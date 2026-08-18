<?php

namespace App\Enums;

/**
 * The outcome of one mobile check-in attempt. Rejected attempts are persisted, not discarded —
 * they are the audit trail that makes a spoofable geolocation channel accountable.
 */
enum CheckInResult: string
{
    case Accepted = 'accepted';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Accepted => 'Accepted',
            self::Rejected => 'Rejected',
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
