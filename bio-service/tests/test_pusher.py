from datetime import datetime
from unittest.mock import AsyncMock, MagicMock, patch

from app.drivers.base import PunchLog
from app.services.pusher import push_logs


async def test_push_logs_sends_expected_payload_and_header():
    logs = [
        PunchLog(
            device_user_id="U001",
            punched_at=datetime(2026, 1, 1, 8, 0),
            device_serial="STUB-001",
        ),
    ]

    mock_response = MagicMock()
    mock_response.raise_for_status.return_value = None
    mock_response.json.return_value = {"status": "ok", "stored": 1, "duplicates": 0}

    mock_client = MagicMock()
    mock_client.post = AsyncMock(return_value=mock_response)
    mock_client.__aenter__ = AsyncMock(return_value=mock_client)
    mock_client.__aexit__ = AsyncMock(return_value=False)

    with patch("httpx.AsyncClient", return_value=mock_client):
        result = await push_logs(logs)

    assert result == {"status": "ok", "stored": 1, "duplicates": 0}

    _, kwargs = mock_client.post.call_args
    assert kwargs["headers"]["X-Internal-Secret"]
    assert kwargs["json"]["logs"][0]["device_user_id"] == "U001"
    assert kwargs["json"]["logs"][0]["device_serial"] == "STUB-001"
