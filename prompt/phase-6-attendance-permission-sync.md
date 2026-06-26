# Phase 6 — Attendance ⇄ Permission Synchronization (the core innovation)

## Context

Per `development_plan.md`, Phase 6 is the heart of the project: **eliminate the false "Absent"/null
records** that appear when an employee has an HR-approved permission/leave. Phases 4 and 5 built the two
halves and left a clean seam:

- **Phase 4** computes daily `attendance_records` from raw punches. With no punch it writes
  `status = Absent`. It already preserves HR manual corrections (`is_manual`) by skipping them.
- **Phase 5** built the request → approval lifecycle and, on approval, dispatches
  `App\Events\PermissionRequestApproved` (carrying the `PermissionRequest`) **with no listener**. That event
  is the seam Phase 6 attaches to. `PermissionType::toAttendanceStatus()` already maps each leave type to the
  `AttendanceStatus` the sync engine must write (OfficialLeave→OfficialLeave, SickLeave→SickLeave,
  Permission→PermissionApproved, FieldDuty→FieldDuty), so **no new mapping logic is needed**.

Phase 6 closes the loop: when HR approves a request, an event-driven, queued, idempotent, fully-audited sync
overlays the approved leave status onto `attendance_records` for every date in the request's range.

## The two-engine coexistence problem (key design decision)

Two writers now touch `attendance_records.status`:

1. The **AttendanceCalculator** (daily recompute) — writes Present/Late/Absent from punches.
2. The **AttendanceSyncService** (new) — writes the leave status on approval.

Naively, the nightly `attendance:compute` would clobber a synced leave day back to `Absent`. We solve this the
same way Phase 4 protects manual corrections: a record knows *why* it holds a leave status.

**New column `attendance_records.permission_request_id`** (nullable FK → `permission_requests`, `nullOnDelete`).
Semantics: "this day's status is backed by this approved permission." It makes the two engines converge
regardless of order:

- Sync sets `status = leave` **and** `permission_request_id`.
- The calculator, on a **no-punch** day whose record has `permission_request_id` set, **preserves** the leave
  status (counts it as skipped, exactly like `is_manual`).
- If a **punch later arrives** on a permission-backed day, the employee physically showed up: the calculator
  recomputes to Present/Late and **clears** `permission_request_id` (reality wins).

Both orderings (compute-then-approve, approve-then-compute) converge to the same result, and re-running either
engine is a no-op. `is_manual` still outranks everything.

## Conflict-resolution rules (punch vs approved leave)

Applied per date inside `AttendanceSyncService`:

1. **Manual correction present** (`is_manual = true`) → **skip**. HR's explicit override is final.
2. **Real punch present** (status Present/Late with `first_in` set) → **skip**. The employee was physically
   there; we never erase real attendance with a leave overlay. (Audited as skipped/conflict.)
3. **Absent / null / no record / already permission-backed** → **overlay** the leave status, set
   `permission_request_id`, `is_manual = false`, write a remark. Creates the record if the day has none yet
   (handles future-dated approvals before the calculator has run that day).
4. **Already synced to the same status & request** → **no-op skip** (no write, no audit) → idempotency.

## A. Migration — `database/migrations/2026_06_26_000001_add_permission_request_id_to_attendance_records_table.php` (new)

```php
Schema::table('attendance_records', function (Blueprint $table) {
    $table->foreignId('permission_request_id')->nullable()->after('is_manual')
        ->constrained()->nullOnDelete();
});
```
`down()` drops the FK + column.

## B. Model — `app/Models/AttendanceRecord.php`

- Add `'permission_request_id'` to `$fillable`.
- Cast `'permission_request_id' => 'integer'`.
- Add `permissionRequest(): BelongsTo` (→ `PermissionRequest`).

## C. Service — `app/Services/AttendanceSyncService.php` (new, the core)

- `syncForApproval(PermissionRequest $request): array` — returns `['created'=>int,'updated'=>int,'skipped'=>int]`.
  Loads the request's employee + target `AttendanceStatus` (`$request->type->toAttendanceStatus()`), iterates
  each date `start_date..end_date` inclusive, applies the conflict rules, returns the tally. Wrapped in a DB
  transaction.
- Private `applyForDate(Employee, CarbonInterface $date, AttendanceStatus $status, PermissionRequest $req): string`
  — `firstOrNew` on `(employee_id, work_date)`; runs the rules above; on overlay writes `status`,
  `permission_request_id`, `is_manual=false`, `remarks` ("Synced from approved {type label} (request #{id})");
  writes an `AuditLog` (auditable = the `AttendanceRecord`, `action='attendance.synced'`,
  `user_id = $req->reviewed_by`, old/new status snapshot). Returns `'created'|'updated'|'skipped'`.

Reuses the existing generic `AuditLog` morph (same pattern as Phase 4 corrections).

## D. AttendanceCalculator — `app/Services/AttendanceCalculator.php`

Inside `computeForDate`'s per-employee loop, right after the `is_manual` skip:

- If `$existing` has `permission_request_id !== null` **and** `$punches->isEmpty()` → preserve (skip, `$skipped++`).
- After deriving attributes, if `$existing` was permission-backed **and** punches now exist → add
  `'permission_request_id' => null` to the attributes so `updateOrCreate` clears the stale link (punch wins).

No change to `deriveAttributes`; the leave-preservation and link-clearing live in the loop. Phase 4 tests are
unaffected (no test data sets `permission_request_id`).

## E. Listener — `app/Listeners/SyncAttendanceForApprovedPermission.php` (new)

`implements ShouldQueue`. Constructor-injects `AttendanceSyncService`; `handle(PermissionRequestApproved $event)`
calls `syncForApproval($event->permissionRequest)`. Registered **explicitly** via `Event::listen(...)` in
`AppServiceProvider::boot()` (the skeleton has no `EventServiceProvider`; explicit registration is
deterministic and avoids any double-fire from auto-discovery). In tests the default `sync` queue driver runs
the listener inline, so an HTTP approve flips attendance within the request — ideal for the critical-path test.

## F. Command — `app/Console/Commands/SyncApprovedPermissions.php` (new)

`attendance:sync-permissions {--from=} {--to=}` — replays the sync over **all `Approved` permission requests**
(optionally filtered to those overlapping a date range), for retroactive backfill and to demo idempotency.
Mirrors `ComputeAttendance`'s structure; prints created/updated/skipped totals. Not scheduled (approval already
triggers sync); it's an operator/backfill tool.

## G. Seeder — `database/seeders/PermissionSeeder.php`

After creating the **Approved** sample (the past Sick-Leave that overlaps seeded attendance), call
`AttendanceSyncService::syncForApproval(...)` on each approved request so a fresh `migrate:fresh --seed`
visibly shows the Absent→Sick-Leave flip on `/attendance` out of the box (the seeder writes rows directly, so
the event never fires — sync must be invoked explicitly). The Sick-Leave sample's dates (`-5..-4` days) fall
in the seeded current week, demonstrating the core fix immediately.

## H. Tests — `tests/Feature/AttendancePermissionSyncTest.php` (new, PHPUnit + `RefreshDatabase`)

Mirror `AttendanceCalculatorTest`/`AttendanceCorrectionTest`. Cover:

1. **Critical path:** employee with no punch on date D → `syncForApproval` an Approved Permission covering D →
   record flips `Absent`/none → leave status, `permission_request_id` set, one `attendance.synced` audit row.
2. **Idempotency:** running sync twice → one record, exactly one audit row, same status.
3. **Multi-day range:** a 3-day Official Leave → 3 leave records created.
4. **Conflict — punch wins:** a Present/Late record with `first_in` → sync **skips** (stays Present, no link).
5. **Manual correction wins:** `is_manual` record → sync skips (untouched).
6. **Calculator preserves synced leave:** sync a no-punch day → `computeForDate` (no punches) keeps the leave
   status (not Absent).
7. **Punch clears the link:** synced leave day, then a punch + `computeForDate` → Present/Late,
   `permission_request_id` cleared.
8. **Event wiring (end-to-end):** HR approves via the `permission-requests.review` HTTP route → attendance
   flips (the queued listener ran inline). One sync audit row.

## Implementation order

A migration → B model → C service → D calculator → E listener+registration → F command → G seeder →
H tests. Build the service + tests together (the conflict matrix is the riskiest part) before the seeder/command.

## Out of scope (later phases)

- Reverting attendance when an approved request is later cancelled/rejected — current policy can't cancel an
  approved request, so no revert path is built here.
- Monthly report / export reflecting permissions — **Phase 10** (this phase makes the daily grid correct).
- Notifications on sync — **Phase 9**.

## Verification

1. `php artisan migrate:fresh --seed` — clean schema with the new column; `/attendance` shows the seeded
   approved Sick-Leave days as **Sick Leave** (violet), not Absent.
2. `php artisan test --filter Sync` (and full `php artisan test`) green — sync, idempotency, conflict,
   calculator-coexistence, and HTTP-approve-flips-attendance all pass; Phase 4/5 suites unaffected.
3. `php artisan attendance:sync-permissions` — idempotent backfill prints sane created/updated/skipped totals;
   re-running reports everything skipped.
4. `npm run build` — Vite/TS compiles clean (the existing `/attendance` page already renders leave pills).
5. Manual: log in as `hr@eapms.test`, approve a Pending request whose dates include an Absent day for that
   employee → that day flips to the leave status; an `audit_logs` row with `action='attendance.synced'` exists.
