<?php

declare(strict_types=1);

namespace App\Account;

use mysqli;
use mysqli_stmt;
use RuntimeException;
use Throwable;

/**
 * Relasi akun V2: user <-> pengurus dan user <-> wali.
 *
 * Keunikan relasi dijaga oleh unique key `users_pengurus_unique` dan `users_wali_unique`
 * pada basis data, bukan hanya oleh pemeriksaan aplikasi.
 */
final class PerizinanAccountRepository
{
    public function __construct(private mysqli $db)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function accounts(string $kind): array
    {
        $role = $kind === 'pengurus' ? 'pengurus' : 'orang_tua';

        return $this->select(
            "SELECT u.id, u.name, u.username, u.email, u.phone, u.is_active, u.force_password_change,
                    u.last_login_at, u.pengurus_id, u.wali_id,
                    pg.nama AS pengurus_nama, pg.jabatan, pg.is_active AS pengurus_aktif,
                    w.nama AS wali_nama, w.no_hp AS wali_hp, w.is_active AS wali_aktif,
                    (SELECT COUNT(*) FROM santri_wali sw WHERE sw.wali_id = u.wali_id AND sw.archived_at IS NULL) AS jumlah_santri
               FROM users u
               JOIN user_roles ur ON ur.user_id = u.id
               JOIN roles r ON r.id = ur.role_id AND r.slug = ?
               LEFT JOIN pengurus pg ON pg.id = u.pengurus_id
               LEFT JOIN wali w ON w.id = u.wali_id
              GROUP BY u.id
              ORDER BY u.name",
            [$role]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function availablePengurus(): array
    {
        return $this->select(
            'SELECT pg.id, pg.nama, pg.jabatan
               FROM pengurus pg
               LEFT JOIN users u ON u.pengurus_id = pg.id
              WHERE pg.is_active = 1 AND pg.archived_at IS NULL AND u.id IS NULL
              ORDER BY pg.nama'
        );
    }

    /**
     * Hanya wali aktif yang masih memiliki relasi santri aktif yang layak diberi akun.
     *
     * @return array<int, array<string, mixed>>
     */
    public function availableWali(): array
    {
        return $this->select(
            'SELECT w.id, w.nama, w.no_hp,
                    (SELECT COUNT(*) FROM santri_wali sw WHERE sw.wali_id = w.id AND sw.archived_at IS NULL) AS jumlah_santri
               FROM wali w
               LEFT JOIN users u ON u.wali_id = w.id
              WHERE w.is_active = 1 AND w.archived_at IS NULL AND u.id IS NULL
             HAVING jumlah_santri > 0
              ORDER BY w.nama'
        );
    }

    /**
     * Akun yang belum terhubung ke master pengurus/wali mana pun.
     *
     * @return array<int, array<string, mixed>>
     */
    public function unlinkedAccounts(): array
    {
        return $this->select(
            "SELECT u.id, u.name, u.username,
                    GROUP_CONCAT(r.slug ORDER BY r.slug SEPARATOR ',') AS roles
               FROM users u
               LEFT JOIN user_roles ur ON ur.user_id = u.id
               LEFT JOIN roles r ON r.id = ur.role_id
              WHERE u.pengurus_id IS NULL AND u.wali_id IS NULL AND u.guru_id IS NULL AND u.is_active = 1
              GROUP BY u.id
              ORDER BY u.name"
        );
    }

    /**
     * Relasi wali-santri aktif untuk pemeriksaan admin.
     *
     * @return array<int, array<string, mixed>>
     */
    public function waliRelations(int $waliId): array
    {
        return $this->select(
            'SELECT sw.id, sw.hubungan, sw.is_primary, sw.archived_at, s.id AS santri_id, s.nis, s.nama_santri,
                    s.is_active AS santri_aktif
               FROM santri_wali sw
               JOIN santri s ON s.id = sw.santri_id
              WHERE sw.wali_id = ?
              ORDER BY sw.archived_at IS NOT NULL, s.nama_santri',
            [$waliId]
        );
    }

    public function findUser(int $id): ?array
    {
        return $this->select(
            "SELECT u.id, u.name, u.username, u.is_active, u.guru_id, u.pengurus_id, u.wali_id,
                    GROUP_CONCAT(r.slug ORDER BY r.slug SEPARATOR ',') AS roles
               FROM users u
               LEFT JOIN user_roles ur ON ur.user_id = u.id
               LEFT JOIN roles r ON r.id = ur.role_id
              WHERE u.id = ? GROUP BY u.id LIMIT 1",
            [$id]
        )[0] ?? null;
    }

    /**
     * Membuat akun baru sekaligus relasi dan role, dalam satu transaksi.
     *
     * @param array<string, mixed> $data
     */
    public function createLinked(array $data, string $role, string $passwordHash, int $actorId): int
    {
        $this->db->begin_transaction();
        try {
            $this->execute(
                'INSERT INTO users (name, username, email, phone, password, pengurus_id, wali_id, is_active, force_password_change, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1, 1, NOW(), NOW())',
                [
                    $data['name'],
                    $data['username'],
                    $data['email'],
                    $data['phone'],
                    $passwordHash,
                    $data['pengurus_id'],
                    $data['wali_id'],
                ]
            );
            $id = (int) $this->db->insert_id;
            $this->attachRole($id, $role, $actorId);
            $this->db->commit();

            return $id;
        } catch (Throwable $exception) {
            $this->db->rollback();
            throw $exception;
        }
    }

    /**
     * Menghubungkan akun yang sudah ada ke master pengurus/wali dan menambahkan role.
     */
    public function linkExisting(int $userId, string $role, ?int $pengurusId, ?int $waliId, int $actorId): void
    {
        $this->db->begin_transaction();
        try {
            $this->execute(
                'UPDATE users SET pengurus_id = ?, wali_id = ?, updated_at = NOW() WHERE id = ?',
                [$pengurusId, $waliId, $userId]
            );
            $this->attachRole($userId, $role, $actorId);
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollback();
            throw $exception;
        }
    }

    private function attachRole(int $userId, string $role, int $actorId): void
    {
        $this->execute(
            'INSERT IGNORE INTO user_roles (user_id, role_id, assigned_by) SELECT ?, id, ? FROM roles WHERE slug = ?',
            [$userId, $actorId, $role]
        );
        $exists = $this->select(
            'SELECT 1 AS ada FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.slug = ? LIMIT 1',
            [$userId, $role]
        );
        if ($exists === []) {
            throw new RuntimeException('Role ' . $role . ' belum tersedia. Jalankan migrasi V2 Fase 1 terlebih dahulu.');
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function select(string $sql, array $params = []): array
    {
        $statement = $this->db->prepare($sql);
        if ($statement === false || !$this->run($statement, $params)) {
            throw new RuntimeException('Data akun perizinan tidak dapat dibaca.');
        }
        $result = $statement->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $statement->close();

        return $rows;
    }

    private function execute(string $sql, array $params): void
    {
        $statement = $this->db->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Perintah akun perizinan tidak dapat disiapkan.');
        }
        if (!$this->run($statement, $params)) {
            $errno = $statement->errno;
            $statement->close();
            if ($errno === 1062) {
                throw new RuntimeException('Username, email, pengurus, atau wali tersebut sudah dipakai akun lain.');
            }
            throw new RuntimeException('Perubahan akun perizinan gagal disimpan.');
        }
        $statement->close();
    }

    private function run(mysqli_stmt $statement, array $params): bool
    {
        if ($params !== []) {
            $types = '';
            $references = [];
            foreach ($params as $key => &$value) {
                $types .= is_int($value) || is_bool($value) ? 'i' : (is_float($value) ? 'd' : 's');
                $references[$key] = &$value;
            }
            unset($value);
            if (!$statement->bind_param($types, ...$references)) {
                return false;
            }
        }

        return $statement->execute();
    }
}
