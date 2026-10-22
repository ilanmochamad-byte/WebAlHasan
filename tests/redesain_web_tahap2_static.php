<?php

declare(strict_types=1);

/**
 * Pemeriksaan statis redesain website Tahap 2 — navigasi, peran & Beranda.
 *
 * Tidak memerlukan basis data maupun server. Perilaku nyata diuji di
 * `tests/redesain_web_tahap2_http.php` dan
 * `tests/browser/uji-redesain-web-tahap2.mjs`. Memeriksa:
 *   S2-1 peta menu per peran (A–K), tanpa label versi, H ditunda ke Tahap 4;
 *   S2-2 peran aktif di sesi (AR): terikat akun, hanya peran sah, POST + CSRF;
 *   S2-3 cakupan perizinan dari peran sesi, deretan tombol mode dihapus;
 *   S2-4 pusat notifikasi untuk semua akun (AK) dengan pemeriksaan pemilik;
 *   S2-5 akses ditolak di kerangka (A-5), teks polos 403 V4 dihapus;
 *   S2-6 kerangka: pencarian menu (AQ), pemilih peran, lonceng, keluar POST;
 *   S2-7 Beranda per peran hanya membaca dan memakai cakupan akun;
 *   S2-8 halaman masuk, ganti kata sandi, keluar;
 *   S2-9 CSS/JS; S2-10 fixture hanya DB test_*_test; S2-11 lint.
 *
 *   php tests/redesain_web_tahap2_static.php
 */

$root = dirname(__DIR__);
$lulus = 0;
$gagal = [];
$assert = static function (bool $ok, string $pesan) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    $ok ? $lulus++ : $gagal[] = $pesan;
};
$source = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$tanpaKomentar = static function (string $php): string {
    $hasil = '';
    foreach (token_get_all($php) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $hasil .= is_array($token) ? $token[1] : $token;
    }

    return $hasil;
};
$bagian = static function (string $teks, string $mulai, string $akhir): string {
    $a = strpos($teks, $mulai);
    $b = $a === false ? false : strpos($teks, $akhir, $a + strlen($mulai));

    return $a === false ? '' : substr($teks, $a, $b === false ? null : $b - $a);
};

echo '=== S2-1. Peta menu per peran ===' . PHP_EOL;
$nav = $source('app/Ui/Navigation.php');
$admin = $bagian($nav, 'private static function admin(', 'private static function guru(');
preg_match_all("/self::item\\('([^']+)', '([^']+)'/", $admin, $menuAdmin);
$labelAdmin = array_merge(['Beranda', 'Perizinan', 'Pengajian', 'Pembinaan', 'Penilaian & rapor'], $menuAdmin[2]);
$harapAdmin = ['Beranda', 'Perizinan', 'Pengajian', 'Pembinaan', 'Penilaian & rapor', 'Data santri', 'Asatidz & pengurus', 'Kelas & kobong', 'Pendaftar', 'Pembayaran', 'Rekap keuangan', 'Konten website', 'Tahun ajaran', 'Penugasan', 'Akun & hak akses', 'Kanal notifikasi', 'Data sistem lama'];
$assert($labelAdmin === $harapAdmin, 'Admin: 17 menu dalam urutan keputusan A–E: ' . implode(' · ', $labelAdmin));
preg_match_all("/\\['label' => '([^']*)', 'items'/", $admin, $grupAdmin);
$assert($grupAdmin[1] === ['', 'Kegiatan santri', 'Akademik', 'Data pesantren', 'PSB', 'Website', 'Pengaturan'], 'Admin: 7 grup (Beranda + 6 grup bernama)');
$assert(preg_match("/self::item\\('[^']+', '[^']*\\b(V2|V3|V4)\\b/", $nav) !== 1 && preg_match("/self::tab\\('[^']*\\b(V2|V3|V4)\\b/", $nav) !== 1, 'Tanpa label versi pada menu dan tab');
$assert(str_contains($nav, "self::tab('Publikasi ke orang tua'") && str_contains($nav, "'Informasi pembinaan', '/portal/v3_publikasi.php'"), 'F: staf "Publikasi ke orang tua", orang tua "Informasi pembinaan"');
$guru = $bagian($nav, 'private static function guru(', 'private static function pengurus(');
$assert(str_contains($guru, "'Antrean izin'") && str_contains($guru, "'Sikap binaan'") && str_contains($guru, "'Pembinaan binaan'") && str_contains($guru, "['label' => 'Murobi'"), 'G: murobi mendapat tiga menu di grup Murobi');
$assert(str_contains($guru, '$c->showPengajian()') && str_contains($source('app/Ui/NavigationContext.php'), 'activeScheduleOptions'), 'Guru mapel tanpa jadwal tidak melihat Pengajian');
$pengurus = $bagian($nav, 'private static function pengurus(', 'private static function pendidikan(');
$assert(str_contains($pengurus, '$c->own(PenugasanJenis::CAP_SIKAP_INPUT)') && str_contains($pengurus, "isset(\$c->v3['v3.pelanggaran.kelola'])"), 'J: Sikap semester dan Pembinaan hanya bagi pembimbing');
$pendidikan = $bagian($nav, 'private static function pendidikan(', 'private static function orangTua(');
$assert(!str_contains($pendidikan, 'admin_laporan_absensi.php') && str_contains($pendidikan, 'keputusan H'), 'H: menu laporan absensi Bagian Pendidikan ditunda ke Tahap 4 bersama hak aksesnya');
$assert(!str_contains($source('admin/_laporan_guard.php'), 'pendidikan'), 'H: penjaga laporan tidak diubah di Tahap 2');
$ortu = $bagian($nav, 'private static function orangTua(', 'private static function beranda(');
$assert(str_contains($ortu, '$items[] = $notifikasi;'), 'K: notifikasi orang tua juga tampil sebagai menu');
$assert(str_contains($nav, "'admin_santri.php'") && str_contains($nav, "'proses_mutasi_alumni.php'") && str_contains($nav, "'admin_murobi.php', 'admin_pembimbing.php'") && str_contains($nav, "'admin_izin.php'") && str_contains($nav, "'admin_wali_rekonsiliasi.php'"), 'Alamat lama ditempatkan di bawah menu barunya');
$assert(str_contains($nav, 'Menyembunyikan menu') && !preg_match('/\b(http_response_code|Denial::|exit)\b/', $tanpaKomentar($nav)), 'Peta menu bukan kontrol akses (tanpa guard)');

echo PHP_EOL . '=== S2-2. Peran aktif di sesi ===' . PHP_EOL;
$peran = $source('app/Ui/Peran.php');
$assert(str_contains($peran, "'user' => (int) \$user['id']") && str_contains($peran, "(int) (\$simpan['user'] ?? 0) === (int) (\$user['id'] ?? -1)"), 'Pilihan peran diikat ke id akun');
$assert(preg_match('/public static function set\(array \$user, string \$peran\): bool\s*\{\s*if \(!in_array\(\$peran, self::available\(\$user\), true\)\)/', $peran) === 1, 'Peran di luar hak akun ditolak');
$assert(!preg_match('/\b(INSERT|UPDATE|DELETE)\b/', $tanpaKomentar($peran)), 'Peran aktif tanpa perubahan database (AR)');
$peranPhp = $source('portal/peran.php');
$assert(str_contains($peranPhp, "http_response_code(405)") && str_contains($peranPhp, 'Csrf::requireValid(') && str_contains($peranPhp, 'requireWebUser()') && str_contains($peranPhp, 'SafeRedirect::sanitize('), 'portal/peran.php: masuk, POST + CSRF, tujuan kembali disaring');
$assert(str_contains($source('admin/cek_login.php'), 'Peran::reset();'), 'Masuk ulang kembali ke peran bawaan');

echo PHP_EOL . '=== S2-3. Cakupan perizinan dari peran sesi ===' . PHP_EOL;
foreach (['izin.php', 'izin_antrean.php', 'izin_ringkasan.php', 'izin_buat.php', 'izin_detail.php', 'laporan.php', 'laporan_cetak.php', 'laporan_csv.php'] as $halaman) {
    $isi = $source('portal/' . $halaman);
    $assert(str_contains($isi, 'portal_requested_mode($currentUser, $userCapabilities)') && !str_contains($isi, "isset(\$_GET['mode']) ? (string) \$_GET['mode']"), "{$halaman} memakai cakupan peran sesi");
}
$ui = $source('portal/_ui.php');
$assert(!str_contains($bagian($ui, 'function portal_mode_switcher', 'function portal_pagination'), 'btn-group'), 'Deretan tombol mode per halaman dihapus');

echo PHP_EOL . '=== S2-4. Notifikasi untuk semua akun ===' . PHP_EOL;
$notif = $tanpaKomentar($source('portal/notifikasi.php'));
$assert(!str_contains($notif, '_ui.php') && !str_contains($notif, '_guard.php') && str_contains($notif, 'authorization()->requireWebUser()'), 'AK: penjaga perizinan diganti penjaga akun masuk');
$assert(str_contains($notif, 'Csrf::requireValid(') && !preg_match('/\$_(GET|POST)\[[\'"](user|penerima)/', $notif), 'Mutasi ber-CSRF; penerima tidak pernah diambil dari parameter');
$repo = $source('app/Notification/NotificationRepository.php');
$assert(str_contains($repo, "'perizinan' => 'izin.%'") && str_contains($repo, '$pola = self::KATEGORI[$kategori ?? \'\'] ?? null;') && str_contains($repo, "n.penerima_user_id = ?"), 'Kategori dari daftar tetap; query tetap mewajibkan penerima');

echo PHP_EOL . '=== S2-5. Akses ditolak ===' . PHP_EOL;
foreach (['sikap_semester.php', 'verifikasi_semester.php', 'rapor_penanda.php', 'rapor_semester.php', 'nilai_semester.php', 'rencana_nilai.php'] as $halaman) {
    $isi = $source('portal/' . $halaman);
    $assert(!str_contains($isi, "echo '403 — Akses ditolak.'") && str_contains($isi, 'Denial::page('), "{$halaman}: teks polos 403 diganti halaman akses ditolak");
}
$denial = $source('app/Ui/Denial.php');
$assert(str_contains($denial, 'ah_page_open(') && str_contains($denial, 'http_response_code($status)') && str_contains($denial, 'minta admin pesantren'), 'Denial di dalam kerangka, tetap 403, menyebut siapa yang memberi akses');

echo PHP_EOL . '=== S2-6. Kerangka ===' . PHP_EOL;
$layout = $source('app/Ui/Layout.php');
foreach (['ah-menu-data' => 'data pencarian menu', 'role="combobox"' => 'kotak cari sebagai combobox', 'ah-peran__pill' => 'pil peran topbar', 'ah-drawer-peran' => 'pemilih peran di laci', 'ah-bell__list' => 'pratinjau lonceng', 'ah-tabs--module' => 'tab modul dari peta menu', 'data-ah-drawer' => 'butir Menu bilah bawah', "'bare'" => 'kerangka polos'] as $jarum => $arti) {
    $assert(str_contains($layout, $jarum), "Layout memuat {$arti}");
}
$assert(!preg_match('/<a[^>]+\$logoutUrl/', $layout) && str_contains($layout, 'data-confirm-title="Keluar dari Sistem Al Hasan?"'), 'A-4: Keluar berupa formulir POST dengan dialog, bukan tautan');
$assert(str_contains($layout, "'per_page' => 5"), 'Lonceng memuat 5 notifikasi terbaru');
$assert(str_contains($layout, 'Peran::set($user, $peran)') && str_contains($layout, 'Peran beralih ke'), 'Halaman milik peran lain akun ini mengalihkan peran dengan pesan');

echo PHP_EOL . '=== S2-7. Beranda ===' . PHP_EOL;
$beranda = $tanpaKomentar($source('app/Beranda/BerandaService.php'));
$assert(!preg_match('/\b(INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM)\b/i', $beranda), 'BerandaService hanya membaca');
$assert(!preg_match('/->query\(/', $beranda) && str_contains($beranda, '->prepare($sql)'), 'BerandaService memakai prepared statement');
$assert(str_contains($beranda, 'TEMPAT_PRIORITAS = 4') && str_contains($beranda, 'BATAS_TERTUNDA_JAM = 24'), 'L/P: 4 tempat, batas tertunda 1 hari');
preg_match_all("/\\\$this->kartu\\(\\\$kartu, '([a-z_]+)'/", $beranda, $urutan);
$assert($urutan[1] === ['izin_penetapan', 'psb_baru', 'psb_belum_lunas', 'notifikasi_gagal', 'izin_tertunda', 'bahan_perbaikan', 'santri_tanpa_tempat', 'rekomendasi'], 'L + AA: urutan delapan kartu prioritas');
$index = $source('portal/index.php');
$assert(!preg_match("/require[^;]*_(guard|ui)\\.php/", $index), 'Beranda tidak memuat guard perizinan');
$assert(str_contains($index, 'Peran::ADMIN') && str_contains($index, 'Peran::GURU') && str_contains($index, 'Peran::PENGURUS') && str_contains($index, 'Peran::PENDIDIKAN'), 'Beranda per peran B-1 s.d. B-5');
$assert(str_contains($index, 'in_array($dipilih, $anakIds, true)'), 'B-5: pilihan anak dibatasi anak yang terhubung');

echo PHP_EOL . '=== S2-8. Masuk, kata sandi, keluar ===' . PHP_EOL;
foreach (['Nama pengguna atau kata sandi salah.', 'Sesi Anda berakhir. Silakan masuk lagi.', 'Anda sudah keluar.', 'Terlalu banyak percobaan. Coba lagi beberapa saat lagi.', 'Akun ini belum punya hak akses. Hubungi admin.', 'data-ah-reveal="password"', 'ah-masuk__id'] as $jarum) {
    $assert(str_contains($index, $jarum), "A-1 memuat: {$jarum}");
}
$sandi = $source('admin/ubah_password.php');
$assert(str_contains($sandi, "'bare' => \$wajib") && str_contains($sandi, 'data-ah-password') && str_contains($sandi, "strlen(\$password) < 12"), 'A-3: kerangka (polos saat wajib), daftar centang, syarat tetap di server');
$logout = $source('admin/logout.php');
$assert(str_contains($logout, "if (\$_SERVER['REQUEST_METHOD'] === 'POST')") && str_contains($logout, 'Csrf::requireValid(') && str_contains($logout, 'ah_page_open('), 'A-4: keluar hanya lewat POST ber-CSRF; GET konfirmasi di kerangka');

echo PHP_EOL . '=== S2-9. CSS dan JavaScript ===' . PHP_EOL;
$css = $source('assets/ui/alhasan.css');
$js = $source('assets/ui/alhasan.js');
$assert(str_contains($css, '.ah-nojs .ah-search') && str_contains($css, '.ah-task-grid') && str_contains($css, '.ah-masuk__wrap') && !preg_match('#https?://#', $css), 'CSS komponen Tahap 2 tanpa aset eksternal');
foreach (["getElementById('ah-menu-data')", 'aria-activedescendant', 'data-ah-reveal', 'data-ah-password', '[data-ah-drawer]', '[data-ah-find]'] as $jarum) {
    $assert(str_contains($js, $jarum), "alhasan.js memuat {$jarum}");
}
$assert(!preg_match('/\b(fetch|XMLHttpRequest)\b/', $bagian($js, 'Pencarian menu', 'Tampilkan kata sandi')), 'Pencarian menu tanpa permintaan jaringan (AQ)');
exec('node --check ' . escapeshellarg($root . '/assets/ui/alhasan.js') . ' 2>&1', $keluaranNode, $kodeNode);
$assert($kodeNode === 0, 'Sintaks alhasan.js valid');

echo PHP_EOL . '=== S2-10. Fixture ===' . PHP_EOL;
$assert(str_contains($source('tests/redesain_web_tahap2_seed.php'), "preg_match('/^test_.*_test\$/', \$namaDb)") && str_contains($source('tests/redesain_web_tahap2_db.sh'), 'case "$ASAL" in *_test)'), 'Fixture hanya untuk DB uji test_*_test dari sandbox *_test');

echo PHP_EOL . '=== S2-11. Lint ===' . PHP_EOL;
foreach (['app/Ui/Navigation.php', 'app/Ui/NavigationContext.php', 'app/Ui/Peran.php', 'app/Ui/Layout.php', 'app/Ui/Denial.php', 'app/Ui/Status.php', 'app/Beranda/BerandaService.php', 'app/Notification/NotificationRepository.php', 'app/Notification/NotificationCenterService.php', 'app/Auth/Authorization.php', 'app/Auth/PortalGuard.php', 'app/bootstrap.php', 'portal/index.php', 'portal/peran.php', 'portal/notifikasi.php', 'portal/_guard.php', 'portal/_ui.php', 'admin/ubah_password.php', 'admin/logout.php', 'admin/cek_login.php', 'admin/sidebar.php', 'tests/redesain_web_tahap2_seed.php', 'tests/redesain_web_tahap2_http.php'] as $berkas) {
    exec('php -l ' . escapeshellarg($root . '/' . $berkas) . ' 2>&1', $keluaran, $kode);
    $assert($kode === 0, 'php -l ' . $berkas);
}

echo PHP_EOL . ($gagal === [] ? 'Semua pemeriksaan lulus (' . $lulus . ').' : count($gagal) . ' pemeriksaan gagal.') . PHP_EOL;
exit($gagal === [] ? 0 : 1);
