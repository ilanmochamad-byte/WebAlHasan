<?php

declare(strict_types=1);

namespace App\Bsa;

/**
 * Penolakan layanan keuangan santri/BSA (PRD V6). Pesan selalu aman
 * ditampilkan: tidak pernah memuat nominal masukan, saldo santri lain, nama
 * santri di luar hak, atau nilai referensi.
 */
final class BsaException extends \RuntimeException
{
    /** @param int $errno kode galat basis data yang diteruskan repository (0 = bukan galat basis data) */
    public function __construct(string $message, public readonly int $status = 422, public readonly int $errno = 0)
    {
        parent::__construct($message);
    }
}
