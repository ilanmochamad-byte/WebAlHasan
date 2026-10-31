<?php

declare(strict_types=1);

/**
 * Uji server Fase 1 kelas gabungan lewat server PHP lokal (sesi web dan bearer
 * token REST) di atas DB `tests/jadwal_kelas_gabungan_f1_db.sh`:
 *
 *   JKG_DB=test_jkg_f1_http_test bash tests/jadwal_kelas_gabungan_f1_db.sh
 *   JKG_DB=test_jkg_f1_http_test php tests/jadwal_kelas_gabungan_f1_http.php
 *
 *   H-1 formulir admin: kelas utama + kelas gabungan; simpan lewat POST + CSRF
 *   H-2 tampilan jadwal (rincian, mingguan, daftar, saringan kelas) memuat seluruh kelas
 *   H-3 isian gagal mempertahankan pilihan kelas gabungan; guru tidak dapat POST jadwal
 *   H-4 buka pertemuan lewat web; peserta dua kelas dengan kelas asal; pembukaan ulang ditolak
 *   H-5 REST: `classes` aditif + `class` lama; buka lewat API (kelas berikutnya) 201, ulang 409
 *   H-6 absensi lewat API; laporan web per kelas/gabungan, rincian, CSV
 *   H-7 halaman publik jadwal ngaji: kedua kelas tampil, saringan kelas PI menemukan jadwal
 *   H-8 log server tanpa galat PHP
 *
 * Mengubah data uji; bangun ulang DB sebelum mengulang.
 */

$root = dirname(__DIR__);
$dbName = getenv('JKG_DB') ?: 'test_jkg_f1_http_test';
$port = (int) (getenv('JKG_PORT') ?: 8971);
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
$ids = require __DIR__ . '/jadwal_kelas_gabungan_f1_seed.php';
$conn = app_db();
$satu = static function (string $sql) use ($conn): mixed {
    $r = $conn->query($sql);
    if ($r === false) {
        throw new RuntimeException($conn->error . ' :: ' . $sql);
    }
    return $r->fetch_row()[0] ?? null;
};
$k = $ids['kelas'];
$nama = [];
foreach ($k as $kunci => $id) {
    $nama[$kunci] = (string) $satu("SELECT nama_kelas FROM kelas WHERE id = $id");
}

$log = tempnam(sys_get_temp_dir(), 'jkg-server-');
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

final class KlienJkg
{
    private string $jar;

    public function __construct(private string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'jkg-jar-');
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

$admin = new KlienJkg($base);
$admin->login('sbx_admin');
$guruWeb = new KlienJkg($base);
$guruWeb->login('sbx_guru_biasa');
$tGuru = (string) ($api('POST', '/auth/login', null, ['username' => 'sbx_guru_biasa', 'password' => 'Sandbox#123', 'device_name' => 'uji-jkg'])['data']['token'] ?? '');
$assert($tGuru !== '' && $admin->req('/admin/admin_pengajian.php')['status'] === 200, 'Admin masuk web dan guru memperoleh token API');

echo "\n=== H-1 formulir admin ===\n";
$form = $admin->req('/admin/admin_pengajian.php?tab=jadwal&action=create');
$assert($form['status'] === 200 && str_contains($form['body'], 'Kelas utama') && str_contains($form['body'], 'name="kelas_gabungan[]"')
    && str_contains($form['body'], 'value="' . $k['PI'] . '"') && !str_contains($form['body'], 'value="' . $ids['kelas_arsip'] . '"'),
    'Formulir memuat kelas utama + kotak centang kelas gabungan (kelas arsip tidak ditawarkan)');
$isian = ['tab' => 'jadwal', 'action' => 'save', 'id_tahun' => $ids['tahun'], 'hari' => 'Kamis', 'waktu_sholat' => "Ba'da Isya",
    'waktu_mulai' => '20:00', 'waktu_selesai' => '20:45', 'id_kelas' => $k['PA'], 'kelas_gabungan' => [$k['PI']], 'id_guru' => $ids['guru_g'],
    'tempat' => 'Aula Gabungan ' . $ids['sfx'], 'fan_ilmu' => 'Nahwu ' . $ids['sfx'], 'nama_kitab' => 'Jurumiyah'];
$r = $admin->req('/admin/admin_pengajian.php?tab=jadwal', $isian + ['_csrf' => $admin->csrf('/admin/admin_pengajian.php?tab=jadwal&action=create')]);
$S = (int) $satu("SELECT id FROM jadwal_ngaji WHERE fan_ilmu = 'Nahwu {$ids['sfx']}'");
$assert($r['status'] === 302 && $S > 0 && (int) $satu("SELECT COUNT(*) FROM jadwal_kelas WHERE jadwal_id = $S") === 2, 'POST formulir menyimpan jadwal gabungan (dua baris jadwal_kelas)');
$r = $admin->req('/admin/admin_pengajian.php?tab=jadwal', $isian + ['_csrf' => '']);
$assert(in_array($r['status'], [400, 403, 419], true) && (int) $satu("SELECT COUNT(*) FROM jadwal_ngaji WHERE fan_ilmu = 'Nahwu {$ids['sfx']}'") === 1, 'POST tanpa CSRF ditolak tanpa jadwal baru');

echo "\n=== H-2 tampilan jadwal ===\n";
$label = $nama['PA'] . ', ' . $nama['PI'];
$detail = $admin->req('/admin/admin_pengajian.php?tab=jadwal&action=detail&id=' . $S);
$assert(str_contains($detail['body'], htmlspecialchars($label)) && str_contains($detail['body'], 'Kelas gabungan'), 'Rincian jadwal menampilkan kedua kelas dan penanda kelas gabungan');
$ubah = $admin->req('/admin/admin_pengajian.php?tab=jadwal&action=edit&id=' . $S);
$assert((bool) preg_match('/id="kelas_gabungan_' . $k['PI'] . '"[^>]*checked/', $ubah['body']) && !preg_match('/id="kelas_gabungan_' . $k['C'] . '"[^>]*checked/', $ubah['body']),
    'Formulir ubah mencentang kelas gabungan yang tersimpan saja');
$mingguan = $admin->req('/admin/admin_pengajian.php?tab=jadwal&year_id=' . $ids['tahun']);
$assert(str_contains($mingguan['body'], htmlspecialchars($label)), 'Tampilan mingguan menampilkan seluruh kelas jadwal');
$saringPI = $admin->req('/admin/admin_pengajian.php?tab=jadwal&tampilan=daftar&class_id=' . $k['PI'] . '&year_id=' . $ids['tahun']);
$saringC = $admin->req('/admin/admin_pengajian.php?tab=jadwal&tampilan=daftar&class_id=' . $k['C'] . '&year_id=' . $ids['tahun']);
$assert(str_contains($saringPI['body'], 'Nahwu ' . $ids['sfx']) && !str_contains($saringC['body'], 'Nahwu ' . $ids['sfx']), 'Saringan daftar kelas PI menemukan jadwal gabungan; kelas C tidak');
$guruLihat = $guruWeb->req('/admin/admin_pengajian.php?tab=jadwal&action=detail&id=' . $S);
$assert($guruLihat['status'] === 200 && str_contains($guruLihat['body'], htmlspecialchars($label)), 'Guru pengampu melihat jadwal gabungannya');

echo "\n=== H-3 isian gagal dan hak ===\n";
$gagalIsian = array_replace($isian, ['fan_ilmu' => 'Bentrok ' . $ids['sfx'], 'waktu_mulai' => '20:10', 'waktu_selesai' => '20:30', 'kelas_gabungan' => [$k['C']]]);
$r = $admin->req('/admin/admin_pengajian.php?tab=jadwal', $gagalIsian + ['_csrf' => $admin->csrf('/admin/admin_pengajian.php?tab=jadwal&action=create')]);
$balik = $admin->req((string) parse_url((string) $r['location'], PHP_URL_PATH) . '?' . (string) parse_url((string) $r['location'], PHP_URL_QUERY));
$assert(str_contains($balik['body'], 'Bentrok guru') && (bool) preg_match('/id="kelas_gabungan_' . $k['C'] . '"[^>]*checked/', $balik['body']),
    'Simpan gagal (bentrok guru) mempertahankan pilihan kelas gabungan di formulir');
$r = $guruWeb->req('/admin/admin_pengajian.php?tab=jadwal', array_replace($isian, ['fan_ilmu' => 'Guru ' . $ids['sfx']]) + ['_csrf' => $guruWeb->csrf('/admin/admin_pengajian.php')]);
$assert((int) $satu("SELECT COUNT(*) FROM jadwal_ngaji WHERE fan_ilmu = 'Guru {$ids['sfx']}'") === 0, 'Guru tidak dapat menyimpan jadwal lewat POST');

echo "\n=== H-4 buka pertemuan lewat web ===\n";
$D1 = (new DateTimeImmutable('next thursday'))->format('Y-m-d');
$D2 = (new DateTimeImmutable('next thursday'))->modify('+7 days')->format('Y-m-d');
$bukaForm = $admin->req('/admin/admin_pengajian.php?tab=pertemuan&action=create&schedule_id=' . $S);
$assert(str_contains($bukaForm['body'], htmlspecialchars($label)), 'Pilihan jadwal di formulir buka pertemuan menampilkan seluruh kelas');
$r = $admin->req('/admin/admin_pengajian.php?tab=pertemuan', ['tab' => 'pertemuan', 'action' => 'open', 'schedule_id' => $S, 'tanggal_pertemuan' => $D1, 'catatan' => '',
    '_csrf' => $admin->csrf('/admin/admin_pengajian.php?tab=pertemuan')]);
$M1 = (int) $satu("SELECT id FROM pertemuan_pengajian WHERE jadwal_id = $S AND tanggal_pertemuan = '$D1'");
$assert($r['status'] === 302 && $M1 > 0 && (int) $satu("SELECT COUNT(*) FROM pertemuan_peserta WHERE pertemuan_id = $M1") === 5, 'Pertemuan dibuka lewat web: lima peserta dari dua kelas');
$halaman = $admin->req('/admin/admin_pengajian.php?tab=pertemuan&id=' . $M1);
$assert(str_contains($halaman['body'], htmlspecialchars($label)) && str_contains($halaman['body'], htmlspecialchars($nama['PI'])) && substr_count($halaman['body'], 'data-ah-filter-text=') === 5,
    'Rincian pertemuan web: kedua kelas, kelas asal peserta, lima baris');
$r = $admin->req('/admin/admin_pengajian.php?tab=pertemuan', ['tab' => 'pertemuan', 'action' => 'open', 'schedule_id' => $S, 'tanggal_pertemuan' => $D1, 'catatan' => '',
    '_csrf' => $admin->csrf('/admin/admin_pengajian.php?tab=pertemuan')]);
$assert((int) $satu("SELECT COUNT(*) FROM pertemuan_pengajian WHERE jadwal_id = $S AND tanggal_pertemuan = '$D1'") === 1, 'Pembukaan ulang lewat web tidak membuat pertemuan kedua');
$daftar = $admin->req('/admin/admin_pengajian.php?tab=pertemuan&q=' . rawurlencode($nama['PI']));
$assert(str_contains($daftar['body'], '?tab=pertemuan&amp;id=' . $M1) || str_contains($daftar['body'], 'id=' . $M1), 'Pencarian daftar pertemuan dengan nama kelas gabungan menemukan pertemuan');

echo "\n=== H-5 REST ===\n";
$j = $api('GET', '/schedules/' . $S . '?date=' . $D1, $tGuru);
$assert($j['status'] === 200 && ($j['data']['class']['id'] ?? 0) === $k['PA'] && array_column($j['data']['classes'] ?? [], 'id') === [$k['PA'], $k['PI']] && ($j['data']['meeting']['id'] ?? 0) === $M1,
    'GET /schedules/{id}: class lama = kelas utama; classes = PA, PI; pertemuan yang sama');
$m = $api('GET', '/meetings/' . $M1, $tGuru);
$kelasSiswa = array_count_values(array_map(static fn (array $s): int => $s['class']['id'], $m['data']['students'] ?? []));
ksort($kelasSiswa);
$assert($m['status'] === 200 && $kelasSiswa === [$k['PA'] => 3, $k['PI'] => 2] && count($m['data']['task']['classes']) === 2, 'GET /meetings/{id}: lima santri dengan kelas asal, task.classes');
$r = $api('POST', '/schedules/' . $S . '/meetings', $tGuru, ['date' => $D1, 'idempotency_key' => 'jkg-http-dup-' . $ids['sfx']]);
$assert($r['status'] === 409, 'POST pertemuan kedua untuk tanggal sama → 409');
// Ganti kelas gabungan (PI → C) di web, lalu buka pertemuan berikutnya lewat API.
$admin->req('/admin/admin_pengajian.php?tab=jadwal', array_replace($isian, ['kelas_gabungan' => [$k['C']]]) + ['id' => $S, '_csrf' => $admin->csrf('/admin/admin_pengajian.php?tab=jadwal&action=edit&id=' . $S)]);
$r = $api('POST', '/schedules/' . $S . '/meetings', $tGuru, ['date' => $D2, 'idempotency_key' => 'jkg-http-open-' . $ids['sfx']]);
$M2 = (int) ($r['data']['id'] ?? 0);
$kelasM2 = array_count_values(array_map(static fn (array $s): int => $s['class']['id'], $r['data']['students'] ?? []));
ksort($kelasM2);
$assert($r['status'] === 201 && $kelasM2 === [$k['PA'] => 3, $k['C'] => 2], 'POST /schedules/{id}/meetings sesudah kelas diubah: peserta PA + C (pertemuan berikutnya)');
$m1Lagi = $api('GET', '/meetings/' . $M1, $tGuru);
$assert(array_column($m1Lagi['data']['task']['classes'] ?? [], 'id') === [$k['PA'], $k['PI']] && count($m1Lagi['data']['students']) === 5, 'Pertemuan pertama tetap PA + PI sesudah perubahan kelas');
$daftarApi = $api('GET', '/meetings?date_from=' . $D1 . '&date_to=' . $D2, $tGuru);
$item = array_values(array_filter($daftarApi['data']['items'] ?? [], static fn (array $x): bool => $x['id'] === $M1))[0] ?? [];
$assert(($item['class_name'] ?? '') === $nama['PA'] && ($item['class_names'] ?? '') === $label && count($item['classes'] ?? []) === 2, 'GET /meetings: class_name lama, class_names + classes');

echo "\n=== H-6 absensi dan laporan ===\n";
foreach ([$M1 => 'Hadir', $M2 => 'Sakit'] as $mid => $status) {
    $rinci = $api('GET', '/meetings/' . $mid, $tGuru)['data'];
    $r = $api('PUT', '/meetings/' . $mid . '/attendance', $tGuru, ['idempotency_key' => 'jkg-http-abs-' . $mid . $ids['sfx'], 'teacher' => ['status' => 'Hadir'],
        'students' => array_map(static fn (array $s): array => ['student_id' => $s['student_id'], 'status' => $status], $rinci['students'])]);
    $assert($r['status'] === 200 && (int) $satu("SELECT COUNT(*) FROM absensi_guru WHERE pertemuan_id = $mid") === 1 && (int) $satu("SELECT COUNT(*) FROM absensi_santri WHERE pertemuan_id = $mid") === 5,
        "Absensi pertemuan #$mid lewat API: satu guru + lima santri");
}
$saring = 'date_from=' . $D1 . '&date_to=' . $D2 . '&schedule_id=' . $S;
$lapPI = $admin->req('/admin/admin_laporan_absensi.php?' . $saring . '&class_id=' . $k['PI'] . '&subject_scope=santri');
$lapC = $admin->req('/admin/admin_laporan_absensi.php?' . $saring . '&class_id=' . $k['C'] . '&subject_scope=santri');
$assert($lapPI['status'] === 200 && substr_count($lapPI['body'], 'Santri PI') >= 2 && !str_contains($lapPI['body'], 'Santri PA1 ' . $ids['sfx']),
    'Laporan web kelas PI: santri PI saja');
$assert($lapC['status'] === 200 && str_contains($lapC['body'], 'Santri C1 ' . $ids['sfx']) && !str_contains($lapC['body'], 'Santri PI1 ' . $ids['sfx']), 'Laporan web kelas C: santri C saja');
$rinciWeb = $admin->req('/admin/laporan_absensi_detail.php?id=' . $M1);
$assert($rinciWeb['status'] === 200 && str_contains($rinciWeb['body'], '<th scope="col">Kelas</th>') && str_contains($rinciWeb['body'], htmlspecialchars($label)), 'Rincian kehadiran web: kolom kelas asal dan seluruh kelas pertemuan');
$csv = $admin->req('/admin/export_laporan_absensi.php?' . $saring . '&subject_scope=gabungan');
$baris = array_values(array_filter(explode("\n", trim($csv['body'])), static fn (string $b): bool => trim($b) !== ''));
$baris = array_values(array_filter($baris, static fn (string $b): bool => str_contains($b, $ids['sfx']) || str_contains($b, 'Guru') && str_contains($b, 'Nahwu')));
$guruCsv = array_values(array_filter($baris, static fn (string $b): bool => str_contains($b, ',Guru,') || str_contains($b, ';Guru;') || str_contains($b, '"Guru"')));
$assert($csv['status'] === 200 && count($guruCsv) === 2, 'CSV gabungan: dua baris absensi guru (satu per pertemuan, tidak ganda) — ' . count($guruCsv));
$santriPI = array_values(array_filter($baris, static fn (string $b): bool => str_contains($b, 'Santri PI1 ' . $ids['sfx'])));
$assert(count($santriPI) === 1 && str_contains($santriPI[0], $nama['PI']), 'CSV: santri PI tepat sekali dengan kelas asal PI');

echo "\n=== H-7 halaman publik ===\n";
$publik = $admin->req('/jadwal_ngaji.php?waktu=' . rawurlencode("Ba'da Isya"));
$assert($publik['status'] === 200 && str_contains($publik['body'], htmlspecialchars($nama['PA'] . ', ' . $nama['C'])), 'Jadwal publik menampilkan seluruh kelas jadwal gabungan');
$publikPI = (new KlienJkg($base))->req('/jadwal_ngaji.php?waktu=' . rawurlencode("Ba'da Isya") . '&kelas=' . $k['C']);
$publikPI2 = (new KlienJkg($base))->req('/jadwal_ngaji.php?waktu=' . rawurlencode("Ba'da Isya") . '&kelas=' . $k['PI']);
$assert(str_contains($publikPI['body'], 'Nahwu ' . $ids['sfx']) && !str_contains($publikPI2['body'], 'Nahwu ' . $ids['sfx']) && str_contains($publikPI['body'], 'value="' . $k['C'] . '"'),
    'Saringan kelas publik: kelas gabungan sekarang (C) menemukan jadwal; kelas lama (PI) tidak');

echo "\n=== H-8 log server ===\n";
$isiLog = (string) file_get_contents($log);
$assert(!preg_match('/PHP (Fatal|Warning|Notice|Deprecated|Parse)/', $isiLog), 'Log server tanpa galat/peringatan PHP');
if (preg_match_all('/PHP (Fatal|Warning|Notice|Deprecated|Parse)[^\n]*/', $isiLog, $mm)) {
    echo implode("\n", array_slice($mm[0], 0, 5)) . PHP_EOL;
}

echo "\nHasil: " . $lulus . ' lulus, ' . count($gagal) . " gagal\n";
foreach ($gagal as $pesan) {
    echo '  - ' . $pesan . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
