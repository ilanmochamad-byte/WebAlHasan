<?php

declare(strict_types=1);

/**
 * Uji server Fase 3 penugasan lintas guru/pengurus — halaman Keamanan (hanya
 * baca) lewat server PHP lokal di atas DB uji `tests/penugasan_keamanan_f3_db.sh`
 * dengan skenario `tests/penugasan_keamanan_f3_seed.php`.
 *
 *   PKF3_DB=test_pk_f3_http_test bash tests/penugasan_keamanan_f3_db.sh
 *   PKF3_DB=test_pk_f3_http_test php tests/penugasan_keamanan_f3_http.php
 *
 *   H-1 menu: grup Keamanan pada peran Pengurus untuk K; tidak untuk N dan admin
 *   H-2 izin: daftar/rincian/cetak 200; santri nonaktif 404; POST 405; N/admin 403
 *   H-3 pelanggaran: tanpa formulir; tautan Rahasia tersembunyi; nonaktif 404; cetak; lampiran
 *   H-4 konseling: Rahasia tidak di daftar, rincian 404; Internal lengkap; cetak
 *   H-5 kehadiran: seluruh jadwal, tanpa tombol CSV; ekspor CSV 403; cetak 200; N 403
 *   H-6 guru+Keamanan (G): peran Guru ditawari "Buka sebagai Pengurus"; peran Pengurus 200;
 *       kehadiran sebagai Guru tetap jadwal sendiri (CSV ada)
 *   H-7 halaman biasa tidak melebar: pembinaan/rincian izin V2/V7 biasa tetap menolak K
 *   H-8 pusat penugasan admin: jenis Keamanan tampil; daftar subjek
 *   H-9 audit tanpa isi; log server tanpa galat PHP
 *
 * Mengubah data uji; bangun ulang DB sebelum mengulang.
 */

$root = dirname(__DIR__);
$db = getenv('PKF3_DB') ?: 'test_pk_f3_http_test';
$port = (int) (getenv('PKF3_PORT') ?: 8981);
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
$ids = require __DIR__ . '/penugasan_keamanan_f3_seed.php';
register_shutdown_function(static function () use ($ids): void {
    @unlink($ids['lampiran_berkas']);
});
$log = tempnam(sys_get_temp_dir(), 'pkf3-server-');
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

final class Klien
{
    private string $jar;

    public function __construct(private string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'pkf3-jar-');
    }

    /** @return array{status:int, body:string, location:?string, headers:string} */
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
        $headers = substr($out, 0, $size);
        preg_match('/^Location:\s*(.+)$/mi', $headers, $m);

        return ['status' => $status, 'body' => substr($out, $size), 'location' => isset($m[1]) ? trim($m[1]) : null, 'headers' => $headers];
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

    public function peran(string $peran): void
    {
        $this->req('/portal/peran.php', ['_csrf' => $this->csrf(), 'peran' => $peran, 'kembali' => '/portal/index.php']);
    }
}

$base = 'http://127.0.0.1:' . $port;
$web = static function (string $user) use ($base): Klien {
    $k = new Klien($base);
    $k->login($user);

    return $k;
};
$conn = app_db();
$satu = static function (string $sql) use ($conn): mixed {
    $r = $conn->query($sql);
    if ($r === false) {
        throw new RuntimeException($conn->error . ' :: ' . $sql);
    }

    return $r->fetch_row()[0] ?? null;
};
// Formulir POST selain tombol keluar bawaan kerangka halaman.
$tanpaPost = static fn (string $html): bool => preg_match('/<form[^>]*method="post"(?![^>]*logout\.php)[^>]*>/i', $html) !== 1;

$wk = $web('sbx_pengurus_biasa');
$wn = $web('sbx_pengurus_b');
$wa = $web('sbx_admin');
$wg = $web('sbx_guru_biasa');

// --- H-1 menu -----------------------------------------------------------------------------
$r = $wk->req('/portal/index.php');
$assert($r['status'] === 200 && str_contains($r['body'], 'keamanan_izin.php') && str_contains($r['body'], 'keamanan_pelanggaran.php')
    && str_contains($r['body'], 'Kehadiran pengajian') && str_contains($r['body'], '>Keamanan<'),
    'H-1 K: grup menu Keamanan (kehadiran, perizinan, pembinaan) pada peran Pengurus');
$r = $wn->req('/portal/index.php');
$assert($r['status'] === 200 && !str_contains($r['body'], 'keamanan_izin.php'), 'H-1 N tanpa penugasan: tanpa menu Keamanan');
$r = $wa->req('/portal/index.php');
$assert($r['status'] === 200 && !str_contains($r['body'], 'keamanan_izin.php'), 'H-1 admin: peta menu admin tidak berubah (tanpa Keamanan)');

// --- H-2 izin -------------------------------------------------------------------------------
$r = $wk->req('/portal/keamanan_izin.php');
$assert($r['status'] === 200 && str_contains($r['body'], 'data-id="' . $ids['izin_wali'] . '"') && $tanpaPost($r['body'])
    && str_contains($r['body'], 'Cetak / PDF') && !str_contains($r['body'], 'Ekspor CSV') && !str_contains($r['body'], $ids['alasan_wali']),
    'H-2 daftar izin: baris wali, tanpa formulir POST, cetak tanpa CSV, tanpa alasan di daftar');
$r = $wk->req('/portal/keamanan_izin.php?asal=wali&tahap=pembimbing');
$assert($r['status'] === 200 && str_contains($r['body'], 'data-id="' . $ids['izin_wali'] . '"') && !str_contains($r['body'], 'data-id="' . $ids['izin_aktif'] . '"'),
    'H-2 saringan asal/tahap bekerja');
$r = $wk->req('/portal/keamanan_izin.php?dari=salah');
$assert($r['status'] === 200 && str_contains($r['body'], 'tanpa saringan'), 'H-2 saringan tidak valid: pesan + daftar tanpa saringan');
$r = $wk->req('/portal/keamanan_izin_detail.php?id=' . $ids['izin_wali']);
$assert($r['status'] === 200 && str_contains($r['body'], $ids['alasan_wali']) && str_contains($r['body'], 'PKF3 catatan wali') && $tanpaPost($r['body'])
    && !preg_match('/>\s*(Putuskan|Teruskan|Koreksi|Batalkan pengajuan)\s*</', $r['body']),
    'H-2 rincian izin wali: alasan dan catatan lengkap, tanpa tombol tindakan');
$r = $wk->req('/portal/keamanan_izin_detail.php?id=' . $ids['izin_aktif']);
$assert($r['status'] === 200 && str_contains($r['body'], 'Keputusan') && str_contains($r['body'], 'Riwayat'), 'H-2 rincian izin pengurus: keputusan dan riwayat');
$assert($wk->req('/portal/keamanan_izin_detail.php?id=' . $ids['izin_nonaktif'])['status'] === 404
    && $wk->req('/portal/keamanan_izin_detail.php?id=999999999')['status'] === 404,
    'H-2 rincian santri nonaktif dan ID tidak ada → 404');
$csrfK = $wk->csrf();
foreach (['/portal/keamanan_izin.php', '/portal/keamanan_izin_detail.php?id=' . $ids['izin_wali'], '/portal/keamanan_pelanggaran.php', '/portal/keamanan_konseling_detail.php?id=1'] as $path) {
    $assert($wk->req($path, ['_csrf' => $csrfK, 'aksi' => 'putuskan'])['status'] === 405, "H-2 POST $path → 405");
}
$assert($wn->req('/portal/keamanan_izin.php')['status'] === 403 && $wa->req('/portal/keamanan_izin.php')['status'] === 403
    && $wn->req('/portal/keamanan_izin_detail.php?id=' . $ids['izin_wali'])['status'] === 403,
    'H-2 N dan admin (tanpa penugasan Keamanan sendiri) → 403');
$cetakSebelum = (int) $satu("SELECT COUNT(*) FROM audit_logs WHERE action = 'keamanan.izin.dicetak'");
$r = $wk->req('/portal/keamanan_izin_cetak.php?asal=wali');
$assert($r['status'] === 200 && str_contains($r['body'], 'Laporan Perizinan Santri — Keamanan') && str_contains($r['body'], '#' . $ids['izin_wali'])
    && (int) $satu("SELECT COUNT(*) FROM audit_logs WHERE action = 'keamanan.izin.dicetak'") === $cetakSebelum + 1,
    'H-2 cetak izin 200, memuat baris saringan, dan tercatat di audit');
// `peran=keamanan` diabaikan: K jatuh ke cakupan Pengurus V2 miliknya sendiri, bukan cakupan Keamanan.
$r = $wk->req('/portal/izin_wali_laporan_ekspor.php?peran=keamanan');
$assert(!str_contains($r['headers'], '-keamanan-') && !str_contains($r['body'], $ids['alasan_wali']) && !str_contains($r['body'], (string) $ids['izin_wali'] . ',Wali'),
    'H-2 parameter peran=keamanan tidak membuka ekspor CSV cakupan Keamanan (jatuh ke cakupan Pengurus milik akun)');

// --- H-3 pelanggaran ------------------------------------------------------------------------
$r = $wk->req('/portal/keamanan_pelanggaran.php');
$assert($r['status'] === 200 && $tanpaPost($r['body']) && !str_contains($r['body'], 'Catat pelanggaran')
    && str_contains($r['body'], 'keamanan_pelanggaran_detail.php?id=' . $ids['pelanggaran_rahasia'])
    && !str_contains($r['body'], 'keamanan_pelanggaran_detail.php?id=' . $ids['pelanggaran_nonaktif'] . '"'),
    'H-3 daftar pelanggaran: tanpa formulir/Catat; santri nonaktif tidak muncul');
$r = $wk->req('/portal/keamanan_pelanggaran_detail.php?id=' . $ids['pelanggaran_rahasia']);
$assert($r['status'] === 200 && !str_contains($r['body'], 'keamanan_konseling_detail.php?id=' . $ids['kasus_rahasia']) && $tanpaPost($r['body'])
    && !str_contains($r['body'], 'Tandai mengetahui') && str_contains($r['body'], 'data-hanya-baca'),
    'H-3 rincian pelanggaran bertaut Rahasia: tautan kasus tidak tampil; tanpa tindakan');
$r = $wk->req('/portal/keamanan_pelanggaran_detail.php?id=' . $ids['pelanggaran_internal']);
$assert($r['status'] === 200 && str_contains($r['body'], 'keamanan_konseling_detail.php?id=' . $ids['kasus_internal']), 'H-3 rincian pelanggaran bertaut Internal: tautan kasus tampil');
$assert($wk->req('/portal/keamanan_pelanggaran_detail.php?id=' . $ids['pelanggaran_nonaktif'])['status'] === 404, 'H-3 pelanggaran santri nonaktif → 404');
$r = $wk->req('/portal/keamanan_pelanggaran_cetak.php?id=' . $ids['pelanggaran_internal']);
$assert($r['status'] === 200 && str_contains($r['body'], 'Catatan Pelanggaran Santri'), 'H-3 cetak pelanggaran 200');
$r = $wk->req('/portal/keamanan_lampiran.php?id=' . $ids['lampiran']);
$assert($r['status'] === 200 && $r['body'] === "PKF3 lampiran sintetis\n" && str_contains($r['headers'], 'attachment'), 'H-3 lampiran pelanggaran terunduh');
$assert($wk->req('/portal/keamanan_lampiran.php?id=999999')['status'] === 404 && $wn->req('/portal/keamanan_lampiran.php?id=' . $ids['lampiran'])['status'] === 403,
    'H-3 lampiran tidak ada → 404; N → 403');

// --- H-4 konseling --------------------------------------------------------------------------
$r = $wk->req('/portal/keamanan_konseling.php');
$assert($r['status'] === 200 && str_contains($r['body'], 'keamanan_konseling_detail.php?id=' . $ids['kasus_internal'])
    && !str_contains($r['body'], 'keamanan_konseling_detail.php?id=' . $ids['kasus_rahasia'] . '"') && !str_contains($r['body'], '>Rahasia<'),
    'H-4 daftar konseling: Internal tampil, Rahasia tidak');
$assert($wk->req('/portal/keamanan_konseling_detail.php?id=' . $ids['kasus_rahasia'])['status'] === 404
    && $wk->req('/portal/keamanan_konseling_cetak.php?id=' . $ids['kasus_rahasia'])['status'] === 404,
    'H-4 rincian dan cetak kasus Rahasia → 404 (keberadaan tidak bocor)');
$tujuan = (string) $satu('SELECT tujuan FROM v3_konseling_kasus WHERE id = ' . $ids['kasus_internal']);
$r = $wk->req('/portal/keamanan_konseling_detail.php?id=' . $ids['kasus_internal']);
$assert($r['status'] === 200 && str_contains($r['body'], htmlspecialchars($tujuan, ENT_QUOTES, 'UTF-8')) && $tanpaPost($r['body']), 'H-4 rincian kasus Internal: tujuan lengkap, tanpa formulir');
$r = $wk->req('/portal/keamanan_konseling_cetak.php?id=' . $ids['kasus_internal']);
$assert($r['status'] === 200 && str_contains($r['body'], 'Catatan Konseling Internal'), 'H-4 cetak kasus Internal 200');

// --- H-5 kehadiran --------------------------------------------------------------------------
$r = $wk->req('/admin/admin_laporan_absensi.php');
$assert($r['status'] === 200 && str_contains($r['body'], 'Seluruh jadwal pengajian') && !str_contains($r['body'], 'export_laporan_absensi.php')
    && str_contains($r['body'], 'laporan_absensi_cetak.php'), 'H-5 K: laporan kehadiran seluruh jadwal, Cetak tanpa tombol CSV');
$r = $wk->req('/admin/export_laporan_absensi.php');
$assert($r['status'] === 403 && !str_contains($r['headers'], 'text/csv'), 'H-5 K: ekspor CSV kehadiran → 403 (PK-T2)');
$assert($wk->req('/admin/laporan_absensi_cetak.php')['status'] === 200, 'H-5 K: cetak kehadiran 200');
$assert($wk->req('/admin/admin_laporan_absensi.php', ['_csrf' => $csrfK])['status'] === 405, 'H-5 K: POST laporan kehadiran → 405');
$assert($wn->req('/admin/admin_laporan_absensi.php')['status'] === 403, 'H-5 N tanpa penugasan: laporan kehadiran → 403');
$r = $web('sbx_pendidikan')->req('/admin/export_laporan_absensi.php');
$assert($r['status'] === 200 && str_contains($r['headers'], 'text/csv'), 'H-5 kontrol: Bagian Pendidikan tetap dapat mengekspor CSV');

// --- H-6 guru + Keamanan -------------------------------------------------------------------
$r = $wg->req('/portal/keamanan_izin.php');
$assert($r['status'] === 403 && str_contains($r['body'], 'Buka sebagai'), 'H-6 G pada peran Guru: halaman Keamanan menawarkan "Buka sebagai Pengurus"');
$r = $wg->req('/admin/admin_laporan_absensi.php');
$assert($r['status'] === 200 && !str_contains($r['body'], 'Seluruh jadwal pengajian') && str_contains($r['body'], 'export_laporan_absensi.php'),
    'H-6 G pada peran Guru: laporan kehadiran tetap jadwal sendiri dan CSV');
$wg->peran('pengurus');
$assert($wg->req('/portal/keamanan_izin.php')['status'] === 200 && $wg->req('/portal/keamanan_konseling.php')['status'] === 200,
    'H-6 G pada peran Pengurus: halaman Keamanan 200');
$r = $wg->req('/admin/admin_laporan_absensi.php');
$assert($r['status'] === 200 && str_contains($r['body'], 'Seluruh jadwal pengajian') && !str_contains($r['body'], 'export_laporan_absensi.php'),
    'H-6 G pada peran Pengurus: kehadiran seluruh jadwal tanpa CSV');
$assert($wg->req('/admin/export_laporan_absensi.php')['status'] === 403, 'H-6 G pada peran Pengurus: ekspor CSV → 403');

// --- H-7 halaman biasa tidak melebar ---------------------------------------------------------
$assert($wk->req('/portal/v3_pelanggaran.php')['status'] === 403 && $wk->req('/portal/v3_konseling_detail.php?id=' . $ids['kasus_internal'])['status'] === 403,
    'H-7 halaman pembinaan biasa tetap menolak K');
$assert($wk->req('/portal/izin_wali_detail.php?id=' . $ids['izin_wali'])['status'] !== 200 && $wk->req('/portal/izin_detail.php?id=' . $ids['izin_aktif'])['status'] !== 200,
    'H-7 rincian izin V7/V2 biasa tetap menolak K (Keamanan memakai rinciannya sendiri)');

// --- H-8 pusat penugasan -------------------------------------------------------------------
$r = $wa->req('/admin/admin_penugasan.php?jenis=keamanan');
$assert($r['status'] === 200 && str_contains($r['body'], 'Seluruh santri aktif') && str_contains($r['body'], 'Keamanan (2)'),
    'H-8 pusat penugasan: jenis Keamanan, cakupan seluruh santri aktif, dua penugasan aktif');
$posKeamanan = strpos($r['body'], 'jenis=keamanan"');
$posMendatang = strpos($r['body'], 'Disiapkan untuk modul mendatang');
$assert($posKeamanan !== false && $posMendatang !== false && $posKeamanan < $posMendatang, 'H-8 Keamanan tidak berada di kelompok "modul mendatang"');

// --- H-9 audit dan log --------------------------------------------------------------------------
$jejak = (string) $satu("SELECT GROUP_CONCAT(COALESCE(after_json,'') SEPARATOR ' ') FROM audit_logs WHERE action LIKE 'keamanan.%'");
$assert((int) $satu("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'keamanan.%'") >= 8 && !str_contains($jejak, 'PKF3') && !str_contains($jejak, 'alasan')
    && !str_contains($jejak, htmlspecialchars(mb_substr($tujuan, 0, 15))), 'H-9 jejak audit Keamanan tanpa alasan/uraian/tujuan');
usleep(300000);
$isiLog = (string) file_get_contents($log);
$assert(preg_match('/PHP (Warning|Notice|Fatal|Deprecated)|Uncaught/', $isiLog) !== 1, 'H-9 log server tanpa galat PHP');

echo PHP_EOL . 'Hasil: ' . $lulus . ' lulus, ' . count($gagal) . ' gagal.' . PHP_EOL;
exit($gagal === [] ? 0 : 1);
