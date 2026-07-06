# Migrate ai-service LLM summarizer from Claude API to OpenRouter, with dynamic provider/model/key settings

## Context

`ai-service`'s report-summary feature (`app/llm/summarizer.py`) currently calls Claude directly via the `anthropic` Python SDK, with the model and API key hardcoded in `ai-service`'s own `.env` (`ANTHROPIC_API_KEY`, `CLAUDE_MODEL`). The user wants to move to OpenRouter (a single OpenAI-compatible gateway to many model providers) and, critically, wants **provider, model, and API key to be configurable at runtime from a settings page** in the Laravel app — not baked into env vars — for a "full dynamic experience" (swap models/providers without redeploying).

`yner_main` is the orchestrator (per CLAUDE.md) and already owns all config that crosses the service boundary — `ai-service` is stateless and just returns scored results. So the settings belong in Laravel (persisted, admin-editable), and get passed to `ai-service` per-request through the existing signed `X-Internal-Secret` channel, exactly like every other cross-service call in this codebase.

## Design decision

Rather than hardcoding "OpenRouter" as a single fixed provider, `ai-service`'s LLM client becomes a **generic OpenAI-compatible chat-completions client** (base URL + API key + model). OpenRouter is the default preset, but the same code path works for OpenAI, or any other OpenAI-compatible endpoint, by changing `base_url`. This is what makes "provider" a meaningful separate field from "model" in the settings UI, and it's the simplest implementation (one HTTP client, no per-vendor SDKs).

`ai-service` keeps env-var fallbacks (`LLM_*`) for standalone dev/testing, but a request from Laravel always carries the live settings, which win.

---

## 1. `yner_main` — new AI Settings feature (Admin-only)

**Migration** `database/migrations/2026_07_06_000000_create_ai_settings_table.php`: singleton table `ai_settings` — `provider` (string, default `openrouter`), `base_url` (string, default `https://openrouter.ai/api/v1`), `model` (string, default `anthropic/claude-sonnet-4.5`), `api_key` (text, nullable), `updated_by` (nullable FK to `users`), timestamps.

**Model** `app/Models/AiSetting.php`: `api_key` cast to `encrypted` (Laravel's built-in encrypted cast — never stored/logged in plaintext). Static `AiSetting::current()` using the `firstOrCreate([], [defaults])` singleton-row trick (same idea used elsewhere for "the one row" pattern).

**Gate** in `app/Providers/AppServiceProvider.php::boot()`, alongside the existing `viewReports` closure-gate: `Gate::define('manageAiSettings', fn (User $user) => $user->hasRole(RoleName::Admin->value));` — Admin only, since this stores a secret key and controls all AI spend.

**Controller** `app/Http/Controllers/AiSettingController.php` (`edit` / `update`, mirrors `ProfileController`):
- Both actions start with `Gate::authorize('manageAiSettings')` (same pattern as `ReportController`).
- `edit()`: Inertia render `settings/ai` with `provider`, `base_url`, `model`, and `has_api_key` (bool) + `api_key_hint` (last 4 chars if set) — **never** the raw key.
- `update()`: validates `provider` (string, required), `base_url` (required, `url`), `model` (required, string), `api_key` (`nullable`, string). Only overwrites `api_key` if a non-empty value was submitted (blank = "keep current key" — user shouldn't have to re-paste it every save); sets `updated_by`.

**Routes** in `routes/web.php` (inside the existing `auth` middleware group, next to `profile.*`):
```php
Route::get('/settings/ai', [AiSettingController::class, 'edit'])->name('ai-settings.edit');
Route::put('/settings/ai', [AiSettingController::class, 'update'])->name('ai-settings.update');
```

**Inertia page** `resources/js/pages/settings/ai.tsx`: form using the existing shadcn primitives (`Card`, `Input`, `Label`, `Select`, `Button`, `InputError`) and `useForm` + `put(route('ai-settings.update'))`, following `update-password-form.tsx`'s shape. Fields:
- Provider — shadcn `Select` with presets `OpenRouter` / `OpenAI` / `Custom`; selecting a preset auto-fills the Base URL field (still editable, so "Custom" just means "type your own").
- Base URL — text input.
- Model — text input with helper text/placeholder examples (`anthropic/claude-sonnet-4.5`, `openai/gpt-4o`, `google/gemini-2.5-pro`).
- API Key — password-type input, placeholder shows `has_api_key` ? `"•••• (unchanged if left blank)"` : `"Not set"`.

**Shared props**: add `'manageAiSettings' => $user?->can('manageAiSettings') ?? false` to the `can` array in `app/Http/Middleware/HandleInertiaRequests.php`.

**Sidebar**: add an "AI Settings" entry to `resources/js/components/app-sidebar.tsx` (new "System" nav group, `show: can.manageAiSettings`, a `Settings`/`Cpu` icon from `lucide-react`).

**Wire settings into the outgoing call** — `app/Services/AiInsightsClient.php::summarize()`:
```php
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
```
No changes needed to `ReportSummaryController` — it already just calls `$client->summarize($stats)`.

---

## 2. `ai-service` — generic OpenAI-compatible client, replacing the Anthropic SDK

**`app/config.py`**: replace `anthropic_api_key` / `claude_model` with generic fallback settings used only when a request doesn't carry overrides (standalone/dev use):
```python
llm_provider: str = "openrouter"
llm_base_url: str = "https://openrouter.ai/api/v1"
llm_api_key: str = ""
llm_model: str = "anthropic/claude-sonnet-4.5"
llm_timeout_seconds: int = 30
openrouter_referer: str = ""   # optional, for OpenRouter's attribution headers
openrouter_title: str = "EAPMS"
```

**`app/schemas.py`**: add optional override fields to `SummaryRequest`: `provider`, `model`, `api_key`, `base_url` (all `Optional[str] = None`). These flow in via `request.model_dump()` exactly as today — no router changes needed in `app/routers/analysis.py`. `_build_user_prompt` only reads its existing whitelist of keys, so these new fields are automatically excluded from the outbound prompt (same privacy guarantee as today, verified by existing `test_prompt_builder_ignores_injected_pii`).

**`app/llm/summarizer.py`** — rewritten:
- Drop `import anthropic`.
- New `_resolve_config(stats: dict) -> dict` — picks `provider`/`model`/`api_key`/`base_url` from the request if present and non-empty, else from `settings.llm_*`.
- `summarize()`: if resolved `api_key` is falsy → `_fallback(stats)` (same as today's "no key" path). Otherwise POST via `httpx.Client(timeout=settings.llm_timeout_seconds)` to `f"{base_url.rstrip('/')}/chat/completions"` with an OpenAI-compatible body (`model`, `messages: [system, user]`, `max_tokens: 1024`), `Authorization: Bearer <key>` header, plus `HTTP-Referer`/`X-Title` headers only when `openrouter_referer`/`openrouter_title` are set and the base URL is OpenRouter's.
- Parse `resp.json()["choices"][0]["message"]["content"]` as the reply text; reuse the existing `_parse_model_json` best-effort JSON parser and raw-text fallback untouched — the whole call stays inside the existing broad `try/except` so any network/parse/HTTP error still degrades to `_fallback()`, never raises.
- Update the module docstring and inline comments from "Claude Messages API" to "OpenRouter / OpenAI-compatible Chat Completions API"; the response's `model` field reports whatever model string was actually used (already generic, no change needed there).

**`requirements.txt`**: remove `anthropic==0.42.0` (no longer used; `httpx` — already a dependency — covers the raw HTTP call).

**`.env.example`** and **`docker-compose.yml`** (ai-service service env block): replace `ANTHROPIC_API_KEY` / `CLAUDE_MODEL` with `LLM_PROVIDER`, `LLM_BASE_URL`, `LLM_API_KEY`, `LLM_MODEL`.

**Root `.env.example`**: update the "AI Service" section similarly.

**Tests**: update `tests/test_summary_endpoint.py` (references `settings.anthropic_api_key`, "claude-sonnet-4-6") and `tests/test_summary_privacy.py` (unaffected in substance, but check wording) to use the new generic settings names; add one assertion that `api_key` never leaks into `_build_user_prompt` output (cheap, mirrors the existing PII test style).

**CLAUDE.md**: update the "AI is hybrid" bullet and the "Claude API usage (ai-service)" section to describe the OpenRouter/generic-provider setup instead of a hardcoded Claude model, since this file is the project's architecture reference and would otherwise go stale.

---

## Verification

1. `cd ai-service && pytest` — full suite green, especially `test_summary_endpoint.py` (fallback-without-key path, JSON-parse path) and `test_summary_privacy.py`.
2. `cd yner_main && php artisan migrate` then `php artisan test` (existing report-summary feature tests, if any, still pass).
3. Manual smoke test: log in as Admin, visit `/settings/ai`, save an OpenRouter API key + a model (e.g. `anthropic/claude-sonnet-4.5`), then generate a report summary from `/report-summaries` and confirm the narrative comes back non-fallback with the configured model name shown. Then clear the key and confirm it gracefully falls back to the template narrative.
