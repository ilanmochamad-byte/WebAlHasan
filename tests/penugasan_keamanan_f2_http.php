<?php

declare(strict_types=1);

/**
 * Uji server Fase 2 penugasan lintas guru/pengurus — pemisahan tugas lewat
 * halaman web dan REST API (server PHP lokal) di atas DB uji
 * `tests/penugasan_keamanan_f2_db.sh`. Skenario dibentuk lewat layanan resmi:
 * akun X (`sbx_guru_biasa`) menjadi pembimbing dan murobi kamar/kelas yang sama.
 *
 *   PKF2_DB=test_pk_f2_http_test bash tests/penugasan_keamanan_f2_db.sh
 *   PKF2_DB=test_pk_f2_http_test php tests/penugasan_keamanan_f2_http.php
 *
 *   W-1 izin: rincian murobi X tanpa tombol Putuskan + penjelasan; POST web/REST ditolak
 *   W-2 izin: REST aksi tanpa keputusan bagi pengaju, kunci aksi tetap; penetapan admin X → 422
 *   W-3 izin: admin pembuat tanpa Putuskan pengganti; admin lain melihatnya
 *   W-4 sikap: REST murobi X ditolak; halaman murobi menjelaskan dan tidak menawarkan centang
 *   W-5 PK-T1: halaman verifikasi X menjelaskan, tanpa tombol; REST verifikasi ditolak
 *   W-6 V3: rincian pelanggaran X tanpa "Tandai mengetahui"; REST ditolak
 *   W-7 akun tak terlibat tetap dapat bertindak; halaman lain 200; log tanpa galat PHP
 *
 * Mengubah data uji; bangun ulang DB sebelum mengulang.
 */

$root = dirname(__DIR__);
$db = getenv('PKF2_DB') ?: 'test_pk_f2_http_test';
$port = (int) (getenv('PKF2_PORT') ?: 8974);
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
$log = tempnam(sys_get_temp_dir(), 'pkf2-server-');
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
        $this->jar = tempnam(sys_get_temp_dir(), 'pkf2-jar-');
    }

    /** @return array{status:int, body:string, location:?string} */
    public function req(string $path, ?array $post = null, array $headers = [], ?string $raw = null): array
    {
        $h = curl_init($this->base . $path);
        curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $headers]);
        if ($post !== null) {
            curl_setopt($h, CURLOPT_POST, true);
            curl_setopt($h, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        if ($raw !== null) {
            curl_setopt($h, CURLOPT_POST, true);
            curl_setopt($h, CURLOPT_POSTFIELDS, $raw);
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

    public function peran(string $peran): void
    {
        $this->req('/portal/peran.php', ['_csrf' => $this->csrf(), 'peran' => $peran, 'kembali' => '/portal/index.php']);
    }
}

$base = 'http://127.0.0.1:' . $port;
$web = static function (string $user) use ($base): Klien {
    $k = new Klien($base);
    $k->login($user);

    return $k;
};
$api = static function (string $method, string $path, ?string $token, ?array $body = null) use ($base): array {
    $k = new Klien($base);
    $h = array_merge(['Accept: application/json', 'Content-Type: application/json'], $token === null ? [] : ['Authorization: Bearer ' . $token]);
    $r = $method === 'GET' ? $k->req('/api/v1' . $path, null, $h) : $k->req('/api/v1' . $path, null, $h, (string) json_encode($body ?? []));
    $r['json'] = json_decode($r['body'], true);

    return $r;
};
$token = static function (string $user) use ($api): string {
    return (string) ($api('POST', '/auth/login', null, ['username' => $user, 'password' => 'Sandbox#123', 'device_name' => 'uji-pk-f2'])['json']['data']['token'] ?? '');
};
$conn = app_db();
$satu = static function (string $sql) use ($conn): mixed {
    $r = $conn->query($sql);
    if ($r === false) {
        throw new RuntimeException($conn->error . ' :: ' . $sql);
    }

    return $r->fetch_row()[0] ?? null;
};
$kunci = static fn (string $awal): string => 'pkf2h-' . $awal . '-' . bin2hex(random_bytes(6));

// --- Penyiapan lewat layanan resmi (skenario bersama dengan uji browser) ---------------
$ids = require __DIR__ . '/penugasan_keamanan_f2_seed.php';
['x' => $x, 'guru_x' => $guruX, 'tahun' => $tahun, 'rencana' => $rencana, 'rm_x' => $rmX, 'santri_kelas' => $santriKelas,
    'izin_x' => $izinX, 'izin_admin' => $izinAdmin, 'pelanggaran' => $idPel, 'versi_sikap' => $versiSikap] = $ids;
$assert($izinX > 0 && $izinAdmin > 0 && $versiSikap > 0 && $idPel > 0, 'penyiapan: izin dipaksa ke X, izin buatan admin, sikap dan nilai isian X, pelanggaran catatan X');

$tX = $token('sbx_guru_biasa');
$tAdmin = $token('sbx_admin');
$tAdminLain = $token('sbx_v6_admin_bsa');
$assert($tX !== '' && $tAdmin !== '' && $tAdminLain !== '', 'token API X, admin, dan admin lain');

// --- W-1 izin web ------------------------------------------------------------------------
$wx = $web('sbx_guru_biasa');
$wx->peran('guru');
$r = $wx->req('/portal/izin_detail.php?id=' . $izinX . '&mode=murobi');
$assert($r['status'] === 200 && str_contains($r['body'], 'data-pemisahan-tugas') && !str_contains($r['body'], 'id="aksi-putuskan"'),
    'W-1a rincian izin bagi murobi X (pengaju): penjelasan pemisahan tugas, tanpa dialog Putuskan');
$r = $wx->req('/portal/izin_aksi.php', ['_csrf' => $wx->csrf('/portal/izin_detail.php?id=' . $izinX . '&mode=murobi'), 'aksi' => 'putuskan', 'mode' => 'murobi',
    'pengajuan_id' => $izinX, 'version' => (int) $satu("SELECT version FROM izin_pengajuan WHERE id = $izinX"), 'idempotency_key' => $kunci('web-putus'), 'hasil' => 'Disetujui', 'alasan' => 'Putus sendiri lewat web']);
$assert($r['status'] === 403 && (int) $satu("SELECT COUNT(*) FROM izin_keputusan WHERE pengajuan_id = $izinX") === 0
    && $satu("SELECT status FROM izin_pengajuan WHERE id = $izinX") === 'Diajukan',
    'W-1b POST web keputusan oleh X → halaman Aksi ditolak 403 tanpa baris keputusan (status ' . $r['status'] . ')');
$assert(str_contains($r['body'], 'mengajukan atau meninjau'), 'W-1c halaman penolakan menyebut alasan pemisahan tugas');
$r = $api('POST', '/izin/pengajuan/' . $izinX . '/keputusan?mode=murobi', $tX, ['hasil' => 'Disetujui', 'alasan' => 'Putus sendiri lewat API', 'idempotency_key' => $kunci('api-putus')]);
$assert($r['status'] === 403 && (int) $satu("SELECT COUNT(*) FROM izin_keputusan WHERE pengajuan_id = $izinX") === 0, 'W-1d REST keputusan oleh X → 403 tanpa keputusan');

// --- W-2 izin REST aksi dan penetapan --------------------------------------------------------
$r = $api('GET', '/izin/pengajuan/' . $izinX . '?mode=murobi', $tX);
$aksi = $r['json']['data']['aksi'] ?? null;
$assert($r['status'] === 200 && is_array($aksi) && array_keys($aksi) === ['putuskan_murobi', 'putuskan_admin', 'tetapkan_murobi', 'batalkan', 'koreksi'] && $aksi['putuskan_murobi'] === false,
    'W-2a REST rincian: putuskan_murobi false bagi pengaju, kunci aksi tetap lima (kontrak aplikasi)');
$r = $api('GET', '/izin/pengajuan/' . $izinX . '/routing', $tAdmin);
$berhak = array_map(static fn (array $g): int => (int) ($g['id'] ?? $g['guru_id'] ?? 0), $r['json']['data']['murobi_berhak'] ?? []);
$assert($r['status'] === 200 && !in_array($guruX, $berhak, true), 'W-2b REST routing admin: murobi berhak tidak memuat X');
$r = $api('POST', '/izin/pengajuan/' . $izinX . '/penetapan-murobi', $tAdmin, ['murobi_guru_id' => $guruX, 'alasan' => 'Tetapkan pengaju', 'idempotency_key' => $kunci('api-tetap')]);
$assert($r['status'] === 422, 'W-2c REST penetapan admin atas X → 422 (status ' . $r['status'] . ')');

// --- W-3 admin pengganti ----------------------------------------------------------------------
$wa = $web('sbx_admin');
$r = $wa->req('/portal/izin_detail.php?id=' . $izinAdmin . '&mode=admin');
$assert($r['status'] === 200 && !str_contains($r['body'], 'id="aksi-putuskan"') && str_contains($r['body'], 'data-pemisahan-tugas'),
    'W-3a admin pembuat: tanpa Putuskan pengganti, dengan penjelasan');
$r = $api('GET', '/izin/pengajuan/' . $izinAdmin . '?mode=admin', $tAdminLain);
$assert(($r['json']['data']['aksi']['putuskan_admin'] ?? null) === true, 'W-3b admin lain: putuskan_admin tersedia');
$r = $api('POST', '/izin/pengajuan/' . $izinAdmin . '/keputusan?mode=admin', $tAdmin, ['hasil' => 'Disetujui', 'alasan' => 'Putus sendiri', 'alasan_penggantian' => 'Tanpa murobi', 'idempotency_key' => $kunci('api-admin')]);
$assert($r['status'] === 403, 'W-3c REST admin pembuat memutus → 403');
$r = $api('POST', '/izin/pengajuan/' . $izinAdmin . '/keputusan?mode=admin', $tAdminLain, ['hasil' => 'Disetujui', 'alasan' => 'Admin lain', 'alasan_penggantian' => 'Tanpa murobi', 'idempotency_key' => $kunci('api-admin-lain')]);
$assert($r['status'] === 201, 'W-3d REST admin lain memutus → 201');

// --- W-4 sikap ----------------------------------------------------------------------------------
$r = $api('POST', '/v4/sikap/murobi', $tX, ['rencana_id' => $rencana, 'santri_id' => $santriKelas, 'version' => $versiSikap, 'action' => 'diketahui', 'alasan' => '']);
$assert($r['status'] === 403 && $satu("SELECT diketahui_murobi_user_id FROM v4_sikap WHERE santri_id = $santriKelas AND tahun_ajaran_id = $tahun") === null,
    'W-4a REST murobi X mengetahui sikap isiannya → 403');
$r = $wx->req('/portal/sikap_semester.php?mode=murobi&tahun_ajaran_id=' . $tahun . '&rencana_id=' . $rencana . '&santri_id=' . $santriKelas);
$assert($r['status'] === 200 && str_contains($r['body'], 'Anda mengisi sikap ini sebagai pembimbing') && !str_contains($r['body'], 'name="action" value="diketahui"')
    && str_contains($r['body'], 'data-pemisahan-tugas') && !str_contains($r['body'], 'value="' . $rencana . ':' . $santriKelas . ':'),
    'W-4b halaman Sikap binaan X: penjelasan, tanpa tombol Tandai diketahui, baris tanpa centang');

// --- W-5 PK-T1 ----------------------------------------------------------------------------------
$wx->peran('pendidikan');
$r = $wx->req('/portal/verifikasi_semester.php?rencana_id=' . $rencana . '&santri_id=' . $santriKelas . '&bahan=nilai:' . $rmX);
$assert($r['status'] === 200 && str_contains($r['body'], 'Nilai ini memuat isian Anda sendiri') && !str_contains($r['body'], 'name="action" value="verifikasi"'),
    'W-5a halaman Kelengkapan X: nilai isiannya dijelaskan, tanpa tombol Verifikasi');
$r = $api('GET', '/v4/verifikasi?rencana_id=' . $rencana . '&santri_id=' . $santriKelas, $tX);
$state = null;
foreach ($r['json']['data']['matriks'][0]['bahan'] ?? [] as $m) {
    if ($m['jenis'] === 'nilai' && (int) $m['mapel_key'] === $rmX) {
        $state = $m['pemeriksaan'];
    }
}
$assert($state !== null && in_array($santriKelas . ':' . $rmX, $r['json']['data']['diisi_sendiri'] ?? [], true), 'W-5b REST matriks memuat penanda diisi sendiri');
$r = $api('POST', '/v4/verifikasi', $tX, ['rencana_id' => $rencana, 'santri_id' => $santriKelas, 'jenis' => 'nilai', 'mapel_key' => $rmX, 'action' => 'verifikasi', 'alasan' => '', 'version' => $state['version'] ?? 0, 'sumber_hash' => $state['sumber_hash'] ?? '']);
$assert($r['status'] === 403, 'W-5c REST verifikasi nilai isian sendiri → 403');

// --- W-6 V3 -------------------------------------------------------------------------------------
$wx->peran('guru');
$r = $wx->req('/portal/v3_pelanggaran_detail.php?id=' . $idPel);
$assert($r['status'] === 200 && str_contains($r['body'], 'data-pemisahan-tugas') && !str_contains($r['body'], 'id="aksi-ketahui"'),
    'W-6a rincian pelanggaran catatan X: penjelasan, tanpa Tandai mengetahui');
$r = $api('POST', '/v3/pelanggaran/' . $idPel . '/diketahui', $tX, ['idempotency_key' => $kunci('api-v3')]);
$assert($r['status'] === 403 && (int) $satu("SELECT COUNT(*) FROM v3_murobi_catatan WHERE pelanggaran_id = $idPel") === 0, 'W-6b REST tanda mengetahui X → 403');

// --- W-7 halaman lain dan log --------------------------------------------------------------------
$wm = $web('sbx_murobi_c');
foreach (['/portal/izin_antrean.php?mode=murobi', '/portal/sikap_semester.php?mode=murobi', '/portal/index.php'] as $path) {
    $assert($wm->req($path)['status'] === 200, "W-7 murobi lain $path tetap 200");
}
$r = $wm->req('/portal/sikap_semester.php?mode=murobi&tahun_ajaran_id=' . $tahun . '&rencana_id=' . $rencana . '&santri_id=' . $santriKelas);
$assert(str_contains($r['body'], 'name="action" value="diketahui"') && !str_contains($r['body'], 'Anda mengisi sikap ini'), 'W-7 murobi lain (bukan pengisi) tetap melihat Tandai diketahui');
foreach (['/portal/izin_antrean.php?mode=admin', '/portal/index.php', '/portal/verifikasi_semester.php?rencana_id=' . $rencana] as $path) {
    $assert($wa->req($path)['status'] === 200, "W-7 admin $path tetap 200");
}
usleep(300000);
$isiLog = (string) file_get_contents($log);
$assert(preg_match('/PHP (Warning|Notice|Fatal|Deprecated)|Uncaught/', $isiLog) !== 1, 'W-7 log server tanpa galat PHP');

echo PHP_EOL . 'Hasil: ' . $lulus . ' lulus, ' . count($gagal) . ' gagal.' . PHP_EOL;
exit($gagal === [] ? 0 : 1);
