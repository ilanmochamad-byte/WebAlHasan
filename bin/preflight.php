<?php

declare(strict_types=1);

use App\Database\BackupWriter;

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$base = APP_ROOT . '/storage/backups/' . date('Ymd_His');
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--output=')) {
        $base = rtrim(substr($argument, 9), '/');
    }
}

if (!is_dir($base) && !mkdir($base, 0700, true) && !is_dir($base)) {
    throw new RuntimeException('Direktori output tidak dapat dibuat: ' . $base);
}

$writer = new BackupWriter(app_db());
$counts = $writer->write($base . '/database.sql');
$duplicates = $writer->duplicateReport();
$manifest = [
    'created_at' => date(DATE_ATOM),
    'database' => app_config('database.database'),
    'backup_file' => 'database.sql',
    'row_counts' => $counts,
    'duplicates' => $duplicates,
];
file_put_contents($base . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$report = "# Laporan Pra-Migrasi\n\n";
$report .= '- Waktu: ' . $manifest['created_at'] . "\n";
$report .= '- Database: `' . $manifest['database'] . "`\n";
$report .= "- Backup: `database.sql`\n\n## Jumlah baris\n\n| Tabel | Jumlah |\n|---|---:|\n";
foreach ($counts as $table => $count) {
    $report .= '| `' . $table . '` | ' . $count . " |\n";
}
$report .= "\n## Duplikasi kunci bisnis\n";
foreach ($duplicates as $key => $rows) {
    $report .= "\n### `{$key}`\n\n";
    if ($rows === []) {
        $report .= "Tidak ditemukan duplikasi.\n";
        continue;
    }
    $report .= "```json\n" . json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n```\n";
}
file_put_contents($base . '/report.md', $report);

echo "Backup dan laporan dibuat di {$base}\n";

