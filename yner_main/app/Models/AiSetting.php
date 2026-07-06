<?php

namespace App\Models;

use App\Services\AiInsightsClient;
use Illuminate\Database\Eloquent\Model;

/**
 * Singleton row holding the admin-configurable LLM provider/model/API key used by
 * {@see AiInsightsClient} for report-summary narration (Phase 8). Editable from
 * the AI Settings page (Admin-only, see the `manageAiSettings` gate).
 */
class AiSetting extends Model
{
    protected $fillable = ['provider', 'base_url', 'model', 'api_key', 'updated_by'];

    protected $casts = [
        'api_key' => 'encrypted',
    ];

    /**
     * The one settings row, created with sane defaults on first access.
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate([], [
            'provider' => 'openrouter',
            'base_url' => 'https://openrouter.ai/api/v1',
            'model' => 'anthropic/claude-sonnet-4.5',
        ]);
    }
}
