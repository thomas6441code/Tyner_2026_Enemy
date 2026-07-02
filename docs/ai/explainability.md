# AI Feature Explainability (Phase 7 — Internal ML)

This document answers the supervisor's annotation *"explain how each AI feature works"* for the
three internal, self-hosted machine-learning features. All models run inside `ai-service`
(Python/FastAPI + scikit-learn); the external Claude API is **not** used here (that is Phase 8,
summarization only). No employee names or raw biometric templates are ever sent to the model —
the input is a list of daily attendance records keyed by internal `employee_id` plus numeric
features.

## Data flow

```
Laravel (ai:score-attendance, nightly 02:00)
  └─ gathers active employees' attendance_records + schedule times
     └─ POST /api/analysis/anomalies      → anomaly list
     └─ POST /api/analysis/predictions     → per-employee risk scores
        └─ Laravel persists into ai_anomalies / ai_predictions
```

The AI service is **stateless**: every call ships the data it needs, the model is fit on that
batch, and the scored result (plus feature importances) is returned. This keeps MySQL as the
single source of truth and keeps PII out of the ML tier.

## Feature engineering (`app/ml/features.py`)

From each daily record we derive:

| Feature | Meaning |
| --- | --- |
| `late_minutes` | Minutes past the scheduled sign-in. |
| `early_leave_minutes` | Minutes the employee left before scheduled sign-out. |
| `worked_minutes` | Total minutes between first-in and last-out. |
| `first_in_minutes` | Sign-in time expressed as minutes since midnight (captures *when* they arrive). |
| `is_absence` | 1 only when the day is `absent` **and not** covered by an approved leave. |

Approved-leave days (`is_leave = true`) are excluded from absence signals, so a synced
permission/leave (Phase 6) never looks like absenteeism — this is what ties Phase 7 back to the
project's core innovation.

Per-employee aggregates for risk prediction: `absence_rate`, `late_rate`, `avg_late_minutes`,
`worked_minutes_mean/std`, `first_in_std` (arrival-time consistency), and `trend_slope` (is
absence getting worse over time?).

## 1. Smart attendance analysis (6.6.1)

The aggregate features above *are* the smart analysis: lateness rate, absenteeism rate, working
hours consistency and a directional trend per employee. They are surfaced on the AI Insights
page and reused as model inputs.

## 2. Anomaly detection (6.6.2) — `app/ml/anomaly.py`

Two complementary, explainable detectors run together:

- **Isolation Forest** (unsupervised ensemble). It repeatedly makes random splits; points that
  get *isolated* in very few splits are outliers. It needs no labels and catches unusual
  *combinations* of features (e.g. present but with abnormally low worked-minutes and high
  lateness). `contamination ≈ 0.08` sets how many outliers we expect. The `decision_function` is
  flipped so a **higher score = more anomalous**.
- **Z-score** (per-employee statistical check). For each employee with enough history, a day
  whose `late_minutes` or `first_in_minutes` sits more than **3σ** from *that employee's own*
  average is flagged, with a plain-English reason ("sign-in of 10:42 is 3.4σ from this
  employee's average of 08:05"). This gives a transparent single-feature explanation a reviewer
  can trust.

On collisions the Isolation-Forest entry wins; results are sorted by score.

## 3. Predictive monitoring (6.6.3) — `app/ml/prediction.py`

A **RandomForestClassifier** outputs an absenteeism/lateness **risk score** in `[0, 1]` per
employee, bucketed into low / medium / high.

- **Self-supervised labels** — no manual annotation. Each employee's history is split at
  `as_of − 30 days`; features come from the earlier *feature window*, the label is 1 when the
  recent *outcome window* shows a high absence/late rate (≥ 0.30). Training rows are pooled
  across employees.
- **Explainability** — the response returns global `feature_importances` (which patterns the
  forest relied on) and per-employee `top_factors` (the employee's own driving features), so a
  score is never a black box.
- **Metrics** — when there is enough data, a held-out split reports accuracy / precision /
  recall for the defense (Phase 11 formalizes ML evaluation).
- **Graceful fallback** — with too little history or a single class, the service returns a
  transparent weighted-rule score (`0.45·absence_rate + 0.25·late_rate + 0.30·trend_slope`) and
  marks `fallback = true`, so the endpoint always returns something usable.

## Reproducibility

All models use `random_state = 42`, so a given batch produces the same scores every run.
