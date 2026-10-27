<?php

declare(strict_types=1);

/**
 * Uji migrasi–rollback PRD V7 Fase 1 (030) pada salinan MariaDB/MySQL.
 *
 *   V7F1_TANPA_030=1 V7F1_DB=test_v7_f1_mig_test bash tests/v7_phase1_db.sh
 *   V7F1_DB=test_v7_f1_mig_test php tests/v7_phase1_migration.php
 *
 *   M-1 preflight + manifest pada salinan 029 (data sintetis V1–V6 + ±1.000 izin V2)
 *   M-2 migrate up: verifier + manifest (jumlah baris, sidik jari ID/isi),
 *       CHECKSUM TABLE tabel inti, definisi kolom V2 izin_pengajuan tetap
 *   M-3 idempoten: berkas 030 dijalankan ulang tanpa galat dan tanpa perubahan
 *   M-4 rollback tanpa data V7: preflight lulus, verifier --rollback + manifest,
 *       checksum identik, struktur izin_pengajuan identik dengan sebelum 030
 *   M-5 sesudah rollback: jalur pengurus V2 tetap membuat pengajuan; layanan V7 503
 *   M-6 siklus naik lagi; data wali dibuat; preflight rollback MENOLAK dan penjaga
 *       SQL menghentikan rollback sebelum perubahan skema (030 tetap tercatat)
 *
 * Mengubah salinan; bangun ulang sebelum mengulang.
 */

$root = dirname(__DIR__);
$dbName = getenv('V7F1_DB') ?: 'test_v7_f1_mig_test';
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
$inti = ['perizinan', 'izin_keputusan', 'izin_keputusan_koreksi', 'izin_riwayat_status', 'izin_idempotency_keys', 'notifikasi_outbox',
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
$manifest = sys_get_temp_dir() . '/v7f1-manifest-' . getmypid() . '.json';

// ===================================================================== M-1
echo PHP_EOL . '=== M-1. Preflight pada salinan 029 ===' . PHP_EOL;
$assert((string) $satu('SELECT migration FROM schema_migrations ORDER BY id DESC LIMIT 1') === '029_v6_fase3_tabungan.sql'
    && (int) $satu("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan' AND COLUMN_NAME = 'asal_pengajuan'") === 0,
    'Salinan berada di migrasi 029, tanpa kolom V7');
$izinV2 = (int) $satu('SELECT COUNT(*) FROM izin_pengajuan');
$warisan = (int) $satu('SELECT COUNT(*) FROM izin_pengajuan WHERE is_legacy = 1');
echo '[info] Pengajuan V2: ' . $izinV2 . ' (warisan ' . $warisan . '), perizinan warisan: ' . (int) $satu('SELECT COUNT(*) FROM perizinan')
    . ', keputusan: ' . (int) $satu('SELECT COUNT(*) FROM izin_keputusan') . ', relasi santri–wali: ' . (int) $satu('SELECT COUNT(*) FROM santri_wali') . PHP_EOL;
$assert($izinV2 > 100, 'Salinan memuat data izin V2 sintetis yang cukup untuk uji preservasi');
$pre = $cli('bin/v7_phase1_preflight.php', [$manifest]);
$assert($pre['kode'] === 0 && is_file($manifest), 'Preflight lulus dan manifest ditulis');
$cs0 = $checksum();
$isi0 = $isi();
$st0 = $struktur();
$stV2 = $strukturV2();
$k0 = $kunci();
$assert(count($isi0) >= 18 && count($cs0) >= 15, 'Hash isi ' . count($isi0) . ' tabel inti (izin V2, identitas, penugasan, PSB, V3–V6) dan CHECKSUM ' . count($cs0) . ' tabel tanpa kolom generated tercatat');

// ===================================================================== M-2
echo PHP_EOL . '=== M-2. Migrasi naik ===' . PHP_EOL;
$up = $cli('bin/migrate.php', ['up']);
$assert($up['kode'] === 0 && str_contains($up['out'], '030_v7_fase1_pengajuan_wali.sql'), 'migrate up menerapkan 030');
$ver = $cli('bin/v7_phase1_verify.php', [$manifest]);
$assert($ver['kode'] === 0, 'Verifier + manifest lulus sesudah migrasi');
if ($ver['kode'] !== 0) {
    echo $ver['out'] . PHP_EOL;
}
$cs1 = $checksum();
$isi1 = $isi();
$assert($cs1 === $cs0, 'CHECKSUM tabel inti identik sesudah migrasi' . ($cs1 === $cs0 ? '' : ': berbeda ' . implode(', ', array_keys(array_diff_assoc($cs1, $cs0)))));
$assert($isi1 === $isi0, 'Hash isi seluruh tabel inti identik sesudah migrasi' . ($isi1 === $isi0 ? '' : ': berbeda ' . implode(', ', array_keys(array_diff_assoc($isi1, $isi0)))));
$assert($strukturV2() === $stV2, 'Definisi ' . $nV2 . ' kolom V2 izin_pengajuan tetap (selain ENUM status yang diperluas di ujung)');
$assert((int) $satu("SELECT COUNT(*) FROM izin_pengajuan WHERE asal_pengajuan = 'Pengurus'") === $izinV2 && (int) $satu("SELECT COUNT(*) FROM izin_pengajuan WHERE wali_id IS NOT NULL") === 0,
    'Seluruh baris lama berasal Pengurus, tanpa wali_id');
$assert((int) $satu('SELECT COUNT(*) FROM izin_pengajuan WHERE is_legacy = 1') === $warisan && (int) $satu('SELECT COUNT(*) FROM izin_pembimbing_penetapan') === 0,
    'Baris warisan tetap; tabel penetapan kosong');
$autoInc = (int) $satu("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan'");
$assert($autoInc > (int) $satu('SELECT MAX(id) FROM izin_pengajuan'), 'Penghitung ID melanjutkan ruang ID yang sama (tidak memakai ulang ID V2/warisan)');

// ===================================================================== M-3
echo PHP_EOL . '=== M-3. Idempoten ===' . PHP_EOL;
$stSesudah = $struktur();
$ulang = $db->multi_query((string) file_get_contents($root . '/database/migrations/030_v7_fase1_pengajuan_wali.sql'));
while ($db->more_results() && $db->next_result()) {
}
$assert($ulang && $db->errno === 0 && $struktur() === $stSesudah && $checksum() === $cs0 && $isi() === $isi0, 'Berkas 030 dijalankan ulang tanpa galat dan tanpa perubahan');
$assert($cli('bin/migrate.php', ['up'])['out'] === 'Tidak ada migrasi baru.', 'migrate up kedua: tidak ada migrasi baru');

// ===================================================================== M-4
echo PHP_EOL . '=== M-4. Rollback tanpa data V7 ===' . PHP_EOL;
$rp = $cli('bin/v7_phase1_rollback_preflight.php');
$assert($rp['kode'] === 0, 'Preflight rollback lulus (tidak ada data V7)');
$rb = $cli('bin/migrate.php', ['rollback']);
$assert($rb['kode'] === 0 && str_contains($rb['out'], '030_v7_fase1_pengajuan_wali.sql'), 'Rollback 030 berjalan');
$vr = $cli('bin/v7_phase1_verify.php', ['--rollback', $manifest]);
$assert($vr['kode'] === 0, 'Verifier --rollback + manifest lulus');
if ($vr['kode'] !== 0) {
    echo $vr['out'] . PHP_EOL;
}
$assert($checksum() === $cs0 && $isi() === $isi0 && $struktur() === $st0 && $kunci() === $k0, 'Checksum, hash isi, struktur kolom, dan constraint izin_pengajuan identik dengan sebelum 030');

// ===================================================================== M-5
echo PHP_EOL . '=== M-5. Sesudah rollback ===' . PHP_EOL;
$pengurusA = ['id' => (int) $satu("SELECT id FROM users WHERE username = 'sbx_pengurus_a'"), 'roles' => ['pengurus'], 'guru_id' => null];
$a1 = (int) $satu("SELECT id FROM santri WHERE nis = 'SBX-S-001'");
$v2 = izin_workflow_service()->create($pengurusA, ['santri_id' => $a1, 'tgl_izin' => '2027-05-03', 'tgl_kembali' => '2027-05-03', 'alasan' => 'Pulang sesudah rollback'], 'v7-mig-v2-0001', ['ip' => null, 'user_agent' => null], 'pengurus');
$assert(in_array($v2['status'], ['Diajukan', 'Perlu Penetapan Admin'], true) && (int) $v2['id'] > 0, 'Jalur pengurus V2 tetap membuat pengajuan tanpa skema 030 (' . $v2['status'] . ')');
$ortuA = ['id' => (int) $satu("SELECT id FROM users WHERE username = 'sbx_ortu_a'"), 'roles' => [], 'guru_id' => null];
try {
    izin_wali_service()->akses($ortuA);
    $status503 = 0;
} catch (\App\Izin\IzinException $e) {
    $status503 = $e->status();
}
$assert($status503 === 503, 'Layanan V7 menjawab 503 "belum dipasang" tanpa skema 030');

// ===================================================================== M-6
echo PHP_EOL . '=== M-6. Naik lagi, data wali, rollback ditolak ===' . PHP_EOL;
$up2 = $cli('bin/migrate.php', ['up']);
$assert($up2['kode'] === 0 && $cli('bin/v7_phase1_verify.php')['kode'] === 0, 'Siklus naik–turun–naik: 030 terpasang lagi dan verifier lulus');
$hasil = izin_wali_service()->buat($ortuA, ['santri_id' => $a1, 'tgl_izin' => '2027-06-01', 'tgl_kembali' => '2027-06-02', 'alasan' => 'Acara keluarga'], 'v7-mig-wali-0001', ['ip' => null, 'user_agent' => null]);
$calon = count((new \App\Izin\PembimbingRouter($db))->candidates($a1, date('Y-m-d')));
$assert($hasil['status'] === ($calon === 1 ? 'Menunggu Pembimbing' : 'Perlu Penetapan Pembimbing'), 'Wali fixture V2 (anak A1) membuat pengajuan; tahap sesuai jumlah pembimbing sah (' . $calon . ')');
$stData = $struktur();
$rp2 = $cli('bin/v7_phase1_rollback_preflight.php');
$assert($rp2['kode'] === 1 && str_contains($rp2['out'], 'DITOLAK'), 'Preflight rollback menolak karena ada data V7');
$rb2 = $cli('bin/migrate.php', ['rollback']);
$assert($rb2['kode'] !== 0 && (string) $satu('SELECT migration FROM schema_migrations ORDER BY id DESC LIMIT 1') === '030_v7_fase1_pengajuan_wali.sql'
    && $struktur() === $stData && (int) $satu("SELECT COUNT(*) FROM izin_pengajuan WHERE asal_pengajuan = 'Wali'") === 1,
    'Penjaga SQL menghentikan rollback sebelum perubahan skema; 030 tetap tercatat; data wali utuh');
$assert($cli('bin/v7_phase1_verify.php')['kode'] === 0, 'Verifier tetap lulus sesudah rollback yang ditolak');

@unlink($manifest);
echo PHP_EOL . 'Hasil: ' . $lulus . ' lulus, ' . count($gagal) . ' gagal' . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
