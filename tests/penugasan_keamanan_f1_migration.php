<?php

declare(strict_types=1);

/**
 * Uji migrasi 032 dan rollback berpenjaga (Fase 1 penugasan lintas guru/pengurus)
 * di atas salinan DB tanpa 032:
 *
 *   PKF1_TANPA_032=1 PKF1_DB=test_pk_f1_mig_test bash tests/penugasan_keamanan_f1_db.sh
 *   PKF1_DB=test_pk_f1_mig_test php tests/penugasan_keamanan_f1_migration.php
 *
 *   M-1 sebelum 032: kode berjalan, pemilihan guru ditolak "belum dipasang", hak lama utuh
 *   M-2 naik: kolom/unique/FK terpasang, baris lama tidak berubah (jumlah + sidik isi)
 *   M-3 menjalankan ulang berkas migrasi tidak menggandakan apa pun
 *   M-4 FK dan unique ditegakkan basis data
 *   M-5 rollback tanpa identitas: skema kembali, data lama utuh; naik lagi
 *   M-6 rollback DITOLAK sesudah ada identitas; skema, catatan migrasi, dan identitas tetap
 */

$root = dirname(__DIR__);
$dbName = getenv('PKF1_DB') ?: 'test_pk_f1_mig_test';
if (!preg_match('/^test_.*_test$/', $dbName)) {
    fwrite(STDERR, "DB uji harus bernama test_*_test.\n");
    exit(2);
}
putenv('DB_NAME=' . $dbName);
require_once $root . '/app/bootstrap.php';

use App\Database\Migrator;
use App\Database\Schema;
use App\Penugasan\PenugasanException;
use App\Penugasan\PenugasanJenis;

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
$nama = '032_penugasan_keamanan_fase1_identitas_guru.sql';
$kolomAda = static function () use ($satu): bool {
    Schema::forget();

    return (int) $satu("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengurus' AND COLUMN_NAME = 'guru_id'") === 1;
};
$tercatat = static fn (): bool => (int) $satu("SELECT COUNT(*) FROM schema_migrations WHERE migration = '$nama'") === 1;
// Sidik isi tabel yang tidak boleh berubah (kolom lama pengurus saja).
$sidik = static function () use ($satu): string {
    $bagian = [
        (string) $satu("SELECT CONCAT(COUNT(*), ':', COALESCE(SUM(CRC32(CONCAT_WS('|', id, nama, COALESCE(nomor_identitas, '~NULL~'), COALESCE(no_hp, '~NULL~'), jabatan, is_active, COALESCE(archived_at, '~NULL~')))), 0)) FROM pengurus"),
        (string) $satu("SELECT CONCAT(COUNT(*), ':', COALESCE(SUM(CRC32(CONCAT_WS('|', id, username, COALESCE(guru_id, '~NULL~'), COALESCE(pengurus_id, '~NULL~'), COALESCE(wali_id, '~NULL~'), is_active))), 0)) FROM users"),
        (string) $satu("SELECT CONCAT(COUNT(*), ':', COALESCE(SUM(CRC32(CONCAT_WS('|', user_id, role_id))), 0)) FROM user_roles"),
        (string) $satu("SELECT CONCAT(COUNT(*), ':', COALESCE(SUM(CRC32(CONCAT_WS('|', id, nama_guru, is_active, COALESCE(archived_at, '~NULL~')))), 0)) FROM guru"),
    ];
    foreach (PenugasanJenis::semua() as $definisi) {
        $bagian[] = (string) $satu('SELECT CONCAT(COUNT(*), ":", COALESCE(SUM(CRC32(CONCAT_WS("|", id, ' . $definisi['subjek_kolom'] . ', is_active))), 0)) FROM ' . $definisi['tabel']);
    }

    return implode(' ', $bagian);
};
$admin = (int) $satu("SELECT id FROM users WHERE username = 'sbx_admin'");
$tahun = (int) $satu("SELECT id FROM tahun_ajaran WHERE status = 'Aktif' AND archived_at IS NULL LIMIT 1");
$guru = (int) $satu("SELECT guru_id FROM users WHERE username = 'sbx_guru_biasa'");
$userPengurus = (int) $satu("SELECT id FROM users WHERE username = 'sbx_pengurus_a'");
$fitur = static function (int $userId): array {
    capabilities()->forget($userId);

    return capabilities()->featureList(['id' => $userId, 'roles' => [], 'guru_id' => null]);
};
$kesehatan = static fn (string $subjek): array => ['pengurus_id' => $subjek, 'tahun_ajaran_id' => $tahun, 'tanggal_mulai' => date('Y-m-d')];

// --- M-1 sebelum 032 --------------------------------------------------------
$assert(!$kolomAda() && !$tercatat() && $satu('SELECT migration FROM schema_migrations ORDER BY id DESC LIMIT 1') === '031_v7_fase2_tinjauan_pembimbing.sql',
    'M-1 salinan berada pada 031 tanpa kolom pengurus.guru_id');
$assert(penugasan_service()->opsi(PenugasanJenis::PEMBIMBING)['subjek_guru'] === [] && penugasan_service()->opsi(PenugasanJenis::PEMBIMBING)['subjek'] !== [],
    'M-1 halaman penugasan berjalan; kelompok guru belum ditawarkan');
try {
    penugasan_service()->buat(PenugasanJenis::KESEHATAN, $kesehatan('guru:' . $guru), $admin);
    $assert(false, 'M-1 memilih guru sebelum 032 seharusnya ditolak');
} catch (PenugasanException $e) {
    $assert(str_contains((string) json_encode($e->errors()), 'belum dipasang'), 'M-1 memilih guru sebelum 032 ditolak dengan pesan "belum dipasang"');
}
$fiturAwal = $fitur($userPengurus);
$assert(in_array(PenugasanJenis::CAP_PEMBIMBING_BINAAN, $fiturAwal, true) && capabilities()->linkedPengurusId($userPengurus) !== null,
    'M-1 hak pengurus biasa tetap dihitung tanpa kolom baru');
$assert(perizinan_account_service()->availablePengurus() !== null && account_service()->find($userPengurus)['pengurus_guru_id'] === null,
    'M-1 halaman akun berjalan (pemilik identitas NULL)');
$guruRow = master_data_service()->guru($guru);
master_data_service()->saveGuru(['nip' => (string) $guruRow['nip'], 'nama_guru' => (string) $guruRow['nama_guru'], 'no_hp' => (string) ($guruRow['no_hp'] ?? '')], $guru);
$assert(master_data_service()->identitasGuru($guru) === null, 'M-1 Data Guru tetap dapat disimpan; sinkronisasi tidak berbuat apa-apa');
$sidikAwal = $sidik();

// --- M-2 naik ---------------------------------------------------------------
$diterapkan = $migrator->up();
Schema::forget();
$assert($diterapkan === [$nama] && $kolomAda() && $tercatat(), 'M-2 migrasi 032 diterapkan dan tercatat');
$kolom = $db->query("SELECT DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengurus' AND COLUMN_NAME = 'guru_id'")->fetch_assoc();
$assert($kolom['DATA_TYPE'] === 'int' && $kolom['IS_NULLABLE'] === 'YES' && in_array($kolom['COLUMN_DEFAULT'], [null, 'NULL'], true), 'M-2 kolom guru_id INT NULL DEFAULT NULL (sama dengan guru.id)');
$assert((int) $satu("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengurus' AND INDEX_NAME = 'pengurus_guru_unique' AND NON_UNIQUE = 0") === 1
    && (int) $satu("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'pengurus_guru_fk' AND REFERENCED_TABLE_NAME = 'guru'") === 1,
    'M-2 unique key dan foreign key ke guru terpasang');
$assert((int) $satu('SELECT COUNT(*) FROM pengurus WHERE guru_id IS NOT NULL') === 0 && $sidik() === $sidikAwal, 'M-2 seluruh baris lama tidak berubah (guru_id NULL, sidik sama)');
$assert($fitur($userPengurus) === $fiturAwal, 'M-2 hak pengurus biasa sama sebelum dan sesudah migrasi');

// --- M-3 jalankan ulang -------------------------------------------------------
$jalankanBerkas = static function (string $berkas) use ($db): ?string {
    if (!$db->multi_query((string) file_get_contents($berkas))) {
        return $db->error;
    }
    do {
        if ($r = $db->store_result()) {
            $r->free();
        }
        if ($db->errno) {
            return $db->error;
        }
    } while ($db->more_results() && $db->next_result());

    return $db->errno ? $db->error : null;
};
$galatUlang = $jalankanBerkas($root . '/database/migrations/' . $nama);
$assert($galatUlang === null && (int) $satu("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengurus' AND COLUMN_NAME = 'guru_id'") === 1
    && (int) $satu("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengurus' AND CONSTRAINT_TYPE = 'FOREIGN KEY'") === 1,
    'M-3 menjalankan ulang 032 aman: tanpa galat, tanpa indeks/FK ganda');

// --- M-4 FK dan unique ---------------------------------------------------------
$db->query("INSERT INTO pengurus (nama, jabatan, guru_id, is_active) VALUES ('FK tiruan', 'Guru', 2147483000, 1)");
$assert($db->errno === 1452, 'M-4 FK menolak identitas untuk guru yang tidak ada (errno ' . $db->errno . ')');
$db->query("INSERT INTO pengurus (nama, jabatan, guru_id, is_active) VALUES ('Unik A', 'Guru', $guru, 1)");
$idA = (int) $db->insert_id;
$db->query("INSERT INTO pengurus (nama, jabatan, guru_id, is_active) VALUES ('Unik B', 'Guru', $guru, 1)");
$assert($idA > 0 && $db->errno === 1062, 'M-4 unique menolak identitas kedua untuk guru yang sama (errno ' . $db->errno . ')');
$db->query("DELETE FROM pengurus WHERE id = $idA");

// --- M-5 rollback tanpa identitas ------------------------------------------------
$turun = $migrator->rollbackLast();
Schema::forget();
$assert($turun === $nama && !$kolomAda() && !$tercatat(), 'M-5 rollback tanpa identitas berhasil; kolom dan catatan migrasi dilepas');
$assert((int) $satu("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengurus' AND INDEX_NAME = 'pengurus_guru_unique'") === 0
    && (int) $satu("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pengurus' AND CONSTRAINT_NAME = 'pengurus_guru_fk'") === 0,
    'M-5 unique key dan FK ikut dilepas');
$assert($sidik() === $sidikAwal && $fitur($userPengurus) === $fiturAwal, 'M-5 data dan hak lama utuh sesudah rollback');
$migrator->up();
Schema::forget();
$assert($kolomAda() && $tercatat(), 'M-5 naik lagi sesudah rollback berhasil');

// --- M-6 rollback ditolak sesudah ada identitas ------------------------------------
$idPenugasan = penugasan_service()->buat(PenugasanJenis::KESEHATAN, $kesehatan('guru:' . $guru), $admin);
$identitas = (int) $satu("SELECT id FROM pengurus WHERE guru_id = $guru");
$assert($idPenugasan > 0 && $identitas > 0, 'M-6 penyiapan: identitas guru terbentuk lewat layanan');
$sidikIdentitas = $sidik();
$ditolak = null;
try {
    $migrator->rollbackLast();
} catch (RuntimeException $e) {
    $ditolak = $e->getMessage();
}
Schema::forget();
$db = app_db();
$assert($ditolak !== null && str_contains($ditolak, $nama), 'M-6 rollback ditolak penjaga data (' . ($ditolak ?? 'tidak ditolak') . ')');
$assert($kolomAda() && $tercatat() && (int) $satu("SELECT guru_id FROM pengurus WHERE id = $identitas") === $guru && $sidik() === $sidikIdentitas,
    'M-6 skema, catatan migrasi, identitas, dan seluruh data tetap sesudah penolakan');
$assert(in_array(PenugasanJenis::CAP_KESEHATAN_CATAT, $fitur((int) $satu("SELECT id FROM users WHERE guru_id = $guru")), true),
    'M-6 hak akun guru tetap berjalan sesudah rollback ditolak');

echo PHP_EOL . 'Hasil: ' . $lulus . ' lulus, ' . count($gagal) . ' gagal.' . PHP_EOL;
exit($gagal === [] ? 0 : 1);
