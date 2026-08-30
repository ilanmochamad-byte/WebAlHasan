<?php

declare(strict_types=1);

namespace App\Auth;

use App\Http\JsonResponse;
use App\Http\SafeRedirect;
use App\Http\Session;
use App\Ui\Denial;

final class Authorization
{
    public function __construct(private AuthRepository $users)
    {
    }

    public function currentUser(): ?array
    {
        if (empty($_SESSION['user_id'])) {
            return null;
        }

        $user = $this->users->findActiveById((int) $_SESSION['user_id']);
        if ($user === null) {
            Session::destroy();
            return null;
        }

        $_SESSION['roles'] = $user['roles'];
        $_SESSION['force_password_change'] = $user['force_password_change'];

        return $user;
    }

    /**
     * Pengguna anonim diarahkan ke satu pintu masuk `/portal/`.
     *
     * Tujuan semula ikut dibawa sebagai `next` agar tautan detail yang dibuka
     * sebelum masuk dapat dipulihkan setelah login. `SafeRedirect` hanya
     * menerima alamat internal yang diizinkan; hak akses tetap diperiksa ulang
     * oleh guard halaman tujuan setelah pengalihan, termasuk bila pengguna
     * masuk dengan akun lain.
     */
    public function requireWebUser(): array
    {
        $user = $this->currentUser();
        if ($user === null) {
            $next = SafeRedirect::currentRequest();
            header('Location: ' . app_url('/portal/index.php') . '?pesan=sesi'
                . ($next === null ? '' : '&next=' . rawurlencode($next)));
            exit;
        }

        $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($user['force_password_change'] && $script !== 'ubah_password.php' && $script !== 'logout.php') {
            header('Location: ' . app_url('/admin/ubah_password.php'));
            exit;
        }

        return $user;
    }

    public function requireWebRole(string $role): array
    {
        $user = $this->requireWebUser();
        if (!in_array($role, $user['roles'], true)) {
            Denial::render(
                'Akun ini tidak memiliki hak untuk membuka fungsi tersebut.',
                'Halaman yang Anda buka hanya untuk pemegang role ' . $role . '. '
                    . 'Beranda dan menu lain yang menjadi hak Anda tetap dapat dibuka.'
            );
        }

        return $user;
    }

    public function requireApiRole(string $role): array
    {
        $user = $this->currentUser();
        if ($user === null) {
            JsonResponse::error('UNAUTHENTICATED', 'Sesi atau token tidak valid.', 401);
        }
        if (!in_array($role, $user['roles'], true)) {
            JsonResponse::error('FORBIDDEN', 'Akun tidak berhak mengakses sumber daya ini.', 403);
        }

        return $user;
    }
}

