<?php

declare(strict_types=1);

namespace App\Account;

use mysqli;
use mysqli_stmt;
use RuntimeException;
use Throwable;

/**
 * Akses data akun dan hak akses.
 *
 * **Koreksi ke-1 (30 Agustus 2026).** Metode `setRole()` lama DIHAPUS. Ia
 * menghapus SELURUH baris `user_roles` milik satu akun sebelum menetapkan satu
 * role pilihan, sehingga akun multi-peran kehilangan role lain secara diam-diam.
 * Penggantinya adalah `grantRole()` dan `revokeRole()` yang bekerja per satu
 * baris relasi, sehingga perubahan satu role tidak pernah menyentuh role lain.
 */
final class AccountRepository
{
    /** Role yang dikenal sistem. Sumbernya tabel `roles`. */
    public const ROLES = ['admin', 'guru', 'pengurus', 'orang_tua'];

    public function __construct(private mysqli $db)
    {
    }

    /**
     * Daftar akun dengan role dan identitas master yang terhubung.
     *
     * @param array{q?:string, role?:string, status?:string} $filters
     * @return array{rows:array<int, array<string, mixed>>, total:int}
     */
    public function paginate(array $filters, int $page, int $perPage): array
    {
        $where = ['1 = 1'];
        $params = [];

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(u.name LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR g.nama_guru LIKE ? OR pg.nama LIKE ? OR w.nama LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like, $like, $like);
        }

        $role = (string) ($filters['role'] ?? '');
        if (in_array($role, self::ROLES, true)) {
            $where[] = 'EXISTS (SELECT 1 FROM user_roles ur2 JOIN roles r2 ON r2.id = ur2.role_id WHERE ur2.user_id = u.id AND r2.slug = ?)';
            $params[] = $role;
        } elseif ($role === 'tanpa_role') {
            $where[] = 'NOT EXISTS (SELECT 1 FROM user_roles ur2 WHERE ur2.user_id = u.id)';
        }

        $status = (string) ($filters['status'] ?? '');
        if ($status === 'aktif') {
            $where[] = 'u.is_active = 1';
        } elseif ($status === 'nonaktif') {
            $where[] = 'u.is_active = 0';
        } elseif ($status === 'wajib_ganti_password') {
            $where[] = 'u.force_password_change = 1';
        }

        $join = ' FROM users u
                  LEFT JOIN guru g ON g.id = u.guru_id
                  LEFT JOIN pengurus pg ON pg.id = u.pengurus_id
                  LEFT JOIN wali w ON w.id = u.wali_id';
        $clause = ' WHERE ' . implode(' AND ', $where);

        $total = (int) ($this->one('SELECT COUNT(*) AS jumlah' . $join . $clause, $params)['jumlah'] ?? 0);

        $rows = $this->all(
            "SELECT u.id, u.name, u.username, u.email, u.phone, u.is_active, u.force_password_change,
                    u.last_login_at, u.guru_id, u.pengurus_id, u.wali_id,
                    g.nama_guru, g.is_active AS guru_aktif, g.archived_at AS guru_arsip,
                    pg.nama AS pengurus_nama, pg.jabatan AS pengurus_jabatan, pg.is_active AS pengurus_aktif, pg.archived_at AS pengurus_arsip,
                    w.nama AS wali_nama, w.no_hp AS wali_hp, w.is_active AS wali_aktif, w.archived_at AS wali_arsip,
                    (SELECT COUNT(*) FROM santri_wali sw WHERE sw.wali_id = u.wali_id AND sw.archived_at IS NULL) AS jumlah_santri,
                    (SELECT GROUP_CONCAT(DISTINCT r.slug ORDER BY r.slug SEPARATOR ',')
                       FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id) AS roles,
                    (SELECT COUNT(*) FROM murobi_assignments ma
                       JOIN tahun_ajaran ta ON ta.id = ma.tahun_ajaran_id
                      WHERE ma.guru_id = u.guru_id AND ma.is_active = 1 AND ma.archived_at IS NULL
                        AND ma.tanggal_mulai <= CURDATE()
                        AND (ma.tanggal_selesai IS NULL OR ma.tanggal_selesai >= CURDATE())
                        AND ta.status = 'Aktif' AND ta.archived_at IS NULL) AS murobi_aktif"
            . $join . $clause . ' ORDER BY u.name, u.id LIMIT ? OFFSET ?',
            [...$params, $perPage, ($page - 1) * $perPage]
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Guru yang belum memiliki akun. Guru yang diarsipkan tidak ditawarkan.
     */
    public function availableTeachers(): array
    {
        return $this->all(
            'SELECT g.id, g.nip, g.nama_guru
               FROM guru g
               LEFT JOIN users u ON u.guru_id = g.id
              WHERE u.id IS NULL AND g.archived_at IS NULL
              ORDER BY g.nama_guru'
        );
    }

    public function find(int $id): ?array
    {
        return $this->one(
            "SELECT u.id, u.name, u.username, u.email, u.phone, u.guru_id, u.pengurus_id, u.wali_id,
                    u.is_active, u.force_password_change,
                    g.nama_guru, g.is_active AS guru_aktif, g.archived_at AS guru_arsip,
                    pg.nama AS pengurus_nama, pg.is_active AS pengurus_aktif, pg.archived_at AS pengurus_arsip,
                    w.nama AS wali_nama, w.is_active AS wali_aktif, w.archived_at AS wali_arsip,
                    (SELECT GROUP_CONCAT(DISTINCT r.slug ORDER BY r.slug SEPARATOR ',')
                       FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id) AS roles
               FROM users u
               LEFT JOIN guru g ON g.id = u.guru_id
               LEFT JOIN pengurus pg ON pg.id = u.pengurus_id
               LEFT JOIN wali w ON w.id = u.wali_id
              WHERE u.id = ? LIMIT 1",
            [$id]
        );
    }

    public function createTeacher(array $data, string $passwordHash, int $actorId, ?callable $beforeCommit = null): int
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
            if ($beforeCommit !== null) { $beforeCommit($id); }
            $this->db->commit();
            return $id;
        } catch (Throwable $exception) {
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

    /**
     * Menambahkan SATU role tanpa menyentuh role lain milik akun tersebut.
     *
     * `INSERT IGNORE` membuat pemberian role bersifat idempoten: mengirim ulang
     * formulir yang sama tidak menghasilkan baris ganda atau galat.
     */
    public function grantRole(int $userId, string $role, int $actorId): void
    {
        $this->execute(
            'INSERT IGNORE INTO user_roles (user_id, role_id, assigned_by) SELECT ?, id, ? FROM roles WHERE slug = ?',
            [$userId, $actorId, $role]
        );
        if (!$this->hasRole($userId, $role)) {
            throw new RuntimeException('Role ' . $role . ' tidak dikenal sistem. Pastikan migrasi role sudah dijalankan.');
        }
    }

    /**
     * Mencabut SATU role. Role lain milik akun tidak tersentuh.
     */
    public function revokeRole(int $userId, string $role): void
    {
        $this->execute(
            'DELETE ur FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.slug = ?',
            [$userId, $role]
        );
    }

    public function hasRole(int $userId, string $role): bool
    {
        return $this->one(
            'SELECT 1 AS ada FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.slug = ? LIMIT 1',
            [$userId, $role]
        ) !== null;
    }

    /**
     * Jumlah akun admin yang masih aktif.
     *
     * `$lock` mengunci baris relasi admin yang dibaca (`FOR UPDATE`). Dipakai di
     * dalam transaksi supaya dua permintaan bersamaan yang sama-sama mencabut
     * atau menonaktifkan admin tidak bisa lolos bersama dan menyisakan nol
     * admin — pemeriksaan aplikasi saja tidak cukup untuk kasus itu.
     */
    public function countActiveAdmins(bool $lock = false): int
    {
        $sql = "SELECT COUNT(*) AS jumlah
                  FROM users u
                  JOIN user_roles ur ON ur.user_id = u.id
                  JOIN roles r ON r.id = ur.role_id AND r.slug = 'admin'
                 WHERE u.is_active = 1";

        return (int) ($this->one($sql . ($lock ? ' FOR UPDATE' : ''))['jumlah'] ?? 0);
    }

    public function resetPassword(int $id, string $hash): bool
    {
        $statement = $this->db->prepare('UPDATE users SET password = ?, force_password_change = 1, updated_at = NOW() WHERE id = ?');
        $statement->bind_param('si', $hash, $id);
        $ok = $statement->execute() && $statement->affected_rows === 1;
        $statement->close();
        return $ok;
    }

    public function transaction(callable $work): mixed
    {
        $this->db->begin_transaction();
        try {
            $result = $work();
            $this->db->commit();
            return $result;
        } catch (Throwable $exception) {
            $this->db->rollback();
            throw $exception;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function all(string $sql, array $params = []): array
    {
        $statement = $this->statement($sql, $params);
        $result = $statement->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $statement->close();

        return $rows;
    }

    private function one(string $sql, array $params = []): ?array
    {
        $statement = $this->statement($sql, $params);
        $result = $statement->get_result();
        $row = $result ? ($result->fetch_assoc() ?: null) : null;
        $statement->close();

        return $row;
    }

    private function execute(string $sql, array $params = []): void
    {
        $this->statement($sql, $params)->close();
    }

    private function statement(string $sql, array $params): mysqli_stmt
    {
        $statement = $this->db->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Perintah akun tidak dapat disiapkan.');
        }
        if ($params !== []) {
            $types = '';
            $references = [];
            foreach ($params as $key => &$value) {
                $types .= is_int($value) || is_bool($value) ? 'i' : (is_float($value) ? 'd' : 's');
                $references[$key] = &$value;
            }
            unset($value);
            $statement->bind_param($types, ...$references);
        }
        if (!$statement->execute()) {
            $errno = $statement->errno;
            $statement->close();
            throw new RuntimeException($errno === 1062 ? 'Data tersebut sudah dipakai akun lain.' : 'Perubahan akun gagal disimpan.');
        }

        return $statement;
    }
}
