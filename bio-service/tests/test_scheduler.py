from datetime import datetime
from unittest.mock import AsyncMock, MagicMock, patch

from app.drivers.base import PunchLog
from app.services import scheduler


async def test_poll_job_skips_cycle_when_registry_unavailable():
    scheduler._last_poll.clear()

    with patch(
        "app.services.scheduler.fetch_active_devices",
        AsyncMock(side_effect=Exception("down")),
    ):
        await scheduler.poll_job()

    assert scheduler._last_poll == {}


async def test_poll_job_isolates_failing_device_and_updates_last_poll_only_on_success():
    scheduler._last_poll.clear()

    devices = [
        {"id": 1, "type": "stub", "serial": "STUB-FAIL"},
        {"id": 2, "type": "stub", "serial": "STUB-OK"},
    ]

    failing_driver = MagicMock()
    failing_driver.connect.side_effect = ConnectionError("device offline")

    ok_driver = MagicMock()
    ok_driver.connect.return_value = None
    ok_driver.fetch_logs.return_value = [
        PunchLog(
            device_user_id="U001",
            punched_at=datetime(2026, 1, 1, 8, 0),
            device_serial="STUB-OK",
        ),
    ]
    ok_driver.disconnect.return_value = None

    def build_driver_side_effect(device):
        return failing_driver if device["id"] == 1 else ok_driver

    mock_push_logs = AsyncMock(return_value={"status": "ok", "stored": 1, "duplicates": 0})

    with (
        patch("app.services.scheduler.fetch_active_devices", AsyncMock(return_value=devices)),
        patch("app.services.scheduler.build_driver", side_effect=build_driver_side_effect),
        patch("app.services.scheduler.push_logs", mock_push_logs),
    ):
        await scheduler.poll_job()

    assert 1 not in scheduler._last_poll
    assert 2 in scheduler._last_poll
    mock_push_logs.assert_awaited_once()
    ok_driver.disconnect.assert_called_once()
