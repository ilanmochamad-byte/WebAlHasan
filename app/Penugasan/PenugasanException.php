<?php

declare(strict_types=1);

namespace App\Penugasan;

use RuntimeException;

/**
 * Kesalahan penugasan yang aman ditampilkan kepada admin.
 *
 * `status` mengikuti kontrak HTTP proyek: 403 hak, 404 tidak ditemukan,
 * 409 duplikat/tumpang tindih, 422 validasi.
 */
class PenugasanException extends RuntimeException
{
    /**
     * @param array<string, string> $errors pesan per kolom formulir (opsional)
     */
    public function __construct(string $message, private int $status = 422, private array $errors = [])
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public static function forbidden(string $message = 'Hanya admin yang dapat mengelola penugasan.'): self
    {
        return new self($message, 403);
    }

    public static function notFound(string $message = 'Penugasan tidak ditemukan.'): self
    {
        return new self($message, 404);
    }

    /**
     * @param array<string, string> $errors
     */
    public static function invalid(string $message, array $errors = []): self
    {
        return new self($message, 422, $errors);
    }

    public static function conflict(string $message): self
    {
        return new self($message, 409);
    }
}
