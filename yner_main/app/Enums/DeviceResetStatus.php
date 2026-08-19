<?php

namespace App\Enums;

/**
 * Lifecycle of an employee's request to replace the device linked to their account.
 *
 * Mirrors RegistrationStatus deliberately — same three states, same review semantics — so the
 * review UI and controller read the same way as the registration-request flow beside it.
 *
 * `Approved` is not the end state it looks like: approval only opens a time-boxed window
 * (`approved_until`) in which one registration may happen. The request is spent once
 * `used_at` is set, which is what stops an old approval being reused months later.
 */
enum DeviceResetStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
        };
    }
}
