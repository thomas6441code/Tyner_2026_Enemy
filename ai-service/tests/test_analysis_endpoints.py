from datetime import date, timedelta

from fastapi.testclient import TestClient

from app.config import settings
from main import app

client = TestClient(app)
AUTH = {"X-Internal-Secret": settings.internal_api_secret}


def _payload_records(n=20):
    start = date(2026, 4, 1)
    records = []
    for i in range(n):
        d = (start + timedelta(days=i)).isoformat()
        records.append(
            {
                "employee_id": 1,
                "work_date": d,
                "status": "present",
                "first_in": f"{d}T08:0{i % 6}:00",
                "worked_minutes": 480,
                "late_minutes": 0,
                "early_leave_minutes": 0,
                "is_leave": False,
            }
        )
    return records


def test_anomalies_requires_secret():
    resp = client.post("/api/analysis/anomalies", json={"records": []})
    assert resp.status_code == 422 or resp.status_code == 403


def test_anomalies_rejects_wrong_secret():
    resp = client.post(
        "/api/analysis/anomalies", json={"records": []}, headers={"X-Internal-Secret": "nope"}
    )
    assert resp.status_code == 403


def test_anomalies_happy_path():
    resp = client.post(
        "/api/analysis/anomalies", json={"records": _payload_records()}, headers=AUTH
    )
    assert resp.status_code == 200
    body = resp.json()
    assert "anomalies" in body
    assert body["meta"]["method"] == "isolation_forest+zscore"


def test_predictions_happy_path():
    resp = client.post(
        "/api/analysis/predictions",
        json={"records": _payload_records(), "as_of": "2026-05-01"},
        headers=AUTH,
    )
    assert resp.status_code == 200
    body = resp.json()
    assert "predictions" in body
    assert "feature_importances" in body["model"]
