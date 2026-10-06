# Demo guide

A 15-minute walkthrough that shows the whole system working end to end. Reset to a clean, known state first:

```bat
C:\xampp\php\php.exe bin\seed.php --reset
```

All dates in the demo data are relative to today, so it always looks current. Password for every account: `Farm@Demo2026`.
Use a private/incognito window per role if you want two roles on screen at once.

## 1. The big picture — owner (owner@ffms.test) · 3 min
1. **Dashboard** — acreage, active crops, livestock, expected vs harvested output, income/expense/profit, receivables, inventory value.
   Point out the alerts panel, 5-day weather, upcoming harvests (wheat is days from harvest), market prices with 30-day change.
2. Switch the **farm selector** (top bar) to *Mkushi Highlands Farm* and back — multi-farm support.
3. **Fields & map** — real map with field boundaries; click a field for soil and current crop.

## 2. From plan to harvest — agronomist (agronomist@ffms.test) · 4 min
1. **Predictions → Yield outlook** — open *Why?* on Tomato: each factor (history, soil, water, pests, heat, fertiliser) with its percentage impact.
2. **Price & market** — pick Onion; show history, 6-month forecast band, best selling month, risk.
3. **Recommendations** — irrigate/postpone advice from the forecast and crop suitability per field. Note West Block (pH 5.4, low nitrogen): every score is lower and
   a legume (groundnut) tops the list, while other fields favour crops that rotate well after the last planting.
4. **Pests & diseases → Scouting** — critical downy mildew on onions → **Treat** (choose Mancozeb) → note stock falls and cost is posted.
5. **Alerts** — critical items: expired chemical, irrigation fault on West Block, storage limit exceeded on a maize lot, overdue FMD vaccinations.

## 3. Money in — manager/accountant · 4 min
1. **Sales orders → + New sales order**: pick a customer and Tomato — the price fills from market data. Create it.
2. Click **Invoice**, then on **Invoices** click **Record payment** with a part payment → invoice becomes *Partial*; try an overpayment (blocked).
3. **Finance → Income & expenses** — the payment is already in the ledger, marked *Auto* (read-only). Add a manual entry to show the difference.
4. **Reports → Profitability by crop** — revenue, direct cost, margin, cost/ha, cost/kg. **Export CSV**.

## 4. Money out & stock — manager · 2 min
1. **Inventory** — Urea is low. **Purchase orders → + New purchase order** (Urea 100 bags) → Submit → Approve → Mark ordered → **Receive goods**.
   Inventory now shows the new stock and weighted-average cost. **Pay** the order → expense appears in Finance.
2. **Labour → Payroll** — last month's payroll is waiting as drafts: **Mark paid** on one worker and see the labour expense appear in Finance. Then **Generate payroll** for the current month to show it built from attendance.

## 5. Control and security — admin (admin@ffms.test) · 2 min
1. **Users & roles** — create a user; note the password rule. Sign in as `worker@ffms.test`: only their tasks, no finance, no users.
2. **Audit log** — every action above is recorded with user, record and IP.
3. **Backups → Create backup now** — download the SQL file.

## Things worth saying
- Predictions are explainable rule-based models on the farm's own data, not a black box; they improve as seasons accumulate.
- Operations drive the books: no double entry — stock, ledger, invoices and storage update together in a database transaction.
- The weather forecast is live when online and falls back to the farm's own climate history when offline.
- Notifications are queued in an outbox (simulated); connecting an email/SMS gateway is a one-method change (see launch checklist).
