from datetime import datetime, timezone
from unittest.mock import MagicMock, patch

import httpx
import pytest

from app.drivers.hikvision import HikvisionDriver


def test_connect_success():
    driver = HikvisionDriver(host="10.0.0.6", username="admin", password="secret", serial="HIK-001")

    mock_response = MagicMock()
    mock_response.raise_for_status.return_value = None

    with patch("httpx.get", return_value=mock_response) as mock_get:
        driver.connect()

    mock_get.assert_called_once()
    assert driver._connected is True
    assert driver.health()["status"] == "connected"


def test_connect_failure_raises_connection_error():
    driver = HikvisionDriver(host="10.0.0.6", username="admin", password="secret", serial="HIK-001")

    with patch("httpx.get", side_effect=httpx.ConnectError("refused")):
        with pytest.raises(ConnectionError):
            driver.connect()

    assert driver._connected is False
    assert driver.health()["status"] == "not_connected"


def test_fetch_logs_parses_info_list():
    driver = HikvisionDriver(host="10.0.0.6", username="admin", password="secret", serial="HIK-001")
    driver._connected = True

    mock_response = MagicMock()
    mock_response.raise_for_status.return_value = None
    mock_response.json.return_value = {
        "AcsEvent": {
            "InfoList": [
                {"employeeNoString": "1001", "time": "2026-01-02T08:00:00+00:00"},
                {"employeeNoString": "1002", "time": "2026-01-02T17:00:00+00:00"},
            ]
        }
    }

    with patch("httpx.post", return_value=mock_response):
        logs = driver.fetch_logs(since=datetime(2026, 1, 2, tzinfo=timezone.utc))

    assert len(logs) == 2
    assert logs[0].device_user_id == "1001"
    assert logs[0].device_serial == "HIK-001"
    assert logs[1].device_user_id == "1002"


def test_fetch_logs_without_connection_raises():
    driver = HikvisionDriver(host="10.0.0.6", username="admin", password="secret", serial="HIK-001")

    with pytest.raises(RuntimeError):
        driver.fetch_logs(since=datetime(2026, 1, 1, tzinfo=timezone.utc))


def test_fetch_logs_malformed_response_raises():
    driver = HikvisionDriver(host="10.0.0.6", username="admin", password="secret", serial="HIK-001")
    driver._connected = True

    mock_response = MagicMock()
    mock_response.raise_for_status.return_value = None
    mock_response.json.return_value = {"unexpected": "shape"}

    with patch("httpx.post", return_value=mock_response):
        with pytest.raises(RuntimeError):
            driver.fetch_logs(since=datetime(2026, 1, 2, tzinfo=timezone.utc))
