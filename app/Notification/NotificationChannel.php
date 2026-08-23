<?php

declare(strict_types=1);

namespace App\Notification;

/**
 * Kanal notifikasi V2 Fase 4.
 *
 * `InApp` adalah SUMBER STATUS UTAMA (PRD 5.7): ia tidak pernah memanggil
 * penyedia eksternal, tidak dapat dimatikan admin, dan ketersediaannya tidak
 * bergantung pada keberhasilan Push maupun WhatsApp.
 */
final class NotificationChannel
{
    public const IN_APP = 'InApp';
    public const PUSH = 'Push';
    public const WHATSAPP = 'WhatsApp';

    public const ALL = [self::IN_APP, self::PUSH, self::WHATSAPP];

    /** Kanal yang diproses worker/cron (memanggil penyedia eksternal). */
    public const EKSTERNAL = [self::PUSH, self::WHATSAPP];

    public static function valid(string $channel): bool
    {
        return in_array($channel, self::ALL, true);
    }

    public static function label(string $channel): string
    {
        return match ($channel) {
            self::IN_APP => 'Dalam aplikasi',
            self::PUSH => 'Push notification',
            self::WHATSAPP => 'WhatsApp',
            default => $channel,
        };
    }
}
