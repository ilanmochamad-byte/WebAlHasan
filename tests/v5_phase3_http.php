<?php

declare(strict_types=1);

/**
 * Uji HTTP PRD V5 Fase 3 — web (sesi + CSRF) dan API (token) — pada server
 * PHP bawaan dan DB uji terpisah.
 *
 *   V5F3_DB=test_v5_f3_http_test bash tests/v5_phase3_db.sh
 *   V5F3_DB=test_v5_f3_http_test php tests/v5_phase3_http.php
 *
 *   M-A  hak halaman ringkasan petugas dan halaman wali per akun (server,
 *        bukan menu); menu "Kesehatan anak" dan bagian ringkasan di detail;
 *   M-B  sebelum terbit: halaman/API wali kosong, ID tebakan 403;
 *   M-C  alur web petugas: susun → pratinjau (persis tampilan wali, tanpa
 *        catatan klinis) → tanpa konfirmasi ditolak → terbit; kirim ulang
 *        kunci sama → replay; tanpa CSRF 419;
 *   M-D  wali web: dua wali melihat ringkasannya, wali lain 403; notifikasi
 *        generik; ketukan notifikasi → halaman wali yang memeriksa ulang;
 *   M-E  tautan notifikasi sesudah logout → masuk dulu; wali lain dengan
 *        tautan yang sama → 403; relasi dicabut → sesi web & token lama 403;
 *   M-F  API: wali (akses, daftar, detail), petugas (internal, pratinjau,
 *        terbit, tarik, replay header), admin/pembimbing ditolak menulis,
 *        daftar notifikasi API hanya penunjuk;
 *   M-G  penggantian/penarikan web beralasan; wali melihat "ditarik";
 *   M-H  no-store, log server tanpa galat PHP dan tanpa data terlarang.
 *
 * Mengubah data uji; bangun ulang DB sebelum mengulang.
 */

$root = dirname(__DIR__);
$db = getenv('V5F3_DB') ?: 'test_v5_f3_http_test';
$port = (int) (getenv('V5F3_PORT') ?: 8993);
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

$log = tempnam(sys_get_temp_dir(), 'v5f3-server-');
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
        $this->jar = tempnam(sys_get_temp_dir(), 'v5f3-jar-');
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
    $r = $api('POST', '/auth/login', null, ['username' => $user, 'password' => 'Sandbox#123', 'device_name' => 'uji-v5-f3']);

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
$hitung = static fn (string $tabel, string $where = '1 = 1'): int => (int) $satu("SELECT COUNT(*) FROM `$tabel` WHERE $where");
$nilaiInput = static function (string $html, string $nama): string {
    preg_match('/name="' . preg_quote($nama, '/') . '" value="([^"]*)"/', $html, $m);

    return html_entity_decode($m[1] ?? '', ENT_QUOTES);
};
$kunci = static fn (string $awal): string => $awal . '-' . bin2hex(random_bytes(6));
$penanda = 'V5F3HTTP' . strtoupper(bin2hex(random_bytes(3)));
$sW = (int) $satu("SELECT id FROM santri WHERE nis='V5S0201'");
$wCabut = (int) $satu("SELECT id FROM wali WHERE nama='SBX V5 Wali Cabut'");
$waktu = date('Y-m-d H:i', strtotime('-2 hours'));
$assert($sW > 0 && $hitung('kesehatan_ringkasan') === 0, 'Fixture V5 Fase 3 tersedia dan belum ada ringkasan');

// Kunjungan sumber dibuat lewat layanan Fase 1–2 (bukan bagian yang diuji di sini).
$uidPetugas = (int) $satu("SELECT id FROM users WHERE username='sbx_v5_petugas_f'");
$buat = static fn (): int => kesehatan_service()->buat(['id' => $uidPetugas, 'roles' => []], ['idempotency_key' => $kunci('m-buat'), 'santri_id' => $sW, 'waktu_kunjungan' => $waktu,
    'keluhan' => "$penanda-KELUHAN", 'catatan_klinis' => "$penanda-CATATAN", 'obat' => [['nama_obat' => "$penanda-OBAT"]], 'rujukan' => [['tujuan' => "$penanda-RUJUKAN"]]], 'web')['id'];
$kid = $buat();

$akun = ['sbx_admin', 'sbx_v5_petugas_f', 'sbx_v5_admin_petugas', 'sbx_v5_pembimbing', 'sbx_v5_murobi', 'sbx_v5_petugas', 'sbx_v5_wali_1', 'sbx_v5_wali_2', 'sbx_v5_wali_cabut', 'sbx_v5_wali_lain'];
$k = [];
foreach ($akun as $u) {
    $k[$u] = $klien($u);
}
$petugas = $k['sbx_v5_petugas_f'];
$halRingkasan = '/portal/kesehatan_ringkasan.php?id=' . $kid;

// ===================================================================== M-A
echo PHP_EOL . '=== M-A. Hak halaman ===' . PHP_EOL;
$harap = [
    'sbx_v5_petugas_f' => [200, 403], 'sbx_admin' => [200, 403], 'sbx_v5_admin_petugas' => [200, 403], 'sbx_v5_pembimbing' => [200, 403],
    'sbx_v5_murobi' => [403, 403], 'sbx_v5_petugas' => [403, 403], 'sbx_v5_wali_1' => [403, 200], 'sbx_v5_wali_lain' => [403, 200],
];
foreach ($harap as $u => [$petugasHal, $waliHal]) {
    $a = $k[$u]->req($halRingkasan)['status'];
    $b = $k[$u]->req('/portal/kesehatan_wali.php')['status'];
    $assert([$a, $b] === [$petugasHal, $waliHal], "$u: ringkasan petugas $a, kesehatan anak $b (harap $petugasHal/$waliHal)");
}
$r = $k['sbx_admin']->req($halRingkasan);
$assert(!str_contains($r['body'], 'name="pratinjau_hash"') && !str_contains($r['body'], 'value="pratinjau"') && str_contains($r['body'], 'Hanya baca'), 'Admin: halaman ringkasan hanya baca, tanpa formulir terbit');
$r = $k['sbx_v5_pembimbing']->req($halRingkasan);
$assert(!str_contains($r['body'], 'value="pratinjau"') && !str_contains($r['body'], 'Riwayat ringkasan'), 'Pembimbing terkait: hanya baca, tanpa riwayat');
$r = $petugas->req('/portal/kesehatan_detail.php?id=' . $kid);
$assert(str_contains($r['body'], 'Ringkasan untuk wali') && str_contains($r['body'], 'Susun ringkasan untuk wali') && str_contains($r['body'], 'Catatan klinik di atas tidak pernah tampil kepada wali'),
    'Detail kunjungan petugas: bagian "Ringkasan untuk wali" dengan tautan susun');
$r = $k['sbx_v5_wali_1']->req('/portal/index.php');
$assert(str_contains($r['body'], 'Kesehatan anak') && str_contains($r['body'], '/portal/kesehatan_wali.php'), 'Menu wali memuat "Kesehatan anak"');
$assert(!str_contains($k['sbx_v5_petugas_f']->req('/portal/index.php')['body'], 'Kesehatan anak'), 'Menu petugas tanpa "Kesehatan anak"');

// ===================================================================== M-B
echo PHP_EOL . '=== M-B. Sebelum terbit ===' . PHP_EOL;
$r = $k['sbx_v5_wali_1']->req('/portal/kesehatan_wali.php');
$assert($r['status'] === 200 && str_contains($r['body'], 'Belum ada informasi kesehatan'), 'Halaman wali sebelum terbit: kosong');
foreach ([1, 2, 50] as $tebak) {
    $assert($k['sbx_v5_wali_1']->req('/portal/kesehatan_wali.php?id=' . $tebak)['status'] === 403, "Web: ID tebakan $tebak sebelum terbit → 403");
}
$tWali1 = $token('sbx_v5_wali_1');
$tWaliLain = $token('sbx_v5_wali_lain');
$tWaliCabut = $token('sbx_v5_wali_cabut');
$tPetugas = $token('sbx_v5_petugas_f');
$tAdmin = $token('sbx_admin');
$tPembimbing = $token('sbx_v5_pembimbing');
$r = $api('GET', '/v5/kesehatan/wali/ringkasan', $tWali1);
$assert($r['status'] === 200 && $r['json']['data']['total'] === 0 && $api('GET', '/v5/kesehatan/wali/ringkasan/1', $tWali1)['status'] === 403, 'API wali sebelum terbit: daftar kosong, ID tebakan 403');
$r = $api('GET', '/v5/kesehatan/wali/akses', $tWali1);
$assert($r['status'] === 200 && $r['json']['data']['wali'] === true && count($r['json']['data']['anak']) === 1 && $api('GET', '/v5/kesehatan/wali/akses', $tPetugas)['json']['data']['wali'] === false,
    'API akses wali: wali_1 punya satu anak; petugas bukan wali');

// ===================================================================== M-C
echo PHP_EOL . '=== M-C. Alur web petugas ===' . PHP_EOL;
$r = $petugas->req($halRingkasan);
$assert($r['status'] === 200 && str_contains($r['body'], 'name="ringkasan"') && str_contains($r['body'], 'Lihat pratinjau') && !str_contains($r['body'], "$penanda-KELUHAN"),
    'Formulir susun ringkasan kosong, tidak diisi dari catatan klinis');
$kv = $nilaiInput($r['body'], 'kunjungan_versi');
$isiForm = ['ringkasan' => "$penanda-RINGKASAN Ananda sempat demam dan kini sudah membaik.", 'tindakan_umum' => 'Istirahat di klinik', 'tindak_lanjut_wali' => 'Mohon pantau suhu tubuh.'];
$tanpaCsrf = ['aksi' => 'pratinjau', 'kunjungan_versi' => $kv, 'revisi_ringkasan' => '0'] + $isiForm;
$assert($petugas->req($halRingkasan, $tanpaCsrf)['status'] === 419, 'POST tanpa CSRF → 419');
$r = $petugas->req($halRingkasan, ['_csrf' => $petugas->csrf($halRingkasan)] + $tanpaCsrf);
$assert($r['status'] === 200 && str_contains($r['body'], 'Pratinjau · persis seperti dilihat wali') && str_contains($r['body'], 'name="konfirmasi"') && str_contains($r['body'], "$penanda-RINGKASAN")
    && str_contains($r['body'], 'Yang perlu diperhatikan orang tua') && !str_contains($r['body'], "$penanda-KELUHAN") && !str_contains($r['body'], "$penanda-OBAT") && $hitung('kesehatan_ringkasan') === 0,
    'Pratinjau web: isi persis tampilan wali, tanpa catatan klinis, belum menulis apa pun');
$terbitForm = ['_csrf' => $nilaiInput($r['body'], '_csrf'), 'aksi' => 'terbit', 'pratinjau_hash' => $nilaiInput($r['body'], 'pratinjau_hash'), 'idempotency_key' => $nilaiInput($r['body'], 'idempotency_key'),
    'kunjungan_versi' => $kv, 'revisi_ringkasan' => '0', 'alasan' => ''] + $isiForm;
$r = $petugas->req($halRingkasan, $terbitForm);
$assert($r['status'] === 422 && str_contains($r['body'], 'Konfirmasi eksplisit') && $hitung('kesehatan_ringkasan') === 0, 'Terbit tanpa centang konfirmasi → 422 tanpa baris');
$r = $petugas->req($halRingkasan, $terbitForm + ['konfirmasi' => '1']);
$rid = (int) $satu("SELECT id FROM kesehatan_ringkasan WHERE kunjungan_id = $kid");
$assert(in_array($r['status'], [302, 303], true) && $rid > 0 && $hitung('notifikasi_outbox', "event_key = 'v5:kesehatan:ringkasan:$rid:v1' AND kanal = 'InApp'") === 3, 'Terbit web → alihkan; 3 InApp');
$r = $petugas->req($halRingkasan, $terbitForm + ['konfirmasi' => '1']);
$assert(in_array($r['status'], [302, 303], true) && $hitung('kesehatan_ringkasan_versi') === 1 && $hitung('notifikasi_outbox', "event_type LIKE 'v5\\_%'") === 3, 'Kirim ulang (klik ganda, kunci sama) → replay, tanpa versi/notifikasi ganda');
$r = $petugas->req($halRingkasan);
$assert(str_contains($r['body'], 'sudah diterbitkan sebelumnya') && str_contains($r['body'], 'Yang dilihat wali saat ini') && str_contains($r['body'], "$penanda-RINGKASAN") && str_contains($r['body'], 'Riwayat ringkasan'),
    'Sesudah terbit: pesan replay, isi berlaku, dan riwayat tampil bagi petugas');

// ===================================================================== M-D
echo PHP_EOL . '=== M-D. Wali web dan notifikasi ===' . PHP_EOL;
foreach (['sbx_v5_wali_1', 'sbx_v5_wali_2'] as $u) {
    $r = $k[$u]->req('/portal/kesehatan_wali.php');
    $d = $k[$u]->req('/portal/kesehatan_wali.php?id=' . $rid);
    $assert($r['status'] === 200 && str_contains($r['body'], 'kesehatan_wali.php?id=' . $rid) && $d['status'] === 200 && str_contains($d['body'], "$penanda-RINGKASAN")
        && !str_contains($d['body'], "$penanda-KELUHAN") && !str_contains($d['body'], "$penanda-OBAT") && !str_contains($d['body'], 'SBX V5 Petugas F'), "$u: daftar + detail ringkasan sendiri, tanpa catatan klinis/nama petugas");
}
$assert($k['sbx_v5_wali_lain']->req('/portal/kesehatan_wali.php?id=' . $rid)['status'] === 403 && !str_contains($k['sbx_v5_wali_lain']->req('/portal/kesehatan_wali.php')['body'], 'kesehatan_wali.php?id=' . $rid),
    'Wali lain: detail 403, tidak ada di daftar');
$nid = (int) $satu("SELECT id FROM notifikasi_outbox WHERE event_key = 'v5:kesehatan:ringkasan:$rid:v1' AND kanal = 'InApp' AND penerima_user_id = (SELECT id FROM users WHERE username = 'sbx_v5_wali_1')");
$r = $k['sbx_v5_wali_1']->req('/portal/notifikasi.php?jenis=kesehatan');
$assert($r['status'] === 200 && str_contains($r['body'], 'Informasi kesehatan') && !str_contains($r['body'], 'SBX V5 Wali Santri') && !str_contains($r['body'], $penanda), 'Pusat notifikasi web: kategori Kesehatan, kalimat generik tanpa nama/isi');
$r = $k['sbx_v5_wali_1']->req('/portal/notifikasi.php?buka=' . $nid);
$assert(in_array($r['status'], [302, 303], true) && str_contains((string) $r['location'], '/portal/kesehatan_wali.php?id=' . $rid), 'Ketukan notifikasi → halaman wali (hanya penunjuk)');
$assert($k['sbx_v5_wali_1']->req('/portal/kesehatan_wali.php?id=' . $rid)['status'] === 200, 'Tujuan notifikasi memeriksa ulang dan terbuka bagi wali berhak');
$r = $k['sbx_v5_wali_lain']->req('/portal/notifikasi.php?buka=' . $nid);
$assert($r['status'] !== 302 && str_contains($r['body'], 'Notifikasi tidak dapat dibuka'), 'Notifikasi milik wali lain tidak dapat dibuka dengan ID tebakan');

// ===================================================================== M-E
echo PHP_EOL . '=== M-E. Logout, tautan lama, relasi dicabut ===' . PHP_EOL;
$tamu = new Klien($base);
$r = $tamu->req('/portal/notifikasi.php?buka=' . $nid);
$assert(in_array($r['status'], [302, 303], true) && str_contains((string) $r['location'], 'pesan=sesi'), 'Ketukan notifikasi tanpa sesi → diarahkan masuk');
$r = $tamu->req('/portal/kesehatan_wali.php?id=' . $rid);
$assert(in_array($r['status'], [302, 303], true) && str_contains((string) $r['location'], 'pesan=sesi') && str_contains((string) $r['location'], rawurlencode('kesehatan_wali.php?id=' . $rid)) && !str_contains($r['body'], $penanda),
    'Tautan ringkasan tanpa sesi → masuk dulu (membawa tujuan), tanpa isi');
$tamu->login('sbx_v5_wali_lain');
$assert($tamu->req('/portal/kesehatan_wali.php?id=' . $rid)['status'] === 403, 'Masuk sebagai wali lain lalu membuka tautan yang sama → 403');
$keluar = new Klien($base);
$keluar->login('sbx_v5_wali_1');
$keluar->req('/admin/logout.php', ['_csrf' => $keluar->csrf('/admin/logout.php')]);
$assert(in_array($keluar->req('/portal/kesehatan_wali.php?id=' . $rid)['status'], [302, 303], true), 'Sesudah logout, tautan lama meminta masuk lagi');
$assert($k['sbx_v5_wali_cabut']->req('/portal/kesehatan_wali.php?id=' . $rid)['status'] === 200 && $api('GET', '/v5/kesehatan/wali/ringkasan/' . $rid, $tWaliCabut)['status'] === 200, 'Sebelum dicabut: wali_cabut membuka web/API');
$jalankan("UPDATE santri_wali SET archived_at = NOW() WHERE santri_id = $sW AND wali_id = $wCabut");
$assert($k['sbx_v5_wali_cabut']->req('/portal/kesehatan_wali.php?id=' . $rid)['status'] === 403 && $api('GET', '/v5/kesehatan/wali/ringkasan/' . $rid, $tWaliCabut)['status'] === 403
    && $api('GET', '/v5/kesehatan/wali/ringkasan', $tWaliCabut)['json']['data']['total'] === 0, 'Relasi dicabut: sesi web & token lama → 403, daftar API kosong');
$nCabut = (int) $satu("SELECT id FROM notifikasi_outbox WHERE event_key = 'v5:kesehatan:ringkasan:$rid:v1' AND kanal = 'InApp' AND penerima_user_id = (SELECT id FROM users WHERE username = 'sbx_v5_wali_cabut')");
$r = $k['sbx_v5_wali_cabut']->req('/portal/notifikasi.php?buka=' . $nCabut);
$assert(in_array($r['status'], [302, 303], true) && $k['sbx_v5_wali_cabut']->req((string) parse_url((string) $r['location'], PHP_URL_PATH) . '?' . parse_url((string) $r['location'], PHP_URL_QUERY))['status'] === 403,
    'Tautan notifikasi lama wali yang relasinya dicabut → tujuan 403');

// ===================================================================== M-F
echo PHP_EOL . '=== M-F. API ===' . PHP_EOL;
$r = $api('GET', '/v5/kesehatan/wali/ringkasan', $tWali1);
$assert($r['status'] === 200 && $r['json']['data']['total'] === 1 && $r['json']['data']['rows'][0]['ada_tindak_lanjut'] === true && str_contains($r['header'], 'no-store'), 'API daftar wali: 1 ringkasan, penanda tindak lanjut, no-store');
$r = $api('GET', '/v5/kesehatan/wali/ringkasan/' . $rid, $tWali1);
$assert($r['status'] === 200 && $r['json']['data']['ringkasan'] === $isiForm['ringkasan'] && !str_contains($r['body'], "$penanda-KELUHAN") && !str_contains($r['body'], 'petugas'), 'API detail wali: proyeksi tanpa klinis/petugas');
$assert($api('GET', '/v5/kesehatan/wali/ringkasan/' . $rid, $tWaliLain)['status'] === 403 && $api('GET', '/v5/kesehatan/wali/ringkasan', $tPetugas)['status'] === 403, 'API: wali lain 403; petugas bukan wali 403');
$r = $api('GET', '/notifikasi?jenis=kesehatan', $tWali1);
$item = $r['json']['data']['items'][0] ?? [];
$assert($r['status'] === 200 && ($item['tautan']['tipe'] ?? '') === 'kesehatan_ringkasan' && ($item['tautan']['ringkasan_id'] ?? 0) === $rid && $item['santri_nama'] === null && !str_contains($r['body'], 'SBX V5 Wali Santri'),
    'API notifikasi wali: penunjuk ringkasan_id, tanpa nama santri');
$r = $api('GET', '/v5/kesehatan/kunjungan/' . $kid . '/ringkasan', $tPetugas);
$assert($r['status'] === 200 && $r['json']['data']['ringkasan']['revisi'] === 1 && count($r['json']['data']['versi']) === 1, 'API internal petugas: kepala + riwayat');
$r = $api('GET', '/v5/kesehatan/kunjungan/' . $kid . '/ringkasan', $tPembimbing);
$assert($r['status'] === 200 && $r['json']['data']['versi'] === [] && $r['json']['data']['berlaku']['ringkasan'] === $isiForm['ringkasan'], 'API internal pembimbing: versi berlaku saja');
$kid2 = $buat();
$badan = ['kunjungan_versi' => 1, 'revisi_ringkasan' => 0, 'ringkasan' => 'Ringkasan lewat API untuk wali santri.', 'tindakan_umum' => null, 'tindak_lanjut_wali' => null];
$pr = $api('POST', '/v5/kesehatan/kunjungan/' . $kid2 . '/ringkasan/pratinjau', $tPetugas, $badan);
$assert($pr['status'] === 200 && strlen($pr['json']['data']['pratinjau_hash']) === 64 && $hitung('kesehatan_ringkasan', "kunjungan_id = $kid2") === 0, 'API pratinjau: hash, tanpa menulis');
foreach (['admin' => $tAdmin, 'pembimbing' => $tPembimbing, 'wali' => $tWali1] as $label => $t) {
    $assert($api('POST', '/v5/kesehatan/kunjungan/' . $kid2 . '/ringkasan/pratinjau', $t, $badan)['status'] === 403
        && $api('POST', '/v5/kesehatan/kunjungan/' . $kid2 . '/ringkasan', $t, $badan + ['konfirmasi' => true, 'pratinjau_hash' => $pr['json']['data']['pratinjau_hash']], ['Idempotency-Key: ' . $kunci('m-f')])['status'] === 403,
        "API $label: pratinjau/terbit → 403");
}
$kunciApi = $kunci('m-f-api');
$t1 = $api('POST', '/v5/kesehatan/kunjungan/' . $kid2 . '/ringkasan', $tPetugas, $badan + ['konfirmasi' => true, 'pratinjau_hash' => $pr['json']['data']['pratinjau_hash']], ['Idempotency-Key: ' . $kunciApi]);
$t2 = $api('POST', '/v5/kesehatan/kunjungan/' . $kid2 . '/ringkasan', $tPetugas, $badan + ['konfirmasi' => true, 'pratinjau_hash' => $pr['json']['data']['pratinjau_hash']], ['Idempotency-Key: ' . $kunciApi]);
$assert($t1['status'] === 201 && $t1['json']['data']['replayed'] === false && $t1['json']['data']['notifikasi']['in_app'] === 2 && $t2['status'] === 200 && $t2['json']['data']['replayed'] === true,
    'API terbit: 201 lalu replay 200 dengan kunci header sama; 2 InApp (wali_cabut sudah tidak berhak)');
$assert(!isset($t1['json']['data']['ringkasan']) && !str_contains($t1['body'], 'Ringkasan lewat API'), 'Respons tulis API tanpa isi ringkasan');
$tk = $api('POST', '/v5/kesehatan/kunjungan/' . $kid2 . '/ringkasan/tarik', $tPetugas, ['revisi_ringkasan' => 1, 'alasan' => 'Penarikan uji lewat API'], ['Idempotency-Key: ' . $kunci('m-f-tarik')]);
$assert($tk['status'] === 200 && $tk['json']['data']['revisi'] === 2 && $api('GET', '/v5/kesehatan/wali/ringkasan/' . $t1['json']['data']['ringkasan_id'], $tWali1)['json']['data']['status'] === 'Ditarik', 'API tarik: wali melihat Ditarik');
$assert($api('POST', '/v5/kesehatan/kunjungan/' . $kid2 . '/ringkasan/tarik', $tAdmin, ['revisi_ringkasan' => 2, 'alasan' => 'Admin mencoba menarik'], ['Idempotency-Key: ' . $kunci('m-f-ta')])['status'] === 403, 'API admin tarik → 403');

// ===================================================================== M-G
echo PHP_EOL . '=== M-G. Penggantian dan penarikan web ===' . PHP_EOL;
$r = $petugas->req($halRingkasan);
$assert(str_contains($r['body'], 'Ganti ringkasan (versi baru)') && str_contains($r['body'], 'name="alasan"') && preg_match('/<textarea[^>]*id="ringkasan"[^>]*>' . preg_quote("$penanda-RINGKASAN", '/') . '/', $r['body']) === 1
    && !str_contains($r['body'], "$penanda-KELUHAN"), 'Formulir penggantian: textarea diisi versi berlaku (bukan catatan klinis), wajib alasan');
$ganti = ['_csrf' => $petugas->csrf($halRingkasan), 'aksi' => 'pratinjau', 'kunjungan_versi' => '1', 'revisi_ringkasan' => '1', 'ringkasan' => "$penanda-V2 Ananda sudah sehat kembali.", 'tindakan_umum' => '', 'tindak_lanjut_wali' => '', 'alasan' => ''];
$r = $petugas->req($halRingkasan, $ganti);
$assert($r['status'] === 422 && str_contains($r['body'], 'Alasan penggantian wajib'), 'Penggantian tanpa alasan → 422');
$r = $petugas->req($halRingkasan, ['alasan' => 'Perbaikan isi setelah kontrol'] + $ganti);
$r = $petugas->req($halRingkasan, ['_csrf' => $nilaiInput($r['body'], '_csrf'), 'aksi' => 'terbit', 'konfirmasi' => '1', 'pratinjau_hash' => $nilaiInput($r['body'], 'pratinjau_hash'),
    'idempotency_key' => $nilaiInput($r['body'], 'idempotency_key'), 'alasan' => 'Perbaikan isi setelah kontrol'] + array_intersect_key($ganti, array_flip(['kunjungan_versi', 'revisi_ringkasan', 'ringkasan', 'tindakan_umum', 'tindak_lanjut_wali'])));
$assert(in_array($r['status'], [302, 303], true) && (int) $satu("SELECT versi_berlaku FROM kesehatan_ringkasan WHERE id = $rid") === 2, 'Penggantian web beralasan → versi 2');
$d = $k['sbx_v5_wali_2']->req('/portal/kesehatan_wali.php?id=' . $rid);
$assert(str_contains($d['body'], "$penanda-V2") && !str_contains($d['body'], "$penanda-RINGKASAN") && str_contains($d['body'], 'diperbarui pesantren') && !str_contains($d['body'], 'Perbaikan isi setelah kontrol'),
    'Wali melihat versi 2 saja (tanpa alasan internal)');
$r = $petugas->req($halRingkasan);
$assert(str_contains($r['body'], 'Diganti dengan versi baru') && str_contains($r['body'], 'Perbaikan isi setelah kontrol') && str_contains($r['body'], "$penanda-RINGKASAN"), 'Petugas menelusuri versi lama dan alasan');
$r = $petugas->req($halRingkasan, ['_csrf' => $petugas->csrf($halRingkasan), 'aksi' => 'tarik', 'revisi_ringkasan' => '2', 'alasan' => '', 'idempotency_key' => $kunci('m-g-t')]);
$assert($r['status'] === 422 && (int) $satu("SELECT revisi FROM kesehatan_ringkasan WHERE id = $rid") === 2, 'Tarik web tanpa alasan → 422');
$r = $petugas->req($halRingkasan, ['_csrf' => $petugas->csrf($halRingkasan), 'aksi' => 'tarik', 'revisi_ringkasan' => '2', 'alasan' => 'Ditarik untuk disusun ulang', 'idempotency_key' => $kunci('m-g-t')]);
$d = $k['sbx_v5_wali_1']->req('/portal/kesehatan_wali.php?id=' . $rid);
$assert(in_array($r['status'], [302, 303], true) && str_contains($d['body'], 'telah ditarik oleh pesantren') && !str_contains($d['body'], "$penanda-V2"), 'Tarik web beralasan → wali melihat "ditarik" tanpa isi');
$assert($hitung('notifikasi_outbox', "event_key LIKE 'v5:kesehatan:ringkasan:$rid:%'") === 5, 'Notifikasi ringkasan ini: v1 (3) + v2 (2); penarikan tanpa notifikasi');

// ===================================================================== M-H
echo PHP_EOL . '=== M-H. no-store dan log server ===' . PHP_EOL;
foreach ([[$petugas, $halRingkasan], [$k['sbx_v5_wali_1'], '/portal/kesehatan_wali.php?id=' . $rid], [$k['sbx_v5_wali_1'], '/portal/kesehatan_wali.php']] as [$kl, $p]) {
    $assert(str_contains(strtolower($kl->req($p)['header']), 'cache-control: private, no-store'), "no-store: $p");
}
$isiLog = (string) file_get_contents($log);
$assert(!preg_match('/PHP (Fatal|Warning|Notice|Deprecated|Parse)/', $isiLog), 'Log server: 0 galat PHP');
$assert(!str_contains($isiLog, $penanda) && !str_contains($isiLog, 'SBX V5 Wali Santri') && !str_contains($isiLog, '08120000530'), 'Log server: 0 isi ringkasan/klinis, nama santri, atau telepon');
$outbox = json_encode($conn->query("SELECT judul, isi, data_json FROM notifikasi_outbox WHERE event_type LIKE 'v5\\_%'")->fetch_all(MYSQLI_ASSOC), JSON_UNESCAPED_UNICODE);
$assert(!str_contains($outbox, $penanda) && !str_contains($outbox, 'SBX V5') && !str_contains($outbox, '0812'), 'Outbox V5 sesudah alur web/API: 0 data terlarang');
exec('DB_NAME=' . escapeshellarg($db) . ' php ' . escapeshellarg($root . '/bin/v5_phase3_verify.php') . ' 2>&1', $verif, $kodeVerif);
$assert($kodeVerif === 0, 'Verifier 026 lulus sesudah alur HTTP');

echo PHP_EOL . 'Lulus: ' . $lulus . ' · Gagal: ' . count($gagal) . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
