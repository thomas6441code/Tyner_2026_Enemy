# IFM Attendance & Permission Management System

A Final Year Project for the **Institute of Finance Management (IFM)**.

Biometric attendance (ZKTeco / Hikvision) + HR permission/leave management — synchronized so approved absences never show as "Absent" in reports. Layered with AI features: anomaly detection, absenteeism prediction (scikit-learn), and natural-language report summaries (Claude API).

## Architecture

Three services, one Docker Compose:

| Service | Tech | Port |
|---|---|---|
| `laravel-app` | Laravel 11 / PHP 8.2, MySQL 8 | 8000 |
| `ai-service` | Python / FastAPI, scikit-learn, Claude API | 8001 |
| `biometric-adapter` | Python / FastAPI, pyzk / ISAPI | 8002 |

## Quick start

```bash
cp .env.example .env          # fill in secrets
docker compose up --build
```

The Laravel app will be available at http://localhost:8000.

## Local development (without Docker)

### Laravel
```bash
cd laravel-app
cp .env.example .env && php artisan key:generate
composer install
php artisan migrate --seed
php artisan serve
```

### Python services
```bash
cd ai-service           # or biometric-adapter
python -m venv venv && source venv/bin/activate
pip install -r requirements.txt
uvicorn main:app --reload
```

## Running tests

```bash
# Laravel
cd laravel-app && php artisan test

# Python services
cd ai-service && pytest
cd biometric-adapter && pytest
```

## Branching strategy

- `main` — production-ready only
- `develop` — integration branch
- `feature/<name>` — one branch per phase/feature, PR into `develop`

## Phase status

| Phase | Description | Status |
|---|---|---|
| 0 | Project setup & foundations | ✅ Done |
| 1 | Requirements & system design | ⬜ Next |
| 2 | Auth, RBAC & core domain | ⬜ |
| 3 | Biometric device integration | ⬜ |
| 4 | Attendance computation engine | ⬜ |
| 5 | HR permission & leave management | ⬜ |
| 6 | Attendance ↔ permission sync | ⬜ |
| 7 | AI: anomaly detection & prediction | ⬜ |
| 8 | AI: Claude report summarization | ⬜ |
| 9 | Notification system | ⬜ |
| 10 | Reporting & dashboards | ⬜ |
| 11 | Testing & QA | ⬜ |
| 12 | Documentation | ⬜ |
| 13 | Cloud VPS deployment | ⬜ |
