"""Privacy guardrail: the AI service is aggregates-only, never PII (Phase 11).

CLAUDE.md and the Phase 8 design promise that nothing sent to the external Claude API — or
into the internal ML pipeline — carries employee names or per-person identifiers. These tests
turn that promise into an enforced contract at both boundaries: the request **schemas** and the
LLM **prompt builder**.
"""

from __future__ import annotations

from app.llm import summarizer
from app.schemas import AttendanceRecordIn, SummaryRequest

# Field/token shapes that would indicate a PII leak.
PII_KEYS = {"name", "full_name", "employee_name", "email", "phone", "employee_code", "national_id"}
PII_TOKENS = ["Jane", "Doe", "jane@", "employee_name", "national_id", "+2557", "EMP-"]


def test_summary_request_schema_carries_no_pii_fields():
    fields = set(SummaryRequest.model_fields.keys())
    leaked = fields & PII_KEYS
    assert leaked == set(), f"SummaryRequest exposes PII-shaped fields: {leaked}"


def test_attendance_record_schema_is_id_and_metrics_only():
    """The ML ingest row identifies employees by opaque id + numeric/date features only."""
    fields = set(AttendanceRecordIn.model_fields.keys())
    assert "employee_id" in fields
    assert fields & PII_KEYS == set(), f"AttendanceRecordIn exposes PII fields: {fields & PII_KEYS}"


def test_prompt_builder_ignores_injected_pii():
    """Even if a caller wrongly mixes PII into the stats dict, only whitelisted aggregate
    keys reach the prompt — the builder never echoes unknown keys."""
    stats = {
        "period_label": "June 2026",
        "scope": "Engineering department",
        "headcount": 24,
        "working_days": 21,
        "attendance_rate": 0.92,
        "status_counts": {"present": 400, "absent": 20},
        # Rogue PII that must NOT appear in the outbound prompt:
        "employee_name": "Jane Doe",
        "email": "jane@example.com",
        "phone": "+255700000000",
        "records": [{"employee_code": "EMP-001", "first_in": "2026-06-01T08:00:00"}],
    }

    prompt = summarizer._build_user_prompt(stats)

    for token in PII_TOKENS:
        assert token not in prompt, f"PII token leaked into Claude prompt: {token!r}"
    # Sanity: the aggregate content is present.
    assert "Attendance rate: 92%" in prompt
    assert "Engineering department" in prompt


def test_system_prompt_states_the_privacy_contract():
    assert "never" in summarizer.SYSTEM_PROMPT.lower()
    assert "name" in summarizer.SYSTEM_PROMPT.lower()


def test_prompt_builder_ignores_llm_config_overrides():
    """provider/model/api_key/base_url are per-request LLM config, not aggregate stats —
    they must never be echoed into the outbound prompt."""
    stats = {
        "period_label": "June 2026",
        "scope": "Engineering department",
        "attendance_rate": 0.92,
        "provider": "openrouter",
        "model": "anthropic/claude-sonnet-4.5",
        "api_key": "sk-or-super-secret-value",
        "base_url": "https://openrouter.ai/api/v1",
    }

    prompt = summarizer._build_user_prompt(stats)

    assert "sk-or-super-secret-value" not in prompt
    assert "openrouter" not in prompt.lower()
