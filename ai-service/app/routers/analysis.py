from datetime import datetime, timezone

from fastapi import APIRouter, Depends

from ..dependencies import verify_internal_secret
from ..llm import summarizer
from ..ml import anomaly, prediction
from ..schemas import (
    AnomalyRequest,
    AnomalyResponse,
    PredictionRequest,
    PredictionResponse,
    SummaryRequest,
    SummaryResponse,
)

router = APIRouter(
    prefix="/api/analysis", tags=["analysis"], dependencies=[Depends(verify_internal_secret)]
)


@router.post("/anomalies", response_model=AnomalyResponse)
def detect_anomalies(request: AnomalyRequest) -> AnomalyResponse:
    """Isolation Forest + z-score anomaly detection over attendance history (6.6.2)."""
    records = [r.model_dump() for r in request.records]
    anomalies = anomaly.detect(records)
    return AnomalyResponse(
        anomalies=anomalies,
        meta={
            "count": len(anomalies),
            "method": "isolation_forest+zscore",
            "generated_at": datetime.now(timezone.utc),
        },
    )


@router.post("/predictions", response_model=PredictionResponse)
def predict_risk(request: PredictionRequest) -> PredictionResponse:
    """RandomForest absenteeism/lateness risk score per employee (6.6.3)."""
    records = [r.model_dump() for r in request.records]
    result = prediction.predict(records, as_of=request.as_of)
    return PredictionResponse(**result)


@router.post("/summary", response_model=SummaryResponse)
def generate_summary(request: SummaryRequest) -> SummaryResponse:
    """Claude API monthly report summarization over aggregated stats (6.6.5).

    Aggregates only — no PII. Degrades to a deterministic template summary when the Claude API
    key is unset or the call fails (``fallback: true``).
    """
    result = summarizer.summarize(request.model_dump())
    return SummaryResponse(**result)
