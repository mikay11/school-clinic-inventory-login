# School Clinic Inventory System

A server-rendered PHP 8 + MySQL application for managing school clinic medicines, stock movements, expirations, reports, notifications, and user accounts.

## Features

- Institutional login (@edu.ph) with password verification, CSRF protection, remember-me, idle timeout, and password recovery
- Dashboard with stock, low-stock, and expiration summaries plus activity feed
- Medicine inventory with search, status/category filters, pagination, add/edit/delete
- Stock In and Stock Out with batch-level quantities, validation, and activity logging
- Expiration monitoring with automatic unread alerts
- Reports with four report types, date filters, CSV export, and printable output
- Notifications with mark-as-read actions
- Persisted clinic settings and account/password management

Default account after importing `database.sql`:

| Email | Password |
| --- | --- |
| `nurse@school.edu.ph` | `Clinic@2026` |

Change this password after the first login under **Settings**.

## Local development

Requirements: PHP 7.4+ (8.x recommended) with `pdo_mysql`, and MySQL 5.7+/MariaDB.

1. Create a database named `school_clinic_inventory`.
2. Import `database.sql` (via phpMyAdmin or `mysql -u root -p < database.sql`).
3. Copy `config/config.local.php.example` to `config/config.local.php` and fill in your DB credentials. This file is gitignored and is never uploaded by the deploy workflow.
4. Serve the project root, e.g. `php -S localhost:8080`, then open `http://localhost:8080`.

Configuration precedence: `config/config.local.php` → environment variables → built-in defaults. Available keys are listed in `config/config.local.php.example`.

## Deploy to InfinityFree (free)

### 1. Create the hosting account

1. Sign up at InfinityFree and create a free hosting account/website.
2. Note your free subdomain (for example `yourname.infinityfreeapp.com`).
3. In the control panel, open **MySQL Databases**, create a database, and note the database name, username, host, and password.
4. Open **phpMyAdmin**, select your new database, then import `database.sql`.
   - Before importing, either edit the `CREATE DATABASE` / `USE` lines in `database.sql` to match the database name InfinityFree gave you, or delete those two lines and select the database in phpMyAdmin first.
5. You do **not** need to create `config/config.local.php` manually. The workflow generates it from the GitHub secrets below at deploy time, so credentials never enter the repository.

### 2. Find your FTP details

In the InfinityFree control panel, open **FTP Accounts** (or **File Manager → FTP Details**). You need the FTP server hostname, username, password, and port (usually 21). The remote root directory is normally `./`; adjust the secret below if your files must go into a subfolder.

### 3. Add GitHub repository secrets

In the GitHub repo: **Settings → Secrets and variables → Actions → New repository secret**.

| Secret | Value |
| --- | --- |
| `FTP_SERVER` | FTP hostname from InfinityFree |
| `FTP_USERNAME` | FTP username |
| `FTP_PASSWORD` | FTP password |
| `FTP_PORT` | `21` (or the port you were given) |
| `FTP_PROTOCOL` | `ftp` (use `ftps` only if your account supports it) |
| `FTP_REMOTE_DIR` | `./` (or your site's remote folder, with a trailing slash) |
| `DB_HOST` | MySQL hostname from the control panel, e.g. `sql123.infinityfree.com` |
| `DB_PORT` | `3306` |
| `DB_NAME` | Database name from InfinityFree, e.g. `if0_12345678_school_clinic` |
| `DB_USER` | Database username |
| `DB_PASS` | Database password |
| `APP_BASE_URL` | `https://yourname.infinityfreeapp.com` |
| `APP_TIMEZONE` | `Asia/Manila` |
| `MAIL_FROM` | `no-reply@school.edu.ph` |
| `SESSION_IDLE_MINUTES` | `30` |
| `APP_DEBUG` | `false` |
| `SHOW_RESET_LINK` | `true` (InfinityFree free plan blocks `mail()`, so on-screen recovery links are needed) |

### 4. Push to deploy

Any push to `main` runs a PHP lint job, then syncs the files to your hosting over FTP. You can also trigger it manually with **Actions → Lint and deploy to InfinityFree → Run workflow**.

Visit `https://yourname.infinityfreeapp.com` and log in with the default account.

### 5. Add a custom domain later

In InfinityFree: **Domains → Add Domain**, point your domain's `A` record to the IP shown by InfinityFree (or use their nameservers), then set `APP_BASE_URL` in `config/config.local.php` to `https://your-domain.com`. SSL can be enabled from **SSL/TLS**.

## Password recovery

The recovery form stores a one-hour token and emails a reset link using PHP `mail()`. On hosts where `mail()` is blocked, set `SHOW_RESET_LINK` to `'true'` temporarily in `config/config.local.php`, request a reset, use the on-screen link, then set it back to `'false'`. Do not leave it enabled on a public site.

## Security notes

- Change the seeded password immediately.
- Keep `config/config.local.php` out of git (it is gitignored and excluded from deployment).
- `APP_DEBUG` and `SHOW_RESET_LINK` should stay `false` in production.
- The `.htaccess` file blocks direct access to configuration and documentation files; if your host rejects any directive, remove that single line rather than the whole file.

## Structure

```
auth/          login and logout handlers
config/        configuration and PDO connection
includes/      session, CSRF, layout, and notification helpers
assets/        styles, JavaScript, and logos
*.php          application pages
database.sql   schema, settings, and seed data
.github/       lint + FTP deploy workflow
```
