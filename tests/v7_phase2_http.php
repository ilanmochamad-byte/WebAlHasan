<?php

declare(strict_types=1);

/**
 * Uji server PRD V7 Fase 2 — website dan API tinjauan pembimbing, perbaikan
 * dan pembatalan wali, serta keputusan akhir V2 lewat server PHP lokal di atas
 * DB uji `tests/v7_phase2_db.sh`.
 *
 *   V7F2_DB=test_v7_f2_http_test bash tests/v7_phase2_db.sh
 *   V7F2_DB=test_v7_f2_http_test php tests/v7_phase2_http.php
 *
 *   W-A  pembimbing web: tiga tindakan, CSRF, GET tidak memutasi, kirim ulang,
 *        versi lama → 409 dengan isian dan kunci dipertahankan
 *   W-B  wali pengaju web: catatan perbaikan, perbaiki/kirim ulang, isi tidak
 *        berubah → 422; wali kedua/asing dan peran lain ditolak
 *   W-C  penerusan → rincian V2 bertanda asal wali; murobi memutus lewat
 *        formulir V2; admin tidak dapat membatalkan; wali melihat keputusan
 *   W-D  penolakan pembimbing dan pembatalan wali di web; daftar V2 orang tua
 *   W-E  API: tinjauan/perbaikan/pembatalan (201/200/replay/409/422/403/404),
 *        riwayat pembimbing, keputusan murobi V2, no-store
 *   W-F  pencabutan penugasan/relasi saat sesi web dan token masih aktif
 *   W-G  log server tanpa galat PHP dan tanpa isi izin; verifier Fase 2
 *
 * Mengubah data uji; bangun ulang DB sebelum mengulang.
 */

$root = dirname(__DIR__);
$db = getenv('V7F2_DB') ?: 'test_v7_f2_http_test';
$port = (int) (getenv('V7F2_PORT') ?: 8996);
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

$log = tempnam(sys_get_temp_dir(), 'v7f2-server-');
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
        $this->jar = tempnam(sys_get_temp_dir(), 'v7f2-jar-');
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
    $r = $api('POST', '/auth/login', null, ['username' => $user, 'password' => 'Sandbox#123', 'device_name' => 'uji-v7-f2']);

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
$status = static fn (int $id): string => (string) $satu("SELECT status FROM izin_pengajuan WHERE id = $id");
$versi = static fn (int $id): int => (int) $satu("SELECT version FROM izin_pengajuan WHERE id = $id");
$hitung = static fn (string $tabel, string $where): int => (int) $satu("SELECT COUNT(*) FROM $tabel WHERE $where");
$sSatu = $sid('V7S0001');
$sCadangan = $sid('V7S0008');
$sTanpaMurobi = $sid('V7S0006');
$assert(min($sSatu, $sCadangan, $sTanpaMurobi) > 0 && $hitung('izin_pengajuan', "asal_pengajuan = 'Wali'") === 0, 'Fixture V7 Fase 2 tersedia; belum ada pengajuan wali');

$akun = ['sbx_admin', 'sbx_v7_wali_a', 'sbx_v7_wali_b', 'sbx_ortu_b', 'sbx_v7_pembimbing_satu', 'sbx_v7_pembimbing_dua', 'sbx_murobi_a', 'sbx_murobi_b'];
$k = [];
$t = [];
foreach ($akun as $u) {
    $k[$u] = $klien($u);
    $t[$u] = $token($u);
}
$assert(count(array_filter($t)) === count($akun), 'Sesi web dan token API untuk delapan akun');
$buat = static function (int $santri, string $dari, string $sampai, string $alasan) use ($api, $t): int {
    $r = $api('POST', '/v7/izin/wali/pengajuan', $t['sbx_v7_wali_a'], ['santri_id' => $santri, 'tgl_izin' => $dari, 'tgl_kembali' => $sampai, 'alasan' => $alasan,
        'idempotency_key' => 'v7f2-http-buat-' . bin2hex(random_bytes(6))]);

    return (int) ($r['json']['data']['id'] ?? 0);
};
$kirim = static function (Klien $kl, string $alamat, string $aksi, array $isian, ?string $tindakan = null) use ($formulir): array {
    $f = $formulir($kl, $alamat);
    // Formulir yang benar dipilih dari nilai `tindakan`/`aksi` tersembunyi pada halaman.
    $pola = '/<form[^>]*>(?:(?!<\/form>).)*name="aksi" value="' . preg_quote($aksi, '/') . '"(?:(?!<\/form>).)*' . ($tindakan === null ? '' : 'name="tindakan" value="' . preg_quote($tindakan, '/') . '"') . '(?:(?!<\/form>).)*<\/form>/s';
    preg_match($pola, $f['body'], $m);
    $form = $m[0] ?? '';
    preg_match('/name="idempotency_key" value="([^"]+)"/', $form, $mk);
    preg_match('/name="version" value="(\d+)"/', $form, $mv);
    preg_match('/name="mode" value="([^"]*)"/', $form, $mm);
    $post = ['_csrf' => $f['csrf'], 'aksi' => $aksi, 'mode' => $mm[1] ?? '', 'pengajuan_id' => (int) preg_replace('/\D/', '', (string) strstr($alamat, 'id=')),
        'version' => $mv[1] ?? '', 'idempotency_key' => $mk[1] ?? ''] + ($tindakan === null ? [] : ['tindakan' => $tindakan]) + $isian;

    return ['ada' => $form !== '', 'post' => $post, 'r' => $kl->req($alamat, $post)];
};

// ===================================================================== W-A
echo PHP_EOL . '=== W-A. Pembimbing di website ===' . PHP_EOL;
$id1 = $buat($sCadangan, '2026-12-01', '2026-12-02', 'Menghadiri walimah sepupu HTTP');
$url1 = '/portal/izin_wali_detail.php?id=' . $id1;
$r = $k['sbx_v7_pembimbing_satu']->req($url1);
$assert($r['status'] === 200 && str_contains($r['body'], 'Teruskan ke murobi') && str_contains($r['body'], 'Minta perbaikan') && str_contains($r['body'], 'value="tolak"'),
    'Rincian pembimbing tujuan: tindakan teruskan, minta perbaikan, tolak');
$r = $k['sbx_v7_pembimbing_dua']->req($url1);
$assert($r['status'] === 404, 'Pembimbing lain: rincian 404');
$coba = $kirim($k['sbx_v7_pembimbing_satu'], $url1, 'tinjau', ['catatan' => 'Mohon perjelas jam jemput HTTP'], 'minta_perbaikan');
$assert($coba['ada'], 'Formulir minta perbaikan ditemukan di halaman');
$tanpaCsrf = $k['sbx_v7_pembimbing_satu']->req($url1, array_merge($coba['post'], ['_csrf' => 'salah', 'idempotency_key' => 'v7f2-http-csrf-01']));
$assert($tanpaCsrf['status'] >= 400 && $versi($id1) === 2 && $status($id1) === 'Perlu Perbaikan Wali', 'POST sah 303 berlaku; POST dengan CSRF salah ditolak (' . $tanpaCsrf['status'] . ')');
$assert($coba['r']['status'] === 303 && str_contains((string) $coba['r']['location'], 'izin_wali_detail.php?id=' . $id1), 'Minta perbaikan → 303 ke rincian');
$ulang = $k['sbx_v7_pembimbing_satu']->req($url1, $coba['post']);
$assert($ulang['status'] === 303 && $hitung('izin_pembimbing_tinjauan', "pengajuan_id = $id1") === 1 && str_contains($k['sbx_v7_pembimbing_satu']->req($url1)['body'], 'sudah tercatat sebelumnya'),
    'Kirim ulang (klik ganda) → 303, tetap satu tinjauan, pesan kiriman ulang');
$k['sbx_v7_pembimbing_satu']->req($url1 . '&aksi=tinjau&tindakan=tolak&catatan=GET&version=2&idempotency_key=v7f2-http-get-0001');
$assert($versi($id1) === 2, 'GET berparameter tidak memutasi');
$r = $k['sbx_v7_pembimbing_satu']->req($url1);
$assert(!str_contains($r['body'], 'value="tolak"') && str_contains($r['body'], 'Mohon perjelas jam jemput HTTP'), 'Sesudah diminta perbaikan: tindakan tinjauan hilang, catatan tampil di jejak tinjauan');

// ===================================================================== W-B
echo PHP_EOL . '=== W-B. Wali pengaju di website ===' . PHP_EOL;
$r = $k['sbx_v7_wali_a']->req($url1);
$assert($r['status'] === 200 && str_contains($teks($r['body']), 'Pembimbing meminta perbaikan') && str_contains($r['body'], 'Mohon perjelas jam jemput HTTP')
    && str_contains($r['body'], 'Perbaiki dan kirim ulang') && str_contains($r['body'], 'value="batalkan"'), 'Wali pengaju: catatan perbaikan, tombol perbaiki dan batalkan');
$r = $k['sbx_v7_wali_b']->req($url1);
$assert($r['status'] === 200 && !str_contains($r['body'], 'Mohon perjelas') && !str_contains($r['body'], 'walimah') && !str_contains($r['body'], 'name="aksi"')
    && str_contains($teks($r['body']), 'Perlu perbaikan wali'), 'Wali kedua: hanya status ringkas, tanpa catatan pembimbing, alasan, atau formulir');
$fB = $formulir($k['sbx_v7_wali_b'], $url1);
$r = $k['sbx_v7_wali_b']->req($url1, ['_csrf' => $fB['csrf'], 'aksi' => 'perbaiki', 'mode' => 'orang_tua', 'pengajuan_id' => $id1, 'version' => 2,
    'idempotency_key' => 'v7f2-http-wb-0001', 'tgl_izin' => '2026-12-01', 'tgl_kembali' => '2026-12-03', 'alasan' => 'Wali lain mengubah']);
$assert($r['status'] === 403 && $versi($id1) === 2, 'Wali kedua POST perbaikan → 403 tanpa perubahan');
$fPbB = $formulir($k['sbx_v7_pembimbing_satu'], $url1);
$r = $k['sbx_v7_pembimbing_satu']->req($url1, ['_csrf' => $fPbB['csrf'], 'mode' => 'pengurus', 'aksi' => 'perbaiki', 'pengajuan_id' => $id1, 'version' => 2, 'idempotency_key' => 'v7f2-http-pb-0001']);
$assert($r['status'] === 403 && $versi($id1) === 2, 'Pembimbing POST aksi wali → 403' . ($r['status'] === 403 ? '' : ' (dapat ' . $r['status'] . ': ' . mb_substr($teks($r['body']), 0, 200) . ')'));
$sama = $kirim($k['sbx_v7_wali_a'], $url1, 'perbaiki', ['tgl_izin' => '2026-12-01', 'tgl_kembali' => '2026-12-02', 'alasan' => 'Menghadiri walimah sepupu HTTP', 'catatan' => '']);
$assert($sama['r']['status'] === 422 && str_contains($sama['r']['body'], 'belum diubah') && str_contains($sama['r']['body'], 'value="' . $sama['post']['idempotency_key'] . '"'),
    'Isi tidak berubah → 422; dialog tetap terbuka dengan kunci idempotensi yang sama');
$ok = $kirim($k['sbx_v7_wali_a'], $url1, 'perbaiki', ['tgl_izin' => '2026-12-01', 'tgl_kembali' => '2026-12-03', 'alasan' => 'Menghadiri walimah sepupu HTTP, dijemput Sabtu', 'catatan' => 'Jam 08.00']);
$assert($ok['r']['status'] === 303 && $status($id1) === 'Menunggu Pembimbing' && $versi($id1) === 3 && $hitung('izin_wali_perbaikan', "pengajuan_id = $id1") === 1,
    'Perbaikan web → 303, kembali Menunggu Pembimbing, versi 3, satu versi isi lama');
$r = $k['sbx_v7_pembimbing_satu']->req($url1);
$assert(str_contains($r['body'], 'Versi isi sebelum perbaikan') && str_contains($r['body'], 'Menghadiri walimah sepupu HTTP') && str_contains($r['body'], 'value="teruskan"'),
    'Pembimbing melihat versi isi lama dan tindakan tinjauan kembali');

// ===================================================================== W-C
echo PHP_EOL . '=== W-C. Penerusan, keputusan murobi V2 ===' . PHP_EOL;
$basi = $formulir($k['sbx_v7_pembimbing_satu'], $url1);
$ter = $kirim($k['sbx_v7_pembimbing_satu'], $url1, 'tinjau', ['catatan' => 'Sudah konfirmasi HTTP'], 'teruskan');
$assert($ter['r']['status'] === 303 && $status($id1) === 'Diajukan' && $versi($id1) === 4, 'Teruskan web → 303, Diajukan ke murobi tunggal');
$r = $k['sbx_v7_pembimbing_satu']->req($url1, array_merge($ter['post'], ['idempotency_key' => 'v7f2-http-basi-0001', 'tindakan' => 'tolak', 'catatan' => 'Tolak versi basi', 'version' => 3]));
$assert($r['status'] === 409 && str_contains($teks($r['body']), 'Tindakan belum tersimpan') && str_contains($teks($r['body']), 'tidak lagi menunggu tinjauan')
    && !str_contains($r['body'], 'value="tolak"') && $versi($id1) === 4 && $status($id1) === 'Diajukan',
    'Tinjauan dari halaman basi sesudah diteruskan → 409 dengan penjelasan, tanpa formulir tinjauan, tanpa perubahan' . ($r['status'] === 409 ? '' : ' (dapat ' . $r['status'] . ': ' . mb_substr($teks($r['body']), 0, 300) . ')'));
$r = $k['sbx_murobi_a']->req('/portal/izin_detail.php?id=' . $id1);
$assert($r['status'] === 200 && str_contains($teks($r['body']), 'Diajukan wali dan diteruskan pembimbing SBX V7 Pembimbing Satu') && str_contains($r['body'], 'Sudah konfirmasi HTTP')
    && str_contains($r['body'], 'izin_wali_detail.php?id=' . $id1), 'Rincian V2 murobi: penanda asal wali, catatan penerusan, tautan tinjauan');
$r = $k['sbx_murobi_a']->req($url1);
$assert($r['status'] === 200 && str_contains($r['body'], 'Buka izin #' . $id1), 'Rincian V7 murobi tujuan terbuka dengan tautan ke halaman keputusan V2');
$r = $k['sbx_murobi_b']->req($url1);
$assert($r['status'] === 404, 'Murobi lain: rincian V7 404');
$r = $k['sbx_admin']->req('/portal/izin_detail.php?id=' . $id1);
$assert($r['status'] === 200 && !str_contains($r['body'], 'aksi-batalkan') && str_contains($r['body'], 'aksi-putuskan'), 'Rincian V2 admin: putuskan pengganti tersedia, batalkan tidak');
$fA = $formulir($k['sbx_admin'], '/portal/izin_detail.php?id=' . $id1);
$r = $k['sbx_admin']->req('/portal/izin_aksi.php', ['_csrf' => $fA['csrf'], 'aksi' => 'batalkan', 'mode' => 'admin', 'pengajuan_id' => $id1, 'version' => 4,
    'idempotency_key' => 'v7f2-http-adm-bt-01', 'alasan' => 'Admin membatalkan']);
$assert($r['status'] === 403 && $status($id1) === 'Diajukan', 'Admin POST batal V2 atas pengajuan wali → 403');
$fMB = $formulir($k['sbx_murobi_b'], '/portal/izin_antrean.php');
$r = $k['sbx_murobi_b']->req('/portal/izin_aksi.php', ['_csrf' => $fMB['csrf'], 'aksi' => 'putuskan', 'mode' => 'murobi', 'pengajuan_id' => $id1, 'version' => 4,
    'idempotency_key' => 'v7f2-http-mb-0001', 'hasil' => 'Disetujui', 'alasan' => 'Bukan murobi tujuan']);
$assert($r['status'] === 403 && $hitung('izin_keputusan', "pengajuan_id = $id1") === 0, 'Murobi B (di luar penugasan) POST keputusan → 403');
$fM = $formulir($k['sbx_murobi_a'], '/portal/izin_detail.php?id=' . $id1);
$r = $k['sbx_murobi_a']->req('/portal/izin_aksi.php', ['_csrf' => $fM['csrf'], 'aksi' => 'putuskan', 'mode' => 'murobi', 'pengajuan_id' => $id1, 'version' => $fM['versi'],
    'idempotency_key' => $fM['kunci'], 'hasil' => 'Disetujui', 'alasan' => 'Diizinkan dengan catatan HTTP']);
$assert($r['status'] === 303 && $status($id1) === 'Disetujui' && $hitung('izin_keputusan', "pengajuan_id = $id1") === 1, 'Murobi tujuan memutus lewat formulir V2 → 303, satu keputusan');
$r = $k['sbx_v7_wali_a']->req($url1);
$assert(str_contains($teks($r['body']), 'Keputusan murobi') && str_contains($r['body'], 'Diizinkan dengan catatan HTTP') && !str_contains($r['body'], 'value="batalkan"'),
    'Wali pengaju melihat keputusan murobi beserta alasannya; tanpa tombol batal');
$r = $k['sbx_v7_wali_b']->req($url1);
$assert(str_contains($teks($r['body']), 'Disetujui murobi') && !str_contains($r['body'], 'Diizinkan dengan catatan HTTP'), 'Wali kedua melihat status "Disetujui murobi" tanpa alasan keputusan');

// ===================================================================== W-D
echo PHP_EOL . '=== W-D. Tolak dan batal di website; daftar V2 orang tua ===' . PHP_EOL;
$id2 = $buat($sSatu, '2026-12-05', '2026-12-06', 'Acara sekolah adik HTTP');
$url2 = '/portal/izin_wali_detail.php?id=' . $id2;
$tolak = $kirim($k['sbx_v7_pembimbing_satu'], $url2, 'tinjau', ['catatan' => 'Bertepatan ujian HTTP'], 'tolak');
$assert($tolak['r']['status'] === 303 && $status($id2) === 'Ditolak Pembimbing' && $hitung('izin_keputusan', "pengajuan_id = $id2") === 0, 'Tolak web → Ditolak Pembimbing tanpa keputusan');
$r = $k['sbx_v7_wali_a']->req($url2);
$assert(str_contains($teks($r['body']), 'Ditolak pada tahap pembimbing (bukan keputusan murobi)') && str_contains($r['body'], 'Bertepatan ujian HTTP'), 'Wali pengaju melihat hasil tahap pembimbing dan alasannya');
$r = $k['sbx_v7_wali_b']->req($url2);
$assert(str_contains($teks($r['body']), 'Ditolak pembimbing') && !str_contains($r['body'], 'Bertepatan ujian HTTP'), 'Wali kedua: status ringkas tanpa alasan penolakan');
$id3 = $buat($sTanpaMurobi, '2026-12-08', '2026-12-09', 'Kontrol gigi HTTP');
$api('POST', '/v7/izin/pengajuan/' . $id3 . '/tinjauan', $t['sbx_v7_pembimbing_satu'], ['tindakan' => 'teruskan', 'version' => 1, 'idempotency_key' => 'v7f2-http-tr3-0001']);
$assert($status($id3) === 'Perlu Penetapan Admin', 'Pengajuan santri tanpa murobi diteruskan → Perlu Penetapan Admin');
$r = $k['sbx_admin']->req('/portal/izin_wali.php?antrean=1');
$assert(str_contains($r['body'], 'izin_wali_detail.php?id=' . $id3) && str_contains($r['body'], 'Perlu penetapan murobi'), 'Antrean admin V7 menandai "Perlu penetapan murobi"');
$batal = $kirim($k['sbx_v7_wali_a'], '/portal/izin_wali_detail.php?id=' . $id3, 'batalkan', ['alasan' => 'Jadwal dokter diundur HTTP']);
$assert($batal['r']['status'] === 303 && $status($id3) === 'Dibatalkan', 'Wali pengaju membatalkan di web saat menunggu penetapan murobi');
$r = $k['sbx_v7_wali_a']->req('/portal/izin.php');
$assert($r['status'] === 200 && !str_contains($r['body'], 'izin_detail.php?id=' . $id1) && !str_contains($r['body'], 'walimah'), 'Daftar Izin anak V2 tidak memuat pengajuan wali yang sudah diputus');
$r = $k['sbx_v7_wali_a']->req('/portal/izin_detail.php?id=' . $id1);
$assert($r['status'] === 403, 'Rincian V2 orang tua untuk pengajuan wali → 403 (dibaca lewat halaman V7)');
$r = $k['sbx_v7_pembimbing_satu']->req('/portal/izin_wali.php?riwayat=1');
$assert($r['status'] === 200 && str_contains($r['body'], 'izin_wali_detail.php?id=' . $id1) && str_contains($r['body'], 'izin_wali_detail.php?id=' . $id2)
    && str_contains($r['body'], 'Sudah ditinjau'), 'Pembimbing: tab "Sudah ditinjau" memuat pengajuan yang diteruskan dan ditolak');

// ===================================================================== W-E
echo PHP_EOL . '=== W-E. API ===' . PHP_EOL;
$id4 = $buat($sCadangan, '2026-12-12', '2026-12-13', 'Menjenguk nenek API');
$jalur = '/v7/izin/pengajuan/' . $id4;
$r = $api('POST', $jalur . '/tinjauan', $t['sbx_v7_pembimbing_satu'], ['tindakan' => 'minta_perbaikan', 'catatan' => 'Sebutkan alamat API', 'version' => 1, 'idempotency_key' => 'v7f2-api-mp-0001']);
$assert($r['status'] === 200 && $r['json']['data']['status'] === 'Perlu Perbaikan Wali' && $r['json']['data']['version'] === 2 && str_contains($r['header'], 'no-store'), 'API tinjauan → 200, versi 2, no-store');
$r2 = $api('POST', $jalur . '/tinjauan', $t['sbx_v7_pembimbing_satu'], [], ['Idempotency-Key: v7f2-api-mp-0001']);
$assert($r2['status'] === 422, 'API tinjauan tanpa isi → 422');
$r2 = $api('POST', $jalur . '/tinjauan', $t['sbx_v7_pembimbing_satu'], ['tindakan' => 'minta_perbaikan', 'catatan' => 'Sebutkan alamat API', 'version' => 1], ['Idempotency-Key: v7f2-api-mp-0001']);
$assert($r2['status'] === 200 && $r2['json']['data']['idempotent_replay'] === true && $hitung('izin_pembimbing_tinjauan', "pengajuan_id = $id4") === 1, 'Kunci lewat header Idempotency-Key → putar ulang 200');
$assert($api('POST', $jalur . '/tinjauan', $t['sbx_v7_pembimbing_satu'], ['tindakan' => 'tolak', 'catatan' => 'Berbeda', 'version' => 1, 'idempotency_key' => 'v7f2-api-mp-0001'])['status'] === 409,
    'API kunci sama isi berbeda → 409');
$assert($api('POST', $jalur . '/tinjauan', $t['sbx_v7_pembimbing_dua'], ['tindakan' => 'teruskan', 'version' => 2, 'idempotency_key' => 'v7f2-api-pb2-0001'])['status'] === 404
    && $api('POST', $jalur . '/tinjauan', $t['sbx_admin'], ['tindakan' => 'teruskan', 'version' => 2, 'idempotency_key' => 'v7f2-api-adm-0001'])['status'] === 403
    && $api('POST', $jalur . '/tinjauan', $t['sbx_v7_wali_a'], ['tindakan' => 'teruskan', 'version' => 2, 'idempotency_key' => 'v7f2-api-wa-0001'])['status'] === 403
    && $api('POST', $jalur . '/tinjauan', null, ['tindakan' => 'teruskan', 'version' => 2, 'idempotency_key' => 'v7f2-api-anon-01'])['status'] === 401,
    'API tinjauan: pembimbing lain 404, admin 403, wali 403, tanpa token 401');
$d = $api('GET', $jalur, $t['sbx_v7_wali_a']);
$assert($d['json']['data']['tindakan'] === ['perbaiki' => true, 'batalkan' => true] && $d['json']['data']['tinjauan'][0]['catatan'] === 'Sebutkan alamat API', 'API rincian wali pengaju: tindakan dan catatan perbaikan');
$d = $api('GET', $jalur, $t['sbx_v7_wali_b']);
$assert($d['json']['data']['tindakan'] === [] && !isset($d['json']['data']['tinjauan']) && !str_contains($d['body'], 'alamat API') && !str_contains($d['body'], 'nenek'), 'API rincian wali kedua: ringkas tanpa catatan/alasan/tindakan');
$assert($api('POST', $jalur . '/perbaikan', $t['sbx_v7_wali_b'], ['tgl_izin' => '2026-12-12', 'tgl_kembali' => '2026-12-14', 'alasan' => 'Wali lain', 'version' => 2, 'idempotency_key' => 'v7f2-api-wb-0001'])['status'] === 403
    && $api('POST', $jalur . '/perbaikan', $t['sbx_ortu_b'], ['tgl_izin' => '2026-12-12', 'tgl_kembali' => '2026-12-14', 'alasan' => 'Wali asing', 'version' => 2, 'idempotency_key' => 'v7f2-api-wx-0001'])['status'] === 404,
    'API perbaikan: wali kedua 403, wali asing 404');
$r = $api('POST', $jalur . '/perbaikan', $t['sbx_v7_wali_a'], ['tgl_izin' => '2026-12-12', 'tgl_kembali' => '2026-12-14', 'alasan' => 'Menjenguk nenek di Garut API', 'version' => 2, 'idempotency_key' => 'v7f2-api-wa-0002']);
$assert($r['status'] === 200 && $r['json']['data']['status'] === 'Menunggu Pembimbing' && $r['json']['data']['version'] === 3, 'API perbaikan wali pengaju → 200, versi 3');
$r = $api('POST', $jalur . '/tinjauan', $t['sbx_v7_pembimbing_satu'], ['tindakan' => 'teruskan', 'version' => 3, 'idempotency_key' => 'v7f2-api-tr-0001']);
$assert($r['status'] === 200 && $r['json']['data']['status'] === 'Diajukan' && $r['json']['data']['perlu_penetapan_murobi'] === false, 'API teruskan → Diajukan');
$assert($api('POST', '/izin/pengajuan/' . $id4 . '/keputusan', $t['sbx_murobi_b'], ['hasil' => 'Disetujui', 'alasan' => 'Bukan tujuan', 'version' => 4, 'idempotency_key' => 'v7f2-api-mb-0001'])['status'] === 403,
    'API keputusan V2 oleh murobi di luar penugasan → 403');
$assert($api('POST', '/izin/pengajuan/' . $id4 . '/pembatalan', $t['sbx_admin'], ['alasan' => 'Admin batal', 'version' => 4, 'idempotency_key' => 'v7f2-api-ab-0001'])['status'] === 403,
    'API pembatalan V2 oleh admin atas pengajuan wali → 403');
$r = $api('POST', $jalur . '/pembatalan', $t['sbx_v7_wali_a'], ['alasan' => 'Nenek sudah pulih API', 'version' => 4, 'idempotency_key' => 'v7f2-api-bt-0001']);
$assert($r['status'] === 200 && $r['json']['data']['status'] === 'Dibatalkan' && $status($id4) === 'Dibatalkan', 'API pembatalan wali pengaju sesudah diteruskan → 200 Dibatalkan');
$assert($api('POST', '/izin/pengajuan/' . $id4 . '/keputusan', $t['sbx_murobi_a'], ['hasil' => 'Disetujui', 'alasan' => 'Terlambat', 'version' => 5, 'idempotency_key' => 'v7f2-api-ma-0001'])['status'] === 409
    && $hitung('izin_keputusan', "pengajuan_id = $id4") === 0, 'Keputusan murobi sesudah pembatalan → 409, tanpa keputusan');
$r = $api('GET', '/v7/izin/pembimbing/riwayat', $t['sbx_v7_pembimbing_satu']);
$assert($r['status'] === 200 && in_array($id4, array_column($r['json']['data']['items'], 'id'), true), 'API riwayat pembimbing memuat status terkini');
$assert($api('GET', '/v7/izin/pembimbing/riwayat', $t['sbx_v7_wali_a'])['status'] === 403, 'API riwayat pembimbing untuk wali → 403');
$ortu = $api('GET', '/izin/pengajuan?per_page=100', $t['sbx_v7_wali_a']);
$assert($ortu['status'] === 200 && array_intersect([$id1, $id3, $id4], array_map('intval', array_column($ortu['json']['data']['items'] ?? [], 'id'))) === [],
    'API V2 orang tua tidak memuat pengajuan wali');

// ===================================================================== W-F
echo PHP_EOL . '=== W-F. Pencabutan pada sesi/token lama ===' . PHP_EOL;
$id5 = $buat($sSatu, '2026-12-20', '2026-12-21', 'Menghadiri wisuda kakak HTTP');
$url5 = '/portal/izin_wali_detail.php?id=' . $id5;
$fPb = $formulir($k['sbx_v7_pembimbing_satu'], $url5);
$pa = (int) $satu("SELECT pembimbing_assignment_id FROM izin_pengajuan WHERE id = $id5");
$jalankan("UPDATE pembimbing_assignments SET is_active = 0 WHERE id = $pa");
$r = $k['sbx_v7_pembimbing_satu']->req($url5, ['_csrf' => $fPb['csrf'], 'aksi' => 'tinjau', 'mode' => 'pengurus', 'tindakan' => 'teruskan', 'pengajuan_id' => $id5, 'version' => 1, 'idempotency_key' => $fPb['kunci']]);
$assert($r['status'] === 404 && $versi($id5) === 1, 'Penugasan dicabut sesudah halaman dibuka: POST dari sesi web lama → 404 tanpa perubahan');
$assert($api('POST', '/v7/izin/pengajuan/' . $id5 . '/tinjauan', $t['sbx_v7_pembimbing_satu'], ['tindakan' => 'teruskan', 'version' => 1, 'idempotency_key' => 'v7f2-api-cabut-01'])['status'] === 404,
    'Token lama pembimbing → 404 tanpa perubahan');
$jalankan("UPDATE pembimbing_assignments SET is_active = 1 WHERE id = $pa");
$api('POST', '/v7/izin/pengajuan/' . $id5 . '/tinjauan', $t['sbx_v7_pembimbing_satu'], ['tindakan' => 'minta_perbaikan', 'catatan' => 'Lengkapi', 'version' => 1, 'idempotency_key' => 'v7f2-api-mp-0005']);
$fWa = $formulir($k['sbx_v7_wali_a'], $url5);
$relasi = (int) $satu("SELECT sw.id FROM santri_wali sw JOIN users u ON u.wali_id = sw.wali_id WHERE u.username = 'sbx_v7_wali_a' AND sw.santri_id = $sSatu");
$jalankan("UPDATE santri_wali SET archived_at = NOW() WHERE id = $relasi");
$r = $k['sbx_v7_wali_a']->req($url5, ['_csrf' => $fWa['csrf'], 'aksi' => 'batalkan', 'mode' => 'orang_tua', 'pengajuan_id' => $id5, 'version' => 2, 'idempotency_key' => 'v7f2-http-cabut-02', 'alasan' => 'Relasi dicabut']);
$assert($r['status'] === 404 && $status($id5) === 'Perlu Perbaikan Wali', 'Relasi wali dicabut: POST batal dari sesi web lama → 404');
$assert($api('POST', '/v7/izin/pengajuan/' . $id5 . '/perbaikan', $t['sbx_v7_wali_a'], ['tgl_izin' => '2026-12-20', 'tgl_kembali' => '2026-12-22', 'alasan' => 'Relasi dicabut', 'version' => 2, 'idempotency_key' => 'v7f2-api-cabut-03'])['status'] === 404,
    'Token lama wali → 404 tanpa perubahan');
$jalankan("UPDATE santri_wali SET archived_at = NULL WHERE id = $relasi");

// ===================================================================== W-G
echo PHP_EOL . '=== W-G. Log dan verifier ===' . PHP_EOL;
usleep(300000);
$isiLog = (string) file_get_contents($log);
$assert(!preg_match('/PHP (Warning|Notice|Fatal|Deprecated)|Uncaught/', $isiLog), 'Log server tanpa galat/peringatan PHP');
$assert(!str_contains($isiLog, 'walimah') && !str_contains($isiLog, 'nenek') && !str_contains($isiLog, 'Mohon perjelas'), 'Log server tanpa alasan izin atau catatan pembimbing');
exec('DB_NAME=' . escapeshellarg($db) . ' php ' . escapeshellarg($root . '/bin/v7_phase2_verify.php') . ' 2>&1', $out, $kodeVerify);
$assert($kodeVerify === 0, 'Verifier V7 Fase 2 lulus sesudah uji HTTP (' . trim((string) end($out)) . ')');
@unlink($log);

echo PHP_EOL . 'Lulus: ' . $lulus . ' · Gagal: ' . count($gagal) . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
