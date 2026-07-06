# Phase 12 — Documentation

## Context

Phases 0–11 are functionally complete (auth/RBAC, biometric ingest, attendance engine, HR
permissions, the sync engine, internal ML, Claude summarization, notifications, reporting,
and QA). What's missing is the documentation layer the FYP proposal and defense require:
`docs/design/` is still an empty placeholder (Phase 1 diagrams were never produced), there's
no consolidated API reference, and there's no user-facing manual or ops runbook. Phase 12
closes all of that so `/docs` is complete, per `development_plan.md`.

Per user decision: this phase produces **supporting documentation only** (no academic
chapter prose) but **does** backfill the Phase 1 design diagrams as Mermaid diagrams inside
`docs/design/`, since the "finalized diagrams" deliverable depends on them and they were
never created.

## Deliverables

### 1. `docs/design/` — system design (backfills Phase 1)
- `docs/design/README.md` — index + short requirements recap (functional/non-functional,
  pulled from `development_plan.md` objectives 1–6 and 6.6.1–6.6.6).
- `docs/design/use-case-diagram.md` — Mermaid use-case-style diagram, actors Employee / HR
  Officer / Admin, derived from actual routes (`routes/web.php`) and policies
  (`DepartmentPolicy`, `EmployeePolicy`, `WorkSchedulePolicy`).
- `docs/design/erd.md` — Mermaid `erDiagram` built from the real migrations (18 tables:
  users/roles, departments, work_schedules, employees, biometric_devices,
  device_enrollments, raw_attendance_logs, attendance_records (+ permission_request_id link),
  permission_requests, audit_logs, notifications, ai_anomalies, ai_predictions,
  report_summaries).
- `docs/design/dfd.md` — Level 0 (context) and Level 1 Mermaid flowcharts: biometric
  terminals → bio-service → yner_main → MySQL, plus the ai-service branch (Claude +
  scikit-learn).
- `docs/design/flowcharts.md` — two Mermaid flowcharts: (a) attendance computation
  (raw punch → pairing → status derivation, `ComputeAttendance` command /
  attendance engine), (b) approval → sync (`PermissionRequestApproved` event →
  `SyncAttendanceForApprovedPermission` listener → `AttendanceSyncService` →
  `attendance_records.status` overwrite), matching Phase 6's actual implementation.
- `docs/design/architecture.md` — Mermaid diagram of the 3-service architecture (already
  described in README/CLAUDE.md) with the signed `X-Internal-Secret` REST boundary called
  out, plus the tech-stack justification table already in `development_plan.md` (copied in,
  not re-derived).

### 2. `docs/api/` — API reference
- `docs/api/laravel.md` — every route in `routes/web.php` + `routes/auth.php` grouped by
  resource (Departments, Work Schedules, Employees, Permission Requests + review/attachment,
  Biometric Devices, Device Enrollments, Attendance, AI Insights, Reports (+ CSV/PDF export),
  Report Summaries, Notifications, Profile, Dashboard), each with method, path, auth
  middleware, controller@method, and the Inertia page or redirect it produces. Sourced from
  actual controllers (`app/Http/Controllers/*`) not guessed.
- `docs/api/internal-rest.md` — the two internal REST contracts:
  - `yner_main` ⇄ `bio-service`: `POST /api/biometric/ingest`, `GET /api/biometric/devices`
    (both behind `verify.internal-secret`), request/response shapes from
    `BiometricIngestController` / `BiometricDeviceListController`.
  - `yner_main` ⇄ `ai-service`: `POST /api/analysis/anomalies`, `/predictions`, `/summary`
    (from `ai-service/app/routers/analysis.py` + `AiInsightsClient`), including the
    aggregates-only privacy note for `/summary`.
  - `GET /health` on both Python services.
  - The `X-Internal-Secret` header contract itself (`app/dependencies.py` verification).

### 3. `docs/manual/` — user manual
One file per role, task-oriented (screenshots not included — text walkthroughs only),
grounded in the actual pages under `resources/js/pages/`:
- `docs/manual/employee.md` — login, dashboard, viewing own attendance, submitting a
  permission/leave request (types: Official Leave, Sick Leave, Permission, Field Duty),
  tracking request status, notifications, profile.
- `docs/manual/hr-officer.md` — everything above the employee has, plus: employee CRUD,
  reviewing/approving-rejecting permission requests (and what happens to attendance on
  approval), attendance manual correction, reports (view/export CSV+PDF), report summaries
  (generate/regenerate AI narrative), AI Insights page.
- `docs/manual/admin.md` — everything HR has, plus: department & work-schedule CRUD,
  biometric device + device-enrollment management, full system oversight.
- `docs/manual/README.md` — short index + seeded login table (already in CLAUDE.md/README,
  copied for convenience).

### 4. `docs/runbook.md` — admin/ops runbook
- Local dev bring-up (`start.ps1` / `eapms:start` / `eapms:status --watch`) and Docker
  Compose bring-up, sourced from `docker-compose.yml`, `CLAUDE.md`, and
  `EapmsStart`/`EapmsStatus` commands.
- Full environment variable reference: `INTERNAL_API_SECRET`, `AI_SERVICE_URL`,
  `BIO_SERVICE_URL`, `ANTHROPIC_API_KEY`, `CLAUDE_MODEL`, `LARAVEL_BASE_URL`,
  `POLL_INTERVAL_SECONDS`, DB_* — pulled from `docker-compose.yml` + `.env.example`.
- Scheduled/queued jobs table: `ai:score-attendance` (nightly 02:00), `attendance:sync-permissions`
  (backfill), `ComputeAttendance`, `RemindSignIn`, queue worker (`queue:work`) — what each
  does and how to run/re-run manually.
- Troubleshooting: services down (`eapms:status`), stuck queue jobs, AI/Claude fallback
  triggering (missing `ANTHROPIC_API_KEY`), signed-secret mismatch symptoms.
- Backup/restore note for MySQL (mysqldump) since Phase 13 deployment will need it referenced.

### 5. Cross-cutting updates
- `development_plan.md`: mark Phase 12 ✅ **COMPLETE** with a summary blockquote (matching
  the style of Phases 6–11), listing what was produced and where.
- `yner_main/README.md`: fix the **stale** phase status table (currently shows 6–12 as
  "Pending"/"Not started" even though 6–11 are done per `development_plan.md`) and add a
  `docs/` map (design / api / manual / runbook / ai / uat) to the README so the doc set is
  discoverable.

## Files to create/modify
- New: `docs/design/{README,use-case-diagram,erd,dfd,flowcharts,architecture}.md`
- New: `docs/api/{laravel,internal-rest}.md`
- New: `docs/manual/{README,employee,hr-officer,admin}.md`
- New: `docs/runbook.md`
- Edit: `development_plan.md` (Phase 12 status block)
- Edit: `yner_main/README.md` (phase table fix + docs map)
- New (per CLAUDE.md convention): `prompt/phase-12-documentation.md` — this plan, saved
  before execution begins.

## Verification
- Mermaid diagrams: spot-check syntax renders (GitHub-flavored Mermaid fences) — no build
  step exists for `.md`, so verification is visual/syntax review, not a test run.
- Cross-check every route/endpoint documented in `docs/api/*.md` against
  `routes/web.php`, `routes/api.php`, and the two FastAPI routers so nothing is invented.
- Cross-check every migration/table in `docs/design/erd.md` against
  `database/migrations/*.php` for field-level accuracy.
- Re-read `yner_main/README.md` phase table against `development_plan.md` headings after
  editing to confirm they now agree.
