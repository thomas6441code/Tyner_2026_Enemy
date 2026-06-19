import logging
from datetime import datetime, timedelta, timezone

from apscheduler.schedulers.asyncio import AsyncIOScheduler

from ..config import settings
from ..driver_factory import build_driver
from .device_registry import fetch_active_devices
from .pusher import push_logs

logger = logging.getLogger(__name__)

_last_poll: dict[int, datetime] = {}


async def poll_job() -> None:
    """Poll every active device for new punches and push them to Laravel."""
    try:
        devices = await fetch_active_devices()
    except Exception:
        logger.exception("Failed to fetch device registry from Laravel; skipping this poll cycle")
        return

    for device in devices:
        device_id = device["id"]
        since = _last_poll.get(device_id, datetime.now(timezone.utc) - timedelta(hours=24))

        try:
            driver = build_driver(device)
            driver.connect()
            try:
                logs = driver.fetch_logs(since)
            finally:
                driver.disconnect()

            if logs:
                await push_logs(logs)

            _last_poll[device_id] = datetime.now(timezone.utc)
        except Exception:
            logger.exception("Poll failed for device %s (%s)", device_id, device.get("serial"))


def start_scheduler() -> AsyncIOScheduler:
    scheduler = AsyncIOScheduler()
    scheduler.add_job(poll_job, "interval", seconds=settings.poll_interval_seconds)
    scheduler.start()
    return scheduler
