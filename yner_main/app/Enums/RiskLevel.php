<?php

namespace App\Enums;

/**
 * Absenteeism/lateness risk buckets produced by the AI service prediction model (Phase 7).
 * The AI service assigns the bucket from the raw probability; this enum mirrors those values
 * for type-safe casting and UI labelling on the Laravel side.
 */
enum RiskLevel: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Low',
            self::Medium => 'Medium',
            self::High => 'High',
        };
    }
}
