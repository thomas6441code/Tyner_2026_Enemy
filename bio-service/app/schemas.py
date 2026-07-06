from datetime import datetime

from pydantic import BaseModel


class DeviceTestRequest(BaseModel):
    type: str
    serial: str = ""
    host: str | None = None
    port: int | None = None
    username: str | None = None
    password: str | None = None


class DeviceTestResponse(BaseModel):
    ok: bool
    status: str
    driver: str | None = None
    error: str | None = None
    checked_at: datetime
