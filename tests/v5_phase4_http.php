<?php

declare(strict_types=1);

/**
 * Uji HTTP PRD V5 Fase 4 — tambahan server untuk aplikasi — pada server PHP
 * bawaan dan DB uji terpisah.
 *
 *   V5F3_DB=test_v5_f4_http_test bash tests/v5_phase3_db.sh
 *   V5F4_DB=test_v5_f4_http_test php tests/v5_phase4_http.php
 *
 *   N-A  API penugasan kesehatan: hanya admin (petugas, admin+petugas sebagai
 *        petugas tetap admin, pembimbing, murobi, wali, pengurus biasa 403),
 *        tanpa token 401, no-store, jenis lain tidak tersentuh;
 *   N-B  buat → pengurus segera dapat mencatat (token lama); tumpang tindih 409;
 *        validasi 422 berpesan; nonaktifkan/aktifkan/akhiri beralasan, tanpa
 *        alasan 422; token petugas lama ditolak sesudah dinonaktifkan;
 *        audit penugasan.* tercatat, tanpa penghapusan baris;
 *   N-C  pemilih santri berkelas: kelas + jumlah sama dengan penempatan aktif,
 *        penyaring kelas/tanpa kelas, hanya petugas; web kesehatan_buat
 *        mengelompokkan <optgroup> dengan isi sama dengan API;
 *   N-D  kontrak lama tidak berubah: kunci baris santri lama tetap ada,
 *        endpoint Fase 1–3 tetap menjawab; log server tanpa galat PHP.
 *
 * Mengubah data uji; bangun ulang DB sebelum mengulang.
 */

$root = dirname(__DIR__);
$db = getenv('V5F4_DB') ?: 'test_v5_f4_http_test';
$port = (int) (getenv('V5F4_PORT') ?: 8984);
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

$log = tempnam(sys_get_temp_dir(), 'v5f4-server-');
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

$base = 'http://127.0.0.1:' . $port;
$req = static function (string $method, string $path, array $headers = [], ?string $body = null, ?string $jar = null) use ($base): array {
    $h = curl_init($base . $path);
    curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $headers, CURLOPT_CUSTOMREQUEST => $method]);
    if ($body !== null) {
        curl_setopt($h, CURLOPT_POSTFIELDS, $body);
    }
    if ($jar !== null) {
        curl_setopt_array($h, [CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    }
    $out = (string) curl_exec($h);
    $status = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
    $size = (int) curl_getinfo($h, CURLINFO_HEADER_SIZE);
    curl_close($h);

    return ['status' => $status, 'header' => substr($out, 0, $size), 'body' => substr($out, $size), 'json' => json_decode(substr($out, $size), true)];
};
$tokens = [];
$api = static function (string $user, string $method, string $path, ?array $body = null) use ($req, &$tokens): array {
    if (!isset($tokens[$user])) {
        $r = $req('POST', '/api/v1/auth/login', ['Content-Type: application/json'], (string) json_encode(['username' => $user, 'password' => 'Sandbox#123', 'device_name' => 'uji-v5-f4']));
        $tokens[$user] = (string) ($r['json']['data']['token'] ?? '');
    }

    return $req($method, '/api/v1' . $path, ['Accept: application/json', 'Content-Type: application/json', 'Authorization: Bearer ' . $tokens[$user]], $body === null ? null : (string) json_encode($body));
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
$kunci = static fn (string $awal): string => $awal . '-' . bin2hex(random_bytes(6));

// ===================================================================== N-A
echo PHP_EOL . '=== N-A. Gerbang API penugasan kesehatan ===' . PHP_EOL;
$r = $req('GET', '/api/v1/v5/kesehatan/penugasan', ['Accept: application/json']);
$assert($r['status'] === 401, 'Tanpa token → 401');
$r = $api('sbx_admin', 'GET', '/v5/kesehatan/penugasan');
$assert($r['status'] === 200 && stripos($r['header'], 'Cache-Control: private, no-store') !== false, 'Admin: 200 dengan Cache-Control private, no-store');
$data = $r['json']['data'];
$jumlahDb = (int) $satu('SELECT COUNT(*) FROM kesehatan_assignments');
$assert($data['total'] === $jumlahDb && count($data['opsi']['pengurus']) > 0 && count($data['opsi']['tahun']) > 0, "Admin: daftar = seluruh penugasan kesehatan ($jumlahDb) + opsi pengurus/tahun");
$kunciBaris = array_keys($data['rows'][0] ?? []);
sort($kunciBaris);
$assert($kunciBaris === ['aktif', 'akun', 'alasan_pengakhiran', 'catatan', 'diperbarui_pada', 'id', 'pengurus', 'status', 'tahun', 'tanggal_mulai', 'tanggal_selesai'], 'DTO penugasan hanya kolom yang ditentukan (tanpa token/kata sandi/role mentah)');
$assert(!str_contains($r['body'], 'password') && !str_contains($r['body'], 'token_hash'), 'Respons tanpa kata sandi/token');
$r = $api('sbx_v5_admin_petugas', 'GET', '/v5/kesehatan/penugasan');
$assert($r['status'] === 200, 'Admin yang juga petugas: membaca sebagai admin');
foreach (['sbx_v5_petugas_f', 'sbx_v5_pembimbing', 'sbx_v5_murobi', 'sbx_v5_wali_1', 'sbx_pengurus_biasa', 'sbx_guru_biasa'] as $u) {
    $a = $api($u, 'GET', '/v5/kesehatan/penugasan')['status'];
    $b = $api($u, 'POST', '/v5/kesehatan/penugasan', ['pengurus_id' => 1, 'tahun_ajaran_id' => 1, 'tanggal_mulai' => date('Y-m-d')])['status'];
    $c = $api($u, 'POST', '/v5/kesehatan/penugasan/1/nonaktifkan', ['alasan' => 'Percobaan bukan admin'])['status'];
    $assert([$a, $b, $c] === [403, 403, 403], "$u: daftar/buat/nonaktifkan → $a/$b/$c (harap 403)");
}
$r = $api('sbx_admin', 'POST', '/v5/kesehatan/penugasan/1/hapus', ['alasan' => 'x']);
$assert($r['status'] === 404, 'Aksi tidak dikenal (hapus) → 404; tidak ada penghapusan');

// ===================================================================== N-B
echo PHP_EOL . '=== N-B. Mutasi penugasan dari aplikasi ===' . PHP_EOL;
$pengurusBaru = (int) $satu("SELECT pengurus_id FROM users WHERE username='sbx_v5_petugas'");
$tahun = (int) $satu("SELECT id FROM tahun_ajaran WHERE status='Aktif' AND archived_at IS NULL ORDER BY id DESC LIMIT 1");
$assert($api('sbx_v5_petugas', 'GET', '/v5/kesehatan/akses')['json']['data']['catat'] === false, 'sbx_v5_petugas (pengurus) belum dapat mencatat');
$r = $api('sbx_admin', 'POST', '/v5/kesehatan/penugasan', ['pengurus_id' => $pengurusBaru, 'tahun_ajaran_id' => $tahun, 'tanggal_mulai' => '']);
$assert($r['status'] === 422 && isset($r['json']['error']['details']['tanggal_mulai']), 'Tanpa tanggal mulai → 422 dengan rincian kolom');
$sebelum = (int) $satu('SELECT COUNT(*) FROM kesehatan_assignments');
$r = $api('sbx_admin', 'POST', '/v5/kesehatan/penugasan', ['pengurus_id' => $pengurusBaru, 'tahun_ajaran_id' => $tahun, 'tanggal_mulai' => date('Y-m-d'), 'catatan' => 'Dari aplikasi']);
$pid = (int) ($r['json']['data']['id'] ?? 0);
$assert($r['status'] === 201 && $pid > 0 && $r['json']['data']['penugasan']['status'] === 'Aktif' && $r['json']['data']['hak'] === null, 'Admin membuat penugasan → 201, Aktif, hak efektif');
$assert($api('sbx_v5_petugas', 'GET', '/v5/kesehatan/akses')['json']['data']['catat'] === true, 'Token pengurus yang sudah masuk langsung dapat mencatat');
$r = $api('sbx_admin', 'POST', '/v5/kesehatan/penugasan', ['pengurus_id' => $pengurusBaru, 'tahun_ajaran_id' => $tahun, 'tanggal_mulai' => date('Y-m-d')]);
$assert($r['status'] === 409 && (int) $satu('SELECT COUNT(*) FROM kesehatan_assignments') === $sebelum + 1, 'Kiriman ulang/tumpang tindih → 409 tanpa baris kedua');
$sid = (int) $satu("SELECT id FROM santri WHERE nis='V5S0201'");
$buat = $api('sbx_v5_petugas', 'POST', '/v5/kesehatan/kunjungan', ['santri_id' => $sid, 'waktu_kunjungan' => date('Y-m-d H:i', strtotime('-1 hour')), 'keluhan' => 'Keluhan uji Fase 4', 'idempotency_key' => $kunci('n-b')]);
$assert($buat['status'] === 201, 'Petugas baru mencatat kunjungan → 201');
$r = $api('sbx_admin', 'POST', "/v5/kesehatan/penugasan/$pid/nonaktifkan", ['alasan' => '']);
$assert($r['status'] === 422, 'Nonaktifkan tanpa alasan → 422');
$r = $api('sbx_admin', 'POST', "/v5/kesehatan/penugasan/$pid/nonaktifkan", ['alasan' => 'Dipindah tugas']);
$assert($r['status'] === 200 && $r['json']['data']['penugasan']['status'] === 'Dinonaktifkan', 'Nonaktifkan beralasan → Dinonaktifkan');
$r = $api('sbx_admin', 'POST', "/v5/kesehatan/penugasan/$pid/nonaktifkan", ['alasan' => 'Dipindah tugas lagi']);
$assert($r['status'] === 409, 'Nonaktifkan dua kali → 409');
$r = $api('sbx_v5_petugas', 'POST', '/v5/kesehatan/kunjungan', ['santri_id' => $sid, 'waktu_kunjungan' => date('Y-m-d H:i', strtotime('-1 hour')), 'keluhan' => 'Sesudah dicabut', 'idempotency_key' => $kunci('n-b2')]);
$assert($r['status'] === 403 && (int) $satu("SELECT COUNT(*) FROM kesehatan_kunjungan WHERE keluhan='Sesudah dicabut'") === 0, 'Token lama sesudah penugasan dinonaktifkan → 403 tanpa baris');
$r = $api('sbx_admin', 'POST', "/v5/kesehatan/penugasan/$pid/aktifkan", ['alasan' => 'Kembali bertugas']);
$assert($r['status'] === 200 && $r['json']['data']['penugasan']['status'] === 'Aktif', 'Aktifkan kembali beralasan → Aktif');
$r = $api('sbx_admin', 'POST', "/v5/kesehatan/penugasan/$pid/akhiri", ['alasan' => 'Masa tugas selesai', 'tanggal_selesai' => date('Y-m-d', strtotime('-1 day'))]);
$assert($r['status'] === 422, 'Akhiri dengan tanggal sebelum mulai → 422');
$r = $api('sbx_admin', 'POST', "/v5/kesehatan/penugasan/$pid/akhiri", ['alasan' => 'Masa tugas selesai', 'tanggal_selesai' => date('Y-m-d')]);
$assert($r['status'] === 200 && $r['json']['data']['penugasan']['tanggal_selesai'] === date('Y-m-d') && $r['json']['data']['penugasan']['alasan_pengakhiran'] === 'Masa tugas selesai', 'Akhiri beralasan hari ini → tanggal selesai + alasan tersimpan');
$assert((int) $satu('SELECT COUNT(*) FROM kesehatan_assignments') === $sebelum + 1, 'Tidak ada baris penugasan yang dihapus');
$aksi = (string) $satu("SELECT GROUP_CONCAT(action ORDER BY id) FROM audit_logs WHERE entity_type='kesehatan_assignment' AND entity_id=$pid");
// Penolakan nonaktif ganda (409) juga diaudit layanan sebagai penolakan.
$assert($aksi === 'penugasan.buat,penugasan.nonaktifkan,penugasan.tolak_tumpang_tindih,penugasan.aktifkan,penugasan.akhiri', "Audit penugasan berurutan termasuk penolakan: $aksi");

// ===================================================================== N-C
echo PHP_EOL . '=== N-C. Pemilih santri berkelas ===' . PHP_EOL;
$r = $api('sbx_v5_petugas_f', 'GET', '/v5/kesehatan/santri/kelas');
$kelas = $r['json']['data'] ?? [];
$tahunSql = "(SELECT id FROM tahun_ajaran WHERE status='Aktif' AND archived_at IS NULL ORDER BY id DESC LIMIT 1)";
$jumlahDitempatkan = (int) $satu("SELECT COUNT(*) FROM plotting_kelas pk JOIN santri s ON s.id=pk.id_santri AND s.is_active=1 AND s.archived_at IS NULL WHERE pk.status='Aktif' AND pk.id_tahun=$tahunSql");
$jumlahAktif = (int) $satu('SELECT COUNT(*) FROM santri WHERE is_active=1 AND archived_at IS NULL');
$assert($r['status'] === 200 && array_sum(array_column($kelas['kelas'], 'jumlah')) === $jumlahDitempatkan && $kelas['tanpa_kelas'] === $jumlahAktif - $jumlahDitempatkan,
    "Kelas + jumlah = penempatan aktif ($jumlahDitempatkan) dan tanpa kelas = sisa santri aktif ({$kelas['tanpa_kelas']})");
$kelasX = (int) $satu("SELECT id FROM kelas WHERE nama_kelas='SBX V5 Kelas X'");
$r = $api('sbx_v5_petugas_f', 'GET', "/v5/kesehatan/santri?kelas_id=$kelasX");
$assert($r['status'] === 200 && array_column($r['json']['data']['rows'], 'nis') === ['V5S0101'] && $r['json']['data']['rows'][0]['kelas']['id'] === $kelasX, 'Penyaring satu kelas hanya santri kelas itu, dengan kelasnya');
$r = $api('sbx_v5_petugas_f', 'GET', '/v5/kesehatan/santri?q=V5S020&kelas_id=tanpa');
$assert($r['status'] === 200 && count($r['json']['data']['rows']) === 2 && array_unique(array_column($r['json']['data']['rows'], 'kelas')) === [null], 'Penyaring "tanpa" hanya santri belum ditempatkan');
$r = $api('sbx_v5_petugas_f', 'GET', '/v5/kesehatan/santri?q=V5S01');
$rows = $r['json']['data']['rows'];
$assert(array_keys($rows[0]) === ['id', 'nis', 'nama', 'kelas'] && $rows[0]['kelas'] !== null && end($rows)['kelas'] === null, 'Baris pemilih berkunci lama + kelas; urut kelas dulu, belum ditempatkan paling akhir');
foreach (['sbx_admin', 'sbx_v5_pembimbing', 'sbx_v5_wali_1'] as $u) {
    $a = $api($u, 'GET', '/v5/kesehatan/santri/kelas')['status'];
    $b = $api($u, 'GET', '/v5/kesehatan/santri')['status'];
    $assert([$a, $b] === [403, 403], "$u: kelas pemilih/santri pemilih → $a/$b (harap 403)");
}
// Web: <select> dikelompokkan <optgroup> per kelas, isi sama dengan API.
$jar = tempnam(sys_get_temp_dir(), 'v5f4-jar-');
$hal = $req('GET', '/portal/index.php', [], null, $jar);
preg_match('/name="_csrf" value="([^"]+)"/', $hal['body'], $m);
$req('POST', '/admin/cek_login.php', ['Content-Type: application/x-www-form-urlencoded'], http_build_query(['_csrf' => $m[1] ?? '', 'username' => 'sbx_v5_petugas_f', 'password' => 'Sandbox#123']), $jar);
$hal = $req('GET', '/portal/kesehatan_buat.php?q=V5S01', [], null, $jar);
preg_match_all('/<optgroup label="([^"]+)">/', $hal['body'], $grup);
$apiGrup = array_values(array_unique(array_map(static fn (array $s): string => $s['kelas']['nama'] ?? 'Belum ditempatkan di kelas', $rows)));
$assert($hal['status'] === 200 && array_map('html_entity_decode', $grup[1]) === $apiGrup, 'Web: <optgroup> per kelas sama urutan dengan API (' . implode(', ', $apiGrup) . ')');
preg_match_all('/<option value="(\d+)"/', $hal['body'], $opsi);
$assert(array_map('intval', $opsi[1]) === array_column($rows, 'id'), 'Web: opsi santri identik dengan API');

// ===================================================================== N-D
echo PHP_EOL . '=== N-D. Kontrak lama dan log ===' . PHP_EOL;
foreach (['/v5/kesehatan/akses', '/v5/kesehatan/kunjungan', '/v5/kesehatan/santri?q=V5S0201', '/v5/kesehatan/wali/akses'] as $p) {
    $assert($api('sbx_v5_petugas_f', 'GET', $p)['status'] === 200, "Petugas $p tetap 200");
}
$isiLog = (string) file_get_contents($log);
$assert(!preg_match('/PHP (Fatal|Warning|Notice|Deprecated)|Stack trace/', $isiLog), 'Log server tanpa galat PHP');
$assert(!str_contains($isiLog, 'Keluhan uji Fase 4') && !str_contains($isiLog, 'Sandbox#123'), 'Log server tanpa isi klinis atau kata sandi');

echo PHP_EOL . 'Hasil: ' . $lulus . ' lulus, ' . count($gagal) . ' gagal' . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
