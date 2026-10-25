<?php

declare(strict_types=1);

/**
 * Uji server PRD V6 Fase 3 — tabungan lengkap dan pembayaran silang —
 * website dan API lewat server PHP lokal di atas DB uji `tests/v6_phase3_db.sh`.
 *
 *   V6F3_DB=test_v6_f3_http_test bash tests/v6_phase3_db.sh
 *   V6F3_DB=test_v6_f3_http_test php tests/v6_phase3_http.php
 *
 *   M-A  hak setiap halaman Fase 3 per akun (server, bukan menu); GET aksi
 *        → 405; navigasi bagian keuangan per peran;
 *   M-B  formulir web petugas: setoran tunai (CSRF wajib), belanja, klik
 *        ganda; setoran transfer tanpa centang → menunggu, saldo tetap;
 *        verifikasi dan tolak lewat formulir; kuitansi tabungan;
 *   M-C  bayar tagihan dari tabungan lewat web: persetujuan wajib, saldo dan
 *        sisa tampil, klik ganda satu debit, referensi silang di kedua
 *        riwayat dan kuitansi;
 *   M-D  koreksi lewat web: setoran yang sudah dipakai ditolak, pembatalan
 *        pembayaran tabungan mengembalikan saldo, koreksi belanja;
 *   M-E  API: setoran 201/202, verifikasi/tolak, belanja, bayar-tabungan,
 *        kuitansi tabungan, koreksi, mutasi, rekonsiliasi; replay/409;
 *        admin dan wali tidak memutasi; wali tanpa data internal; no-store;
 *   M-F  dua belanja API bersamaan atas saldo yang sama;
 *   M-G  penugasan dinonaktifkan dan relasi wali dicabut saat sesi web/token
 *        lama masih aktif;
 *   M-H  log server tanpa galat PHP/referensi transfer; audit tanpa data uang;
 *        verifier Fase 1–3 lulus.
 *
 * Mengubah data uji; bangun ulang DB sebelum mengulang.
 */

$root = dirname(__DIR__);
$db = getenv('V6F3_DB') ?: 'test_v6_f3_http_test';
$port = (int) (getenv('V6F3_PORT') ?: 8994);
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

$log = tempnam(sys_get_temp_dir(), 'v6f3-server-');
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
        $this->jar = tempnam(sys_get_temp_dir(), 'v6f3-jar-');
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
    $r = $api('POST', '/auth/login', null, ['username' => $user, 'password' => 'Sandbox#123', 'device_name' => 'uji-v6-f3']);

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
    'ledger' => (int) $satu('SELECT COUNT(*) FROM bsa_tabungan_transaksi'),
    'saldo' => (int) $satu('SELECT COALESCE(SUM(saldo), 0) FROM bsa_tabungan_akun'),
    'transfer' => (int) $satu('SELECT COUNT(*) FROM bsa_setoran_transfer'),
    'transfer_status' => (string) $satu("SELECT COALESCE(GROUP_CONCAT(status ORDER BY id), '') FROM bsa_setoran_transfer"),
    'pembayaran' => (int) $satu('SELECT COUNT(*) FROM bsa_pembayaran'),
    'terbayar' => (int) $satu('SELECT COALESCE(SUM(terbayar), 0) FROM bsa_tagihan'),
    'pembatalan' => (int) $satu('SELECT COUNT(*) FROM bsa_pembayaran_pembatalan'),
    'idem' => (int) $satu('SELECT COUNT(*) FROM bsa_idempotensi'),
];
$sid = static fn (string $nis): int => (int) $satu("SELECT id FROM santri WHERE nis='$nis'");
$sSmp = $sid('V6S0001');
$sTsanawi = $sid('V6S0002');
$sTanpa = $sid('V6S0003');
$awal = $jumlah();
$assert(min($sSmp, $sTsanawi, $sTanpa) > 0 && $awal['ledger'] + $awal['transfer'] + $awal['pembayaran'] === 0, 'Fixture V6 tersedia; tabungan dan pembayaran kosong (027–029 terpasang)');
$formulir = static function (Klien $kl, string $alamat): array {
    $r = $kl->req($alamat);
    preg_match('/id="form-[a-z-]+"[^>]*>.*?name="_csrf" value="([^"]+)".*?name="idempotency_key" value="([^"]+)"/s', $r['body'], $m);

    return ['status' => $r['status'], 'csrf' => $m[1] ?? '', 'kunci' => $m[2] ?? '', 'body' => $r['body']];
};
$saldo = static fn (int $s): int => (int) $satu("SELECT COALESCE((SELECT saldo FROM bsa_tabungan_akun WHERE santri_id = $s), 0)");
$tagihanRow = static fn (int $id): array => $conn->query("SELECT total, terbayar, status FROM bsa_tagihan WHERE id = $id")->fetch_assoc() ?? [];
$hariIni = date('Y-m-d');
$kunciBaru = static fn (string $a): string => $a . '-' . bin2hex(random_bytes(6));
$akun = ['sbx_admin', 'sbx_v6_bsa_b', 'sbx_v6_bsa_lama', 'sbx_v6_bsa_tsanawi', 'sbx_v6_admin_bsa', 'sbx_v6_wali_smp', 'sbx_v6_wali_tsanawi', 'sbx_pengurus_biasa', 'sbx_guru_biasa', 'sbx_ortu_a'];
$k = [];
foreach ($akun as $u) {
    $k[$u] = $klien($u);
}
$p = $k['sbx_v6_bsa_b'];
$w = $k['sbx_v6_wali_smp'];
$petugasArr = ['id' => (int) $satu("SELECT id FROM users WHERE username = 'sbx_v6_bsa_b'"), 'roles' => []];
$tagihanBaru = static fn (int $s, string $periode, int $n): int => bsa_service()->terbitkanTagihan($petugasArr, ['idempotency_key' => $kunciBaru('tg'), 'santri_id' => $s, 'periode' => $periode,
    'jatuh_tempo' => $periode . '-10', 'komponen' => [['nama' => 'Syahriah HTTP', 'nominal' => $n]]], 'web')['id'];

// ===================================================================== M-A
echo PHP_EOL . '=== M-A. Hak halaman Fase 3 ===' . PHP_EOL;
$t0 = $tagihanBaru($sSmp, '2026-10', 100000);
$halaman = ['/portal/bsa_tabungan.php', '/portal/bsa_tabungan.php?tab=transfer', '/portal/bsa_rekonsiliasi.php'];
foreach (['sbx_admin' => 200, 'sbx_v6_admin_bsa' => 200, 'sbx_v6_bsa_b' => 200, 'sbx_v6_bsa_tsanawi' => 200, 'sbx_v6_wali_smp' => 403, 'sbx_pengurus_biasa' => 403, 'sbx_guru_biasa' => 403, 'sbx_ortu_a' => 403] as $u => $kode) {
    $hasil = array_map(static fn (string $h): int => $k[$u]->req($h)['status'], $halaman);
    $assert($hasil === array_fill(0, count($halaman), $kode), "$u: halaman tabungan, setoran transfer, rekonsiliasi → $kode (" . implode(',', $hasil) . ')');
}
foreach (['sbx_admin' => 403, 'sbx_v6_admin_bsa' => 403, 'sbx_v6_wali_smp' => 403, 'sbx_pengurus_biasa' => 403, 'sbx_v6_bsa_tsanawi' => 403, 'sbx_v6_bsa_b' => 200] as $u => $kode) {
    $r = $k[$u]->req("/portal/bsa_bayar_tabungan.php?tagihan_id=$t0");
    $assert($r['status'] === $kode || ($u === 'sbx_v6_bsa_tsanawi' && $r['status'] === 404), "$u: formulir bayar dari tabungan → " . $r['status']);
}
$assert($p->req('/portal/bsa_tabungan_aksi.php')['status'] === 405 && $p->req('/portal/bsa_tabungan_aksi.php?aksi=koreksi&transaksi_id=1')['status'] === 405, 'GET bsa_tabungan_aksi.php → 405 (tanpa mutasi lewat GET)');
$navP = $p->req('/portal/bsa_tunggakan.php')['body'];
$navW = $w->req('/portal/bsa_tunggakan.php')['body'];
$assert(str_contains($navP, 'bsa_tabungan.php') && str_contains($navP, 'bsa_rekonsiliasi.php') && !str_contains($navW, 'bsa_tabungan.php') && !str_contains($navW, 'bsa_rekonsiliasi.php'),
    'Navigasi keuangan: petugas melihat Tabungan dan Rekonsiliasi; orang tua tidak');
$assert($jumlah()['ledger'] === 0, 'Pemeriksaan hak tanpa mutasi');

// ===================================================================== M-B
echo PHP_EOL . '=== M-B. Setoran, belanja, setoran transfer lewat web ===' . PHP_EOL;
$f = $formulir($p, "/portal/bsa_transaksi.php?santri_id=$sSmp&jenis=setoran");
$isi = ['idempotency_key' => $f['kunci'], 'nominal' => '100.000', 'metode' => 'Tunai'];
$assert($p->req("/portal/bsa_transaksi.php?santri_id=$sSmp&jenis=setoran", $isi)['status'] === 419 && $jumlah()['ledger'] === 0, 'POST setoran tanpa CSRF → 419 tanpa transaksi');
$assert(str_contains($f['body'], 'name="terverifikasi"') && str_contains($f['body'], 'menunggu verifikasi'), 'Formulir setoran memuat pernyataan "sudah memeriksa dana masuk" untuk transfer');
$r1 = $p->req("/portal/bsa_transaksi.php?santri_id=$sSmp&jenis=setoran", ['_csrf' => $f['csrf']] + $isi);
$r2 = $p->req("/portal/bsa_transaksi.php?santri_id=$sSmp&jenis=setoran", ['_csrf' => $f['csrf']] + $isi);
$assert(in_array($r1['status'], [302, 303], true) && in_array($r2['status'], [302, 303], true) && $jumlah()['ledger'] === 1 && $saldo($sSmp) === 100000, 'Setoran tunai Rp100.000 lewat web; klik ganda tetap satu transaksi');
$f = $formulir($p, "/portal/bsa_transaksi.php?santri_id=$sSmp&jenis=penarikan");
$assert(str_contains($f['body'], 'name="jenis"') && str_contains($f['body'], 'value="Belanja"'), 'Formulir pengeluaran memuat pilihan Penarikan/Belanja');
$r = $p->req("/portal/bsa_transaksi.php?santri_id=$sSmp&jenis=penarikan", ['_csrf' => $f['csrf'], 'idempotency_key' => $f['kunci'], 'jenis' => 'Belanja', 'nominal' => '25.000', 'metode' => 'Tunai', 'tujuan' => 'Kitab HTTP']);
$halSantri = $teks($p->req("/portal/bsa_santri.php?id=$sSmp")['body']);
$assert(in_array($r['status'], [302, 303], true) && $saldo($sSmp) === 75000 && str_contains($halSantri, 'Rp75.000') && str_contains($halSantri, 'Belanja · Tunai') && str_contains($halSantri, 'BSA-TB-'),
    'Belanja Rp25.000 → saldo Rp75.000 tampil; riwayat memuat jenis Belanja dan nomor kuitansi tabungan');
$f = $formulir($p, "/portal/bsa_transaksi.php?santri_id=$sSmp&jenis=penarikan");
$r = $p->req("/portal/bsa_transaksi.php?santri_id=$sSmp&jenis=penarikan", ['_csrf' => $f['csrf'], 'idempotency_key' => $f['kunci'], 'jenis' => 'Belanja', 'nominal' => '75.001', 'metode' => 'Tunai', 'tujuan' => 'Terlalu besar']);
$assert($r['status'] === 422 && str_contains($teks($r['body']), 'Saldo tabungan tidak mencukupi. Belanja tidak dicatat') && str_contains($r['body'], 'value="' . $f['kunci'] . '"') && $saldo($sSmp) === 75000,
    'Belanja melebihi saldo → 422, formulir dirender ulang dengan kunci sama');
$f = $formulir($p, "/portal/bsa_transaksi.php?santri_id=$sTsanawi&jenis=setoran");
$isiTr = ['_csrf' => $f['csrf'], 'idempotency_key' => $f['kunci'], 'nominal' => '40.000', 'metode' => 'Transfer', 'referensi' => 'TRF-HTTP-F3-1'];
$r = $p->req("/portal/bsa_transaksi.php?santri_id=$sTsanawi&jenis=setoran", $isiTr);
$p->req("/portal/bsa_transaksi.php?santri_id=$sTsanawi&jenis=setoran", $isiTr);
$halTs = $p->req("/portal/bsa_santri.php?id=$sTsanawi");
$assert(in_array($r['status'], [302, 303], true) && $jumlah()['transfer'] === 1 && $saldo($sTsanawi) === 0 && str_contains($teks($halTs['body']), 'Setoran menunggu') && str_contains($teks($halTs['body']), 'Menunggu verifikasi'),
    'Setoran transfer tanpa centang → satu setoran menunggu (klik ganda tetap satu), saldo Rp0, tampil "Setoran menunggu"');
$idTr = (int) $satu('SELECT MAX(id) FROM bsa_setoran_transfer');
$csrf = $p->csrf();
$sebelum = $jumlah();
$assert($k['sbx_admin']->req('/portal/bsa_tabungan_aksi.php', ['_csrf' => $k['sbx_admin']->csrf(), 'aksi' => 'verifikasi', 'setoran_transfer_id' => $idTr, 'santri_id' => $sTsanawi, 'idempotency_key' => $kunciBaru('a')])['status'] === 403
    && $w->req('/portal/bsa_tabungan_aksi.php', ['_csrf' => $w->csrf(), 'aksi' => 'verifikasi', 'setoran_transfer_id' => $idTr, 'santri_id' => $sTsanawi, 'idempotency_key' => $kunciBaru('a')])['status'] === 403
    && $jumlah() === $sebelum, 'Admin dan wali POST verifikasi → 403 tanpa mutasi');
$kv = $kunciBaru('web-v');
$r = $p->req('/portal/bsa_tabungan_aksi.php', ['_csrf' => $csrf, 'aksi' => 'verifikasi', 'setoran_transfer_id' => $idTr, 'santri_id' => $sTsanawi, 'idempotency_key' => $kv]);
$p->req('/portal/bsa_tabungan_aksi.php', ['_csrf' => $csrf, 'aksi' => 'verifikasi', 'setoran_transfer_id' => $idTr, 'santri_id' => $sTsanawi, 'idempotency_key' => $kv]);
$assert(in_array($r['status'], [302, 303], true) && $saldo($sTsanawi) === 40000 && $jumlah()['transfer_status'] === 'Sah' && (int) $satu("SELECT COUNT(*) FROM bsa_tabungan_transaksi WHERE santri_id = $sTsanawi") === 1,
    'Verifikasi lewat web → saldo Rp40.000; klik ganda tetap satu kredit');
$f = $formulir($p, "/portal/bsa_transaksi.php?santri_id=$sTsanawi&jenis=setoran");
$p->req("/portal/bsa_transaksi.php?santri_id=$sTsanawi&jenis=setoran", ['_csrf' => $f['csrf'], 'idempotency_key' => $f['kunci'], 'nominal' => '9.000', 'metode' => 'Transfer', 'referensi' => 'TRF-HTTP-F3-2']);
$idTr2 = (int) $satu('SELECT MAX(id) FROM bsa_setoran_transfer');
$r = $p->req('/portal/bsa_tabungan_aksi.php', ['_csrf' => $csrf, 'aksi' => 'tolak', 'setoran_transfer_id' => $idTr2, 'santri_id' => $sTsanawi, 'idempotency_key' => $kunciBaru('t'), 'alasan' => 'abc']);
$assert(in_array($r['status'], [302, 303], true) && $jumlah()['transfer_status'] === 'Sah,Menunggu', 'Tolak dengan alasan pendek → dikembalikan dengan pesan, tanpa perubahan');
$p->req('/portal/bsa_tabungan_aksi.php', ['_csrf' => $csrf, 'aksi' => 'tolak', 'setoran_transfer_id' => $idTr2, 'santri_id' => $sTsanawi, 'idempotency_key' => $kunciBaru('t'), 'alasan' => 'Dana tidak masuk rekening']);
$assert($jumlah()['transfer_status'] === 'Sah,Ditolak' && $saldo($sTsanawi) === 40000 && str_contains($teks($p->req("/portal/bsa_santri.php?id=$sTsanawi")['body']), 'Dana tidak masuk rekening'), 'Tolak beralasan lewat web: status Ditolak, alasan tampil, saldo tetap');
$f = $formulir($p, "/portal/bsa_transaksi.php?santri_id=$sTsanawi&jenis=setoran");
$p->req("/portal/bsa_transaksi.php?santri_id=$sTsanawi&jenis=setoran", ['_csrf' => $f['csrf'], 'idempotency_key' => $f['kunci'], 'nominal' => '10.000', 'metode' => 'Transfer', 'referensi' => 'TRF-HTTP-F3-3', 'terverifikasi' => '1']);
$assert($saldo($sTsanawi) === 50000 && $jumlah()['transfer'] === 2, 'Transfer dengan centang "sudah diperiksa" → sah seketika (saldo Rp50.000), tanpa antrean');
$idS1 = (int) $satu("SELECT MIN(id) FROM bsa_tabungan_transaksi WHERE santri_id = $sSmp");
$kw = $p->req("/portal/bsa_tabungan_kuitansi.php?id=$idS1");
$assert($kw['status'] === 200 && str_contains($teks($kw['body']), 'Kuitansi setoran tabungan') && str_contains($teks($kw['body']), 'Rp 100.000') && str_contains($kw['header'], 'no-store'),
    'Kuitansi setoran tabungan siap cetak (Rp100.000, no-store)');

// ===================================================================== M-C
echo PHP_EOL . '=== M-C. Bayar tagihan dari tabungan lewat web ===' . PHP_EOL;
$f = $formulir($p, "/portal/bsa_bayar_tabungan.php?tagihan_id=$t0");
$assert($f['status'] === 200 && str_contains($teks($f['body']), 'Rp75.000') && str_contains($f['body'], 'name="setuju"'), 'Formulir bayar dari tabungan: saldo Rp75.000 tampil, persetujuan wajib');
$sebelum = $jumlah();
$r = $p->req("/portal/bsa_bayar_tabungan.php?tagihan_id=$t0", ['_csrf' => $f['csrf'], 'idempotency_key' => $f['kunci'], 'nominal' => '50.000']);
$assert($r['status'] === 422 && str_contains($teks($r['body']), 'persetujuan') && $jumlah() === $sebelum, 'Tanpa centang persetujuan → 422 tanpa debit atau pembayaran');
$isiB = ['_csrf' => $f['csrf'], 'idempotency_key' => $f['kunci'], 'nominal' => '50.000', 'setuju' => '1'];
$r1 = $p->req("/portal/bsa_bayar_tabungan.php?tagihan_id=$t0", $isiB);
$r2 = $p->req("/portal/bsa_bayar_tabungan.php?tagihan_id=$t0", $isiB);
$assert(in_array($r1['status'], [302, 303], true) && in_array($r2['status'], [302, 303], true) && $saldo($sSmp) === 25000 && (int) $tagihanRow($t0)['terbayar'] === 50000
    && (int) $satu("SELECT COUNT(*) FROM bsa_pembayaran WHERE tagihan_id = $t0") === 1 && (int) $satu("SELECT COUNT(*) FROM bsa_tabungan_transaksi WHERE jenis = 'PembayaranTagihan'") === 1,
    'Bayar Rp50.000 dari tabungan lewat web → saldo Rp25.000, sisa Rp50.000; klik ganda tetap satu debit dan satu pembayaran');
$idBayar = (int) $satu("SELECT id FROM bsa_pembayaran WHERE tagihan_id = $t0");
$nomorKw = (string) $satu("SELECT nomor_kuitansi FROM bsa_pembayaran WHERE id = $idBayar");
$idDebit = (int) $satu("SELECT tabungan_transaksi_id FROM bsa_pembayaran WHERE id = $idBayar");
$nomorTb = \App\Bsa\BsaService::nomorKuitansiTabungan($idDebit, (string) $satu("SELECT created_at FROM bsa_tabungan_transaksi WHERE id = $idDebit"));
$halTag = $teks($p->req("/portal/bsa_tagihan.php?id=$t0")['body']);
$halS = $teks($p->req("/portal/bsa_santri.php?id=$sSmp")['body']);
$assert(str_contains($halTag, 'Dari tabungan') && str_contains($halTag, 'Debit ' . $nomorTb) && str_contains($halS, 'Kuitansi tagihan ' . $nomorKw) && str_contains($halS, 'Pembayaran tagihan · internal'),
    "Referensi silang tampil di kedua riwayat: tagihan → debit $nomorTb; tabungan → kuitansi $nomorKw");
$kq = $teks($p->req("/portal/bsa_kuitansi.php?id=$idBayar")['body']);
$kt = $teks($p->req("/portal/bsa_tabungan_kuitansi.php?id=$idDebit")['body']);
$assert(str_contains($kq, 'Dari tabungan santri') && str_contains($kq, $nomorTb) && str_contains($kt, 'Bukti pembayaran tagihan dari tabungan') && str_contains($kt, $nomorKw) && str_contains($kt, 'bukan penerimaan uang baru'),
    'Kuitansi tagihan menyebut debit tabungan; bukti debit menyebut kuitansi tagihan dan bukan kas baru');
$wt = $teks($w->req("/portal/bsa_santri.php?id=$sSmp")['body']);
$assert(str_contains($wt, $nomorKw) && !str_contains($wt, 'Koreksi') && $w->req("/portal/bsa_tabungan_kuitansi.php?id=$idDebit")['status'] === 200, 'Wali melihat referensi silang dan bukti debit anaknya, tanpa tombol koreksi');
$f = $formulir($p, "/portal/bsa_bayar_tabungan.php?tagihan_id=$t0");
$r = $p->req("/portal/bsa_bayar_tabungan.php?tagihan_id=$t0", ['_csrf' => $f['csrf'], 'idempotency_key' => $f['kunci'], 'nominal' => '25.001', 'setuju' => '1']);
$assert($r['status'] === 422 && str_contains($teks($r['body']), 'Saldo tabungan tidak mencukupi') && $saldo($sSmp) === 25000, 'Melebihi saldo → 422 "Saldo tabungan tidak mencukupi"');

// ===================================================================== M-D
echo PHP_EOL . '=== M-D. Koreksi lewat web ===' . PHP_EOL;
$sebelum = $jumlah();
$r = $p->req('/portal/bsa_tabungan_aksi.php', ['_csrf' => $csrf, 'aksi' => 'koreksi', 'transaksi_id' => $idS1, 'santri_id' => $sSmp, 'idempotency_key' => $kunciBaru('k'), 'alasan' => 'Setoran salah santri']);
$assert(in_array($r['status'], [302, 303], true) && $jumlah() === $sebelum && str_contains($teks($p->req("/portal/bsa_santri.php?id=$sSmp")['body']), 'Saldo tabungan tidak cukup untuk membalik setoran'),
    'Koreksi setoran yang sudah dipakai membayar tagihan → ditolak dengan pesan, tanpa mutasi');
$csrfTag = $p->csrf("/portal/bsa_tagihan.php?id=$t0");
$r = $p->req('/portal/bsa_tagihan_aksi.php', ['_csrf' => $csrfTag, 'aksi' => 'batal_bayar', 'pembayaran_id' => $idBayar, 'tagihan_id' => $t0, 'idempotency_key' => $kunciBaru('bb'), 'alasan' => 'Dibayar tunai oleh wali']);
$assert(in_array($r['status'], [302, 303], true) && $saldo($sSmp) === 75000 && (int) $tagihanRow($t0)['terbayar'] === 0 && str_contains($teks($p->req("/portal/bsa_tagihan.php?id=$t0")['body']), 'saldo dikembalikan'),
    'Pembatalan pembayaran dari tabungan lewat web → saldo Rp75.000 dan sisa Rp100.000 kembali; tertulis "saldo dikembalikan"');
$idBelanja = (int) $satu("SELECT id FROM bsa_tabungan_transaksi WHERE santri_id = $sSmp AND jenis = 'Belanja'");
$p->req('/portal/bsa_tabungan_aksi.php', ['_csrf' => $csrf, 'aksi' => 'koreksi', 'transaksi_id' => $idBelanja, 'santri_id' => $sSmp, 'idempotency_key' => $kunciBaru('k'), 'alasan' => 'Kitab dikembalikan ke toko']);
$assert($saldo($sSmp) === 100000 && (int) $satu("SELECT COUNT(*) FROM bsa_tabungan_transaksi WHERE pembalik_dari_id = $idBelanja AND arah = 'Masuk'") === 1
    && str_contains($teks($p->req("/portal/bsa_santri.php?id=$sSmp")['body']), 'Sudah dikoreksi'), 'Koreksi belanja lewat web → pembalik masuk, saldo Rp100.000, cap "Sudah dikoreksi"');

// ===================================================================== M-E
echo PHP_EOL . '=== M-E. API Fase 3 ===' . PHP_EOL;
$tP = $token('sbx_v6_bsa_b');
$tA = $token('sbx_admin');
$tW = $token('sbx_v6_wali_smp');
$tW2 = $token('sbx_v6_wali_tsanawi');
$assert($api('GET', '/v6/bsa/tabungan', null)['status'] === 401, 'API tanpa token → 401');
$kS = $kunciBaru('api-st');
$a1 = $api('POST', "/v6/bsa/santri/$sTanpa/setoran", $tP, ['nominal' => 100000, 'metode' => 'Tunai'], ['Idempotency-Key: ' . $kS]);
$a2 = $api('POST', "/v6/bsa/santri/$sTanpa/setoran", $tP, ['nominal' => 100000, 'metode' => 'Tunai'], ['Idempotency-Key: ' . $kS]);
$assert($a1['status'] === 201 && ($a1['json']['data']['status_setoran'] ?? '') === 'Sah' && $a2['status'] === 200 && ($a2['json']['data']['replayed'] ?? false) === true && str_contains($a1['header'], 'no-store'),
    'Setoran tunai API → 201 Sah, replay 200, no-store');
$kT = $kunciBaru('api-tr');
$a3 = $api('POST', "/v6/bsa/santri/$sTanpa/setoran", $tP, ['nominal' => 20000, 'metode' => 'Transfer', 'referensi' => 'TRF-HTTP-F3-API'], ['Idempotency-Key: ' . $kT]);
$idTrApi = (int) ($a3['json']['data']['setoran_transfer_id'] ?? 0);
$assert($a3['status'] === 202 && ($a3['json']['data']['status_setoran'] ?? '') === 'Menunggu' && ($a3['json']['data']['saldo_sesudah'] ?? -1) === 100000
    && $api('POST', "/v6/bsa/santri/$sTanpa/setoran", $tP, ['nominal' => 20000, 'metode' => 'Transfer', 'referensi' => 'TRF-HTTP-F3-API', 'terverifikasi' => true], ['Idempotency-Key: ' . $kT])['status'] === 409,
    'Setoran transfer API tanpa terverifikasi → 202 Menunggu (saldo tetap); kunci sama dengan terverifikasi → 409');
$l = $api('GET', '/v6/bsa/setoran-transfer', $tP);
$assert($l['status'] === 200 && in_array($idTrApi, array_column($l['json']['data']['rows'], 'id'), true) && $api('GET', '/v6/bsa/setoran-transfer', $tW)['status'] === 403, 'Daftar setoran transfer menunggu API (petugas 200; wali 403)');
foreach ([[$tA, 403, 'admin'], [$tW, 403, 'wali']] as [$tok, $kode, $label]) {
    $assert($api('POST', "/v6/bsa/setoran-transfer/$idTrApi/verifikasi", $tok, [], ['Idempotency-Key: ' . $kunciBaru('x')])['status'] === $kode
        && $api('POST', "/v6/bsa/santri/$sTanpa/penarikan", $tok, ['jenis' => 'Belanja', 'nominal' => 1, 'metode' => 'Tunai', 'tujuan' => 'Uji'], ['Idempotency-Key: ' . $kunciBaru('x')])['status'] === $kode
        && $api('POST', "/v6/bsa/tagihan/$t0/bayar-tabungan", $tok, ['nominal' => 1, 'setuju' => true], ['Idempotency-Key: ' . $kunciBaru('x')])['status'] === $kode
        && $api('POST', "/v6/bsa/tabungan/$idS1/koreksi", $tok, ['alasan' => 'Tanpa hak'], ['Idempotency-Key: ' . $kunciBaru('x')])['status'] === $kode, "API mutasi Fase 3 oleh $label → $kode");
}
$v = $api('POST', "/v6/bsa/setoran-transfer/$idTrApi/verifikasi", $tP, [], ['Idempotency-Key: ' . $kunciBaru('api-v')]);
$assert($v['status'] === 200 && ($v['json']['data']['status_setoran'] ?? '') === 'Sah' && ($v['json']['data']['saldo_sesudah'] ?? 0) === 120000
    && $api('POST', "/v6/bsa/setoran-transfer/$idTrApi/tolak", $tP, ['alasan' => 'Sudah sah sebelumnya'], ['Idempotency-Key: ' . $kunciBaru('x')])['status'] === 409, 'Verifikasi API → 200 Sah saldo Rp120.000; tolak sesudahnya → 409');
$b = $api('POST', "/v6/bsa/santri/$sTanpa/penarikan", $tP, ['jenis' => 'Belanja', 'nominal' => 20000, 'metode' => 'Tunai', 'tujuan' => 'Seragam API'], ['Idempotency-Key: ' . $kunciBaru('api-b')]);
$assert($b['status'] === 201 && ($b['json']['data']['jenis'] ?? '') === 'Belanja' && ($b['json']['data']['saldo_sesudah'] ?? 0) === 100000, 'Belanja API → 201, saldo Rp100.000');
$tK = $tagihanBaru($sTanpa, '2026-10', 60000);
$kBt = $kunciBaru('api-bt');
$c1 = $api('POST', "/v6/bsa/tagihan/$tK/bayar-tabungan", $tP, ['nominal' => 50000, 'setuju' => true], ['Idempotency-Key: ' . $kBt]);
$c2 = $api('POST', "/v6/bsa/tagihan/$tK/bayar-tabungan", $tP, ['nominal' => 50000, 'setuju' => true], ['Idempotency-Key: ' . $kBt]);
$c3 = $api('POST', "/v6/bsa/tagihan/$tK/bayar-tabungan", $tP, ['nominal' => 40000, 'setuju' => true], ['Idempotency-Key: ' . $kBt]);
$c4 = $api('POST', "/v6/bsa/tagihan/$tK/bayar-tabungan", $tP, ['nominal' => 10000], ['Idempotency-Key: ' . $kunciBaru('x')]);
$c5 = $api('POST', "/v6/bsa/tagihan/$tK/bayar-tabungan", $tP, ['nominal' => 10001, 'setuju' => true], ['Idempotency-Key: ' . $kunciBaru('x')]);
$assert($c1['status'] === 201 && ($c1['json']['data']['saldo'] ?? 0) === 50000 && ($c1['json']['data']['sisa'] ?? 0) === 10000 && $c2['status'] === 200 && ($c2['json']['data']['id'] ?? 0) === ($c1['json']['data']['id'] ?? -1)
    && $c3['status'] === 409 && $c4['status'] === 422 && $c5['status'] === 422, 'Bayar-tabungan API: 201 (saldo Rp50.000, sisa Rp10.000), replay 200, kunci sama nominal beda 409, tanpa setuju 422, melebihi sisa 422');
$idDebitApi = (int) ($c1['json']['data']['transaksi_id'] ?? 0);
$g = $api('GET', "/v6/bsa/tabungan/$idDebitApi", $tP);
$assert($g['status'] === 200 && $g['json']['data']['jenis'] === 'PembayaranTagihan' && $g['json']['data']['pembayaran']['id'] === ($c1['json']['data']['id'] ?? -1) && $g['json']['data']['saldo_sebelum'] === 100000,
    'GET tabungan/{id}: debit menunjuk pembayaran tagihan, saldo sebelum Rp100.000');
$rt = $api('GET', "/v6/bsa/tagihan/$tK", $tP);
$assert(($rt['json']['data']['pembayaran'][0]['tabungan']['transaksi_id'] ?? 0) === $idDebitApi, 'GET tagihan/{id}: pembayaran menunjuk debit tabungan yang sama');
$kr = $api('POST', "/v6/bsa/tabungan/$idDebitApi/koreksi", $tP, ['alasan' => 'Koreksi debit langsung'], ['Idempotency-Key: ' . $kunciBaru('x')]);
$assert($kr['status'] === 422, 'Koreksi debit pembayaran tagihan lewat API → 422 (gunakan pembatalan pembayaran)');
$m = $api('GET', '/v6/bsa/tabungan?jenis=PembayaranTagihan', $tA);
$assert($m['status'] === 200 && count($m['json']['data']['rows']) >= 1 && array_unique(array_column($m['json']['data']['rows'], 'jenis')) === ['PembayaranTagihan'] && $api('GET', '/v6/bsa/tabungan', $tW)['status'] === 403,
    'Mutasi tabungan API tersaring jenis (admin 200); wali 403');
$rk = $api('GET', '/v6/bsa/rekonsiliasi', $tA);
$assert($rk['status'] === 200 && $rk['json']['data']['internal']['selisih'] === 0 && $rk['json']['data']['saldo']['selisih_akun'] === 0 && $api('GET', '/v6/bsa/rekonsiliasi', $tW)['status'] === 403
    && $api('GET', '/v6/bsa/rekonsiliasi?tanggal=2026-02-30', $tA)['status'] === 422, 'Rekonsiliasi API admin: selisih internal dan saldo Rp0; wali 403; tanggal tidak valid 422');
$ws = $api('GET', "/v6/bsa/santri/$sSmp", $tW);
$assert($ws['status'] === 200 && $ws['json']['data']['setoran_transfer'] === [] && array_filter($ws['json']['data']['tabungan']['rows'], static fn ($x) => array_key_exists('referensi', $x) || array_key_exists('alasan', $x)) === [],
    'Rincian santri API wali: tanpa setoran transfer, referensi, atau alasan');
$assert($api('GET', "/v6/bsa/tabungan/$idDebitApi", $tW)['status'] === 404 && $api('GET', "/v6/bsa/tabungan/$idS1", $tW2)['status'] === 404 && $api('GET', "/v6/bsa/tabungan/$idS1", $tW)['status'] === 200,
    'Kuitansi tabungan API: anak lain → 404; anak sendiri → 200');
$ps = $api('GET', "/v6/bsa/santri/$sTsanawi", $tP);
$assert(count($ps['json']['data']['setoran_transfer'] ?? []) === 2, 'Rincian santri API petugas memuat riwayat setoran transfer');

// ===================================================================== M-F
echo PHP_EOL . '=== M-F. Dua belanja API bersamaan ===' . PHP_EOL;
$multi = curl_multi_init();
$handles = [];
for ($i = 0; $i < 2; $i++) {
    $h = curl_init($base . "/api/v1/v6/bsa/santri/$sTanpa/penarikan");
    curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 'Authorization: Bearer ' . $tP, 'Idempotency-Key: serbu-' . $i . '-' . bin2hex(random_bytes(6))],
        CURLOPT_POSTFIELDS => json_encode(['jenis' => 'Belanja', 'nominal' => 30000, 'metode' => 'Tunai', 'tujuan' => 'Serbu'])]);
    curl_multi_add_handle($multi, $h);
    $handles[] = $h;
}
do {
    curl_multi_exec($multi, $jalan);
    curl_multi_select($multi);
} while ($jalan > 0);
$kode = array_map(static fn ($h): int => (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE), $handles);
sort($kode);
$assert($kode === [201, 422] && $saldo($sTanpa) === 20000, 'Dua belanja Rp30.000 bersamaan lewat API atas saldo Rp50.000 → [201, 422], saldo Rp20.000 (server PHP satu pekerja: bukti kontrak HTTP, bukan konkurensi DB)');

// ===================================================================== M-G
echo PHP_EOL . '=== M-G. Pencabutan saat sesi lama aktif ===' . PHP_EOL;
$f = $formulir($p, "/portal/bsa_bayar_tabungan.php?tagihan_id=$t0");
$tugasB = (int) $satu("SELECT a.id FROM bendahara_bulanan_assignments a JOIN users u ON u.pengurus_id = a.pengurus_id WHERE u.username = 'sbx_v6_bsa_b'");
penugasan_service()->nonaktifkan(\App\Penugasan\PenugasanJenis::BENDAHARA_BULANAN, $tugasB, 'Uji cabut HTTP Fase 3', (int) $satu("SELECT id FROM users WHERE username = 'sbx_admin'"));
$sebelum = $jumlah();
$r = $p->req("/portal/bsa_bayar_tabungan.php?tagihan_id=$t0", ['_csrf' => $f['csrf'], 'idempotency_key' => $f['kunci'], 'nominal' => '10.000', 'setuju' => '1']);
$assert($r['status'] === 403 && $jumlah() === $sebelum, 'Formulir bayar dari tabungan terbuka sebelum pencabutan → POST 403 tanpa debit');
$assert($api('POST', "/v6/bsa/santri/$sTanpa/penarikan", $tP, ['jenis' => 'Belanja', 'nominal' => 1000, 'metode' => 'Tunai', 'tujuan' => 'Sesudah dicabut'], ['Idempotency-Key: ' . $kunciBaru('x')])['status'] === 403
    && $api('GET', '/v6/bsa/rekonsiliasi', $tP)['status'] === 403 && $p->req('/portal/bsa_tabungan.php')['status'] === 403 && $jumlah() === $sebelum, 'Token/sesi lama sesudah pencabutan: belanja, rekonsiliasi, daftar tabungan → 403');
$relasi = (int) $satu("SELECT sw.id FROM santri_wali sw JOIN users u ON u.wali_id = sw.wali_id WHERE u.username = 'sbx_v6_wali_smp'");
$jalankan("UPDATE santri_wali SET archived_at = NOW() WHERE id = $relasi");
$assert($w->req("/portal/bsa_tabungan_kuitansi.php?id=$idS1")['status'] === 404 && $api('GET', "/v6/bsa/tabungan/$idS1", $tW)['status'] === 404, 'Relasi wali dicabut: kuitansi tabungan web dan API → 404');
$jalankan("UPDATE santri_wali SET archived_at = NULL WHERE id = $relasi");

// ===================================================================== M-H
echo PHP_EOL . '=== M-H. Log, audit, verifier ===' . PHP_EOL;
usleep(300000);
$isiLog = (string) file_get_contents($log);
$assert(!preg_match('/PHP (Warning|Notice|Fatal|Deprecated)|Uncaught/', $isiLog), 'Log server tanpa galat/peringatan PHP');
$assert(!str_contains($isiLog, 'TRF-HTTP-F3'), 'Log server tanpa referensi transfer');
$assert((int) $satu("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'bsa.%' AND (CONCAT(COALESCE(before_json,''), COALESCE(after_json,'')) LIKE '%TRF-HTTP%' OR after_json REGEXP '\"(nominal|saldo|saldo_sesudah|total|terbayar|sisa|referensi|alasan|tujuan|nama)\"')") === 0,
    'audit_logs bsa.* tanpa nominal, saldo, referensi, alasan, tujuan, atau nama');
$out = [];
exec('DB_NAME=' . escapeshellarg($db) . ' php ' . escapeshellarg($root . '/bin/v6_phase3_verify.php') . ' 2>&1', $out, $kodeVerify);
$assert($kodeVerify === 0, 'Verifier Fase 1–3 lulus sesudah uji HTTP');
if ($kodeVerify !== 0) {
    echo implode(PHP_EOL, array_filter($out, static fn ($l) => str_contains($l, '[gagal]'))) . PHP_EOL;
}
@unlink($log);

echo PHP_EOL . 'Lulus: ' . $lulus . ' · Gagal: ' . count($gagal) . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
