<?php

declare(strict_types=1);

/**
 * Pemeriksaan statis paket "Koreksi dan Modernisasi UI/UX V1–V2".
 *
 * Tidak memerlukan basis data. Fokus pada janji-janji struktural ketujuh
 * koreksi (keputusan pengguna 30 Agustus 2026):
 *
 *   1. pusat Akun & Hak Akses: role eksplisit, tanpa penghapusan role massal;
 *   2. data santri–wali: pemilihan wali, atomik, tanpa penggabungan otomatis;
 *   3. data guru: tanpa pilihan tugas lama, kolom lama tidak ditimpa;
 *   4. modul Pengajian bertab dengan alamat lama tetap berfungsi;
 *   5. pemisahan penyajian laporan kehadiran tanpa mengubah default API;
 *   6. lapisan desain bersama, navigasi bebas guard, cetak tanpa sidebar;
 *   7. satu pintu masuk /portal/ tanpa sistem login kedua;
 *   8. migrasi 010 aditif dan berpasangan dengan rollback;
 *   9. lint sintaks seluruh berkas baru/diubah.
 *
 * Jalankan:
 *   php tests/perapihan_static.php
 */

$root = dirname(__DIR__);
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? '[lulus] ' : '[gagal] ') . $message . PHP_EOL;
    if (!$condition) {
        $failures[] = $message;
    }
};
$source = static fn (string $path): string => (string) @file_get_contents($root . '/' . $path);

/** Membuang komentar sebelum menilai larangan: yang dinilai KODE, bukan dokumentasi. */
$tanpaKomentar = static function (string $php): string {
    if (trim($php) === '') {
        return '';
    }
    $bersih = '';
    foreach (token_get_all($php) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $bersih .= $token[1];
            continue;
        }
        $bersih .= $token;
    }

    return $bersih;
};

echo '=== 1. Pusat Akun & Hak Akses ===' . PHP_EOL;

$accountRepo = $source('app/Account/AccountRepository.php');
$accountService = $source('app/Account/AccountService.php');
$halamanAkun = $source('admin/admin_akun.php');

$assert(
    str_contains($accountRepo, 'function grantRole') && str_contains($accountRepo, 'function revokeRole'),
    'Repository akun menyediakan penambahan dan pencabutan role per satu relasi'
);
$assert(
    !str_contains($tanpaKomentar($accountRepo), 'DELETE FROM user_roles WHERE user_id = ?')
    && !preg_match('/function setRole/', $tanpaKomentar($accountRepo)),
    'Jalur lama yang menghapus SELURUH role sebelum menetapkan satu role sudah dihapus'
);
$assert(
    str_contains($accountRepo, 'DELETE ur FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.slug = ?'),
    'Pencabutan role hanya menyentuh satu baris relasi, bukan seluruh role akun'
);
$assert(
    str_contains($accountService, 'requireMasterRelation')
    && str_contains($accountService, 'Role Guru hanya dapat diberikan')
    && str_contains($accountService, 'Role Pengurus hanya dapat diberikan')
    && str_contains($accountService, 'Role Orang Tua hanya dapat diberikan'),
    'Role guru/pengurus/orang tua menuntut relasi master yang valid di server'
);
$assert(
    str_contains($accountService, 'KONFIRMASI_ADMIN') && str_contains($accountService, "konfirmasi_admin"),
    'Pemberian role admin menuntut konfirmasi khusus'
);
$assert(
    str_contains($accountService, 'countActiveAdmins(true)') && str_contains($accountRepo, 'FOR UPDATE'),
    'Perlindungan admin terakhir memakai penguncian baris di dalam transaksi (tahan permintaan bersamaan)'
);
$assert(
    str_contains($accountService, 'tidak dapat melepas hak admin dari akun sendiri')
    && str_contains($accountService, 'tidak dapat menonaktifkan akun sendiri'),
    'Admin tidak dapat menghilangkan aksesnya sendiri karena kesalahan'
);
$assert(
    str_contains($accountService, "'account_role_granted'") && str_contains($accountService, "'account_role_revoked'"),
    'Setiap perubahan hak akses tercatat pada audit'
);
$assert(
    str_contains($halamanAkun, 'Murobi (kemampuan') || str_contains($halamanAkun, 'murobi_aktif'),
    'Murobi ditampilkan sebagai kemampuan berdasarkan penugasan aktif, bukan role'
);
$assert(
    !preg_match('/<select[^>]*name="role"/', $halamanAkun),
    'Tidak ada lagi dropdown role tunggal yang memaksa akun menjadi satu kategori'
);
$assert(
    str_contains($source('admin/admin_akun_perizinan.php'), "require __DIR__ . '/admin_akun.php'"),
    'Halaman akun lama meneruskan POST ke pusat akun (guard admin dan CSRF tetap berjalan)'
);
$assert(
    str_contains($accountService, 'resetPassword') && str_contains($accountService, 'revokeAllForUser')
    && str_contains($accountService, 'force_password_change'),
    'Reset password, pencabutan perangkat, dan kewajiban ganti password awal dipertahankan'
);

echo PHP_EOL . '=== 2. Data santri dan wali ===' . PHP_EOL;

$masterService = $source('app/MasterData/MasterDataService.php');
$masterRepo = $source('app/MasterData/MasterDataRepository.php');
$halamanSantri = $source('admin/admin_master_santri.php');
$blokWali = $source('admin/_santri_wali_field.php');
$rekonsiliasi = $source('admin/admin_wali_rekonsiliasi.php');

$assert(
    str_contains($masterService, 'normalizeWaliSpec') && str_contains($masterService, 'applyWaliSpec'),
    'saveSantri menerima spesifikasi wali yang eksplisit'
);
$assert(
    !preg_match('/foreach \(\[\[.name. => \$data\[.nama_ayah.\]/', $tanpaKomentar($masterService)),
    'Pembuatan wali otomatis per santri baru sudah dihapus'
);
$assert(
    str_contains($masterService, 'begin_transaction()') && str_contains($masterService, 'rollback()'),
    'Santri, wali baru, dan relasinya disimpan dalam satu transaksi'
);
$assert(
    str_contains($halamanSantri, 'ah_form_token(') && str_contains($halamanSantri, 'ah_form_token_consume('),
    'Formulir santri dilindungi token sekali pakai terhadap pengiriman ulang'
);
$assert(
    str_contains($blokWali, 'Pilih wali terdaftar') && str_contains($blokWali, 'Buat wali baru'),
    'Formulir santri menyediakan pemilihan wali terdaftar dan pembuatan wali baru'
);
$assert(
    str_contains($blokWali, 'tidak</strong> membuat akun login') || str_contains($blokWali, 'tidak membuat akun login'),
    'Pembuatan atau pemilihan wali dinyatakan tidak membuat akun login'
);
$assert(
    !str_contains($tanpaKomentar($masterService), 'perizinan_account_service')
    && !preg_match('/INSERT INTO users/i', $tanpaKomentar($masterRepo)),
    'Jalur master data tidak pernah membuat akun login'
);
$assert(
    str_contains($masterService, 'konfirmasi_timpa') && str_contains($masterService, "'master.legacy.mirror'"),
    'Nilai kolom lama yang bertentangan hanya ditimpa setelah konfirmasi, dengan audit sebelum/sesudah'
);
$assert(
    str_contains($masterService, "array_key_exists('nama_ayah', \$input)"),
    'Kolom lama tidak dikosongkan hanya karena formulir tidak lagi mengirimkannya'
);
$assert(
    str_contains($masterService, 'konfirmasi_dampak') && str_contains($masterService, 'santri_terdampak'),
    'Perubahan identitas wali bersama menampilkan santri terdampak dan menuntut konfirmasi'
);
$assert(
    str_contains($masterService, 'function mergeWali')
    && str_contains($masterService, 'Penggabungan diblokir')
    && str_contains($masterService, 'waliMarkMerged'),
    'Penggabungan identitas dilakukan satu pasang, dapat diblokir, dan mempertahankan baris sumber'
);
$assert(
    !preg_match('/DELETE\s+FROM\s+wali/i', $tanpaKomentar($masterRepo))
    && !preg_match('/DELETE\s+FROM\s+santri_wali/i', $tanpaKomentar($masterRepo)),
    'Rekonsiliasi tidak pernah menghapus wali atau relasinya (ID lama dipertahankan)'
);
$assert(
    str_contains($rekonsiliasi, 'TIDAK ADA penggabungan massal') || str_contains($rekonsiliasi, 'Tidak ada penggabungan massal'),
    'Halaman rekonsiliasi menegaskan tidak ada penggabungan massal'
);
$assert(
    str_contains($masterRepo, 'function waliDuplicateCandidates')
    && str_contains($masterRepo, 'function waliWithoutRelations')
    && str_contains($masterRepo, 'function santriWithIncompleteWali')
    && str_contains($masterRepo, 'function santriLegacyConflicts'),
    'Laporan kandidat duplikasi, konflik, dan hubungan belum lengkap tersedia'
);
$assert(
    str_contains($halamanSantri, 'Hubungan wali') && str_contains($halamanSantri, 'santriWali('),
    'Detail santri menampilkan hubungan wali aktual, bukan hanya teks kolom lama'
);

echo PHP_EOL . '=== 3. Data guru dan penugasan ===' . PHP_EOL;

$halamanGuru = $source('admin/admin_guru.php');
$halamanMurobi = $source('admin/admin_murobi.php');

$assert(
    !preg_match('/\[.Guru.,\s*.Pembimbing.,\s*.Keduanya.\]/', $halamanGuru . $masterService),
    'Pilihan tugas lama Guru/Pembimbing/Keduanya dihapus dari formulir dan validasi'
);
$assert(
    !preg_match('/<select[^>]*name="status"/', $halamanGuru),
    'Tidak ada dropdown pengganti pada formulir guru'
);
$assert(
    str_contains($halamanGuru, 'Data Guru') && !str_contains($halamanGuru, 'Guru &amp; Pembimbing'),
    'Label halaman guru menjadi Data Guru'
);
$assert(
    str_contains($source('app/Ui/Navigation.php'), "'Data Guru'"),
    'Menu memakai label Data Guru'
);
$assert(
    str_contains($masterRepo, "'UPDATE guru SET nip = ?, nama_guru = ?, no_hp = ?, updated_at = NOW() WHERE id = ?'"),
    'Menyimpan guru tidak menyentuh kolom status lama (data historis dipertahankan)'
);
$assert(
    str_contains($masterRepo, 'function guruAssignmentSummary')
    && str_contains($masterRepo, 'FROM jadwal_ngaji')
    && str_contains($masterRepo, 'FROM murobi_assignments'),
    'Penugasan dihitung dari jadwal dan penugasan murobi, bukan dari label tugas'
);
$assert(
    str_contains($halamanGuru, 'aktif/nonaktif') || str_contains($halamanGuru, 'Nonaktifkan'),
    'Status aktif/nonaktif guru dipertahankan'
);
$assert(
    str_contains($halamanMurobi, 'approval izin')
    && str_contains($halamanMurobi, 'tanpa jadwal mengajar'),
    'Keterangan murobi diperbarui mengikuti aturan V2 dan menyebut guru tanpa jadwal'
);

echo PHP_EOL . '=== 4. Modul Pengajian terpadu ===' . PHP_EOL;

$pengajian = $source('admin/admin_pengajian.php');

$assert(
    str_contains($pengajian, "?tab=jadwal") && str_contains($pengajian, "?tab=pertemuan"),
    'Satu menu Pengajian dengan tab Jadwal dan Pertemuan'
);
$assert(
    str_contains($pengajian, "'tabs' =>"),
    'Tab dirender lewat kerangka bersama'
);
$assert(
    str_contains($pengajian, 'ah_query(array_merge')
    && str_contains($source('admin/_pengajian_jadwal.php'), "'schedule_id' => \$jadwalId"),
    'Konteks dan filter terbawa saat berpindah tab dan saat membuka pertemuan sebuah jadwal'
);
$assert(
    str_contains($pengajian, 'Hanya admin yang dapat mengelola jadwal pengajian'),
    'Guru tidak memperoleh hak pengelolaan jadwal admin (ditolak di server)'
);
$assert(
    str_contains($pengajian, "'teacher_id' => \$bolehKelolaJadwal ? (\$_GET['teacher_id'] ?? '') : \$guruId"),
    'Filter guru dipaksa di server sehingga guru tidak melihat jadwal guru lain'
);
foreach (['admin/admin_jadwal_ngaji.php', 'admin/pertemuan_pengajian.php'] as $alamatLama) {
    $kode = $source($alamatLama);
    $assert(
        str_contains($kode, "require __DIR__ . '/admin_pengajian.php'") && str_contains($kode, '302'),
        basename($alamatLama) . ' tetap berfungsi: GET dialihkan, POST diteruskan penuh'
    );
}
$assert(
    !preg_match('/DELETE\s+FROM\s+(pertemuan_pengajian|pertemuan_peserta|absensi_)/i', $tanpaKomentar($pengajian)),
    'Modul pengajian tidak menghapus pertemuan, peserta, atau absensi'
);

echo PHP_EOL . '=== 5. Pemisahan laporan kehadiran ===' . PHP_EOL;

$reportFilter = $source('app/Report/ReportFilter.php');
$reportRepo = $source('app/Report/ReportRepository.php');
$reportService = $source('app/Report/ReportService.php');
$halamanLaporan = $source('admin/admin_laporan_absensi.php');

$assert(
    str_contains($reportFilter, "SCOPE_SANTRI = 'santri'")
    && str_contains($reportFilter, "SCOPE_GURU = 'guru'")
    && str_contains($reportFilter, "SCOPE_GABUNGAN = 'gabungan'"),
    'Tersedia tiga penyajian: santri, guru, gabungan'
);
$assert(
    str_contains($reportFilter, 'DEFAULT_SCOPE_API = self::SCOPE_GABUNGAN'),
    'Default kontrak API lama TIDAK berubah (tetap gabungan)'
);
$assert(
    str_contains($halamanLaporan, 'ReportFilter::SCOPE_SANTRI'),
    'Halaman web memakai penyajian Santri sebagai tampilan awal secara eksplisit'
);
$assert(
    str_contains($reportRepo, 'includesGuru()') && str_contains($reportRepo, 'includesSantri()'),
    'Pemisahan dilakukan pada satu tempat di repository laporan'
);
$assert(
    substr_count($reportRepo, 'attendanceRowsSql(') >= 5,
    'Ringkasan, ringkasan per jadwal, halaman, ekspor, dan EXPLAIN memakai definisi filter yang sama'
);
$assert(
    str_contains($source('admin/export_laporan_absensi.php'), 'ReportFilter::SCOPE_SANTRI')
    && str_contains($source('admin/laporan_absensi_cetak.php'), 'ReportFilter::SCOPE_SANTRI'),
    'CSV dan cetak memakai penyajian awal yang sama dengan layar'
);
$assert(
    str_contains($halamanLaporan, "\$query['subject_scope'] = \$scope"),
    'Tombol ekspor dan cetak membawa penyajian yang sedang aktif'
);
$assert(
    str_contains($reportService, "'Penyajian' => \$filter->scopeLabel()"),
    'Penyajian ikut tercetak pada daftar filter aktif'
);
$assert(
    !preg_match('/DELETE\s+FROM\s+absensi_guru/i', $tanpaKomentar($reportRepo) . $tanpaKomentar($halamanLaporan)),
    'Absensi guru tidak dihapus oleh pemisahan penyajian'
);
$assert(
    str_contains($halamanLaporan, 'teacher_name'),
    'Guru tetap tampil sebagai pengampu pada laporan santri'
);

echo PHP_EOL . '=== 6. Desain dan navigasi bersama ===' . PHP_EOL;

$css = $source('assets/ui/alhasan.css');
$layout = $source('app/Ui/Layout.php');
$navigation = $source('app/Ui/Navigation.php');
$sidebar = $source('admin/sidebar.php');

$assert($css !== '', 'Berkas desain bersama tersedia: assets/ui/alhasan.css');
$assert(
    str_contains($css, ':root') && str_contains($css, '--ah-green-800') && str_contains($css, '--ah-radius'),
    'Warna, jarak, dan sudut memakai token bersama'
);
$assert(
    str_contains($css, '@media print') && str_contains($css, '.ah-sidebar,'),
    'Halaman cetak membuang kerangka navigasi'
);
$assert(
    str_contains($css, 'prefers-reduced-motion'),
    'Preferensi pengurangan animasi dihormati'
);
$assert(
    str_contains($css, ':focus-visible') && str_contains($css, 'outline:'),
    'Fokus keyboard selalu terlihat'
);
$assert(
    str_contains($css, 'overflow-x: auto'),
    'Tabel lebar menggulir di dalam wadahnya sendiri'
);
$assert(
    str_contains($css, '--ah-touch: 44px'),
    'Ukuran sentuh minimum ditetapkan untuk ponsel'
);
$assert(
    !preg_match('#https?://(?!cdn\.jsdelivr\.net|cdnjs\.cloudflare\.com)#', $css),
    'Tidak ada aset eksternal baru pada berkas desain'
);
$assert(
    // Yang dinilai KODE, bukan komentar penjelas.
    !str_contains($tanpaKomentar($navigation), '_guard.php')
    && !str_contains($tanpaKomentar($navigation), 'requireWebRole')
    && !str_contains($tanpaKomentar($navigation), 'requireWebUser'),
    'Komponen navigasi bebas dari guard khusus admin sehingga aman lintas peran'
);
$assert(
    !str_contains($sidebar, "require_once __DIR__ . '/_guard.php'"),
    'sidebar.php tidak lagi menyeret guard khusus admin'
);
$assert(
    str_contains($layout, 'ah-skip') && str_contains($layout, 'Lompat ke konten utama'),
    'Tersedia tautan lompat ke konten utama'
);
$assert(
    str_contains($layout, 'aria-current="page"') && str_contains($layout, 'ah-crumbs'),
    'Menu aktif dan breadcrumb tersedia'
);
$assert(
    str_contains($layout, 'ah-nav-toggle') && str_contains($layout, 'aria-expanded'),
    'Menu ponsel dapat dibuka/ditutup dengan status yang terbaca pembaca layar'
);
$assert(
    str_contains($source('app/Ui/functions.php'), 'function ah_old_keep'),
    'Isian pengguna dipertahankan saat validasi gagal'
);
$assert(
    str_contains($source('app/Ui/functions.php'), 'function ah_empty'),
    'Tersedia keadaan kosong yang menjelaskan langkah berikutnya'
);
foreach (['admin/_master_ui.php', 'portal/_ui.php'] as $adaptor) {
    $assert(
        str_contains($source($adaptor), 'ah_page_open'),
        basename($adaptor) . ' memakai kerangka bersama, bukan menggambar layout sendiri'
    );
}
$assert(
    !str_contains($source('admin/laporan_absensi_cetak.php'), 'master_header')
    && !str_contains($source('portal/laporan_cetak.php'), 'portal_header'),
    'Halaman cetak tidak memuat kerangka bersidebar'
);

echo PHP_EOL . '=== 7. Satu pintu masuk /portal/ ===' . PHP_EOL;

$pintu = $source('portal/index.php');
$cekLogin = $source('admin/cek_login.php');
$safeRedirect = $source('app/Http/SafeRedirect.php');

$assert(
    str_contains($pintu, 'Masuk Sistem Al Hasan'),
    'Pengguna anonim yang membuka /portal/ mendapat halaman Masuk Sistem Al Hasan'
);
$assert(
    str_contains($pintu, "app_url('/admin/cek_login.php')") && !str_contains($pintu, 'password_verify'),
    'Tidak ada sistem login kedua: formulir memakai penangan autentikasi yang sudah ada'
);
$assert(
    !str_contains($pintu, "require_once __DIR__ . '/_guard.php'"),
    'Beranda umum tidak memakai pemeriksaan kemampuan perizinan'
);
$assert(
    str_contains($pintu, "force_password_change") && str_contains($pintu, 'ubah_password.php'),
    'Password sementara wajib diselesaikan sebelum fungsi operasional'
);
$assert(
    str_contains($pintu, 'belum memiliki peran atau hubungan data yang sah'),
    'Akun tanpa role atau relasi valid mendapat penjelasan tanpa akses tambahan'
);
$assert(
    str_contains($source('admin/logout.php'), "app_url('/portal/index.php')")
    && str_contains($source('admin/logout.php'), 'Session::destroy'),
    'Logout mengakhiri sesi dan kembali ke pintu masuk yang sama'
);
$assert(
    str_contains($source('admin/admin_login.php'), '302') && str_contains($source('admin/admin_login.php'), "app_url('/portal/index.php')"),
    'Alamat lama /admin/admin_login.php mengarahkan ke pintu masuk baru'
);
$assert(
    str_contains($source('app/Auth/Authorization.php'), "app_url('/portal/index.php')"),
    'Sesi kedaluwarsa mengarah ke pintu masuk yang sama'
);
$assert(
    str_contains($safeRedirect, "ALLOWED_PREFIXES = ['/admin/', '/portal/']")
    && str_contains($safeRedirect, "str_starts_with(\$value, '//')")
    && str_contains($safeRedirect, 'BLOCKED_SCRIPTS'),
    'Pemulihan tujuan hanya menerima alamat internal dan menolak tujuan eksternal atau berulang'
);
$assert(
    str_contains($cekLogin, 'login_throttle()') && str_contains($cekLogin, 'Csrf::requireValid'),
    'Pembatasan percobaan masuk dan CSRF tetap berlaku pada penangan login'
);
$assert(
    str_contains($source('app/Auth/AuthService.php'), 'session_regenerate_id(true)'),
    'Regenerasi sesi saat login dipertahankan'
);
$assert(
    str_contains($source('app/Auth/LoginThrottle.php'), 'error_log') && str_contains($source('app/Auth/LoginThrottle.php'), 'audit_logs'),
    'Pembatasan percobaan masuk memakai audit yang sudah ada dan tidak mengunci semua orang bila gagal dihitung'
);
$assert(
    !str_contains($source('api/v1/index.php'), 'portal/index.php'),
    'Endpoint autentikasi API tidak ikut diubah oleh penyatuan pintu masuk web'
);

echo PHP_EOL . '=== 8. Migrasi 010 ===' . PHP_EOL;

$migrasi = $source('database/migrations/010_perapihan_rekonsiliasi_wali.sql');
$rollback = $source('database/rollbacks/010_perapihan_rekonsiliasi_wali.sql');

$assert($migrasi !== '' && $rollback !== '', 'Migrasi 010 memiliki rollback berpasangan');
$assert(
    !preg_match('/\b(DROP\s+TABLE|TRUNCATE|DELETE\s+FROM|UPDATE\s+\w+\s+SET)\b/i', preg_replace('/^\s*--.*$/m', '', $migrasi)),
    'Migrasi 010 sepenuhnya aditif: tidak menghapus atau mengubah data'
);
$assert(
    substr_count($migrasi, 'information_schema') >= 3,
    'Migrasi 010 berpenjaga INFORMATION_SCHEMA sehingga aman dijalankan ulang'
);
$assert(
    !preg_match('/UNIQUE/i', $migrasi),
    'Nomor HP tidak dijadikan kunci unik (boleh dipakai bersama)'
);
$assert(
    str_contains($rollback, 'DROP COLUMN merged_into_wali_id') && str_contains($rollback, 'audit_logs'),
    'Rollback melepas kolom penanda dan menjelaskan jejak yang tetap tersimpan pada audit'
);

echo PHP_EOL . '=== 9. Lint PHP berkas baru/diubah ===' . PHP_EOL;

$berkas = [
    'app/Ui/Layout.php', 'app/Ui/Navigation.php', 'app/Ui/Denial.php', 'app/Ui/functions.php',
    'app/Http/SafeRedirect.php', 'app/Auth/LoginThrottle.php', 'app/Auth/LandingRouter.php',
    'app/Auth/Authorization.php', 'app/Auth/PortalGuard.php', 'app/bootstrap.php',
    'app/Account/AccountRepository.php', 'app/Account/AccountService.php',
    'app/MasterData/MasterDataRepository.php', 'app/MasterData/MasterDataService.php',
    'app/Report/ReportFilter.php', 'app/Report/ReportRepository.php', 'app/Report/ReportService.php',
    'admin/_master_ui.php', 'admin/sidebar.php', 'admin/admin_akun.php', 'admin/admin_akun_perizinan.php',
    'admin/admin_guru.php', 'admin/admin_murobi.php', 'admin/admin_master_santri.php', 'admin/admin_wali.php',
    'admin/admin_wali_rekonsiliasi.php', 'admin/get_wali_json.php', 'admin/_santri_wali_field.php',
    'admin/admin_pengajian.php', 'admin/_pengajian_jadwal.php', 'admin/_pengajian_pertemuan.php',
    'admin/admin_jadwal_ngaji.php', 'admin/pertemuan_pengajian.php', 'admin/admin_laporan_absensi.php',
    'admin/export_laporan_absensi.php', 'admin/laporan_absensi_cetak.php', 'admin/admin_login.php',
    'admin/cek_login.php', 'admin/logout.php', 'admin/ubah_password.php',
    'portal/_ui.php', 'portal/index.php', 'portal/izin_ringkasan.php',
];
foreach ($berkas as $file) {
    $output = [];
    $status = 0;
    exec(escapeshellcmd(PHP_BINARY) . ' -l ' . escapeshellarg($root . '/' . $file) . ' 2>&1', $output, $status);
    $assert($status === 0, 'php -l lulus untuk ' . $file);
}

echo PHP_EOL;
if ($failures === []) {
    echo 'SELURUH PEMERIKSAAN STATIS PERAPIHAN LULUS.' . PHP_EOL;
    exit(0);
}
echo 'GAGAL (' . count($failures) . "):" . PHP_EOL . ' - ' . implode(PHP_EOL . ' - ', $failures) . PHP_EOL;
exit(1);
