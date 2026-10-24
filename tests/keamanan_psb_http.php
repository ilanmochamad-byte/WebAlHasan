<?php

declare(strict_types=1);

/**
 * Uji server workstream keamanan PSB (Apache + php-fpm, `.htaccess` aktif).
 *
 *   KPSB_DIR=/private/tmp/<privat> bash tests/keamanan_psb_server.sh start
 *   KEAMANAN_PSB_RUN_TESTS=1 KPSB_URL=http://127.0.0.1:8971 \
 *     KPSB_WEB_ROOT=/private/tmp/<privat>/web php tests/keamanan_psb_http.php
 *
 * Kelompok:
 *   A  anonim ditolak (portal, biaya, kwitansi, bukti, berkas, unggah, folder berkas)
 *   B  masuk pendaftar: POST + CSRF, No. HP salah/kosong, regenerasi sesi, keluar
 *   C  pembatasan percobaan gagal per NISN dan per IP
 *   D  isolasi antarpendaftar (baca dan tulis)
 *   E  sesi lama berakhir setelah No. HP diganti di sesi lain / pendaftar dihapus
 *   F  validasi unggahan dan penyimpanan privat
 *   G  jalur berkas berpenjaga (pemilik, admin, staf lain, metode, berkas lama)
 *   H  rincian biaya dihitung server, terkunci setelah lunas, admin tetap bisa
 *   I  pendaftaran publik: metode, CSRF, validasi, hak cetak bukti
 *   J  halaman admin PSB memakai jalur berpenjaga; keluaran di-escape
 *   K  tidak ada galat PHP di log server
 *
 * Seluruh data sintetis (NISN 99000000xx, nomor KPSB-T-xxxx). DB wajib test_*_test.
 */

if (getenv('KEAMANAN_PSB_RUN_TESTS') !== '1') {
    fwrite(STDERR, "Set KEAMANAN_PSB_RUN_TESTS=1 untuk menjalankan.\n");
    exit(2);
}
$base = rtrim((string) getenv('KPSB_URL'), '/');
$web = rtrim((string) getenv('KPSB_WEB_ROOT'), '/');
$db = getenv('KPSB_DB') ?: 'test_keamanan_psb_test';
if (!preg_match('#^http://127\.0\.0\.1:\d+$#', $base) || $web === '' || !is_dir($web) || file_exists($web . '/.git')
    || !preg_match('/^test_.*_test$/', $db)) {
    fwrite(STDERR, "Ditolak: URL harus 127.0.0.1, KPSB_WEB_ROOT salinan tanpa .git, DB test_*_test.\n");
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

final class Klien
{
    private string $jar;

    public function __construct(private string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'kpsb-jar-');
    }

    public function __destruct()
    {
        @unlink($this->jar);
    }

    /** @return array{status:int, body:string, location:?string, type:string, headers:string} */
    public function req(string $path, ?array $post = null, string $method = ''): array
    {
        $h = curl_init($this->base . $path);
        curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 60]);
        if ($method !== '') {
            curl_setopt($h, CURLOPT_CUSTOMREQUEST, $method);
            if ($method === 'HEAD') {
                curl_setopt($h, CURLOPT_NOBODY, true);
            }
        }
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

    public function csrf(string $path = '/login_pendaftar.php'): string
    {
        $r = $this->req($path);
        if (preg_match('/name="_csrf" value="([^"]+)"/', $r['body'], $m) !== 1) {
            throw new RuntimeException('Token CSRF tidak ditemukan pada ' . $path);
        }

        return $m[1];
    }

    public function masuk(string $nisn, string $hp): array
    {
        return $this->req('/auth_pendaftar.php', ['_csrf' => $this->csrf(), 'nisn' => $nisn, 'hp' => $hp]);
    }

    public function staf(string $username): void
    {
        $this->req('/admin/cek_login.php', ['_csrf' => $this->csrf('/portal/index.php'), 'username' => $username, 'password' => 'Sandbox#123']);
    }

    public function cookie(): string
    {
        foreach (file($this->jar) ?: [] as $baris) {
            $kolom = explode("\t", trim($baris));
            if (count($kolom) === 7) {
                return $kolom[6];
            }
        }

        return '';
    }
}

// --- Fixture sintetis -------------------------------------------------------
$q("DELETE FROM psb_pembayaran WHERE no_pendaftaran LIKE 'KPSB-%' OR no_pendaftaran IN (SELECT no_pendaftaran FROM psb_pendaftar WHERE nisn LIKE '99000000%')");
$q("DELETE FROM psb_pendaftar WHERE no_pendaftaran LIKE 'KPSB-%' OR nisn LIKE '99000000%'");
$q("DELETE FROM audit_logs WHERE action LIKE 'psb.login%'");
$fixture = [
    'A' => ['KPSB-T-0001', 'Pendaftar Uji A', '9900000001', '081200000001', 'SMP Terpadu Al Hasan', 'L'],
    'B' => ['KPSB-T-0002', 'Pendaftar Uji B', '9900000002', '081200000002', 'SMK Terpadu Al Hasan', 'P'],
    'C' => ['KPSB-T-0003', 'Pendaftar Uji C', '9900000003', '081200000003', 'RA Terpadu Al Hasan', 'P'],
    'D' => ['KPSB-T-0004', 'Pendaftar Uji D', '9900000004', '', 'SMP Terpadu Al Hasan', 'L'],
    'E' => ['KPSB-T-0005', 'Pendaftar Uji E', '9900000005', '081200000005', 'SMP Terpadu Al Hasan', 'L'],
    'X' => ['KPSB-T-0006', 'Uji <i>KPSB</i> & "kutip"', '9900000006', '081200000006', 'SMP Terpadu Al Hasan', 'P'],
];
$id = [];
foreach ($fixture as $k => [$no, $nama, $nisn, $hp, $unit, $jk]) {
    $q('INSERT INTO psb_pendaftar (no_pendaftaran, nama_lengkap, nisn, nik, tempat_lahir, tgl_lahir, jenis_kelamin, alamat_jalan, desa, kecamatan, kab_kota, provinsi, nama_ayah, nama_ibu, no_hp_wali, sekolah_asal, jenjang_tujuan, status)
        VALUES (?, ?, ?, ?, "Ciamis", "2012-05-01", ?, "Jl. Uji", "Desa Uji", "Kec Uji", "Kab Uji", "Prov Uji", "Ayah Uji", "Ibu Uji", ?, "SD Uji", ?, "Baru")',
        [$no, $nama, $nisn, '32070000000000' . substr($nisn, -2), $jk, $hp, $unit]);
    $id[$k] = (int) $satu('SELECT id FROM psb_pendaftar WHERE no_pendaftaran = ?', [$no])['id'];
}
$q("INSERT INTO psb_pembayaran (no_pendaftaran, kategori_biaya, syahriyyah, infaq, rincian_wajib, total_wajib, total_keseluruhan, metode_pembayaran)
    VALUES ('KPSB-T-0002', 'SMK Terpadu Al Hasan (Baru)', 800000, 1000000, '{\"Kitab\":270000}', 270000, 2070000, 'Cash')");
$pembayaranB = static fn () => $satu("SELECT syahriyyah, infaq, rincian_wajib, total_wajib, total_keseluruhan, status_pembayaran FROM psb_pembayaran WHERE no_pendaftaran='KPSB-T-0002'");
$bayarBAwal = $pembayaranB();

// Berkas lama sintetis (pola nama lama) di folder berkas_psb/ salinan.
$png = static function (int $w = 40, int $h = 30): string {
    $img = imagecreatetruecolor($w, $h);
    imagefill($img, 0, 0, imagecolorallocate($img, 20, 120, 60));
    ob_start();
    imagepng($img);

    return (string) ob_get_clean();
};
$tmp = sys_get_temp_dir() . '/kpsb-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700);
register_shutdown_function(static function () use ($tmp): void {
    foreach (glob($tmp . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($tmp);
});
$lamaNama = 'file_kk_' . $id['A'] . '_1700000000.png';
file_put_contents($web . '/berkas_psb/' . $lamaNama, $png());
$q('UPDATE psb_pendaftar SET file_kk = ? WHERE id = ?', [$lamaNama, $id['A']]);
$lamaB = 'file_akta_' . $id['B'] . '_1700000000.png';
file_put_contents($web . '/berkas_psb/' . $lamaB, $png());
$q('UPDATE psb_pendaftar SET file_akta = ? WHERE id = ?', [$lamaB, $id['B']]);
$teks = static fn (string $html): string => trim(html_entity_decode((string) preg_replace('/\s+/', ' ', (string) preg_replace('/<[^>]+>/', ' ', (string) preg_replace('#<(script|style)\b.*?</\1>#s', '', $html))), ENT_QUOTES));

// --- A. Anonim ----------------------------------------------------------------
$anon = new Klien($base);
$r = $anon->req('/portal_pendaftar.php');
$cek($r['status'] === 302 && str_contains((string) $r['location'], 'login_pendaftar.php'), 'A1 portal anonim diarahkan ke halaman masuk');
$r = $anon->req('/biaya_psb.php?cari=KPSB-T-0001');
$cek($r['status'] === 200 && !str_contains($r['body'], 'Pendaftar Uji A') && str_contains($r['body'], 'Masuk Portal Pendaftar'), 'A2 rincian biaya anonim tanpa data pendaftar');
$r = $anon->req('/biaya_psb.php?cari=' . '32070000000001');
$cek(!str_contains($r['body'], 'Pendaftar Uji A'), 'A3 pencarian NIK anonim tanpa data');
foreach (['/cetak_bukti.php?noreg=KPSB-T-0001', '/cetak_biaya_psb.php?noreg=KPSB-T-0002', '/cetak_bukti.php?noreg=TIDAK-ADA'] as $path) {
    $r = $anon->req($path);
    $cek($r['status'] === 403 && !str_contains($r['body'], 'Pendaftar Uji'), 'A4 ' . $path . ' anonim 403 tanpa data');
}
$r = $anon->req('/lihat_berkas_psb.php?id=' . $id['A'] . '&jenis=file_kk');
$cek($r['status'] === 401 && !str_starts_with($r['body'], "\x89PNG"), 'A5 jalur berkas anonim 401');
$r = $anon->req('/berkas_psb/' . $lamaNama);
$cek($r['status'] === 403, 'A6 folder berkas_psb/ ditolak langsung oleh .htaccess');
$r = $anon->req('/berkas_psb/');
$cek($r['status'] === 403, 'A7 daftar isi berkas_psb/ ditolak');
$sebelumFile = count(glob($web . '/storage/private/psb/*') ?: []) + count(glob($web . '/berkas_psb/*') ?: []);
$f = $tmp . '/a.png';
file_put_contents($f, $png());
$r = $anon->req('/proses_upload_berkas.php', ['upload' => '1', 'file_ktp' => new CURLFile($f, 'image/png', 'a.png')]);
$sesudahFile = count(glob($web . '/storage/private/psb/*') ?: []) + count(glob($web . '/berkas_psb/*') ?: []);
$cek($r['status'] === 302 && str_contains((string) $r['location'], 'login_pendaftar.php') && $sebelumFile === $sesudahFile, 'A8 unggah anonim ditolak tanpa berkas tersimpan');
$r = $anon->req('/logout_pendaftar.php');
$cek($r['status'] === 200 && str_contains($r['body'], 'name="_csrf"'), 'A9 GET keluar hanya menampilkan konfirmasi');

// --- B. Masuk -------------------------------------------------------------------
$k = new Klien($base);
$r = $k->req('/auth_pendaftar.php');
$cek($r['status'] === 302 && str_contains((string) $r['location'], 'login_pendaftar.php'), 'B1 GET penangan masuk tidak memproses');
$r = $k->req('/auth_pendaftar.php', ['nisn' => '9900000001', 'hp' => '081200000001']);
$cek(str_contains((string) $r['location'], 'pesan=token') && $k->req('/portal_pendaftar.php')['status'] === 302, 'B2 masuk tanpa CSRF ditolak');
$r = $k->masuk('9900000001', '081299999999');
$cek(str_contains((string) $r['location'], 'pesan=gagal') && $k->req('/portal_pendaftar.php')['status'] === 302, 'B3 No. HP salah ditolak');
$r = $k->masuk('9900000004', '');
$cek(str_contains((string) $r['location'], 'pesan=gagal'), 'B4 pendaftar ber-No. HP kosong tidak dapat masuk dengan isian kosong');
$sebelum = $k->cookie();
$r = $k->masuk('9900000001', '081200000001');
$sesudah = $k->cookie();
$cek(str_contains((string) $r['location'], 'portal_pendaftar.php') && $sebelum !== '' && $sesudah !== '' && $sebelum !== $sesudah, 'B5 masuk sah dengan ID sesi baru');
$r = $k->req('/portal_pendaftar.php');
$cek($r['status'] === 200 && str_contains($r['body'], 'Pendaftar Uji A') && substr_count($r['body'], 'name="_csrf"') >= 3, 'B6 portal tampil dengan token di formulir biodata, unggah, keluar');
$cek($satu("SELECT COUNT(*) n FROM audit_logs WHERE action='psb.login_succeeded' AND entity_id=?", [$id['A']])['n'] >= 1
    && $satu("SELECT COUNT(*) n FROM audit_logs WHERE action LIKE 'psb.login%' AND after_json LIKE '%9900000001%'")['n'] == 0, 'B7 audit masuk tanpa NISN mentah');
$kLuar = new Klien($base);
$kLuar->masuk('9900000005', '081200000005');
$r = $kLuar->req('/logout_pendaftar.php', ['x' => '1']);
$cek($r['status'] === 419 && $kLuar->req('/portal_pendaftar.php')['status'] === 200, 'B8 keluar tanpa CSRF tidak mengakhiri sesi');
$r = $kLuar->req('/logout_pendaftar.php', ['_csrf' => $kLuar->csrf('/portal_pendaftar.php')]);
$cek(str_contains((string) $r['location'], 'pesan=keluar') && $kLuar->req('/portal_pendaftar.php')['status'] === 302, 'B9 keluar POST ber-CSRF mengakhiri sesi');

// --- C. Pembatasan percobaan ------------------------------------------------------
$q("DELETE FROM audit_logs WHERE action = 'psb.login_failed'");
$kc = new Klien($base);
for ($i = 0; $i < App\Psb\PendaftarSesi::BATAS_NISN; $i++) {
    $kc->masuk('9900000005', '0000000' . $i);
}
$r = $kc->masuk('9900000005', '081200000005');
$cek(str_contains((string) $r['location'], 'pesan=terkunci') && $kc->req('/portal_pendaftar.php')['status'] === 302, 'C1 NISN terkunci setelah ' . App\Psb\PendaftarSesi::BATAS_NISN . ' gagal, walau kredensial benar');
$r = (new Klien($base))->masuk('9900000001', '081200000001');
$cek(str_contains((string) $r['location'], 'portal_pendaftar.php'), 'C2 NISN lain dari IP sama tetap dapat masuk di bawah batas IP');
$q("DELETE FROM audit_logs WHERE action = 'psb.login_failed'");
for ($i = 0; $i < App\Psb\PendaftarSesi::BATAS_IP; $i++) {
    $q("INSERT INTO audit_logs (action, entity_type, after_json, ip_address, created_at) VALUES ('psb.login_failed', 'psb_pendaftar', ?, '127.0.0.1', NOW())", ['{"kunci":"uji' . $i . '"}']);
}
$r = (new Klien($base))->masuk('9900000001', '081200000001');
$cek(str_contains((string) $r['location'], 'pesan=terkunci'), 'C3 IP terkunci setelah ' . App\Psb\PendaftarSesi::BATAS_IP . ' gagal');
$q("DELETE FROM audit_logs WHERE action = 'psb.login_failed'");

// --- D. Isolasi antarpendaftar ----------------------------------------------------
$r = $k->req('/biaya_psb.php?cari=KPSB-T-0002');
$cek(!str_contains($r['body'], 'Pendaftar Uji B') && str_contains($r['body'], 'rincian biaya pendaftaran Anda sendiri'), 'D1 rincian biaya pendaftar lain ditolak');
$r = $k->req('/biaya_psb.php?cari=32070000000002');
$cek(!str_contains($r['body'], 'Pendaftar Uji B'), 'D2 pencarian NIK pendaftar lain ditolak');
$cek($k->req('/cetak_bukti.php?noreg=KPSB-T-0002')['status'] === 403, 'D3 bukti pendaftar lain 403');
$cek($k->req('/cetak_biaya_psb.php?noreg=KPSB-T-0002')['status'] === 403, 'D4 kwitansi pendaftar lain 403');
$r = $k->req('/lihat_berkas_psb.php?id=' . $id['B'] . '&jenis=file_akta');
$cek($r['status'] === 403 && !str_starts_with($r['body'], "\x89PNG"), 'D5 berkas pendaftar lain 403');
$r = $k->req('/biaya_psb.php', ['_csrf' => $k->csrf('/biaya_psb.php'), 'simpan_cetak' => '1', 'no_pendaftaran' => 'KPSB-T-0002',
    'syahriyyah' => '0', 'infaq' => '0', 'seragam_psas' => '0', 'seragam_pramuka' => '0', 'metode_pembayaran' => 'Cash']);
$cek($r['status'] === 403 && $pembayaranB() == $bayarBAwal, 'D6 simpan rincian biaya pendaftar lain ditolak, tagihan tetap');
$sebelumB = $satu('SELECT * FROM psb_pendaftar WHERE id = ?', [$id['B']]);
$biodata = ['_csrf' => $k->csrf('/portal_pendaftar.php'), 'update_data' => '1', 'id' => (string) $id['B'], 'nama_lengkap' => 'Pendaftar Uji A2',
    'nik' => '3207000000000001', 'tempat_lahir' => 'Ciamis', 'tgl_lahir' => '2012-05-01', 'jenis_kelamin' => 'L', 'jenjang_tujuan' => 'SMP Terpadu Al Hasan',
    'alamat_jalan' => 'Jl. Uji', 'nama_ayah' => 'Ayah Uji', 'nama_ibu' => 'Ibu Uji', 'no_hp_wali' => '081200000001', 'sekolah_asal' => 'SD Uji'];
$r = $k->req('/portal_pendaftar.php', $biodata);
$cek($r['status'] === 302 && $satu('SELECT nama_lengkap FROM psb_pendaftar WHERE id = ?', [$id['A']])['nama_lengkap'] === 'Pendaftar Uji A2'
    && $satu('SELECT * FROM psb_pendaftar WHERE id = ?', [$id['B']]) == $sebelumB, 'D7 ubah biodata hanya baris sendiri (id kiriman diabaikan)');
$r = $k->req('/portal_pendaftar.php', ['_csrf' => 'salah', 'nama_lengkap' => 'Tanpa Token'] + $biodata);
$cek($satu('SELECT nama_lengkap FROM psb_pendaftar WHERE id = ?', [$id['A']])['nama_lengkap'] === 'Pendaftar Uji A2', 'D8 ubah biodata tanpa CSRF sah ditolak');
$r = $k->req('/portal_pendaftar.php', ['jenis_kelamin' => 'Z', 'tgl_lahir' => '2012-13-40', 'no_hp_wali' => 'abc'] + $biodata);
$cek($satu('SELECT jenis_kelamin, no_hp_wali FROM psb_pendaftar WHERE id = ?', [$id['A']]) == ['jenis_kelamin' => 'L', 'no_hp_wali' => '081200000001'], 'D9 isian tidak sah ditolak server');

// --- E. Sesi lama setelah akses dicabut -------------------------------------------
$k2 = new Klien($base);
$k2->masuk('9900000001', '081200000001');
$cek($k2->req('/portal_pendaftar.php')['status'] === 200, 'E0 sesi kedua pendaftar A aktif');
$r = $k->req('/portal_pendaftar.php', ['_csrf' => $k->csrf('/portal_pendaftar.php'), 'no_hp_wali' => '081200000011'] + $biodata);
$r1 = $k->req('/portal_pendaftar.php');
$r2 = $k2->req('/portal_pendaftar.php');
$cek($r1['status'] === 200 && $r2['status'] === 302 && str_contains((string) $r2['location'], 'pesan=sesi'), 'E1 ganti No. HP: sesi ini tetap, sesi lain berakhir');
$cek($k2->req('/lihat_berkas_psb.php?jenis=file_kk')['status'] === 401 && $k2->req('/cetak_bukti.php?noreg=KPSB-T-0001')['status'] === 403, 'E2 sesi lama tidak lagi membuka berkas/bukti');
$cek(str_contains((string) (new Klien($base))->masuk('9900000001', '081200000001')['location'], 'pesan=gagal')
    && str_contains((string) (new Klien($base))->masuk('9900000001', '081200000011')['location'], 'portal_pendaftar.php'), 'E3 No. HP lama tidak berlaku, No. HP baru berlaku');
$kC = new Klien($base);
$kC->masuk('9900000003', '081200000003');
$cek($kC->req('/portal_pendaftar.php')['status'] === 200, 'E4 sesi pendaftar C aktif');
$admin = new Klien($base);
$admin->staf('sbx_admin');
$r = $admin->req('/admin/hapus_siswa.php', ['_csrf' => $admin->csrf('/admin/admin_data.php'), 'id' => (string) $id['C']]);
$r = $kC->req('/portal_pendaftar.php');
$cek($satu('SELECT id FROM psb_pendaftar WHERE id = ?', [$id['C']]) === null && $r['status'] === 302 && str_contains((string) $r['location'], 'pesan=sesi'), 'E5 pendaftar dihapus admin: sesinya berakhir');
$cek($kC->req('/biaya_psb.php')['status'] === 200 && !str_contains($kC->req('/biaya_psb.php')['body'], 'Pendaftar Uji C'), 'E6 sesi terhapus tidak membuka rincian biaya');

// --- F. Unggahan ------------------------------------------------------------------
$unggah = static function (Klien $kl, array $files, ?string $token = null): array {
    $post = ['_csrf' => $token ?? $kl->csrf('/portal_pendaftar.php'), 'upload' => '1'] + $files;
    $kl->req('/proses_upload_berkas.php', $post);

    return $kl->req('/portal_pendaftar.php');
};
$privat = static fn (): array => glob($web . '/storage/private/psb/*.{png,jpg,pdf}', GLOB_BRACE) ?: [];
$jumlahPrivat = count($privat());
file_put_contents($tmp . '/ktp.png', $png());
$r = $unggah($k, ['file_ktp' => new CURLFile($tmp . '/ktp.png', 'image/png', 'ktp.png')]);
$ktp = (string) $satu('SELECT file_ktp FROM psb_pendaftar WHERE id = ?', [$id['A']])['file_ktp'];
$cek(preg_match('/^ktp_[0-9a-f]{32}\.png$/', $ktp) === 1 && is_file($web . '/storage/private/psb/' . $ktp) && !is_file($web . '/berkas_psb/' . $ktp), 'F1 PNG sah tersimpan privat dengan nama acak');
$cek(str_contains($r['body'], 'Berkas berhasil diperbarui'), 'F2 pesan hasil unggah tampil');
$cek(is_file($web . '/storage/private/psb/.htaccess') && $anon->req('/storage/private/psb/' . $ktp)['status'] === 403, 'F3 folder privat ditolak web (.htaccess akar + folder)');
file_put_contents($tmp . '/palsu.jpg', "bukan gambar\n" . str_repeat('x', 100));
file_put_contents($tmp . '/palsu.pdf', "bukan pdf\n");
file_put_contents($tmp . '/besar.png', $png(10, 10) . str_repeat("\0", 2 * 1024 * 1024));
file_put_contents($tmp . '/skrip.php', $png());
file_put_contents($tmp . '/pdfsah.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
foreach ([
    ['file_kk', 'palsu.jpg', 'isi bukan gambar berekstensi jpg'],
    ['file_akta', 'palsu.pdf', 'isi bukan PDF berekstensi pdf'],
    ['file_ijazah', 'besar.png', 'lebih dari 2 MB'],
    ['file_nilai', 'skrip.php', 'ekstensi di luar daftar'],
] as [$kolom, $nama, $label]) {
    $sebelum = $satu('SELECT ' . $kolom . ' AS v FROM psb_pendaftar WHERE id = ?', [$id['A']])['v'];
    $r = $unggah($k, [$kolom => new CURLFile($tmp . '/' . $nama, 'application/octet-stream', $nama)]);
    $cek($satu('SELECT ' . $kolom . ' AS v FROM psb_pendaftar WHERE id = ?', [$id['A']])['v'] === $sebelum && str_contains($r['body'], 'Belum tersimpan'), 'F4 ditolak: ' . $label);
}
$cek(count($privat()) === $jumlahPrivat + 1, 'F5 berkas tertolak tidak tersimpan di server');
$r = $unggah($k, ['file_prestasi' => new CURLFile($tmp . '/pdfsah.pdf', 'application/pdf', 'sertifikat.pdf')]);
$cek(preg_match('/^prestasi_[0-9a-f]{32}\.pdf$/', (string) $satu('SELECT file_prestasi FROM psb_pendaftar WHERE id = ?', [$id['A']])['file_prestasi']) === 1, 'F6 PDF sah diterima');
$sebelumNilai = $satu('SELECT file_nilai FROM psb_pendaftar WHERE id = ?', [$id['A']])['file_nilai'];
$unggah($k, ['file_nilai' => new CURLFile($tmp . '/ktp.png', 'image/png', 'n.png')], 'token-salah');
$cek($satu('SELECT file_nilai FROM psb_pendaftar WHERE id = ?', [$id['A']])['file_nilai'] === $sebelumNilai, 'F7 unggah tanpa CSRF sah ditolak');
$cek($satu('SELECT file_ktp FROM psb_pendaftar WHERE id = ?', [$id['B']])['file_ktp'] === null, 'F8 unggahan tidak menyentuh pendaftar lain');

// --- G. Jalur berkas berpenjaga ---------------------------------------------------------
$r = $k->req('/lihat_berkas_psb.php?jenis=file_ktp');
$cek($r['status'] === 200 && str_starts_with($r['body'], "\x89PNG") && str_contains($r['type'], 'image/png')
    && stripos($r['headers'], 'X-Content-Type-Options: nosniff') !== false && stripos($r['headers'], 'no-store') !== false, 'G1 pemilik membuka berkasnya (image/png, nosniff, no-store)');
$r = $k->req('/lihat_berkas_psb.php?jenis=file_kk');
$cek($r['status'] === 200 && str_starts_with($r['body'], "\x89PNG"), 'G2 berkas lama di berkas_psb/ dibaca lewat jalur berpenjaga');
$r = $k->req('/lihat_berkas_psb.php?jenis=file_prestasi&unduh=1');
$cek($r['status'] === 200 && str_contains($r['type'], 'application/pdf') && stripos($r['headers'], 'attachment;') !== false, 'G3 unduh PDF sebagai lampiran');
$cek($k->req('/lihat_berkas_psb.php?jenis=file_ktp', null, 'HEAD')['status'] === 200, 'G4 HEAD diizinkan');
$r = $k->req('/lihat_berkas_psb.php?jenis=file_ktp', ['x' => '1']);
$cek($r['status'] === 405 && stripos($r['headers'], 'Allow: GET, HEAD') !== false, 'G5 POST ke jalur berkas 405');
$cek($k->req('/lihat_berkas_psb.php?jenis=password')['status'] === 404 && $k->req('/lihat_berkas_psb.php?jenis=file_ijazah')['status'] === 404, 'G6 jenis tidak dikenal/kosong 404');
$cek($k->req('/lihat_berkas_psb.php?id=abc&jenis=file_ktp')['status'] === 403, 'G7 id tidak sah dari pendaftar 403');
$r = $admin->req('/lihat_berkas_psb.php?id=' . $id['B'] . '&jenis=file_akta');
$cek($r['status'] === 200 && str_starts_with($r['body'], "\x89PNG"), 'G8 admin membuka berkas pendaftar mana pun');
$cek($admin->req('/lihat_berkas_psb.php?id=999999999&jenis=file_akta')['status'] === 404, 'G9 admin: pendaftar tidak ada 404');
$guru = new Klien($base);
$guru->staf('sbx_guru_biasa');
$cek($guru->req('/lihat_berkas_psb.php?id=' . $id['B'] . '&jenis=file_akta')['status'] === 403, 'G10 staf bukan admin 403');
$q('UPDATE psb_pendaftar SET file_nilai = ? WHERE id = ?', ['../.env', $id['E']]);
$kE = new Klien($base);
$kE->masuk('9900000005', '081200000005');
$cek($kE->req('/lihat_berkas_psb.php?jenis=file_nilai')['status'] === 404, 'G11 nama berkas tak wajar di basis data tidak keluar folder');
file_put_contents($web . '/berkas_psb/file_ijazah_' . $id['E'] . '_1.pdf', "<html><body>bukan pdf</body></html>");
$q('UPDATE psb_pendaftar SET file_ijazah = ? WHERE id = ?', ['file_ijazah_' . $id['E'] . '_1.pdf', $id['E']]);
$r = $kE->req('/lihat_berkas_psb.php?jenis=file_ijazah');
$cek($r['status'] === 200 && str_contains($r['type'], 'application/octet-stream') && stripos($r['headers'], 'attachment;') !== false, 'G12 berkas lama berisi bukan JPG/PNG/PDF hanya diunduh sebagai lampiran');

// --- H. Rincian biaya --------------------------------------------------------------------
$r = $k->req('/biaya_psb.php?cari=KPSB-T-0001');
$cek($r['status'] === 200 && str_contains($r['body'], 'Pendaftar Uji A2') && str_contains($r['body'], 'name="wajib[]"') && str_contains($r['body'], 'name="_csrf"'), 'H1 pemilik melihat rinciannya dengan formulir ber-CSRF');
$katalog = App\Psb\BiayaPsb::katalog($satu('SELECT * FROM psb_pendaftar WHERE id = ?', [$id['A']]));
$r = $k->req('/biaya_psb.php?cari=KPSB-T-0001', ['_csrf' => $k->csrf('/biaya_psb.php'), 'simpan_cetak' => '1', 'no_pendaftaran' => 'KPSB-T-0001',
    'kategori_biaya' => 'Kategori Karangan', 'syahriyyah' => '800000', 'infaq' => '500000', 'seragam_psas' => '190000', 'seragam_pramuka' => '0',
    'metode_pembayaran' => 'Transfer', 'total_wajib' => '1', 'rincian_wajib_json' => '{"Kitab":1}', 'wajib' => ['Kitab', 'Lemari', 'Komponen Karangan']]);
$bayarA = $satu("SELECT * FROM psb_pembayaran WHERE no_pendaftaran='KPSB-T-0001'");
$harapWajib = $katalog['wajib']['Lemari'] + $katalog['wajib']['Kitab'];
$cek($r['status'] === 303 && str_contains((string) $r['location'], 'cetak_biaya_psb.php?noreg=KPSB-T-0001'), 'H2 simpan mengalihkan ke kwitansi');
$cek($bayarA !== null && $bayarA['kategori_biaya'] === $katalog['kategori'] && (int) $bayarA['total_wajib'] === $harapWajib
    && (int) $bayarA['total_keseluruhan'] === 800000 + 500000 + 190000 + $harapWajib
    && json_decode($bayarA['rincian_wajib'], true) == ['Lemari' => $katalog['wajib']['Lemari'], 'Kitab' => $katalog['wajib']['Kitab']], 'H3 kategori, harga wajib, dan total dihitung server dari katalog');
$r = $k->req('/biaya_psb.php?cari=KPSB-T-0001', ['_csrf' => $k->csrf('/biaya_psb.php'), 'simpan_cetak' => '1', 'no_pendaftaran' => 'KPSB-T-0001',
    'syahriyyah' => '-5', 'infaq' => '1e9', 'seragam_psas' => '0', 'seragam_pramuka' => '0', 'metode_pembayaran' => 'Cash']);
$cek($r['status'] === 200 && $satu("SELECT total_keseluruhan FROM psb_pembayaran WHERE no_pendaftaran='KPSB-T-0001'")['total_keseluruhan'] == $bayarA['total_keseluruhan'], 'H4 nominal tidak sah ditolak');
$r = $k->req('/cetak_biaya_psb.php?noreg=KPSB-T-0001');
$cek($r['status'] === 200 && str_contains($r['body'], 'Lemari') && stripos($r['headers'], 'no-store') !== false, 'H5 pemilik membuka kwitansinya');
$q("UPDATE psb_pembayaran SET status_pembayaran='Lunas', waktu_lunas=NOW() WHERE no_pendaftaran='KPSB-T-0001'");
$r = $k->req('/biaya_psb.php?cari=KPSB-T-0001', ['_csrf' => $k->csrf('/biaya_psb.php'), 'simpan_cetak' => '1', 'no_pendaftaran' => 'KPSB-T-0001',
    'syahriyyah' => '0', 'infaq' => '0', 'seragam_psas' => '0', 'seragam_pramuka' => '0', 'metode_pembayaran' => 'Cash']);
$cek(str_contains($r['body'], 'sudah ditandai lunas') && $satu("SELECT total_keseluruhan FROM psb_pembayaran WHERE no_pendaftaran='KPSB-T-0001'")['total_keseluruhan'] == $bayarA['total_keseluruhan'], 'H6 tagihan lunas tidak dapat diubah dari halaman biaya');
$r = $admin->req('/biaya_psb.php?cari=KPSB-T-0002');
$cek(str_contains($r['body'], 'Pendaftar Uji B') && str_contains($r['body'], 'name="cari"'), 'H7 admin tetap mencari rincian pendaftar mana pun');
$cek($admin->req('/cetak_biaya_psb.php?noreg=KPSB-T-0002')['status'] === 200 && $admin->req('/cetak_bukti.php?noreg=KPSB-T-0002')['status'] === 200, 'H8 admin membuka kwitansi dan bukti');
$cek($guru->req('/cetak_bukti.php?noreg=KPSB-T-0002')['status'] === 403 && !str_contains($guru->req('/biaya_psb.php?cari=KPSB-T-0002')['body'], 'Pendaftar Uji B'), 'H9 staf bukan admin tidak membuka data pendaftar');
$cek($pembayaranB() == $bayarBAwal, 'H10 tagihan pendaftar B tidak berubah sepanjang uji');

// --- I. Pendaftaran publik --------------------------------------------------------------
$q("DELETE FROM psb_pendaftar WHERE nisn IN ('9900000099', '9900000098')");
$daftar = ['daftar' => '1', 'nama_lengkap' => 'Pendaftar Baru Uji', 'nisn' => '9900000099', 'nik' => '3207000000000099', 'tempat_lahir' => 'Ciamis',
    'tgl_lahir' => '2013-02-03', 'jenis_kelamin' => 'P', 'jenjang_tujuan' => 'SMP Terpadu Al Hasan', 'alamat_jalan' => 'Jl. Baru', 'nama_provinsi' => 'JAWA BARAT',
    'nama_kabupaten' => 'KAB. CIAMIS', 'nama_kecamatan' => 'CIAMIS', 'nama_desa' => 'SINDANGRASA', 'nama_ayah' => 'Ayah Baru', 'nama_ibu' => 'Ibu Baru',
    'no_hp_wali' => '081200000099', 'sekolah_asal' => 'SD Baru'];
$pub = new Klien($base);
$cek($pub->req('/proses_psb.php')['status'] === 302, 'I1 GET proses pendaftaran tidak memproses');
$r = $pub->req('/proses_psb.php', $daftar);
$cek(str_contains((string) $r['location'], 'status=token') && $satu("SELECT id FROM psb_pendaftar WHERE nisn='9900000099'") === null, 'I2 pendaftaran tanpa CSRF ditolak');
$r = $pub->req('/proses_psb.php', ['_csrf' => $pub->csrf('/psb.php'), 'jenis_kelamin' => 'X', 'jenjang_tujuan' => 'Unit Karangan', 'tgl_lahir' => '2099-01-01'] + $daftar);
$gagalPage = $pub->req('/psb.php?status=gagal');
$cek(str_contains((string) $r['location'], 'status=gagal') && $satu("SELECT id FROM psb_pendaftar WHERE nisn='9900000099'") === null
    && str_contains($gagalPage['body'], 'Jenis kelamin tidak dikenal'), 'I3 isian tidak sah ditolak dengan pesan');
$r = $pub->req('/proses_psb.php', ['_csrf' => $pub->csrf('/psb.php')] + $daftar);
$baru = $satu("SELECT * FROM psb_pendaftar WHERE nisn='9900000099'");
$cek($baru !== null && $baru['status'] === 'Baru' && preg_match('/^PSB-\d{4}-\d{4}$/', $baru['no_pendaftaran']) === 1
    && str_contains((string) $r['location'], 'noreg=' . $baru['no_pendaftaran']), 'I4 pendaftaran sah tersimpan (status Baru, nomor PSB-tahun-acak)');
$sukses = $pub->req('/psb.php?status=sukses&noreg=' . rawurlencode((string) $baru['no_pendaftaran']));
$cek(str_contains($sukses['body'], 'cetak_bukti.php?noreg=') && $pub->req('/cetak_bukti.php?noreg=' . $baru['no_pendaftaran'])['status'] === 200, 'I5 peramban pendaftar mencetak buktinya');
$lain = new Klien($base);
$cek($lain->req('/cetak_bukti.php?noreg=' . $baru['no_pendaftaran'])['status'] === 403 && !str_contains($lain->req('/psb.php?status=sukses&noreg=' . $baru['no_pendaftaran'])['body'], 'cetak_bukti.php?noreg='), 'I6 peramban lain tidak mendapat hak cetak');
$cek($pub->req('/cetak_bukti.php?noreg=KPSB-T-0002')['status'] === 403, 'I7 hak cetak hanya untuk pendaftaran itu');
$r = $pub->req('/proses_psb.php', ['_csrf' => $pub->csrf('/psb.php')] + $daftar);
$cek(str_contains((string) $r['location'], 'status=gagal') && (int) $satu("SELECT COUNT(*) n FROM psb_pendaftar WHERE nisn='9900000099'")['n'] === 1, 'I8 NISN ganda ditolak');
$r = $pub->req('/psb.php?status=sukses&noreg=' . rawurlencode('<b>x</b>'));
$cek(!str_contains($r['body'], '<b>x</b>'), 'I9 nomor di alamat tidak ditampilkan apa adanya');
$cek(($k3 = new Klien($base)) && str_contains((string) $k3->masuk('9900000099', '081200000099')['location'], 'portal_pendaftar.php'), 'I10 pendaftar baru dapat masuk portal');

// --- J. Admin dan escape --------------------------------------------------------------------
$r = $admin->req('/admin/admin_data.php?id=' . $id['A'] . '&bagian=berkas');
$cek($r['status'] === 200 && str_contains($r['body'], 'lihat_berkas_psb.php?id=' . $id['A'] . '&amp;jenis=file_ktp') && !preg_match('#href="[^"]*/berkas_psb/#', $r['body']), 'J1 panel admin menautkan jalur berpenjaga, bukan folder');
$cek($admin->req('/admin/admin_pembayaran_psb.php')['status'] === 200 && $admin->req('/admin/admin_data.php')['status'] === 200, 'J2 halaman admin PSB tetap 200');
$kX = new Klien($base);
$kX->masuk('9900000006', '081200000006');
foreach (['/portal_pendaftar.php', '/cetak_bukti.php?noreg=KPSB-T-0006', '/biaya_psb.php'] as $path) {
    $body = $kX->req($path)['body'];
    $cek(!str_contains($body, '<i>KPSB</i>') && str_contains($body, '&lt;i&gt;KPSB&lt;/i&gt;'), 'J3 nama di-escape pada ' . $path);
}
foreach (['/', '/psb.php', '/berita.php', '/galeri.php', '/download.php', '/login_pendaftar.php'] as $path) {
    $cek($anon->req($path)['status'] === 200, 'J4 halaman publik ' . $path . ' 200');
}

// --- K. Log -----------------------------------------------------------------------------------
$log = dirname($web) . '/run/php-error.log';
$isi = is_file($log) ? (string) file_get_contents($log) : '';
$cek(!preg_match('/PHP (Warning|Fatal|Parse|Deprecated|Notice)/', $isi), 'K1 tanpa galat/peringatan PHP di log server');

// Bersihkan berkas sintetis di salinan; baris DB sintetis dibiarkan untuk diperiksa.
foreach (array_merge(glob($web . '/berkas_psb/file_*') ?: [], $privat()) as $berkas) {
    @unlink($berkas);
}

echo PHP_EOL . 'Hasil: ' . $lulus . ' lulus, ' . count($gagal) . ' gagal' . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
