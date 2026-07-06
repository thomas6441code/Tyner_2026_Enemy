from fastapi.testclient import TestClient

from app.config import settings
from app.llm import summarizer
from main import app

client = TestClient(app)
AUTH = {"X-Internal-Secret": settings.internal_api_secret}

CONFIG = {
    "provider": "openrouter",
    "base_url": "https://openrouter.ai/api/v1",
    "model": "anthropic/claude-sonnet-4.5",
    "api_key": "sk-or-test-value",
}


def test_llm_test_requires_secret():
    resp = client.post("/api/analysis/llm-test", json=CONFIG)
    assert resp.status_code in (403, 422)


def test_llm_test_rejects_wrong_secret():
    resp = client.post("/api/analysis/llm-test", json=CONFIG, headers={"X-Internal-Secret": "nope"})
    assert resp.status_code == 403


def test_llm_test_reports_missing_api_key(monkeypatch):
    monkeypatch.setattr(settings, "llm_api_key", "")
    payload = {**CONFIG, "api_key": None}
    resp = client.post("/api/analysis/llm-test", json=payload, headers=AUTH)
    assert resp.status_code == 200
    body = resp.json()
    assert body["ok"] is False
    assert body["latency_ms"] == 0
    assert "API key" in body["error"]


def test_llm_test_reports_success(monkeypatch):
    def fake_test_connection(overrides):
        from datetime import datetime, timezone

        return {
            "ok": True,
            "provider": overrides["provider"],
            "model": overrides["model"],
            "base_url": overrides["base_url"],
            "latency_ms": 123,
            "reply": "OK",
            "error": None,
            "generated_at": datetime.now(timezone.utc),
        }

    monkeypatch.setattr(summarizer, "test_connection", fake_test_connection)
    resp = client.post("/api/analysis/llm-test", json=CONFIG, headers=AUTH)
    assert resp.status_code == 200
    body = resp.json()
    assert body["ok"] is True
    assert body["reply"] == "OK"
    assert body["latency_ms"] == 123
    # the API key itself is never echoed back
    assert "api_key" not in body
    assert CONFIG["api_key"] not in resp.text


def test_llm_test_reports_failure(monkeypatch):
    def fake_test_connection(overrides):
        from datetime import datetime, timezone

        return {
            "ok": False,
            "provider": overrides["provider"],
            "model": overrides["model"],
            "base_url": overrides["base_url"],
            "latency_ms": 50,
            "reply": None,
            "error": "HTTP 401: invalid api key",
            "generated_at": datetime.now(timezone.utc),
        }

    monkeypatch.setattr(summarizer, "test_connection", fake_test_connection)
    resp = client.post("/api/analysis/llm-test", json=CONFIG, headers=AUTH)
    assert resp.status_code == 200
    body = resp.json()
    assert body["ok"] is False
    assert "invalid api key" in body["error"]
