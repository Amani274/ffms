<?php

declare(strict_types=1);

/** Multi-row business documents: sales orders, invoices, purchase orders, payroll runs. */
final class Documents
{
    private static function items(string $resource, array $items, string $parentCol, int $parentId, int $farmId, string $cropOrItemCheck): void
    {
        $cols = Registry::get($resource)['cols'];
        unset($cols[$parentCol]);
        $errors = [];
        $clean = [];
        foreach (array_values($items) as $i => $item) {
            if (!is_array($item)) fail(422, 'Items must be a list of objects.');
            try {
                $row = Validator::check($cols, $item, false);
            } catch (ApiError $e) {
                foreach ($e->errors as $f => $m) $errors["items.$i.$f"] = $m;
                continue;
            }
            if ($cropOrItemCheck === 'crop' && !Db::val('SELECT 1 FROM crops WHERE id = ? AND farm_id = ?', [$row['crop_id'], $farmId])) {
                $errors["items.$i.crop_id"] = 'Crop not found on this farm.';
            }
            if ($cropOrItemCheck === 'item' && !empty($row['item_id']) && !Db::val('SELECT 1 FROM inventory_items WHERE id = ? AND farm_id = ?', [$row['item_id'], $farmId])) {
                $errors["items.$i.item_id"] = 'Inventory item not found on this farm.';
            }
            $clean[] = $row;
        }
        if ($errors) fail(422, 'Please correct the highlighted fields.', $errors);
        foreach ($clean as $row) Db::insert($resource, [$parentCol => $parentId] + $row);
    }

    public static function createOrder(array $d, array $items): int
    {
        $d['order_no'] = $d['order_no'] ?? 'TMP-' . bin2hex(random_bytes(6));
        $id = Db::insert('sales_orders', $d);
        self::items('sales_order_items', $items, 'order_id', $id, (int) $d['farm_id'], 'crop');
        Db::update('sales_orders', $id, ['order_no' => sprintf('SO-%s-%04d', gmdate('Y'), $id)]);
        self::recalcOrder($id);
        return $id;
    }

    public static function recalcOrder(int $orderId): void
    {
        $total = (float) Db::val('SELECT IFNULL(SUM(quantity_kg * unit_price),0) FROM sales_order_items WHERE order_id = ?', [$orderId]);
        Db::update('sales_orders', $orderId, ['total_amount' => round($total, 2)]);
        Db::exec("UPDATE invoices SET amount = ? WHERE order_id = ? AND amount_paid = 0 AND status = 'unpaid'", [round($total, 2), $orderId]);
    }

    public static function createPurchaseOrder(array $d, array $items): int
    {
        $d['po_no'] = $d['po_no'] ?? 'TMP-' . bin2hex(random_bytes(6));
        $d['status'] = 'draft';
        $id = Db::insert('purchase_orders', $d);
        self::items('purchase_order_items', $items, 'po_id', $id, (int) $d['farm_id'], 'item');
        Db::update('purchase_orders', $id, ['po_no' => sprintf('PO-%s-%04d', gmdate('Y'), $id)]);
        self::recalcPurchaseOrder($id);
        return $id;
    }

    public static function recalcPurchaseOrder(int $poId): void
    {
        $total = (float) Db::val('SELECT IFNULL(SUM(quantity * unit_price),0) FROM purchase_order_items WHERE po_id = ?', [$poId]);
        Db::update('purchase_orders', $poId, ['total_amount' => round($total, 2)]);
    }

    /** Mark a delivered order's storage lots as dispatched. */
    public static function fulfilOrder(int $orderId): void
    {
        $order = Db::one('SELECT customer_id, delivery_date, order_date FROM sales_orders WHERE id = ?', [$orderId]);
        $cust = Db::one('SELECT name, address FROM customers WHERE id = ?', [$order['customer_id']]);
        foreach (Db::all('SELECT * FROM sales_order_items WHERE order_id = ? AND lot_id IS NOT NULL', [$orderId]) as $it) {
            if (Db::val('SELECT 1 FROM dispatches WHERE order_id = ? AND lot_id = ?', [$orderId, $it['lot_id']])) continue;
            $lot = Db::one('SELECT quantity_kg FROM storage_lots WHERE id = ? FOR UPDATE', [$it['lot_id']]);
            $qty = min((float) $it['quantity_kg'], (float) $lot['quantity_kg']);
            if ($qty <= 0) continue;
            Db::insert('dispatches', [
                'lot_id' => $it['lot_id'], 'order_id' => $orderId, 'dispatch_date' => $order['delivery_date'] ?? gmdate('Y-m-d'),
                'quantity_kg' => $qty, 'destination' => $cust['name'], 'status' => 'delivered',
            ]);
            Db::exec('UPDATE storage_lots SET quantity_kg = quantity_kg - ? WHERE id = ?', [$qty, $it['lot_id']]);
            Db::exec("UPDATE storage_lots SET status = IF(quantity_kg <= 0.0001, 'dispatched', status) WHERE id = ?", [$it['lot_id']]);
        }
    }
}

final class Actions
{
    private static function requireRole(array $user, array $roles): void
    {
        if (!in_array($user['role'], $roles, true)) fail(403, 'Your role is not permitted to perform this action.');
        Auth::verifyCsrf();
    }

    private static function owned(string $table, int $id, array $user): array
    {
        $row = Db::one("SELECT * FROM `$table` WHERE id = ?", [$id]) ?? fail(404, 'Record not found.');
        if ($user['farm_id'] !== null && (int) $row['farm_id'] !== (int) $user['farm_id']) fail(404, 'Record not found.');
        return $row;
    }

    /** POST sales/orders/{id}/invoice */
    public static function invoiceOrder(int $orderId): never
    {
        $user = Auth::require();
        self::requireRole($user, ['admin', 'owner', 'manager', 'accountant']);
        $order = self::owned('sales_orders', $orderId, $user);
        if ($order['status'] === 'cancelled') fail(409, 'A cancelled order cannot be invoiced.');
        if (Db::val('SELECT 1 FROM invoices WHERE order_id = ?', [$orderId])) fail(409, 'This order already has an invoice.');
        if ((float) $order['total_amount'] <= 0) fail(422, 'Add at least one order line before invoicing.');
        $days = max(0, min(180, (int) (body()['due_days'] ?? 14)));
        $id = Db::tx(function () use ($order, $orderId, $days) {
            $id = Db::insert('invoices', [
                'farm_id' => $order['farm_id'], 'order_id' => $orderId, 'invoice_no' => 'TMP-' . bin2hex(random_bytes(6)),
                'issue_date' => gmdate('Y-m-d'), 'due_date' => gmdate('Y-m-d', strtotime("+$days days")), 'amount' => $order['total_amount'],
            ]);
            Db::update('invoices', $id, ['invoice_no' => sprintf('INV-%s-%04d', gmdate('Y'), $id)]);
            if ($order['status'] === 'pending') Db::update('sales_orders', $orderId, ['status' => 'confirmed']);
            return $id;
        });
        Audit::log('create', 'invoices', (string) $id, ['order_id' => $orderId], $user);
        reply(Db::one('SELECT * FROM invoices WHERE id = ?', [$id]), 201);
    }

    /** POST purchasing/{id}/{submit|approve|reject|order|receive|cancel|pay} */
    public static function purchasing(int $poId, string $action): never
    {
        $user = Auth::require();
        $po = self::owned('purchase_orders', $poId, $user);
        $flow = [
            'submit' => [['draft'], 'pending_approval', ['admin', 'owner', 'manager']],
            'approve' => [['pending_approval'], 'approved', ['admin', 'owner', 'manager']],
            'reject' => [['pending_approval'], 'draft', ['admin', 'owner', 'manager']],
            'order' => [['approved'], 'ordered', ['admin', 'owner', 'manager']],
            'receive' => [['ordered'], 'received', ['admin', 'owner', 'manager']],
            'cancel' => [['draft', 'pending_approval', 'approved', 'ordered'], 'cancelled', ['admin', 'owner', 'manager']],
        ];
        if ($action === 'pay') {
            self::requireRole($user, ['admin', 'owner', 'accountant']);
            if (!in_array($po['status'], ['ordered', 'received'], true)) fail(409, 'Only ordered or received purchase orders can be paid.');
            $amount = round((float) (body()['amount'] ?? 0), 2);
            $balance = round((float) $po['total_amount'] - (float) $po['amount_paid'], 2);
            if ($amount <= 0 || $amount > $balance + 0.005) fail(422, 'Please correct the highlighted fields.', ['amount' => "Enter an amount up to the outstanding balance ($balance)."]);
            $date = (string) (body()['payment_date'] ?? gmdate('Y-m-d'));
            Db::tx(function () use ($poId, $po, $amount, $date) {
                $paid = round((float) $po['amount_paid'] + $amount, 2);
                Db::update('purchase_orders', $poId, ['amount_paid' => $paid]);
                Ledger::post((int) $po['farm_id'], 'expense', 'procurement', $paid, $date, 'Payments for ' . $po['po_no'], 'po_paid', $poId);
            });
            Audit::log('pay', 'purchase_orders', (string) $poId, ['amount' => $amount], $user);
            reply(Db::one('SELECT * FROM purchase_orders WHERE id = ?', [$poId]));
        }
        if (!isset($flow[$action])) fail(404, 'Unknown purchasing action.');
        [$from, $to, $roles] = $flow[$action];
        self::requireRole($user, $roles);
        if (!in_array($po['status'], $from, true)) fail(409, "A purchase order that is '{$po['status']}' cannot be moved with '$action'.");
        if ($action === 'submit' && (float) $po['total_amount'] <= 0) fail(422, 'Add at least one line before submitting.');

        Db::tx(function () use ($poId, $po, $action, $to, $user) {
            $upd = ['status' => $to];
            if ($action === 'approve') $upd += ['approved_by' => $user['id'], 'approved_at' => gmdate('Y-m-d H:i:s')];
            if ($action === 'receive') {
                $upd['received_at'] = gmdate('Y-m-d H:i:s');
                foreach (Db::all('SELECT * FROM purchase_order_items WHERE po_id = ? AND item_id IS NOT NULL', [$poId]) as $line) {
                    Stock::move((int) $line['item_id'], 'in', (float) $line['quantity'], gmdate('Y-m-d'), $po['po_no'], 'Goods received', (float) $line['unit_price']);
                }
            }
            Db::update('purchase_orders', $poId, $upd);
        });
        Audit::log($action, 'purchase_orders', (string) $poId, [], $user);
        reply(Db::one('SELECT * FROM purchase_orders WHERE id = ?', [$poId]));
    }

    /** POST payroll/generate {farm_id, period_start, period_end} */
    public static function generatePayroll(): never
    {
        $user = Auth::require();
        self::requireRole($user, ['admin', 'owner', 'accountant']);
        $in = body();
        $start = (string) ($in['period_start'] ?? '');
        $end = (string) ($in['period_end'] ?? '');
        foreach ([$start, $end] as $d) {
            $dt = DateTime::createFromFormat('Y-m-d', $d);
            if (!$dt || $dt->format('Y-m-d') !== $d) fail(422, 'period_start and period_end must be dates (YYYY-MM-DD).');
        }
        if ($end < $start) fail(422, 'The period end must not be before the start.');
        $farm = Crud::writeFarm(['user' => $user, 'farm' => null], $in);
        $pct = (float) Env::get('PAYROLL_DEDUCTION_PCT', '5');
        $periodDays = (int) ((strtotime($end) - strtotime($start)) / 86400) + 1;

        $created = 0;
        Db::tx(function () use ($farm, $start, $end, $pct, $periodDays, &$created) {
            foreach (Db::all("SELECT * FROM workers WHERE farm_id = ? AND status = 'active'", [$farm]) as $w) {
                if (Db::val('SELECT 1 FROM payroll_runs WHERE worker_id = ? AND period_start = ? AND period_end = ?', [$w['id'], $start, $end])) continue;
                $att = Db::one(
                    "SELECT IFNULL(SUM(CASE status WHEN 'present' THEN 1 WHEN 'half' THEN 0.5 ELSE 0 END),0) AS days, IFNULL(SUM(CASE WHEN status IN ('present','half') THEN hours ELSE 0 END),0) AS hrs
                     FROM worker_attendance WHERE worker_id = ? AND work_date BETWEEN ? AND ?",
                    [$w['id'], $start, $end]
                );
                $rate = (float) $w['wage_rate'];
                $gross = match ($w['wage_type']) {
                    'monthly' => $rate * min(1, $periodDays / 30),
                    'hourly' => $rate * (float) $att['hrs'],
                    default => $rate * (float) $att['days'],
                };
                $gross = round($gross, 2);
                $ded = round($gross * $pct / 100, 2);
                Db::insert('payroll_runs', [
                    'worker_id' => $w['id'], 'period_start' => $start, 'period_end' => $end, 'days_worked' => $att['days'],
                    'gross_pay' => $gross, 'deductions' => $ded, 'net_pay' => round($gross - $ded, 2), 'status' => 'draft',
                ]);
                $created++;
            }
        });
        Audit::log('generate', 'payroll_runs', null, ['farm_id' => $farm, 'period' => "$start..$end", 'created' => $created], $user);
        reply(['created' => $created, 'deduction_pct' => $pct], 201);
    }

    /** POST alerts/refresh */
    public static function refreshAlerts(): never
    {
        $ctx = Crud::context();
        Auth::verifyCsrf();
        $farms = $ctx['farm'] !== null ? [$ctx['farm']] : array_map('intval', array_column(Db::all('SELECT id FROM farms'), 'id'));
        $out = [];
        foreach ($farms as $f) $out[$f] = AlertEngine::refresh($f);
        reply($out);
    }
}
