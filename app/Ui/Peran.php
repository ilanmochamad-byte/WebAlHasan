<?php

declare(strict_types=1);

namespace App\Ui;

use App\Auth\Capabilities;
use App\Penugasan\PenugasanJenis;

/**
 * Peran aktif tampilan (redesain website Tahap 2, keputusan AR 22 Oktober 2026).
 *
 * Akun berperan ganda memilih SATU peran yang menentukan peta menu, Beranda,
 * dan cakupan bawaan halaman perizinan. Pilihan disimpan di sesi sampai keluar
 * (atau masuk ulang), tanpa perubahan database.
 *
 * Aturan yang dijaga kelas ini:
 *   - Daftar peran yang boleh dipilih dihitung ulang dari hak NYATA akun pada
 *     setiap request (role + relasi master + penugasan aktif). Nilai sesi yang
 *     tidak ada di daftar itu diabaikan dan jatuh ke peran bawaan.
 *   - Peran hanya mempersempit tampilan; peran tidak pernah memberi hak.
 *     Setiap halaman tetap menjaga dirinya sendiri di server (guard, cakupan
 *     layanan). Menyembunyikan menu bukan kontrol akses.
 *   - Nilai sesi diikat ke id akun, sehingga pilihan akun lain pada peramban
 *     yang sama tidak pernah terbawa.
 */
final class Peran
{
    public const ADMIN = 'admin';
    public const GURU = 'guru';
    public const PENGURUS = 'pengurus';
    public const PENDIDIKAN = 'pendidikan';
    public const ORANG_TUA = 'orang_tua';

    /** Urutan bawaan: peran pertama yang dimiliki menjadi peran awal setelah masuk. */
    public const URUTAN = [self::ADMIN, self::PENDIDIKAN, self::GURU, self::PENGURUS, self::ORANG_TUA];

    private const SESSION_KEY = '_ah_peran';

    /** @var array<int, array<int, string>> */
    private static array $cache = [];

    public static function label(string $peran): string
    {
        return match ($peran) {
            self::ADMIN => 'Admin',
            self::GURU => 'Guru',
            self::PENGURUS => 'Pengurus',
            self::PENDIDIKAN => 'Bagian Pendidikan',
            self::ORANG_TUA => 'Orang tua',
            default => $peran,
        };
    }

    /**
     * Keterangan singkat peran untuk pemilih peran (A-2).
     */
    public static function description(string $peran): string
    {
        return match ($peran) {
            self::ADMIN => 'Seluruh data dan pengaturan',
            self::GURU => 'Jadwal, nilai, tugas murobi',
            self::PENGURUS => 'Perizinan dan santri binaan',
            self::PENDIDIKAN => 'Kelengkapan dan penerbitan rapor',
            self::ORANG_TUA => 'Izin, rapor, dan informasi anak',
            default => '',
        };
    }

    /**
     * Peran yang boleh dipilih akun ini, dalam urutan bawaan.
     *
     * @param array<string, mixed> $user
     * @return array<int, string>
     */
    public static function available(array $user): array
    {
        $id = (int) ($user['id'] ?? 0);
        if (isset(self::$cache[$id])) {
            return self::$cache[$id];
        }

        $roles = $user['roles'] ?? [];
        $hasil = [];
        if (in_array('admin', $roles, true)) {
            $hasil[] = self::ADMIN;
        }
        // Bagian Pendidikan adalah penugasan, bukan role login: hanya ditawarkan
        // bila akun memegang penugasan Pendidikan aktif miliknya sendiri. Hak
        // pengawasan admin tidak dihitung sebagai peran terpisah.
        if (in_array('pengurus', $roles, true)) {
            $sumber = capabilities()->featureSource($user, PenugasanJenis::CAP_RAPOR_VERIFIKASI);
            if (in_array($sumber, [Capabilities::SUMBER_PENUGASAN, Capabilities::SUMBER_KEDUANYA], true)) {
                $hasil[] = self::PENDIDIKAN;
            }
        }
        if (in_array('guru', $roles, true)) {
            $hasil[] = self::GURU;
        }
        if (in_array('pengurus', $roles, true)) {
            $hasil[] = self::PENGURUS;
        }
        if (in_array('orang_tua', $roles, true)) {
            $hasil[] = self::ORANG_TUA;
        }

        // Urutan selalu mengikuti URUTAN, apa pun urutan role di basis data.
        $hasil = array_values(array_intersect(self::URUTAN, $hasil));

        return self::$cache[$id] = $hasil;
    }

    /**
     * Peran aktif: pilihan sesi bila masih sah, selain itu peran bawaan.
     *
     * @param array<string, mixed> $user
     */
    public static function active(array $user): ?string
    {
        $available = self::available($user);
        if ($available === []) {
            return null;
        }
        $simpan = $_SESSION[self::SESSION_KEY] ?? null;
        if (is_array($simpan)
            && (int) ($simpan['user'] ?? 0) === (int) ($user['id'] ?? -1)
            && in_array($simpan['peran'] ?? null, $available, true)) {
            return (string) $simpan['peran'];
        }

        return $available[0];
    }

    /**
     * Menyimpan peran aktif. Nilai di luar peran akun ditolak (false).
     *
     * @param array<string, mixed> $user
     */
    public static function set(array $user, string $peran): bool
    {
        if (!in_array($peran, self::available($user), true)) {
            return false;
        }
        $_SESSION[self::SESSION_KEY] = ['user' => (int) $user['id'], 'peran' => $peran];

        return true;
    }

    /**
     * Mengembalikan ke peran bawaan (dipakai setelah masuk ulang).
     */
    public static function reset(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
        self::$cache = [];
    }

    /**
     * Cakupan perizinan yang sesuai dengan peran aktif, bila akun memilikinya.
     *
     * Dipakai sebagai cakupan bawaan halaman perizinan menggantikan deretan
     * tombol mode per halaman (A-2). Layanan perizinan tetap mengabaikan cakupan
     * yang bukan milik akun.
     *
     * @param array<int, string> $capabilities Kemampuan perizinan nyata akun.
     */
    public static function izinMode(?string $peran, array $capabilities): ?string
    {
        $mode = match ($peran) {
            self::ADMIN => Capabilities::ADMIN,
            self::GURU => Capabilities::MUROBI,
            self::PENGURUS, self::PENDIDIKAN => Capabilities::PENGURUS,
            self::ORANG_TUA => Capabilities::ORANG_TUA,
            default => null,
        };

        return $mode !== null && in_array($mode, $capabilities, true) ? $mode : null;
    }

    /**
     * Peran tampilan untuk cakupan perizinan lama (`?mode=`) pada alamat lama.
     */
    public static function fromIzinMode(string $mode): ?string
    {
        return match ($mode) {
            Capabilities::ADMIN => self::ADMIN,
            Capabilities::MUROBI => self::GURU,
            Capabilities::PENGURUS => self::PENGURUS,
            Capabilities::ORANG_TUA => self::ORANG_TUA,
            default => null,
        };
    }
}
