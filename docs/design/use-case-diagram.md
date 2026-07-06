# Use-Case Diagram

Actors and use cases are derived directly from `routes/web.php`, the controllers, and the
policies that gate them (`DepartmentPolicy`, `EmployeePolicy`, `WorkSchedulePolicy`,
`PermissionRequestPolicy`, `BiometricDevicePolicy`, `DeviceEnrollmentPolicy`,
`AttendanceRecordPolicy`, `ReportSummaryPolicy`, `AiAnomalyPolicy`) plus the `viewReports` Gate.

```mermaid
graph LR
    Employee((Employee))
    HR((HR Officer))
    Admin((Admin))

    subgraph EAPMS
        UC1[View own attendance]
        UC2[Submit permission / leave request]
        UC3[Track own request status]
        UC4[View / manage notifications]
        UC5[Manage own profile]

        UC6[Manage employees]
        UC7[Review / approve / reject permission requests]
        UC8[Correct attendance record]
        UC9[View reports + export CSV/PDF]
        UC10[Generate AI report summary]
        UC11[View AI Insights - anomalies / risk]

        UC12[Manage departments]
        UC13[Manage work schedules]
        UC14[Manage biometric devices]
        UC15[Manage device enrollments]
    end

    Employee --> UC1
    Employee --> UC2
    Employee --> UC3
    Employee --> UC4
    Employee --> UC5

    HR --> UC1
    HR --> UC4
    HR --> UC5
    HR --> UC6
    HR --> UC7
    HR --> UC8
    HR --> UC9
    HR --> UC10
    HR --> UC11

    Admin --> UC1
    Admin --> UC4
    Admin --> UC5
    Admin --> UC6
    Admin --> UC7
    Admin --> UC8
    Admin --> UC9
    Admin --> UC10
    Admin --> UC11
    Admin --> UC12
    Admin --> UC13
    Admin --> UC14
    Admin --> UC15
```

## Actor summary

| Actor | Can do | Cannot do |
| --- | --- | --- |
| **Employee** | View own attendance grid, submit/edit/cancel own *pending* permission requests, view own notifications, edit own profile | See other employees' data, review/approve requests, correct attendance, access reports/AI pages, manage departments/schedules/devices |
| **HR Officer** | Everything an Employee can on their own scope, **plus**: full employee CRUD (`EmployeePolicy` — create/update; delete is Admin-only), review/approve/reject any permission request, manually correct attendance, view reports + export, generate AI summaries, view AI Insights | Delete an employee, manage departments/work-schedules/biometric devices/enrollments (Admin-only per `DepartmentPolicy`/`WorkSchedulePolicy`/`BiometricDevicePolicy`) |
| **Admin** | Everything HR can, **plus**: department CRUD, work-schedule CRUD, biometric device CRUD, device-enrollment CRUD, delete employees | — (full system access) |

Note: `PermissionRequestPolicy::review` and `::update` are additionally time-gated — a
request can only be edited by its owner or reviewed by HR/Admin while `status = pending`.
