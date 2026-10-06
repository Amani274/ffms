# Architecture

## Request flow

```text
Browser (React SPA, HashRouter)  ──fetch/axios, cookies + X-CSRF-Token──▶  Apache  ──▶  api/index.php
                                                                                │
        public: /health, /auth/login                                           ├─ Auth::boot (session) → Auth::require
        custom: /dashboard /weather /predictions /reports /sales /purchasing   ├─ custom handlers (Dashboard, Predict, Reports, Actions…)
                /payroll /alerts /users /backups /meta /options                └─ generic: /{resource}[/{id}] → Crud::handle
```

All responses are JSON: `{ "success": true, "data": …, "meta": { total, page, per_page } }` or
`{ "success": false, "message": "…", "errors": { field: "reason" } }` with correct HTTP status codes
(401 unauthenticated, 403 forbidden, 404 not found/out-of-scope, 409 conflict, 422 validation, 429 throttled).

## The resource registry

`api/src/Registry.php` defines each resource once: columns with validation rules (`int`, `dec:min,max`,
`str:max`, `enum:a,b`, `date`, `bool`, `email`, `!` = required, `fk:resource`), role access, joins and display
columns, farm scope expression and row-level `own` rules. Three things consume it:

1. **`Crud`** — generic list (filters, search, date range, pagination), get, create, update, delete.
2. **`/meta`** — tells the UI which screens, fields, enums and permissions the signed-in role has, so the generic
   create/edit forms are generated from the same rules the server enforces.
3. **Validation** — identical rules on every write; unknown fields are ignored (mass-assignment safe).

Adding a table = a migration + one registry entry (+ a column list in `frontend/src/resources.js` for the screen).

## Security model

| Concern | Control |
|---|---|
| Authentication | PHP sessions (HttpOnly, SameSite=Lax, Secure on HTTPS), idle timeout, ID regenerated at login |
| Passwords | bcrypt (cost 11), strength rule (≥10 chars, letters + numbers), 8 failures / 15 min throttle |
| CSRF | per-session token required on every non-GET request (`X-CSRF-Token`) |
| Authorisation | role list per resource and per operation, enforced server-side; UI only mirrors it |
| Data isolation | users bound to a farm see only that farm (404 for other farms' records); workers see only their own tasks, attendance and pay |
| Injection | prepared statements everywhere; sort/filter columns come from the registry, never from input |
| Integrity | foreign keys, transactions around multi-table writes, stock cannot go negative, payments cannot exceed balance |
| Audit | append-only `audit_logs` (user, action, entity, record, IP) for logins, writes and exports |
| Files | `.env`, `storage/`, `database/`, `bin/`, `docs/`, `api/src/` blocked from the web by `.htaccess` |
| Backups | admin-only SQL dump (API + CLI); verified restorable |

## Predictions (decision support)

Everything is explainable and runs offline:

- **Yield** = variety target × area × product of factors: farm history for the crop (damped by number of seasons), soil pH fit,
  water supplied (rain + irrigation, converted to mm) vs needed so far, unresolved pest severity × area affected, heat-stress days
  above the crop's optimum, fertiliser applied. Range narrows with history and crop progress; revenue uses the forecast price for the harvest month.
- **Price** = least-squares trend on deseasonalised monthly average prices × seasonal index (≥18 months of data), with an 80% band
  from residual spread; volatility → risk rating; recent vs earlier demand levels → demand trend.
- **Recommendations** = forecast rain vs days since last irrigation and heat (irrigate/postpone); low-wind/low-rain days for spraying;
  per-field crop suitability scored on soil pH (40%), temperature (25%), water availability (15%), soil nitrogen (10%) and crop rotation (10%).

## Data model highlights

56 tables in four migrations (core, resources, production, business). Money is `DECIMAL(14,2)`, dates are `DATE`, timestamps are stored in UTC.
`transactions` is the single financial ledger; `ref_type/ref_id` link automatic entries to their source so they can be updated or reversed idempotently.
