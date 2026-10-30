<?php

declare(strict_types=1);

namespace App\Keamanan;

use App\Auth\Capabilities;
use App\Penugasan\PenugasanJenis;

/**
 * Hak baca Keamanan (penugasan lintas guru/pengurus Fase 3, PK-5/PK-6).
 *
 * Keamanan adalah penugasan, bukan role login. Hak bacanya hanya sah bila akun
 * memegang penugasan Keamanan aktif MILIK SENDIRI (`sumber` penugasan atau
 * keduanya): hak pengawasan admin tidak membuka halaman Keamanan, karena admin
 * sudah membaca seluruh data lewat menunya sendiri.
 *
 * Jalur baca Keamanan memakai layanan yang sudah ada (izin, pembinaan V3).
 * Halaman Keamanan memasang PENANDA pada array akun sesudah memeriksa hak;
 * layanan yang menerima penanda itu memeriksa ulang hak lewat `sah()` dan
 * memakai mode baca `keamanan` (seluruh santri aktif, penyaring kerahasiaan
 * sama dengan murobi). Penanda hanya mempersempit ke mode itu dan tidak
 * pernah memberi hak sendiri: tanpa penugasan yang sah, layanan menolak.
 * Pola yang sama dengan penanda laporan kehadiran Bagian Pendidikan
 * (`App\Report\ReportFilter::BACA_SEMUA_JADWAL`, keputusan H).
 */
final class KeamananAkses
{
    public const PENANDA = '_keamanan_pantau';

    /** Mode baca layanan V3/izin untuk Keamanan. */
    public const MODE = 'keamanan';

    /**
     * @param array<string, mixed> $user
     */
    public static function sah(Capabilities $capabilities, array $user): bool
    {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId < 1) {
            return false;
        }
        // Dihitung ulang dari basis data: penugasan yang baru berakhir,
        // dinonaktifkan, atau identitas guru yang dinonaktifkan langsung gugur.
        $capabilities->forget($userId);

        return in_array(
            $capabilities->featureSource($user, PenugasanJenis::CAP_KEAMANAN_PANTAU),
            [Capabilities::SUMBER_PENUGASAN, Capabilities::SUMBER_KEDUANYA],
            true
        );
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function tandai(array $user): array
    {
        $user[self::PENANDA] = true;

        return $user;
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function diminta(array $user): bool
    {
        return ($user[self::PENANDA] ?? false) === true;
    }
}
