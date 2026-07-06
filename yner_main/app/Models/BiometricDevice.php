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
        'last_checked_at',
        'last_status',
        'last_status_message',
    ];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'password' => 'encrypted',
            'last_checked_at' => 'datetime',
        ];
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(DeviceEnrollment::class);
    }
}
