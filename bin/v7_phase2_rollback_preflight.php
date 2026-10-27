<?php

declare(strict_types=1);

/**
 * Preflight rollback PRD V7 Fase 2 (migrasi 031). Hanya membaca.
 *
 *   php bin/v7_phase2_rollback_preflight.php
 *
 * Rollback hanya aman bila 031 migrasi terakhir dan belum ada data Fase 2:
 * baris tinjauan/perbaikan, klaim idempotensi tinjauan/perbaikan/batal, atau
 * pengajuan wali yang sudah melewati tahap Fase 1. Penjaga SQL pada berkas
 * rollback menegakkan aturan yang sama.
 */

require_once __DIR__ . '/_v7_phase2.php';

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
$periksa($terakhir === V7F2_MIGRASI, 'Migrasi terakhir adalah 031 (' . $terakhir . ')');
if (v7f1_ada_tabel($db, 'izin_pembimbing_tinjauan') && v7f1_ada_tabel($db, 'izin_wali_perbaikan')) {
    $tinjauan = (int) v7f1_nilai($db, 'SELECT COUNT(*) FROM izin_pembimbing_tinjauan');
    $perbaikan = (int) v7f1_nilai($db, 'SELECT COUNT(*) FROM izin_wali_perbaikan');
    $kunci = (int) v7f1_nilai($db, 'SELECT COUNT(*) FROM izin_idempotency_keys WHERE operation IN (' . v7f2_in(V7F2_OPERASI) . ')');
    $lanjut = (int) v7f1_nilai($db, "SELECT COUNT(*) FROM izin_pengajuan WHERE asal_pengajuan = 'Wali' AND status NOT IN ('Menunggu Pembimbing', 'Perlu Penetapan Pembimbing')");
    $periksa($tinjauan === 0, 'Tidak ada tinjauan pembimbing (' . $tinjauan . ')');
    $periksa($perbaikan === 0, 'Tidak ada perbaikan wali (' . $perbaikan . ')');
    $periksa($kunci === 0, 'Tidak ada klaim idempotensi tinjauan/perbaikan/batal (' . $kunci . ')');
    $periksa($lanjut === 0, 'Tidak ada pengajuan wali di luar tahap Fase 1 (' . $lanjut . ')');
} else {
    $periksa(false, 'Skema 031 tidak lengkap; periksa status migrasi sebelum rollback');
}
echo $gagal === 0
    ? "Rollback 031 aman dijalankan sesudah backup terverifikasi.\n"
    : "Rollback 031 DITOLAK. Jejak tinjauan/perbaikan/pembatalan tidak boleh hilang; pulihkan dari backup atau buat migrasi korektif.\n";
exit($gagal > 0 ? 1 : 0);
