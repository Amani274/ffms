<?php

declare(strict_types=1);

final class Auth
{
    public const ROLES = ['admin', 'owner', 'manager', 'agronomist', 'worker', 'accountant'];
    private static ?array $user = null;

    public static function boot(): void
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        session_name('ffms_sid');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        session_start();

        $idle = ((int) Env::get('SESSION_IDLE_MIN', '240')) * 60;
        if (isset($_SESSION['uid'], $_SESSION['last_seen']) && time() - $_SESSION['last_seen'] > $idle) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        if (isset($_SESSION['uid'])) $_SESSION['last_seen'] = time();
    }

    public static function user(): ?array
    {
        if (self::$user !== null) return self::$user;
        $uid = $_SESSION['uid'] ?? null;
        if (!$uid) return null;
        $u = Db::one('SELECT id, name, email, role, farm_id, phone, active FROM users WHERE id = ?', [$uid]);
        if (!$u || !$u['active']) {
            $_SESSION = [];
            return null;
        }
        return self::$user = $u;
    }

    public static function require(): array
    {
        $u = self::user();
        if (!$u) fail(401, 'Authentication required.');
        return $u;
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
        return $_SESSION['csrf'];
    }

    public static function verifyCsrf(): void
    {
        $sent = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if ($sent === '' || !hash_equals((string) ($_SESSION['csrf'] ?? ''), $sent)) {
            fail(403, 'Missing or invalid CSRF token. Please refresh and sign in again.');
        }
    }

    public static function login(string $email, string $password): array
    {
        $email = strtolower(trim($email));
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $failures = (int) Db::val(
            'SELECT COUNT(*) FROM login_attempts WHERE email = ? AND success = 0 AND created_at > (UTC_TIMESTAMP() - INTERVAL 15 MINUTE)',
            [$email]
        );
        if ($failures >= 8) fail(429, 'Too many failed attempts. Try again in 15 minutes.');

        $u = Db::one('SELECT * FROM users WHERE email = ?', [$email]);
        $ok = $u && $u['active'] && password_verify($password, $u['password_hash']);
        Db::insert('login_attempts', ['email' => $email, 'ip_address' => $ip, 'success' => $ok ? 1 : 0, 'created_at' => gmdate('Y-m-d H:i:s')]);
        if (!$ok) {
            Audit::log('login_failed', 'auth', null, ['email' => $email], null);
            fail(401, 'Invalid email or password.');
        }
        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $u['id'];
        $_SESSION['last_seen'] = time();
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        Db::exec('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?', [$u['id']]);
        self::$user = null;
        $user = self::user();
        Audit::log('login', 'auth', (string) $u['id'], [], $user);
        return $user;
    }

    public static function logout(): void
    {
        $u = self::user();
        if ($u) Audit::log('logout', 'auth', (string) $u['id'], [], $u);
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
        }
        session_destroy();
        self::$user = null;
    }

    public static function hashPassword(string $plain): string
    {
        return password_hash($plain, PASSWORD_BCRYPT, ['cost' => 11]);
    }

    public static function checkPasswordStrength(string $plain): void
    {
        if (strlen($plain) < 10 || !preg_match('/[A-Za-z]/', $plain) || !preg_match('/\d/', $plain)) {
            fail(422, 'Password must be at least 10 characters and include letters and numbers.', ['password' => 'Too weak.']);
        }
    }
}

final class Audit
{
    public static function log(string $action, string $entity, ?string $entityId, array $details = [], ?array $user = null): void
    {
        $user ??= Auth::user();
        try {
            Db::insert('audit_logs', [
                'user_id' => $user['id'] ?? null,
                'user_name' => $user['name'] ?? null,
                'action' => $action,
                'entity' => $entity,
                'entity_id' => $entityId,
                'details' => $details ? json_encode($details, JSON_PARTIAL_OUTPUT_ON_ERROR) : null,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            error_log('FFMS audit failure: ' . $e->getMessage());
        }
    }
}
