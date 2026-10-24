<?php

declare(strict_types=1);

/**
 * Uji integrasi PRD V5 Fase 5 — laporan layanan klinik — pada DB uji
 * terpisah (fixture `tests/v5_phase5_seed.php`, periode September 2026).
 *
 *   bash tests/v5_phase5_db.sh && V5_RUN_INTEGRATION=1 php tests/v5_phase5_integration.php
 *
 *   O-1  hitungan laporan petugas/admin = nilai fixture: satu kunjungan dengan
 *        tiga obat tetap satu kunjungan; obat dibatalkan koreksi tidak dihitung;
 *   O-2  hitungan = kueri sumber independen dan = total daftar kunjungan
 *        (`KesehatanService::daftar`) dengan saringan sama, per peran;
 *   O-3  pembaca binaan: hanya Tercatat santri dalam cakupan (pembimbing A/B,
 *        murobi, ganda, murobi tanpa santri), draf tidak bocor, saringan
 *        kelas lain kosong, pilihan kelas hanya dalam cakupan;
 *   O-4  wali, pengurus/guru tanpa penugasan, akun nonaktif → 403; pencabutan
 *        penugasan → 403 pada panggilan berikutnya, perpindahan kamar segera
 *        mengurangi angka;
 *   O-5  privasi: keluaran seluruh peran memuat 0 penanda isi klinis/ringkasan/
 *        alasan; laporan tidak menulis log akses maupun audit;
 *   O-6  validasi periode (terbalik, > 366 hari, tanggal tidak valid) → 422;
 *   O-7  pengawasan admin: koreksi/publikasi dengan pelaku, alasan, versi;
 *        0 koreksi wajib-alasan tanpa alasan; non-admin tanpa pengawasan;
 *   O-8  konkurensi + transaksi gagal: 6 proses kunci sama → 1 kunjungan,
 *        6 koreksi dari versi sama → 1 versi, 6 penerbitan → 1 peristiwa;
 *        pemicu gagal di tengah pencatatan/koreksi → 0 perubahan; laporan
 *        bertambah tepat sesuai efek yang sah; verifier V5 lulus.
 *
 * Mengubah data uji; bangun ulang DB sebelum mengulang.
 */

if (getenv('V5_RUN_INTEGRATION') !== '1') {
    fwrite(STDERR, "Setel V5_RUN_INTEGRATION=1 (DB uji test_*_test) untuk menjalankan.\n");
    exit(2);
}
$dbName = getenv('DB_NAME') ?: 'test_v5_f5_test';
if (!preg_match('/^test_.*_test$/', $dbName)) {
    fwrite(STDERR, "Ditolak: DB uji harus bernama test_*_test.\n");
    exit(2);
}
putenv('DB_NAME=' . $dbName);
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Kesehatan\KesehatanException;

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
$akun = static fn (string $u): array => ['id' => (int) $satu("SELECT id FROM users WHERE username='" . $db->real_escape_string($u) . "'"), 'roles' => []];
$lap = kesehatan_laporan_service();
$klinik = kesehatan_service();
$ringkasan = kesehatan_ringkasan_service();
$SEP = ['dari' => '2026-09-01', 'sampai' => '2026-09-30'];
$P = 'V5F5RHS';
$status = static function (callable $fn): int {
    try {
        $fn();

        return 200;
    } catch (KesehatanException $e) {
        return $e->status;
    }
};
$inti = static fn (array $r): array => [
    'kunjungan' => $r['kunjungan'], 'obat' => $r['obat'], 'rujukan' => $r['rujukan'], 'tindak_lanjut' => $r['tindak_lanjut'],
    'ringkasan_wali' => $r['ringkasan_wali'], 'tertunda' => [$r['tertunda']['rujukan']['total'], $r['tertunda']['tindak_lanjut']['total']],
];
$nis = static fn (array $rows): array => array_values(array_unique(array_map(static fn (array $x): string => $x['santri']['nis'], $rows)));

$petugas = $akun('sbx_v5_petugas_f');
$admin = $akun('sbx_admin');
$sA = (int) $satu("SELECT id FROM santri WHERE nis='V5S0501'");
$sB = (int) $satu("SELECT id FROM santri WHERE nis='V5S0502'");
$kelasX = (int) $satu("SELECT id FROM kelas WHERE nama_kelas='SBX V5 Kelas X'");
$kelasY = (int) $satu("SELECT id FROM kelas WHERE nama_kelas='SBX V5 Kelas Y'");
$assert($sA > 0 && $sB > 0 && $kelasY > 0 && (int) $satu("SELECT COUNT(*) FROM kesehatan_kunjungan k JOIN santri s ON s.id = k.santri_id WHERE s.nis LIKE 'V5S050%'") === 7,
    'Fixture V5 Fase 5 tersedia (7 kunjungan lewat layanan)');

// ===================================================================== O-1
echo PHP_EOL . '=== O-1. Hitungan petugas/admin = fixture ===' . PHP_EOL;
$harapStaf = [
    'kunjungan' => ['total' => 6, 'tercatat' => 5, 'draf' => 1, 'santri' => 3, 'dikoreksi' => 1, 'dengan_obat' => 5, 'dengan_rujukan' => 3, 'dengan_tindak_lanjut' => 5],
    'obat' => ['pemberian' => 8],
    'rujukan' => ['total' => 4, 'menunggu' => 2, 'diketahui' => 2],
    'tindak_lanjut' => ['total' => 5, 'terbuka' => 4, 'terlambat' => 1, 'selesai' => 1],
    'ringkasan_wali' => ['terbit' => 2, 'ditarik' => 1, 'belum' => 2],
    'tertunda' => [3, 4],
];
$rPetugas = $lap->laporan($petugas, $SEP);
$rAdmin = $lap->laporan($admin, $SEP);
$assert($inti($rPetugas) === $harapStaf, 'Petugas: kunjungan 6 (5 Tercatat + 1 Draf), 8 pemberian obat, rujukan 4/2/2, TL 5/4/1/1, ringkasan 2/1/2, tertunda 3/4');
$assert($inti($rAdmin) === $harapStaf, 'Admin: angka identik dengan petugas');
$assert($inti($lap->laporan($akun('sbx_v5_admin_petugas'), $SEP)) === $harapStaf, 'Admin yang juga petugas: angka identik');
$assert($rPetugas['per_kelas'] === [
    ['kelas' => ['id' => $kelasX, 'nama' => 'SBX V5 Kelas X'], 'kunjungan' => 3, 'santri' => 1],
    ['kelas' => ['id' => $kelasY, 'nama' => 'SBX V5 Kelas Y'], 'kunjungan' => 2, 'santri' => 1],
    ['kelas' => null, 'kunjungan' => 1, 'santri' => 1],
], 'Per kelas: X 3, Y 2, belum ditempatkan 1 (jumlah = total)');
$assert($rPetugas['per_bulan'] === [['bulan' => '2026-09', 'kunjungan' => 6, 'santri' => 3]], 'Per bulan: September 2026 = 6; kunjungan Agustus di luar periode tidak dihitung');
$k1 = (int) $satu("SELECT k.id FROM kesehatan_kunjungan k JOIN santri s ON s.id = k.santri_id WHERE s.nis='V5S0501' AND k.waktu_kunjungan='2026-09-03 08:00:00'");
$assert((int) $satu("SELECT COUNT(*) FROM kesehatan_obat WHERE kunjungan_id = $k1 AND versi_dibatalkan IS NULL") === 3
    && $rPetugas['kunjungan']['dengan_obat'] === 5, 'Kunjungan K1 dengan 3 obat dihitung SATU kunjungan "dengan obat" (tidak menggandakan)');
$assert((int) $satu("SELECT COUNT(*) FROM kesehatan_obat WHERE versi_dibatalkan IS NOT NULL AND kunjungan_id IN (SELECT k.id FROM kesehatan_kunjungan k JOIN santri s ON s.id=k.santri_id WHERE s.nis LIKE 'V5S050%')") === 1,
    'Satu obat dibatalkan koreksi tetap tersimpan (versi_dibatalkan) tetapi tidak dihitung pemberian');
$tertunda = $rPetugas['tertunda'];
$assert($tertunda['rujukan']['rows'][0]['waktu_kunjungan'] === '2026-08-25 14:00:00' && $tertunda['rujukan']['total'] === 3,
    'Rujukan menunggu: semua periode (kunjungan Agustus ikut), terlama lebih dahulu');
$assert(array_sum(array_column($tertunda['rujukan']['rows'], 'lewat')) === 0, 'Rujukan tidak diberi tanda "lewat" (tanggal rujukan bukan target)');
$assert(array_sum(array_column($tertunda['tindak_lanjut']['rows'], 'lewat')) === 1 && $tertunda['tindak_lanjut']['rows'][0]['tanggal'] === '2026-09-10',
    'Tindak lanjut terbuka: target terdekat lebih dahulu, tepat satu lewat target');
$assert(in_array('Draf', array_column($tertunda['tindak_lanjut']['rows'], 'status'), true), 'Petugas melihat tindak lanjut terbuka milik Draf');

// ===================================================================== O-2
echo PHP_EOL . '=== O-2. Hitungan = kueri sumber independen = total daftar ===' . PHP_EOL;
$per = "k.waktu_kunjungan >= '2026-09-01' AND k.waktu_kunjungan < '2026-10-01'";
$sumber = [
    'total' => (int) $satu("SELECT COUNT(*) FROM kesehatan_kunjungan k WHERE $per"),
    'draf' => (int) $satu("SELECT COUNT(*) FROM kesehatan_kunjungan k WHERE $per AND k.status='Draf'"),
    'santri' => (int) $satu("SELECT COUNT(DISTINCT santri_id) FROM kesehatan_kunjungan k WHERE $per"),
    'dengan_obat' => (int) $satu("SELECT COUNT(DISTINCT o.kunjungan_id) FROM kesehatan_obat o JOIN kesehatan_kunjungan k ON k.id=o.kunjungan_id WHERE $per AND o.versi_dibatalkan IS NULL"),
    'pemberian' => (int) $satu("SELECT COUNT(*) FROM kesehatan_obat o JOIN kesehatan_kunjungan k ON k.id=o.kunjungan_id WHERE $per AND o.versi_dibatalkan IS NULL"),
    'rujukan_menunggu' => (int) $satu("SELECT COUNT(*) FROM kesehatan_rujukan r JOIN kesehatan_kunjungan k ON k.id=r.kunjungan_id WHERE $per AND r.versi_dibatalkan IS NULL AND r.status='Menunggu hasil'"),
    'tl_terbuka' => (int) $satu("SELECT COUNT(*) FROM kesehatan_tindak_lanjut t JOIN kesehatan_kunjungan k ON k.id=t.kunjungan_id WHERE $per AND t.versi_dibatalkan IS NULL AND t.status='Terbuka'"),
    'terbit' => (int) $satu("SELECT COUNT(*) FROM kesehatan_ringkasan g JOIN kesehatan_kunjungan k ON k.id=g.kunjungan_id WHERE $per AND g.status='Terbit'"),
];
$dariLap = [
    'total' => $rPetugas['kunjungan']['total'], 'draf' => $rPetugas['kunjungan']['draf'], 'santri' => $rPetugas['kunjungan']['santri'],
    'dengan_obat' => $rPetugas['kunjungan']['dengan_obat'], 'pemberian' => $rPetugas['obat']['pemberian'], 'rujukan_menunggu' => $rPetugas['rujukan']['menunggu'],
    'tl_terbuka' => $rPetugas['tindak_lanjut']['terbuka'], 'terbit' => $rPetugas['ringkasan_wali']['terbit'],
];
$assert($dariLap === $sumber, 'Delapan angka laporan = kueri sumber yang ditulis terpisah (' . json_encode($sumber) . ')');
$peran = ['sbx_v5_petugas_f', 'sbx_admin', 'sbx_v5_pembimbing', 'sbx_v5_pembimbing_b', 'sbx_v5_murobi', 'sbx_v5_ganda', 'sbx_v5_murobi_kosong'];
foreach ($peran as $u) {
    $r = $lap->laporan($akun($u), $SEP);
    $d = $klinik->daftar($akun($u), $SEP);
    $rm = $klinik->daftar($akun($u), $SEP + ['rujukan' => 'menunggu']);
    $tl = $klinik->daftar($akun($u), ['tindak_lanjut' => 'terbuka']);
    $rj = $klinik->daftar($akun($u), ['rujukan' => 'menunggu']);
    $assert($r['kunjungan']['total'] === $d['total'] && $r['kunjungan']['dengan_obat'] === $d['ringkasan']['dengan_obat']
        && $r['tertunda']['tindak_lanjut']['total'] === $tl['total'] && $r['tertunda']['rujukan']['total'] === $rj['total']
        && $rm['total'] <= $r['rujukan']['menunggu'],
        "$u: total/dengan obat/tertunda laporan = daftar kunjungan dengan saringan sama ({$r['kunjungan']['total']}/{$d['total']})");
}

// ===================================================================== O-3
echo PHP_EOL . '=== O-3. Pembaca binaan: hanya cakupan, tanpa draf ===' . PHP_EOL;
$harapBinaan = [
    'sbx_v5_pembimbing' => [3, 5, 1, 2, ['V5S0501'], [1, 2]],
    'sbx_v5_murobi' => [3, 5, 1, 2, ['V5S0501'], [1, 2]],
    'sbx_v5_pembimbing_b' => [1, 2, 1, 0, ['V5S0502'], [1, 0]],
    'sbx_v5_ganda' => [4, 7, 2, 2, ['V5S0501', 'V5S0502'], [2, 2]],
    'sbx_v5_murobi_kosong' => [0, 0, 0, 0, [], [0, 0]],
];
foreach ($harapBinaan as $u => [$total, $obat, $rujMenunggu, $tlTerbuka, $nisHarap, $tertundaHarap]) {
    $r = $lap->laporan($akun($u), $SEP);
    $nisLihat = array_values(array_unique(array_merge($nis($r['tertunda']['rujukan']['rows']), $nis($r['tertunda']['tindak_lanjut']['rows']))));
    sort($nisLihat);
    $nisDaftar = $nis($klinik->daftar($akun($u), [])['rows']);
    sort($nisDaftar);
    $assert($r['kunjungan']['total'] === $total && $r['obat']['pemberian'] === $obat && $r['rujukan']['menunggu'] === $rujMenunggu
        && $r['tindak_lanjut']['terbuka'] === $tlTerbuka && $r['kunjungan']['draf'] === null && $r['pengawasan'] === null
        && [$r['tertunda']['rujukan']['total'], $r['tertunda']['tindak_lanjut']['total']] === $tertundaHarap,
        "$u: kunjungan $total, obat $obat, rujukan menunggu $rujMenunggu, TL terbuka $tlTerbuka; draf disembunyikan; tanpa pengawasan");
    $assert(array_diff($nisLihat, $nisHarap) === [] && array_diff($nisLihat, $nisDaftar === [] ? $nisHarap : array_merge($nisDaftar, $nisHarap)) === [],
        "$u: daftar tertunda hanya santri cakupan (" . implode(',', $nisLihat) . ')');
    $semua = array_merge($r['tertunda']['rujukan']['rows'], $r['tertunda']['tindak_lanjut']['rows']);
    $assert(!in_array('Draf', array_column($semua, 'status'), true), "$u: tidak ada kunjungan Draf pada daftar tertunda");
}
$rB = $lap->laporan($akun('sbx_v5_pembimbing_b'), $SEP);
$assert($rB['tindak_lanjut']['total'] === 0 && $rB['tertunda']['tindak_lanjut']['total'] === 0,
    'Pembimbing B: tindak lanjut Draf K4 (santri binaannya) tidak terhitung maupun tampil');
$rP = $lap->laporan($akun('sbx_v5_pembimbing'), $SEP + ['kelas_id' => (string) $kelasY]);
$assert($rP['kunjungan']['total'] === 0 && $rP['tertunda']['rujukan']['total'] === 0 && $rP['per_kelas'] === [],
    'Pembimbing A menyaring kelas Y (di luar cakupan): kosong, bukan angka santri lain');
$assert(array_column($lap->laporan($akun('sbx_v5_pembimbing'), $SEP)['kelas_pilihan'], 'id') === [$kelasX], 'Pilihan kelas pembimbing A hanya kelas X');
$assert($lap->laporan($akun('sbx_v5_pembimbing'), $SEP + ['kelas_id' => 'tanpa'])['kunjungan']['total'] === 0, 'Pembimbing A: "belum ditempatkan" kosong (santri luar cakupan tidak terhitung)');
$rStafY = $lap->laporan($petugas, $SEP + ['kelas_id' => (string) $kelasY]);
$assert($rStafY['kunjungan']['total'] === 2 && $rStafY['kunjungan']['draf'] === 1 && count($rStafY['per_kelas']) === 1, 'Petugas menyaring kelas Y: 2 kunjungan (1 draf)');
$assert($lap->laporan($petugas, $SEP + ['kelas_id' => 'tanpa'])['kunjungan']['total'] === 1, 'Petugas menyaring belum ditempatkan: 1 kunjungan');

// ===================================================================== O-4
echo PHP_EOL . '=== O-4. Ditolak dan pencabutan ===' . PHP_EOL;
foreach (['sbx_v5_wali_1', 'sbx_v5_wali_lain', 'sbx_v5_wali_kosong', 'sbx_pengurus_biasa', 'sbx_guru_biasa', 'sbx_v5_petugas'] as $u) {
    $assert($status(static fn () => $lap->laporan($akun($u), $SEP)) === 403, "$u → 403");
}
$assert($status(static fn () => $lap->laporan(['id' => 0, 'roles' => []], $SEP)) === 403, 'Tanpa akun → 403');
$jalankan("UPDATE users SET is_active = 0 WHERE username = 'sbx_v5_petugas_g'");
$assert($status(static fn () => $lap->laporan($akun('sbx_v5_petugas_g'), $SEP)) === 403, 'Petugas berakun nonaktif → 403');
$jalankan("UPDATE users SET is_active = 1 WHERE username = 'sbx_v5_petugas_g'");
$pembimbing = $akun('sbx_v5_pembimbing');
$assert($lap->laporan($pembimbing, $SEP)['kunjungan']['total'] === 3, 'Pembimbing A sebelum pencabutan: 3');
$pengurusPembimbing = (int) $satu("SELECT pengurus_id FROM users WHERE username='sbx_v5_pembimbing'");
$jalankan("UPDATE pembimbing_assignments SET is_active = 0 WHERE pengurus_id = $pengurusPembimbing");
$assert($status(static fn () => $lap->laporan($pembimbing, $SEP)) === 403, 'Penugasan pembimbing dinonaktifkan → panggilan berikutnya 403');
$jalankan("UPDATE pembimbing_assignments SET is_active = 1 WHERE pengurus_id = $pengurusPembimbing");
$kamarB = (int) $satu("SELECT id FROM kamar WHERE nama_kamar='SBX V5 Kamar B'");
$kamarA = (int) $satu("SELECT id FROM kamar WHERE nama_kamar='SBX V5 Kamar A'");
$jalankan("UPDATE plotting_kamar SET id_kamar = $kamarB WHERE id_santri = $sA");
$assert($lap->laporan($pembimbing, $SEP)['kunjungan']['total'] === 0 && $lap->laporan($akun('sbx_v5_pembimbing_b'), $SEP)['kunjungan']['total'] === 4,
    'Santri A pindah ke Kamar B: pembimbing A 0, pembimbing B 1 + 3 (perubahan cakupan segera)');
$jalankan("UPDATE plotting_kamar SET id_kamar = $kamarA WHERE id_santri = $sA");
$assert($lap->laporan($pembimbing, $SEP)['kunjungan']['total'] === 3, 'Dikembalikan ke Kamar A: pembimbing A 3');

// ===================================================================== O-5
echo PHP_EOL . '=== O-5. Privasi dan tanpa penulisan ===' . PHP_EOL;
$akses0 = (int) $satu('SELECT COUNT(*) FROM kesehatan_akses_log');
$audit0 = (int) $satu('SELECT COUNT(*) FROM audit_logs');
$keluaran = '';
foreach (array_merge($peran, ['sbx_v5_admin_petugas']) as $u) {
    foreach ([$SEP, ['dari' => '2026-08-01', 'sampai' => date('Y-m-d')]] as $periode) {
        $keluaran .= json_encode($lap->laporan($akun($u), $periode), JSON_UNESCAPED_UNICODE);
    }
}
$assert(substr_count($keluaran, $P) === 0, 'Keluaran laporan seluruh peran memuat 0 penanda isi klinis/obat/rujukan/tindak lanjut/ringkasan/alasan (' . strlen($keluaran) . ' bait)');
foreach (['keluhan', 'catatan_klinis', 'tindakan', 'nama_obat', 'tujuan', 'hasil', 'uraian', 'alasan', 'petunjuk', 'tindakan_umum', 'tindak_lanjut_wali', 'no_hp', 'telepon'] as $kunci) {
    $assert(!str_contains($keluaran, '"' . $kunci . '"'), "Tidak ada kunci \"$kunci\" pada keluaran laporan");
}
$assert((int) $satu('SELECT COUNT(*) FROM kesehatan_akses_log') === $akses0 && (int) $satu('SELECT COUNT(*) FROM audit_logs') === $audit0,
    'Laporan tidak menulis log akses maupun audit (hanya membaca)');

// ===================================================================== O-6
echo PHP_EOL . '=== O-6. Validasi periode ===' . PHP_EOL;
$assert($status(static fn () => $lap->laporan($petugas, ['dari' => '2026-09-30', 'sampai' => '2026-09-01'])) === 422, 'Periode terbalik → 422');
$assert($status(static fn () => $lap->laporan($petugas, ['dari' => '2025-01-01', 'sampai' => '2026-09-30'])) === 422, 'Periode > 366 hari → 422');
$assert($status(static fn () => $lap->laporan($petugas, ['dari' => '2026-02-30'])) === 422 && $status(static fn () => $lap->laporan($petugas, ['sampai' => ['x']])) === 422,
    'Tanggal tidak valid/bukan teks → 422');
$assert($status(static fn () => $lap->laporan($petugas, ['dari' => '2025-10-01', 'sampai' => '2026-09-30'])) === 200, 'Periode tepat 365 hari diterima');
$bawaan = $lap->laporan($petugas, []);
$assert($bawaan['periode']['dari'] === date('Y-m-01') && $bawaan['periode']['sampai'] === date('Y-m-d'), 'Tanpa periode → awal bulan berjalan s.d. hari ini');
$assert($status(static fn () => $lap->laporan($petugas, $SEP + ['kelas_id' => "1 OR 1=1"])) === 200
    && $lap->laporan($petugas, $SEP + ['kelas_id' => "1 OR 1=1"])['filter']['kelas_id'] === '', 'Kelas tidak valid diabaikan (semua kelas), tanpa galat SQL');

// ===================================================================== O-7
echo PHP_EOL . '=== O-7. Pengawasan admin ===' . PHP_EOL;
$hariIni = ['dari' => date('Y-m-d', strtotime('-1 day')), 'sampai' => date('Y-m-d')];
$awas = $lap->laporan($admin, $hariIni)['pengawasan'];
$jumlahKoreksi = (int) $satu("SELECT COUNT(*) FROM kesehatan_kunjungan_versi WHERE versi > 1 AND dibuat_pada >= '{$hariIni['dari']}'");
$assert($awas !== null && $awas['koreksi']['total'] === $jumlahKoreksi && $awas['koreksi']['tanpa_alasan_wajib'] === 0, "Admin: koreksi = $jumlahKoreksi sumber; 0 koreksi wajib-alasan tanpa alasan");
$k7 = array_values(array_filter($awas['koreksi_terbaru'], static fn (array $x): bool => $x['santri']['nis'] === 'V5S0501'));
$assert($k7 !== [] && $k7[0]['versi_sebelum'] === 1 && $k7[0]['versi'] === 2 && $k7[0]['oleh'] === 'SBX V5 Petugas F' && $k7[0]['beralasan'] === true && $k7[0]['waktu'] !== '',
    'Koreksi K7: pelaku, waktu, versi 1 → 2, beralasan (teks alasan tidak ditampilkan)');
$peristiwa = array_column($awas['peristiwa_ringkasan'], null, 'jenis');
$assert(($peristiwa['Ganti']['jumlah'] ?? 0) >= 1 && $peristiwa['Ganti']['beralasan'] === $peristiwa['Ganti']['jumlah']
    && ($peristiwa['Tarik']['jumlah'] ?? 0) >= 1 && $peristiwa['Tarik']['beralasan'] === $peristiwa['Tarik']['jumlah'],
    'Publikasi ulang (Ganti) dan Tarik seluruhnya beralasan');
$assert(array_sum(array_column($awas['per_petugas'], 'koreksi')) === $jumlahKoreksi, 'Aktivitas per petugas: jumlah koreksi = total koreksi');
$assert(array_sum(array_column($awas['akses'], 'jumlah')) === (int) $satu("SELECT COUNT(*) FROM kesehatan_akses_log WHERE created_at >= '{$hariIni['dari']}'"), 'Akses rincian = baris log akses periode');
foreach (['sbx_v5_petugas_f', 'sbx_v5_pembimbing', 'sbx_v5_murobi'] as $u) {
    $assert($lap->laporan($akun($u), $hariIni)['pengawasan'] === null, "$u: tanpa bagian pengawasan admin");
}

// ===================================================================== O-8
echo PHP_EOL . '=== O-8. Konkurensi, transaksi gagal, verifier ===' . PHP_EOL;
$hari = ['dari' => date('Y-m-01'), 'sampai' => date('Y-m-d')];
$sebelum = $inti($lap->laporan($petugas, $hari));
$tmp = sys_get_temp_dir() . '/v5f5-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700);
// Perintah ditulis lebih dahulu agar seluruh proses menunggu berkas aba-aba yang sama.
$abaBersama = $tmp . '/aba-bersama';
$jalan = static function (array $perintah) use ($abaBersama, $dbName): array {
    @unlink($abaBersama);
    $proses = [];
    $pipa = [];
    foreach ($perintah as $p) {
        $proses[] = proc_open(array_merge($p, [$abaBersama]), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), array_merge(getenv(), ['DB_NAME' => $dbName]));
        $pipa[] = $pipes;
    }
    usleep(400000);
    touch($abaBersama);
    $hasil = [];
    foreach ($proses as $i => $h) {
        $hasil[] = json_decode(trim((string) stream_get_contents($pipa[$i][1])), true);
        proc_close($h);
    }

    return $hasil;
};
$waktuBaru = date('Y-m-d H:i', strtotime('-10 minutes'));
$kunciSama = 'v5f5-paralel-' . bin2hex(random_bytes(4));
// Santri V5S0201 punya tiga wali berakun (fixture Fase 3) sehingga deduplikasi notifikasi teruji.
$sW = (int) $satu("SELECT id FROM santri WHERE nis='V5S0201'");
$hasil = $jalan(array_fill(0, 6, ['php', 'tests/v5_phase1_worker.php', (string) $petugas['id'], (string) $sW, $kunciSama, $P . '-KELUHAN paralel', $waktuBaru]));
$baru = array_values(array_filter($hasil, static fn ($h): bool => is_array($h) && ($h['ok'] ?? false) && !($h['replayed'] ?? true)));
$ulang = array_filter($hasil, static fn ($h): bool => is_array($h) && ($h['ok'] ?? false) && ($h['replayed'] ?? false));
$kParalel = (int) ($baru[0]['id'] ?? 0);
$assert(count($baru) === 1 && count($ulang) === 5 && count(array_unique(array_column($hasil, 'id'))) === 1, '6 proses kunci sama: 1 kunjungan baru + 5 replay ke ID yang sama');
$sesudah = $inti($lap->laporan($petugas, $hari));
$assert($sesudah['kunjungan']['total'] === $sebelum['kunjungan']['total'] + 1 && $sesudah['kunjungan']['tercatat'] === $sebelum['kunjungan']['tercatat'] + 1,
    'Laporan bertambah tepat 1 kunjungan');

$detail = $klinik->detail($petugas, $kParalel, 'web');
for ($i = 0; $i < 6; $i++) {
    file_put_contents($tmp . "/koreksi-$i.json", json_encode(['versi' => 1, 'alasan' => $P . '-ALASAN paralel ' . $i, 'idempotency_key' => 'v5f5-kor-' . $i . '-' . bin2hex(random_bytes(3)),
        'waktu_kunjungan' => $detail['waktu_kunjungan'], 'keluhan' => $P . '-KELUHAN koreksi ' . $i, 'obat' => [['nama_obat' => $P . '-OBAT ' . $i, 'jumlah' => '1']]]));
}
$hasilKoreksi = $jalan(array_map(static fn (int $i): array => ['php', 'tests/v5_phase2_worker.php', (string) $petugas['id'], (string) $kParalel, $tmp . "/koreksi-$i.json"], range(0, 5)));
$sukses = array_filter($hasilKoreksi, static fn ($h): bool => is_array($h) && ($h['ok'] ?? false));
$konflik = array_filter($hasilKoreksi, static fn ($h): bool => is_array($h) && !($h['ok'] ?? true) && ($h['status'] ?? 0) === 409);
$assert(count($sukses) === 1 && count($konflik) === 5, '6 koreksi dari versi 1 bersamaan: 1 sukses + 5 konflik 409');
$assert((int) $satu("SELECT versi FROM kesehatan_kunjungan WHERE id = $kParalel") === 2 && (int) $satu("SELECT COUNT(*) FROM kesehatan_kunjungan_versi WHERE kunjungan_id = $kParalel") === 2
    && (int) $satu("SELECT COUNT(*) FROM kesehatan_obat WHERE kunjungan_id = $kParalel AND versi_dibatalkan IS NULL") === 1, 'Hanya satu versi baru dan satu obat aktif');
$sesudahKoreksi = $inti($lap->laporan($petugas, $hari));
$assert($sesudahKoreksi['kunjungan']['dikoreksi'] === $sesudah['kunjungan']['dikoreksi'] + 1 && $sesudahKoreksi['obat']['pemberian'] === $sesudah['obat']['pemberian'] + 1,
    'Laporan: +1 dikoreksi, +1 pemberian obat (bukan +6)');

$isiTerbit = ['kunjungan_versi' => 2, 'revisi_ringkasan' => 0, 'ringkasan' => $P . '-RINGKASAN paralel untuk wali.', 'tindakan_umum' => null, 'tindak_lanjut_wali' => null, 'alasan' => null];
$pr = $ringkasan->pratinjau($petugas, $kParalel, $isiTerbit);
for ($i = 0; $i < 6; $i++) {
    file_put_contents($tmp . "/terbit-$i.json", json_encode($isiTerbit + ['konfirmasi' => true, 'pratinjau_hash' => $pr['pratinjau_hash'], 'idempotency_key' => 'v5f5-terbit-' . $i . '-' . bin2hex(random_bytes(3))]));
}
$outbox0 = (int) $satu("SELECT COUNT(*) FROM notifikasi_outbox WHERE event_type LIKE 'v5\\_kesehatan\\_%'");
$hasilTerbit = $jalan(array_map(static fn (int $i): array => ['php', 'tests/v5_phase3_worker.php', (string) $petugas['id'], (string) $kParalel, $tmp . "/terbit-$i.json"], range(0, 5)));
$assert(count(array_filter($hasilTerbit, static fn ($h): bool => is_array($h) && ($h['ok'] ?? false))) === 1
    && (int) $satu("SELECT COUNT(*) FROM kesehatan_ringkasan_peristiwa e JOIN kesehatan_ringkasan g ON g.id = e.ringkasan_id WHERE g.kunjungan_id = $kParalel") === 1,
    '6 penerbitan bersamaan (kunci berbeda, revisi sama): 1 peristiwa Terbit, sisanya ditolak');
$outbox1 = (int) $satu("SELECT COUNT(*) FROM notifikasi_outbox WHERE event_type LIKE 'v5\\_kesehatan\\_%'");
$penerima = (int) $satu("SELECT jumlah_penerima FROM kesehatan_ringkasan_peristiwa e JOIN kesehatan_ringkasan g ON g.id = e.ringkasan_id WHERE g.kunjungan_id = $kParalel");
$assert($penerima === 3 && $outbox1 - $outbox0 >= $penerima && (int) $satu("SELECT COUNT(*) FROM notifikasi_outbox WHERE kanal = 'InApp' AND event_type LIKE 'v5\\_kesehatan\\_%' AND event_key LIKE CONCAT('v5:kesehatan:ringkasan:', (SELECT id FROM kesehatan_ringkasan WHERE kunjungan_id = $kParalel), ':v%')") === 3 && (int) $satu("SELECT COUNT(*) FROM (SELECT event_key, kanal, penerima_user_id FROM notifikasi_outbox GROUP BY event_key, kanal, penerima_user_id HAVING COUNT(*) > 1) d") === 0,
    "Notifikasi: InApp per penerima ($penerima), tanpa baris efektif ganda");
$assert($inti($lap->laporan($petugas, $hari))['ringkasan_wali']['terbit'] === $sesudahKoreksi['ringkasan_wali']['terbit'] + 1, 'Laporan: ringkasan terbit +1');

// Transaksi gagal di tengah: kunjungan sudah ditulis lalu rincian gagal → seluruhnya batal.
$tetap = $inti($lap->laporan($petugas, $hari));
$jumlahTabel = static fn (): array => array_map(static fn (string $t): int => (int) $satu("SELECT COUNT(*) FROM $t"), [
    'kesehatan_kunjungan', 'kesehatan_kunjungan_versi', 'kesehatan_obat', 'kesehatan_versi_rincian', 'kesehatan_idempotensi', 'audit_logs']);
$hitung0 = $jumlahTabel();
$kunciGagal = 'v5f5-gagal-' . bin2hex(random_bytes(4));
$jalankan("CREATE TRIGGER v5f5_gagal BEFORE INSERT ON kesehatan_obat FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uji'");
try {
    $kode = $status(static fn () => $klinik->buat($petugas, ['idempotency_key' => $kunciGagal, 'santri_id' => $sB, 'waktu_kunjungan' => $waktuBaru, 'keluhan' => $P . '-KELUHAN gagal',
        'obat' => [['nama_obat' => $P . '-OBAT gagal', 'jumlah' => '1']]], 'web'));
    $kodeKoreksi = $status(static fn () => $klinik->koreksi($petugas, $kParalel, ['versi' => 2, 'alasan' => 'Koreksi yang gagal di tengah', 'idempotency_key' => 'v5f5-gk-' . bin2hex(random_bytes(3)),
        'waktu_kunjungan' => $detail['waktu_kunjungan'], 'keluhan' => $P . '-KELUHAN gagal', 'obat' => [['nama_obat' => $P . '-OBAT baru', 'jumlah' => '2']]], 'web'));
} finally {
    $jalankan('DROP TRIGGER IF EXISTS v5f5_gagal');
}
$assert($kode === 503 && $kodeKoreksi === 503, "Pemicu gagal pada rincian: pencatatan $kode, koreksi $kodeKoreksi (harap 503)");
$assert($jumlahTabel() === $hitung0 && (int) $satu("SELECT versi FROM kesehatan_kunjungan WHERE id = $kParalel") === 2,
    '0 baris berubah di kunjungan/versi/obat/snapshot/idempotensi/audit; versi tetap 2');
$assert($inti($lap->laporan($petugas, $hari)) === $tetap, 'Laporan tidak berubah oleh transaksi gagal');
$ulangi = $klinik->buat($petugas, ['idempotency_key' => $kunciGagal, 'santri_id' => $sB, 'waktu_kunjungan' => $waktuBaru, 'keluhan' => $P . '-KELUHAN gagal',
    'obat' => [['nama_obat' => $P . '-OBAT gagal', 'jumlah' => '1']]], 'web');
$assert($ulangi['status'] === 201 && !$ulangi['replayed'], 'Kunci yang sama dapat diulang sesudah kegagalan (klaim ikut batal) → 201');

$verifier = [];
$kodeVerifier = 1;
exec('DB_NAME=' . escapeshellarg($dbName) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/v5_verify.php') . ' 2>&1', $verifier, $kodeVerifier);
echo '    ' . implode(PHP_EOL . '    ', array_filter($verifier, static fn (string $l): bool => str_starts_with($l, '[gagal]') || str_starts_with($l, 'Ringkasan'))) . PHP_EOL;
$assert($kodeVerifier === 0, 'bin/v5_verify.php lulus sesudah konkurensi dan transaksi gagal (' . count(array_filter($verifier, static fn (string $l): bool => str_starts_with($l, '[lulus]'))) . ' pemeriksaan lulus)');
array_map('unlink', glob($tmp . '/*') ?: []);
@rmdir($tmp);

echo PHP_EOL . 'Ringkasan: ' . $lulus . ' lulus, ' . count($gagal) . ' gagal' . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
