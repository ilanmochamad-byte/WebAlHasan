<?php

declare(strict_types=1);

/**
 * Pemeriksaan statis redesain website Tahap 1 — fondasi kerangka & komponen.
 *
 * Tidak memerlukan basis data maupun server. Memeriksa:
 *   S-1 kamus status bersama dan kesetaraan lencana izin lama;
 *   S-2 bilah bawah HP (Beranda dulu, paling banyak 5, satu butir per modul);
 *   S-3 menu ⋯ (details, bahaya paling bawah, POST + CSRF, konfirmasi);
 *   S-4 panel samping, bilah pilihan, ringkasan galat;
 *   S-5 kerangka Layout: tiga bentuk navigasi, ID lama dipertahankan;
 *   S-6 CSS tiga ukuran dan cetak; S-7 JavaScript komponen;
 *   S-8 galeri uji tidak terjangkau dari jalur produksi; S-9 lint.
 *
 * Jalankan:
 *   php tests/redesain_web_tahap1_static.php
 */

$root = dirname(__DIR__);
define('APP_ROOT', $root);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'App\\')) {
        $file = $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});
function app_url(string $path = ''): string
{
    return '/' . ltrim($path, '/');
}
require_once $root . '/app/Ui/functions.php';

use App\Ui\Navigation;
use App\Ui\Status;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? '[lulus] ' : '[gagal] ') . $message . PHP_EOL;
    if (!$condition) {
        $failures[] = $message;
    }
};
$source = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$_SESSION = [];

echo '=== S-1. Kamus status ===' . PHP_EOL;
$harapan = [
    'ok' => ['Selesai', 'Disetujui', 'Lunas', 'Terbit', 'Diterbitkan', 'Terverifikasi', 'Diterima'],
    'info' => ['Diajukan', 'Baru', 'Diserahkan'],
    'warn' => ['Menunggu', 'Perlu Perbaikan', 'Perlu Penetapan Admin', 'Belum Lunas', 'Menunggu keputusan Anda', 'Dikembalikan'],
    'danger' => ['Ditolak', 'Ditarik', 'Gagal'],
    'muted' => ['Draf', 'Arsip', 'Dibatalkan'],
];
foreach ($harapan as $nada => $daftar) {
    $salah = array_filter($daftar, static fn (string $s): bool => Status::tone($s) !== $nada);
    $assert($salah === [], "Status {$nada}: " . implode(', ', $daftar) . ($salah ? ' — salah: ' . implode(', ', $salah) : ''));
}
$assert(Status::tone('Status tak dikenal') === 'info' && Status::tone('x', 'muted') === 'muted', 'Status tak dikenal memakai cadangan yang dipilih pemanggil');
$lama = static fn (string $status): string => ah_badge($status, match ($status) {
    'Disetujui' => 'ok', 'Ditolak' => 'danger', 'Dibatalkan' => 'muted', 'Perlu Penetapan Admin' => 'warn', default => 'info',
});
$sama = true;
foreach (['Diajukan', 'Perlu Penetapan Admin', 'Disetujui', 'Ditolak', 'Dibatalkan'] as $status) {
    $sama = $sama && ah_status($status) === $lama($status);
}
$assert($sama, 'Lencana kelima status izin identik dengan pemetaan portal_status_badge lama');
$assert(str_contains($source('portal/_ui.php'), 'return ah_status($status);'), 'portal_status_badge memakai kamus bersama');
$assert(ah_status('<b>') === '<span class="ah-badge ah-badge--info">&lt;b&gt;</span>', 'Label status di-escape');

echo PHP_EOL . '=== S-2. Bilah bawah HP ===' . PHP_EOL;
$item = static fn (string $key): array => ['key' => $key, 'label' => $key, 'url' => '/' . $key, 'icon' => 'fa-x', 'badge' => null, 'badge_label' => null];
$grup = [
    ['label' => 'Utama', 'items' => [$item('beranda'), $item('admin.dashboard')]],
    ['label' => 'Nilai', 'items' => [$item('v4.nilai'), $item('v4.sikap'), $item('v4.rapor')]],
    ['label' => 'Pembinaan', 'items' => [$item('v3.pelanggaran'), $item('v3.publikasi')]],
    ['label' => 'Izin', 'items' => [$item('izin.ringkasan'), $item('izin.daftar')]],
    ['label' => 'Master', 'items' => [$item('master.santri'), $item('master.guru')]],
];
$bar = Navigation::bottomBar($grup, 'izin.daftar');
$assert(count($bar) === 5 && $bar[0]['key'] === 'beranda', 'Paling banyak 5 butir dengan Beranda pertama');
$assert(array_column($bar, 'short') === ['Beranda', 'Perizinan', 'Nilai', 'Sikap', 'Pembinaan'], 'Urutan prioritas dan satu butir per modul (Pembinaan tidak ganda): ' . implode(', ', array_column($bar, 'short')));
$assert($bar[1]['current'] === true && $bar[0]['current'] === false, 'Sub-halaman modul (izin.daftar) menyorot butir Perizinan');
$assert(Navigation::bottomBar([['label' => 'Utama', 'items' => [$item('beranda')]]], 'beranda')[0]['current'] === true, 'Akun dengan satu menu tetap mendapat Beranda');
$assert(Navigation::find($grup, 'master.guru')['url'] === '/master.guru' && Navigation::find($grup, 'tidak.ada') === null, 'Navigation::find mencari butir menurut kunci');

echo PHP_EOL . '=== S-3. Menu ⋯ ===' . PHP_EOL;
$menu = ah_menu([
    ['label' => 'Hapus', 'action' => '/x.php', 'fields' => ['id' => 7], 'danger' => true,
     'confirm' => ['title' => 'Hapus "A"?', 'text' => 'Tidak dapat dibatalkan.', 'ok' => 'Hapus', 'reason' => 'alasan', 'reason_label' => 'Alasan']],
    ['label' => 'Ubah', 'url' => '/x.php?action=edit&id=7'],
], 'Tindakan untuk A');
$assert(str_starts_with($menu, '<details class="ah-menu ah-menu--row">') && str_contains($menu, '<summary class="ah-menu__btn">'), 'Menu memakai <details>/<summary> (jalan tanpa JavaScript)');
$assert(strpos($menu, '>Ubah<') < strpos($menu, '<hr class="ah-menu__sep">') && strpos($menu, '<hr class="ah-menu__sep">') < strpos($menu, '>Hapus<'), 'Tindakan berbahaya dipisah dan diletakkan paling bawah');
$assert(str_contains($menu, '<form method="post" action="/x.php"') && str_contains($menu, 'name="_csrf"') && str_contains($menu, 'name="id" value="7"'), 'Tindakan bermutasi berupa formulir POST dengan CSRF');
$assert(str_contains($menu, 'data-confirm-title="Hapus &quot;A&quot;?"') && str_contains($menu, 'data-confirm-tone="danger"') && str_contains($menu, 'data-confirm-reason="alasan"'), 'Konfirmasi: judul di-escape, nada bahaya, alasan wajib');
$assert(str_contains($menu, 'href="/x.php?action=edit&amp;id=7"') && str_contains($menu, 'ah-visually-hidden">Tindakan untuk A<'), 'Tautan di-escape dan tombol ⋯ punya nama yang terbaca');
$assert(!str_contains(ah_menu([['label' => 'Ubah', 'url' => '#']]), 'ah-menu__sep'), 'Tanpa tindakan bahaya tidak ada pemisah');

echo PHP_EOL . '=== S-4. Panel samping, pilihan massal, galat ===' . PHP_EOL;
ob_start();
ah_panel_open(['title' => 'Tambah guru', 'close_url' => '/admin/admin_guru.php?q=a&b=1']);
echo 'ISI';
ah_panel_close();
$panel = (string) ob_get_clean();
$assert(str_contains($panel, 'data-ah-close="/admin/admin_guru.php?q=a&amp;b=1"') && str_contains($panel, 'href="/admin/admin_guru.php?q=a&amp;b=1"'), 'Panel menutup ke alamat daftar lewat tautan biasa (tanpa JS) dan Esc (JS)');
$assert(str_contains($panel, 'aria-labelledby="ah-panel-title"') && str_ends_with($panel, 'ISI</div></section>'), 'Panel berlabel judul dan membungkus isi');
$assert(str_contains(ah_selection_bar('<b>X</b>'), 'class="ah-selbar" hidden') && str_contains(ah_selection_bar(''), 'data-ah-select-clear'), 'Bilah pilihan tersembunyi sampai ada yang dipilih dan dapat dibatalkan');
$assert(ah_error_summary('_uji') === '', 'Tanpa galat tidak ada ringkasan');
$_SESSION['_uji_errors'] = ['nama' => 'Nama wajib.', 'hp' => 'HP salah.'];
$assert(str_contains(ah_error_summary('_uji'), 'Perbaiki 2 isian bertanda merah.') && str_contains(ah_error_summary('_uji'), 'role="alert"'), 'Ringkasan galat menyebut jumlah isian');

echo PHP_EOL . '=== S-5. Kerangka Layout ===' . PHP_EOL;
$layout = $source('app/Ui/Layout.php');
foreach (['class="ah-rail"' => 'rel ikon tablet', 'class="ah-bottombar"' => 'bilah bawah HP', 'id="ah-toasts"' => 'wadah toast', 'ah-drawer-head' => 'kepala laci (nama & peran)', 'ah-drawer-foot' => 'Keluar di laci', 'ah-nav-group__btn' => 'grup sidebar dapat dilipat', 'ah-topbar__title' => 'judul halaman di topbar HP', 'ah-topbar__bell' => 'lonceng notifikasi', 'ah-profile' => 'menu profil'] as $jarum => $arti) {
    $assert(str_contains($layout, $jarum), "Layout memuat {$arti}");
}
$assert(str_contains($layout, '<button class="ah-topbar__btn ah-topbar__toggle" type="button" id="ah-nav-toggle"') && str_contains($layout, 'id="ah-sidebar"') && str_contains($layout, 'id="ah-konten"'), 'ID lama (#ah-nav-toggle tombol, #ah-sidebar, #ah-konten) dipertahankan');
$assert(str_contains($layout, 'class="ah-nojs"') && str_contains($layout, "replace('ah-nojs', 'ah-js')"), 'Penanda tanpa-JS/JS untuk panel samping');
$assert(str_contains($layout, "self::asset('/assets/ui/alhasan.js')") && str_contains($layout, 'window.ALHASAN_CSRF'), 'Perilaku dimuat dari alhasan.js setelah token CSRF disetel');
$assert(!str_contains($layout, 'Kemampuan aktif'), 'Daftar "Kemampuan aktif" di atas sidebar dihapus (pindah ke menu profil/laci)');
$assert(str_contains($layout, 'data-ah-toast'), 'Pesan hasil tindakan ditandai untuk toast');

echo PHP_EOL . '=== S-6. CSS ===' . PHP_EOL;
$css = $source('assets/ui/alhasan.css');
$assert(str_contains($css, '--ah-sidebar-w: 240px') && str_contains($css, '--ah-rail-w: 46px') && str_contains($css, '--ah-panel-w: 420px'), 'Ukuran cetak biru: sidebar 240px, rel 46px, panel 420px');
$assert(str_contains($css, '@media (min-width: 576px) and (max-width: 991.98px)') && str_contains($css, '@media (max-width: 575.98px)') && str_contains($css, '@media (min-width: 1400px)'), 'Batas ukuran Bootstrap: <576, 576–991, ≥992, ≥1400');
$assert(str_contains($css, 'max-width: calc(1320px + 3.5rem)'), 'Desktop lebar dibatasi 1320px');
$assert(preg_match('/@media print \{\s*\.ah-topbar,\s*\.ah-sidebar,\s*\.ah-rail,\s*\.ah-bottombar,\s*\.ah-toasts,/', $css) === 1, 'Cetak membuang rel, bilah bawah, dan toast');
$assert(str_contains($css, 'min-height: var(--ah-touch)') && str_contains($css, '--ah-touch: 44px'), 'Sasaran sentuh 44px');
$assert(!preg_match('#https?://#', $css), 'Tanpa aset eksternal baru di CSS');

echo PHP_EOL . '=== S-7. JavaScript ===' . PHP_EOL;
$js = $source('assets/ui/alhasan.js');
foreach (['AlHasan.toast', 'AlHasan.confirm', 'AlHasan.bulk', 'AlHasan.post', 'AlHasan.selected', "'Menyimpan…'", 'pageshow', 'showModal'] as $jarum) {
    $assert(str_contains($js, $jarum), "alhasan.js memuat {$jarum}");
}
$assert(substr_count($js, 'window.confirm(') === 1 && str_contains($js, "typeof HTMLDialogElement === 'undefined'"), 'confirm() bawaan hanya cadangan peramban tanpa <dialog>');
$assert(str_contains($js, 'return next(index + 1);') && !str_contains($js, 'Promise.all'), 'Proses massal berurutan satu per satu (tanpa Promise.all)');
$assert(str_contains($js, "'X-CSRF-Token'") && str_contains($js, "body.append('_csrf'"), 'POST dari halaman membawa CSRF');
exec('node --check ' . escapeshellarg($root . '/assets/ui/alhasan.js') . ' 2>&1', $keluaran, $kode);
$assert($kode === 0, 'Sintaks alhasan.js valid (node --check)' . ($kode ? ': ' . implode(' ', $keluaran) : ''));

echo PHP_EOL . '=== S-8. Galeri uji ===' . PHP_EOL;
$produksi = array_merge(glob($root . '/admin/*.php') ?: [], glob($root . '/portal/*.php') ?: [], glob($root . '/app/*/*.php') ?: [], glob($root . '/*.php') ?: []);
$bocor = array_filter($produksi, static fn (string $f): bool => str_contains((string) file_get_contents($f), '__uji'));
$assert($bocor === [], 'Alamat /__uji/ tidak dirujuk kode produksi');
$assert(str_contains($source('tests/fixtures/redesain_web_komponen.php'), "requireWebRole('admin')") && str_contains($source('tests/fixtures/redesain_web_komponen.php'), 'Csrf::requireValid'), 'Galeri uji tetap mewajibkan admin dan CSRF');
$assert(str_contains($source('.htaccess') . $source('tests/v4_phase1_router.php'), 'tests'), 'Folder tests ditolak oleh router/konfigurasi produksi');

echo PHP_EOL . '=== S-9. Lint ===' . PHP_EOL;
foreach (['app/Ui/Layout.php', 'app/Ui/Navigation.php', 'app/Ui/Status.php', 'app/Ui/functions.php', 'portal/_ui.php', 'portal/notifikasi.php', 'tests/fixtures/redesain_web_komponen.php', 'tests/redesain_web_tahap1_router.php'] as $berkas) {
    exec('php -l ' . escapeshellarg($root . '/' . $berkas) . ' 2>&1', $hasil, $kode);
    $assert($kode === 0, 'php -l ' . $berkas);
}

echo PHP_EOL . (count($failures) === 0 ? 'Semua pemeriksaan lulus.' : count($failures) . ' pemeriksaan gagal.') . PHP_EOL;
exit($failures === [] ? 0 : 1);
