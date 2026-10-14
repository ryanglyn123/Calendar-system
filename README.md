# FDCP Calendar System

Internal calendar and resource booking platform for the Film Development Council of the Philippines. Laravel 13, Inertia v3, Vue 3 with TypeScript, and Tailwind CSS 4.

## Features

- Unified calendars: organization-wide defaults, division calendars, unlimited personal calendars, and per-resource calendars, with complete isolation between users and per-user color and visibility preferences.
- Custom calendar UI: month, week, day, and agenda views with drag to create, move, and resize, overlap packing, multi-day spanning bars, keyboard operation, and dark mode.
- Full recurrence (RFC 5545 RRULE) with this, this-and-following, and all-events edit scopes and per-occurrence overrides.
- Attendees with RSVP, per-event reminders (in-app and email), and a queued notification center with per-user channel preferences.
- Resource booking for admin-defined resource types (board rooms, vehicles, and anything else) with approval workflows, edit requests, conflict detection, working hours, holiday blocking, and per-type booking rules.
- Super Admin panel: users, pending registrations, invitations, divisions, roles and permissions, calendars, event types, resource types, resources, booking approvals, change requests, working hours, holidays, announcements, audit log, system health, and typed settings. All configurable without code changes.
- Sign in with FDCP SSO (fdcp-accounts). Email and password (Fortify, with 2FA) is for administrators only. Self-registration lands in a pending queue for admin approval.

## Requirements

- PHP 8.4+ with extensions: bcmath, ctype, curl, dom, fileinfo, gd, intl, mbstring, mysql (pdo_mysql), opcache, sqlite3 (for tests), xml, zip
- Composer 2
- Node.js 22+, npm
- MySQL 8 or MariaDB 10.6+ (tests use in-memory SQLite)
- Mailpit for local email (optional but recommended)

## Local setup (step by step)

Works the same on Windows, macOS, and Linux. On Windows, get PHP + Composer + Node the easy way with [Laravel Herd](https://herd.laravel.com/windows), and MySQL via the MySQL Installer or XAMPP.

1. **Clone and enter the project**

   ```bash
   git clone <repository-url> Calendar-system
   cd Calendar-system
   ```

2. **Install dependencies**

   ```bash
   composer install
   npm install
   ```

3. **Create your environment file and app key**

   ```bash
   cp .env.example .env        # Windows PowerShell: copy .env.example .env
   php artisan key:generate
   ```

4. **Create the database**, then make sure `.env` matches your MySQL credentials (`DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`):

   ```sql
   CREATE DATABASE fdcp_calendar CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

5. **Migrate and seed** (roles, permissions, divisions, calendars, event types, PH holidays, resources, and the super admin account):

   ```bash
   php artisan migrate --seed
   ```

6. **Optional demo data** (sample users for every role, a month of events, bookings in every status):

   ```bash
   php artisan db:seed --class=DemoSeeder
   ```

7. **Run the app**

   ```bash
   composer dev
   ```

   This starts the PHP server, queue worker, log tail, and Vite together. Open http://localhost:8000 and sign in as `admin@fdcp.gov.ph` / `password`.

8. **Optional: local email.** Run [Mailpit](https://mailpit.axllent.org/) and set `MAIL_MAILER=smtp`, `MAIL_PORT=1025` in `.env`; sent mail appears at http://localhost:8025. The default `.env` uses `MAIL_MAILER=log` (mail goes to `storage/logs/laravel.log`).

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

### FDCP SSO

Staff sign in through FDCP SSO (the `fdcp-accounts` app). On the SSO server, register the calendar as a client:

```bash
php artisan app:register-client calendar --redirect="https://YOUR-CALENDAR-DOMAIN/auth/fdcp/callback"
```

Put the printed client ID and secret in `.env` as `FDCP_ACCOUNTS_CLIENT_ID` and `FDCP_ACCOUNTS_CLIENT_SECRET`, and set `FDCP_ACCOUNTS_URL` to the SSO address. In FDCP Accounts, give each person access to "FDCP Calendar". On their first sign-in the calendar links their existing account by email, or creates a staff account. Roles stay managed here, under Admin, Users. Signing out of the calendar also signs out of FDCP SSO.

Email and password sign-in works only for accounts that can open the admin panel, so administrators can still get in if SSO is down.

## Deploying to Ubuntu on AWS (step by step)

Tested layout: one EC2 instance (Ubuntu 24.04 LTS, t3.small or larger) running nginx + PHP-FPM + MySQL. For a managed database, point `DB_HOST` at an RDS MySQL instance instead of installing MySQL locally and skip step 3.

1. **Launch the instance.** Ubuntu 24.04 LTS AMI. In the security group, allow inbound 22 (SSH, your IP only), 80, and 443. Associate an Elastic IP and point your DNS record (e.g. `calendar.fdcp.gov.ph`) at it. Then connect:

   ```bash
   ssh -i your-key.pem ubuntu@<elastic-ip>
   ```

2. **Install PHP 8.4, nginx, Composer, Node 22**

   ```bash
   sudo apt update && sudo apt upgrade -y
   sudo add-apt-repository ppa:ondrej/php -y
   sudo apt install -y nginx php8.4-fpm php8.4-cli php8.4-mysql php8.4-mbstring \
       php8.4-xml php8.4-curl php8.4-zip php8.4-intl php8.4-bcmath php8.4-gd \
       php8.4-sqlite3 php8.4-opcache unzip

   # Composer
   curl -sS https://getcomposer.org/installer | php
   sudo mv composer.phar /usr/local/bin/composer

   # Node.js 22
   curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
   sudo apt install -y nodejs
   ```

3. **Install MySQL and create the database** (skip if using RDS):

   ```bash
   sudo apt install -y mysql-server
   sudo mysql_secure_installation
   sudo mysql
   ```

   ```sql
   CREATE DATABASE fdcp_calendar CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'fdcp'@'localhost' IDENTIFIED BY 'a-strong-password';
   GRANT ALL PRIVILEGES ON fdcp_calendar.* TO 'fdcp'@'localhost';
   FLUSH PRIVILEGES;
   ```

4. **Deploy the code**

   ```bash
   sudo mkdir -p /var/www/calendar && sudo chown ubuntu:ubuntu /var/www/calendar
   git clone <repository-url> /var/www/calendar
   cd /var/www/calendar
   composer install --no-dev --optimize-autoloader
   npm ci && npm run build
   ```

5. **Configure the environment**

   ```bash
   cp .env.example .env
   php artisan key:generate
   nano .env
   ```

   Set at minimum:

   ```ini
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://calendar.fdcp.gov.ph
   DB_DATABASE=fdcp_calendar
   DB_USERNAME=fdcp
   DB_PASSWORD=a-strong-password
   SESSION_ENCRYPT=true
   MAIL_MAILER=smtp        # plus your real MAIL_HOST/PORT/USERNAME/PASSWORD
   ```

6. **Migrate, seed, and set permissions**

   ```bash
   php artisan migrate --seed --force
   sudo chown -R www-data:www-data storage bootstrap/cache
   ```

   Do NOT run `DemoSeeder` in production. Sign in as `admin@fdcp.gov.ph` / `password` and change the password immediately.

7. **nginx server block** — create `/etc/nginx/sites-available/calendar`:

   ```nginx
   server {
       listen 80;
       server_name calendar.fdcp.gov.ph;
       root /var/www/calendar/public;

       index index.php;
       charset utf-8;
       client_max_body_size 10m;

       location / {
           try_files $uri $uri/ /index.php?$query_string;
       }

       location ~ \.php$ {
           include snippets/fastcgi-php.conf;
           fastcgi_pass unix:/run/php/php8.4-fpm.sock;
       }

       location ~ /\.(?!well-known).* {
           deny all;
       }
   }
   ```

   ```bash
   sudo ln -s /etc/nginx/sites-available/calendar /etc/nginx/sites-enabled/
   sudo rm -f /etc/nginx/sites-enabled/default
   sudo nginx -t && sudo systemctl reload nginx
   ```

8. **HTTPS with Let's Encrypt**

   ```bash
   sudo apt install -y certbot python3-certbot-nginx
   sudo certbot --nginx -d calendar.fdcp.gov.ph
   ```

9. **Queue worker as a systemd service** — reminders, emails, and in-app notifications are queued and will not send without it. Create `/etc/systemd/system/calendar-queue.service`:

   ```ini
   [Unit]
   Description=FDCP Calendar queue worker
   After=network.target mysql.service

   [Service]
   User=www-data
   Group=www-data
   Restart=always
   RestartSec=3
   ExecStart=/usr/bin/php /var/www/calendar/artisan queue:work --tries=3 --max-time=3600

   [Install]
   WantedBy=multi-user.target
   ```

   ```bash
   sudo systemctl daemon-reload
   sudo systemctl enable --now calendar-queue
   ```

10. **Scheduler cron** — dispatches due event reminders and prunes expired invitations:

    ```bash
    sudo crontab -u www-data -e
    # add:
    * * * * * cd /var/www/calendar && php artisan schedule:run >> /dev/null 2>&1
    ```

11. **Cache for speed**

    ```bash
    php artisan optimize        # caches config, routes, views, events
    ```

12. **Updating a running deployment**

    ```bash
    cd /var/www/calendar
    php artisan down
    git pull
    composer install --no-dev --optimize-autoloader
    npm ci && npm run build
    php artisan migrate --force
    php artisan optimize
    sudo systemctl restart calendar-queue    # workers must reload new code
    php artisan up
    ```

## Performance

Local development on Windows has three structural bottlenecks worth knowing about:

- **Enable OPcache in your php.ini** (`zend_extension=opcache`, `opcache.enable=1`, and for dev `opcache.revalidate_freq=0` so code edits apply immediately). Without it every request recompiles the entire framework; measured here it cuts per-request time about 3x under `artisan serve`. Restart `composer dev` after changing php.ini.
- `php artisan serve` (what `composer dev` runs) handles **one request at a time on Windows**; PHP's built-in server cannot fork workers there. The app minimizes concurrent requests (calendar pages inline their initial data, and month windows fetch as one spanning request), but for a much faster dev loop use [Laravel Herd](https://herd.laravel.com/windows) (nginx + PHP-FPM + OPcache) and point it at this folder instead of `artisan serve`.
- Keeping the repository inside a OneDrive-synced folder slows every request: PHP stats thousands of files per request, and OneDrive plus Windows Defender intercept each one. Move the project outside OneDrive if you can, or at least add an exclusion for the folder in Windows Security and pause OneDrive sync while developing.

In production, `composer install --optimize-autoloader`, `php artisan optimize`, and OPcache (steps above) are what make it fast. Settings are cached (`SETTINGS_CACHE_ENABLED=true`) and each user's accessible-calendar set is cached for five minutes; both invalidate automatically on change.

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
