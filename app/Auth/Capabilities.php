<?php

declare(strict_types=1);

namespace App\Auth;

use mysqli;

/**
 * Kemampuan (capability) perizinan V2.
 *
 * Kemampuan selalu dihitung ulang dari basis data pada sisi server. Menyembunyikan
 * tombol pada UI tidak pernah dianggap sebagai kontrol akses (PRD 5.2).
 *
 * - `admin`      : role `admin`.
 * - `pengurus`   : role `pengurus` DAN `users.pengurus_id` menunjuk pengurus aktif.
 * - `murobi`     : role `guru` DAN ada `murobi_assignments` aktif yang cocok dengan
 *                  tahun ajaran aktif pada tanggal berjalan. Tidak ada role `murobi`.
 * - `orang_tua`  : role `orang_tua` DAN `users.wali_id` menunjuk wali aktif.
 */
final class Capabilities
{
    public const ADMIN = 'admin';
    public const PENGURUS = 'pengurus';
    public const MUROBI = 'murobi';
    public const ORANG_TUA = 'orang_tua';

    public const ALL = [self::ADMIN, self::PENGURUS, self::MUROBI, self::ORANG_TUA];

    /** @var array<int, array<int, string>> */
    private array $cache = [];

    public function __construct(private mysqli $db)
    {
    }

    /**
     * @param array{id:int, roles:array<int,string>, guru_id:int|null} $user
     * @return array<int, string>
     */
    public function forUser(array $user): array
    {
        $userId = (int) $user['id'];
        if (isset($this->cache[$userId])) {
            return $this->cache[$userId];
        }

        $roles = $user['roles'] ?? [];
        $capabilities = [];

        if (in_array('admin', $roles, true)) {
            $capabilities[] = self::ADMIN;
        }
        if (in_array('pengurus', $roles, true) && $this->linkedPengurusId($userId) !== null) {
            $capabilities[] = self::PENGURUS;
        }
        if (in_array('guru', $roles, true) && $this->hasActiveMurobiAssignment($userId)) {
            $capabilities[] = self::MUROBI;
        }
        if (in_array('orang_tua', $roles, true) && $this->linkedWaliId($userId) !== null) {
            $capabilities[] = self::ORANG_TUA;
        }

        return $this->cache[$userId] = $capabilities;
    }

    /**
     * @param array{id:int, roles:array<int,string>, guru_id:int|null} $user
     */
    public function has(array $user, string $capability): bool
    {
        return in_array($capability, $this->forUser($user), true);
    }

    /**
     * @param array{id:int, roles:array<int,string>, guru_id:int|null} $user
     * @param array<int, string> $capabilities
     */
    public function hasAny(array $user, array $capabilities): bool
    {
        return array_intersect($capabilities, $this->forUser($user)) !== [];
    }

    public function linkedPengurusId(int $userId): ?int
    {
        return $this->scalar(
            'SELECT p.id AS nilai
               FROM users u
               JOIN pengurus p ON p.id = u.pengurus_id
              WHERE u.id = ? AND u.is_active = 1 AND p.is_active = 1 AND p.archived_at IS NULL
              LIMIT 1',
            $userId
        );
    }

    public function linkedWaliId(int $userId): ?int
    {
        return $this->scalar(
            'SELECT w.id AS nilai
               FROM users u
               JOIN wali w ON w.id = u.wali_id
              WHERE u.id = ? AND u.is_active = 1 AND w.is_active = 1 AND w.archived_at IS NULL
              LIMIT 1',
            $userId
        );
    }

    /**
     * Guru tanpa penugasan murobi aktif tidak pernah memperoleh kemampuan keputusan.
     */
    public function hasActiveMurobiAssignment(int $userId): bool
    {
        return $this->scalar(
            "SELECT 1 AS nilai
               FROM users u
               JOIN guru g ON g.id = u.guru_id
               JOIN murobi_assignments ma ON ma.guru_id = g.id
               JOIN tahun_ajaran ta ON ta.id = ma.tahun_ajaran_id
               LEFT JOIN kelas kl ON kl.id = ma.kelas_id
                    AND kl.is_active = 1 AND kl.archived_at IS NULL
              WHERE u.id = ?
                AND u.is_active = 1
                AND g.is_active = 1 AND g.archived_at IS NULL
                AND ma.is_active = 1 AND ma.archived_at IS NULL
                AND ma.tanggal_mulai <= CURDATE()
                AND (ma.tanggal_selesai IS NULL OR ma.tanggal_selesai >= CURDATE())
                AND ta.status = 'Aktif' AND ta.archived_at IS NULL
                AND (ma.target_type = 'Kamar' OR (ma.target_type = 'Kelas' AND kl.id IS NOT NULL))
              LIMIT 1",
            $userId
        ) !== null;
    }

    public function forget(int $userId): void
    {
        unset($this->cache[$userId]);
    }

    private function scalar(string $sql, int $userId): ?int
    {
        $statement = $this->db->prepare($sql);
        if ($statement === false) {
            return null;
        }
        $statement->bind_param('i', $userId);
        if (!$statement->execute()) {
            $statement->close();
            return null;
        }
        $row = $statement->get_result()?->fetch_assoc();
        $statement->close();

        return $row === null || $row === false ? null : (int) $row['nilai'];
    }
}
