<?php

declare(strict_types=1);

/**
 * Pemeriksaan statis V2 Fase 4.
 *
 * Tidak memerlukan basis data. Fokus:
 *   1. migrasi 008 aditif, idempoten, dan memiliki rollback berpasangan;
 *   2. endpoint notifikasi terpasang dan selalu melewati NotificationApiService;
 *   3. notifikasi in-app tidak dapat dimatikan dan tidak bergantung penyedia;
 *   4. WhatsApp default mati dan hanya dapat menyala setelah pemeriksaan lulus;
 *   5. isi push/WhatsApp tidak memuat alasan izin, catatan, atau data pribadi;
 *   6. `SafeError` benar-benar menyamarkan token, nomor, dan credential;
 *   7. deduplikasi peristiwa/kanal/penerima dan retry tanpa baris baru;
 *   8. worker aman diulang, punya lock, backoff terbatas, dan mode uji coba;
 *   9. tidak ada secret, credential, atau data produksi pada repositori;
 *  10. aplikasi Expo tetap SDK 57 (tanpa upgrade) dengan expo-notifications 57;
 *  11. lint sintaks seluruh berkas PHP baru/diubah.
 *
 * Jalankan:
 *   MOBILE_APP_ROOT=/path/ke/alhasanApps php tests/v2_phase4_static.php
 */

$root = dirname(__DIR__);
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? '[lulus] ' : '[gagal] ') . $message . PHP_EOL;
    if (!$condition) {
        $failures[] = $message;
    }
};
$source = static fn (string $path): string => (string) @file_get_contents($root . '/' . $path);

$mobileRoot = getenv('MOBILE_APP_ROOT') ?: dirname($root, 4) . '/alhasanApps';
$mobile = static fn (string $path): string => (string) @file_get_contents($mobileRoot . '/' . $path);
$adaMobile = is_dir($mobileRoot . '/src');

/**
 * Membuang komentar sebelum memeriksa larangan.
 *
 * Tanpa ini, kalimat dokumentasi seperti "migrasi ini tidak memakai DROP TABLE"
 * akan dianggap sebagai pelanggaran. Yang diperiksa harus KODE, bukan komentar.
 */
$tanpaKomentarSql = static function (string $sql): string {
    return (string) preg_replace('/^\s*--.*$/m', '', $sql);
};
$tanpaKomentarPhp = static function (string $php): string {
    if (trim($php) === '') {
        return '';
    }
    $bersih = '';
    foreach (token_get_all($php) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $bersih .= $token[1];
            continue;
        }
        $bersih .= $token;
    }

    return $bersih;
};
$tanpaKomentarTs = static function (string $ts): string {
    $ts = (string) preg_replace('#/\*.*?\*/#s', '', $ts);

    return (string) preg_replace('#^\s*//.*$#m', '', $ts);
};

// Autoload ringan agar perilaku SafeError dapat diuji tanpa basis data.
spl_autoload_register(static function (string $class) use ($root): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $path = $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

// ---------------------------------------------------------------------------
// 1. Migrasi dan rollback
// ---------------------------------------------------------------------------
echo PHP_EOL . '=== 1. Migrasi 008 dan rollback ===' . PHP_EOL;

$migrasi = $source('database/migrations/008_v2_phase4_notifikasi_push_whatsapp.sql');
$rollback = $source('database/rollbacks/008_v2_phase4_notifikasi_push_whatsapp.sql');

$assert($migrasi !== '', 'Migrasi 008 Fase 4 tersedia');
$assert($rollback !== '', 'Rollback 008 Fase 4 tersedia');

// Yang diperiksa adalah PERNYATAAN SQL, bukan komentar penjelasnya.
$migrasiKode = $tanpaKomentarSql($migrasi);
$rollbackKode = $tanpaKomentarSql($rollback);

// Aditif: tidak boleh menghapus atau menimpa data bisnis Fase 1-3.
foreach (['DROP TABLE', 'TRUNCATE', 'DELETE FROM', 'DROP DATABASE'] as $terlarang) {
    $assert(
        stripos($migrasiKode, $terlarang) === false,
        'Migrasi 008 tidak memakai ' . $terlarang
    );
}
$assert(
    !preg_match('/\bDROP\s+COLUMN\b/i', $migrasiKode),
    'Migrasi 008 tidak melepas kolom mana pun'
);
$assert(
    // Dua bentuk UPDATE yang diizinkan dan tidak menyentuh data bisnis:
    //   - `ON UPDATE CURRENT_TIMESTAMP` pada definisi kolom timestamp,
    //   - `ON DUPLICATE KEY UPDATE` saat menyemai baris sewa worker.
    // Selain itu, migrasi tidak boleh memperbarui satu baris pun.
    preg_match_all('/\bUPDATE\b/i', $migrasiKode)
        === preg_match_all('/ON DUPLICATE KEY UPDATE|ON UPDATE CURRENT_TIMESTAMP/i', $migrasiKode),
    'Migrasi 008 tidak memperbarui satu pun baris data bisnis'
);
$assert(
    !preg_match('/\b(izin_pengajuan|izin_keputusan|izin_riwayat_status|perizinan|audit_logs)\b/i', $rollbackKode),
    'Rollback 008 tidak menyentuh tabel perizinan maupun audit Fase 1-3'
);

// Idempoten: setiap ALTER dibungkus pemeriksaan INFORMATION_SCHEMA.
$jumlahAlter = preg_match_all('/ALTER TABLE/i', $migrasiKode);
$jumlahPenjaga = preg_match_all('/information_schema/i', $migrasiKode);
$assert(
    $jumlahPenjaga >= $jumlahAlter,
    'Setiap ALTER pada migrasi 008 dibungkus pemeriksaan INFORMATION_SCHEMA (' . $jumlahAlter . ' ALTER, ' . $jumlahPenjaga . ' penjaga)'
);
$assert(
    substr_count($migrasiKode, 'CREATE TABLE IF NOT EXISTS') === substr_count($migrasiKode, 'CREATE TABLE'),
    'Seluruh CREATE TABLE pada migrasi 008 memakai IF NOT EXISTS'
);
$assert(
    substr_count($rollbackKode, 'DROP TABLE IF EXISTS') === substr_count($rollbackKode, 'DROP TABLE'),
    'Seluruh DROP TABLE pada rollback memakai IF EXISTS'
);

// Objek yang wajib ada.
foreach ([
    'notifikasi_percobaan',
    'notifikasi_pengaturan_audit',
    'notifikasi_worker_lock',
    'gagal_permanen',
    'tersedia_pada',
    'locked_by',
    'locked_until',
    'push_aktif',
    'device_id',
    'alasan_pencabutan',
    'pengaturan_notifikasi_inapp_check',
] as $objek) {
    $assert(str_contains($migrasi, $objek), 'Migrasi 008 menambahkan ' . $objek);
}
$assert(
    str_contains($migrasi, 'CHECK (inapp_enabled = 1)'),
    'Basis data menegakkan notifikasi in-app tidak dapat dimatikan'
);
$assert(
    !preg_match('/(api[_-]?key|secret|password|token)\s+VARCHAR/i', $migrasiKode),
    'Skema Fase 4 tidak menyediakan tempat penyimpanan credential penyedia'
);

// ---------------------------------------------------------------------------
// 2. Endpoint REST
// ---------------------------------------------------------------------------
echo PHP_EOL . '=== 2. Endpoint notifikasi ===' . PHP_EOL;

$router = $source('api/v1/index.php');
foreach ([
    "'/notifikasi'",
    "'/notifikasi/belum-dibaca'",
    "'/notifikasi/dibaca-semua'",
    '#^/notifikasi/(\d+)$#',
    '#^/notifikasi/(\d+)/dibaca$#',
    "'/notifikasi/perangkat'",
    "'/notifikasi/perangkat/pencabutan'",
    '#^/notifikasi/perangkat/(\d+)/push$#',
    "'/notifikasi/admin/status'",
    "'/notifikasi/admin/pemeriksaan'",
    "'/notifikasi/admin/sakelar'",
    "'/notifikasi/admin/pesan-uji'",
    "'/notifikasi/admin/kegagalan'",
    '#^/notifikasi/admin/kegagalan/(\d+)/coba-ulang$#',
    "'/notifikasi/admin/worker'",
    "'/notifikasi/admin/audit'",
] as $rute) {
    $assert(str_contains($router, $rute), 'Rute Fase 4 terpasang: ' . $rute);
}
$assert(
    !str_contains($router, 'notification_center_service()')
        && !str_contains($router, 'notification_admin_service()')
        && !str_contains($router, 'push_device_service()'),
    'Router tidak memotong jalur: seluruh notifikasi melewati notification_api_service()'
);
$assert(
    // Rute literal `/notifikasi/perangkat` harus terdaftar sebelum pola id
    // numerik agar tidak pernah tertangkap sebagai detail notifikasi.
    strpos($router, "'/notifikasi/perangkat'") < strpos($router, '#^/notifikasi/(\d+)$#'),
    'Rute perangkat didaftarkan sebelum pola id numerik'
);
$assert(
    str_contains($router, 'revokeOnLogout($user, $pushToken)'),
    'Logout mencabut registrasi perangkat push'
);

// ---------------------------------------------------------------------------
// 3. In-app selalu tersedia
// ---------------------------------------------------------------------------
echo PHP_EOL . '=== 3. Notifikasi in-app sebagai sumber status utama ===' . PHP_EOL;

$service = $source('app/Notification/NotificationService.php');
$assert(
    str_contains($service, '// 1. In-app — selalu, tanpa syarat kanal apa pun.'),
    'In-app dibuat tanpa memeriksa sakelar kanal mana pun'
);
$assert(
    preg_match('/pushAktif\s*&&/', $service) === 1 && preg_match('/waAktif\s*&&/', $service) === 1,
    'Push dan WhatsApp diantrekan hanya ketika kanalnya menyala'
);
$repository = $source('app/Notification/NotificationRepository.php');
$assert(
    str_contains($repository, "\$inApp ? 'Sent' : 'Queued'"),
    'Baris in-app langsung berstatus Sent tanpa menunggu penyedia eksternal'
);
$admin = $source('app/Notification/NotificationAdminService.php');
$assert(
    str_contains($admin, 'tidak dapat dimatikan'),
    'Layanan admin menolak permintaan mematikan kanal in-app'
);
$settings = $source('app/Notification/SettingsRepository.php');
$assert(
    str_contains($settings, "'inapp_enabled' => true"),
    'Pembacaan pengaturan selalu melaporkan in-app aktif'
);

// ---------------------------------------------------------------------------
// 4. WhatsApp opsional dan berpagar
// ---------------------------------------------------------------------------
echo PHP_EOL . '=== 4. WhatsApp opsional ===' . PHP_EOL;

$assert(
    str_contains($settings, "AND whatsapp_check_status = 'Lulus'"),
    'Repositori hanya menyalakan WhatsApp ketika pemeriksaan berstatus Lulus'
);
$assert(
    str_contains($settings, 'AND whatsapp_provider = ?'),
    'Repositori mengikat pemeriksaan Lulus pada penyedia WhatsApp yang sedang dipilih'
);
$assert(
    str_contains($admin, "\$sebelum['whatsapp_check_status'] !== 'Lulus'"),
    'Layanan admin menolak menyalakan WhatsApp sebelum pemeriksaan lulus'
);
$assert(
    str_contains($admin, "\$sebelum['whatsapp_provider']") && str_contains($admin, "\$this->whatsapp->readiness()"),
    'Layanan admin memeriksa ulang identitas penyedia dan kelengkapan konfigurasi saat menyalakan WhatsApp'
);
$assert(
    str_contains($source('database/migrations/006_v2_phase1_perizinan_foundation.sql'), 'pengaturan_notifikasi_whatsapp_check'),
    'Basis data juga menegakkan syarat pemeriksaan lulus (CHECK dari migrasi 006)'
);

$factory = $source('app/Notification/WhatsApp/ProviderFactory.php');
$assert(
    str_contains($factory, "'' , 'none', 'null' => new NullProvider()"),
    'Penyedia default adalah NullProvider (WhatsApp mati, tanpa vendor)'
);
$null = $source('app/Notification/WhatsApp/NullProvider.php');
$assert(
    !preg_match('/curl_init|file_get_contents|fsockopen|stream_context_create/i', $null),
    'NullProvider tidak pernah membuka koneksi jaringan'
);
$fake = $source('app/Notification/WhatsApp/FakeProvider.php');
$assert(
    !preg_match('/curl_init|fsockopen|stream_context_create/i', $fake),
    'Adapter uji tidak pernah membuka koneksi jaringan'
);
$assert(
    str_contains($fake, 'public function mengirimNyata(): bool') && str_contains($fake, 'return false;'),
    'Adapter uji menyatakan dirinya BUKAN pengiriman nyata'
);
$assert(
    str_contains($fake, 'ADAPTER_UJI_DI_PRODUKSI'),
    'Adapter uji menolak dipakai ketika APP_ENV=production'
);
$assert(
    str_contains($admin, 'BUKAN bukti pengiriman WhatsApp nyata'),
    'Panel admin menandai hasil adapter uji sebagai bukan bukti pengiriman nyata'
);
$interface = $source('app/Notification/WhatsApp/WhatsAppProvider.php');
$assert(
    str_contains($interface, 'interface WhatsAppProvider'),
    'Terdapat antarmuka penyedia WhatsApp yang netral vendor'
);
$dispatcher = $source('app/Notification/NotificationDispatcher.php');
$assert(
    str_contains($dispatcher, 'Berhenti SEBELUM klaim maupun koneksi apa pun.'),
    'Worker berhenti sebelum menghubungi penyedia ketika kanal mati'
);

// ---------------------------------------------------------------------------
// 5. Isi notifikasi aman
// ---------------------------------------------------------------------------
echo PHP_EOL . '=== 5. Payload aman ===' . PHP_EOL;

$event = $source('app/Notification/NotificationEvent.php');
foreach (['alasan', 'catatan_pengurus', 'alasan_keputusan', 'alasan_penggantian', 'alasan_koreksi'] as $sensitif) {
    $assert(
        !preg_match('/\$context\[[\'"]' . preg_quote($sensitif, '/') . '[\'"]\]/', $event),
        'Isi notifikasi tidak pernah membaca konteks ' . $sensitif
    );
}
$assert(
    !preg_match('/\$pengajuan\[[\'"](alasan|catatan_pengurus)[\'"]\]/', $service),
    'Pembuat notifikasi tidak pernah membaca alasan atau catatan pengurus'
);
$assert(
    str_contains($event, "if (\$channel !== NotificationChannel::IN_APP)"),
    'Kanal eksternal memakai varian isi ringkas terpisah'
);
$assert(
    str_contains($event, "'tipe' => 'izin'") && str_contains($event, "'pengajuan_id' => \$pengajuanId"),
    'Payload deep link hanya memuat penunjuk sumber daya'
);
$assert(
    str_contains($dispatcher, "array_intersect_key(\$data, array_flip(['tipe', 'event', 'pengajuan_id', 'url']))"),
    'Worker push membatasi payload data ke daftar kunci yang diizinkan'
);
$center = $source('app/Notification/NotificationCenterService.php');
$assert(
    !preg_match('/token|no_hp|phone/i', $tanpaKomentarPhp($center)),
    'Pusat notifikasi pengguna tidak pernah mengembalikan token atau nomor telepon'
);

// ---------------------------------------------------------------------------
// 6. Perilaku SafeError
// ---------------------------------------------------------------------------
echo PHP_EOL . '=== 6. Pembersihan galat (SafeError) ===' . PHP_EOL;

$bersih = \App\Notification\SafeError::message(
    'Gagal kirim ke ExponentPushToken[AbCdEf123456] Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.payload.signature untuk +6281234567890'
);
$assert(!str_contains($bersih, 'AbCdEf123456'), 'SafeError menyamarkan token push Expo');
$assert(!str_contains($bersih, 'eyJhbGciOiJIUzI1NiJ9'), 'SafeError menyamarkan bearer token');
$assert(!str_contains($bersih, '6281234567890'), 'SafeError menyamarkan nomor telepon');
$assert(
    !str_contains(\App\Notification\SafeError::message('{"api_key":"rahasia-sangat-panjang-123456"}'), 'rahasia-sangat-panjang-123456'),
    'SafeError menyamarkan nilai api_key pada JSON'
);
$assert(
    !str_contains(\App\Notification\SafeError::message('https://api.contoh.id/kirim?token=abcdef123456&to=628'), 'abcdef123456'),
    'SafeError menyamarkan credential pada query string'
);
$assert(
    mb_strlen(\App\Notification\SafeError::message(str_repeat('x', 900))) <= \App\Notification\SafeError::MAX_LENGTH,
    'SafeError membatasi panjang pesan agar muat pada kolom error aman'
);
$assert(
    \App\Notification\SafeError::code('Device Not Registered!') === 'DEVICE_NOT_REGISTERED',
    'SafeError menormalkan kode galat menjadi bentuk aman'
);
$scrub = \App\Notification\SafeError::scrub(['authorization' => 'Bearer abc', 'status' => 'ok']);
$assert(
    $scrub['authorization'] === '[disamarkan]' && $scrub['status'] === 'ok',
    'SafeError::scrub menghapus nilai pada kunci sensitif dan mempertahankan sisanya'
);

// ---------------------------------------------------------------------------
// 7. Deduplikasi dan idempotensi
// ---------------------------------------------------------------------------
echo PHP_EOL . '=== 7. Deduplikasi ===' . PHP_EOL;

$assert(
    str_contains($repository, 'ON DUPLICATE KEY UPDATE id = id'),
    'Enqueue memakai ON DUPLICATE KEY sehingga peristiwa yang sama tidak menghasilkan baris kedua'
);
$assert(
    str_contains($repository, '$affected === 1 && $insertId > 0 ? $insertId : null'),
    'Enqueue membedakan baris baru dari duplikat yang diabaikan'
);
$assert(
    str_contains($event, 'public static function key(') && str_contains($event, "':v' . \$version"),
    'Kunci peristiwa deterministik memakai id pengajuan dan versi baris'
);
$assert(
    str_contains($repository, "SET status = 'Queued'") && str_contains($repository, 'public function requeue('),
    'Percobaan ulang mengembalikan baris yang SAMA ke antrean, bukan membuat baris baru'
);
$workflow = $source('app/Izin/IzinWorkflowService.php');
foreach ([
    'NotificationEvent::PENGAJUAN_DIBUAT',
    'NotificationEvent::ROUTING_PERLU_ADMIN',
    'NotificationEvent::MUROBI_DITETAPKAN',
    'NotificationEvent::MUROBI_DITETAPKAN_ULANG',
    'NotificationEvent::KEPUTUSAN_DISETUJUI',
    'NotificationEvent::KEPUTUSAN_DITOLAK',
    'NotificationEvent::KEPUTUSAN_ADMIN_PENGGANTI',
    'NotificationEvent::PEMBATALAN',
    'NotificationEvent::KOREKSI',
] as $peristiwa) {
    $assert(str_contains($workflow, $peristiwa), 'Alur perizinan memicu ' . $peristiwa);
}
$assert(
    substr_count($workflow, '$this->notify(') === 5,
    'Notifikasi dipicu pada kelima mutasi perizinan (create, assign, decide, cancel, correct)'
);
$assert(
    str_contains($service, 'catch (Throwable $exception)') && str_contains($service, 'Notifikasi tidak pernah membatalkan pengajuan atau keputusan.'),
    'Kegagalan notifikasi tidak pernah menggagalkan transaksi perizinan'
);

// ---------------------------------------------------------------------------
// 8. Worker, retry, dan concurrency
// ---------------------------------------------------------------------------
echo PHP_EOL . '=== 8. Worker outbox ===' . PHP_EOL;

$outbox = $source('app/Notification/OutboxRepository.php');
$lock = $source('app/Notification/WorkerLock.php');
$worker = $source('bin/notifikasi_worker.php');

$assert(str_contains($outbox, 'MAX_PERCOBAAN = 5'), 'Retry dibatasi jumlahnya');
$assert(
    str_contains($outbox, 'BACKOFF_BASE') && str_contains($outbox, 'BACKOFF_MAX'),
    'Retry memakai backoff dengan batas atas'
);
$assert(
    str_contains($outbox, 'SET locked_by = ?, locked_until = DATE_ADD(NOW(), INTERVAL ? SECOND)'),
    'Klaim baris memakai satu UPDATE atomik dengan pemilik dan masa berlaku'
);
$assert(
    str_contains($outbox, 'WHERE id = ? AND locked_by = ?'),
    'Penyelesaian baris hanya berlaku bagi worker pemiliknya'
);
$assert(
    str_contains($lock, 'WHERE nama = ? AND kedaluwarsa_pada <= NOW()'),
    'Sewa worker hanya dapat diambil satu proses pada satu waktu'
);
$assert(
    str_contains($lock, 'public function release()') && str_contains($dispatcher, '} finally {'),
    'Sewa worker selalu dilepas walaupun terjadi galat'
);
$assert(
    str_contains($dispatcher, '$this->lock->renew()')
        && str_contains($dispatcher, '$this->outbox->renewClaims($owner)'),
    'Worker memperpanjang sewa proses dan klaim baris sebelum setiap pengiriman'
);
$assert(
    str_contains($worker, "PHP_SAPI !== 'cli'"),
    'Worker hanya dapat dijalankan dari CLI'
);
$assert(
    str_contains($worker, '--uji-coba') && str_contains($worker, '--status'),
    'Worker menyediakan mode manual yang aman (uji coba dan status)'
);
$assert(
    !preg_match('/echo[^;]*\$token|print[^;]*\$token|var_dump/i', $worker . $dispatcher),
    'Worker tidak pernah mencetak token atau membuang isi variabel'
);
$assert(
    str_contains($outbox, 'notifikasi_percobaan'),
    'Setiap percobaan pengiriman dicatat pada tabel riwayat percobaan'
);
$assert(
    str_contains($outbox, "'KANAL_NONAKTIF'"),
    'Antrean kanal yang dimatikan ditandai jelas dan tidak diambil worker'
);

// ---------------------------------------------------------------------------
// 9. Otorisasi notifikasi
// ---------------------------------------------------------------------------
echo PHP_EOL . '=== 9. Otorisasi ===' . PHP_EOL;

foreach ([
    'listForUser', 'unreadCount', 'findForUser', 'markRead', 'markAllRead',
] as $method) {
    $assert(
        preg_match('/function ' . $method . '\((?:[^)]*)int \$(?:id, int \$)?userId/', $repository) === 1
            || str_contains($repository, 'function ' . $method . '(int $userId'),
        'Query ' . $method . '() wajib menyertakan penerima_user_id'
    );
}
$assert(
    substr_count($repository, 'penerima_user_id = ?') >= 6,
    'Setiap query pusat notifikasi menyaring berdasarkan penerima'
);
$assert(
    str_contains($center, 'throw NotificationException::forbidden();'),
    'Notifikasi milik pengguna lain dijawab 403, bukan isinya'
);
$devices = $source('app/Notification/DeviceRepository.php');
$assert(
    str_contains($devices, 'WHERE id = ? AND user_id = ?'),
    'Pencabutan perangkat selalu dibatasi pemiliknya'
);
$assert(
    str_contains($admin, 'private function requireAdmin(array $user): void'),
    'Seluruh operasi panel kanal memeriksa capability admin di server'
);
$resolver = $source('app/Notification/RecipientResolver.php');
$assert(
    str_contains($resolver, 'murobi_assignments') && str_contains($resolver, 'santri_wali')
        && str_contains($resolver, 'u.pengurus_id'),
    'Penerima ditentukan dari relasi nyata (penugasan murobi, relasi wali, relasi pengurus)'
);
$assert(
    str_contains($resolver, "ma.is_active = 1") && str_contains($resolver, "ta.status = 'Aktif'"),
    'Guru tanpa penugasan murobi aktif tidak pernah menjadi penerima'
);
$assert(
    str_contains($resolver, 'LEFT JOIN kelas kl')
        && str_contains($resolver, "ma.target_type = 'Kamar'")
        && str_contains($resolver, 'kl.id IS NOT NULL'),
    'Penugasan murobi berbasis kelas hanya sah ketika kelas masih aktif'
);
$assert(
    substr_count($devices, 'JOIN users u ON u.id = p.user_id AND u.is_active = 1') >= 2,
    'Token perangkat hanya diambil untuk akun pengguna yang masih aktif'
);
$accountService = $source('app/Account/AccountService.php');
$assert(
    str_contains($accountService, 'revokeAllForUser') && str_contains($accountService, 'akun_dinonaktifkan'),
    'Penonaktifan akun langsung mencabut seluruh token perangkat pengguna'
);

// ---------------------------------------------------------------------------
// 10. Kebersihan repositori
// ---------------------------------------------------------------------------
echo PHP_EOL . '=== 10. Tidak ada secret atau data produksi ===' . PHP_EOL;

$berkasFase4 = array_merge(
    glob($root . '/app/Notification/*.php') ?: [],
    glob($root . '/app/Notification/*/*.php') ?: [],
    [
        $root . '/app/Api/NotificationApiService.php',
        $root . '/bin/notifikasi_worker.php',
        $root . '/bin/v2_phase4_preflight.php',
        $root . '/bin/v2_phase4_verify.php',
        $root . '/portal/notifikasi.php',
        $root . '/admin/admin_notifikasi.php',
    ]
);
foreach ($berkasFase4 as $path) {
    // Komentar dibuang lebih dulu: contoh penulisan seperti
    // `ExponentPushToken[...]` pada dokumentasi bukan credential.
    $isi = $tanpaKomentarPhp((string) @file_get_contents($path));
    $nama = str_replace($root . '/', '', $path);
    $assert(
        !preg_match('/ExponentPushToken\[[A-Za-z0-9._\-]{6,}\]/', $isi),
        'Tidak ada token push sungguhan tertanam pada ' . $nama
    );
    $assert(
        !preg_match('/(api[_-]?key|secret|password|token)\s*=\s*[\'"][A-Za-z0-9._\-]{16,}[\'"]/i', $isi),
        'Tidak ada credential tertanam pada ' . $nama
    );
}
$envContoh = $source('.env.example');
foreach (['PUSH_TOKEN_KEY', 'WHATSAPP_PROVIDER', 'WHATSAPP_API_URL', 'WHATSAPP_API_TOKEN'] as $nama) {
    $assert(str_contains($envContoh, $nama), '.env.example mendokumentasikan ' . $nama);
    $assert(
        preg_match('/^' . preg_quote($nama, '/') . '=\s*$/m', $envContoh) === 1,
        '.env.example tidak memuat NILAI untuk ' . $nama
    );
}
$assert(
    str_contains($source('.gitignore'), 'storage/backups/'),
    'Hasil preflight (backup dan manifest) tidak masuk repositori'
);
$assert(
    !preg_match('/k1807225|webalhasan\.sql/i', implode('', array_map(
        static fn (string $p): string => (string) @file_get_contents($p),
        $berkasFase4
    ))),
    'Tidak ada berkas Fase 4 yang menyentuh dump produksi'
);

// ---------------------------------------------------------------------------
// 11. Aplikasi Expo
// ---------------------------------------------------------------------------
echo PHP_EOL . '=== 11. Aplikasi Expo SDK 57 ===' . PHP_EOL;

if (!$adaMobile) {
    $assert(false, 'MOBILE_APP_ROOT tidak ditemukan: ' . $mobileRoot);
} else {
    $package = $mobile('package.json');
    $packageData = json_decode($package, true);
    $expoVersion = (string) ($packageData['dependencies']['expo'] ?? '');
    $reactNativeVersion = (string) ($packageData['dependencies']['react-native'] ?? '');
    $notifVersion = (string) ($packageData['dependencies']['expo-notifications'] ?? '');

    $assert(
        preg_match('/^[~^]?57\./', $expoVersion) === 1
            && preg_match('/^[~^]?0\.86\./', $reactNativeVersion) === 1,
        'Expo SDK 57 dan React Native 0.86 TIDAK di-upgrade'
    );
    $assert(
        preg_match('/^[~^]?57\./', $notifVersion) === 1,
        'expo-notifications memakai versi selaras SDK 57 (' . $notifVersion . ')'
    );
    $assert(
        str_contains($mobile('app.json'), '"expo-notifications"'),
        'Plugin expo-notifications terdaftar pada app.json'
    );
    $assert(
        str_contains($mobile('app.json'), '"defaultChannel": "perizinan"'),
        'Kanal Android bawaan diselaraskan dengan pengirim server'
    );

    $registrasi = $mobile('src/notifications/push-registration.ts');
    $assert(
        str_contains($registrasi, "ANDROID_CHANNEL_ID = 'perizinan'"),
        'Aplikasi membuat kanal Android bernama sama dengan channelId server'
    );
    $assert(
        str_contains($registrasi, 'setNotificationChannelAsync')
            && strpos($registrasi, 'setNotificationChannelAsync') < strpos($registrasi, 'requestPermissionsAsync'),
        'Kanal Android dibuat sebelum permintaan izin (urutan SDK 57)'
    );
    $assert(
        str_contains($registrasi, 'getPermissionsAsync') && str_contains($registrasi, 'existing.canAskAgain'),
        'Izin tidak diminta berulang tanpa kebutuhan'
    );
    $assert(
        str_contains($registrasi, "SecureStore.getItemAsync(INSTALLATION_ID_KEY)")
            && str_contains($registrasi, "SecureStore.setItemAsync(INSTALLATION_ID_KEY"),
        'Identitas instalasi perangkat disimpan stabil di SecureStore, bukan hanya selama sesi aplikasi'
    );
    $assert(
        str_contains($registrasi, 'if (!minta)')
            && strpos($registrasi, 'if (!minta)') < strpos($registrasi, 'requestPermissionsAsync'),
        'Registrasi otomatis tidak memunculkan dialog izin tanpa tindakan pengguna'
    );
    $assert(
        str_contains($registrasi, 'getExpoPushTokenAsync({ projectId: id })'),
        'Token diambil dengan projectId sesuai dokumentasi SDK 57'
    );
    $assert(
        str_contains($registrasi, 'Device.isDevice'),
        'Registrasi menolak simulator/emulator dan menjelaskan alasannya'
    );
    $assert(
        str_contains($registrasi, 'development build'),
        'Kebutuhan development build didokumentasikan pada kode registrasi'
    );

    $context = $mobile('src/notifications/notification-context.tsx');
    $kodeContext = $tanpaKomentarTs($context);
    $assert(
        str_contains($kodeContext, 'shouldShowBanner') && str_contains($kodeContext, 'shouldShowList')
            && !str_contains($kodeContext, 'shouldShowAlert'),
        'Handler foreground memakai API SDK 57 (shouldShowBanner/shouldShowList)'
    );
    $assert(
        str_contains($context, 'addNotificationReceivedListener')
            && str_contains($context, 'addNotificationResponseReceivedListener')
            && str_contains($context, 'useLastNotificationResponse'),
        'Aplikasi menangani foreground, ketukan, dan cold start dari notifikasi'
    );
    $assert(
        str_contains($context, "router.push({ pathname: '/izin/[id]'"),
        'Deep link membuka layar detail izin'
    );
    $assert(
        str_contains($context, 'tertunda.current = id'),
        'Deep link sebelum login ditunda sampai autentikasi berhasil'
    );

    $penyimpanan = $mobile('src/notifications/push-token-storage.ts');
    $assert(
        str_contains($penyimpanan, 'SecureStore'),
        'Token push perangkat disimpan pada SecureStore'
    );
    foreach ([
        'src/notifications/push-registration.ts',
        'src/notifications/notification-context.tsx',
        'src/notifications/push-token-storage.ts',
        'src/app/notifikasi/[id].tsx',
        'src/app/notifikasi/perangkat.tsx',
        'src/app/(app)/(notifikasi)/notifikasi.tsx',
    ] as $berkas) {
        $isi = $mobile($berkas);
        $assert($isi !== '', 'Berkas mobile Fase 4 tersedia: ' . $berkas);
        $assert(
            !preg_match('/console\.(log|warn|error|info)\s*\(/', $isi),
            'Tidak ada pencatatan konsol yang dapat membocorkan token pada ' . $berkas
        );
    }
    $assert(
        !preg_match('/ExponentPushToken\[[A-Za-z0-9._\-]+\]/', $mobile('src/notifications/push-registration.ts')),
        'Tidak ada token perangkat sungguhan tertanam pada bundle'
    );
    $assert(
        !preg_match('/(WHATSAPP_API_TOKEN|PUSH_TOKEN_KEY|EXPO_ACCESS_TOKEN)/', $mobile('app.config.ts') . $mobile('app.json')),
        'Secret server tidak pernah masuk konfigurasi bundle mobile'
    );

    $layar = $mobile('src/app/(app)/(notifikasi)/notifikasi.tsx');
    foreach (['LoadingState', 'EmptyState', 'ErrorState', 'Tandai semua dibaca', 'Halaman'] as $bagian) {
        $assert(str_contains($layar, $bagian), 'Layar notifikasi menyediakan ' . $bagian);
    }
    $assert(
        str_contains($mobile('src/components/app-tabs.tsx'), 'NativeTabs.Trigger.Badge'),
        'Tab notifikasi menampilkan lencana jumlah belum dibaca'
    );
    $assert(
        str_contains($mobile('src/api/client.ts'), 'perangkatCabut') && str_contains($mobile('src/auth/auth-context.tsx'), 'pushTokenStorage.clear()'),
        'Logout mencabut registrasi perangkat dan membersihkan token lokal'
    );
}

// ---------------------------------------------------------------------------
// 12. Halaman web
// ---------------------------------------------------------------------------
echo PHP_EOL . '=== 12. Halaman web ===' . PHP_EOL;

$pusat = $source('portal/notifikasi.php');
foreach (['jumlah_belum_dibaca', 'tandai_dibaca', 'tandai_semua', 'portal_pagination', 'Belum ada notifikasi'] as $bagian) {
    $assert(str_contains($pusat, $bagian), 'Pusat notifikasi web menyediakan ' . $bagian);
}
$assert(
    str_contains($pusat, 'portal_csrf()'),
    'Seluruh mutasi pusat notifikasi web dilindungi CSRF'
);
$panel = $source('admin/admin_notifikasi.php');
foreach ([
    'Periksa konfigurasi', 'Kirim pesan uji', 'Pengiriman gagal', 'Coba ulang',
    'Audit perubahan kanal', 'Jalankan worker sekali', 'Uji coba (tanpa kirim)',
] as $bagian) {
    $assert(str_contains($panel, $bagian), 'Panel admin menyediakan ' . $bagian);
}
$assert(
    str_contains($panel, 'Environment yang dibutuhkan (nama saja)'),
    'Panel admin hanya menampilkan NAMA environment, bukan nilainya'
);
$assert(
    str_contains($source('portal/_ui.php'), 'portal_unread_count($user)'),
    'Navigasi portal menampilkan lencana notifikasi belum dibaca'
);

// ---------------------------------------------------------------------------
// 13. Lint PHP
// ---------------------------------------------------------------------------
echo PHP_EOL . '=== 13. php -l berkas Fase 4 ===' . PHP_EOL;

$berkasLint = array_merge($berkasFase4, [
    $root . '/app/bootstrap.php',
    $root . '/app/Izin/IzinWorkflowService.php',
    $root . '/api/v1/index.php',
    $root . '/portal/_ui.php',
    $root . '/admin/sidebar.php',
    $root . '/tests/v2_phase4_static.php',
]);
foreach (array_unique($berkasLint) as $path) {
    if (!is_file($path)) {
        $assert(false, 'Berkas tidak ditemukan untuk lint: ' . $path);
        continue;
    }
    $output = [];
    $status = 0;
    exec(escapeshellcmd(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $output, $status);
    $assert($status === 0, 'php -l lulus: ' . str_replace($root . '/', '', $path));
}

// ---------------------------------------------------------------------------
echo PHP_EOL;
if ($failures === []) {
    echo 'SELURUH PEMERIKSAAN STATIS FASE 4 LULUS.' . PHP_EOL;
    exit(0);
}
echo 'PEMERIKSAAN STATIS FASE 4 GAGAL (' . count($failures) . '):' . PHP_EOL;
foreach ($failures as $failure) {
    echo '- ' . $failure . PHP_EOL;
}
exit(1);
