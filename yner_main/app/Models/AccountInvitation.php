<?php

namespace App\Models;

use App\Enums\InvitationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AccountInvitation extends Model
{
    protected $fillable = [
        'uuid',
        'registration_request_id',
        'employee_id',
        'email',
        'token',
        'expires_at',
        'used_at',
        'revoked_at',
        'created_by',
    ];

    /**
     * Never let the token hash reach a response.
     *
     * Controllers pass models straight into Inertia props; without this the bcrypt hash
     * would be serialized into the page payload on any screen that lists invitations.
     */
    protected $hidden = [
        'token',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function registrationRequest(): BelongsTo
    {
        return $this->belongsTo(RegistrationRequest::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Issue a fresh invitation for an employee.
     *
     * Returns the plaintext token alongside the model — this is the ONLY moment it exists
     * in readable form. Only its bcrypt hash is persisted, so a lost token can never be
     * recovered, only replaced via resend().
     *
     * `$email` is an explicit parameter rather than derived from the employee or request:
     * it is where the activation link is delivered, so silently resolving it to null would
     * mint an invitation nobody can receive.
     *
     * @return array{invitation: self, plainToken: string}
     */
    public static function issue(
        Employee $employee,
        string $email,
        ?RegistrationRequest $request = null,
        ?User $createdBy = null,
    ): array {
        // Str::random() draws from random_bytes(); 64 chars over a 62-char alphabet is ~381 bits.
        $plainToken = Str::random(64);

        $invitation = self::create([
            'uuid' => (string) Str::uuid(),
            'registration_request_id' => $request?->id,
            'employee_id' => $employee->id,
            'email' => $email,
            'token' => Hash::make($plainToken),
            'expires_at' => now()->addMinutes((int) config('auth.invitations.expire', 4320)),
            'created_by' => $createdBy?->id,
        ]);

        return ['invitation' => $invitation, 'plainToken' => $plainToken];
    }

    /**
     * Verify a plaintext token against the stored hash.
     *
     * bcrypt verification is constant-time, which is why lookup goes by the public `uuid`
     * and the secret is only ever compared here — never `where('token', $plain)`, which
     * would be a timing-observable index probe over a secret.
     */
    public function matches(string $plainToken): bool
    {
        return Hash::check($plainToken, $this->token);
    }

    /**
     * Whether this invitation can still be redeemed.
     */
    public function isUsable(): bool
    {
        return $this->used_at === null
            && $this->revoked_at === null
            && $this->expires_at->isFuture();
    }

    /**
     * Derived lifecycle state — see InvitationStatus for why this is not a column.
     */
    public function status(): InvitationStatus
    {
        return match (true) {
            $this->used_at !== null => InvitationStatus::Used,
            $this->revoked_at !== null => InvitationStatus::Revoked,
            $this->expires_at->isPast() => InvitationStatus::Expired,
            default => InvitationStatus::Pending,
        };
    }
}
