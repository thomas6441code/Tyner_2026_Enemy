<?php

namespace App\Http\Controllers;

use App\Enums\PermissionStatus;
use App\Enums\PermissionType;
use App\Enums\RoleName;
use App\Events\PermissionRequestApproved;
use App\Models\AuditLog;
use App\Models\PermissionRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PermissionRequestController extends Controller
{
    /**
     * List permission requests. Admin/HR see everything; an Employee sees only their own.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', PermissionRequest::class);

        $user = $request->user();
        $isManager = $user->hasAnyRole([RoleName::Admin->value, RoleName::HrOfficer->value]);

        $query = PermissionRequest::with(['employee', 'reviewer'])
            ->orderByRaw("status = 'pending' desc")
            ->latest();

        if (! $isManager) {
            $query->whereHas('employee', fn ($q) => $q->where('user_id', $user->id));
        }

        $requests = $query->paginate(15)->through(fn (PermissionRequest $req) => [
            'id' => $req->id,
            'employee' => $req->employee?->fullName(),
            'type' => $req->type->value,
            'type_label' => $req->type->label(),
            'start_date' => $req->start_date->toDateString(),
            'end_date' => $req->end_date->toDateString(),
            'reason' => $req->reason,
            'status' => $req->status->value,
            'status_label' => $req->status->label(),
            'reviewer' => $req->reviewer?->name,
            'review_note' => $req->review_note,
            'reviewed_at' => $req->reviewed_at?->toDateTimeString(),
            'has_attachment' => $req->attachment_path !== null,
            'can_review' => $user->can('review', $req),
            'can_edit' => $user->can('update', $req),
            'can_cancel' => $user->can('delete', $req),
        ]);

        return Inertia::render('permission-requests/index', [
            'requests' => $requests,
            'stats' => [
                'pending' => PermissionRequest::where('status', PermissionStatus::Pending)->count(),
                'approved' => PermissionRequest::where('status', PermissionStatus::Approved)->count(),
                'rejected' => PermissionRequest::where('status', PermissionStatus::Rejected)->count(),
            ],
            'types' => collect(PermissionType::cases())
                ->map(fn (PermissionType $t) => ['value' => $t->value, 'label' => $t->label()])
                ->all(),
            'statuses' => collect(PermissionStatus::cases())
                ->map(fn (PermissionStatus $s) => ['value' => $s->value, 'label' => $s->label()])
                ->all(),
            'actions' => [
                'create' => $user->can('create', PermissionRequest::class),
            ],
            'status' => session('status'),
        ]);
    }

    /**
     * Employee submits a new permission/leave request.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', PermissionRequest::class);

        $validated = $this->validateRequest($request);

        $employee = $request->user()->employee;

        $permissionRequest = PermissionRequest::create([
            'employee_id' => $employee->id,
            'type' => $validated['type'],
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'reason' => $validated['reason'],
            'attachment_path' => $this->storeAttachment($request),
            'status' => PermissionStatus::Pending,
        ]);

        $this->audit($request, $permissionRequest, 'permission.submitted', null, $validated['reason']);

        return redirect()->route('permission-requests.index')->with('status', 'Permission request submitted.');
    }

    /**
     * Owner edits a still-pending request.
     */
    public function update(Request $request, PermissionRequest $permissionRequest): RedirectResponse
    {
        $this->authorize('update', $permissionRequest);

        $validated = $this->validateRequest($request);

        $attachment = $this->storeAttachment($request);
        if ($attachment !== null && $permissionRequest->attachment_path) {
            Storage::delete($permissionRequest->attachment_path);
        }

        $permissionRequest->update([
            'type' => $validated['type'],
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'reason' => $validated['reason'],
            'attachment_path' => $attachment ?? $permissionRequest->attachment_path,
        ]);

        $this->audit($request, $permissionRequest, 'permission.updated', null, $validated['reason']);

        return redirect()->route('permission-requests.index')->with('status', 'Permission request updated.');
    }

    /**
     * Owner cancels a still-pending request (soft cancel — status → Cancelled, keeps history).
     */
    public function destroy(Request $request, PermissionRequest $permissionRequest): RedirectResponse
    {
        $this->authorize('delete', $permissionRequest);

        $old = ['status' => $permissionRequest->status->value];
        $permissionRequest->update(['status' => PermissionStatus::Cancelled]);

        $this->audit($request, $permissionRequest, 'permission.cancelled', $old, null);

        return redirect()->route('permission-requests.index')->with('status', 'Permission request cancelled.');
    }

    /**
     * HR/Admin approves or rejects a pending request.
     */
    public function review(Request $request, PermissionRequest $permissionRequest): RedirectResponse
    {
        $this->authorize('review', $permissionRequest);

        $validated = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'review_note' => ['nullable', 'string', 'max:1000', 'required_if:decision,rejected'],
        ]);

        $old = ['status' => $permissionRequest->status->value];
        $status = $validated['decision'] === 'approved' ? PermissionStatus::Approved : PermissionStatus::Rejected;

        $permissionRequest->update([
            'status' => $status,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_note' => $validated['review_note'] ?? null,
        ]);

        $this->audit($request, $permissionRequest, 'permission.reviewed', $old, $validated['review_note'] ?? null);

        if ($status === PermissionStatus::Approved) {
            event(new PermissionRequestApproved($permissionRequest));
        }

        return redirect()->route('permission-requests.index')
            ->with('status', "Permission request {$status->value}.");
    }

    /**
     * Stream a request's stored attachment to anyone allowed to view the request.
     */
    public function attachment(PermissionRequest $permissionRequest): StreamedResponse
    {
        $this->authorize('view', $permissionRequest);

        abort_if($permissionRequest->attachment_path === null, 404);

        return Storage::download($permissionRequest->attachment_path);
    }

    /**
     * Shared validation rules for store/update.
     *
     * @return array<string, mixed>
     */
    private function validateRequest(Request $request): array
    {
        return $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', PermissionType::values())],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['required', 'string', 'max:1000'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:4096'],
        ]);
    }

    /**
     * Persist the uploaded attachment (if any) and return its storage path.
     */
    private function storeAttachment(Request $request): ?string
    {
        if (! $request->hasFile('attachment')) {
            return null;
        }

        return $request->file('attachment')->store('permission-attachments');
    }

    /**
     * Write an audit-trail entry for a request state change.
     *
     * @param  array<string, mixed>|null  $old
     */
    private function audit(Request $request, PermissionRequest $permissionRequest, string $action, ?array $old, ?string $note): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'auditable_type' => PermissionRequest::class,
            'auditable_id' => $permissionRequest->id,
            'action' => $action,
            'old_values' => $old,
            'new_values' => [
                'type' => $permissionRequest->type->value,
                'status' => $permissionRequest->status->value,
                'start_date' => $permissionRequest->start_date->toDateString(),
                'end_date' => $permissionRequest->end_date->toDateString(),
            ],
            'note' => $note,
        ]);
    }
}
