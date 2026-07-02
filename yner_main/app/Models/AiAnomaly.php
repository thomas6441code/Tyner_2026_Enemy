<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiAnomaly extends Model
{
    protected $fillable = [
        'employee_id',
        'work_date',
        'method',
        'score',
        'explanation',
        'features',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date:Y-m-d',
            'score' => 'float',
            'features' => 'array',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
