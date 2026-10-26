<?php

declare(strict_types=1);

/**
 * Uji server PRD V6 Fase 5 — laporan dan ekspor keuangan — website dan API
 * lewat server PHP lokal di atas DB uji `tests/v6_phase5_db.sh`.
 *
 *   V6F5_DB=test_v6_f5_http_test bash tests/v6_phase5_db.sh
 *   V6F5_DB=test_v6_f5_http_test php tests/v6_phase5_http.php
 *
 *   H-A  hak halaman laporan dan ekspor per akun (server, bukan menu); tanpa
 *        masuk dialihkan; navigasi "Laporan" per peran;
 *   H-B  isi halaman: oracle angka bulan ini, kas terpisah dari pembayaran
 *        internal, jejak koreksi beralasan, R-10 hanya admin; wali tanpa kas,
 *        alasan, referensi, transfer menunggu, atau santri lain;
 *   H-C  saringan: periode tidak sah → pesan; santri/unit di luar hak 404;
 *        laporan per santri;
 *   H-D  ekspor web: CSV berkepala unduhan, no-store, nosniff; per peran/anak;
 *        wali tanpa ekspor koreksi; audit ekspor;
 *   H-E  API laporan dan ekspor: JSON = layanan, 401/403/404/422, no-store,
 *        CSV; wali tanpa bagian internal;
 *   H-F  sesi web dan token API lama sesudah penugasan/relasi dicabut;
 *   H-G  log server tanpa galat PHP/referensi; verifier rilis lulus.
 *
 * Mengubah data uji; bangun ulang DB sebelum mengulang.
 */

$root = dirname(__DIR__);
$db = getenv('V6F5_DB') ?: 'test_v6_f5_http_test';
$port = (int) (getenv('V6F5_PORT') ?: 8988);
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

$log = tempnam(sys_get_temp_dir(), 'v6f5-server-');
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
        $this->jar = tempnam(sys_get_temp_dir(), 'v6f5-jar-');
    }

    /** @return array{status:int, body:string, location:?string, type:string, header:string} */
    public function req(string $path, ?array $post = null, array $headers = [], string $method = ''): array
    {
        $h = curl_init($this->base . $path);
        curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $headers]);
        if ($post !== null) {
            curl_setopt($h, CURLOPT_POST, true);
            curl_setopt($h, CURLOPT_POSTFIELDS, http_build_query($post));
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

    public function login(string $user): void
    {
        $r = $this->req('/portal/index.php');
        preg_match('/name="_csrf" value="([^"]+)"/', $r['body'], $m);
        $this->req('/admin/cek_login.php', ['_csrf' => $m[1] ?? '', 'username' => $user, 'password' => 'Sandbox#123']);
    }
}

$base = 'http://127.0.0.1:' . $port;
$klien = static function (string $user) use ($base): Klien {
    $k = new Klien($base);
    $k->login($user);

    return $k;
};
$api = static function (string $path, ?string $token, string $method = 'GET') use ($base): array {
    $k = new Klien($base);
    $r = $k->req('/api/v1' . $path, null, array_merge(['Accept: application/json'], $token === null ? [] : ['Authorization: Bearer ' . $token]), $method === 'GET' ? '' : $method);
    $r['json'] = json_decode($r['body'], true);

    return $r;
};
$token = static function (string $user) use ($base): string {
    $h = curl_init($base . '/api/v1/auth/login');
    curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['username' => $user, 'password' => 'Sandbox#123', 'device_name' => 'uji-v6-f5'])]);
    $j = json_decode((string) curl_exec($h), true);
    curl_close($h);

    return (string) ($j['data']['token'] ?? '');
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
$sid = static fn (string $nis): int => (int) $satu("SELECT id FROM santri WHERE nis='$nis'");
$uid = static fn (string $u): int => (int) $satu("SELECT id FROM users WHERE username='$u'");
$s1 = $sid('V6S0001');
$s2 = $sid('V6S0002');
$s4 = $sid('V6S0004');
$kunci = static fn (string $a): string => $a . '-' . bin2hex(random_bytes(6));
$B = date('Y-m');
$akhirB = (new DateTimeImmutable($B . '-01'))->modify('+1 month -1 day')->format('Y-m-d');

// Fixture lewat layanan (bulan ini): SMP setoran 100.000, belanja 25.000 dikoreksi, tagihan 100.000 dibayar
// tabungan 50.000 + transfer menunggu 10.000; Tsanawi setoran 70.000 + tagihan 90.000 lunas tunai;
// santri keluar setoran 12.000 lalu dinonaktifkan (R-10).
$petugas = ['id' => $uid('sbx_v6_bsa_b'), 'roles' => []];
$tab = tabungan_service();
$tag = tagihan_service();
$assert((int) $satu('SELECT COUNT(*) FROM bsa_tabungan_transaksi') === 0, 'DB uji V6 Fase 5 kosong dari transaksi (027–029 terpasang)');
$tab->setoran($petugas, $s1, ['idempotency_key' => $kunci('s'), 'nominal' => 100000, 'metode' => 'Tunai'], 'web');
$bl = $tab->penarikan($petugas, $s1, ['idempotency_key' => $kunci('b'), 'jenis' => 'Belanja', 'nominal' => 25000, 'metode' => 'Tunai', 'tujuan' => 'Kitab HTTP F5'], 'web');
$tab->koreksi($petugas, (int) $bl['id'], ['idempotency_key' => $kunci('k'), 'alasan' => 'Uji HTTP F5 alasan koreksi'], 'web');
$t1 = bsa_service()->terbitkanTagihan($petugas, ['idempotency_key' => $kunci('t'), 'santri_id' => $s1, 'periode' => $B, 'jatuh_tempo' => $akhirB,
    'komponen' => [['nama' => 'Syahriah HTTP F5', 'nominal' => 100000]]], 'web')['id'];
$tag->bayarDariTabungan($petugas, $t1, ['idempotency_key' => $kunci('bt'), 'nominal' => 50000, 'setuju' => '1'], 'web');
$tag->catatPembayaran($petugas, $t1, ['idempotency_key' => $kunci('tr'), 'nominal' => 10000, 'sumber' => 'Transfer', 'referensi' => 'TRF-HTTP-F5', 'tanggal_transaksi' => date('Y-m-d')], 'web');
$tab->setoran($petugas, $s2, ['idempotency_key' => $kunci('s'), 'nominal' => 70000, 'metode' => 'Tunai'], 'web');
$t2 = bsa_service()->terbitkanTagihan($petugas, ['idempotency_key' => $kunci('t'), 'santri_id' => $s2, 'periode' => $B, 'jatuh_tempo' => $akhirB,
    'komponen' => [['nama' => 'Syahriah HTTP F5', 'nominal' => 90000]]], 'web')['id'];
$tag->catatPembayaran($petugas, $t2, ['idempotency_key' => $kunci('tn'), 'nominal' => 90000, 'sumber' => 'Tunai', 'tanggal_transaksi' => date('Y-m-d')], 'web');
$jalankan("UPDATE santri SET is_active = 1 WHERE id = $s4");
$tab->setoran($petugas, $s4, ['idempotency_key' => $kunci('s'), 'nominal' => 12000, 'metode' => 'Tunai'], 'web');
$jalankan("UPDATE santri SET is_active = 0 WHERE id = $s4");
$namaS2 = (string) $satu("SELECT nama_santri FROM santri WHERE id = $s2");
$namaS1 = (string) $satu("SELECT nama_santri FROM santri WHERE id = $s1");
$namaS4 = (string) $satu("SELECT nama_santri FROM santri WHERE id = $s4");

$akun = ['sbx_admin', 'sbx_v6_admin_bsa', 'sbx_v6_bsa_b', 'sbx_v6_bsa_tsanawi', 'sbx_v6_wali_smp', 'sbx_v6_wali_dua', 'sbx_ortu_a', 'sbx_pengurus_biasa', 'sbx_guru_biasa', 'sbx_v5_petugas_b'];
$k = [];
foreach ($akun as $u) {
    $k[$u] = $klien($u);
}

// ===================================================================== H-A
echo PHP_EOL . '=== H-A. Hak halaman laporan dan ekspor ===' . PHP_EOL;
foreach (['sbx_admin' => 200, 'sbx_v6_admin_bsa' => 200, 'sbx_v6_bsa_b' => 200, 'sbx_v6_bsa_tsanawi' => 200, 'sbx_v6_wali_smp' => 200, 'sbx_v6_wali_dua' => 200, 'sbx_ortu_a' => 200,
    'sbx_pengurus_biasa' => 403, 'sbx_guru_biasa' => 403, 'sbx_v5_petugas_b' => 403] as $u => $kode) {
    $hasil = [$k[$u]->req('/portal/bsa_laporan.php')['status'], $k[$u]->req('/portal/bsa_laporan_ekspor.php?jenis=santri')['status']];
    $assert($hasil === [$kode, $kode], "$u: laporan dan ekspor per santri → $kode (" . implode(',', $hasil) . ')');
}
$tamu = new Klien($base);
$r = $tamu->req('/portal/bsa_laporan.php');
$r2 = $tamu->req('/portal/bsa_laporan_ekspor.php?jenis=santri');
$assert(in_array($r['status'], [302, 303], true) && in_array($r2['status'], [302, 303], true) && !str_contains($r2['body'], 'V6S0001'), 'Tanpa masuk: laporan dan ekspor dialihkan ke halaman masuk tanpa data');
$navP = $k['sbx_v6_bsa_b']->req('/portal/bsa.php')['body'];
$navW = $k['sbx_v6_wali_smp']->req('/portal/bsa.php')['body'];
$assert(str_contains($navP, 'bsa_laporan.php') && str_contains($navW, 'bsa_laporan.php') && str_contains($navP, 'bsa_rekonsiliasi.php') && !str_contains($navW, 'bsa_rekonsiliasi.php'),
    'Navigasi keuangan memuat "Laporan" untuk petugas dan orang tua (rekonsiliasi tetap internal)');

// ===================================================================== H-B
echo PHP_EOL . '=== H-B. Isi halaman per peran ===' . PHP_EOL;
$hA = $teks($k['sbx_admin']->req('/portal/bsa_laporan.php')['body']);
$assert(str_contains($hA, 'Tagihan terbit Rp190.000') && str_contains($hA, 'Terbayar Rp140.000') && str_contains($hA, 'Saldo akhir Rp132.000')
    && str_contains($hA, 'Bayar tagihan dari tabungan Rp50.000') && str_contains($hA, 'Penerimaan kas baru Rp272.000'),
    'Admin: tagihan Rp190.000, terbayar Rp140.000, saldo akhir Rp132.000, kas baru Rp272.000 (setoran 182.000 + tunai 90.000; dari tabungan Rp50.000 terpisah)');
$assert(str_contains($hA, 'Rekonsiliasi periode: selisih Rp0') && str_contains($hA, 'Jejak koreksi dan pembatalan') && str_contains($hA, 'Uji HTTP F5 alasan koreksi')
    && str_contains($hA, 'Santri nonaktif yang masih bersaldo') && str_contains($hA, $namaS4) && str_contains($hA, '1 transfer (Rp10.000) menunggu verifikasi'),
    'Admin: selisih Rp0, jejak koreksi beralasan, R-10 santri keluar bersaldo, transfer menunggu terpisah');
$hT = $teks($k['sbx_v6_bsa_tsanawi']->req('/portal/bsa_laporan.php')['body']);
$assert(str_contains($hT, $namaS2) && !str_contains($hT, $namaS1) && !str_contains($hT, $namaS4) && str_contains($hT, 'Saldo akhir Rp70.000') && !str_contains($hT, 'Santri nonaktif yang masih'),
    'Petugas Tsanawi: hanya santri Tsanawi (saldo Rp70.000), tanpa santri lain dan tanpa daftar R-10');
foreach (['sbx_v6_wali_smp' => [$namaS1, 'Saldo akhir Rp50.000'], 'sbx_v6_wali_dua' => [$namaS2, 'Saldo akhir Rp120.000']] as $u => [$nama, $saldo]) {
    $isi = $k[$u]->req('/portal/bsa_laporan.php')['body'];
    $hw = $teks($isi);
    $assert(str_contains($hw, $nama) && str_contains($hw, $saldo) && !str_contains($hw, 'Kas nyata') && !str_contains($hw, 'Jejak koreksi') && !str_contains($isi, 'TRF-HTTP-F5')
        && !str_contains($hw, 'Uji HTTP F5 alasan') && !str_contains($hw, 'menunggu verifikasi') && !str_contains($hw, $namaS4) && !str_contains($isi, 'jenis=koreksi'),
        "$u: laporan anak ($saldo) tanpa kas, koreksi beralasan, referensi, transfer menunggu, santri lain, atau ekspor koreksi");
}
$hS = $teks($k['sbx_v6_wali_smp']->req('/portal/bsa_laporan.php')['body']);
$assert(!str_contains($hS, $namaS2), 'Wali SMP tidak melihat anak lain (santri Tsanawi)');

// ===================================================================== H-C
echo PHP_EOL . '=== H-C. Saringan ===' . PHP_EOL;
$r = $k['sbx_admin']->req('/portal/bsa_laporan.php?dari=2026-13');
$ikut = $k['sbx_admin']->req('/portal/bsa_laporan.php');
$assert(in_array($r['status'], [302, 303], true) && str_contains($teks($ikut['body']), 'Periode dari tidak sah'), 'Periode tidak sah → dialihkan dengan pesan');
$depan = (new DateTimeImmutable($B . '-01'))->modify('+1 month')->format('Y-m');
$r = $k['sbx_admin']->req("/portal/bsa_laporan.php?dari=$depan&sampai=$depan");
$assert(in_array($r['status'], [302, 303], true), 'Periode masa depan → dialihkan');
$assert($k['sbx_v6_bsa_tsanawi']->req("/portal/bsa_laporan.php?santri=$s1")['status'] === 404 && $k['sbx_v6_wali_smp']->req("/portal/bsa_laporan.php?santri=$s2")['status'] === 404
    && $k['sbx_v6_bsa_tsanawi']->req('/portal/bsa_laporan.php?unit=SMP')['status'] === 404 && $k['sbx_v6_bsa_tsanawi']->req("/portal/bsa_laporan_ekspor.php?jenis=transaksi&santri=$s1")['status'] === 404,
    'Santri/unit di luar hak (petugas unit, wali anak lain), juga lewat ekspor → 404');
$hPer = $teks($k['sbx_admin']->req("/portal/bsa_laporan.php?santri=$s1")['body']);
$assert(str_contains($hPer, 'Laporan untuk ' . $namaS1) && str_contains($hPer, 'Saldo akhir Rp50.000') && !str_contains($hPer, $namaS2), 'Laporan per santri (admin): hanya santri itu, saldo Rp50.000');

// ===================================================================== H-D
echo PHP_EOL . '=== H-D. Ekspor web ===' . PHP_EOL;
$auditAwal = (int) $satu("SELECT COUNT(*) FROM audit_logs WHERE action = 'bsa.laporan.ekspor'");
$e = $k['sbx_admin']->req('/portal/bsa_laporan_ekspor.php?jenis=santri');
$assert($e['status'] === 200 && str_starts_with($e['type'], 'text/csv') && preg_match('/Content-Disposition: attachment; filename="keuangan-santri-' . $B . '\.csv"/i', $e['header']) === 1
    && preg_match('/Cache-Control: private, no-store/i', $e['header']) === 1 && preg_match('/X-Content-Type-Options: nosniff/i', $e['header']) === 1 && str_starts_with($e['body'], "\xEF\xBB\xBF"),
    'Ekspor admin: text/csv, lampiran bernama periode, no-store, nosniff, BOM UTF-8');
$baris = static fn (string $csv): array => array_values(array_filter(explode("\n", trim(substr($csv, 3))), static fn (string $l): bool => $l !== ''));
$assert(count($baris($e['body'])) === 4 && str_contains($e['body'], 'Selisih akun'), 'Ekspor admin per santri: 3 santri + kepala, dengan kolom selisih');
$eW = $k['sbx_v6_wali_dua']->req('/portal/bsa_laporan_ekspor.php?jenis=transaksi')['body'];
$assert(str_contains($eW, 'V6S0001') && str_contains($eW, 'V6S0002') && !str_contains($eW, 'V6S0004') && !str_contains($eW, 'TRF-HTTP-F5') && !str_contains($eW, 'Uji HTTP F5'),
    'Ekspor wali dua anak: rincian transaksi kedua anaknya saja, tanpa referensi/alasan');
$assert($k['sbx_v6_wali_dua']->req('/portal/bsa_laporan_ekspor.php?jenis=koreksi')['status'] === 403 && $k['sbx_v6_wali_smp']->req('/portal/bsa_laporan_ekspor.php?jenis=transaksi&santri=' . $s2)['status'] === 404,
    'Wali: ekspor koreksi 403; ekspor anak lain 404');
$eK = $k['sbx_v6_bsa_b']->req('/portal/bsa_laporan_ekspor.php?jenis=koreksi')['body'];
$assert(str_contains($eK, 'Uji HTTP F5 alasan koreksi') && str_contains($eK, 'BSA-TB-'), 'Ekspor koreksi petugas: alasan dan nomor kuitansi asal');
$r = $k['sbx_admin']->req('/portal/bsa_laporan_ekspor.php?jenis=apa');
$assert(in_array($r['status'], [302, 303], true), 'Jenis ekspor tidak dikenal → dialihkan dengan pesan (tanpa berkas)');
$assert((int) $satu("SELECT COUNT(*) FROM audit_logs WHERE action = 'bsa.laporan.ekspor'") - $auditAwal === 3, 'Audit ekspor: tepat 3 ekspor web berhasil di bagian ini tercatat');

// ===================================================================== H-E
echo PHP_EOL . '=== H-E. API laporan dan ekspor ===' . PHP_EOL;
$tok = [];
foreach (['sbx_admin', 'sbx_v6_bsa_tsanawi', 'sbx_v6_wali_smp', 'sbx_v6_wali_dua', 'sbx_pengurus_biasa'] as $u) {
    $tok[$u] = $token($u);
}
$assert(!in_array('', $tok, true), 'Token API untuk lima akun');
$r = $api('/v6/bsa/laporan', $tok['sbx_admin']);
$layanan = laporan_bsa_service()->laporan(['id' => $uid('sbx_admin'), 'roles' => []], []);
$assert($r['status'] === 200 && preg_match('/Cache-Control: private, no-store/i', $r['header']) === 1 && $r['json']['data']['penerimaan_kas_baru'] === $layanan['penerimaan_kas_baru']
    && $r['json']['data']['tabungan'] === $layanan['tabungan'] && $r['json']['data']['tagihan'] === $layanan['tagihan'] && $r['json']['data']['selisih']['nol'] === true,
    'API admin: 200 no-store; angka = layanan web (kas baru Rp' . $layanan['penerimaan_kas_baru'] . '), selisih Rp0');
$r = $api('/v6/bsa/laporan', $tok['sbx_v6_wali_smp']);
$assert($r['status'] === 200 && $r['json']['data']['kas'] === null && $r['json']['data']['internal'] === null && $r['json']['data']['koreksi'] === null
    && $r['json']['data']['tagihan']['menunggu_verifikasi'] === null && !str_contains($r['body'], 'TRF-HTTP-F5') && !str_contains($r['body'], 'Uji HTTP F5')
    && array_column(array_column($r['json']['data']['per_santri']['rows'], 'santri'), 'id') === [$s1], 'API wali SMP: hanya anaknya, tanpa kas/internal/koreksi/transfer menunggu/referensi');
$assert($api('/v6/bsa/laporan', null)['status'] === 401 && $api('/v6/bsa/laporan', $tok['sbx_pengurus_biasa'])['status'] === 403
    && $api('/v6/bsa/laporan?santri=' . $s1, $tok['sbx_v6_bsa_tsanawi'])['status'] === 404 && $api('/v6/bsa/laporan?dari=2026-13', $tok['sbx_admin'])['status'] === 422
    && $api('/v6/bsa/laporan', $tok['sbx_admin'], 'POST')['status'] === 404, 'API: tanpa token 401, pengurus 403, santri di luar cakupan 404, periode salah 422, POST 404');
$e = $api('/v6/bsa/laporan/ekspor?jenis=transaksi', $tok['sbx_v6_wali_dua']);
$assert($e['status'] === 200 && str_starts_with($e['type'], 'text/csv') && str_contains($e['body'], 'V6S0002') && !str_contains($e['body'], 'V6S0004') && !str_contains($e['body'], 'TRF-HTTP-F5'),
    'API ekspor wali dua anak: CSV dua anaknya saja');
$e = $api('/v6/bsa/laporan/ekspor?jenis=koreksi', $tok['sbx_v6_wali_dua']);
$assert($e['status'] === 403 && ($e['json']['success'] ?? null) === false, 'API ekspor koreksi oleh wali → 403 JSON');

// ===================================================================== H-F
echo PHP_EOL . '=== H-F. Sesi dan token lama sesudah pencabutan ===' . PHP_EOL;
$tugasT = (int) $satu("SELECT a.id FROM bendahara_bulanan_assignments a JOIN users u ON u.pengurus_id = a.pengurus_id WHERE u.username = 'sbx_v6_bsa_tsanawi' AND a.is_active = 1 ORDER BY a.id LIMIT 1");
$jalankan("UPDATE bendahara_bulanan_assignments SET is_active = 0 WHERE id = $tugasT");
$assert($k['sbx_v6_bsa_tsanawi']->req('/portal/bsa_laporan.php')['status'] === 403 && $k['sbx_v6_bsa_tsanawi']->req('/portal/bsa_laporan_ekspor.php?jenis=santri')['status'] === 403
    && $api('/v6/bsa/laporan', $tok['sbx_v6_bsa_tsanawi'])['status'] === 403, 'Penugasan dinonaktifkan: sesi web dan token lama → 403 untuk laporan dan ekspor');
$jalankan("UPDATE bendahara_bulanan_assignments SET is_active = 1 WHERE id = $tugasT");
$relasi = (int) $satu("SELECT sw.id FROM santri_wali sw JOIN users u ON u.wali_id = sw.wali_id WHERE u.username = 'sbx_v6_wali_dua' AND sw.santri_id = $s2 AND sw.archived_at IS NULL");
$jalankan("UPDATE santri_wali SET archived_at = NOW() WHERE id = $relasi");
$hD = $teks($k['sbx_v6_wali_dua']->req('/portal/bsa_laporan.php')['body']);
$eD = $k['sbx_v6_wali_dua']->req('/portal/bsa_laporan_ekspor.php?jenis=transaksi')['body'];
$aD = $api('/v6/bsa/laporan', $tok['sbx_v6_wali_dua']);
$assert(!str_contains($hD, $namaS2) && str_contains($hD, $namaS1) && !str_contains($eD, 'V6S0002') && array_column(array_column($aD['json']['data']['per_santri']['rows'], 'santri'), 'id') === [$s1]
    && $api('/v6/bsa/laporan?santri=' . $s2, $tok['sbx_v6_wali_dua'])['status'] === 404, 'Relasi satu anak dicabut: halaman, ekspor, dan API wali dua anak tinggal satu anak; anak dicabut 404');
$jalankan("UPDATE santri_wali SET archived_at = NULL WHERE id = $relasi");

// ===================================================================== H-G
echo PHP_EOL . '=== H-G. Log, audit, verifier ===' . PHP_EOL;
usleep(300000);
$isiLog = (string) file_get_contents($log);
$assert(!preg_match('/PHP (Warning|Notice|Fatal|Deprecated)|Uncaught/', $isiLog), 'Log server tanpa galat/peringatan PHP');
$assert(!str_contains($isiLog, 'TRF-HTTP-F5') && !str_contains($isiLog, 'Bearer '), 'Log server tanpa referensi transfer atau token');
$assert((int) $satu("SELECT COUNT(*) FROM audit_logs WHERE action = 'bsa.laporan.ekspor' AND (after_json REGEXP '\"(nominal|saldo|total|terbayar|sisa|referensi|alasan|nama)\"' OR after_json LIKE '%TRF%')") === 0
    && (int) $satu("SELECT COUNT(*) FROM audit_logs WHERE action = 'bsa.laporan.ekspor' AND JSON_UNQUOTE(JSON_EXTRACT(after_json, '$.kanal')) = 'api'") === 1,
    'Audit ekspor tanpa nominal/alasan/referensi; ekspor API tercatat berkanal api');
$out = [];
exec('DB_NAME=' . escapeshellarg($db) . ' php ' . escapeshellarg($root . '/bin/v6_verify.php') . ' 2>&1', $out, $kodeVerify);
$assert($kodeVerify === 0, 'Verifier rilis V6 lulus sesudah uji HTTP');
if ($kodeVerify !== 0) {
    echo implode(PHP_EOL, array_filter($out, static fn ($l) => str_contains($l, '[gagal]'))) . PHP_EOL;
}
@unlink($log);

echo PHP_EOL . 'Lulus: ' . $lulus . ' · Gagal: ' . count($gagal) . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
