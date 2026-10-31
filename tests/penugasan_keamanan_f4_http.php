<?php

declare(strict_types=1);

/**
 * Uji server Fase 4 penugasan lintas guru/pengurus — REST baca-saja Keamanan
 * dan identitas akun untuk aplikasi (bearer token, bukan sesi web), lewat
 * server PHP lokal di atas DB `tests/penugasan_keamanan_f4_db.sh` dengan
 * skenario `tests/penugasan_keamanan_f3_seed.php`.
 *
 *   PKF3_DB=test_pk_f4_http_test bash tests/penugasan_keamanan_f4_db.sh
 *   PKF3_DB=test_pk_f4_http_test php tests/penugasan_keamanan_f4_http.php
 *
 *   R-1  akses: K/G pantau; N, admin, orang tua tidak; 401 tanpa token; no-store
 *   R-2  seluruh metode mutasi pada sembilan rute → 405 tanpa perubahan data
 *   R-3  izin: daftar = layanan web Keamanan (oracle SQL santri aktif); tanpa
 *        alasan di daftar; `peran`/kunci asing diabaikan; saringan tidak valid 422
 *   R-4  rincian izin lengkap + audit tanpa isi; nonaktif/tidak ada 404; N 403;
 *        audit gagal → 503 tanpa isi
 *   R-5  pelanggaran: daftar/angka = layanan web; nonaktif tidak terbaca; rincian
 *        lengkap tanpa kunci internal (rekomendasi, path lampiran); kasus Rahasia
 *        tidak tertaut; 422
 *   R-6  konseling: Rahasia tidak di daftar/angka/pencarian; rincian Rahasia =
 *        tidak ada (404, pesan sama); Internal lengkap
 *   R-7  kehadiran: seluruh jadwal, peserta santri aktif; rincian pertemuan
 *        diaudit; audit gagal 503; `/reports` lama tidak melebar
 *   R-8  pencabutan penugasan / guru nonaktif → token lama ditolak; pulih
 *   R-9  `GET /profile/identitas` (dua identitas); bentuk /profile dan login tidak berubah
 *   R-10 audit Keamanan tanpa isi; log server tanpa galat PHP
 *
 * Mengubah data uji; bangun ulang DB sebelum mengulang.
 */

use App\Keamanan\KeamananAkses;
use App\Report\ReportFilter;

$root = dirname(__DIR__);
$db = getenv('PKF3_DB') ?: 'test_pk_f4_http_test';
$port = (int) (getenv('PKF4_PORT') ?: 8958);
if (!preg_match('/^test_.*_test$/', $db)) {
    fwrite(STDERR, "Ditolak: DB uji harus bernama test_*_test.\n");
    exit(2);
}
putenv('PKF3_DB=' . $db);
putenv('DB_NAME=' . $db);
require_once $root . '/app/bootstrap.php';

$lulus = 0;
$gagal = [];
$assert = static function (bool $ok, string $pesan) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    $ok ? $lulus++ : $gagal[] = $pesan;
};
$ids = require __DIR__ . '/penugasan_keamanan_f3_seed.php';
$conn = app_db();
register_shutdown_function(static function () use ($ids, $conn): void {
    @$conn->query('DROP TRIGGER IF EXISTS pkf4_audit_reject');
    @unlink($ids['lampiran_berkas']);
});
$log = tempnam(sys_get_temp_dir(), 'pkf4-server-');
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
register_shutdown_function(static function () use ($server, $port): void {
    if (is_resource($server)) {
        proc_terminate($server);
    }
    exec('pkill -f ' . escapeshellarg('php -S 127.0.0.1:' . $port . ' '));
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
$login = static fn (string $user): array => $api('POST', '/auth/login', null, ['username' => $user, 'password' => 'Sandbox#123', 'device_name' => 'uji-pk-f4']);
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
$akunWeb = static function (int $userId) use ($satu): array {
    capabilities()->forget($userId);
    $roles = (string) $satu("SELECT GROUP_CONCAT(r.slug ORDER BY r.slug) FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = $userId");
    $guru = $satu("SELECT guru_id FROM users WHERE id = $userId");

    return ['id' => $userId, 'roles' => $roles === '' ? [] : explode(',', $roles), 'guru_id' => $guru === null ? null : (int) $guru];
};

$login_K = $login('sbx_pengurus_biasa');
$t = [
    'K' => (string) ($login_K['data']['token'] ?? ''),
    'G' => (string) ($login('sbx_guru_biasa')['data']['token'] ?? ''),
    'N' => (string) ($login('sbx_pengurus_b')['data']['token'] ?? ''),
    'A' => (string) ($login('sbx_admin')['data']['token'] ?? ''),
    'W' => (string) ($login('sbx_v7_wali_a')['data']['token'] ?? ''),
];
$assert(!in_array('', $t, true), 'Lima akun uji memperoleh token API');
$rute = ['/keamanan/akses', '/keamanan/izin', '/keamanan/izin/' . $ids['izin_wali'], '/keamanan/pelanggaran',
    '/keamanan/pelanggaran/' . $ids['pelanggaran_lampiran'], '/keamanan/konseling', '/keamanan/konseling/' . $ids['kasus_internal'],
    '/keamanan/kehadiran', '/keamanan/kehadiran/pertemuan/1'];

echo "\n=== R-1 akses ===\n";
$akses = static fn (string $siapa): mixed => $api('GET', '/keamanan/akses', $t[$siapa])['data']['pantau'] ?? null;
$assert($akses('K') === true && $akses('G') === true, 'K (pengurus) dan G (guru lewat identitas pengurus) pantau = true');
$assert($akses('N') === false && $akses('W') === false, 'Pengurus tanpa Keamanan dan orang tua: pantau = false (200, bukan galat)');
$assert($akses('A') === false, 'Admin tanpa penugasan Keamanan sendiri: pantau = false (PF3-1 berlaku di REST)');
$r = $api('GET', '/keamanan/izin', null);
$assert($r['status'] === 401, 'Tanpa token → 401');
$r = $api('GET', '/keamanan/izin', $t['K']);
$assert(stripos($r['header'], 'Cache-Control: private, no-store') !== false, 'Respons Keamanan Cache-Control private, no-store');
foreach (['N', 'W', 'A'] as $siapa) {
    $kode = array_map(static fn (string $p): int => $api('GET', $p, $t[$siapa])['status'], array_slice($rute, 1));
    $assert(array_unique($kode) === [403], "$siapa ditolak 403 pada delapan rute data Keamanan");
}
$assert($api('GET', '/keamanan/tidak-ada', $t['K'])['status'] === 404, 'Rute Keamanan tak dikenal → 404');

echo "\n=== R-2 metode mutasi ===\n";
$sidik = static fn (): string => (string) $satu("SELECT CONCAT((SELECT COUNT(*) FROM izin_pengajuan), '/', (SELECT COALESCE(SUM(version),0) FROM izin_pengajuan), '/',
    (SELECT COUNT(*) FROM v3_pelanggaran), '/', (SELECT COUNT(*) FROM v3_konseling_kasus), '/', (SELECT COUNT(*) FROM v3_murobi_catatan), '/',
    (SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'keamanan.%'), '/', (SELECT COUNT(*) FROM izin_keputusan))");
$sebelum = $sidik();
$buruk = [];
foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $metode) {
    foreach ($rute as $p) {
        $r = $api($metode, $p, $t['K'], ['status' => 'Disetujui', 'alasan' => 'coba mutasi', 'idempotency_key' => 'pkf4-' . bin2hex(random_bytes(4))]);
        if ($r['status'] !== 405 || stripos($r['header'], 'Allow: GET') === false) {
            $buruk[] = "$metode $p → {$r['status']}";
        }
    }
}
$assert($buruk === [], '36 permintaan mutasi (4 metode × 9 rute) → 405 Allow: GET' . ($buruk === [] ? '' : ': ' . implode(', ', $buruk)));
$assert($sidik() === $sebelum, 'Tidak ada perubahan izin/pembinaan/keputusan/audit Keamanan akibat permintaan mutasi');

echo "\n=== R-3 daftar izin ===\n";
$kUser = $akunWeb((int) $ids['k']);
$web = izin_laporan_service()->laporan(KeamananAkses::tandai($kUser), []);
$r = $api('GET', '/keamanan/izin', $t['K']);
$oracle = (int) $satu("SELECT COUNT(*) FROM izin_pengajuan p JOIN santri s ON s.id = p.santri_id AND s.is_active = 1 AND s.archived_at IS NULL");
$assert($r['status'] === 200 && (int) $r['data']['total'] === (int) $web['total'] && (int) $r['data']['total'] === $oracle,
    "Total izin REST = layanan web = oracle SQL santri aktif ($oracle)");
$assert(array_column($r['data']['items'], 'id') === array_column($web['items'], 'id'), 'Halaman pertama REST = halaman web (urutan sama)');
$item = $r['data']['items'][0] ?? [];
$assert(!array_key_exists('alasan', $item) && ($item['detail']['api'] ?? '') === '/keamanan/izin/' . ($item['id'] ?? 0), 'Daftar tanpa alasan; tautan REST rincian Keamanan');
$r2 = $api('GET', '/keamanan/izin?peran=admin&santri_id=1&pembimbing_id=1', $t['K']);
$assert((int) $r2['data']['total'] === $oracle && $r2['data']['peran'] === 'keamanan', 'Parameter peran/santri_id/pembimbing_id diabaikan (cakupan tetap Keamanan)');
$r3 = $api('GET', '/keamanan/izin?asal=wali', $t['K']);
$oracleWali = (int) $satu("SELECT COUNT(*) FROM izin_pengajuan p JOIN santri s ON s.id = p.santri_id AND s.is_active = 1 AND s.archived_at IS NULL WHERE p.asal_pengajuan = 'Wali'");
$assert((int) $r3['data']['total'] === $oracleWali && in_array($ids['izin_wali'], array_column($r3['data']['items'], 'id'), true), "Saringan asal Wali = oracle ($oracleWali), memuat pengajuan wali tahap pembimbing");
$assert(!in_array($ids['izin_nonaktif'], array_column($r3['data']['items'], 'id'), true), 'Pengajuan santri nonaktif tidak di daftar');
$assert($api('GET', '/keamanan/izin?asal=lain', $t['K'])['status'] === 422, 'Saringan asal tidak valid → 422');

echo "\n=== R-4 rincian izin ===\n";
$auditSebelum = (int) $satu("SELECT COUNT(*) FROM audit_logs WHERE action = 'keamanan.izin.dilihat'");
$r = $api('GET', '/keamanan/izin/' . $ids['izin_wali'], $t['K']);
$assert($r['status'] === 200 && $r['data']['pengajuan']['alasan'] === $ids['alasan_wali'] && $r['data']['pengajuan']['catatan_wali'] === 'PKF3 catatan wali'
    && is_array($r['data']['riwayat']) && count($r['data']['riwayat']) >= 1, 'Rincian lengkap: alasan, catatan wali, riwayat (PK-T4)');
$assert(($r['data']['riwayat'][0]['status_sesudah_label'] ?? '') === 'Menunggu tinjauan pembimbing', 'Riwayat membawa label status yang sama dengan halaman web');
$auditBaru = $conn->query("SELECT actor_user_id, entity_id, after_json FROM audit_logs WHERE action = 'keamanan.izin.dilihat' ORDER BY id DESC LIMIT 1")->fetch_assoc();
$assert((int) $satu("SELECT COUNT(*) FROM audit_logs WHERE action = 'keamanan.izin.dilihat'") === $auditSebelum + 1
    && (int) $auditBaru['actor_user_id'] === (int) $ids['k'] && (int) $auditBaru['entity_id'] === (int) $ids['izin_wali']
    && !str_contains((string) $auditBaru['after_json'], 'PKF3'), 'Pembukaan rincian REST tercatat satu kali, pelaku K, tanpa alasan');
$assert($api('GET', '/keamanan/izin/' . $ids['izin_nonaktif'], $t['K'])['status'] === 404, 'Izin santri nonaktif → 404');
$tidakAda = $api('GET', '/keamanan/izin/99999999', $t['K']);
$assert($tidakAda['status'] === 404, 'Izin tidak ada → 404');
$assert($api('GET', '/keamanan/izin/' . $ids['izin_aktif'], $t['G'])['status'] === 200, 'G membaca rincian izin asal Pengurus santri aktif');
$jalankan("CREATE TRIGGER pkf4_audit_reject BEFORE INSERT ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture audit reject'");
$gagalAudit = [];
foreach (['/keamanan/izin/' . $ids['izin_wali'], '/keamanan/pelanggaran/' . $ids['pelanggaran_lampiran'],
    '/keamanan/konseling/' . $ids['kasus_internal']] as $p) {
    $r = $api('GET', $p, $t['K']);
    if ($r['status'] !== 503 || $r['data'] !== null || str_contains($r['body'], 'PKF3') || str_contains($r['body'], 'PRIVATE-T5')) {
        $gagalAudit[] = "$p → {$r['status']}";
    }
}
$jalankan('DROP TRIGGER pkf4_audit_reject');
$assert($gagalAudit === [], 'Audit gagal → 503 tanpa isi pada rincian izin/pelanggaran/kasus' . ($gagalAudit === [] ? '' : ': ' . implode(', ', $gagalAudit)));

echo "\n=== R-5 pelanggaran ===\n";
$webP = v3_pelanggaran_service()->page(KeamananAkses::tandai($kUser), ['page' => '1']);
$webC = v3_pelanggaran_service()->statusCounts(KeamananAkses::tandai($kUser), ['page' => '1']);
$r = $api('GET', '/keamanan/pelanggaran', $t['K']);
$assert($r['status'] === 200 && (int) $r['data']['total'] === (int) $webP['total'] && $r['data']['jumlah_status'] === $webC
    && array_column($r['data']['items'], 'id') === array_column($webP['rows'], 'id'), 'Daftar, total, dan angka chip pelanggaran = layanan web Keamanan');
$oracleP = (int) $satu("SELECT COUNT(*) FROM v3_pelanggaran p JOIN santri s ON s.id = p.santri_id AND s.is_active = 1 AND s.archived_at IS NULL WHERE p.archived_at IS NULL AND NOT EXISTS (SELECT 1 FROM v3_pelanggaran nx WHERE nx.revisi_dari_id = p.id)");
$assert(array_sum($r['data']['jumlah_status']) === $oracleP, "Jumlah seluruh chip = oracle SQL pelanggaran kini santri aktif ($oracleP)");
$assert(!in_array($ids['pelanggaran_nonaktif'], array_column($r['data']['items'], 'id'), true), 'Pelanggaran santri nonaktif tidak di daftar');
$r = $api('GET', '/keamanan/pelanggaran/' . $ids['pelanggaran_lampiran'], $t['K']);
$lampiran = $r['data']['lampiran'][0] ?? [];
$assert($r['status'] === 200 && $r['data']['pelanggaran']['uraian'] !== '' && ($lampiran['nama'] ?? '') === 'bukti-pkf3.pdf'
    && !array_key_exists('path', $lampiran) && !array_key_exists('sha256', $lampiran) && !array_key_exists('lokasi_privat', $lampiran)
    && $r['data']['lampiran_unduh'] === 'website', 'Rincian lengkap; lampiran hanya metadata tanpa path/sha256; unduhan lewat website');
$assert(!array_key_exists('rekomendasi', $r['data']) && !array_key_exists('rekonsiliasi', $r['data']) && !array_key_exists('peringatan_konfigurasi', $r['data']),
    'Kunci yang tidak ditampilkan web Keamanan (rekomendasi, rekonsiliasi, peringatan) tidak dikirim');
$r = $api('GET', '/keamanan/pelanggaran/' . $ids['pelanggaran_rahasia'], $t['K']);
$assert($r['status'] === 200 && !in_array($ids['kasus_rahasia'], array_column($r['data']['konseling'], 'kasus_id'), true),
    'Pelanggaran yang ditindaklanjuti kasus Rahasia terbaca, tautan kasus Rahasia tidak dikirim (PF3-5)');
$r = $api('GET', '/keamanan/pelanggaran/' . $ids['pelanggaran_internal'], $t['K']);
$assert(in_array($ids['kasus_internal'], array_column($r['data']['konseling'], 'kasus_id'), true), 'Tautan kasus Internal tetap dikirim');
$assert($api('GET', '/keamanan/pelanggaran/' . $ids['pelanggaran_nonaktif'], $t['K'])['status'] === 404
    && $api('GET', '/keamanan/pelanggaran/99999999', $t['K'])['status'] === 404, 'Pelanggaran santri nonaktif dan yang tidak ada sama-sama 404');
$assert($api('GET', '/keamanan/pelanggaran?status=Rahasia', $t['K'])['status'] === 422 && $api('GET', '/keamanan/pelanggaran?page=0', $t['K'])['status'] === 422
    && $api('GET', '/keamanan/pelanggaran?periode=kemarin', $t['K'])['status'] === 422, 'Status/halaman/periode tidak valid → 422');
$r = $api('GET', '/keamanan/pelanggaran?periode=rentang&mulai=2000-01-01&sampai=2000-01-02', $t['K']);
$assert($r['status'] === 200 && (int) $r['data']['total'] === 0, 'Periode rentang tanpa kejadian → 0 baris');

echo "\n=== R-6 konseling ===\n";
$r = $api('GET', '/keamanan/konseling', $t['K']);
$oracleK = (int) $satu("SELECT COUNT(*) FROM v3_konseling_kasus k JOIN santri s ON s.id = k.santri_id AND s.is_active = 1 AND s.archived_at IS NULL WHERE k.kerahasiaan = 'Internal'");
$assert($r['status'] === 200 && (int) $r['data']['total'] === $oracleK && array_sum($r['data']['jumlah_status']) === $oracleK
    && !in_array($ids['kasus_rahasia'], array_column($r['data']['items'], 'id'), true) && in_array($ids['kasus_internal'], array_column($r['data']['items'], 'id'), true),
    "Daftar dan angka konseling = oracle kasus Internal santri aktif ($oracleK); Rahasia tidak ada");
$santriRahasia = (string) $satu('SELECT s.nama_santri FROM v3_konseling_kasus k JOIN santri s ON s.id = k.santri_id WHERE k.id = ' . (int) $ids['kasus_rahasia']);
$r = $api('GET', '/keamanan/konseling?q=' . rawurlencode($santriRahasia), $t['K']);
$assert(!in_array($ids['kasus_rahasia'], array_column($r['data']['items'], 'id'), true), 'Pencarian nama santri kasus Rahasia tidak memuat kasus Rahasia');
$rahasia = $api('GET', '/keamanan/konseling/' . $ids['kasus_rahasia'], $t['K']);
$tak = $api('GET', '/keamanan/konseling/99999999', $t['K']);
$assert($rahasia['status'] === 404 && $tak['status'] === 404 && $rahasia['json']['error']['message'] === $tak['json']['error']['message'],
    'Kasus Rahasia dan kasus tidak ada: 404 dengan pesan identik (keberadaan tidak bocor)');
$r = $api('GET', '/keamanan/konseling/' . $ids['kasus_internal'], $t['K']);
$assert($r['status'] === 200 && ($r['data']['kasus']['tujuan'] ?? '') !== '' && count($r['data']['sesi']) >= 1 && !array_key_exists('catatan_murobi', $r['data']),
    'Kasus Internal lengkap (tujuan, sesi); catatan murobi yang tidak ditampilkan web tidak dikirim');

echo "\n=== R-7 kehadiran ===\n";
$baca = $kUser + [ReportFilter::BACA_SEMUA_JADWAL => true, ReportFilter::SANTRI_AKTIF_SAJA => true];
$q = ['date_from' => '2020-01-01', 'date_to' => date('Y-m-d', strtotime('+1 year'))];
$webR = report_service()->report($q, $baca, ReportFilter::SCOPE_SANTRI);
$r = $api('GET', '/keamanan/kehadiran?' . http_build_query($q), $t['K']);
$assert($r['status'] === 200 && $r['data']['summary'] === $webR['summary'] && count($r['data']['items']) === count($webR['items']),
    'Ringkasan dan halaman kehadiran REST = laporan web Keamanan (seluruh jadwal, santri aktif)');
$guruJadwal = (int) $satu('SELECT COUNT(DISTINCT id_guru) FROM jadwal_ngaji');
$guruDiLaporan = count(array_unique(array_column($r['data']['items'], 'teacher_id')));
$assert($guruDiLaporan >= 2, "Kehadiran memuat jadwal lebih dari satu guru ($guruDiLaporan dari $guruJadwal guru berjadwal)");
$nonaktifDiLaporan = array_filter($r['data']['items'], static fn (array $i): bool => $i['subject_type'] === 'Santri'
    && (int) $satu('SELECT is_active FROM santri WHERE id = ' . (int) $i['subject_id']) !== 1);
$assert($nonaktifDiLaporan === [], 'Tidak ada baris peserta santri nonaktif');
$pertemuan = (int) ($r['data']['items'][0]['meeting_id'] ?? 0);
$auditSebelum = (int) $satu("SELECT COUNT(*) FROM audit_logs WHERE action = 'keamanan.kehadiran.dilihat'");
$r = $api('GET', '/keamanan/kehadiran/pertemuan/' . $pertemuan, $t['K']);
$assert($r['status'] === 200 && is_array($r['data']['students']) && array_key_exists('izin_id', $r['data']['students'][0] ?? ['izin_id' => null])
    && (int) $satu("SELECT COUNT(*) FROM audit_logs WHERE action = 'keamanan.kehadiran.dilihat'") === $auditSebelum + 1, 'Rincian pertemuan 200 dan berjejak audit');
$assert($api('GET', '/keamanan/kehadiran/pertemuan/99999999', $t['K'])['status'] === 404, 'Pertemuan tidak ada → 404');
$jalankan("CREATE TRIGGER pkf4_audit_reject BEFORE INSERT ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture audit reject'");
$r = $api('GET', '/keamanan/kehadiran/pertemuan/' . $pertemuan, $t['K']);
$jalankan('DROP TRIGGER pkf4_audit_reject');
$assert($r['status'] === 503 && $r['data'] === null, 'Rincian pertemuan saat audit gagal → 503 tanpa isi');
$assert($api('GET', '/keamanan/kehadiran?date_from=2026-13-01', $t['K'])['status'] === 422, 'Tanggal kehadiran tidak valid → 422');
$assert($api('GET', '/reports', $t['K'])['status'] === 403, '`/reports` lama tetap menolak K (REST lama tidak melebar)');
$gGuru = (int) $satu('SELECT guru_id FROM users WHERE id = ' . (int) $ids['g']);
$rg = $api('GET', '/reports?' . http_build_query($q), $t['G']);
$assert($rg['status'] === 200 && array_unique(array_merge([$gGuru], array_column($rg['data']['items'], 'teacher_id'))) === [$gGuru],
    '`/reports` G tetap hanya jadwalnya sendiri walau G memegang Keamanan');

echo "\n=== R-8 pencabutan ===\n";
$jalankan('UPDATE keamanan_assignments SET is_active = 0 WHERE id = ' . (int) $ids['penugasan_k']);
$assert($akses('K') === false && $api('GET', '/keamanan/izin', $t['K'])['status'] === 403
    && $api('GET', '/keamanan/izin/' . $ids['izin_wali'], $t['K'])['status'] === 403, 'Penugasan K dinonaktifkan → token lama ditolak seketika');
$jalankan('UPDATE keamanan_assignments SET is_active = 1 WHERE id = ' . (int) $ids['penugasan_k']);
$assert($akses('K') === true, 'Penugasan K diaktifkan lagi → pulih');
$jalankan('UPDATE guru SET is_active = 0 WHERE id = ' . $gGuru);
$assert($akses('G') === false && $api('GET', '/keamanan/pelanggaran', $t['G'])['status'] === 403, 'Guru G dinonaktifkan → identitas pengurus miliknya gugur, token lama ditolak');
$jalankan('UPDATE guru SET is_active = 1 WHERE id = ' . $gGuru);
$jalankan("UPDATE keamanan_assignments SET tanggal_selesai = DATE_SUB(CURDATE(), INTERVAL 1 DAY), tanggal_mulai = DATE_SUB(CURDATE(), INTERVAL 2 DAY) WHERE id = " . (int) $ids['penugasan_g']);
$assert($akses('G') === false && $api('GET', '/keamanan/konseling', $t['G'])['status'] === 403, 'Penugasan G berakhir kemarin → token lama ditolak');
$jalankan('UPDATE keamanan_assignments SET tanggal_selesai = NULL, tanggal_mulai = CURDATE() WHERE id = ' . (int) $ids['penugasan_g']);
$assert($akses('G') === true, 'Penugasan G dipulihkan');

echo "\n=== R-9 identitas akun ===\n";
$identitas = static function (string $siapa) use ($api, $t): array {
    $r = $api('GET', '/profile/identitas', $t[$siapa]);

    return ['status' => $r['status'], 'data' => $r['data'], 'header' => $r['header']];
};
$ig = $identitas('G');
$assert($ig['status'] === 200 && stripos($ig['header'], 'Cache-Control: private, no-store') !== false, '/profile/identitas 200, no-store');
$pg = $ig['data'];
$assert(($pg['guru']['id'] ?? null) === $gGuru && ($pg['pengurus']['id'] ?? null) === (int) $ids['g_pengurus']
    && ($pg['pengurus']['milik_guru'] ?? null) === true
    && in_array(['jenis' => 'keamanan', 'label' => 'Keamanan', 'identitas' => 'pengurus'], $pg['penugasan'] ?? [], true),
    'G: identitas guru + identitas pengurus milik guru + penugasan Keamanan (identitas pengurus)');
$pk = $identitas('K')['data'];
$assert($pk['guru'] === null && ($pk['pengurus']['milik_guru'] ?? null) === false && array_column($pk['penugasan'], 'jenis') === ['keamanan'],
    'K: hanya identitas pengurus biasa; penugasan Keamanan');
$assert($identitas('W')['data'] === ['guru' => null, 'pengurus' => null, 'penugasan' => []], 'Orang tua: tanpa identitas guru/pengurus/penugasan');
$assert(!in_array('keamanan', array_column($identitas('A')['data']['penugasan'], 'jenis'), true), 'Admin: pengawasan admin tidak tampil sebagai penugasan');
$assert($api('GET', '/profile/identitas', null)['status'] === 401, '/profile/identitas tanpa token → 401');
$profilG = $api('GET', '/profile', $t['G'])['data'] ?? [];
$assert(array_keys($profilG) === ['id', 'name', 'username', 'guru', 'roles', 'capabilities', 'feature_capabilities'], 'Bentuk /profile tidak berubah (tanpa kunci baru)');
$assert(!array_key_exists('identitas', $login_K['data']['profile'] ?? []), 'Respons login tidak berubah (tanpa kunci baru)');
$jalankan('UPDATE guru SET is_active = 0 WHERE id = ' . $gGuru);
$igNonaktif = $identitas('G')['data'];
$assert(is_array($igNonaktif) && array_key_exists('pengurus', $igNonaktif) && $igNonaktif['pengurus'] === null && $igNonaktif['penugasan'] === [],
    'Guru nonaktif → identitas pengurus milik guru dan penugasannya tidak ditampilkan');
$jalankan('UPDATE guru SET is_active = 1 WHERE id = ' . $gGuru);

echo "\n=== R-10 audit dan log ===\n";
$bocor = (int) $satu("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'keamanan.%' AND (COALESCE(after_json,'') LIKE '%PKF3%' OR COALESCE(after_json,'') LIKE '%PRIVATE-T5%'
    OR COALESCE(after_json,'') LIKE '%uraian%' OR COALESCE(after_json,'') LIKE '%alasan%')");
$assert($bocor === 0, 'Seluruh jejak audit Keamanan tanpa alasan/uraian/isi konseling');
$isiLog = (string) file_get_contents($log);
$assert(!preg_match('/PHP (Warning|Notice|Fatal|Deprecated)|Uncaught/', $isiLog), 'Log server tanpa galat PHP');
$assert(!str_contains($isiLog, $ids['alasan_wali']), 'Log server tanpa alasan izin');

echo PHP_EOL . "Hasil: {$lulus} lulus, " . count($gagal) . " gagal" . PHP_EOL;
foreach ($gagal as $g) {
    echo " - $g" . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
