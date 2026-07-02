from datetime import date, datetime, timedelta

from app.ml import prediction


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


def test_prediction_shape_and_levels():
    start = date(2026, 4, 1)
    records = []
    for i in range(40):
        records.append(_record(1, start + timedelta(days=i)))  # reliable
        records.append(
            _record(
                2,
                start + timedelta(days=i),  # unreliable
                status="absent" if i % 2 else "present",
                first_in=None if i % 2 else "09:30",
                late=0 if i % 2 else 90,
                worked=0 if i % 2 else 400,
            )
        )

    result = prediction.predict(records, as_of=start + timedelta(days=40))
    assert {"predictions", "model"} <= result.keys()
    by_emp = {p["employee_id"]: p for p in result["predictions"]}
    assert set(by_emp) == {1, 2}
    for p in result["predictions"]:
        assert 0.0 <= p["risk_score"] <= 1.0
        assert p["risk_level"] in {"low", "medium", "high"}
    # The unreliable employee should carry more risk than the reliable one.
    assert by_emp[2]["risk_score"] >= by_emp[1]["risk_score"]


def test_small_data_uses_fallback():
    records = [_record(1, date(2026, 6, 1)), _record(1, date(2026, 6, 2))]
    result = prediction.predict(records, as_of=date(2026, 6, 3))
    assert result["model"]["fallback"] is True
    assert len(result["predictions"]) == 1


def test_deterministic():
    start = date(2026, 4, 1)
    records = [_record(1, start + timedelta(days=i), late=i % 20) for i in range(30)]
    a = prediction.predict(records, as_of=start + timedelta(days=30))
    b = prediction.predict(records, as_of=start + timedelta(days=30))
    assert a == b
