"""Absenteeism / lateness risk prediction.

A **RandomForestClassifier** learns which attendance patterns precede a bad stretch, then
scores each employee's *current* standing as a risk probability in ``[0, 1]``.

Self-supervised labelling (no manual annotation needed): each employee's history is split at
``as_of - OUTCOME_DAYS``. Features are computed on the earlier *feature window*; the label is
1 when the *outcome window* shows a high absence/late rate. Training rows are pooled across
all employees. When there is too little data or only one class is present, the model falls
back to a transparent weighted-rule score so the endpoint always returns something usable
(graceful degradation).

The response exposes ``feature_importances`` and per-employee ``top_factors`` so the result is
explainable for the defense.
"""

from __future__ import annotations

from datetime import date

import pandas as pd
from sklearn.ensemble import RandomForestClassifier
from sklearn.metrics import accuracy_score, precision_score, recall_score
from sklearn.model_selection import train_test_split

from .features import EMPLOYEE_FEATURE_COLUMNS, employee_feature_frame, records_to_frame

RANDOM_STATE = 42
OUTCOME_DAYS = 30
BAD_OUTCOME_THRESHOLD = 0.30  # absence+late rate in the outcome window that marks a "bad" stretch
MIN_TRAIN_SAMPLES = 12
MODEL_VERSION = "rf-1.0"

# Transparent weights for the rule-based fallback (must cover EMPLOYEE_FEATURE_COLUMNS).
FALLBACK_WEIGHTS = {
    "absence_rate": 0.45,
    "late_rate": 0.25,
    "avg_late_minutes": 0.0,
    "worked_minutes_mean": 0.0,
    "worked_minutes_std": 0.0,
    "first_in_std": 0.0,
    "trend_slope": 0.30,
}


def _risk_level(score: float) -> str:
    if score < 0.34:
        return "low"
    if score < 0.67:
        return "medium"
    return "high"


def _build_training_set(df: pd.DataFrame, as_of: date) -> tuple[pd.DataFrame, pd.Series]:
    cutoff = pd.Timestamp(as_of) - pd.Timedelta(days=OUTCOME_DAYS)
    feature_rows, labels = [], []
    for employee_id, group in df.groupby("employee_id"):
        feature_window = group[group["work_date"] < cutoff]
        outcome_window = group[group["work_date"] >= cutoff]
        if len(feature_window) < 3 or len(outcome_window) < 3:
            continue
        feats = employee_feature_frame(feature_window)
        if feats.empty:
            continue
        worked = outcome_window[~outcome_window["is_leave"]]
        if len(worked) == 0:
            continue
        outcome_rate = float(worked["is_absence"].mean()) + float(
            (worked["late_minutes"] > 0).mean()
        )
        feature_rows.append(feats.iloc[0])
        labels.append(1 if outcome_rate >= BAD_OUTCOME_THRESHOLD else 0)

    if not feature_rows:
        return pd.DataFrame(columns=EMPLOYEE_FEATURE_COLUMNS), pd.Series(dtype=int)

    x = pd.DataFrame(feature_rows)[EMPLOYEE_FEATURE_COLUMNS].reset_index(drop=True)
    y = pd.Series(labels, dtype=int)
    return x, y


def _fallback(scoring: pd.DataFrame) -> dict:
    predictions = []
    for _, row in scoring.iterrows():
        score = sum(FALLBACK_WEIGHTS[col] * float(row[col]) for col in EMPLOYEE_FEATURE_COLUMNS)
        score = float(min(max(score, 0.0), 1.0))
        top = _top_factors(row, FALLBACK_WEIGHTS)
        predictions.append(
            {
                "employee_id": int(row["employee_id"]),
                "risk_score": round(score, 4),
                "risk_level": _risk_level(score),
                "top_factors": top,
            }
        )
    return {
        "predictions": predictions,
        "model": {
            "version": f"{MODEL_VERSION}-fallback",
            "feature_importances": {k: v for k, v in FALLBACK_WEIGHTS.items() if v > 0},
            "metrics": {},
            "fallback": True,
        },
    }


def _top_factors(row: pd.Series, importances: dict[str, float], limit: int = 3) -> list[dict]:
    contributions = []
    for col in EMPLOYEE_FEATURE_COLUMNS:
        weight = importances.get(col, 0.0)
        if weight <= 0:
            continue
        contributions.append(
            {
                "feature": col,
                "value": round(float(row[col]), 4),
                "importance": round(float(weight), 4),
            }
        )
    contributions.sort(key=lambda c: c["importance"], reverse=True)
    return contributions[:limit]


def predict(records: list[dict], as_of: date | None = None) -> dict:
    df = records_to_frame(records)
    if df.empty:
        return {
            "predictions": [],
            "model": {
                "version": MODEL_VERSION,
                "feature_importances": {},
                "metrics": {},
                "fallback": True,
            },
        }

    as_of = as_of or df["work_date"].max().date()
    scoring = employee_feature_frame(df)

    x_train, y_train = _build_training_set(df, as_of)
    if len(x_train) < MIN_TRAIN_SAMPLES or y_train.nunique() < 2:
        return _fallback(scoring)

    metrics: dict[str, float] = {}
    if len(x_train) >= 20 and y_train.nunique() == 2:
        x_tr, x_te, y_tr, y_te = train_test_split(
            x_train, y_train, test_size=0.25, random_state=RANDOM_STATE, stratify=y_train
        )
    else:
        x_tr, y_tr, x_te, y_te = x_train, y_train, None, None

    model = RandomForestClassifier(
        n_estimators=200, max_depth=6, random_state=RANDOM_STATE, class_weight="balanced"
    )
    model.fit(x_tr, y_tr)

    if x_te is not None:
        pred = model.predict(x_te)
        metrics = {
            "accuracy": round(float(accuracy_score(y_te, pred)), 4),
            "precision": round(float(precision_score(y_te, pred, zero_division=0)), 4),
            "recall": round(float(recall_score(y_te, pred, zero_division=0)), 4),
            "train_samples": int(len(x_train)),
        }
    else:
        metrics = {"train_samples": int(len(x_train))}

    importances = {
        col: float(imp) for col, imp in zip(EMPLOYEE_FEATURE_COLUMNS, model.feature_importances_)
    }

    proba = model.predict_proba(scoring[EMPLOYEE_FEATURE_COLUMNS])
    positive_idx = list(model.classes_).index(1)
    predictions = []
    for i, (_, row) in enumerate(scoring.iterrows()):
        score = float(proba[i][positive_idx])
        predictions.append(
            {
                "employee_id": int(row["employee_id"]),
                "risk_score": round(score, 4),
                "risk_level": _risk_level(score),
                "top_factors": _top_factors(row, importances),
            }
        )

    return {
        "predictions": predictions,
        "model": {
            "version": MODEL_VERSION,
            "feature_importances": {k: round(v, 4) for k, v in importances.items()},
            "metrics": metrics,
            "fallback": False,
        },
    }
