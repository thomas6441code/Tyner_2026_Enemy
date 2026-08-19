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

    /**
     * Mirrors the column default so the `saving` hook below sees `active` on a brand-new row.
     *
     * Without this the model's status would be null until the database applied its own
     * default, and the hook would null `active_user_id` on a device that is in fact active —
     * quietly leaving the user's binding slot empty.
     */
    protected $attributes = [
        'status' => DeviceStatus::Active->value,
    ];

    protected function casts(): array
    {
        return [
            'transports' => 'array',
            'sign_count' => 'integer',
            'status' => DeviceStatus::class,
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
            'device_token_issued_at' => 'datetime',
        ];
    }

    /**
     * Keep `active_user_id` in lockstep with `status`, everywhere, always.
     *
     * `active_user_id` carries a unique index and is the database's expression of "at most one
     * active device per user". Maintaining it in a model hook rather than at each call site is
     * the whole point: a future path that flips `status` without knowing about this column
     * still cannot corrupt the invariant, and cannot accidentally leave a revoked row holding
     * the slot its owner now needs for a replacement.
     */
    protected static function booted(): void
    {
        static::saving(function (self $device) {
            $device->active_user_id = $device->status === DeviceStatus::Active
                ? $device->user_id
                : null;
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The one device currently linked to a user, if any.
     *
     * Reads through `active_user_id` rather than `where user_id = ? and status = active`
     * precisely because that column is the uniquely-indexed one — the query cannot return two
     * rows even if something upstream is wrong.
     */
    public static function boundTo(int $userId): ?self
    {
        return static::query()->where('active_user_id', $userId)->first();
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
