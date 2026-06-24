<?php

namespace App\Enums;

enum PermissionType: string
{
    case OfficialLeave = 'official_leave';
    case SickLeave = 'sick_leave';
    case Permission = 'permission';
    case FieldDuty = 'field_duty';

    /**
     * All backing values, for validation `in:` rules and seeders.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type) => $type->value, self::cases());
    }

    /**
     * Human-readable label for UI display.
     */
    public function label(): string
    {
        return match ($this) {
            self::OfficialLeave => 'Official Leave',
            self::SickLeave => 'Sick Leave',
            self::Permission => 'Permission',
            self::FieldDuty => 'Field Duty',
        };
    }

    /**
     * The attendance status the Phase 6 sync engine writes when a request of this type
     * is approved. Keeping the mapping here means the sync engine needs no extra logic.
     */
    public function toAttendanceStatus(): AttendanceStatus
    {
        return match ($this) {
            self::OfficialLeave => AttendanceStatus::OfficialLeave,
            self::SickLeave => AttendanceStatus::SickLeave,
            self::Permission => AttendanceStatus::PermissionApproved,
            self::FieldDuty => AttendanceStatus::FieldDuty,
        };
    }
}
