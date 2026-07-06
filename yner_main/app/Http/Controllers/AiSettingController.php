<?php

namespace App\Http\Controllers;

use App\Models\AiSetting;
use App\Services\AiInsightsClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AiSettingController extends Controller
{
    /**
     * AI Settings — Admin-configurable provider/model/API key for report-summary narration
     * (Phase 8). The API key is write-only: never rendered back to the client, only a
     * has-a-key flag and a last-4 hint.
     */
    public function edit(Request $request): Response
    {
        Gate::authorize('manageAiSettings');

        $setting = AiSetting::current();

        return Inertia::render('settings/ai', [
            'provider' => $setting->provider,
            'base_url' => $setting->base_url,
            'model' => $setting->model,
            'has_api_key' => filled($setting->api_key),
            'api_key_hint' => $setting->api_key ? substr($setting->api_key, -4) : null,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        Gate::authorize('manageAiSettings');

        $validated = $request->validate([
            'provider' => ['required', 'string', 'max:50'],
            'base_url' => ['required', 'url', 'max:255'],
            'model' => ['required', 'string', 'max:255'],
            'api_key' => ['nullable', 'string', 'max:500'],
        ]);

        $setting = AiSetting::current();

        // Blank API key means "keep the current one" — the field is never pre-filled with the
        // real value, so an empty submit should not wipe out an already-configured key.
        $setting->fill([
            'provider' => $validated['provider'],
            'base_url' => $validated['base_url'],
            'model' => $validated['model'],
            'updated_by' => $request->user()?->id,
        ]);

        if (filled($validated['api_key'] ?? null)) {
            $setting->api_key = $validated['api_key'];
        }

        $setting->save();

        return back()->with('status', 'AI settings updated.');
    }

    /**
     * "Test Connection" — a live round-trip to the configured LLM provider, used from the AI
     * Settings page so an admin can verify provider/model/key changes before saving. A blank
     * `api_key` in the request falls back to the already-saved key (mirrors update()'s
     * "leave blank to keep" behaviour), so testing works without re-entering a saved key.
     */
    public function test(Request $request): JsonResponse
    {
        Gate::authorize('manageAiSettings');

        $validated = $request->validate([
            'provider' => ['required', 'string', 'max:50'],
            'base_url' => ['required', 'url', 'max:255'],
            'model' => ['required', 'string', 'max:255'],
            'api_key' => ['nullable', 'string', 'max:500'],
        ]);

        $setting = AiSetting::current();
        $apiKey = filled($validated['api_key'] ?? null) ? $validated['api_key'] : $setting->api_key;

        $result = app(AiInsightsClient::class)->testConnection([
            'provider' => $validated['provider'],
            'base_url' => $validated['base_url'],
            'model' => $validated['model'],
            'api_key' => $apiKey,
        ]);

        if ($result === null) {
            return response()->json([
                'ok' => false,
                'error' => 'The AI service is unreachable. Confirm ai-service is running and try again.',
            ], 502);
        }

        return response()->json($result);
    }
}
