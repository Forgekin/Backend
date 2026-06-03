# ForgeKin Admin — Developer Guide & Technical Overview

A technical reference for engineers working on the **ForgeKin admin platform**: the
React admin SPA (`ForgekinAdmin`) and the Laravel API (`ForgekinBackend`). It covers
architecture, setup, auth, the data model, conventions, testing, deployment, and
step-by-step recipes for common changes.

> Audience: developers (full-stack, frontend, backend, DevOps). For an end-user/role
> walkthrough see the in-app **User Guide** (`/guide`) or `public/forgekin-admin-handbook.html`.

---

## Table of contents

1. [System architecture](#1-system-architecture)
2. [Repository layout](#2-repository-layout)
3. [Tech stack](#3-tech-stack)
4. [Local setup](#4-local-setup)
5. [Environment variables](#5-environment-variables)
6. [Authentication & authorization](#6-authentication--authorization)
7. [Data model](#7-data-model)
8. [API conventions](#8-api-conventions)
9. [Notifications](#9-notifications)
10. [Email & campaigns](#10-email--campaigns)
11. [Frontend architecture](#11-frontend-architecture)
12. [Coding conventions](#12-coding-conventions)
13. [Testing](#13-testing)
14. [Build & deployment](#14-build--deployment)
15. [Security notes](#15-security-notes)
16. [Recipes (how-to)](#16-recipes-how-to)
17. [Gotchas & troubleshooting](#17-gotchas--troubleshooting)
18. [Reference tables](#18-reference-tables)

---

## 1. System architecture

```
                 ┌─────────────────────────┐
  Admin user ──▶ │  ForgekinAdmin (SPA)     │   React 19 + Vite + Tailwind
                 │  axios + Sanctum token   │   token in localStorage("admin_user")
                 └───────────┬─────────────┘
                             │  HTTPS  Authorization: Bearer <token>
                             ▼
                 ┌─────────────────────────┐
                 │  ForgekinBackend (API)   │   Laravel + Sanctum + Spatie Permission
                 │  /api/* routes           │
                 └───┬──────────┬───────┬───┘
                     │          │       │
                ┌────▼───┐ ┌────▼───┐ ┌─▼──────────┐
                │ MySQL  │ │  SMTP  │ │ Queue/Cron │  (queue & cron optional —
                └────────┘ └────────┘ └────────────┘   sync fallbacks exist)
```

- **ForgekinAdmin** — internal admin SPA (this repo). Talks only to the API via a
  bearer token; all access is also re-enforced server-side.
- **ForgekinBackend** — the Laravel REST API and source of truth. Also serves the
  freelancer and employer apps (separate frontends, not in these repos).
- Stateless token auth (no cookies/CSRF for the API). Notifications are persisted in
  the DB **and** emailed. Background work (campaign sends, scheduled releases) runs
  inline by default with optional queue/cron acceleration.

---

## 2. Repository layout

### `ForgekinAdmin/` (React SPA)

```
src/
  api/api.js                 axios instance (baseURL, token interceptor, 401 handling)
  store/useAuthStore.js      zustand auth store: user, can(), hasRole(), isSuperAdmin()
  config/access.js           PAGE_ACCESS, allowedBy(), NAV_ORDER, firstAllowedPath()
  layouts/DashboardLayout.jsx sidebar + top bar + route guard + idle logout
  components/
    dashboard.jsx            shared UI: DashboardHeader, StatCard, PipelineBar,
                             ViewToggle, SortSelect, Pagination
    SupportNotificationCenter.jsx
  utils/sanitizeHtml.js      DOMPurify wrapper for any HTML rendered via dangerouslySetInnerHTML
  pages/                     Dashboard, Users, JobManagement, AssignedJobs,
                             ServiceRequests, AdminUsers, RolesPermissions,
                             EmailCampaigns, SupportCenter, UserGuide, Settings, Login
  App.jsx                    routes + <Protected> guard + landing redirect
public/
  guide/                     User Guide screenshots (+ README of expected filenames)
  forgekin-admin-handbook.html  standalone distributable handbook
vite.config.js               Vite + Vitest (jsdom) config
```

### `ForgekinBackend/` (Laravel API)

```
app/
  Http/Controllers/          UserController, FreelancerController, EmployerController,
                             JobController, EmailCampaignController, ContactController,
                             NotificationController, RolePermissionController,
                             AdminPerformanceController, ...
  Http/Middleware/           EnsureEmployerIsVerified ('verified'), TriggerDueCampaigns
  Models/                    User, Freelancer, Employer, Job, JobPayment, JobHour,
                             JobReview, FreelancerWithdrawal, ContactMessage,
                             EmailCampaign, Skill, WorkExperience, Shift, FreelancerDocument
  Notifications/             *.php (mail + database channels)
  Jobs/SendEmailCampaign.php queue-ready campaign sender
  Services/CampaignDispatcher.php  single source for dispatching/running campaigns
  Console/Commands/RunDueCampaigns.php  `campaigns:run-due`
  Observers/JobObserver.php  job lifecycle side-effects (notifications)
routes/
  api.php                    all /api routes + middleware
  console.php                scheduler (Schedule::command('campaigns:run-due'))
database/
  migrations/                schema
  seeders/                   RolesAndPermissionsSeeder, SuperAdminSeeder, ...
bootstrap/app.php            middleware aliases (role/permission/role_or_permission/verified)
tests/Feature/               PHPUnit feature tests
phpunit.xml                  sqlite :memory:, array mailer, sync queue
```

---

## 3. Tech stack

| Layer | Tech |
|---|---|
| Admin SPA | React 19, Vite 8, react-router-dom 7, zustand 5, axios, Tailwind v4, lucide-react, dompurify, jspdf |
| API | Laravel (PHP 8.2+), Sanctum (token auth), spatie/laravel-permission, Eloquent |
| DB | MySQL (prod/local), SQLite `:memory:` (tests) |
| Mail | SMTP (prod), `array` mailer (tests) |
| Tests | PHPUnit (backend), Vitest + jsdom (frontend) |

---

## 4. Local setup

### Backend (`ForgekinBackend`)

```bash
composer install
cp .env.example .env          # then edit DB + MAIL settings
php artisan key:generate
php artisan migrate --seed     # runs migrations + DatabaseSeeder
# or seed roles/permissions only:
php artisan db:seed --class=RolesAndPermissionsSeeder
php artisan serve              # http://127.0.0.1:8000
```

- **Seeders:** `RolesAndPermissionsSeeder` (roles + permissions, idempotent
  `firstOrCreate`), `SuperAdminSeeder` (the first Super-Admin account). Re-run the
  roles seeder after adding a permission to the module list.
- **Queue/cron are optional** — see [§10](#10-email--campaigns). Campaign sends run
  inline (`dispatchSync`) and scheduled releases fire from normal API traffic.

### Frontend (`ForgekinAdmin`)

```bash
npm install
npm run dev        # Vite dev server
npm run build      # production build -> dist/
npm run lint       # ESLint (flat config)
npm test           # Vitest (run once)
npm run test:watch # Vitest watch
```

Set `VITE_API_BASE_URL` (see below) so the SPA points at your local API; otherwise it
defaults to the production API.

---

## 5. Environment variables

### Backend `.env`

| Key | Notes |
|---|---|
| `APP_ENV` / `APP_DEBUG` | **Production must be `production` / `false`.** Debug leaks stack traces + secrets. |
| `APP_KEY` | `php artisan key:generate`. |
| `DB_*` | MySQL connection. |
| `MAIL_*` | SMTP. Verify SPF/DKIM for deliverability. |
| `SANCTUM_EXPIRATION` | Token lifetime in minutes (default **480** = 8h). |
| `CORS_ALLOWED_ORIGINS` | Comma-separated prod origins (localhost is always allowed via pattern). |
| `CAMPAIGNS_QUEUE` | `true` → push campaign sends to the queue (needs a worker); default `false` = inline. |
| `CAMPAIGNS_AUTO_TICK` | `false` disables the traffic-driven scheduled-campaign trigger (default on). |

> ⚠️ `CAMPAIGNS_QUEUE` / `CAMPAIGNS_AUTO_TICK` are read via `env()` **outside** config.
> After `php artisan config:cache` they fall back to their hard-coded defaults (queue
> off, auto-tick on). Move them into a `config/` file if you need them tunable in a
> cached prod build.

### Frontend `.env`

| Key | Notes |
|---|---|
| `VITE_API_BASE_URL` | API base, e.g. `http://127.0.0.1:8000/api`. Defaults to `https://api.forgekin.org/api` (`src/api/api.js`). |

---

## 6. Authentication & authorization

### Token flow

- `POST /api/users/login` → `{ token, user }`, where `user` includes `roles` and an
  **effective `permissions`** array (`$user->getAllPermissions()->pluck('name')`).
- The SPA stores `{...user, token}` in `localStorage["admin_user"]`. `src/api/api.js`
  attaches `Authorization: Bearer <token>` on every request and, on a **401 for a
  non-login request**, clears the session and redirects to `/login`.
- Tokens expire after `SANCTUM_EXPIRATION` (8h default). The layout also auto-logs-out
  after 5 minutes of inactivity (`DashboardLayout.jsx`).

### Guard model (important)

Everything is pinned to the **`web`** guard — roles, permissions, user↔role assignment,
and lookups. `auth:sanctum` authenticates the bearer token and promotes `sanctum` to
the request's default guard; the `User` model's default guard name resolves to `web`
(provider match), so Spatie's `permission:`/`role:` checks read the `web`-guard
permissions. **Keep all role/permission creation on `guard_name => 'web'`.**

### Backend enforcement

Middleware aliases (registered in `bootstrap/app.php`): `role`, `permission`,
`role_or_permission` (Spatie) and `verified` (`EnsureEmployerIsVerified`). Examples
from `routes/api.php`:

```php
Route::get('/admin/jobs', [JobController::class,'index'])->middleware('permission:jobs.read');
Route::patch('/admin/jobs/{id}/approve', ...)->middleware('permission:jobs.approve');
Route::patch('/admin/freelancers/{id}/deactivate', ...)->middleware('role:Super-Admin|Admin');
Route::prefix('admin')->middleware(['auth:sanctum','role_or_permission:Super-Admin|Admin|campaigns.manage'])->group(...);
```

**Object-level authorization:** the generic `auth:sanctum` guard accepts any account
type (freelancer/employer/user) and the three tables share an id space, so ownership
checks must assert the **type**, not just a matching id:

```php
// EmployerController::update — correct pattern
if (! auth()->user() instanceof Employer || auth()->id() !== $employer->id) abort(403);
```

This `instanceof` guard is applied across employer/freelancer/job mutation routes.

### Frontend enforcement (defense-in-depth)

- `store/useAuthStore.js` — `isSuperAdmin()`, `hasRole(name)`, `can(perm)` (Super-Admin
  always true; otherwise checks the login `permissions` array).
- `config/access.js` — **single source of truth**: `PAGE_ACCESS` maps each route to a
  rule (`{ perm }`, `{ roles }`, `{ superAdminOnly }`, or `{}` = any authed user).
  `allowedBy(rule, helpers)` evaluates it.
- `App.jsx <Protected path>` gates each route; `DashboardLayout` filters the sidebar
  with the same `allowedBy`; individual action buttons gate with `can('...')`.
- The server independently re-checks every request — **the UI never grants what the
  backend wouldn't.**

### Canonical permission set (`RolesAndPermissionsSeeder`)

```
jobs.{create,read,update,delete,approve,reject,assign}
employers.{read,verify}
campaigns.manage
admin.dashboard
users.{create,read,update,delete}      # defined; System Users page is Super-Admin-only
roles.manage / permissions.manage      # defined; Roles page is Super-Admin-only
(+ "<module>.*" wildcards)
```

Roles: **Super-Admin** (all), **Admin** (jobs.*, employers.read/verify, campaigns.manage,
admin.dashboard). Custom roles are created in the Roles & Permissions UI.

> Note: `users.*`, `roles.manage`, `permissions.manage` exist but are **not enforced**
> by any route (System Users and Roles pages are `role:Super-Admin` only). Treat them as
> reserved/inert until those routes are switched to permission gating.

---

## 7. Data model

Key models (Eloquent). Note **`Job` maps to the `job_postings` table** (`protected $table`).

| Model | Table | Notes |
|---|---|---|
| `User` | `users` | Admin/system accounts. `HasRoles`, `HasApiTokens`, `Notifiable`. `$hidden`: password, remember_token. Returns `permissions` at login. |
| `Freelancer` | `freelancers` | Authenticatable, `Notifiable`, `HasApiTokens`, `HasRoles`. `$appends`: `profile_image_url`. |
| `Employer` | `employers` | Authenticatable, `Notifiable`. `verification_status` (active/inactive). `$appends`: `company_logo_url`. |
| `Job` | `job_postings` | `#[ObservedBy(JobObserver)]`. Relations: `employer`, `assignedFreelancer`, `payments`, `review`, `hourLogs`. status lifecycle: new → pending_approval → approved → assigned → accepted → in_progress → on_hold → done (+ rejected). |
| `JobPayment` | `job_payments` | gross, platform_fee, tax, net, status (paid/pending/refunded/disputed). |
| `JobHour` | `job_hours` | hourly time logs. |
| `JobReview` | `job_reviews` | stars + text. |
| `FreelancerWithdrawal` | — | withdrawal requests/settlements. |
| `EmailCampaign` | `email_campaigns` | broadcasts; see [§10](#10-email--campaigns). |
| `ContactMessage` | `contact_messages` | public "Contact Us" submissions. |
| `Skill`, `WorkExperience`, `FreelancerDocument`, `Shift` | — | freelancer profile data. |

Conventions:
- `$hidden` excludes `password`/tokens and raw storage paths.
- Image URLs are exposed via `$appends` accessors (`profile_image_url`,
  `company_logo_url`) built by `App\Support\StorageUrl` — clients use those, never raw paths.
- Avoid FK constraints where remote-migration fragility is a concern (e.g.
  `email_campaigns.created_by` is an indexed `unsignedBigInteger`, no FK).

---

## 8. API conventions

- All routes are under **`/api`** (`routes/api.php`).
- **Response shape:** `{ "success": true, "data": ... }` or
  `{ "success": false, "message": "..." }`. Validation errors use Laravel's standard
  `422 { message, errors }`.
- **Pagination:** Laravel paginator → the items live at `data.data` (or `data.data.data`
  when the paginator is itself under a `data` key, e.g. `/jobs`). `per_page` is capped
  at **100** on list endpoints (default 10–15). `/users` returns all users (no paginate).
- **Throttling:** login `5,1`; verify/resend/reset `6,1`; contact `5,1`; support `10,1`;
  support-reply `20,1`.
- **Public vs protected:** public reads exist for `GET /freelancers`, `/employers`,
  `/jobs` (and `{id}`). Mutations require `auth:sanctum` (+ `verified` for
  employer/freelancer self-service, or `permission:`/`role:` for admin routes).

---

## 9. Notifications

- Channels: **`database` + `mail`**. All three account models are `Notifiable`.
- `NotificationController::present()` flattens a stored notification to:
  `{ id, type, title, message, url, from, email, body, read, created_at }`.
- **Types** the admin UI understands (`SupportNotificationCenter.jsx`):
  - `broadcast` → opens a read-only HTML reader (body sanitized via DOMPurify).
  - `support_request` → opens a reply modal (emails the sender).
  - `job` / url starting `/jobs` → deep-links to the job.
  - `account` / `employer` → informational / links out.
- Endpoints: `GET /notifications` (`?per_page`, `?filter=unread`), `/unread-count`,
  `POST /{id}/read`, `/read-all`, `DELETE /{id}`.

To add a notification: create `app/Notifications/Foo.php` with
`via() => ['mail','database']` and a `toArray()` returning at least
`type/title/message/url` (add `body` for rich HTML). Send via `$model->notify(...)` or
`Notification::send($models, ...)`.

---

## 10. Email & campaigns

Three email patterns coexist:
1. **Notifications** (`mail` channel) — most transactional email.
2. **Inline `Mail::send([], [], fn ($m) => ...)`** with a branded HTML shell — campaign sends.
3. (Mailables where present.)

**Campaign pipeline** (queue-ready with sync fallback):

```
EmailCampaignController ──▶ CampaignDispatcher::dispatch()/runDue()
                               │
                               ▼
                       SendEmailCampaign (ShouldQueue)
                         • dispatchSync() by default (no worker needed)
                         • dispatch() when CAMPAIGNS_QUEUE=true
                         • emails each recipient + writes a `broadcast` DB notification
```

- `CampaignDispatcher` is the **single source** used by the controller, the
  `campaigns:run-due` command, and the auto-trigger.
- **Scheduled releases without cron:** `TriggerDueCampaigns` middleware (on the `api`
  group) calls `CampaignDispatcher::tick()` in `terminate()`, throttled to once/minute
  via a cache lock — so due campaigns send off normal traffic. With a real cron,
  `Schedule::command('campaigns:run-due')->everyMinute()` (in `routes/console.php`)
  handles it; both are safe together. Admins can also click "Run scheduled".
- Audiences: `freelancers | employers | system_users | everyone` (`EmailCampaign::recipientsFor`).

---

## 11. Frontend architecture

- **Styling:** Tailwind v4. Project utility classes you'll reuse: `card`, `btn-primary`,
  `input-field`, `bg-primary` / `text-primary` / `primary-dark`, `animate-fadeIn`.
- **Shared components** (`src/components/dashboard.jsx`) — use these for consistency:
  `DashboardHeader`, `StatCard`, `PipelineBar`, `ViewToggle` (card/list), `SortSelect`,
  `Pagination` (windowed, "Showing X–Y of N").
- **Page pattern:** fetch a list (often `per_page=100`), then do **search / filter /
  sort / pagination client-side** over the loaded array. KPI tiles often double as
  filters. Card/list views toggle with `ViewToggle` (preference persisted in
  `localStorage`).
- **Modals:** `createPortal` to `document.body`; close on Escape + backdrop click.
- **HTML rendering:** any `dangerouslySetInnerHTML` MUST pass through
  `sanitizeHtml()` (`src/utils/sanitizeHtml.js`, DOMPurify) — campaign bodies/broadcasts
  are author-supplied.
- **State:** `zustand` (`useAuthStore`). No Redux.

### ESLint flat config quirks (important)

- **No `eslint-plugin-react`** is configured. Consequences:
  - JSX usage of a variable doesn't count as "use" for `no-unused-vars` on **function
    args**. Pattern: assign dynamic icon components to a **capitalized local `const`**
    (`const Icon = item.icon`) — `varsIgnorePattern: '^[A-Z_]'` ignores those.
  - `react/...` rule names (e.g. `react/no-danger`) are **not defined** — don't add
    `eslint-disable react/...` comments (they error).
- `react-hooks/set-state-in-effect` errors on `useEffect(() => setX(...), [...])`
  reset patterns in some components — reset state in the event handler instead, or via
  the render-time previous-value pattern.

---

## 12. Coding conventions

- **Access control lives in `config/access.js`** (frontend) and route middleware
  (backend) — don't scatter ad-hoc role checks; reuse `can()/hasRole()/allowedBy()`.
- **Reuse the shared dashboard components** rather than re-implementing headers, stat
  cards, pagination, toggles.
- **Currency:** GHS; display the symbol from the API (`filters.currency_symbol`, `₵`)
  rather than hard-coding.
- Match surrounding comment density and naming. Keep controller responses to the
  `{ success, data|message }` shape.
- Frontend files are `.jsx`, PascalCase components, camelCase helpers.

---

## 13. Testing

### Backend (PHPUnit)

- `phpunit.xml`: `APP_ENV=testing`, SQLite `:memory:`, `MAIL_MAILER=array`,
  `QUEUE_CONNECTION=sync`, `BCRYPT_ROUNDS=4`.
- Tests use `RefreshDatabase`, factories, `Mail::fake()`, and `Laravel\Sanctum\Sanctum::actingAs`
  or Bearer tokens (`$user->createToken('t')->plainTextToken`).
- Roles in tests: `Role::create(['name' => 'Super-Admin'])` (default guard = `web`),
  `$user->assignRole(...)`.

```bash
php artisan test                      # full suite
php artisan test --filter=EmailCampaignTest
```

> There are ~6 **known, pre-existing** failing tests unrelated to feature work
> (file-storage upload paths, a month-boundary date assertion, inactive-employer login).
> Don't treat those as regressions; compare against the baseline before/after a change.

### Frontend (Vitest)

```bash
npm test
```

- `vite.config.js` sets `test.environment = 'jsdom'`. Currently covers
  `src/utils/sanitizeHtml.test.js`. There's no component-render harness yet — add one
  (e.g. `@testing-library/react`) if you start testing components.

---

## 14. Build & deployment

**Frontend:** `npm run build` → `dist/` (static). `public/` (including
`forgekin-admin-handbook.html` and `guide/`) is copied verbatim. Serve `dist/` behind
your web server / CDN. Set `VITE_API_BASE_URL` at build time.

**Backend checklist:**
- `APP_ENV=production`, `APP_DEBUG=false`.
- `php artisan migrate --force` and `php artisan db:seed --class=RolesAndPermissionsSeeder`
  (idempotent — required so `campaigns.manage` and roles exist).
- Set `CORS_ALLOWED_ORIGINS`, `SANCTUM_EXPIRATION`, mail creds.
- Optional perf: a queue worker (`CAMPAIGNS_QUEUE=true` + `php artisan queue:work`) and a
  cron (`* * * * * php artisan schedule:run`). Without them, sync + traffic-driven
  fallbacks apply.
- If you run `php artisan config:cache`, remember the `env()` caveat in [§5](#5-environment-variables).

---

## 15. Security notes

Implemented hardening (see git history / `AccountAuthorizationTest`):
- **BOLA fix:** `instanceof` type checks on employer/freelancer/job ownership routes;
  job `store` forces `employer_id` for employers.
- **Throttling** on verify-email / resend / reset-password.
- **Stored-XSS mitigation:** all author HTML rendered through DOMPurify (`sanitizeHtml`).
- **Dependency:** axios pinned to a patched version (audit clean).

Open items / things to watch:
- The admin **token lives in `localStorage`** → any XSS = token theft. Keep all HTML
  sinks sanitized; be wary of new `dangerouslySetInnerHTML`.
- Ensure **`APP_DEBUG=false`** in production.
- Public `GET /freelancers` & `/employers` expose PII (email/phone/dob) unauthenticated
  — review whether that's intended for your deployment.

---

## 16. Recipes (how-to)

**Add a permission-gated page**
1. Backend: protect the route(s) with `middleware('permission:foo.bar')`; add `foo` to
   the seeder module list and re-seed.
2. Frontend: add `'/foo': { perm: 'foo.bar' }` to `PAGE_ACCESS`, add to `NAV_ORDER`,
   add the `<Route>` in `App.jsx` (`<Protected path="/foo">`), and a menu item in
   `DashboardLayout.jsx` (it's filtered by `allowedBy`).

**Add a permission-gated action button**
- Wrap the control in `{can('foo.bar') && ...}` and ensure the endpoint has the matching
  `permission:` middleware. (UI gate + server gate must agree.)

**Add a list page with filters/pagination**
- Fetch with `?per_page=100`, derive `filtered` via `useMemo`, slice for the current
  page, and render `<Pagination ... />` from `components/dashboard`. Reset the page in
  the filter handlers (not in a `useEffect`).

**Add a notification type** — see [§9](#9-notifications).

**Add a campaign audience** — extend `EmailCampaign::AUDIENCES` and `recipientsFor()`,
plus the frontend `AUDIENCE_META`.

---

## 17. Gotchas & troubleshooting

| Symptom | Cause / fix |
|---|---|
| Permission/role middleware unexpectedly 403s | A role/permission created with a guard other than `web`. Keep everything on `web`. |
| `env()` value ignored in prod | `config:cache` is active; move the var into `config/` or stop caching. |
| Client pagination "misses" records | Lists load up to `per_page=100`; beyond that, switch to server-side pagination. |
| `campaigns.manage` not assignable | Re-run `RolesAndPermissionsSeeder`. |
| Scheduled campaign never sends | No cron + no API traffic. Hit "Run scheduled", run `php artisan campaigns:run-due`, or set up cron. |
| Duplicate route shadowing | Two routes with the same method+URI — the last wins. Keep one handler. |
| ESLint error on `eslint-disable react/...` | No react plugin configured — remove the comment. |
| Admin delete/edit returns 403 on a `/jobs/{id}` route | That's the employer-owned route; admins must use the `/admin/jobs/{id}` route. |

---

## 18. Reference tables

### Pages → path → access

| Page | Path | Access |
|---|---|---|
| Performance Overview | `/` | `admin.dashboard` |
| User Management | `/users` | `employers.read` |
| Job Management | `/jobs` | `jobs.read` |
| Assigned Jobs | `/jobs/assigned` | `jobs.read` |
| Mechanic Requests | `/service-requests` | `jobs.read` |
| System Users | `/admin-users` | Super-Admin only |
| Roles & Permissions | `/roles-permissions` | Super-Admin only |
| Email Campaigns | `/campaigns` | Super-Admin/Admin or `campaigns.manage` |
| Support & Notifications | `/support` | any authed user |
| User Guide | `/guide` | any authed user |
| Settings | `/settings` | any authed user |

### Key endpoints (non-exhaustive)

| Method | Path | Guard |
|---|---|---|
| POST | `/users/login` | public (throttle 5,1) |
| GET | `/admin/performance` | `permission:admin.dashboard` |
| GET/POST/PATCH/DELETE | `/admin/jobs...` | `permission:jobs.*` |
| PATCH | `/admin/employers/{id}/approve|revoke` | `permission:employers.verify` |
| PATCH | `/admin/freelancers/{id}/deactivate|reactivate` | `role:Super-Admin|Admin` |
| * | `/admin/campaigns...` | `role_or_permission:Super-Admin|Admin|campaigns.manage` |
| * | `/roles`, `/permissions`, `/users...` | `role:Super-Admin` |
| GET/POST/DELETE | `/notifications...` | `auth:sanctum` |

---

*Keep this guide in sync when you add pages, permissions, endpoints, or notification
types — it's the onboarding reference for new engineers.*
