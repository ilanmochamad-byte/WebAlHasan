<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Guard portal web berbasis kemampuan.
 *
 * Guard ini berjalan di server sebelum halaman apa pun dirender. Menyembunyikan
 * menu tidak pernah dipakai sebagai kontrol akses (PRD 5.2).
 */
final class PortalGuard
{
    public function __construct(
        private Authorization $authorization,
        private Capabilities $capabilities
    ) {
    }

    /**
     * Memastikan pengguna masuk dan memiliki sedikitnya satu kemampuan yang diminta.
     *
     * @param array<int, string> $allowed
     * @return array{user: array<string, mixed>, capabilities: array<int, string>}
     */
    public function require(array $allowed): array
    {
        $user = $this->authorization->requireWebUser();
        $granted = $this->capabilities->forUser($user);

        if (array_intersect($allowed, $granted) === []) {
            $this->deny();
        }

        return ['user' => $user, 'capabilities' => $granted];
    }

    /**
     * Portal perizinan terbuka bagi seluruh kemampuan perizinan.
     *
     * @return array{user: array<string, mixed>, capabilities: array<int, string>}
     */
    public function requireAnyPerizinan(): array
    {
        return $this->require(Capabilities::ALL);
    }

    private function deny(): never
    {
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="id"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Akses ditolak</title>'
            . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">'
            . '</head><body class="bg-light"><main class="container py-5" style="max-width:520px">'
            . '<div class="card border-0 shadow-sm"><div class="card-body p-4">'
            . '<h1 class="h4">403 — Akses ditolak</h1>'
            . '<p class="text-muted mb-3">Akun ini tidak memiliki kemampuan perizinan yang diperlukan untuk membuka halaman tersebut.</p>'
            . '<a class="btn btn-outline-secondary" href="' . htmlspecialchars(app_url('/admin/admin_login.php'), ENT_QUOTES, 'UTF-8') . '">Kembali ke halaman masuk</a>'
            . '</div></div></main></body></html>';
        exit;
    }
}
