# Admin / Operations Runbook

Operational reference for running, configuring, and troubleshooting all three EAPMS services.
For end-user feature walkthroughs see [`manual/`](manual/); for the API contracts see
[`api/`](api/).

## Bringing the stack up

### Local dev (Windows, no Docker)

```powershell
.\start.ps1                         # from the repo root
```

This is a thin wrapper (`start.ps1`) around `cd yner_main && php artisan eapms:start`, which:

1. Starts the Vite dev server (`npm run dev`) unless `--no-vite`.
2. For each Python service (`ai-service`, `bio-service`), unless `--no-ai`/`--no-bio`:
   - Creates a `venv/` on first run if missing, installs `requirements.txt` if `uvicorn`
     isn't already present in the venv (both silent/idempotent — safe to re-run).
   - Launches `uvicorn main:app --host 0.0.0.0 --port <port>` as a **detached background
     process**, logging to `yner_main/storage/logs/services/<name>.log`.
3. Runs `php artisan serve` **in the foreground** on `--port` (default 8000) — `Ctrl+C` stops
   the foreground Laravel process, but the background Python processes and Vite keep running
   until killed separately (by PID, or by closing the terminal/reboot).

Useful flags: `--port=`, `--ai-port=`, `--bio-port=`, `--no-ai`, `--no-bio`, `--no-vite`.

```powershell
cd yner_main
php artisan eapms:status              # one-shot health table
php artisan eapms:status --watch      # auto-refreshes every 5s (Ctrl+C to exit)
```

`eapms:status` hits `GET /health` on `ai-service` (`:8001`) and `bio-service` (`:8002`) with a
2s timeout and reports UP/DOWN + latency; `yner_main` itself is always reported UP (the
command running *is* Laravel).

### Docker Compose (all three services + MySQL)

```bash
docker compose up --build
docker compose down
```

`docker-compose.yml` defines four services: `mysql` (8.0, healthchecked via `mysqladmin
ping`), `yner_main` (`:8000`, depends on MySQL healthy), `ai-service` (`:8001`), `bio-service`
(`:8002`, depends on `yner_main`). Each service builds from its own `Dockerfile`.

## First-time setup (either path)

```bash
cd yner_main
cp .env.example .env && php artisan key:generate
composer install && npm install
php artisan migrate --seed          # roles + sample org + seeded attendance history
npm run build
```

```bash
cd ai-service   # and separately: cd bio-service
python -m venv venv && venv\Scripts\activate    # Windows; source venv/bin/activate on *nix
pip install -r requirements.txt
cp .env.example .env
```

## Environment variables

### `yner_main/.env`

| Var | Default | Purpose |
| --- | --- | --- |
| `DB_CONNECTION`/`DB_HOST`/`DB_PORT`/`DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD` | mysql / 127.0.0.1 / 3306 / eapms / eapms / secret | Laravel's MySQL connection |
| `QUEUE_CONNECTION` | `database` | Queue driver — `SyncAttendanceForApprovedPermission` is `ShouldQueue`; run a worker (see below) or set `sync` for inline (test) execution |
| `INTERNAL_API_SECRET` | `change-me-in-production` | Shared secret Laravel expects on inbound `/api/biometric/*` calls, and sends on outbound calls to `ai-service` — **must match** the same var in both Python services' `.env` |
| `AI_SERVICE_URL` | `http://localhost:8001` (Compose: `http://ai-service:8001`) | Base URL `AiInsightsClient` calls |
| `MAIL_MAILER` | `log` | Set to a real driver (smtp, etc.) to actually deliver notification emails instead of writing them to the log |

Note: `yner_main` has **no** `BIO_SERVICE_URL` — the data flow is one-directional at the HTTP
level for that pairing (bio-service polls `GET /api/biometric/devices` from Laravel and pushes
punches to `POST /api/biometric/ingest`; Laravel never calls out to bio-service). The
`BIO_SERVICE_URL` var set on the `yner_main` container in `docker-compose.yml` is currently
unused by the codebase — safe to ignore/remove if you're tightening the Compose file.

### `ai-service/.env`

| Var | Default | Purpose |
| --- | --- | --- |
| `INTERNAL_API_SECRET` | `change-me-in-production` | Verified on every `/api/analysis/*` request — must match Laravel's value |
| `LARAVEL_BASE_URL` | `http://yner_main` | Not currently called by ai-service itself (reserved) |
| `ANTHROPIC_API_KEY` | *(empty)* | Claude API key — **unset = automatic template fallback**, not an error |
| `CLAUDE_MODEL` | `claude-sonnet-4-6` | Model used for report summarization |

### `bio-service/.env`

| Var | Default | Purpose |
| --- | --- | --- |
| `INTERNAL_API_SECRET` | `change-me-in-production` | Sent on every call to Laravel's `/api/biometric/*` |
| `LARAVEL_BASE_URL` | `http://yner_main` | Where the poller pushes ingested punches and fetches the device registry |
| `POLL_INTERVAL_SECONDS` | `300` | How often the scheduler polls each active device |

**Keep `INTERNAL_API_SECRET` identical across all three `.env` files** — a mismatch fails
every internal call with `403`.

## Scheduled & queued jobs

| Job | Schedule / trigger | Command | Purpose |
| --- | --- | --- | --- |
| Attendance computation | Daily 01:00 (`routes/console.php`) | `php artisan attendance:compute` | Recomputes yesterday's attendance from raw punches. Also invoked ad-hoc per-date by the ingest endpoint. |
| AI scoring | Daily 02:00 | `php artisan ai:score-attendance [--days=90]` | Sends the scoring window to `ai-service`, persists `ai_anomalies`/`ai_predictions`, notifies Admin/HR if anything was scored |
| Sign-in reminder | Daily 08:15 | `php artisan attendance:remind-sign-in [--date=]` | Notifies active, linked employees with no punch yet today |
| Permission → attendance sync | Event-driven (on approval) | *(automatic — `SyncAttendanceForApprovedPermission` listener)* | Overlays the approved leave status onto attendance for the request's date range |
| Backfill sync | Manual, as needed | `php artisan attendance:sync-permissions [--from=] [--to=]` | Idempotent replay of the sync engine over **all** approved requests — use after a data import, a bug fix, or if you suspect drift |

For the scheduler to actually fire (`Schedule::command(...)`), either run
`php artisan schedule:work` (dev) or put `php artisan schedule:run` on the system's real cron,
once a minute, in production.

**Queue worker** (required for the sync engine to run on `QUEUE_CONNECTION=database`):
```bash
php artisan queue:work
```
Without a running worker, approved-permission syncs stay queued in the `jobs` table until one
starts — attendance won't reflect the approval until then. Set `QUEUE_CONNECTION=sync` (tests
already do this) if you want approvals to sync inline instead.

## Troubleshooting

| Symptom | Likely cause | Fix |
| --- | --- | --- |
| `eapms:status` shows ai-service/bio-service DOWN | Python service crashed, venv missing deps, or port in use | Check `yner_main/storage/logs/services/<name>.log`; re-run `eapms:start` (venv/deps auto-repair) |
| Permission approved but attendance still shows Absent | Queue worker not running (`QUEUE_CONNECTION=database`) | Start `php artisan queue:work`, or run `php artisan attendance:sync-permissions` to backfill |
| `403 Invalid internal secret` on any inter-service call | `INTERNAL_API_SECRET` mismatch between services | Ensure all three `.env` files have the identical value; restart the services after changing it |
| AI Insights / report summary always shows "fallback" | `ANTHROPIC_API_KEY` unset on `ai-service`, or Claude API unreachable | Set a valid key in `ai-service/.env` and restart ai-service — this is a graceful degradation, not a bug |
| "AI service is unavailable" flash on Report Summaries page | `ai-service` down or `AI_SERVICE_URL` wrong | Check `eapms:status`; verify `AI_SERVICE_URL` points at the right host/port |
| New biometric device never produces attendance | No `device_enrollments` row mapping that device's `device_user_id` to an employee | Admin → Device Enrollments → add the mapping (see `docs/manual/admin.md`) |
| Nightly jobs never run | No cron / `schedule:work` configured | See "Scheduled & queued jobs" above |

## Backup & restore (MySQL)

```bash
# Backup
mysqldump -h 127.0.0.1 -u eapms -p eapms > eapms_$(date +%Y%m%d).sql

# Restore
mysql -h 127.0.0.1 -u eapms -p eapms < eapms_20260706.sql
```

Under Docker Compose, `mysql_data` is a named volume — back it up at the volume level
(`docker run --rm -v tyner_2026_mysql_data:/data -v $(pwd):/backup alpine tar czf
/backup/mysql_data.tar.gz /data`) as an alternative to `mysqldump`, or in addition to it.
Phase 13 (deployment) will formalize an automated backup schedule on the production VPS; this
is the manual procedure until then.
