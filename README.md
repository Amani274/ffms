# FFMS — Full Farm Management System

A web platform for running a mixed crop-and-livestock farm: land and crops, irrigation, livestock, inventory,
equipment, labour, post-harvest storage, sales, procurement, finance, weather, alerts and decision support.

- **Backend:** PHP 8.2 JSON API (no framework), PDO, MySQL/MariaDB
- **Frontend:** React 19 + Vite, Chart.js, Leaflet/OpenStreetMap
- **Intelligence:** transparent rule-based and statistical forecasting (no external AI service needed)
- **Pattern:** modelled on `hrm-system-main` — API + shared bootstrap, numbered SQL migrations, CLI seed/backup, docs

See [docs/MODULES.md](docs/MODULES.md) for how every module in the project brief maps to the system,
[docs/IMPLEMENTATION_PLAN.md](docs/IMPLEMENTATION_PLAN.md) for the section-by-section code map and size review, and
[docs/RENDER_DEPLOYMENT.md](docs/RENDER_DEPLOYMENT.md) to host it on Render.

## Quick start (XAMPP on Windows)

1. Start **Apache** and **MySQL** in the XAMPP control panel. The project lives at `C:\xampp\htdocs\ffms`.
2. Copy the environment file (defaults suit a stock XAMPP):
   ```bat
   copy .env.example .env
   ```
3. Create the database and load demo data:
   ```bat
   C:\xampp\php\php.exe bin\migrate.php
   C:\xampp\php\php.exe bin\seed.php
   ```
4. Open **http://localhost/ffms/** and sign in with a demo account (password `Farm@Demo2026`):

   | Role | Email | Can do |
   |---|---|---|
   | Administrator | admin@ffms.test | everything, users, backups |
   | Owner | owner@ffms.test | all farms, finance, reports, users |
   | Manager | manager@ffms.test | operations, labour, approvals (Chongwe farm only) |
   | Agronomist | agronomist@ffms.test | crops, pests, irrigation, soil |
   | Accountant | accountant@ffms.test | finance, sales, payroll, procurement payments |
   | Worker | worker@ffms.test | own tasks and field records |
   | Manager (farm 2) | manager2@ffms.test | demonstrates per-farm data isolation |

The built front end is already in `app/`. Rebuild it only if you change `frontend/src` (needs Node 20+):

```bat
cd frontend
npm install
npm run build
```

For live front-end development run `npm run dev` (http://localhost:5173) — it proxies `/ffms/api` to Apache.

## Useful commands

| Command | Purpose |
|---|---|
| `php bin/migrate.php` | apply pending migrations (`--fresh` drops and recreates the database) |
| `php bin/seed.php --reset` | wipe all data and reload the demo dataset |
| `php bin/backup.php` | write a SQL dump to `storage/backups` and prune old ones |
| `php bin/create-admin.php "Name" email` | create a real administrator (password from `ADMIN_PASSWORD`) without demo data |
| `powershell -File bin/api-smoke-test.ps1` | 67 end-to-end API checks (auth, validation, workflows, role isolation) |

Restore a backup: `mysql -u root ffms < storage\backups\ffms-YYYYMMDD-HHMMSS.sql`

## Project layout

```text
api/                 PHP API (index.php router, src/ = engine; src/ is blocked from the web)
  src/Registry.php     every resource: columns, validation, roles, joins — single source of truth
  src/Crud.php         generic list/get/create/update/delete with farm + role scoping
  src/Hooks.php        business rules: stock deduction, ledger posting, payments, harvest→storage
  src/Documents.php    sales orders, invoices, purchase-order workflow, payroll generation
  src/Predict.php      yield, price forecast, irrigation/crop recommendations
  src/Alerts.php       alert engine + notification outbox
  src/Weather.php      Open-Meteo forecast with offline climatology fallback
  src/Reports.php      13 reports + CSV export
database/migrations/ numbered SQL (001 core … 004 business); never edit an applied migration
bin/                 CLI: migrate, seed, backup, api-smoke-test.ps1
frontend/            React source;  app/ = production build served by Apache
docs/                MODULES, ARCHITECTURE, DEMO_GUIDE, LAUNCH_CHECKLIST
storage/             backups and logs (blocked from the web)
```

## Configuration (`.env`)

| Key | Default | Meaning |
|---|---|---|
| `DB_HOST/PORT/NAME/USER/PASS` | 127.0.0.1 / 3306 / ffms / root / (empty) | MySQL connection |
| `CORS_ORIGINS` | localhost:5173 | allowed dev-server origins |
| `SESSION_IDLE_MIN` | 240 | idle logout |
| `CURRENCY` | ZMW | display currency |
| `PAYROLL_DEDUCTION_PCT` | 5 | statutory deduction applied when generating payroll |
| `WEATHER_LIVE` | 1 | 1 = live forecast from Open-Meteo, 0 = offline estimate only |
| `NOTIFY_DRIVER` | simulate | notifications are logged to the outbox; see the launch checklist to go live |
| `DEMO_PASSWORD` | Farm@Demo2026 | used by `seed.php` only |

Before any real use read [docs/LAUNCH_CHECKLIST.md](docs/LAUNCH_CHECKLIST.md).
