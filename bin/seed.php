<?php

declare(strict_types=1);

/**
 * Compact demo dataset: two farms, 3 months of operational history (market prices keep 24 months for forecasting).
 * Dates are relative to today so every screen looks current.
 *   php bin/seed.php            (only when the database is empty)
 *   php bin/seed.php --reset    (wipes all data first)
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../api/src/Core.php';
require_once __DIR__ . '/../api/src/Auth.php';
require_once __DIR__ . '/../api/src/Hooks.php';
Env::load(__DIR__ . '/../.env');
mt_srand(2026);
date_default_timezone_set('UTC');

if (in_array('--reset', $argv, true)) {
    $pdo = Db::pdo();
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach (array_column(Db::all('SHOW TABLES'), 'Tables_in_' . Env::get('DB_NAME', 'ffms')) as $t) {
        if ($t !== 'schema_migrations') $pdo->exec("TRUNCATE TABLE `$t`");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    echo "Existing data wiped.\n";
}
if ((int) Db::val('SELECT COUNT(*) FROM users') > 0) {
    fwrite(STDERR, "Database already contains data. Re-run with --reset to wipe and reseed.\n");
    exit(1);
}

$TODAY = gmdate('Y-m-d');
function d(int $off): string { global $TODAY; return gmdate('Y-m-d', strtotime("$TODAY $off days")); }
function rnd(float $a, float $b, int $dec = 2): float { return round($a + mt_rand() / mt_getrandmax() * ($b - $a), $dec); }
function pick(array $a): mixed { return $a[array_rand($a)]; }
function ins(string $t, array $r): int { return Db::insert($t, $r); }
function season(string $date): string { $y = (int) substr($date, 0, 4); $m = (int) substr($date, 5, 2); return $m >= 7 ? "$y/" . substr((string) ($y + 1), 2) : ($y - 1) . '/' . substr((string) $y, 2); }
function square(float $lat, float $lon, float $s): string { return json_encode(['type' => 'Polygon', 'coordinates' => [[[$lon - $s, $lat - $s], [$lon + $s, $lat - $s], [$lon + $s, $lat + $s], [$lon - $s, $lat + $s], [$lon - $s, $lat - $s]]]]); }

Db::pdo()->beginTransaction();

// ============================================================ farms & users
$f1 = ins('farms', ['name' => 'Chongwe Valley Farm', 'location' => 'Chongwe, Lusaka Province', 'latitude' => -15.3292, 'longitude' => 28.6823, 'size_ha' => 120, 'registration_no' => 'ZM-AGR-2014-0412', 'contact_phone' => '+260971000111', 'description' => 'Mixed irrigated crops with a small dairy and layer unit.']);
$f2 = ins('farms', ['name' => 'Mkushi Highlands Farm', 'location' => 'Mkushi, Central Province', 'latitude' => -13.6167, 'longitude' => 29.3833, 'size_ha' => 40, 'registration_no' => 'ZM-AGR-2019-1177', 'description' => 'Rain-fed grain farm.']);

$demoPw = Env::get('DEMO_PASSWORD', 'Farm@Demo2026');
$hash = Auth::hashPassword($demoPw);
$users = [
    ['System Administrator', 'admin@ffms.test', 'admin', null], ['Mulenga Chanda', 'owner@ffms.test', 'owner', null], ['Chileshe Mwansa', 'manager@ffms.test', 'manager', $f1],
    ['Dr. Namukolo Banda', 'agronomist@ffms.test', 'agronomist', $f1], ['Bwalya Tembo', 'accountant@ffms.test', 'accountant', $f1], ['Joseph Phiri', 'worker@ffms.test', 'worker', $f1],
];
$uid = [];
foreach ($users as $i => [$n, $e, $r, $fm]) $uid[$r] = ins('users', ['name' => $n, 'email' => $e, 'password_hash' => $hash, 'role' => $r, 'farm_id' => $fm, 'phone' => '+26097110000' . ($i + 1), 'active' => 1]);
ins('users', ['name' => 'Kondwani Mumba', 'email' => 'manager2@ffms.test', 'password_hash' => $hash, 'role' => 'manager', 'farm_id' => $f2, 'active' => 1]);

// ============================================================ fields, crops, pests
$fld = [];
foreach ([
    ['North Block A', 24, -15.3180, 28.6700, 'Loam', 6.4, 3.1, 'cropping'], ['North Block B', 18, -15.3150, 28.6860, 'Clay loam', 6.1, 2.8, 'cropping'],
    ['River Plot 1', 6, -15.3405, 28.6950, 'Sandy loam', 6.6, 2.2, 'cropping'], ['River Plot 2', 5, -15.3430, 28.7010, 'Sandy loam', 6.8, 2.4, 'cropping'],
    ['West Block', 20, -15.3300, 28.6450, 'Red clay', 5.4, 1.9, 'cropping'], ['Wheat Flats', 15, -15.3050, 28.6750, 'Sandy clay loam', 6.9, 2.6, 'cropping'],
] as [$n, $ha, $la, $lo, $soil, $ph, $om, $use]) {
    $fld[$n] = ins('fields', ['farm_id' => $f1, 'name' => $n, 'size_ha' => $ha, 'latitude' => $la, 'longitude' => $lo, 'boundary_geojson' => square($la, $lo, 0.002 + $ha / 24000),
        'soil_type' => $soil, 'soil_ph' => $ph, 'organic_matter_pct' => $om, 'nitrogen_ppm' => $ph < 6 ? 22 : rnd(30, 42, 0), 'phosphorus_ppm' => rnd(18, 30, 0), 'potassium_ppm' => rnd(120, 190, 0), 'land_use' => $use, 'status' => 'active']);
}
$m2f = ins('fields', ['farm_id' => $f2, 'name' => 'Highland 1', 'size_ha' => 22, 'latitude' => -13.61, 'longitude' => 29.38, 'boundary_geojson' => square(-13.61, 29.38, 0.0025), 'soil_type' => 'Loam', 'soil_ph' => 6.2, 'land_use' => 'cropping']);
foreach ([['North Block A', '2024/25', 'Maize', 7400], ['West Block', '2024/25', 'Soybean', 1850], ['River Plot 1', '2024/25', 'Tomato', 41500]] as [$fn, $s, $c, $y]) {
    ins('field_history', ['field_id' => $fld[$fn], 'season' => $s, 'crop_name' => $c, 'yield_kg_per_ha' => $y]);
}

// name, variety, category, days, yield/ha, seed/ha, spacing, water mm, pH min/max, temp min/max, base price
$cropDefs = [
    ['Maize', 'SC 513', 'cereal', 140, 7500, 25, '75 x 25 cm', 550, 5.8, 7.0, 18, 32, 5.8], ['Soybean', 'Lukanga', 'legume', 120, 2800, 80, '45 x 5 cm', 450, 6.0, 7.0, 15, 30, 11.5],
    ['Wheat', 'Zambezi', 'cereal', 100, 6500, 120, '20 cm rows', 400, 6.0, 7.5, 10, 25, 8.2], ['Tomato', 'Rodade', 'vegetable', 100, 45000, 0.3, '100 x 40 cm', 600, 6.0, 6.8, 18, 30, 9.5],
    ['Onion', 'Texas Grano', 'vegetable', 150, 32000, 4, '30 x 10 cm', 500, 6.0, 7.0, 13, 28, 8.0],
];
$crop = [];
$base = [];
foreach ($cropDefs as [$n, $v, $cat, $days, $y, $seed, $sp, $w, $pa, $pb, $ta, $tb, $price]) {
    $crop[$n] = ins('crops', ['farm_id' => $f1, 'name' => $n, 'variety' => $v, 'category' => $cat, 'days_to_maturity' => $days, 'expected_yield_kg_ha' => $y, 'seed_rate_kg_ha' => $seed, 'plant_spacing_cm' => $sp,
        'water_need_mm' => $w, 'optimal_ph_min' => $pa, 'optimal_ph_max' => $pb, 'optimal_temp_min' => $ta, 'optimal_temp_max' => $tb]);
    $base[$n] = $price;
}
$c2 = ins('crops', ['farm_id' => $f2, 'name' => 'Maize', 'variety' => 'SC 403', 'category' => 'cereal', 'days_to_maturity' => 130, 'expected_yield_kg_ha' => 6200, 'water_need_mm' => 520]);

$pest = [];
foreach ([
    ['Fall armyworm', 'pest', 'Maize, wheat', 'Ragged leaf damage, frass in the whorl.', 'Scout weekly; spray emamectin or cypermethrin early morning; destroy egg masses.'],
    ['Aphids', 'pest', 'Wheat, tomato, soybean', 'Curled leaves, honeydew, stunted growth.', 'Encourage natural enemies; spray imidacloprid or neem.'],
    ['Tuta absoluta (leaf miner)', 'pest', 'Tomato', 'Blotch mines in leaves, tunnelled fruit.', 'Pheromone traps; remove infested leaves; rotate spinosad / abamectin.'],
    ['Early blight', 'disease', 'Tomato', 'Dark concentric spots on older leaves.', 'Mancozeb every 7-10 days in humid weather; stake plants; rotate crops.'],
    ['Downy mildew', 'disease', 'Onion', 'Pale patches with grey-purple fuzz, collapsing tips.', 'Improve air flow; avoid night irrigation; metalaxyl + mancozeb.'],
] as [$n, $k, $a, $s, $c]) $pest[$n] = ins('pest_catalog', ['farm_id' => $f1, 'name' => $n, 'kind' => $k, 'affects' => $a, 'symptoms' => $s, 'control_measures' => $c]);

// ============================================================ suppliers & inventory
$sup = [];
foreach ([['seed', 'AgriSeed Zambia Ltd', 4.6], ['fertilizer', 'Zambia Fertiliser & Lime Co', 4.2], ['chemical', 'AgroChem Supplies', 4.0], ['feed', 'Feeds & Vet Hub', 4.4], ['general', 'Chongwe Farm Supplies', 4.1]] as [$cat, $n, $rt]) {
    $sup[$cat] = ins('suppliers', ['farm_id' => $f1, 'name' => $n, 'category' => $cat, 'contact_person' => 'Sales desk', 'phone' => '+26021125' . mt_rand(1000, 9999), 'rating' => $rt]);
}
$inv = [];
$invDefs = [ // name, category, unit, qty, reorder, unit cost, expiry offset, supplier
    ['Maize seed SC 513', 'seed', 'kg', 900, 400, 45, null, 'seed'], ['Tomato seed Rodade', 'seed', 'packet', 30, 15, 420, null, 'seed'],
    ['Compound D (10:20:10)', 'fertilizer', 'bag', 120, 50, 780, null, 'fertilizer'], ['Urea (46% N)', 'fertilizer', 'bag', 16, 40, 640, null, 'fertilizer'],
    ['Cypermethrin 200EC', 'chemical', 'litre', 26, 10, 210, 20, 'chemical'], ['Mancozeb 80WP', 'chemical', 'kg', 34, 15, 120, 380, 'chemical'],
    ['Imidacloprid 350SC', 'chemical', 'litre', 6, 8, 340, 300, 'chemical'], ['Chlorpyrifos 480EC', 'chemical', 'litre', 4, 5, 185, -12, 'chemical'],
    ['Dairy meal', 'feed', 'kg', 2600, 1500, 8.6, null, 'feed'], ['Layer mash', 'feed', 'kg', 950, 600, 9.4, null, 'feed'], ['FMD vaccine', 'vet_medicine', 'dose', 70, 40, 38, 24, 'feed'],
    ['Maize bags 50 kg', 'packaging', 'piece', 2400, 800, 6.5, null, 'general'], ['Tomato crates', 'packaging', 'piece', 300, 400, 38, null, 'general'],
    ['Diesel', 'fuel', 'litre', 4800, 1500, 28.5, null, 'general'], ['Knapsack sprayers', 'tools', 'piece', 6, 3, 650, null, 'general'],
];
foreach ($invDefs as [$n, $cat, $unit, $qty, $re, $cost, $exp, $sc]) {
    $inv[$n] = ins('inventory_items', ['farm_id' => $f1, 'name' => $n, 'category' => $cat, 'unit' => $unit, 'quantity' => $qty, 'reorder_level' => $re, 'unit_cost' => $cost, 'expiry_date' => $exp !== null ? d($exp) : null, 'supplier_id' => $sup[$sc]]);
    // consistent history: one opening stock-in, then stock-outs, ending at the recorded quantity
    $used = max(1, round($qty * rnd(0.15, 0.4), 0));
    ins('stock_movements', ['item_id' => $inv[$n], 'movement_type' => 'in', 'quantity' => $qty + $used, 'unit_cost' => $cost, 'movement_date' => d(-80), 'reference' => 'Opening stock', 'user_id' => $uid['manager']]);
    foreach ([-50, -25, -8] as $off) ins('stock_movements', ['item_id' => $inv[$n], 'movement_type' => 'out', 'quantity' => round($used / 3, 2), 'unit_cost' => $cost, 'movement_date' => d($off), 'reference' => 'Field use', 'user_id' => $uid['worker']]);
}

// ============================================================ equipment
$eq = [];
foreach ([ // name, type, model, purchase offset, cost, salvage, life, hours, service interval, fuel
    ['John Deere 5075E Tractor', 'tractor', 'JD 5075E 75hp', -2400, 520000, 90000, 12, 3420, 250, 'diesel'], ['Boom Sprayer 600L', 'sprayer', 'Jacto 600', -1500, 68000, 8000, 8, 880, 150, 'petrol'],
    ['Borehole Pump BH1', 'pump', 'Grundfos SP46', -1100, 55000, 5000, 10, 9800, 500, 'electric'], ['Toyota Hilux 4x4', 'vehicle', 'Hilux 2.8 GD-6', -1300, 650000, 220000, 8, 5200, 250, 'diesel'],
    ['Perkins Generator 60kVA', 'generator', 'Perkins 1104', -1700, 95000, 12000, 12, 2100, 250, 'diesel'],
] as [$n, $t, $mm, $pd, $pc, $sv, $life, $hrs, $si, $fuel]) {
    $eq[$n] = ins('equipment', ['farm_id' => $f1, 'name' => $n, 'equipment_type' => $t, 'make_model' => $mm, 'purchase_date' => d($pd), 'purchase_cost' => $pc, 'salvage_value' => $sv, 'useful_life_years' => $life,
        'total_hours' => $hrs, 'service_interval_hours' => $si, 'fuel_type' => $fuel, 'status' => $n === 'Boom Sprayer 600L' ? 'maintenance' : 'operational']);
}
foreach ([ // equipment, type, scheduled, completed, cost, description, status
    ['John Deere 5075E Tractor', 'service', -45, -44, 4800, '250-hour service: oil, filters, greasing', 'done'], ['Toyota Hilux 4x4', 'service', -60, -60, 3100, '10,000 km service', 'done'],
    ['Boom Sprayer 600L', 'repair', -3, null, 1800, 'Replace pump diaphragm and nozzles (overdue)', 'scheduled'], ['Perkins Generator 60kVA', 'service', 4, null, 2600, 'Scheduled service: oil and coolant', 'scheduled'],
    ['Borehole Pump BH1', 'inspection', 25, null, 1200, 'Pre-season pump inspection', 'scheduled'],
] as [$e, $type, $sched, $comp, $cost, $desc, $status]) {
    $mid = ins('maintenance_records', ['equipment_id' => $eq[$e], 'maintenance_type' => $type, 'scheduled_date' => d($sched), 'completed_date' => $comp !== null ? d($comp) : null, 'cost' => $cost, 'description' => $desc, 'status' => $status]);
    if ($status === 'done') Ledger::post($f1, 'expense', 'equipment', (float) $cost, d($comp), ucfirst($type) . ': ' . $desc, 'maintenance', $mid);
}

// ============================================================ workers, attendance, tasks, payroll
$wk = [];
foreach ([
    ['Joseph Phiri', 'Tractor operator', 'daily', 190, $uid['worker']], ['Mary Zulu', 'Farm supervisor', 'monthly', 7800, null], ['Peter Banda', 'Field worker', 'daily', 120, null],
    ['Grace Mumba', 'Field worker', 'daily', 120, null], ['Daniel Kunda', 'Herdsman', 'monthly', 4300, null], ['Rose Njovu', 'Irrigation technician', 'daily', 170, null],
] as [$n, $title, $wt, $rate, $u]) {
    $wk[$n] = ins('workers', ['farm_id' => $f1, 'user_id' => $u, 'name' => $n, 'job_title' => $title, 'phone' => '+26097' . mt_rand(1000000, 9999999), 'hire_date' => d(-mt_rand(300, 1500)), 'wage_type' => $wt, 'wage_rate' => $rate, 'status' => 'active']);
    ins('worker_reviews', ['worker_id' => $wk[$n], 'review_date' => d(-30), 'rating' => mt_rand(3, 5), 'comments' => pick(['Reliable and punctual.', 'Good attention to detail.', 'Strong team player.', 'Improving steadily.']), 'reviewer_id' => $uid['manager']]);
}
for ($off = -90; $off <= 0; $off++) {
    if ((int) gmdate('N', strtotime(d($off))) >= 6) continue;
    foreach ($wk as $wid) {
        $r = mt_rand(1, 100);
        if ($off === 0 && $r > 80) continue;
        $st = $r <= 90 ? 'present' : ($r <= 95 ? 'half' : ($r <= 98 ? 'absent' : 'leave'));
        ins('worker_attendance', ['worker_id' => $wid, 'work_date' => d($off), 'status' => $st, 'hours' => $st === 'half' ? 4 : ($st === 'present' ? 8 : 0)]);
    }
}
foreach ([2 => true, 1 => false] as $mo => $isPaid) { // two months ago: paid; last month: drafts awaiting payment
    $ps = gmdate('Y-m-01', strtotime("first day of -$mo months", strtotime($TODAY)));
    $pe = gmdate('Y-m-t', strtotime($ps));
    foreach ($wk as $n => $wid) {
        $w = Db::one('SELECT wage_type, wage_rate FROM workers WHERE id = ?', [$wid]);
        $days = (float) Db::val("SELECT IFNULL(SUM(CASE status WHEN 'present' THEN 1 WHEN 'half' THEN 0.5 ELSE 0 END),0) FROM worker_attendance WHERE worker_id = ? AND work_date BETWEEN ? AND ?", [$wid, $ps, $pe]) ?: 20;
        $gross = $w['wage_type'] === 'monthly' ? (float) $w['wage_rate'] : (float) $w['wage_rate'] * $days;
        $pid = ins('payroll_runs', ['worker_id' => $wid, 'period_start' => $ps, 'period_end' => $pe, 'days_worked' => $days, 'gross_pay' => $gross, 'deductions' => 0, 'net_pay' => $gross, 'status' => $isPaid ? 'paid' : 'draft', 'paid_at' => $isPaid ? "$pe 16:00:00" : null]);
        if ($isPaid) Ledger::post($f1, 'expense', 'labour', $gross, $pe, "Wages: $n", 'payroll', $pid);
    }
}
foreach ([ // title, worker, field, due, priority, status, hours
    ['Service boom sprayer', 'Peter Banda', null, -3, 'high', 'todo', 0], ['Top-dress tomatoes', 'Peter Banda', 'River Plot 1', 2, 'high', 'in_progress', 4], ['Weed onions', 'Grace Mumba', 'River Plot 2', 1, 'medium', 'in_progress', 6],
    ['Prepare North Block B for maize', 'Joseph Phiri', 'North Block B', 7, 'high', 'todo', 0], ['Wheat harvest preparation', 'Mary Zulu', 'Wheat Flats', 5, 'high', 'in_progress', 8],
    ['Calibrate pivot irrigation', 'Rose Njovu', 'North Block A', 3, 'medium', 'todo', 0], ['Repair feed-store door', 'Daniel Kunda', null, -2, 'low', 'todo', 0], ['Grade and pack onions', 'Grace Mumba', null, -6, 'medium', 'done', 14],
] as [$t, $w, $fl, $due, $pr, $st, $hrs]) {
    ins('tasks', ['farm_id' => $f1, 'title' => $t, 'worker_id' => $wk[$w], 'field_id' => $fl ? $fld[$fl] : null, 'due_date' => d($due), 'priority' => $pr, 'status' => $st, 'hours_spent' => $hrs, 'output_units' => $st === 'done' ? 12 : 0, 'completed_at' => $st === 'done' ? d($due) . ' 15:00:00' : null]);
}
ins('equipment_allocations', ['equipment_id' => $eq['John Deere 5075E Tractor'], 'field_id' => $fld['North Block B'], 'worker_id' => $wk['Joseph Phiri'], 'date_from' => d(-5), 'date_to' => d(10), 'purpose' => 'Land preparation for maize']);
foreach ([['John Deere 5075E Tractor', 2, 5.5, 'Joseph Phiri'], ['Toyota Hilux 4x4', 2, 2.2, 'Mary Zulu'], ['Perkins Generator 60kVA', 3, 3.0, 'Rose Njovu']] as [$en, $step, $hpd, $who]) {
    for ($i = 1; $i <= 30; $i += $step) {
        $hrs = rnd($hpd * 0.5, $hpd * 1.5, 1);
        $fuel = round($hrs * rnd(6, 11), 1);
        $id = ins('equipment_usage', ['equipment_id' => $eq[$en], 'usage_date' => d(-$i), 'hours' => $hrs, 'fuel_litres' => $fuel, 'fuel_cost' => round($fuel * 28.5, 2), 'worker_id' => $wk[$who]]);
        Ledger::post($f1, 'expense', 'fuel', round($fuel * 28.5, 2), d(-$i), 'Fuel: ' . $en, 'equip_usage', $id);
    }
}

// ============================================================ plantings, warehouses, harvests, storage
function planting(int $farm, int $field, int $crop, string $cropName, int $off, int $days, float $area, string $stage, string $status, string $note = ''): int
{
    global $cropDefs;
    $y = 0;
    foreach ($cropDefs as $c) if ($c[0] === $cropName) $y = $c[4];
    return ins('plantings', ['farm_id' => $farm, 'field_id' => $field, 'crop_id' => $crop, 'season' => season(d($off)), 'planting_date' => d($off), 'area_ha' => $area, 'expected_harvest_date' => d($off + $days),
        'growth_stage' => $stage, 'status' => $status, 'expected_yield_kg' => $y * $area, 'notes' => $note]);
}
$pl = [];
$hist = [ // key, field, crop, plant offset, days, ha, yield kg/ha, loss %
    ['maize', 'North Block A', 'Maize', -215, 140, 24, 7850, 4], ['tomato', 'River Plot 1', 'Tomato', -170, 100, 3, 41000, 9], ['onion', 'River Plot 2', 'Onion', -230, 150, 5, 29500, 7],
];
foreach ($hist as [$k, $fn, $cn, $off, $days, $ha]) $pl[$k] = planting($f1, $fld[$fn], $crop[$cn], $cn, $off, $days, (float) $ha, 'harvested', 'harvested');
$pl['p_tomato'] = planting($f1, $fld['River Plot 1'], $crop['Tomato'], 'Tomato', -50, 100, 3, 'fruiting', 'growing');
$pl['p_onion'] = planting($f1, $fld['River Plot 2'], $crop['Onion'], 'Onion', -85, 150, 5, 'vegetative', 'growing');
$pl['p_wheat'] = planting($f1, $fld['Wheat Flats'], $crop['Wheat'], 'Wheat', -90, 100, 15, 'maturity', 'growing');
$pl['p_soy'] = planting($f1, $fld['North Block A'], $crop['Soybean'], 'Soybean', -70, 120, 12, 'flowering', 'growing');
$pl['maize_B'] = planting($f1, $fld['North Block B'], $crop['Maize'], 'Maize', 8, 140, 18, 'planned', 'planned', 'Main rain-fed season maize.');
$pl['maize_W'] = planting($f1, $fld['West Block'], $crop['Maize'], 'Maize', 12, 140, 20, 'planned', 'planned', 'After lime application.');
planting($f2, $m2f, $c2, 'Maize', 15, 130, 12, 'planned', 'planned');

$wh = [
    'grain' => ins('warehouses', ['farm_id' => $f1, 'name' => 'Main Grain Store', 'warehouse_type' => 'dry', 'capacity_kg' => 150000, 'temperature_c' => 24, 'location' => 'Near the farmhouse']),
    'cold' => ins('warehouses', ['farm_id' => $f1, 'name' => 'Cold Room 1', 'warehouse_type' => 'cold', 'capacity_kg' => 15000, 'temperature_c' => 4, 'location' => 'Packhouse']),
];
foreach ($hist as [$k, $fn, $cn, $off, $days, $ha, $yha, $lossPct]) {
    $gross = round($yha * $ha / (1 - $lossPct / 100));
    $loss = round($gross * $lossPct / 100);
    foreach ([[0.85, 'A'], [0.15, 'B']] as [$share, $grade]) {
        ins('harvests', ['planting_id' => $pl[$k], 'harvest_date' => d($off + $days + 3), 'quantity_kg' => round($gross * $share), 'grade' => $grade, 'loss_kg' => round($loss * $share),
            'loss_reason' => pick(['Spillage and handling', 'Moisture damage', 'Transport bruising']), 'post_harvest_handling' => $cn === 'Tomato' ? 'Cooled within 4 h' : 'Dried to 12.5% moisture', 'packaging' => $cn === 'Tomato' ? 'Plastic crates' : '50 kg bags']);
    }
}
$lot = [];
foreach ([['LOT-MZ-A01', 'grain', 'Maize', 'A', 62000, -72, 365, 6.4], ['LOT-MZ-B02', 'grain', 'Maize', 'B', 18000, -72, 60, 5.2], ['LOT-ON-A01', 'cold', 'Onion', 'A', 8000, -76, 180, 8.4]] as [$code, $w, $cn, $g, $kg, $off, $max, $val]) {
    $lot[$code] = ins('storage_lots', ['warehouse_id' => $wh[$w], 'crop_id' => $crop[$cn], 'lot_code' => $code, 'quantity_kg' => $kg, 'grade' => $g, 'stored_date' => d($off), 'max_storage_days' => $max, 'spoilage_kg' => round($kg * 0.004), 'unit_value' => $val, 'status' => 'stored']);
}

// ============================================================ crop activities, growth, pests
$act = function (int $plId, string $type, int $off, string $product, string $item, float $perHa, float $ha, bool $scheduled = false) use ($inv, $f1) {
    $off = max($off, -88);
    $id = $item !== '' ? $inv[$item] : null;
    $qty = round($perHa * $ha, 2);
    $unit = $id ? (float) Db::val('SELECT unit_cost FROM inventory_items WHERE id = ?', [$id]) : 0;
    $cost = $scheduled ? 0 : round($qty * $unit + ($item === '' ? rnd(300, 1200, 0) : 0), 2);
    $aid = ins('crop_activities', ['planting_id' => $plId, 'activity_type' => $type, 'activity_date' => d($off), 'scheduled' => $scheduled ? 1 : 0, 'item_id' => $id, 'product_name' => $product, 'quantity' => $qty, 'cost' => $cost, 'posted' => $scheduled ? 0 : 1]);
    if (!$scheduled && $cost > 0) {
        $p = Db::one('SELECT field_id, crop_id FROM plantings WHERE id = ?', [$plId]);
        Ledger::post($f1, 'expense', 'input', $cost, d($off), ucfirst($type) . ': ' . $product, 'crop_activity', $aid, ['field_id' => $p['field_id'], 'crop_id' => $p['crop_id'], 'planting_id' => $plId]);
    }
};
foreach ($hist as [$k, $fn, $cn, $off, $days, $ha]) {
    $act($pl[$k], 'fertilizer', $off + 2, 'Basal Compound D', 'Compound D (10:20:10)', 6, (float) $ha);
    $act($pl[$k], 'fertilizer', $off + 40, 'Top dressing urea', 'Urea (46% N)', 5, (float) $ha);
    $act($pl[$k], 'spraying', $off + 55, 'Cover spray', 'Mancozeb 80WP', 2, (float) $ha);
}
$act($pl['p_tomato'], 'fertilizer', -48, 'Basal Compound D', 'Compound D (10:20:10)', 12, 3);
$act($pl['p_tomato'], 'spraying', -18, 'Mancozeb cover spray', 'Mancozeb 80WP', 3, 3);
$act($pl['p_tomato'], 'fertilizer', 2, 'Top dress urea', 'Urea (46% N)', 6, 3, true);
$act($pl['p_tomato'], 'spraying', 5, 'Leaf-miner round 2', 'Cypermethrin 200EC', 0.8, 3, true);
$act($pl['p_onion'], 'fertilizer', -83, 'Basal Compound D', 'Compound D (10:20:10)', 12, 5);
$act($pl['p_onion'], 'fertilizer', -45, 'Top dressing urea', 'Urea (46% N)', 8, 5);
$act($pl['p_wheat'], 'fertilizer', -88, 'Basal Compound D', 'Compound D (10:20:10)', 8, 15);
$act($pl['p_wheat'], 'fertilizer', -50, 'Top dressing urea', 'Urea (46% N)', 6, 15);
$act($pl['p_soy'], 'fertilizer', -68, 'Basal Compound D', 'Compound D (10:20:10)', 4, 12);
$act($pl['maize_B'], 'fertilizer', 8, 'Basal Compound D at planting', 'Compound D (10:20:10)', 6, 18, true);
foreach (['p_tomato' => [[-50, 'planted', 15], [-30, 'vegetative', 60], [-12, 'flowering', 110], [-2, 'fruiting', 140]], 'p_onion' => [[-85, 'planted', 5], [-55, 'vegetative', 35], [-20, 'vegetative', 55]],
    'p_wheat' => [[-90, 'planted', 5], [-55, 'vegetative', 40], [-25, 'flowering', 75], [-4, 'maturity', 90]], 'p_soy' => [[-70, 'planted', 4], [-40, 'vegetative', 35], [-8, 'flowering', 60]]] as $k => $rows) {
    foreach ($rows as [$off, $st, $h]) ins('growth_records', ['planting_id' => $pl[$k], 'record_date' => d($off), 'growth_stage' => $st, 'height_cm' => $h, 'health_score' => mt_rand(65, 94)]);
}
$sr = [];
foreach ([ // field, planting, pest, offset, severity, area %, status
    ['River Plot 1', 'p_tomato', 'Tuta absoluta (leaf miner)', -9, 'high', 22, 'treating'], ['River Plot 1', 'p_tomato', 'Early blight', -4, 'medium', 12, 'open'],
    ['River Plot 2', 'p_onion', 'Downy mildew', -3, 'critical', 45, 'open'], ['Wheat Flats', 'p_wheat', 'Aphids', -12, 'low', 6, 'resolved'], ['North Block A', 'p_soy', 'Aphids', -2, 'low', 4, 'open'],
] as $i => [$fn, $pk, $pn, $off, $sev, $pct, $st]) {
    $sr[$i] = ins('scouting_reports', ['field_id' => $fld[$fn], 'planting_id' => $pl[$pk], 'catalog_id' => $pest[$pn], 'report_date' => d($off), 'severity' => $sev, 'affected_area_pct' => $pct, 'status' => $st, 'reported_by' => $uid['agronomist']]);
}
foreach ([[0, -7, 'Cypermethrin 200EC', 2.4, 6], [4, -10, 'Imidacloprid 350SC', 0.5, 8]] as [$si, $off, $chem, $qty, $eff]) {
    $cost = round($qty * (float) Db::val('SELECT unit_cost FROM inventory_items WHERE id = ?', [$inv[$chem]]), 2);
    $tid = ins('treatments', ['scouting_id' => $sr[$si], 'treatment_date' => d($off), 'item_id' => $inv[$chem], 'chemical_name' => $chem, 'quantity' => $qty, 'cost' => $cost, 'effectiveness' => $eff, 'posted' => 1]);
    $p = Db::one('SELECT sr.field_id, sr.planting_id, pl.crop_id FROM scouting_reports sr LEFT JOIN plantings pl ON pl.id = sr.planting_id WHERE sr.id = ?', [$sr[$si]]);
    Ledger::post($f1, 'expense', 'input', $cost, d($off), 'Treatment: ' . $chem, 'treatment', $tid, ['field_id' => $p['field_id'], 'crop_id' => $p['crop_id'], 'planting_id' => $p['planting_id']]);
}

// ============================================================ irrigation
$ws = [];
foreach ([['BH1', 'Borehole BH1', 'borehole', 400000, 'active'], ['River', 'Chongwe River Intake', 'river', null, 'active'], ['Dam', 'Farm Dam', 'dam', 12000000, 'low']] as [$k, $n, $t, $cap, $st]) {
    $ws[$k] = ins('water_sources', ['farm_id' => $f1, 'name' => $n, 'source_type' => $t, 'capacity_litres' => $cap, 'status' => $st]);
}
$isys = [];
foreach ([ // name, field, source, type, flow L/h, status
    ['Centre Pivot – North Block A', 'North Block A', 'BH1', 'pivot', 180000, 'operational'], ['Drip – River Plot 1', 'River Plot 1', 'River', 'drip', 60000, 'operational'],
    ['Drip – River Plot 2', 'River Plot 2', 'River', 'drip', 50000, 'operational'], ['Sprinklers – Wheat Flats', 'Wheat Flats', 'BH1', 'sprinkler', 100000, 'operational'],
    ['Sprinklers – West Block', 'West Block', 'Dam', 'sprinkler', 80000, 'fault'],
] as [$n, $fn, $wsk, $type, $flow, $st]) {
    $isys[$n] = ins('irrigation_systems', ['farm_id' => $f1, 'field_id' => $fld[$fn], 'water_source_id' => $ws[$wsk], 'pump_equipment_id' => $eq['Borehole Pump BH1'], 'name' => $n, 'system_type' => $type, 'flow_rate_lph' => $flow, 'status' => $st]);
}
foreach ($isys as $n => $sid) {
    $i = Db::one('SELECT i.flow_rate_lph, i.status, f.size_ha FROM irrigation_systems i JOIN fields f ON f.id = i.field_id WHERE i.id = ?', [$sid]);
    if ($i['status'] !== 'operational') continue;
    $flow = (int) $i['flow_rate_lph'];
    for ($off = -90; $off <= -1; $off += (str_contains($n, 'Drip') ? 1 : 2)) { // ~4.5-6.5 mm per event; 1 mm over 1 ha = 10,000 L
        $litres = rnd(4.5, 6.5, 1) * (float) $i['size_ha'] * 10000;
        $dur = max(30, (int) round($litres / $flow * 60));
        $cost = round($dur / 60 * rnd(30, 45), 2);
        $rid = ins('irrigation_schedules', ['system_id' => $sid, 'scheduled_date' => d($off), 'duration_min' => $dur, 'planned_volume_litres' => (int) $litres, 'actual_volume_litres' => (int) round($litres * rnd(0.96, 1, 3)), 'energy_cost' => $cost, 'status' => 'done']);
        Ledger::post($f1, 'expense', 'utilities', $cost, d($off), 'Irrigation energy cost', 'irrigation', $rid);
    }
    foreach ([0, 1] as $off) {
        $litres = 5.5 * (float) $i['size_ha'] * 10000;
        ins('irrigation_schedules', ['system_id' => $sid, 'scheduled_date' => d($off), 'duration_min' => max(30, (int) round($litres / $flow * 60)), 'planned_volume_litres' => (int) $litres, 'energy_cost' => 150, 'status' => 'planned']);
    }
}

// ============================================================ livestock
$an = [];
$mk = function (string $tag, string $sp, string $breed, string $sex, int $ageDays, float $wt, string $status = 'active') use (&$an, $f1) {
    $an[$tag] = ins('animals', ['farm_id' => $f1, 'tag_no' => $tag, 'species' => $sp, 'breed' => $breed, 'sex' => $sex, 'birth_date' => d(-$ageDays), 'current_weight_kg' => $wt, 'status' => $status, 'source' => 'Born on farm']);
};
for ($i = 1; $i <= 6; $i++) $mk(sprintf('C-%03d', $i), 'cattle', 'Friesian cross', 'F', mt_rand(1100, 2400), rnd(430, 560, 1));
$mk('C-007', 'cattle', 'Friesian', 'M', 1900, 720);
for ($i = 8; $i <= 10; $i++) $mk(sprintf('C-%03d', $i), 'cattle', 'Friesian cross', 'F', mt_rand(300, 700), rnd(190, 330, 1));
for ($i = 1; $i <= 3; $i++) $mk(sprintf('G-%03d', $i), 'goat', 'Boer', 'F', mt_rand(400, 1200), rnd(40, 65, 1));
$mk('FLOCK-A', 'poultry', 'Hy-Line layers', 'F', 300, 1.9);
$mk('FLOCK-B', 'poultry', 'Hy-Line layers', 'F', 120, 1.5);
$mk('C-099', 'cattle', 'Friesian cross', 'F', 2400, 0, 'dead');
Db::exec("UPDATE animals SET death_date = ?, death_cause = 'Acute bloat' WHERE tag_no = 'C-099'", [d(-40)]);
for ($i = 1; $i <= 6; $i++) {
    $tag = sprintf('C-%03d', $i);
    ins('animal_health', ['animal_id' => $an[$tag], 'record_type' => 'vaccination', 'record_date' => d(-170 + $i), 'name' => 'FMD vaccine', 'item_id' => $inv['FMD vaccine'], 'next_due_date' => d($i <= 2 ? -5 + $i : ($i <= 4 ? 5 + $i : 150)), 'cost' => 38, 'vet_name' => 'Dr. Kaunda']);
}
ins('animal_health', ['animal_id' => $an['C-004'], 'record_type' => 'disease', 'record_date' => d(-20), 'name' => 'Mastitis — left rear quarter', 'cost' => 185, 'vet_name' => 'Dr. Kaunda']);
foreach (['C-001', 'C-002', 'C-008'] as $tag) {
    $w = (float) Db::val('SELECT current_weight_kg FROM animals WHERE id = ?', [$an[$tag]]);
    for ($m = 3; $m >= 0; $m--) ins('animal_weights', ['animal_id' => $an[$tag], 'weigh_date' => d(-$m * 30), 'weight_kg' => round($w - $m * rnd(2, 4.5), 1)]);
}
for ($w = 4; $w >= 1; $w--) {
    foreach ([['Dairy herd', 'Dairy meal', 520], ['Layer flocks', 'Layer mash', 190]] as [$grp, $feed, $kg]) {
        $cost = round($kg * (float) Db::val('SELECT unit_cost FROM inventory_items WHERE id = ?', [$inv[$feed]]), 2);
        $fid = ins('feed_records', ['farm_id' => $f1, 'group_name' => $grp, 'item_id' => $inv[$feed], 'feed_date' => d(-$w * 7), 'quantity_kg' => $kg, 'cost' => $cost, 'posted' => 1]);
        Ledger::post($f1, 'expense', 'livestock', $cost, d(-$w * 7), "Feed: $feed", 'feed', $fid);
    }
}
ins('breeding_records', ['dam_id' => $an['C-003'], 'sire_id' => $an['C-007'], 'mating_date' => d(-120), 'expected_birth_date' => d(163), 'status' => 'pregnant']);
ins('breeding_records', ['dam_id' => $an['C-001'], 'sire_id' => $an['C-007'], 'mating_date' => d(-300), 'expected_birth_date' => d(-17), 'actual_birth_date' => d(-17), 'offspring_count' => 1, 'status' => 'born']);
for ($off = -90; $off <= 0; $off += ($off > -30 ? 1 : 7)) {
    ins('livestock_production', ['farm_id' => $f1, 'species' => 'cattle', 'product' => 'milk', 'production_date' => d($off), 'quantity' => $off > -30 ? rnd(95, 120, 1) : rnd(95, 120, 1) * 7, 'unit' => 'litres']);
    ins('livestock_production', ['farm_id' => $f1, 'species' => 'poultry', 'product' => 'eggs', 'production_date' => d($off), 'quantity' => $off > -30 ? mt_rand(560, 640) : mt_rand(560, 640) * 7, 'unit' => 'eggs']);
}

// ============================================================ market, customers, sales
$cust = [];
foreach ([['Shoprite Fresh Produce (Lusaka DC)', 'retail', 'Lusaka'], ['Soweto Market Traders Assoc.', 'market', 'Soweto Market'], ['Zambia National Milling Co.', 'buyer', 'Kafue'], ['Premier Foods Processing', 'contract', 'Lusaka']] as [$n, $t, $dest]) {
    $cust[$n] = ins('customers', ['farm_id' => $f1, 'name' => $n, 'customer_type' => $t, 'contact_person' => 'Procurement desk', 'phone' => '+26021188' . mt_rand(1000, 9999), 'address' => $dest, 'market_destination' => $dest]);
}
$custIds = array_values($cust);
$peak = ['Maize' => 1, 'Soybean' => 11, 'Wheat' => 12, 'Tomato' => 8, 'Onion' => 1];
$amp = ['Maize' => 0.18, 'Soybean' => 0.12, 'Wheat' => 0.10, 'Tomato' => 0.28, 'Onion' => 0.22];
$priceAt = fn(string $cn, int $monthsAgo, int $month) => round($base[$cn] * (1 + 0.008 * (24 - $monthsAgo)) * (1 + $amp[$cn] * cos(2 * M_PI * ($month - $peak[$cn]) / 12)) * (1 + rnd(-0.04, 0.04, 3)), 2);
foreach ($crop as $cn => $cid) { // 24 monthly points: the seasonal price forecast needs a full two cycles
    for ($mAgo = 24; $mAgo >= 0; $mAgo--) {
        $date = gmdate('Y-m-15', strtotime("first day of -$mAgo months", strtotime($TODAY)));
        if ($date > $TODAY) $date = d(-3);
        $p = $priceAt($cn, $mAgo, (int) substr($date, 5, 2));
        ins('market_prices', ['farm_id' => $f1, 'crop_id' => $cid, 'market_name' => 'Soweto Market', 'price_date' => $date, 'price_per_kg' => $p, 'demand_level' => $p > $base[$cn] * 1.12 ? 'high' : ($p < $base[$cn] * 0.95 ? 'low' : 'medium')]);
    }
    ins('market_prices', ['farm_id' => $f1, 'crop_id' => $cid, 'market_name' => 'Soweto Market', 'price_date' => d(-2), 'price_per_kg' => $priceAt($cn, 0, (int) gmdate('n')), 'demand_level' => 'medium']);
}
$qty = ['Maize' => [8000, 30000], 'Soybean' => [2000, 9000], 'Wheat' => [5000, 15000], 'Tomato' => [1500, 5000], 'Onion' => [3000, 8000]];
$orders = [];
for ($n = 0; $n < 12; $n++) {
    $off = -mt_rand(3, 88);
    $date = d($off);
    $oid = ins('sales_orders', ['farm_id' => $f1, 'customer_id' => pick($custIds), 'order_no' => 'TMP-' . $n, 'order_date' => $date, 'delivery_date' => d($off + 3), 'status' => 'delivered']);
    $total = 0;
    foreach ((array) array_rand($qty, mt_rand(1, 2)) as $cn) {
        $q = round(mt_rand($qty[$cn][0], $qty[$cn][1]), -2);
        $price = $priceAt($cn, (int) floor(-$off / 30), (int) substr($date, 5, 2));
        ins('sales_order_items', ['order_id' => $oid, 'crop_id' => $crop[$cn], 'quantity_kg' => $q, 'unit_price' => $price, 'grade' => 'A']);
        $total += $q * $price;
    }
    $status = $off > -8 ? pick(['pending', 'confirmed']) : 'delivered';
    Db::update('sales_orders', $oid, ['order_no' => sprintf('SO-%s-%04d', substr($date, 0, 4), $oid), 'total_amount' => round($total, 2), 'status' => $status]);
    $orders[] = [$oid, $off, round($total, 2), $status, $date];
}
foreach ($orders as [$oid, $off, $total, $status, $date]) {
    if ($status === 'pending') continue;
    $due = gmdate('Y-m-d', strtotime("$date +14 days"));
    $iid = ins('invoices', ['farm_id' => $f1, 'order_id' => $oid, 'invoice_no' => 'TMP-' . $oid, 'issue_date' => $date, 'due_date' => $due, 'amount' => $total]);
    Db::update('invoices', $iid, ['invoice_no' => sprintf('INV-%s-%04d', substr($date, 0, 4), $iid)]);
    $r = mt_rand(1, 100);
    $mode = $off < -30 ? ($r <= 80 ? 'paid' : ($r <= 92 ? 'partial' : 'unpaid')) : ($r <= 30 ? 'paid' : 'unpaid');
    $paid = 0;
    if ($mode !== 'unpaid') {
        $amt = $mode === 'paid' ? $total : round($total * 0.5, 2);
        $pdate = min($TODAY, gmdate('Y-m-d', strtotime("$date +5 days")));
        $payId = ins('payments', ['invoice_id' => $iid, 'payment_date' => $pdate, 'amount' => $amt, 'method' => pick(['bank', 'mobile_money', 'cash']), 'reference' => 'PAY' . mt_rand(10000, 99999)]);
        Ledger::post($f1, 'income', 'sales', $amt, $pdate, 'Payment for invoice', 'payment', $payId);
        $paid = $amt;
    }
    Db::update('invoices', $iid, ['amount_paid' => $paid, 'status' => $paid <= 0 ? 'unpaid' : ($paid + 0.01 >= $total ? 'paid' : 'partial')]);
}
ins('dispatches', ['lot_id' => $lot['LOT-MZ-A01'], 'dispatch_date' => d(-30), 'quantity_kg' => 8000, 'vehicle' => 'ZM-HLX-4417', 'destination' => 'Zambia National Milling Co.', 'status' => 'delivered']);
ins('contracts', ['farm_id' => $f1, 'customer_id' => $cust['Premier Foods Processing'], 'contract_type' => 'offtake', 'crop_id' => $crop['Maize'], 'quantity_kg' => 120000, 'price_per_kg' => 6.4, 'start_date' => d(-60), 'end_date' => d(180), 'terms' => 'Monthly deliveries of 20 t, grade A.', 'status' => 'active']);
ins('contracts', ['farm_id' => $f1, 'supplier_id' => $sup['seed'], 'contract_type' => 'supply', 'start_date' => d(-90), 'end_date' => d(270), 'terms' => 'Preferred pricing on certified seed.', 'status' => 'active']);

// ============================================================ procurement
foreach ([ // supplier, offset, status, lines [item, qty, price], paid share
    ['seed', -75, 'received', [['Maize seed SC 513', 1200, 45]], 1.0], ['fertilizer', -60, 'received', [['Compound D (10:20:10)', 100, 780]], 0.5],
    ['fertilizer', -6, 'ordered', [['Urea (46% N)', 120, 660]], 0], ['chemical', -3, 'pending_approval', [['Imidacloprid 350SC', 12, 345]], 0], ['general', -1, 'draft', [['Tomato crates', 200, 38]], 0],
] as [$sc, $off, $st, $lines, $share]) {
    $po = ins('purchase_orders', ['farm_id' => $f1, 'supplier_id' => $sup[$sc], 'po_no' => 'TMP-PO' . $off, 'order_date' => d($off), 'expected_date' => d($off + 7), 'status' => $st,
        'approved_by' => in_array($st, ['ordered', 'received'], true) ? $uid['manager'] : null, 'received_at' => $st === 'received' ? d($off + 5) . ' 09:00:00' : null]);
    $total = 0;
    foreach ($lines as [$item, $q, $pr]) {
        ins('purchase_order_items', ['po_id' => $po, 'item_id' => $inv[$item], 'description' => $item, 'quantity' => $q, 'unit_price' => $pr]);
        $total += $q * $pr;
    }
    $paid = round($total * $share, 2);
    Db::update('purchase_orders', $po, ['po_no' => sprintf('PO-%s-%04d', gmdate('Y'), $po), 'total_amount' => $total, 'amount_paid' => $paid]);
    if ($paid > 0) Ledger::post($f1, 'expense', 'procurement', $paid, d($off + 8), 'Payments for PO ' . $po, 'po_paid', $po);
}
ins('quotations', ['farm_id' => $f1, 'supplier_id' => $sup['seed'], 'description' => 'Maize seed SC 513 (certified), 1,500 kg', 'quantity' => 1500, 'unit_price' => 47, 'valid_until' => d(14), 'status' => 'open']);
ins('quotations', ['farm_id' => $f1, 'supplier_id' => $sup['general'], 'description' => 'Diesel bulk delivery, 6,000 L', 'quantity' => 6000, 'unit_price' => 28.1, 'valid_until' => d(5), 'status' => 'open']);

// ============================================================ finance
for ($mAgo = 3; $mAgo >= 0; $mAgo--) {
    $md = min($TODAY, gmdate('Y-m-05', strtotime("first day of -$mAgo months", strtotime($TODAY))));
    ins('transactions', ['farm_id' => $f1, 'txn_type' => 'expense', 'category' => 'utilities', 'amount' => rnd(18000, 26000, 0), 'txn_date' => $md, 'description' => 'Electricity — packhouse, cold room, borehole', 'created_by' => $uid['accountant']]);
    ins('transactions', ['farm_id' => $f1, 'txn_type' => 'income', 'category' => 'sales', 'amount' => rnd(58000, 71000, 0), 'txn_date' => min($TODAY, gmdate('Y-m-26', strtotime($md))), 'description' => 'Milk sales — dairy collection', 'created_by' => $uid['accountant']]);
}
foreach (['input' => 600000, 'labour' => 300000, 'fuel' => 120000, 'equipment' => 60000, 'utilities' => 100000, 'procurement' => 300000] as $cat => $amt) {
    ins('budgets', ['farm_id' => $f1, 'budget_year' => (int) gmdate('Y'), 'category' => $cat, 'amount' => $amt]);
}
ins('loans', ['farm_id' => $f1, 'lender' => 'Agricultural Development Bank', 'purpose' => 'Pivot expansion', 'principal' => 1200000, 'interest_rate_pct' => 14, 'start_date' => d(-540), 'end_date' => d(320), 'balance' => 780000, 'status' => 'active']);
ins('loans', ['farm_id' => $f1, 'lender' => 'Zanaco Agri Finance', 'purpose' => 'Seasonal input finance', 'principal' => 400000, 'interest_rate_pct' => 17, 'start_date' => d(-300), 'end_date' => d(25), 'balance' => 150000, 'status' => 'active']);

// ============================================================ weather
$tmax = [1 => 27.5, 2 => 27.8, 3 => 28.0, 4 => 27.5, 5 => 25.8, 6 => 23.5, 7 => 23.5, 8 => 26.0, 9 => 30.0, 10 => 32.5, 11 => 31.0, 12 => 28.5];
$tmin = [1 => 17.5, 2 => 17.4, 3 => 16.8, 4 => 14.8, 5 => 11.5, 6 => 8.5, 7 => 8.2, 8 => 10.5, 9 => 14.5, 10 => 17.5, 11 => 18.0, 12 => 17.8];
$rainP = [1 => .58, 2 => .52, 3 => .40, 4 => .12, 5 => .03, 6 => 0, 7 => 0, 8 => 0, 9 => .02, 10 => .08, 11 => .30, 12 => .52];
$hum = [1 => 78, 2 => 77, 3 => 74, 4 => 62, 5 => 50, 6 => 46, 7 => 42, 8 => 38, 9 => 34, 10 => 36, 11 => 52, 12 => 72];
$wx = function (string $date) use ($tmax, $tmin, $rainP, $hum) {
    $m = (int) substr($date, 5, 2);
    $rain = mt_rand(1, 1000) / 1000 < $rainP[$m] ? rnd(1, 38, 1) : 0.0;
    $mx = round($tmax[$m] + rnd(-2.5, 2.5, 1) - ($rain > 0 ? 2.5 : 0), 1);
    $mn = round($tmin[$m] + rnd(-2, 2, 1), 1);
    return ['temp_min_c' => $mn, 'temp_max_c' => max($mx, $mn + 4), 'humidity_pct' => min(98, max(15, $hum[$m] + ($rain > 0 ? 12 : 0) + rnd(-8, 8, 0))), 'rainfall_mm' => $rain, 'wind_kph' => rnd(6, 24, 1),
        'conditions' => $rain > 15 ? 'Heavy rain' : ($rain > 0 ? 'Showers' : (mt_rand(0, 3) ? 'Mostly sunny' : 'Partly cloudy'))];
};
for ($off = -90; $off <= 0; $off++) ins('weather_records', ['farm_id' => $f1, 'record_date' => d($off), 'source' => 'station'] + $wx(d($off)));
for ($off = -30; $off <= 0; $off++) ins('weather_records', ['farm_id' => $f2, 'record_date' => d($off), 'source' => 'station'] + $wx(d($off)));
Db::exec("UPDATE weather_records SET rainfall_mm = 0, conditions = 'Mostly sunny' WHERE record_date = ? AND farm_id IN (?, ?)", [d(0), $f1, $f2]);
for ($off = 0; $off <= 6; $off++) {
    $w = $wx(d($off));
    ins('weather_forecasts', ['farm_id' => $f1, 'forecast_date' => d($off), 'temp_min_c' => $w['temp_min_c'], 'temp_max_c' => $w['temp_max_c'], 'humidity_pct' => $w['humidity_pct'], 'rain_probability_pct' => $w['rainfall_mm'] > 0 ? 60 : 10,
        'rainfall_mm' => $w['rainfall_mm'], 'wind_kph' => $w['wind_kph'], 'conditions' => $w['conditions'], 'source' => 'seed', 'fetched_at' => gmdate('Y-m-d H:i:s', strtotime('-1 day'))]);
}

// ============================================================ second farm (small)
foreach ([['Kondwani Mumba', 'Farm manager', 'monthly', 6200], ['Lucy Bwalya', 'Field worker', 'daily', 110]] as [$n, $t, $wt, $r]) {
    ins('workers', ['farm_id' => $f2, 'name' => $n, 'job_title' => $t, 'hire_date' => d(-800), 'wage_type' => $wt, 'wage_rate' => $r, 'status' => 'active']);
}
foreach ([['Maize seed', 'seed', 'kg', 600, 200, 44], ['Diesel', 'fuel', 'litre', 900, 300, 28.5]] as [$n, $c, $u, $q, $re, $co]) {
    ins('inventory_items', ['farm_id' => $f2, 'name' => $n, 'category' => $c, 'unit' => $u, 'quantity' => $q, 'reorder_level' => $re, 'unit_cost' => $co]);
}
ins('customers', ['farm_id' => $f2, 'name' => 'Mkushi Grain Traders', 'customer_type' => 'buyer', 'address' => 'Mkushi']);
for ($k = 1; $k <= 3; $k++) ins('animals', ['farm_id' => $f2, 'tag_no' => sprintf('M-%03d', $k), 'species' => 'cattle', 'breed' => 'Boran', 'sex' => $k === 1 ? 'M' : 'F', 'birth_date' => d(-mt_rand(500, 2000)), 'current_weight_kg' => rnd(300, 480, 1)]);

Db::pdo()->commit();

// ============================================================ derive alerts
require_once __DIR__ . '/../api/src/Registry.php';
require_once __DIR__ . '/../api/src/Weather.php';
require_once __DIR__ . '/../api/src/Predict.php';
require_once __DIR__ . '/../api/src/Alerts.php';
putenv('WEATHER_LIVE=0');
foreach ([$f1, $f2] as $f) AlertEngine::refresh($f);
@unlink(dirname(__DIR__) . '/storage/logs/notifications.log');

echo "Seed complete.\n\nDemo accounts (password: $demoPw)\n";
foreach ($users as [$n, $e, $r]) echo sprintf("  %-12s %s\n", $r, $e);
echo "  manager      manager2@ffms.test (second farm)\n";
