# EAPMS — Invitation-Based Registration + WebAuthn Geofenced Mobile Check-In

## Context

EAPMS is complete through Phase 13: biometric devices push punches into `raw_attendance_logs`, `AttendanceCalculator` computes `attendance_records`, and the permission-sync engine overwrites false Absents. Two gaps remain.

**1. Registration is wide open.** `Auth\RegisteredUserController::store()` creates a `users` row from an anonymous POST, assigns **no role**, links **no Employee**, and logs the person straight in. Anyone who can reach `/register` gets an authenticated session on an HR system.

**2. Attendance requires physical device proximity.** Field staff, remote sites, and anyone away from a terminal cannot be recorded at all.

This work adds a second, independent attendance channel — a phone acting as the terminal, with the employee's identity proven by a WebAuthn passkey and their presence bounded by a server-side geofence — and replaces open signup with an admin-approved registration-request → single-use invitation → account-activation flow.

**Intended outcome:** every account originates from an approved request; every employee can check in from a registered phone inside a geofence; and both attendance channels converge in `raw_attendance_logs` so the existing calculator, permission sync, reports, and AI pipeline work unchanged.

### Locked decisions (from user)

| Decision | Choice |
|---|---|
| Phone client | **WebAuthn platform authenticator in the mobile browser.** No native app, no Sanctum. Reuses the Inertia session. |
| Data flow | Mobile punches land in **existing `raw_attendance_logs`** as a second source. |
| Session model | **No attendance sessions.** Check In / Check Out validated against the employee's `work_schedule` + geofence. |
| Registration | `/register` **repointed** to the request→approve→invite flow. |
| Biometric channel | **Untouched.** Both channels independent at the edge, converged in the pipeline. |
| Secure context | Deployed **HTTPS domain** is the target; `WEBAUTHN_RP_ID` is env-driven. |
| Degradation | **Hard block.** `WEBAUTHN_REQUIRE=true` default; server rejects any check-in without a valid assertion. |

### Step 0 — record the plan (per CLAUDE.md)

Copy this file to `prompt/secure-registration-and-mobile-webauthn-checkin.md` before starting execution.

---

## Cross-cutting design

### How mobile punches resolve to an employee

`AttendanceCalculator::punchesByEmployee()` ([AttendanceCalculator.php:109-133](yner_main/app/Services/AttendanceCalculator.php#L109-L133)) resolves punches **only** via `device_enrollments ⋈ biometric_devices` keyed `"{serial}|{device_user_id}"`, and silently drops anything unresolved.

Add nullable `employee_id` + `source` to `raw_attendance_logs` and short-circuit the lookup. The entire calculator change is one expression:

```php
$employeeId = $log->employee_id
    ?? $lookup->get($log->device_serial.'|'.$log->device_user_id)?->employee_id;
```

`device_serial` / `device_user_id` are NOT NULL and carry the `raw_attendance_logs_dedupe_unique` index. **Do not make them nullable** — MySQL treats NULL as distinct in a unique index, which would silently disable dedupe for every mobile row. Write sentinels instead:

- `device_serial` = `RawAttendanceLog::MOBILE_SERIAL` (`'MOBILE-WEBAUTHN'`)
- `device_user_id` = `(string) $employee->id`

This reuses the existing unique index verbatim as the mobile natural key, guarantees the enrollment lookup can never match a mobile row (channels provably independent), and keeps mobile rows out of the admin device-log view. Guard the sentinel with `Rule::notIn` in `BiometricDeviceController::validateDevice()`.

`deriveAttributes()` is direction-agnostic (`first()` / `last()` of sorted punches), so Check In / Check Out need no new calculator concept — direction is for UI, audit, and ordering rules only.

### Work location resolution — fail closed

`employees.work_location_id` → `departments.work_location_id` → **refuse check-in**. No global default: a global default silently geofences everyone against head office and produces plausible-but-wrong attendance, the worst failure mode here.

### Threat model — write this into the code comments

**WebAuthn proves *which device*, not *where the device is*.** Coordinates come from `navigator.geolocation` and are spoofable via devtools sensor override or a mock-location app. Layered mitigations: server-side Haversine only (client distance is display-only and never trusted); reject `accuracy > 100m`; persist every attempt including rejections with IP/UA; flag impossible travel. Mobile check-in is a **supplement** to the biometric channel — the audit trail is the real control. State this to stakeholders as a product constraint.

### Secure context / RP ID

`navigator.credentials` is `undefined` outside HTTPS/localhost — not a catchable error. RP ID must be the **public** registrable domain (not the container host, no scheme, no port), and credentials are cryptographically bound to it. Store `rp_id` per credential so a future domain change yields a clean "re-register your device" prompt instead of an opaque signature failure. New `config/webauthn.php`; env keys `WEBAUTHN_RP_ID`, `WEBAUTHN_RP_NAME`, `WEBAUTHN_TIMEOUT_MS`, `WEBAUTHN_REQUIRE`.

### New dependencies

- `composer require web-auth/webauthn-lib:^5.2` — **pin the major**; the v4→v5 ceremony API changed significantly. Wrap every library type behind `App\Services\WebAuthnService` so the surface touches one file. Do **not** hand-roll (CBOR decode, COSE parsing, ES256 verify, rpIdHash + challenge + origin checks — one missed check is a full auth bypass). Do **not** use `laragear/webauthn`: it binds credentials to `users` via its own trait and migrations, and we need them joined to `Employee`, `MobileCheckIn`, and a revocation lifecycle.
- `npm install @simplewebauthn/browser` — emits the base64url JSON shape `PublicKeyCredentialLoader` consumes, removing the hand-written ArrayBuffer↔base64url conversions that are the top source of WebAuthn bugs.

---

## Phase 1 — Registration request → invitation → account

### Migrations
- `create_registration_requests_table` — first_name, last_name, email (indexed, **not** unique: allow re-apply after rejection), phone, department_id (nullOnDelete), note, status `string(20)` default `pending`, reviewed_by/reviewed_at/review_note, employee_id (set on approval), ip_address, `index(['status','created_at'])`.
- `create_account_invitations_table` — uuid (unique, the public selector), registration_request_id, employee_id, email, **token (bcrypt hash — never plaintext)**, expires_at, used_at, revoked_at, created_by, `index(['email','used_at'])`.

Use `string(20)` + PHP enum cast, not a DB `enum` — matches the `permission_requests` precedent and avoids MySQL ALTER pain.

### Enums
- `app/Enums/RegistrationStatus.php` — Pending|Approved|Rejected, mirroring `PermissionStatus`.
- `app/Enums/InvitationStatus.php` — Pending|Used|Expired|Revoked, **derived not stored**. A stored status alongside `expires_at` is a lie-by-staleness unless a job sweeps it.

### Models
- `RegistrationRequest` — casts, `reviewer()`, `department()`, `employee()`, `invitation()`.
- `AccountInvitation` — `$hidden = ['token']` (prevents leaking into an Inertia prop); `isUsable()`, `status()`, `matches(string $plain)` via `Hash::check` (bcrypt verification is constant-time); static `issue(Employee, ?RegistrationRequest, User): array{invitation, plainToken}` using `Str::random(64)`, returning plaintext **once**.

### Approval creates the Employee; activation creates the User
Approval is when HR supplies what the applicant cannot: unique `employee_code`, department, work schedule, work location, hire date — all of which `AttendanceCalculator` depends on. Create the Employee with `status = 'inactive'`; `computeForDate()` filters `where('status','active')`, so an approved-but-unactivated employee generates **zero phantom Absents**. Activation then does the minimum: create User, `assignRole(RoleName::Employee->value)`, link `employee.user_id`, flip to `active`, set `email_verified_at = now()` (the token *is* the email proof).

### Controllers
- `Auth\RegistrationRequestController` (guest) — `create`, `store` (throttled, records IP, **identical success response** whether or not the email exists → no account enumeration), `submitted`.
- `RegistrationRequestReviewController` (Admin+HR) — `index` (paginate 15, stats, `formData()` with departments/schedules/locations), `approve` (validates employee_code etc.; in a transaction: create inactive Employee → mark Approved → `AccountInvitation::issue()` → audit → notify; flashes the **one-time activation URL**), `reject` (review_note required).
- `AccountInvitationController` (Admin+HR) — `index`, `resend` (revoke old, issue fresh), `destroy` (sets `revoked_at`; never hard-delete, it is audit evidence).
- `Auth\AccountActivationController` (guest) — `create($uuid, $token)` resolves by uuid then `Hash::check`; failures render `auth/activation-invalid` with reason (`expired`|`used`|`invalid`) and never leak whether the uuid existed. `store()` re-verifies (never trust the GET) and inside `DB::transaction` does `lockForUpdate()` + **re-checks `used_at === null` inside the lock** before creating the User.

> The in-lock recheck is what actually makes the token single-use. A bare `if ($inv->used_at)` before the update is a TOCTOU race two simultaneous submissions win.

### Routes
`routes/auth.php` (guest group) — **keep the URL `/register` and route name `register`**: `login.tsx:115` and `welcome.tsx:34` call `route('register')` and would throw at runtime otherwise. The hole closes because the *controller* changes.
```php
Route::get('register', [RegistrationRequestController::class, 'create'])->name('register');
Route::post('register', [RegistrationRequestController::class, 'store'])->middleware('throttle:5,60');
Route::get('register/submitted', [...])->name('registration-request.submitted');
Route::get('activate/{uuid}/{token}', [AccountActivationController::class, 'create'])
    ->middleware('throttle:10,1')->name('account.activate');
Route::post('activate/{uuid}/{token}', [...])->middleware('throttle:10,1')->name('account.activate.store');
```
`routes/web.php` (auth group) — `registration-requests.index`, `.approve`, `.reject`; `account-invitations.index`, `.resend`, `.destroy`. Not `Route::resource` — these are domain actions, matching the existing `permission-requests/{x}/review` shape.

### Policies
`RegistrationRequestPolicy` (viewAny/view/review → Admin+HR; delete → Admin), `AccountInvitationPolicy` (Admin+HR). Auto-discovered — no registration, consistent with the existing 9 policies.

### Notifications
Extend `app/Notifications/SystemNotification.php` with named static factories (`registrationRequestSubmitted`, `registrationRequestApproved`, `registrationRequestRejected`, `accountActivated`). **Do not create Mailables** — the private-ctor + factory pattern is the convention.

> **Required change:** applicant notifications are on-demand (`Notification::route('mail', $email)`) because no `User` exists yet, but `via()` is hardcoded to `['database','mail']`. Add an `$notifiable instanceof AnonymousNotifiable → ['mail']` branch, with a test.

### React
`auth/registration-request.tsx` (replaces `auth/register.tsx`, which is deleted; **drop the Google button** — there is no OAuth backend, it is misleading UI), `auth/registration-submitted.tsx`, `auth/activate.tsx` (identity fields `disabled`, password + confirm), `auth/activation-invalid.tsx`, `registration-requests/index.tsx` and `account-invitations/index.tsx` (mirror `device-enrollments/index.tsx`: StatCard row → Card+Table → Pagination → dialogs), `components/registration-approve-dialog.tsx` (modelled on `permission-review-dialog.tsx`), `registration-reject-dialog.tsx`, and **`invitation-link-dialog.tsx`** — a copy button plus "this link will not be shown again". Essential given `MAIL_MAILER=log`: invitations are not deliverable in dev/staging.

Every new sidebar entry requires editing three files together or TS fails: `app-sidebar.tsx`, `HandleInertiaRequests::share()`, and `resources/js/types/index.d.ts` (`SharedData['can']` is a closed interface).

### Security specifics
`Str::random(64)` ≈ 381 bits. Store `Hash::make()`, look up by **uuid then `Hash::check`** — never `where('token', $plain)`. Identical response for "uuid not found" and "token mismatch". Expiry 72h (`config('auth.invitations.expire')`); expired → "request a new link" notifies HR, never self-serves a token. Reuse attempts write an `audit_logs` row (`invitation.reuse_attempt`) with IP. Keep `email`, `status`, `employee_id`, `reviewed_by` **out of `$fillable`** — assign server-controlled fields individually.

### Tests
- **`tests/Feature/Auth/RegistrationTest.php` — rewrite.** Both existing assertions are now false. Assert the request is stored pending, `assertGuest()`, and **`assertDatabaseCount('users', 0)`** — this is the regression test that keeps the hole closed.
- `RegistrationRequestTest` — public submit; duplicate email absorbed silently; Employee gets 403; approve creates an inactive Employee + pending invitation + audit row; reject requires a note; throttling.
- `AccountActivationTest` — happy path creates the User with the Employee role and authenticates; **reuse creates no second user**; expired/wrong-token/tampered-uuid rejected.
- Extend `AuthorizationMatrixTest` with the two new index routes.

---

## Phase 2 — Work locations & geofencing

*(Built before WebAuthn: lowest risk, and the check-in page depends on it.)*

### Migrations
- `create_work_locations_table` — name (unique), address, `latitude decimal(10,7)`, `longitude decimal(10,7)`, `radius_meters` unsigned default 150, is_active. `decimal(10,7)` gives ~1.1cm precision and is **exact**, unlike float — a boundary comparison against a drifting float is a support ticket.
- `add_work_location_to_employees_and_departments_table` — nullable FK on both, `nullOnDelete`.

### Service
`app/Services/GeofenceService.php` — **pure PHP, no DB functions**. `distanceMeters()` (Haversine, R = 6_371_000.0), `resolveLocationFor(Employee)`, `isWithin(WorkLocation, lat, lon)`. Pure PHP because dev is MySQL 8 and tests are SQLite in-memory: `ST_Distance_Sphere` does not exist in SQLite. Cast explicitly to `(float)` — MySQL returns `decimal` as a string, SQLite as a float.

### Controller / policy / routes
`WorkLocationController` following `WorkScheduleController` verbatim (modal forms, `actions()` helper). Validation: latitude `between:-90,90`, longitude `between:-180,180`, `radius_meters` `integer|min:20|max:5000` — the 20m floor matters, GPS accuracy makes anything smaller unenforceable and produces false rejections. `Route::resource('work-locations', ...)->except(['show','create','edit'])`. `WorkLocationPolicy`: Admin+HR view/create/update, Admin delete.

**Must also change:** `Employee` and `Department` models (`$fillable` + `workLocation()`), `EmployeeController::validateEmployee()` and `formData()`, `DepartmentController` equivalently, `employee-form.tsx` / `department-form.tsx` (add the select), plus the sidebar/shared-props/types trio.

### Config
New `config/attendance.php` → `mobile` key: `max_accuracy_meters` (100), `min_interval_seconds` (60), `check_in_early_minutes` (120), `check_in_late_minutes` (240), `check_out_early_minutes` (120), `check_out_late_minutes` (240), `impossible_travel_kmh` (200). Deliberately **not** columns on `work_schedules` — that table is shared with the biometric path and its `AttendanceCalculatorTest` fixtures; config keeps the blast radius at zero.

### Tests
`WorkLocationTest` (CRUD + authz matrix), `tests/Unit/GeofenceServiceTest.php` (known fixtures: 1° longitude at the equator ≈ 111.32km; identical points → 0; boundary at exactly `radius_meters`).

---

## Phase 3 — WebAuthn device registration

### Migration
`create_user_devices_table` — user_id, `credential_id string(512) unique`, `public_key text`, sign_count, aaguid, transports (json), attestation_type, **rp_id**, device_name, platform, status, last_used_at, last_used_ip, revoked_at, revoked_reason, `index(['user_id','status'])`.

`string(512)` × 4 bytes utf8mb4 = 2048 bytes, under InnoDB's 3072-byte index limit. **Do not widen** without switching to a hash column.

Store the **whole serialized `PublicKeyCredentialSource`** in `public_key` (the library round-trips it via `jsonSerialize()` / `createFromArray()`). The scalar columns are for querying and admin display only — reconstructing the source from scattered columns is where hand-rolled implementations break.

### Service
`app/Services/WebAuthnService.php`:
- `registrationOptions(User)` — `attestation: none`, `authenticatorSelection: {attachment: 'platform', residentKey: 'preferred', userVerification: 'required'}`, `excludeCredentials` = the user's active credentials (prevents double-registering one phone), `challenge = random_bytes(32)` stored **in the session**, never a hidden field.
- `verifyRegistration(User, payload, deviceName)` — loads the challenge, **deletes it immediately (single-use)**, validates, persists a `UserDevice`.
- `assertionOptions(User)` / `verifyAssertion(User, payload)` — updates `sign_count`, `last_used_at`, `last_used_ip`.

`app/Services/WebAuthn/CredentialSourceRepository.php` returns **only active credentials matching the current `rp_id`** — revocation enforced at the repository layer so no call site can forget it.

**Sign-count regression (cloned credential):** if `$stored > 0 && $new <= $stored` → revoke the device, reject the ceremony, audit `device.clone_suspected`, notify Admin. **If `$stored === 0 && $new === 0`, skip the check entirely** — Apple Touch/Face ID and most Android platform authenticators always report 0, and a naive check locks out every iPhone user on their second check-in. Make this an explicit code comment.

### Controller / routes / policy
`UserDeviceController` — `index` (own devices; Admin sees all), `registerOptions`, `registerVerify`, `destroy` (soft revoke + audit + notify owner). Routes under the existing `auth` group, `throttle:10,1` on the ceremony endpoints. **Add `password.confirm` middleware to device registration** — registering a new authenticator is a privilege escalation on a hijacked session, and `ConfirmablePasswordController` plus its page already ship with the app. `UserDevicePolicy`: view/delete → owner or Admin; create → any user with a linked active Employee.

### React
`pages/devices/index.tsx`; `components/webauthn/use-webauthn.ts` (wraps `startRegistration`/`startAuthentication`, plus a capability probe returning `'supported' | 'insecure-context' | 'unsupported'` via `PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable()`); `webauthn-unavailable.tsx` distinguishing insecure-context (actionable: "open over HTTPS") from unsupported hardware; `device-register-dialog.tsx`.

Handle each error distinctly — all are common: `NotAllowedError` (cancelled/timed out), `InvalidStateError` ("this device is already registered"), `NotSupportedError`, `SecurityError` (RP ID mismatch — a **config bug**, not user error, so say so).

### Tests
`UserDeviceTest` — options endpoint returns and stores a challenge; throttled; verify rejects a payload with **no session challenge** (replay guard) and with a **mismatched challenge**; a user cannot revoke another's device (403) but Admin can; revoked devices excluded from `allowCredentials`; sign-count regression revokes + audits.

> Real ceremony crypto cannot be generated in PHPUnit. **Bind a fake `WebAuthnService` in the container for all Phase 4 check-in tests** (keeps them focused on business rules) and test the real service against a committed browser-generated fixture with a pinned challenge.

---

## Phase 4 — Mobile check-in / check-out

### Migrations
- `add_source_and_employee_to_raw_attendance_logs_table` — `employee_id` nullable FK after `device_serial`, `source string(20) default 'device'` indexed, `index(['employee_id','punched_at'])`. Existing rows take the defaults — **no data migration**, safe on production.
- `create_mobile_check_ins_table` — employee_id, user_id, raw_attendance_log_id, user_device_id, work_location_id, work_date, direction, punched_at, latitude/longitude `decimal(10,7)`, accuracy_meters, **distance_meters (server-computed only)**, within_geofence, webauthn_verified, result (`accepted`|`rejected`), rejection_reason, flagged, ip_address, user_agent, `index(['employee_id','work_date','direction','result'])`.

> A dedicated table rather than `raw_payload` JSON: the admin log must sort/filter by distance, employee, direction, and result. MySQL 8 indexes JSON only via generated columns and SQLite's JSON semantics differ — that is two code paths. One migration buys indexable, portable, testable queries.

> **No unique index on (employee, date, direction)** — it must apply only to accepted rows and MySQL has no partial indexes. The transactional `lockForUpdate` check gives the same guarantee, and keeping rejected attempts in the same table is far more useful for audit.

### Models / enums
`MobileCheckIn`; `AttendanceSource` (Device|Mobile); `CheckInDirection` (In|Out). **`RawAttendanceLog` must change:** add `employee_id`/`source` to `$fillable`, `const MOBILE_SERIAL = 'MOBILE-WEBAUTHN'`, `employee()` BelongsTo, scopes `mobile()`/`device()`.

### The pipeline — `app/Services/MobileCheckInService.php`
`record(User, CheckInDirection, array $payload): MobileCheckIn` — a strict, ordered, **fail-closed** gate chain. Every rejection writes a `mobile_check_ins` row with `result='rejected'` and a reason, then throws `MobileCheckInException` which the controller renders as 422.

1. **Active employee** — else `no_employee_record`.
2. **WebAuthn assertion** — `verifyAssertion()` against the session challenge; sets `user_device_id`, `webauthn_verified`. With `WEBAUTHN_REQUIRE=true`, failure or absence → `webauthn_failed`. **Never trust a client capability claim.**
3. **Accuracy gate** — `accuracy_meters <= config(...max_accuracy_meters)`, else `low_gps_accuracy`. Blocks IP-derived coarse fixes.
4. **Work location** — `resolveLocationFor()`; null → `no_work_location`.
5. **Geofence** — server-side Haversine vs `radius_meters`; outside → `outside_geofence` (the row still records the real distance — that is the point of the audit table).
6. **Schedule window** — from `start_time`/`end_time` + the config offsets; else `no_work_schedule` / `outside_schedule_window`.
7. **Ordering & duplication** — inside `DB::transaction` with `lockForUpdate()` on today's rows: `already_checked_in`, `no_check_in`, `already_checked_out`, `too_soon`.
8. **Impossible travel** — over `impossible_travel_kmh` from the previous accepted punch → **accept but set `flagged=true`** and notify Admin. Do not reject; a legitimate flight or a bad fix would lock out a real employee.
9. **Write `raw_attendance_logs`** — sentinel serial, `employee_id`, `source='mobile'`, `punched_at = now()`. Catch `QueryException` 23000 on the dedupe index → friendly `duplicate_punch`, not a 500.
10. **Write `mobile_check_ins`** with the log FK and `result='accepted'`.
11. **Write `audit_logs`** — `mobile.check-in`/`mobile.check-out`, `auditable = MobileCheckIn`, location snapshot in `new_values`.
12. **`ComputeAttendanceForDate::dispatch(...)`** — mirroring [BiometricIngestController.php:41-44](yner_main/app/Http/Controllers/Api/BiometricIngestController.php#L41-L44), so a mobile punch reaches `attendance_records` immediately.

> **`punched_at` must be `now()` server-side.** Never accept a client timestamp — that is a trivial late-arrival bypass. This deliberately differs from `BiometricIngestController`, which trusts `punched_at` because it comes from an internal-secret-authenticated service. Comment the distinction so nobody "harmonizes" the two later.

### Controller / routes / policy
`MobileCheckInController` — `show` (employee page: today's state, resolved location, schedule window, device count, `webauthnRequired`), `assertionOptions`, `store`, `index` (Admin/HR log with date/employee/result filters). Routes `check-in.show`, `check-in.assertion-options`, `check-in.store`, `mobile-check-ins.index` under the existing `auth` group, `throttle:20,1` on the write paths. `MobileCheckInPolicy`: `viewAny` → Admin+HR; `create` → any user with an active linked Employee.

### React
`pages/check-in/show.tsx` (large Check In / Check Out buttons, status, location name, display-only distance readout, device-registration prompt if none active); `components/check-in/use-geolocation.ts` with `{enableHighAccuracy: true, timeout: 15000, maximumAge: 0}` — **`maximumAge: 0` is load-bearing**, a cached fix from the employee's home would sail through the geofence; `geolocation-error.tsx` with distinct copy for `PERMISSION_DENIED` / `POSITION_UNAVAILABLE` / `TIMEOUT` / not-available (also an insecure-context symptom); `pages/mobile-check-ins/index.tsx`. Add a check-in card to `dashboard/employee.tsx` (`DashboardController::employeeDashboard()` already handles `$employee === null`).

> **UI sequencing:** geolocation **first**, then the WebAuthn assertion, then POST — all in one handler chain. Both are user-gesture-sensitive on iOS Safari, and a WebAuthn prompt firing after a 15s geolocation timeout gets dismissed by the OS.

### Tests
- `MobileCheckInTest` — one test per rejection reason, plus a happy path asserting the `raw_attendance_logs` row has `source='mobile'`, `employee_id` set, and `device_serial='MOBILE-WEBAUTHN'`.
- `MobileCheckInComputeTest` — modelled on `BiometricIngestComputeTest`, `Queue::fake()`.
- **`AttendanceCalculatorTest` — extend, do not rewrite.** Mobile-only punch pair → `Present`; mobile punch after grace → `Late`; and **mobile + device punches for the same employee on the same day merge into one record** (`first_in` from the earlier source, `last_out` from the later). That last one is the integration test proving the two-channel design holds.
- `MobileCheckInLogTest` — admin log authz + filtering.

---

## Phase 5 — Polish

`config/webauthn.php` + `.env.example` (`WEBAUTHN_RP_ID`, `WEBAUTHN_RP_NAME`, `WEBAUTHN_REQUIRE`, `MOBILE_MAX_ACCURACY_METERS`, …) — also add the missing `BIO_SERVICE_URL` / `BIO_SERVICE_TIMEOUT` while there. `WorkLocationSeeder` + extend `OrgSeeder` to assign locations. Extend `EapmsStatus` with mobile-channel health (devices registered, check-ins today, flagged count). New `PruneInvitations` command scheduled in `routes/console.php`. README section on the WebAuthn secure-context requirement and the deployed-domain RP ID — otherwise every developer rediscovers it painfully.

---

## What must NOT change

- `AttendanceCalculator::deriveAttributes()`, `markProcessed()`, the `permission_request_id` short-circuits, and the punch sort order.
- The `raw_attendance_logs_dedupe_unique` index and the NOT-NULL-ness of `device_serial` / `device_user_id`.
- `device_enrollments`, `biometric_devices`, `BiometricIngestController`, `BiometricDeviceListController`, `VerifyInternalSecret`, `BioServiceClient` — the biometric channel is untouched.
- `attendance_records` schema, the permission sync engine, `ReportController`, both report aggregators, the AI pipeline.
- The `register` **route name and URL** — only the controller behind it changes.
- The `notifications` table's `uuidMorphs` quirk (pre-existing; MySQL coerces; "fixing" it needs a data migration).
- The `SystemNotification` private-ctor + static-factory pattern — extend it, never bypass it with a Mailable.

---

## Risk register

| Risk | Severity | Mitigation |
|---|---|---|
| **Geolocation is client-supplied and spoofable**; WebAuthn does not fix this | **High** | Accuracy gate, full audit of every attempt, impossible-travel flagging, server-side distance only. Communicate as a product constraint. |
| **Secure-context / RP-ID misconfiguration** silently breaks all WebAuthn on phones | **High** | env-driven `config/webauthn.php`, per-credential `rp_id`, distinct `SecurityError` UI copy, README. |
| **Touching `AttendanceCalculator` breaks biometrics / sync / AI** | **High** | The change is one `??` expression. Extend the existing tests, never modify them; all must pass untouched. |
| **Token single-use race** under concurrent activation | **High** | `DB::transaction` + `lockForUpdate` + in-lock `used_at` recheck. |
| `MAIL_MAILER=log` — **invitations never delivered** in dev/staging | Medium | One-time copy-link dialog; email is not the only delivery path. |
| **Sign-count check locks out iPhone/Android** (both always report 0) | Medium | Skip when stored and incoming are both 0; comment it. |
| Sentinel serial **collides with a real device** | Medium | `Rule::notIn` on device validation + test. |
| `web-auth/webauthn-lib` **v4→v5 API drift** | Medium | Pin `^5.2`; isolate behind `WebAuthnService`. |
| **`SharedData.can` is a closed TS interface** | Medium | Update `types/index.d.ts` in the same commit as `HandleInertiaRequests`, every time. |
| `SystemNotification::via()` hardcoded to `['database','mail']` — fails for pre-User applicants | Medium | `AnonymousNotifiable` branch + test. |
| **MySQL vs SQLite divergence** (JSON, partial indexes, spatial fns) | Medium | Pure-PHP Haversine, no partial indexes, relational audit table. |
| `decimal(10,7)` returns string on MySQL, float on SQLite | Low | Explicit `(float)` casts in `GeofenceService`. |
| Removing public self-registration is a **process change** | Low | README + in-app copy on the submitted page. |

---

## Commit sequencing

1. Phase 1 migrations + models + enums + policies (no UI) — tests green.
2. Phase 1 controllers + routes + notifications; **rewrite `RegistrationTest` here** — close the security hole before adding any new surface.
3. Phase 1 React + sidebar + shared props + types.
4. Phase 2 work locations (lowest risk; the check-in page depends on it).
5. Phase 3 deps + `user_devices` + `WebAuthnService` + tests.
6. Phase 3 device UI.
7. **Phase 4 migrations + the two-line `AttendanceCalculator` change + extended calculator tests — before the endpoint exists.** Prove the mobile/device merge first.
8. Phase 4 service + controller + routes + tests.
9. Phase 4 React check-in page + admin log.
10. Phase 5 config, seeders, README, prune command.

---

## Verification

**Automated**
```bash
cd yner_main
php artisan test                 # full suite; existing 108+ tests must pass untouched
php artisan test --filter=AttendanceCalculatorTest   # proves the two-channel merge
php artisan test --filter=RegistrationTest           # proves signup is closed
./vendor/bin/pint
npm run build                    # catches SharedData.can type drift
```

**Manual — registration (works on localhost)**
1. `php artisan migrate:fresh --seed`, `php artisan serve`.
2. Visit `/register` as a guest → submit a request → confirm **no `users` row was created** and you are still logged out.
3. Log in as `admin@eapms.test` → Registration Requests → Approve with an employee code, department, schedule, and work location → copy the one-time activation URL from the dialog.
4. Open the URL in a private window → identity fields read-only → set a password → you land on the employee dashboard with the Employee role.
5. **Re-open the same URL → "already used", and no second user exists.** Try a tampered token → generic invalid page.

**Manual — mobile check-in (requires the deployed HTTPS domain)**
6. Deploy with `WEBAUTHN_RP_ID` set to the public host. On a phone, open the site, log in as the activated employee, go to Devices → Register this device → complete the fingerprint/Face prompt → the device appears as active.
7. Create a work location at your current coordinates with a 150m radius and assign it to the employee.
8. Go to Check In → allow location → tap Check In → biometric prompt → success. Verify: a `raw_attendance_logs` row with `source='mobile'` and `device_serial='MOBILE-WEBAUTHN'`; a `mobile_check_ins` row with a server-computed `distance_meters`; and an `attendance_records` row for today with the right `first_in` and status.
9. Tap Check In again → `already_checked_in`. Tap Check Out → succeeds and `last_out` is set.
10. Set the location's coordinates far away → attempt a check-in → rejected as `outside_geofence`, and the rejected attempt **still appears in the admin Mobile Check-Ins log** with the real distance.
11. Revoke the device from Devices → attempt a check-in → the assertion is refused.

**Cross-channel (the core claim)**
12. For one employee on one day, ingest a device punch via the existing bio-service path **and** record a mobile check-out. Confirm `attendance_records` holds a **single** row whose `first_in` and `last_out` span both sources.
13. Approve a permission request covering a day the employee has no punches → confirm the sync engine still overwrites Absent → leave status, unaffected by the new column.
