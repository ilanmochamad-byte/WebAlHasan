<?php

declare(strict_types=1);

/**
 * Uji server PRD V7 Fase 3 — notifikasi tahap (pusat notifikasi web/API dan
 * tautan), pencabutan hak pada sesi/token lama, laporan lintas asal web/API
 * dan ekspor CSV, lewat server PHP lokal di atas DB `tests/v7_phase3_db.sh`.
 *
 *   V7F3_DB=test_v7_f3_http_test bash tests/v7_phase3_db.sh
 *   V7F3_DB=test_v7_f3_http_test php tests/v7_phase3_http.php
 *
 *   H-A  pengajuan wali (API) → pusat notifikasi pembimbing web/API, tautan
 *        membuka rincian; penugasan dicabut → tautan 404 pada sesi/token lama
 *   H-B  teruskan → wali pengaju dan murobi; wali kedua tidak; keputusan V2
 *        lewat API → wali pengaju; relasi dicabut → 404
 *   H-C  laporan API per peran: total = baris CSV, no-store, 403/422
 *   H-D  laporan web per peran: data-total = API, ekspor web = ekspor API,
 *        wali kedua ringkas, saringan tidak valid → peringatan
 *   H-E  log server tanpa galat PHP/isi izin; verifier Fase 2 dan Fase 3
 *
 * Mengubah data uji; bangun ulang DB sebelum mengulang.
 */

$root = dirname(__DIR__);
$db = getenv('V7F3_DB') ?: 'test_v7_f3_http_test';
$port = (int) (getenv('V7F3_PORT') ?: 8997);
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

$log = tempnam(sys_get_temp_dir(), 'v7f3-server-');
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
        $this->jar = tempnam(sys_get_temp_dir(), 'v7f3-jar-');
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
$klien = static function (string $user) use ($base): Klien {
    $k = new Klien($base);
    $k->login($user);

    return $k;
};
$api = static function (string $method, string $path, ?string $token, ?array $body = null, array $headers = []) use ($base): array {
    $k = new Klien($base);
    $h = array_merge(['Accept: application/json', 'Content-Type: application/json'], $token === null ? [] : ['Authorization: Bearer ' . $token], $headers);
    $r = $k->req('/api/v1' . $path, null, $h, $body === null ? null : (string) json_encode($body), $method === 'GET' ? '' : $method);
    $r['json'] = json_decode($r['body'], true);

    return $r;
};
$token = static function (string $user) use ($api): string {
    $r = $api('POST', '/auth/login', null, ['username' => $user, 'password' => 'Sandbox#123', 'device_name' => 'uji-v7-f3']);

    return (string) ($r['json']['data']['token'] ?? '');
};
$conn = app_db();
$satu = static function (string $sql) use ($conn): mixed {
    $r = $conn->query($sql);
    if ($r === false) {
        throw new RuntimeException($conn->error . ' :: ' . $sql);
    }

    return $r->fetch_row()[0] ?? null;
};
$jalankan = static function (string $sql) use ($conn): void {
    if ($conn->query($sql) === false) {
        throw new RuntimeException($conn->error . ' :: ' . $sql);
    }
};
$teks = static fn (string $html): string => trim(html_entity_decode((string) preg_replace('/\s+/', ' ', (string) preg_replace('/<[^>]+>/', ' ', (string) preg_replace('#<(script|style|template)\b.*?</\1>#s', '', $html))), ENT_QUOTES));
$formulir = static function (Klien $kl, string $alamat): array {
    $r = $kl->req($alamat);
    preg_match('/name="idempotency_key" value="([^"]+)"/', $r['body'], $mk);
    preg_match('/name="_csrf" value="([^"]+)"/', $r['body'], $mc);
    preg_match('/name="version" value="(\d+)"/', $r['body'], $mv);

    return ['status' => $r['status'], 'kunci' => $mk[1] ?? '', 'csrf' => $mc[1] ?? '', 'versi' => $mv[1] ?? '', 'body' => $r['body']];
};
$sid = static fn (string $nis): int => (int) $satu("SELECT id FROM santri WHERE nis = '$nis'");
$hitung = static fn (string $tabel, string $where): int => (int) $satu("SELECT COUNT(*) FROM $tabel WHERE $where");
$sCadangan = $sid('V7S0008');
$PENANDA = 'RAHASIA-HTTP-V7F3';
$assert($sCadangan > 0 && $hitung('notifikasi_outbox', "event_key LIKE 'v7:%'") === 0, 'Fixture V7 tersedia; belum ada notifikasi V7');

$akun = ['sbx_admin', 'sbx_v7_wali_a', 'sbx_v7_wali_b', 'sbx_ortu_b', 'sbx_v7_pembimbing_satu', 'sbx_v7_pembimbing_dua', 'sbx_murobi_a', 'sbx_murobi_b',
    'sbx_pengurus_a', 'sbx_guru_biasa'];
$k = [];
$t = [];
foreach ($akun as $u) {
    $k[$u] = $klien($u);
    $t[$u] = $token($u);
}
$assert(count(array_filter($t)) === count($akun), 'Sesi web dan token API untuk sepuluh akun');
$uid = static fn (string $u): int => (int) $satu("SELECT id FROM users WHERE username = '$u'");
$notifApi = static function (string $u, int $id) use ($api, $t): array {
    $r = $api('GET', '/notifikasi?jenis=perizinan&per_page=100', $t[$u]);

    return array_values(array_filter($r['json']['data']['items'] ?? [], static fn ($i) => ($i['tautan']['tipe'] ?? '') === 'izin_wali' && ($i['tautan']['pengajuan_id'] ?? 0) === $id));
};
$buat = static function (int $santri, string $tgl) use ($api, $t, $PENANDA): int {
    $r = $api('POST', '/v7/izin/wali/pengajuan', $t['sbx_v7_wali_a'], ['santri_id' => $santri, 'tgl_izin' => $tgl, 'tgl_kembali' => $tgl,
        'alasan' => $PENANDA . ' acara keluarga', 'idempotency_key' => 'v7f3-http-buat-' . bin2hex(random_bytes(6))]);

    return (int) ($r['json']['data']['id'] ?? 0);
};
$paSatu = (int) $satu("SELECT id FROM pembimbing_assignments WHERE pengurus_id = (SELECT pengurus_id FROM users WHERE username = 'sbx_v7_pembimbing_satu')
    AND kamar_id = (SELECT id FROM kamar WHERE nama_kamar = 'SBX V7 Kamar Satu') AND is_active = 1 LIMIT 1");

// ===================================================================== H-A
echo PHP_EOL . '=== H-A. Notifikasi pembimbing dan tautan ===' . PHP_EOL;
$id1 = $buat($sCadangan, '2027-10-02');
$n = $notifApi('sbx_v7_pembimbing_satu', $id1);
$assert($id1 > 0 && count($n) === 1 && $n[0]['santri_nama'] === null && $n[0]['pengajuan_id'] === null && !str_contains(json_encode($n), $PENANDA),
    'API /notifikasi pembimbing: satu notifikasi izin_wali tanpa nama santri/alasan');
$assert($notifApi('sbx_v7_wali_a', $id1) === [] && $notifApi('sbx_v7_wali_b', $id1) === [] && $notifApi('sbx_murobi_a', $id1) === [] && $notifApi('sbx_v7_pembimbing_dua', $id1) === [],
    'Pengaju, wali kedua, murobi, dan pembimbing lain tidak menerima notifikasi "masuk"');
$halaman = $k['sbx_v7_pembimbing_satu']->req('/portal/notifikasi.php?jenis=perizinan');
$assert($halaman['status'] === 200 && str_contains($teks($halaman['body']), 'Pengajuan izin wali menunggu tinjauan') && !str_contains($halaman['body'], $PENANDA),
    'Pusat notifikasi web pembimbing menampilkan judul generik');
$notifId = (int) $n[0]['id'];
$r = $k['sbx_v7_pembimbing_satu']->req('/portal/notifikasi.php?buka=' . $notifId);
$assert($r['status'] === 302 && str_contains((string) $r['location'], '/portal/izin_wali_detail.php?id=' . $id1), 'Buka notifikasi → dialihkan ke rincian pengajuan wali');
$r = $k['sbx_v7_pembimbing_satu']->req('/portal/izin_wali_detail.php?id=' . $id1);
$assert($r['status'] === 200 && str_contains($r['body'], 'SBX V7 Santri Cadangan'), 'Tautan dibuka: rincian tampil sesudah pemeriksaan hak');
$assert($k['sbx_v7_wali_b']->req('/portal/notifikasi.php?buka=' . $notifId)['status'] !== 302
    || !str_contains((string) $k['sbx_v7_wali_b']->req('/portal/notifikasi.php?buka=' . $notifId)['location'], 'izin_wali_detail'),
    'ID notifikasi milik pembimbing tidak dapat dibuka akun lain');
$jalankan("UPDATE pembimbing_assignments SET is_active = 0 WHERE id = $paSatu");
$r = $k['sbx_v7_pembimbing_satu']->req('/portal/notifikasi.php?buka=' . $notifId);
$r2 = $k['sbx_v7_pembimbing_satu']->req('/portal/izin_wali_detail.php?id=' . $id1);
$r3 = $api('GET', '/v7/izin/pengajuan/' . $id1, $t['sbx_v7_pembimbing_satu']);
$assert($r2['status'] === 404 && !str_contains($r2['body'], 'SBX V7 Santri Cadangan') && $r3['status'] === 404,
    'Penugasan dicabut: tautan yang sama → 404 di web (sesi lama) dan API (token lama)');
$jalankan("UPDATE pembimbing_assignments SET is_active = 1 WHERE id = $paSatu");

// ===================================================================== H-B
echo PHP_EOL . '=== H-B. Penerusan, keputusan, dan relasi wali ===' . PHP_EOL;
$r = $api('POST', '/v7/izin/pengajuan/' . $id1 . '/tinjauan', $t['sbx_v7_pembimbing_satu'], ['tindakan' => 'teruskan', 'version' => 1, 'idempotency_key' => 'v7f3-http-tr-1']);
$assert($r['status'] === 200 && $r['json']['data']['status'] === 'Diajukan', 'API teruskan → Diajukan');
$api('POST', '/v7/izin/pengajuan/' . $id1 . '/tinjauan', $t['sbx_v7_pembimbing_satu'], ['tindakan' => 'teruskan', 'version' => 1, 'idempotency_key' => 'v7f3-http-tr-1']);
$assert(count($notifApi('sbx_v7_wali_a', $id1)) === 1 && $notifApi('sbx_v7_wali_b', $id1) === [] && count($notifApi('sbx_murobi_a', $id1)) === 1
    && $notifApi('sbx_murobi_b', $id1) === [], 'Diteruskan: wali pengaju dan murobi tujuan masing-masing satu (replay tidak menggandakan); wali kedua dan murobi lain nol');
$r = $api('GET', '/v7/izin/pengajuan/' . $id1, $t['sbx_murobi_a']);
$assert($r['status'] === 200 && $r['json']['data']['peran_akses'] === 'murobi', 'Tautan murobi membuka rincian sesudah diteruskan');
$r = $api('POST', '/izin/pengajuan/' . $id1 . '/keputusan', $t['sbx_murobi_a'], ['hasil' => 'Disetujui', 'alasan' => $PENANDA . ' keputusan', 'version' => 2, 'idempotency_key' => 'v7f3-http-pt-1']);
$diputus = array_values(array_filter($notifApi('sbx_v7_wali_a', $id1), static fn ($i) => $i['event_type'] === 'izin.wali_diputus'));
$assert($r['status'] === 201 && count($diputus) === 1 && $notifApi('sbx_v7_wali_b', $id1) === [] && !str_contains(json_encode($diputus), $PENANDA),
    'Keputusan murobi (API V2) → satu notifikasi untuk wali pengaju saja, tanpa alasan keputusan');
$assert($hitung('notifikasi_outbox', "event_type LIKE 'izin.keputusan%' AND pengajuan_id = $id1") === 0, 'Notifikasi V2 lama tidak dipancarkan untuk baris wali');
$rel = (int) $satu("SELECT sw.id FROM santri_wali sw JOIN users u ON u.wali_id = sw.wali_id WHERE u.username = 'sbx_v7_wali_a' AND sw.santri_id = $sCadangan AND sw.archived_at IS NULL");
$jalankan("UPDATE santri_wali SET archived_at = NOW() WHERE id = $rel");
$assert($api('GET', '/v7/izin/pengajuan/' . $id1, $t['sbx_v7_wali_a'])['status'] === 404 && $k['sbx_v7_wali_a']->req('/portal/izin_wali_detail.php?id=' . $id1)['status'] === 404,
    'Relasi wali dicabut: tautan keputusan → 404 pada token dan sesi lama');
$jalankan("UPDATE santri_wali SET archived_at = NULL WHERE id = $rel");
$id2 = $buat($sCadangan, '2027-10-04');
$api('POST', '/v7/izin/pengajuan/' . $id2 . '/tinjauan', $t['sbx_v7_pembimbing_satu'], ['tindakan' => 'tolak', 'catatan' => $PENANDA . ' tolak', 'version' => 1, 'idempotency_key' => 'v7f3-http-tl-1']);
$id3 = $buat($sCadangan, '2027-10-06');
$api('POST', '/v7/izin/pengajuan/' . $id3 . '/pembatalan', $t['sbx_v7_wali_a'], ['alasan' => 'Batal HTTP', 'version' => 1, 'idempotency_key' => 'v7f3-http-bt-1']);
$assert(count($notifApi('sbx_v7_wali_a', $id2)) === 1 && count($notifApi('sbx_v7_pembimbing_satu', $id3)) === 2,
    'Tolak → wali pengaju; batal → pembimbing (masuk + dibatalkan)');

// ===================================================================== H-C
echo PHP_EOL . '=== H-C. Laporan API ===' . PHP_EOL;
$csvBaris = static fn (string $csv): array => array_map(static fn ($b) => (int) str_getcsv($b, ',', '"', '')[0], array_slice(array_values(array_filter(explode("\n", trim(substr($csv, 3))))), 1));
$totalApi = [];
foreach (['sbx_admin', 'sbx_v7_pembimbing_satu', 'sbx_v7_pembimbing_dua', 'sbx_pengurus_a', 'sbx_murobi_a', 'sbx_v7_wali_a', 'sbx_v7_wali_b', 'sbx_ortu_b'] as $u) {
    $r = $api('GET', '/v7/izin/laporan', $t[$u]);
    $e = $api('GET', '/v7/izin/laporan/ekspor', $t[$u]);
    $totalApi[$u] = (int) ($r['json']['data']['total'] ?? -1);
    $assert($r['status'] === 200 && $totalApi[$u] === (int) $r['json']['data']['ringkasan']['total'] && $e['status'] === 200
        && str_contains($e['header'], 'text/csv') && count($csvBaris($e['body'])) === $totalApi[$u] && str_contains($r['header'], 'no-store')
        && !str_contains($r['body'] . $e['body'], $PENANDA), "API laporan $u: total {$totalApi[$u]} = baris CSV; no-store; tanpa alasan");
}
$assert($totalApi['sbx_admin'] === $hitung('izin_pengajuan', '1'), 'API laporan admin menghitung seluruh pengajuan tepat sekali');
$assert($api('GET', '/v7/izin/laporan', $t['sbx_guru_biasa'])['status'] === 403 && $api('GET', '/v7/izin/laporan/ekspor', $t['sbx_guru_biasa'])['status'] === 403,
    'Guru tanpa penugasan murobi → 403 laporan dan ekspor');
$assert($api('GET', '/v7/izin/laporan?tahap=ngawur', $t['sbx_admin'])['status'] === 422 && $api('GET', '/v7/izin/laporan', null)['status'] === 401,
    'Saringan tidak valid → 422; tanpa token → 401');
$wb = $api('GET', '/v7/izin/laporan?asal=wali', $t['sbx_v7_wali_b'])['json']['data']['items'] ?? [];
$assert($wb !== [] && array_filter($wb, static fn ($i) => $i['pengaju'] !== 'Wali lain' || isset($i['pembimbing'])) === [], 'Wali kedua: baris wali A ringkas "Wali lain"');
$r = $api('GET', '/v7/izin/laporan?peran=admin', $t['sbx_v7_wali_a']);
$assert($r['json']['data']['peran'] === 'orang_tua', 'Parameter peran tidak memperluas cakupan');

// ===================================================================== H-D
echo PHP_EOL . '=== H-D. Laporan web ===' . PHP_EOL;
foreach (['sbx_admin', 'sbx_v7_pembimbing_satu', 'sbx_murobi_a', 'sbx_v7_wali_a', 'sbx_v7_wali_b'] as $u) {
    $r = $k[$u]->req('/portal/izin_wali_laporan.php');
    preg_match('/data-total="(\d+)"/', $r['body'], $m);
    $e = $k[$u]->req('/portal/izin_wali_laporan_ekspor.php');
    $assert($r['status'] === 200 && (int) ($m[1] ?? -1) === $totalApi[$u] && count($csvBaris($e['body'])) === $totalApi[$u] && !str_contains($r['body'], $PENANDA)
        && str_contains($r['header'], 'no-store'), "Web laporan $u: data-total = API = ekspor web ({$totalApi[$u]})");
}
$r = $k['sbx_v7_wali_b']->req('/portal/izin_wali_laporan.php?asal=wali');
$assert(str_contains($teks($r['body']), 'Wali lain') && !str_contains($teks($r['body']), 'Lama total'), 'Web wali kedua: kolom pengaju "Wali lain", tanpa kolom petugas/durasi');
$r = $k['sbx_admin']->req('/portal/izin_wali_laporan.php?tahap=ngawur');
$assert($r['status'] === 200 && str_contains($teks($r['body']), 'Tahap tidak dikenal'), 'Web saringan tidak valid → peringatan, laporan tanpa saringan');
$r = $k['sbx_admin']->req('/portal/izin_wali_laporan.php?asal=wali&hasil=disetujui');
preg_match('/data-total="(\d+)"/', $r['body'], $m);
$assert((int) ($m[1] ?? -1) === $hitung('izin_pengajuan', "asal_pengajuan = 'Wali' AND status = 'Disetujui'"), 'Web saringan asal+hasil = SQL independen');
$assert($k['sbx_guru_biasa']->req('/portal/izin_wali_laporan.php')['status'] === 403, 'Web guru tanpa penugasan → 403');
$assert($hitung('audit_logs', "action = 'izin_v7_laporan_ekspor'") === 13, 'Setiap ekspor (8 API + 5 web) diaudit');

// ===================================================================== H-E
echo PHP_EOL . '=== H-E. Log dan verifier ===' . PHP_EOL;
usleep(300000);
$isiLog = (string) file_get_contents($log);
$assert(!preg_match('/PHP (Warning|Notice|Fatal|Deprecated)|Uncaught/', $isiLog), 'Log server tanpa galat/peringatan PHP');
$assert(!str_contains($isiLog, $PENANDA) && !str_contains($isiLog, 'ExponentPushToken') && !str_contains($isiLog, 'Bearer'), 'Log server tanpa alasan izin atau token');
foreach (['v7_phase2_verify.php', 'v7_phase3_verify.php'] as $berkas) {
    $out = [];
    exec('DB_NAME=' . escapeshellarg($db) . ' php ' . escapeshellarg($root . '/bin/' . $berkas) . ' 2>&1', $out, $kode);
    $assert($kode === 0, $berkas . ' lulus sesudah uji HTTP (' . trim((string) end($out)) . ')');
}
@unlink($log);

echo PHP_EOL . 'Lulus: ' . $lulus . ' · Gagal: ' . count($gagal) . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
