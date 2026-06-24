# Phase 5 — HR Permission & Leave Management

## Context

Per `development_plan.md`, Phase 5 digitizes the **HR side** of the core integration. Today an employee
with an HR-approved absence still appears **Absent** in attendance reports because there is no permission
workflow at all. This phase builds the request → approval lifecycle: an employee submits a permission/leave
request (type, date range, reason, optional attachment); HR/Admin approve or reject it with a note; every
state change is audited.

Phase 5 deliberately stops short of touching `attendance_records` — that overwrite is **Phase 6** (the sync
engine). So this phase ends by **dispatching a domain event on approval** (`PermissionRequestApproved`) with
no listener yet. That event is the seam Phase 6 attaches its sync job to, exactly as the plan's "event-driven
… approvals trigger immediate resync" note requires. The data we persist (employee, leave type, date range,
approved status) is precisely what the sync engine consumes.

**Confirmed design decisions (consistent with prior phases):**
- Permission **types** and request **status** are PHP backed enums (`App\Enums\PermissionType`,
  `App\Enums\PermissionStatus`), mirroring `App\Enums\AttendanceStatus` / `RoleName`. This is the same
  deliberate deviation Phase 4 documented (enum + string column instead of the `permission_types` /
  status-types lookup tables named in the data-model doc).
- `PermissionType` maps each case to the `AttendanceStatus` leave case the sync engine will write, via a
  `toAttendanceStatus()` helper — so Phase 6 needs no new mapping logic.
- Audit trail reuses the existing generic `App\Models\AuditLog` (built in Phase 4), via the `morphTo`
  `auditable` relation.
- Frontend follows the established Inertia modal-CRUD pattern (index page + `*-form-dialog.tsx` +
  `confirm-dialog.tsx`), no new GET form pages.

## A. Enums — `app/Enums/` (new ×2)

1. `PermissionType.php` — backed enum: `OfficialLeave='official_leave'`, `SickLeave='sick_leave'`,
   `Permission='permission'`, `FieldDuty='field_duty'`. Helpers `values()`, `label()`, and
   `toAttendanceStatus(): AttendanceStatus` (OfficialLeave→OfficialLeave, SickLeave→SickLeave,
   Permission→PermissionApproved, FieldDuty→FieldDuty — for Phase 6).
2. `PermissionStatus.php` — backed enum: `Pending='pending'`, `Approved='approved'`, `Rejected='rejected'`,
   `Cancelled='cancelled'`. Helpers `values()`, `label()`.

## B. Migration — `database/migrations/2026_06_24_000003_create_permission_requests_table.php` (new)

Columns: `id`, `foreignId employee_id` cascadeOnDelete, `string type`, `date start_date`, `date end_date`,
`text reason`, `string attachment_path` nullable, `string status` default `'pending'`,
`foreignId reviewed_by` nullable nullOnDelete (→ users), `text review_note` nullable,
`timestamp reviewed_at` nullable, timestamps. Index `['employee_id','status']`.

## C. Models

- `app/Models/PermissionRequest.php` (new): fillable for all columns; casts `type`=>`PermissionType::class`,
  `status`=>`PermissionStatus::class`, `start_date`/`end_date`=>date, `reviewed_at`=>datetime;
  `belongsTo(Employee)`, `belongsTo(User,'reviewed_by')` as `reviewer()`. Helper `isPending(): bool`.
- `app/Models/Employee.php`: add `permissionRequests(): HasMany` (mirror existing `attendanceRecords()`).

## D. Policy — `app/Policies/PermissionRequestPolicy.php` (new, auto-discovered)

- `viewAny`: any authenticated (controller scopes the list).
- `view`: Admin/HR, or owner (`$req->employee?->user_id === $user->id`).
- `create`: any authenticated user **linked to an Employee** (`$user->employee()->exists()`) — Admin/HR also
  allowed (they submit on behalf / for their own linked record).
- `update`: owner **while Pending** only (edit a not-yet-reviewed request).
- `delete`: owner **while Pending** (treated as "cancel"; controller sets status→Cancelled rather than hard
  delete — see note).
- `review` (custom ability for approve/reject): Admin/HR only, and only when the request `isPending()`.

Register nothing extra — Laravel 12 auto-discovers `App\Policies\PermissionRequestPolicy` for
`App\Models\PermissionRequest`.

## E. Events — `app/Events/` (new ×1, the Phase 6 seam)

- `PermissionRequestApproved.php` (new): carries the `PermissionRequest`. Dispatched by the controller on
  approval. **No listener in Phase 5** — Phase 6 registers the sync job listener. (A matching
  `PermissionRequestRejected` is not needed yet; rejection has no attendance side-effect.)

## F. Controller — `app/Http/Controllers/PermissionRequestController.php` (new)

Mirrors `EmployeeController` structure (explicit `$this->authorize(...)` per method, `actions()` helper,
`formData()` helper).

- `index(Request)`: `authorize('viewAny', PermissionRequest::class)`.
  - Admin/HR → all requests with `employee` + `reviewer`; Employee → only their own
    (`whereHas('employee', fn($q)=>$q->where('user_id',$user->id))`).
  - `orderByRaw("status='pending' desc")->latest()`, paginate 15, map to a flat shape (id, employee name,
    type value+label, start/end date, reason, status value+label, reviewer name, review_note, reviewed_at,
    attachment present bool, plus per-row `canReview`/`canCancel`/`canEdit` booleans).
  - `stats`: counts by status (pending, approved, rejected). `actions.create` flag. `types`/`statuses`
    option lists for the form/filters. `status` flash.
- `store(Request)`: `authorize('create', PermissionRequest::class)`; validate `type` in
  `PermissionType::values()`, `start_date` date, `end_date` date `after_or_equal:start_date`, `reason`
  required string, `attachment` nullable file (`mimes:pdf,jpg,jpeg,png`, `max:4096`). Resolve the acting
  employee (`$user->employee`); store attachment via `->store('permission-attachments')` on the `local`
  disk; create with `status=Pending`; write an `AuditLog` (`action:'permission.submitted'`). Redirect with
  flash.
- `update(Request, PermissionRequest)`: `authorize('update', $req)`; same validation (attachment optional,
  replace if provided); update fields (status stays Pending); audit `permission.updated`.
- `destroy(PermissionRequest)`: `authorize('delete', $req)`; set `status=Cancelled` (soft cancel, keeps
  history) + audit `permission.cancelled`; redirect.
- `review(Request, PermissionRequest)`: `authorize('review', $req)`; validate
  `decision` in `['approved','rejected']`, `review_note` nullable string (required when rejected). Set
  `status`, `reviewed_by=$user->id`, `reviewed_at=now()`, `review_note`; capture old/new → `AuditLog`
  (`action:'permission.reviewed'`). **If approved → `event(new PermissionRequestApproved($req))`.** Redirect
  with flash.
- `attachment(PermissionRequest)`: `authorize('view', $req)`; stream the stored file
  (`Storage::download($req->attachment_path)`), 404 when none.

## G. Routes — `routes/web.php` (inside the existing `auth` group)

```php
Route::resource('permission-requests', PermissionRequestController::class)->except(['show','create','edit']);
Route::put('permission-requests/{permissionRequest}/review', [PermissionRequestController::class, 'review'])
    ->name('permission-requests.review');
Route::get('permission-requests/{permissionRequest}/attachment', [PermissionRequestController::class, 'attachment'])
    ->name('permission-requests.attachment');
```
Add the controller import at the top.

## H. Shared nav prop + sidebar

- `app/Http/Middleware/HandleInertiaRequests.php`: add
  `'viewPermissionRequests' => $user?->can('viewAny', PermissionRequest::class) ?? false` to the `can` array
  (everyone authenticated sees it — employees see their own).
- `resources/js/components/app-sidebar.tsx`: add a "Permissions" item under the **Activities** group
  (icon e.g. `CalendarClock` or `FileText` from lucide), `route('permission-requests.index')`,
  `active: route().current('permission-requests.*')`, `show: can.viewPermissionRequests`.

## I. Frontend — `resources/js/`

- `components/permission-request-form-dialog.tsx` (new): mirrors `employee-form-dialog.tsx`. `useForm` with
  `type`, `start_date`, `end_date`, `reason`, `attachment` (File). File input via shadcn `Input type=file`;
  must `post`/`put` with `forceFormData: true` for the upload. Type `Select`, date inputs, reason `Textarea`.
  422 errors stay in the modal.
- `components/permission-review-dialog.tsx` (new): small dialog for HR/Admin — shows request details,
  Approve / Reject buttons, `review_note` `Textarea` (required toggle when rejecting); `put` to
  `permission-requests.review`.
- `pages/permission-requests/index.tsx` (new): mirrors `employees/index.tsx`. KPI `StatCard`s (Pending,
  Approved, Rejected). Status filter chips (client-side or `router.get` with query). Table columns:
  Employee (hidden/redundant for the employee's own view but kept for HR), Type (badge), Dates, Status
  (badge — amber/emerald/rose/slate), Reviewer, Actions. Actions per row: View (details + attachment link),
  Edit/Cancel when `canEdit`/`canCancel`, **Review** (approve/reject) when `canReview`. "New Request" button
  gated on `actions.create`.

## J. Seeder — `database/seeders/PermissionSeeder.php` (new)

Called from `DatabaseSeeder` after `AttendanceSeeder`. Create a handful of sample requests for the seeded
employee: one Pending, one Approved (reviewed_by = the seeded HR user), one Rejected — covering each badge
and giving HR something to action out of the box. No attachment files needed (path null).

## K. Tests — `tests/Feature/PermissionRequestTest.php` (new, PHPUnit + `RefreshDatabase`)

Mirror `AttendanceCorrectionTest`/`DepartmentTest`. Cover:
- Employee with a linked Employee record can submit (status Pending, audit row written).
- `end_date` before `start_date` → 422; `reason` required → 422; bad `type` → 422.
- Index scoping: Employee sees only own requests; Admin/HR see all.
- HR can approve → status Approved, `reviewed_by`/`reviewed_at` set, audit `permission.reviewed`,
  and `PermissionRequestApproved` dispatched (`Event::fake()` + `assertDispatched`).
- HR can reject with a `review_note`; rejecting **without** a note → 422.
- Employee **cannot** review (403); cannot review an already-reviewed request (403).
- Owner can cancel own Pending (status→Cancelled); cannot edit/cancel once Approved (403).
- Attachment upload (`UploadedFile::fake()->create('leave.pdf')`) is stored and downloadable by HR;
  forbidden to an unrelated employee (403).

## Implementation order

A enums → B migration → C models → D policy → E event → F controller → G routes → H nav → I React →
J seeder → K tests. Build policy + controller + tests together (the authorization matrix is the riskiest
part) before wiring the frontend.

## Out of scope (later phases, do not build here)

- The actual attendance overwrite on approval — **Phase 6** (this plan only dispatches the event).
- Real notification delivery (in-app/email) — **Phase 9**. Phase 5 only writes audit rows + the event seam.
- Dashboard "pending approvals" KPI cards — **Phase 10**.

## Verification

1. `cd yner_main && php artisan migrate:fresh --seed` — clean schema; seeded sample requests appear.
2. `php artisan test --filter Permission` — all new Feature tests green (submit, validation, scoping,
   approve+event, reject-needs-note, employee-forbidden-review, cancel rules, attachment authz).
3. `npm run build` — Vite/TS compiles clean.
4. Manual: log in as `employee@eapms.test` → /permissions → submit a Sick Leave request with a PDF →
   appears Pending, can cancel. Log in as `hr@eapms.test` → sees it → Approve with a note → status flips to
   Approved, reviewer shown; reject path requires a note. Confirm an `audit_logs` row per action via tinker.
5. Confirm `php artisan test` (full suite) still green — Phase 4 tests unaffected.
