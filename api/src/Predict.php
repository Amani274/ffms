<?php

declare(strict_types=1);

/**
 * Transparent, rule-based and statistical decision support. Every prediction returns the
 * factors behind it so users can see why. No external AI service is required.
 */
final class Predict
{
    private const SEVERITY_WEIGHT = ['low' => 0.01, 'medium' => 0.04, 'high' => 0.10, 'critical' => 0.20];

    // ---------------------------------------------------------------- yield

    public static function yield(int $plantingId): ?array
    {
        $p = Db::one(
            'SELECT t.*, cr.name AS crop_name, cr.expected_yield_kg_ha, cr.days_to_maturity, cr.water_need_mm, cr.optimal_ph_min, cr.optimal_ph_max,
                    cr.optimal_temp_max, fl.name AS field_name, fl.soil_ph
             FROM plantings t JOIN crops cr ON cr.id = t.crop_id JOIN fields fl ON fl.id = t.field_id WHERE t.id = ?',
            [$plantingId]
        );
        if (!$p) return null;

        $today = gmdate('Y-m-d');
        $area = (float) $p['area_ha'];
        $baseline = (float) $p['expected_yield_kg_ha'] * $area;
        $daysSince = max(0, (int) ((strtotime($today) - strtotime($p['planting_date'])) / 86400));
        $progress = $p['status'] === 'harvested' ? 1.0 : min(1.0, $daysSince / max(1, (int) $p['days_to_maturity']));
        $factors = [];

        // 1. Farm history for this crop
        $h = Db::one(
            "SELECT COUNT(*) AS n, IFNULL(SUM(x.expected),0) AS expected, IFNULL(SUM(x.actual),0) AS actual, IFNULL(SUM(x.loss),0) AS loss, IFNULL(SUM(x.gross),0) AS gross
             FROM (SELECT pl.id, pl.expected_yield_kg AS expected, IFNULL(SUM(hv.quantity_kg - hv.loss_kg),0) AS actual,
                          IFNULL(SUM(hv.loss_kg),0) AS loss, IFNULL(SUM(hv.quantity_kg),0) AS gross
                   FROM plantings pl LEFT JOIN harvests hv ON hv.planting_id = pl.id
                   WHERE pl.crop_id = ? AND pl.farm_id = ? AND pl.status = 'harvested' AND pl.id <> ?
                   GROUP BY pl.id) x",
            [$p['crop_id'], $p['farm_id'], $plantingId]
        );
        $n = (int) $h['n'];
        $histMult = 1.0;
        if ($n > 0 && (float) $h['expected'] > 0) {
            $ratio = (float) $h['actual'] / (float) $h['expected'];
            $weight = min($n, 3) / 3;
            $histMult = 1 + ($ratio - 1) * $weight;
            $factors[] = self::factor('history', 'Farm history', $histMult, sprintf('%d past %s season(s) delivered %.0f%% of expected yield.', $n, $p['crop_name'], $ratio * 100));
        } else {
            $factors[] = self::factor('history', 'Farm history', 1.0, 'No completed seasons for this crop yet; using the variety baseline.');
        }
        $lossRate = (float) $h['gross'] > 0 ? (float) $h['loss'] / (float) $h['gross'] : 0.06;

        // 2. Soil pH suitability
        $ph = $p['soil_ph'] !== null ? (float) $p['soil_ph'] : null;
        if ($ph !== null) {
            $min = (float) $p['optimal_ph_min'];
            $max = (float) $p['optimal_ph_max'];
            $dist = $ph < $min ? $min - $ph : ($ph > $max ? $ph - $max : 0);
            $m = 1 - min(0.25, $dist * 0.12);
            $factors[] = self::factor('soil', 'Soil pH', $m, $dist > 0
                ? sprintf('Field pH %.1f is outside the optimal %.1f–%.1f range for %s.', $ph, $min, $max, $p['crop_name'])
                : sprintf('Field pH %.1f is within the optimal range.', $ph));
        }

        // 3. Water supply (rain + irrigation) against requirement so far
        $rain = (float) Db::val('SELECT IFNULL(SUM(rainfall_mm),0) FROM weather_records WHERE farm_id = ? AND record_date >= ?', [$p['farm_id'], $p['planting_date']]);
        $litres = (float) Db::val(
            "SELECT IFNULL(SUM(s.actual_volume_litres),0) FROM irrigation_schedules s JOIN irrigation_systems i ON i.id = s.system_id
             WHERE i.field_id = ? AND s.status = 'done' AND s.scheduled_date >= ?",
            [$p['field_id'], $p['planting_date']]
        );
        $irrMm = $area > 0 ? $litres / ($area * 10000) : 0;
        $supplied = $rain + $irrMm;
        $need = max(1.0, (float) $p['water_need_mm'] * $progress);
        if ($daysSince < 7 || $p['planting_date'] > $today) {
            $factors[] = self::factor('water', 'Water supply', 1.0, 'Too early in the season to assess water supply.');
        } else {
            $wr = $supplied / $need;
            $wm = $wr < 1 ? max(0.6, 0.5 + 0.5 * $wr) : ($wr > 1.5 ? 0.97 : 1.0);
            $factors[] = self::factor('water', 'Water supply', $wm, sprintf('%.0f mm supplied (%.0f rain + %.0f irrigation) vs ~%.0f mm needed so far.', $supplied, $rain, $irrMm, $need));
        }

        // 4. Open pest & disease pressure
        $pest = Db::all("SELECT severity, affected_area_pct FROM scouting_reports WHERE planting_id = ? AND status <> 'resolved'", [$plantingId]);
        $penalty = 0.0;
        foreach ($pest as $r) $penalty += (self::SEVERITY_WEIGHT[$r['severity']] ?? 0.02) * (0.5 + (float) $r['affected_area_pct'] / 200);
        $penalty = min(0.35, $penalty);
        $factors[] = self::factor('pests', 'Pest & disease pressure', 1 - $penalty, $pest ? count($pest) . ' unresolved scouting report(s) on this crop.' : 'No unresolved pest or disease reports.');

        // 5. Heat stress days since planting
        $hot = (int) Db::val('SELECT COUNT(*) FROM weather_records WHERE farm_id = ? AND record_date >= ? AND temp_max_c > ?', [$p['farm_id'], $p['planting_date'], (float) $p['optimal_temp_max'] + 5]);
        $hm = 1 - min(0.08, $hot * 0.006);
        $factors[] = self::factor('heat', 'Heat stress', $hm, $hot ? "$hot day(s) well above the crop's optimal maximum." : 'No significant heat-stress days recorded.');

        // 6. Fertiliser application
        $fert = (int) Db::val("SELECT COUNT(*) FROM crop_activities WHERE planting_id = ? AND activity_type IN ('fertilizer','manure') AND scheduled = 0", [$plantingId]);
        $fm = ($fert === 0 && $progress > 0.3) ? 0.92 : 1.0;
        $factors[] = self::factor('fertiliser', 'Fertiliser programme', $fm, $fert ? "$fert fertiliser/manure application(s) recorded." : ($progress > 0.3 ? 'No fertiliser applied yet — yield potential reduced.' : 'Too early to assess.'));

        $mult = 1.0;
        foreach ($factors as $f) $mult *= $f['multiplier'];
        $predicted = $baseline * $mult;
        $band = max(0.07, 0.18 - 0.03 * min($n, 4)) * (1 - 0.5 * $progress);
        $conf = (int) max(40, min(92, round(55 + 8 * min($n, 4) + $progress * 15)));
        $harvest = (string) $p['expected_harvest_date'];

        $price = null;
        $priceInfo = self::priceForecast((int) $p['crop_id']);
        if ($priceInfo['available']) {
            $month = substr($harvest, 0, 7);
            foreach ($priceInfo['forecast'] as $f) if ($f['month'] === $month) $price = $f['price'];
            $price ??= $priceInfo['current_price'];
        }
        $net = $predicted * (1 - $lossRate);

        return [
            'planting_id' => $plantingId, 'crop' => $p['crop_name'], 'field' => $p['field_name'], 'area_ha' => $area, 'status' => $p['status'],
            'stage' => $p['growth_stage'], 'progress_pct' => round($progress * 100), 'expected_harvest_date' => $harvest,
            'baseline_kg' => round($baseline), 'predicted_kg' => round($predicted), 'low_kg' => round($predicted * (1 - $band)), 'high_kg' => round($predicted * (1 + $band)),
            'predicted_kg_ha' => $area > 0 ? round($predicted / $area) : 0, 'vs_baseline_pct' => round(($mult - 1) * 100, 1), 'confidence_pct' => $conf,
            'expected_loss_pct' => round($lossRate * 100, 1), 'expected_price_per_kg' => $price !== null ? round($price, 2) : null,
            'expected_revenue' => $price !== null ? round($net * $price) : null, 'factors' => $factors,
            'method' => 'Baseline variety yield × agronomic factors (history, soil, water, pests, heat, fertiliser)',
        ];
    }

    public static function yieldAll(?int $farmId): array
    {
        $sql = "SELECT id FROM plantings WHERE status IN ('growing','planned')" . ($farmId ? ' AND farm_id = ?' : '') . ' ORDER BY expected_harvest_date';
        $out = [];
        foreach (Db::all($sql, $farmId ? [$farmId] : []) as $r) {
            if ($y = self::yield((int) $r['id'])) $out[] = $y;
        }
        return $out;
    }

    private static function factor(string $key, string $label, float $mult, string $detail): array
    {
        return ['key' => $key, 'label' => $label, 'multiplier' => round($mult, 3), 'impact_pct' => round(($mult - 1) * 100, 1), 'detail' => $detail];
    }

    // ---------------------------------------------------------------- price

    public static function priceForecast(int $cropId, int $horizon = 6): array
    {
        $rows = Db::all(
            "SELECT DATE_FORMAT(price_date,'%Y-%m') AS ym, AVG(price_per_kg) AS p,
                    AVG(CASE demand_level WHEN 'high' THEN 3 WHEN 'medium' THEN 2 ELSE 1 END) AS d
             FROM market_prices WHERE crop_id = ? GROUP BY ym ORDER BY ym",
            [$cropId]
        );
        $crop = Db::one('SELECT name FROM crops WHERE id = ?', [$cropId]);
        if (count($rows) < 4) return ['available' => false, 'crop' => $crop['name'] ?? '', 'message' => 'At least 4 months of recorded market prices are needed to forecast.'];

        $series = array_slice($rows, -36);
        $n = count($series);
        $mean = array_sum(array_map(fn($r) => (float) $r['p'], $series)) / $n;

        // seasonal index per calendar month (needs >= 18 months)
        $idx = array_fill(1, 12, 1.0);
        if ($n >= 18) {
            $by = [];
            foreach ($series as $r) $by[(int) substr($r['ym'], 5, 2)][] = (float) $r['p'];
            foreach ($by as $m => $vals) $idx[$m] = max(0.5, min(1.5, (array_sum($vals) / count($vals)) / $mean));
        }
        $xs = [];
        $ys = [];
        foreach ($series as $i => $r) {
            $xs[] = $i;
            $ys[] = (float) $r['p'] / $idx[(int) substr($r['ym'], 5, 2)];
        }
        $mx = array_sum($xs) / $n;
        $my = array_sum($ys) / $n;
        $num = $den = 0.0;
        foreach ($xs as $k => $x) {
            $num += ($x - $mx) * ($ys[$k] - $my);
            $den += ($x - $mx) ** 2;
        }
        $slope = $den > 0 ? $num / $den : 0.0;
        $icpt = $my - $slope * $mx;
        $res = [];
        foreach ($xs as $k => $x) $res[] = $ys[$k] - ($icpt + $slope * $x);
        $sd = sqrt(array_sum(array_map(fn($e) => $e * $e, $res)) / max(1, $n - 2));

        $last = end($series);
        $forecast = [];
        $lastTs = strtotime($last['ym'] . '-01');
        for ($h = 1; $h <= $horizon; $h++) {
            $ts = strtotime("+$h months", $lastTs);
            $m = (int) date('n', $ts);
            $p = max(0.01, ($icpt + $slope * ($n - 1 + $h)) * $idx[$m]);
            $forecast[] = ['month' => date('Y-m', $ts), 'price' => round($p, 2), 'low' => round(max(0, $p - 1.28 * $sd * $idx[$m]), 2), 'high' => round($p + 1.28 * $sd * $idx[$m], 2)];
        }
        $best = $forecast[0];
        foreach ($forecast as $f) if ($f['price'] > $best['price']) $best = $f;

        $changes = [];
        for ($i = 1; $i < $n; $i++) {
            $prev = (float) $series[$i - 1]['p'];
            if ($prev > 0) $changes[] = ((float) $series[$i]['p'] - $prev) / $prev;
        }
        $cm = $changes ? array_sum($changes) / count($changes) : 0;
        $vol = $changes ? sqrt(array_sum(array_map(fn($c) => ($c - $cm) ** 2, $changes)) / max(1, count($changes) - 1)) : 0;
        $trendPct = $mean > 0 ? $slope / $mean * 100 : 0;

        $dRecent = array_sum(array_map(fn($r) => (float) $r['d'], array_slice($series, -3))) / min(3, $n);
        $dPrev = $n >= 6 ? array_sum(array_map(fn($r) => (float) $r['d'], array_slice($series, -6, 3))) / 3 : $dRecent;
        $current = (float) $last['p'];

        return [
            'available' => true, 'crop' => $crop['name'] ?? '', 'crop_id' => $cropId,
            'history' => array_map(fn($r) => ['month' => $r['ym'], 'price' => round((float) $r['p'], 2)], $series),
            'forecast' => $forecast, 'current_price' => round($current, 2),
            'trend_pct_per_month' => round($trendPct, 2), 'direction' => $trendPct > 0.4 ? 'rising' : ($trendPct < -0.4 ? 'falling' : 'stable'),
            'best_month' => $best['month'], 'best_price' => $best['price'], 'upside_pct' => $current > 0 ? round(($best['price'] - $current) / $current * 100, 1) : 0,
            'volatility_pct' => round($vol * 100, 1), 'risk' => $vol < 0.08 ? 'low' : ($vol < 0.16 ? 'medium' : 'high'),
            'demand_trend' => $dRecent > $dPrev + 0.2 ? 'strengthening' : ($dRecent < $dPrev - 0.2 ? 'weakening' : 'steady'),
            'method' => 'Seasonally adjusted linear trend on monthly average market prices (80% band)',
        ];
    }

    public static function priceOverview(?int $farmId): array
    {
        $out = [];
        $crops = Db::all('SELECT DISTINCT c.id FROM crops c JOIN market_prices m ON m.crop_id = c.id' . ($farmId ? ' WHERE c.farm_id = ?' : ''), $farmId ? [$farmId] : []);
        foreach ($crops as $c) {
            $f = self::priceForecast((int) $c['id']);
            if ($f['available']) unset($f['history']);
            $out[] = $f;
        }
        return $out;
    }

    // ------------------------------------------------------ recommendations

    public static function recommendations(int $farmId): array
    {
        $forecast = Weather::forecast($farmId);
        $rain48 = 0.0;
        foreach (array_slice($forecast, 0, 2) as $f) $rain48 += (float) $f['rainfall_mm'];
        $stats = Weather::stats($farmId, 5);
        $recs = [];

        // Irrigation
        $systems = Db::all(
            "SELECT i.id, i.name, i.field_id, fl.name AS field_name FROM irrigation_systems i JOIN fields fl ON fl.id = i.field_id
             WHERE i.farm_id = ? AND i.status = 'operational'",
            [$farmId]
        );
        foreach ($systems as $s) {
            $pl = Db::one("SELECT p.id, p.planting_date, p.area_ha, cr.name, cr.water_need_mm, cr.days_to_maturity, cr.optimal_temp_max FROM plantings p JOIN crops cr ON cr.id = p.crop_id WHERE p.field_id = ? AND p.status = 'growing' ORDER BY p.planting_date DESC LIMIT 1", [$s['field_id']]);
            if (!$pl) continue;
            $last = Db::val("SELECT MAX(scheduled_date) FROM irrigation_schedules WHERE system_id = ? AND status = 'done'", [$s['id']]);
            $daysSince = $last ? (int) ((time() - strtotime((string) $last)) / 86400) : 99;
            $maxT = (float) ($forecast[0]['temp_max_c'] ?? 28);
            if ($rain48 >= 8) {
                $recs[] = ['type' => 'irrigation', 'severity' => 'info', 'title' => "Postpone irrigation — {$s['field_name']}", 'message' => sprintf('%.0f mm of rain is forecast in the next 48 hours. Skip %s for %s.', $rain48, $s['name'], $pl['name']), 'field' => $s['field_name']];
            } elseif ($daysSince >= 3 && ($maxT >= 30 || $stats['rain_mm'] < 5)) {
                $recs[] = ['type' => 'irrigation', 'severity' => $daysSince >= 5 ? 'warning' : 'info', 'title' => "Irrigate {$s['field_name']} within 24 hours", 'message' => sprintf('%s last irrigated %s; only %.0f mm rain in 5 days and %.0f°C forecast. %s is in active growth.', $s['name'], $last ? "$daysSince day(s) ago" : 'never recorded', $stats['rain_mm'], $maxT, $pl['name']), 'field' => $s['field_name']];
            } else {
                $recs[] = ['type' => 'irrigation', 'severity' => 'ok', 'title' => "{$s['field_name']} irrigation on track", 'message' => "Recent irrigation and rainfall are adequate for {$pl['name']}.", 'field' => $s['field_name']];
            }
        }

        // Spray window
        $open = (int) Db::val("SELECT COUNT(*) FROM scouting_reports sr JOIN fields fl ON fl.id = sr.field_id WHERE fl.farm_id = ? AND sr.status <> 'resolved' AND sr.severity IN ('medium','high','critical')", [$farmId]);
        if ($open > 0) {
            $win = null;
            foreach ($forecast as $f) if ((float) $f['wind_kph'] < 15 && (float) $f['rain_probability_pct'] < 30) {
                $win = $f;
                break;
            }
            $recs[] = $win
                ? ['type' => 'spraying', 'severity' => 'info', 'title' => 'Best spraying window: ' . date('D j M', strtotime($win['forecast_date'])), 'message' => sprintf('%d open pest/disease report(s) need treatment. Low wind (%.0f km/h) and %.0f%% rain chance on that day.', $open, $win['wind_kph'], $win['rain_probability_pct'])]
                : ['type' => 'spraying', 'severity' => 'warning', 'title' => 'No ideal spraying window this week', 'message' => "$open open pest/disease report(s) need treatment, but wind or rain is unfavourable for the next 7 days."];
        }

        // Crop suitability by field
        $mean = $stats['mean_temp'] ?? ($forecast ? ((float) $forecast[0]['temp_min_c'] + (float) $forecast[0]['temp_max_c']) / 2 : 22.0);
        $rain30 = Weather::stats($farmId, 30)['rain_mm'];
        $crops = Db::all('SELECT * FROM crops WHERE farm_id = ?', [$farmId]);
        $suit = [];
        foreach (Db::all("SELECT f.*, EXISTS(SELECT 1 FROM irrigation_systems i WHERE i.field_id = f.id AND i.status IN ('operational','maintenance')) AS irrigated FROM fields f WHERE f.farm_id = ? AND f.land_use = 'cropping' AND f.status <> 'retired'", [$farmId]) as $fl) {
            $scored = [];
            $prev = Db::one(
                "SELECT p.crop_id, cr.category, cr.name FROM plantings p JOIN crops cr ON cr.id = p.crop_id
                 WHERE p.field_id = ? AND p.status IN ('growing','harvested') ORDER BY p.planting_date DESC, p.id DESC LIMIT 1",
                [$fl['id']]
            );
            $nitrogen = $fl['nitrogen_ppm'] !== null ? (float) $fl['nitrogen_ppm'] : null;
            foreach ($crops as $c) {
                $phScore = 0.7;
                $why = [];
                if ($fl['soil_ph'] !== null) {
                    $ph = (float) $fl['soil_ph'];
                    $d = $ph < (float) $c['optimal_ph_min'] ? (float) $c['optimal_ph_min'] - $ph : ($ph > (float) $c['optimal_ph_max'] ? $ph - (float) $c['optimal_ph_max'] : 0);
                    $phScore = max(0, 1 - $d / 1.5);
                    $why[] = $d == 0 ? "pH {$ph} suits it" : "pH {$ph} is " . round($d, 1) . ' off optimal';
                }
                $tmin = (float) $c['optimal_temp_min'];
                $tmax = (float) $c['optimal_temp_max'];
                $td = $mean < $tmin ? $tmin - $mean : ($mean > $tmax ? $mean - $tmax : 0);
                $tScore = max(0, 1 - $td / 8);
                $why[] = $td == 0 ? sprintf('%.0f°C mean temp is in range', $mean) : sprintf('%.0f°C mean temp is %.0f° outside range', $mean, $td);
                $needMonth = (float) $c['water_need_mm'] / max(1, (int) $c['days_to_maturity'] / 30);
                $wScore = min(1, $rain30 / max(1, $needMonth));
                if ((int) $fl['irrigated']) {
                    $wScore = max($wScore, 0.9);
                    $why[] = 'irrigation available';
                } else {
                    $why[] = sprintf('%.0f mm rain in 30 days', $rain30);
                }
                // soil fertility: nitrogen matters for non-legumes; legumes fix their own
                $fScore = 0.8;
                if ($c['category'] === 'legume') {
                    $fScore = 1.0;
                    $why[] = 'legume restores nitrogen';
                } elseif ($nitrogen !== null) {
                    $fScore = min(1.0, $nitrogen / 38);
                    if ($fScore < 0.8) $why[] = sprintf('low soil N (%.0f ppm)', $nitrogen);
                }
                // rotation: avoid repeating the last crop or its family
                $rScore = 1.0;
                if ($prev) {
                    if ((int) $prev['crop_id'] === (int) $c['id']) {
                        $rScore = 0.3;
                        $why[] = 'same crop as the last planting';
                    } elseif ($prev['category'] === $c['category']) {
                        $rScore = 0.7;
                        $why[] = 'same family as ' . $prev['name'];
                    } else {
                        $why[] = 'good rotation after ' . $prev['name'];
                    }
                }
                $score = 0.40 * $phScore + 0.25 * $tScore + 0.15 * $wScore + 0.10 * $fScore + 0.10 * $rScore;
                $scored[] = ['crop_id' => (int) $c['id'], 'crop' => $c['name'] . ($c['variety'] ? ' – ' . $c['variety'] : ''), 'score' => (int) round($score * 100), 'reasons' => $why];
            }
            usort($scored, fn($a, $b) => [$b['score'], $a['crop']] <=> [$a['score'], $b['crop']]);
            $suit[] = ['field_id' => (int) $fl['id'], 'field' => $fl['name'], 'soil_ph' => $fl['soil_ph'], 'top' => array_slice($scored, 0, 3)];
        }
        return ['weather_basis' => ['rain_48h_mm' => round($rain48, 1), 'rain_30d_mm' => round($rain30, 1), 'mean_temp_c' => round($mean, 1)], 'actions' => $recs, 'crop_suitability' => $suit];
    }
}
