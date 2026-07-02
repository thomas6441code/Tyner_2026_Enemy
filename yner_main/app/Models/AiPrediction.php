<?php

namespace App\Models;

use App\Enums\RiskLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiPrediction extends Model
{
    protected $fillable = [
        'employee_id',
        'risk_score',
        'risk_level',
        'top_factors',
        'model_version',
        'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'risk_score' => 'float',
            'risk_level' => RiskLevel::class,
            'top_factors' => 'array',
            'computed_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
