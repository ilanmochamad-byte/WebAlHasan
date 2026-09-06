<?php

declare(strict_types=1);

namespace App\Account;

/**
 * Flash sesi satu kali untuk pesan kredensial akun baru.
 *
 * Perilaku "satu kali tampil" seluruhnya bergantung pada kelas ini:
 *
 *   - muatan disimpan sebagai DATA TERSTRUKTUR, bukan potongan HTML, sehingga
 *     halaman yang menampilkannya wajib meng-escape sendiri setiap nilai;
 *   - `take()` MENGHAPUS muatan dari sesi pada pembacaan pertama, jadi memuat
 *     ulang halaman, menekan tombol kembali, atau membuka halaman di tab lain
 *     tidak dapat memunculkannya kembali;
 *   - `forget()` dipanggil pada jalur kegagalan agar pembuatan akun yang gagal
 *     tidak pernah meninggalkan pesan kredensial palsu.
 *
 * Password sementara hanya berada di sini sesingkat mungkin: satu kali redirect
 * setelah POST berhasil. Ia tidak pernah masuk URL, query string, cookie,
 * localStorage, sessionStorage peramban, audit, maupun log aplikasi.
 */
final class CredentialFlash
{
    private const KEY = '_ah_kredensial_akun';

    /**
     * @param array<string, mixed> $payload Muatan dari CredentialMessage::forSavedAccount().
     */
    public static function set(array $payload): void
    {
        if (!self::sessionReady()) {
            return;
        }
        $_SESSION[self::KEY] = $payload;
    }

    public static function has(): bool
    {
        return self::sessionReady() && isset($_SESSION[self::KEY]);
    }

    /**
     * Membaca sekaligus MENGHAPUS muatan. Tidak ada pembacaan kedua.
     *
     * @return array<string, mixed>|null
     */
    public static function take(): ?array
    {
        if (!self::sessionReady()) {
            return null;
        }
        $payload = $_SESSION[self::KEY] ?? null;
        unset($_SESSION[self::KEY]);

        return is_array($payload) ? $payload : null;
    }

    public static function forget(): void
    {
        if (!self::sessionReady()) {
            return;
        }
        unset($_SESSION[self::KEY]);
    }

    private static function sessionReady(): bool
    {
        return isset($_SESSION) && is_array($_SESSION);
    }
}
