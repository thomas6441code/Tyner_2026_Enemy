<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BiometricDevice extends Model
{
    protected $fillable = [
        'name',
        'type',
        'serial',
        'host',
        'port',
        'username',
        'password',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'password' => 'encrypted',
        ];
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(DeviceEnrollment::class);
    }
}
