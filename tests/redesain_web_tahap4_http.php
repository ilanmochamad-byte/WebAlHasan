<?php

declare(strict_types=1);

/**
 * Uji server redesain website Tahap 4 — perizinan, pengajian & cetak.
 *
 * Kriteria selesai Tahap 4 (cetak biru): pengurus mengajukan izin dari HP
 * tanpa geser horizontal (bagian HP diuji browser); Bagian Pendidikan membaca
 * laporan absensi seluruh jadwal; laporan cetak bertanda tangan Bagian
 * Pendidikan. Skrip ini menjalankan server PHP lokal sendiri di atas DB uji
 * dari `tests/redesain_web_tahap4_db.sh` (+ salinan kosong) dan memeriksa:
 *
 *   H4-A  akses per peran ke setiap layar I-1 s.d. I-7, J-1 s.d. J-6 (status
 *         HTTP, menu/tab aktif, kerangka bersama);
 *   H4-B  keputusan H: Bagian Pendidikan membaca SELURUH jadwal (angka sama
 *         dengan admin), guru hanya miliknya, pengurus biasa/orang tua 403,
 *         hanya-baca (POST 405, tidak membuka modul jadwal), REST API tidak
 *         ikut melebar, penanda tidak dapat disuntik lewat alamat;
 *   H4-C  I-1/I-2/I-7: angka = basis data, label tanpa "V2", saringan Aktif/
 *         Riwayat orang tua, santri di luar cakupan tidak terbuka, escape;
 *   H4-D  I-3/N: keputusan dari antrean kembali ke antrean dengan detail sama;
 *   H4-E  I-4/M/Q: info panel memeriksa cakupan, jumlah izin semester = DB;
 *   H4-F  I-5: tindakan sesuai hak, info teknis hanya admin, id tidak sah;
 *   H4-G  I-6: grafik dari filter yang sama (jumlah = total), periode;
 *   H4-H  J-1/J-2/J-3/R/S/T: Ahad, Senin–Ahad, guru hanya jadwal sendiri,
 *         kehadiran hanya-baca, label "Sedang izin" = basis data;
 *   H4-I  X-1/AS: cetak perizinan dan kehadiran (web dan renderer) bertanda
 *         tangan Bagian Pendidikan sekali di lembar terakhir, kode laporan;
 *   H4-J  keadaan kosong;
 *   H4-K  tidak ada galat/peringatan PHP di log server.
 *
 *   bash tests/redesain_web_tahap4_db.sh
 *   php tests/redesain_web_tahap4_http.php
 */

use App\Api\ApiException;
use App\Report\IzinPrintRenderer;
use App\Report\PrintRenderer;
use App\Report\ReportFilter;

$root = dirname(__DIR__);
$db = getenv('TAHAP4_DB') ?: 'test_redesain_t4_test';
$dbKosong = getenv('TAHAP4_DB_KOSONG') ?: 'test_redesain_t4_kosong_test';
$port = (int) (getenv('TAHAP4_PORT') ?: 8972);
$portKosong = $port + 1;
if (!preg_match('/^test_.*_test$/', $db) || !preg_match('/^test_.*_test$/', $dbKosong)) {
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

// --- Server lokal ------------------------------------------------------------
$logs = [];
$servers = [];
foreach ([[$port, $db], [$portKosong, $dbKosong]] as [$p, $nama]) {
    $log = tempnam(sys_get_temp_dir(), 'tahap4-server-');
    $logs[$p] = $log;
    $servers[] = proc_open(['php', '-S', '127.0.0.1:' . $p, $root . '/tests/redesain_web_tahap1_router.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root, array_merge(getenv(), ['DB_NAME' => $nama]));
}
register_shutdown_function(static function () use (&$servers): void {
    foreach ($servers as $server) {
        if (is_resource($server)) {
            proc_terminate($server);
        }
    }
});
foreach ([$port, $portKosong] as $p) {
    for ($i = 0; $i < 50; $i++) {
        $sock = @fsockopen('127.0.0.1', $p);
        if ($sock !== false) {
            fclose($sock);
            break;
        }
        usleep(100000);
    }
}

final class Klien
{
    private string $jar;

    public function __construct(private string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'tahap4-jar-');
    }

    /**
     * @return array{status:int, body:string, location:?string, type:string}
     */
    public function req(string $path, ?array $post = null, array $headers = []): array
    {
        $h = curl_init($this->base . $path);
        curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $headers]);
        if ($post !== null) {
            curl_setopt($h, CURLOPT_POST, true);
            curl_setopt($h, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $raw = (string) curl_exec($h);
        $status = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
        $size = (int) curl_getinfo($h, CURLINFO_HEADER_SIZE);
        $type = (string) curl_getinfo($h, CURLINFO_CONTENT_TYPE);
        curl_close($h);
        preg_match('/^Location:\s*(.+)$/mi', substr($raw, 0, $size), $m);

        return ['status' => $status, 'body' => substr($raw, $size), 'location' => isset($m[1]) ? trim($m[1]) : null, 'type' => $type];
    }

    public function csrf(string $path = '/portal/index.php'): string
    {
        $r = $this->req($path);
        if (preg_match('/name="_csrf" value="([^"]+)"/', $r['body'], $m) !== 1) {
            throw new RuntimeException('Token CSRF tidak ditemukan pada ' . $path);
        }

        return $m[1];
    }

    public function login(string $user): void
    {
        $this->req('/admin/cek_login.php', ['_csrf' => $this->csrf(), 'username' => $user, 'password' => 'Sandbox#123']);
    }

    public function follow(array $r): array
    {
        $loc = (string) $r['location'];

        return $loc === '' ? $r : $this->req(preg_replace('#^https?://[^/]+#', '', $loc));
    }
}

$base = 'http://127.0.0.1:' . $port;
$klien = static function (string $user, ?string $b = null) use ($base): Klien {
    $k = new Klien($b ?? $base);
    $k->login($user);

    return $k;
};
$teks = static fn (string $html): string => trim(html_entity_decode((string) preg_replace('/\s+/', ' ', (string) preg_replace('/<[^>]+>/', ' ', (string) preg_replace('#<(script|style)\b.*?</\1>#s', '', $html))), ENT_QUOTES));
$sql = static function (string $query): array {
    $r = app_db()->query($query);

    return $r === true ? [] : $r->fetch_all(MYSQLI_ASSOC);
};
$satu = static fn (string $query): mixed => array_values($sql($query)[0] ?? [null])[0];
$aktif = static function (string $html): ?string {
    $side = substr($html, (int) strpos($html, 'id="ah-sidebar"'));
    preg_match('/aria-current="page">\s*<i[^>]*><\/i>\s*<span>([^<]*)<\/span>/', $side, $m);

    return isset($m[1]) ? html_entity_decode($m[1], ENT_QUOTES) : null;
};
$tabModul = static function (string $html): ?string {
    preg_match('/ah-tabs--module.*?aria-current="page"><span>([^<]*)</s', $html, $m);

    return isset($m[1]) ? html_entity_decode($m[1], ENT_QUOTES) : null;
};
$main = static fn (string $html): string => (string) substr($html, (int) strpos($html, '<main'));

$admin = $klien('sbx_admin');
$pengurus = $klien('sbx_pengurus_a');
$murobi = $klien('sbx_murobi_c');
$murobiA = $klien('sbx_murobi_a');
$ortu = $klien('sbx_ortu_a');
$guru = $klien('sbx_guru_biasa');
$pendidikan = $klien('sbx_pendidikan');
$pengurusBiasa = $klien('sbx_pengurus_biasa');
$guruMapel = $klien('sbx_guru_mapel');
$anonim = new Klien($base);

$pertemuan = (int) $satu("SELECT p.id FROM pertemuan_pengajian p JOIN jadwal_ngaji j ON j.id=p.jadwal_id WHERE j.fan_ilmu='Tafsir Jalalain' AND p.status='Dibuka' ORDER BY p.id DESC LIMIT 1");
$pertemuanLain = (int) $satu("SELECT p.id FROM pertemuan_pengajian p JOIN jadwal_ngaji j ON j.id=p.jadwal_id WHERE j.fan_ilmu='Akhlak' AND j.nama_kitab='Kitab SBX' ORDER BY p.id DESC LIMIT 1");
$izinPerlu = (int) $satu("SELECT id FROM izin_pengajuan WHERE status='Perlu Penetapan Admin' ORDER BY id LIMIT 1");
$antrean = array_map(static fn (array $r): int => (int) $r['id'], $sql("SELECT id FROM izin_pengajuan WHERE murobi_guru_id=3 AND status='Diajukan' ORDER BY tgl_izin DESC, id DESC"));
$izinLegacy = (int) $satu('SELECT id FROM izin_pengajuan WHERE is_legacy=1 ORDER BY id LIMIT 1');
$izinPengurusA = (int) $satu("SELECT id FROM izin_pengajuan WHERE pengurus_id=1 AND status='Disetujui' ORDER BY id DESC LIMIT 1");
$izinPengurusB = (int) $satu('SELECT id FROM izin_pengajuan WHERE pengurus_id IS NOT NULL AND pengurus_id<>1 AND murobi_guru_id<>3 AND santri_id NOT IN (SELECT santri_id FROM santri_wali WHERE wali_id=1) ORDER BY id DESC LIMIT 1');
$assert($pertemuan > 0 && $pertemuanLain > 0 && $izinPerlu > 0 && count($antrean) === 3, 'Fixture Tahap 4 tersedia (pertemuan, antrean murobi C, izin perlu penetapan)');

// ===================================================================== H4-A
echo PHP_EOL . '=== H4-A. Akses per peran, menu, dan tab ===' . PHP_EOL;
$matriks = [
    // alamat => [admin, pengurus, murobi_c, ortu, guru_biasa, pendidikan, pengurus_biasa, guru_mapel]
    // Bagian Pendidikan berperan aktif "Bagian Pendidikan": halaman perizinan
    // milik peran Pengurus-nya ditahan dengan pilihan "Buka sebagai" (A-2).
    '/portal/izin_ringkasan.php' => [200, 200, 200, 200, 403, 403, 200, 403],
    '/portal/izin.php' => [200, 200, 200, 200, 403, 403, 200, 403],
    '/portal/izin_antrean.php' => [200, 200, 200, 200, 403, 403, 200, 403],
    '/portal/izin_buat.php' => [200, 200, 403, 403, 403, 403, 200, 403],
    '/portal/laporan.php' => [200, 200, 200, 200, 403, 403, 200, 403],
    '/admin/admin_pengajian.php' => [200, 403, 200, 403, 200, 403, 403, 200],
    '/admin/admin_pengajian.php?tab=pertemuan' => [200, 403, 200, 403, 200, 403, 403, 200],
    '/admin/admin_laporan_absensi.php' => [200, 403, 200, 403, 200, 200, 403, 200],
];
$akun = ['admin' => $admin, 'pengurus' => $pengurus, 'murobi_c' => $murobi, 'ortu' => $ortu, 'guru_biasa' => $guru, 'pendidikan' => $pendidikan, 'pengurus_biasa' => $pengurusBiasa, 'guru_mapel' => $guruMapel];
foreach ($matriks as $path => $harap) {
    $aktual = [];
    foreach (array_values($akun) as $k) {
        $aktual[] = $k->req($path)['status'];
    }
    $assert($aktual === $harap, "$path status per peran " . implode('/', $aktual) . ' = ' . implode('/', $harap));
}
foreach (['/portal/izin_ringkasan.php', '/portal/izin.php', '/admin/admin_pengajian.php', '/admin/admin_laporan_absensi.php'] as $path) {
    $r = $anonim->req($path);
    $assert(in_array($r['status'], [302, 303], true) && str_contains((string) $r['location'], '/portal/index.php?pesan=sesi'), "Anonim $path diarahkan ke halaman masuk");
}
$cek = [
    [$admin, '/portal/izin_ringkasan.php', 'Perizinan', 'Ringkasan'], [$admin, '/portal/izin.php', 'Perizinan', 'Pengajuan'],
    [$admin, '/portal/izin_antrean.php', 'Perizinan', 'Antrean'], [$admin, '/portal/laporan.php', 'Perizinan', 'Laporan'],
    [$admin, '/portal/izin_buat.php', 'Perizinan', 'Pengajuan'], [$admin, '/admin/admin_pengajian.php', 'Pengajian', 'Jadwal'],
    [$admin, '/admin/admin_pengajian.php?tab=pertemuan', 'Pengajian', 'Pertemuan'], [$admin, '/admin/admin_laporan_absensi.php', 'Pengajian', 'Kehadiran'],
    [$admin, "/admin/laporan_absensi_detail.php?id=$pertemuan", 'Pengajian', 'Kehadiran'], [$murobi, '/portal/izin_antrean.php', 'Antrean izin', 'Menunggu keputusan'],
    [$ortu, '/portal/izin.php', 'Izin anak', 'Aktif'], [$ortu, '/portal/izin.php?tab=riwayat', 'Izin anak', 'Riwayat'],
    [$pendidikan, '/admin/admin_laporan_absensi.php', 'Laporan absensi pengajian', null], [$pengurus, '/portal/izin_buat.php', 'Perizinan', 'Pengajuan'],
];
foreach ($cek as [$k, $path, $menu, $tab]) {
    $h = $k->req($path)['body'];
    $assert($aktif($h) === $menu && $tabModul($h) === $tab && str_contains($h, 'id="ah-shell"'), "$path: menu \"$menu\"" . ($tab ? " tab \"$tab\"" : ' tanpa tab') . ' (' . $aktif($h) . '/' . $tabModul($h) . ')');
}
$h = $admin->req('/admin/admin_pengajian.php')['body'];
preg_match_all('#ah-tabs--module.*?</ul>#s', $h, $m);
$assert(substr_count($m[0][0] ?? '', '<li>') === 3 && str_contains($m[0][0] ?? '', 'Jadwal') && str_contains($m[0][0] ?? '', 'Pertemuan') && str_contains($m[0][0] ?? '', 'Kehadiran'), 'Pengajian bertab Jadwal · Pertemuan · Kehadiran (cetak biru J-1 s.d. J-5)');
$h = $pendidikan->req('/portal/index.php')['body'];
$assert(str_contains($h, '>Laporan absensi pengajian<') && str_contains($h, 'admin_laporan_absensi.php'), 'Menu Bagian Pendidikan memuat "Laporan absensi pengajian" (grup Laporan)');
$h = $pengurusBiasa->req('/portal/index.php')['body'];
$assert(!str_contains($h, 'Laporan absensi pengajian'), 'Pengurus tanpa penugasan Pendidikan tidak melihat menu laporan absensi');

// ===================================================================== H4-B
echo PHP_EOL . '=== H4-B. Keputusan H: laporan absensi Bagian Pendidikan ===' . PHP_EOL;
$angka = static function (string $html): array {
    preg_match('#<p class="ah-stat__label">Pertemuan</p><p class="ah-stat__value">(\d+)</p>#', $html, $m);
    preg_match_all('#<td>[^<]*<span class="ah-cell-sub">Kitab SBX</span></td>#', $html, $baris);

    return ['pertemuan' => (int) ($m[1] ?? -1), 'jadwal' => count($baris[0])];
};
$q = '?periode=semester&subject_scope=gabungan';
$nAdmin = $angka($admin->req('/admin/admin_laporan_absensi.php' . $q)['body']);
$hP = $pendidikan->req('/admin/admin_laporan_absensi.php' . $q)['body'];
$nPend = $angka($hP);
$nGuru = $angka($murobiA->req('/admin/admin_laporan_absensi.php' . $q)['body']);
$guruLainId = (int) $satu("SELECT id_guru FROM jadwal_ngaji WHERE fan_ilmu='Akhlak' AND nama_kitab='Kitab SBX' LIMIT 1");
$assert($nAdmin['pertemuan'] >= 2 && $nPend === $nAdmin, 'Bagian Pendidikan membaca angka seluruh jadwal = admin (' . json_encode($nPend) . ' = ' . json_encode($nAdmin) . ')');
$assert($nGuru['pertemuan'] < $nAdmin['pertemuan'], 'Guru tetap hanya jadwal sendiri (' . $nGuru['pertemuan'] . ' < ' . $nAdmin['pertemuan'] . ')');
$assert(str_contains($hP, '<h1>Laporan absensi pengajian</h1>') && str_contains($hP, 'Seluruh jadwal pengajian · hanya-baca') && !str_contains($hP, 'ah-tabs--module'), 'J-6: judul dan keterangan hanya-baca, tanpa tab Jadwal/Pertemuan');
$r = $pendidikan->req('/admin/admin_laporan_absensi.php?teacher_id=' . $guruLainId . '&periode=semester');
$assert($r['status'] === 200 && $angka($r['body'])['pertemuan'] >= 1, 'Bagian Pendidikan boleh menyaring guru mana pun (guru lain)');
$r = $murobiA->req('/admin/admin_laporan_absensi.php?teacher_id=' . $guruLainId . '&periode=semester');
$assert($r['status'] === 403, 'Guru menyaring guru lain tetap ditolak 403');
$r = $pendidikan->req("/admin/laporan_absensi_detail.php?id=$pertemuanLain");
$assert($r['status'] === 200 && !str_contains($main($r['body']), '<form method="post"'), 'J-5 Bagian Pendidikan membuka detail pertemuan guru mana pun, tanpa formulir ubah');
$r = $murobiA->req("/admin/laporan_absensi_detail.php?id=$pertemuanLain");
$assert($r['status'] === 403, 'Guru tidak membuka detail pertemuan guru lain (403)');
$csv = $pendidikan->req('/admin/export_laporan_absensi.php?periode=semester&date_from=2026-07-01&date_to=2026-12-31&subject_scope=santri');
$csvAdmin = $admin->req('/admin/export_laporan_absensi.php?date_from=2026-07-01&date_to=2026-12-31&subject_scope=santri');
$assert($csv['status'] === 200 && str_contains($csv['type'], 'text/csv') && substr_count($csv['body'], "\n") === substr_count($csvAdmin['body'], "\n"), 'CSV Bagian Pendidikan = CSV admin untuk filter sama');
$cetak = $pendidikan->req('/admin/laporan_absensi_cetak.php?date_from=2026-07-01&date_to=2026-12-31');
$assert($cetak['status'] === 200 && str_contains($cetak['body'], 'class="ttd"'), 'Cetak kehadiran terbuka untuk Bagian Pendidikan');
foreach (['/admin/admin_laporan_absensi.php', '/admin/export_laporan_absensi.php', '/admin/laporan_absensi_cetak.php'] as $path) {
    $r = $pendidikan->req($path, ['_csrf' => $pendidikan->csrf('/admin/admin_laporan_absensi.php')]);
    $assert($r['status'] === 405, "Hanya-baca: POST $path → 405");
}
$r = $pendidikan->req('/admin/admin_pengajian.php');
$r2 = $pendidikan->req('/admin/admin_pengajian.php', ['_csrf' => $pendidikan->csrf('/admin/admin_laporan_absensi.php'), 'tab' => 'jadwal', 'action' => 'archive', 'id' => '1']);
$assert($r['status'] === 403 && $r2['status'] === 403 && (string) $satu('SELECT archived_at FROM jadwal_ngaji WHERE id=1') === '', 'Bagian Pendidikan tidak membuka modul jadwal/pertemuan dan tidak dapat mengubah jadwal');
foreach ([$pengurusBiasa, $ortu, $pengurus] as $k) {
    $assert($k->req('/admin/admin_laporan_absensi.php')['status'] === 403 && $k->req("/admin/laporan_absensi_detail.php?id=$pertemuan")['status'] === 403, 'Pengurus tanpa penugasan Pendidikan / orang tua: laporan dan detail 403');
}
$r = $pengurusBiasa->req('/admin/admin_laporan_absensi.php?' . ReportFilter::BACA_SEMUA_JADWAL . '=1');
$assert($r['status'] === 403, 'Penanda baca-semua tidak dapat disuntik lewat parameter alamat');
// REST API / layanan tanpa penanda web tidak melebar.
$userPendidikan = ['id' => (int) $satu("SELECT id FROM users WHERE username='sbx_pendidikan'"), 'roles' => ['pengurus'], 'guru_id' => null, 'pengurus_id' => 86, 'name' => 'SBX Bagian Pendidikan'];
try {
    report_service()->apiReport([], $userPendidikan);
    $assert(false, 'apiReport menolak Bagian Pendidikan tanpa penanda web');
} catch (ApiException $e) {
    $assert($e->status() === 403, 'apiReport (REST API) tetap menolak Bagian Pendidikan: 403, kontrak API tidak melebar');
}
try {
    report_service()->meeting($pertemuan, $userPendidikan);
    $assert(false, 'meeting() tanpa penanda menolak');
} catch (ApiException $e) {
    $assert($e->status() === 403, 'Layanan detail pertemuan tanpa penanda web menolak 403');
}
$assert(report_service()->report(['date_from' => '2026-07-01', 'date_to' => '2026-12-31'], $userPendidikan + [ReportFilter::BACA_SEMUA_JADWAL => true])['summary']['meeting_count'] === report_service()->report(['date_from' => '2026-07-01', 'date_to' => '2026-12-31'], ['id' => 1, 'roles' => ['admin']])['summary']['meeting_count'], 'Penanda web memberi cakupan baca yang sama dengan admin, tidak lebih');

// ===================================================================== H4-C
echo PHP_EOL . '=== H4-C. Ringkasan, pengajuan, Izin anak ===' . PHP_EOL;
$h = $admin->req('/portal/izin_ringkasan.php')['body'];
$total = (int) $satu('SELECT COUNT(*) FROM izin_pengajuan');
$perlu = (int) $satu("SELECT COUNT(*) FROM izin_pengajuan WHERE status='Perlu Penetapan Admin'");
$assert(str_contains($h, '<p class="ah-stat__value">' . $total . '</p>') && str_contains($h, 'ah-stat--link') && str_contains($h, 'status=Perlu+Penetapan+Admin'), "I-1 kartu angka = DB (total $total) dan menjadi tautan ke Pengajuan");
$assert(str_contains($h, 'Alur izin') && str_contains($h, 'ah-vsteps') && !str_contains($h, 'Data warisan'), 'I-1 kartu Alur izin menggantikan catatan panjang; tanpa label "Data warisan"');
$h = $admin->req('/portal/izin.php')['body'];
$assert(str_contains($h, 'ah-chips') && preg_match('#Perlu penetapan</span> <span class="ah-chips__count">' . number_format($perlu, 0, ',', '.') . '</span>#', $h) === 1, "I-2 chip status berangka = DB ($perlu perlu penetapan)");
preg_match('#<thead>(.*?)</thead>#s', $main($h), $th);
$assert(substr_count($th[1] ?? '', '<th') === 7 && !preg_match('/>\s*V2\s*</', $h) && !str_contains($h, 'Keputusan</th>'), 'I-2 tabel 7 kolom, tanpa label "V2" dan kolom Keputusan');
$r = $admin->req('/portal/izin.php?source=legacy');
$assert(str_contains($r['body'], 'Arsip sistem lama') && str_contains($r['body'], 'ah-filter__more" open'), 'I-2 Asal data di Filter lanjutan: "Arsip sistem lama", terbuka saat aktif');
$r = $admin->req('/portal/izin.php?tertunda=1&status=Diajukan');
$assert(str_contains($r['body'], 'Menunggu ') && str_contains($r['body'], 'ah-badge--warn'), 'I-2 pengajuan menunggu > 1 hari diberi tanda kuning');
$r = $admin->req('/portal/izin.php?status=%3Cscript%3E&q=%3Cscript%3Ealert(1)%3C/script%3E');
$assert($r['status'] === 200 && !str_contains($r['body'], '<script>alert(1)'), 'I-2 parameter tidak sah diabaikan dan isian di-escape');
// I-7
$anakA = array_map(static fn (array $r): int => (int) $r['santri_id'], $sql('SELECT santri_id FROM santri_wali WHERE wali_id=1 AND archived_at IS NULL'));
$santriPertama = (int) $satu('SELECT s.id FROM santri_wali sw JOIN santri s ON s.id=sw.santri_id WHERE sw.wali_id=1 AND sw.archived_at IS NULL ORDER BY s.nama_santri, s.id LIMIT 1');
$aktifDb = (int) $satu("SELECT COUNT(*) FROM izin_pengajuan WHERE santri_id=$santriPertama AND (status IN ('Diajukan','Perlu Penetapan Admin') OR (status='Disetujui' AND tgl_kembali>=CURDATE()))");
$riwayatDb = (int) $satu("SELECT COUNT(*) FROM izin_pengajuan WHERE santri_id=$santriPertama") - $aktifDb;
$hA = $ortu->req('/portal/izin.php?santri_id=' . $santriPertama)['body'];
$hR = $ortu->req('/portal/izin.php?tab=riwayat&santri_id=' . $santriPertama)['body'];
$hitung = static fn (string $html): int => substr_count($html, 'class="ah-icard"');
$assert($hitung($hA) === min(20, $aktifDb) && $hitung($hR) === min(20, $riwayatDb), "I-7 tab Aktif ($aktifDb) dan Riwayat ($riwayatDb) = DB");
$assert(count($anakA) < 2 || str_contains($hA, 'aria-label="Pilih anak"'), 'I-7 pilihan anak tampil bila lebih dari satu anak');
$assert(!str_contains($main($hA), '<form method="post"') && !str_contains($main($hA), 'ah-table'), 'I-7 orang tua tanpa formulir dan tanpa tabel staf');
$santriLain = (int) $satu('SELECT s.id FROM santri s WHERE s.id NOT IN (SELECT santri_id FROM santri_wali WHERE wali_id=1) AND s.id IN (SELECT santri_id FROM izin_pengajuan) LIMIT 1');
$hL = $ortu->req('/portal/izin.php?santri_id=' . $santriLain)['body'];
$assert($hitung($hL) === $hitung($hA) && !str_contains($hL, (string) $satu("SELECT nama_santri FROM santri WHERE id=$santriLain") . '</h1>'), 'I-7 santri_id di luar anak sendiri diabaikan (kembali ke anak sendiri)');
$assert($izinPengurusB > 0 && $ortu->req("/portal/izin_detail.php?id=$izinPengurusB")['status'] === 403, 'Orang tua tidak membuka izin anak lain (403)');

// ===================================================================== H4-D
echo PHP_EOL . '=== H4-D. Antrean murobi dan keputusan N ===' . PHP_EOL;
$h = $murobi->req('/portal/izin_antrean.php')['body'];
$assert(substr_count($h, 'class="ah-qlist') >= 1 && substr_count($h, 'is-late') === 1 && str_contains($h, '&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;'), 'I-3 daftar antrean: satu ditandai tertunda > 1 hari, alasan ber-tag di-escape');
$h = $murobi->req('/portal/izin_antrean.php?id=' . $antrean[1])['body'];
$assert(str_contains($h, 'name="hasil" value="Disetujui"') && str_contains($h, 'name="hasil" value="Ditolak"') && str_contains($h, 'name="kembali" value="antrean"') && str_contains($h, 'Izin semester ini'), 'I-3 detail berdampingan dengan Setujui/Tolak dan jumlah izin semester ini');
$assert(!str_contains($h, '<script>alert("x")'), 'I-3 detail alasan di-escape');
$c = $murobi->csrf('/portal/izin_antrean.php?id=' . $antrean[0]);
preg_match('#name="idempotency_key" value="([^"]+)"#', $murobi->req('/portal/izin_antrean.php?id=' . $antrean[0])['body'], $key);
$versi = (int) $satu('SELECT version FROM izin_pengajuan WHERE id=' . $antrean[0]);
$r = $murobi->req('/portal/izin_aksi.php', ['_csrf' => $c, 'aksi' => 'putuskan', 'kembali' => 'antrean', 'mode' => 'murobi', 'pengajuan_id' => $antrean[0], 'version' => $versi, 'idempotency_key' => $key[1] ?? 'x', 'hasil' => 'Disetujui', 'alasan' => 'Disetujui uji H4-D']);
$assert($r['status'] === 303 && str_contains((string) $r['location'], '/portal/izin_antrean.php?id=' . $antrean[0]), 'Keputusan dari antrean kembali ke antrean dengan detail yang sama (303)');
$hasil = $murobi->follow($r)['body'];
$assert(str_contains($teks($hasil), 'Izin #' . $antrean[0] . ' disetujui') && str_contains($hasil, 'Berikutnya: #') && (string) $satu('SELECT status FROM izin_pengajuan WHERE id=' . $antrean[0]) === 'Disetujui', 'Pesan hasil + tombol "Berikutnya"; tidak pindah otomatis; status tersimpan');
$c = $murobi->csrf('/portal/izin_antrean.php?id=' . $antrean[1]);
preg_match('#name="idempotency_key" value="([^"]+)"#', $murobi->req('/portal/izin_detail.php?id=' . $antrean[1])['body'], $key);
$r = $murobi->req('/portal/izin_aksi.php', ['_csrf' => $c, 'aksi' => 'putuskan', 'kembali' => 'https://contoh.invalid/', 'mode' => 'murobi', 'pengajuan_id' => $antrean[1], 'version' => (int) $satu('SELECT version FROM izin_pengajuan WHERE id=' . $antrean[1]), 'idempotency_key' => $key[1] ?? 'y', 'hasil' => 'Ditolak', 'alasan' => 'Ditolak uji H4-D']);
$assert($r['status'] === 303 && str_contains((string) $r['location'], '/portal/izin_detail.php?id=' . $antrean[1]), 'Nilai "kembali" lain diabaikan: tetap ke detail (tidak ada pengalihan bebas)');
$r = $murobi->req('/portal/izin_aksi.php', ['aksi' => 'putuskan', 'pengajuan_id' => $antrean[2], 'hasil' => 'Disetujui', 'alasan' => 'tanpa csrf']);
$assert($r['status'] === 419 && (string) $satu('SELECT status FROM izin_pengajuan WHERE id=' . $antrean[2]) === 'Diajukan', 'Keputusan tanpa CSRF ditolak 419');
$r = $murobi->req('/portal/izin_antrean.php?id=' . $izinPengurusB);
$assert(str_contains($r['body'], 'ah-note--danger') && !str_contains($r['body'], 'name="hasil"'), 'Antrean ?id= di luar cakupan murobi tidak membuka detail');

// ===================================================================== H4-E
echo PHP_EOL . '=== H4-E. Ajukan izin: info panel dan jumlah semester (M, Q) ===' . PHP_EOL;
$tahun = (int) $satu("SELECT id FROM tahun_ajaran WHERE status='Aktif' AND archived_at IS NULL LIMIT 1");
$binaan = (int) $satu("SELECT s.id FROM pembimbing_assignments pa JOIN plotting_kamar pk ON pk.id_kamar=pa.kamar_id AND pk.id_tahun=pa.tahun_ajaran_id JOIN santri s ON s.id=pk.id_santri WHERE pa.pengurus_id=1 AND pa.is_active=1 AND s.is_active=1 ORDER BY s.id LIMIT 1");
$bukanBinaan = (int) $satu("SELECT s.id FROM santri s WHERE s.is_active=1 AND s.id NOT IN (SELECT pk.id_santri FROM pembimbing_assignments pa JOIN plotting_kamar pk ON pk.id_kamar=pa.kamar_id AND pk.id_tahun=pa.tahun_ajaran_id WHERE pa.pengurus_id=1) AND s.id NOT IN (SELECT pl.id_santri FROM pembimbing_assignments pa JOIN plotting_kelas pl ON pl.id_kelas=pa.kelas_id AND pl.id_tahun=pa.tahun_ajaran_id WHERE pa.pengurus_id=1) LIMIT 1");
$r = $pengurus->req('/portal/izin_buat.php?info=1&mode=pengurus&santri_id=' . $binaan, null, ['Accept: application/json']);
$json = json_decode($r['body'], true);
$dbTotal = (int) $satu("SELECT COUNT(*) FROM izin_pengajuan WHERE santri_id=$binaan AND tahun_ajaran_id=$tahun AND status<>'Dibatalkan'");
$dbSetuju = (int) $satu("SELECT COUNT(*) FROM izin_pengajuan WHERE santri_id=$binaan AND tahun_ajaran_id=$tahun AND status='Disetujui'");
$assert($r['status'] === 200 && ($json['ok'] ?? false) === true && str_starts_with((string) $json['jumlah'], "$dbTotal pengajuan, $dbSetuju disetujui"), "Info panel pengurus: jumlah izin semester ini = DB ($dbTotal/$dbSetuju) (Q)");
$kandidat = izin_router()->candidates($binaan, date('Y-m-d'));
$assert(count($kandidat) === 1 ? $json['murobi'] === $kandidat[0]['nama_guru'] : str_contains((string) $json['murobi'], 'admin'), 'Info panel murobi tujuan = routing yang sama dengan pengajuan (' . $json['murobi'] . ')');
$r = $pengurus->req('/portal/izin_buat.php?info=1&mode=pengurus&santri_id=' . $bukanBinaan);
$assert($r['status'] === 403 && (json_decode($r['body'], true)['ok'] ?? true) === false, 'Info panel santri di luar binaan ditolak 403 (tanpa membocorkan data)');
$assert($ortu->req('/portal/izin_buat.php?info=1&santri_id=' . $santriPertama)['status'] === 403 && $murobi->req('/portal/izin_buat.php?info=1&santri_id=' . $binaan)['status'] === 403, 'Info panel tidak untuk orang tua dan murobi (403)');
$h = $pengurus->req('/portal/izin_buat.php')['body'];
$assert(substr_count($h, 'data-ah-prefix=') === 4 && str_contains($h, 'data-ah-prefix="Pulang: "') && !str_contains($h, 'name="alasan_cepat"') && !str_contains($h, 'name="jenis'), 'Alasan cepat = empat tombol yang mengisi awal teks; tanpa isian/kolom baru (M)');
$assert(str_contains($h, 'data-ah-option-filter="santri_id"') && substr_count($h, '<select class="form-select" id="santri_id"') === 1 && !str_contains($h, 'list-group-item'), 'Satu kotak pilih santri yang dapat dicari (tanpa daftar + dropdown ganda)');
$h = $admin->req('/portal/izin_buat.php')['body'];
$assert(str_contains($h, 'ah-ribbon') && str_contains($h, 'Mode admin.'), 'Mode admin ditandai pita kuning');
$c = $pengurus->csrf('/portal/izin_buat.php');
$sebelum = (int) $satu('SELECT COUNT(*) FROM izin_pengajuan');
$r = $pengurus->req('/portal/izin_aksi.php', ['_csrf' => $c, 'aksi' => 'buat', 'mode' => 'pengurus', 'idempotency_key' => bin2hex(random_bytes(8)), 'santri_id' => $bukanBinaan, 'tgl_izin' => date('Y-m-d'), 'tgl_kembali' => date('Y-m-d'), 'alasan' => 'Pulang: di luar cakupan']);
$assert($r['status'] === 403 && (int) $satu('SELECT COUNT(*) FROM izin_pengajuan') === $sebelum, 'Pengajuan untuk santri di luar binaan tetap ditolak server 403');

// ===================================================================== H4-F
echo PHP_EOL . '=== H4-F. Detail izin ===' . PHP_EOL;
$h = $admin->req("/portal/izin_detail.php?id=$izinPerlu")['body'];
$assert(substr_count($h, 'data-ah-dialog') >= 3 && str_contains($h, 'id="aksi-tetapkan"') && str_contains($h, 'id="aksi-putuskan"') && str_contains($h, 'id="aksi-batalkan"') && !str_contains($h, 'id="aksi-koreksi"'), 'I-5 admin: Tetapkan/Putuskan pengganti/Batalkan sebagai tombol + dialog; koreksi belum ada');
$assert(!str_contains($h, 'Versi data') && str_contains($h, 'Info teknis') && str_contains($h, 'class="ah-tl"'), 'I-5 versi data tersembunyi di ⋯ Info teknis; riwayat sebagai linimasa');
$assert(str_contains($admin->req("/portal/izin_detail.php?id=$izinPerlu&info=teknis")['body'], 'Versi data'), 'I-5 Info teknis tampil untuk admin');
$h = $pengurus->req("/portal/izin_detail.php?id=$izinPengurusA&info=teknis")['body'];
$assert(!str_contains($h, 'Versi data') && !str_contains($h, 'id="aksi-'), 'I-5 pengurus: tanpa info teknis; izin yang sudah diputus tanpa tindakan');
$h = $ortu->req("/portal/izin_detail.php?id=" . (int) $satu("SELECT id FROM izin_pengajuan WHERE santri_id=$santriPertama ORDER BY id DESC LIMIT 1"))['body'];
$assert(!str_contains($main($h), '<form method="post"') && str_contains($h, 'hanya-baca') && !str_contains($h, 'Penerusan'), 'I-5 tampilan orang tua hanya-baca, tanpa catatan teknis routing');
$h = $admin->req("/portal/izin_detail.php?id=$izinLegacy")['body'];
$assert($izinLegacy === 0 || (str_contains($h, 'Arsip sistem lama') && !str_contains($h, 'id="aksi-')), 'I-5 arsip sistem lama tanpa tindakan');
$r = $admin->req('/portal/izin_detail.php?id=0');
$assert($r['status'] === 404 && !str_contains($r['body'], 'Rincian'), 'I-5 nomor pengajuan tidak sah → 404 (tidak lagi membuka pengajuan pertama)');
$assert($pengurus->req("/portal/izin_detail.php?id=$izinPengurusB")['status'] === 403, 'I-5 pengurus tidak membuka pengajuan pengurus lain (403)');
$h = $admin->req("/portal/izin_detail.php?id=$izinPengurusA")['body'];
$assert(str_contains($h, 'Mengajukan izin') || str_contains($h, 'Memutuskan izin') || str_contains($h, 'Belum ada riwayat'), 'I-5 peristiwa riwayat ditulis manusiawi');

// ===================================================================== H4-G
echo PHP_EOL . '=== H4-G. Laporan perizinan ===' . PHP_EOL;
$input = ['periode' => 'semester'];
$semester = izin_repository()->activeYear();
[$masukan, $kode] = App\Ui\Periode::terapkan($input, $semester);
$userAdmin = ['id' => (int) $satu("SELECT id FROM users WHERE username='sbx_admin'"), 'roles' => ['admin'], 'guru_id' => null, 'name' => 'SBX Administrator'];
$lap = izin_report_service()->report($userAdmin, $masukan, 'admin');
$grafik = izin_report_service()->charts($userAdmin, $masukan, 'admin');
$assert(array_sum($grafik['per_pekan']) === $lap['ringkasan']['total'] && array_sum($grafik['per_kelas']) === $lap['ringkasan']['total'], 'I-6 jumlah batang per pekan dan per kelas = total ringkasan (filter sama, ' . $lap['ringkasan']['total'] . ')');
$senin = array_filter(array_keys($grafik['per_pekan']), static fn (string $t): bool => $t !== '' && date('N', strtotime($t)) !== '1');
$assert($senin === [], 'I-6 pekan dimulai hari Senin (T)');
$assert($kode === 'semester' && $masukan['date_from'] === (str_contains((string) $semester['semester'], 'Genap') ? substr((string) $semester['tahun'], 5, 4) . '-01-01' : substr((string) $semester['tahun'], 0, 4) . '-07-01'), 'Periode "Semester ini" mengikuti semester aktif (' . $masukan['date_from'] . ' s.d. ' . $masukan['date_to'] . ')');
[$m2] = App\Ui\Periode::terapkan(['periode' => 'pekan'], $semester, 'bulan', new DateTimeImmutable('2026-10-25'));
$assert($m2['date_from'] === '2026-10-19' && $m2['date_to'] === '2026-10-25', 'Periode "Pekan ini" Senin 19 – Ahad 25 Okt 2026');
[$m3, $k3] = App\Ui\Periode::terapkan(['date_from' => '2026-08-01', 'date_to' => '2026-08-31'], $semester);
$assert($k3 === 'rentang' && $m3['date_from'] === '2026-08-01', 'Alamat lama bertanggal tanpa periode tetap memakai tanggalnya');
$h = $admin->req('/portal/laporan.php?periode=semester')['body'];
$assert(str_contains($h, 'Pengajuan per pekan') && str_contains($h, 'Pengajuan per kelas') && !str_contains($h, 'Alasan izin') && str_contains($h, 'date_from=' . $masukan['date_from']), 'I-6 grafik per pekan & per kelas (tanpa grafik alasan); tautan cetak/CSV membawa tanggal hasil periode');
$utama = substr($h, (int) strpos($h, 'ah-filter__form'), (int) strpos($h, 'ah-filter__more') - (int) strpos($h, 'ah-filter__form'));
$assert(substr_count($utama, '<select class="form-select"') === 4 && str_contains($utama, 'id="periode"') && str_contains($h, 'Filter lanjutan'), 'I-6 empat saringan utama (Periode, Status, Kelas, Kamar) + Filter lanjutan');
$assert(str_contains($h, strtoupper(substr((string) $lap['kriteria'], 0, 10))), 'I-6 kode laporan = sidik kriteria');
$r = $pengurus->req('/portal/laporan.php?pengurus_id=2');
$assert($r['status'] === 403, 'I-6 pengurus memperluas cakupan lewat parameter tetap 403');

// ===================================================================== H4-H
echo PHP_EOL . '=== H4-H. Pengajian: jadwal, pertemuan, kehadiran ===' . PHP_EOL;
$h = $admin->req('/admin/admin_pengajian.php')['body'];
preg_match('#<table class="ah-week">.*?<thead><tr>(.*?)</tr>#s', $h, $kepala);
preg_match_all('#<th scope="col"[^>]*>([^<]+)</th>#', $kepala[1] ?? '', $hari);
$assert(($hari[1] ?? []) === ['Waktu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Ahad'], 'J-1 kisi mingguan Senin…Ahad, "Minggu" ditulis "Ahad" (T)');
$jumlahJadwal = (int) $satu("SELECT COUNT(*) FROM jadwal_ngaji j JOIN tahun_ajaran t ON t.id=j.id_tahun WHERE t.status='Aktif' AND j.archived_at IS NULL AND j.hari IS NOT NULL AND j.waktu_mulai IS NOT NULL");
$assert(substr_count($h, 'class="ah-block') === $jumlahJadwal * 2, "J-1 setiap jadwal semester aktif tampil di kisi dan tampilan hari ($jumlahJadwal)");
$assert(!preg_match('/\bMinggu\b/', $teks($main($h))) && str_contains($h, 'class="ah-daypick"'), 'J-1 tanpa label "Minggu"; pilih hari untuk HP');
$h = $admin->req('/admin/admin_pengajian.php?tampilan=daftar')['body'];
$assert(str_contains($h, 'ah-table--cards') && str_contains($h, 'data-confirm-title="Arsipkan') && !str_contains($h, 'onsubmit="return confirm'), 'J-1 daftar dengan ⋯ dan dialog bersama, tanpa confirm() bawaan');
$h = $admin->req('/admin/admin_pengajian.php?tab=jadwal&action=create')['body'];
$assert(str_contains($h, 'class="ah-panel"') && str_contains($h, '<option value="Minggu"') && str_contains($h, '>Ahad</option>'), 'J-1 tambah jadwal di panel samping; nilai "Minggu" tetap tersimpan, label "Ahad"');
$hGuru = $murobiA->req('/admin/admin_pengajian.php')['body'];
$milikLain = (string) $satu("SELECT g.nama_guru FROM jadwal_ngaji j JOIN guru g ON g.id=j.id_guru WHERE j.fan_ilmu='Akhlak' AND j.nama_kitab='Kitab SBX' LIMIT 1");
$assert(str_contains($hGuru, 'class="ah-block') && !str_contains($hGuru, $milikLain) && !str_contains($hGuru, 'Tambah jadwal'), 'J-1 guru hanya melihat jadwal sendiri, tanpa Tambah jadwal');
$r = $murobiA->req('/admin/admin_pengajian.php?teacher_id=' . $guruLainId);
$assert(!str_contains($r['body'], $milikLain), 'J-1 guru tidak dapat melihat jadwal guru lain lewat parameter');
$r = $murobiA->req('/admin/admin_pengajian.php', ['_csrf' => $murobiA->csrf('/admin/admin_pengajian.php'), 'tab' => 'jadwal', 'action' => 'deactivate', 'id' => '1']);
$assert($r['status'] === 403, 'J-1 POST jadwal oleh guru ditolak 403');
$h = $admin->req('/admin/admin_pengajian.php?tab=pertemuan')['body'];
preg_match('#<thead>(.*?)</thead>#s', $main($h), $th);
$assert(substr_count($th[1] ?? '', '<th') === 6 && !str_contains($h, 'name="schedule_id"'), 'J-3 daftar pertemuan 6 kolom, terpisah dari formulir buka');
$h = $admin->req('/admin/admin_pengajian.php?tab=pertemuan&action=create')['body'];
$assert(str_contains($h, 'class="ah-panel"') && str_contains($h, 'value="draft"') && str_contains($h, 'value="open"'), 'J-3 Buka pertemuan di panel samping (draf / buka & bekukan)');
$h = $admin->req("/admin/admin_pengajian.php?tab=pertemuan&id=$pertemuan")['body'];
$m = $main($h);
$peserta = (int) $satu("SELECT COUNT(*) FROM pertemuan_peserta WHERE pertemuan_id=$pertemuan");
$izinS = izin_service()->approvedCovering(array_map(static fn (array $r): int => (int) $r['santri_id'], $sql("SELECT santri_id FROM pertemuan_peserta WHERE pertemuan_id=$pertemuan")), (string) $satu("SELECT tanggal_pertemuan FROM pertemuan_pengajian WHERE id=$pertemuan"));
$assert(substr_count($m, 'data-ah-filter-text=') === $peserta && substr_count($m, 'Sedang izin #') === count($izinS) && count($izinS) >= 1, "J-2 $peserta peserta; label \"Sedang izin\" = izin disetujui pada tanggalnya (" . count($izinS) . ') (S)');
$hadirDb = (int) $satu("SELECT COUNT(*) FROM absensi_santri WHERE pertemuan_id=$pertemuan AND status='Hadir'");
$assert(str_contains($m, '<b>' . $hadirDb . ' hadir</b>') && str_contains($m, 'belum dicatat'), "J-2 ringkasan kehadiran dari aplikasi = DB ($hadirDb hadir), sisanya belum dicatat");
$assert(!preg_match('#<(select|input)[^>]+name="(status|kehadiran)#', $m) && str_contains($m, 'value="complete"') && str_contains($m, 'Kehadiran dicatat guru lewat aplikasi'), 'J-2 tanpa isian kehadiran (R); hanya Selesaikan pertemuan');
$status = (string) $satu("SELECT status FROM absensi_santri WHERE pertemuan_id=$pertemuan AND santri_id=" . (int) array_key_first($izinS));
$assert($status === 'Izin', 'Label tidak mengisi status: status santri yang izin tetap seperti dicatat aplikasi');
$assert($guru->req("/admin/admin_pengajian.php?tab=pertemuan&id=$pertemuan")['status'] === 403, 'J-2 guru lain tidak membuka pertemuan ini (403)');
$c = $admin->csrf("/admin/admin_pengajian.php?tab=pertemuan&id=$pertemuan");
$r = $admin->req('/admin/admin_pengajian.php?tab=pertemuan', ['_csrf' => $c, 'tab' => 'pertemuan', 'action' => 'complete', 'meeting_id' => $pertemuan]);
$assert(in_array($r['status'], [302, 303], true) && (string) $satu("SELECT status FROM pertemuan_pengajian WHERE id=$pertemuan") === 'Selesai' && str_contains($admin->follow($r)['body'], 'aria-current="step">Selesai'), 'J-2 Selesaikan pertemuan seperti sebelumnya; langkah berpindah ke Selesai');
$h = $admin->req("/admin/laporan_absensi_detail.php?id=$pertemuan")['body'];
$assert(str_contains($h, 'Sedang izin #') && str_contains($h, 'fa-clock') && !str_contains($main($h), '<form method="post"'), 'J-5 detail pertemuan: label izin, waktu perubahan, tanpa formulir');
$h = $admin->req('/admin/admin_laporan_absensi.php?periode=semester')['body'];
$assert(str_contains($h, 'Komposisi kehadiran per jadwal') && str_contains($h, 'class="ah-seg') && str_contains($h, 'Rincian baris kehadiran'), 'J-4 segmen Santri/Guru/Gabungan, bilah komposisi, rincian terlipat');
preg_match('#export_laporan_absensi\.php\?([^"]+)"#', $h, $ex);
parse_str(html_entity_decode($ex[1] ?? ''), $exQ);
$assert(($exQ['date_from'] ?? '') === $masukan['date_from'] && ($exQ['subject_scope'] ?? '') === 'santri', 'J-4 CSV memakai tanggal hasil periode dan penyajian yang sama');

// ===================================================================== H4-I
echo PHP_EOL . '=== H4-I. Templat cetak (X-1, AS) ===' . PHP_EOL;
$r = $admin->req('/portal/laporan_cetak.php?' . http_build_query(['date_from' => $masukan['date_from'], 'date_to' => $masukan['date_to'], 'mode' => 'admin']));
$lembar = explode('<section class="lembar">', $r['body']);
array_shift($lembar);
$assert($r['status'] === 200 && substr_count($r['body'], 'class="ttd"') === 1 && str_contains((string) end($lembar), 'class="ttd"') && str_contains($r['body'], 'Bagian Pendidikan'), 'Cetak perizinan: satu tanda tangan Bagian Pendidikan di lembar terakhir');
$assert(str_contains($r['body'], 'ttd-garis') && !preg_match('#class="ttd-garis">[^<]+<#', $r['body']), 'Garis nama kosong untuk diisi manual');
$assert(substr_count($r['body'], 'Kode laporan ' . strtoupper(substr((string) $lap['kriteria'], 0, 10))) === count($lembar), 'Kode laporan di kaki setiap lembar');
$assert(str_contains($r['body'], '<header class="identity"><h1>Pesantren Al Hasan</h1>'), 'Kop identitas "Pesantren Al Hasan" sama');
$r = $admin->req('/admin/laporan_absensi_cetak.php?date_from=2026-07-01&date_to=2026-12-31');
$assert(substr_count($r['body'], 'class="ttd"') === 1 && str_contains($r['body'], 'Kode laporan '), 'Cetak kehadiran: tanda tangan Bagian Pendidikan + kode laporan');
$h = IzinPrintRenderer::render(izin_report_service()->document($userAdmin, $masukan, 'admin'));
$assert(substr_count($h, 'class="ttd"') === 1, 'Renderer perizinan (juga dipakai aplikasi/API) memuat tanda tangan');
$h = PrintRenderer::report(['items' => [], 'summary' => ['statuses' => []], 'filters' => []]);
$assert(substr_count($h, 'class="ttd"') === 1 && substr_count($h, '<section class="lembar">') === 1, 'Laporan kosong tetap satu lembar bertanda tangan');
$kasus = (int) $satu('SELECT id FROM v3_konseling_kasus ORDER BY id LIMIT 1');
if ($kasus > 0) {
    $r = $admin->req('/portal/v3_konseling_cetak.php?id=' . $kasus);
    $assert($r['status'] !== 200 || str_contains($r['body'], '<header class="identity"><h1>Pesantren Al Hasan</h1>'), 'Cetak konseling memakai kop yang sama');
}

// ===================================================================== H4-J
echo PHP_EOL . '=== H4-J. Keadaan kosong ===' . PHP_EOL;
$cekKosong = (new Klien('http://127.0.0.1:' . $portKosong))->req('/portal/index.php');
if (!str_contains($cekKosong['body'], 'name="_csrf"')) {
    echo 'Server kosong menjawab ' . $cekKosong['status'] . ': ' . substr($teks($cekKosong['body']), 0, 300) . PHP_EOL . substr((string) file_get_contents($logs[$portKosong]), -800) . PHP_EOL;
}
$kosongAdmin = $klien('sbx_admin', 'http://127.0.0.1:' . $portKosong);
foreach ([
    '/portal/izin_ringkasan.php' => 'Belum ada pengajuan', '/portal/izin.php' => 'Tidak ada pengajuan yang cocok',
    '/portal/izin_antrean.php' => 'Antrean kosong', '/portal/laporan.php' => 'Tidak ada pengajuan sesuai saringan',
    '/admin/admin_pengajian.php?tab=pertemuan' => 'Belum ada pertemuan', '/admin/admin_laporan_absensi.php' => 'Tidak ada data sesuai saringan',
] as $path => $pesan) {
    $r = $kosongAdmin->req($path);
    $assert($r['status'] === 200 && str_contains($teks($r['body']), $pesan), "Kosong $path: \"$pesan\"");
}
$r = $kosongAdmin->req('/portal/laporan.php');
$assert(str_contains($teks($r['body']), 'Tidak ada pengajuan pada periode ini.'), 'Grafik laporan kosong punya keadaan kosong');
$kosongOrtu = $klien('sbx_ortu_a', 'http://127.0.0.1:' . $portKosong);
$assert(str_contains($teks($kosongOrtu->req('/portal/izin.php')['body']), 'Tidak ada izin aktif'), 'Kosong I-7: "Tidak ada izin aktif"');

// ===================================================================== H4-K
echo PHP_EOL . '=== H4-K. Log server ===' . PHP_EOL;
foreach ($logs as $p => $log) {
    $isi = (string) file_get_contents($log);
    preg_match_all('/PHP (Warning|Notice|Deprecated|Fatal error|Parse error)[^\n]*/', $isi, $m);
    $assert($m[0] === [], "Server $p tanpa galat/peringatan PHP" . ($m[0] === [] ? '' : ': ' . implode(' | ', array_slice(array_unique($m[0]), 0, 3))));
}

echo PHP_EOL . 'Ringkasan: ' . $lulus . ' lulus, ' . count($gagal) . ' gagal' . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
