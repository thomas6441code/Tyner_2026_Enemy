# Redesign UI to match Sage attendance dashboard + modal forms

## Context

The user supplied a reference screenshot of the **"Sage"** SaaS HR product's *Employee
Attendance* dashboard and asked to restyle the EAPMS (`yner_main`) frontend to match it.
Two hard requirements:

1. **Match the look** of the reference: a clean white sidebar with icon nav + light/dark
   toggle, a top bar with a global search + bell/messages + user chip, and a flagship
   **Employee Attendance** page (4 KPI stat cards + a weekly attendance grid with colored
   status pills). Use **dummy data** for attendance (no `attendance_records` table exists
   yet — that is Phase 6) but wire in **real data where it already exists** (employee
   names, departments, KPI counts from the DB).
2. **All create/edit forms open in a modal popup**, never on their own page.

The current frontend (Inertia + React + Tailwind v4 + hand-written shadcn primitives) is
functional but plain: a 56px text-only sidebar, a thin header, and separate
`create.tsx` / `edit.tsx` pages per resource. The reusable `*-form.tsx` body components
are already decoupled from page layout, so they can be dropped straight into dialogs.

## Approach

### 1. Theme / design tokens — `resources/css/app.css`
- Repaint to the Sage palette: app background light gray (`hsl(220 20% 97%)`), white
  cards, **blue primary** (`hsl(217 91% 60%)`), softer borders, `--radius-lg: 0.75rem`.
- Add **class-based dark mode**: `@custom-variant dark (&:where(.dark, .dark *));` plus a
  `.dark { … }` token block. Keep `@theme inline` mapping the `--color-*` vars.
- Add a tiny no-flash theme script in `app.blade.php` (`<head>`) that reads
  `localStorage.theme` and toggles `documentElement.classList`.

### 2. App shell
- **`resources/js/components/use-theme.ts`** (new): `light|dark` state synced to
  `localStorage` + `<html>.dark`.
- **`app-sidebar.tsx`**: white fixed sidebar, brand at top, grouped nav with lucide
  icons (General → Dashboard; Activities → Attendance, Employees; Management →
  Departments, Work Schedules; Devices → Biometric Devices, Device Enrollments), active
  pill = light-blue bg + blue text. Footer = Light/Dark segmented toggle. Keep `can.*`
  gating.
- **`app-header.tsx`**: search input ("Search anything…"), right side bell + messages
  icons, user chip (avatar initials + name + role) → dropdown (Profile, Log Out).
- **`app-layout.tsx`**: recompose — fixed sidebar + scrollable content column; header
  sits inside the content column; gray page bg.

### 3. Flagship Employee Attendance page (the screenshot)
- **`AttendanceController@index`** (new) + `GET /attendance` route (auth, verified).
  Builds: KPI counts (present/late/on-leave/absent — **deterministic dummy** derived from
  real employee count), and a weekly roster: real employees (name, department as "role",
  initials) with **deterministic dummy** per-day hours/status. Falls back to a fixed dummy
  roster if no employees seeded.
- **`resources/js/pages/attendance/index.tsx`** (new): title + subtitle + Download
  button; 4 stat cards w/ icons; search + Filter + date row; filter chips; weekly table
  (Employee col + Sun–Sat) with colored pills (green hours/active, violet leave, red
  absent, amber late). Pills via a small `statusPill()` helper using Tailwind classes.
- Add "Attendance" to the sidebar; make it the default post-login landing is **not**
  changed (keep `/dashboard` role routing).

### 4. Forms → modals (all 5 resources)
Pattern (repeated per resource): the **index page** owns dialog state
`{ open, record }` (record `null` = create). A new thin
**`resources/js/components/<resource>-form-dialog.tsx`** wraps `Dialog` +
`useForm` + the existing `*-form.tsx` body + footer buttons, keyed on
`record?.id ?? 'new'` so it re-inits per row. Submit uses
`post/put(..., { onSuccess: close, preserveScroll: true })`; 422 errors stay in the open
modal via `useForm().errors`. Add a reusable **`confirm-dialog.tsx`** for deletes
(replaces `window.confirm`).

Controllers — **remove `create()`/`edit()`**, keep `store/update/destroy`, and have
`index()` ship the data the modals need:
- `EmployeeController@index`: add per-row editable fields (`first_name`, `last_name`,
  `phone`, `hire_date`, ids, dept/schedule names, user email) + `formData()`
  (departments, workSchedules, unlinkedUsers).
- `DeviceEnrollmentController@index`: add `biometric_device_id`, `employee_id` to rows +
  `formData()` (employees, devices).
- `BiometricDeviceController@index`: map rows to explicit fields (id,name,type,serial,
  host,port,username,status,enrollments_count) — **also fixes an existing leak** of the
  `encrypted` password into props.
- `Department` / `WorkSchedule` index already expose all editable fields — no data change.
- Routes: `routes/web.php` → add `->except(['create','edit'])` to the 5 resources
  (employees keeps `show`). Delete the now-dead `pages/**/create.tsx` & `edit.tsx`.
- Employee **View** becomes a read-only modal on the index (keep `show` route too).

### 5. Restyle existing pages to the new shell
- Index pages (employees, departments, work-schedules, biometric-devices,
  device-enrollments): card title row + "New" button (opens modal), restyled table, flash
  toast strip, search field (client filter is fine / decorative where not wired).
- Role dashboards (`dashboard/admin|hr|employee`): KPI cards restyled to the new look
  (data unchanged). Profile page left functional (settings, not a CRUD modal).

### 6. Shared types — `resources/js/types/index.d.ts`
- Add optional `role` label to header use; keep existing `can.*`.

## Critical files
- `resources/css/app.css`, `resources/views/app.blade.php`
- `resources/js/layouts/app-layout.tsx`, `components/app-sidebar.tsx`, `components/app-header.tsx`
- `components/use-theme.ts` (new), `components/confirm-dialog.tsx` (new),
  `components/<resource>-form-dialog.tsx` (new ×5)
- `pages/attendance/index.tsx` (new) + `app/Http/Controllers/AttendanceController.php` (new)
- `routes/web.php`, the 5 resource controllers, the 5 `pages/*/index.tsx`
- delete: `pages/{employees,departments,work-schedules,biometric-devices,device-enrollments}/{create,edit}.tsx`

## Verification
- `npm run build` (Vite) must compile clean (TS types included).
- If PHP/MySQL available: `php artisan serve` + `npm run dev`, log in as
  `admin@eapms.test` / `password`, visit `/attendance` (see cards + grid), open New/Edit
  modals on each resource, submit + validation-error paths, toggle light/dark. Capture a
  screenshot via preview tools. Otherwise rely on the production build + manual code review.
