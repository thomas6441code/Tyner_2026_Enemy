from datetime import datetime
from unittest.mock import MagicMock, patch

import pytest

from app.drivers.zkteco import ZKTecoDriver


def make_record(user_id: str, timestamp: datetime):
    record = MagicMock()
    record.user_id = user_id
    record.timestamp = timestamp
    return record


def test_connect_success():
    driver = ZKTecoDriver(host="10.0.0.5", serial="ZK-001")

    mock_conn = MagicMock()
    mock_zk_instance = MagicMock()
    mock_zk_instance.connect.return_value = mock_conn

    with patch("zk.ZK", return_value=mock_zk_instance):
        driver.connect()

    assert driver._conn is mock_conn
    assert driver.health()["status"] == "connected"


def test_connect_failure_raises_connection_error():
    driver = ZKTecoDriver(host="10.0.0.5", serial="ZK-001")

    mock_zk_instance = MagicMock()
    mock_zk_instance.connect.side_effect = Exception("timeout")

    with patch("zk.ZK", return_value=mock_zk_instance):
        with pytest.raises(ConnectionError):
            driver.connect()

    assert driver._conn is None
    assert driver.health()["status"] == "not_connected"


def test_fetch_logs_maps_and_filters_by_since():
    driver = ZKTecoDriver(host="10.0.0.5", serial="ZK-001")
    driver._conn = MagicMock()
    driver._conn.get_attendance.return_value = [
        make_record("U001", datetime(2026, 1, 1, 8, 0)),
        make_record("U002", datetime(2026, 1, 2, 8, 0)),
    ]

    logs = driver.fetch_logs(since=datetime(2026, 1, 2, 0, 0))

    assert len(logs) == 1
    assert logs[0].device_user_id == "U002"
    assert logs[0].device_serial == "ZK-001"
    assert logs[0].punched_at == datetime(2026, 1, 2, 8, 0)


def test_fetch_logs_without_connection_raises():
    driver = ZKTecoDriver(host="10.0.0.5", serial="ZK-001")

    with pytest.raises(RuntimeError):
        driver.fetch_logs(since=datetime(2026, 1, 1))
