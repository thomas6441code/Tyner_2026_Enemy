# Seed 3 months of demo data for 24 employees

## Goal

Seed realistic test data so every dashboard, report, and AI panel shows real
computed data instead of empty tables or static placeholders — enough for a
full end-to-end walkthrough of the app as if it were a real 24-person org.

## Scope investigated first

Read the seeder chain (`DatabaseSeeder` → `RoleSeeder`, `OrgSeeder`,
`AttendanceSeeder`, `PermissionSeeder`), the models/enums for
`AttendanceRecord`, `PermissionRequest`, `AiPrediction`, `AiAnomaly`, the
`AttendanceCalculator` and `AttendanceSyncService` pipelines, and the
`DashboardController` / `ReportController` / `AiInsightController` consumers
to confirm exactly which tables each dashboard panel reads before writing
anything. Confirmed feature tests use `RefreshDatabase` + build their own
fixtures (only `RoleSeeder` is ever seeded in tests), so expanding these
seeders is safe.

## Plan (executed)

1. **`OrgSeeder`** — expand from 5 to 24 employees across the existing 5
   departments (Finance/ICT/HR/Registry/Academics, ~5 each), staggered hire
   dates. Keep `EMP-0001` linked to the seeded `employee@eapms.test` login.

2. **`AttendanceSeeder`** — generate ~3 months of raw punches (weekdays only)
   for all 24 employees, run them through the real `AttendanceCalculator` (not
   hand-crafted `attendance_records` rows) so the full pipeline is exercised.
   Give each employee a deterministic behavior profile (reliable / average /
   at-risk) via a fixed RNG seed so lateness/early-leave/absence odds vary
   realistically across the roster. Compute only weekdays — computing
   weekends would score everyone "Absent" on Sat/Sun and skew the
   weekly-absence radar and attendance-rate charts.

3. **`PermissionSeeder`** — spread ~16 permission/leave requests (approved /
   pending / rejected) across 11 employees and the 3-month window instead of
   just one employee. Run every approved request through
   `AttendanceSyncService::syncForApproval()` explicitly (seeders write rows
   directly so the approval event never fires) so the Absent → leave-status
   sync is visibly exercised on `/attendance` and in reports.

4. **`AiInsightSeeder`** (new) — derive `ai_predictions` and `ai_anomalies`
   from the real seeded attendance history, matching the exact shape
   `ai:score-attendance` persists (`risk_score`, `risk_level`, `top_factors`,
   `method`, `score`, `explanation`, `features`). This lets the Admin
   dashboard's AI decision-support panel and the `/ai-insights` page show real
   numbers without the Python `ai-service` running. Risk thresholds
   (high ≥ 0.14, medium ≥ 0.07) were tuned against the actual seeded
   distribution so the roster always yields a handful of each level.

5. Register `AiInsightSeeder` in `DatabaseSeeder`.

## Verification performed

- `php artisan migrate:fresh --seed --force` end to end.
- Tinker scripts confirming: 24 employees, ~3,000 raw punches, ~1,600
  attendance records spanning 2026-04-06 → 2026-07-06, a believable status
  mix (present/late/absent + 6 synced leave records), 24 AI predictions
  (3 high / 7 medium / 14 low), 23 anomalies, 16 permission requests.
- Rendered `DashboardController@index` directly as the admin user and
  inspected the actual Inertia props: attendance-rate chart (92–100% across
  10 buckets), department bar chart, weekly-absence radar (confirmed
  Sat/Sun = 0 after the weekend-compute fix), top attendants, and the
  high-risk AI panel — all populated with real, varied values.
- `./vendor/bin/pint database/seeders/` clean; `php artisan test
  --filter=DashboardTest` passes (3/3).

## Files touched

- `yner_main/database/seeders/OrgSeeder.php`
- `yner_main/database/seeders/AttendanceSeeder.php`
- `yner_main/database/seeders/PermissionSeeder.php`
- `yner_main/database/seeders/AiInsightSeeder.php` (new)
- `yner_main/database/seeders/DatabaseSeeder.php`
