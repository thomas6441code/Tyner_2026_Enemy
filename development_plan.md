# Development Plan — AI-Enhanced Employee Attendance & Permission Management System (IFM)

> **Deliverable:** On approval, this plan is saved to the project root as
> `C:\Users\Thomas-6441\Tyner_2026\DEVELOPMENT_PLAN.md` (the single execution action).
> The working directory is currently empty — this is a **greenfield** build.

---

## Context

This is a Final Year Project for the **Institute of Finance Management (IFM)**. The core problem: biometric
(fingerprint) attendance systems run **independently** from HR permission/leave management, so an employee
with an HR-approved permission/leave still shows up as **Absent** or **null** in monthly attendance reports.

The system fixes this by **synchronizing biometric attendance with HR permission records** and layering on
**AI features** (trend analysis, anomaly detection, absenteeism prediction, intelligent notifications, and
automated report summarization).

This plan breaks the build into **14 sequenced phases** so no requirement or supervisor concern is missed.
Each phase lists its goal, tasks, deliverables, and which proposal requirement it satisfies.

### Locked architectural decisions (confirmed with user)

| Decision          | Choice                      | Rationale                                                                                                                                                                                                               |
| ----------------- | --------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Biometric capture | **Device-agnostic adapter** | Pluggable drivers for**ZKTeco** (`pyzk`/TCP-IP) and **Hikvision** (ISAPI) behind one interface — answers supervisor's "which device?" by supporting both.                                                               |
| AI implementation | **Hybrid**                  | Internal ML (scikit-learn) for detection/prediction (explainable, self-hosted)**+** external **Claude API** for natural-language report summaries. Cleanly answers "internal or external — and how": _both, by design_. |
| Deployment        | **Cloud VPS** (Ubuntu)      | Central access for IFM HR across campuses, managed backups, TLS, scalable.                                                                                                                                              |

### Direct answers to the supervisor's margin annotations (must appear in final report / defense prep)

1. **"Which fingerprint device?"** → Device-agnostic adapter; reference devices **ZKTeco** and **Hikvision**. Templates stay on-device; we pull **attendance logs** (timestamps + device-user-id), not raw fingerprints.
2. **"Is AI internal or external, and how?"** → Hybrid: internal scikit-learn models (anomaly/prediction) run in our Python service; external Claude API used only for prose summarization. Documented data-flow + REST contracts (Phase 7–8).
3. **"Justify language choices; combine CSS3+Bootstrap and PHP+Laravel."** → Stack table below treats **Laravel (PHP 8.x)** as one item and **Bootstrap 5 (CSS3)** as one item, with a written justification column.
4. **"What deployment environment, and why?"** → Cloud VPS section (Phase 13) with justification.
5. **"Explain how each AI feature works."** → Each AI feature has an explicit method note (Phase 7–8) and an explainability deliverable for defense.

---

## Target Architecture

```
                ┌──────────────────────────────────────────────┐
  Biometric     │  Python Biometric Adapter Service (FastAPI)  │
  terminals ───▶│  driver interface → ZKTeco / Hikvision driver │──┐  REST (signed)
  (ZKTeco /     │  pulls punch logs, normalizes, pushes         │  │
   Hikvision)   └──────────────────────────────────────────────┘  │
                                                                   ▼
  Browser  ───▶  ┌───────────────────────────────────────────────────────┐
 (Bootstrap 5 /  │              Laravel (PHP 8.x) — core app             │
  Blade / JS)    │  Auth/RBAC · Employees · Attendance engine ·          │
                 │  Permission/Leave workflow · Sync engine ·            │
                 │  Notifications · Reporting · REST API                 │
                 └───────────────┬───────────────────────┬──────────────┘
                                 │ MySQL                 │ REST
                                 ▼                       ▼
                      ┌──────────────────┐   ┌──────────────────────────────┐
                      │   MySQL 8        │   │  Python AI Service (FastAPI) │
                      │  (all records)   │   │  scikit-learn: anomaly +     │
                      └──────────────────┘   │  prediction; Claude API for  │
                                             │  report summarization        │
                                             └──────────────────────────────┘
```

**Three deployable services**, orchestrated with Docker Compose:

- **Laravel app** — web UI, business logic, REST API, single source of truth.
- **Biometric Adapter (Python/FastAPI)** — device drivers + scheduled log pull → pushes to Laravel.
- **AI Service (Python/FastAPI)** — ML models + Claude summarization, called by Laravel.

### Technology stack (rationalized per supervisor)

| Layer       | Technology                                                           | Why this choice                                                                                                                                                                                                            |
| ----------- | -------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Frontend    | **Bootstrap 5 (HTML5 + CSS3)** + vanilla JS                          | Responsive, fast to build, mobile-friendly; CSS3 styling is delivered*through* Bootstrap (one item, not two).                                                                                                              |
| Backend     | **Laravel (PHP 8.x)**                                                | Laravel*is* PHP — listed as one. Chosen over raw PHP for built-in auth, Eloquent ORM, queues, scheduler, API tooling, security (CSRF/validation). Chosen over Node/Django for team familiarity + XAMPP-friendly local dev. |
| Database    | **MySQL 8**                                                          | Relational data (employees↔attendance↔permissions) fits SQL; mature, free, well-documented.                                                                                                                                |
| AI / ML     | **Python 3.x + scikit-learn, pandas**                                | De-facto ML ecosystem; explainable classical models suited to tabular attendance data.                                                                                                                                     |
| AI / NLP    | **Claude API** (external)                                            | Natural-language monthly summaries the supervisor asked us to "explain" — LLM produces readable insights ML alone cannot.                                                                                                  |
| Integration | **REST APIs** + **FastAPI** Python services                          | Clean language boundary between PHP and Python; signed internal calls.                                                                                                                                                     |
| Biometric   | **Device-agnostic adapter** (`pyzk` for ZKTeco, ISAPI for Hikvision) | Supports both reference devices without rewriting core.                                                                                                                                                                    |
| Tooling     | VS Code · Git/GitHub · Docker · XAMPP (local)                        | Version control, reproducible envs, local PHP/MySQL.                                                                                                                                                                       |

---

## Data Model (key tables)

`users` (auth) · `roles`/`permissions` (RBAC) · `departments` · `employees` ·
`work_schedules` (shift, expected in/out, grace period) · `biometric_devices` ·
`device_enrollments` (device_user_id ⇄ employee_id) · `raw_attendance_logs` (punch events) ·
`attendance_records` (daily computed: status, first_in, last_out, hours, late_minutes) ·
`attendance_status_types` (Present, Absent, Late, **Official Leave, Sick Leave, Permission Approved, Field Duty**) ·
`permission_types` · `permission_requests` (employee, type, date range, reason, status, approver) ·
`ai_anomalies` · `ai_predictions` (risk score) · `report_summaries` (LLM output) ·
`notifications` · `audit_logs`.

ERD/DFD/Use-Case diagrams are produced in Phase 1.

---

## Phased Development Plan

### Phase 0 — Project Setup & Foundations ✅ **COMPLETE**

**Goal:** Reproducible skeleton for all three services.

- Init Git repo + GitHub, branching strategy, `.gitignore`, README.
- Scaffold Laravel app; configure `.env`, MySQL connection, queues, scheduler.
- Scaffold two FastAPI services (`ai-service`, `biometric-adapter`) with `requirements.txt`/venv.
- `docker-compose.yml` (laravel + php-fpm/nginx, mysql, ai-service, biometric-adapter).
- Coding standards (Pint/PHP-CS-Fixer, Black/Ruff), basic CI (lint + test).
- **Deliverable:** running empty stack, one command up. _(Maps: methodology 6.3, tooling 6.5.12–6.5.14)_

### Phase 1 — Requirements & System Design (documentation artifacts) ⬜ **NOT STARTED**

> `docs/design/` is still an empty placeholder — the diagrams + requirements spec remain outstanding.

**Goal:** Lock requirements and produce the design diagrams the proposal mandates.

- Finalize functional + non-functional requirements (from objectives 4.2 + AI features 6.6).
- **Use-Case diagram** (actors: Employee, HR Officer, Admin), **ERD**, **DFD (L0/L1)**, **flowcharts** (attendance compute, approval→sync), **system architecture diagram**.
- Tech-stack justification write-up (the table above).
- **Deliverable:** `/docs/design/` with all diagrams + requirements spec. _(Maps: 6.4.1–6.4.5)_

### Phase 2 — Authentication, RBAC & Core Domain ✅ **COMPLETE**

**Goal:** Identity and the employee/department backbone.

- Laravel auth (Breeze/Fortify); roles **Employee / HR Officer / Admin** with policies.
- Employee CRUD, department CRUD, profile, work-schedule config.
- Role-based dashboard shells + Bootstrap layout/navigation.
- **Deliverable:** secured app, seeded sample org. _(Maps: actors in 6.4.1; objective 6)_

### Phase 3 — Biometric Device Integration (device-agnostic adapter) ✅ **COMPLETE**

**Goal:** Pull attendance punches from real devices, vendor-independently.

- Define `BiometricDriver` interface (connect, fetch_logs, list_users, health).
- Implement **ZKTeco driver** (`pyzk` over TCP/IP) and **Hikvision driver** (ISAPI/event stream).
- Device registry + `device_enrollments` mapping (device_user_id → employee).
- Scheduled poller normalizes punches → POST to Laravel ingestion API (idempotent, signed).
- **Deliverable:** punches flowing from a device (or driver stub) into `raw_attendance_logs`. _(Maps: objective 1; 6.5.11; supervisor device annotation)_

### Phase 4 — Attendance Computation Engine ✅ **COMPLETE**

**Goal:** Turn raw punches into accurate daily attendance.

- Ingestion endpoint + dedupe; pair sign-in/sign-out; compute hours, **late/early** vs work schedule.
- Daily status derivation (Present / Late / Absent / null-for-no-punch).
- HR manual-correction UI with audit trail.
- **Deliverable:** daily `attendance_records` per employee. _(Maps: objective 1; problem of null/absent records)_

### Phase 5 — HR Permission & Leave Management ✅ **COMPLETE**

**Goal:** Digitize the HR side of the integration.

- Employee submits permission/leave request (type, date range, reason, attachment).
- Permission types: **Official Leave, Sick Leave, Permission, Field Duty**.
- HR approval/rejection workflow, routing, status, notifications hook, audit.
- **Deliverable:** end-to-end request → approval lifecycle. _(Maps: objective 2; 6.6.6 categories)_

### Phase 6 — Attendance ⇄ Permission Synchronization (the core innovation)

**Goal:** The heart of the project — eliminate false "Absent"/null records.

- Sync engine: on permission **approval**, overlay approved dates onto attendance and update status from Absent/null → **Official Leave / Sick Leave / Permission Approved / Field Duty**.
- Conflict resolution rules (punch exists vs approved leave), recompute on retro-approval, full audit.
- Event-driven (Laravel events/jobs) so approvals trigger immediate resync.
- **Deliverable:** approved permissions correctly reflected in attendance + monthly view. _(Maps: objectives 2 & 3; AI feature 6.6.6 — automatic status synchronization)_

### Phase 7 — AI Service: Internal ML (analysis, anomaly detection, prediction)

**Goal:** Explainable, self-hosted intelligence over attendance data.

- FastAPI service + feature pipeline (pandas) over attendance history.
- **Smart attendance analysis** — lateness/absenteeism rates, trend aggregation. _(6.6.1)_
- **Anomaly detection** — Isolation Forest / statistical z-score on punch-time & pattern outliers. _(6.6.2)_
- **Predictive monitoring** — classifier (logistic regression / random forest) outputs absenteeism/lateness **risk score** per employee. _(6.6.3)_
- Explainability notes + feature importance (so the team can _explain how it works_ per supervisor).
- Laravel↔AI REST contract; scheduled batch scoring → `ai_anomalies`, `ai_predictions`.
- **Deliverable:** insights endpoints + persisted scores. _(Maps: objective 4; 6.5.9; 6.6.1–6.6.3)_

### Phase 8 — AI Service: External LLM Report Summarization (Claude API)

**Goal:** Human-readable monthly insight the supervisor asked us to explain.

- Integrate **Claude API**; build structured-data → prompt → summary pipeline.
- **Automated report summarization** — monthly narrative + highlights/recommendations. _(6.6.5)_
- Guardrails: send aggregates only (privacy), caching, graceful fallback if API unavailable.
- **Deliverable:** "Generate AI summary" produces a monthly narrative on the report page. _(Maps: 6.6.5; "internal or external" → external piece, documented)_

### Phase 9 — Intelligent Notification System

**Goal:** Proactive alerts/reminders.

- Channels: in-app + email (optional SMS gateway); Laravel queue + scheduler.
- Triggers: pending approvals, detected anomalies, high-risk predictions, sign-in reminders, monthly-report ready.
- **Deliverable:** notification center + email delivery. _(Maps: objective 5; 6.6.4)_

### Phase 10 — Reporting, Dashboards & Decision Support

**Goal:** Accurate reports that reflect permissions + AI insights.

- Monthly/range attendance reports (now permission-aware — no false absences).
- Export **PDF/Excel**; analytics dashboards (charts) for trends, anomalies, risk.
- Surface AI summary + predictions for management decision support.
- **Deliverable:** report module + dashboards. _(Maps: objective 6; expected outcomes 1–6)_

### Phase 11 — Testing & QA

**Goal:** Verify every objective and AI feature.

- Laravel **Pest/PHPUnit** (unit + feature), Python **pytest** (drivers, ML, API).
- ML evaluation (precision/recall on labeled sample; document metrics).
- Integration tests across the 3 services; security checks (authz, input validation, secrets).
- **UAT script** mapping each test to an objective/AI feature (traceability).
- **Deliverable:** green test suite + UAT sign-off. _(Maps: 6.3.5)_

### Phase 12 — Documentation

**Goal:** Academic + operational docs.

- API docs, user manual (Employee/HR/Admin), admin/runbook, final report sections, finalized diagrams.
- **Deliverable:** `/docs` complete. _(Maps: 6.3.7)_

### Phase 13 — Deployment (Cloud VPS) & Hardening

**Goal:** Live system with justification.

- Provision Ubuntu VPS; Nginx + PHP-FPM + MySQL; Python services via Docker/systemd; queue worker + cron scheduler.
- HTTPS (Let's Encrypt), env secrets, automated DB backups, log rotation, firewall.
- CI/CD deploy; written justification for cloud choice (central multi-campus access, backups, TLS, scalability).
- **Deliverable:** production URL + ops runbook. _(Maps: 6.3.6; supervisor deployment annotation)_

---

## Requirements Traceability (nothing missed)

| Proposal requirement                         | Covered in            |
| -------------------------------------------- | --------------------- |
| Obj 1 — biometric sign-in/out                | Phase 3, 4            |
| Obj 2 — integrate attendance + HR permission | Phase 5, 6            |
| Obj 3 — auto status update on approval       | Phase 6               |
| Obj 4 — AI analysis & anomaly detection      | Phase 7               |
| Obj 5 — intelligent notification & reporting | Phase 9, 10           |
| Obj 6 — improve accuracy & efficiency        | Phase 4, 6, 10, 11    |
| 6.6.1 Smart analysis                         | Phase 7               |
| 6.6.2 Anomaly detection                      | Phase 7               |
| 6.6.3 Predictive monitoring                  | Phase 7               |
| 6.6.4 Intelligent notifications              | Phase 9               |
| 6.6.5 Automated report summarization         | Phase 8               |
| 6.6.6 Auto status synchronization            | Phase 6               |
| Design tools (UML/ERD/DFD/flowchart/arch)    | Phase 1               |
| Tech-stack justification                     | Phase 1 + stack table |
| Deployment environment + why                 | Phase 13              |

---

## Verification (end-to-end)

1. **Sync correctness (core):** seed employee with no punch on a date → HR approves a Permission for that date → confirm `attendance_records` flips Absent/null → "Permission Approved", and the monthly report + export show it correctly.
2. **Biometric ingest:** run adapter against a ZKTeco stub and a Hikvision stub → confirm both populate `raw_attendance_logs` through the same interface.
3. **ML:** run anomaly + prediction batch on seeded history → verify `ai_anomalies`/`ai_predictions` populate and feature-importance output exists.
4. **LLM:** trigger monthly summary → Claude returns a narrative; with API key removed, fallback message shows (no crash).
5. **Notifications:** approve a request and detect an anomaly → in-app + email fire.
6. **Automated tests:** `php artisan test` (Pest) and `pytest` both green in CI.
7. **Deploy smoke test:** hit production URL over HTTPS, log in per role, generate a report.

---

## Notes / Assumptions

- Treated as an **academic FYP prototype** (working software + full documentation/diagrams), not an enterprise rollout.
- Physical biometric hardware may be unavailable during dev → driver **stubs/simulator** stand in; real ZKTeco/Hikvision drivers are written to the same interface so swap-in is trivial.
- Claude API used only for **aggregated** summary text (no raw biometric/PII sent externally).
