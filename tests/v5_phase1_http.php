<?php

declare(strict_types=1);

/**
 * Uji server PRD V5 Fase 1 — website dan API kesehatan santri lewat server PHP
 * lokal di atas DB uji `tests/v5_phase1_db.sh`.
 *
 *   V5F1_DB=test_v5_f1_http_test bash tests/v5_phase1_db.sh
 *   V5F1_DB=test_v5_f1_http_test php tests/v5_phase1_http.php
 *
 *   H-A  hak setiap halaman per akun (server, bukan menu); tanpa sesi → masuk;
 *   H-B  admin menugaskan pengurus kesehatan lewat Pusat Penugasan (POST+CSRF);
 *   H-C  petugas mencatat lewat formulir web: tepat satu kunjungan, klik ganda
 *        (POST ulang kunci sama) tidak menggandakan, CSRF wajib, GET tidak
 *        memutasi; detail dibaca ulang;
 *   H-D  API: token petugas mencatat (Idempotency-Key), replay, kunci sama isi
 *        beda 409, detail/daftar; admin/guru/wali/pengurus tanpa penugasan
 *        ditolak mutasi; tanpa token 401; no-store;
 *   H-E  admin membaca detail + riwayat akses (web dan API, kanal tercatat);
 *   H-F  penugasan dinonaktifkan admin saat sesi web/token petugas masih
 *        aktif → tulis berikutnya ditolak tanpa baris baru;
 *   H-G  akun dinonaktifkan saat sesi aktif → ditolak;
 *   H-H  log server tanpa galat/peringatan PHP dan tanpa isi klinis;
 *        audit_logs/kesehatan_akses_log tanpa isi klinis.
 *
 * Mengubah data uji; bangun ulang DB sebelum mengulang.
 */

$root = dirname(__DIR__);
$db = getenv('V5F1_DB') ?: 'test_v5_f1_http_test';
$port = (int) (getenv('V5F1_PORT') ?: 8995);
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

$log = tempnam(sys_get_temp_dir(), 'v5f1-server-');
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
        $this->jar = tempnam(sys_get_temp_dir(), 'v5f1-jar-');
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
    $r = $api('POST', '/auth/login', null, ['username' => $user, 'password' => 'Sandbox#123', 'device_name' => 'uji-v5']);

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
$jumlah = static fn (): int => (int) $satu('SELECT COUNT(*) FROM kesehatan_kunjungan');
$penanda = 'V5HTTP-' . bin2hex(random_bytes(4));
$santri = (int) $satu("SELECT id FROM santri WHERE nis='V5S0001'");
$tahun = (int) $satu("SELECT id FROM tahun_ajaran WHERE status='Aktif' AND archived_at IS NULL LIMIT 1");
$pengurusPetugas = (int) $satu("SELECT pengurus_id FROM users WHERE username='sbx_v5_petugas'");
$assert($santri > 0 && $pengurusPetugas > 0 && $jumlah() === 0, 'Fixture V5 Fase 1 tersedia dan belum ada kunjungan');

$akun = ['sbx_admin', 'sbx_v5_petugas', 'sbx_v5_petugas_b', 'sbx_v5_petugas_c', 'sbx_v5_admin_petugas', 'sbx_pengurus_biasa', 'sbx_pengurus_a', 'sbx_guru_biasa', 'sbx_murobi_c', 'sbx_ortu_a'];
$k = [];
foreach ($akun as $u) {
    $k[$u] = $klien($u);
}

// ===================================================================== H-B
echo PHP_EOL . '=== H-B. Admin menugaskan petugas kesehatan (Pusat Penugasan) ===' . PHP_EOL;
$r = $k['sbx_admin']->req('/admin/admin_penugasan.php?jenis=kesehatan&tambah=1');
$assert($r['status'] === 200 && str_contains($r['body'], 'Petugas Kesehatan') && str_contains($teks($r['body']), 'Cakupan: seluruh santri aktif'), 'Formulir penugasan Petugas Kesehatan tampil dengan cakupan seluruh santri aktif');
$assert($k['sbx_v5_petugas']->req('/portal/kesehatan_buat.php')['status'] === 403, 'Sebelum ditugaskan, pengurus ditolak membuka formulir kunjungan (403)');
$csrf = $k['sbx_admin']->csrf('/admin/admin_penugasan.php?jenis=kesehatan');
$r = $k['sbx_admin']->req('/admin/admin_penugasan.php?jenis=kesehatan', ['_csrf' => $csrf, 'jenis' => 'kesehatan', 'action' => 'buat',
    'pengurus_id' => $pengurusPetugas, 'tahun_ajaran_id' => $tahun, 'tanggal_mulai' => date('Y-m-d'), 'catatan' => 'Uji HTTP']);
$tugas = (int) $satu("SELECT id FROM kesehatan_assignments WHERE pengurus_id = $pengurusPetugas AND is_active = 1");
$assert(in_array($r['status'], [302, 303], true) && $tugas > 0, 'Admin menyimpan penugasan lewat POST + CSRF');
$r = $k['sbx_admin']->req('/admin/admin_penugasan.php?jenis=kesehatan&status=aktif');
$assert($r['status'] === 200 && str_contains($r['body'], 'SBX V5 Petugas Klinik') && str_contains($r['body'], 'Seluruh santri aktif') && str_contains($r['body'], 'Mencatat kunjungan klinik'), 'Daftar penugasan kesehatan memuat petugas, cakupan, dan hak');
$r = $k['sbx_pengurus_a']->req('/admin/admin_penugasan.php?jenis=kesehatan', ['_csrf' => $k['sbx_pengurus_a']->csrf(), 'jenis' => 'kesehatan', 'action' => 'buat',
    'pengurus_id' => (int) $satu("SELECT pengurus_id FROM users WHERE username='sbx_pengurus_biasa'"), 'tahun_ajaran_id' => $tahun, 'tanggal_mulai' => date('Y-m-d')]);
$assert((int) $satu("SELECT COUNT(*) FROM kesehatan_assignments a JOIN users u ON u.pengurus_id = a.pengurus_id WHERE u.username = 'sbx_pengurus_biasa'") === 0, 'Non-admin tidak dapat membuat penugasan kesehatan (status ' . $r['status'] . ')');

// ===================================================================== H-A
echo PHP_EOL . '=== H-A. Hak setiap halaman (server) ===' . PHP_EOL;
$layar = [
    '/portal/kesehatan.php' => ['sbx_admin' => 200, 'sbx_v5_petugas' => 200, 'sbx_v5_admin_petugas' => 200, 'sbx_pengurus_biasa' => 403, 'sbx_pengurus_a' => 403, 'sbx_guru_biasa' => 403, 'sbx_murobi_c' => 403, 'sbx_ortu_a' => 403],
    '/portal/kesehatan_buat.php' => ['sbx_v5_petugas' => 200, 'sbx_admin' => 403, 'sbx_v5_admin_petugas' => 403, 'sbx_pengurus_biasa' => 403, 'sbx_guru_biasa' => 403, 'sbx_ortu_a' => 403],
];
foreach ($layar as $alamat => $harap) {
    foreach ($harap as $u => $status) {
        $r = $k[$u]->req($alamat);
        $assert($r['status'] === $status, "$alamat $u → $status" . ($r['status'] === $status ? '' : ' (dapat ' . $r['status'] . ')'));
    }
}
$r = (new Klien($base))->req('/portal/kesehatan.php');
$assert(in_array($r['status'], [302, 303], true) && str_contains((string) $r['location'], 'pesan=sesi'), 'Tanpa sesi → dialihkan ke halaman masuk');
$r = $k['sbx_v5_petugas']->req('/portal/kesehatan.php');
$assert(str_contains($r['header'], 'no-store') && str_contains($r['body'], 'Catat kunjungan'), 'Daftar petugas: no-store dan tombol Catat kunjungan');
$r = $k['sbx_admin']->req('/portal/kesehatan.php');
$assert(!str_contains($r['body'], 'kesehatan_buat.php') && str_contains($teks($r['body']), 'Hanya baca'), 'Daftar admin: tanpa tombol/tautan pencatatan, berlabel hanya baca');
$nav = static fn (Klien $kl): bool => str_contains($kl->req('/portal/index.php')['body'], '/portal/kesehatan.php');
$assert($nav($k['sbx_admin']) && $nav($k['sbx_v5_petugas']) && !$nav($k['sbx_pengurus_biasa']) && !$nav($k['sbx_ortu_a']) && !$nav($k['sbx_guru_biasa']), 'Menu Kesehatan hanya untuk admin dan petugas berpenugasan');

// ===================================================================== H-C
echo PHP_EOL . '=== H-C. Pencatatan lewat formulir web ===' . PHP_EOL;
$p = $k['sbx_v5_petugas'];
$form = $p->req('/portal/kesehatan_buat.php?q=V5S0001');
preg_match('/name="idempotency_key" value="([^"]+)"/', $form['body'], $mk);
preg_match('/name="_csrf" value="([^"]+)"/', $form['body'], $mc);
$assert(isset($mk[1], $mc[1]) && str_contains($form['body'], 'value="' . $santri . '"') && !str_contains($form['body'], 'V5S0002'), 'Formulir memuat kunci idempotensi, CSRF, santri aktif; santri nonaktif tidak ditawarkan');
$isi = ['_csrf' => $mc[1] ?? '', 'idempotency_key' => $mk[1] ?? '', 'santri_id' => (string) $santri, 'waktu_kunjungan' => date('Y-m-d\TH:i', strtotime('-20 minutes')),
    'keluhan' => "$penanda-WEB-KELUHAN pusing", 'catatan_klinis' => "$penanda-WEB-CATATAN tensi normal", 'tindakan' => "$penanda-WEB-TINDAKAN istirahat"];
$r = $p->req('/portal/kesehatan_buat.php', array_diff_key($isi, ['_csrf' => 1]));
$assert($r['status'] === 419 && $jumlah() === 0, 'POST tanpa CSRF ditolak (419) tanpa baris');
$r1 = $p->req('/portal/kesehatan_buat.php', $isi);
$id = (int) $satu('SELECT MAX(id) FROM kesehatan_kunjungan');
$assert(in_array($r1['status'], [302, 303], true) && str_contains((string) $r1['location'], 'kesehatan_detail.php?id=' . $id) && $jumlah() === 1, 'POST pertama → satu kunjungan, dialihkan ke detail');
$r2 = $p->req('/portal/kesehatan_buat.php', $isi);
$assert(in_array($r2['status'], [302, 303], true) && $r2['location'] === $r1['location'] && $jumlah() === 1, 'Klik ganda/POST ulang kunci sama → kunjungan yang sama, tetap satu');
$d = $p->req('/portal/kesehatan_detail.php?id=' . $id);
$assert($d['status'] === 200 && str_contains($d['body'], "$penanda-WEB-KELUHAN") && str_contains($d['body'], "$penanda-WEB-TINDAKAN") && str_contains($teks($d['body']), 'sudah tersimpan sebelumnya'), 'Detail dibaca ulang dengan isi yang sama; pesan bahwa kirim ulang tidak menggandakan');
$assert(!str_contains($d['body'], 'Riwayat akses') && str_contains($d['header'], 'no-store'), 'Detail petugas: tanpa riwayat akses, no-store');
$beda = $isi;
$beda['keluhan'] = "$penanda-WEB berbeda";
$r = $p->req('/portal/kesehatan_buat.php', $beda);
$assert($r['status'] === 409 && $jumlah() === 1 && str_contains($teks($r['body']), 'sudah dipakai'), 'Kunci sama + isi berbeda → 409, formulir dirender ulang, tanpa baris baru');
$salah = $isi;
$salah['idempotency_key'] = bin2hex(random_bytes(16));
$salah['keluhan'] = '';
$r = $p->req('/portal/kesehatan_buat.php', $salah);
$assert($r['status'] === 422 && $jumlah() === 1 && str_contains($r['body'], 'value="' . $salah['idempotency_key'] . '"'), 'Validasi gagal → 422, kunci yang sama dipertahankan, tanpa baris');
// Isi klinis tidak pernah dikirim lewat URL; uji GET memakai teks netral.
$p->req('/portal/kesehatan_buat.php?' . http_build_query(['idempotency_key' => 'get-' . bin2hex(random_bytes(6)), 'santri_id' => (string) $santri, 'waktu_kunjungan' => $isi['waktu_kunjungan'], 'keluhan' => 'uji-get-netral']));
$assert($jumlah() === 1, 'GET dengan parameter tidak memutasi');
$daftar = $p->req('/portal/kesehatan.php');
$assert(substr_count($daftar['body'], 'kesehatan_detail.php?id=' . $id) === 1 && !str_contains($daftar['body'], $penanda), 'Daftar: satu baris kunjungan, tanpa isi klinis');
$r = $k['sbx_admin']->req('/portal/kesehatan_buat.php', array_merge($isi, ['_csrf' => $k['sbx_admin']->csrf('/portal/kesehatan.php'), 'idempotency_key' => bin2hex(random_bytes(16))]));
$assert($r['status'] === 403 && $jumlah() === 1, 'Admin POST formulir kunjungan → 403 tanpa baris');
$r = $k['sbx_v5_admin_petugas']->req('/portal/kesehatan_buat.php', array_merge($isi, ['_csrf' => $k['sbx_v5_admin_petugas']->csrf('/portal/kesehatan.php'), 'idempotency_key' => bin2hex(random_bytes(16))]));
$assert($r['status'] === 403 && $jumlah() === 1, 'Admin yang juga petugas POST formulir → 403 tanpa baris');
foreach (['sbx_pengurus_biasa', 'sbx_guru_biasa', 'sbx_ortu_a'] as $u) {
    $r = $k[$u]->req('/portal/kesehatan_buat.php', array_merge($isi, ['_csrf' => $k[$u]->csrf(), 'idempotency_key' => bin2hex(random_bytes(16))]));
    $assert($r['status'] === 403 && $jumlah() === 1, "$u POST formulir → 403 tanpa baris");
}

// ===================================================================== H-D
echo PHP_EOL . '=== H-D. API kesehatan ===' . PHP_EOL;
$tPetugas = $token('sbx_v5_petugas');
$tAdmin = $token('sbx_admin');
$assert($tPetugas !== '' && $tAdmin !== '', 'Token API petugas dan admin diperoleh');
$r = $api('GET', '/v5/kesehatan/akses', $tPetugas);
$assert($r['status'] === 200 && $r['json']['data']['catat'] === true && $r['json']['data']['sumber'] === 'petugas' && str_contains($r['header'], 'no-store'), 'GET akses petugas: catat=true, no-store');
$r = $api('GET', '/v5/kesehatan/akses', $tAdmin);
$assert($r['status'] === 200 && $r['json']['data']['baca'] === true && $r['json']['data']['catat'] === false, 'GET akses admin: baca=true, catat=false');
$assert($api('GET', '/v5/kesehatan/kunjungan', null)['status'] === 401, 'Tanpa token → 401');
$r = $api('GET', '/v5/kesehatan/santri?q=V5S000', $tPetugas);
$assert($r['status'] === 200 && array_column($r['json']['data']['rows'], 'id') === [$santri], 'Pilihan santri API: hanya santri aktif');
$kunci = 'api-' . bin2hex(random_bytes(8));
$badan = ['santri_id' => $santri, 'waktu_kunjungan' => date('Y-m-d H:i', strtotime('-5 minutes')), 'keluhan' => "$penanda-API-KELUHAN batuk", 'catatan_klinis' => "$penanda-API-CATATAN", 'tindakan' => null];
$r = $api('POST', '/v5/kesehatan/kunjungan', $tPetugas, $badan, ['Idempotency-Key: ' . $kunci]);
$idApi = (int) ($r['json']['data']['id'] ?? 0);
$assert($r['status'] === 201 && $idApi > 0 && $r['json']['data']['replayed'] === false && $jumlah() === 2 && !str_contains($r['body'], $penanda), 'POST API → 201 {id}, respons tanpa isi klinis');
$r = $api('POST', '/v5/kesehatan/kunjungan', $tPetugas, $badan, ['Idempotency-Key: ' . $kunci]);
$assert($r['status'] === 200 && (int) $r['json']['data']['id'] === $idApi && $r['json']['data']['replayed'] === true && $jumlah() === 2, 'Ulang POST kunci+isi sama → 200 kunjungan sama');
$r = $api('POST', '/v5/kesehatan/kunjungan', $tPetugas, ['idempotency_key' => $kunci] + $badan);
$assert($r['status'] === 200 && (int) $r['json']['data']['id'] === $idApi, 'Kunci di badan JSON setara dengan header');
$r = $api('POST', '/v5/kesehatan/kunjungan', $tPetugas, array_merge($badan, ['keluhan' => "$penanda-API lain"]), ['Idempotency-Key: ' . $kunci]);
$assert($r['status'] === 409 && $r['json']['error']['code'] === 'CONFLICT' && $jumlah() === 2, 'Kunci sama isi beda → 409 CONFLICT tanpa baris');
$r = $api('POST', '/v5/kesehatan/kunjungan', $tPetugas, $badan);
$assert($r['status'] === 422 && $jumlah() === 2, 'Tanpa kunci idempotensi → 422');
$r = $api('POST', '/v5/kesehatan/kunjungan', $tPetugas, null, ['Idempotency-Key: ' . bin2hex(random_bytes(8))], );
$assert($r['status'] === 422, 'Badan kosong → 422');
$r = $api('GET', '/v5/kesehatan/kunjungan/' . $idApi, $tPetugas);
$assert($r['status'] === 200 && $r['json']['data']['keluhan'] === $badan['keluhan'] && $r['json']['data']['tindakan'] === null && $r['json']['data']['versi'] === 1, 'GET detail API petugas: isi sama, versi 1');
$r = $api('GET', '/v5/kesehatan/kunjungan', $tPetugas);
$assert($r['status'] === 200 && $r['json']['data']['total'] === 2 && !str_contains($r['body'], $penanda) && !str_contains($r['body'], '"keluhan"'), 'GET daftar API: 2 kunjungan, tanpa isi klinis');
foreach (['sbx_admin' => $tAdmin, 'sbx_v5_admin_petugas' => $token('sbx_v5_admin_petugas'), 'sbx_pengurus_biasa' => $token('sbx_pengurus_biasa'), 'sbx_guru_biasa' => $token('sbx_guru_biasa'),
    'sbx_murobi_c' => $token('sbx_murobi_c'), 'sbx_ortu_a' => $token('sbx_ortu_a')] as $u => $t) {
    $r = $api('POST', '/v5/kesehatan/kunjungan', $t, $badan, ['Idempotency-Key: ' . bin2hex(random_bytes(8))]);
    $assert($r['status'] === 403 && $jumlah() === 2, "API POST $u → 403 tanpa baris");
    if (!in_array($u, ['sbx_admin', 'sbx_v5_admin_petugas'], true)) {
        $assert($api('GET', '/v5/kesehatan/kunjungan', $t)['status'] === 403 && $api('GET', '/v5/kesehatan/kunjungan/' . $idApi, $t)['status'] === 403, "API GET daftar/detail $u → 403");
    }
}
$assert($api('GET', '/v5/kesehatan/kunjungan/99999999', $tPetugas)['status'] === 404, 'Detail tidak ada → 404');
$assert($api('GET', '/v5/kesehatan/tidak-ada', $tPetugas)['status'] === 404, 'Endpoint kesehatan tak dikenal → 404');

// ===================================================================== H-E
echo PHP_EOL . '=== H-E. Admin membaca detail dan audit akses ===' . PHP_EOL;
$r = $k['sbx_admin']->req('/portal/kesehatan_detail.php?id=' . $id);
$assert($r['status'] === 200 && str_contains($r['body'], "$penanda-WEB-CATATAN") && str_contains($r['body'], 'Riwayat akses') && str_contains($teks($r['body']), 'Admin (pengawasan)') && !str_contains($r['body'], 'kesehatan_buat.php'), 'Admin web: detail + riwayat akses, tanpa tautan ubah');
$r = $api('GET', '/v5/kesehatan/kunjungan/' . $idApi, $tAdmin);
$assert($r['status'] === 200 && $r['json']['data']['akses']['catat'] === false, 'Admin API membaca detail (catat=false)');
$r = $api('GET', '/v5/kesehatan/kunjungan/' . $idApi . '/akses', $tAdmin);
$kanal = array_column($r['json']['data']['rows'] ?? [], 'kanal', 'sumber');
$assert($r['status'] === 200 && ($kanal['admin'] ?? '') === 'api' && ($kanal['petugas'] ?? '') === 'api', 'Riwayat akses API: pembukaan petugas & admin tercatat dengan kanal');
$assert($api('GET', '/v5/kesehatan/kunjungan/' . $idApi . '/akses', $tPetugas)['status'] === 403, 'Petugas tidak membaca riwayat akses lewat API (403)');
$assert((int) $satu("SELECT COUNT(*) FROM kesehatan_akses_log WHERE kunjungan_id = $id AND kanal = 'web' AND sumber = 'admin'") === 1, 'Pembukaan web admin tercatat');

// ===================================================================== H-F
echo PHP_EOL . '=== H-F. Penugasan dinonaktifkan saat sesi masih aktif ===' . PHP_EOL;
$pb = $k['sbx_v5_petugas_b'];
$tB = $token('sbx_v5_petugas_b');
$form = $pb->req('/portal/kesehatan_buat.php?q=V5S0001');
preg_match('/name="idempotency_key" value="([^"]+)"/', $form['body'], $mk);
preg_match('/name="_csrf" value="([^"]+)"/', $form['body'], $mc);
$assert($form['status'] === 200 && isset($mk[1]), 'Petugas B membuka formulir saat penugasan aktif');
$tugasB = (int) $satu("SELECT a.id FROM kesehatan_assignments a JOIN users u ON u.pengurus_id = a.pengurus_id WHERE u.username = 'sbx_v5_petugas_b'");
$csrf = $k['sbx_admin']->csrf('/admin/admin_penugasan.php?jenis=kesehatan');
$k['sbx_admin']->req('/admin/admin_penugasan.php?jenis=kesehatan', ['_csrf' => $csrf, 'jenis' => 'kesehatan', 'action' => 'nonaktifkan', 'id' => $tugasB, 'alasan' => 'Uji pencabutan saat sesi aktif']);
$assert((int) $satu("SELECT is_active FROM kesehatan_assignments WHERE id = $tugasB") === 0, 'Admin menonaktifkan penugasan lewat Pusat Penugasan');
$sebelum = $jumlah();
$r = $pb->req('/portal/kesehatan_buat.php', ['_csrf' => $mc[1] ?? '', 'idempotency_key' => $mk[1] ?? '', 'santri_id' => (string) $santri,
    'waktu_kunjungan' => date('Y-m-d\TH:i', strtotime('-3 minutes')), 'keluhan' => "$penanda-B setelah dicabut"]);
$assert($r['status'] === 403 && $jumlah() === $sebelum, 'Formulir yang sudah terbuka dikirim setelah pencabutan → 403 tanpa baris');
$r = $api('POST', '/v5/kesehatan/kunjungan', $tB, $badan, ['Idempotency-Key: ' . bin2hex(random_bytes(8))]);
$assert($r['status'] === 403 && $jumlah() === $sebelum, 'Token lama petugas B setelah pencabutan → 403 tanpa baris');
$assert($pb->req('/portal/kesehatan_detail.php?id=' . $id)['status'] === 403, 'Detail juga tertutup bagi sesi lama setelah pencabutan');

// ===================================================================== H-G
echo PHP_EOL . '=== H-G. Akun dinonaktifkan saat sesi aktif ===' . PHP_EOL;
$pc = $k['sbx_v5_petugas_c'];
$tC = $token('sbx_v5_petugas_c');
$assert($pc->req('/portal/kesehatan.php')['status'] === 200, 'Petugas C membuka daftar sebelum akun dinonaktifkan');
$jalankan("UPDATE users SET is_active = 0 WHERE username = 'sbx_v5_petugas_c'");
$r = $pc->req('/portal/kesehatan.php');
$assert($r['status'] !== 200 && !str_contains($r['body'], 'SBX V5 Santri Klinik'), 'Sesi web akun nonaktif tidak lagi membuka daftar (status ' . $r['status'] . ')');
$r = $api('POST', '/v5/kesehatan/kunjungan', $tC, $badan, ['Idempotency-Key: ' . bin2hex(random_bytes(8))]);
$assert(in_array($r['status'], [401, 403], true) && $jumlah() === $sebelum, 'Token akun nonaktif ditolak (status ' . $r['status'] . ') tanpa baris');
$jalankan("UPDATE users SET is_active = 1 WHERE username = 'sbx_v5_petugas_c'");

// ===================================================================== H-H
echo PHP_EOL . '=== H-H. Log umum dan audit tanpa isi klinis ===' . PHP_EOL;
usleep(300000);
$isiLog = (string) file_get_contents($log);
$assert(!preg_match('/PHP (Warning|Notice|Fatal|Deprecated)|Uncaught/', $isiLog), 'Log server tanpa galat/peringatan PHP');
$assert(!str_contains($isiLog, $penanda), 'Log server memuat 0 isi klinis');
$assert((int) $satu("SELECT COUNT(*) FROM audit_logs WHERE CONCAT(COALESCE(before_json,''), COALESCE(after_json,'')) LIKE '%" . $penanda . "%'") === 0, 'audit_logs memuat 0 isi klinis');
$assert((int) $satu("SELECT COUNT(*) FROM audit_logs WHERE action = 'kesehatan.kunjungan.dicatat'") === $jumlah(), 'Setiap kunjungan tepat satu audit metadata');
$kolom = array_column($conn->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kesehatan_akses_log'")->fetch_all(MYSQLI_ASSOC), 'COLUMN_NAME');
$assert($kolom !== [] && array_intersect($kolom, ['keluhan', 'catatan_klinis', 'tindakan']) === [], 'kesehatan_akses_log tidak punya kolom isi klinis');
@unlink($log);

echo PHP_EOL . 'Lulus: ' . $lulus . ' · Gagal: ' . count($gagal) . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
