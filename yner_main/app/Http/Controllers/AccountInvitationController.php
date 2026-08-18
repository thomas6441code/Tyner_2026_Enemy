<?php

namespace App\Http\Controllers;

use App\Enums\InvitationStatus;
use App\Models\AccountInvitation;
use App\Models\AuditLog;
use App\Notifications\SystemNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin/HR management of issued activation links.
 *
 * Invitations are never hard-deleted — an issued invitation is audit evidence, so
 * "revoke" is a state change and "resend" mints a replacement alongside it.
 */
class AccountInvitationController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', AccountInvitation::class);

        $user = $request->user();

        $invitations = AccountInvitation::with(['employee', 'creator'])
            ->latest()
            ->paginate(15)
            ->through(fn (AccountInvitation $invitation) => [
                'id' => $invitation->id,
                'employee' => $invitation->employee?->fullName(),
                'employee_code' => $invitation->employee?->employee_code,
                'email' => $invitation->email,
                'status' => $invitation->status()->value,
                'status_label' => $invitation->status()->label(),
                'expires_at' => $invitation->expires_at->toDateTimeString(),
                'used_at' => $invitation->used_at?->toDateTimeString(),
                'created_by' => $invitation->creator?->name,
                'created_at' => $invitation->created_at?->toDateTimeString(),
                'can_resend' => $user->can('resend', $invitation),
                'can_revoke' => $user->can('delete', $invitation) && $invitation->isUsable(),
            ]);

        $all = AccountInvitation::all();

        return Inertia::render('account-invitations/index', [
            'invitations' => $invitations,
            'stats' => [
                'pending' => $all->filter(fn ($i) => $i->status() === InvitationStatus::Pending)->count(),
                'used' => $all->filter(fn ($i) => $i->status() === InvitationStatus::Used)->count(),
                'expired' => $all->filter(fn ($i) => $i->status() === InvitationStatus::Expired)->count(),
                'revoked' => $all->filter(fn ($i) => $i->status() === InvitationStatus::Revoked)->count(),
            ],
            'status' => session('status'),
            'invitationUrl' => session('invitationUrl'),
        ]);
    }

    /**
     * Revoke the existing link and issue a fresh one for the same employee.
     */
    public function resend(Request $request, AccountInvitation $accountInvitation): RedirectResponse
    {
        $this->authorize('resend', $accountInvitation);

        $result = DB::transaction(function () use ($request, $accountInvitation) {
            $accountInvitation->update(['revoked_at' => now()]);

            return AccountInvitation::issue(
                $accountInvitation->employee,
                $accountInvitation->email,
                $accountInvitation->registrationRequest,
                $request->user(),
            );
        });

        $activationUrl = route('account.activate', [
            'uuid' => $result['invitation']->uuid,
            'token' => $result['plainToken'],
        ]);

        $this->audit($request, $result['invitation'], 'invitation.resent');

        Notification::route('mail', $accountInvitation->email)
            ->notify(SystemNotification::registrationRequestApproved(
                $accountInvitation->employee?->fullName() ?? 'Applicant',
                $activationUrl,
            ));

        return redirect()->route('account-invitations.index')
            ->with('status', 'A new activation link was issued.')
            ->with('invitationUrl', $activationUrl);
    }

    /**
     * Revoke an invitation without replacing it.
     */
    public function destroy(Request $request, AccountInvitation $accountInvitation): RedirectResponse
    {
        $this->authorize('delete', $accountInvitation);

        $accountInvitation->update(['revoked_at' => now()]);

        $this->audit($request, $accountInvitation, 'invitation.revoked');

        return redirect()->route('account-invitations.index')
            ->with('status', 'Invitation revoked.');
    }

    private function audit(Request $request, AccountInvitation $invitation, string $action): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'auditable_type' => AccountInvitation::class,
            'auditable_id' => $invitation->id,
            'action' => $action,
            'new_values' => [
                'employee_id' => $invitation->employee_id,
                'status' => $invitation->status()->value,
            ],
        ]);
    }
}
