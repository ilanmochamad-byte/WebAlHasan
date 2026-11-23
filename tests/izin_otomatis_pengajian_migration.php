<?php

declare(strict_types=1);

/**
 * Uji migrasi 036 (status "Izin" otomatis) naik → rollback → naik dengan
 * preservasi isi seluruh tabel lama, serta perilaku kode sesudah rollback (K-7).
 *
 *   JKG_DB=test_iop_mig_test bash tests/jadwal_kelas_gabungan_f1_db.sh
 *   IOP_DB=test_iop_mig_test php tests/izin_otomatis_pengajian_migration.php
 *
 * DB tujuan sudah memuat 036 (rantai fixture `migrate up`). Mengubah data uji.
 */

use App\Database\Schema;

$root = dirname(__DIR__);
$dbName = getenv('IOP_DB') ?: 'test_iop_mig_test';
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
$migrate = static function (string $perintah) use ($root, $dbName): int {
    $out = [];
    exec('cd ' . escapeshellarg($root) . ' && DB_NAME=' . escapeshellarg($dbName) . ' ' . escapeshellarg(PHP_BINARY) . ' bin/migrate.php ' . $perintah . ' 2>&1', $out, $kode);
    Schema::forget();
    return $kode;
};
$kolomAda = static fn (string $kolom): bool => (int) $satu("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pertemuan_peserta' AND COLUMN_NAME = '$kolom'") === 1;
$terakhir = static fn (): string => (string) $satu('SELECT migration FROM schema_migrations ORDER BY id DESC LIMIT 1');

/** SHA-256 isi seluruh tabel dasar (kolom lama saja; kolom 036 dikecualikan), urut kunci utama. */
$sidik = static function () use ($db): array {
    $hasil = [];
    $tabel = $db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME NOT IN ('schema_migrations') ORDER BY TABLE_NAME")->fetch_all();
    foreach ($tabel as [$t]) {
        $kolom = array_map(static fn (array $r): string => '`' . $r[0] . '`', $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$t' AND COLUMN_NAME NOT IN ('izin_pengajuan_id','izin_keterangan') ORDER BY ORDINAL_POSITION")->fetch_all());
        $pk = array_map(static fn (array $r): string => '`' . $r[0] . '`', $db->query("SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$t' AND CONSTRAINT_NAME = 'PRIMARY' ORDER BY ORDINAL_POSITION")->fetch_all());
        $urut = $pk !== [] ? implode(',', $pk) : implode(',', $kolom);
        $h = hash_init('sha256');
        $n = 0;
        $r = $db->query("SELECT " . implode(',', $kolom) . " FROM `$t` ORDER BY $urut", MYSQLI_USE_RESULT);
        while ($row = $r->fetch_row()) {
            hash_update($h, json_encode($row) . "\n");
            $n++;
        }
        $r->free();
        $hasil[$t] = $n . ':' . hash_final($h);
    }
    return $hasil;
};

$ids = require __DIR__ . '/jadwal_kelas_gabungan_f1_seed.php';
$admin = ['id' => $ids['admin_id'], 'roles' => ['admin'], 'guru_id' => null, 'name' => 'Admin uji'];
$guru = ['id' => $ids['guru_user_id'], 'roles' => ['guru'], 'guru_id' => $ids['guru_g'], 'name' => 'Guru uji'];
$c0 = $ids['santri']['C'][0];
$c1 = $ids['santri']['C'][1];
$izin = static function (int $santri, string $tgl) use ($db, $ids): int {
    $db->query("INSERT INTO izin_pengajuan (santri_id, murobi_guru_id, tahun_ajaran_id, tgl_izin, tgl_kembali, alasan, status, asal_pengajuan, diajukan_pada)
                VALUES ($santri, {$ids['guru_h']}, {$ids['tahun']}, '$tgl', '$tgl', 'uji migrasi', 'Disetujui', 'Pengurus', NOW())");
    $id = (int) $db->insert_id;
    $db->query("INSERT INTO izin_keputusan (pengajuan_id, hasil, alasan, diputus_oleh_user_id, kapasitas, diputus_pada) VALUES ($id, 'Disetujui', 'uji', {$ids['admin_id']}, 'Murobi', NOW())");
    return $id;
};
$tgl = static fn (int $minggu): string => (new DateTimeImmutable('next thursday'))->modify('+' . (7 * $minggu) . ' days')->format('Y-m-d');
$SC = (int) schedule_service()->save(['id_tahun' => $ids['tahun'], 'id_kelas' => $ids['kelas']['C'], 'id_guru' => $ids['guru_g'], 'hari' => 'Kamis',
    'waktu_mulai' => '05:00', 'waktu_selesai' => '05:30', 'waktu_sholat' => "Ba'da Isya", 'fan_ilmu' => 'Migrasi ' . $ids['sfx'], 'nama_kitab' => 'Uji', 'tempat' => 'Uji'], $ids['admin_id'])['id'];

echo "=== Naik (036 terpasang) ===\n";
$assert($terakhir() === '036_izin_otomatis_pengajian.sql' && $kolomAda('izin_pengajuan_id') && $kolomAda('izin_keterangan'), '036 tercatat terakhir; dua kolom ada');
$fk = (string) $satu("SELECT REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pertemuan_peserta' AND CONSTRAINT_NAME = 'pertemuan_peserta_izin_fk'");
$assert($fk === 'izin_pengajuan', 'Kunci asing usulan → izin_pengajuan');
$assert((int) $satu('SELECT COUNT(*) FROM pertemuan_peserta WHERE izin_pengajuan_id IS NOT NULL OR izin_keterangan IS NOT NULL') === 0, 'Migrasi tidak mengisi ulang peserta lama (seluruhnya NULL)');
$sql = (string) file_get_contents($root . '/database/migrations/036_izin_otomatis_pengajian.sql');
$tanpaKomentar = preg_replace('/^--.*$/m', '', $sql);
$assert(!preg_match('/\b(UPDATE|DELETE|TRUNCATE|INSERT)\b/i', (string) $tanpaKomentar), 'Migrasi tanpa UPDATE/DELETE/TRUNCATE/INSERT');
$db->multi_query($sql);
do {
    if ($r = $db->store_result()) {
        $r->free();
    }
} while ($db->more_results() && $db->next_result());
$assert($db->errno === 0 && $kolomAda('izin_pengajuan_id'), 'Migrasi dapat dijalankan ulang tanpa galat');

// Data dengan usulan: pertemuan Dibuka berusulan dan pertemuan Selesai berabsensi.
$iA = $izin($c0, $tgl(1));
$m1 = schedule_service()->open($SC, $tgl(1), '', $admin);
$izin($c0, $tgl(2));
$m2 = schedule_service()->open($SC, $tgl(2), '', $admin);
teacher_api_service()->saveAttendance($m2, ['idempotency_key' => 'iop-mig-' . $ids['sfx'], 'teacher' => ['status' => 'Hadir'],
    'students' => [['student_id' => $c0, 'status' => 'Izin'], ['student_id' => $c1, 'status' => 'Hadir']]], $guru);
$assert((int) $satu("SELECT izin_pengajuan_id FROM pertemuan_peserta WHERE pertemuan_id = $m1 AND santri_id = $c0") === $iA, 'Fixture: pertemuan Dibuka berusulan');
$usulanDibuka = (int) $satu("SELECT COUNT(*) FROM pertemuan_peserta pp JOIN pertemuan_pengajian p ON p.id = pp.pertemuan_id WHERE p.status = 'Dibuka' AND pp.izin_pengajuan_id IS NOT NULL");
$assert($usulanDibuka >= 1, "Kueri pemeriksaan pra-rollback (README): $usulanDibuka usulan pada pertemuan Dibuka");
$sebelum = $sidik();

echo "\n=== Rollback ===\n";
$assert($migrate('rollback') === 0, 'Rollback 036 berhasil walau usulan berisi');
$assert(!$kolomAda('izin_pengajuan_id') && !$kolomAda('izin_keterangan') && $terakhir() !== '036_izin_otomatis_pengajian.sql', 'Kolom 036 dilepas dan catatan migrasi dihapus');
$sesudah = $sidik();
$beda = array_keys(array_filter($sebelum, static fn (string $v, string $t): bool => ($sesudah[$t] ?? null) !== $v, ARRAY_FILTER_USE_BOTH));
$assert($beda === [] && count($sebelum) === count($sesudah), 'Preservasi: isi ' . count($sebelum) . ' tabel lama (kolom lama) identik sesudah rollback' . ($beda !== [] ? ' — beda: ' . implode(',', $beda) : ''));
$assert((string) $satu("SELECT status FROM absensi_santri WHERE pertemuan_id = $m2 AND santri_id = $c0") === 'Izin', 'Absensi tersimpan tidak tersentuh rollback');

echo "\n=== K-7 perilaku sesudah rollback (baseline) ===\n";
$izin($c1, $tgl(3));
$m3 = schedule_service()->open($SC, $tgl(3), '', $admin);
$assert($m3 > 0 && (int) $satu("SELECT COUNT(*) FROM absensi_santri WHERE pertemuan_id = $m3") === 0, 'Web: buka pertemuan tanpa 036 berjalan seperti baseline');
$resp = teacher_api_service()->openMeeting($SC, ['date' => $tgl(4), 'idempotency_key' => 'iop-mig-open-' . $ids['sfx']], $guru)['data'];
$assert(array_keys($resp['students'][0]) === ['student_id', 'nis', 'name', 'class', 'attendance', 'izin'] && array_filter(array_column($resp['students'], 'izin')) === [],
    'REST tanpa 036: kunci lama tetap, `izin` selalu null');
$hasil = schedule_service()->refreshParticipants($m3, $admin);
$assert(array_keys($hasil) === ['ditambah', 'dikeluarkan', 'dipertahankan'], 'Perbarui tanpa 036: hasil/audit identik baseline (tanpa kunci izin_*)');
$respR = teacher_api_service()->refreshParticipants($m3, $guru);
$assert(array_keys($respR['participant_changes']) === ['added', 'removed', 'kept_with_attendance'], 'REST perbarui tanpa 036: participant_changes identik baseline');
$rinci = report_service()->meeting($m3, $admin);
$label = izin_service()->approvedCovering([$c1], $tgl(3));
$assert(array_filter(array_column($rinci['students'], 'izin')) === [] && isset($label[$c1]), 'Laporan tanpa 036: `izin` null; label "Sedang izin" dihitung tetap tersedia');

echo "\n=== Naik lagi ===\n";
$sebelumNaik = $sidik();
$assert($migrate('up') === 0 && $kolomAda('izin_pengajuan_id') && $terakhir() === '036_izin_otomatis_pengajian.sql', 'Naik lagi berhasil');
$sesudahNaik = $sidik();
$assert($sebelumNaik === $sesudahNaik, 'Preservasi: isi tabel lama identik sesudah naik lagi');
$assert((int) $satu('SELECT COUNT(*) FROM pertemuan_peserta WHERE izin_pengajuan_id IS NOT NULL') === 0, 'Naik lagi tidak mengisi ulang usulan lama (pertemuan lama tidak diisi ulang)');
$hasil = schedule_service()->refreshParticipants($m3, $admin);
$assert(($hasil['izin_diisi'] ?? null) === 1, 'Sesudah naik lagi, "Perbarui daftar" mengisi kembali usulan pertemuan Dibuka');

echo "\nHasil: $lulus lulus, " . count($gagal) . " gagal\n";
exit($gagal === [] ? 0 : 1);
