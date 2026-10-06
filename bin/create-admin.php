<?php

declare(strict_types=1);

/**
 * CLI-only: create (or reset the password of) an administrator account without the demo data.
 *   set ADMIN_PASSWORD=<strong password>
 *   php bin/create-admin.php "Full Name" you@example.com
 * The password comes from the environment so it never appears in shell history or process lists.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../api/src/Core.php';
require_once __DIR__ . '/../api/src/Auth.php';
Env::load(__DIR__ . '/../.env');

[$script, $name, $email] = array_pad($argv, 3, '');
$password = (string) getenv('ADMIN_PASSWORD');
if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
    fwrite(STDERR, "Usage: set ADMIN_PASSWORD=...  &&  php bin/create-admin.php \"Full Name\" email@example.com\n");
    exit(1);
}
try {
    Auth::checkPasswordStrength($password);
} catch (ApiError $e) {
    fwrite(STDERR, "ADMIN_PASSWORD is too weak: at least 10 characters with letters and numbers.\n");
    exit(1);
}
$email = strtolower($email);
$hash = Auth::hashPassword($password);
$existing = Db::val('SELECT id FROM users WHERE email = ?', [$email]);
if ($existing) {
    Db::update('users', (int) $existing, ['name' => $name, 'role' => 'admin', 'password_hash' => $hash, 'active' => 1, 'farm_id' => null]);
    echo "Updated existing user $email as administrator.\n";
} else {
    Db::insert('users', ['name' => $name, 'email' => $email, 'password_hash' => $hash, 'role' => 'admin', 'farm_id' => null, 'active' => 1]);
    echo "Created administrator $email.\n";
}
