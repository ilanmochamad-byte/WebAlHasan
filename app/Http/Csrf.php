<?php

declare(strict_types=1);

namespace App\Http;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION['_csrf_token'];
    }

    public static function input(): string
    {
        return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    public static function validate(?string $token): bool
    {
        return is_string($token)
            && isset($_SESSION['_csrf_token'])
            && hash_equals((string) $_SESSION['_csrf_token'], $token);
    }

    public static function requireValid(?string $token): void
    {
        if (!self::validate($token)) {
            http_response_code(419);
            exit('Permintaan ditolak karena token keamanan tidak valid. Muat ulang halaman lalu coba lagi.');
        }
    }
}

