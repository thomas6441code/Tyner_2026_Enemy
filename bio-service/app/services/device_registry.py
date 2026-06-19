import httpx

from ..config import settings


async def fetch_active_devices() -> list[dict]:
    """GET the active biometric device registry from Laravel."""
    async with httpx.AsyncClient() as client:
        response = await client.get(
            f"{settings.laravel_base_url}/api/biometric/devices",
            headers={"X-Internal-Secret": settings.internal_api_secret},
            timeout=10,
        )
        response.raise_for_status()
        return response.json()["devices"]
