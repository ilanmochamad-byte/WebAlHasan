<?php

declare(strict_types=1);

use App\Audit\AuditLogger;
use App\Auth\AuthRepository;
use App\Auth\Authorization;
use App\Database\Connection;
use App\Http\Session;
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
