# PHP-to-React Conversion Record

This document records exactly how the School Clinic Inventory System was changed from a server-rendered PHP app into a React web app with a PHP JSON API.

## 1. Goal

The instructor required the GitHub repository to contain a **React web app**. The original repository was PHP 8 + MySQL only, so the frontend was rebuilt in React while the existing database and PHP logic were kept as a backend API.

Result: the repository root is now a React + Vite project (`package.json`, `index.html`, `src/`), and the PHP code lives under `api/`.

## 2. Structure before and after

Before:

```
index.php, dashboard.php, medicine-inventory.php, ... (all PHP pages)
auth/       config/       includes/       assets/       database.sql
```

After:

```
index.html                 Vite entry document
package.json               React/Vite dependencies and scripts
vite.config.js             dev proxy for /api
src/
  main.jsx                 React root + BrowserRouter + AuthProvider
  App.jsx                  route table
  api.js                   fetch wrapper with CSRF handling
  AuthContext.jsx          session/user/unread state
  constants.js             shared lists and formatters
  components/              Layout, Icons, UI, BrandLogo
  pages/                   Login, Dashboard, Inventory, StockIn, etc.
  styles.css               React app styling (teal palette preserved)
public/.htaccess           SPA fallback for Apache hosting
api/
  index.php                JSON API router used by React
  config/                  DB config moved from root
  includes/                session, CSRF, notification helpers moved from root
  auth/ + legacy *.php     original PHP UI kept for reference
  .htaccess                blocks direct access to config files
assets/                    original logos and legacy CSS/JS
database.sql               unchanged
.github/workflows/deploy.yml
README.md                  updated for the React stack
CONVERSION.md              this document
```

## 3. Step-by-step changes

### Step 1 — Move the PHP backend

All root PHP pages plus the `auth/`, `config/`, and `includes/` folders were moved into `api/`. Because every PHP include uses `__DIR__`, the relative paths continued to work after the move. `database.sql`, `assets/`, and `.github/` stayed at the root.

The uncommitted edits that already existed in several PHP files were preserved during the move.

### Step 2 — Add the React/Vite scaffold

Created:

- `package.json` with React 18, React Router 6, Vite 6, and `@vitejs/plugin-react`
- `vite.config.js` with a proxy from `/api` to `http://localhost:8080` for local development
- `index.html` with `<div id="root">` and `/src/main.jsx`
- `.gitignore` entries for `node_modules/`, `dist/`, and `api/config/config.local.php`

### Step 3 — Create the JSON API

Created `api/index.php` as a single router. It reuses the existing helpers:

- `api/includes/session.php` for PHP sessions, `csrf_token()`, and `csrf_valid()`
- `api/includes/layout.php` for `create_notification()`, `generate_inventory_alerts()`, and `session_identity()`
- `api/includes/deleted-data.php` for notification suppression
- `api/config/db.php` for the PDO connection
- `api/config/constants.php` for medicine categories and stock-out reasons

API behavior:

- All responses are JSON with `Cache-Control: no-store`.
- Every POST/PUT/DELETE requires the CSRF token returned by `GET /api/?route=session`.
- Authentication stays session-cookie based; no JWT was introduced.
- Idle timeout returns HTTP 401 instead of redirecting to a PHP page.
- Database failures return JSON errors instead of PHP error pages.

Main routes:

| Route | Method | Purpose |
| --- | --- | --- |
| `session` | GET | auth state, CSRF token, user, unread count |
| `login`, `logout` | POST | session login/logout |
| `forgot-password`, `reset-password` | POST | password recovery |
| `dashboard` | GET | summary cards, alerts, activity feed |
| `medicines` | GET | filtered, paginated inventory |
| `medicine` | GET/POST/PUT/DELETE | detail, create, update, delete |
| `medicines/bulk-delete` | POST | bulk delete |
| `stock-options` | GET | medicines, batches, history, reasons |
| `stock-in`, `stock-out` | POST | stock movements |
| `expiration` | GET | expiration summary and batch list |
| `reports` | GET | four report types with date filters |
| `notifications`, `notifications/read`, `notification/delete` | GET/POST | alerts |
| `settings` | GET/POST | account and clinic preferences |

### Step 4 — Build the React UI

Created one page component per screen and wired them with React Router:

| React route | Component | API route used |
| --- | --- | --- |
| `/login` | `Login.jsx` | `session`, `login` |
| `/forgot-password` | `ForgotPassword.jsx` | `forgot-password` |
| `/reset-password` | `ResetPassword.jsx` | `reset-password` |
| `/dashboard` | `Dashboard.jsx` | `dashboard` |
| `/inventory` | `Inventory.jsx` | `medicines`, `medicine`, `medicines/bulk-delete` |
| `/inventory/new` | `MedicineForm.jsx` | `medicine` POST |
| `/inventory/:id` | `MedicineView.jsx` | `medicine` GET/DELETE |
| `/inventory/:id/edit` | `MedicineForm.jsx` | `medicine` GET/PUT |
| `/stock-in` | `StockIn.jsx` | `stock-options`, `stock-in` |
| `/stock-out` | `StockOut.jsx` | `stock-options`, `stock-out` |
| `/expiration` | `Expiration.jsx` | `expiration` |
| `/reports` | `Reports.jsx` | `reports` |
| `/notifications` | `Notifications.jsx` | `notifications*` |
| `/settings` | `Settings.jsx` | `settings` |

`Layout.jsx` renders the sidebar, topbar, unread badge, and logout button around protected routes. `Protected` in `App.jsx` redirects unauthenticated users to `/login`.

`src/api.js` centralizes `fetch()`: it sends `credentials: 'include'`, attaches the CSRF token to mutating requests, and stores the refreshed token from each response.

### Step 5 — Styling

Replaced dependency on the old `assets/css/*.css` with `src/styles.css`, keeping the same teal color palette, card layout, sidebar, tables, badges, and responsive behavior. The original logo is imported directly from `assets/img/logo-1.jpg` through Vite, so no image files were duplicated.

### Step 6 — SPA routing on Apache

Added `public/.htaccess` with a rewrite rule that sends unknown paths to `index.html`. Vite copies it into `dist/`, so deep links such as `/inventory/3` work on InfinityFree.

Added `api/.htaccess` to deny direct requests to config/include files inside the API folder.

### Step 7 — Update the deploy workflow

`.github/workflows/deploy.yml` now:

1. Lints every PHP file (excluding the generated local config).
2. Installs npm dependencies and runs `npm run build`.
3. Generates `api/config/config.local.php` from GitHub secrets.
4. Uploads `dist/` to the web root.
5. Uploads `api/` to the `/api` directory on the server.

### Step 8 — Make GitHub Pages React too

Added `.github/workflows/pages.yml` so the GitHub Pages site is built from the same React source:

1. It builds Vite with `VITE_ROUTER_MODE=hash`, so `HashRouter` is used instead of `BrowserRouter` and deep links work without a Pages rewrite rule.
2. `VITE_BASE` is derived from the repository name so asset URLs work on `github.io/<repo>/`.
3. `VITE_API_URL` is read from a repository variable and baked into the build; `src/api.js` uses it instead of `/api/` when present.
4. `API_ALLOWED_ORIGIN` was added to config generation and CORS handling in `api/index.php`, with `SameSite=None; Secure` cookies when cross-origin mode is enabled. This lets the Pages site call the hosted API, subject to browser third-party cookie rules.

GitHub Pages cannot run PHP, so the API still has to stay on InfinityFree (or another PHP host). Without `VITE_API_URL`, the Pages build only renders the UI shell and data calls fail.

### Step 9 — Update documentation

- `README.md` now describes the React + PHP API stack, local setup, API routes, InfinityFree deployment, and GitHub Pages deployment.
- `CONVERSION.md` (this file) documents the conversion process.

## 4. Verification performed

Commands run locally:

```
npm install
npm run build
php -l api/index.php
Get-ChildItem -Recurse -Filter *.php -Path api | ForEach-Object { php -l $_.FullName }
```

Results:

- `npm install` completed and created `package-lock.json`.
- `npm run build` succeeded: 52 modules transformed, output written to `dist/`.
- All 26 PHP files passed `php -l` with no syntax errors.
- API smoke test with `php -S` returned valid JSON for `/api/?route=session`, including a CSRF token.
- With `API_ALLOWED_ORIGIN` set, the same endpoint returned `Access-Control-Allow-Origin` for the GitHub Pages origin, confirming the Pages-to-API CORS path.
- Re-ran `npm run build` after the Pages routing changes; the build still succeeded.

`npm install` reported two moderate advisories in the Vite dependency tree; they did not block the build.

## 5. How to run locally

Terminal 1 — PHP API:

```
php -S localhost:8080
```

Terminal 2 — React dev server:

```
npm install
npm run dev
```

Then open the Vite URL. Vite forwards `/api` requests to the PHP server.

## 6. Notes

- The original PHP pages were not deleted; they remain in `api/` as reference and as a fallback server-rendered UI.
- `database.sql` and the seeded account are unchanged.
- No git commit was created as part of this conversion.
- On GitHub, the repo now visibly contains `package.json`, `src/App.jsx`, and multiple `.jsx` page files, which is what the instructor's React requirement checks for.
