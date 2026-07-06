<?php

namespace App\Services;

use App\Models\AiSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Signed HTTP client for the internal Python AI service (Phase 7).
 *
 * yner_main is the orchestrator: it assembles the attendance dataset and POSTs it here; the
 * AI service is stateless and returns scored results. Every call is signed with the shared
 * `X-Internal-Secret` header (verified by the AI service's `verify_internal_secret`).
 *
 * All methods degrade gracefully: a network error, timeout, or non-2xx response is logged and
 * returns `null`, so a nightly batch never crashes because the AI tier is momentarily down.
 */
class AiInsightsClient
{
    /**
     * @param  array<int, array<string, mixed>>  $records
     * @return array{anomalies: array<int, array<string, mixed>>, meta: array<string, mixed>}|null
     */
    public function detectAnomalies(array $records): ?array
    {
        return $this->post('/api/analysis/anomalies', ['records' => $records]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     * @return array{predictions: array<int, array<string, mixed>>, model: array<string, mixed>}|null
     */
    public function predictRisk(array $records, ?string $asOf = null): ?array
    {
        return $this->post('/api/analysis/predictions', array_filter([
            'records' => $records,
            'as_of' => $asOf,
        ], fn ($value) => $value !== null));
    }

    /**
     * Send aggregated monthly stats for LLM narration (Phase 8). Aggregates only — no PII.
     * The current AI Settings (provider/model/API key, see {@see AiSetting}) are merged into
     * the payload so ai-service can call the configured LLM without owning any of that state.
     *
     * @param  array<string, mixed>  $stats
     * @return array{narrative: string, highlights: array<int, string>, recommendations: array<int, string>, model: string, fallback: bool, generated_at: string}|null
     */
    public function summarize(array $stats): ?array
    {
        $setting = AiSetting::current();

        return $this->post('/api/analysis/summary', [
            ...$stats,
            'provider' => $setting->provider,
            'model' => $setting->model,
            'api_key' => $setting->api_key,
            'base_url' => $setting->base_url,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function post(string $path, array $payload): ?array
    {
        $url = rtrim((string) config('services.ai.url'), '/').$path;

        try {
            $response = Http::timeout((int) config('services.ai.timeout', 30))
                ->withHeaders(['X-Internal-Secret' => config('services.internal_secret')])
                ->acceptJson()
                ->post($url, $payload);

            if ($response->failed()) {
                Log::warning('AI service call failed', [
                    'url' => $url,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            return $response->json();
        } catch (Throwable $e) {
            Log::warning('AI service unreachable', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
