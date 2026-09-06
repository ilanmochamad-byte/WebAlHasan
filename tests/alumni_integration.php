<?php

declare(strict_types=1);

/**
 * Pengujian integrasi "Koreksi Pengelolaan Alumni"
 * (keputusan pengguna 6 September 2026) pada basis data sungguhan.
 *
 *   AL-1  kelulusan individual menyimpan snapshot lengkap;
 *   AL-2  status Pindah individual;
 *   AL-3  status Berhenti individual;
 *   AL-4  pemrosesan massal satu kelas bersifat atomik;
 *   AL-5  penempatan kelas aktif ditutup, barisnya tetap sebagai riwayat;
 *   AL-6  penempatan kamar semester berjalan dilepas dan tercatat;
 *   AL-7  santri sumber TIDAK dihapus; ia menjadi arsip dan tetap terbaca;
 *   AL-8  riwayat kelas dan kamar tahun ajaran LAIN tidak tersentuh;
 *   AL-9  relasi wali, identitas wali, dan akun wali tidak terhapus;
 *   AL-10 memproses santri yang sama dua kali ditolak, bukan digandakan;
 *   AL-11 request ganda tidak menghasilkan alumni ganda (kunci unik basis data);
 *   AL-12 kegagalan penyimpanan alumni membatalkan SELURUH perubahan;
 *   AL-13 kegagalan penutupan kelas membatalkan SELURUH perubahan;
 *   AL-14 arsip dan pemulihan catatan alumni beralasan wajib;
 *   AL-15 pembatalan kelulusan mengaktifkan santri tanpa membuat penempatan;
 *   AL-16 nilai filter berbahaya (SQL/XSS) diperlakukan sebagai teks biasa;
 *   AL-17 catatan alumni warisan tetap terbaca, terfilter, dan tidak berubah;
 *   AL-18 regresi: penempatan kelas/kamar dan daftar santri aktif tetap benar;
 *   AL-19 kegagalan audit membatalkan SELURUH perubahan;
 *   AL-20 validasi status, tanggal, tahun, tingkat, dan batas massal di server.
 *
 * Seluruh fixture memakai data FIKTIF berakhiran acak dan dihapus kembali pada
 * blok `finally`. Tidak ada permintaan jaringan keluar dan tidak ada data
 * produksi yang disentuh.
 *
 * Jalankan hanya pada database berakhiran `_test`:
 *   ALUMNI_RUN_INTEGRATION=1 php tests/alumni_integration.php
 */

$root = dirname(__DIR__);
if (getenv('ALUMNI_RUN_INTEGRATION') !== '1') {
    fwrite(STDOUT, "[lewati] Set ALUMNI_RUN_INTEGRATION=1 dan arahkan DB_NAME ke database khusus *_test.\n");
    exit(77);
}

require_once $root . '/app/bootstrap.php';

use App\MasterData\AlumniRepository;
use App\MasterData\AlumniService;
use App\MasterData\MasterDataException;
use App\MasterData\PenempatanService;

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
$tolak = static function (callable $aksi, string $pesan) use ($assert): void {
    try {
        $aksi();
        $assert(false, $pesan . ' (ternyata TIDAK ditolak)');
    } catch (Throwable $exception) {
        $assert(true, $pesan . ' [' . $exception->getMessage() . ']');
    }
};

$db = app_db();
$suffix = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
$kecil = strtolower($suffix);

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

$dibuat = ['users' => [], 'santri' => [], 'kelas' => [], 'kamar' => [], 'wali' => [], 'tahun' => [], 'alumni' => []];
$triggerKelas = false;
$triggerAlumni = false;
$tahunLamaDinonaktifkan = false;

try {
    $service = alumni_service();
    $penempatan = penempatan_service();
    $repository = new AlumniRepository($db);

    if (!$repository->schemaSiap()) {
        fwrite(STDERR, "Migrasi 011 belum dijalankan pada database uji. Jalankan 'php bin/migrate.php up'.\n");
        exit(2);
    }

    // ------------------------------------------------------------- fixture
    $adminId = $exec(
        'INSERT INTO users (name, username, password, is_active, force_password_change, created_at, updated_at)
         VALUES (?, ?, ?, 1, 0, NOW(), NOW())',
        ['Admin Alumni ' . $suffix, 'al.admin.' . $kecil, password_hash('UjiAlumni123Aa', PASSWORD_DEFAULT)]
    );
    $dibuat['users'][] = $adminId;
    $exec("INSERT INTO user_roles (user_id, role_id, assigned_by) SELECT ?, id, ? FROM roles WHERE slug = 'admin'", [$adminId, $adminId]);
    $_SESSION['user_id'] = $adminId;

    $year = $service->activeYear();
    $assert(is_array($year), 'AL-0 tahun ajaran aktif tersedia untuk pengujian');
    $yearId = (int) $year['id'];

    // Tahun ajaran LAMA untuk membuktikan riwayat semester lain tidak disentuh.
    $tahunLama = $exec(
        "INSERT INTO tahun_ajaran (tahun, semester, status, created_at, updated_at) VALUES (?, 'Ganjil', 'Non-Aktif', NOW(), NOW())",
        ['LAMA' . $suffix]
    );
    $dibuat['tahun'][] = $tahunLama;

    $kelasA = $exec("INSERT INTO kelas (nama_kelas, jenjang, is_active, created_at, updated_at) VALUES (?, 'Uji', 1, NOW(), NOW())", ['AL Kelas A ' . $suffix]);
    $kelasB = $exec("INSERT INTO kelas (nama_kelas, jenjang, is_active, created_at, updated_at) VALUES (?, 'Uji', 1, NOW(), NOW())", ['AL Kelas B ' . $suffix]);
    $dibuat['kelas'] = [$kelasA, $kelasB];

    $kamarA = $exec('INSERT INTO kamar (nama_kamar, kapasitas) VALUES (?, 10)', ['AL Kamar A ' . $suffix]);
    $dibuat['kamar'][] = $kamarA;

    /** @var array<string, int> $santri */
    $santri = [];
    foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G'] as $index => $tanda) {
        $santri[$tanda] = $exec(
            "INSERT INTO santri (nis, nama_santri, jenis_kelamin, tempat_lahir, tgl_lahir, alamat, desa, kecamatan, kab_kota, provinsi,
                                 nama_ayah, no_hp_ayah, nama_ibu, no_hp_ibu, asal_sekolah, sekolah_saat_ini, foto, is_active, created_at, updated_at)
             VALUES (?, ?, 'L', 'Ciamis', '2011-03-0" . ($index + 1) . "', 'Jl Uji 1', 'Desa Uji', 'Kec Uji', 'Kab Uji', 'Jabar',
                     ?, '081234567890', ?, '081234567891', 'SD Uji', ?, 'default.jpg', 1, NOW(), NOW())",
            [
                'AL' . $suffix . $tanda,
                'Santri Alumni ' . $tanda . ' ' . $suffix,
                'Ayah ' . $tanda . ' ' . $suffix,
                'Ibu ' . $tanda . ' ' . $suffix,
                'MTs Uji ' . $suffix,
            ]
        );
        $dibuat['santri'][] = $santri[$tanda];
    }

    // Wali + relasi + akun wali, untuk membuktikan tidak ada yang dihapus.
    $waliId = $exec(
        'INSERT INTO wali (nama, no_hp, alamat, is_active, created_at, updated_at) VALUES (?, ?, ?, 1, NOW(), NOW())',
        ['Wali Uji ' . $suffix, '081200000000', 'Jl Wali']
    );
    $dibuat['wali'][] = $waliId;
    $exec(
        "INSERT INTO santri_wali (santri_id, wali_id, hubungan, is_primary, created_at) VALUES (?, ?, 'Ayah', 1, NOW())",
        [$santri['A'], $waliId]
    );
    $akunWaliId = $exec(
        'INSERT INTO users (name, username, password, wali_id, is_active, force_password_change, created_at, updated_at)
         VALUES (?, ?, ?, ?, 1, 0, NOW(), NOW())',
        ['Akun Wali ' . $suffix, 'al.wali.' . $kecil, password_hash('UjiWali123Aa', PASSWORD_DEFAULT), $waliId]
    );
    $dibuat['users'][] = $akunWaliId;

    // Penempatan awal: kelas + kamar pada semester aktif, dan kelas pada
    // semester LAMA sebagai riwayat.
    foreach (['A', 'B', 'C', 'D', 'E'] as $tanda) {
        $penempatan->apply(PenempatanService::AKSI_KELAS_TETAPKAN, [$santri[$tanda]], ['kelas_id' => $kelasA, 'tanggal_mulai' => '2026-07-01'], $adminId);
    }
    $penempatan->apply(PenempatanService::AKSI_KAMAR_TETAPKAN, [$santri['A'], $santri['B']], ['kamar_id' => $kamarA], $adminId);
    $exec(
        "INSERT INTO plotting_kelas (id_santri, id_kelas, id_tahun, tanggal_mulai, tanggal_selesai, status, created_at, updated_at)
         VALUES (?, ?, ?, '2025-07-01', '2026-06-30', 'Selesai', NOW(), NOW())",
        [$santri['A'], $kelasB, $tahunLama]
    );
    $exec('INSERT INTO plotting_kamar (id_santri, id_kamar, id_tahun) VALUES (?, ?, ?)', [$santri['A'], $kamarA, $tahunLama]);

    $opsiLulus = [
        'status_keluar' => 'Lulus',
        'tgl_keluar' => '2026-06-30',
        'tahun_angkatan' => '2025/2026',
        'tingkat' => 'Tsanawi',
        'catatan' => 'Lulus tepat waktu ' . $suffix,
    ];

    // ================================================================ AL-1
    $riwayatKelasSebelum = $angka("SELECT COUNT(*) n FROM plotting_kelas WHERE id_santri = {$santri['A']}");
    $hasil = $service->terapkan([$santri['A']], $opsiLulus, $adminId);
    $alumniA = (int) $hasil['alumni_id'];
    $dibuat['alumni'][] = $alumniA;
    $rowA = $satu('SELECT * FROM alumni WHERE id = ' . $alumniA);
    $assert(
        (int) ($rowA['santri_id'] ?? 0) === $santri['A']
        && ($rowA['nis'] ?? '') === 'AL' . $suffix . 'A'
        && ($rowA['status_keluar'] ?? '') === 'Lulus'
        && ($rowA['tgl_keluar'] ?? '') === '2026-06-30'
        && ($rowA['tahun_angkatan'] ?? '') === '2025/2026'
        && ($rowA['tingkat'] ?? '') === 'Tsanawi',
        'AL-1 kelulusan individual menyimpan referensi santri dan keterangan keluar'
    );
    $assert(
        ($rowA['jenis_kelamin'] ?? '') === 'L' && ($rowA['tempat_lahir'] ?? '') === 'Ciamis'
        && ($rowA['alamat'] ?? '') === 'Jl Uji 1' && ($rowA['nama_ayah'] ?? '') === 'Ayah A ' . $suffix
        && ($rowA['no_hp_ibu'] ?? '') === '081234567891' && ($rowA['foto'] ?? '') === 'default.jpg',
        'AL-1 snapshot identitas, alamat, orang tua, dan foto tersimpan'
    );
    $assert(
        ($rowA['unit_terakhir'] ?? '') === 'MTs Uji ' . $suffix
        && ($rowA['kelas_terakhir'] ?? '') === 'AL Kelas A ' . $suffix
        && ($rowA['kamar_terakhir'] ?? '') === 'AL Kamar A ' . $suffix,
        'AL-1 unit, kelas, dan kamar terakhir tersimpan sebagai snapshot'
    );
    $assert(
        (int) ($rowA['created_by'] ?? 0) === $adminId && ($rowA['created_at'] ?? '') !== ''
        && ($rowA['catatan'] ?? '') === 'Lulus tepat waktu ' . $suffix,
        'AL-1 pelaku, waktu, dan catatan proses tercatat'
    );
    $auditA = $satu("SELECT action, before_json, after_json, actor_user_id FROM audit_logs WHERE entity_type = 'alumni' AND entity_id = {$alumniA} ORDER BY id DESC LIMIT 1");
    $assert(
        ($auditA['action'] ?? '') === 'alumni.proses'
        && (int) ($auditA['actor_user_id'] ?? 0) === $adminId
        && str_contains((string) $auditA['after_json'], '"santri_diarsipkan":true')
        && str_contains((string) $auditA['after_json'], '"kelas_ditutup":true'),
        'AL-1 audit memuat pelaku, nilai sebelum/sesudah, dan tindakan yang dilakukan'
    );

    // ================================================================ AL-5
    $kelasAktifA = $angka("SELECT COUNT(*) n FROM plotting_kelas WHERE id_santri = {$santri['A']} AND id_tahun = {$yearId} AND status = 'Aktif'");
    $kelasSelesaiA = $satu("SELECT status, tanggal_selesai FROM plotting_kelas WHERE id_santri = {$santri['A']} AND id_tahun = {$yearId} ORDER BY id DESC LIMIT 1");
    $assert($kelasAktifA === 0, 'AL-5 penempatan kelas aktif ditutup setelah kelulusan');
    $assert(
        ($kelasSelesaiA['status'] ?? '') === 'Selesai' && ($kelasSelesaiA['tanggal_selesai'] ?? '') === '2026-06-30',
        'AL-5 baris kelas menjadi Selesai dengan tanggal keluar, bukan dihapus'
    );

    // ================================================================ AL-6
    $assert(
        $angka("SELECT COUNT(*) n FROM plotting_kamar WHERE id_santri = {$santri['A']} AND id_tahun = {$yearId}") === 0,
        'AL-6 penempatan kamar semester berjalan dilepas sehingga tempatnya tersedia'
    );
    $assert(
        str_contains((string) $auditA['after_json'], '"kamar_dilepas":1')
        && str_contains((string) $auditA['before_json'], 'AL Kamar A ' . $suffix),
        'AL-6 kamar sebelumnya tercatat pada audit dan pada snapshot alumni'
    );

    // ================================================================ AL-7
    $santriA = $satu('SELECT * FROM santri WHERE id = ' . $santri['A']);
    $assert($santriA !== [], 'AL-7 baris santri sumber TIDAK dihapus');
    $assert(
        (int) ($santriA['is_active'] ?? 1) === 0 && ($santriA['archived_at'] ?? null) !== null,
        'AL-7 santri sumber berstatus arsip/nonaktif'
    );
    $daftarAktif = master_data_service()->santriList(['q' => 'AL' . $suffix . 'A', 'state' => 'active'], 1);
    $assert((int) $daftarAktif['total'] === 0, 'AL-7 santri alumni tidak lagi muncul pada daftar santri aktif');
    $daftarArsip = master_data_service()->santriList(['q' => 'AL' . $suffix . 'A', 'state' => 'archived'], 1);
    $assert((int) $daftarArsip['total'] === 1, 'AL-7 santri alumni tetap dapat ditemukan lewat filter arsip');

    // ================================================================ AL-8
    $assert(
        $angka("SELECT COUNT(*) n FROM plotting_kelas WHERE id_santri = {$santri['A']}") === $riwayatKelasSebelum,
        'AL-8 tidak ada baris riwayat kelas yang dihapus'
    );
    $assert(
        $angka("SELECT COUNT(*) n FROM plotting_kelas WHERE id_santri = {$santri['A']} AND id_tahun = {$tahunLama} AND status = 'Selesai'") === 1,
        'AL-8 riwayat kelas tahun ajaran sebelumnya tetap utuh'
    );
    $assert(
        $angka("SELECT COUNT(*) n FROM plotting_kamar WHERE id_santri = {$santri['A']} AND id_tahun = {$tahunLama}") === 1,
        'AL-8 penempatan kamar tahun ajaran sebelumnya TIDAK disentuh'
    );

    // ================================================================ AL-9
    $assert(
        $angka("SELECT COUNT(*) n FROM santri_wali WHERE santri_id = {$santri['A']} AND archived_at IS NULL") === 1,
        'AL-9 relasi wali santri tidak dihapus dan tidak diarsipkan'
    );
    $assert(
        $angka("SELECT COUNT(*) n FROM wali WHERE id = {$waliId} AND is_active = 1 AND archived_at IS NULL") === 1,
        'AL-9 identitas wali tetap aktif'
    );
    $assert(
        $angka("SELECT COUNT(*) n FROM users WHERE id = {$akunWaliId} AND is_active = 1") === 1,
        'AL-9 akun wali tidak dinonaktifkan otomatis'
    );

    // ============================================================ AL-2, AL-3
    $hasilPindah = $service->terapkan([$santri['B']], [
        'status_keluar' => 'Pindah', 'tgl_keluar' => '2026-05-20',
        'tahun_angkatan' => '2026', 'tingkat' => 'Ibtida', 'catatan' => 'Ikut orang tua',
    ], $adminId);
    $alumniB = (int) $hasilPindah['alumni_id'];
    $dibuat['alumni'][] = $alumniB;
    $rowB = $satu('SELECT * FROM alumni WHERE id = ' . $alumniB);
    $assert(
        ($rowB['status_keluar'] ?? '') === 'Pindah' && ($rowB['tahun_angkatan'] ?? '') === '2026'
        && ($rowB['tingkat'] ?? '') === 'Ibtida'
        && $angka("SELECT COUNT(*) n FROM plotting_kamar WHERE id_santri = {$santri['B']} AND id_tahun = {$yearId}") === 0,
        'AL-2 status Pindah individual tersimpan dan kamar ikut dilepas'
    );

    $hasilBerhenti = $service->terapkan([$santri['C']], [
        'status_keluar' => 'Berhenti', 'tgl_keluar' => '2026-04-10',
        'tahun_angkatan' => '2026', 'tingkat' => 'Ibtida', 'catatan' => '',
    ], $adminId);
    $alumniC = (int) $hasilBerhenti['alumni_id'];
    $dibuat['alumni'][] = $alumniC;
    $rowC = $satu('SELECT * FROM alumni WHERE id = ' . $alumniC);
    $assert(
        ($rowC['status_keluar'] ?? '') === 'Berhenti' && $rowC['catatan'] === null,
        'AL-3 status Berhenti individual tersimpan; catatan kosong disimpan sebagai NULL'
    );

    // =============================================================== AL-10
    $tolak(
        static fn () => $service->terapkan([$santri['A']], $opsiLulus, $adminId),
        'AL-10 memproses santri yang sudah menjadi alumni ditolak'
    );
    $assert(
        $angka("SELECT COUNT(*) n FROM alumni WHERE santri_id = {$santri['A']}") === 1,
        'AL-10 tidak ada catatan alumni ganda yang tercipta'
    );

    // =============================================================== AL-11
    // Lapis kedua: kunci unik basis data menolak penyisipan langsung, sekalipun
    // pemeriksaan aplikasi dilewati (mis. dua request benar-benar bersamaan).
    $gandaDitolak = false;
    try {
        $exec(
            "INSERT INTO alumni (santri_id, nis, nama_santri, jenis_kelamin, tempat_lahir, tgl_lahir, alamat, desa, kecamatan,
                                 kab_kota, provinsi, nama_ayah, nama_ibu, asal_sekolah, unit_terakhir, tahun_angkatan, tingkat,
                                 status_keluar, tgl_keluar, foto)
             VALUES (?, ?, 'Duplikat', 'L', 'Ciamis', '2011-01-01', 'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', '2026', 'Ibtida', 'Lulus', '2026-06-30', 'default.jpg')",
            [$santri['A'], 'AL' . $suffix . 'A2']
        );
    } catch (Throwable $exception) {
        $gandaDitolak = str_contains($exception->getMessage(), 'Duplicate') || str_contains($exception->getMessage(), 'duplicate');
    }
    $assert($gandaDitolak, 'AL-11 kunci unik basis data menolak catatan alumni aktif kedua untuk santri yang sama');

    $nisGandaDitolak = false;
    try {
        $exec(
            "INSERT INTO alumni (nis, nama_santri, jenis_kelamin, tempat_lahir, tgl_lahir, alamat, desa, kecamatan,
                                 kab_kota, provinsi, nama_ayah, nama_ibu, asal_sekolah, unit_terakhir, tahun_angkatan, tingkat,
                                 status_keluar, tgl_keluar, foto)
             VALUES (?, 'Duplikat NIS', 'L', 'Ciamis', '2011-01-01', 'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', '2026', 'Ibtida', 'Lulus', '2026-06-30', 'default.jpg')",
            ['AL' . $suffix . 'A']
        );
    } catch (Throwable $exception) {
        $nisGandaDitolak = str_contains(strtolower($exception->getMessage()), 'duplicate');
    }
    $assert($nisGandaDitolak, 'AL-11 kunci unik basis data menolak NIS alumni aktif yang sama');

    // ================================================================ AL-4
    // D dan E masih di kelas A. F belum punya kelas. Massal berdasarkan kelas.
    $idsKelas = $service->santriAktifPadaKelas($kelasA, $yearId);
    sort($idsKelas);
    $assert($idsKelas === [$santri['D'], $santri['E']], 'AL-4 daftar santri aktif satu kelas hanya memuat yang belum diproses');

    $auditSebelumMassal = $angka('SELECT COUNT(*) n FROM audit_logs');
    $hasilMassal = $service->terapkan($idsKelas, [
        'status_keluar' => 'Lulus', 'tgl_keluar' => '2026-06-30',
        'tahun_angkatan' => '2025/2026', 'tingkat' => 'Tsanawi', 'catatan' => 'Kelulusan kelas ' . $suffix,
    ], $adminId);
    foreach ($hasilMassal['baris'] as $barisMassal) {
        $dibuat['alumni'][] = (int) $barisMassal['alumni_id'];
    }
    $assert(
        (int) $hasilMassal['jumlah'] === 2
        && $angka("SELECT COUNT(*) n FROM alumni WHERE santri_id IN ({$santri['D']}, {$santri['E']})") === 2,
        'AL-4 pemrosesan massal satu kelas membuat catatan alumni untuk seluruh santrinya'
    );
    $assert(
        $angka("SELECT COUNT(*) n FROM santri WHERE id IN ({$santri['D']}, {$santri['E']}) AND is_active = 0 AND archived_at IS NOT NULL") === 2,
        'AL-4 seluruh santri massal berstatus arsip'
    );
    $assert(
        $angka("SELECT COUNT(*) n FROM plotting_kelas WHERE id_santri IN ({$santri['D']}, {$santri['E']}) AND id_tahun = {$yearId} AND status = 'Aktif'") === 0,
        'AL-4 kelas aktif seluruh santri massal ditutup'
    );
    $ringkasanMassal = $satu("SELECT after_json FROM audit_logs WHERE action = 'alumni.massal' ORDER BY id DESC LIMIT 1");
    $assert(
        str_contains((string) ($ringkasanMassal['after_json'] ?? ''), '"jumlah_santri":2')
        && str_contains((string) ($ringkasanMassal['after_json'] ?? ''), 'Kelulusan kelas ' . $suffix),
        'AL-4 audit ringkasan massal mencatat jumlah santri dan alasannya'
    );
    $assert($angka('SELECT COUNT(*) n FROM audit_logs') > $auditSebelumMassal, 'AL-4 audit bertambah pada pemrosesan massal');
    $assert($service->santriAktifPadaKelas($kelasA, $yearId) === [], 'AL-4 kelas menjadi kosong dari santri aktif setelah diproses');

    // =============================================================== AL-20
    $tolak(static fn () => $service->terapkan([$santri['F']], ['status_keluar' => 'Meninggal'] + $opsiLulus, $adminId), 'AL-20 status keluar di luar daftar sah ditolak');
    $tolak(static fn () => $service->terapkan([$santri['F']], ['tgl_keluar' => '30-06-2026'] + $opsiLulus, $adminId), 'AL-20 tanggal keluar berformat salah ditolak');
    $tolak(static fn () => $service->terapkan([$santri['F']], ['tahun_angkatan' => 'dua ribu'] + $opsiLulus, $adminId), 'AL-20 tahun angkatan tidak wajar ditolak');
    $tolak(static fn () => $service->terapkan([$santri['F']], ['tingkat' => 'Aliyah'] + $opsiLulus, $adminId), 'AL-20 tingkat di luar daftar sah ditolak');
    $tolak(static fn () => $service->terapkan([], $opsiLulus, $adminId), 'AL-20 proses tanpa santri terpilih ditolak');
    $tolak(static fn () => $service->terapkan(['abc'], $opsiLulus, $adminId), 'AL-20 ID santri tidak valid ditolak');
    $tolak(
        static fn () => $service->terapkan(range(1, AlumniService::BATAS_MASSAL + 1), $opsiLulus, $adminId),
        'AL-20 operasi massal melebihi batas ditolak'
    );
    $assert(
        $angka("SELECT COUNT(*) n FROM alumni WHERE santri_id = {$santri['F']}") === 0
        && $angka("SELECT COUNT(*) n FROM santri WHERE id = {$santri['F']} AND is_active = 1") === 1,
        'AL-20 seluruh penolakan validasi tidak mengubah data apa pun'
    );

    // =============================================================== AL-13
    // Penutupan kelas dibuat gagal lewat trigger; SELURUH proses harus batal.
    $penempatan->apply(PenempatanService::AKSI_KELAS_TETAPKAN, [$santri['F']], ['kelas_id' => $kelasA, 'tanggal_mulai' => '2026-07-01'], $adminId);
    $penempatan->apply(PenempatanService::AKSI_KAMAR_TETAPKAN, [$santri['F']], ['kamar_id' => $kamarA], $adminId);
    $alumniSebelum = $angka('SELECT COUNT(*) n FROM alumni');
    if ($db->query(
        "CREATE TRIGGER al_gagal_kelas_{$kecil} BEFORE UPDATE ON plotting_kelas FOR EACH ROW
         BEGIN IF NEW.id_santri = {$santri['F']} THEN
           SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uji gagal tutup kelas';
         END IF; END"
    ) !== false) {
        $triggerKelas = true;
        $tolak(
            static fn () => $service->terapkan([$santri['F']], $opsiLulus, $adminId),
            'AL-13 kegagalan penutupan kelas menggagalkan proses'
        );
        $db->query("DROP TRIGGER al_gagal_kelas_{$kecil}");
        $triggerKelas = false;
        $assert(
            $angka('SELECT COUNT(*) n FROM alumni') === $alumniSebelum
            && $angka("SELECT COUNT(*) n FROM santri WHERE id = {$santri['F']} AND is_active = 1 AND archived_at IS NULL") === 1
            && $angka("SELECT COUNT(*) n FROM plotting_kamar WHERE id_santri = {$santri['F']} AND id_tahun = {$yearId}") === 1
            && $angka("SELECT COUNT(*) n FROM plotting_kelas WHERE id_santri = {$santri['F']} AND id_tahun = {$yearId} AND status = 'Aktif'") === 1,
            'AL-13 rollback penuh: alumni, status santri, kelas, dan kamar tidak berubah'
        );
    } else {
        echo "[lewati] AL-13 memerlukan hak CREATE TRIGGER pada database uji.\n";
    }

    // =============================================================== AL-12
    // Penyimpanan alumni dibuat gagal lewat trigger; SELURUH proses harus batal.
    if ($db->query(
        "CREATE TRIGGER al_gagal_alumni_{$kecil} BEFORE INSERT ON alumni FOR EACH ROW
         BEGIN IF NEW.santri_id = {$santri['F']} THEN
           SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'uji gagal simpan alumni';
         END IF; END"
    ) !== false) {
        $triggerAlumni = true;
        $tolak(
            static fn () => $service->terapkan([$santri['F']], $opsiLulus, $adminId),
            'AL-12 kegagalan penyimpanan alumni menggagalkan proses'
        );
        $db->query("DROP TRIGGER al_gagal_alumni_{$kecil}");
        $triggerAlumni = false;
        $assert(
            $angka('SELECT COUNT(*) n FROM alumni') === $alumniSebelum
            && $angka("SELECT COUNT(*) n FROM santri WHERE id = {$santri['F']} AND is_active = 1 AND archived_at IS NULL") === 1
            && $angka("SELECT COUNT(*) n FROM plotting_kamar WHERE id_santri = {$santri['F']} AND id_tahun = {$yearId}") === 1,
            'AL-12 rollback penuh: santri tetap aktif dan penempatannya utuh'
        );
    } else {
        echo "[lewati] AL-12 memerlukan hak CREATE TRIGGER pada database uji.\n";
    }

    // =============================================================== AL-19
    // Audit dibuat gagal dengan memindahkan tabelnya sementara.
    if ($db->query('RENAME TABLE audit_logs TO audit_logs_al_' . $kecil) !== false) {
        $tolak(
            static fn () => $service->terapkan([$santri['F']], $opsiLulus, $adminId),
            'AL-19 kegagalan pencatatan audit menggagalkan proses'
        );
        $db->query('RENAME TABLE audit_logs_al_' . $kecil . ' TO audit_logs');
        $assert(
            $angka('SELECT COUNT(*) n FROM alumni') === $alumniSebelum
            && $angka("SELECT COUNT(*) n FROM santri WHERE id = {$santri['F']} AND is_active = 1 AND archived_at IS NULL") === 1
            && $angka("SELECT COUNT(*) n FROM plotting_kelas WHERE id_santri = {$santri['F']} AND id_tahun = {$yearId} AND status = 'Aktif'") === 1,
            'AL-19 rollback penuh saat audit tidak dapat disimpan'
        );
    } else {
        echo "[lewati] AL-19 memerlukan hak RENAME TABLE pada database uji.\n";
    }

    // =============================================================== AL-14
    $tolak(static fn () => $service->arsipkan($alumniC, '', $adminId), 'AL-14 mengarsipkan tanpa alasan ditolak');
    $tolak(static fn () => $service->arsipkan($alumniC, 'ok', $adminId), 'AL-14 alasan terlalu pendek ditolak');
    $service->arsipkan($alumniC, 'Salah input, santri sebenarnya masih mondok', $adminId);
    $rowCArsip = $satu('SELECT * FROM alumni WHERE id = ' . $alumniC);
    $assert(
        $rowCArsip !== [] && ($rowCArsip['archived_at'] ?? null) !== null
        && ($rowCArsip['jenis_arsip'] ?? '') === 'arsip'
        && ($rowCArsip['alasan_arsip'] ?? '') === 'Salah input, santri sebenarnya masih mondok'
        && ($rowCArsip['foto'] ?? '') === 'default.jpg',
        'AL-14 arsip menyimpan alasan dan TIDAK menghapus baris maupun foto'
    );
    $assert(
        $angka("SELECT COUNT(*) n FROM santri WHERE id = {$santri['C']} AND is_active = 0") === 1,
        'AL-14 mengarsipkan catatan alumni tidak mengubah status santri sumber'
    );
    $auditArsip = $satu("SELECT action, before_json, after_json FROM audit_logs WHERE entity_id = {$alumniC} AND action = 'alumni.arsip' ORDER BY id DESC LIMIT 1");
    $assert(
        str_contains((string) ($auditArsip['before_json'] ?? ''), '"archived_at":null')
        && str_contains((string) ($auditArsip['after_json'] ?? ''), 'Salah input'),
        'AL-14 audit arsip memuat nilai sebelum, sesudah, dan alasannya'
    );
    $aktifSaja = $service->listPage(['state' => 'active', 'q' => 'AL' . $suffix . 'C'], 1);
    $arsipSaja = $service->listPage(['state' => 'archived', 'q' => 'AL' . $suffix . 'C'], 1);
    $assert((int) $aktifSaja['total'] === 0 && (int) $arsipSaja['total'] === 1, 'AL-14 catatan arsip hilang dari daftar aktif tetapi tetap dapat ditemukan');

    $tolak(static fn () => $service->pulihkan($alumniC, '', $adminId), 'AL-14 memulihkan tanpa alasan ditolak');
    $service->pulihkan($alumniC, 'Konfirmasi wali: santri memang berhenti', $adminId);
    $rowCPulih = $satu('SELECT * FROM alumni WHERE id = ' . $alumniC);
    $assert(
        $rowCPulih['archived_at'] === null && $rowCPulih['jenis_arsip'] === null,
        'AL-14 pemulihan mengembalikan catatan ke status aktif'
    );
    $assert(
        $angka("SELECT COUNT(*) n FROM santri WHERE id = {$santri['C']} AND is_active = 0 AND archived_at IS NOT NULL") === 1,
        'AL-14 pemulihan catatan alumni TIDAK ikut mengaktifkan kembali santri'
    );

    // =============================================================== AL-15
    $tolak(static fn () => $service->batalkan($alumniB, 'ok', $adminId), 'AL-15 pembatalan tanpa alasan memadai ditolak');
    $hasilBatal = $service->batalkan($alumniB, 'Keputusan pimpinan: mutasi dibatalkan', $adminId);
    $rowBBatal = $satu('SELECT * FROM alumni WHERE id = ' . $alumniB);
    $santriBBatal = $satu('SELECT * FROM santri WHERE id = ' . $santri['B']);
    $assert(
        ($rowBBatal['archived_at'] ?? null) !== null && ($rowBBatal['jenis_arsip'] ?? '') === 'pembatalan',
        'AL-15 catatan alumni diarsipkan dengan jenis pembatalan'
    );
    $assert(
        (int) ($santriBBatal['is_active'] ?? 0) === 1 && $santriBBatal['archived_at'] === null,
        'AL-15 santri sumber diaktifkan kembali'
    );
    $assert(
        $angka("SELECT COUNT(*) n FROM plotting_kelas WHERE id_santri = {$santri['B']} AND id_tahun = {$yearId} AND status = 'Aktif'") === 0
        && $angka("SELECT COUNT(*) n FROM plotting_kamar WHERE id_santri = {$santri['B']} AND id_tahun = {$yearId}") === 0,
        'AL-15 pembatalan TIDAK membuat penempatan kelas atau kamar baru secara otomatis'
    );
    $assert(
        $angka("SELECT COUNT(*) n FROM audit_logs WHERE action = 'alumni.batalkan' AND entity_id = {$alumniB}") === 1
        && $angka("SELECT COUNT(*) n FROM audit_logs WHERE action = 'alumni.batalkan.santri' AND entity_id = {$santri['B']}") === 1,
        'AL-15 pembatalan mencatat audit untuk catatan alumni DAN untuk santri'
    );
    $assert((int) $hasilBatal['santri_id'] === $santri['B'], 'AL-15 hasil pembatalan menyebut santri yang diaktifkan kembali');
    $tolak(static fn () => $service->batalkan($alumniB, 'Dicoba dua kali', $adminId), 'AL-15 membatalkan catatan yang sudah diarsipkan ditolak');

    // Setelah pembatalan, santri B boleh diproses ulang: kunci unik NIS tidak
    // terkunci selamanya oleh baris arsip.
    $hasilUlang = $service->terapkan([$santri['B']], [
        'status_keluar' => 'Pindah', 'tgl_keluar' => '2026-06-01',
        'tahun_angkatan' => '2026', 'tingkat' => 'Ibtida', 'catatan' => 'Proses ulang setelah pembatalan',
    ], $adminId);
    $dibuat['alumni'][] = (int) $hasilUlang['alumni_id'];
    $assert(
        $angka("SELECT COUNT(*) n FROM alumni WHERE santri_id = {$santri['B']}") === 2
        && $angka("SELECT COUNT(*) n FROM alumni WHERE santri_id = {$santri['B']} AND archived_at IS NULL") === 1,
        'AL-15 santri yang kelulusannya dibatalkan dapat diproses ulang tanpa menghapus catatan lama'
    );

    // =============================================================== AL-17
    // Catatan alumni WARISAN: tanpa santri_id, tanpa pelaku, tanpa waktu.
    $alumniWarisan = $exec(
        "INSERT INTO alumni (nis, nama_santri, jenis_kelamin, tempat_lahir, tgl_lahir, alamat, desa, kecamatan, kab_kota,
                             provinsi, nama_ayah, no_hp_ayah, nama_ibu, no_hp_ibu, asal_sekolah, unit_terakhir,
                             tahun_angkatan, tingkat, status_keluar, tgl_keluar, foto)
         VALUES (?, ?, 'P', 'Tasik', '2005-02-02', 'Jl Lama', 'Desa Lama', 'Kec Lama', 'Kab Lama', 'Jabar',
                 'Ayah Lama', '081100000000', 'Ibu Lama', NULL, 'SD Lama', 'MTs Lama', '2015', 'Ibtida', 'Lulus', '2015-06-30', 'lama.jpg')",
        ['LAMA' . $suffix, 'Alumni Warisan ' . $suffix]
    );
    $dibuat['alumni'][] = $alumniWarisan;
    $warisan = $service->find($alumniWarisan);
    $assert(
        $warisan !== null && $warisan['santri_id'] === null && $warisan['created_at'] === null
        && ($warisan['foto'] ?? '') === 'lama.jpg' && ($warisan['nama_ayah'] ?? '') === 'Ayah Lama',
        'AL-17 catatan alumni warisan tetap terbaca lengkap dengan foto dan snapshot identitasnya'
    );
    $filterLama = $service->listPage(['q' => 'LAMA' . $suffix, 'tahun' => '2015', 'tingkat' => 'Ibtida', 'status' => 'Lulus'], 1);
    $assert((int) $filterLama['total'] === 1, 'AL-17 filter tahun, tingkat, dan status lama tetap berfungsi');
    $filterTanpaSantri = $service->listPage(['tautan' => 'tanpa_santri'], 1);
    $assert((int) $filterTanpaSantri['total'] >= 1, 'AL-17 data warisan dapat disaring sebagai "belum terhubung"');
    $ringkasanAkhir = $service->summary();
    $assert($ringkasanAkhir['tanpa_santri'] >= 1, 'AL-17 ringkasan melaporkan jumlah data warisan yang perlu diperiksa admin');

    // Memasangkan catatan warisan ke santri sumber HANYA atas perintah admin.
    $service->hubungkanSantri($alumniWarisan, $santri['G'], $adminId);
    $assert(
        (int) ($satu('SELECT santri_id FROM alumni WHERE id = ' . $alumniWarisan)['santri_id'] ?? 0) === $santri['G'],
        'AL-17 catatan warisan dapat dihubungkan ke santri sumber atas konfirmasi admin'
    );
    $assert(
        $angka("SELECT COUNT(*) n FROM audit_logs WHERE action = 'alumni.hubungkan' AND entity_id = {$alumniWarisan}") === 1,
        'AL-17 pemasangan referensi santri tercatat pada audit'
    );
    $assert(
        $angka("SELECT COUNT(*) n FROM santri WHERE id = {$santri['G']} AND is_active = 1") === 1,
        'AL-17 menghubungkan catatan warisan TIDAK mengubah status santri'
    );
    $tolak(
        static fn () => $service->hubungkanSantri($alumniWarisan, $santri['F'], $adminId),
        'AL-17 catatan yang sudah terhubung tidak dapat dipasangkan ulang'
    );

    // =============================================================== AL-16
    $jahat = "' OR 1=1 -- <script>alert('xss')</script>";
    $hasilJahat = $service->listPage(['q' => $jahat], 1);
    $assert((int) $hasilJahat['total'] === 0, 'AL-16 nilai filter berbahaya diperlakukan sebagai teks pencarian biasa, bukan SQL');
    $filterJahat = $service->normalizeFilters(['status' => 'Lulus OR 1=1', 'tingkat' => '<script>', 'state' => 'DROP', 'tautan' => 'x']);
    $assert(
        $filterJahat['status'] === '' && $filterJahat['tingkat'] === ''
        && $filterJahat['state'] === 'active' && $filterJahat['tautan'] === '',
        'AL-16 nilai pilihan di luar daftar sah dibuang, bukan diteruskan ke SQL'
    );
    $alumniXss = $exec(
        "INSERT INTO alumni (nis, nama_santri, jenis_kelamin, tempat_lahir, tgl_lahir, alamat, desa, kecamatan, kab_kota,
                             provinsi, nama_ayah, nama_ibu, asal_sekolah, unit_terakhir, tahun_angkatan, tingkat,
                             status_keluar, tgl_keluar, foto)
         VALUES (?, ?, 'L', 'X', '2010-01-01', 'X', 'X', 'X', 'X', 'X', 'X', 'X', 'X', 'X', '2026', 'Ibtida', 'Lulus', '2026-01-01', 'default.jpg')",
        ['XSS' . $suffix, "<script>alert('xss')</script>"]
    );
    $dibuat['alumni'][] = $alumniXss;
    $rowXss = $service->find($alumniXss);
    $assert(
        ($rowXss['nama_santri'] ?? '') === "<script>alert('xss')</script>",
        'AL-16 nilai berbahaya tersimpan apa adanya di basis data (escaping adalah tugas lapisan tampilan)'
    );
    $assert(
        ah_e($rowXss['nama_santri'] ?? '') === '&lt;script&gt;alert(&#039;xss&#039;)&lt;/script&gt;',
        'AL-16 helper escaping halaman mengubahnya menjadi teks yang aman ditampilkan'
    );

    // =============================================================== AL-18
    // Regresi modul tetangga: penempatan kelas/kamar tetap dapat dipakai untuk
    // santri yang masih aktif, dan daftar santri aktif tetap benar.
    $penempatan->apply(PenempatanService::AKSI_KELAS_TETAPKAN, [$santri['G']], ['kelas_id' => $kelasB, 'tanggal_mulai' => '2026-07-05'], $adminId);
    $penempatan->apply(PenempatanService::AKSI_KAMAR_TETAPKAN, [$santri['G']], ['kamar_id' => $kamarA], $adminId);
    $assert(
        $angka("SELECT COUNT(*) n FROM plotting_kelas WHERE id_santri = {$santri['G']} AND id_tahun = {$yearId} AND status = 'Aktif'") === 1
        && $angka("SELECT COUNT(*) n FROM plotting_kamar WHERE id_santri = {$santri['G']} AND id_tahun = {$yearId}") === 1,
        'AL-18 regresi penempatan kelas dan kamar tetap berfungsi setelah migrasi 011'
    );
    $daftarPenempatan = $penempatan->listPage(['q' => 'AL' . $suffix . 'G'], $yearId, 1);
    $assert((int) $daftarPenempatan['total'] === 1, 'AL-18 regresi halaman penempatan tetap menemukan santri aktif');
    $daftarSantriAktif = master_data_service()->santriList(['q' => 'Santri Alumni', 'state' => 'active'], 1);
    $assert(
        (int) $daftarSantriAktif['total'] === 2,
        'AL-18 regresi daftar santri aktif hanya memuat dua santri yang belum diproses (F dan G)'
    );
    $assert(
        $angka("SELECT COUNT(*) n FROM users WHERE id = {$akunWaliId} AND is_active = 1") === 1
        && $angka("SELECT COUNT(*) n FROM wali WHERE id = {$waliId}") === 1
        && $angka("SELECT COUNT(*) n FROM santri_wali WHERE wali_id = {$waliId}") === 1,
        'AL-18 regresi modul wali dan akun tetap utuh sepanjang seluruh pengujian'
    );
} catch (Throwable $exception) {
    echo '[gagal] Pengujian berhenti: ' . $exception->getMessage() . PHP_EOL;
    $failures[] = 'pengujian berhenti: ' . $exception->getMessage();
} finally {
    // ------------------------------------------------------------ bersih-bersih
    try {
        if ($triggerKelas) {
            $db->query("DROP TRIGGER IF EXISTS al_gagal_kelas_{$kecil}");
        }
        if ($triggerAlumni) {
            $db->query("DROP TRIGGER IF EXISTS al_gagal_alumni_{$kecil}");
        }
        if ($db->query("SHOW TABLES LIKE 'audit_logs_al_" . $kecil . "'")?->num_rows) {
            $db->query('RENAME TABLE audit_logs_al_' . $kecil . ' TO audit_logs');
        }
        if ($tahunLamaDinonaktifkan && isset($yearId)) {
            $db->query("UPDATE tahun_ajaran SET status = 'Aktif' WHERE id = " . (int) $yearId);
        }
        foreach ($dibuat['santri'] as $id) {
            $db->query('DELETE FROM alumni WHERE santri_id = ' . (int) $id);
        }
        foreach ($dibuat['alumni'] as $id) {
            $db->query('DELETE FROM alumni WHERE id = ' . (int) $id);
        }
        foreach ($dibuat['santri'] as $id) {
            $db->query('DELETE FROM plotting_kamar WHERE id_santri = ' . (int) $id);
            $db->query('DELETE FROM plotting_kelas WHERE id_santri = ' . (int) $id);
            $db->query('DELETE FROM santri_wali WHERE santri_id = ' . (int) $id);
        }
        foreach ($dibuat['users'] as $id) {
            $db->query('DELETE FROM user_roles WHERE user_id = ' . (int) $id);
            $db->query('DELETE FROM audit_logs WHERE actor_user_id = ' . (int) $id);
            $db->query('UPDATE alumni SET created_by = NULL, updated_by = NULL WHERE created_by = ' . (int) $id . ' OR updated_by = ' . (int) $id);
            $db->query('DELETE FROM users WHERE id = ' . (int) $id);
        }
        foreach ($dibuat['wali'] as $id) {
            $db->query('DELETE FROM santri_wali WHERE wali_id = ' . (int) $id);
            $db->query('DELETE FROM wali WHERE id = ' . (int) $id);
        }
        foreach ($dibuat['santri'] as $id) {
            $db->query('DELETE FROM santri WHERE id = ' . (int) $id);
        }
        foreach ($dibuat['kelas'] as $id) {
            $db->query('DELETE FROM plotting_kelas WHERE id_kelas = ' . (int) $id);
            $db->query('DELETE FROM kelas WHERE id = ' . (int) $id);
        }
        foreach ($dibuat['kamar'] as $id) {
            $db->query('DELETE FROM plotting_kamar WHERE id_kamar = ' . (int) $id);
            $db->query('DELETE FROM kamar WHERE id = ' . (int) $id);
        }
        foreach ($dibuat['tahun'] as $id) {
            $db->query('DELETE FROM plotting_kamar WHERE id_tahun = ' . (int) $id);
            $db->query('DELETE FROM plotting_kelas WHERE id_tahun = ' . (int) $id);
            $db->query('DELETE FROM tahun_ajaran WHERE id = ' . (int) $id);
        }
    } catch (Throwable $exception) {
        echo '[gagal] Bersih-bersih fixture: ' . $exception->getMessage() . PHP_EOL;
        $failures[] = 'bersih-bersih fixture';
    }
}

echo PHP_EOL;
if ($failures === []) {
    echo 'SELURUH PENGUJIAN INTEGRASI ALUMNI LULUS.' . PHP_EOL;
    exit(0);
}
echo 'GAGAL (' . count($failures) . '):' . PHP_EOL . ' - ' . implode(PHP_EOL . ' - ', $failures) . PHP_EOL;
exit(1);
