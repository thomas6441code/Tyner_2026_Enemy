<?php

namespace App\Enums;

enum AttendanceStatus: string
{
    case Present = 'present';
    case Late = 'late';
    case Absent = 'absent';
    case OfficialLeave = 'official_leave';
    case SickLeave = 'sick_leave';
    case PermissionApproved = 'permission_approved';
    case FieldDuty = 'field_duty';

    /**
     * All backing values, for validation `in:` rules and seeders.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }

    /**
     * Human-readable label for UI display.
     */
    public function label(): string
    {
        return match ($this) {
            self::Present => 'Present',
            self::Late => 'Late',
            self::Absent => 'Absent',
            self::OfficialLeave => 'Official Leave',
            self::SickLeave => 'Sick Leave',
            self::PermissionApproved => 'Permission Approved',
            self::FieldDuty => 'Field Duty',
        };
    }

    /**
     * Whether this status represents an approved absence (leave/permission/field duty).
     *
     * Phase 4 only ever writes Present/Late/Absent; the leave statuses are set by the
     * Phase 6 sync engine when HR approves a permission. KPI counts and the sync engine
     * use this to distinguish "on leave" from a real absence.
     */
    public function isLeave(): bool
    {
        return match ($this) {
            self::OfficialLeave, self::SickLeave, self::PermissionApproved, self::FieldDuty => true,
            default => false,
        };
    }

    /**
     * Whether clock times (first in / last out / worked hours / late minutes) are
     * meaningful for this status.
     *
     * Only a physically-present day carries times. An absence and every approved-leave
     * status describe a day the employee was not on the clock, so reports must blank
     * those columns rather than print stale or zeroed punch data next to them — a
     * "Sick Leave" row showing 08:50–16:00 reads as if the employee actually worked.
     */
    public function showsTimes(): bool
    {
        return match ($this) {
            self::Present, self::Late => true,
            default => false,
        };
    }
}
