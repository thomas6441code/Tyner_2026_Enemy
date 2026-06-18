# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

AI-Enhanced Employee Attendance & Permission Management System for the **Institute of Finance Management (IFM)** — a Final Year Project. The core problem: biometric attendance and HR permission/leave systems run independently, causing approved absences to show as "Absent" in reports. This system synchronizes them and adds AI features (anomaly detection, absenteeism prediction, report summarization via Claude API).

## Three-service architecture

```
biometric-adapter/ — Python/FastAPI, pulls punch logs from ZKTeco/Hikvision devices
laravel-app/       — PHP/Laravel, core web app + business logic + REST API + MySQL
ai-service/        — Python/FastAPI, scikit-learn ML + Claude API for report summaries
```

Orchestrated with Docker Compose. Laravel is the single source of truth; the two Python services are called by Laravel over signed REST.

## Development commands

### Laravel app
```bash
cd laravel-app
cp .env.example .env && php artisan key:generate
composer install
php artisan migrate --seed
php artisan serve               # dev server
php artisan test                # Pest/PHPUnit test suite
./vendor/bin/pint               # PHP code style (PHP-CS-Fixer/Pint)
php artisan queue:work          # process queued jobs (notifications, sync jobs)
php artisan schedule:run        # run scheduler manually
```

### Python services (ai-service / biometric-adapter)
```bash
cd ai-service           # or biometric-adapter
python -m venv venv && source venv/bin/activate  # or venv\Scripts\activate on Windows
pip install -r requirements.txt
uvicorn main:app --reload       # dev server
pytest                          # test suite
ruff check . && black --check . # lint + format check
```

### Docker (full stack)
```bash
docker compose up --build       # start all three services
docker compose down
```

## Key architectural decisions

**Biometric adapter is device-agnostic:** `BiometricDriver` interface with pluggable implementations — `ZKTecoDriver` (`pyzk` over TCP/IP) and `HikvisionDriver` (ISAPI). During dev, a stub/simulator stands in for real hardware. The adapter pulls attendance logs (timestamps + device-user-id), not raw fingerprints.

**AI is hybrid:** Internal `scikit-learn` models (Isolation Forest for anomaly detection, logistic/random-forest classifier for absenteeism risk scores) run entirely in `ai-service`. External **Claude API** is used only for prose monthly report summarization — send aggregated stats only, never PII. Always implement a graceful fallback when the Claude API is unavailable.

**Sync engine is the core innovation (Phase 6):** When HR approves a permission/leave, a Laravel event triggers a resync job that overwrites `attendance_records.status` from Absent/null → the correct approved status (Official Leave, Sick Leave, Permission Approved, Field Duty). This must be idempotent and retroactive. Every status change is audit-logged.

**Internal service calls are signed:** Laravel → AI Service and Biometric Adapter → Laravel use a shared secret in a request header to prevent unauthenticated calls.

## Data model highlights

Critical tables and their relationships:
- `employees` ↔ `device_enrollments` ↔ `biometric_devices` — maps `device_user_id` to an employee
- `raw_attendance_logs` — immutable punch events from devices
- `attendance_records` — daily computed record per employee (status, first_in, last_out, hours, late_minutes); status is overwritten by the sync engine on permission approval
- `permission_requests` — HR approval workflow; approval event triggers attendance resync
- `attendance_status_types` — includes: Present, Late, Absent, Official Leave, Sick Leave, Permission Approved, Field Duty
- `ai_anomalies` / `ai_predictions` — persisted ML outputs (batch scored by scheduler)
- `report_summaries` — LLM narrative output, cached per month/department

## Testing strategy

- **Laravel (Pest):** unit tests for attendance computation logic and sync engine; feature tests for the full approval→sync→report flow. The sync correctness test is the critical path: seed an employee with no punch → approve a permission → assert `attendance_records.status` updated.
- **Python (pytest):** unit tests for each biometric driver (against stubs), ML pipeline, and FastAPI endpoints. Include ML evaluation metrics (precision/recall) documented in test output.
- **CI:** lint (`pint`, `ruff`/`black`) + tests must be green before merging.

## Claude API usage (ai-service)

Use `claude-sonnet-4-6` or newer. Send only aggregated monthly stats (headcounts, rates, top patterns) — never employee names or raw records. Cache summaries in `report_summaries` to avoid redundant API calls. The prompt should produce: narrative paragraph, key highlights, and recommendations.
