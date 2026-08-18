<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    protected $fillable = [
        'name',
        'description',
        'work_location_id',
    ];

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /**
     * Default geofence site for everyone in this department, inherited by any employee who has
     * no location of their own.
     */
    public function workLocation(): BelongsTo
    {
        return $this->belongsTo(WorkLocation::class);
    }
}
