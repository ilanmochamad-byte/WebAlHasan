<?php

declare(strict_types=1);

/**
 * Uji statis workstream keamanan PSB (tanpa basis data dan server).
 *
 *   php tests/keamanan_psb_static.php
 *
 * S1  berkas PSB publik tanpa query berinterpolasi, tanpa session_start mentah
 * S2  setiap formulir POST PSB membawa token CSRF; penangan memeriksanya
 * S3  keluaran data pendaftar di-escape
 * S4  berkas: .htaccess akar + berkas_psb/.htaccess, tautan admin berpenjaga
 * S5  katalog biaya identik dengan biaya_psb.php baseline (semua kombinasi)
 * S6  batas tetap (2 MB, jenis, pembatasan percobaan) dan kelas baru ter-lint
 */

$root = dirname(__DIR__);
$baca = static fn (string $f): string => (string) file_get_contents($root . '/' . $f);
$lulus = 0;
$gagal = [];
$cek = static function (bool $ok, string $pesan) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    $ok ? $lulus++ : $gagal[] = $pesan;
};

$publik = ['psb.php', 'proses_psb.php', 'login_pendaftar.php', 'auth_pendaftar.php', 'logout_pendaftar.php', 'portal_pendaftar.php',
    'proses_upload_berkas.php', 'biaya_psb.php', 'cetak_biaya_psb.php', 'cetak_bukti.php', 'lihat_berkas_psb.php'];

// S1
foreach ($publik as $f) {
    $s = $baca($f);
    $cek(!str_contains($s, 'mysqli_query(') && !str_contains($s, 'mysqli_real_escape_string'), 'S1 ' . $f . ' tanpa query berinterpolasi');
    $cek(!preg_match('/^\s*session_start\(\)/m', $s), 'S1 ' . $f . ' memakai sesi aplikasi (bootstrap), bukan session_start mentah');
    $cek(!preg_match('/\$_(GET|POST|REQUEST)\[[^\]]+\]\s*;?\s*\?>/', $s) && !preg_match('/echo\s+\$_(GET|POST|REQUEST)/', $s), 'S1 ' . $f . ' tidak menampilkan parameter mentah');
}

// S2
foreach (['psb.php' => 1, 'login_pendaftar.php' => 1, 'logout_pendaftar.php' => 1, 'portal_pendaftar.php' => 3, 'biaya_psb.php' => 1] as $f => $n) {
    $s = $baca($f);
    $cek(substr_count($s, 'Csrf::input()') >= $n && substr_count(strtolower($s), 'method="post"') <= substr_count($s, 'Csrf::input()'), 'S2 formulir POST ' . $f . ' membawa token CSRF');
}
foreach (['proses_psb.php', 'auth_pendaftar.php', 'logout_pendaftar.php', 'portal_pendaftar.php', 'proses_upload_berkas.php', 'biaya_psb.php'] as $f) {
    $cek(str_contains($baca($f), 'Csrf::validate('), 'S2 ' . $f . ' memeriksa token CSRF');
}
$cek(str_contains($baca('lihat_berkas_psb.php'), "['GET', 'HEAD']"), 'S2 jalur berkas hanya GET/HEAD');

// S3
foreach (['portal_pendaftar.php', 'biaya_psb.php', 'cetak_bukti.php', 'cetak_biaya_psb.php'] as $f) {
    $cek(!preg_match('/echo\s+\$d\[/', $baca($f)), 'S3 ' . $f . ' tidak mencetak kolom pendaftar tanpa escape');
}
$cek(str_contains($baca('cetak_biaya_psb.php'), "ah_e(\$item)"), 'S3 nama komponen kwitansi di-escape');

// S4
$ht = $baca('.htaccess');
$cek(str_contains($ht, 'RewriteRule ^berkas_psb(?:/|$) - [F,L,NC]') && str_contains($ht, 'RewriteRule ^(?:app|bin|database|docs|storage|tests|vendor)(?:/|$) - [F,L,NC]'), 'S4 .htaccess akar menolak berkas_psb/ dan storage/');
$cek(str_contains($baca('berkas_psb/.htaccess'), 'Require all denied'), 'S4 berkas_psb/.htaccess menolak semua');
$cek(str_contains($baca('.gitignore'), "berkas_psb/*\n!berkas_psb/.htaccess") && trim($baca('storage/private/psb/.gitignore')) === "*\n!.gitignore", 'S4 berkas baru tidak ikut repositori');
$data = $baca('admin/admin_data.php');
$cek(!str_contains($data, "'/berkas_psb/") && str_contains($data, "lihat_berkas_psb.php?"), 'S4 panel admin menautkan jalur berpenjaga');
$cek(str_contains($data, 'Pratinjau gambar berkas dipasang setelah tahap perbaikan keamanan PSB') && !preg_match('/<img[^>]*berkas_psb/', $data), 'S4 pratinjau (AG) tetap belum dipasang');
$jalur = $baca('lihat_berkas_psb.php');
$cek(str_contains($jalur, '$row[$jenis]') && !preg_match('/->baca\([^)]*\$_GET/', $jalur), 'S4 nama berkas dari baris basis data, bukan parameter');
$cek(str_contains($baca('app/Psb/BerkasPsb.php'), "/storage/private/psb'") && str_contains($baca('app/Psb/BerkasPsb.php'), 'random_bytes(16)'), 'S4 unggahan baru privat bernama acak');

// S5 paritas katalog biaya terhadap baseline
require_once $root . '/app/Psb/PsbException.php';
require_once $root . '/app/Psb/BiayaPsb.php';
$baseline = (string) shell_exec('git -C ' . escapeshellarg($root) . ' show a116e0f:biaya_psb.php 2>/dev/null');
$mulai = strpos($baseline, '$is_alumni = (stripos(');
$akhir = strpos($baseline, '$q_cek = mysqli_query(');
if ($mulai === false || $akhir === false) {
    $cek(false, 'S5 potongan katalog baseline a116e0f ditemukan');
} else {
    $potongan = substr($baseline, $mulai, $akhir - $mulai);
    $katalogLama = static function (array $d) use ($potongan): array {
        eval($potongan);

        return ['kategori' => $kategori_biaya, 'syahriyyah' => $s_default, 'infaq' => $i_default, 'psas' => $psas_default,
            'pramuka' => $pramuka_default, 'wajib' => $wajib_items];
    };
    $kombinasi = 0;
    $sama = true;
    foreach (['SMP Terpadu Al Hasan', 'SMK Terpadu Al Hasan', 'RA Terpadu Al Hasan', 'MAN 2 Ciamis', 'SMKN 1 Ciamis', '-'] as $unit) {
        foreach (['L', 'P'] as $jk) {
            foreach (['SD Negeri 1', 'SMP Terpadu Al Hasan', 'smp terpadu al hasan ciamis'] as $asal) {
                $d = ['jenjang_tujuan' => $unit, 'jenis_kelamin' => $jk, 'sekolah_asal' => $asal];
                $baru = App\Psb\BiayaPsb::katalog($d);
                unset($baru['seragam']);
                $kombinasi++;
                if ($katalogLama($d) !== $baru) {
                    $sama = false;
                    echo '  beda: ' . $unit . ' ' . $jk . ' ' . $asal . PHP_EOL;
                }
            }
        }
    }
    $cek($sama, 'S5 katalog biaya identik dengan baseline untuk ' . $kombinasi . ' kombinasi unit × jenis kelamin × asal sekolah');
}
$biaya = $baca('app/Psb/BiayaPsb.php');
$cek(str_contains($biaya, "'Tsanawi Non-SMK (Baru)'") && !str_contains($biaya, 'MI Daar'), 'S5 biaya MI/RA tidak ditetapkan (tetap kategori lama)');

// S6
$cek(str_contains($baca('app/Psb/BerkasPsb.php'), 'MAKS_BYTE = 2 * 1024 * 1024') && str_contains($baca('app/Psb/BerkasPsb.php'), "'image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'"), 'S6 batas 2 MB dan jenis JPG/PNG/PDF');
$sesi = $baca('app/Psb/PendaftarSesi.php');
$cek(str_contains($sesi, 'session_regenerate_id(true)') && str_contains($sesi, 'hash_equals') && str_contains($sesi, "'psb.login_failed'"), 'S6 regenerasi sesi, perbandingan waktu-tetap, pembatasan percobaan');
$cek(str_contains($baca('login_pendaftar.php'), 'name="nisn"') && str_contains($baca('login_pendaftar.php'), 'name="hp"'), 'S6 login tetap NISN + No. HP wali (AP)');
foreach (array_merge($publik, glob($root . '/app/Psb/*.php') ?: [], ['admin/admin_data.php']) as $f) {
    $path = str_starts_with($f, '/') ? $f : $root . '/' . $f;
    exec('php -l ' . escapeshellarg($path) . ' 2>&1', $out, $code);
    $cek($code === 0, 'S6 lint ' . basename($path));
}

echo PHP_EOL . 'Hasil: ' . $lulus . ' lulus, ' . count($gagal) . ' gagal' . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
