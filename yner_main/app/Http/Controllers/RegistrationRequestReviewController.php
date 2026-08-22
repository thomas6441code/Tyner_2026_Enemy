<?php

namespace App\Http\Controllers;

use App\Enums\RegistrationStatus;
use App\Http\Concerns\HasIndexFilters;
use App\Models\AccountInvitation;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\RegistrationRequest;
use App\Models\WorkLocation;
use App\Models\WorkSchedule;
use App\Notifications\SystemNotification;
use App\Services\EmployeeCodeGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Admin/HR review queue for public registration requests.
 *
 * Approval is where the HR record is born: the applicant supplies identity, HR supplies
 * everything the attendance engine needs (employee code, department, schedule, hire date).
 */
class RegistrationRequestReviewController extends Controller
{
    use HasIndexFilters;

    /**
     * @return array<string, mixed>
     */
    private function sortable(): array
    {
        return [
            'name' => ['last_name', 'first_name'],
            'email' => 'email',
            'department' => Department::select('name')
                ->whereColumn('departments.id', 'registration_requests.department_id'),
            'status' => 'status',
            'submitted' => 'created_at',
        ];
    }

    public function __construct(private readonly EmployeeCodeGenerator $codes) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', RegistrationRequest::class);

        $user = $request->user();

        $filters = $this->indexFilters($request, $this->sortable(), 'submitted', 'desc');

        $query = RegistrationRequest::with(['department', 'reviewer', 'employee']);

        $this->applySearch($query, $filters['search'], [
            'first_name', 'last_name', 'email', 'phone', 'note',
            'department.name',
        ]);

        // Pending on top by default; a chosen column takes over completely.
        if (! $filters['explicit']) {
            $query->orderByRaw("status = 'pending' desc");
        }

        $this->applySort($query, $filters, $this->sortable());

        $requests = $query
            ->paginate(15)
            ->withQueryString()
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
            'filters' => $filters,
            'stats' => [
                'pending' => RegistrationRequest::where('status', RegistrationStatus::Pending)->count(),
                'approved' => RegistrationRequest::where('status', RegistrationStatus::Approved)->count(),
                'rejected' => RegistrationRequest::where('status', RegistrationStatus::Rejected)->count(),
            ],
            'formData' => [
                // Shown in the approve dialog so the reviewer knows which code they are about
                // to mint. Display only — the server allocates the real one on approval.
                'nextEmployeeCode' => $this->codes->peek(),
                'departments' => Department::orderBy('name')->get(['id', 'name']),
                'workSchedules' => WorkSchedule::orderBy('name')->get(['id', 'name']),
                'workLocations' => WorkLocation::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            ],
            'status' => session('status'),
            'invitationUrl' => session('invitationUrl'),
            'invitationEmail' => session('invitationEmail'),
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
            // No `employee_code`: the server allocates it. Asking a reviewer to invent a unique
            // key mid-approval is how duplicates and typos get in, and a rejected approval at
            // this point has already half-happened in the reviewer's head.
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'work_schedule_id' => ['required', 'integer', 'exists:work_schedules,id'],
            // Optional, unlike the others: an employee with no location of their own inherits
            // their department's, and only the mobile channel needs one at all.
            'work_location_id' => ['nullable', 'integer', 'exists:work_locations,id'],
            'hire_date' => ['required', 'date'],
        ]);

        $result = DB::transaction(function () use ($request, $registrationRequest, $validated) {
            $employee = $this->codes->create([
                'department_id' => $validated['department_id'],
                'work_schedule_id' => $validated['work_schedule_id'],
                'work_location_id' => $validated['work_location_id'] ?? null,
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

        $emailed = $this->mailActivationLink(
            $registrationRequest->email,
            $registrationRequest->fullName(),
            $activationUrl,
        );

        // MAIL_MAILER is `log` in dev/staging, so the link must also be surfaced in the UI.
        // This is the only moment the plaintext token exists — it is not recoverable later.
        return redirect()->route('registration-requests.index')
            ->with('status', $emailed
                ? "Registration approved. The activation link was emailed to {$registrationRequest->email}."
                : "Registration approved, but the activation link could not be emailed to {$registrationRequest->email}. Share the link below directly.")
            ->with('invitationUrl', $activationUrl)
            ->with('invitationEmail', $emailed ? $registrationRequest->email : null);
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

        try {
            Notification::route('mail', $registrationRequest->email)
                ->notify(SystemNotification::registrationRequestRejected(
                    $registrationRequest->fullName(),
                    $validated['review_note'],
                ));
        } catch (Throwable $e) {
            // The decision is already committed; a mail outage must not turn it into a 500.
            Log::warning('Rejection notice could not be delivered.', [
                'registration_request_id' => $registrationRequest->id,
                'error' => $e->getMessage(),
            ]);
        }

        return redirect()->route('registration-requests.index')
            ->with('status', 'Registration request rejected.');
    }

    /**
     * Deliver the activation link to the applicant, reporting whether it got out.
     *
     * No User row exists yet, so this is an on-demand mail notification. Failure is
     * swallowed deliberately: the employee, the invitation and the audit entry are
     * already committed, and throwing here would surface as a 500 on a completed
     * approval — leaving the reviewer with neither confirmation nor the link. The
     * caller falls back to handing the link over manually instead.
     */
    private function mailActivationLink(string $email, string $applicantName, string $activationUrl): bool
    {
        try {
            Notification::route('mail', $email)
                ->notify(SystemNotification::registrationRequestApproved($applicantName, $activationUrl));

            return true;
        } catch (Throwable $e) {
            Log::warning('Activation link could not be emailed.', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
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
