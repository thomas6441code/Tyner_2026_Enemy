<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A geofenced site an employee may check in from on the mobile channel.
 *
 * Coordinates are stored as decimal(10,7) (~1.1cm precision, exact) rather than float, so a
 * distance comparison against `radius_meters` is reproducible. MySQL hands decimals back as
 * strings and SQLite as floats, hence the explicit float casts below — GeofenceService does
 * arithmetic on these and must never receive a numeric string.
 */
class WorkLocation extends Model
{
    protected $fillable = [
        'name',
        'address',
        'latitude',
        'longitude',
        'radius_meters',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'radius_meters' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }
}
