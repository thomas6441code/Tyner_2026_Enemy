# System Design (Phase 1 / Phase 12)

This folder holds the design artifacts the FYP proposal requires (use-case diagram, ERD,
DFD, flowcharts, architecture diagram) plus a short requirements recap. They were backfilled
in Phase 12 against the system **as actually built**, not as originally proposed — every
diagram here is traceable to real routes, migrations, and services in the codebase.

## Index

| Artifact | File |
| --- | --- |
| Requirements recap | this file |
| Use-case diagram | [use-case-diagram.md](use-case-diagram.md) |
| Entity-Relationship Diagram | [erd.md](erd.md) |
| Data Flow Diagrams (L0/L1) | [dfd.md](dfd.md) |
| Flowcharts (attendance compute, approval → sync) | [flowcharts.md](flowcharts.md) |
| System architecture + tech-stack justification | [architecture.md](architecture.md) |

Diagrams use [Mermaid](https://mermaid.js.org/) fenced code blocks, which render natively on
GitHub/GitLab and in most Markdown previewers (including VS Code's built-in preview) — no
external diagramming tool or export step is required to keep them current.

## Functional requirements (objectives 1–6)

| # | Objective | Delivered by |
| --- | --- | --- |
| 1 | Biometric sign-in/out capture, vendor-independent | Phase 3 (`bio-service`, `BiometricDriver` interface) + Phase 4 (attendance computation) |
| 2 | Integrate biometric attendance with HR permission/leave management | Phase 5 (permission workflow) + Phase 6 (sync engine) |
| 3 | Automatically update attendance status on permission approval | Phase 6 (`AttendanceSyncService`) |
| 4 | AI-driven analysis and anomaly detection over attendance | Phase 7 (`ai-service` — Isolation Forest, z-score, RandomForest) |
| 5 | Intelligent notifications and reporting for management | Phase 9 (notifications) + Phase 10 (reports/dashboards) |
| 6 | Improve the accuracy/efficiency of attendance record-keeping | Phase 4, 6, 10, 11 (computation, sync, reporting, QA) |

## AI feature requirements (6.6.1–6.6.6)

| # | Feature | Delivered by |
| --- | --- | --- |
| 6.6.1 | Smart attendance analysis | `ai-service/app/ml/features.py` — lateness/absence rates, trend slope |
| 6.6.2 | Anomaly detection | `ai-service/app/ml/anomaly.py` — Isolation Forest + per-employee z-score |
| 6.6.3 | Predictive monitoring | `ai-service/app/ml/prediction.py` — RandomForest absenteeism/lateness risk score |
| 6.6.4 | Intelligent notifications | Laravel database notifications (`SystemNotification`) + scheduled sign-in reminder |
| 6.6.5 | Automated report summarization | `ai-service/app/llm/summarizer.py` — Claude API over aggregated stats |
| 6.6.6 | Automatic attendance ⇄ permission status synchronization | `AttendanceSyncService` (Phase 6, the project's core innovation) |

## Non-functional requirements

- **Security** — RBAC via `spatie/laravel-permission` (Admin / HR Officer / Employee),
  policy-gated controllers, signed (`X-Internal-Secret`) internal REST calls between the
  three services, CSRF-protected forms (Laravel default).
- **Privacy** — only aggregated, non-PII statistics are ever sent to the external Claude API
  (see [architecture.md](architecture.md) and `docs/ai/explainability.md`); the exact payload
  sent is persisted in `report_summaries.stats` as an audit trail.
- **Reliability** — the AI/Claude paths degrade gracefully (rule-based fallback scoring,
  template narrative) rather than failing the request when a Python service or the Claude API
  is unreachable.
- **Idempotency** — biometric ingestion dedupes on `(device_serial, device_user_id,
  punched_at)`; the permission→attendance sync and AI scoring commands are safe to re-run.
- **Auditability** — every attendance correction, permission decision, and sync overlay is
  recorded in `audit_logs`.

See [`development_plan.md`](../../development_plan.md) at the project root for full phase-by-phase
detail and the tech-stack justification table.
