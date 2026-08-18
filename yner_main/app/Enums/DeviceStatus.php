<?php

namespace App\Enums;

/**
 * Lifecycle of a registered WebAuthn authenticator.
 *
 * Stored as string(20) with a PHP enum cast rather than a DB enum, matching the
 * permission_requests / registration_requests precedent and avoiding MySQL ALTER pain.
 */
enum DeviceStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Revoked => 'Revoked',
        };
    }
}
