# Phase 7 — AI Service: Internal ML (analysis, anomaly detection, prediction)

## Context

Per `development_plan.md`, Phase 7 layers **explainable, self-hosted intelligence** over the attendance
data that Phases 4–6 produce. Three proposal features must be delivered:

- **6.6.1 Smart attendance analysis** — lateness/absenteeism rates, trend aggregation.
- **6.6.2 Anomaly detection** — Isolation Forest / statistical z-score on punch-time & pattern outliers.
- **6.6.3 Predictive monitoring** — a classifier that outputs an absenteeism/lateness **risk score** per
  employee.

Plus an **explainability** deliverable (feature importance + method notes) so the team can *explain how it
works* per the supervisor's annotation, and a documented **Laravel ↔ AI REST contract** with scheduled batch
scoring that persists into `ai_anomalies` / `ai_predictions`.

The AI service stubs already exist (`ai-service/app/routers/analysis.py`) with `/api/analysis/anomalies`,
`/api/analysis/predictions`, `/api/analysis/summary` (summary is Phase 8). `requirements-ml.txt` pins the ML
deps, uninstalled until now.

## Locked design decision: data flows Laravel → AI (AI stays stateless)

Matching the existing architecture (Laravel is the single source of truth and *calls* the Python services;
bio-service pushes *into* Laravel; the AI service never touches MySQL) and the privacy rule (send aggregates,
no PII), **a Laravel scheduled command assembles the attendance dataset and POSTs it to the AI service**,
which engineers features, runs the models, and returns scored results. Laravel persists them.

- Payload carries only internal `employee_id` + numeric/date features — **never names**.
- The AI service is stateless: fit-on-batch (Isolation Forest is unsupervised; the RandomForest is trained
  per run on a self-derived label). Trained artifacts + feature importances are returned in the response (and
  optionally cached to `ai-service/models/` via joblib) for explainability.
- Graceful degradation everywhere: if the AI service is down, the Laravel command logs and no-ops; if there is
  too little history for a supervised model, the AI service falls back to a transparent rule-based risk score.

## REST contract

```
POST /api/analysis/anomalies       (X-Internal-Secret)
  { "records": [ { employee_id, work_date, status, first_in, last_out,
                   worked_minutes, late_minutes, early_leave_minutes,
                   is_leave, schedule_start, schedule_end }, ... ] }
→ { "anomalies": [ { employee_id, work_date, method, score, explanation,
                     features: {...} }, ... ],
    "meta": { count, method, generated_at } }

POST /api/analysis/predictions     (X-Internal-Secret)
  { "records": [ ...same shape... ], "as_of": "YYYY-MM-DD" }
→ { "predictions": [ { employee_id, risk_score, risk_level,
                       top_factors: [ { feature, value, importance } ] }, ... ],
    "model": { version, feature_importances: {...}, metrics: {...}, fallback: bool } }
```

`is_leave` = the record's status is an approved-absence type (so leave days are not treated as anomalous
absences). Records with a leave status are excluded from "absence" signals in feature engineering.

## AI service (`ai-service/`)

1. **Install ML deps** — `pip install -r requirements-ml.txt` (scikit-learn, pandas, numpy, joblib).
2. **`app/schemas.py`** — pydantic request/response models for the contract above.
3. **`app/ml/features.py`** — pandas pipeline that turns the flat record list into:
   - per-day feature rows (for anomaly detection): late_minutes, worked_minutes, punch-time-of-day,
     early_leave_minutes, is_absence.
   - per-employee aggregate features (for prediction): absence_rate, late_rate, avg_late_minutes,
     worked_minutes_mean/std, punch_time_std, trend_slope (recent vs older window), distinct_active_days.
4. **`app/ml/anomaly.py`** — Isolation Forest over the per-day feature matrix **plus** a per-employee z-score
   check on punch-time / late-minutes; each returned anomaly includes a score and a human-readable
   `explanation` naming the driving feature.
5. **`app/ml/prediction.py`** — RandomForestClassifier trained on per-employee features with a self-derived
   binary label (high absence/late rate in a recent outcome window vs the earlier feature window); outputs
   per-employee `risk_score` (probability), bucketed `risk_level` (low/medium/high), per-row `top_factors`,
   and global `feature_importances`. Reports simple train/test metrics. Rule-based weighted fallback when the
   data is too small or single-class.
6. **Routers** — implement `/api/analysis/anomalies` and `/api/analysis/predictions`; leave `/summary` as the
   Phase 8 stub.
7. **`docs/ai/explainability.md`** — one page: what each feature means, how Isolation Forest / z-score /
   RandomForest work here, and how to read the risk score (defense prep).
8. **pytest** — feature engineering correctness; an injected obvious outlier is flagged; prediction output
   shape + determinism (seeded); endpoint auth (403 without secret) and happy-path contract with synthetic
   records.

## Laravel (`yner_main/`)

1. **Migrations + models**
   - `ai_anomalies`: `employee_id` FK, `work_date`, `method`, `score` (float), `explanation`, `features` json,
     timestamps; unique `(employee_id, work_date, method)` for idempotent upsert.
   - `ai_predictions`: `employee_id` FK, `risk_score` (float), `risk_level`, `top_factors` json,
     `model_version`, `computed_at`, timestamps; unique `(employee_id)` (latest score per employee, upserted).
   - `App\Models\AiAnomaly`, `App\Models\AiPrediction` with `employee()` relations; a `RiskLevel`
     string-backed enum (low/medium/high) with a `label()`.
2. **`config/services.php`** — add an `ai` block (`url` from `AI_SERVICE_URL`, reuse `internal_secret`).
3. **`App\Services\AiInsightsClient`** — signed `Http::withHeaders(['X-Internal-Secret' => ...])` client for
   both endpoints; returns structured results or an empty/failed marker on error (logged, never throws).
4. **`app/Console/Commands/ScoreAttendance.php`** (`ai:score-attendance {--days=90}`) — load active employees'
   attendance history (with schedule times), build the payload, call the client, idempotently upsert into
   `ai_anomalies` / `ai_predictions`, log an `ai.scored` summary, print a summary line. Schedule daily at
   **02:00** (after the 01:00 `attendance:compute`) in `routes/console.php`.
   *Decision:* AI scores are recomputed analytics (rewritten every run), not record-level state changes, so
   the `ai.scored` entry is a structured `Log::info` summary rather than an `audit_logs` row — the audit table
   is reserved for attendance/permission state changes and requires a per-record `auditable`.
5. **AI Insights read page** — `AiInsightController@index` → Inertia `ai/insights` (Admin/HR only, gated by a
   policy/gate), showing an anomalies table, a risk-score table (sorted high→low), and the model's feature
   importances. Sidebar link conditioned on a shared `can.viewAiInsights` prop. Heavy charts stay in Phase 10.
6. **Feature test** — seed employees + attendance history (one with an obvious anomaly), bind a fake
   `AiInsightsClient` returning canned scores, run `ai:score-attendance`, assert both tables populate and the
   command is idempotent on re-run.

## Verification

- `pytest` (ai-service) and `php artisan test` (yner_main) green.
- Manual: run `ai:score-attendance` against a seeded DB → `ai_anomalies`/`ai_predictions` populate; the AI
  Insights page renders anomalies + risk scores + feature importance; with the AI service down the command
  logs a warning and exits cleanly (no crash, no partial writes).

## Wrap-up

- This plan saved to `prompt/phase-7-ai-internal-ml.md`.
- Mark Phase 7 complete in `development_plan.md`; commit.
