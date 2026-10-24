<?php

declare(strict_types=1);

/**
 * Uji integrasi layanan PRD V5 Fase 2 — obat, rujukan, tindak lanjut,
 * koreksi berversi, dan pembaca internal — langsung pada `KesehatanService`
 * dan MariaDB uji.
 *
 *   bash tests/v5_phase2_db.sh
 *   V5_RUN_INTEGRATION=1 php tests/v5_phase2_integration.php
 *
 * Skenario (kode = kriteria penerimaan Fase 2):
 *   J-1  matriks hak: petugas, admin, admin+petugas, pembimbing, murobi,
 *        pembimbing+murobi, murobi tanpa santri, pengurus/guru tanpa penugasan,
 *        wali — baca/catat/cakupan/riwayat dihitung server;
 *   J-2  satu kunjungan, dua obat, satu rujukan, satu tindak lanjut → satu
 *        baris daftar, dua obat pada detail; replay kunci sama → tanpa baris
 *        rincian baru; kunci sama isi beda → 409 tanpa perubahan;
 *   J-3  hasil rujukan diperbarui → versi 2, status Hasil diketahui; versi
 *        lama → 409; replay → versi yang sama;
 *   J-4  koreksi: tanpa alasan / alasan pendek / tanpa perubahan / rincian
 *        kunjungan lain / Tercatat→Draf ditolak tanpa perubahan; koreksi sah
 *        → versi 3, riwayat sebelum–sesudah, baris obat lama dibatalkan (tidak
 *        dihapus), snapshot versi lama utuh;
 *   J-5  alur draf: draf tidak tampil kepada pembimbing/murobi, lanjutkan
 *        draf tanpa alasan, tetapkan tercatat → tampil; koreksi berikutnya
 *        wajib alasan;
 *   J-6  konkurensi (proses terpisah): 6 koreksi dari versi sama dengan kunci
 *        berbeda → 1 sukses + 5 konflik, satu versi baru, isi pemenang utuh;
 *        6 kiriman kunci+isi sama → 1 versi, sisanya replay;
 *   J-7  pembaca binaan: hanya santri dalam cakupan, hanya Tercatat, ID
 *        tebakan → 403, penyaring tidak memperluas cakupan, ringkasan sama
 *        dengan baris; sumber log akses per baris;
 *   J-8  seluruh percobaan tulis pembaca binaan/admin ditolak 403, riwayat
 *        versi/akses ditolak, 0 perubahan;
 *   J-9  sesi lama: pencabutan penugasan, perpindahan kamar, kelas selesai,
 *        master nonaktif, akun nonaktif → detail segera tertutup;
 *   J-10 penugasan petugas dicabut → koreksi berikutnya 403 tanpa versi;
 *   J-11 privasi: 0 isi klinis/rincian di daftar, audit_logs, log akses,
 *        klaim idempotensi, dan pesan galat;
 *   J-12 tidak ada stok; tabel PSB/keuangan/izin dan seluruh tabel lain tidak
 *        berubah; verifier 025 lulus.
 *
 * Mengubah data uji; bangun ulang DB sebelum mengulang.
 */

if (getenv('V5_RUN_INTEGRATION') !== '1') {
    fwrite(STDERR, "Set V5_RUN_INTEGRATION=1 untuk menjalankan.\n");
    exit(2);
}
$dbName = getenv('V5F2_DB') ?: 'test_v5_f2_test';
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
$uid = static fn (string $u): int => (int) $satu("SELECT id FROM users WHERE username='" . $db->real_escape_string($u) . "'");
$akun = static fn (string $u): array => ['id' => $uid($u), 'roles' => []];
$svc = kesehatan_service();
$tolak = static function (callable $fn): ?int {
    try {
        $fn();
    } catch (KesehatanException $e) {
        return $e->status;
    }

    return null;
};
$pesanTolak = static function (callable $fn): string {
    try {
        $fn();
    } catch (KesehatanException $e) {
        return $e->getMessage();
    }

    return '';
};
$kunci = static fn (string $awal): string => $awal . '-' . bin2hex(random_bytes(6));
$jumlah = static fn (string $tabel, string $where = '1 = 1'): int => (int) $satu("SELECT COUNT(*) FROM `$tabel` WHERE $where");
$waktu = date('Y-m-d H:i', strtotime('-2 hours'));
$hariIni = date('Y-m-d');
$sA = (int) $satu("SELECT id FROM santri WHERE nis='V5S0101'");
$sB = (int) $satu("SELECT id FROM santri WHERE nis='V5S0102'");
$sLuar = (int) $satu("SELECT id FROM santri WHERE nis='V5S0103'");
$assert($sA > 0 && $sB > 0 && $sLuar > 0 && $uid('sbx_v5_pembimbing') > 0 && $uid('sbx_v5_petugas_d') > 0, 'Fixture V5 Fase 2 tersedia');
$petugas = $akun('sbx_v5_petugas_b');
$petugasD = $akun('sbx_v5_petugas_d');
$admin = $akun('sbx_admin');
$penanda = 'P2X' . strtoupper(bin2hex(random_bytes(4)));

$hitungLain = static function () use ($db): array {
    $hasil = [];
    $r = $db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
        AND TABLE_NAME NOT LIKE 'kesehatan\\_%' AND TABLE_NAME NOT IN ('audit_logs', 'api_tokens', 'login_attempts', 'schema_migrations') ORDER BY TABLE_NAME");
    foreach ($r->fetch_all(MYSQLI_ASSOC) as $row) {
        $hasil[$row['TABLE_NAME']] = (int) $db->query('SELECT COUNT(*) FROM `' . $row['TABLE_NAME'] . '`')->fetch_row()[0];
    }

    return $hasil;
};
$checksumLain = static function () use ($db): array {
    $hasil = [];
    foreach ($db->query('CHECKSUM TABLE psb_pendaftar, psb_pembayaran, bendahara_psb_assignments, panitia_psb_assignments, izin_pengajuan, izin_keputusan, izin_riwayat_status, perizinan, wali, santri_wali')->fetch_all(MYSQLI_ASSOC) as $row) {
        $hasil[(string) $row['Table']] = (string) $row['Checksum'];
    }

    return $hasil;
};
$sebelumLain = $hitungLain();
$sebelumChecksum = $checksumLain();

// ===================================================================== J-1
echo PHP_EOL . '=== J-1. Matriks hak (dihitung server) ===' . PHP_EOL;
$harap = [
    'sbx_v5_petugas_b' => [true, true, 'petugas', 'seluruh', false, true],
    'sbx_admin' => [true, false, 'admin', 'seluruh', true, true],
    'sbx_v5_admin_petugas' => [true, false, 'admin_petugas', 'seluruh', true, true],
    'sbx_v5_pembimbing' => [true, false, 'pembimbing', 'binaan', false, false],
    'sbx_v5_murobi' => [true, false, 'murobi', 'binaan', false, false],
    'sbx_v5_ganda' => [true, false, 'pembimbing_murobi', 'binaan', false, false],
    'sbx_v5_murobi_kosong' => [true, false, 'murobi', 'binaan', false, false],
    'sbx_pengurus_biasa' => [false, false, null, null, false, false],
    'sbx_guru_biasa' => [false, false, null, null, false, false],
    'sbx_ortu_a' => [false, false, null, null, false, false],
];
foreach ($harap as $u => [$baca, $catat, $sumber, $cakupan, $rAkses, $rVersi]) {
    $a = $svc->akses($akun($u));
    $assert($a['baca'] === $baca && $a['catat'] === $catat && $a['sumber'] === $sumber && $a['cakupan'] === $cakupan
        && $a['riwayat_akses'] === $rAkses && $a['riwayat_versi'] === $rVersi,
        "$u: baca=" . var_export($baca, true) . ' catat=' . var_export($catat, true) . ' sumber=' . var_export($sumber, true) . ' cakupan=' . var_export($cakupan, true));
}

// ===================================================================== J-2
echo PHP_EOL . '=== J-2. Satu kunjungan, dua obat, satu rujukan ===' . PHP_EOL;
$isi = [
    'idempotency_key' => $kunci('j2'),
    'santri_id' => $sA,
    'waktu_kunjungan' => $waktu,
    'keluhan' => "$penanda-KELUHAN demam",
    'catatan_klinis' => "$penanda-CATATAN",
    'tindakan' => "$penanda-TINDAKAN kompres",
    'obat' => [
        ['nama_obat' => "$penanda-OBAT-1", 'jumlah' => '1,5', 'satuan' => 'tablet', 'petunjuk' => "$penanda-PETUNJUK 3x1"],
        ['nama_obat' => "$penanda-OBAT-2", 'jumlah' => '2', 'satuan' => 'sachet', 'petunjuk' => ''],
        ['nama_obat' => '', 'jumlah' => '', 'satuan' => '', 'petunjuk' => ''],
    ],
    'rujukan' => [['tujuan' => "$penanda-TUJUAN Puskesmas", 'alasan' => "$penanda-ALASAN cek darah"]],
    'tindak_lanjut' => [['uraian' => "$penanda-URAIAN kontrol tiga hari", 'tanggal_target' => date('Y-m-d', strtotime('+3 days'))], ['uraian' => '']],
];
$kunjunganAwal = $jumlah('kesehatan_kunjungan');
$h = $svc->buat($petugas, $isi, 'web');
$id = $h['id'];
$assert(!$h['replayed'] && $h['status'] === 201 && $jumlah('kesehatan_kunjungan') === $kunjunganAwal + 1, 'Satu kunjungan tersimpan (201)');
$assert($jumlah('kesehatan_obat', "kunjungan_id = $id") === 2 && $jumlah('kesehatan_rujukan', "kunjungan_id = $id") === 1 && $jumlah('kesehatan_tindak_lanjut', "kunjungan_id = $id") === 1,
    'Baris kosong diabaikan: 2 obat, 1 rujukan, 1 tindak lanjut tersimpan');
$assert($jumlah('kesehatan_versi_rincian', "kunjungan_id = $id AND versi = 1") === 1 && $jumlah('kesehatan_kunjungan_versi', "kunjungan_id = $id") === 1, 'Versi 1 + snapshot rincian versi 1');
$d = $svc->detail($petugas, $id, 'web');
$assert(count($d['obat']) === 2 && $d['obat'][0]['jumlah'] === '1.5' && $d['obat'][0]['waktu_pemberian'] === $waktu . ':00' && $d['obat'][1]['petunjuk'] === null,
    'Detail: dua obat, jumlah desimal, waktu pemberian bawaan = waktu kunjungan');
$assert(count($d['rujukan']) === 1 && $d['rujukan'][0]['status'] === 'Menunggu hasil' && $d['rujukan'][0]['tanggal_rujukan'] === substr($waktu, 0, 10) && $d['rujukan'][0]['hasil'] === null,
    'Detail: satu rujukan Menunggu hasil, tanggal bawaan = tanggal kunjungan');
$assert(count($d['tindak_lanjut']) === 1 && $d['tindak_lanjut'][0]['status'] === 'Terbuka', 'Detail: satu tindak lanjut Terbuka');
$l = $svc->daftar($petugas, ['santri_id' => $sA]);
$baris = array_values(array_filter($l['rows'], static fn (array $r): bool => $r['id'] === $id));
$assert($l['total'] === 1 && count($l['rows']) === 1 && $baris !== [] && $baris[0]['penanda'] === ['obat' => 2, 'rujukan' => 1, 'rujukan_menunggu' => 1, 'tindak_lanjut' => 1, 'tindak_lanjut_terbuka' => 1],
    'Daftar santri: SATU baris kunjungan dengan penanda 2 obat, 1 rujukan menunggu, 1 tindak lanjut terbuka');
$assert($l['ringkasan'] === ['kunjungan' => 1, 'dengan_obat' => 1, 'rujukan_menunggu' => 1, 'tindak_lanjut_terbuka' => 1, 'draf' => 0], 'Ringkasan dari kueri yang sama: 1 kunjungan');
$h2 = $svc->buat($petugas, $isi, 'api');
$assert($h2['replayed'] && $h2['id'] === $id && $jumlah('kesehatan_obat', "kunjungan_id = $id") === 2 && $jumlah('kesehatan_versi_rincian', "kunjungan_id = $id") === 1,
    'Kirim ulang kunci+isi sama → kunjungan sama, tanpa obat/snapshot baru');
$beda = $isi;
$beda['obat'][1]['jumlah'] = '3';
$assert($tolak(static fn () => $svc->buat($petugas, $beda, 'web')) === 409 && $jumlah('kesehatan_obat', "kunjungan_id = $id") === 2, 'Kunci sama + obat berbeda → 409 tanpa perubahan');
foreach ([
    'nama obat kosong' => ['obat' => [['nama_obat' => '', 'jumlah' => '1']]],
    'jumlah negatif' => ['obat' => [['nama_obat' => 'X', 'jumlah' => '-1']]],
    'jumlah bukan angka' => ['obat' => [['nama_obat' => 'X', 'jumlah' => 'dua']]],
    'obat di masa depan' => ['obat' => [['nama_obat' => 'X', 'waktu_pemberian' => date('Y-m-d H:i', strtotime('+2 hours'))]]],
    'rujukan tanpa tujuan' => ['rujukan' => [['tujuan' => '', 'alasan' => 'x']]],
    'tanggal hasil tanpa hasil' => ['rujukan' => [['tujuan' => 'X', 'tanggal_hasil' => $hariIni]]],
    'rujukan sebelum kunjungan' => ['rujukan' => [['tujuan' => 'X', 'tanggal_rujukan' => date('Y-m-d', strtotime('-40 days'))]]],
    'tindak lanjut status asing' => ['tindak_lanjut' => [['uraian' => 'X', 'status' => 'Tutup']]],
    'rincian bukan larik' => ['obat' => 'X'],
    'rincian baru membawa id' => ['obat' => [['id' => 1, 'nama_obat' => 'X']]],
    'obat lebih dari 20' => ['obat' => array_fill(0, 21, ['nama_obat' => 'X'])],
] as $label => $ubah) {
    $x = array_merge($isi, $ubah, ['idempotency_key' => $kunci('j2v')]);
    $sebelum = $jumlah('kesehatan_kunjungan');
    $assert($tolak(static fn () => $svc->buat($petugas, $x, 'web')) === 422 && $jumlah('kesehatan_kunjungan') === $sebelum, "Validasi $label → 422 tanpa kunjungan");
}
$assert($jumlah('kesehatan_idempotensi', "kunci LIKE 'j2v-%'") === 0, 'Validasi gagal: 0 klaim idempotensi tertinggal');

// ===================================================================== J-3
echo PHP_EOL . '=== J-3. Hasil rujukan diperbarui ===' . PHP_EOL;
$rid = $d['rujukan'][0]['id'];
$hasilRujukan = ['versi' => 1, 'alasan' => 'Hasil laboratorium diterima', 'hasil' => "$penanda-HASIL normal", 'tanggal_hasil' => $hariIni, 'idempotency_key' => $kunci('j3')];
$assert($tolak(static fn () => $svc->hasilRujukan($petugas, $id, $rid, array_merge($hasilRujukan, ['alasan' => '']), 'web')) === 422 && (int) $satu("SELECT versi FROM kesehatan_kunjungan WHERE id = $id") === 1,
    'Hasil rujukan tanpa alasan pada kunjungan Tercatat → 422');
$r = $svc->hasilRujukan($petugas, $id, $rid, $hasilRujukan, 'web');
$assert(!$r['replayed'] && $r['versi'] === 2 && (string) $satu("SELECT status FROM kesehatan_rujukan WHERE id = $rid") === 'Hasil diketahui', 'Hasil rujukan → versi 2, status Hasil diketahui');
$r2 = $svc->hasilRujukan($petugas, $id, $rid, $hasilRujukan, 'api');
$assert($r2['replayed'] && $r2['versi'] === 2 && $jumlah('kesehatan_kunjungan_versi', "kunjungan_id = $id") === 2, 'Replay kunci sama → versi 2 yang sama, tanpa versi baru');
$assert($tolak(static fn () => $svc->hasilRujukan($petugas, $id, $rid, array_merge($hasilRujukan, ['idempotency_key' => $kunci('j3b')]), 'web')) === 409, 'Membawa versi lama (1) → 409');
$assert($tolak(static fn () => $svc->hasilRujukan($petugas, $id, $rid, array_merge($hasilRujukan, ['versi' => 2, 'idempotency_key' => $kunci('j3c')]), 'web')) === 409,
    'Hasil yang sudah tercatat tidak ditimpa lewat jalur hasil (409)');
$assert($tolak(static fn () => $svc->hasilRujukan($petugas, $id, 999999, array_merge($hasilRujukan, ['versi' => 2, 'idempotency_key' => $kunci('j3d')]), 'web')) === 404, 'Rujukan bukan milik kunjungan → 404');
$v = $svc->riwayatVersi($petugas, $id, 'web');
$p = array_column($v['versi'][0]['perubahan'], 'sesudah', 'kolom');
$assert($v['versi'][0]['versi'] === 2 && $v['versi'][0]['alasan'] === 'Hasil laboratorium diterima' && ($p['status'] ?? '') === 'Hasil diketahui' && ($p['hasil'] ?? '') === "$penanda-HASIL normal",
    'Riwayat versi 2: alasan + perubahan status/hasil rujukan sebelum→sesudah');

// ===================================================================== J-4
echo PHP_EOL . '=== J-4. Koreksi berversi ===' . PHP_EOL;
$d = $svc->detail($petugas, $id, 'web');
$keadaan = static function (array $d): array {
    return [
        'waktu_kunjungan' => substr($d['waktu_kunjungan'], 0, 16), 'keluhan' => $d['keluhan'], 'catatan_klinis' => $d['catatan_klinis'], 'tindakan' => $d['tindakan'],
        'status' => $d['status'], 'obat' => $d['obat'], 'rujukan' => $d['rujukan'], 'tindak_lanjut' => $d['tindak_lanjut'],
    ];
};
$dasar = $keadaan($d) + ['versi' => 2];
$cobaTolak = static function (array $ubah, string $label, int $harapStatus = 422) use ($svc, $petugas, $id, $dasar, $kunci, $assert, $tolak, $satu): void {
    $x = array_merge($dasar, $ubah, ['idempotency_key' => $kunci('j4t')]);
    $assert($tolak(static fn () => $svc->koreksi($petugas, $id, $x, 'web')) === $harapStatus && (int) $satu("SELECT versi FROM kesehatan_kunjungan WHERE id = $id") === 2,
        "Koreksi $label → $harapStatus, versi tetap 2");
};
$cobaTolak(['keluhan' => "$penanda-KELUHAN demam tinggi"], 'tanpa alasan');
$cobaTolak(['keluhan' => "$penanda-KELUHAN demam tinggi", 'alasan' => 'abc'], 'alasan < 5 karakter');
$cobaTolak(['alasan' => 'Tidak mengubah apa pun'], 'tanpa perubahan isi');
$cobaTolak(['alasan' => 'Kembalikan ke draf', 'status' => 'Draf'], 'Tercatat → Draf');
$lain = (int) $satu("SELECT id FROM kesehatan_obat WHERE kunjungan_id <> $id LIMIT 1");
$obatAsing = $dasar['obat'];
$obatAsing[] = ['id' => $lain ?: 999999, 'nama_obat' => 'X'];
$cobaTolak(['alasan' => 'Menyisipkan obat kunjungan lain', 'obat' => $obatAsing], 'dengan id rincian kunjungan lain');
$cobaTolak(['alasan' => 'Versi kosong', 'versi' => ''], 'tanpa versi');
$obat1 = $dasar['obat'][0]['id'];
$obat2 = $dasar['obat'][1]['id'];
$koreksi = array_merge($dasar, [
    'idempotency_key' => $kunci('j4'),
    'alasan' => 'Salah tulis jumlah dan obat kedua tidak jadi diberikan',
    'keluhan' => "$penanda-KELUHAN demam tinggi",
    'obat' => [
        ['id' => $obat1, 'nama_obat' => "$penanda-OBAT-1", 'jumlah' => '1', 'satuan' => 'tablet', 'petunjuk' => "$penanda-PETUNJUK 3x1"],
        ['id' => $obat2, 'nama_obat' => "$penanda-OBAT-2", 'hapus' => '1'],
        ['nama_obat' => "$penanda-OBAT-3", 'jumlah' => '1', 'satuan' => 'botol'],
    ],
]);
$k = $svc->koreksi($petugas, $id, $koreksi, 'web');
$assert(!$k['replayed'] && $k['versi'] === 3 && (int) $satu("SELECT versi FROM kesehatan_kunjungan WHERE id = $id") === 3, 'Koreksi sah → versi 3');
$assert((int) $satu("SELECT versi_dibatalkan FROM kesehatan_obat WHERE id = $obat2") === 3 && $jumlah('kesehatan_obat', "kunjungan_id = $id") === 3
    && $jumlah('kesehatan_obat', "kunjungan_id = $id AND versi_dibatalkan IS NULL") === 2, 'Obat yang dihapus dibatalkan pada versi 3, barisnya tetap ada (tidak DELETE)');
$d3 = $svc->detail($petugas, $id, 'web');
$assert($d3['keluhan'] === "$penanda-KELUHAN demam tinggi" && array_column($d3['obat'], 'jumlah') === ['1', '1'] && $d3['rujukan'][0]['status'] === 'Hasil diketahui',
    'Detail versi 3: keluhan baru, dua obat aktif, hasil rujukan tetap');
$rv = $svc->riwayatVersi($admin, $id, 'web');
$v3 = $rv['versi'][0];
$jenis = array_map(static fn (array $p): string => $p['bagian'] . ':' . $p['jenis'] . ':' . ($p['kolom'] ?? ''), $v3['perubahan']);
$assert($v3['versi'] === 3 && $v3['alasan'] === $koreksi['alasan'] && $v3['oleh']['id'] === $petugas['id']
    && in_array('kunjungan:ubah:keluhan', $jenis, true) && in_array('obat:ubah:jumlah', $jenis, true) && in_array('obat:hapus:', $jenis, true) && in_array('obat:tambah:', $jenis, true),
    'Riwayat versi 3: pelaku, alasan, perubahan keluhan, jumlah obat, obat dihapus, obat ditambah');
$keluhanSebelum = array_values(array_filter($v3['perubahan'], static fn (array $p): bool => $p['kolom'] === 'keluhan'))[0] ?? [];
$assert(($keluhanSebelum['sebelum'] ?? '') === "$penanda-KELUHAN demam" && ($keluhanSebelum['sesudah'] ?? '') === "$penanda-KELUHAN demam tinggi", 'Nilai sebelum–sesudah keluhan dapat dibaca');
$snap2 = json_decode((string) $satu("SELECT rincian FROM kesehatan_versi_rincian WHERE kunjungan_id = $id AND versi = 2"), true);
$assert(count($snap2['obat']) === 2 && $snap2['obat'][1]['jumlah'] === '2' && (string) $satu("SELECT keluhan FROM kesehatan_kunjungan_versi WHERE kunjungan_id = $id AND versi = 2") === "$penanda-KELUHAN demam",
    'Snapshot versi 2 utuh (dua obat, keluhan lama) setelah koreksi');
$k2 = $svc->koreksi($petugas, $id, $koreksi, 'api');
$assert($k2['replayed'] && $k2['versi'] === 3 && $jumlah('kesehatan_kunjungan_versi', "kunjungan_id = $id") === 3, 'Replay koreksi kunci sama → versi 3 yang sama');
$assert($tolak(static fn () => $svc->koreksi($petugas, $id, array_merge($koreksi, ['alasan' => 'Isi lain dengan kunci sama']), 'web')) === 409, 'Kunci koreksi sama + isi berbeda → 409');
$assert(count($rv['versi']) === 3 && $rv['versi'][2]['versi'] === 1 && $rv['versi'][2]['alasan'] === null, 'Riwayat lengkap 3 versi, versi 1 pencatatan awal');

// ===================================================================== J-5
echo PHP_EOL . '=== J-5. Alur draf ===' . PHP_EOL;
$draf = ['idempotency_key' => $kunci('j5'), 'santri_id' => $sA, 'waktu_kunjungan' => $waktu, 'keluhan' => "$penanda-DRAF pusing", 'status' => 'Draf'];
$hd = $svc->buat($petugas, $draf, 'web');
$idDraf = $hd['id'];
$pembimbing = $akun('sbx_v5_pembimbing');
$murobi = $akun('sbx_v5_murobi');
$adaDi = static fn (array $l, int $kid): bool => in_array($kid, array_column($l['rows'], 'id'), true);
$assert((string) $satu("SELECT status FROM kesehatan_kunjungan WHERE id = $idDraf") === 'Draf', 'Draf tersimpan');
$assert(!$adaDi($svc->daftar($pembimbing, []), $idDraf) && $tolak(static fn () => $svc->detail($pembimbing, $idDraf, 'web')) === 403
    && !$adaDi($svc->daftar($murobi, []), $idDraf) && $tolak(static fn () => $svc->detail($murobi, $idDraf, 'api')) === 403, 'Draf tidak tampil kepada pembimbing/murobi (daftar + detail 403)');
$assert($adaDi($svc->daftar($admin, ['status' => 'Draf']), $idDraf) && $adaDi($svc->daftar($petugasD, []), $idDraf), 'Draf tampil kepada admin dan petugas lain');
$assert($tolak(static fn () => $svc->daftar($pembimbing, ['status' => 'Draf'])) === null && !$adaDi($svc->daftar($pembimbing, ['status' => 'Draf']), $idDraf), 'Penyaring status=Draf tidak membuka draf bagi pembimbing');
$kd = $svc->koreksi($petugasD, $idDraf, ['idempotency_key' => $kunci('j5a'), 'versi' => 1, 'waktu_kunjungan' => $waktu, 'keluhan' => "$penanda-DRAF pusing dan mual", 'status' => 'Draf'], 'web');
$assert($kd['versi'] === 2 && (string) $satu("SELECT status FROM kesehatan_kunjungan WHERE id = $idDraf") === 'Draf', 'Lanjutkan draf tanpa alasan → versi 2, tetap Draf (petugas lain boleh)');
$kn = $svc->koreksi($petugas, $idDraf, ['idempotency_key' => $kunci('j5n'), 'versi' => 2, 'waktu_kunjungan' => $waktu, 'keluhan' => "$penanda-DRAF pusing dan mual", 'tindakan' => 'Istirahat'], 'api');
$assert($kn['versi'] === 3 && (string) $satu("SELECT status FROM kesehatan_kunjungan WHERE id = $idDraf") === 'Draf', 'Koreksi API tanpa status mempertahankan Draf (tidak tercatatkan diam-diam)');
$kt = $svc->koreksi($petugas, $idDraf, ['idempotency_key' => $kunci('j5b'), 'versi' => 3, 'waktu_kunjungan' => $waktu, 'keluhan' => "$penanda-DRAF pusing dan mual", 'tindakan' => 'Istirahat', 'status' => 'Tercatat'], 'web');
$assert($kt['versi'] === 4 && $adaDi($svc->daftar($pembimbing, []), $idDraf) && $svc->detail($pembimbing, $idDraf, 'web')['status'] === 'Tercatat', 'Tetapkan tercatat → tampil kepada pembimbing');
$assert($tolak(static fn () => $svc->koreksi($petugas, $idDraf, ['idempotency_key' => $kunci('j5c'), 'versi' => 4, 'waktu_kunjungan' => $waktu, 'keluhan' => 'Ubah tanpa alasan', 'status' => 'Tercatat'], 'web')) === 422,
    'Setelah Tercatat, koreksi tanpa alasan → 422');

// ===================================================================== J-6
echo PHP_EOL . '=== J-6. Koreksi bersamaan (proses terpisah) ===' . PHP_EOL;
$scratch = sys_get_temp_dir() . '/v5f2-' . bin2hex(random_bytes(4));
mkdir($scratch);
$serbu = static function (array $permintaan) use ($scratch, $dbName): array {
    $aba = $scratch . '/mulai-' . bin2hex(random_bytes(3));
    $proc = [];
    foreach ($permintaan as $i => [$userId, $kunjunganId, $masukan]) {
        $berkas = $scratch . '/masukan-' . $i . '-' . bin2hex(random_bytes(3)) . '.json';
        file_put_contents($berkas, json_encode($masukan, JSON_UNESCAPED_UNICODE));
        $h = proc_open(['php', dirname(__DIR__) . '/tests/v5_phase2_worker.php', (string) $userId, (string) $kunjunganId, $berkas, $aba],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge(getenv(), ['DB_NAME' => $dbName]));
        $proc[$i] = [$h, $pipes];
    }
    usleep(500000);
    touch($aba);
    $hasil = [];
    foreach ($proc as [$h, $pipes]) {
        $out = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        proc_close($h);
        $hasil[] = json_decode(trim((string) $out), true) ?? ['ok' => false, 'status' => 0];
    }

    return $hasil;
};
$d = $svc->detail($petugas, $id, 'web');
$dasar = $keadaan($d) + ['versi' => 3];
$permintaan = [];
for ($i = 0; $i < 6; $i++) {
    $pelaku = $i % 2 === 0 ? $petugas['id'] : $petugasD['id'];
    $permintaan[] = [$pelaku, $id, array_merge($dasar, ['idempotency_key' => $kunci('j6-' . $i), 'alasan' => "Koreksi bersamaan nomor $i", 'tindakan' => "$penanda-TINDAKAN-BERSAMAAN-$i"])];
}
$hasil = $serbu($permintaan);
$sukses = array_values(array_filter($hasil, static fn (array $h): bool => $h['ok'] === true));
$konflik = array_filter($hasil, static fn (array $h): bool => $h['ok'] === false && $h['status'] === 409);
$assert(count($sukses) === 1 && count($konflik) === 5, '6 koreksi dari versi 3 bersamaan → 1 sukses + 5 konflik 409 (dapat: ' . count($sukses) . ' sukses, ' . count($konflik) . ' konflik)');
$assert((int) $satu("SELECT versi FROM kesehatan_kunjungan WHERE id = $id") === 4 && $jumlah('kesehatan_kunjungan_versi', "kunjungan_id = $id") === 4
    && $jumlah('kesehatan_versi_rincian', "kunjungan_id = $id") === 4, 'Tepat satu versi baru (4) beserta satu snapshot');
$tindakan = (string) $satu("SELECT tindakan FROM kesehatan_kunjungan WHERE id = $id");
$alasan4 = (string) $satu("SELECT alasan FROM kesehatan_kunjungan_versi WHERE kunjungan_id = $id AND versi = 4");
$assert(preg_match('/BERSAMAAN-(\d)$/', $tindakan, $m) === 1 && $alasan4 === 'Koreksi bersamaan nomor ' . $m[1]
    && (string) $satu("SELECT tindakan FROM kesehatan_kunjungan_versi WHERE kunjungan_id = $id AND versi = 3") === "$penanda-TINDAKAN kompres",
    'Isi pemenang utuh dan cocok dengan alasannya; versi 3 tidak tertimpa');
$assert($jumlah('kesehatan_idempotensi', "kunci LIKE 'j6-%'") === 1, 'Hanya klaim pemenang yang tersimpan; klaim konflik ikut dibatalkan');
$sama = array_merge($dasar, ['versi' => 4, 'idempotency_key' => $kunci('j6s'), 'alasan' => 'Kiriman ganda dari jaringan lambat', 'catatan_klinis' => "$penanda-CATATAN-GANDA"]);
$sama['tindakan'] = $tindakan;
$hasil = $serbu(array_fill(0, 6, [$petugas['id'], $id, $sama]));
$versiHasil = array_unique(array_column($hasil, 'versi'));
$assert(count(array_filter($hasil, static fn (array $h): bool => $h['ok'] === true)) === 6 && count(array_filter($hasil, static fn (array $h): bool => ($h['replayed'] ?? null) === false)) === 1
    && $versiHasil === [5], '6 kiriman kunci+isi sama bersamaan → 1 versi baru (5), 5 replay versi yang sama');
$assert((int) $satu("SELECT versi FROM kesehatan_kunjungan WHERE id = $id") === 5 && $jumlah('kesehatan_kunjungan_versi', "kunjungan_id = $id") === 5, 'Tidak ada versi ganda');

// ===================================================================== J-7
echo PHP_EOL . '=== J-7. Pembaca binaan sesuai cakupan ===' . PHP_EOL;
$hB = $svc->buat($petugas, ['idempotency_key' => $kunci('j7b'), 'santri_id' => $sB, 'waktu_kunjungan' => $waktu, 'keluhan' => "$penanda-B batuk",
    'rujukan' => [['tujuan' => "$penanda-TUJUAN RS"]]], 'web');
$hLuar = $svc->buat($petugas, ['idempotency_key' => $kunci('j7l'), 'santri_id' => $sLuar, 'waktu_kunjungan' => $waktu, 'keluhan' => "$penanda-LUAR flu",
    'tindak_lanjut' => [['uraian' => "$penanda-URAIAN istirahat"]]], 'web');
$idB = $hB['id'];
$idLuar = $hLuar['id'];
$ids = static fn (array $u, array $f = []): array => array_column($svc->daftar($u, $f)['rows'], 'id');
$santriDi = static fn (array $u): array => array_values(array_unique(array_map(static fn (array $r): int => $r['santri']['id'], $svc->daftar($u, [])['rows'])));
$pembimbingB = $akun('sbx_v5_pembimbing_b');
$ganda = $akun('sbx_v5_ganda');
$kosong = $akun('sbx_v5_murobi_kosong');
$assert($santriDi($pembimbing) === [$sA] && in_array($id, $ids($pembimbing), true) && !in_array($idB, $ids($pembimbing), true), 'Pembimbing Kamar A hanya membaca santri Binaan A');
$assert($santriDi($pembimbingB) === [$sB] && $ids($pembimbingB) === [$idB], 'Pembimbing Kamar B hanya membaca santri Binaan B');
$assert($santriDi($murobi) === [$sA], 'Murobi Kelas X hanya membaca santri Binaan A');
$assert($svc->daftar($kosong, [])['total'] === 0 && $svc->daftar($kosong, [])['ringkasan']['kunjungan'] === 0, 'Murobi tanpa santri: daftar dan ringkasan kosong');
$gandaSantri = $santriDi($ganda);
sort($gandaSantri);
$harapGanda = [$sA, $sB];
sort($harapGanda);
$assert($gandaSantri === $harapGanda, 'Pembimbing+murobi membaca gabungan kedua cakupan (A lewat murobi, B lewat pembimbing)');
$petugasSantri = array_unique(array_map(static fn (array $r): int => $r['santri']['id'], $svc->daftar($petugas, [])['rows']));
$assert(in_array($sLuar, $petugasSantri, true), 'Petugas membaca seluruh santri termasuk di luar cakupan binaan');
foreach (['sbx_v5_pembimbing' => $pembimbing, 'sbx_v5_pembimbing_b' => $pembimbingB, 'sbx_v5_murobi' => $murobi, 'sbx_v5_ganda' => $ganda, 'sbx_v5_murobi_kosong' => $kosong] as $u => $x) {
    $assert($tolak(static fn () => $svc->detail($x, $idLuar, 'api')) === 403 && $tolak(static fn () => $svc->detail($x, 99999999, 'api')) === 403,
        "$u: ID santri di luar cakupan dan ID tidak ada sama-sama 403");
    $assert($svc->daftar($x, ['santri_id' => $sLuar])['total'] === 0 && $svc->daftar($x, ['santri_id' => $sLuar])['ringkasan']['kunjungan'] === 0,
        "$u: penyaring santri di luar cakupan → hasil kosong");
}
$assert($tolak(static fn () => $svc->detail($pembimbing, $idB, 'web')) === 403 && $tolak(static fn () => $svc->detail($pembimbingB, $id, 'web')) === 403, 'Silang pembimbing A↔B ditolak 403');
$dp = $svc->detail($pembimbing, $id, 'web');
$assert($dp['keluhan'] !== '' && count($dp['obat']) === 2 && $dp['akses']['catat'] === false && $dp['akses']['riwayat_versi'] === false, 'Pembimbing membaca rincian internal terbaru (hanya baca)');
$svc->detail($ganda, $idB, 'api');
$svc->detail($ganda, $id, 'web');
$assert((string) $satu("SELECT sumber FROM kesehatan_akses_log WHERE kunjungan_id = $idB AND user_id = {$ganda['id']} ORDER BY id DESC LIMIT 1") === 'pembimbing'
    && (string) $satu("SELECT sumber FROM kesehatan_akses_log WHERE kunjungan_id = $id AND user_id = {$ganda['id']} ORDER BY id DESC LIMIT 1") === 'murobi'
    && (string) $satu("SELECT sumber FROM kesehatan_akses_log WHERE kunjungan_id = $id AND user_id = {$pembimbing['id']} ORDER BY id DESC LIMIT 1") === 'pembimbing',
    'Log akses mencatat sumber hak per baris (pembimbing/murobi)');
// Penyaring tidak memperluas cakupan, angka ringkasan sama dengan baris.
foreach ([['rujukan', 'menunggu'], ['rujukan', 'diketahui'], ['tindak_lanjut', 'terbuka']] as [$kolom, $nilai]) {
    $f = [$kolom => $nilai];
    $lp = $svc->daftar($pembimbingB, $f);
    $assert(array_diff(array_column($lp['rows'], 'id'), [$idB]) === [] && $lp['ringkasan']['kunjungan'] === $lp['total'], "Penyaring $kolom=$nilai pembimbing B tetap dalam cakupan, ringkasan = baris");
}
$lt = $svc->daftar($petugas, ['tindak_lanjut' => 'terbuka']);
$tlTerbuka = (int) $satu("SELECT COUNT(DISTINCT k.id) FROM kesehatan_kunjungan k JOIN kesehatan_tindak_lanjut t ON t.kunjungan_id = k.id AND t.versi_dibatalkan IS NULL AND t.status = 'Terbuka'");
$assert($lt['total'] === $tlTerbuka && in_array($idLuar, array_column($lt['rows'], 'id'), true), 'Penyaring tindak lanjut terbuka (petugas) = hitungan sumber');
$lr = $svc->daftar($petugas, ['rujukan' => 'menunggu']);
$assert(in_array($idB, array_column($lr['rows'], 'id'), true) && !in_array($id, array_column($lr['rows'], 'id'), true), 'Penyaring rujukan menunggu: kunjungan B ya, kunjungan A (hasil diketahui) tidak');
$ld = $svc->daftar($petugas, ['dari' => date('Y-m-d', strtotime('+1 day'))]);
$assert($ld['total'] === 0 && $svc->daftar($petugas, ['dari' => substr($waktu, 0, 10), 'sampai' => substr($waktu, 0, 10)])['total'] >= 3, 'Penyaring tanggal dari/sampai');
$assert($svc->daftar($petugas, ['dari' => '2026-02-31', 'rujukan' => 'xx'])['filter']['dari'] === '', 'Penyaring tidak valid diabaikan');

// ===================================================================== J-8
echo PHP_EOL . '=== J-8. Tulis ditolak bagi pembaca dan admin ===' . PHP_EOL;
$versiKini = (int) $satu("SELECT versi FROM kesehatan_kunjungan WHERE id = $id");
$sebelum = [$jumlah('kesehatan_kunjungan'), $jumlah('kesehatan_kunjungan_versi'), $jumlah('kesehatan_obat'), $jumlah('kesehatan_rujukan'), $jumlah('kesehatan_idempotensi')];
$dKini = $svc->detail($petugas, $id, 'web');
foreach (['sbx_v5_pembimbing', 'sbx_v5_murobi', 'sbx_v5_ganda', 'sbx_v5_murobi_kosong', 'sbx_admin', 'sbx_v5_admin_petugas', 'sbx_pengurus_biasa', 'sbx_ortu_a'] as $u) {
    $x = $akun($u);
    $assert($tolak(static fn () => $svc->buat($x, ['idempotency_key' => $kunci('j8'), 'santri_id' => $sA, 'waktu_kunjungan' => $waktu, 'keluhan' => 'x'], 'api')) === 403
        && $tolak(static fn () => $svc->koreksi($x, $id, array_merge($keadaan($dKini), ['versi' => $versiKini, 'alasan' => 'Percobaan tulis', 'keluhan' => 'diubah', 'idempotency_key' => $kunci('j8')]), 'api')) === 403
        && $tolak(static fn () => $svc->hasilRujukan($x, $idB, (int) $satu("SELECT id FROM kesehatan_rujukan WHERE kunjungan_id = $idB"), ['versi' => 1, 'alasan' => 'Percobaan tulis', 'hasil' => 'x', 'idempotency_key' => $kunci('j8')], 'api')) === 403,
        "$u: catat/koreksi/hasil rujukan → 403");
}
$assert([$jumlah('kesehatan_kunjungan'), $jumlah('kesehatan_kunjungan_versi'), $jumlah('kesehatan_obat'), $jumlah('kesehatan_rujukan'), $jumlah('kesehatan_idempotensi')] === $sebelum
    && (int) $satu("SELECT versi FROM kesehatan_kunjungan WHERE id = $id") === $versiKini, 'Seluruh percobaan tulis: 0 baris/versi/klaim baru');
foreach (['sbx_v5_pembimbing', 'sbx_v5_murobi', 'sbx_v5_ganda'] as $u) {
    $assert($tolak(static fn () => $svc->riwayatVersi($akun($u), $id, 'web')) === 403 && $tolak(static fn () => $svc->riwayatAkses($akun($u), $id)) === 403, "$u: riwayat versi/akses → 403");
}
$assert($tolak(static fn () => $svc->riwayatAkses($petugas, $id)) === 403 && $svc->riwayatVersi($petugas, $id, 'api')['versi'] !== [], 'Petugas membaca riwayat versi, bukan riwayat akses');
$assert($tolak(static fn () => $svc->santriPilihan($pembimbing, '')) === 403, 'Pembimbing tidak membuka pemilih santri formulir (403)');
$assert(str_contains($pesanTolak(static fn () => $svc->koreksi($pembimbing, $id, [], 'web')), 'hanya membaca'), 'Pesan penolakan pembaca binaan jelas');

// ===================================================================== J-9
echo PHP_EOL . '=== J-9. Perubahan cakupan pada sesi lama ===' . PHP_EOL;
$sesiPembimbing = $pembimbing;   // objek pengguna lama dipakai ulang (sesi/token lama)
$aP = (int) $satu("SELECT id FROM pembimbing_assignments WHERE pengurus_id = (SELECT pengurus_id FROM users WHERE username='sbx_v5_pembimbing')");
$assert($svc->detail($sesiPembimbing, $id, 'web')['id'] === $id, 'Sebelum: pembimbing membuka detail');
$jalankan("UPDATE pembimbing_assignments SET is_active = 0 WHERE id = $aP");
$assert($tolak(static fn () => $svc->detail($sesiPembimbing, $id, 'web')) === 403 && $tolak(static fn () => $svc->daftar($sesiPembimbing, [])) === 403, 'Penugasan pembimbing dinonaktifkan → detail & daftar segera 403');
$jalankan("UPDATE pembimbing_assignments SET is_active = 1, tanggal_selesai = DATE_SUB(CURDATE(), INTERVAL 1 DAY), tanggal_mulai = DATE_SUB(CURDATE(), INTERVAL 30 DAY) WHERE id = $aP");
$assert($tolak(static fn () => $svc->detail($sesiPembimbing, $id, 'web')) === 403, 'Penugasan diakhiri kemarin → detail 403');
$jalankan("UPDATE pembimbing_assignments SET tanggal_selesai = NULL WHERE id = $aP");
$assert($svc->detail($sesiPembimbing, $id, 'web')['id'] === $id, 'Dipulihkan → detail terbuka lagi');
$kamarA = (int) $satu("SELECT id FROM kamar WHERE nama_kamar = 'SBX V5 Kamar A'");
$kamarB = (int) $satu("SELECT id FROM kamar WHERE nama_kamar = 'SBX V5 Kamar B'");
$jalankan("UPDATE plotting_kamar SET id_kamar = $kamarB WHERE id_santri = $sA AND id_kamar = $kamarA");
$assert($tolak(static fn () => $svc->detail($sesiPembimbing, $id, 'web')) === 403 && $svc->daftar($sesiPembimbing, [])['total'] === 0
    && $svc->detail($pembimbingB, $id, 'web')['id'] === $id, 'Santri pindah kamar → pembimbing lama tertutup, pembimbing kamar baru terbuka');
$jalankan("UPDATE plotting_kamar SET id_kamar = $kamarA WHERE id_santri = $sA AND id_kamar = $kamarB");
$jalankan("UPDATE plotting_kelas SET status = 'Selesai' WHERE id_santri = $sA");
$assert($tolak(static fn () => $svc->detail($murobi, $id, 'web')) === 403 && $svc->detail($pembimbing, $id, 'web')['id'] === $id, 'Penempatan kelas selesai → murobi tertutup, pembimbing kamar tetap');
$jalankan("UPDATE plotting_kelas SET status = 'Aktif' WHERE id_santri = $sA");
$jalankan("UPDATE pengurus SET is_active = 0 WHERE id = (SELECT pengurus_id FROM users WHERE username='sbx_v5_pembimbing')");
$assert($tolak(static fn () => $svc->detail($sesiPembimbing, $id, 'web')) === 403, 'Master pengurus nonaktif → detail 403');
$jalankan("UPDATE pengurus SET is_active = 1 WHERE id = (SELECT pengurus_id FROM users WHERE username='sbx_v5_pembimbing')");
$jalankan("UPDATE users SET is_active = 0 WHERE username = 'sbx_v5_murobi'");
$assert($tolak(static fn () => $svc->daftar($murobi, [])) === 403 && $tolak(static fn () => $svc->detail($murobi, $id, 'api')) === 403, 'Akun murobi nonaktif → daftar & detail 403');
$jalankan("UPDATE users SET is_active = 1 WHERE username = 'sbx_v5_murobi'");
$jalankan("UPDATE santri SET is_active = 0 WHERE id = $sA");
$assert($tolak(static fn () => $svc->detail($sesiPembimbing, $id, 'web')) === 403 && $svc->detail($petugas, $id, 'web')['id'] === $id, 'Santri nonaktif → pembaca binaan tertutup; petugas tetap membaca riwayat');
$jalankan("UPDATE santri SET is_active = 1 WHERE id = $sA");
$jalankan("DELETE FROM user_roles WHERE user_id = {$murobi['id']} AND role_id = (SELECT id FROM roles WHERE slug = 'guru')");
$assert($tolak(static fn () => $svc->detail($murobi, $id, 'web')) === 403, 'Role guru dicabut → murobi tertutup');
$jalankan("INSERT INTO user_roles (user_id, role_id) SELECT {$murobi['id']}, id FROM roles WHERE slug = 'guru'");
$assert($svc->detail($murobi, $id, 'web')['id'] === $id && $svc->detail($pembimbing, $id, 'web')['id'] === $id, 'Seluruh cakupan dipulihkan');

// ===================================================================== J-10
echo PHP_EOL . '=== J-10. Penugasan petugas dicabut saat sesi koreksi terbuka ===' . PHP_EOL;
$petugasE = $akun('sbx_v5_petugas_e');
$dE = $svc->detail($petugasE, $idB, 'web');
$tE = (int) $satu("SELECT id FROM kesehatan_assignments WHERE pengurus_id = (SELECT pengurus_id FROM users WHERE username='sbx_v5_petugas_e')");
$jalankan("UPDATE kesehatan_assignments SET is_active = 0 WHERE id = $tE");
$versiB = (int) $satu("SELECT versi FROM kesehatan_kunjungan WHERE id = $idB");
$assert($tolak(static fn () => $svc->koreksi($petugasE, $idB, array_merge($keadaan($dE), ['versi' => $dE['versi'], 'alasan' => 'Koreksi setelah dicabut', 'keluhan' => 'diubah', 'idempotency_key' => $kunci('j10')]), 'web')) === 403
    && (int) $satu("SELECT versi FROM kesehatan_kunjungan WHERE id = $idB") === $versiB && $jumlah('kesehatan_idempotensi', "kunci LIKE 'j10-%'") === 0,
    'Koreksi dari formulir yang masih terbuka ditolak 403, tanpa versi/klaim');
$assert($tolak(static fn () => $svc->detail($petugasE, $idB, 'web')) === 403, 'Baca juga tertutup setelah pencabutan');
$jalankan("UPDATE kesehatan_assignments SET is_active = 1 WHERE id = $tE");

// ===================================================================== J-11
echo PHP_EOL . '=== J-11. Privasi ===' . PHP_EOL;
$dump = json_encode([$svc->daftar($petugas, []), $svc->daftar($pembimbing, []), $svc->daftar($admin, ['santri_id' => $sA])], JSON_UNESCAPED_UNICODE);
$assert(!str_contains((string) $dump, $penanda) && !preg_match('/"(keluhan|catatan_klinis|tindakan|nama_obat|petunjuk|tujuan|hasil|uraian)"/', (string) $dump), 'Daftar (petugas/pembimbing/admin) tanpa isi klinis maupun rincian');
$assert((int) $satu("SELECT COUNT(*) FROM audit_logs WHERE CONCAT(COALESCE(before_json,''), COALESCE(after_json,'')) LIKE '%$penanda%'") === 0, 'audit_logs memuat 0 penanda isi klinis/rincian/alasan');
$assert((int) $satu("SELECT COUNT(*) FROM audit_logs WHERE action = 'kesehatan.kunjungan.dikoreksi' AND after_json LIKE '%bagian_berubah%'") >= 4
    && (int) $satu("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'kesehatan.%' AND CONCAT(COALESCE(before_json,''), COALESCE(after_json,'')) REGEXP '\"(keluhan|catatan_klinis|tindakan|alasan|nama_obat|petunjuk|tujuan|hasil|uraian)\"'") === 0,
    'Audit koreksi hanya metadata (versi, status, kode bagian, jumlah rincian)');
$aksesLog = (string) json_encode($db->query('SELECT * FROM kesehatan_akses_log')->fetch_all(MYSQLI_ASSOC));
$idem = (string) json_encode($db->query('SELECT * FROM kesehatan_idempotensi')->fetch_all(MYSQLI_ASSOC));
$assert(!str_contains($aksesLog, $penanda) && !str_contains($idem, $penanda), 'Log akses dan klaim idempotensi tanpa isi');
$assert((int) $satu("SELECT COUNT(*) FROM kesehatan_akses_log WHERE aksi = 'baca_versi'") >= 3, 'Pembacaan riwayat versi tercatat (aksi baca_versi)');
$pesan = $pesanTolak(static fn () => $svc->buat($petugas, array_merge($isi, ['idempotency_key' => $kunci('j11'), 'obat' => [['nama_obat' => str_repeat('Z', 201)]]]), 'web'));
$assert($pesan !== '' && !str_contains($pesan, 'ZZZ') && !str_contains($pesan, $penanda), 'Pesan galat validasi tanpa nilai masukan');

// ===================================================================== J-12
echo PHP_EOL . '=== J-12. Tanpa stok; tabel lain tidak berubah ===' . PHP_EOL;
$assert((int) $satu("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND (TABLE_NAME LIKE '%stok%' OR TABLE_NAME LIKE '%persediaan%' OR TABLE_NAME LIKE '%inventory%')") === 0,
    'Tidak ada tabel stok/persediaan');
$sesudahLain = $hitungLain();
$selisih = array_keys(array_filter($sesudahLain, static fn (int $n, string $t): bool => ($sebelumLain[$t] ?? -1) !== $n, ARRAY_FILTER_USE_BOTH));
$assert($selisih === [], 'Seluruh tabel non-kesehatan (termasuk PSB, pembayaran, izin, santri, wali) tidak berubah jumlah baris' . ($selisih === [] ? '' : ': ' . implode(', ', $selisih)));
$assert(array_diff_key($sesudahLain, $sebelumLain) === [], 'Tidak ada tabel baru di luar kesehatan_*');
$assert($checksumLain() === $sebelumChecksum, 'Checksum tabel PSB (pendaftar, pembayaran, penugasan), izin, wali, santri_wali identik sebelum–sesudah');
exec('DB_NAME=' . escapeshellarg($dbName) . ' php ' . escapeshellarg(dirname(__DIR__) . '/bin/v5_phase2_verify.php') . ' 2>&1', $out, $kode);
$assert($kode === 0, 'Verifier 025 lulus atas data uji (versi monoton, snapshot, alasan, rincian = snapshot, 0 yatim)' . ($kode === 0 ? '' : ': ' . implode(' | ', array_filter($out, static fn (string $l): bool => str_starts_with($l, '[gagal]')))));

array_map('unlink', glob($scratch . '/*') ?: []);
@rmdir($scratch);
echo PHP_EOL . 'Lulus: ' . $lulus . ' · Gagal: ' . count($gagal) . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
