from abc import ABC, abstractmethod
from dataclasses import dataclass
from datetime import datetime


@dataclass
class PunchLog:
    device_user_id: str
    punched_at: datetime
    device_serial: str


class BiometricDriver(ABC):
    """Pluggable interface for all biometric device vendors."""

    @abstractmethod
    def connect(self) -> None: ...

    @abstractmethod
    def disconnect(self) -> None: ...

    @abstractmethod
    def fetch_logs(self, since: datetime) -> list[PunchLog]: ...

    @abstractmethod
    def health(self) -> dict: ...
