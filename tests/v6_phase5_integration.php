<?php

declare(strict_types=1);

/**
 * Uji integrasi PRD V6 Fase 5 — laporan, audit, dan kesiapan rilis bersama —
 * langsung pada `LaporanService` dan MariaDB/MySQL uji.
 *
 *   bash tests/v6_phase5_db.sh
 *   V6_RUN_INTEGRATION=1 php tests/v6_phase5_integration.php
 *
 * Fixture sintetis dua bulan dibuat lewat layanan (bulan A = bulan lalu,
 * bulan B = bulan ini; transaksi bulan A digeser waktunya ke tanggal 15 bulan
 * A — hanya DB uji). Oracle angka ditulis tangan di bawah, bukan dihitung
 * ulang dari kode yang diuji.
 *
 *   I-1  hak laporan: petugas seluruh unit/unit, admin, admin+petugas, wali
 *        satu/dua anak, wali tanpa anak V6; pengurus/guru/petugas kesehatan
 *        403; santri di luar hak 404; unit di luar cakupan 404; periode tidak
 *        sah/masa depan/terlalu panjang 422;
 *   I-2  KRITERIA 1–2: laporan bulan B, A, dan A..B = oracle (tagihan terbit,
 *        terbayar per sumber, sisa, lewat jatuh tempo, setoran, penarikan,
 *        belanja, bayar dari tabungan, koreksi, pengembalian, saldo awal/
 *        akhir) dan per santri: saldo akhir = setoran − penarikan/belanja −
 *        bayar tagihan ± pembalik; selisih Rp0;
 *   I-3  KRITERIA 3: kas vs perpindahan internal — penerimaan kas baru tidak
 *        berubah oleh pembayaran dari tabungan; debit = pembayaran tabungan;
 *        laporan bulan ini = rekonsiliasi harian hari ini;
 *   I-4  KRITERIA 4: setiap koreksi/pembatalan pada laporan beraktor,
 *        berwaktu, beralasan, berasal; tanpa alasan bagi wali;
 *   I-5  laporan = kueri sumber terpisah; per bulan dan per santri = agregat;
 *   I-6  peran: petugas unit hanya unitnya, wali hanya anaknya (tanpa kas,
 *        internal, transfer menunggu, alasan); R-10 santri nonaktif bersaldo
 *        hanya pada laporan admin;
 *   I-7  ekspor CSV per peran/anak, tanpa referensi transfer/alasan bagi
 *        wali, wali tanpa ekspor koreksi, sel formula diberi awalan, audit
 *        ekspor tanpa nominal;
 *   I-8  pencabutan penugasan/relasi pada sesi lama → laporan dan ekspor
 *        tertutup seketika;
 *   I-9  konkurensi dan idempotensi: kunci sama dari 6 proses → satu
 *        setoran; dua belanja melebihi saldo → satu sukses; laporan yang dibaca
 *        bersamaan dengan mutasi tetap konsisten (satu snapshot) — kontrol
 *        tanpa snapshot dicatat;
 *   I-10 kontrol pengrusakan: saldo akun/terbayar/audit yang dirusak sengaja
 *        terdeteksi laporan dan verifier, lalu dipulihkan;
 *   I-11 verifier rilis `bin/v6_verify.php` dan verifier fase lulus;
 *        laporan tidak menulis data.
 *
 * Mengubah data uji; bangun ulang DB sebelum mengulang.
 */

if (getenv('V6_RUN_INTEGRATION') !== '1') {
    fwrite(STDERR, "Set V6_RUN_INTEGRATION=1 untuk menjalankan.\n");
    exit(2);
}
$dbName = getenv('V6F5_DB') ?: 'test_v6_f5_test';
if (!preg_match('/^test_.*_test$/', $dbName)) {
    fwrite(STDERR, "Ditolak: DB uji harus bernama test_*_test.\n");
    exit(2);
}
putenv('DB_NAME=' . $dbName);
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Bsa\BsaException;
use App\Bsa\LaporanService;

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

    return $r->fetch_row()[0] ?? null;
};
$jalankan = static function (string $sql) use ($db): void {
    if ($db->query($sql) === false) {
        throw new RuntimeException($db->error . ' :: ' . $sql);
    }
};
$uid = static fn (string $u): int => (int) $satu("SELECT id FROM users WHERE username='" . $db->real_escape_string($u) . "'");
$akun = static fn (string $u): array => ['id' => $uid($u), 'roles' => []];
$sid = static fn (string $nis): int => (int) $satu("SELECT id FROM santri WHERE nis='" . $db->real_escape_string($nis) . "'");
$tab = tabungan_service();
$tag = tagihan_service();
$bsa = bsa_service();
$lap = laporan_bsa_service();
$tolak = static function (callable $fn): ?int {
    try {
        $fn();
    } catch (BsaException $e) {
        return $e->status;
    }

    return null;
};
$k = static fn (string $awalan): string => $awalan . '-' . bin2hex(random_bytes(6));

$B = date('Y-m');
$A = (new DateTimeImmutable($B . '-01'))->modify('-1 month')->format('Y-m');
$akhirB = (new DateTimeImmutable($B . '-01'))->modify('+1 month -1 day')->format('Y-m-d');
$hariIni = date('Y-m-d');
echo "Bulan A = $A (digeser), bulan B = $B (hari ini $hariIni)\n";

$s1 = $sid('V6S0001');
$s2 = $sid('V6S0002');
$s3 = $sid('V6S0003');
$s4 = $sid('V6S0004');
$petugas = $akun('sbx_v6_bsa_b');
$petugasT = $akun('sbx_v6_bsa_tsanawi');
$admin = $akun('sbx_admin');
$adminBsa = $akun('sbx_v6_admin_bsa');
$waliSmp = $akun('sbx_v6_wali_smp');
$waliT = $akun('sbx_v6_wali_tsanawi');
$waliDua = $akun('sbx_v6_wali_dua');
$ortuA = $akun('sbx_ortu_a');
$assert(min($s1, $s2, $s3, $s4, $petugas['id'], $waliDua['id']) > 0 && (int) $satu('SELECT COUNT(*) FROM bsa_tabungan_transaksi') === 0, 'Fixture V6 Fase 1–4 tersedia; ledger kosong');
$assert($lap->tersedia(), 'LaporanService tersedia (027–029 terpasang)');

$setor = static fn (int $s, int $n, array $lain = []) => $tab->setoran($petugas, $s, $lain + ['idempotency_key' => $k('st'), 'nominal' => $n, 'metode' => 'Tunai'], 'web');
$keluar = static fn (int $s, int $n, string $jenis) => $tab->penarikan($petugas, $s, ['idempotency_key' => $k('kl'), 'jenis' => $jenis, 'nominal' => $n, 'metode' => 'Tunai', 'tujuan' => 'Uji ' . $jenis], 'web');
$tagihan = static fn (int $s, string $periode, int $n, string $jatuhTempo) => $bsa->terbitkanTagihan($petugas, ['idempotency_key' => $k('tg'), 'santri_id' => $s, 'periode' => $periode,
    'jatuh_tempo' => $jatuhTempo, 'komponen' => [['nama' => 'Syahriah uji', 'nominal' => $n]]], 'web')['id'];
$tunai = static fn (int $t, int $n) => $tag->catatPembayaran($petugas, $t, ['idempotency_key' => $k('bt'), 'nominal' => $n, 'sumber' => 'Tunai', 'tanggal_transaksi' => $hariIni], 'web');
$dariTab = static fn (int $t, int $n) => $tag->bayarDariTabungan($petugas, $t, ['idempotency_key' => $k('bb'), 'nominal' => $n, 'setuju' => '1'], 'web');
$batal = static fn (int $p, string $alasan) => $tag->batalkanPembayaran($petugas, $p, ['idempotency_key' => $k('bx'), 'alasan' => $alasan], 'web');

// ===================================================================== fixture
echo PHP_EOL . '=== Fixture bulan A (digeser) dan bulan B ===' . PHP_EOL;
// Bulan A — santri SMP: setoran 200.000, belanja 30.000, tagihan A 100.000 dibayar tunai 40.000 + tabungan 60.000.
$setor($s1, 200000);
$keluar($s1, 30000, 'Belanja');
$tA1 = $tagihan($s1, $A, 100000, $A . '-10');
$tunai($tA1, 40000);
$dariTab($tA1, 60000);
$waktuA = $A . '-15 10:00:00';
$jalankan("UPDATE bsa_tabungan_transaksi SET created_at = '$waktuA' WHERE santri_id = $s1");
$jalankan("UPDATE bsa_pembayaran SET created_at = '$waktuA', diverifikasi_pada = '$waktuA', tanggal_transaksi = '$A-15' WHERE santri_id = $s1");
$jalankan("UPDATE bsa_tagihan SET created_at = '$waktuA' WHERE santri_id = $s1");
$jalankan("UPDATE bsa_tagihan_riwayat SET created_at = '$waktuA' WHERE tagihan_id = $tA1");
// Tsanawi: tagihan A 80.000 belum dibayar (lewat jatuh tempo).
$tA2 = $tagihan($s2, $A, 80000, $A . '-10');
// Bulan B — SMP.
$st = $setor($s1, 50000, ['metode' => 'Transfer', 'referensi' => 'TRF-V6F5-SETORAN']);
$assert($st['status'] === 202 && $st['status_setoran'] === 'Menunggu', 'Setoran transfer B Rp50.000 menunggu (belum saldo)');
$tab->verifikasiSetoran($petugas, (int) $st['setoran_transfer_id'], ['idempotency_key' => $k('vf')], 'web');
$keluar($s1, 20000, 'Penarikan');
$tB1 = $tagihan($s1, $B, 120000, $akhirB);
$menunggu = $tag->catatPembayaran($petugas, $tB1, ['idempotency_key' => $k('bm'), 'nominal' => 30000, 'sumber' => 'Transfer', 'referensi' => 'TRF-V6F5-BAYAR', 'tanggal_transaksi' => $hariIni], 'web');
$assert($menunggu['status_pembayaran'] === 'Menunggu', 'Pembayaran transfer B Rp30.000 menunggu verifikasi');
$dariTab($tB1, 50000);
$pTunai = $tunai($tB1, 20000);
$batal((int) $pTunai['id'], 'Uji V6F5 alasan batal tunai');
$pTab = $dariTab($tB1, 10000);
$batal((int) $pTab['id'], 'Uji V6F5 alasan batal tabungan');
$bl = $keluar($s1, 15000, 'Belanja');
$tab->koreksi($petugas, (int) $bl['id'], ['idempotency_key' => $k('kr'), 'alasan' => 'Uji V6F5 alasan koreksi belanja'], 'web');
$sk = $setor($s1, 5000);
$tab->koreksi($petugas, (int) $sk['id'], ['idempotency_key' => $k('kr'), 'alasan' => 'Uji V6F5 alasan koreksi setoran'], 'web');
// Bulan B — Tsanawi.
$setor($s2, 70000);
$tB2 = $tagihan($s2, $B, 90000, $akhirB);
$tunai($tB2, 90000);
$stT = $setor($s2, 25000, ['metode' => 'Transfer', 'referensi' => 'TRF-V6F5-DITOLAK']);
$tab->tolakSetoran($petugas, (int) $stT['setoran_transfer_id'], ['idempotency_key' => $k('tl'), 'alasan' => 'Uji V6F5 alasan tolak setoran'], 'web');
// Bulan B — tanpa kelas: tagihan 50.000, revisi potongan 5.000, lalu dibatalkan.
$tB3 = $tagihan($s3, $B, 50000, $akhirB);
$tag->revisiTagihan($petugas, $tB3, ['idempotency_key' => $k('rv'), 'versi' => 1, 'jenis' => 'Potongan', 'nominal' => 5000, 'alasan' => 'Uji V6F5 alasan revisi'], 'web');
$tag->batalkanTagihan($petugas, $tB3, ['idempotency_key' => $k('bl'), 'versi' => 2, 'alasan' => 'Uji V6F5 alasan batal tagihan'], 'web');
// Bulan B — santri yang kemudian keluar (R-10): setoran 12.000 selagi aktif, lalu dinonaktifkan.
$jalankan("UPDATE santri SET is_active = 1 WHERE id = $s4");
$setor($s4, 12000);
$jalankan("UPDATE santri SET is_active = 0 WHERE id = $s4");
$saldo = static fn (int $s): int => (int) $satu("SELECT COALESCE((SELECT saldo FROM bsa_tabungan_akun WHERE santri_id = $s), 0)");
$assert($saldo($s1) === 90000 && $saldo($s2) === 70000 && $saldo($s4) === 12000 && $saldo($s3) === 0, 'Saldo fixture: SMP Rp90.000, Tsanawi Rp70.000, santri keluar Rp12.000');

// ===================================================================== I-1
echo PHP_EOL . '=== I-1. Hak laporan ===' . PHP_EOL;
foreach (['sbx_v6_bsa_b' => 'petugas', 'sbx_v6_bsa_tsanawi' => 'petugas', 'sbx_admin' => 'admin', 'sbx_v6_admin_bsa' => 'admin_petugas', 'sbx_v6_wali_dua' => 'wali', 'sbx_ortu_a' => 'wali'] as $u => $sumber) {
    $r = $lap->laporan($akun($u), []);
    $internal = $sumber !== 'wali';
    $assert($r['akses']['sumber'] === $sumber && ($r['kas'] !== null) === $internal && ($r['internal'] !== null) === $internal && ($r['koreksi'] !== null) === $internal
        && ($r['selisih'] !== null) === $internal && ($r['nonaktif'] !== null) === in_array($sumber, ['admin', 'admin_petugas'], true),
        "$u ($sumber): laporan terbaca; bagian kas/internal/koreksi/selisih " . ($internal ? 'ada' : 'tidak ada') . '; daftar R-10 ' . (in_array($sumber, ['admin', 'admin_petugas'], true) ? 'ada' : 'tidak ada'));
}
foreach (['sbx_pengurus_biasa', 'sbx_guru_biasa', 'sbx_v5_petugas_b'] as $u) {
    $assert($tolak(static fn () => $lap->laporan($akun($u), [])) === 403 && $tolak(static fn () => $lap->ekspor($akun($u), ['jenis' => 'santri'], 'web')) === 403, "$u: laporan dan ekspor → 403");
}
$assert($tolak(static fn () => $lap->laporan($petugasT, ['santri' => $s1])) === 404 && $tolak(static fn () => $lap->laporan($waliSmp, ['santri' => $s2])) === 404
    && $tolak(static fn () => $lap->laporan($ortuA, ['santri' => $s1])) === 404 && $tolak(static fn () => $lap->laporan($petugas, ['santri' => $s4])) === 404
    && $tolak(static fn () => $lap->laporan($petugas, ['santri' => 999999])) === 404, 'Santri di luar hak/tidak ada/nonaktif bagi petugas → 404');
$assert($tolak(static fn () => $lap->laporan($petugasT, ['unit' => 'SMP'])) === 404 && $tolak(static fn () => $lap->laporan($petugas, ['unit' => 'Tidak Ada'])) === 422,
    'Unit di luar cakupan → 404; unit tidak dikenal → 422');
$depan = (new DateTimeImmutable($B . '-01'))->modify('+1 month')->format('Y-m');
$jauh = (new DateTimeImmutable($B . '-01'))->modify('-12 month')->format('Y-m');
foreach ([['dari' => '2026-13'], ['sampai' => 'x'], ['dari' => $depan, 'sampai' => $depan], ['dari' => $B, 'sampai' => $A], ['dari' => $jauh, 'sampai' => $B]] as $salah) {
    $assert($tolak(static fn () => $lap->laporan($admin, $salah)) === 422, 'Periode tidak sah ' . json_encode($salah) . ' → 422');
}
$assert($tolak(static fn () => $lap->laporan($admin, ['dari' => (new DateTimeImmutable($B . '-01'))->modify('-11 month')->format('Y-m'), 'sampai' => $B])) === null, 'Rentang tepat 12 bulan diterima');

// ===================================================================== I-2
echo PHP_EOL . '=== I-2. Laporan = oracle (kriteria 1–2) ===' . PHP_EOL;
$rB = $lap->laporan($admin, ['dari' => $B, 'sampai' => $B]);
$t = $rB['tagihan'];
$assert($t['terbit'] === ['jumlah' => 2, 'nominal' => 210000] && $t['terbayar'] === 140000 && $t['sisa'] === 70000 && $t['lunas'] === 1 && $t['belum_lunas'] === 1
    && $t['dibatalkan'] === ['jumlah' => 1, 'nominal' => 45000] && $t['lewat_jatuh_tempo'] === ['jumlah' => 0, 'sisa' => 0],
    'Tagihan B: terbit 2 = Rp210.000, terbayar Rp140.000, sisa Rp70.000, 1 dibatalkan Rp45.000 (sesudah revisi)');
$assert($t['pembayaran_sah'] === ['Tunai' => 90000, 'Transfer' => 0, 'Tabungan' => 50000] && $t['pembayaran_dibatalkan'] === ['Tunai' => 20000, 'Transfer' => 0, 'Tabungan' => 10000]
    && $t['menunggu_verifikasi'] === ['jumlah' => 1, 'nominal' => 30000], 'Pembayaran B: sah tunai 90.000 + tabungan 50.000; dibatalkan tunai 20.000 + tabungan 10.000; transfer menunggu 30.000 tidak dihitung');
$tb = $rB['tabungan'];
$assert($tb['saldo_awal'] === 110000 && $tb['setoran'] === ['Tunai' => 87000, 'Transfer' => 50000] && $tb['penarikan'] === ['Tunai' => 20000, 'Transfer' => 0]
    && $tb['belanja'] === ['Tunai' => 15000, 'Transfer' => 0] && $tb['pembayaran_tagihan'] === 60000 && $tb['koreksi_masuk'] === ['Tunai' => 15000, 'Transfer' => 0]
    && $tb['koreksi_keluar'] === ['Tunai' => 5000, 'Transfer' => 0] && $tb['pengembalian'] === 10000 && $tb['mutasi_bersih'] === 62000 && $tb['saldo_akhir'] === 172000,
    'Tabungan B: awal 110.000 + setoran 137.000 − penarikan 20.000 − belanja 15.000 − bayar tagihan 60.000 + koreksi 15.000 − 5.000 + pengembalian 10.000 = akhir 172.000');
$rA = $lap->laporan($admin, ['dari' => $A, 'sampai' => $A]);
$assert($rA['tagihan']['terbit'] === ['jumlah' => 2, 'nominal' => 180000] && $rA['tagihan']['terbayar'] === 100000 && $rA['tagihan']['lewat_jatuh_tempo'] === ['jumlah' => 1, 'sisa' => 80000]
    && $rA['tabungan']['saldo_awal'] === 0 && $rA['tabungan']['saldo_akhir'] === 110000 && $rA['tabungan']['pembayaran_tagihan'] === 60000 && $rA['penerimaan_kas_baru'] === 240000,
    'Laporan A: tagihan Rp180.000 (lewat jatuh tempo 1 = Rp80.000), saldo 0 → 110.000, kas baru Rp240.000 (setoran 200.000 + tunai 40.000)');
$rAB = $lap->laporan($admin, ['dari' => $A, 'sampai' => $B]);
$assert($rAB['tagihan']['terbit'] === ['jumlah' => 4, 'nominal' => 390000] && $rAB['tagihan']['terbayar'] === 240000 && $rAB['tagihan']['sisa'] === 150000
    && $rAB['tagihan']['pembayaran_sah'] === ['Tunai' => 130000, 'Transfer' => 0, 'Tabungan' => 110000]
    && $rAB['tabungan']['saldo_awal'] === 0 && $rAB['tabungan']['saldo_akhir'] === 172000 && $rAB['tabungan']['setoran'] === ['Tunai' => 287000, 'Transfer' => 50000]
    && $rAB['penerimaan_kas_baru'] === 487000, 'Laporan A..B: tagihan 4 = Rp390.000, terbayar 240.000 (tunai 130.000 + tabungan 110.000), saldo 0 → 172.000, kas baru Rp487.000');
$bulanAB = array_column($rAB['per_bulan'], null, 'periode');
$assert(array_keys($bulanAB) === [$A, $B] && $bulanAB[$A]['kas_baru'] === 240000 && $bulanAB[$B]['kas_baru'] === 247000 && $bulanAB[$A]['bayar_tabungan'] === 60000
    && $bulanAB[$B]['bayar_tabungan'] === 60000 && $bulanAB[$B]['pembatalan'] === 30000 && $bulanAB[$A]['tagihan_terbit'] === 180000 && $bulanAB[$B]['sisa'] === 70000,
    'Per bulan A..B: kas baru 240.000 + 247.000; bayar dari tabungan 60.000 per bulan; pembatalan B 30.000');
$perSantri = array_column(array_map(static fn (array $r): array => $r + ['sid' => $r['santri']['id']], $rB['per_santri']['rows']), null, 'sid');
$r1 = $perSantri[$s1] ?? [];
$assert(count($perSantri) === 4 && isset($perSantri[$s3], $perSantri[$s4]) && $r1['tagihan'] === 120000 && $r1['terbayar'] === 50000 && $r1['sisa'] === 70000 && $r1['bayar_kas'] === 20000
    && $r1['batal_bayar'] === 30000 && $r1['saldo_awal'] === 110000 && $r1['setoran'] === 55000 && $r1['penarikan'] === 20000 && $r1['belanja'] === 15000
    && $r1['bayar_tabungan'] === 60000 && $r1['koreksi_masuk'] === 15000 && $r1['koreksi_keluar'] === 5000 && $r1['pengembalian'] === 10000 && $r1['saldo_akhir'] === 90000
    && $r1['saldo_akun'] === 90000 && $r1['selisih_saldo'] === 0 && $r1['selisih_akun'] === 0,
    'Per santri SMP B: 110.000 + 55.000 − 20.000 − 15.000 − 60.000 + 15.000 − 5.000 + 10.000 = 90.000 = saldo akun; selisih Rp0');
foreach ([$rA, $rB, $rAB] as $i => $r) {
    $assert($r['selisih']['nol'] === true && $r['per_santri']['jumlah']['santri_selisih_saldo'] === 0 && $r['per_santri']['jumlah']['santri_selisih_akun'] === 0,
        'Selisih rekonsiliasi laporan ' . ['A', 'B', 'A..B'][$i] . ' = Rp0 (tagihan, saldo, internal, pengembalian, per santri, akun)');
}

// ===================================================================== I-3
echo PHP_EOL . '=== I-3. Kas vs perpindahan internal (kriteria 3) ===' . PHP_EOL;
$kas = $rB['kas'];
$assert($kas['Tunai'] === ['setoran' => 87000, 'pembayaran_tagihan' => 110000, 'koreksi_masuk' => 15000, 'masuk' => 212000, 'penarikan_belanja' => 35000, 'koreksi_keluar' => 25000,
    'keluar' => 60000, 'bersih' => 152000] && $kas['Transfer']['masuk'] === 50000 && $kas['Transfer']['keluar'] === 0 && $rB['penerimaan_kas_baru'] === 247000,
    'Kas B: tunai masuk 212.000 (setoran 87.000 + tagihan 110.000 + koreksi 15.000) keluar 60.000; transfer 50.000; kas baru 247.000');
$assert($rB['internal'] === ['debit_tabungan' => 60000, 'pembayaran_dari_tabungan' => 60000, 'pengembalian_ke_tabungan' => 10000, 'pembatalan_pembayaran_tabungan' => 10000,
    'selisih' => 0, 'selisih_pengembalian' => 0], 'Internal B: debit tabungan 60.000 = pembayaran dari tabungan 60.000; pengembalian 10.000 = pembatalan 10.000');
$rek = $tab->rekonsiliasi($admin, ['tanggal' => $hariIni]);
$assert($rek['kas'] === $kas && $rek['penerimaan_kas_baru'] === $rB['penerimaan_kas_baru'] && $rek['internal']['debit_tabungan'] === $rB['internal']['debit_tabungan'],
    'Kas dan internal laporan bulan B = rekonsiliasi harian hari ini (seluruh transaksi B dicatat hari ini)');
$sebelum = $lap->laporan($admin, []);
$bayarInternal = $dariTab($tB1, 5000);
$sesudah = $lap->laporan($admin, []);
$assert($sesudah['penerimaan_kas_baru'] === $sebelum['penerimaan_kas_baru'] && $sesudah['internal']['pembayaran_dari_tabungan'] === $sebelum['internal']['pembayaran_dari_tabungan'] + 5000
    && $sesudah['tabungan']['saldo_akhir'] === $sebelum['tabungan']['saldo_akhir'] - 5000 && $sesudah['tagihan']['terbayar'] === $sebelum['tagihan']['terbayar'] + 5000,
    'Bayar tagihan Rp5.000 dari tabungan: kas baru tetap, pembayaran internal +5.000, saldo −5.000, terbayar +5.000');
$batal((int) $bayarInternal['id'], 'Uji V6F5 alasan kembalikan internal');

// ===================================================================== I-4
echo PHP_EOL . '=== I-4. Jejak koreksi dan pembatalan (kriteria 4) ===' . PHP_EOL;
$rB = $lap->laporan($admin, ['dari' => $B, 'sampai' => $B]);
$kor = $rB['koreksi']['rows'];
$jenis = array_count_values(array_column($kor, 'jenis'));
$assert(($jenis['Koreksi tabungan'] ?? 0) === 2 && ($jenis['Pembatalan pembayaran'] ?? 0) === 3 && ($jenis['Penolakan setoran transfer'] ?? 0) === 1
    && ($jenis['Revisi tagihan'] ?? 0) === 1 && ($jenis['Pembatalan tagihan'] ?? 0) === 1, 'Daftar koreksi B: 2 koreksi tabungan, 3 pembatalan pembayaran, 1 setoran ditolak, 1 revisi, 1 pembatalan tagihan');
$lengkap = array_filter($kor, static fn (array $x): bool => $x['aktor'] !== null && $x['aktor'] !== '' && $x['waktu'] !== '' && mb_strlen((string) $x['alasan']) >= 5 && $x['rujukan']['label'] !== '');
$assert(count($lengkap) === count($kor) && count($kor) === 8, 'Seluruh 8 koreksi/pembatalan memiliki aktor, waktu, alasan, dan asal (nomor kuitansi/tagihan/setoran)');
$kuitansiAsal = array_column(array_filter($kor, static fn (array $x): bool => $x['jenis'] === 'Koreksi tabungan'), 'rujukan');
$assert(count(array_filter($kuitansiAsal, static fn (array $x): bool => preg_match('/^BSA-TB-[0-9]{4}-[0-9]{6,}$/', $x['label']) === 1)) === 2, 'Koreksi tabungan merujuk nomor kuitansi tabungan asal');
$pb = $rB['pembatalan'];
$assert($pb['koreksi_tabungan'] === ['masuk' => 15000, 'keluar' => 5000, 'jumlah' => 2] && $pb['tagihan_dibatalkan'] === ['jumlah' => 1, 'nominal' => 45000] && $pb['tagihan_direvisi'] === 1
    && $pb['setoran_transfer_ditolak'] === ['jumlah' => 1, 'nominal' => 25000] && $pb['penolakan_transfer_pembayaran'] === ['jumlah' => 0, 'nominal' => 0]
    && $pb['pembayaran'] === ['Tunai' => 20000, 'Transfer' => 0, 'Tabungan' => 15000], 'Ringkasan pembatalan B (termasuk pengembalian internal Rp5.000 dari I-3)');

// ===================================================================== I-5
echo PHP_EOL . '=== I-5. Laporan = sumber ===' . PHP_EOL;
$mulaiB = $B . '-01 00:00:00';
$sumber = [
    'setoran_tunai' => (int) $satu("SELECT COALESCE(SUM(nominal), 0) FROM bsa_tabungan_transaksi WHERE jenis = 'Setoran' AND metode = 'Tunai' AND created_at >= '$mulaiB'"),
    'debit' => (int) $satu("SELECT COALESCE(SUM(nominal), 0) FROM bsa_tabungan_transaksi WHERE jenis = 'PembayaranTagihan' AND created_at >= '$mulaiB'"),
    'saldo' => (int) $satu('SELECT COALESCE(SUM(saldo), 0) FROM bsa_tabungan_akun'),
    'terbayar' => (int) $satu("SELECT COALESCE(SUM(terbayar), 0) FROM bsa_tagihan WHERE status = 'Terbit' AND periode = '$B'"),
];
$assert($rB['tabungan']['setoran']['Tunai'] === $sumber['setoran_tunai'] && $rB['internal']['debit_tabungan'] === $sumber['debit'] && $rB['tabungan']['saldo_akhir'] === $sumber['saldo']
    && $rB['tagihan']['terbayar'] === $sumber['terbayar'], 'Angka laporan B = kueri sumber terpisah ' . json_encode($sumber));
$j = $rB['per_santri']['jumlah'];
$assert($j['saldo_akhir'] === $rB['tabungan']['saldo_akhir'] && $j['tagihan'] === $rB['tagihan']['terbit']['nominal'] && $j['terbayar'] === $rB['tagihan']['terbayar']
    && $j['setoran'] === array_sum($rB['tabungan']['setoran']) && $j['bayar_tabungan'] === $rB['tabungan']['pembayaran_tagihan'] && $j['santri'] === 4,
    'Jumlah per santri = agregat (saldo, tagihan, terbayar, setoran, bayar dari tabungan)');
$assert(array_sum(array_column($rAB['per_bulan'], 'setoran')) === array_sum($rAB['tabungan']['setoran'])
    && array_sum(array_column($rAB['per_bulan'], 'tagihan_terbit')) === $rAB['tagihan']['terbit']['nominal'], 'Jumlah per bulan = agregat rentang');
$q = $lap->laporan($admin, ['q' => 'Tsanawi']);
$assert($q['per_santri']['total'] === 1 && $q['per_santri']['jumlah']['saldo_akhir'] === 70000 && $q['tabungan']['saldo_akhir'] === $rB['tabungan']['saldo_akhir'],
    'Pencarian per santri menyaring tabel per santri saja (jumlah ikut pencarian; agregat tetap)');
$hal = $lap->laporan($admin, ['page' => 99]);
$assert($hal['per_santri']['page'] === 1 && $hal['per_santri']['total'] === 4, 'Halaman di luar jangkauan dijepit ke halaman terakhir');

// ===================================================================== I-6
echo PHP_EOL . '=== I-6. Peran: unit, wali, R-10 ===' . PHP_EOL;
$rT = $lap->laporan($petugasT, []);
$assert(array_column(array_column($rT['per_santri']['rows'], 'santri'), 'id') === [$s2] && $rT['tabungan']['saldo_akhir'] === 70000 && $rT['tagihan']['terbit']['nominal'] === 90000
    && $rT['penerimaan_kas_baru'] === 160000 && count($rT['koreksi']['rows']) === 1 && $rT['selisih']['nol'], 'Petugas Tsanawi: hanya santri Tsanawi (saldo 70.000, tagihan 90.000, kas baru 160.000, 1 koreksi)');
$rD = $lap->laporan($waliDua, []);
$ids = array_column(array_column($rD['per_santri']['rows'], 'santri'), 'id');
sort($ids);
$exp = [$s1, $s2];
sort($exp);
$assert($ids === $exp && $rD['tabungan']['saldo_akhir'] === 160000 && $rD['tagihan']['terbit']['nominal'] === 210000 && $rD['tagihan']['menunggu_verifikasi'] === null
    && $rD['tabungan']['setoran_menunggu'] === null && $rD['pembatalan']['setoran_transfer_ditolak'] === null && !isset($rD['per_santri']['rows'][0]['selisih_akun']),
    'Wali dua anak: dua anak saja (saldo 160.000, tagihan 210.000); tanpa transfer menunggu, penolakan, selisih internal');
$rS = $lap->laporan($waliSmp, []);
$assert(array_column(array_column($rS['per_santri']['rows'], 'santri'), 'id') === [$s1] && $rS['tabungan']['saldo_akhir'] === 90000 && $rS['tagihan']['terbayar'] === 50000,
    'Wali SMP: hanya anaknya (saldo 90.000, terbayar 50.000)');
$rO = $lap->laporan($ortuA, []);
$anakO = array_map('intval', array_column($db->query("SELECT DISTINCT sw.santri_id FROM santri_wali sw JOIN users u ON u.wali_id = sw.wali_id JOIN santri s ON s.id = sw.santri_id
    WHERE u.id = {$ortuA['id']} AND sw.archived_at IS NULL AND s.is_active = 1 AND s.archived_at IS NULL ORDER BY sw.santri_id")->fetch_all(MYSQLI_ASSOC), 'santri_id'));
$idsO = array_column(array_column($rO['per_santri']['rows'], 'santri'), 'id');
sort($idsO);
$assert($idsO === $anakO && array_intersect($idsO, [$s1, $s2, $s3, $s4]) === [] && $rO['tabungan']['saldo_akhir'] === 0 && $rO['tagihan']['terbit']['jumlah'] === 0,
    'Wali tanpa anak V6: hanya ' . count($anakO) . ' anaknya sendiri dengan angka Rp0, bukan data santri lain');
$json = json_encode([$rD, $rS, $rO]);
$assert(!str_contains($json, 'TRF-V6F5') && !str_contains($json, 'Uji V6F5 alasan') && !str_contains($json, 'SBX V6 Santri Tanpa'), 'Laporan wali tanpa referensi transfer, alasan, atau santri lain');
$nonaktif = $rB['nonaktif'];
$assert($nonaktif['jumlah'] === 1 && $nonaktif['saldo'] === 12000 && $nonaktif['rows'][0]['santri']['id'] === $s4, 'R-10: admin melihat 1 santri nonaktif bersaldo Rp12.000 (hanya dilaporkan)');
$assert($lap->laporan($adminBsa, [])['nonaktif']['jumlah'] === 1 && $lap->laporan($petugas, [])['nonaktif'] === null
    && !in_array($s4, array_column(array_column($lap->laporan($petugas, [])['per_santri']['rows'], 'santri'), 'id'), true),
    'R-10: admin+petugas melihat daftar; petugas tidak melihat santri nonaktif di laporan maupun daftar');

// ===================================================================== I-7
echo PHP_EOL . '=== I-7. Ekspor CSV per peran ===' . PHP_EOL;
$csv = static function (array $e): string {
    $h = fopen('php://memory', 'w+');
    LaporanService::tulisCsv($h, $e['kolom'], $e['baris']);
    rewind($h);

    return (string) stream_get_contents($h);
};
$auditAwal = (int) $satu("SELECT COUNT(*) FROM audit_logs WHERE action = 'bsa.laporan.ekspor'");
$eA = $lap->ekspor($admin, ['jenis' => 'santri'], 'web');
$assert(count($eA['baris']) === 4 && in_array('Selisih akun', $eA['kolom'], true) && str_starts_with($csv($eA), "\xEF\xBB\xBF"), 'Admin: CSV per santri 4 baris dengan kolom selisih; UTF-8 BOM');
$eD = $lap->ekspor($waliDua, ['jenis' => 'santri'], 'web');
$eDT = $lap->ekspor($waliDua, ['jenis' => 'transaksi', 'dari' => $A, 'sampai' => $B], 'web');
$teksD = $csv($eD) . $csv($eDT);
$nisD = array_unique(array_column($eDT['baris'], 2));
sort($nisD);
$assert(count($eD['baris']) === 2 && !in_array('Selisih akun', $eD['kolom'], true) && $nisD === ['V6S0001', 'V6S0002'] && !str_contains($teksD, 'TRF-V6F5') && !str_contains($teksD, 'Uji V6F5'),
    'Wali dua anak: CSV per santri 2 baris tanpa kolom selisih; rincian transaksi hanya dua anaknya, tanpa referensi transfer/alasan');
$assert($tolak(static fn () => $lap->ekspor($waliDua, ['jenis' => 'koreksi'], 'web')) === 403 && $tolak(static fn () => $lap->ekspor($admin, ['jenis' => 'rahasia'], 'web')) === 422
    && $tolak(static fn () => $lap->ekspor($waliSmp, ['jenis' => 'santri', 'santri' => $s2], 'web')) === 404, 'Wali: ekspor koreksi 403; jenis tidak dikenal 422; anak lain 404');
$eT = $lap->ekspor($petugasT, ['jenis' => 'transaksi'], 'web');
$assert(array_unique(array_column($eT['baris'], 2)) === ['V6S0002'], 'Petugas Tsanawi: rincian transaksi hanya santri Tsanawi');
$eK = $lap->ekspor($admin, ['jenis' => 'koreksi'], 'web');
$assert(count($eK['baris']) === 8 && count(array_filter($eK['baris'], static fn (array $b): bool => str_starts_with((string) $b[7], 'Uji V6F5') && $b[6] !== '' && $b[8] !== '')) === 8,
    'Admin: CSV koreksi 8 baris, masing-masing beraktor, beralasan, dan berasal');
$eTr = $lap->ekspor($admin, ['jenis' => 'transaksi'], 'web');
$kasBaru = array_sum(array_map(static fn (array $b): int => $b[9] === 'Ya' ? (int) $b[8] : 0, $eTr['baris']));
$internal = array_sum(array_map(static fn (array $b): int => $b[10] === 'Ya' && $b[4] === 'Pembayaran tagihan' ? (int) $b[8] : 0, $eTr['baris']));
$lapKini = $lap->laporan($admin, []);
$assert($kasBaru === $lapKini['penerimaan_kas_baru'] && $internal === $lapKini['internal']['pembayaran_dari_tabungan'],
    'CSV rincian transaksi: Σ "Kas baru" = penerimaan kas baru laporan (Rp' . $kasBaru . '); Σ pembayaran internal = laporan');
$namaAsli = (string) $satu("SELECT nama_santri FROM santri WHERE id = $s3");
$jalankan("UPDATE santri SET nama_santri = '=HYPERLINK(\"http://contoh\")' WHERE id = $s3");
$isi = $csv($lap->ekspor($admin, ['jenis' => 'santri'], 'web'));
$jalankan("UPDATE santri SET nama_santri = '" . $db->real_escape_string($namaAsli) . "' WHERE id = $s3");
$assert(str_contains($isi, "\"'=HYPERLINK(\"\"http://contoh\"\")\"") && !preg_match('/(^|,)=HYPERLINK/m', $isi), 'Sel teks berawalan = diberi kutip pembuka (tanpa formula aktif)');
$audit = $db->query("SELECT actor_user_id, after_json FROM audit_logs WHERE action = 'bsa.laporan.ekspor' ORDER BY id")->fetch_all(MYSQLI_ASSOC);
$assert(count($audit) - $auditAwal === 7 && array_filter($audit, static fn (array $a): bool => preg_match('/nominal|saldo|total|alasan|nama|TRF/', (string) $a['after_json']) === 1) === []
    && in_array($waliDua['id'], array_map('intval', array_column($audit, 'actor_user_id')), true), 'Setiap ekspor berhasil tercatat di audit (7) tanpa nominal/nama/alasan; ekspor ditolak tidak dicatat');

// ===================================================================== I-8
echo PHP_EOL . '=== I-8. Pencabutan pada sesi lama ===' . PHP_EOL;
$tugasT = (int) $satu("SELECT a.id FROM bendahara_bulanan_assignments a JOIN users u ON u.pengurus_id = a.pengurus_id WHERE u.id = {$petugasT['id']} AND a.is_active = 1 ORDER BY a.id LIMIT 1");
$jalankan("UPDATE bendahara_bulanan_assignments SET is_active = 0 WHERE id = $tugasT");
$assert($tolak(static fn () => $lap->laporan($petugasT, [])) === 403 && $tolak(static fn () => $lap->ekspor($petugasT, ['jenis' => 'santri'], 'web')) === 403, 'Penugasan dinonaktifkan: laporan dan ekspor petugas → 403 seketika');
$jalankan("UPDATE bendahara_bulanan_assignments SET is_active = 1 WHERE id = $tugasT");
$relasi = (int) $satu("SELECT sw.id FROM santri_wali sw JOIN users u ON u.wali_id = sw.wali_id WHERE u.id = {$waliDua['id']} AND sw.santri_id = $s2 AND sw.archived_at IS NULL");
$jalankan("UPDATE santri_wali SET archived_at = NOW() WHERE id = $relasi");
$rD2 = $lap->laporan($waliDua, []);
$assert(array_column(array_column($rD2['per_santri']['rows'], 'santri'), 'id') === [$s1] && $rD2['tabungan']['saldo_akhir'] === 90000
    && $tolak(static fn () => $lap->laporan($waliDua, ['santri' => $s2])) === 404 && array_unique(array_column($lap->ekspor($waliDua, ['jenis' => 'transaksi'], 'web')['baris'], 2)) === ['V6S0001'],
    'Relasi satu anak dicabut: laporan/ekspor wali dua anak tinggal anak lainnya; anak dicabut 404');
$jalankan("UPDATE santri_wali SET archived_at = NULL WHERE id = $relasi");

// ===================================================================== I-9
echo PHP_EOL . '=== I-9. Konkurensi, idempotensi, dan snapshot ===' . PHP_EOL;
$jalanPekerja = static function (array $perintah) use ($dbName): array {
    $aba = tempnam(sys_get_temp_dir(), 'v6f5aba');
    unlink($aba);
    $proses = [];
    foreach ($perintah as $p) {
        $h = proc_open(array_merge(['php', dirname(__DIR__) . '/tests/v6_phase5_worker.php', $aba], array_map('strval', $p)),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), array_merge(getenv(), ['DB_NAME' => $dbName]));
        $proses[] = [$h, $pipes];
    }
    usleep(300000);
    touch($aba);
    $hasil = [];
    foreach ($proses as [$h, $pipes]) {
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        proc_close($h);
        $hasil[] = json_decode(trim((string) $out), true) ?? ['ok' => false, 'galat' => trim($err . $out)];
    }
    @unlink($aba);

    return $hasil;
};
$kunciSama = $k('sama');
$sebelum = (int) $satu("SELECT COUNT(*) FROM bsa_tabungan_transaksi WHERE santri_id = $s3");
$h = $jalanPekerja(array_fill(0, 6, [$petugas['id'], 'setoran', $s3, $kunciSama, 40000]));
$assert(count(array_filter($h, static fn (array $x): bool => ($x['ok'] ?? false) === true)) === 6 && count(array_unique(array_column($h, 'id'))) === 1
    && (int) $satu("SELECT COUNT(*) FROM bsa_tabungan_transaksi WHERE santri_id = $s3") === $sebelum + 1 && $saldo($s3) === 40000,
    'Enam proses setoran kunci sama → satu kredit Rp40.000 (lima replay)');
$h = $jalanPekerja([[$petugas['id'], 'belanja', $s3, $k('b1'), 30000], [$petugas['id'], 'belanja', $s3, $k('b2'), 30000]]);
$assert(count(array_filter($h, static fn (array $x): bool => ($x['ok'] ?? false) === true)) === 1 && $saldo($s3) === 10000, 'Dua belanja Rp30.000 bersamaan atas saldo Rp40.000 → tepat satu sukses, saldo Rp10.000');
$h = $jalanPekerja([...array_fill(0, 3, [$petugas['id'], 'mutasi', $s1, $tB1, 4]), ...array_fill(0, 3, [$admin['id'], 'laporan', 15])]);
$mutasi = array_filter($h, static fn (array $x): bool => isset($x['berhasil']));
$baca = array_filter($h, static fn (array $x): bool => isset($x['baca']));
$tidak = array_merge(...array_values(array_map(static fn (array $x): array => $x['tidak_konsisten'], $baca)));
$assert(count($mutasi) === 3 && array_sum(array_column($mutasi, 'berhasil')) > 0 && array_sum(array_column($baca, 'baca')) === 45 && $tidak === [],
    'Tiga proses mutasi (' . array_sum(array_column($mutasi, 'berhasil')) . ' langkah) bersamaan dengan 45 pembacaan laporan: 0 laporan tidak konsisten (satu snapshot)');
$kontrol = $jalanPekerja([...array_fill(0, 3, [$petugas['id'], 'mutasi', $s1, $tB1, 4]), ...array_fill(0, 3, [$admin['id'], 'laporan', 15, 'tanpa-snapshot'])]);
$tk = array_sum(array_map(static fn (array $x): int => array_sum($x['tidak_konsisten'] ?? []), array_filter($kontrol, static fn (array $x): bool => isset($x['baca']))));
echo '[info] KONTROL kueri satu per satu tanpa snapshot pada beban yang sama: ' . $tk . ' dari 45 pembacaan tidak konsisten (bergantung waktu; tidak dinilai).' . PHP_EOL;
$akhirLap = $lap->laporan($admin, []);
$assert($akhirLap['selisih']['nol'] && $akhirLap['per_santri']['jumlah']['santri_selisih_akun'] === 0, 'Sesudah beban: laporan admin selisih Rp0');

// ===================================================================== I-10
echo PHP_EOL . '=== I-10. Kontrol pengrusakan terdeteksi ===' . PHP_EOL;
$verifikasi = static function () use ($dbName): array {
    $out = [];
    exec('DB_NAME=' . escapeshellarg($dbName) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/v6_verify.php') . ' 2>&1', $out, $kode);

    return [$kode, $out];
};
$jalankan("UPDATE bsa_tabungan_akun SET saldo = saldo + 1 WHERE santri_id = $s1");
$rusak = $lap->laporan($admin, []);
[$kodeRusak, $outRusak] = $verifikasi();
$jalankan("UPDATE bsa_tabungan_akun SET saldo = saldo - 1 WHERE santri_id = $s1");
$assert(!$rusak['selisih']['nol'] && $rusak['selisih']['santri_akun'] === 1 && $kodeRusak === 1
    && (bool) preg_grep('/\[gagal\] Saldo akun = setoran − penarikan − belanja − bayar tagihan/', $outRusak), 'Saldo akun +Rp1: laporan menandai 1 santri selisih; verifier gagal (exit 1)');
$jalankan("UPDATE bsa_tagihan SET terbayar = terbayar - 1 WHERE id = $tB1");
$rusak = $lap->laporan($admin, []);
[$kodeRusak, $outRusak] = $verifikasi();
$jalankan("UPDATE bsa_tagihan SET terbayar = terbayar + 1 WHERE id = $tB1");
$assert(!$rusak['selisih']['nol'] && $rusak['selisih']['tagihan_terbayar'] === -1 && $rusak['selisih']['tagihan_baris'] === 1 && $kodeRusak === 1
    && (bool) preg_grep('/\[gagal\] Setiap tagihan: total − Σ pembayaran sah = sisa/', $outRusak), 'Terbayar −Rp1: laporan selisih tagihan −Rp1; verifier gagal');
$auditKoreksi = $db->query("SELECT * FROM audit_logs WHERE action = 'bsa.tabungan.koreksi' ORDER BY id LIMIT 1")->fetch_assoc();
$jalankan('DELETE FROM audit_logs WHERE id = ' . (int) $auditKoreksi['id']);
[$kodeRusak, $outRusak] = $verifikasi();
$kolom = array_keys($auditKoreksi);
$jalankan('INSERT INTO audit_logs (`' . implode('`, `', $kolom) . '`) VALUES (' . implode(', ', array_map(static fn ($v): string => $v === null ? 'NULL' : "'" . $db->real_escape_string((string) $v) . "'", $auditKoreksi)) . ')');
$assert($kodeRusak === 1 && (bool) preg_grep('/\[gagal\] Setiap koreksi tabungan punya audit oleh pencatatnya/', $outRusak), 'Satu audit koreksi dihapus: verifier gagal "koreksi tanpa jejak" (audit dipulihkan)');

// ===================================================================== I-11
echo PHP_EOL . '=== I-11. Verifier rilis dan laporan hanya membaca ===' . PHP_EOL;
$hitung = static fn (): string => (string) $satu("SELECT CONCAT_WS(':', (SELECT COUNT(*) FROM bsa_tabungan_transaksi), (SELECT COUNT(*) FROM bsa_pembayaran), (SELECT COUNT(*) FROM bsa_tagihan),
    (SELECT COUNT(*) FROM bsa_idempotensi), (SELECT COALESCE(SUM(saldo), 0) FROM bsa_tabungan_akun), (SELECT COUNT(*) FROM audit_logs), (SELECT COUNT(*) FROM notifikasi_outbox))");
$awal = $hitung();
foreach ([$admin, $petugas, $waliDua] as $u) {
    $lap->laporan($u, ['dari' => $A, 'sampai' => $B]);
}
$assert($hitung() === $awal, 'Laporan tidak menulis apa pun (ledger, pembayaran, tagihan, idempotensi, saldo, audit, notifikasi tetap)');
[$kode, $out] = $verifikasi();
echo implode(PHP_EOL, array_filter($out, static fn (string $l): bool => str_contains($l, '[gagal]') || str_starts_with($l, 'Ringkasan') || str_starts_with($l, '[info]'))) . PHP_EOL;
$assert($kode === 0, 'bin/v6_verify.php lulus pada DB berfixture sesudah seluruh skenario');

echo PHP_EOL . "Ringkasan: {$lulus} lulus, " . count($gagal) . ' gagal' . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
