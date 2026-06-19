<?php

namespace App\Enums;

enum RoleName: string
{
    case Admin = 'Admin';
    case HrOfficer = 'HR Officer';
    case Employee = 'Employee';

    public static function values(): array
    {
        return array_map(fn (self $role) => $role->value, self::cases());
    }
}
