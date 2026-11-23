<?php

declare(strict_types=1);

/**
 * Uji server status "Izin" otomatis (migrasi 036) lewat server PHP lokal (sesi
 * web dan bearer token REST) di atas DB `tests/jadwal_kelas_gabungan_f1_db.sh`
 * dengan skenario `penugasan_keamanan_f3_seed.php` (akun Keamanan) dan
 * `jadwal_kelas_gabungan_f1_seed.php`:
 *
 *   JKG_DB=test_iop_http_test bash tests/jadwal_kelas_gabungan_f1_db.sh
 *   IOP_DB=test_iop_http_test php tests/izin_otomatis_pengajian_http.php
 *
 *   H-1 buka pertemuan lewat web (POST + CSRF): halaman pertemuan menampilkan
 *       keterangan IP-3 dari snapshot; tanpa formulir absensi
 *   H-2 REST GET /meetings/{id}: kunci `izin` aditif; tanpa isi izin
 *   H-3 simpan absensi lewat REST dengan status diubah guru (Hadir): rincian
 *       laporan web menampilkan Hadir + keterangan usulan
 *   H-4 REST Keamanan rincian kehadiran: `izin` + `izin_id` dari snapshot
 *   H-5 pertemuan tanpa usulan: label lama "Sedang izin #" tetap
 *   H-6 perbarui lewat web (pesan jumlah) dan REST (participant_changes)
 *   H-7 isi izin (alasan, catatan, keputusan) tidak muncul di halaman, respons,
 *       audit, maupun log server; log tanpa galat PHP
 *
 * Mengubah data uji; bangun ulang DB sebelum mengulang.
 */

$root = dirname(__DIR__);
$dbName = getenv('IOP_DB') ?: 'test_iop_http_test';
$port = (int) (getenv('IOP_PORT') ?: 8973);
if (!preg_match('/^test_.*_test$/', $dbName)) {
    fwrite(STDERR, "Ditolak: DB uji harus bernama test_*_test.\n");
    exit(2);
}
putenv('DB_NAME=' . $dbName);
require_once $root . '/app/bootstrap.php';

$lulus = 0;
$gagal = [];
$assert = static function (bool $ok, string $pesan) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    $ok ? $lulus++ : $gagal[] = $pesan;
};
$idsK = require __DIR__ . '/penugasan_keamanan_f3_seed.php';
$ids = require __DIR__ . '/jadwal_kelas_gabungan_f1_seed.php';
$conn = app_db();
$satu = static function (string $sql) use ($conn): mixed {
    $r = $conn->query($sql);
    if ($r === false) {
        throw new RuntimeException($conn->error . ' :: ' . $sql);
    }
    return $r->fetch_row()[0] ?? null;
};
$sfx = $ids['sfx'];
$RAHASIA = 'RAHASIA-ALASAN-' . $sfx;
$bulan = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
$dm = static fn (string $ymd): string => (int) substr($ymd, 8, 2) . ' ' . $bulan[(int) substr($ymd, 5, 2)];
$izin = static function (int $santri, string $dari, string $sampai, string $kapasitas = 'Murobi') use ($conn, $ids, $RAHASIA): int {
    $st = $conn->prepare("INSERT INTO izin_pengajuan (santri_id, murobi_guru_id, tahun_ajaran_id, tgl_izin, tgl_kembali, alasan, catatan_pengurus, status, asal_pengajuan, diajukan_pada)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'Disetujui', 'Pengurus', NOW())");
    $st->execute([$santri, $ids['guru_h'], $ids['tahun'], $dari, $sampai, $RAHASIA . ' alasan', $RAHASIA . ' catatan']);
    $id = (int) $conn->insert_id;
    $st = $conn->prepare("INSERT INTO izin_keputusan (pengajuan_id, hasil, alasan, diputus_oleh_user_id, kapasitas, alasan_penggantian, diputus_pada) VALUES (?, 'Disetujui', ?, ?, ?, ?, NOW())");
    $st->execute([$id, $RAHASIA . ' keputusan', $ids['admin_id'], $kapasitas, $kapasitas === 'Admin Pengganti' ? $RAHASIA . ' pengganti' : null]);
    return $id;
};

$log = tempnam(sys_get_temp_dir(), 'iop-server-');
$server = proc_open(['php', '-S', '127.0.0.1:' . $port, $root . '/tests/redesain_web_tahap1_router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root, array_merge(getenv(), ['DB_NAME' => $dbName]));
for ($i = 0; $i < 50; $i++) {
    $sock = @fsockopen('127.0.0.1', $port);
    if ($sock !== false) {
        fclose($sock);
        break;
    }
    usleep(100000);
}
register_shutdown_function(static function () use ($server, $port): void {
    if (is_resource($server)) {
        proc_terminate($server);
    }
    exec('pkill -f ' . escapeshellarg('php -S 127.0.0.1:' . $port . ' '));
});
$base = 'http://127.0.0.1:' . $port;

final class KlienIop
{
    private string $jar;

    public function __construct(private string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'iop-jar-');
    }

    /** @return array{status:int, body:string, location:?string} */
    public function req(string $path, ?array $post = null): array
    {
        $h = curl_init($this->base . $path);
        curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 60]);
        if ($post !== null) {
            curl_setopt($h, CURLOPT_POST, true);
            curl_setopt($h, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $out = (string) curl_exec($h);
        $status = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
        $size = (int) curl_getinfo($h, CURLINFO_HEADER_SIZE);
        curl_close($h);
        preg_match('/^Location:\s*(.+)$/mi', substr($out, 0, $size), $m);
        return ['status' => $status, 'body' => substr($out, $size), 'location' => isset($m[1]) ? trim($m[1]) : null];
    }

    public function csrf(string $path = '/portal/index.php'): string
    {
        $r = $this->req($path);
        preg_match('/name="_csrf" value="([^"]+)"/', $r['body'], $m);
        return $m[1] ?? '';
    }

    public function login(string $user): void
    {
        $this->req('/admin/cek_login.php', ['_csrf' => $this->csrf(), 'username' => $user, 'password' => 'Sandbox#123']);
    }
}

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
    return ['status' => $status, 'data' => $json['data'] ?? null, 'json' => $json];
};

$admin = new KlienIop($base);
$admin->login('sbx_admin');
$tGuru = (string) ($api('POST', '/auth/login', null, ['username' => 'sbx_guru_biasa', 'password' => 'Sandbox#123', 'device_name' => 'uji-iop'])['data']['token'] ?? '');
$tKeamanan = (string) ($api('POST', '/auth/login', null, ['username' => 'sbx_pengurus_biasa', 'password' => 'Sandbox#123', 'device_name' => 'uji-iop'])['data']['token'] ?? '');
$assert($tGuru !== '' && $tKeamanan !== '' && $admin->req('/admin/admin_pengajian.php')['status'] === 200, 'Admin masuk web; guru dan Keamanan memperoleh token API');

$c0 = $ids['santri']['C'][0];
$c1 = $ids['santri']['C'][1];
$S = (int) schedule_service()->save(['id_tahun' => $ids['tahun'], 'id_kelas' => $ids['kelas']['C'], 'id_guru' => $ids['guru_g'], 'hari' => 'Kamis',
    'waktu_mulai' => '19:00', 'waktu_selesai' => '19:45', 'waktu_sholat' => "Ba'da Isya", 'fan_ilmu' => 'Izin HTTP ' . $sfx, 'nama_kitab' => 'Uji', 'tempat' => 'Uji'], $ids['admin_id'])['id'];
$D1 = (new DateTimeImmutable('next thursday'))->modify('+35 days')->format('Y-m-d');
$sampai = (new DateTimeImmutable($D1))->modify('+2 days')->format('Y-m-d');
$I1 = $izin($c0, $D1, $sampai);
$ket = "Izin No. $I1 — disetujui murobi s.d. " . $dm($sampai);

echo "\n=== H-1 buka pertemuan lewat web ===\n";
$r = $admin->req('/admin/admin_pengajian.php?tab=pertemuan', ['tab' => 'pertemuan', 'action' => 'open', 'schedule_id' => $S, 'tanggal_pertemuan' => $D1, 'catatan' => '',
    '_csrf' => $admin->csrf('/admin/admin_pengajian.php?tab=pertemuan')]);
$M1 = (int) $satu("SELECT id FROM pertemuan_pengajian WHERE jadwal_id = $S AND tanggal_pertemuan = '$D1'");
$assert($r['status'] === 302 && $M1 > 0 && (string) $satu("SELECT izin_keterangan FROM pertemuan_peserta WHERE pertemuan_id = $M1 AND santri_id = $c0") === $ket,
    'Buka lewat web membekukan usulan dengan keterangan IP-3');
$halaman = $admin->req('/admin/admin_pengajian.php?tab=pertemuan&id=' . $M1);
$assert($halaman['status'] === 200 && str_contains($halaman['body'], htmlspecialchars($ket)) && substr_count($halaman['body'], 'data-izin-usulan') === 1,
    'Halaman pertemuan web: keterangan usulan dari snapshot, satu baris');
$assert(str_contains($halaman['body'], '1 sedang izin') && !str_contains($halaman['body'], 'Sedang izin #' . $I1), 'Pertemuan berusulan tidak memakai label lama; hitungan "1 sedang izin"');
$assert(!preg_match('/name="students|name="status\[/', $halaman['body']), 'Tanpa formulir absensi web (keputusan R)');

echo "\n=== H-2 REST rincian pertemuan ===\n";
$m = $api('GET', '/meetings/' . $M1, $tGuru);
$siswa = array_column($m['data']['students'] ?? [], null, 'student_id');
$assert($m['status'] === 200 && ($siswa[$c0]['izin'] ?? null) === ['pengajuan_id' => $I1, 'keterangan' => $ket, 'sampai' => $sampai] && $siswa[$c1]['izin'] === null,
    'GET /meetings/{id}: `izin` aditif sesuai §3.4');

echo "\n=== H-3 simpan lewat REST (guru ubah ke Hadir) dan rincian laporan web ===\n";
$simpan = $api('PUT', '/meetings/' . $M1 . '/attendance', $tGuru, ['idempotency_key' => 'iop-http-' . $sfx, 'teacher' => ['status' => 'Hadir'],
    'students' => [['student_id' => $c0, 'status' => 'Hadir'], ['student_id' => $c1, 'status' => 'Hadir']]]);
$assert(in_array($simpan['status'], [200, 201], true) && (string) $satu("SELECT status FROM absensi_santri WHERE pertemuan_id = $M1 AND santri_id = $c0") === 'Hadir',
    'Simpan REST: status Hadir pilihan guru tersimpan (server tidak memaksa Izin)');
$ulang = $api('PUT', '/meetings/' . $M1 . '/attendance', $tGuru, ['idempotency_key' => 'iop-http-' . $sfx, 'teacher' => ['status' => 'Hadir'],
    'students' => [['student_id' => $c0, 'status' => 'Hadir'], ['student_id' => $c1, 'status' => 'Hadir']]]);
$assert($ulang['status'] === $simpan['status'] && (int) $satu("SELECT COUNT(*) FROM absensi_santri WHERE pertemuan_id = $M1") === 2, 'Kunci idempotensi sama → satu penyimpanan');
$detail = $admin->req('/admin/laporan_absensi_detail.php?id=' . $M1);
$assert($detail['status'] === 200 && str_contains($detail['body'], htmlspecialchars($ket)) && str_contains($detail['body'], 'data-izin-usulan'),
    'Rincian laporan web: keterangan usulan tampil');

echo "\n=== H-4 REST Keamanan rincian kehadiran ===\n";
$k = $api('GET', '/keamanan/kehadiran/pertemuan/' . $M1, $tKeamanan);
$ks = array_column($k['data']['students'] ?? [], null, 'student_id');
$assert($k['status'] === 200 && ($ks[$c0]['izin']['keterangan'] ?? null) === $ket && ($ks[$c0]['izin_id'] ?? null) === $I1 && ($ks[$c0]['status'] ?? null) === 'Hadir',
    'Keamanan: keterangan usulan + izin_id dari snapshot, status tersimpan Hadir');

echo "\n=== H-5 pertemuan tanpa usulan: label lama tetap ===\n";
$D2 = (new DateTimeImmutable($D1))->modify('+7 days')->format('Y-m-d');
$I2 = $izin($c1, $D2, $D2, 'Admin Pengganti');
$M2 = schedule_service()->open($S, $D2, '', ['id' => $ids['admin_id'], 'roles' => ['admin'], 'guru_id' => null]);
$conn->query("UPDATE pertemuan_peserta SET izin_pengajuan_id = NULL, izin_keterangan = NULL WHERE pertemuan_id = $M2");
$h2 = $admin->req('/admin/admin_pengajian.php?tab=pertemuan&id=' . $M2);
$assert(str_contains($h2['body'], 'Sedang izin #' . $I2) && !str_contains($h2['body'], 'data-izin-usulan'), 'Pertemuan tanpa usulan (seperti pertemuan lama): label "Sedang izin #" yang dihitung');
$d2 = $admin->req('/admin/laporan_absensi_detail.php?id=' . $M2);
$assert(str_contains($d2['body'], 'Sedang izin #' . $I2), 'Rincian laporan pertemuan tanpa usulan: label lama');

echo "\n=== H-6 perbarui lewat web dan REST ===\n";
$r = $admin->req('/admin/admin_pengajian.php?tab=pertemuan', ['tab' => 'pertemuan', 'action' => 'refresh_participants', 'meeting_id' => $M2,
    '_csrf' => $admin->csrf('/admin/admin_pengajian.php?tab=pertemuan&id=' . $M2)]);
$h2b = $admin->req('/admin/admin_pengajian.php?tab=pertemuan&id=' . $M2);
$assert($r['status'] === 302 && str_contains($h2b['body'], '1 usulan izin diisi') && str_contains($h2b['body'], htmlspecialchars("Izin No. $I2 — disetujui admin pengganti s.d. " . $dm($D2))),
    'Perbarui web: pesan "1 usulan izin diisi" dan keterangan admin pengganti tampil');
$conn->query("UPDATE izin_pengajuan SET status = 'Dibatalkan' WHERE id = $I2");
$p = $api('POST', '/meetings/' . $M2 . '/participants/refresh', $tGuru, []);
$assert($p['status'] === 200 && ($p['data']['participant_changes']['izin_cleared'] ?? null) === 1 && array_column($p['data']['students'], 'izin', 'student_id')[$c1] === null,
    'Perbarui REST: izin dibatalkan → izin_cleared 1 dan `izin` null');

echo "\n=== H-7 tanpa isi izin; log server ===\n";
$semua = $halaman['body'] . $detail['body'] . $h2['body'] . $h2b['body'] . json_encode([$m, $simpan, $k, $p], JSON_UNESCAPED_UNICODE);
$assert(!str_contains($semua, $RAHASIA), 'Halaman dan respons tidak memuat alasan/catatan/keputusan izin');
$assert((int) $satu("SELECT COUNT(*) FROM audit_logs WHERE CONCAT_WS('|', before_json, after_json) LIKE '%RAHASIA-ALASAN-%'") === 0, 'Audit tanpa isi izin');
$isiLog = (string) file_get_contents($log);
$assert(!str_contains($isiLog, $RAHASIA), 'Log server tanpa isi izin');
$assert(!preg_match('/PHP (Fatal|Warning|Notice|Deprecated|Parse)/', $isiLog), 'Log server tanpa galat/peringatan PHP');
if (preg_match_all('/PHP (Fatal|Warning|Notice|Deprecated|Parse)[^\n]*/', $isiLog, $mm)) {
    echo implode("\n", array_slice($mm[0], 0, 5)) . PHP_EOL;
}

echo "\nHasil: " . $lulus . ' lulus, ' . count($gagal) . " gagal\n";
foreach ($gagal as $pesan) {
    echo '  - ' . $pesan . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
