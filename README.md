# School Clinic Inventory System

A React + Vite single-page app with a PHP 8 / MySQL JSON API. It manages school clinic medicines, stock movements, expirations, reports, notifications, and user accounts.

## Features

- Institutional login (`@edu.ph`) with CSRF protection, remember-me, idle timeout, and password recovery
- Dashboard with stock, low-stock, and expiration summaries plus an activity feed
- Medicine inventory with search, filters, pagination, add/edit/delete, and batch details
- Stock In and Stock Out with batch validation and activity logging
- Expiration monitoring with automatic unread alerts
- Four report types, date filters, CSV export, and printable output
- Notifications and account/clinic settings

Default account after importing `database.sql`:

| Email | Password |
| --- | --- |
| `nurse@school.edu.ph` | `Clinic@2026` |

Change this password after the first login under **Settings**.

## Tech stack

| Layer | Technology |
| --- | --- |
| Frontend | React 18, React Router, Vite |
| Backend | PHP 8.2 JSON API (`api/index.php`) |
| Database | MySQL / MariaDB |
| Hosting | Static React build + PHP API on InfinityFree |

Repository layout:

```
src/             React components, pages, API client, styles
public/          static files copied into dist/ (SPA .htaccess)
api/             PHP JSON API, config, includes, legacy PHP pages
assets/          original logos and legacy CSS/JS
database.sql     schema, settings, and seed data
.github/         lint + FTP deploy + GitHub Pages workflows
CONVERSION.md    step-by-step record of the PHP-to-React conversion
```

## Local development

Requirements: Node 18+, PHP 8.2 with `pdo_mysql`, and MySQL 5.7+/MariaDB.

1. Create a database named `school_clinic_inventory` and import `database.sql`.
2. Copy `api/config/config.local.php.example` to `api/config/config.local.php` and fill in your DB credentials. This file is gitignored.
3. Start the API server from the project root: `php -S localhost:8080`.
4. In another terminal, run `npm install` then `npm run dev`.
5. Open the Vite URL (usually `http://localhost:5173`). Vite proxies `/api` to `localhost:8080`.

Production build: `npm run build` → output is in `dist/`.

## API routes

The React app calls `/api/?route=<name>` using JSON and session cookies. Main routes: `session`, `login`, `logout`, `forgot-password`, `reset-password`, `dashboard`, `medicines`, `medicine`, `medicines/bulk-delete`, `stock-options`, `stock-in`, `stock-out`, `expiration`, `reports`, `notifications`, `notifications/read`, `notification/delete`, and `settings`.

Every POST/PUT/DELETE includes the CSRF token returned by `session`.

## Deploy to InfinityFree

1. Create the hosting account, MySQL database, and import `database.sql`.
2. In the repo, add these secrets under **Settings → Secrets and variables → Actions → New repository secret**:

| Secret | Value |
| --- | --- |
| `FTP_SERVER` | FTP hostname from InfinityFree |
| `FTP_USERNAME` | FTP username |
| `FTP_PASSWORD` | FTP password |
| `FTP_PORT` | `21` |
| `FTP_PROTOCOL` | `ftp` |
| `FTP_REMOTE_DIR` | `./` (or your remote folder, with a trailing slash) |
| `DB_HOST` | MySQL hostname, e.g. `sql123.infinityfree.com` |
| `DB_PORT` | `3306` |
| `DB_NAME` | Database name from InfinityFree |
| `DB_USER` | Database username |
| `DB_PASS` | Database password |
| `APP_BASE_URL` | `https://yourname.infinityfreeapp.com` |
| `API_ALLOWED_ORIGIN` | `https://yourname.github.io` (only needed for GitHub Pages) |
| `APP_TIMEZONE` | `Asia/Manila` |
| `MAIL_FROM` | `no-reply@school.edu.ph` |
| `SESSION_IDLE_MINUTES` | `30` |
| `APP_DEBUG` | `false` |
| `SHOW_RESET_LINK` | `true` on free hosting (mail is blocked), `false` otherwise |

3. Push to `main`. The workflow lints PHP, builds React, generates `api/config/config.local.php` from secrets, then uploads `dist/` to the web root and `api/` to `/api`.
4. Visit your site and log in with the default account.

## Deploy the React UI to GitHub Pages

GitHub Pages can host the React build (it cannot run PHP). A second workflow, `.github/workflows/pages.yml`, builds Vite with hash routing so deep links work without server rewrites.

1. In **Settings → Pages → Build and deployment**, set **Source** to **GitHub Actions**.
2. Add a repository variable **Settings → Secrets and variables → Actions → Variables → New repository variable** named `VITE_API_URL` with your hosted API base, e.g. `https://yourname.infinityfreeapp.com/api/`.
3. Add the `API_ALLOWED_ORIGIN` secret above with your Pages origin, e.g. `https://yourname.github.io`, then re-run the InfinityFree deploy workflow so the API allows that origin.
4. Push to `main`. The Pages workflow publishes `dist/` to `https://<username>.github.io/<repo>/`.

Without `VITE_API_URL`, the Pages build falls back to `/api/` on github.io, so login and data calls will fail. API requests from github.io also depend on third-party cookies being allowed by the browser.

## Password recovery

The API stores a one-hour token and emails a reset link. On hosts where `mail()` is blocked, set `SHOW_RESET_LINK` to `true`, deploy, request a reset, use the on-screen link, then set it back to `false` and deploy again.

## Security notes

- Change the seeded password immediately.
- Keep `api/config/config.local.php` out of git; the workflow generates it from secrets.
- `APP_DEBUG` and `SHOW_RESET_LINK` should stay `false` in production.
- `public/.htaccess` provides the SPA fallback; `api/.htaccess` blocks direct access to config files.
