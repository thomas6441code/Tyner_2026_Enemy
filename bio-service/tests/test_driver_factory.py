import pytest

from app.driver_factory import build_driver
from app.drivers.hikvision import HikvisionDriver
from app.drivers.stub import StubDriver
from app.drivers.zkteco import ZKTecoDriver


def test_build_stub_driver():
    driver = build_driver({"id": 1, "type": "stub", "serial": "STUB-001"})
    assert isinstance(driver, StubDriver)
    assert driver.serial == "STUB-001"


def test_build_zkteco_driver():
    driver = build_driver(
        {"id": 2, "type": "zkteco", "serial": "ZK-001", "host": "10.0.0.5", "port": 4370}
    )
    assert isinstance(driver, ZKTecoDriver)
    assert driver.host == "10.0.0.5"
    assert driver.port == 4370


def test_build_hikvision_driver():
    driver = build_driver(
        {
            "id": 3,
            "type": "hikvision",
            "serial": "HIK-001",
            "host": "10.0.0.6",
            "username": "admin",
            "password": "secret",
        }
    )
    assert isinstance(driver, HikvisionDriver)
    assert driver.host == "10.0.0.6"
    assert driver.username == "admin"


def test_build_unknown_type_raises():
    with pytest.raises(ValueError):
        build_driver({"id": 4, "type": "unknown", "serial": "X"})
