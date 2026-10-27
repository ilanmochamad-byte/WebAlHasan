<?php

declare(strict_types=1);

/**
 * Uji server PRD V7 Fase 4 — kontrak REST yang dipakai aplikasi (bearer token,
 * bukan sesi web), lewat server PHP lokal di atas DB `tests/v7_phase4_db.sh`.
 *
 *   V7F4_DB=test_v7_f4_http_test bash tests/v7_phase4_db.sh
 *   V7F4_DB=test_v7_f4_http_test php tests/v7_phase4_http.php
 *
 *   A-A  akses per peran; dua kiriman berkunci sama → satu ID; kunci sama isi
 *        berbeda → 409; Cache-Control no-store
 *   A-B  wali kedua ringkas tanpa alasan/tindakan dan ditolak saat memutasi;
 *        wali asing, pembimbing lain, murobi sebelum penerusan → 404/kosong
 *   A-C  minta perbaikan → perbaiki (versi lama 409) → teruskan; V2 memuat
 *        `asal:"Wali"` bagi murobi/admin; keputusan V2 ganda → satu keputusan;
 *        status sama di rincian V7 wali/murobi/admin dan web
 *   A-D  routing ambigu → admin menetapkan pembimbing sah (bukan sah → 422);
 *        wali pengaju membatalkan; pembatalan sesudah keputusan → 409
 *   A-E  pencabutan penugasan/relasi → token lama 404; notifikasi tautan
 *        `izin_wali` tanpa nama/alasan; `asal:"Pengurus"` untuk baris lain
 *   A-F  log server tanpa galat PHP/isi izin
 *
 * Mengubah data uji; bangun ulang DB sebelum mengulang.
 */

$root = dirname(__DIR__);
$db = getenv('V7F4_DB') ?: 'test_v7_f4_http_test';
$port = (int) (getenv('V7F4_PORT') ?: 8974);
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

$log = tempnam(sys_get_temp_dir(), 'v7f4-server-');
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
$api = static function (string $method, string $path, ?string $token, ?array $body = null) use ($base): array {
    $h = curl_init($base . '/api/v1' . $path);
    $headers = ['Accept: application/json', 'Content-Type: application/json'];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $headers]);
    if ($method !== 'GET') {
        curl_setopt($h, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($h, CURLOPT_POSTFIELDS, (string) json_encode($body ?? []));
    }
    $out = (string) curl_exec($h);
    $size = (int) curl_getinfo($h, CURLINFO_HEADER_SIZE);
    $status = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
    curl_close($h);
    $json = json_decode(substr($out, $size), true);

    return ['status' => $status, 'header' => substr($out, 0, $size), 'body' => substr($out, $size), 'data' => $json['data'] ?? null, 'json' => $json];
};
$token = static function (string $user) use ($api): string {
    $r = $api('POST', '/auth/login', null, ['username' => $user, 'password' => 'Sandbox#123', 'device_name' => 'uji-v7-f4-app']);

    return (string) ($r['data']['token'] ?? '');
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
$sid = static fn (string $nis): int => (int) $satu("SELECT id FROM santri WHERE nis = '$nis'");
$hitung = static fn (string $tabel, string $where): int => (int) $satu("SELECT COUNT(*) FROM $tabel WHERE $where");
$kunci = static fn (string $p): string => 'v7f4-' . $p . '-' . bin2hex(random_bytes(6));
$PENANDA = 'RAHASIA-APP-V7F4';

$akun = ['sbx_admin', 'sbx_v7_wali_a', 'sbx_v7_wali_b', 'sbx_ortu_b', 'sbx_v7_pembimbing_satu', 'sbx_v7_pembimbing_dua', 'sbx_murobi_a', 'sbx_murobi_b'];
$t = [];
foreach ($akun as $u) {
    $t[$u] = $token($u);
}
$assert(count(array_filter($t)) === count($akun), 'Token API untuk delapan akun (seperti aplikasi)');
$sSatu = $sid('V7S0001');
$sDua = $sid('V7S0003');
$assert($sSatu > 0 && $sDua > 0 && $hitung('izin_pengajuan', "asal_pengajuan = 'Wali'") === 0, 'Fixture V7 tersedia; belum ada pengajuan wali');

// ===================================================================== A-A
echo PHP_EOL . '=== A-A. Akses, buat, idempotensi ===' . PHP_EOL;
$akses = static fn (string $u): array => $api('GET', '/v7/izin/akses', $t[$u])['data'] ?? [];
$a = $akses('sbx_v7_wali_a');
$assert(($a['wali'] ?? false) && ($a['boleh_mengajukan'] ?? false) && !($a['pembimbing'] ?? true) && !($a['admin'] ?? true), 'Akses wali A: wali, boleh mengajukan, bukan petugas');
$assert(($akses('sbx_v7_pembimbing_satu')['pembimbing'] ?? false) && ($akses('sbx_admin')['admin'] ?? false) && ($akses('sbx_murobi_a')['murobi'] ?? false),
    'Akses pembimbing, admin, dan murobi sesuai peran server');
$anak = $api('GET', '/v7/izin/wali/anak', $t['sbx_v7_wali_a'])['data']['items'] ?? [];
$assert(in_array($sSatu, array_column($anak, 'id'), true) && !in_array($sid('V7S0005'), array_column($anak, 'id'), true) || in_array($sSatu, array_column($anak, 'id'), true),
    'Daftar anak wali A memuat V7S0001 (pilih anak di aplikasi)');
$kBuat = $kunci('buat');
$isi = ['santri_id' => $sSatu, 'tgl_izin' => '2027-11-02', 'tgl_kembali' => '2027-11-03', 'alasan' => $PENANDA . ' acara keluarga', 'catatan' => 'Dijemput ayah', 'idempotency_key' => $kBuat];
$r1 = $api('POST', '/v7/izin/wali/pengajuan', $t['sbx_v7_wali_a'], $isi);
$r2 = $api('POST', '/v7/izin/wali/pengajuan', $t['sbx_v7_wali_a'], $isi);
$id = (int) ($r1['data']['id'] ?? 0);
$assert($r1['status'] === 201 && $r2['status'] === 200 && ($r2['data']['idempotent_replay'] ?? false) && (int) ($r2['data']['id'] ?? 0) === $id,
    'Kirim ulang berkunci sama (retry jaringan/ketukan ganda) → ID sama, replay 200');
$assert($hitung('izin_pengajuan', "santri_id = $sSatu AND asal_pengajuan = 'Wali'") === 1, 'Tepat satu baris pengajuan wali');
$r = $api('POST', '/v7/izin/wali/pengajuan', $t['sbx_v7_wali_a'], ['tgl_kembali' => '2027-11-04'] + $isi);
$assert($r['status'] === 409 && $hitung('izin_pengajuan', "santri_id = $sSatu AND asal_pengajuan = 'Wali'") === 1, 'Kunci sama isi berbeda → 409 tanpa baris baru');
$r = $api('POST', '/v7/izin/wali/pengajuan', $t['sbx_v7_wali_a'], ['idempotency_key' => $kunci('bentur')] + $isi);
$assert($r['status'] === 409 && str_contains((string) ($r['json']['error']['message'] ?? ''), 'bersinggungan'), 'Rentang bertumpang tindih (kunci baru) → 409 berpesan Indonesia');
$assert(str_contains(strtolower($r1['header']), 'cache-control: private, no-store'), 'Respons V7 no-store (tidak disimpan cache perangkat/proksi)');

// ===================================================================== A-B
echo PHP_EOL . '=== A-B. Wali kedua, wali asing, pengguna di luar cakupan ===' . PHP_EOL;
$dWaliA = $api('GET', "/v7/izin/pengajuan/$id", $t['sbx_v7_wali_a'])['data'] ?? [];
$assert(($dWaliA['peran_akses'] ?? '') === 'wali' && ($dWaliA['pengajuan']['pengaju_sendiri'] ?? false) && ($dWaliA['tindakan']['batalkan'] ?? false) && !($dWaliA['tindakan']['perbaiki'] ?? true),
    'Wali pengaju: isi + tombol batal; perbaiki belum (belum diminta)');
$listB = $api('GET', '/v7/izin/wali/pengajuan', $t['sbx_v7_wali_b'])['data']['items'] ?? [];
$itemB = array_values(array_filter($listB, static fn ($x) => $x['id'] === $id))[0] ?? [];
$dB = $api('GET', "/v7/izin/pengajuan/$id", $t['sbx_v7_wali_b']);
$assert(($itemB['ringkas'] ?? false) && !array_key_exists('alasan', $itemB) && ($dB['data']['tindakan'] ?? null) === [] && !str_contains($dB['body'], $PENANDA) && !str_contains(json_encode($listB), $PENANDA),
    'Wali kedua: daftar/rincian ringkas tanpa alasan, catatan, atau tindakan');
$versi = (int) $dWaliA['pengajuan']['version'];
$r = $api('POST', "/v7/izin/pengajuan/$id/pembatalan", $t['sbx_v7_wali_b'], ['alasan' => 'Coba batal', 'version' => $versi, 'idempotency_key' => $kunci('batal-b')]);
$assert($r['status'] === 403 && $satu("SELECT status FROM izin_pengajuan WHERE id = $id") === 'Menunggu Pembimbing', 'Wali kedua membatalkan → 403 tanpa perubahan');
foreach (['sbx_ortu_b' => 'Wali asing', 'sbx_v7_pembimbing_dua' => 'Pembimbing lain', 'sbx_murobi_a' => 'Murobi sebelum penerusan'] as $u => $label) {
    $r = $api('GET', "/v7/izin/pengajuan/$id", $t[$u]);
    $assert(in_array($r['status'], [403, 404], true) && !str_contains($r['body'], $PENANDA), "$label: rincian ditolak ({$r['status']}) tanpa isi");
}
$assert($api('GET', '/v7/izin/pengajuan/99999999', $t['sbx_v7_wali_a'])['status'] === 404, 'ID tebakan → 404');
$assert(!in_array($id, array_column($api('GET', '/v7/izin/wali/pengajuan', $t['sbx_ortu_b'])['data']['items'] ?? [], 'id'), true), 'Daftar wali asing tidak memuat pengajuan');
$assert(!in_array($id, array_column($api('GET', '/v7/izin/pembimbing/antrean', $t['sbx_v7_pembimbing_dua'])['data']['items'] ?? [], 'id'), true), 'Antrean pembimbing lain tidak memuat pengajuan');
$assert(!in_array($id, array_column($api('GET', '/izin/antrean?mode=murobi', $t['sbx_murobi_a'])['data']['items'] ?? [], 'id'), true), 'Antrean murobi V2 belum memuat pengajuan sebelum diteruskan');
$r = $api('POST', "/v7/izin/pengajuan/$id/tinjauan", $t['sbx_v7_pembimbing_dua'], ['tindakan' => 'teruskan', 'version' => $versi, 'idempotency_key' => $kunci('tinjau-lain')]);
$assert($r['status'] === 404, 'Pembimbing lain meninjau → 404');

// ===================================================================== A-C
echo PHP_EOL . '=== A-C. Perbaikan, penerusan, keputusan V2 ===' . PHP_EOL;
$antrean = $api('GET', '/v7/izin/pembimbing/antrean', $t['sbx_v7_pembimbing_satu'])['data']['items'] ?? [];
$assert(in_array($id, array_column($antrean, 'id'), true), 'Pembimbing tujuan melihat satu item di antrean');
$kMinta = $kunci('minta');
$minta = ['tindakan' => 'minta_perbaikan', 'catatan' => 'Mohon perjelas jam penjemputan', 'version' => $versi, 'idempotency_key' => $kMinta];
$m1 = $api('POST', "/v7/izin/pengajuan/$id/tinjauan", $t['sbx_v7_pembimbing_satu'], $minta);
$m2 = $api('POST', "/v7/izin/pengajuan/$id/tinjauan", $t['sbx_v7_pembimbing_satu'], $minta);
$assert($m1['status'] === 200 && ($m2['data']['idempotent_replay'] ?? false) && $hitung('izin_pembimbing_tinjauan', "pengajuan_id = $id") === 1, 'Minta perbaikan diulang → satu tinjauan');
$r = $api('POST', "/v7/izin/pengajuan/$id/tinjauan", $t['sbx_v7_pembimbing_satu'], ['tindakan' => 'teruskan', 'version' => $versi, 'idempotency_key' => $kunci('basi')]);
$assert($r['status'] === 409, 'Versi lama (layar basi) → 409');
$dWaliA = $api('GET', "/v7/izin/pengajuan/$id", $t['sbx_v7_wali_a'])['data'] ?? [];
$assert(($dWaliA['tindakan']['perbaiki'] ?? false) && ($dWaliA['tinjauan'][0]['catatan'] ?? '') === 'Mohon perjelas jam penjemputan', 'Wali pengaju melihat catatan perbaikan dan tombol perbaiki');
$versi = (int) $dWaliA['pengajuan']['version'];
$r = $api('POST', "/v7/izin/pengajuan/$id/perbaikan", $t['sbx_v7_wali_b'], ['tgl_izin' => '2027-11-02', 'tgl_kembali' => '2027-11-03', 'alasan' => 'Ubah', 'version' => $versi, 'idempotency_key' => $kunci('perbaiki-b')]);
$assert($r['status'] === 403, 'Wali kedua memperbaiki → 403');
$perbaiki = ['tgl_izin' => '2027-11-02', 'tgl_kembali' => '2027-11-03', 'alasan' => $PENANDA . ' acara keluarga, dijemput 15.00', 'catatan' => 'Dijemput ayah', 'version' => $versi, 'idempotency_key' => $kunci('perbaiki')];
$p1 = $api('POST', "/v7/izin/pengajuan/$id/perbaikan", $t['sbx_v7_wali_a'], $perbaiki);
$p2 = $api('POST', "/v7/izin/pengajuan/$id/perbaikan", $t['sbx_v7_wali_a'], $perbaiki);
$assert($p1['status'] === 200 && ($p2['data']['idempotent_replay'] ?? false) && $hitung('izin_wali_perbaikan', "pengajuan_id = $id") === 1, 'Perbaikan diulang → satu versi perbaikan');
$versi = (int) $p1['data']['version'];
$kTeruskan = ['tindakan' => 'teruskan', 'catatan' => 'Layak', 'version' => $versi, 'idempotency_key' => $kunci('teruskan')];
$f1 = $api('POST', "/v7/izin/pengajuan/$id/tinjauan", $t['sbx_v7_pembimbing_satu'], $kTeruskan);
$f2 = $api('POST', "/v7/izin/pengajuan/$id/tinjauan", $t['sbx_v7_pembimbing_satu'], $kTeruskan);
$assert(($f1['data']['status'] ?? '') === 'Diajukan' && ($f2['data']['idempotent_replay'] ?? false) && $hitung('izin_pengajuan', "santri_id = $sSatu AND asal_pengajuan = 'Wali'") === 1,
    'Teruskan diulang → satu penerusan, ID sama, status Diajukan (murobi tunggal)');
$v2Murobi = $api('GET', "/izin/pengajuan/$id?mode=murobi", $t['sbx_murobi_a']);
$assert($v2Murobi['status'] === 200 && ($v2Murobi['data']['pengajuan']['asal'] ?? '') === 'Wali' && ($v2Murobi['data']['aksi']['putuskan_murobi'] ?? false) && !($v2Murobi['data']['aksi']['batalkan'] ?? true),
    'V2 murobi: `asal:"Wali"`, dapat memutus, tidak dapat membatalkan');
$antreanM = $api('GET', '/izin/antrean?mode=murobi', $t['sbx_murobi_a'])['data']['items'] ?? [];
$itemM = array_values(array_filter($antreanM, static fn ($x) => $x['id'] === $id))[0] ?? [];
$assert(($itemM['asal'] ?? '') === 'Wali', 'Antrean murobi V2 memuat pengajuan dengan `asal:"Wali"`');
$dMurobi = $api('GET', "/v7/izin/pengajuan/$id", $t['sbx_murobi_a'])['data'] ?? [];
$assert(($dMurobi['peran_akses'] ?? '') === 'murobi' && ($dMurobi['tindakan']['putuskan'] ?? false), 'Rincian V7 murobi: tindakan putuskan (lewat V2)');
$assert($api('GET', "/izin/pengajuan/$id?mode=murobi", $t['sbx_murobi_b'])['status'] >= 403, 'Murobi lain → ditolak V2');
$versiV2 = (int) $v2Murobi['data']['pengajuan']['version'];
$kep = ['hasil' => 'Disetujui', 'alasan' => 'Sesuai jadwal', 'version' => $versiV2, 'idempotency_key' => $kunci('putus')];
$k1 = $api('POST', "/izin/pengajuan/$id/keputusan?mode=murobi", $t['sbx_murobi_a'], $kep);
$k2 = $api('POST', "/izin/pengajuan/$id/keputusan?mode=murobi", $t['sbx_murobi_a'], $kep);
$assert($k1['status'] === 201 && $k2['status'] === 200 && ($k2['data']['idempotent_replay'] ?? false) && $hitung('izin_keputusan', "pengajuan_id = $id") === 1, 'Ketukan ganda keputusan murobi → satu keputusan efektif');
$status = static fn (string $u): string => (string) ($api('GET', "/v7/izin/pengajuan/$id", $t[$u])['data']['pengajuan']['status'] ?? '');
$assert($status('sbx_v7_wali_a') === 'Disetujui' && $status('sbx_v7_wali_b') === 'Disetujui' && $status('sbx_murobi_a') === 'Disetujui' && $status('sbx_admin') === 'Disetujui'
    && $satu("SELECT status FROM izin_pengajuan WHERE id = $id") === 'Disetujui', 'Status sama bagi wali pengaju, wali kedua, murobi, admin, dan basis data');
$dWaliA = $api('GET', "/v7/izin/pengajuan/$id", $t['sbx_v7_wali_a'])['data'] ?? [];
$assert(($dWaliA['keputusan']['hasil'] ?? '') === 'Disetujui' && ($dWaliA['keputusan']['oleh'] ?? '') === 'Murobi' && ($dWaliA['tindakan']['batalkan'] ?? true) === false,
    'Wali pengaju melihat keputusan murobi; batal tidak lagi ditawarkan');
$r = $api('POST', "/v7/izin/pengajuan/$id/pembatalan", $t['sbx_v7_wali_a'], ['alasan' => 'Terlambat', 'version' => (int) $dWaliA['pengajuan']['version'], 'idempotency_key' => $kunci('batal-telat')]);
$assert($r['status'] === 409 && $hitung('izin_keputusan', "pengajuan_id = $id") === 1, 'Pembatalan sesudah keputusan → 409, keputusan tetap satu');

// ===================================================================== A-D
echo PHP_EOL . '=== A-D. Routing ambigu, penetapan admin, pembatalan wali ===' . PHP_EOL;
$r = $api('POST', '/v7/izin/wali/pengajuan', $t['sbx_v7_wali_a'], ['santri_id' => $sDua, 'tgl_izin' => '2027-11-10', 'tgl_kembali' => '2027-11-10', 'alasan' => $PENANDA . ' periksa gigi', 'idempotency_key' => $kunci('ambigu')]);
$id2 = (int) ($r['data']['id'] ?? 0);
$assert(($r['data']['status'] ?? '') === 'Perlu Penetapan Pembimbing', 'Dua kandidat pembimbing → antrean admin');
$adm = $api('GET', '/v7/izin/admin/pengajuan?antrean=1', $t['sbx_admin'])['data'] ?? [];
$assert(in_array($id2, array_column($adm['items'] ?? [], 'id'), true) && !in_array($id2, array_column($api('GET', '/izin/antrean?mode=murobi', $t['sbx_murobi_a'])['data']['items'] ?? [], 'id'), true),
    'Antrean admin memuat pengajuan; murobi tidak');
$dAdm = $api('GET', "/v7/izin/pengajuan/$id2", $t['sbx_admin'])['data'] ?? [];
$kand = $api('GET', "/v7/izin/pengajuan/$id2/kandidat-pembimbing", $t['sbx_admin'])['data']['items'] ?? [];
$assert(($dAdm['tindakan']['tetapkan_pembimbing'] ?? false) && count($kand) === 2, 'Admin: tindakan tetapkan pembimbing, dua kandidat sah');
$pDua = (int) $satu("SELECT pengurus_id FROM users WHERE username = 'sbx_v7_pembimbing_dua'");
$r = $api('POST', "/v7/izin/pengajuan/$id2/penetapan-pembimbing", $t['sbx_admin'], ['pengurus_id' => 999999, 'alasan' => 'Uji', 'version' => (int) $dAdm['pengajuan']['version'], 'idempotency_key' => $kunci('tetap-salah')]);
$assert($r['status'] === 422, 'Penetapan pengurus bukan pembimbing sah → 422');
$r = $api('POST', "/v7/izin/pengajuan/$id2/penetapan-pembimbing", $t['sbx_v7_wali_a'], ['pengurus_id' => $pDua, 'alasan' => 'Uji', 'version' => (int) $dAdm['pengajuan']['version'], 'idempotency_key' => $kunci('tetap-wali')]);
$assert($r['status'] === 403, 'Wali menetapkan pembimbing → 403');
$r = $api('POST', "/v7/izin/pengajuan/$id2/penetapan-pembimbing", $t['sbx_admin'], ['pengurus_id' => $pDua, 'alasan' => 'Pembimbing kelas', 'version' => (int) $dAdm['pengajuan']['version'], 'idempotency_key' => $kunci('tetap')]);
$assert($r['status'] === 200 && in_array($id2, array_column($api('GET', '/v7/izin/pembimbing/antrean', $t['sbx_v7_pembimbing_dua'])['data']['items'] ?? [], 'id'), true),
    'Admin menetapkan pembimbing sah → masuk antrean pembimbing tersebut');
$dW = $api('GET', "/v7/izin/pengajuan/$id2", $t['sbx_v7_wali_a'])['data'] ?? [];
$batal = ['alasan' => 'Jadwal berubah', 'version' => (int) $dW['pengajuan']['version'], 'idempotency_key' => $kunci('batal')];
$b1 = $api('POST', "/v7/izin/pengajuan/$id2/pembatalan", $t['sbx_v7_wali_a'], $batal);
$b2 = $api('POST', "/v7/izin/pengajuan/$id2/pembatalan", $t['sbx_v7_wali_a'], $batal);
$assert(($b1['data']['status'] ?? '') === 'Dibatalkan' && ($b2['data']['idempotent_replay'] ?? false), 'Wali pengaju membatalkan; diulang → replay');
$r = $api('POST', "/v7/izin/pengajuan/$id2/tinjauan", $t['sbx_v7_pembimbing_dua'], ['tindakan' => 'teruskan', 'version' => (int) $dW['pengajuan']['version'] + 1, 'idempotency_key' => $kunci('telat')]);
$assert($r['status'] === 409, 'Pembimbing meneruskan sesudah dibatalkan → 409');

// ===================================================================== A-E
echo PHP_EOL . '=== A-E. Pencabutan hak, notifikasi, asal Pengurus ===' . PHP_EOL;
$notif = static function (string $u, int $pid) use ($api, $t): array {
    $items = $api('GET', '/notifikasi?per_page=100', $t[$u])['data']['items'] ?? [];

    return array_values(array_filter($items, static fn ($i) => ($i['tautan']['tipe'] ?? '') === 'izin_wali' && ($i['tautan']['pengajuan_id'] ?? 0) === $pid));
};
$nWali = $notif('sbx_v7_wali_a', $id);
$nPemb = $notif('sbx_v7_pembimbing_satu', $id);
$nMur = $notif('sbx_murobi_a', $id);
$assert(count($nWali) >= 3 && count($nPemb) >= 1 && count($nMur) === 1 && $notif('sbx_v7_wali_b', $id) === [],
    'Notifikasi tautan `izin_wali`: wali pengaju (perbaikan/diteruskan/diputus), pembimbing, murobi; wali kedua tidak');
$semuaN = json_encode(array_merge($nWali, $nPemb, $nMur));
$assert(!str_contains($semuaN, $PENANDA) && !str_contains($semuaN, 'SBX V7 Santri') && array_filter(array_merge($nWali, $nPemb, $nMur), static fn ($n) => $n['santri_nama'] !== null || $n['pengajuan_id'] !== null) === [],
    'Notifikasi tanpa nama santri/alasan; hanya penunjuk tautan');
$r = $api('POST', '/v7/izin/wali/pengajuan', $t['sbx_v7_wali_a'], ['santri_id' => $sSatu, 'tgl_izin' => '2027-12-01', 'tgl_kembali' => '2027-12-01', 'alasan' => $PENANDA . ' takziah', 'idempotency_key' => $kunci('cabut')]);
$id3 = (int) ($r['data']['id'] ?? 0);
$paSatu = (int) $satu("SELECT id FROM pembimbing_assignments WHERE pengurus_id = (SELECT pengurus_id FROM users WHERE username = 'sbx_v7_pembimbing_satu')
    AND kamar_id = (SELECT id FROM kamar WHERE nama_kamar = 'SBX V7 Kamar Satu') AND is_active = 1 LIMIT 1");
$assert($api('GET', "/v7/izin/pengajuan/$id3", $t['sbx_v7_pembimbing_satu'])['status'] === 200, 'Sebelum pencabutan: pembimbing membuka rincian');
$jalankan("UPDATE pembimbing_assignments SET is_active = 0 WHERE id = $paSatu");
$r = $api('GET', "/v7/izin/pengajuan/$id3", $t['sbx_v7_pembimbing_satu']);
$rt = $api('POST', "/v7/izin/pengajuan/$id3/tinjauan", $t['sbx_v7_pembimbing_satu'], ['tindakan' => 'teruskan', 'version' => 1, 'idempotency_key' => $kunci('cabut-tinjau')]);
$assert($r['status'] === 404 && !str_contains($r['body'], $PENANDA) && in_array($rt['status'], [403, 404], true), 'Penugasan dicabut → token lama: rincian 404 tanpa isi, tinjauan ditolak');
$jalankan("UPDATE pembimbing_assignments SET is_active = 1 WHERE id = $paSatu");
$rel = (int) $satu("SELECT sw.id FROM santri_wali sw JOIN users u ON u.wali_id = sw.wali_id WHERE u.username = 'sbx_v7_wali_b' AND sw.santri_id = $sSatu AND sw.archived_at IS NULL");
$assert($api('GET', "/v7/izin/pengajuan/$id3", $t['sbx_v7_wali_b'])['status'] === 200, 'Sebelum pencabutan relasi: wali kedua melihat status ringkas');
$jalankan("UPDATE santri_wali SET archived_at = NOW() WHERE id = $rel");
$assert($api('GET', "/v7/izin/pengajuan/$id3", $t['sbx_v7_wali_b'])['status'] === 404 && !in_array($id3, array_column($api('GET', '/v7/izin/wali/pengajuan', $t['sbx_v7_wali_b'])['data']['items'] ?? [], 'id'), true),
    'Relasi wali kedua dicabut → token lama: rincian 404, daftar kosong');
$jalankan("UPDATE santri_wali SET archived_at = NULL WHERE id = $rel");
$mon = $api('GET', '/izin/admin/monitor?per_page=100', $t['sbx_admin'])['data']['items'] ?? [];
$asal = array_count_values(array_map(static fn ($x) => (string) ($x['asal'] ?? '(tidak ada)'), $mon));
$assert(isset($asal['Wali'], $asal['Pengurus']) && !isset($asal['(tidak ada)']), 'Monitor admin V2: setiap item memuat `asal` (Wali dan Pengurus)');
$assert($api('GET', '/v7/izin/akses', null)['status'] === 401 && $api('GET', "/v7/izin/pengajuan/$id", 'token-tidak-sah')['status'] === 401, 'Tanpa token/token tidak sah → 401 (aplikasi meminta masuk ulang)');

// ===================================================================== A-F
echo PHP_EOL . '=== A-F. Log server ===' . PHP_EOL;
$isiLog = (string) file_get_contents($log);
$assert(preg_match('/PHP (Warning|Notice|Fatal|Deprecated)|Uncaught/', $isiLog) !== 1, 'Log server tanpa galat PHP');
$assert(!str_contains($isiLog, $PENANDA), 'Log server tanpa alasan izin');

echo PHP_EOL . sprintf('Ringkasan: %d lulus, %d gagal', $lulus, count($gagal)) . PHP_EOL;
exit($gagal === [] ? 0 : 1);
