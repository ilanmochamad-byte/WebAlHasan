<?php

declare(strict_types=1);

/**
 * Uji migrasi 034 (jadwal kelas gabungan Fase 1) dan rollback berpenjaga pada
 * salinan DB tanpa 034:
 *
 *   JKG_TANPA_034=1 JKG_DB=test_jkg_f1_mig_test bash tests/jadwal_kelas_gabungan_f1_db.sh
 *   JKG_DB=test_jkg_f1_mig_test php tests/jadwal_kelas_gabungan_f1_migration.php
 *
 *   M-1 sebelum 034: kode berjalan satu kelas; kelas gabungan ditolak dengan pesan jelas;
 *       penyiapan sejarah (jadwal berganti kelas sesudah pertemuan, pertemuan tanpa
 *       peserta, draf) lewat layanan resmi
 *   M-2 naik: dua tabel; isian = satu kelas utama per jadwal, kelas historis pertemuan;
 *       draf tidak diisi; tabel lama tidak berubah (jumlah + sidik)
 *   M-3 menjalankan ulang berkas migrasi aman (tanpa baris ganda)
 *   M-4 kunci asing, UNIQUE, dan CHECK ditegakkan basis data
 *   M-5 rollback saat masih satu kelas berhasil; kode tetap berjalan; naik lagi sama
 *   M-6 rollback DITOLAK sesudah ada jadwal gabungan, juga sesudah jadwal dikembalikan
 *       ke satu kelas selama ada pertemuan berkelas ganda; data tetap
 */

use App\Database\Migrator;
use App\Database\Schema;
use App\Schedule\KelasJadwal;
use App\Schedule\ScheduleException;

$root = dirname(__DIR__);
$dbName = getenv('JKG_DB') ?: 'test_jkg_f1_mig_test';
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
$semua = static function (string $sql) use ($db): array {
    $r = $db->query($sql);
    if ($r === false) {
        throw new RuntimeException($db->error . ' :: ' . $sql);
    }
    return $r->fetch_all(MYSQLI_ASSOC);
};
$migrator = new Migrator($db, $root . '/database/migrations', $root . '/database/rollbacks');
$nama = '034_jadwal_kelas_gabungan_fase1.sql';
$tabelAda = static function () use ($satu): bool {
    Schema::forget();
    return (int) $satu("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('jadwal_kelas', 'pertemuan_kelas')") === 2;
};
$tercatat = static fn (): bool => (int) $satu("SELECT COUNT(*) FROM schema_migrations WHERE migration = '$nama'") === 1;
// Sidik tabel lama yang tidak boleh berubah karena 034.
$sidik = static function () use ($satu): string {
    return implode(' ', [
        (string) $satu("SELECT CONCAT(COUNT(*), ':', COALESCE(SUM(CRC32(CONCAT_WS('|', id, id_tahun, id_kelas, id_guru, COALESCE(hari, '~'), COALESCE(waktu_mulai, '~'), tempat, fan_ilmu, is_active, COALESCE(archived_at, '~'), COALESCE(updated_at, '~')))), 0)) FROM jadwal_ngaji"),
        (string) $satu("SELECT CONCAT(COUNT(*), ':', COALESCE(SUM(CRC32(CONCAT_WS('|', id, jadwal_id, tanggal_pertemuan, status, COALESCE(opened_at, '~'), COALESCE(completed_at, '~'), updated_at))), 0)) FROM pertemuan_pengajian"),
        (string) $satu("SELECT CONCAT(COUNT(*), ':', COALESCE(SUM(CRC32(CONCAT_WS('|', id, pertemuan_id, santri_id, COALESCE(plotting_kelas_id, '~'), kelas_id_snapshot, tahun_ajaran_id_snapshot, nis_snapshot, nama_santri_snapshot))), 0)) FROM pertemuan_peserta"),
        (string) $satu("SELECT CONCAT(COUNT(*), ':', COALESCE(SUM(CRC32(CONCAT_WS('|', id, pertemuan_id, guru_id, status, dicatat_pada, updated_at))), 0)) FROM absensi_guru"),
        (string) $satu("SELECT CONCAT(COUNT(*), ':', COALESCE(SUM(CRC32(CONCAT_WS('|', id, pertemuan_id, santri_id, status, dicatat_pada, updated_at))), 0)) FROM absensi_santri"),
        (string) $satu("SELECT CONCAT(COUNT(*), ':', COALESCE(SUM(CRC32(CONCAT_WS('|', id, id_santri, id_kelas, id_tahun, status))), 0)) FROM plotting_kelas"),
        (string) $satu("SELECT CONCAT(COUNT(*), ':', COALESCE(SUM(CRC32(CONCAT_WS('|', id, nis, nama_santri, is_active))), 0)) FROM santri"),
        (string) $satu("SELECT CONCAT(COUNT(*), ':', COALESCE(SUM(CRC32(CONCAT_WS('|', id, nama_kelas, is_active))), 0)) FROM kelas"),
        (string) $satu('SELECT COUNT(*) FROM audit_logs'),
    ]);
};
$jalankanBerkas = static function (string $berkas) use ($db): int {
    $db->multi_query((string) file_get_contents($berkas));
    do {
        if ($r = $db->store_result()) {
            $r->free();
        }
    } while ($db->more_results() && $db->next_result());
    return $db->errno;
};

$assert(!$tabelAda() && !$tercatat() && $satu('SELECT migration FROM schema_migrations ORDER BY id DESC LIMIT 1') === '033_penugasan_keamanan_fase3_keamanan.sql',
    'M-0 salinan berada pada 033, tanpa tabel 034');

echo "\n=== M-1 sebelum 034 ===\n";
$ids = require __DIR__ . '/jadwal_kelas_gabungan_f1_seed.php';
$k = $ids['kelas'];
$admin = ['id' => $ids['admin_id'], 'roles' => ['admin'], 'guru_id' => null, 'name' => 'Admin uji'];
$s = schedule_service();
$kamis = (new DateTimeImmutable('next thursday'))->format('Y-m-d');
$dasar = ['id_tahun' => $ids['tahun'], 'waktu_sholat' => "Ba'da Isya", 'hari' => 'Kamis', 'waktu_mulai' => '20:00', 'waktu_selesai' => '20:45',
    'fan_ilmu' => 'Sejarah ' . $ids['sfx'], 'nama_kitab' => 'Khulashah', 'id_guru' => $ids['guru_g'], 'tempat' => 'Ruang M ' . $ids['sfx']];
$j = static fn (array $ubah): array => array_replace($dasar, $ubah);
$assert(!KelasJadwal::terpasang($db), 'M-1 skema 034 tidak terpasang');
$tolak = null;
try {
    $s->save($j(['id_kelas' => $k['PA'], 'kelas_gabungan' => [$k['PI']]]), $ids['admin_id']);
} catch (ScheduleException $e) {
    $tolak = $e->getMessage();
}
$assert($tolak !== null && str_contains($tolak, 'migrasi 034') && (int) $satu("SELECT COUNT(*) FROM jadwal_ngaji WHERE fan_ilmu = 'Sejarah {$ids['sfx']}'") === 0,
    'M-1 kelas gabungan sebelum 034 ditolak dengan pesan jelas; tidak ada jadwal setengah jadi');
// Sejarah: jadwal X dibuka untuk PA lalu kelas utamanya diganti ke PI (satu kelas, jalur lama).
$X = (int) $s->save($j(['id_kelas' => $k['PA']]), $ids['admin_id'])['id'];
$mX = $s->open($X, $kamis, '', $admin);
$s->save($j(['id_kelas' => $k['PI']]), $ids['admin_id'], $X);
$fX = $s->find($X);
$assert($fX['kelas_label'] === (string) $satu("SELECT nama_kelas FROM kelas WHERE id = {$k['PI']}") && $fX['kelas_ids'] === (string) $k['PI'], 'M-1 label/ID kelas tetap terbaca (satu kelas) sebelum 034');
$assert((int) $satu("SELECT COUNT(*) FROM pertemuan_peserta WHERE pertemuan_id = $mX AND kelas_id_snapshot = {$k['PA']}") === 3, 'M-1 pembukaan sebelum 034 memakai jalur satu kelas (3 santri PA)');
// Pertemuan tanpa peserta: kelas tanpa anggota aktif.
$kosong = master_data_service()->saveClass(['nama_kelas' => 'Kelas Kosong ' . $ids['sfx'], 'jenjang' => 'Uji']);
$Y = (int) $s->save($j(['id_kelas' => $kosong, 'id_guru' => $ids['guru_h'], 'tempat' => 'Ruang Y ' . $ids['sfx']]), $ids['admin_id'])['id'];
$mY = $s->open($Y, $kamis, '', $admin);
// Draf: kelas dibekukan nanti saat dibuka.
$Z = (int) $s->save($j(['id_kelas' => $k['C'], 'id_guru' => $ids['guru_i'], 'tempat' => 'Ruang Z ' . $ids['sfx']]), $ids['admin_id'])['id'];
$mZ = $s->createDraft($Z, $kamis, '', $admin);
$lap = report_service()->report(['date_from' => $kamis, 'date_to' => $kamis, 'class_id' => $k['PA']], $admin, 'gabungan');
$assert(is_array($lap['summary']), 'M-1 laporan dengan saringan kelas berjalan sebelum 034');
$jadwalSah = (int) $satu('SELECT COUNT(*) FROM jadwal_ngaji j JOIN kelas k ON k.id = j.id_kelas');
$dibuka = array_map('intval', array_column($semua("SELECT id FROM pertemuan_pengajian WHERE status IN ('Dibuka','Selesai')"), 'id'));
$sidikAwal = $sidik();

echo "\n=== M-2 naik ===\n";
$naik = $migrator->up();
$assert($naik === [$nama] && $tabelAda() && $tercatat(), 'M-2 migrasi 034 diterapkan dan tercatat');
$assert((int) $satu('SELECT COUNT(*) FROM jadwal_kelas') === $jadwalSah && (int) $satu('SELECT COUNT(*) FROM jadwal_kelas WHERE is_utama = 1') === $jadwalSah
    && (int) $satu('SELECT COUNT(*) FROM jadwal_kelas jk JOIN jadwal_ngaji j ON j.id = jk.jadwal_id WHERE jk.kelas_id <> j.id_kelas') === 0,
    "M-2 tiap jadwal ($jadwalSah) mendapat tepat satu baris kelas utama = id_kelas; tidak ada penggabungan otomatis");
$assert((int) $satu("SELECT COUNT(DISTINCT pertemuan_id) FROM pertemuan_kelas WHERE sumber = 'Migrasi 034'") === count($dibuka)
    && (int) $satu('SELECT COUNT(*) FROM (SELECT pertemuan_id FROM pertemuan_kelas GROUP BY pertemuan_id HAVING SUM(is_utama) <> 1) x') === 0,
    'M-2 tiap pertemuan yang sudah dibuka (' . count($dibuka) . ') mendapat kelas beku dengan tepat satu kelas utama');
$assert($semua("SELECT kelas_id FROM pertemuan_kelas WHERE pertemuan_id = $mX") === [['kelas_id' => (string) $k['PA']]],
    'M-2 pertemuan yang dibuka saat jadwal masih PA tetap berkelas PA walau jadwal kini PI (historis)');
$assert($semua("SELECT kelas_id, is_utama FROM pertemuan_kelas WHERE pertemuan_id = $mY") === [['kelas_id' => (string) $kosong, 'is_utama' => '1']],
    'M-2 pertemuan tanpa peserta memakai kelas utama jadwal');
$assert((int) $satu("SELECT COUNT(*) FROM pertemuan_kelas WHERE pertemuan_id = $mZ") === 0, 'M-2 draf tidak diisi; kelasnya dibekukan saat dibuka');
$assert((int) $satu('SELECT COUNT(*) FROM pertemuan_peserta pp WHERE NOT EXISTS (SELECT 1 FROM pertemuan_kelas pk WHERE pk.pertemuan_id = pp.pertemuan_id AND pk.kelas_id = pp.kelas_id_snapshot)') === 0,
    'M-2 kelas beku setiap pertemuan memuat kelas snapshot seluruh pesertanya');
$assert($sidik() === $sidikAwal, 'M-2 tabel lama (jadwal, pertemuan, peserta, absensi, keanggotaan, santri, kelas, audit) tidak berubah');
$fk = (int) $satu("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME IN ('jadwal_kelas', 'pertemuan_kelas')");
$assert($fk === 5, "M-2 lima kunci asing terpasang (jadwal, kelas, pembuat; pertemuan, kelas) — $fk");
$s->open($Z, $kamis, '', $admin);
$assert($semua("SELECT kelas_id, sumber FROM pertemuan_kelas WHERE pertemuan_id = $mZ") === [['kelas_id' => (string) $k['C'], 'sumber' => 'Pembukaan']],
    'M-2 draf lama yang dibuka sesudah migrasi membekukan kelas jadwalnya');
$sidikNaik = $sidik();
$isianNaik = (string) $satu("SELECT CONCAT(COUNT(*), ':', SUM(CRC32(CONCAT_WS('|', jadwal_id, kelas_id, is_utama)))) FROM jadwal_kelas") . '/'
    . (string) $satu("SELECT CONCAT(COUNT(*), ':', SUM(CRC32(CONCAT_WS('|', pertemuan_id, kelas_id, is_utama, sumber)))) FROM pertemuan_kelas");

echo "\n=== M-3 jalankan ulang ===\n";
$errno = $jalankanBerkas($root . '/database/migrations/' . $nama);
$isianUlang = (string) $satu("SELECT CONCAT(COUNT(*), ':', SUM(CRC32(CONCAT_WS('|', jadwal_id, kelas_id, is_utama)))) FROM jadwal_kelas") . '/'
    . (string) $satu("SELECT CONCAT(COUNT(*), ':', SUM(CRC32(CONCAT_WS('|', pertemuan_id, kelas_id, is_utama, sumber)))) FROM pertemuan_kelas");
$assert($errno === 0 && $isianUlang === $isianNaik && $sidik() === $sidikNaik, 'M-3 menjalankan ulang 034 aman: tanpa galat, tanpa baris ganda, tabel lama sama');

echo "\n=== M-4 kunci asing, UNIQUE, CHECK ===\n";
$db->query("INSERT INTO jadwal_kelas (jadwal_id, kelas_id, is_utama) VALUES ($X, 2147483000, 0)");
$assert($db->errno === 1452, 'M-4 kunci asing menolak kelas yang tidak ada (errno ' . $db->errno . ')');
$db->query("INSERT INTO jadwal_kelas (jadwal_id, kelas_id, is_utama) VALUES (2147483000, {$k['PA']}, 0)");
$assert($db->errno === 1452, 'M-4 kunci asing menolak jadwal yang tidak ada (errno ' . $db->errno . ')');
$db->query("INSERT INTO jadwal_kelas (jadwal_id, kelas_id, is_utama) VALUES ($X, {$k['PI']}, 0)");
$assert($db->errno === 1062, 'M-4 UNIQUE menolak pasangan jadwal–kelas ganda (errno ' . $db->errno . ')');
$db->query("INSERT INTO jadwal_kelas (jadwal_id, kelas_id, is_utama) VALUES ($X, {$k['C']}, 2)");
$assert(in_array($db->errno, [4025, 3819], true), 'M-4 CHECK menolak is_utama selain 0/1 (errno ' . $db->errno . ')');
$db->query("INSERT INTO pertemuan_kelas (pertemuan_id, kelas_id, is_utama) VALUES (2147483000, {$k['PA']}, 1)");
$assert($db->errno === 1452, 'M-4 kunci asing menolak pertemuan yang tidak ada (errno ' . $db->errno . ')');
$db->query("INSERT INTO pertemuan_kelas (pertemuan_id, kelas_id, is_utama) VALUES ($mX, {$k['PA']}, 1)");
$assert($db->errno === 1062, 'M-4 UNIQUE menolak kelas ganda pada satu pertemuan (errno ' . $db->errno . ')');
$assert($sidik() === $sidikNaik, 'M-4 percobaan yang ditolak tidak meninggalkan perubahan');

echo "\n=== M-5 rollback saat satu kelas ===\n";
$turun = $migrator->rollbackLast();
$assert($turun === $nama && !$tabelAda() && !$tercatat() && $sidik() === $sidikNaik, 'M-5 rollback berhasil selama masih satu kelas; tabel lama utuh');
$p = $s->meeting($mX, $admin);
$assert(!KelasJadwal::terpasang($db) && count($p['participants']) === 3 && $s->find($X)['kelas_ids'] === (string) $k['PI'], 'M-5 kode tetap berjalan sesudah rollback (perilaku satu kelas)');
$migrator->up();
$isianLagi = (string) $satu("SELECT CONCAT(COUNT(*), ':', SUM(CRC32(CONCAT_WS('|', jadwal_id, kelas_id, is_utama)))) FROM jadwal_kelas") . '/'
    . (string) $satu("SELECT CONCAT(COUNT(*), ':', SUM(CRC32(CONCAT_WS('|', pertemuan_id, kelas_id, is_utama)))) FROM pertemuan_kelas");
$assert($tabelAda() && $tercatat() && str_starts_with($isianLagi, explode('/', $isianNaik)[0] . '/'), 'M-5 naik lagi menghasilkan isian jadwal yang sama');

echo "\n=== M-6 rollback ditolak sesudah ada data gabungan ===\n";
$G = (int) $s->save($j(['id_kelas' => $k['PA'], 'kelas_gabungan' => [$k['PI']], 'hari' => 'Jumat', 'tempat' => 'Ruang G ' . $ids['sfx']]), $ids['admin_id'])['id'];
$jumat = (new DateTimeImmutable('next friday'))->format('Y-m-d');
$mG = $s->open($G, $jumat, '', $admin);
$sidikIsi = $sidik() . '|' . (string) $satu('SELECT COUNT(*) FROM jadwal_kelas') . '|' . (string) $satu('SELECT COUNT(*) FROM pertemuan_kelas');
$ditolak = null;
try {
    $migrator->rollbackLast();
} catch (RuntimeException $e) {
    $ditolak = $e->getMessage();
}
$db = app_db();
$assert($ditolak !== null && str_contains($ditolak, $nama), 'M-6 rollback ditolak penjaga data saat ada jadwal gabungan (' . ($ditolak ?? 'tidak ditolak') . ')');
$assert($tabelAda() && $tercatat() && $sidik() . '|' . (string) $satu('SELECT COUNT(*) FROM jadwal_kelas') . '|' . (string) $satu('SELECT COUNT(*) FROM pertemuan_kelas') === $sidikIsi,
    'M-6 tabel, catatan migrasi, dan seluruh data tetap sesudah penolakan');
$s->save($j(['id_kelas' => $k['PA'], 'hari' => 'Jumat', 'tempat' => 'Ruang G ' . $ids['sfx']]), $ids['admin_id'], $G);
$assert((int) $satu('SELECT COUNT(*) FROM jadwal_kelas WHERE is_utama = 0') === 0 && (int) $satu("SELECT COUNT(*) FROM pertemuan_kelas WHERE pertemuan_id = $mG") === 2,
    'M-6 penyiapan: jadwal dikembalikan ke satu kelas, pertemuan lama tetap berkelas dua');
$ditolak = null;
try {
    $migrator->rollbackLast();
} catch (RuntimeException $e) {
    $ditolak = $e->getMessage();
}
$db = app_db();
$assert($ditolak !== null && $tabelAda() && $tercatat(), 'M-6 rollback tetap ditolak selama ada pertemuan berkelas ganda');

echo PHP_EOL . 'Hasil: ' . $lulus . ' lulus, ' . count($gagal) . ' gagal.' . PHP_EOL;
exit($gagal === [] ? 0 : 1);
