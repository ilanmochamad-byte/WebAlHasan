<?php

declare(strict_types=1);

namespace App\Notification;

use RuntimeException;

/**
 * Galat pada jalur notifikasi.
 *
 * Pesan yang dibawa kelas ini SELALU aman ditampilkan kepada admin: pemanggil
 * wajib membersihkan detail penyedia melalui `SafeError` sebelum membungkusnya
 * di sini, sehingga credential, token, dan nomor tujuan tidak pernah bocor ke
 * respons API, log, atau audit.
 */
class NotificationException extends RuntimeException
{
    public function __construct(string $message, private int $status = 422)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    public static function forbidden(string $message = 'Akun ini tidak berhak mengakses notifikasi tersebut.'): self
    {
        return new self($message, 403);
    }

    public static function invalid(string $message): self
    {
        return new self($message, 422);
    }

    public static function conflict(string $message): self
    {
        return new self($message, 409);
    }

    public static function unavailable(string $message): self
    {
        return new self($message, 503);
    }
}
