<?php

declare(strict_types=1);

namespace App\Izin;

use RuntimeException;

class IzinException extends RuntimeException
{
    public function __construct(string $message, private int $status = 422)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    public static function forbidden(string $message = 'Akun ini tidak berhak mengakses data perizinan tersebut.'): self
    {
        return new self($message, 403);
    }

    public static function notFound(string $message = 'Pengajuan izin tidak ditemukan.'): self
    {
        return new self($message, 404);
    }

    public static function invalid(string $message): self
    {
        return new self($message, 422);
    }
}
