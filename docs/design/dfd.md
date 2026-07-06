# Data Flow Diagrams

## Level 0 — Context Diagram

The whole system as a single process, showing its external actors and data stores.

```mermaid
flowchart TB
    Terminals[Biometric Terminals<br/>ZKTeco / Hikvision]
    Employee((Employee))
    HR((HR Officer))
    Admin((Admin))
    Claude[Claude API<br/>external LLM]

    subgraph System[0. EAPMS]
        direction TB
    end

    DB[(MySQL)]

    Terminals -- "raw punch events" --> System
    Employee -- "login / leave requests / view attendance" --> System
    HR -- "employee mgmt / approvals / reports" --> System
    Admin -- "org config / device mgmt" --> System

    System -- "attendance / notifications" --> Employee
    System -- "reports / AI insights / summaries" --> HR
    System -- "everything HR gets, plus config views" --> Admin
    System <--> DB
    System -- "aggregated monthly stats" --> Claude
    Claude -- "narrative summary" --> System
```

## Level 1 — Process Decomposition

Breaks "0. EAPMS" into its three services and the core processes inside `yner_main`.

```mermaid
flowchart TB
    Terminals[Biometric Terminals]
    Employee((Employee))
    HR_Admin((HR Officer / Admin))
    Claude[Claude API]

    subgraph Bio[bio-service — Python/FastAPI]
        P1[1. Poll device via driver<br/>ZKTeco / Hikvision / Stub]
    end

    subgraph Main[yner_main — Laravel, source of truth]
        P2[2. Ingest punches<br/>BiometricIngestController]
        P3[3. Compute daily attendance<br/>AttendanceCalculator]
        P4[4. Permission workflow<br/>PermissionRequestController]
        P5[5. Sync engine<br/>AttendanceSyncService]
        P6[6. Reporting & dashboards<br/>AttendanceReportAggregator]
        P7[7. Notifications<br/>SystemNotification]
    end

    subgraph AI[ai-service — Python/FastAPI]
        P8[8. Anomaly detection<br/>Isolation Forest + z-score]
        P9[9. Risk prediction<br/>RandomForest]
        P10[10. Report summarization<br/>Claude prompt/parse]
    end

    D1[(raw_attendance_logs)]
    D2[(attendance_records)]
    D3[(permission_requests)]
    D4[(ai_anomalies / ai_predictions)]
    D5[(report_summaries)]
    D6[(notifications)]

    Terminals --> P1
    P1 -- "POST /api/biometric/ingest\n(signed)" --> P2
    P2 --> D1
    P2 -- "dispatches ComputeAttendanceForDate" --> P3
    D1 --> P3
    P3 --> D2

    Employee -- "submit request" --> P4
    P4 --> D3
    HR_Admin -- "approve / reject" --> P4
    P4 -- "PermissionRequestApproved event" --> P5
    D3 --> P5
    P5 --> D2
    P5 -- "audit_logs" --> P5

    D2 --> P6
    D3 --> P6
    HR_Admin -- "view / export" --> P6

    D2 -- "nightly batch, aggregates only" --> P8
    D2 -- "nightly batch, aggregates only" --> P9
    P8 --> D4
    P9 --> D4
    D4 --> P6

    P6 -- "monthly aggregates, no PII" --> P10
    P10 <--> Claude
    P10 --> D5
    D5 --> P6

    P4 --> P7
    P8 --> P7
    P9 --> P7
    P10 --> P7
    P7 --> D6
    D6 -- "in-app + email" --> Employee
    D6 -- "in-app + email" --> HR_Admin
```

### Key data-flow guarantees

- **Idempotent ingestion** — `raw_attendance_logs` dedupes on `(device_serial,
  device_user_id, punched_at)`; re-polling never double-counts a punch.
- **Aggregates-only external boundary** — everything crossing into process 10 (Claude) is a
  count/rate/label, never an employee name or raw record (enforced by
  `MonthlyReportAggregator`, audited via `report_summaries.stats`).
- **Signed internal boundary** — every arrow crossing a service subgraph boundary
  (`bio-service` → `yner_main`, `yner_main` → `ai-service`) carries the `X-Internal-Secret`
  header, verified before the request reaches process logic.
