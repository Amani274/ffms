<?php

declare(strict_types=1);

/**
 * Applies pending SQL migrations from database/migrations in filename order.
 * Usage: php bin/migrate.php            (apply pending)
 *        php bin/migrate.php --fresh    (DROP the database and re-apply everything — destroys data)
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../api/src/Core.php';
Env::load(__DIR__ . '/../.env');

$name = Env::get('DB_NAME', 'ffms');
$serverDsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', Env::get('DB_HOST', '127.0.0.1'), Env::get('DB_PORT', '3306'));
try {
    $server = new PDO($serverDsn, Env::get('DB_USER', 'root'), Env::get('DB_PASS', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION] + Db::sslOptions());
} catch (PDOException $e) {
    fwrite(STDERR, "Cannot connect to MySQL: {$e->getMessage()}\nStart MySQL in the XAMPP control panel and check DB_* settings in .env\n");
    exit(1);
}
if (in_array('--fresh', $argv, true)) {
    $server->exec("DROP DATABASE IF EXISTS `$name`");
    echo "Dropped database $name\n";
}
try {
    $server->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch (PDOException $e) {
    echo "Note: could not create database $name ({$e->getMessage()}); assuming it already exists.\n"; // managed hosts often pre-create it
}

$pdo = Db::pdo();
$pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(100) PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
$done = array_column(Db::all('SELECT version FROM schema_migrations'), 'version');

$files = glob(__DIR__ . '/../database/migrations/*.sql') ?: [];
sort($files);
$applied = 0;
foreach ($files as $file) {
    $version = basename($file);
    if (in_array($version, $done, true)) continue;
    echo "Applying $version ... ";
    $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($file));
    foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', (string) $sql))) as $stmt) {
        $pdo->exec($stmt);
    }
    Db::exec('INSERT INTO schema_migrations (version) VALUES (?)', [$version]);
    echo "done\n";
    $applied++;
}
echo $applied ? "Applied $applied migration(s).\n" : "Database is up to date.\n";
