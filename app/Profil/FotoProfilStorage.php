<?php

declare(strict_types=1);

namespace App\Profil;

use finfo;

/**
 * Foto profil di folder privat (PI-5): JPEG/PNG/WebP ≤ 2 MiB, nama berkas acak,
 * tanpa ekstensi dari klien. Folder `storage/private` ditolak server web
 * (`Require all denied`); foto hanya dilayani endpoint yang memeriksa hak.
 */
final class FotoProfilStorage
{
    public const MAX_BYTES = 2_097_152;
    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    private const IMAGE_TYPES = [IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_PNG => 'image/png', IMAGETYPE_WEBP => 'image/webp'];

    public function __construct(private string $directory)
    {
    }

    /**
     * Memindahkan unggahan HTTP ke folder privat. Mengembalikan nama berkas.
     *
     * @param array<string, mixed>|null $upload satu entri `$_FILES`
     */
    public function simpanUnggahan(?array $upload): string
    {
        if ($upload === null || is_array($upload['error'] ?? null) || (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new ProfilException('Pilih satu berkas foto.', 422, ['foto' => 'Pilih satu berkas foto.']);
        }
        $error = (int) ($upload['error'] ?? UPLOAD_ERR_OK);
        if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw new ProfilException('Ukuran foto maksimal 2 MB.', 422, ['foto' => 'Ukuran foto maksimal 2 MB.']);
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new ProfilException('Unggah foto gagal. Silakan pilih ulang berkas.', 422, ['foto' => 'Unggah foto gagal.']);
        }
        $temporary = (string) ($upload['tmp_name'] ?? '');
        if ($temporary === '' || !is_uploaded_file($temporary)) {
            throw new ProfilException('Berkas foto tidak sah.', 422, ['foto' => 'Berkas foto tidak sah.']);
        }
        $size = filesize($temporary);
        if ($size === false || $size < 1 || $size > self::MAX_BYTES) {
            throw new ProfilException('Ukuran foto maksimal 2 MB.', 422, ['foto' => 'Ukuran foto maksimal 2 MB.']);
        }
        $extension = $this->jenisGambar($temporary);
        $this->siapkanFolder();
        $name = bin2hex(random_bytes(16)) . '.' . $extension;
        if (!move_uploaded_file($temporary, $this->directory . '/' . $name)) {
            throw new ProfilException('Foto tidak dapat disimpan. Silakan coba lagi.', 503);
        }
        @chmod($this->directory . '/' . $name, 0640);

        return $name;
    }

    public function path(?string $name): ?string
    {
        if ($name === null || !self::namaSah($name)) {
            return null;
        }
        $path = $this->directory . '/' . $name;

        return is_file($path) ? $path : null;
    }

    public function mime(string $name): string
    {
        return array_search(pathinfo($name, PATHINFO_EXTENSION), self::TYPES, true) ?: 'application/octet-stream';
    }

    public function hapus(?string $name): void
    {
        $path = $this->path($name);
        if ($path !== null) {
            @unlink($path);
        }
    }

    public static function namaSah(string $name): bool
    {
        return (bool) preg_match('/^[0-9a-f]{32}\.(jpg|png|webp)$/', $name);
    }

    /** Jenis dari isi berkas (finfo) yang juga terbaca sebagai gambar utuh. */
    private function jenisGambar(string $path): string
    {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        $info = @getimagesize($path);
        $extension = self::TYPES[$mime] ?? null;
        if ($extension === null || $info === false || (self::IMAGE_TYPES[$info[2] ?? 0] ?? null) !== $mime
            || (int) $info[0] < 1 || (int) $info[1] < 1 || (int) $info[0] > 8000 || (int) $info[1] > 8000) {
            throw new ProfilException('Foto harus berupa gambar JPG, PNG, atau WebP.', 422, ['foto' => 'Foto harus berupa gambar JPG, PNG, atau WebP.']);
        }

        return $extension;
    }

    private function siapkanFolder(): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0750, true) && !is_dir($this->directory)) {
            throw new ProfilException('Penyimpanan foto tidak tersedia.', 503);
        }
        if (!is_writable($this->directory)) {
            throw new ProfilException('Penyimpanan foto tidak tersedia.', 503);
        }
    }
}
