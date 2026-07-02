"""Anomaly detection over daily attendance features.

Two complementary, explainable methods run together:

* **Isolation Forest** (scikit-learn) — an unsupervised ensemble that isolates outliers in
  the multivariate feature space (unusual combinations of late/worked/punch-time). It needs
  no labels and is well suited to the small tabular attendance data here.
* **Z-score** — a per-employee statistical check flagging any day whose late-minutes or
  punch-in time sits far (>3σ) from that employee's own normal, giving a transparent,
  single-feature reason a reviewer can read at a glance.

Each anomaly carries a human-readable ``explanation`` naming the driving feature so the team
can *explain how it works* (supervisor requirement).
"""

from __future__ import annotations

import pandas as pd
from sklearn.ensemble import IsolationForest

from .features import daily_feature_frame

RANDOM_STATE = 42
Z_THRESHOLD = 3.0
CONTAMINATION = 0.08  # expected share of outliers; conservative for attendance data


def _explain_row(row: pd.Series) -> str:
    if row["is_absence"] == 1:
        return "Unexplained absence (no punch and no approved leave)."
    reasons = []
    if row["late_minutes"] > 0:
        reasons.append(f"{int(row['late_minutes'])} min late")
    if row["early_leave_minutes"] > 0:
        reasons.append(f"left {int(row['early_leave_minutes'])} min early")
    if row["worked_minutes"] > 0:
        reasons.append(f"{int(row['worked_minutes'])} min worked")
    return "Unusual pattern: " + ", ".join(reasons) if reasons else "Unusual attendance pattern."


def _isolation_forest(daily: pd.DataFrame) -> list[dict]:
    from .features import DAILY_FEATURE_COLUMNS

    matrix = daily[DAILY_FEATURE_COLUMNS].astype(float).to_numpy()
    model = IsolationForest(
        n_estimators=200,
        contamination=CONTAMINATION,
        random_state=RANDOM_STATE,
    )
    model.fit(matrix)
    labels = model.predict(matrix)  # -1 = anomaly
    # decision_function: lower = more anomalous. Flip so higher score = more anomalous.
    raw = model.decision_function(matrix)
    scores = (-raw).astype(float)

    out = []
    for idx, label in enumerate(labels):
        if label != -1:
            continue
        row = daily.iloc[idx]
        out.append(
            {
                "employee_id": int(row["employee_id"]),
                "work_date": row["work_date"].date(),
                "method": "isolation_forest",
                "score": round(float(scores[idx]), 4),
                "explanation": _explain_row(row),
                "features": {
                    "late_minutes": float(row["late_minutes"]),
                    "early_leave_minutes": float(row["early_leave_minutes"]),
                    "worked_minutes": float(row["worked_minutes"]),
                    "first_in_minutes": float(row["first_in_minutes"]),
                    "is_absence": float(row["is_absence"]),
                },
            }
        )
    return out


def _zscore(daily: pd.DataFrame) -> list[dict]:
    out = []
    for employee_id, group in daily.groupby("employee_id"):
        if len(group) < 4:
            continue  # too few points for a meaningful per-employee baseline
        for column, human in (
            ("late_minutes", "late minutes"),
            ("first_in_minutes", "sign-in time"),
        ):
            values = group[column].astype(float)
            std = values.std(ddof=0)
            if std == 0:
                continue
            mean = values.mean()
            z = (values - mean) / std
            for idx, z_value in z.items():
                if abs(z_value) < Z_THRESHOLD:
                    continue
                row = group.loc[idx]
                out.append(
                    {
                        "employee_id": int(employee_id),
                        "work_date": row["work_date"].date(),
                        "method": "zscore",
                        "score": round(float(abs(z_value)), 4),
                        "explanation": (
                            f"{human.capitalize()} of {values.loc[idx]:.0f} is "
                            f"{abs(z_value):.1f}σ from this employee's average ({mean:.0f})."
                        ),
                        "features": {column: float(values.loc[idx]), "z_score": float(z_value)},
                    }
                )
    return out


def detect(records: list[dict]) -> list[dict]:
    """Run both detectors and return a merged, de-duplicated anomaly list.

    When a (employee, date) is flagged by both methods, the higher-signal Isolation Forest
    entry wins; otherwise both appear (different reasons, different methods).
    """
    from .features import records_to_frame

    daily = daily_feature_frame(records_to_frame(records))
    if daily.empty:
        return []

    anomalies = []
    if len(daily) >= 8:  # Isolation Forest needs a few points to be meaningful
        anomalies.extend(_isolation_forest(daily))
    anomalies.extend(_zscore(daily))

    # Prefer isolation_forest on collisions, then keep the higher score.
    best: dict[tuple[int, object], dict] = {}
    priority = {"isolation_forest": 1, "zscore": 0}
    for item in anomalies:
        key = (item["employee_id"], item["work_date"])
        current = best.get(key)
        if current is None:
            best[key] = item
            continue
        if priority[item["method"]] > priority[current["method"]] or (
            item["method"] == current["method"] and item["score"] > current["score"]
        ):
            best[key] = item

    return sorted(best.values(), key=lambda a: a["score"], reverse=True)
