<?php

declare(strict_types=1);

/**
 * Fixture sintetis redesain website Tahap 2 (navigasi, peran & Beranda).
 *
 * Dijalankan oleh `tests/redesain_web_tahap2_db.sh` pada DB salinan
 * `test_*_test`. Menolak DB lain. Seluruh akun berawalan `sbx_`, kata sandi
 * sama dengan akun sandbox lain. Tidak memakai data pribadi.
 *
 * Menambah:
 *   - sbx_pendidikan     : pengurus + penugasan Bagian Pendidikan (peran ganda
 *                          Bagian Pendidikan + Pengurus, tanpa pembimbing);
 *   - sbx_guru_mapel     : guru dengan penugasan mapel saja (tanpa jadwal,
 *                          tanpa murobi, tanpa peran perizinan);
 *   - sbx_ganda          : admin + guru (jadwal hari ini, murobi satu kamar);
 *   - sbx_pengurus_biasa : pengurus tanpa penugasan pembimbing (keputusan J);
 *   - sbx_sandi_wajib    : guru tanpa data master dengan kata sandi sementara
 *                          yang wajib diganti (A-3);
 *   - data V4 (rencana kelas, nilai, sikap, bahan dikembalikan), V3 (ambang,
 *     poin, pelanggaran, rekomendasi), PSB, jadwal hari ini, notifikasi
 *     berbagai kategori, dan anak kedua untuk sbx_ortu_a.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

$namaDb = (string) app_config('database.database');
if (!preg_match('/^test_.*_test$/', $namaDb)) {
    fwrite(STDERR, 'Seed Tahap 2 hanya untuk DB test_*_test, bukan ' . $namaDb . PHP_EOL);
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
$val = static function (string $sql) use ($db): mixed {
    $row = $db->query($sql)?->fetch_row();

    return $row[0] ?? null;
};

$tahun = (int) $val("SELECT id FROM tahun_ajaran WHERE status='Aktif' AND archived_at IS NULL LIMIT 1");
$kelas = (int) $val('SELECT id FROM kelas ORDER BY id LIMIT 1');
$hash = (string) $val("SELECT password FROM users WHERE username='sbx_admin'");
$admin = (int) $val("SELECT id FROM users WHERE username='sbx_admin'");
$role = static fn (string $slug): int => (int) $val("SELECT id FROM roles WHERE slug='" . $slug . "'");
$hari = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'][(int) date('w')];
$mulaiTugas = date('Y-m-d', strtotime('-60 days'));

$akun = static function (string $username, string $name, array $roles, array $link = []) use ($q, $hash, $role): int {
    $id = $q('INSERT INTO users (name, username, password, guru_id, pengurus_id, wali_id, is_active, force_password_change) VALUES (?,?,?,?,?,?,1,0)', 'sssiii', [
        $name, $username, $hash, $link['guru_id'] ?? null, $link['pengurus_id'] ?? null, $link['wali_id'] ?? null,
    ]);
    foreach ($roles as $slug) {
        $q('INSERT INTO user_roles (user_id, role_id) VALUES (?,?)', 'ii', [$id, $role($slug)]);
    }

    return $id;
};

// --- Akun ---------------------------------------------------------------
$pendidikan = $akun('sbx_pendidikan', 'SBX Bagian Pendidikan', ['pengurus'], ['pengurus_id' => 86]);
$guruMapel = $akun('sbx_guru_mapel', 'SBX Guru Mapel', ['guru'], ['guru_id' => 205]);
$ganda = $akun('sbx_ganda', 'SBX Admin Guru', ['admin', 'guru'], ['guru_id' => 206]);
$pengurusBiasa = $akun('sbx_pengurus_biasa', 'SBX Pengurus Biasa', ['pengurus'], ['pengurus_id' => 87]);
$sandiWajib = $akun('sbx_sandi_wajib', 'SBX Sandi Wajib', ['guru']);
$q('UPDATE users SET force_password_change = 1 WHERE id = ?', 'i', [$sandiWajib]);
$guruBiasa = (int) $val("SELECT id FROM users WHERE username='sbx_guru_biasa'");
$murobiC = (int) $val("SELECT id FROM users WHERE username='sbx_murobi_c'");
$ortuA = (int) $val("SELECT id FROM users WHERE username='sbx_ortu_a'");

$q('INSERT INTO pendidikan_assignments (pengurus_id, tahun_ajaran_id, jenjang, tanggal_mulai, is_active, created_by) VALUES (86, ?, NULL, ?, 1, ?)', 'isi', [$tahun, $mulaiTugas, $admin]);
$kamarGanda = (int) $val('SELECT id FROM kamar WHERE id NOT IN (SELECT kamar_id FROM murobi_assignments WHERE kamar_id IS NOT NULL) AND id NOT IN (1, 2, 3) ORDER BY id LIMIT 1');
$q("INSERT INTO murobi_assignments (guru_id, tahun_ajaran_id, target_type, kamar_id, tanggal_mulai, is_active, created_by) VALUES (206, ?, 'Kamar', ?, ?, 1, ?)", 'iisi', [$tahun, $kamarGanda, $mulaiTugas, $admin]);

// --- Kelas berisi santri (kamar 1 dan 3 = binaan sbx_pengurus_a) ---------
$santriKelas = [];
$res = $db->query('SELECT pm.id_santri FROM plotting_kamar pm JOIN santri s ON s.id = pm.id_santri
                    WHERE pm.id_kamar IN (1, 3) AND pm.id_tahun = ' . $tahun . ' AND s.is_active = 1 ORDER BY pm.id_santri LIMIT 12');
while ($row = $res->fetch_row()) {
    $santriKelas[] = (int) $row[0];
}
foreach ($santriKelas as $sid) {
    if ((int) $val("SELECT COUNT(*) FROM plotting_kelas WHERE id_santri = $sid AND id_tahun = $tahun") === 0) {
        $q("INSERT INTO plotting_kelas (id_santri, id_kelas, id_tahun, tanggal_mulai, status) VALUES (?,?,?,?, 'Aktif')", 'iiis', [$sid, $kelas, $tahun, $mulaiTugas]);
    }
}
// Satu santri berkelas tetapi tanpa kamar, agar kartu "tanpa kelas atau kamar"
// memuat dua jenis kekurangan sekaligus (koreksi audit F-4).
// Kelas terpisah tanpa rencana V4, sehingga angka penilaian tidak berubah.
$tanpaKamar = $q("INSERT INTO santri (nis, nama_santri, jenis_kelamin, tempat_lahir, tgl_lahir, alamat, desa, kecamatan, kab_kota, provinsi, nama_ayah, nama_ibu, asal_sekolah, sekolah_saat_ini, is_active)
    VALUES ('SBX-T2-TK', 'SBX Santri Tanpa Kamar', 'L', 'Ciamis', '2013-01-01', 'Jl. Contoh', 'Desa', 'Kecamatan', 'Ciamis', 'Jawa Barat', 'Ayah Contoh', 'Ibu Contoh', 'SD Contoh', 'SMP', 1)");
$kelasLain = $q("INSERT INTO kelas (nama_kelas, jenjang, is_active) VALUES ('SBX Kelas 2', 'Tsanawi', 1)");
$q("INSERT INTO plotting_kelas (id_santri, id_kelas, id_tahun, tanggal_mulai, status) VALUES (?,?,?,?, 'Aktif')", 'iiis', [$tanpaKamar, $kelasLain, $tahun, $mulaiTugas]);

$santriKelas = [];
$res = $db->query("SELECT id_santri FROM plotting_kelas WHERE id_kelas = $kelas AND id_tahun = $tahun AND status = 'Aktif' ORDER BY id_santri");
while ($row = $res->fetch_row()) {
    $santriKelas[] = (int) $row[0];
}

// --- V4: rencana, mapel, nilai, sikap, bahan ------------------------------
$mapelFiqih = $q("INSERT INTO mata_pelajaran (kode, nama, kategori) VALUES ('SBX-FQH', 'Fiqih', 'Diniyah')");
$mapelNahwu = $q("INSERT INTO mata_pelajaran (kode, nama, kategori) VALUES ('SBX-NHW', 'Nahwu', 'Diniyah')");
$gmFiqih = $q('INSERT INTO guru_mapel_assignments (guru_id, mata_pelajaran_id, kelas_id, tahun_ajaran_id, tanggal_mulai, created_by) VALUES (205, ?, ?, ?, ?, ?)', 'iiisi', [$mapelFiqih, $kelas, $tahun, $mulaiTugas, $admin]);
$gmNahwu = $q('INSERT INTO guru_mapel_assignments (guru_id, mata_pelajaran_id, kelas_id, tahun_ajaran_id, tanggal_mulai, created_by) VALUES (205, ?, ?, ?, ?, ?)', 'iiisi', [$mapelNahwu, $kelas, $tahun, $mulaiTugas, $admin]);
$rencana = $q("INSERT INTO v4_rencana (tahun_ajaran_id, kelas_id, status, tempat, created_by) VALUES (?, ?, 'Siap', 'Ciamis', ?)", 'iii', [$tahun, $kelas, $admin]);
$rmFiqih = $q('INSERT INTO v4_rencana_mapel (rencana_id, mata_pelajaran_id, guru_mapel_assignment_id, wajib, target_ketuntasan, bobot_harian, bobot_tengah, bobot_akhir) VALUES (?,?,?,1,70,40,30,30)', 'iii', [$rencana, $mapelFiqih, $gmFiqih]);
$rmNahwu = $q('INSERT INTO v4_rencana_mapel (rencana_id, mata_pelajaran_id, guru_mapel_assignment_id, wajib, target_ketuntasan, bobot_harian, bobot_tengah, bobot_akhir) VALUES (?,?,?,1,70,40,30,30)', 'iii', [$rencana, $mapelNahwu, $gmNahwu]);
foreach (array_slice($santriKelas, 0, 5) as $i => $sid) {
    foreach ([['Harian', 1], ['Tengah', 0], ['Akhir', 0]] as [$komponen, $nomor]) {
        $q("INSERT INTO v4_nilai (rencana_mapel_id, santri_id, komponen, nomor_harian, nilai, status, pelaku_user_id, kapasitas) VALUES (?,?,?,?,?, 'Draf', ?, 'guru')", 'iisidi', [$rmFiqih, $sid, $komponen, $nomor, 75 + $i, $guruMapel]);
    }
}
// Sidik sumber dihitung dengan pembacaan yang sama dengan matriks V4
// (`BahanRepository::state()`), sehingga status pemeriksaan berlaku.
$bahanRepo = new App\V4\BahanRepository($db, audit_logger());
$sidikNilai = static fn (int $sid): string => App\V4\BahanRepository::fingerprint($bahanRepo->query(
    'SELECT * FROM v4_nilai WHERE rencana_mapel_id=? AND santri_id=? ORDER BY komponen,nomor_harian', 'ii', [$rmFiqih, $sid]
));
$bahan = static function (int $sid, string $jenis, int $key, string $status, string $hash, string $alasan) use ($q, $rencana, $pendidikan): void {
    $q("INSERT INTO v4_bahan (rencana_id, santri_id, jenis, mapel_key, status, sumber_hash, actor_user_id, kapasitas, alasan, updated_at) VALUES (?,?,?,?,?,?,?, 'pendidikan', ?, NOW() - INTERVAL 2 DAY)", 'iisissis', [$rencana, $sid, $jenis, $key, $status, $hash, $pendidikan, $alasan]);
};
// Dua bahan nilai dikembalikan (sumber belum berubah: tetap perlu perbaikan).
foreach (array_slice($santriKelas, 0, 2) as $sid) {
    $bahan($sid, 'nilai', $rmFiqih, 'Dikembalikan', $sidikNilai($sid), 'Nilai harian perlu dilengkapi');
}
// Satu bahan nilai terverifikasi sah (sumber sama).
if (isset($santriKelas[2])) {
    $bahan($santriKelas[2], 'nilai', $rmFiqih, 'Terverifikasi', $sidikNilai($santriKelas[2]), '');
}
$pengurusA = (int) $val("SELECT id FROM users WHERE username='sbx_pengurus_a'");
// Sikap diserahkan untuk dua santri; santri ketiga belum (Sikap belum diisi).
foreach (array_slice($santriKelas, 0, 2) as $sid) {
    $q("INSERT INTO v4_sikap (santri_id, tahun_ajaran_id, kelas_id, adab, kedisiplinan, tanggung_jawab, pembimbing_user_id, status) VALUES (?,?,?, 'Baik', 'Baik', 'Cukup', ?, 'Dikirim')", 'iiii', [$sid, $tahun, $kelas, $pengurusA]);
}
// Bahan sikap pernah terverifikasi, lalu sumbernya berubah (sidik lama):
// status efektifnya Draf/perlu verifikasi ulang, bukan Terverifikasi (F-3).
$bahan($santriKelas[0], 'sikap', 0, 'Terverifikasi', hash('sha256', 'sumber-lama'), '');

// --- Jadwal pengajian hari ini --------------------------------------------
$jadwal = static function (int $guru, string $waktu, string $mulai, string $selesai, string $fan, string $tempat) use ($q, $tahun, $kelas, $hari, $admin): int {
    return $q("INSERT INTO jadwal_ngaji (id_tahun, waktu_sholat, hari, jam, waktu_mulai, waktu_selesai, jam_migration_status, id_kelas, fan_ilmu, nama_kitab, id_guru, tempat, is_active, created_by) VALUES (?,?,?,?,?,?, 'Berhasil', ?, ?, 'Kitab SBX', ?, ?, 1, ?)", 'isssssisisi', [
        $tahun, $waktu, $hari, substr($mulai, 0, 5) . '-' . substr($selesai, 0, 5), $mulai, $selesai, $kelas, $fan, $guru, $tempat, $admin,
    ]);
};
$j1 = $jadwal(1, "Ba'da Shubuh", '04:45:00', '05:45:00', 'Fathul Qarib', 'Masjid');
$j2 = $jadwal(1, "Ba'da Magrib", '18:15:00', '19:00:00', 'Tafsir Jalalain', 'Masjid');
$jadwal(206, "Ba'da Isya", '23:30:00', '23:59:00', "Ta'lim Muta'allim", 'Asrama');
$jadwal(4, "Ba'da Ashar", '15:30:00', '16:30:00', 'Akhlak', 'Aula');
$q("INSERT INTO pertemuan_pengajian (jadwal_id, tanggal_pertemuan, status, created_by, opened_by, completed_by, opened_at, completed_at) VALUES (?, CURDATE(), 'Selesai', ?, ?, ?, NOW(), NOW())", 'iiii', [$j1, $admin, $admin, $admin]);

// --- V3: ambang, poin, pelanggaran, rekomendasi (binaan sbx_pengurus_a) ----
$santriBinaan = (int) $val('SELECT pm.id_santri FROM plotting_kamar pm WHERE pm.id_kamar = 1 AND pm.id_tahun = ' . $tahun . ' ORDER BY pm.id_santri LIMIT 1');
$kategori = $q("INSERT INTO v3_kategori (kode, nama, tanggal_mulai, created_by) VALUES ('SBX-DIS', 'Kedisiplinan', ?, ?)", 'si', [$mulaiTugas, $admin]);
$katalog = $q("INSERT INTO v3_katalog (kode, nama, kategori_id, tingkat, poin_default, tanggal_mulai, created_by) VALUES ('SBX-TLT', 'Terlambat kembali', ?, 'Sedang', 15, ?, ?)", 'isi', [$kategori, $mulaiTugas, $admin]);
$ambang = $q("INSERT INTO v3_ambang (tahun_ajaran_id, nilai_minimum, label, rekomendasi, tanggal_mulai, created_by) VALUES (?, 10, 'Perlu pembinaan', 'Lakukan konseling dengan wali.', ?, ?)", 'isi', [$tahun, $mulaiTugas, $admin]);
$pelanggaran = $q("INSERT INTO v3_pelanggaran (santri_id, tahun_ajaran_id, pembimbing_id, pembimbing_assignment_id, katalog_id, waktu_kejadian, tempat, uraian, kategori_snapshot, tingkat_snapshot, poin_snapshot, status, created_by) VALUES (?, ?, 1, 1, ?, NOW() - INTERVAL 1 HOUR, 'Asrama', 'Terlambat kembali dari izin (sintetis).', 'Kedisiplinan', 'Sedang', 15, 'Dicatat', ?)", 'iiii', [$santriBinaan, $tahun, $katalog, $pengurusA]);
$q('INSERT INTO v3_poin_agregat (santri_id, tahun_ajaran_id, total_poin, direkonsiliasi_pada, created_by) VALUES (?, ?, 15, NOW(), ?)', 'iii', [$santriBinaan, $tahun, $pengurusA]);
$q("INSERT INTO v3_rekomendasi (santri_id, tahun_ajaran_id, ambang_id, dipicu_oleh_pelanggaran_id, total_poin_snapshot, label_snapshot, rekomendasi_snapshot, status, event_key, created_by, created_at) VALUES (?, ?, ?, ?, 15, 'Perlu pembinaan', 'Lakukan konseling dengan wali.', 'Baru', ?, ?, NOW() - INTERVAL 2 DAY)", 'iiiisi', [$santriBinaan, $tahun, $ambang, $pelanggaran, 'sbx-tahap2-rekomendasi-' . $santriBinaan, $pengurusA]);

// --- PSB -----------------------------------------------------------------
foreach ([['SBX-T2-001', 'Calon Santri Satu', 3, 'Baru', 1], ['SBX-T2-002', 'Calon Santri Dua', 0, 'Baru', 2], ['SBX-T2-003', 'Calon Santri Tiga', 0, 'Diterima', 3]] as [$no, $nama, $hariLalu, $status, $urut]) {
    $nisn = sprintf('990000%04d', $urut);
    $nik = sprintf('99000000000%05d', $urut);
    $q("INSERT INTO psb_pendaftar (no_pendaftaran, tgl_daftar, nama_lengkap, nisn, nik, tempat_lahir, tgl_lahir, jenis_kelamin, alamat_jalan, desa, kecamatan, kab_kota, provinsi, nama_ayah, nama_ibu, no_hp_wali, sekolah_asal, jenjang_tujuan, status)
        VALUES (?, NOW() - INTERVAL ? DAY, ?, ?, ?, 'Ciamis', '2014-01-01', 'L', 'Jl. Contoh', 'Desa', 'Kecamatan', 'Ciamis', 'Jawa Barat', 'Ayah Contoh', 'Ibu Contoh', '080000000000', 'SD Contoh', 'SMP Terpadu', ?)", 'sissss', [$no, $hariLalu, $nama, $nisn, $nik, $status]);
}
$q("INSERT INTO psb_pembayaran (no_pendaftaran, kategori_biaya, syahriyyah, infaq, rincian_wajib, total_wajib, total_keseluruhan, status_pembayaran, tanggal) VALUES ('SBX-T2-003', 'SMP Terpadu', 100000, 50000, '[]', 150000, 150000, 'Belum Lunas', NOW() - INTERVAL 3 DAY)");

// --- Notifikasi berbagai kategori -----------------------------------------
$notif = static function (int $penerima, string $event, string $judul, string $isi, string $data, string $kanal = 'InApp', string $status = 'Sent', ?string $dibaca = null, int $hariLalu = 0) use ($q): void {
    $q('INSERT INTO notifikasi_outbox (event_key, event_type, kanal, penerima_user_id, judul, isi, data_json, status, dibaca_pada, created_at, updated_at, tersedia_pada) VALUES (?,?,?,?,?,?,?,?,?, NOW() - INTERVAL ? DAY, NOW() - INTERVAL ? DAY, NOW())', 'sssisssssii', [
        'sbx-t2:' . $penerima . ':' . $event . ':' . md5($judul . $kanal), $event, $kanal, $penerima, $judul, $isi, $data, $status, $dibaca, $hariLalu, $hariLalu,
    ]);
};
$notif($guruMapel, 'v4_rapor_terbit', 'Bahan nilai Fiqih dikembalikan', 'Bagian Pendidikan meminta nilai harian dilengkapi.', '{"tipe":"sistem"}');
$notif($guruMapel, 'v3_publikasi_terbit', 'Pengumuman pembinaan', 'Contoh notifikasi pembinaan untuk guru mapel.', '{"tipe":"sistem"}', 'InApp', 'Sent', date('Y-m-d H:i:s'), 3);
$notif($pendidikan, 'v4_rapor_terbit', 'Rapor kelas siap diperiksa', 'Bahan rapor SBX Kelas 1 sudah lengkap sebagian.', '{"tipe":"sistem"}');
$notif($ortuA, 'v4_rapor_terbit', 'Rapor semester tersedia', 'Rapor semester anak Anda dapat dibuka.', '{"tipe":"sistem"}');
$notif($ortuA, 'v3_publikasi_terbit', 'Informasi pembinaan baru', 'Ada informasi pembinaan untuk anak Anda.', '{"tipe":"sistem"}', 'InApp', 'Sent', null, 1);
$notif($admin, 'izin.pengajuan_dibuat', 'Push gagal terkirim (sintetis)', 'Pengiriman push gagal.', '{"tipe":"sistem"}', 'Push', 'Failed', null, 2);
$notif($admin, 'izin.keputusan_disetujui', 'WhatsApp gagal terkirim (sintetis)', 'Pengiriman gagal.', '{"tipe":"sistem"}', 'WhatsApp', 'Failed');

// --- Anak kedua untuk sbx_ortu_a -----------------------------------------
$anakKedua = (int) $val('SELECT s.id FROM santri s WHERE s.is_active = 1 AND s.id NOT IN (SELECT santri_id FROM santri_wali) ORDER BY s.id LIMIT 1');
$q("INSERT INTO santri_wali (santri_id, wali_id, hubungan, is_primary) VALUES (?, 1, 'Ayah', 0)", 'i', [$anakKedua]);

$db->commit();

echo json_encode([
    'db' => $namaDb,
    'akun' => compact('pendidikan', 'guruMapel', 'ganda', 'pengurusBiasa', 'sandiWajib'),
    'rencana' => $rencana,
    'santri_kelas' => count($santriKelas),
    'jadwal_hari' => $hari,
    'santri_binaan_poin' => $santriBinaan,
    'anak_kedua' => $anakKedua,
], JSON_PRETTY_PRINT) . PHP_EOL;
