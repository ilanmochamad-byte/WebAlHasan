<?php

declare(strict_types=1);

/**
 * Pemeriksaan statis fitur "Pesan Kredensial Akun Siap Salin"
 * (keputusan pengguna 6 September 2026).
 *
 * Tidak memerlukan basis data maupun peramban. Yang diperiksa:
 *
 *   PK-1  teks baku pesan persis sesuai keputusan produk;
 *   PK-2  pesan hanya dibuat dari akun yang benar-benar tersimpan;
 *   PK-3  nilai berasal dari pembacaan ulang server, bukan formulir mentah;
 *   PK-4  flash kredensial terstruktur dan hanya dapat dibaca satu kali;
 *   PK-5  panel meng-escape seluruh data pengguna (tanpa XSS);
 *   PK-6  isi salinan sama persis dengan yang terlihat dan berupa teks biasa;
 *   PK-7  password tidak pernah berada pada atribut HTML;
 *   PK-8  status penyalinan dapat dibaca teknologi bantu (aria-live);
 *   PK-9  tersedia jalan menyalin manual bila Clipboard API ditolak;
 *   PK-10 tidak ada email otomatis: tanpa mailto, SMTP, atau permintaan keluar;
 *   PK-11 halaman akun mengirim Cache-Control private, no-store;
 *   PK-12 kegagalan pembuatan akun tidak meninggalkan pesan kredensial;
 *   PK-13 alur reset password tidak diubah;
 *   PK-14 password sementara tidak masuk audit, log, URL, atau storage peramban;
 *   PK-15 lint sintaks seluruh berkas baru dan yang diubah.
 *
 * Jalankan:
 *   php tests/kredensial_static.php
 */

$root = dirname(__DIR__);

require_once $root . '/app/Ui/Layout.php';
require_once $root . '/app/Account/CredentialMessage.php';
require_once $root . '/app/Account/CredentialFlash.php';
require_once $root . '/app/Ui/CredentialPanel.php';

use App\Account\CredentialFlash;
use App\Account\CredentialMessage;
use App\Ui\CredentialPanel;

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
$tolak = static function (callable $aksi, string $pesan) use ($assert): void {
    try {
        $aksi();
        $assert(false, $pesan . ' (ternyata TIDAK ditolak)');
    } catch (Throwable $exception) {
        $assert(true, $pesan);
    }
};

/** Akun fiktif hasil pembacaan ulang dari server. Bukan data nyata. */
$akunUji = [
    'id' => 4321,
    'name' => 'Ustadz Fulan bin Fulan',
    'username' => 'fulan.guru',
    'email' => 'fulan@contoh.test',
];
$sandiUji = 'Ah!0f1e2d3c4b5a67';

echo '=== PK-1. Teks baku pesan ===' . PHP_EOL;

$muatan = CredentialMessage::forSavedAccount($akunUji, $sandiUji, 'guru');
$teks = CredentialMessage::text($muatan);

$baku = "Assalamu\u{2019}alaikum.\n"
    . "\n"
    . "Yth. Ustadz Fulan bin Fulan,\n"
    . "\n"
    . "Akun Anda pada Sistem Al Hasan telah dibuat.\n"
    . "\n"
    . "Alamat masuk:\n"
    . "https://alhasan.co.id/portal/\n"
    . "\n"
    . "Username: fulan.guru\n"
    . "Password sementara: " . $sandiUji . "\n"
    . "\n"
    . "Pada login pertama, Anda akan diminta membuat password baru. Setelah password baru berhasil dibuat, password sementara di atas tidak dapat digunakan kembali.\n"
    . "\n"
    . "Mohon simpan informasi akun ini dengan aman dan jangan membagikannya kepada pihak lain.\n"
    . "\n"
    . "Wassalamu\u{2019}alaikum.";

$assert($teks === $baku, 'PK-1a teks pesan sama persis dengan teks baku keputusan produk');
$assert(CredentialMessage::PORTAL_URL === 'https://alhasan.co.id/portal/', 'PK-1b alamat masuk adalah /portal/ produksi');
$assert(!str_contains($teks, '<') && !str_contains($teks, '>'), 'PK-1c teks baku tidak memuat markup');
$assert(str_contains($teks, 'wajib') === false && str_contains($teks, 'diminta membuat password baru'),
    'PK-1d instruksi ganti password pada login pertama ada di dalam pesan');

echo PHP_EOL . '=== PK-2. Hanya dari akun yang benar-benar tersimpan ===' . PHP_EOL;

$tolak(static fn () => CredentialMessage::forSavedAccount(['id' => 0, 'name' => 'A', 'username' => 'aaaa'], 'x', 'guru'),
    'PK-2a akun tanpa id ditolak');
$tolak(static fn () => CredentialMessage::forSavedAccount(['id' => 9, 'name' => '', 'username' => 'aaaa'], 'x', 'guru'),
    'PK-2b akun tanpa nama ditolak');
$tolak(static fn () => CredentialMessage::forSavedAccount(['id' => 9, 'name' => 'A', 'username' => ''], 'x', 'guru'),
    'PK-2c akun tanpa username ditolak');
$tolak(static fn () => CredentialMessage::forSavedAccount($akunUji, '', 'guru'),
    'PK-2d password kosong ditolak (tidak ada pesan palsu)');
$tolak(static fn () => CredentialMessage::forSavedAccount($akunUji, $sandiUji, 'admin'),
    'PK-2e jenis akun di luar guru/pengurus/orang tua ditolak');
$assert(CredentialMessage::KINDS === ['guru', 'pengurus', 'orang_tua'],
    'PK-2f cakupan tepat tiga jalur pembuatan akun yang didukung admin');

echo PHP_EOL . '=== PK-3. Nilai berasal dari pembacaan ulang server ===' . PHP_EOL;

// Formulir mentah mengirim spasi dan huruf besar; server menormalkan lalu
// membacanya kembali. Pesan WAJIB memakai nilai server.
$akunNormal = ['id' => 12, 'name' => 'Bu Fulanah', 'username' => 'fulanah.wali', 'email' => 'fulanah@contoh.test'];
$pesanNormal = CredentialMessage::text(CredentialMessage::forSavedAccount($akunNormal, $sandiUji, 'orang_tua'));
$assert(str_contains($pesanNormal, 'Username: fulanah.wali') && !str_contains($pesanNormal, 'FULANAH.WALI  '),
    'PK-3a pesan memakai username hasil normalisasi server');

$accountService = $source('app/Account/AccountService.php');
$perizinanService = $source('app/Account/PerizinanAccountService.php');
$assert(preg_match('/\$account = \$this->accounts->find\(\$id\);/', $accountService) === 1,
    'PK-3b createTeacher membaca ulang akun dari server setelah simpan');
$assert(str_contains($accountService, "'account' => \$account"),
    'PK-3c createTeacher mengembalikan baris akun hasil pembacaan ulang');
$assert(preg_match('/\$account = \$this->accounts->find\(\$id\);/', $perizinanService) === 1,
    'PK-3d create() pengurus/orang tua membaca ulang akun dari server setelah simpan');
$assert(str_contains($perizinanService, "'account' => \$account"),
    'PK-3e create() mengembalikan baris akun hasil pembacaan ulang');

echo PHP_EOL . '=== PK-4. Flash kredensial satu kali ===' . PHP_EOL;

$_SESSION = [];
$assert(CredentialFlash::take() === null, 'PK-4a sesi bersih tidak menghasilkan pesan');
CredentialFlash::set($muatan);
$assert(CredentialFlash::has(), 'PK-4b muatan tersimpan setelah pembuatan akun berhasil');
$assert(is_array($_SESSION['_ah_kredensial_akun'] ?? null),
    'PK-4c muatan sesi berupa DATA terstruktur, bukan potongan HTML');
$diambil = CredentialFlash::take();
$assert($diambil === $muatan, 'PK-4d pembacaan pertama mengembalikan muatan utuh');
$assert(!isset($_SESSION['_ah_kredensial_akun']),
    'PK-4e muatan langsung dihapus dari sesi ketika dibaca');
$assert(CredentialFlash::take() === null,
    'PK-4f pembacaan kedua (muat ulang / tombol kembali) tidak menghasilkan apa pun');
CredentialFlash::set($muatan);
CredentialFlash::forget();
$assert(CredentialFlash::take() === null, 'PK-4g forget() membersihkan muatan pada jalur kegagalan');
$_SESSION = [];

echo PHP_EOL . '=== PK-5 s.d. PK-9. Panel kredensial ===' . PHP_EOL;

$jahat = [
    'id' => 99,
    'name' => 'Fulan "<script>alert(1)</script>" & \'Co\'',
    'username' => 'x<img src=x onerror=alert(2)>',
    'email' => '"><svg onload=alert(3)>@contoh.test',
];
$muatanJahat = CredentialMessage::forSavedAccount($jahat, $sandiUji, 'pengurus');
$panel = CredentialPanel::render($muatanJahat);

$assert(!str_contains($panel, '<script>alert(1)</script>'), 'PK-5a nama bermuatan skrip tidak lolos mentah');
$assert(!str_contains($panel, '<img src=x'), 'PK-5b username bermuatan tag tidak lolos mentah');
$assert(!str_contains($panel, '<svg onload='), 'PK-5c email bermuatan tag tidak lolos mentah');
$assert(str_contains($panel, '&lt;script&gt;alert(1)&lt;/script&gt;'), 'PK-5d nama tampil sebagai teks yang di-escape');
$assert(substr_count($panel, '<script>') === 1 && substr_count($panel, '</script>') === 1,
    'PK-5e hanya ada satu blok skrip, yaitu skrip tombol salin milik panel');

// Isi yang disalin = textContent elemen <pre>.
$assert(preg_match('#<pre class="ah-kredensial__teks[^"]*" id="ah-kredensial-teks"[^>]*>(.*?)</pre>#s', $panel, $cocok) === 1,
    'PK-6a panel memuat kotak pesan siap salin');
$terlihat = html_entity_decode((string) ($cocok[1] ?? ''), ENT_QUOTES, 'UTF-8');
$assert($terlihat === CredentialMessage::text($muatanJahat),
    'PK-6b isi kotak pesan sama persis dengan teks pesan (yang akan disalin tombol)');
$assert(!str_contains($terlihat, '&lt;') && !str_contains($terlihat, '&amp;'),
    'PK-6c hasil salinan berupa teks biasa, tanpa entitas atau tag HTML');
$assert(str_contains($panel, "teks.textContent") && !str_contains($panel, 'innerHTML'),
    'PK-6d tombol menyalin textContent, bukan markup');

// Password tidak boleh berada pada atribut HTML mana pun.
preg_match_all('/\s[a-zA-Z-]+="([^"]*)"/', $panel, $atribut);
$sandiPadaAtribut = false;
foreach ($atribut[1] as $nilai) {
    if (str_contains($nilai, $sandiUji)) {
        $sandiPadaAtribut = true;
    }
}
$assert(!$sandiPadaAtribut, 'PK-7a password sementara tidak berada pada atribut HTML mana pun');
$assert(!preg_match('/data-[a-z-]*="[^"]*' . preg_quote($sandiUji, '/') . '/', $panel),
    'PK-7b tidak ada atribut data-* yang membawa password');
$assert(!str_contains($panel, 'value="' . $sandiUji . '"'),
    'PK-7c password tidak ditaruh pada nilai input');
$assert(substr_count($panel, $sandiUji) === 2,
    'PK-7d password muncul tepat dua kali sebagai teks: ringkasan akun dan isi pesan');

$assert(str_contains($panel, 'id="ah-kredensial-status" role="status" aria-live="polite"'),
    'PK-8a status penyalinan berada pada area aria-live yang sopan');
$assert(str_contains($panel, "umumkan('Pesan berhasil disalin')"),
    'PK-8b keberhasilan menyalin diumumkan dengan status "Pesan berhasil disalin"');
$assert(str_contains($panel, 'status.textContent'),
    'PK-8c status ditulis sebagai teks, bukan markup');

$assert(str_contains($panel, 'navigator.clipboard') && str_contains($panel, 'sorotManual'),
    'PK-9a tersedia jalur Clipboard API sekaligus jalur sorot manual');
$assert(str_contains($panel, 'Ctrl+C'),
    'PK-9b petunjuk penyalinan manual disebutkan kepada pengguna');
$assert(str_contains($panel, 'Tanpa tombol salin, pesan tetap dapat disalin manual'),
    'PK-9c petunjuk manual tetap ada meski JavaScript mati');
$assert(str_contains($panel, 'type="button"') && !preg_match('/<form[^>]*>/', $panel),
    'PK-9d tombol salin bukan tombol formulir: kegagalan menyalin tidak membuat akun/password baru');
$assert(str_contains($panel, 'Bersihkan papan klip'),
    'PK-9e peringatan membersihkan papan klip setelah email terkirim ditampilkan');

// Isi wajib panel.
foreach ([
    'Nama pengguna' => 'nama pengguna',
    'Alamat email tujuan' => 'alamat email tujuan',
    'Username' => 'username',
    'Password sementara' => 'password sementara',
    'Alamat masuk' => 'alamat masuk portal',
    'wajib mengganti password pada login pertama' => 'instruksi wajib ganti password',
    'tidak boleh dibagikan kepada pihak lain' => 'peringatan tidak boleh dibagikan',
    'Salin pesan' => 'tombol Salin pesan',
    'https://alhasan.co.id/portal/' => 'URL portal',
] as $penggalan => $label) {
    $assert(str_contains($panel, $penggalan), 'PK-5f panel memuat ' . $label);
}

$panelTanpaEmail = CredentialPanel::render(
    CredentialMessage::forSavedAccount(['id' => 3, 'name' => 'Fulan', 'username' => 'fulan.x', 'email' => ''], $sandiUji, 'guru')
);
$assert(str_contains($panelTanpaEmail, 'tidak memiliki email'),
    'PK-5g akun tanpa email dijelaskan, email tetap opsional seperti sebelumnya');

echo PHP_EOL . '=== PK-10. Tidak ada pengiriman email otomatis ===' . PHP_EOL;

$panelSemua = $panel . $panelTanpaEmail;
foreach (['mailto:', 'smtp', 'SMTP', 'sendmail', 'PHPMailer', 'fetch(', 'XMLHttpRequest', 'navigator.sendBeacon'] as $terlarang) {
    $assert(!str_contains($panelSemua, $terlarang), 'PK-10a panel tidak memuat "' . $terlarang . '"');
}
$panelSumber = $source('app/Ui/CredentialPanel.php');
$pesanSumber = $source('app/Account/CredentialMessage.php');
foreach ([$panelSumber, $pesanSumber] as $berkas) {
    $assert(!preg_match('/\bmail\s*\(/', $berkas), 'PK-10b berkas fitur tidak memanggil mail()');
}

echo PHP_EOL . '=== PK-11 s.d. PK-13. Halaman Akun & Hak Akses ===' . PHP_EOL;

$halaman = $source('admin/admin_akun.php');

$assert(str_contains($panelSumber, "header('Cache-Control: private, no-store, max-age=0')"),
    'PK-11a tersedia header Cache-Control private, no-store');
$assert(str_contains($halaman, 'CredentialPanel::noStore()'),
    'PK-11b halaman akun mengirim header no-store saat panel akan tampil');
$posisiAmbil = strpos($halaman, 'CredentialFlash::take()');
$posisiHeader = strpos($halaman, 'master_header(');
$assert($posisiAmbil !== false && $posisiHeader !== false && $posisiAmbil < $posisiHeader,
    'PK-11c muatan diambil dan header dikirim SEBELUM keluaran halaman dimulai');

$assert(preg_match('#catch \(Throwable \$exception\) \{\s*(?://[^\n]*\n\s*)*CredentialFlash::forget\(\);#', $halaman) === 1,
    'PK-12a jalur kegagalan langsung membuang pesan kredensial');
$assert(str_contains($halaman, 'CredentialFlash::forget();'),
    'PK-12b sisa pesan permintaan sebelumnya dibersihkan sebelum aksi baru diproses');
$assert(substr_count($halaman, 'CredentialFlash::forget();') >= 2,
    'PK-12c pembersihan dilakukan pada awal POST dan pada jalur gagal');
$assert(str_contains($halaman, "header('Location: ' . app_url('/admin/' . \$kembali));"),
    'PK-12d hasil POST tetap memakai pola redirect: retry tidak mengirim ulang formulir');
$assert(!preg_match('/app_url\([^)]*\$(temporaryPassword|kredensial)/', $halaman),
    'PK-12e password dan muatan pesan tidak pernah masuk URL atau query string');

$assert(str_contains($halaman, "case 'reset_password':")
    && str_contains($halaman, '$temporaryPassword = $service->resetPassword($userId, $aktorId);'),
    'PK-13a aksi reset password tetap seperti semula');
$assert(str_contains($halaman, 'Password sementara (ditampilkan sekali)'),
    'PK-13b tampilan hasil reset password tidak diubah');
$assert(!preg_match("/case 'reset_password':.*?CredentialMessage::forSavedAccount/s", $halaman),
    'PK-13c reset password tidak ikut membuat pesan kredensial baru');

echo PHP_EOL . '=== PK-14. Pengamanan password sementara ===' . PHP_EOL;

$assert(str_contains($accountService, 'password_hash($temporaryPassword, PASSWORD_DEFAULT)')
    && !preg_match('/createTeacher\(\$data, \$temporaryPassword/', $accountService),
    'PK-14a jalur guru hanya menyimpan hash password');
$assert(str_contains($perizinanService, 'password_hash($temporaryPassword, PASSWORD_DEFAULT)'),
    'PK-14b jalur pengurus/orang tua hanya menyimpan hash password');

foreach ([
    'app/Account/AccountService.php' => $accountService,
    'app/Account/PerizinanAccountService.php' => $perizinanService,
    'app/Account/CredentialMessage.php' => $pesanSumber,
    'app/Account/CredentialFlash.php' => $source('app/Account/CredentialFlash.php'),
    'app/Ui/CredentialPanel.php' => $panelSumber,
    'admin/admin_akun.php' => $halaman,
] as $nama => $isi) {
    $isi = $tanpaKomentar($isi);
    $assert(!preg_match('/error_log\([^)]*(\$temporaryPassword|\$sandi|password)/i', $isi),
        'PK-14c ' . $nama . ' tidak menulis password ke log aplikasi');
    $assert(!preg_match('/console\.(log|info|warn|debug)/', $isi),
        'PK-14d ' . $nama . ' tidak menulis apa pun ke konsol peramban');
    $assert(!preg_match('/localStorage|sessionStorage|document\.cookie/', $isi),
        'PK-14e ' . $nama . ' tidak menyentuh storage peramban');
}

$assert(!preg_match("/auditRequired\([^;]*temporary_password/s", $accountService . $perizinanService),
    'PK-14f audit pembuatan akun tidak memuat password');
$assert(str_contains($accountService, "'roles' => ['guru']") && str_contains($perizinanService, "'role' => \$kind"),
    'PK-14g audit tetap mencatat identitas akun dan role, bukan password');

$assert(str_contains($panelSumber, 'ah-no-print'),
    'PK-14h panel kredensial tidak ikut tercetak');
$css = $source('assets/ui/alhasan.css');
$assert(str_contains($css, '.ah-kredensial__teks') && str_contains($css, '.ah-kredensial__status'),
    'PK-14i gaya panel tersedia pada lapisan desain bersama');

echo PHP_EOL . '=== PK-15. Lint sintaks ===' . PHP_EOL;

foreach ([
    'app/Account/CredentialMessage.php',
    'app/Account/CredentialFlash.php',
    'app/Ui/CredentialPanel.php',
    'app/Account/AccountService.php',
    'app/Account/PerizinanAccountService.php',
    'admin/admin_akun.php',
    'tests/kredensial_static.php',
] as $berkas) {
    $keluaran = [];
    $kode = 0;
    exec('php -l ' . escapeshellarg($root . '/' . $berkas) . ' 2>&1', $keluaran, $kode);
    $assert($kode === 0, 'PK-15 sintaks ' . $berkas . ' valid');
}

echo PHP_EOL;
if ($failures === []) {
    echo 'SELURUH PEMERIKSAAN STATIS PESAN KREDENSIAL LULUS.' . PHP_EOL;
    exit(0);
}
echo 'GAGAL: ' . count($failures) . ' pemeriksaan.' . PHP_EOL;
foreach ($failures as $failure) {
    echo ' - ' . $failure . PHP_EOL;
}
exit(1);
