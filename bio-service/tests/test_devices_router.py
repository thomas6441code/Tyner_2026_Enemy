from unittest.mock import MagicMock, patch

from fastapi.testclient import TestClient

from app.config import settings
from main import app

client = TestClient(app)
HEADERS = {"X-Internal-Secret": settings.internal_api_secret}


def test_requires_internal_secret():
    response = client.post(
        "/api/devices/test-connection",
        json={"type": "stub", "serial": "STUB-001"},
    )
    assert response.status_code == 422


def test_rejects_wrong_secret():
    response = client.post(
        "/api/devices/test-connection",
        json={"type": "stub", "serial": "STUB-001"},
        headers={"X-Internal-Secret": "wrong"},
    )
    assert response.status_code == 403


def test_stub_device_reports_ok():
    response = client.post(
        "/api/devices/test-connection",
        json={"type": "stub", "serial": "STUB-001"},
        headers=HEADERS,
    )
    assert response.status_code == 200
    body = response.json()
    assert body["ok"] is True
    assert body["driver"] == "stub"
    assert body["error"] is None


def test_unreachable_device_reports_failure():
    unreachable = MagicMock()
    unreachable.connect.side_effect = ConnectionError(
        "Failed to connect to ZKTeco device 10.0.0.5:4370: timed out"
    )
    unreachable.disconnect.return_value = None

    with patch("app.routers.devices.build_driver", return_value=unreachable):
        response = client.post(
            "/api/devices/test-connection",
            json={"type": "zkteco", "serial": "ZK-001", "host": "10.0.0.5", "port": 4370},
            headers=HEADERS,
        )

    assert response.status_code == 200
    body = response.json()
    assert body["ok"] is False
    assert "timed out" in body["error"]


def test_unknown_device_type_reports_failure():
    response = client.post(
        "/api/devices/test-connection",
        json={"type": "unknown", "serial": "X"},
        headers=HEADERS,
    )
    assert response.status_code == 200
    body = response.json()
    assert body["ok"] is False
    assert "Unknown device type" in body["error"]
