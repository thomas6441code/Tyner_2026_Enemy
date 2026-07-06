from fastapi import APIRouter
from pydantic import BaseModel

router = APIRouter(tags=["health"])


class HealthResponse(BaseModel):
    status: str = "ok"
    service: str = "ai-service"


@router.get(
    "/health",
    response_model=HealthResponse,
    summary="Liveness probe",
    description="Returns `{status: ok}` when the service is up. No authentication required.",
)
def health() -> HealthResponse:
    return HealthResponse(status="ok", service="ai-service")
