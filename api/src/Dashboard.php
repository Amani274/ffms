<?php

declare(strict_types=1);

final class Dashboard
{
    private static function months(int $n): array
    {
        $out = [];
        for ($i = $n - 1; $i >= 0; $i--) $out[] = gmdate('Y-m', strtotime(gmdate('Y-m-01') . " -$i months"));
        return $out;
    }

    private static function series(array $months, array $rows, string $key, string $val): array
    {
        $map = [];
        foreach ($rows as $r) $map[$r[$key]] = (float) $r[$val];
        return array_map(fn($m) => ['month' => $m, 'value' => round($map[$m] ?? 0, 2)], $months);
    }

    public static function summary(array $ctx): array
    {
        $farm = $ctx['farm'];
        $role = $ctx['user']['role'];
        $fin = in_array($role, ['admin', 'owner', 'accountant', 'manager'], true);
        $wf = fn(string $c) => $farm !== null ? " AND $c = " . (int) $farm : '';
        $farmIds = $farm !== null ? [(int) $farm] : array_map('intval', array_column(Db::all('SELECT id FROM farms'), 'id'));
        foreach ($farmIds as $fid) AlertEngine::refreshIfStale($fid);
        $months = self::months(12);
        $from12 = $months[0] . '-01';
        $year = gmdate('Y');
        $out = ['farm_id' => $farm, 'role' => $role, 'currency' => Env::get('CURRENCY', 'ZMW')];

        $land = Db::one('SELECT COUNT(*) AS farms, IFNULL(SUM(size_ha),0) AS total_ha FROM farms WHERE 1=1' . $wf('id'));
        $fields = Db::one('SELECT COUNT(*) AS fields, IFNULL(SUM(size_ha),0) AS field_ha FROM fields WHERE status <> \'retired\'' . $wf('farm_id'));
        $growing = Db::one("SELECT COUNT(*) AS plantings, COUNT(DISTINCT crop_id) AS crops, IFNULL(SUM(area_ha),0) AS area_ha, IFNULL(SUM(expected_yield_kg),0) AS expected_kg FROM plantings WHERE status = 'growing'" . $wf('farm_id'));
        $out['land'] = [
            'farms' => (int) $land['farms'], 'total_ha' => (float) $land['total_ha'], 'fields' => (int) $fields['fields'], 'field_ha' => (float) $fields['field_ha'],
            'planted_ha' => (float) $growing['area_ha'], 'active_plantings' => (int) $growing['plantings'], 'active_crops' => (int) $growing['crops'], 'expected_kg' => (float) $growing['expected_kg'],
        ];
        $out['crop_stages'] = Db::all("SELECT growth_stage AS stage, COUNT(*) AS plantings, ROUND(SUM(area_ha),1) AS area_ha FROM plantings WHERE status = 'growing'" . $wf('farm_id') . ' GROUP BY growth_stage ORDER BY FIELD(growth_stage,\'planted\',\'germination\',\'vegetative\',\'flowering\',\'fruiting\',\'maturity\')');
        $out['upcoming_harvests'] = Db::all(
            "SELECT p.id, cr.name AS crop, fl.name AS field, p.expected_harvest_date, DATEDIFF(p.expected_harvest_date, CURDATE()) AS days, p.expected_yield_kg, p.growth_stage
             FROM plantings p JOIN crops cr ON cr.id = p.crop_id JOIN fields fl ON fl.id = p.field_id WHERE p.status = 'growing'" . $wf('p.farm_id') . ' ORDER BY p.expected_harvest_date LIMIT 6'
        );

        $prod = Db::all("SELECT DATE_FORMAT(h.harvest_date,'%Y-%m') AS m, SUM(h.quantity_kg - h.loss_kg) AS kg FROM harvests h JOIN plantings p ON p.id = h.planting_id WHERE h.harvest_date >= ?" . $wf('p.farm_id') . ' GROUP BY m', [$from12]);
        $out['production_monthly'] = self::series($months, $prod, 'm', 'kg');
        $out['actual_production_kg_ytd'] = (float) Db::val("SELECT IFNULL(SUM(h.quantity_kg - h.loss_kg),0) FROM harvests h JOIN plantings p ON p.id = h.planting_id WHERE YEAR(h.harvest_date) = ?" . $wf('p.farm_id'), [$year]);

        $out['livestock'] = Db::all("SELECT species, COUNT(*) AS count FROM animals WHERE status = 'active'" . $wf('farm_id') . ' GROUP BY species ORDER BY count DESC');
        $out['livestock_total'] = array_sum(array_map(fn($r) => (int) $r['count'], $out['livestock']));

        if ($fin) {
            $inc = Db::all("SELECT DATE_FORMAT(txn_date,'%Y-%m') AS m, SUM(amount) AS v FROM transactions WHERE txn_type = 'income' AND txn_date >= ?" . $wf('farm_id') . ' GROUP BY m', [$from12]);
            $exp = Db::all("SELECT DATE_FORMAT(txn_date,'%Y-%m') AS m, SUM(amount) AS v FROM transactions WHERE txn_type = 'expense' AND txn_date >= ?" . $wf('farm_id') . ' GROUP BY m', [$from12]);
            $out['finance_monthly'] = ['income' => self::series($months, $inc, 'm', 'v'), 'expense' => self::series($months, $exp, 'm', 'v')];
            $ytd = Db::one("SELECT IFNULL(SUM(CASE WHEN txn_type='income' THEN amount END),0) AS income, IFNULL(SUM(CASE WHEN txn_type='expense' THEN amount END),0) AS expense FROM transactions WHERE YEAR(txn_date) = ?" . $wf('farm_id'), [$year]);
            $out['finance'] = [
                'income_ytd' => (float) $ytd['income'], 'expense_ytd' => (float) $ytd['expense'], 'profit_ytd' => (float) $ytd['income'] - (float) $ytd['expense'],
                'receivables' => (float) Db::val("SELECT IFNULL(SUM(amount - amount_paid),0) FROM invoices WHERE status IN ('unpaid','partial')" . $wf('farm_id')),
                'overdue_invoices' => (int) Db::val("SELECT COUNT(*) FROM invoices WHERE status IN ('unpaid','partial') AND due_date < CURDATE()" . $wf('farm_id')),
                'loan_balance' => (float) Db::val("SELECT IFNULL(SUM(balance),0) FROM loans WHERE status = 'active'" . $wf('farm_id')),
            ];
        }

        $inv = Db::one('SELECT SUM(quantity <= reorder_level AND reorder_level > 0) AS low, SUM(expiry_date IS NOT NULL AND DATEDIFF(expiry_date, CURDATE()) <= 30 AND quantity > 0) AS expiring, IFNULL(SUM(quantity * unit_cost),0) AS value, COUNT(*) AS items FROM inventory_items WHERE 1=1' . $wf('farm_id'));
        $out['inventory'] = ['items' => (int) $inv['items'], 'low_stock' => (int) $inv['low'], 'expiring' => (int) $inv['expiring'], 'value' => $fin ? (float) $inv['value'] : null];
        $out['low_stock_items'] = Db::all('SELECT id, name, quantity, unit, reorder_level FROM inventory_items WHERE quantity <= reorder_level AND reorder_level > 0' . $wf('farm_id') . ' ORDER BY quantity / reorder_level LIMIT 5');

        $wxFarm = $farm ?? (int) Db::val('SELECT id FROM farms ORDER BY id LIMIT 1');
        $out['weather'] = [
            'current' => Db::one('SELECT * FROM weather_records WHERE farm_id = ? ORDER BY record_date DESC LIMIT 1', [$wxFarm]),
            'forecast' => array_slice(Weather::forecast($wxFarm), 0, 5),
            'rain_30d_mm' => Weather::stats($wxFarm, 30)['rain_mm'],
        ];

        $out['pests'] = [
            'by_severity' => Db::all("SELECT sr.severity, COUNT(*) AS count FROM scouting_reports sr JOIN fields fl ON fl.id = sr.field_id WHERE sr.status <> 'resolved'" . $wf('fl.farm_id') . ' GROUP BY sr.severity'),
            'latest' => Db::all("SELECT sr.id, sr.severity, sr.report_date, fl.name AS field, IFNULL(pc.name,'Observation') AS pest FROM scouting_reports sr JOIN fields fl ON fl.id = sr.field_id LEFT JOIN pest_catalog pc ON pc.id = sr.catalog_id WHERE sr.status <> 'resolved'" . $wf('fl.farm_id') . " ORDER BY FIELD(sr.severity,'critical','high','medium','low'), sr.report_date DESC LIMIT 5"),
        ];
        $out['irrigation'] = [
            'systems' => Db::all('SELECT status, COUNT(*) AS count FROM irrigation_systems WHERE 1=1' . $wf('farm_id') . ' GROUP BY status'),
            'planned_today' => (int) Db::val("SELECT COUNT(*) FROM irrigation_schedules s JOIN irrigation_systems i ON i.id = s.system_id WHERE s.scheduled_date = CURDATE() AND s.status = 'planned'" . $wf('i.farm_id')),
            'litres_30d' => (float) Db::val("SELECT IFNULL(SUM(s.actual_volume_litres),0) FROM irrigation_schedules s JOIN irrigation_systems i ON i.id = s.system_id WHERE s.status = 'done' AND s.scheduled_date >= (CURDATE() - INTERVAL 30 DAY)" . $wf('i.farm_id')),
        ];

        $alertHide = $role === 'worker' ? " AND alert_type <> 'market_opportunity'" : '';
        $out['market'] = $role === 'worker' ? null : Db::all(
            "SELECT cr.name AS crop, cr.id AS crop_id,
               (SELECT m.price_per_kg FROM market_prices m WHERE m.crop_id = cr.id ORDER BY m.price_date DESC, m.id DESC LIMIT 1) AS price,
               (SELECT m.price_per_kg FROM market_prices m WHERE m.crop_id = cr.id AND m.price_date <= (CURDATE() - INTERVAL 30 DAY) ORDER BY m.price_date DESC LIMIT 1) AS prev_price
             FROM crops cr WHERE EXISTS (SELECT 1 FROM market_prices m WHERE m.crop_id = cr.id)" . $wf('cr.farm_id') . ' ORDER BY cr.name LIMIT 8'
        );

        $out['alerts'] = [
            'counts' => Db::all("SELECT severity, COUNT(*) AS count FROM alerts WHERE status <> 'resolved'" . $alertHide . $wf('farm_id') . ' GROUP BY severity'),
            'latest' => Db::all("SELECT id, alert_type, severity, title, message, due_date, status FROM alerts WHERE status <> 'resolved'" . $alertHide . $wf('farm_id') . " ORDER BY FIELD(severity,'critical','warning','info'), created_at DESC LIMIT 8"),
        ];
        $tasks = Db::one("SELECT SUM(status <> 'done') AS open, SUM(status <> 'done' AND due_date < CURDATE()) AS overdue, SUM(status = 'done' AND completed_at >= (UTC_TIMESTAMP() - INTERVAL 7 DAY)) AS done_week FROM tasks WHERE 1=1" . $wf('farm_id'));
        $out['tasks'] = ['open' => (int) $tasks['open'], 'overdue' => (int) $tasks['overdue'], 'done_week' => (int) $tasks['done_week']];
        return $out;
    }
}
