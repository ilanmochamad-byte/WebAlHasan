<?php

declare(strict_types=1);

/**
 * Uji server PRD V6 Fase 2 — tagihan bulanan lengkap — website dan API lewat
 * server PHP lokal di atas DB uji `tests/v6_phase2_db.sh`.
 *
 *   V6F2_DB=test_v6_f2_http_test bash tests/v6_phase2_db.sh
 *   V6F2_DB=test_v6_f2_http_test php tests/v6_phase2_http.php
 *
 *   K-A  hak setiap halaman Fase 2 per akun (server, bukan menu); GET aksi
 *        → 405; navigasi bagian keuangan per peran;
 *   K-B  petugas lewat formulir web: tarif (CSRF wajib, alasan perubahan),
 *        penyesuaian santri, pratinjau → terbitkan (klik ganda tidak
 *        menggandakan; terbit ulang periode sama tidak membuat tagihan kedua);
 *   K-C  pembayaran web Rp40.000 + Rp60.000 atas Rp100.000 → sisa Rp0 tampil;
 *        klik ganda; berlebih ditolak; kuitansi; transfer menunggu →
 *        verifikasi; pembatalan beralasan; revisi dan pembatalan tagihan;
 *        perubahan tarif sesudah terbit tidak mengubah tagihan/kuitansi;
 *   K-D  admin dan wali POST → 403 tanpa mutasi; wali melihat tagihan dan
 *        kuitansi anaknya tanpa referensi/catatan; wali lain → 404 (termasuk
 *        ID ditebak) pada web dan API;
 *   K-E  API: tarif, penyesuaian, pratinjau, penerbitan, pembayaran,
 *        verifikasi, pembatalan, tunggakan, kuitansi; Idempotency-Key, replay,
 *        409; tanpa token 401; no-store;
 *   K-F  dua pembayaran API bersamaan atas sisa yang sama;
 *   K-G  penugasan dinonaktifkan dan relasi wali dicabut saat sesi web/token
 *        lama masih aktif;
 *   K-H  log server tanpa galat PHP/referensi transfer; audit tanpa data uang;
 *        verifier Fase 1 + 2 lulus.
 *
 * Mengubah data uji; bangun ulang DB sebelum mengulang.
 */

$root = dirname(__DIR__);
$db = getenv('V6F2_DB') ?: 'test_v6_f2_http_test';
$port = (int) (getenv('V6F2_PORT') ?: 8995);
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

$log = tempnam(sys_get_temp_dir(), 'v6f2-server-');
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
        $this->jar = tempnam(sys_get_temp_dir(), 'v6f2-jar-');
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
    $r = $api('POST', '/auth/login', null, ['username' => $user, 'password' => 'Sandbox#123', 'device_name' => 'uji-v6-f2']);

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
    'tagihan' => (int) $satu('SELECT COUNT(*) FROM bsa_tagihan'),
    'pembayaran' => (int) $satu('SELECT COUNT(*) FROM bsa_pembayaran'),
    'terbayar' => (int) $satu('SELECT COALESCE(SUM(terbayar), 0) FROM bsa_tagihan'),
    'tarif' => (int) $satu('SELECT COUNT(*) FROM bsa_tarif'),
    'penyesuaian' => (int) $satu('SELECT COUNT(*) FROM bsa_penyesuaian'),
    'pembatalan' => (int) $satu('SELECT COUNT(*) FROM bsa_pembayaran_pembatalan'),
    'riwayat' => (int) $satu('SELECT COUNT(*) FROM bsa_tagihan_riwayat'),
    'idem' => (int) $satu('SELECT COUNT(*) FROM bsa_idempotensi'),
];
$sid = static fn (string $nis): int => (int) $satu("SELECT id FROM santri WHERE nis='$nis'");
$sSmp = $sid('V6S0001');
$sTsanawi = $sid('V6S0002');
$sTanpa = $sid('V6S0003');
$assert(min($sSmp, $sTsanawi, $sTanpa) > 0 && array_sum($jumlah()) === 0, 'Fixture V6 tersedia; tabel tagihan Fase 2 kosong (027 + 028 terpasang)');
$formulir = static function (Klien $kl, string $alamat): array {
    $r = $kl->req($alamat);
    preg_match('/id="form-[a-z]+"[^>]*>.*?name="_csrf" value="([^"]+)".*?name="idempotency_key" value="([^"]+)"/s', $r['body'], $m);

    return ['status' => $r['status'], 'csrf' => $m[1] ?? '', 'kunci' => $m[2] ?? '', 'body' => $r['body']];
};
$tagihanRow = static fn (int $id): array => $conn->query("SELECT total, terbayar, status, versi FROM bsa_tagihan WHERE id = $id")->fetch_assoc() ?? [];
$hariIni = date('Y-m-d');

$akun = ['sbx_admin', 'sbx_v6_bsa_b', 'sbx_v6_bsa_lama', 'sbx_v6_bsa_tsanawi', 'sbx_v6_admin_bsa', 'sbx_v6_wali_smp', 'sbx_v6_wali_tsanawi', 'sbx_pengurus_biasa', 'sbx_guru_biasa', 'sbx_ortu_a'];
$k = [];
foreach ($akun as $u) {
    $k[$u] = $klien($u);
}
$p = $k['sbx_v6_bsa_b'];

// ===================================================================== K-B (tarif dulu agar halaman berisi)
echo PHP_EOL . '=== K-B. Formulir web petugas: tarif, penyesuaian, penerbitan ===' . PHP_EOL;
$f = $formulir($p, '/portal/bsa_tarif.php');
$isiTarif = ['idempotency_key' => $f['kunci'], 'unit' => 'SMP', 'berlaku_mulai' => '2026-10', 'komponen' => [['nama' => 'Syahriah HTTP', 'nominal' => '300.000'], ['nama' => 'Makan HTTP', 'nominal' => '200.000']]];
$r = $p->req('/portal/bsa_tarif.php', $isiTarif);
$assert($r['status'] === 419 && $jumlah()['tarif'] === 0, 'POST tarif tanpa CSRF → 419 tanpa tarif');
$r = $p->req('/portal/bsa_tarif.php', ['_csrf' => $f['csrf']] + $isiTarif);
$r2 = $p->req('/portal/bsa_tarif.php', ['_csrf' => $f['csrf']] + $isiTarif);
$assert(in_array($r['status'], [302, 303], true) && in_array($r2['status'], [302, 303], true) && $jumlah()['tarif'] === 1, 'Tarif SMP tersimpan lewat POST + CSRF; klik ganda (kunci sama) tetap satu tarif');
$f = $formulir($p, '/portal/bsa_tarif.php');
$r = $p->req('/portal/bsa_tarif.php', ['_csrf' => $f['csrf'], 'idempotency_key' => $f['kunci'], 'unit' => 'SMP', 'berlaku_mulai' => '2026-11', 'komponen' => [['nama' => 'Syahriah HTTP', 'nominal' => '350.000']]]);
$assert($r['status'] === 422 && str_contains($teks($r['body']), 'wajib disertai alasan') && $jumlah()['tarif'] === 1
    && str_contains($r['body'], 'value="' . $f['kunci'] . '"'), 'Perubahan tarif tanpa alasan → 422, formulir dirender ulang dengan kunci sama');
$f = $formulir($p, '/portal/bsa_tarif.php');
$p->req('/portal/bsa_tarif.php', ['_csrf' => $f['csrf'], 'idempotency_key' => $f['kunci'], 'unit' => 'Tsanawi', 'berlaku_mulai' => '2026-09', 'komponen' => [['nama' => 'Syahriah HTTP', 'nominal' => '250.000']]]);
$r = $p->req('/portal/bsa_tarif.php');
$assert($r['status'] === 200 && str_contains($teks($r['body']), 'Unit SMP') && str_contains($teks($r['body']), 'Rp500.000') && str_contains($teks($r['body']), 'Rp250.000') && str_contains($r['header'], 'no-store'),
    'Halaman tarif memuat tarif SMP Rp500.000 dan Tsanawi Rp250.000 (no-store)');
$f = $formulir($p, "/portal/bsa_penyesuaian.php?santri_id=$sSmp");
$r = $p->req("/portal/bsa_penyesuaian.php?santri_id=$sSmp", ['_csrf' => $f['csrf'], 'idempotency_key' => $f['kunci'], 'jenis' => 'Potongan', 'nama' => 'Potongan HTTP', 'nominal' => '100.000', 'berlaku_mulai' => '2026-10', 'alasan' => 'Santri berprestasi (uji)']);
$assert(in_array($r['status'], [302, 303], true) && $jumlah()['penyesuaian'] === 1 && str_contains($p->req("/portal/bsa_penyesuaian.php?santri_id=$sSmp")['body'], 'Potongan HTTP'), 'Penyesuaian santri beralasan tersimpan lewat web');
$r = $p->req('/portal/bsa_penerbitan.php?pratinjau=1&periode=2026-10&jatuh_tempo=2026-10-10');
preg_match('/name="pratinjau_hash" value="([a-f0-9]{64})"/', $r['body'], $mh);
preg_match('/id="form-terbit".*?name="_csrf" value="([^"]+)".*?name="idempotency_key" value="([^"]+)"/s', $r['body'], $mf);
$akanTerbit = (int) (preg_match('/Terbitkan (\d+) tagihan/', $r['body'], $mt) ? $mt[1] : 0);
$assert($r['status'] === 200 && isset($mh[1], $mf[2]) && $akanTerbit > 1 && str_contains($teks($r['body']), 'dilewati: Belum ditempatkan di kelas') && $jumlah()['tagihan'] === 0,
    "Pratinjau web: $akanTerbit tagihan akan terbit, santri tanpa kelas dikelompokkan dilewati, belum ada tagihan tertulis");
$terbit = ['_csrf' => $mf[1], 'idempotency_key' => $mf[2], 'periode' => '2026-10', 'jatuh_tempo' => '2026-10-10', 'unit' => '', 'pratinjau_hash' => $mh[1]];
$r = $p->req('/portal/bsa_penerbitan.php', $terbit);
$r2 = $p->req('/portal/bsa_penerbitan.php', $terbit);
$assert(in_array($r['status'], [302, 303], true) && $r['location'] === $r2['location'] && str_contains((string) $r['location'], 'id=') && $jumlah()['tagihan'] === $akanTerbit,
    "Terbitkan + klik ganda (kunci sama) → satu penerbitan, $akanTerbit tagihan");
$r = $p->req(str_replace('http://127.0.0.1:' . $port, '', (string) $r['location']));
$assert($r['status'] === 200 && str_contains($teks($r['body']), 'Hasil per santri'), 'Rincian penerbitan tampil');
$r = $p->req('/portal/bsa_penerbitan.php?pratinjau=1&periode=2026-10&jatuh_tempo=2026-10-10');
$assert(str_contains($teks($r['body']), 'Tidak ada tagihan baru untuk diterbitkan') && !str_contains($r['body'], 'id="form-terbit"'), 'Pratinjau ulang periode sama: tidak ada tagihan baru, tombol terbit tidak ada');
preg_match('/name="_csrf" value="([^"]+)"/', $r['body'], $mc);
$r = $p->req('/portal/bsa_penerbitan.php', ['_csrf' => $mc[1] ?? '', 'idempotency_key' => bin2hex(random_bytes(8)), 'periode' => '2026-10', 'jatuh_tempo' => '2026-10-10', 'unit' => '', 'pratinjau_hash' => $mh[1]]);
$assert(in_array($r['status'], [302, 303], true) && $jumlah()['tagihan'] === $akanTerbit, 'POST terbit ulang periode sama (kunci lain, hash lama) ditolak tanpa tagihan kedua');
$tagSmp = (int) $satu("SELECT id FROM bsa_tagihan WHERE santri_id = $sSmp AND periode = '2026-10'");
$assert((int) $satu("SELECT total FROM bsa_tagihan WHERE id = $tagSmp") === 400000, 'Tagihan santri SMP berpenyesuaian Rp500.000 − Rp100.000 = Rp400.000');

// ===================================================================== K-A
echo PHP_EOL . '=== K-A. Hak setiap halaman Fase 2 (server) ===' . PHP_EOL;
$layar = [
    '/portal/bsa_tarif.php' => ['sbx_v6_bsa_b' => 200, 'sbx_v6_bsa_tsanawi' => 200, 'sbx_admin' => 200, 'sbx_v6_admin_bsa' => 200, 'sbx_v6_wali_smp' => 403, 'sbx_pengurus_biasa' => 403, 'sbx_guru_biasa' => 403],
    '/portal/bsa_penerbitan.php' => ['sbx_v6_bsa_b' => 200, 'sbx_admin' => 200, 'sbx_v6_wali_smp' => 403, 'sbx_pengurus_biasa' => 403],
    "/portal/bsa_penyesuaian.php?santri_id=$sSmp" => ['sbx_v6_bsa_b' => 200, 'sbx_admin' => 200, 'sbx_v6_bsa_tsanawi' => 404, 'sbx_v6_wali_smp' => 403, 'sbx_guru_biasa' => 403],
    "/portal/bsa_tagihan.php?id=$tagSmp" => ['sbx_v6_bsa_b' => 200, 'sbx_admin' => 200, 'sbx_v6_wali_smp' => 200, 'sbx_v6_bsa_tsanawi' => 404, 'sbx_v6_wali_tsanawi' => 404, 'sbx_ortu_a' => 404, 'sbx_pengurus_biasa' => 403],
    "/portal/bsa_bayar.php?tagihan_id=$tagSmp" => ['sbx_v6_bsa_b' => 200, 'sbx_admin' => 403, 'sbx_v6_admin_bsa' => 403, 'sbx_v6_wali_smp' => 403, 'sbx_v6_bsa_tsanawi' => 404],
    '/portal/bsa_tunggakan.php' => ['sbx_v6_bsa_b' => 200, 'sbx_admin' => 200, 'sbx_v6_wali_smp' => 200, 'sbx_pengurus_biasa' => 403, 'sbx_guru_biasa' => 403],
    '/portal/bsa_pembayaran.php' => ['sbx_v6_bsa_b' => 200, 'sbx_admin' => 200, 'sbx_v6_wali_smp' => 200, 'sbx_pengurus_biasa' => 403],
    '/portal/bsa_kuitansi.php?id=999999' => ['sbx_v6_bsa_b' => 404, 'sbx_admin' => 404, 'sbx_v6_wali_smp' => 404],
    '/portal/bsa_tagihan_aksi.php' => ['sbx_v6_bsa_b' => 405, 'sbx_admin' => 405],
];
foreach ($layar as $alamat => $harap) {
    foreach ($harap as $u => $status) {
        $r = $k[$u]->req($alamat);
        $assert($r['status'] === $status, "$alamat $u → $status" . ($r['status'] === $status ? '' : ' (dapat ' . $r['status'] . ')'));
    }
}
$r = $k['sbx_admin']->req("/portal/bsa_tagihan.php?id=$tagSmp");
$assert(!str_contains($r['body'], 'bsa_bayar.php') && !str_contains($r['body'], 'bsa_tagihan_aksi.php') && str_contains($teks($r['body']), 'Hanya baca'), 'Rincian tagihan admin: tanpa tombol pembayaran/koreksi, berlabel hanya baca');
$r = $k['sbx_v6_wali_smp']->req('/portal/bsa.php');
$assert(str_contains($teks($r['body']), 'Belum lunas') && !str_contains($r['body'], 'bsa_tarif.php') && !str_contains($r['body'], 'bsa_penerbitan.php'), 'Navigasi wali: Anak · Belum lunas · Pembayaran (tanpa tarif/penerbitan)');
$r = $p->req('/portal/bsa.php');
$assert(str_contains($r['body'], 'bsa_tarif.php') && str_contains($r['body'], 'bsa_penerbitan.php') && str_contains($r['body'], 'bsa_tunggakan.php'), 'Navigasi petugas: santri, tunggakan, pembayaran, penerbitan, tarif');

// ===================================================================== K-C
echo PHP_EOL . '=== K-C. Pembayaran web, kuitansi, koreksi ===' . PHP_EOL;
$fm = $formulir($p, "/portal/bsa_tagihan_buat.php?santri_id=$sTanpa");
$p->req("/portal/bsa_tagihan_buat.php?santri_id=$sTanpa", ['_csrf' => $fm['csrf'], 'idempotency_key' => $fm['kunci'], 'periode' => '2026-10', 'jatuh_tempo' => '2026-10-10', 'komponen' => [['nama' => 'Syahriah HTTP', 'nominal' => '100.000']]]);
$tag100 = (int) $satu("SELECT id FROM bsa_tagihan WHERE santri_id = $sTanpa AND periode = '2026-10'");
$assert($tag100 > 0, 'Tagihan manual Rp100.000 untuk santri tanpa kelas');
$f = $formulir($p, "/portal/bsa_bayar.php?tagihan_id=$tag100");
$assert($f['status'] === 200 && str_contains($f['body'], 'value="100.000"'), 'Formulir pembayaran terisi sisa Rp100.000');
$bayar40 = ['_csrf' => $f['csrf'], 'idempotency_key' => $f['kunci'], 'nominal' => '40.000', 'sumber' => 'Tunai', 'tanggal_transaksi' => $hariIni];
$r = $p->req("/portal/bsa_bayar.php?tagihan_id=$tag100", $bayar40);
$r2 = $p->req("/portal/bsa_bayar.php?tagihan_id=$tag100", $bayar40);
$assert(in_array($r['status'], [302, 303], true) && in_array($r2['status'], [302, 303], true) && $jumlah()['pembayaran'] === 1 && (int) $tagihanRow($tag100)['terbayar'] === 40000,
    'Pembayaran Rp40.000 + klik ganda (kunci sama) → satu pembayaran');
$f = $formulir($p, "/portal/bsa_bayar.php?tagihan_id=$tag100");
$r = $p->req("/portal/bsa_bayar.php?tagihan_id=$tag100", ['_csrf' => $f['csrf'], 'idempotency_key' => $f['kunci'], 'nominal' => '60.001', 'sumber' => 'Tunai', 'tanggal_transaksi' => $hariIni]);
$assert($r['status'] === 422 && str_contains($teks($r['body']), 'melebihi sisa') && $jumlah()['pembayaran'] === 1, 'Pembayaran Rp60.001 atas sisa Rp60.000 → 422 tanpa pembayaran');
$r = $p->req("/portal/bsa_bayar.php?tagihan_id=$tag100", ['_csrf' => $f['csrf'], 'idempotency_key' => $f['kunci'], 'nominal' => '60.000', 'sumber' => 'Tunai', 'tanggal_transaksi' => $hariIni]);
$rt = $p->req("/portal/bsa_tagihan.php?id=$tag100");
$assert(in_array($r['status'], [302, 303], true) && (int) $tagihanRow($tag100)['terbayar'] === 100000 && str_contains($teks($rt['body']), 'Lunas') && preg_match('/Sisa\s*Rp0/', $teks($rt['body'])) === 1
    && preg_match_all('/bsa_kuitansi\.php\?id=(\d+)/', $rt['body'], $mk) === count($mk[1]) && count(array_unique($mk[1])) === 2
    && str_contains($teks($rt['body']), 'Buka kuitansi'), 'Pembayaran Rp60.000 → sisa Rp0 tampil Lunas; dua kuitansi tertaut; pesan berhasil menautkan kuitansi');
$r = $p->req("/portal/bsa_bayar.php?tagihan_id=$tag100");
$assert(str_contains($teks($r['body']), 'Tagihan ini sudah lunas') && !str_contains($r['body'], 'id="form-bayar"'), 'Formulir pembayaran tagihan lunas tidak tersedia');
$idB40 = (int) $satu("SELECT id FROM bsa_pembayaran WHERE tagihan_id = $tag100 ORDER BY id LIMIT 1");
$r = $p->req("/portal/bsa_kuitansi.php?id=$idB40");
$assert($r['status'] === 200 && str_contains($teks($r['body']), 'Kuitansi pembayaran tagihan bulanan') && str_contains($teks($r['body']), 'Rp 40.000') && str_contains($teks($r['body']), '60.000')
    && str_contains($r['header'], 'no-store'), 'Kuitansi Rp40.000 memuat sisa sesudah Rp60.000 (snapshot), no-store');
// Transfer menunggu → verifikasi; pembatalan beralasan.
$f = $formulir($p, "/portal/bsa_bayar.php?tagihan_id=$tagSmp");
$p->req("/portal/bsa_bayar.php?tagihan_id=$tagSmp", ['_csrf' => $f['csrf'], 'idempotency_key' => $f['kunci'], 'nominal' => '150.000', 'sumber' => 'Transfer', 'referensi' => 'TRF-HTTP-F2-1', 'tanggal_transaksi' => $hariIni]);
$idTr = (int) $satu("SELECT id FROM bsa_pembayaran WHERE tagihan_id = $tagSmp AND status = 'Menunggu'");
$rt = $p->req("/portal/bsa_tagihan.php?id=$tagSmp");
$assert($idTr > 0 && (int) $tagihanRow($tagSmp)['terbayar'] === 0 && str_contains($teks($rt['body']), 'Menunggu verifikasi') && str_contains($rt['body'], 'Verifikasi dana masuk')
    && $p->req("/portal/bsa_kuitansi.php?id=$idTr")['status'] === 404, 'Transfer tanpa centang verifikasi → Menunggu, belum berkuitansi, tombol verifikasi tampil');
preg_match('/name="aksi" value="verifikasi">.*?name="idempotency_key" value="([^"]+)"/s', $rt['body'], $mv);
preg_match('/name="_csrf" value="([^"]+)"/', $rt['body'], $mc);
$r = $k['sbx_v6_bsa_lama']->req('/portal/bsa_tagihan_aksi.php', ['_csrf' => $k['sbx_v6_bsa_lama']->csrf("/portal/bsa_tagihan.php?id=$tagSmp"), 'aksi' => 'verifikasi', 'pembayaran_id' => $idTr, 'tagihan_id' => $tagSmp, 'idempotency_key' => bin2hex(random_bytes(8))]);
$assert(in_array($r['status'], [302, 303], true) && (int) $tagihanRow($tagSmp)['terbayar'] === 150000 && $p->req("/portal/bsa_kuitansi.php?id=$idTr")['status'] === 200,
    'Petugas BSA lain memverifikasi lewat web → Sah, sisa berkurang, kuitansi tersedia');
$rt = $p->req("/portal/bsa_tagihan.php?id=$tag100");
preg_match('/name="aksi" value="batal_bayar"><input type="hidden" name="pembayaran_id" value="' . $idB40 . '">.*?name="idempotency_key" value="([^"]+)"/s', $rt['body'], $mx);
preg_match('/name="_csrf" value="([^"]+)"/', $rt['body'], $mc);
$batal = ['_csrf' => $mc[1] ?? '', 'aksi' => 'batal_bayar', 'pembayaran_id' => $idB40, 'tagihan_id' => $tag100, 'idempotency_key' => $mx[1] ?? '', 'alasan' => 'Salah catat santri (uji)'];
$r = $p->req('/portal/bsa_tagihan_aksi.php', ['alasan' => 'abc'] + $batal);
$assert(in_array($r['status'], [302, 303], true) && $jumlah()['pembatalan'] === 0, 'Pembatalan dengan alasan terlalu pendek ditolak tanpa entri');
$r = $p->req('/portal/bsa_tagihan_aksi.php', $batal);
$r2 = $p->req('/portal/bsa_tagihan_aksi.php', $batal);
$assert(isset($mx[1]) && $jumlah()['pembatalan'] === 1 && (int) $tagihanRow($tag100)['terbayar'] === 60000 && (string) $satu("SELECT status FROM bsa_pembayaran WHERE id = $idB40") === 'Dibatalkan'
    && (int) $satu("SELECT nominal FROM bsa_pembayaran WHERE id = $idB40") === 40000, 'Pembatalan beralasan + klik ganda → satu entri penyeimbang; nominal tetap; sisa naik Rp40.000');
$r = $p->req("/portal/bsa_kuitansi.php?id=$idB40");
$assert($r['status'] === 200 && str_contains($teks($r['body']), 'DIBATALKAN') && str_contains($teks($r['body']), 'Salah catat santri'), 'Kuitansi yang dibatalkan tetap terbuka dengan cap DIBATALKAN dan alasannya (petugas)');
// Revisi dan pembatalan tagihan lewat web.
$tagT = (int) $satu("SELECT id FROM bsa_tagihan WHERE santri_id = $sTsanawi AND periode = '2026-10'");
$pt = $k['sbx_v6_bsa_tsanawi'];
$rt = $pt->req("/portal/bsa_tagihan.php?id=$tagT");
preg_match('/name="aksi" value="revisi">.*?name="versi" value="(\d+)"><input type="hidden" name="idempotency_key" value="([^"]+)"/s', $rt['body'], $mr);
preg_match('/name="_csrf" value="([^"]+)"/', $rt['body'], $mc);
$r = $pt->req('/portal/bsa_tagihan_aksi.php', ['_csrf' => $mc[1] ?? '', 'aksi' => 'revisi', 'tagihan_id' => $tagT, 'versi' => $mr[1] ?? '', 'idempotency_key' => $mr[2] ?? '',
    'jenis' => 'Tambahan', 'nama' => 'Kitab HTTP', 'nominal' => '25.000', 'alasan' => 'Kitab tambahan semester (uji)']);
$rt = $pt->req("/portal/bsa_tagihan.php?id=$tagT");
$assert(($mr[1] ?? '') === '1' && (int) $tagihanRow($tagT)['total'] === 275000 && (int) $tagihanRow($tagT)['versi'] === 2 && str_contains($teks($rt['body']), 'Kitab HTTP') && str_contains($teks($rt['body']), 'Riwayat versi'),
    'Revisi web: total Rp275.000 versi 2, baris tambahan dan riwayat versi tampil');
$r = $pt->req('/portal/bsa_tagihan_aksi.php', ['_csrf' => $mc[1] ?? '', 'aksi' => 'revisi', 'tagihan_id' => $tagT, 'versi' => '1', 'idempotency_key' => bin2hex(random_bytes(8)),
    'jenis' => 'Potongan', 'nominal' => '1.000', 'alasan' => 'Versi lama dari tab lain']);
$assert((int) $tagihanRow($tagT)['versi'] === 2 && str_contains($teks($pt->req("/portal/bsa_tagihan.php?id=$tagT")['body']), 'sudah berubah'), 'Revisi dari versi basi (tab lain) ditolak dengan pesan versi berubah');
$tagLain = (int) $satu("SELECT id FROM bsa_tagihan WHERE periode = '2026-10' AND jenjang = 'Tsanawi' AND santri_id <> $sTsanawi ORDER BY id LIMIT 1");
$rt = $pt->req("/portal/bsa_tagihan.php?id=$tagLain");
preg_match('/name="aksi" value="batal">.*?name="versi" value="(\d+)"><input type="hidden" name="idempotency_key" value="([^"]+)"/s', $rt['body'], $mb);
$r = $pt->req('/portal/bsa_tagihan_aksi.php', ['_csrf' => $mc[1] ?? '', 'aksi' => 'batal', 'tagihan_id' => $tagLain, 'versi' => $mb[1] ?? '', 'idempotency_key' => $mb[2] ?? '', 'alasan' => 'Santri pindah (uji HTTP)']);
$assert($tagihanRow($tagLain)['status'] === 'Dibatalkan' && str_contains($teks($pt->req("/portal/bsa_tagihan.php?id=$tagLain")['body']), 'Dibatalkan'), 'Pembatalan tagihan lewat web: status Dibatalkan, riwayat tetap');
// Perubahan tarif sesudah terbit.
$sebelumT = $conn->query("SELECT id, total, versi FROM bsa_tagihan ORDER BY id")->fetch_all(MYSQLI_ASSOC);
$sebelumK = $conn->query('SELECT pembayaran_id, total_tagihan, terbayar_sebelum, sisa_sesudah FROM bsa_pembayaran_kuitansi ORDER BY pembayaran_id')->fetch_all(MYSQLI_ASSOC);
$kuitansiHtml = $teks($p->req("/portal/bsa_kuitansi.php?id=$idTr")['body']);
$f = $formulir($p, '/portal/bsa_tarif.php');
$p->req('/portal/bsa_tarif.php', ['_csrf' => $f['csrf'], 'idempotency_key' => $f['kunci'], 'unit' => 'SMP', 'berlaku_mulai' => '2026-10', 'komponen' => [['nama' => 'Syahriah HTTP', 'nominal' => '900.000']], 'alasan' => 'Uji perubahan tarif sesudah terbit']);
$assert($jumlah()['tarif'] === 3 && (string) $satu("SELECT status FROM bsa_tarif WHERE jenjang = 'SMP' AND berlaku_mulai = '2026-10' ORDER BY id LIMIT 1") === 'Diganti', 'Tarif SMP Oktober diganti lewat web (beralasan)');
$assert($conn->query("SELECT id, total, versi FROM bsa_tagihan ORDER BY id")->fetch_all(MYSQLI_ASSOC) === $sebelumT
    && $conn->query('SELECT pembayaran_id, total_tagihan, terbayar_sebelum, sisa_sesudah FROM bsa_pembayaran_kuitansi ORDER BY pembayaran_id')->fetch_all(MYSQLI_ASSOC) === $sebelumK
    && preg_replace('/Dicetak [0-9. -]+ WIB/', '', $teks($p->req("/portal/bsa_kuitansi.php?id=$idTr")['body'])) === preg_replace('/Dicetak [0-9. -]+ WIB/', '', $kuitansiHtml),
    'Perubahan tarif sesudah terbit tidak mengubah nominal tagihan, data kuitansi, atau tampilan kuitansi lama');

// ===================================================================== K-D
echo PHP_EOL . '=== K-D. Admin dan wali ===' . PHP_EOL;
$sebelum = $jumlah();
foreach (['sbx_admin', 'sbx_v6_admin_bsa', 'sbx_v6_wali_smp'] as $u) {
    $kl = $k[$u];
    $c = $kl->csrf();
    $s1 = $kl->req("/portal/bsa_bayar.php?tagihan_id=$tagSmp", ['_csrf' => $c, 'idempotency_key' => bin2hex(random_bytes(8)), 'nominal' => '1.000', 'sumber' => 'Tunai', 'tanggal_transaksi' => $hariIni])['status'];
    $s2 = $kl->req('/portal/bsa_tarif.php', ['_csrf' => $c, 'idempotency_key' => bin2hex(random_bytes(8)), 'unit' => 'SMP', 'berlaku_mulai' => '2027-01', 'komponen' => [['nama' => 'X', 'nominal' => '1']], 'alasan' => 'Coba tanpa hak'])['status'];
    $s3 = $kl->req('/portal/bsa_tagihan_aksi.php', ['_csrf' => $c, 'aksi' => 'revisi', 'tagihan_id' => $tagSmp, 'versi' => 1, 'idempotency_key' => bin2hex(random_bytes(8)), 'jenis' => 'Potongan', 'nominal' => 1, 'alasan' => 'Coba tanpa hak'])['status'];
    $s4 = $kl->req('/portal/bsa_tagihan_aksi.php', ['_csrf' => $c, 'aksi' => 'batal_bayar', 'pembayaran_id' => $idTr, 'tagihan_id' => $tagSmp, 'idempotency_key' => bin2hex(random_bytes(8)), 'alasan' => 'Coba tanpa hak'])['status'];
    $s5 = $kl->req('/portal/bsa_penerbitan.php', ['_csrf' => $c, 'idempotency_key' => bin2hex(random_bytes(8)), 'periode' => '2026-12', 'jatuh_tempo' => '2026-12-10', 'pratinjau_hash' => str_repeat('a', 64)])['status'];
    $assert([$s1, $s2, $s3, $s4, $s5] === [403, 403, 403, 403, 403], "$u: POST pembayaran/tarif/revisi/pembatalan/penerbitan → 403 (" . implode(',', [$s1, $s2, $s3, $s4, $s5]) . ')');
}
$assert($jumlah() === $sebelum, 'Penolakan admin/wali tanpa mutasi apa pun');
$w = $k['sbx_v6_wali_smp'];
$r = $w->req("/portal/bsa_tagihan.php?id=$tagSmp");
$assert($r['status'] === 200 && str_contains($teks($r['body']), 'Potongan HTTP') && !str_contains($r['body'], 'TRF-HTTP-F2-1') && !str_contains($teks($r['body']), 'Santri berprestasi') && !str_contains($teks($r['body']), 'Riwayat versi'),
    'Wali membaca tagihan anaknya: baris penyesuaian tampil, tanpa referensi transfer, alasan, atau riwayat internal');
$r = $w->req("/portal/bsa_kuitansi.php?id=$idTr");
$assert($r['status'] === 200 && !str_contains($r['body'], 'TRF-HTTP-F2-1') && str_contains($teks($r['body']), 'Rp 150.000'), 'Wali membuka kuitansi anaknya tanpa referensi transfer');
$w2 = $k['sbx_v6_wali_tsanawi'];
$ids = array_map('intval', array_column($conn->query('SELECT id FROM bsa_pembayaran')->fetch_all(MYSQLI_ASSOC), 'id'));
$bocor = 0;
foreach ($ids as $i) {
    $bocor += $w2->req("/portal/bsa_kuitansi.php?id=$i")['status'] === 404 ? 0 : 1;
}
$assert($bocor === 0 && $w2->req("/portal/bsa_tagihan.php?id=$tagSmp")['status'] === 404, 'Wali Tsanawi: seluruh kuitansi santri lain (ID ditebak) dan tagihan SMP → 404');
$r = $w2->req('/portal/bsa_pembayaran.php');
$assert(!str_contains($r['body'], 'SBX V6 Santri SMP') && !str_contains($r['body'], 'SBX V6 Santri Tanpa Kelas'), 'Daftar pembayaran wali Tsanawi tanpa santri lain');
$r = $w->req('/portal/bsa_tunggakan.php');
$assert(str_contains($r['body'], 'SBX V6 Santri SMP') && !str_contains($r['body'], 'SBX V6 Santri Tsanawi') && str_contains($teks($r['body']), 'Tagihan belum lunas'), 'Halaman "Belum lunas" wali hanya anaknya');

// ===================================================================== K-E
echo PHP_EOL . '=== K-E. API ===' . PHP_EOL;
$tP = $token('sbx_v6_bsa_b');
$tA = $token('sbx_admin');
$tW = $token('sbx_v6_wali_smp');
$tW2 = $token('sbx_v6_wali_tsanawi');
$assert($tP !== '' && $tA !== '' && $tW !== '' && $tW2 !== '', 'Token API petugas, admin, dan dua wali');
$r = $api('GET', '/v6/bsa/tarif', $tP);
$assert($r['status'] === 200 && count($r['json']['data']['tarif']) === 3 && str_contains($r['header'], 'no-store'), 'GET tarif (no-store)');
$assert($api('GET', '/v6/bsa/tarif', $tW)['status'] === 403 && $api('GET', '/v6/bsa/tarif', null)['status'] === 401, 'GET tarif: wali 403, tanpa token 401');
$kunciApi = 'api-tarif-' . bin2hex(random_bytes(5));
$isi = ['unit' => 'Tsanawi', 'berlaku_mulai' => '2026-12', 'komponen' => [['nama' => 'Syahriah API', 'nominal' => 260000]], 'alasan' => 'Tarif Desember lewat API'];
$r1 = $api('POST', '/v6/bsa/tarif', $tP, $isi, ['Idempotency-Key: ' . $kunciApi]);
$r2 = $api('POST', '/v6/bsa/tarif', $tP, $isi, ['Idempotency-Key: ' . $kunciApi]);
$r3 = $api('POST', '/v6/bsa/tarif', $tP, ['komponen' => [['nama' => 'Syahriah API', 'nominal' => 270000]]] + $isi, ['Idempotency-Key: ' . $kunciApi]);
$assert($r1['status'] === 201 && $r2['status'] === 200 && ($r2['json']['data']['replayed'] ?? false) && $r3['status'] === 409 && $jumlah()['tarif'] === 4, 'POST tarif API: 201, replay 200, kunci sama isi beda 409');
$assert($api('POST', '/v6/bsa/tarif', $tA, $isi, ['Idempotency-Key: api-x-' . bin2hex(random_bytes(4))])['status'] === 403, 'POST tarif admin → 403');
$r = $api('POST', '/v6/bsa/penerbitan/pratinjau', $tP, ['periode' => '2026-12', 'jatuh_tempo' => '2026-12-10', 'unit' => 'Tsanawi']);
$hashApi = (string) ($r['json']['data']['hash'] ?? '');
$assert($r['status'] === 200 && $r['json']['data']['jumlah']['Terbit'] > 0 && array_unique(array_column($r['json']['data']['rows'], 'unit')) === ['Tsanawi'], 'Pratinjau API unit Tsanawi Desember');
$kM = 'api-massal-' . bin2hex(random_bytes(5));
$r1 = $api('POST', '/v6/bsa/penerbitan', $tP, ['periode' => '2026-12', 'jatuh_tempo' => '2026-12-10', 'unit' => 'Tsanawi', 'pratinjau_hash' => $hashApi], ['Idempotency-Key: ' . $kM]);
$r2 = $api('POST', '/v6/bsa/penerbitan', $tP, ['periode' => '2026-12', 'jatuh_tempo' => '2026-12-10', 'unit' => 'Tsanawi', 'pratinjau_hash' => $hashApi], ['Idempotency-Key: ' . $kM]);
$idM = (int) ($r1['json']['data']['id'] ?? 0);
$assert($r1['status'] === 201 && $r2['status'] === 200 && ($r2['json']['data']['id'] ?? 0) === $idM && $api('GET', "/v6/bsa/penerbitan/$idM", $tP)['status'] === 200
    && $api('GET', "/v6/bsa/penerbitan/$idM", $tW)['status'] === 403, 'Penerbitan API: 201, replay sama, rincian petugas 200, wali 403');
$tagDes = (int) $satu("SELECT id FROM bsa_tagihan WHERE santri_id = $sTsanawi AND periode = '2026-12'");
$r = $api('GET', "/v6/bsa/tagihan/$tagDes", $tP);
$assert($r['status'] === 200 && $r['json']['data']['total'] === 260000 && array_key_exists('pembayaran', $r['json']['data']) && array_key_exists('komponen', $r['json']['data']), 'Rincian tagihan API = kontrak Fase 1 + pembayaran/penyesuaian/riwayat');
$kB = 'api-bayar-' . bin2hex(random_bytes(5));
$r1 = $api('POST', "/v6/bsa/tagihan/$tagDes/pembayaran", $tP, ['nominal' => 100000, 'sumber' => 'Transfer', 'referensi' => 'TRF-HTTP-F2-API', 'tanggal_transaksi' => $hariIni, 'terverifikasi' => true], ['Idempotency-Key: ' . $kB]);
$r2 = $api('POST', "/v6/bsa/tagihan/$tagDes/pembayaran", $tP, ['nominal' => 100000, 'sumber' => 'Transfer', 'referensi' => 'TRF-HTTP-F2-API', 'tanggal_transaksi' => $hariIni, 'terverifikasi' => true], ['Idempotency-Key: ' . $kB]);
$r3 = $api('POST', "/v6/bsa/tagihan/$tagDes/pembayaran", $tP, ['nominal' => 100001, 'sumber' => 'Transfer', 'referensi' => 'TRF-HTTP-F2-API', 'tanggal_transaksi' => $hariIni, 'terverifikasi' => true], ['Idempotency-Key: ' . $kB]);
$r4 = $api('POST', "/v6/bsa/tagihan/$tagDes/pembayaran", $tP, ['nominal' => 160001, 'sumber' => 'Tunai', 'tanggal_transaksi' => $hariIni], ['Idempotency-Key: api-lebih-' . bin2hex(random_bytes(4))]);
$idApi = (int) ($r1['json']['data']['id'] ?? 0);
$assert($r1['status'] === 201 && ($r1['json']['data']['sisa'] ?? -1) === 160000 && $r2['status'] === 200 && ($r2['json']['data']['id'] ?? 0) === $idApi && $r3['status'] === 409 && $r4['status'] === 422,
    'Pembayaran API: 201 (sisa Rp160.000), replay 200, kunci sama nominal beda 409, berlebih 422');
foreach ([[$tA, 403], [$tW, 403], [$tW2, 403]] as [$tok, $kode]) {
    $assert($api('POST', "/v6/bsa/tagihan/$tagDes/pembayaran", $tok, ['nominal' => 1, 'sumber' => 'Tunai', 'tanggal_transaksi' => $hariIni], ['Idempotency-Key: api-t-' . bin2hex(random_bytes(4))])['status'] === $kode
        && $api('POST', "/v6/bsa/pembayaran/$idApi/batal", $tok, ['alasan' => 'Tanpa hak sama sekali'], ['Idempotency-Key: api-t-' . bin2hex(random_bytes(4))])['status'] === $kode, "Admin/wali POST pembayaran dan pembatalan API → $kode");
}
$r = $api('GET', "/v6/bsa/pembayaran/$idApi", $tW2);
$assert($r['status'] === 200 && !array_key_exists('referensi', $r['json']['data']) && $r['json']['data']['kuitansi']['sisa_sesudah'] === 160000, 'Kuitansi API wali Tsanawi (anaknya): tanpa referensi, snapshot sisa');
$assert($api('GET', "/v6/bsa/pembayaran/$idApi", $tW)['status'] === 404 && $api('GET', "/v6/bsa/tagihan/$tagDes", $tW)['status'] === 404 && $api('GET', "/v6/bsa/pembayaran/$idTr", $tW2)['status'] === 404,
    'Kuitansi/tagihan API anak lain → 404 bagi wali');
$r = $api('GET', '/v6/bsa/pembayaran', $tW);
$assert($r['status'] === 200 && array_unique(array_column(array_column($r['json']['data']['rows'], 'santri'), 'id')) === [$sSmp], 'Daftar pembayaran API wali SMP hanya anaknya');
$r = $api('GET', '/v6/bsa/tunggakan?unit=Tsanawi', $tP);
$assert($r['status'] === 200 && $r['json']['data']['ringkasan']['jumlah'] > 0 && array_filter($r['json']['data']['rows'], static fn ($x) => $x['santri']['unit'] !== 'Tsanawi') === [], 'Tunggakan API tersaring unit Tsanawi');
$assert($api('GET', '/v6/bsa/tunggakan', $token('sbx_pengurus_biasa'))['status'] === 403, 'Tunggakan API pengurus tanpa penugasan → 403');
$r = $api('POST', "/v6/bsa/santri/$sSmp/penyesuaian", $tP, ['jenis' => 'Tambahan', 'nama' => 'Les API', 'nominal' => 15000, 'berlaku_mulai' => '2027-01', 'alasan' => 'Les bahasa (uji API)'], ['Idempotency-Key: api-pj-' . bin2hex(random_bytes(4))]);
$idPj = (int) ($r['json']['data']['id'] ?? 0);
$assert($r['status'] === 201 && $api('GET', "/v6/bsa/santri/$sSmp/penyesuaian", $tP)['status'] === 200 && $api('GET', "/v6/bsa/santri/$sSmp/penyesuaian", $tW)['status'] === 403
    && $api('POST', "/v6/bsa/penyesuaian/$idPj/cabut", $tP, ['alasan' => 'Les batal (uji API)'], ['Idempotency-Key: api-pc-' . bin2hex(random_bytes(4))])['status'] === 200, 'Penyesuaian API: buat 201, baca petugas, wali 403, cabut 200');
$r = $api('POST', "/v6/bsa/tagihan/$tagDes/revisi", $tP, ['versi' => 1, 'jenis' => 'Potongan', 'nominal' => 10000, 'alasan' => 'Koreksi lewat API'], ['Idempotency-Key: api-rv-' . bin2hex(random_bytes(4))]);
$assert($r['status'] === 200 && ($r['json']['data']['versi'] ?? 0) === 2, 'Revisi API → versi 2');

// ===================================================================== K-F
echo PHP_EOL . '=== K-F. Dua pembayaran API bersamaan ===' . PHP_EOL;
$fm = $formulir($p, "/portal/bsa_tagihan_buat.php?santri_id=$sTanpa");
$p->req("/portal/bsa_tagihan_buat.php?santri_id=$sTanpa", ['_csrf' => $fm['csrf'], 'idempotency_key' => $fm['kunci'], 'periode' => '2027-01', 'jatuh_tempo' => '2027-01-10', 'komponen' => [['nama' => 'Syahriah HTTP', 'nominal' => '100.000']]]);
$tagK = (int) $satu("SELECT id FROM bsa_tagihan WHERE santri_id = $sTanpa AND periode = '2027-01'");
$multi = curl_multi_init();
$handles = [];
for ($i = 0; $i < 2; $i++) {
    $h = curl_init($base . "/api/v1/v6/bsa/tagihan/$tagK/pembayaran");
    curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 'Authorization: Bearer ' . $tP, 'Idempotency-Key: serbu-' . $i . '-' . bin2hex(random_bytes(6))],
        CURLOPT_POSTFIELDS => json_encode(['nominal' => 60000, 'sumber' => 'Tunai', 'tanggal_transaksi' => $hariIni])]);
    curl_multi_add_handle($multi, $h);
    $handles[] = $h;
}
do {
    curl_multi_exec($multi, $jalan);
    curl_multi_select($multi);
} while ($jalan > 0);
$kode = array_map(static fn ($h): int => (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE), $handles);
sort($kode);
$assert($kode === [201, 422] && (int) $tagihanRow($tagK)['terbayar'] === 60000, 'Dua pembayaran Rp60.000 bersamaan lewat API atas Rp100.000 → [201, 422], terbayar Rp60.000 (server PHP satu pekerja: bukti kontrak HTTP, bukan konkurensi DB)');

// ===================================================================== K-G
echo PHP_EOL . '=== K-G. Pencabutan saat sesi lama aktif ===' . PHP_EOL;
$f = $formulir($p, "/portal/bsa_bayar.php?tagihan_id=$tagK");
$tugasB = (int) $satu("SELECT a.id FROM bendahara_bulanan_assignments a JOIN users u ON u.pengurus_id = a.pengurus_id WHERE u.username = 'sbx_v6_bsa_b'");
penugasan_service()->nonaktifkan(\App\Penugasan\PenugasanJenis::BENDAHARA_BULANAN, $tugasB, 'Uji cabut HTTP Fase 2', (int) $satu("SELECT id FROM users WHERE username = 'sbx_admin'"));
$sebelum = $jumlah();
$r = $p->req("/portal/bsa_bayar.php?tagihan_id=$tagK", ['_csrf' => $f['csrf'], 'idempotency_key' => $f['kunci'], 'nominal' => '10.000', 'sumber' => 'Tunai', 'tanggal_transaksi' => $hariIni]);
$assert($r['status'] === 403 && $jumlah() === $sebelum, 'Formulir pembayaran terbuka sebelum pencabutan → POST 403 tanpa pembayaran');
$assert($api('POST', "/v6/bsa/tagihan/$tagK/pembayaran", $tP, ['nominal' => 1000, 'sumber' => 'Tunai', 'tanggal_transaksi' => $hariIni], ['Idempotency-Key: api-cabut-' . bin2hex(random_bytes(4))])['status'] === 403
    && $api('GET', '/v6/bsa/tunggakan', $tP)['status'] === 403 && $p->req('/portal/bsa_tunggakan.php')['status'] === 403 && $jumlah() === $sebelum, 'Token/sesi lama sesudah pencabutan: mutasi dan baca → 403');
$relasi = (int) $satu("SELECT sw.id FROM santri_wali sw JOIN users u ON u.wali_id = sw.wali_id WHERE u.username = 'sbx_v6_wali_smp'");
$jalankan("UPDATE santri_wali SET archived_at = NOW() WHERE id = $relasi");
$assert($w->req("/portal/bsa_kuitansi.php?id=$idTr")['status'] === 404 && $w->req("/portal/bsa_tagihan.php?id=$tagSmp")['status'] === 404 && $api('GET', "/v6/bsa/pembayaran/$idTr", $tW)['status'] === 404
    && ($api('GET', '/v6/bsa/pembayaran', $tW)['json']['data']['total'] ?? -1) === 0, 'Relasi wali dicabut: kuitansi/tagihan web dan API → 404, daftar kosong');
$jalankan("UPDATE santri_wali SET archived_at = NULL WHERE id = $relasi");

// ===================================================================== K-H
echo PHP_EOL . '=== K-H. Log, audit, verifier ===' . PHP_EOL;
usleep(300000);
$isiLog = (string) file_get_contents($log);
$assert(!preg_match('/PHP (Warning|Notice|Fatal|Deprecated)|Uncaught/', $isiLog), 'Log server tanpa galat/peringatan PHP');
$assert(!str_contains($isiLog, 'TRF-HTTP-F2'), 'Log server tanpa referensi transfer');
$assert((int) $satu("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'bsa.%' AND (CONCAT(COALESCE(before_json,''), COALESCE(after_json,'')) LIKE '%TRF-HTTP%' OR after_json REGEXP '\"(nominal|total|terbayar|sisa|referensi|alasan|nama)\"')") === 0,
    'audit_logs bsa.* tanpa nominal, sisa, referensi, alasan, atau nama');
$out = [];
exec('DB_NAME=' . escapeshellarg($db) . ' php ' . escapeshellarg($root . '/bin/v6_phase2_verify.php') . ' 2>&1', $out, $kodeVerify);
$assert($kodeVerify === 0, 'Verifier Fase 1 + 2 lulus sesudah uji HTTP');
if ($kodeVerify !== 0) {
    echo implode(PHP_EOL, array_filter($out, static fn ($l) => str_contains($l, '[gagal]'))) . PHP_EOL;
}
@unlink($log);

echo PHP_EOL . 'Lulus: ' . $lulus . ' · Gagal: ' . count($gagal) . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
