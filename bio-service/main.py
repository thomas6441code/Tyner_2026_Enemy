from fastapi import FastAPI

from app.routers import devices, health
from app.services.scheduler import start_scheduler

DESCRIPTION = """
Device-agnostic **biometric adapter** for the Employee Attendance & Permission Management
System (EAPMS).

This service runs a background scheduler that polls enrolled ZKTeco / Hikvision devices (or a
deterministic `StubDriver` in development), normalizes the punch events, and **pushes** them to
`yner_main` (Laravel) at `POST /api/biometric/ingest` using the signed `X-Internal-Secret`
header. It is push-oriented, so its own HTTP surface is intentionally minimal.

### Driver abstraction
`BiometricDriver` (see `app/drivers/base.py`) with `ZKTecoDriver`, `HikvisionDriver` and
`StubDriver` implementations, selected by `driver_factory`.
"""

tags_metadata = [
    {
        "name": "health",
        "description": "Liveness probe used by `eapms:status` and Docker health checks.",
    },
    {
        "name": "devices",
        "description": "On-demand per-device actions requested by yner_main (Test Connection).",
    },
]

app = FastAPI(
    title="EAPMS Biometric Adapter",
    version="0.1.0",
    description=DESCRIPTION,
    openapi_tags=tags_metadata,
    contact={"name": "EAPMS", "url": "http://localhost:8000"},
    license_info={"name": "Proprietary — IFM Final Year Project"},
    docs_url="/docs",
    redoc_url="/redoc",
    openapi_url="/openapi.json",
)

app.include_router(health.router)
app.include_router(devices.router)

scheduler = None


@app.on_event("startup")
def on_startup() -> None:
    global scheduler
    scheduler = start_scheduler()


@app.on_event("shutdown")
def on_shutdown() -> None:
    if scheduler is not None:
        scheduler.shutdown(wait=False)
