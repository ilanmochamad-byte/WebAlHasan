<?php

declare(strict_types=1);

namespace App\Account;

use mysqli;
use RuntimeException;

final class AccountRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function all(): array
    {
        $result = $this->db->query(
            "SELECT u.id, u.name, u.username, u.email, u.phone, u.guru_id, u.is_active, u.force_password_change,
                    u.last_login_at, g.nama_guru, GROUP_CONCAT(r.slug ORDER BY r.slug SEPARATOR ',') roles
             FROM users u
             LEFT JOIN guru g ON g.id = u.guru_id
             LEFT JOIN user_roles ur ON ur.user_id = u.id
             LEFT JOIN roles r ON r.id = ur.role_id
             GROUP BY u.id
             ORDER BY u.name"
        );
        if ($result === false) {
            throw new RuntimeException('Daftar akun tidak dapat dibaca.');
        }

        return $result->fetch_all(MYSQLI_ASSOC);
    }

    public function availableTeachers(): array
    {
        $result = $this->db->query(
            'SELECT g.id, g.nip, g.nama_guru FROM guru g LEFT JOIN users u ON u.guru_id = g.id WHERE u.id IS NULL ORDER BY g.nama_guru'
        );
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    public function find(int $id): ?array
    {
        $statement = $this->db->prepare(
            "SELECT u.id, u.name, u.username, u.email, u.phone, u.guru_id, u.is_active, u.force_password_change,
                    GROUP_CONCAT(r.slug ORDER BY r.slug SEPARATOR ',') roles
             FROM users u
             LEFT JOIN user_roles ur ON ur.user_id = u.id
             LEFT JOIN roles r ON r.id = ur.role_id
             WHERE u.id = ? GROUP BY u.id LIMIT 1"
        );
        $statement->bind_param('i', $id);
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc() ?: null;
        $statement->close();
        return $row;
    }

    public function createTeacher(array $data, string $passwordHash, int $actorId): int
    {
        $this->db->begin_transaction();
        try {
            $statement = $this->db->prepare(
                'INSERT INTO users (name, username, email, phone, password, guru_id, is_active, force_password_change, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 1, 1, NOW(), NOW())'
            );
            $statement->bind_param('sssssi', $data['name'], $data['username'], $data['email'], $data['phone'], $passwordHash, $data['guru_id']);
            if (!$statement->execute()) {
                throw new RuntimeException($statement->errno === 1062 ? 'Username, email, atau guru sudah digunakan akun lain.' : 'Akun guru gagal dibuat.');
            }
            $id = (int) $statement->insert_id;
            $statement->close();

            $role = 'guru';
            $statement = $this->db->prepare('INSERT INTO user_roles (user_id, role_id, assigned_by) SELECT ?, id, ? FROM roles WHERE slug = ?');
            $statement->bind_param('iis', $id, $actorId, $role);
            if (!$statement->execute()) {
                throw new RuntimeException('Role guru gagal ditetapkan.');
            }
            $statement->close();
            $this->db->commit();
            return $id;
        } catch (\Throwable $exception) {
            $this->db->rollback();
            throw $exception;
        }
    }

    public function setActive(int $id, bool $active): bool
    {
        $statement = $this->db->prepare('UPDATE users SET is_active = ?, updated_at = NOW() WHERE id = ?');
        $value = $active ? 1 : 0;
        $statement->bind_param('ii', $value, $id);
        $ok = $statement->execute() && $statement->affected_rows === 1;
        $statement->close();
        return $ok;
    }

    public function setRole(int $id, string $role, int $actorId): void
    {
        $this->db->begin_transaction();
        try {
            $statement = $this->db->prepare('DELETE FROM user_roles WHERE user_id = ?');
            $statement->bind_param('i', $id);
            $statement->execute();
            $statement->close();

            $statement = $this->db->prepare('INSERT INTO user_roles (user_id, role_id, assigned_by) SELECT ?, id, ? FROM roles WHERE slug = ?');
            $statement->bind_param('iis', $id, $actorId, $role);
            if (!$statement->execute() || $statement->affected_rows !== 1) {
                throw new RuntimeException('Role tidak valid.');
            }
            $statement->close();
            $this->db->commit();
        } catch (\Throwable $exception) {
            $this->db->rollback();
            throw $exception;
        }
    }

    public function resetPassword(int $id, string $hash): bool
    {
        $statement = $this->db->prepare('UPDATE users SET password = ?, force_password_change = 1, updated_at = NOW() WHERE id = ?');
        $statement->bind_param('si', $hash, $id);
        $ok = $statement->execute() && $statement->affected_rows === 1;
        $statement->close();
        return $ok;
    }
}

