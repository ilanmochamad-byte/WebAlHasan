<?php

declare(strict_types=1);

/**
 * Smoke test HTTP V2 Fase 4 — pusat notifikasi web dan panel kanal admin.
 *
 * Menjalankan halaman Fase 4 lewat HTTP sungguhan (server PHP built-in) untuk
 * membuktikan guard, CSRF, kode status, dan alur POST/Redirect/GET bekerja
 * seperti yang dijanjikan — bukan hanya pada lapisan layanan.
 *
 * Yang diperiksa:
 *   WN-1  anonim diarahkan ke halaman masuk;
 *   WN-2  seluruh peran perizinan dapat membuka pusat notifikasi;
 *   WN-3  lencana jumlah belum dibaca dan filter tampil;
 *   WN-4  tandai dibaca dan tandai semua bekerja lewat POST/Redirect/GET;
 *   WN-5  membuka notifikasi milik pengguna lain tidak pernah menampilkan isinya;
 *   WN-6  POST tanpa token CSRF ditolak 419;
 *   WN-7  panel kanal admin hanya untuk admin (non-admin 403);
 *   WN-8  panel admin tidak menampilkan credential, token, atau nomor;
 *   WN-9  kanal in-app tampil sebagai selalu aktif dan tidak dapat dimatikan.
 *
 * Memakai fixture sandbox Fase 3 (`bin/v2_phase3_sandbox_seed.php`).
 *
 * Jalankan hanya pada database berakhiran `_test`:
 *   V2_PHASE4_RUN_WEB=1 php tests/v2_phase4_web_smoke.php
 */

$root = dirname(__DIR__);
if (getenv('V2_PHASE4_RUN_WEB') !== '1') {
    fwrite(STDOUT, "[lewati] Set V2_PHASE4_RUN_WEB=1 dan arahkan DB_NAME ke database khusus *_test.\n");
    exit(77);
}

putenv('PUSH_TOKEN_KEY=' . base64_encode(str_repeat("\x2b", 32)));
putenv('WHATSAPP_PROVIDER=');

require_once $root . '/app/bootstrap.php';

if (!str_ends_with((string) app_config('database.database'), '_test')) {
    fwrite(STDERR, "Smoke test ditolak: DB_NAME wajib berakhiran _test.\n");
    exit(2);
}
if (!extension_loaded('curl')) {
    fwrite(STDERR, "Ekstensi curl dibutuhkan untuk smoke test HTTP.\n");
    exit(2);
}

$db = app_db();
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? '[lulus] ' : '[gagal] ') . $message . PHP_EOL;
    if (!$condition) {
        $failures[] = $message;
    }
};

/**
 * Port server uji.
 *
 * Bila `V2_PHASE4_WEB_PORT` tidak disetel, port bebas dipilih otomatis. Ini
 * mencegah kelas kegagalan yang menyesatkan: server uji dari putaran sebelumnya
 * yang belum berhenti akan tetap memegang port tetap, sehingga putaran baru
 * diam-diam menguji PROSES LAMA (dengan kode dan environment lama) dan
 * melaporkan kegagalan yang sebenarnya tidak ada pada kode saat ini.
 *
 * Bila port disetel manual dan ternyata sudah dipakai, pengujian berhenti
 * dengan pesan yang jelas alih-alih menghasilkan hasil palsu.
 */
$portBebas = static function (): int {
    $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($socket === false) {
        return 8714;
    }
    $nama = (string) stream_socket_get_name($socket, false);
    fclose($socket);

    return (int) substr($nama, (int) strrpos($nama, ':') + 1);
};
$portDiminta = (int) (getenv('V2_PHASE4_WEB_PORT') ?: 0);
if ($portDiminta > 0) {
    $probe = @fsockopen('127.0.0.1', $portDiminta, $errno, $errstr, 0.5);
    if (is_resource($probe)) {
        fclose($probe);
        fwrite(STDERR, "Port {$portDiminta} sudah dipakai proses lain. Hentikan server uji lama"
            . " (ss -ltnp | grep {$portDiminta}) atau kosongkan V2_PHASE4_WEB_PORT.\n");
        exit(2);
    }
}
$port = $portDiminta > 0 ? $portDiminta : $portBebas();
$baseUrl = 'http://127.0.0.1:' . $port;
$sandi = 'Sandbox#123';
$suffix = strtolower(bin2hex(random_bytes(3)));

/** Klien HTTP sederhana dengan cookie jar per peran. */
final class KlienWeb
{
    private string $jar;

    public function __construct(private string $baseUrl, string $label)
    {
        $this->jar = (string) tempnam(sys_get_temp_dir(), 'wah4-' . $label . '-');
    }

    /**
     * @param array<string, string>|null $post
     * @return array{status:int, body:string, location:?string}
     */
    public function request(string $path, ?array $post = null): array
    {
        $handle = curl_init($this->baseUrl . $path);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_TIMEOUT => 20,
        ]);
        if ($post !== null) {
            curl_setopt($handle, CURLOPT_POST, true);
            curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $raw = (string) curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        curl_close($handle);

        $headers = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);
        $location = null;
        if (preg_match('/^Location:\s*(.+)$/mi', $headers, $matches) === 1) {
            $location = trim($matches[1]);
        }

        return ['status' => $status, 'body' => $body, 'location' => $location];
    }

    public function csrf(string $path): string
    {
        $response = $this->request($path);
        if (preg_match('/name="_csrf" value="([^"]+)"/', $response['body'], $matches) === 1) {
            return $matches[1];
        }
        throw new RuntimeException('Token CSRF tidak ditemukan pada ' . $path . ' (status ' . $response['status'] . ')');
    }

    public function login(string $username, string $password): array
    {
        // PERUBAHAN ALAMAT - paket perapihan V1-V2, koreksi ke-7 (satu pintu masuk),
        // keputusan pengguna 30 Agustus 2026. Halaman masuk kini berada di
        // `/portal/index.php`; `/admin/admin_login.php` tetap berfungsi sebagai
        // alamat lama yang mengarahkan ke sana. Penangan POST tidak berubah:
        // tetap `/admin/cek_login.php`, sehingga alur pengiriman formulir lama
        // tetap kompatibel.
        $token = $this->csrf('/portal/index.php');

        return $this->request('/admin/cek_login.php', [
            '_csrf' => $token,
            'username' => $username,
            'password' => $password,
        ]);
    }
}

$server = null;
$dibuat = ['izin' => []];
$settings = notification_settings_repository();
$pengaturanAwal = $settings->current();

try {
    foreach (['sbx_admin', 'sbx_pengurus_a', 'sbx_murobi_a', 'sbx_ortu_a'] as $required) {
        $row = $db->query("SELECT id FROM users WHERE username = '" . $db->real_escape_string($required) . "' LIMIT 1");
        if (!$row || $row->num_rows === 0) {
            throw new RuntimeException('Fixture sandbox belum tersedia. Jalankan: V2_PHASE3_SEED=1 php bin/v2_phase3_sandbox_seed.php');
        }
    }

    $adminId = (int) ($db->query("SELECT id FROM users WHERE username = 'sbx_admin' LIMIT 1")?->fetch_assoc()['id'] ?? 0);
    $pengurusUserId = (int) ($db->query("SELECT id FROM users WHERE username = 'sbx_pengurus_a' LIMIT 1")?->fetch_assoc()['id'] ?? 0);
    $murobiUserId = (int) ($db->query("SELECT id FROM users WHERE username = 'sbx_murobi_a' LIMIT 1")?->fetch_assoc()['id'] ?? 0);
    $santriA1 = (int) ($db->query("SELECT id FROM santri WHERE nis = 'SBX-S-001' LIMIT 1")?->fetch_assoc()['id'] ?? 0);
    $_SESSION = ['user_id' => $adminId];

    $settings->setPushEnabled(false, $adminId);
    $settings->setWhatsappEnabled(false, $adminId);

    // Satu pengajuan supaya murobi benar-benar punya notifikasi untuk dibaca.
    $pengajuan = izin_workflow_service()->create(
        auth_repository()->findActiveById($pengurusUserId) ?? throw new RuntimeException('Akun pengurus fixture tidak ditemukan.'),
        [
            'santri_id' => $santriA1,
            'tgl_izin' => date('Y-m-d', strtotime('+5 days')),
            'tgl_kembali' => date('Y-m-d', strtotime('+6 days')),
            'alasan' => 'Alasan rahasia smoke test web',
        ],
        'f4-web-' . $suffix,
        ['ip' => '127.0.0.1', 'user_agent' => 'smoke-fase-4']
    );
    $dibuat['izin'][] = (int) $pengajuan['id'];

    $idNotifikasiMurobi = (int) ($db->query(
        "SELECT id FROM notifikasi_outbox WHERE penerima_user_id = " . $murobiUserId
        . " AND kanal = 'InApp' ORDER BY id DESC LIMIT 1"
    )?->fetch_assoc()['id'] ?? 0);
    if ($idNotifikasiMurobi < 1) {
        throw new RuntimeException('Notifikasi murobi tidak terbentuk; alur Fase 4 belum berjalan.');
    }

    // --------------------------------------------------------- server lokal
    $descriptors = [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
    $pipes = [];
    // Dijalankan TANPA shell (bentuk array `proc_open`). Dengan bentuk string,
    // `/bin/sh` menjadi anak proses dan `proc_terminate` hanya mematikan
    // shell-nya — server PHP tetap hidup memegang port dan menyesatkan putaran
    // pengujian berikutnya.
    //
    // Kunci sandbox diwariskan lewat environment proses anak: tanpa itu
    // konfigurasi push pada proses server dianggap belum siap dan sakelar push
    // ditolak — persis seperti di produksi bila environment belum diisi.
    $serverEnv = getenv();
    $serverEnv['PUSH_TOKEN_KEY'] = (string) getenv('PUSH_TOKEN_KEY');
    $server = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root],
        $descriptors,
        $pipes,
        $root,
        $serverEnv
    );
    if (!is_resource($server)) {
        throw new RuntimeException('Server uji tidak dapat dijalankan.');
    }
    $siap = false;
    for ($i = 0; $i < 60; $i++) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.5);
        if ($socket !== false) {
            fclose($socket);
            $siap = true;
            break;
        }
        usleep(200000);
    }
    if (!$siap) {
        throw new RuntimeException('Server uji tidak merespons pada port ' . $port . '.');
    }

    // ------------------------------------------------------------- WN-1 anonim
    echo PHP_EOL . '=== WN-1. Anonim ===' . PHP_EOL;
    $anon = new KlienWeb($baseUrl, 'anon');
    $anonPusat = $anon->request('/portal/notifikasi.php');
    $assert(
        $anonPusat['status'] === 302 && str_contains((string) $anonPusat['location'], '/portal/index.php'),
        'WN-1a Anonim diarahkan ke halaman masuk dari pusat notifikasi'
    );
    $anonAdmin = $anon->request('/admin/admin_notifikasi.php');
    $assert(
        $anonAdmin['status'] === 302 && str_contains((string) $anonAdmin['location'], '/portal/index.php'),
        'WN-1b Anonim diarahkan ke halaman masuk dari panel kanal'
    );

    // ------------------------------------------------------- WN-2 semua peran
    echo PHP_EOL . '=== WN-2. Akses seluruh peran ===' . PHP_EOL;
    $klien = [];
    foreach ([
        'admin' => 'sbx_admin',
        'pengurus' => 'sbx_pengurus_a',
        'murobi' => 'sbx_murobi_a',
        'ortu' => 'sbx_ortu_a',
    ] as $alias => $username) {
        $klien[$alias] = new KlienWeb($baseUrl, $alias);
        $klien[$alias]->login($username, $sandi);
        $halaman = $klien[$alias]->request('/portal/notifikasi.php');
        $assert($halaman['status'] === 200, 'WN-2 ' . $alias . ' dapat membuka pusat notifikasi');
    }

    // -------------------------------------------------------- WN-3 tampilan
    echo PHP_EOL . '=== WN-3. Tampilan pusat notifikasi ===' . PHP_EOL;
    $pusatMurobi = $klien['murobi']->request('/portal/notifikasi.php');
    foreach (['Notifikasi', 'Belum dibaca', 'Sudah dibaca', 'Semua'] as $bagian) {
        $assert(str_contains($pusatMurobi['body'], $bagian), 'WN-3a Pusat notifikasi menampilkan filter "' . $bagian . '"');
    }
    $assert(
        str_contains($pusatMurobi['body'], 'belum dibaca') || str_contains($pusatMurobi['body'], 'Semua sudah dibaca'),
        'WN-3b Ringkasan jumlah belum dibaca ditampilkan'
    );
    $assert(
        !str_contains($pusatMurobi['body'], 'rahasia'),
        'WN-3c Alasan izin tidak pernah tampil pada daftar notifikasi'
    );
    $kosong = $klien['ortu']->request('/portal/notifikasi.php?status=belum_dibaca');
    $assert($kosong['status'] === 200, 'WN-3d Filter status dapat dibuka tanpa galat');

    // ----------------------------------------------------- WN-4 tandai dibaca
    echo PHP_EOL . '=== WN-4. Tandai dibaca ===' . PHP_EOL;
    $csrfMurobi = $klien['murobi']->csrf('/portal/notifikasi.php');
    $tandai = $klien['murobi']->request('/portal/notifikasi.php', [
        '_csrf' => $csrfMurobi,
        'aksi' => 'tandai_dibaca',
        'id' => (string) $idNotifikasiMurobi,
    ]);
    $assert(
        $tandai['status'] === 302 && str_contains((string) $tandai['location'], 'notifikasi.php'),
        'WN-4a Tandai dibaca memakai pola POST/Redirect/GET'
    );
    $assert(
        (int) ($db->query('SELECT COUNT(*) AS n FROM notifikasi_outbox WHERE id = ' . $idNotifikasiMurobi . ' AND dibaca_pada IS NOT NULL')?->fetch_assoc()['n'] ?? 0) === 1,
        'WN-4b Notifikasi tercatat sudah dibaca'
    );

    $csrfMurobi = $klien['murobi']->csrf('/portal/notifikasi.php');
    $semua = $klien['murobi']->request('/portal/notifikasi.php', [
        '_csrf' => $csrfMurobi,
        'aksi' => 'tandai_semua',
    ]);
    $assert($semua['status'] === 302, 'WN-4c Tandai semua dibaca mengalihkan kembali ke daftar');
    $assert(
        (int) ($db->query('SELECT COUNT(*) AS n FROM notifikasi_outbox WHERE penerima_user_id = ' . $murobiUserId . " AND kanal = 'InApp' AND dibaca_pada IS NULL")?->fetch_assoc()['n'] ?? -1) === 0,
        'WN-4d Seluruh notifikasi murobi tercatat sudah dibaca'
    );

    // Membuka detail sekaligus menandai dibaca.
    $detail = $klien['murobi']->request('/portal/notifikasi.php?detail=' . $idNotifikasiMurobi);
    $assert($detail['status'] === 200, 'WN-4e Detail notifikasi dapat dibuka pemiliknya');
    $assert(str_contains($detail['body'], 'Tutup'), 'WN-4f Panel detail notifikasi ditampilkan');

    // ------------------------------------------------ WN-5 otorisasi silang
    echo PHP_EOL . '=== WN-5. Otorisasi silang ===' . PHP_EOL;
    $silang = $klien['ortu']->request('/portal/notifikasi.php?detail=' . $idNotifikasiMurobi);
    $assert(
        $silang['status'] === 200 && str_contains($silang['body'], 'Notifikasi tidak dapat dibuka'),
        'WN-5a Orang tua melihat pesan penolakan, bukan isi notifikasi murobi'
    );
    $assert(
        !str_contains($silang['body'], 'menunggu keputusan Anda'),
        'WN-5b Isi notifikasi milik murobi tidak bocor ke halaman orang tua'
    );
    $csrfOrtu = $klien['ortu']->csrf('/portal/notifikasi.php');
    $silangTandai = $klien['ortu']->request('/portal/notifikasi.php', [
        '_csrf' => $csrfOrtu,
        'aksi' => 'tandai_dibaca',
        'id' => (string) $idNotifikasiMurobi,
    ]);
    $assert($silangTandai['status'] === 302, 'WN-5c Percobaan menandai notifikasi orang lain dialihkan');
    $pesanGagal = $klien['ortu']->request('/portal/notifikasi.php');
    $assert(
        str_contains($pesanGagal['body'], 'tidak berhak'),
        'WN-5d Pesan penolakan ditampilkan setelah percobaan lintas pengguna'
    );

    // ------------------------------------------------------------ WN-6 CSRF
    echo PHP_EOL . '=== WN-6. CSRF ===' . PHP_EOL;
    $tanpaCsrf = $klien['murobi']->request('/portal/notifikasi.php', ['aksi' => 'tandai_semua']);
    $assert($tanpaCsrf['status'] === 419, 'WN-6a POST tanpa token CSRF ditolak 419');
    $csrfSalah = $klien['admin']->request('/admin/admin_notifikasi.php', [
        '_csrf' => 'token-palsu',
        'aksi' => 'sakelar',
        'kanal' => 'Push',
        'aktif' => '1',
    ]);
    $assert($csrfSalah['status'] === 419, 'WN-6b Panel admin menolak token CSRF yang salah');

    // -------------------------------------------------------- WN-7 panel admin
    echo PHP_EOL . '=== WN-7. Panel kanal admin ===' . PHP_EOL;
    foreach (['pengurus', 'murobi', 'ortu'] as $alias) {
        $tolak = $klien[$alias]->request('/admin/admin_notifikasi.php');
        $assert($tolak['status'] === 403, 'WN-7a ' . $alias . ' ditolak 403 pada panel kanal admin');
    }
    $panel = $klien['admin']->request('/admin/admin_notifikasi.php');
    $assert($panel['status'] === 200, 'WN-7b Admin dapat membuka panel kanal');
    foreach ([
        'Kanal Notifikasi', 'Periksa konfigurasi', 'Kirim pesan uji',
        'Pengiriman gagal', 'Audit perubahan kanal',
    ] as $bagian) {
        $assert(str_contains($panel['body'], $bagian), 'WN-7c Panel memuat bagian "' . $bagian . '"');
    }

    $csrfAdmin = $klien['admin']->csrf('/admin/admin_notifikasi.php');
    $nyalakanPush = $klien['admin']->request('/admin/admin_notifikasi.php', [
        '_csrf' => $csrfAdmin,
        'aksi' => 'sakelar',
        'kanal' => 'Push',
        'aktif' => '1',
    ]);
    $assert($nyalakanPush['status'] === 302, 'WN-7d Sakelar kanal memakai POST/Redirect/GET');
    $assert($settings->current()['push_enabled'] === true, 'WN-7e Sakelar push tersimpan');

    $csrfAdmin = $klien['admin']->csrf('/admin/admin_notifikasi.php');
    $klien['admin']->request('/admin/admin_notifikasi.php', [
        '_csrf' => $csrfAdmin,
        'aksi' => 'sakelar',
        'kanal' => 'Push',
        'aktif' => '0',
    ]);
    $assert($settings->current()['push_enabled'] === false, 'WN-7f Sakelar push dapat dimatikan kembali');

    $csrfAdmin = $klien['admin']->csrf('/admin/admin_notifikasi.php');
    $nyalakanWa = $klien['admin']->request('/admin/admin_notifikasi.php', [
        '_csrf' => $csrfAdmin,
        'aksi' => 'sakelar',
        'kanal' => 'WhatsApp',
        'aktif' => '1',
    ]);
    $assert($nyalakanWa['status'] === 302, 'WN-7g Permintaan menyalakan WhatsApp diproses');
    $assert(
        $settings->current()['whatsapp_enabled'] === false,
        'WN-7h WhatsApp tetap mati karena pemeriksaan konfigurasi belum lulus'
    );
    $panelSetelah = $klien['admin']->request('/admin/admin_notifikasi.php');
    $assert(
        str_contains($panelSetelah['body'], 'tidak dapat dinyalakan') || str_contains($panelSetelah['body'], 'belum lulus'),
        'WN-7i Alasan penolakan WhatsApp ditampilkan kepada admin'
    );

    // ---------------------------------------------- WN-8 & WN-9 keamanan panel
    echo PHP_EOL . '=== WN-8/WN-9. Keamanan tampilan ===' . PHP_EOL;
    $assert(
        !str_contains($panelSetelah['body'], 'ExponentPushToken'),
        'WN-8a Panel admin tidak menampilkan token perangkat'
    );
    $assert(
        !preg_match('/PUSH_TOKEN_KEY\s*[=:]\s*\S/', $panelSetelah['body']),
        'WN-8b Panel admin tidak menampilkan NILAI environment'
    );
    $assert(
        str_contains($panelSetelah['body'], 'WHATSAPP_API_TOKEN'),
        'WN-8c Panel admin menampilkan NAMA environment yang dibutuhkan'
    );
    $assert(
        str_contains($panelSetelah['body'], 'Selalu aktif'),
        'WN-9a Kanal in-app ditandai selalu aktif dan tidak dapat dimatikan'
    );
    $csrfAdmin = $klien['admin']->csrf('/admin/admin_notifikasi.php');
    $matikanInApp = $klien['admin']->request('/admin/admin_notifikasi.php', [
        '_csrf' => $csrfAdmin,
        'aksi' => 'sakelar',
        'kanal' => 'InApp',
        'aktif' => '0',
    ]);
    $assert($matikanInApp['status'] === 302, 'WN-9b Permintaan mematikan in-app diproses dan dialihkan');
    $assert(
        $settings->current()['inapp_enabled'] === true,
        'WN-9c Notifikasi in-app tetap aktif apa pun yang diminta'
    );
} catch (Throwable $exception) {
    $failures[] = 'Kesalahan tak terduga: ' . $exception->getMessage();
    echo '[gagal] ' . $exception->getMessage() . ' @ ' . $exception->getFile() . ':' . $exception->getLine() . PHP_EOL;
} finally {
    if (is_resource($server)) {
        // SIGTERM lebih dulu, lalu SIGKILL bila masih hidup: server yang
        // tertinggal akan menyesatkan putaran pengujian berikutnya.
        proc_terminate($server);
        for ($i = 0; $i < 20; $i++) {
            $status = proc_get_status($server);
            if (($status['running'] ?? false) !== true) {
                break;
            }
            usleep(100000);
        }
        if ((proc_get_status($server)['running'] ?? false) === true) {
            proc_terminate($server, 9);
        }
        proc_close($server);
    }

    try {
        $settings->setWhatsappEnabled(false, $adminId ?? 0);
        $settings->recordWhatsappCheck(
            $pengaturanAwal['whatsapp_check_status'],
            (string) ($pengaturanAwal['whatsapp_check_pesan'] ?? 'Dipulihkan setelah pengujian.'),
            $pengaturanAwal['whatsapp_provider'],
            $adminId ?? 0
        );
        if ($pengaturanAwal['whatsapp_enabled']) {
            $settings->setWhatsappEnabled(true, $adminId ?? 0, (string) $pengaturanAwal['whatsapp_provider']);
        }
        $settings->setPushEnabled($pengaturanAwal['push_enabled'], $adminId ?? 0);
    } catch (Throwable $exception) {
        echo '[perhatian] Pengaturan kanal tidak dapat dipulihkan: ' . $exception->getMessage() . PHP_EOL;
    }

    $db->query('SET FOREIGN_KEY_CHECKS=0');
    $ids = array_values(array_filter(array_map('intval', $dibuat['izin'])));
    if ($ids !== []) {
        $daftar = implode(',', $ids);
        $db->query('DELETE FROM notifikasi_percobaan WHERE outbox_id IN (SELECT id FROM notifikasi_outbox WHERE pengajuan_id IN (' . $daftar . '))');
        $db->query('DELETE FROM notifikasi_outbox WHERE pengajuan_id IN (' . $daftar . ')');
        $db->query("DELETE FROM audit_logs WHERE entity_type = 'izin_pengajuan' AND entity_id IN (" . $daftar . ')');
        $db->query('DELETE FROM izin_keputusan WHERE pengajuan_id IN (' . $daftar . ')');
        $db->query('DELETE FROM izin_riwayat_status WHERE pengajuan_id IN (' . $daftar . ')');
        $db->query('DELETE FROM izin_idempotency_keys WHERE pengajuan_id IN (' . $daftar . ')');
        $db->query('DELETE FROM izin_pengajuan WHERE id IN (' . $daftar . ')');
    }
    $db->query('DELETE FROM notifikasi_pengaturan_audit WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)');
    $db->query("DELETE FROM audit_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE) AND action LIKE 'notifikasi.%'");
    $db->query('SET FOREIGN_KEY_CHECKS=1');
    echo '[bersih] Fixture smoke test Fase 4 dihapus.' . PHP_EOL;
}

exit($failures === [] ? 0 : 1);
