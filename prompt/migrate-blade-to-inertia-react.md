# Migrate EAPMS frontend: Blade → Inertia.js + React + TypeScript + Tailwind v4 + shadcn/ui

## Context

Phase 2 built the entire EAPMS frontend in Blade + Bootstrap 5 (auth, dashboards, Department/Employee/WorkSchedule CRUD, profile — 45 Blade files total, listed below). The user finds the Bootstrap/Blade UI unimpressive and wants to switch to Laravel's official **React starter kit** stack instead: Inertia.js + React + TypeScript + Tailwind CSS v4 + shadcn/ui (Radix primitives + `class-variance-authority`) + lucide-react icons. Bootstrap is dropped entirely — no dual-framework CSS. Base font stack: `system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica`.

This is a full frontend rewrite, not an incremental tweak: every controller currently returning a Blade `view()` switches to `Inertia::render()`, every Blade view/partial/component becomes a `.tsx` page or component, and the Blade-only authorization pattern (`@can` directives in views) is replaced by shared Inertia props computed once in middleware.

**Decision: do NOT run `php artisan breeze:install react`.** That installer rewrites `routes/web.php`, `routes/auth.php`, and the Auth controllers from its own stub — on a from-scratch app that's fine, but here it would clobber the custom Department/Employee/WorkSchedule/Dashboard routes and controllers already built. CLAUDE.md already documents a near-identical gotcha for `breeze:install blade` resetting the Bootstrap swap; same risk applies here, just bigger blast radius. Instead, Inertia/React/Tailwind/shadcn are grafted onto the existing app by hand: same routes, same controllers (just swapping the render call), same policies.

## Current state (from exploration)

- **Routes:** `routes/web.php` (dashboard, departments, employees, work-schedules, profile — all under `auth` middleware) and `routes/auth.php` (Breeze's standard login/register/password-reset/email-verification routes). Full route list confirmed during exploration — no changes to route paths/names, only to what each action returns.
- **Controllers returning views:** `DashboardController`, `DepartmentController`, `EmployeeController`, `WorkScheduleController`, `ProfileController`, and everything in `app/Http/Controllers/Auth/` (`AuthenticatedSessionController`, `RegisteredUserController`, `PasswordResetLinkController`, `NewPasswordController`, `ConfirmablePasswordController`, `EmailVerificationPromptController`, `PasswordController`). Each currently does `return view('x.y', [...])`; props passed are already enumerated (e.g. `EmployeeController::formData()` returns `departments`, `workSchedules`, `unlinkedUsers`).
- **Authorization:** Policies (`DepartmentPolicy`, `EmployeePolicy`, `WorkSchedulePolicy`) are unchanged — still called via `$this->authorize(...)` in every controller method (Laravel 12's bare `Controller` base class doesn't support `authorizeResource()`/constructor middleware, per existing CLAUDE.md note). What changes is `resources/views/layouts/sidebar.blade.php`'s `@can('viewAny', Model::class)` directives — these become a `can` object shared from a new `HandleInertiaRequests` middleware and read in a React `Sidebar` component.
- **Views to replace (45 files):** layouts (`app`, `guest`, `navigation`, `sidebar`), 6 auth pages, 3 dashboards, Department/Employee/WorkSchedule CRUD (index/create/edit/show + partial forms), profile (edit + 3 partials), 11 reusable Blade components (buttons, inputs, dropdown, modal, nav-link, etc.), `welcome.blade.php`.
- **Build tooling today:** `vite.config.js` only has `laravel-vite-plugin` over `resources/css/app.css` + `resources/js/app.js`; `package.json` has `bootstrap` + `@popperjs/core` as the only frontend deps; `app.css` imports Bootstrap's CSS; `app.js` just wires up `window.bootstrap`. `bootstrap/app.php` has empty `withMiddleware()`/`withExceptions()` hooks — easy to add Inertia middleware there.
- **Tests:** Only `DashboardTest.php` uses `assertViewIs`/`assertViewHas` (3 assertions, for `dashboards.admin`/`dashboards.hr`/`dashboards.employee`). Other Feature tests (`DepartmentTest`, `EmployeeTest`, `ProfileTest`, `Auth/*`) mostly use `assertOk`/`assertRedirect`/DB assertions, which keep working — only the few view-specific assertions need to change to `assertInertia(fn (Assert $page) => $page->component('...'))`.

## Target stack & package additions

**Composer (backend):**
- `inertiajs/inertia-laravel` — Inertia adapter (also brings the `assertInertia()` test macro)
- `tightenco/ziggy` — generates a `route()` helper usable from React/TS, matching the official kit's navigation pattern

**npm (frontend), replacing `bootstrap`/`@popperjs/core`:**
- `react`, `react-dom`
- `@inertiajs/react`
- `@vitejs/plugin-react`
- `typescript`, `@types/react`, `@types/react-dom`, `@types/node`
- `tailwindcss` (v4) + `@tailwindcss/vite`
- `class-variance-authority`, `clsx`, `tailwind-merge` (shadcn utility deps)
- `@radix-ui/react-slot`, `@radix-ui/react-label`, `@radix-ui/react-dialog`, `@radix-ui/react-dropdown-menu`, `@radix-ui/react-select`, `@radix-ui/react-avatar`, `@radix-ui/react-checkbox` (one Radix package per shadcn primitive actually used — Button/Card/Input/Label/Table/Badge/Dialog/DropdownMenu/Select/Textarea/Avatar covers every current Blade component)
- `lucide-react` — icons
- `ziggy-js` (JS-side companion to `tightenco/ziggy`)

shadcn primitives are hand-written from the well-known shadcn source (not via `npx shadcn add`, to avoid depending on network/registry access during this session) into `resources/js/components/ui/`.

**CSS:** `resources/css/app.css` becomes a single `@import "tailwindcss";` plus a `@theme inline { ... }` block defining shadcn's CSS-variable design tokens (background/foreground/primary/border/radius etc., light theme only — no dark mode requested) and the font stack the user specified. Bootstrap import and `.sidebar` custom CSS are removed (replaced by Tailwind utility classes directly in the components).

## Execution plan (ordered)

**1. Backend/build scaffolding**
- `composer require inertiajs/inertia-laravel tightenco/ziggy`
- Remove `bootstrap`/`@popperjs/core` from `package.json`; add the npm packages above
- Create `app/Http/Middleware/HandleInertiaRequests.php` (extends `Inertia\Middleware`), share:
  - `auth.user` (id, name, email, `roles` array of role names from `RoleName` enum values)
  - `can.viewDepartments` / `can.viewEmployees` / `can.viewWorkSchedules` (policy `viewAny` checks, same ones currently used in the Blade sidebar)
  - `flash.status` (from `session('status')`, used today by `with('status', ...)` redirects)
  - `ziggy` (route list for the JS `route()` helper)
- Register the middleware + Ziggy's `HandleInertiaRequests::class` group in `bootstrap/app.php`'s `withMiddleware()`
- Add `tsconfig.json` (paths alias `@/*` → `resources/js/*`, matching the official kit)
- Rewrite `vite.config.js`: add `@vitejs/plugin-react` and `@tailwindcss/vite` plugins alongside `laravel-vite-plugin`; entry point becomes `resources/js/app.tsx`
- Rewrite `resources/css/app.css` as described above
- Create `resources/js/app.tsx` — standard Inertia React bootstrap (`createInertiaApp`, resolves pages from `resources/js/pages/**/*.tsx`, mounts via `createRoot`)
- Create `resources/js/lib/utils.ts` (the shadcn `cn()` helper: `clsx` + `tailwind-merge`)
- Hand-write shadcn primitives into `resources/js/components/ui/`: `button.tsx`, `card.tsx`, `input.tsx`, `label.tsx`, `table.tsx`, `badge.tsx`, `dialog.tsx`, `dropdown-menu.tsx`, `select.tsx`, `textarea.tsx`, `avatar.tsx`

**2. Shared layout/navigation (replaces `layouts/*.blade.php` + nav/sidebar)**
- `resources/js/layouts/app-layout.tsx` (replaces `layouts/app.blade.php` — header slot + sidebar + main content area)
- `resources/js/layouts/guest-layout.tsx` (replaces `layouts/guest.blade.php` — centered card for auth pages)
- `resources/js/components/app-sidebar.tsx` (replaces `layouts/sidebar.blade.php` — reads `can.*` shared props instead of `@can`, active-route highlighting via Inertia's `usePage()` instead of `request()->routeIs()`)
- `resources/js/components/app-header.tsx` (replaces `layouts/navigation.blade.php` — user dropdown via shadcn `DropdownMenu`, logout via Inertia `router.post(route('logout'))`)

**3. Auth pages** (replaces `resources/views/auth/*.blade.php`, controllers in `app/Http/Controllers/Auth/*` switch `view()` → `Inertia::render()` with the same data they already validate/pass)
- `pages/auth/login.tsx`, `register.tsx`, `forgot-password.tsx`, `reset-password.tsx`, `confirm-password.tsx`, `verify-email.tsx`
- `pages/welcome.tsx` (replaces `welcome.blade.php`)

**4. Dashboards** (replaces `resources/views/dashboards/*.blade.php`; `DashboardController::index` keeps its three role branches, swaps `view(...)` for `Inertia::render('dashboard/admin', [...])` etc. with identical prop shapes already enumerated above)
- `pages/dashboard/admin.tsx`, `hr.tsx`, `employee.tsx`

**5. CRUD modules** (replaces `departments/*`, `employees/*`, `work-schedules/*`, `profile/*`; same pattern per module — controller swaps `view()`→`Inertia::render()` with unchanged prop shapes, index pages get a shadcn `Table`, create/edit share a form component, validation errors come through Inertia's automatic `errors` prop instead of `$errors`/`@error`)
- `pages/departments/{index,create,edit}.tsx` + `components/department-form.tsx`
- `pages/employees/{index,create,edit,show}.tsx` + `components/employee-form.tsx`
- `pages/work-schedules/{index,create,edit}.tsx` + `components/work-schedule-form.tsx`
- `pages/profile/edit.tsx` + partial components for update-profile-info / update-password / delete-user forms

**6. Cleanup**
- Delete `resources/views/` entirely except `resources/views/app.blade.php` (the one root template Inertia needs — create it: `<div id="app" data-page="{{ json_encode($page) }}"></div>` style root, following Inertia's standard root view)
- Remove `bootstrap`/`@popperjs/core` references everywhere (already done in step 1's `package.json` edit — just confirm no leftover imports)
- Update `tests/Feature/DashboardTest.php`: replace `assertViewIs('dashboards.admin')` → `assertInertia(fn (Assert $page) => $page->component('dashboard/admin'))` (and same for hr/employee), add `use Inertia\Testing\AssertableInertia as Assert;`
- Spot-check `DepartmentTest`, `EmployeeTest`, `ProfileTest`, `Auth/*` tests for any view-name assumptions (`assertViewIs`/`assertSee` tied to Blade markup) that need updating to Inertia equivalents
- Update `CLAUDE.md`: replace the "Frontend is Bootstrap 5, not Tailwind" section with the new stack (Inertia + React + TS + Tailwind v4 + shadcn/ui, no Bootstrap), update "Auth is Laravel Breeze (blade stack)" → "(React/Inertia stack)", update "Dashboard is role-routed" to reference Inertia component names instead of Blade view names
- Update `README.md` similarly if it references Bootstrap/Blade

## Verification

- `php artisan test` — full PHPUnit suite must pass (38 existing tests, after the assertion updates in step 6)
- `npm run build` — confirms Vite/TS/Tailwind compile cleanly with no type errors
- `npm run dev` + `php artisan serve`, then manually exercise: login as each seeded user (`admin@eapms.test` / `hr@eapms.test` / `employee@eapms.test`, password `password`), confirm role-correct dashboard renders, confirm sidebar shows only permitted links per role, run through one full CRUD cycle (create/edit/delete a Department) to confirm Inertia form submission + validation errors + flash message all work
- `./vendor/bin/pint` — PHP style check on touched controllers/middleware
