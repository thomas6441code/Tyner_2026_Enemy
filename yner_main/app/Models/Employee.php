<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Employee extends Model
{
    protected $fillable = [
        'user_id',
        'department_id',
        'work_schedule_id',
        'work_location_id',
        'employee_code',
        'first_name',
        'last_name',
        'phone',
        'hire_date',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'hire_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function workSchedule(): BelongsTo
    {
        return $this->belongsTo(WorkSchedule::class);
    }

    /**
     * The employee's own geofence site, if one is set. May be null — GeofenceService then
     * falls back to the department's location before refusing.
     */
    public function workLocation(): BelongsTo
    {
        return $this->belongsTo(WorkLocation::class);
    }

    public function fullName(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    public function deviceEnrollments(): HasMany
    {
        return $this->hasMany(DeviceEnrollment::class);
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function permissionRequests(): HasMany
    {
        return $this->hasMany(PermissionRequest::class);
    }

    public function registrationRequest(): HasOne
    {
        return $this->hasOne(RegistrationRequest::class);
    }

    public function accountInvitations(): HasMany
    {
        return $this->hasMany(AccountInvitation::class);
    }
}
