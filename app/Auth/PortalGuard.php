<?php

declare(strict_types=1);

namespace App\Auth;

use App\Ui\Denial;

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
        Denial::render(
            'Akun ini tidak memiliki kemampuan perizinan yang diperlukan.',
            'Modul perizinan hanya terbuka bagi admin, pengurus, murobi (guru dengan penugasan murobi aktif), '
                . 'dan orang tua dengan relasi wali aktif. Beranda serta menu lain yang menjadi hak Anda tetap dapat dibuka.'
        );
    }
}
