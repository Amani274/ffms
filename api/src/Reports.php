<?php

declare(strict_types=1);

final class Reports
{
    private const FIN = ['admin', 'owner', 'accountant', 'manager'];
    private const OPS = ['admin', 'owner', 'manager', 'agronomist', 'accountant'];

    public static function catalog(): array
    {
        return [
            'crop_production' => ['Crop production', self::OPS, 'Every planting with expected vs. harvested output.'],
            'yield' => ['Yield', self::OPS, 'Yield per hectare, losses and variance by crop.'],
            'livestock' => ['Livestock', self::OPS, 'Herd status, mortality, production, feed and health costs by species.'],
            'financial' => ['Financial (P&L)', self::FIN, 'Monthly income, expenses and profit.'],
            'expenses' => ['Expenses', self::FIN, 'Spending by category with share of total.'],
            'sales' => ['Sales', self::FIN, 'Orders with invoice and payment status.'],
            'inventory' => ['Inventory', self::OPS, 'Stock levels, valuation, expiry and reorder status.'],
            'labour' => ['Labour', self::FIN, 'Attendance, wages, task output and ratings per worker.'],
            'irrigation' => ['Irrigation', self::OPS, 'Water use, run-time and energy cost per system.'],
            'pest_disease' => ['Pest & disease', self::OPS, 'Incidence, severity and treatment effectiveness.'],
            'equipment' => ['Equipment', self::OPS, 'Usage, fuel, maintenance and book value.'],
            'profitability' => ['Profitability by crop', self::FIN, 'Revenue, direct cost, margin, cost/ha and cost/kg per crop.'],
            'farm_performance' => ['Farm performance', self::FIN, 'Headline KPIs for the period.'],
        ];
    }

    public static function run(string $type, array $ctx, string $from, string $to): array
    {
        $cat = self::catalog();
        if (!isset($cat[$type])) fail(404, 'Unknown report.');
        if (!in_array($ctx['user']['role'], $cat[$type][1], true)) fail(403, 'Your role cannot view this report.');
        $farm = $ctx['farm'];
        $p = ['from' => $from, 'to' => $to];
        $wf = fn(string $c) => $farm !== null ? " AND $c = " . (int) $farm : '';
        [$columns, $rows, $summary] = self::{'r_' . $type}($p, $wf);
        return ['type' => $type, 'title' => $cat[$type][0], 'from' => $from, 'to' => $to, 'columns' => $columns, 'rows' => $rows, 'summary' => $summary];
    }

    private static function col(string $key, string $label, string $fmt = 'text'): array
    {
        return ['key' => $key, 'label' => $label, 'format' => $fmt];
    }

    private static function r_crop_production(array $p, callable $wf): array
    {
        $rows = Db::all(
            "SELECT cr.name AS crop, fl.name AS field, t.season, t.planting_date, t.area_ha, t.status, t.growth_stage,
                    ROUND(t.expected_yield_kg) AS expected_kg, ROUND(IFNULL(SUM(h.quantity_kg - h.loss_kg),0)) AS harvested_kg,
                    ROUND(IFNULL(SUM(h.quantity_kg - h.loss_kg),0) / NULLIF(t.area_ha,0)) AS yield_kg_ha
             FROM plantings t JOIN crops cr ON cr.id = t.crop_id JOIN fields fl ON fl.id = t.field_id LEFT JOIN harvests h ON h.planting_id = t.id
             WHERE t.planting_date BETWEEN :from AND :to" . $wf('t.farm_id') . ' GROUP BY t.id ORDER BY t.planting_date DESC',
            $p
        );
        $cols = [self::col('crop', 'Crop'), self::col('field', 'Field'), self::col('season', 'Season'), self::col('planting_date', 'Planted', 'date'), self::col('area_ha', 'Area (ha)', 'number'),
            self::col('status', 'Status'), self::col('growth_stage', 'Stage'), self::col('expected_kg', 'Expected (kg)', 'number'), self::col('harvested_kg', 'Harvested (kg)', 'number'), self::col('yield_kg_ha', 'Yield (kg/ha)', 'number')];
        return [$cols, $rows, ['Plantings' => count($rows), 'Total area (ha)' => round(array_sum(array_column($rows, 'area_ha')), 1), 'Harvested (kg)' => array_sum(array_column($rows, 'harvested_kg'))]];
    }

    private static function r_yield(array $p, callable $wf): array
    {
        $rows = Db::all(
            "SELECT cr.name AS crop, COUNT(*) AS plantings, ROUND(SUM(x.area_ha),1) AS area_ha,
                    ROUND(SUM(x.gross)) AS gross_kg, ROUND(SUM(x.loss)) AS loss_kg,
                    ROUND(SUM(x.loss) / NULLIF(SUM(x.gross),0) * 100, 1) AS loss_pct,
                    ROUND((SUM(x.gross) - SUM(x.loss)) / NULLIF(SUM(x.area_ha),0)) AS avg_yield_kg_ha,
                    cr.expected_yield_kg_ha AS target_kg_ha
             FROM crops cr JOIN (
                 SELECT t.id, t.crop_id, t.area_ha, SUM(h.quantity_kg) AS gross, SUM(h.loss_kg) AS loss
                 FROM plantings t JOIN harvests h ON h.planting_id = t.id WHERE h.harvest_date BETWEEN :from AND :to GROUP BY t.id
             ) x ON x.crop_id = cr.id WHERE 1=1" . $wf('cr.farm_id') . ' GROUP BY cr.id ORDER BY gross_kg DESC',
            $p
        );
        foreach ($rows as &$r) $r['variance_pct'] = $r['target_kg_ha'] > 0 ? round(((float) $r['avg_yield_kg_ha'] - (float) $r['target_kg_ha']) / (float) $r['target_kg_ha'] * 100, 1) : null;
        $cols = [self::col('crop', 'Crop'), self::col('plantings', 'Plantings', 'number'), self::col('area_ha', 'Area (ha)', 'number'), self::col('gross_kg', 'Gross (kg)', 'number'), self::col('loss_kg', 'Losses (kg)', 'number'),
            self::col('loss_pct', 'Loss %', 'number'), self::col('avg_yield_kg_ha', 'Yield (kg/ha)', 'number'), self::col('target_kg_ha', 'Target (kg/ha)', 'number'), self::col('variance_pct', 'Vs target %', 'number')];
        return [$cols, $rows, ['Gross harvested (kg)' => array_sum(array_column($rows, 'gross_kg')), 'Losses (kg)' => array_sum(array_column($rows, 'loss_kg'))]];
    }

    private static function r_livestock(array $p, callable $wf): array
    {
        $rows = Db::all(
            "SELECT a.species, SUM(a.status = 'active') AS active, SUM(a.status IN ('dead')) AS dead, SUM(a.status IN ('sold','culled')) AS sold,
                    ROUND(AVG(CASE WHEN a.status = 'active' THEN a.current_weight_kg END),1) AS avg_weight_kg,
                    ROUND(SUM(a.status = 'dead') / COUNT(*) * 100, 1) AS mortality_pct
             FROM animals a WHERE 1=1" . $wf('a.farm_id') . ' GROUP BY a.species ORDER BY active DESC'
        );
        foreach ($rows as &$r) {
            $q = Db::one("SELECT IFNULL(SUM(quantity),0) AS prod FROM livestock_production WHERE species = :s AND production_date BETWEEN :from AND :to" . $wf('farm_id'), ['s' => $r['species']] + $p);
            $h = Db::one("SELECT COUNT(*) AS events, IFNULL(SUM(h.cost),0) AS cost FROM animal_health h JOIN animals a ON a.id = h.animal_id WHERE a.species = :s AND h.record_date BETWEEN :from AND :to" . $wf('a.farm_id'), ['s' => $r['species']] + $p);
            $f = Db::one("SELECT IFNULL(SUM(f.cost),0) AS cost FROM feed_records f LEFT JOIN animals a ON a.id = f.animal_id WHERE a.species = :s AND f.feed_date BETWEEN :from AND :to" . $wf('f.farm_id'), ['s' => $r['species']] + $p);
            $r['production'] = round((float) $q['prod'], 1);
            $r['health_events'] = (int) $h['events'];
            $r['health_cost'] = round((float) $h['cost'], 2);
            $r['feed_cost'] = round((float) $f['cost'], 2);
        }
        $cols = [self::col('species', 'Species'), self::col('active', 'Active', 'number'), self::col('dead', 'Dead', 'number'), self::col('sold', 'Sold / culled', 'number'), self::col('mortality_pct', 'Mortality %', 'number'),
            self::col('avg_weight_kg', 'Avg weight (kg)', 'number'), self::col('production', 'Production (units)', 'number'), self::col('health_events', 'Health events', 'number'), self::col('health_cost', 'Health cost', 'money'), self::col('feed_cost', 'Feed cost', 'money')];
        return [$cols, $rows, ['Active animals' => array_sum(array_column($rows, 'active'))]];
    }

    private static function r_financial(array $p, callable $wf): array
    {
        $rows = Db::all(
            "SELECT DATE_FORMAT(txn_date,'%Y-%m') AS month, ROUND(SUM(CASE WHEN txn_type='income' THEN amount ELSE 0 END),2) AS income,
                    ROUND(SUM(CASE WHEN txn_type='expense' THEN amount ELSE 0 END),2) AS expense
             FROM transactions WHERE txn_date BETWEEN :from AND :to" . $wf('farm_id') . ' GROUP BY month ORDER BY month',
            $p
        );
        foreach ($rows as &$r) $r['profit'] = round((float) $r['income'] - (float) $r['expense'], 2);
        $inc = array_sum(array_column($rows, 'income'));
        $exp = array_sum(array_column($rows, 'expense'));
        $cols = [self::col('month', 'Month'), self::col('income', 'Income', 'money'), self::col('expense', 'Expenses', 'money'), self::col('profit', 'Profit / loss', 'money')];
        return [$cols, $rows, ['Total income' => round($inc, 2), 'Total expenses' => round($exp, 2), 'Net profit' => round($inc - $exp, 2), 'Margin %' => $inc > 0 ? round(($inc - $exp) / $inc * 100, 1) : 0]];
    }

    private static function r_expenses(array $p, callable $wf): array
    {
        $rows = Db::all("SELECT category, COUNT(*) AS entries, ROUND(SUM(amount),2) AS amount FROM transactions WHERE txn_type = 'expense' AND txn_date BETWEEN :from AND :to" . $wf('farm_id') . ' GROUP BY category ORDER BY amount DESC', $p);
        $total = array_sum(array_column($rows, 'amount'));
        foreach ($rows as &$r) $r['share_pct'] = $total > 0 ? round((float) $r['amount'] / $total * 100, 1) : 0;
        $cols = [self::col('category', 'Category'), self::col('entries', 'Entries', 'number'), self::col('amount', 'Amount', 'money'), self::col('share_pct', 'Share %', 'number')];
        return [$cols, $rows, ['Total expenses' => round($total, 2)]];
    }

    private static function r_sales(array $p, callable $wf): array
    {
        $rows = Db::all(
            "SELECT o.order_date, o.order_no, cu.name AS customer, o.status, o.total_amount, i.invoice_no, IFNULL(i.amount_paid,0) AS paid, i.status AS invoice_status
             FROM sales_orders o JOIN customers cu ON cu.id = o.customer_id LEFT JOIN invoices i ON i.order_id = o.id
             WHERE o.order_date BETWEEN :from AND :to" . $wf('o.farm_id') . ' ORDER BY o.order_date DESC',
            $p
        );
        foreach ($rows as &$r) $r['balance'] = round((float) $r['total_amount'] - (float) $r['paid'], 2);
        $valid = array_filter($rows, fn($r) => $r['status'] !== 'cancelled');
        $cols = [self::col('order_date', 'Date', 'date'), self::col('order_no', 'Order'), self::col('customer', 'Customer'), self::col('status', 'Status'), self::col('total_amount', 'Total', 'money'),
            self::col('invoice_no', 'Invoice'), self::col('paid', 'Paid', 'money'), self::col('balance', 'Balance', 'money'), self::col('invoice_status', 'Payment')];
        return [$cols, $rows, ['Orders' => count($valid), 'Sales value' => round(array_sum(array_column($valid, 'total_amount')), 2), 'Outstanding' => round(array_sum(array_column($valid, 'balance')), 2)]];
    }

    private static function r_inventory(array $p, callable $wf): array
    {
        $rows = Db::all(
            "SELECT name, category, quantity, unit, reorder_level, unit_cost, ROUND(quantity * unit_cost, 2) AS value, expiry_date,
                    CASE WHEN expiry_date IS NOT NULL AND expiry_date < CURDATE() THEN 'Expired'
                         WHEN quantity <= 0 THEN 'Out of stock'
                         WHEN quantity <= reorder_level AND reorder_level > 0 THEN 'Low stock'
                         WHEN expiry_date IS NOT NULL AND DATEDIFF(expiry_date, CURDATE()) <= 30 THEN 'Expiring soon' ELSE 'OK' END AS state
             FROM inventory_items WHERE 1=1" . $wf('farm_id') . ' ORDER BY category, name'
        );
        $cols = [self::col('name', 'Item'), self::col('category', 'Category'), self::col('quantity', 'Quantity', 'number'), self::col('unit', 'Unit'), self::col('reorder_level', 'Reorder at', 'number'),
            self::col('unit_cost', 'Unit cost', 'money'), self::col('value', 'Value', 'money'), self::col('expiry_date', 'Expiry', 'date'), self::col('state', 'Status')];
        return [$cols, $rows, ['Items' => count($rows), 'Stock value' => round(array_sum(array_column($rows, 'value')), 2), 'Needs attention' => count(array_filter($rows, fn($r) => $r['state'] !== 'OK'))]];
    }

    private static function r_labour(array $p, callable $wf): array
    {
        $rows = Db::all(
            "SELECT w.name, w.job_title,
               (SELECT IFNULL(SUM(CASE a.status WHEN 'present' THEN 1 WHEN 'half' THEN 0.5 ELSE 0 END),0) FROM worker_attendance a WHERE a.worker_id = w.id AND a.work_date BETWEEN :from AND :to) AS days_present,
               (SELECT COUNT(*) FROM worker_attendance a WHERE a.worker_id = w.id AND a.status = 'absent' AND a.work_date BETWEEN :from2 AND :to2) AS days_absent,
               (SELECT IFNULL(SUM(pr.net_pay),0) FROM payroll_runs pr WHERE pr.worker_id = w.id AND pr.period_end BETWEEN :from3 AND :to3) AS wages,
               (SELECT COUNT(*) FROM tasks t WHERE t.worker_id = w.id AND t.status = 'done' AND DATE(t.completed_at) BETWEEN :from4 AND :to4) AS tasks_done,
               (SELECT IFNULL(SUM(t.hours_spent),0) FROM tasks t WHERE t.worker_id = w.id AND t.status = 'done' AND DATE(t.completed_at) BETWEEN :from5 AND :to5) AS task_hours,
               (SELECT ROUND(AVG(r.rating),1) FROM worker_reviews r WHERE r.worker_id = w.id) AS avg_rating
             FROM workers w WHERE w.status = 'active'" . $wf('w.farm_id') . ' ORDER BY w.name',
            ['from' => $p['from'], 'to' => $p['to'], 'from2' => $p['from'], 'to2' => $p['to'], 'from3' => $p['from'], 'to3' => $p['to'], 'from4' => $p['from'], 'to4' => $p['to'], 'from5' => $p['from'], 'to5' => $p['to']]
        );
        foreach ($rows as &$r) $r['cost_per_day'] = (float) $r['days_present'] > 0 ? round((float) $r['wages'] / (float) $r['days_present'], 2) : null;
        $cols = [self::col('name', 'Worker'), self::col('job_title', 'Role'), self::col('days_present', 'Days present', 'number'), self::col('days_absent', 'Days absent', 'number'), self::col('tasks_done', 'Tasks done', 'number'),
            self::col('task_hours', 'Task hours', 'number'), self::col('avg_rating', 'Rating (1-5)', 'number'), self::col('wages', 'Wages paid/due', 'money'), self::col('cost_per_day', 'Cost / day', 'money')];
        return [$cols, $rows, ['Workers' => count($rows), 'Total wages' => round(array_sum(array_column($rows, 'wages')), 2), 'Tasks completed' => array_sum(array_column($rows, 'tasks_done'))]];
    }

    private static function r_irrigation(array $p, callable $wf): array
    {
        $rows = Db::all(
            "SELECT i.name, i.system_type, fl.name AS field, i.status, COUNT(s.id) AS sessions, ROUND(IFNULL(SUM(s.duration_min),0)/60,1) AS hours,
                    IFNULL(SUM(s.actual_volume_litres),0) AS litres, ROUND(IFNULL(SUM(s.energy_cost),0),2) AS energy_cost,
                    ROUND(IFNULL(SUM(s.actual_volume_litres),0) / NULLIF(fl.size_ha,0)) AS litres_per_ha
             FROM irrigation_systems i LEFT JOIN fields fl ON fl.id = i.field_id
             LEFT JOIN irrigation_schedules s ON s.system_id = i.id AND s.status = 'done' AND s.scheduled_date BETWEEN :from AND :to
             WHERE 1=1" . $wf('i.farm_id') . ' GROUP BY i.id ORDER BY litres DESC',
            $p
        );
        $cols = [self::col('name', 'System'), self::col('system_type', 'Type'), self::col('field', 'Field'), self::col('status', 'Status'), self::col('sessions', 'Sessions', 'number'), self::col('hours', 'Run hours', 'number'),
            self::col('litres', 'Water (L)', 'number'), self::col('litres_per_ha', 'L / ha', 'number'), self::col('energy_cost', 'Energy cost', 'money')];
        return [$cols, $rows, ['Total water (L)' => array_sum(array_column($rows, 'litres')), 'Energy cost' => round(array_sum(array_column($rows, 'energy_cost')), 2)]];
    }

    private static function r_pest_disease(array $p, callable $wf): array
    {
        $rows = Db::all(
            "SELECT IFNULL(pc.name,'Unidentified') AS pest, IFNULL(pc.kind,'-') AS kind, COUNT(DISTINCT sr.id) AS reports,
                    SUM(sr.status <> 'resolved') AS open_reports, ROUND(AVG(sr.affected_area_pct),1) AS avg_area_pct,
                    SUM(sr.severity IN ('high','critical')) AS severe, COUNT(t.id) AS treatments, ROUND(IFNULL(SUM(t.cost),0),2) AS treatment_cost,
                    ROUND(AVG(t.effectiveness),1) AS avg_effectiveness
             FROM scouting_reports sr JOIN fields fl ON fl.id = sr.field_id LEFT JOIN pest_catalog pc ON pc.id = sr.catalog_id LEFT JOIN treatments t ON t.scouting_id = sr.id
             WHERE sr.report_date BETWEEN :from AND :to" . $wf('fl.farm_id') . ' GROUP BY pc.id, pc.name, pc.kind ORDER BY reports DESC',
            $p
        );
        $cols = [self::col('pest', 'Pest / disease'), self::col('kind', 'Kind'), self::col('reports', 'Reports', 'number'), self::col('open_reports', 'Open', 'number'), self::col('severe', 'Severe', 'number'),
            self::col('avg_area_pct', 'Avg area %', 'number'), self::col('treatments', 'Treatments', 'number'), self::col('treatment_cost', 'Treatment cost', 'money'), self::col('avg_effectiveness', 'Effectiveness (1-10)', 'number')];
        return [$cols, $rows, ['Reports' => array_sum(array_column($rows, 'reports')), 'Treatment cost' => round(array_sum(array_column($rows, 'treatment_cost')), 2)]];
    }

    private static function r_equipment(array $p, callable $wf): array
    {
        $rows = Db::all(
            "SELECT e.name, e.equipment_type, e.status, e.total_hours,
               (SELECT IFNULL(SUM(u.hours),0) FROM equipment_usage u WHERE u.equipment_id = e.id AND u.usage_date BETWEEN :from AND :to) AS period_hours,
               (SELECT IFNULL(SUM(u.fuel_litres),0) FROM equipment_usage u WHERE u.equipment_id = e.id AND u.usage_date BETWEEN :from2 AND :to2) AS fuel_litres,
               (SELECT IFNULL(SUM(u.fuel_cost),0) FROM equipment_usage u WHERE u.equipment_id = e.id AND u.usage_date BETWEEN :from3 AND :to3) AS fuel_cost,
               (SELECT IFNULL(SUM(m.cost),0) FROM maintenance_records m WHERE m.equipment_id = e.id AND m.status = 'done' AND m.completed_date BETWEEN :from4 AND :to4) AS maintenance_cost,
               ROUND(GREATEST(e.purchase_cost - ((e.purchase_cost - e.salvage_value) / GREATEST(e.useful_life_years,1)) * (IFNULL(DATEDIFF(CURDATE(), e.purchase_date),0) / 365.25), e.salvage_value), 2) AS book_value
             FROM equipment e WHERE 1=1" . $wf('e.farm_id') . ' ORDER BY e.name',
            ['from' => $p['from'], 'to' => $p['to'], 'from2' => $p['from'], 'to2' => $p['to'], 'from3' => $p['from'], 'to3' => $p['to'], 'from4' => $p['from'], 'to4' => $p['to']]
        );
        foreach ($rows as &$r) $r['cost_per_hour'] = (float) $r['period_hours'] > 0 ? round(((float) $r['fuel_cost'] + (float) $r['maintenance_cost']) / (float) $r['period_hours'], 2) : null;
        $cols = [self::col('name', 'Equipment'), self::col('equipment_type', 'Type'), self::col('status', 'Status'), self::col('period_hours', 'Hours (period)', 'number'), self::col('total_hours', 'Lifetime hours', 'number'),
            self::col('fuel_litres', 'Fuel (L)', 'number'), self::col('fuel_cost', 'Fuel cost', 'money'), self::col('maintenance_cost', 'Maintenance', 'money'), self::col('cost_per_hour', 'Cost / hour', 'money'), self::col('book_value', 'Book value', 'money')];
        return [$cols, $rows, ['Fuel cost' => round(array_sum(array_column($rows, 'fuel_cost')), 2), 'Maintenance cost' => round(array_sum(array_column($rows, 'maintenance_cost')), 2), 'Book value' => round(array_sum(array_column($rows, 'book_value')), 2)]];
    }

    private static function r_profitability(array $p, callable $wf): array
    {
        $crops = Db::all('SELECT id, name FROM crops WHERE 1=1' . $wf('farm_id') . ' ORDER BY name');
        $rows = [];
        foreach ($crops as $c) {
            $q = ['c' => $c['id']] + $p;
            $rev = (float) Db::val("SELECT IFNULL(SUM(i.quantity_kg * i.unit_price),0) FROM sales_order_items i JOIN sales_orders o ON o.id = i.order_id WHERE i.crop_id = :c AND o.status <> 'cancelled' AND o.order_date BETWEEN :from AND :to", $q);
            $cost = (float) Db::val("SELECT IFNULL(SUM(amount),0) FROM transactions WHERE crop_id = :c AND txn_type = 'expense' AND txn_date BETWEEN :from AND :to", $q);
            $area = (float) Db::val("SELECT IFNULL(SUM(area_ha),0) FROM plantings WHERE crop_id = :c AND planting_date BETWEEN :from AND :to", $q);
            $kg = (float) Db::val("SELECT IFNULL(SUM(h.quantity_kg - h.loss_kg),0) FROM harvests h JOIN plantings p ON p.id = h.planting_id WHERE p.crop_id = :c AND h.harvest_date BETWEEN :from AND :to", $q);
            if ($rev == 0.0 && $cost == 0.0 && $area == 0.0) continue;
            $rows[] = [
                'crop' => $c['name'], 'area_ha' => round($area, 1), 'harvested_kg' => round($kg), 'revenue' => round($rev, 2), 'direct_cost' => round($cost, 2),
                'profit' => round($rev - $cost, 2), 'margin_pct' => $rev > 0 ? round(($rev - $cost) / $rev * 100, 1) : null, 'roi_pct' => $cost > 0 ? round(($rev - $cost) / $cost * 100, 1) : null,
                'cost_per_ha' => $area > 0 ? round($cost / $area, 2) : null, 'cost_per_kg' => $kg > 0 ? round($cost / $kg, 2) : null,
            ];
        }
        usort($rows, fn($a, $b) => $b['profit'] <=> $a['profit']);
        $cols = [self::col('crop', 'Crop'), self::col('area_ha', 'Area (ha)', 'number'), self::col('harvested_kg', 'Harvested (kg)', 'number'), self::col('revenue', 'Revenue', 'money'), self::col('direct_cost', 'Direct cost', 'money'),
            self::col('profit', 'Profit', 'money'), self::col('margin_pct', 'Margin %', 'number'), self::col('roi_pct', 'ROI %', 'number'), self::col('cost_per_ha', 'Cost / ha', 'money'), self::col('cost_per_kg', 'Cost / kg', 'money')];
        $rev = array_sum(array_column($rows, 'revenue'));
        $cost = array_sum(array_column($rows, 'direct_cost'));
        return [$cols, $rows, ['Revenue' => round($rev, 2), 'Direct costs' => round($cost, 2), 'Crop profit' => round($rev - $cost, 2), 'Note' => 'Revenue is by order date (accrual); costs are expenses tagged to the crop.']];
    }

    private static function r_farm_performance(array $p, callable $wf): array
    {
        $inc = (float) Db::val("SELECT IFNULL(SUM(amount),0) FROM transactions WHERE txn_type='income' AND txn_date BETWEEN :from AND :to" . $wf('farm_id'), $p);
        $exp = (float) Db::val("SELECT IFNULL(SUM(amount),0) FROM transactions WHERE txn_type='expense' AND txn_date BETWEEN :from AND :to" . $wf('farm_id'), $p);
        $ha = (float) Db::val("SELECT IFNULL(SUM(area_ha),0) FROM plantings WHERE planting_date BETWEEN :from AND :to" . $wf('farm_id'), $p);
        $kg = (float) Db::val("SELECT IFNULL(SUM(h.quantity_kg - h.loss_kg),0) FROM harvests h JOIN plantings p ON p.id = h.planting_id WHERE h.harvest_date BETWEEN :from AND :to" . $wf('p.farm_id'), $p);
        $loss = (float) Db::val("SELECT IFNULL(SUM(h.loss_kg),0) / NULLIF(SUM(h.quantity_kg),0) * 100 FROM harvests h JOIN plantings p ON p.id = h.planting_id WHERE h.harvest_date BETWEEN :from AND :to" . $wf('p.farm_id'), $p);
        $water = (float) Db::val("SELECT IFNULL(SUM(s.actual_volume_litres),0) FROM irrigation_schedules s JOIN irrigation_systems i ON i.id = s.system_id WHERE s.status='done' AND s.scheduled_date BETWEEN :from AND :to" . $wf('i.farm_id'), $p);
        $milk = (float) Db::val("SELECT IFNULL(SUM(quantity),0) FROM livestock_production WHERE product = 'milk' AND production_date BETWEEN :from AND :to" . $wf('farm_id'), $p);
        $workers = (int) Db::val("SELECT COUNT(*) FROM workers WHERE status='active'" . $wf('farm_id'));
        $labour = (float) Db::val("SELECT IFNULL(SUM(amount),0) FROM transactions WHERE category='labour' AND txn_type='expense' AND txn_date BETWEEN :from AND :to" . $wf('farm_id'), $p);
        $m = fn(string $k, $v, string $note = '') => ['metric' => $k, 'value' => $v, 'note' => $note];
        $rows = [
            $m('Total income', round($inc, 2)), $m('Total expenses', round($exp, 2)), $m('Net profit', round($inc - $exp, 2)),
            $m('Profit margin %', $inc > 0 ? round(($inc - $exp) / $inc * 100, 1) : 0), $m('Return on investment %', $exp > 0 ? round(($inc - $exp) / $exp * 100, 1) : 0, '(income − expenses) ÷ expenses'),
            $m('Area planted (ha)', round($ha, 1)), $m('Harvested (kg, net)', round($kg)), $m('Average yield (kg/ha)', $ha > 0 ? round($kg / $ha) : 0),
            $m('Cost per hectare', $ha > 0 ? round($exp / $ha, 2) : 0, 'All expenses ÷ area planted'), $m('Cost per kg harvested', $kg > 0 ? round($exp / $kg, 2) : 0),
            $m('Post-harvest loss %', round($loss, 1)), $m('Water applied (m³)', round($water / 1000)), $m('Water per kg harvested (L)', $kg > 0 ? round($water / $kg, 1) : 0),
            $m('Milk produced (L)', round($milk)), $m('Active workers', $workers), $m('Labour cost per worker', $workers > 0 ? round($labour / $workers, 2) : 0),
        ];
        $cols = [self::col('metric', 'Metric'), self::col('value', 'Value', 'number'), self::col('note', 'Note')];
        return [$cols, $rows, ['Net profit' => round($inc - $exp, 2)]];
    }

    public static function csv(array $report): string
    {
        $h = fopen('php://temp', 'r+');
        fputcsv($h, [$report['title'], $report['from'] . ' to ' . $report['to']]);
        fputcsv($h, array_map(fn($c) => $c['label'], $report['columns']));
        foreach ($report['rows'] as $row) {
            fputcsv($h, array_map(function ($c) use ($row) {
                $v = $row[$c['key']] ?? '';
                return is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v;
            }, $report['columns']));
        }
        rewind($h);
        $out = stream_get_contents($h);
        fclose($h);
        return $out;
    }
}
