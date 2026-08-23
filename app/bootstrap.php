<?php

declare(strict_types=1);

use App\Account\AccountRepository;
use App\Account\AccountService;
use App\Account\PerizinanAccountRepository;
use App\Account\PerizinanAccountService;
use App\Audit\AuditLogger;
use App\Api\ApiAuthRepository;
use App\Api\ApiAuthService;
use App\Api\IzinApiService;
use App\Api\NotificationApiService;
use App\Api\TeacherRepository;
use App\Api\TeacherService;
use App\Auth\ApiTokenAuthenticator;
use App\Auth\AuthRepository;
use App\Auth\Authorization;
use App\Auth\Capabilities;
use App\Auth\LandingRouter;
use App\Auth\PortalGuard;
use App\Auth\TokenHasher;
use App\Database\Connection;
use App\Http\Session;
use App\Izin\IzinIdempotency;
use App\Izin\IzinRepository;
use App\Izin\IzinRouter;
use App\Izin\IzinService;
use App\Izin\IzinWorkflowService;
use App\Izin\IzinWriteRepository;
use App\Izin\PembimbingRepository;
use App\Izin\PembimbingService;
use App\MasterData\MasterDataRepository;
use App\MasterData\MasterDataService;
use App\Notification\DeviceRepository;
use App\Notification\DeviceService;
use App\Notification\NotificationAdminService;
use App\Notification\NotificationCenterService;
use App\Notification\NotificationDispatcher;
use App\Notification\NotificationRepository;
use App\Notification\NotificationService;
use App\Notification\OutboxRepository;
use App\Notification\Push\ExpoPushClient;
use App\Notification\PushTokenProtector;
use App\Notification\RecipientResolver;
use App\Notification\SettingsRepository as NotificationSettingsRepository;
use App\Notification\WhatsApp\ProviderFactory as WhatsAppProviderFactory;
use App\Notification\WhatsApp\WhatsAppProvider;
use App\Notification\WorkerLock;
use App\Report\ReportRepository;
use App\Report\ReportService;
use App\Schedule\ScheduleRepository;
use App\Schedule\ScheduleService;
use App\Support\Env;

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = APP_ROOT . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

Env::load(APP_ROOT . '/.env');

$GLOBALS['app_config'] = [
    'env' => Env::get('APP_ENV', 'production'),
    'debug' => Env::bool('APP_DEBUG', false),
    'url' => rtrim((string) Env::get('APP_URL', ''), '/'),
    'base_path' => '/' . trim((string) Env::get('APP_BASE_PATH', ''), '/'),
    'timezone' => Env::get('APP_TIMEZONE', 'Asia/Jakarta'),
    'database' => [
        'host' => Env::get('DB_HOST', '127.0.0.1'),
        'port' => (int) Env::get('DB_PORT', '3306'),
        'database' => Env::get('DB_NAME', 'webalhasan'),
        'username' => Env::get('DB_USER', 'webalhasan_user'),
        'password' => Env::get('DB_PASSWORD', ''),
        'charset' => Env::get('DB_CHARSET', 'utf8mb4'),
    ],
    'session' => [
        'name' => Env::get('SESSION_NAME', 'alhasan_admin'),
        'secure' => Env::bool('SESSION_SECURE_COOKIE', !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ],
    'api' => [
        'token_hash_secret' => Env::get('API_TOKEN_HASH_SECRET', ''),
        'token_ttl_days' => (int) Env::get('API_TOKEN_TTL_DAYS', '30'),
    ],
    // V2 Fase 4. Seluruh nilai di bawah berasal dari environment server dan
    // TIDAK pernah disimpan ke basis data, audit, log, atau bundle mobile.
    'notifikasi' => [
        'push_token_key' => Env::get('PUSH_TOKEN_KEY', ''),
        'expo_access_token' => Env::get('EXPO_ACCESS_TOKEN', ''),
        'push_timeout_seconds' => (int) Env::get('PUSH_TIMEOUT_SECONDS', '10'),
        'worker_batch' => (int) Env::get('NOTIFIKASI_WORKER_BATCH', '25'),
    ],
];

date_default_timezone_set((string) $GLOBALS['app_config']['timezone']);
ini_set('display_errors', $GLOBALS['app_config']['debug'] ? '1' : '0');
error_reporting(E_ALL);

set_exception_handler(static function (Throwable $exception): void {
    error_log((string) $exception);
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, (app_config('debug') ? $exception->getMessage() : 'Perintah gagal. Aktifkan APP_DEBUG untuk detail.') . PHP_EOL);
        exit(1);
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo app_config('debug') ? $exception->getMessage() : 'Terjadi kesalahan pada sistem. Silakan coba lagi atau hubungi administrator.';
});

Session::start($GLOBALS['app_config']['session']);

function app_config(?string $key = null): mixed
{
    $config = $GLOBALS['app_config'];
    if ($key === null) {
        return $config;
    }

    foreach (explode('.', $key) as $segment) {
        if (!is_array($config) || !array_key_exists($segment, $config)) {
            return null;
        }
        $config = $config[$segment];
    }

    return $config;
}

function app_db(): mysqli
{
    return Connection::get(app_config('database'));
}

function app_url(string $path = ''): string
{
    $basePath = app_config('base_path') === '/' ? '' : app_config('base_path');
    return $basePath . '/' . ltrim($path, '/');
}

function auth_repository(): AuthRepository
{
    static $repository;
    return $repository ??= new AuthRepository(app_db());
}

function authorization(): Authorization
{
    static $authorization;
    return $authorization ??= new Authorization(auth_repository());
}

function audit_logger(): AuditLogger
{
    static $logger;
    return $logger ??= new AuditLogger(app_db());
}

function master_data_service(): MasterDataService
{
    static $service;
    return $service ??= new MasterDataService(new MasterDataRepository(app_db()), audit_logger());
}

function schedule_service(): ScheduleService
{
    static $service;
    return $service ??= new ScheduleService(new ScheduleRepository(app_db()), audit_logger());
}

function token_hasher(): TokenHasher
{
    static $hasher;
    return $hasher ??= new TokenHasher((string) app_config('api.token_hash_secret'), (string) app_config('env'));
}

function api_authenticator(): ApiTokenAuthenticator
{
    static $authenticator;
    return $authenticator ??= new ApiTokenAuthenticator(app_db(), token_hasher());
}

function api_auth_service(): ApiAuthService
{
    static $service;
    return $service ??= new ApiAuthService(
        new ApiAuthRepository(app_db()),
        token_hasher(),
        audit_logger(),
        (int) app_config('api.token_ttl_days'),
        (string) app_config('timezone'),
        capabilities()
    );
}

function teacher_api_service(): TeacherService
{
    static $service;
    return $service ??= new TeacherService(
        new TeacherRepository(app_db()),
        audit_logger(),
        (string) app_config('timezone')
    );
}

function report_service(): ReportService
{
    static $service;
    return $service ??= new ReportService(
        new ReportRepository(app_db()),
        (string) app_config('timezone')
    );
}

// --- V2 Fase 1: fondasi perizinan (aditif; tidak mengubah layanan V1 di atas) ---

function account_service(): AccountService
{
    static $service;
    return $service ??= new AccountService(new AccountRepository(app_db()), audit_logger());
}

function capabilities(): Capabilities
{
    static $capabilities;
    return $capabilities ??= new Capabilities(app_db());
}

function portal_guard(): PortalGuard
{
    static $guard;
    return $guard ??= new PortalGuard(authorization(), capabilities());
}

/**
 * Tujuan navigasi setelah autentikasi (berbasis capability, bukan role saja).
 * Bukan kontrol akses: halaman tujuan tetap menjaga dirinya sendiri.
 */
function landing_router(): LandingRouter
{
    static $router;
    return $router ??= new LandingRouter(capabilities());
}

function izin_repository(): IzinRepository
{
    static $repository;
    return $repository ??= new IzinRepository(app_db());
}

function izin_service(): IzinService
{
    static $service;
    return $service ??= new IzinService(izin_repository(), capabilities());
}

// --- V2 Fase 2: pengajuan, routing, dan keputusan ---------------------------

function izin_router(): IzinRouter
{
    static $router;
    return $router ??= new IzinRouter(app_db());
}

function izin_workflow_service(): IzinWorkflowService
{
    static $service;
    return $service ??= new IzinWorkflowService(
        izin_repository(),
        new IzinWriteRepository(app_db()),
        izin_router(),
        new IzinIdempotency(app_db()),
        izin_service(),
        capabilities(),
        audit_logger(),
        // V2 Fase 4: produsen outbox. Hanya menulis baris lokal di dalam
        // transaksi perizinan; tidak pernah memanggil penyedia eksternal.
        notification_service()
    );
}

function pembimbing_service(): PembimbingService
{
    static $service;
    return $service ??= new PembimbingService(new PembimbingRepository(app_db()), audit_logger());
}

// --- V2 Fase 3: REST API perizinan multi-peran ------------------------------

function izin_api_service(): IzinApiService
{
    static $service;
    return $service ??= new IzinApiService(
        izin_service(),
        izin_workflow_service(),
        izin_repository(),
        izin_router(),
        capabilities()
    );
}

function perizinan_account_service(): PerizinanAccountService
{
    static $service;
    return $service ??= new PerizinanAccountService(
        new PerizinanAccountRepository(app_db()),
        account_service(),
        audit_logger()
    );
}

// --- V2 Fase 4: notifikasi in-app, push, dan WhatsApp opsional --------------
//
// Seluruh objek di bawah bersifat aditif. Tidak ada satu pun layanan V1 atau
// Fase 1-3 di atas yang berubah kontraknya.

function notification_repository(): NotificationRepository
{
    static $repository;
    return $repository ??= new NotificationRepository(app_db());
}

function notification_outbox_repository(): OutboxRepository
{
    static $repository;
    return $repository ??= new OutboxRepository(app_db());
}

function notification_settings_repository(): NotificationSettingsRepository
{
    static $repository;
    return $repository ??= new NotificationSettingsRepository(app_db());
}

function push_device_repository(): DeviceRepository
{
    static $repository;
    return $repository ??= new DeviceRepository(app_db());
}

/**
 * Kunci perlindungan token push. Berasal dari environment server; bila kosong,
 * registrasi perangkat ditolak dengan pesan konfigurasi (bukan diam-diam
 * menyimpan token tanpa perlindungan).
 */
function push_token_protector(): PushTokenProtector
{
    static $protector;
    return $protector ??= new PushTokenProtector((string) app_config('notifikasi.push_token_key'));
}

function notification_service(): NotificationService
{
    static $service;
    return $service ??= new NotificationService(
        app_db(),
        notification_repository(),
        new RecipientResolver(app_db()),
        notification_settings_repository(),
        push_device_repository()
    );
}

function notification_center_service(): NotificationCenterService
{
    static $service;
    return $service ??= new NotificationCenterService(notification_repository());
}

function push_device_service(): DeviceService
{
    static $service;
    return $service ??= new DeviceService(
        push_device_repository(),
        push_token_protector(),
        audit_logger()
    );
}

/**
 * Penyedia WhatsApp aktif. Default `NullProvider`: tidak ada vendor, tidak ada
 * koneksi keluar, dan WhatsApp tetap mati sampai admin menyalakannya setelah
 * pemeriksaan konfigurasi lulus.
 */
function whatsapp_provider(): WhatsAppProvider
{
    static $provider;
    return $provider ??= WhatsAppProviderFactory::make((string) app_config('env'));
}

function notification_dispatcher(): NotificationDispatcher
{
    static $dispatcher;
    return $dispatcher ??= new NotificationDispatcher(
        app_db(),
        notification_outbox_repository(),
        push_device_repository(),
        push_token_protector(),
        new ExpoPushClient(
            (string) app_config('notifikasi.expo_access_token'),
            (int) app_config('notifikasi.push_timeout_seconds')
        ),
        whatsapp_provider(),
        notification_settings_repository(),
        new WorkerLock(app_db())
    );
}

function notification_api_service(): NotificationApiService
{
    static $service;
    return $service ??= new NotificationApiService(
        notification_center_service(),
        push_device_service(),
        notification_admin_service()
    );
}

function notification_admin_service(): NotificationAdminService
{
    static $service;
    return $service ??= new NotificationAdminService(
        capabilities(),
        notification_settings_repository(),
        notification_repository(),
        notification_outbox_repository(),
        push_device_repository(),
        push_token_protector(),
        whatsapp_provider(),
        notification_service(),
        notification_dispatcher(),
        audit_logger()
    );
}
