from datetime import datetime
from .base import BiometricDriver, PunchLog


class HikvisionDriver(BiometricDriver):
    """Hikvision device driver using ISAPI HTTP."""

    def __init__(self, host: str, username: str, password: str, serial: str = ""):
        self.host = host
        self.username = username
        self.password = password
        self.serial = serial

    def connect(self) -> None:
        # Phase 3: verify ISAPI /ISAPI/System/deviceInfo reachable
        raise NotImplementedError("Hikvision driver — implemented in Phase 3")

    def disconnect(self) -> None:
        pass

    def fetch_logs(self, since: datetime) -> list[PunchLog]:
        raise NotImplementedError("Hikvision driver — implemented in Phase 3")

    def health(self) -> dict:
        return {"driver": "hikvision", "host": self.host, "status": "not_connected"}
