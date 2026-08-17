<?php

declare(strict_types=1);

namespace App\MasterData;

use DateTimeImmutable;

final class Normalizer
{
    public static function text(mixed $value): string
    {
        return preg_replace('/\s+/u', ' ', trim((string) $value)) ?? '';
    }

    public static function identifier(mixed $value): string
    {
        return strtoupper(preg_replace('/\s+/u', '', trim((string) $value)) ?? '');
    }

    public static function phone(mixed $value): string
    {
        $phone = preg_replace('/[^0-9+]/', '', trim((string) $value)) ?? '';
        if (str_starts_with($phone, '+62')) {
            return '0' . substr($phone, 3);
        }
        if (str_starts_with($phone, '62')) {
            return '0' . substr($phone, 2);
        }
        return $phone;
    }

    public static function date(mixed $value, bool $required = false): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return $required ? '' : null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $value : '';
    }

    public static function email(mixed $value): string
    {
        return strtolower(trim((string) $value));
    }
}

