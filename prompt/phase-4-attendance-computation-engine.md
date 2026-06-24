# Phase 4 — Attendance Computation Engine

## Context

Per `development_plan.md`, Phase 4 turns the raw punch events that Phase 3 now ingests into **accurate daily
attendance records**. Today the flagship `/attendance` page (`AttendanceController.php` →
`attendance/index.tsx`) renders **deterministic dummy cells** because no `attendance_records` table exists.
Phase 3 landed the ingest side (`raw_attendance_logs`, `biometric_devices`, `device_enrollments`,
`BiometricIngestController`) but nothing consumes those logs.

This phase builds the consumer: map raw punches → employees (via device enrollments), pair first-in/last-out
per day, compute worked/late/early minutes against each employee's `work_schedule`, derive a daily status
(Present / Late / Absent), and persist one idempotent record per employee per day. It also gives HR a
manual-correction UI with an audit trail, and replaces the dummy `/attendance` grid with real data. This is
the foundation the Phase 6 sync engine overwrites (Absent → approved leave types).

**Confirmed decisions (this session):**
- Statuses are a **PHP backed enum** `App\Enums\AttendanceStatus` (mirrors `App\Enums\RoleName`), stored as a
  string column — not an `attendance_status_types` table. Deliberate deviation from the data-model doc,
  documented here.
- A no-punch working day for an active employee produces an **`Absent`** record (so monthly reports are
  complete and Phase 6 has a row to flip).

## A. Enum — `app/Enums/AttendanceStatus.php` (new)

Backed enum mirroring `RoleName`. Cases: `Present='present'`, `Late='late'`, `Absent='absent'`,
`OfficialLeave='official_leave'`, `SickLeave='sick_leave'`, `PermissionApproved='permission_approved'`,
`FieldDuty='field_duty'`. Helpers: `values(): array`, `label(): string`, `isLeave(): bool`
(true for the four leave/permission cases — used by Phase 6 + KPI counts). The first three are the only
statuses Phase 4 *writes*; the leave cases exist so Phase 6 can set them without a schema change.

## B. Migrations — `yner_main/database/migrations/` (new ×2)

1. `create_attendance_records_table`: `id`, `foreignId employee_id` cascadeOnDelete, `date work_date`,
   `string status` default `'absent'`, `dateTime first_in` nullable, `dateTime last_out` nullable,
   `unsignedInteger worked_minutes` nullable, `unsignedInteger late_minutes` default 0,
   `unsignedInteger early_leave_minutes` default 0, `boolean is_manual` default false, `text remarks`
   nullable, timestamps, **`unique(['employee_id','work_date'])`** (the idempotency key).
2. `create_audit_logs_table` (generic, reused by Phase 6): `id`, `foreignId user_id` nullable nullOnDelete,
   `morphs('auditable')`, `string action`, `json old_values` nullable, `json new_values` nullable,
   `text note` nullable, timestamps.

## C. Models

- `app/Models/AttendanceRecord.php` (new): fillable for all columns; casts — `work_date`=>date,
  `first_in`/`last_out`=>datetime, `status`=>`AttendanceStatus::class`, `is_manual`=>bool, the three minute
  columns=>integer; `belongsTo(Employee)`.
- `app/Models/AuditLog.php` (new): fillable; casts `old_values`/`new_values`=>array; `belongsTo(User)`,
  `morphTo('auditable')`.
- `app/Models/Employee.php`: add `attendanceRecords(): HasMany` (mirror existing `deviceEnrollments()`).

## D. Computation service — `app/Services/AttendanceCalculator.php` (new)

Single class, the core of the phase.
- `computeForDate(CarbonInterface $date): array` → `{computed, skipped}`.
- `computeForRange(CarbonInterface $from, CarbonInterface $to): array`.
- Internals:
  - **Enrollment lookup:** join `device_enrollments` → `biometric_devices` once to build
    `"{serial}|{device_user_id}" => employee_id` (raw logs key on `device_serial`, enrollments on
    `biometric_device_id`).
  - Load `raw_attendance_logs` `whereDate('punched_at', $date)`, resolve each to an employee via the lookup,
    group punches by employee.
  - Iterate `Employee::where('status','active')->with('workSchedule')->get()`. For each:
    - **Skip if the existing record has `is_manual === true`** (preserves HR corrections — critical for
      idempotency + correction safety) and count it as skipped.
    - No punches → `Absent`, times null, minutes 0.
    - Punches → `first_in`=min, `last_out`=max (null when only one punch), `worked_minutes`=diff when both.
      With a `workSchedule`: `late_minutes = max(0, first_in − (start_time + grace_period_minutes))`,
      status `Late` if `late_minutes>0` else `Present`; `early_leave_minutes = max(0, end_time − last_out)`
      when `last_out` set. No schedule → `Present` with zero late/early.
    - `AttendanceRecord::updateOrCreate(['employee_id','work_date'], [...])`.
  - Stamp `processed_at = now()` on the consumed raw logs (traceability; recompute re-reads all logs for the
    date regardless, so it stays idempotent).

## E. Trigger: command, schedule, job, ingest hook

- `app/Console/Commands/ComputeAttendance.php` (new): signature
  `attendance:compute {date?} {--from=} {--to=}`; defaults to **yesterday** when no args; calls the
  calculator and prints a `{computed, skipped}` summary.
- `routes/console.php`: `Schedule::command('attendance:compute')->dailyAt('01:00');`.
- `app/Jobs/ComputeAttendanceForDate.php` (new, queued): wraps `AttendanceCalculator::computeForDate`.
- `app/Http/Controllers/Api/BiometricIngestController.php`: after `insertOrIgnore`, dispatch
  `ComputeAttendanceForDate` once per **distinct date** in the batch so ingested punches auto-flow into
  `attendance_records`. Response shape unchanged.

## F. HR manual-correction UI + real flagship page

- `app/Policies/AttendanceRecordPolicy.php` (new, auto-discovered): `viewAny` = any authenticated;
  `view` = Admin/HR or own (`$record->employee->user_id === $user->id`); `update` = Admin + HR only;
  no create/delete (records are computed, not hand-created).
- `AttendanceController@index` rework — replace dummy generators with real data:
  - Admin/HR → all active employees; Employee → only their linked `Employee` (filter by `user_id`).
  - Load the week's `attendance_records` (Sun-start week, matching current code) keyed `[employee_id][Y-m-d]`.
  - Map each record to the **existing `Cell` shape** plus correction fields: `type` from status
    (`isLeave`→`leave`, `Absent`→`absent`, present/late with `first_in` but no `last_out` on today→`active`,
    otherwise `hours`/`partial` by worked vs scheduled), `label` (hours string / status label), and
    `record: {id, status, first_in, last_out, remarks}` for the modal.
  - `stats`: real **today** counts — present, late, onLeave (`isLeave`), absent.
  - Pass `statusOptions` (enum value/label list) and `canCorrect` (`can('update', AttendanceRecord)`).
- `AttendanceController@update` (new) + route `Route::put('/attendance/{attendanceRecord}', ...)
  ->name('attendance.update')` in the `auth` group of `routes/web.php`:
  `authorize('update', $record)`; validate `status` in `AttendanceStatus::values()`, `first_in`/`last_out`
  nullable date, `remarks` required string; capture old attrs → `update([... , 'is_manual'=>true])` →
  write an `AuditLog` (`action:'attendance.corrected'`, old/new values, `note`=remarks, `user_id`); redirect
  back with flash `status`.
- Frontend:
  - `resources/js/components/attendance-correction-dialog.tsx` (new): mirrors the existing
    `*-form-dialog.tsx` pattern (e.g. `employee-form-dialog.tsx`) + `confirm-dialog.tsx`. `Dialog` + `useForm`
    (status `Select`, first_in/last_out inputs, remarks `Textarea`) → `put(route('attendance.update', id),
    { preserveScroll, onSuccess: close })`; 422 errors stay in the modal.
  - `resources/js/pages/attendance/index.tsx`: extend `Cell` with the optional `record`; when
    `canCorrect` and a cell has a record, clicking it opens the dialog. Keep the current visual design.

## G. Dev seed data — `database/seeders/AttendanceSeeder.php` (new)

Called from `DatabaseSeeder` after `OrgSeeder` so `/attendance` shows real data out of the box and the whole
pipeline is exercised:
- Ensure a stub `BiometricDevice` (`type:stub`, `serial:STUB-001`, active) and a `DeviceEnrollment` per
  sample employee (`device_user_id` = `employee_code`).
- Generate `raw_attendance_logs` across the current week (Mon-Fri) with realistic in/out times — include a
  late arrival and one absentee to show every pill type.
- Run `AttendanceCalculator::computeForRange` over the week to populate `attendance_records`.

## H. Tests — `yner_main/tests/Feature/` (PHPUnit + `RefreshDatabase`, mirror `DepartmentTest`/Phase-3 tests)

- `AttendanceCalculatorTest.php`: present vs late detection (grace boundary, `late_minutes`, status); no-punch
  active employee → `Absent`; `worked_minutes` correct; **idempotent** (run twice → one row, same values);
  **`is_manual` record not overwritten** by recompute.
- `ComputeAttendanceCommandTest.php`: `attendance:compute {date}` produces the expected records.
- `AttendanceCorrectionTest.php`: HR can correct (status changes, `is_manual` true, an `audit_logs` row with
  old/new + actor `user_id`); Admin allowed; **Employee forbidden (403)**; recompute after a manual
  correction leaves it intact.
- `BiometricIngestComputeTest.php`: `Bus::fake()` → ingest dispatches `ComputeAttendanceForDate` for the
  batch's distinct dates.

## Implementation order

A enum → B migrations → C models → D calculator → E command/job/schedule/ingest hook → F policy +
controller + route + React → G seeder → H tests. Build the calculator and its tests together (D+H first
slice) since everything downstream depends on correct computation.

## Verification

1. `cd yner_main && php artisan migrate:fresh --seed` — clean schema + seeded week of attendance.
2. `php artisan test` — all new Feature tests green (especially late-detection, no-punch→Absent, idempotency,
   manual-not-clobbered, Employee-forbidden-correction, ingest-dispatches-job).
3. `php artisan attendance:compute 2026-06-23` — prints a `{computed, skipped}` summary; re-run → same counts,
   no duplicate rows (idempotency).
4. `npm run build` — Vite/TS compiles clean.
5. Manual: log in as `admin@eapms.test`, open `/attendance` — real weekly grid + KPI cards; click a cell →
   correction modal, change status + add a reason, save → cell updates and an `audit_logs` row is written
   (verify via `tinker`). Log in as `employee@eapms.test` → sees only own row, no correction modal.
6. End-to-end: `POST /api/biometric/ingest` (with valid `X-Internal-Secret`) a punch for an enrolled
   `device_user_id`, run the queue (`php artisan queue:work --once`), confirm a matching `attendance_records`
   row appears.
