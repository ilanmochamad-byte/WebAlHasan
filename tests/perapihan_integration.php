<?php

declare(strict_types=1);

/**
 * Pengujian integrasi paket "Koreksi dan Modernisasi UI/UX V1–V2".
 *
 * Menjalankan kriteria penerimaan ketujuh koreksi (keputusan pengguna
 * 30 Agustus 2026) pada basis data sungguhan, tanpa permintaan jaringan keluar:
 *
 *   KA-*  Akun & hak akses      : role eksplisit, relasi master, admin terakhir;
 *   KW-*  Santri & wali         : saudara kandung, nama kembar, penggabungan;
 *   KG-*  Data guru             : kolom tugas lama tidak ditimpa;
 *   KP-*  Pengajian             : cakupan guru pada jadwal dan pertemuan;
 *   KL-*  Laporan kehadiran     : 30 / 1 / 31 pada ringkasan, detail, ekspor.
 *
 * Seluruh fixture dibuat dengan akhiran acak dan dihapus kembali pada blok
 * `finally`, sehingga tidak mengganggu berkas uji lain.
 *
 * Jalankan hanya pada database berakhiran `_test`:
 *   PERAPIHAN_RUN_INTEGRATION=1 php tests/perapihan_integration.php
 */

$root = dirname(__DIR__);
if (getenv('PERAPIHAN_RUN_INTEGRATION') !== '1') {
    fwrite(STDOUT, "[lewati] Set PERAPIHAN_RUN_INTEGRATION=1 dan arahkan DB_NAME ke database khusus *_test.\n");
    exit(77);
}

require_once $root . '/app/bootstrap.php';

use App\Account\AccountService;
use App\MasterData\MasterDataException;
use App\Report\ReportFilter;
use App\Schedule\ScheduleException;

if (!str_ends_with((string) app_config('database.database'), '_test')) {
    fwrite(STDERR, "Ditolak: pengujian ini hanya boleh berjalan pada database berakhiran _test.\n");
    exit(2);
}

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? '[lulus] ' : '[gagal] ') . $message . PHP_EOL;
    if (!$condition) {
        $failures[] = $message;
    }
};
$tolak = static function (callable $aksi, string $penggalan, string $pesan) use ($assert): void {
    try {
        $aksi();
        $assert(false, $pesan . ' (ternyata TIDAK ditolak)');
    } catch (Throwable $exception) {
        $assert(
            str_contains($exception->getMessage(), $penggalan),
            $pesan . ' [' . $exception->getMessage() . ']'
        );
    }
};

$db = app_db();
$suffix = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
$akun = account_service();
$master = master_data_service();
$jadwal = schedule_service();

$adminRow = $db->query(
    "SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
      WHERE r.slug = 'admin' AND u.is_active = 1 ORDER BY u.id LIMIT 1"
)?->fetch_assoc();
if (!$adminRow) {
    fwrite(STDERR, "Fixture admin tidak tersedia. Jalankan bin/v2_phase3_sandbox_seed.php terlebih dahulu.\n");
    exit(2);
}
$adminId = (int) $adminRow['id'];
$_SESSION = ['user_id' => $adminId];

$dibuat = ['users' => [], 'santri' => [], 'wali' => [], 'guru' => [], 'kelas' => [], 'jadwal' => [], 'pertemuan' => []];
$roles = static function (int $userId) use ($db): array {
    $hasil = [];
    $rs = $db->query(
        'SELECT r.slug FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ' . $userId . ' ORDER BY r.slug'
    );
    while ($rs && $row = $rs->fetch_assoc()) {
        $hasil[] = $row['slug'];
    }

    return $hasil;
};

try {
    // =====================================================================
    echo PHP_EOL . '=== KA. Akun dan hak akses ===' . PHP_EOL;
    // =====================================================================

    $guruId = $master->saveGuru(['nip' => 'PR' . $suffix, 'nama_guru' => 'Guru Perapihan ' . $suffix, 'no_hp' => '']);
    $dibuat['guru'][] = $guruId;

    $buatAkun = $akun->createTeacher([
        'guru_id' => $guruId,
        'name' => 'Akun Perapihan ' . $suffix,
        'username' => 'pr.' . strtolower($suffix),
        'email' => '',
        'phone' => '',
    ], $adminId);
    $userId = (int) $buatAkun['id'];
    $dibuat['users'][] = $userId;

    $assert($roles($userId) === ['guru'], 'KA-1 Akun guru baru memperoleh tepat satu role guru');

    // Role yang menuntut relasi master ditolak server bila relasinya tidak ada.
    $tolak(
        static fn () => $akun->grantRole($userId, 'orang_tua', $adminId),
        'terhubung dengan satu data wali',
        'KA-2 Role Orang Tua ditolak untuk akun tanpa relasi wali'
    );
    $tolak(
        static fn () => $akun->grantRole($userId, 'pengurus', $adminId),
        'terhubung dengan satu data pengurus',
        'KA-3 Role Pengurus ditolak untuk akun tanpa relasi pengurus'
    );

    // Pemberian admin adalah tindakan khusus.
    $tolak(
        static fn () => $akun->grantRole($userId, 'admin', $adminId, []),
        AccountService::KONFIRMASI_ADMIN,
        'KA-4 Role Admin ditolak tanpa konfirmasi yang diketik ulang'
    );

    $akun->grantRole($userId, 'admin', $adminId, ['konfirmasi_admin' => AccountService::KONFIRMASI_ADMIN]);
    $assert($roles($userId) === ['admin', 'guru'], 'KA-5 Penambahan role admin MEMPERTAHANKAN role guru yang sudah ada');

    $akun->revokeRole($userId, 'admin', $adminId);
    $assert($roles($userId) === ['guru'], 'KA-6 Pencabutan role admin tidak menyentuh role guru');

    // Admin terakhir dilindungi.
    $jumlahAdmin = (int) ($db->query(
        "SELECT COUNT(*) AS c FROM users u JOIN user_roles ur ON ur.user_id = u.id
           JOIN roles r ON r.id = ur.role_id AND r.slug = 'admin' WHERE u.is_active = 1"
    )?->fetch_assoc()['c'] ?? 0);
    if ($jumlahAdmin === 1) {
        $tolak(
            static fn () => $akun->revokeRole($adminId, 'admin', 999999),
            'admin aktif terakhir',
            'KA-7 Admin aktif terakhir tidak dapat dicabut'
        );
        $tolak(
            static fn () => $akun->setActive($adminId, false, 999999),
            'admin aktif terakhir',
            'KA-8 Admin aktif terakhir tidak dapat dinonaktifkan'
        );
    } else {
        $assert(true, 'KA-7/8 Dilewati: lingkungan memiliki lebih dari satu admin aktif (' . $jumlahAdmin . ')');
    }

    $tolak(
        static fn () => $akun->setActive($adminId, false, $adminId),
        'menonaktifkan akun sendiri',
        'KA-9 Admin tidak dapat menonaktifkan akunnya sendiri'
    );

    // Audit mencatat seluruh perubahan hak akses.
    $auditGrant = (int) ($db->query(
        "SELECT COUNT(*) AS c FROM audit_logs WHERE action = 'account_role_granted' AND entity_id = " . $userId
    )?->fetch_assoc()['c'] ?? 0);
    $auditRevoke = (int) ($db->query(
        "SELECT COUNT(*) AS c FROM audit_logs WHERE action = 'account_role_revoked' AND entity_id = " . $userId
    )?->fetch_assoc()['c'] ?? 0);
    $assert($auditGrant >= 1 && $auditRevoke >= 1, 'KA-10 Penambahan dan pencabutan hak akses tercatat pada audit');

    // Hak yang dicabut tidak dapat dipertahankan sesi lama: kemampuan dihitung
    // ulang dari basis data pada setiap pemeriksaan server.
    $muat = static fn (int $id): array => auth_repository()->findActiveById($id) ?? [];
    $akun->grantRole($userId, 'admin', $adminId, ['konfirmasi_admin' => AccountService::KONFIRMASI_ADMIN]);
    $assert(in_array('admin', $muat($userId)['roles'], true), 'KA-11a Role baru terbaca pada pemeriksaan server berikutnya');
    $akun->revokeRole($userId, 'admin', $adminId);
    $assert(!in_array('admin', $muat($userId)['roles'], true), 'KA-11b Role yang dicabut hilang pada pemeriksaan server berikutnya');

    // =====================================================================
    echo PHP_EOL . '=== KW. Santri dan wali ===' . PHP_EOL;
    // =====================================================================

    $kakak = $master->saveSantri([
        'nis' => 'W1' . $suffix, 'nama_santri' => 'Kakak ' . $suffix,
        'jenis_kelamin' => 'L', 'tgl_lahir' => '2010-01-01',
        'wali' => ['Ayah' => ['mode' => 'baru', 'nama' => 'Ayah Bersama ' . $suffix, 'no_hp' => '081200000001', 'alamat' => 'Jl Uji']],
    ]);
    $dibuat['santri'][] = $kakak;
    $waliBersama = (int) ($db->query('SELECT wali_id FROM santri_wali WHERE santri_id = ' . $kakak . ' AND archived_at IS NULL LIMIT 1')?->fetch_assoc()['wali_id'] ?? 0);
    $dibuat['wali'][] = $waliBersama;
    $assert($waliBersama > 0, 'KW-1 Wali baru dibuat dan terhubung dari formulir santri');

    $adik = $master->saveSantri([
        'nis' => 'W2' . $suffix, 'nama_santri' => 'Adik ' . $suffix,
        'jenis_kelamin' => 'P', 'tgl_lahir' => '2013-01-01',
        'wali' => ['Ayah' => ['mode' => 'pilih', 'wali_id' => $waliBersama]],
    ]);
    $dibuat['santri'][] = $adik;
    $jumlahRelasi = (int) ($db->query('SELECT COUNT(*) AS c FROM santri_wali WHERE wali_id = ' . $waliBersama . ' AND archived_at IS NULL')?->fetch_assoc()['c'] ?? 0);
    $assert($jumlahRelasi === 2, 'KW-2 Dua saudara kandung memakai SATU identitas wali yang dipilih admin');

    $cermin = $db->query('SELECT nama_ayah, no_hp_ayah FROM santri WHERE id = ' . $kakak)?->fetch_assoc();
    $assert(
        trim((string) $cermin['nama_ayah']) === 'Ayah Bersama ' . $suffix && $cermin['no_hp_ayah'] === '081200000001',
        'KW-3 Kolom lama menjadi cermin identitas wali yang dikonfirmasi'
    );

    // Dua orang bernama sama (bahkan nomor HP sama) tetap dua identitas.
    $ketiga = $master->saveSantri([
        'nis' => 'W3' . $suffix, 'nama_santri' => 'Anak Lain ' . $suffix,
        'jenis_kelamin' => 'L', 'tgl_lahir' => '2012-01-01',
        'wali' => ['Ayah' => ['mode' => 'baru', 'nama' => 'Ayah Bersama ' . $suffix, 'no_hp' => '081200000001', 'alamat' => 'Jl Lain']],
    ]);
    $dibuat['santri'][] = $ketiga;
    $waliKembar = (int) ($db->query('SELECT wali_id FROM santri_wali WHERE santri_id = ' . $ketiga . ' AND archived_at IS NULL LIMIT 1')?->fetch_assoc()['wali_id'] ?? 0);
    $dibuat['wali'][] = $waliKembar;
    $assert(
        $waliKembar > 0 && $waliKembar !== $waliBersama,
        'KW-4 Nama dan nomor HP yang sama TIDAK digabungkan otomatis menjadi satu identitas'
    );

    $akunWali = (int) ($db->query('SELECT COUNT(*) AS c FROM users WHERE wali_id IN (' . $waliBersama . ', ' . $waliKembar . ')')?->fetch_assoc()['c'] ?? 0);
    $assert($akunWali === 0, 'KW-5 Membuat atau memilih wali tidak membuat akun login');

    // Nilai kolom lama yang bertentangan tidak ditimpa tanpa konfirmasi.
    $waliBerbeda = $master->saveWali(['nama' => 'Ayah Berbeda ' . $suffix, 'no_hp' => '081200000002', 'alamat' => '']);
    $dibuat['wali'][] = $waliBerbeda;
    $tolak(
        static fn () => $master->saveSantri([
            'nis' => 'W1' . $suffix, 'nama_santri' => 'Kakak ' . $suffix,
            'jenis_kelamin' => 'L', 'tgl_lahir' => '2010-01-01',
            'wali' => ['Ayah' => ['mode' => 'pilih', 'wali_id' => $waliBerbeda]],
        ], $kakak),
        'konfirmasi penggantian nilai lama',
        'KW-6 Nilai kolom lama yang bertentangan tidak ditimpa tanpa konfirmasi'
    );
    $masihLama = $db->query('SELECT nama_ayah FROM santri WHERE id = ' . $kakak)?->fetch_assoc();
    $masihRelasi = (int) ($db->query('SELECT wali_id FROM santri_wali WHERE santri_id = ' . $kakak . ' AND archived_at IS NULL LIMIT 1')?->fetch_assoc()['wali_id'] ?? 0);
    $assert(
        trim((string) $masihLama['nama_ayah']) === 'Ayah Bersama ' . $suffix && $masihRelasi === $waliBersama,
        'KW-7 Penolakan membatalkan seluruh transaksi (kolom lama dan relasi tidak berubah)'
    );

    $master->saveSantri([
        'nis' => 'W1' . $suffix, 'nama_santri' => 'Kakak ' . $suffix,
        'jenis_kelamin' => 'L', 'tgl_lahir' => '2010-01-01',
        'wali' => ['Ayah' => ['mode' => 'pilih', 'wali_id' => $waliBerbeda]],
        'konfirmasi_timpa' => ['Ayah' => '1'],
    ], $kakak);
    $auditCermin = (int) ($db->query("SELECT COUNT(*) AS c FROM audit_logs WHERE action = 'master.legacy.mirror' AND entity_id = " . $kakak)?->fetch_assoc()['c'] ?? 0);
    $assert($auditCermin >= 1, 'KW-8 Penggantian nilai lama yang dikonfirmasi tercatat pada audit (sebelum dan sesudah)');

    // Penggabungan identitas: diblokir bila menyangkut akun login.
    $tolak(
        static fn () => $master->mergeWali($waliKembar, $waliBersama, $adminId, false),
        'konfirmasi daftar santri terdampak',
        'KW-9 Penggabungan ditolak tanpa konfirmasi santri terdampak'
    );

    $db->query('UPDATE users SET wali_id = ' . $waliKembar . ' WHERE id = ' . $userId);
    $tolak(
        static fn () => $master->mergeWali($waliKembar, $waliBersama, $adminId, true),
        'memiliki akun login',
        'KW-10 Penggabungan diblokir bila identitas menyangkut akun login'
    );
    $db->query('UPDATE users SET wali_id = NULL WHERE id = ' . $userId);

    $hasilGabung = $master->mergeWali($waliKembar, $waliBersama, $adminId, true);
    $sumber = $db->query('SELECT id, is_active, archived_at, merged_into_wali_id FROM wali WHERE id = ' . $waliKembar)?->fetch_assoc();
    $assert(
        $sumber !== null && (int) $sumber['id'] === $waliKembar && (int) $sumber['merged_into_wali_id'] === $waliBersama,
        'KW-11 Wali sumber TIDAK dihapus: ID lama dipertahankan dan ditandai digabungkan'
    );
    $assert(
        $hasilGabung['dipindahkan'] + $hasilGabung['diarsipkan'] >= 1 && $hasilGabung['santri'] !== [],
        'KW-12 Relasi dipindahkan ke identitas tujuan dan santri terdampak tercatat'
    );
    $auditMerge = (int) ($db->query("SELECT COUNT(*) AS c FROM audit_logs WHERE action = 'master.wali.merge' AND entity_id = " . $waliKembar)?->fetch_assoc()['c'] ?? 0);
    $assert($auditMerge === 1, 'KW-13 Penggabungan tercatat pada audit');

    // Identitas bersama: perubahan menuntut konfirmasi dampak.
    $tolak(
        static fn () => $master->saveWali(['nama' => 'Ayah Diubah ' . $suffix, 'no_hp' => '081200000009', 'alamat' => ''], $waliBersama),
        'centang konfirmasi',
        'KW-14 Mengubah identitas wali bersama menuntut konfirmasi setelah melihat santri terdampak'
    );
    $master->saveWali([
        'nama' => 'Ayah Diubah ' . $suffix, 'no_hp' => '081200000009', 'alamat' => '', 'konfirmasi_dampak' => '1',
    ], $waliBersama);
    $assert(
        (string) ($db->query('SELECT nama FROM wali WHERE id = ' . $waliBersama)?->fetch_assoc()['nama'] ?? '') === 'Ayah Diubah ' . $suffix,
        'KW-15 Perubahan identitas bersama tersimpan setelah dikonfirmasi'
    );

    $laporanRekonsiliasi = $master->reconciliationReport(50);
    $assert(
        array_key_exists('duplikat', $laporanRekonsiliasi)
        && array_key_exists('tanpa_relasi', $laporanRekonsiliasi)
        && array_key_exists('relasi_belum_lengkap', $laporanRekonsiliasi)
        && array_key_exists('konflik_kolom_lama', $laporanRekonsiliasi),
        'KW-16 Laporan rekonsiliasi menyediakan kandidat duplikasi, konflik, dan relasi belum lengkap'
    );

    // Impor lama tetap dapat menyimpan santri tanpa membuat identitas wali.
    $imporId = $master->saveSantri([
        'nis' => 'W4' . $suffix, 'nama_santri' => 'Santri Impor ' . $suffix,
        'jenis_kelamin' => 'L', 'tgl_lahir' => '2011-05-05',
        'nama_ayah' => 'Ayah Impor ' . $suffix, 'no_hp_ayah' => '081200000003',
        'nama_ibu' => 'Ibu Impor ' . $suffix, 'no_hp_ibu' => '',
    ]);
    $dibuat['santri'][] = $imporId;
    $relasiImpor = (int) ($db->query('SELECT COUNT(*) AS c FROM santri_wali WHERE santri_id = ' . $imporId)?->fetch_assoc()['c'] ?? 0);
    $assert($relasiImpor === 0, 'KW-17 Impor lama menyimpan kolom lama tanpa membuat identitas wali ganda');
    $kolomImpor = $db->query('SELECT nama_ayah, nama_ibu FROM santri WHERE id = ' . $imporId)?->fetch_assoc();
    $assert(
        trim((string) $kolomImpor['nama_ayah']) === 'Ayah Impor ' . $suffix,
        'KW-18 Data lama dari impor tetap terbaca (kompatibilitas ekspor dan laporan)'
    );

    // =====================================================================
    echo PHP_EOL . '=== KG. Data guru ===' . PHP_EOL;
    // =====================================================================

    $db->query("UPDATE guru SET status = 'Keduanya' WHERE id = " . $guruId);
    $master->saveGuru(['nip' => 'PR' . $suffix, 'nama_guru' => 'Guru Perapihan Diubah ' . $suffix, 'no_hp' => '081200000004'], $guruId);
    $setelah = $db->query('SELECT nama_guru, status FROM guru WHERE id = ' . $guruId)?->fetch_assoc();
    $assert(
        $setelah['nama_guru'] === 'Guru Perapihan Diubah ' . $suffix && $setelah['status'] === 'Keduanya',
        'KG-1 Menyimpan formulir guru TIDAK mengubah nilai tugas lama menjadi Guru'
    );

    $ringkasanPenugasan = $master->guruAssignments($guruId);
    $assert(
        $ringkasanPenugasan['jadwal_aktif'] === 0 && $ringkasanPenugasan['murobi_aktif'] === 0,
        'KG-2 Guru tanpa jadwal dan tanpa penugasan murobi dilaporkan apa adanya'
    );

    // =====================================================================
    echo PHP_EOL . '=== KP + KL. Pengajian dan laporan kehadiran ===' . PHP_EOL;
    // =====================================================================

    $tahunId = (int) ($db->query("SELECT id FROM tahun_ajaran WHERE status = 'Aktif' AND archived_at IS NULL LIMIT 1")?->fetch_assoc()['id'] ?? 0);
    if ($tahunId < 1) {
        throw new RuntimeException('Tahun ajaran aktif tidak tersedia pada database uji.');
    }
    $kelasId = $master->saveClass(['nama_kelas' => 'Kelas ' . $suffix, 'jenjang' => 'Uji']);
    $dibuat['kelas'][] = $kelasId;

    $santriKelas = [];
    for ($i = 1; $i <= 30; $i++) {
        $sid = $master->saveSantri([
            'nis' => sprintf('L%s%02d', $suffix, $i),
            'nama_santri' => sprintf('Santri Laporan %s %02d', $suffix, $i),
            'jenis_kelamin' => 'L', 'tgl_lahir' => '2011-01-01',
        ]);
        $santriKelas[] = $sid;
        $dibuat['santri'][] = $sid;
        $master->assignActiveClass(['santri_id' => $sid, 'kelas_id' => $kelasId, 'tanggal_mulai' => date('Y-m-d')], $adminId);
    }

    $namaHari = ['Sunday' => 'Minggu', 'Monday' => 'Senin', 'Tuesday' => 'Selasa', 'Wednesday' => 'Rabu',
                 'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu'];
    $tanggal = date('Y-m-d');
    $jadwalBaru = $jadwal->save([
        'id_tahun' => $tahunId, 'hari' => $namaHari[date('l', strtotime($tanggal))],
        'waktu_sholat' => "Ba'da Shubuh", 'waktu_mulai' => '05:00', 'waktu_selesai' => '06:00',
        'id_kelas' => $kelasId, 'id_guru' => $guruId, 'tempat' => 'Aula ' . $suffix,
        'fan_ilmu' => 'Fikih ' . $suffix, 'nama_kitab' => 'Kitab ' . $suffix,
    ], $adminId);
    $jadwalId = (int) $jadwalBaru['id'];
    $dibuat['jadwal'][] = $jadwalId;

    $adminUser = ['id' => $adminId, 'name' => 'Admin Uji', 'username' => 'admin', 'roles' => ['admin'], 'guru_id' => null];
    $pertemuanId = $jadwal->open($jadwalId, $tanggal, 'Fixture ' . $suffix, $adminUser);
    $dibuat['pertemuan'][] = $pertemuanId;

    $peserta = (int) ($db->query('SELECT COUNT(*) AS c FROM pertemuan_peserta WHERE pertemuan_id = ' . $pertemuanId)?->fetch_assoc()['c'] ?? 0);
    $assert($peserta === 30, 'KP-1 Snapshot peserta dibekukan saat pertemuan dibuka (30 santri)');

    // Cakupan guru: guru lain tidak boleh membuka pertemuan ini.
    $guruLainId = $master->saveGuru(['nip' => 'PX' . $suffix, 'nama_guru' => 'Guru Lain ' . $suffix, 'no_hp' => '']);
    $dibuat['guru'][] = $guruLainId;
    $guruLainUser = ['id' => $adminId, 'name' => 'Guru Lain', 'username' => 'guru', 'roles' => ['guru'], 'guru_id' => $guruLainId];
    $tolak(
        static fn () => $jadwal->meeting($pertemuanId, $guruLainUser),
        'jadwal miliknya',
        'KP-2 Guru lain ditolak membuka pertemuan milik guru pengampu'
    );

    $db->query("INSERT INTO absensi_guru (pertemuan_id, guru_id, status, catatan, dicatat_oleh, dicatat_pada, updated_at)
                VALUES ({$pertemuanId}, {$guruId}, 'Hadir', NULL, {$adminId}, NOW(), NOW())");
    foreach ($santriKelas as $sid) {
        $db->query("INSERT INTO absensi_santri (pertemuan_id, santri_id, status, catatan, dicatat_oleh, dicatat_pada, updated_at)
                    VALUES ({$pertemuanId}, {$sid}, 'Hadir', NULL, {$adminId}, NOW(), NOW())");
    }

    $filterDasar = ['date_from' => $tanggal, 'date_to' => $tanggal, 'schedule_id' => $jadwalId, 'per_page' => 100];
    $harapan = [
        ReportFilter::SCOPE_SANTRI => 30,
        ReportFilter::SCOPE_GURU => 1,
        ReportFilter::SCOPE_GABUNGAN => 31,
    ];
    foreach ($harapan as $mode => $jumlah) {
        $laporan = report_service()->report($filterDasar + ['subject_scope' => $mode], $adminUser);
        $ekspor = report_service()->exportRows($filterDasar + ['subject_scope' => $mode], $adminUser);
        $assert(
            $laporan['summary']['detail_count'] === $jumlah,
            'KL-1 Ringkasan mode ' . $mode . ' menghasilkan ' . $jumlah . ' catatan [' . $laporan['summary']['detail_count'] . ']'
        );
        $assert(
            count($laporan['items']) === $jumlah,
            'KL-2 Detail mode ' . $mode . ' menghasilkan ' . $jumlah . ' baris [' . count($laporan['items']) . ']'
        );
        $assert(
            count($ekspor['items']) === $jumlah,
            'KL-3 Ekspor mode ' . $mode . ' menghasilkan ' . $jumlah . ' baris [' . count($ekspor['items']) . ']'
        );
        $assert(
            array_sum($laporan['summary']['statuses']) === $jumlah,
            'KL-4 Jumlah status mode ' . $mode . ' sama dengan jumlah baris detail'
        );
    }

    $gabungan = report_service()->report($filterDasar + ['subject_scope' => ReportFilter::SCOPE_GABUNGAN], $adminUser);
    $assert(
        $gabungan['summary']['student_attendance_count'] === 30 && $gabungan['summary']['teacher_attendance_count'] === 1,
        'KL-5 Mode gabungan menampilkan jumlah santri dan guru masing-masing'
    );
    $jenis = array_unique(array_column($gabungan['items'], 'subject_type'));
    sort($jenis);
    $assert($jenis === ['Guru', 'Santri'], 'KL-6 Mode gabungan memberi penanda jenis pada setiap baris');

    $santriSaja = report_service()->report($filterDasar + ['subject_scope' => ReportFilter::SCOPE_SANTRI], $adminUser);
    $namaPengampu = array_unique(array_column($santriSaja['items'], 'teacher_name'));
    $assert(
        count($namaPengampu) === 1 && str_contains((string) $namaPengampu[0], $suffix),
        'KL-7 Guru tetap tampil sebagai pengampu pada laporan santri tanpa dihitung sebagai santri'
    );

    // Default kontrak API TIDAK berubah.
    $tanpaParameter = report_service()->report($filterDasar, $adminUser);
    $assert(
        $tanpaParameter['summary']['detail_count'] === 31
        && $tanpaParameter['filters']['subject_scope'] === ReportFilter::SCOPE_GABUNGAN,
        'KL-8 Default REST API tetap gabungan bila subject_scope tidak dikirim'
    );
    $defaultWeb = report_service()->report($filterDasar, $adminUser, ReportFilter::SCOPE_SANTRI);
    $assert(
        $defaultWeb['summary']['detail_count'] === 30 && $defaultWeb['filters']['subject_scope'] === ReportFilter::SCOPE_SANTRI,
        'KL-9 Halaman web memakai penyajian Santri sebagai tampilan awal'
    );

    $tolak(
        static fn () => report_service()->report($filterDasar + ['subject_scope' => 'semua'], $adminUser),
        'Penyajian laporan tidak valid',
        'KL-10 Nilai penyajian yang tidak dikenal ditolak 422'
    );

    // Absensi guru tetap ada meskipun mode Santri tidak menampilkannya.
    $absensiGuruTersimpan = (int) ($db->query('SELECT COUNT(*) AS c FROM absensi_guru WHERE pertemuan_id = ' . $pertemuanId)?->fetch_assoc()['c'] ?? 0);
    $assert($absensiGuruTersimpan === 1, 'KL-11 Absensi guru tidak dihapus oleh pemisahan penyajian');
} catch (Throwable $exception) {
    $assert(false, 'Pengujian terhenti: ' . $exception->getMessage());
} finally {
    // ------------------------------------------------------------- bersih
    foreach ($dibuat['pertemuan'] as $id) {
        $db->query('DELETE FROM absensi_santri WHERE pertemuan_id = ' . (int) $id);
        $db->query('DELETE FROM absensi_guru WHERE pertemuan_id = ' . (int) $id);
        $db->query('DELETE FROM pertemuan_peserta WHERE pertemuan_id = ' . (int) $id);
        $db->query('DELETE FROM pertemuan_pengajian WHERE id = ' . (int) $id);
    }
    foreach ($dibuat['jadwal'] as $id) {
        $db->query('DELETE FROM jadwal_ngaji WHERE id = ' . (int) $id);
    }
    foreach ($dibuat['santri'] as $id) {
        $db->query('DELETE FROM santri_wali WHERE santri_id = ' . (int) $id);
        $db->query('DELETE FROM plotting_kelas WHERE id_santri = ' . (int) $id);
        $db->query('DELETE FROM santri WHERE id = ' . (int) $id);
    }
    foreach ($dibuat['wali'] as $id) {
        $db->query('DELETE FROM santri_wali WHERE wali_id = ' . (int) $id);
        $db->query('DELETE FROM wali WHERE id = ' . (int) $id);
    }
    foreach ($dibuat['kelas'] as $id) {
        $db->query('DELETE FROM plotting_kelas WHERE id_kelas = ' . (int) $id);
        $db->query('DELETE FROM kelas WHERE id = ' . (int) $id);
    }
    foreach ($dibuat['users'] as $id) {
        $db->query('DELETE FROM user_roles WHERE user_id = ' . (int) $id);
        $db->query('DELETE FROM users WHERE id = ' . (int) $id);
    }
    foreach ($dibuat['guru'] as $id) {
        $db->query('DELETE FROM murobi_assignments WHERE guru_id = ' . (int) $id);
        $db->query('DELETE FROM guru WHERE id = ' . (int) $id);
    }
    echo '[bersih] Fixture perapihan dihapus.' . PHP_EOL;
}

echo PHP_EOL;
if ($failures === []) {
    echo 'SELURUH PENGUJIAN INTEGRASI PERAPIHAN LULUS.' . PHP_EOL;
    exit(0);
}
echo 'GAGAL (' . count($failures) . "):" . PHP_EOL . ' - ' . implode(PHP_EOL . ' - ', $failures) . PHP_EOL;
exit(1);
