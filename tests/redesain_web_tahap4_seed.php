<?php

declare(strict_types=1);

/**
 * Fixture sintetis redesain website Tahap 4 (perizinan, pengajian, cetak).
 *
 * Dijalankan oleh `tests/redesain_web_tahap4_db.sh` di atas salinan DB Tahap 3
 * (`test_*_test`). Menolak DB lain. Tidak memakai data pribadi.
 *
 * Menambah:
 *   - pertemuan "Tafsir Jalalain" hari ini berstatus Dibuka dengan peserta
 *     yang dibekukan dari kelas jadwalnya, kehadiran guru, dan kehadiran
 *     santri dari "aplikasi" (sebagian belum dicatat) — untuk J-2/J-5;
 *   - izin DISETUJUI yang mencakup hari ini untuk peserta pertama (label
 *     "Sedang izin", keputusan S) beserta keputusannya;
 *   - tiga pengajuan Diajukan yang diarahkan ke sbx_murobi_c (satu menunggu
 *     lebih dari 1 hari, satu beralasan berisi tag HTML untuk uji escape) —
 *     untuk antrean I-3;
 *   - satu izin anak sbx_ortu_a yang masih aktif (I-7).
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

$namaDb = (string) app_config('database.database');
if (!preg_match('/^test_.*_test$/', $namaDb)) {
    fwrite(STDERR, 'Seed Tahap 4 hanya untuk DB test_*_test, bukan ' . $namaDb . PHP_EOL);
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
$admin = (int) $val("SELECT id FROM users WHERE username='sbx_admin'");
$guruUser = (int) $val("SELECT id FROM users WHERE username='sbx_murobi_a'");
$pengurusUser = (int) $val("SELECT id FROM users WHERE username='sbx_pengurus_a'");
$jadwal = $db->query("SELECT id, id_kelas, id_guru FROM jadwal_ngaji WHERE fan_ilmu='Tafsir Jalalain' AND nama_kitab='Kitab SBX' ORDER BY id DESC LIMIT 1")->fetch_assoc();
if (!$jadwal) {
    throw new RuntimeException('Jadwal Tafsir Jalalain fixture Tahap 2 tidak ditemukan.');
}
$hariIni = date('Y-m-d');

// --- J-2/J-5: pertemuan Dibuka berpeserta ---------------------------------
// Enam santri sintetis tambahan di kelas jadwal agar daftar peserta cukup.
for ($i = 1; $i <= 6; $i++) {
    $sid = $q("INSERT INTO santri (nis, nama_santri, jenis_kelamin, tempat_lahir, tgl_lahir, alamat, desa, kecamatan, kab_kota, provinsi, nama_ayah, nama_ibu, asal_sekolah, sekolah_saat_ini, is_active)
        VALUES (?, ?, 'L', 'Ciamis', '2012-01-01', 'Jl. Contoh', 'Desa', 'Kecamatan', 'Ciamis', 'Jawa Barat', 'Ayah Contoh', 'Ibu Contoh', 'SD Contoh', 'SMP', 1)", 'ss', ['SBX-T4-' . $i, 'SBX T4 Santri ' . $i]);
    $q("INSERT INTO plotting_kelas (id_santri, id_kelas, id_tahun, tanggal_mulai, status) VALUES (?,?,?, CURDATE() - INTERVAL 30 DAY, 'Aktif')", 'iii', [$sid, (int) $jadwal['id_kelas'], $tahun]);
}
$pertemuan = $q("INSERT INTO pertemuan_pengajian (jadwal_id, tanggal_pertemuan, status, catatan, created_by, opened_by, opened_at) VALUES (?, ?, 'Dibuka', 'Materi: QS Al-Baqarah 1-5 (sintetis)', ?, ?, NOW() - INTERVAL 30 MINUTE)", 'isii', [(int) $jadwal['id'], $hariIni, $admin, $admin]);
$peserta = [];
$res = $db->query('SELECT pk.id AS plotting_id, s.id, s.nis, s.nama_santri FROM plotting_kelas pk JOIN santri s ON s.id = pk.id_santri
                    WHERE pk.id_kelas = ' . (int) $jadwal['id_kelas'] . ' AND pk.id_tahun = ' . $tahun . " AND pk.status = 'Aktif' ORDER BY s.nama_santri, s.id");
while ($row = $res->fetch_assoc()) {
    $peserta[] = $row;
    $q('INSERT INTO pertemuan_peserta (pertemuan_id, santri_id, plotting_kelas_id, nis_snapshot, nama_santri_snapshot, kelas_id_snapshot, tahun_ajaran_id_snapshot) VALUES (?,?,?,?,?,?,?)', 'iiissii', [
        $pertemuan, (int) $row['id'], (int) $row['plotting_id'], (string) $row['nis'], (string) $row['nama_santri'], (int) $jadwal['id_kelas'], $tahun,
    ]);
}
if (count($peserta) < 4) {
    throw new RuntimeException('Kelas fixture terlalu sedikit pesertanya.');
}
$statusUrut = ['Izin', 'Hadir', 'Terlambat', 'Hadir', 'Sakit', 'Hadir', 'Alpa', 'Hadir'];
foreach (array_slice($peserta, 0, count($peserta) - 2) as $i => $row) {
    $status = $statusUrut[$i % count($statusUrut)];
    $q('INSERT INTO absensi_santri (pertemuan_id, santri_id, status, dicatat_pada, dicatat_oleh, catatan) VALUES (?,?,?, NOW() - INTERVAL 20 MINUTE, ?, ?)', 'iisis', [
        $pertemuan, (int) $row['id'], $status, $guruUser, $status === 'Terlambat' ? 'Datang 18.25' : null,
    ]);
}
$q("INSERT INTO absensi_guru (pertemuan_id, guru_id, status, dicatat_pada, dicatat_oleh) VALUES (?, ?, 'Hadir', NOW() - INTERVAL 25 MINUTE, ?)", 'iii', [$pertemuan, (int) $jadwal['id_guru'], $guruUser]);

// Pertemuan selesai milik guru lain (Akhlak, sbx_guru_biasa) agar laporan
// kehadiran memuat lebih dari satu guru: Bagian Pendidikan (keputusan H) dan
// admin membaca keduanya, guru hanya miliknya.
$jadwalLain = $db->query("SELECT id, id_guru FROM jadwal_ngaji WHERE fan_ilmu='Akhlak' AND nama_kitab='Kitab SBX' ORDER BY id DESC LIMIT 1")->fetch_assoc();
$pertemuanLain = $q("INSERT INTO pertemuan_pengajian (jadwal_id, tanggal_pertemuan, status, created_by, opened_by, completed_by, opened_at, completed_at) VALUES (?, ?, 'Selesai', ?, ?, ?, NOW() - INTERVAL 3 HOUR, NOW() - INTERVAL 2 HOUR)", 'isiii', [(int) $jadwalLain['id'], $hariIni, $admin, $admin, $admin]);
foreach (array_slice($peserta, 2, 4) as $i => $row) {
    $q('INSERT INTO pertemuan_peserta (pertemuan_id, santri_id, plotting_kelas_id, nis_snapshot, nama_santri_snapshot, kelas_id_snapshot, tahun_ajaran_id_snapshot) VALUES (?,?,?,?,?,?,?)', 'iiissii', [
        $pertemuanLain, (int) $row['id'], (int) $row['plotting_id'], (string) $row['nis'], (string) $row['nama_santri'], (int) $jadwal['id_kelas'], $tahun,
    ]);
    $q('INSERT INTO absensi_santri (pertemuan_id, santri_id, status, dicatat_pada, dicatat_oleh) VALUES (?,?,?, NOW() - INTERVAL 150 MINUTE, ?)', 'iisi', [$pertemuanLain, (int) $row['id'], $i === 0 ? 'Alpa' : 'Hadir', $admin]);
}
$q("INSERT INTO absensi_guru (pertemuan_id, guru_id, status, dicatat_pada, dicatat_oleh) VALUES (?, ?, 'Terlambat', NOW() - INTERVAL 160 MINUTE, ?)", 'iii', [$pertemuanLain, (int) $jadwalLain['id_guru'], $admin]);

// --- Keputusan S: izin disetujui yang mencakup hari ini --------------------
$izinBerjalan = $q("INSERT INTO izin_pengajuan (santri_id, pengurus_id, diajukan_oleh_user_id, murobi_guru_id, routing_kandidat, routing_catatan, routing_pada, tahun_ajaran_id, tgl_izin, tgl_kembali, alasan, status, diajukan_pada)
    VALUES (?, 1, ?, 1, 1, 'Diteruskan ke SBX Murobi A.', NOW() - INTERVAL 2 DAY, ?, ?, ?, 'Pulang: acara keluarga (sintetis T4)', 'Disetujui', NOW() - INTERVAL 2 DAY)", 'iiiss', [
    (int) $peserta[0]['id'], $pengurusUser, $tahun, date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('+1 day')),
]);
$q("INSERT INTO izin_keputusan (pengajuan_id, hasil, alasan, diputus_oleh_user_id, kapasitas, diputus_pada) VALUES (?, 'Disetujui', 'Hati-hati di jalan, kembali sebelum Maghrib.', ?, 'Murobi', NOW() - INTERVAL 1 DAY)", 'ii', [$izinBerjalan, $guruUser]);

// --- I-3: antrean sbx_murobi_c (murobi kelas 1, guru 3) -------------------
$antrean = [];
foreach ([
    [1, 'Berobat: kontrol gigi (sintetis T4)', 3],
    [2, 'Pulang: <script>alert("x")</script> & <b>tebal</b>', 0],
    [3, 'Acara keluarga: pernikahan kakak (sintetis T4)', 0],
] as [$urut, $alasan, $hariLalu]) {
    $antrean[] = $q("INSERT INTO izin_pengajuan (santri_id, pengurus_id, diajukan_oleh_user_id, murobi_guru_id, routing_kandidat, routing_catatan, routing_pada, tahun_ajaran_id, tgl_izin, tgl_kembali, alasan, catatan_pengurus, status, diajukan_pada, created_at)
        VALUES (?, 1, ?, 3, 1, 'Diteruskan ke SBX Murobi C.', NOW() - INTERVAL ? DAY, ?, ?, ?, ?, 'Catatan untuk murobi (sintetis)', 'Diajukan', NOW() - INTERVAL ? DAY - INTERVAL 2 HOUR, NOW() - INTERVAL ? DAY)", 'iiiisssii', [
        (int) $peserta[$urut]['id'], $pengurusUser, $hariLalu, $tahun, date('Y-m-d', strtotime('+' . ($urut + 1) . ' days')), date('Y-m-d', strtotime('+' . ($urut + 2) . ' days')), $alasan, $hariLalu, $hariLalu,
    ]);
}
foreach ($antrean as $id) {
    $q("INSERT INTO izin_riwayat_status (pengajuan_id, peristiwa, status_sebelum, status_sesudah, pelaku_user_id, pelaku_kapasitas, alasan) VALUES (?, 'Pengajuan dibuat', NULL, 'Diajukan', ?, 'pengurus', NULL)", 'ii', [$id, $pengurusUser]);
}

$db->commit();
echo json_encode([
    'tahap' => 4,
    'pertemuan' => $pertemuan,
    'pertemuan_guru_lain' => $pertemuanLain,
    'peserta' => count($peserta),
    'izin_berjalan' => $izinBerjalan,
    'antrean_murobi_c' => $antrean,
], JSON_PRETTY_PRINT) . PHP_EOL;
