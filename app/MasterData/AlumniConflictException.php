<?php

declare(strict_types=1);

namespace App\MasterData;

use RuntimeException;

/**
 * Konflik bersamaan saat memproses kelulusan/mutasi alumni.
 *
 * Dipisahkan dari `MasterDataException` karena artinya berbeda: bukan input
 * admin yang salah, melainkan dua permintaan bersamaan atas santri yang sama —
 * deadlock, lock wait timeout, atau pelanggaran kunci unik alumni aktif.
 * Seluruh operasi sudah di-rollback; admin cukup memuat ulang lalu memeriksa
 * keadaannya. Halaman pemanggil menjawabnya dengan HTTP 409.
 */
final class AlumniConflictException extends RuntimeException
{
}
