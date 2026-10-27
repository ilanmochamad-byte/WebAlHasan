<?php

declare(strict_types=1);

/**
 * Uji migrasi–rollback PRD V7 Fase 2 (031) pada salinan MariaDB/MySQL.
 *
 *   V7F2_TANPA_031=1 V7F2_DB=test_v7_f2_mig_test bash tests/v7_phase2_db.sh
 *   V7F2_DB=test_v7_f2_mig_test php tests/v7_phase2_migration.php
 *
 *   N-1 salinan 030 berisi pengajuan wali tahap Fase 1 (menunggu pembimbing,
 *       penetapan admin) dan ±1.000 izin V2; layanan Fase 2 → 503; preflight + manifest
 *   N-2 migrate up: hanya 031; verifier + manifest; hash isi/CHECKSUM tabel inti
 *       (termasuk izin_pengajuan dan penetapan) dan struktur izin_pengajuan tetap
 *   N-3 idempoten: berkas 031 dijalankan ulang tanpa galat dan tanpa perubahan
 *   N-4 rollback dengan data Fase 1 saja: preflight lulus, verifier --rollback +
 *       manifest, verifier Fase 1 lulus, jalur Fase 1 dan V2 tetap berjalan
 *   N-5 naik lagi; data Fase 2 (pembatalan wali, tinjauan); preflight rollback
 *       MENOLAK dan penjaga SQL menghentikan rollback (031 tetap tercatat)
 *
 * Mengubah salinan; bangun ulang sebelum mengulang.
 */

$root = dirname(__DIR__);
$dbName = getenv('V7F2_DB') ?: 'test_v7_f2_mig_test';
if (!preg_match('/^test_.*_test$/', $dbName)) {
    fwrite(STDERR, "DB uji harus bernama test_*_test.\n");
    exit(2);
}
putenv('DB_NAME=' . $dbName);
require_once $root . '/app/bootstrap.php';

use App\Izin\IzinException;

$lulus = 0;
$gagal = [];
$assert = static function (bool $ok, string $pesan) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    $ok ? $lulus++ : $gagal[] = $pesan;
};
$db = app_db();
$satu = static function (string $sql) use ($db): mixed {
    $r = $db->query($sql);

    return $r === false ? throw new RuntimeException($db->error) : ($r->fetch_row()[0] ?? null);
};
$cli = static function (string $script, array $args = []) use ($root, $dbName): array {
    $cmd = 'DB_NAME=' . escapeshellarg($dbName) . ' php ' . escapeshellarg($root . '/' . $script);
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg($a);
    }
    exec($cmd . ' 2>&1', $out, $kode);
    \App\Database\Schema::forget();

    return ['kode' => $kode, 'out' => implode("\n", $out)];
};
$inti = ['izin_pengajuan', 'izin_pembimbing_penetapan', 'perizinan', 'izin_keputusan', 'izin_keputusan_koreksi', 'izin_riwayat_status', 'izin_idempotency_keys', 'notifikasi_outbox',
    'santri', 'wali', 'santri_wali', 'users', 'user_roles', 'pengurus', 'guru', 'pembimbing_assignments', 'murobi_assignments',
    'plotting_kamar', 'plotting_kelas', 'tahun_ajaran', 'psb_pendaftar', 'bsa_tagihan', 'bsa_tabungan_transaksi', 'kesehatan_kunjungan', 'v3_pelanggaran', 'v4_rapor'];
$inti = array_values(array_filter($inti, static fn (string $t): bool => (int) $satu("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$t'") === 1));
// Tabel dengan kolom generated (mis. `santri_wali.active_guard`) dikecualikan
// dari CHECKSUM TABLE: pada MariaDB 12 lokal nilainya berubah sesudah ALTER tabel
// lain walau isi barisnya sama. Seluruh tabel inti tetap dibandingkan lewat hash
// isi kolom dasar di bawah (`$isi`).
$denganGenerated = array_map(static fn (array $r): string => (string) $r[0], $db->query("SELECT DISTINCT TABLE_NAME FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND EXTRA LIKE '%GENERATED%'")->fetch_all());
$checksum = static function () use ($db, $inti, $denganGenerated): array {
    $hasil = [];
    $tabel = array_values(array_diff($inti, $denganGenerated));
    foreach ($db->query('CHECKSUM TABLE ' . implode(', ', array_map(static fn ($t) => '`' . $t . '`', $tabel)))->fetch_all(MYSQLI_ASSOC) as $row) {
        $hasil[(string) $row['Table']] = (string) $row['Checksum'];
    }

    return $hasil;
};
$db->query('SET SESSION group_concat_max_len = 4294967295');
$isi = static function () use ($db, $inti): array {
    $hasil = [];
    foreach ($inti as $t) {
        $kolom = array_map(static fn (array $r): string => 'COALESCE(CAST(`' . $r[0] . '` AS CHAR), \'<null>\')', $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$t' AND EXTRA NOT LIKE '%GENERATED%' ORDER BY ORDINAL_POSITION")->fetch_all());
        $hasil[$t] = (string) $db->query('SELECT SHA2(COALESCE(GROUP_CONCAT(CONCAT_WS(\'|\', ' . implode(', ', $kolom) . ') ORDER BY 1 SEPARATOR \'\n\'), \'\'), 256) FROM `' . $t . '`')->fetch_row()[0];
    }

    return $hasil;
};
$struktur = static fn (): string => (string) $satu("SELECT SHA2(GROUP_CONCAT(CONCAT_WS('|', COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COALESCE(COLUMN_DEFAULT, '-'), ORDINAL_POSITION) ORDER BY ORDINAL_POSITION SEPARATOR ';'), 256)
    FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan'");
$nV2 = (int) $satu("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan'");
$strukturV2 = static fn (): string => (string) $satu("SELECT SHA2(GROUP_CONCAT(CONCAT_WS('|', COLUMN_NAME, IF(COLUMN_NAME = 'status', '-', COLUMN_TYPE), IS_NULLABLE, COALESCE(COLUMN_DEFAULT, '-'), ORDINAL_POSITION) ORDER BY ORDINAL_POSITION SEPARATOR ';'), 256)
    FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan' AND ORDINAL_POSITION <= $nV2");
$kunci = static fn (): string => (string) $satu("SELECT SHA2(COALESCE(GROUP_CONCAT(CONSTRAINT_NAME ORDER BY CONSTRAINT_NAME), ''), 256) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan'");
$manifest = sys_get_temp_dir() . '/v7f2-manifest-' . getmypid() . '.json';
$meta = ['ip' => null, 'user_agent' => null];
$akun = static function (string $u) use ($satu): array {
    $id = (int) $satu("SELECT id FROM users WHERE username = '$u'");
    $g = $satu("SELECT guru_id FROM users WHERE id = $id");

    return ['id' => $id, 'roles' => [], 'guru_id' => $g === null ? null : (int) $g];
};
$galat = static function (callable $f): ?int {
    try {
        $f();

        return null;
    } catch (IzinException $e) {
        return $e->status();
    }
};
$wA = $akun('sbx_v7_wali_a');
$pb1 = $akun('sbx_v7_pembimbing_satu');
$admin = $akun('sbx_admin');
$sid = static fn (string $nis): int => (int) $satu("SELECT id FROM santri WHERE nis = '$nis'");

// ===================================================================== N-1
echo PHP_EOL . '=== N-1. Salinan 030 dengan data Fase 1 ===' . PHP_EOL;
$assert((string) $satu('SELECT migration FROM schema_migrations ORDER BY id DESC LIMIT 1') === '030_v7_fase1_pengajuan_wali.sql'
    && (int) $satu("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pembimbing_tinjauan'") === 0,
    'Salinan berada di migrasi 030, tanpa tabel 031');
$w = izin_wali_service();
$idMenunggu = (int) $w->buat($wA, ['santri_id' => $sid('V7S0001'), 'tgl_izin' => '2027-07-01', 'tgl_kembali' => '2027-07-02', 'alasan' => 'Data Fase 1 sebelum 031'], 'v7f2-mig-f1-0001', $meta)['id'];
$idNol = (int) $w->buat($wA, ['santri_id' => $sid('V7S0002'), 'tgl_izin' => '2027-07-01', 'tgl_kembali' => '2027-07-02', 'alasan' => 'Data Fase 1 tanpa pembimbing'], 'v7f2-mig-f1-0002', $meta)['id'];
$idTetap = (int) $w->buat($wA, ['santri_id' => $sid('V7S0003'), 'tgl_izin' => '2027-07-01', 'tgl_kembali' => '2027-07-02', 'alasan' => 'Data Fase 1 dua pembimbing'], 'v7f2-mig-f1-0003', $meta)['id'];
$w->tetapkanPembimbing($admin, $idTetap, (int) $satu("SELECT pengurus_id FROM users WHERE username = 'sbx_v7_pembimbing_dua'"), 'Pembimbing kelas', 1, 'v7f2-mig-f1-0004', $meta);
$assert((int) $satu("SELECT COUNT(*) FROM izin_pengajuan WHERE asal_pengajuan = 'Wali'") === 3 && (int) $satu('SELECT COUNT(*) FROM izin_pembimbing_penetapan') === 1,
    'Tiga pengajuan wali tahap Fase 1 dan satu penetapan pembimbing dibuat tanpa 031');
$assert($galat(fn () => $w->tinjau($pb1, $idMenunggu, 'teruskan', null, 1, 'v7f2-mig-f2-0001', $meta)) === 503
    && $galat(fn () => $w->batalkan($wA, $idMenunggu, 'Belum dipasang', 1, 'v7f2-mig-f2-0002', $meta)) === 503
    && $w->detail($wA, $idMenunggu)['tindakan'] === ['perbaiki' => false, 'batalkan' => false],
    'Tanpa 031: tindakan Fase 2 menjawab 503; rincian tetap terbaca tanpa tindakan');
$izinV2 = (int) $satu("SELECT COUNT(*) FROM izin_pengajuan WHERE asal_pengajuan = 'Pengurus'");
$assert($izinV2 > 100, 'Salinan memuat ' . $izinV2 . ' pengajuan V2/warisan untuk uji preservasi');
$pre = $cli('bin/v7_phase2_preflight.php', [$manifest]);
$assert($pre['kode'] === 0 && is_file($manifest), 'Preflight Fase 2 lulus dan manifest ditulis');
if ($pre['kode'] !== 0) {
    echo $pre['out'] . PHP_EOL;
}
$cs0 = $checksum();
$isi0 = $isi();
$st0 = $struktur();
$k0 = $kunci();
$assert(count($isi0) >= 20, 'Hash isi ' . count($isi0) . ' tabel inti (termasuk izin_pengajuan dan penetapan V7) tercatat');

// ===================================================================== N-2
echo PHP_EOL . '=== N-2. Migrasi naik ===' . PHP_EOL;
$up = $cli('bin/migrate.php', ['up']);
$assert($up['kode'] === 0 && str_contains($up['out'], '031_v7_fase2_tinjauan_pembimbing.sql') && !str_contains($up['out'], '030_'), 'migrate up hanya menerapkan 031');
$ver = $cli('bin/v7_phase2_verify.php', [$manifest]);
$assert($ver['kode'] === 0, 'Verifier Fase 2 + manifest lulus sesudah migrasi');
if ($ver['kode'] !== 0) {
    echo $ver['out'] . PHP_EOL;
}
$assert($checksum() === $cs0, 'CHECKSUM tabel inti identik sesudah migrasi');
$assert($isi() === $isi0, 'Hash isi tabel inti identik sesudah migrasi (pengajuan wali Fase 1 tidak berubah)');
$assert($struktur() === $st0 && $kunci() === $k0, 'Struktur kolom dan constraint izin_pengajuan tidak berubah (031 tanpa ALTER)');
$assert((int) $satu('SELECT COUNT(*) FROM izin_pembimbing_tinjauan') === 0 && (int) $satu('SELECT COUNT(*) FROM izin_wali_perbaikan') === 0, 'Tabel 031 kosong');
$assert($cli('bin/v7_phase1_verify.php')['kode'] === 0, 'Verifier Fase 1 tetap lulus pada data Fase 1 sesudah 031');

// ===================================================================== N-3
echo PHP_EOL . '=== N-3. Idempoten ===' . PHP_EOL;
$skema = static fn (): string => (string) $satu("SELECT SHA2(GROUP_CONCAT(CONCAT_WS('|', TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE) ORDER BY TABLE_NAME, ORDINAL_POSITION), 256)
    FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('izin_pembimbing_tinjauan', 'izin_wali_perbaikan')");
$sk = $skema();
$ulang = $db->multi_query((string) file_get_contents($root . '/database/migrations/031_v7_fase2_tinjauan_pembimbing.sql'));
while ($db->more_results() && $db->next_result()) {
}
$assert($ulang && $db->errno === 0 && $skema() === $sk && $isi() === $isi0, 'Berkas 031 dijalankan ulang tanpa galat dan tanpa perubahan');
$assert($cli('bin/migrate.php', ['up'])['out'] === 'Tidak ada migrasi baru.', 'migrate up kedua: tidak ada migrasi baru');

// ===================================================================== N-4
echo PHP_EOL . '=== N-4. Rollback dengan data Fase 1 saja ===' . PHP_EOL;
$rp = $cli('bin/v7_phase2_rollback_preflight.php');
$assert($rp['kode'] === 0, 'Preflight rollback 031 lulus (hanya data tahap Fase 1)');
$rb = $cli('bin/migrate.php', ['rollback']);
$assert($rb['kode'] === 0 && str_contains($rb['out'], '031_v7_fase2_tinjauan_pembimbing.sql'), 'Rollback 031 berjalan');
$vr = $cli('bin/v7_phase2_verify.php', ['--rollback', $manifest]);
$assert($vr['kode'] === 0, 'Verifier --rollback + manifest lulus');
if ($vr['kode'] !== 0) {
    echo $vr['out'] . PHP_EOL;
}
$assert($checksum() === $cs0 && $isi() === $isi0 && $struktur() === $st0 && $kunci() === $k0, 'Checksum, hash isi, struktur, dan constraint identik dengan sebelum 031');
$assert($cli('bin/v7_phase1_verify.php')['kode'] === 0, 'Verifier Fase 1 lulus sesudah rollback 031');
$w = izin_wali_service();
$baru = $w->buat($wA, ['santri_id' => $sid('V7S0008'), 'tgl_izin' => '2027-07-05', 'tgl_kembali' => '2027-07-05', 'alasan' => 'Sesudah rollback 031'], 'v7f2-mig-f1-0005', $meta);
$assert($baru['status'] === 'Menunggu Pembimbing' && $galat(fn () => $w->tinjau($pb1, (int) $baru['id'], 'teruskan', null, 1, 'v7f2-mig-f2-0003', $meta)) === 503,
    'Sesudah rollback: pengajuan Fase 1 tetap dibuat; tinjauan Fase 2 menjawab 503');
$pengurusA = ['id' => (int) $satu("SELECT id FROM users WHERE username = 'sbx_pengurus_a'"), 'roles' => ['pengurus'], 'guru_id' => null];
$v2 = izin_workflow_service()->create($pengurusA, ['santri_id' => (int) $satu("SELECT id FROM santri WHERE nis = 'SBX-S-001'"), 'tgl_izin' => '2027-07-10', 'tgl_kembali' => '2027-07-10', 'alasan' => 'Jalur V2 sesudah rollback 031'],
    'v7f2-mig-v2-0001', $meta, 'pengurus');
$assert((int) $v2['id'] > 0, 'Jalur pengurus V2 tetap membuat pengajuan sesudah rollback 031');

// ===================================================================== N-5
echo PHP_EOL . '=== N-5. Naik lagi, data Fase 2, rollback ditolak ===' . PHP_EOL;
$up2 = $cli('bin/migrate.php', ['up']);
$assert($up2['kode'] === 0 && $cli('bin/v7_phase2_verify.php')['kode'] === 0, 'Siklus naik–turun–naik: 031 terpasang lagi dan verifier lulus');
$w = izin_wali_service();
$w->batalkan($wA, (int) $baru['id'], 'Rencana berubah', 1, 'v7f2-mig-f2-0004', $meta);
$rp2 = $cli('bin/v7_phase2_rollback_preflight.php');
$assert($rp2['kode'] === 1 && str_contains($rp2['out'], 'DITOLAK'), 'Sesudah pembatalan wali (tanpa tinjauan): preflight rollback menolak');
$rb2 = $cli('bin/migrate.php', ['rollback']);
$assert($rb2['kode'] !== 0 && (string) $satu('SELECT migration FROM schema_migrations ORDER BY id DESC LIMIT 1') === '031_v7_fase2_tinjauan_pembimbing.sql',
    'Penjaga SQL menghentikan rollback; 031 tetap tercatat');
$w->tinjau($pb1, $idMenunggu, 'minta_perbaikan', 'Perjelas', 1, 'v7f2-mig-f2-0005', $meta);
$rb3 = $cli('bin/migrate.php', ['rollback']);
$assert($rb3['kode'] !== 0 && (int) $satu("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pembimbing_tinjauan'") === 1
    && (int) $satu('SELECT COUNT(*) FROM izin_pembimbing_tinjauan') === 1, 'Dengan tinjauan tersimpan: rollback ditolak, tabel dan jejak utuh');
$assert($cli('bin/v7_phase2_verify.php')['kode'] === 0, 'Verifier Fase 2 lulus sesudah rollback yang ditolak');

@unlink($manifest);
echo PHP_EOL . 'Hasil: ' . $lulus . ' lulus, ' . count($gagal) . ' gagal' . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
