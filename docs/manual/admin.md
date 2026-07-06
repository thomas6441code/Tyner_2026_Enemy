# Admin Manual

Everything in the [HR Officer manual](hr-officer.md) applies. This manual covers the
Admin-only capabilities — org structure and biometric infrastructure configuration.

## Employees — delete

Admin is the only role that can **delete** an employee record (`EmployeePolicy::delete`).
Deleting an employee cascades to their `device_enrollments` and nulls the employee link on
their `attendance_records`/`permission_requests` foreign keys (per the migrations'
`nullOnDelete`/`cascadeOnDelete` rules) — history is not silently lost, but review before
deleting.

## Departments (`/departments`)

Full CRUD (`DepartmentPolicy` — create/update/delete are Admin-only; HR can still view).
`name` must be unique. The index shows: total departments, total assigned employees, average
employees per department, and how many departments have zero employees.

## Work Schedules (`/work-schedules`)

Full CRUD (`WorkSchedulePolicy` — Admin-only for create/update/delete). Each schedule has a
`name` (unique), `start_time`/`end_time` (end must be after start), and a
`grace_period_minutes` (0–120) — the window after `start_time` before a punch counts as
**Late**. Deleting or editing a schedule affects every employee assigned to it.

## Biometric Devices (`/biometric-devices`)

Register the physical fingerprint terminals bio-service polls:

1. **Name**, **type** (`stub` for testing / `zkteco` / `hikvision`), a unique **serial**.
2. Connection details: **host**, **port**, **username**, **password** (device credentials —
   leave password blank on edit to keep the existing one unchanged).
3. **Status** (`active`/`inactive`) — only `active` devices are returned to bio-service's poll
   registry (`GET /api/biometric/devices`), so setting a device `inactive` immediately stops
   it from being polled.

## Device Enrollments (`/device-enrollments`)

Maps a physical device's internal user ID to an EAPMS employee:

1. Choose the **device** and the **employee**.
2. Enter the **device_user_id** — the ID the fingerprint terminal itself assigns to that
   person (not the EAPMS `employee_code`). This must be unique **per device** (the same
   `device_user_id` can exist on two different devices for the same employee if they're
   enrolled on multiple terminals).

Without a correct enrollment row, punches from that terminal for that person cannot be
matched to an employee and will not produce attendance records.

## System oversight

As Admin you have unrestricted access to every page in the [HR Officer manual](hr-officer.md)
(employees, permission requests, attendance corrections, reports, report summaries, AI
insights) with no scope restriction, plus the org-configuration pages above. For operational
tasks (starting/stopping services, scheduled jobs, environment variables, troubleshooting),
see [`../runbook.md`](../runbook.md).
