<?php

namespace App\Http\Controllers;

use App\Enums\RegistrationStatus;
use App\Models\AccountInvitation;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\RegistrationRequest;
use App\Models\WorkSchedule;
use App\Notifications\SystemNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin/HR review queue for public registration requests.
 *
 * Approval is where the HR record is born: the applicant supplies identity, HR supplies
 * everything the attendance engine needs (employee code, department, schedule, hire date).
 */
class RegistrationRequestReviewController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', RegistrationRequest::class);

        $user = $request->user();

        $requests = RegistrationRequest::with(['department', 'reviewer', 'employee'])
            ->orderByRaw("status = 'pending' desc")
            ->latest()
            ->paginate(15)
            ->through(fn (RegistrationRequest $req) => [
                'id' => $req->id,
                'name' => $req->fullName(),
                'email' => $req->email,
                'phone' => $req->phone,
                'department' => $req->department?->name,
                'note' => $req->note,
                'status' => $req->status->value,
                'status_label' => $req->status->label(),
                'reviewer' => $req->reviewer?->name,
                'review_note' => $req->review_note,
                'reviewed_at' => $req->reviewed_at?->toDateTimeString(),
                'employee_code' => $req->employee?->employee_code,
                'submitted_at' => $req->created_at?->toDateTimeString(),
                'can_review' => $user->can('review', $req),
            ]);

        return Inertia::render('registration-requests/index', [
            'requests' => $requests,
            'stats' => [
                'pending' => RegistrationRequest::where('status', RegistrationStatus::Pending)->count(),
                'approved' => RegistrationRequest::where('status', RegistrationStatus::Approved)->count(),
                'rejected' => RegistrationRequest::where('status', RegistrationStatus::Rejected)->count(),
            ],
            'formData' => [
                'departments' => Department::orderBy('name')->get(['id', 'name']),
                'workSchedules' => WorkSchedule::orderBy('name')->get(['id', 'name']),
            ],
            'status' => session('status'),
            'invitationUrl' => session('invitationUrl'),
        ]);
    }

    /**
     * Approve a request: create the HR record and issue a single-use activation link.
     *
     * The Employee is created `inactive` on purpose. AttendanceCalculator::computeForDate()
     * only iterates active employees, so an approved-but-not-yet-activated person generates
     * no phantom Absent records while their invitation sits unredeemed.
     */
    public function approve(Request $request, RegistrationRequest $registrationRequest): RedirectResponse
    {
        $this->authorize('review', $registrationRequest);

        $validated = $request->validate([
            'employee_code' => ['required', 'string', 'max:50', Rule::unique('employees', 'employee_code')],
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'work_schedule_id' => ['required', 'integer', 'exists:work_schedules,id'],
            'hire_date' => ['required', 'date'],
        ]);

        $result = DB::transaction(function () use ($request, $registrationRequest, $validated) {
            $employee = Employee::create([
                'department_id' => $validated['department_id'],
                'work_schedule_id' => $validated['work_schedule_id'],
                'employee_code' => $validated['employee_code'],
                'first_name' => $registrationRequest->first_name,
                'last_name' => $registrationRequest->last_name,
                'phone' => $registrationRequest->phone,
                'hire_date' => $validated['hire_date'],
                'status' => 'inactive',
            ]);

            // Assigned individually, not via update(): these are deliberately absent from
            // $fillable so the anonymous public store() path can never reach them.
            $registrationRequest->status = RegistrationStatus::Approved;
            $registrationRequest->employee_id = $employee->id;
            $registrationRequest->reviewed_by = $request->user()->id;
            $registrationRequest->reviewed_at = now();
            $registrationRequest->save();

            return AccountInvitation::issue(
                $employee,
                $registrationRequest->email,
                $registrationRequest,
                $request->user(),
            );
        });

        $activationUrl = route('account.activate', [
            'uuid' => $result['invitation']->uuid,
            'token' => $result['plainToken'],
        ]);

        $this->audit($request, $registrationRequest, 'registration.approved', null);

        // No User exists yet, so this is an on-demand mail notification.
        Notification::route('mail', $registrationRequest->email)
            ->notify(SystemNotification::registrationRequestApproved(
                $registrationRequest->fullName(),
                $activationUrl,
            ));

        // MAIL_MAILER is `log` in dev/staging, so the link must also be surfaced in the UI.
        // This is the only moment the plaintext token exists — it is not recoverable later.
        return redirect()->route('registration-requests.index')
            ->with('status', 'Registration approved. Share the activation link below.')
            ->with('invitationUrl', $activationUrl);
    }

    /**
     * Reject a request with a mandatory reason.
     */
    public function reject(Request $request, RegistrationRequest $registrationRequest): RedirectResponse
    {
        $this->authorize('review', $registrationRequest);

        $validated = $request->validate([
            'review_note' => ['required', 'string', 'max:1000'],
        ]);

        $registrationRequest->status = RegistrationStatus::Rejected;
        $registrationRequest->reviewed_by = $request->user()->id;
        $registrationRequest->reviewed_at = now();
        $registrationRequest->review_note = $validated['review_note'];
        $registrationRequest->save();

        $this->audit($request, $registrationRequest, 'registration.rejected', $validated['review_note']);

        Notification::route('mail', $registrationRequest->email)
            ->notify(SystemNotification::registrationRequestRejected(
                $registrationRequest->fullName(),
                $validated['review_note'],
            ));

        return redirect()->route('registration-requests.index')
            ->with('status', 'Registration request rejected.');
    }

    private function audit(Request $request, RegistrationRequest $registrationRequest, string $action, ?string $note): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'auditable_type' => RegistrationRequest::class,
            'auditable_id' => $registrationRequest->id,
            'action' => $action,
            'old_values' => ['status' => RegistrationStatus::Pending->value],
            'new_values' => [
                'status' => $registrationRequest->status->value,
                'employee_id' => $registrationRequest->employee_id,
            ],
            'note' => $note,
        ]);
    }
}
