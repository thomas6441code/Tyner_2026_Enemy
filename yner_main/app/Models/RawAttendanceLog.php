<?php

namespace App\Models;

use App\Enums\AttendanceSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable punch event, from either attendance channel.
 *
 * Biometric rows arrive from bio-service and resolve to an employee through
 * device_enrollments. Mobile rows arrive from the WebAuthn check-in flow, already know their
 * employee, and carry sentinel values in the device columns so that the existing
 * `raw_attendance_logs_dedupe_unique` index keeps working for them verbatim.
 */
class RawAttendanceLog extends Model
{
    /**
     * The `device_serial` written by every mobile check-in.
     *
     * Three things follow from using a sentinel instead of nulling the device columns:
     *
     *   1. The dedupe unique index (device_serial, device_user_id, punched_at) keeps applying
     *      to mobile rows. NULLs compare as distinct in a MySQL unique index, so nullable
     *      columns would silently disable dedupe for the entire mobile channel.
     *   2. The enrollment lookup "{serial}|{device_user_id}" can never match a mobile row —
     *      no real device has this serial — so the two channels are provably independent.
     *   3. Mobile rows stay out of the admin per-device log view, which queries by serial.
     *
     * Guarded against collision by a Rule::notIn on the biometric device serial field.
     */
    public const MOBILE_SERIAL = 'MOBILE-WEBAUTHN';

    protected $fillable = [
        'device_user_id',
        'punched_at',
        'device_serial',
        'employee_id',
        'source',
        'raw_payload',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'punched_at' => 'datetime',
            'raw_payload' => 'array',
            'processed_at' => 'datetime',
            'source' => AttendanceSource::class,
        ];
    }

    /**
     * Set on mobile rows only; device rows resolve through DeviceEnrollment instead.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function scopeMobile(Builder $query): Builder
    {
        return $query->where('source', AttendanceSource::Mobile);
    }

    public function scopeDevice(Builder $query): Builder
    {
        return $query->where('source', AttendanceSource::Device);
    }
}
