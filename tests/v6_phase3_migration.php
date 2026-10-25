<?php

declare(strict_types=1);

/**
 * Uji migrasi dan rollback 029 (PRD V6 Fase 3) pada SALINAN database uji.
 *
 *   V6_RUN_MIGRATION=1 php tests/v6_phase3_migration.php
 *
 * Langkah (masing-masing DB `test_*_test` tersendiri, dibangun ulang):
 *   O-1  salinan berantai V6 Fase 2 (fixture V1–V5 + V6) dikembalikan ke
 *        keadaan 028 lewat preflight + rollback 029; verifier --rollback;
 *   O-2  data Fase 1–2 dibuat pada keadaan 028 (setoran tunai/transfer,
 *        penarikan, tagihan, pembayaran); preflight Fase 3 menulis manifest
 *        (jumlah baris seluruh tabel lama + sidik jari ID dan isi uang
 *        027/028) dan checksum tabel inti + 027/028; `migrate up` → 029;
 *   O-3  verifier Fase 3 dengan manifest: invarians Fase 1–2 + tabel 029,
 *        6 kunci asing, 0 yatim, 5 CHECK; data lama tetap;
 *   O-4  SQL 029 dijalankan ulang langsung (idempoten); sumber aditif murni;
 *        rollback hanya melepas tabel 029 dengan penjaga data;
 *   O-5  data Fase 3 lewat layanan (setoran transfer menunggu, belanja,
 *        bayar dari tabungan) → verifier lulus; preflight rollback MEMBLOKIR
 *        tiga sumber; `migrate rollback` ditolak penjaga SQL;
 *   O-6  salinan kedua tanpa data Fase 3: rollback 029 lulus, verifier
 *        --rollback dengan manifest, checksum tabel inti + 027/028 tetap;
 *   O-7  kode Fase 3 sesudah rollback: fitur tabungan lengkap 503, setoran
 *        transfer kembali perilaku Fase 1–2, halaman tabungan/rekonsiliasi
 *        503, navigasi Tabungan tersembunyi, fitur Fase 1–2 tetap berjalan;
 *   O-8  migrasi ulang lulus verifier dengan manifest; satu belanja pasca-029
 *        kembali memblokir rollback.
 */

if (getenv('V6_RUN_MIGRATION') !== '1') {
    fwrite(STDERR, "Set V6_RUN_MIGRATION=1 untuk menjalankan.\n");
    exit(2);
}
$root = dirname(__DIR__);
$dbA = 'test_v6_f3_migrasi_test';
$dbB = 'test_v6_f3_rollback_test';

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
    psb_pendaftar, psb_pembayaran, bsa_tagihan, bsa_tagihan_komponen, bsa_tabungan_akun, bsa_tabungan_transaksi, bsa_pembayaran, bsa_idempotensi,
    bsa_tarif, bsa_tarif_komponen, bsa_penyesuaian, bsa_penerbitan, bsa_penerbitan_santri, bsa_tagihan_penyesuaian, bsa_tagihan_riwayat, bsa_pembayaran_kuitansi, bsa_pembayaran_pembatalan';
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
       OR TABLE_NAME LIKE 'bsa\\_%') AND TABLE_NAME <> 'bsa_setoran_transfer'
    ORDER BY TABLE_NAME, ORDINAL_POSITION")->fetch_all(MYSQLI_ASSOC)));
$tabelF3 = static fn (mysqli $m): int => (int) $m->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bsa_setoran_transfer'")->fetch_row()[0];
$tercatat = static fn (mysqli $m): int => (int) $m->query("SELECT COUNT(*) FROM schema_migrations WHERE migration = '029_v6_fase3_tabungan.sql'")->fetch_row()[0];
$scratch = sys_get_temp_dir() . '/v6f3-migrasi-' . bin2hex(random_bytes(3));
mkdir($scratch);
$manifest = $scratch . '/manifest.json';
$layanan = static function (string $kode) use ($sh): Closure {
    return static fn (string $db): array => $sh('php -r ' . escapeshellarg("require 'app/bootstrap.php';\n" . $kode), $db);
};

// ===================================================================== O-1
echo '=== O-1. Salinan uji pada keadaan 028 ===' . PHP_EOL;
[$kode] = $sh('V6F2_DB=' . $dbA . ' bash tests/v6_phase2_db.sh', $dbA);
$m = $buka($dbA);
$assert($kode === 0 && $tercatat($m) === 1 && $tabelF3($m) === 1, 'Salinan berantai V6 Fase 2 (fixture V1–V6 sintetis) dibangun dengan 027 + 028 + 029: ' . $dbA);
[$kode, $out] = $sh('php bin/v6_phase3_rollback_preflight.php', $dbA);
$assert($kode === 0 && str_contains($out, 'Rollback 029 aman'), 'Preflight rollback 029 lulus (tanpa data Fase 3, 029 terakhir)');
[$kode, $out] = $sh('php bin/migrate.php rollback', $dbA);
$assert($kode === 0 && str_contains($out, '029_v6_fase3_tabungan.sql') && $tabelF3($m) === 0, 'Rollback 029 mengembalikan salinan ke keadaan 028');
[$kode, $out] = $sh('php bin/v6_phase3_verify.php --rollback', $dbA);
$assert($kode === 0 && !str_contains($out, '[gagal]'), 'Verifier --rollback: tabel 029 dilepas, 029 tidak tercatat, lima belas tabel 027/028 tetap');

// ===================================================================== O-2
echo PHP_EOL . '=== O-2. Data Fase 1–2, preflight, dan migrasi ===' . PHP_EOL;
$fase12 = $layanan(<<<'PHP'
$u = ['id' => (int) app_db()->query("SELECT id FROM users WHERE username='sbx_v6_bsa_b'")->fetch_row()[0], 'roles' => []];
$s = (int) app_db()->query("SELECT id FROM santri WHERE nis='V6S0001'")->fetch_row()[0];
$k = static fn (): string => 'migrasi-' . bin2hex(random_bytes(6));
$tab = tabungan_service();
$tab->setoran($u, $s, ['idempotency_key' => $k(), 'nominal' => 100000, 'metode' => 'Tunai'], 'web');
$tr = $tab->setoran($u, $s, ['idempotency_key' => $k(), 'nominal' => 20000, 'metode' => 'Transfer', 'referensi' => 'TRF-MIGRASI-1'], 'web');
$tab->penarikan($u, $s, ['idempotency_key' => $k(), 'nominal' => 30000, 'metode' => 'Tunai', 'tujuan' => 'Uji migrasi'], 'web');
$t = bsa_service()->terbitkanTagihan($u, ['idempotency_key' => $k(), 'santri_id' => $s, 'periode' => '2026-10', 'jatuh_tempo' => '2026-10-10', 'komponen' => [['nama' => 'Uji', 'nominal' => 100000]]], 'web');
tagihan_service()->catatPembayaran($u, $t['id'], ['idempotency_key' => $k(), 'nominal' => 40000, 'sumber' => 'Tunai', 'tanggal_transaksi' => date('Y-m-d')], 'web');
echo bsa_service()->saldo($u, $s)['saldo'] . '|' . $tr['status'] . '|' . ($tab->tersedia() ? 'f3' : 'tanpa-f3');
PHP);
[$kode, $out] = $fase12($dbA);
$assert($kode === 0 && trim($out) === '90000|201|tanpa-f3', 'Pada keadaan 028: setoran, setoran transfer (sah seketika seperti Fase 1–2), penarikan, tagihan, dan pembayaran berjalan; saldo Rp90.000 (' . trim($out) . ')');
[$kode, $out] = $sh('php bin/v6_phase3_preflight.php ' . escapeshellarg($manifest), $dbA);
$isiManifest = json_decode((string) @file_get_contents($manifest), true);
$assert($kode === 0 && ($isiManifest['fase'] ?? '') === 'v6-fase3' && count($isiManifest['id_fingerprints'] ?? []) === 10 && isset($isiManifest['legacy_counts']['bsa_pembayaran'], $isiManifest['legacy_counts']['bsa_tarif'])
    && !isset($isiManifest['legacy_counts']['bsa_setoran_transfer']) && str_contains($out, 'Migrasi 028 (tagihan bulanan lengkap) tercatat') && str_contains($out, 'Baris berjenis Fase 3 di tabel 027 sebelum 029: 0'),
    'Preflight Fase 3 lulus dan menulis manifest (tabel lama termasuk 027/028, sidik jari ID + isi uang termasuk pembayaran)');
$sumSebelum = $checksum($m);
$kolomSebelum = $kolom($m);
$assert(count($sumSebelum) === 38, 'Checksum ' . count($sumSebelum) . ' tabel (identitas, penugasan, V2–V5, PSB, 15 tabel 027/028) dicatat sebelum migrasi');
[$kode, $out] = $sh('php bin/migrate.php up', $dbA);
$assert($kode === 0 && str_contains($out, '029_v6_fase3_tabungan.sql'), 'bin/migrate.php up menerapkan 029');

// ===================================================================== O-3
echo PHP_EOL . '=== O-3. Verifier sesudah migrasi ===' . PHP_EOL;
[$kode, $out] = $sh('php bin/v6_phase3_verify.php ' . escapeshellarg($manifest), $dbA);
$assert($kode === 0 && !str_contains($out, '[gagal]'), 'Verifier Fase 3 dengan manifest lulus (' . substr_count($out, '[lulus]') . ' pemeriksaan)');
$assert(str_contains($out, 'Jumlah referensi yatim tabel Fase 3: 0') && str_contains($out, 'Kunci asing tabel Fase 3 terpasang (6 dari 6)') && str_contains($out, 'Seluruh invarians Fase 1–2 lulus'),
    '6 kunci asing Fase 3, 0 referensi yatim, invarians Fase 1–2 lulus');
$assert(str_contains($out, '[lulus] Sidik jari bsa_tabungan tetap') && str_contains($out, '[lulus] Sidik jari bsa_pembayaran tetap') && str_contains($out, '[lulus] Sidik jari santri_wali_relasi tetap'),
    'Sidik jari isi uang 027/028 (ledger, saldo, tagihan, pembayaran) dan ID identitas tetap');
$assert($checksum($m) === $sumSebelum && $kolom($m) === $kolomSebelum, 'Checksum dan struktur kolom tabel inti + 027/028 tetap sesudah migrasi (029 tidak mengubah tabel lama)');
$checks = (int) $m->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'bsa_setoran_transfer' AND CONSTRAINT_TYPE = 'CHECK'")->fetch_row()[0];
$assert($checks === 5, "Lima CHECK basis data terpasang pada tabel Fase 3 ($checks)");
$tolakCheck = $m->query("INSERT INTO bsa_setoran_transfer (santri_id, nominal, referensi, status, penugasan_id, pengurus_id, dicatat_oleh, idempotency_key)
    SELECT s.id, 1000, 'X', 'Sah', a.id, a.pengurus_id, u.id, 'uji-check-sah' FROM santri s, bendahara_bulanan_assignments a, users u WHERE s.nis = 'V6S0001' AND u.username = 'sbx_v6_bsa_b' AND a.pengurus_id = u.pengurus_id LIMIT 1") === false;
$assert($tolakCheck && (int) $m->query("SELECT COUNT(*) FROM bsa_setoran_transfer")->fetch_row()[0] === 0, 'CHECK menolak setoran transfer Sah tanpa baris ledger (status ⇔ transaksi_id)');

// ===================================================================== O-4
echo PHP_EOL . '=== O-4. Migrasi idempoten dan aditif ===' . PHP_EOL;
$mysql = 'MYSQL_PWD=' . escapeshellarg((string) app_config('database.password')) . ' mysql -h' . escapeshellarg((string) app_config('database.host')) . ' -P' . (int) app_config('database.port')
    . ' -u' . escapeshellarg((string) app_config('database.username'));
[$kode, $out] = $sh($mysql . ' ' . $dbA . ' < database/migrations/029_v6_fase3_tabungan.sql', $dbA);
$assert($kode === 0 && !preg_match('/ERROR/i', $out), 'SQL 029 dijalankan ulang tanpa galat (CREATE TABLE IF NOT EXISTS)');
$assert($checksum($m) === $sumSebelum && $tercatat($m) === 1 && $tabelF3($m) === 1, 'Jalan ulang tidak mengubah data lama dan tidak menggandakan catatan migrasi');
$sumber = (string) preg_replace('/--.*$/m', '', (string) file_get_contents($root . '/database/migrations/029_v6_fase3_tabungan.sql'));
$assert(!preg_match('/\b(DROP|DELETE|UPDATE|TRUNCATE|ALTER|RENAME|INSERT)\b/i', $sumber) && substr_count($sumber, 'CREATE TABLE IF NOT EXISTS') === 1 && !preg_match('/\b(FLOAT|DOUBLE|REAL|DECIMAL)\b/i', $sumber),
    'Migrasi 029 aditif murni: 1 CREATE TABLE IF NOT EXISTS, tanpa DROP/DELETE/UPDATE/ALTER/INSERT, tanpa tipe pecahan');
$rb = (string) preg_replace('/--.*$/m', '', (string) file_get_contents($root . '/database/rollbacks/029_v6_fase3_tabungan.sql'));
preg_match_all('/DROP TABLE IF EXISTS (\w+)/', $rb, $drop);
$assert($drop[1] === ['bsa_setoran_transfer'] && !preg_match('/\b(DELETE|UPDATE|TRUNCATE|ALTER)\b/i', $rb) && str_contains($rb, 'FROM bsa_setoran_transfer)') && str_contains($rb, "jenis IN ('Belanja', 'Pembalik', 'PembayaranTagihan')")
    && str_contains($rb, "sumber = 'Tabungan'"), 'Rollback 029 hanya melepas bsa_setoran_transfer; penjaga memeriksa antrean transfer, jenis ledger Fase 3, dan pembayaran dari tabungan');

// ===================================================================== O-5
echo PHP_EOL . '=== O-5. Data Fase 3 memblokir rollback ===' . PHP_EOL;
$fase3Data = $layanan(<<<'PHP'
$u = ['id' => (int) app_db()->query("SELECT id FROM users WHERE username='sbx_v6_bsa_b'")->fetch_row()[0], 'roles' => []];
$s = (int) app_db()->query("SELECT id FROM santri WHERE nis='V6S0001'")->fetch_row()[0];
$k = static fn (): string => 'migrasi-' . bin2hex(random_bytes(6));
$tab = tabungan_service();
$tr = $tab->setoran($u, $s, ['idempotency_key' => $k(), 'nominal' => 15000, 'metode' => 'Transfer', 'referensi' => 'TRF-MIGRASI-2'], 'web');
$tab->penarikan($u, $s, ['idempotency_key' => $k(), 'jenis' => 'Belanja', 'nominal' => 10000, 'metode' => 'Tunai', 'tujuan' => 'Uji migrasi'], 'web');
$t = (int) app_db()->query("SELECT id FROM bsa_tagihan WHERE santri_id = $s AND periode = '2026-10'")->fetch_row()[0];
$b = tagihan_service()->bayarDariTabungan($u, $t, ['idempotency_key' => $k(), 'nominal' => 50000, 'setuju' => '1'], 'web');
echo $tr['status'] . '|' . $b['saldo'] . '|' . $b['sisa'];
PHP);
[$kode, $out] = $fase3Data($dbA);
$assert($kode === 0 && trim($out) === '202|30000|10000', 'Setoran transfer menunggu (202), belanja Rp10.000, dan bayar Rp50.000 dari tabungan (saldo Rp30.000, sisa Rp10.000) lewat layanan');
[$kode, $out] = $sh('php bin/v6_phase3_verify.php', $dbA);
$assert($kode === 0 && str_contains($out, 'Jumlah referensi yatim tabel Fase 3: 0'), 'Verifier dengan data Fase 3: 0 yatim, invarians lulus');
[$kode, $out] = $sh('php bin/v6_phase3_rollback_preflight.php', $dbA);
$assert($kode === 1 && str_contains($out, '[blokir] bsa_setoran_transfer = 1') && str_contains($out, '[blokir] bsa_tabungan_transaksi (data Fase 3) = 2') && str_contains($out, '[blokir] bsa_pembayaran (data Fase 3) = 1')
    && str_contains($out, 'Jangan rollback 029'), 'Preflight rollback MEMBLOKIR tiga sumber data Fase 3');
[$kode] = $sh('php bin/migrate.php rollback', $dbA);
$assert($kode !== 0 && $tabelF3($m) === 1 && $tercatat($m) === 1 && (int) $m->query('SELECT COUNT(*) FROM bsa_setoran_transfer')->fetch_row()[0] === 1,
    'Rollback tanpa preflight ditolak penjaga SQL: tabel dan data tetap, 029 tetap tercatat');
[$kode, $out] = $sh('php bin/v6_phase2_rollback_preflight.php', $dbA);
$assert($kode === 1 && str_contains($out, 'bukan 028'), 'Preflight rollback 028 juga memblokir selama 029 terpasang (urutan migrasi)');

// ===================================================================== O-6
echo PHP_EOL . '=== O-6. Rollback salinan tanpa data Fase 3 ===' . PHP_EOL;
[$kode] = $sh('V6F2_DB=' . $dbB . ' bash tests/v6_phase2_db.sh', $dbB);
$mb = $buka($dbB);
[$kode2] = $sh('php bin/v6_phase3_rollback_preflight.php && php bin/migrate.php rollback', $dbB);
$assert($kode === 0 && $kode2 === 0 && $tabelF3($mb) === 0, 'Salinan kedua dibangun lalu dikembalikan ke 028: ' . $dbB);
[$kode, $out] = $fase12($dbB);
[$kode2] = $sh('php bin/migrate.php up', $dbB);
$assert($kode === 0 && trim($out) === '90000|201|tanpa-f3' && $kode2 === 0 && $tabelF3($mb) === 1, 'Data Fase 1–2 dicatat pada 028, lalu 029 dipasang tanpa data Fase 3');
$manifestB = $scratch . '/manifest-b.json';
[$kode] = $sh('php bin/v6_phase3_preflight.php ' . escapeshellarg($manifestB), $dbB);
$sumB = $checksum($mb);
$kolomB = $kolom($mb);
[$kode, $out] = $sh('php bin/v6_phase3_rollback_preflight.php', $dbB);
$assert($kode === 0 && str_contains($out, 'Rollback 029 aman'), 'Preflight rollback lulus: data Fase 1–2 (setoran/penarikan/pembayaran tunai) tidak memblokir');
[$kode, $out] = $sh('php bin/migrate.php rollback', $dbB);
$assert($kode === 0 && str_contains($out, '029_v6_fase3_tabungan.sql') && $tabelF3($mb) === 0, 'Rollback 029 dijalankan; tabel dilepas');
[$kode, $out] = $sh('php bin/v6_phase3_verify.php --rollback ' . escapeshellarg($manifestB), $dbB);
$assert($kode === 0 && !str_contains($out, '[gagal]'), 'Verifier --rollback dengan manifest: tabel lama, jumlah baris, sidik jari ID dan isi uang 027/028 tetap');
$assert($checksum($mb) === $sumB && $kolom($mb) === $kolomB, 'Checksum dan struktur kolom tabel inti + 027/028 tetap sesudah rollback');
[$kode] = $sh('php bin/v6_phase2_verify.php', $dbB);
$assert($kode === 0, 'Verifier Fase 1–2 lulus sesudah rollback 029');

// ===================================================================== O-7
echo PHP_EOL . '=== O-7. Kode Fase 3 sesudah rollback ===' . PHP_EOL;
$sesudah = $layanan(<<<'PHP'
$u = ['id' => (int) app_db()->query("SELECT id FROM users WHERE username='sbx_v6_bsa_b'")->fetch_row()[0], 'roles' => []];
$s = (int) app_db()->query("SELECT id FROM santri WHERE nis='V6S0002'")->fetch_row()[0];
$tab = tabungan_service();
$tag = tagihan_service();
$k = static fn (): string => 'sesudah-' . bin2hex(random_bytes(6));
$h = [];
$tg = (int) app_db()->query("SELECT MIN(id) FROM bsa_tagihan")->fetch_row()[0];
$x = (int) app_db()->query("SELECT MIN(id) FROM bsa_tabungan_transaksi")->fetch_row()[0];
foreach (['rekonsiliasi' => fn () => $tab->rekonsiliasi($u, []), 'mutasi' => fn () => $tab->daftarMutasi($u, []), 'kuitansi' => fn () => $tab->transaksi($u, $x),
    'koreksi' => fn () => $tab->koreksi($u, $x, ['idempotency_key' => $k(), 'alasan' => 'Sesudah rollback'], 'web'),
    'belanja' => fn () => $tab->penarikan($u, $s, ['idempotency_key' => $k(), 'jenis' => 'Belanja', 'nominal' => 1, 'metode' => 'Tunai', 'tujuan' => 'Uji'], 'web'),
    'bayartab' => fn () => $tag->bayarDariTabungan($u, $tg, ['idempotency_key' => $k(), 'nominal' => 1, 'setuju' => '1'], 'web')] as $n => $f) {
    try { $f(); $h[] = $n . '=200'; } catch (\App\Bsa\BsaException $e) { $h[] = $n . '=' . $e->status; }
}
$tr = $tab->setoran($u, $s, ['idempotency_key' => $k(), 'nominal' => 5000, 'metode' => 'Transfer', 'referensi' => 'TRF-SESUDAH'], 'web');
$h[] = 'transfer=' . $tr['status'] . (array_key_exists('status_setoran', $tr) ? '-f3' : '-f12');
$d = $tab->santri($u, $s);
$h[] = 'santri=' . ($d['setoran_transfer'] === [] ? 'tanpa-antrean' : 'ada');
$p = $tab->penarikan($u, $s, ['idempotency_key' => $k(), 'nominal' => 1000, 'metode' => 'Tunai', 'tujuan' => 'Uji'], 'web');
$h[] = 'penarikan=' . $p['status'];
$t = bsa_service()->terbitkanTagihan($u, ['idempotency_key' => $k(), 'santri_id' => $s, 'periode' => '2026-12', 'jatuh_tempo' => '2026-12-10', 'komponen' => [['nama' => 'X', 'nominal' => 1000]]], 'web');
$b = $tag->catatPembayaran($u, $t['id'], ['idempotency_key' => $k(), 'nominal' => 1000, 'sumber' => 'Tunai', 'tanggal_transaksi' => date('Y-m-d')], 'web');
$h[] = 'fase2=' . $b['status'];
echo implode(' ', $h);
PHP);
[$kode, $out] = $sesudah($dbB);
$assert($kode === 0 && str_contains($out, 'rekonsiliasi=503') && str_contains($out, 'mutasi=503') && str_contains($out, 'kuitansi=503') && str_contains($out, 'koreksi=503') && str_contains($out, 'belanja=503')
    && str_contains($out, 'bayartab=503') && str_contains($out, 'transfer=201-f12') && str_contains($out, 'santri=tanpa-antrean') && str_contains($out, 'penarikan=201') && str_contains($out, 'fase2=201'),
    'Sesudah rollback: fitur Fase 3 503, setoran transfer kembali sah seketika (Fase 1–2), penarikan dan pembayaran tunai tetap berjalan (' . trim($out) . ')');
$port = 8992;
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
$sTs = (int) $mb->query("SELECT id FROM santri WHERE nis = 'V6S0002'")->fetch_row()[0];
[$s1, $b1] = $curl('/portal/bsa_tunggakan.php');
[$s2] = $curl('/portal/bsa_tabungan.php');
[$s3] = $curl('/portal/bsa_rekonsiliasi.php');
[$s4, $b4] = $curl('/portal/bsa_santri.php?id=' . $sTs);
[$s5, $b5] = $curl('/portal/bsa_transaksi.php?santri_id=' . $sTs . '&jenis=setoran');
proc_terminate($server);
$assert($s1 === 200 && str_contains($b1, 'bsa_tarif.php') && !str_contains($b1, 'bsa_tabungan.php') && $s2 === 503 && $s3 === 503 && $s4 === 200 && !str_contains($b4, 'bsa_tabungan_kuitansi.php')
    && $s5 === 200 && !str_contains($b5, 'name="terverifikasi"'),
    "Web sesudah rollback: navigasi tanpa Tabungan/Rekonsiliasi, halaman Fase 3 503, rincian santri dan formulir setoran Fase 1 tanpa elemen Fase 3 ($s1/$s2/$s3/$s4/$s5)");
$assert(!preg_match('/PHP (Warning|Notice|Fatal|Deprecated)|Uncaught/', (string) @file_get_contents($log)), 'Log server sesudah rollback tanpa galat PHP');

// ===================================================================== O-8
echo PHP_EOL . '=== O-8. Migrasi ulang sesudah rollback ===' . PHP_EOL;
$manifestC = $scratch . '/manifest-c.json';
[$kode] = $sh('php bin/v6_phase3_preflight.php ' . escapeshellarg($manifestC), $dbB);
[$kode2] = $sh('php bin/migrate.php up', $dbB);
[$kode3, $out] = $sh('php bin/v6_phase3_verify.php ' . escapeshellarg($manifestC), $dbB);
$assert($kode === 0 && $kode2 === 0 && $kode3 === 0 && !str_contains($out, '[gagal]'), 'Migrasi ulang 029 lulus verifier Fase 1–3 dengan manifest');
$belanja = $layanan(<<<'PHP'
$u = ['id' => (int) app_db()->query("SELECT id FROM users WHERE username='sbx_v6_bsa_b'")->fetch_row()[0], 'roles' => []];
$s = (int) app_db()->query("SELECT id FROM santri WHERE nis='V6S0001'")->fetch_row()[0];
echo tabungan_service()->penarikan($u, $s, ['idempotency_key' => 'ulang-' . bin2hex(random_bytes(6)), 'jenis' => 'Belanja', 'nominal' => 1000, 'metode' => 'Tunai', 'tujuan' => 'Uji'], 'web')['status'];
PHP);
[$kode, $out] = $belanja($dbB);
[$kode2, $out2] = $sh('php bin/v6_phase3_rollback_preflight.php', $dbB);
$assert($kode === 0 && trim($out) === '201' && $kode2 === 1 && str_contains($out2, '[blokir] bsa_tabungan_transaksi (data Fase 3) = 1') && str_contains($out2, '[lulus] bsa_setoran_transfer = 0'),
    'Satu belanja pasca-029 (tanpa antrean transfer) sudah cukup memblokir rollback 029');

array_map('unlink', glob($scratch . '/*') ?: []);
rmdir($scratch);
echo PHP_EOL . 'Lulus: ' . $lulus . ' · Gagal: ' . count($gagal) . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
