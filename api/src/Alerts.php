<?php

declare(strict_types=1);

/**
 * Rule engine that derives alerts from live farm data. Alerts are keyed (dedupe_key) so repeated runs
 * update rather than duplicate; alerts whose condition has cleared are auto-resolved.
 */
final class AlertEngine
{
    /** Re-evaluate at most once per $maxAgeSeconds per farm (stamp file under storage/logs). */
    public static function refreshIfStale(int $farmId, int $maxAgeSeconds = 600): void
    {
        $dir = dirname(__DIR__, 2) . '/storage/logs';
        $stamp = "$dir/alerts-$farmId.stamp";
        if (is_file($stamp) && time() - (int) filemtime($stamp) < $maxAgeSeconds) return;
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        @touch($stamp);
        try {
            self::refresh($farmId);
        } catch (Throwable $e) {
            error_log('FFMS alert refresh failed: ' . $e->getMessage());
        }
    }

    public static function refresh(int $farmId): array
    {
        $found = [];
        $add = function (string $type, string $sev, string $title, string $msg, ?string $entity, ?int $eid, ?string $due, string $key) use (&$found) {
            $found[$key] = compact('type', 'sev', 'title', 'msg', 'entity', 'eid', 'due');
        };
        $today = gmdate('Y-m-d');

        // Low stock
        foreach (Db::all('SELECT id, name, quantity, unit, reorder_level FROM inventory_items WHERE farm_id = ? AND quantity <= reorder_level AND reorder_level > 0', [$farmId]) as $i) {
            $out = (float) $i['quantity'] <= 0;
            $add('low_inventory', $out ? 'critical' : 'warning', ($out ? 'Out of stock: ' : 'Low stock: ') . $i['name'],
                sprintf('%s %s left (reorder level %s). Raise a purchase order.', (float) $i['quantity'], $i['unit'], (float) $i['reorder_level']), 'inventory_items', (int) $i['id'], null, "low:{$i['id']}");
        }
        // Expiring / expired inputs
        foreach (Db::all('SELECT id, name, category, expiry_date, DATEDIFF(expiry_date, CURDATE()) AS d FROM inventory_items WHERE farm_id = ? AND expiry_date IS NOT NULL AND quantity > 0 AND DATEDIFF(expiry_date, CURDATE()) <= 30', [$farmId]) as $i) {
            $d = (int) $i['d'];
            $add('expiring_stock', $d < 0 ? 'critical' : 'warning', ($d < 0 ? 'Expired: ' : 'Expiring soon: ') . $i['name'],
                $d < 0 ? 'Expired ' . abs($d) . ' day(s) ago — do not use; dispose safely.' : "Expires in $d day(s) ({$i['expiry_date']}). Use or replace.", 'inventory_items', (int) $i['id'], $i['expiry_date'], "exp:{$i['id']}");
        }
        // Vaccinations due
        foreach (Db::all(
            "SELECT h.id, h.name, h.next_due_date, a.tag_no, a.species, DATEDIFF(h.next_due_date, CURDATE()) AS d
             FROM animal_health h JOIN animals a ON a.id = h.animal_id
             WHERE a.farm_id = ? AND a.status = 'active' AND h.next_due_date IS NOT NULL AND DATEDIFF(h.next_due_date, CURDATE()) <= 14
               AND NOT EXISTS (SELECT 1 FROM animal_health h2 WHERE h2.animal_id = h.animal_id AND h2.name = h.name AND h2.record_date > h.record_date)",
            [$farmId]
        ) as $v) {
            $d = (int) $v['d'];
            $add('vaccination', $d < 0 ? 'critical' : 'warning', ($d < 0 ? 'Overdue: ' : 'Due: ') . $v['name'] . ' – ' . $v['tag_no'],
                $d < 0 ? abs($d) . " day(s) overdue for {$v['species']} {$v['tag_no']}." : "Due in $d day(s) for {$v['species']} {$v['tag_no']}.", 'animal_health', (int) $v['id'], $v['next_due_date'], "vacc:{$v['id']}");
        }
        // Equipment maintenance
        foreach (Db::all("SELECT m.id, m.scheduled_date, m.description, e.name, DATEDIFF(m.scheduled_date, CURDATE()) AS d FROM maintenance_records m JOIN equipment e ON e.id = m.equipment_id WHERE e.farm_id = ? AND m.status = 'scheduled' AND DATEDIFF(m.scheduled_date, CURDATE()) <= 7", [$farmId]) as $m) {
            $d = (int) $m['d'];
            $add('maintenance', $d < 0 ? 'critical' : 'warning', ($d < 0 ? 'Overdue maintenance: ' : 'Maintenance due: ') . $m['name'],
                ($m['description'] ?: 'Scheduled service') . ($d < 0 ? ' was due ' . abs($d) . ' day(s) ago.' : " is due in $d day(s)."), 'maintenance_records', (int) $m['id'], $m['scheduled_date'], "maint:{$m['id']}");
        }
        foreach (Db::all("SELECT id, name, service_interval_hours, total_hours FROM equipment WHERE farm_id = ? AND service_interval_hours IS NOT NULL AND status <> 'retired'", [$farmId]) as $e) {
            $lastHours = (float) (Db::val("SELECT IFNULL(SUM(hours),0) FROM equipment_usage WHERE equipment_id = ? AND usage_date > IFNULL((SELECT MAX(completed_date) FROM maintenance_records WHERE equipment_id = ? AND status = 'done' AND maintenance_type = 'service'), '1970-01-01')", [$e['id'], $e['id']]));
            if ($lastHours >= (float) $e['service_interval_hours'] * 0.9) {
                $add('maintenance', $lastHours >= (float) $e['service_interval_hours'] ? 'critical' : 'warning', 'Service hours reached: ' . $e['name'],
                    sprintf('%.0f hours since last service (interval %d h).', $lastHours, $e['service_interval_hours']), 'equipment', (int) $e['id'], null, "svc:{$e['id']}");
            }
        }
        // Equipment faults / irrigation problems
        foreach (Db::all("SELECT id, name, status FROM irrigation_systems WHERE farm_id = ? AND status IN ('fault','offline')", [$farmId]) as $s) {
            $add('irrigation_problem', 'critical', 'Irrigation ' . $s['status'] . ': ' . $s['name'], 'Crops served by this system may be water-stressed. Inspect pump, filters and lines.', 'irrigation_systems', (int) $s['id'], null, "irrfault:{$s['id']}");
        }
        foreach (Db::all("SELECT id, name, status FROM water_sources WHERE farm_id = ? AND status IN ('low','dry')", [$farmId]) as $w) {
            $add('irrigation_problem', $w['status'] === 'dry' ? 'critical' : 'warning', 'Water source ' . $w['status'] . ': ' . $w['name'], 'Plan alternative supply or reduce irrigation demand.', 'water_sources', (int) $w['id'], null, "ws:{$w['id']}");
        }
        // Scheduled irrigation today/tomorrow
        foreach (Db::all("SELECT s.id, s.scheduled_date, s.start_time, i.name, DATEDIFF(s.scheduled_date, CURDATE()) AS d FROM irrigation_schedules s JOIN irrigation_systems i ON i.id = s.system_id WHERE i.farm_id = ? AND s.status = 'planned' AND DATEDIFF(s.scheduled_date, CURDATE()) BETWEEN -1 AND 1", [$farmId]) as $s) {
            $d = (int) $s['d'];
            $add('irrigation', $d < 0 ? 'warning' : 'info', ($d < 0 ? 'Missed irrigation: ' : ($d === 0 ? 'Irrigation today: ' : 'Irrigation tomorrow: ')) . $s['name'], 'Scheduled for ' . $s['scheduled_date'] . ' at ' . substr((string) $s['start_time'], 0, 5) . '.', 'irrigation_schedules', (int) $s['id'], $s['scheduled_date'], "irr:{$s['id']}");
        }
        // Scheduled crop activities (planting, fertiliser, spraying)
        foreach (Db::all(
            "SELECT a.id, a.activity_type, a.activity_date, a.product_name, cr.name AS crop, fl.name AS field, DATEDIFF(a.activity_date, CURDATE()) AS d
             FROM crop_activities a JOIN plantings p ON p.id = a.planting_id JOIN crops cr ON cr.id = p.crop_id JOIN fields fl ON fl.id = p.field_id
             WHERE p.farm_id = ? AND a.scheduled = 1 AND a.posted = 0 AND DATEDIFF(a.activity_date, CURDATE()) <= 7",
            [$farmId]
        ) as $a) {
            $d = (int) $a['d'];
            $type = in_array($a['activity_type'], ['fertilizer', 'manure'], true) ? 'fertilizer' : ($a['activity_type'] === 'spraying' ? 'spraying' : 'crop_activity');
            $add($type, $d < 0 ? 'warning' : 'info', ucfirst($a['activity_type']) . ($d < 0 ? ' overdue: ' : ' scheduled: ') . $a['crop'] . ' – ' . $a['field'],
                ($a['product_name'] ? $a['product_name'] . '. ' : '') . ($d < 0 ? abs($d) . ' day(s) overdue.' : ($d === 0 ? 'Due today.' : "Due in $d day(s).")), 'crop_activities', (int) $a['id'], $a['activity_date'], "act:{$a['id']}");
        }
        // Upcoming plantings
        foreach (Db::all("SELECT p.id, p.planting_date, cr.name AS crop, fl.name AS field, DATEDIFF(p.planting_date, CURDATE()) AS d FROM plantings p JOIN crops cr ON cr.id = p.crop_id JOIN fields fl ON fl.id = p.field_id WHERE p.farm_id = ? AND p.status = 'planned' AND DATEDIFF(p.planting_date, CURDATE()) <= 10", [$farmId]) as $p) {
            $d = (int) $p['d'];
            $add('planting', $d < 0 ? 'warning' : 'info', 'Planting ' . ($d < 0 ? 'overdue: ' : 'coming up: ') . $p['crop'] . ' – ' . $p['field'], $d < 0 ? abs($d) . ' day(s) past the planned date.' : "Planned for {$p['planting_date']} (in $d day(s)).", 'plantings', (int) $p['id'], $p['planting_date'], "plant:{$p['id']}");
        }
        // Expected harvests
        foreach (Db::all("SELECT p.id, p.expected_harvest_date, cr.name AS crop, fl.name AS field, DATEDIFF(p.expected_harvest_date, CURDATE()) AS d FROM plantings p JOIN crops cr ON cr.id = p.crop_id JOIN fields fl ON fl.id = p.field_id WHERE p.farm_id = ? AND p.status = 'growing' AND DATEDIFF(p.expected_harvest_date, CURDATE()) <= 14", [$farmId]) as $p) {
            $d = (int) $p['d'];
            $add('harvest', $d < 0 ? 'warning' : 'info', ($d < 0 ? 'Harvest overdue: ' : 'Harvest approaching: ') . $p['crop'] . ' – ' . $p['field'], $d < 0 ? abs($d) . ' day(s) past expected harvest date.' : "Expected {$p['expected_harvest_date']} (in $d day(s)). Arrange labour, packaging and storage.", 'plantings', (int) $p['id'], $p['expected_harvest_date'], "harv:{$p['id']}");
        }
        // Pest & disease outbreaks
        foreach (Db::all("SELECT sr.id, sr.severity, sr.affected_area_pct, fl.name AS field, IFNULL(pc.name,'Unidentified issue') AS pest FROM scouting_reports sr JOIN fields fl ON fl.id = sr.field_id LEFT JOIN pest_catalog pc ON pc.id = sr.catalog_id WHERE fl.farm_id = ? AND sr.status <> 'resolved' AND sr.severity IN ('high','critical')", [$farmId]) as $s) {
            $add('disease_outbreak', $s['severity'] === 'critical' ? 'critical' : 'warning', ucfirst($s['severity']) . ' infestation: ' . $s['pest'] . ' – ' . $s['field'], sprintf('%.0f%% of the area affected. Review recommended control measures and schedule treatment.', $s['affected_area_pct']), 'scouting_reports', (int) $s['id'], null, "pest:{$s['id']}");
        }
        foreach (Db::all("SELECT sr.catalog_id, pc.name, COUNT(*) AS c FROM scouting_reports sr JOIN fields fl ON fl.id = sr.field_id JOIN pest_catalog pc ON pc.id = sr.catalog_id WHERE fl.farm_id = ? AND sr.report_date >= (CURDATE() - INTERVAL 14 DAY) AND sr.status <> 'resolved' GROUP BY sr.catalog_id, pc.name HAVING c >= 3", [$farmId]) as $o) {
            $add('disease_outbreak', 'critical', 'Possible outbreak: ' . $o['name'], $o['c'] . ' unresolved reports in the last 14 days across your fields.', 'pest_catalog', (int) $o['catalog_id'], null, "outbreak:{$o['catalog_id']}");
        }
        // Weather risks
        foreach (Weather::risks(array_slice(Weather::forecast($farmId), 0, 3)) as $r) {
            $add('weather', $r['severity'], ucfirst(str_replace('_', ' ', $r['type'])) . ' risk – ' . date('D j M', strtotime($r['date'])), $r['message'], null, null, $r['date'], 'wx:' . $r['type'] . ':' . $r['date']);
        }
        // Storage spoilage risk
        foreach (Db::all("SELECT l.id, l.lot_code, cr.name AS crop, l.max_storage_days - DATEDIFF(CURDATE(), l.stored_date) AS left_days FROM storage_lots l JOIN warehouses w ON w.id = l.warehouse_id JOIN crops cr ON cr.id = l.crop_id WHERE w.farm_id = ? AND l.status = 'stored' AND l.quantity_kg > 0 AND l.max_storage_days - DATEDIFF(CURDATE(), l.stored_date) <= 14", [$farmId]) as $l) {
            $d = (int) $l['left_days'];
            $add('spoilage', $d <= 0 ? 'critical' : 'warning', 'Storage limit ' . ($d <= 0 ? 'exceeded: ' : 'nearing: ') . $l['crop'] . ' (' . $l['lot_code'] . ')', $d <= 0 ? 'Past the safe storage period — sell or dispatch immediately.' : "$d day(s) of safe storage left. Prioritise sales.", 'storage_lots', (int) $l['id'], null, "lot:{$l['id']}");
        }
        // Market opportunities: price well above trailing average and stock on hand
        foreach (Db::all("SELECT DISTINCT l.crop_id, cr.name FROM storage_lots l JOIN warehouses w ON w.id = l.warehouse_id JOIN crops cr ON cr.id = l.crop_id WHERE w.farm_id = ? AND l.status = 'stored' AND l.quantity_kg > 0", [$farmId]) as $c) {
            $f = Predict::priceForecast((int) $c['crop_id']);
            if (!$f['available']) continue;
            $tail = array_slice($f['history'], -12);
            $avg12 = array_sum(array_column($tail, 'price')) / max(1, count($tail));
            if ($avg12 > 0 && $f['current_price'] >= $avg12 * 1.10) {
                $add('market_opportunity', 'info', 'Strong price for ' . $c['name'], sprintf('Market price K%.2f/kg is %.0f%% above the 12-month average. You have stock in storage — consider selling.', $f['current_price'], ($f['current_price'] / $avg12 - 1) * 100), 'crops', (int) $c['crop_id'], null, "mkt:{$c['crop_id']}");
            }
        }

        return self::persist($farmId, $found, $today);
    }

    private static function persist(int $farmId, array $found, string $today): array
    {
        $new = 0;
        $stmt = Db::pdo()->prepare(
            'INSERT INTO alerts (farm_id, alert_type, severity, title, message, entity, entity_id, due_date, dedupe_key, status, created_at)
             VALUES (:f,:t,:s,:ti,:m,:e,:ei,:d,:k,\'new\',UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE severity = VALUES(severity), title = VALUES(title), message = VALUES(message), due_date = VALUES(due_date)'
        );
        foreach ($found as $key => $a) {
            $stmt->execute(['f' => $farmId, 't' => $a['type'], 's' => $a['sev'], 'ti' => mb_substr($a['title'], 0, 190), 'm' => mb_substr($a['msg'], 0, 500),
                'e' => $a['entity'], 'ei' => $a['eid'], 'd' => $a['due'], 'k' => $key]);
            if ($stmt->rowCount() === 1) {
                $new++;
                if (in_array($a['sev'], ['critical', 'warning'], true)) {
                    Notifier::notify($farmId, $a['sev'], $a['title'], $a['msg']);
                }
            }
        }
        // Conditions that have cleared are removed, so the same alert can fire again if the problem returns.
        // A manually resolved alert stays visible (as resolved) only while its condition persists.
        $keys = array_keys($found);
        $resolved = 0;
        foreach (Db::all('SELECT id, dedupe_key, status FROM alerts WHERE farm_id = ?', [$farmId]) as $row) {
            if (!isset($found[$row['dedupe_key']])) {
                Db::exec('DELETE FROM alerts WHERE id = ?', [$row['id']]);
                if ($row['status'] !== 'resolved') $resolved++;
            }
        }
        return ['active' => count($keys), 'new' => $new, 'auto_resolved' => $resolved, 'refreshed_at' => $today];
    }
}

/** Notification hook: critical/warning alerts are appended to storage/logs/notifications.log. Replace with an SMTP/SMS gateway call to go live. */
final class Notifier
{
    public static function notify(int $farmId, string $severity, string $title, string $message): void
    {
        $dir = dirname(__DIR__, 2) . '/storage/logs';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $to = implode(', ', array_column(Db::all("SELECT email FROM users WHERE active = 1 AND role IN ('owner','manager') AND (farm_id IS NULL OR farm_id = ?)", [$farmId]), 'email'));
        @file_put_contents("$dir/notifications.log", sprintf("%s [%s] farm=%d to=%s | %s — %s
", gmdate('c'), $severity, $farmId, $to, $title, $message), FILE_APPEND);
    }
}
