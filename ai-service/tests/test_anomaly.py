from datetime import date, datetime, timedelta

from app.ml import anomaly


def _record(
    employee_id, work_date, first_in="08:00", late=0, worked=480, status="present", is_leave=False
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


def test_injected_outlier_is_flagged():
    start = date(2026, 5, 1)
    records = [_record(1, start + timedelta(days=i)) for i in range(15)]
    # One wildly late day for employee 1.
    outlier_date = start + timedelta(days=15)
    records.append(_record(1, outlier_date, first_in="11:30", late=210, worked=180))
    # A second employee with steady normal behaviour.
    records += [_record(2, start + timedelta(days=i)) for i in range(16)]

    anomalies = anomaly.detect(records)
    flagged = {(a["employee_id"], a["work_date"]) for a in anomalies}
    assert (1, outlier_date) in flagged


def test_empty_input_returns_no_anomalies():
    assert anomaly.detect([]) == []


def test_clean_history_is_stable():
    start = date(2026, 5, 1)
    records = [_record(1, start + timedelta(days=i)) for i in range(20)]
    # Identical, well-behaved days should not produce z-score outliers (std == 0).
    zscore = [a for a in anomaly.detect(records) if a["method"] == "zscore"]
    assert zscore == []
