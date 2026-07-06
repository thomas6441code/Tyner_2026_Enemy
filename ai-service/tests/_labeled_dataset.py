"""Deterministic labeled attendance samples for ML evaluation (Phase 11).

These builders produce attendance records in the exact shape the AI service consumes
(``employee_id``, ``work_date``, ``status``, ``first_in``, ``worked_minutes``,
``late_minutes``, ``early_leave_minutes``, ``is_leave``) **together with ground-truth
labels**, so ``anomaly.detect`` and ``prediction.predict`` can be scored with
precision / recall / F1.

Everything is seeded (``random.Random(seed)``) — no unseeded randomness — so the metrics
are reproducible run-to-run and in CI.
"""

from __future__ import annotations

import random
from datetime import date, timedelta

# Grace-normal sign-in is ~08:00; the working day is 480 min.
NORMAL_FIRST_IN_MIN = 8 * 60
NORMAL_WORKED_MIN = 480


def _iso_first_in(day: date, minutes_since_midnight: int) -> str:
    hour, minute = divmod(minutes_since_midnight, 60)
    return f"{day.isoformat()}T{hour:02d}:{minute:02d}:00"


def anomaly_sample(
    n_employees: int = 6,
    days: int = 25,
    seed: int = 42,
) -> tuple[list[dict], set[tuple[int, str]]]:
    """Mostly-normal attendance with a known set of injected anomalies.

    Two anomalies are injected per employee at fixed indices: an **unexplained absence**
    and an **extreme-late / off-hours sign-in** day. Injected fraction ≈ 8%, matching the
    detector's ``CONTAMINATION`` prior.

    Returns ``(records, truth)`` where ``truth`` is the set of ``(employee_id, iso_date)``
    pairs that are genuine anomalies.
    """
    rng = random.Random(seed)
    start = date(2026, 4, 1)
    records: list[dict] = []
    truth: set[tuple[int, str]] = set()

    absence_idx, late_idx = 7, 18  # fixed → reproducible ground truth
    for emp in range(1, n_employees + 1):
        for i in range(days):
            day = start + timedelta(days=i)
            iso = day.isoformat()

            if i == absence_idx:
                # Unexplained absence: no punch, not covered by leave.
                records.append(
                    {
                        "employee_id": emp,
                        "work_date": iso,
                        "status": "absent",
                        "first_in": None,
                        "worked_minutes": 0,
                        "late_minutes": 0,
                        "early_leave_minutes": 0,
                        "is_leave": False,
                    }
                )
                truth.add((emp, iso))
                continue

            if i == late_idx:
                # Extreme lateness / off-hours sign-in.
                first_in = 11 * 60 + rng.randint(0, 30)  # ~11:00–11:30
                records.append(
                    {
                        "employee_id": emp,
                        "work_date": iso,
                        "status": "present",
                        "first_in": _iso_first_in(day, first_in),
                        "worked_minutes": 300,
                        "late_minutes": first_in - NORMAL_FIRST_IN_MIN,
                        "early_leave_minutes": 0,
                        "is_leave": False,
                    }
                )
                truth.add((emp, iso))
                continue

            # Normal day: small natural jitter around 08:00 / 480 min, on time.
            first_in = NORMAL_FIRST_IN_MIN + rng.randint(-4, 5)
            late = max(0, first_in - (NORMAL_FIRST_IN_MIN + 5))
            records.append(
                {
                    "employee_id": emp,
                    "work_date": iso,
                    "status": "present",
                    "first_in": _iso_first_in(day, first_in),
                    "worked_minutes": NORMAL_WORKED_MIN + rng.randint(-10, 10),
                    "late_minutes": late,
                    "early_leave_minutes": 0,
                    "is_leave": False,
                }
            )

    return records, truth


def risk_sample(
    n_per_class: int = 12,
    as_of: date = date(2026, 6, 1),
    history_days: int = 92,
    seed: int = 42,
) -> tuple[list[dict], dict[int, int]]:
    """Employees with clearly high-risk vs low-risk attendance patterns.

    High-risk employees carry a high absence rate and frequent lateness across the whole
    window (so their outcome window also looks bad, giving the self-supervised trainer a
    positive label); low-risk employees are near-perfect. Ground truth is the intended
    class per employee.

    Returns ``(records, truth)`` where ``truth`` maps ``employee_id -> 1`` (high risk)
    or ``0`` (low risk).
    """
    rng = random.Random(seed)
    start = as_of - timedelta(days=history_days)
    records: list[dict] = []
    truth: dict[int, int] = {}

    def emit(emp: int, absent_prob: float, late_choices: list[int]) -> None:
        for i in range(history_days):
            day = start + timedelta(days=i)
            iso = day.isoformat()
            if rng.random() < absent_prob:
                records.append(
                    {
                        "employee_id": emp,
                        "work_date": iso,
                        "status": "absent",
                        "first_in": None,
                        "worked_minutes": 0,
                        "late_minutes": 0,
                        "early_leave_minutes": 0,
                        "is_leave": False,
                    }
                )
                continue
            late = rng.choice(late_choices)
            first_in = NORMAL_FIRST_IN_MIN + late
            records.append(
                {
                    "employee_id": emp,
                    "work_date": iso,
                    "status": "present",
                    "first_in": _iso_first_in(day, first_in),
                    "worked_minutes": NORMAL_WORKED_MIN - late,
                    "late_minutes": late,
                    "early_leave_minutes": 0,
                    "is_leave": False,
                }
            )

    emp = 1
    for _ in range(n_per_class):  # high risk
        emit(emp, absent_prob=0.40, late_choices=[0, 15, 30, 45])
        truth[emp] = 1
        emp += 1
    for _ in range(n_per_class):  # low risk
        emit(emp, absent_prob=0.02, late_choices=[0, 0, 0, 5])
        truth[emp] = 0
        emp += 1

    return records, truth
