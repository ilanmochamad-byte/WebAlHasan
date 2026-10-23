<?php

declare(strict_types=1);

namespace App\Konten;

use RuntimeException;

/**
 * Penyimpanan berkas unggahan Konten website (redesain website Tahap 3,
 * W-2 s.d. W-4).
 *
 * Folder tujuan tetap sama dengan halaman lama (gambar berita di `gambar/`,
 * galeri di `gambar_galeri/`, unduhan di `admin/file_download/`) dan nama
 * berkas tetap berpola `acak_nama-asli`. Yang ditambahkan: jenis berkas
 * dibatasi daftar tetap, gambar harus benar-benar gambar, ukuran dibatasi,
 * dan nama asli dibersihkan dari karakter jalur. Galat tampil sebagai pesan
 * di halaman, bukan kotak alert peramban.
 */
final class Unggahan
{
    public const GAMBAR = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    public const DOKUMEN = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'txt', 'zip', 'jpg', 'jpeg', 'png'];
    public const MAKS_GAMBAR = 5 * 1024 * 1024;
    public const MAKS_DOKUMEN = 20 * 1024 * 1024;

    /**
     * Berkas dari `$_FILES[name]`, baik tunggal maupun `name[]`.
     *
     * @return array<int, array{name:string, type:string, tmp_name:string, error:int, size:int}>
     */
    public static function daftar(string $field): array
    {
        $file = $_FILES[$field] ?? null;
        if (!is_array($file) || !isset($file['name'])) {
            return [];
        }
        if (!is_array($file['name'])) {
            return ((int) $file['error'] === UPLOAD_ERR_NO_FILE) ? [] : [$file];
        }
        $hasil = [];
        foreach (array_keys($file['name']) as $i) {
            if ((int) $file['error'][$i] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $hasil[] = ['name' => (string) $file['name'][$i], 'type' => (string) $file['type'][$i], 'tmp_name' => (string) $file['tmp_name'][$i],
                'error' => (int) $file['error'][$i], 'size' => (int) $file['size'][$i]];
        }

        return $hasil;
    }

    /**
     * Memeriksa lalu memindahkan satu berkas ke folder tujuan.
     *
     * @param array{name:string, tmp_name:string, error:int, size:int} $file
     * @param array<int, string> $jenis ekstensi yang diizinkan
     * @return string nama berkas tersimpan (tanpa folder)
     */
    public static function simpan(array $file, string $folder, array $jenis, int $maks, bool $gambar = false): string
    {
        $asli = (string) $file['name'];
        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException(in_array((int) $file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? $asli . ': berkas terlalu besar.' : $asli . ': berkas gagal diunggah. Coba lagi.');
        }
        $ext = strtolower(pathinfo($asli, PATHINFO_EXTENSION));
        if (!in_array($ext, $jenis, true)) {
            throw new RuntimeException($asli . ': jenis berkas tidak didukung. Gunakan ' . strtoupper(implode(', ', array_unique($jenis))) . '.');
        }
        if ((int) $file['size'] <= 0 || (int) $file['size'] > $maks) {
            throw new RuntimeException($asli . ': ukuran paling besar ' . (int) ($maks / 1024 / 1024) . ' MB.');
        }
        if (!is_uploaded_file((string) $file['tmp_name'])) {
            throw new RuntimeException($asli . ': berkas unggahan tidak dikenali.');
        }
        if ($gambar && @getimagesize((string) $file['tmp_name']) === false) {
            throw new RuntimeException($asli . ': isi berkas bukan gambar.');
        }
        $nama = random_int(100000000, 2147483647) . '_' . self::namaAman($asli, $ext);
        $tujuan = rtrim($folder, '/') . '/' . $nama;
        // Folder unggahan lama tidak ikut repositori; dibuat bila belum ada.
        if (!is_dir(dirname($tujuan))) {
            @mkdir(dirname($tujuan), 0755, true);
        }
        if (!is_dir(dirname($tujuan)) || !move_uploaded_file((string) $file['tmp_name'], $tujuan)) {
            throw new RuntimeException($asli . ': berkas tidak dapat disimpan di server.');
        }

        return $nama;
    }

    /** Nama asli tanpa jalur dan karakter khusus, ekstensi dipertahankan. */
    public static function namaAman(string $asli, string $ext): string
    {
        $dasar = pathinfo(str_replace('\\', '/', $asli), PATHINFO_FILENAME);
        $dasar = trim((string) preg_replace('/[^A-Za-z0-9 ._()-]+/', '-', $dasar), ' .-');
        $dasar = mb_substr($dasar === '' ? 'berkas' : $dasar, 0, 80);

        return $dasar . '.' . $ext;
    }

    /** Judul awal dari nama berkas (galeri: judul dapat diubah di panel). */
    public static function judulDari(string $asli): string
    {
        $dasar = trim((string) preg_replace('/[_-]+/', ' ', pathinfo($asli, PATHINFO_FILENAME)));

        return mb_substr($dasar === '' ? 'Foto' : $dasar, 0, 255);
    }

    public static function ukuran(?string $path): string
    {
        if ($path === null || !is_file($path)) {
            return '—';
        }
        $byte = (int) filesize($path);

        return $byte >= 1048576 ? number_format($byte / 1048576, 1, ',', '.') . ' MB' : max(1, (int) round($byte / 1024)) . ' KB';
    }
}
