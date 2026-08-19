<?php

declare(strict_types=1);

namespace App\Http;

use App\Api\ApiException;

final class Request
{
    public static function json(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
            throw new ApiException('INVALID_JSON', 'Body request harus berupa JSON yang valid.', 422);
        }
        return $data;
    }

    public static function bearerToken(): ?string
    {
        $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if ($header === '' && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            $header = (string) ($headers['Authorization'] ?? $headers['authorization'] ?? '');
        }
        return preg_match('/^Bearer\s+(\S+)$/i', trim($header), $matches) ? $matches[1] : null;
    }
}
