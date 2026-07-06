# ML Evaluation — Precision / Recall (Phase 11)

This document records the quantitative evaluation of the two internal scikit-learn models that
power the project's AI features, against a **labeled** attendance sample. It is the evidence for
the Phase 11 requirement *"ML evaluation (precision/recall on labeled sample; document metrics)"*
and complements the qualitative write-up in [`explainability.md`](explainability.md).

- **6.6.2 Anomaly detection** — Isolation Forest + per-employee z-score (`app/ml/anomaly.py`)
- **6.6.3 Absenteeism-risk prediction** — RandomForest classifier (`app/ml/prediction.py`)

## Why a synthetic labeled sample

The project runs on stub/simulated biometric data during development (no production attendance
history with human-verified "this day was genuinely anomalous" labels exists yet). To measure
precision/recall we therefore construct a **deterministic labeled dataset** with known ground
truth — mostly-normal attendance into which specific, known anomalies and known high-risk
patterns are injected. The generator is seeded (`random.Random(42)`), and both models use a
fixed `RANDOM_STATE = 42`, so the metrics below are **reproducible** exactly, run-to-run and in
CI.

Generator: [`ai-service/tests/_labeled_dataset.py`](../../ai-service/tests/_labeled_dataset.py)
Evaluation: [`ai-service/tests/test_ml_evaluation.py`](../../ai-service/tests/test_ml_evaluation.py)

## Metric definitions

For each model, a prediction is compared to ground truth as a binary decision:

- **Precision** = TP / (TP + FP) — of the items the model flagged, how many were truly positive.
- **Recall** = TP / (TP + FN) — of the truly positive items, how many the model caught.
- **F1** = harmonic mean of precision and recall.

## 1. Anomaly detection (6.6.2)

**Sample.** 6 employees × 25 days = 150 attendance rows. Two anomalies injected per employee at
fixed positions — one **unexplained absence** (no punch, no approved leave) and one
**extreme-late / off-hours sign-in** (~11:00, ~180 min late). Injected anomalies = 12 (8% of
rows, matching the detector's `CONTAMINATION = 0.08` prior). Ground truth is the set of
`(employee_id, work_date)` pairs that were injected.

**Decision.** An `(employee_id, work_date)` returned by `anomaly.detect()` (from either the
Isolation Forest or the z-score detector, merged) counts as a positive.

| Metric | Value | Support (true anomalies) |
| --- | --- | --- |
| Precision | **1.000** | 12 |
| Recall | **1.000** | 12 |
| F1 | **1.000** | 12 |
| TP / FP / FN | 12 / 0 / 0 | |

The two detectors agree perfectly on this sample: the injected absences and off-hours sign-ins
are unambiguous multivariate outliers, so there are no false positives among the homogeneous
normal days and no misses.

**Enforced floors** (asserted in the test): recall ≥ 0.80, precision ≥ 0.60. The generous margin
above the floors means routine refactors won't silently regress detection quality while still
tolerating the natural jitter of real data.

## 2. Absenteeism-risk prediction (6.6.3)

**Sample.** 24 employees × 92 days. 12 employees follow a **high-risk** pattern (~40% absence
rate, frequent lateness) and 12 follow a **low-risk** pattern (~2% absence, punctual). Ground
truth is the intended class per employee. The RandomForest trained (non-fallback) on 24
self-supervised samples with both classes present.

**Decision.** An employee is predicted high-risk when the returned `risk_score ≥ 0.50`.

| Metric | Value | Support (high-risk employees) |
| --- | --- | --- |
| Precision | **0.750** | 12 |
| Recall | **1.000** | 12 |
| F1 | **0.857** | 12 |
| TP / FP / FN | 12 / 4 / 0 | |

The model's own held-out train/test split (25% test) reported: **accuracy 0.833, precision 0.80,
recall 1.00** (`train_samples = 24`).

Interpretation: at the 0.50 threshold the model catches **every** high-risk employee (recall
1.0) at the cost of a few conservative false positives (4 low-risk employees scored ≥ 0.50).
For an HR **early-warning** tool this is the desirable trade-off — the cost of a missed at-risk
employee (no follow-up) outweighs the cost of a false alarm (a harmless check-in). The
`risk_level` bands (`low < 0.34 ≤ medium < 0.67 ≤ high`) let HR prioritise the strongest signals.

**Enforced floors** (asserted in the test): recall ≥ 0.70, precision ≥ 0.60.

## Reproduce

```bash
cd ai-service
./venv/Scripts/python.exe -m pytest tests/test_ml_evaluation.py -s -q   # -s prints the metrics
```

The `-s` flag surfaces the exact `precision=… recall=… f1=…` lines the table above is copied from.
Because everything is seeded, the printed numbers match this document exactly.

## Limitations & next step

These metrics validate that the models behave correctly on data with a **known** signal — they
confirm the pipeline detects the patterns it is designed to detect, reproducibly. They are not a
claim about accuracy on live IFM data. When real, human-labeled attendance history is available,
re-run the same harness against it (swap the generator for the labeled export) to obtain
production metrics; the evaluation code and thresholds carry over unchanged.
