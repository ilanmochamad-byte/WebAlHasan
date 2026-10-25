<?php

declare(strict_types=1);

/**
 * Uji migrasi dan rollback 027 (PRD V6 Fase 1) pada SALINAN database uji.
 *
 *   V6_RUN_MIGRATION=1 php tests/v6_phase1_migration.php
 *
 * Langkah (masing-masing DB `test_*_test` tersendiri, dibangun ulang):
 *   M-1  salinan berantai V5 Fase 5 (fixture V1–V5) dikembalikan ke keadaan
 *        produksi terakhir (026) lewat rollback 027; verifier --rollback;
 *   M-2  preflight menulis manifest (jumlah baris + sidik jari ID) dan
 *        checksum tabel inti; migrasi 027 dijalankan `bin/migrate.php up`;
 *   M-3  verifier dengan manifest: 6 tabel, 22 kunci asing, 0 referensi
 *        yatim, invarians uang, jumlah baris dan ID lama tetap;
 *   M-4  SQL 027 dijalankan ulang langsung (idempoten); sumber migrasi aditif
 *        murni; rollback hanya melepas tabel bsa_*;
 *   M-5  data BSA lewat layanan → verifier lulus; preflight rollback
 *        MEMBLOKIR; `bin/migrate.php rollback` juga ditolak penjaga SQL tanpa
 *        tabel dilepas dan 027 tetap tercatat;
 *   M-6  salinan tanpa data BSA: preflight rollback lulus, rollback 027,
 *        verifier --rollback dengan manifest, checksum dan kolom tetap;
 *   M-7  kode Fase 1 sesudah rollback: layanan 503 "belum dipasang", menu
 *        Keuangan tidak tampil, pusat penugasan dan capability tetap berjalan;
 *   M-8  migrasi ulang sesudah rollback lulus verifier dengan manifest.
 */

if (getenv('V6_RUN_MIGRATION') !== '1') {
    fwrite(STDERR, "Set V6_RUN_MIGRATION=1 untuk menjalankan.\n");
    exit(2);
}
$root = dirname(__DIR__);
$dbA = 'test_v6_f1_migrasi_test';
$dbB = 'test_v6_f1_rollback_test';

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
$inti = 'santri, wali, santri_wali, users, user_roles, pengurus, guru, tahun_ajaran, kelas, plotting_kelas, plotting_kamar, murobi_assignments, pembimbing_assignments,
    pendidikan_assignments, bendahara_bulanan_assignments, kesehatan_assignments, kesehatan_kunjungan, kesehatan_ringkasan, izin_pengajuan, v3_pelanggaran, v4_rapor,
    psb_pendaftar, psb_pembayaran';
$checksum = static function (mysqli $m) use ($inti): array {
    $hasil = [];
    $r = $m->query('CHECKSUM TABLE ' . $inti);
    foreach ($r === false ? [] : $r->fetch_all(MYSQLI_ASSOC) as $row) {
        $hasil[(string) $row['Table']] = (string) $row['Checksum'];
    }

    return $hasil;
};
$kolomInti = static fn (mysqli $m): string => hash('sha256', json_encode($m->query("SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('santri','wali','santri_wali','users','pengurus','bendahara_bulanan_assignments','psb_pendaftar','psb_pembayaran')
    ORDER BY TABLE_NAME, ORDINAL_POSITION")->fetch_all(MYSQLI_ASSOC)));
$tabelBsa = static fn (mysqli $m): int => (int) $m->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'bsa\\_%'")->fetch_row()[0];
$scratch = sys_get_temp_dir() . '/v6f1-migrasi-' . bin2hex(random_bytes(3));
mkdir($scratch);
$manifest = $scratch . '/manifest.json';

// ===================================================================== M-1
echo '=== M-1. Salinan uji pada keadaan 026 ===' . PHP_EOL;
[$kode] = $sh('V5F5_DB=' . $dbA . ' bash tests/v5_phase5_db.sh', $dbA);
$assert($kode === 0, 'Salinan berantai V5 Fase 5 (fixture V1–V5 sintetis) dibangun: ' . $dbA);
[$kode, $out] = $sh('php bin/v6_phase1_rollback_preflight.php', $dbA);
$assert($kode === 0 && str_contains($out, 'Rollback 027 aman'), 'Preflight rollback lulus (tabel BSA kosong, 027 migrasi terakhir)');
[$kode, $out] = $sh('php bin/migrate.php rollback', $dbA);
$assert($kode === 0 && str_contains($out, '027_v6_fase1_bsa.sql'), 'Rollback 027 mengembalikan salinan ke keadaan 026');
[$kode, $out] = $sh('php bin/v6_phase1_verify.php --rollback', $dbA);
$assert($kode === 0, 'Verifier --rollback: tabel BSA tidak ada, 027 tidak tercatat, tabel penugasan tetap');

// ===================================================================== M-2
echo PHP_EOL . '=== M-2. Preflight dan migrasi ===' . PHP_EOL;
[$kode, $out] = $sh('php bin/v6_phase1_preflight.php ' . escapeshellarg($manifest), $dbA);
$isiManifest = json_decode((string) @file_get_contents($manifest), true);
$assert($kode === 0 && is_array($isiManifest) && count($isiManifest['id_fingerprints'] ?? []) === 5 && str_contains($out, 'Migrasi 026 tercatat') && str_contains($out, 'menegakkan CHECK'),
    'Preflight lulus (CHECK, InnoDB, binlog, 026) dan menulis manifest dengan sidik jari ID');
$m = $buka($dbA);
$sumSebelum = $checksum($m);
$kolomSebelum = $kolomInti($m);
$assert(count($sumSebelum) === 23, 'Checksum ' . count($sumSebelum) . ' tabel inti (identitas, penugasan, V2–V5, PSB) dicatat sebelum migrasi');
[$kode, $out] = $sh('php bin/migrate.php up', $dbA);
$assert($kode === 0 && str_contains($out, '027_v6_fase1_bsa.sql'), 'bin/migrate.php up menerapkan 027');

// ===================================================================== M-3
echo PHP_EOL . '=== M-3. Verifier sesudah migrasi ===' . PHP_EOL;
[$kode, $out] = $sh('php bin/v6_phase1_verify.php ' . escapeshellarg($manifest), $dbA);
$assert($kode === 0 && !str_contains($out, '[gagal]'), 'Verifier dengan manifest lulus (' . substr_count($out, '[lulus]') . ' pemeriksaan)');
$assert(str_contains($out, 'Jumlah referensi yatim tabel BSA: 0') && str_contains($out, 'Kunci asing tabel BSA terpasang (22 dari 22)'), '22 kunci asing, 0 referensi yatim');
$assert(str_contains($out, '[lulus] Sidik jari ID santri tetap') && str_contains($out, '[lulus] Relasi santri–wali (santri, wali, status arsip) tetap'), 'Sidik jari ID santri/wali/santri_wali/users dan relasi tetap');
$assert($checksum($m) === $sumSebelum && $kolomInti($m) === $kolomSebelum, 'Checksum dan struktur kolom tabel inti tetap sesudah migrasi');
$checks = (int) $m->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'bsa\\_%' AND CONSTRAINT_TYPE = 'CHECK'")->fetch_row()[0];
$assert($checks === 14, "Empat belas CHECK basis data terpasang pada tabel BSA ($checks)");

// ===================================================================== M-4
echo PHP_EOL . '=== M-4. Migrasi idempoten dan aditif ===' . PHP_EOL;
[$kode, $out] = $sh('MYSQL_PWD=' . escapeshellarg((string) app_config('database.password')) . ' mysql -h' . escapeshellarg((string) app_config('database.host')) . ' -P' . (int) app_config('database.port')
    . ' -u' . escapeshellarg((string) app_config('database.username')) . ' ' . $dbA . ' < database/migrations/027_v6_fase1_bsa.sql', $dbA);
$assert($kode === 0 && !preg_match('/ERROR/i', $out), 'SQL 027 dijalankan ulang tanpa galat (CREATE TABLE IF NOT EXISTS)');
$assert($checksum($m) === $sumSebelum && (int) $m->query("SELECT COUNT(*) FROM schema_migrations WHERE migration = '027_v6_fase1_bsa.sql'")->fetch_row()[0] === 1,
    'Jalan ulang tidak mengubah data lama dan tidak menggandakan catatan migrasi');
$sumber = (string) file_get_contents($root . '/database/migrations/027_v6_fase1_bsa.sql');
$kode027 = (string) preg_replace(['/--.*$/m', '/ON UPDATE CURRENT_TIMESTAMP/i'], '', $sumber);
$assert(!preg_match('/\b(DROP|DELETE|UPDATE|TRUNCATE|ALTER|RENAME|INSERT)\b/i', $kode027) && substr_count($kode027, 'CREATE TABLE IF NOT EXISTS') === 6 && !preg_match('/\b(FLOAT|DOUBLE|REAL)\b/i', $kode027),
    'Migrasi 027 aditif murni: 6 CREATE TABLE IF NOT EXISTS, tanpa DROP/DELETE/UPDATE/ALTER/INSERT, tanpa tipe float');
$rb = (string) preg_replace('/--.*$/m', '', (string) file_get_contents($root . '/database/rollbacks/027_v6_fase1_bsa.sql'));
preg_match_all('/DROP TABLE IF EXISTS (\w+)/', $rb, $drop);
$assert(count($drop[1]) === 6 && array_filter($drop[1], static fn ($t) => !str_starts_with($t, 'bsa_')) === [] && !preg_match('/\b(DELETE|UPDATE|TRUNCATE|ALTER)\b/i', $rb),
    'Rollback 027 hanya melepas enam tabel bsa_* (tanpa DELETE/UPDATE/ALTER)');

// ===================================================================== M-5
echo PHP_EOL . '=== M-5. Data BSA memblokir rollback ===' . PHP_EOL;
[$kode] = $sh('php tests/v6_phase1_seed.php', $dbA);
$assert($kode === 0, 'Fixture V6 Fase 1 dimuat pada salinan 027');
$isi = <<<'PHP'
require 'app/bootstrap.php';
$u = ['id' => (int) app_db()->query("SELECT id FROM users WHERE username='sbx_v6_bsa_b'")->fetch_row()[0], 'roles' => []];
$s = (int) app_db()->query("SELECT id FROM santri WHERE nis='V6S0001'")->fetch_row()[0];
$svc = bsa_service();
$svc->setoran($u, $s, ['idempotency_key' => 'migrasi-' . bin2hex(random_bytes(6)), 'nominal' => 100000, 'metode' => 'Tunai'], 'web');
$svc->penarikan($u, $s, ['idempotency_key' => 'migrasi-' . bin2hex(random_bytes(6)), 'nominal' => 30000, 'metode' => 'Tunai', 'tujuan' => 'Uji migrasi'], 'web');
$t = $svc->terbitkanTagihan($u, ['idempotency_key' => 'migrasi-' . bin2hex(random_bytes(6)), 'santri_id' => $s, 'periode' => '2026-10', 'jatuh_tempo' => '2026-10-10', 'komponen' => [['nama' => 'Uji', 'nominal' => 1000]]], 'web');
echo $svc->saldo($u, $s)['saldo'] . '|' . $t['id'];
PHP;
[$kode, $out] = $sh('php -r ' . escapeshellarg($isi), $dbA);
$assert($kode === 0 && str_starts_with(trim($out), '70000|'), 'Setoran Rp100.000, penarikan Rp30.000, dan satu tagihan dicatat lewat layanan (saldo Rp70.000)');
[$kode, $out] = $sh('php bin/v6_phase1_verify.php', $dbA);
$assert($kode === 0 && str_contains($out, 'Jumlah referensi yatim tabel BSA: 0') && str_contains($out, '[lulus] Saldo akun = Σ masuk − Σ keluar transaksi sah'), 'Verifier dengan data: 0 yatim, saldo = ledger');
[$kode, $out] = $sh('php bin/v6_phase1_rollback_preflight.php', $dbA);
$assert($kode === 1 && str_contains($out, '[blokir] bsa_tabungan_transaksi = 2') && str_contains($out, 'Jangan rollback 027'), 'Preflight rollback MEMBLOKIR bila ada data BSA');
[$kode, $out] = $sh('php bin/migrate.php rollback', $dbA);
$assert($kode !== 0 && $tabelBsa($m) === 6 && (int) $m->query("SELECT COUNT(*) FROM schema_migrations WHERE migration = '027_v6_fase1_bsa.sql'")->fetch_row()[0] === 1
    && (int) $m->query('SELECT COUNT(*) FROM bsa_tabungan_transaksi')->fetch_row()[0] === 2, 'Rollback tanpa preflight ditolak penjaga SQL: enam tabel dan data tetap, 027 tetap tercatat');

// ===================================================================== M-6
echo PHP_EOL . '=== M-6. Rollback salinan tanpa data BSA ===' . PHP_EOL;
[$kode] = $sh('V5F5_DB=' . $dbB . ' bash tests/v5_phase5_db.sh', $dbB);
$assert($kode === 0, 'Salinan kedua dibangun: ' . $dbB);
$manifestB = $scratch . '/manifest-b.json';
[$kode] = $sh('php bin/v6_phase1_preflight.php ' . escapeshellarg($manifestB), $dbB);
$mb = $buka($dbB);
$sumB = $checksum($mb);
$kolomB = $kolomInti($mb);
[$kode, $out] = $sh('php bin/v6_phase1_rollback_preflight.php', $dbB);
$assert($kode === 0 && str_contains($out, 'Rollback 027 aman'), 'Preflight rollback lulus pada tabel BSA kosong');
[$kode, $out] = $sh('php bin/migrate.php rollback', $dbB);
$assert($kode === 0 && str_contains($out, '027_v6_fase1_bsa.sql') && $tabelBsa($mb) === 0, 'Rollback 027 dijalankan; enam tabel dilepas');
[$kode, $out] = $sh('php bin/v6_phase1_verify.php --rollback ' . escapeshellarg($manifestB), $dbB);
$assert($kode === 0 && !str_contains($out, '[gagal]'), 'Verifier --rollback dengan manifest: tabel lama, jumlah baris, dan sidik jari ID tetap');
$assert($checksum($mb) === $sumB && $kolomInti($mb) === $kolomB, 'Checksum dan struktur kolom tabel inti tetap sesudah rollback');

// ===================================================================== M-7
echo PHP_EOL . '=== M-7. Kode Fase 1 sesudah rollback ===' . PHP_EOL;
$isi = <<<'PHP'
require 'app/bootstrap.php';
$admin = ['id' => (int) app_db()->query("SELECT id FROM users WHERE username='sbx_admin'")->fetch_row()[0], 'roles' => ['admin']];
try { bsa_service()->akses($admin); echo "tanpa-galat\n"; } catch (\App\Bsa\BsaException $e) { echo 'status=' . $e->status . "\n"; }
$nav = \App\Ui\Navigation::forUser($admin, capabilities()->forUser($admin), null, [], 'admin');
echo 'menu=' . (str_contains(json_encode($nav), 'bsa.php') ? 'ada' : 'tidak') . "\n";
echo 'daftar=' . penugasan_service()->daftar('bendahara_bulanan', ['q' => '', 'status' => 'aktif'], 1)['total'] . "\n";
echo 'murobi=' . (penugasan_service()->daftar('murobi', ['q' => ''], 1)['total'] > 0 ? 'ada' : 'kosong') . "\n";
echo 'cap=' . (in_array('bsa.tabungan', capabilities()->featureList($admin), true) ? 'admin-ada' : 'admin-tidak') . "\n";
PHP;
[$kode, $out] = $sh('php -r ' . escapeshellarg($isi), $dbB);
$assert($kode === 0 && str_contains($out, 'status=503') && str_contains($out, 'menu=tidak') && str_contains($out, 'murobi=ada') && str_contains($out, 'cap=admin-ada'),
    'Sesudah rollback: layanan BSA 503, menu Keuangan tidak tampil, pusat penugasan dan capability tetap berjalan (' . trim(str_replace("\n", ' ', $out)) . ')');

// ===================================================================== M-8
echo PHP_EOL . '=== M-8. Migrasi ulang sesudah rollback ===' . PHP_EOL;
[$kode] = $sh('php bin/migrate.php up', $dbB);
[$kode2, $out] = $sh('php bin/v6_phase1_verify.php ' . escapeshellarg($manifestB), $dbB);
$assert($kode === 0 && $kode2 === 0 && !str_contains($out, '[gagal]'), 'Migrasi ulang 027 lulus verifier dengan manifest');
$assert($checksum($mb) === $sumB, 'Checksum tabel inti tetap sesudah siklus migrasi–rollback–migrasi');

array_map('unlink', glob($scratch . '/*') ?: []);
rmdir($scratch);
echo PHP_EOL . 'Lulus: ' . $lulus . ' · Gagal: ' . count($gagal) . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
