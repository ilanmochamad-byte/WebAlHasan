<?php

declare(strict_types=1);

/**
 * Uji migrasi dan rollback 024 (PRD V5 Fase 1) pada SALINAN database uji.
 *
 *   V5_RUN_MIGRATION=1 php tests/v5_phase1_migration.php
 *
 * Langkah (masing-masing DB `test_*_test` tersendiri, dibangun ulang):
 *   M-1  salinan berantai Tahap 7 (fixture V1–V4) dikembalikan ke keadaan
 *        produksi saat ini (023) lewat rollback 024; verifier --rollback;
 *   M-2  preflight menulis manifest + checksum santri/wali/santri_wali/users/
 *        pengurus/penugasan; migrasi 024 dijalankan `bin/migrate.php up`;
 *   M-3  verifier dengan manifest: 5 tabel, 18 kunci asing, 0 referensi yatim,
 *        seluruh jumlah baris tabel lama tetap, checksum tetap;
 *   M-4  SQL 024 dijalankan ulang langsung (idempoten) tanpa galat/perubahan;
 *   M-5  aplikasi pada skema 024: data sintetis lewat layanan, verifier lulus,
 *        preflight rollback MEMBLOKIR karena ada data kesehatan;
 *   M-6  salinan tanpa data kesehatan: preflight rollback lulus, rollback 024,
 *        verifier --rollback dengan manifest, checksum tetap;
 *   M-7  kode Fase 1 sesudah rollback: layanan menjawab 503 "belum dipasang",
 *        pusat penugasan dan capability tetap berjalan;
 *   M-8  migrasi ulang sesudah rollback lulus verifier.
 */

if (getenv('V5_RUN_MIGRATION') !== '1') {
    fwrite(STDERR, "Set V5_RUN_MIGRATION=1 untuk menjalankan.\n");
    exit(2);
}
$root = dirname(__DIR__);
$dbA = 'test_v5_f1_migrasi_test';
$dbB = 'test_v5_f1_rollback_test';

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
putenv('DB_NAME=' . $dbA);
require_once $root . '/app/bootstrap.php';
mysqli_report(MYSQLI_REPORT_OFF);
$buka = static fn (string $db): mysqli => new mysqli((string) app_config('database.host'), (string) app_config('database.username'), (string) app_config('database.password'), $db, (int) app_config('database.port'));
$checksum = static function (mysqli $m): array {
    $hasil = [];
    $r = $m->query('CHECKSUM TABLE santri, wali, santri_wali, users, user_roles, pengurus, guru, tahun_ajaran, murobi_assignments, pembimbing_assignments, pendidikan_assignments, plotting_kamar, plotting_kelas, izin_pengajuan, v3_pelanggaran, v4_rapor, pendaftaran_santri');
    foreach ($r === false ? [] : $r->fetch_all(MYSQLI_ASSOC) as $row) {
        $hasil[(string) $row['Table']] = (string) $row['Checksum'];
    }

    return $hasil;
};
$scratch = sys_get_temp_dir() . '/v5f1-migrasi-' . bin2hex(random_bytes(3));
mkdir($scratch);
$manifest = $scratch . '/manifest.json';

// ===================================================================== M-1
echo '=== M-1. Salinan uji pada keadaan 023 ===' . PHP_EOL;
[$kode] = $sh('TAHAP7_DB=' . $dbA . ' bash tests/redesain_web_tahap7_db.sh', $dbA);
$assert($kode === 0, 'Salinan berantai Tahap 7 (fixture V1–V4 sintetis) dibangun: ' . $dbA);
[$kode, $out] = $sh('php bin/migrate.php rollback', $dbA);
$assert($kode === 0 && str_contains($out, '024_v5_fase1_kesehatan.sql'), 'Rollback 024 mengembalikan salinan ke keadaan 023');
[$kode, $out] = $sh('php bin/v5_phase1_verify.php --rollback', $dbA);
$assert($kode === 0, 'Verifier --rollback: tabel kesehatan tidak ada, 024 tidak tercatat');

// ===================================================================== M-2
echo PHP_EOL . '=== M-2. Preflight dan migrasi ===' . PHP_EOL;
[$kode, $out] = $sh('php bin/v5_phase1_preflight.php ' . escapeshellarg($manifest), $dbA);
$assert($kode === 0 && is_file($manifest) && str_contains($out, 'Migrasi 023 tercatat'), 'Preflight lulus dan menulis manifest');
$m = $buka($dbA);
$sumSebelum = $checksum($m);
$assert(count($sumSebelum) >= 15, 'Checksum ' . count($sumSebelum) . ' tabel inti dicatat sebelum migrasi');
[$kode, $out] = $sh('php bin/migrate.php up', $dbA);
$assert($kode === 0 && str_contains($out, '024_v5_fase1_kesehatan.sql'), 'bin/migrate.php up menerapkan 024');

// ===================================================================== M-3
echo PHP_EOL . '=== M-3. Verifier sesudah migrasi ===' . PHP_EOL;
[$kode, $out] = $sh('php bin/v5_phase1_verify.php ' . escapeshellarg($manifest), $dbA);
$assert($kode === 0 && !str_contains($out, '[gagal]'), 'Verifier dengan manifest lulus (' . substr_count($out, '[lulus]') . ' pemeriksaan)');
$assert(str_contains($out, 'Jumlah referensi yatim tabel kesehatan: 0') && str_contains($out, 'Kunci asing tabel kesehatan terpasang (18)'), '18 kunci asing, 0 referensi yatim');
$assert($checksum($m) === $sumSebelum, 'Checksum santri/wali/santri_wali/users/pengurus/penugasan/V2–V4/PSB tetap sesudah migrasi');
$kolomLama = (int) $m->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('santri','wali','santri_wali','users','pengurus')")->fetch_row()[0];

// ===================================================================== M-4
echo PHP_EOL . '=== M-4. Migrasi idempoten ===' . PHP_EOL;
$baca = static fn (string $k): string => trim((string) shell_exec('grep -E "^' . $k . '=" ' . escapeshellarg($root . '/.env') . ' | head -1 | cut -d= -f2-'));
[$kode, $out] = $sh('MYSQL_PWD=' . escapeshellarg($baca('DB_PASSWORD')) . ' mysql -h' . escapeshellarg((string) app_config('database.host')) . ' -u' . escapeshellarg((string) app_config('database.username')) . ' ' . $dbA . ' < database/migrations/024_v5_fase1_kesehatan.sql 2>&1 | grep -v ssl-verify', $dbA);
$assert(!preg_match('/ERROR/i', $out), 'SQL 024 dijalankan ulang tanpa galat (CREATE TABLE IF NOT EXISTS)');
$assert($checksum($m) === $sumSebelum && (int) $m->query("SELECT COUNT(*) FROM schema_migrations WHERE migration = '024_v5_fase1_kesehatan.sql'")->fetch_row()[0] === 1, 'Jalan ulang tidak mengubah data lama dan tidak menggandakan catatan migrasi');
$sumber = (string) file_get_contents($root . '/database/migrations/024_v5_fase1_kesehatan.sql');
// Klausa kolom/kunci asing (ON UPDATE CURRENT_TIMESTAMP, ON DELETE SET NULL) bukan pernyataan DML.
$kode024 = (string) preg_replace(['/--.*$/m', '/ON (UPDATE|DELETE) (CURRENT_TIMESTAMP|SET NULL)/i'], '', $sumber);
$assert(!preg_match('/\b(DROP|DELETE|UPDATE|TRUNCATE|ALTER|RENAME)\b/i', $kode024) && substr_count($kode024, 'CREATE TABLE IF NOT EXISTS') === 5, 'Migrasi 024 aditif murni: 5 CREATE TABLE IF NOT EXISTS, tanpa DROP/DELETE/UPDATE/ALTER');

// ===================================================================== M-5
echo PHP_EOL . '=== M-5. Data kesehatan memblokir rollback ===' . PHP_EOL;
[$kode] = $sh('php tests/v5_phase1_seed.php', $dbA);
$assert($kode === 0, 'Fixture V5 Fase 1 dimuat pada salinan 024');
$isi = <<<'PHP'
require 'app/bootstrap.php';
$u = (int) app_db()->query("SELECT id FROM users WHERE username='sbx_v5_petugas_b'")->fetch_row()[0];
$s = (int) app_db()->query("SELECT id FROM santri WHERE nis='V5S0001'")->fetch_row()[0];
$h = kesehatan_service()->buat(['id' => $u, 'roles' => []], ['idempotency_key' => 'migrasi-' . bin2hex(random_bytes(6)), 'santri_id' => $s,
    'waktu_kunjungan' => date('Y-m-d H:i', strtotime('-1 hour')), 'keluhan' => 'Keluhan sintetis uji migrasi'], 'web');
kesehatan_service()->detail(['id' => $u, 'roles' => []], $h['id'], 'web');
echo $h['id'];
PHP;
[$kode, $out] = $sh('php -r ' . escapeshellarg($isi), $dbA);
$assert($kode === 0 && (int) $out > 0, 'Satu kunjungan sintetis dicatat lewat layanan');
[$kode, $out] = $sh('php bin/v5_phase1_verify.php ' . escapeshellarg($manifest), $dbA);
$gagalVerifier = substr_count($out, '[gagal]');
$assert(str_contains($out, 'referensi yatim tabel kesehatan: 0') && str_contains($out, '[lulus] Setiap kunjungan punya snapshot versi terkininya') && str_contains($out, '[lulus] audit_logs kesehatan tanpa kolom isi klinis'), 'Verifier dengan data: 0 yatim, snapshot versi lengkap, audit tanpa isi klinis');
$assert($gagalVerifier === substr_count($out, '[gagal] Jumlah baris lama users') + substr_count($out, '[gagal] Jumlah baris lama pengurus') + substr_count($out, '[gagal] Jumlah baris lama santri ') + substr_count($out, '[gagal] Jumlah baris lama user_roles') + substr_count($out, '[gagal] Jumlah baris lama api_tokens'),
    'Perbedaan manifest hanya pada baris fixture V5 yang memang ditambahkan (users/pengurus/santri/user_roles)');
[$kode, $out] = $sh('php bin/v5_phase1_rollback_preflight.php', $dbA);
$assert($kode === 1 && str_contains($out, '[blokir] kesehatan_kunjungan = 1') && str_contains($out, 'Jangan rollback 024'), 'Preflight rollback MEMBLOKIR bila ada data kesehatan');

// ===================================================================== M-6
echo PHP_EOL . '=== M-6. Rollback salinan tanpa data kesehatan ===' . PHP_EOL;
[$kode] = $sh('TAHAP7_DB=' . $dbB . ' bash tests/redesain_web_tahap7_db.sh', $dbB);
$assert($kode === 0, 'Salinan kedua dibangun: ' . $dbB);
$manifestB = $scratch . '/manifest-b.json';
[$kode] = $sh('php bin/v5_phase1_preflight.php ' . escapeshellarg($manifestB), $dbB);
$mb = $buka($dbB);
$sumB = $checksum($mb);
[$kode, $out] = $sh('php bin/v5_phase1_rollback_preflight.php', $dbB);
$assert($kode === 0 && str_contains($out, 'Rollback 024 aman'), 'Preflight rollback lulus pada tabel kesehatan kosong');
[$kode, $out] = $sh('php bin/migrate.php rollback', $dbB);
$assert($kode === 0 && str_contains($out, '024_v5_fase1_kesehatan.sql'), 'Rollback 024 dijalankan');
[$kode, $out] = $sh('php bin/v5_phase1_verify.php --rollback ' . escapeshellarg($manifestB), $dbB);
$assert($kode === 0 && !str_contains($out, '[gagal]'), 'Verifier --rollback dengan manifest: 5 tabel dilepas, seluruh baris lama tetap');
$assert($checksum($mb) === $sumB, 'Checksum tabel inti tetap sesudah rollback');
$kolomLamaB = (int) $mb->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('santri','wali','santri_wali','users','pengurus')")->fetch_row()[0];
$assert($kolomLamaB === $kolomLama, 'Struktur kolom santri/wali/santri_wali/users/pengurus tidak berubah oleh migrasi maupun rollback');

// ===================================================================== M-7
echo PHP_EOL . '=== M-7. Kode Fase 1 sesudah rollback ===' . PHP_EOL;
$isi = <<<'PHP'
require 'app/bootstrap.php';
$admin = (int) app_db()->query("SELECT id FROM users WHERE username='sbx_admin'")->fetch_row()[0];
try { kesehatan_service()->akses(['id' => $admin, 'roles' => []]); echo "tanpa-galat\n"; } catch (\App\Kesehatan\KesehatanException $e) { echo 'status=' . $e->status . "\n"; }
$d = penugasan_service()->daftar('kesehatan', ['q' => '', 'status' => 'aktif'], 1);
echo 'daftar=' . $d['total'] . "\n";
echo 'murobi=' . (penugasan_service()->daftar('murobi', ['q' => ''], 1)['total'] > 0 ? 'ada' : 'kosong') . "\n";
echo 'cap=' . (in_array('kesehatan.catat', capabilities()->featureList(['id' => $admin, 'roles' => []]), true) ? 'admin-ada' : 'admin-tidak') . "\n";
PHP;
[$kode, $out] = $sh('php -r ' . escapeshellarg($isi), $dbB);
$assert($kode === 0 && str_contains($out, 'status=503') && str_contains($out, 'daftar=0') && str_contains($out, 'murobi=ada'), 'Sesudah rollback: layanan kesehatan 503, pusat penugasan berjalan (daftar kesehatan kosong, murobi tetap)');

// ===================================================================== M-8
echo PHP_EOL . '=== M-8. Migrasi ulang sesudah rollback ===' . PHP_EOL;
[$kode] = $sh('php bin/migrate.php up', $dbB);
[$kode2, $out] = $sh('php bin/v5_phase1_verify.php ' . escapeshellarg($manifestB), $dbB);
$assert($kode === 0 && $kode2 === 0 && !str_contains($out, '[gagal]'), 'Migrasi ulang 024 lulus verifier dengan manifest');
$assert($checksum($mb) === $sumB, 'Checksum tabel inti tetap sesudah siklus migrasi–rollback–migrasi');

array_map('unlink', glob($scratch . '/*') ?: []);
rmdir($scratch);
echo PHP_EOL . 'Lulus: ' . $lulus . ' · Gagal: ' . count($gagal) . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
