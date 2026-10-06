<?php

declare(strict_types=1);

/**
 * Writes a timestamped SQL dump to storage/backups and prunes dumps older than BACKUP_KEEP_DAYS (default 30).
 * Schedule nightly with Windows Task Scheduler:  C:\xampp\php\php.exe C:\xampp\htdocs\ffms\bin\backup.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../api/src/Core.php';
require_once __DIR__ . '/../api/src/Admin.php';
Env::load(__DIR__ . '/../.env');

$file = Backup::create();
echo 'Backup written: ' . basename($file) . ' (' . number_format(filesize($file) / 1024, 0) . " KB)\n";

$keep = (int) Env::get('BACKUP_KEEP_DAYS', '30');
foreach (glob(dirname($file) . '/ffms-*.sql') ?: [] as $old) {
    if (filemtime($old) < time() - $keep * 86400) {
        unlink($old);
        echo 'Pruned ' . basename($old) . "\n";
    }
}
