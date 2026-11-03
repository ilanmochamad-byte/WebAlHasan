<?php

declare(strict_types=1);

/**
 * Uji migrasi 035 (profil pengguna dan impor guru/pengurus Fase 1) dan rollback
 * berpenjaga pada salinan DB tanpa 035:
 *
 *   PIF1_TANPA_035=1 PIF1_DB=test_pif1_mig_test bash tests/profil_impor_f1_db.sh
 *   PIF1_DB=test_pif1_mig_test php tests/profil_impor_f1_migration.php
 *
 *   M-1 sebelum 035: kode berjalan (login web/API, Data Guru) dan profil melapor
 *       "belum dipasang" (503), tanpa galat fatal
 *   M-2 naik: satu tabel + dua kolom; seluruh baris guru/pengurus/wali/users/
 *       user_roles/api_tokens/penugasan/santri_wali identik (sidik isi seluruh kolom lama)
 *   M-3 menjalankan ulang berkas migrasi aman
 *   M-4 kunci asing, UNIQUE, dan CHECK ditegakkan basis data (pemilik tepat satu,
 *       NIK 16 digit, nama foto, NIK unik per jenis)
 *   M-5 rollback saat tabel kosong berhasil; token `ganti_password` dicabut lebih
 *       dulu; data lama identik; naik lagi sama
 *   M-6 rollback DITOLAK bila sudah ada profil pribadi; data tetap
 */

use App\Database\Migrator;
use App\Database\Schema;
use App\Profil\ProfilException;

$root = dirname(__DIR__);
$dbName = getenv('PIF1_DB') ?: 'test_pif1_mig_test';
if (!preg_match('/^test_.*_test$/', $dbName)) {
    fwrite(STDERR, "DB uji harus bernama test_*_test.\n");
    exit(2);
}
putenv('DB_NAME=' . $dbName);
require_once $root . '/app/bootstrap.php';

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
$nama = '035_profil_impor_fase1.sql';
$terpasang = static function () use ($satu): bool {
    Schema::forget();
    return (int) $satu("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'profil_pribadi'") === 1
        && (int) $satu("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND ((TABLE_NAME = 'users' AND COLUMN_NAME = 'sesi_versi') OR (TABLE_NAME = 'api_tokens' AND COLUMN_NAME = 'cakupan'))") === 2;
};
$tercatat = static fn (): bool => (int) $satu("SELECT COUNT(*) FROM schema_migrations WHERE migration = '$nama'") === 1;
$TABEL = ['guru', 'pengurus', 'wali', 'users', 'user_roles', 'api_tokens', 'santri_wali', 'murobi_assignments', 'pembimbing_assignments', 'guru_mapel_assignments',
    'pendidikan_assignments', 'kesehatan_assignments', 'bendahara_bulanan_assignments', 'keamanan_assignments', 'perangkat_push', 'santri'];
// Sidik isi seluruh kolom LAMA (kolom 035 dikecualikan) per tabel.
$kolomLama = [];
foreach ($TABEL as $t) {
    $kolomLama[$t] = array_values(array_diff(array_column($db->query("SHOW COLUMNS FROM `$t`")->fetch_all(MYSQLI_ASSOC), 'Field'), ['sesi_versi', 'cakupan']));
}
$sidik = static function () use ($db, $TABEL, $kolomLama): array {
    $hasil = [];
    foreach ($TABEL as $t) {
        $kolom = implode(', ', array_map(static fn (string $k): string => "`$k`", $kolomLama[$t]));
        $hasil[$t] = hash('sha256', json_encode($db->query("SELECT $kolom FROM `$t` ORDER BY 1")->fetch_all(MYSQLI_NUM)));
    }
    return $hasil;
};
$jalankanBerkas = static function (string $berkas) use ($db): bool {
    if (!$db->multi_query((string) file_get_contents($berkas))) {
        return false;
    }
    do {
        if ($r = $db->store_result()) {
            $r->free();
        }
        if ($db->errno) {
            return false;
        }
    } while ($db->more_results() && $db->next_result());
    return $db->errno === 0;
};
$ditolak = static function (string $sql) use ($db): bool {
    try {
        return $db->query($sql) === false;
    } catch (Throwable) {
        return true;
    }
};

echo "=== M-1 sebelum 035 ===\n";
$assert(!$terpasang() && !$tercatat(), 'Salinan DB tanpa 035');
$assert(!profil_service()->tersedia(), 'Layanan profil melapor belum tersedia');
$e = null;
try {
    profil_service()->baca(1);
} catch (ProfilException $x) {
    $e = $x;
}
$assert($e !== null && $e->status() === 503, 'Baca profil sebelum 035 → 503 "belum dipasang", bukan galat fatal');
$assert(auth_repository()->findActiveById(1)['sesi_versi'] === null && auth_repository()->findByUsername('sbx_admin') !== null, 'Autentikasi web berjalan tanpa kolom sesi_versi (sesi tidak diperiksa versinya)');
$_SESSION = ['user_id' => 1];
$guruId = (int) $satu('SELECT id FROM guru ORDER BY id LIMIT 1');
$guruLama = $db->query("SELECT nip, nama_guru, no_hp FROM guru WHERE id = $guruId")->fetch_assoc();
master_data_service()->saveGuru(['nama_guru' => $guruLama['nama_guru'], 'nip' => $guruLama['nip'] ?? '', 'no_hp' => $guruLama['no_hp'] ?? '', 'provinsi' => 'Diabaikan'], $guruId);
$assert(!master_data_service()->profilTersedia(), 'Data Guru tetap tersimpan sebelum 035; isian profil diabaikan');

echo "\n=== M-2 naik ===\n";
$sebelum = $sidik();
$audit = (int) $satu('SELECT COUNT(*) FROM audit_logs');
$diterapkan = $migrator->up();
$assert($diterapkan === [$nama] && $terpasang() && $tercatat(), 'Migrasi 035 diterapkan dan tercatat');
$assert($sidik() === $sebelum, 'Isi seluruh kolom lama 16 tabel (guru, pengurus, wali, users, role, token, relasi, penugasan, santri) identik');
$assert((int) $satu('SELECT COUNT(*) FROM profil_pribadi') === 0 && (int) $satu('SELECT COUNT(*) FROM users WHERE sesi_versi <> 0') === 0
    && (int) $satu("SELECT COUNT(*) FROM api_tokens WHERE cakupan <> 'penuh'") === 0 && (int) $satu('SELECT COUNT(*) FROM audit_logs') === $audit,
    'Tanpa isian otomatis: profil kosong, sesi_versi 0, seluruh token lama "penuh", audit tidak bertambah');
$assert(profil_service()->tersedia(), 'Layanan profil tersedia sesudah naik');

echo "\n=== M-3 jalankan ulang ===\n";
$assert($jalankanBerkas($root . '/database/migrations/' . $nama) && $sidik() === $sebelum && (int) $satu('SELECT COUNT(*) FROM profil_pribadi') === 0, 'Berkas migrasi dapat dijalankan ulang tanpa perubahan');

echo "\n=== M-4 batasan basis data ===\n";
$waliId = (int) $satu('SELECT id FROM wali ORDER BY id LIMIT 1');
$assert($ditolak("INSERT INTO profil_pribadi (jenis_pemilik, guru_id, pengurus_id) VALUES ('guru', $guruId, 1)"), 'CHECK: dua pemilik sekaligus ditolak');
$assert($ditolak("INSERT INTO profil_pribadi (jenis_pemilik, wali_id) VALUES ('guru', $waliId)"), 'CHECK: jenis pemilik tidak cocok dengan kolom ditolak');
$assert($ditolak("INSERT INTO profil_pribadi (jenis_pemilik, guru_id, nik) VALUES ('guru', $guruId, '12345')"), 'CHECK: NIK bukan 16 digit ditolak');
$assert($ditolak("INSERT INTO profil_pribadi (jenis_pemilik, guru_id, foto) VALUES ('guru', $guruId, '../../x.php')"), 'CHECK: nama berkas foto di luar pola acak ditolak');
$assert($ditolak("INSERT INTO profil_pribadi (jenis_pemilik, guru_id) VALUES ('guru', 99999999)"), 'FK: guru tidak ada ditolak');
$db->query("INSERT INTO profil_pribadi (jenis_pemilik, guru_id, nik) VALUES ('guru', $guruId, '3200000000000001')");
$guru2 = (int) $satu("SELECT id FROM guru WHERE id <> $guruId ORDER BY id LIMIT 1");
$assert($ditolak("INSERT INTO profil_pribadi (jenis_pemilik, guru_id) VALUES ('guru', $guruId)"), 'UNIQUE: satu profil per guru');
$assert($ditolak("INSERT INTO profil_pribadi (jenis_pemilik, guru_id, nik) VALUES ('guru', $guru2, '3200000000000001')"), 'UNIQUE: NIK unik di antara guru');
$assert(!$ditolak("INSERT INTO profil_pribadi (jenis_pemilik, wali_id, nik) VALUES ('wali', $waliId, '3200000000000001')"), 'NIK sama pada jenis lain (data ganda yang hanya diperingatkan) tidak diblokir basis data');
$assert($ditolak("UPDATE api_tokens SET cakupan = 'admin' LIMIT 1"), 'ENUM cakupan token menolak nilai di luar penuh/ganti_password');

echo "\n=== M-6 rollback ditolak bila ada profil ===\n";
$adaProfil = (int) $satu('SELECT COUNT(*) FROM profil_pribadi');
$e = null;
try {
    $migrator->rollbackLast();
} catch (Throwable $x) {
    $e = $x;
}
Schema::forget();
$assert($e !== null && $terpasang() && $tercatat() && (int) $satu('SELECT COUNT(*) FROM profil_pribadi') === $adaProfil, 'Rollback ditolak; profil, kolom, dan catatan migrasi tetap');
$assert($sidik() === $sebelum, 'Data lama tetap identik sesudah rollback ditolak');

echo "\n=== M-5 rollback saat kosong ===\n";
$db->query('DELETE FROM profil_pribadi');
$userId = (int) $satu('SELECT id FROM users ORDER BY id LIMIT 1');
$db->query("INSERT INTO api_tokens (user_id, token_hash, name, cakupan, expires_at, created_at) VALUES ($userId, '" . str_repeat('c', 64) . "', 'uji', 'ganti_password', DATE_ADD(NOW(), INTERVAL 1 DAY), NOW())");
$tokenTerbatas = (int) $db->insert_id;
$sebelumRollback = $sidik();
$assert($migrator->rollbackLast() === $nama && !$terpasang() && !$tercatat(), 'Rollback saat profil kosong berhasil');
$sesudah = $sidik();
$assert($satu("SELECT revoked_at FROM api_tokens WHERE id = $tokenTerbatas") !== null, 'Token ganti_password yang masih berlaku dicabut sebelum kolom cakupan dilepas');
unset($sesudah['api_tokens'], $sebelumRollback['api_tokens']);
$assert($sesudah === $sebelumRollback, 'Data lama lain identik sesudah rollback');
Schema::forget();
$assert(!profil_service()->tersedia() && auth_repository()->findActiveById(1) !== null, 'Kode tetap berjalan sesudah rollback');
$assert($migrator->up() === [$nama] && $terpasang(), 'Naik lagi berhasil');

echo "\n" . $lulus . ' lulus, ' . count($gagal) . " gagal\n";
foreach ($gagal as $g) {
    echo ' - ' . $g . "\n";
}
exit($gagal === [] ? 0 : 1);
