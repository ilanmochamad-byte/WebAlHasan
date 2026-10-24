<?php

declare(strict_types=1);

/**
 * Syarat rollback migrasi 024 (PRD V5 Fase 1). Hanya membaca.
 *
 *   php bin/v5_phase1_rollback_preflight.php
 *
 * Rollback melepas tabel kesehatan. Bila salah satunya berisi baris
 * (penugasan, kunjungan, versi, idempotensi, atau log akses), rollback
 * DIBLOKIR: catatan klinis dan jejak aksesnya tidak boleh hilang. Pulihkan
 * backup terverifikasi atau buat migrasi korektif.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/app/bootstrap.php';

$db = app_db();
$blokir = 0;
foreach (['kesehatan_akses_log', 'kesehatan_idempotensi', 'kesehatan_kunjungan_versi', 'kesehatan_kunjungan', 'kesehatan_assignments'] as $t) {
    $s = $db->prepare('SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $s->bind_param('s', $t);
    $s->execute();
    $ada = (int) $s->get_result()->fetch_assoc()['n'] === 1;
    $s->close();
    if (!$ada) {
        echo '[info] ' . $t . ' tidak ada' . PHP_EOL;
        continue;
    }
    $n = (int) $db->query('SELECT COUNT(*) AS n FROM `' . $t . '`')->fetch_assoc()['n'];
    echo ($n === 0 ? '[lulus] ' : '[blokir] ') . $t . ' = ' . $n . PHP_EOL;
    if ($n !== 0) {
        $blokir++;
    }
}
echo $blokir === 0
    ? "Rollback 024 aman dijalankan: tidak ada data kesehatan yang akan hilang. Tetap buat backup terverifikasi.\n"
    : "Jangan rollback 024: ada data kesehatan. Pulihkan backup terverifikasi atau buat migrasi korektif.\n";
exit($blokir > 0 ? 1 : 0);
