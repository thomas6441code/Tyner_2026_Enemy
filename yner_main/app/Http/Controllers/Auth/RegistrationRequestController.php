<?php

namespace App\Http\Controllers\Auth;

use App\Enums\RegistrationStatus;
use App\Enums\RoleName;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\RegistrationRequest;
use App\Models\User;
use App\Notifications\SystemNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public entry point for joining EAPMS.
 *
 * This replaces Breeze's RegisteredUserController, which created an authenticated `users`
 * row from an anonymous POST with no role and no Employee link. Nothing here creates an
 * account: a request is filed, an admin reviews it, and only an approved applicant
 * receives a single-use activation link (see AccountActivationController).
 */
class RegistrationRequestController extends Controller
{
    /**
     * Display the registration request form.
     */
    public function create(): Response
    {
        return Inertia::render('auth/registration-request', [
            'departments' => Department::orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Record a registration request for admin review.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        // Absorb duplicates silently. Telling an anonymous caller "that email already has an
        // account/request" turns this form into an account enumeration oracle, so both paths
        // return the identical redirect below.
        if ($this->isDuplicate($validated['email'])) {
            return redirect()->route('registration-request.submitted');
        }

        $registrationRequest = new RegistrationRequest($validated);
        // Server-controlled fields are assigned explicitly — they are not in $fillable
        // precisely because this payload is anonymous and untrusted.
        $registrationRequest->email = $validated['email'];
        $registrationRequest->status = RegistrationStatus::Pending;
        $registrationRequest->ip_address = $request->ip();
        $registrationRequest->save();

        $this->notifyManagement($registrationRequest);

        return redirect()->route('registration-request.submitted');
    }

    /**
     * Confirmation screen shown after a request is filed.
     */
    public function submitted(): Response
    {
        return Inertia::render('auth/registration-submitted');
    }

    /**
     * Whether this email already has an account or an open request.
     */
    private function isDuplicate(string $email): bool
    {
        return User::where('email', $email)->exists()
            || RegistrationRequest::where('email', $email)
                ->where('status', RegistrationStatus::Pending)
                ->exists();
    }

    private function notifyManagement(RegistrationRequest $registrationRequest): void
    {
        // whereHas rather than Spatie's role() scope: role() throws if the role rows are
        // missing, and this runs on a public unauthenticated endpoint where a 500 would be
        // a far worse outcome than a skipped notification.
        $recipients = User::whereHas('roles', fn ($q) => $q->whereIn('name', [
            RoleName::Admin->value,
            RoleName::HrOfficer->value,
        ]))->get();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, SystemNotification::registrationRequestSubmitted(
            $registrationRequest->fullName(),
            $registrationRequest->id,
            route('registration-requests.index'),
        ));
    }
}
