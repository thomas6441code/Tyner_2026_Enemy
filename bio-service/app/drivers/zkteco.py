from datetime import datetime
from .base import BiometricDriver, PunchLog


class ZKTecoDriver(BiometricDriver):
    """ZKTeco device driver using pyzk over TCP/IP."""

    def __init__(self, host: str, port: int = 4370, serial: str = ""):
        self.host = host
        self.port = port
        self.serial = serial
        self._conn = None

    def connect(self) -> None:
        # Phase 3: import zk; self._conn = ZK(self.host, self.port).connect()
        raise NotImplementedError("ZKTeco driver — implemented in Phase 3")

    def disconnect(self) -> None:
        if self._conn:
            self._conn.disconnect()

    def fetch_logs(self, since: datetime) -> list[PunchLog]:
        raise NotImplementedError("ZKTeco driver — implemented in Phase 3")

    def health(self) -> dict:
        return {"driver": "zkteco", "host": self.host, "status": "not_connected"}
