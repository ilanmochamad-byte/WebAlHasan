<?php

declare(strict_types=1);

/**
 * Uji migrasi 033 dan rollback berpenjaga (Fase 3 penugasan lintas guru/pengurus,
 * penugasan Keamanan) pada salinan DB tersendiri:
 *
 *   PKF3_DB=test_pk_f3_mig_test bash tests/penugasan_keamanan_f3_db.sh
 *   PKF3_DB=test_pk_f3_mig_test php tests/penugasan_keamanan_f3_migration.php
 *
 * Uji ini lebih dahulu melepas 033 yang masih kosong lewat Migrator (bagian
 * M-5 rollback pada keadaan kosong), lalu:
 *
 *   M-1 sebelum 033: kode berjalan; Keamanan "belum dipasang", tanpa capability/menu
 *   M-2 naik: tabel terpasang dan tercatat; tabel lain tidak berubah (jumlah + sidik)
 *   M-3 menjalankan ulang berkas migrasi aman
 *   M-4 kunci asing dan CHECK rentang tanggal ditegakkan basis data
 *   M-5 rollback saat kosong berhasil; naik lagi
 *   M-6 rollback DITOLAK sesudah ada penugasan (juga yang sudah nonaktif); data tetap
 */

$root = dirname(__DIR__);
$dbName = getenv('PKF3_DB') ?: 'test_pk_f3_mig_test';
if (!preg_match('/^test_.*_test$/', $dbName)) {
    fwrite(STDERR, "DB uji harus bernama test_*_test.\n");
    exit(2);
}
putenv('DB_NAME=' . $dbName);
require_once $root . '/app/bootstrap.php';

use App\Database\Migrator;
use App\Database\Schema;
use App\Keamanan\KeamananAkses;
use App\Penugasan\PenugasanJenis;
use App\Ui\Navigation;

$lulus = 0;
$gagal = [];
$assert = static function (bool $ok, string $pesan) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    $ok ? $lulus++ : $gagal[] = $pesan;
};
$db = app_db();
$satu = static function (string $sql) use ($db): mixed {
    $r = $db->query($sql);
    if ($r === false) {
        throw new RuntimeException($db->error . ' :: ' . $sql);
    }

    return $r->fetch_row()[0] ?? null;
};
$migrator = new Migrator($db, $root . '/database/migrations', $root . '/database/rollbacks');
$nama = '033_penugasan_keamanan_fase3_keamanan.sql';
$tabelAda = static function () use ($satu): bool {
    Schema::forget();

    return (int) $satu("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'keamanan_assignments'") === 1;
};
$tercatat = static fn (): bool => (int) $satu("SELECT COUNT(*) FROM schema_migrations WHERE migration = '$nama'") === 1;
// Sidik tabel yang tidak boleh berubah karena 033 (master, akun, penugasan lain, izin, pembinaan).
$sidik = static function () use ($satu): string {
    $bagian = [
        (string) $satu("SELECT CONCAT(COUNT(*), ':', COALESCE(SUM(CRC32(CONCAT_WS('|', id, nama, COALESCE(guru_id, '~NULL~'), is_active))), 0)) FROM pengurus"),
        (string) $satu("SELECT CONCAT(COUNT(*), ':', COALESCE(SUM(CRC32(CONCAT_WS('|', id, username, COALESCE(guru_id, '~NULL~'), COALESCE(pengurus_id, '~NULL~'), is_active))), 0)) FROM users"),
        (string) $satu("SELECT CONCAT(COUNT(*), ':', COALESCE(SUM(CRC32(CONCAT_WS('|', user_id, role_id))), 0)) FROM user_roles"),
        (string) $satu("SELECT CONCAT(COUNT(*), ':', COALESCE(SUM(CRC32(CONCAT_WS('|', id, status, version))), 0)) FROM izin_pengajuan"),
        (string) $satu("SELECT CONCAT(COUNT(*), ':', COALESCE(SUM(CRC32(CONCAT_WS('|', id, status, version))), 0)) FROM v3_pelanggaran"),
        (string) $satu("SELECT CONCAT(COUNT(*), ':', COALESCE(SUM(CRC32(CONCAT_WS('|', id, kerahasiaan, status, version))), 0)) FROM v3_konseling_kasus"),
        (string) $satu('SELECT COUNT(*) FROM audit_logs'),
    ];
    foreach (PenugasanJenis::semua() as $jenis => $definisi) {
        if ($jenis === PenugasanJenis::KEAMANAN) {
            continue;
        }
        $bagian[] = (string) $satu('SELECT CONCAT(COUNT(*), ":", COALESCE(SUM(CRC32(CONCAT_WS("|", id, ' . $definisi['subjek_kolom'] . ', is_active))), 0)) FROM ' . $definisi['tabel']);
    }

    return implode(' ', $bagian);
};
$admin = (int) $satu("SELECT id FROM users WHERE username = 'sbx_admin'");
$tahun = (int) $satu("SELECT id FROM tahun_ajaran WHERE status = 'Aktif' AND archived_at IS NULL LIMIT 1");
$k = (int) $satu("SELECT id FROM users WHERE username = 'sbx_pengurus_biasa'");
$kPengurus = (int) $satu("SELECT pengurus_id FROM users WHERE id = $k");
$akunK = ['id' => $k, 'roles' => ['pengurus'], 'guru_id' => null];
$fitur = static function (int $userId): array {
    capabilities()->forget($userId);

    return capabilities()->featureList(['id' => $userId, 'roles' => [], 'guru_id' => null]);
};
$input = ['pengurus_id' => (string) $kPengurus, 'tahun_ajaran_id' => (string) $tahun, 'tanggal_mulai' => date('Y-m-d')];

// --- Penyiapan: 033 terakhir dan kosong; dilepas (rollback saat kosong) -----------------
$assert($satu('SELECT migration FROM schema_migrations ORDER BY id DESC LIMIT 1') === $nama && (int) $satu('SELECT COUNT(*) FROM keamanan_assignments') === 0,
    'M-0 salinan berada pada 033 dengan tabel kosong');
$turun = $migrator->rollbackLast();
$assert($turun === $nama && !$tabelAda() && !$tercatat(), 'M-5a rollback 033 saat kosong berhasil; tabel dan catatan dilepas');

// --- M-1 sebelum 033 ------------------------------------------------------------------
$sidikAwal = $sidik();
$fiturAwal = $fitur($k);
$layanan = new App\Penugasan\PenugasanService(new App\Penugasan\PenugasanRepository($db), audit_logger(), capabilities());
$assert(!$layanan->skemaTersedia(PenugasanJenis::KEAMANAN) && $layanan->daftar(PenugasanJenis::KEAMANAN, ['status' => 'aktif'], 1)['total'] === 0,
    'M-1 pusat penugasan: Keamanan "belum dipasang", daftar kosong tanpa galat');
$assert(!in_array(PenugasanJenis::CAP_KEAMANAN_PANTAU, $fiturAwal, true) && !KeamananAkses::sah(capabilities(), $akunK),
    'M-1 tanpa tabel: tidak ada capability Keamanan');
$menu = Navigation::build($akunK + ['id' => $k], capabilities()->forUser($akunK), 0, [], 'pengurus');
$assert(!str_contains((string) json_encode($menu), 'keamanan_izin'), 'M-1 peta menu pengurus tanpa grup Keamanan');
$assert(count(izin_laporan_service()->laporan($akunK, [])['items']) >= 0, 'M-1 laporan izin lain tetap berjalan');

// --- M-2 naik --------------------------------------------------------------------------
$diterapkan = $migrator->up();
$assert($diterapkan === [$nama] && $tabelAda() && $tercatat(), 'M-2 migrasi 033 diterapkan dan tercatat');
$assert((int) $satu('SELECT COUNT(*) FROM keamanan_assignments') === 0 && $sidik() === $sidikAwal, 'M-2 tabel baru kosong; seluruh tabel lain tidak berubah (sidik sama)');
$assert($fitur($k) === $fiturAwal, 'M-2 hak akun sama sebelum dan sesudah migrasi');
$fk = (int) $satu("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'keamanan_assignments'");
$assert($fk === 5, 'M-2 lima kunci asing terpasang (pengurus, tahun, pembuat, pengubah, pengakhir)');

// --- M-3 jalankan ulang ------------------------------------------------------------------
$db->multi_query((string) file_get_contents($root . '/database/migrations/' . $nama));
do {
    if ($r = $db->store_result()) {
        $r->free();
    }
} while ($db->more_results() && $db->next_result());
$assert($db->errno === 0 && $tabelAda() && (int) $satu("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'keamanan_assignments' AND CONSTRAINT_TYPE = 'FOREIGN KEY'") === 5,
    'M-3 menjalankan ulang 033 aman: tanpa galat, tanpa batasan ganda');

// --- M-4 FK dan CHECK -----------------------------------------------------------------------
$db->query("INSERT INTO keamanan_assignments (pengurus_id, tahun_ajaran_id, tanggal_mulai) VALUES (2147483000, $tahun, CURDATE())");
$assert($db->errno === 1452, 'M-4 kunci asing menolak pengurus yang tidak ada (errno ' . $db->errno . ')');
$db->query("INSERT INTO keamanan_assignments (pengurus_id, tahun_ajaran_id, tanggal_mulai, tanggal_selesai) VALUES ($kPengurus, $tahun, CURDATE(), CURDATE() - INTERVAL 1 DAY)");
$assert(in_array($db->errno, [4025, 3819], true), 'M-4 CHECK menolak tanggal selesai sebelum mulai (errno ' . $db->errno . ')');
$assert((int) $satu('SELECT COUNT(*) FROM keamanan_assignments') === 0, 'M-4 tidak ada baris tersisa dari percobaan yang ditolak');

// --- M-5 rollback kosong lalu naik lagi -------------------------------------------------------
$turun = $migrator->rollbackLast();
$assert($turun === $nama && !$tabelAda() && $sidik() === $sidikAwal, 'M-5b rollback kosong berhasil; data lain utuh');
$migrator->up();
$assert($tabelAda() && $tercatat(), 'M-5c naik lagi sesudah rollback berhasil');

// --- M-6 rollback ditolak sesudah ada penugasan ----------------------------------------------
$id = penugasan_service()->buat(PenugasanJenis::KEAMANAN, $input, $admin);
$assert($id > 0 && KeamananAkses::sah(capabilities(), $akunK), 'M-6 penyiapan: penugasan Keamanan lewat layanan, hak aktif');
penugasan_service()->nonaktifkan(PenugasanJenis::KEAMANAN, $id, 'PKF3 uji migrasi', $admin);
$sidikIsi = $sidik() . '|' . (string) $satu('SELECT CONCAT(COUNT(*), ":", SUM(is_active)) FROM keamanan_assignments');
$ditolak = null;
try {
    $migrator->rollbackLast();
} catch (RuntimeException $e) {
    $ditolak = $e->getMessage();
}
$db = app_db();
$assert($ditolak !== null && str_contains($ditolak, $nama), 'M-6 rollback ditolak penjaga data walau penugasan sudah nonaktif (' . ($ditolak ?? 'tidak ditolak') . ')');
$assert($tabelAda() && $tercatat() && $sidik() . '|' . (string) $satu('SELECT CONCAT(COUNT(*), ":", SUM(is_active)) FROM keamanan_assignments') === $sidikIsi,
    'M-6 tabel, catatan migrasi, riwayat penugasan, dan data lain tetap sesudah penolakan');

echo PHP_EOL . 'Hasil: ' . $lulus . ' lulus, ' . count($gagal) . ' gagal.' . PHP_EOL;
exit($gagal === [] ? 0 : 1);
