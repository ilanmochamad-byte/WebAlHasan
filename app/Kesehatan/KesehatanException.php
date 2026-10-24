<?php

declare(strict_types=1);

namespace App\Kesehatan;

/**
 * Penolakan layanan kesehatan santri (PRD V5). Pesan selalu aman ditampilkan:
 * tidak pernah memuat isi klinis, nama santri, atau nilai masukan.
 */
final class KesehatanException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
