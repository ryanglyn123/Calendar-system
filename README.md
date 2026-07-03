# FDCP Calendar System

Internal calendar and resource booking platform for the Film Development Council of the Philippines. Laravel 13, Inertia v3, Vue 3 with TypeScript, and Tailwind CSS 4.

## Features

- Unified calendars: organization-wide defaults, division calendars, unlimited personal calendars, and per-resource calendars, with complete isolation between users and per-user color and visibility preferences.
- Custom calendar UI: month, week, day, and agenda views with drag to create, move, and resize, overlap packing, multi-day spanning bars, keyboard operation, and dark mode.
- Full recurrence (RFC 5545 RRULE) with this, this-and-following, and all-events edit scopes and per-occurrence overrides.
- Attendees with RSVP, per-event reminders (in-app and email), and a queued notification center with per-user channel preferences.
- Resource booking for admin-defined resource types (board rooms, vehicles, and anything else) with approval workflows, edit requests, conflict detection, working hours, holiday blocking, and per-type booking rules.
- Super Admin panel: users, pending registrations, invitations, divisions, roles and permissions, calendars, event types, resource types, resources, booking approvals, change requests, working hours, holidays, announcements, audit log, system health, and typed settings. All configurable without code changes.
- Sign in with password (Fortify, with 2FA and passkeys) or Google (Socialite). Self-registration and unknown Google accounts land in a pending queue for admin approval.

## Requirements

- PHP 8.4+, Composer
- Node.js 22+, npm
- MySQL 8 or MariaDB 10.6+ (tests use in-memory SQLite)
- Mailpit for local email (optional but recommended)

## Local setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate

# create the database named in .env, then:
php artisan migrate --seed

# optional demo data (sample users, events, bookings in every status):
php artisan db:seed --class=DemoSeeder

composer dev
```

`composer dev` runs the PHP server, queue worker, log tail, and Vite together. The app is at http://localhost:8000.

### Seeded accounts

All seeded accounts use the password `password`.

| Email | Role |
|---|---|
| admin@fdcp.gov.ph | Super Admin (base seed) |
| division.admin@fdcp.gov.ph | Division Admin (demo) |
| staff@fdcp.gov.ph | Staff (demo) |
| room.approver@fdcp.gov.ph | Board Room Approver (demo) |
| vehicle.approver@fdcp.gov.ph | Vehicle Approver (demo) |
| pending@fdcp.gov.ph | Pending registration (demo) |

### Google sign-in

Create OAuth 2.0 credentials in the Google Cloud console with the redirect URI `APP_URL/auth/google/callback`, then set `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET` in `.env`. Unknown Google emails create pending accounts that an admin approves. An allowed email domain can be enforced under Admin, Settings.

## Production notes

- Scheduler: add a cron entry running `php artisan schedule:run` every minute. It dispatches due event reminders and prunes expired invitations.
- Queue: run a supervised worker, `php artisan queue:work --tries=3`. Reminders, emails, and in-app notifications are all queued. Failed jobs are visible (and retryable) under Admin, System Health.
- Set `APP_ENV=production`, `APP_DEBUG=false`, a real `MAIL_*` relay, and `SESSION_ENCRYPT=true` behind HTTPS.
- Build assets with `npm run build`.

## Testing and quality gates

```bash
php artisan test          # Pest, in-memory SQLite
vendor/bin/phpstan analyse
vendor/bin/pint --test
npm run types:check       # vue-tsc
npm run lint
npm run build
```

## Architecture notes

- All datetimes are stored in UTC; events keep their creating timezone for recurrence expansion. The app timezone is Asia/Manila.
- Recurring events store the RRULE on the parent row plus override rows per edited occurrence; windows are expanded on the fly, capped at 500 occurrences per event.
- Session-authenticated JSON endpoints live under `/api/v1` inside the web middleware group, so CSRF is enforced everywhere.
- Authorization is permission-based (spatie/laravel-permission). `super_admin` bypasses permission checks via `Gate::before`; business invariants are enforced as explicit controller guards.
- Typed settings (spatie/laravel-settings) are injected into validators and services, so admin-configured rules are enforced by construction.
