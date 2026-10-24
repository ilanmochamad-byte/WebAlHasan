<?php

declare(strict_types=1);

/**
 * Uji server redesain website Tahap 8 — situs publik & PSB (Apache + php-fpm,
 * `.htaccess` aktif, salinan sumber di luar checkout).
 *
 *   KPSB_DIR=/private/tmp/<privat> KPSB_PORT=8981 KPSB_DB=test_redesain_t8_test bash tests/keamanan_psb_server.sh start
 *   REDESAIN_T8_SEED=1 KPSB_DB=test_redesain_t8_test KPSB_WEB_ROOT=/private/tmp/<privat>/web php tests/redesain_web_tahap8_seed.php
 *   REDESAIN_T8_RUN=1 KPSB_URL=http://127.0.0.1:8981 KPSB_DB=test_redesain_t8_test \
 *     KPSB_WEB_ROOT=/private/tmp/<privat>/web php tests/redesain_web_tahap8_http.php
 *
 *   H8-A  halaman publik & alamat lama 200, kerangka bersama, tanpa "#", isi kosong tidak tampil
 *   H8-B  kabar: 12 per halaman, cari ber-escape, detail id angka, meta bagikan
 *   H8-C  galeri tanpa album, keterangan tetap, muat 24
 *   H8-D  jadwal per waktu, saringan, jadwal nonaktif tidak tampil
 *   H8-E  unduhan: jenis/ukuran sebenarnya
 *   H8-F  cek keaslian rapor: kerangka situs, CSP dan CSRF tetap
 *   H8-G  formulir PSB: jenjang AN, RA/MI belum dibuka (server), ketik ulang HP, isian kembali saat gagal, halaman berhasil
 *   H8-H  portal: bagian status/data/berkas, ubah per bagian + aturan jenjang, unggah per dokumen, pembiayaan di portal
 *   H8-I  bukti & kwitansi memakai kop X-1
 *   H8-J  pratinjau berkas admin (AG) lewat jalur berpenjaga
 *   H8-K  tanpa galat PHP di log server
 *
 * Data sintetis: berita/galeri/unduhan/jadwal "[T8]", pendaftar NISN 97000000xx. DB wajib test_*_test.
 */

if (getenv('REDESAIN_T8_RUN') !== '1') {
    fwrite(STDERR, "Set REDESAIN_T8_RUN=1 untuk menjalankan.\n");
    exit(2);
}
$base = rtrim((string) getenv('KPSB_URL'), '/');
$web = rtrim((string) getenv('KPSB_WEB_ROOT'), '/');
$db = getenv('KPSB_DB') ?: 'test_redesain_t8_test';
if (!preg_match('#^http://127\.0\.0\.1:\d+$#', $base) || $web === '' || !is_dir($web) || file_exists($web . '/.git') || !preg_match('/^test_.*_test$/', $db)) {
    fwrite(STDERR, "Ditolak: URL 127.0.0.1, KPSB_WEB_ROOT salinan tanpa .git, DB test_*_test.\n");
    exit(2);
}
putenv('DB_NAME=' . $db);
require_once dirname(__DIR__) . '/app/bootstrap.php';
$mysql = app_db();

$lulus = 0;
$gagal = [];
$cek = static function (bool $ok, string $pesan) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    $ok ? $lulus++ : $gagal[] = $pesan;
};
$q = static function (string $sql, array $p = []) use ($mysql) {
    $stmt = $mysql->prepare($sql);
    if ($p !== []) {
        $stmt->bind_param(str_repeat('s', count($p)), ...array_map('strval', $p));
    }
    $stmt->execute();
    $r = $stmt->get_result();
    $stmt->close();

    return $r === false ? null : $r->fetch_all(MYSQLI_ASSOC);
};
$satu = static fn (string $sql, array $p = []) => ($q($sql, $p)[0] ?? null);

final class Klien8
{
    private string $jar;

    public function __construct(private string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'kt8-jar-');
    }

    public function __destruct()
    {
        @unlink($this->jar);
    }

    /** @return array{status:int, body:string, location:?string, type:string, headers:string} */
    public function req(string $path, ?array $post = null): array
    {
        $h = curl_init($this->base . $path);
        curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 60]);
        if ($post !== null) {
            $multipart = false;
            array_walk_recursive($post, static function ($v) use (&$multipart): void { $multipart = $multipart || $v instanceof CURLFile; });
            curl_setopt($h, CURLOPT_POST, true);
            curl_setopt($h, CURLOPT_POSTFIELDS, $multipart ? $post : http_build_query($post));
        }
        $raw = (string) curl_exec($h);
        $status = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
        $size = (int) curl_getinfo($h, CURLINFO_HEADER_SIZE);
        $type = (string) curl_getinfo($h, CURLINFO_CONTENT_TYPE);
        curl_close($h);
        $headers = substr($raw, 0, $size);
        preg_match('/^Location:\s*(.+)$/mi', $headers, $m);

        return ['status' => $status, 'body' => substr($raw, $size), 'location' => isset($m[1]) ? trim($m[1]) : null, 'type' => $type, 'headers' => $headers];
    }

    public function csrf(string $path): string
    {
        $r = $this->req($path);
        if (preg_match('/name="_csrf" value="([^"]+)"/', $r['body'], $m) !== 1) {
            throw new RuntimeException('Token CSRF tidak ditemukan pada ' . $path);
        }

        return $m[1];
    }

    public function masuk(string $nisn, string $hp): array
    {
        return $this->req('/auth_pendaftar.php', ['_csrf' => $this->csrf('/login_pendaftar.php'), 'nisn' => $nisn, 'hp' => $hp]);
    }

    public function staf(string $username): void
    {
        $this->req('/admin/cek_login.php', ['_csrf' => $this->csrf('/portal/index.php'), 'username' => $username, 'password' => 'Sandbox#123']);
    }
}

$anon = new Klien8($base);
$hitung = static fn (string $jarum, string $body): int => substr_count($body, $jarum);

// --- H8-A. Halaman publik --------------------------------------------------------
$halaman = ['/', '/index.php', '/profil', '/profil.php', '/pendidikan', '/pendidikan.php', '/berita.php', '/galeri.php', '/jadwal_ngaji.php',
    '/download.php', '/privacy', '/privacy.php', '/verifikasi_rapor.php', '/psb.php', '/login_pendaftar.php', '/logout_pendaftar.php', '/biaya_psb.php'];
foreach ($halaman as $path) {
    $r = $anon->req($path);
    $cek($r['status'] === 200 && str_contains($r['body'], '/assets/ui/situs.css') && !preg_match('/href=["\']#["\']/', $r['body'])
        && !str_contains($r['body'], 'style.css') && !str_contains($r['body'], '[Tahun Berdiri]'), 'H8-A ' . $path . ' 200, kerangka situs, tanpa tautan "#"');
}
foreach (array_keys(App\Situs\Kerangka::PROFIL) as $bagian) {
    $r = $anon->req('/profil?bagian=' . $bagian);
    $cek($r['status'] === 200 && str_contains($r['body'], 'aria-current="page">' . htmlspecialchars(App\Situs\Kerangka::PROFIL[$bagian], ENT_QUOTES)) && str_contains($r['body'], 'Belum diterbitkan'),
        'H8-A Profil bagian ' . $bagian . ' beralamat sendiri, isi kosong tampil jujur');
}
$beranda = $anon->req('/')['body'];
$cek(str_contains($beranda, '>Masuk</summary>') && str_contains($beranda, 'href="/portal/"') && str_contains($beranda, 'href="/login_pendaftar.php"')
    && str_contains($beranda, 'Cek keaslian rapor') && str_contains($beranda, 'class="s-quick"'), 'H8-A navbar "Masuk" dan bilah pintasan Beranda (AM)');
$cek(!str_contains($beranda, 'Telepon:') && !str_contains($beranda, 'wa.me/') && !str_contains($beranda, 'class="s-stats"') && !str_contains($beranda, 'judul-tentang')
    && str_contains($beranda, 'Jalan Jenderal Ahmad Yani No. 120'), 'H8-A isi resmi kosong (telepon/WA/statistik/tentang) tidak tampil; alamat dari kebijakan privasi tampil');
$cek(str_contains($beranda, 'https://smpt.alhasan.co.id') && str_contains($beranda, 'target="_blank" rel="noopener"') && str_contains($beranda, 'MI Daar el-Qolam'), 'H8-A empat unit, situs unit membuka tab baru');
$cek($anon->req('/app/Situs/isi-situs.php')['status'] === 403 && $anon->req('/style.css')['status'] === 404, 'H8-A berkas isi resmi ditolak web; style.css memang tidak ada');
$pend = $anon->req('/pendidikan')['body'];
$cek(str_contains($pend, 'id="unit-ra"') && str_contains($pend, 'psb.php?jenjang=SMP%20Terpadu%20Al%20Hasan') && !str_contains($pend, 'psb.php?jenjang=RA') && str_contains($pend, 'PSB belum dibuka'),
    'H8-A Unit pendidikan: daftar dengan jenjang terpilih, RA/MI belum dibuka');

// --- H8-B. Kabar -------------------------------------------------------------------
$jumlahBerita = (int) $satu('SELECT COUNT(*) n FROM berita')['n'];
$r = $anon->req('/berita.php');
$cek($hitung('class="s-card__title"', $r['body']) === min(12, $jumlahBerita) && str_contains($r['body'], 'Halaman 1 dari ' . (int) ceil($jumlahBerita / 12)), 'H8-B berita 12 per halaman dengan penomoran');
$r = $anon->req('/berita.php?halaman=2');
$cek($hitung('class="s-card__title"', $r['body']) === $jumlahBerita - 12 && str_contains($r['body'], 'rel="prev"'), 'H8-B halaman 2 berisi sisa berita');
$r = $anon->req('/berita.php?cari=' . rawurlencode('nomor 13'));
$cek($hitung('class="s-card__title"', $r['body']) === 1 && str_contains($r['body'], 'nomor 13'), 'H8-B cari berita');
$r = $anon->req('/berita.php?cari=' . rawurlencode('<script>x</script>'));
$cek(!str_contains($r['body'], '<script>x</script>') && str_contains($r['body'], '&lt;script&gt;'), 'H8-B kata kunci di-escape');
$r = $anon->req('/berita.php?cari=' . rawurlencode("' OR '1'='1"));
$cek($r['status'] === 200 && $hitung('class="s-card__title"', $r['body']) === 0, 'H8-B kata kunci berpetik tidak mengubah query');
$idBerita = (int) $satu("SELECT id FROM berita WHERE judul LIKE '[T8] Kabar sintetis nomor 5%'")['id'];
$r = $anon->req('/detail.php?id=' . $idBerita);
$cek($r['status'] === 200 && str_contains($r['body'], 'property="og:title" content="[T8] Kabar sintetis nomor 5"') && str_contains($r['body'], 'href="/berita.php">Kabar</a>')
    && str_contains($r['body'], 'Kabar lainnya') && str_contains($r['body'], 'https://wa.me/?text=') && !str_contains($r['body'], 'facebook'), 'H8-B detail: meta bagikan, jalur ke Kabar, WhatsApp + salin tautan');
foreach (['abc', $idBerita . '%20OR%201=1', '0', '-1'] as $id) {
    $r = $anon->req('/detail.php?id=' . $id);
    $cek($r['status'] === 404 && str_contains($r['body'], 'Berita tidak ditemukan'), 'H8-B detail id tidak sah (' . $id . ') 404');
}

// --- H8-C. Galeri -------------------------------------------------------------------
$jumlahFoto = (int) $satu('SELECT COUNT(*) n FROM galeri')['n'];
$r = $anon->req('/galeri.php');
$cek($hitung('<figcaption>', $r['body']) === min(24, $jumlahFoto) && str_contains($r['body'], 'galeri.php?jumlah=48') && str_contains($r['body'], 'Keterangan foto sintetis')
    && str_contains($r['body'], 'data-s-galeri') && !preg_match('/album/i', $r['body']), 'H8-C galeri 24 foto pertama, keterangan tetap, muat lagi, tanpa album');
$r = $anon->req('/galeri.php?jumlah=48');
$cek($hitung('<figcaption>', $r['body']) === min(48, $jumlahFoto) && !str_contains($r['body'], 'jumlah=72'), 'H8-C muat lagi menampilkan sisa foto');
$cek($anon->req('/galeri.php?jumlah=999999')['status'] === 200, 'H8-C jumlah tidak sah tetap aman');

// --- H8-D. Jadwal ngaji --------------------------------------------------------------
$r = $anon->req('/jadwal_ngaji.php');
$cek($hitung('<a href="/jadwal_ngaji.php?waktu=', $r['body']) === 4 && str_contains($r['body'], '[T8] Kitab sintetis 1-1') && !str_contains($r['body'], '[T8] Kitab nonaktif')
    && str_contains($r['body'], 'data-label="Kitab"'), 'H8-D segmen 4 waktu sholat; jadwal nonaktif tidak tampil');
$r = $anon->req('/jadwal_ngaji.php?waktu=' . rawurlencode("Ba'da Isya"));
$cek(str_contains($r['body'], '[T8] Kitab sintetis 4-1') && !str_contains($r['body'], '[T8] Kitab sintetis 1-1'), 'H8-D pilih waktu Ba\'da Isya');
$r = $anon->req('/jadwal_ngaji.php?cari=' . rawurlencode('tidak-ada-kitab'));
$cek(str_contains($r['body'], 'Belum ada jadwal') && str_contains($r['body'], 'Hapus saringan'), 'H8-D saringan tanpa hasil menjelaskan sebabnya');
$r = $anon->req('/jadwal_ngaji.php?waktu=' . rawurlencode("x' OR 1=1 -- "));
$cek($r['status'] === 200 && str_contains($r['body'], 'aria-current="page">Ba&#039;da Shubuh'), 'H8-D waktu tidak sah kembali ke Ba\'da Shubuh');

// --- H8-E. Unduhan ---------------------------------------------------------------------
$r = $anon->req('/download.php');
$cek(str_contains($r['body'], 's-jenis--pdf') && str_contains($r['body'], '>DOCX<') && preg_match('/PDF · \d+ KB/', $r['body']) === 1
    && str_contains($r['body'], 'href="/file_download/t8_brosur.pdf" download'), 'H8-E jenis dan ukuran berkas sebenarnya, seluruh baris diunduh');
$cek($anon->req('/file_download/t8_brosur.pdf')['status'] === 200, 'H8-E berkas unduhan tetap terbuka');

// --- H8-F. Cek keaslian rapor ----------------------------------------------------------
$r = $anon->req('/verifikasi_rapor.php');
$cek(str_contains($r['headers'], "default-src 'none'") && str_contains($r['headers'], "frame-ancestors 'none'") && str_contains($r['headers'], "script-src 'self';")
    && stripos($r['headers'], 'no-store') !== false && stripos($r['headers'], 'Referrer-Policy: no-referrer') !== false, 'H8-F header keamanan dan CSP ketat tetap (tanpa CDN)');
$cek(str_contains($r['body'], 'class="s-top"') && str_contains($r['body'], 'class="s-foot"') && !str_contains($r['body'], 'cdn.jsdelivr') && !preg_match('/<script>(?!<\/script>)/', $r['body']),
    'H8-F di dalam kerangka situs, tanpa CDN dan skrip sebaris');
$r = $anon->req('/verifikasi_rapor.php', ['kode' => str_repeat('a', 48)]);
$cek($r['status'] === 419, 'H8-F POST tanpa CSRF ditolak 419');
$r = $anon->req('/verifikasi_rapor.php', ['_csrf' => $anon->csrf('/verifikasi_rapor.php'), 'kode' => str_repeat('c', 48)]);
$cek($r['status'] === 404 && str_contains($r['body'], 'Kode tidak ditemukan'), 'H8-F kode tidak dikenal 404 tanpa data');

// --- H8-G. Formulir PSB --------------------------------------------------------------------
$q("DELETE FROM psb_pembayaran WHERE no_pendaftaran IN (SELECT no_pendaftaran FROM psb_pendaftar WHERE nisn LIKE '97000000%')");
$q("DELETE FROM psb_pendaftar WHERE nisn LIKE '97000000%'");
$r = $anon->req('/psb.php');
$cek(preg_match_all('/<fieldset class="s-langkah/', $r['body']) === 4 && str_contains($r['body'], '<optgroup label="Unit Al Hasan">') && str_contains($r['body'], '<optgroup label="Sekolah Mitra">')
    && preg_match('/value="RA Terpadu Al Hasan" disabled>RA Terpadu Al Hasan \(belum dibuka\)/', $r['body']) === 1
    && preg_match('/value="MI Daar el-Qolam" disabled>MI Daar el-Qolam \(belum dibuka\)/', $r['body']) === 1, 'H8-G 4 langkah, jenjang dikelompokkan, RA/MI belum dibuka');
$cek(str_contains($r['body'], 'name="nisn" type="text" inputmode="numeric"') && str_contains($r['body'], 'name="setuju_privasi" value="1" required') && str_contains($r['body'], 'href="/privacy"'), 'H8-G NISN berpapan angka, persetujuan privasi wajib bertaut');
$cek(str_contains($anon->req('/psb.php?jenjang=' . rawurlencode('SMK Terpadu Al Hasan'))['body'], 'value="SMK Terpadu Al Hasan" selected')
    && !str_contains($anon->req('/psb.php?jenjang=' . rawurlencode('RA Terpadu Al Hasan'))['body'], '" selected'), 'H8-G jenjang dari tautan unit terpilih, kecuali yang belum dibuka');
$isi = ['daftar' => '1', 'nama_lengkap' => 'Pendaftar T8 Baru', 'nisn' => '9700000010', 'nik' => '3207000000009710', 'tempat_lahir' => 'Ciamis', 'tgl_lahir' => '2014-03-04',
    'jenis_kelamin' => 'L', 'jenjang_tujuan' => 'SMP Terpadu Al Hasan', 'alamat_jalan' => 'Jl. T8', 'nama_provinsi' => 'JAWA BARAT', 'nama_kabupaten' => 'KAB. CIAMIS',
    'nama_kecamatan' => 'CIAMIS', 'nama_desa' => 'SINDANGRASA', 'nama_ayah' => 'Ayah T8', 'nama_ibu' => 'Ibu T8', 'no_hp_wali' => '081297000010',
    'no_hp_wali_ulang' => '081297000010', 'sekolah_asal' => 'SD T8', 'setuju_privasi' => '1'];
$pub = new Klien8($base);
foreach (['RA Terpadu Al Hasan', 'MI Daar el-Qolam'] as $tutup) {
    $r = $pub->req('/proses_psb.php', ['_csrf' => $pub->csrf('/psb.php'), 'jenjang_tujuan' => $tutup] + $isi);
    $gagalPage = $pub->req('/psb.php?status=gagal')['body'];
    $cek(str_contains((string) $r['location'], 'status=gagal') && $satu("SELECT id FROM psb_pendaftar WHERE nisn='9700000010'") === null
        && str_contains($gagalPage, 'Pendaftaran ' . $tutup . ' belum dibuka'), 'H8-G server menolak pendaftaran ' . $tutup . ' (belum dibuka)');
}
$r = $pub->req('/proses_psb.php', ['_csrf' => $pub->csrf('/psb.php'), 'no_hp_wali_ulang' => '081297000099', 'nik' => '12ab'] + $isi);
$gagalPage = $pub->req('/psb.php?status=gagal')['body'];
$cek(str_contains((string) $r['location'], 'status=gagal') && $satu("SELECT id FROM psb_pendaftar WHERE nisn='9700000010'") === null
    && str_contains($gagalPage, 'diketik ulang belum sama') && str_contains($gagalPage, 'NIK hanya berisi angka') && str_contains($gagalPage, 'id="galat-nik"')
    && str_contains($gagalPage, 'value="Pendaftar T8 Baru"') && str_contains($gagalPage, 'data-s-langkah-galat="0"'), 'H8-G ketik ulang HP beda dan NIK salah: galat per isian, isian kembali, langkah galat dibuka');
$cek(!str_contains($pub->req('/psb.php?status=gagal')['body'], 'value="Pendaftar T8 Baru"'), 'H8-G isian kembali hanya sekali (sesi dibersihkan)');
$r = $pub->req('/proses_psb.php', ['_csrf' => $pub->csrf('/psb.php')] + $isi);
$baru = $satu("SELECT * FROM psb_pendaftar WHERE nisn='9700000010'");
$sukses = $pub->req('/psb.php?status=sukses&noreg=' . rawurlencode((string) ($baru['no_pendaftaran'] ?? '')))['body'];
$cek($baru !== null && $baru['status'] === 'Baru' && $baru['no_hp_wali'] === '081297000010' && str_contains($sukses, 'Pendaftaran berhasil')
    && str_contains($sukses, 'id="nomor-pendaftaran">' . $baru['no_pendaftaran']) && str_contains($sukses, 'cetak_bukti.php?noreg=') && str_contains($sukses, 'Langkah berikutnya'),
    'H8-G pendaftaran sah tersimpan; halaman berhasil (P-2) dengan nomor, cetak bukti, langkah berikutnya');
$cek(!array_key_exists('setuju_privasi', $baru ?? []) && !array_key_exists('no_hp_wali_ulang', $baru ?? []), 'H8-G persetujuan dan ketik ulang tidak disimpan (AO, tanpa migrasi)');

// --- H8-H. Portal pendaftar -------------------------------------------------------------------
$tambah = static function (string $nisn, string $hp, string $jenjang, string $nama) use ($q, $satu): int {
    $q('INSERT INTO psb_pendaftar (no_pendaftaran, nama_lengkap, nisn, nik, tempat_lahir, tgl_lahir, jenis_kelamin, alamat_jalan, desa, kecamatan, kab_kota, provinsi, nama_ayah, nama_ibu, no_hp_wali, sekolah_asal, jenjang_tujuan, status)
        VALUES (?, ?, ?, ?, "Ciamis", "2014-01-02", "P", "Jl. T8", "Desa T8", "Kec T8", "Kab T8", "Prov T8", "Ayah T8", "Ibu T8", ?, "SD T8", ?, "Baru")',
        ['KT8-' . substr($nisn, -4), $nama, $nisn, '3207' . $nisn . '01', $hp, $jenjang]);

    return (int) $satu('SELECT id FROM psb_pendaftar WHERE nisn = ?', [$nisn])['id'];
};
$idRa = $tambah('9700000021', '081297000021', 'RA Terpadu Al Hasan', 'Pendaftar T8 RA');
$idSmp = $tambah('9700000022', '081297000022', 'SMP Terpadu Al Hasan', 'Pendaftar T8 SMP');
$kRa = new Klien8($base);
$kRa->masuk('9700000021', '081297000021');
$kSmp = new Klien8($base);
$kSmp->masuk('9700000022', '081297000022');
$p = $kSmp->req('/portal_pendaftar.php');
$cek($p['status'] === 200 && str_contains($p['body'], 'id="status"') && str_contains($p['body'], 'id="data"') && str_contains($p['body'], 'id="berkas"')
    && str_contains($p['body'], 'class="s-bottombar"') && str_contains($p['body'], 'href="/biaya_psb.php"'), 'H8-H portal: Status, Data diri, Berkas, Pembiayaan (menu samping dan bilah bawah)');
$cek(str_contains($p['body'], 'Belum lengkap') && str_contains($p['body'], 'Unggah 3 berkas wajib lagi') && !str_contains($p['body'], 'Status Akun'), 'H8-H langkah berikutnya sesuai status nyata');
$cek($hitung('name="bagian_ubah"', $p['body']) === 3 && $hitung('action="proses_upload_berkas.php"', $p['body']) === 6 && $hitung('name="_csrf"', $p['body']) >= 10,
    'H8-H tiga formulir ubah per bagian dan enam formulir unggah, semuanya ber-CSRF');
$ubahDari = static function (string $body, string $bagian): array {
    preg_match('/<form[^>]*>(?:(?!<\/form>).)*name="bagian_ubah" value="' . $bagian . '"(?:(?!<\/form>).)*<\/form>/s', $body, $m);
    $form = $m[0] ?? '';
    preg_match_all('/<(?:input|textarea|select)\b[^>]*>/s', $form, $mm);
    $data = [];
    foreach ($mm[0] as $tag) {
        if (!preg_match('/\sname="([^"]+)"/', $tag, $n) || (str_contains($tag, 'type="radio"') && !str_contains($tag, 'checked'))) {
            continue;
        }
        $data[$n[1]] = preg_match('/\svalue="([^"]*)"/', $tag, $v) ? html_entity_decode($v[1], ENT_QUOTES) : '';
    }
    if (preg_match('/<textarea[^>]*name="alamat_jalan"[^>]*>(.*?)<\/textarea>/s', $form, $t)) {
        $data['alamat_jalan'] = html_entity_decode($t[1], ENT_QUOTES);
    }
    if (preg_match('/<select[^>]*name="jenjang_tujuan".*?<\/select>/s', $form, $s) && preg_match('/<option value="([^"]*)"[^>]*selected/', $s[0], $o)) {
        $data['jenjang_tujuan'] = html_entity_decode($o[1], ENT_QUOTES);
    }

    return $data;
};
$formSmp = $ubahDari($p['body'], 'calon');
$cek(($formSmp['jenjang_tujuan'] ?? '') === 'SMP Terpadu Al Hasan' && ($formSmp['no_hp_wali'] ?? '') === '081297000022' && isset($formSmp['_csrf']), 'H8-H formulir ubah bagian membawa seluruh biodata tersimpan');
$r = $kSmp->req('/portal_pendaftar.php', ['jenjang_tujuan' => 'RA Terpadu Al Hasan'] + $formSmp);
$cek($r['status'] === 200 && $satu('SELECT jenjang_tujuan FROM psb_pendaftar WHERE id = ?', [$idSmp])['jenjang_tujuan'] === 'SMP Terpadu Al Hasan'
    && str_contains($r['body'], 'belum dibuka') && preg_match('/<details class="s-ubah" open>\s*<summary class="s-panel__head"><h2 id="judul-calon">/', $r['body']) === 1, 'H8-H pindah ke RA ditolak server; bagian calon santri terbuka dengan galat');
$r = $kSmp->req('/portal_pendaftar.php', ['jenjang_tujuan' => 'MI Daar el-Qolam'] + $formSmp);
$cek($satu('SELECT jenjang_tujuan FROM psb_pendaftar WHERE id = ?', [$idSmp])['jenjang_tujuan'] === 'SMP Terpadu Al Hasan', 'H8-H pindah ke MI ditolak server');
$r = $kSmp->req('/portal_pendaftar.php', ['jenjang_tujuan' => 'SMK Terpadu Al Hasan', 'nama_lengkap' => 'Pendaftar T8 SMP Diubah'] + $formSmp);
$cek($r['status'] === 302 && str_contains((string) $r['location'], 'portal_pendaftar.php#data')
    && $satu('SELECT jenjang_tujuan, nama_lengkap, no_hp_wali FROM psb_pendaftar WHERE id = ?', [$idSmp]) == ['jenjang_tujuan' => 'SMK Terpadu Al Hasan', 'nama_lengkap' => 'Pendaftar T8 SMP Diubah', 'no_hp_wali' => '081297000022'],
    'H8-H ubah bagian calon santri tersimpan; bagian lain tetap');
$p = $kSmp->req('/portal_pendaftar.php');
$cek(str_contains($p['body'], 'Data diri berhasil diperbarui.') && str_contains($p['body'], 'data-s-bagian-awal="data"') && str_contains($p['body'], 'data-s-toast="ok"'), 'H8-H pesan hasil di bagian Data diri + toast (bukan alert)');
$pRa = $kRa->req('/portal_pendaftar.php');
$formRa = $ubahDari($pRa['body'], 'ortu');
$cek(preg_match('/value="RA Terpadu Al Hasan" selected>RA Terpadu Al Hasan</', $pRa['body']) === 1, 'H8-H pendaftar RA lama melihat jenjang tersimpannya dapat dipertahankan');
$r = $kRa->req('/portal_pendaftar.php', ['nama_ayah' => 'Ayah T8 Baru'] + $formRa);
$cek($r['status'] === 302 && $satu('SELECT nama_ayah, jenjang_tujuan FROM psb_pendaftar WHERE id = ?', [$idRa]) == ['nama_ayah' => 'Ayah T8 Baru', 'jenjang_tujuan' => 'RA Terpadu Al Hasan'],
    'H8-H pendaftar RA lama tetap dapat menyimpan biodata (jenjang tersimpan dipertahankan)');
$formAlamat = $ubahDari($kRa->req('/portal_pendaftar.php')['body'], 'alamat');
$r = $kRa->req('/portal_pendaftar.php', ['nama_provinsi' => 'JAWA TENGAH', 'nama_kabupaten' => 'KAB. CILACAP', 'nama_kecamatan' => 'MAJENANG', 'nama_desa' => 'PAHONJEAN'] + $formAlamat);
$cek($satu('SELECT provinsi, desa FROM psb_pendaftar WHERE id = ?', [$idRa]) == ['provinsi' => 'JAWA TENGAH', 'desa' => 'PAHONJEAN'], 'H8-H ubah alamat dengan wilayah baru');
$formAlamat = $ubahDari($kRa->req('/portal_pendaftar.php')['body'], 'alamat');
$kRa->req('/portal_pendaftar.php', ['alamat_jalan' => 'Jl. T8 Baru'] + $formAlamat);
$cek($satu('SELECT provinsi, alamat_jalan FROM psb_pendaftar WHERE id = ?', [$idRa]) == ['provinsi' => 'JAWA TENGAH', 'alamat_jalan' => 'Jl. T8 Baru'], 'H8-H wilayah kosong mempertahankan wilayah sekarang');
// Unggah per dokumen
$tmp = sys_get_temp_dir() . '/kt8-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700);
$img = imagecreatetruecolor(30, 20);
imagefill($img, 0, 0, imagecolorallocate($img, 20, 120, 60));
imagepng($img, $tmp . '/kk.png');
file_put_contents($tmp . '/besar.png', (string) file_get_contents($tmp . '/kk.png') . str_repeat("\0", 2 * 1024 * 1024));
$r = $kSmp->req('/proses_upload_berkas.php', ['_csrf' => $kSmp->csrf('/portal_pendaftar.php'), 'upload' => '1', 'file_kk' => new CURLFile($tmp . '/kk.png', 'image/png', 'kk.png')]);
$p = $kSmp->req('/portal_pendaftar.php');
$cek(str_contains((string) $r['location'], 'portal_pendaftar.php#berkas') && str_contains($p['body'], 'Berkas berhasil diperbarui: Kartu keluarga')
    && preg_match('/id="berkas-file_kk".*?Terunggah.*?s-upload__pesan--ok[^>]*>Tersimpan: Kartu keluarga\./s', $p['body']) === 1
    && str_contains($p['body'], 'href="/lihat_berkas_psb.php?jenis=file_kk"') && str_contains($p['body'], 'data-s-bagian-awal="berkas"'), 'H8-H unggah satu dokumen: pesan di baris itu, tautan lihat berpenjaga');
$kSmp->req('/proses_upload_berkas.php', ['_csrf' => $kSmp->csrf('/portal_pendaftar.php'), 'upload' => '1', 'file_akta' => new CURLFile($tmp . '/besar.png', 'image/png', 'besar.png')]);
$p = $kSmp->req('/portal_pendaftar.php');
$cek(preg_match('/id="berkas-file_akta".*?s-upload__pesan--galat[^>]*>Belum tersimpan: Akta kelahiran: ukuran paling besar 2 MB\./s', $p['body']) === 1
    && $satu('SELECT file_akta FROM psb_pendaftar WHERE id = ?', [$idSmp])['file_akta'] === null, 'H8-H berkas ditolak: galat di baris berkas itu, tidak tersimpan');
foreach (glob($tmp . '/*') ?: [] as $f) {
    @unlink($f);
}
@rmdir($tmp);
// Pembiayaan di portal
$b = $kSmp->req('/biaya_psb.php');
$cek($b['status'] === 200 && str_contains($b['body'], 'class="s-ptop"') && preg_match('/href="\/biaya_psb.php" aria-current="page">Pembiayaan/', $b['body']) === 1
    && str_contains($b['body'], 'name="wajib[]"') && str_contains($b['body'], 'data-s-total') && !preg_match('/<form[^>]*target=/', $b['body']), 'H8-H pembiayaan di alamat lama, dalam kerangka portal (menu Pembiayaan aktif), tanpa tab baru');
$a = $anon->req('/biaya_psb.php');
$cek(!str_contains($a['body'], 'class="s-ptop"') && str_contains($a['body'], 'Masuk Portal Pendaftar') && !str_contains($a['body'], 'name="wajib[]"'), 'H8-H pembiayaan anonim hanya ajakan masuk');
$q("UPDATE psb_pendaftar SET status = 'Diterima' WHERE id = ?", [$idSmp]);
$p = $kSmp->req('/portal_pendaftar.php');
$cek(str_contains($p['body'], '>Diterima</span>') && str_contains($p['body'], 'Buka pembiayaan'), 'H8-H status Diterima mengarahkan ke pembiayaan');
$q("UPDATE psb_pendaftar SET status = 'Ditolak' WHERE id = ?", [$idSmp]);
$cek(str_contains($kSmp->req('/portal_pendaftar.php')['body'], 'Tidak diterima'), 'H8-H status Ditolak tampil berkata, bukan warna saja');

// --- H8-I. Cetak ------------------------------------------------------------------------------------
$bukti = $kSmp->req('/cetak_bukti.php?noreg=KT8-0022');
$cek($bukti['status'] === 200 && str_contains($bukti['body'], '<header class="identity"><h1>Pesantren Al Hasan</h1><h2>Kartu bukti pendaftaran') && str_contains($bukti['body'], 'class="report-nav"')
    && str_contains($bukti['body'], 'Panitia PSB') && !str_contains($bukti['body'], 'Times New Roman') && !str_contains($bukti['body'], 'logo_alhasan.png'), 'H8-I bukti pendaftaran memakai kop X-1');
$kwitansiNo = 'KT8-0022';
$kSmp->req('/biaya_psb.php', ['_csrf' => $kSmp->csrf('/biaya_psb.php'), 'simpan_cetak' => '1', 'no_pendaftaran' => $kwitansiNo, 'syahriyyah' => '800000', 'infaq' => '0',
    'seragam_psas' => '0', 'seragam_pramuka' => '0', 'metode_pembayaran' => 'Transfer', 'wajib' => ['Kitab']]);
$kw = $kSmp->req('/cetak_biaya_psb.php?noreg=' . $kwitansiNo);
$cek($kw['status'] === 200 && str_contains($kw['body'], '<header class="identity"><h1>Pesantren Al Hasan</h1><h2>Kwitansi pembayaran awal santri baru') && str_contains($kw['body'], 'Bendahara penerimaan')
    && str_contains($kw['body'], '1046-322-987') && stripos($kw['headers'], 'no-store') !== false, 'H8-I kwitansi memakai kop X-1, penanda tangan tetap');
$cek($anon->req('/cetak_bukti.php?noreg=KT8-0022')['status'] === 403 && $kRa->req('/cetak_biaya_psb.php?noreg=KT8-0022')['status'] === 403, 'H8-I bukti/kwitansi tetap tertutup bagi anonim dan pendaftar lain');

// --- H8-J. Pratinjau admin (AG) -----------------------------------------------------------------------
$admin = new Klien8($base);
$admin->staf('sbx_admin');
$r = $admin->req('/admin/admin_data.php?id=' . $idSmp . '&bagian=berkas');
$src = '/lihat_berkas_psb.php?id=' . $idSmp . '&amp;jenis=file_kk';
$cek($r['status'] === 200 && str_contains($r['body'], '<img src="' . $src . '" alt="Pratinjau Kartu keluarga"') && !preg_match('#src="[^"]*/berkas_psb/#', $r['body'])
    && str_contains($r['body'], 'Belum ada'), 'H8-J panel Pendaftar menampilkan pratinjau lewat jalur berpenjaga');
$gambarAdmin = $admin->req(html_entity_decode($src));
$guru = new Klien8($base);
$guru->staf('sbx_guru_biasa');
$cek($gambarAdmin['status'] === 200 && str_contains($gambarAdmin['type'], 'image/png') && $guru->req(html_entity_decode($src))['status'] === 403
    && $anon->req(html_entity_decode($src))['status'] === 401 && $kRa->req(html_entity_decode($src))['status'] === 403, 'H8-J gambar pratinjau: admin 200, guru 403, anonim 401, pendaftar lain 403');

// --- H8-K. Log ----------------------------------------------------------------------------------------
$log = dirname($web) . '/run/php-error.log';
$isiLog = is_file($log) ? (string) file_get_contents($log) : '';
$cek(!preg_match('/PHP (Warning|Fatal|Parse|Deprecated|Notice)/', $isiLog), 'H8-K tanpa galat/peringatan PHP di log server');
foreach (glob($web . '/storage/private/psb/kk_*') ?: [] as $berkas) {
    @unlink($berkas);
}

echo PHP_EOL . 'Hasil: ' . $lulus . ' lulus, ' . count($gagal) . ' gagal' . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
