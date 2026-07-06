# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Plan record-keeping

Whenever a plan is created (e.g. via plan mode) and approved, write the full plan content to a new markdown file under `prompt/` at the project root **before** starting execution. Name the file descriptively (e.g. `prompt/migrate-blade-to-inertia-react.md`) — do not overwrite previous plan files. This keeps a durable, human-readable history of every plan alongside the code, independent of any session's plan-file cache.

## Project overview

**Employee Attendance & Permission Management System (EAPMS)** — Final Year Project for IFM. Biometric attendance and HR permission/leave management are synchronized so approved absences never show as "Absent" in reports. Adds AI features: anomaly detection, absenteeism prediction (scikit-learn), and natural-language report summaries via an OpenRouter/OpenAI-compatible LLM.

## Three-service architecture

```
yner_main/    — Laravel 12 / PHP 8.2  — core app, business logic, REST API, MySQL (port 8000)
ai-service/   — Python / FastAPI      — scikit-learn ML + LLM report summarization (port 8001)
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
npm install && npm run build        # Inertia/React/Tailwind v4 frontend assets
php artisan migrate --seed          # roles, sample departments/employees, one login per role
php artisan serve                   # dev server on :8000
php artisan test                    # PHPUnit test suite (.env.testing → SQLite in-memory)
./vendor/bin/pint                   # PHP code style
php artisan queue:work              # process queued jobs
php artisan schedule:run            # run scheduler manually
```

Seeded logins (password `password` for all): `admin@eapms.test` (Admin), `hr@eapms.test` (HR Officer), `employee@eapms.test` (Employee).

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

**AI is hybrid:** `ai-service` runs scikit-learn models internally (Isolation Forest for anomaly detection, random forest for risk scores). An external LLM, called through an OpenAI-compatible Chat Completions API (OpenRouter by default; any compatible endpoint works), is used only for prose monthly report summaries — send aggregated stats only, never PII. Provider, model, and API key are Admin-configurable at runtime from the AI Settings page (`/settings/ai` in yner_main, backed by `App\Models\AiSetting`) and sent to `ai-service` per-request via `AiInsightsClient`; `ai-service`'s own `LLM_*` env vars are only a fallback for standalone use. Always implement graceful fallback when no key is configured or the LLM call fails.

**Sync engine is the core innovation (Phase 6):** When HR approves a permission/leave, a Laravel event triggers a sync job that overwrites `attendance_records.status` from Absent/null → the correct approved status. Must be idempotent, retroactive, and fully audit-logged.

**Internal service calls are signed:** All requests from yner_main → Python services and bio-service → yner_main include `X-Internal-Secret` header verified in `app/dependencies.py`.

**Frontend is Inertia.js + React + TypeScript + Tailwind v4 + shadcn/ui, no Bootstrap, no Blade pages:** Controllers return `Inertia::render('page/name', [...])` instead of `view(...)`; pages live in `resources/js/pages/**/*.tsx`, resolved by the `name` string passed to `Inertia::render`. `resources/views/app.blade.php` is the only remaining Blade file — the Inertia root template (`@routes`, `@vite`, `@inertiaHead`/`@inertia`). `resources/css/app.css` is `@import "tailwindcss";` plus a `@theme inline { ... }` block defining shadcn's CSS-variable design tokens (light theme only). shadcn primitives are hand-written under `resources/js/components/ui/` (not installed via `npx shadcn add`). Route URLs are generated client-side via Ziggy's `route()` helper (`tightenco/ziggy` + `ziggy-js`, exposed through the `@routes` Blade directive). Authorization that used to live in Blade `@can` directives is now computed server-side and shared as `can.*` props via `App\Http\Middleware\HandleInertiaRequests` (registered in `bootstrap/app.php`'s `withMiddleware()`), read in `resources/js/components/app-sidebar.tsx`. Per-page action flags (e.g. row-level create/update/delete buttons) are computed in each controller's index/show method and passed as a `can` prop — Department/WorkSchedule policies are Admin-only for create/update/delete, Employee policies are Admin+HR for create/update and Admin-only for delete. Multi-form pages (profile edit) use Inertia's `errorBag` option on the client (`useForm().put(url, { errorBag: 'updatePassword' })`) to match the server's `validateWithBag('updatePassword', ...)`/`validateWithBag('userDeletion', ...)` calls, so each form's validation errors surface independently instead of bleeding into other forms on the same page.

**Auth is Laravel Breeze (React/Inertia stack), RBAC is spatie/laravel-permission:** Three roles — `Admin`, `HR Officer`, `Employee` — defined as the `App\Enums\RoleName` backed enum (always reference roles through this enum, not raw strings). `User` uses the `HasRoles` trait. Policies (`DepartmentPolicy`, `EmployeePolicy`, `WorkSchedulePolicy`) gate CRUD: Admin manages everything, HR Officer can view/manage employees but not departments/work-schedules, Employee can only view their own linked `Employee` record. **Controllers must call `$this->authorize(...)` explicitly in each method** — do not use `authorizeResource()`/`$this->middleware()` in a controller constructor; Laravel 12's minimal skeleton `Controller` base class doesn't extend `Illuminate\Routing\Controller`, so those calls fail with "Call to undefined method ...::middleware()".

**Dashboard is role-routed:** `DashboardController@index` (route `/dashboard`) inspects the authenticated user's role and returns one of the Inertia components `dashboard/admin`, `dashboard/hr`, or `dashboard/employee` (under `resources/js/pages/dashboard/`). The sidebar (`resources/js/components/app-sidebar.tsx`) shows management links conditioned on the shared `can.view*` props, so it adapts automatically as policies change.

## Data model highlights

**Implemented (Phase 2):**
- `departments` — name, description; `employees` belong to a department
- `work_schedules` — name, start/end time, grace period minutes
- `employees` — HR record (`employee_code`, name, phone, hire date, status); optionally linked 1:1 to a `users` row via nullable `user_id` (an employee may exist before they have a login, or never get one)
- Spatie `roles` / `model_has_roles` tables — see `RoleSeeder` and `OrgSeeder` for the seed data shape

**Planned (later phases):**
- `device_enrollments` ↔ `biometric_devices` — maps `device_user_id` to employee
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

## LLM usage (ai-service)

Report summaries call an OpenAI-compatible Chat Completions endpoint (`{base_url}/chat/completions`) — OpenRouter by default (e.g. `anthropic/claude-sonnet-4.5`, `openai/gpt-4o`, `google/gemini-2.5-pro`), or any other OpenAI-compatible provider by changing the base URL. Provider/model/API key are set from yner_main's AI Settings page, not hardcoded. Send only aggregated monthly stats (headcounts, rates, patterns) — never employee names or raw records. Cache summaries in `report_summaries` to avoid redundant calls.
