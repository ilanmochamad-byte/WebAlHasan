<?php

declare(strict_types=1);

use App\Database\Migrator;

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$command = $argv[1] ?? 'status';
$migrator = new Migrator(app_db(), APP_ROOT . '/database/migrations', APP_ROOT . '/database/rollbacks');

if ($command === 'up') {
    $migrations = $migrator->up();
    echo $migrations ? "Diterapkan:\n- " . implode("\n- ", $migrations) . "\n" : "Tidak ada migrasi baru.\n";
    exit(0);
}

if ($command === 'rollback') {
    $migration = $migrator->rollbackLast();
    echo $migration ? "Rollback selesai: {$migration}\n" : "Tidak ada migrasi yang dapat di-rollback.\n";
    exit(0);
}

if ($command === 'status') {
    foreach ($migrator->status() as $item) {
        echo ($item['applied_at'] ? '[diterapkan] ' : '[menunggu]   ') . $item['migration'] . ($item['applied_at'] ? ' @ ' . $item['applied_at'] : '') . "\n";
    }
    exit(0);
}

fwrite(STDERR, "Perintah: php bin/migrate.php [status|up|rollback]\n");
exit(1);

