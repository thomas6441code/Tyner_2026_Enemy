# Employee Attendance & Permission Management System (EAPMS)

A Final Year Project for the **Institute of Finance Management (IFM)**.

Biometric attendance (ZKTeco / Hikvision) + HR permission/leave management — synchronized so approved absences never show as "Absent" in reports. Layered with AI features: anomaly detection, absenteeism prediction (scikit-learn), and natural-language report summaries (Claude API).

## Architecture

Three services, one Docker Compose, all orchestrated from **yner_main**:

| Service | Tech | Port |
|---|---|---|
| `yner_main` | Laravel 12 / PHP 8.2, MySQL 8 — core app | 8000 |
| `ai-service` | Python / FastAPI, scikit-learn, Claude API | 8001 |
| `bio-service` | Python / FastAPI, pyzk / ISAPI — biometric adapter | 8002 |

## Quick start (development)

```powershell
# Windows
.\start.ps1

# Linux / macOS
bash start.sh
```

This launches all three services from `yner_main` and streams their status to the console.

**Check status at any time:**
```powershell
.\status.ps1            # Windows
# or
cd yner_main && php artisan eapms:status
cd yner_main && php artisan eapms:status --watch   # refreshes every 5s
```

## Quick start (Docker — all in one)

```bash
cp .env.example .env    # fill in secrets
docker compose up --build
```

## Manual per-service start

### yner_main (Laravel)
```bash
cd yner_main
cp .env.example .env && php artisan key:generate
composer install
npm install && npm run build
php artisan migrate --seed
php artisan serve
```

Seeding creates the three roles (Admin, HR Officer, Employee), a sample department/work-schedule/employee set, and one login per role for local testing:

| Role | Email | Password |
|---|---|---|
| Admin | `admin@eapms.test` | `password` |
| HR Officer | `hr@eapms.test` | `password` |
| Employee | `employee@eapms.test` | `password` |

### Python services
```bash
cd ai-service           # or bio-service
python -m venv venv && source venv/bin/activate    # or venv\Scripts\activate on Windows
pip install -r requirements.txt
uvicorn main:app --reload --port 8001              # 8002 for bio-service
```

## Running tests

```bash
# yner_main
cd yner_main && php artisan test

# Python services
cd ai-service && pytest
cd bio-service && pytest
```

## Branching strategy

- `main` — production-ready only
- `develop` — integration branch
- `feature/<name>` — one branch per phase/feature, PR into `develop`

## Phase status

| Phase | Description | Status |
|---|---|---|
| 0 | Project setup & foundations | ✅ Done |
| 1 | Requirements & system design | ⬜ |
| 2 | Auth, RBAC & core domain | ✅ Done |
| 3 | Biometric device integration | ✅ Done |
| 4 | Attendance computation engine | ✅ Done |
| 5 | HR permission & leave management | ✅ Done |
| 6 | Attendance ↔ permission sync | ✅ Done |
| 7 | AI: anomaly detection & prediction | ⬜ |
| 8 | AI: Claude report summarization | ⬜ |
| 9 | Notification system | ⬜ |
| 10 | Reporting & dashboards | ⬜ |
| 11 | Testing & QA | ⬜ |
| 12 | Documentation | ⬜ |
| 13 | Cloud VPS deployment | ⬜ |
