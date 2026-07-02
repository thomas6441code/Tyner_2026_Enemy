"""Feature engineering over attendance history.

Two shapes are produced from the same flat record list:

* :func:`daily_feature_frame` — one row per attendance record, used by the unsupervised
  anomaly detector (Isolation Forest / z-score).
* :func:`employee_feature_frame` — one aggregate row per employee, used by the supervised
  absenteeism-risk classifier.

Everything is derived from numeric/date fields only; no names ever enter the pipeline.
"""

from __future__ import annotations

import pandas as pd

# Statuses that represent a real, unexcused absence (drives the "absence" signals). Leave
# days carry ``is_leave=True`` and are explicitly excluded so an approved permission never
# looks like absenteeism.
ABSENT_STATUS = "absent"

# Columns fed to the anomaly detector.
DAILY_FEATURE_COLUMNS = [
    "late_minutes",
    "early_leave_minutes",
    "worked_minutes",
    "first_in_minutes",
    "is_absence",
]

# Columns fed to the risk classifier.
EMPLOYEE_FEATURE_COLUMNS = [
    "absence_rate",
    "late_rate",
    "avg_late_minutes",
    "worked_minutes_mean",
    "worked_minutes_std",
    "first_in_std",
    "trend_slope",
]


def _time_to_minutes(value) -> float:
    """Minutes-since-midnight for a datetime/timestamp, or NaN when absent."""
    if value is None or pd.isna(value):
        return float("nan")
    ts = pd.to_datetime(value)
    return float(ts.hour * 60 + ts.minute)


def records_to_frame(records: list[dict]) -> pd.DataFrame:
    """Normalize the raw record dicts into a typed DataFrame."""
    if not records:
        return pd.DataFrame(
            columns=[
                "employee_id",
                "work_date",
                "status",
                "worked_minutes",
                "late_minutes",
                "early_leave_minutes",
                "is_leave",
                "first_in_minutes",
                "is_absence",
            ]
        )

    df = pd.DataFrame(records)
    df["work_date"] = pd.to_datetime(df["work_date"])
    df["first_in_minutes"] = (
        df.get("first_in").map(_time_to_minutes) if "first_in" in df else float("nan")
    )
    for col in ("worked_minutes", "late_minutes", "early_leave_minutes"):
        df[col] = pd.to_numeric(df.get(col), errors="coerce").fillna(0)
    df["is_leave"] = df.get("is_leave", False).astype(bool)
    # A genuine absence: status marked absent AND not covered by an approved leave.
    df["is_absence"] = ((df["status"] == ABSENT_STATUS) & (~df["is_leave"])).astype(int)
    return df.sort_values(["employee_id", "work_date"]).reset_index(drop=True)


def daily_feature_frame(df: pd.DataFrame) -> pd.DataFrame:
    """One feature row per attendance day (leave days excluded — they aren't anomalies)."""
    if df.empty:
        return df.assign(**{c: [] for c in DAILY_FEATURE_COLUMNS})

    work = df[~df["is_leave"]].copy()
    # Missing punch-in time (e.g. pure absence) → 0 so the matrix is dense; the is_absence
    # flag already carries that signal for the detector.
    work["first_in_minutes"] = work["first_in_minutes"].fillna(0.0)
    for col in DAILY_FEATURE_COLUMNS:
        if col not in work:
            work[col] = 0
    return work.reset_index(drop=True)


def _trend_slope(series: pd.Series) -> float:
    """Simple slope of a 0/1 absence series over time (positive = worsening)."""
    n = len(series)
    if n < 2:
        return 0.0
    x = pd.Series(range(n), dtype=float)
    x_mean = x.mean()
    y_mean = series.mean()
    denom = ((x - x_mean) ** 2).sum()
    if denom == 0:
        return 0.0
    return float(((x - x_mean) * (series.values - y_mean)).sum() / denom)


def employee_feature_frame(df: pd.DataFrame) -> pd.DataFrame:
    """One aggregate feature row per employee for the risk classifier."""
    if df.empty:
        return pd.DataFrame(columns=["employee_id", *EMPLOYEE_FEATURE_COLUMNS])

    rows = []
    for employee_id, group in df.groupby("employee_id"):
        group = group.sort_values("work_date")
        scheduled_days = len(group)
        worked = group[~group["is_leave"]]
        present = worked[worked["is_absence"] == 0]
        absence_rate = float(worked["is_absence"].mean()) if len(worked) else 0.0
        late_rate = float((present["late_minutes"] > 0).mean()) if len(present) else 0.0
        rows.append(
            {
                "employee_id": int(employee_id),
                "absence_rate": absence_rate,
                "late_rate": late_rate,
                "avg_late_minutes": float(present["late_minutes"].mean()) if len(present) else 0.0,
                "worked_minutes_mean": (
                    float(present["worked_minutes"].mean()) if len(present) else 0.0
                ),
                "worked_minutes_std": (
                    float(present["worked_minutes"].std(ddof=0)) if len(present) else 0.0
                ),
                "first_in_std": (
                    float(present["first_in_minutes"].std(ddof=0)) if len(present) else 0.0
                ),
                "trend_slope": _trend_slope(worked["is_absence"]) if len(worked) else 0.0,
                "scheduled_days": scheduled_days,
            }
        )

    frame = pd.DataFrame(rows).fillna(0.0)
    return frame
