"""Pydantic request/response contracts for the Laravel ↔ AI-service REST boundary.

Laravel assembles the attendance dataset and POSTs it here; the service is stateless and
returns scored results. Payloads carry only the internal ``employee_id`` plus numeric/date
features — never employee names or any other PII.
"""

from __future__ import annotations

from datetime import date, datetime
from typing import Optional

from pydantic import BaseModel, Field


class AttendanceRecordIn(BaseModel):
    """One computed daily attendance row, as stored in Laravel ``attendance_records``."""

    employee_id: int
    work_date: date
    status: str
    first_in: Optional[datetime] = None
    last_out: Optional[datetime] = None
    worked_minutes: Optional[int] = None
    late_minutes: int = 0
    early_leave_minutes: int = 0
    is_leave: bool = False
    schedule_start: Optional[str] = None  # "HH:MM" expected sign-in
    schedule_end: Optional[str] = None  # "HH:MM" expected sign-out


class AnomalyRequest(BaseModel):
    records: list[AttendanceRecordIn] = Field(default_factory=list)


class Anomaly(BaseModel):
    employee_id: int
    work_date: date
    method: str  # "isolation_forest" | "zscore"
    score: float  # higher = more anomalous
    explanation: str
    features: dict[str, float] = Field(default_factory=dict)


class AnomalyMeta(BaseModel):
    count: int
    method: str
    generated_at: datetime


class AnomalyResponse(BaseModel):
    anomalies: list[Anomaly] = Field(default_factory=list)
    meta: AnomalyMeta


class PredictionRequest(BaseModel):
    records: list[AttendanceRecordIn] = Field(default_factory=list)
    as_of: Optional[date] = None


class TopFactor(BaseModel):
    feature: str
    value: float
    importance: float


class Prediction(BaseModel):
    employee_id: int
    risk_score: float  # probability in [0, 1]
    risk_level: str  # "low" | "medium" | "high"
    top_factors: list[TopFactor] = Field(default_factory=list)


class ModelInfo(BaseModel):
    version: str
    feature_importances: dict[str, float] = Field(default_factory=dict)
    metrics: dict[str, float] = Field(default_factory=dict)
    fallback: bool = False


class PredictionResponse(BaseModel):
    predictions: list[Prediction] = Field(default_factory=list)
    model: ModelInfo
