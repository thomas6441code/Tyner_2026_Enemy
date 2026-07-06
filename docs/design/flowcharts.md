# Flowcharts

## 1. Attendance computation (Phase 4)

`App\Console\Commands\ComputeAttendance` (`attendance:compute`, scheduled daily at 01:00 for
"yesterday") drives `AttendanceCalculator` over `raw_attendance_logs` for a date/range. It is
also triggered ad-hoc: `BiometricIngestController::store` dispatches
`ComputeAttendanceForDate` for every distinct punch date in a freshly-ingested batch, so
attendance updates without waiting for the nightly run.

```mermaid
flowchart TD
    Start([Trigger: nightly 01:00\nor fresh ingest])
    A[For each active employee × date in range]
    B{Existing attendance_records\nrow is_manual = true?}
    C[Skip — HR correction is final]
    D[Fetch raw_attendance_logs\nfor employee + date]
    E{Any punches found?}
    F[status = Absent]
    G[first_in = earliest punch\nlast_out = latest punch]
    H[worked_minutes = last_out − first_in]
    I{first_in later than\nwork_schedule.start_time\n+ grace_period_minutes?}
    J[status = Late\nlate_minutes = delta]
    K[status = Present]
    L{last_out before\nwork_schedule.end_time?}
    M[early_leave_minutes = delta]
    N[Upsert attendance_records\nunique on employee_id+work_date]
    End([Done])

    Start --> A --> B
    B -- yes --> C --> End
    B -- no --> D --> E
    E -- no --> F --> N
    E -- yes --> G --> H --> I
    I -- yes --> J --> L
    I -- no --> K --> L
    L -- yes --> M --> N
    L -- no --> N
    N --> End
```

## 2. Approval → Sync (Phase 6 — the core innovation)

The chain that guarantees an HR-approved permission/leave overwrites a false "Absent"/null
record: `PermissionRequestController::review` → `PermissionRequestApproved` event →
`SyncAttendanceForApprovedPermission` listener (`ShouldQueue`) → `AttendanceSyncService`. The
standalone `attendance:sync-permissions` command replays this same logic over all approved
requests for retroactive backfills.

```mermaid
flowchart TD
    Start([HR/Admin approves\na permission request])
    A[PermissionRequestController::review\nstatus → Approved, audit-logged]
    B[event: PermissionRequestApproved]
    C[Listener: SyncAttendanceForApprovedPermission\nShouldQueue — queued or sync-driver inline]
    D[AttendanceSyncService::syncForApproval]
    E[For each date in start_date..end_date]
    F{attendance_records row\nis_manual = true?}
    G[Skip — HR correction outranks overlay]
    H{Real punch present\nstatus in Present/Late?}
    I[Skip — never erase reality]
    J{Already synced to this\nexact status + request_id?}
    K[Skip — idempotent no-op]
    L[Overlay: status = PermissionType→AttendanceStatus\npermission_request_id = request.id\nis_manual = false]
    M[Write audit_logs\naction = attendance.synced]
    N([Next date / Done])

    Start --> A --> B --> C --> D --> E --> F
    F -- yes --> G --> N
    F -- no --> H
    H -- yes --> I --> N
    H -- no --> J
    J -- yes --> K --> N
    J -- no --> L --> M --> N
```

### Status mapping (`PermissionType::toAttendanceStatus`)

| Permission type | Attendance status written |
| --- | --- |
| `official_leave` | Official Leave |
| `sick_leave` | Sick Leave |
| `permission` | Permission Approved |
| `field_duty` | Field Duty |

This mapping is why the AI features (Phase 7) never mistake a synced leave day for
absenteeism — `is_leave` (used by `ai-service` feature engineering) is derived from
`status->isLeave()`, which is true for all four synced statuses.
