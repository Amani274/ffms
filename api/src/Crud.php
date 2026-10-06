<?php

declare(strict_types=1);

final class Crud
{
    /** Resolve the farm the request is operating on (null = all accessible farms). */
    public static function context(): array
    {
        $user = Auth::require();
        $requested = isset($_GET['farm_id']) && ctype_digit((string) $_GET['farm_id']) ? (int) $_GET['farm_id'] : null;
        $farm = $user['farm_id'] !== null ? (int) $user['farm_id'] : $requested;
        return ['user' => $user, 'farm' => $farm];
    }

    /** Farm to attach to new records. */
    public static function writeFarm(array $ctx, array $input): int
    {
        $farm = isset($input['farm_id']) && is_numeric($input['farm_id']) ? (int) $input['farm_id'] : null;
        if ($ctx['user']['farm_id'] !== null) $farm = (int) $ctx['user']['farm_id'];
        $farm ??= $ctx['farm'];
        $farm ??= (int) Db::val('SELECT id FROM farms ORDER BY id LIMIT 1');
        if (!$farm) fail(422, 'No farm exists yet. Create a farm first.');
        return $farm;
    }

    public static function handle(string $name, ?string $id, string $method): never
    {
        $res = Registry::get($name);
        if (!$res) fail(404, 'Unknown resource.');
        $ctx = self::context();
        $role = $ctx['user']['role'];
        $ops = $res['ops'] ?? ['list', 'get', 'create', 'update', 'delete'];

        $op = match (true) {
            $method === 'GET' && $id === null => 'list',
            $method === 'GET' => 'get',
            $method === 'POST' && $id === null => 'create',
            in_array($method, ['PUT', 'PATCH'], true) && $id !== null => 'update',
            $method === 'DELETE' && $id !== null => 'delete',
            default => fail(405, 'Method not allowed.'),
        };
        if (!in_array($op, $ops, true)) fail(405, 'This operation is not supported for this resource.');

        if (in_array($op, ['list', 'get'], true)) {
            if (!in_array($role, $res['read'], true)) fail(403, 'Your role cannot view this resource.');
        } else {
            $allowed = $op === 'delete' ? ($res['delete'] ?? $res['write']) : $res['write'];
            if (!in_array($role, $allowed, true)) fail(403, 'Your role is not permitted to perform this action.');
            Auth::verifyCsrf();
        }
        if ($id !== null && !ctype_digit($id)) fail(400, 'Invalid record id.');

        match ($op) {
            'list' => self::list($name, $res, $ctx),
            'get' => reply(self::mustFind($name, (int) $id, $ctx)),
            'create' => self::create($name, $res, $ctx),
            'update' => self::update($name, $res, (int) $id, $ctx),
            'delete' => self::delete($name, $res, (int) $id, $ctx),
        };
    }

    private static function fromClause(array $res): string
    {
        return "`{$res['table']}` t " . ($res['join'] ?? '') . ' ' . ($res['join_extra'] ?? '');
    }

    private static function scopeSql(array $res, array $ctx, array &$p): string
    {
        $sql = '';
        if (!empty($res['scope']) && $ctx['farm'] !== null) {
            $sql .= " AND {$res['scope']} = :__farm";
            $p['__farm'] = $ctx['farm'];
        }
        if (!empty($res['own']) && $ctx['user']['role'] === 'worker') {
            $sql .= " AND ({$res['own']})";
            $p['uid'] = $ctx['user']['id'];
        }
        if (!empty($res['hide'][$ctx['user']['role']])) $sql .= " AND ({$res['hide'][$ctx['user']['role']]})";
        return $sql;
    }

    public static function find(string $name, int $id, array $ctx, bool $protect = false): ?array
    {
        $res = Registry::get($name);
        $p = ['__id' => $id];
        $sql = 'SELECT ' . ($res['select'] ?? 't.*') . ' FROM ' . self::fromClause($res) . ' WHERE t.id = :__id' . self::scopeSql($res, $ctx, $p);
        if ($protect && !empty($res['protect'])) $sql .= " AND ({$res['protect']})";
        $row = Db::one($sql . ' LIMIT 1', $p);
        return $row ? self::castRow($res, $row, $ctx['user']['role']) : null;
    }

    private static function mustFind(string $name, int $id, array $ctx): array
    {
        return self::find($name, $id, $ctx) ?? fail(404, 'Record not found.');
    }

    private static function castRow(array $res, array $row, string $role = ''): array
    {
        foreach ($res['redact'][$role] ?? [] as $hidden) unset($row[$hidden]);
        foreach ($row as $k => $v) {
            if ($v === null) continue;
            $rule = $res['cols'][$k] ?? null;
            if ($k === 'id' || str_ends_with($k, '_id') || $k === 'farm_id') {
                if (is_numeric($v)) $row[$k] = (int) $v;
            } elseif ($rule !== null) {
                $type = Validator::parse($rule)['type'];
                if ($type === 'int' || $type === 'bool') $row[$k] = (int) $v;
                elseif ($type === 'dec') $row[$k] = (float) $v;
            }
        }
        return $row;
    }

    private static function list(string $name, array $res, array $ctx): never
    {
        $p = [];
        $where = ' WHERE 1=1' . self::scopeSql($res, $ctx, $p);

        foreach (($res['filters'] ?? []) as $key => $expr) {
            $param = is_int($key) ? $expr : $key;
            $col = is_int($key) ? "t.$expr" : $expr;
            if (!isset($_GET[$param]) || !is_string($_GET[$param]) || $_GET[$param] === '') continue;
            $vals = array_slice(explode(',', $_GET[$param]), 0, 20);
            $ph = [];
            foreach ($vals as $i => $v) {
                $ph[] = ":f_{$param}_$i";
                $p["f_{$param}_$i"] = $v;
            }
            $where .= " AND $col IN (" . implode(',', $ph) . ')';
        }
        if (!empty($_GET['q']) && is_string($_GET['q']) && !empty($res['search'])) {
            $parts = [];
            foreach ($res['search'] as $i => $col) {
                $parts[] = "$col LIKE :q$i";
                $p["q$i"] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $_GET['q']) . '%';
            }
            $where .= ' AND (' . implode(' OR ', $parts) . ')';
        }
        if (!empty($res['date_col'])) {
            foreach (['from' => '>=', 'to' => '<='] as $key => $cmp) {
                $v = $_GET[$key] ?? '';
                if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                    $where .= " AND DATE({$res['date_col']}) $cmp :d_$key";
                    $p["d_$key"] = $v;
                }
            }
        }

        $from = self::fromClause($res);
        $total = (int) Db::val("SELECT COUNT(*) FROM $from $where", $p);
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $per = min(500, max(1, (int) ($_GET['per_page'] ?? 100)));
        $offset = ($page - 1) * $per;
        $rows = Db::all(
            'SELECT ' . ($res['select'] ?? 't.*') . " FROM $from $where ORDER BY " . ($res['order'] ?? 't.id') . " LIMIT $per OFFSET $offset",
            $p
        );
        reply(array_map(fn($r) => self::castRow($res, $r, $ctx['user']['role']), $rows), 200, ['total' => $total, 'page' => $page, 'per_page' => $per]);
    }

    private static function checkForeignKeys(array $res, array $data, array $ctx): void
    {
        foreach ($res['cols'] as $col => $rule) {
            $r = Validator::parse($rule);
            if ($r['fk'] && isset($data[$col]) && $data[$col] !== null) {
                $c = $ctx;
                if ($r['fk'] === 'farms' && $ctx['user']['role'] !== 'worker') $c['farm'] = $ctx['user']['farm_id'] !== null ? (int) $ctx['user']['farm_id'] : null;
                $fkRes = Registry::get($r['fk']);
                if ($fkRes && !empty($fkRes['own'])) $c['user']['role'] = 'admin';
                if (!self::find($r['fk'], (int) $data[$col], $c)) {
                    fail(422, 'Please correct the highlighted fields.', [$col => 'Selected record does not exist or is not accessible.']);
                }
            }
        }
    }

    private static function create(string $name, array $res, array $ctx): never
    {
        if (!empty($res['own']) && $ctx['user']['role'] === 'worker') fail(403, 'Your role cannot create these records.');
        $input = body();
        if (isset($res['cols']['farm_id'])) $input['farm_id'] = self::writeFarm($ctx, $input);
        $data = Validator::check($res['cols'], $input, false);
        self::checkForeignKeys($res, $data, $ctx);
        $extras = array_intersect_key($input, array_flip($res['extra'] ?? []));

        $id = Db::tx(function () use ($name, $res, &$data, $extras, $ctx) {
            Hooks::before($name, 'create', $data, null, $ctx);
            $id = !empty($res['custom_create'])
                ? Hooks::customCreate($name, $data, $extras, $ctx)
                : Db::insert($res['table'], $data);
            Hooks::after($name, 'create', self::rawRow($res, $id), null, $extras, $ctx);
            return $id;
        });
        $row = self::mustFind($name, $id, $ctx);
        Audit::log('create', $name, (string) $id, self::auditDetails($data), $ctx['user']);
        reply($row, 201);
    }

    private static function update(string $name, array $res, int $id, array $ctx): never
    {
        $existing = self::mustFind($name, $id, $ctx);
        if (!empty($res['protect']) && !self::find($name, $id, $ctx, true)) {
            fail(403, 'This record was generated by the system and cannot be edited directly.');
        }
        $input = body();
        $data = Validator::check($res['cols'], $input, true);
        $extras = array_intersect_key($input, array_flip($res['extra'] ?? []));
        if (!$data && !$extras) fail(400, 'No updatable fields were supplied.');
        if ($ctx['user']['farm_id'] !== null) unset($data['farm_id']);
        self::checkForeignKeys($res, $data, $ctx);

        Db::tx(function () use ($name, $res, $id, &$data, $existing, $extras, $ctx) {
            Hooks::before($name, 'update', $data, $existing, $ctx);
            Db::update($res['table'], $id, $data);
            Hooks::after($name, 'update', self::rawRow($res, $id), $existing, $extras, $ctx);
        });
        Audit::log('update', $name, (string) $id, self::auditDetails($data), $ctx['user']);
        reply(self::mustFind($name, $id, $ctx));
    }

    private static function delete(string $name, array $res, int $id, array $ctx): never
    {
        $existing = self::mustFind($name, $id, $ctx);
        if (!empty($res['protect']) && !self::find($name, $id, $ctx, true)) {
            fail(403, 'This record was generated by the system and cannot be deleted directly.');
        }
        Db::tx(function () use ($name, $res, $id, $existing, $ctx) {
            Db::exec("DELETE FROM `{$res['table']}` WHERE id = ?", [$id]);
            Hooks::deleted($name, $existing, $ctx);
        });
        Audit::log('delete', $name, (string) $id, [], $ctx['user']);
        reply(['id' => $id, 'deleted' => true]);
    }

    private static function rawRow(array $res, int $id): array
    {
        return Db::one("SELECT * FROM `{$res['table']}` WHERE id = ?", [$id]) ?? [];
    }

    private static function auditDetails(array $data): array
    {
        return array_map(fn($v) => is_string($v) && mb_strlen($v) > 80 ? mb_substr($v, 0, 80) . '…' : $v, $data);
    }
}
