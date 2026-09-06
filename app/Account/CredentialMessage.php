<?php

declare(strict_types=1);

namespace App\Account;

use InvalidArgumentException;

/**
 * Pesan informasi login siap salin (keputusan pengguna 6 September 2026).
 *
 * Sistem TIDAK mengirim email. Setelah admin membuat akun guru, pengurus, atau
 * orang tua, admin menyalin pesan baku di bawah ini dan menempelkannya sendiri
 * ke email pengguna. Kelas ini hanya menyusun teksnya; ia tidak menyimpan,
 * mencatat, maupun mengirim apa pun.
 *
 * Aturan yang dijaga kelas ini:
 *
 *   - nama, username, dan email SELALU berasal dari baris akun yang dibaca
 *     ULANG dari server setelah penyimpanan berhasil, bukan dari nilai mentah
 *     formulir, sehingga pesan memuat nilai yang benar-benar tersimpan;
 *   - pesan hanya dapat dibuat untuk akun yang benar-benar punya id, nama, dan
 *     username — pembuatan akun yang gagal tidak menghasilkan pesan palsu;
 *   - keluarannya TEKS BIASA. Tidak ada markup di dalamnya, sehingga isi yang
 *     disalin admin sama persis dengan yang terlihat pada layar.
 *
 * Password sementara hanya lewat di memori proses dan pada flash sesi satu kali
 * (lihat CredentialFlash). Ia tidak pernah ditulis ke basis data dalam bentuk
 * asli, tidak masuk audit, dan tidak masuk log.
 */
final class CredentialMessage
{
    /** Alamat masuk tunggal seluruh peran. */
    public const PORTAL_URL = 'https://alhasan.co.id/portal/';

    /** Jenis akun yang didukung admin saat ini. */
    public const KINDS = ['guru', 'pengurus', 'orang_tua'];

    /**
     * Menyusun muatan pesan dari akun yang SUDAH tersimpan dan dibaca ulang.
     *
     * @param array<string, mixed> $account Baris akun hasil pembacaan ulang dari server.
     * @return array{account_id:int, kind:string, name:string, username:string, email:string, password:string, portal_url:string}
     */
    public static function forSavedAccount(array $account, string $temporaryPassword, string $kind): array
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException('Jenis akun untuk pesan kredensial tidak dikenal.');
        }

        $id = (int) ($account['id'] ?? 0);
        $name = trim((string) ($account['name'] ?? ''));
        $username = trim((string) ($account['username'] ?? ''));

        if ($id < 1 || $name === '' || $username === '' || $temporaryPassword === '') {
            throw new InvalidArgumentException(
                'Pesan kredensial hanya dapat dibuat dari akun yang benar-benar tersimpan dan terbaca kembali.'
            );
        }

        return [
            'account_id' => $id,
            'kind' => $kind,
            'name' => $name,
            'username' => $username,
            'email' => trim((string) ($account['email'] ?? '')),
            'password' => $temporaryPassword,
            'portal_url' => self::PORTAL_URL,
        ];
    }

    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            'guru' => 'Guru',
            'pengurus' => 'Pengurus',
            'orang_tua' => 'Orang Tua',
            default => $kind,
        };
    }

    /**
     * Teks baku pesan. Teks biasa, tanpa markup apa pun.
     *
     * @param array<string, mixed> $payload Muatan dari forSavedAccount().
     */
    public static function text(array $payload): string
    {
        return implode("\n", [
            'Assalamu’alaikum.',
            '',
            'Yth. ' . (string) $payload['name'] . ',',
            '',
            'Akun Anda pada Sistem Al Hasan telah dibuat.',
            '',
            'Alamat masuk:',
            (string) ($payload['portal_url'] ?? self::PORTAL_URL),
            '',
            'Username: ' . (string) $payload['username'],
            'Password sementara: ' . (string) $payload['password'],
            '',
            'Pada login pertama, Anda akan diminta membuat password baru. Setelah password baru berhasil dibuat, password sementara di atas tidak dapat digunakan kembali.',
            '',
            'Mohon simpan informasi akun ini dengan aman dan jangan membagikannya kepada pihak lain.',
            '',
            'Wassalamu’alaikum.',
        ]);
    }
}
