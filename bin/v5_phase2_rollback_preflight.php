<?php

declare(strict_types=1);

/**
 * Syarat rollback migrasi 025 (PRD V5 Fase 2). Hanya membaca.
 *
 *   php bin/v5_phase2_rollback_preflight.php
 *
 * Rollback melepas tabel obat, rujukan, tindak lanjut, dan snapshot rincian
 * versi. Bila salah satunya berisi baris, rollback DIBLOKIR: rincian klinis
 * dan riwayat versinya tidak boleh hilang. Pulihkan backup terverifikasi
 * atau buat migrasi korektif. Tabel 024 tidak dilepas oleh rollback ini.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/app/bootstrap.php';

$db = app_db();
$blokir = 0;
foreach (['kesehatan_versi_rincian', 'kesehatan_tindak_lanjut', 'kesehatan_rujukan', 'kesehatan_obat'] as $t) {
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
    ? "Rollback 025 aman dijalankan: tidak ada rincian kesehatan yang akan hilang. Tetap buat backup terverifikasi.\n"
    : "Jangan rollback 025: ada rincian kesehatan. Pulihkan backup terverifikasi atau buat migrasi korektif.\n";
exit($blokir > 0 ? 1 : 0);
