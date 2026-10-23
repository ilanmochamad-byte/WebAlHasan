<?php

declare(strict_types=1);

/**
 * Fixture sintetis redesain website Tahap 3 (halaman lama ke kerangka baru).
 *
 * Dijalankan oleh `tests/redesain_web_tahap3_db.sh` setelah fixture Tahap 2,
 * pada DB salinan `test_*_test`. Menolak DB lain. Tidak memakai data pribadi
 * dan TIDAK membuat berkas di folder unggahan; baris berita/galeri/unduhan
 * menunjuk nama berkas sintetis yang tidak ada.
 *
 * Menambah (semua berawalan SBX-T3 / sbx-t3):
 *   - 30 pendaftar PSB berbagai status/unit/jenis kelamin (cukup untuk dua
 *     halaman daftar) + tagihan untuk 25 di antaranya;
 *   - 25 berita dua bulan, 30 foto galeri, 22 dokumen unduhan;
 *   - 30 catatan pelanggaran sistem lama;
 *   - 24 pengiriman gagal Push/WhatsApp dan 25 baris audit kanal;
 *   - satu judul/isian berisi tag HTML untuk uji escape.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

$namaDb = (string) app_config('database.database');
if (!preg_match('/^test_.*_test$/', $namaDb)) {
    fwrite(STDERR, 'Seed Tahap 3 hanya untuk DB test_*_test, bukan ' . $namaDb . PHP_EOL);
    exit(2);
}

$db = app_db();
$db->begin_transaction();
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
$val = static fn (string $sql): mixed => $db->query($sql)?->fetch_row()[0] ?? null;
$admin = (int) $val("SELECT id FROM users WHERE username='sbx_admin'");

// --- PSB -----------------------------------------------------------------
$unit = ['SMP Terpadu Al Hasan', 'SMK Terpadu Al Hasan', 'RA Terpadu Al Hasan', 'SMAN 1 Ciamis', 'MAN 2 Ciamis'];
$status = ['Baru', 'Baru', 'Diterima', 'Cadangan', 'Ditolak', 'Dimigrasi'];
$kec = ['Ciamis', 'Cikoneng', 'Sadananya'];
$psb = [];
for ($i = 1; $i <= 30; $i++) {
    $no = sprintf('SBX-T3-%03d', $i);
    $nama = $i === 7 ? 'SBX T3 <b>Tebal</b> Pendaftar 007' : sprintf('SBX T3 Pendaftar %03d', $i);
    $st = $status[$i % count($status)];
    $jk = $i % 2 === 0 ? 'P' : 'L';
    $berkas = $i % 3 === 0 ? 'sbx-t3-kk-' . $i . '.jpg' : null;
    $psb[$no] = $q("INSERT INTO psb_pendaftar (no_pendaftaran, tgl_daftar, nama_lengkap, nisn, nik, tempat_lahir, tgl_lahir, jenis_kelamin, alamat_jalan, desa, kecamatan, kab_kota, provinsi, nama_ayah, nama_ibu, no_hp_wali, sekolah_asal, jenjang_tujuan, status, file_kk, file_akta)
        VALUES (?, NOW() - INTERVAL ? HOUR, ?, ?, ?, 'Ciamis', '2013-05-01', ?, 'Jl. Sintetis', 'Desa Sintetis', ?, 'Ciamis', 'Jawa Barat', 'Ayah Sintetis', 'Ibu Sintetis', '081200000000', 'SD Sintetis', ?, ?, ?, ?)",
        'sisssssssss', [$no, $i * 5, $nama, sprintf('9930%06d', $i), sprintf('99300000000%05d', $i), $jk, $kec[$i % 3], $unit[$i % count($unit)], $st, $berkas, $berkas]);
    if ($i <= 25) {
        $lunas = $i % 4 === 0;
        $wajib = json_encode(['Formulir' => 100000, 'Kitab' => 50000 * ($i % 3 + 1)]);
        $total = 300000 + 100000 + 50000 * ($i % 3 + 1) + ($i % 2 === 0 ? 75000 : 0);
        $q("INSERT INTO psb_pembayaran (no_pendaftaran, kategori_biaya, syahriyyah, infaq, seragam_psas, seragam_pramuka, rincian_wajib, total_wajib, total_keseluruhan, metode_pembayaran, status_pembayaran, waktu_lunas, tanggal)
            VALUES (?, 'Reguler', 300000, ?, 0, 0, ?, ?, ?, ?, ?, ?, NOW() - INTERVAL ? HOUR)", 'sisiisssi', [
            $no, $i % 2 === 0 ? 75000 : 0, $wajib, 100000 + 50000 * ($i % 3 + 1), $total, $i % 3 === 0 ? 'Transfer' : 'Cash',
            $lunas ? 'Lunas' : 'Belum Lunas', $lunas ? date('Y-m-d H:i:s') : null, $i * 5,
        ]);
    }
}

// --- Konten website --------------------------------------------------------
for ($i = 1; $i <= 25; $i++) {
    $judul = $i === 3 ? 'SBX T3 Berita <script>x</script> tiga' : sprintf('SBX T3 Berita %02d', $i);
    $q("INSERT INTO berita (judul, isi_berita, gambar, tanggal) VALUES (?, '<p>Isi berita sintetis.</p>', ?, CURDATE() - INTERVAL ? DAY)", 'ssi', [$judul, 'sbx-t3-berita-' . $i . '.jpg', $i * 3]);
}
for ($i = 1; $i <= 30; $i++) {
    $q("INSERT INTO galeri (judul, keterangan, gambar, tanggal) VALUES (?, 'Keterangan sintetis', ?, CURDATE() - INTERVAL ? DAY)", 'ssi', [sprintf('SBX T3 Foto %02d', $i), 'sbx-t3-foto-' . $i . '.jpg', $i]);
}
for ($i = 1; $i <= 22; $i++) {
    $q("INSERT INTO download (judul, nama_file, tanggal) VALUES (?, ?, CURDATE() - INTERVAL ? DAY)", 'ssi', [sprintf('SBX T3 Dokumen %02d', $i), 'sbx-t3-dok-' . $i . ($i % 2 ? '.pdf' : '.docx'), $i]);
}

// --- Data sistem lama: pelanggaran ----------------------------------------
$santri = array_map('intval', array_column($db->query('SELECT id FROM santri ORDER BY id LIMIT 5')->fetch_all(MYSQLI_ASSOC), 'id'));
for ($i = 1; $i <= 30; $i++) {
    $jenis = $i === 2 ? 'SBX T3 <script>lama</script>' : 'SBX T3 jenis lama ' . $i;
    $q("INSERT INTO pelanggaran (id_santri, tgl_pelanggaran, jenis_pelanggaran, poin, hukuman) VALUES (?, ?, ?, ?, 'Hukuman sintetis')", 'issi',
        [$santri[$i % count($santri)], ($i % 2 ? '2025' : '2024') . '-0' . (($i % 9) + 1) . '-1' . ($i % 9), $jenis, $i]);
}

// --- Notifikasi: pengiriman gagal dan audit kanal --------------------------
for ($i = 1; $i <= 24; $i++) {
    $kanal = $i % 3 === 0 ? 'WhatsApp' : 'Push';
    $q("INSERT INTO notifikasi_outbox (event_key, event_type, kanal, penerima_user_id, judul, isi, data_json, status, percobaan, error_kode, error_terakhir, gagal_permanen, created_at, updated_at, tersedia_pada)
        VALUES (?, 'izin.pengajuan_dibuat', ?, ?, 'SBX T3 gagal', 'Isi sintetis', '{\"tipe\":\"sistem\"}', 'Failed', 3, 'SBX_GAGAL', 'Galat sintetis', ?, NOW() - INTERVAL ? HOUR, NOW() - INTERVAL ? HOUR, NOW())",
        'ssiiii', ['sbx-t3-gagal-' . $i, $kanal, $admin, $i % 5 === 0 ? 1 : 0, $i, $i]);
}
for ($i = 1; $i <= 25; $i++) {
    $q("INSERT INTO notifikasi_pengaturan_audit (aksi, kanal, nilai_sebelum, nilai_sesudah, hasil, pesan, aktor_user_id, created_at) VALUES ('pemeriksaan_konfigurasi', 'Push', NULL, NULL, 'Lulus', ?, ?, NOW() - INTERVAL ? HOUR)",
        'sii', ['SBX T3 audit ' . $i, $admin, $i]);
}

$db->commit();
echo json_encode(['db' => $namaDb, 'psb' => count($psb), 'contoh_psb' => $psb['SBX-T3-002'], 'tahap' => 3], JSON_PRETTY_PRINT) . PHP_EOL;
