# User Acceptance Test (UAT) Script & Requirements Traceability — Phase 11

This script maps **every project objective and AI feature** to (a) a manual acceptance test a
tester can perform in the running system, and (b) the automated test(s) that verify the same
behaviour. It is the Phase 11 deliverable *"UAT script mapping each test to an objective/AI
feature (traceability)"* and doubles as the sign-off record.

- **Objectives**: Obj 1–6 from the proposal.
- **AI features**: 6.6.1–6.6.6.
- Test references use `File::test_name` (Laravel `tests/Feature`, ai-service/bio-service
  `tests/`). Run the suites with the commands in [§ Running the suites](#running-the-suites).

## Environment for manual UAT

1. `cd yner_main && php artisan migrate:fresh --seed` — seeds roles + sample org.
2. Start the stack: `./start.ps1` (or `php artisan eapms:start`) — brings up Laravel + ai-service
   + bio-service.
3. Seeded logins (password `password`): `admin@eapms.test`, `hr@eapms.test`,
   `employee@eapms.test`.

## Traceability matrix

### Objectives

| # | Objective | Manual UAT step | Expected result | Automated coverage |
|---|-----------|-----------------|-----------------|--------------------|
| **Obj 1** | Biometric sign-in/out capture | With the stub device active, run the biometric poller (or `attendance:compute`), open **Attendance**. | Punches appear as `raw_attendance_logs`; daily records compute Present/Late correctly. | `test_stub_returns_logs`, `test_zkteco_driver`, `test_hikvision_driver` (bio-service); `BiometricIngestTest::test_valid_secret_stores_logs`; `AttendanceCalculatorTest::test_late_arrival_is_flagged_with_minutes`, `::test_on_time_employee_is_present` |
| **Obj 2** | Integrate attendance + HR permission | As Employee, submit a permission request; as HR, approve it. | Request lifecycle submit → approve works; notifications fire. | `PermissionRequestTest::test_employee_can_submit_a_request`, `::test_hr_can_approve_and_event_is_dispatched` |
| **Obj 3** | Auto status update on approval (core innovation) | Pick an employee with an **Absent** day. Approve a permission covering that day. Reopen Attendance/Report. | The day flips Absent/null → the approved leave status; no false Absent. | `AttendancePermissionSyncTest::test_approved_permission_flips_a_no_punch_day_to_leave_status`, `::test_approving_via_http_syncs_attendance_end_to_end`, `::test_manual_correction_outranks_the_sync` |
| **Obj 4** | AI analysis & anomaly detection | Run `php artisan ai:score-attendance`; open **AI Insights**. | Anomalies + per-employee risk scores persist and render with explanations. | `ScoreAttendanceCommandTest::test_persists_anomalies_and_predictions`; `AiInsightPageTest::test_admin_can_view_ai_insights`; ai-service `test_injected_outlier_is_flagged`, `test_prediction_shape_and_levels` |
| **Obj 5** | Intelligent notification & reporting | Approve a request / detect an anomaly; check the notification inbox. Open **Reports**. | In-app notification appears; report renders with correct figures. | `NotificationSystemTest::test_permission_submission_notifies_management_and_populates_inbox`, `::test_sign_in_reminder_command_notifies_employees_without_attendance`; `ReportTest::test_admin_can_view_report_page` |
| **Obj 6** | Improve accuracy & efficiency | Generate a monthly report spanning an approved-leave day; export CSV + PDF. | Report counts approved leave as leave (not absent); exports download. | `ReportTest::test_report_counts_approved_leave_as_leave_not_absent`, `::test_csv_export_downloads`, `::test_pdf_export_downloads`; `ReportValidationTest::*` |

### AI features

| # | AI feature | Manual UAT step | Expected result | Automated coverage |
|---|-----------|-----------------|-----------------|--------------------|
| **6.6.1** | Smart attendance analysis | Open **AI Insights**; review per-employee lateness/absence rates + trend. | Rates and trend slope shown per employee. | ai-service `test_employee_features_rates`; `AiInsightPageTest::test_admin_can_view_ai_insights` |
| **6.6.2** | Anomaly detection | Inject an off-hours punch, run scoring, open **AI Insights**. | Day flagged with a human-readable explanation. | ai-service `test_injected_outlier_is_flagged`, **`test_anomaly_detection_precision_recall`** (P/R on labeled sample) |
| **6.6.3** | Predictive monitoring (risk) | Run scoring; review high-risk list on dashboard/Reports. | Risk score + level + top factors per employee. | ai-service `test_prediction_shape_and_levels`, `test_small_data_uses_fallback`, **`test_absenteeism_prediction_precision_recall`** |
| **6.6.4** | Intelligent notifications | Trigger the morning reminder command / a review. | Targeted in-app (+ email-ready) notification. | `NotificationSystemTest::*` |
| **6.6.5** | Automated report summarization (Claude) | On **Report Summaries**, generate a monthly summary. | Narrative + highlights render; with no API key, template fallback (no crash). | `ReportSummaryTest::test_generates_and_persists_a_summary`, `::test_service_unavailable_writes_nothing`; ai-service `test_summary_falls_back_without_api_key`, `test_summary_parses_claude_json` |
| **6.6.6** | Auto status synchronization | (Same as Obj 3.) | Approved permission overwrites attendance status idempotently. | `AttendancePermissionSyncTest::test_sync_is_idempotent`, `::test_sync_covers_every_day_in_a_range`, `::test_real_punch_is_not_overwritten_by_a_leave_overlay` |

### Cross-cutting: security, privacy & integration

| Area | Manual UAT step | Expected result | Automated coverage |
|------|-----------------|-----------------|--------------------|
| **Authorization (authz)** | Log in as each role; attempt to open Departments, Reports, AI Insights, Biometric Devices. | Employee is blocked (403) from management pages; HR blocked from device admin; guest → login. | `AuthorizationMatrixTest::test_route_enforces_role_matrix`, `::test_guests_are_redirected_to_login` |
| **Input validation** | Submit a malformed permission request; pass a bad date / unknown department to Reports. | Validation errors returned; no crash, no unbounded range. | `PermissionRequestTest::test_validation_rejects_bad_input`; `ReportValidationTest::*`; `BiometricIngestTest::test_malformed_payload_is_rejected` |
| **Signed internal calls (secrets)** | Call an internal endpoint without / with a wrong `X-Internal-Secret`. | Rejected with 403. | `BiometricIngestTest::test_missing_secret_is_forbidden`, `::test_wrong_secret_is_forbidden`; ai-service `test_anomalies_requires_secret`, `test_summary_rejects_wrong_secret`; bio-service `test_push_logs_sends_expected_payload_and_header` |
| **Privacy (aggregates-only to LLM)** | N/A (design guarantee). | No employee name/PII leaves the system to Claude. | ai-service `test_summary_request_schema_carries_no_pii_fields`, `test_prompt_builder_ignores_injected_pii`, `test_attendance_record_schema_is_id_and_metrics_only` |
| **3-service integration** | Start all three services; run poller → scoring → summary. | Data flows device → Laravel → AI service and back. | bio-service `test_poll_job_isolates_failing_device_and_updates_last_poll_only_on_success`; `BiometricIngestComputeTest::test_ingest_dispatches_compute_job_per_distinct_date`; `ScoreAttendanceCommandTest::test_returns_failure_and_writes_nothing_when_service_down` (graceful degradation) |
| **ML evaluation metrics** | Review documented precision/recall. | Metrics meet documented floors. | See [`docs/ai/ml-evaluation.md`](../ai/ml-evaluation.md); `test_ml_evaluation.py` |

## Running the suites

```bash
# Laravel (unit + feature)
cd yner_main && php artisan test

# AI service (drivers-agnostic ML + API + evaluation + privacy)
cd ai-service && ./venv/Scripts/python.exe -m pytest tests -q

# Biometric adapter (drivers, scheduler, signed push)
cd bio-service && ./venv/Scripts/python.exe -m pytest tests -q
```

## Latest test run (record actual result at sign-off)

| Suite | Command | Result |
|-------|---------|--------|
| Laravel | `php artisan test` | ____ passed |
| ai-service | `pytest tests -q` | ____ passed |
| bio-service | `pytest tests -q` | ____ passed |

> Recorded baseline at Phase 11 authoring: Laravel **130 passed**, ai-service **25 passed**,
> bio-service **19 passed** (update the row above with the value from the sign-off run).

## Sign-off

By signing below, the tester confirms that the manual UAT steps above were executed against the
running system and produced the **Expected result**, and that the three automated suites pass.

| Role | Name | Signature | Date | Pass / Fail |
|------|------|-----------|------|-------------|
| Tester (Developer) | | | | |
| HR Officer (User rep) | | | | |
| Supervisor | | | | |

**Defects raised during UAT:** _none / see list below_

| # | Description | Severity | Status |
|---|-------------|----------|--------|
| | | | |
