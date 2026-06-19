# Phase 3 — Biometric Device Integration (Device-Agnostic Adapter)

## Context

`development_plan.md` Phase 3 is the first phase that connects real hardware concepts to the system: pull attendance punches from biometric devices vendor-independently, behind one interface, so the supervisor's "which device?" question is answered by design (ZKTeco + Hikvision, both swappable behind `BiometricDriver`). The scaffold already exists — `BiometricDriver` ABC, `StubDriver`, NotImplementedError stubs for ZKTeco/Hikvision, and a `pusher.py` that POSTs to a Laravel endpoint that doesn't exist yet. This plan finishes the loop: real (untested-against-hardware-but-protocol-correct) drivers, a device registry living in Laravel (the single source of truth per CLAUDE.md), a scheduler in bio-service that pulls the registry and polls devices, and an Admin CRUD UI so devices/enrollments are configured through the app instead of `.env`/tinker. End state: punches flow from a device (real or `StubDriver`) into `raw_attendance_logs`, deduplicated and idempotent.

**Locked decisions (confirmed with user this session):**
- Drivers implement real protocol code (pyzk for ZKTeco, ISAPI/httpx for Hikvision), not thin TODO wrappers — correctness is judged against the interface/mocks since no hardware is present.
- Full Admin CRUD UI for `biometric_devices` and `device_enrollments`, mirroring the existing Department/WorkSchedule/Employee pattern exactly.
- bio-service pulls its device registry from Laravel each poll cycle (`GET /api/biometric/devices`) rather than static env config — avoids two sources of truth, reuses the Admin UI as the real registry, and reuses the existing signed-header pattern. No new docker-compose/env vars needed; device credentials live only in the `biometric_devices` table.
- Devices are Admin-only (view+manage), not Admin+HR like Department — deliberate departure from the Department/WorkSchedule view-pattern since devices are infra, not HR data.
- Per-device poll password/credentials cross the internal network in plaintext JSON (matches the project's existing trust model: shared `X-Internal-Secret`, no TLS internally) — acceptable for this FYP scope.

## A. Laravel migrations + models

Three new migrations in `yner_main/database/migrations/`:
- `create_biometric_devices_table`: id, name, `type` enum(stub,zkteco,hikvision), `serial` unique, `host` nullable, `port` unsigned int nullable, `username` nullable, `password` **text** nullable (text because the `encrypted` cast ciphertext is long), `status` enum(active,inactive) default active, timestamps.
- `create_device_enrollments_table`: id, `biometric_device_id` FK cascadeOnDelete, `employee_id` FK cascadeOnDelete, `device_user_id` string, timestamps, unique(`biometric_device_id`,`device_user_id`).
- `create_raw_attendance_logs_table`: id, `device_user_id`, `punched_at` dateTime, `device_serial`, `raw_payload` json nullable, `processed_at` timestamp nullable, timestamps, **named** unique(`device_serial`,`device_user_id`,`punched_at`) as `raw_attendance_logs_dedupe_unique` (explicit name to avoid MySQL's 64-char auto-name limit).

New models: `App\Models\BiometricDevice` (`encrypted` cast on `password`, `integer` cast on `port`, `hasMany(DeviceEnrollment)`), `App\Models\DeviceEnrollment` (`belongsTo(BiometricDevice)`, `belongsTo(Employee)`), `App\Models\RawAttendanceLog` (casts: `punched_at` => datetime, `raw_payload` => array, `processed_at` => datetime).

Modify `app/Models/Employee.php`: add `deviceEnrollments(): HasMany`.

## B. Laravel API (ingestion + device-list + signed-secret middleware)

- New `app/Http/Middleware/VerifyInternalSecret.php` — compares `X-Internal-Secret` header to `config('services.internal_secret')`; `abort(403, ...)` on mismatch.
- Modify `config/services.php` — add `'internal_secret' => env('INTERNAL_API_SECRET')` (already set in `.env.testing` as `testing-secret`, already used by docker-compose/ai-service/bio-service — reuse the same env var name).
- New `routes/api.php`: `Route::middleware('verify.internal-secret')->prefix('biometric')->group(...)` → `POST /ingest` and `GET /devices`.
- Modify `bootstrap/app.php`: add `api: __DIR__.'/../routes/api.php'` to `withRouting()`; register `$middleware->alias(['verify.internal-secret' => \App\Http\Middleware\VerifyInternalSecret::class])` inside `withMiddleware()`.
- New `app/Http/Controllers/Api/BiometricIngestController.php` — `store(Request $request)`: validates `logs` array (`device_user_id` string, `punched_at` date, `device_serial` string required each), bulk `RawAttendanceLog::insertOrIgnore($rows)` (NOT a per-row `create()` loop — relies on the unique constraint from A for idempotent dedupe), returns `{status: ok, stored: N, duplicates: M}`.
- New `app/Http/Controllers/Api/BiometricDeviceListController.php` — `index()`: `BiometricDevice::where('status','active')->get([...])`, returns `{devices: [...]}` including decrypted `password` (cast auto-decrypts on access/serialize).

## C. Laravel Admin CRUD UI (mirrors Department/Employee pattern exactly)

Verified pattern from `DepartmentController`/`EmployeeController`/`DepartmentPolicy`/`HandleInertiaRequests`/`app-sidebar.tsx`/`employee-form.tsx`:
- Controllers call `$this->authorize(...)` explicitly per method (no constructor middleware).
- `actions(Request $request)` private helper returns `{create, update, delete}` booleans for index-page button gating — this is the only per-button gating mechanism; sidebar `can.*` flags are nav-visibility only (`viewX`).
- Inertia pages live under plural-kebab dirs (`departments/`, not `department/`).
- Foreign-key selects use shadcn `Select`/`SelectItem` with a `NONE` sentinel pattern (see `employee-form.tsx`) when nullable.

New Policies: `BiometricDevicePolicy` (Admin-only for `viewAny/view/create/update/delete` — no HR), `DeviceEnrollmentPolicy` (Admin-only, same shape).

New Controllers:
- `BiometricDeviceController` — index (paginate 15, `withCount('enrollments')`), create, store (validate name/type-in/serial-unique/host/port 1-65535/username/password/status-in), edit, update (only overwrite `password` if the submitted value is non-empty — don't blank out a saved credential on an unrelated field edit), destroy, private `actions()`.
- `DeviceEnrollmentController` — index (paginate 15, `with(['employee','biometricDevice'])`, `through()` to `{id, device_user_id, employee:{fullName}, biometricDevice:{name,serial}}`), create/store (validate `biometric_device_id` exists, `employee_id` exists, `device_user_id` unique scoped to that device via `Rule::unique('device_enrollments')->where('biometric_device_id', ...)`), edit/update (same, excluding current row), destroy, private `formData()` (employees + active devices for the selects) + `actions()`.

Modify `routes/web.php`: add `Route::resource('biometric-devices', ...)->except('show')` and `Route::resource('device-enrollments', ...)->except('show')` inside the existing `auth` group.

Modify `HandleInertiaRequests::share()`: add `'viewBiometricDevices' => $user?->can('viewAny', BiometricDevice::class) ?? false` and `'viewDeviceEnrollments' => $user?->can('viewAny', DeviceEnrollment::class) ?? false` to the `can` array (skip a separate `manageBiometricDevices` sidebar flag — per-button gating is already handled by each controller's `actions()`, matching the existing Department/Employee convention exactly).

Modify `resources/js/components/app-sidebar.tsx`: add two nav items (Biometric Devices → `biometric-devices.index`, gated `can.viewBiometricDevices`; Device Enrollments → `device-enrollments.index`, gated `can.viewDeviceEnrollments`), same `{href, label, active, show}` shape.

New React files (mirror `department-form.tsx` / `departments/{index,create,edit}.tsx` / `employee-form.tsx`'s Select pattern):
- `components/biometric-device-form.tsx` — Input(name), Select(type: stub/zkteco/hikvision), Input(serial), Input(host), Input(port, type=number), Input(username), Input(password, type=password, placeholder "Leave blank to keep current password" in edit mode), Select(status: active/inactive).
- `pages/biometric-devices/{index,create,edit}.tsx` — index table columns: name, type, serial, status, enrollments count, edit/delete (gated by `actions.update`/`actions.delete`); edit's `useForm` seeds `password: ''` always blank regardless of stored value.
- `components/device-enrollment-form.tsx` — Select(biometric_device_id, from `devices` prop), Select(employee_id, from `employees` prop, label = fullName), Input(device_user_id).
- `pages/device-enrollments/{index,create,edit}.tsx` — index columns: device name, employee fullName, device_user_id, edit/delete.

## D. bio-service real driver implementations

Modify `bio-service/app/drivers/zkteco.py`:
- `connect()`: `from zk import ZK` (import inside the method so the base install without `requirements-devices.txt` still works) → `ZK(self.host, port=self.port, timeout=5).connect()`; wrap in try/except → raise `ConnectionError` with context.
- `fetch_logs(since)`: raise `RuntimeError("Driver not connected")` if `self._conn` is None; else `self._conn.get_attendance()`, filter `record.timestamp >= since`, map to `PunchLog(device_user_id=str(record.user_id), punched_at=record.timestamp, device_serial=self.serial)`.
- `disconnect()` / `health()`: keep existing shape, reflect actual connection state.

Modify `bio-service/app/drivers/hikvision.py`:
- `connect()`: `httpx.get(f"http://{host}/ISAPI/System/deviceInfo", auth=httpx.DigestAuth(username, password), timeout=5)` (ISAPI uses Digest auth, not Basic); raise `ConnectionError` on failure; set `self._connected`.
- `fetch_logs(since)`: POST to the ISAPI access-control event-search endpoint with a time-range body; parse `AcsEvent.InfoList[]` → `PunchLog(device_user_id=employeeNoString, punched_at=parsed ISO time, device_serial=self.serial)`; defensive parsing (raise clear `RuntimeError` on unexpected shape, since this can only be verified via mocks).
- `disconnect()`/`health()`: reflect `self._connected`.

No new dependency needed (`httpx.DigestAuth` is built in; `pyzk==0.9` already in `requirements-devices.txt`).

## E. bio-service driver factory + scheduler

- New `app/driver_factory.py`: `build_driver(device: dict) -> BiometricDriver` — dispatches on `device["type"]` to `StubDriver`/`ZKTecoDriver`/`HikvisionDriver`; `ValueError` on unknown type.
- New `app/services/device_registry.py`: `async fetch_active_devices() -> list[dict]` — GET `{laravel_base_url}/api/biometric/devices` with `X-Internal-Secret` header (mirrors `pusher.py`'s POST, symmetric pattern).
- New `app/services/scheduler.py`: module-level `_last_poll: dict[int, datetime]` (per-device-id, in-memory — acceptable for FYP scope, resets harmlessly on restart since Laravel dedupes). `async def poll_job()`: fetch devices (try/except, skip cycle on Laravel-down) → for each device, default lookback 24h on first poll, `build_driver` → `connect()`/`fetch_logs(since)`/`disconnect()` wrapped in a **per-device** try/except (one bad device doesn't block others) → `push_logs(logs)` if non-empty → update `_last_poll[device_id]` only on success. `start_scheduler() -> AsyncIOScheduler` — use **`AsyncIOScheduler`** (not `BackgroundScheduler`) since `poll_job`/`fetch_active_devices`/`push_logs` are all `async def` and need the running FastAPI event loop; `add_job(poll_job, 'interval', seconds=settings.poll_interval_seconds)`, `.start()`.
- Modify `bio-service/main.py`: `@app.on_event("startup")` calls `start_scheduler()` (keep existing `on_event` style for minimal diff vs. migrating to `lifespan`), `@app.on_event("shutdown")` calls `scheduler.shutdown(wait=False)`.

## F. Tests

**bio-service (pytest, mirror existing `test_stub_driver.py`/`test_health.py` style):**
- `tests/test_zkteco_driver.py` — patch `zk.ZK`; test connect success/failure, `fetch_logs` mapping + `since` filtering, `health()` state.
- `tests/test_hikvision_driver.py` — patch `httpx.get`/`httpx.post`; test connect success/failure, `fetch_logs` JSON parsing, malformed-response error handling.
- `tests/test_driver_factory.py` — one case per driver type + unknown-type `ValueError`.
- `tests/test_pusher.py` — mock `httpx.AsyncClient.post`; assert payload shape + `X-Internal-Secret` header.
- `tests/test_scheduler.py` — mock registry/factory/pusher; assert per-device isolation (one failing device doesn't stop the cycle) and `_last_poll` updates only on success. Use `pytest-asyncio` (already a dependency).

**yner_main (PHPUnit + `RefreshDatabase`, mirror `DepartmentTest.php` exactly — this repo does NOT use Pest):**
- `tests/Feature/BiometricIngestTest.php` — valid secret stores logs; missing/wrong secret → 403; duplicate log → deduped (`stored`/`duplicates` counts correct); malformed payload → 422. Use `.env.testing`'s `INTERNAL_API_SECRET=testing-secret`.
- `tests/Feature/BiometricDeviceListTest.php` — requires secret (403 without); returns only active devices; returned `password` matches original plaintext (proves the `encrypted` cast round-trips through JSON).
- `tests/Feature/BiometricDeviceTest.php` — admin can view/create/delete; **HR officer is forbidden** (403) from index/create (the deliberate Admin-only departure from Department's Admin+HR pattern); employee forbidden; stored password differs from plaintext when read directly via `DB::table(...)->value('password')` (proves encryption is active, not just cast-transparent).
- `tests/Feature/DeviceEnrollmentTest.php` — admin can create; duplicate `device_user_id` on the *same* device rejected (422); same `device_user_id` on a *different* device allowed; HR forbidden.

## G. docker-compose.yml / .env.example

No changes — confirmed no new env vars needed per the E design decision (device credentials live in `biometric_devices`, not env).

## Implementation order

1. A (migrations/models) → 2. B (middleware/config/api routes/ingestion+device-list controllers) → 3. C (Admin CRUD: policies → controllers → routes/web.php → HandleInertiaRequests → sidebar → React pages) → 4. D (real drivers, independent, can interleave) → 5. E (factory/registry/scheduler/main.py, depends on B+D) → 6. F (tests, ideally alongside each prior step) → 7. G (no-op verification).

After A: run `php artisan migrate` against the dev DB (tests use `RefreshDatabase` automatically).

## Verification

1. `cd yner_main && php artisan test` — new Feature tests green, especially the dedup + 403 + Admin-only-device-access cases.
2. `cd bio-service && pytest` — new driver/factory/scheduler/pusher tests green alongside existing `test_health.py`/`test_stub_driver.py`.
3. End-to-end manual check: seed a `BiometricDevice(type: stub, serial: STUB-001, status: active)` + `DeviceEnrollment` via the new Admin UI, start the stack (`./start.ps1` or `php artisan eapms:start`), confirm bio-service's scheduler fires within `poll_interval_seconds`, `raw_attendance_logs` gets populated (check via `php artisan tinker` or DB client), re-run the same window and confirm no duplicate rows (idempotency).
4. Hit `GET /api/biometric/devices` and `POST /api/biometric/ingest` manually (curl/Postman) with correct vs. incorrect `X-Internal-Secret` to confirm 200 vs 403.
5. Click through the new Biometric Devices / Device Enrollments pages as Admin (create/edit/delete) and as HR/Employee (confirm 403/hidden nav) in the browser.
