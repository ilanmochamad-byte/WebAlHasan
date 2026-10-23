<?php

declare(strict_types=1);

/**
 * Uji server redesain website Tahap 3 — halaman lama ke kerangka baru.
 *
 * Kriteria selesai Tahap 3 (cetak biru): tidak ada lagi sidebar.php dan
 * alert(); daftar berhalaman; WhatsApp tidak dapat dinyalakan. Skrip ini
 * menjalankan server PHP lokal sendiri di atas DB uji dari
 * `tests/redesain_web_tahap3_db.sh` (+ salinan kosong) dan memeriksa:
 *
 *   H3-A  akses: setiap halaman Tahap 3 hanya untuk admin (403 peran lain,
 *         anonim ke halaman masuk), di dalam kerangka bersama dengan menu/tab
 *         yang benar, tanpa sidebar lama;
 *   H3-B  N-2 kanal notifikasi: tab, WhatsApp Ditangguhkan dan tidak dapat
 *         dinyalakan/diuji/dijalankan (panel dan layanan), Push mati dengan
 *         konfirmasi, pengiriman gagal + riwayat berhalaman, coba ulang JSON;
 *   H3-C  N-4 ringkasan administrasi per tema, angka = basis data;
 *   H3-D  N-5 data sistem lama: pencarian, tahun, baca-saja, escape;
 *   H3-E  K-5 pendaftar: chip = basis data, 20 baris/halaman, saringan,
 *         panel, keputusan lewat pesan (bukan alert), tambah + validasi,
 *         impor diperiksa tanpa menyimpan lalu disimpan, token sekali pakai,
 *         ekspor .xlsx sesuai saringan;
 *   H3-F  K-6 pembayaran, K-7 rekap keuangan, K-8 rekap jumlah santri;
 *   H3-G  W-1 s.d. W-4 konten website: berhalaman, cari, tulis/ubah berita
 *         dengan validasi dan pemeriksaan gambar, galeri banyak foto, unduhan,
 *         jenis berkas berbahaya ditolak;
 *   H3-H  keadaan kosong (DB kosong);
 *   H3-I  tidak ada galat/peringatan PHP di log server.
 *
 *   bash tests/redesain_web_tahap3_db.sh
 *   php tests/redesain_web_tahap3_http.php
 */

$root = dirname(__DIR__);
$db = getenv('TAHAP3_DB') ?: 'test_redesain_t3_test';
$dbKosong = getenv('TAHAP3_DB_KOSONG') ?: 'test_redesain_t3_kosong_test';
$port = (int) (getenv('TAHAP3_PORT') ?: 8952);
$portKosong = $port + 1;
if (!preg_match('/^test_.*_test$/', $db) || !preg_match('/^test_.*_test$/', $dbKosong)) {
    fwrite(STDERR, "Ditolak: DB uji harus bernama test_*_test.\n");
    exit(2);
}
putenv('DB_NAME=' . $db);
require_once $root . '/app/bootstrap.php';
require_once $root . '/admin/SimpleXLSXGen.php';

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
    $log = tempnam(sys_get_temp_dir(), 'tahap3-server-');
    $logs[$p] = $log;
    $servers[] = proc_open(['php', '-S', '127.0.0.1:' . $p, $root . '/tests/redesain_web_tahap1_router.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root, array_merge(getenv(), ['DB_NAME' => $nama]));
}
$berkasSementara = [];
register_shutdown_function(static function () use (&$servers, &$berkasSementara): void {
    foreach ($servers as $server) {
        if (is_resource($server)) {
            proc_terminate($server);
        }
    }
    foreach ($berkasSementara as $berkas) {
        if (is_file($berkas)) {
            unlink($berkas);
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
        $this->jar = tempnam(sys_get_temp_dir(), 'tahap3-jar-');
    }

    /**
     * @param array<string, mixed>|null $post nilai CURLFile untuk unggahan
     * @return array{status:int, body:string, location:?string, type:string}
     */
    public function req(string $path, ?array $post = null, array $headers = []): array
    {
        $h = curl_init($this->base . $path);
        curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $headers]);
        if ($post !== null) {
            $multipart = false;
            array_walk_recursive($post, static function ($v) use (&$multipart): void { $multipart = $multipart || $v instanceof CURLFile; });
            curl_setopt($h, CURLOPT_POST, true);
            curl_setopt($h, CURLOPT_POSTFIELDS, $multipart ? $post : http_build_query($post));
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

    /** Ikuti satu pengalihan dan kembalikan halaman tujuannya. */
    public function follow(array $r): array
    {
        $loc = (string) $r['location'];
        if ($loc === '') {
            return $r;
        }
        if (!str_starts_with($loc, '/') && !str_starts_with($loc, 'http')) {
            $loc = '/admin/' . $loc;
        }

        return $this->req(preg_replace('#^https?://[^/]+#', '', $loc));
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
$barisTabel = static fn (string $html, string $id): int => preg_match('#<table[^>]*id="' . $id . '".*?<tbody>(.*?)</tbody>#s', $html, $m) ? substr_count($m[1], '<tr') : -1;
$xlsx = static function (array $baris) use (&$berkasSementara): string {
    $path = tempnam(sys_get_temp_dir(), 'tahap3-') . '.xlsx';
    $berkasSementara[] = $path;
    \Shuchkin\SimpleXLSXGen::fromArray($baris)->saveAs($path);

    return $path;
};
$png = static function () use (&$berkasSementara): string {
    $path = tempnam(sys_get_temp_dir(), 'tahap3-') . '.png';
    $berkasSementara[] = $path;
    file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));

    return $path;
};

$admin = $klien('sbx_admin');
$adminId = (int) $satu("SELECT id FROM users WHERE username='sbx_admin'");

// ===================================================================== H3-A
echo PHP_EOL . '=== H3-A. Akses, kerangka, dan menu ===' . PHP_EOL;
$layar = [
    '/admin/admin_notifikasi.php' => ['Kanal notifikasi', null], '/admin/admin_dashboard.php' => ['Beranda', 'Ringkasan administrasi'],
    '/admin/admin_pelanggaran.php' => ['Data sistem lama', null], '/admin/admin_data.php' => ['Pendaftar', null],
    '/admin/admin_pembayaran_psb.php' => ['Pembayaran', null], '/admin/admin_rekap_keuangan.php' => ['Rekap keuangan', null],
    '/admin/admin_rekap_santri.php' => ['Data santri', 'Rekap jumlah'], '/admin/admin_berita.php' => ['Konten website', 'Berita'],
    '/admin/tambah_berita.php' => ['Konten website', 'Berita'], '/admin/admin_galeri.php' => ['Konten website', 'Galeri'],
    '/admin/admin_download.php' => ['Konten website', 'Unduhan'],
];
$pengurus = $klien('sbx_pengurus_a');
$guru = $klien('sbx_guru_biasa');
$anonim = new Klien($base);
foreach ($layar as $path => [$menu, $tab]) {
    $r = $admin->req($path);
    $assert($r['status'] === 200 && str_contains($r['body'], 'id="ah-shell"') && substr_count($r['body'], '<h1') === 1
        && !str_contains($r['body'], 'ah-legacy-sidebar') && !str_contains($r['body'], 'id="sidebarMenu"'), "$path: 200 di kerangka bersama, satu h1, tanpa sidebar lama");
    $assert($aktif($r['body']) === $menu && ($tab === null || $tabModul($r['body']) === $tab), "$path: menu aktif $menu" . ($tab ? " › tab $tab" : ''));
    $assert(!preg_match('/\balert\s*\(/', $r['body']) && !str_contains($r['body'], 'dataTables'), "$path: tanpa alert() dan DataTables");
    $assert($pengurus->req($path)['status'] === 403 && $guru->req($path)['status'] === 403, "$path: pengurus dan guru ditolak 403");
    $x = $anonim->req($path);
    $assert($x['status'] === 302 && str_contains((string) $x['location'], 'pesan=sesi'), "$path: tanpa sesi ke halaman masuk");
}
foreach (['/admin/export_psb.php', '/admin/export_pembayaran.php', '/admin/admin_rekap_keuangan.php?unduh=1', '/admin/admin_rekap_santri.php?unduh=1'] as $path) {
    $assert($pengurus->req($path)['status'] === 403, "$path: ekspor ditolak untuk pengurus");
}

// ===================================================================== H3-B
echo PHP_EOL . '=== H3-B. N-2 Kanal notifikasi ===' . PHP_EOL;
$settings = new \App\Notification\SettingsRepository(app_db());
$awal = $settings->current();
$r = $admin->req('/admin/admin_notifikasi.php');
$b = $r['body'];
$assert(str_contains($b, '>Kanal<') && str_contains($b, 'Pengiriman gagal') && str_contains($b, '>Riwayat<'), 'N-2 tab Kanal · Pengiriman gagal · Riwayat');
preg_match('#<section class="ah-channel ah-channel--paused"[^>]*kanal-whatsapp.*?</section>#s', $b, $wa);
$wa = $wa[0] ?? '';
$assert($wa !== '' && str_contains($wa, 'Ditangguhkan') && !str_contains($wa, 'Nyalakan kanal') && !str_contains($wa, 'Kirim pesan uji')
    && !str_contains($wa, 'Jalankan worker') && !str_contains($wa, 'Uji coba (tanpa kirim)') && str_contains($wa, 'Periksa konfigurasi'), 'AL kartu WhatsApp Ditangguhkan: tanpa Nyalakan, Kirim uji, worker; Periksa konfigurasi di ⋯');
$assert(str_contains($wa, 'WHATSAPP_API_TOKEN') && !preg_match('/WHATSAPP_API_TOKEN\s*[=:]\s*\S/', $b), 'N-2 hanya NAMA environment yang ditampilkan');
$assert(str_contains($b, 'Selalu aktif') && str_contains($b, 'Kirim pesan uji'), 'N-2 kanal dalam aplikasi selalu aktif dengan satu tombol utama');
$csrf = $admin->csrf('/admin/admin_notifikasi.php');
foreach ([['aksi' => 'sakelar', 'kanal' => 'WhatsApp', 'aktif' => '1'], ['aksi' => 'pesan_uji', 'kanal' => 'WhatsApp'], ['aksi' => 'worker', 'kanal' => 'WhatsApp']] as $post) {
    $sebelum = $sql('SELECT COUNT(*) n FROM notifikasi_outbox')[0]['n'];
    $r = $admin->req('/admin/admin_notifikasi.php', ['_csrf' => $csrf] + $post);
    $hal = $admin->follow($r);
    $assert($r['status'] === 302 && str_contains($teks($hal['body']), 'ditangguhkan') && $settings->current()['whatsapp_enabled'] === false
        && $sql('SELECT COUNT(*) n FROM notifikasi_outbox')[0]['n'] === $sebelum, 'AL panel menolak ' . $post['aksi'] . ' WhatsApp; kanal tetap mati, tidak ada pesan');
}
// Layanan menolak walau pemeriksaan konfigurasi berstatus Lulus (API memakai layanan yang sama).
$settings->recordWhatsappCheck('Lulus', 'Sintetis Tahap 3', (string) $awal['whatsapp_provider'], $adminId);
$userAdmin = auth_repository()->findActiveById($adminId) ?? [];
try {
    notification_admin_service()->ubahSakelar($userAdmin, 'WhatsApp', true, ['ip' => null, 'user_agent' => null]);
    $assert(false, 'AL layanan menolak menyalakan WhatsApp walau pemeriksaan Lulus');
} catch (\App\Notification\NotificationException $e) {
    $assert($e->status() === 409 && $settings->current()['whatsapp_enabled'] === false, 'AL layanan menolak menyalakan WhatsApp walau pemeriksaan Lulus (409)');
}
$settings->recordWhatsappCheck((string) $awal['whatsapp_check_status'], (string) ($awal['whatsapp_check_pesan'] ?? 'Dipulihkan'), $awal['whatsapp_provider'], $adminId);
$assert(str_contains($b, 'data-confirm-title="Matikan ') || $settings->current()['push_enabled'] === false, 'N-2 Matikan Push memakai dialog konfirmasi (bila Push aktif)');
$gagalTotal = (int) $satu("SELECT COUNT(*) FROM notifikasi_outbox WHERE status='Failed'");
$r = $admin->req('/admin/admin_notifikasi.php?tab=gagal');
$assert(str_contains($r['body'], 'id="pengiriman-gagal"') && $barisTabel(str_replace('<table class="ah-table ah-table--cards">', '<table class="ah-table ah-table--cards" id="gagal">', $r['body']), 'gagal') === min(20, $gagalTotal)
    && str_contains($r['body'], 'total ' . $gagalTotal . ' data'), "N-2 pengiriman gagal 20 baris/halaman dari $gagalTotal");
$assert(str_contains($r['body'], 'data-ah-bulk=') && str_contains($r['body'], 'name="outbox_ids[]"') && str_contains($r['body'], '>Coba ulang<'), 'N-2 coba ulang per baris dan massal');
$r2 = $admin->req('/admin/admin_notifikasi.php?tab=gagal&kanal=WhatsApp');
$assert(str_contains($r2['body'], 'total ' . (int) $satu("SELECT COUNT(*) FROM notifikasi_outbox WHERE status='Failed' AND kanal='WhatsApp'") . ' data') || (int) $satu("SELECT COUNT(*) FROM notifikasi_outbox WHERE status='Failed' AND kanal='WhatsApp'") <= 20, 'N-2 saringan kanal pengiriman gagal');
$satuGagal = (int) $satu("SELECT id FROM notifikasi_outbox WHERE status='Failed' AND kanal='WhatsApp' ORDER BY id DESC LIMIT 1");
$j = $admin->req('/admin/admin_notifikasi.php', ['_csrf' => $csrf, 'aksi' => 'coba_ulang_massal', 'kembali' => 'gagal', 'outbox_id' => (string) $satuGagal], ['Accept: application/json']);
$json = json_decode($j['body'], true);
$assert(is_array($json) && ($json['ok'] ?? null) === false && str_contains((string) $json['message'], 'ditangguhkan') && $satu("SELECT status FROM notifikasi_outbox WHERE id=$satuGagal") === 'Failed',
    'N-2 proses massal (JSON) menolak coba ulang WhatsApp Ditangguhkan dengan alasan, baris tetap Failed');
$assert(preg_match('#<tr>(?:(?!</tr>).)*<td>WhatsApp</td>(?:(?!</tr>).)*>Ditangguhkan<#s', $r['body']) === 1 && !preg_match('#<tr>(?:(?!</tr>).)*WhatsApp(?:(?!</tr>).)*name="outbox_ids\[\]"#s', $r['body']) && !preg_match('#<tr>(?:(?!</tr>).)*WhatsApp(?:(?!</tr>).)*>Coba ulang<#s', $r['body']),
    'F-1 baris WhatsApp di Pengiriman gagal tanpa kotak pilih dan tanpa Coba ulang');

// Koreksi audit F-1: sakelar WhatsApp LAMA yang masih menyala (dari sebelum
// keputusan AL) tidak boleh dipakai worker, percobaan ulang, maupun cron.
$waLama = (int) $satu("SELECT id FROM notifikasi_outbox WHERE status='Failed' AND kanal='WhatsApp' ORDER BY id LIMIT 1");
$db = app_db();
$db->begin_transaction();
try {
    $settings->recordWhatsappCheck('Lulus', 'Sintetis F-1', 'fake', $adminId);
    $settings->setWhatsappEnabled(true, $adminId, 'fake');
    $db->query("UPDATE notifikasi_outbox SET status='Queued', tersedia_pada=NOW(), gagal_permanen=0 WHERE kanal='WhatsApp' AND status='Failed' AND id<>$waLama");
    $antre = (int) $satu("SELECT COUNT(*) FROM notifikasi_outbox WHERE kanal='WhatsApp' AND status='Queued'");
    $palsu = new \App\Notification\WhatsApp\FakeProvider('ok', false);
    $dispatcher = new \App\Notification\NotificationDispatcher($db, notification_outbox_repository(), push_device_repository(), push_token_protector(),
        new \App\Notification\Push\ExpoPushClient('', 5), $palsu, $settings, new \App\Notification\WorkerLock($db));
    $cron = $dispatcher->run('WhatsApp', 50);
    $assert($cron['dijalankan'] === false && str_contains((string) $cron['alasan'], 'ditangguhkan') && count($palsu->terkirim()) === 0
        && (int) $satu("SELECT COUNT(*) FROM notifikasi_outbox WHERE kanal='WhatsApp' AND status='Queued'") === $antre, "F-1 dispatcher/cron: sakelar lama aktif, $antre antrean, nol send, antrean utuh");
    $uji = $dispatcher->run('WhatsApp', 50, true);
    $assert(count($palsu->terkirim()) === 0 && (int) $satu("SELECT COUNT(*) FROM notifikasi_outbox WHERE kanal='WhatsApp' AND status='Queued'") === $antre, 'F-1 uji coba tanpa kirim tetap tersedia dan tidak mengubah antrean');
    $kode = static function (callable $f): int { try { $f(); return 0; } catch (\App\Notification\NotificationException $e) { return $e->status(); } };
    $assert($kode(static fn () => notification_admin_service()->jalankanWorker($userAdmin, 'WhatsApp', false)) === 409, 'F-1 layanan (panel/API) menolak worker WhatsApp yang mengirim (409)');
    $sebelum = $sql("SELECT status, percobaan FROM notifikasi_outbox WHERE id=$waLama");
    $assert($kode(static fn () => notification_admin_service()->cobaUlang($userAdmin, $waLama, ['ip' => null, 'user_agent' => null])) === 409
        && $sql("SELECT status, percobaan FROM notifikasi_outbox WHERE id=$waLama") === $sebelum, 'F-1 percobaan ulang WhatsApp ditolak 409; status dan percobaan tetap');
    $assert(notification_admin_service()->jalankanWorker($userAdmin, 'Push', true)['kanal'] === 'Push', 'F-1 regresi: worker Push uji coba tetap berjalan lewat layanan');
} finally {
    $db->rollback();
}
$assert($settings->current()['whatsapp_enabled'] === false, 'F-1 fixture sakelar lama dipulihkan (rollback)');
$r = $admin->req('/admin/admin_notifikasi.php?tab=riwayat');
$auditTotal = (int) $satu('SELECT COUNT(*) FROM notifikasi_pengaturan_audit');
$assert(str_contains($r['body'], 'Audit perubahan kanal') && str_contains($r['body'], 'total ' . $auditTotal . ' data'), "N-2 riwayat berhalaman ($auditTotal catatan)");
$assert($admin->req('/admin/admin_notifikasi.php?tab=riwayat&page=2')['status'] === 200, 'N-2 riwayat halaman 2 terbuka');

// ===================================================================== H3-C
echo PHP_EOL . '=== H3-C. N-4 Ringkasan administrasi ===' . PHP_EOL;
$r = $admin->req('/admin/admin_dashboard.php');
$t = $teks($r['body']);
$assert(str_contains($t, 'Total pendaftar ' . number_format((int) $satu('SELECT COUNT(*) FROM psb_pendaftar'), 0, ',', '.')) && str_contains($t, 'Pendaftar per unit') && str_contains($t, 'Status pendaftaran'), 'N-4 tema PSB: total pendaftar = basis data, dua grafik');
$assert(str_contains($t, 'Sekolah mitra') && str_contains($t, 'Dimigrasi ' . (int) $satu("SELECT COUNT(*) FROM psb_pendaftar WHERE status='Dimigrasi'")), 'N-4 unit dikelompokkan, status lengkap termasuk Dimigrasi');
$r = $admin->req('/admin/admin_dashboard.php?tema=santri');
$t = $teks($r['body']);
$assert(str_contains($t, 'Santri aktif ' . number_format((int) $satu('SELECT COUNT(*) FROM santri WHERE is_active=1 AND archived_at IS NULL'), 0, ',', '.')) && str_contains($t, 'Hunian asrama') && str_contains($r['body'], 'admin_penempatan_santri.php'),
    'N-4 tema Santri & asrama: santri aktif (bukan semua baris), hunian, tautan penempatan');
$r = $admin->req('/admin/admin_dashboard.php?tema=kegiatan');
$t = $teks($r['body']);
$assert(str_contains($t, 'Kehadiran pengajian 10 pekan') && str_contains($t, 'Izin bulan ini per status') && str_contains($r['body'], 'admin_laporan_absensi.php'), 'N-4 tema Kegiatan: kehadiran 10 pekan, izin, laporan');
$assert(!str_contains($r['body'], 'chart.js') && str_contains($admin->req('/admin/admin_dashboard.php')['body'], 'conic-gradient'), 'N-4 grafik dari server tanpa Chart.js');

// ===================================================================== H3-D
echo PHP_EOL . '=== H3-D. N-5 Data sistem lama ===' . PHP_EOL;
$r = $admin->req('/admin/admin_pelanggaran.php');
$assert(str_contains($r['body'], 'Data warisan') && str_contains($r['body'], 'Pembinaan › Pelanggaran') && str_contains($admin->req('/admin/admin_pelanggaran.php?page=2')['body'], '&lt;script&gt;lama&lt;/script&gt;'), 'N-5 baca-saja, rujukan ke Pembinaan, isi di-escape');
$assert($barisTabel($r['body'], 'arsip-pelanggaran') === 25 && str_contains($r['body'], 'total ' . (int) $satu('SELECT COUNT(*) FROM pelanggaran') . ' data'), 'N-5 25 baris per halaman');
$r = $admin->req('/admin/admin_pelanggaran.php?tahun=2024');
$assert(str_contains($r['body'], '<option value="2024" selected') && $barisTabel($r['body'], 'arsip-pelanggaran') === min(25, (int) $satu("SELECT COUNT(*) FROM pelanggaran WHERE YEAR(tgl_pelanggaran)=2024")), 'N-5 saringan tahun = basis data');
$nama = (string) $satu('SELECT s.nama_santri FROM pelanggaran p JOIN santri s ON s.id=p.id_santri ORDER BY p.id LIMIT 1');
$cocok = (int) $satu("SELECT COUNT(*) FROM pelanggaran p JOIN santri s ON s.id=p.id_santri WHERE s.nama_santri LIKE '%" . app_db()->real_escape_string($nama) . "%'");
$r = $admin->req('/admin/admin_pelanggaran.php?q=' . rawurlencode($nama));
$assert($barisTabel($r['body'], 'arsip-pelanggaran') === min(25, $cocok), "N-5 cari santri ($cocok cocok)");
$assert($admin->req('/admin/admin_pelanggaran.php?q=' . rawurlencode("' OR 1=1 -- "))['status'] === 200, 'N-5 masukan pencarian tidak memengaruhi SQL');
$assert($admin->req('/admin/admin_pelanggaran.php?hapus=1')['status'] === 405 && $admin->req('/admin/admin_pelanggaran.php', ['_csrf' => $csrf, 'tambah' => '1'])['status'] === 405, 'N-5 hapus/catat lama tetap 405');

// ===================================================================== H3-E
echo PHP_EOL . '=== H3-E. K-5 Pendaftar PSB ===' . PHP_EOL;
$hitung = static fn (string $where = '1=1'): int => (int) $satu('SELECT COUNT(*) FROM psb_pendaftar WHERE ' . $where);
$r = $admin->req('/admin/admin_data.php');
$t = $teks($r['body']);
$assert(str_contains($t, 'Semua ' . $hitung("status<>'Dimigrasi'")) && str_contains($t, 'Baru ' . $hitung("status='Baru'")) && str_contains($t, 'Dimigrasi ' . $hitung("status='Dimigrasi'")), 'K-5 chip status = basis data (Semua = belum dimigrasi)');
$assert($barisTabel($r['body'], 'daftar-pendaftar') === 20 && str_contains($r['body'], 'Halaman 1 dari 2'), 'K-5 20 baris per halaman');
$r = $admin->req('/admin/admin_data.php?page=2');
$assert($barisTabel($r['body'], 'daftar-pendaftar') === $hitung("status<>'Dimigrasi'") - 20, 'K-5 halaman 2 berisi sisanya');
$r = $admin->req('/admin/admin_data.php?status=Baru&jk=P');
$assert($barisTabel($r['body'], 'daftar-pendaftar') === min(20, $hitung("status='Baru' AND jenis_kelamin='P'")) && str_contains($r['body'], 'aria-current="page"><span>Baru'), 'K-5 saringan status + L/P');
$r = $admin->req('/admin/admin_data.php?cari=' . rawurlencode('Pendaftar 01') . '&jenjang=' . rawurlencode('SMP Terpadu Al Hasan'));
$assert($barisTabel($r['body'], 'daftar-pendaftar') === $hitung("status<>'Dimigrasi' AND nama_lengkap LIKE '%Pendaftar 01%' AND jenjang_tujuan='SMP Terpadu Al Hasan'"), 'K-5 cari + unit tujuan');
$assert($admin->req('/admin/admin_data.php?cari=' . rawurlencode("x' OR '1'='1") . '&jk=' . rawurlencode("L' OR 1=1"))['status'] === 200, 'K-5 masukan saringan tidak memengaruhi SQL');
$r = $admin->req('/admin/admin_data.php');
$assert(str_contains($r['body'], 'SBX T3 &lt;b&gt;Tebal&lt;/b&gt; Pendaftar 007'), 'K-5 nama di-escape');
$baru = (int) $satu("SELECT id FROM psb_pendaftar WHERE no_pendaftaran='SBX-T3-006'");
$st = (string) $satu("SELECT status FROM psb_pendaftar WHERE id=$baru");
$r = $admin->req('/admin/admin_data.php?id=' . $baru);
$assert(str_contains($r['body'], 'class="ah-panel"') && str_contains($r['body'], 'Biodata') && str_contains($r['body'], 'Berkas ') && str_contains($r['body'], 'Riwayat'), 'K-5 panel detail dengan Biodata · Berkas · Riwayat');
$assert(!preg_match('/<img[^>]*berkas_psb/', $admin->req('/admin/admin_data.php?id=' . $baru . '&bagian=berkas')['body']), 'AG panel berkas tanpa pratinjau gambar');
$assert($st !== 'Baru' || (str_contains($r['body'], 'id="formStatusTerima' . $baru . '"') && str_contains($r['body'], 'id="formStatusTolak' . $baru . '"') && str_contains($r['body'], 'data-confirm-title="Tolak pendaftar ini?"')), 'K-5 keputusan Terima/Cadangan/Tolak di panel; Tolak memakai dialog');
$assert(str_contains($r['body'], 'data-confirm-match=') && str_contains($r['body'], 'action="hapus_siswa.php"'), 'K-5 hapus pendaftar di ⋯ dengan ketik nama');
$c = $admin->csrf('/admin/admin_data.php');
$r = $admin->req('/admin/proses_psb_admin.php', ['_csrf' => $c, 'act' => 'status', 'id' => (string) $baru, 'val' => 'Cadangan', 'kembali' => 'status=Baru&jk=P&evil=1']);
$hal = $admin->follow($r);
$assert($r['status'] === 302 && $r['location'] === 'admin_data.php?status=Baru&jk=P&id=' . $baru && $satu("SELECT status FROM psb_pendaftar WHERE id=$baru") === 'Cadangan'
    && str_contains($teks($hal['body']), 'Status pendaftar menjadi Cadangan'), 'K-5 ubah status → pesan di halaman, saringan dipertahankan (kunci asing dibuang)');
$terima = (int) $satu("SELECT id FROM psb_pendaftar WHERE status='Diterima' ORDER BY id LIMIT 1");
$santri = (int) $satu('SELECT COUNT(*) FROM santri');
$r = $admin->req('/admin/proses_psb_admin.php', ['_csrf' => $c, 'act' => 'migrasi', 'id' => (string) $terima]);
$hal = $admin->follow($r);
$assert($r['status'] === 302 && (int) $satu('SELECT COUNT(*) FROM santri') === $santri + 1 && $satu("SELECT status FROM psb_pendaftar WHERE id=$terima") === 'Dimigrasi'
    && preg_match('/Migrasi berhasil\. .+ NIS \d{7}/', $teks($hal['body'])) === 1 && !str_contains($hal['body'], 'alert('), 'K-5 migrasi → satu santri baru, pesan NIS (bukan alert)');
$sebelum = $hitung();
$r = $admin->req('/admin/admin_data.php', ['_csrf' => $c, 'aksi' => 'tambah', 'nama' => '', 'jk' => 'L', 'tgl_lahir' => '2014-13-40', 'jenjang_tujuan' => 'Sekolah palsu', 'sekolah_asal' => 'SD Dipertahankan']);
$hal = $admin->follow($r);
$assert($r['status'] === 302 && $hitung() === $sebelum && str_contains($hal['body'], 'Nama lengkap wajib diisi') && str_contains($hal['body'], 'Tanggal lahir tidak valid')
    && str_contains($hal['body'], 'Unit tujuan tidak dikenal') && str_contains($hal['body'], 'value="SD Dipertahankan"'), 'K-5 tambah tidak valid: pesan per isian, isian dipertahankan, tidak tersimpan');
$r = $admin->req('/admin/admin_data.php', ['_csrf' => $c, 'aksi' => 'tambah', 'nama' => 'SBX T3 Manual', 'nisn' => '', 'jk' => 'P', 'tgl_lahir' => '2014-02-03', 'jenjang_tujuan' => 'SMP Terpadu Al Hasan']);
$manual = $sql("SELECT id, nisn, no_pendaftaran, status FROM psb_pendaftar WHERE nama_lengkap='SBX T3 Manual'")[0] ?? [];
$assert($r['status'] === 302 && ($manual['status'] ?? '') === 'Baru' && str_starts_with((string) ($manual['nisn'] ?? ''), 'TMP') && preg_match('/^PSB\d{8}$/', (string) ($manual['no_pendaftaran'] ?? '')) === 1
    && $r['location'] !== null && str_ends_with((string) $r['location'], '?id=' . $manual['id']), 'K-5 tambah valid: status Baru, NISN sementara TMP…, nomor PSB…, panel pendaftar baru');
$dup = (string) $satu("SELECT nisn FROM psb_pendaftar WHERE no_pendaftaran='SBX-T3-001'");
$r = $admin->req('/admin/admin_data.php', ['_csrf' => $c, 'aksi' => 'tambah', 'nama' => 'SBX T3 Ganda', 'nisn' => $dup, 'jk' => 'P', 'tgl_lahir' => '2014-02-03', 'jenjang_tujuan' => 'SMP Terpadu Al Hasan']);
$assert((int) $satu("SELECT COUNT(*) FROM psb_pendaftar WHERE nama_lengkap='SBX T3 Ganda'") === 0 && str_contains($admin->follow($r)['body'], 'NISN sudah dipakai'), 'K-5 NISN ganda ditolak dengan pesan');

// Wizard impor
$berkas = $xlsx([
    ['Nama', 'NISN', 'NIK', 'JK', 'Tempat', 'Tgl', 'Alamat', 'Desa', 'Kec', 'Kab', 'Prov', 'Ayah', 'Ibu', 'HP', 'Asal', 'Jenjang'],
    ['SBX T3 Impor Satu', '9931000001', '1', 'L', 'Ciamis', '2014-01-02', 'Jl', 'D', 'K', 'Kab', 'Prov', 'A', 'I', '0812', 'SD', 'SMP Terpadu Al Hasan'],
    ['SBX T3 Impor Dua', '', '', 'P', 'Ciamis', '03/04/2014', '', '', '', '', '', '', '', '', '', 'SMK Terpadu Al Hasan'],
    ['', '9931000003', '', 'L', '', '', '', '', '', '', '', '', '', '', '', ''],
    ['SBX T3 Impor Ganda', '9931000001', '', 'L', '', '2014-01-01', '', '', '', '', '', '', '', '', '', ''],
    ['SBX T3 Impor Lama', $dup, '', 'L', '', '2014-01-01', '', '', '', '', '', '', '', '', '', ''],
    ['SBX T3 Impor Tanggal', '', '', 'L', '', 'bukan tanggal', '', '', '', '', '', '', '', '', '', ''],
]);
$sebelum = $hitung();
$r = $admin->req('/admin/admin_data.php', ['_csrf' => $c, 'aksi' => 'impor_periksa', 'berkas' => new CURLFile($berkas, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'impor.xlsx')]);
$periksa = $admin->follow($r);
$tp = $teks($periksa['body']);
$assert($r['status'] === 302 && $hitung() === $sebelum && str_contains($tp, 'Siap ditambahkan 2') && str_contains($tp, 'Dilewati 4') && str_contains($tp, 'Nama lengkap (kolom A) kosong')
    && str_contains($tp, 'NISN sama dengan baris 2') && str_contains($tp, 'NISN sudah terdaftar') && str_contains($tp, 'Tanggal lahir (kolom F) tidak dikenali'), 'K-5 impor langkah Periksa: 2 siap, 4 dilewati beserta alasan, BELUM tersimpan');
preg_match('/name="token" value="([a-f0-9]{32})"/', $periksa['body'], $tok);
$r = $admin->req('/admin/admin_data.php', ['_csrf' => $c, 'aksi' => 'impor_simpan', 'token' => $tok[1] ?? '']);
$hasil = $admin->follow($r);
$assert($hitung() === $sebelum + 2 && $satu("SELECT tgl_lahir FROM psb_pendaftar WHERE nama_lengkap='SBX T3 Impor Dua'") === '2014-04-03' && str_contains($teks($hasil['body']), 'Impor selesai: 2 pendaftar ditambahkan · 4 baris dilewati'), 'K-5 impor langkah Simpan: 2 tersimpan, ringkasan sebagai pesan');
$admin->req('/admin/admin_data.php', ['_csrf' => $c, 'aksi' => 'impor_simpan', 'token' => $tok[1] ?? '']);
$assert($hitung() === $sebelum + 2, 'K-5 token impor sekali pakai: kirim ulang tidak menambah data');
$r = $admin->req('/admin/admin_data.php', ['_csrf' => $c, 'aksi' => 'impor_periksa', 'berkas' => new CURLFile($png(), 'image/png', 'bukan.xlsx.png')]);
$assert(str_contains($admin->follow($r)['body'], 'Berkas harus .xlsx'), 'K-5 impor menolak berkas bukan .xlsx');
$e = $admin->req('/admin/export_psb.php?status=Baru');
$assert($e['status'] === 200 && str_contains($e['type'], 'spreadsheetml') && str_starts_with($e['body'], 'PK'), 'K-5 ekspor .xlsx dibuat server');
$tmp = tempnam(sys_get_temp_dir(), 'tahap3-') . '.xlsx';
$berkasSementara[] = $tmp;
file_put_contents($tmp, $e['body']);
require_once $root . '/admin/SimpleXLSX.php';
$isi = \Shuchkin\SimpleXLSX::parse($tmp)->rows();
$assert(count($isi) - 1 === $hitung("status='Baru'") && $isi[0][1] === 'NO PENDAFTARAN', 'K-5 ekspor mengikuti saringan (Baru) dan judul kolom lama');
$e = $admin->req('/admin/export_psb.php?cari=tidak-ada-sama-sekali');
$assert($e['status'] === 302 && str_contains($admin->follow($e)['body'], 'Tidak ada pendaftar yang cocok'), 'K-5 ekspor kosong → pesan, bukan alert');
$r = $admin->req('/admin/hapus_siswa.php', ['_csrf' => $c, 'id' => (string) $manual['id'], 'kembali' => 'status=Baru']);
$assert($r['status'] === 302 && $r['location'] === 'admin_data.php?status=Baru' && (int) $satu('SELECT COUNT(*) FROM psb_pendaftar WHERE id=' . (int) $manual['id']) === 0, 'K-5 hapus → daftar dengan saringan, data terhapus');

// ===================================================================== H3-F
echo PHP_EOL . '=== H3-F. K-6 Pembayaran, K-7 Rekap keuangan, K-8 Rekap santri ===' . PHP_EOL;
$bayarSql = 'FROM psb_pembayaran pb JOIN psb_pendaftar p ON p.no_pendaftaran=pb.no_pendaftaran';
$r = $admin->req('/admin/admin_pembayaran_psb.php');
$t = $teks($r['body']);
$kas = (int) $satu("SELECT COALESCE(SUM(pb.total_keseluruhan),0) $bayarSql WHERE pb.status_pembayaran='Lunas'");
$assert(str_contains($t, 'Belum lunas ' . (int) $satu("SELECT COUNT(*) $bayarSql WHERE pb.status_pembayaran='Belum Lunas'")) && str_contains($t, 'Kas masuk Rp ' . number_format($kas, 0, ',', '.')), 'K-6 ringkasan = basis data');
$assert($barisTabel($r['body'], 'daftar-pembayaran') === min(20, (int) $satu("SELECT COUNT(*) $bayarSql")) && str_contains($r['body'], 'Rincian'), 'K-6 berhalaman 20 dengan tombol Rincian');
$r = $admin->req('/admin/admin_pembayaran_psb.php?status=' . rawurlencode('Belum Lunas'));
$assert($barisTabel($r['body'], 'daftar-pembayaran') === min(20, (int) $satu("SELECT COUNT(*) $bayarSql WHERE pb.status_pembayaran='Belum Lunas'")), 'K-6 chip Belum lunas (alamat Beranda lama tetap)');
$tagihan = (int) $satu("SELECT pb.id $bayarSql WHERE pb.status_pembayaran='Belum Lunas' ORDER BY pb.id LIMIT 1");
$r = $admin->req('/admin/admin_pembayaran_psb.php?id=' . $tagihan);
$assert(str_contains($r['body'], 'class="ah-panel"') && str_contains($r['body'], 'B. Biaya wajib terbayar') && str_contains($r['body'], 'data-confirm-title="Tandai lunas?"') && str_contains($r['body'], 'name="act" value="lunas"'), 'K-6 panel rincian dengan Tandai lunas (dialog)');
$c = $admin->csrf('/admin/admin_pembayaran_psb.php');
$r = $admin->req('/admin/admin_pembayaran_psb.php', ['_csrf' => $c, 'act' => 'lunas', 'id' => (string) $tagihan, 'kembali' => 'status=Belum+Lunas']);
$assert($r['status'] === 302 && $satu("SELECT status_pembayaran FROM psb_pembayaran WHERE id=$tagihan") === 'Lunas' && str_contains($teks($admin->follow($r)['body']), 'ditandai lunas'), 'K-6 tandai lunas → pesan, tersimpan');
$audit = (int) $satu("SELECT COUNT(*) FROM audit_logs WHERE action='psb.pembayaran.batal_lunas' AND entity_id=$tagihan");
// Koreksi audit F-2: alasan wajib di server, diperiksa sebelum status berubah.
$waktuLunas = $satu("SELECT waktu_lunas FROM psb_pembayaran WHERE id=$tagihan");
foreach ([[], ['alasan' => '   '], ['alasan' => str_repeat('x', 501)]] as $post) {
    $r = $admin->req('/admin/admin_pembayaran_psb.php', ['_csrf' => $c, 'act' => 'batal', 'id' => (string) $tagihan, 'kembali' => 'status=Lunas'] + $post);
    $hal = $admin->follow($r);
    $assert($r['status'] === 302 && str_contains((string) $r['location'], 'batal=1') && $satu("SELECT status_pembayaran FROM psb_pembayaran WHERE id=$tagihan") === 'Lunas'
        && $satu("SELECT waktu_lunas FROM psb_pembayaran WHERE id=$tagihan") === $waktuLunas
        && (int) $satu("SELECT COUNT(*) FROM audit_logs WHERE action='psb.pembayaran.batal_lunas' AND entity_id=$tagihan") === $audit
        && str_contains($teks($hal['body']), 'Status lunas tidak berubah') && str_contains($hal['body'], 'id="form-batal-lunas"') && str_contains($hal['body'], 'name="alasan"'),
        'F-2 batal lunas ' . ($post === [] ? 'tanpa alasan' : (strlen($post['alasan']) > 500 ? 'alasan > 500' : 'alasan spasi')) . ' ditolak sebelum UPDATE; status/waktu/audit tetap; formulir alasan tanpa JavaScript dibuka');
}
$r = $admin->req('/admin/admin_pembayaran_psb.php', ['_csrf' => $c, 'act' => 'batal', 'id' => (string) $tagihan, 'alasan' => 'Salah input sintetis']);
$assert($satu("SELECT status_pembayaran FROM psb_pembayaran WHERE id=$tagihan") === 'Belum Lunas' && (int) $satu("SELECT COUNT(*) FROM audit_logs WHERE action='psb.pembayaran.batal_lunas' AND entity_id=$tagihan") === $audit + 1
    && str_contains((string) $satu("SELECT after_json FROM audit_logs WHERE action='psb.pembayaran.batal_lunas' AND entity_id=$tagihan ORDER BY id DESC LIMIT 1"), 'Salah input sintetis'), 'K-6 batal lunas dengan alasan tercatat di audit');
$lunasId = (int) $satu("SELECT pb.id $bayarSql WHERE pb.status_pembayaran='Lunas' LIMIT 1");
$assert(str_contains($admin->req('/admin/admin_pembayaran_psb.php?id=' . $lunasId)['body'], 'data-confirm-reason="alasan"'), 'K-6 batal lunas di ⋯ meminta alasan');
$e = $admin->req('/admin/export_pembayaran.php?status=Lunas');
$assert($e['status'] === 200 && str_starts_with($e['body'], 'PK'), 'K-6 ekspor Excel dari server');
$r = $admin->req('/admin/admin_rekap_keuangan.php');
$totalLunas = 0;
foreach ($sql("SELECT pb.syahriyyah+pb.infaq+pb.seragam_psas+pb.seragam_pramuka AS a, pb.rincian_wajib $bayarSql WHERE pb.status_pembayaran='Lunas'") as $row) {
    $totalLunas += (int) $row['a'] + array_sum(array_map('intval', (array) json_decode((string) $row['rincian_wajib'], true)));
}
$assert(str_contains($teks($r['body']), 'total Rp ' . number_format($totalLunas, 0, ',', '.')) && str_contains($r['body'], 'class="ah-matrix"') && str_contains($r['body'], 'class="ah-fold"'), 'K-7 total rekap lunas = basis data; matriks + lipatan HP');
$assert(str_contains($teks($admin->req('/admin/admin_rekap_keuangan.php?status=' . rawurlencode('Belum Lunas'))['body']), 'Tagihan belum lunas'), 'K-7 segmen Belum lunas');
$e = $admin->req('/admin/admin_rekap_keuangan.php?status=Semua&unduh=1');
$assert($e['status'] === 200 && str_starts_with($e['body'], 'PK'), 'K-7 ekspor Excel dari server');
$r = $admin->req('/admin/admin_rekap_santri.php');
$assert(str_contains($teks($r['body']), 'Total santri aktif') && str_contains($r['body'], 'Santri per jenjang') && str_contains($r['body'], 'Rasio laki-laki dan perempuan'), 'K-8 tabel dengan total dan dua grafik');
$e = $admin->req('/admin/admin_rekap_santri.php?unduh=1');
$assert($e['status'] === 200 && str_starts_with($e['body'], 'PK'), 'K-8 unduh Excel dari server');

// ===================================================================== H3-G
echo PHP_EOL . '=== H3-G. W-1 s.d. W-4 Konten website ===' . PHP_EOL;
$r = $admin->req('/admin/admin_berita.php');
$assert($barisTabel($r['body'], 'daftar-berita') === 20 && str_contains($r['body'], 'total ' . (int) $satu('SELECT COUNT(*) FROM berita') . ' data') && str_contains($admin->req('/admin/admin_berita.php?page=2')['body'], 'SBX T3 Berita &lt;script&gt;x&lt;/script&gt; tiga'), 'W-1 20 berita per halaman, judul di-escape');
$assert(str_contains($r['body'], '>Lihat di situs<') && str_contains($r['body'], 'action="proses_berita.php?act=hapus"') && str_contains($r['body'], 'data-confirm-title="Hapus berita'), 'W-1 menu ⋯ Ubah, Lihat di situs, Hapus (dialog menyebut judul)');
$assert($barisTabel($admin->req('/admin/admin_berita.php?q=' . rawurlencode('Berita 1'))['body'], 'daftar-berita') === (int) $satu("SELECT COUNT(*) FROM berita WHERE judul LIKE '%Berita 1%'"), 'W-1 cari judul');
$bulan = (string) $satu("SELECT DATE_FORMAT(MAX(tanggal), '%Y-%m') FROM berita");
$assert($barisTabel($admin->req('/admin/admin_berita.php?bulan=' . $bulan)['body'], 'daftar-berita') === min(20, (int) $satu("SELECT COUNT(*) FROM berita WHERE DATE_FORMAT(tanggal, '%Y-%m')='$bulan'")), 'W-1 saringan bulan');
$c = $admin->csrf('/admin/tambah_berita.php');
$jumlah = (int) $satu('SELECT COUNT(*) FROM berita');
$r = $admin->req('/admin/proses_berita.php?act=tambah', ['_csrf' => $c, 'judul' => '', 'isi' => '<p>Isi dipertahankan</p>']);
$hal = $admin->follow($r);
$assert($r['status'] === 302 && (int) $satu('SELECT COUNT(*) FROM berita') === $jumlah && str_contains($hal['body'], 'Judul wajib diisi') && str_contains($hal['body'], 'Gambar sampul wajib dipilih') && str_contains($hal['body'], 'Isi dipertahankan'), 'W-2 tulis tanpa judul/sampul: pesan per isian, isi dipertahankan');
$palsu = tempnam(sys_get_temp_dir(), 'tahap3-') . '.jpg';
$berkasSementara[] = $palsu;
file_put_contents($palsu, '<?php echo 1;');
foreach ([[$palsu, 'sampul.jpg', 'bukan gambar'], [$palsu, 'sampul.php', 'jenis berkas tidak didukung']] as [$p, $namaBerkas, $pesan]) {
    $r = $admin->req('/admin/proses_berita.php?act=tambah', ['_csrf' => $c, 'judul' => 'SBX T3 Tolak', 'isi' => '<p>x</p>', 'foto' => new CURLFile($p, 'image/jpeg', $namaBerkas)]);
    $assert((int) $satu('SELECT COUNT(*) FROM berita') === $jumlah && str_contains($admin->follow($r)['body'], $pesan) && glob($root . '/gambar/*_' . pathinfo($namaBerkas, PATHINFO_FILENAME) . '.*') === [], "W-2 sampul $namaBerkas ditolak ($pesan), tidak ada berkas tersimpan");
}
$r = $admin->req('/admin/proses_berita.php?act=tambah', ['_csrf' => $c, 'judul' => 'SBX T3 Berita Baru', 'isi' => '<p>Isi <b>baru</b></p>', 'foto' => new CURLFile($png(), 'image/png', '../../sampul baru.png')]);
$beritaBaru = $sql("SELECT id, gambar, tanggal FROM berita WHERE judul='SBX T3 Berita Baru'")[0] ?? [];
$gambarBaru = $root . '/gambar/' . ($beritaBaru['gambar'] ?? 'x');
$berkasSementara[] = $gambarBaru;
$assert($r['status'] === 302 && is_file($gambarBaru) && preg_match('/^\d+_sampul baru\.png$/', (string) ($beritaBaru['gambar'] ?? '')) === 1 && ($beritaBaru['tanggal'] ?? '') === date('Y-m-d')
    && str_contains($teks($admin->follow($r)['body']), 'langsung tampil di situs'), 'W-2 tulis berita: tersimpan dengan sampul di folder gambar/, nama berkas tanpa jalur, langsung tampil');
$e = $admin->req('/admin/edit_berita.php?id=' . (int) ($beritaBaru['id'] ?? 0));
$assert($e['status'] === 200 && str_contains($e['body'], 'value="SBX T3 Berita Baru"') && str_contains($e['body'], 'data-berita-pratinjau') && str_contains($e['body'], 'Simpan &amp; tampilkan'), 'W-2 ubah berita di kerangka admin dengan Pratinjau dan Simpan & tampilkan');
$r = $admin->req('/admin/proses_berita.php?act=update', ['_csrf' => $c, 'id' => (string) $beritaBaru['id'], 'judul' => 'SBX T3 Berita Diubah', 'isi' => '<p>Ubah</p>', 'gbr_lama' => '../../index.php']);
$assert($satu('SELECT judul FROM berita WHERE id=' . (int) $beritaBaru['id']) === 'SBX T3 Berita Diubah' && is_file($gambarBaru) && is_file($root . '/index.php'), 'W-2 ubah teks tanpa mengganti sampul; gbr_lama dari permintaan diabaikan');
$assert($admin->req('/admin/edit_berita.php?id=999999')['status'] === 302, 'W-2 berita tidak ada → kembali ke daftar dengan pesan');
$assert($admin->req('/admin/proses_berita.php?act=tambah')['status'] === 405, 'W-2 GET ke pemroses berita ditolak 405');

$r = $admin->req('/admin/admin_galeri.php');
$assert(substr_count($r['body'], '<li><a href="/admin/admin_galeri.php?id=') === 24 && str_contains($r['body'], 'name="foto[]"') && str_contains($r['body'], 'data-ah-drop'), 'W-3 kisi 24 foto per halaman, unggah beberapa foto dengan zona tarik');
$c = $admin->csrf('/admin/admin_galeri.php');
$jumlah = (int) $satu('SELECT COUNT(*) FROM galeri');
$r = $admin->req('/admin/admin_galeri.php', ['_csrf' => $c, 'upload_foto' => '1', 'keterangan' => 'Kegiatan sintetis',
    'foto[0]' => new CURLFile($png(), 'image/png', 'foto_pertama.png'), 'foto[1]' => new CURLFile($png(), 'image/png', 'foto-kedua.png'), 'foto[2]' => new CURLFile($palsu, 'image/jpeg', 'jahat.php')]);
$fotoBaru = $sql("SELECT id, judul, gambar FROM galeri WHERE keterangan='Kegiatan sintetis' ORDER BY id");
foreach ($fotoBaru as $f) {
    $berkasSementara[] = $root . '/gambar_galeri/' . $f['gambar'];
}
$assert((int) $satu('SELECT COUNT(*) FROM galeri') === $jumlah + 2 && array_column($fotoBaru, 'judul') === ['foto pertama', 'foto kedua'] && str_contains($teks($admin->follow($r)['body']), '2 foto diunggah · 1 gagal'), 'W-3 dua foto jadi dua baris (judul dari nama berkas), berkas .php ditolak');
$satuFoto = (int) ($fotoBaru[0]['id'] ?? 0);
$assert(str_contains($admin->req('/admin/admin_galeri.php?id=' . $satuFoto)['body'], 'class="ah-panel"'), 'W-3 klik foto membuka panel ubah/hapus');
$admin->req('/admin/admin_galeri.php', ['_csrf' => $c, 'ubah' => (string) $satuFoto, 'judul' => 'SBX T3 Judul Baru', 'keterangan' => 'Keterangan baru']);
$assert($satu("SELECT judul FROM galeri WHERE id=$satuFoto") === 'SBX T3 Judul Baru', 'W-3 ubah judul dan keterangan');
foreach ($fotoBaru as $f) {
    $r = $admin->req('/admin/admin_galeri.php', ['_csrf' => $c, 'hapus' => (string) $f['id']]);
    $assert($r['status'] === 302 && !is_file($root . '/gambar_galeri/' . $f['gambar']) && (int) $satu('SELECT COUNT(*) FROM galeri WHERE id=' . (int) $f['id']) === 0, 'W-3 hapus foto: baris dan berkas terhapus');
}

$r = $admin->req('/admin/admin_download.php');
$assert($barisTabel($r['body'], 'daftar-unduhan') === 20 && str_contains($r['body'], '>PDF<') && str_contains($r['body'], '>DOCX<'), 'W-4 20 dokumen per halaman dengan kolom jenis');
$c = $admin->csrf('/admin/admin_download.php');
$pdf = tempnam(sys_get_temp_dir(), 'tahap3-') . '.pdf';
$berkasSementara[] = $pdf;
file_put_contents($pdf, "%PDF-1.4\n% sintetis\n");
$r = $admin->req('/admin/admin_download.php', ['_csrf' => $c, 'upload_file' => '1', 'judul' => 'SBX T3 Brosur', 'file' => new CURLFile($pdf, 'application/pdf', 'brosur.pdf')]);
$dok = $sql("SELECT id, nama_file FROM download WHERE judul='SBX T3 Brosur'")[0] ?? [];
$berkasSementara[] = $root . '/admin/file_download/' . ($dok['nama_file'] ?? 'x');
$assert($r['status'] === 302 && is_file($root . '/admin/file_download/' . ($dok['nama_file'] ?? 'x')), 'W-4 unggah dokumen PDF tersimpan');
$r = $admin->req('/admin/admin_download.php', ['_csrf' => $c, 'upload_file' => '1', 'judul' => 'SBX T3 Jahat', 'file' => new CURLFile($palsu, 'text/plain', 'skrip.phtml')]);
$assert((int) $satu("SELECT COUNT(*) FROM download WHERE judul='SBX T3 Jahat'") === 0 && $r['location'] === 'admin_download.php?action=unggah' && str_contains($admin->follow($r)['body'], 'jenis berkas tidak didukung'), 'W-4 jenis berkas berbahaya ditolak, panel unggah dibuka lagi dengan pesan');
$r = $admin->req('/admin/admin_download.php?q=' . rawurlencode('SBX T3 Brosur'));
$assert($barisTabel($r['body'], 'daftar-unduhan') === 1 && str_contains($r['body'], 'KB'), 'W-4 cari judul, ukuran berkas tampil');
$r = $admin->req('/admin/admin_download.php', ['_csrf' => $c, 'hapus' => (string) ($dok['id'] ?? 0)]);
$assert(!is_file($root . '/admin/file_download/' . ($dok['nama_file'] ?? 'x')), 'W-4 hapus dokumen menghapus berkasnya');

// ===================================================================== H3-H
echo PHP_EOL . '=== H3-H. Keadaan kosong ===' . PHP_EOL;
$kosong = $klien('sbx_admin', 'http://127.0.0.1:' . $portKosong);
foreach ([
    '/admin/admin_data.php' => 'Tidak ada pendaftar yang cocok', '/admin/admin_pembayaran_psb.php' => 'Tidak ada tagihan yang cocok',
    '/admin/admin_rekap_keuangan.php' => 'Belum ada angka untuk', '/admin/admin_berita.php' => 'Belum ada berita',
    '/admin/admin_galeri.php' => 'Belum ada foto', '/admin/admin_download.php' => 'Belum ada dokumen',
    '/admin/admin_pelanggaran.php' => 'Belum ada data warisan', '/admin/admin_notifikasi.php?tab=gagal' => 'Tidak ada pengiriman gagal',
    '/admin/admin_notifikasi.php?tab=riwayat' => 'Belum ada perubahan kanal', '/admin/admin_dashboard.php' => 'Belum ada pendaftar.',
] as $path => $pesan) {
    $r = $kosong->req($path);
    $assert($r['status'] === 200 && str_contains($teks($r['body']), $pesan), "Kosong $path: \"$pesan\"");
}

// ===================================================================== H3-I
echo PHP_EOL . '=== H3-I. Log server ===' . PHP_EOL;
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
