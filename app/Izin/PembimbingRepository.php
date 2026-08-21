<?php

declare(strict_types=1);

namespace App\Izin;

use mysqli;
use mysqli_stmt;
use RuntimeException;

/**
 * Penugasan pembimbing: relasi pengurus dengan kamar/kelas pada satu tahun ajaran.
 */
final class PembimbingRepository
{
    public function __construct(private mysqli $db)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->select(
            "SELECT pa.*, pg.nama AS pengurus_nama, pg.jabatan,
                    ta.tahun, ta.semester, ta.status AS tahun_status,
                    COALESCE(km.nama_kamar, kl.nama_kelas) AS target_name
               FROM pembimbing_assignments pa
               JOIN pengurus pg ON pg.id = pa.pengurus_id
               JOIN tahun_ajaran ta ON ta.id = pa.tahun_ajaran_id
               LEFT JOIN kamar km ON km.id = pa.kamar_id
               LEFT JOIN kelas kl ON kl.id = pa.kelas_id
              ORDER BY ta.tahun DESC, pg.nama, pa.id"
        );
    }

    public function find(int $id): ?array
    {
        return $this->select('SELECT * FROM pembimbing_assignments WHERE id = ? LIMIT 1', [$id])[0] ?? null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function activeForPengurus(int $pengurusId, string $onDate): array
    {
        return $this->select(
            "SELECT pa.*, COALESCE(km.nama_kamar, kl.nama_kelas) AS target_name
               FROM pembimbing_assignments pa
               JOIN tahun_ajaran ta ON ta.id = pa.tahun_ajaran_id
                    AND ta.status = 'Aktif' AND ta.archived_at IS NULL
               LEFT JOIN kamar km ON km.id = pa.kamar_id
               LEFT JOIN kelas kl ON kl.id = pa.kelas_id
              WHERE pa.pengurus_id = ? AND pa.is_active = 1 AND pa.archived_at IS NULL
                AND pa.tanggal_mulai <= ? AND (pa.tanggal_selesai IS NULL OR pa.tanggal_selesai >= ?)
              ORDER BY pa.id",
            [$pengurusId, $onDate, $onDate]
        );
    }

    public function activePengurus(): array
    {
        return $this->select(
            'SELECT id, nama, jabatan FROM pengurus WHERE is_active = 1 AND archived_at IS NULL ORDER BY nama'
        );
    }

    public function pengurusIsActive(int $id): bool
    {
        return $this->select(
            'SELECT id FROM pengurus WHERE id = ? AND is_active = 1 AND archived_at IS NULL LIMIT 1',
            [$id]
        ) !== [];
    }

    public function yearIsUsable(int $id): bool
    {
        return $this->select(
            'SELECT id FROM tahun_ajaran WHERE id = ? AND archived_at IS NULL LIMIT 1',
            [$id]
        ) !== [];
    }

    public function kamarExists(int $id): bool
    {
        return $this->select('SELECT id FROM kamar WHERE id = ? LIMIT 1', [$id]) !== [];
    }

    public function kelasIsUsable(int $id): bool
    {
        return $this->select(
            'SELECT id FROM kelas WHERE id = ? AND is_active = 1 AND archived_at IS NULL LIMIT 1',
            [$id]
        ) !== [];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data, int $actorId): int
    {
        $this->execute(
            'INSERT INTO pembimbing_assignments
                (pengurus_id, tahun_ajaran_id, target_type, kamar_id, kelas_id, tanggal_mulai, tanggal_selesai, is_active, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)',
            [
                $data['pengurus_id'],
                $data['tahun_ajaran_id'],
                $data['target_type'],
                $data['kamar_id'],
                $data['kelas_id'],
                $data['tanggal_mulai'],
                $data['tanggal_selesai'],
                $actorId,
            ]
        );

        return (int) $this->db->insert_id;
    }

    public function setState(int $id, bool $active, ?bool $archive = null): void
    {
        if ($archive === null) {
            $this->execute(
                'UPDATE pembimbing_assignments SET is_active = ?, updated_at = NOW() WHERE id = ?',
                [$active ? 1 : 0, $id]
            );
            return;
        }

        $this->execute(
            'UPDATE pembimbing_assignments SET archived_at = ' . ($archive ? 'NOW()' : 'NULL')
            . ', is_active = ?, updated_at = NOW() WHERE id = ?',
            [$archive ? 0 : 1, $id]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function select(string $sql, array $params = []): array
    {
        $statement = $this->db->prepare($sql);
        if ($statement === false || !$this->run($statement, $params)) {
            throw new RuntimeException('Data penugasan pembimbing tidak dapat dibaca.');
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
            throw new RuntimeException('Perintah penugasan pembimbing tidak dapat disiapkan.');
        }
        if (!$this->run($statement, $params)) {
            $errno = $statement->errno;
            $statement->close();
            if ($errno === 1062) {
                throw IzinException::invalid('Penugasan pembimbing dengan pengurus, tahun ajaran, target, dan tanggal mulai yang sama sudah ada.');
            }
            if ($errno === 4025 || $errno === 3819) {
                throw IzinException::invalid('Penugasan pembimbing ditolak oleh aturan basis data. Periksa target kamar/kelas dan rentang tanggal.');
            }
            throw new RuntimeException('Penugasan pembimbing gagal disimpan.');
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
