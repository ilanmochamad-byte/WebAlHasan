<?php

declare(strict_types=1);

/**
 * Syarat rollback migrasi 026 (PRD V5 Fase 3). Hanya membaca.
 *
 *   php bin/v5_phase3_rollback_preflight.php
 *
 * Rollback melepas empat tabel ringkasan wali. Bila salah satunya berisi
 * baris, atau `notifikasi_outbox` sudah memuat notifikasi V5 (yang akan
 * menunjuk ringkasan yang hilang), rollback DIBLOKIR: snapshot yang pernah
 * dilihat wali beserta jejak penerbitannya tidak boleh hilang. Pulihkan backup
 * terverifikasi atau buat migrasi korektif. Tabel 024–025 tidak dilepas.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/app/bootstrap.php';

$db = app_db();
$blokir = 0;
foreach (['kesehatan_ringkasan_penerima', 'kesehatan_ringkasan_peristiwa', 'kesehatan_ringkasan_versi', 'kesehatan_ringkasan'] as $t) {
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
$outbox = (int) $db->query("SELECT COUNT(*) AS n FROM notifikasi_outbox WHERE event_type LIKE 'v5\\_kesehatan\\_%'")->fetch_assoc()['n'];
echo ($outbox === 0 ? '[lulus] ' : '[blokir] ') . 'notifikasi_outbox V5 kesehatan = ' . $outbox . PHP_EOL;
if ($outbox !== 0) {
    $blokir++;
}
echo $blokir === 0
    ? "Rollback 026 aman dijalankan: tidak ada ringkasan wali atau notifikasinya yang akan hilang. Tetap buat backup terverifikasi.\n"
    : "Jangan rollback 026: ada ringkasan wali atau notifikasinya. Pulihkan backup terverifikasi atau buat migrasi korektif.\n";
exit($blokir > 0 ? 1 : 0);
