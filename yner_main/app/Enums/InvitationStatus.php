<?php

namespace App\Enums;

/**
 * Lifecycle state of an account invitation.
 *
 * Deliberately NOT a database column. Expiry is a function of `expires_at` and the
 * current time, so a stored status would be a lie-by-staleness the moment a row sat
 * past its expiry without a sweeper job running. `AccountInvitation::status()` derives
 * this from `used_at` / `revoked_at` / `expires_at` on every read instead.
 */
enum InvitationStatus: string
{
    case Pending = 'pending';
    case Used = 'used';
    case Expired = 'expired';
    case Revoked = 'revoked';

    /**
     * All backing values, for validation `in:` rules and filters.
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
            self::Pending => 'Pending',
            self::Used => 'Used',
            self::Expired => 'Expired',
            self::Revoked => 'Revoked',
        };
    }
}
