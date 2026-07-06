# Internal Service REST Contracts

These endpoints are **not** for browser/end-user use — they're the signed, machine-to-machine
boundary between the three services. Every request must carry:

```
X-Internal-Secret: <INTERNAL_API_SECRET>
```

using the **same** `INTERNAL_API_SECRET` value configured on both sides (see
[`../runbook.md`](../runbook.md) for the env var reference). A missing/incorrect header gets:

- **Laravel side** (`App\Http\Middleware\VerifyInternalSecret`, alias `verify.internal-secret`,
  applied to the `routes/api.php` group): `403 Forbidden — "Invalid internal secret."`
- **ai-service side** (`verify_internal_secret` dependency in `app/dependencies.py`, applied to
  the whole `/api/analysis` router): `403 Forbidden — {"detail": "Invalid internal secret"}`

No PII (employee names, raw biometric templates) crosses any of these boundaries — payloads
carry internal numeric `employee_id`s, dates, and aggregated numbers only.

---

## bio-service → yner_main

### `POST /api/biometric/ingest`

Called by bio-service's scheduled poller after normalizing punches from a device driver
(`BiometricIngestController::store`).

**Request**
```json
{
  "logs": [
    { "device_user_id": "1042", "punched_at": "2026-07-06T08:03:12", "device_serial": "ZK-001-A1" }
  ]
}
```
`logs` is required; each entry requires `device_user_id` (string), `punched_at` (date/datetime),
`device_serial` (string).

**Behavior**
- Inserts rows into `raw_attendance_logs` with `insertOrIgnore`, deduped on the unique key
  `(device_serial, device_user_id, punched_at)` — safe to re-send the same batch.
- Dispatches `ComputeAttendanceForDate` for every distinct `punched_at` date in the batch, so
  attendance recomputes immediately rather than waiting for the 01:00 schedule.

**Response `200`**
```json
{ "status": "ok", "stored": 3, "duplicates": 1 }
```

### `GET /api/biometric/devices`

Called by bio-service to refresh its poll registry (`BiometricDeviceListController::index`).

**Response `200`**
```json
{
  "devices": [
    { "id": 1, "name": "Main Gate", "type": "zkteco", "serial": "ZK-001-A1",
      "host": "10.0.0.5", "port": 4370, "username": null, "password": null }
  ]
}
```
Only devices with `status = active` are returned. `password` is the stored device credential
(nullable) — sensitive, internal-only.

---

## yner_main → ai-service

Base URL: `config('services.ai.url')` (env `AI_SERVICE_URL`). Client:
`App\Services\AiInsightsClient`. All three calls degrade to `null` on timeout/non-2xx/network
error (logged as a warning) — callers (`ScoreAttendance` command, `ReportSummaryController`)
treat `null` as "AI unavailable" and never crash.

### `POST /api/analysis/anomalies`

Isolation Forest + per-employee z-score anomaly detection (6.6.2).

**Request** (`AnomalyRequest`)
```json
{ "records": [ { "employee_id": 12, "work_date": "2026-07-01", "status": "present",
    "first_in": "2026-07-01T08:42:00", "last_out": "2026-07-01T17:05:00",
    "worked_minutes": 503, "late_minutes": 12, "early_leave_minutes": 0,
    "is_leave": false, "schedule_start": "08:00", "schedule_end": "17:00" } ] }
```

**Response** (`AnomalyResponse`)
```json
{ "anomalies": [ { "employee_id": 12, "work_date": "2026-07-01", "method": "zscore",
    "score": 3.4, "explanation": "sign-in of 08:42 is 3.4σ from this employee's average of 08:05",
    "features": { "late_minutes": 12.0 } } ],
  "meta": { "count": 1, "method": "isolation_forest+zscore", "generated_at": "2026-07-06T02:00:01Z" } }
```

### `POST /api/analysis/predictions`

RandomForest absenteeism/lateness risk score per employee (6.6.3).

**Request** (`PredictionRequest`) — same `records` shape as above, plus optional `as_of` (date).

**Response** (`PredictionResponse`)
```json
{ "predictions": [ { "employee_id": 12, "risk_score": 0.62, "risk_level": "medium",
    "top_factors": [ { "feature": "absence_rate", "value": 0.18, "importance": 0.41 } ] } ],
  "model": { "version": "rf-2026.07", "feature_importances": { "absence_rate": 0.41 },
    "metrics": { "accuracy": 0.83, "precision": 0.75, "recall": 1.0 }, "fallback": false } }
```
When there's too little history / a single class, `model.fallback = true` and `risk_score` is
the rule-based weighted score (`0.45·absence_rate + 0.25·late_rate + 0.30·trend_slope`).

### `POST /api/analysis/summary`

Claude-powered monthly narrative (6.6.5) — see `docs/ai/explainability.md` for the full
prompt/caching design. **Aggregates only**: the request body (`SummaryRequest`) never contains
employee names or per-person rows.

**Request**
```json
{ "period_label": "June 2026", "scope": "Engineering department", "headcount": 24,
  "working_days": 21, "status_counts": { "present": 420, "late": 38, "absent": 12 },
  "leave_breakdown": { "official_leave": 6, "sick_leave": 4 },
  "attendance_rate": 0.94, "punctuality_rate": 0.91, "absence_rate": 0.02,
  "late_incidents": 38, "avg_late_minutes": 14.2, "anomalies_count": 3, "high_risk_count": 1,
  "prev_attendance_rate": 0.92, "top_patterns": ["Late arrivals cluster on Mondays"] }
```

**Response** (`SummaryResponse`)
```json
{ "narrative": "Engineering maintained strong attendance in June...",
  "highlights": ["94% attendance rate, up from 92% in May"],
  "recommendations": ["Review Monday-morning scheduling for repeat latecomers"],
  "model": "claude-sonnet-4-6", "fallback": false, "generated_at": "2026-07-06T10:00:00Z" }
```
`fallback: true` means `ANTHROPIC_API_KEY` was unset or the Claude call failed — the narrative
is then a deterministic template built from the same numbers, never a crash.

---

## Health checks (both Python services)

### `GET /health` — no authentication required

```json
{ "status": "ok", "service": "ai-service" }
```
(`bio-service` returns the analogous `{"status": "ok", "service": "bio-service"}`.) Polled by
`php artisan eapms:status` to render the live health dashboard.
