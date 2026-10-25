<?php

declare(strict_types=1);

/**
 * Uji integrasi layanan PRD V6 Fase 2 — tagihan bulanan lengkap — langsung
 * pada `TagihanService` dan MariaDB/MySQL uji.
 *
 *   bash tests/v6_phase2_db.sh
 *   V6_RUN_INTEGRATION=1 php tests/v6_phase2_integration.php
 *
 * Skenario (kode = kriteria penerimaan Fase 2, PRD V6 §6):
 *   J-1  matriks hak Fase 2 per akun (tarif, penyesuaian, pratinjau,
 *        tunggakan, pembayaran) — admin/wali tidak memutasi;
 *   J-2  tarif unit: cakupan unit petugas, unit tidak dikenal, alasan wajib
 *        untuk perubahan, mengganti periode mulai yang sama (lama Diganti,
 *        nominal lama tetap), kunci unik basis data satu tarif berlaku;
 *   J-3  penyesuaian per santri beralasan: validasi, cakupan, pencabutan;
 *   J-4  KRITERIA 1–2: pratinjau + penerbitan massal: tarif unit sama →
 *        tagihan sesuai santri sasaran; satu penyesuaian mengubah HANYA
 *        tagihan santri itu; menjalankan periode yang sama dua kali tidak
 *        membuat tagihan kedua atau mengubah snapshot; replay; hash basi →
 *        409 tanpa mutasi; santri tanpa kelas/nonaktif; jatuh tempo;
 *   J-5  KRITERIA 3: Rp40.000 lalu Rp60.000 atas Rp100.000 → sisa Rp0, dua
 *        pembayaran terverifikasi; pembayaran lebih dari sisa ditolak;
 *        transfer menunggu/verifikasi/tolak; idempotensi; pembatalan
 *        beralasan sebagai entri penyeimbang;
 *   J-6  KRITERIA 4: perubahan tarif setelah terbit tidak mengubah nominal,
 *        snapshot, atau kuitansi tagihan lama; revisi tagihan beralasan
 *        juga tidak mengubah kuitansi lama;
 *   J-7  revisi dan pembatalan tagihan: versi (optimistic), batas terbayar,
 *        riwayat versi, tidak ada tagihan kedua sesudah dibatalkan;
 *   J-8  konkurensi (proses terpisah): dua pembayaran Rp60.000 bersamaan
 *        atas sisa Rp100.000; delapan pembayaran Rp20.000; delapan kiriman
 *        kunci sama; dua verifikasi bersamaan; dua penerbitan massal
 *        bersamaan; revisi bersamaan dengan pembayaran;
 *   J-9  kegagalan transaksi (audit dipaksa gagal lewat trigger uji):
 *        pembayaran, verifikasi, pembatalan, dan penerbitan massal batal
 *        seluruhnya tanpa baris/klaim/kuitansi yatim;
 *   J-10 KRITERIA 5: isolasi wali dan lintas santri: tagihan, kuitansi,
 *        daftar pembayaran, tunggakan, ID ditebak; wali tanpa referensi,
 *        catatan, alasan, dan transfer menunggu;
 *   J-11 pencabutan penugasan pada sesi lama, cakupan dipersempit, santri
 *        nonaktif: mutasi dan replay ditolak tanpa baris baru;
 *   J-12 verifier Fase 1 + Fase 2 lulus; audit `bsa.*` tanpa data uang;
 *        preservasi tabel lain.
 *
 * Mengubah data uji; bangun ulang DB sebelum mengulang.
 */

if (getenv('V6_RUN_INTEGRATION') !== '1') {
    fwrite(STDERR, "Set V6_RUN_INTEGRATION=1 untuk menjalankan.\n");
    exit(2);
}
$dbName = getenv('V6F2_DB') ?: 'test_v6_f2_test';
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
$svc = tagihan_service();
$bsa = bsa_service();
$tolak = static function (callable $fn): ?int {
    try {
        $fn();
    } catch (BsaException $e) {
        return $e->status;
    }

    return null;
};
$kunci = static fn (string $awalan): string => $awalan . '-' . bin2hex(random_bytes(6));
$tabel = ['bsa_tarif', 'bsa_tarif_komponen', 'bsa_penyesuaian', 'bsa_penerbitan', 'bsa_penerbitan_santri', 'bsa_tagihan', 'bsa_tagihan_komponen',
    'bsa_tagihan_penyesuaian', 'bsa_tagihan_riwayat', 'bsa_pembayaran', 'bsa_pembayaran_kuitansi', 'bsa_pembayaran_pembatalan', 'bsa_idempotensi', 'bsa_tabungan_transaksi'];
$jumlah = static function () use ($tabel, $satu): array {
    $h = [];
    foreach ($tabel as $t) {
        $h[$t] = (int) $satu("SELECT COUNT(*) FROM `$t`");
    }
    $h['terbayar'] = (int) $satu('SELECT COALESCE(SUM(terbayar), 0) FROM bsa_tagihan');
    $h['total'] = (int) $satu('SELECT COALESCE(SUM(total), 0) FROM bsa_tagihan');
    $h['saldo'] = (int) $satu('SELECT COALESCE(SUM(saldo), 0) FROM bsa_tabungan_akun');

    return $h;
};
$tagihanRow = static fn (int $id): array => $db->query("SELECT id, santri_id, periode, total, terbayar, status, versi, jatuh_tempo FROM bsa_tagihan WHERE id = $id")->fetch_assoc() ?? [];
$sidikTagihan = static function (int $id) use ($db): string {
    $k = $db->query("SELECT urutan, nama, nominal FROM bsa_tagihan_komponen WHERE tagihan_id = $id ORDER BY urutan")->fetch_all(MYSQLI_ASSOC);
    $j = $db->query("SELECT versi, jenis, nama, nominal FROM bsa_tagihan_penyesuaian WHERE tagihan_id = $id ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    $t = $db->query("SELECT total, versi, jatuh_tempo, jenjang FROM bsa_tagihan WHERE id = $id")->fetch_assoc();

    return hash('sha256', json_encode([$k, $j, $t]));
};
$hariIni = date('Y-m-d');

$sSmp = $sid('V6S0001');
$sTsanawi = $sid('V6S0002');
$sTanpa = $sid('V6S0003');
$sNonaktif = $sid('V6S0004');
$assert(min($sSmp, $sTsanawi, $sTanpa, $sNonaktif) > 0, 'Fixture V6 tersedia (empat santri sintetis)');
$awal = $jumlah();
$assert(array_sum(array_intersect_key($awal, array_flip($tabel))) === 0, 'Tabel BSA Fase 1–2 mulai kosong (migrasi 027 dan 028 terpasang)');
$petugas = $akun('sbx_v6_bsa_b');
$petugasT = $akun('sbx_v6_bsa_tsanawi');
$admin = $akun('sbx_admin');
$waliSmp = $akun('sbx_v6_wali_smp');
$waliT = $akun('sbx_v6_wali_tsanawi');

$lindungi = ['santri', 'wali', 'santri_wali', 'users', 'pengurus', 'kelas', 'plotting_kelas', 'psb_pendaftar', 'psb_pembayaran', 'izin_pengajuan', 'kesehatan_kunjungan',
    'kesehatan_ringkasan', 'v3_pelanggaran', 'v4_rapor', 'bendahara_bulanan_assignments', 'kesehatan_assignments'];
$preservasi = static function () use ($lindungi, $satu): array {
    $hasil = [];
    foreach ($lindungi as $t) {
        $ada = (int) $satu("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$t'");
        $hasil[$t] = $ada === 1 ? (int) $satu("SELECT COUNT(*) FROM `$t`") : null;
    }

    return $hasil;
};
$awalLindungi = $preservasi();

// ===================================================================== J-1
echo PHP_EOL . '=== J-1. Matriks hak Fase 2 ===' . PHP_EOL;
foreach (['sbx_admin' => 'admin', 'sbx_v6_admin_bsa' => 'admin_petugas', 'sbx_v6_bsa_b' => 'petugas', 'sbx_v6_bsa_tsanawi' => 'petugas'] as $u => $sumber) {
    $d = $svc->daftarTarif($akun($u));
    $assert($d['akses']['sumber'] === $sumber && $tolak(static fn () => $svc->tunggakan($akun($u), [])) === null && $tolak(static fn () => $svc->daftarPembayaran($akun($u), [])) === null,
        "$u ($sumber): membaca tarif, tunggakan, dan pembayaran");
}
$assert($svc->daftarTarif($petugasT)['unit'] === ['Tsanawi'] && count($svc->daftarTarif($admin)['unit']) === 2, 'Pilihan unit petugas Tsanawi = [Tsanawi]; admin melihat seluruh unit kelas aktif');
foreach (['sbx_v6_wali_smp', 'sbx_ortu_a'] as $u) {
    $assert($tolak(static fn () => $svc->daftarTarif($akun($u))) === 403 && $tolak(static fn () => $svc->penyesuaianSantri($akun($u), $sSmp)) === 403
        && $tolak(static fn () => $svc->daftarPenerbitan($akun($u))) === 403, "$u: tarif, penyesuaian, penerbitan → 403 (hanya internal)");
}
foreach (['sbx_pengurus_biasa', 'sbx_guru_biasa', 'sbx_v5_petugas_b'] as $u) {
    $assert($tolak(static fn () => $svc->daftarTarif($akun($u))) === 403 && $tolak(static fn () => $svc->tunggakan($akun($u), [])) === 403
        && $tolak(static fn () => $svc->daftarPembayaran($akun($u), [])) === 403, "$u: seluruh bacaan Fase 2 → 403");
}
$tarifSmp = ['unit' => 'SMP', 'berlaku_mulai' => '2026-10', 'komponen' => [['nama' => 'Syahriah uji', 'nominal' => '300.000'], ['nama' => 'Makan uji', 'nominal' => 200000]]];
foreach (['sbx_admin' => 403, 'sbx_v6_admin_bsa' => 403, 'sbx_v6_wali_smp' => 403, 'sbx_pengurus_biasa' => 403, 'sbx_guru_biasa' => 403] as $u => $kode) {
    $assert($tolak(static fn () => $svc->buatTarif($akun($u), $tarifSmp + ['idempotency_key' => $kunci('j1')], 'web')) === $kode
        && $tolak(static fn () => $svc->pratinjau($akun($u), ['periode' => '2026-10', 'jatuh_tempo' => '2026-10-10'])) === $kode
        && $tolak(static fn () => $svc->buatPenyesuaian($akun($u), $sSmp, ['idempotency_key' => $kunci('j1'), 'jenis' => 'Potongan', 'nama' => 'Uji', 'nominal' => 1, 'berlaku_mulai' => '2026-10', 'alasan' => 'Uji hak'], 'web')) === $kode,
        "$u: tarif, pratinjau, penyesuaian (mutasi) → $kode");
}
$assert($jumlah() === $awal, 'Penolakan J-1 tanpa baris atau klaim idempotensi baru');

// ===================================================================== J-2
echo PHP_EOL . '=== J-2. Tarif unit/jenjang ===' . PHP_EOL;
$assert($tolak(static fn () => $svc->buatTarif($petugasT, $tarifSmp + ['idempotency_key' => $kunci('j2')], 'web')) === 404, 'Petugas Tsanawi menetapkan tarif SMP → 404 (di luar cakupan)');
$assert($tolak(static fn () => $svc->buatTarif($petugas, ['unit' => 'MI'] + $tarifSmp + ['idempotency_key' => $kunci('j2')], 'web')) === 422, 'Unit di luar kelas aktif ditolak 422');
foreach (['nol' => [['nama' => 'X', 'nominal' => '0']], 'pecahan' => [['nama' => 'X', 'nominal' => '1000,5']], 'float' => [['nama' => 'X', 'nominal' => 1000.0]], 'kosong' => [['nama' => '', 'nominal' => '']], 'tanpa nama' => [['nama' => '', 'nominal' => 5]]] as $l => $komp) {
    $assert($tolak(static fn () => $svc->buatTarif($petugas, ['komponen' => $komp] + $tarifSmp + ['idempotency_key' => $kunci('j2')], 'web')) === 422, "Komponen tarif $l ditolak 422");
}
$assert($tolak(static fn () => $svc->buatTarif($petugas, ['berlaku_mulai' => '2026-13'] + $tarifSmp + ['idempotency_key' => $kunci('j2')], 'web')) === 422, 'Periode mulai tidak valid ditolak');
$assert($jumlah() === $awal, 'Validasi tarif tanpa baris atau klaim baru');
$kT = $kunci('j2-smp');
$t1 = $svc->buatTarif($petugas, $tarifSmp + ['idempotency_key' => $kT, 'unit' => 'smp'], 'web');
$t1b = $svc->buatTarif($petugas, $tarifSmp + ['idempotency_key' => $kT, 'unit' => 'smp'], 'web');
$assert($t1['status'] === 201 && $t1b['replayed'] && $t1b['id'] === $t1['id'] && (int) $satu('SELECT COUNT(*) FROM bsa_tarif') === 1, 'Tarif SMP (unit dinormalisasi ke "SMP") tersimpan; kiriman ulang kunci sama → tarif yang sama');
$assert((string) $satu("SELECT jenjang FROM bsa_tarif WHERE id = {$t1['id']}") === 'SMP' && (int) $satu("SELECT total FROM bsa_tarif WHERE id = {$t1['id']}") === 500000, 'Tarif SMP Rp500.000 = Σ dua komponen');
$t2 = $svc->buatTarif($petugasT, ['unit' => 'Tsanawi', 'berlaku_mulai' => '2026-09', 'komponen' => [['nama' => 'Syahriah uji', 'nominal' => '250.000']]] + ['idempotency_key' => $kunci('j2-t')], 'web');
$assert($t2['status'] === 201, 'Petugas Tsanawi menetapkan tarif Tsanawi Rp250.000 mulai September 2026');
$assert($tolak(static fn () => $svc->buatTarif($petugas, ['berlaku_mulai' => '2026-12', 'komponen' => [['nama' => 'Syahriah uji', 'nominal' => 350000]]] + $tarifSmp + ['idempotency_key' => $kunci('j2')], 'web')) === 422,
    'Perubahan tarif unit yang sudah bertarif tanpa alasan ditolak');
$ganti = $svc->buatTarif($petugasT, ['unit' => 'Tsanawi', 'berlaku_mulai' => '2026-09', 'komponen' => [['nama' => 'Syahriah uji', 'nominal' => '200.000'], ['nama' => 'Kitab uji', 'nominal' => '50.000']],
    'alasan' => 'Rincian komponen dipisah', 'idempotency_key' => $kunci('j2-ganti')], 'api');
$lama = $db->query("SELECT status, total, diganti_oleh_id, diganti_pada FROM bsa_tarif WHERE id = {$t2['id']}")->fetch_assoc();
$assert($ganti['menggantikan_id'] === $t2['id'] && $lama['status'] === 'Diganti' && (int) $lama['total'] === 250000 && (int) $lama['diganti_oleh_id'] === $ganti['id'] && $lama['diganti_pada'] !== null,
    'Mengganti tarif periode mulai sama: lama Diganti (nominal tetap Rp250.000, menunjuk pengganti), baru Berlaku');
$assert((int) $satu("SELECT COUNT(*) FROM bsa_tarif_komponen WHERE tarif_id = {$t2['id']}") === 1, 'Komponen tarif lama tidak dihapus');
$dup = $db->query("INSERT INTO bsa_tarif (jenjang, berlaku_mulai, total, status, penugasan_id, pengurus_id, dibuat_oleh, idempotency_key)
    SELECT jenjang, berlaku_mulai, total, 'Berlaku', penugasan_id, pengurus_id, dibuat_oleh, 'uji-dup-langsung' FROM bsa_tarif WHERE id = {$t1['id']}");
$assert($dup === false && $db->errno === 1062, 'Kunci unik basis data menolak tarif Berlaku kedua untuk unit+periode mulai yang sama');
$assert((int) $satu("SELECT COUNT(*) FROM audit_logs WHERE action = 'bsa.tarif.buat'") === 3, 'Setiap tarif tersimpan punya satu jejak audit (3)');

// ===================================================================== J-3
echo PHP_EOL . '=== J-3. Penyesuaian per santri ===' . PHP_EOL;
$pjIsi = ['jenis' => 'Potongan', 'nama' => 'Potongan yatim uji', 'nominal' => '150.000', 'berlaku_mulai' => '2026-10', 'alasan' => 'Santri yatim (sintetis)'];
$sebelum = $jumlah();
foreach (['tanpa alasan' => ['alasan' => ''], 'alasan pendek' => ['alasan' => 'abc'], 'jenis lain' => ['jenis' => 'Diskon'], 'nominal nol' => ['nominal' => '0'], 'keterangan kosong' => ['nama' => ''],
    'sampai sebelum mulai' => ['berlaku_sampai' => '2026-09'], 'periode salah' => ['berlaku_mulai' => '10-2026']] as $l => $ubah) {
    $assert($tolak(static fn () => $svc->buatPenyesuaian($petugas, $sSmp, $ubah + $pjIsi + ['idempotency_key' => $kunci('j3')], 'web')) === 422, "Penyesuaian $l ditolak 422");
}
$assert($tolak(static fn () => $svc->buatPenyesuaian($petugasT, $sSmp, $pjIsi + ['idempotency_key' => $kunci('j3')], 'web')) === 404, 'Petugas Tsanawi atas santri SMP → 404');
$assert($tolak(static fn () => $svc->buatPenyesuaian($petugas, $sNonaktif, $pjIsi + ['idempotency_key' => $kunci('j3')], 'web')) === 404, 'Santri nonaktif → 404');
$assert($jumlah() === $sebelum, 'Penolakan penyesuaian tanpa baris baru');
$pj = $svc->buatPenyesuaian($petugas, $sSmp, $pjIsi + ['idempotency_key' => $kunci('j3-ok')], 'web');
$pjCabut = $svc->buatPenyesuaian($petugas, $sSmp, ['jenis' => 'Tambahan', 'nama' => 'Les tambahan uji', 'nominal' => 25000, 'berlaku_mulai' => '2026-10', 'alasan' => 'Akan dicabut di uji', 'idempotency_key' => $kunci('j3-c')], 'web');
$c = $svc->cabutPenyesuaian($petugas, $pjCabut['id'], ['alasan' => 'Les dibatalkan', 'idempotency_key' => $kunci('j3-cabut')], 'web');
$rowC = $db->query("SELECT status, dicabut_oleh, dicabut_pada, alasan_cabut, nominal FROM bsa_penyesuaian WHERE id = {$pjCabut['id']}")->fetch_assoc();
$assert($c['status'] === 200 && $rowC['status'] === 'Dicabut' && (int) $rowC['dicabut_oleh'] === $petugas['id'] && $rowC['alasan_cabut'] === 'Les dibatalkan' && (int) $rowC['nominal'] === 25000,
    'Pencabutan menyimpan aktor, waktu, dan alasan; baris tetap ada');
$assert($tolak(static fn () => $svc->cabutPenyesuaian($petugas, $pjCabut['id'], ['alasan' => 'Cabut lagi', 'idempotency_key' => $kunci('j3-lagi')], 'web')) === 409, 'Mencabut dua kali → 409');
$assert($tolak(static fn () => $svc->cabutPenyesuaian($petugasT, $pj['id'], ['alasan' => 'Bukan cakupan', 'idempotency_key' => $kunci('j3-x')], 'web')) === 404, 'Petugas lain unit mencabut → 404');
$lp = $svc->penyesuaianSantri($admin, $sSmp);
$assert(count($lp['rows']) === 2 && $lp['akses']['kelola'] === false, 'Admin membaca daftar penyesuaian santri (hanya baca)');

// ===================================================================== J-4
echo PHP_EOL . '=== J-4. Pratinjau dan penerbitan massal (kriteria 1–2) ===' . PHP_EOL;
$rencana = static fn (array $u, string $periode, ?string $unit = null): array => $svc->pratinjau($u, ['periode' => $periode, 'jatuh_tempo' => $periode . '-10', 'unit' => $unit]);
foreach (['2026-10-01x', '2026-09-30', '2027-01-02'] as $jt) {
    $assert($tolak(static fn () => $svc->pratinjau($petugas, ['periode' => '2026-10', 'jatuh_tempo' => $jt])) === 422, "Jatuh tempo $jt di luar awal periode..+3 bulan ditolak");
}
$assert($tolak(static fn () => $svc->pratinjau($petugasT, ['periode' => '2026-10', 'jatuh_tempo' => '2026-10-10', 'unit' => 'SMP'])) === 404, 'Petugas Tsanawi mempratinjau unit SMP → 404');
$pr = $rencana($petugas, '2026-10');
$sasaranSmp = (int) $satu("SELECT COUNT(*) FROM santri s JOIN plotting_kelas pk ON pk.id_santri = s.id AND pk.status = 'Aktif' AND pk.id_tahun = (SELECT id FROM tahun_ajaran WHERE status = 'Aktif' AND archived_at IS NULL ORDER BY id DESC LIMIT 1)
    JOIN kelas k ON k.id = pk.id_kelas WHERE s.is_active = 1 AND s.archived_at IS NULL AND k.jenjang = 'SMP'");
$sasaranT = (int) $satu("SELECT COUNT(*) FROM santri s JOIN plotting_kelas pk ON pk.id_santri = s.id AND pk.status = 'Aktif' AND pk.id_tahun = (SELECT id FROM tahun_ajaran WHERE status = 'Aktif' AND archived_at IS NULL ORDER BY id DESC LIMIT 1)
    JOIN kelas k ON k.id = pk.id_kelas WHERE s.is_active = 1 AND s.archived_at IS NULL AND k.jenjang = 'Tsanawi'");
$aktifSemua = (int) $satu('SELECT COUNT(*) FROM santri WHERE is_active = 1 AND archived_at IS NULL');
$baris = static fn (array $p, int $s): ?array => array_values(array_filter($p['rows'], static fn (array $r): bool => $r['santri_id'] === $s))[0] ?? null;
$assert(count($pr['rows']) === $aktifSemua && $pr['jumlah']['Terbit'] === $sasaranSmp + $sasaranT && $pr['jumlah']['Dilewati'] === $aktifSemua - $sasaranSmp - $sasaranT,
    "Pratinjau seluruh unit: $aktifSemua santri aktif → " . ($sasaranSmp + $sasaranT) . ' akan terbit (SMP ' . $sasaranSmp . ', Tsanawi ' . $sasaranT . '), sisanya dilewati');
$assert(($baris($pr, $sTanpa)['keterangan'] ?? '') === 'Belum ditempatkan di kelas' && $baris($pr, $sNonaktif) === null, 'Santri tanpa kelas dilewati beralasan; santri nonaktif bukan sasaran');
$smpLain = array_values(array_filter($pr['rows'], static fn (array $r): bool => $r['unit'] === 'SMP' && $r['santri_id'] !== $sSmp));
$tsLain = array_values(array_filter($pr['rows'], static fn (array $r): bool => $r['unit'] === 'Tsanawi'));
$assert($smpLain !== [] && array_unique(array_column($smpLain, 'total')) === [500000] && array_unique(array_column($smpLain, 'tarif_id')) === [$t1['id']],
    'Tarif unit SMP yang sama → setiap santri SMP lain Rp500.000 dari tarif SMP');
$assert($tsLain !== [] && array_unique(array_column($tsLain, 'total')) === [250000] && array_unique(array_column($tsLain, 'tarif_id')) === [$ganti['id']],
    'Santri Tsanawi memakai tarif Tsanawi yang berlaku (pengganti, mulai September berlaku untuk Oktober) Rp250.000');
$bSmp = $baris($pr, $sSmp);
$assert($bSmp['total'] === 350000 && count($bSmp['penyesuaian']) === 1 && $bSmp['penyesuaian'][0]['id'] === $pj['id'], 'Santri SMP berpenyesuaian: Rp500.000 − potongan Rp150.000 = Rp350.000 (penyesuaian dicabut tidak ikut)');
$prT = $rencana($petugasT, '2026-10');
$assert($prT['jumlah']['Terbit'] === $sasaranT && count($prT['rows']) === $sasaranT && array_unique(array_column($prT['rows'], 'unit')) === ['Tsanawi'], 'Pratinjau petugas Tsanawi hanya memuat santri Tsanawi');
$assert($jumlah()['bsa_tagihan'] === 0 && (int) $satu("SELECT COUNT(*) FROM bsa_idempotensi WHERE operasi = 'tagihan.massal'") === 0, 'Pratinjau tidak menulis tagihan atau klaim');
$assert($tolak(static fn () => $svc->terbitkanMassal($petugas, ['periode' => '2026-10', 'jatuh_tempo' => '2026-10-10', 'pratinjau_hash' => str_repeat('a', 64), 'idempotency_key' => $kunci('j4')], 'web')) === 409
    && $jumlah()['bsa_tagihan'] === 0, 'Hash pratinjau tidak cocok → 409 tanpa tagihan');
$kM = $kunci('j4-massal');
$m = $svc->terbitkanMassal($petugas, ['periode' => '2026-10', 'jatuh_tempo' => '2026-10-10', 'pratinjau_hash' => $pr['hash'], 'idempotency_key' => $kM], 'web');
$assert($m['status'] === 201 && $m['jumlah_terbit'] === $sasaranSmp + $sasaranT && $jumlah()['bsa_tagihan'] === $sasaranSmp + $sasaranT, 'Penerbitan massal: ' . $m['jumlah_terbit'] . ' tagihan terbit dalam satu transaksi');
$tagSmp = (int) $satu("SELECT id FROM bsa_tagihan WHERE santri_id = $sSmp AND periode = '2026-10'");
$assert((int) $satu("SELECT total FROM bsa_tagihan WHERE id = $tagSmp") === 350000 && (int) $satu("SELECT COUNT(*) FROM bsa_tagihan_penyesuaian WHERE tagihan_id = $tagSmp AND asal = 'Penyesuaian' AND penyesuaian_id = {$pj['id']}") === 1,
    'Tagihan santri berpenyesuaian Rp350.000 dengan satu baris penyesuaian tersalin');
$assert((int) $satu("SELECT COUNT(*) FROM bsa_tagihan_penyesuaian p JOIN bsa_tagihan t ON t.id = p.tagihan_id WHERE t.santri_id <> $sSmp") === 0
    && (int) $satu("SELECT COUNT(DISTINCT total) FROM bsa_tagihan t JOIN plotting_kelas pk ON pk.id_santri = t.santri_id AND pk.status = 'Aktif' JOIN kelas k ON k.id = pk.id_kelas WHERE k.jenjang = 'SMP' AND t.santri_id <> $sSmp AND t.periode = '2026-10'") === 1,
    'Penyesuaian hanya mengubah tagihan santri itu; tagihan SMP lain tetap seragam');
$assert((int) $satu("SELECT COUNT(*) FROM bsa_tagihan_komponen k JOIN bsa_tagihan t ON t.id = k.tagihan_id WHERE t.periode = '2026-10' AND t.jenjang = 'SMP'") === $sasaranSmp * 2
    && (int) $satu("SELECT COUNT(*) FROM bsa_tagihan_riwayat r JOIN bsa_tagihan t ON t.id = r.tagihan_id WHERE t.periode = '2026-10' AND r.versi = 1 AND r.tindakan = 'Terbit'") === $m['jumlah_terbit'],
    'Snapshot komponen tarif tersalin per tagihan; riwayat versi 1 Terbit per tagihan');
$assert((int) $satu("SELECT COUNT(*) FROM bsa_penerbitan_santri WHERE penerbitan_id = {$m['id']}") === $aktifSemua
    && (int) $satu("SELECT COUNT(*) FROM bsa_penerbitan_santri WHERE penerbitan_id = {$m['id']} AND hasil = 'Dilewati' AND keterangan = 'Belum ditempatkan di kelas'") === $aktifSemua - $sasaranSmp - $sasaranT,
    'Hasil per santri sasaran tercatat (terbit dan dilewati beserta alasannya)');
$assert($tagihanRow($tagSmp)['terbayar'] === '0' && (int) $satu("SELECT COUNT(*) FROM bsa_tabungan_transaksi") === 0, 'Tagihan terbit tidak memotong tabungan');
$sidikAwal = $sidikTagihan($tagSmp);
$snapshotSemua = (string) $satu("SELECT SHA2(GROUP_CONCAT(CONCAT_WS(':', t.id, t.total, t.versi, k.urutan, k.nominal) ORDER BY t.id, k.urutan), 256) FROM bsa_tagihan t JOIN bsa_tagihan_komponen k ON k.tagihan_id = t.id WHERE t.periode = '2026-10'");
$r2 = $svc->terbitkanMassal($petugas, ['periode' => '2026-10', 'jatuh_tempo' => '2026-10-10', 'pratinjau_hash' => $pr['hash'], 'idempotency_key' => $kM], 'web');
$assert($r2['replayed'] && $r2['id'] === $m['id'] && $jumlah()['bsa_tagihan'] === $m['jumlah_terbit'], 'Kiriman ulang kunci sama → penerbitan yang sama (replay), tanpa tagihan baru');
$pr2 = $rencana($petugas, '2026-10');
$assert($pr2['jumlah']['Terbit'] === 0 && $pr2['jumlah']['SudahAda'] === $m['jumlah_terbit'], 'Pratinjau ulang periode sama: seluruh sasaran "sudah ada"');
$sebelum = $jumlah();
$assert($tolak(static fn () => $svc->terbitkanMassal($petugas, ['periode' => '2026-10', 'jatuh_tempo' => '2026-10-15', 'pratinjau_hash' => $svc->pratinjau($petugas, ['periode' => '2026-10', 'jatuh_tempo' => '2026-10-15'])['hash'], 'idempotency_key' => $kunci('j4-ulang')], 'web')) === 422,
    'Menjalankan penerbitan periode yang sama kedua kali (kunci lain) → 422 "tidak ada tagihan baru"');
$assert($tolak(static fn () => $svc->terbitkanMassal($petugasT, ['periode' => '2026-10', 'jatuh_tempo' => '2026-10-10', 'pratinjau_hash' => $prT['hash'], 'idempotency_key' => $kunci('j4-t')], 'web')) === 409,
    'Pratinjau basi (petugas Tsanawi, sebelum penerbitan) → 409');
$assert($jumlah() === $sebelum && $sidikTagihan($tagSmp) === $sidikAwal
    && (string) $satu("SELECT SHA2(GROUP_CONCAT(CONCAT_WS(':', t.id, t.total, t.versi, k.urutan, k.nominal) ORDER BY t.id, k.urutan), 256) FROM bsa_tagihan t JOIN bsa_tagihan_komponen k ON k.tagihan_id = t.id WHERE t.periode = '2026-10'") === $snapshotSemua,
    'Penerbitan ulang tidak membuat tagihan kedua dan tidak mengubah snapshot tagihan pertama');
$assert($tolak(static fn () => $bsa->terbitkanTagihan($petugas, ['idempotency_key' => $kunci('j4-manual'), 'santri_id' => $sSmp, 'periode' => '2026-10', 'jatuh_tempo' => '2026-10-10', 'komponen' => [['nama' => 'X', 'nominal' => 1]]], 'web')) === 409,
    'Tagihan manual Fase 1 untuk santri+periode yang sudah terbit massal → 409');

// ===================================================================== J-5
echo PHP_EOL . '=== J-5. Pembayaran tunai/transfer, sebagian, berlebih (kriteria 3) ===' . PHP_EOL;
$m100 = $bsa->terbitkanTagihan($petugas, ['idempotency_key' => $kunci('j5-tagihan'), 'santri_id' => $sTanpa, 'periode' => '2026-10', 'jatuh_tempo' => '2026-10-10',
    'komponen' => [['nama' => 'Syahriah uji', 'nominal' => '100.000']]], 'web');
$tag100 = $m100['id'];
$assert((int) $satu("SELECT COUNT(*) FROM bsa_tagihan_riwayat WHERE tagihan_id = $tag100 AND versi = 1 AND tindakan = 'Terbit'") === 1, 'Tagihan manual Fase 1 Rp100.000 juga punya riwayat versi 1 sesudah 028');
$bayar = static fn (array $u, int $t, mixed $nominal, array $tambah = []) => $svc->catatPembayaran($u, $t, $tambah + ['idempotency_key' => $kunci('bayar'), 'nominal' => $nominal, 'sumber' => 'Tunai', 'tanggal_transaksi' => $hariIni], 'web');
$sebelum = $jumlah();
foreach (['nol' => '0', 'negatif' => '-1', 'pecahan' => '40000,5', 'float' => 40000.0, 'notasi ilmiah' => '4e4', 'teks' => 'empat puluh ribu'] as $l => $n) {
    $assert($tolak(static fn () => $bayar($petugas, $tag100, $n)) === 422, "Nominal pembayaran $l ditolak 422");
}
$assert($tolak(static fn () => $bayar($petugas, $tag100, 1000, ['sumber' => 'Cek'])) === 422 && $tolak(static fn () => $bayar($petugas, $tag100, 1000, ['sumber' => 'Transfer'])) === 422,
    'Cara selain Tunai/Transfer dan transfer tanpa referensi ditolak');
$assert($tolak(static fn () => $bayar($petugas, $tag100, 1000, ['tanggal_transaksi' => date('Y-m-d', strtotime('+1 day'))])) === 422, 'Tanggal pembayaran di masa depan ditolak');
$assert($tolak(static fn () => $bayar($petugas, $tag100, '100.001')) === 422, 'Pembayaran Rp100.001 atas sisa Rp100.000 → 422');
$assert($jumlah() === $sebelum, 'Penolakan pembayaran tanpa baris, kuitansi, atau klaim baru');
$kB = $kunci('j5-40');
$b40 = $svc->catatPembayaran($petugas, $tag100, ['idempotency_key' => $kB, 'nominal' => '40.000', 'sumber' => 'Tunai', 'tanggal_transaksi' => $hariIni], 'web');
$b40r = $svc->catatPembayaran($petugas, $tag100, ['idempotency_key' => $kB, 'nominal' => '40.000', 'sumber' => 'Tunai', 'tanggal_transaksi' => $hariIni], 'web');
$assert($b40['status'] === 201 && $b40['sisa'] === 60000 && $b40r['replayed'] && $b40r['id'] === $b40['id'] && $b40r['nomor_kuitansi'] === $b40['nomor_kuitansi'],
    'Pembayaran Rp40.000 → sisa Rp60.000; kiriman ulang kunci sama → pembayaran dan kuitansi yang sama');
$assert($tolak(static fn () => $svc->catatPembayaran($petugas, $tag100, ['idempotency_key' => $kB, 'nominal' => '50.000', 'sumber' => 'Tunai', 'tanggal_transaksi' => $hariIni], 'web')) === 409,
    'Kunci sama nominal berbeda → 409');
$assert($tolak(static fn () => $bayar($petugas, $tag100, 60001)) === 422, 'Rp60.001 atas sisa Rp60.000 → 422 (pembayaran berlebih ditolak)');
$b60 = $bayar($petugas, $tag100, 60000);
$t = $svc->rincianTagihan($petugas, $tag100);
$assert($b60['sisa'] === 0 && $t['sisa'] === 0 && $t['terbayar'] === 100000 && $t['status_bayar'] === 'Lunas', 'Pembayaran Rp60.000 → sisa Rp0, terbayar Rp100.000, status Lunas');
$sah = array_values(array_filter($t['pembayaran'], static fn (array $b): bool => $b['status'] === 'Sah'));
$assert(count($sah) === 2 && $sah[0]['verifikasi'] !== null && $sah[1]['verifikasi'] !== null && $sah[0]['kuitansi']['sisa_sesudah'] === 60000 && $sah[1]['kuitansi']['sisa_sesudah'] === 0,
    'Dua transaksi terverifikasi dengan kuitansi (sisa sesudah Rp60.000 lalu Rp0)');
$assert(preg_match('/^BSA-KW-\d{4}-\d{6}$/', $b40['nomor_kuitansi']) === 1 && $b40['nomor_kuitansi'] !== $b60['nomor_kuitansi'], 'Nomor kuitansi unik berformat BSA-KW-TTTT-NNNNNN');
$assert($tolak(static fn () => $bayar($petugas, $tag100, 1)) === 422, 'Tagihan lunas: pembayaran Rp1 pun ditolak');
// Transfer menunggu dan verifikasi pada tagihan SMP Rp350.000.
$tr = $svc->catatPembayaran($petugas, $tagSmp, ['idempotency_key' => $kunci('j5-tr'), 'nominal' => '300.000', 'sumber' => 'Transfer', 'referensi' => 'TRF-UJI-F2-1', 'tanggal_transaksi' => $hariIni], 'api');
$assert($tr['status_pembayaran'] === 'Menunggu' && $tagihanRow($tagSmp)['terbayar'] === '0' && (int) $satu("SELECT COUNT(*) FROM bsa_pembayaran_kuitansi WHERE pembayaran_id = {$tr['id']}") === 0,
    'Transfer tanpa pernyataan verifikasi → Menunggu, belum mengurangi sisa, belum berkuitansi');
$assert($tolak(static fn () => $bayar($petugas, $tagSmp, 50001)) === 422, 'Pembayaran baru melebihi sisa dikurangi transfer menunggu (Rp50.000) → 422');
$assert($tolak(static fn () => $svc->verifikasiPembayaran($admin, $tr['id'], ['idempotency_key' => $kunci('v')], 'web')) === 403 && $tolak(static fn () => $svc->verifikasiPembayaran($waliSmp, $tr['id'], ['idempotency_key' => $kunci('v')], 'web')) === 403,
    'Admin dan wali tidak dapat memverifikasi (403)');
$v = $svc->verifikasiPembayaran($akun('sbx_v6_bsa_lama'), $tr['id'], ['idempotency_key' => $kunci('j5-v')], 'web');
$assert($v['status_pembayaran'] === 'Sah' && $v['sisa'] === 50000 && (int) $satu("SELECT diverifikasi_oleh FROM bsa_pembayaran WHERE id = {$tr['id']}") === $uid('sbx_v6_bsa_lama')
    && (int) $satu("SELECT dicatat_oleh FROM bsa_pembayaran WHERE id = {$tr['id']}") === $petugas['id'], 'Verifikasi oleh petugas BSA lain: Sah, sisa Rp50.000, pencatat dan verifikator tercatat terpisah');
$assert($tolak(static fn () => $svc->verifikasiPembayaran($petugas, $tr['id'], ['idempotency_key' => $kunci('j5-v2')], 'web')) === 409, 'Verifikasi kedua (kunci lain) → 409');
$tr2 = $svc->catatPembayaran($petugas, $tagSmp, ['idempotency_key' => $kunci('j5-tr2'), 'nominal' => 50000, 'sumber' => 'Transfer', 'referensi' => 'TRF-UJI-F2-2', 'tanggal_transaksi' => $hariIni, 'terverifikasi' => '1'], 'api');
$assert($tr2['status_pembayaran'] === 'Sah' && $tr2['sisa'] === 0, 'Transfer dengan pernyataan dana sudah diperiksa → Sah seketika, sisa Rp0');
// Pembatalan beralasan sebagai entri penyeimbang.
$sebelumBatal = $db->query("SELECT nominal, nomor_kuitansi FROM bsa_pembayaran WHERE id = {$tr2['id']}")->fetch_assoc();
$assert($tolak(static fn () => $svc->batalkanPembayaran($petugas, $tr2['id'], ['alasan' => 'abc', 'idempotency_key' => $kunci('x')], 'web')) === 422, 'Pembatalan tanpa alasan memadai ditolak');
$x = $svc->batalkanPembayaran($petugas, $tr2['id'], ['alasan' => 'Transfer ternyata ganda di rekening', 'idempotency_key' => $kunci('j5-x')], 'web');
$sesudahBatal = $db->query("SELECT p.nominal, p.nomor_kuitansi, p.status, x.jenis, x.status_sebelum, x.alasan, x.dibatalkan_oleh FROM bsa_pembayaran p JOIN bsa_pembayaran_pembatalan x ON x.pembayaran_id = p.id WHERE p.id = {$tr2['id']}")->fetch_assoc();
$assert($x['sisa'] === 50000 && $tagihanRow($tagSmp)['terbayar'] === '300000' && $sesudahBatal['status'] === 'Dibatalkan' && $sesudahBatal['nominal'] === $sebelumBatal['nominal']
    && $sesudahBatal['nomor_kuitansi'] === $sebelumBatal['nomor_kuitansi'] && $sesudahBatal['jenis'] === 'Pembatalan' && (int) $sesudahBatal['dibatalkan_oleh'] === $petugas['id'],
    'Pembatalan: sisa kembali Rp50.000; nominal dan nomor kuitansi tidak diubah; entri penyeimbang beralasan + aktor');
$assert((int) $satu("SELECT COUNT(*) FROM bsa_pembayaran_kuitansi WHERE pembayaran_id = {$tr2['id']}") === 1, 'Kuitansi pembayaran yang dibatalkan tetap tersimpan (riwayat tidak dihapus)');
$tr3 = $svc->catatPembayaran($petugas, $tagSmp, ['idempotency_key' => $kunci('j5-tr3'), 'nominal' => 50000, 'sumber' => 'Transfer', 'referensi' => 'TRF-UJI-F2-3', 'tanggal_transaksi' => $hariIni], 'api');
$tolakTr = $svc->batalkanPembayaran($petugas, $tr3['id'], ['alasan' => 'Dana tidak ditemukan di rekening', 'idempotency_key' => $kunci('j5-tolak')], 'web');
$assert($tolakTr['status_pembayaran'] === 'Dibatalkan' && (string) $satu("SELECT jenis FROM bsa_pembayaran_pembatalan WHERE pembayaran_id = {$tr3['id']}") === 'Penolakan'
    && $tagihanRow($tagSmp)['terbayar'] === '300000', 'Transfer menunggu ditolak → Penolakan beralasan; terbayar tidak berubah');
$assert($tolak(static fn () => $svc->batalkanPembayaran($petugas, $tr3['id'], ['alasan' => 'Tolak lagi saja', 'idempotency_key' => $kunci('j5-lagi')], 'web')) === 409, 'Membatalkan pembayaran yang sudah dibatalkan → 409');

// ===================================================================== J-6
echo PHP_EOL . '=== J-6. Perubahan tarif sesudah terbit (kriteria 4) ===' . PHP_EOL;
$kuitansiAwal = $db->query("SELECT pembayaran_id, tagihan_versi, total_tagihan, terbayar_sebelum, sisa_sesudah FROM bsa_pembayaran_kuitansi ORDER BY pembayaran_id")->fetch_all(MYSQLI_ASSOC);
$tagihanAwal = $db->query("SELECT id, total, terbayar, versi, jatuh_tempo FROM bsa_tagihan WHERE periode = '2026-10' ORDER BY id")->fetch_all(MYSQLI_ASSOC);
$komponenAwal = (string) $satu("SELECT SHA2(GROUP_CONCAT(CONCAT_WS(':', tagihan_id, urutan, nama, nominal) ORDER BY id), 256) FROM bsa_tagihan_komponen");
$naik = $svc->buatTarif($petugas, ['unit' => 'SMP', 'berlaku_mulai' => '2026-10', 'komponen' => [['nama' => 'Syahriah uji', 'nominal' => '400.000'], ['nama' => 'Makan uji', 'nominal' => 250000]],
    'alasan' => 'Penyesuaian tarif Oktober (uji)', 'idempotency_key' => $kunci('j6-ganti')], 'web');
$baru = $svc->buatTarif($petugas, ['unit' => 'SMP', 'berlaku_mulai' => '2026-11', 'komponen' => [['nama' => 'Syahriah uji', 'nominal' => '450.000']], 'alasan' => 'Tarif baru mulai November', 'idempotency_key' => $kunci('j6-nov')], 'web');
$assert($naik['menggantikan_id'] === $t1['id'] && $baru['menggantikan_id'] === null, 'Tarif SMP Oktober diganti (Rp650.000) dan tarif November baru (Rp450.000)');
$assert($db->query("SELECT id, total, terbayar, versi, jatuh_tempo FROM bsa_tagihan WHERE periode = '2026-10' ORDER BY id")->fetch_all(MYSQLI_ASSOC) === $tagihanAwal
    && (string) $satu("SELECT SHA2(GROUP_CONCAT(CONCAT_WS(':', tagihan_id, urutan, nama, nominal) ORDER BY id), 256) FROM bsa_tagihan_komponen") === $komponenAwal,
    'Nominal, versi, dan snapshot komponen seluruh tagihan Oktober tidak berubah');
$assert($db->query("SELECT pembayaran_id, tagihan_versi, total_tagihan, terbayar_sebelum, sisa_sesudah FROM bsa_pembayaran_kuitansi ORDER BY pembayaran_id")->fetch_all(MYSQLI_ASSOC) === $kuitansiAwal,
    'Kuitansi tagihan lama tidak berubah');
$k40 = $svc->pembayaran($petugas, $b40['id']);
$assert($k40['kuitansi'] === ['tagihan_versi' => 1, 'total_tagihan' => 100000, 'terbayar_sebelum' => 0, 'sisa_sesudah' => 60000] && $k40['nominal'] === 40000, 'Data kuitansi Rp40.000 tetap (total Rp100.000, sisa sesudah Rp60.000)');
$prOkt = $rencana($petugas, '2026-10');
$prNov = $rencana($petugas, '2026-11');
$assert($prOkt['jumlah']['Terbit'] === 0 && ($baris($prNov, $sSmp)['total'] ?? 0) === 300000 && ($baris($prNov, $sSmp)['tarif_id'] ?? 0) === $baru['id']
    && array_unique(array_column(array_filter($prNov['rows'], static fn (array $r): bool => $r['unit'] === 'SMP' && $r['santri_id'] !== $sSmp), 'total')) === [450000],
    'Periode November memakai tarif baru (Rp450.000; santri berpenyesuaian Rp300.000); Oktober tetap tidak menerbitkan apa pun');

// ===================================================================== J-7
echo PHP_EOL . '=== J-7. Revisi dan pembatalan tagihan ===' . PHP_EOL;
$tagT = (int) $satu("SELECT id FROM bsa_tagihan WHERE santri_id = $sTsanawi AND periode = '2026-10'");
$rt = $tagihanRow($tagT);
$assert((int) $rt['total'] === 250000 && (int) $rt['versi'] === 1, 'Tagihan Tsanawi Oktober Rp250.000 versi 1');
$assert($tolak(static fn () => $svc->revisiTagihan($petugasT, $tagT, ['versi' => 2, 'jenis' => 'Potongan', 'nominal' => 10000, 'alasan' => 'Versi salah', 'idempotency_key' => $kunci('j7')], 'web')) === 409, 'Revisi dengan versi basi → 409');
$assert($tolak(static fn () => $svc->revisiTagihan($petugasT, $tagT, ['versi' => 1, 'jenis' => 'Potongan', 'nominal' => 10000, 'alasan' => '', 'idempotency_key' => $kunci('j7')], 'web')) === 422, 'Revisi tanpa alasan → 422');
$assert($tolak(static fn () => $svc->revisiTagihan($petugasT, $tagT, ['versi' => 1, 'jenis' => 'Potongan', 'nominal' => 250000, 'alasan' => 'Hapus semua', 'idempotency_key' => $kunci('j7')], 'web')) === 422, 'Revisi yang membuat total Rp0 → 422 (gunakan pembatalan)');
$assert($tolak(static fn () => $svc->revisiTagihan($petugasT, $tagT, ['versi' => 1, 'alasan' => 'Tidak ada isi', 'idempotency_key' => $kunci('j7')], 'web')) === 422, 'Revisi tanpa perubahan → 422');
$assert($tolak(static fn () => $svc->revisiTagihan($admin, $tagT, ['versi' => 1, 'jenis' => 'Potongan', 'nominal' => 1, 'alasan' => 'Admin coba', 'idempotency_key' => $kunci('j7')], 'web')) === 403, 'Admin merevisi → 403');
$assert($tolak(static fn () => $svc->revisiTagihan($petugasT, $tagSmp, ['versi' => 1, 'jenis' => 'Potongan', 'nominal' => 1, 'alasan' => 'Unit lain', 'idempotency_key' => $kunci('j7')], 'web')) === 404, 'Petugas Tsanawi merevisi tagihan SMP → 404');
$bT = $bayar($petugasT, $tagT, 100000);
$assert($tolak(static fn () => $svc->revisiTagihan($petugasT, $tagT, ['versi' => 1, 'jenis' => 'Potongan', 'nominal' => 160000, 'alasan' => 'Di bawah terbayar', 'idempotency_key' => $kunci('j7')], 'web')) === 422,
    'Revisi ke total di bawah terbayar (Rp100.000) → 422');
$rv = $svc->revisiTagihan($petugasT, $tagT, ['versi' => 1, 'jenis' => 'Potongan', 'nama' => 'Koreksi kitab', 'nominal' => '50.000', 'jatuh_tempo' => '2026-10-20', 'alasan' => 'Kitab sudah dimiliki santri', 'idempotency_key' => $kunci('j7-rv')], 'web');
$rt = $tagihanRow($tagT);
$assert($rv['versi'] === 2 && (int) $rt['total'] === 200000 && $rt['jatuh_tempo'] === '2026-10-20' && (int) $rt['terbayar'] === 100000, 'Revisi: versi 2, total Rp200.000, jatuh tempo 20 Oktober, terbayar tetap');
$assert((int) $satu("SELECT COUNT(*) FROM bsa_tagihan_komponen WHERE tagihan_id = $tagT") === 2 && (int) $satu("SELECT COUNT(*) FROM bsa_tagihan_penyesuaian WHERE tagihan_id = $tagT AND asal = 'Revisi' AND versi = 2 AND jenis = 'Potongan' AND nominal = 50000") === 1,
    'Komponen semula tetap; revisi tercatat sebagai baris potongan versi 2');
$riw = $svc->rincianTagihan($petugasT, $tagT)['riwayat'];
$assert(count($riw) === 2 && $riw[1]['tindakan'] === 'Revisi' && $riw[1]['alasan'] === 'Kitab sudah dimiliki santri' && $riw[1]['total'] === 200000, 'Riwayat versi menyimpan revisi beralasan dengan aktor');
$assert((int) $satu("SELECT sisa_sesudah FROM bsa_pembayaran_kuitansi WHERE pembayaran_id = {$bT['id']}") === 150000, 'Kuitansi sebelum revisi tetap menyimpan sisa sesudah Rp150.000 (keadaan saat dibayar)');
$assert($tolak(static fn () => $svc->batalkanTagihan($petugasT, $tagT, ['versi' => 2, 'alasan' => 'Santri pindah', 'idempotency_key' => $kunci('j7')], 'web')) === 422, 'Tagihan berpembayaran tidak dapat dibatalkan → 422');
$tagLain = (int) $satu("SELECT t.id FROM bsa_tagihan t WHERE t.periode = '2026-10' AND t.jenjang = 'Tsanawi' AND t.santri_id <> $sTsanawi ORDER BY t.id LIMIT 1");
$bt = $svc->batalkanTagihan($petugasT, $tagLain, ['versi' => 1, 'alasan' => 'Santri pindah pesantren (uji)', 'idempotency_key' => $kunci('j7-batal')], 'web');
$rl = $tagihanRow($tagLain);
$assert($bt['versi'] === 2 && $rl['status'] === 'Dibatalkan' && (int) $rl['total'] === 250000 && (int) $satu("SELECT COUNT(*) FROM bsa_tagihan_riwayat WHERE tagihan_id = $tagLain AND tindakan = 'Dibatalkan' AND versi = 2") === 1,
    'Pembatalan tagihan tanpa pembayaran: status Dibatalkan, nominal tetap, riwayat versi 2 beralasan');
$assert($tolak(static fn () => $bayar($petugasT, $tagLain, 1000)) === 422, 'Pembayaran atas tagihan dibatalkan → 422');
$assert(($baris($rencana($petugas, '2026-10'), (int) $rl['santri_id'])['hasil'] ?? '') === 'SudahAda', 'Santri dengan tagihan dibatalkan tidak mendapat tagihan kedua periode itu');

// ===================================================================== J-8
echo PHP_EOL . '=== J-8. Konkurensi (proses terpisah) ===' . PHP_EOL;
$scratch = sys_get_temp_dir() . '/v6f2-' . bin2hex(random_bytes(4));
mkdir($scratch);
$serbu = static function (array $permintaan) use ($scratch, $dbName): array {
    $aba = $scratch . '/mulai-' . bin2hex(random_bytes(3));
    $proc = [];
    foreach ($permintaan as $i => $p) {
        $h = proc_open(array_merge(['php', dirname(__DIR__) . '/tests/v6_phase2_worker.php', $aba], array_map('strval', $p)),
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
$baruTagihan = static function (int $santri, string $periode, int $nominal) use ($bsa, $petugas, $kunci): int {
    return $bsa->terbitkanTagihan($petugas, ['idempotency_key' => $kunci('j8-t'), 'santri_id' => $santri, 'periode' => $periode, 'jatuh_tempo' => $periode . '-10', 'komponen' => [['nama' => 'Uji konkurensi', 'nominal' => $nominal]]], 'web')['id'];
};
$pB = $petugas['id'];
$pL = $uid('sbx_v6_bsa_lama');
$t8 = $baruTagihan($sTanpa, '2027-01', 100000);
$hasil = $serbu([[$pB, 'bayar', $t8, $kunci('c1'), 60000], [$pL, 'bayar', $t8, $kunci('c2'), 60000]]);
$sukses = count(array_filter($hasil, static fn ($r) => $r['ok']));
$assert($sukses === 1 && count(array_filter($hasil, static fn ($r) => !$r['ok'] && $r['status'] === 422)) === 1 && (int) $tagihanRow($t8)['terbayar'] === 60000,
    'Dua pembayaran Rp60.000 bersamaan atas sisa Rp100.000 → tepat satu sukses, satu 422, terbayar Rp60.000 (' . json_encode($hasil) . ')');
$t8b = $baruTagihan($sTanpa, '2027-02', 100000);
$hasil = $serbu(array_map(static fn ($i) => [$i % 2 ? $pB : $pL, 'bayar', $t8b, $kunci('c8-' . $i), 20000], range(1, 8)));
$sukses = count(array_filter($hasil, static fn ($r) => $r['ok']));
$sisaList = array_map(static fn ($r) => $r['sisa'], array_filter($hasil, static fn ($r) => $r['ok']));
$assert($sukses === 5 && (int) $tagihanRow($t8b)['terbayar'] === 100000 && count(array_unique($sisaList)) === 5 && count(array_filter($hasil, static fn ($r) => !$r['ok'] && $r['status'] !== 422)) === 0,
    'Delapan pembayaran Rp20.000 bersamaan atas Rp100.000 → lima sukses berurutan (sisa berbeda), tiga 422 bisnis, tanpa galat DB (' . json_encode($hasil) . ')');
$t8c = $baruTagihan($sTanpa, '2027-03', 100000);
$kSama = $kunci('c-sama');
$hasil = $serbu(array_fill(0, 8, [$pB, 'bayar', $t8c, $kSama, 30000]));
$ids = array_unique(array_column(array_filter($hasil, static fn ($r) => $r['ok']), 'id'));
$assert(count($ids) === 1 && count(array_filter($hasil, static fn ($r) => $r['ok'] && !$r['replayed'])) === 1 && (int) $satu("SELECT COUNT(*) FROM bsa_pembayaran WHERE tagihan_id = $t8c") === 1
    && (int) $tagihanRow($t8c)['terbayar'] === 30000, 'Delapan kiriman bersamaan kunci+isi sama → satu pembayaran Rp30.000, tujuh replay (' . json_encode($hasil) . ')');
$m8 = $svc->catatPembayaran($petugas, $t8c, ['idempotency_key' => $kunci('c-tr'), 'nominal' => 70000, 'sumber' => 'Transfer', 'referensi' => 'TRF-UJI-C', 'tanggal_transaksi' => $hariIni], 'api');
$hasil = $serbu([[$pB, 'verifikasi', $m8['id'], $kunci('cv1')], [$pL, 'verifikasi', $m8['id'], $kunci('cv2')]]);
$assert(count(array_filter($hasil, static fn ($r) => $r['ok'])) === 1 && count(array_filter($hasil, static fn ($r) => !$r['ok'] && $r['status'] === 409)) === 1 && (int) $tagihanRow($t8c)['terbayar'] === 100000
    && (int) $satu("SELECT COUNT(*) FROM bsa_pembayaran_kuitansi WHERE pembayaran_id = {$m8['id']}") === 1, 'Dua verifikasi bersamaan atas transfer yang sama → satu sukses, satu 409, satu kuitansi (' . json_encode($hasil) . ')');
$hasil = $serbu([[$pB, 'batal', $sah[0]['id'], $kunci('cb1')], [$pL, 'batal', $sah[0]['id'], $kunci('cb2')]]);
$assert(count(array_filter($hasil, static fn ($r) => $r['ok'])) === 1 && (int) $tagihanRow($tag100)['terbayar'] === 60000 && (int) $satu("SELECT COUNT(*) FROM bsa_pembayaran_pembatalan WHERE pembayaran_id = {$sah[0]['id']}") === 1,
    'Dua pembatalan bersamaan atas pembayaran yang sama → satu pembatalan, terbayar turun sekali (' . json_encode($hasil) . ')');
// Dua penerbitan massal bersamaan untuk periode yang sama (dua petugas seluruh unit).
$prA = $rencana($petugas, '2026-12');
$prB = $rencana($akun('sbx_v6_bsa_lama'), '2026-12');
$sebelumT = (int) $satu('SELECT COUNT(*) FROM bsa_tagihan');
$hasil = $serbu([[$pB, 'massal', '2026-12', '2026-12-10', $prA['hash'], $kunci('cm1')], [$pL, 'massal', '2026-12', '2026-12-10', $prB['hash'], $kunci('cm2')]]);
$assert($prA['hash'] === $prB['hash'] && count(array_filter($hasil, static fn ($r) => $r['ok'])) === 1 && count(array_filter($hasil, static fn ($r) => !$r['ok'] && in_array($r['status'], [409, 422], true))) === 1
    && (int) $satu('SELECT COUNT(*) FROM bsa_tagihan') === $sebelumT + $prA['jumlah']['Terbit'] && (int) $satu("SELECT COUNT(*) FROM bsa_penerbitan WHERE periode = '2026-12'") === 1,
    'Dua penerbitan massal bersamaan periode sama → satu penerbitan, tanpa tagihan ganda (' . json_encode($hasil) . ')');
$assert((int) $satu('SELECT COUNT(*) FROM (SELECT santri_id, periode FROM bsa_tagihan GROUP BY santri_id, periode HAVING COUNT(*) > 1) g') === 0, 'Tidak ada tagihan ganda per santri+periode');
$t8d = $baruTagihan($sTanpa, '2027-04', 100000);
$hasil = $serbu([[$pB, 'bayar', $t8d, $kunci('cr1'), 80000], [$pL, 'revisi', $t8d, 1, $kunci('cr2'), 30000]]);
$rd = $tagihanRow($t8d);
$assert((int) $rd['terbayar'] <= (int) $rd['total'] && count(array_filter($hasil, static fn ($r) => !$r['ok'] && !in_array($r['status'], [409, 422], true))) === 0,
    'Pembayaran Rp80.000 dan revisi −Rp30.000 bersamaan → terbayar ≤ total (Rp' . $rd['terbayar'] . ' ≤ Rp' . $rd['total'] . '), penolakan hanya 409/422 (' . json_encode($hasil) . ')');
array_map('unlink', glob($scratch . '/*') ?: []);
rmdir($scratch);

// ===================================================================== J-9
echo PHP_EOL . '=== J-9. Kegagalan transaksi di tengah operasi ===' . PHP_EOL;
$t9 = $baruTagihan($sTanpa, '2027-05', 100000);
$b9 = $svc->catatPembayaran($petugas, $t9, ['idempotency_key' => $kunci('j9-tr'), 'nominal' => 40000, 'sumber' => 'Transfer', 'referensi' => 'TRF-UJI-9', 'tanggal_transaksi' => $hariIni], 'api');
$b9s = $bayar($petugas, $t9, 10000);
$jalankan('DROP TRIGGER IF EXISTS uji_v6f2_audit_gagal');
$jalankan("CREATE TRIGGER uji_v6f2_audit_gagal BEFORE INSERT ON audit_logs FOR EACH ROW BEGIN
    IF NEW.action IN ('bsa.pembayaran.catat', 'bsa.pembayaran.verifikasi', 'bsa.pembayaran.batal', 'bsa.tagihan.massal', 'bsa.tagihan.revisi', 'bsa.tarif.buat') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uji audit gagal'; END IF; END");
$sebelum = $jumlah();
$assert($tolak(static fn () => $bayar($petugas, $t9, 20000)) === 503, 'Pembayaran dengan audit gagal → 503');
$assert($tolak(static fn () => $svc->verifikasiPembayaran($petugas, $b9['id'], ['idempotency_key' => $kunci('j9-v')], 'web')) === 503, 'Verifikasi dengan audit gagal → 503');
$assert($tolak(static fn () => $svc->batalkanPembayaran($petugas, $b9s['id'], ['alasan' => 'Uji gagal audit', 'idempotency_key' => $kunci('j9-x')], 'web')) === 503, 'Pembatalan dengan audit gagal → 503');
$assert($tolak(static fn () => $svc->revisiTagihan($petugas, $t9, ['versi' => 1, 'jenis' => 'Tambahan', 'nominal' => 5000, 'alasan' => 'Uji gagal audit', 'idempotency_key' => $kunci('j9-r')], 'web')) === 503, 'Revisi dengan audit gagal → 503');
$pr9 = $rencana($petugas, '2027-01');
$assert($tolak(static fn () => $svc->terbitkanMassal($petugas, ['periode' => '2027-01', 'jatuh_tempo' => '2027-01-10', 'pratinjau_hash' => $pr9['hash'], 'idempotency_key' => $kunci('j9-m')], 'web')) === 503,
    'Penerbitan massal ' . $pr9['jumlah']['Terbit'] . ' tagihan dengan audit penutup gagal → 503');
$assert($tolak(static fn () => $svc->buatTarif($petugas, ['unit' => 'SMP', 'berlaku_mulai' => '2027-01', 'komponen' => [['nama' => 'X', 'nominal' => 1]], 'alasan' => 'Uji gagal audit', 'idempotency_key' => $kunci('j9-t')], 'web')) === 503,
    'Tarif dengan audit gagal → 503 (tarif lama tidak ditandai Diganti)');
$jalankan('DROP TRIGGER uji_v6f2_audit_gagal');
$assert($jumlah() === $sebelum, 'Seluruh kegagalan di tengah operasi dibatalkan atomik: nol tagihan, pembayaran, kuitansi, pembatalan, tarif, atau klaim baru');
$r9 = $tagihanRow($t9);
$assert((int) $r9['terbayar'] === 10000 && (int) $r9['versi'] === 1 && (string) $satu("SELECT status FROM bsa_pembayaran WHERE id = {$b9['id']}") === 'Menunggu'
    && (string) $satu("SELECT status FROM bsa_pembayaran WHERE id = {$b9s['id']}") === 'Sah', 'Tagihan dan pembayaran kembali ke keadaan sebelum kegagalan');
$assert((int) $satu('SELECT COUNT(*) FROM bsa_idempotensi WHERE hasil_id IS NULL') === 0, 'Tidak ada klaim idempotensi menggantung');

// ===================================================================== J-10
echo PHP_EOL . '=== J-10. Isolasi wali dan lintas santri (kriteria 5) ===' . PHP_EOL;
$assert($tolak(static fn () => $svc->rincianTagihan($waliT, $tagSmp)) === 404 && $tolak(static fn () => $svc->pembayaran($waliT, $tr['id'])) === 404 && $tolak(static fn () => $svc->pembayaran($waliT, $b40['id'])) === 404,
    'Wali Tsanawi membuka tagihan dan kuitansi santri SMP/santri lain → 404');
$assert($tolak(static fn () => $svc->rincianTagihan($akun('sbx_ortu_a'), $tagSmp)) === 404 && $tolak(static fn () => $svc->pembayaran($akun('sbx_ortu_a'), $tr['id'])) === 404, 'Wali tanpa relasi → 404');
$maks = (int) $satu('SELECT MAX(id) FROM bsa_pembayaran');
$bocor = 0;
for ($i = 1; $i <= $maks + 3; $i++) {
    try {
        $p = $svc->pembayaran($waliT, $i);
        $bocor += $p['santri']['id'] === $sTsanawi ? 0 : 1;
    } catch (BsaException $e) {
        $bocor += $e->status === 404 ? 0 : 1;
    }
}
$assert($bocor === 0, 'Seluruh ID pembayaran 1..' . ($maks + 3) . ' ditebak wali Tsanawi: hanya milik anaknya, lainnya 404');
$dw = $svc->daftarPembayaran($waliSmp, []);
$assert($dw['total'] >= 1 && array_unique(array_column(array_column($dw['rows'], 'santri'), 'id')) === [$sSmp]
    && array_filter($dw['rows'], static fn (array $b): bool => $b['status'] === 'Menunggu' || array_key_exists('referensi', $b) || array_key_exists('catatan', $b)) === [],
    'Daftar pembayaran wali SMP: hanya anaknya, tanpa transfer menunggu, referensi, atau catatan');
$assert($svc->daftarPembayaran($waliSmp, ['status' => 'Menunggu'])['rows'] === array_values(array_filter($svc->daftarPembayaran($waliSmp, ['status' => 'Menunggu'])['rows'], static fn ($b) => $b['status'] !== 'Menunggu')), 'Saringan status Menunggu bagi wali tidak membuka transfer menunggu');
$rw = $svc->rincianTagihan($waliSmp, $tagSmp);
$assert($rw['riwayat'] === [] && array_filter($rw['penyesuaian'], static fn (array $j): bool => array_key_exists('alasan', $j)) === []
    && array_filter($rw['pembayaran'], static fn (array $b): bool => array_key_exists('referensi', $b) || isset($b['pembatalan']['alasan'])) === [], 'Rincian tagihan bagi wali tanpa riwayat internal, alasan, referensi, atau alasan pembatalan');
$kw = $svc->pembayaran($waliSmp, $tr2['id']);
$assert($kw['status'] === 'Dibatalkan' && !array_key_exists('referensi', $kw) && !isset($kw['pembatalan']['alasan']), 'Kuitansi dibatalkan bagi wali: status terlihat, tanpa referensi dan alasan');
$tw = $svc->tunggakan($waliT, []);
$assert(array_unique(array_column(array_column($tw['rows'], 'santri'), 'id')) === [$sTsanawi] && $tw['ringkasan']['santri'] === 1, 'Tunggakan wali Tsanawi hanya anaknya');
$tt = $svc->tunggakan($petugasT, []);
$assert(array_filter($tt['rows'], static fn (array $r): bool => $r['santri']['unit'] !== 'Tsanawi') === [] && $tt['total'] > 0, 'Tunggakan petugas Tsanawi hanya santri Tsanawi');
$ta = $svc->tunggakan($admin, ['lewat' => '1']);
$assert(array_filter($ta['rows'], static fn (array $r): bool => !$r['lewat_jatuh_tempo']) === [], 'Saringan "lewat jatuh tempo" hanya tagihan yang jatuh temponya sudah lewat');
$assert(array_filter($svc->daftarPembayaran($petugasT, [])['rows'], static fn (array $b): bool => $b['santri']['id'] === $sSmp) === [], 'Daftar pembayaran petugas Tsanawi tanpa pembayaran santri SMP');
$assert($tolak(static fn () => $svc->pembayaran($petugasT, $tr['id'])) === 404 && $tolak(static fn () => $svc->penerbitan($petugasT, $m['id'])) === 404, 'Petugas Tsanawi: kuitansi SMP dan penerbitan seluruh unit buatan petugas lain → 404');
$assert($svc->pembayaran($admin, $tr['id'])['referensi'] === 'TRF-UJI-F2-1', 'Admin membaca referensi transfer (pengawasan)');

// ===================================================================== J-11
echo PHP_EOL . '=== J-11. Pencabutan penugasan, cakupan dipersempit, santri nonaktif ===' . PHP_EOL;
$t11 = $baruTagihan($sSmp, '2027-02', 100000);
$kR = $kunci('j11-replay');
$b11 = $svc->catatPembayaran($petugas, $t11, ['idempotency_key' => $kR, 'nominal' => 10000, 'sumber' => 'Tunai', 'tanggal_transaksi' => $hariIni], 'web');
$tugasB = (int) $satu("SELECT a.id FROM bendahara_bulanan_assignments a JOIN users u ON u.pengurus_id = a.pengurus_id WHERE u.username = 'sbx_v6_bsa_b'");
$jalankan("UPDATE bendahara_bulanan_assignments SET jenjang = 'Tsanawi' WHERE id = $tugasB");
$sebelum = $jumlah();
$assert($tolak(static fn () => $svc->catatPembayaran($petugas, $t11, ['idempotency_key' => $kR, 'nominal' => 10000, 'sumber' => 'Tunai', 'tanggal_transaksi' => $hariIni], 'web')) === 404,
    'Cakupan dipersempit ke Tsanawi: replay pembayaran santri SMP → 404 tanpa data hasil');
$assert($tolak(static fn () => $svc->terbitkanMassal($petugas, ['periode' => '2026-10', 'jatuh_tempo' => '2026-10-10', 'pratinjau_hash' => $pr['hash'], 'idempotency_key' => $kM, 'unit' => 'SMP'], 'web')) !== null
    && $tolak(static fn () => $svc->rincianTagihan($petugas, $tagSmp)) === 404, 'Cakupan dipersempit: tagihan SMP 404; penerbitan unit SMP ditolak');
$jalankan("UPDATE bendahara_bulanan_assignments SET jenjang = NULL WHERE id = $tugasB");
$jalankan("UPDATE santri SET is_active = 0 WHERE id = $sSmp");
$assert($tolak(static fn () => $svc->catatPembayaran($petugas, $t11, ['idempotency_key' => $kR, 'nominal' => 10000, 'sumber' => 'Tunai', 'tanggal_transaksi' => $hariIni], 'web')) === 404
    && $tolak(static fn () => $bayar($petugas, $t11, 1000)) === 404, 'Santri dinonaktifkan: replay dan pembayaran baru → 404');
$assert($svc->rincianTagihan($admin, $t11)['terbayar'] === 10000 && count($svc->tunggakan($admin, ['q' => 'V6S0001'])['rows']) >= 1, 'Admin tetap membaca tagihan/tunggakan santri nonaktif');
$jalankan("UPDATE santri SET is_active = 1 WHERE id = $sSmp");
penugasan_service()->nonaktifkan(PenugasanJenis::BENDAHARA_BULANAN, $tugasB, 'Uji cabut Fase 2', $uid('sbx_admin'));
$assert($tolak(static fn () => $bayar($petugas, $t11, 1000)) === 403 && $tolak(static fn () => $svc->buatTarif($petugas, ['unit' => 'SMP', 'berlaku_mulai' => '2027-03', 'komponen' => [['nama' => 'X', 'nominal' => 1]], 'alasan' => 'Sesudah dicabut', 'idempotency_key' => $kunci('j11')], 'web')) === 403
    && $tolak(static fn () => $svc->revisiTagihan($petugas, $t11, ['versi' => 1, 'jenis' => 'Potongan', 'nominal' => 1, 'alasan' => 'Sesudah dicabut', 'idempotency_key' => $kunci('j11')], 'web')) === 403
    && $tolak(static fn () => $svc->tunggakan($petugas, [])) === 403 && $tolak(static fn () => $svc->pratinjau($petugas, ['periode' => '2027-03', 'jatuh_tempo' => '2027-03-10'])) === 403,
    'Penugasan dinonaktifkan saat sesi masih dipakai: pembayaran, tarif, revisi, tunggakan, pratinjau → 403');
$assert($jumlah() === $sebelum, 'Penolakan J-11 tanpa baris atau klaim baru');

// ===================================================================== J-12
echo PHP_EOL . '=== J-12. Verifier, audit, preservasi ===' . PHP_EOL;
$out = [];
exec('DB_NAME=' . escapeshellarg($dbName) . ' php ' . escapeshellarg(dirname(__DIR__) . '/bin/v6_phase2_verify.php') . ' 2>&1', $out, $kodeVerify);
$assert($kodeVerify === 0, 'Verifier Fase 1 + Fase 2 lulus pada data uji (' . count(array_filter($out, static fn ($l) => str_contains($l, '[lulus]'))) . ' pemeriksaan)');
if ($kodeVerify !== 0) {
    echo implode(PHP_EOL, array_filter($out, static fn ($l) => str_contains($l, '[gagal]'))) . PHP_EOL;
}
$assert((int) $satu("SELECT COUNT(*) FROM audit_logs WHERE action = 'bsa.tagihan.terbit'") === (int) $satu('SELECT COUNT(*) FROM bsa_tagihan'), 'Setiap tagihan (manual dan massal) punya satu jejak audit terbit');
$assert((int) $satu("SELECT COUNT(*) FROM audit_logs WHERE action = 'bsa.pembayaran.catat'") === (int) $satu('SELECT COUNT(*) FROM bsa_pembayaran')
    && (int) $satu("SELECT COUNT(*) FROM audit_logs WHERE action = 'bsa.pembayaran.batal'") === (int) $satu('SELECT COUNT(*) FROM bsa_pembayaran_pembatalan'), 'Setiap pembayaran dan pembatalan punya jejak audit');
$assert((int) $satu("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'bsa.%' AND (after_json REGEXP '\"(nominal|total|terbayar|sisa|referensi|alasan|catatan|nama)\"' OR after_json LIKE '%TRF-UJI%')") === 0,
    'Audit bsa.* Fase 2 tanpa nominal, sisa, referensi, alasan, atau nama');
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
