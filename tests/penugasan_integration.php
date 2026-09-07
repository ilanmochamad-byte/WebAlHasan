<?php

declare(strict_types=1);

/**
 * Pengujian integrasi "Fondasi Penugasan dan Hak Akses Lintas PRD V3–V6"
 * (keputusan pengguna 7 September 2026) pada basis data sungguhan.
 *
 * Nomor mengikuti daftar pengujian wajib pada instruksi paket:
 *
 *   FI-1  role dasar lama tetap berfungsi (daftar capability perizinan V2 utuh);
 *   FI-2  murobi tetap capability dari penugasan guru;
 *   FI-3  pembimbing tetap capability dari penugasan pengurus;
 *   FI-4  admin dapat membuat setiap jenis penugasan baru;
 *   FI-5  pengguna non-admin tidak dapat mengelola penugasan;
 *   FI-6  penugasan belum mulai tidak menghasilkan capability;
 *   FI-7  penugasan aktif menghasilkan capability yang benar;
 *   FI-8  penugasan berakhir/diakhiri/dinonaktifkan tidak lagi menghasilkan capability;
 *   FI-9  pengurus dapat memiliki beberapa penugasan sekaligus;
 *   FI-10 panitia PSB tidak otomatis memperoleh capability bendahara PSB;
 *   FI-11 bendahara PSB tidak otomatis memperoleh capability panitia PSB;
 *   FI-12 guru hanya memperoleh capability mapel pada kelas, semester, dan tahun ajaran yang ditugaskan;
 *   FI-13 penugasan duplikat ditolak;
 *   FI-14 penugasan bertumpang tindih yang tidak sah ditolak, yang sah diterima;
 *   FI-15 perubahan penugasan dan audit bersifat transaksional;
 *   FI-16 percobaan IDOR dan manipulasi parameter ditolak;
 *   FI-19 endpoint profil lama tetap kompatibel (struktur capabilities utuh);
 *   FI-20 mode dan menu aplikasi lama tidak berubah setelah penugasan;
 *   FI-21 login guru, pengurus, admin, dan orang tua tetap berfungsi;
 *   FI-23 tidak ada capability yang diperoleh dengan memanipulasi sesi/input;
 *   FI-24 audit menyimpan perubahan tanpa data sensitif;
 *   FI-25 capability tidak efektif bila role dasar/relasi master tidak valid;
 *   FI-26 tahun ajaran diperhitungkan (PSB boleh tahun belum aktif; lainnya wajib aktif).
 *
 * FI-17 (CSRF) dan FI-18 (halaman lama) dibuktikan lewat HTTP pada
 * tests/penugasan_web_smoke.php; FI-22 (regresi V1–V2) oleh rangkaian regresi.
 *
 * Seluruh fixture memakai data FIKTIF berakhiran acak dan dihapus kembali pada
 * blok `finally`. Tidak ada permintaan jaringan keluar dan tidak ada data
 * produksi yang disentuh.
 *
 * Jalankan hanya pada database berakhiran `_test`:
 *   PENUGASAN_RUN_INTEGRATION=1 php tests/penugasan_integration.php
 */

$root = dirname(__DIR__);
if (getenv('PENUGASAN_RUN_INTEGRATION') !== '1') {
    fwrite(STDOUT, "[lewati] Set PENUGASAN_RUN_INTEGRATION=1 dan arahkan DB_NAME ke database khusus *_test.\n");
    exit(77);
}

require_once $root . '/app/bootstrap.php';

use App\Auth\AuthService;
use App\Auth\Capabilities;
use App\Penugasan\PenugasanException;
use App\Penugasan\PenugasanJenis;
use App\Penugasan\PenugasanService;

if (!str_ends_with((string) app_config('database.database'), '_test')) {
    fwrite(STDERR, "Ditolak: pengujian ini hanya boleh berjalan pada database berakhiran _test.\n");
    exit(2);
}
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? '[lulus] ' : '[gagal] ') . $message . PHP_EOL;
    if (!$condition) {
        $failures[] = $message;
    }
};
$tolak = static function (callable $aksi, string $pesan, ?int $status = null) use ($assert): void {
    try {
        $aksi();
        $assert(false, $pesan . ' (ternyata TIDAK ditolak)');
    } catch (PenugasanException $exception) {
        $assert($status === null || $exception->status() === $status, $pesan . ' [' . $exception->status() . ': ' . $exception->getMessage() . ']');
    } catch (Throwable $exception) {
        $assert($status === null, $pesan . ' [' . $exception->getMessage() . ']');
    }
};

$db = app_db();
$suffix = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
$kecil = strtolower($suffix);
$sandi = 'UjiPenugasan123Aa';
$hari = static fn (int $selisih): string => date('Y-m-d', strtotime(($selisih >= 0 ? '+' : '') . $selisih . ' days'));

$satu = static function (string $sql) use ($db): array {
    $rs = $db->query($sql);

    return ($rs && $row = $rs->fetch_assoc()) ? $row : [];
};
$angka = static fn (string $sql): int => (int) ($satu($sql)['n'] ?? 0);
$exec = static function (string $sql, array $params = []) use ($db): int {
    $statement = $db->prepare($sql);
    if ($statement === false) {
        throw new RuntimeException('Query gagal disiapkan: ' . $db->error . ' — ' . $sql);
    }
    if ($params !== []) {
        $types = '';
        $refs = [];
        foreach ($params as $key => &$value) {
            $types .= is_int($value) ? 'i' : 's';
            $refs[$key] = &$value;
        }
        unset($value);
        $statement->bind_param($types, ...$refs);
    }
    if (!$statement->execute()) {
        $error = $statement->error;
        $statement->close();
        throw new RuntimeException('Query gagal dijalankan: ' . $error);
    }
    $id = (int) $statement->insert_id;
    $statement->close();

    return $id;
};

$tahunAktif = (int) ($satu("SELECT id FROM tahun_ajaran WHERE status = 'Aktif' AND archived_at IS NULL LIMIT 1")['id'] ?? 0);
if ($tahunAktif < 1) {
    fwrite(STDOUT, "[lewati] Tidak ada tahun ajaran aktif pada database uji.\n");
    exit(77);
}
$tahunAktifRow = $satu('SELECT tahun, semester FROM tahun_ajaran WHERE id = ' . $tahunAktif);

$dibuat = ['users' => [], 'guru' => [], 'pengurus' => [], 'wali' => [], 'kelas' => [], 'kamar' => [], 'tahun' => [], 'mapel' => []];
$mulaiUji = date('Y-m-d H:i:s');

$caps = capabilities();
$loadUser = static fn (int $id): ?array => auth_repository()->findActiveById($id);
$fitur = static function (int $userId) use ($caps, $loadUser): array {
    $caps->forget($userId);
    $user = $loadUser($userId);

    return $user === null ? [] : $caps->featureList($user);
};
$dasar = static function (int $userId) use ($caps, $loadUser): array {
    $caps->forget($userId);
    $user = $loadUser($userId);

    return $user === null ? [] : $caps->forUser($user);
};
$akun = static function (string $username, string $nama, string $role, ?int $guruId = null, ?int $pengurusId = null, ?int $waliId = null, bool $beriRole = true) use ($exec, $sandi, &$dibuat): int {
    $id = $exec(
        'INSERT INTO users (name, username, password, guru_id, pengurus_id, wali_id, is_active, force_password_change, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 1, 0, NOW(), NOW())',
        [$nama, $username, password_hash($sandi, PASSWORD_DEFAULT), $guruId, $pengurusId, $waliId]
    );
    $dibuat['users'][] = $id;
    if ($beriRole) {
        $exec('INSERT INTO user_roles (user_id, role_id, assigned_by) SELECT ?, id, NULL FROM roles WHERE slug = ?', [$id, $role]);
    }

    return $id;
};

try {
    // ------------------------------------------------------------- fixture
    $tahunDepan = $exec("INSERT INTO tahun_ajaran (tahun, semester, status, created_at, updated_at) VALUES (?, 'Ganjil', 'Non-Aktif', NOW(), NOW())", ['UJ' . $suffix]);
    $tahunArsip = $exec("INSERT INTO tahun_ajaran (tahun, semester, status, archived_at, created_at, updated_at) VALUES (?, 'Genap', 'Non-Aktif', NOW(), NOW(), NOW())", ['UJ' . $suffix]);
    array_push($dibuat['tahun'], $tahunDepan, $tahunArsip);

    $jenjang = 'Uji' . $suffix;
    $kelasA = $exec('INSERT INTO kelas (nama_kelas, jenjang, is_active) VALUES (?, ?, 1)', ['Kelas A ' . $suffix, $jenjang]);
    $kelasB = $exec('INSERT INTO kelas (nama_kelas, jenjang, is_active) VALUES (?, ?, 1)', ['Kelas B ' . $suffix, $jenjang]);
    $kelasNonaktif = $exec('INSERT INTO kelas (nama_kelas, jenjang, is_active) VALUES (?, ?, 0)', ['Kelas N ' . $suffix, $jenjang]);
    array_push($dibuat['kelas'], $kelasA, $kelasB, $kelasNonaktif);
    $kamar = $exec('INSERT INTO kamar (nama_kamar, kapasitas) VALUES (?, 10)', ['Kamar ' . $suffix]);
    $dibuat['kamar'][] = $kamar;

    $guru = $exec("INSERT INTO guru (nip, nama_guru, status, is_active) VALUES (?, ?, 'Guru', 1)", ['FG1' . $suffix, 'Guru Uji ' . $suffix]);
    $guruTanpaAkun = $exec("INSERT INTO guru (nip, nama_guru, status, is_active) VALUES (?, ?, 'Guru', 1)", ['FG2' . $suffix, 'Guru Tanpa Akun ' . $suffix]);
    $guruNonaktif = $exec("INSERT INTO guru (nip, nama_guru, status, is_active) VALUES (?, ?, 'Guru', 0)", ['FG3' . $suffix, 'Guru Nonaktif ' . $suffix]);
    $guruTanpaRole = $exec("INSERT INTO guru (nip, nama_guru, status, is_active) VALUES (?, ?, 'Guru', 1)", ['FG4' . $suffix, 'Guru Tanpa Role ' . $suffix]);
    array_push($dibuat['guru'], $guru, $guruTanpaAkun, $guruNonaktif, $guruTanpaRole);

    $pengurus = $exec("INSERT INTO pengurus (nama, nomor_identitas, jabatan, is_active) VALUES (?, ?, 'Keamanan', 1)", ['Pengurus Uji ' . $suffix, 'FP1' . $suffix]);
    $pengurus2 = $exec("INSERT INTO pengurus (nama, nomor_identitas, jabatan, is_active) VALUES (?, ?, 'Bendahara', 1)", ['Pengurus Dua ' . $suffix, 'FP2' . $suffix]);
    $pengurusTanpaAkun = $exec("INSERT INTO pengurus (nama, nomor_identitas, jabatan, is_active) VALUES (?, ?, 'Pendidikan', 1)", ['Pengurus Tanpa Akun ' . $suffix, 'FP3' . $suffix]);
    array_push($dibuat['pengurus'], $pengurus, $pengurus2, $pengurusTanpaAkun);

    $wali = $exec('INSERT INTO wali (nama, no_hp, is_active) VALUES (?, ?, 1)', ['Wali Uji ' . $suffix, '081200009999']);
    $dibuat['wali'][] = $wali;

    $adminId = $akun('fp.admin.' . $kecil, 'Admin Uji ' . $suffix, 'admin');
    $guruUser = $akun('fp.guru.' . $kecil, 'Akun Guru ' . $suffix, 'guru', $guru);
    $guruTanpaRoleUser = $akun('fp.gurunr.' . $kecil, 'Akun Guru Tanpa Role ' . $suffix, 'guru', $guruTanpaRole, null, null, false);
    $pengurusUser = $akun('fp.pengurus.' . $kecil, 'Akun Pengurus ' . $suffix, 'pengurus', null, $pengurus);
    $pengurus2User = $akun('fp.pengurus2.' . $kecil, 'Akun Pengurus Dua ' . $suffix, 'pengurus', null, $pengurus2);
    $ortuUser = $akun('fp.ortu.' . $kecil, 'Akun Ortu ' . $suffix, 'orang_tua', null, null, $wali);

    $_SESSION = ['user_id' => $adminId];
    $service = penugasan_service();

    $mapel = $service->simpanMataPelajaran(['nama' => 'Fiqih ' . $suffix, 'kode' => 'FQ' . $suffix], null, $adminId);
    $mapel2 = $service->simpanMataPelajaran(['nama' => 'Nahwu ' . $suffix, 'kode' => ''], null, $adminId);
    array_push($dibuat['mapel'], $mapel, $mapel2);

    // =============================================================== FI-1
    $assert(Capabilities::ALL === ['admin', 'pengurus', 'murobi', 'orang_tua'], 'FI-1 daftar capability perizinan V2 tidak berubah');
    $assert($dasar($adminId) === ['admin'], 'FI-1 akun admin tetap memperoleh capability admin');
    $assert($dasar($guruUser) === [], 'FI-1 guru tanpa penugasan murobi tidak memperoleh capability perizinan');
    $assert($dasar($pengurusUser) === ['pengurus'], 'FI-1 pengurus dengan relasi master aktif tetap memperoleh capability pengurus');
    $assert($dasar($ortuUser) === ['orang_tua'], 'FI-1 orang tua dengan wali aktif tetap memperoleh capability orang_tua');
    $assert($fitur($guruUser) === [] && $fitur($pengurusUser) === [] && $fitur($ortuUser) === [], 'FI-1 tanpa penugasan tidak ada feature capability');
    $assert($fitur($adminId) === PenugasanJenis::semuaCapability(), 'FI-1 admin memperoleh seluruh feature capability sebagai pengawasan');
    $adminUser = $loadUser($adminId);
    $assert($caps->featureSource($adminUser, 'nilai.input') === Capabilities::SUMBER_ADMIN, 'FI-1 sumber capability admin ditandai "admin" (pengawasan), bukan operasional');

    // =============================================================== FI-4
    $idJenis = [];
    $idJenis[PenugasanJenis::MUROBI] = $service->buat(PenugasanJenis::MUROBI, [
        'guru_id' => $guru, 'tahun_ajaran_id' => $tahunAktif, 'target_type' => 'Kamar', 'kamar_id' => $kamar, 'tanggal_mulai' => $hari(0),
    ], $adminId);
    $idJenis[PenugasanJenis::PEMBIMBING] = $service->buat(PenugasanJenis::PEMBIMBING, [
        'pengurus_id' => $pengurus, 'tahun_ajaran_id' => $tahunAktif, 'target_type' => 'Kelas', 'kelas_id' => $kelasA, 'tanggal_mulai' => $hari(0),
    ], $adminId);
    $idJenis[PenugasanJenis::GURU_MAPEL] = $service->buat(PenugasanJenis::GURU_MAPEL, [
        'guru_id' => $guru, 'mata_pelajaran_id' => $mapel, 'kelas_id' => $kelasA, 'tahun_ajaran_id' => $tahunAktif, 'tanggal_mulai' => $hari(0), 'catatan' => 'Uji',
    ], $adminId);
    $idJenis[PenugasanJenis::PENDIDIKAN] = $service->buat(PenugasanJenis::PENDIDIKAN, [
        'pengurus_id' => $pengurus, 'tahun_ajaran_id' => $tahunAktif, 'jenjang' => $jenjang, 'tanggal_mulai' => $hari(0),
    ], $adminId);
    $idJenis[PenugasanJenis::BENDAHARA_BULANAN] = $service->buat(PenugasanJenis::BENDAHARA_BULANAN, [
        'pengurus_id' => $pengurus, 'tahun_ajaran_id' => $tahunAktif, 'jenjang' => '', 'tanggal_mulai' => $hari(0),
    ], $adminId);
    $idJenis[PenugasanJenis::PANITIA_PSB] = $service->buat(PenugasanJenis::PANITIA_PSB, [
        'pengurus_id' => $pengurus, 'tahun_ajaran_id' => $tahunDepan, 'gelombang' => '1', 'tanggal_mulai' => $hari(0),
    ], $adminId);
    $idJenis[PenugasanJenis::BENDAHARA_PSB] = $service->buat(PenugasanJenis::BENDAHARA_PSB, [
        'pengurus_id' => $pengurus2, 'tahun_ajaran_id' => $tahunDepan, 'gelombang' => '', 'tanggal_mulai' => $hari(0),
    ], $adminId);
    foreach ($idJenis as $j => $id) {
        $assert($id > 0 && $service->detail($j, $id) !== null, 'FI-4 admin membuat penugasan ' . $j . ' (#' . $id . ')');
    }
    foreach (['guru_mapel_assignments', 'pendidikan_assignments', 'bendahara_bulanan_assignments', 'panitia_psb_assignments', 'bendahara_psb_assignments'] as $t) {
        $assert($angka('SELECT COUNT(*) n FROM ' . $t . ' WHERE created_by = ' . $adminId) === 1, 'FI-4 baris ' . $t . ' menyimpan created_by admin');
    }

    // =============================================================== FI-2
    $assert($dasar($guruUser) === [Capabilities::MUROBI], 'FI-2 penugasan murobi tetap menghasilkan capability murobi V2 pada akun guru');
    $assert(in_array('murobi.binaan', $fitur($guruUser), true), 'FI-2 penugasan murobi menghasilkan capability binaan (kesiapan V3)');
    $guruUserRow = $loadUser($guruUser);
    $assert($caps->featureAppliesToKamar($guruUserRow, 'murobi.binaan', $kamar) && !$caps->featureAppliesToKamar($guruUserRow, 'murobi.binaan', $kamar + 100000), 'FI-2 cakupan binaan murobi mengikuti kamar penugasan');
    $assert(!$caps->featureAppliesToKelas($guruUserRow, 'murobi.binaan', $kelasA), 'FI-2 cakupan kamar tidak mewakili kelas');

    // =============================================================== FI-3
    $assert($dasar($pengurusUser) === [Capabilities::PENGURUS], 'FI-3 role pengurus tetap menghasilkan capability pengurus V2');
    $assert(in_array('pembimbing.binaan', $fitur($pengurusUser), true), 'FI-3 penugasan pembimbing menghasilkan capability binaan pengurus');
    $pembimbingAktif = pembimbing_service()->activeForPengurus($pengurus);
    $assert(count($pembimbingAktif) === 1 && (int) $pembimbingAktif[0]['kelas_id'] === $kelasA, 'FI-3 layanan pembimbing V2 tetap membaca penugasan yang dibuat pusat penugasan');
    $pengurusUserRow = $loadUser($pengurusUser);
    $assert($caps->featureAppliesToKelas($pengurusUserRow, 'pembimbing.binaan', $kelasA) && !$caps->featureAppliesToKelas($pengurusUserRow, 'pembimbing.binaan', $kelasB), 'FI-3 cakupan binaan pembimbing mengikuti kelas penugasan');

    // =============================================================== FI-7 / FI-9 / FI-12
    $fiturPengurus = $fitur($pengurusUser);
    foreach (['pembimbing.binaan', 'rapor.verifikasi', 'rapor.finalisasi', 'rapor.buka_koreksi', 'rapor.cetak',
        'pembiayaan_bulanan.tagihan', 'pembiayaan_bulanan.pembayaran', 'pembiayaan_bulanan.verifikasi', 'pembiayaan_bulanan.laporan',
        'psb.pendaftar', 'psb.verifikasi', 'psb.seleksi', 'psb.penerimaan'] as $cap) {
        $assert(in_array($cap, $fiturPengurus, true), 'FI-7/9 pengurus dengan empat penugasan sekaligus memperoleh ' . $cap);
    }
    $assert($caps->featureSource($pengurusUserRow, 'rapor.finalisasi') === Capabilities::SUMBER_PENUGASAN, 'FI-7 sumber capability pengurus adalah penugasan (operasional)');
    $assert($caps->featureAppliesTo($pengurusUserRow, 'rapor.finalisasi', ['jenjang' => $jenjang]) && !$caps->featureAppliesTo($pengurusUserRow, 'rapor.finalisasi', ['jenjang' => 'Lain' . $suffix]), 'FI-7 cakupan Bagian Pendidikan mengikuti jenjang');
    $assert($caps->featureAppliesToKelas($pengurusUserRow, 'rapor.finalisasi', $kelasA), 'FI-7 cakupan jenjang mencakup kelas dengan jenjang itu');
    $assert($caps->featureAppliesTo($pengurusUserRow, 'pembiayaan_bulanan.tagihan', ['jenjang' => 'Apa saja']), 'FI-7 bendahara bulanan tanpa jenjang berlaku untuk seluruh unit');
    $assert($caps->featureAppliesToGelombangPsb($pengurusUserRow, 'psb.pendaftar', $tahunDepan, '1') && !$caps->featureAppliesToGelombangPsb($pengurusUserRow, 'psb.pendaftar', $tahunDepan, '2'), 'FI-7 cakupan panitia PSB mengikuti gelombang');
    $assert(!$caps->featureAppliesToTahunAjaran($pengurusUserRow, 'psb.pendaftar', $tahunAktif), 'FI-7 capability PSB tidak berlaku pada tahun ajaran lain');

    $fiturGuru = $fitur($guruUser);
    foreach (['nilai.input', 'nilai.lihat_sendiri', 'nilai.koreksi_sendiri'] as $cap) {
        $assert(in_array($cap, $fiturGuru, true), 'FI-12 guru mapel memperoleh ' . $cap);
    }
    $guruUserRow = $loadUser($guruUser);
    $assert($caps->featureAppliesToKelas($guruUserRow, 'nilai.input', $kelasA, $tahunAktif), 'FI-12 capability nilai berlaku pada kelas yang ditugaskan');
    $assert(!$caps->featureAppliesToKelas($guruUserRow, 'nilai.input', $kelasB, $tahunAktif), 'FI-12 capability nilai TIDAK berlaku pada kelas lain');
    $assert($caps->featureAppliesToMataPelajaran($guruUserRow, 'nilai.input', $mapel, $kelasA, $tahunAktif), 'FI-12 capability nilai berlaku pada mata pelajaran yang ditugaskan');
    $assert(!$caps->featureAppliesToMataPelajaran($guruUserRow, 'nilai.input', $mapel2, $kelasA, $tahunAktif), 'FI-12 capability nilai TIDAK berlaku pada mata pelajaran lain');
    $assert($caps->featureAppliesToSemester($guruUserRow, 'nilai.input', (string) $tahunAktifRow['tahun'], (string) $tahunAktifRow['semester']), 'FI-12 capability nilai berlaku pada tahun ajaran/semester yang ditugaskan');
    $assert(!$caps->featureAppliesToSemester($guruUserRow, 'nilai.input', 'UJ' . $suffix, 'Ganjil'), 'FI-12 capability nilai TIDAK berlaku pada tahun ajaran/semester lain');
    $assert(!$caps->featureAppliesToTahunAjaran($guruUserRow, 'nilai.input', $tahunDepan), 'FI-12 capability nilai TIDAK berlaku pada tahun ajaran lain');
    $assert(!in_array('rapor.finalisasi', $fiturGuru, true) && !in_array('psb.pendaftar', $fiturGuru, true), 'FI-12 guru tidak memperoleh capability penugasan pengurus');

    // =============================================================== FI-10 / FI-11
    $fiturP2 = $fitur($pengurus2User);
    $assert(!in_array('psb_keuangan.tagihan', $fiturPengurus, true) && !in_array('psb_keuangan.laporan', $fiturPengurus, true), 'FI-10 panitia PSB tidak otomatis memperoleh capability bendahara PSB');
    $assert(in_array('psb_keuangan.tagihan', $fiturP2, true) && !in_array('psb.pendaftar', $fiturP2, true) && !in_array('psb.seleksi', $fiturP2, true), 'FI-11 bendahara PSB tidak otomatis memperoleh capability panitia PSB');
    $keduanya = $service->buat(PenugasanJenis::PANITIA_PSB, ['pengurus_id' => $pengurus2, 'tahun_ajaran_id' => $tahunDepan, 'gelombang' => '', 'tanggal_mulai' => $hari(0)], $adminId);
    $fiturP2 = $fitur($pengurus2User);
    $assert(in_array('psb.pendaftar', $fiturP2, true) && in_array('psb_keuangan.tagihan', $fiturP2, true), 'FI-10/11 pengurus boleh menjadi panitia PSB dan bendahara PSB sekaligus');

    // =============================================================== FI-26
    $assert(in_array('psb.pendaftar', $fitur($pengurusUser), true), 'FI-26 penugasan PSB pada tahun ajaran yang BELUM aktif tetap efektif');
    $tolak(static fn () => $service->buat(PenugasanJenis::PENDIDIKAN, ['pengurus_id' => $pengurus2, 'tahun_ajaran_id' => $tahunArsip, 'jenjang' => '', 'tanggal_mulai' => $hari(0)], $adminId), 'FI-26 tahun ajaran yang diarsipkan ditolak', 422);
    $pendidikanDepan = $service->buat(PenugasanJenis::PENDIDIKAN, ['pengurus_id' => $pengurus2, 'tahun_ajaran_id' => $tahunDepan, 'jenjang' => '', 'tanggal_mulai' => $hari(0)], $adminId);
    $assert(!in_array('rapor.finalisasi', $fitur($pengurus2User), true), 'FI-26 penugasan non-PSB pada tahun ajaran yang belum aktif BELUM menghasilkan capability');

    // =============================================================== FI-6
    $nanti = $service->buat(PenugasanJenis::BENDAHARA_BULANAN, ['pengurus_id' => $pengurus2, 'tahun_ajaran_id' => $tahunAktif, 'jenjang' => '', 'tanggal_mulai' => $hari(3)], $adminId);
    $assert(!in_array('pembiayaan_bulanan.tagihan', $fitur($pengurus2User), true), 'FI-6 penugasan yang belum mulai tidak menghasilkan capability');
    $assert($service->status($service->detail(PenugasanJenis::BENDAHARA_BULANAN, $nanti)) === PenugasanService::STATUS_AKAN_DATANG, 'FI-6 status penugasan belum mulai = Akan Datang');

    // =============================================================== FI-8
    $lampau = $service->buat(PenugasanJenis::GURU_MAPEL, ['guru_id' => $guru, 'mata_pelajaran_id' => $mapel2, 'kelas_id' => $kelasB, 'tahun_ajaran_id' => $tahunAktif, 'tanggal_mulai' => $hari(-10), 'tanggal_selesai' => $hari(-1)], $adminId);
    $guruUserRow = $loadUser($guruUser);
    $caps->forget($guruUser);
    $assert(!$caps->featureAppliesToMataPelajaran($guruUserRow, 'nilai.input', $mapel2, $kelasB, $tahunAktif), 'FI-8 penugasan yang sudah berakhir tidak menghasilkan capability');
    $assert($service->status($service->detail(PenugasanJenis::GURU_MAPEL, $lampau)) === PenugasanService::STATUS_BERAKHIR, 'FI-8 status penugasan lampau = Berakhir');

    $service->akhiri(PenugasanJenis::PANITIA_PSB, $idJenis[PenugasanJenis::PANITIA_PSB], $hari(0), 'Uji pengakhiran hari ini', $adminId);
    $assert(in_array('psb.pendaftar', $fitur($pengurusUser), true), 'FI-8 pengakhiran pada hari ini masih berlaku sampai akhir tanggal selesai');
    $barisAkhir = $service->detail(PenugasanJenis::PANITIA_PSB, $idJenis[PenugasanJenis::PANITIA_PSB]);
    $assert($barisAkhir['diakhiri_pada'] !== null && (int) $barisAkhir['diakhiri_oleh'] === $adminId && $barisAkhir['alasan_pengakhiran'] === 'Uji pengakhiran hari ini', 'FI-8 pengakhiran mencatat pelaku, waktu, dan alasan');
    $tolak(static fn () => $service->akhiri(PenugasanJenis::PANITIA_PSB, $idJenis[PenugasanJenis::PANITIA_PSB], $hari(-30), 'Mundur', $adminId), 'FI-8 tanggal selesai mendahului tanggal mulai ditolak', 422);

    $service->nonaktifkan(PenugasanJenis::PENDIDIKAN, $idJenis[PenugasanJenis::PENDIDIKAN], 'Uji penonaktifan', $adminId);
    $assert(!in_array('rapor.finalisasi', $fitur($pengurusUser), true), 'FI-8 penugasan yang dinonaktifkan tidak lagi menghasilkan capability');
    $assert($dasar($pengurusUser) === [Capabilities::PENGURUS] && (int) $satu('SELECT is_active FROM users WHERE id = ' . $pengurusUser)['is_active'] === 1, 'FI-8 menonaktifkan penugasan tidak menonaktifkan akun dan tidak mencabut role dasar');
    $assert($angka('SELECT COUNT(*) n FROM pendidikan_assignments WHERE id = ' . $idJenis[PenugasanJenis::PENDIDIKAN]) === 1, 'FI-8 baris penugasan yang dinonaktifkan tetap tersimpan (tidak dihapus)');
    $service->aktifkan(PenugasanJenis::PENDIDIKAN, $idJenis[PenugasanJenis::PENDIDIKAN], 'Uji pengaktifan', $adminId);
    $assert(in_array('rapor.finalisasi', $fitur($pengurusUser), true), 'FI-8 pengaktifan kembali memulihkan capability');

    $service->nonaktifkan(PenugasanJenis::MUROBI, $idJenis[PenugasanJenis::MUROBI], 'Uji nonaktif murobi', $adminId);
    $assert($dasar($guruUser) === [] && !in_array('murobi.binaan', $fitur($guruUser), true), 'FI-8 menonaktifkan murobi dari pusat penugasan mencabut capability murobi V2 dan binaan');
    $service->aktifkan(PenugasanJenis::MUROBI, $idJenis[PenugasanJenis::MUROBI], 'Uji aktif murobi', $adminId);
    $assert($dasar($guruUser) === [Capabilities::MUROBI], 'FI-8 mengaktifkan kembali murobi memulihkan capability V2');

    // =============================================================== FI-13
    $sebelumDup = $angka('SELECT COUNT(*) n FROM guru_mapel_assignments');
    $tolak(static fn () => $service->buat(PenugasanJenis::GURU_MAPEL, ['guru_id' => $guru, 'mata_pelajaran_id' => $mapel, 'kelas_id' => $kelasA, 'tahun_ajaran_id' => $tahunAktif, 'tanggal_mulai' => $hari(0)], $adminId), 'FI-13 penugasan identik ditolak', 409);
    $tolak(static fn () => $service->buat(PenugasanJenis::MUROBI, ['guru_id' => $guru, 'tahun_ajaran_id' => $tahunAktif, 'target_type' => 'Kamar', 'kamar_id' => $kamar, 'tanggal_mulai' => $hari(0)], $adminId), 'FI-13 penugasan murobi identik ditolak', 409);
    $tolak(static fn () => $service->buat(PenugasanJenis::BENDAHARA_PSB, ['pengurus_id' => $pengurus2, 'tahun_ajaran_id' => $tahunDepan, 'gelombang' => '', 'tanggal_mulai' => $hari(0)], $adminId), 'FI-13 penugasan bendahara PSB identik ditolak', 409);
    $assert($angka('SELECT COUNT(*) n FROM guru_mapel_assignments') === $sebelumDup, 'FI-13 penolakan duplikat tidak menyimpan baris');
    $assert($angka("SELECT COUNT(*) n FROM audit_logs WHERE action = 'penugasan.tolak_tumpang_tindih' AND actor_user_id = " . $adminId) >= 3, 'FI-13 percobaan duplikat tercatat pada audit');

    // =============================================================== FI-14
    $tolak(static fn () => $service->buat(PenugasanJenis::GURU_MAPEL, ['guru_id' => $guru, 'mata_pelajaran_id' => $mapel, 'kelas_id' => $kelasA, 'tahun_ajaran_id' => $tahunAktif, 'tanggal_mulai' => $hari(5), 'tanggal_selesai' => $hari(20)], $adminId), 'FI-14 periode beririsan dengan penugasan tanpa akhir ditolak', 409);
    $tolak(static fn () => $service->buat(PenugasanJenis::BENDAHARA_BULANAN, ['pengurus_id' => $pengurus, 'tahun_ajaran_id' => $tahunAktif, 'jenjang' => $jenjang, 'tanggal_mulai' => $hari(1)], $adminId), 'FI-14 cakupan jenjang tertentu bertumpang tindih dengan cakupan seluruh unit ditolak', 409);
    $tolak(static fn () => $service->buat(PenugasanJenis::PANITIA_PSB, ['pengurus_id' => $pengurus2, 'tahun_ajaran_id' => $tahunDepan, 'gelombang' => '2', 'tanggal_mulai' => $hari(0)], $adminId), 'FI-14 gelombang tertentu bertumpang tindih dengan seluruh gelombang ditolak', 409);
    $tolak(static fn () => $service->buat(PenugasanJenis::GURU_MAPEL, ['guru_id' => $guru, 'mata_pelajaran_id' => $mapel2, 'kelas_id' => $kelasB, 'tahun_ajaran_id' => $tahunAktif, 'tanggal_mulai' => $hari(-5), 'tanggal_selesai' => $hari(-3)], $adminId), 'FI-14 periode beririsan dengan penugasan lampau ditolak', 409);

    $sah1 = $service->buat(PenugasanJenis::GURU_MAPEL, ['guru_id' => $guru, 'mata_pelajaran_id' => $mapel, 'kelas_id' => $kelasB, 'tahun_ajaran_id' => $tahunAktif, 'tanggal_mulai' => $hari(0)], $adminId);
    $assert($sah1 > 0, 'FI-14 mata pelajaran sama pada kelas berbeda diterima');
    $sah2 = $service->buat(PenugasanJenis::GURU_MAPEL, ['guru_id' => $guru, 'mata_pelajaran_id' => $mapel2, 'kelas_id' => $kelasB, 'tahun_ajaran_id' => $tahunAktif, 'tanggal_mulai' => $hari(0)], $adminId);
    $assert($sah2 > 0, 'FI-14 periode berurutan setelah penugasan lampau berakhir diterima');
    $sah3 = $service->buat(PenugasanJenis::MUROBI, ['guru_id' => $guru, 'tahun_ajaran_id' => $tahunAktif, 'target_type' => 'Kelas', 'kelas_id' => $kelasA, 'tanggal_mulai' => $hari(0)], $adminId);
    $assert($sah3 > 0, 'FI-14 murobi kamar dan murobi kelas untuk guru yang sama diterima (cakupan berbeda)');
    $tolak(static fn () => $service->ubah(PenugasanJenis::GURU_MAPEL, $sah1, ['mata_pelajaran_id' => $mapel, 'kelas_id' => $kelasA, 'tanggal_mulai' => $hari(0), 'alasan' => 'Pindah kelas'], $adminId), 'FI-14 mengubah cakupan menjadi bertumpang tindih ditolak', 409);
    $tolak(static fn () => $service->aktifkan(PenugasanJenis::GURU_MAPEL, $lampau, 'Coba aktifkan', $adminId), 'FI-14 mengaktifkan kembali penugasan yang sudah aktif ditolak sebagai konflik', 409);

    // =============================================================== FI-5
    foreach ([$guruUser => 'guru', $pengurusUser => 'pengurus', $ortuUser => 'orang tua', 0 => 'anonim'] as $aktor => $label) {
        $tolak(static fn () => $service->buat(PenugasanJenis::PENDIDIKAN, ['pengurus_id' => $pengurus2, 'tahun_ajaran_id' => $tahunAktif, 'jenjang' => '', 'tanggal_mulai' => $hari(0)], $aktor), 'FI-5 ' . $label . ' tidak dapat membuat penugasan', 403);
    }
    $tolak(static fn () => $service->akhiri(PenugasanJenis::PENDIDIKAN, $idJenis[PenugasanJenis::PENDIDIKAN], $hari(0), 'Coba akhiri', $pengurusUser), 'FI-5 pengurus tidak dapat mengakhiri penugasan', 403);
    $tolak(static fn () => $service->nonaktifkan(PenugasanJenis::PENDIDIKAN, $idJenis[PenugasanJenis::PENDIDIKAN], 'Coba nonaktif', $guruUser), 'FI-5 guru tidak dapat menonaktifkan penugasan', 403);
    $tolak(static fn () => $service->ubah(PenugasanJenis::PENDIDIKAN, $idJenis[PenugasanJenis::PENDIDIKAN], ['jenjang' => '', 'tanggal_mulai' => $hari(0), 'alasan' => 'Coba ubah'], $ortuUser), 'FI-5 orang tua tidak dapat mengubah penugasan', 403);
    $tolak(static fn () => $service->simpanMataPelajaran(['nama' => 'Ilegal ' . $suffix], null, $guruUser), 'FI-5 guru tidak dapat menambah mata pelajaran', 403);
    $assert($angka("SELECT COUNT(*) n FROM audit_logs WHERE action = 'penugasan.tolak_hak' AND actor_user_id IN (" . $guruUser . ',' . $pengurusUser . ',' . $ortuUser . ')') >= 6, 'FI-5 percobaan tanpa hak tercatat pada audit');

    // =============================================================== FI-16
    $tolak(static fn () => $service->ubah(PenugasanJenis::BENDAHARA_BULANAN, $idJenis[PenugasanJenis::GURU_MAPEL] + 100000, ['jenjang' => '', 'tanggal_mulai' => $hari(0), 'alasan' => 'Percobaan IDOR'], $adminId), 'FI-16 ID yang tidak ada ditolak (404)', 404);
    $tolak(static fn () => $service->akhiri(PenugasanJenis::GURU_MAPEL, 0, $hari(0), 'IDOR nol', $adminId), 'FI-16 ID nol ditolak', 404);
    $service->ubah(PenugasanJenis::GURU_MAPEL, $sah1, ['guru_id' => $guruTanpaAkun, 'tahun_ajaran_id' => $tahunDepan, 'mata_pelajaran_id' => $mapel, 'kelas_id' => $kelasB, 'tanggal_mulai' => $hari(0), 'catatan' => 'coba ganti orang', 'alasan' => 'Uji manipulasi parameter'], $adminId);
    $barisUbah = $service->detail(PenugasanJenis::GURU_MAPEL, $sah1);
    $assert((int) $barisUbah['subjek_id'] === $guru && (int) $barisUbah['tahun_ajaran_id'] === $tahunAktif, 'FI-16 parameter guru_id/tahun_ajaran_id pada ubah diabaikan (subjek dan tahun dikunci)');
    $tolak(static fn () => $service->buat(PenugasanJenis::GURU_MAPEL, ['guru_id' => $guruNonaktif, 'mata_pelajaran_id' => $mapel, 'kelas_id' => $kelasA, 'tahun_ajaran_id' => $tahunAktif, 'tanggal_mulai' => $hari(0)], $adminId), 'FI-16 guru nonaktif ditolak', 422);
    $tolak(static fn () => $service->buat(PenugasanJenis::GURU_MAPEL, ['guru_id' => $guru, 'mata_pelajaran_id' => $mapel, 'kelas_id' => $kelasNonaktif, 'tahun_ajaran_id' => $tahunAktif, 'tanggal_mulai' => $hari(0)], $adminId), 'FI-16 kelas nonaktif ditolak', 422);
    $tolak(static fn () => $service->buat(PenugasanJenis::GURU_MAPEL, ['guru_id' => $guru, 'mata_pelajaran_id' => 999999999, 'kelas_id' => $kelasA, 'tahun_ajaran_id' => $tahunAktif, 'tanggal_mulai' => $hari(0)], $adminId), 'FI-16 mata pelajaran yang tidak ada ditolak', 422);
    $tolak(static fn () => $service->buat(PenugasanJenis::PENDIDIKAN, ['pengurus_id' => $pengurus2, 'tahun_ajaran_id' => $tahunAktif, 'jenjang' => 'TidakAda' . $suffix, 'tanggal_mulai' => $hari(0)], $adminId), 'FI-16 jenjang yang tidak dipakai kelas ditolak', 422);
    $tolak(static fn () => $service->buat(PenugasanJenis::PANITIA_PSB, ['pengurus_id' => $guru, 'tahun_ajaran_id' => $tahunDepan, 'gelombang' => '', 'tanggal_mulai' => $hari(0)], $adminId), 'FI-16 ID guru dipakai sebagai pengurus ditolak bila bukan pengurus aktif', 422);
    $tolak(static fn () => $service->buat(PenugasanJenis::MUROBI, ['guru_id' => $guru, 'tahun_ajaran_id' => $tahunAktif, 'target_type' => 'Kelas', 'kamar_id' => $kamar, 'kelas_id' => '', 'tanggal_mulai' => $hari(0)], $adminId), 'FI-16 target Kelas tanpa kelas ditolak', 422);
    $tolak(static fn () => $service->buat(PenugasanJenis::MUROBI, ['guru_id' => $guru, 'tahun_ajaran_id' => $tahunAktif, 'target_type' => 'Asrama', 'kamar_id' => $kamar, 'tanggal_mulai' => $hari(0)], $adminId), 'FI-16 jenis target di luar pilihan ditolak', 422);
    $tolak(static fn () => $service->buat(PenugasanJenis::PANITIA_PSB, ['pengurus_id' => $pengurus2, 'tahun_ajaran_id' => $tahunDepan, 'gelombang' => '<script>', 'tanggal_mulai' => $hari(0)], $adminId), 'FI-16 label gelombang dengan karakter tak wajar ditolak', 422);
    $tolak(static fn () => $service->buat(PenugasanJenis::PANITIA_PSB, ['pengurus_id' => $pengurus2, 'tahun_ajaran_id' => $tahunDepan, 'gelombang' => '3', 'tanggal_mulai' => '2026-13-40'], $adminId), 'FI-16 tanggal tidak valid ditolak', 422);
    $tolak(static fn () => $service->buat(PenugasanJenis::PANITIA_PSB, ['pengurus_id' => $pengurus2, 'tahun_ajaran_id' => $tahunDepan, 'gelombang' => '3', 'tanggal_mulai' => $hari(5), 'tanggal_selesai' => $hari(1)], $adminId), 'FI-16 tanggal selesai mendahului tanggal mulai ditolak', 422);
    $tolak(static fn () => $service->buat('bendahara', ['pengurus_id' => $pengurus2], $adminId), 'FI-16 jenis penugasan tak dikenal ditolak', 422);
    $tolak(static fn () => $service->ubah(PenugasanJenis::PENDIDIKAN, $idJenis[PenugasanJenis::PENDIDIKAN], ['jenjang' => '', 'tanggal_mulai' => $hari(0), 'alasan' => 'ok'], $adminId), 'FI-16 alasan perubahan terlalu pendek ditolak', 422);

    // =============================================================== FI-15
    $sebelumTx = $angka('SELECT COUNT(*) n FROM bendahara_psb_assignments');
    $auditSebelumTx = $angka("SELECT COUNT(*) n FROM audit_logs WHERE action = 'penugasan.buat'");
    if ($db->query('RENAME TABLE audit_logs TO audit_logs_fp_' . $kecil) !== false) {
        try {
            $service->buat(PenugasanJenis::BENDAHARA_PSB, ['pengurus_id' => $pengurus, 'tahun_ajaran_id' => $tahunDepan, 'gelombang' => '', 'tanggal_mulai' => $hari(0)], $adminId);
            $assert(false, 'FI-15 kegagalan audit menggagalkan pembuatan (ternyata TIDAK)');
        } catch (Throwable $exception) {
            $assert(true, 'FI-15 kegagalan pencatatan audit menggagalkan pembuatan [' . $exception->getMessage() . ']');
        }
        $db->query('RENAME TABLE audit_logs_fp_' . $kecil . ' TO audit_logs');
        $assert($angka('SELECT COUNT(*) n FROM bendahara_psb_assignments') === $sebelumTx, 'FI-15 rollback penuh: tidak ada baris penugasan tersimpan tanpa audit');
        $assert(!in_array('psb_keuangan.tagihan', $fitur($pengurusUser), true), 'FI-15 tidak ada capability yang bocor dari transaksi yang dibatalkan');
    } else {
        echo "[lewati] FI-15 memerlukan hak RENAME TABLE pada database uji.\n";
    }
    $baru = $service->buat(PenugasanJenis::BENDAHARA_PSB, ['pengurus_id' => $pengurus, 'tahun_ajaran_id' => $tahunDepan, 'gelombang' => '', 'tanggal_mulai' => $hari(0)], $adminId);
    $auditBaru = $satu("SELECT actor_user_id, entity_type, entity_id, after_json FROM audit_logs WHERE action = 'penugasan.buat' AND entity_type = 'bendahara_psb_assignment' AND entity_id = " . $baru);
    $assert($auditBaru !== [] && (int) $auditBaru['actor_user_id'] === $adminId, 'FI-15 pembuatan yang berhasil menulis audit beserta pelakunya');
    $assert($angka("SELECT COUNT(*) n FROM audit_logs WHERE action = 'penugasan.buat'") === $auditSebelumTx + 1, 'FI-15 hanya satu audit pembuatan bertambah');
    $delta = $satu("SELECT before_json, after_json FROM audit_logs WHERE action = 'penugasan.capability_berubah' AND entity_id = " . $pengurusUser . ' ORDER BY id DESC LIMIT 1');
    $sesudahDelta = json_decode((string) ($delta['after_json'] ?? '{}'), true) ?: [];
    $assert(in_array('psb_keuangan.tagihan', $sesudahDelta['bertambah'] ?? [], true), 'FI-15 perubahan capability akun tercatat (bertambah psb_keuangan.*)');

    // =============================================================== FI-25
    $tanpaAkun = $service->buat(PenugasanJenis::PENDIDIKAN, ['pengurus_id' => $pengurusTanpaAkun, 'tahun_ajaran_id' => $tahunAktif, 'jenjang' => '', 'tanggal_mulai' => $hari(0)], $adminId);
    $efektif = $service->capabilityEfektif(PenugasanJenis::PENDIDIKAN, $pengurusTanpaAkun);
    $assert($tanpaAkun > 0 && $efektif['akun'] === null && $efektif['capabilities'] === [] && str_contains((string) $efektif['pesan'], 'belum mempunyai akun'), 'FI-25 penugasan untuk pengurus tanpa akun tersimpan, dijelaskan belum efektif');
    $gm = $service->buat(PenugasanJenis::GURU_MAPEL, ['guru_id' => $guruTanpaRole, 'mata_pelajaran_id' => $mapel, 'kelas_id' => $kelasA, 'tahun_ajaran_id' => $tahunAktif, 'tanggal_mulai' => $hari(0)], $adminId);
    $assert($fitur($guruTanpaRoleUser) === [], 'FI-25 akun tanpa role guru tidak memperoleh capability walau penugasan aktif');
    $efektifNr = $service->capabilityEfektif(PenugasanJenis::GURU_MAPEL, $guruTanpaRole);
    $assert($efektifNr['capabilities'] === [] && str_contains((string) $efektifNr['pesan'], 'belum memegang role'), 'FI-25 pusat penugasan menjelaskan role dasar yang belum diberikan');
    $exec("INSERT INTO user_roles (user_id, role_id, assigned_by) SELECT ?, id, NULL FROM roles WHERE slug = 'guru'", [$guruTanpaRoleUser]);
    $assert(in_array('nilai.input', $fitur($guruTanpaRoleUser), true), 'FI-25 setelah role guru diberikan, capability langsung efektif tanpa mengubah penugasan');
    $exec("DELETE FROM user_roles WHERE user_id = ? AND role_id = (SELECT id FROM roles WHERE slug = 'guru')", [$guruTanpaRoleUser]);
    $assert($fitur($guruTanpaRoleUser) === [] && $angka('SELECT COUNT(*) n FROM guru_mapel_assignments WHERE id = ' . $gm) === 1, 'FI-25 mencabut role dasar mematikan capability tanpa menghapus riwayat penugasan');
    $exec('UPDATE guru SET is_active = 0 WHERE id = ?', [$guru]);
    $assert($fitur($guruUser) === [] && $dasar($guruUser) === [], 'FI-25 master guru nonaktif mematikan seluruh capability penugasan');
    $exec('UPDATE guru SET is_active = 1 WHERE id = ?', [$guru]);
    $exec('UPDATE users SET is_active = 0 WHERE id = ?', [$pengurusUser]);
    $caps->forget($pengurusUser);
    $assert($caps->featureList(['id' => $pengurusUser, 'roles' => ['pengurus', 'admin'], 'guru_id' => null]) === [], 'FI-25 akun nonaktif tidak memperoleh capability apa pun');
    $exec('UPDATE users SET is_active = 1 WHERE id = ?', [$pengurusUser]);
    $exec('UPDATE mata_pelajaran SET is_active = 0 WHERE id = ?', [$mapel]);
    $guruUserRow = $loadUser($guruUser);
    $caps->forget($guruUser);
    $assert(!$caps->featureAppliesToMataPelajaran($guruUserRow, 'nilai.input', $mapel, $kelasA, $tahunAktif), 'FI-25 mata pelajaran nonaktif menghentikan capability penugasan yang memakainya');
    $exec('UPDATE mata_pelajaran SET is_active = 1 WHERE id = ?', [$mapel]);

    // =============================================================== FI-23
    $_SESSION = ['user_id' => $guruUser, 'roles' => ['admin'], 'capabilities' => ['admin', 'psb.pendaftar']];
    $dariSesi = authorization()->currentUser();
    $caps->forget($guruUser);
    $assert($dariSesi !== null && $dariSesi['roles'] === ['guru'], 'FI-23 role sesi yang dimanipulasi ditimpa role dari basis data');
    $assert(!in_array('admin', $caps->forUser($dariSesi), true), 'FI-23 capability admin tidak muncul dari sesi palsu');
    $assert($caps->featureList(['id' => $guruUser, 'roles' => ['admin', 'pengurus'], 'guru_id' => $guru]) === $fitur($guruUser), 'FI-23 role palsu pada input resolver diabaikan; hasil sama dengan role basis data');
    $assert(!in_array('psb.pendaftar', $caps->featureList(['id' => $guruUser, 'roles' => ['admin'], 'guru_id' => $guru]), true), 'FI-23 capability pengurus tidak diperoleh guru lewat manipulasi input');
    $caps->forget($guruUser);
    $tolak(static fn () => $service->buat(PenugasanJenis::PENDIDIKAN, ['pengurus_id' => $pengurus2, 'tahun_ajaran_id' => $tahunAktif, 'jenjang' => '', 'tanggal_mulai' => $hari(0)], $guruUser), 'FI-23 sesi palsu tidak memberi hak mengelola penugasan', 403);
    $_SESSION = ['user_id' => $adminId];

    // =============================================================== FI-19 / FI-20
    $apiUser = static function (int $id) use ($db): array {
        $row = $db->query('SELECT u.id, u.name, u.username, u.guru_id, g.nip, g.nama_guru FROM users u LEFT JOIN guru g ON g.id = u.guru_id WHERE u.id = ' . $id)->fetch_assoc();
        $user = auth_repository()->findActiveById($id);

        return $row + ['roles' => $user['roles'] ?? []];
    };
    $profilGuru = api_auth_service()->profile($apiUser($guruUser));
    $assert(array_keys($profilGuru) === ['id', 'name', 'username', 'guru', 'roles', 'capabilities', 'feature_capabilities'], 'FI-19 profil memuat field lama pada urutan lama + satu field aditif');
    $assert(array_keys($profilGuru['capabilities']) === ['list', 'default_mode', 'konteks', 'menus', 'aksi'], 'FI-19 struktur capabilities lama tidak berubah');
    $assert($profilGuru['capabilities']['list'] === ['murobi'] && $profilGuru['capabilities']['default_mode'] === 'murobi', 'FI-19 mode guru-murobi tetap seperti sebelumnya');
    $assert(array_column($profilGuru['capabilities']['menus'], 'key') === ['jadwal', 'laporan', 'izin_murobi'], 'FI-20 menu aplikasi guru tidak berubah walau punya penugasan mapel');
    $assert(in_array('nilai.input', $profilGuru['feature_capabilities']['list'], true) && ($profilGuru['feature_capabilities']['sumber']['nilai.input'] ?? '') === 'penugasan', 'FI-19 field aditif memuat capability fondasi beserta sumbernya');
    $assert(!isset($profilGuru['feature_capabilities']['menus']) && !isset($profilGuru['feature_capabilities']['default_mode']), 'FI-20 field aditif tidak membawa menu atau mode baru');
    $profilPengurus = api_auth_service()->profile($apiUser($pengurusUser));
    $assert($profilPengurus['capabilities']['default_mode'] === 'pengurus' && array_column($profilPengurus['capabilities']['menus'], 'key') === ['izin_pengurus'], 'FI-20 mode dan menu pengurus tidak berubah walau punya banyak penugasan');
    $assert(!in_array('bendahara', $profilPengurus['capabilities']['list'], true) && !in_array('pendidikan', $profilPengurus['capabilities']['list'], true), 'FI-20 tidak ada mode baru pendidikan/bendahara pada daftar mode');
    $profilOrtu = api_auth_service()->profile($apiUser($ortuUser));
    $assert($profilOrtu['capabilities']['list'] === ['orang_tua'] && $profilOrtu['feature_capabilities']['list'] === [], 'FI-19 profil orang tua tidak berubah dan tanpa feature capability');

    // =============================================================== FI-21
    $auth = new AuthService(auth_repository(), audit_logger());
    foreach (['fp.admin.' . $kecil => 'admin', 'fp.guru.' . $kecil => 'guru', 'fp.pengurus.' . $kecil => 'pengurus', 'fp.ortu.' . $kecil => 'orang tua'] as $username => $label) {
        $_SESSION = [];
        $assert(@$auth->attempt($username, $sandi), 'FI-21 login web ' . $label . ' tetap berfungsi');
    }
    $_SESSION = ['user_id' => $adminId];

    // =============================================================== FI-24
    $auditSensitif = $angka("SELECT COUNT(*) n FROM audit_logs WHERE created_at >= '" . $mulaiUji . "' AND action LIKE 'penugasan.%' AND (before_json LIKE '%password%' OR after_json LIKE '%password%' OR after_json LIKE '%token%' OR before_json LIKE '%token%')");
    $assert($auditSensitif === 0, 'FI-24 audit penugasan tidak memuat password/token');
    $contoh = $satu("SELECT before_json, after_json, ip_address, user_agent FROM audit_logs WHERE action = 'penugasan.ubah' ORDER BY id DESC LIMIT 1");
    $sebelumJson = json_decode((string) ($contoh['before_json'] ?? ''), true);
    $sesudahJson = json_decode((string) ($contoh['after_json'] ?? ''), true);
    $assert(is_array($sebelumJson) && is_array($sesudahJson) && isset($sesudahJson['alasan']) && isset($sebelumJson['jenis']) && isset($sesudahJson['subjek_id']), 'FI-24 audit perubahan menyimpan nilai sebelum/sesudah, jenis, subjek, dan alasan');
    foreach (['penugasan.buat', 'penugasan.ubah', 'penugasan.akhiri', 'penugasan.nonaktifkan', 'penugasan.aktifkan', 'penugasan.capability_berubah', 'penugasan.tolak_tumpang_tindih', 'penugasan.tolak_hak'] as $aksi) {
        $assert($angka("SELECT COUNT(*) n FROM audit_logs WHERE action = '" . $aksi . "' AND created_at >= '" . $mulaiUji . "'") >= 1, 'FI-24 aksi audit tercatat: ' . $aksi);
    }

    // =============================================================== daftar & filter
    $daftar = $service->daftar(PenugasanJenis::GURU_MAPEL, ['q' => 'Guru Uji ' . $suffix, 'status' => 'aktif', 'kelas_id' => $kelasB], 1);
    $assert((int) $daftar['total'] === 2 && $daftar['rows'][0]['status_label'] === PenugasanService::STATUS_AKTIF, 'FI-daftar filter nama + status + kelas mengembalikan penugasan yang tepat');
    $daftarBerakhir = $service->daftar(PenugasanJenis::GURU_MAPEL, ['q' => 'Guru Uji ' . $suffix, 'status' => 'berakhir'], 1);
    $assert((int) $daftarBerakhir['total'] === 1 && (int) $daftarBerakhir['rows'][0]['id'] === $lampau, 'FI-daftar filter Berakhir mengembalikan penugasan lampau');
    $daftarNanti = $service->daftar(PenugasanJenis::BENDAHARA_BULANAN, ['q' => 'Pengurus Dua ' . $suffix, 'status' => 'akan_datang'], 1);
    $assert((int) $daftarNanti['total'] === 1 && (int) $daftarNanti['rows'][0]['id'] === $nanti, 'FI-daftar filter Akan Datang mengembalikan penugasan mendatang');
    $daftarXss = $service->daftar(PenugasanJenis::PENDIDIKAN, ['q' => "' OR 1=1 --", 'jenjang' => "x' OR '1'='1"], 1);
    $assert((int) $daftarXss['total'] === 0, 'FI-daftar nilai filter berbahaya diperlakukan sebagai teks biasa');
} finally {
    $_SESSION = [];
    foreach ($dibuat['users'] as $id) {
        $db->query('DELETE FROM audit_logs WHERE actor_user_id = ' . (int) $id . " OR (entity_type = 'user' AND entity_id = " . (int) $id . ')');
    }
    $db->query("DELETE FROM audit_logs WHERE created_at >= '" . $mulaiUji . "' AND (action LIKE 'penugasan.%' OR action LIKE 'mata_pelajaran.%')");
    foreach ($dibuat['guru'] as $id) {
        $db->query('DELETE FROM guru_mapel_assignments WHERE guru_id = ' . (int) $id);
        $db->query('DELETE FROM murobi_assignments WHERE guru_id = ' . (int) $id);
    }
    foreach ($dibuat['pengurus'] as $id) {
        foreach (['pembimbing_assignments', 'pendidikan_assignments', 'bendahara_bulanan_assignments', 'panitia_psb_assignments', 'bendahara_psb_assignments'] as $t) {
            $db->query('DELETE FROM ' . $t . ' WHERE pengurus_id = ' . (int) $id);
        }
    }
    foreach ($dibuat['mapel'] as $id) {
        $db->query('DELETE FROM mata_pelajaran WHERE id = ' . (int) $id);
    }
    foreach ($dibuat['users'] as $id) {
        $db->query('DELETE FROM user_roles WHERE user_id = ' . (int) $id);
        $db->query('DELETE FROM users WHERE id = ' . (int) $id);
    }
    foreach ($dibuat['guru'] as $id) {
        $db->query('DELETE FROM guru WHERE id = ' . (int) $id);
    }
    foreach ($dibuat['pengurus'] as $id) {
        $db->query('DELETE FROM pengurus WHERE id = ' . (int) $id);
    }
    foreach ($dibuat['wali'] as $id) {
        $db->query('DELETE FROM wali WHERE id = ' . (int) $id);
    }
    foreach ($dibuat['kelas'] as $id) {
        $db->query('DELETE FROM kelas WHERE id = ' . (int) $id);
    }
    foreach ($dibuat['kamar'] as $id) {
        $db->query('DELETE FROM kamar WHERE id = ' . (int) $id);
    }
    foreach ($dibuat['tahun'] as $id) {
        $db->query('DELETE FROM tahun_ajaran WHERE id = ' . (int) $id);
    }
    $db->query('DROP TABLE IF EXISTS audit_logs_fp_' . $kecil);
}

echo PHP_EOL . 'Total gagal: ' . count($failures) . PHP_EOL;
exit($failures === [] ? 0 : 1);
