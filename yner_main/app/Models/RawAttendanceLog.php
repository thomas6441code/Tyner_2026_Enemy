<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RawAttendanceLog extends Model
{
    protected $fillable = [
        'device_user_id',
        'punched_at',
        'device_serial',
        'raw_payload',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'punched_at' => 'datetime',
            'raw_payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
