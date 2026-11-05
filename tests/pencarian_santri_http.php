<?php

declare(strict_types=1);

/**
 * Uji kontrak pencarian santri (nama/NIS) pada daftar berhalaman untuk
 * aplikasi — paket "pencarian santri, IBTIDA PA/PI, dan versi aplikasi"
 * (5 November 2026). Server PHP lokal + bearer token di atas DB sintetis
 * `tests/penugasan_keamanan_f4_db.sh` dengan skenario
 * `tests/penugasan_keamanan_f3_seed.php` (dimuat oleh uji ini).
 *
 *   PKF3_DB=test_pcs_http_test bash tests/penugasan_keamanan_f4_db.sh
 *   PKF3_DB=test_pcs_http_test php tests/pencarian_santri_http.php
 *
 *   C-1 `GET /keamanan/izin?q=` (baru): hasil = saringan lokal atas SELURUH
 *       halaman tanpa `q` (oracle), menjangkau halaman > 1, ringkasan ikut
 *       saringan, tidak memperlebar cakupan (santri nonaktif tetap tidak ada)
 *   C-2 validasi `q`: array → 422, > 100 karakter → 422, `%`/`_` harfiah,
 *       huruf besar/kecil tidak berpengaruh, kosong = tanpa saringan
 *   C-3 `GET /keamanan/pelanggaran|konseling?q=` kini juga NIS; Rahasia tetap
 *       tidak terlihat lewat pencarian
 *   C-4 `GET /v3/pelanggaran|konseling/kasus?q=` (daftar pembinaan aplikasi)
 *       untuk admin dan murobi: hasil = saringan lokal seluruh halaman akun
 *       yang sama (tidak melebar), NIS dan nama
 *   C-5 ekspor laporan izin V7 dengan `q`: audit hanya penanda `q: true`,
 *       tanpa kata kunci (nama santri)
 *   C-6 log server tanpa galat PHP
 *
 * Membaca dan menambah jejak audit; bangun ulang DB sebelum mengulang.
 */

$root = dirname(__DIR__);
$db = getenv('PKF3_DB') ?: 'test_pcs_http_test';
$port = (int) (getenv('PCS_PORT') ?: 8957);
if (!preg_match('/^test_.*_test$/', $db)) {
    fwrite(STDERR, "Ditolak: DB uji harus bernama test_*_test.\n");
    exit(2);
}
putenv('PKF3_DB=' . $db);
putenv('DB_NAME=' . $db);
require_once $root . '/app/bootstrap.php';

$lulus = 0;
$gagal = [];
$assert = static function (bool $ok, string $pesan) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    $ok ? $lulus++ : $gagal[] = $pesan;
};
$ids = require __DIR__ . '/penugasan_keamanan_f3_seed.php';
$conn = app_db();
register_shutdown_function(static function () use ($ids): void {
    @unlink($ids['lampiran_berkas']);
});
$log = tempnam(sys_get_temp_dir(), 'pcs-server-');
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

$base = 'http://127.0.0.1:' . $port;
$api = static function (string $method, string $path, ?string $token, ?array $body = null) use ($base): array {
    $h = curl_init($base . '/api/v1' . $path);
    $headers = ['Accept: application/json', 'Content-Type: application/json'];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $headers]);
    if ($method !== 'GET') {
        curl_setopt($h, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($h, CURLOPT_POSTFIELDS, (string) json_encode($body ?? []));
    }
    $out = (string) curl_exec($h);
    $size = (int) curl_getinfo($h, CURLINFO_HEADER_SIZE);
    $status = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
    curl_close($h);
    $json = json_decode(substr($out, $size), true);

    return ['status' => $status, 'body' => substr($out, $size), 'data' => $json['data'] ?? null];
};
$login = static fn (string $user): string => (string) ($api('POST', '/auth/login', null,
    ['username' => $user, 'password' => 'Sandbox#123', 'device_name' => 'uji-pencarian'])['data']['token'] ?? '');
$satu = static function (string $sql) use ($conn): mixed {
    $r = $conn->query($sql);
    if ($r === false) {
        throw new RuntimeException($conn->error . ' :: ' . $sql);
    }

    return $r->fetch_row()[0] ?? null;
};
$nisSantri = [];
$r = $conn->query('SELECT id, nis, nama_santri FROM santri');
while ($row = $r->fetch_assoc()) {
    $nisSantri[(int) $row['id']] = ['nis' => (string) $row['nis'], 'nama' => (string) $row['nama_santri']];
}
$cocok = static function (int $santriId, string $q) use ($nisSantri): bool {
    $s = $nisSantri[$santriId] ?? ['nis' => '', 'nama' => ''];
    $q = mb_strtolower($q);

    return str_contains(mb_strtolower($s['nama']), $q) || str_contains(mb_strtolower($s['nis']), $q);
};
/** Seluruh halaman satu daftar: [ids, santri_id per id, total per respons pertama, jumlah halaman]. */
$semuaHalaman = static function (string $path, string $token, string $kunciItem, callable $santriDari) use ($api): array {
    $idSantri = [];
    $halaman = 0;
    $total = null;
    for ($page = 1; $page <= 500; $page++) {
        $sep = str_contains($path, '?') ? '&' : '?';
        $r = $api('GET', $path . $sep . 'page=' . $page, $token);
        if ($r['status'] !== 200) {
            throw new RuntimeException("$path halaman $page → {$r['status']}");
        }
        $baris = $r['data'][$kunciItem] ?? [];
        $total ??= (int) ($r['data']['total'] ?? 0);
        $halaman++;
        foreach ($baris as $b) {
            $idSantri[(int) $b['id']] = (int) $santriDari($b);
        }
        $perPage = (int) ($r['data']['per_page'] ?? 25);
        if (count($baris) < $perPage) {
            break;
        }
    }

    return ['peta' => $idSantri, 'total' => (int) $total, 'halaman' => $halaman];
};

$t = ['K' => $login('sbx_pengurus_biasa'), 'A' => $login('sbx_admin'), 'M' => $login('sbx_murobi_a')];
$assert(!in_array('', $t, true), 'Akun Keamanan, admin, dan murobi memperoleh token API');

echo "\n=== C-1 /keamanan/izin?q= ===\n";
$izinSantri = static fn (array $b): int => (int) $b['santri']['id'];
$penuh = $semuaHalaman('/keamanan/izin', $t['K'], 'items', $izinSantri);
$assert($penuh['halaman'] > 2 && count($penuh['peta']) === $penuh['total'], "Daftar tanpa q berhalaman ({$penuh['halaman']} halaman, {$penuh['total']} baris)");
// Pilih santri yang izinnya BUKAN di halaman pertama tanpa saringan.
$halaman1 = array_slice(array_keys($penuh['peta']), 0, 25);
$santriHal1 = array_unique(array_map(static fn (int $id): int => $penuh['peta'][$id], $halaman1));
$calon = null;
foreach (array_reverse($penuh['peta'], true) as $id => $sid) {
    if (!in_array($sid, $santriHal1, true) && ($nisSantri[$sid]['nis'] ?? '') !== '') {
        $calon = $sid;
        break;
    }
}
$assert($calon !== null, 'Ada santri yang izinnya hanya di halaman > 1 (kasus lintas halaman)');
$nis = $nisSantri[$calon]['nis'];
$nama = $nisSantri[$calon]['nama'];
foreach (['NIS' => $nis, 'nama (huruf besar)' => mb_strtoupper($nama), 'potongan nama' => mb_substr($nama, 1, max(3, (int) (mb_strlen($nama) / 2)))] as $jenis => $q) {
    $hasil = $semuaHalaman('/keamanan/izin?q=' . rawurlencode($q), $t['K'], 'items', $izinSantri);
    $harap = array_keys(array_filter($penuh['peta'], static fn (int $sid): bool => $cocok($sid, $q)));
    sort($harap);
    $dapat = array_keys($hasil['peta']);
    sort($dapat);
    $assert($dapat === $harap && $hasil['total'] === count($harap) && in_array($calon, $hasil['peta'], true),
        "q $jenis: hasil = oracle saringan seluruh halaman (" . count($harap) . ' baris), memuat santri dari halaman > 1');
}
$r = $api('GET', '/keamanan/izin?q=' . rawurlencode($nis), $t['K']);
$assert((int) $r['data']['ringkasan']['total'] === (int) $r['data']['total'] && ($r['data']['filter']['q'] ?? null) === $nis,
    'Ringkasan memakai saringan q yang sama; filter q dikembalikan');
$nonaktif = (int) $satu('SELECT santri_id FROM izin_pengajuan WHERE id = ' . (int) $ids['izin_nonaktif']);
$r = $api('GET', '/keamanan/izin?q=' . rawurlencode($nisSantri[$nonaktif]['nis']), $t['K']);
$assert($r['status'] === 200 && !in_array((int) $ids['izin_nonaktif'], array_map('intval', array_column($r['data']['items'], 'id')), true)
    && !in_array($nonaktif, array_map($izinSantri, $r['data']['items']), true), 'NIS santri nonaktif tidak membuka izinnya (cakupan tidak melebar)');
$nisWali = $nisSantri[(int) $satu('SELECT santri_id FROM izin_pengajuan WHERE id = ' . (int) $ids['izin_wali'])]['nis'];
$r = $api('GET', '/keamanan/izin?q=' . rawurlencode($nisWali) . '&asal=wali&peran=admin', $t['K']);
$e = $conn->real_escape_string($nisWali);
$harapWali = (int) $satu("SELECT COUNT(*) FROM izin_pengajuan p JOIN santri s ON s.id = p.santri_id AND s.is_active = 1 AND s.archived_at IS NULL
    WHERE p.asal_pengajuan = 'Wali' AND (s.nama_santri LIKE '%$e%' OR s.nis LIKE '%$e%')");
$assert($r['status'] === 200 && $harapWali > 0 && (int) $r['data']['total'] === $harapWali && $r['data']['peran'] === 'keamanan'
    && in_array((int) $ids['izin_wali'], array_map('intval', array_column($r['data']['items'], 'id')), true),
    "q NIS + asal Wali digabung (oracle SQL $harapWali, memuat pengajuan wali); peran tetap Keamanan");

echo "\n=== C-2 validasi q ===\n";
$assert($api('GET', '/keamanan/izin?q[]=a', $t['K'])['status'] === 422, 'q array → 422');
$assert($api('GET', '/keamanan/izin?q=' . str_repeat('a', 101), $t['K'])['status'] === 422, 'q 101 karakter → 422');
$assert((int) $api('GET', '/keamanan/izin?q=' . rawurlencode('%'), $t['K'])['data']['total']
    === (int) $satu("SELECT COUNT(*) FROM izin_pengajuan p JOIN santri s ON s.id = p.santri_id AND s.is_active = 1 AND s.archived_at IS NULL WHERE s.nama_santri LIKE '%\\\\%%' OR s.nis LIKE '%\\\\%%'"),
    '`%` dicari harfiah, bukan wildcard');
$assert((int) $api('GET', '/keamanan/izin?q=' . rawurlencode('_'), $t['K'])['data']['total']
    === (int) $satu("SELECT COUNT(*) FROM izin_pengajuan p JOIN santri s ON s.id = p.santri_id AND s.is_active = 1 AND s.archived_at IS NULL WHERE s.nama_santri LIKE '%\\\\_%' OR s.nis LIKE '%\\\\_%'"),
    '`_` dicari harfiah, bukan wildcard');
$r = $api('GET', '/keamanan/izin?q=' . rawurlencode('   '), $t['K']);
$assert((int) $r['data']['total'] === $penuh['total'] && array_key_exists('q', $r['data']['filter']) && $r['data']['filter']['q'] === null,
    'q spasi saja = tanpa saringan (filter q null)');
$assert($api('GET', '/keamanan/izin?q=' . rawurlencode($nis), $t['A'])['status'] === 403, 'Admin tanpa penugasan Keamanan tetap 403 dengan q');

echo "\n=== C-3 /keamanan/pelanggaran|konseling?q= NIS ===\n";
foreach (['pelanggaran', 'konseling'] as $daftar) {
    $semua = $semuaHalaman('/keamanan/' . $daftar, $t['K'], 'items', static fn (array $b): int => (int) $b['santri_id']);
    $sids = array_values(array_unique($semua['peta']));
    $ok = $sids !== [];
    foreach ($sids as $sid) {
        $q = $nisSantri[$sid]['nis'];
        $hasil = $semuaHalaman('/keamanan/' . $daftar . '?q=' . rawurlencode($q), $t['K'], 'items', static fn (array $b): int => (int) $b['santri_id']);
        $harap = array_keys(array_filter($semua['peta'], static fn (int $x): bool => $cocok($x, $q)));
        sort($harap);
        $dapat = array_keys($hasil['peta']);
        sort($dapat);
        $ok = $ok && $dapat === $harap && $harap !== [];
    }
    $assert($ok, "Keamanan $daftar: q NIS setiap santri terlihat = oracle saringan daftar sendiri (" . count($sids) . ' santri)');
}
$rahasia = (int) $satu('SELECT santri_id FROM v3_konseling_kasus WHERE id = ' . (int) $ids['kasus_rahasia']);
$r = $api('GET', '/keamanan/konseling?q=' . rawurlencode($nisSantri[$rahasia]['nis']), $t['K']);
$assert($r['status'] === 200 && !in_array((int) $ids['kasus_rahasia'], array_map('intval', array_column($r['data']['items'], 'id')), true)
    && array_sum($r['data']['jumlah_status']) === count($r['data']['items']), 'Kasus Rahasia tidak muncul lewat pencarian NIS; angka = baris terlihat');

echo "\n=== C-4 /v3 daftar pembinaan aplikasi ===\n";
foreach (['A' => 'admin', 'M' => 'murobi'] as $siapa => $label) {
    foreach (['pelanggaran' => '/v3/pelanggaran', 'kasus' => '/v3/konseling/kasus'] as $jenis => $path) {
        $semua = $semuaHalaman($path, $t[$siapa], 'rows', static fn (array $b): int => (int) $b['santri_id']);
        $sids = array_values(array_unique($semua['peta']));
        $ok = true;
        foreach ($sids as $sid) {
            foreach ([$nisSantri[$sid]['nis'], mb_strtolower(mb_substr($nisSantri[$sid]['nama'], 0, 4))] as $q) {
                $hasil = $semuaHalaman($path . '?q=' . rawurlencode($q), $t[$siapa], 'rows', static fn (array $b): int => (int) $b['santri_id']);
                $harap = array_keys(array_filter($semua['peta'], static fn (int $x): bool => $cocok($x, $q)));
                sort($harap);
                $dapat = array_keys($hasil['peta']);
                sort($dapat);
                $ok = $ok && $dapat === $harap && $hasil['total'] === count($harap);
            }
        }
        $assert($ok, "$label $jenis: q NIS/nama = oracle saringan seluruh daftar akun sendiri (" . count($semua['peta']) . ' baris, ' . count($sids) . ' santri)');
    }
}
// Santri di luar cakupan murobi tidak terbuka lewat q.
$semuaAdmin = $semuaHalaman('/v3/pelanggaran', $t['A'], 'rows', static fn (array $b): int => (int) $b['santri_id']);
$semuaMurobi = $semuaHalaman('/v3/pelanggaran', $t['M'], 'rows', static fn (array $b): int => (int) $b['santri_id']);
$luar = array_values(array_diff(array_unique($semuaAdmin['peta']), array_unique($semuaMurobi['peta'])));
if ($luar !== []) {
    $r = $api('GET', '/v3/pelanggaran?q=' . rawurlencode($nisSantri[$luar[0]]['nis']), $t['M']);
    $terlihat = array_map(static fn (array $b): int => (int) $b['santri_id'], $r['data']['rows'] ?? []);
    $assert($r['status'] === 200 && !in_array($luar[0], $terlihat, true), 'Murobi: NIS santri di luar cakupan tidak membuka pelanggarannya');
} else {
    echo "[info] fixture: seluruh pelanggaran admin juga terlihat murobi; kontrol di luar cakupan dilewati\n";
}

echo "\n=== C-5 audit ekspor tanpa kata kunci ===\n";
$sebelum = (int) $satu("SELECT COALESCE(MAX(id),0) FROM audit_logs");
$h = curl_init($base . '/api/v1/v7/izin/laporan/ekspor?q=' . rawurlencode($nama));
curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $t['A']]]);
$csv = (string) curl_exec($h);
$kode = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
curl_close($h);
$audit = $conn->query("SELECT after_json FROM audit_logs WHERE id > $sebelum AND action = 'izin_v7_laporan_ekspor' ORDER BY id DESC LIMIT 1")->fetch_assoc();
$json = json_decode((string) ($audit['after_json'] ?? ''), true);
$assert($kode === 200 && str_contains($csv, $nis), 'Ekspor admin dengan q berhasil dan memuat baris santri yang dicari');
$assert(is_array($json) && ($json['filter']['q'] ?? null) === true && !str_contains((string) $audit['after_json'], $nama)
    && !str_contains((string) $audit['after_json'], json_encode($nama, JSON_UNESCAPED_UNICODE)), 'Audit ekspor: q hanya penanda true, tanpa nama santri');

echo "\n=== C-6 log server ===\n";
$isi = (string) file_get_contents($log);
$assert(!preg_match('/PHP (Warning|Notice|Fatal|Deprecated)|Uncaught/', $isi), 'Log server tanpa galat PHP');
@unlink($log);

echo PHP_EOL . 'Ringkasan: ' . $lulus . ' lulus, ' . count($gagal) . ' gagal' . PHP_EOL;
foreach ($gagal as $pesan) {
    echo ' - ' . $pesan . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
