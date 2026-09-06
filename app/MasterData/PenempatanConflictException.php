<?php

declare(strict_types=1);

namespace App\MasterData;

use RuntimeException;

/**
 * Konflik penguncian (deadlock atau lock wait timeout) saat penempatan.
 *
 * Dipisahkan dari `MasterDataException` karena artinya berbeda: bukan input
 * admin yang salah, melainkan dua perubahan bersamaan pada santri atau kamar
 * yang sama. Seluruh operasi sudah di-rollback; admin cukup mencoba lagi.
 */
final class PenempatanConflictException extends RuntimeException
{
}
