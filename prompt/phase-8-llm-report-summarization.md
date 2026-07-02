# Phase 8 — AI Service: External LLM Report Summarization (Claude API)

## Context

Per `development_plan.md`, Phase 8 delivers proposal feature **6.6.5 Automated report summarization**: a
human-readable **monthly narrative** over the attendance data, produced by the **external Claude API**. This
is the "external" half of the supervisor's "internal or external — and how?" answer (Phase 7 was the internal
scikit-learn half).

The AI service stub already exists (`ai-service/app/routers/analysis.py` → `/api/analysis/summary` returns
`{"message": "not yet implemented"}`). `anthropic==0.42.0` is already pinned in `requirements.txt`, and
`app/config.py` already carries `anthropic_api_key` + `claude_model` (default `claude-sonnet-4-6`).

## Locked design decision: Laravel aggregates → AI summarizes → Laravel caches

Matching the Phase 7 architecture (Laravel is the single source of truth and *calls* the stateless Python
services; the AI service never touches MySQL) and the **privacy rule** (send **aggregates only**, never PII):

- Laravel's `MonthlyReportAggregator` computes month-level, department-scoped **aggregate statistics**
  (headcounts, status counts, attendance/punctuality/absence rates, leave breakdown, late stats, anomaly &
  high-risk counts, previous-month trend). **No employee names or per-person rows leave Laravel** — the
  scope label is at most a department name, never an individual.
- Laravel POSTs those aggregates to `POST /api/analysis/summary`. The AI service builds a prompt, calls the
  Claude Messages API, and returns a structured narrative + highlights + recommendations.
- **Caching:** Laravel persists each result in `report_summaries`, unique per `(period_month, department_id)`.
  Re-requesting a cached month returns the stored row without another Claude call unless `force` is set.
- **Graceful fallback everywhere:** if `ANTHROPIC_API_KEY` is empty or the API errors/times out, the AI
  service returns a **deterministic template-based** summary with `fallback: true` (no crash). If the AI
  service itself is unreachable, `AiInsightsClient::summarize()` returns `null` and the controller flashes a
  friendly error — never a 500.

## REST contract

```
POST /api/analysis/summary          (X-Internal-Secret)
  { period_label, scope, headcount, working_days,
    status_counts: { present, late, absent, ... }, leave_breakdown: {...},
    attendance_rate, punctuality_rate, absence_rate,
    late_incidents, avg_late_minutes,
    anomalies_count, high_risk_count,
    prev_attendance_rate?, top_patterns?: [ "..." ] }
→ { narrative, highlights: [..], recommendations: [..],
    model, fallback: bool, generated_at }
```

## AI service (`ai-service/`)

1. **`app/schemas.py`** — `SummaryRequest` (the aggregate contract above; all numeric/labels, no PII) and
   `SummaryResponse` (`narrative`, `highlights[]`, `recommendations[]`, `model`, `fallback`, `generated_at`).
2. **`app/llm/summarizer.py`** — `summarize(stats: dict) -> dict`:
   - Builds a system prompt ("HR attendance analytics assistant; aggregate data only; concise, factual;
     return strict JSON") + a user prompt rendering the stats.
   - Calls `anthropic.Anthropic(api_key=...).messages.create(...)` with the configured model; parses the JSON
     body (with a tolerant fallback that treats the raw text as the narrative if JSON parsing fails).
   - `_fallback(stats)` builds a readable template narrative from the numbers when the key is missing or the
     API errors — returns `fallback: True` and `model: "template-fallback"`. **Never raises.**
3. **Router** — implement `/api/analysis/summary` with `response_model=SummaryResponse`, keeping the
   `verify_internal_secret` dependency.
4. **pytest** (`tests/test_summary_endpoint.py`) — 403 without/with wrong secret; happy-path shape with the
   fallback path (no API key in test env) asserting `fallback` true and non-empty narrative; a monkeypatched
   Anthropic client returning canned JSON asserting it is parsed into `highlights`/`recommendations`.

## Laravel (`yner_main/`)

1. **Migration + model** — `report_summaries`: `period_month` (date, first of month), nullable
   `department_id` FK (nullOnDelete; null = organization-wide), `narrative` text, `highlights` json,
   `recommendations` json, `stats` json (the payload we sent, for audit/explainability), `model` string
   nullable, `fallback` bool, nullable `generated_by` FK → users, timestamps; unique
   `(period_month, department_id)`. `App\Models\ReportSummary` with casts + `department()` / `generatedBy()`.
2. **`App\Policies\ReportSummaryPolicy`** — `viewAny` + `create`: Admin / HR Officer only (same audience as
   AI insights; auto-discovered by Laravel's naming convention).
3. **`App\Services\MonthlyReportAggregator`** — `aggregate(CarbonInterface $month, ?Department $dept): array`
   computes the contract's aggregate stats from `attendance_records` (+ `ai_anomalies` count for the month,
   `ai_predictions` high-risk count), scoped to the department's active employees when given, plus the
   previous month's attendance rate for trend. Returns only numbers/labels.
4. **`AiInsightsClient::summarize(array $stats): ?array`** — signed POST to `/api/analysis/summary`; returns
   the decoded body or `null` on error (reuses the existing graceful `post()` helper).
5. **`App\Http\Controllers\ReportSummaryController`**
   - `index` — authorize `viewAny`; list stored summaries (department name, period, narrative, highlights,
     recommendations, model/fallback badges) newest first; pass departments + a default month (last completed
     month) for the form. Renders `reports/summaries`.
   - `store` — authorize `create`; validate `period` (`Y-m`), nullable `department_id` (exists), `force` bool;
     resolve the month; if a cached row exists and not `force`, flash "loaded from cache" and return; else
     aggregate → `summarize()`; on `null` flash an error (no write); else `updateOrCreate` keyed by
     `(period_month, department_id)` storing narrative/highlights/recommendations/stats/model/fallback +
     `generated_by`. Flash success.
6. **Routes** — `GET /report-summaries` (`report-summaries.index`), `POST /report-summaries`
   (`report-summaries.store`) inside the `auth` group.
7. **Sidebar + shared prop** — add `can.viewReportSummaries` (`viewAny` `ReportSummary`) in
   `HandleInertiaRequests`; add a "Report Summaries" link (FileText icon) under **Activities** in
   `app-sidebar.tsx`, gated on that prop.
8. **Frontend** — `resources/js/pages/reports/summaries.tsx`: a generate form (month input, department
   select incl. "Organization-wide", force-regenerate checkbox, submit via `useForm().post`) and a list of
   stored summaries rendered as cards (scope + period heading, narrative prose, highlights & recommendations
   lists, model / fallback / cached badges).
9. **Feature test** (`tests/Feature/ReportSummaryTest.php`) — bind a fake `AiInsightsClient` returning a
   canned summary; seed a month of attendance; assert `store` creates one `report_summaries` row with the
   narrative; re-post without `force` → still one row (cache hit, client not called again); Employee role is
   `403`; Admin allowed.

## Guardrails recap (defense prep)

- **Privacy:** only aggregate counts/rates + department label are sent externally — never names or raw punch
  rows. The exact payload is persisted in `report_summaries.stats` so the team can show *what* left the system.
- **Caching:** one Claude call per `(month, department)` unless explicitly regenerated.
- **Fallback:** missing key / API error → deterministic template summary (`fallback: true`); unreachable AI
  service → flashed error, no crash.

## Verification

- `pytest` (ai-service) and `php artisan test` (yner_main) green; `npm run build` compiles.
- Manual: open **Report Summaries**, generate a month → a narrative card appears; regenerate toggles a fresh
  call; with `ANTHROPIC_API_KEY` unset the card still renders with a "fallback" badge (no crash).

## Wrap-up

- This plan saved to `prompt/phase-8-llm-report-summarization.md`.
- Mark Phase 8 complete in `development_plan.md`; commit.
