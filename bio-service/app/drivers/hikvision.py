from datetime import datetime, timezone

import httpx

from .base import BiometricDriver, PunchLog


class HikvisionDriver(BiometricDriver):
    """Hikvision device driver using ISAPI HTTP (Digest auth)."""

    def __init__(self, host: str, username: str, password: str, serial: str = ""):
        self.host = host
        self.username = username
        self.password = password
        self.serial = serial
        self._connected = False

    def _auth(self) -> httpx.DigestAuth:
        return httpx.DigestAuth(self.username, self.password)

    def connect(self) -> None:
        try:
            response = httpx.get(
                f"http://{self.host}/ISAPI/System/deviceInfo",
                auth=self._auth(),
                timeout=5,
            )
            response.raise_for_status()
        except Exception as exc:
            self._connected = False
            raise ConnectionError(
                f"Failed to connect to Hikvision device {self.host}: {exc}"
            ) from exc

        self._connected = True

    def disconnect(self) -> None:
        self._connected = False

    def fetch_logs(self, since: datetime) -> list[PunchLog]:
        if not self._connected:
            raise RuntimeError("Driver not connected")

        end_time = datetime.now(timezone.utc)
        response = httpx.post(
            f"http://{self.host}/ISAPI/AccessControl/AcsEvent?format=json",
            auth=self._auth(),
            json={
                "AcsEventCond": {
                    "searchID": "1",
                    "searchResultPosition": 0,
                    "maxResults": 1000,
                    "major": 5,
                    "minor": 75,
                    "startTime": since.isoformat(),
                    "endTime": end_time.isoformat(),
                }
            },
            timeout=10,
        )
        response.raise_for_status()
        body = response.json()

        try:
            info_list = body["AcsEvent"]["InfoList"]
        except (KeyError, TypeError) as exc:
            raise RuntimeError(f"Unexpected Hikvision ISAPI response shape: {body}") from exc

        logs = []
        for event in info_list:
            try:
                device_user_id = event["employeeNoString"]
                punched_at = datetime.fromisoformat(event["time"])
            except (KeyError, ValueError) as exc:
                raise RuntimeError(f"Unexpected Hikvision event shape: {event}") from exc

            logs.append(
                PunchLog(
                    device_user_id=device_user_id,
                    punched_at=punched_at,
                    device_serial=self.serial,
                )
            )

        return logs

    def health(self) -> dict:
        return {
            "driver": "hikvision",
            "host": self.host,
            "status": "connected" if self._connected else "not_connected",
        }
