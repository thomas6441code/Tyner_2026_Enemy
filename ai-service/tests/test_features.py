from datetime import date, datetime

from app.ml.features import (
    daily_feature_frame,
    employee_feature_frame,
    records_to_frame,
)


def _record(
    employee_id, work_date, status="present", first_in="08:00", late=0, worked=480, is_leave=False
):
    first = None
    if first_in is not None:
        h, m = first_in.split(":")
        first = datetime(work_date.year, work_date.month, work_date.day, int(h), int(m))
    return {
        "employee_id": employee_id,
        "work_date": work_date,
        "status": status,
        "first_in": first,
        "last_out": None,
        "worked_minutes": worked,
        "late_minutes": late,
        "early_leave_minutes": 0,
        "is_leave": is_leave,
    }


def test_absence_excludes_approved_leave():
    records = [
        _record(1, date(2026, 6, 1), status="absent", first_in=None, worked=0),
        _record(
            1, date(2026, 6, 2), status="official_leave", first_in=None, worked=0, is_leave=True
        ),
    ]
    df = records_to_frame(records)
    # Real absence counts once; the leave day is not an absence.
    assert df["is_absence"].tolist() == [1, 0]


def test_daily_feature_frame_drops_leave_rows():
    records = [
        _record(1, date(2026, 6, 1)),
        _record(1, date(2026, 6, 2), status="sick_leave", is_leave=True),
    ]
    daily = daily_feature_frame(records_to_frame(records))
    assert len(daily) == 1


def test_employee_features_rates():
    records = [
        _record(1, date(2026, 6, 1), late=30),
        _record(1, date(2026, 6, 2), status="absent", first_in=None, worked=0),
        _record(1, date(2026, 6, 3)),
        _record(1, date(2026, 6, 4)),
    ]
    feats = employee_feature_frame(records_to_frame(records)).iloc[0]
    assert feats["employee_id"] == 1
    assert 0.24 < feats["absence_rate"] < 0.26  # 1 of 4 days
    assert feats["late_rate"] > 0
