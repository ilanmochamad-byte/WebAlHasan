<?php

declare(strict_types=1);

namespace App\Psb;

use finfo;

/**
 * Berkas pendaftar PSB (KTP, KK, akta, ijazah, nilai, prestasi) — workstream
 * keamanan PSB.
 *
 * Unggahan baru disimpan di `storage/private/psb/` dengan nama acak yang
 * tidak dapat ditebak; isi diperiksa di server (ukuran, jenis sebenarnya, dan
 * gambar/PDF yang sah), bukan hanya ekstensi. Berkas lama tetap di folder
 * `berkas_psb/` dengan nama aslinya (tidak dipindah, tidak dihapus) dan hanya
 * dibaca lewat `lihat_berkas_psb.php`, yang memeriksa hak akses setiap
 * permintaan. Nama berkas selalu berasal dari kolom basis data pendaftar yang
 * berhak, tidak pernah dari parameter permintaan.
 */
final class BerkasPsb
{
    /** Batas yang sudah diumumkan di portal ("Maks 2MB"), kini ditegakkan server. */
    public const MAKS_BYTE = 2 * 1024 * 1024;

    /** Jenis isi yang diterima => ekstensi tersimpan. */
    public const JENIS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];

    /** Ekstensi nama asli yang diterima (aturan lama). */
    public const EKSTENSI = ['jpg', 'jpeg', 'png', 'pdf'];

    private string $privat;
    private string $lama;

    public function __construct(?string $root = null)
    {
        $root ??= defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2);
        $this->privat = rtrim($root, '/') . '/storage/private/psb';
        $this->lama = rtrim($root, '/') . '/berkas_psb';
    }

    /**
     * Memeriksa lalu menyimpan satu unggahan. Mengembalikan nama tersimpan.
     *
     * @param array{name?:mixed, tmp_name?:mixed, error?:mixed, size?:mixed} $file
     */
    public function simpan(array $file, string $kolom): string
    {
        if (!isset(PsbAdmin::BERKAS[$kolom])) {
            throw new PsbException('Jenis berkas tidak dikenal.');
        }
        $label = PsbAdmin::BERKAS[$kolom];
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new PsbException(in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? $label . ': ukuran paling besar 2 MB.' : $label . ': berkas gagal diunggah. Coba lagi.');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new PsbException($label . ': berkas unggahan tidak dikenali.');
        }

        return $this->simpanDari($tmp, (string) ($file['name'] ?? ''), $kolom, true);
    }

    /** Hanya untuk fixture uji (berkas biasa, bukan unggahan HTTP). */
    public function simpanUji(string $path, string $namaAsli, string $kolom): string
    {
        if (getenv('KEAMANAN_PSB_RUN_TESTS') !== '1') {
            throw new PsbException('Mode fixture tidak aktif.', [], 403);
        }

        return $this->simpanDari($path, $namaAsli, $kolom, false);
    }

    /**
     * Lokasi dan jenis isi berkas tersimpan, atau null bila tidak ada.
     *
     * @return array{path:string, mime:string, size:int, ext:string}|null
     */
    public function baca(?string $tersimpan): ?array
    {
        $nama = basename(str_replace('\\', '/', (string) $tersimpan));
        if ($nama === '' || $nama !== (string) $tersimpan || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,200}$/', $nama)) {
            return null;
        }
        foreach ([$this->privat, $this->lama] as $folder) {
            $path = $folder . '/' . $nama;
            if (!is_file($path) || is_link($path)) {
                continue;
            }
            $asli = realpath($path);
            $dasar = realpath($folder);
            if ($asli === false || $dasar === false || !str_starts_with($asli, $dasar . DIRECTORY_SEPARATOR)) {
                continue;
            }
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($asli);
            $mime = is_string($mime) && isset(self::JENIS[$mime]) ? $mime : 'application/octet-stream';

            return ['path' => $asli, 'mime' => $mime, 'size' => (int) filesize($asli), 'ext' => self::JENIS[$mime] ?? 'bin'];
        }

        return null;
    }

    public function folderPrivat(): string
    {
        return $this->privat;
    }

    private function simpanDari(string $sumber, string $namaAsli, string $kolom, bool $unggahan): string
    {
        $label = PsbAdmin::BERKAS[$kolom];
        $ext = strtolower(pathinfo(str_replace('\\', '/', $namaAsli), PATHINFO_EXTENSION));
        if (!in_array($ext, self::EKSTENSI, true)) {
            throw new PsbException($label . ': jenis berkas harus JPG, PNG, atau PDF.');
        }
        $size = filesize($sumber);
        if ($size === false || $size < 1 || $size > self::MAKS_BYTE) {
            throw new PsbException($label . ': ukuran paling besar 2 MB.');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($sumber);
        if (!is_string($mime) || !isset(self::JENIS[$mime])) {
            throw new PsbException($label . ': isi berkas bukan JPG, PNG, atau PDF.');
        }
        if ($mime === 'application/pdf') {
            $awal = (string) file_get_contents($sumber, false, null, 0, 5);
            if ($awal !== '%PDF-') {
                throw new PsbException($label . ': isi berkas bukan PDF yang sah.');
            }
        } else {
            $info = @getimagesize($sumber);
            if ($info === false || ($info['mime'] ?? '') !== $mime) {
                throw new PsbException($label . ': isi berkas bukan gambar yang sah.');
            }
        }

        $this->siapkanFolder();
        $nama = substr($kolom, 5) . '_' . bin2hex(random_bytes(16)) . '.' . self::JENIS[$mime];
        $tujuan = $this->privat . '/' . $nama;
        $ok = $unggahan ? move_uploaded_file($sumber, $tujuan) : copy($sumber, $tujuan);
        if (!$ok) {
            throw new PsbException($label . ': berkas tidak dapat disimpan di server.', [], 503);
        }
        @chmod($tujuan, 0600);

        return $nama;
    }

    /**
     * Folder privat dibuat bila belum ada, dengan penolakan akses web sendiri
     * sebagai lapis kedua di samping aturan `storage/` pada `.htaccess` akar.
     */
    private function siapkanFolder(): void
    {
        if (!is_dir($this->privat) && !@mkdir($this->privat, 0700, true) && !is_dir($this->privat)) {
            throw new PsbException('Penyimpanan berkas pendaftar tidak tersedia.', [], 503);
        }
        $htaccess = $this->privat . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n");
        }
    }
}
