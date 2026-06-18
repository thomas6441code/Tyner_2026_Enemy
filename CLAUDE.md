# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

**Employee Attendance & Permission Management System (EAPMS)** — Final Year Project for IFM. Biometric attendance and HR permission/leave management are synchronized so approved absences never show as "Absent" in reports. Adds AI features: anomaly detection, absenteeism prediction (scikit-learn), and natural-language report summaries via Claude API.

## Three-service architecture

```
yner_main/    — Laravel 12 / PHP 8.2  — core app, business logic, REST API, MySQL (port 8000)
ai-service/   — Python / FastAPI      — scikit-learn ML + Claude API summarization  (port 8001)
bio-service/  — Python / FastAPI      — ZKTeco/Hikvision biometric adapter          (port 8002)
```

Orchestrated with Docker Compose. **yner_main** is the single source of truth; both Python services are called by yner_main over signed REST (`X-Internal-Secret` header). Starting yner_main also starts the Python services:

```powershell
.\start.ps1                         # Windows — starts all three
cd yner_main && php artisan eapms:start    # same, from yner_main directly
cd yner_main && php artisan eapms:status   # health dashboard (--watch for live refresh)
```

## Development commands

### yner_main (Laravel)
```bash
cd yner_main
cp .env.example .env && php artisan key:generate
composer install
php artisan migrate --seed
php artisan serve                   # dev server on :8000
php artisan test                    # PHPUnit test suite (.env.testing → SQLite in-memory)
./vendor/bin/pint                   # PHP code style
php artisan queue:work              # process queued jobs
php artisan schedule:run            # run scheduler manually
```

### Python services (ai-service / bio-service)
```bash
cd ai-service           # or bio-service
python -m venv venv && venv\Scripts\activate   # Windows
pip install -r requirements.txt
uvicorn main:app --reload --port 8001          # 8002 for bio-service
pytest                                          # test suite
ruff check . && black --check .                # lint + format check
```

### Docker (full stack)
```bash
docker compose up --build
docker compose down
```

## Key architectural decisions

**yner_main is the orchestrator:** The two Python services are started from `php artisan eapms:start`, which launches them as background processes (writing to `yner_main/storage/logs/services/`) and then starts Laravel in the foreground. `eapms:status` checks their `/health` endpoints.

**Biometric adapter is device-agnostic:** `BiometricDriver` abstract class in `bio-service/app/drivers/base.py` with `ZKTecoDriver`, `HikvisionDriver`, and `StubDriver` implementations. During dev, `StubDriver` generates deterministic test punches. Real drivers are implemented in Phase 3.

**AI is hybrid:** `ai-service` runs scikit-learn models internally (Isolation Forest for anomaly detection, random forest for risk scores). External Claude API is used only for prose monthly report summaries — send aggregated stats only, never PII. Always implement graceful fallback when Claude API is unavailable.

**Sync engine is the core innovation (Phase 6):** When HR approves a permission/leave, a Laravel event triggers a sync job that overwrites `attendance_records.status` from Absent/null → the correct approved status. Must be idempotent, retroactive, and fully audit-logged.

**Internal service calls are signed:** All requests from yner_main → Python services and bio-service → yner_main include `X-Internal-Secret` header verified in `app/dependencies.py`.

## Data model highlights

- `employees` ↔ `device_enrollments` ↔ `biometric_devices` — maps `device_user_id` to employee
- `raw_attendance_logs` — immutable punch events from devices
- `attendance_records` — daily computed record per employee; `status` is overwritten by sync engine on permission approval
- `permission_requests` — HR approval workflow; approval event triggers attendance resync
- `attendance_status_types` — Present, Late, Absent, Official Leave, Sick Leave, Permission Approved, Field Duty
- `ai_anomalies` / `ai_predictions` — persisted ML batch results
- `report_summaries` — LLM narrative, cached per month/department

## Testing

- **Laravel (PHPUnit):** `php artisan test` — uses `.env.testing` with in-memory SQLite. Feature tests use `RefreshDatabase`. Critical-path test: seed employee with no punch → approve permission → assert `attendance_records.status` updated.
- **Python (pytest):** `pytest` in each service dir. Includes `StubDriver` tests and FastAPI health endpoint tests.
- **CI:** `.github/workflows/ci.yml` — three parallel jobs, all must pass before merging.

## Claude API usage (ai-service)

Use `claude-sonnet-4-6` or newer. Send only aggregated monthly stats (headcounts, rates, patterns) — never employee names or raw records. Cache summaries in `report_summaries` to avoid redundant calls.
