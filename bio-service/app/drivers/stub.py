from datetime import datetime, timedelta
import random
from .base import BiometricDriver, PunchLog


class StubDriver(BiometricDriver):
    """Deterministic stub — stands in for real hardware during development and CI."""

    def __init__(self, serial: str = "STUB-001", user_ids: list[str] | None = None):
        self.serial = serial
        self.user_ids = user_ids or ["U001", "U002", "U003"]

    def connect(self) -> None:
        pass

    def disconnect(self) -> None:
        pass

    def fetch_logs(self, since: datetime) -> list[PunchLog]:
        logs = []
        day = since.replace(hour=0, minute=0, second=0)
        for uid in self.user_ids:
            sign_in = day.replace(hour=8, minute=random.randint(0, 30))
            sign_out = day.replace(hour=17, minute=random.randint(0, 30))
            if sign_in >= since:
                logs.append(PunchLog(device_user_id=uid, punched_at=sign_in, device_serial=self.serial))
            if sign_out >= since:
                logs.append(PunchLog(device_user_id=uid, punched_at=sign_out, device_serial=self.serial))
        return logs

    def health(self) -> dict:
        return {"driver": "stub", "serial": self.serial, "status": "ok"}
