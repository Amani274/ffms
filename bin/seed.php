<?php

declare(strict_types=1);

/**
 * Loads a realistic, deterministic demo dataset relative to today's date so every screen has live-looking data.
 * Usage: php bin/seed.php            (only when the database is empty)
 *        php bin/seed.php --reset    (wipes all data first)
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

const TODAY = '__today__';
$TODAY = gmdate('Y-m-d');
function d(int $off): string { global $TODAY; return gmdate('Y-m-d', strtotime("$TODAY $off days")); }
function rnd(float $a, float $b, int $dec = 2): float { return round($a + mt_rand() / mt_getrandmax() * ($b - $a), $dec); }
function pick(array $a): mixed { return $a[array_rand($a)]; }
function ins(string $t, array $r): int { return Db::insert($t, $r); }
function season(string $date): string { $y = (int) substr($date, 0, 4); $m = (int) substr($date, 5, 2); return $m >= 7 ? "$y/" . substr((string) ($y + 1), 2) : ($y - 1) . '/' . substr((string) $y, 2); }
function square(float $lat, float $lon, float $s = 0.0035): string
{
    return json_encode(['type' => 'Polygon', 'coordinates' => [[[$lon - $s, $lat - $s], [$lon + $s, $lat - $s], [$lon + $s, $lat + $s], [$lon - $s, $lat + $s], [$lon - $s, $lat - $s]]]]);
}

Db::pdo()->beginTransaction();

// ============================================================ farms, users
$f1 = ins('farms', ['name' => 'Chongwe Valley Farm', 'location' => 'Chongwe, Lusaka Province', 'latitude' => -15.3292, 'longitude' => 28.6823, 'size_ha' => 185, 'registration_no' => 'ZM-AGR-2014-0412', 'contact_phone' => '+260971000111', 'description' => 'Mixed commercial farm: irrigated maize, soybean, wheat and vegetables with a dairy and layer unit.']);
$f2 = ins('farms', ['name' => 'Mkushi Highlands Farm', 'location' => 'Mkushi, Central Province', 'latitude' => -13.6167, 'longitude' => 29.3833, 'size_ha' => 60, 'registration_no' => 'ZM-AGR-2019-1177', 'contact_phone' => '+260972000222', 'description' => 'Rain-fed grain and groundnut farm.']);

$demoPw = Env::get('DEMO_PASSWORD', 'Farm@Demo2026');
$hash = Auth::hashPassword($demoPw);
$users = [
    ['System Administrator', 'admin@ffms.test', 'admin', null, '+260971100001'],
    ['Mulenga Chanda', 'owner@ffms.test', 'owner', null, '+260971100002'],
    ['Chileshe Mwansa', 'manager@ffms.test', 'manager', $f1, '+260971100003'],
    ['Dr. Namukolo Banda', 'agronomist@ffms.test', 'agronomist', $f1, '+260971100004'],
    ['Bwalya Tembo', 'accountant@ffms.test', 'accountant', $f1, '+260971100005'],
    ['Joseph Phiri', 'worker@ffms.test', 'worker', $f1, '+260971100006'],
];
$uid = [];
foreach ($users as [$n, $e, $r, $fm, $ph]) $uid[$r] = ins('users', ['name' => $n, 'email' => $e, 'password_hash' => $hash, 'role' => $r, 'farm_id' => $fm, 'phone' => $ph, 'active' => 1]);

// ============================================================ fields
$fieldDefs = [
    ['North Block A', 24, -15.3180, 28.6700, 'Loam', 6.4, 3.1, 42, 28, 190, 'cropping', 'Centre-pivot irrigated, deep well-drained soil.'],
    ['North Block B', 18, -15.3150, 28.6860, 'Clay loam', 6.1, 2.8, 38, 24, 175, 'cropping', 'Good moisture retention; occasional waterlogging in heavy rain.'],
    ['River Plot 1', 6, -15.3405, 28.6950, 'Sandy loam', 6.6, 2.2, 30, 31, 160, 'cropping', 'Drip-irrigated vegetable plot beside the river.'],
    ['River Plot 2', 5, -15.3430, 28.7010, 'Sandy loam', 6.8, 2.4, 33, 29, 165, 'cropping', 'Drip-irrigated vegetable plot.'],
    ['East Orchard', 8, -15.3290, 28.7120, 'Loam', 6.2, 3.4, 36, 26, 180, 'orchard', 'Citrus and mango trees.'],
    ['South Pasture', 40, -15.3560, 28.6600, 'Sandy clay', 6.0, 2.0, 25, 18, 140, 'pasture', 'Improved pasture (Rhodes grass) for the dairy herd.'],
    ['West Block', 20, -15.3300, 28.6450, 'Red clay', 5.4, 1.9, 22, 15, 120, 'cropping', 'Acidic soil — lime programme in progress.'],
    ['Wheat Flats', 15, -15.3050, 28.6750, 'Sandy clay loam', 6.9, 2.6, 40, 27, 185, 'cropping', 'Sprinkler-irrigated winter wheat field.'],
];
$fld = [];
foreach ($fieldDefs as $i => [$n, $ha, $la, $lo, $soil, $ph, $om, $N, $P, $K, $use, $note]) {
    $fld[$n] = ins('fields', ['farm_id' => $f1, 'name' => $n, 'size_ha' => $ha, 'latitude' => $la, 'longitude' => $lo, 'boundary_geojson' => square($la, $lo, 0.002 + $ha / 24000),
        'soil_type' => $soil, 'soil_ph' => $ph, 'organic_matter_pct' => $om, 'nitrogen_ppm' => $N, 'phosphorus_ppm' => $P, 'potassium_ppm' => $K, 'soil_notes' => $note, 'land_use' => $use, 'status' => 'active']);
}
$m2 = [];
foreach ([['Highland 1', 22, -13.6100, 29.3800, 'Loam', 6.2], ['Highland 2', 18, -13.6200, 29.3900, 'Sandy loam', 5.9], ['Valley Strip', 12, -13.6250, 29.3750, 'Clay loam', 6.5]] as [$n, $ha, $la, $lo, $soil, $ph]) {
    $m2[$n] = ins('fields', ['farm_id' => $f2, 'name' => $n, 'size_ha' => $ha, 'latitude' => $la, 'longitude' => $lo, 'boundary_geojson' => square($la, $lo, 0.0025), 'soil_type' => $soil, 'soil_ph' => $ph, 'organic_matter_pct' => 2.5, 'land_use' => 'cropping']);
}
foreach ([
    ['North Block A', '2023/24', 'Maize', 7400], ['North Block A', '2022/23', 'Soybean', 2650], ['West Block', '2023/24', 'Soybean', 1850],
    ['Wheat Flats', '2023/24', 'Wheat', 6000], ['River Plot 1', '2023/24', 'Tomato', 41500], ['North Block B', '2022/23', 'Maize', 6700],
] as [$fn, $s, $c, $y]) ins('field_history', ['field_id' => $fld[$fn], 'season' => $s, 'crop_name' => $c, 'yield_kg_per_ha' => $y, 'notes' => 'Imported from paper records']);

// ============================================================ crops & pests
$cropDefs = [
    // name, variety, category, days, yield kg/ha, seed kg/ha, spacing, plants/ha, water mm, phmin, phmax, tmin, tmax, base price
    ['Maize', 'SC 513', 'cereal', 140, 7500, 25, '75 x 25 cm', 53000, 550, 5.8, 7.0, 18, 32, 5.8],
    ['Soybean', 'Lukanga', 'legume', 120, 2800, 80, '45 x 5 cm', 440000, 450, 6.0, 7.0, 15, 30, 11.5],
    ['Wheat', 'Zambezi', 'cereal', 120, 6500, 120, '20 cm rows', 3000000, 450, 6.0, 7.5, 10, 25, 8.2],
    ['Tomato', 'Rodade', 'vegetable', 100, 45000, 0.3, '100 x 40 cm', 25000, 600, 6.0, 6.8, 18, 30, 9.5],
    ['Onion', 'Texas Grano', 'vegetable', 150, 32000, 4, '30 x 10 cm', 330000, 500, 6.0, 7.0, 13, 28, 8.0],
    ['Cabbage', 'Copenhagen', 'vegetable', 90, 38000, 0.4, '60 x 45 cm', 37000, 400, 6.0, 6.8, 12, 24, 4.2],
    ['Groundnut', 'Chalimbana', 'legume', 130, 2200, 100, '75 x 10 cm', 133000, 500, 5.5, 7.0, 20, 32, 22.0],
    ['Sunflower', 'Paradise', 'oilseed', 110, 2500, 6, '75 x 30 cm', 44000, 500, 6.0, 7.5, 18, 30, 9.5],
];
$crop = [];
$base = [];
foreach ($cropDefs as [$n, $v, $cat, $days, $y, $seed, $sp, $pl, $w, $pa, $pb, $ta, $tb, $price]) {
    $crop[$n] = ins('crops', ['farm_id' => $f1, 'name' => $n, 'variety' => $v, 'category' => $cat, 'days_to_maturity' => $days, 'expected_yield_kg_ha' => $y, 'seed_rate_kg_ha' => $seed,
        'plant_spacing_cm' => $sp, 'plants_per_ha' => $pl, 'water_need_mm' => $w, 'optimal_ph_min' => $pa, 'optimal_ph_max' => $pb, 'optimal_temp_min' => $ta, 'optimal_temp_max' => $tb, 'unit' => 'kg']);
    $base[$n] = $price;
}
$c2m = ins('crops', ['farm_id' => $f2, 'name' => 'Maize', 'variety' => 'SC 403', 'category' => 'cereal', 'days_to_maturity' => 130, 'expected_yield_kg_ha' => 6200, 'water_need_mm' => 520]);
$c2g = ins('crops', ['farm_id' => $f2, 'name' => 'Groundnut', 'variety' => 'Chalimbana', 'category' => 'legume', 'days_to_maturity' => 130, 'expected_yield_kg_ha' => 2000, 'water_need_mm' => 480, 'optimal_ph_min' => 5.5]);

$pestDefs = [
    ['Fall armyworm', 'pest', 'Maize, sorghum, wheat', 'Ragged leaf damage, frass in the whorl, small larvae on leaves.', 'Scout weekly from emergence. Spray emamectin benzoate or cypermethrin early morning; destroy egg masses; rotate chemical groups.'],
    ['Aphids', 'pest', 'Cabbage, tomato, wheat, soybean', 'Curled leaves, sticky honeydew, sooty mould, stunted growth.', 'Encourage natural enemies; spray imidacloprid or neem extract; avoid excess nitrogen.'],
    ['Tuta absoluta (leaf miner)', 'pest', 'Tomato', 'Irregular blotch mines in leaves; tunnelled fruit.', 'Pheromone traps; remove infested leaves; spray spinosad or abamectin on rotation.'],
    ['Red spider mite', 'pest', 'Soybean, tomato, beans', 'Fine webbing, yellow stippling on leaves, leaf drop in hot dry weather.', 'Irrigate to reduce dust and heat stress; spray abamectin or sulphur.'],
    ['Cutworms', 'pest', 'Maize, vegetables', 'Seedlings cut at ground level overnight.', 'Soil insecticide at planting; flood-irrigate before planting; hand-pick larvae.'],
    ['Early blight', 'disease', 'Tomato, potato', 'Dark concentric-ring spots on older leaves, defoliation.', 'Mancozeb or chlorothalonil every 7–10 days in humid weather; stake plants; rotate crops.'],
    ['Downy mildew', 'disease', 'Onion, cabbage', 'Pale patches with grey-purple fuzz on leaves, collapsing tips.', 'Improve air flow; avoid overhead irrigation at night; metalaxyl + mancozeb.'],
    ['Soybean rust', 'disease', 'Soybean', 'Small tan lesions on undersides of leaves, early defoliation.', 'Triazole/strobilurin fungicide at first sign; plant tolerant varieties.'],
    ['Maize streak virus', 'disease', 'Maize', 'Yellow streaks along leaf veins, stunting.', 'Use tolerant hybrids; control leafhopper vectors; early planting.'],
    ['Black jack (Bidens pilosa)', 'weed', 'All crops', 'Competes for light and nutrients; seeds cling to animals and clothing.', 'Pre-emergence herbicide; hoe weeding at 3 and 6 weeks.'],
];
$pest = [];
foreach ($pestDefs as [$n, $k, $a, $s, $c]) $pest[$n] = ins('pest_catalog', ['farm_id' => $f1, 'name' => $n, 'kind' => $k, 'affects' => $a, 'symptoms' => $s, 'control_measures' => $c]);

// ============================================================ suppliers, inventory
$supDefs = [
    ['AgriSeed Zambia Ltd', 'seed', 'Natasha Mumba', '+260211250100', 'sales@agriseed.example', 'Plot 14, Great East Road, Lusaka', 4.6],
    ['Zambia Fertiliser & Lime Co', 'fertilizer', 'Kelvin Sikazwe', '+260211250200', 'orders@zamfert.example', 'Industrial Area, Kafue', 4.2],
    ['AgroChem Supplies', 'chemical', 'Peter Kabwe', '+260211250300', 'info@agrochem.example', 'Lumumba Road, Lusaka', 4.0],
    ['Feeds & Vet Hub', 'feed', 'Dr. Alice Kaunda', '+260211250400', 'hub@feedsvet.example', 'Chilanga', 4.4],
    ['PackWell Packaging', 'packaging', 'Brian Zulu', '+260211250500', 'packwell@example.com', 'Heavy Industrial Area, Lusaka', 3.8],
    ['Chongwe Fuel & Lubricants', 'fuel', 'Mercy Nalwamba', '+260211250600', 'fuel@chongwefuel.example', 'Chongwe Town', 4.1],
];
$sup = [];
foreach ($supDefs as [$n, $cat, $cp, $ph, $em, $ad, $rt]) $sup[$cat] = ins('suppliers', ['farm_id' => $f1, 'name' => $n, 'category' => $cat, 'contact_person' => $cp, 'phone' => $ph, 'email' => $em, 'address' => $ad, 'rating' => $rt]);

$inv = [];
$invDefs = [
    // name, cat, unit, qty, reorder, cost, expiry offset|null, location, supplier cat
    ['Maize seed SC 513', 'seed', 'kg', 1400, 400, 45, null, 'Seed store', 'seed'],
    ['Soybean seed Lukanga', 'seed', 'kg', 650, 200, 38, null, 'Seed store', 'seed'],
    ['Wheat seed Zambezi', 'seed', 'kg', 1800, 600, 22, null, 'Seed store', 'seed'],
    ['Tomato seed Rodade', 'seed', 'packet', 38, 15, 420, null, 'Seed store', 'seed'],
    ['Cabbage seedlings', 'seed', 'tray', 18, 30, 95, null, 'Nursery', 'seed'],
    ['Compound D (10:20:10)', 'fertilizer', 'bag', 135, 50, 780, null, 'Fertiliser shed', 'fertilizer'],
    ['Urea (46% N)', 'fertilizer', 'bag', 16, 40, 640, null, 'Fertiliser shed', 'fertilizer'],
    ['Agricultural lime', 'fertilizer', 'bag', 220, 80, 120, null, 'Fertiliser shed', 'fertilizer'],
    ['Chicken manure', 'fertilizer', 'tonne', 24, 8, 350, null, 'Compost yard', 'fertilizer'],
    ['Cypermethrin 200EC', 'chemical', 'litre', 26, 10, 210, 20, 'Chemical store', 'chemical'],
    ['Mancozeb 80WP', 'chemical', 'kg', 34, 15, 120, 380, 'Chemical store', 'chemical'],
    ['Glyphosate 360SL', 'chemical', 'litre', 60, 20, 95, 420, 'Chemical store', 'chemical'],
    ['Imidacloprid 350SC', 'chemical', 'litre', 6, 8, 340, 300, 'Chemical store', 'chemical'],
    ['Chlorpyrifos 480EC', 'chemical', 'litre', 4, 5, 185, -12, 'Chemical store', 'chemical'],
    ['Dairy meal', 'feed', 'kg', 3200, 1500, 8.6, null, 'Feed store', 'feed'],
    ['Layer mash', 'feed', 'kg', 950, 600, 9.4, null, 'Feed store', 'feed'],
    ['Maize bran', 'feed', 'kg', 380, 500, 3.2, null, 'Feed store', 'feed'],
    ['Oxytetracycline LA', 'vet_medicine', 'bottle', 12, 5, 185, 240, 'Vet cabinet', 'feed'],
    ['FMD vaccine', 'vet_medicine', 'dose', 70, 40, 38, 24, 'Cold chain fridge', 'feed'],
    ['Dewormer (albendazole)', 'vet_medicine', 'bottle', 9, 4, 120, 500, 'Vet cabinet', 'feed'],
    ['Newcastle vaccine', 'vet_medicine', 'dose', 400, 150, 2.5, 70, 'Cold chain fridge', 'feed'],
    ['Maize bags 50 kg', 'packaging', 'piece', 2400, 800, 6.5, null, 'Main grain store', 'packaging'],
    ['Tomato crates', 'packaging', 'piece', 300, 400, 38, null, 'Packhouse', 'packaging'],
    ['Onion mesh nets', 'packaging', 'piece', 1500, 500, 3.2, null, 'Packhouse', 'packaging'],
    ['Diesel', 'fuel', 'litre', 4800, 1500, 28.5, null, 'Fuel tank', 'fuel'],
    ['Petrol', 'fuel', 'litre', 320, 150, 31.2, null, 'Fuel tank', 'fuel'],
    ['Knapsack sprayers', 'tools', 'piece', 6, 3, 650, null, 'Tool shed', 'chemical'],
    ['Hand hoes', 'tools', 'piece', 40, 15, 85, null, 'Tool shed', 'chemical'],
];
foreach ($invDefs as [$n, $cat, $unit, $qty, $re, $cost, $exp, $loc, $sc]) {
    $inv[$n] = ins('inventory_items', ['farm_id' => $f1, 'name' => $n, 'category' => $cat, 'unit' => $unit, 'quantity' => $qty, 'reorder_level' => $re, 'unit_cost' => $cost,
        'expiry_date' => $exp !== null ? d($exp) : null, 'storage_location' => $loc, 'supplier_id' => $sup[$sc]]);
}
// Consistent movement history: one opening stock-in, then spread-out stock-outs, ending exactly at the recorded quantity.
foreach ($invDefs as [$n, $cat, $unit, $qty, $re, $cost]) {
    $used = round($qty * rnd(0.15, 0.45), 0) ?: 1;
    ins('stock_movements', ['item_id' => $inv[$n], 'movement_type' => 'in', 'quantity' => $qty + $used, 'unit_cost' => $cost, 'movement_date' => d(-90), 'reference' => 'Opening stock', 'notes' => 'Received from supplier', 'user_id' => $uid['manager']]);
    $parts = mt_rand(2, 4);
    for ($i = 0; $i < $parts; $i++) {
        $q = round($used / $parts, 2);
        ins('stock_movements', ['item_id' => $inv[$n], 'movement_type' => 'out', 'quantity' => $q, 'unit_cost' => $cost, 'movement_date' => d(-mt_rand(2, 80)), 'reference' => 'Field use', 'notes' => null, 'user_id' => $uid['worker']]);
    }
}

// ============================================================ equipment, maintenance, usage
$eqDefs = [
    ['John Deere 5075E Tractor', 'tractor', 'JD 5075E 75hp', 'JD5075-88214', -2400, 520000, 90000, 12, 3420, 250, 'diesel'],
    ['Massey Ferguson 290 Tractor', 'tractor', 'MF 290', 'MF290-11873', -3600, 310000, 50000, 15, 6150, 250, 'diesel'],
    ['Disc Plough 4-disc', 'implement', 'Baldan 4D', 'BAL-4D-0932', -2400, 42000, 6000, 10, 1400, null, null],
    ['Boom Sprayer 600L', 'sprayer', 'Jacto 600', 'JAC-600-5521', -1500, 68000, 8000, 8, 880, 150, 'petrol'],
    ['Combine Harvester', 'harvester', 'CLAAS Lexion 540', 'CL-540-2207', -1800, 1800000, 300000, 15, 1650, 200, 'diesel'],
    ['Borehole Pump BH1', 'pump', 'Grundfos SP46', 'GR-SP46-771', -1100, 55000, 5000, 10, 9800, 500, 'electric'],
    ['River Diesel Pump', 'pump', 'Lowara 3SV', 'LOW-3SV-310', -900, 38000, 4000, 8, 2300, 300, 'diesel'],
    ['Toyota Hilux 4x4', 'vehicle', 'Hilux 2.8 GD-6', 'ZM-HLX-4417', -1300, 650000, 220000, 8, 5200, 250, 'diesel'],
    ['Perkins Generator 60kVA', 'generator', 'Perkins 1104', 'PK-60-1180', -1700, 95000, 12000, 12, 2100, 250, 'diesel'],
];
$eq = [];
foreach ($eqDefs as [$n, $t, $mm, $sn, $pd, $pc, $sv, $life, $hrs, $si, $fuel]) {
    $eq[$n] = ins('equipment', ['farm_id' => $f1, 'name' => $n, 'equipment_type' => $t, 'make_model' => $mm, 'serial_no' => $sn, 'purchase_date' => d($pd), 'purchase_cost' => $pc, 'salvage_value' => $sv,
        'useful_life_years' => $life, 'total_hours' => $hrs, 'service_interval_hours' => $si, 'fuel_type' => $fuel, 'status' => 'operational']);
}
Db::exec("UPDATE equipment SET status = 'maintenance' WHERE id = ?", [$eq['Boom Sprayer 600L']]);
$maint = [
    [$eq['John Deere 5075E Tractor'], 'service', -120, -118, 4800, '250-hour service: oil, filters, greasing', 130, 'done'],
    [$eq['John Deere 5075E Tractor'], 'repair', -45, -44, 2350, 'Replaced hydraulic hose', null, 'done'],
    [$eq['Massey Ferguson 290 Tractor'], 'service', -80, -79, 5200, '250-hour service and clutch adjustment', 170, 'done'],
    [$eq['Combine Harvester'], 'service', -200, -198, 18500, 'Pre-season overhaul: belts, bearings, concave', 165, 'done'],
    [$eq['Toyota Hilux 4x4'], 'service', -95, -95, 3100, '10,000 km service', 85, 'done'],
    [$eq['Borehole Pump BH1'], 'inspection', -60, -60, 900, 'Annual pump and starter inspection', 305, 'done'],
    [$eq['Boom Sprayer 600L'], 'repair', -3, null, 1800, 'Replace pump diaphragm and nozzles (overdue)', null, 'scheduled'],
    [$eq['Perkins Generator 60kVA'], 'service', 4, null, 2600, 'Scheduled service: oil and coolant', null, 'scheduled'],
    [$eq['Massey Ferguson 290 Tractor'], 'service', 12, null, 5200, '250-hour service due', null, 'scheduled'],
    [$eq['River Diesel Pump'], 'inspection', 25, null, 1200, 'Pre-season inspection', null, 'scheduled'],
];
foreach ($maint as [$e, $type, $sched, $comp, $cost, $desc, $next, $status]) {
    $mid = ins('maintenance_records', ['equipment_id' => $e, 'maintenance_type' => $type, 'scheduled_date' => d($sched), 'completed_date' => $comp !== null ? d($comp) : null, 'cost' => $cost,
        'description' => $desc, 'next_due_date' => $next !== null ? d($next) : null, 'status' => $status]);
    if ($status === 'done') Ledger::post($f1, 'expense', 'equipment', (float) $cost, d($comp), ucfirst($type) . ': ' . $desc, 'maintenance', $mid);
}

// ============================================================ workers, attendance, tasks, payroll
$wDefs = [
    ['Joseph Phiri', 'Tractor operator', 'daily', 190, $uid['worker'], -1400], ['Mary Zulu', 'Farm supervisor', 'monthly', 7800, null, -2100], ['Peter Banda', 'Field worker', 'daily', 120, null, -900],
    ['Grace Mumba', 'Field worker', 'daily', 120, null, -800], ['Samuel Tembo', 'Field worker', 'daily', 120, null, -700], ['Ruth Lungu', 'Packhouse assistant', 'daily', 130, null, -650],
    ['Patrick Mulenga', 'Sprayer operator', 'daily', 150, null, -1100], ['Agnes Mwale', 'Field worker', 'daily', 120, null, -500], ['Bernard Chola', 'Security guard', 'monthly', 3900, null, -1700],
    ['Esther Nkonde', 'Dairy milker', 'daily', 135, null, -600], ['Moses Daka', 'Field worker', 'daily', 120, null, -400], ['Faith Sakala', 'Packhouse assistant', 'daily', 130, null, -300],
    ['Daniel Kunda', 'Herdsman', 'monthly', 4300, null, -1500], ['Rose Njovu', 'Irrigation technician', 'daily', 170, null, -1000],
];
$wk = [];
foreach ($wDefs as [$n, $title, $wt, $rate, $u, $hd]) {
    $wk[$n] = ins('workers', ['farm_id' => $f1, 'user_id' => $u, 'name' => $n, 'job_title' => $title, 'phone' => '+26097' . mt_rand(1000000, 9999999), 'national_id' => mt_rand(100000, 999999) . '/10/1', 'hire_date' => d($hd), 'wage_type' => $wt, 'wage_rate' => $rate, 'status' => 'active']);
}
$wIds = array_values($wk);
for ($off = -75; $off <= 0; $off++) {
    $dow = (int) gmdate('N', strtotime(d($off)));
    if ($dow >= 6) continue;
    foreach ($wk as $n => $wid) {
        $r = mt_rand(1, 100);
        $status = $off === 0 ? ($r <= 80 ? 'present' : 'absent') : ($r <= 90 ? 'present' : ($r <= 94 ? 'half' : ($r <= 98 ? 'absent' : 'leave')));
        if ($off === 0 && $r > 80) continue;
        ins('worker_attendance', ['worker_id' => $wid, 'work_date' => d($off), 'status' => $status, 'hours' => $status === 'half' ? 4 : ($status === 'present' ? 8 : 0)]);
    }
}
// payroll: two months ago is paid; last month is generated as drafts awaiting review and payment
for ($mo = 2; $mo >= 1; $mo--) {
    $ps = gmdate('Y-m-01', strtotime("first day of -$mo months", strtotime($TODAY)));
    $pe = gmdate('Y-m-t', strtotime($ps));
    foreach ($wDefs as [$n, $title, $wt, $rate]) {
        $a = Db::one("SELECT IFNULL(SUM(CASE status WHEN 'present' THEN 1 WHEN 'half' THEN 0.5 ELSE 0 END),0) AS days FROM worker_attendance WHERE worker_id = ? AND work_date BETWEEN ? AND ?", [$wk[$n], $ps, $pe]);
        $days = (float) $a['days'] ?: ($mo === 2 ? 20 : 21);
        $gross = $wt === 'monthly' ? (float) $rate : $rate * $days;
        $ded = round($gross * 0.05, 2);
        $isPaid = $mo === 2;
        $pid = ins('payroll_runs', ['worker_id' => $wk[$n], 'period_start' => $ps, 'period_end' => $pe, 'days_worked' => $days, 'gross_pay' => $gross, 'deductions' => $ded, 'net_pay' => $gross - $ded, 'status' => $isPaid ? 'paid' : 'draft', 'paid_at' => $isPaid ? $pe . ' 16:00:00' : null]);
        if ($isPaid) Ledger::post($f1, 'expense', 'labour', $gross - $ded, $pe, "Wages: $n", 'payroll', $pid);
    }
}
$tasksDefs = [
    ['Service boom sprayer before wheat spraying', 'Replace diaphragm and nozzles', 'Patrick Mulenga', null, -3, 'high', 'todo', 0],
    ['Top-dress tomatoes with CAN', 'Apply 150 kg/ha along drip lines', 'Peter Banda', 'River Plot 1', 2, 'high', 'in_progress', 4],
    ['Weed onions — River Plot 2', 'Hand weeding between rows', 'Grace Mumba', 'River Plot 2', 1, 'medium', 'in_progress', 6],
    ['Scout tomatoes for Tuta absoluta', 'Check pheromone traps and mark infested leaves', 'Samuel Tembo', 'River Plot 1', 0, 'high', 'todo', 0],
    ['Prepare North Block B for maize planting', 'Disc ploughing and ridging', 'Joseph Phiri', 'North Block B', 7, 'high', 'todo', 0],
    ['Lime application — West Block', 'Spread 2 t/ha agricultural lime', 'Moses Daka', 'West Block', 10, 'medium', 'todo', 0],
    ['Repair fence on South Pasture', 'Replace broken posts near the gate', 'Bernard Chola', 'South Pasture', -2, 'low', 'todo', 0],
    ['Vaccinate heifers (FMD booster)', 'Dairy heifers group', 'Daniel Kunda', null, 4, 'high', 'todo', 0],
    ['Calibrate pivot irrigation', 'Check nozzle package and uniformity', 'Rose Njovu', 'North Block A', 3, 'medium', 'todo', 0],
    ['Harvest preparation: wheat', 'Service combine, arrange bags and transport', 'Mary Zulu', 'Wheat Flats', 5, 'high', 'in_progress', 8],
    ['Grade and pack onions', 'Cure, grade and net 2 t', 'Ruth Lungu', null, -6, 'medium', 'done', 14],
    ['Milking schedule review', 'Move evening milking to 16:30', 'Esther Nkonde', null, -8, 'low', 'done', 2],
    ['Spray cabbage for aphids', 'Imidacloprid at label rate', 'Patrick Mulenga', 'River Plot 1', -4, 'medium', 'done', 5],
    ['Clean cold room', 'Sanitise and calibrate thermostat', 'Faith Sakala', null, -10, 'low', 'done', 3],
    ['Install drip repair kit', 'Replace 40 emitters on River Plot 2', 'Rose Njovu', 'River Plot 2', -12, 'medium', 'done', 7],
    ['Count and tag calves', 'Update ear tags and register', 'Daniel Kunda', null, -15, 'medium', 'done', 4],
];
foreach ($tasksDefs as [$t, $desc, $w, $fl, $due, $pr, $st, $hrs]) {
    ins('tasks', ['farm_id' => $f1, 'title' => $t, 'description' => $desc, 'field_id' => $fl ? $fld[$fl] : null, 'worker_id' => $wk[$w], 'due_date' => d($due), 'priority' => $pr, 'status' => $st,
        'hours_spent' => $hrs, 'output_units' => $st === 'done' ? rnd(1, 20, 1) : 0, 'completed_at' => $st === 'done' ? d($due) . ' 15:00:00' : null]);
}
foreach ($wk as $n => $wid) {
    ins('worker_reviews', ['worker_id' => $wid, 'review_date' => d(-30), 'rating' => mt_rand(3, 5), 'comments' => pick(['Reliable and punctual.', 'Good attention to detail.', 'Needs more initiative on tasks.', 'Strong team player.', 'Improving steadily.']), 'reviewer_id' => $uid['manager']]);
}
foreach ($eq as $n => $eid) {
    if (!in_array($n, ['John Deere 5075E Tractor', 'Massey Ferguson 290 Tractor'], true)) continue;
    ins('equipment_allocations', ['equipment_id' => $eid, 'field_id' => $fld['North Block B'], 'worker_id' => $wk['Joseph Phiri'], 'date_from' => d(-5), 'date_to' => d(10), 'purpose' => 'Land preparation for maize']);
}
foreach ([['John Deere 5075E Tractor', 90, 5.5, 'Joseph Phiri'], ['Massey Ferguson 290 Tractor', 50, 4.5, 'Joseph Phiri'], ['Toyota Hilux 4x4', 60, 2.2, 'Mary Zulu'], ['Perkins Generator 60kVA', 40, 3.0, 'Rose Njovu'], ['River Diesel Pump', 35, 3.0, 'Rose Njovu']] as [$en, $n, $hpd, $who]) {
    for ($i = 0; $i < $n; $i += 2) {
        $hrs = rnd($hpd * 0.5, $hpd * 1.5, 1);
        $fuel = round($hrs * rnd(6, 11), 1);
        $uidv = ins('equipment_usage', ['equipment_id' => $eq[$en], 'usage_date' => d(-$i), 'hours' => $hrs, 'fuel_litres' => $fuel, 'fuel_cost' => round($fuel * 28.5, 2), 'worker_id' => $wk[$who], 'field_id' => in_array($en, ['John Deere 5075E Tractor', 'Massey Ferguson 290 Tractor'], true) ? $fld[pick(['North Block A', 'North Block B', 'Wheat Flats'])] : null, 'notes' => null]);
        Ledger::post($f1, 'expense', 'fuel', round($fuel * 28.5, 2), d(-$i), 'Fuel: ' . $en, 'equip_usage', $uidv);
    }
}

// ============================================================ plantings (history + current + planned)
$pl = [];
function planting(int $farm, int $field, int $crop, string $cropName, int $plantOff, int $days, float $area, string $stage, string $status, ?float $expectedKg = null, string $note = ''): int
{
    global $cropDefs;
    $y = 0;
    foreach ($cropDefs as $c) if ($c[0] === $cropName) $y = $c[4];
    $pd = d($plantOff);
    return ins('plantings', ['farm_id' => $farm, 'field_id' => $field, 'crop_id' => $crop, 'season' => season($pd), 'planting_date' => $pd, 'area_ha' => $area, 'expected_harvest_date' => d($plantOff + $days),
        'growth_stage' => $stage, 'status' => $status, 'expected_yield_kg' => $expectedKg ?? $y * $area, 'notes' => $note]);
}
// completed seasons (feed prediction history + profitability)
$hist = [
    ['H_maize_A', 'North Block A', 'Maize', -330, 140, 24, 7850, 4, 'Good season, timely rains and full fertiliser programme.'],
    ['H_maize_B', 'North Block B', 'Maize', -325, 140, 18, 6900, 5, 'Some waterlogging in January.'],
    ['H_maize_W', 'West Block', 'Maize', -330, 140, 20, 5400, 6, 'Acidic soil limited yield.'],
    ['H_soy_W', 'West Block', 'Soybean', -300, 120, 20, 1900, 5, 'Low pH reduced nodulation.'],
    ['H_wheat', 'Wheat Flats', 'Wheat', -480, 120, 15, 6100, 3, 'Winter wheat, sprinkler irrigated.'],
    ['H_tom_1', 'River Plot 1', 'Tomato', -250, 100, 3, 41000, 9, 'Leaf miner pressure mid-season.'],
    ['H_tom_0', 'River Plot 1', 'Tomato', -420, 100, 3, 43500, 8, 'Excellent fruit set.'],
    ['H_onion', 'River Plot 2', 'Onion', -300, 150, 5, 29500, 7, 'Slight bulb splitting after irrigation fault.'],
    ['H_cab', 'River Plot 2', 'Cabbage', -200, 90, 4, 36500, 6, 'Good heads, aphid pressure managed.'],
];
foreach ($hist as [$key, $fn, $cn, $off, $days, $ha, $yha, $lossPct, $note]) {
    $pl[$key] = planting($f1, $fld[$fn], $crop[$cn], $cn, $off, $days, (float) $ha, 'harvested', 'harvested', null, $note);
}
// current season
$pl['tomato'] = planting($f1, $fld['River Plot 1'], $crop['Tomato'], 'Tomato', -62, 100, 3, 'fruiting', 'growing');
$pl['cabbage'] = planting($f1, $fld['River Plot 1'], $crop['Cabbage'], 'Cabbage', -40, 90, 2, 'vegetative', 'growing');
$pl['onion'] = planting($f1, $fld['River Plot 2'], $crop['Onion'], 'Onion', -105, 150, 5, 'vegetative', 'growing');
$pl['wheat'] = planting($f1, $fld['Wheat Flats'], $crop['Wheat'], 'Wheat', -112, 120, 15, 'maturity', 'growing');
$pl['soy'] = planting($f1, $fld['North Block A'], $crop['Soybean'], 'Soybean', -95, 120, 12, 'maturity', 'growing', null, 'Dry-season irrigated soybean under the pivot.');
$pl['maize_B'] = planting($f1, $fld['North Block B'], $crop['Maize'], 'Maize', 16, 140, 18, 'planned', 'planned', null, 'Main rain-fed season maize.');
$pl['maize_W'] = planting($f1, $fld['West Block'], $crop['Maize'], 'Maize', 20, 140, 20, 'planned', 'planned', null, 'After lime application.');
$pl2 = planting($f2, $m2['Highland 1'], $c2m, 'Maize', -330, 130, 22, 'harvested', 'harvested', 22 * 6200);
$pl2g = planting($f2, $m2['Highland 2'], $c2g, 'Groundnut', -310, 130, 18, 'harvested', 'harvested', 18 * 2000);

// harvests for completed plantings + storage lots
$wh = [
    'grain' => ins('warehouses', ['farm_id' => $f1, 'name' => 'Main Grain Store', 'warehouse_type' => 'dry', 'capacity_kg' => 400000, 'temperature_c' => 24, 'location' => 'Near the farmhouse']),
    'cold' => ins('warehouses', ['farm_id' => $f1, 'name' => 'Cold Room 1', 'warehouse_type' => 'cold', 'capacity_kg' => 20000, 'temperature_c' => 4, 'location' => 'Packhouse']),
    'silo' => ins('warehouses', ['farm_id' => $f1, 'name' => 'Silo A', 'warehouse_type' => 'silo', 'capacity_kg' => 250000, 'temperature_c' => 22, 'location' => 'North yard']),
];
$harvest = [];
foreach ($hist as [$key, $fn, $cn, $off, $days, $ha, $yha, $lossPct]) {
    $gross = round($yha * $ha / (1 - $lossPct / 100));
    $loss = round($gross * $lossPct / 100);
    $hd = d($off + $days + mt_rand(0, 6));
    $split = in_array($cn, ['Maize', 'Wheat', 'Soybean'], true) ? [[0.86, 'A'], [0.14, 'B']] : [[0.8, 'A'], [0.2, 'B']];
    foreach ($split as [$share, $grade]) {
        $harvest[] = ins('harvests', ['planting_id' => $pl[$key], 'harvest_date' => $hd, 'quantity_kg' => round($gross * $share), 'grade' => $grade, 'quality_notes' => $grade === 'A' ? 'Clean, uniform' : 'Minor defects',
            'loss_kg' => round($loss * $share), 'loss_reason' => pick(['Spillage and handling', 'Moisture damage', 'Pest damage', 'Transport bruising']),
            'post_harvest_handling' => in_array($cn, ['Tomato', 'Cabbage'], true) ? 'Cooled within 4 h' : 'Dried to 12.5% moisture', 'packaging' => in_array($cn, ['Tomato'], true) ? 'Plastic crates' : '50 kg bags']);
    }
}
foreach ([[$pl2, 22 * 6200, 'Maize', -330, 130], [$pl2g, 18 * 2000, 'Groundnut', -310, 130]] as [$p, $kg, $n, $off, $days]) {
    ins('harvests', ['planting_id' => $p, 'harvest_date' => d($off + $days), 'quantity_kg' => $kg * 0.97, 'grade' => 'A', 'loss_kg' => $kg * 0.03]);
}
$lots = [
    [$wh['grain'], 'Maize', 'A', 62000, -185, 365, 6.4, 'LOT-MZ-A01'], [$wh['grain'], 'Maize', 'B', 18000, -186, 150, 5.2, 'LOT-MZ-B02'],
    [$wh['silo'], 'Soybean', 'A', 14500, -165, 365, 11.8, 'LOT-SB-A01'], [$wh['grain'], 'Wheat', 'A', 21000, -350, 540, 8.4, 'LOT-WH-A01'],
    [$wh['cold'], 'Onion', 'A', 11000, -128, 180, 8.4, 'LOT-ON-A01'],
];
$lotId = [];
foreach ($lots as [$w, $cn, $g, $kg, $off, $max, $val, $code]) {
    $lotId[$code] = ins('storage_lots', ['warehouse_id' => $w, 'crop_id' => $crop[$cn], 'lot_code' => $code, 'quantity_kg' => $kg, 'grade' => $g, 'stored_date' => d($off), 'max_storage_days' => $max, 'spoilage_kg' => round($kg * 0.004), 'unit_value' => $val, 'status' => 'stored']);
}

// ============================================================ crop activities, growth records
$activityCost = ['fertilizer' => [180, 360], 'manure' => [90, 180], 'spraying' => [60, 240], 'weeding' => [40, 90], 'irrigation' => [0, 0]];
$addAct = function (int $plId, string $type, int $off, string $product, string $itemName, float $qtyPerHa, float $ha, bool $scheduled = false) use ($inv, $f1, $fld) {
    $item = $itemName !== '' ? $inv[$itemName] : null;
    $qty = round($qtyPerHa * $ha, 2);
    $unitCost = $item ? (float) Db::val('SELECT unit_cost FROM inventory_items WHERE id = ?', [$item]) : 0;
    $cost = $scheduled ? 0 : round($qty * $unitCost + ($itemName === '' ? rnd(300, 1200, 0) : 0), 2);
    $aid = ins('crop_activities', ['planting_id' => $plId, 'activity_type' => $type, 'activity_date' => d($off), 'scheduled' => $scheduled ? 1 : 0, 'item_id' => $item, 'product_name' => $product,
        'quantity' => $qty, 'unit' => $item ? Db::val('SELECT unit FROM inventory_items WHERE id = ?', [$item]) : null, 'cost' => $cost, 'posted' => $scheduled ? 0 : 1]);
    if (!$scheduled && $cost > 0) {
        $p = Db::one('SELECT field_id, crop_id FROM plantings WHERE id = ?', [$plId]);
        Ledger::post($f1, 'expense', 'input', $cost, d($off), ucfirst($type) . ': ' . $product, 'crop_activity', $aid, ['field_id' => $p['field_id'], 'crop_id' => $p['crop_id'], 'planting_id' => $plId]);
    }
    return $aid;
};
foreach ($hist as [$key, $fn, $cn, $off, $days, $ha]) {
    $isVeg = in_array($cn, ['Tomato', 'Onion', 'Cabbage'], true);
    $addAct($pl[$key], 'fertilizer', $off + 2, 'Basal Compound D', 'Compound D (10:20:10)', $isVeg ? 12 : 6, (float) $ha);
    $addAct($pl[$key], 'fertilizer', $off + 40, 'Top dressing urea', 'Urea (46% N)', $isVeg ? 8 : 5, (float) $ha);
    $addAct($pl[$key], 'spraying', $off + 55, $isVeg ? 'Fungicide cover spray' : 'Insecticide', $isVeg ? 'Mancozeb 80WP' : 'Cypermethrin 200EC', $isVeg ? 3 : 0.6, (float) $ha);
    $addAct($pl[$key], 'weeding', $off + 30, 'Hand weeding labour contract', '', 0, (float) $ha);
}
$addAct($pl['tomato'], 'fertilizer', -60, 'Basal Compound D', 'Compound D (10:20:10)', 12, 3);
$addAct($pl['tomato'], 'fertilizer', -25, 'Top dressing urea', 'Urea (46% N)', 8, 3);
$addAct($pl['tomato'], 'spraying', -18, 'Mancozeb cover spray', 'Mancozeb 80WP', 3, 3);
$addAct($pl['tomato'], 'spraying', -6, 'Cypermethrin for leaf miner', 'Cypermethrin 200EC', 0.8, 3);
$addAct($pl['tomato'], 'fertilizer', 2, 'Top dress CAN', 'Urea (46% N)', 6, 3, true);
$addAct($pl['tomato'], 'spraying', 5, 'Spinosad leaf-miner round 2', 'Cypermethrin 200EC', 0.8, 3, true);
$addAct($pl['cabbage'], 'fertilizer', -38, 'Basal Compound D', 'Compound D (10:20:10)', 10, 2);
$addAct($pl['cabbage'], 'spraying', -5, 'Imidacloprid for aphids', 'Imidacloprid 350SC', 0.25, 2);
$addAct($pl['onion'], 'fertilizer', -103, 'Basal Compound D', 'Compound D (10:20:10)', 12, 5);
$addAct($pl['onion'], 'fertilizer', -60, 'Top dressing urea', 'Urea (46% N)', 8, 5);
$addAct($pl['onion'], 'weeding', -20, 'Hand weeding labour', '', 0, 5);
$addAct($pl['onion'], 'spraying', -12, 'Mancozeb for downy mildew', 'Mancozeb 80WP', 3, 5);
$addAct($pl['wheat'], 'fertilizer', -110, 'Basal Compound D', 'Compound D (10:20:10)', 8, 15);
$addAct($pl['wheat'], 'fertilizer', -65, 'Top dressing urea', 'Urea (46% N)', 6, 15);
$addAct($pl['wheat'], 'spraying', -30, 'Fungicide', 'Mancozeb 80WP', 2, 15);
$addAct($pl['soy'], 'fertilizer', -93, 'Basal Compound D', 'Compound D (10:20:10)', 4, 12);
$addAct($pl['soy'], 'spraying', -40, 'Rust prevention spray', 'Mancozeb 80WP', 2, 12);
$addAct($pl['soy'], 'spraying', -14, 'Red spider mite control', 'Cypermethrin 200EC', 0.6, 12);
$addAct($pl['maize_B'], 'fertilizer', 14, 'Basal Compound D at planting', 'Compound D (10:20:10)', 6, 18, true);
$addAct($pl['maize_B'], 'spraying', 16, 'Pre-emergence herbicide', 'Glyphosate 360SL', 2, 18, true);
$stages = [
    'tomato' => [[-62, 'planted', 15], [-48, 'vegetative', 40], [-35, 'vegetative', 75], [-21, 'flowering', 110], [-10, 'fruiting', 130], [-2, 'fruiting', 140]],
    'cabbage' => [[-40, 'planted', 8], [-30, 'germination', 15], [-18, 'vegetative', 30], [-6, 'vegetative', 44]],
    'onion' => [[-105, 'planted', 5], [-90, 'germination', 12], [-70, 'vegetative', 35], [-50, 'vegetative', 55], [-25, 'vegetative', 62], [-5, 'vegetative', 70]],
    'wheat' => [[-112, 'planted', 5], [-90, 'vegetative', 30], [-65, 'flowering', 70], [-40, 'flowering', 85], [-15, 'maturity', 90], [-3, 'maturity', 92]],
    'soy' => [[-95, 'planted', 4], [-70, 'vegetative', 35], [-45, 'flowering', 60], [-20, 'fruiting', 80], [-4, 'maturity', 85]],
];
foreach ($stages as $k => $rows) foreach ($rows as [$off, $st, $h]) {
    ins('growth_records', ['planting_id' => $pl[$k], 'record_date' => d($off), 'growth_stage' => $st, 'height_cm' => $h, 'health_score' => mt_rand($k === 'tomato' ? 62 : 74, 94), 'notes' => null]);
}

// ============================================================ pest scouting & treatments
$scout = [
    ['River Plot 1', 'tomato', 'Tuta absoluta (leaf miner)', -9, 'high', 22, 'treating', 'Mines visible on upper canopy; trap counts rising.'],
    ['River Plot 1', 'tomato', 'Early blight', -4, 'medium', 12, 'open', 'Lower-leaf lesions after humid spell.'],
    ['River Plot 1', 'cabbage', 'Aphids', -7, 'medium', 18, 'treating', 'Colonies on inner leaves.'],
    ['River Plot 2', 'onion', 'Downy mildew', -3, 'critical', 45, 'open', 'Spreading from the low corner after night irrigation.'],
    ['Wheat Flats', 'wheat', 'Aphids', -12, 'low', 6, 'resolved', 'Below economic threshold; natural enemies present.'],
    ['North Block A', 'soy', 'Red spider mite', -16, 'medium', 10, 'resolved', 'Hot dry spell; treated and irrigation increased.'],
    ['North Block A', 'soy', 'Soybean rust', -2, 'low', 4, 'open', 'First lesions on lower leaves.'],
    ['West Block', null, 'Black jack (Bidens pilosa)', -6, 'medium', 25, 'open', 'Heavy weed seedbank before planting.'],
    ['North Block B', null, 'Cutworms', -20, 'low', 3, 'resolved', 'Old stalk residue; plan pre-plant soil treatment.'],
];
$sr = [];
foreach ($scout as $i => [$fn, $pk, $pn, $off, $sev, $pct, $st, $note]) {
    $sr[$i] = ins('scouting_reports', ['field_id' => $fld[$fn], 'planting_id' => $pk ? $pl[$pk] : null, 'catalog_id' => $pest[$pn], 'report_date' => d($off), 'severity' => $sev, 'affected_area_pct' => $pct, 'status' => $st, 'reported_by' => $uid['agronomist'], 'notes' => $note]);
}
foreach ([[0, -7, 'Cypermethrin 200EC', 'Cypermethrin 200EC', 2.4, 6, 'Spray 800 ml/ha early morning'], [2, -5, 'Imidacloprid 350SC', 'Imidacloprid 350SC', 0.5, 8, 'Spot-spray colonies'], [4, -10, 'Neem extract', '', 3, 7, 'Organic option trial'], [5, -14, 'Cypermethrin 200EC', 'Cypermethrin 200EC', 7.2, 8, 'Whole block spray']] as [$si, $off, $chem, $itemName, $qty, $eff, $note]) {
    $unit = $itemName ? (float) Db::val('SELECT unit_cost FROM inventory_items WHERE id = ?', [$inv[$itemName]]) : 80;
    $cost = round($qty * $unit, 2);
    $tid = ins('treatments', ['scouting_id' => $sr[$si], 'treatment_date' => d($off), 'item_id' => $itemName ? $inv[$itemName] : null, 'chemical_name' => $chem, 'dosage' => $note, 'quantity' => $qty, 'cost' => $cost, 'effectiveness' => $eff, 'notes' => null, 'posted' => 1]);
    $p = Db::one('SELECT sr.field_id, sr.planting_id, pl.crop_id FROM scouting_reports sr LEFT JOIN plantings pl ON pl.id = sr.planting_id WHERE sr.id = ?', [$sr[$si]]);
    Ledger::post($f1, 'expense', 'input', $cost, d($off), 'Treatment: ' . $chem, 'treatment', $tid, ['field_id' => $p['field_id'], 'crop_id' => $p['crop_id'], 'planting_id' => $p['planting_id']]);
}

// ============================================================ irrigation
$ws = [
    'BH1' => ins('water_sources', ['farm_id' => $f1, 'name' => 'Borehole BH1', 'source_type' => 'borehole', 'capacity_litres' => 400000, 'status' => 'active', 'notes' => '45 m3/h yield, tested annually']),
    'River' => ins('water_sources', ['farm_id' => $f1, 'name' => 'Chongwe River Intake', 'source_type' => 'river', 'capacity_litres' => null, 'status' => 'active', 'notes' => 'Licensed abstraction 180 m3/day']),
    'Dam' => ins('water_sources', ['farm_id' => $f1, 'name' => 'Farm Dam', 'source_type' => 'dam', 'capacity_litres' => 12000000, 'status' => 'low', 'notes' => 'Level dropping at end of dry season']),
    'BH2' => ins('water_sources', ['farm_id' => $f1, 'name' => 'Borehole BH2', 'source_type' => 'borehole', 'capacity_litres' => 250000, 'status' => 'active', 'notes' => null]),
];
$sys = [
    ['Centre Pivot – North Block A', 'North Block A', 'BH1', 'Borehole Pump BH1', 'pivot', 180000, 'operational'],
    ['Drip – River Plot 1', 'River Plot 1', 'River', 'River Diesel Pump', 'drip', 60000, 'operational'],
    ['Drip – River Plot 2', 'River Plot 2', 'River', 'River Diesel Pump', 'drip', 50000, 'operational'],
    ['Sprinklers – Wheat Flats', 'Wheat Flats', 'BH2', 'Borehole Pump BH1', 'sprinkler', 100000, 'operational'],
    ['Sprinklers – West Block', 'West Block', 'Dam', 'River Diesel Pump', 'sprinkler', 80000, 'fault'],
    ['Hose lines – East Orchard', 'East Orchard', 'BH2', null, 'manual', 20000, 'maintenance'],
];
$isys = [];
foreach ($sys as [$n, $fn, $wsk, $pump, $type, $flow, $st]) {
    $isys[$n] = ins('irrigation_systems', ['farm_id' => $f1, 'field_id' => $fld[$fn], 'water_source_id' => $ws[$wsk], 'pump_equipment_id' => $pump ? $eq[$pump] : null, 'name' => $n, 'system_type' => $type, 'flow_rate_lph' => $flow, 'status' => $st]);
}
foreach ($isys as $n => $sid) {
    $info = Db::one('SELECT i.flow_rate_lph, i.status, f.size_ha FROM irrigation_systems i JOIN fields f ON f.id = i.field_id WHERE i.id = ?', [$sid]);
    if ($info['status'] !== 'operational') continue;
    $flow = (int) $info['flow_rate_lph'];
    $area = (float) $info['size_ha'];
    // Realistic applications: ~4.5-6.5 mm per event (1 mm over 1 ha = 10,000 L); drip daily, others every second day.
    for ($off = -120; $off <= -1; $off += (str_contains($n, 'Drip') ? 1 : 2)) {
        $litres = rnd(4.5, 6.5, 1) * $area * 10000;
        $dur = max(30, (int) round($litres / $flow * 60));
        $vol = (int) round($litres * rnd(0.96, 1.0, 3));
        $cost = round($dur / 60 * rnd(30, 45), 2);
        $rid = ins('irrigation_schedules', ['system_id' => $sid, 'scheduled_date' => d($off), 'start_time' => '05:30:00', 'duration_min' => $dur, 'planned_volume_litres' => (int) round($litres), 'actual_volume_litres' => $vol, 'energy_cost' => $cost, 'status' => 'done']);
        Ledger::post($f1, 'expense', 'utilities', $cost, d($off), 'Irrigation energy cost', 'irrigation', $rid);
    }
    foreach ([0, 1, 2] as $off) {
        $litres = rnd(4.5, 6.5, 1) * $area * 10000;
        $dur = max(30, (int) round($litres / $flow * 60));
        ins('irrigation_schedules', ['system_id' => $sid, 'scheduled_date' => d($off), 'start_time' => '05:30:00', 'duration_min' => $dur, 'planned_volume_litres' => (int) round($litres), 'energy_cost' => round($dur / 60 * 38, 2), 'status' => 'planned']);
    }
}
ins('irrigation_schedules', ['system_id' => $isys['Centre Pivot – North Block A'], 'scheduled_date' => d(-9), 'start_time' => '05:30:00', 'duration_min' => 180, 'planned_volume_litres' => 540000, 'status' => 'skipped', 'notes' => 'Skipped — 22 mm rain overnight']);

// ============================================================ livestock
$an = [];
$mk = function (string $tag, string $sp, string $breed, string $sex, int $birthOff, float $wt, string $note = '', string $status = 'active') use (&$an, $f1) {
    $an[$tag] = ins('animals', ['farm_id' => $f1, 'tag_no' => $tag, 'species' => $sp, 'breed' => $breed, 'sex' => $sex, 'birth_date' => d($birthOff), 'acquired_date' => d($birthOff), 'source' => 'Born on farm', 'current_weight_kg' => $wt, 'status' => $status, 'notes' => $note]);
};
for ($i = 1; $i <= 12; $i++) $mk(sprintf('C-%03d', $i), 'cattle', 'Friesian cross', 'F', -mt_rand(1100, 2600), rnd(430, 560, 1), 'Dairy cow');
$mk('C-013', 'cattle', 'Friesian', 'M', -1900, 720, 'Herd bull');
for ($i = 14; $i <= 21; $i++) $mk(sprintf('C-%03d', $i), 'cattle', 'Friesian cross', 'F', -mt_rand(260, 720), rnd(190, 330, 1), 'Heifer');
for ($i = 1; $i <= 12; $i++) $mk(sprintf('G-%03d', $i), 'goat', 'Boer', $i <= 10 ? 'F' : 'M', -mt_rand(300, 1400), rnd(38, 70, 1));
for ($i = 1; $i <= 4; $i++) $mk(sprintf('S-%03d', $i), 'sheep', 'Dorper', 'F', -mt_rand(400, 1200), rnd(40, 62, 1));
$mk('FLOCK-A', 'poultry', 'Hy-Line layers', 'F', -310, 1.9, 'Layer flock of 400 birds (batch record)');
$mk('FLOCK-B', 'poultry', 'Hy-Line layers', 'F', -120, 1.5, 'Layer flock of 300 birds (batch record)');
$mk('C-099', 'cattle', 'Friesian cross', 'F', -2400, 0, 'Died of bloat', 'dead');
Db::exec("UPDATE animals SET death_date = ?, death_cause = 'Acute bloat' WHERE tag_no = 'C-099'", [d(-40)]);
$dairy = array_filter(array_keys($an), fn($t) => preg_match('/^C-0(0\d|1[0-2])$/', $t));
$i = 0;
foreach ($dairy as $tag) {
    $i++;
    ins('animal_health', ['animal_id' => $an[$tag], 'record_type' => 'vaccination', 'record_date' => d($i <= 4 ? -350 + $i : -170 + $i), 'name' => 'FMD vaccine', 'item_id' => $inv['FMD vaccine'], 'next_due_date' => d($i <= 3 ? -5 + $i : ($i <= 6 ? 5 + $i : 150)), 'cost' => 38, 'vet_name' => 'Dr. Kaunda']);
    ins('animal_health', ['animal_id' => $an[$tag], 'record_type' => 'vaccination', 'record_date' => d(-100), 'name' => 'Brucellosis screening', 'next_due_date' => d(265), 'cost' => 55, 'vet_name' => 'Dr. Kaunda']);
}
foreach (['C-004' => 'Mastitis — left rear quarter', 'C-009' => 'Foot rot', 'G-003' => 'Worm burden — drenched'] as $tag => $what) {
    ins('animal_health', ['animal_id' => $an[$tag], 'record_type' => $tag === 'G-003' ? 'treatment' : 'disease', 'record_date' => d(-mt_rand(10, 30)), 'name' => $what, 'item_id' => $inv['Oxytetracycline LA'], 'cost' => 185, 'vet_name' => 'Dr. Kaunda', 'notes' => 'Responded well']);
}
foreach (['C-001', 'C-002', 'C-005', 'C-014', 'C-015', 'C-016'] as $tag) {
    $w = (float) Db::val('SELECT current_weight_kg FROM animals WHERE id = ?', [$an[$tag]]);
    for ($m = 11; $m >= 0; $m--) ins('animal_weights', ['animal_id' => $an[$tag], 'weigh_date' => d(-$m * 30), 'weight_kg' => round($w - $m * rnd(1.5, 4.5), 1)]);
}
for ($w = 8; $w >= 1; $w--) {
    foreach ([['Dairy herd', 'Dairy meal', 520], ['Dairy herd', 'Maize bran', 140], ['Layer flocks', 'Layer mash', 190]] as [$grp, $feed, $kg]) {
        $q = round($kg * rnd(0.9, 1.1));
        $cost = round($q * (float) Db::val('SELECT unit_cost FROM inventory_items WHERE id = ?', [$inv[$feed]]), 2);
        $fid = ins('feed_records', ['farm_id' => $f1, 'animal_id' => null, 'group_name' => $grp, 'item_id' => $inv[$feed], 'feed_date' => d(-$w * 7), 'quantity_kg' => $q, 'cost' => $cost, 'posted' => 1]);
        Ledger::post($f1, 'expense', 'livestock', $cost, d(-$w * 7), "Feed: $feed", 'feed', $fid);
    }
}
foreach ([['C-003', 'C-013', -250, -200, null, 'pregnant'], ['C-007', 'C-013', -200, -140, null, 'pregnant'], ['C-010', 'C-013', -170, -110, null, 'pregnant'], ['C-001', 'C-013', -520, -240, -240, 'born'], ['C-006', 'C-013', -610, -330, -330, 'born']] as [$dam, $sire, $mate, $exp, $birth, $st]) {
    ins('breeding_records', ['dam_id' => $an[$dam], 'sire_id' => $an[$sire], 'mating_date' => d($mate), 'expected_birth_date' => d($mate + 283), 'actual_birth_date' => $birth !== null ? d($mate + 283) : null, 'offspring_count' => $birth !== null ? 1 : 0, 'status' => $st]);
}
for ($off = -420; $off <= 0; $off += ($off > -60 ? 1 : 7)) {
    $lit = $off > -60 ? rnd(170, 215, 1) : rnd(170, 215, 1) * 7;
    ins('livestock_production', ['farm_id' => $f1, 'animal_id' => null, 'species' => 'cattle', 'product' => 'milk', 'production_date' => d($off), 'quantity' => $lit, 'unit' => 'litres']);
    ins('livestock_production', ['farm_id' => $f1, 'animal_id' => null, 'species' => 'poultry', 'product' => 'eggs', 'production_date' => d($off), 'quantity' => $off > -60 ? mt_rand(560, 640) : mt_rand(560, 640) * 7, 'unit' => 'eggs']);
}

// ============================================================ market, customers, sales
$cust = [];
foreach ([
    ['Shoprite Fresh Produce (Lusaka DC)', 'retail', 'Joyce Mutale', '+260211880100', 'Lusaka'], ['Soweto Market Traders Assoc.', 'market', 'Wilson Mwape', '+260977112233', 'Soweto Market'],
    ['Zambia National Milling Co.', 'buyer', 'Anthony Phiri', '+260211880300', 'Kafue'], ['Premier Foods Processing', 'contract', 'Caroline Sinkala', '+260211880400', 'Lusaka'],
    ['City Wholesalers Ltd', 'buyer', 'Raj Patel', '+260211880500', 'Lusaka'], ['Kabwe Fresh Veg Hub', 'market', 'Dennis Chimfwembe', '+260955660770', 'Kabwe'],
    ['Lusaka Hotels Group', 'retail', 'Chef Mubanga', '+260211880700', 'Lusaka'], ['Ndola Agri Traders', 'buyer', 'Felix Kasonde', '+260966990011', 'Ndola'],
] as [$n, $t, $cp, $ph, $dest]) $cust[$n] = ins('customers', ['farm_id' => $f1, 'name' => $n, 'customer_type' => $t, 'contact_person' => $cp, 'phone' => $ph, 'email' => strtolower(preg_replace('/[^a-z]+/i', '', explode(' ', $n)[0])) . '@buyer.example', 'address' => $dest, 'market_destination' => $dest]);
$custIds = array_values($cust);

$peak = ['Maize' => 1, 'Soybean' => 11, 'Wheat' => 12, 'Tomato' => 8, 'Onion' => 1, 'Cabbage' => 9, 'Groundnut' => 12, 'Sunflower' => 10];
$amp = ['Maize' => 0.18, 'Soybean' => 0.12, 'Wheat' => 0.10, 'Tomato' => 0.28, 'Onion' => 0.22, 'Cabbage' => 0.20, 'Groundnut' => 0.10, 'Sunflower' => 0.12];
$priceAt = function (string $cn, int $monthsAgo, int $month) use ($base, $peak, $amp) {
    $trend = 1 + 0.008 * (36 - $monthsAgo);
    $season = 1 + $amp[$cn] * cos(2 * M_PI * ($month - $peak[$cn]) / 12);
    return round($base[$cn] * $trend * $season * (1 + rnd(-0.04, 0.04, 3)), 2);
};
foreach ($crop as $cn => $cid) {
    for ($mAgo = 36; $mAgo >= 0; $mAgo--) {
        $date = gmdate('Y-m-15', strtotime("first day of -$mAgo months", strtotime($TODAY)));
        if ($date > $TODAY) $date = d(-3);
        $mon = (int) substr($date, 5, 2);
        $p = $priceAt($cn, $mAgo, $mon);
        $demand = $p > $base[$cn] * 1.12 ? 'high' : ($p < $base[$cn] * 0.95 ? 'low' : 'medium');
        ins('market_prices', ['farm_id' => $f1, 'crop_id' => $cid, 'market_name' => 'Soweto Market', 'price_date' => $date, 'price_per_kg' => $p, 'demand_level' => $demand]);
        ins('market_prices', ['farm_id' => $f1, 'crop_id' => $cid, 'market_name' => 'Lusaka Wholesale', 'price_date' => $date, 'price_per_kg' => round($p * rnd(0.97, 1.05, 3), 2), 'demand_level' => $demand]);
    }
    ins('market_prices', ['farm_id' => $f1, 'crop_id' => $cid, 'market_name' => 'Soweto Market', 'price_date' => d(-2), 'price_per_kg' => $priceAt($cn, 0, (int) gmdate('n')), 'demand_level' => 'medium']);
}

$qtyRange = ['Maize' => [8000, 30000], 'Soybean' => [2000, 9000], 'Wheat' => [5000, 20000], 'Tomato' => [1500, 7000], 'Onion' => [3000, 9000], 'Cabbage' => [2000, 6000]];
$cropsSold = array_keys($qtyRange);
$orderIds = [];
for ($n = 0; $n < 46; $n++) {
    $off = -mt_rand(3, 430);
    $date = d($off);
    $oid = ins('sales_orders', ['farm_id' => $f1, 'customer_id' => pick($custIds), 'order_no' => 'TMP-' . $n, 'order_date' => $date, 'delivery_date' => d($off + mt_rand(1, 5)), 'status' => 'delivered']);
    $total = 0;
    foreach ((array) array_rand(array_flip($cropsSold), mt_rand(1, 2)) as $k) {
        $cn = $cropsSold[$k] ?? $k;
        [$lo, $hi] = $qtyRange[$cn];
        $qty = round(mt_rand($lo, $hi), -2);
        $price = $priceAt($cn, (int) floor(-$off / 30), (int) substr($date, 5, 2));
        ins('sales_order_items', ['order_id' => $oid, 'crop_id' => $crop[$cn], 'quantity_kg' => $qty, 'unit_price' => $price, 'grade' => 'A']);
        $total += $qty * $price;
    }
    $status = $off > -8 ? pick(['pending', 'confirmed', 'confirmed']) : ($off > -20 ? 'delivered' : 'delivered');
    Db::update('sales_orders', $oid, ['order_no' => sprintf('SO-%s-%04d', substr($date, 0, 4), $oid), 'total_amount' => round($total, 2), 'status' => $status]);
    $orderIds[] = [$oid, $off, round($total, 2), $status, $date];
}
$invN = 0;
foreach ($orderIds as [$oid, $off, $total, $status, $date]) {
    if ($status === 'pending') continue;
    $due = gmdate('Y-m-d', strtotime("$date +14 days"));
    $iid = ins('invoices', ['farm_id' => $f1, 'order_id' => $oid, 'invoice_no' => 'TMP-' . $oid, 'issue_date' => $date, 'due_date' => $due, 'amount' => $total, 'amount_paid' => 0, 'status' => 'unpaid']);
    Db::update('invoices', $iid, ['invoice_no' => sprintf('INV-%s-%04d', substr($date, 0, 4), $iid)]);
    $r = mt_rand(1, 100);
    $mode = $off < -50 ? ($r <= 93 ? 'paid' : ($r <= 98 ? 'partial' : 'unpaid')) : ($off < -20 ? ($r <= 55 ? 'paid' : ($r <= 75 ? 'partial' : 'unpaid')) : ($r <= 25 ? 'paid' : 'unpaid'));
    $paid = 0;
    if ($mode === 'paid' || $mode === 'partial') {
        $chunks = $mode === 'paid' && mt_rand(0, 1) ? [0.6, 0.4] : ($mode === 'partial' ? [0.5] : [1.0]);
        foreach ($chunks as $k => $share) {
            $pdate = min($TODAY, gmdate('Y-m-d', strtotime("$date +" . (3 + $k * 9) . ' days')));
            $amt = round($total * $share, 2);
            $payId = ins('payments', ['invoice_id' => $iid, 'payment_date' => $pdate, 'amount' => $amt, 'method' => pick(['bank', 'bank', 'mobile_money', 'cash']), 'reference' => 'PAY' . mt_rand(10000, 99999)]);
            Ledger::post($f1, 'income', 'sales', $amt, $pdate, 'Payment for invoice', 'payment', $payId);
            $paid += $amt;
        }
    }
    Db::update('invoices', $iid, ['amount_paid' => $paid, 'status' => $paid <= 0 ? 'unpaid' : ($paid + 0.01 >= $total ? 'paid' : 'partial')]);
}
foreach ([[$lotId['LOT-MZ-A01'], -40, 8000, 'Zambia National Milling Co.'], [$lotId['LOT-SB-A01'], -22, 4500, 'Premier Foods Processing'], [$lotId['LOT-ON-A01'], -15, 3000, 'Shoprite Fresh Produce (Lusaka DC)']] as [$lot, $off, $qty, $dest]) {
    ins('dispatches', ['lot_id' => $lot, 'dispatch_date' => d($off), 'quantity_kg' => $qty, 'vehicle' => 'ZM-HLX-4417', 'destination' => $dest, 'status' => 'delivered']);
}
// contracts
ins('contracts', ['farm_id' => $f1, 'customer_id' => $cust['Premier Foods Processing'], 'contract_type' => 'offtake', 'crop_id' => $crop['Maize'], 'quantity_kg' => 120000, 'price_per_kg' => 6.4, 'start_date' => d(-60), 'end_date' => d(180), 'terms' => 'Monthly deliveries of 20 t, grade A, payment within 21 days.', 'status' => 'active']);
ins('contracts', ['farm_id' => $f1, 'customer_id' => $cust['Shoprite Fresh Produce (Lusaka DC)'], 'contract_type' => 'contract_farming', 'crop_id' => $crop['Tomato'], 'quantity_kg' => 60000, 'price_per_kg' => 9.2, 'start_date' => d(-90), 'end_date' => d(90), 'terms' => 'Weekly supply; crates provided by buyer; quality audit monthly.', 'status' => 'active']);
ins('contracts', ['farm_id' => $f1, 'supplier_id' => $sup['seed'], 'contract_type' => 'supply', 'start_date' => d(-200), 'end_date' => d(165), 'terms' => 'Preferred pricing on certified seed; 30-day payment terms.', 'status' => 'active']);

// ============================================================ procurement
$po = [
    // supplier cat, offsetDays, status, [[item, qty, price]], paid share
    ['seed', -85, 'received', [['Maize seed SC 513', 1200, 45], ['Soybean seed Lukanga', 500, 38]], 1.0],
    ['fertilizer', -80, 'received', [['Compound D (10:20:10)', 150, 780], ['Urea (46% N)', 100, 640]], 1.0],
    ['chemical', -70, 'received', [['Mancozeb 80WP', 40, 120], ['Cypermethrin 200EC', 30, 210]], 1.0],
    ['fuel', -50, 'received', [['Diesel', 5000, 28.5]], 1.0],
    ['feed', -35, 'received', [['Dairy meal', 4000, 8.6], ['Layer mash', 1500, 9.4]], 0.5],
    ['packaging', -20, 'received', [['Tomato crates', 200, 38]], 1.0],
    ['fertilizer', -6, 'ordered', [['Urea (46% N)', 120, 660]], 0],
    ['chemical', -3, 'pending_approval', [['Imidacloprid 350SC', 12, 345], ['Chlorpyrifos 480EC', 6, 190]], 0],
    ['seed', -1, 'draft', [['Cabbage seedlings', 60, 98]], 0],
];
foreach ($po as [$sc, $off, $st, $lines, $paidShare]) {
    $odate = d($off);
    $poId = ins('purchase_orders', ['farm_id' => $f1, 'supplier_id' => $sup[$sc], 'po_no' => 'TMP-PO' . $off, 'order_date' => $odate, 'expected_date' => d($off + 7), 'status' => $st,
        'approved_by' => in_array($st, ['approved', 'ordered', 'received'], true) ? $uid['manager'] : null, 'approved_at' => in_array($st, ['approved', 'ordered', 'received'], true) ? $odate . ' 10:00:00' : null,
        'received_at' => $st === 'received' ? d($off + 5) . ' 09:00:00' : null]);
    $total = 0;
    foreach ($lines as [$item, $q, $pr]) {
        ins('purchase_order_items', ['po_id' => $poId, 'item_id' => $inv[$item], 'description' => $item, 'quantity' => $q, 'unit_price' => $pr]);
        $total += $q * $pr;
    }
    $paid = round($total * $paidShare, 2);
    Db::update('purchase_orders', $poId, ['po_no' => sprintf('PO-%s-%04d', substr($odate, 0, 4), $poId), 'total_amount' => round($total, 2), 'amount_paid' => $paid]);
    if ($paid > 0) Ledger::post($f1, 'expense', 'procurement', $paid, d($off + 8), 'Payments for PO ' . $poId, 'po_paid', $poId);
}
foreach ([['seed', 'Maize seed SC 513 (certified), 1,500 kg', 1500, 47, 14], ['fertilizer', 'Urea 46% N, 200 bags', 200, 655, 10], ['fuel', 'Diesel bulk delivery, 6,000 L', 6000, 28.1, 5], ['chemical', 'Herbicide pre-emergence, 80 L', 80, 98, 20]] as [$sc, $d, $q, $pr, $valid]) {
    ins('quotations', ['farm_id' => $f1, 'supplier_id' => $sup[$sc], 'description' => $d, 'quantity' => $q, 'unit_price' => $pr, 'valid_until' => d($valid), 'status' => 'open']);
}

// ============================================================ finance: manual entries, budgets, loans
for ($mAgo = 13; $mAgo >= 0; $mAgo--) {
    $md = gmdate('Y-m-05', strtotime("first day of -$mAgo months", strtotime($TODAY)));
    if ($md > $TODAY) $md = $TODAY;
    ins('transactions', ['farm_id' => $f1, 'txn_type' => 'expense', 'category' => 'utilities', 'amount' => rnd(18000, 26000, 0), 'txn_date' => $md, 'description' => 'Electricity (ZESCO) — packhouse, cold room, borehole', 'created_by' => $uid['accountant']]);
    ins('transactions', ['farm_id' => $f1, 'txn_type' => 'income', 'category' => 'sales', 'amount' => rnd(58000, 71000, 0), 'txn_date' => gmdate('Y-m-28', strtotime($md)) > $TODAY ? $TODAY : gmdate('Y-m-28', strtotime($md)), 'description' => 'Milk sales — Parmalat collection', 'created_by' => $uid['accountant']]);
    ins('transactions', ['farm_id' => $f1, 'txn_type' => 'income', 'category' => 'sales', 'amount' => rnd(21000, 29000, 0), 'txn_date' => gmdate('Y-m-26', strtotime($md)) > $TODAY ? $TODAY : gmdate('Y-m-26', strtotime($md)), 'description' => 'Egg sales — local retailers', 'created_by' => $uid['accountant']]);
    if ($mAgo % 3 === 0) ins('transactions', ['farm_id' => $f1, 'txn_type' => 'expense', 'category' => 'other', 'amount' => rnd(9000, 14000, 0), 'txn_date' => $md, 'description' => 'Insurance premium — crop & equipment', 'created_by' => $uid['accountant']]);
}
$year = (int) gmdate('Y');
foreach (['input' => 1250000, 'labour' => 900000, 'fuel' => 380000, 'equipment' => 160000, 'utilities' => 320000, 'livestock' => 220000, 'procurement' => 650000, 'loan' => 300000, 'other' => 80000] as $cat => $amt) {
    ins('budgets', ['farm_id' => $f1, 'budget_year' => $year, 'category' => $cat, 'amount' => $amt]);
}
$loan1 = ins('loans', ['farm_id' => $f1, 'lender' => 'Agricultural Development Bank', 'purpose' => 'Combine harvester and pivot expansion', 'principal' => 1200000, 'interest_rate_pct' => 14, 'start_date' => d(-540), 'end_date' => d(320), 'balance' => 1200000, 'status' => 'active']);
$loan2 = ins('loans', ['farm_id' => $f1, 'lender' => 'Zanaco Agri Finance', 'purpose' => 'Seasonal input finance', 'principal' => 400000, 'interest_rate_pct' => 17, 'start_date' => d(-300), 'end_date' => d(25), 'balance' => 400000, 'status' => 'active']);
foreach ([[$loan1, 18, 38000], [$loan2, 9, 32000]] as [$lid, $n, $amt]) {
    $paid = 0;
    for ($k = $n; $k >= 1; $k--) {
        $pid = ins('loan_payments', ['loan_id' => $lid, 'payment_date' => d(-$k * 30), 'amount' => $amt, 'notes' => 'Monthly instalment']);
        Ledger::post($f1, 'expense', 'loan', (float) $amt, d(-$k * 30), 'Loan repayment', 'loan_payment', $pid);
        $paid += $amt;
    }
    $principal = (float) Db::val('SELECT principal FROM loans WHERE id = ?', [$lid]);
    Db::update('loans', $lid, ['balance' => max(0, $principal - $paid)]);
}

// ============================================================ weather
$tmax = [1 => 27.5, 2 => 27.8, 3 => 28.0, 4 => 27.5, 5 => 25.8, 6 => 23.5, 7 => 23.5, 8 => 26.0, 9 => 30.0, 10 => 32.5, 11 => 31.0, 12 => 28.5];
$tmin = [1 => 17.5, 2 => 17.4, 3 => 16.8, 4 => 14.8, 5 => 11.5, 6 => 8.5, 7 => 8.2, 8 => 10.5, 9 => 14.5, 10 => 17.5, 11 => 18.0, 12 => 17.8];
$rainP = [1 => .58, 2 => .52, 3 => .40, 4 => .12, 5 => .03, 6 => 0, 7 => 0, 8 => 0, 9 => .02, 10 => .08, 11 => .30, 12 => .52];
$hum = [1 => 78, 2 => 77, 3 => 74, 4 => 62, 5 => 50, 6 => 46, 7 => 42, 8 => 38, 9 => 34, 10 => 36, 11 => 52, 12 => 72];
$wx = function (string $date, float $latAdj = 0) use ($tmax, $tmin, $rainP, $hum) {
    $m = (int) substr($date, 5, 2);
    $rain = mt_rand(1, 1000) / 1000 < $rainP[$m] ? rnd(1, 38, 1) : 0.0;
    if ($rain > 25 && mt_rand(0, 1)) $rain = rnd(25, 60, 1);
    $cloud = $rain > 0 ? -2.5 : 0;
    $mx = round($tmax[$m] + rnd(-2.5, 2.5, 1) + $cloud - $latAdj, 1);
    $mn = round($tmin[$m] + rnd(-2, 2, 1) - $latAdj, 1);
    return ['temp_min_c' => $mn, 'temp_max_c' => max($mx, $mn + 4), 'humidity_pct' => min(98, max(15, $hum[$m] + ($rain > 0 ? 12 : 0) + rnd(-8, 8, 0))), 'rainfall_mm' => $rain, 'wind_kph' => rnd(6, 24, 1),
        'conditions' => $rain > 15 ? 'Heavy rain' : ($rain > 0 ? 'Showers' : (mt_rand(0, 3) ? 'Mostly sunny' : 'Partly cloudy'))];
};
for ($off = -420; $off <= 0; $off++) ins('weather_records', ['farm_id' => $f1, 'record_date' => d($off), 'source' => 'station'] + $wx(d($off)));
for ($off = -120; $off <= 0; $off++) ins('weather_records', ['farm_id' => $f2, 'record_date' => d($off), 'source' => 'station'] + $wx(d($off), 1.2));
Db::exec("UPDATE weather_records SET rainfall_mm = 0, conditions = 'Mostly sunny' WHERE record_date = ? AND farm_id IN (?, ?)", [d(0), $f1, $f2]);
for ($off = 0; $off <= 6; $off++) {
    $w = $wx(d($off));
    ins('weather_forecasts', ['farm_id' => $f1, 'forecast_date' => d($off), 'temp_min_c' => $w['temp_min_c'], 'temp_max_c' => $w['temp_max_c'], 'humidity_pct' => $w['humidity_pct'], 'rain_probability_pct' => $w['rainfall_mm'] > 0 ? mt_rand(45, 85) : mt_rand(0, 20),
        'rainfall_mm' => $w['rainfall_mm'], 'wind_kph' => $w['wind_kph'], 'conditions' => $w['conditions'], 'source' => 'seed', 'fetched_at' => gmdate('Y-m-d H:i:s', strtotime('-1 day'))]);
}

// ============================================================ farm 2 (small second farm)
$u2 = ins('users', ['name' => 'Kondwani Mumba', 'email' => 'manager2@ffms.test', 'password_hash' => $hash, 'role' => 'manager', 'farm_id' => $f2, 'phone' => '+260972100001', 'active' => 1]);
foreach ([['Kondwani Mumba', 'Farm manager', 'monthly', 6200], ['Lucy Bwalya', 'Field worker', 'daily', 110], ['Alick Zimba', 'Field worker', 'daily', 110]] as [$n, $t, $wt, $r]) {
    ins('workers', ['farm_id' => $f2, 'name' => $n, 'job_title' => $t, 'hire_date' => d(-800), 'wage_type' => $wt, 'wage_rate' => $r, 'status' => 'active']);
}
planting($f2, $m2['Valley Strip'], $c2m, 'Maize', 15, 130, 12, 'planned', 'planned');
foreach ([['Maize seed', 'seed', 'kg', 600, 200, 44], ['Compound D', 'fertilizer', 'bag', 80, 30, 780], ['Diesel', 'fuel', 'litre', 900, 300, 28.5]] as [$n, $c, $u, $q, $re, $co]) {
    ins('inventory_items', ['farm_id' => $f2, 'name' => $n, 'category' => $c, 'unit' => $u, 'quantity' => $q, 'reorder_level' => $re, 'unit_cost' => $co]);
}
ins('customers', ['farm_id' => $f2, 'name' => 'Mkushi Grain Traders', 'customer_type' => 'buyer', 'phone' => '+260977300400', 'address' => 'Mkushi']);
for ($k = 1; $k <= 8; $k++) ins('animals', ['farm_id' => $f2, 'tag_no' => sprintf('M-%03d', $k), 'species' => 'cattle', 'breed' => 'Boran', 'sex' => $k === 1 ? 'M' : 'F', 'birth_date' => d(-mt_rand(500, 2000)), 'current_weight_kg' => rnd(300, 480, 1)]);

Db::pdo()->commit();

// ============================================================ derive alerts for both farms
require_once __DIR__ . '/../api/src/Registry.php';
require_once __DIR__ . '/../api/src/Weather.php';
require_once __DIR__ . '/../api/src/Predict.php';
require_once __DIR__ . '/../api/src/Alerts.php';
putenv('WEATHER_LIVE=0');
foreach ([$f1, $f2] as $f) AlertEngine::refresh($f);

echo "Seed complete.\n\nDemo accounts (password: $demoPw)\n";
foreach ($users as [$n, $e, $r]) echo sprintf("  %-12s %s\n", $r, $e);
echo "  manager      manager2@ffms.test (second farm)\n";
