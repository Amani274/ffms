<?php

declare(strict_types=1);

/** Idempotent finance postings keyed by (ref_type, ref_id). */
final class Ledger
{
    public static function post(int $farmId, string $type, string $category, float $amount, string $date, ?string $desc, string $refType, int $refId, array $links = []): void
    {
        if ($amount <= 0) {
            self::remove($refType, $refId);
            return;
        }
        $row = [
            'farm_id' => $farmId, 'txn_type' => $type, 'category' => $category, 'amount' => round($amount, 2), 'txn_date' => $date,
            'description' => $desc !== null ? mb_substr($desc, 0, 255) : null,
            'field_id' => $links['field_id'] ?? null, 'crop_id' => $links['crop_id'] ?? null, 'planting_id' => $links['planting_id'] ?? null,
        ];
        $existing = Db::val('SELECT id FROM transactions WHERE ref_type = ? AND ref_id = ?', [$refType, $refId]);
        if ($existing) {
            Db::update('transactions', (int) $existing, $row);
        } else {
            Db::insert('transactions', $row + ['ref_type' => $refType, 'ref_id' => $refId, 'created_by' => Auth::user()['id'] ?? null]);
        }
    }

    public static function remove(string $refType, int $refId): void
    {
        Db::exec('DELETE FROM transactions WHERE ref_type = ? AND ref_id = ?', [$refType, $refId]);
    }
}

final class Stock
{
    /** Apply a stock movement atomically. Returns [movementId, unitCost]. */
    public static function move(int $itemId, string $type, float $qty, string $date, ?string $reference = null, ?string $notes = null, ?float $unitCost = null): array
    {
        $item = Db::one('SELECT * FROM inventory_items WHERE id = ? FOR UPDATE', [$itemId]);
        if (!$item) fail(404, 'Inventory item not found.');
        $current = (float) $item['quantity'];
        $cost = $unitCost !== null && $unitCost > 0 ? $unitCost : (float) $item['unit_cost'];

        if ($type === 'out') {
            if ($qty > $current + 0.0001) {
                fail(409, sprintf('Insufficient stock for %s: %s %s available, %s requested.', $item['name'], rtrim(rtrim(number_format($current, 2, '.', ''), '0'), '.'), $item['unit'], rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.')));
            }
            $new = $current - $qty;
        } else {
            $new = $current + $qty;
            if ($unitCost !== null && $unitCost > 0 && $new > 0) {
                $cost = (($current * (float) $item['unit_cost']) + ($qty * $unitCost)) / $new;
            }
        }
        Db::update('inventory_items', $itemId, ['quantity' => round($new, 2), 'unit_cost' => round($cost, 2)]);
        $id = Db::insert('stock_movements', [
            'item_id' => $itemId, 'movement_type' => $type, 'quantity' => $qty, 'unit_cost' => round($cost, 2),
            'movement_date' => $date, 'reference' => $reference, 'notes' => $notes, 'user_id' => Auth::user()['id'] ?? null,
        ]);
        return [$id, $cost];
    }
}

final class Hooks
{
    private static function planting(int $id): array
    {
        return Db::one('SELECT id, farm_id, field_id, crop_id FROM plantings WHERE id = ?', [$id]) ?? fail(404, 'Planting not found.');
    }

    public static function before(string $res, string $action, array &$d, ?array $old, array $ctx): void
    {
        $today = gmdate('Y-m-d');
        switch ($res) {
            case 'plantings':
                if ($action === 'create') {
                    $crop = Db::one('SELECT days_to_maturity, expected_yield_kg_ha FROM crops WHERE id = ?', [$d['crop_id']]);
                    if (empty($d['expected_harvest_date'])) {
                        $d['expected_harvest_date'] = gmdate('Y-m-d', strtotime($d['planting_date'] . ' +' . (int) $crop['days_to_maturity'] . ' days'));
                    }
                    if (empty($d['expected_yield_kg'])) $d['expected_yield_kg'] = round((float) $crop['expected_yield_kg_ha'] * (float) $d['area_ha'], 2);
                }
                break;
            case 'tasks':
                if (isset($d['status'])) {
                    if ($d['status'] === 'done' && empty($old['completed_at'])) $d['completed_at'] = gmdate('Y-m-d H:i:s');
                    if ($d['status'] !== 'done') $d['completed_at'] = null;
                }
                break;
            case 'loans':
                if ($action === 'create' && !isset($d['balance'])) $d['balance'] = $d['principal'];
                break;
            case 'scouting_reports':
                if ($action === 'create') $d['reported_by'] = $ctx['user']['id'];
                break;
            case 'animals':
                if (($d['status'] ?? null) === 'dead' && empty($d['death_date']) && empty($old['death_date'])) $d['death_date'] = $today;
                break;
            case 'storage_lots':
                if ($action === 'create' && empty($d['lot_code'])) $d['lot_code'] = 'LOT-' . gmdate('ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
                break;
            case 'payroll_runs':
                if ($action === 'create' && (!isset($d['net_pay']) || (float) $d['net_pay'] == 0.0)) {
                    $d['net_pay'] = max(0, (float) ($d['gross_pay'] ?? 0) - (float) ($d['deductions'] ?? 0));
                }
                if (($d['status'] ?? null) === 'paid' && ($old['status'] ?? null) !== 'paid') $d['paid_at'] = gmdate('Y-m-d H:i:s');
                if (($d['status'] ?? null) === 'draft') $d['paid_at'] = null;
                break;
            case 'maintenance_records':
                if (($d['status'] ?? null) === 'done' && empty($d['completed_date']) && empty($old['completed_date'])) $d['completed_date'] = $today;
                break;
            case 'payments':
                if ($action === 'create') {
                    $inv = Db::one('SELECT amount, amount_paid, status FROM invoices WHERE id = ? FOR UPDATE', [$d['invoice_id']]);
                    if ($inv['status'] === 'void') fail(422, 'Cannot record a payment against a void invoice.');
                    $balance = round((float) $inv['amount'] - (float) $inv['amount_paid'], 2);
                    if ((float) $d['amount'] > $balance + 0.005) fail(422, 'Please correct the highlighted fields.', ['amount' => "Exceeds the outstanding balance of $balance."]);
                }
                break;
            case 'dispatches':
                if ($action === 'create') {
                    $lot = Db::one('SELECT quantity_kg FROM storage_lots WHERE id = ? FOR UPDATE', [$d['lot_id']]);
                    if ((float) $d['quantity_kg'] > (float) $lot['quantity_kg'] + 0.0001) {
                        fail(422, 'Please correct the highlighted fields.', ['quantity_kg' => 'Exceeds the quantity available in this lot (' . $lot['quantity_kg'] . ' kg).']);
                    }
                }
                break;
        }
    }

    public static function customCreate(string $res, array $d, array $extras, array $ctx): int
    {
        switch ($res) {
            case 'stock_movements':
                [$id] = Stock::move((int) $d['item_id'], $d['movement_type'], (float) $d['quantity'], $d['movement_date'], $d['reference'] ?? null, $d['notes'] ?? null, (float) ($d['unit_cost'] ?? 0));
                return $id;
            case 'sales_orders':
                return Documents::createOrder($d, $extras['items'] ?? []);
            case 'purchase_orders':
                return Documents::createPurchaseOrder($d, $extras['items'] ?? []);
        }
        fail(500, 'Unsupported custom create.');
    }

    public static function after(string $res, string $action, array $row, ?array $old, array $extras, array $ctx): void
    {
        $id = (int) ($row['id'] ?? 0);
        switch ($res) {
            case 'crop_activities':
                self::postActivity($row);
                break;
            case 'treatments':
                self::postTreatment($row);
                break;
            case 'feed_records':
                self::postFeed($row);
                break;
            case 'equipment_usage':
                $delta = (float) $row['hours'] - (float) ($old['hours'] ?? 0);
                if ($delta != 0.0) Db::exec('UPDATE equipment SET total_hours = GREATEST(0, total_hours + ?) WHERE id = ?', [$delta, $row['equipment_id']]);
                $farm = (int) Db::val('SELECT farm_id FROM equipment WHERE id = ?', [$row['equipment_id']]);
                Ledger::post($farm, 'expense', 'fuel', (float) $row['fuel_cost'], $row['usage_date'], 'Fuel for equipment usage', 'equip_usage', $id, ['field_id' => $row['field_id']]);
                break;
            case 'maintenance_records':
                $farm = (int) Db::val('SELECT farm_id FROM equipment WHERE id = ?', [$row['equipment_id']]);
                if ($row['status'] === 'done') {
                    Ledger::post($farm, 'expense', 'equipment', (float) $row['cost'], $row['completed_date'] ?? $row['scheduled_date'], ucfirst($row['maintenance_type']) . ': ' . ($row['description'] ?? 'maintenance'), 'maintenance', $id);
                } else {
                    Ledger::remove('maintenance', $id);
                }
                break;
            case 'irrigation_schedules':
                if ($row['status'] === 'done') {
                    $sys = Db::one('SELECT farm_id, field_id, flow_rate_lph FROM irrigation_systems WHERE id = ?', [$row['system_id']]);
                    if ($row['actual_volume_litres'] === null) {
                        $vol = (int) $row['planned_volume_litres'] ?: (int) round((float) $sys['flow_rate_lph'] * (int) $row['duration_min'] / 60);
                        Db::exec('UPDATE irrigation_schedules SET actual_volume_litres = ? WHERE id = ?', [$vol, $id]);
                    }
                    Ledger::post((int) $sys['farm_id'], 'expense', 'utilities', (float) $row['energy_cost'], $row['scheduled_date'], 'Irrigation energy cost', 'irrigation', $id, ['field_id' => $sys['field_id']]);
                } else {
                    Ledger::remove('irrigation', $id);
                }
                break;
            case 'payroll_runs':
                $w = Db::one('SELECT farm_id, name FROM workers WHERE id = ?', [$row['worker_id']]);
                if ($row['status'] === 'paid') {
                    Ledger::post((int) $w['farm_id'], 'expense', 'labour', (float) $row['net_pay'], substr((string) ($row['paid_at'] ?? gmdate('Y-m-d')), 0, 10), 'Wages: ' . $w['name'], 'payroll', $id);
                } else {
                    Ledger::remove('payroll', $id);
                }
                break;
            case 'payments':
                self::syncInvoice((int) $row['invoice_id']);
                $inv = Db::one('SELECT farm_id, invoice_no FROM invoices WHERE id = ?', [$row['invoice_id']]);
                Ledger::post((int) $inv['farm_id'], 'income', 'sales', (float) $row['amount'], $row['payment_date'], 'Payment for ' . $inv['invoice_no'], 'payment', $id);
                break;
            case 'harvests':
                self::afterHarvest($row, $extras, $action);
                break;
            case 'dispatches':
                if ($action === 'create') {
                    Db::exec('UPDATE storage_lots SET quantity_kg = quantity_kg - ? WHERE id = ?', [$row['quantity_kg'], $row['lot_id']]);
                    self::syncLotStatus((int) $row['lot_id']);
                }
                break;
            case 'animal_weights':
                $latest = Db::val('SELECT weight_kg FROM animal_weights WHERE animal_id = ? ORDER BY weigh_date DESC, id DESC LIMIT 1', [$row['animal_id']]);
                if ($latest !== null) Db::exec('UPDATE animals SET current_weight_kg = ? WHERE id = ?', [$latest, $row['animal_id']]);
                break;
            case 'sales_order_items':
                Documents::recalcOrder((int) $row['order_id']);
                break;
            case 'purchase_order_items':
                Documents::recalcPurchaseOrder((int) $row['po_id']);
                break;
        }
    }

    public static function deleted(string $res, array $old, array $ctx): void
    {
        $id = (int) $old['id'];
        switch ($res) {
            case 'crop_activities': Ledger::remove('crop_activity', $id); break;
            case 'treatments': Ledger::remove('treatment', $id); break;
            case 'feed_records': Ledger::remove('feed', $id); break;
            case 'maintenance_records': Ledger::remove('maintenance', $id); break;
            case 'irrigation_schedules': Ledger::remove('irrigation', $id); break;
            case 'payroll_runs': Ledger::remove('payroll', $id); break;
            case 'equipment_usage':
                Db::exec('UPDATE equipment SET total_hours = GREATEST(0, total_hours - ?) WHERE id = ?', [$old['hours'], $old['equipment_id']]);
                Ledger::remove('equip_usage', $id);
                break;
            case 'payments':
                self::syncInvoice((int) $old['invoice_id']);
                Ledger::remove('payment', $id);
                break;
            case 'dispatches':
                Db::exec('UPDATE storage_lots SET quantity_kg = quantity_kg + ? WHERE id = ?', [$old['quantity_kg'], $old['lot_id']]);
                self::syncLotStatus((int) $old['lot_id']);
                break;
            case 'sales_order_items': Documents::recalcOrder((int) $old['order_id']); break;
            case 'purchase_order_items': Documents::recalcPurchaseOrder((int) $old['po_id']); break;
            case 'invoices': Db::exec("DELETE FROM transactions WHERE ref_type = 'payment' AND ref_id IN (SELECT id FROM payments WHERE invoice_id = ?)", [$id]); break;
        }
    }

    // ---- posting helpers (stock deduction happens once; the expense follows later edits) ----

    private static function postActivity(array $r): void
    {
        $pl = self::planting((int) $r['planting_id']);
        $links = ['field_id' => $pl['field_id'], 'crop_id' => $pl['crop_id'], 'planting_id' => $pl['id']];
        if ((int) $r['scheduled'] === 1) {
            Ledger::remove('crop_activity', (int) $r['id']);
            return;
        }
        $cost = (float) $r['cost'];
        if (!(int) $r['posted']) {
            if ($r['item_id'] && (float) $r['quantity'] > 0) {
                [, $unit] = Stock::move((int) $r['item_id'], 'out', (float) $r['quantity'], $r['activity_date'], 'crop_activity#' . $r['id'], $r['activity_type']);
                if ($cost <= 0) $cost = round($unit * (float) $r['quantity'], 2);
            }
            Db::exec('UPDATE crop_activities SET posted = 1, cost = ? WHERE id = ?', [$cost, $r['id']]);
        }
        $label = ucfirst($r['activity_type']) . ($r['product_name'] ? ': ' . $r['product_name'] : '');
        Ledger::post((int) $pl['farm_id'], 'expense', 'input', $cost, $r['activity_date'], $label, 'crop_activity', (int) $r['id'], $links);
    }

    private static function postTreatment(array $r): void
    {
        $sr = Db::one('SELECT sr.field_id, sr.planting_id, fl.farm_id, pl.crop_id FROM scouting_reports sr JOIN fields fl ON fl.id = sr.field_id LEFT JOIN plantings pl ON pl.id = sr.planting_id WHERE sr.id = ?', [$r['scouting_id']]);
        $cost = (float) $r['cost'];
        if (!(int) $r['posted']) {
            if ($r['item_id'] && (float) $r['quantity'] > 0) {
                [, $unit] = Stock::move((int) $r['item_id'], 'out', (float) $r['quantity'], $r['treatment_date'], 'treatment#' . $r['id'], 'pest/disease treatment');
                if ($cost <= 0) $cost = round($unit * (float) $r['quantity'], 2);
            }
            Db::exec('UPDATE treatments SET posted = 1, cost = ? WHERE id = ?', [$cost, $r['id']]);
        }
        Ledger::post((int) $sr['farm_id'], 'expense', 'input', $cost, $r['treatment_date'], 'Treatment: ' . $r['chemical_name'], 'treatment', (int) $r['id'],
            ['field_id' => $sr['field_id'], 'crop_id' => $sr['crop_id'], 'planting_id' => $sr['planting_id']]);
        Db::exec("UPDATE scouting_reports SET status = 'treating' WHERE id = ? AND status = 'open'", [$r['scouting_id']]);
    }

    private static function postFeed(array $r): void
    {
        $cost = (float) $r['cost'];
        if (!(int) $r['posted']) {
            if ($r['item_id']) {
                [, $unit] = Stock::move((int) $r['item_id'], 'out', (float) $r['quantity_kg'], $r['feed_date'], 'feed#' . $r['id'], 'livestock feed');
                if ($cost <= 0) $cost = round($unit * (float) $r['quantity_kg'], 2);
            }
            Db::exec('UPDATE feed_records SET posted = 1, cost = ? WHERE id = ?', [$cost, $r['id']]);
        }
        Ledger::post((int) $r['farm_id'], 'expense', 'livestock', $cost, $r['feed_date'], 'Livestock feed', 'feed', (int) $r['id']);
    }

    private static function afterHarvest(array $r, array $extras, string $action): void
    {
        $pl = self::planting((int) $r['planting_id']);
        if (!empty($extras['mark_complete'])) {
            Db::exec("UPDATE plantings SET status = 'harvested', growth_stage = 'harvested' WHERE id = ?", [$pl['id']]);
        }
        if ($action === 'create' && $r['warehouse_id']) {
            $net = round((float) $r['quantity_kg'] - (float) $r['loss_kg'], 2);
            if ($net > 0) {
                $price = Db::val('SELECT price_per_kg FROM market_prices WHERE crop_id = ? ORDER BY price_date DESC, id DESC LIMIT 1', [$pl['crop_id']]) ?? 0;
                Db::insert('storage_lots', [
                    'warehouse_id' => $r['warehouse_id'], 'harvest_id' => $r['id'], 'crop_id' => $pl['crop_id'],
                    'lot_code' => 'LOT-' . gmdate('ymd', strtotime($r['harvest_date'])) . '-' . str_pad((string) $r['id'], 4, '0', STR_PAD_LEFT),
                    'quantity_kg' => $net, 'grade' => $r['grade'], 'stored_date' => $r['harvest_date'], 'unit_value' => $price,
                ]);
            }
        }
    }

    public static function syncInvoice(int $invoiceId): void
    {
        $paid = (float) Db::val('SELECT IFNULL(SUM(amount),0) FROM payments WHERE invoice_id = ?', [$invoiceId]);
        $inv = Db::one('SELECT amount, status FROM invoices WHERE id = ?', [$invoiceId]);
        $status = $inv['status'] === 'void' ? 'void' : ($paid <= 0 ? 'unpaid' : ($paid + 0.005 >= (float) $inv['amount'] ? 'paid' : 'partial'));
        Db::update('invoices', $invoiceId, ['amount_paid' => round($paid, 2), 'status' => $status]);
    }

    private static function syncLotStatus(int $lotId): void
    {
        $lot = Db::one('SELECT quantity_kg, status FROM storage_lots WHERE id = ?', [$lotId]);
        if ($lot['status'] === 'spoiled') return;
        Db::update('storage_lots', $lotId, ['status' => (float) $lot['quantity_kg'] <= 0.0001 ? 'dispatched' : 'stored']);
    }
}
