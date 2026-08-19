<?php

namespace App\Http\Controllers;

use App\Enums\DeviceResetStatus;
use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\DeviceResetRequest;
use App\Models\User;
use App\Models\UserDevice;
use App\Notifications\SystemNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The sanctioned path from one linked device to the next.
 *
 * An account is bound to exactly one device and the employee cannot unbind it themselves —
 * otherwise the binding would be a formality, undone and redone at will whenever someone wanted
 * a colleague to punch for them. Here they explain why they need a different phone, an Admin or
 * HR Officer decides, and the decision is permanent record.
 *
 * Approval does two things atomically: it revokes the outgoing device (freeing the unique
 * `active_user_id` slot) and opens a short window in which exactly one replacement may be
 * registered. It does not itself register anything.
 */
class DeviceResetRequestController extends Controller
{
    /**
     * The Admin/HR review queue.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', DeviceResetRequest::class);

        $user = $request->user();

        $filters = $request->validate([
            'status' => ['nullable', 'string', 'in:'.implode(',', DeviceResetStatus::values())],
            'employee' => ['nullable', 'string', 'max:100'],
        ]);

        $requests = DeviceResetRequest::with(['user', 'employee', 'reviewer', 'device'])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['employee'] ?? null, fn ($q, $term) => $q->whereHas(
                'employee',
                fn ($e) => $e->where('first_name', 'like', "%{$term}%")
                    ->orWhere('last_name', 'like', "%{$term}%")
                    ->orWhere('employee_code', 'like', "%{$term}%"),
            ))
            ->orderByRaw("status = 'pending' desc")
            ->latest()
            ->paginate(15)
            ->withQueryString()
            ->through(fn (DeviceResetRequest $req) => [
                'id' => $req->id,
                'employee' => $req->employee?->fullName() ?? $req->user?->name,
                'employee_code' => $req->employee?->employee_code,
                'current_device' => $req->device?->device_name,
                'reason' => $req->reason,
                'status' => $req->status->value,
                'status_label' => $req->status->label(),
                'reviewer' => $req->reviewer?->name,
                'review_note' => $req->review_note,
                'reviewed_at' => $req->reviewed_at?->toDateTimeString(),
                'approved_until' => $req->approved_until?->toDateTimeString(),
                'used_at' => $req->used_at?->toDateTimeString(),
                // Approved but neither spent nor expired: the window is genuinely open right
                // now, which is a different thing from "was approved at some point".
                'is_usable' => $req->isUsable(),
                'submitted_at' => $req->created_at?->toDateTimeString(),
                'can_review' => $user->can('review', $req),
            ]);

        return Inertia::render('device-reset-requests/index', [
            'requests' => $requests,
            'filters' => $filters,
            'stats' => [
                'pending' => DeviceResetRequest::where('status', DeviceResetStatus::Pending)->count(),
                'approved' => DeviceResetRequest::where('status', DeviceResetStatus::Approved)->count(),
                'rejected' => DeviceResetRequest::where('status', DeviceResetStatus::Rejected)->count(),
            ],
            'status' => session('status'),
        ]);
    }

    /**
     * An employee asks to link a different phone.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', DeviceResetRequest::class);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $user = $request->user();

        // One open request at a time. Without this, a queue of pending requests would let an
        // employee bank approvals and re-link repeatedly without anyone noticing the pattern.
        if (DeviceResetRequest::where('user_id', $user->id)->where('status', DeviceResetStatus::Pending)->exists()) {
            return back()->with('status', 'You already have a device reset request awaiting review.');
        }

        $reset = new DeviceResetRequest($validated);
        $reset->user_id = $user->id;
        $reset->employee_id = $user->employee?->id;
        $reset->user_device_id = UserDevice::boundTo($user->id)?->id;
        $reset->ip_address = $request->ip();
        $reset->user_agent = $request->userAgent() ? mb_substr($request->userAgent(), 0, 250) : null;
        $reset->save();

        AuditLog::create([
            'user_id' => $user->id,
            'auditable_type' => DeviceResetRequest::class,
            'auditable_id' => $reset->id,
            'action' => 'device_reset.requested',
            'new_values' => [
                'user_device_id' => $reset->user_device_id,
                'ip' => $request->ip(),
            ],
            'note' => $reset->reason,
        ]);

        $this->notifyReviewers(SystemNotification::deviceResetRequested(
            $user->employee?->fullName() ?? $user->name,
            $reset->id,
            route('device-reset-requests.index'),
        ));

        return back()->with('status', 'Device reset requested. An administrator will review it.');
    }

    /**
     * Approve: revoke the outgoing device and open the registration window.
     *
     * Both halves in one transaction. An approval that opened the window without revoking would
     * momentarily allow two active devices — which the unique index would then refuse, leaving
     * the employee approved and still unable to register.
     */
    public function approve(Request $request, DeviceResetRequest $deviceResetRequest): RedirectResponse
    {
        $this->authorize('review', $deviceResetRequest);

        $validated = $request->validate([
            'review_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $deadline = now()->addHours((int) config('device.reset_window_hours'));

        DB::transaction(function () use ($request, $deviceResetRequest, $validated, $deadline) {
            $current = UserDevice::boundTo($deviceResetRequest->user_id);

            if ($current !== null) {
                $current->revoke('Replaced via approved device reset request #'.$deviceResetRequest->id.'.');

                // Record which device actually went out, which may differ from the one recorded
                // at request time if the binding moved in between.
                $deviceResetRequest->user_device_id = $current->id;
            }

            $deviceResetRequest->status = DeviceResetStatus::Approved;
            $deviceResetRequest->reviewed_by = $request->user()->id;
            $deviceResetRequest->reviewed_at = now();
            $deviceResetRequest->review_note = $validated['review_note'] ?? null;
            $deviceResetRequest->approved_until = $deadline;
            $deviceResetRequest->save();
        });

        $this->audit($request, $deviceResetRequest, 'device_reset.approved', $validated['review_note'] ?? null);

        $deviceResetRequest->user?->notify(SystemNotification::deviceResetApproved(
            $deviceResetRequest->employee?->fullName() ?? $deviceResetRequest->user->name,
            $deadline->toDayDateTimeString(),
            route('devices.index'),
        ));

        return redirect()->route('device-reset-requests.index')->with(
            'status',
            'Device reset approved. The employee may register a new device until '.$deadline->toDayDateTimeString().'.',
        );
    }

    /**
     * Reject with a mandatory reason. The existing device stays linked and usable.
     */
    public function reject(Request $request, DeviceResetRequest $deviceResetRequest): RedirectResponse
    {
        $this->authorize('review', $deviceResetRequest);

        $validated = $request->validate([
            'review_note' => ['required', 'string', 'max:1000'],
        ]);

        $deviceResetRequest->status = DeviceResetStatus::Rejected;
        $deviceResetRequest->reviewed_by = $request->user()->id;
        $deviceResetRequest->reviewed_at = now();
        $deviceResetRequest->review_note = $validated['review_note'];
        $deviceResetRequest->save();

        $this->audit($request, $deviceResetRequest, 'device_reset.rejected', $validated['review_note']);

        $deviceResetRequest->user?->notify(SystemNotification::deviceResetRejected(
            $deviceResetRequest->employee?->fullName() ?? $deviceResetRequest->user->name,
            $validated['review_note'],
            route('devices.index'),
        ));

        return redirect()->route('device-reset-requests.index')->with('status', 'Device reset request rejected.');
    }

    private function notifyReviewers(SystemNotification $notification): void
    {
        $reviewers = User::role([RoleName::Admin->value, RoleName::HrOfficer->value])->get();

        if ($reviewers->isNotEmpty()) {
            Notification::send($reviewers, $notification);
        }
    }

    private function audit(Request $request, DeviceResetRequest $reset, string $action, ?string $note): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'auditable_type' => DeviceResetRequest::class,
            'auditable_id' => $reset->id,
            'action' => $action,
            'old_values' => ['status' => DeviceResetStatus::Pending->value],
            'new_values' => [
                'status' => $reset->status->value,
                'user_device_id' => $reset->user_device_id,
                'approved_until' => $reset->approved_until?->toDateTimeString(),
                'ip' => $request->ip(),
            ],
            'note' => $note,
        ]);
    }
}
