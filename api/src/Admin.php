<?php

declare(strict_types=1);

final class Admin
{
    // ---------------------------------------------------------------- metadata for the UI

    public static function meta(): never
    {
        $ctx = Crud::context();
        $user = $ctx['user'];
        $farms = Db::all('SELECT id, name, location, latitude, longitude, size_ha FROM farms' . ($user['farm_id'] ? ' WHERE id = ' . (int) $user['farm_id'] : '') . ' ORDER BY name');
        $resources = [];
        foreach (Registry::all() as $name => $r) {
            $cols = [];
            foreach ($r['cols'] as $c => $rule) {
                $p = Validator::parse($rule);
                $cols[$c] = ['type' => $p['type'], 'required' => $p['required'], 'enum' => $p['type'] === 'enum' ? explode(',', (string) $p['args']) : null, 'fk' => $p['fk'], 'default' => $p['has_default'] ? $p['default'] : null];
            }
            $ops = $r['ops'] ?? ['list', 'get', 'create', 'update', 'delete'];
            $resources[$name] = [
                'label' => $r['label'], 'read' => in_array($user['role'], $r['read'], true),
                'create' => in_array('create', $ops, true) && in_array($user['role'], $r['write'], true) && empty($r['own']),
                'update' => in_array('update', $ops, true) && in_array($user['role'], $r['write'], true),
                'delete' => in_array('delete', $ops, true) && in_array($user['role'], $r['delete'] ?? $r['write'], true),
                'cols' => $cols,
            ];
        }
        $reports = [];
        foreach (Reports::catalog() as $k => [$title, $roles, $desc]) {
            if (in_array($user['role'], $roles, true)) $reports[] = ['key' => $k, 'title' => $title, 'description' => $desc];
        }
        reply([
            'user' => $user, 'csrf' => Auth::csrfToken(), 'farms' => $farms, 'active_farm' => $ctx['farm'],
            'resources' => $resources, 'reports' => $reports, 'currency' => Env::get('CURRENCY', 'ZMW'), 'roles' => Auth::ROLES,
        ]);
    }

    private const OPTION_LABEL = [
        'farms' => 't.name', 'fields' => 't.name', 'crops' => "CONCAT(t.name, IF(t.variety IS NULL OR t.variety = '', '', CONCAT(' – ', t.variety)))",
        'plantings' => "CONCAT(cr.name, ' – ', fl.name, ' (', t.season, ')')", 'inventory_items' => "CONCAT(t.name, ' (', t.unit, ')')",
        'suppliers' => 't.name', 'customers' => 't.name', 'equipment' => 't.name', 'workers' => 't.name', 'animals' => "CONCAT(t.tag_no, ' – ', t.species)",
        'warehouses' => 't.name', 'storage_lots' => "CONCAT(t.lot_code, ' – ', cr.name, ' (', ROUND(t.quantity_kg), ' kg)')",
        'scouting_reports' => "CONCAT(fl.name, ' – ', IFNULL(pc.name, 'Observation'), ' (', t.report_date, ')')", 'pest_catalog' => 't.name',
        'water_sources' => 't.name', 'irrigation_systems' => 't.name', 'invoices' => "CONCAT(t.invoice_no, ' – balance ', ROUND(t.amount - t.amount_paid, 2))",
        'sales_orders' => 't.order_no', 'purchase_orders' => 't.po_no', 'contracts' => "CONCAT('Contract #', t.id, IFNULL(CONCAT(' – ', cr.name), ''))",
        'loans' => 't.lender', 'harvests' => "CONCAT(cr.name, ' – ', t.harvest_date)",
    ];

    public static function options(string $name): never
    {
        $ctx = Crud::context();
        if (!isset(self::OPTION_LABEL[$name])) fail(404, 'No options for this resource.');
        $res = Registry::get($name);
        if (!in_array($ctx['user']['role'], $res['read'], true)) fail(403, 'Your role cannot view this resource.');
        $p = [];
        $where = '';
        if (!empty($res['scope']) && $ctx['farm'] !== null) {
            $where .= " AND {$res['scope']} = :farm";
            $p['farm'] = $ctx['farm'];
        }
        if (!empty($res['own']) && $ctx['user']['role'] === 'worker') {
            $where .= " AND ({$res['own']})";
            $p['uid'] = $ctx['user']['id'];
        }
        $label = self::OPTION_LABEL[$name];
        $extra = $name === 'inventory_items' && !empty($_GET['category']) ? ' AND t.category = :cat' : '';
        if ($extra) $p['cat'] = (string) $_GET['category'];
        $status = '';
        if ($name === 'plantings' && ($_GET['active'] ?? '') === '1') $status = " AND t.status IN ('growing','planned')";
        if ($name === 'storage_lots' && ($_GET['available'] ?? '') === '1') $status = " AND t.status IN ('stored','partial') AND t.quantity_kg > 0";
        if ($name === 'animals' && ($_GET['active'] ?? '') === '1') $status = " AND t.status = 'active'";
        $rows = Db::all(
            "SELECT t.id, $label AS label FROM `{$res['table']}` t " . ($res['join'] ?? '') . ' ' . ($res['join_extra'] ?? '') . " WHERE 1=1$where$extra$status ORDER BY label LIMIT 500",
            $p
        );
        reply(array_map(fn($r) => ['id' => (int) $r['id'], 'label' => (string) $r['label']], $rows));
    }

    // ---------------------------------------------------------------- users

    private static function authorize(array $user): void
    {
        if (!in_array($user['role'], ['admin', 'owner'], true)) fail(403, 'Only administrators and owners can manage users.');
    }

    private static function publicUser(array $u): array
    {
        unset($u['password_hash']);
        $u['id'] = (int) $u['id'];
        $u['active'] = (int) $u['active'];
        $u['farm_id'] = $u['farm_id'] !== null ? (int) $u['farm_id'] : null;
        return $u;
    }

    public static function users(string $method, ?string $id): never
    {
        $user = Auth::require();
        self::authorize($user);
        $cols = [
            'name' => 'str:120!', 'email' => 'email!', 'role' => 'enum:' . implode(',', Auth::ROLES) . '!', 'farm_id' => 'int|fk:farms',
            'phone' => 'str:40', 'active' => 'bool|default:1',
        ];
        if ($method === 'GET') {
            $sel = 'SELECT u.id, u.name, u.email, u.role, u.farm_id, f.name AS farm_name, u.phone, u.active, u.last_login_at, u.created_at FROM users u LEFT JOIN farms f ON f.id = u.farm_id';
            if ($id === null) {
                reply(array_map([self::class, 'publicUser'], Db::all("$sel ORDER BY u.role, u.name")));
            }
            $row = Db::one("$sel WHERE u.id = ?", [(int) $id]) ?? fail(404, 'User not found.');
            reply(self::publicUser($row));
        }
        Auth::verifyCsrf();
        $in = body();

        if ($method === 'POST' && $id === null) {
            $data = Validator::check($cols, $in, false);
            self::guardRole($user, $data['role']);
            $pw = (string) ($in['password'] ?? '');
            Auth::checkPasswordStrength($pw);
            if (Db::val('SELECT 1 FROM users WHERE email = ?', [$data['email']])) fail(422, 'Please correct the highlighted fields.', ['email' => 'This email is already registered.']);
            $data['password_hash'] = Auth::hashPassword($pw);
            $newId = Db::insert('users', $data);
            Audit::log('create', 'users', (string) $newId, ['email' => $data['email'], 'role' => $data['role']], $user);
            reply(self::publicUser(Db::one('SELECT * FROM users WHERE id = ?', [$newId])), 201);
        }
        if (!ctype_digit((string) $id)) fail(400, 'Invalid user id.');
        $target = Db::one('SELECT * FROM users WHERE id = ?', [(int) $id]) ?? fail(404, 'User not found.');
        self::guardRole($user, $target['role']);

        if (in_array($method, ['PUT', 'PATCH'], true)) {
            $data = Validator::check($cols, $in, true);
            if (isset($data['role'])) self::guardRole($user, $data['role']);
            if ((int) $target['id'] === (int) $user['id'] && ((isset($data['active']) && !$data['active']) || (isset($data['role']) && $data['role'] !== $target['role']))) {
                fail(409, 'You cannot deactivate or change the role of your own account.');
            }
            if (isset($data['email']) && Db::val('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$data['email'], $target['id']])) fail(422, 'Please correct the highlighted fields.', ['email' => 'This email is already registered.']);
            if (!empty($in['password'])) {
                Auth::checkPasswordStrength((string) $in['password']);
                $data['password_hash'] = Auth::hashPassword((string) $in['password']);
            }
            if (!$data) fail(400, 'No updatable fields were supplied.');
            Db::update('users', (int) $target['id'], $data);
            Audit::log('update', 'users', (string) $target['id'], array_diff_key($data, ['password_hash' => 1]) + (isset($data['password_hash']) ? ['password' => 'reset'] : []), $user);
            reply(self::publicUser(Db::one('SELECT * FROM users WHERE id = ?', [$target['id']])));
        }
        if ($method === 'DELETE') {
            if ((int) $target['id'] === (int) $user['id']) fail(409, 'You cannot delete your own account.');
            Db::exec('DELETE FROM users WHERE id = ?', [$target['id']]);
            Audit::log('delete', 'users', (string) $target['id'], ['email' => $target['email']], $user);
            reply(['id' => (int) $target['id'], 'deleted' => true]);
        }
        fail(405, 'Method not allowed.');
    }

    private static function guardRole(array $actor, string $targetRole): void
    {
        if ($actor['role'] !== 'admin' && $targetRole === 'admin') fail(403, 'Only an administrator can manage administrator accounts.');
    }

    public static function changePassword(): never
    {
        $user = Auth::require();
        Auth::verifyCsrf();
        $in = body();
        $row = Db::one('SELECT password_hash FROM users WHERE id = ?', [$user['id']]);
        if (!password_verify((string) ($in['current_password'] ?? ''), $row['password_hash'])) fail(422, 'Please correct the highlighted fields.', ['current_password' => 'Current password is incorrect.']);
        Auth::checkPasswordStrength((string) ($in['new_password'] ?? ''));
        Db::update('users', (int) $user['id'], ['password_hash' => Auth::hashPassword((string) $in['new_password'])]);
        Audit::log('password_change', 'users', (string) $user['id'], [], $user);
        reply(['changed' => true]);
    }

    // ---------------------------------------------------------------- backups

    public static function backups(string $method, ?string $name): never
    {
        $user = Auth::require();
        if ($user['role'] !== 'admin') fail(403, 'Only administrators can manage backups.');
        $dir = dirname(__DIR__, 2) . '/storage/backups';
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        if ($method === 'POST') {
            Auth::verifyCsrf();
            $file = Backup::create();
            Audit::log('backup', 'system', null, ['file' => basename($file)], $user);
            reply(['file' => basename($file), 'size' => filesize($file)], 201);
        }
        if ($name !== null) {
            if (!preg_match('/^ffms-\d{8}-\d{6}\.sql$/', $name) || !is_file("$dir/$name")) fail(404, 'Backup not found.');
            header('Content-Type: application/sql');
            header('Content-Disposition: attachment; filename="' . $name . '"');
            header('Content-Length: ' . filesize("$dir/$name"));
            readfile("$dir/$name");
            exit;
        }
        $files = [];
        foreach (glob("$dir/ffms-*.sql") ?: [] as $f) $files[] = ['file' => basename($f), 'size' => filesize($f), 'created_at' => gmdate('Y-m-d H:i:s', filemtime($f))];
        usort($files, fn($a, $b) => strcmp($b['file'], $a['file']));
        reply($files);
    }
}

final class Backup
{
    /** Portable SQL dump written with PDO only (no external binaries). Restore with: mysql ffms < file.sql */
    public static function create(): string
    {
        $dir = dirname(__DIR__, 2) . '/storage/backups';
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $file = $dir . '/ffms-' . gmdate('Ymd-His') . '.sql';
        $h = fopen($file, 'wb');
        $pdo = Db::pdo();
        fwrite($h, "-- FFMS backup " . gmdate('c') . "\nSET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n\n");
        $tables = array_column(Db::all('SHOW TABLES'), 'Tables_in_' . Env::get('DB_NAME', 'ffms'));
        foreach ($tables as $t) {
            $create = Db::one("SHOW CREATE TABLE `$t`");
            fwrite($h, "DROP TABLE IF EXISTS `$t`;\n" . ($create['Create Table'] ?? '') . ";\n\n");
            $st = $pdo->query("SELECT * FROM `$t`");
            $batch = [];
            while ($row = $st->fetch()) {
                $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $row);
                $batch[] = '(' . implode(',', $vals) . ')';
                if (count($batch) >= 200) {
                    fwrite($h, "INSERT INTO `$t` VALUES " . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            }
            if ($batch) fwrite($h, "INSERT INTO `$t` VALUES " . implode(",\n", $batch) . ";\n");
            fwrite($h, "\n");
        }
        fwrite($h, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($h);
        return $file;
    }
}
