<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Pemulihan tujuan setelah masuk (paket perapihan V1–V2, koreksi ke-7).
 *
 * Aturan pengguna: "Jika pengguna membuka tautan detail sebelum login, tujuan
 * dapat dipulihkan setelah login hanya jika merupakan alamat internal yang
 * diizinkan dan pengguna berhak mengaksesnya. Tolak tujuan pengalihan eksternal
 * atau tidak valid."
 *
 * Kelas ini hanya menyaring BENTUK alamatnya. Hak akses tetap diperiksa ulang
 * oleh guard halaman tujuan setelah pengalihan, termasuk ketika pengguna
 * berganti akun — sehingga `next` tidak pernah bisa dipakai untuk membuka
 * halaman yang tidak berhak dibuka akun tersebut.
 */
final class SafeRedirect
{
    /**
     * Folder internal yang boleh menjadi tujuan pemulihan.
     *
     * @var array<int, string>
     */
    private const ALLOWED_PREFIXES = ['/admin/', '/portal/'];

    /**
     * Halaman yang tidak masuk akal (atau berbahaya) sebagai tujuan kembali:
     * memulihkannya hanya menghasilkan lingkaran masuk–keluar.
     *
     * @var array<int, string>
     */
    private const BLOCKED_SCRIPTS = ['logout.php', 'cek_login.php', 'admin_login.php', 'index.php'];

    /**
     * Mengubah kandidat menjadi path internal yang aman, atau null bila ditolak.
     */
    public static function sanitize(mixed $candidate): ?string
    {
        if (!is_string($candidate)) {
            return null;
        }
        $value = trim($candidate);
        if ($value === '' || strlen($value) > 512) {
            return null;
        }
        // Tolak karakter kendali dan pemisah header.
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return null;
        }
        // Tolak alamat absolut, skema (termasuk javascript:/data:), dan
        // protocol-relative //host yang membawa pengguna ke situs lain.
        if (str_starts_with($value, '//') || str_starts_with($value, '\\\\') || preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*:#', $value) === 1) {
            return null;
        }
        if (!str_starts_with($value, '/')) {
            return null;
        }

        $path = (string) parse_url($value, PHP_URL_PATH);
        $query = parse_url($value, PHP_URL_QUERY);
        if ($path === '' || str_contains($path, '..')) {
            return null;
        }

        $base = app_url('');
        $base = $base === '/' ? '' : rtrim($base, '/');
        $relative = $base !== '' && str_starts_with($path, $base . '/') ? substr($path, strlen($base)) : $path;

        $allowed = false;
        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($relative, $prefix)) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed || !str_ends_with($relative, '.php')) {
            return null;
        }
        if (in_array(basename($relative), self::BLOCKED_SCRIPTS, true)) {
            return null;
        }

        return app_url($relative) . (is_string($query) && $query !== '' ? '?' . $query : '');
    }

    /**
     * Tujuan `next` untuk halaman yang sedang diminta pengguna anonim.
     */
    public static function currentRequest(): ?string
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            return null;
        }

        return self::sanitize((string) ($_SERVER['REQUEST_URI'] ?? ''));
    }
}
