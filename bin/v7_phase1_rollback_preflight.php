<?php

declare(strict_types=1);

/**
 * Preflight rollback PRD V7 Fase 1 (migrasi 030). Hanya membaca.
 *
 *   php bin/v7_phase1_rollback_preflight.php
 *
 * Rollback hanya aman bila 030 adalah migrasi terakhir yang tercatat dan
 * belum ada data V7: pengajuan berasal wali, baris bertahap V7, riwayat
 * penetapan pembimbing, atau klaim idempotensi `izin.wali.*`. Penjaga SQL
 * pada berkas rollback menegakkan aturan yang sama; skrip ini menjelaskannya
 * sebelum operator menjalankan `php bin/migrate.php rollback`.
 */

require_once __DIR__ . '/_v7_phase1.php';

$db = app_db();
$gagal = 0;
$periksa = static function (bool $ok, string $label) use (&$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $label . PHP_EOL;
    if (!$ok) {
        $gagal++;
    }
};
echo '[info] Basis data terpilih: ' . (string) v7f1_nilai($db, 'SELECT DATABASE()') . PHP_EOL;
$periksa(v7f1_check_aktif($db), 'CHECK aktif pada sesi dan default global (MariaDB)');
$terakhir = (string) v7f1_nilai($db, 'SELECT migration FROM schema_migrations ORDER BY id DESC LIMIT 1');
$periksa($terakhir === '030_v7_fase1_pengajuan_wali.sql', 'Migrasi terakhir adalah 030 (' . $terakhir . ')');
if (v7f1_ada_kolom($db, 'izin_pengajuan', 'asal_pengajuan') && v7f1_ada_tabel($db, 'izin_pembimbing_penetapan')) {
    $wali = (int) v7f1_nilai($db, "SELECT COUNT(*) FROM izin_pengajuan WHERE asal_pengajuan = 'Wali' OR wali_id IS NOT NULL
        OR status IN ('" . implode("','", V7F1_STATUS_V7) . "')");
    $penetapan = (int) v7f1_nilai($db, 'SELECT COUNT(*) FROM izin_pembimbing_penetapan');
    $kunci = (int) v7f1_nilai($db, "SELECT COUNT(*) FROM izin_idempotency_keys WHERE operation LIKE 'izin.wali.%'");
    $periksa($wali === 0, 'Tidak ada pengajuan wali/tahap V7 (' . $wali . ')');
    $periksa($penetapan === 0, 'Tidak ada riwayat penetapan pembimbing (' . $penetapan . ')');
    $periksa($kunci === 0, 'Tidak ada klaim idempotensi izin.wali.* (' . $kunci . ')');
} else {
    $periksa(false, 'Skema 030 tidak lengkap; periksa status migrasi sebelum rollback');
}
echo $gagal === 0
    ? "Rollback 030 aman dijalankan sesudah backup terverifikasi.\n"
    : "Rollback 030 DITOLAK. Data V7 tidak boleh kehilangan asal/identitas wali; pulihkan dari backup atau buat migrasi korektif.\n";
exit($gagal > 0 ? 1 : 0);
