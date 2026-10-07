# Implementation plan and code map

## Build order

1. Reviewed `hrm-system-main` (API + shared bootstrap, numbered migrations, CLI seed, React front end) and the brief.
2. Decisions: React/Vite, MySQL (ships with XAMPP), rule-based predictions.
3. Schema → API core → one generic CRUD engine driven by a resource registry (instead of one PHP file per endpoint) → business hooks → weather, predictions, alerts, reports, dashboard.
4. Tooling (migrate, seed, backup, create-admin, API tests) → front end → verification → Render packaging.
5. **Brief audit and trim** (below).

## Code map (current)

**Database** — 4 migrations, 54 tables, 825 lines: `001_core` (farms, users, fields, audit) · `002_resources` (suppliers, inventory, equipment, labour, payroll) ·
`003_production` (crops, pests, irrigation, livestock, storage, harvest) · `004_business` (sales, procurement, ledger, budgets, loans, weather, alerts).

**API** — `api/`, 3,181 lines

| File | Lines | Role |
|---|---|---|
| `index.php` | 149 | router, sessions, CSRF, error handling |
| `src/Core.php` | 272 | env, DB wrapper, validation rules |
| `src/Auth.php` | 141 | login + throttling, CSRF, audit log |
| `src/Registry.php` | 610 | every resource: columns, validation, roles, joins, farm scope |
| `src/Crud.php` | 245 | generic list/get/create/update/delete with scoping |
| `src/Hooks.php` | 330 | stock deduction, ledger postings, payments, harvest → storage |
| `src/Documents.php` | 199 | sales orders, invoices, purchase-order workflow, payroll generation |
| `src/Weather.php` | 144 | live forecast, offline fallback, risks |
| `src/Predict.php` | 300 | yield, price forecast, irrigation + crop recommendations |
| `src/Alerts.php` | 178 | alert engine (15 types), notification log hook |
| `src/Dashboard.php` · `Reports.php` · `Admin.php` | 104 · 274 · 235 | dashboard, 13 reports, users/backups/metadata |

**Tools** — `bin/`: `migrate` 54 · `seed` 470 · `backup` 26 · `create-admin` 40 · `api-smoke-test.ps1` 161.
**Front end** — `frontend/src/`, 2,011 lines: shell/auth/api/format (`App`, `auth`, `api`, `format`, `main`) · `ui.jsx` 252 (modal, table, generic form from server metadata) ·
`resources.js` 140 (screen config) · `ResourcePage` 164 (one screen for ~45 resources) · `Documents` 129 · `Dashboard` 167 · `Fields` 61 · `Weather` 94 · `Predictions` 167 · `Reports` 74 · `Alerts` 51 · `AdminPages` 110 · `LoginPage` 67 · `charts` 39 · `styles` 187.
**Deploy** — `Dockerfile`, `render.yaml`, `docker/` (168 lines).

## Brief audit result

About 195 requirement bullets across 21 sections; every section is implemented, and almost every table traces to an explicit bullet. Named items kept exactly: the 13 reports,
the 6 roles, the 12 alert types, the dashboard list, and sections 16–17 (including demand trends). Thin but sufficient: land-use planning, breed, seedling and market-destination
fields, supplier rating, productivity (task hours), historical weather. Partial: SMS/email delivery (alerts are written to `storage/logs/notifications.log` — no gateway).

## What was trimmed (beyond-the-brief depth)

Removed: loan repayments table/screen · notification outbox table/screen · 3 extra alert types (overdue invoice, loan due, overdue tasks) · alert type/severity filters and manual re-evaluate
(alerts now refresh when the dashboard opens) · delivered-order auto-dispatch · stock "adjust" movements · spray-window advice · crop-rotation/nitrogen scoring · price confidence bands ·
payroll deduction percentage and date dialog (now a one-click "generate last month") · duplicate dashboard/weather charts · CSV export · print · dark mode.
Kept on purpose: per-role data hardening, login throttling, change-password, create-admin, backups, the 24-month price table (the seasonal forecast needs it).
Demo data cut to 3 months and scaled down (6 fields, 5 crops, 6 workers, 19 animals, 15 inventory items, 12 orders). Seven docs merged into three.

**Result:** PHP 4,184 → 3,771 · React 2,089 → 2,011 · SQL 848 → 825 · seed 713 → 470 · markdown 451 → ~150. The runtime code shrank about 7% — most of what was beyond the brief was small;
the bulk of the code is the brief itself. `hrm-system-main` for comparison: PHP 10,369 (136 files), React 6,145.

## Remaining scope decision

Shrinking further means dropping whole brief modules (e.g. livestock or storage ≈ 400–700 lines each across schema, API and screens). That is a requirements decision for the group.
