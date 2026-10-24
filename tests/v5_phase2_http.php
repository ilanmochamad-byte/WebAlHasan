<?php

declare(strict_types=1);

/**
 * Uji HTTP PRD V5 Fase 2 — web (sesi + CSRF) dan API (token) — pada server
 * PHP bawaan dan DB uji terpisah.
 *
 *   V5F2_DB=test_v5_f2_http_test bash tests/v5_phase2_db.sh
 *   V5F2_DB=test_v5_f2_http_test php tests/v5_phase2_http.php
 *
 *   K-A  hak setiap halaman Fase 1–2 untuk petugas, admin, pembimbing,
 *        murobi, pembimbing+murobi, dan akun tanpa hak (server, bukan menu);
 *   K-B  formulir web: satu kunjungan dengan dua obat, satu rujukan, satu
 *        tindak lanjut → satu baris daftar, dua obat pada detail; klik ganda
 *        satu kunjungan; tanpa CSRF 419;
 *   K-C  catat hasil rujukan lewat web → versi 2, riwayat versi;
 *   K-D  koreksi web: tanpa alasan ditolak tanpa perubahan; dua petugas dari
 *        versi sama → satu tersimpan, satu 409 "Muat versi terbaru";
 *   K-E  draf web: draf tidak tampil bagi pembimbing; tetapkan tercatat;
 *   K-F  API: rincian, koreksi, hasil rujukan, riwayat versi, replay, konflik,
 *        dan penolakan tulis/riwayat bagi pembaca binaan & admin;
 *   K-G  pembaca binaan web/API: cakupan, ID tebakan 403, menu, tanpa tombol
 *        ubah; sesi/token lama setelah pencabutan & pindah kamar → 403;
 *   K-H  no-store, log server tanpa galat/isi klinis, audit tanpa isi.
 *
 * Mengubah data uji; bangun ulang DB sebelum mengulang.
 */

$root = dirname(__DIR__);
$db = getenv('V5F2_DB') ?: 'test_v5_f2_http_test';
$port = (int) (getenv('V5F2_PORT') ?: 8994);
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

$log = tempnam(sys_get_temp_dir(), 'v5f2-server-');
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
        $this->jar = tempnam(sys_get_temp_dir(), 'v5f2-jar-');
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
    $r = $api('POST', '/auth/login', null, ['username' => $user, 'password' => 'Sandbox#123', 'device_name' => 'uji-v5-f2']);

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
$penanda = 'V5F2HTTP' . strtoupper(bin2hex(random_bytes(4)));
$sA = (int) $satu("SELECT id FROM santri WHERE nis='V5S0101'");
$sB = (int) $satu("SELECT id FROM santri WHERE nis='V5S0102'");
$sLuar = (int) $satu("SELECT id FROM santri WHERE nis='V5S0103'");
$waktu = date('Y-m-d\TH:i', strtotime('-90 minutes'));
$assert($sA > 0 && $sB > 0 && $sLuar > 0 && $hitung('kesehatan_kunjungan') === 0, 'Fixture V5 Fase 2 tersedia dan belum ada kunjungan');

$akun = ['sbx_admin', 'sbx_v5_petugas_b', 'sbx_v5_petugas_d', 'sbx_v5_admin_petugas', 'sbx_v5_pembimbing', 'sbx_v5_pembimbing_b', 'sbx_v5_murobi', 'sbx_v5_murobi_kosong',
    'sbx_v5_ganda', 'sbx_pengurus_biasa', 'sbx_guru_biasa', 'sbx_ortu_a'];
$k = [];
foreach ($akun as $u) {
    $k[$u] = $klien($u);
}
$petugas = $k['sbx_v5_petugas_b'];
$kunci = static fn (string $awal): string => $awal . '-' . bin2hex(random_bytes(6));
$kunciForm = static function (string $html): string {
    preg_match('/name="idempotency_key" value="([^"]+)"/', $html, $m);

    return $m[1] ?? '';
};

// ===================================================================== K-B
echo PHP_EOL . '=== K-B. Formulir web: dua obat, satu rujukan, satu tindak lanjut ===' . PHP_EOL;
$r = $petugas->req('/portal/kesehatan_buat.php');
$assert($r['status'] === 200 && str_contains($r['body'], 'name="obat[0][nama_obat]"') && str_contains($r['body'], 'name="obat[2][nama_obat]"') && str_contains($r['body'], 'name="rujukan[0][tujuan]"')
    && str_contains($r['body'], 'name="tindak_lanjut[1][uraian]"') && str_contains($r['body'], 'value="Draf"') && str_contains($r['body'], 'Tidak ada perhitungan stok'),
    'Formulir memuat 3 baris obat, 1 rujukan, 2 tindak lanjut, tombol draf, dan keterangan tanpa stok');
$form = [
    '_csrf' => $petugas->csrf('/portal/kesehatan_buat.php'), 'idempotency_key' => $kunciForm($r['body']), 'santri_id' => $sA, 'waktu_kunjungan' => $waktu,
    'keluhan' => "$penanda-KELUHAN", 'catatan_klinis' => "$penanda-CATATAN", 'tindakan' => '', 'status' => 'Tercatat',
    'obat' => [
        ['nama_obat' => "$penanda-OBAT-1", 'jumlah' => '0,5', 'satuan' => 'tablet', 'petunjuk' => "$penanda-PETUNJUK", 'waktu_pemberian' => ''],
        ['nama_obat' => "$penanda-OBAT-2", 'jumlah' => '1', 'satuan' => 'sendok', 'petunjuk' => '', 'waktu_pemberian' => $waktu],
        ['nama_obat' => '', 'jumlah' => '', 'satuan' => '', 'petunjuk' => '', 'waktu_pemberian' => ''],
    ],
    'rujukan' => [['tujuan' => "$penanda-TUJUAN", 'tanggal_rujukan' => '', 'alasan' => "$penanda-ALASAN", 'hasil' => '', 'tanggal_hasil' => '']],
    'tindak_lanjut' => [['uraian' => "$penanda-URAIAN", 'tanggal_target' => '', 'status' => 'Terbuka', 'tanggal_selesai' => ''], ['uraian' => '', 'tanggal_target' => '', 'status' => 'Terbuka', 'tanggal_selesai' => '']],
];
$tanpaCsrf = $form;
unset($tanpaCsrf['_csrf']);
$r = $petugas->req('/portal/kesehatan_buat.php', $tanpaCsrf);
$assert($r['status'] === 419 && $hitung('kesehatan_kunjungan') === 0, 'POST tanpa CSRF → 419 tanpa baris');
$r = $petugas->req('/portal/kesehatan_buat.php', $form);
$id = (int) $satu('SELECT MAX(id) FROM kesehatan_kunjungan');
$assert(in_array($r['status'], [302, 303], true) && str_contains((string) $r['location'], 'kesehatan_detail.php?id=' . $id), 'Simpan → alihkan ke detail');
$r2 = $petugas->req('/portal/kesehatan_buat.php', $form);
$assert(in_array($r2['status'], [302, 303], true) && $hitung('kesehatan_kunjungan') === 1 && $hitung('kesehatan_obat') === 2 && $hitung('kesehatan_rujukan') === 1 && $hitung('kesehatan_tindak_lanjut') === 1,
    'Klik ganda (kunci sama) → tetap 1 kunjungan, 2 obat, 1 rujukan, 1 tindak lanjut');
$r = $petugas->req('/portal/kesehatan_detail.php?id=' . $id);
$t = $teks($r['body']);
$assert($r['status'] === 200 && str_contains($t, "$penanda-OBAT-1") && str_contains($t, "$penanda-OBAT-2") && str_contains($t, '0,5 tablet') && str_contains($t, "$penanda-TUJUAN")
    && str_contains($t, 'Menunggu hasil') && str_contains($t, "$penanda-URAIAN") && str_contains($t, '2 obat'), 'Detail web: dua obat, satu rujukan menunggu, satu tindak lanjut');
$assert(str_contains($r['body'], 'kesehatan_koreksi.php?id=' . $id) && str_contains($r['body'], 'kesehatan_hasil_rujukan.php?id=' . $id) && str_contains($r['body'], 'kesehatan_riwayat.php?id=' . $id),
    'Petugas melihat tombol Koreksi, Catat hasil rujukan, Riwayat versi');
$r = $petugas->req('/portal/kesehatan.php');
$assert($r['status'] === 200 && substr_count($r['body'], 'kesehatan_detail.php?id=' . $id . '"') === 1 && str_contains($teks($r['body']), '2 obat') && str_contains($teks($r['body']), 'Rujukan menunggu hasil')
    && !str_contains($r['body'], "$penanda-OBAT") && !str_contains($r['body'], "$penanda-KELUHAN"), 'Daftar web: SATU baris dengan penanda 2 obat & rujukan menunggu, tanpa isi klinis');
$r = $petugas->req('/portal/kesehatan.php?rujukan=menunggu&tindak_lanjut=terbuka&dari=' . substr($waktu, 0, 10));
$assert($r['status'] === 200 && str_contains($r['body'], 'kesehatan_detail.php?id=' . $id . '"'), 'Penyaring rujukan menunggu + tindak lanjut terbuka + tanggal menemukan kunjungan');
$r = $petugas->req('/portal/kesehatan.php?rujukan=tanpa');
$assert($r['status'] === 200 && !str_contains($r['body'], 'kesehatan_detail.php?id=' . $id . '"') && str_contains($teks($r['body']), 'Tidak ada kunjungan yang cocok'), 'Penyaring tanpa rujukan mengecualikan kunjungan');
$r = $petugas->req('/portal/kesehatan.php?santri_id=' . $sA);
$assert($r['status'] === 200 && str_contains($teks($r['body']), 'Riwayat kesehatan santri') && str_contains($teks($r['body']), 'SBX V5 Binaan A'), 'Riwayat per santri berjudul nama santri');
$r = $petugas->req('/portal/kesehatan_buat.php', array_merge($form, ['idempotency_key' => $kunci('kb'), 'obat' => [['nama_obat' => '', 'jumlah' => '2']]]));
$assert($r['status'] === 422 && str_contains($teks($r['body']), 'Obat ke-1: nama obat wajib diisi') && str_contains($r['body'], 'value="2"') && $hitung('kesehatan_kunjungan') === 1,
    'Validasi obat → 422, isian dirender ulang dari POST, tanpa baris');

// ===================================================================== K-C
echo PHP_EOL . '=== K-C. Hasil rujukan lewat web ===' . PHP_EOL;
$rid = (int) $satu("SELECT id FROM kesehatan_rujukan WHERE kunjungan_id = $id");
$r = $petugas->req("/portal/kesehatan_hasil_rujukan.php?id=$id&rujukan=$rid");
$assert($r['status'] === 200 && str_contains($r['body'], 'name="versi" value="1"') && str_contains($r['body'], 'name="hasil"'), 'Formulir hasil rujukan membawa versi 1');
$r = $petugas->req("/portal/kesehatan_hasil_rujukan.php?id=$id&rujukan=$rid", ['_csrf' => $petugas->csrf(), 'idempotency_key' => $kunciForm($r['body']), 'versi' => 1,
    'hasil' => "$penanda-HASIL", 'tanggal_hasil' => date('Y-m-d'), 'alasan' => 'Hasil diterima dari puskesmas']);
$assert(in_array($r['status'], [302, 303], true) && (int) $satu("SELECT versi FROM kesehatan_kunjungan WHERE id = $id") === 2 && (string) $satu("SELECT status FROM kesehatan_rujukan WHERE id = $rid") === 'Hasil diketahui',
    'Hasil rujukan tersimpan → versi 2');
$r = $petugas->req('/portal/kesehatan_riwayat.php?id=' . $id);
$t = $teks($r['body']);
$assert($r['status'] === 200 && str_contains($t, 'Versi 2 (terbaru)') && str_contains($t, 'Hasil diterima dari puskesmas') && str_contains($t, 'Menunggu hasil') && str_contains($t, 'Hasil diketahui') && str_contains($t, 'Pencatatan awal'),
    'Riwayat versi web: versi 2 dengan alasan dan status rujukan sebelum→sesudah, versi 1 pencatatan awal');

// ===================================================================== K-D
echo PHP_EOL . '=== K-D. Koreksi web dan konflik dua petugas ===' . PHP_EOL;
$petugasD = $k['sbx_v5_petugas_d'];
$fB = $petugas->req('/portal/kesehatan_koreksi.php?id=' . $id);
$fD = $petugasD->req('/portal/kesehatan_koreksi.php?id=' . $id);
$assert($fB['status'] === 200 && str_contains($fB['body'], 'name="versi" value="2"') && str_contains($fB['body'], 'name="obat[1][hapus]"') && str_contains($fB['body'], 'name="alasan"')
    && str_contains($fB['body'], $penanda . '-OBAT-2'), 'Formulir koreksi memuat versi 2, baris obat lama dengan pilihan Hapus, dan alasan');
$obat = $conn->query("SELECT id FROM kesehatan_obat WHERE kunjungan_id = $id ORDER BY id")->fetch_all(MYSQLI_ASSOC);
$koreksi = static function (string $html, Klien $kl, array $ubah) use ($id, $penanda, $waktu, $obat, $rid, $kunciForm): array {
    return array_replace_recursive([
        '_csrf' => $kl->csrf(), 'idempotency_key' => $kunciForm($html), 'versi' => 2, 'status' => 'Tercatat', 'waktu_kunjungan' => $waktu,
        'keluhan' => "$penanda-KELUHAN", 'catatan_klinis' => "$penanda-CATATAN", 'tindakan' => '',
        'obat' => [
            ['id' => $obat[0]['id'], 'nama_obat' => "$penanda-OBAT-1", 'jumlah' => '0.5', 'satuan' => 'tablet', 'petunjuk' => "$penanda-PETUNJUK", 'waktu_pemberian' => $waktu],
            ['id' => $obat[1]['id'], 'nama_obat' => "$penanda-OBAT-2", 'jumlah' => '1', 'satuan' => 'sendok', 'petunjuk' => '', 'waktu_pemberian' => $waktu],
        ],
        'rujukan' => [['id' => $rid, 'tujuan' => "$penanda-TUJUAN", 'tanggal_rujukan' => substr($waktu, 0, 10), 'alasan' => "$penanda-ALASAN", 'hasil' => "$penanda-HASIL", 'tanggal_hasil' => date('Y-m-d')]],
        'tindak_lanjut' => [['id' => (int) app_db()->query("SELECT id FROM kesehatan_tindak_lanjut WHERE kunjungan_id = $id")->fetch_row()[0], 'uraian' => "$penanda-URAIAN", 'tanggal_target' => '', 'status' => 'Terbuka', 'tanggal_selesai' => '']],
        'alasan' => '',
    ], $ubah);
};
$r = $petugas->req('/portal/kesehatan_koreksi.php?id=' . $id, $koreksi($fB['body'], $petugas, ['keluhan' => "$penanda-KELUHAN-B"]));
$assert($r['status'] === 422 && str_contains($teks($r['body']), 'Alasan koreksi wajib') && (int) $satu("SELECT versi FROM kesehatan_kunjungan WHERE id = $id") === 2 && str_contains($r['body'], "$penanda-KELUHAN-B"),
    'Koreksi tanpa alasan → 422, versi tetap 2, isian dipertahankan dari POST');
$r = $petugas->req('/portal/kesehatan_koreksi.php?id=' . $id, $koreksi($fB['body'], $petugas, ['keluhan' => "$penanda-KELUHAN-B", 'alasan' => 'Keluhan dilengkapi petugas B',
    'obat' => [1 => ['hapus' => '1']], 'tindak_lanjut' => [0 => ['status' => 'Selesai']]]));
$assert(in_array($r['status'], [302, 303], true) && (int) $satu("SELECT versi FROM kesehatan_kunjungan WHERE id = $id") === 3
    && $hitung('kesehatan_obat', "kunjungan_id = $id AND versi_dibatalkan IS NULL") === 1 && (string) $satu("SELECT status FROM kesehatan_tindak_lanjut WHERE kunjungan_id = $id") === 'Selesai',
    'Petugas B: koreksi beralasan → versi 3, satu obat dibatalkan, tindak lanjut selesai');
$r = $petugasD->req('/portal/kesehatan_koreksi.php?id=' . $id, $koreksi($fD['body'], $petugasD, ['keluhan' => "$penanda-KELUHAN-D", 'alasan' => 'Koreksi petugas D dari versi lama']));
$assert($r['status'] === 409 && str_contains($teks($r['body']), 'sudah diubah menjadi versi 3') && str_contains($teks($r['body']), 'Muat versi terbaru')
    && (int) $satu("SELECT versi FROM kesehatan_kunjungan WHERE id = $id") === 3 && (string) $satu("SELECT keluhan FROM kesehatan_kunjungan WHERE id = $id") === "$penanda-KELUHAN-B",
    'Petugas D dari versi 2 → 409 + "Muat versi terbaru"; koreksi B tidak tertimpa');
$assert($hitung('kesehatan_kunjungan_versi', "kunjungan_id = $id") === 3 && (string) $satu("SELECT keluhan FROM kesehatan_kunjungan_versi WHERE kunjungan_id = $id AND versi = 2") === "$penanda-KELUHAN",
    'Versi 2 tetap tersimpan utuh di riwayat');

// ===================================================================== K-E
echo PHP_EOL . '=== K-E. Draf lewat web ===' . PHP_EOL;
$r = $petugas->req('/portal/kesehatan_buat.php');
$r = $petugas->req('/portal/kesehatan_buat.php', ['_csrf' => $petugas->csrf(), 'idempotency_key' => $kunciForm($r['body']), 'santri_id' => $sA, 'waktu_kunjungan' => $waktu,
    'keluhan' => "$penanda-DRAF", 'status' => 'Draf']);
$idDraf = (int) $satu('SELECT MAX(id) FROM kesehatan_kunjungan');
$assert(in_array($r['status'], [302, 303], true) && (string) $satu("SELECT status FROM kesehatan_kunjungan WHERE id = $idDraf") === 'Draf', 'Simpan sebagai draf → status Draf');
$assert($k['sbx_v5_pembimbing']->req('/portal/kesehatan_detail.php?id=' . $idDraf)['status'] === 403
    && !str_contains($k['sbx_v5_pembimbing']->req('/portal/kesehatan.php')['body'], 'kesehatan_detail.php?id=' . $idDraf . '"'), 'Draf: pembimbing 403 detail, tidak ada di daftar');
$r = $petugas->req('/portal/kesehatan_detail.php?id=' . $idDraf);
$assert(str_contains($teks($r['body']), 'Lanjutkan draf') && str_contains($teks($r['body']), 'Belum tampil kepada pembimbing/murobi'), 'Detail draf: tombol Lanjutkan draf + keterangan');
$f = $petugas->req('/portal/kesehatan_koreksi.php?id=' . $idDraf);
$assert(str_contains($f['body'], 'Tetapkan sebagai tercatat') && !preg_match('/id="alasan"[^>]*required/', $f['body']), 'Formulir draf: tombol Tetapkan, alasan tidak wajib');
$r = $petugas->req('/portal/kesehatan_koreksi.php?id=' . $idDraf, ['_csrf' => $petugas->csrf(), 'idempotency_key' => $kunciForm($f['body']), 'versi' => 1, 'waktu_kunjungan' => $waktu,
    'keluhan' => "$penanda-DRAF lengkap", 'status' => 'Tercatat', 'alasan' => '']);
$assert(in_array($r['status'], [302, 303], true) && (string) $satu("SELECT status FROM kesehatan_kunjungan WHERE id = $idDraf") === 'Tercatat'
    && $k['sbx_v5_pembimbing']->req('/portal/kesehatan_detail.php?id=' . $idDraf)['status'] === 200, 'Tetapkan tercatat tanpa alasan → tampil bagi pembimbing');

// ===================================================================== K-A
echo PHP_EOL . '=== K-A. Hak setiap halaman (server) ===' . PHP_EOL;
$r = $petugas->req('/portal/kesehatan_buat.php');
$petugas->req('/portal/kesehatan_buat.php', ['_csrf' => $petugas->csrf(), 'idempotency_key' => $kunciForm($r['body']), 'santri_id' => $sLuar, 'waktu_kunjungan' => $waktu, 'keluhan' => "$penanda-LUAR"]);
$idLuar = (int) $satu('SELECT MAX(id) FROM kesehatan_kunjungan');
$halaman = [
    '/portal/kesehatan.php' => [200, 200, 200, 200, 200, 200, 200, 200, 403, 403, 403],
    '/portal/kesehatan_buat.php' => [200, 403, 403, 403, 403, 403, 403, 403, 403, 403, 403],
    "/portal/kesehatan_detail.php?id=$id" => [200, 200, 200, 200, 403, 200, 403, 200, 403, 403, 403],
    "/portal/kesehatan_detail.php?id=$idLuar" => [200, 200, 200, 403, 403, 403, 403, 403, 403, 403, 403],
    '/portal/kesehatan_detail.php?id=99999999' => [404, 404, 404, 403, 403, 403, 403, 403, 403, 403, 403],
    "/portal/kesehatan_koreksi.php?id=$id" => [200, 403, 403, 403, 403, 403, 403, 403, 403, 403, 403],
    "/portal/kesehatan_hasil_rujukan.php?id=$id&rujukan=$rid" => [200, 403, 403, 403, 403, 403, 403, 403, 403, 403, 403],
    "/portal/kesehatan_riwayat.php?id=$id" => [200, 200, 200, 403, 403, 403, 403, 403, 403, 403, 403],
];
$urutan = ['sbx_v5_petugas_b', 'sbx_admin', 'sbx_v5_admin_petugas', 'sbx_v5_pembimbing', 'sbx_v5_pembimbing_b', 'sbx_v5_murobi', 'sbx_v5_murobi_kosong', 'sbx_v5_ganda',
    'sbx_pengurus_biasa', 'sbx_guru_biasa', 'sbx_ortu_a'];
foreach ($halaman as $path => $harap) {
    $salah = [];
    foreach ($urutan as $i => $u) {
        $s = $k[$u]->req($path)['status'];
        if ($s !== $harap[$i]) {
            $salah[] = "$u=$s≠{$harap[$i]}";
        }
    }
    $assert($salah === [], "$path: " . ($salah === [] ? 'seluruh 11 akun sesuai' : implode(', ', $salah)));
}
$sebelum = [$hitung('kesehatan_kunjungan'), $hitung('kesehatan_kunjungan_versi')];
foreach (['sbx_admin', 'sbx_v5_admin_petugas', 'sbx_v5_pembimbing', 'sbx_v5_murobi', 'sbx_v5_ganda'] as $u) {
    $kl = $k[$u];
    $s1 = $kl->req('/portal/kesehatan_koreksi.php?id=' . $id, ['_csrf' => $kl->csrf(), 'idempotency_key' => $kunci('ka'), 'versi' => 3, 'waktu_kunjungan' => $waktu, 'keluhan' => 'diubah', 'alasan' => 'Percobaan tulis', 'status' => 'Tercatat'])['status'];
    $s2 = $kl->req("/portal/kesehatan_hasil_rujukan.php?id=$id&rujukan=$rid", ['_csrf' => $kl->csrf(), 'idempotency_key' => $kunci('ka'), 'versi' => 3, 'hasil' => 'x', 'alasan' => 'Percobaan tulis'])['status'];
    $s3 = $kl->req('/portal/kesehatan_buat.php', ['_csrf' => $kl->csrf(), 'idempotency_key' => $kunci('ka'), 'santri_id' => $sA, 'waktu_kunjungan' => $waktu, 'keluhan' => 'x'])['status'];
    $assert([$s1, $s2, $s3] === [403, 403, 403], "$u: POST koreksi/hasil rujukan/catat web → 403");
}
$assert([$hitung('kesehatan_kunjungan'), $hitung('kesehatan_kunjungan_versi')] === $sebelum, 'POST web pembaca/admin: 0 kunjungan/versi baru');

// ===================================================================== K-F
echo PHP_EOL . '=== K-F. API ===' . PHP_EOL;
$t = [];
foreach (['sbx_v5_petugas_b', 'sbx_v5_petugas_d', 'sbx_admin', 'sbx_v5_pembimbing', 'sbx_v5_pembimbing_b', 'sbx_v5_murobi', 'sbx_v5_ganda', 'sbx_guru_biasa'] as $u) {
    $t[$u] = $token($u);
}
$tP = $t['sbx_v5_petugas_b'];
$badan = ['santri_id' => $sB, 'waktu_kunjungan' => str_replace('T', ' ', $waktu), 'keluhan' => "$penanda-API-KELUHAN",
    'obat' => [['nama_obat' => "$penanda-API-OBAT-1", 'jumlah' => 1, 'satuan' => 'tablet'], ['nama_obat' => "$penanda-API-OBAT-2", 'jumlah' => 2.5, 'satuan' => 'ml']],
    'rujukan' => [['tujuan' => "$penanda-API-TUJUAN"]]];
$kunciApi = $kunci('api');
$r = $api('POST', '/v5/kesehatan/kunjungan', $tP, $badan, ['Idempotency-Key: ' . $kunciApi]);
$idApi = (int) ($r['json']['data']['id'] ?? 0);
$assert($r['status'] === 201 && $idApi > 0 && array_keys($r['json']['data']) === ['id', 'replayed'] && !str_contains($r['body'], $penanda) && str_contains($r['header'], 'no-store'),
    'POST API dengan dua obat → 201 {id, replayed}, tanpa isi klinis, no-store');
$r = $api('POST', '/v5/kesehatan/kunjungan', $tP, $badan, ['Idempotency-Key: ' . $kunciApi]);
$assert($r['status'] === 200 && $r['json']['data']['replayed'] === true && $hitung('kesehatan_obat', "kunjungan_id = $idApi") === 2, 'Replay API → 200 replayed, obat tetap 2');
$r = $api('GET', '/v5/kesehatan/kunjungan/' . $idApi, $tP);
$dApi = $r['json']['data'] ?? [];
$assert($r['status'] === 200 && count($dApi['obat'] ?? []) === 2 && ($dApi['obat'][1]['jumlah'] ?? '') === '2.5' && count($dApi['rujukan'] ?? []) === 1 && ($dApi['rujukan'][0]['status'] ?? '') === 'Menunggu hasil',
    'GET detail API: dua obat (jumlah 2.5), satu rujukan menunggu');
$r = $api('GET', '/v5/kesehatan/kunjungan?santri_id=' . $sB, $tP);
$baris = $r['json']['data']['rows'] ?? [];
$assert($r['status'] === 200 && count($baris) === 1 && ($baris[0]['penanda']['obat'] ?? 0) === 2 && !str_contains($r['body'], $penanda) && isset($r['json']['data']['ringkasan']),
    'GET daftar API santri B: satu baris, penanda 2 obat, ringkasan, tanpa isi');
$koreksiApi = ['versi' => 1, 'waktu_kunjungan' => $dApi['waktu_kunjungan'], 'keluhan' => "$penanda-API-KELUHAN", 'status' => 'Tercatat',
    'obat' => [$dApi['obat'][0], ['id' => $dApi['obat'][1]['id'], 'nama_obat' => "$penanda-API-OBAT-2", 'jumlah' => '3', 'satuan' => 'ml']], 'rujukan' => $dApi['rujukan']];
$r = $api('POST', "/v5/kesehatan/kunjungan/$idApi/koreksi", $tP, $koreksiApi, ['Idempotency-Key: ' . $kunci('apik')]);
$assert($r['status'] === 422 && ($r['json']['error']['code'] ?? '') === 'VALIDATION_FAILED' && (int) $satu("SELECT versi FROM kesehatan_kunjungan WHERE id = $idApi") === 1, 'Koreksi API tanpa alasan → 422');
$kunciK = $kunci('apik');
$r = $api('POST', "/v5/kesehatan/kunjungan/$idApi/koreksi", $tP, $koreksiApi + ['alasan' => 'Jumlah sirup dikoreksi'], ['Idempotency-Key: ' . $kunciK]);
$assert($r['status'] === 200 && ($r['json']['data'] ?? []) === ['id' => $idApi, 'versi' => 2, 'replayed' => false], 'Koreksi API beralasan → 200 {id, versi 2, replayed false}');
$r = $api('POST', "/v5/kesehatan/kunjungan/$idApi/koreksi", $tP, $koreksiApi + ['alasan' => 'Jumlah sirup dikoreksi'], ['Idempotency-Key: ' . $kunciK]);
$assert($r['status'] === 200 && ($r['json']['data']['replayed'] ?? null) === true && ($r['json']['data']['versi'] ?? 0) === 2, 'Replay koreksi API → versi 2 yang sama');
$r = $api('POST', "/v5/kesehatan/kunjungan/$idApi/koreksi", $t['sbx_v5_petugas_d'], $koreksiApi + ['alasan' => 'Koreksi dari versi lama'], ['Idempotency-Key: ' . $kunci('apik')]);
$assert($r['status'] === 409 && ($r['json']['error']['code'] ?? '') === 'CONFLICT' && (int) $satu("SELECT versi FROM kesehatan_kunjungan WHERE id = $idApi") === 2, 'Koreksi API dari versi lama → 409 CONFLICT');
$ridApi = (int) $dApi['rujukan'][0]['id'];
$r = $api('POST', "/v5/kesehatan/kunjungan/$idApi/rujukan/$ridApi/hasil", $tP, ['versi' => 2, 'hasil' => "$penanda-API-HASIL", 'alasan' => 'Hasil rontgen diterima'], ['Idempotency-Key: ' . $kunci('apih')]);
$assert($r['status'] === 200 && ($r['json']['data']['versi'] ?? 0) === 3 && (string) $satu("SELECT status FROM kesehatan_rujukan WHERE id = $ridApi") === 'Hasil diketahui', 'Hasil rujukan API → versi 3');
$r = $api('GET', "/v5/kesehatan/kunjungan/$idApi/versi", $tP);
$assert($r['status'] === 200 && count($r['json']['data']['versi'] ?? []) === 3 && ($r['json']['data']['versi'][1]['alasan'] ?? '') === 'Jumlah sirup dikoreksi', 'Riwayat versi API petugas: 3 versi, alasan terbaca');
$assert($api('GET', "/v5/kesehatan/kunjungan/$idApi/versi", $t['sbx_admin'])['status'] === 200, 'Riwayat versi API admin 200');
foreach (['sbx_v5_pembimbing_b', 'sbx_v5_murobi', 'sbx_v5_ganda'] as $u) {
    $assert($api('GET', "/v5/kesehatan/kunjungan/$idApi/versi", $t[$u])['status'] === 403 && $api('GET', "/v5/kesehatan/kunjungan/$idApi/akses", $t[$u])['status'] === 403, "Riwayat versi/akses API $u → 403");
}
$sebelum = [$hitung('kesehatan_kunjungan'), $hitung('kesehatan_kunjungan_versi')];
foreach (['sbx_admin', 'sbx_v5_pembimbing_b', 'sbx_v5_murobi', 'sbx_v5_ganda', 'sbx_guru_biasa'] as $u) {
    $s1 = $api('POST', '/v5/kesehatan/kunjungan', $t[$u], $badan, ['Idempotency-Key: ' . $kunci('apix')])['status'];
    $s2 = $api('POST', "/v5/kesehatan/kunjungan/$idApi/koreksi", $t[$u], array_merge($koreksiApi, ['versi' => 3, 'alasan' => 'Percobaan tulis']), ['Idempotency-Key: ' . $kunci('apix')])['status'];
    $s3 = $api('POST', "/v5/kesehatan/kunjungan/$idApi/rujukan/$ridApi/hasil", $t[$u], ['versi' => 3, 'hasil' => 'x', 'alasan' => 'Percobaan tulis'], ['Idempotency-Key: ' . $kunci('apix')])['status'];
    $assert([$s1, $s2, $s3] === [403, 403, 403], "API POST catat/koreksi/hasil $u → 403");
}
$assert([$hitung('kesehatan_kunjungan'), $hitung('kesehatan_kunjungan_versi')] === $sebelum, 'API tulis pembaca/admin: 0 baris/versi baru');

// ===================================================================== K-G
echo PHP_EOL . '=== K-G. Pembaca binaan web/API dan sesi lama ===' . PHP_EOL;
$r = $api('GET', '/v5/kesehatan/kunjungan', $t['sbx_v5_pembimbing_b']);
$santriTerlihat = array_values(array_unique(array_map(static fn (array $x): int => $x['santri']['id'], $r['json']['data']['rows'] ?? [])));
$assert($r['status'] === 200 && $santriTerlihat === [$sB] && ($r['json']['data']['akses']['cakupan'] ?? '') === 'binaan', 'API daftar pembimbing B: hanya santri B, cakupan binaan');
$assert($api('GET', '/v5/kesehatan/kunjungan/' . $idApi, $t['sbx_v5_pembimbing_b'])['status'] === 200 && $api('GET', '/v5/kesehatan/kunjungan/' . $id, $t['sbx_v5_pembimbing_b'])['status'] === 403
    && $api('GET', '/v5/kesehatan/kunjungan/' . $idLuar, $t['sbx_v5_pembimbing_b'])['status'] === 403 && $api('GET', '/v5/kesehatan/kunjungan/99999999', $t['sbx_v5_pembimbing_b'])['status'] === 403,
    'API detail pembimbing B: santri B 200; santri A, luar cakupan, dan ID tidak ada 403');
$r = $api('GET', '/v5/kesehatan/kunjungan?santri_id=' . $sLuar, $t['sbx_v5_ganda']);
$assert($r['status'] === 200 && ($r['json']['data']['total'] ?? -1) === 0 && ($r['json']['data']['ringkasan']['kunjungan'] ?? -1) === 0, 'API penyaring santri luar cakupan → kosong');
$r = $api('GET', '/v5/kesehatan/akses', $t['sbx_v5_ganda']);
$assert(($r['json']['data']['sumber'] ?? '') === 'pembimbing_murobi' && ($r['json']['data']['catat'] ?? true) === false, 'API akses ganda: pembimbing_murobi, catat=false');
$r = $k['sbx_v5_pembimbing']->req('/portal/kesehatan_detail.php?id=' . $id);
$assert($r['status'] === 200 && str_contains($teks($r['body']), "$penanda-OBAT-1") && !str_contains($r['body'], 'kesehatan_koreksi.php') && !str_contains($r['body'], 'kesehatan_riwayat.php')
    && !str_contains($r['body'], 'kesehatan_hasil_rujukan.php') && str_contains($teks($r['body']), 'Hanya baca'), 'Web pembimbing: rincian terbaca, tanpa tombol koreksi/riwayat/hasil');
foreach (['sbx_v5_pembimbing' => true, 'sbx_v5_murobi' => true, 'sbx_v5_murobi_kosong' => true, 'sbx_v5_ganda' => true, 'sbx_pengurus_biasa' => false, 'sbx_guru_biasa' => false] as $u => $ada) {
    $beranda = $k[$u]->req('/portal/index.php')['body'];
    $assert(str_contains($beranda, '/portal/kesehatan.php') === $ada, "Menu Kesehatan " . ($ada ? 'tampil' : 'tidak tampil') . " untuk $u");
}
$r = $k['sbx_v5_pembimbing']->req('/portal/kesehatan.php');
$assert(!str_contains($r['body'], 'id="status" name="status"') && !str_contains($r['body'], 'ah-stat__label">Draf<') && str_contains($teks($r['body']), 'santri binaan'), 'Daftar pembimbing tanpa penyaring/ringkasan draf');
$assert(str_contains($petugas->req('/portal/kesehatan.php')['body'], 'ah-stat__label">Draf<'), 'Kontrol: daftar petugas memuat ringkasan draf');
$aP = (int) $satu("SELECT id FROM pembimbing_assignments WHERE pengurus_id = (SELECT pengurus_id FROM users WHERE username='sbx_v5_pembimbing_b')");
$jalankan("UPDATE pembimbing_assignments SET is_active = 0 WHERE id = $aP");
$assert($k['sbx_v5_pembimbing_b']->req('/portal/kesehatan_detail.php?id=' . $idApi)['status'] === 403 && $api('GET', '/v5/kesehatan/kunjungan/' . $idApi, $t['sbx_v5_pembimbing_b'])['status'] === 403
    && $api('GET', '/v5/kesehatan/kunjungan', $t['sbx_v5_pembimbing_b'])['status'] === 403, 'Pencabutan penugasan: sesi web & token lama pembimbing B → 403');
$jalankan("UPDATE pembimbing_assignments SET is_active = 1 WHERE id = $aP");
$kamarA = (int) $satu("SELECT id FROM kamar WHERE nama_kamar = 'SBX V5 Kamar A'");
$kamarB = (int) $satu("SELECT id FROM kamar WHERE nama_kamar = 'SBX V5 Kamar B'");
$jalankan("UPDATE plotting_kamar SET id_kamar = $kamarA WHERE id_santri = $sB AND id_kamar = $kamarB");
$assert($k['sbx_v5_pembimbing_b']->req('/portal/kesehatan_detail.php?id=' . $idApi)['status'] === 403 && $api('GET', '/v5/kesehatan/kunjungan/' . $idApi, $t['sbx_v5_pembimbing_b'])['status'] === 403
    && $k['sbx_v5_pembimbing']->req('/portal/kesehatan_detail.php?id=' . $idApi)['status'] === 200, 'Santri B pindah ke Kamar A: pembimbing B tertutup (web/API), pembimbing A terbuka');
$jalankan("UPDATE plotting_kamar SET id_kamar = $kamarB WHERE id_santri = $sB AND id_kamar = $kamarA");
$jalankan("UPDATE users SET is_active = 0 WHERE username = 'sbx_v5_murobi'");
$assert($api('GET', '/v5/kesehatan/kunjungan/' . $id, $t['sbx_v5_murobi'])['status'] === 401 || $api('GET', '/v5/kesehatan/kunjungan/' . $id, $t['sbx_v5_murobi'])['status'] === 403, 'Akun murobi dinonaktifkan: token lama ditolak');
$assert(in_array($k['sbx_v5_murobi']->req('/portal/kesehatan_detail.php?id=' . $id)['status'], [302, 401, 403], true), 'Akun murobi dinonaktifkan: sesi web lama tidak membuka detail');
$jalankan("UPDATE users SET is_active = 1 WHERE username = 'sbx_v5_murobi'");

// ===================================================================== K-H
echo PHP_EOL . '=== K-H. Cache, log, dan audit ===' . PHP_EOL;
foreach (['/portal/kesehatan.php', "/portal/kesehatan_detail.php?id=$id", "/portal/kesehatan_riwayat.php?id=$id", "/portal/kesehatan_koreksi.php?id=$id"] as $path) {
    $assert(str_contains(strtolower($petugas->req($path)['header']), 'cache-control: private, no-store'), "$path: Cache-Control private, no-store");
}
$assert(str_contains(strtolower($api('GET', "/v5/kesehatan/kunjungan/$idApi/versi", $tP)['header']), 'no-store'), 'API riwayat versi: no-store');
usleep(300000);
$isiLog = (string) file_get_contents($log);
$assert(!preg_match('/PHP (Warning|Notice|Fatal|Deprecated)|Uncaught/', $isiLog), 'Log server tanpa galat/peringatan PHP');
$assert(!str_contains($isiLog, $penanda), 'Log server memuat 0 isi klinis/rincian');
$assert((int) $satu("SELECT COUNT(*) FROM audit_logs WHERE CONCAT(COALESCE(before_json,''), COALESCE(after_json,'')) LIKE '%" . $penanda . "%'") === 0, 'audit_logs memuat 0 isi klinis/rincian/alasan');
$assert((int) $satu("SELECT COUNT(*) FROM audit_logs WHERE action = 'kesehatan.kunjungan.dikoreksi'") === (int) $satu('SELECT COUNT(*) FROM kesehatan_kunjungan_versi WHERE versi > 1'),
    'Setiap versi koreksi tepat satu audit metadata');
$assert((int) $satu("SELECT COUNT(*) FROM kesehatan_akses_log WHERE sumber IN ('pembimbing', 'murobi') AND kanal = 'api'") > 0
    && (int) $satu("SELECT COUNT(*) FROM kesehatan_akses_log WHERE sumber = 'pembimbing' AND kanal = 'web'") > 0, 'Pembacaan pembimbing/murobi tercatat di log akses (web dan API)');
exec('DB_NAME=' . escapeshellarg($db) . ' php ' . escapeshellarg($root . '/bin/v5_phase2_verify.php') . ' 2>&1', $out, $kode);
$assert($kode === 0, 'Verifier 025 lulus atas data HTTP');
@unlink($log);

echo PHP_EOL . 'Lulus: ' . $lulus . ' · Gagal: ' . count($gagal) . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
