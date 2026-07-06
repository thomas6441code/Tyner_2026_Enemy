# Laravel Application Routes (`yner_main`)

All routes below come directly from `routes/web.php` and `routes/auth.php`. These are
**Inertia-rendered application routes**, not a public JSON API — most return an Inertia page
(server-driven React render) or a redirect with a flashed `status` message. The only routes
that return raw JSON/binary responses are noted explicitly.

Every route requires an authenticated session (`auth` middleware) unless listed under
**Guest routes**. Authorization beyond "logged in" is enforced per-method via
`$this->authorize(...)` (policy) or `Gate::authorize(...)`, referenced in the "Authorization"
column — see the policy classes under `app/Policies/` for the exact rules, or
[`docs/design/use-case-diagram.md`](../design/use-case-diagram.md) for the actor summary.

## Guest routes (`auth.php`, `middleware('guest')`)

| Method | Path | Controller@method | Purpose |
| --- | --- | --- | --- |
| GET | `/register` | `RegisteredUserController@create` | Registration form |
| POST | `/register` | `RegisteredUserController@store` | Create account |
| GET | `/login` | `AuthenticatedSessionController@create` | Login form |
| POST | `/login` | `AuthenticatedSessionController@store` | Authenticate |
| GET | `/forgot-password` | `PasswordResetLinkController@create` | Request reset form |
| POST | `/forgot-password` | `PasswordResetLinkController@store` | Send reset link |
| GET | `/reset-password/{token}` | `NewPasswordController@create` | Reset form |
| POST | `/reset-password` | `NewPasswordController@store` | Set new password |

## Authenticated account routes (`auth.php`, `middleware('auth')`)

| Method | Path | Controller@method | Purpose |
| --- | --- | --- | --- |
| GET | `/verify-email` | `EmailVerificationPromptController` | Prompt to verify email |
| GET | `/verify-email/{id}/{hash}` | `VerifyEmailController` | Verify (signed URL, throttled 6/min) |
| POST | `/email/verification-notification` | `EmailVerificationNotificationController@store` | Resend verification (throttled 6/min) |
| GET | `/confirm-password` | `ConfirmablePasswordController@show` | Re-auth confirmation form |
| POST | `/confirm-password` | `ConfirmablePasswordController@store` | Confirm current password |
| PUT | `/password` | `PasswordController@update` | Change password |
| POST | `/logout` | `AuthenticatedSessionController@destroy` | Log out |

## Dashboard

| Method | Path | Controller@method | Authorization | Renders |
| --- | --- | --- | --- | --- |
| GET | `/dashboard` | `DashboardController@index` | any authenticated user | `dashboard/admin`, `dashboard/hr`, or `dashboard/employee` depending on role |

## Attendance

| Method | Path | Controller@method | Authorization | Renders / Effect |
| --- | --- | --- | --- | --- |
| GET | `/attendance` | `AttendanceController@index` | `viewAny AttendanceRecord` (all authenticated; scope narrows to own record for Employee) | `attendance/index` — current-week grid |
| PUT | `/attendance/{attendanceRecord}` | `AttendanceController@update` | `update AttendanceRecord` (Admin/HR) | Manual correction; writes `audit_logs` (`attendance.corrected`) |

## Permission Requests

| Method | Path | Controller@method | Authorization | Renders / Effect |
| --- | --- | --- | --- | --- |
| GET | `/permission-requests` | `PermissionRequestController@index` | `viewAny` (all; scope narrows to own for Employee) | `permission-requests/index` |
| POST | `/permission-requests` | `PermissionRequestController@store` | `create` (must have a linked `Employee`) | Creates request (`status=pending`), notifies Admin/HR |
| PUT | `/permission-requests/{permissionRequest}` | `PermissionRequestController@update` | `update` (owner, while pending) | Updates request |
| DELETE | `/permission-requests/{permissionRequest}` | `PermissionRequestController@destroy` | `delete` (owner, while pending) | Soft-cancel (`status=cancelled`, row kept) |
| PUT | `/permission-requests/{permissionRequest}/review` | `PermissionRequestController@review` | `review` (Admin/HR, while pending) | Approve/reject; approval fires `PermissionRequestApproved` → sync engine |
| GET | `/permission-requests/{permissionRequest}/attachment` | `PermissionRequestController@attachment` | `view` | Streams the stored attachment file |

## Employees, Departments, Work Schedules, Biometric Devices, Device Enrollments

These five resources follow the same Laravel resource-route shape
(`Route::resource(...)->except(['show', 'create', 'edit'])` — no dedicated show/create/edit
pages; forms are modals on the index page):

| Resource | Base path | Authorization (viewAny / create / update / delete) |
| --- | --- | --- |
| Employees | `/employees` | Admin+HR / Admin+HR / Admin+HR / **Admin-only** (`EmployeePolicy`) |
| Departments | `/departments` | Admin+HR / **Admin-only** / **Admin-only** / **Admin-only** (`DepartmentPolicy`) |
| Work Schedules | `/work-schedules` | Admin+HR / **Admin-only** / **Admin-only** / **Admin-only** (`WorkSchedulePolicy`) |
| Biometric Devices | `/biometric-devices` | per `BiometricDevicePolicy` |
| Device Enrollments | `/device-enrollments` | per `DeviceEnrollmentPolicy` |

Each resource exposes:

| Method | Path | Effect |
| --- | --- | --- |
| GET | `/{resource}` | Index (paginated, 15/page) |
| POST | `/{resource}` | Store |
| PUT/PATCH | `/{resource}/{id}` | Update |
| DELETE | `/{resource}/{id}` | Destroy |

Notable validation rules:
- **Employees** — `employee_code` and `user_id` unique; `user_id` nullable (an employee may
  have no login yet).
- **Departments** — `name` unique.
- **Work Schedules** — `end_time` must be `after:start_time`; `grace_period_minutes` 0–120.
- **Biometric Devices** — `type` in `stub|zkteco|hikvision`; `serial` unique; blank
  `password` on update leaves the stored credential unchanged.
- **Device Enrollments** — `device_user_id` unique **per device** (not globally).

## AI Insights

| Method | Path | Controller@method | Authorization | Renders |
| --- | --- | --- | --- | --- |
| GET | `/ai-insights` | `AiInsightController@index` | `viewAny AiAnomaly` (Admin/HR) | `ai/insights` — latest anomalies (top 100), risk predictions, feature importances |

## Reports

| Method | Path | Controller@method | Authorization | Renders / Effect |
| --- | --- | --- | --- | --- |
| GET | `/reports` | `ReportController@index` | `Gate::authorize('viewReports')` (Admin/HR) | `reports/attendance` — range/department report + AI decision-support panel |
| GET | `/reports/export/csv` | `ReportController@exportCsv` | same | Streams a CSV download |
| GET | `/reports/export/pdf` | `ReportController@exportPdf` | same | Streams a PDF download (dompdf, A4) |

Query params (all optional, shared by all three): `from`, `to` (`Y-m-d`, default = current
month), `department_id`.

## Report Summaries (AI narrative)

| Method | Path | Controller@method | Authorization | Renders / Effect |
| --- | --- | --- | --- | --- |
| GET | `/report-summaries` | `ReportSummaryController@index` | `viewAny ReportSummary` (Admin/HR) | `reports/summaries` — last 60 cached summaries |
| POST | `/report-summaries` | `ReportSummaryController@store` | `create ReportSummary` | Generates (or loads cached) summary for `period` (`Y-m`) + optional `department_id`; `force=true` bypasses cache |

## Notifications

| Method | Path | Controller@method | Renders / Effect |
| --- | --- | --- | --- |
| GET | `/notifications` | `NotificationController@index` | `notifications/index` — paginated inbox (20/page) |
| PATCH | `/notifications/{notification}/read` | `NotificationController@markAsRead` | Marks one as read |
| PATCH | `/notifications/read-all` | `NotificationController@markAllAsRead` | Marks all unread as read |

## Profile

| Method | Path | Controller@method | Renders / Effect |
| --- | --- | --- | --- |
| GET | `/profile` | `ProfileController@edit` | `profile/edit` |
| PATCH | `/profile` | `ProfileController@update` | Updates name/email (re-verifies email if changed) |
| DELETE | `/profile` | `ProfileController@destroy` | Deletes account (requires `current_password`, `errorBag: userDeletion`) |
