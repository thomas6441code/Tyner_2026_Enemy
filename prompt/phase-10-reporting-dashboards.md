# Phase 10 — Reporting, Dashboards & Decision Support

> Status: **Complete.** Plan approved and executed in one session (Phase-10 scope only).
> Export format decided with the user: **CSV** (Excel-compatible, dependency-free) + **PDF via dompdf**.

## Goal

Deliver the management-facing reporting layer that ties the earlier phases together: permission-aware
attendance reports (monthly + custom range), PDF/CSV export, real analytics charts, and an AI
decision-support surface (high-risk employees + latest narrative). Fixes the two gaps left after
Phase 9: there was no attendance report module, and the Admin dashboard charts ran on placeholder data.

## What was built

### Data layer
- **`app/Services/AttendanceReportAggregator.php`** — detailed, named, per-employee report over a
  date range (Admin/HR only). Reads the same `attendance_records` the Phase 6 sync overwrites, so it
  is **permission-aware for free**: approved leave surfaces as its leave status, never a false Absent.
  Returns `rows`, `totals`, `trend` (daily attendance-rate series, bucketed to ~14 points),
  `statusDistribution`, and `meta`. Distinct from the anonymized `MonthlyReportAggregator` used for
  the LLM.

### Controller + routes
- **`app/Http/Controllers/ReportController.php`** — `index` (Inertia page), `exportCsv`
  (`streamDownload` + `fputcsv`), `exportPdf` (dompdf, landscape A4). Filters: `from`/`to` (default =
  current month; inverted ranges swapped; span capped at 366 days) and `department_id`. Also passes
  **decision-support** props: top-5 high-risk employees (`AiPrediction`) and the latest
  `ReportSummary` matching the month/scope.
- Routes `reports.index`, `reports.export.csv`, `reports.export.pdf` (in the `auth,verified` group).

### Authorization
- **`viewReports` gate** in `AppServiceProvider` (Admin + HR, mirroring `ReportSummaryPolicy`'s
  audience). Controller calls `Gate::authorize('viewReports')`; shared to the client as
  `can.viewReports` via `HandleInertiaRequests` and typed in `resources/js/types/index.d.ts`.

### Frontend
- **`resources/js/pages/reports/attendance.tsx`** — filter bar (from/to/department), KPI `StatCard`s,
  attendance-rate trend (`LineAreaChart`), status-distribution (`BarChart`), AI decision-support panel
  (narrative + high-risk list, linking to Report Summaries / AI Insights), and a per-employee table.
  CSV/PDF export buttons are plain `<a href>` to the export routes with the active query string.
- **`resources/views/reports/attendance-pdf.blade.php`** — server-rendered HTML for dompdf only
  (a documented exception to the "app.blade.php is the only Blade page" rule — it is not a web page).
- **Sidebar**: "Reports" link added to the Activities group (`BarChart3` icon, gated by
  `can.viewReports`).

### Real dashboard analytics + AI widget
- **`DashboardController`** — `attendanceReport()`, `topAttendants()`, `weeklyAbsent()` and today's
  KPI counts now read real `attendance_records` (placeholder sine-wave / fake-percentage generators
  removed). Added `decisionSupport()` → top high-risk employees + 30-day anomaly count.
- **`resources/js/pages/dashboard/admin.tsx`** — renders the AI Decision Support widget (high-risk
  list with risk bars) and an anomalies tile.

## Tests — `tests/Feature/ReportTest.php`

- Admin can view `reports/attendance`; rows carry correct present/absent/leave counts.
- **Core**: an approved-permission day counts as leave, not absent → attendance_rate = 66.7%.
- Employee gets 403 on the page and both export routes.
- CSV export returns `text/csv` attachment containing employee data.
- PDF export returns `application/pdf`.

Full suite: **108 passed / 361 assertions**. `pint` clean, `npm run build` clean.

## Dependency added

- `barryvdh/laravel-dompdf` (v3, auto-discovered) for the PDF export.

## Maps to

Objective 5 (reporting), Objective 6 (accuracy/efficiency), expected outcomes 1–6.
