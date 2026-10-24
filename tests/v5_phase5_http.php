<?php

declare(strict_types=1);

/**
 * Uji HTTP PRD V5 Fase 5 — laporan layanan klinik — pada server PHP bawaan
 * dan DB uji terpisah.
 *
 *   V5F5_DB=test_v5_f5_http_test bash tests/v5_phase5_db.sh
 *   V5F5_DB=test_v5_f5_http_test php tests/v5_phase5_http.php
 *
 *   Q-A  API `GET /v5/kesehatan/laporan`: tanpa token 401; petugas/admin/
 *        pembimbing/murobi 200 no-store dengan angka = layanan; wali,
 *        pengurus/guru tanpa penugasan 403; periode tidak valid 422 berpesan;
 *        token lama pembimbing ditolak sesudah penugasan dicabut;
 *   Q-B  web `portal/kesehatan_laporan.php`: angka kartu = API per peran,
 *        bagian pengawasan hanya admin, angka Draf tidak tampil bagi binaan,
 *        wali/pengurus biasa ditolak, sesi lama ditolak sesudah pencabutan,
 *        periode tidak valid → pesan + periode bawaan, no-store;
 *   Q-C  tautan angka membuka daftar kunjungan dengan saringan sama dan
 *        total daftar = angka laporan; daftar memuat tombol "Laporan";
 *   Q-D  0 penanda isi klinis pada HTML/JSON laporan; log server tanpa
 *        galat PHP.
 *
 * Mengubah data uji; bangun ulang DB sebelum mengulang.
 */

$root = dirname(__DIR__);
$db = getenv('V5F5_DB') ?: 'test_v5_f5_http_test';
$port = (int) (getenv('V5F5_PORT') ?: 8985);
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

$log = tempnam(sys_get_temp_dir(), 'v5f5-server-');
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
        $this->jar = tempnam(sys_get_temp_dir(), 'v5f5-jar-');
    }

    /** @return array{status:int, body:string, header:string, json:mixed} */
    public function req(string $path, ?array $post = null, array $headers = []): array
    {
        $h = curl_init($this->base . $path);
        curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $headers]);
        if ($post !== null) {
            curl_setopt($h, CURLOPT_POST, true);
            curl_setopt($h, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $out = (string) curl_exec($h);
        $status = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
        $size = (int) curl_getinfo($h, CURLINFO_HEADER_SIZE);
        curl_close($h);

        return ['status' => $status, 'body' => substr($out, $size), 'header' => substr($out, 0, $size), 'json' => json_decode(substr($out, $size), true)];
    }

    public function login(string $user): void
    {
        $r = $this->req('/portal/index.php');
        preg_match('/name="_csrf" value="([^"]+)"/', $r['body'], $m);
        $this->req('/admin/cek_login.php', ['_csrf' => $m[1] ?? '', 'username' => $user, 'password' => 'Sandbox#123']);
    }
}

$base = 'http://127.0.0.1:' . $port;
$web = [];
$masuk = static function (string $user) use ($base, &$web): Klien {
    if (!isset($web[$user])) {
        $web[$user] = new Klien($base);
        $web[$user]->login($user);
    }

    return $web[$user];
};
$tokens = [];
$api = static function (string $user, string $path) use ($base, &$tokens): array {
    $k = new Klien($base);
    if (!isset($tokens[$user])) {
        $h = curl_init($base . '/api/v1/auth/login');
        curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['username' => $user, 'password' => 'Sandbox#123', 'device_name' => 'uji-v5-f5'])]);
        $tokens[$user] = (string) (json_decode((string) curl_exec($h), true)['data']['token'] ?? '');
        curl_close($h);
    }

    return $k->req('/api/v1' . $path, null, ['Accept: application/json', 'Authorization: Bearer ' . $tokens[$user]]);
};
$conn = app_db();
$satu = static function (string $sql) use ($conn): mixed {
    $r = $conn->query($sql);
    if ($r === false) {
        throw new RuntimeException($conn->error . ' :: ' . $sql);
    }

    return $r->fetch_row()[0] ?? null;
};
/** Pasangan label → nilai kartu angka pada HTML. */
$kartu = static function (string $html): array {
    preg_match_all('#<p class="ah-stat__label">(.*?)</p><p class="ah-stat__value">(.*?)</p>#s', $html, $m, PREG_SET_ORDER);
    $hasil = [];
    foreach ($m as $x) {
        $hasil[html_entity_decode($x[1])] = (int) str_replace('.', '', $x[2]);
    }

    return $hasil;
};
$P = 'V5F5RHS';
$q = '?dari=2026-09-01&sampai=2026-09-30';
$halaman = '/portal/kesehatan_laporan.php';

// ===================================================================== Q-A
echo PHP_EOL . '=== Q-A. API laporan ===' . PHP_EOL;
$r = (new Klien($base))->req('/api/v1/v5/kesehatan/laporan' . $q, null, ['Accept: application/json']);
$assert($r['status'] === 401, 'Tanpa token → 401');
$apiData = [];
$harap = ['sbx_v5_petugas_f' => 6, 'sbx_admin' => 6, 'sbx_v5_pembimbing' => 3, 'sbx_v5_pembimbing_b' => 1, 'sbx_v5_murobi' => 3, 'sbx_v5_ganda' => 4, 'sbx_v5_murobi_kosong' => 0];
foreach ($harap as $u => $total) {
    $r = $api($u, '/v5/kesehatan/laporan' . $q);
    $apiData[$u] = $r['json']['data'] ?? [];
    $layanan = kesehatan_laporan_service()->laporan(['id' => (int) $satu("SELECT id FROM users WHERE username='$u'"), 'roles' => []], ['dari' => '2026-09-01', 'sampai' => '2026-09-30']);
    unset($layanan['akses']);
    $dariApi = $apiData[$u];
    unset($dariApi['akses']);
    $assert($r['status'] === 200 && stripos($r['header'], 'Cache-Control: private, no-store') !== false && ($apiData[$u]['kunjungan']['total'] ?? -1) === $total
        && $dariApi == $layanan, "$u: 200 no-store, $total kunjungan, isi = layanan");
    $assert(substr_count($r['body'], $P) === 0, "$u: JSON laporan tanpa penanda isi klinis");
}
$assert($apiData['sbx_admin']['pengawasan'] !== null && $apiData['sbx_v5_petugas_f']['pengawasan'] === null && $apiData['sbx_v5_pembimbing']['kunjungan']['draf'] === null,
    'Pengawasan hanya admin; draf binaan null');
foreach (['sbx_v5_wali_1', 'sbx_v5_wali_lain', 'sbx_pengurus_biasa', 'sbx_guru_biasa'] as $u) {
    $r = $api($u, '/v5/kesehatan/laporan' . $q);
    $assert($r['status'] === 403 && substr_count($r['body'], 'SBX V5 Lapor') === 0, "$u → 403 tanpa nama santri");
}
$r = $api('sbx_v5_petugas_f', '/v5/kesehatan/laporan?dari=2026-09-30&sampai=2026-09-01');
$assert($r['status'] === 422 && is_string($r['json']['error']['message'] ?? null), 'Periode terbalik → 422 berpesan');
$r = $api('sbx_v5_petugas_f', '/v5/kesehatan/laporan?dari=2024-01-01&sampai=2026-09-30');
$assert($r['status'] === 422, 'Periode > 366 hari → 422');

// ===================================================================== Q-B
echo PHP_EOL . '=== Q-B. Halaman web ===' . PHP_EOL;
$html = [];
foreach (array_keys($harap) as $u) {
    $r = $masuk($u)->req($halaman . $q);
    $html[$u] = $r['body'];
    $k = $kartu($r['body']);
    $d = $apiData[$u];
    $assert($r['status'] === 200 && stripos($r['header'], 'no-store') !== false
        && ($k['Kunjungan'] ?? -1) === $d['kunjungan']['total'] && ($k['Tercatat'] ?? -1) === $d['kunjungan']['tercatat']
        && ($k['Rujukan'] ?? -1) === $d['rujukan']['total'] && ($k['Menunggu hasil'] ?? -1) === $d['rujukan']['menunggu']
        && ($k['Tindak lanjut terbuka'] ?? -1) === $d['tindak_lanjut']['terbuka'] && ($k['Lewat target'] ?? -1) === $d['tindak_lanjut']['terlambat']
        && ($k['Berlaku (terbit)'] ?? -1) === $d['ringkasan_wali']['terbit'],
        "$u: kartu web = API (kunjungan {$d['kunjungan']['total']}, rujukan {$d['rujukan']['total']}, TL terbuka {$d['tindak_lanjut']['terbuka']})");
    $assert(substr_count($r['body'], $P) === 0, "$u: HTML laporan tanpa penanda isi klinis");
}
$assert(str_contains($html['sbx_admin'], 'Pengawasan admin') && !str_contains($html['sbx_v5_petugas_f'], 'Pengawasan admin') && !str_contains($html['sbx_v5_pembimbing'], 'Pengawasan admin'),
    'Bagian "Pengawasan admin" hanya untuk admin');
$assert(isset($kartu($html['sbx_v5_petugas_f'])['Draf']) && !isset($kartu($html['sbx_v5_pembimbing'])['Draf']) && !isset($kartu($html['sbx_v5_pembimbing_b'])['Draf']),
    'Kartu Draf tampil bagi petugas, tidak bagi pembimbing');
$assert(!str_contains($html['sbx_v5_pembimbing'], 'SBX V5 Lapor B') && !str_contains($html['sbx_v5_pembimbing'], 'SBX V5 Lapor Luar')
    && !str_contains($html['sbx_v5_pembimbing_b'], 'SBX V5 Lapor A') && str_contains($html['sbx_v5_petugas_f'], 'SBX V5 Lapor Luar'),
    'Nama santri di luar cakupan tidak muncul pada laporan pembimbing');
foreach (['sbx_v5_wali_1', 'sbx_pengurus_biasa', 'sbx_guru_biasa'] as $u) {
    $r = $masuk($u)->req($halaman . $q);
    $assert($r['status'] === 403 && !str_contains($r['body'], 'SBX V5 Lapor'), "$u: halaman laporan 403 tanpa data");
}
$r = (new Klien($base))->req($halaman);
$assert(in_array($r['status'], [302, 303], true), 'Tanpa sesi → dialihkan ke masuk');
$r = $masuk('sbx_v5_petugas_f')->req($halaman . '?dari=2026-09-30&sampai=2026-09-01');
$assert($r['status'] === 200 && str_contains($r['body'], 'tidak boleh sesudah tanggal akhir') && str_contains($r['body'], 'periode bawaan'), 'Periode terbalik: pesan + periode bawaan');
$r = $masuk('sbx_v5_petugas_f')->req($halaman . $q . '&kelas_id=' . (int) $satu("SELECT id FROM kelas WHERE nama_kelas='SBX V5 Kelas Y'"));
$assert(($kartu($r['body'])['Kunjungan'] ?? -1) === 2 && str_contains($r['body'], ' selected>SBX V5 Kelas Y<'), 'Saringan kelas Y: 2 kunjungan, pilihan tetap terpilih');

// Sesi/token lama sesudah penugasan pembimbing dicabut.
$pengurus = (int) $satu("SELECT pengurus_id FROM users WHERE username='sbx_v5_pembimbing'");
$conn->query("UPDATE pembimbing_assignments SET is_active = 0 WHERE pengurus_id = $pengurus");
$rWeb = $masuk('sbx_v5_pembimbing')->req($halaman . $q);
$rApi = $api('sbx_v5_pembimbing', '/v5/kesehatan/laporan' . $q);
$assert($rWeb['status'] === 403 && $rApi['status'] === 403 && !str_contains($rWeb['body'], 'SBX V5 Lapor A'), 'Pencabutan penugasan: sesi web dan token API lama → 403');
$conn->query("UPDATE pembimbing_assignments SET is_active = 1 WHERE pengurus_id = $pengurus");
$assert($masuk('sbx_v5_pembimbing')->req($halaman . $q)['status'] === 200, 'Dipulihkan → 200 lagi');

// ===================================================================== Q-C
echo PHP_EOL . '=== Q-C. Tautan angka ke daftar ===' . PHP_EOL;
foreach (['sbx_v5_petugas_f', 'sbx_v5_pembimbing'] as $u) {
    preg_match('#<a class="ah-stat ah-stat--link" href="([^"]+)"><p class="ah-stat__label">Kunjungan</p>#', $html[$u], $m);
    $url = html_entity_decode($m[1] ?? '');
    $assert(str_contains($url, 'kesehatan.php') && str_contains($url, 'dari=2026-09-01') && str_contains($url, 'sampai=2026-09-30'), "$u: kartu Kunjungan menaut ke daftar dengan periode sama");
    $r = $masuk($u)->req(parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY));
    $assert($r['status'] === 200 && preg_match('#<span class="text-muted small">(\d+) kunjungan</span>#', $r['body'], $mm) === 1 && (int) $mm[1] === $apiData[$u]['kunjungan']['total'],
        "$u: total daftar dari tautan = angka laporan ({$apiData[$u]['kunjungan']['total']})");
    preg_match('#href="([^"]+)"><p class="ah-stat__label">Menunggu hasil</p>#', $html[$u], $m);
    $r = $masuk($u)->req(html_entity_decode($m[1] ?? '/'));
    preg_match('#<span class="text-muted small">(\d+) kunjungan</span>#', $r['body'], $mm);
    $kunjunganMenunggu = (int) $satu("SELECT COUNT(DISTINCT r.kunjungan_id) FROM kesehatan_rujukan r JOIN kesehatan_kunjungan k ON k.id = r.kunjungan_id
        WHERE r.versi_dibatalkan IS NULL AND r.status = 'Menunggu hasil' AND k.waktu_kunjungan >= '2026-09-01' AND k.waktu_kunjungan < '2026-10-01'"
        . ($u === 'sbx_v5_pembimbing' ? " AND k.status = 'Tercatat' AND k.santri_id = (SELECT id FROM santri WHERE nis = 'V5S0501')" : ''));
    $assert((int) ($mm[1] ?? -1) === $kunjunganMenunggu, "$u: tautan Menunggu hasil → daftar $kunjunganMenunggu kunjungan (sumber)");
}
$r = $masuk('sbx_v5_pembimbing')->req('/portal/kesehatan.php');
$assert(str_contains($r['body'], 'kesehatan_laporan.php'), 'Daftar kunjungan memuat tombol Laporan (juga bagi pembaca binaan)');
$detailTautan = preg_match_all('#kesehatan_detail\.php\?id=(\d+)#', $html['sbx_v5_pembimbing'], $m) > 0 ? array_unique($m[1]) : [];
$milikLain = (int) $satu('SELECT COUNT(*) FROM kesehatan_kunjungan k JOIN santri s ON s.id = k.santri_id WHERE k.id IN (' . (implode(',', array_map('intval', $detailTautan)) ?: '0') . ") AND s.nis <> 'V5S0501'");
$assert($detailTautan !== [] && $milikLain === 0, 'Tautan detail pada laporan pembimbing hanya kunjungan santri cakupan (' . count($detailTautan) . ')');

// ===================================================================== Q-D
echo PHP_EOL . '=== Q-D. Log server ===' . PHP_EOL;
$isiLog = (string) file_get_contents($log);
$assert(preg_match('/PHP (Fatal|Warning|Notice|Deprecated|Parse)/', $isiLog) !== 1, 'Log server tanpa galat/peringatan PHP');
$assert(substr_count($isiLog, $P) === 0, 'Log server tanpa penanda isi klinis');

echo PHP_EOL . 'Ringkasan: ' . $lulus . ' lulus, ' . count($gagal) . ' gagal' . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
