<?php

declare(strict_types=1);

/**
 * Pembantu SQL uji aplikasi PRD V5 Fase 4: menjalankan satu pernyataan pada
 * DB `test_*_test` (DB_NAME) dan mencetak hasil TSV. Dipakai skrip browser
 * aplikasi untuk memeriksa jumlah baris dan mengubah keadaan fixture
 * (pencabutan relasi/penugasan, kedaluwarsa token). Menolak DB lain.
 *
 *   DB_NAME=test_v5_f4_app_test php tests/v5_phase4_sql.php "SELECT 1"
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

$namaDb = (string) app_config('database.database');
if (!preg_match('/^test_.*_test$/', $namaDb)) {
    fwrite(STDERR, 'Hanya untuk DB test_*_test, bukan ' . $namaDb . PHP_EOL);
    exit(2);
}
$sql = (string) ($argv[1] ?? '');
$hasil = app_db()->query($sql);
if ($hasil === false) {
    fwrite(STDERR, app_db()->error . PHP_EOL);
    exit(1);
}
if ($hasil instanceof mysqli_result) {
    while ($baris = $hasil->fetch_row()) {
        echo implode("\t", array_map(static fn ($v): string => $v === null ? 'NULL' : (string) $v, $baris)), PHP_EOL;
    }
}
