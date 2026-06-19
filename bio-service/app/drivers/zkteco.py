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
        from zk import ZK

        try:
            self._conn = ZK(self.host, port=self.port, timeout=5).connect()
        except Exception as exc:
            self._conn = None
            raise ConnectionError(
                f"Failed to connect to ZKTeco device {self.host}:{self.port}: {exc}"
            ) from exc

    def disconnect(self) -> None:
        if self._conn:
            self._conn.disconnect()
            self._conn = None

    def fetch_logs(self, since: datetime) -> list[PunchLog]:
        if self._conn is None:
            raise RuntimeError("Driver not connected")

        records = self._conn.get_attendance()
        return [
            PunchLog(
                device_user_id=str(record.user_id),
                punched_at=record.timestamp,
                device_serial=self.serial,
            )
            for record in records
            if record.timestamp >= since
        ]

    def health(self) -> dict:
        return {
            "driver": "zkteco",
            "host": self.host,
            "status": "connected" if self._conn is not None else "not_connected",
        }
