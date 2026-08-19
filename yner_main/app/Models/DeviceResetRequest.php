<?php

namespace App\Models;

use App\Enums\DeviceResetStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An employee asking to link a different phone to their account.
 *
 * Approval is not a state so much as a key: it revokes the outgoing device and opens a short,
 * single-use window in which one new device may be registered. Everything that decides whether
 * that window is still open lives in `isUsable()`, so the policy, the controller and the tests
 * all agree by construction.
 */
class DeviceResetRequest extends Model
{
    /**
     * Only the employee-supplied field is mass assignable. `user_id`, `employee_id`,
     * `user_device_id`, `status`, the review columns and `approved_until` are all
     * server-controlled — `approved_until` in particular is the entire authorisation, and a
     * request body must never be able to reach it.
     */
    protected $fillable = [
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => DeviceResetStatus::class,
            'reviewed_at' => 'datetime',
            'approved_until' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    protected $attributes = [
        'status' => DeviceResetStatus::Pending->value,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * The device this request replaces. Kept even after that device is revoked — the pairing
     * of "which phone went out" with "which approval let the next one in" is the audit trail.
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(UserDevice::class, 'user_device_id');
    }

    public function isPending(): bool
    {
        return $this->status === DeviceResetStatus::Pending;
    }

    /**
     * Whether this approval still entitles the user to register a device.
     *
     * All three conditions matter: approved (not pending or rejected), not already spent, and
     * not expired. Dropping any one of them turns a one-time reset into a standing licence.
     */
    public function isUsable(): bool
    {
        return $this->status === DeviceResetStatus::Approved
            && $this->used_at === null
            && $this->approved_until !== null
            && $this->approved_until->isFuture();
    }

    /**
     * The approved, unexpired, unspent reset for a user — the single thing UserDevicePolicy
     * consults when the user already had a device.
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->where('status', DeviceResetStatus::Approved)
            ->whereNull('used_at')
            ->where('approved_until', '>', now());
    }
}
