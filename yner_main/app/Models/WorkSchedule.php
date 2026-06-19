<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkSchedule extends Model
{
    protected $fillable = [
        'name',
        'start_time',
        'end_time',
        'grace_period_minutes',
    ];

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
