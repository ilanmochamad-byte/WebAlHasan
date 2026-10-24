<?php

declare(strict_types=1);

/**
 * Uji migrasi dan rollback 026 (PRD V5 Fase 3) pada SALINAN database uji.
 *
 *   V5_RUN_MIGRATION=1 php tests/v5_phase3_migration.php
 *
 * Langkah (masing-masing DB `test_*_test` tersendiri, dibangun ulang):
 *   P-1  salinan V5 Fase 2 (fixture V1–V4 + V5 F1–F2) dikembalikan ke keadaan
 *        025 lewat rollback 026; satu kunjungan Fase 2 (dengan obat dan
 *        rujukan) dicatat lewat layanan seperti data sebelum 026;
 *   P-2  preflight menulis manifest (termasuk sembilan tabel kesehatan dan
 *        notifikasi_outbox) + checksum; migrasi 026 dijalankan;
 *   P-3  verifier dengan manifest: 4 tabel, 14 kunci asing (satu gabungan),
 *        0 yatim, seluruh baris lama tetap, checksum tetap;
 *   P-4  SQL 026 dijalankan ulang (idempoten); berkas aditif murni;
 *   P-5  kunjungan sebelum 026 dapat diterbitkan ringkasannya oleh kode Fase 3;
 *        verifier lulus; preflight rollback MEMBLOKIR (tabel + outbox V5);
 *   P-6  salinan tanpa ringkasan: preflight rollback lulus, rollback 026,
 *        verifier --rollback dengan manifest, checksum tetap, layanan
 *        ringkasan 503 sedangkan layanan klinis Fase 1–2 tetap berjalan;
 *   P-7  migrasi ulang 026 lulus verifier.
 */

if (getenv('V5_RUN_MIGRATION') !== '1') {
    fwrite(STDERR, "Set V5_RUN_MIGRATION=1 untuk menjalankan.\n");
    exit(2);
}
$root = dirname(__DIR__);
$dbA = 'test_v5_f3_migrasi_test';
$dbB = 'test_v5_f3_rollback_test';

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
        izin_pengajuan, v3_pelanggaran, v3_publikasi, v4_rapor, psb_pendaftar, psb_pembayaran, notifikasi_outbox, perangkat_push, pengaturan_notifikasi,
        kesehatan_assignments, kesehatan_kunjungan, kesehatan_kunjungan_versi, kesehatan_idempotensi, kesehatan_akses_log,
        kesehatan_obat, kesehatan_rujukan, kesehatan_tindak_lanjut, kesehatan_versi_rincian');
    foreach ($r === false ? [] : $r->fetch_all(MYSQLI_ASSOC) as $row) {
        $hasil[(string) $row['Table']] = (string) $row['Checksum'];
    }

    return $hasil;
};
$scratch = sys_get_temp_dir() . '/v5f3-migrasi-' . bin2hex(random_bytes(3));
mkdir($scratch);
$manifest = $scratch . '/manifest.json';
$kunjunganFase2 = <<<'PHP'
require 'app/bootstrap.php';
$u = ['id' => (int) app_db()->query("SELECT id FROM users WHERE username='sbx_v5_petugas_b'")->fetch_row()[0], 'roles' => []];
$s = (int) app_db()->query("SELECT id FROM santri WHERE nis='V5S0101'")->fetch_row()[0];
$k = kesehatan_service()->buat($u, ['idempotency_key' => 'sebelum-026-' . bin2hex(random_bytes(4)), 'santri_id' => $s, 'waktu_kunjungan' => date('Y-m-d H:i', strtotime('-1 day')),
    'keluhan' => 'Keluhan sintetis sebelum 026', 'obat' => [['nama_obat' => 'Obat sintetis', 'jumlah' => '1']], 'rujukan' => [['tujuan' => 'Fasilitas sintetis']]], 'web');
echo 'kunjungan=' . $k['id'] . "\n";
PHP;

// ===================================================================== P-1
echo '=== P-1. Salinan uji pada keadaan 025 dengan data Fase 2 ===' . PHP_EOL;
[$kode] = $sh('V5F2_DB=' . $dbA . ' bash tests/v5_phase2_db.sh', $dbA);
$assert($kode === 0, 'Salinan V5 Fase 2 (fixture V1–V4 + V5 F1–F2 sintetis) dibangun: ' . $dbA);
[$kode, $out] = $sh('php bin/v5_phase3_rollback_preflight.php && php bin/migrate.php rollback', $dbA);
$assert($kode === 0 && str_contains($out, 'Rollback selesai: 026_v5_fase3_ringkasan_wali.sql'), 'Rollback 026 mengembalikan salinan ke keadaan 025 (produksi sesudah Fase 2)');
[$kode] = $sh('php bin/v5_phase3_verify.php --rollback', $dbA);
$assert($kode === 0, 'Verifier --rollback: empat tabel 026 tidak ada, 024–025 tetap');
[$kode, $out] = $sh('php -r ' . escapeshellarg($kunjunganFase2), $dbA);
$assert($kode === 0 && preg_match('/kunjungan=(\d+)/', $out, $mk) === 1, 'Satu kunjungan Fase 2 (obat + rujukan) dicatat sebelum 026');
$idSebelum = (int) ($mk[1] ?? 0);
$m = $buka($dbA);

// ===================================================================== P-2
echo PHP_EOL . '=== P-2. Preflight dan migrasi ===' . PHP_EOL;
[$kode, $out] = $sh('php bin/v5_phase3_preflight.php ' . escapeshellarg($manifest), $dbA);
$isiManifest = json_decode((string) @file_get_contents($manifest), true);
$assert($kode === 0 && str_contains($out, 'Migrasi 025 tercatat') && isset($isiManifest['legacy_counts']['kesehatan_obat'], $isiManifest['legacy_counts']['notifikasi_outbox']) && !isset($isiManifest['legacy_counts']['kesehatan_ringkasan']),
    'Preflight lulus; manifest memuat tabel 024–025 dan outbox, tanpa tabel 026');
$sumSebelum = $checksum($m);
$assert(count($sumSebelum) === 30, 'Checksum 30 tabel inti + notifikasi + 024–025 dicatat sebelum migrasi');
[$kode, $out] = $sh('php bin/migrate.php up', $dbA);
$assert($kode === 0 && str_contains($out, '026_v5_fase3_ringkasan_wali.sql') && !str_contains($out, '025_v5'), 'bin/migrate.php up hanya menerapkan 026');

// ===================================================================== P-3
echo PHP_EOL . '=== P-3. Verifier sesudah migrasi ===' . PHP_EOL;
[$kode, $out] = $sh('php bin/v5_phase3_verify.php ' . escapeshellarg($manifest), $dbA);
$assert($kode === 0 && !str_contains($out, '[gagal]'), 'Verifier dengan manifest lulus (' . substr_count($out, '[lulus]') . ' pemeriksaan)');
$assert(str_contains($out, 'Jumlah referensi yatim tabel 026: 0') && str_contains($out, 'Kunci asing tabel 026 terpasang (14'), '14 kunci asing (satu gabungan), 0 referensi yatim');
$assert($checksum($m) === $sumSebelum, 'Checksum tabel inti, notifikasi, dan sembilan tabel 024–025 tetap sesudah migrasi');
$kolomLama = (int) $m->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND (TABLE_NAME IN ('santri','wali','santri_wali','users','notifikasi_outbox','perangkat_push')
    OR (TABLE_NAME LIKE 'kesehatan\\_%' AND TABLE_NAME NOT LIKE 'kesehatan\\_ringkasan%'))")->fetch_row()[0];

// ===================================================================== P-4
echo PHP_EOL . '=== P-4. Migrasi idempoten dan aditif ===' . PHP_EOL;
[$kode, $out] = $sh('MYSQL_PWD=' . escapeshellarg((string) app_config('database.password')) . ' mysql -h' . escapeshellarg((string) app_config('database.host')) . ' -P' . (int) app_config('database.port')
    . ' -u' . escapeshellarg((string) app_config('database.username')) . ' ' . $dbA . ' < database/migrations/026_v5_fase3_ringkasan_wali.sql 2>&1 | grep -v ssl-verify', $dbA);
$assert(!preg_match('/ERROR/i', $out), 'SQL 026 dijalankan ulang tanpa galat (CREATE TABLE IF NOT EXISTS)');
$assert($checksum($m) === $sumSebelum && (int) $m->query("SELECT COUNT(*) FROM schema_migrations WHERE migration = '026_v5_fase3_ringkasan_wali.sql'")->fetch_row()[0] === 1,
    'Jalan ulang tidak mengubah data lama dan tidak menggandakan catatan migrasi');
$kode026 = (string) preg_replace(['/--.*$/m', '/ON (UPDATE|DELETE) (CURRENT_TIMESTAMP|SET NULL)/i'], '', (string) file_get_contents($root . '/database/migrations/026_v5_fase3_ringkasan_wali.sql'));
$assert(!preg_match('/\b(DROP|DELETE|UPDATE|TRUNCATE|ALTER|RENAME|INSERT)\b/i', $kode026) && substr_count($kode026, 'CREATE TABLE IF NOT EXISTS') === 4, 'Migrasi 026 aditif murni: 4 CREATE TABLE IF NOT EXISTS');

// ===================================================================== P-5
echo PHP_EOL . '=== P-5. Kunjungan sebelum 026 dengan kode Fase 3; data memblokir rollback ===' . PHP_EOL;
[$kode] = $sh('php tests/v5_phase3_seed.php', $dbA);
$assert($kode === 0, 'Fixture V5 Fase 3 dimuat pada salinan 026');
// Wali sintetis dihubungkan ke santri kunjungan lama agar ada penerima.
$m->query("INSERT INTO santri_wali (santri_id, wali_id, hubungan, is_primary) SELECT (SELECT id FROM santri WHERE nis='V5S0101'), id, 'Wali', 0 FROM wali WHERE nama = 'SBX V5 Wali Lain'");
$isi = <<<PHP
require 'app/bootstrap.php';
\$u = ['id' => (int) app_db()->query("SELECT id FROM users WHERE username='sbx_v5_petugas_b'")->fetch_row()[0], 'roles' => []];
\$w = ['id' => (int) app_db()->query("SELECT id FROM users WHERE username='sbx_v5_wali_lain'")->fetch_row()[0], 'roles' => []];
\$s = kesehatan_ringkasan_service();
\$m = ['kunjungan_versi' => 1, 'revisi_ringkasan' => 0, 'ringkasan' => 'Ringkasan sintetis untuk kunjungan sebelum 026.'];
\$p = \$s->pratinjau(\$u, $idSebelum, \$m);
\$t = \$s->terbitkan(\$u, $idSebelum, \$m + ['konfirmasi' => true, 'pratinjau_hash' => \$p['pratinjau_hash'], 'idempotency_key' => 'migrasi-terbit-' . bin2hex(random_bytes(4))], 'web');
echo 'versi=' . \$t['versi'] . ' inapp=' . \$t['notifikasi']['in_app'] . ' wali=' . \$s->detailWali(\$w, \$t['ringkasan_id'], 'web')['status'] . "\\n";
PHP;
[$kode, $out] = $sh('php -r ' . escapeshellarg($isi), $dbA);
$assert($kode === 0 && str_contains($out, 'versi=1 inapp=1 wali=Terbit'), 'Kunjungan yang dicatat sebelum 026 dapat diterbitkan ringkasannya dan dibaca wali berelasi');
[$kode, $out] = $sh('php bin/v5_phase3_verify.php', $dbA);
$assert($kode === 0 && !str_contains($out, '[gagal]'), 'Verifier tetap lulus dengan data sebelum 026 + ringkasan Fase 3');
[$kode, $out] = $sh('php bin/v5_phase3_rollback_preflight.php', $dbA);
$assert($kode === 1 && str_contains($out, '[blokir] kesehatan_ringkasan = 1') && str_contains($out, '[blokir] notifikasi_outbox V5 kesehatan = 1') && str_contains($out, 'Jangan rollback 026'),
    'Preflight rollback MEMBLOKIR bila ada ringkasan atau notifikasinya');

// ===================================================================== P-6
echo PHP_EOL . '=== P-6. Rollback salinan tanpa ringkasan ===' . PHP_EOL;
[$kode] = $sh('V5F2_DB=' . $dbB . ' bash tests/v5_phase2_db.sh', $dbB);
$assert($kode === 0, 'Salinan kedua dibangun: ' . $dbB);
[$kode] = $sh('php -r ' . escapeshellarg($kunjunganFase2), $dbB);
$mb = $buka($dbB);
$manifestB = $scratch . '/manifest-b.json';
[$kode] = $sh('php bin/v5_phase3_preflight.php ' . escapeshellarg($manifestB), $dbB);
$sumB = $checksum($mb);
[$kode, $out] = $sh('php bin/v5_phase3_rollback_preflight.php', $dbB);
$assert($kode === 0 && str_contains($out, 'Rollback 026 aman'), 'Preflight rollback lulus pada tabel 026 kosong (walau 024–025 berisi)');
[$kode, $out] = $sh('php bin/migrate.php rollback', $dbB);
$assert($kode === 0 && str_contains($out, '026_v5_fase3_ringkasan_wali.sql'), 'Rollback 026 dijalankan (hanya 026)');
[$kode, $out] = $sh('php bin/v5_phase3_verify.php --rollback ' . escapeshellarg($manifestB), $dbB);
$assert($kode === 0 && !str_contains($out, '[gagal]'), 'Verifier --rollback dengan manifest: 4 tabel dilepas, seluruh baris lama tetap');
$assert($checksum($mb) === $sumB, 'Checksum tabel inti, notifikasi, dan 024–025 tetap sesudah rollback');
$assert((int) $mb->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND (TABLE_NAME IN ('santri','wali','santri_wali','users','notifikasi_outbox','perangkat_push')
    OR (TABLE_NAME LIKE 'kesehatan\\_%' AND TABLE_NAME NOT LIKE 'kesehatan\\_ringkasan%'))")->fetch_row()[0] === $kolomLama, 'Struktur kolom tabel lama tidak berubah oleh migrasi maupun rollback');
$isi = <<<'PHP'
require 'app/bootstrap.php';
$u = ['id' => (int) app_db()->query("SELECT id FROM users WHERE username='sbx_v5_petugas_b'")->fetch_row()[0], 'roles' => []];
$w = ['id' => (int) app_db()->query("SELECT id FROM users WHERE username='sbx_ortu_a'")->fetch_row()[0], 'roles' => []];
try { kesehatan_ringkasan_service()->aksesWali($w); echo "tanpa-galat\n"; } catch (\App\Kesehatan\KesehatanException $e) { echo 'ringkasan=' . $e->status . "\n"; }
// Daftar (bukan detail) agar log akses tidak bertambah dan manifest tetap dapat dibandingkan.
echo 'klinis=' . (kesehatan_service()->akses($u)['catat'] ? 'catat' : '-') . ' obat=' . kesehatan_service()->daftar($u, [])['rows'][0]['penanda']['obat'] . "\n";
echo 'menu=' . ((new \App\Ui\NavigationContext($w, [], []))->kesehatanWali() ? 'ya' : 'tidak') . "\n";
PHP;
[$kode, $out] = $sh('php -r ' . escapeshellarg($isi), $dbB);
$assert($kode === 0 && str_contains($out, 'ringkasan=503') && str_contains($out, 'klinis=catat obat=1') && str_contains($out, 'menu=tidak'),
    'Sesudah rollback 026: layanan ringkasan 503 (aman), menu wali tersembunyi, layanan klinis Fase 1–2 tetap berjalan');

// ===================================================================== P-7
echo PHP_EOL . '=== P-7. Migrasi ulang sesudah rollback ===' . PHP_EOL;
[$kode] = $sh('php bin/migrate.php up', $dbB);
[$kode2, $out] = $sh('php bin/v5_phase3_verify.php ' . escapeshellarg($manifestB), $dbB);
$assert($kode === 0 && $kode2 === 0 && !str_contains($out, '[gagal]'), 'Migrasi ulang 026 lulus verifier dengan manifest');
$assert($checksum($mb) === $sumB, 'Checksum tetap sesudah siklus migrasi–rollback–migrasi');

array_map('unlink', glob($scratch . '/*') ?: []);
rmdir($scratch);
echo PHP_EOL . 'Lulus: ' . $lulus . ' · Gagal: ' . count($gagal) . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
