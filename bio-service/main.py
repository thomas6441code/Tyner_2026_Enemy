from fastapi import FastAPI

from app.routers import health
from app.services.scheduler import start_scheduler

app = FastAPI(title="IFM Biometric Adapter", version="0.1.0")

app.include_router(health.router)

scheduler = None


@app.on_event("startup")
def on_startup() -> None:
    global scheduler
    scheduler = start_scheduler()


@app.on_event("shutdown")
def on_shutdown() -> None:
    if scheduler is not None:
        scheduler.shutdown(wait=False)
