<?php

declare(strict_types=1);

namespace App\Http;

final class JsonResponse
{
    public static function success(mixed $data = null, int $status = 200): never
    {
        self::send(['success' => true, 'data' => $data, 'error' => null], $status);
    }

    public static function error(string $code, string $message, int $status, array $details = []): never
    {
        self::send([
            'success' => false,
            'data' => null,
            'error' => ['code' => $code, 'message' => $message, 'details' => $details],
        ], $status);
    }

    private static function send(array $payload, int $status): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

