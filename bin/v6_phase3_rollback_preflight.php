<?php

declare(strict_types=1);

/**
 * Preflight rollback PRD V6 Fase 3 (migrasi 029). Hanya membaca.
 *
 *   php bin/v6_phase3_rollback_preflight.php
 *
 * Rollback 029 hanya aman bila tidak ada data Fase 3: `bsa_setoran_transfer`
 * kosong, ledger 027 tanpa baris Belanja/Pembalik/PembayaranTagihan/Internal,
 * `bsa_pembayaran` tanpa sumber Tabungan, dan 029 adalah migrasi terakhir.
 * Bila ada data, pulihkan backup terverifikasi atau buat migrasi korektif.
 * Penjaga yang sama juga ada di berkas rollback SQL.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/app/bootstrap.php';

$db = app_db();
$blokir = 0;
$saringan = [
    'bsa_setoran_transfer' => '',
    'bsa_tabungan_transaksi' => " WHERE jenis IN ('Belanja', 'Pembalik', 'PembayaranTagihan') OR metode = 'Internal'",
    'bsa_pembayaran' => " WHERE sumber = 'Tabungan'",
];
foreach ($saringan as $t => $where) {
    $s = $db->prepare('SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $s->bind_param('s', $t);
    $s->execute();
    $ada = (int) $s->get_result()->fetch_assoc()['n'] === 1;
    $s->close();
    if (!$ada) {
        echo '[info] ' . $t . ' tidak ada' . PHP_EOL;
        continue;
    }
    $n = (int) $db->query('SELECT COUNT(*) AS n FROM `' . $t . '`' . $where)->fetch_assoc()['n'];
    echo ($n === 0 ? '[lulus] ' : '[blokir] ') . $t . ($where === '' ? '' : ' (data Fase 3)') . ' = ' . $n . PHP_EOL;
    if ($n !== 0) {
        $blokir++;
    }
}
$terakhir = (string) ($db->query('SELECT migration FROM schema_migrations ORDER BY id DESC LIMIT 1')->fetch_assoc()['migration'] ?? '');
if ($terakhir !== '029_v6_fase3_tabungan.sql') {
    echo '[blokir] Migrasi terakhir tercatat adalah ' . $terakhir . ', bukan 029: `bin/migrate.php rollback` akan melepas migrasi lain.' . PHP_EOL;
    $blokir++;
}
echo $blokir === 0
    ? "Rollback 029 aman dijalankan: tidak ada data Fase 3 yang akan kehilangan konteks. Tetap buat backup terverifikasi.\n"
    : "Jangan rollback 029: ada data tabungan Fase 3 atau urutan migrasi tidak sesuai. Pulihkan backup terverifikasi atau buat migrasi korektif.\n";
exit($blokir > 0 ? 1 : 0);
