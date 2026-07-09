"""One-shot end-to-end demo: drive the REAL StubDriver + pusher against a live yner_main.

Proves the biometric pipeline without physical hardware. The only values customised here
are the device serial and enrolled user-ids (COM5 / EMP-000x) so the deterministic stub
punches resolve to real seeded employees — exactly what a real device supplies in production.

Run with the bio-service venv while `php artisan serve` is up on :8000:
    LARAVEL_BASE_URL=http://127.0.0.1:8000 venv/Scripts/python.exe demo_poll.py
"""
import asyncio
from datetime import datetime, timezone

from app.drivers.stub import StubDriver
from app.services.pusher import push_logs
from app.config import settings


async def main() -> None:
    print(f"[demo] target Laravel : {settings.laravel_base_url}")
    print(f"[demo] internal secret: {settings.internal_api_secret!r}")

    # Real driver, real interface. Serial + user-ids match seeded enrollments on device COM5.
    driver = StubDriver(serial="COM5", user_ids=["EMP-0001", "EMP-0002", "EMP-0003"])

    driver.connect()
    print(f"[demo] driver.health(): {driver.health()}")

    since = datetime.now(timezone.utc).replace(hour=0, minute=0, second=0, microsecond=0)
    logs = driver.fetch_logs(since)
    driver.disconnect()

    print(f"[demo] StubDriver.fetch_logs() produced {len(logs)} punches:")
    for log in logs:
        print(f"        {log.device_serial} | {log.device_user_id} @ {log.punched_at.isoformat()}")

    result = await push_logs(logs)
    print(f"[demo] pusher.push_logs() -> ingest response: {result}")


if __name__ == "__main__":
    asyncio.run(main())
