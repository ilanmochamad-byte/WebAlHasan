<?php

declare(strict_types=1);

/**
 * Uji server PRD V7 Fase 1 — website dan API pengajuan wali lewat server PHP
 * lokal di atas DB uji `tests/v7_phase1_db.sh`.
 *
 *   V7F1_DB=test_v7_f1_http_test bash tests/v7_phase1_db.sh
 *   V7F1_DB=test_v7_f1_http_test php tests/v7_phase1_http.php
 *
 *   H-A  wali pengaju lewat formulir web: satu pengajuan, klik ganda (POST ulang
 *        kunci sama) tidak menggandakan, CSRF wajib, GET tidak memutasi,
 *        benturan → 409 dengan isian dipertahankan
 *   H-B  hak halaman per akun: wali kedua ringkas tanpa tombol mutasi; wali
 *        asing/murobi/pembimbing lain 403/404; tanpa sesi → masuk
 *   H-C  admin: antrean pengecualian, penetapan pembimbing sah (CSRF, versi,
 *        kunci), kiriman ulang, non-admin ditolak
 *   H-D  API: akses, buat 201/200 replay/409, proyeksi per peran, 401/403/404,
 *        no-store; permukaan V2 tidak memuat tahap V7
 *   H-E  pencabutan relasi/penugasan saat sesi web dan token masih aktif
 *   H-F  navigasi per peran; log server tanpa galat PHP; verifier
 *
 * Mengubah data uji; bangun ulang DB sebelum mengulang.
 */

$root = dirname(__DIR__);
$db = getenv('V7F1_DB') ?: 'test_v7_f1_http_test';
$port = (int) (getenv('V7F1_PORT') ?: 8997);
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

$log = tempnam(sys_get_temp_dir(), 'v7f1-server-');
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
        $this->jar = tempnam(sys_get_temp_dir(), 'v7f1-jar-');
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
    $r = $api('POST', '/auth/login', null, ['username' => $user, 'password' => 'Sandbox#123', 'device_name' => 'uji-v7']);

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
$sSatu = $sid('V7S0001');
$sNol = $sid('V7S0002');
$sDua = $sid('V7S0003');
$pengurusDua = (int) $satu("SELECT pengurus_id FROM users WHERE username = 'sbx_v7_pembimbing_dua'");
$pengurusSatu = (int) $satu("SELECT pengurus_id FROM users WHERE username = 'sbx_v7_pembimbing_satu'");
$jumlahWali = static fn (): int => (int) $satu("SELECT COUNT(*) FROM izin_pengajuan WHERE asal_pengajuan = 'Wali'");
$assert(min($sSatu, $sNol, $sDua, $pengurusDua) > 0 && $jumlahWali() === 0, 'Fixture V7 tersedia; belum ada pengajuan wali');

$akun = ['sbx_admin', 'sbx_v7_wali_a', 'sbx_v7_wali_b', 'sbx_ortu_b', 'sbx_v7_pembimbing_satu', 'sbx_v7_pembimbing_dua', 'sbx_murobi_a'];
$k = [];
foreach ($akun as $u) {
    $k[$u] = $klien($u);
}

// ===================================================================== H-A
echo PHP_EOL . '=== H-A. Formulir wali ===' . PHP_EOL;
$r = $k['sbx_v7_wali_a']->req('/portal/izin_wali.php');
$assert($r['status'] === 200 && str_contains($r['body'], 'izin_wali_buat.php') && str_contains($r['header'], 'no-store'), 'Wali pengaju: halaman Pengajuan wali 200, tombol Ajukan izin, no-store');
$f = $formulir($k['sbx_v7_wali_a'], '/portal/izin_wali_buat.php');
$assert($f['status'] === 200 && $f['kunci'] !== '' && str_contains($f['body'], 'SBX V7 Santri Satu') && !str_contains($f['body'], 'SBX V7 Santri Nonaktif'),
    'Formulir: kunci idempotensi tersembunyi; pilihan anak aktif saja');
$post = ['_csrf' => $f['csrf'], 'mode' => 'orang_tua', 'idempotency_key' => $f['kunci'], 'santri_id' => $sSatu, 'tgl_izin' => '2026-11-02', 'tgl_kembali' => '2026-11-03',
    'alasan' => 'Menghadiri pernikahan kakak HTTP', 'catatan' => 'Dijemput ayah HTTP'];
$r = $k['sbx_v7_wali_a']->req('/portal/izin_wali_buat.php', array_merge($post, ['_csrf' => 'salah']));
$assert($r['status'] >= 400 && $jumlahWali() === 0, 'POST tanpa CSRF sah ditolak (' . $r['status'] . ') tanpa baris');
// Alasan pada uji GET sengaja berbeda: server pengembangan mencatat URL lengkap.
$r = $k['sbx_v7_wali_a']->req('/portal/izin_wali_buat.php?' . http_build_query(array_merge($post, ['alasan' => 'Uji GET', 'catatan' => ''])));
$assert($jumlahWali() === 0, 'GET berparameter tidak memutasi');
$r1 = $k['sbx_v7_wali_a']->req('/portal/izin_wali_buat.php', $post);
$r2 = $k['sbx_v7_wali_a']->req('/portal/izin_wali_buat.php', $post);
$id1 = (int) $satu("SELECT id FROM izin_pengajuan WHERE asal_pengajuan = 'Wali' ORDER BY id LIMIT 1");
$assert($r1['status'] === 303 && $r2['status'] === 303 && $r1['location'] === $r2['location'] && str_contains((string) $r1['location'], 'izin_wali_detail.php?id=' . $id1) && $jumlahWali() === 1,
    'Kirim + kirim ulang (klik ganda) → dua 303 ke detail yang sama, satu baris');
$r = $k['sbx_v7_wali_a']->req('/portal/izin_wali_detail.php?id=' . $id1);
$assert($r['status'] === 200 && str_contains($r['body'], 'Menghadiri pernikahan kakak HTTP') && str_contains($teks($r['body']), 'Menunggu tinjauan pembimbing')
    && str_contains($teks($r['body']), 'sudah tersimpan sebelumnya'), 'Detail pengaju: alasan, tahap Menunggu tinjauan pembimbing, pesan kiriman ulang');
$f2 = $formulir($k['sbx_v7_wali_a'], '/portal/izin_wali_buat.php');
$r = $k['sbx_v7_wali_a']->req('/portal/izin_wali_buat.php', array_merge($post, ['_csrf' => $f2['csrf'], 'idempotency_key' => $f2['kunci'], 'tgl_izin' => '2026-11-03', 'tgl_kembali' => '2026-11-04', 'alasan' => 'Rentang bentrok HTTP']));
$assert($r['status'] === 409 && str_contains($r['body'], 'Rentang bentrok HTTP') && str_contains($r['body'], 'value="' . $f2['kunci'] . '"') && $jumlahWali() === 1,
    'Benturan tanggal → 409, isian dan kunci dipertahankan, tanpa baris');

// ===================================================================== H-B
echo PHP_EOL . '=== H-B. Hak halaman per akun ===' . PHP_EOL;
$r = $k['sbx_v7_wali_b']->req('/portal/izin_wali_detail.php?id=' . $id1);
$assert($r['status'] === 200 && !str_contains($r['body'], 'pernikahan kakak HTTP') && !str_contains($r['body'], 'Dijemput ayah HTTP') && !str_contains($r['body'], 'name="idempotency_key"')
    && !str_contains($r['body'], 'tetapkan_pembimbing')
    && str_contains($teks($r['body']), 'dibuat wali lain'), 'Wali kedua: status ringkas, tanpa alasan/catatan, tanpa formulir mutasi');
$r = $k['sbx_v7_wali_b']->req('/portal/izin_wali.php');
$assert($r['status'] === 200 && str_contains($r['body'], 'izin_wali_detail.php?id=' . $id1) && !str_contains($r['body'], 'pernikahan kakak HTTP'), 'Daftar wali kedua: item ringkas');
$layar = [
    '/portal/izin_wali_detail.php?id=' . $id1 => ['sbx_ortu_b' => 404, 'sbx_v7_pembimbing_dua' => 404, 'sbx_murobi_a' => 404, 'sbx_v7_pembimbing_satu' => 200, 'sbx_admin' => 200],
    '/portal/izin_wali_detail.php?id=999999' => ['sbx_v7_wali_a' => 404, 'sbx_admin' => 404],
    '/portal/izin_wali.php' => ['sbx_murobi_a' => 403, 'sbx_ortu_b' => 200, 'sbx_v7_pembimbing_dua' => 200],
    '/portal/izin_wali_buat.php' => ['sbx_admin' => 403, 'sbx_v7_pembimbing_satu' => 403, 'sbx_murobi_a' => 403],
];
foreach ($layar as $alamat => $harap) {
    foreach ($harap as $u => $status) {
        $r = $k[$u]->req($alamat);
        $assert($r['status'] === $status, "$alamat $u → $status" . ($r['status'] === $status ? '' : ' (dapat ' . $r['status'] . ')'));
    }
}
$r = $k['sbx_ortu_b']->req('/portal/izin_wali.php');
$assert(!str_contains($r['body'], 'izin_wali_detail.php?id=' . $id1), 'Wali asing: daftar tidak memuat pengajuan anak lain');
$fAsing = $formulir($k['sbx_ortu_b'], '/portal/izin_wali_buat.php');
$r = $k['sbx_ortu_b']->req('/portal/izin_wali_buat.php', ['_csrf' => $fAsing['csrf'], 'mode' => 'orang_tua', 'idempotency_key' => 'v7-http-asing-01', 'santri_id' => $sSatu,
    'tgl_izin' => '2026-12-01', 'tgl_kembali' => '2026-12-01', 'alasan' => 'Wali asing mencoba']);
$assert($r['status'] === 404 && $jumlahWali() === 1, 'Wali asing POST untuk anak lain → 404 tanpa baris');
$r = $k['sbx_v7_pembimbing_satu']->req('/portal/izin_wali.php');
$assert($r['status'] === 200 && str_contains($r['body'], 'izin_wali_detail.php?id=' . $id1) && str_contains($r['body'], 'pernikahan kakak HTTP'), 'Pembimbing tujuan: antrean memuat item dengan alasan');
$r = $k['sbx_v7_pembimbing_dua']->req('/portal/izin_wali.php');
$assert(!str_contains($r['body'], 'izin_wali_detail.php?id=' . $id1), 'Pembimbing lain: antrean kosong');
$r = $k['sbx_murobi_a']->req('/portal/izin_antrean.php');
$assert($r['status'] === 200 && !str_contains($r['body'], 'pernikahan kakak HTTP') && !str_contains($r['body'], 'id=' . $id1 . '"'), 'Antrean murobi V2 tidak memuat pengajuan wali');
$r = $k['sbx_admin']->req('/portal/izin_detail.php?id=' . $id1);
$assert($r['status'] === 403, 'Detail V2 untuk ID tahap pembimbing → 403 (dibaca lewat halaman V7)');
$r = (new Klien($base))->req('/portal/izin_wali.php');
$assert(in_array($r['status'], [302, 303], true), 'Tanpa sesi → dialihkan ke halaman masuk');

// ===================================================================== H-C
echo PHP_EOL . '=== H-C. Admin: antrean dan penetapan ===' . PHP_EOL;
$fN = $formulir($k['sbx_v7_wali_a'], '/portal/izin_wali_buat.php');
$k['sbx_v7_wali_a']->req('/portal/izin_wali_buat.php', ['_csrf' => $fN['csrf'], 'mode' => 'orang_tua', 'idempotency_key' => $fN['kunci'], 'santri_id' => $sDua,
    'tgl_izin' => '2026-11-05', 'tgl_kembali' => '2026-11-05', 'alasan' => 'Kontrol gigi HTTP']);
$idDua = (int) $satu("SELECT id FROM izin_pengajuan WHERE asal_pengajuan = 'Wali' AND santri_id = $sDua");
$assert((string) $satu("SELECT status FROM izin_pengajuan WHERE id = $idDua") === 'Perlu Penetapan Pembimbing', 'Santri dua kandidat → Perlu Penetapan Pembimbing');
$r = $k['sbx_admin']->req('/portal/izin_wali.php');
$assert($r['status'] === 200 && str_contains($r['body'], 'izin_wali_detail.php?id=' . $idDua) && !str_contains($r['body'], 'izin_wali_detail.php?id=' . $id1 . '"'), 'Antrean admin bawaan: hanya pengecualian');
$r = $k['sbx_admin']->req('/portal/izin_wali.php?antrean=0');
$assert(str_contains($r['body'], 'izin_wali_detail.php?id=' . $id1) && str_contains($r['body'], 'izin_wali_detail.php?id=' . $idDua), 'Semua pengajuan wali: keduanya');
$fA = $formulir($k['sbx_admin'], '/portal/izin_wali_detail.php?id=' . $idDua);
$assert($fA['versi'] === '1' && substr_count($fA['body'], 'name="pengurus_id"') === 1 && str_contains($fA['body'], 'SBX V7 Pembimbing Satu') && str_contains($fA['body'], 'SBX V7 Pembimbing Dua'),
    'Formulir penetapan: versi 1, dua kandidat sah');
$postA = ['_csrf' => $fA['csrf'], 'aksi' => 'tetapkan_pembimbing', 'mode' => 'admin', 'pengajuan_id' => $idDua, 'version' => '1', 'idempotency_key' => $fA['kunci'],
    'pengurus_id' => $pengurusDua, 'alasan' => 'Pembimbing kelas lebih dekat'];
$r = $k['sbx_admin']->req('/portal/izin_wali_detail.php?id=' . $idDua, array_merge($postA, ['_csrf' => 'salah']));
$assert($r['status'] >= 400 && (int) $satu('SELECT COUNT(*) FROM izin_pembimbing_penetapan') === 0, 'Penetapan tanpa CSRF sah ditolak');
$fP = $formulir($k['sbx_v7_pembimbing_satu'], '/portal/izin_wali.php');
$r = $k['sbx_v7_pembimbing_satu']->req('/portal/izin_wali_detail.php?id=' . $idDua, array_merge($postA, ['_csrf' => $fP['csrf'], 'mode' => 'pengurus']));
$assert($r['status'] === 403 && (int) $satu('SELECT COUNT(*) FROM izin_pembimbing_penetapan') === 0, 'Pembimbing POST penetapan → 403');
$r = $k['sbx_admin']->req('/portal/izin_wali_detail.php?id=' . $idDua, array_merge($postA, ['pengurus_id' => (int) $satu("SELECT id FROM pengurus WHERE nomor_identitas = 'SBX-V7-P-003'")]));
$assert($r['status'] === 422 && (int) $satu('SELECT COUNT(*) FROM izin_pembimbing_penetapan') === 0, 'Admin memilih pengurus bukan pembimbing sah → 422');
$r1 = $k['sbx_admin']->req('/portal/izin_wali_detail.php?id=' . $idDua, $postA);
$r2 = $k['sbx_admin']->req('/portal/izin_wali_detail.php?id=' . $idDua, $postA);
$assert($r1['status'] === 303 && $r2['status'] === 303 && (int) $satu("SELECT COUNT(*) FROM izin_pembimbing_penetapan WHERE pengajuan_id = $idDua") === 1
    && (int) $satu("SELECT version FROM izin_pengajuan WHERE id = $idDua") === 2 && (int) $satu("SELECT pembimbing_pengurus_id FROM izin_pengajuan WHERE id = $idDua") === $pengurusDua,
    'Penetapan + kiriman ulang → satu baris penetapan, versi 2, Pembimbing Dua');
$r = $k['sbx_admin']->req('/portal/izin_wali_detail.php?id=' . $idDua, array_merge($postA, ['idempotency_key' => 'v7-http-adm-lama-1']));
$assert($r['status'] === 409, 'Formulir versi lama dengan kunci baru → 409');
$r = $k['sbx_v7_pembimbing_dua']->req('/portal/izin_wali.php');
$assert(str_contains($r['body'], 'izin_wali_detail.php?id=' . $idDua), 'Sesudah penetapan: antrean Pembimbing Dua memuat item');
$r = $k['sbx_admin']->req('/portal/izin_wali_detail.php?id=' . $idDua);
$assert(str_contains($teks($r['body']), 'Riwayat penetapan pembimbing') && str_contains($r['body'], 'Pembimbing kelas lebih dekat'), 'Detail admin memuat riwayat penetapan beralasan');

// ===================================================================== H-D
echo PHP_EOL . '=== H-D. API ===' . PHP_EOL;
$t = [];
foreach (['sbx_admin', 'sbx_v7_wali_a', 'sbx_v7_wali_b', 'sbx_ortu_b', 'sbx_v7_pembimbing_satu', 'sbx_v7_pembimbing_dua', 'sbx_murobi_a'] as $u) {
    $t[$u] = $token($u);
}
$assert(!in_array('', $t, true), 'Token API seluruh akun uji');
$r = $api('GET', '/v7/izin/akses', $t['sbx_v7_wali_a']);
$assert($r['status'] === 200 && $r['json']['data']['wali'] === true && $r['json']['data']['boleh_mengajukan'] === true && str_contains($r['header'], 'no-store'), 'GET akses wali: peran wali, boleh mengajukan, no-store');
$assert($api('GET', '/v7/izin/akses', null)['status'] === 401, 'Tanpa token → 401');
$anak = $api('GET', '/v7/izin/wali/anak', $t['sbx_v7_wali_a'])['json']['data']['items'] ?? [];
$assert(count($anak) === 4 && !in_array($sid('V7S0005'), array_column($anak, 'id'), true), 'Daftar anak API: empat anak aktif, tanpa santri nonaktif');
$body = ['santri_id' => $sNol, 'tgl_izin' => '2026-11-07', 'tgl_kembali' => '2026-11-08', 'alasan' => 'Berobat ke dokter API'];
$r1 = $api('POST', '/v7/izin/wali/pengajuan', $t['sbx_v7_wali_a'], $body, ['Idempotency-Key: v7-http-api-0001']);
$r2 = $api('POST', '/v7/izin/wali/pengajuan', $t['sbx_v7_wali_a'], $body, ['Idempotency-Key: v7-http-api-0001']);
$r3 = $api('POST', '/v7/izin/wali/pengajuan', $t['sbx_v7_wali_a'], array_merge($body, ['alasan' => 'Isi berbeda API']), ['Idempotency-Key: v7-http-api-0001']);
$idApi = (int) ($r1['json']['data']['id'] ?? 0);
$assert($r1['status'] === 201 && $r2['status'] === 200 && ($r2['json']['data']['id'] ?? 0) === $idApi && $r2['json']['data']['idempotent_replay'] === true
    && $r3['status'] === 409 && $r3['json']['error']['code'] === 'CONFLICT' && $jumlahWali() === 3, 'API buat: 201, replay 200 ID sama, kunci sama isi beda 409, satu baris');
$assert($api('POST', '/v7/izin/wali/pengajuan', $t['sbx_ortu_b'], array_merge($body, ['tgl_izin' => '2026-12-20', 'tgl_kembali' => '2026-12-20']), ['Idempotency-Key: v7-http-api-asing'])['status'] === 404
    && $api('POST', '/v7/izin/wali/pengajuan', $t['sbx_v7_pembimbing_satu'], $body, ['Idempotency-Key: v7-http-api-pb'])['status'] === 403
    && $api('POST', '/v7/izin/wali/pengajuan', $t['sbx_v7_wali_a'], ['santri_id' => $sNol], ['Idempotency-Key: v7-http-api-0002'])['status'] === 422,
    'API buat: wali asing 404, pengurus 403, isian kurang 422');
$dB = $api('GET', '/v7/izin/pengajuan/' . $id1, $t['sbx_v7_wali_b']);
$assert($dB['status'] === 200 && $dB['json']['data']['pengajuan']['ringkas'] === true && !str_contains($dB['body'], 'pernikahan') && $dB['json']['data']['riwayat'] === [],
    'API detail wali kedua: ringkas tanpa alasan/riwayat');
$dA = $api('GET', '/v7/izin/pengajuan/' . $id1, $t['sbx_v7_wali_a']);
$assert($dA['json']['data']['pengajuan']['alasan'] === 'Menghadiri pernikahan kakak HTTP', 'API detail wali pengaju: alasan lengkap');
foreach (['sbx_ortu_b' => 404, 'sbx_v7_pembimbing_dua' => 404, 'sbx_murobi_a' => 404, 'sbx_v7_pembimbing_satu' => 200, 'sbx_admin' => 200] as $u => $s) {
    $r = $api('GET', '/v7/izin/pengajuan/' . $id1, $t[$u]);
    $assert($r['status'] === $s, "API detail $u → $s" . ($r['status'] === $s ? '' : ' (dapat ' . $r['status'] . ')'));
}
$assert($api('GET', '/v7/izin/pengajuan/999999', $t['sbx_admin'])['status'] === 404, 'API detail ID tebakan → 404');
$assert($api('GET', '/v7/izin/pembimbing/antrean', $t['sbx_murobi_a'])['status'] === 403 && $api('GET', '/v7/izin/wali/pengajuan', $t['sbx_murobi_a'])['status'] === 403
    && $api('GET', '/v7/izin/admin/pengajuan', $t['sbx_v7_pembimbing_satu'])['status'] === 403, 'API daftar: murobi 403, pembimbing ke pemantauan admin 403');
$antrean = $api('GET', '/v7/izin/pembimbing/antrean', $t['sbx_v7_pembimbing_satu'])['json']['data'];
$assert($antrean['total'] === 1 && $antrean['items'][0]['id'] === $id1, 'API antrean Pembimbing Satu: satu item');
$adm = $api('GET', '/v7/izin/admin/pengajuan?antrean=1', $t['sbx_admin'])['json']['data'];
$assert($adm['total'] === 1 && $adm['items'][0]['id'] === $idApi, 'API antrean admin: pengajuan santri Nol (nol kandidat)');
$kand = $api('GET', '/v7/izin/pengajuan/' . $idApi . '/kandidat-pembimbing', $t['sbx_admin']);
$assert($kand['status'] === 200 && $kand['json']['data']['items'] === [], 'API kandidat santri Nol: kosong');
$r = $api('POST', '/v7/izin/pengajuan/' . $idApi . '/penetapan-pembimbing', $t['sbx_admin'], ['pengurus_id' => $pengurusSatu, 'alasan' => 'Coba bukan pembimbing', 'version' => 1], ['Idempotency-Key: v7-http-api-tetap-1']);
$assert($r['status'] === 422, 'API penetapan pembimbing tidak sah → 422');
$r = $api('POST', '/v7/izin/pengajuan/' . $idApi . '/penetapan-pembimbing', $t['sbx_v7_wali_a'], ['pengurus_id' => $pengurusSatu, 'alasan' => 'Wali coba', 'version' => 1], ['Idempotency-Key: v7-http-api-tetap-2']);
$assert($r['status'] === 403, 'API penetapan oleh wali → 403');
$v2Admin = $api('GET', '/izin/pengajuan/' . $id1, $t['sbx_admin']);
$v2Ortu = $api('GET', '/izin/pengajuan?per_page=100', $t['sbx_v7_wali_b']);
$assert($v2Admin['status'] === 403 && !str_contains($v2Ortu['body'], 'pernikahan') && !in_array($id1, array_column($v2Ortu['json']['data']['items'] ?? [], 'id'), true),
    'API V2: detail admin untuk tahap V7 403; daftar orang tua V2 tidak memuat pengajuan wali');
$assert($api('GET', '/izin/pengajuan?q=pernikahan', $t['sbx_v7_wali_b'])['json']['data']['pagination']['total'] === 0, 'API V2 orang tua: pencarian alasan wali lain kosong');

// ===================================================================== H-E
echo PHP_EOL . '=== H-E. Pencabutan pada sesi/token lama ===' . PHP_EOL;
$relasiB = (int) $satu("SELECT id FROM santri_wali WHERE wali_id = (SELECT wali_id FROM users WHERE username = 'sbx_v7_wali_b') AND santri_id = $sSatu");
$jalankan("UPDATE santri_wali SET archived_at = NOW() WHERE id = $relasiB");
$assert($k['sbx_v7_wali_b']->req('/portal/izin_wali_detail.php?id=' . $id1)['status'] === 404 && $api('GET', '/v7/izin/pengajuan/' . $id1, $t['sbx_v7_wali_b'])['status'] === 404
    && $api('GET', '/v7/izin/wali/pengajuan', $t['sbx_v7_wali_b'])['json']['data']['total'] === 0, 'Relasi wali kedua dicabut: sesi web dan token lama → 404, daftar kosong');
$jalankan("UPDATE santri_wali SET archived_at = NULL WHERE id = $relasiB");
$pa = (int) $satu("SELECT id FROM pembimbing_assignments WHERE pengurus_id = $pengurusSatu AND kamar_id = (SELECT id FROM kamar WHERE nama_kamar = 'SBX V7 Kamar Satu')");
$jalankan("UPDATE pembimbing_assignments SET is_active = 0 WHERE id = $pa");
$assert($k['sbx_v7_pembimbing_satu']->req('/portal/izin_wali_detail.php?id=' . $id1)['status'] === 404 && $api('GET', '/v7/izin/pembimbing/antrean', $t['sbx_v7_pembimbing_satu'])['json']['data']['total'] === 0
    && $api('GET', '/v7/izin/admin/pengajuan?antrean=1', $t['sbx_admin'])['json']['data']['total'] === 2, 'Penugasan pembimbing dicabut: sesi/token lama tertutup; admin melihat pengecualian baru');
$jalankan("UPDATE pembimbing_assignments SET is_active = 1 WHERE id = $pa");

// ===================================================================== H-F
echo PHP_EOL . '=== H-F. Navigasi, log, verifier ===' . PHP_EOL;
$assert(str_contains($k['sbx_v7_wali_a']->req('/portal/izin.php')['body'], 'izin_wali.php'), 'Orang tua: halaman Izin anak memuat tautan Pengajuan wali');
$assert(str_contains($k['sbx_v7_pembimbing_satu']->req('/portal/izin_ringkasan.php')['body'], 'izin_wali.php'), 'Pengurus: menu Perizinan memuat tab Pengajuan wali');
$assert(!str_contains($k['sbx_murobi_a']->req('/portal/izin_antrean.php')['body'], 'izin_wali.php'), 'Murobi: tanpa tautan Pengajuan wali');
usleep(300000);
$isiLog = (string) file_get_contents($log);
$assert(!preg_match('/PHP (Warning|Notice|Fatal|Deprecated)|Uncaught/', $isiLog), 'Log server tanpa galat/peringatan PHP');
$assert(!str_contains($isiLog, 'pernikahan') && !str_contains($isiLog, 'Berobat ke dokter'), 'Log server tanpa alasan izin');
exec('DB_NAME=' . escapeshellarg($db) . ' php ' . escapeshellarg($root . '/bin/v7_phase1_verify.php') . ' 2>&1', $out, $kodeVerify);
$assert($kodeVerify === 0, 'Verifier V7 lulus sesudah uji HTTP');
@unlink($log);

echo PHP_EOL . 'Lulus: ' . $lulus . ' · Gagal: ' . count($gagal) . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
