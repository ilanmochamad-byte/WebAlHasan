<?php

declare(strict_types=1);

namespace App\Auth;

use App\Http\JsonResponse;
use App\Http\Session;

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

    public function requireWebUser(): array
    {
        $user = $this->currentUser();
        if ($user === null) {
            header('Location: ' . app_url('/admin/admin_login.php?pesan=sesi'));
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
            http_response_code(403);
            exit('Akses ditolak. Akun ini tidak memiliki hak untuk membuka fungsi admin.');
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

