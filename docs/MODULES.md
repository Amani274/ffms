# Module coverage

Maps each section of the project brief ("Full Farm Management System") to what is built. Sections 3, 16 and 17
had no headings in the brief; their content (soil conditions, yield prediction, price forecasting) is covered below.

Screens are reachable from the sidebar; **table** names are in `database/migrations`.

| # | Brief module | Delivered | Screens / tables |
|---|---|---|---|
| 1 | Farm & field management | Multiple farms, fields with size, GPS point and GeoJSON boundary drawn on an OpenStreetMap map, land use, crop-rotation history | Fields & map, Farms, Field history · `farms`, `fields`, `field_history` |
| 2 | Crop management | Crop/variety catalogue with agronomic needs, planting schedule, spacing/density, fertiliser/manure/spray records (cost + stock deducted), growth monitoring, expected vs actual harvest, yield per hectare | Crops, Plantings, Activities, Growth, Harvests · `crops`, `plantings`, `crop_activities`, `growth_records`, `harvests` |
| 3 | Soil conditions *(untitled in brief)* | pH, organic matter, N-P-K, soil type and notes per field; pH feeds yield prediction and crop suitability | Field register · `fields` |
| 4 | Irrigation & water | Water sources, systems (drip/pivot/sprinkler…), schedules, litres used, pump link, energy cost posted to finance, weather-based "irrigate / postpone" advice, fault alerts | Irrigation screens · `water_sources`, `irrigation_systems`, `irrigation_schedules` |
| 5 | Livestock | Register, breed, tag, birth/death, vaccination schedules with due alerts, disease/treatment, feed (stock + cost), weight growth, breeding, production (milk/eggs/meat), mortality | Livestock screens · `animals`, `animal_health`, `animal_weights`, `feed_records`, `breeding_records`, `livestock_production` |
| 6 | Inventory & inputs | Seeds, fertiliser, chemicals, feed, vet medicines, packaging, fuel, tools; stock-in/out ledger, reorder levels, expiry; low-stock and expiry alerts | Inventory, Stock movements · `inventory_items`, `stock_movements` |
| 7 | Equipment & machinery | Asset register, allocation, hours, fuel, maintenance schedule + repair history, straight-line depreciation, due reminders | Equipment screens · `equipment`, `equipment_allocations`, `equipment_usage`, `maintenance_records` |
| 8 | Labour | Workers, roles, attendance, task allocation, hours, reviews, payroll generation from attendance (posts labour cost) | Labour screens · `workers`, `worker_attendance`, `tasks`, `worker_reviews`, `payroll_runs` |
| 9 | Pest & disease | Reference database with control measures, field scouting, treatments with chemical + cost + effectiveness, outbreak alerts | Pests & diseases · `pest_catalog`, `scouting_reports`, `treatments` |
| 10 | Weather | Live 7-day forecast (Open-Meteo), offline climatology fallback, recorded rainfall/temperature/humidity/wind, extreme-weather alerts, history charts | Weather · `weather_records`, `weather_forecasts` |
| 11 | Harvest | Quantities by field, grade, losses and reasons, handling, packaging, storage, actual-vs-expected yield | Harvests · `harvests` |
| 12 | Market & sales | Customers, market prices + demand, sales orders with line items, invoices, payments, contracts, profitability by crop | Sales screens · `customers`, `market_prices`, `contracts`, `sales_orders`, `invoices`, `payments` |
| 13 | Financial management | Income/expense ledger (auto-posted from operations + manual entries), budgets vs actual, loans + repayments, P&L, ROI, cost/ha, cost/kg | Finance screens, Reports · `transactions`, `budgets`, `loans`, `loan_payments` |
| 14 | Suppliers & procurement | Suppliers, quotations, purchase orders with approval workflow, goods receipt (adds stock), payments, supplier spend/rating | Procurement · `suppliers`, `quotations`, `purchase_orders`, `purchase_order_items` |
| 15 | Storage & post-harvest | Warehouses (dry/cold/silo) with utilisation, produce lots with grade and storage duration, spoilage alerts, valuation, dispatch | Warehouses, Stored produce, Dispatch · `warehouses`, `storage_lots`, `dispatches` |
| 16 | Yield prediction *(untitled)* | Per-planting predicted yield with range, confidence, revenue, and the factor breakdown (history, soil, water, pests, heat, fertiliser) | Predictions → Yield outlook · `Predict::yield` |
| 17 | Price forecasting *(untitled)* | Seasonally adjusted trend forecast, 6 months, 80% band, best selling month, risk and demand trend | Predictions → Price & market · `Predict::priceForecast` |
| 18 | Dashboard & analytics | Acreage, active crops, livestock, stages, expected vs actual production, expenses, revenue, profit, stock, weather, pests, irrigation, market prices (finance hidden from non-finance roles) | Dashboard · `Dashboard::summary` |
| 19 | Notifications & alerts | 18 alert types derived from live data, auto-cleared when resolved, email/SMS outbox (simulated until a gateway is connected) | Alerts, Notifications · `alerts`, `notification_outbox` |
| 20 | Reports | 13 reports with date range, CSV export and print | Reports · `Reports.php` |
| 21 | Security & users | 6 roles, per-farm isolation, bcrypt passwords, CSRF, login throttling, audit log, backups, idle timeout | Users, Audit log, Backups · `users`, `audit_logs`, `login_attempts` |

## How operations connect (so numbers always agree)

- Completing a **crop activity**, **treatment** or **feed record** deducts stock and posts an expense, tagged to the crop/field.
- A **payment** updates its invoice (unpaid → partial → paid) and posts income. Overpayment is rejected.
- A **purchase order** moves draft → approval → ordered → received. Receiving adds stock; payments post expense.
- A **harvest** with a warehouse creates a storage lot; **dispatch** and delivered **sales orders** reduce lots.
- **Payroll** is generated from attendance; marking it paid posts labour expense.
- **Equipment usage** accumulates hours and posts fuel cost; **maintenance** marked done posts equipment cost.
- Ledger rows created automatically are protected from direct editing, so the books cannot drift from operations.
