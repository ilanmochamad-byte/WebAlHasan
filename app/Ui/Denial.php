<?php

declare(strict_types=1);

namespace App\Ui;

/**
 * Halaman "akses ditolak" bersama (paket perapihan V1–V2).
 *
 * Satu tampilan untuk seluruh penolakan otorisasi web, sehingga pengguna selalu
 * mendapat penjelasan dan jalan keluar — bukan satu baris teks polos. Dipakai
 * oleh `Authorization::requireWebRole()` dan `PortalGuard`.
 *
 * Halaman ini adalah RESPONS penolakan, bukan pengganti pemeriksaan otorisasi:
 * pemanggilnya sudah memutuskan bahwa akses ditolak sebelum memanggil kelas ini.
 */
final class Denial
{
    public static function render(string $ringkas, string $penjelasan, int $status = 403): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/html; charset=utf-8');
        }
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $beranda = $e(app_url('/portal/index.php'));
        echo '<!doctype html><html lang="id"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Akses ditolak — Sistem Al Hasan</title>'
            . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">'
            . '<link rel="stylesheet" href="' . $e(app_url('/assets/ui/alhasan.css')) . '">'
            . '</head><body class="ah"><main class="container py-5" style="max-width:640px">'
            . '<div class="ah-card"><div class="ah-card__body">'
            . '<p class="ah-badge ah-badge--danger">' . $status . ' — Akses ditolak</p>'
            . '<h1 class="h4 mt-2">' . $e($ringkas) . '</h1>'
            . '<p class="text-muted">' . $e($penjelasan) . '</p>'
            . '<div class="d-flex flex-wrap gap-2 mt-3">'
            . '<a class="btn btn-primary" href="' . $beranda . '">Kembali ke beranda</a>'
            . '<a class="btn btn-outline-secondary" href="' . $e(app_url('/admin/logout.php')) . '">Keluar dan masuk sebagai akun lain</a>'
            . '</div></div></div></main></body></html>';
        exit;
    }
}
