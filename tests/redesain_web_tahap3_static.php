<?php

declare(strict_types=1);

/**
 * Uji statis redesain website Tahap 3 — halaman lama ke kerangka baru.
 *
 * Kriteria selesai (cetak biru): tidak ada lagi `sidebar.php` dan `alert()`;
 * daftar berhalaman; WhatsApp tidak dapat dinyalakan. Ditambah: setiap
 * halaman N-2, N-4, N-5, K-5 s.d. K-8, W-1 s.d. W-4 memakai kerangka bersama,
 * tidak memuat pustaka lama (DataTables, SheetJS, Chart.js), query daftar
 * memakai parameter terikat, dan keputusan AG/AH/AI/AL tercermin di kode.
 *
 *   php tests/redesain_web_tahap3_static.php
 */

$root = dirname(__DIR__);
$lulus = 0;
$gagal = 0;
$cek = static function (bool $ok, string $pesan) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    $ok ? $lulus++ : $gagal++;
};
$baca = static fn (string $berkas): string => (string) @file_get_contents($root . '/' . $berkas);
$kode = static function (string $isi): string {
    $hasil = '';
    foreach (token_get_all($isi) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $hasil .= is_array($t) ? $t[1] : $t;
    }

    return $hasil;
};

$halaman = [
    'N-2' => 'admin/admin_notifikasi.php', 'N-4' => 'admin/admin_dashboard.php', 'N-5' => 'admin/admin_pelanggaran.php',
    'K-5' => 'admin/admin_data.php', 'K-6' => 'admin/admin_pembayaran_psb.php', 'K-7' => 'admin/admin_rekap_keuangan.php',
    'K-8' => 'admin/admin_rekap_santri.php', 'W-1' => 'admin/admin_berita.php', 'W-2' => 'admin/_berita_form.php',
    'W-3' => 'admin/admin_galeri.php', 'W-4' => 'admin/admin_download.php',
];
$baru = ['app/Ui/Chart.php', 'app/Beranda/RingkasanAdministrasi.php', 'app/Psb/PsbAdmin.php', 'app/Psb/PsbPembayaran.php', 'app/Psb/PsbException.php', 'app/Konten/Unggahan.php'];
$diubah = ['admin/proses_psb_admin.php', 'admin/hapus_siswa.php', 'admin/export_psb.php', 'admin/export_pembayaran.php', 'admin/proses_berita.php',
    'admin/tambah_berita.php', 'admin/edit_berita.php', 'app/Ui/functions.php', 'app/Ui/Navigation.php', 'app/V3/KatalogService.php',
    'app/Notification/NotificationChannel.php', 'app/Notification/NotificationAdminService.php', 'app/Notification/SettingsRepository.php',
    'app/Beranda/BerandaService.php', 'tests/redesain_web_tahap3_seed.php', 'tests/redesain_web_tahap3_http.php'];

// S3-1 lint
foreach (array_unique([...array_values($halaman), ...$baru, ...$diubah]) as $berkas) {
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($root . '/' . $berkas) . ' 2>&1', $out, $status);
    $cek($status === 0, 'S3-1 php -l ' . $berkas);
}
exec('node --check ' . escapeshellarg($root . '/assets/ui/alhasan.js') . ' 2>&1', $out, $status);
$cek($status === 0, 'S3-1 node --check assets/ui/alhasan.js');

// S3-2 kriteria: sidebar.php pensiun
$cek(!is_file($root . '/admin/sidebar.php'), 'S3-2 admin/sidebar.php sudah dihapus');
$pemakai = [];
foreach (['admin', 'portal', 'app', 'api'] as $folder) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $folder, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() === 'php' && preg_match('/sidebar\.php/', $kode((string) file_get_contents($file->getPathname())))) {
            $pemakai[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }
}
$cek($pemakai === [], 'S3-2 tidak ada kode yang memuat sidebar.php' . ($pemakai === [] ? '' : ' (' . implode(', ', $pemakai) . ')'));
$cek(!str_contains($baca('app/Ui/Navigation.php'), 'function activeKey'), 'S3-2 kompatibilitas Navigation::activeKey untuk sidebar lama ikut dihapus');

// S3-3 kriteria: tanpa alert()
$alert = [];
foreach (['admin', 'portal', 'app', 'assets/ui'] as $folder) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $folder, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $nama = $file->getFilename();
        if (!in_array($file->getExtension(), ['php', 'js'], true) || str_starts_with($nama, 'SimpleXLSX')) {
            continue;
        }
        $isi = (string) file_get_contents($file->getPathname());
        if ($file->getExtension() === 'php') {
            $isi = $kode($isi);
        } else {
            $isi = (string) preg_replace('#/\*.*?\*/|^\s*//.*$#ms', '', $isi);
        }
        if (preg_match('/\balert\s*\(/', $isi)) {
            $alert[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }
}
$cek($alert === [], 'S3-3 tidak ada alert() di admin, portal, app, dan aset bersama' . ($alert === [] ? '' : ' (' . implode(', ', $alert) . ')'));

// S3-4 setiap halaman memakai kerangka bersama, tanpa kerangka/pustaka lama
foreach ($halaman as $layar => $berkas) {
    $isi = $kode($baca($berkas));
    $cek(str_contains($isi, 'ah_page_open(') || str_contains($isi, 'master_header('), "S3-4 $layar $berkas membuka kerangka bersama");
    $cek(!str_contains($isi, '<!DOCTYPE') && !str_contains($isi, '<html') && !str_contains($isi, 'navbar'), "S3-4 $layar tanpa kerangka HTML/navbar sendiri");
    $cek(!preg_match('/datatables|xlsx(\.full)?\.min\.js|cdn\.jsdelivr\.net\/npm\/chart\.js|onclick=|onsubmit=|style="[^"]*(color|background)/i', $isi), "S3-4 $layar tanpa DataTables, SheetJS, Chart.js, handler inline, atau warna inline");
}
foreach (['admin/tambah_berita.php', 'admin/edit_berita.php'] as $berkas) {
    $cek(str_contains($baca($berkas), "_berita_form.php") && !str_contains($baca($berkas), '<!DOCTYPE'), "S3-4 W-2 $berkas memakai penyunting bersama di kerangka admin");
}

// S3-5 daftar berhalaman
foreach (['N-2' => 'ah_pagination(', 'N-5' => 'master_pagination(', 'K-5' => 'ah_pagination(', 'K-6' => 'ah_pagination(', 'W-1' => 'ah_pagination(', 'W-3' => 'ah_pagination(', 'W-4' => 'ah_pagination('] as $layar => $fungsi) {
    $cek(substr_count($baca($halaman[$layar]), $fungsi) >= 1, "S3-5 $layar daftar berhalaman ($fungsi)");
}
$cek(str_contains($baca('app/Psb/PsbAdmin.php'), 'PER_PAGE = 20') && str_contains($baca('app/Psb/PsbPembayaran.php'), 'PER_PAGE = 20'), 'S3-5 K-5/K-6 20 baris per halaman');
$cek(str_contains($baca('app/Notification/SettingsRepository.php'), 'function auditTrailPage'), 'S3-5 N-2 riwayat kanal berhalaman');
$cek(str_contains($baca('app/V3/KatalogService.php'), "'years'") && str_contains($baca('app/V3/KatalogService.php'), "nama_santri LIKE ?"), 'S3-5 N-5 pencarian santri dan saringan tahun (parameter terikat)');

// S3-6 kriteria: WhatsApp tidak dapat dinyalakan (AL), di layanan dan panel
$channel = $baca('app/Notification/NotificationChannel.php');
$layanan = $baca('app/Notification/NotificationAdminService.php');
$panel = $kode($baca('admin/admin_notifikasi.php'));
$cek(str_contains($channel, 'public const DITANGGUHKAN = [self::WHATSAPP]'), 'S3-6 AL WhatsApp terdaftar sebagai kanal ditangguhkan');
$cek(preg_match('/if \(\$aktif && NotificationChannel::ditangguhkan\(\$kanal\)\)\s*\{\s*throw NotificationException::conflict/', $layanan) === 1, 'S3-6 AL layanan menolak menyalakan kanal ditangguhkan (panel dan API)');
$cek(preg_match('/function kirimPesanUji[\s\S]*?NotificationChannel::ditangguhkan\(\$kanal\)[\s\S]*?conflict/', $layanan) === 1, 'S3-6 AL layanan menolak pesan uji kanal ditangguhkan');
$cek(str_contains($panel, "\$aksi === 'worker' && NotificationChannel::ditangguhkan(\$kanal)"), 'S3-6 AL panel tidak menjalankan worker kanal ditangguhkan');
$cek(str_contains($panel, 'if ($ditangguhkan):') && str_contains($panel, "!\$ditangguhkan) {\n            \$menu[] = notif_menu_item('Jalankan worker sekali'"), 'S3-6 AL kartu ditangguhkan tanpa Nyalakan/Kirim uji/worker');
$cek(str_contains($panel, "'Matikan kanal'") && str_contains($panel, "'confirm' => ['title' => 'Matikan '"), 'S3-6 N-2 mematikan kanal memakai konfirmasi');
$cek(str_contains($panel, 'data-ah-bulk=') && str_contains($panel, "coba_ulang_massal"), 'S3-6 N-2 coba ulang per baris dan massal');

// S3-7 query: parameter terikat, tanpa escape string manual
foreach (['admin/admin_data.php', 'admin/admin_pembayaran_psb.php', 'admin/admin_rekap_keuangan.php', 'admin/admin_rekap_santri.php', 'admin/admin_berita.php',
    'admin/admin_galeri.php', 'admin/admin_download.php', 'admin/proses_psb_admin.php', 'admin/proses_berita.php', 'admin/export_psb.php',
    'admin/export_pembayaran.php', 'admin/edit_berita.php', 'app/Psb/PsbAdmin.php', 'app/Psb/PsbPembayaran.php', 'app/Beranda/RingkasanAdministrasi.php'] as $berkas) {
    $isi = $kode($baca($berkas));
    // String berinterpolasi ("…$_GET…") yang memuat kata SQL.
    $rakitan = false;
    $dalam = false;
    $isiString = '';
    foreach (token_get_all($isi) as $t) {
        if ($t === '"') {
            if ($dalam && preg_match('/\b(SELECT|INSERT|UPDATE|DELETE)\b/i', $isiString) && preg_match('/\$_(GET|POST|REQUEST)/', $isiString)) {
                $rakitan = true;
            }
            $dalam = !$dalam;
            $isiString = '';
            continue;
        }
        if ($dalam) {
            $isiString .= is_array($t) ? $t[1] : $t;
        }
    }
    $cek(!str_contains($isi, 'mysqli_real_escape_string') && !preg_match('/mysqli_query\s*\(/', $isi) && !$rakitan, "S3-7 $berkas tanpa SQL rakitan dari permintaan");
}

// S3-8 keluaran di-escape: tidak ada echo nilai baris mentah
foreach ($halaman as $layar => $berkas) {
    $cek(!preg_match('/<\?php\s+echo\s+\$(d|row|b)\[/', $baca($berkas)) && !preg_match('/<\?=\s*\$(d|row|baris|berita|dipilih)\[\'[a-z_]+\'\]\s*\?>/', $baca($berkas)), "S3-8 $layar tanpa keluaran kolom basis data mentah");
}

// S3-9 keputusan AG, AH, AI
$data = $kode($baca('admin/admin_data.php'));
$cek(!preg_match('/<img[^>]*berkas_psb/', $data) && str_contains($data, 'Pratinjau gambar berkas dipasang setelah tahap perbaikan keamanan PSB'), 'S3-9 AG panel pendaftar tanpa pratinjau gambar berkas');
$cek(!preg_match('/kategori|status_terbit|draf/i', $kode($baca('admin/admin_berita.php')) . $kode($baca('admin/_berita_form.php')) . $kode($baca('admin/proses_berita.php'))), 'S3-9 AH berita tanpa status dan kategori');
$cek(!preg_match('/album/i', $kode($baca('admin/admin_galeri.php'))), 'S3-9 AI galeri tanpa album');
$cek(str_contains($baca('admin/admin_galeri.php'), 'name="foto[]"') && str_contains($baca('admin/admin_galeri.php'), ' multiple'), 'S3-9 W-3 unggah beberapa foto sekaligus');

// S3-10 komponen
$chart = $baca('app/Ui/Chart.php');
$cek(!preg_match('#https?://#', $kode($chart)) && str_contains($chart, 'conic-gradient') && str_contains($chart, '<svg'), 'S3-10 grafik dirender server tanpa pustaka luar');
$cek(substr_count($chart, "self::empty(") >= 3, 'S3-10 setiap jenis grafik punya keadaan kosong');
$css = $baca('assets/ui/alhasan.css');
$cek(str_contains($css, '--ah-chart-1') && !preg_match('#https?://(?!cdn\.jsdelivr\.net|cdnjs\.cloudflare\.com)#', $css), 'S3-10 warna grafik dari token, tanpa aset eksternal baru');
foreach (['.ah-filter', '.ah-media', '.ah-drop', '.ah-matrix', '.ah-fold', '.ah-channels', '.ah-editor', '.ah-steps'] as $kelas) {
    $cek(str_contains($css, $kelas . ' '), 'S3-10 gaya komponen ' . $kelas);
}
$js = $baca('assets/ui/alhasan.js');
$cek(str_contains($js, 'data-ah-filter') && str_contains($js, 'data-ah-drop') && str_contains($js, "form[data-ah-bulk]") && str_contains($js, 'options.match'), 'S3-10 perilaku saringan HP, zona unggah, proses massal formulir, dan ketik-untuk-konfirmasi');
$fn = $baca('app/Ui/functions.php');
$cek(str_contains($fn, 'function ah_xlsx_download') && str_contains($fn, '"\0" . (string) $cell'), 'S3-10 ekspor Excel di server menulis teks mentah (bukan rumus)');
$cek(str_contains($fn, "'match' => 'data-confirm-match'"), 'S3-10 menu ⋯ mendukung ketik nama untuk konfirmasi');

// S3-11 unggahan dibatasi
$unggah = $baca('app/Konten/Unggahan.php');
$cek(str_contains($unggah, "public const GAMBAR = ['jpg', 'jpeg', 'png', 'webp', 'gif']") && str_contains($unggah, 'getimagesize') && str_contains($unggah, 'is_uploaded_file') && !preg_match("/'php|'phtml|'phar/", $unggah), 'S3-11 jenis berkas unggahan dibatasi, gambar diperiksa isinya');
foreach (['admin/admin_galeri.php', 'admin/admin_download.php', 'admin/proses_berita.php'] as $berkas) {
    $isi = $kode($baca($berkas));
    $cek(str_contains($isi, 'Unggahan::simpan(') && !str_contains($isi, 'move_uploaded_file'), "S3-11 $berkas menyimpan unggahan lewat pemeriksa bersama");
}
$cek(!str_contains($kode($baca('admin/proses_berita.php')), "\$_POST['gbr_lama']"), 'S3-11 nama gambar lama berita diambil dari basis data');

// S3-12 PSB: alur bisnis lama tetap, hasil lewat pesan
$proses = $kode($baca('admin/proses_psb_admin.php'));
$cek(str_contains($proses, "in_array(\$status, ['Diterima', 'Ditolak', 'Cadangan'], true)") && str_contains($proses, "date('y') . (\$d['jenjang_tujuan'] === 'SMP Terpadu Al Hasan' ? '01' : '02')"), 'S3-12 K-5 status dan pola NIS migrasi tetap');
$cek(str_contains($proses, 'ah_flash_set(') && str_contains($kode($baca('admin/hapus_siswa.php')), 'ah_flash_set('), 'S3-12 K-5 hasil tindakan tampil sebagai pesan');
$cek(str_contains($data, "periksaImpor(") && str_contains($data, "'impor_simpan'") && str_contains($data, 'hash_equals('), 'S3-12 K-5 impor diperiksa dulu lalu disimpan dengan token sekali pakai');
$cek(str_contains($kode($baca('admin/admin_pembayaran_psb.php')), "audit_logger()->log('psb.pembayaran.batal_lunas'"), 'S3-12 K-6 alasan batal lunas dicatat di audit');

// S3-13 tautan Beranda dan peta menu
$cek(str_contains($baca('app/Beranda/BerandaService.php'), "'/admin/admin_notifikasi.php?tab=gagal#pengiriman-gagal'"), 'S3-13 kartu notifikasi gagal membuka tab Pengiriman gagal');
$nav = $baca('app/Ui/Navigation.php');
$cek(str_contains($nav, "self::tab('Rekap jumlah', '/admin/admin_rekap_santri.php'") && str_contains($nav, "self::tab('Ringkasan administrasi', '/admin/admin_dashboard.php'"), 'S3-13 K-8 dan N-4 tetap sebagai tab menu barunya');

echo PHP_EOL . "Ringkasan: {$lulus} lulus, {$gagal} gagal" . PHP_EOL;
exit($gagal === 0 ? 0 : 1);
