from .drivers.base import BiometricDriver
from .drivers.hikvision import HikvisionDriver
from .drivers.stub import StubDriver
from .drivers.zkteco import ZKTecoDriver


def build_driver(device: dict) -> BiometricDriver:
    """Construct the right BiometricDriver implementation for a device registry entry."""
    device_type = device["type"]
    serial = device.get("serial", "")

    if device_type == "stub":
        return StubDriver(serial=serial)

    if device_type == "zkteco":
        return ZKTecoDriver(
            host=device["host"],
            port=device.get("port") or 4370,
            serial=serial,
        )

    if device_type == "hikvision":
        return HikvisionDriver(
            host=device["host"],
            username=device.get("username") or "",
            password=device.get("password") or "",
            serial=serial,
        )

    raise ValueError(f"Unknown device type: {device_type}")
