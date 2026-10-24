<?php

declare(strict_types=1);

/**
 * Uji statis redesain website Tahap 8 — situs publik & PSB (tanpa DB/server).
 *
 *   php tests/redesain_web_tahap8_static.php
 *
 * T8-A  kerangka: header/footer memakai App\Situs\Kerangka, token bersama,
 *       tanpa style.css/CDN animasi; navbar tanpa "#", "Masuk" (AM), 6 bagian Profil
 * T8-B  tanpa isi contoh: [Tahun Berdiri], nomor contoh, foto stok, tautan "#";
 *       bahan resmi kosong di isi-situs.php (keputusan butir 1)
 * T8-C  PSB P-1: 4 langkah, jenjang Unit Al Hasan/Sekolah Mitra (AN), RA/MI
 *       belum dibuka (butir 2b) ditegakkan server, NISN/NIK/HP teks inputmode
 *       numeric, persetujuan privasi wajib tanpa disimpan (AO)
 * T8-D  portal & biaya: unggah per dokumen, biaya_psb tetap di alamatnya dan
 *       dibuka dari menu Pembiayaan (butir 3), aturan biaya AJ tidak berubah
 * T8-E  cetak P-8 memakai kop X-1; pratinjau berkas admin (AG) lewat jalur berpenjaga
 * T8-F  batas: kelas keamanan PSB, app/Auth, migrasi, mobile, rapor tidak berubah
 *       terhadap baseline 1b319fa; tanpa alert()/confirm(); lint
 */

$root = dirname(__DIR__);
const BASELINE = '1b319fa';
$baca = static fn (string $f): string => (string) @file_get_contents($root . '/' . $f);
$lulus = 0;
$gagal = [];
$cek = static function (bool $ok, string $pesan) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    $ok ? $lulus++ : $gagal[] = $pesan;
};
$git = static fn (string $args): string => (string) shell_exec('git -C ' . escapeshellarg($root) . ' ' . $args . ' 2>/dev/null');

$publik = ['index.php', 'profil.php', 'pendidikan.php', 'berita.php', 'detail.php', 'galeri.php', 'jadwal_ngaji.php', 'download.php',
    'privacy.php', 'verifikasi_rapor.php', 'psb.php', 'login_pendaftar.php', 'logout_pendaftar.php', 'portal_pendaftar.php',
    'biaya_psb.php', 'cetak_bukti.php', 'cetak_biaya_psb.php', 'header.php', 'footer.php', 'app/Situs/Kerangka.php'];
$semua = implode("\n", array_map($baca, $publik));

// T8-A
$cek(str_contains($baca('header.php'), 'Kerangka::buka(') && str_contains($baca('footer.php'), 'Kerangka::tutup()'), 'T8-A header.php/footer.php memakai kerangka situs bersama');
$cek(!preg_match('/href=["\']?style\.css/', $semua) && !str_contains($semua, 'aos.js') && !str_contains($semua, 'fonts.googleapis') && !str_contains($semua, 'lightbox')
    && !str_contains($semua, 'masonry') && !str_contains($semua, 'flatpickr'), 'T8-A tanpa style.css yang hilang dan tanpa pustaka animasi/galeri/tanggal CDN');
$css = $baca('assets/ui/situs.css');
$cek(str_contains($baca('app/Situs/Kerangka.php'), "/assets/ui/alhasan.css") && str_contains($baca('app/Situs/Kerangka.php'), '/assets/ui/situs.css')
    && preg_match_all('/#[0-9a-fA-F]{3,6}\b/', preg_replace('/^:root \{.*?\n\}/ms', '', $css)) === 0, 'T8-A warna situs hanya dari token :root (alhasan.css + situs.css)');
$k = $baca('app/Situs/Kerangka.php');
$cek(!preg_match('/href="#"/', $semua) && !str_contains($semua, "href='#'"), 'T8-A tidak ada tautan "#" di halaman publik dan kerangka');
$cek(str_contains($k, '>Masuk</summary>') && str_contains($k, "/portal/") && str_contains($k, '/login_pendaftar.php'), 'T8-A tautan "Masuk" di navbar (AM): Masuk Sistem dan Portal Pendaftar');
$cek(str_contains($baca('index.php'), 'class="s-quick"') && substr_count($baca('index.php'), '<a href=', ) >= 4 && str_contains($baca('index.php'), '/verifikasi_rapor.php')
    && str_contains($baca('index.php'), '/portal/'), 'T8-A bilah pintasan Beranda: PSB, Portal Pendaftar, Cek rapor, Masuk Sistem (AM)');
$cek(count(App_profil()) === 6, 'T8-A Profil 6 bagian beralamat sendiri (U-2)');
$cek(str_contains($baca('.htaccess'), 'RewriteRule ^profil/?$ profil.php') && str_contains($baca('.htaccess'), 'RewriteRule ^pendidikan/?$ pendidikan.php')
    && str_contains($baca('.htaccess'), 'RewriteRule ^privacy/?$ privacy.php'), 'T8-A alamat bersih /profil, /pendidikan; /privacy tetap');
$cek(str_contains($baca('berita.php'), 'BERITA_PER_HALAMAN = 12') && !preg_match('/kategori|status/i', preg_replace('/\/\*.*?\*\//s', '', $baca('berita.php'))), 'T8-A berita berhalaman 12 tanpa status/kategori (AH)');
$cek(str_contains($baca('galeri.php'), 'GALERI_PER_MUAT = 24') && !preg_match('/album/i', preg_replace('/\/\*.*?\*\//s', '', $baca('galeri.php'))) && str_contains($baca('galeri.php'), '<figcaption>'), 'T8-A galeri tanpa album, keterangan tetap (AI), muat 24');
$cek(str_contains($baca('jadwal_ngaji.php'), 'class="s-seg"') && str_contains($baca('jadwal_ngaji.php'), 'data-label="Kitab"'), 'T8-A jadwal ngaji per waktu sholat (segmen) dan kartu di HP');
$cek(str_contains($baca('verifikasi_rapor.php'), 'Kerangka::buka(') && str_contains($baca('verifikasi_rapor.php'), "frame-ancestors 'none'")
    && str_contains($baca('verifikasi_rapor.php'), 'Csrf::requireValid') && str_contains($baca('verifikasi_rapor.php'), "'bootstrap' => false"), 'T8-A cek keaslian rapor di kerangka situs, CSP/CSRF tetap, tanpa CDN');

// T8-B
$isiSitus = require $root . '/app/Situs/isi-situs.php';
$cek(!preg_match('/\[Tahun Berdiri\]|6281234567890|812 3456 7890|123456\b|unsplash|flaticon/i', $semua . $baca('app/Situs/isi-situs.php')), 'T8-B tanpa "[Tahun Berdiri]", nomor contoh, atau foto stok');
$cek($isiSitus['tahun_berdiri'] === '' && $isiSitus['telepon'] === '' && $isiSitus['whatsapp'] === '' && $isiSitus['logo'] === '' && $isiSitus['statistik'] === []
    && $isiSitus['tentang'] === '' && $isiSitus['profil']['sejarah'] === '' && $isiSitus['profil']['dewan_pengajar'] === [], 'T8-B bahan resmi dikosongkan untuk diisi manual (keputusan butir 1)');
$cek(str_contains($baca('app/Situs/IsiSitus.php'), "str_contains(\$jalur, '..')") && str_contains($baca('app/Situs/IsiSitus.php'), 'is_file(APP_ROOT'), 'T8-B gambar isi resmi hanya berkas lokal yang ada');
$cek(str_contains($baca('profil.php'), 'Belum diterbitkan'), 'T8-B bagian Profil kosong menampilkan keadaan kosong jujur, bukan teks contoh');

// T8-C
$psb = $baca('psb.php');
$cek(preg_match_all('/class="s-langkah[ "]/', $psb) === 4 && str_contains($psb, 'data-s-langkah'), 'T8-C formulir 4 langkah (P-1)');
$cek(str_contains($psb, 'optgroup label="Unit Al Hasan"') && str_contains($psb, 'optgroup label="Sekolah Mitra"'), 'T8-C jenjang dikelompokkan Unit Al Hasan / Sekolah Mitra (AN)');
$jenjang = $baca('app/Psb/Jenjang.php');
$cek(str_contains($jenjang, "BELUM_DIBUKA = ['RA Terpadu Al Hasan', 'MI Daar el-Qolam']") && str_contains($baca('app/Psb/PendaftarData.php'), 'Jenjang::galat('), 'T8-C RA/MI belum dibuka ditegakkan server (butir 2b)');
$cek(!str_contains($baca('app/Psb/PsbAdmin.php'), 'MI Daar') && !str_contains($baca('app/Psb/BiayaPsb.php'), 'MI Daar'), 'T8-C MI tidak masuk katalog unit/biaya; biaya MI/RA tidak ditetapkan');
foreach (['nisn', 'nik', 'no_hp_wali', 'no_hp_wali_ulang'] as $n) {
    $cek(preg_match('/name="' . $n . '" type="text" inputmode="numeric"/', $psb) === 1, 'T8-C ' . $n . ' isian teks inputmode numeric');
}
$cek(!preg_match('/type="number"/', $psb . $baca('login_pendaftar.php') . $baca('portal_pendaftar.php') . $baca('biaya_psb.php')), 'T8-C tidak ada type=number di PSB (angka 0 di depan aman)');
$proses = $baca('proses_psb.php');
$cek(str_contains($psb, 'name="setuju_privasi" value="1" required') && str_contains($proses, "\$_POST['setuju_privasi']") && !str_contains($baca('app/Psb/PendaftarData.php'), 'setuju_privasi'), 'T8-C persetujuan privasi wajib di peramban dan server, tidak disimpan (AO)');
$cek(str_contains($baca('login_pendaftar.php'), 'name="nisn"') && str_contains($baca('login_pendaftar.php'), 'name="hp" type="password"') && str_contains($baca('login_pendaftar.php'), 'action="auth_pendaftar.php"'), 'T8-C masuk tetap NISN + No. HP wali, disamarkan (AP)');

// T8-D
$portal = $baca('portal_pendaftar.php');
$cek(substr_count($portal, 'action="proses_upload_berkas.php"') === 1 && str_contains($portal, 'foreach ($daftarBerkas') && str_contains($portal, 'data-s-unggah'), 'T8-D unggah per dokumen (P-6)');
$cek(str_contains($baca('app/Situs/Kerangka.php'), "'biaya' => ['Pembiayaan', app_url('/biaya_psb.php')]") && str_contains($baca('biaya_psb.php'), "Kerangka::bukaPortal(\$pendaftarMasuk, 'biaya'"), 'T8-D pembiayaan tetap di biaya_psb.php, dibuka dari menu Pembiayaan portal (butir 3)');
$cek(str_contains($baca('biaya_psb.php'), 'BiayaPsb::dariKiriman(') && str_contains($baca('biaya_psb.php'), 'name="wajib[]"') && !str_contains($baca('biaya_psb.php'), 'target="_blank"'), 'T8-D aturan biaya wajib tetap (AJ), kwitansi di tab yang sama');
$cek($git('diff --quiet ' . BASELINE . ' -- app/Psb/BiayaPsb.php; echo $?') === "0\n", 'T8-D katalog dan simpan biaya tidak berubah dari baseline');

// T8-E
$cek(str_contains($baca('cetak_bukti.php'), 'PrintLayout::kop(') && str_contains($baca('cetak_biaya_psb.php'), 'PrintLayout::kop(')
    && str_contains($baca('cetak_bukti.php'), 'PrintLayout::cssDasar()') && str_contains($baca('cetak_biaya_psb.php'), 'PrintLayout::cssDasar()'), 'T8-E bukti dan kwitansi memakai kop dan huruf templat X-1 (P-8)');
$cek(str_contains($baca('cetak_bukti.php'), "tandaTangan('Panitia PSB')") && str_contains($baca('cetak_biaya_psb.php'), 'Bendahara penerimaan'), 'T8-E penanda tangan tetap (Panitia PSB, Bendahara)');
$data = $baca('admin/admin_data.php');
$cek(preg_match('/<img src="<\?= ah_e\(\$urlBerkas\) \?>"/', $data) === 1 && str_contains($data, "app_url('/lihat_berkas_psb.php?'") && !preg_match('#src="[^"]*/berkas_psb/#', $data), 'T8-E pratinjau berkas admin lewat lihat_berkas_psb.php (AG)');

// T8-F
foreach (['app/Psb/PsbAkses.php', 'app/Psb/BerkasPsb.php', 'app/Psb/PendaftarSesi.php', 'lihat_berkas_psb.php', 'auth_pendaftar.php', 'app/Auth', 'app/V4', 'database', 'api', 'portal/rapor_ttd.php', 'berkas_psb'] as $jalur) {
    $cek($git('diff --quiet ' . BASELINE . ' -- ' . escapeshellarg($jalur) . '; echo $?') === "0\n", 'T8-F tidak berubah dari baseline: ' . $jalur);
}
$cek(trim($git('ls-files berkas_psb')) !== '' && count(array_filter(explode("\n", trim($git('ls-files berkas_psb'))))) === 7, 'T8-F enam berkas pendaftar lama + .htaccess tetap terlacak (tidak dihapus)');
foreach (['psb.php', 'portal_pendaftar.php', 'biaya_psb.php', 'login_pendaftar.php', 'galeri.php', 'assets/ui/situs.js'] as $f) {
    $cek(!preg_match('/\balert\(|\bconfirm\(/', preg_replace('/\/\*.*?\*\//s', '', $baca($f))), 'T8-F tanpa alert()/confirm() bawaan: ' . $f);
}
foreach (array_merge($publik, ['proses_psb.php', 'proses_upload_berkas.php', 'app/Situs/IsiSitus.php', 'app/Situs/isi-situs.php', 'app/Psb/Jenjang.php', 'app/Psb/PendaftarData.php', 'admin/admin_data.php']) as $f) {
    exec('php -l ' . escapeshellarg($root . '/' . $f) . ' 2>&1', $out, $code);
    $cek($code === 0, 'T8-F lint ' . $f);
}
exec('node --check ' . escapeshellarg($root . '/assets/ui/situs.js') . ' 2>&1', $out, $code);
$cek($code === 0, 'T8-F sintaks assets/ui/situs.js');

/** @return array<string, string> */
function App_profil(): array
{
    $s = (string) file_get_contents(dirname(__DIR__) . '/app/Situs/Kerangka.php');
    preg_match('/public const PROFIL = \[(.*?)\];/s', $s, $m);
    preg_match_all("/'([a-z-]+)' => '/", $m[1] ?? '', $k);

    return array_flip($k[1]);
}

echo PHP_EOL . 'Hasil: ' . $lulus . ' lulus, ' . count($gagal) . ' gagal' . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
