<?php

namespace App\Models;

use App\Services\EmployeeCodeGenerator;
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

    /**
     * Fill in a sequential employee code whenever one was not supplied.
     *
     * In a hook rather than at each call site so that EVERY creation path is covered — the two
     * controllers, the seeders, the factories, and whatever gets written next. A rule that has
     * to be remembered is a rule that will eventually be forgotten, and forgetting this one
     * means a NOT NULL violation at best and a hand-typed duplicate at worst.
     *
     * A code that IS supplied is honoured: seeders and tests pin codes deliberately so demo
     * data and fixtures stay stable across re-seeds. The user-facing paths never supply one —
     * see EmployeeCodeGenerator::create(), which strips it.
     */
    protected static function booted(): void
    {
        static::creating(function (self $employee) {
            if (blank($employee->employee_code)) {
                $employee->employee_code = app(EmployeeCodeGenerator::class)->next();
            }
        });

        // Advance the high-water mark once the row is real. This runs for pinned codes too:
        // a seeder inserting EMP-0024 must push the mark to 24, or the next generated code
        // would collide with it. And because the mark outlives the row, deleting an employee
        // never frees their code for reuse — see EmployeeCodeGenerator.
        static::created(function (self $employee) {
            app(EmployeeCodeGenerator::class)->remember($employee->employee_code);
        });
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
