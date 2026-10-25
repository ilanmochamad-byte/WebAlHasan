<?php

declare(strict_types=1);

/**
 * Uji migrasi dan rollback 028 (PRD V6 Fase 2) pada SALINAN database uji.
 *
 *   V6_RUN_MIGRATION=1 php tests/v6_phase2_migration.php
 *
 * Langkah (masing-masing DB `test_*_test` tersendiri, dibangun ulang):
 *   N-1  salinan berantai V6 Fase 1 (fixture V1–V5 + V6) dikembalikan ke
 *        keadaan 027 lewat preflight + rollback 028; verifier --rollback;
 *   N-2  data Fase 1 dibuat pada keadaan 027 (setoran, penarikan, tagihan
 *        manual tanpa riwayat); preflight Fase 2 menulis manifest (jumlah
 *        baris seluruh tabel lama + sidik jari ID dan isi uang 027) dan
 *        checksum tabel inti + 027; `bin/migrate.php up` menerapkan 028;
 *   N-3  verifier Fase 2 dengan manifest: invarians Fase 1 + 9 tabel, 28
 *        kunci asing, 0 yatim; data 027 dan tabel lama tetap; CHECK terpasang;
 *   N-4  SQL 028 dijalankan ulang langsung (idempoten); sumber aditif murni;
 *        rollback hanya melepas sembilan tabel 028 dengan penjaga data;
 *   N-5  data Fase 2 lewat layanan (tarif, penerbitan massal, pembayaran) →
 *        verifier lulus; preflight rollback MEMBLOKIR; `migrate rollback`
 *        ditolak penjaga SQL tanpa tabel/data dilepas;
 *   N-6  salinan kedua tanpa data Fase 2: rollback 028 lulus, verifier
 *        --rollback dengan manifest, checksum tabel inti + 027 tetap;
 *   N-7  kode Fase 2 sesudah rollback: fitur tagihan lengkap 503, halaman
 *        tarif 503, navigasi bagian keuangan tersembunyi, fitur Fase 1 tetap
 *        berjalan (setoran, tagihan manual tanpa riwayat);
 *   N-8  migrasi ulang sesudah rollback lulus verifier dengan manifest; satu
 *        tagihan manual pasca-028 (riwayat) kembali memblokir rollback.
 */

if (getenv('V6_RUN_MIGRATION') !== '1') {
    fwrite(STDERR, "Set V6_RUN_MIGRATION=1 untuk menjalankan.\n");
    exit(2);
}
$root = dirname(__DIR__);
$dbA = 'test_v6_f2_migrasi_test';
$dbB = 'test_v6_f2_rollback_test';

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
    psb_pendaftar, psb_pembayaran, bsa_tagihan, bsa_tagihan_komponen, bsa_tabungan_akun, bsa_tabungan_transaksi, bsa_pembayaran, bsa_idempotensi';
$checksum = static function (mysqli $m) use ($inti): array {
    $hasil = [];
    $r = $m->query('CHECKSUM TABLE ' . $inti);
    foreach ($r === false ? [] : $r->fetch_all(MYSQLI_ASSOC) as $row) {
        $hasil[(string) $row['Table']] = (string) $row['Checksum'];
    }

    return $hasil;
};
$kolom = static fn (mysqli $m): string => hash('sha256', json_encode($m->query("SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND (TABLE_NAME IN ('santri','wali','santri_wali','users','pengurus','bendahara_bulanan_assignments','psb_pendaftar','psb_pembayaran')
       OR TABLE_NAME IN ('bsa_tagihan','bsa_tagihan_komponen','bsa_tabungan_akun','bsa_tabungan_transaksi','bsa_pembayaran','bsa_idempotensi'))
    ORDER BY TABLE_NAME, ORDINAL_POSITION")->fetch_all(MYSQLI_ASSOC)));
$fase2 = "'" . implode("', '", \App\Bsa\TagihanRepository::TABEL) . "'";
$tabelF2 = static fn (mysqli $m): int => (int) $m->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($fase2)")->fetch_row()[0];
$tercatat = static fn (mysqli $m): int => (int) $m->query("SELECT COUNT(*) FROM schema_migrations WHERE migration = '028_v6_fase2_tagihan_bulanan.sql'")->fetch_row()[0];
$scratch = sys_get_temp_dir() . '/v6f2-migrasi-' . bin2hex(random_bytes(3));
mkdir($scratch);
$manifest = $scratch . '/manifest.json';
$layanan = static function (string $kode) use ($sh): Closure {
    return static fn (string $db): array => $sh('php -r ' . escapeshellarg("require 'app/bootstrap.php';\n" . $kode), $db);
};

// ===================================================================== N-1
echo '=== N-1. Salinan uji pada keadaan 027 ===' . PHP_EOL;
[$kode] = $sh('V6F1_DB=' . $dbA . ' bash tests/v6_phase1_db.sh', $dbA);
$m = $buka($dbA);
$assert($kode === 0 && $tercatat($m) === 1 && $tabelF2($m) === 9, 'Salinan berantai V6 Fase 1 (fixture V1–V6 sintetis) dibangun dengan 027 + 028: ' . $dbA);
[$kode, $out] = $sh('php bin/v6_phase2_rollback_preflight.php', $dbA);
$assert($kode === 0 && str_contains($out, 'Rollback 028 aman'), 'Preflight rollback 028 lulus (tabel Fase 2 dan bsa_pembayaran kosong, 028 terakhir)');
[$kode, $out] = $sh('php bin/migrate.php rollback', $dbA);
$assert($kode === 0 && str_contains($out, '028_v6_fase2_tagihan_bulanan.sql') && $tabelF2($m) === 0, 'Rollback 028 mengembalikan salinan ke keadaan 027');
[$kode, $out] = $sh('php bin/v6_phase2_verify.php --rollback', $dbA);
$assert($kode === 0 && !str_contains($out, '[gagal]'), 'Verifier --rollback: sembilan tabel dilepas, 028 tidak tercatat, enam tabel 027 tetap');

// ===================================================================== N-2
echo PHP_EOL . '=== N-2. Data Fase 1, preflight, dan migrasi ===' . PHP_EOL;
$fase1 = $layanan(<<<'PHP'
$u = ['id' => (int) app_db()->query("SELECT id FROM users WHERE username='sbx_v6_bsa_b'")->fetch_row()[0], 'roles' => []];
$s = (int) app_db()->query("SELECT id FROM santri WHERE nis='V6S0001'")->fetch_row()[0];
$svc = bsa_service();
$svc->setoran($u, $s, ['idempotency_key' => 'migrasi-' . bin2hex(random_bytes(6)), 'nominal' => 100000, 'metode' => 'Tunai'], 'web');
$svc->penarikan($u, $s, ['idempotency_key' => 'migrasi-' . bin2hex(random_bytes(6)), 'nominal' => 30000, 'metode' => 'Tunai', 'tujuan' => 'Uji migrasi'], 'web');
$t = $svc->terbitkanTagihan($u, ['idempotency_key' => 'migrasi-' . bin2hex(random_bytes(6)), 'santri_id' => $s, 'periode' => '2026-10', 'jatuh_tempo' => '2026-10-10', 'komponen' => [['nama' => 'Uji', 'nominal' => 100000]]], 'web');
echo $svc->saldo($u, $s)['saldo'] . '|' . $t['id'] . '|' . (tagihan_service()->tersedia() ? 'f2' : 'tanpa-f2');
PHP);
[$kode, $out] = $fase1($dbA);
$assert($kode === 0 && preg_match('/^70000\|\d+\|tanpa-f2$/', trim($out)) === 1, 'Pada keadaan 027: setoran, penarikan, dan tagihan manual Fase 1 berjalan (saldo Rp70.000; fitur Fase 2 tidak tersedia)');
$tagihan027 = (int) explode('|', trim($out))[1];
[$kode, $out] = $sh('php bin/v6_phase2_preflight.php ' . escapeshellarg($manifest), $dbA);
$isiManifest = json_decode((string) @file_get_contents($manifest), true);
$assert($kode === 0 && ($isiManifest['fase'] ?? '') === 'v6-fase2' && count($isiManifest['id_fingerprints'] ?? []) === 9 && isset($isiManifest['legacy_counts']['bsa_tagihan'])
    && str_contains($out, 'Migrasi 027 (fondasi BSA) tercatat') && str_contains($out, 'kolom terbangkit'), 'Preflight Fase 2 lulus dan menulis manifest (tabel lama termasuk 027, sidik jari ID + isi uang 027)');
$sumSebelum = $checksum($m);
$kolomSebelum = $kolom($m);
$assert(count($sumSebelum) === 29, 'Checksum ' . count($sumSebelum) . ' tabel (identitas, penugasan, V2–V5, PSB, enam tabel 027) dicatat sebelum migrasi');
[$kode, $out] = $sh('php bin/migrate.php up', $dbA);
$assert($kode === 0 && str_contains($out, '028_v6_fase2_tagihan_bulanan.sql'), 'bin/migrate.php up menerapkan 028');

// ===================================================================== N-3
echo PHP_EOL . '=== N-3. Verifier sesudah migrasi ===' . PHP_EOL;
[$kode, $out] = $sh('php bin/v6_phase2_verify.php ' . escapeshellarg($manifest), $dbA);
$assert($kode === 0 && !str_contains($out, '[gagal]'), 'Verifier Fase 2 dengan manifest lulus (' . substr_count($out, '[lulus]') . ' pemeriksaan)');
$assert(str_contains($out, 'Jumlah referensi yatim tabel Fase 2: 0') && str_contains($out, 'Kunci asing tabel Fase 2 terpasang (28 dari 28)') && str_contains($out, 'Kunci asing tabel BSA terpasang (22 dari 22)'),
    '28 kunci asing Fase 2 + 22 kunci asing 027, 0 referensi yatim');
$assert(str_contains($out, '[lulus] Sidik jari bsa_tagihan tetap') && str_contains($out, '[lulus] Sidik jari bsa_saldo tetap') && str_contains($out, '[lulus] Sidik jari santri_wali_relasi tetap'),
    'Sidik jari isi uang 027 (tagihan, komponen, ledger, saldo) dan ID identitas tetap');
$assert($checksum($m) === $sumSebelum && $kolom($m) === $kolomSebelum, 'Checksum dan struktur kolom tabel inti + 027 tetap sesudah migrasi (028 tidak mengubah tabel lama)');
$checks = (int) $m->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME IN ($fase2) AND CONSTRAINT_TYPE = 'CHECK'")->fetch_row()[0];
$assert($checks === 22, "Dua puluh dua CHECK basis data terpasang pada tabel Fase 2 ($checks)");
$assert((int) $m->query("SELECT COUNT(*) FROM bsa_tagihan_riwayat WHERE tagihan_id = $tagihan027")->fetch_row()[0] === 0, 'Tagihan era 027 tidak diberi riwayat palsu (versi 1 tanpa riwayat tetap sah)');

// ===================================================================== N-4
echo PHP_EOL . '=== N-4. Migrasi idempoten dan aditif ===' . PHP_EOL;
$mysql = 'MYSQL_PWD=' . escapeshellarg((string) app_config('database.password')) . ' mysql -h' . escapeshellarg((string) app_config('database.host')) . ' -P' . (int) app_config('database.port')
    . ' -u' . escapeshellarg((string) app_config('database.username'));
[$kode, $out] = $sh($mysql . ' ' . $dbA . ' < database/migrations/028_v6_fase2_tagihan_bulanan.sql', $dbA);
$assert($kode === 0 && !preg_match('/ERROR/i', $out), 'SQL 028 dijalankan ulang tanpa galat (CREATE TABLE IF NOT EXISTS)');
$assert($checksum($m) === $sumSebelum && $tercatat($m) === 1 && $tabelF2($m) === 9, 'Jalan ulang tidak mengubah data lama dan tidak menggandakan catatan migrasi');
$sumber = (string) preg_replace(['/--.*$/m', '/ON UPDATE CURRENT_TIMESTAMP/i'], '', (string) file_get_contents($root . '/database/migrations/028_v6_fase2_tagihan_bulanan.sql'));
$assert(!preg_match('/\b(DROP|DELETE|UPDATE|TRUNCATE|ALTER|RENAME|INSERT)\b/i', $sumber) && substr_count($sumber, 'CREATE TABLE IF NOT EXISTS') === 9 && !preg_match('/\b(FLOAT|DOUBLE|REAL|DECIMAL)\b/i', $sumber),
    'Migrasi 028 aditif murni: 9 CREATE TABLE IF NOT EXISTS, tanpa DROP/DELETE/UPDATE/ALTER/INSERT, tanpa tipe pecahan');
$rb = (string) preg_replace('/--.*$/m', '', (string) file_get_contents($root . '/database/rollbacks/028_v6_fase2_tagihan_bulanan.sql'));
preg_match_all('/DROP TABLE IF EXISTS (\w+)/', $rb, $drop);
sort($drop[1]);
$harap = \App\Bsa\TagihanRepository::TABEL;
sort($harap);
$assert($drop[1] === $harap && !preg_match('/\b(DELETE|UPDATE|TRUNCATE|ALTER)\b/i', $rb) && str_contains($rb, 'FROM bsa_pembayaran)'),
    'Rollback 028 hanya melepas sembilan tabel Fase 2; penjaga memeriksa tabel 028 dan bsa_pembayaran (027)');

// ===================================================================== N-5
echo PHP_EOL . '=== N-5. Data Fase 2 memblokir rollback ===' . PHP_EOL;
$fase2Data = $layanan(<<<'PHP'
$u = ['id' => (int) app_db()->query("SELECT id FROM users WHERE username='sbx_v6_bsa_b'")->fetch_row()[0], 'roles' => []];
$svc = tagihan_service();
$k = static fn (): string => 'migrasi-' . bin2hex(random_bytes(6));
$svc->buatTarif($u, ['idempotency_key' => $k(), 'unit' => 'SMP', 'berlaku_mulai' => '2026-11', 'komponen' => [['nama' => 'Syahriah', 'nominal' => 300000]]], 'web');
$p = $svc->pratinjau($u, ['periode' => '2026-11', 'jatuh_tempo' => '2026-11-10']);
$m = $svc->terbitkanMassal($u, ['idempotency_key' => $k(), 'periode' => '2026-11', 'jatuh_tempo' => '2026-11-10', 'pratinjau_hash' => $p['hash']], 'web');
$t = (int) app_db()->query("SELECT MIN(id) FROM bsa_tagihan WHERE periode = '2026-11'")->fetch_row()[0];
$b = $svc->catatPembayaran($u, $t, ['idempotency_key' => $k(), 'nominal' => 100000, 'sumber' => 'Tunai', 'tanggal_transaksi' => date('Y-m-d')], 'web');
echo $m['jumlah_terbit'] . '|' . $b['sisa'];
PHP);
[$kode, $out] = $fase2Data($dbA);
$assert($kode === 0 && preg_match('/^\d+\|200000$/', trim($out)) === 1, 'Tarif, penerbitan massal (' . explode('|', trim($out))[0] . ' tagihan), dan pembayaran Rp100.000 lewat layanan');
[$kode, $out] = $sh('php bin/v6_phase2_verify.php', $dbA);
$assert($kode === 0 && str_contains($out, 'Jumlah referensi yatim tabel Fase 2: 0'), 'Verifier dengan data Fase 2: 0 yatim, invarians lulus');
[$kode, $out] = $sh('php bin/v6_phase2_rollback_preflight.php', $dbA);
$assert($kode === 1 && str_contains($out, '[blokir] bsa_pembayaran = 1') && str_contains($out, '[blokir] bsa_tarif = 1') && str_contains($out, 'Jangan rollback 028'), 'Preflight rollback MEMBLOKIR bila ada data Fase 2');
$sebelum = (int) $m->query('SELECT COUNT(*) FROM bsa_pembayaran_kuitansi')->fetch_row()[0];
[$kode] = $sh('php bin/migrate.php rollback', $dbA);
$assert($kode !== 0 && $tabelF2($m) === 9 && $tercatat($m) === 1 && (int) $m->query('SELECT COUNT(*) FROM bsa_pembayaran_kuitansi')->fetch_row()[0] === $sebelum && $sebelum === 1,
    'Rollback tanpa preflight ditolak penjaga SQL: sembilan tabel dan data tetap, 028 tetap tercatat');
[$kode, $out] = $sh('php bin/v6_phase1_rollback_preflight.php', $dbA);
$assert($kode === 1 && str_contains($out, 'bukan 027'), 'Preflight rollback 027 juga memblokir selama 028 terpasang (urutan migrasi)');

// ===================================================================== N-6
echo PHP_EOL . '=== N-6. Rollback salinan tanpa data Fase 2 ===' . PHP_EOL;
[$kode] = $sh('V6F1_DB=' . $dbB . ' bash tests/v6_phase1_db.sh', $dbB);
$mb = $buka($dbB);
[$kode2] = $sh('php bin/v6_phase2_rollback_preflight.php && php bin/migrate.php rollback', $dbB);
$assert($kode === 0 && $kode2 === 0 && $tabelF2($mb) === 0, 'Salinan kedua dibangun lalu dikembalikan ke 027: ' . $dbB);
[$kode, $out] = $fase1($dbB);
[$kode2] = $sh('php bin/migrate.php up', $dbB);
$assert($kode === 0 && str_starts_with(trim($out), '70000|') && $kode2 === 0 && $tabelF2($mb) === 9, 'Data Fase 1 dicatat pada 027 (saldo Rp70.000, satu tagihan manual), lalu 028 dipasang tanpa data Fase 2');
$manifestB = $scratch . '/manifest-b.json';
[$kode] = $sh('php bin/v6_phase2_preflight.php ' . escapeshellarg($manifestB), $dbB);
$sumB = $checksum($mb);
$kolomB = $kolom($mb);
[$kode, $out] = $sh('php bin/v6_phase2_rollback_preflight.php', $dbB);
$assert($kode === 0 && str_contains($out, 'Rollback 028 aman'), 'Preflight rollback lulus pada tabel Fase 2 kosong (data Fase 1 di tabel 027 tidak memblokir)');
[$kode, $out] = $sh('php bin/migrate.php rollback', $dbB);
$assert($kode === 0 && str_contains($out, '028_v6_fase2_tagihan_bulanan.sql') && $tabelF2($mb) === 0, 'Rollback 028 dijalankan; sembilan tabel dilepas');
[$kode, $out] = $sh('php bin/v6_phase2_verify.php --rollback ' . escapeshellarg($manifestB), $dbB);
$assert($kode === 0 && !str_contains($out, '[gagal]'), 'Verifier --rollback dengan manifest: tabel lama, jumlah baris, sidik jari ID dan isi uang 027 tetap');
$assert($checksum($mb) === $sumB && $kolom($mb) === $kolomB, 'Checksum dan struktur kolom tabel inti + 027 tetap sesudah rollback');
[$kode, $out] = $sh('php bin/v6_phase1_verify.php', $dbB);
$assert($kode === 0, 'Verifier Fase 1 lulus sesudah rollback 028 (saldo = ledger, total = Σ komponen)');

// ===================================================================== N-7
echo PHP_EOL . '=== N-7. Kode Fase 2 sesudah rollback ===' . PHP_EOL;
$sesudah = $layanan(<<<'PHP'
$u = ['id' => (int) app_db()->query("SELECT id FROM users WHERE username='sbx_v6_bsa_b'")->fetch_row()[0], 'roles' => []];
$s = (int) app_db()->query("SELECT id FROM santri WHERE nis='V6S0002'")->fetch_row()[0];
$t = tagihan_service();
$h = [];
foreach (['tarif' => fn () => $t->daftarTarif($u), 'tunggakan' => fn () => $t->tunggakan($u, []), 'pratinjau' => fn () => $t->pratinjau($u, ['periode' => '2026-12', 'jatuh_tempo' => '2026-12-10'])] as $n => $f) {
    try { $f(); $h[] = $n . '=200'; } catch (\App\Bsa\BsaException $e) { $h[] = $n . '=' . $e->status; }
}
$tg = (int) app_db()->query("SELECT MIN(id) FROM bsa_tagihan")->fetch_row()[0];
$r = $t->rincianTagihan($u, $tg);
$h[] = 'rincian=' . (isset($r['komponen']) && $r['pembayaran'] === [] ? 'fase1' : 'lain');
$svc = bsa_service();
$svc->setoran($u, $s, ['idempotency_key' => 'sesudah-' . bin2hex(random_bytes(6)), 'nominal' => 5000, 'metode' => 'Tunai'], 'web');
$b = $svc->terbitkanTagihan($u, ['idempotency_key' => 'sesudah-' . bin2hex(random_bytes(6)), 'santri_id' => $s, 'periode' => '2026-12', 'jatuh_tempo' => '2026-12-10', 'komponen' => [['nama' => 'X', 'nominal' => 1000]]], 'web');
$h[] = 'fase1=' . ($b['status'] === 201 ? 'ok' : 'gagal');
echo implode(' ', $h);
PHP);
[$kode, $out] = $sesudah($dbB);
$assert($kode === 0 && str_contains($out, 'tarif=503') && str_contains($out, 'tunggakan=503') && str_contains($out, 'pratinjau=503') && str_contains($out, 'rincian=fase1') && str_contains($out, 'fase1=ok'),
    'Sesudah rollback: fitur Fase 2 503, rincian tagihan kembali ke kontrak Fase 1, setoran dan tagihan manual Fase 1 tetap berjalan (' . trim($out) . ')');
$port = 8994;
$log = $scratch . '/server.log';
$server = proc_open(['php', '-S', '127.0.0.1:' . $port, $root . '/tests/redesain_web_tahap1_router.php'], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root, array_merge(getenv(), ['DB_NAME' => $dbB]));
usleep(700000);
$jar = $scratch . '/jar';
$curl = static function (string $path, ?array $post = null) use ($port, $jar): array {
    $h = curl_init('http://127.0.0.1:' . $port . $path);
    curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 30]);
    if ($post !== null) {
        curl_setopt_array($h, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post)]);
    }
    $body = (string) curl_exec($h);
    $status = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
    curl_close($h);

    return [$status, $body];
};
[, $b] = $curl('/portal/index.php');
preg_match('/name="_csrf" value="([^"]+)"/', $b, $mc);
$curl('/admin/cek_login.php', ['_csrf' => $mc[1] ?? '', 'username' => 'sbx_v6_bsa_b', 'password' => 'Sandbox#123']);
[$s1, $b1] = $curl('/portal/bsa.php');
[$s2] = $curl('/portal/bsa_tarif.php');
[$s3] = $curl('/portal/bsa_tunggakan.php');
$tg = (int) $mb->query('SELECT MIN(id) FROM bsa_tagihan')->fetch_row()[0];
[$s4, $b4] = $curl('/portal/bsa_tagihan.php?id=' . $tg);
proc_terminate($server);
$assert($s1 === 200 && !str_contains($b1, 'bsa_tarif.php') && $s2 === 503 && $s3 === 503 && $s4 === 200 && !str_contains($b4, 'id="ah-pembayaran"'),
    "Web sesudah rollback: daftar 200 tanpa navigasi Fase 2, tarif/tunggakan 503, rincian tagihan 200 tanpa bagian pembayaran ($s1/$s2/$s3/$s4)");
$assert(!preg_match('/PHP (Warning|Notice|Fatal|Deprecated)|Uncaught/', (string) @file_get_contents($log)), 'Log server sesudah rollback tanpa galat PHP');

// ===================================================================== N-8
echo PHP_EOL . '=== N-8. Migrasi ulang sesudah rollback ===' . PHP_EOL;
$manifestC = $scratch . '/manifest-c.json';
[$kode] = $sh('php bin/v6_phase2_preflight.php ' . escapeshellarg($manifestC), $dbB);
[$kode2] = $sh('php bin/migrate.php up', $dbB);
[$kode3, $out] = $sh('php bin/v6_phase2_verify.php ' . escapeshellarg($manifestC), $dbB);
$assert($kode === 0 && $kode2 === 0 && $kode3 === 0 && !str_contains($out, '[gagal]'), 'Migrasi ulang 028 lulus verifier Fase 1 + 2 dengan manifest');
$manual = $layanan(<<<'PHP'
$u = ['id' => (int) app_db()->query("SELECT id FROM users WHERE username='sbx_v6_bsa_b'")->fetch_row()[0], 'roles' => []];
$s = (int) app_db()->query("SELECT id FROM santri WHERE nis='V6S0003'")->fetch_row()[0];
echo bsa_service()->terbitkanTagihan($u, ['idempotency_key' => 'ulang-' . bin2hex(random_bytes(6)), 'santri_id' => $s, 'periode' => '2027-01', 'jatuh_tempo' => '2027-01-10', 'komponen' => [['nama' => 'X', 'nominal' => 1000]]], 'web')['status'];
PHP);
[$kode, $out] = $manual($dbB);
[$kode2, $out2] = $sh('php bin/v6_phase2_rollback_preflight.php', $dbB);
$assert($kode === 0 && trim($out) === '201' && $kode2 === 1 && str_contains($out2, '[blokir] bsa_tagihan_riwayat = 1'),
    'Tagihan manual Fase 1 sesudah 028 menulis riwayat versi 1 → preflight rollback 028 memblokir');
[$kode] = $sh('php bin/v6_phase2_verify.php', $dbB);
$assert($kode === 0, 'Verifier lulus dengan campuran tagihan era 027 (tanpa riwayat) dan pasca-028 (dengan riwayat)');

array_map('unlink', glob($scratch . '/*') ?: []);
rmdir($scratch);
echo PHP_EOL . 'Lulus: ' . $lulus . ' · Gagal: ' . count($gagal) . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
