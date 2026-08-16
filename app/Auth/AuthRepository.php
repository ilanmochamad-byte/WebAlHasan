<?php

declare(strict_types=1);

namespace App\Auth;

use mysqli;
use RuntimeException;

final class AuthRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function findByUsername(string $username): ?array
    {
        $statement = $this->db->prepare(
            "SELECT u.id, u.name, u.username, u.password, u.guru_id, u.is_active, u.force_password_change,
                    GROUP_CONCAT(DISTINCT r.slug ORDER BY r.slug SEPARATOR ',') AS roles
             FROM users u
             LEFT JOIN user_roles ur ON ur.user_id = u.id
             LEFT JOIN roles r ON r.id = ur.role_id
             WHERE u.username = ?
             GROUP BY u.id
             LIMIT 1"
        );
        if ($statement === false) {
            throw new RuntimeException('Skema autentikasi Fase 1 belum diterapkan.');
        }
        $statement->bind_param('s', $username);
        $statement->execute();
        $result = $statement->get_result();
        $user = $result->fetch_assoc() ?: null;
        $statement->close();

        return $user === null ? null : $this->normalize($user);
    }

    public function findActiveById(int $id): ?array
    {
        $statement = $this->db->prepare(
            "SELECT u.id, u.name, u.username, u.password, u.guru_id, u.is_active, u.force_password_change,
                    GROUP_CONCAT(DISTINCT r.slug ORDER BY r.slug SEPARATOR ',') AS roles
             FROM users u
             LEFT JOIN user_roles ur ON ur.user_id = u.id
             LEFT JOIN roles r ON r.id = ur.role_id
             WHERE u.id = ? AND u.is_active = 1
             GROUP BY u.id
             LIMIT 1"
        );
        if ($statement === false) {
            return null;
        }
        $statement->bind_param('i', $id);
        $statement->execute();
        $user = $statement->get_result()->fetch_assoc() ?: null;
        $statement->close();

        return $user === null ? null : $this->normalize($user);
    }

    public function touchLastLogin(int $id): void
    {
        $statement = $this->db->prepare('UPDATE users SET last_login_at = NOW(), updated_at = NOW() WHERE id = ?');
        if ($statement !== false) {
            $statement->bind_param('i', $id);
            $statement->execute();
            $statement->close();
        }
    }

    public function updatePassword(int $id, string $hash): bool
    {
        $statement = $this->db->prepare('UPDATE users SET password = ?, force_password_change = 0, updated_at = NOW() WHERE id = ? AND is_active = 1');
        if ($statement === false) {
            return false;
        }
        $statement->bind_param('si', $hash, $id);
        $ok = $statement->execute() && $statement->affected_rows === 1;
        $statement->close();

        return $ok;
    }

    private function normalize(array $user): array
    {
        $user['id'] = (int) $user['id'];
        $user['guru_id'] = $user['guru_id'] === null ? null : (int) $user['guru_id'];
        $user['is_active'] = (bool) $user['is_active'];
        $user['force_password_change'] = (bool) $user['force_password_change'];
        $user['roles'] = $user['roles'] ? explode(',', (string) $user['roles']) : [];

        return $user;
    }
}

