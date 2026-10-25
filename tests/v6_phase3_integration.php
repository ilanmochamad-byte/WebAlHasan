<?php

declare(strict_types=1);

/**
 * Uji integrasi layanan PRD V6 Fase 3 — tabungan lengkap dan pembayaran
 * silang — langsung pada `TabunganService`/`TagihanService` dan MariaDB/MySQL
 * uji.
 *
 *   bash tests/v6_phase3_db.sh
 *   V6_RUN_INTEGRATION=1 php tests/v6_phase3_integration.php
 *
 * Skenario (kode = kriteria penerimaan Fase 3, PRD V6 §6):
 *   L-1  matriks hak Fase 3: admin/admin+petugas/wali/pengurus lain tidak
 *        memutasi; petugas unit lain 404; bacaan internal 403 untuk wali;
 *   L-2  KRITERIA 1a: setoran Rp100.000 + belanja Rp25.000 → saldo Rp75.000;
 *        nomor kuitansi tabungan; belanja/penarikan melebihi saldo ditolak;
 *   L-3  setoran transfer: tanpa pernyataan diperiksa → menunggu (202), saldo
 *        tetap; replay; kunci sama beda isi → 409; verifikasi → ledger sah
 *        satu kali; verifikasi/tolak ulang → 409; tolak beralasan;
 *   L-4  KRITERIA 2: tagihan Rp100.000; bayar Rp50.000 dari saldo Rp75.000
 *        → saldo Rp25.000, sisa Rp50.000; satu referensi silang terbaca di
 *        riwayat tabungan dan rincian tagihan; persetujuan wajib; melebihi
 *        saldo/sisa ditolak; tagihan tidak memotong tabungan otomatis;
 *   L-5  KRITERIA 4: tindakan diulang/klik ganda dengan kunci sama → satu
 *        debit dan satu pembayaran; kunci sama isi beda → 409;
 *   L-6  KRITERIA 1b + konkurensi (proses terpisah): dua belanja Rp60.000
 *        bersamaan → tepat satu sukses; dua pembayaran dari tabungan
 *        bersamaan; pembayaran tabungan vs penarikan; pembayaran tabungan vs
 *        pembayaran tunai pada tagihan sama; delapan kiriman kunci sama;
 *        dua verifikasi; dua koreksi; dua pembatalan pembayaran tabungan;
 *   L-7  KRITERIA 3: kegagalan setelah debit disiapkan (trigger uji pada
 *        INSERT pembayaran/kuitansi/audit) → saldo dan sisa semula, nol
 *        transaksi efektif yatim; kegagalan pengembalian saat pembatalan;
 *   L-8  koreksi: pembalik beralasan; setoran yang sudah dipakai membayar
 *        tagihan tidak dapat dibalik (saldo tidak negatif); debit tagihan
 *        dikoreksi lewat pembatalan pembayaran (saldo + sisa kembali,
 *        referensi silang); pembalik tidak dibalik lagi; koreksi ganda 409;
 *   L-9  KRITERIA 5: rekonsiliasi — penerimaan kas tidak bertambah karena
 *        pembayaran dari tabungan; selisih internal/saldo Rp0; cakupan;
 *   L-10 otorisasi wali: rincian/kuitansi anak sendiri tanpa referensi,
 *        alasan, dan setoran menunggu; ID ditebak → 404; relasi dicabut;
 *   L-11 pencabutan penugasan, cakupan dipersempit, santri nonaktif: mutasi
 *        dan replay ditolak tanpa baris baru;
 *   L-12 verifier Fase 1–3 lulus; audit tanpa data uang; preservasi.
 *
 * Mengubah data uji; bangun ulang DB sebelum mengulang.
 */

if (getenv('V6_RUN_INTEGRATION') !== '1') {
    fwrite(STDERR, "Set V6_RUN_INTEGRATION=1 untuk menjalankan.\n");
    exit(2);
}
$dbName = getenv('V6F3_DB') ?: 'test_v6_f3_test';
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
$tab = tabungan_service();
$tag = tagihan_service();
$bsa = bsa_service();
$tolak = static function (callable $fn): ?int {
    try {
        $fn();
    } catch (BsaException $e) {
        return $e->status;
    }

    return null;
};
$pesanTolak = static function (callable $fn): string {
    try {
        $fn();
    } catch (BsaException $e) {
        return $e->getMessage();
    }

    return '';
};
$kunci = static fn (string $awalan): string => $awalan . '-' . bin2hex(random_bytes(6));
$tabel = ['bsa_tagihan', 'bsa_pembayaran', 'bsa_pembayaran_kuitansi', 'bsa_pembayaran_pembatalan', 'bsa_idempotensi', 'bsa_tabungan_transaksi', 'bsa_tabungan_akun', 'bsa_setoran_transfer'];
$jumlah = static function () use ($tabel, $satu): array {
    $h = [];
    foreach ($tabel as $t) {
        $h[$t] = (int) $satu("SELECT COUNT(*) FROM `$t`");
    }
    $h['audit_bsa'] = (int) $satu("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'bsa.%'");
    $h['terbayar'] = (int) $satu('SELECT COALESCE(SUM(terbayar), 0) FROM bsa_tagihan');
    $h['saldo'] = (int) $satu('SELECT COALESCE(SUM(saldo), 0) FROM bsa_tabungan_akun');
    $h['setoran_status'] = (string) $satu("SELECT COALESCE(GROUP_CONCAT(CONCAT(id, ':', status) ORDER BY id), '') FROM bsa_setoran_transfer");
    $h['bayar_status'] = (string) $satu("SELECT COALESCE(GROUP_CONCAT(CONCAT(id, ':', status) ORDER BY id), '') FROM bsa_pembayaran");

    return $h;
};
$saldo = static fn (int $santri): int => (int) $satu("SELECT COALESCE((SELECT saldo FROM bsa_tabungan_akun WHERE santri_id = $santri), 0)");
$tagihanRow = static fn (int $id): array => $db->query("SELECT id, santri_id, periode, total, terbayar, status, versi FROM bsa_tagihan WHERE id = $id")->fetch_assoc() ?? [];
$hariIni = date('Y-m-d');

$sSmp = $sid('V6S0001');
$sTsanawi = $sid('V6S0002');
$sTanpa = $sid('V6S0003');
$sNonaktif = $sid('V6S0004');
$assert(min($sSmp, $sTsanawi, $sTanpa, $sNonaktif) > 0, 'Fixture V6 tersedia (empat santri sintetis)');
$awal = $jumlah();
$assert($awal['bsa_tabungan_transaksi'] + $awal['bsa_pembayaran'] + $awal['bsa_setoran_transfer'] + $awal['bsa_tagihan'] === 0, 'Tabel BSA mulai kosong (migrasi 027, 028, 029 terpasang)');
$assert($tab->tersedia(), 'TabunganService tersedia (029 terpasang)');
$petugas = $akun('sbx_v6_bsa_b');
$petugasT = $akun('sbx_v6_bsa_tsanawi');
$petugasL = $akun('sbx_v6_bsa_lama');
$admin = $akun('sbx_admin');
$adminBsa = $akun('sbx_v6_admin_bsa');
$waliSmp = $akun('sbx_v6_wali_smp');
$waliT = $akun('sbx_v6_wali_tsanawi');

$lindungi = ['santri', 'wali', 'santri_wali', 'users', 'pengurus', 'kelas', 'plotting_kelas', 'psb_pendaftar', 'psb_pembayaran', 'izin_pengajuan', 'kesehatan_kunjungan',
    'kesehatan_ringkasan', 'v3_pelanggaran', 'v4_rapor', 'bendahara_bulanan_assignments', 'kesehatan_assignments', 'bsa_tarif', 'bsa_penyesuaian', 'bsa_penerbitan'];
$preservasi = static function () use ($lindungi, $satu): array {
    $hasil = [];
    foreach ($lindungi as $t) {
        $ada = (int) $satu("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$t'");
        $hasil[$t] = $ada === 1 ? (int) $satu("SELECT COUNT(*) FROM `$t`") : null;
    }

    return $hasil;
};
$awalLindungi = $preservasi();
$setor = static fn (array $u, int $s, int $n, array $lain = []) => $tab->setoran($u, $s, ['idempotency_key' => $kunci('st'), 'nominal' => $n, 'metode' => 'Tunai'] + $lain, 'web');
$keluar = static fn (array $u, int $s, int $n, string $jenis = 'Belanja') => $tab->penarikan($u, $s, ['idempotency_key' => $kunci('kl'), 'jenis' => $jenis, 'nominal' => $n, 'metode' => 'Tunai', 'tujuan' => 'Uji ' . $jenis], 'web');
$tagihanBaru = static fn (int $s, string $periode, int $n) => $bsa->terbitkanTagihan($petugas, ['idempotency_key' => $kunci('tg'), 'santri_id' => $s, 'periode' => $periode,
    'jatuh_tempo' => $periode . '-10', 'komponen' => [['nama' => 'Syahriah uji', 'nominal' => $n]]], 'web')['id'];
$bayarTab = static fn (array $u, int $t, int $n, ?string $k = null, array $lain = []) => $tag->bayarDariTabungan($u, $t, ['idempotency_key' => $k ?? $kunci('bt'), 'nominal' => $n, 'setuju' => '1'] + $lain, 'web');

// ===================================================================== L-1
echo PHP_EOL . '=== L-1. Matriks hak Fase 3 ===' . PHP_EOL;
foreach (['sbx_admin' => 'admin', 'sbx_v6_admin_bsa' => 'admin_petugas', 'sbx_v6_bsa_b' => 'petugas', 'sbx_v6_bsa_tsanawi' => 'petugas'] as $u => $sumber) {
    $assert($tolak(static fn () => $tab->daftarMutasi($akun($u), [])) === null && $tolak(static fn () => $tab->rekonsiliasi($akun($u), [])) === null
        && $tolak(static fn () => $tab->daftarSetoranTransfer($akun($u), [])) === null && $tab->daftarMutasi($akun($u), [])['akses']['sumber'] === $sumber,
        "$u ($sumber): membaca mutasi tabungan, setoran transfer, dan rekonsiliasi");
}
foreach (['sbx_v6_wali_smp', 'sbx_ortu_a'] as $u) {
    $assert($tolak(static fn () => $tab->daftarMutasi($akun($u), [])) === 403 && $tolak(static fn () => $tab->rekonsiliasi($akun($u), [])) === 403
        && $tolak(static fn () => $tab->daftarSetoranTransfer($akun($u), [])) === 403, "$u: mutasi lintas santri, setoran transfer, rekonsiliasi → 403 (hanya internal)");
}
foreach (['sbx_pengurus_biasa', 'sbx_guru_biasa', 'sbx_v5_petugas_b'] as $u) {
    $assert($tolak(static fn () => $tab->daftarMutasi($akun($u), [])) === 403 && $tolak(static fn () => $tab->rekonsiliasi($akun($u), [])) === 403, "$u: bacaan Fase 3 → 403");
}
$tagHak = $tagihanBaru($sSmp, '2026-09', 10000);
foreach (['sbx_admin' => 403, 'sbx_v6_admin_bsa' => 403, 'sbx_v6_wali_smp' => 403, 'sbx_pengurus_biasa' => 403, 'sbx_guru_biasa' => 403, 'sbx_v6_bsa_tsanawi' => 404] as $u => $kode) {
    $assert($tolak(static fn () => $setor($akun($u), $sSmp, 1000)) === $kode
        && $tolak(static fn () => $setor($akun($u), $sSmp, 1000, ['metode' => 'Transfer', 'referensi' => 'TRF-UJI-L1'])) === $kode
        && $tolak(static fn () => $keluar($akun($u), $sSmp, 1000)) === $kode
        && $tolak(static fn () => $bayarTab($akun($u), $tagHak, 1000)) === $kode,
        "$u: setoran tunai/transfer, belanja, bayar dari tabungan → $kode");
}
$sebelumL1 = $jumlah();
$assert($sebelumL1['bsa_tabungan_transaksi'] === 0 && $sebelumL1['bsa_setoran_transfer'] === 0 && $sebelumL1['bsa_pembayaran'] === 0, 'Penolakan L-1 tanpa baris uang atau setoran transfer');

// ===================================================================== L-2
echo PHP_EOL . '=== L-2. Setoran Rp100.000 + belanja Rp25.000 → Rp75.000 (kriteria 1a) ===' . PHP_EOL;
$s1 = $setor($petugas, $sSmp, 100000);
$b1 = $keluar($petugas, $sSmp, 25000);
$x1 = $db->query("SELECT jenis, arah, metode, nominal, saldo_sesudah, status, tujuan, disetujui_oleh, dicatat_oleh FROM bsa_tabungan_transaksi WHERE id = {$b1['id']}")->fetch_assoc();
$assert($s1['status'] === 201 && $s1['status_setoran'] === 'Sah' && $s1['saldo_sesudah'] === 100000 && $b1['saldo_sesudah'] === 75000 && $saldo($sSmp) === 75000,
    'Setoran tunai Rp100.000 sah seketika; belanja Rp25.000 → saldo Rp75.000');
$assert($x1['jenis'] === 'Belanja' && $x1['arah'] === 'Keluar' && $x1['status'] === 'Sah' && (int) $x1['disetujui_oleh'] === $petugas['id'] && (int) $x1['dicatat_oleh'] === $petugas['id'] && $x1['tujuan'] === 'Uji Belanja',
    'Belanja tercatat jenis Belanja, sah, tujuan tersimpan, pencatat = penyetuju');
$assert(preg_match('/^BSA-TB-' . date('Y') . '-0+' . $s1['id'] . '$/', (string) $s1['nomor_kuitansi']) === 1 && $s1['nomor_kuitansi'] !== $b1['nomor_kuitansi'], 'Nomor kuitansi tabungan unik turunan ID ledger (' . $s1['nomor_kuitansi'] . ')');
$sebelum = $jumlah();
$assert($tolak(static fn () => $keluar($petugas, $sSmp, 75001)) === 422 && $tolak(static fn () => $keluar($petugas, $sSmp, 75001, 'Penarikan')) === 422
    && $tolak(static fn () => $tab->penarikan($petugas, $sSmp, ['idempotency_key' => $kunci('x'), 'jenis' => 'Hadiah', 'nominal' => 1, 'metode' => 'Tunai', 'tujuan' => 'Uji'], 'web')) === 422
    && $tolak(static fn () => $tab->penarikan($petugas, $sSmp, ['idempotency_key' => $kunci('x'), 'jenis' => 'Belanja', 'nominal' => 1, 'metode' => 'Tunai'], 'web')) === 422,
    'Belanja/penarikan Rp75.001 (> saldo), jenis tidak dikenal, dan belanja tanpa tujuan → 422');
$assert($jumlah() === $sebelum && $saldo($sSmp) === 75000, 'Penolakan L-2 tanpa mutasi');
$kB = $kunci('l2-b');
$bA = $tab->penarikan($petugas, $sSmp, ['idempotency_key' => $kB, 'jenis' => 'Belanja', 'nominal' => 5000, 'metode' => 'Tunai', 'tujuan' => 'Sabun'], 'web');
$bB = $tab->penarikan($petugas, $sSmp, ['idempotency_key' => $kB, 'jenis' => 'Belanja', 'nominal' => 5000, 'metode' => 'Tunai', 'tujuan' => 'Sabun'], 'web');
$assert($bB['replayed'] && $bB['id'] === $bA['id'] && $tolak(static fn () => $tab->penarikan($petugas, $sSmp, ['idempotency_key' => $kB, 'jenis' => 'Penarikan', 'nominal' => 5000, 'metode' => 'Tunai', 'tujuan' => 'Sabun'], 'web')) === 409,
    'Belanja kunci sama → replay; kunci sama sebagai penarikan → 409');
$kr = $tab->koreksi($petugas, $bA['id'], ['idempotency_key' => $kunci('l2-k'), 'alasan' => 'Belanja sabun batal dibeli'], 'web');
$assert($saldo($sSmp) === 75000 && $kr['saldo'] === 75000, 'Belanja Rp5.000 dikoreksi pembalik → saldo kembali Rp75.000');

// ===================================================================== L-3
echo PHP_EOL . '=== L-3. Setoran transfer: menunggu, verifikasi, tolak ===' . PHP_EOL;
$kT = $kunci('l3-tr');
$m1 = $tab->setoran($petugasT, $sTsanawi, ['idempotency_key' => $kT, 'nominal' => 40000, 'metode' => 'Transfer', 'referensi' => 'TRF-UJI-301'], 'api');
$assert($m1['status'] === 202 && $m1['status_setoran'] === 'Menunggu' && $m1['transaksi_id'] === null && $m1['nomor_kuitansi'] === null && $saldo($sTsanawi) === 0
    && (int) $satu("SELECT COUNT(*) FROM bsa_tabungan_transaksi WHERE santri_id = $sTsanawi") === 0, 'Transfer tanpa pernyataan diperiksa → 202 menunggu; saldo Rp0; tidak ada baris ledger');
$m1b = $tab->setoran($petugasT, $sTsanawi, ['idempotency_key' => $kT, 'nominal' => 40000, 'metode' => 'Transfer', 'referensi' => 'TRF-UJI-301'], 'api');
$assert($m1b['replayed'] && $m1b['id'] === $m1['id'] && (int) $satu('SELECT COUNT(*) FROM bsa_setoran_transfer') === 1, 'Kiriman ulang kunci sama → replay, satu setoran transfer');
$assert($tolak(static fn () => $tab->setoran($petugasT, $sTsanawi, ['idempotency_key' => $kT, 'nominal' => 40000, 'metode' => 'Transfer', 'referensi' => 'TRF-UJI-301', 'terverifikasi' => '1'], 'api')) === 409
    && $tolak(static fn () => $tab->setoran($petugasT, $sTsanawi, ['idempotency_key' => $kT, 'nominal' => 45000, 'metode' => 'Transfer', 'referensi' => 'TRF-UJI-301'], 'api')) === 409
    && $tolak(static fn () => $tab->setoran($petugasT, $sTsanawi, ['idempotency_key' => $kunci('x'), 'nominal' => 1000, 'metode' => 'Transfer'], 'api')) === 422,
    'Kunci sama sebagai setoran terverifikasi atau nominal lain → 409; transfer tanpa referensi → 422');
$d3 = $tab->santri($petugasT, $sTsanawi);
$assert($d3['saldo'] === 0 && $d3['saldo_menunggu'] === 40000 && count($d3['setoran_transfer']) === 1 && $d3['setoran_transfer'][0]['status'] === 'Menunggu', 'Rincian santri: saldo efektif Rp0, setoran menunggu Rp40.000 terpisah');
$kV = $kunci('l3-v');
$v1 = $tab->verifikasiSetoran($petugas, $m1['id'], ['idempotency_key' => $kV], 'web');
$lx = $db->query("SELECT jenis, metode, nominal, saldo_sesudah, referensi, dicatat_oleh, disetujui_oleh, idempotency_key FROM bsa_tabungan_transaksi WHERE id = " . (int) $v1['transaksi_id'])->fetch_assoc();
$assert($v1['status_setoran'] === 'Sah' && $saldo($sTsanawi) === 40000 && $v1['saldo_sesudah'] === 40000 && $lx['jenis'] === 'Setoran' && $lx['metode'] === 'Transfer' && (int) $lx['nominal'] === 40000
    && $lx['referensi'] === 'TRF-UJI-301' && (int) $lx['dicatat_oleh'] === $petugasT['id'] && (int) $lx['disetujui_oleh'] === $petugas['id'] && $lx['idempotency_key'] === 'transfer#' . $m1['id'],
    'Verifikasi oleh petugas lain → satu baris ledger Setoran/Transfer sah (pencatat = penerima, penyetuju = pemeriksa), saldo Rp40.000');
$v1b = $tab->verifikasiSetoran($petugas, $m1['id'], ['idempotency_key' => $kV], 'web');
$assert($v1b['replayed'] && $v1b['transaksi_id'] === $v1['transaksi_id'] && $saldo($sTsanawi) === 40000, 'Verifikasi diulang dengan kunci sama → replay tanpa kredit kedua');
$assert($tolak(static fn () => $tab->verifikasiSetoran($petugasT, $m1['id'], ['idempotency_key' => $kunci('x')], 'web')) === 409
    && $tolak(static fn () => $tab->tolakSetoran($petugasT, $m1['id'], ['idempotency_key' => $kunci('x'), 'alasan' => 'Sudah sah'], 'web')) === 409, 'Verifikasi/tolak setoran yang sudah sah → 409');
$assert($tab->setoran($petugasT, $sTsanawi, ['idempotency_key' => $kT, 'nominal' => 40000, 'metode' => 'Transfer', 'referensi' => 'TRF-UJI-301'], 'api')['status_setoran'] === 'Sah',
    'Replay setoran awal sesudah verifikasi mengembalikan status kini (Sah)');
$m2 = $tab->setoran($petugasT, $sTsanawi, ['idempotency_key' => $kunci('l3-tr2'), 'nominal' => 15000, 'metode' => 'Transfer', 'referensi' => 'TRF-UJI-302'], 'api');
$assert($tolak(static fn () => $tab->tolakSetoran($petugasT, $m2['id'], ['idempotency_key' => $kunci('x'), 'alasan' => 'x'], 'web')) === 422, 'Tolak tanpa alasan ≥ 5 karakter → 422');
$t2 = $tab->tolakSetoran($petugasT, $m2['id'], ['idempotency_key' => $kunci('l3-t'), 'alasan' => 'Dana tidak ditemukan di rekening'], 'web');
$st2 = $db->query("SELECT status, alasan_tolak, diputus_oleh, transaksi_id FROM bsa_setoran_transfer WHERE id = {$m2['id']}")->fetch_assoc();
$assert($t2['status_setoran'] === 'Ditolak' && $st2['alasan_tolak'] === 'Dana tidak ditemukan di rekening' && (int) $st2['diputus_oleh'] === $petugasT['id'] && $st2['transaksi_id'] === null && $saldo($sTsanawi) === 40000,
    'Tolak beralasan: status Ditolak, aktor + alasan tersimpan, saldo tetap Rp40.000');
$assert($tolak(static fn () => $tab->verifikasiSetoran($petugas, $m2['id'], ['idempotency_key' => $kunci('x')], 'web')) === 409, 'Verifikasi setoran yang ditolak → 409');
$tv = $tab->setoran($petugasT, $sTsanawi, ['idempotency_key' => $kunci('l3-tv'), 'nominal' => 10000, 'metode' => 'Transfer', 'referensi' => 'TRF-UJI-303', 'terverifikasi' => '1'], 'api');
$assert($tv['status'] === 201 && $tv['status_setoran'] === 'Sah' && $saldo($sTsanawi) === 50000, 'Transfer dengan pernyataan dana sudah diperiksa → sah seketika (saldo Rp50.000)');
$assert($tab->daftarSetoranTransfer($petugasT, [])['total'] === 0 && $tab->daftarSetoranTransfer($petugasT, ['status' => 'semua'])['total'] === 2
    && $tab->daftarSetoranTransfer($petugas, ['status' => 'Sah'])['total'] === 1, 'Daftar setoran transfer menurut status (menunggu 0, semua 2, sah 1)');

// ===================================================================== L-4
echo PHP_EOL . '=== L-4. Bayar tagihan Rp50.000 dari saldo Rp75.000 (kriteria 2) ===' . PHP_EOL;
$t100 = $tagihanBaru($sSmp, '2026-10', 100000);
$assert($saldo($sSmp) === 75000 && (int) $tagihanRow($t100)['terbayar'] === 0, 'Tagihan Rp100.000 terbit tanpa memotong tabungan otomatis (saldo tetap Rp75.000)');
$sebelum = $jumlah();
$assert($tolak(static fn () => $tag->bayarDariTabungan($petugas, $t100, ['idempotency_key' => $kunci('x'), 'nominal' => 50000], 'web')) === 422
    && str_contains($pesanTolak(static fn () => $tag->bayarDariTabungan($petugas, $t100, ['idempotency_key' => $kunci('x'), 'nominal' => 50000, 'setuju' => '0'], 'web')), 'persetujuan'),
    'Tanpa centang persetujuan pemotongan tabungan → 422 (tindakan eksplisit)');
$assert($tolak(static fn () => $bayarTab($petugas, $t100, 75001)) === 422 && str_contains($pesanTolak(static fn () => $bayarTab($petugas, $t100, 75001)), 'Saldo tabungan tidak mencukupi')
    && $tolak(static fn () => $bayarTab($petugas, $tagHak, 10001)) === 422, 'Melebihi saldo (Rp75.001) atau melebihi sisa tagihan → 422');
$assert($jumlah() === $sebelum, 'Penolakan bayar dari tabungan tanpa mutasi');
$kBt = $kunci('l4-bt');
$p1 = $bayarTab($petugas, $t100, 50000, $kBt, ['catatan' => 'Atas permintaan wali']);
$rT = $tagihanRow($t100);
$assert($p1['status'] === 201 && $p1['status_pembayaran'] === 'Sah' && $p1['sisa'] === 50000 && $p1['saldo'] === 25000 && $saldo($sSmp) === 25000 && (int) $rT['terbayar'] === 50000,
    'Bayar Rp50.000 dari tabungan → saldo Rp25.000, sisa tagihan tepat Rp50.000');
$debit = $db->query('SELECT id, jenis, arah, metode, nominal, saldo_sesudah, tujuan, idempotency_key FROM bsa_tabungan_transaksi WHERE id = ' . (int) $p1['transaksi_id'])->fetch_assoc();
$pb = $db->query("SELECT sumber, status, tabungan_transaksi_id, diverifikasi_oleh, nomor_kuitansi FROM bsa_pembayaran WHERE id = {$p1['id']}")->fetch_assoc();
$assert($debit['jenis'] === 'PembayaranTagihan' && $debit['arah'] === 'Keluar' && $debit['metode'] === 'Internal' && (int) $debit['saldo_sesudah'] === 25000
    && $pb['sumber'] === 'Tabungan' && $pb['status'] === 'Sah' && (int) $pb['tabungan_transaksi_id'] === (int) $debit['id'] && (int) $pb['diverifikasi_oleh'] === $petugas['id'],
    'Satu debit ledger PembayaranTagihan/Internal dan satu pembayaran Tabungan sah yang menunjuk debit itu (referensi silang unik)');
$assert((int) $satu("SELECT COUNT(*) FROM bsa_pembayaran_kuitansi WHERE pembayaran_id = {$p1['id']} AND total_tagihan = 100000 AND terbayar_sebelum = 0 AND sisa_sesudah = 50000") === 1,
    'Kuitansi tagihan snapshot: total Rp100.000, terbayar sebelum Rp0, sisa sesudah Rp50.000');
$riwayat = $tab->santri($petugas, $sSmp)['tabungan']['rows'];
$baris = array_values(array_filter($riwayat, static fn ($x) => $x['id'] === (int) $debit['id']))[0] ?? [];
$rincian = $tag->rincianTagihan($petugas, $t100);
$bayarRow = $rincian['pembayaran'][0] ?? [];
$assert(($baris['pembayaran']['id'] ?? 0) === $p1['id'] && ($baris['pembayaran']['nomor_kuitansi'] ?? '') === $pb['nomor_kuitansi'] && ($baris['pembayaran']['tagihan_id'] ?? 0) === $t100
    && ($bayarRow['tabungan']['transaksi_id'] ?? 0) === (int) $debit['id'] && ($bayarRow['tabungan']['nomor_kuitansi'] ?? '') === $baris['nomor_kuitansi'],
    'Referensi silang terbaca di kedua riwayat: debit tabungan → ' . $pb['nomor_kuitansi'] . ', pembayaran tagihan → ' . ($baris['nomor_kuitansi'] ?? '?'));
$waliRow = array_values(array_filter($tab->santri($waliSmp, $sSmp)['tabungan']['rows'], static fn ($x) => $x['id'] === (int) $debit['id']))[0] ?? [];
$assert(($waliRow['pembayaran']['nomor_kuitansi'] ?? '') === $pb['nomor_kuitansi'] && ($tag->rincianTagihan($waliSmp, $t100)['pembayaran'][0]['tabungan']['transaksi_id'] ?? 0) === (int) $debit['id'],
    'Wali anak itu membaca referensi silang yang sama di riwayat tabungan dan rincian tagihan');
$tB = $tagihanBaru($sSmp, '2026-08', 20000);
$tag->batalkanTagihan($petugas, $tB, ['versi' => 1, 'alasan' => 'Uji tagihan dibatalkan', 'idempotency_key' => $kunci('x')], 'web');
$assert($tolak(static fn () => $bayarTab($petugas, $tB, 1000)) === 422, 'Bayar dari tabungan atas tagihan yang dibatalkan → 422');
$tagTs = $tagihanBaru($sTsanawi, '2026-09', 10000);
$tr = $tag->catatPembayaran($petugas, $tagTs, ['idempotency_key' => $kunci('l4-tr'), 'nominal' => 8000, 'sumber' => 'Transfer', 'referensi' => 'TRF-UJI-401', 'tanggal_transaksi' => $hariIni], 'web');
$assert($tolak(static fn () => $bayarTab($petugas, $tagTs, 3000)) === 422 && $bayarTab($petugas, $tagTs, 2000)['sisa'] === 8000,
    'Transfer menunggu ikut membatasi: sisa Rp10.000 − menunggu Rp8.000 → Rp3.000 ditolak, Rp2.000 diterima');
$tag->batalkanPembayaran($petugas, $tr['id'], ['alasan' => 'Transfer keliru santri', 'idempotency_key' => $kunci('x')], 'web');

// ===================================================================== L-5
echo PHP_EOL . '=== L-5. Kiriman ulang dan klik ganda (kriteria 4) ===' . PHP_EOL;
$sebelum = $jumlah();
$p1b = $bayarTab($petugas, $t100, 50000, $kBt, ['catatan' => 'Atas permintaan wali']);
$assert($p1b['replayed'] && $p1b['id'] === $p1['id'] && $p1b['transaksi_id'] === $p1['transaksi_id'] && $p1b['sisa'] === 50000 && $p1b['saldo'] === 25000, 'Ulang request kunci sama → hasil sama (pembayaran dan debit yang sama)');
$assert($tolak(static fn () => $bayarTab($petugas, $t100, 40000, $kBt, ['catatan' => 'Atas permintaan wali'])) === 409, 'Kunci sama, nominal berbeda → 409');
$sesudah = $jumlah();
$assert($sesudah['bsa_tabungan_transaksi'] === $sebelum['bsa_tabungan_transaksi'] && $sesudah['bsa_pembayaran'] === $sebelum['bsa_pembayaran'] && $saldo($sSmp) === 25000,
    'Tidak ada debit atau pembayaran kedua');

// ===================================================================== L-6
echo PHP_EOL . '=== L-6. Konkurensi (proses terpisah, kriteria 1b) ===' . PHP_EOL;
$scratch = sys_get_temp_dir() . '/v6f3-' . bin2hex(random_bytes(4));
mkdir($scratch);
$serbu = static function (array $permintaan) use ($scratch, $dbName): array {
    $aba = $scratch . '/mulai-' . bin2hex(random_bytes(3));
    $proc = [];
    foreach ($permintaan as $i => $p) {
        $h = proc_open(array_merge(['php', dirname(__DIR__) . '/tests/v6_phase3_worker.php', $aba], array_map('strval', $p)),
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
$ok = static fn (array $h): int => count(array_filter($h, static fn ($r) => $r['ok']));
$bisnis = static fn (array $h, array $kode = [422]): int => count(array_filter($h, static fn ($r) => !$r['ok'] && in_array($r['status'], $kode, true)));
$pB = $petugas['id'];
$pL = $petugasL['id'];
$setor($petugas, $sTanpa, 100000);
$keluar($petugas, $sTanpa, 25000);
$assert($saldo($sTanpa) === 75000, 'Santri uji konkurensi: setoran Rp100.000 + belanja Rp25.000 → Rp75.000');
$h = $serbu([[$pB, 'belanja', $sTanpa, $kunci('c1'), 60000], [$pL, 'belanja', $sTanpa, $kunci('c2'), 60000]]);
$assert($ok($h) === 1 && $bisnis($h) === 1 && $saldo($sTanpa) === 15000, 'Dua belanja Rp60.000 bersamaan atas saldo Rp75.000 → tepat satu sukses, satu 422, saldo Rp15.000 (' . json_encode($h) . ')');
$setor($petugas, $sTanpa, 85000);
$tc1 = $tagihanBaru($sTanpa, '2027-01', 100000);
$tc2 = $tagihanBaru($sTanpa, '2027-02', 100000);
$h = $serbu([[$pB, 'bayartab', $tc1, $kunci('c3'), 60000], [$pL, 'bayartab', $tc2, $kunci('c4'), 60000]]);
$assert($ok($h) === 1 && $bisnis($h) === 1 && $saldo($sTanpa) === 40000 && (int) $tagihanRow($tc1)['terbayar'] + (int) $tagihanRow($tc2)['terbayar'] === 60000,
    'Dua pembayaran Rp60.000 dari saldo Rp100.000 ke dua tagihan bersamaan → tepat satu sukses, saldo Rp40.000 (' . json_encode($h) . ')');
$h = $serbu([[$pB, 'bayartab', $tc1, $kunci('c5'), 30000], [$pL, 'penarikan', $sTanpa, $kunci('c6'), 30000]]);
$assert($ok($h) === 1 && $bisnis($h) === 1 && $saldo($sTanpa) === 10000, 'Pembayaran tabungan Rp30.000 vs penarikan Rp30.000 bersamaan atas saldo Rp40.000 → tepat satu sukses (' . json_encode($h) . ')');
$setor($petugas, $sTanpa, 90000);
$tc3 = $tagihanBaru($sTanpa, '2027-03', 50000);
$h = $serbu([[$pB, 'bayartab', $tc3, $kunci('c7'), 40000], [$pL, 'bayartunai', $tc3, $kunci('c8'), 40000]]);
$assert($ok($h) === 1 && $bisnis($h) === 1 && (int) $tagihanRow($tc3)['terbayar'] === 40000, 'Pembayaran tabungan vs tunai Rp40.000 bersamaan atas sisa Rp50.000 → satu sukses, tanpa bayar berlebih (' . json_encode($h) . ')');
$tc4 = $tagihanBaru($sTanpa, '2027-04', 100000);
$saldoSebelum = $saldo($sTanpa);
$kSama = $kunci('c-sama');
$h = $serbu(array_fill(0, 8, [$pB, 'bayartab', $tc4, $kSama, 20000]));
$assert($ok($h) === 8 && count(array_unique(array_column($h, 'id'))) === 1 && count(array_filter($h, static fn ($r) => $r['ok'] && !$r['replayed'])) === 1
    && (int) $satu("SELECT COUNT(*) FROM bsa_pembayaran WHERE tagihan_id = $tc4") === 1 && $saldo($sTanpa) === $saldoSebelum - 20000
    && (int) $satu("SELECT COUNT(*) FROM bsa_tabungan_transaksi WHERE idempotency_key = '$kSama'") === 1, 'Delapan klik bersamaan kunci+isi sama → satu debit dan satu pembayaran, tujuh replay (' . json_encode(array_column($h, 'replayed')) . ')');
$saldoSebelum = $saldo($sTanpa);
$bisa = min(10, intdiv($saldoSebelum, 10000));
$h = $serbu(array_map(static fn ($i) => [$i % 2 ? $pB : $pL, 'belanja', $sTanpa, $kunci('c8-' . $i), 10000], range(1, 10)));
$assert($bisa < 10 && $ok($h) === $bisa && $bisnis($h) === 10 - $bisa && $saldo($sTanpa) === $saldoSebelum - $bisa * 10000
    && count(array_unique(array_column(array_filter($h, static fn ($r) => $r['ok']), 'saldo_sesudah'))) === $bisa,
    'Sepuluh belanja Rp10.000 bersamaan atas saldo Rp' . $saldoSebelum . ' → tepat ' . $bisa . ' sukses berurutan (saldo sesudah berbeda), ' . (10 - $bisa) . ' × 422, saldo Rp' . $saldo($sTanpa));
$m6 = $tab->setoran($petugas, $sTanpa, ['idempotency_key' => $kunci('c-tr'), 'nominal' => 30000, 'metode' => 'Transfer', 'referensi' => 'TRF-UJI-601'], 'web');
$saldoSebelum = $saldo($sTanpa);
$h = $serbu([[$pB, 'verifikasi', $m6['id'], $kunci('cv1')], [$pL, 'verifikasi', $m6['id'], $kunci('cv2')]]);
$assert($ok($h) === 1 && $bisnis($h, [409]) === 1 && $saldo($sTanpa) === $saldoSebelum + 30000 && (int) $satu("SELECT COUNT(*) FROM bsa_tabungan_transaksi WHERE idempotency_key = 'transfer#{$m6['id']}'") === 1,
    'Dua verifikasi bersamaan atas setoran transfer yang sama → satu kredit, satu 409 (' . json_encode($h) . ')');
$sx = $setor($petugas, $sTanpa, 5000);
$h = $serbu([[$pB, 'koreksi', $sx['id'], $kunci('ck1')], [$pL, 'koreksi', $sx['id'], $kunci('ck2')]]);
$assert($ok($h) === 1 && $bisnis($h, [409]) === 1 && (int) $satu("SELECT COUNT(*) FROM bsa_tabungan_transaksi WHERE pembalik_dari_id = {$sx['id']}") === 1,
    'Dua koreksi bersamaan atas setoran yang sama → satu pembalik, satu 409 (' . json_encode($h) . ')');
$pc = $bayarTab($petugas, $tc4, 10000);
$saldoSebelum = $saldo($sTanpa);
$h = $serbu([[$pB, 'batal', $pc['id'], $kunci('cb1')], [$pL, 'batal', $pc['id'], $kunci('cb2')]]);
$assert($ok($h) === 1 && $bisnis($h, [409]) === 1 && $saldo($sTanpa) === $saldoSebelum + 10000 && (int) $satu("SELECT COUNT(*) FROM bsa_tabungan_transaksi WHERE pembalik_dari_id = {$pc['transaksi_id']}") === 1,
    'Dua pembatalan bersamaan atas pembayaran dari tabungan → satu pengembalian saldo, satu 409 (' . json_encode($h) . ')');
$assert((int) $satu('SELECT COUNT(*) FROM bsa_tabungan_akun WHERE saldo < 0') === 0 && (int) $satu("SELECT COUNT(*) FROM bsa_tagihan WHERE terbayar > total") === 0, 'Nol saldo negatif dan nol tagihan terbayar berlebih');
array_map('unlink', glob($scratch . '/*') ?: []);
rmdir($scratch);

// ===================================================================== L-7
echo PHP_EOL . '=== L-7. Kegagalan di antara dua pencatatan (kriteria 3) ===' . PHP_EOL;
$t7 = $tagihanBaru($sTsanawi, '2026-10', 100000);
$jalankan('DROP TRIGGER IF EXISTS uji_v6f3_bayar_gagal');
$jalankan("CREATE TRIGGER uji_v6f3_bayar_gagal BEFORE INSERT ON bsa_pembayaran FOR EACH ROW BEGIN
    IF NEW.sumber = 'Tabungan' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uji pembayaran gagal sesudah debit'; END IF; END");
$sebelum = $jumlah();
$saldoT = $saldo($sTsanawi);
$kGagal = $kunci('l7-a');
$assert($tolak(static fn () => $bayarTab($petugas, $t7, 30000, $kGagal)) === 503, 'INSERT pembayaran gagal SESUDAH debit ledger + saldo disiapkan → 503');
$jalankan('DROP TRIGGER uji_v6f3_bayar_gagal');
$assert($jumlah() === $sebelum && $saldo($sTsanawi) === $saldoT && (int) $tagihanRow($t7)['terbayar'] === 0, 'Rollback: saldo Rp' . $saldoT . ' dan sisa Rp100.000 semula; nol ledger, pembayaran, klaim, atau audit baru');
$assert((int) $satu("SELECT COUNT(*) FROM bsa_tabungan_transaksi x WHERE x.jenis = 'PembayaranTagihan' AND NOT EXISTS (SELECT 1 FROM bsa_pembayaran p WHERE p.tabungan_transaksi_id = x.id)") === 0
    && (int) $satu("SELECT COUNT(*) FROM bsa_tabungan_transaksi WHERE idempotency_key = '$kGagal'") === 0, 'Nol debit tabungan yatim');
$jalankan('DROP TRIGGER IF EXISTS uji_v6f3_kuitansi_gagal');
$jalankan("CREATE TRIGGER uji_v6f3_kuitansi_gagal BEFORE INSERT ON bsa_pembayaran_kuitansi FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uji kuitansi gagal'; END");
$assert($tolak(static fn () => $bayarTab($petugas, $t7, 30000)) === 503, 'Kuitansi gagal sesudah debit, pembayaran, dan terbayar → 503');
$jalankan('DROP TRIGGER uji_v6f3_kuitansi_gagal');
$jalankan('DROP TRIGGER IF EXISTS uji_v6f3_audit_gagal');
$jalankan("CREATE TRIGGER uji_v6f3_audit_gagal BEFORE INSERT ON audit_logs FOR EACH ROW BEGIN
    IF NEW.action IN ('bsa.pembayaran.tabungan', 'bsa.tabungan.verifikasi', 'bsa.tabungan.koreksi', 'bsa.tabungan.belanja', 'bsa.tabungan.setoran', 'bsa.tabungan.tolak') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uji audit gagal'; END IF; END");
$sebelum = $jumlah();
$assert($tolak(static fn () => $bayarTab($petugas, $t7, 30000)) === 503, 'Audit pembayaran dari tabungan gagal → 503');
$assert($tolak(static fn () => $keluar($petugas, $sTsanawi, 1000)) === 503 && $tolak(static fn () => $setor($petugas, $sTsanawi, 1000, ['metode' => 'Transfer', 'referensi' => 'TRF-UJI-702'])) === 503,
    'Audit belanja dan setoran transfer menunggu gagal → 503');
$assert($tolak(static fn () => $tab->koreksi($petugas, $b1['id'], ['idempotency_key' => $kunci('x'), 'alasan' => 'Uji audit gagal'], 'web')) === 503, 'Koreksi belanja dengan audit gagal → 503');
$jalankan('DROP TRIGGER uji_v6f3_audit_gagal');
$assert($jumlah() === $sebelum && $saldo($sTsanawi) === $saldoT && (int) $tagihanRow($t7)['terbayar'] === 0, 'Seluruh kegagalan audit dibatalkan atomik: nol baris/klaim baru, saldo dan sisa semula');
// Kegagalan pengembalian saat membatalkan pembayaran dari tabungan.
$p7 = $bayarTab($petugas, $t7, 20000);
$jalankan('DROP TRIGGER IF EXISTS uji_v6f3_balik_gagal');
$jalankan("CREATE TRIGGER uji_v6f3_balik_gagal BEFORE INSERT ON bsa_tabungan_transaksi FOR EACH ROW BEGIN
    IF NEW.jenis = 'Pembalik' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uji pengembalian gagal'; END IF; END");
$sebelum = $jumlah();
$assert($tolak(static fn () => $tag->batalkanPembayaran($petugas, $p7['id'], ['alasan' => 'Uji pengembalian gagal', 'idempotency_key' => $kunci('x')], 'web')) === 503,
    'Pembatalan pembayaran dari tabungan dengan pengembalian saldo gagal → 503');
$jalankan('DROP TRIGGER uji_v6f3_balik_gagal');
$assert($jumlah() === $sebelum && (string) $satu("SELECT status FROM bsa_pembayaran WHERE id = {$p7['id']}") === 'Sah' && (int) $tagihanRow($t7)['terbayar'] === 20000,
    'Rollback: pembayaran tetap Sah, terbayar Rp20.000, tanpa entri pembatalan atau pengembalian yatim');
$assert((int) $satu('SELECT COUNT(*) FROM bsa_idempotensi WHERE hasil_id IS NULL') === 0, 'Tidak ada klaim idempotensi menggantung');

// ===================================================================== L-8
echo PHP_EOL . '=== L-8. Koreksi transaksi, termasuk yang terkait tagihan ===' . PHP_EOL;
// sSmp: setoran Rp100.000 (s1), belanja Rp25.000, debit tagihan Rp50.000 → saldo Rp25.000.
$assert($saldo($sSmp) === 25000, 'Keadaan awal L-8: saldo Rp25.000 sesudah pembayaran tagihan Rp50.000');
$sebelum = $jumlah();
$assert($tolak(static fn () => $tab->koreksi($petugas, $s1['id'], ['idempotency_key' => $kunci('x'), 'alasan' => 'Setoran salah santri'], 'web')) === 422
    && str_contains($pesanTolak(static fn () => $tab->koreksi($petugas, $s1['id'], ['idempotency_key' => $kunci('x'), 'alasan' => 'Setoran salah santri'], 'web')), 'pembayaran tagihan'),
    'Membalik setoran Rp100.000 yang sudah dipakai membayar tagihan (saldo Rp25.000) → 422, saldo tidak boleh negatif');
$assert($tolak(static fn () => $tab->koreksi($petugas, (int) $p1['transaksi_id'], ['idempotency_key' => $kunci('x'), 'alasan' => 'Koreksi debit'], 'web')) === 422
    && str_contains($pesanTolak(static fn () => $tab->koreksi($petugas, (int) $p1['transaksi_id'], ['idempotency_key' => $kunci('x'), 'alasan' => 'Koreksi debit'], 'web')), $pb['nomor_kuitansi']),
    'Debit pembayaran tagihan tidak dibalik sendirian → 422 dengan rujukan ke pembatalan ' . $pb['nomor_kuitansi']);
$assert($tolak(static fn () => $tab->koreksi($petugas, $s1['id'], ['idempotency_key' => $kunci('x'), 'alasan' => 'abc'], 'web')) === 422, 'Koreksi tanpa alasan ≥ 5 karakter → 422');
$assert($jumlah() === $sebelum, 'Penolakan koreksi tanpa mutasi');
$kBatal = $kunci('l8-batal');
$bt = $tag->batalkanPembayaran($petugas, $p1['id'], ['alasan' => 'Wali membayar tunai sendiri', 'idempotency_key' => $kBatal], 'web');
$balik = $db->query("SELECT id, jenis, arah, metode, nominal, saldo_sesudah, pembalik_dari_id, alasan FROM bsa_tabungan_transaksi WHERE pembalik_dari_id = " . (int) $p1['transaksi_id'])->fetch_assoc();
$assert($bt['status_pembayaran'] === 'Dibatalkan' && $bt['sisa'] === 100000 && $bt['saldo'] === 75000 && $saldo($sSmp) === 75000 && (int) $tagihanRow($t100)['terbayar'] === 0,
    'Pembatalan pembayaran dari tabungan → sisa kembali Rp100.000 dan saldo kembali Rp75.000 dalam satu transaksi');
$assert($balik !== null && $balik['jenis'] === 'Pembalik' && $balik['arah'] === 'Masuk' && $balik['metode'] === 'Internal' && (int) $balik['nominal'] === 50000
    && $balik['alasan'] === 'Wali membayar tunai sendiri' && (int) $bt['transaksi_id'] === (int) $balik['id'], 'Pengembalian = baris Pembalik/Masuk/Internal beralasan yang merujuk debit asal');
$rb = $tag->rincianTagihan($petugas, $t100)['pembayaran'][0];
$rowBalik = array_values(array_filter($tab->santri($petugas, $sSmp)['tabungan']['rows'], static fn ($x) => $x['id'] === (int) $balik['id']))[0] ?? [];
$assert($rb['status'] === 'Dibatalkan' && $rb['tabungan']['pengembalian_id'] === (int) $balik['id'] && ($rowBalik['pembayaran']['id'] ?? 0) === $p1['id'] && ($rowBalik['pembalik_dari']['id'] ?? 0) === (int) $p1['transaksi_id'],
    'Riwayat tagihan menunjuk pengembalian; riwayat tabungan menunjuk pembayaran dan debit asal');
$bt2 = $tag->batalkanPembayaran($petugas, $p1['id'], ['alasan' => 'Wali membayar tunai sendiri', 'idempotency_key' => $kBatal], 'web');
$assert($bt2['replayed'] && $saldo($sSmp) === 75000 && $tolak(static fn () => $tag->batalkanPembayaran($petugas, $p1['id'], ['alasan' => 'Batal lagi', 'idempotency_key' => $kunci('x')], 'web')) === 409,
    'Pembatalan diulang: replay tanpa pengembalian kedua; kunci baru → 409');
$assert($tolak(static fn () => $tab->koreksi($petugas, (int) $balik['id'], ['idempotency_key' => $kunci('x'), 'alasan' => 'Balik pengembalian'], 'web')) === 422, 'Transaksi pembalik tidak dapat dibalik lagi → 422');
$assert($tolak(static fn () => $tab->koreksi($admin, $b1['id'], ['idempotency_key' => $kunci('x'), 'alasan' => 'Admin mencoba'], 'web')) === 403
    && $tolak(static fn () => $tab->koreksi($waliSmp, $b1['id'], ['idempotency_key' => $kunci('x'), 'alasan' => 'Wali mencoba'], 'web')) === 403
    && $tolak(static fn () => $tab->koreksi($petugasT, $b1['id'], ['idempotency_key' => $kunci('x'), 'alasan' => 'Unit lain mencoba'], 'web')) === 404
    && $tolak(static fn () => $tab->koreksi($petugas, 99999999, ['idempotency_key' => $kunci('x'), 'alasan' => 'Tidak ada'], 'web')) === 404, 'Koreksi oleh admin/wali → 403; unit lain/ID tidak ada → 404');
$kb1 = $tab->koreksi($petugas, $b1['id'], ['idempotency_key' => $kunci('l8-kb'), 'alasan' => 'Belanja kitab dikembalikan toko'], 'web');
$assert($saldo($sSmp) === 100000 && $kb1['saldo'] === 100000, 'Koreksi belanja Rp25.000 → pembalik Masuk, saldo Rp100.000');
$k8 = $tab->koreksi($petugas, $s1['id'], ['idempotency_key' => $kunci('l8-k'), 'alasan' => 'Setoran tercatat ganda'], 'web');
$assert($k8['saldo'] === 0 && $saldo($sSmp) === 0 && $tolak(static fn () => $tab->koreksi($petugas, $s1['id'], ['idempotency_key' => $kunci('x'), 'alasan' => 'Koreksi lagi'], 'web')) === 409,
    'Sesudah pembayaran dibatalkan dan belanja dikoreksi, setoran Rp100.000 dapat dibalik (saldo Rp0); koreksi kedua → 409');
$kx = $db->query("SELECT arah, metode, nominal, alasan, tujuan FROM bsa_tabungan_transaksi WHERE id = {$k8['id']}")->fetch_assoc();
$assert($kx['arah'] === 'Keluar' && $kx['metode'] === 'Tunai' && (int) $kx['nominal'] === 100000 && $kx['alasan'] === 'Setoran tercatat ganda' && str_contains((string) $kx['tujuan'], $s1['nomor_kuitansi']),
    'Pembalik setoran: Keluar, metode Tunai sama, nominal sama, alasan tersimpan, tujuan merujuk ' . $s1['nomor_kuitansi']);
$assert((int) $satu("SELECT nominal FROM bsa_tabungan_transaksi WHERE id = {$s1['id']}") === 100000 && (int) $satu("SELECT saldo_sesudah FROM bsa_tabungan_transaksi WHERE id = {$s1['id']}") === 100000,
    'Transaksi asal tidak diubah (nominal dan saldo sesudah tetap)');
$assert($tolak(static fn () => $bayarTab($petugas, $t100, 1000)) === 422 && (int) $tagihanRow($t100)['terbayar'] === 0, 'Saldo Rp0: bayar dari tabungan → 422, tagihan tetap');

// ===================================================================== L-9
echo PHP_EOL . '=== L-9. Rekonsiliasi harian (kriteria 5) ===' . PHP_EOL;
$r0 = $tab->rekonsiliasi($admin, []);
$assert($r0['internal']['selisih'] === 0 && $r0['internal']['selisih_pengembalian'] === 0 && $r0['saldo']['selisih'] === 0 && $r0['saldo']['selisih_akun'] === 0,
    'Rekonsiliasi admin hari ini: selisih internal Rp0, selisih pengembalian Rp0, saldo awal + mutasi = akhir = Σ akun');
$kas0 = $r0['penerimaan_kas_baru'];
$setor($petugas, $sTanpa, 100000);
$r1 = $tab->rekonsiliasi($admin, []);
$assert($r1['penerimaan_kas_baru'] === $kas0 + 100000 && $r1['kas']['Tunai']['setoran'] === $r0['kas']['Tunai']['setoran'] + 100000, 'Setoran tunai Rp100.000 → penerimaan kas baru +Rp100.000');
$t9 = $tagihanBaru($sTanpa, '2027-06', 50000);
$p9 = $bayarTab($petugas, $t9, 50000);
$r2 = $tab->rekonsiliasi($admin, []);
$assert($r2['penerimaan_kas_baru'] === $r1['penerimaan_kas_baru'] && $r2['kas'] === $r1['kas'], 'Pembayaran tagihan Rp50.000 dari tabungan → penerimaan kas dan seluruh kas tunai/transfer TIDAK bertambah');
$assert($r2['internal']['pembayaran_dari_tabungan'] === $r1['internal']['pembayaran_dari_tabungan'] + 50000 && $r2['internal']['debit_tabungan'] === $r1['internal']['debit_tabungan'] + 50000
    && $r2['internal']['selisih'] === 0 && $r2['tagihan']['pembayaran']['Tabungan'] === $r1['tagihan']['pembayaran']['Tabungan'] + 50000,
    'Pembayaran itu tercatat sebagai perpindahan internal: debit tabungan = pembayaran dari tabungan (+Rp50.000), selisih Rp0');
$t9b = $tagihanBaru($sTanpa, '2027-07', 30000);
$tag->catatPembayaran($petugas, $t9b, ['idempotency_key' => $kunci('l9-tn'), 'nominal' => 30000, 'sumber' => 'Tunai', 'tanggal_transaksi' => $hariIni], 'web');
$r3 = $tab->rekonsiliasi($admin, []);
$assert($r3['penerimaan_kas_baru'] === $r2['penerimaan_kas_baru'] + 30000 && $r3['kas']['Tunai']['pembayaran_tagihan'] === $r2['kas']['Tunai']['pembayaran_tagihan'] + 30000,
    'Pembayaran tagihan tunai Rp30.000 → penerimaan kas baru +Rp30.000 (uang nyata)');
$m9 = $tab->setoran($petugas, $sTanpa, ['idempotency_key' => $kunci('l9-tr'), 'nominal' => 70000, 'metode' => 'Transfer', 'referensi' => 'TRF-UJI-901'], 'web');
$r4 = $tab->rekonsiliasi($admin, []);
$assert($r4['penerimaan_kas_baru'] === $r3['penerimaan_kas_baru'] && $r4['menunggu']['setoran_transfer']['nominal'] >= 70000, 'Setoran transfer menunggu tidak dihitung sebagai kas; tampil di "menunggu"');
$tab->verifikasiSetoran($petugas, $m9['id'], ['idempotency_key' => $kunci('l9-v')], 'web');
$r5 = $tab->rekonsiliasi($admin, []);
$assert($r5['penerimaan_kas_baru'] === $r4['penerimaan_kas_baru'] + 70000 && $r5['kas']['Transfer']['setoran'] === $r4['kas']['Transfer']['setoran'] + 70000, 'Sesudah diverifikasi: transfer Rp70.000 masuk penerimaan kas');
$mutasiHari = $tab->rekonsiliasi($admin, []);
$sumLedger = (int) $satu("SELECT COALESCE(SUM(CASE WHEN arah = 'Masuk' THEN nominal ELSE -nominal END), 0) FROM bsa_tabungan_transaksi");
$assert($mutasiHari['saldo']['akhir'] === $sumLedger && $mutasiHari['saldo']['akun'] === (int) $satu('SELECT SUM(saldo) FROM bsa_tabungan_akun') && $mutasiHari['saldo']['selisih_akun'] === 0,
    'Saldo akhir rekonsiliasi = Σ ledger = Σ akun (Rp' . $sumLedger . ')');
$rT = $tab->rekonsiliasi($petugasT, []);
$assert(array_filter($rT['rows'], static fn ($x) => $x['santri']['id'] !== $sTsanawi) === [] && $rT['penerimaan_kas_baru'] < $mutasiHari['penerimaan_kas_baru'],
    'Petugas Tsanawi hanya merekonsiliasi santri dalam cakupannya');
$kemarin = $tab->rekonsiliasi($admin, ['tanggal' => date('Y-m-d', strtotime('-1 day'))]);
$assert($kemarin['rows'] === [] && $kemarin['penerimaan_kas_baru'] === 0 && $kemarin['saldo']['akun'] === null, 'Tanggal kemarin: tanpa transaksi, saldo akun cerminan tidak dibandingkan');
$assert($tolak(static fn () => $tab->rekonsiliasi($admin, ['tanggal' => date('Y-m-d', strtotime('+1 day'))])) === 422 && $tolak(static fn () => $tab->rekonsiliasi($admin, ['tanggal' => '2026-02-30'])) === 422,
    'Tanggal masa depan atau tidak valid → 422');

// ===================================================================== L-10
echo PHP_EOL . '=== L-10. Otorisasi wali ===' . PHP_EOL;
$dw = $tab->santri($waliSmp, $sSmp);
$assert($dw['setoran_transfer'] === [] && $dw['saldo_menunggu'] === 0 && array_filter($dw['tabungan']['rows'], static fn ($x) => array_key_exists('referensi', $x) || array_key_exists('alasan', $x) || array_key_exists('catatan', $x)) === [],
    'Wali: riwayat tabungan anak tanpa referensi, catatan, alasan; tanpa setoran transfer');
$tw = $tab->transaksi($waliSmp, $k8['id']);
$assert($tw['jenis'] === 'Pembalik' && !array_key_exists('alasan', $tw) && !array_key_exists('referensi', $tw) && $tw['saldo_sebelum'] === 100000 && $tw['saldo_sesudah'] === 0, 'Wali membuka kuitansi tabungan anaknya tanpa alasan/referensi');
$terbuka = 0;
$maks = (int) $satu('SELECT MAX(id) FROM bsa_tabungan_transaksi');
for ($i = 1; $i <= $maks + 2; $i++) {
    try {
        $x = $tab->transaksi($waliSmp, $i);
        $terbuka += $x['santri']['id'] === $sSmp ? 0 : 1000;
    } catch (BsaException $e) {
        $terbuka += $e->status === 404 ? 0 : 1000;
    }
}
$milikSmp = (int) $satu("SELECT COUNT(*) FROM bsa_tabungan_transaksi WHERE santri_id = $sSmp");
$assert($terbuka === 0, "Seluruh ID transaksi 1..$maks ditebak wali SMP → hanya $milikSmp transaksi anaknya, lainnya 404");
$assert($tolak(static fn () => $tab->transaksi($waliT, $s1['id'])) === 404 && $tolak(static fn () => $tab->santri($waliT, $sSmp)) === 404 && $tolak(static fn () => $tab->transaksi($akun('sbx_ortu_a'), $s1['id'])) === 404,
    'Wali anak lain dan wali tanpa relasi → 404');
$relasi = (int) $satu("SELECT sw.id FROM santri_wali sw JOIN users u ON u.wali_id = sw.wali_id WHERE u.username = 'sbx_v6_wali_smp' AND sw.santri_id = $sSmp");
$jalankan("UPDATE santri_wali SET archived_at = NOW() WHERE id = $relasi");
$assert($tolak(static fn () => $tab->transaksi($waliSmp, $s1['id'])) === 404 && $tolak(static fn () => $tab->santri($waliSmp, $sSmp)) === 404, 'Relasi wali dicabut → kuitansi dan rincian 404 pada sesi yang sama');
$jalankan("UPDATE santri_wali SET archived_at = NULL WHERE id = $relasi");
foreach (['bsa.tabungan.verifikasi', 'bsa.pembayaran.tabungan', 'bsa.tabungan.koreksi'] as $act) {
    $assert((int) $satu("SELECT COUNT(*) FROM audit_logs WHERE action = '$act'") > 0, "Jejak audit $act ada");
}

// ===================================================================== L-11
echo PHP_EOL . '=== L-11. Pencabutan penugasan, cakupan, santri nonaktif ===' . PHP_EOL;
$tugasB = (int) $satu("SELECT a.id FROM bendahara_bulanan_assignments a JOIN users u ON u.pengurus_id = a.pengurus_id WHERE u.username = 'sbx_v6_bsa_b'");
$setor($petugas, $sSmp, 40000);
$t11 = $tagihanBaru($sSmp, '2027-01', 30000);
$k11 = $kunci('l11-bt');
$bayarTab($petugas, $t11, 10000, $k11);
$m11 = $tab->setoran($petugas, $sSmp, ['idempotency_key' => $kunci('l11-tr'), 'nominal' => 5000, 'metode' => 'Transfer', 'referensi' => 'TRF-UJI-1101'], 'web');
$jalankan("UPDATE bendahara_bulanan_assignments SET jenjang = 'Tsanawi' WHERE id = $tugasB");
$sebelum = $jumlah();
$assert($tolak(static fn () => $bayarTab($petugas, $t11, 10000, $k11)) === 404 && $tolak(static fn () => $tab->verifikasiSetoran($petugas, $m11['id'], ['idempotency_key' => $kunci('x')], 'web')) === 404
    && $tolak(static fn () => $keluar($petugas, $sSmp, 1000)) === 404 && $tolak(static fn () => $tab->transaksi($petugas, $s1['id'])) === 404,
    'Cakupan dipersempit ke Tsanawi: replay bayar tabungan, verifikasi, belanja, kuitansi santri SMP → 404');
$jalankan("UPDATE bendahara_bulanan_assignments SET jenjang = NULL WHERE id = $tugasB");
$jalankan("UPDATE santri SET is_active = 0 WHERE id = $sSmp");
$assert($tolak(static fn () => $bayarTab($petugas, $t11, 10000, $k11)) === 404 && $tolak(static fn () => $setor($petugas, $sSmp, 1000)) === 404
    && $tolak(static fn () => $tab->koreksi($petugas, $kb1['id'], ['idempotency_key' => $kunci('x'), 'alasan' => 'Santri nonaktif'], 'web')) === 404, 'Santri dinonaktifkan: replay dan mutasi baru → 404');
$assert($tab->transaksi($admin, $s1['id'])['nominal'] === 100000, 'Admin tetap membaca kuitansi tabungan santri nonaktif');
$jalankan("UPDATE santri SET is_active = 1 WHERE id = $sSmp");
penugasan_service()->nonaktifkan(PenugasanJenis::BENDAHARA_BULANAN, $tugasB, 'Uji cabut Fase 3', $uid('sbx_admin'));
$assert($tolak(static fn () => $bayarTab($petugas, $t11, 1000)) === 403 && $tolak(static fn () => $setor($petugas, $sSmp, 1000)) === 403
    && $tolak(static fn () => $tab->verifikasiSetoran($petugas, $m11['id'], ['idempotency_key' => $kunci('x')], 'web')) === 403
    && $tolak(static fn () => $tab->rekonsiliasi($petugas, [])) === 403 && $tolak(static fn () => $tab->daftarMutasi($petugas, [])) === 403,
    'Penugasan dinonaktifkan saat sesi masih dipakai: bayar tabungan, setoran, verifikasi, rekonsiliasi, mutasi → 403');
$assert($jumlah() === $sebelum, 'Penolakan L-11 tanpa baris atau klaim baru');

// ===================================================================== L-12
echo PHP_EOL . '=== L-12. Verifier, audit, preservasi ===' . PHP_EOL;
$out = [];
exec('DB_NAME=' . escapeshellarg($dbName) . ' php ' . escapeshellarg(dirname(__DIR__) . '/bin/v6_phase3_verify.php') . ' 2>&1', $out, $kodeVerify);
$assert($kodeVerify === 0, 'Verifier Fase 1 + 2 + 3 lulus pada data uji (' . count(array_filter($out, static fn ($l) => str_contains($l, '[lulus]'))) . ' pemeriksaan)');
if ($kodeVerify !== 0) {
    echo implode(PHP_EOL, array_filter($out, static fn ($l) => str_contains($l, '[gagal]'))) . PHP_EOL;
}
$assert((int) $satu("SELECT COUNT(*) FROM audit_logs WHERE action = 'bsa.pembayaran.tabungan'") === (int) $satu("SELECT COUNT(*) FROM bsa_pembayaran WHERE sumber = 'Tabungan'")
    && (int) $satu("SELECT COUNT(*) FROM audit_logs WHERE action = 'bsa.tabungan.koreksi'") === (int) $satu("SELECT COUNT(*) FROM bsa_tabungan_transaksi WHERE jenis = 'Pembalik' AND metode <> 'Internal'")
    && (int) $satu("SELECT COUNT(*) FROM audit_logs WHERE action IN ('bsa.tabungan.verifikasi', 'bsa.tabungan.tolak')") === (int) $satu("SELECT COUNT(*) FROM bsa_setoran_transfer WHERE status <> 'Menunggu'"),
    'Setiap pembayaran dari tabungan, koreksi, dan keputusan setoran transfer punya satu jejak audit');
$assert((int) $satu("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'bsa.%' AND (after_json REGEXP '\"(nominal|saldo|saldo_sesudah|total|terbayar|sisa|referensi|alasan|catatan|tujuan|nama)\"' OR after_json LIKE '%TRF-UJI%')") === 0,
    'Audit bsa.* Fase 3 tanpa nominal, saldo, referensi, alasan, tujuan, atau nama');
$akhir = $preservasi();
foreach ($lindungi as $tb) {
    if ($awalLindungi[$tb] === null) {
        continue;
    }
    $assert($akhir[$tb] === $awalLindungi[$tb], "Jumlah baris $tb tetap {$awalLindungi[$tb]}");
}

echo PHP_EOL . "Hasil: $lulus lulus, " . count($gagal) . ' gagal' . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
