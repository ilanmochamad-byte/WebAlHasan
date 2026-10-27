<?php

declare(strict_types=1);

/**
 * Uji HTTP end-to-end PRD V7 Fase 5 — website (sesi + CSRF) dan REST (bearer
 * token seperti aplikasi) lewat server PHP lokal di atas DB
 * `tests/v7_phase5_db.sh`.
 *
 *   V7F5_DB=test_v7_f5_http_test bash tests/v7_phase5_db.sh
 *   V7F5_DB=test_v7_f5_http_test php tests/v7_phase5_http.php
 *
 *   H-A  jalur wali penuh di WEBSITE: wali membuat (klik ganda), pembimbing
 *        meminta perbaikan dan meneruskan, murobi memutus di halaman V2; wali
 *        membuka notifikasi → rincian; ID/status sama di API dan laporan
 *   H-B  jalur wali penuh lewat API: dua pembimbing → admin menetapkan →
 *        teruskan → nol murobi → admin pengganti; status sama di website
 *   H-C  jalur pengurus V2 di website dan API: buat, putus, batal, koreksi;
 *        tanpa tahap pembimbing
 *   H-D  matriks sembilan akun × (daftar, rincian web/API, mutasi, laporan,
 *        ekspor, tautan notifikasi) atas pengajuan menunggu pembimbing dan
 *        pengajuan yang sudah diteruskan: nol akses lintas santri/peran
 *   H-E  laporan dan cetak lama: laporan V2 web/CSV/cetak/API, laporan V7
 *        web/ekspor = API; tanpa galat
 *   H-F  log server tanpa galat PHP dan tanpa isi alasan
 *
 * Mengubah data uji; bangun ulang DB sebelum mengulang.
 */

$root = dirname(__DIR__);
$db = getenv('V7F5_DB') ?: 'test_v7_f5_http_test';
$port = (int) (getenv('V7F5_PORT') ?: 8971);
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

$log = tempnam(sys_get_temp_dir(), 'v7f5-server-');
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
register_shutdown_function(static function () use ($server): void {
    if (is_resource($server)) {
        proc_terminate($server);
    }
});

final class Klien
{
    private string $jar;

    public function __construct(private string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'v7f5-jar-');
    }

    /** @return array{status:int, body:string, location:?string, header:string} */
    public function req(string $path, ?array $post = null, array $headers = [], ?string $raw = null, string $method = ''): array
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
        if ($method !== '') {
            curl_setopt($h, CURLOPT_CUSTOMREQUEST, $method);
        }
        $out = (string) curl_exec($h);
        $status = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
        $size = (int) curl_getinfo($h, CURLINFO_HEADER_SIZE);
        curl_close($h);
        preg_match('/^Location:\s*(.+)$/mi', substr($out, 0, $size), $m);

        return ['status' => $status, 'body' => substr($out, $size), 'location' => isset($m[1]) ? trim($m[1]) : null, 'header' => substr($out, 0, $size)];
    }

    public function csrf(string $path = '/portal/index.php'): string
    {
        $r = $this->req($path);
        if (preg_match('/name="_csrf" value="([^"]+)"/', $r['body'], $m) !== 1) {
            $r = $this->req('/portal/index.php');
            preg_match('/name="_csrf" value="([^"]+)"/', $r['body'], $m);
        }

        return $m[1] ?? '';
    }

    public function login(string $user): void
    {
        $this->req('/admin/cek_login.php', ['_csrf' => $this->csrf(), 'username' => $user, 'password' => 'Sandbox#123']);
    }
}

$base = 'http://127.0.0.1:' . $port;
$api = static function (string $method, string $path, ?string $token, ?array $body = null) use ($base): array {
    $k = new Klien($base);
    $h = array_merge(['Accept: application/json', 'Content-Type: application/json'], $token === null ? [] : ['Authorization: Bearer ' . $token]);
    $r = $k->req('/api/v1' . $path, null, $h, $body === null ? null : (string) json_encode($body), $method === 'GET' ? '' : $method);
    $r['json'] = json_decode($r['body'], true);
    $r['data'] = $r['json']['data'] ?? null;

    return $r;
};
$conn = app_db();
$satu = static function (string $sql) use ($conn): mixed {
    $r = $conn->query($sql);
    if ($r === false) {
        throw new RuntimeException($conn->error . ' :: ' . $sql);
    }

    return $r->fetch_row()[0] ?? null;
};
$teks = static fn (string $html): string => trim(html_entity_decode((string) preg_replace('/\s+/', ' ', (string) preg_replace('/<[^>]+>/', ' ', (string) preg_replace('#<(script|style|template)\b.*?</\1>#s', '', $html))), ENT_QUOTES));
$sid = static fn (string $nis): int => (int) $satu("SELECT id FROM santri WHERE nis = '$nis'");
$status = static fn (int $id): string => (string) $satu("SELECT status FROM izin_pengajuan WHERE id = $id");
$versi = static fn (int $id): int => (int) $satu("SELECT version FROM izin_pengajuan WHERE id = $id");
$hitung = static fn (string $tabel, string $where): int => (int) $satu("SELECT COUNT(*) FROM $tabel WHERE $where");
$kunci = static fn (string $p): string => 'v7f5h-' . $p . '-' . bin2hex(random_bytes(6));
$PENANDA = 'RAHASIA-HTTP-V7F5';

$akun = ['sbx_admin', 'sbx_v7_wali_a', 'sbx_v7_wali_b', 'sbx_ortu_b', 'sbx_v7_pembimbing_satu', 'sbx_v7_pembimbing_dua', 'sbx_murobi_a', 'sbx_murobi_b', 'sbx_pengurus_a'];
$k = [];
$t = [];
foreach ($akun as $u) {
    $k[$u] = new Klien($base);
    $k[$u]->login($u);
    $r = $api('POST', '/auth/login', null, ['username' => $u, 'password' => 'Sandbox#123', 'device_name' => 'uji-v7-f5']);
    $t[$u] = (string) ($r['data']['token'] ?? '');
}
$assert(count(array_filter($t)) === count($akun), 'Sesi web dan token API untuk sembilan akun');
$sSatu = $sid('V7S0001');
$sDua = $sid('V7S0003');
$sTanpaMurobi = $sid('V7S0006');
$sCadangan = $sid('V7S0008');
$assert(min($sSatu, $sDua, $sTanpaMurobi, $sCadangan) > 0 && $hitung('izin_pengajuan', "asal_pengajuan = 'Wali'") === 0, 'Fixture V7 tersedia; belum ada pengajuan wali');

/** Kirim formulir V7 pada halaman rincian: memilih formulir dari nilai aksi/tindakan tersembunyi. */
$kirim = static function (Klien $kl, int $id, string $aksi, array $isian, ?string $tindakan = null): array {
    $alamat = '/portal/izin_wali_detail.php?id=' . $id;
    $r = $kl->req($alamat);
    preg_match('/name="_csrf" value="([^"]+)"/', $r['body'], $mc);
    $pola = '/<form[^>]*>(?:(?!<\/form>).)*name="aksi" value="' . preg_quote($aksi, '/') . '"(?:(?!<\/form>).)*'
        . ($tindakan === null ? '' : 'name="tindakan" value="' . preg_quote($tindakan, '/') . '"') . '(?:(?!<\/form>).)*<\/form>/s';
    preg_match($pola, $r['body'], $m);
    $form = $m[0] ?? '';
    preg_match('/name="idempotency_key" value="([^"]+)"/', $form, $mk);
    preg_match('/name="version" value="(\d+)"/', $form, $mv);
    preg_match('/name="mode" value="([^"]*)"/', $form, $mm);
    $post = ['_csrf' => $mc[1] ?? '', 'aksi' => $aksi, 'mode' => $mm[1] ?? '', 'pengajuan_id' => $id, 'version' => $mv[1] ?? '', 'idempotency_key' => $mk[1] ?? '']
        + ($tindakan === null ? [] : ['tindakan' => $tindakan]) + $isian;

    return ['ada' => $form !== '', 'post' => $post, 'r' => $kl->req($alamat, $post)];
};
/** Keputusan V2 lewat halaman web `portal/izin_aksi.php`. */
$putusWeb = static function (Klien $kl, int $id, string $mode, string $hasil, ?string $pengganti = null) use ($versi): array {
    $alamat = '/portal/izin_detail.php?id=' . $id . '&mode=' . $mode;
    $r = $kl->req($alamat);
    preg_match('/name="_csrf" value="([^"]+)"/', $r['body'], $mc);
    preg_match('/<form[^>]*>(?:(?!<\/form>).)*name="aksi" value="putuskan"(?:(?!<\/form>).)*<\/form>/s', $r['body'], $m);
    preg_match('/name="idempotency_key" value="([^"]+)"/', $m[0] ?? '', $mk);
    $post = ['_csrf' => $mc[1] ?? '', 'aksi' => 'putuskan', 'mode' => $mode, 'pengajuan_id' => $id, 'version' => $versi($id),
        'idempotency_key' => $mk[1] ?? ('v7f5h-putus-' . bin2hex(random_bytes(5))), 'hasil' => $hasil, 'alasan' => 'Keputusan web uji F5']
        + ($pengganti === null ? [] : ['alasan_penggantian' => $pengganti]);

    return ['form' => ($m[0] ?? '') !== '', 'post' => $post, 'r' => $kl->req('/portal/izin_aksi.php', $post)];
};

// ===================================================================== H-A
echo PHP_EOL . '=== H-A. Jalur wali penuh di website ===' . PHP_EOL;
$halBuat = '/portal/izin_wali_buat.php?santri_id=' . $sCadangan;
$r = $k['sbx_v7_wali_a']->req($halBuat);
preg_match('/name="_csrf" value="([^"]+)"/', $r['body'], $mc);
preg_match('/name="idempotency_key" value="([^"]+)"/', $r['body'], $mk);
$form = ['_csrf' => $mc[1] ?? '', 'idempotency_key' => $mk[1] ?? '', 'santri_id' => (string) $sCadangan, 'tgl_izin' => '2027-02-01', 'tgl_kembali' => '2027-02-02',
    'alasan' => $PENANDA . ' walimah keluarga', 'catatan' => 'Dijemput paman'];
$r1 = $k['sbx_v7_wali_a']->req($halBuat, $form);
$r2 = $k['sbx_v7_wali_a']->req($halBuat, $form);
preg_match('/id=(\d+)/', (string) $r1['location'], $m);
$idA = (int) ($m[1] ?? 0);
$assert($r1['status'] === 303 && $r2['status'] === 303 && $idA > 0 && $hitung('izin_pengajuan', "santri_id = $sCadangan AND asal_pengajuan = 'Wali'") === 1,
    'Wali mengirim formulir web dua kali (klik ganda/muat ulang) → satu pengajuan, keduanya dialihkan ke rincian');
$assert($k['sbx_v7_wali_a']->req($halBuat, ['_csrf' => 'salah'] + $form)['status'] === 419, 'POST tanpa CSRF sah ditolak 419');
$c = $kirim($k['sbx_v7_pembimbing_satu'], $idA, 'tinjau', ['catatan' => 'Mohon cantumkan jam jemput'], 'minta_perbaikan');
$assert($c['ada'] && $c['r']['status'] === 303 && $status($idA) === 'Perlu Perbaikan Wali', 'Pembimbing meminta perbaikan dari halaman web');
$d = $api('GET', '/v7/izin/pengajuan/' . $idA, $t['sbx_v7_wali_a'])['data'];
$c = $kirim($k['sbx_v7_wali_a'], $idA, 'perbaiki', ['tgl_izin' => '2027-02-01', 'tgl_kembali' => '2027-02-03', 'alasan' => $PENANDA . ' walimah, dijemput 16.00', 'catatan' => 'Dijemput paman']);
$assert(($d['tindakan']['perbaiki'] ?? false) && $c['ada'] && $c['r']['status'] === 303 && $status($idA) === 'Menunggu Pembimbing', 'Wali memperbaiki dari halaman web; API menandai tindakan perbaiki');
$c = $kirim($k['sbx_v7_pembimbing_satu'], $idA, 'tinjau', [], 'teruskan');
$assert($c['ada'] && $status($idA) === 'Diajukan' && (int) $satu("SELECT murobi_guru_id FROM izin_pengajuan WHERE id = $idA") > 0, 'Pembimbing meneruskan dari halaman web → murobi tunggal');
$p = $putusWeb($k['sbx_murobi_a'], $idA, 'murobi', 'Disetujui');
$assert($p['form'] && $p['r']['status'] === 303 && $status($idA) === 'Disetujui' && $hitung('izin_keputusan', "pengajuan_id = $idA") === 1,
    'Murobi memutus dari halaman keputusan V2 pada ID yang sama; satu keputusan');
$dApi = $api('GET', '/v7/izin/pengajuan/' . $idA, $t['sbx_v7_wali_a'])['data'];
$web = $teks($k['sbx_v7_wali_a']->req('/portal/izin_wali_detail.php?id=' . $idA)['body']);
$lap = $api('GET', '/v7/izin/laporan?santri_id=' . $sCadangan . '&asal=wali', $t['sbx_admin'])['data'];
$assert(($dApi['pengajuan']['status'] ?? '') === 'Disetujui' && str_contains($web, 'Disetujui') && in_array($idA, array_column($lap['items'] ?? [], 'id'), true),
    'Status dan ID sama di API wali, halaman wali, dan laporan admin');
$notifTerbaru = static fn (string $u): int => (int) $satu("SELECT o.id FROM notifikasi_outbox o JOIN users u ON u.id = o.penerima_user_id
    WHERE u.username = '$u' AND o.kanal = 'InApp' AND o.event_key LIKE 'v7:izin:%' ORDER BY o.id DESC LIMIT 1");
$notifId = $notifTerbaru('sbx_v7_wali_a');
$buka = $notifId > 0 ? $k['sbx_v7_wali_a']->req('/portal/notifikasi.php?buka=' . $notifId) : ['status' => 0, 'location' => null];
$assert($buka['status'] === 302 && str_contains((string) $buka['location'], 'izin_wali_detail.php?id=' . $idA), 'Notifikasi wali dibuka → rincian pengajuan yang sama');
$bukaLain = $k['sbx_v7_wali_b']->req('/portal/notifikasi.php?buka=' . $notifId);
$assert(!str_contains((string) $bukaLain['location'], 'izin_wali_detail.php?id=' . $idA) && !str_contains($bukaLain['body'], $PENANDA), 'Wali kedua tidak dapat membuka notifikasi milik wali pengaju');

// ===================================================================== H-B
echo PHP_EOL . '=== H-B. Jalur wali penuh lewat API (aplikasi) ===' . PHP_EOL;
$isi = ['santri_id' => $sDua, 'tgl_izin' => '2027-02-10', 'tgl_kembali' => '2027-02-11', 'alasan' => $PENANDA . ' kontrol dokter', 'idempotency_key' => $kunci('buat')];
$b1 = $api('POST', '/v7/izin/wali/pengajuan', $t['sbx_v7_wali_a'], $isi);
$b2 = $api('POST', '/v7/izin/wali/pengajuan', $t['sbx_v7_wali_a'], $isi);
$idB = (int) ($b1['data']['id'] ?? 0);
$assert($b1['status'] === 201 && $b2['status'] === 200 && (int) ($b2['data']['id'] ?? 0) === $idB && ($b1['data']['status'] ?? '') === 'Perlu Penetapan Pembimbing',
    'API: retry berkunci sama → ID sama; dua pembimbing → Perlu Penetapan Pembimbing');
$kand = $api('GET', '/v7/izin/pengajuan/' . $idB . '/kandidat-pembimbing', $t['sbx_admin'])['data']['items'] ?? [];
$pengurus2 = (int) $satu("SELECT pengurus_id FROM users WHERE username = 'sbx_v7_pembimbing_dua'");
$assert(count($kand) === 2 && $api('GET', '/v7/izin/pengajuan/' . $idB . '/kandidat-pembimbing', $t['sbx_v7_pembimbing_satu'])['status'] === 403, 'Kandidat dua pembimbing hanya untuk admin');
$r = $api('POST', '/v7/izin/pengajuan/' . $idB . '/penetapan-pembimbing', $t['sbx_admin'], ['pengurus_id' => $pengurus2, 'alasan' => 'Pembimbing kelas', 'version' => $versi($idB), 'idempotency_key' => $kunci('tetap')]);
$assert($r['status'] === 200 && $status($idB) === 'Menunggu Pembimbing', 'Admin menetapkan Pembimbing Dua lewat API');
$assert($api('POST', '/v7/izin/pengajuan/' . $idB . '/tinjauan', $t['sbx_v7_pembimbing_satu'], ['tindakan' => 'teruskan', 'version' => $versi($idB), 'idempotency_key' => $kunci('t')])['status'] === 404,
    'Pembimbing yang tidak ditetapkan → 404');
$r = $api('POST', '/v7/izin/pengajuan/' . $idB . '/tinjauan', $t['sbx_v7_pembimbing_dua'], ['tindakan' => 'teruskan', 'version' => $versi($idB), 'idempotency_key' => $kunci('t')]);
echo '[info] Routing murobi V7S0003: ' . $status($idB) . PHP_EOL;
$assert($r['status'] === 200 && in_array($status($idB), ['Diajukan', 'Perlu Penetapan Admin'], true), 'Pembimbing Dua meneruskan lewat API');
$r = $api('POST', '/izin/pengajuan/' . $idB . '/keputusan', $t['sbx_admin'], ['hasil' => 'Ditolak', 'alasan' => 'Admin pengganti API', 'alasan_penggantian' => 'Murobi berhalangan',
    'version' => $versi($idB), 'idempotency_key' => $kunci('ap'), 'mode' => 'admin']);
$assert(in_array($r['status'], [200, 201], true) && $status($idB) === 'Ditolak' && $hitung('izin_keputusan', "pengajuan_id = $idB AND kapasitas = 'Admin Pengganti'") === 1,
    'Admin pengganti beralasan memutus lewat API V2 pada ID yang sama [' . $r['status'] . ']');
$assert(str_contains($teks($k['sbx_v7_wali_a']->req('/portal/izin_wali_detail.php?id=' . $idB)['body']), 'Ditolak')
    && ($api('GET', '/v7/izin/pengajuan/' . $idB, $t['sbx_v7_wali_a'])['data']['pengajuan']['status'] ?? '') === 'Ditolak', 'Wali melihat hasil yang sama di website dan API');

// ===================================================================== H-C
echo PHP_EOL . '=== H-C. Jalur pengurus V2 di website dan API ===' . PHP_EOL;
$halV2 = '/portal/izin_buat.php?mode=pengurus';
$r = $k['sbx_v7_pembimbing_satu']->req($halV2);
preg_match('/name="_csrf" value="([^"]+)"/', $r['body'], $mc);
preg_match('/name="idempotency_key" value="([^"]+)"/', $r['body'], $mk);
$fv2 = ['_csrf' => $mc[1] ?? '', 'aksi' => 'buat', 'mode' => 'pengurus', 'idempotency_key' => $mk[1] ?? '', 'santri_id' => (string) $sSatu,
    'tgl_izin' => '2027-02-20', 'tgl_kembali' => '2027-02-21', 'alasan' => $PENANDA . ' jalur pengurus', 'catatan_pengurus' => 'Uji F5'];
$a1 = $k['sbx_v7_pembimbing_satu']->req('/portal/izin_aksi.php', $fv2);
$a2 = $k['sbx_v7_pembimbing_satu']->req('/portal/izin_aksi.php', $fv2);
preg_match('/id=(\d+)/', (string) $a1['location'], $m);
$idV2 = (int) ($m[1] ?? 0);
$assert($a1['status'] === 303 && $a2['status'] === 303 && $idV2 > 0 && $hitung('izin_pengajuan', "santri_id = $sSatu AND tgl_izin = '2027-02-20'") === 1
    && $hitung('izin_pengajuan', "id = $idV2 AND asal_pengajuan = 'Pengurus' AND pembimbing_pengurus_id IS NULL") === 1, 'Pengurus membuat pengajuan V2 dari web (klik ganda → satu), asal Pengurus');
$p = $putusWeb($k['sbx_murobi_a'], $idV2, 'murobi', 'Disetujui');
$assert($status($idV2) === 'Disetujui' && $hitung('izin_pembimbing_tinjauan', "pengajuan_id = $idV2") === 0, 'Murobi memutus pengajuan pengurus dari web tanpa tahap pembimbing');
$r = $api('POST', '/izin/pengajuan/' . $idV2 . '/koreksi', $t['sbx_admin'], ['hasil' => 'Ditolak', 'alasan' => 'Dikoreksi', 'alasan_koreksi' => 'Salah input', 'version' => $versi($idV2), 'idempotency_key' => $kunci('kor')]);
$assert($r['status'] === 200 && $status($idV2) === 'Ditolak' && $hitung('izin_keputusan', "pengajuan_id = $idV2") === 1, 'Admin mengoreksi keputusan V2 lewat API (satu keputusan)');
$cv = ['santri_id' => $sSatu, 'tgl_izin' => '2027-02-24', 'tgl_kembali' => '2027-02-25', 'alasan' => 'Pengurus API', 'idempotency_key' => $kunci('v2'), 'mode' => 'pengurus'];
$c1 = $api('POST', '/izin/pengajuan', $t['sbx_v7_pembimbing_satu'], $cv);
$idV2b = (int) ($c1['data']['id'] ?? ($c1['data']['pengajuan']['id'] ?? 0));
$c2 = $api('POST', '/izin/pengajuan', $t['sbx_v7_pembimbing_satu'], $cv);
$assert(in_array($c1['status'], [200, 201], true) && $idV2b > 0 && $hitung('izin_pengajuan', "santri_id = $sSatu AND tgl_izin = '2027-02-24'") === 1, 'Pengurus membuat lewat API V2; retry tidak menambah baris');
$r = $api('POST', '/izin/pengajuan/' . $idV2b . '/pembatalan', $t['sbx_v7_pembimbing_satu'], ['alasan' => 'Batal pengurus API', 'version' => $versi($idV2b), 'idempotency_key' => $kunci('bv2'), 'mode' => 'pengurus']);
$assert($r['status'] === 200 && $status($idV2b) === 'Dibatalkan', 'Pengurus membatalkan lewat API V2');
$wv2 = $api('POST', '/v7/izin/wali/pengajuan', $t['sbx_v7_wali_a'], ['santri_id' => $sSatu, 'tgl_izin' => '2027-02-24', 'tgl_kembali' => '2027-02-24', 'alasan' => 'Lepas sesudah batal', 'idempotency_key' => $kunci('lepas')]);
$assert($wv2['status'] === 201, 'Rentang pengajuan pengurus yang dibatalkan dilepas untuk pengajuan wali');
$idLepas = (int) ($wv2['data']['id'] ?? 0);
$r = $api('POST', '/izin/pengajuan/' . $idLepas . '/pembatalan', $t['sbx_admin'], ['alasan' => 'Admin mencoba', 'version' => $versi($idLepas), 'idempotency_key' => $kunci('x'), 'mode' => 'admin']);
$assert(in_array($r['status'], [403, 409], true) && $status($idLepas) === 'Menunggu Pembimbing' && $versi($idLepas) === 1,
    'Pembatalan V2 (admin) atas pengajuan wali di tahap pembimbing ditolak tanpa perubahan [' . $r['status'] . ']');
$bentur = $api('POST', '/izin/pengajuan', $t['sbx_v7_pembimbing_satu'], ['santri_id' => $sSatu, 'tgl_izin' => '2027-02-24', 'tgl_kembali' => '2027-02-25', 'alasan' => 'Bentur',
    'idempotency_key' => $kunci('bentur'), 'mode' => 'pengurus']);
$assert($bentur['status'] === 409, 'Pengajuan pengurus bertumpang tindih dengan pengajuan wali aktif → 409');

// ===================================================================== H-D
echo PHP_EOL . '=== H-D. Matriks akses sembilan akun ===' . PHP_EOL;
$idMenunggu = (int) ($api('POST', '/v7/izin/wali/pengajuan', $t['sbx_v7_wali_a'], ['santri_id' => $sCadangan, 'tgl_izin' => '2027-03-01', 'tgl_kembali' => '2027-03-02',
    'alasan' => $PENANDA . ' matriks', 'idempotency_key' => $kunci('mx')])['data']['id'] ?? 0);
$idTerus = (int) ($api('POST', '/v7/izin/wali/pengajuan', $t['sbx_v7_wali_a'], ['santri_id' => $sCadangan, 'tgl_izin' => '2027-03-05', 'tgl_kembali' => '2027-03-06',
    'alasan' => $PENANDA . ' matriks terus', 'idempotency_key' => $kunci('mx2')])['data']['id'] ?? 0);
$api('POST', '/v7/izin/pengajuan/' . $idTerus . '/tinjauan', $t['sbx_v7_pembimbing_satu'], ['tindakan' => 'teruskan', 'version' => $versi($idTerus), 'idempotency_key' => $kunci('t')]);
$assert($idMenunggu > 0 && $status($idMenunggu) === 'Menunggu Pembimbing' && $status($idTerus) === 'Diajukan', 'Dua pengajuan uji matriks: menunggu pembimbing dan sudah diteruskan');
$vT = $versi($idTerus);
$r = $api('POST', '/izin/pengajuan/' . $idTerus . '/pembatalan', $t['sbx_admin'], ['alasan' => 'Admin mencoba', 'version' => $vT, 'idempotency_key' => $kunci('x'), 'mode' => 'admin']);
$assert($r['status'] === 403 && $status($idTerus) === 'Diajukan' && $versi($idTerus) === $vT, 'Pembatalan V2 (admin) atas pengajuan wali berstatus V2 ditolak 403 tanpa perubahan [' . $r['status'] . ']');
// Harapan: penuh = isi + mutasi sesuai peran; ringkas = status tanpa isi/tindakan; tidak = 404/tanpa item.
$harap = [
    'sbx_admin' => ['penuh', 'penuh'],
    'sbx_v7_wali_a' => ['penuh', 'penuh'],
    'sbx_v7_wali_b' => ['ringkas', 'ringkas'],
    'sbx_ortu_b' => ['tidak', 'tidak'],
    'sbx_v7_pembimbing_satu' => ['penuh', 'penuh'],
    'sbx_v7_pembimbing_dua' => ['tidak', 'tidak'],
    'sbx_murobi_a' => ['tidak', 'penuh'],
    'sbx_murobi_b' => ['tidak', 'tidak'],
    'sbx_pengurus_a' => ['tidak', 'tidak'],
];
$salah = [];
foreach ($harap as $u => $perId) {
    foreach ([$idMenunggu, $idTerus] as $i => $id) {
        $h = $perId[$i];
        $rApi = $api('GET', '/v7/izin/pengajuan/' . $id, $t[$u]);
        $rWeb = $k[$u]->req('/portal/izin_wali_detail.php?id=' . $id);
        $isiTerlihat = str_contains($rApi['body'], $PENANDA) || str_contains($rWeb['body'], $PENANDA);
        $ok = match ($h) {
            'penuh' => $rApi['status'] === 200 && $rWeb['status'] === 200 && $isiTerlihat,
            'ringkas' => $rApi['status'] === 200 && $rWeb['status'] === 200 && !$isiTerlihat && ($rApi['data']['tindakan'] ?? null) === [],
            default => $rApi['status'] !== 200 && $rWeb['status'] !== 200 && !$isiTerlihat,
        };
        // Laporan: hanya pemegang cakupan melihat ID; wali kedua melihat ringkas.
        $lap = $api('GET', '/v7/izin/laporan?santri_id=' . $sCadangan, $t[$u]);
        $diLaporan = in_array($id, array_column($lap['data']['items'] ?? [], 'id'), true);
        $harusLaporan = $h !== 'tidak' && !($u === 'sbx_murobi_a' && $i === 0);
        $eks = $k[$u]->req('/portal/izin_wali_laporan_ekspor.php?santri_id=' . $sCadangan);
        $diEkspor = $eks['status'] === 200 && preg_match('/^' . $id . ',/m', $eks['body']) === 1;
        if (!$ok || $diLaporan !== $harusLaporan || $diEkspor !== $harusLaporan || str_contains($lap['body'] . $eks['body'], $PENANDA)) {
            $salah[] = "$u#$id(api {$rApi['status']}, web {$rWeb['status']}, isi " . ($isiTerlihat ? 'ya' : 'tidak') . ', laporan ' . ($diLaporan ? 'ya' : 'tidak') . ', ekspor ' . ($diEkspor ? 'ya' : 'tidak') . ')';
        }
    }
}
$assert($salah === [], 'Rincian web/API, laporan, dan ekspor: 9 akun × 2 pengajuan sesuai peran; laporan/ekspor tanpa alasan' . ($salah === [] ? '' : ': ' . implode('; ', $salah)));
// Mutasi oleh akun yang tidak berhak.
$mutasiSalah = [];
foreach (['sbx_v7_wali_b', 'sbx_ortu_b', 'sbx_v7_pembimbing_dua', 'sbx_murobi_a', 'sbx_pengurus_a', 'sbx_admin'] as $u) {
    $v = $versi($idMenunggu);
    $codes = [
        'batal' => $api('POST', '/v7/izin/pengajuan/' . $idMenunggu . '/pembatalan', $t[$u], ['alasan' => 'x', 'version' => $v, 'idempotency_key' => $kunci('mb')])['status'],
        'tinjau' => $api('POST', '/v7/izin/pengajuan/' . $idMenunggu . '/tinjauan', $t[$u], ['tindakan' => 'teruskan', 'version' => $v, 'idempotency_key' => $kunci('mt')])['status'],
        'putus' => $api('POST', '/izin/pengajuan/' . $idMenunggu . '/keputusan', $t[$u], ['hasil' => 'Disetujui', 'alasan' => 'x', 'version' => $v, 'idempotency_key' => $kunci('mp')])['status'],
    ];
    if (in_array(200, $codes, true) || in_array(201, $codes, true) || in_array(500, $codes, true)) {
        $mutasiSalah[] = $u . json_encode($codes);
    }
}
$assert($mutasiSalah === [] && $status($idMenunggu) === 'Menunggu Pembimbing' && $versi($idMenunggu) === 1 && $hitung('izin_keputusan', "pengajuan_id = $idMenunggu") === 0,
    'Enam akun tanpa hak (termasuk admin untuk tinjauan/batal wali dan murobi sebelum penerusan): batal/tinjau/putus ditolak, tanpa perubahan' . ($mutasiSalah === [] ? '' : ': ' . implode('; ', $mutasiSalah)));
// Tautan notifikasi lintas akun.
$notifPb = $notifTerbaru('sbx_v7_pembimbing_satu');
$bocorNotif = [];
foreach (['sbx_v7_pembimbing_dua', 'sbx_ortu_b', 'sbx_v7_wali_b', 'sbx_murobi_b'] as $u) {
    $r = $k[$u]->req('/portal/notifikasi.php?buka=' . $notifPb);
    $rApi = $api('GET', '/notifikasi', $t[$u]);
    if (str_contains((string) $r['location'], 'izin_wali_detail') || str_contains($r['body'] . $rApi['body'], $PENANDA) || in_array($notifPb, array_column($rApi['data']['items'] ?? [], 'id'), true)) {
        $bocorNotif[] = $u;
    }
}
$assert($notifPb > 0 && $bocorNotif === [], 'Notifikasi pembimbing tidak dapat dibuka/dibaca akun lain (web dan API)');
// Pencabutan pada token/sesi lama.
$relasi = (int) $satu("SELECT sw.id FROM santri_wali sw JOIN users u ON u.wali_id = sw.wali_id WHERE u.username = 'sbx_v7_wali_a' AND sw.santri_id = $sCadangan");
$conn->query("UPDATE santri_wali SET archived_at = NOW() WHERE id = $relasi");
$rApi = $api('GET', '/v7/izin/pengajuan/' . $idMenunggu, $t['sbx_v7_wali_a']);
$rWeb = $k['sbx_v7_wali_a']->req('/portal/izin_wali_detail.php?id=' . $idMenunggu);
$notifWali = $notifTerbaru('sbx_v7_wali_a');
$rBuka = $k['sbx_v7_wali_a']->req('/portal/notifikasi.php?buka=' . $notifWali);
$tujuan = (string) $rBuka['location'];
$rTujuan = $tujuan !== '' ? $k['sbx_v7_wali_a']->req((string) parse_url($tujuan, PHP_URL_PATH) . '?' . (string) parse_url($tujuan, PHP_URL_QUERY)) : ['status' => 0, 'body' => ''];
$assert($rApi['status'] === 404 && $rWeb['status'] === 404 && !str_contains($rTujuan['body'], $PENANDA), 'Relasi dicabut: token dan sesi lama 404; tautan notifikasi tidak menampilkan isi');
$conn->query("UPDATE santri_wali SET archived_at = NULL WHERE id = $relasi");

// ===================================================================== H-E
echo PHP_EOL . '=== H-E. Laporan dan cetak lama, laporan V7 ===' . PHP_EOL;
$lama = [
    ['sbx_admin', '/portal/laporan.php?mode=admin'], ['sbx_admin', '/portal/laporan_cetak.php?mode=admin'], ['sbx_admin', '/portal/laporan_csv.php?mode=admin'],
    ['sbx_murobi_a', '/portal/laporan.php?mode=murobi'], ['sbx_murobi_a', '/portal/laporan_cetak.php?mode=murobi'],
    ['sbx_v7_pembimbing_satu', '/portal/laporan.php?mode=pengurus'], ['sbx_v7_wali_a', '/portal/izin.php?mode=orang_tua'],
    ['sbx_admin', '/portal/izin_wali_laporan.php'], ['sbx_admin', '/portal/izin_wali.php'], ['sbx_v7_pembimbing_satu', '/portal/izin_wali.php'],
];
$tidakOk = [];
foreach ($lama as [$u, $hal]) {
    $r = $k[$u]->req($hal);
    if ($r['status'] !== 200 || preg_match('/Fatal error|Warning:|Notice:|Deprecated:/', $r['body']) === 1) {
        $tidakOk[] = $u . ' ' . $hal . ' ' . $r['status'];
    }
}
$assert($tidakOk === [], 'Laporan/cetak/CSV V2, daftar orang tua V2, dan halaman V7 terbuka 200 tanpa galat (10 halaman)' . ($tidakOk === [] ? '' : ': ' . implode('; ', $tidakOk)));
$csvV2 = $k['sbx_admin']->req('/portal/laporan_csv.php?mode=admin');
$assert(str_contains(strtolower($csvV2['header']), 'text/csv') && !str_contains($csvV2['body'], $PENANDA . ' matriks'), 'CSV laporan V2 tetap CSV');
foreach (['/izin/laporan?mode=admin', '/izin/laporan/cetak?mode=admin'] as $p) {
    $r = $api('GET', $p, $t['sbx_admin']);
    $assert($r['status'] === 200, 'API laporan V2 ' . $p . ' tetap 200');
}
$lapApi = $api('GET', '/v7/izin/laporan', $t['sbx_admin'])['data'];
$eks = $k['sbx_admin']->req('/portal/izin_wali_laporan_ekspor.php');
$barisCsv = max(0, count(array_filter(explode("\n", trim($eks['body'])))) - 1);
$sumber = $hitung('izin_pengajuan', '1');
$assert(($lapApi['total'] ?? -1) === $sumber && $barisCsv === $sumber && array_sum(array_column($lapApi['ringkasan']['per_asal'] ?? [], 'jumlah')) >= 0,
    'Laporan V7 admin: total API = baris ekspor web = ' . $sumber . ' pengajuan sumber (kedua asal dihitung tepat sekali)');
$lapWeb = $teks($k['sbx_admin']->req('/portal/izin_wali_laporan.php')['body']);
$assert(str_contains($lapWeb, number_format($sumber, 0, ',', '.')) || str_contains($lapWeb, (string) $sumber), 'Halaman laporan V7 menampilkan total yang sama');

// ===================================================================== H-F
echo PHP_EOL . '=== H-F. Log server ===' . PHP_EOL;
proc_terminate($server);
usleep(300000);
$isiLog = (string) file_get_contents($log);
$assert(preg_match('/PHP (Warning|Notice|Fatal|Deprecated)|Uncaught/', $isiLog) !== 1, 'Log server tanpa galat PHP');
$assert(!str_contains($isiLog, $PENANDA) && !preg_match('/Bearer [A-Za-z0-9]{20,}/', $isiLog), 'Log server tanpa isi alasan dan tanpa token mentah');

echo PHP_EOL . 'Hasil: ' . $lulus . ' lulus, ' . count($gagal) . ' gagal' . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
