<?php

declare(strict_types=1);

namespace App\Auth;

use App\Http\JsonResponse;
use mysqli;

final class ApiTokenAuthenticator
{
    public function __construct(private mysqli $db)
    {
    }

    public function requireRole(string $role): array
    {
        $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $matches)) {
            JsonResponse::error('UNAUTHENTICATED', 'Token bearer diperlukan.', 401);
        }

        $hash = hash('sha256', $matches[1]);
        $statement = $this->db->prepare(
            "SELECT u.id, u.name, u.username, u.guru_id, GROUP_CONCAT(DISTINCT r.slug ORDER BY r.slug SEPARATOR ',') roles
             FROM api_tokens t
             JOIN users u ON u.id = t.user_id AND u.is_active = 1
             JOIN user_roles ur ON ur.user_id = u.id
             JOIN roles r ON r.id = ur.role_id
             WHERE t.token_hash = ? AND t.revoked_at IS NULL AND t.expires_at > NOW()
             GROUP BY u.id LIMIT 1"
        );
        if ($statement === false) {
            JsonResponse::error('SERVER_ERROR', 'Layanan autentikasi belum siap.', 500);
        }
        $statement->bind_param('s', $hash);
        $statement->execute();
        $user = $statement->get_result()->fetch_assoc() ?: null;
        $statement->close();

        if ($user === null) {
            JsonResponse::error('UNAUTHENTICATED', 'Sesi atau token tidak valid.', 401);
        }
        $roles = $user['roles'] ? explode(',', $user['roles']) : [];
        if (!in_array($role, $roles, true)) {
            JsonResponse::error('FORBIDDEN', 'Akun tidak berhak mengakses sumber daya ini.', 403);
        }
        $user['roles'] = $roles;

        return $user;
    }
}

