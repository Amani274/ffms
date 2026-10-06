<?php

declare(strict_types=1);

final class Env
{
    private static array $vars = [];

    public static function load(string $file): void
    {
        if (!is_file($file)) return;
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
            [$k, $v] = explode('=', $line, 2);
            self::$vars[trim($k)] = trim($v, " \t\"'");
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $env = getenv($key);
        if ($env !== false && $env !== '') return $env;
        return self::$vars[$key] ?? $default;
    }
}

final class ApiError extends RuntimeException
{
    public function __construct(public int $status, string $message, public array $errors = [])
    {
        parent::__construct($message);
    }
}

function fail(int $status, string $message, array $errors = []): never
{
    throw new ApiError($status, $message, $errors);
}

function reply(mixed $data = null, int $status = 200, array $meta = []): never
{
    http_response_code($status);
    $out = ['success' => true, 'data' => $data];
    if ($meta) $out['meta'] = $meta;
    echo json_encode($out, JSON_THROW_ON_ERROR | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

function body(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') return $cache = [];
    try {
        $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        fail(400, 'Request body is not valid JSON.');
    }
    if (!is_array($decoded)) fail(400, 'Request body must be a JSON object.');
    return $cache = $decoded;
}

final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo) return self::$pdo;
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            Env::get('DB_HOST', '127.0.0.1'),
            Env::get('DB_PORT', '3306'),
            Env::get('DB_NAME', 'ffms')
        );
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00', sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'",
        ] + self::sslOptions();
        self::$pdo = new PDO($dsn, Env::get('DB_USER', 'root'), Env::get('DB_PASS', ''), $options);
        return self::$pdo;
    }

    /** TLS for managed databases: set DB_SSL=1 (CA bundle path via DB_SSL_CA, default the system store). */
    public static function sslOptions(): array
    {
        if (Env::get('DB_SSL', '0') !== '1') return [];
        return [PDO::MYSQL_ATTR_SSL_CA => Env::get('DB_SSL_CA', '/etc/ssl/certs/ca-certificates.crt')];
    }

    public static function all(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    public static function val(string $sql, array $params = []): mixed
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function exec(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(',', array_map(fn($c) => "`$c`", $cols)),
            implode(',', array_map(fn($c) => ":$c", $cols))
        );
        self::exec($sql, $data);
        return (int) self::pdo()->lastInsertId();
    }

    public static function update(string $table, int $id, array $data): void
    {
        if (!$data) return;
        $set = implode(',', array_map(fn($c) => "`$c` = :$c", array_keys($data)));
        self::exec("UPDATE `$table` SET $set WHERE id = :__id", $data + ['__id' => $id]);
    }

    public static function tx(callable $fn): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) return $fn();
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}

/**
 * Compact rule validator. Rule syntax: "type[:args][!]|fk:resource|default:value"
 * Types: int, dec:min,max, str:max, text, date, datetime, time, enum:a,b,c, bool, email
 */
final class Validator
{
    public static function parse(string $rule): array
    {
        $parts = explode('|', $rule);
        $typeSpec = array_shift($parts);
        $required = str_ends_with($typeSpec, '!');
        $typeSpec = rtrim($typeSpec, '!');
        [$type, $args] = array_pad(explode(':', $typeSpec, 2), 2, null);
        $out = ['type' => $type, 'args' => $args, 'required' => $required, 'fk' => null, 'default' => null, 'has_default' => false];
        foreach ($parts as $p) {
            if (str_starts_with($p, 'fk:')) $out['fk'] = substr($p, 3);
            if (str_starts_with($p, 'default:')) {
                $out['default'] = substr($p, 8);
                $out['has_default'] = true;
            }
        }
        return $out;
    }

    public static function enumValues(string $rule): array
    {
        $p = self::parse($rule);
        return $p['type'] === 'enum' ? explode(',', (string) $p['args']) : [];
    }

    public static function check(array $cols, array $input, bool $partial): array
    {
        $clean = [];
        $errors = [];
        foreach ($cols as $name => $rule) {
            $r = self::parse($rule);
            $present = array_key_exists($name, $input);
            if (!$present) {
                if ($partial) continue;
                if ($r['has_default']) {
                    $clean[$name] = $r['default'] === 'null' ? null : $r['default'];
                    continue;
                }
                if ($r['required']) $errors[$name] = 'This field is required.';
                continue;
            }
            $value = $input[$name];
            if ($value === '' || $value === null) {
                if ($r['required']) $errors[$name] = 'This field is required.';
                else $clean[$name] = null;
                continue;
            }
            try {
                $clean[$name] = self::cast($r, $value);
            } catch (InvalidArgumentException $e) {
                $errors[$name] = $e->getMessage();
            }
        }
        if ($errors) fail(422, 'Please correct the highlighted fields.', $errors);
        return $clean;
    }

    private static function cast(array $r, mixed $v): mixed
    {
        switch ($r['type']) {
            case 'int':
                if (!is_numeric($v) || (string) (int) $v !== (string) (int) (float) $v) throw new InvalidArgumentException('Must be a whole number.');
                $n = (int) $v;
                if ($r['args'] !== null) {
                    [$min, $max] = array_map('intval', explode(',', $r['args']));
                    if ($n < $min || $n > $max) throw new InvalidArgumentException("Must be between $min and $max.");
                }
                return $n;
            case 'dec':
                if (!is_numeric($v) || !is_finite((float) $v)) throw new InvalidArgumentException('Must be a number.');
                $n = (float) $v;
                if ($r['args'] !== null) {
                    [$min, $max] = array_map('floatval', explode(',', $r['args']));
                    if ($n < $min || $n > $max) throw new InvalidArgumentException("Must be between $min and $max.");
                }
                return $n;
            case 'str':
            case 'text':
                if (!is_string($v) && !is_numeric($v)) throw new InvalidArgumentException('Must be text.');
                $s = trim((string) $v);
                $max = $r['type'] === 'text' ? 65000 : (int) ($r['args'] ?? 255);
                if (mb_strlen($s) > $max) throw new InvalidArgumentException("Must be at most $max characters.");
                return $s;
            case 'date':
                $d = DateTime::createFromFormat('Y-m-d', (string) $v);
                if (!$d || $d->format('Y-m-d') !== (string) $v) throw new InvalidArgumentException('Must be a date (YYYY-MM-DD).');
                return (string) $v;
            case 'datetime':
                $ts = strtotime((string) $v);
                if ($ts === false) throw new InvalidArgumentException('Must be a date and time.');
                return gmdate('Y-m-d H:i:s', $ts);
            case 'time':
                if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', (string) $v)) throw new InvalidArgumentException('Must be a time (HH:MM).');
                return strlen((string) $v) === 5 ? $v . ':00' : (string) $v;
            case 'enum':
                $allowed = explode(',', (string) $r['args']);
                if (!in_array((string) $v, $allowed, true)) throw new InvalidArgumentException('Must be one of: ' . implode(', ', $allowed) . '.');
                return (string) $v;
            case 'bool':
                return filter_var($v, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            case 'email':
                if (!filter_var($v, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Must be a valid email address.');
                return strtolower(trim((string) $v));
        }
        throw new InvalidArgumentException('Unsupported field type.');
    }
}
