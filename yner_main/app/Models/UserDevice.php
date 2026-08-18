<?php

namespace App\Models;

use App\Enums\DeviceStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A WebAuthn platform authenticator (a phone's fingerprint/face sensor) registered to a user.
 *
 * `public_key` holds the whole serialized credential record; the scalar columns beside it are
 * denormalised copies for querying and admin display and are never the source of truth for
 * verification.
 */
class UserDevice extends Model
{
    protected $fillable = [
        'user_id',
        'credential_id',
        'public_key',
        'sign_count',
        'aaguid',
        'transports',
        'attestation_type',
        'rp_id',
        'device_name',
        'platform',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'transports' => 'array',
            'sign_count' => 'integer',
            'status' => DeviceStatus::class,
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Usable credentials only: active, and bound to the RP ID currently in force.
     *
     * A credential created under a different RP ID can never produce a valid signature for
     * this one, so it is excluded here rather than being allowed to fail cryptically later.
     */
    public function scopeUsable(Builder $query, string $rpId): Builder
    {
        return $query->where('status', DeviceStatus::Active)->where('rp_id', $rpId);
    }

    public function isActive(): bool
    {
        return $this->status === DeviceStatus::Active;
    }

    /**
     * Soft revocation. The row is kept forever — it is evidence about which authenticator
     * could have produced which check-in.
     */
    public function revoke(string $reason): void
    {
        $this->status = DeviceStatus::Revoked;
        $this->revoked_at = now();
        $this->revoked_reason = $reason;
        $this->save();
    }
}
