<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportSummary extends Model
{
    protected $fillable = [
        'period_month',
        'department_id',
        'narrative',
        'highlights',
        'recommendations',
        'stats',
        'model',
        'fallback',
        'generated_by',
    ];

    protected function casts(): array
    {
        return [
            'period_month' => 'date:Y-m-d',
            'highlights' => 'array',
            'recommendations' => 'array',
            'stats' => 'array',
            'fallback' => 'boolean',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
