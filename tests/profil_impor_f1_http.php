<?php

declare(strict_types=1);

/**
 * Uji server Fase 1 profil pengguna dan impor guru/pengurus lewat server PHP
 * lokal (bearer token REST dan sesi web) di atas DB `tests/profil_impor_f1_db.sh`:
 *
 *   PIF1_DB=test_pif1_http_test bash tests/profil_impor_f1_db.sh
 *   PIF1_DB=test_pif1_http_test php tests/profil_impor_f1_http.php
 *
 *   H-1 GET /profile/pribadi untuk Admin, Guru, Pengurus, Orang Tua: data pemilik saja;
 *       bentuk /profile lama tetap
 *   H-2 POST/PATCH /profile/pribadi: kunci terlarang 422; perubahan sah terbaca tanpa login ulang
 *   H-3 foto: unggah/ganti/hapus; berkas berbahaya/terlalu besar ditolak; akses lintas akun 404;
 *       folder privat tidak tersaji
 *   H-4 POST /profile/password: salah ditolak; berhasil → token lain 401, sesi web lain keluar
 *   H-5 password sementara: klien lama 403; token terbatas hanya ganti password/logout (PI-1)
 *   H-6 website: ganti kata sandi mencabut sesi lain; sesi sendiri tetap
 *   H-7 impor admin: templat, periksa, simpan lewat HTTP + CSRF; non-admin ditolak
 *   H-8 Data Guru/Pengurus: isian identitas baru dan tombol impor; simpan lewat formulir
 *   H-9 log server tanpa galat PHP
 *
 * Mengubah data uji; bangun ulang DB sebelum mengulang.
 */

$root = dirname(__DIR__);
$dbName = getenv('PIF1_DB') ?: 'test_pif1_http_test';
$port = (int) (getenv('PIF1_PORT') ?: 8975);
if (!preg_match('/^test_.*_test$/', $dbName)) {
    fwrite(STDERR, "Ditolak: DB uji harus bernama test_*_test.\n");
    exit(2);
}
putenv('DB_NAME=' . $dbName);
require_once $root . '/app/bootstrap.php';

$lulus = 0;
$gagal = [];
$assert = static function (bool $ok, string $pesan) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    $ok ? $lulus++ : $gagal[] = $pesan;
};
$db = app_db();
$satu = static function (string $sql) use ($db): mixed {
    $r = $db->query($sql);
    if ($r === false) {
        throw new RuntimeException($db->error . ' :: ' . $sql);
    }
    return $r->fetch_row()[0] ?? null;
};

$log = tempnam(sys_get_temp_dir(), 'pif1-server-');
$server = proc_open(['php', '-S', '127.0.0.1:' . $port, $root . '/tests/redesain_web_tahap1_router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root, array_merge(getenv(), ['DB_NAME' => $dbName]));
for ($i = 0; $i < 50; $i++) {
    $sock = @fsockopen('127.0.0.1', $port);
    if ($sock !== false) {
        fclose($sock);
        break;
    }
    usleep(100000);
}
register_shutdown_function(static function () use ($server, $port): void {
    if (is_resource($server)) {
        proc_terminate($server);
    }
    exec('pkill -f ' . escapeshellarg('php -S 127.0.0.1:' . $port . ' '));
});
$base = 'http://127.0.0.1:' . $port;

final class KlienPif1
{
    private string $jar;

    public function __construct(private string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'pif1-jar-');
    }

    /** @return array{status:int, body:string, location:?string, type:string} */
    public function req(string $path, ?array $post = null, bool $mentah = false): array
    {
        $h = curl_init($this->base . $path);
        curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 60]);
        if ($post !== null) {
            curl_setopt($h, CURLOPT_POST, true);
            curl_setopt($h, CURLOPT_POSTFIELDS, $mentah ? $post : http_build_query($post));
        }
        $out = (string) curl_exec($h);
        $status = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
        $size = (int) curl_getinfo($h, CURLINFO_HEADER_SIZE);
        $type = (string) curl_getinfo($h, CURLINFO_CONTENT_TYPE);
        curl_close($h);
        preg_match('/^Location:\s*(.+)$/mi', substr($out, 0, $size), $m);
        return ['status' => $status, 'body' => substr($out, $size), 'location' => isset($m[1]) ? trim($m[1]) : null, 'type' => $type];
    }

    public function csrf(string $path = '/portal/index.php'): string
    {
        $r = $this->req($path);
        preg_match('/name="_csrf" value="([^"]+)"/', $r['body'], $m);
        if (!isset($m[1])) {
            preg_match('/ALHASAN_CSRF\s*=\s*"([^"]+)"/', $r['body'], $m);
        }
        return $m[1] ?? '';
    }

    public function login(string $user, string $password = 'Sandbox#123'): int
    {
        return $this->req('/admin/cek_login.php', ['_csrf' => $this->csrf(), 'username' => $user, 'password' => $password])['status'];
    }
}

$api = static function (string $method, string $path, ?string $token, mixed $body = null, bool $multipart = false) use ($base): array {
    $h = curl_init($base . '/api/v1' . $path);
    $headers = ['Accept: application/json'];
    if (!$multipart) {
        $headers[] = 'Content-Type: application/json';
    }
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $headers]);
    if ($method !== 'GET') {
        curl_setopt($h, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($h, CURLOPT_POSTFIELDS, $multipart ? $body : (string) json_encode($body ?? []));
    }
    $out = (string) curl_exec($h);
    $size = (int) curl_getinfo($h, CURLINFO_HEADER_SIZE);
    $status = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
    $type = (string) curl_getinfo($h, CURLINFO_CONTENT_TYPE);
    curl_close($h);
    $raw = substr($out, $size);
    $json = json_decode($raw, true);
    return ['status' => $status, 'data' => $json['data'] ?? null, 'json' => $json, 'raw' => $raw, 'type' => $type, 'kode' => $json['error']['code'] ?? null];
};
$masuk = static function (string $user, string $password = 'Sandbox#123', array $tambahan = []) use ($api): array {
    return $api('POST', '/auth/login', null, ['username' => $user, 'password' => $password, 'device_name' => 'uji-pif1'] + $tambahan);
};
$token = static fn (string $user): string => (string) ($masuk($user)['data']['token'] ?? '');

$uid = static fn (string $username): int => (int) $satu("SELECT id FROM users WHERE username = '" . $db->real_escape_string($username) . "'");
$tAdmin = $token('sbx_admin');
$tGuru = $token('sbx_murobi_a');
$tPeng = $token('sbx_pengurus_a');
$tOrtu = $token('sbx_ortu_a');
$assert($tAdmin !== '' && $tGuru !== '' && $tPeng !== '' && $tOrtu !== '', 'Empat kelompok akun memperoleh token API');

echo "\n=== H-1 baca profil ===\n";
foreach (['sbx_admin' => [$tAdmin, 'akun', 'admin'], 'sbx_murobi_a' => [$tGuru, 'guru', 'murobi'], 'sbx_pengurus_a' => [$tPeng, 'pengurus', 'pembimbing'], 'sbx_ortu_a' => [$tOrtu, 'wali', 'orang_tua']] as $u => [$t, $sumber, $kode]) {
    $r = $api('GET', '/profile/pribadi', $t);
    $assert($r['status'] === 200 && ($r['data']['akun']['id'] ?? 0) === $uid($u) && ($r['data']['akun']['username'] ?? '') === $u && $r['data']['sumber'] === $sumber
        && in_array($kode, array_column($r['data']['status'], 'kode'), true), "Profil $u: hanya akun sendiri, sumber $sumber, status $kode dari server");
}
$rOrtu = $api('GET', '/profile/pribadi', $tOrtu)['raw'];
$assert(!str_contains($rOrtu, 'SBX Wali B') && !str_contains($rOrtu, 'SBX Murobi') && !str_contains($rOrtu, 'sbx_admin'), 'Profil orang tua tidak memuat identitas akun lain');
$lama = $api('GET', '/profile', $tGuru)['data'];
$assert(array_keys($lama) === ['id', 'name', 'username', 'guru', 'roles', 'capabilities', 'feature_capabilities'], 'Bentuk /profile lama tidak berubah (aditif)');
$assert($api('GET', '/profile/pribadi', null)['status'] === 401 && $api('GET', '/profile/pribadi', 'token-palsu')['status'] === 401, 'Tanpa/token palsu → 401');
$assert(str_contains(strtolower((string) ($api('GET', '/profile/pribadi', $tGuru)['type'])), 'json'), 'Respons profil JSON');

echo "\n=== H-2 ubah profil ===\n";
$guruId = (int) $satu("SELECT guru_id FROM users WHERE username = 'sbx_murobi_a'");
$potret = static fn (): string => (string) json_encode([$db->query("SELECT username, guru_id, pengurus_id, wali_id, is_active FROM users WHERE username = 'sbx_murobi_a'")->fetch_assoc(),
    $db->query("SELECT ur.role_id FROM user_roles ur JOIN users u ON u.id = ur.user_id WHERE u.username = 'sbx_murobi_a'")->fetch_all(),
    $db->query("SELECT id, is_active, tanggal_mulai, tanggal_selesai, kamar_id, kelas_id FROM murobi_assignments WHERE guru_id = $guruId")->fetch_all()]);
$awal = $potret();
foreach (['username' => 'admin', 'roles' => ['admin'], 'penugasan' => [['jenis' => 'pendidikan']], 'kamar' => 'Kamar X', 'kelas' => '1', 'mapel' => 'Fiqih', 'guru_id' => 1, 'user_id' => 1, 'nip' => 'X'] as $kunci => $nilai) {
    $r = $api('POST', '/profile/pribadi', $tGuru, ['nama' => 'SBX Murobi A', $kunci => $nilai]);
    $assert($r['status'] === 422 && $r['kode'] === 'VALIDATION_FAILED' && isset($r['json']['error']['details'][$kunci]) && $potret() === $awal, "Menulis $kunci lewat edit profil ditolak 422; akun/role/penugasan tetap");
}
$r = $api('PATCH', '/profile/pribadi', $tGuru, ['nama' => 'SBX Murobi A Baru', 'tempat_lahir' => 'Ciamis', 'tanggal_lahir' => '1991-03-04', 'no_hp' => '081311112222', 'email' => 'murobi.a@example.test',
    'alamat_rumah' => 'Jl. A', 'kelurahan' => 'Desa A', 'kecamatan' => 'Kec A', 'kabupaten' => 'Kab A', 'provinsi' => 'Jawa Barat']);
$assert($r['status'] === 200 && $r['data']['pribadi']['nama'] === 'SBX Murobi A Baru' && $r['data']['pribadi']['kabupaten'] === 'Kab A', 'PATCH profil sah diterima dan dikembalikan');
$assert(($api('GET', '/profile', $tGuru)['data']['name'] ?? '') === 'SBX Murobi A Baru' && ($api('GET', '/profile/pribadi', $tGuru)['data']['pribadi']['email'] ?? '') === 'murobi.a@example.test',
    'Nilai baru langsung terbaca token yang sama tanpa login ulang');
$assert($api('POST', '/profile/pribadi', $tGuru, ['nama' => 'Basi', 'versi' => 0])['status'] === 409, 'Versi basi → 409 CONFLICT');
$assert($api('POST', '/profile/pribadi', $tGuru, ['email' => 'salah'])['status'] === 422, 'Email salah → 422');

echo "\n=== H-3 foto ===\n";
$folder = sys_get_temp_dir() . '/pif1-foto-' . getmypid();
@mkdir($folder);
$png = $folder . '/foto.png';
file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
$jpg = $folder . '/foto.jpg';
$gambar = imagecreatetruecolor(4, 4);
imagejpeg($gambar, $jpg);
$unggah = static fn (string $t, string $path, string $nama, string $mime) => $api('POST', '/profile/foto', $t, ['foto' => new CURLFile($path, $mime, $nama)], true);
$r = $unggah($tGuru, $png, 'foto.png', 'image/png');
$assert($r['status'] === 200 && $r['data']['foto']['ada'] === true && $r['data']['foto']['versi'] === 1 && $r['data']['foto']['url'] === '/profile/foto', 'Unggah PNG → foto ada, versi 1');
$f = $api('GET', '/profile/foto', $tGuru);
$assert($f['status'] === 200 && $f['type'] === 'image/png' && $f['raw'] === file_get_contents($png), 'Pemilik membaca foto sendiri (isi sama)');
$namaBerkas = (string) $satu("SELECT foto FROM profil_pribadi WHERE guru_id = $guruId");
$r = $unggah($tGuru, $jpg, 'ganti.jpg', 'image/jpeg');
$namaBaru = (string) $satu("SELECT foto FROM profil_pribadi WHERE guru_id = $guruId");
$assert($r['status'] === 200 && $r['data']['foto']['versi'] === 2 && str_ends_with($namaBaru, '.jpg') && !is_file($root . '/storage/private/profil/' . $namaBerkas), 'Ganti foto → versi naik; berkas lama dihapus');
$uGuru = $uid('sbx_murobi_a');
$assert($api('GET', '/profile/foto/' . $uGuru, $tAdmin)['status'] === 200, 'Admin membaca foto akun lain');
foreach (['pengurus' => $tPeng, 'orang tua' => $tOrtu] as $label => $t) {
    $assert($api('GET', '/profile/foto/' . $uGuru, $t)['status'] === 404, "Akun $label membaca foto guru → 404");
}
$assert($api('GET', '/profile/foto', $tOrtu)['status'] === 404 && $api('GET', '/profile/foto/999999', $tAdmin)['status'] === 404, 'Tanpa foto / akun tidak ada → 404');
$php = $folder . '/jahat.png';
file_put_contents($php, "<?php echo 'jahat'; ?>");
$svg = $folder . '/gambar.svg';
file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
$poli = $folder . '/poli.png';
file_put_contents($poli, file_get_contents($png) . "<?php system('id'); ?>");
$besar = $folder . '/besar.jpg';
file_put_contents($besar, file_get_contents($jpg) . str_repeat("\0", 2 * 1024 * 1024));
$gif = $folder . '/gerak.gif';
imagegif($gambar, $gif);
foreach (['skrip PHP bernama .png' => [$php, 'jahat.png', 'image/png'], 'SVG berskrip' => [$svg, 'gambar.svg', 'image/svg+xml'], 'GIF' => [$gif, 'gerak.gif', 'image/gif'],
    'lebih dari 2 MB' => [$besar, 'besar.jpg', 'image/jpeg']] as $label => [$path, $nama, $mime]) {
    $r = $unggah($tGuru, $path, $nama, $mime);
    $assert($r['status'] === 422 && (string) $satu("SELECT foto FROM profil_pribadi WHERE guru_id = $guruId") === $namaBaru, "Unggahan $label ditolak 422; foto lama tetap");
}
$r = $unggah($tGuru, $poli, 'poli.png', 'image/png');
$disimpan = (string) $satu("SELECT foto FROM profil_pribadi WHERE guru_id = $guruId");
$assert($r['status'] !== 200 || preg_match('/^[0-9a-f]{32}\.png$/', $disimpan) === 1, 'PNG berekor skrip hanya disimpan dengan nama acak .png di folder privat (tidak dapat dieksekusi)');
$assert($api('POST', '/profile/foto', $tGuru, [], true)['status'] === 422, 'Tanpa berkas → 422');
$tamu = new KlienPif1($base);
$assert($tamu->req('/storage/private/profil/' . $disimpan)['status'] === 403, 'Folder privat foto tidak tersaji langsung (router uji = .htaccess Require all denied)');
$adminWeb = new KlienPif1($base);
$adminWeb->login('sbx_admin');
$w = $adminWeb->req('/admin/foto_profil.php?jenis=guru&id=' . $guruId);
$assert($w['status'] === 200 && str_starts_with($w['type'], 'image/'), 'Admin web membaca foto lewat endpoint berpenjaga');
$ortuWeb = new KlienPif1($base);
$ortuWeb->login('sbx_ortu_a');
$assert($ortuWeb->req('/admin/foto_profil.php?jenis=guru&id=' . $guruId)['status'] !== 200, 'Orang tua tidak dapat membaca foto lewat endpoint admin');
$r = $api('POST', '/profile/foto/hapus', $tGuru);
$assert($r['status'] === 200 && $r['data']['foto']['ada'] === false && $satu("SELECT foto FROM profil_pribadi WHERE guru_id = $guruId") === null, 'Hapus foto → kembali ke inisial');

echo "\n=== H-4 ganti password lewat API ===\n";
$web1 = new KlienPif1($base);
$web1->login('sbx_pengurus_a');
$assert($web1->req('/admin/ubah_password.php')['status'] === 200, 'Sesi web pengurus aktif sebelum ganti password');
$tPengLain = $token('sbx_pengurus_a');
$r = $api('POST', '/profile/password', $tPeng, ['password_saat_ini' => 'Salah#12345678', 'password_baru' => 'PasswordBaru#2026', 'konfirmasi_password' => 'PasswordBaru#2026']);
$assert($r['status'] === 422 && isset($r['json']['error']['details']['password_saat_ini']) && $api('GET', '/profile/pribadi', $tPengLain)['status'] === 200, 'Password saat ini salah → 422; token lain tetap');
$r = $api('POST', '/profile/password', $tPeng, ['password_saat_ini' => 'Sandbox#123', 'password_baru' => 'lemah', 'konfirmasi_password' => 'lemah']);
$assert($r['status'] === 422 && isset($r['json']['error']['details']['password_baru']), 'Password baru lemah → 422');
$r = $api('POST', '/profile/password', $tPeng, ['password_saat_ini' => 'Sandbox#123', 'password_baru' => 'PasswordBaru#2026', 'konfirmasi_password' => 'PasswordBaru#2026', 'device_id' => 'hp-uji']);
$assert($r['status'] === 200 && $r['data']['token_lain_dicabut'] >= 1 && !str_contains($r['raw'], 'PasswordBaru') && !str_contains($r['raw'], 'Sandbox'), 'Password berhasil diganti; respons tanpa password');
$assert($api('GET', '/profile/pribadi', $tPeng)['status'] === 200 && $api('GET', '/profile/pribadi', $tPengLain)['status'] === 401, 'Token yang dipakai tetap; token lain akun ini → 401');
$k = $web1->req('/admin/ubah_password.php');
$assert($k['status'] === 302 && str_contains((string) $k['location'], 'portal/index.php'), 'Sesi web lain akun ini keluar setelah password diganti (PI-2)');
$assert($masuk('sbx_pengurus_a')['status'] === 401 && $masuk('sbx_pengurus_a', 'PasswordBaru#2026')['status'] === 200, 'Password lama tidak berlaku; password baru dapat dipakai login');

echo "\n=== H-5 password sementara (PI-1) ===\n";
$uSementara = $uid('sbx_pengurus_b');
$tPenuhLama = $token('sbx_pengurus_b');
$sementara = account_service()->resetPassword($uSementara, 1);
$r = $masuk('sbx_pengurus_b', $sementara);
$assert($r['status'] === 403 && $r['kode'] === 'PASSWORD_CHANGE_REQUIRED', 'Klien lama (tanpa penanda) tetap ditolak 403 PASSWORD_CHANGE_REQUIRED');
$r = $masuk('sbx_pengurus_b', $sementara, ['izinkan_password_sementara' => true]);
$tTerbatas = (string) ($r['data']['token'] ?? '');
$assert($r['status'] === 200 && $r['data']['token_scope'] === 'ganti_password' && $r['data']['password_change_required'] === true && !isset($r['data']['profile']['capabilities']),
    'Klien yang mendukung: token terbatas tanpa capability/menu');
$assert($satu("SELECT cakupan FROM api_tokens WHERE user_id = $uSementara ORDER BY id DESC LIMIT 1") === 'ganti_password', 'Token tersimpan bercakupan ganti_password');
$diblokir = true;
foreach ([['GET', '/profile/pribadi'], ['GET', '/profile'], ['GET', '/me/capabilities'], ['GET', '/izin/pengajuan'], ['POST', '/profile/pribadi'], ['GET', '/v6/bsa/akses'], ['GET', '/keamanan/akses']] as [$m, $p]) {
    $x = $api($m, $p, $tTerbatas, ['nama' => 'X']);
    $diblokir = $diblokir && $x['status'] === 403 && $x['kode'] === 'PASSWORD_CHANGE_REQUIRED';
}
$assert($diblokir, 'Token terbatas ditolak 403 pada profil, capability, izin, BSA, keamanan, dan edit profil');
$assert($api('GET', '/profile/pribadi', $tPenuhLama)['kode'] === 'PASSWORD_CHANGE_REQUIRED', 'Token penuh lama juga dibatasi selama password sementara belum diganti');
$r = $api('POST', '/profile/password', $tTerbatas, ['password_saat_ini' => $sementara, 'password_baru' => 'PasswordSendiri#2026', 'konfirmasi_password' => 'PasswordSendiri#2026']);
$assert($r['status'] === 200 && $r['data']['wajib_ganti_password'] === false, 'Ganti password sementara lewat token terbatas berhasil');
$assert($api('GET', '/profile/pribadi', $tTerbatas)['status'] === 200 && $satu("SELECT cakupan FROM api_tokens WHERE token_hash = '" . token_hasher()->hash($tTerbatas) . "'") === 'penuh'
    && $api('GET', '/profile/pribadi', $tPenuhLama)['status'] === 401, 'Token terbatas menjadi penuh; token lama dicabut');
$assert((int) $satu("SELECT DATEDIFF(expires_at, NOW()) FROM api_tokens WHERE token_hash = '" . token_hasher()->hash($tTerbatas) . "'") >= max(1, (int) app_config('api.token_ttl_days') - 1),
    'Token yang menjadi penuh memperoleh masa berlaku token biasa (bukan 1 hari)');
$tLogout = (string) ($masuk('sbx_pengurus_b', 'PasswordSendiri#2026')['data']['token'] ?? '');
$sementara2 = account_service()->resetPassword($uSementara, 1);
$tT2 = (string) ($masuk('sbx_pengurus_b', $sementara2, ['izinkan_password_sementara' => true])['data']['token'] ?? '');
$assert($api('POST', '/auth/logout', $tT2)['status'] === 200 && $api('POST', '/profile/password', $tT2, [])['status'] === 401, 'Token terbatas dapat logout; sesudahnya tidak berlaku');

echo "\n=== H-6 website: ganti kata sandi ===\n";
$a = new KlienPif1($base);
$b = new KlienPif1($base);
$a->login('sbx_ortu_b');
$b->login('sbx_ortu_b');
$csrf = $a->csrf('/admin/ubah_password.php');
$r = $a->req('/admin/ubah_password.php', ['_csrf' => $csrf, 'password_saat_ini' => 'Salah#123456789', 'password_baru' => 'WebBaru#20262026', 'konfirmasi_password' => 'WebBaru#20262026']);
$assert($r['status'] === 200 && str_contains($r['body'], 'Kata sandi saat ini tidak benar') && $b->req('/admin/ubah_password.php')['status'] === 200, 'Web: password saat ini salah ditolak; sesi lain tetap');
$r = $a->req('/admin/ubah_password.php', ['_csrf' => $a->csrf('/admin/ubah_password.php'), 'password_saat_ini' => 'Sandbox#123', 'password_baru' => 'WebBaru#20262026', 'konfirmasi_password' => 'WebBaru#20262026']);
$assert($r['status'] === 200 && $a->req('/admin/ubah_password.php')['status'] === 200, 'Web: ganti kata sandi berhasil; sesi sendiri tetap');
$kb = $b->req('/admin/ubah_password.php');
$assert($kb['status'] === 302 && str_contains((string) $kb['location'], 'pesan=sesi'), 'Web: sesi lain akun yang sama keluar');
$assert((int) $satu("SELECT COUNT(*) FROM audit_logs WHERE action = 'password_changed' AND entity_id = " . $uid('sbx_ortu_b')) === 1, 'Jejak audit lama password_changed tetap ditulis');

echo "\n=== H-7 impor admin lewat HTTP ===\n";
$t = $adminWeb->req('/admin/proses_import_personel.php?jenis=guru&templat=1');
$assert($t['status'] === 200 && str_contains($t['type'], 'spreadsheet') && str_starts_with($t['body'], 'PK'), 'Templat impor guru berupa berkas .xlsx');
$csrfAdmin = $adminWeb->csrf('/admin/admin_guru.php');
$baris = [['baris' => 2, 'nama' => 'HTTP Guru Impor', 'nip' => 'HTTP-G-1', 'nik' => '3200000000005001', 'tugas' => 'Murobi', 'kamar' => 'SBX Kamar A']];
$kirim = static function (KlienPif1 $k, string $mode, array $rows, array $pilihan, string $csrf, string $jenis = 'guru'): array {
    $r = $k->req('/admin/proses_import_personel.php', ['_csrf' => $csrf, 'jenis' => $jenis, 'mode' => $mode, 'payload' => json_encode(['rows' => $rows, 'pilihan' => $pilihan])]);
    return ['status' => $r['status'], 'json' => json_decode($r['body'], true)];
};
$tugasSebelum = (string) json_encode($db->query('SELECT * FROM murobi_assignments ORDER BY id')->fetch_all());
$p = $kirim($adminWeb, 'periksa', $baris, [], $csrfAdmin);
$assert($p['status'] === 200 && ($p['json']['hasil'][0]['aksi'] ?? '') === 'tambah' && (int) $satu("SELECT COUNT(*) FROM guru WHERE nip = 'HTTP-G-1'") === 0, 'Periksa lewat HTTP: pratinjau tanpa menyimpan');
$s = $kirim($adminWeb, 'simpan', $baris, [2 => ['sidik' => $p['json']['hasil'][0]['sidik'] ?? '']], $csrfAdmin);
$assert($s['status'] === 200 && ($s['json']['ditambah'] ?? 0) === 1 && (int) $satu("SELECT COUNT(*) FROM guru WHERE nip = 'HTTP-G-1'") === 1, 'Simpan lewat HTTP: satu guru baru');
$s2 = $kirim($adminWeb, 'simpan', $baris, [2 => ['sidik' => $p['json']['hasil'][0]['sidik'] ?? '']], $csrfAdmin);
$assert(($s2['json']['disimpan'] ?? -1) === 0 && (int) $satu("SELECT COUNT(*) FROM guru WHERE nip = 'HTTP-G-1'") === 1, 'Kiriman ulang sidik lama tidak menggandakan guru');
$assert($tugasSebelum === json_encode($db->query('SELECT * FROM murobi_assignments ORDER BY id')->fetch_all()), 'Kolom Tugas/Kamar tidak mengubah penugasan murobi');
$tanpaCsrf = $adminWeb->req('/admin/proses_import_personel.php', ['jenis' => 'guru', 'mode' => 'periksa', 'payload' => json_encode(['rows' => $baris])]);
$assert($tanpaCsrf['status'] === 419, 'POST impor tanpa CSRF ditolak');
$guruWeb = new KlienPif1($base);
$guruWeb->login('sbx_guru_biasa');
$ditolak = $guruWeb->req('/admin/proses_import_personel.php', ['_csrf' => $guruWeb->csrf(), 'jenis' => 'guru', 'mode' => 'simpan', 'payload' => json_encode(['rows' => $baris])]);
$assert($ditolak['status'] !== 200 || !str_contains($ditolak['body'], '"ok":true'), 'Guru (non-admin) tidak dapat memakai endpoint impor');
$assert($adminWeb->req('/admin/proses_import_personel.php?jenis=santri&templat=1')['status'] === 404, 'Jenis impor tak dikenal → 404');
$pp = $kirim($adminWeb, 'periksa', [['baris' => 2, 'nama' => 'HTTP Pengurus Impor', 'nip_nik' => '3200000000005002']], [], $csrfAdmin, 'pengurus');
$assert($pp['status'] === 200 && ($pp['json']['hasil'][0]['nik'] ?? '') === '3200000000005002', 'Periksa impor pengurus lewat HTTP');

echo "\n=== H-8 halaman Data Guru/Pengurus ===\n";
$hal = $adminWeb->req('/admin/admin_guru.php?action=impor');
$assert($hal['status'] === 200 && str_contains($hal['body'], 'data-ah-impor-personel') && str_contains($hal['body'], 'Unduh templat'), 'Halaman impor guru tersedia');
$assert(str_contains($adminWeb->req('/admin/admin_pengurus.php?action=impor')['body'], 'data-jenis="pengurus"'), 'Halaman impor pengurus tersedia');
$daftar = $adminWeb->req('/admin/admin_guru.php')['body'];
$assert(str_contains($daftar, 'action=impor'), 'Tombol Impor Excel tampil di Data Guru');
$form = $adminWeb->req('/admin/admin_guru.php?action=edit&id=' . $guruId)['body'];
$assert(str_contains($form, 'name="nik"') && str_contains($form, 'name="tanggal_lahir"') && str_contains($form, 'name="kabupaten"') && !str_contains($form, 'name="status"'), 'Formulir guru memuat isian identitas; kolom tugas lama tidak menjadi isian');
$r = $adminWeb->req('/admin/admin_guru.php', ['_csrf' => $adminWeb->csrf('/admin/admin_guru.php?action=edit&id=' . $guruId), 'action' => 'save', 'id' => (string) $guruId,
    'nama_guru' => 'SBX Murobi A Admin', 'nip' => (string) $satu("SELECT COALESCE(nip, '') FROM guru WHERE id = $guruId"), 'no_hp' => '081311113333', 'nik' => '3200000000005003',
    'tempat_lahir' => 'Kuningan', 'tanggal_lahir' => '1991-03-04', 'alamat_rumah' => '', 'kelurahan' => '', 'kecamatan' => '', 'kabupaten' => 'Kab A', 'provinsi' => 'Jawa Barat', 'email' => 'murobi.a@example.test']);
$assert($r['status'] === 302 && $satu("SELECT nik FROM profil_pribadi WHERE guru_id = $guruId") === '3200000000005003' && $satu("SELECT name FROM users WHERE id = $uGuru") === 'SBX Murobi A Admin'
    && $satu("SELECT alamat_rumah FROM profil_pribadi WHERE guru_id = $guruId") === null, 'Simpan formulir guru: NIK, nama akun, dan pengosongan isian diterapkan');
$detail = $adminWeb->req('/admin/admin_guru.php?action=detail&id=' . $guruId)['body'];
$assert(str_contains($detail, '3200000000005003') && str_contains($detail, 'Kuningan'), 'Detail guru menampilkan identitas pribadi');
$pengId = (int) $satu("SELECT pengurus_id FROM users WHERE username = 'sbx_pengurus_a'");
$fp = $adminWeb->req('/admin/admin_pengurus.php?action=edit&id=' . $pengId)['body'];
$assert(str_contains($fp, 'NIP / nomor identitas') && str_contains($fp, 'name="nik"'), 'Formulir pengurus: label NIP / nomor identitas (PI-9) dan NIK');

echo "\n=== H-9 log server ===\n";
usleep(300000);
$isiLog = (string) file_get_contents($log);
$assert(!preg_match('/PHP (Fatal|Warning|Parse|Deprecated|Notice)/', $isiLog), 'Log server tanpa galat PHP');
if (preg_match_all('/PHP (Fatal|Warning|Parse|Deprecated|Notice)[^\n]*/', $isiLog, $m)) {
    echo implode("\n", array_slice(array_unique($m[0]), 0, 10)), "\n";
}
array_map('unlink', glob($folder . '/*') ?: []);
@rmdir($folder);

echo "\n" . $lulus . ' lulus, ' . count($gagal) . " gagal\n";
foreach ($gagal as $g) {
    echo ' - ' . $g . "\n";
}
exit($gagal === [] ? 0 : 1);
