"""ML evaluation on labeled samples (Phase 11 — 6.6.2 anomaly, 6.6.3 prediction).

Scores the two internal models against a deterministic ground-truth sample and asserts
documented precision / recall floors. The printed metrics are the source of the numbers
recorded in ``docs/ai/ml-evaluation.md`` — run with ``pytest -s`` to see them.
"""

from __future__ import annotations

from datetime import date

from app.ml import anomaly, prediction

from ._labeled_dataset import anomaly_sample, risk_sample


def _prf(tp: int, fp: int, fn: int) -> tuple[float, float, float]:
    precision = tp / (tp + fp) if (tp + fp) else 0.0
    recall = tp / (tp + fn) if (tp + fn) else 0.0
    f1 = 2 * precision * recall / (precision + recall) if (precision + recall) else 0.0
    return precision, recall, f1


def test_anomaly_detection_precision_recall():
    records, truth = anomaly_sample()
    detected = {
        (int(a["employee_id"]), a["work_date"].isoformat()) for a in anomaly.detect(records)
    }

    tp = len(detected & truth)
    fp = len(detected - truth)
    fn = len(truth - detected)
    precision, recall, f1 = _prf(tp, fp, fn)

    print(
        f"\n[anomaly] support={len(truth)} tp={tp} fp={fp} fn={fn} "
        f"precision={precision:.3f} recall={recall:.3f} f1={f1:.3f}"
    )

    # Documented floors (see docs/ai/ml-evaluation.md).
    assert recall >= 0.80
    assert precision >= 0.60


def test_absenteeism_prediction_precision_recall():
    as_of = date(2026, 6, 1)
    records, truth = risk_sample(as_of=as_of)
    result = prediction.predict(records, as_of=as_of)

    predicted = {int(p["employee_id"]): (p["risk_score"] >= 0.5) for p in result["predictions"]}

    tp = sum(1 for e, is_high in predicted.items() if is_high and truth.get(e) == 1)
    fp = sum(1 for e, is_high in predicted.items() if is_high and truth.get(e) == 0)
    fn = sum(1 for e, is_high in predicted.items() if not is_high and truth.get(e) == 1)
    precision, recall, f1 = _prf(tp, fp, fn)

    print(
        f"\n[prediction] model={result['model']['version']} "
        f"fallback={result['model']['fallback']} metrics={result['model'].get('metrics')}"
    )
    print(
        f"[prediction] support_pos={sum(truth.values())} tp={tp} fp={fp} fn={fn} "
        f"precision={precision:.3f} recall={recall:.3f} f1={f1:.3f}"
    )

    # Documented floors (see docs/ai/ml-evaluation.md).
    assert recall >= 0.70
    assert precision >= 0.60
