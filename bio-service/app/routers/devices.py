from datetime import datetime, timezone

from fastapi import APIRouter, Depends

from ..dependencies import verify_internal_secret
from ..driver_factory import build_driver
from ..schemas import DeviceTestRequest, DeviceTestResponse

_AUTH_RESPONSES = {401: {"description": "Missing or invalid `X-Internal-Secret` header."}}

router = APIRouter(
    prefix="/api/devices",
    tags=["devices"],
    dependencies=[Depends(verify_internal_secret)],
    responses=_AUTH_RESPONSES,
)


@router.post(
    "/test-connection",
    response_model=DeviceTestResponse,
    summary="Live connectivity check for a single device",
)
def test_connection(request: DeviceTestRequest) -> DeviceTestResponse:
    """Builds the right driver for the device and attempts connect()+health(), used by
    yner_main's Biometric Devices "Test Connection" action. Never raises — connection
    failures are reported in the response body, not as HTTP errors."""
    device = request.model_dump()

    try:
        driver = build_driver(device)
    except ValueError as exc:
        return DeviceTestResponse(
            ok=False,
            status="error",
            driver=None,
            error=str(exc),
            checked_at=datetime.now(timezone.utc),
        )

    try:
        driver.connect()
        health = driver.health()
        return DeviceTestResponse(
            ok=True,
            status=health.get("status", "ok"),
            driver=health.get("driver"),
            error=None,
            checked_at=datetime.now(timezone.utc),
        )
    except Exception as exc:
        return DeviceTestResponse(
            ok=False,
            status="error",
            driver=device.get("type"),
            error=str(exc),
            checked_at=datetime.now(timezone.utc),
        )
    finally:
        try:
            driver.disconnect()
        except Exception:
            pass
