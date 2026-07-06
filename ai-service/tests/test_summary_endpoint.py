from fastapi.testclient import TestClient

from app.config import settings
from app.llm import summarizer
from main import app

client = TestClient(app)
AUTH = {"X-Internal-Secret": settings.internal_api_secret}

STATS = {
    "period_label": "June 2026",
    "scope": "Organization-wide",
    "headcount": 24,
    "working_days": 21,
    "status_counts": {"present": 400, "late": 60, "absent": 20},
    "leave_breakdown": {"sick_leave": 12, "official_leave": 8},
    "attendance_rate": 0.92,
    "punctuality_rate": 0.87,
    "absence_rate": 0.04,
    "late_incidents": 60,
    "avg_late_minutes": 14.5,
    "anomalies_count": 5,
    "high_risk_count": 2,
    "prev_attendance_rate": 0.88,
}


def test_summary_requires_secret():
    resp = client.post("/api/analysis/summary", json=STATS)
    assert resp.status_code in (403, 422)


def test_summary_rejects_wrong_secret():
    resp = client.post("/api/analysis/summary", json=STATS, headers={"X-Internal-Secret": "nope"})
    assert resp.status_code == 403


def test_summary_falls_back_without_api_key(monkeypatch):
    monkeypatch.setattr(settings, "llm_api_key", "")
    resp = client.post("/api/analysis/summary", json=STATS, headers=AUTH)
    assert resp.status_code == 200
    body = resp.json()
    assert body["fallback"] is True
    assert body["model"] == "template-fallback"
    assert body["narrative"]  # non-empty
    assert len(body["highlights"]) >= 1
    assert "92%" in body["narrative"]  # attendance rate rendered
    # improvement over previous month (0.88 -> 0.92) is narrated
    assert "improved" in body["narrative"]


def test_summary_parses_llm_json(monkeypatch):
    """When the LLM returns valid JSON, it is parsed into the structured response."""

    canned = {
        "narrative": "Attendance was strong this month.",
        "highlights": ["92% attendance", "Punctuality up"],
        "recommendations": ["Keep monitoring late arrivals"],
    }

    def fake_summarize(stats):
        from datetime import datetime, timezone

        return {
            **canned,
            "model": "anthropic/claude-sonnet-4.5",
            "fallback": False,
            "generated_at": datetime.now(timezone.utc),
        }

    monkeypatch.setattr(summarizer, "summarize", fake_summarize)
    resp = client.post("/api/analysis/summary", json=STATS, headers=AUTH)
    assert resp.status_code == 200
    body = resp.json()
    assert body["fallback"] is False
    assert body["narrative"] == canned["narrative"]
    assert body["highlights"] == canned["highlights"]
    assert body["recommendations"] == canned["recommendations"]


def test_summarizer_unit_fallback_is_deterministic():
    a = summarizer._fallback(STATS)
    b = summarizer._fallback(STATS)
    assert a["narrative"] == b["narrative"]
    assert a["fallback"] is True
