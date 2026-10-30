<?php

declare(strict_types=1);

/**
 * Uji server perbaikan "Terbitkan tagihan" satu santri memakai tarif —
 * halaman `portal/bsa_tagihan_buat.php` dan API penerbitan dengan `santri_id`.
 *
 *   V6F5_DB=test_v6_satu_santri_http_test bash tests/v6_phase5_db.sh
 *   DB_NAME=test_v6_satu_santri_http_test php tests/v6_tagihan_satu_santri_http.php
 *
 *   W-1  santri bertarif: rincian tarif + penyesuaian dan total tampil,
 *        isian manual tidak ditawarkan; terbitkan → rincian tagihan; klik ganda
 *        tetap satu tagihan; santri lain tidak ikut;
 *   W-2  kiriman manual untuk santri bertarif ditolak server tanpa tagihan;
 *   W-3  santri tanpa kelas / unit tanpa tarif: penjelasan + isian manual,
 *        kiriman manual berhasil;
 *   W-4  pratinjau basi → pesan, tanpa tagihan;
 *   W-5  hak halaman (admin/wali 403, petugas unit lain 404);
 *   W-6  API pratinjau/penerbitan dengan santri_id;
 *   W-7  log server tanpa galat PHP; verifier Fase 1–3.
 *
 * Mengubah data uji; bangun ulang DB sebelum mengulang.
 */

$root = dirname(__DIR__);
$db = getenv('DB_NAME') ?: 'test_v6_satu_santri_http_test';
$port = (int) (getenv('V6SS_PORT') ?: 8968);
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
$log = tempnam(sys_get_temp_dir(), 'v6ss-server-');
$server = proc_open(['php', '-S', '127.0.0.1:' . $port, $root . '/tests/redesain_web_tahap1_router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root, array_merge(getenv(), ['DB_NAME' => $db]));
for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
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
        $this->jar = tempnam(sys_get_temp_dir(), 'v6ss-jar-');
    }

    /** @return array{status:int, body:string, location:?string, header:string} */
    public function req(string $path, ?array $post = null, array $headers = [], ?string $raw = null): array
    {
        $h = curl_init($this->base . $path);
        curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $headers]);
        if ($post !== null || $raw !== null) {
            curl_setopt($h, CURLOPT_POST, true);
            curl_setopt($h, CURLOPT_POSTFIELDS, $raw ?? http_build_query($post));
        }
        $out = (string) curl_exec($h);
        $status = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
        $size = (int) curl_getinfo($h, CURLINFO_HEADER_SIZE);
        curl_close($h);
        preg_match('/^Location:\s*(.+)$/mi', substr($out, 0, $size), $m);

        return ['status' => $status, 'body' => substr($out, $size), 'location' => isset($m[1]) ? trim($m[1]) : null, 'header' => substr($out, 0, $size)];
    }

    public function login(string $user): self
    {
        preg_match('/name="_csrf" value="([^"]+)"/', $this->req('/portal/index.php')['body'], $m);
        $this->req('/admin/cek_login.php', ['_csrf' => $m[1] ?? '', 'username' => $user, 'password' => 'Sandbox#123']);

        return $this;
    }
}

$base = 'http://127.0.0.1:' . $port;
$conn = app_db();
$satu = static fn (string $sql): mixed => $conn->query($sql)->fetch_row()[0] ?? null;
$teks = static fn (string $html): string => trim(html_entity_decode((string) preg_replace('/\s+/', ' ', (string) preg_replace('/<[^>]+>/', ' ', (string) preg_replace('#<(script|style|template)\b.*?</\1>#s', '', $html))), ENT_QUOTES));
$sid = static fn (string $nis): int => (int) $satu("SELECT id FROM santri WHERE nis = '$nis'");
$uid = static fn (string $u): int => (int) $satu("SELECT id FROM users WHERE username = '$u'");
$k = static fn (string $a): string => $a . '-' . bin2hex(random_bytes(6));
$s1 = $sid('V6S0001');
$s2 = $sid('V6S0002');
$s3 = $sid('V6S0003');
$periode = date('Y-m');
$smpLain = (int) $satu("SELECT s.id FROM santri s JOIN plotting_kelas pk ON pk.id_santri = s.id AND pk.status = 'Aktif'
    AND pk.id_tahun = (SELECT id FROM tahun_ajaran WHERE status = 'Aktif' AND archived_at IS NULL ORDER BY id DESC LIMIT 1) JOIN kelas kl ON kl.id = pk.id_kelas
    WHERE s.is_active = 1 AND s.archived_at IS NULL AND kl.jenjang = 'SMP' AND s.id <> $s1 ORDER BY s.id LIMIT 1");
$petugasArr = ['id' => $uid('sbx_v6_bsa_b'), 'roles' => []];
$tag = tagihan_service();
$tag->buatTarif($petugasArr, ['idempotency_key' => $k('tf'), 'unit' => 'SMP', 'berlaku_mulai' => $periode,
    'komponen' => [['nama' => 'Syahriah HTTP', 'nominal' => '150000'], ['nama' => 'Makan HTTP', 'nominal' => '200000']]], 'web');
$tag->buatPenyesuaian($petugasArr, $s1, ['idempotency_key' => $k('pj'), 'jenis' => 'Potongan', 'nama' => 'Potongan HTTP', 'nominal' => '50000',
    'berlaku_mulai' => $periode, 'alasan' => 'Uji potongan HTTP'], 'web');
$assert($smpLain > 0 && (int) $satu('SELECT COUNT(*) FROM bsa_tagihan') === 0, 'Fixture: tarif SMP Rp350.000, potongan Rp50.000 santri SMP, belum ada tagihan');
$p = (new Klien($base))->login('sbx_v6_bsa_b');
$halaman = static fn (Klien $kl, int $s, array $q = []): array => $kl->req('/portal/bsa_tagihan_buat.php?' . http_build_query(['santri_id' => $s] + $q));
$formTarif = static function (string $body): array {
    preg_match('/id="form-tarif".*?<\/form>/s', $body, $f);
    preg_match_all('/name="([a-z_]+)" value="([^"]*)"/', $f[0] ?? '', $m, PREG_SET_ORDER);

    return array_column($m, 2, 1);
};

echo PHP_EOL . '=== W-1. Santri bertarif ===' . PHP_EOL;
$r = $halaman($p, $s1);
$t = $teks($r['body']);
$assert($r['status'] === 200 && str_contains($t, 'Syahriah HTTP Tarif unit SMP Rp150.000') && str_contains($t, 'Makan HTTP Tarif unit SMP Rp200.000')
    && str_contains($t, 'Potongan HTTP Potongan santri −Rp50.000') && preg_match('/Total tagihan Tarif ± penyesuaian Rp300\.000/u', $t) === 1 && str_contains($t, 'Terbitkan tagihan Rp300.000'),
    'Halaman: komponen tarif, potongan santri, total Rp300.000 dari tarif');
$assert(!str_contains($r['body'], 'id="form-tagihan"') && !str_contains($r['body'], 'komponen[0][nama]'), 'Isian komponen manual tidak ditawarkan untuk santri bertarif');
$f = $formTarif($r['body']);
$assert(($f['mode'] ?? '') === 'tarif' && strlen($f['pratinjau_hash'] ?? '') === 64 && ($f['periode'] ?? '') === $periode, 'Formulir terbit membawa mode tarif, periode, dan hash pratinjau');
$r1 = $p->req("/portal/bsa_tagihan_buat.php?santri_id=$s1", $f);
$r2 = $p->req("/portal/bsa_tagihan_buat.php?santri_id=$s1", $f);
$tid = (int) $satu("SELECT id FROM bsa_tagihan WHERE santri_id = $s1 AND periode = '$periode'");
$assert(in_array($r1['status'], [302, 303], true) && str_contains((string) $r1['location'], 'bsa_tagihan.php?id=' . $tid) && str_contains((string) $r2['location'], 'bsa_tagihan.php?id=' . $tid)
    && (int) $satu("SELECT COUNT(*) FROM bsa_tagihan WHERE santri_id = $s1") === 1 && (int) $satu("SELECT total FROM bsa_tagihan WHERE id = $tid") === 300000,
    'Terbitkan → rincian tagihan Rp300.000; klik ganda tetap satu tagihan');
$assert((int) $satu("SELECT COUNT(*) FROM bsa_tagihan WHERE periode = '$periode'") === 1, 'Santri SMP lain tidak ikut tertagih');
$rinci = $teks($p->req("/portal/bsa_tagihan.php?id=$tid")['body']);
$assert(str_contains($rinci, 'Syahriah HTTP') && str_contains($rinci, 'Potongan HTTP'), 'Rincian tagihan memuat komponen tarif dan potongan tersalin');
$r = $halaman($p, $s1);
$assert(str_contains($teks($r['body']), 'Tagihan periode ini sudah ada') && str_contains($r['body'], 'bsa_tagihan.php?id=' . $tid) && !str_contains($r['body'], 'id="form-tarif"'),
    'Buka ulang halaman: tagihan sudah ada, tautan ke tagihan, tanpa tombol terbit');

echo PHP_EOL . '=== W-2. Manual ditolak untuk santri bertarif ===' . PHP_EOL;
$depan = (new DateTimeImmutable($periode . '-01'))->modify('+1 month')->format('Y-m');
preg_match('/name="_csrf" value="([^"]+)"/', $r['body'], $c);
$manual = ['_csrf' => $c[1] ?? '', 'idempotency_key' => $k('mn'), 'periode' => $depan, 'jatuh_tempo' => $depan . '-10', 'komponen' => [['nama' => 'Manual liar', 'nominal' => '1000']]];
foreach (['tanpa mode (formulir lama)' => $manual, 'mode manual' => $manual + ['mode' => 'manual']] as $label => $isi) {
    $r = $p->req("/portal/bsa_tagihan_buat.php?santri_id=$s1", $isi);
    $assert($r['status'] === 422 && str_contains($teks($r['body']), 'tercakup tarif unit') && (int) $satu("SELECT COUNT(*) FROM bsa_tagihan WHERE santri_id = $s1") === 1,
        "Kiriman manual $label untuk santri bertarif → 422, tanpa tagihan");
}

echo PHP_EOL . '=== W-3. Tanpa kelas / tanpa tarif ===' . PHP_EOL;
$r = $halaman($p, $s3);
$assert(str_contains($teks($r['body']), 'Tarif tidak dapat dipakai: belum ditempatkan di kelas') && str_contains($r['body'], 'id="form-tagihan"') && !str_contains($r['body'], 'id="form-tarif"'),
    'Santri tanpa kelas: penjelasan + isian manual');
preg_match('/id="form-tagihan".*?name="_csrf" value="([^"]+)".*?name="idempotency_key" value="([^"]+)"/s', $r['body'], $m);
$isiManual = ['_csrf' => $m[1], 'mode' => 'manual', 'idempotency_key' => $m[2], 'periode' => $periode,
    'jatuh_tempo' => $periode . '-10', 'komponen' => [['nama' => 'Syahriah manual', 'nominal' => '120.000']]];
$r = $p->req("/portal/bsa_tagihan_buat.php?santri_id=$s3", $isiManual);
$t3 = (int) $satu("SELECT id FROM bsa_tagihan WHERE santri_id = $s3 AND periode = '$periode'");
$assert(in_array($r['status'], [302, 303], true) && (int) $satu("SELECT total FROM bsa_tagihan WHERE id = $t3") === 120000, 'Kiriman manual santri tanpa kelas → tagihan Rp120.000');
$r = $p->req("/portal/bsa_tagihan_buat.php?santri_id=$s3", $isiManual);
$assert(in_array($r['status'], [302, 303], true) && str_contains((string) $r['location'], 'bsa_tagihan.php?id=' . $t3) && (int) $satu("SELECT COUNT(*) FROM bsa_tagihan WHERE santri_id = $s3") === 1,
    'Kiriman manual ulang kunci sama → replay ke tagihan yang sama (tidak ditolak, tidak ganda)');
$r = $halaman($p, $s3);
$assert(str_contains($teks($r['body']), 'Tagihan periode ini sudah ada') && str_contains($r['body'], 'id="form-tagihan"'),
    'Santri tanpa kelas yang sudah bertagihan bulan ini: catatan "sudah ada" dan isian manual tetap tersedia untuk bulan lain');
$r = $halaman($p, $s2);
$assert(str_contains($teks($r['body']), 'belum ada tarif unit untuk periode ini') && str_contains($teks($r['body']), 'Tetapkan tarif unit lebih dahulu') && str_contains($r['body'], 'id="form-tagihan"'),
    'Santri Tsanawi tanpa tarif unit: penjelasan + isian manual');

echo PHP_EOL . '=== W-4. Pratinjau basi ===' . PHP_EOL;
$r = $halaman($p, $smpLain);
$f = $formTarif($r['body']);
$tag->buatPenyesuaian($petugasArr, $smpLain, ['idempotency_key' => $k('pj'), 'jenis' => 'Tambahan', 'nama' => 'Tambahan basi', 'nominal' => '5000', 'berlaku_mulai' => $periode, 'alasan' => 'Uji pratinjau basi'], 'web');
$r = $p->req("/portal/bsa_tagihan_buat.php?santri_id=$smpLain", $f);
$ikut = $p->req((string) parse_url((string) $r['location'], PHP_URL_PATH) . '?' . (string) parse_url((string) $r['location'], PHP_URL_QUERY));
$assert(in_array($r['status'], [302, 303], true) && str_contains($teks($ikut['body']), 'berubah sejak pratinjau') && preg_match('/Total tagihan Tarif ± penyesuaian Rp355\.000/u', $teks($ikut['body'])) === 1
    && (int) $satu("SELECT COUNT(*) FROM bsa_tagihan WHERE santri_id = $smpLain") === 0, 'Tambahan sesudah pratinjau → pesan, pratinjau baru Rp355.000, tanpa tagihan');

echo PHP_EOL . '=== W-5. Hak halaman ===' . PHP_EOL;
foreach (['sbx_admin' => 403, 'sbx_v6_wali_smp' => 403, 'sbx_pengurus_biasa' => 403, 'sbx_v6_bsa_tsanawi' => 404] as $u => $kode) {
    $kl = (new Klien($base))->login($u);
    $assert($halaman($kl, $smpLain)['status'] === $kode, "$u: halaman terbitkan tagihan santri SMP → $kode");
}

echo PHP_EOL . '=== W-6. API ===' . PHP_EOL;
$api = static function (string $metode, string $path, ?string $token, ?array $body = null) use ($base): array {
    $kl = new Klien($base);
    $h = ['Accept: application/json', 'Content-Type: application/json'];
    if ($token !== null) {
        $h[] = 'Authorization: Bearer ' . $token;
    }
    $r = $kl->req('/api/v1' . $path, null, $h, $metode === 'POST' ? (string) json_encode($body ?? []) : null);
    $r['json'] = json_decode($r['body'], true);

    return $r;
};
$token = (string) ($api('POST', '/auth/login', null, ['username' => 'sbx_v6_bsa_b', 'password' => 'Sandbox#123', 'device_name' => 'uji-satu-santri'])['json']['data']['token'] ?? '');
$pr = $api('POST', '/v6/bsa/penerbitan/pratinjau', $token, ['santri_id' => $smpLain, 'periode' => $periode, 'jatuh_tempo' => $periode . '-20']);
$assert($pr['status'] === 200 && count($pr['json']['data']['rows']) === 1 && $pr['json']['data']['santri_id'] === $smpLain && count($pr['json']['data']['rows'][0]['komponen']) === 2
    && $pr['json']['data']['rows'][0]['total'] === 355000, 'API pratinjau dengan santri_id: satu baris, komponen tarif, total Rp355.000');
$tb = $api('POST', '/v6/bsa/penerbitan', $token, ['idempotency_key' => $k('api'), 'santri_id' => $smpLain, 'periode' => $periode, 'jatuh_tempo' => $periode . '-20', 'pratinjau_hash' => $pr['json']['data']['hash']]);
$assert($tb['status'] === 201 && $tb['json']['data']['jumlah_terbit'] === 1 && (int) $satu("SELECT total FROM bsa_tagihan WHERE santri_id = $smpLain AND periode = '$periode'") === 355000,
    'API penerbitan dengan santri_id → 201, satu tagihan Rp355.000');
$assert($api('POST', '/v6/bsa/penerbitan/pratinjau', $token, ['santri_id' => $s1 + 1000000, 'periode' => $periode, 'jatuh_tempo' => $periode . '-20'])['status'] === 404,
    'API pratinjau santri tidak ada → 404');

echo PHP_EOL . '=== W-7. Log dan verifier ===' . PHP_EOL;
usleep(300000);
$assert(!preg_match('/PHP (Warning|Notice|Fatal|Deprecated)|Uncaught/', (string) file_get_contents($log)), 'Log server tanpa galat/peringatan PHP');
exec('DB_NAME=' . escapeshellarg($db) . ' php ' . escapeshellarg($root . '/bin/v6_phase3_verify.php') . ' 2>&1', $out, $kodeVerify);
$assert($kodeVerify === 0, 'Verifier Fase 1–3 lulus sesudah uji HTTP');
@unlink($log);

echo PHP_EOL . 'Lulus: ' . $lulus . ' · Gagal: ' . count($gagal) . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
