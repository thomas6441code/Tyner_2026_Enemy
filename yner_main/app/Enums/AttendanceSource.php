<?php

namespace App\Enums;

/**
 * Which channel produced a `raw_attendance_logs` row.
 *
 * The two channels are independent at the edge and converge here. AttendanceCalculator does
 * not branch on this value — it resolves the employee either way — so this is for filtering,
 * reporting and audit, not for control flow in the attendance engine.
 */
enum AttendanceSource: string
{
    case Device = 'device';
    case Mobile = 'mobile';

    public function label(): string
    {
        return match ($this) {
            self::Device => 'Biometric Device',
            self::Mobile => 'Mobile Check-In',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
