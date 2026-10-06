# Implementation plan and code map

How FFMS was built, in the order it was built, with what each section contains. Line counts are current
(`wc -l`). Counts understate volume in a few files (`Registry.php`, `resources.js`, `Alerts.php`) because their lines are long.

## Build order

1. **Reference review** — read `hrm-system-main` (API + shared bootstrap, numbered migrations, CLI seed, React front end, docs) and the brief.
2. **Decisions** — React/Vite, MySQL (ships with XAMPP), rule-based predictions (no paid AI service).
3. **Schema** — four numbered migrations, grouped by domain.
4. **API core** — config, DB, validation, auth, then one generic CRUD engine driven by a single resource registry (instead of one PHP file per endpoint like HRM).
5. **Business rules** — hooks that keep stock, ledger, invoices and storage consistent; multi-row documents; weather, predictions, alerts, reports, dashboard.
6. **Tooling** — migrate, seed, backup, create-admin, API smoke test.
7. **Front end** — shared shell and components, a config-driven screen for every resource, custom pages where a table is not enough.
8. **Verification** — 67 API checks, browser walk-through of every route, phone-width check, backup restore test; defects found were fixed (yield maths, role data leaks, `.env` exposure).
9. **Render packaging** — Dockerfile, start script, Blueprint (see `RENDER_DEPLOYMENT.md`).

## Database — `database/migrations/` (848 lines, 56 tables)

| File | Lines | Contents |
|---|---|---|
| `001_core.sql` | 88 | farms, users, fields, field history, audit log, login attempts |
| `002_resources.sql` | 184 | suppliers, inventory + stock movements, equipment (+ usage, allocation, maintenance), workers, attendance, tasks, reviews, payroll |
| `003_production.sql` | 294 | crops, plantings, activities, growth, pests, scouting, treatments, irrigation, livestock, warehouses, harvests, storage lots |
| `004_business.sql` | 282 | customers, prices, contracts, sales orders/lines, invoices, payments, dispatch, quotations, purchase orders, ledger, budgets, loans, weather, alerts, notification outbox |

## API engine — `api/` (2,900 lines)

| File | Lines | What it does |
|---|---|---|
| `index.php` | 159 | Router: public routes, session, CSRF, custom endpoints, falls through to generic CRUD; JSON error handling |
| `src/Core.php` | 272 | `Env` loader, `Db` (PDO wrapper, transactions, SSL option), `Validator` (rule mini-language), JSON helpers |
| `src/Auth.php` | 141 | Sessions, login + throttling, CSRF check, password rules, `Audit` logger |
| `src/Registry.php` | 623 | **Single source of truth**: every resource's columns, validation, roles, joins, farm scope, worker-only rules, redaction |
| `src/Crud.php` | 245 | Generic list/get/create/update/delete with filters, search, date range, paging, farm + role scoping |
| `src/Hooks.php` | 358 | Business rules on write: stock deduction, ledger postings, payment → invoice, harvest → storage lot, dispatch, loan balance; `Ledger` and `Stock` helpers |
| `src/Documents.php` | 230 | Sales orders with lines, invoicing, purchase-order workflow (submit → approve → order → receive → pay), payroll generation |
| `src/Weather.php` | 144 | Live 7-day forecast (Open-Meteo), cache, offline climatology fallback, risk detection |
| `src/Predict.php` | 344 | Yield prediction with factor breakdown, price forecast, irrigation/spray advice, crop suitability |
| `src/Alerts.php` | 199 | Alert engine (18 types, auto-clearing), notification outbox |
| `src/Dashboard.php` | 110 | Dashboard aggregates, role-aware (finance hidden from field roles) |
| `src/Reports.php` | 291 | 13 reports + CSV export |
| `src/Admin.php` | 235 | `/meta` (UI permissions), `/options` (dropdowns), user management, backups |

## Command-line tools — `bin/` (1,150 lines)

| File | Lines | Purpose |
|---|---|---|
| `migrate.php` | 54 | Apply pending migrations; `--fresh` rebuilds |
| `seed.php` | 713 | Demo dataset (two farms, a year of history), dated relative to today |
| `backup.php` | 26 | SQL dump + pruning |
| `create-admin.php` | 40 | Create a real administrator without demo data |
| `api-smoke-test.ps1` | 159 | 67 end-to-end checks: auth, validation, workflows, role isolation |

## Front end — `frontend/src/` (2,089 lines)

| File | Lines | Purpose |
|---|---|---|
| `main.jsx`, `App.jsx` | 144 | Entry, routing, sidebar/topbar shell, role-filtered navigation, change-password |
| `api.js`, `auth.jsx`, `format.js` | 165 | HTTP client (CSRF, farm scoping), session/farm state, money/date formatting |
| `ui.jsx` | 252 | Modal, table, badges, form fields, **generic create/edit form built from server metadata** |
| `resources.js` | 143 | Per-resource screen config: columns, filters, row actions (also the sidebar map) |
| `charts.jsx`, `styles.css` | 237 | Chart.js wrappers; design system (light/dark, responsive) |
| `pages/ResourcePage.jsx` | 154 | The one screen used by ~50 resources |
| `pages/Documents.jsx` | 161 | Order/PO builder, lines viewer, pay and payroll dialogs |
| `pages/Dashboard.jsx` | 186 | KPIs, charts, alerts, weather, harvests, pests, irrigation, stock, prices |
| `pages/FieldsPage.jsx` | 61 | Leaflet map of field boundaries + field register |
| `pages/WeatherPage.jsx` | 102 | Forecast, risks, history charts |
| `pages/PredictionsPage.jsx` | 170 | Yield outlook, price forecast, recommendations |
| `pages/ReportsPage.jsx` | 77 | Report runner, CSV export, print |
| `pages/AlertsPage.jsx` | 60 | Alert list with resolve / re-evaluate |
| `pages/AdminPages.jsx` | 110 | Users & roles, backups |
| `pages/LoginPage.jsx` | 67 | Sign-in with demo-account shortcuts |

## Deployment files (new)

`Dockerfile` (40) · `docker/start.sh` (68) · `docker/apache.conf` (22) · `docker/mariadb.cnf` (10) · `render.yaml` (28)

---

## Size review: can it be smaller?

Totals: **PHP 4,184 · React 2,089 · SQL 848** (about 7,100). For comparison, `hrm-system-main` is **PHP 10,369 (136 files) · React 6,145 · SQL 810**,
so FFMS is roughly 40% of HRM's size. It is much larger than your coursework projects (`task1` ≈ 650 lines) because it adds what those do not have:
login/roles/CSRF, validation, audit, farm isolation, a ledger, forecasting and reports.

The registry design already replaced what would be ~55 endpoint files in HRM's style. If you want it smaller, these are the options — **none applied**:

| Option | Removes | Est. lines saved | Trade-off |
|---|---|---|---|
| Slim the demo seed (one farm, 6 months, fewer records) | most of `seed.php` | ~350–450 | demo looks emptier, charts thinner |
| Drop low-value resources (field history, reviews, allocations, loan payments, quotations, breeding, outbox) | registry + screens + tables | ~300 | fewer brief items covered |
| 13 → 6 core reports | `Reports.php` | ~130 | fewer analytics |
| 18 → ~8 alert types | `Alerts.php` | ~80 | less proactive |
| Drop crop-suitability + recommendations | `Predict.php`, Predictions tab | ~150 | loses weather-based advice |
| Remove purchase-order workflow and payroll generation (keep plain records) | `Documents.php`, `Hooks.php` | ~200 | less automation |
| Drop weather charts page / map | 2 pages | ~160 | less visual |
| **All of the above** | | **~1,300–1,500 (≈20%)** | a thinner brief coverage |

Honest recommendation: leave the engine (Registry, Crud, Hooks) alone — it is what keeps the code small relative to the feature count.
If the goal is "looks like the pre-existing projects", the realistic lever is scope (fewer modules), not tidier code.

## Next steps

1. Push the project to GitHub and deploy on Render (`RENDER_DEPLOYMENT.md`) — demo mode needs no external database.
2. Open the deployed URL and run the demo guide once per role; note anything that behaves differently from localhost.
3. Decide the scope question above before the final submission (keep, or trim to a defined subset).
4. For a persistent public deployment: point `DB_*` at a managed MySQL/MariaDB, set `SEED_ON_START=0`, create a real admin, hide demo hints (see `LAUNCH_CHECKLIST.md`).
5. Optional hardening: SMTP/SMS delivery, scheduled alert job, PDF invoices.
