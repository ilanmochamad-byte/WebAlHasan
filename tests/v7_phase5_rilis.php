<?php

declare(strict_types=1);

/**
 * Uji kesiapan rilis PRD V7 (Fase 5) pada SALINAN database uji.
 *
 *   V7_RUN_RILIS=1 php tests/v7_phase5_rilis.php
 *
 * Langkah (DB `test_*_test` tersendiri, dibangun ulang):
 *   R-1  salinan V6 (029 terakhir, tanpa 030–031) ditambah baris `perizinan`
 *        warisan V1; preflight rilis lulus dan menulis manifest privat 0600;
 *        `--rilis` mencocokkan 31 migrasi + 2 rollback dengan manifest
 *        ter-commit; manifest diubah/terpotong ditolak;
 *   R-2  jam basis data ≠ jam aplikasi (APP_TIMEZONE berbeda) ditolak;
 *   R-3  `migrate up` memasang 030–031 sekaligus; `bin/v7_verify.php` +
 *        manifest pra-migrasi lulus (jumlah dan isi seluruh tabel lama,
 *        irisan V2, perizinan warisan);
 *   R-4  pemasangan sebagian (030 tanpa 031) ditolak preflight dan verifier;
 *   R-5  turun tanpa data (031 → 030, preflight rollback lulus) mengembalikan
 *        snapshot pra-migrasi persis; naik lagi lulus verifier — naik–turun–naik;
 *   R-6  dengan data V7: pengajuan yang masih di tahap Fase 1 mengizinkan
 *        rollback 031 tetapi rollback 030 DITOLAK (preflight + penjaga SQL);
 *        sesudah tinjauan/keputusan, rollback 031 DITOLAK; data dan catatan
 *        migrasi tetap, tidak ada tabel penjaga tersisa;
 *   R-7  latihan backup → restore → verifikasi → rollback ditolak, dua metode
 *        (BackupWriter aplikasi dan mariadb-dump runbook);
 *   R-8  kontrol pengrusakan: verifier mendeteksi audit yang dihapus, transisi
 *        tidak sah, keputusan ganda yang disisipkan paksa, referensi yatim, dan
 *        perubahan isi V2/warisan terhadap manifest.
 */

if (getenv('V7_RUN_RILIS') !== '1') {
    fwrite(STDERR, "Set V7_RUN_RILIS=1 untuk menjalankan.\n");
    exit(2);
}
$root = dirname(__DIR__);
$dbA = getenv('V7F5_RILIS_DB') ?: 'test_v7_f5_rilis_test';
if (!preg_match('/^test_.*_test$/', $dbA)) {
    exit(2);
}

$lulus = 0;
$gagal = [];
$assert = static function (bool $ok, string $pesan) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    $ok ? $lulus++ : $gagal[] = $pesan;
};
/** @return array{0:int, 1:string} */
$sh = static function (string $perintah, string $db, array $env = []) use ($root): array {
    $proc = proc_open(['bash', '-c', $perintah], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, array_merge(getenv(), ['DB_NAME' => $db], $env));
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    $kode = proc_close($proc);

    return [$kode, (string) $out];
};
[$kode] = $sh('V7F5_TANPA_V7=1 V7F5_DB=' . $dbA . ' bash tests/v7_phase5_db.sh', $dbA);
putenv('DB_NAME=' . $dbA);
require_once $root . '/app/bootstrap.php';
require_once $root . '/bin/_v7_rilis.php';
mysqli_report(MYSQLI_REPORT_OFF);
$a = new mysqli((string) app_config('database.host'), (string) app_config('database.username'), (string) app_config('database.password'), $dbA, (int) app_config('database.port'));
$a->set_charset('utf8mb4');
$scratch = sys_get_temp_dir() . '/v7f5-rilis-' . bin2hex(random_bytes(3));
mkdir($scratch, 0700);
$n = static fn (string $sql): int => (int) $a->query($sql)->fetch_row()[0];
$terakhir = static fn (): string => (string) $a->query('SELECT migration FROM schema_migrations ORDER BY id DESC LIMIT 1')->fetch_row()[0];
$tabelV7 = static fn (): int => $n("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('" . implode("','", V7_TABEL) . "')");
$penjaga = static fn (): int => $n("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '%rollback\\_guard%'");
$snapshot = static function (string $berkas): array {
    $m = json_decode((string) file_get_contents($berkas), true);

    return [$m['legacy_counts'] ?? null, $m['isi_tabel_lama'] ?? null, $m['irisan_v2'] ?? null];
};

// ===================================================================== R-1
echo PHP_EOL . '=== R-1. Titik awal V6, preflight dan manifest rilis ===' . PHP_EOL;
$assert($kode === 0 && $terakhir() === '029_v6_fase3_tabungan.sql' && $tabelV7() === 0 && $n("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'izin_pengajuan' AND COLUMN_NAME = 'asal_pengajuan'") === 0, 'Salinan V6 (029 terakhir), tanpa kolom/tabel V7: ' . $dbA);
$santri = $n("SELECT MIN(id) FROM santri WHERE is_active = 1");
$a->query("INSERT INTO perizinan (id, id_santri, tgl_izin, tgl_kembali, alasan, status) VALUES
    (990001, $santri, '2024-01-02', '2024-01-03', 'Warisan V1 sintetis satu', 'Disetujui'),
    (990002, $santri, '2024-02-02', '2024-02-04', 'Warisan V1 sintetis dua', 'Ditolak'),
    (990003, $santri, '2024-03-02', '2024-03-02', 'Warisan V1 sintetis tiga', 'Pending')");
$assert($n('SELECT COUNT(*) FROM perizinan WHERE id >= 990001') === 3 && $n("SELECT COUNT(*) FROM izin_pengajuan") > 0 && $n('SELECT COUNT(*) FROM izin_keputusan') > 0,
    'Data lama tersedia: tiga baris perizinan warisan V1, pengajuan dan keputusan V2');
$pre = $scratch . '/manifest-pra.json';
[$kode, $out] = $sh('php bin/v7_preflight.php ' . escapeshellarg($pre) . ' --rilis=docs/phase-v7-5/manifest-migrasi.json', $dbA);
$m = json_decode((string) @file_get_contents($pre), true);
$assert($kode === 0 && str_contains($out, 'Preflight V7: lulus.') && ($m['v7_terpasang'] ?? null) === array_fill_keys(V7_MIGRASI, false) && ($m['isi_v7'] ?? null) === []
    && (fileperms($pre) & 0777) === 0600 && ($m['irisan_v2']['perizinan']['baris'] ?? 0) === 3, 'Preflight sebelum migrasi lulus; manifest privat 0600 memuat perizinan warisan, tanpa isi V7');
$assert(substr_count($out, 'SHA-256 sesuai manifest rilis') === 33 && !str_contains($out, '[gagal]'), 'Preflight --rilis: 31 migrasi + 2 rollback = manifest ter-commit (33 SHA cocok)');
$rilis = json_decode((string) file_get_contents($root . '/docs/phase-v7-5/manifest-migrasi.json'), true);
$ubah = $rilis;
$ubah['rollbacks'][1]['sha256'] = str_repeat('0', 64);
file_put_contents($scratch . '/rilis-ubah.json', json_encode($ubah));
[$kode, $out] = $sh('php bin/v7_preflight.php --rilis=' . escapeshellarg($scratch . '/rilis-ubah.json'), $dbA);
$assert($kode === 1 && str_contains($out, '[gagal] SHA-256 sesuai manifest rilis: database/rollbacks/031_v7_fase2_tinjauan_pembimbing.sql'), 'Manifest rilis dengan hash rollback 031 berbeda ditolak');
$potong = $rilis;
array_pop($potong['migrations']);
$potong['rollbacks'] = [];
file_put_contents($scratch . '/rilis-potong.json', json_encode($potong));
[$kode, $out] = $sh('php bin/v7_preflight.php --rilis=' . escapeshellarg($scratch . '/rilis-potong.json'), $dbA);
$assert($kode === 1 && str_contains($out, '[gagal] Daftar lengkap dan unik manifest rilis: migrations') && str_contains($out, '[gagal] Daftar lengkap dan unik manifest rilis: rollbacks'),
    'Manifest rilis terpotong (tanpa 031, tanpa rollback) ditolak walau hash sisanya cocok');

// ===================================================================== R-2
echo PHP_EOL . '=== R-2. Jam basis data vs aplikasi ===' . PHP_EOL;
[$kode, $out] = $sh('php bin/v7_preflight.php', $dbA, ['APP_TIMEZONE' => 'Pacific/Kiritimati']);
$assert($kode === 1 && preg_match('/\[gagal\] Jam basis data .* selisih \d+ detik/', $out) === 1, 'APP_TIMEZONE berbeda dengan zona waktu DB → preflight gagal (tanggal penugasan aktif/laporan bergeser)');

// ===================================================================== R-3
echo PHP_EOL . '=== R-3. Migrasi 030–031 sekaligus, verifier + manifest ===' . PHP_EOL;
[$kode, $out] = $sh('php bin/migrate.php up', $dbA);
\App\Database\Schema::forget();
$assert($kode === 0 && preg_match_all('/^- 03[01]_v7_/m', $out) === 2 && $terakhir() === V7_MIGRASI[1] && $tabelV7() === 3, 'migrate up memasang 030 dan 031 (hanya keduanya)');
[$kode, $out] = $sh('php bin/v7_verify.php ' . escapeshellarg($pre), $dbA);
$assert($kode === 0 && str_contains($out, 'Ringkasan verifier V7:') && str_contains($out, '[lulus] Isi seluruh') && str_contains($out, '[lulus] Irisan V2 perizinan tetap (3 baris)'),
    'bin/v7_verify.php + manifest pra-migrasi lulus: jumlah dan isi seluruh tabel lama, irisan V2, perizinan warisan tetap');
echo '    ' . (preg_match('/Ringkasan verifier V7: .*/', $out, $mm) ? $mm[0] : '') . PHP_EOL;

// ===================================================================== R-4
echo PHP_EOL . '=== R-4. Pemasangan sebagian ditolak ===' . PHP_EOL;
[$kp] = $sh('php bin/v7_phase2_rollback_preflight.php', $dbA);
[$kr] = $sh('php bin/migrate.php rollback', $dbA);
$assert($kp === 0 && $kr === 0 && $terakhir() === V7_MIGRASI[0], 'Rollback 031 tanpa data: preflight lulus, migrate rollback berhasil (030 tersisa)');
[$kode, $out] = $sh('php bin/v7_preflight.php', $dbA);
$assert($kode === 1 && str_contains($out, '[gagal] Tidak ada pemasangan sebagian 030–031'), 'Preflight menolak keadaan 030 tanpa 031');
[$kode, $out] = $sh('php bin/v7_verify.php', $dbA);
$assert($kode === 1 && str_contains($out, '[gagal] Migrasi 030 dan 031 tercatat'), 'Verifier menolak rilis sebagian');

// ===================================================================== R-5
echo PHP_EOL . '=== R-5. Turun ke V6 lalu naik lagi ===' . PHP_EOL;
[$kp] = $sh('php bin/v7_phase1_rollback_preflight.php', $dbA);
[$kr] = $sh('php bin/migrate.php rollback', $dbA);
\App\Database\Schema::forget();
$assert($kp === 0 && $kr === 0 && $terakhir() === '029_v6_fase3_tabungan.sql' && $tabelV7() === 0 && $penjaga() === 0, 'Rollback 030 tanpa data: preflight lulus, kembali ke 029, 0 tabel V7/penjaga');
$turun = $scratch . '/manifest-turun.json';
[$kode] = $sh('php bin/v7_preflight.php ' . escapeshellarg($turun), $dbA);
$assert($kode === 0 && $snapshot($turun) === $snapshot($pre), 'Sesudah turun: jumlah dan isi seluruh tabel lama serta irisan V2 identik dengan pra-migrasi (ENUM/kolom 030 dilepas bersih)');
[$kode] = $sh('php bin/migrate.php up', $dbA);
[$kv, $out] = $sh('php bin/v7_verify.php ' . escapeshellarg($pre), $dbA);
$assert($kode === 0 && $kv === 0, 'Naik lagi: verifier + manifest pra-migrasi lulus (naik–turun–naik)');

// ===================================================================== R-6
echo PHP_EOL . '=== R-6. Penjaga rollback dengan data V7 ===' . PHP_EOL;
[$k1] = $sh('php tests/v7_phase1_seed.php', $dbA);
[$k2] = $sh('php tests/v7_phase2_seed.php', $dbA);
$assert($k1 === 0 && $k2 === 0, 'Fixture sintetis V7 Fase 1–2 ditambahkan sesudah migrasi');
\App\Database\Schema::forget();
$izin = izin_wali_service();
$akun = static function (string $u) use ($a): array {
    $row = $a->query("SELECT u.id, u.guru_id, GROUP_CONCAT(r.slug) AS roles FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
        WHERE u.username = '$u' GROUP BY u.id, u.guru_id")->fetch_assoc();

    return ['id' => (int) $row['id'], 'roles' => explode(',', (string) $row['roles']), 'guru_id' => $row['guru_id'] === null ? null : (int) $row['guru_id']];
};
$meta = ['ip' => '127.0.0.1', 'user_agent' => 'uji-v7-f5-rilis'];
$wA = $akun('sbx_v7_wali_a');
$pb1 = $akun('sbx_v7_pembimbing_satu');
$mA = $akun('sbx_murobi_a');
$sSatu = $n("SELECT id FROM santri WHERE nis = 'V7S0001'");
$sCad = $n("SELECT id FROM santri WHERE nis = 'V7S0008'");
$id1 = (int) $izin->buat($wA, ['santri_id' => $sSatu, 'tgl_izin' => '2027-05-03', 'tgl_kembali' => '2027-05-04', 'alasan' => 'Rilis R-6'], 'v7f5r-1-' . bin2hex(random_bytes(4)), $meta)['id'];
$assert($id1 > 0 && $n("SELECT COUNT(*) FROM izin_pengajuan WHERE asal_pengajuan = 'Wali'") === 1, 'Satu pengajuan wali di tahap Fase 1 (Menunggu Pembimbing)');
[$kp] = $sh('php bin/v7_phase2_rollback_preflight.php', $dbA);
[$kr] = $sh('php bin/migrate.php rollback', $dbA);
$assert($kp === 0 && $kr === 0 && $terakhir() === V7_MIGRASI[0], 'Rollback 031 diizinkan (belum ada data Fase 2)');
$isiSebelum = v7_hash_kueri($a, "SELECT * FROM izin_pengajuan WHERE asal_pengajuan = 'Wali'");
[$kp, $op] = $sh('php bin/v7_phase1_rollback_preflight.php', $dbA);
[$kr, $or] = $sh('php bin/migrate.php rollback', $dbA);
$assert($kp === 1 && str_contains($op, 'Rollback 030 DITOLAK') && $kr !== 0 && str_contains($or, 'v7_fase1_rollback_guard_kosong'),
    'Rollback 030 berisi pengajuan wali DITOLAK oleh preflight dan penjaga SQL');
$assert($terakhir() === V7_MIGRASI[0] && v7_hash_kueri($a, "SELECT * FROM izin_pengajuan WHERE asal_pengajuan = 'Wali'") === $isiSebelum && $penjaga() === 0,
    'Sesudah penolakan: 030 tetap tercatat, isi pengajuan wali utuh, tidak ada tabel penjaga');
[$kode] = $sh('php bin/migrate.php up', $dbA);
\App\Database\Schema::forget();
$izin = izin_wali_service();
$izin->tinjau($pb1, $id1, 'teruskan', null, 1, 'v7f5r-2-' . bin2hex(random_bytes(4)), $meta);
izin_workflow_service()->decide($mA, $id1, 'Disetujui', 'Rilis R-6', null, 2, 'v7f5r-3-' . bin2hex(random_bytes(4)), $meta, 'murobi');
$id2 = (int) $izin->buat($wA, ['santri_id' => $sCad, 'tgl_izin' => '2027-05-10', 'tgl_kembali' => '2027-05-11', 'alasan' => 'Rilis R-6 kedua'], 'v7f5r-4-' . bin2hex(random_bytes(4)), $meta)['id'];
$izin->tinjau($pb1, $id2, 'minta_perbaikan', 'Perjelas', 1, 'v7f5r-5-' . bin2hex(random_bytes(4)), $meta);
$izin->perbaiki($wA, $id2, ['tgl_izin' => '2027-05-10', 'tgl_kembali' => '2027-05-12', 'alasan' => 'Rilis R-6 kedua diperjelas'], 2, 'v7f5r-6-' . bin2hex(random_bytes(4)), $meta);
$izin->batalkan($wA, $id2, 'Tidak jadi', 3, 'v7f5r-7-' . bin2hex(random_bytes(4)), $meta);
$assert($kode === 0 && $n("SELECT COUNT(*) FROM izin_keputusan WHERE pengajuan_id = $id1") === 1 && $n("SELECT COUNT(*) FROM izin_wali_perbaikan") === 1,
    '031 dipasang lagi; teruskan, putus, perbaiki, batal tercatat');
$isiV7 = [v7_hash_kueri($a, 'SELECT * FROM izin_pembimbing_tinjauan'), v7_hash_kueri($a, "SELECT * FROM izin_pengajuan WHERE asal_pengajuan = 'Wali'")];
[$kp, $op] = $sh('php bin/v7_phase2_rollback_preflight.php', $dbA);
[$kr, $or] = $sh('php bin/migrate.php rollback', $dbA);
$assert($kp === 1 && $kr !== 0 && str_contains($or, 'v7_fase2_rollback_guard_kosong') && $terakhir() === V7_MIGRASI[1] && $penjaga() === 0
    && [v7_hash_kueri($a, 'SELECT * FROM izin_pembimbing_tinjauan'), v7_hash_kueri($a, "SELECT * FROM izin_pengajuan WHERE asal_pengajuan = 'Wali'")] === $isiV7,
    'Rollback 031 berisi tinjauan/perbaikan/keputusan DITOLAK (preflight + penjaga SQL); data dan catatan migrasi tetap');
[$kode, $out] = $sh('php bin/v7_verify.php', $dbA);
$assert($kode === 0, 'Verifier lulus pada salinan berisi data V7 sesudah penolakan rollback');

// ===================================================================== R-7
echo PHP_EOL . '=== R-7. Latihan backup → restore → verifikasi ===' . PHP_EOL;
foreach (['writer', 'dump'] as $metode) {
    [$kode, $out] = $sh('V7_DRILL=1 php bin/v7_backup_restore_drill.php --metode=' . $metode, $dbA);
    preg_match('/Ringkasan latihan: (\d+) lulus, (\d+) gagal/', $out, $mm);
    $assert($kode === 0 && ($mm[2] ?? '1') === '0', "Latihan $metode: " . ($mm[0] ?? 'tanpa ringkasan'));
    file_put_contents($scratch . '/drill-' . $metode . '.txt', $out);
}
[$kode, $out] = $sh('V7_DRILL=1 php bin/v7_backup_restore_drill.php', 'produksi_ilan');
$assert($kode === 2 && str_contains($out, 'wajib test_*_test'), 'Latihan menolak DB bukan test_*_test');
[$kode, $out] = $sh('php bin/v7_backup_restore_drill.php', $dbA);
$assert($kode === 2 && str_contains($out, 'V7_DRILL=1'), 'Latihan menolak tanpa V7_DRILL=1');

// ===================================================================== R-8
echo PHP_EOL . '=== R-8. Kontrol pengrusakan (verifier harus gagal) ===' . PHP_EOL;
$kontrol = static function (string $label, string $rusak, string $pulih, string $pola, ?string $manifest = null) use ($a, $sh, $dbA, $assert): void {
    $a->query('SET FOREIGN_KEY_CHECKS = 0');
    $ok1 = $a->multi_query($rusak);
    while ($a->more_results() && $a->next_result()) {
    }
    [$kode, $out] = $sh('php bin/v7_verify.php' . ($manifest === null ? '' : ' ' . escapeshellarg($manifest)), $dbA);
    $a->multi_query($pulih);
    while ($a->more_results() && $a->next_result()) {
    }
    $a->query('SET FOREIGN_KEY_CHECKS = 1');
    [$kodeLagi] = $sh('php bin/v7_verify.php' . ($manifest === null ? '' : ' ' . escapeshellarg($manifest)), $dbA);
    $assert($ok1 && $kode === 1 && str_contains($out, '[gagal] ' . $pola) && $kodeLagi === 0, 'Kontrol ' . $label . ': verifier gagal pada "' . $pola . '", lulus lagi sesudah dipulihkan');
};
$audit = $a->query("SELECT * FROM audit_logs WHERE action = 'izin_wali_diteruskan' ORDER BY id LIMIT 1")->fetch_assoc();
$kontrol('audit dihapus', 'DELETE FROM audit_logs WHERE id = ' . (int) $audit['id'],
    'INSERT INTO audit_logs (id, actor_user_id, action, entity_type, entity_id, before_json, after_json, ip_address, user_agent, created_at) VALUES ('
    . implode(', ', array_map(static fn ($v) => $v === null ? 'NULL' : "'" . $a->real_escape_string((string) $v) . "'", [$audit['id'], $audit['actor_user_id'], $audit['action'], $audit['entity_type'],
        $audit['entity_id'], $audit['before_json'], $audit['after_json'], $audit['ip_address'], $audit['user_agent'], $audit['created_at']])) . ')',
    'Jumlah peristiwa riwayat = jumlah audit');
$rw = (int) $a->query("SELECT id FROM izin_riwayat_status WHERE pengajuan_id = $id1 AND peristiwa = 'tinjauan_diteruskan'")->fetch_row()[0];
$kontrol('transisi tidak sah', "UPDATE izin_riwayat_status SET status_sebelum = 'Perlu Penetapan Pembimbing' WHERE id = $rw",
    "UPDATE izin_riwayat_status SET status_sebelum = 'Menunggu Pembimbing' WHERE id = $rw", 'Setiap riwayat baris Wali adalah transisi sah');
$kontrol('status kini tanpa riwayat', "UPDATE izin_pengajuan SET status = 'Ditolak' WHERE id = $id1; UPDATE izin_keputusan SET hasil = 'Ditolak' WHERE pengajuan_id = $id1",
    "UPDATE izin_pengajuan SET status = 'Disetujui' WHERE id = $id1; UPDATE izin_keputusan SET hasil = 'Disetujui' WHERE pengajuan_id = $id1", 'Rantai riwayat tiap pengajuan Wali bersambung');
$kontrol('keputusan untuk pengajuan dibatalkan', "INSERT INTO izin_keputusan (id, pengajuan_id, hasil, alasan, diputus_oleh_user_id, kapasitas, diputus_pada) VALUES (999901, $id2, 'Disetujui', 'Paksa', {$mA['id']}, 'Murobi', NOW())",
    'DELETE FROM izin_keputusan WHERE id = 999901', 'Tidak ada keputusan untuk pengajuan yang belum diputus');
$kontrol('referensi yatim', "INSERT INTO izin_riwayat_status (pengajuan_id, peristiwa, status_sebelum, status_sesudah, pelaku_user_id) VALUES (98765432, 'keputusan', 'Diajukan', 'Disetujui', {$mA['id']})",
    'DELETE FROM izin_riwayat_status WHERE pengajuan_id = 98765432', 'Nol referensi yatim');
// Manifest diambil sesudah kontrol tanpa manifest: pemulihan status di atas
// memperbarui `updated_at` baris pengajuan, yang memang ikut di-hash.
$pasca = $scratch . '/manifest-pasca.json';
$sh('php bin/v7_preflight.php ' . escapeshellarg($pasca), $dbA);
$kontrol('isi perizinan warisan diubah', "UPDATE perizinan SET alasan = 'Diubah diam-diam' WHERE id = 990002",
    "UPDATE perizinan SET alasan = 'Warisan V1 sintetis dua' WHERE id = 990002", 'Irisan V2 perizinan tetap', $pasca);
[$v2, $catatan, $diubah] = $a->query("SELECT id, catatan_pengurus, updated_at FROM izin_pengajuan WHERE asal_pengajuan = 'Pengurus' ORDER BY id LIMIT 1")->fetch_row();
$kontrol('kolom V2 baris pengurus diubah', "UPDATE izin_pengajuan SET catatan_pengurus = 'Diubah diam-diam' WHERE id = $v2",
    'UPDATE izin_pengajuan SET catatan_pengurus = ' . ($catatan === null ? 'NULL' : "'" . $a->real_escape_string((string) $catatan) . "'") . ", updated_at = '$diubah' WHERE id = $v2",
    'Irisan V2 izin_pengajuan_v2 tetap', $pasca);
$kontrol('baris disisipkan ke tabel lama', "INSERT INTO kamar (id, nama_kamar, kapasitas) VALUES (999902, 'Kamar sisipan kontrol', 1)", 'DELETE FROM kamar WHERE id = 999902', 'Jumlah baris seluruh tabel lama tetap', $pasca);
[$alamat, $santriDiubah] = $a->query("SELECT alamat, updated_at FROM santri WHERE id = $santri")->fetch_row();
$kontrol('isi identitas santri diubah', "UPDATE santri SET alamat = CONCAT(alamat, '#') WHERE id = $santri",
    "UPDATE santri SET alamat = '" . $a->real_escape_string((string) $alamat) . "', updated_at = '$santriDiubah' WHERE id = $santri", 'Isi seluruh', $pasca);

echo PHP_EOL . 'Hasil: ' . $lulus . ' lulus, ' . count($gagal) . ' gagal' . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
