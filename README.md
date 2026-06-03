# ForgeKin Backend

The REST API backend for **ForgeKin**, a freelance-marketplace platform. It serves three
audiences — **freelancers**, **employers**, and **admins** (Super-Admin / Admin) — covering
account registration & verification, job posting and the full job lifecycle (post → approve →
assign → accept → in progress → done), email notifications, dashboards, and email campaigns.

Built with **Laravel 12** on **PHP 8.2+**, authenticated with **Laravel Sanctum** (bearer
tokens), authorized with **spatie/laravel-permission** (roles & permissions), and documented
with **Scribe**.

---

## Tech stack

| Concern | Choice |
|---|---|
| Framework | Laravel 12 (PHP `^8.2`) |
| Auth | Laravel Sanctum (token-based) |
| Roles / permissions | spatie/laravel-permission |
| API docs | Scribe (`knuckleswtf/scribe`) |
| Default DB | SQLite (configurable to MySQL) |
| Queue / cache / sessions | `database` driver (default) |
| Frontend assets | Vite + Tailwind CSS v4 |
| Code style | Laravel Pint |
| Tests | PHPUnit |

---

## Prerequisites

- **PHP 8.2+** with the usual Laravel extensions (`mbstring`, `openssl`, `pdo`, `tokenizer`,
  `xml`, `ctype`, `json`, `bcmath`, `fileinfo`, plus `pdo_sqlite` or `pdo_mysql`)
- **Composer**
- **Node.js + npm** (only needed if you work on the Vite/Tailwind assets or the Scribe docs UI)

---

## First-time setup

```bash
# 1. Install PHP dependencies
composer install

# 2. Install JS dependencies (optional — only for asset/doc work)
npm install

# 3. Create your env file and generate the app key
cp .env.example .env
php artisan key:generate

# 4. (SQLite default) create the database file
#    On Windows PowerShell use:  New-Item database/database.sqlite
touch database/database.sqlite

# 5. Run migrations and seed baseline data (roles, shifts, super-admin, sample freelancers)
php artisan migrate --seed

# 6. Link the storage dir so uploaded images (avatars, logos, documents) are publicly served
php artisan storage:link

# 7. Serve the app
php artisan serve
```

The API is then available at `http://127.0.0.1:8000` (all routes are under `/api`, e.g.
`http://127.0.0.1:8000/api/jobs`).

### Default seeded super-admin

`SuperAdminSeeder` creates a login you can use immediately (change these in production):

- **Email:** `superadmin@example.com`
- **Password:** `password123`

Authenticate via `POST /api/users/login` to receive a Sanctum bearer token.

---

## Environment configuration

The defaults in `.env.example` run against **SQLite** with the **`log`** mail driver (emails are
written to `storage/logs/laravel.log` instead of being sent). Adjust as needed:

### Database (switch to MySQL)
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=forgekin
DB_USERNAME=root
DB_PASSWORD=
```

### Mail (real SMTP, e.g. Gmail)
```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=465
MAIL_USERNAME=you@example.com
MAIL_PASSWORD="app-password"
MAIL_FROM_ADDRESS="you@example.com"
MAIL_FROM_NAME="ForgeKin"
```
> Tip: keep `MAIL_MAILER=log` locally to inspect rendered emails in the log without sending them.

### App-specific variables
| Variable | Purpose | Default |
|---|---|---|
| `ADMIN_NOTIFICATION_EMAIL` | Mailbox that receives admin alerts (new employer registrations, etc.). Read via `config('app.admin_email')`. | `ito12.techaide@gmail.com` |
| `FRONTEND_URL` | Base URL used to build links inside notification emails (`config('app.frontend_url')`). | _(set this for correct email links)_ |

> Money shown in emails and dashboards is denominated in **GHS** (Ghanaian Cedi).

---

## Running the app

### Quick start (single process)
```bash
php artisan serve
```

### Full dev environment (server + queue + logs + Vite, all at once)
```bash
composer run dev
```
This runs `php artisan serve`, `php artisan queue:listen`, `php artisan pail` (live logs), and
`npm run dev` concurrently.

### Background workers
The queue, cache, and sessions all use the **`database`** driver. If you dispatch queued work,
run a worker:
```bash
php artisan queue:work        # process jobs continuously
php artisan queue:listen      # same, but reloads code each job (dev-friendly)
```

---

## Common Artisan commands

### Application lifecycle
```bash
php artisan key:generate              # generate APP_KEY (run once after copying .env)
php artisan serve                     # start the dev server on :8000
php artisan storage:link             # symlink public/storage -> storage/app/public (uploads)
php artisan optimize                  # cache config, routes, events for production
php artisan optimize:clear           # clear all caches (config, route, view, event, compiled)
php artisan about                    # environment & package overview
```

### Database & seeding
```bash
php artisan migrate                          # run pending migrations
php artisan migrate --seed                   # migrate, then run DatabaseSeeder
php artisan migrate:fresh --seed             # DROP all tables, re-migrate, re-seed (destructive)
php artisan migrate:rollback                 # roll back the last migration batch
php artisan migrate:status                   # list migrations and their state

php artisan db:seed                          # run DatabaseSeeder (all seeders below)
php artisan db:seed --class=RolesAndPermissionsSeeder   # roles + permissions only
php artisan db:seed --class=SuperAdminSeeder            # the super-admin account only
php artisan db:seed --class=ShiftSeeder                 # shift reference data
php artisan db:seed --class=FreelancerSeeder            # sample freelancers
```
`DatabaseSeeder` runs, in order: `RolesAndPermissionsSeeder` → `ShiftSeeder` →
`SuperAdminSeeder` → `FreelancerSeeder`.

### Caching (clear individually when config/routes change)
```bash
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear
php artisan route:list                # inspect all registered routes
```

### Queue & scheduler
```bash
php artisan queue:work                # run a queue worker
php artisan queue:failed              # list failed jobs
php artisan queue:retry all           # retry all failed jobs
php artisan schedule:run              # run due scheduled tasks once (cron should call this every minute)
php artisan schedule:list             # show the schedule
```

### Project-specific commands
```bash
# Send any scheduled email campaigns whose send-time has arrived.
# Also scheduled to run every minute via the scheduler (routes/console.php),
# and triggered by normal API traffic as a fallback.
php artisan campaigns:run-due

# Clear profile/logo image paths that are (legacy) shared by more than one account.
php artisan images:cleanup-duplicates --dry-run        # preview only, no changes
php artisan images:cleanup-duplicates                  # clear the duplicate path references
php artisan images:cleanup-duplicates --delete-files   # also delete the orphaned files from storage
```

### API documentation (Scribe)
```bash
php artisan scribe:generate           # (re)generate API docs from route annotations
```
Docs are then served at **`/docs`** (e.g. `http://127.0.0.1:8000/docs`). Regenerate after
changing route annotations / docblocks.

### Tinker / REPL
```bash
php artisan tinker                    # interactive REPL against the app
```

---

## Testing & code style

```bash
php artisan test                      # run the test suite (PHPUnit)
composer run test                     # clears config, then runs the suite

./vendor/bin/pint                     # auto-format code to Laravel style
./vendor/bin/pint --test              # check formatting without modifying files
```

---

## Roles & permissions

Two seeded roles (both on the `web` guard):

- **Super-Admin** — full access, including user/role/permission management.
- **Admin** — job & employer moderation (create/read/update/delete/approve/reject/assign jobs,
  read & verify employers, manage campaigns, view the admin dashboard) but **not** user/role
  management.

Permissions follow a `module.action` convention, e.g. `jobs.approve`, `jobs.assign`,
`employers.verify`, `campaigns.manage`, `admin.dashboard`. Routes are guarded with
`permission:` / `role:` middleware (see `routes/api.php`).

---

## Project layout

```
app/
├── Console/Commands/      # campaigns:run-due, images:cleanup-duplicates
├── Http/Controllers/      # API controllers (Jobs, Freelancers, Employers, Users, Campaigns, ...)
├── Models/                # Eloquent models (Job, Freelancer, Employer, User, EmailCampaign, ...)
├── Notifications/         # Email + database notifications (job lifecycle, accounts, ...)
├── Observers/             # JobObserver — emails employer/admins on job status changes
├── Mail/                  # Mailables (verification code, password reset)
└── Support/               # Helpers (e.g. StorageUrl for building public upload URLs)

database/
├── migrations/            # Schema
└── seeders/               # RolesAndPermissions, SuperAdmin, Shift, Freelancer

routes/
├── api.php                # All API endpoints (prefixed /api)
└── console.php            # Scheduler definitions (campaigns:run-due every minute)

config/                    # app.php (admin_email, frontend_url), scribe.php, permission.php, ...
resources/views/emails/    # Blade templates for emails
```

### Notifications & emails

Most user-facing email lives in `app/Notifications/` (Laravel notifications using the `mail`
and/or `database` channels). The `JobObserver` centrally reacts to job **status changes** and
emails the relevant employer, the admin team, and — when a freelancer accepts — a dedicated
admin alert. To preview emails locally without sending, set `MAIL_MAILER=log` and read
`storage/logs/laravel.log`.

---

## Production notes

- Set `APP_ENV=production`, `APP_DEBUG=false`, and a strong unique `APP_KEY`.
- Configure real `DB_*` and `MAIL_*` values, plus `ADMIN_NOTIFICATION_EMAIL` and `FRONTEND_URL`.
- Run `php artisan migrate --force` and `php artisan optimize` on deploy.
- Ensure `php artisan storage:link` has been run so uploaded files resolve.
- Add a cron entry so the scheduler (and thus due email campaigns) fires reliably:
  ```cron
  * * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
  ```
- Run a persistent queue worker (e.g. via Supervisor): `php artisan queue:work`.
```
