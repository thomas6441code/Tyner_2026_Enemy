<?php

namespace App\Http\Controllers\Auth;

use App\Enums\RoleName;
use App\Http\Controllers\Controller;
use App\Models\AccountInvitation;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\SystemNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Redeems a single-use invitation into a real EAPMS account.
 *
 * The identity was fixed at approval time and is rendered read-only — the activation step
 * only ever sets a password. This is what prevents an approved invitation from being used
 * to create an account under a different name.
 */
class AccountActivationController extends Controller
{
    /**
     * Show the activation form for a valid, unredeemed invitation.
     */
    public function create(string $uuid, string $token): Response
    {
        $invitation = $this->resolve($uuid, $token);

        if (! $invitation instanceof AccountInvitation) {
            return Inertia::render('auth/activation-invalid', ['reason' => $invitation]);
        }

        $employee = $invitation->employee;

        return Inertia::render('auth/activate', [
            'uuid' => $uuid,
            'token' => $token,
            // Read-only: these came from the approved registration request.
            'identity' => [
                'name' => $employee->fullName(),
                'email' => $invitation->email,
                'employee_code' => $employee->employee_code,
                'department' => $employee->department?->name,
            ],
            'expiresAt' => $invitation->expires_at->toDateTimeString(),
        ]);
    }

    /**
     * Create the account and consume the invitation.
     */
    public function store(Request $request, string $uuid, string $token): RedirectResponse
    {
        // Re-verify from scratch. The GET that rendered the form proves nothing about this
        // request — the invitation may have been redeemed, revoked, or expired since.
        $invitation = $this->resolve($uuid, $token);

        if (! $invitation instanceof AccountInvitation) {
            return redirect()->route('account.activate', ['uuid' => $uuid, 'token' => $token]);
        }

        $validated = $request->validate([
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = DB::transaction(function () use ($invitation, $validated) {
            // Re-read under a row lock and re-check inside it. Without this, two concurrent
            // submissions both pass the isUsable() check above and both create a user —
            // a TOCTOU race that is exactly what "single-use" must rule out.
            $locked = AccountInvitation::whereKey($invitation->id)->lockForUpdate()->first();

            if ($locked === null || ! $locked->isUsable()) {
                return null;
            }

            $employee = $locked->employee;

            $user = User::create([
                'name' => $employee->fullName(),
                'email' => $locked->email,
                'password' => Hash::make($validated['password']),
            ]);

            // Redeeming a token sent to this address is itself proof of control of it.
            // forceFill because email_verified_at is not in User::$fillable.
            $user->forceFill(['email_verified_at' => now()])->save();

            $user->assignRole(RoleName::Employee->value);

            $employee->update([
                'user_id' => $user->id,
                'status' => 'active',
            ]);

            $locked->update(['used_at' => now()]);

            AuditLog::create([
                'user_id' => $user->id,
                'auditable_type' => AccountInvitation::class,
                'auditable_id' => $locked->id,
                'action' => 'account.activated',
                'new_values' => [
                    'user_id' => $user->id,
                    'employee_id' => $employee->id,
                ],
            ]);

            return $user;
        });

        if ($user === null) {
            return redirect()->route('account.activate', ['uuid' => $uuid, 'token' => $token]);
        }

        $this->notifyManagement($user);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    /**
     * Resolve an invitation from its public selector and secret.
     *
     * Returns the invitation, or a reason string for the invalid page. A missing uuid and a
     * wrong token both return 'invalid' so the two are indistinguishable to a caller
     * probing for valid selectors.
     */
    private function resolve(string $uuid, string $token): AccountInvitation|string
    {
        $invitation = AccountInvitation::with('employee.department')
            ->where('uuid', $uuid)
            ->first();

        if ($invitation === null || ! $invitation->matches($token)) {
            return 'invalid';
        }

        if ($invitation->used_at !== null) {
            // A redeemed link being opened again is worth recording — it is either a
            // confused user or someone who obtained a link they should not have.
            AuditLog::create([
                'user_id' => null,
                'auditable_type' => AccountInvitation::class,
                'auditable_id' => $invitation->id,
                'action' => 'invitation.reuse_attempt',
                'new_values' => ['ip' => request()->ip()],
            ]);

            return 'used';
        }

        if ($invitation->revoked_at !== null) {
            return 'revoked';
        }

        if ($invitation->expires_at->isPast()) {
            return 'expired';
        }

        return $invitation;
    }

    private function notifyManagement(User $user): void
    {
        $recipients = User::whereHas('roles', fn ($q) => $q->whereIn('name', [
            RoleName::Admin->value,
            RoleName::HrOfficer->value,
        ]))->get();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, SystemNotification::accountActivated(
            $user->name,
            route('employees.index'),
        ));
    }
}
