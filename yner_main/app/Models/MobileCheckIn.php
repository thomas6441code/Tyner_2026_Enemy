<?php

namespace App\Models;

use App\Enums\CheckInDirection;
use App\Enums\CheckInRejection;
use App\Enums\CheckInResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One mobile check-in or check-out attempt — accepted or rejected.
 *
 * This is the audit table, and the audit is the real control on this channel. A WebAuthn
 * assertion proves which device presented the request; nothing proves where that device was,
 * because the coordinates come from `navigator.geolocation` and are spoofable. So every
 * attempt is written here with its server-computed distance, IP and user agent, whether or not
 * it produced a punch.
 */
class MobileCheckIn extends Model
{
    protected $fillable = [
        'employee_id',
        'user_id',
        'raw_attendance_log_id',
        'user_device_id',
        'work_location_id',
        'work_date',
        'direction',
        'punched_at',
        'latitude',
        'longitude',
        'accuracy_meters',
        'distance_meters',
        'within_geofence',
        'webauthn_verified',
        'result',
        'rejection_reason',
        'flagged',
        'flag_reason',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'punched_at' => 'datetime',
            'direction' => CheckInDirection::class,
            'result' => CheckInResult::class,
            'rejection_reason' => CheckInRejection::class,
            // decimal(10,7) comes back as a string from MySQL and a float from SQLite; cast so
            // callers and JSON props see one type on both engines.
            'latitude' => 'float',
            'longitude' => 'float',
            'accuracy_meters' => 'integer',
            'distance_meters' => 'integer',
            'within_geofence' => 'boolean',
            'webauthn_verified' => 'boolean',
            'flagged' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rawAttendanceLog(): BelongsTo
    {
        return $this->belongsTo(RawAttendanceLog::class);
    }

    public function userDevice(): BelongsTo
    {
        return $this->belongsTo(UserDevice::class);
    }

    public function workLocation(): BelongsTo
    {
        return $this->belongsTo(WorkLocation::class);
    }

    public function isAccepted(): bool
    {
        return $this->result === CheckInResult::Accepted;
    }

    public function scopeAccepted(Builder $query): Builder
    {
        return $query->where('result', CheckInResult::Accepted);
    }
}
