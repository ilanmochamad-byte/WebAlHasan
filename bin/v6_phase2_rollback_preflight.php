<?php

declare(strict_types=1);

/**
 * Preflight rollback PRD V6 Fase 2 (migrasi 028). Hanya membaca.
 *
 *   php bin/v6_phase2_rollback_preflight.php
 *
 * Rollback 028 hanya aman bila kesembilan tabel 028 KOSONG, `bsa_pembayaran`
 * (027) belum berisi pembayaran, dan 028 adalah migrasi terakhir. Tarif,
 * penyesuaian, riwayat tagihan, kuitansi, dan pembatalan tidak boleh hilang
 * lewat rollback; bila ada data, pulihkan backup terverifikasi atau buat
 * migrasi korektif. Penjaga yang sama juga ada di berkas rollback SQL.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/app/bootstrap.php';

$db = app_db();
$blokir = 0;
foreach ([...\App\Bsa\TagihanRepository::TABEL, 'bsa_pembayaran'] as $t) {
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
$terakhir = (string) ($db->query('SELECT migration FROM schema_migrations ORDER BY id DESC LIMIT 1')->fetch_assoc()['migration'] ?? '');
if ($terakhir !== '028_v6_fase2_tagihan_bulanan.sql') {
    echo '[blokir] Migrasi terakhir tercatat adalah ' . $terakhir . ', bukan 028: `bin/migrate.php rollback` akan melepas migrasi lain.' . PHP_EOL;
    $blokir++;
}
echo $blokir === 0
    ? "Rollback 028 aman dijalankan: tidak ada data Fase 2 yang akan hilang. Tetap buat backup terverifikasi.\n"
    : "Jangan rollback 028: ada data tagihan/pembayaran Fase 2 atau urutan migrasi tidak sesuai. Pulihkan backup terverifikasi atau buat migrasi korektif.\n";
exit($blokir > 0 ? 1 : 0);
