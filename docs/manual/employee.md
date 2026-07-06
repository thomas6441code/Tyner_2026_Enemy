# Employee Manual

Applies to any user whose account holds only the **Employee** role. An Employee only ever
sees data linked to their own `Employee` record (via `employees.user_id`).

## Dashboard (`/dashboard`)

Shows your own employee record — name, department, and work schedule — pulled straight from
`dashboard/employee`. If you see "no employee record linked," ask HR/Admin to link your user
account to your employee profile (`employees.user_id`).

## My Attendance (`/attendance`)

A weekly grid (Sunday–Saturday) showing only your own row. Each day cell shows:

- **Hours** (e.g. "8 Hours" or "7h 30m") if you completed a full in/out punch.
- **Active** if you've signed in today but haven't signed out yet.
- **Absent** if there's no punch and no approved leave for that day.
- A leave label (**Official Leave / Sick Leave / Permission Approved / Field Duty**) if HR
  approved a permission request covering that date — this is the system's core guarantee: an
  approved absence never shows as plain "Absent."

You cannot edit your own attendance — corrections are HR/Admin-only.

## Requesting Permission / Leave (`/permission-requests`)

1. Click **New Request** (only visible if your user account is linked to an `Employee`
   record — `PermissionRequestPolicy::create` requires this).
2. Choose a **type**: Official Leave, Sick Leave, Permission, or Field Duty.
3. Set a **start date** and **end date** (end must be on/after start).
4. Enter a **reason** (required, up to 1000 characters).
5. Optionally attach a **file** (PDF/JPG/PNG, max 4 MB) — e.g. a medical certificate.
6. Submit. Your request starts as **Pending** and Admin/HR are notified.

While a request is still **Pending**, you can:
- **Edit** it (change type/dates/reason/attachment).
- **Cancel** it (soft-cancel — status becomes **Cancelled**, the record is kept for history).

Once HR/Admin reviews it (**Approved** or **Rejected**), you can no longer edit or cancel it,
and you're notified of the decision. If **Approved**, the system automatically updates your
attendance for every date in the range — you don't need to do anything else.

## Notifications (`/notifications`)

Your personal inbox: submission confirmations, review decisions on your requests, and
(if your account has no punch yet for the day) a morning sign-in reminder. Mark individual
items read, or use **Mark all as read**.

## Profile (`/profile`)

Update your name/email (changing email requires re-verification) or your password. You can
also delete your own account here (requires entering your current password).
