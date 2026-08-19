# One Account ⇄ One Device binding for mobile check-in

## Context

Mobile check-in already proves *which device* punched, via a WebAuthn platform authenticator
(`user_devices` + `WebAuthnService` + `MobileCheckInService` gate 2). Two holes remain:

1. **An account can register many devices.** `user_devices` has only `index(user_id, status)` —
   nothing caps it at one. An employee can enroll a phone, hand it to a colleague, and enroll a
   second phone for themselves.
2. **A phone can hold passkeys for many accounts.** `credential_id` is globally unique, but a
   single handset can create a *different* credential per account. `excludeCredentials` is
   currently scoped to the registering user only
   ([WebAuthnService.php:137](yner_main/app/Services/WebAuthnService.php#L137)), so the
   authenticator happily enrolls for a second account. One phone can punch in five people.

Additionally, gate 2 currently degrades to `webauthn_verified = false` and *continues* when
`webauthn.require` is off — an unlinked device can check in.

**Goal:** exactly one active device per account, exactly one account per device, and no check-in
or check-out is possible from anything else.

**Explicitly out of scope: IP addresses.** `mobile_check_ins.ip_address` and
`user_devices.last_used_ip` stay audit-only. They are never used as an identity or location
signal — colleagues share a Wi-Fi NAT, so IP proves nothing. Nothing in this plan reads them for
a decision.

## Approach — three independent enforcement layers

No single layer is sufficient, so all three ship together. Layer 1 is enforced by the phone's own
secure element, Layer 2 by the server, Layer 3 by the database.

### Layer 1 — Hardware: global `excludeCredentials`

At registration, send descriptors for **every active credential in the system** (under the current
`rp_id`), not just the registering user's. The platform authenticator itself then refuses with
`InvalidStateError` if it already holds any of them. A phone that is already linked to *any*
account cannot mint a second passkey — enforced below the browser, so client tampering does not
help.

- New `CredentialSourceRepository::allActiveDescriptors(): array` — reads
  `UserDevice::usable($rpId)`, ordered `last_used_at desc, id desc`, capped at
  `config('webauthn.exclude_limit')` (default 500). Log a warning when truncated.
- `WebAuthnService::registrationOptions()` uses it in place of `descriptorsFor($user)`.
  `assertionOptions()` keeps `descriptorsFor($user)` — assertion must stay user-scoped.
- Credential IDs are opaque random bytes, not PII, and only reach an authenticated employee.
  The single leak is a rough count of enrolled devices; acceptable.

### Layer 2 — Server: a persistent device token

Survives passkey deletion and does not depend on the authenticator behaving.

- Add to `user_devices`: `device_token_hash` (string 64, **unique**, nullable),
  `device_token_issued_at` (timestamp nullable).
- New `app/Services/DeviceTokenService.php`:
  - `issue(UserDevice $device): string` — 32 random bytes, base64url; stores
    `hash('sha256', $token)`; returns the plaintext once.
  - `presented(Request $request): ?string` — reads the signed cookie
    `config('device.token_cookie')` (default `eapms_device`), falling back to the
    `X-Device-Token` header (localStorage mirror sent by the client).
  - `resolve(?string $token): ?UserDevice` — hash lookup restricted to active devices.
  - `queueCookie(string $token): void` — `Cookie::queue(..., minutes: 2y, httpOnly: true,
    secure: true, sameSite: 'lax')`.
- **At registration:** if a presented token resolves to a device owned by a *different* user →
  refuse (`RuntimeException` → 422 "This phone is already linked to another account."), write
  `AuditLog` action `device.link_conflict`, notify Admins via `SystemNotification`.
- **At check-in:** the presented token must resolve to the same `user_devices` row the assertion
  resolved to (new gate 2b, below).

Honest limitation to record in the code comment: a user who clears both the cookie and
localStorage defeats Layer 2 alone. They cannot defeat Layer 1 without deleting their own passkey
(which breaks their own check-in), and re-linking then requires admin approval — the point is to
make sharing expensive and loudly audited, not impossible.

### Layer 3 — Database invariants

- `user_devices.active_user_id` — nullable `unsignedBigInteger`, **unique**. Set to `user_id`
  while `status = active`, `NULL` on revoke. Both MySQL and SQLite ignore NULLs in unique
  indexes, so this is a portable "at most one active device per user". Maintained in a
  `UserDevice::booted()` `saving` hook keyed off `status`, so it cannot drift from `revoke()`.
- `device_token_hash` unique → one token ↔ one device row globally.
- `credential_id` unique already exists.

## Check-in enforcement

[`MobileCheckInService::record()`](yner_main/app/Services/MobileCheckInService.php#L58) — gate 2
is rewritten:

- **Gate 2 (WebAuthn) is now unconditional.** Remove the `webauthn.require` bypass; the mobile
  channel always demands a verified assertion. No assertion → `WebauthnFailed`. `webauthn.require`
  no longer influences this path (keep the config key; it stops being read here).
- **New gate 2b — device binding.** After the assertion resolves a `UserDevice`:
  - user has no active device → `NoLinkedDevice`
  - resolved device is not the user's bound (`active_user_id`) row → `DeviceMismatch`
  - `DeviceTokenService::presented()` is absent, or resolves to a different device row →
    `DeviceMismatch`
  - a mismatch is `flagged` on the persisted attempt and notifies Admins (reuse the existing
    `flagged` + `Notification::send($admins, ...)` path at the end of `record()`).

New `App\Enums\CheckInRejection` cases with copy in `message()`:
- `NoLinkedDevice` — "No device is linked to your account. Register this phone under My Device."
- `DeviceMismatch` — "This is not the device linked to your account. Check in from your
  registered phone, or request a device reset."

## Re-link flow (Admin/HR approval)

Modelled on the existing registration-request pattern
([RegistrationRequest.php](yner_main/app/Models/RegistrationRequest.php),
[RegistrationRequestReviewController.php](yner_main/app/Http/Controllers/RegistrationRequestReviewController.php),
[registration-requests/](yner_main/resources/js/pages/registration-requests/)) — reuse its shape,
its `RegistrationStatus` enum layout, its notify-on-review behaviour, and its Inertia page
structure rather than inventing a new one.

- New migration `2026_08_19_000050_create_device_reset_requests_table.php`:
  `user_id` (cascade), `employee_id` (nullOnDelete), `user_device_id` (nullOnDelete — the device
  being replaced), `reason` text, `status` string(20) default `pending`, `reviewed_by`,
  `reviewed_at`, `review_note`, `approved_until` datetime nullable, `used_at` datetime nullable,
  `ip_address`, `user_agent`, timestamps, index `(user_id, status)`.
- New `App\Enums\DeviceResetStatus` (`pending|approved|rejected`), `App\Models\DeviceResetRequest`,
  `App\Policies\DeviceResetRequestPolicy` (`create`: linked active employee; `viewAny`/`update`:
  Admin + HR Officer).
- New `App\Http\Controllers\DeviceResetRequestController`:
  - `store()` — throttled, one pending per user, writes `AuditLog` `device_reset.requested`,
    notifies Admin + HR.
  - `index()` — Inertia `device-reset-requests/index`, filters `status`, `employee`.
  - `approve()` — inside a transaction: revoke the current active device (reusing
    `UserDevice::revoke()`), set `approved_until = now()->addHours(config('device.reset_window_hours', 24))`,
    audit `device_reset.approved`, notify the employee.
  - `reject()` — note + notify.
- **`UserDevicePolicy` changes:**
  - `create`: linked active employee **AND** (no active device **OR** an approved, unexpired,
    unused reset request).
  - `delete`: **Admin only.** Owners no longer self-revoke — that is what the reset request is
    for; self-revoke would make the whole binding self-service.
- `UserDeviceController::registerVerify()` consumes the approval (`used_at = now()`, status
  advanced) in the same transaction that creates the device.

## Data migration for existing rows

In the `up()` of the `user_devices` alteration migration, **before** adding the unique index:

- Group active devices by `user_id`. Keep the one with the greatest `last_used_at`
  (NULLs last, tie-break `id desc`); revoke the others with
  `'Superseded by the single-device policy.'`, one `AuditLog` row each.
- Backfill `active_user_id = user_id` for the survivors.
- Notify each affected user with a `SystemNotification`.
- `down()` drops the columns; revocations are not reversed (they are audit evidence).

## Frontend

- [`resources/js/pages/devices/index.tsx`](yner_main/resources/js/pages/devices/index.tsx) —
  employees see a single **My Device** card (bound device + status + last used), not a list.
  "Register this device" only when `actions.register`; otherwise a "Request a device reset"
  dialog, plus the pending/approved state of any existing request. Admin view keeps the paginated
  table and gains a link to device resets.
- [`device-register-dialog.tsx`](yner_main/resources/js/components/webauthn/device-register-dialog.tsx)
  — on success, persist the returned `device_token` to `localStorage`.
- [`use-webauthn.ts`](yner_main/resources/js/components/webauthn/use-webauthn.ts) —
  `describeWebAuthnError()` gains an `InvalidStateError` case → "This phone is already linked to
  an account. Only one account can be linked per device."
- [`check-in/show.tsx`](yner_main/resources/js/pages/check-in/show.tsx) — send the localStorage
  token as `X-Device-Token` on both the assertion-options and the `POST /check-in` fetches;
  render the two new rejection reasons; when the user already has a bound device the
  `needsDevice` prompt links to the reset request instead of registration.
- New `resources/js/pages/device-reset-requests/index.tsx` (copy the structure of
  `registration-requests/index.tsx`).
- [`app-sidebar.tsx`](yner_main/resources/js/components/app-sidebar.tsx) — rename "My Devices" →
  "My Device"; add "Device Resets" gated on a new `can.reviewDeviceResets` shared prop from
  [`HandleInertiaRequests`](yner_main/app/Http/Middleware/HandleInertiaRequests.php).

## Config

`config/webauthn.php`: `exclude_limit` (env `WEBAUTHN_EXCLUDE_LIMIT`, default 500).
New `config/device.php`: `token_cookie`, `token_ttl_days` (730), `reset_window_hours` (24),
`reset_throttle` (per-user pending cap).

## Files touched

| Area | Files |
| --- | --- |
| Migrations | new `..._add_device_binding_to_user_devices_table.php`, new `..._create_device_reset_requests_table.php` |
| Models | `UserDevice.php` (booted hook, `activeFor()` scope, fillable), new `DeviceResetRequest.php` |
| Enums | `CheckInRejection.php` (+2 cases), new `DeviceResetStatus.php` |
| Services | `WebAuthnService.php`, `WebAuthn/CredentialSourceRepository.php`, `MobileCheckInService.php`, new `DeviceTokenService.php` |
| Controllers | `UserDeviceController.php`, `MobileCheckInController.php`, new `DeviceResetRequestController.php` |
| Policies | `UserDevicePolicy.php`, new `DeviceResetRequestPolicy.php` |
| Routes / middleware | `routes/web.php`, `HandleInertiaRequests.php` |
| Frontend | the five files above + one new page |
| Notifications | `SystemNotification.php` (+ `deviceLinkConflict`, `deviceResetRequested/Approved/Rejected`, `deviceSuperseded`) |

## Verification

1. `cd yner_main && php artisan migrate:fresh --seed` — confirm the data migration path with a
   seeded user holding two active devices (add a temporary factory case), then check exactly one
   survives and `active_user_id` is unique.
2. `php artisan test` — existing suites must stay green:
   `tests/Feature/UserDeviceTest.php`, `MobileCheckInTest.php`, `MobileCheckInLogTest.php`,
   `MobileCheckInComputeTest.php`. `tests/Support/FakeWebAuthnService.php` needs to honour the
   binding (return the bound device).
3. New tests:
   - `tests/Feature/DeviceBindingTest.php` — registering a second device for the same user is
     forbidden by policy and by the unique index; a device token bound to user A is refused at
     registration for user B (422 + `device.link_conflict` audit row); check-in with an assertion
     from another user's device → `DeviceMismatch`; check-in with no token → `DeviceMismatch`;
     check-in with no active device → `NoLinkedDevice`; a user with a valid bound device + token
     still succeeds.
   - `tests/Feature/DeviceResetRequestTest.php` — request → duplicate pending refused; non-Admin
     cannot approve; approve revokes the old device and opens the window; policy allows exactly
     one registration inside the window and refuses after `approved_until`; owner self-delete now
     403s.
   - Extend `MobileCheckInTest.php` with one case per new rejection reason (matching the existing
     one-test-per-reason convention).
4. `./vendor/bin/pint` and `npm run build`.
5. Manual end-to-end on two phones against one account: phone A registers and checks in; phone B
   is refused at `navigator.credentials.create()` by `InvalidStateError`; phone B is refused at
   check-in with `DeviceMismatch`; submit a reset, approve as Admin, register phone B, confirm
   phone A now fails and phone B succeeds. Verify `/mobile-check-ins` shows the flagged mismatch
   attempts.
