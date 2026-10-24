<?php

declare(strict_types=1);

/**
 * Fixture sintetis PRD V5 Fase 5 (laporan layanan klinik).
 *
 * Dijalankan oleh `tests/v5_phase5_db.sh` SESUDAH fixture Fase 3 pada DB
 * salinan `test_*_test` (migrasi 024–026). Menolak DB lain. Seluruh isi
 * klinis berawalan penanda `V5F5RHS` untuk pemindaian kebocoran.
 *
 * Kunjungan dibuat LEWAT LAYANAN (bukan INSERT langsung) oleh
 * sbx_v5_petugas_f sehingga versi, snapshot rincian, idempotensi, audit,
 * ringkasan, dan outbox persis seperti produksi. Periode laporan fixture:
 * September 2026 (tanggal tetap di masa lalu), ditambah satu kunjungan
 * Agustus 2026 di luar periode.
 *
 *   santri V5S0501 "SBX V5 Lapor A"    : Kamar A + Kelas X → sbx_v5_pembimbing, sbx_v5_murobi, sbx_v5_ganda
 *   santri V5S0502 "SBX V5 Lapor B"    : Kamar B + Kelas Y (baru) → sbx_v5_pembimbing_b, sbx_v5_ganda
 *   santri V5S0503 "SBX V5 Lapor Luar" : tanpa penempatan → hanya petugas/admin
 *
 *   K1 A 2026-09-03 Tercatat: 3 obat, 1 rujukan menunggu, 1 TL terbuka target 2026-09-10 (terlambat); ringkasan Terbit
 *   K2 A 2026-09-10 Tercatat: 1 obat, 1 TL selesai; ringkasan Terbit lalu Ganti (beralasan)
 *   K3 B 2026-09-12 Tercatat: 2 obat, 2 rujukan (1 menunggu, 1 diketahui); ringkasan Terbit lalu Tarik (beralasan)
 *   K4 B 2026-09-15 Draf    : 1 obat, 1 TL terbuka tanpa target
 *   K5 L 2026-09-20 Tercatat: 1 rujukan diketahui, 1 TL terbuka target 2099-01-01
 *   K6 L 2026-08-25 Tercatat: 1 rujukan menunggu (di luar periode)
 *   K7 A 2026-09-25 Tercatat: 2 obat → koreksi beralasan: 1 obat dibatalkan, + 1 TL terbuka tanpa target
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

$namaDb = (string) app_config('database.database');
if (!preg_match('/^test_.*_test$/', $namaDb)) {
    fwrite(STDERR, 'Seed V5 Fase 5 hanya untuk DB test_*_test, bukan ' . $namaDb . PHP_EOL);
    exit(2);
}

$db = app_db();
$q = static function (string $sql, string $types = '', array $params = []) use ($db): int {
    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        throw new RuntimeException($db->error . ' :: ' . $sql);
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    if (!$stmt->execute()) {
        throw new RuntimeException($stmt->error . ' :: ' . $sql);
    }
    $id = (int) $stmt->insert_id;
    $stmt->close();

    return $id;
};
$val = static function (string $sql) use ($db): mixed {
    $row = $db->query($sql)?->fetch_row();

    return $row[0] ?? null;
};
if ((int) $val("SELECT COUNT(*) FROM users WHERE username = 'sbx_v5_petugas_f'") !== 1) {
    fwrite(STDERR, "Fixture V5 Fase 3 belum ada; jalankan tests/v5_phase3_db.sh.\n");
    exit(2);
}
if ((int) $val("SELECT COUNT(*) FROM santri WHERE nis = 'V5S0501'") > 0) {
    fwrite(STDERR, "Fixture V5 Fase 5 sudah ada; bangun ulang DB.\n");
    exit(2);
}

$tahun = (int) $val("SELECT id FROM tahun_ajaran WHERE status='Aktif' AND archived_at IS NULL LIMIT 1");
$admin = (int) $val("SELECT id FROM users WHERE username='sbx_admin'");
$kamarA = (int) $val("SELECT id FROM kamar WHERE nama_kamar='SBX V5 Kamar A'");
$kamarB = (int) $val("SELECT id FROM kamar WHERE nama_kamar='SBX V5 Kamar B'");
$kelasX = (int) $val("SELECT id FROM kelas WHERE nama_kelas='SBX V5 Kelas X'");
$mulai = date('Y-m-d', strtotime('-30 days'));

$db->begin_transaction();
$kelasY = $q("INSERT INTO kelas (nama_kelas, jenjang, is_active) VALUES ('SBX V5 Kelas Y', 'SMP', 1)");
$santri = static fn (string $nis, string $nama): int => $q(
    "INSERT INTO santri (nis, nama_santri, jenis_kelamin, tempat_lahir, tgl_lahir, alamat, desa, kecamatan, kab_kota, provinsi, nama_ayah, nama_ibu, asal_sekolah, sekolah_saat_ini, is_active)
     VALUES (?, ?, 'L', 'Sintetis', '2012-01-01', 'Alamat sintetis', 'Desa', 'Kecamatan', 'Kota', 'Provinsi', 'Ayah sintetis', 'Ibu sintetis', 'Sekolah sintetis', 'Pesantren', 1)",
    'ss', [$nis, $nama]
);
$diKamar = static fn (int $s, int $k): int => $q('INSERT INTO plotting_kamar (id_santri, id_kamar, id_tahun) VALUES (?, ?, ?)', 'iii', [$s, $k, $tahun]);
$diKelas = static fn (int $s, int $k): int => $q("INSERT INTO plotting_kelas (id_santri, id_kelas, id_tahun, tanggal_mulai, status, created_by) VALUES (?, ?, ?, ?, 'Aktif', ?)", 'iiisi', [$s, $k, $tahun, $mulai, $admin]);
$sA = $santri('V5S0501', 'SBX V5 Lapor A');
$sB = $santri('V5S0502', 'SBX V5 Lapor B');
$sL = $santri('V5S0503', 'SBX V5 Lapor Luar');
$diKamar($sA, $kamarA);
$diKelas($sA, $kelasX);
$diKamar($sB, $kamarB);
$diKelas($sB, $kelasY);
$db->commit();

$petugas = ['id' => (int) $val("SELECT id FROM users WHERE username='sbx_v5_petugas_f'"), 'roles' => []];
$klinik = kesehatan_service();
$ringkasan = kesehatan_ringkasan_service();
$n = 0;
$kunci = static function (string $label) use (&$n): string {
    return 'v5f5-seed-' . $label . '-' . (++$n);
};
$P = 'V5F5RHS';
$obat = static fn (string $nama, string $jumlah = '1'): array => ['nama_obat' => $P . '-OBAT ' . $nama, 'jumlah' => $jumlah, 'satuan' => 'tablet', 'petunjuk' => $P . '-PETUNJUK'];
$buat = static function (string $label, int $santri, string $waktu, string $status, array $rincian) use ($klinik, $petugas, $kunci, $P): int {
    return $klinik->buat($petugas, [
        'idempotency_key' => $kunci($label), 'santri_id' => $santri, 'waktu_kunjungan' => $waktu, 'status' => $status,
        'keluhan' => $P . '-KELUHAN ' . $label, 'catatan_klinis' => $P . '-CATATAN ' . $label, 'tindakan' => $P . '-TINDAKAN ' . $label,
    ] + $rincian, 'web')['id'];
};

$k1 = $buat('K1', $sA, '2026-09-03 08:00', 'Tercatat', [
    'obat' => [$obat('Parasetamol'), $obat('Antasida', '2'), $obat('Vitamin C', '0,5')],
    'rujukan' => [['tujuan' => $P . '-TUJUAN Puskesmas', 'tanggal_rujukan' => '2026-09-03', 'alasan' => $P . '-ALASAN-RUJUK']],
    'tindak_lanjut' => [['uraian' => $P . '-TL kontrol ulang', 'tanggal_target' => '2026-09-10']],
]);
$k2 = $buat('K2', $sA, '2026-09-10 09:00', 'Tercatat', [
    'obat' => [$obat('Oralit')],
    'tindak_lanjut' => [['uraian' => $P . '-TL minum air', 'status' => 'Selesai', 'tanggal_selesai' => '2026-09-11']],
]);
$k3 = $buat('K3', $sB, '2026-09-12 10:00', 'Tercatat', [
    'obat' => [$obat('Amoksisilin'), $obat('Ibuprofen')],
    'rujukan' => [
        ['tujuan' => $P . '-TUJUAN RS', 'tanggal_rujukan' => '2026-09-12'],
        ['tujuan' => $P . '-TUJUAN Lab', 'tanggal_rujukan' => '2026-09-13', 'hasil' => $P . '-HASIL normal', 'tanggal_hasil' => '2026-09-14'],
    ],
]);
$k4 = $buat('K4', $sB, '2026-09-15 11:00', 'Draf', [
    'obat' => [$obat('Salep')],
    'tindak_lanjut' => [['uraian' => $P . '-TL draf']],
]);
$k5 = $buat('K5', $sL, '2026-09-20 13:00', 'Tercatat', [
    'rujukan' => [['tujuan' => $P . '-TUJUAN Klinik Gigi', 'tanggal_rujukan' => '2026-09-21', 'hasil' => $P . '-HASIL tambal', 'tanggal_hasil' => '2026-09-22']],
    'tindak_lanjut' => [['uraian' => $P . '-TL kontrol gigi', 'tanggal_target' => '2099-01-01']],
]);
$k6 = $buat('K6', $sL, '2026-08-25 14:00', 'Tercatat', [
    'rujukan' => [['tujuan' => $P . '-TUJUAN Spesialis', 'tanggal_rujukan' => '2026-08-26']],
]);
$k7 = $buat('K7', $sA, '2026-09-25 15:00', 'Tercatat', [
    'obat' => [$obat('Cetirizine'), $obat('Loratadine')],
]);
// Koreksi K7: obat kedua dibatalkan (versi_dibatalkan), tindak lanjut baru.
$d7 = $klinik->detail($petugas, $k7, 'web');
$klinik->koreksi($petugas, $k7, [
    'versi' => $d7['versi'], 'alasan' => $P . '-ALASAN-KOREKSI obat ganda', 'idempotency_key' => $kunci('K7-koreksi'),
    'waktu_kunjungan' => $d7['waktu_kunjungan'], 'keluhan' => $d7['keluhan'], 'catatan_klinis' => $d7['catatan_klinis'], 'tindakan' => $d7['tindakan'],
    'obat' => [$d7['obat'][0]],
    'rujukan' => [],
    'tindak_lanjut' => [['uraian' => $P . '-TL pantau ruam']],
], 'web');

$terbit = static function (int $kid, int $rev, string $teks, ?string $alasan = null) use ($ringkasan, $petugas, $klinik, $kunci): array {
    $versi = (int) $klinik->detail($petugas, $kid, 'web')['versi'];
    $m = ['kunjungan_versi' => $versi, 'revisi_ringkasan' => $rev, 'ringkasan' => $teks, 'tindakan_umum' => 'V5F5RHS-UMUM', 'tindak_lanjut_wali' => null, 'alasan' => $alasan];
    $p = $ringkasan->pratinjau($petugas, $kid, $m);

    return $ringkasan->terbitkan($petugas, $kid, $m + ['konfirmasi' => true, 'pratinjau_hash' => $p['pratinjau_hash'], 'idempotency_key' => $kunci('terbit')], 'web');
};
$terbit($k1, 0, 'V5F5RHS-RINGKASAN K1 untuk wali.');
$terbit($k2, 0, 'V5F5RHS-RINGKASAN K2 versi satu.');
$terbit($k2, 1, 'V5F5RHS-RINGKASAN K2 versi dua.', 'V5F5RHS-ALASAN-GANTI salah ketik');
$terbit($k3, 0, 'V5F5RHS-RINGKASAN K3 untuk wali.');
$ringkasan->tarik($petugas, $k3, ['revisi_ringkasan' => 1, 'alasan' => 'V5F5RHS-ALASAN-TARIK keliru santri', 'idempotency_key' => $kunci('tarik')], 'web');

echo json_encode([
    'db' => $namaDb,
    'santri' => ['a' => $sA, 'b' => $sB, 'luar' => $sL],
    'kelas' => ['x' => $kelasX, 'y' => $kelasY],
    'kunjungan' => compact('k1', 'k2', 'k3', 'k4', 'k5', 'k6', 'k7'),
    'penanda' => $P,
], JSON_PRETTY_PRINT) . PHP_EOL;
