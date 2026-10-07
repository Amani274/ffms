<?php

declare(strict_types=1);

foreach (['Core', 'Auth', 'Registry', 'Crud', 'Hooks', 'Documents', 'Weather', 'Predict', 'Alerts', 'Dashboard', 'Reports', 'Admin'] as $f) {
    require_once __DIR__ . "/src/$f.php";
}
Env::load(dirname(__DIR__) . '/.env');

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

set_exception_handler(static function (Throwable $e): void {
    if ($e instanceof ApiError) {
        http_response_code($e->status);
        echo json_encode(['success' => false, 'message' => $e->getMessage(), 'errors' => (object) $e->errors]);
        return;
    }
    if ($e instanceof PDOException && (string) $e->getCode() === '23000') {
        http_response_code(409);
        $dup = str_contains($e->getMessage(), 'Duplicate entry');
        echo json_encode(['success' => false, 'message' => $dup
            ? 'A record with the same unique value already exists.'
            : 'This record is referenced by other records (or references a missing one), so the change was blocked.']);
        return;
    }
    error_log(sprintf('FFMS %s: %s at %s:%d', get_class($e), $e->getMessage(), basename($e->getFile()), $e->getLine()));
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Something went wrong on the server. Please try again or contact the administrator.']);
});

// CORS: same-origin needs nothing; the Vite dev server origin is allow-listed.
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '') {
    $allowed = array_filter(array_map('trim', explode(',', (string) Env::get('CORS_ORIGINS', 'http://localhost:5173,http://127.0.0.1:5173'))));
    if (in_array($origin, $allowed, true)) {
        header("Access-Control-Allow-Origin: $origin");
        header('Vary: Origin');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    }
}
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Route path relative to this directory
$uriPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$base = rtrim(str_replace('\\', '/', dirname((string) $_SERVER['SCRIPT_NAME'])), '/');
$path = trim(substr($uriPath, strlen($base)), '/');
if (str_starts_with($path, 'index.php')) $path = trim(substr($path, 9), '/');
$seg = $path === '' ? [] : array_map('urldecode', explode('/', $path));
$first = $seg[0] ?? '';

Auth::boot();

// ---- public routes
if ($first === 'health') {
    Db::val('SELECT 1');
    reply(['status' => 'ok', 'time' => gmdate('c')]);
}
if ($first === 'auth' && ($seg[1] ?? '') === 'login' && $method === 'POST') {
    $in = body();
    $user = Auth::login((string) ($in['email'] ?? ''), (string) ($in['password'] ?? ''));
    reply(['user' => $user, 'csrf' => Auth::csrfToken()]);
}
if ($first === 'auth' && ($seg[1] ?? '') === 'logout' && $method === 'POST') {
    Auth::logout();
    reply(['logged_out' => true]);
}
if ($first === 'auth' && ($seg[1] ?? '') === 'me') {
    $u = Auth::require();
    reply(['user' => $u, 'csrf' => Auth::csrfToken()]);
}

// ---- authenticated routes
$user = Auth::require();

switch ($first) {
    case 'auth':
        if (($seg[1] ?? '') === 'change-password' && $method === 'POST') Admin::changePassword();
        break;
    case 'meta':
        Admin::meta();
    case 'options':
        if (isset($seg[1])) Admin::options($seg[1]);
        break;
    case 'dashboard':
        reply(Dashboard::summary(Crud::context()));
    case 'weather':
        $ctx = Crud::context();
        $farm = $ctx['farm'] ?? (int) Db::val('SELECT id FROM farms ORDER BY id LIMIT 1');
        if (($seg[1] ?? '') === 'refresh' && $method === 'POST') {
            Auth::verifyCsrf();
            Weather::forecast($farm, true);
        }
        reply(Weather::overview($farm));
    case 'predictions':
        $ctx = Crud::context();
        $sub = $seg[1] ?? '';
        $farm = $ctx['farm'] ?? (int) Db::val('SELECT id FROM farms ORDER BY id LIMIT 1');
        if (in_array($sub, ['yield', 'price'], true) && $user['role'] === 'worker') fail(403, 'Your role cannot view yield and price forecasts.');
        if ($sub === 'yield' && isset($seg[2])) {
            $y = Predict::yield((int) $seg[2]);
            if (!$y) fail(404, 'Planting not found.');
            reply($y);
        }
        if ($sub === 'yield') reply(Predict::yieldAll($ctx['farm']));
        if ($sub === 'price' && isset($seg[2])) reply(Predict::priceForecast((int) $seg[2]));
        if ($sub === 'price') reply(Predict::priceOverview($ctx['farm']));
        if ($sub === 'recommendations') reply(Predict::recommendations($farm));
        break;
    case 'reports':
        $ctx = Crud::context();
        if (!isset($seg[1])) {
            $out = [];
            foreach (Reports::catalog() as $k => [$title, $roles, $desc]) if (in_array($user['role'], $roles, true)) $out[] = ['key' => $k, 'title' => $title, 'description' => $desc];
            reply($out);
        }
        $re = '/^\d{4}-\d{2}-\d{2}$/';
        $from = preg_match($re, (string) ($_GET['from'] ?? '')) ? $_GET['from'] : gmdate('Y') . '-01-01';
        $to = preg_match($re, (string) ($_GET['to'] ?? '')) ? $_GET['to'] : gmdate('Y-m-d');
        $report = Reports::run($seg[1], $ctx, $from, $to);
        reply($report);
    case 'sales':
        if (($seg[1] ?? '') === 'orders' && isset($seg[2], $seg[3]) && $seg[3] === 'invoice' && $method === 'POST') Actions::invoiceOrder((int) $seg[2]);
        break;
    case 'purchasing':
        if (isset($seg[1], $seg[2]) && $method === 'POST') Actions::purchasing((int) $seg[1], $seg[2]);
        break;
    case 'payroll':
        if (($seg[1] ?? '') === 'generate' && $method === 'POST') Actions::generatePayroll();
        break;
    case 'users':
        Admin::users($method, $seg[1] ?? null);
    case 'backups':
        Admin::backups($method, $seg[1] ?? null);
}

if ($first !== '' && Registry::get($first) !== null && count($seg) <= 2) {
    Crud::handle($first, $seg[1] ?? null, $method);
}
fail(404, 'Endpoint not found.');
