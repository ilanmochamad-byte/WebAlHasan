<?php

declare(strict_types=1);

/**
 * Uji integrasi layanan PRD V6 Fase 1 — fondasi BSA — langsung pada
 * `BsaService`/`PenugasanService` dan MariaDB/MySQL uji.
 *
 *   bash tests/v6_phase1_db.sh
 *   V6_RUN_INTEGRATION=1 php tests/v6_phase1_integration.php
 *
 * Skenario (kode = kriteria penerimaan Fase 1, PRD V6 §6):
 *   I-1  matriks hak: petugas seluruh unit/unit Tsanawi/tahun ajaran lama,
 *        admin, admin+petugas, wali, pengurus tanpa penugasan, guru, petugas
 *        kesehatan (bukan BSA), pengurus yang belum ditugaskan;
 *   I-2  admin menugaskan satu pengurus BSA lewat fondasi penugasan → hak
 *        kelola; penugasan oleh non-admin ditolak;
 *   I-3  kriteria utama: petugas menerbitkan TEPAT satu tagihan, setoran
 *        Rp100.000 dan penarikan Rp30.000 → saldo Rp70.000; tagihan kedua
 *        periode sama ditolak; snapshot komponen = total;
 *   I-4  idempotensi: kunci+isi sama → satu kredit; kunci sama nominal beda →
 *        409 tanpa mutasi; kunci sama operasi lain → 409;
 *   I-5  konkurensi (proses terpisah): dua penarikan Rp80.000 bersamaan atas
 *        saldo Rp100.000 → paling banyak satu sukses; delapan penarikan
 *        bersamaan → tidak pernah negatif; delapan setoran kunci sama → satu
 *        kredit;
 *   I-6  mutasi ditolak tanpa baris baru: admin, admin+petugas, wali,
 *        pengurus tanpa penugasan, guru, petugas di luar unit, santri
 *        nonaktif/tanpa kelas bagi petugas unit, saldo kurang;
 *   I-7  isolasi baca: wali anak lain, ID tagihan ditebak, daftar per hak;
 *   I-8  pencabutan pada "sesi" yang masih dipakai: penugasan dinonaktifkan,
 *        diakhiri kemarin, akun dinonaktifkan, relasi wali diarsipkan;
 *   I-9  validasi nominal eksak (nol, negatif, pecahan, notasi ilmiah, float,
 *        melebihi batas), metode, referensi transfer, tujuan, periode —
 *        0 baris dan 0 klaim idempotensi tersisa;
 *   I-10 penjaga basis data: CHECK menolak saldo negatif dan nominal nol
 *        walau dilewati layanan; audit `bsa.*` tanpa nominal/referensi;
 *   I-11 preservasi: tabel identitas, PSB, V1–V5 tidak berubah jumlah baris.
 *
 * Mengubah data uji; bangun ulang DB sebelum mengulang.
 */

if (getenv('V6_RUN_INTEGRATION') !== '1') {
    fwrite(STDERR, "Set V6_RUN_INTEGRATION=1 untuk menjalankan.\n");
    exit(2);
}
$dbName = getenv('V6F1_DB') ?: 'test_v6_f1_test';
if (!preg_match('/^test_.*_test$/', $dbName)) {
    fwrite(STDERR, "Ditolak: DB uji harus bernama test_*_test.\n");
    exit(2);
}
putenv('DB_NAME=' . $dbName);
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Bsa\BsaException;
use App\Penugasan\PenugasanJenis;

$lulus = 0;
$gagal = [];
$assert = static function (bool $ok, string $pesan) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    $ok ? $lulus++ : $gagal[] = $pesan;
};
$db = app_db();
$satu = static function (string $sql) use ($db): mixed {
    $r = $db->query($sql);
    if ($r === false) {
        throw new RuntimeException($db->error . ' :: ' . $sql);
    }
    $row = $r->fetch_row();

    return $row[0] ?? null;
};
$jalankan = static function (string $sql) use ($db): void {
    if ($db->query($sql) === false) {
        throw new RuntimeException($db->error . ' :: ' . $sql);
    }
};
$uid = static fn (string $u): int => (int) $satu("SELECT id FROM users WHERE username='" . $db->real_escape_string($u) . "'");
$akun = static fn (string $u): array => ['id' => $uid($u), 'roles' => []];
$sid = static fn (string $nis): int => (int) $satu("SELECT id FROM santri WHERE nis='" . $db->real_escape_string($nis) . "'");
$svc = bsa_service();
$tolak = static function (callable $fn): ?int {
    try {
        $fn();
    } catch (BsaException $e) {
        return $e->status;
    }

    return null;
};
$jumlah = static fn (): array => [
    'transaksi' => (int) $satu('SELECT COUNT(*) FROM bsa_tabungan_transaksi'),
    'tagihan' => (int) $satu('SELECT COUNT(*) FROM bsa_tagihan'),
    'komponen' => (int) $satu('SELECT COUNT(*) FROM bsa_tagihan_komponen'),
    'saldo' => (int) $satu('SELECT COALESCE(SUM(saldo), 0) FROM bsa_tabungan_akun'),
    'idem' => (int) $satu('SELECT COUNT(*) FROM bsa_idempotensi'),
    'pembayaran' => (int) $satu('SELECT COUNT(*) FROM bsa_pembayaran'),
];
$saldo = static fn (int $santri): int => (int) $satu('SELECT COALESCE((SELECT saldo FROM bsa_tabungan_akun WHERE santri_id = ' . $santri . '), 0)');
$kunci = static fn (string $awalan): string => $awalan . '-' . bin2hex(random_bytes(6));

$sSmp = $sid('V6S0001');
$sTsanawi = $sid('V6S0002');
$sTanpa = $sid('V6S0003');
$sNonaktif = $sid('V6S0004');
$assert(min($sSmp, $sTsanawi, $sTanpa, $sNonaktif) > 0, 'Fixture V6 tersedia (empat santri sintetis)');
$assert($jumlah() === ['transaksi' => 0, 'tagihan' => 0, 'komponen' => 0, 'saldo' => 0, 'idem' => 0, 'pembayaran' => 0], 'Tabel BSA mulai kosong: saldo awal semua santri Rp0');

$lindungi = ['santri', 'wali', 'santri_wali', 'users', 'pengurus', 'kelas', 'plotting_kelas', 'psb_pendaftar', 'izin', 'kesehatan_kunjungan',
    'kesehatan_ringkasan', 'v3_pelanggaran', 'v4_nilai', 'v4_rapor', 'bendahara_bulanan_assignments', 'kesehatan_assignments'];
$preservasi = static function () use ($lindungi, $satu): array {
    $hasil = [];
    foreach ($lindungi as $t) {
        $ada = (int) $satu("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$t'");
        $hasil[$t] = $ada === 1 ? (int) $satu("SELECT COUNT(*) FROM `$t`") : null;
    }

    return $hasil;
};
$awal = $preservasi();

// ===================================================================== I-1
echo PHP_EOL . '=== I-1. Matriks hak ===' . PHP_EOL;
$harap = [
    'sbx_v6_bsa_b' => [true, true, 'petugas', null],
    'sbx_v6_bsa_tsanawi' => [true, true, 'petugas', ['Tsanawi']],
    'sbx_v6_bsa_lama' => [true, true, 'petugas', null],
    'sbx_v6_admin_bsa' => [true, false, 'admin_petugas', null],
    'sbx_admin' => [true, false, 'admin', null],
    'sbx_v6_wali_smp' => [true, false, 'wali', null],
    'sbx_pengurus_biasa' => [false, false, null, null],
    'sbx_guru_biasa' => [false, false, null, null],
    'sbx_v5_petugas_b' => [false, false, null, null],
    'sbx_v6_bsa' => [false, false, null, null],
];
foreach ($harap as $u => [$baca, $kelola, $sumber, $jenjang]) {
    $a = $svc->akses($akun($u));
    $assert($a['baca'] === $baca && $a['kelola'] === $kelola && $a['sumber'] === $sumber && $a['jenjang'] === $jenjang,
        "$u → baca=" . var_export($baca, true) . ' kelola=' . var_export($kelola, true) . ' sumber=' . var_export($sumber, true));
}
$tahunLama = (string) $satu("SELECT ta.status FROM bendahara_bulanan_assignments a JOIN users u ON u.pengurus_id = a.pengurus_id JOIN tahun_ajaran ta ON ta.id = a.tahun_ajaran_id WHERE u.username = 'sbx_v6_bsa_lama'");
$assert($tahunLama !== 'Aktif' && capabilities()->featureSource($akun('sbx_v6_bsa_lama'), PenugasanJenis::CAP_BSA_TABUNGAN) === 'penugasan',
    'Penugasan BSA pada tahun ajaran non-aktif tetap berlaku (tidak gugur saat semester berganti, keputusan 25 Oktober 2026)');
$assert(capabilities()->featureSource($akun('sbx_v6_bsa_b'), PenugasanJenis::CAP_PEMBIAYAAN_BULANAN_TAGIHAN) === 'penugasan'
    && capabilities()->featureSource($akun('sbx_v6_bsa_b'), PenugasanJenis::CAP_BSA_TABUNGAN) === 'penugasan',
    'Satu penugasan BSA menghasilkan capability tagihan lama dan bsa.tabungan baru');
$assert(PenugasanJenis::definisi(PenugasanJenis::BENDAHARA_BULANAN)['tabel'] === 'bendahara_bulanan_assignments' && !in_array(PenugasanJenis::BENDAHARA_BULANAN, PenugasanJenis::MENDATANG, true),
    'Jenis bendahara_bulanan tetap memakai tabel fondasi dan tidak lagi "modul mendatang"');

// ===================================================================== I-2
echo PHP_EOL . '=== I-2. Admin menugaskan satu pengurus BSA ===' . PHP_EOL;
$pBsa = (int) $satu("SELECT pengurus_id FROM users WHERE username = 'sbx_v6_bsa'");
$tahunAktif = (int) $satu("SELECT id FROM tahun_ajaran WHERE status = 'Aktif' AND archived_at IS NULL ORDER BY id DESC LIMIT 1");
$input = ['pengurus_id' => $pBsa, 'tahun_ajaran_id' => $tahunAktif, 'jenjang' => '', 'tanggal_mulai' => date('Y-m-d'), 'catatan' => 'Uji V6 Fase 1'];
$ditolak = false;
try {
    penugasan_service()->buat(PenugasanJenis::BENDAHARA_BULANAN, $input, $uid('sbx_v6_bsa_b'));
} catch (Throwable) {
    $ditolak = true;
}
$assert($ditolak && (int) $satu("SELECT COUNT(*) FROM bendahara_bulanan_assignments WHERE pengurus_id = $pBsa") === 0, 'Non-admin tidak dapat menugaskan BSA');
$tugasBsa = penugasan_service()->buat(PenugasanJenis::BENDAHARA_BULANAN, $input, $uid('sbx_admin'));
$a = $svc->akses($akun('sbx_v6_bsa'));
$assert($tugasBsa > 0 && $a['kelola'] && $a['sumber'] === 'petugas' && $a['jenjang'] === null, 'Admin menugaskan sbx_v6_bsa sebagai Petugas BSA seluruh unit → hak kelola');

// ===================================================================== I-3
echo PHP_EOL . '=== I-3. Satu tagihan, setoran Rp100.000, penarikan Rp30.000 ===' . PHP_EOL;
$petugas = $akun('sbx_v6_bsa');
$t = $svc->terbitkanTagihan($petugas, ['idempotency_key' => $kunci('i3-tagihan'), 'santri_id' => $sSmp, 'periode' => '2026-10', 'jatuh_tempo' => '2026-10-10',
    'komponen' => [['nama' => 'Syahriah uji', 'nominal' => '250.000'], ['nama' => 'Makan uji', 'nominal' => 300000], ['nama' => '', 'nominal' => '']]], 'web');
$tagihan = $svc->tagihan($petugas, $t['id']);
$assert($t['status'] === 201 && $tagihan['total'] === 550000 && $tagihan['sisa'] === 550000 && count($tagihan['komponen']) === 2 && $tagihan['status_bayar'] === 'Belum dibayar',
    'Tagihan terbit: total = Σ komponen Rp550.000, baris kosong diabaikan, status Belum dibayar');
$assert((int) $satu("SELECT COUNT(*) FROM bsa_tagihan WHERE santri_id = $sSmp") === 1, 'Tepat satu tagihan tersimpan');
$assert($tolak(static fn () => $svc->terbitkanTagihan($petugas, ['idempotency_key' => $kunci('i3-ganda'), 'santri_id' => $sSmp, 'periode' => '2026-10', 'jatuh_tempo' => '2026-10-15',
    'komponen' => [['nama' => 'Lain', 'nominal' => 1]]], 'web')) === 409 && (int) $satu("SELECT COUNT(*) FROM bsa_tagihan WHERE santri_id = $sSmp") === 1,
    'Tagihan kedua santri+periode sama (kunci lain) → 409, tetap satu tagihan');
$s1 = $svc->setoran($petugas, $sSmp, ['idempotency_key' => $kunci('i3-setor'), 'nominal' => '100.000', 'metode' => 'Tunai'], 'web');
$s2 = $svc->penarikan($petugas, $sSmp, ['idempotency_key' => $kunci('i3-tarik'), 'nominal' => 30000, 'metode' => 'Tunai', 'tujuan' => 'Beli kitab uji'], 'api');
$assert($s1['saldo_sesudah'] === 100000 && $s2['saldo_sesudah'] === 70000, 'Setoran Rp100.000 lalu penarikan Rp30.000 → saldo sesudah Rp70.000');
$assert($svc->saldo($petugas, $sSmp) === ['santri_id' => $sSmp, 'saldo' => 70000] && $saldo($sSmp) === 70000, 'Saldo santri terbaca Rp70.000 (layanan dan basis data)');
$assert($svc->saldo($akun('sbx_admin'), $sSmp)['saldo'] === 70000 && $svc->saldo($akun('sbx_v6_wali_smp'), $sSmp)['saldo'] === 70000, 'Admin dan wali anak itu membaca saldo yang sama Rp70.000');
$x = $db->query("SELECT dicatat_oleh, disetujui_oleh, disetujui_pada, penugasan_id, status, tujuan FROM bsa_tabungan_transaksi WHERE id = {$s2['id']}")->fetch_assoc();
$assert((int) $x['dicatat_oleh'] === $petugas['id'] && (int) $x['disetujui_oleh'] === $petugas['id'] && $x['disetujui_pada'] !== null && (int) $x['penugasan_id'] === $tugasBsa
    && $x['status'] === 'Sah' && $x['tujuan'] === 'Beli kitab uji', 'Penarikan menyimpan pencatat = penyetuju, waktu, penugasan, status Sah, dan tujuan');
$assert($svc->tagihan($petugas, $t['id'])['sisa'] === 550000 && $saldo($sSmp) === 70000, 'Tagihan terbit tidak memotong tabungan otomatis');

// ===================================================================== I-4
echo PHP_EOL . '=== I-4. Idempotensi ===' . PHP_EOL;
$k = $kunci('i4-setor');
$sebelum = $jumlah();
$r1 = $svc->setoran($petugas, $sTanpa, ['idempotency_key' => $k, 'nominal' => 50000, 'metode' => 'Transfer', 'referensi' => 'TRF-UJI-1'], 'api');
$r2 = $svc->setoran($petugas, $sTanpa, ['idempotency_key' => $k, 'nominal' => 50000, 'metode' => 'Transfer', 'referensi' => 'TRF-UJI-1'], 'api');
$assert($r1['id'] === $r2['id'] && !$r1['replayed'] && $r2['replayed'] && $jumlah()['transaksi'] === $sebelum['transaksi'] + 1 && $saldo($sTanpa) === 50000,
    'Dua setoran kunci+isi sama → tepat satu kredit (kedua = replay)');
$sebelum = $jumlah();
$assert($tolak(static fn () => $svc->setoran($petugas, $sTanpa, ['idempotency_key' => $k, 'nominal' => 60000, 'metode' => 'Transfer', 'referensi' => 'TRF-UJI-1'], 'api')) === 409
    && $jumlah() === $sebelum, 'Kunci sama nominal berbeda → 409 tanpa mutasi');
$assert($tolak(static fn () => $svc->penarikan($petugas, $sTanpa, ['idempotency_key' => $k, 'nominal' => 50000, 'metode' => 'Transfer', 'referensi' => 'TRF-UJI-1', 'tujuan' => 'Uji'], 'api')) === 409
    && $jumlah() === $sebelum, 'Kunci sama dipakai untuk penarikan → 409 tanpa mutasi');
$assert($tolak(static fn () => $svc->setoran($petugas, $sSmp, ['idempotency_key' => $k, 'nominal' => 50000, 'metode' => 'Transfer', 'referensi' => 'TRF-UJI-1'], 'api')) === 409
    && $jumlah() === $sebelum, 'Kunci sama untuk santri lain → 409 tanpa mutasi');
$kt = $kunci('i4-tagihan');
$isiTagihan = ['idempotency_key' => $kt, 'santri_id' => $sTanpa, 'periode' => '2026-11', 'jatuh_tempo' => '2026-11-10', 'komponen' => [['nama' => 'Syahriah uji', 'nominal' => 1000]]];
$t1 = $svc->terbitkanTagihan($petugas, $isiTagihan, 'web');
$t2 = $svc->terbitkanTagihan($petugas, $isiTagihan, 'web');
$assert($t1['id'] === $t2['id'] && $t2['replayed'] && (int) $satu("SELECT COUNT(*) FROM bsa_tagihan WHERE santri_id = $sTanpa") === 1, 'Penerbitan tagihan diulang dengan kunci sama → tagihan yang sama');

// ===================================================================== I-5
echo PHP_EOL . '=== I-5. Konkurensi (proses terpisah) ===' . PHP_EOL;
$scratch = sys_get_temp_dir() . '/v6f1-' . bin2hex(random_bytes(4));
mkdir($scratch);
$serbu = static function (array $permintaan) use ($scratch, $dbName): array {
    $aba = $scratch . '/mulai-' . bin2hex(random_bytes(3));
    $proc = [];
    foreach ($permintaan as $i => $p) {
        $h = proc_open(array_merge(['php', dirname(__DIR__) . '/tests/v6_phase1_worker.php'], array_map('strval', $p), [$aba]),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge(getenv(), ['DB_NAME' => $dbName]));
        $proc[$i] = [$h, $pipes];
    }
    usleep(400000);
    touch($aba);
    $hasil = [];
    foreach ($proc as [$h, $pipes]) {
        $out = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        proc_close($h);
        $hasil[] = json_decode(trim((string) $out), true) ?? ['ok' => false, 'status' => -1];
    }

    return $hasil;
};
$petugasB = $uid('sbx_v6_bsa_b');
$sTsanawiSaldo = $svc->setoran($akun('sbx_v6_bsa_b'), $sTsanawi, ['idempotency_key' => $kunci('i5-modal'), 'nominal' => 100000, 'metode' => 'Tunai'], 'web');
$assert($sTsanawiSaldo['saldo_sesudah'] === 100000, 'Saldo awal santri uji konkurensi Rp100.000');
$hasil = $serbu([[$petugasB, $sTsanawi, 'penarikan', $kunci('i5-a'), 80000], [$petugasB, $sTsanawi, 'penarikan', $kunci('i5-b'), 80000]]);
$sukses = count(array_filter($hasil, static fn ($r) => $r['ok']));
$assert($sukses <= 1 && $saldo($sTsanawi) === 100000 - 80000 * $sukses && $saldo($sTsanawi) >= 0
    && count(array_filter($hasil, static fn ($r) => !$r['ok'] && $r['status'] === 422)) === 2 - $sukses,
    'Dua penarikan Rp80.000 bersamaan atas saldo Rp100.000 → ' . $sukses . ' sukses, saldo Rp' . $saldo($sTsanawi) . ' (' . json_encode($hasil) . ')');
$assert($sukses === 1, 'Tepat satu dari dua penarikan bersamaan berhasil (yang lain ditolak saldo kurang)');
$svc->setoran($akun('sbx_v6_bsa_b'), $sTsanawi, ['idempotency_key' => $kunci('i5-modal2'), 'nominal' => 80000, 'metode' => 'Tunai'], 'web');
$sebelumSaldo = $saldo($sTsanawi);
$permintaan = [];
for ($i = 0; $i < 8; $i++) {
    $permintaan[] = [$petugasB, $sTsanawi, 'penarikan', $kunci('i5-banyak-' . $i), 30000];
}
$hasil = $serbu($permintaan);
$sukses = count(array_filter($hasil, static fn ($r) => $r['ok']));
$assert($sukses === intdiv($sebelumSaldo, 30000) && $saldo($sTsanawi) === $sebelumSaldo - 30000 * $sukses && $saldo($sTsanawi) >= 0,
    'Delapan penarikan Rp30.000 bersamaan atas Rp' . $sebelumSaldo . ' → ' . $sukses . ' sukses, sisa Rp' . $saldo($sTsanawi) . ', tidak negatif');
$assert(count(array_filter($hasil, static fn ($r) => !$r['ok'] && $r['status'] !== 422)) === 0, 'Penarikan yang gagal ditolak karena saldo kurang (422), bukan galat basis data (' . json_encode($hasil) . ')');
$saldoList = array_map(static fn ($r) => $r['saldo_sesudah'], array_filter($hasil, static fn ($r) => $r['ok']));
$assert(count(array_unique($saldoList)) === count($saldoList), 'Setiap penarikan sukses melihat saldo berbeda (berurutan, bukan uang yang sama)');
$sebelum = $jumlah();
$k = $kunci('i5-sama');
$hasil = $serbu(array_fill(0, 8, [$petugasB, $sTsanawi, 'setoran', $k, 25000]));
$ids = array_unique(array_column(array_filter($hasil, static fn ($r) => $r['ok']), 'id'));
$baru = count(array_filter($hasil, static fn ($r) => $r['ok'] && !$r['replayed']));
$assert(count($ids) === 1 && $baru === 1 && $jumlah()['transaksi'] === $sebelum['transaksi'] + 1 && $jumlah()['saldo'] === $sebelum['saldo'] + 25000,
    'Delapan setoran bersamaan kunci+isi sama → satu kredit Rp25.000, tujuh replay (' . json_encode($hasil) . ')');
$sebelum = $jumlah();
$k = $kunci('i5-beda');
$hasil = $serbu(array_map(static fn ($i) => [$petugasB, $sTsanawi, 'setoran', $k, 1000 + $i], range(1, 6)));
$assert(count(array_filter($hasil, static fn ($r) => $r['ok'])) === 1 && count(array_filter($hasil, static fn ($r) => !$r['ok'] && $r['status'] === 409)) === 5
    && $jumlah()['transaksi'] === $sebelum['transaksi'] + 1, 'Enam setoran bersamaan kunci sama nominal berbeda → satu sukses, lima 409');
$assert((int) $satu('SELECT COUNT(*) FROM bsa_tabungan_akun WHERE saldo < 0') === 0 && (int) $satu("SELECT COUNT(*) FROM bsa_tabungan_transaksi WHERE saldo_sesudah < 0") === 0,
    'Tidak ada saldo negatif setelah seluruh uji konkurensi');
array_map('unlink', glob($scratch . '/*') ?: []);
rmdir($scratch);

// ===================================================================== I-6
echo PHP_EOL . '=== I-6. Mutasi ditolak tanpa baris baru ===' . PHP_EOL;
$sebelum = $jumlah();
$setor = static fn (string $u, int $s) => static fn () => $svc->setoran($akun($u), $s, ['idempotency_key' => $kunci('i6'), 'nominal' => 1000, 'metode' => 'Tunai'], 'web');
$tarik = static fn (string $u, int $s) => static fn () => $svc->penarikan($akun($u), $s, ['idempotency_key' => $kunci('i6'), 'nominal' => 1000, 'metode' => 'Tunai', 'tujuan' => 'Uji tolak'], 'web');
$tagih = static fn (string $u, int $s) => static fn () => $svc->terbitkanTagihan($akun($u), ['idempotency_key' => $kunci('i6'), 'santri_id' => $s, 'periode' => '2026-12', 'jatuh_tempo' => '2026-12-10', 'komponen' => [['nama' => 'X', 'nominal' => 1]]], 'web');
foreach (['sbx_admin' => 403, 'sbx_v6_admin_bsa' => 403, 'sbx_v6_wali_smp' => 403, 'sbx_pengurus_biasa' => 403, 'sbx_guru_biasa' => 403, 'sbx_v5_petugas_b' => 403] as $u => $kode) {
    $assert($tolak($setor($u, $sSmp)) === $kode && $tolak($tarik($u, $sSmp)) === $kode && $tolak($tagih($u, $sSmp)) === $kode, "$u: setoran/penarikan/tagihan → $kode");
}
$assert($tolak($setor('sbx_v6_bsa_tsanawi', $sSmp)) === 404 && $tolak($tarik('sbx_v6_bsa_tsanawi', $sSmp)) === 404 && $tolak($tagih('sbx_v6_bsa_tsanawi', $sSmp)) === 404,
    'Petugas unit Tsanawi atas santri SMP → 404');
$assert($tolak($setor('sbx_v6_bsa_tsanawi', $sTanpa)) === 404, 'Petugas unit atas santri tanpa kelas → 404 (unit tidak terbukti)');
$assert($tolak($setor('sbx_v6_bsa', $sNonaktif)) === 404 && $tolak($tagih('sbx_v6_bsa', $sNonaktif)) === 404, 'Santri nonaktif → 404');
$assert($tolak($setor('sbx_v6_bsa', 999999999)) === 404, 'Santri tidak ada → 404');
$assert($tolak($tarik('sbx_v6_bsa', $sNonaktif)) === 404, 'Penarikan santri nonaktif → 404');
$assert($tolak(static fn () => $svc->penarikan($petugas, $sSmp, ['idempotency_key' => $kunci('i6-lebih'), 'nominal' => 70001, 'metode' => 'Tunai', 'tujuan' => 'Uji lebih'], 'web')) === 422
    && $saldo($sSmp) === 70000, 'Penarikan Rp70.001 atas saldo Rp70.000 → 422, saldo tetap');
$assert($jumlah() === $sebelum, 'Seluruh penolakan I-6 tanpa transaksi, tagihan, saldo, atau klaim idempotensi baru');
$assert((int) $satu("SELECT COUNT(*) FROM bsa_tabungan_akun WHERE santri_id IN ($sNonaktif)") === 0, 'Penolakan tidak menyiapkan akun tabungan santri nonaktif');
$ok = $svc->setoran($akun('sbx_v6_bsa_tsanawi'), $sTsanawi, ['idempotency_key' => $kunci('i6-unit'), 'nominal' => 1000, 'metode' => 'Tunai'], 'web');
$assert($ok['status'] === 201 && (int) $satu("SELECT penugasan_id FROM bsa_tabungan_transaksi WHERE id = {$ok['id']}") === (int) $satu("SELECT a.id FROM bendahara_bulanan_assignments a JOIN users u ON u.pengurus_id = a.pengurus_id WHERE u.username = 'sbx_v6_bsa_tsanawi'"),
    'Petugas unit Tsanawi mencatat untuk santri Tsanawi; penugasan unit tercatat di ledger');
$ok = $svc->setoran($akun('sbx_v6_bsa_lama'), $sSmp, ['idempotency_key' => $kunci('i6-lama'), 'nominal' => 1000, 'metode' => 'Tunai'], 'web');
$svc->penarikan($akun('sbx_v6_bsa_lama'), $sSmp, ['idempotency_key' => $kunci('i6-lama2'), 'nominal' => 1000, 'metode' => 'Tunai', 'tujuan' => 'Kembalikan uji'], 'web');
$assert($ok['status'] === 201 && $saldo($sSmp) === 70000, 'Petugas berpenugasan tahun ajaran non-aktif tetap dapat mencatat (setoran lalu penarikan Rp1.000)');

// ===================================================================== I-7
echo PHP_EOL . '=== I-7. Isolasi baca ===' . PHP_EOL;
$assert($tolak(static fn () => $svc->santri($akun('sbx_v6_wali_tsanawi'), $sSmp)) === 404 && $tolak(static fn () => $svc->saldo($akun('sbx_v6_wali_tsanawi'), $sSmp)) === 404,
    'Wali anak lain membaca santri SMP → 404');
$assert($tolak(static fn () => $svc->tagihan($akun('sbx_v6_wali_tsanawi'), $t['id'])) === 404, 'Wali anak lain membuka ID tagihan → 404');
$assert($tolak(static fn () => $svc->tagihan($akun('sbx_v6_bsa_tsanawi'), $t['id'])) === 404, 'Petugas unit lain membuka ID tagihan → 404');
$assert($tolak(static fn () => $svc->tagihan($petugas, 99999999)) === 404, 'ID tagihan tidak ada → 404');
$assert($tolak(static fn () => $svc->santri($akun('sbx_ortu_a'), $sSmp)) === 404, 'Wali tanpa relasi santri V6 → 404');
foreach (['sbx_pengurus_biasa', 'sbx_guru_biasa', 'sbx_v5_petugas_b'] as $u) {
    $assert($tolak(static fn () => $svc->daftarSantri($akun($u), [])) === 403 && $tolak(static fn () => $svc->santri($akun($u), $sSmp)) === 403, "$u: daftar/rincian → 403");
}
$dw = $svc->daftarSantri($akun('sbx_v6_wali_smp'), []);
$assert($dw['total'] === 1 && $dw['rows'][0]['id'] === $sSmp && $dw['rows'][0]['saldo'] === 70000, 'Daftar wali SMP hanya memuat anaknya (saldo Rp70.000)');
$dt = $svc->daftarSantri($akun('sbx_v6_bsa_tsanawi'), ['q' => 'SBX V6']);
$assert(in_array($sTsanawi, array_column($dt['rows'], 'id'), true) && !in_array($sSmp, array_column($dt['rows'], 'id'), true) && !in_array($sTanpa, array_column($dt['rows'], 'id'), true),
    'Daftar petugas unit Tsanawi memuat santri Tsanawi saja (tanpa SMP dan tanpa kelas)');
$da = $svc->daftarSantri($akun('sbx_admin'), ['q' => 'SBX V6']);
$assert(count(array_intersect([$sSmp, $sTsanawi, $sTanpa], array_column($da['rows'], 'id'))) === 3, 'Daftar admin memuat seluruh santri V6 aktif');
$rw = $svc->santri($akun('sbx_v6_wali_smp'), $sSmp);
$assert(!array_key_exists('referensi', $rw['tabungan']['rows'][0]) && !array_key_exists('catatan', $rw['tabungan']['rows'][0]) && $rw['tabungan']['rows'][0]['tujuan'] !== null,
    'Riwayat bagi wali tanpa referensi dan catatan internal, dengan tujuan');
$rp = $svc->santri($petugas, $sTanpa);
$assert(($rp['tabungan']['rows'][0]['referensi'] ?? null) === 'TRF-UJI-1', 'Petugas melihat referensi transfer');

// ===================================================================== I-8
echo PHP_EOL . '=== I-8. Pencabutan pada sesi yang masih dipakai ===' . PHP_EOL;
$tugasB = (int) $satu("SELECT a.id FROM bendahara_bulanan_assignments a JOIN users u ON u.pengurus_id = a.pengurus_id WHERE u.username = 'sbx_v6_bsa_b'");
$userB = $akun('sbx_v6_bsa_b');
$assert($svc->akses($userB)['kelola'], 'Sebelum pencabutan: sbx_v6_bsa_b kelola');
penugasan_service()->nonaktifkan(PenugasanJenis::BENDAHARA_BULANAN, $tugasB, 'Uji cabut V6', $uid('sbx_admin'));
$sebelum = $jumlah();
$assert($tolak(static fn () => $svc->setoran($userB, $sTsanawi, ['idempotency_key' => $kunci('i8'), 'nominal' => 1000, 'metode' => 'Tunai'], 'web')) === 403
    && $tolak(static fn () => $svc->santri($userB, $sTsanawi)) === 403 && $jumlah() === $sebelum, 'Penugasan dinonaktifkan → mutasi dan baca 403 tanpa baris baru');
$tugasL = (int) $satu("SELECT a.id FROM bendahara_bulanan_assignments a JOIN users u ON u.pengurus_id = a.pengurus_id WHERE u.username = 'sbx_v6_bsa_lama'");
penugasan_service()->akhiri(PenugasanJenis::BENDAHARA_BULANAN, $tugasL, date('Y-m-d', strtotime('-1 day')), 'Uji akhiri kemarin', $uid('sbx_admin'));
$assert($tolak(static fn () => $svc->penarikan($akun('sbx_v6_bsa_lama'), $sSmp, ['idempotency_key' => $kunci('i8'), 'nominal' => 1000, 'metode' => 'Tunai', 'tujuan' => 'Uji'], 'web')) === 403
    && $jumlah() === $sebelum, 'Penugasan diakhiri kemarin → 403 tanpa baris baru');
$uT = $uid('sbx_v6_bsa_tsanawi');
$jalankan("UPDATE users SET is_active = 0 WHERE id = $uT");
$assert($tolak(static fn () => $svc->setoran($akun('sbx_v6_bsa_tsanawi'), $sTsanawi, ['idempotency_key' => $kunci('i8'), 'nominal' => 1000, 'metode' => 'Tunai'], 'web')) === 403
    && $jumlah() === $sebelum, 'Akun petugas dinonaktifkan → 403 tanpa baris baru');
$jalankan("UPDATE users SET is_active = 1 WHERE id = $uT");
$relasi = (int) $satu("SELECT sw.id FROM santri_wali sw JOIN users u ON u.wali_id = sw.wali_id WHERE u.username = 'sbx_v6_wali_tsanawi'");
$jalankan("UPDATE santri_wali SET archived_at = NOW() WHERE id = $relasi");
$assert($tolak(static fn () => $svc->santri($akun('sbx_v6_wali_tsanawi'), $sTsanawi)) === 404 && $svc->daftarSantri($akun('sbx_v6_wali_tsanawi'), [])['total'] === 0,
    'Relasi wali diarsipkan → rincian 404 dan daftar kosong');
$jalankan("UPDATE santri_wali SET archived_at = NULL WHERE id = $relasi");
$assert($svc->saldo($akun('sbx_v6_wali_tsanawi'), $sTsanawi)['saldo'] === $saldo($sTsanawi), 'Relasi dipulihkan → wali membaca lagi (diperiksa ulang setiap permintaan)');
$assert((int) $satu('SELECT COUNT(*) FROM bsa_idempotensi WHERE hasil_id IS NULL') === 0, 'Penolakan pencabutan tidak meninggalkan klaim idempotensi');

// ===================================================================== I-9
echo PHP_EOL . '=== I-9. Validasi nominal eksak dan masukan ===' . PHP_EOL;
$sebelum = $jumlah();
$coba = static fn (array $isi, bool $keluar = false) => $tolak(static fn () => $keluar
    ? $svc->penarikan($petugas, $sSmp, $isi + ['idempotency_key' => $kunci('i9'), 'metode' => 'Tunai', 'tujuan' => 'Uji validasi'], 'web')
    : $svc->setoran($petugas, $sSmp, $isi + ['idempotency_key' => $kunci('i9'), 'metode' => 'Tunai'], 'web'));
foreach (['0' => '0', 'negatif' => '-1000', 'pecahan koma' => '1000,50', 'pecahan titik' => '1000.5', 'notasi ilmiah' => '1e5', 'float' => 1000.0, 'melebihi batas' => '1000000001',
    'kosong' => '', 'teks' => 'seratus', 'array' => ['1000'], 'titik ribuan salah' => '10.00', 'spasi tengah' => '10 000', 'bool' => true] as $label => $nilai) {
    $assert($coba(['nominal' => $nilai]) === 422, "Nominal $label ditolak 422");
}
$assert($coba(['nominal' => 1000, 'metode' => 'Cek']) === 422, 'Metode selain Tunai/Transfer ditolak');
$assert($tolak(static fn () => $svc->setoran($petugas, $sSmp, ['idempotency_key' => $kunci('i9'), 'nominal' => 1000, 'metode' => 'Transfer'], 'web')) === 422, 'Transfer tanpa referensi ditolak');
$assert($tolak(static fn () => $svc->penarikan($petugas, $sSmp, ['idempotency_key' => $kunci('i9'), 'nominal' => 1000, 'metode' => 'Tunai', 'tujuan' => 'ab'], 'web')) === 422, 'Penarikan tanpa tujuan memadai ditolak');
$assert($tolak(static fn () => $svc->setoran($petugas, $sSmp, ['idempotency_key' => 'pendek', 'nominal' => 1000, 'metode' => 'Tunai'], 'web')) === 422, 'Kunci idempotensi tidak aman ditolak');
foreach (['2026-13', '2026-1', '26-10', '2026-10-01', ''] as $p) {
    $assert($tolak(static fn () => $svc->terbitkanTagihan($petugas, ['idempotency_key' => $kunci('i9'), 'santri_id' => $sSmp, 'periode' => $p, 'jatuh_tempo' => '2026-12-10', 'komponen' => [['nama' => 'X', 'nominal' => 1]]], 'web')) === 422,
        "Periode '$p' ditolak");
}
$assert($tolak(static fn () => $svc->terbitkanTagihan($petugas, ['idempotency_key' => $kunci('i9'), 'santri_id' => $sSmp, 'periode' => '2027-01', 'jatuh_tempo' => '2026-12-31', 'komponen' => [['nama' => 'X', 'nominal' => 1]]], 'web')) === 422,
    'Jatuh tempo sebelum awal periode ditolak');
$assert($tolak(static fn () => $svc->terbitkanTagihan($petugas, ['idempotency_key' => $kunci('i9'), 'santri_id' => $sSmp, 'periode' => '2027-01', 'jatuh_tempo' => '2027-01-10', 'komponen' => [['nama' => '', 'nominal' => '']]], 'web')) === 422,
    'Tagihan tanpa komponen ditolak');
$assert($tolak(static fn () => $svc->terbitkanTagihan($petugas, ['idempotency_key' => $kunci('i9'), 'santri_id' => $sSmp, 'periode' => '2027-01', 'jatuh_tempo' => '2027-01-10',
    'komponen' => [['nama' => 'A', 'nominal' => 600000000], ['nama' => 'B', 'nominal' => 600000000]]], 'web')) === 422, 'Total tagihan melebihi batas ditolak');
$assert($jumlah() === $sebelum, 'Seluruh validasi I-9 tanpa baris atau klaim idempotensi baru');

// ===================================================================== I-10
echo PHP_EOL . '=== I-10. Penjaga basis data dan audit ===' . PHP_EOL;
$db->begin_transaction();
$cekSaldo = $db->query("UPDATE bsa_tabungan_akun SET saldo = -1 WHERE santri_id = $sSmp");
$cekNominal = $db->query("UPDATE bsa_tabungan_transaksi SET nominal = 0 WHERE santri_id = $sSmp LIMIT 1");
$cekTerbayar = $db->query("UPDATE bsa_tagihan SET terbayar = total + 1 WHERE id = {$t['id']}");
$db->rollback();
$assert($cekSaldo === false && $cekNominal === false && $cekTerbayar === false && $saldo($sSmp) === 70000, 'CHECK basis data menolak saldo negatif, nominal nol, dan terbayar melebihi total');
$assert((int) $satu("SELECT COUNT(*) FROM audit_logs WHERE action IN ('bsa.tabungan.setoran', 'bsa.tabungan.penarikan')") === (int) $satu('SELECT COUNT(*) FROM bsa_tabungan_transaksi')
    && (int) $satu("SELECT COUNT(*) FROM audit_logs WHERE action = 'bsa.tagihan.terbit'") === (int) $satu('SELECT COUNT(*) FROM bsa_tagihan'), 'Setiap transaksi dan tagihan punya satu jejak audit');
$assert((int) $satu("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'bsa.%' AND (after_json REGEXP '\"(nominal|saldo|saldo_sesudah|referensi|tujuan|total)\"' OR after_json LIKE '%TRF-UJI%')") === 0,
    'Audit bsa.* tanpa nominal, saldo, referensi, atau tujuan');
$out = [];
exec('DB_NAME=' . escapeshellarg($dbName) . ' php ' . escapeshellarg(dirname(__DIR__) . '/bin/v6_phase1_verify.php') . ' 2>&1', $out, $kodeVerify);
$assert($kodeVerify === 0, 'Verifier invarians V6 lulus pada data uji (' . count(array_filter($out, static fn ($l) => str_starts_with($l, '[lulus]'))) . ' pemeriksaan)');

// ===================================================================== I-11
echo PHP_EOL . '=== I-11. Preservasi tabel lain ===' . PHP_EOL;
$akhir = $preservasi();
foreach ($lindungi as $tb) {
    if ($awal[$tb] === null) {
        continue;
    }
    $harapan = $awal[$tb] + ($tb === 'bendahara_bulanan_assignments' ? 1 : 0);
    $assert($akhir[$tb] === $harapan, "Jumlah baris $tb " . ($harapan === $awal[$tb] ? "tetap {$awal[$tb]}" : "{$awal[$tb]} + 1 penugasan BSA oleh admin di I-2"));
}

echo PHP_EOL . "Hasil: $lulus lulus, " . count($gagal) . ' gagal' . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
