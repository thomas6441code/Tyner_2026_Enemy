<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceRecord extends Model
{
    protected $fillable = [
        'employee_id',
        'work_date',
        'status',
        'first_in',
        'last_out',
        'worked_minutes',
        'late_minutes',
        'early_leave_minutes',
        'is_manual',
        'permission_request_id',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date:Y-m-d',
            'status' => AttendanceStatus::class,
            'first_in' => 'datetime',
            'last_out' => 'datetime',
            'worked_minutes' => 'integer',
            'late_minutes' => 'integer',
            'early_leave_minutes' => 'integer',
            'is_manual' => 'boolean',
            'permission_request_id' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function permissionRequest(): BelongsTo
    {
        return $this->belongsTo(PermissionRequest::class);
    }
}
