# Deployment (Render) and going live

## Render demo mode

One Docker web service: Apache + PHP 8.2, the built React app and an in-container MariaDB. On every start it migrates and seeds demo data if the database is empty.
Site: `https://<service>.onrender.com/` (redirects to `/ffms/app/`). Health check: `/ffms/api/health`.

1. Push the repository to GitHub.
2. Render → **New → Blueprint** → select the repository → apply (`render.yaml`, free plan). First build takes several minutes.
3. Sign in as `owner@ffms.test` / `Farm@Demo2026`.

Know before sharing the link:
- **Data resets** whenever the free service restarts or wakes from sleep (cold start ≈ 1 minute).
- **The demo password is public** (shown on the login page). Do not enter real data.
- 512 MB instance: Apache and MariaDB are tuned small (`docker/apache.conf`, `docker/mariadb.cnf`).
- Weather uses the live forecast when online and an estimate otherwise.

### Persistent database

Render has no free MySQL. Use a managed MySQL/MariaDB (e.g. TiDB Cloud Starter, Aiven) and set `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_SSL=1`, `SEED_ON_START=0`.
With an external `DB_HOST` the built-in MariaDB is skipped. Create the first admin from a Render shell in `/var/www/html/ffms`:
`ADMIN_PASSWORD=... php bin/create-admin.php "Name" you@example.com`. On a paid plan you can instead attach a disk at `/var/lib/mysql`.

## Before real use

1. Do not seed. Run `migrate.php` and `create-admin.php`; delete any `@ffms.test` accounts.
2. Hide the demo hints: build with `VITE_SHOW_DEMO=0` (Docker build arg, or `$env:VITE_SHOW_DEMO='0'; npm run build`).
3. Use a restricted DB user (`SELECT, INSERT, UPDATE, DELETE`), serve over HTTPS, schedule `bin/backup.php` nightly and copy dumps off the machine.
4. Enter real farm coordinates (map + forecast), crops (yield, water, pH, temperature), workers and opening stock.
5. Optional: implement email/SMS delivery in `Notifier::notify()` (`api/src/Alerts.php`).

## Known limits

- Predictions are rule-based; recalibrate crop parameters with an agronomist for the real farm.
- Single currency; no tax engine; payroll is gross pay from attendance (enter deductions manually).
- No PDF export, document upload, offline mode or native app (the web app is responsive).
- Restoring a backup is a command-line action. Alerts refresh when the dashboard is opened (at most every 10 minutes).
- Automated checks are the 67-call API suite; there are no unit or browser-test suites. Not load-tested.
- The Docker build was written to the HRM project's pattern and verified on Render's first deploy; re-test after changing the Dockerfile.
