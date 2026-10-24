<?php

declare(strict_types=1);

namespace App\Psb;

/**
 * Pengelompokan jenjang tujuan formulir PSB (redesain Tahap 8, keputusan AN).
 *
 * "Unit Al Hasan" dan "Sekolah Mitra" hanya pengelompokan tampilan; nilai
 * yang disimpan tetap teks jenjang yang sama seperti sebelumnya. RA dan MI
 * tampil tetapi BELUM DIBUKA sampai panitia menetapkan biayanya (keputusan
 * Human Developer 24 Oktober 2026, butir 2b): pendaftaran baru ke jenjang
 * itu ditolak server, dan pendaftar lama tidak dapat berpindah ke sana.
 * Pendaftar lama yang sudah tercatat di RA tetap dapat menyimpan biodatanya.
 * MI tidak ditambahkan ke `PsbAdmin::UNIT` (katalog biaya tidak berubah).
 */
final class Jenjang
{
    /** Nilai jenjang => label singkat. */
    public const UNIT_AL_HASAN = [
        'RA Terpadu Al Hasan' => 'RA Terpadu Al Hasan',
        'MI Daar el-Qolam' => 'MI Daar el-Qolam',
        'SMP Terpadu Al Hasan' => 'SMP Terpadu Al Hasan',
        'SMK Terpadu Al Hasan' => 'SMK Terpadu Al Hasan',
    ];

    public const SEKOLAH_MITRA = [
        'MAN 2 Ciamis' => 'MAN 2 Ciamis',
        'SMAN 1 Ciamis' => 'SMAN 1 Ciamis',
        'SMAN 2 Ciamis' => 'SMAN 2 Ciamis',
        'SMKN 1 Ciamis' => 'SMKN 1 Ciamis',
        'SMKN 2 Ciamis' => 'SMKN 2 Ciamis',
    ];

    /** Tampil di formulir tetapi belum dapat dipilih (biaya belum ditetapkan). */
    public const BELUM_DIBUKA = ['RA Terpadu Al Hasan', 'MI Daar el-Qolam'];

    public static function dibuka(string $jenjang): bool
    {
        return in_array($jenjang, PsbAdmin::UNIT, true) && !in_array($jenjang, self::BELUM_DIBUKA, true);
    }

    /**
     * Galat untuk jenjang kiriman, atau null bila sah.
     *
     * @param string|null $lama jenjang tersimpan (ubah biodata), null untuk pendaftaran baru
     */
    public static function galat(string $jenjang, ?string $lama): ?string
    {
        if ($lama !== null && $jenjang === $lama && in_array($jenjang, PsbAdmin::UNIT, true)) {
            return null;
        }
        if (in_array($jenjang, self::BELUM_DIBUKA, true)) {
            return 'Pendaftaran ' . $jenjang . ' belum dibuka. Pilih jenjang lain atau hubungi panitia PSB.';
        }

        return self::dibuka($jenjang) ? null : 'Jenjang tujuan tidak dikenal.';
    }
}
