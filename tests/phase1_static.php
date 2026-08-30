<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];

$assert = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? '[lulus] ' : '[gagal] ') . $message . PHP_EOL;
    if (!$condition) {
        $failures[] = $message;
    }
};

$loginSource = (string) file_get_contents($root . '/admin/cek_login.php');
$connectionSource = (string) file_get_contents($root . '/koneksi.php');
$assert(!preg_match('/\$username\s*==|\$password\s*==/', $loginSource), 'Login tidak membandingkan username/password dengan nilai literal');
$assert(str_contains($loginSource, 'AuthService'), 'Login lama memakai service autentikasi database');
$assert(!preg_match('/\$pass\s*=\s*[\'\"][^\'\"]+[\'\"]/', $connectionSource), 'Koneksi tidak memuat password database literal');
$guardSource = (string) file_get_contents($root . '/admin/_guard.php');
$assert(str_contains($guardSource, 'Csrf::requireValid'), 'Seluruh POST pada route admin dijaga CSRF di server');

foreach (['index.php', 'berita.php', 'detail.php', 'download.php', 'galeri.php'] as $publicPage) {
    $source = (string) file_get_contents($root . '/' . $publicPage);
    $connectionPosition = strpos($source, 'koneksi.php');
    $headerPosition = strpos($source, 'header.php');
    $assert(
        $connectionPosition !== false && $headerPosition !== false && $connectionPosition < $headerPosition,
        $publicPage . ' memuat bootstrap/koneksi sebelum menghasilkan header HTML'
    );
}

// PERUBAHAN KONTRAK — paket perapihan V1-V2, keputusan pengguna 30 Agustus 2026.
// Lihat docs/perapihan-v1-v2/rencana.md dan perubahan-pengujian.md.
//
// Daftar pengecualian bertambah karena keputusan pengguna, bukan karena
// pelonggaran keamanan:
//   - modul Pengajian terpadu (koreksi ke-4) memang terbuka bagi admin DAN guru,
//     sehingga tidak boleh memakai guard khusus admin. Guardnya diperiksa
//     tersendiri di bawah;
//   - berkas berawalan `_` adalah potongan tampilan, bukan halaman. Ia menolak
//     permintaan langsung lewat penjaga AH_PARTIAL yang diperiksa di bawah.
$protectedExceptions = [
    'SimpleXLSX.php', 'SimpleXLSXGen.php', 'admin_login.php', 'cek_login.php', 'logout.php',
    'ubah_password.php', 'pertemuan_pengajian.php', 'admin_pengajian.php', 'admin_jadwal_ngaji.php',
    '_pengajian_jadwal.php', '_pengajian_pertemuan.php', '_santri_wali_field.php',
];
foreach (glob($root . '/admin/*.php') ?: [] as $file) {
    if (in_array(basename($file), $protectedExceptions, true)) {
        continue;
    }
    $source = (string) file_get_contents($file);
    $assert(str_contains($source, '_guard.php') || basename($file) === '_guard.php', basename($file) . ' memakai guard role admin');
}
// Guard modul Pengajian. Sejak koreksi ke-4, jadwal dan pertemuan berada dalam
// SATU modul `admin/admin_pengajian.php`; dua alamat lama hanya meneruskan ke
// sana. Pemeriksaan yang dulu menyasar `pertemuan_pengajian.php` dipindahkan ke
// modul itu — isinya setara, hanya lokasinya yang berpindah.
$pengajianSource = (string) file_get_contents($root . '/admin/admin_pengajian.php');
$assert(
    str_contains($pengajianSource, 'requireWebUser()') && str_contains($pengajianSource, "in_array('admin'") && str_contains($pengajianSource, "in_array('guru'"),
    'admin_pengajian.php memakai guard pengguna dan membatasi role admin/guru'
);
$assert(
    str_contains($pengajianSource, 'Csrf::requireValid'),
    'admin_pengajian.php melindungi seluruh POST dengan CSRF'
);
foreach (['admin/pertemuan_pengajian.php', 'admin/admin_jadwal_ngaji.php'] as $alamatLama) {
    $kompat = (string) file_get_contents($root . '/' . $alamatLama);
    $assert(
        str_contains($kompat, "require __DIR__ . '/admin_pengajian.php'"),
        basename($alamatLama) . ' meneruskan POST ke modul ber-guard, bukan mengalihkannya melewati validasi'
    );
}
// Potongan tampilan menolak permintaan langsung, sehingga tidak ada jalur masuk
// yang melewati guard halaman pemanggilnya.
foreach (['admin/_pengajian_jadwal.php', 'admin/_pengajian_pertemuan.php', 'admin/_santri_wali_field.php'] as $partial) {
    $kode = (string) file_get_contents($root . '/' . $partial);
    $assert(
        str_contains($kode, "!defined('AH_PARTIAL')") && str_contains($kode, 'http_response_code(404)'),
        basename($partial) . ' menolak akses langsung (potongan tampilan, bukan halaman)'
    );
}

$migration = (string) file_get_contents($root . '/database/migrations/001_phase1_security.sql');
foreach (['roles', 'user_roles', 'api_tokens', 'audit_logs', 'users_guru_unique', 'force_password_change'] as $required) {
    $assert(str_contains($migration, $required), 'Migrasi memuat ' . $required);
}

$hash = password_hash('UjiPassword123!', PASSWORD_DEFAULT);
$assert(password_verify('UjiPassword123!', $hash), 'Runtime mendukung password_hash/password_verify');

require_once $root . '/app/Http/Csrf.php';
$_SESSION = [];
$token = App\Http\Csrf::token();
$assert(strlen($token) === 64 && App\Http\Csrf::validate($token), 'Token CSRF valid dapat dibuat dan diverifikasi');
$assert(!App\Http\Csrf::validate(str_repeat('0', 64)), 'Token CSRF salah ditolak');

exit($failures === [] ? 0 : 1);
