# EAPMS — Employee Attendance & Permission Management System

Final Year Project (IFM). Biometric attendance and HR permission/leave management are
**synchronized** so approved absences never show as "Absent" in reports, with AI features
layered on top: anomaly detection, absenteeism prediction (scikit-learn), and
natural-language report summaries via the Claude API.

## Architecture (three services, Docker Compose)

| Service        | Stack                       | Role                                                      | Port |
| -------------- | --------------------------- | -------------------------------------------------------- | ---- |
| `yner_main`    | Laravel 12 / PHP 8.2 + MySQL | Core app, business logic, REST API — single source of truth | 8000 |
| `ai-service`   | Python / FastAPI            | scikit-learn ML + Claude API report summarization        | 8001 |
| `bio-service`  | Python / FastAPI            | ZKTeco / Hikvision device-agnostic biometric adapter     | 8002 |

`yner_main` is the orchestrator. Internal service calls are signed with an
`X-Internal-Secret` header. Frontend is Inertia.js + React + TypeScript + Tailwind v4 +
hand-written shadcn/ui primitives.

## Quick start

```powershell
.\start.ps1                                  # Windows — starts all three services
cd yner_main && php artisan eapms:start      # same, from yner_main
cd yner_main && php artisan eapms:status     # health dashboard
```

```bash
cd yner_main
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed                   # roles, sample org, a seeded week of attendance
npm run build
php artisan serve                            # http://localhost:8000
php artisan test                             # PHPUnit (SQLite in-memory)
```

Seeded logins (password `password`): `admin@eapms.test` (Admin), `hr@eapms.test` (HR Officer),
`employee@eapms.test` (Employee).

## Development progress

| Phase | Description | Status |
| ----- | ----------- | ------ |
| 0  | Project setup & foundations (3-service skeleton)            | ✅ Complete |
| 1  | Requirements & system design (diagrams, spec)              | ⬜ Not started |
| 2  | Authentication, RBAC & core domain                         | ✅ Complete |
| 3  | Biometric device integration (device-agnostic adapter)     | ✅ Complete |
| 4  | Attendance computation engine                              | ✅ Complete |
| 5  | HR permission & leave management                           | ✅ Complete |
| 6  | Attendance ⇄ permission synchronization (core innovation)  | ⬜ Pending |
| 7  | AI service — internal ML (anomaly detection, prediction)   | ⬜ Pending |
| 8  | AI service — Claude report summarization                   | ⬜ Pending |
| 9  | Intelligent notification system                            | ⬜ Pending |
| 10 | Reporting, dashboards & decision support                   | ⬜ Pending |
| 11 | Testing & QA                                               | ⬜ Pending |
| 12 | Documentation                                              | ⬜ Pending |
| 13 | Deployment (Cloud VPS) & hardening                         | ⬜ Pending |

See [`development_plan.md`](../development_plan.md) for the full plan and per-phase detail,
and [`prompt/`](../prompt/) for the executed implementation plans.
