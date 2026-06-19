import httpx

from ..config import settings
from ..drivers.base import PunchLog


async def push_logs(logs: list[PunchLog]) -> dict:
    """POST normalized punch logs to Laravel ingestion endpoint."""
    payload = [
        {
            "device_user_id": log.device_user_id,
            "punched_at": log.punched_at.isoformat(),
            "device_serial": log.device_serial,
        }
        for log in logs
    ]
    async with httpx.AsyncClient() as client:
        response = await client.post(
            f"{settings.laravel_base_url}/api/biometric/ingest",
            json={"logs": payload},
            headers={"X-Internal-Secret": settings.internal_api_secret},
            timeout=30,
        )
        response.raise_for_status()
        return response.json()
