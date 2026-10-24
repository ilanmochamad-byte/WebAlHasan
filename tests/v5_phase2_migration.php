<?php

declare(strict_types=1);

/**
 * Uji migrasi dan rollback 025 (PRD V5 Fase 2) pada SALINAN database uji.
 *
 *   V5_RUN_MIGRATION=1 php tests/v5_phase2_migration.php
 *
 * Langkah (masing-masing DB `test_*_test` tersendiri, dibangun ulang):
 *   N-1  salinan V5 Fase 1 (fixture V1–V4 + V5 F1) dikembalikan ke keadaan
 *        024 lewat rollback 025; satu kunjungan berbentuk Fase 1 (versi 1
 *        tanpa rincian) disisipkan seperti data yang sudah ada sebelum 025;
 *   N-2  preflight menulis manifest (termasuk lima tabel 024) + checksum;
 *        migrasi 025 dijalankan `bin/migrate.php up`;
 *   N-3  verifier dengan manifest: 4 tabel, 12 kunci asing (satu gabungan),
 *        0 yatim, seluruh baris lama dan tabel 024 tetap, checksum tetap;
 *   N-4  SQL 025 dijalankan ulang (idempoten); berkas aditif murni;
 *   N-5  kunjungan Fase 1 terbaca dan dapat dikoreksi kode Fase 2 (versi 2 +
 *        obat), verifier tetap lulus, preflight rollback MEMBLOKIR;
 *   N-6  salinan tanpa rincian: preflight rollback lulus, rollback 025,
 *        verifier --rollback dengan manifest, tabel 024 + checksum tetap,
 *        layanan menjawab 503 dan pusat penugasan tetap berjalan;
 *   N-7  migrasi ulang 025 lulus verifier.
 */

if (getenv('V5_RUN_MIGRATION') !== '1') {
    fwrite(STDERR, "Set V5_RUN_MIGRATION=1 untuk menjalankan.\n");
    exit(2);
}
$root = dirname(__DIR__);
$dbA = 'test_v5_f2_migrasi_test';
$dbB = 'test_v5_f2_rollback_test';

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
    $r = $m->query('CHECKSUM TABLE santri, wali, santri_wali, users, user_roles, pengurus, guru, tahun_ajaran, murobi_assignments, pembimbing_assignments, plotting_kamar, plotting_kelas,
        izin_pengajuan, v3_pelanggaran, v4_rapor, psb_pendaftar, psb_pembayaran,
        kesehatan_assignments, kesehatan_kunjungan, kesehatan_kunjungan_versi, kesehatan_idempotensi, kesehatan_akses_log');
    foreach ($r === false ? [] : $r->fetch_all(MYSQLI_ASSOC) as $row) {
        $hasil[(string) $row['Table']] = (string) $row['Checksum'];
    }

    return $hasil;
};
$scratch = sys_get_temp_dir() . '/v5f2-migrasi-' . bin2hex(random_bytes(3));
mkdir($scratch);
$manifest = $scratch . '/manifest.json';
$sisipFase1 = <<<'SQL'
INSERT INTO kesehatan_kunjungan (santri_id, waktu_kunjungan, status, versi, keluhan, catatan_klinis, tindakan, petugas_user_id, petugas_pengurus_id, penugasan_id, idempotency_key, created_by, updated_by)
SELECT s.id, NOW() - INTERVAL 1 DAY, 'Tercatat', 1, 'Keluhan sintetis sebelum 025', NULL, NULL, u.id, u.pengurus_id, a.id, 'fase1-sebelum-025', u.id, u.id
  FROM santri s, users u JOIN kesehatan_assignments a ON a.pengurus_id = u.pengurus_id
 WHERE s.nis = 'V5S0001' AND u.username = 'sbx_v5_petugas_b' LIMIT 1;
INSERT INTO kesehatan_kunjungan_versi (kunjungan_id, versi, status, waktu_kunjungan, keluhan, alasan, dibuat_oleh)
SELECT id, 1, status, waktu_kunjungan, keluhan, NULL, created_by FROM kesehatan_kunjungan WHERE idempotency_key = 'fase1-sebelum-025';
INSERT INTO kesehatan_idempotensi (user_id, operasi, kunci, request_hash, kunjungan_id, status_code)
SELECT created_by, 'kunjungan.buat', 'fase1-sebelum-025', REPEAT('a', 64), id, 201 FROM kesehatan_kunjungan WHERE idempotency_key = 'fase1-sebelum-025';
SQL;

// ===================================================================== N-1
echo '=== N-1. Salinan uji pada keadaan 024 dengan data Fase 1 ===' . PHP_EOL;
[$kode] = $sh('V5F1_DB=' . $dbA . ' bash tests/v5_phase1_db.sh', $dbA);
$assert($kode === 0, 'Salinan V5 Fase 1 (fixture V1–V4 + V5 F1 sintetis) dibangun: ' . $dbA);
[$kode, $out] = $sh('php bin/v5_phase2_rollback_preflight.php && php bin/migrate.php rollback', $dbA);
$assert($kode === 0 && str_contains($out, 'Rollback selesai: 025_v5_fase2_rincian_kesehatan.sql'), 'Rollback 025 mengembalikan salinan ke keadaan 024 (produksi sesudah Fase 1)');
[$kode, $out] = $sh('php bin/v5_phase2_verify.php --rollback', $dbA);
$assert($kode === 0, 'Verifier --rollback: empat tabel 025 tidak ada, 024 tetap');
$m = $buka($dbA);
$assert($m->multi_query($sisipFase1) && (function () use ($m): bool { while ($m->more_results()) { $m->next_result(); } return true; })(), 'Satu kunjungan berbentuk Fase 1 disisipkan (versi 1, tanpa rincian)');
$idFase1 = (int) $m->query("SELECT id FROM kesehatan_kunjungan WHERE idempotency_key = 'fase1-sebelum-025'")->fetch_row()[0];
$assert($idFase1 > 0, 'Kunjungan Fase 1 ada sebelum migrasi 025');

// ===================================================================== N-2
echo PHP_EOL . '=== N-2. Preflight dan migrasi ===' . PHP_EOL;
[$kode, $out] = $sh('php bin/v5_phase2_preflight.php ' . escapeshellarg($manifest), $dbA);
$isiManifest = json_decode((string) @file_get_contents($manifest), true);
$assert($kode === 0 && str_contains($out, 'Migrasi 024 tercatat') && isset($isiManifest['legacy_counts']['kesehatan_kunjungan']) && !isset($isiManifest['legacy_counts']['kesehatan_obat']),
    'Preflight lulus; manifest memuat tabel 024, tanpa tabel 025');
$sumSebelum = $checksum($m);
$assert(count($sumSebelum) === 22, 'Checksum 22 tabel inti + 024 dicatat sebelum migrasi');
[$kode, $out] = $sh('php bin/migrate.php up', $dbA);
$assert($kode === 0 && str_contains($out, '025_v5_fase2_rincian_kesehatan.sql') && !str_contains($out, '024_v5'), 'bin/migrate.php up hanya menerapkan 025');

// ===================================================================== N-3
echo PHP_EOL . '=== N-3. Verifier sesudah migrasi ===' . PHP_EOL;
[$kode, $out] = $sh('php bin/v5_phase2_verify.php ' . escapeshellarg($manifest), $dbA);
$assert($kode === 0 && !str_contains($out, '[gagal]'), 'Verifier dengan manifest lulus (' . substr_count($out, '[lulus]') . ' pemeriksaan)');
$assert(str_contains($out, 'Jumlah referensi yatim tabel 025: 0') && str_contains($out, 'Kunci asing tabel 025 terpasang (12'), '12 kunci asing (satu gabungan), 0 referensi yatim');
$assert($checksum($m) === $sumSebelum, 'Checksum tabel inti + lima tabel 024 tetap sesudah migrasi');
$kolom024 = (int) $m->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('kesehatan_kunjungan','kesehatan_kunjungan_versi','kesehatan_idempotensi','kesehatan_akses_log','kesehatan_assignments')")->fetch_row()[0];
$kolomLama = (int) $m->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('santri','wali','santri_wali','users','pengurus','guru','plotting_kamar','plotting_kelas')")->fetch_row()[0];

// ===================================================================== N-4
echo PHP_EOL . '=== N-4. Migrasi idempoten dan aditif ===' . PHP_EOL;
$baca = static fn (string $k): string => trim((string) shell_exec('grep -E "^' . $k . '=" ' . escapeshellarg($root . '/.env') . ' | head -1 | cut -d= -f2-'));
[$kode, $out] = $sh('MYSQL_PWD=' . escapeshellarg($baca('DB_PASSWORD')) . ' mysql -h' . escapeshellarg((string) app_config('database.host')) . ' -u' . escapeshellarg((string) app_config('database.username')) . ' ' . $dbA . ' < database/migrations/025_v5_fase2_rincian_kesehatan.sql 2>&1 | grep -v ssl-verify', $dbA);
$assert(!preg_match('/ERROR/i', $out), 'SQL 025 dijalankan ulang tanpa galat (CREATE TABLE IF NOT EXISTS)');
$assert($checksum($m) === $sumSebelum && (int) $m->query("SELECT COUNT(*) FROM schema_migrations WHERE migration = '025_v5_fase2_rincian_kesehatan.sql'")->fetch_row()[0] === 1, 'Jalan ulang tidak mengubah data lama dan tidak menggandakan catatan migrasi');
$kode025 = (string) preg_replace(['/--.*$/m', '/ON (UPDATE|DELETE) (CURRENT_TIMESTAMP|SET NULL)/i'], '', (string) file_get_contents($root . '/database/migrations/025_v5_fase2_rincian_kesehatan.sql'));
$assert(!preg_match('/\b(DROP|DELETE|UPDATE|TRUNCATE|ALTER|RENAME|INSERT)\b/i', $kode025) && substr_count($kode025, 'CREATE TABLE IF NOT EXISTS') === 4, 'Migrasi 025 aditif murni: 4 CREATE TABLE IF NOT EXISTS, tanpa DROP/ALTER/DML');

// ===================================================================== N-5
echo PHP_EOL . '=== N-5. Data Fase 1 dengan kode Fase 2; data memblokir rollback ===' . PHP_EOL;
[$kode] = $sh('php tests/v5_phase2_seed.php', $dbA);
$assert($kode === 0, 'Fixture V5 Fase 2 dimuat pada salinan 025');
$isi = <<<'PHP'
require 'app/bootstrap.php';
$u = ['id' => (int) app_db()->query("SELECT id FROM users WHERE username='sbx_v5_petugas_b'")->fetch_row()[0], 'roles' => []];
$id = (int) app_db()->query("SELECT id FROM kesehatan_kunjungan WHERE idempotency_key = 'fase1-sebelum-025'")->fetch_row()[0];
$svc = kesehatan_service();
$d = $svc->detail($u, $id, 'web');
echo 'obat=' . count($d['obat']) . ' rujukan=' . count($d['rujukan']) . ' versi=' . $d['versi'] . "\n";
echo 'riwayat=' . count($svc->riwayatVersi($u, $id, 'web')['versi']) . "\n";
$k = $svc->koreksi($u, $id, ['idempotency_key' => 'migrasi-koreksi-' . bin2hex(random_bytes(4)), 'versi' => 1, 'alasan' => 'Melengkapi obat setelah 025',
    'waktu_kunjungan' => $d['waktu_kunjungan'], 'keluhan' => $d['keluhan'], 'obat' => [['nama_obat' => 'Obat sintetis', 'jumlah' => '1']]], 'web');
$v = $svc->riwayatVersi($u, $id, 'web')['versi'];
echo 'koreksi=' . $k['versi'] . ' tambah=' . ($v[0]['perubahan'][0]['jenis'] ?? '-') . "\n";
PHP;
[$kode, $out] = $sh('php -r ' . escapeshellarg($isi), $dbA);
$assert($kode === 0 && str_contains($out, 'obat=0 rujukan=0 versi=1') && str_contains($out, 'riwayat=1'), 'Kunjungan Fase 1 terbaca kode Fase 2 (tanpa rincian, satu versi)');
$assert(str_contains($out, 'koreksi=2 tambah=tambah'), 'Kunjungan Fase 1 dapat dikoreksi: versi 2, obat tercatat sebagai tambahan');
[$kode, $out] = $sh('php bin/v5_phase2_verify.php', $dbA);
$assert($kode === 0 && !str_contains($out, '[gagal]'), 'Verifier tetap lulus dengan data Fase 1 + koreksi Fase 2');
[$kode, $out] = $sh('php bin/v5_phase2_rollback_preflight.php', $dbA);
$assert($kode === 1 && str_contains($out, '[blokir] kesehatan_obat = 1') && str_contains($out, 'Jangan rollback 025'), 'Preflight rollback MEMBLOKIR bila ada rincian kesehatan');

// ===================================================================== N-6
echo PHP_EOL . '=== N-6. Rollback salinan tanpa rincian ===' . PHP_EOL;
[$kode] = $sh('V5F1_DB=' . $dbB . ' bash tests/v5_phase1_db.sh', $dbB);
$assert($kode === 0, 'Salinan kedua dibangun: ' . $dbB);
$mb = $buka($dbB);
$assert($mb->multi_query($sisipFase1) && (function () use ($mb): bool { while ($mb->more_results()) { $mb->next_result(); } return true; })(), 'Kunjungan Fase 1 pada salinan kedua (data 024 yang harus bertahan)');
$manifestB = $scratch . '/manifest-b.json';
[$kode] = $sh('php bin/v5_phase2_preflight.php ' . escapeshellarg($manifestB), $dbB);
$sumB = $checksum($mb);
[$kode, $out] = $sh('php bin/v5_phase2_rollback_preflight.php', $dbB);
$assert($kode === 0 && str_contains($out, 'Rollback 025 aman'), 'Preflight rollback lulus pada tabel 025 kosong (walau 024 berisi)');
[$kode, $out] = $sh('php bin/migrate.php rollback', $dbB);
$assert($kode === 0 && str_contains($out, '025_v5_fase2_rincian_kesehatan.sql'), 'Rollback 025 dijalankan (hanya 025)');
[$kode, $out] = $sh('php bin/v5_phase2_verify.php --rollback ' . escapeshellarg($manifestB), $dbB);
$assert($kode === 0 && !str_contains($out, '[gagal]'), 'Verifier --rollback dengan manifest: 4 tabel dilepas, seluruh baris lama dan 024 tetap');
$assert($checksum($mb) === $sumB, 'Checksum tabel inti + lima tabel 024 tetap sesudah rollback');
$assert((int) $mb->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('kesehatan_kunjungan','kesehatan_kunjungan_versi','kesehatan_idempotensi','kesehatan_akses_log','kesehatan_assignments')")->fetch_row()[0] === $kolom024
    && (int) $mb->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('santri','wali','santri_wali','users','pengurus','guru','plotting_kamar','plotting_kelas')")->fetch_row()[0] === $kolomLama,
    'Struktur kolom tabel 024 dan tabel inti tidak berubah oleh migrasi maupun rollback');
$isi = <<<'PHP'
require 'app/bootstrap.php';
$admin = (int) app_db()->query("SELECT id FROM users WHERE username='sbx_admin'")->fetch_row()[0];
try { kesehatan_service()->akses(['id' => $admin, 'roles' => []]); echo "tanpa-galat\n"; } catch (\App\Kesehatan\KesehatanException $e) { echo 'status=' . $e->status . "\n"; }
echo 'daftar=' . penugasan_service()->daftar('kesehatan', ['q' => '', 'status' => 'aktif'], 1)['total'] . "\n";
PHP;
[$kode, $out] = $sh('php -r ' . escapeshellarg($isi), $dbB);
$assert($kode === 0 && str_contains($out, 'status=503') && preg_match('/daftar=[1-9]/', $out) === 1, 'Sesudah rollback 025: layanan kesehatan 503 (aman), pusat penugasan tetap membaca penugasan 024');

// ===================================================================== N-7
echo PHP_EOL . '=== N-7. Migrasi ulang sesudah rollback ===' . PHP_EOL;
[$kode] = $sh('php bin/migrate.php up', $dbB);
[$kode2, $out] = $sh('php bin/v5_phase2_verify.php ' . escapeshellarg($manifestB), $dbB);
$assert($kode === 0 && $kode2 === 0 && !str_contains($out, '[gagal]'), 'Migrasi ulang 025 lulus verifier dengan manifest');
$assert($checksum($mb) === $sumB, 'Checksum tetap sesudah siklus migrasi–rollback–migrasi');

array_map('unlink', glob($scratch . '/*') ?: []);
rmdir($scratch);
echo PHP_EOL . 'Lulus: ' . $lulus . ' · Gagal: ' . count($gagal) . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
