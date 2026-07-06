# HR Officer Manual

Everything in the [Employee manual](employee.md) applies if your account also has a linked
`Employee` record. This manual covers the additional HR Officer capabilities.

## Dashboard (`/dashboard`)

`dashboard/hr` shows organization-wide headcount (total + active) and a per-department
breakdown with employee counts.

## Employees (`/employees`)

Full CRUD except delete (Admin-only, per `EmployeePolicy`):

- **Create/Edit** — employee code, first/last name, phone, hire date, status
  (active/inactive), department, work schedule, and optionally link to a user login. A user
  can only be linked to one employee at a time (unique constraint), and an employee can exist
  with no linked login yet.
- The index shows stats: total, active, inactive, and "unlinked" (employees with no user
  account yet).

## Permission Requests (`/permission-requests`)

You see **every** employee's requests (not just your own, if you're also an Employee). For
each **Pending** request you can:

1. Open the request and choose **Approve** or **Reject**.
2. If rejecting, a **review note** is required (up to 1000 characters); it's optional on
   approval.
3. On **Approve**, the system immediately (queued) synchronizes attendance: every date in the
   request's range is overlaid onto `attendance_records` with the corresponding status
   (Official Leave / Sick Leave / Permission Approved / Field Duty) — unless that date already
   has a real punch or a manual HR correction, which always take precedence. See
   [`docs/design/flowcharts.md`](../design/flowcharts.md) for the exact conflict rules.

You cannot review a request that isn't **Pending** (already-decided requests are locked).

## Attendance corrections (`/attendance`)

As HR you see **every active employee's** weekly grid, and each cell is editable:

1. Click a day cell to open the correction dialog.
2. Set the correct **status**, optionally **first-in**/**last-out** times (`HH:MM`), and a
   **required remark** explaining the correction.
3. Save — this marks the record `is_manual = true` (so it will never be overwritten by the
   nightly recompute or a leave sync) and logs the change to the audit trail.

## Reports (`/reports`)

1. Filter by date range (`from`/`to`, defaults to the current month) and optionally a
   department.
2. View per-employee rows: present/late/absent/leave day counts, worked hours, late minutes,
   attendance %, punctuality %.
3. A **decision-support panel** shows the top 5 highest-risk employees (from AI scoring) and
   the latest AI narrative summary for the selected month/department, if one exists.
4. **Export CSV** or **Export PDF** to download the same report.

## Report Summaries (`/report-summaries`)

1. Pick a **month** (and optionally a department) and click **Generate**.
2. The system aggregates that month's stats and asks the AI service for a narrative +
   highlights + recommendations. Re-requesting the same month/department **loads the cached
   result** unless you check **Force regenerate**.
3. If the AI service is unreachable, you'll see a friendly message and nothing is saved — no
   crash, and no wasted Claude API call.
4. If the summary was produced by the offline template (Claude API key not configured), it's
   flagged as such rather than presented as a live AI narrative.

## AI Insights (`/ai-insights`)

Read-only decision-support view:
- **Anomalies** — the latest 100 flagged (employee, date) attendance anomalies, with a
  plain-English explanation each (from Isolation Forest or the per-employee z-score check).
- **Predictions** — every employee's current absenteeism/lateness **risk score** and level
  (low/medium/high), with the top factors driving their score.
- **Feature importances** — which signals the risk model relies on most, for transparency.

These are refreshed nightly by the scheduled `ai:score-attendance` job (see
[`../runbook.md`](../runbook.md)).

## Notifications

In addition to your own-request notifications, you receive: new permission-request
submissions from any employee, and system alerts when AI scoring or a report summary
completes.
