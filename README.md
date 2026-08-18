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

Seeding creates the three roles (Admin, HR Officer, Employee), a sample department/work-schedule/employee set, three geofenced work locations, and one login per role for local testing:

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

## Accounts are created by invitation, not self-signup

`/register` does **not** create an account. It submits a **registration request** that an Admin or HR Officer reviews:

1. An applicant submits their details at `/register` — no `users` row is created and they stay logged out.
2. Admin/HR approve the request under **Registration Requests**, supplying what the applicant cannot: employee code, department, work schedule, work location, hire date. Approval creates the `Employee` as **inactive**, so it generates no phantom "Absent" records before the person can log in.
3. Approval mints a **single-use activation link** (72h by default, `AUTH_INVITATION_EXPIRE` minutes). Only a bcrypt hash of the token is stored, so the link is shown **once** — copy it from the dialog.
4. The applicant opens the link, sets a password, and lands on the employee dashboard with the `Employee` role.

Because `MAIL_MAILER=log` in development, invitation emails are not delivered — use the copy-link dialog, or find the link under **Invitations**. Stale links are swept weekly by `php artisan invitations:prune` (unredeemed only; used invitations are kept as evidence of activation).

## Mobile check-in (WebAuthn + geofence)

A second, independent attendance channel: an employee's phone acts as the terminal, their identity proven by a **WebAuthn passkey** and their presence bounded by a **server-side geofence**. Mobile punches land in the same `raw_attendance_logs` table as biometric ones (`source = 'mobile'`, `device_serial = 'MOBILE-WEBAUTHN'`), so the attendance calculator, permission sync, reports, and AI pipeline all work unchanged — and a device punch and a mobile punch on the same day merge into a single attendance record.

### Requires a secure context — read this before debugging

`navigator.credentials` **does not exist** outside HTTPS or `localhost`. It is `undefined`, not an error you can catch, so on `http://192.168.x.x:8000` the check-in page will simply report that WebAuthn is unavailable. Testing on a real phone therefore needs the deployed HTTPS domain (or a tunnel that terminates TLS).

`WEBAUTHN_RP_ID` must be the **public registrable domain the browser sees** — no scheme, no port, no container hostname:

```dotenv
WEBAUTHN_RP_ID=eapms.example.ac.tz      # not http://eapms.example.ac.tz:8000, not the container host
WEBAUTHN_RP_NAME="EAPMS"
WEBAUTHN_REQUIRE=true                    # hard block: no valid assertion, no check-in
```

Left empty, it falls back to the host of `APP_URL` — right for local work, wrong behind a proxy. Credentials are cryptographically bound to this value, so changing it invalidates every registered passkey; each credential records the `rp_id` it was created under, so a domain move produces an honest "re-register your device" prompt instead of an opaque signature failure. A `SecurityError` in the browser means the RP ID does not match the page's origin — that is a **config bug, not user error**.

### Setup

1. Create a **Work Location** with the site's coordinates and a radius (minimum 20m; 150m is a sensible default — phone GPS is routinely 20–50m out). Assign it to a department, or to individual employees who work elsewhere. An employee's own location wins over their department's, and an employee with **neither** cannot check in at all — this is deliberate, a global default would silently geofence everyone against head office.
2. Each employee registers their phone once under **Devices** (password confirmation required, then the fingerprint/Face prompt).
3. **Check In** / **Check Out** from the phone: the page takes a fresh GPS fix, then requests the passkey assertion, then posts.

Tunables live in `config/attendance.php` (`MOBILE_*` env keys): accuracy ceiling, minimum interval between punches, how far either side of the schedule a punch is accepted, and the impossible-travel speed threshold.

### What this does and does not prove

**WebAuthn proves *which device* made the request, not *where that device is*.** Coordinates come from `navigator.geolocation` and are spoofable with a devtools sensor override or a mock-location app. The mitigations are layered, not absolute: distance is computed **server-side only**, fixes coarser than `MOBILE_MAX_ACCURACY_METERS` are rejected, implausible travel between punches is flagged for Admin, and **every attempt — including every rejection — is persisted** with its real distance, IP, and user agent.

That audit trail is the actual control. Mobile check-in is a **supplement** to the biometric terminals, not a replacement, and should be presented to stakeholders that way.

`php artisan eapms:status` reports the channel's health alongside the three services: active devices, geofence sites, and today's accepted, rejected, and flagged check-ins.

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
| 1 | Requirements & system design | ✅ Done |
| 2 | Auth, RBAC & core domain | ✅ Done |
| 3 | Biometric device integration | ✅ Done |
| 4 | Attendance computation engine | ✅ Done |
| 5 | HR permission & leave management | ✅ Done |
| 6 | Attendance ↔ permission sync | ✅ Done |
| 7 | AI: anomaly detection & prediction | ✅ Done |
| 8 | AI: Claude report summarization | ✅ Done |
| 9 | Notification system | ✅ Done |
| 10 | Reporting & dashboards | ✅ Done |
| 11 | Testing & QA | ✅ Done |
| 12 | Documentation | ✅ Done |
| 13 | Cloud VPS deployment | ✅ Done |
| 14 | Invitation-based registration (request → approve → activate) | ✅ Done |
| 15 | Work locations & geofencing | ✅ Done |
| 16 | WebAuthn device registration | ✅ Done |
| 17 | Geofenced mobile check-in / check-out | ✅ Done |
