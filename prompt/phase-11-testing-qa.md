# Phase 11 — Testing & QA

> Status: **Complete.** Plan approved and executed in one session (Phase-11 scope only).
> Goal: verify every objective and AI feature, and produce the two named deliverables —
> a documented ML precision/recall evaluation and a UAT script with a requirements-traceability
> matrix + sign-off.

## Starting point (baseline audit)

All three suites were already green before Phase 11:

- Laravel (`php artisan test`): **108 passed**
- ai-service (`pytest`): **19 passed**
- bio-service (`pytest`): **19 passed**

An audit showed the signed-secret handshake is already tested in both directions
(`BiometricIngestTest`, `test_analysis_endpoints.py`, `test_pusher.py`) and ingest
malformed-payload validation exists. So Phase 11 filled the **genuine** gaps instead of
duplicating existing coverage.

## What was built

### 1. ML evaluation with documented precision/recall — `ai-service`
- **`ai-service/tests/_labeled_dataset.py`** — deterministic labeled sample generator (fixed
  seeds; reproducible). Produces:
  - a daily anomaly sample: mostly-normal attendance with a known set of injected anomalies
    (unexplained absences, extreme lateness, off-hours sign-in), each tagged with ground truth.
  - an employee risk sample: a feature window + outcome window per employee, labeled high-risk
    vs low-risk from the outcome window, so the RandomForest can be scored against known labels.
- **`ai-service/tests/test_ml_evaluation.py`** — runs `anomaly.detect()` and
  `prediction.predict()` over the labeled sample, computes precision / recall / F1 against the
  ground truth, and asserts documented floors. Reproducible via the modules' `RANDOM_STATE = 42`.
- **`docs/ai/ml-evaluation.md`** — methodology + the actual measured numbers from a real run,
  plus how to reproduce. Cross-linked from `docs/ai/explainability.md`.

### 2. Consolidated authorization matrix — `yner_main`
- **`tests/Feature/AuthorizationMatrixTest.php`** — data-driven role × route matrix
  (Admin / HR Officer / Employee / guest) across departments, employees, work-schedules,
  reports, report-summaries, ai-insights, biometric-devices. One readable authz proof.

### 3. Privacy & input-validation guardrails
- **`ai-service/tests/test_summary_privacy.py`** — locks the "aggregates only" guarantee: the
  summary request/response carry no PII-shaped keys (`name`, `employee_code`, `first_in`,
  raw records).
- **`tests/Feature/ReportValidationTest.php`** — the previously-untested report date-filter
  edges (inverted range swapped, span capped at 366 days, non-existent `department_id`).

### 4. UAT script + traceability matrix (named deliverable)
- **`docs/uat/uat-script.md`** — maps every Objective (1–6) and AI feature (6.6.1–6.6.6) to
  manual UAT steps + expected result **and** the automated test(s) that verify it
  (`file::test_name`), ending with a sign-off block.

### 5. Bookkeeping
- This plan record; `development_plan.md` Phase 11 marked ✅ COMPLETE.

## Measured ML metrics

See `docs/ai/ml-evaluation.md` for the recorded precision/recall/F1 (written from a real run).

## Not done (already covered — avoided duplication)
- Secret handshake (missing/wrong/valid) both directions; ingest dedupe + malformed payload;
  per-feature CRUD/authz happy paths (the matrix references, does not re-implement them).

## Maps to
6.3.5 (testing & QA); Objective 6 (accuracy/efficiency); traceability across Objectives 1–6 and
AI features 6.6.1–6.6.6.
