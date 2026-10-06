# Launch checklist — from demo to production

The system is feature-complete for demonstration and is a short list of steps from a real deployment. Everything below that is **not**
finished is stated plainly in "Known limits".

## Must do before real use
1. **Start without demo identities.** On a fresh database run migrations only (do not run `seed.php`), then create your administrator:
   ```bat
   C:\xampp\php\php.exe bin\migrate.php
   set ADMIN_PASSWORD=<a strong password>
   C:\xampp\php\php.exe bin\create-admin.php "Your Name" you@yourfarm.com
   ```
   If you already seeded, create your admin the same way, sign in, and delete every `@ffms.test` account under *Users & roles*.
2. **Hide the demo hints.** Rebuild the front end so the login page no longer lists demo accounts:
   `cd frontend && set VITE_SHOW_DEMO=0 && npm run build` (cmd) or `$env:VITE_SHOW_DEMO='0'; npm run build` (PowerShell).
3. **Create a restricted database user** instead of `root`:
   ```sql
   CREATE USER 'ffms'@'localhost' IDENTIFIED BY '<strong password>';
   GRANT SELECT,INSERT,UPDATE,DELETE ON ffms.* TO 'ffms'@'localhost';
   ```
   then set `DB_USER` / `DB_PASS` in `.env` (migrations need a user with `CREATE`/`ALTER`; run them as root).
4. **Serve over HTTPS** (cookies become `Secure` automatically). Set a real `CORS_ORIGINS` or leave it for same-origin only.
5. **Schedule backups:** Windows Task Scheduler (or cron) running `C:\xampp\php\php.exe C:\xampp\htdocs\ffms\bin\backup.php` nightly; copy dumps off the machine.
6. **Set your currency/payroll values** in `.env` (`CURRENCY`, `PAYROLL_DEDUCTION_PCT`) and enter the farm's real farms, fields, crops, workers and opening stock.
7. **Real farm location** — set each farm's latitude/longitude so the live forecast and map are correct.

## Optional, to complete the integrations
- **Email/SMS:** alerts already write to `notification_outbox`. Implement delivery in `Notifier::queue()` (`api/src/Alerts.php`) —
  e.g. SMTP via PHPMailer for email and an SMS gateway HTTP call — and set `NOTIFY_DRIVER` to your driver name.
- **Scheduled alert refresh:** alerts re-evaluate (at most every 10 minutes) whenever someone opens the dashboard, and on demand via *Re-evaluate*.
  Notifications to owners/managers are queued only during an evaluation, so for unattended overnight alerting add a scheduled task that
  calls `AlertEngine::refresh($farmId)` from a small CLI script.
- **Maps offline:** OpenStreetMap tiles need internet; field outlines still draw without them.

## Known limits (be honest with stakeholders)
- Predictions are rule-based and calibrated on the demo data's assumptions; treat them as decision support and recalibrate crop parameters
  (target yield, water need, pH/temperature ranges) with an agronomist for the real farm.
- Single currency per deployment; no multi-currency or tax engine. Payroll uses one flat deduction percentage, not full statutory tables.
- No PDF invoice/payslip generation (use the browser's Print). No document/image upload, no offline mode, no native mobile app (the web app is responsive).
- Backups can be created and downloaded in the app, but restoring is a command-line action by design.
- Automated tests: the 67-check API smoke test (`bin/api-smoke-test.ps1`) is the safety net; there are no unit or browser-automation suites yet.
- Not load-tested; sized for a single farm business (tens of users, hundreds of thousands of rows).

## Verification before go-live
```bat
powershell -ExecutionPolicy Bypass -File bin\api-smoke-test.ps1     :: run on a seeded copy, not production data
```
Then walk through [DEMO_GUIDE.md](DEMO_GUIDE.md) once as each role.
