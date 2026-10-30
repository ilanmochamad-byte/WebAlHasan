<?php

declare(strict_types=1);

/**
 * Uji integrasi perbaikan "Terbitkan tagihan" satu santri memakai tarif
 * (sesudah PRD V6) — langsung pada `TagihanService` dan MariaDB/MySQL uji.
 *
 *   V6F5_DB=test_v6_satu_santri_test bash tests/v6_phase5_db.sh
 *   V6_RUN_INTEGRATION=1 DB_NAME=test_v6_satu_santri_test php tests/v6_tagihan_satu_santri_integration.php
 *
 *   T-1  pratinjau satu santri: satu baris, komponen tarif + penyesuaian santri,
 *        total = tarif ± penyesuaian; santri lain di unit sama tidak ikut;
 *   T-2  penerbitan satu santri: tepat satu tagihan, komponen tarif tersalin,
 *        baris penyesuaian, catatan penerbitan sasaran 1; santri lain unit sama
 *        tanpa tagihan; ulang kunci sama → replay; kunci baru → tidak ganda;
 *   T-3  data berubah sesudah pratinjau → 409 tanpa tagihan;
 *   T-4  hak: petugas unit lain/santri nonaktif → 404; admin/wali → 403;
 *        cakupan dipersempit sebelum replay → 404;
 *   T-5  isian manual hanya untuk santri tanpa kelas atau unit tanpa tarif;
 *   T-6  penerbitan massal tanpa santri_id tetap sama (hash, hasil);
 *   T-7  verifier Fase 1–3 lulus.
 *
 * Mengubah data uji; bangun ulang DB sebelum mengulang.
 */

if (getenv('V6_RUN_INTEGRATION') !== '1') {
    fwrite(STDERR, "Set V6_RUN_INTEGRATION=1 untuk menjalankan.\n");
    exit(2);
}
$dbName = getenv('DB_NAME') ?: 'test_v6_satu_santri_test';
if (!preg_match('/^test_.*_test$/', $dbName)) {
    fwrite(STDERR, "Ditolak: DB uji harus bernama test_*_test.\n");
    exit(2);
}
putenv('DB_NAME=' . $dbName);
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Bsa\BsaException;

$lulus = 0;
$gagal = [];
$assert = static function (bool $ok, string $pesan) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    $ok ? $lulus++ : $gagal[] = $pesan;
};
$db = app_db();
$satu = static fn (string $sql): mixed => $db->query($sql)->fetch_row()[0] ?? null;
$jalankan = static function (string $sql) use ($db): void {
    if ($db->query($sql) === false) {
        throw new RuntimeException($db->error);
    }
};
$akun = static fn (string $u): array => ['id' => (int) $satu("SELECT id FROM users WHERE username = '" . $db->real_escape_string($u) . "'"), 'roles' => []];
$sid = static fn (string $nis): int => (int) $satu("SELECT id FROM santri WHERE nis = '" . $db->real_escape_string($nis) . "'");
$tolak = static function (callable $fn): ?int {
    try {
        $fn();
    } catch (BsaException $e) {
        return $e->status;
    }

    return null;
};
$k = static fn (string $a): string => $a . '-' . bin2hex(random_bytes(6));
$tag = tagihan_service();
$petugas = $akun('sbx_v6_bsa_b');
$petugasT = $akun('sbx_v6_bsa_tsanawi');
$s1 = $sid('V6S0001');
$s2 = $sid('V6S0002');
$s3 = $sid('V6S0003');
$s4 = $sid('V6S0004');
$periode = date('Y-m');
$jt = $periode . '-20';
$smpLain = array_map('intval', array_column($db->query("SELECT s.id FROM santri s JOIN plotting_kelas pk ON pk.id_santri = s.id AND pk.status = 'Aktif'
    AND pk.id_tahun = (SELECT id FROM tahun_ajaran WHERE status = 'Aktif' AND archived_at IS NULL ORDER BY id DESC LIMIT 1) JOIN kelas kl ON kl.id = pk.id_kelas
    WHERE s.is_active = 1 AND s.archived_at IS NULL AND kl.jenjang = 'SMP' AND s.id <> $s1")->fetch_all(MYSQLI_NUM), 0));
$assert(min($s1, $s2, $s3, $s4, $petugas['id']) > 0 && count($smpLain) >= 1 && (int) $satu('SELECT COUNT(*) FROM bsa_tagihan') === 0,
    'Fixture: santri SMP + ' . count($smpLain) . ' santri SMP lain, belum ada tagihan');

// Tarif SMP dua komponen; potongan untuk santri SMP.
$tarif = $tag->buatTarif($petugas, ['idempotency_key' => $k('tf'), 'unit' => 'SMP', 'berlaku_mulai' => $periode,
    'komponen' => [['nama' => 'Syahriah', 'nominal' => '150000'], ['nama' => 'Makan', 'nominal' => '200000']]], 'web');
$tag->buatPenyesuaian($petugas, $s1, ['idempotency_key' => $k('pj'), 'jenis' => 'Potongan', 'nama' => 'Potongan anak guru', 'nominal' => '50000',
    'berlaku_mulai' => $periode, 'alasan' => 'Anak guru pesantren'], 'web');

echo PHP_EOL . '=== T-1. Pratinjau satu santri ===' . PHP_EOL;
$p = $tag->pratinjau($petugas, ['santri_id' => $s1, 'periode' => $periode, 'jatuh_tempo' => $jt]);
$r = $p['rows'][0] ?? [];
$assert(count($p['rows']) === 1 && $r['santri_id'] === $s1 && $r['hasil'] === 'Terbit' && $r['total'] === 300000 && $p['santri_id'] === $s1,
    'Satu baris santri itu saja: tarif Rp350.000 − potongan Rp50.000 = Rp300.000');
$assert($r['komponen'] === [['nama' => 'Syahriah', 'nominal' => 150000], ['nama' => 'Makan', 'nominal' => 200000]]
    && count($r['penyesuaian']) === 1 && !isset($r['penyesuaian'][0]['alasan']), 'Baris memuat komponen tarif dan penyesuaian (tanpa alasan internal)');
$massal = $tag->pratinjau($petugas, ['periode' => $periode, 'jatuh_tempo' => $jt, 'unit' => 'SMP']);
$assert(!isset($massal['rows'][0]['komponen']) && !isset($massal['santri_id']) && count($massal['rows']) === count($smpLain) + 1 && $massal['hash'] !== $p['hash'],
    'Pratinjau massal tidak berubah bentuk (tanpa komponen/santri_id), hash berbeda dari pratinjau satu santri');

echo PHP_EOL . '=== T-2. Penerbitan satu santri ===' . PHP_EOL;
$kunci = $k('ts');
$h = $tag->terbitkanMassal($petugas, ['idempotency_key' => $kunci, 'santri_id' => $s1, 'periode' => $periode, 'jatuh_tempo' => $jt, 'pratinjau_hash' => $p['hash']], 'web');
$t = $db->query("SELECT id, total, jatuh_tempo, versi FROM bsa_tagihan WHERE santri_id = $s1 AND periode = '$periode'")->fetch_assoc();
$komp = $db->query('SELECT nama, nominal FROM bsa_tagihan_komponen WHERE tagihan_id = ' . (int) $t['id'] . ' ORDER BY urutan')->fetch_all(MYSQLI_ASSOC);
$assert($h['status'] === 201 && $h['jumlah_terbit'] === 1 && (int) $t['total'] === 300000 && $t['jatuh_tempo'] === $jt
    && $komp === [['nama' => 'Syahriah', 'nominal' => '150000'], ['nama' => 'Makan', 'nominal' => '200000']]
    && (int) $satu('SELECT COUNT(*) FROM bsa_tagihan_penyesuaian WHERE tagihan_id = ' . (int) $t['id']) === 1,
    'Tepat satu tagihan Rp300.000: komponen tarif tersalin, satu baris potongan');
$assert((int) $satu("SELECT COUNT(*) FROM bsa_tagihan WHERE periode = '$periode'") === 1, 'Santri SMP lain tidak mendapat tagihan dari tombol satu santri');
$b = $tag->penerbitan($petugas, $h['id']);
$assert($b['jumlah']['sasaran'] === 1 && $b['jumlah']['terbit'] === 1 && $b['rows'][0]['santri']['id'] === $s1 && $b['rows'][0]['tagihan_id'] === (int) $t['id'],
    'Catatan penerbitan: sasaran 1 santri, hasil menunjuk tagihan itu');
$assert((int) $satu("SELECT COUNT(*) FROM audit_logs WHERE action = 'bsa.tagihan.terbit' AND entity_id = " . (int) $t['id']) === 1
    && (int) $satu("SELECT COUNT(*) FROM notifikasi_outbox WHERE event_type = 'v6_bsa_tagihan_terbit' AND data_json LIKE '%\"tagihan_id\":" . (int) $t['id'] . "}%'") >= 1,
    'Audit penerbitan dan pemberitahuan wali tercatat seperti penerbitan massal');
$ulang = $tag->terbitkanMassal($petugas, ['idempotency_key' => $kunci, 'santri_id' => $s1, 'periode' => $periode, 'jatuh_tempo' => $jt, 'pratinjau_hash' => $p['hash']], 'web');
$assert($ulang['replayed'] === true && $ulang['id'] === $h['id'] && (int) $satu("SELECT COUNT(*) FROM bsa_tagihan WHERE santri_id = $s1") === 1, 'Kirim ulang kunci sama → hasil sama, tanpa tagihan kedua');
$p2 = $tag->pratinjau($petugas, ['santri_id' => $s1, 'periode' => $periode, 'jatuh_tempo' => $jt]);
$assert($p2['rows'][0]['hasil'] === 'SudahAda' && $p2['rows'][0]['tagihan_id'] === (int) $t['id']
    && $tolak(static fn () => $tag->terbitkanMassal($petugas, ['idempotency_key' => $k('x'), 'santri_id' => $s1, 'periode' => $periode, 'jatuh_tempo' => $jt, 'pratinjau_hash' => $p2['hash']], 'web')) === 422,
    'Pratinjau ulang: sudah ada; kunci baru tidak menerbitkan tagihan kedua (422)');
$assert($tolak(static fn () => $tag->terbitkanMassal($petugas, ['idempotency_key' => $kunci, 'santri_id' => $smpLain[0], 'periode' => $periode, 'jatuh_tempo' => $jt, 'pratinjau_hash' => $p['hash']], 'web')) === 409,
    'Kunci sama untuk santri lain → 409 (kunci idempotensi terikat santri)');

echo PHP_EOL . '=== T-3. Berubah sesudah pratinjau ===' . PHP_EOL;
$lain = $smpLain[0];
$pl = $tag->pratinjau($petugas, ['santri_id' => $lain, 'periode' => $periode, 'jatuh_tempo' => $jt]);
$tag->buatPenyesuaian($petugas, $lain, ['idempotency_key' => $k('pj'), 'jenis' => 'Tambahan', 'nama' => 'Kegiatan', 'nominal' => '10000', 'berlaku_mulai' => $periode, 'alasan' => 'Kegiatan bulan ini'], 'web');
$assert($tolak(static fn () => $tag->terbitkanMassal($petugas, ['idempotency_key' => $k('b'), 'santri_id' => $lain, 'periode' => $periode, 'jatuh_tempo' => $jt, 'pratinjau_hash' => $pl['hash']], 'web')) === 409
    && (int) $satu("SELECT COUNT(*) FROM bsa_tagihan WHERE santri_id = $lain") === 0, 'Penyesuaian ditambah sesudah pratinjau → 409 tanpa tagihan');
$pl = $tag->pratinjau($petugas, ['santri_id' => $lain, 'periode' => $periode, 'jatuh_tempo' => $jt]);
$assert($pl['rows'][0]['total'] === 360000, 'Pratinjau baru memuat tambahan: Rp360.000');

echo PHP_EOL . '=== T-4. Hak ===' . PHP_EOL;
$assert($tolak(static fn () => $tag->pratinjau($petugasT, ['santri_id' => $s1, 'periode' => $periode, 'jatuh_tempo' => $jt])) === 404
    && $tolak(static fn () => $tag->terbitkanMassal($petugasT, ['idempotency_key' => $k('t'), 'santri_id' => $lain, 'periode' => $periode, 'jatuh_tempo' => $jt, 'pratinjau_hash' => $pl['hash']], 'web')) === 404,
    'Petugas unit Tsanawi atas santri SMP: pratinjau dan penerbitan 404');
$assert($tolak(static fn () => $tag->pratinjau($petugas, ['santri_id' => $s4, 'periode' => $periode, 'jatuh_tempo' => $jt])) === 404
    && $tolak(static fn () => $tag->pratinjau($petugas, ['santri_id' => 999999, 'periode' => $periode, 'jatuh_tempo' => $jt])) === 404, 'Santri nonaktif/tidak ada → 404');
foreach (['sbx_admin' => 403, 'sbx_v6_admin_bsa' => 403, 'sbx_v6_wali_smp' => 403, 'sbx_pengurus_biasa' => 403] as $u => $kode) {
    $assert($tolak(static fn () => $tag->pratinjau($akun($u), ['santri_id' => $lain, 'periode' => $periode, 'jatuh_tempo' => $jt])) === $kode
        && $tolak(static fn () => $tag->terbitkanMassal($akun($u), ['idempotency_key' => $k('a'), 'santri_id' => $lain, 'periode' => $periode, 'jatuh_tempo' => $jt, 'pratinjau_hash' => $pl['hash']], 'web')) === $kode,
        "$u: pratinjau dan penerbitan → $kode");
}
$kunciLain = $k('c');
$hl = $tag->terbitkanMassal($petugas, ['idempotency_key' => $kunciLain, 'santri_id' => $lain, 'periode' => $periode, 'jatuh_tempo' => $jt, 'pratinjau_hash' => $pl['hash']], 'web');
$jalankan("UPDATE santri SET is_active = 0 WHERE id = $lain");
$assert($hl['status'] === 201 && $tolak(static fn () => $tag->terbitkanMassal($petugas, ['idempotency_key' => $kunciLain, 'santri_id' => $lain, 'periode' => $periode, 'jatuh_tempo' => $jt, 'pratinjau_hash' => $pl['hash']], 'web')) === 404,
    'Santri dinonaktifkan sesudah terbit: replay kunci sama tidak lagi mengembalikan hasil (404)');
$jalankan("UPDATE santri SET is_active = 1 WHERE id = $lain");

echo PHP_EOL . '=== T-5. Isian manual hanya tanpa tarif ===' . PHP_EOL;
$assert($tag->alasanTagihanManual($petugas, $s1, $periode) === null && $tag->alasanTagihanManual($petugas, $smpLain[count($smpLain) - 1], $periode) === null,
    'Santri SMP bertarif: isian manual tidak ditawarkan');
$assert($tag->alasanTagihanManual($petugas, $s3, $periode) === 'Belum ditempatkan di kelas', 'Santri tanpa kelas: isian manual boleh (belum ditempatkan di kelas)');
$assert($tag->alasanTagihanManual($petugas, $s2, $periode) === 'Belum ada tarif unit untuk periode ini', 'Santri Tsanawi tanpa tarif unit: isian manual boleh');
$pt = $tag->pratinjau($petugas, ['santri_id' => $s3, 'periode' => $periode, 'jatuh_tempo' => $jt]);
$assert($pt['rows'][0]['hasil'] === 'Dilewati' && $pt['rows'][0]['komponen'] === [], 'Pratinjau santri tanpa kelas: dilewati, tanpa komponen');
$assert($tolak(static fn () => $tag->alasanTagihanManual($petugasT, $s1, $periode)) === 404 && $tolak(static fn () => $tag->alasanTagihanManual($akun('sbx_admin'), $s3, $periode)) === 403,
    'Pemeriksaan isian manual juga menegakkan hak (404 di luar cakupan, 403 admin)');

echo PHP_EOL . '=== T-6. Penerbitan massal tidak berubah ===' . PHP_EOL;
$m = $tag->pratinjau($petugas, ['periode' => $periode, 'jatuh_tempo' => $jt, 'unit' => 'SMP']);
$jml = $m['jumlah'];
$hm = $tag->terbitkanMassal($petugas, ['idempotency_key' => $k('m'), 'periode' => $periode, 'jatuh_tempo' => $jt, 'unit' => 'SMP', 'pratinjau_hash' => $m['hash']], 'web');
$assert($jml['SudahAda'] === 2 && $hm['jumlah_terbit'] === $jml['Terbit'] && (int) $satu("SELECT COUNT(*) FROM bsa_tagihan WHERE periode = '$periode'") === 2 + $jml['Terbit'],
    'Massal unit SMP sesudahnya: 2 sudah ada dilewati, sisanya (' . $jml['Terbit'] . ') terbit — tanpa ganda');

echo PHP_EOL . '=== T-7. Verifier ===' . PHP_EOL;
exec('DB_NAME=' . escapeshellarg($dbName) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/v6_phase3_verify.php') . ' 2>&1', $out, $kode);
$assert($kode === 0, 'Verifier Fase 1–3 lulus');
if ($kode !== 0) {
    echo implode(PHP_EOL, array_filter($out, static fn (string $l): bool => str_contains($l, '[gagal]'))) . PHP_EOL;
}

echo PHP_EOL . "Ringkasan: {$lulus} lulus, " . count($gagal) . ' gagal' . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
