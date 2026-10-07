# FFMS — Full Farm Management System

Web platform for a mixed crop-and-livestock farm, built to the "Full Farm Management System" brief (21 modules).
PHP 8.2 JSON API · MySQL/MariaDB · React + Vite · Chart.js · Leaflet/OpenStreetMap · rule-based forecasting (no paid AI service).

## Run locally (XAMPP, Windows)

1. Start **Apache** and **MySQL** in XAMPP. Project path: `C:\xampp\htdocs\ffms`.
2. `copy .env.example .env`
3. Create the database and load demo data:
   ```bat
   C:\xampp\php\php.exe bin\migrate.php
   C:\xampp\php\php.exe bin\seed.php
   ```
4. Open **http://localhost/ffms/** — password for every demo account is `Farm@Demo2026`.

| Role | Email | Access |
|---|---|---|
| Administrator | admin@ffms.test | everything, users, backups |
| Owner | owner@ffms.test | all farms, finance, reports, users |
| Manager | manager@ffms.test | operations, labour, approvals (Chongwe farm) |
| Agronomist | agronomist@ffms.test | crops, pests, irrigation, soil |
| Accountant | accountant@ffms.test | finance, sales, payroll |
| Worker | worker@ffms.test | own tasks and field records only |
| Manager, farm 2 | manager2@ffms.test | shows per-farm data isolation |

`app/` holds the local build of the React front end (the Docker build compiles its own). To change the UI: `cd frontend && npm install && npm run build` (Node 20+). `npm run dev` serves it on :5173 with an API proxy.

## Commands

| Command | Purpose |
|---|---|
| `php bin/migrate.php` | apply migrations (`--fresh` rebuilds the database) |
| `php bin/seed.php --reset` | wipe and reload the demo data (3 months of history, dated relative to today) |
| `php bin/backup.php` | SQL dump to `storage/backups` (restore: `mysql -u root ffms < file.sql`) |
| `php bin/create-admin.php "Name" email` | real administrator (password from `ADMIN_PASSWORD`) |
| `powershell -File bin/api-smoke-test.ps1` | 67 API checks: auth, validation, workflows, role isolation |

## Five-minute tour

1. **Owner → Dashboard:** acreage, crops, livestock, harvest, income/expenses/profit, alerts, weather, prices. Switch farms from the top bar.
2. **Fields & map**, then **Predictions:** open *Why?* on a yield forecast; check the price outlook and recommendations.
3. **Sales orders → New** (price fills from the market), **Invoice**, then **Invoices → Record payment** — it appears in Finance automatically.
4. **Scouting → Treat:** stock falls and the cost is posted. **Inventory** shows low-stock items; **Purchase orders** run submit → approve → order → receive → pay.
5. **Worker login:** only their own tasks, no finance. **Admin → Audit log, Backups.**

## Configuration (`.env`)

`DB_HOST/PORT/NAME/USER/PASS` (MySQL) · `DB_SSL` (managed DBs) · `CURRENCY` (display) · `WEATHER_LIVE` (1 = Open-Meteo forecast, 0 = offline estimate) ·
`SESSION_IDLE_MIN` · `DEMO_PASSWORD` (seed only) · `CORS_ORIGINS` (dev server).

More: [docs/IMPLEMENTATION_PLAN.md](docs/IMPLEMENTATION_PLAN.md) (code map, brief coverage, size) · [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) (Render, going live, limits).
