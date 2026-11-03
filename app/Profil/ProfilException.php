<?php

declare(strict_types=1);

namespace App\Profil;

use RuntimeException;

/**
 * Kesalahan profil/impor yang aman ditampilkan. `status` mengikuti kontrak
 * HTTP proyek: 401 sesi, 403 hak, 404 tidak ditemukan/bukan milik, 409 konflik
 * versi atau nilai unik, 422 validasi, 503 skema/penyimpanan/audit tidak siap.
 */
final class ProfilException extends RuntimeException
{
    /** @param array<string, string> $errors pesan per kolom */
    public function __construct(string $message, private int $status = 422, private array $errors = [])
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public static function belumDipasang(): self
    {
        return new self('Fitur profil belum dipasang pada server ini (migrasi 035).', 503);
    }
}
