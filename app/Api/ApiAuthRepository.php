<?php

declare(strict_types=1);

namespace App\Api;

use mysqli;
use RuntimeException;

final class ApiAuthRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function loginCandidate(string $username): ?array
    {
        $row = $this->one(
            "SELECT u.id, u.name, u.username, u.password, u.guru_id, u.is_active, u.force_password_change,
                    g.nip, g.nama_guru, g.is_active AS guru_is_active, g.archived_at AS guru_archived_at,
                    GROUP_CONCAT(DISTINCT r.slug ORDER BY r.slug SEPARATOR ',') AS roles
             FROM users u
             LEFT JOIN guru g ON g.id = u.guru_id
             LEFT JOIN user_roles ur ON ur.user_id = u.id
             LEFT JOIN roles r ON r.id = ur.role_id
             WHERE u.username = ?
             GROUP BY u.id
             LIMIT 1",
            [$username]
        );

        return $row === null ? null : $this->normalizeUser($row);
    }

    public function createToken(int $userId, string $hash, string $name, int $ttlDays): array
    {
        $this->execute(
            'INSERT INTO api_tokens (user_id, token_hash, name, expires_at, created_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY), NOW())',
            [$userId, $hash, $name, $ttlDays]
        );
        $id = (int) $this->db->insert_id;
        $row = $this->one('SELECT id, expires_at FROM api_tokens WHERE id = ?', [$id]);
        if ($row === null) {
            throw new RuntimeException('Token API gagal dibuat.');
        }
        return ['id' => (int) $row['id'], 'expires_at' => (string) $row['expires_at']];
    }

    public function touchLogin(int $userId): void
    {
        $this->execute('UPDATE users SET last_login_at = NOW(), updated_at = NOW() WHERE id = ?', [$userId]);
    }

    public function revokeToken(int $tokenId): void
    {
        $this->execute('UPDATE api_tokens SET revoked_at = COALESCE(revoked_at, NOW()) WHERE id = ?', [$tokenId]);
    }

    public function publicProfile(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'name' => (string) $user['name'],
            'username' => (string) $user['username'],
            'guru' => $user['guru_id'] === null ? null : [
                'id' => (int) $user['guru_id'],
                'nip' => $user['nip'] === null ? null : (string) $user['nip'],
                'name' => (string) $user['nama_guru'],
            ],
            'roles' => array_values($user['roles']),
        ];
    }

    private function normalizeUser(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['guru_id'] = $row['guru_id'] === null ? null : (int) $row['guru_id'];
        $row['is_active'] = (bool) $row['is_active'];
        $row['force_password_change'] = (bool) $row['force_password_change'];
        $row['guru_is_active'] = $row['guru_is_active'] === null ? null : (bool) $row['guru_is_active'];
        $row['roles'] = empty($row['roles']) ? [] : explode(',', (string) $row['roles']);
        return $row;
    }

    private function one(string $sql, array $params): ?array
    {
        $statement = $this->statement($sql, $params);
        $row = $statement->get_result()->fetch_assoc() ?: null;
        $statement->close();
        return $row;
    }

    private function execute(string $sql, array $params): void
    {
        $statement = $this->statement($sql, $params);
        $statement->close();
    }

    private function statement(string $sql, array $params): \mysqli_stmt
    {
        $statement = $this->db->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Query autentikasi API tidak dapat disiapkan.');
        }
        if (!$statement->execute($params)) {
            $statement->close();
            throw new RuntimeException('Query autentikasi API gagal dijalankan.');
        }
        return $statement;
    }
}
