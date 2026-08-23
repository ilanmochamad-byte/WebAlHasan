<?php

declare(strict_types=1);

namespace App\Notification\WhatsApp;

use App\Notification\SafeError;

/**
 * Hasil satu operasi penyedia WhatsApp (verifikasi maupun pengiriman).
 *
 * `pesan` SELALU sudah melewati `SafeError` sehingga aman disimpan di basis
 * data, ditulis ke log, dan ditampilkan kepada admin.
 */
final class ProviderResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly string $kode,
        public readonly string $pesan,
        public readonly bool $permanen,
        public readonly ?string $referensi = null
    ) {
    }

    public static function ok(string $pesan = 'Berhasil.', ?string $referensi = null): self
    {
        return new self(true, 'OK', SafeError::message($pesan, 'Berhasil.'), false, $referensi);
    }

    /**
     * Kegagalan sementara: layak dicoba ulang dengan backoff.
     */
    public static function gagal(string $kode, string $pesan): self
    {
        return new self(false, SafeError::code($kode, 'GAGAL'), SafeError::message($pesan), false);
    }

    /**
     * Kegagalan permanen: percobaan ulang tidak akan pernah berhasil
     * (mis. nomor tidak valid, template ditolak, konfigurasi salah).
     */
    public static function permanen(string $kode, string $pesan): self
    {
        return new self(false, SafeError::code($kode, 'GAGAL_PERMANEN'), SafeError::message($pesan), true);
    }
}
