<?php

declare(strict_types=1);

namespace App\MasterData;

use finfo;

final class PhotoStorage
{
    public function __construct(private string $directory)
    {
    }

    public function store(?array $file, string $current = 'default.jpg'): string
    {
        if ($file === null || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return $current ?: 'default.jpg';
        }
        if ((int) ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new MasterDataException('Unggah foto gagal. Silakan pilih ulang berkas.');
        }
        if ((int) ($file['size'] ?? 0) > 2 * 1024 * 1024) {
            throw new MasterDataException('Ukuran foto maksimal 2 MB.');
        }
        $temporary = (string) ($file['tmp_name'] ?? '');
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporary);
        $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
        if ($extension === null) {
            throw new MasterDataException('Foto harus berformat JPG, PNG, atau WebP.');
        }
        if (!is_dir($this->directory) || !is_writable($this->directory)) {
            throw new MasterDataException('Penyimpanan foto tidak tersedia.');
        }
        $name = bin2hex(random_bytes(16)) . '.' . $extension;
        if (!move_uploaded_file($temporary, $this->directory . '/' . $name)) {
            throw new MasterDataException('Foto tidak dapat disimpan.');
        }
        return $name;
    }
}

