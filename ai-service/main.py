from fastapi import FastAPI

from app.routers import analysis, health

DESCRIPTION = """
Internal AI microservice for the **Employee Attendance & Permission Management System (EAPMS)**.

Called only by `yner_main` (Laravel) over signed REST. Every request under `/api/**` must carry a
valid `X-Internal-Secret` header — see the **Authentication** notes on each operation.

### Capabilities
* **Anomaly detection** — Isolation Forest + z-score over attendance history.
* **Absenteeism / lateness prediction** — RandomForest risk scoring per employee.
* **Report summarization** — Claude API narration over *aggregated* monthly stats.

### Privacy
Payloads carry only the internal `employee_id` plus numeric/date features and aggregated
counts — never employee names or any other PII.
"""

tags_metadata = [
    {"name": "health", "description": "Liveness probe used by `eapms:status` and Docker health checks."},
    {
        "name": "analysis",
        "description": "ML scoring and LLM summarization endpoints. All require the "
        "`X-Internal-Secret` header.",
    },
]

app = FastAPI(
    title="EAPMS AI Service",
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
app.include_router(analysis.router)
