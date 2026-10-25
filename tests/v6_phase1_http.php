<?php

declare(strict_types=1);

/**
 * Uji server PRD V6 Fase 1 — website dan API keuangan santri/BSA lewat server
 * PHP lokal di atas DB uji `tests/v6_phase1_db.sh`.
 *
 *   V6F1_DB=test_v6_f1_http_test bash tests/v6_phase1_db.sh
 *   V6F1_DB=test_v6_f1_http_test php tests/v6_phase1_http.php
 *
 *   H-A  admin menugaskan satu pengurus BSA lewat Pusat Penugasan (POST+CSRF);
 *        non-admin ditolak;
 *   H-B  hak setiap halaman per akun (server, bukan menu); tanpa sesi → masuk;
 *        menu Keuangan per peran; admin tanpa tombol pencatatan;
 *   H-C  petugas lewat formulir web: tepat satu tagihan, setoran Rp100.000,
 *        penarikan Rp30.000 → saldo Rp70.000 tampil; klik ganda (POST ulang
 *        kunci sama) tidak menggandakan; CSRF wajib; GET tidak memutasi;
 *        admin/wali/pengurus lain POST → 403/404 tanpa baris;
 *   H-D  API: token petugas (Idempotency-Key), replay, kunci sama nominal beda
 *        409, saldo Rp70.000 di API; admin/wali/pengurus/guru 403; tanpa token
 *        401; wali anak lain/ID ditebak 404; no-store;
 *   H-E  dua penarikan Rp80.000 bersamaan lewat API atas saldo Rp100.000 →
 *        paling banyak satu sukses, tidak negatif;
 *   H-F  penugasan dinonaktifkan admin saat sesi web/token petugas masih
 *        aktif → mutasi dan baca berikutnya ditolak tanpa baris baru;
 *   H-G  akun dinonaktifkan dan relasi wali dicabut saat sesi aktif;
 *   H-H  log server tanpa galat/peringatan PHP; audit tanpa nominal/referensi.
 *
 * Mengubah data uji; bangun ulang DB sebelum mengulang.
 */

$root = dirname(__DIR__);
$db = getenv('V6F1_DB') ?: 'test_v6_f1_http_test';
$port = (int) (getenv('V6F1_PORT') ?: 8996);
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

$log = tempnam(sys_get_temp_dir(), 'v6f1-server-');
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
        $this->jar = tempnam(sys_get_temp_dir(), 'v6f1-jar-');
    }

    /** @return array{status:int, body:string, location:?string, type:string, header:string} */
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
        $type = (string) curl_getinfo($h, CURLINFO_CONTENT_TYPE);
        curl_close($h);
        preg_match('/^Location:\s*(.+)$/mi', substr($out, 0, $size), $m);

        return ['status' => $status, 'body' => substr($out, $size), 'location' => isset($m[1]) ? trim($m[1]) : null, 'type' => $type, 'header' => substr($out, 0, $size)];
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
    $r = $api('POST', '/auth/login', null, ['username' => $user, 'password' => 'Sandbox#123', 'device_name' => 'uji-v6']);

    return (string) ($r['json']['data']['token'] ?? '');
};
$conn = app_db();
$satu = static function (string $sql) use ($conn): mixed {
    $r = $conn->query($sql);
    if ($r === false) {
        throw new RuntimeException($conn->error . ' :: ' . $sql);
    }
    $row = $r->fetch_row();

    return $row[0] ?? null;
};
$jalankan = static function (string $sql) use ($conn): void {
    if ($conn->query($sql) === false) {
        throw new RuntimeException($conn->error . ' :: ' . $sql);
    }
};
$teks = static fn (string $html): string => trim(html_entity_decode((string) preg_replace('/\s+/', ' ', (string) preg_replace('/<[^>]+>/', ' ', (string) preg_replace('#<(script|style|template)\b.*?</\1>#s', '', $html))), ENT_QUOTES));
$jumlah = static fn (): array => [
    'transaksi' => (int) $satu('SELECT COUNT(*) FROM bsa_tabungan_transaksi'),
    'tagihan' => (int) $satu('SELECT COUNT(*) FROM bsa_tagihan'),
    'saldo' => (int) $satu('SELECT COALESCE(SUM(saldo), 0) FROM bsa_tabungan_akun'),
];
$saldo = static fn (int $s): int => (int) $satu("SELECT COALESCE((SELECT saldo FROM bsa_tabungan_akun WHERE santri_id = $s), 0)");
$sid = static fn (string $nis): int => (int) $satu("SELECT id FROM santri WHERE nis='$nis'");
$sSmp = $sid('V6S0001');
$sTsanawi = $sid('V6S0002');
$sTanpa = $sid('V6S0003');
$tahun = (int) $satu("SELECT id FROM tahun_ajaran WHERE status='Aktif' AND archived_at IS NULL ORDER BY id DESC LIMIT 1");
$pBsa = (int) $satu("SELECT pengurus_id FROM users WHERE username='sbx_v6_bsa'");
$assert(min($sSmp, $sTsanawi, $sTanpa, $pBsa) > 0 && $jumlah() === ['transaksi' => 0, 'tagihan' => 0, 'saldo' => 0], 'Fixture V6 Fase 1 tersedia; tabel BSA kosong');
$formulir = static function (Klien $kl, string $alamat): array {
    $r = $kl->req($alamat);
    preg_match('/name="idempotency_key" value="([^"]+)"/', $r['body'], $mk);
    preg_match('/name="_csrf" value="([^"]+)"/', $r['body'], $mc);

    return ['status' => $r['status'], 'kunci' => $mk[1] ?? '', 'csrf' => $mc[1] ?? '', 'body' => $r['body']];
};

$akun = ['sbx_admin', 'sbx_v6_bsa', 'sbx_v6_bsa_b', 'sbx_v6_bsa_tsanawi', 'sbx_v6_admin_bsa', 'sbx_v6_wali_smp', 'sbx_v6_wali_tsanawi', 'sbx_pengurus_biasa', 'sbx_pengurus_a', 'sbx_guru_biasa', 'sbx_ortu_a'];
$k = [];
foreach ($akun as $u) {
    $k[$u] = $klien($u);
}

// ===================================================================== H-A
echo PHP_EOL . '=== H-A. Admin menugaskan satu pengurus BSA ===' . PHP_EOL;
$r = $k['sbx_admin']->req('/admin/admin_penugasan.php?jenis=bendahara_bulanan&tambah=1');
$assert($r['status'] === 200 && str_contains($r['body'], 'Petugas BSA') && str_contains($teks($r['body']), 'tidak gugur saat semester berganti') && !str_contains($teks($r['body']), 'Belum ada — menunggu modul baru'),
    'Formulir penugasan Petugas BSA: cakupan unit opsional, tidak gugur per semester');
$assert($k['sbx_v6_bsa']->req('/portal/bsa.php')['status'] === 403, 'Sebelum ditugaskan, pengurus ditolak membuka keuangan santri (403)');
$r = $k['sbx_pengurus_a']->req('/admin/admin_penugasan.php?jenis=bendahara_bulanan', ['_csrf' => $k['sbx_pengurus_a']->csrf(), 'jenis' => 'bendahara_bulanan', 'action' => 'buat',
    'pengurus_id' => $pBsa, 'tahun_ajaran_id' => $tahun, 'jenjang' => '', 'tanggal_mulai' => date('Y-m-d')]);
$assert((int) $satu("SELECT COUNT(*) FROM bendahara_bulanan_assignments WHERE pengurus_id = $pBsa") === 0, 'Non-admin tidak dapat menugaskan BSA (status ' . $r['status'] . ')');
$csrf = $k['sbx_admin']->csrf('/admin/admin_penugasan.php?jenis=bendahara_bulanan');
$r = $k['sbx_admin']->req('/admin/admin_penugasan.php?jenis=bendahara_bulanan', ['_csrf' => $csrf, 'jenis' => 'bendahara_bulanan', 'action' => 'buat',
    'pengurus_id' => $pBsa, 'tahun_ajaran_id' => $tahun, 'jenjang' => '', 'tanggal_mulai' => date('Y-m-d'), 'catatan' => 'Uji HTTP V6']);
$tugas = (int) $satu("SELECT id FROM bendahara_bulanan_assignments WHERE pengurus_id = $pBsa AND is_active = 1");
$assert(in_array($r['status'], [302, 303], true) && $tugas > 0, 'Admin menyimpan penugasan Petugas BSA lewat POST + CSRF');
$r = $k['sbx_admin']->req('/admin/admin_penugasan.php?jenis=bendahara_bulanan&status=aktif');
$assert($r['status'] === 200 && str_contains($r['body'], 'SBX V6 Petugas BSA') && str_contains($r['body'], 'Seluruh unit') && str_contains($r['body'], 'Mencatat setoran dan penarikan tabungan'),
    'Daftar penugasan memuat petugas, cakupan, dan hak BSA');

// ===================================================================== H-B
echo PHP_EOL . '=== H-B. Hak setiap halaman (server) ===' . PHP_EOL;
$layar = [
    '/portal/bsa.php' => ['sbx_admin' => 200, 'sbx_v6_bsa' => 200, 'sbx_v6_bsa_tsanawi' => 200, 'sbx_v6_admin_bsa' => 200, 'sbx_v6_wali_smp' => 200,
        'sbx_pengurus_biasa' => 403, 'sbx_pengurus_a' => 403, 'sbx_guru_biasa' => 403],
    "/portal/bsa_santri.php?id=$sSmp" => ['sbx_admin' => 200, 'sbx_v6_bsa' => 200, 'sbx_v6_admin_bsa' => 200, 'sbx_v6_wali_smp' => 200,
        'sbx_v6_bsa_tsanawi' => 404, 'sbx_v6_wali_tsanawi' => 404, 'sbx_ortu_a' => 404, 'sbx_pengurus_biasa' => 403, 'sbx_guru_biasa' => 403],
    "/portal/bsa_transaksi.php?santri_id=$sSmp&jenis=setoran" => ['sbx_v6_bsa' => 200, 'sbx_admin' => 403, 'sbx_v6_admin_bsa' => 403, 'sbx_v6_wali_smp' => 403,
        'sbx_pengurus_biasa' => 403, 'sbx_v6_bsa_tsanawi' => 404],
    "/portal/bsa_tagihan_buat.php?santri_id=$sSmp" => ['sbx_v6_bsa' => 200, 'sbx_admin' => 403, 'sbx_v6_wali_smp' => 403, 'sbx_v6_bsa_tsanawi' => 404],
    '/portal/bsa_tagihan.php?id=999999' => ['sbx_v6_bsa' => 404, 'sbx_admin' => 404, 'sbx_v6_wali_smp' => 404],
];
foreach ($layar as $alamat => $harap) {
    foreach ($harap as $u => $status) {
        $r = $k[$u]->req($alamat);
        $assert($r['status'] === $status, "$alamat $u → $status" . ($r['status'] === $status ? '' : ' (dapat ' . $r['status'] . ')'));
    }
}
$r = (new Klien($base))->req('/portal/bsa.php');
$assert(in_array($r['status'], [302, 303], true) && str_contains((string) $r['location'], 'pesan=sesi'), 'Tanpa sesi → dialihkan ke halaman masuk');
$r = $k['sbx_v6_bsa']->req("/portal/bsa_santri.php?id=$sSmp");
$assert(str_contains($r['header'], 'no-store') && str_contains($r['body'], 'Catat setoran') && str_contains($r['body'], 'Catat penarikan') && str_contains($r['body'], 'Terbitkan tagihan'),
    'Rincian petugas: no-store dan tombol setoran/penarikan/tagihan');
$r = $k['sbx_admin']->req("/portal/bsa_santri.php?id=$sSmp");
$assert(!str_contains($r['body'], 'bsa_transaksi.php') && !str_contains($r['body'], 'bsa_tagihan_buat.php') && str_contains($teks($r['body']), 'Hanya baca'), 'Rincian admin: tanpa tautan pencatatan, berlabel hanya baca');
$r = $k['sbx_v6_wali_smp']->req("/portal/bsa_santri.php?id=$sSmp");
$assert(!str_contains($r['body'], 'bsa_transaksi.php') && str_contains($r['body'], 'Keuangan anak'), 'Rincian wali: judul Keuangan anak, tanpa tautan pencatatan');
$r = $k['sbx_v6_bsa_tsanawi']->req('/portal/bsa.php?q=SBX+V6');
$assert(str_contains($r['body'], 'SBX V6 Santri Tsanawi') && !str_contains($r['body'], 'SBX V6 Santri SMP') && !str_contains($r['body'], 'SBX V6 Santri Tanpa Kelas'), 'Daftar petugas unit Tsanawi tanpa santri unit lain');
$r = $k['sbx_v6_wali_smp']->req('/portal/bsa.php');
$assert(str_contains($r['body'], 'SBX V6 Santri SMP') && !str_contains($r['body'], 'SBX V6 Santri Tsanawi') && !str_contains($r['body'], 'id="list-q"'), 'Daftar wali hanya anaknya, tanpa kotak pencarian daftar');
$menu = static fn (Klien $kl): bool => str_contains($kl->req('/portal/index.php')['body'], '/portal/bsa.php');
$assert($menu($k['sbx_admin']) && $menu($k['sbx_v6_bsa']) && $menu($k['sbx_v6_wali_smp']) && !$menu($k['sbx_pengurus_biasa']) && !$menu($k['sbx_guru_biasa']) && !$menu($k['sbx_pengurus_a']),
    'Menu Keuangan hanya untuk admin, petugas BSA, dan orang tua');

// ===================================================================== H-C
echo PHP_EOL . '=== H-C. Formulir web petugas ===' . PHP_EOL;
$p = $k['sbx_v6_bsa'];
$f = $formulir($p, "/portal/bsa_tagihan_buat.php?santri_id=$sSmp");
$tagihanIsi = ['_csrf' => $f['csrf'], 'idempotency_key' => $f['kunci'], 'periode' => '2026-10', 'jatuh_tempo' => '2026-10-10',
    'komponen' => [['nama' => 'Syahriah uji', 'nominal' => '250.000'], ['nama' => 'Makan uji', 'nominal' => '300000'], ['nama' => '', 'nominal' => '']], 'catatan' => 'Uji HTTP'];
$r = $p->req("/portal/bsa_tagihan_buat.php?santri_id=$sSmp", array_diff_key($tagihanIsi, ['_csrf' => 1]));
$assert($r['status'] === 419 && $jumlah()['tagihan'] === 0, 'POST tagihan tanpa CSRF ditolak (419) tanpa baris');
$r1 = $p->req("/portal/bsa_tagihan_buat.php?santri_id=$sSmp", $tagihanIsi);
$idTagihan = (int) $satu('SELECT MAX(id) FROM bsa_tagihan');
$r2 = $p->req("/portal/bsa_tagihan_buat.php?santri_id=$sSmp", $tagihanIsi);
$assert(in_array($r1['status'], [302, 303], true) && str_contains((string) $r1['location'], 'bsa_tagihan.php?id=' . $idTagihan) && $r2['location'] === $r1['location'] && $jumlah()['tagihan'] === 1,
    'POST tagihan + POST ulang kunci sama → tepat satu tagihan, keduanya ke rincian yang sama');
$r = $p->req('/portal/bsa_tagihan.php?id=' . $idTagihan);
$assert($r['status'] === 200 && str_contains($r['body'], 'Rp550.000') && str_contains($r['body'], 'Syahriah uji') && str_contains($r['body'], 'Belum dibayar') && str_contains($teks($r['body']), 'Oktober 2026'),
    'Rincian tagihan: total Rp550.000, komponen snapshot, Belum dibayar');
$f = $formulir($p, "/portal/bsa_tagihan_buat.php?santri_id=$sSmp");
$r = $p->req("/portal/bsa_tagihan_buat.php?santri_id=$sSmp", ['_csrf' => $f['csrf'], 'idempotency_key' => $f['kunci']] + array_diff_key($tagihanIsi, ['_csrf' => 1, 'idempotency_key' => 1]));
$assert($r['status'] === 409 && $jumlah()['tagihan'] === 1 && str_contains($teks($r['body']), 'sudah terbit') && str_contains($r['body'], 'value="' . $f['kunci'] . '"'),
    'Tagihan kedua periode sama (kunci lain) → 409, formulir dirender ulang dengan kunci sama');
$f = $formulir($p, "/portal/bsa_transaksi.php?santri_id=$sSmp&jenis=setoran");
$setor = ['_csrf' => $f['csrf'], 'idempotency_key' => $f['kunci'], 'nominal' => '100.000', 'metode' => 'Tunai'];
$r1 = $p->req("/portal/bsa_transaksi.php?santri_id=$sSmp&jenis=setoran", $setor);
$r2 = $p->req("/portal/bsa_transaksi.php?santri_id=$sSmp&jenis=setoran", $setor);
$assert(in_array($r1['status'], [302, 303], true) && in_array($r2['status'], [302, 303], true) && $jumlah()['transaksi'] === 1 && $saldo($sSmp) === 100000,
    'Setoran Rp100.000 + klik ganda (POST ulang kunci sama) → tepat satu kredit');
$r = $p->req("/portal/bsa_transaksi.php?santri_id=$sSmp&jenis=setoran", array_merge($setor, ['nominal' => '100001']));
$assert($r['status'] === 409 && $jumlah()['transaksi'] === 1, 'Kunci sama nominal beda lewat web → 409 tanpa mutasi');
$f = $formulir($p, "/portal/bsa_transaksi.php?santri_id=$sSmp&jenis=penarikan");
$assert(str_contains($f['body'], 'Rp100.000') && str_contains($f['body'], 'name="tujuan"'), 'Formulir penarikan menampilkan saldo dan meminta tujuan');
$tarik = ['_csrf' => $f['csrf'], 'idempotency_key' => $f['kunci'], 'nominal' => '30000', 'metode' => 'Tunai', 'tujuan' => 'Beli kitab uji HTTP'];
$r = $p->req("/portal/bsa_transaksi.php?santri_id=$sSmp&jenis=penarikan", array_merge($tarik, ['nominal' => '100001']));
$assert($r['status'] === 422 && $jumlah()['transaksi'] === 1 && str_contains($teks($r['body']), 'tidak mencukupi') && str_contains($r['body'], 'value="' . $f['kunci'] . '"'),
    'Penarikan melebihi saldo → 422 (kunci tetap dipertahankan), tanpa mutasi');
$r = $p->req("/portal/bsa_transaksi.php?santri_id=$sSmp&jenis=penarikan", $tarik);
$assert(in_array($r['status'], [302, 303], true) && $saldo($sSmp) === 70000 && $jumlah()['transaksi'] === 2, 'Penarikan Rp30.000 → saldo Rp70.000');
$r = $p->req("/portal/bsa_santri.php?id=$sSmp");
$assert(str_contains($r['body'], 'Rp70.000') && str_contains($teks($r['body']), 'Saldo sekarang Rp70.000') && str_contains($r['body'], 'Beli kitab uji HTTP'), 'Rincian web menampilkan saldo Rp70.000 dan riwayat');
$p->req("/portal/bsa_transaksi.php?santri_id=$sSmp&jenis=setoran&" . http_build_query(['idempotency_key' => 'get-' . bin2hex(random_bytes(6)), 'nominal' => '5000', 'metode' => 'Tunai']));
$assert($jumlah()['transaksi'] === 2, 'GET dengan parameter tidak memutasi');
foreach (['sbx_admin' => 403, 'sbx_v6_admin_bsa' => 403, 'sbx_v6_wali_smp' => 403, 'sbx_pengurus_biasa' => 403, 'sbx_v6_bsa_tsanawi' => 404] as $u => $harap) {
    $r = $k[$u]->req("/portal/bsa_transaksi.php?santri_id=$sSmp&jenis=penarikan", array_merge($tarik, ['_csrf' => $k[$u]->csrf(), 'idempotency_key' => bin2hex(random_bytes(12))]));
    $assert($r['status'] === $harap && $jumlah()['transaksi'] === 2 && $saldo($sSmp) === 70000, "$u POST penarikan web → $harap tanpa mutasi");
}

// ===================================================================== H-D
echo PHP_EOL . '=== H-D. API BSA ===' . PHP_EOL;
$tP = $token('sbx_v6_bsa');
$tAdmin = $token('sbx_admin');
$tWali = $token('sbx_v6_wali_smp');
$tWaliLain = $token('sbx_v6_wali_tsanawi');
$assert($tP !== '' && $tAdmin !== '' && $tWali !== '', 'Token API petugas, admin, dan wali diperoleh');
$r = $api('GET', '/v6/bsa/akses', $tP);
$assert($r['status'] === 200 && $r['json']['data']['kelola'] === true && $r['json']['data']['sumber'] === 'petugas' && str_contains($r['header'], 'no-store'), 'GET akses petugas: kelola=true, no-store');
$r = $api('GET', '/v6/bsa/akses', $tAdmin);
$assert($r['status'] === 200 && $r['json']['data']['baca'] === true && $r['json']['data']['kelola'] === false, 'GET akses admin: baca, tanpa kelola');
$assert($api('GET', '/v6/bsa/santri', null)['status'] === 401, 'Tanpa token → 401');
$r = $api('GET', "/v6/bsa/santri/$sSmp/saldo", $tP);
$assert($r['status'] === 200 && $r['json']['data']['saldo'] === 70000, 'Saldo web Rp70.000 terbaca sama di API');
$assert($api('GET', "/v6/bsa/santri/$sSmp/saldo", $tAdmin)['json']['data']['saldo'] === 70000 && $api('GET', "/v6/bsa/santri/$sSmp/saldo", $tWali)['json']['data']['saldo'] === 70000,
    'Admin dan wali anak itu membaca saldo Rp70.000 di API');
$kunci = 'api-' . bin2hex(random_bytes(8));
$r = $api('POST', "/v6/bsa/santri/$sTanpa/setoran", $tP, ['nominal' => 100000, 'metode' => 'Transfer', 'referensi' => 'TRF-HTTP-1'], ['Idempotency-Key: ' . $kunci]);
$idApi = (int) ($r['json']['data']['id'] ?? 0);
$assert($r['status'] === 201 && $idApi > 0 && $r['json']['data']['replayed'] === false && $r['json']['data']['saldo_sesudah'] === 100000, 'POST setoran API → 201, saldo sesudah Rp100.000');
$r = $api('POST', "/v6/bsa/santri/$sTanpa/setoran", $tP, ['nominal' => 100000, 'metode' => 'Transfer', 'referensi' => 'TRF-HTTP-1'], ['Idempotency-Key: ' . $kunci]);
$assert($r['status'] === 200 && (int) $r['json']['data']['id'] === $idApi && $r['json']['data']['replayed'] === true && $saldo($sTanpa) === 100000, 'Ulang setoran kunci+isi sama → 200 replay, tepat satu kredit');
$r = $api('POST', "/v6/bsa/santri/$sTanpa/setoran", $tP, ['nominal' => 90000, 'metode' => 'Transfer', 'referensi' => 'TRF-HTTP-1'], ['Idempotency-Key: ' . $kunci]);
$assert($r['status'] === 409 && $r['json']['error']['code'] === 'CONFLICT' && $saldo($sTanpa) === 100000, 'Kunci sama nominal berbeda → 409 CONFLICT tanpa mutasi');
$r = $api('POST', "/v6/bsa/santri/$sTanpa/setoran", $tP, ['nominal' => 1000, 'metode' => 'Tunai']);
$assert($r['status'] === 422 && $saldo($sTanpa) === 100000, 'Tanpa kunci idempotensi → 422');
$r = $api('POST', "/v6/bsa/santri/$sTanpa/setoran", $tP, ['nominal' => 1000.5, 'metode' => 'Tunai'], ['Idempotency-Key: ' . bin2hex(random_bytes(8))]);
$assert($r['status'] === 422 && $saldo($sTanpa) === 100000, 'Nominal pecahan (float JSON) → 422');
$r = $api('POST', "/v6/bsa/santri/$sTanpa/penarikan", $tP, ['nominal' => 30000, 'metode' => 'Tunai', 'tujuan' => 'Uji API tarik'], ['Idempotency-Key: ' . bin2hex(random_bytes(8))]);
$assert($r['status'] === 201 && $r['json']['data']['saldo_sesudah'] === 70000 && $api('GET', "/v6/bsa/santri/$sTanpa/saldo", $tP)['json']['data']['saldo'] === 70000, 'Penarikan API Rp30.000 → saldo Rp70.000');
$r = $api('POST', '/v6/bsa/tagihan', $tP, ['santri_id' => $sTanpa, 'periode' => '2026-10', 'jatuh_tempo' => '2026-10-10', 'komponen' => [['nama' => 'Syahriah uji', 'nominal' => 250000]]], ['Idempotency-Key: ' . bin2hex(random_bytes(8))]);
$idT = (int) ($r['json']['data']['id'] ?? 0);
$assert($r['status'] === 201 && $idT > 0, 'POST tagihan API → 201');
$r = $api('GET', '/v6/bsa/tagihan/' . $idT, $tP);
$assert($r['status'] === 200 && $r['json']['data']['total'] === 250000 && $r['json']['data']['sisa'] === 250000 && count($r['json']['data']['komponen']) === 1, 'GET tagihan API: total = sisa Rp250.000, satu komponen');
$r = $api('GET', "/v6/bsa/santri/$sTanpa", $tP);
$assert($r['status'] === 200 && $r['json']['data']['saldo'] === 70000 && count($r['json']['data']['tagihan']) === 1 && $r['json']['data']['tabungan']['total'] === 2
    && ($r['json']['data']['tabungan']['rows'][1]['referensi'] ?? '') === 'TRF-HTTP-1', 'GET rincian santri API: saldo, tagihan, riwayat dengan referensi bagi petugas');
$r = $api('GET', "/v6/bsa/santri/$sSmp", $tWali);
$assert($r['status'] === 200 && !str_contains($r['body'], '"referensi"') && !str_contains($r['body'], '"catatan"') && str_contains($r['body'], 'Beli kitab uji HTTP'), 'Wali membaca anaknya lewat API tanpa referensi/catatan internal');
$assert($api('GET', "/v6/bsa/santri/$sSmp", $tWaliLain)['status'] === 404 && $api('GET', '/v6/bsa/tagihan/' . $idTagihan, $tWaliLain)['status'] === 404
    && $api('GET', "/v6/bsa/santri/$sSmp/saldo", $tWaliLain)['status'] === 404, 'Wali anak lain: rincian/tagihan/saldo lewat ID ditebak → 404');
$r = $api('GET', '/v6/bsa/santri', $tWaliLain);
$assert($r['status'] === 200 && array_column($r['json']['data']['rows'], 'id') === [$sTsanawi], 'Daftar API wali anak lain hanya memuat anaknya');
$sebelum = $jumlah();
foreach (['sbx_admin' => $tAdmin, 'sbx_v6_admin_bsa' => $token('sbx_v6_admin_bsa'), 'sbx_v6_wali_smp' => $tWali, 'sbx_pengurus_biasa' => $token('sbx_pengurus_biasa'), 'sbx_guru_biasa' => $token('sbx_guru_biasa')] as $u => $t) {
    $a = $api('POST', "/v6/bsa/santri/$sSmp/setoran", $t, ['nominal' => 1000, 'metode' => 'Tunai'], ['Idempotency-Key: ' . bin2hex(random_bytes(8))]);
    $b = $api('POST', "/v6/bsa/santri/$sSmp/penarikan", $t, ['nominal' => 1000, 'metode' => 'Tunai', 'tujuan' => 'Uji tolak'], ['Idempotency-Key: ' . bin2hex(random_bytes(8))]);
    $c = $api('POST', '/v6/bsa/tagihan', $t, ['santri_id' => $sSmp, 'periode' => '2026-12', 'jatuh_tempo' => '2026-12-10', 'komponen' => [['nama' => 'X', 'nominal' => 1]]], ['Idempotency-Key: ' . bin2hex(random_bytes(8))]);
    $assert($a['status'] === 403 && $b['status'] === 403 && $c['status'] === 403 && $jumlah() === $sebelum, "API mutasi $u → 403 tanpa baris");
}
foreach (['sbx_pengurus_biasa', 'sbx_guru_biasa'] as $u) {
    $t = $token($u);
    $assert($api('GET', '/v6/bsa/santri', $t)['status'] === 403 && $api('GET', "/v6/bsa/santri/$sSmp", $t)['status'] === 403, "API baca $u → 403");
}
$tT = $token('sbx_v6_bsa_tsanawi');
$assert($api('POST', "/v6/bsa/santri/$sSmp/setoran", $tT, ['nominal' => 1000, 'metode' => 'Tunai'], ['Idempotency-Key: ' . bin2hex(random_bytes(8))])['status'] === 404
    && $api('GET', "/v6/bsa/santri/$sSmp", $tT)['status'] === 404 && $jumlah() === $sebelum, 'Petugas di luar cakupan unit → 404 untuk baca dan mutasi, tanpa baris');
$assert($api('GET', '/v6/bsa/tidak-ada', $tP)['status'] === 404, 'Endpoint BSA tak dikenal → 404');

// ===================================================================== H-E
echo PHP_EOL . '=== H-E. Dua penarikan Rp80.000 bersamaan lewat API ===' . PHP_EOL;
$tB = $token('sbx_v6_bsa_b');
$r = $api('POST', "/v6/bsa/santri/$sTsanawi/setoran", $tB, ['nominal' => 100000, 'metode' => 'Tunai'], ['Idempotency-Key: ' . bin2hex(random_bytes(8))]);
$assert($r['status'] === 201 && $saldo($sTsanawi) === 100000, 'Saldo santri uji Rp100.000');
$multi = curl_multi_init();
$handles = [];
for ($i = 0; $i < 2; $i++) {
    $h = curl_init($base . "/api/v1/v6/bsa/santri/$sTsanawi/penarikan");
    curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 'Authorization: Bearer ' . $tB, 'Idempotency-Key: serbu-' . $i . '-' . bin2hex(random_bytes(6))],
        CURLOPT_POSTFIELDS => json_encode(['nominal' => 80000, 'metode' => 'Tunai', 'tujuan' => 'Uji bersamaan'])]);
    curl_multi_add_handle($multi, $h);
    $handles[] = $h;
}
do {
    curl_multi_exec($multi, $jalan);
    curl_multi_select($multi);
} while ($jalan > 0);
$kode = array_map(static fn ($h) => (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE), $handles);
foreach ($handles as $h) {
    curl_multi_remove_handle($multi, $h);
    curl_close($h);
}
curl_multi_close($multi);
sort($kode);
$assert(count(array_filter($kode, static fn ($c) => $c === 201)) <= 1 && $saldo($sTsanawi) >= 0 && $saldo($sTsanawi) === 100000 - 80000 * count(array_filter($kode, static fn ($c) => $c === 201)),
    'Dua penarikan bersamaan → kode ' . json_encode($kode) . ', saldo Rp' . $saldo($sTsanawi) . ' (paling banyak satu sukses, tidak negatif)');
$assert($kode === [201, 422], 'Server PHP lokal melayani berurutan/bersamaan: tepat satu 201 dan satu 422 saldo kurang');

// ===================================================================== H-F
echo PHP_EOL . '=== H-F. Penugasan dinonaktifkan saat sesi masih aktif ===' . PHP_EOL;
$pb = $k['sbx_v6_bsa_b'];
$f = $formulir($pb, "/portal/bsa_transaksi.php?santri_id=$sTsanawi&jenis=setoran");
$assert($f['status'] === 200 && $f['kunci'] !== '', 'Petugas B membuka formulir saat penugasan aktif');
$tugasB = (int) $satu("SELECT a.id FROM bendahara_bulanan_assignments a JOIN users u ON u.pengurus_id = a.pengurus_id WHERE u.username = 'sbx_v6_bsa_b'");
$csrf = $k['sbx_admin']->csrf('/admin/admin_penugasan.php?jenis=bendahara_bulanan');
$k['sbx_admin']->req('/admin/admin_penugasan.php?jenis=bendahara_bulanan', ['_csrf' => $csrf, 'jenis' => 'bendahara_bulanan', 'action' => 'nonaktifkan', 'id' => $tugasB, 'alasan' => 'Uji pencabutan saat sesi aktif']);
$assert((int) $satu("SELECT is_active FROM bendahara_bulanan_assignments WHERE id = $tugasB") === 0, 'Admin menonaktifkan penugasan BSA lewat Pusat Penugasan');
$sebelum = $jumlah();
$r = $pb->req("/portal/bsa_transaksi.php?santri_id=$sTsanawi&jenis=setoran", ['_csrf' => $f['csrf'], 'idempotency_key' => $f['kunci'], 'nominal' => '5000', 'metode' => 'Tunai']);
$assert($r['status'] === 403 && $jumlah() === $sebelum, 'Formulir yang sudah terbuka dikirim setelah pencabutan → 403 tanpa baris');
$r = $api('POST', "/v6/bsa/santri/$sTsanawi/penarikan", $tB, ['nominal' => 1000, 'metode' => 'Tunai', 'tujuan' => 'Setelah dicabut'], ['Idempotency-Key: ' . bin2hex(random_bytes(8))]);
$assert($r['status'] === 403 && $jumlah() === $sebelum, 'Token lama petugas B setelah pencabutan → 403 tanpa baris');
$assert($pb->req("/portal/bsa_santri.php?id=$sTsanawi")['status'] === 403 && $api('GET', "/v6/bsa/santri/$sTsanawi/saldo", $tB)['status'] === 403, 'Baca web/API juga tertutup bagi sesi lama');
$assert(!str_contains($pb->req('/portal/index.php')['body'], '/portal/bsa.php'), 'Menu Keuangan hilang setelah pencabutan');

// ===================================================================== H-G
echo PHP_EOL . '=== H-G. Akun dinonaktifkan dan relasi wali dicabut ===' . PHP_EOL;
$pt = $k['sbx_v6_bsa_tsanawi'];
$assert($pt->req('/portal/bsa.php')['status'] === 200, 'Petugas Tsanawi membuka daftar sebelum akun dinonaktifkan');
$jalankan("UPDATE users SET is_active = 0 WHERE username = 'sbx_v6_bsa_tsanawi'");
$r = $pt->req('/portal/bsa.php');
$assert($r['status'] !== 200 && !str_contains($r['body'], 'SBX V6 Santri Tsanawi'), 'Sesi web akun nonaktif tidak lagi membuka daftar (status ' . $r['status'] . ')');
$r = $api('POST', "/v6/bsa/santri/$sTsanawi/setoran", $tT, ['nominal' => 1000, 'metode' => 'Tunai'], ['Idempotency-Key: ' . bin2hex(random_bytes(8))]);
$assert(in_array($r['status'], [401, 403], true) && $jumlah() === $sebelum, 'Token akun nonaktif ditolak (status ' . $r['status'] . ') tanpa baris');
$jalankan("UPDATE users SET is_active = 1 WHERE username = 'sbx_v6_bsa_tsanawi'");
$relasi = (int) $satu("SELECT sw.id FROM santri_wali sw JOIN users u ON u.wali_id = sw.wali_id WHERE u.username = 'sbx_v6_wali_smp'");
$jalankan("UPDATE santri_wali SET archived_at = NOW() WHERE id = $relasi");
$assert($k['sbx_v6_wali_smp']->req("/portal/bsa_santri.php?id=$sSmp")['status'] === 404 && $api('GET', "/v6/bsa/santri/$sSmp/saldo", $tWali)['status'] === 404
    && $api('GET', '/v6/bsa/santri', $tWali)['json']['data']['total'] === 0, 'Relasi wali dicabut: sesi web dan token lama → 404, daftar kosong');
$jalankan("UPDATE santri_wali SET archived_at = NULL WHERE id = $relasi");

// ===================================================================== H-H
echo PHP_EOL . '=== H-H. Log dan audit ===' . PHP_EOL;
usleep(300000);
$isiLog = (string) file_get_contents($log);
$assert(!preg_match('/PHP (Warning|Notice|Fatal|Deprecated)|Uncaught/', $isiLog), 'Log server tanpa galat/peringatan PHP');
$assert(!str_contains($isiLog, 'TRF-HTTP-1'), 'Log server tanpa referensi transfer');
$assert((int) $satu("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'bsa.%' AND (CONCAT(COALESCE(before_json,''), COALESCE(after_json,'')) LIKE '%TRF-HTTP%' OR after_json REGEXP '\"(nominal|saldo|saldo_sesudah|tujuan|referensi)\"')") === 0,
    'audit_logs bsa.* tanpa nominal, saldo, tujuan, atau referensi');
$assert((int) $satu("SELECT COUNT(*) FROM audit_logs WHERE action IN ('bsa.tabungan.setoran', 'bsa.tabungan.penarikan')") === $jumlah()['transaksi'], 'Setiap transaksi tepat satu jejak audit');
$out = [];
exec('DB_NAME=' . escapeshellarg($db) . ' php ' . escapeshellarg($root . '/bin/v6_phase1_verify.php') . ' 2>&1', $out, $kodeVerify);
$assert($kodeVerify === 0, 'Verifier invarians V6 lulus sesudah uji HTTP');
@unlink($log);

echo PHP_EOL . 'Lulus: ' . $lulus . ' · Gagal: ' . count($gagal) . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
