# User Manual

Task-oriented walkthroughs, one per role. Every feature described here maps to a real page
under `resources/js/pages/` and a real controller/policy — nothing hypothetical.

| Role | Manual |
| --- | --- |
| Employee | [employee.md](employee.md) |
| HR Officer | [hr-officer.md](hr-officer.md) |
| Admin | [admin.md](admin.md) |

Each role's manual builds on the one before it — HR Officer has everything Employee has,
plus more; Admin has everything HR Officer has, plus more. See
[`docs/design/use-case-diagram.md`](../design/use-case-diagram.md) for the full actor/permission
matrix.

## Seeded logins (local/dev only)

Created by `php artisan migrate --seed`. Password is `password` for all three.

| Role | Email |
| --- | --- |
| Admin | `admin@eapms.test` |
| HR Officer | `hr@eapms.test` |
| Employee | `employee@eapms.test` |

## Getting started (any role)

1. Navigate to the app URL (`http://localhost:8000` in local dev) — you're redirected to
   `/login`.
2. Sign in with your email and password.
3. You land on `/dashboard`, which renders a different view per role (`dashboard/admin`,
   `dashboard/hr`, or `dashboard/employee`).
4. The left sidebar shows only the sections your role can access — it's driven by the same
   `can.*` permission flags the server computed for your account, so it never shows a page
   you'd be denied.
