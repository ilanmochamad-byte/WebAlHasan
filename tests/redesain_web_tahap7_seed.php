<?php

declare(strict_types=1);

/**
 * Fixture sintetis redesain website Tahap 7 — penilaian & rapor.
 *
 * Dijalankan oleh `tests/redesain_web_tahap7_db.sh` pada DB salinan
 * `test_*_test` (menolak DB lain). Tanpa data pribadi. Kelas baru terpisah
 * dari "SBX Kelas 1", sehingga angka uji Tahap 2–6 tidak berubah.
 *
 * Menambah:
 *   - semester lalu "Genap 2025/2026" (Non-Aktif) dengan rencana kelas yang
 *     sama (3 mapel; guru Tafsir semester lalu sudah nonaktif) untuk
 *     "Salin dari semester lalu" (R-1, keputusan X);
 *   - kelas "SBX T7 Kelas Baru" tanpa rencana semester ini tetapi berencana
 *     pada semester lalu (guru Fiqih/Nahwu masih ditugaskan);
 *   - kelas "SBX T7 Kelas 30" berisi 30 santri, rencana 3 mapel (Fiqih,
 *     Nahwu, Tafsir) dengan guru `sbx_guru_mapel`, penanda tangan beraset;
 *   - penanda tangan aktif Bagian Pendidikan & Pimpinan, satu nonaktif;
 *   - pembimbing `sbx_pengurus_a` dan murobi `sbx_murobi_c` untuk kelas itu;
 *   - lewat LAYANAN V4 (bukan INSERT langsung): nilai santri 1–12 lengkap dan
 *     diserahkan; 13–20 Fiqih lengkap (draf); 21–24 Fiqih sebagian; sikap
 *     santri 1–12 diserahkan, 1–10 diketahui murobi; bahan santri 1–8
 *     diverifikasi; Fiqih santri 9 dikembalikan; rapor santri 1 terbit dua
 *     versi (v1 diganti) dan santri 2 terbit satu versi;
 *   - wali `sbx_t7_ortu` (sandi sama dengan akun sandbox) untuk santri 1 dan 3.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Auth\AuthRepository;
use App\V4\{NilaiService, RaporService, RencanaService, SikapService, VerifikasiService};

$namaDb = (string) app_config('database.database');
if (!preg_match('/^test_.*_test$/', $namaDb)) {
    fwrite(STDERR, 'Seed Tahap 7 hanya untuk DB test_*_test, bukan ' . $namaDb . PHP_EOL);
    exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = app_db();
$q = static function (string $sql, string $types = '', array $params = []) use ($db): int {
    $stmt = $db->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();

    return $id;
};
$val = static fn (string $sql): mixed => $db->query($sql)->fetch_row()[0] ?? null;
$akun = static fn (string $username): array => (new AuthRepository($db))->findActiveById((int) $val("SELECT id FROM users WHERE username='" . $db->real_escape_string($username) . "'"));

$admin = $akun('sbx_admin');
$guru = $akun('sbx_guru_mapel');
$pendidikan = $akun('sbx_pendidikan');
$pembimbing = $akun('sbx_pengurus_a');
$murobi = $akun('sbx_murobi_c');
$tahun = (int) $val("SELECT id FROM tahun_ajaran WHERE status='Aktif' AND archived_at IS NULL LIMIT 1");
$mulai = '2026-08-24';
$aid = (int) $admin['id'];
$_SESSION['user_id'] = $aid;

// --- Semester lalu, kelas, santri --------------------------------------------
$lalu = $q("INSERT INTO tahun_ajaran (tahun, semester, status) VALUES ('2025/2026', 'Genap', 'Non-Aktif')");
$kelas = $q("INSERT INTO kelas (nama_kelas, jenjang, is_active) VALUES ('SBX T7 Kelas 30', 'Tsanawi', 1)");
$santri = [];
for ($i = 1; $i <= 30; $i++) {
    $nomor = sprintf('%02d', $i);
    $santri[$i] = $q("INSERT INTO santri (nis, nama_santri, jenis_kelamin, tempat_lahir, tgl_lahir, alamat, desa, kecamatan, kab_kota, provinsi, nama_ayah, nama_ibu, asal_sekolah, sekolah_saat_ini, is_active)
        VALUES (?, ?, 'L', 'Kota Uji', '2013-03-03', 'Alamat uji', 'Desa', 'Kecamatan', 'Kabupaten', 'Provinsi', 'Ayah Uji', 'Ibu Uji', 'SD Uji', 'Tsanawi', 1)",
        'ss', ['SBX-T7-' . $nomor, 'SBX T7 Santri ' . $nomor]);
    $q("INSERT INTO plotting_kelas (id_santri, id_kelas, id_tahun, tanggal_mulai, status) VALUES (?, ?, ?, ?, 'Aktif')", 'iiis', [$santri[$i], $kelas, $tahun, $mulai]);
}

// --- Mapel, guru, penugasan ----------------------------------------------------
$fiqih = (int) $val("SELECT id FROM mata_pelajaran WHERE kode='SBX-FQH'");
$nahwu = (int) $val("SELECT id FROM mata_pelajaran WHERE kode='SBX-NHW'");
$tafsir = $q("INSERT INTO mata_pelajaran (kode, nama, kategori) VALUES ('SBX-TFS', 'Tafsir', 'Diniyah')");
$q("INSERT INTO mata_pelajaran (kode, nama, kategori, is_active) VALUES ('SBX-OLD', 'Khat Lama', 'Keterampilan', 0)");
$guruLama = $q("INSERT INTO guru (nama_guru, status, is_active) VALUES ('SBX Guru Lama', 'Guru', 0)");
$tugas = static fn (int $guruId, int $mapel, int $tahunId) => $q('INSERT INTO guru_mapel_assignments (guru_id, mata_pelajaran_id, kelas_id, tahun_ajaran_id, tanggal_mulai, created_by) VALUES (?, ?, ?, ?, ?, ?)', 'iiiisi', [$guruId, $mapel, $kelas, $tahunId, '2026-01-05', $aid]);
$gm = [$fiqih => $tugas(205, $fiqih, $tahun), $nahwu => $tugas(205, $nahwu, $tahun), $tafsir => $tugas(205, $tafsir, $tahun)];
$gmLalu = [$fiqih => $tugas(205, $fiqih, $lalu), $nahwu => $tugas(205, $nahwu, $lalu), $tafsir => $tugas($guruLama, $tafsir, $lalu)];
$q("INSERT INTO pembimbing_assignments (pengurus_id, tahun_ajaran_id, target_type, kelas_id, tanggal_mulai, is_active, created_by) VALUES (1, ?, 'Kelas', ?, ?, 1, ?)", 'iisi', [$tahun, $kelas, $mulai, $aid]);
$q("INSERT INTO murobi_assignments (guru_id, tahun_ajaran_id, target_type, kelas_id, tanggal_mulai, is_active, created_by) VALUES (3, ?, 'Kelas', ?, ?, 1, ?)", 'iisi', [$tahun, $kelas, $mulai, $aid]);

// --- Penanda tangan dengan aset gambar privat ---------------------------------
$penanda = static function (string $jabatan, string $nama, string $gelar, bool $aktif) use ($q, $aid): array {
    $pid = $q('INSERT INTO v4_penanda_tangan (jabatan, nama, gelar, is_active, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?)', 'sssiii', [$jabatan, $nama, $gelar, $aktif ? 1 : 0, $aid, $aid]);
    $berkas = bin2hex(random_bytes(20));
    $im = imagecreatetruecolor(360, 120);
    imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
    $tinta = imagecolorallocate($im, 20, 55, 110);
    imagestring($im, 5, 12, 12, 'UJI T7 ' . $jabatan, $tinta);
    imageline($im, 20, 90, 320, 50, $tinta);
    imagepng($im, APP_ROOT . '/storage/private/v4/' . $berkas);
    imagedestroy($im);
    chmod(APP_ROOT . '/storage/private/v4/' . $berkas, 0600);
    $aset = $q("INSERT INTO v4_tanda_tangan_aset (penanda_tangan_id, private_path, sha256, mime_type, uploaded_by, activated_by) VALUES (?, ?, ?, 'image/png', ?, ?)",
        'issii', [$pid, $berkas, hash_file('sha256', APP_ROOT . '/storage/private/v4/' . $berkas), $aid, $aid]);

    return ['penanda' => $pid, 'aset' => $aset];
};
$ttdPendidikan = $penanda('Pendidikan', 'Usth. SBX Rahmawati', 'S.Pd.', true);
$ttdPimpinan = $penanda('Pimpinan', 'KH. SBX Pimpinan', '', true);
$ttdLama = $penanda('Pendidikan', 'Ust. SBX Hasan Lama', '', false);

// --- Rencana: semester lalu (langsung, semester nonaktif) dan semester ini (layanan)
$rencanaLalu = $q("INSERT INTO v4_rencana (tahun_ajaran_id, kelas_id, tempat, pendidikan_penanda_id, pendidikan_aset_id, pimpinan_penanda_id, pimpinan_aset_id, created_by) VALUES (?, ?, 'Ciamis', ?, ?, ?, ?, ?)",
    'iiiiiii', [$lalu, $kelas, $ttdPendidikan['penanda'], $ttdPendidikan['aset'], $ttdPimpinan['penanda'], $ttdPimpinan['aset'], $aid]);
foreach ([[$fiqih, 75], [$nahwu, 70], [$tafsir, 65]] as [$mp, $target]) {
    $q('INSERT INTO v4_rencana_mapel (rencana_id, mata_pelajaran_id, guru_mapel_assignment_id, target_ketuntasan) VALUES (?, ?, ?, ?)', 'iiid', [$rencanaLalu, $mp, $gmLalu[$mp], $target]);
}
// Kelas tanpa rencana semester ini, tetapi berencana pada semester lalu (salin R-1).
$kelasBaru = $q("INSERT INTO kelas (nama_kelas, jenjang, is_active) VALUES ('SBX T7 Kelas Baru', 'Tsanawi', 1)");
$tugasBaru = static fn (int $guruId, int $mapel, int $tahunId) => $q('INSERT INTO guru_mapel_assignments (guru_id, mata_pelajaran_id, kelas_id, tahun_ajaran_id, tanggal_mulai, created_by) VALUES (?, ?, ?, ?, ?, ?)', 'iiiisi', [$guruId, $mapel, $kelasBaru, $tahunId, '2026-01-05', $aid]);
$rencanaBaruLalu = $q("INSERT INTO v4_rencana (tahun_ajaran_id, kelas_id, tempat, pendidikan_penanda_id, pendidikan_aset_id, pimpinan_penanda_id, pimpinan_aset_id, created_by) VALUES (?, ?, 'Ciamis', ?, ?, ?, ?, ?)",
    'iiiiiii', [$lalu, $kelasBaru, $ttdPendidikan['penanda'], $ttdPendidikan['aset'], $ttdPimpinan['penanda'], $ttdPimpinan['aset'], $aid]);
foreach ([[$fiqih, 75, $tugasBaru(205, $fiqih, $lalu)], [$nahwu, 72.5, $tugasBaru(205, $nahwu, $lalu)], [$tafsir, 65, $tugasBaru($guruLama, $tafsir, $lalu)]] as [$mp, $target, $g]) {
    $q('INSERT INTO v4_rencana_mapel (rencana_id, mata_pelajaran_id, guru_mapel_assignment_id, target_ketuntasan) VALUES (?, ?, ?, ?)', 'iiid', [$rencanaBaruLalu, $mp, $g, $target]);
}
$tugasBaru(205, $fiqih, $tahun);
$tugasBaru(205, $nahwu, $tahun);
$rencanaSvc = new RencanaService($db, capabilities(), audit_logger());
$baca = $rencanaSvc->save($admin, ['tahun_ajaran_id' => $tahun, 'kelas_id' => $kelas, 'alasan' => 'Fixture Tahap 7', 'tempat' => 'Ciamis',
    'pendidikan_penanda_id' => $ttdPendidikan['penanda'], 'pendidikan_aset_id' => $ttdPendidikan['aset'],
    'pimpinan_penanda_id' => $ttdPimpinan['penanda'], 'pimpinan_aset_id' => $ttdPimpinan['aset'],
    'mapel' => [
        ['mata_pelajaran_id' => $fiqih, 'target_ketuntasan' => 75, 'guru_mapel_assignment_id' => $gm[$fiqih]],
        ['mata_pelajaran_id' => $nahwu, 'target_ketuntasan' => 70, 'guru_mapel_assignment_id' => $gm[$nahwu]],
        ['mata_pelajaran_id' => $tafsir, 'target_ketuntasan' => 70, 'guru_mapel_assignment_id' => $gm[$tafsir]],
    ]]);
$rencana = (int) $baca['rencana']['id'];
$rm = [];
foreach ($baca['mapel'] as $m) {
    $rm[(int) $m['mata_pelajaran_id']] = (int) $m['id'];
}

// --- Nilai lewat NilaiService (guru) -----------------------------------------------
$nilaiSvc = new NilaiService($db, capabilities(), audit_logger());
$isi = static function (int $sid, int $mapel, array $nilai, string $aksi) use ($nilaiSvc, $guru): void {
    $komponen = [];
    foreach ($nilai as [$jenis, $nomor, $angka]) {
        $komponen[] = ['komponen' => $jenis, 'nomor_harian' => $nomor, 'version' => 0, 'nilai' => (string) $angka, 'catatan' => $jenis === 'Akhir' ? 'Capaian baik pada bab thaharah.' : ''];
    }
    $nilaiSvc->save($guru, ['rencana_mapel_id' => $mapel, 'santri_id' => $sid, 'komponen' => $komponen, 'action' => $aksi, 'alasan' => '']);
};
for ($i = 1; $i <= 24; $i++) {
    $dasar = 60 + ($i * 3) % 38;
    if ($i <= 12) {
        foreach ($rm as $mapel) {
            $isi($santri[$i], $mapel, [['Harian', 1, $dasar], ['Harian', 2, min(100, $dasar + 4)], ['Tengah', 0, $dasar + 1], ['Akhir', 0, min(100, $dasar + 2)]], 'serahkan');
        }
    } elseif ($i <= 20) {
        $isi($santri[$i], $rm[$fiqih], [['Harian', 1, $dasar], ['Tengah', 0, $dasar], ['Akhir', 0, $dasar]], 'draf');
    } else {
        $isi($santri[$i], $rm[$fiqih], [['Harian', 1, $dasar]], 'draf');
    }
}

// --- Sikap lewat SikapService (pembimbing, murobi) -------------------------------------
$sikapSvc = new SikapService($db, audit_logger());
for ($i = 1; $i <= 12; $i++) {
    $sikapSvc->save($pembimbing, ['rencana_id' => $rencana, 'santri_id' => $santri[$i], 'version' => 0, 'adab' => 'Baik', 'kedisiplinan' => $i % 3 === 0 ? 'Sangat Baik' : 'Baik', 'tanggung_jawab' => 'Cukup', 'catatan' => 'Rajin piket kamar.', 'action' => 'serahkan', 'alasan' => ''], 'pembimbing');
    if ($i <= 10) {
        $versi = (int) $val('SELECT version FROM v4_sikap WHERE santri_id=' . $santri[$i] . ' AND tahun_ajaran_id=' . $tahun);
        $sikapSvc->save($murobi, ['rencana_id' => $rencana, 'santri_id' => $santri[$i], 'version' => $versi, 'action' => 'diketahui', 'alasan' => ''], 'murobi');
    }
}

// --- Verifikasi Bagian Pendidikan --------------------------------------------------
$verif = new VerifikasiService($db, audit_logger());
$bahan = static fn (int $sid): array => $verif->read($pendidikan, $rencana, $sid)['matriks'][0]['bahan'];
for ($i = 1; $i <= 8; $i++) {
    foreach ($bahan($santri[$i]) as $m) {
        $dasar = ['rencana_id' => $rencana, 'santri_id' => $santri[$i], 'jenis' => $m['jenis'], 'mapel_key' => $m['mapel_key'], 'alasan' => ''];
        if ($m['jenis'] === 'kehadiran') {
            $verif->act($pendidikan, $dasar + ['action' => 'serahkan'] + $m['pemeriksaan']);
            $m = array_values(array_filter($bahan($santri[$i]), static fn (array $b): bool => $b['jenis'] === 'kehadiran'))[0];
        }
        $verif->act($pendidikan, $dasar + ['action' => 'verifikasi'] + $m['pemeriksaan']);
    }
}
foreach ($bahan($santri[9]) as $m) {
    if ($m['jenis'] === 'nilai' && $m['mapel_key'] === $rm[$fiqih]) {
        $verif->act($pendidikan, ['rencana_id' => $rencana, 'santri_id' => $santri[9], 'jenis' => 'nilai', 'mapel_key' => $m['mapel_key'], 'action' => 'kembalikan', 'alasan' => 'Nilai harian 2 perlu diperiksa ulang.'] + $m['pemeriksaan']);
    }
}

// --- Rapor: santri 1 dua versi, santri 2 satu versi --------------------------------
$raporSvc = new RaporService($db, audit_logger());
$terbit = static function (int $sid, string $aksi, string $alasan) use ($raporSvc, $pendidikan, $rencana): void {
    $p = $raporSvc->preview($pendidikan, $rencana, $sid, 'Semoga istiqamah.');
    $raporSvc->act($pendidikan, ['action' => $aksi, 'rencana_id' => $rencana, 'santri_id' => $sid, 'current_version' => $p['current_version'], 'revision' => $p['revision'],
        'pratinjau_hash' => $p['pratinjau_hash'], 'catatan_umum' => 'Semoga istiqamah.', 'alasan' => $alasan, 'idempotency_key' => bin2hex(random_bytes(16))]);
};
$terbit($santri[1], 'Terbit', '');
$terbit($santri[1], 'Ganti', 'Perbaikan catatan umum (fixture).');
$terbit($santri[2], 'Terbit', '');

// --- Wali berakun untuk santri 1 (rapor) dan santri 3 (belum ada rapor) -------------
$hash = (string) $val("SELECT password FROM users WHERE username='sbx_admin'");
$wali = $q("INSERT INTO wali (nama, no_hp, is_active) VALUES ('SBX T7 Wali', '080000000777', 1)");
$q("INSERT INTO santri_wali (santri_id, wali_id, hubungan, is_primary, created_by) VALUES (?, ?, 'Ayah', 1, ?)", 'iii', [$santri[1], $wali, $aid]);
$q("INSERT INTO santri_wali (santri_id, wali_id, hubungan, is_primary, created_by) VALUES (?, ?, 'Ayah', 1, ?)", 'iii', [$santri[3], $wali, $aid]);
$ortu = $q("INSERT INTO users (name, username, password, wali_id, is_active) VALUES ('SBX T7 Wali', 'sbx_t7_ortu', ?, ?, 1)", 'si', [$hash, $wali]);
$q("INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = 'orang_tua'", 'i', [$ortu]);

$ringkas = [
    'tahun' => $tahun, 'tahun_lalu' => $lalu, 'kelas' => $kelas, 'kelas_baru' => $kelasBaru, 'rencana' => $rencana, 'rencana_lalu' => $rencanaLalu,
    'rencana_mapel' => $rm, 'mapel' => ['fiqih' => $fiqih, 'nahwu' => $nahwu, 'tafsir' => $tafsir], 'santri' => $santri,
    'penanda' => ['pendidikan' => $ttdPendidikan, 'pimpinan' => $ttdPimpinan, 'lama' => $ttdLama], 'wali_user' => $ortu,
    'rapor' => (int) $val('SELECT id FROM v4_rapor WHERE santri_id=' . $santri[1]),
];
file_put_contents(APP_ROOT . '/storage/private/v4/redesain_t7_ids.json', json_encode($ringkas, JSON_PRETTY_PRINT));
echo json_encode(['kelas' => $kelas, 'rencana' => $rencana, 'santri' => count($santri), 'rapor' => $ringkas['rapor']]) . PHP_EOL;
