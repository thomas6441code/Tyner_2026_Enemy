<?php

namespace App\Enums;

/**
 * Which way an employee is punching on the mobile channel.
 *
 * AttendanceCalculator::deriveAttributes() is direction-agnostic — it takes first() and last()
 * of the day's sorted punches — so this never reaches the attendance engine. It exists for the
 * check-in UI, the ordering rules (you cannot check out before checking in), and the audit log.
 */
enum CheckInDirection: string
{
    case In = 'in';
    case Out = 'out';

    public function label(): string
    {
        return match ($this) {
            self::In => 'Check In',
            self::Out => 'Check Out',
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
