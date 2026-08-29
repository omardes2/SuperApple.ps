# Production Deployment & Go-Live Checklist

A step-by-step, tick-box checklist for taking SuperApple ERP live. Commands are
listed here; full context is in `docs/DEPLOYMENT.md`. Sample server configs are
in `docs/deploy/`. Nothing here contains secrets.

Related docs: [DEPLOYMENT.md](DEPLOYMENT.md) · [BACKUP_RESTORE.md](BACKUP_RESTORE.md) ·
[deploy/env.production.sample](deploy/env.production.sample) ·
[deploy/nginx.conf.sample](deploy/nginx.conf.sample) ·
[deploy/whatsapp-golive.md](deploy/whatsapp-golive.md)

---

## A. BEFORE DEPLOY (server preparation)

- [ ] **Server**: Linux, a web server (Nginx), and PHP-FPM installed.
- [ ] **PHP 8.3+ (8.4 recommended)** with extensions:
      `ctype, dom, fileinfo, filter, hash, iconv, json, libxml, mbstring,
      openssl, pcre, phar, session, tokenizer, xml, xmlwriter` **plus `pdo_mysql`**
      for MySQL. Recommended for math performance: `bcmath` or `gmp`.
      Verify: `php -m` and `composer check-platform-reqs`.
- [ ] **Composer 2.x** installed.
- [ ] **Node 20+ / npm** — only if you build assets on the server (else build in CI).
- [ ] **MySQL 8.x** running.
- [ ] **Create the database + user** (charset `utf8mb4`, collation `utf8mb4_unicode_ci`):
      ```sql
      CREATE DATABASE superapple CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
      CREATE USER 'superapple'@'localhost' IDENTIFIED BY '<STRONG-PASSWORD>';
      GRANT ALL PRIVILEGES ON superapple.* TO 'superapple'@'localhost';
      FLUSH PRIVILEGES;
      ```
- [ ] **Deploy user** (e.g. `www-data`) owns the app directory.

## B. DEPLOY (first release)

- [ ] **Clone + checkout**:
      ```bash
      sudo git clone https://github.com/omardes2/SuperApple.ps.git /var/www/superapple
      cd /var/www/superapple
      git checkout claude/creative-agency-erp-crm-2j7oz8
      ```
- [ ] **.env**: `cp docs/deploy/env.production.sample .env` then fill it.
      Confirm `APP_ENV=production`, `APP_DEBUG=false`, and do **not** set
      `APP_ALLOW_DEMO_SEED`.
- [ ] **APP_KEY**: `php artisan key:generate` (never committed).
- [ ] **Dependencies**: `composer install --no-dev --optimize-autoloader`.
- [ ] **Assets**: `npm ci && npm run build` (or ship `public/build` from CI).
- [ ] **Permissions** (no `chmod 777`):
      ```bash
      sudo chown -R www-data:www-data storage bootstrap/cache
      sudo find storage bootstrap/cache -type d -exec chmod 775 {} \;
      sudo find storage bootstrap/cache -type f -exec chmod 664 {} \;
      ```
- [ ] **Storage link**: `php artisan storage:link`.
- [ ] **BACKUP the DB** if it is not brand-new (see BACKUP_RESTORE.md).
- [ ] **Migrate**: `php artisan migrate --force`.
- [ ] **Seed foundational data only** (NO demo): `php artisan db:seed --class=ProductionSeeder --force`.
- [ ] **Optimise**: `php artisan optimize` (config + route + view cache).
- [ ] **Web server**: install `docs/deploy/nginx.conf.sample` (root → `public/`), reload Nginx.
- [ ] **HTTPS**: obtain a certificate (Certbot) and enable HTTP→HTTPS redirect.
- [ ] **Queue worker**: install `docs/deploy/supervisor-queue.conf.sample`
      (or the systemd unit) and start it.
- [ ] **Scheduler cron**: install `docs/deploy/scheduler-cron.sample` (one line, every minute).

## C. AFTER DEPLOY (verify — nothing destructive)

- [ ] `bash scripts/verify-production.sh` → all checks pass, exit 0. It runs:
  - [ ] `php artisan about`
  - [ ] `php artisan app:health-check` → **exit 0**
  - [ ] `php artisan app:verify-integrity` → **exit 0**
  - [ ] `php artisan schedule:list` shows `subscriptions:bill` and `payments:send-reminders`
  - [ ] `php artisan queue:failed` (should be empty)
- [ ] **Accounting integrity** (also covered by the checks above): Trial Balance
      and Balance Sheet balanced; AR / AP / Cash / Salary Payable / Employee
      Advances reconciliations pass — **on a fresh DB these pass trivially (0 = 0)**.
- [ ] **Queue is live**: `php artisan queue:work --once` returns without error, or
      confirm the worker process is running (`supervisorctl status` / `systemctl status`).
- [ ] **Scheduler is live**: confirm the cron ran (check `scheduler.log` after a minute).
- [ ] **Error pages**: `APP_DEBUG=false`; visit a bad URL → styled Arabic 404; a
      forbidden page → 403. No stack traces.

## D. BEFORE USERS (first-run configuration)

- [ ] **First administrator** (secure, no weak passwords): `php artisan app:create-admin`.
- [ ] **Company settings**: name, logo, phone, WhatsApp, address, tax number,
      invoice terms, invoice footer.
- [ ] **Work schedule + payroll settings** reviewed.
- [ ] **Exchange rate**: record the current **USD→ILS** rate before issuing any
      invoice (never guess it).
- [ ] **Financial accounts**: create the real Main Cash (ILS), bank account(s),
      and any USD account — do not rely on demo accounts.
- [ ] **Opening balances** (if the company has prior balances): enter them through
      the accounting **opening-balance workflow** — never by editing
      `current_balance` and never as fake payments. Existing customer receivables
      likewise go through a documented opening-balance process (see DEPLOYMENT.md),
      not fabricated payments. Do this only with real figures.
- [ ] **Roles & permissions** reviewed for the real staff (Permission Matrix screen).
- [ ] **Remove any temporary test user** created for smoke testing.

## E. SMOKE TEST (manual, non-polluting)

Log in and open each page (viewing does not create data): Dashboard, Customers,
Projects, Tasks, Attendance, Quotations, Invoices, Payments, Expenses,
Suppliers, Payroll, Subscriptions, Notifications, Reports, Users. If you must
test a financial flow, use a clearly-named test customer and **cancel/reverse**
it properly (cancelling a payment restores the invoice balance and reverses the
GL) rather than leaving fake transactions.

## F. WHATSAPP (optional — only when going live with it)

- [ ] Follow `docs/deploy/whatsapp-golive.md`. The app works with WhatsApp
      **disabled**; enable a real provider only after entering credentials in
      env/secure config and sending a successful manual test message.

---

## Ongoing: updates & rollback

- **Update a release**: `bash scripts/deploy-production.sh` (maintenance mode →
  composer/npm → `migrate --force` → cache rebuild → `queue:restart` → up), then
  `bash scripts/verify-production.sh`.
- **Rollback**: `git checkout <previous-good-commit>`, `composer install
  --no-dev --optimize-autoloader`, restore the DB from the pre-deploy backup if a
  migration must be undone (do **not** run `migrate:rollback` blindly on
  production), rebuild caches, `php artisan queue:restart`. See DEPLOYMENT.md.

## Monitoring after go-live

Laravel logs (`storage/logs`), queue failures (`php artisan queue:failed`),
scheduler execution (`scheduler.log`), disk space, database backups, WhatsApp
failures (WhatsApp dashboard), and HTTP 500s.
