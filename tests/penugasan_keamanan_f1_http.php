<?php

declare(strict_types=1);

/**
 * Uji server Fase 1 penugasan lintas guru/pengurus — halaman admin dan akses
 * guru pemilik identitas lewat server PHP lokal di atas DB uji
 * `tests/penugasan_keamanan_f1_db.sh`.
 *
 *   PKF1_DB=test_pk_f1_http_test bash tests/penugasan_keamanan_f1_db.sh
 *   PKF1_DB=test_pk_f1_http_test php tests/penugasan_keamanan_f1_http.php
 *
 *   H-1 formulir tambah: kelompok Pengurus/Guru hanya pada jenis berbasis pengurus
 *   H-2 POST ber-CSRF menugaskan guru; tanpa CSRF, GET, dan non-admin tidak memutasi
 *   H-3 guru pemilik identitas: peran Pengurus muncul, halaman binaan terbuka
 *   H-4 menonaktifkan guru dari Data Guru menutup halaman binaan pada sesi yang sama
 *   H-5 Data Pengurus menandai identitas dan menolak ubah/status; Data Guru menautkannya
 *   H-6 halaman akun/penugasan lain tetap 200; log server tanpa galat PHP
 *
 * Mengubah data uji; bangun ulang DB sebelum mengulang.
 */

$root = dirname(__DIR__);
$db = getenv('PKF1_DB') ?: 'test_pk_f1_http_test';
$port = (int) (getenv('PKF1_PORT') ?: 8972);
if (!preg_match('/^test_.*_test$/', $db)) {
    fwrite(STDERR, "Ditolak: DB uji harus bernama test_*_test.\n");
    exit(2);
}
putenv('DB_NAME=' . $db);
require_once $root . '/app/bootstrap.php';

$lulus = 0;
$gagal = [];
$assert = static function (bool $ok, string $pesan) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    $ok ? $lulus++ : $gagal[] = $pesan;
};
$log = tempnam(sys_get_temp_dir(), 'pkf1-server-');
$server = proc_open(['php', '-S', '127.0.0.1:' . $port, $root . '/tests/redesain_web_tahap1_router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root, array_merge(getenv(), ['DB_NAME' => $db]));
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

final class Klien
{
    private string $jar;

    public function __construct(private string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'pkf1-jar-');
    }

    /** @return array{status:int, body:string, location:?string} */
    public function req(string $path, ?array $post = null): array
    {
        $h = curl_init($this->base . $path);
        curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 60]);
        if ($post !== null) {
            curl_setopt($h, CURLOPT_POST, true);
            curl_setopt($h, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $out = (string) curl_exec($h);
        $status = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
        $size = (int) curl_getinfo($h, CURLINFO_HEADER_SIZE);
        curl_close($h);
        preg_match('/^Location:\s*(.+)$/mi', substr($out, 0, $size), $m);

        return ['status' => $status, 'body' => substr($out, $size), 'location' => isset($m[1]) ? trim($m[1]) : null];
    }

    public function csrf(string $path = '/portal/index.php'): string
    {
        $r = $this->req($path);
        preg_match('/name="_csrf" value="([^"]+)"/', $r['body'], $m);

        return $m[1] ?? '';
    }

    public function login(string $user): void
    {
        $this->req('/admin/cek_login.php', ['_csrf' => $this->csrf(), 'username' => $user, 'password' => 'Sandbox#123']);
    }
}

$base = 'http://127.0.0.1:' . $port;
$klien = static function (string $user) use ($base): Klien {
    $k = new Klien($base);
    $k->login($user);

    return $k;
};
$conn = app_db();
$satu = static function (string $sql) use ($conn): mixed {
    $r = $conn->query($sql);
    if ($r === false) {
        throw new RuntimeException($conn->error . ' :: ' . $sql);
    }

    return $r->fetch_row()[0] ?? null;
};
$jumlah = static fn () => $satu('SELECT COUNT(*) FROM pengurus') . '/' . $satu('SELECT COUNT(*) FROM pembimbing_assignments') . '/' . $satu("SELECT COUNT(*) FROM users WHERE pengurus_id IS NOT NULL");

$guru = (int) $satu("SELECT guru_id FROM users WHERE username = 'sbx_guru_biasa'");
$tahun = (int) $satu("SELECT id FROM tahun_ajaran WHERE status = 'Aktif' AND archived_at IS NULL LIMIT 1");
$kamar = (int) $satu('SELECT km.id FROM kamar km JOIN plotting_kamar pk ON pk.id_kamar = km.id ORDER BY km.id LIMIT 1');
$admin = $klien('sbx_admin');

// --- H-1 formulir ------------------------------------------------------------
$r = $admin->req('/admin/admin_penugasan.php?jenis=pembimbing&tambah=1');
$assert($r['status'] === 200 && str_contains($r['body'], '<optgroup label="Guru">') && str_contains($r['body'], 'value="guru:' . $guru . '"')
    && str_contains($r['body'], 'Pengurus atau guru'), 'H-1 pembimbing: kelompok Guru ditawarkan dengan nilai guru:ID');
$assert(preg_match('/<option value="guru:\d+"[^>]*>SBX V5 Ganda[^<]* — nama sama dengan pengurus<\/option>/', $r['body']) === 1
    && str_contains($r['body'], 'id="subjek-nama-kembar"') && str_contains($r['body'], 'Periksa nama yang sama.'),
    'H-1 pembimbing: guru yang bernama sama dengan pengurus aktif ditandai dan diberi peringatan');
foreach (['pendidikan', 'kesehatan', 'bendahara_bulanan', 'panitia_psb', 'bendahara_psb'] as $j) {
    $r = $admin->req('/admin/admin_penugasan.php?jenis=' . $j . '&tambah=1');
    $assert($r['status'] === 200 && str_contains($r['body'], '<optgroup label="Guru">'), "H-1 $j: kelompok Guru ditawarkan");
}
foreach (['murobi', 'guru_mapel'] as $j) {
    $r = $admin->req('/admin/admin_penugasan.php?jenis=' . $j . '&tambah=1');
    $assert($r['status'] === 200 && !str_contains($r['body'], 'value="guru:') && !str_contains($r['body'], '<optgroup') && !str_contains($r['body'], 'subjek-nama-kembar'), "H-1 $j: tetap khusus guru tanpa kelompok tambahan");
}

// --- H-2 mutasi ---------------------------------------------------------------
$isian = ['jenis' => 'pembimbing', 'action' => 'buat', 'pengurus_id' => 'guru:' . $guru, 'tahun_ajaran_id' => $tahun,
    'target_type' => 'Kamar', 'kamar_id' => $kamar, 'tanggal_mulai' => date('Y-m-d')];
$awal = $jumlah();
$r = $admin->req('/admin/admin_penugasan.php', $isian);
$assert($jumlah() === $awal, 'H-2 POST tanpa CSRF tidak memutasi (status ' . $r['status'] . ')');
$admin->req('/admin/admin_penugasan.php?' . http_build_query($isian));
$assert($jumlah() === $awal, 'H-2 GET berparameter tidak memutasi');
$pengurusA = $klien('sbx_pengurus_a');
$r = $pengurusA->req('/admin/admin_penugasan.php', $isian + ['_csrf' => $pengurusA->csrf()]);
$assert($r['status'] >= 300 && $jumlah() === $awal, 'H-2 non-admin ditolak tanpa mutasi (status ' . $r['status'] . ')');
$r = $admin->req('/admin/admin_penugasan.php', $isian + ['_csrf' => $admin->csrf('/admin/admin_penugasan.php?jenis=pembimbing')]);
$identitas = (int) $satu("SELECT id FROM pengurus WHERE guru_id = $guru");
$userGuru = (int) $satu("SELECT id FROM users WHERE guru_id = $guru");
$assert($r['status'] === 302 && $identitas > 0 && (int) $satu("SELECT COUNT(*) FROM pembimbing_assignments WHERE pengurus_id = $identitas AND kamar_id = $kamar") === 1
    && (int) $satu("SELECT pengurus_id FROM users WHERE id = $userGuru") === $identitas, 'H-2 POST ber-CSRF menugaskan guru sebagai pembimbing lewat identitasnya');
$r = $admin->req('/admin/admin_penugasan.php?jenis=pembimbing');
$assert(str_contains($r['body'], 'tersimpan') && str_contains($r['body'], (string) $satu("SELECT nama_guru FROM guru WHERE id = $guru")), 'H-2 pesan berhasil dan nama guru tampil di daftar');
$r = $admin->req('/admin/admin_penugasan.php', ['jenis' => 'pembimbing', 'action' => 'buat', 'pengurus_id' => 'guru:99999999', 'tahun_ajaran_id' => $tahun,
    'target_type' => 'Kamar', 'kamar_id' => $kamar, 'tanggal_mulai' => date('Y-m-d'), '_csrf' => $admin->csrf('/admin/admin_penugasan.php?jenis=pembimbing')]);
$ikuti = $admin->req((string) preg_replace('#^https?://[^/]+#', '', (string) $r['location']));
$assert(str_contains($ikuti['body'], 'Guru harus dipilih dari data guru yang aktif') && (int) $satu('SELECT COUNT(*) FROM pengurus WHERE guru_id IS NOT NULL') === 1,
    'H-2 guru tidak sah ditolak dengan pesan pada panel tambah');

// --- H-3 guru pemilik identitas -------------------------------------------------
$g = $klien('sbx_guru_biasa');
$beranda = $g->req('/portal/index.php');
$assert($beranda['status'] === 200 && str_contains($beranda['body'], 'name="peran" value="pengurus"') && str_contains($beranda['body'], 'name="peran" value="guru"'),
    'H-3 akun guru kini dapat beralih ke peran Pengurus');
$g->req('/portal/peran.php', ['_csrf' => $g->csrf(), 'peran' => 'pengurus', 'kembali' => '/portal/index.php']);
$r = $g->req('/portal/santri_binaan.php');
preg_match('/santri_binaan\.php\?id=(\d+)/', $r['body'], $m);
$santriBinaan = (int) ($m[1] ?? 0);
$assert($r['status'] === 200 && $santriBinaan > 0, 'H-3 sebagai Pengurus, daftar santri binaan pembimbing berisi santri kamar binaan');
$assert($g->req('/portal/santri_binaan.php?id=' . $santriBinaan)['status'] === 200, 'H-3 profil santri binaan terbuka');

// --- H-4 nonaktif dari Data Guru -------------------------------------------------
$r = $admin->req('/admin/admin_guru.php', ['_csrf' => $admin->csrf('/admin/admin_guru.php'), 'id' => $guru, 'action' => 'deactivate']);
$assert((int) $satu("SELECT is_active FROM pengurus WHERE id = $identitas") === 0, 'H-4 menonaktifkan guru lewat halaman Data Guru ikut menonaktifkan identitas');
$r = $g->req('/portal/santri_binaan.php');
$assert($r['status'] === 200 && str_contains($r['body'], 'Belum ada santri binaan') && !str_contains($r['body'], 'santri_binaan.php?id='),
    'H-4 sesi guru yang sama: daftar binaan langsung kosong');
$r = $g->req('/portal/santri_binaan.php?id=' . $santriBinaan);
$assert($r['status'] === 403, 'H-4 sesi guru yang sama: profil santri binaan ditolak 403 (status ' . $r['status'] . ')');
$admin->req('/admin/admin_guru.php', ['_csrf' => $admin->csrf('/admin/admin_guru.php'), 'id' => $guru, 'action' => 'activate']);
$g->req('/portal/peran.php', ['_csrf' => $g->csrf(), 'peran' => 'pengurus', 'kembali' => '/portal/index.php']);
$r = $g->req('/portal/santri_binaan.php?id=' . $santriBinaan);
$assert((int) $satu("SELECT is_active FROM pengurus WHERE id = $identitas") === 1 && $r['status'] === 200, 'H-4 mengaktifkan guru memulihkan akses');

// --- H-5 Data Pengurus dan Data Guru ----------------------------------------------
$r = $admin->req('/admin/admin_pengurus.php?state=active&q=' . rawurlencode((string) $satu("SELECT nama FROM pengurus WHERE id = $identitas")));
$assert($r['status'] === 200 && str_contains($r['body'], 'ah-badge--info">Guru') && str_contains($r['body'], 'Buka data guru'), 'H-5 Data Pengurus menandai identitas milik guru');
$r = $admin->req('/admin/admin_pengurus.php?action=edit&id=' . $identitas);
$assert($r['status'] === 200 && str_contains($r['body'], 'Identitas pengurus milik guru') && !str_contains($r['body'], 'name="jabatan"'), 'H-5 panel ubah identitas tidak menampilkan formulir');
$namaAwal = (string) $satu("SELECT nama FROM pengurus WHERE id = $identitas");
$admin->req('/admin/admin_pengurus.php', ['_csrf' => $admin->csrf('/admin/admin_pengurus.php'), 'action' => 'save', 'id' => $identitas, 'nama' => 'Dibajak', 'jabatan' => 'Keamanan']);
$admin->req('/admin/admin_pengurus.php', ['_csrf' => $admin->csrf('/admin/admin_pengurus.php'), 'action' => 'archive', 'id' => $identitas]);
$r = $admin->req('/admin/admin_pengurus.php');
$assert($satu("SELECT nama FROM pengurus WHERE id = $identitas") === $namaAwal && $satu("SELECT archived_at FROM pengurus WHERE id = $identitas") === null
    && str_contains($r['body'], 'mengikuti Data Guru'), 'H-5 POST ubah/arsip identitas lewat Data Pengurus ditolak dengan pesan');
$r = $admin->req('/admin/admin_guru.php?action=detail&id=' . $guru);
$assert($r['status'] === 200 && str_contains($r['body'], 'Penugasan sebagai pengurus'), 'H-5 panel Data Guru menampilkan penugasan sebagai pengurus');
$r = $admin->req('/admin/admin_pengurus.php?action=detail&id=' . $identitas);
$assert(str_contains($r['body'], 'Milik guru') && str_contains($r['body'], '@sbx_guru_biasa'), 'H-5 detail identitas menunjukkan akun guru yang memakainya');

// --- H-6 halaman lain dan log ---------------------------------------------------------
foreach (['/admin/admin_akun.php', '/admin/admin_akun.php?tab=pengurus', '/admin/admin_penugasan.php?jenis=murobi', '/admin/admin_penugasan.php?jenis=kesehatan&kelola=1', '/admin/admin_pengurus.php?action=create', '/admin/admin_guru.php'] as $path) {
    $r = $admin->req($path);
    $assert($r['status'] === 200, "H-6 $path tetap 200");
}
usleep(300000);
$isiLog = (string) file_get_contents($log);
$assert(preg_match('/PHP (Warning|Notice|Fatal|Deprecated)|Uncaught/', $isiLog) !== 1, 'H-6 log server tanpa galat PHP');

echo PHP_EOL . 'Hasil: ' . $lulus . ' lulus, ' . count($gagal) . ' gagal.' . PHP_EOL;
exit($gagal === [] ? 0 : 1);
