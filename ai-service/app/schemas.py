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


class SummaryRequest(BaseModel):
    """Aggregated monthly statistics for LLM narration (6.6.5).

    Privacy guardrail: this carries only counts, rates and labels — never employee names or
    per-person rows. ``scope`` is at most a department name, never an individual.
    """

    period_label: str  # e.g. "June 2026"
    scope: str = "Organization-wide"  # e.g. "Engineering department"
    headcount: int = 0
    working_days: int = 0
    status_counts: dict[str, int] = Field(default_factory=dict)
    leave_breakdown: dict[str, int] = Field(default_factory=dict)
    attendance_rate: float = 0.0  # present+late+leave / expected, in [0, 1]
    punctuality_rate: float = 0.0  # on-time / worked, in [0, 1]
    absence_rate: float = 0.0  # unapproved absences / expected, in [0, 1]
    late_incidents: int = 0
    avg_late_minutes: float = 0.0
    anomalies_count: int = 0
    high_risk_count: int = 0
    prev_attendance_rate: Optional[float] = None
    top_patterns: list[str] = Field(default_factory=list)

    # Per-request LLM overrides sent by yner_main's AI Settings page (Admin-configurable
    # provider/model/key). Not part of the aggregated stats — never echoed into the prompt.
    provider: Optional[str] = None
    model: Optional[str] = None
    api_key: Optional[str] = None
    base_url: Optional[str] = None


class SummaryResponse(BaseModel):
    narrative: str
    highlights: list[str] = Field(default_factory=list)
    recommendations: list[str] = Field(default_factory=list)
    model: str
    fallback: bool = False
    generated_at: datetime
