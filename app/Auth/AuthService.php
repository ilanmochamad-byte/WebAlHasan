<?php

declare(strict_types=1);

namespace App\Auth;

use App\Audit\AuditLogger;

final class AuthService
{
    public function __construct(
        private AuthRepository $users,
        private AuditLogger $audit
    ) {
    }

    public function attempt(string $username, string $password): bool
    {
        $username = trim($username);
        $user = $username === '' ? null : $this->users->findByUsername($username);

        if ($user === null || !$user['is_active'] || !password_verify($password, $user['password'])) {
            $this->audit->log('login_failed', 'user', $user['id'] ?? null, null, [
                'username' => $username,
                'reason' => 'invalid_credentials_or_inactive',
            ]);
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['roles'] = $user['roles'];
        $_SESSION['force_password_change'] = $user['force_password_change'];

        // Penanda lama dipertahankan agar modul yang belum dimodernisasi tetap kompatibel.
        $_SESSION['status'] = 'login';
        $_SESSION['admin'] = $user['name'];

        $this->users->touchLastLogin($user['id']);
        $this->audit->log('login_succeeded', 'user', $user['id'], null, ['roles' => $user['roles']], $user['id']);

        return true;
    }
}

