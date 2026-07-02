"""External LLM report summarization (Phase 8, proposal feature 6.6.5).

Turns Laravel's aggregated monthly attendance statistics into a human-readable narrative via
the **Claude Messages API**. This is the *external* half of the project's hybrid AI answer
(the internal scikit-learn models are Phase 7).

Guardrails baked in here:

- **Privacy** — the caller (Laravel) only ever sends aggregate counts/rates + a department
  label; nothing in this module reintroduces PII.
- **Graceful fallback** — if ``ANTHROPIC_API_KEY`` is unset, or the API errors/times out, or the
  response can't be parsed, ``summarize`` returns a deterministic **template** narrative with
  ``fallback=True`` instead of raising. The endpoint therefore always returns something usable.
"""

from __future__ import annotations

import json
import logging
import re
from datetime import datetime, timezone

from ..config import settings

logger = logging.getLogger(__name__)

FALLBACK_MODEL = "template-fallback"

SYSTEM_PROMPT = (
    "You are an HR attendance analytics assistant for an Employee Attendance & Permission "
    "Management System. You are given ONLY aggregated, anonymized monthly statistics — never "
    "individual employee names or records. Write a concise, factual management summary. Do not "
    "invent numbers that are not in the data. Respond with STRICT JSON only, no markdown, using "
    'exactly this shape: {"narrative": string (2-4 sentences), "highlights": string[] '
    '(3-5 short bullet strings), "recommendations": string[] (2-4 short actionable bullet '
    "strings)}."
)


def _pct(value: float | None) -> str:
    if value is None:
        return "n/a"
    return f"{round(float(value) * 100)}%"


def _build_user_prompt(stats: dict) -> str:
    lines = [
        f"Period: {stats.get('period_label', 'the month')}",
        f"Scope: {stats.get('scope', 'Organization-wide')}",
        f"Headcount: {stats.get('headcount', 0)}",
        f"Working days: {stats.get('working_days', 0)}",
        f"Attendance rate: {_pct(stats.get('attendance_rate'))}",
        f"Punctuality rate: {_pct(stats.get('punctuality_rate'))}",
        f"Absence rate (unapproved): {_pct(stats.get('absence_rate'))}",
        f"Late incidents: {stats.get('late_incidents', 0)} "
        f"(avg {round(float(stats.get('avg_late_minutes', 0)))} min late)",
        f"Daily status counts: {stats.get('status_counts', {})}",
        f"Approved-leave breakdown: {stats.get('leave_breakdown', {})}",
        f"AI anomalies flagged this month: {stats.get('anomalies_count', 0)}",
        f"Employees currently high absenteeism-risk: {stats.get('high_risk_count', 0)}",
    ]
    prev = stats.get("prev_attendance_rate")
    if prev is not None:
        lines.append(f"Previous month attendance rate: {_pct(prev)}")
    patterns = stats.get("top_patterns") or []
    if patterns:
        lines.append("Notable patterns: " + "; ".join(str(p) for p in patterns))
    lines.append("\nWrite the JSON summary now for HR management decision support.")
    return "\n".join(lines)


def _parse_model_json(text: str) -> dict | None:
    """Best-effort parse of the model's JSON reply (tolerates stray prose / code fences)."""
    text = text.strip()
    try:
        return json.loads(text)
    except json.JSONDecodeError:
        match = re.search(r"\{.*\}", text, re.DOTALL)
        if match:
            try:
                return json.loads(match.group(0))
            except json.JSONDecodeError:
                return None
    return None


def _trend_phrase(stats: dict) -> str:
    prev = stats.get("prev_attendance_rate")
    current = stats.get("attendance_rate")
    if prev is None or current is None:
        return ""
    delta = round((float(current) - float(prev)) * 100)
    if delta > 0:
        return f" Attendance improved by {delta} percentage points versus the previous month."
    if delta < 0:
        return f" Attendance fell by {abs(delta)} percentage points versus the previous month."
    return " Attendance held steady versus the previous month."


def _fallback(stats: dict) -> dict:
    """Deterministic template narrative used whenever Claude is unavailable."""
    scope = stats.get("scope", "Organization-wide")
    period = stats.get("period_label", "this period")
    headcount = stats.get("headcount", 0)
    counts = stats.get("status_counts", {})
    absent = counts.get("absent", 0)

    narrative = (
        f"{scope} recorded a {_pct(stats.get('attendance_rate'))} attendance rate across "
        f"{headcount} employees in {period}, with {_pct(stats.get('punctuality_rate'))} of "
        f"worked days on time. There were {stats.get('late_incidents', 0)} late arrivals and "
        f"{absent} unapproved absences." + _trend_phrase(stats)
    )

    highlights = [
        f"Attendance rate: {_pct(stats.get('attendance_rate'))}",
        f"Punctuality rate: {_pct(stats.get('punctuality_rate'))}",
        f"Late incidents: {stats.get('late_incidents', 0)} "
        f"(avg {round(float(stats.get('avg_late_minutes', 0)))} min)",
        f"Unapproved absences: {absent}",
        f"AI anomalies flagged: {stats.get('anomalies_count', 0)}",
    ]

    recommendations = []
    if stats.get("high_risk_count", 0):
        recommendations.append(
            f"Follow up with {stats['high_risk_count']} employee(s) flagged high absenteeism-risk."
        )
    if stats.get("late_incidents", 0):
        recommendations.append("Review punctuality with teams showing repeated late arrivals.")
    if absent:
        recommendations.append("Confirm unapproved absences are not missing permission records.")
    if not recommendations:
        recommendations.append(
            "Maintain current attendance practices; no critical issues detected."
        )

    return {
        "narrative": narrative,
        "highlights": highlights,
        "recommendations": recommendations,
        "model": FALLBACK_MODEL,
        "fallback": True,
        "generated_at": datetime.now(timezone.utc),
    }


def summarize(stats: dict) -> dict:
    """Return a narrative summary of the aggregated stats. Never raises."""
    if not settings.anthropic_api_key:
        logger.info("ANTHROPIC_API_KEY not set — using template fallback for report summary.")
        return _fallback(stats)

    try:
        import anthropic

        client = anthropic.Anthropic(api_key=settings.anthropic_api_key)
        message = client.messages.create(
            model=settings.claude_model,
            max_tokens=1024,
            system=SYSTEM_PROMPT,
            messages=[{"role": "user", "content": _build_user_prompt(stats)}],
        )
        text = "".join(
            block.text for block in message.content if getattr(block, "type", None) == "text"
        )
        parsed = _parse_model_json(text)
        if not parsed or not parsed.get("narrative"):
            # The model answered but not as parseable JSON — treat the raw text as the narrative.
            narrative = (text or "").strip()
            if not narrative:
                raise ValueError("empty response from Claude")
            parsed = {"narrative": narrative, "highlights": [], "recommendations": []}

        return {
            "narrative": str(parsed.get("narrative", "")).strip(),
            "highlights": [str(h) for h in parsed.get("highlights", [])][:5],
            "recommendations": [str(r) for r in parsed.get("recommendations", [])][:4],
            "model": settings.claude_model,
            "fallback": False,
            "generated_at": datetime.now(timezone.utc),
        }
    except Exception as exc:  # noqa: BLE001 — any API/parse failure must degrade, not crash.
        logger.warning("Claude summarization failed (%s) — using template fallback.", exc)
        return _fallback(stats)
