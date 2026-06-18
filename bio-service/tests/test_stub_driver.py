from datetime import datetime
from app.drivers.stub import StubDriver


def test_stub_returns_logs():
    driver = StubDriver(user_ids=["U001", "U002"])
    driver.connect()
    logs = driver.fetch_logs(since=datetime(2026, 1, 1, 0, 0, 0))
    assert len(logs) == 4  # 2 sign-in + 2 sign-out
    assert all(log.device_serial == "STUB-001" for log in logs)


def test_stub_health():
    driver = StubDriver()
    assert driver.health()["status"] == "ok"
