<?php

declare(strict_types=1);

namespace App\Api;

use mysqli;
use mysqli_stmt;
use RuntimeException;

final class TeacherRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function db(): mysqli
    {
        return $this->db;
    }

    public function activeSchedulePatterns(array $user): array
    {
        $sql = "SELECT j.id, j.id_tahun, j.id_kelas, j.id_guru, j.hari, j.waktu_mulai, j.waktu_selesai,
                       j.fan_ilmu, j.nama_kitab, j.tempat, k.nama_kelas, k.jenjang,
                       g.nip, g.nama_guru, ta.tahun, ta.semester
                FROM jadwal_ngaji j
                JOIN tahun_ajaran ta ON ta.id = j.id_tahun
                JOIN kelas k ON k.id = j.id_kelas
                JOIN guru g ON g.id = j.id_guru
                WHERE j.is_active = 1 AND j.archived_at IS NULL
                  AND j.hari IS NOT NULL AND j.waktu_mulai IS NOT NULL AND j.waktu_selesai IS NOT NULL
                  AND ta.status = 'Aktif' AND ta.archived_at IS NULL";
        $params = [];
        if (!in_array('admin', $user['roles'], true)) {
            $sql .= ' AND j.id_guru = ?';
            $params[] = (int) $user['guru_id'];
        }
        return $this->all($sql . " ORDER BY FIELD(j.hari, 'Senin','Selasa','Rabu','Kamis','Jumat','Sabtu','Minggu'), j.waktu_mulai, j.id", $params);
    }

    public function meetingsInRange(array $user, string $from, string $to): array
    {
        $sql = "SELECT p.id, p.jadwal_id, p.tanggal_pertemuan, p.status, p.opened_at, p.completed_at
                FROM pertemuan_pengajian p
                JOIN jadwal_ngaji j ON j.id = p.jadwal_id
                WHERE p.tanggal_pertemuan BETWEEN ? AND ?";
        $params = [$from, $to];
        if (!in_array('admin', $user['roles'], true)) {
            $sql .= ' AND j.id_guru = ?';
            $params[] = (int) $user['guru_id'];
        }
        return $this->all($sql, $params);
    }

    public function scheduleFind(int $id, bool $forUpdate = false): ?array
    {
        return $this->one(
            "SELECT j.*, k.nama_kelas, k.jenjang, g.nip, g.nama_guru,
                    ta.tahun, ta.semester, ta.status AS tahun_status, ta.archived_at AS tahun_archived_at
             FROM jadwal_ngaji j
             JOIN tahun_ajaran ta ON ta.id = j.id_tahun
             JOIN kelas k ON k.id = j.id_kelas
             JOIN guru g ON g.id = j.id_guru
             WHERE j.id = ?" . ($forUpdate ? ' FOR UPDATE' : ''),
            [$id]
        );
    }

    public function meetingByScheduleDate(int $scheduleId, string $date, bool $forUpdate = false): ?array
    {
        return $this->one(
            'SELECT * FROM pertemuan_pengajian WHERE jadwal_id = ? AND tanggal_pertemuan = ?' . ($forUpdate ? ' FOR UPDATE' : ''),
            [$scheduleId, $date]
        );
    }

    public function createOpenedMeeting(int $scheduleId, string $date, ?string $notes, int $actorId): int
    {
        $this->execute(
            "INSERT INTO pertemuan_pengajian
                (jadwal_id, tanggal_pertemuan, status, catatan, created_by, opened_by, opened_at, created_at, updated_at)
             VALUES (?, ?, 'Dibuka', ?, ?, ?, NOW(), NOW(), NOW())",
            [$scheduleId, $date, $notes, $actorId, $actorId]
        );
        return (int) $this->db->insert_id;
    }

    public function snapshotParticipants(int $meetingId, int $classId, int $yearId): void
    {
        $this->execute(
            "INSERT INTO pertemuan_peserta
                (pertemuan_id, santri_id, plotting_kelas_id, nis_snapshot, nama_santri_snapshot, kelas_id_snapshot, tahun_ajaran_id_snapshot, created_at)
             SELECT ?, s.id, pk.id, s.nis, s.nama_santri, ?, ?, NOW()
             FROM plotting_kelas pk
             JOIN santri s ON s.id = pk.id_santri
             WHERE pk.id_kelas = ? AND pk.id_tahun = ? AND pk.status = 'Aktif'
             ORDER BY s.nama_santri, s.id",
            [$meetingId, $classId, $yearId, $classId, $yearId]
        );
    }

    public function meetingFind(int $id, bool $forUpdate = false): ?array
    {
        return $this->one(
            "SELECT p.*, j.id_guru, j.id_kelas, j.id_tahun, j.hari, j.waktu_mulai, j.waktu_selesai,
                    j.fan_ilmu, j.nama_kitab, j.tempat, g.nip, g.nama_guru,
                    k.nama_kelas, k.jenjang, ta.tahun, ta.semester
             FROM pertemuan_pengajian p
             JOIN jadwal_ngaji j ON j.id = p.jadwal_id
             JOIN guru g ON g.id = j.id_guru
             JOIN kelas k ON k.id = j.id_kelas
             JOIN tahun_ajaran ta ON ta.id = j.id_tahun
             WHERE p.id = ?" . ($forUpdate ? ' FOR UPDATE' : ''),
            [$id]
        );
    }

    public function meetingPage(array $user, ?string $from, ?string $to, int $page, int $perPage): array
    {
        $where = [];
        $params = [];
        if (!in_array('admin', $user['roles'], true)) {
            $where[] = 'j.id_guru = ?';
            $params[] = (int) $user['guru_id'];
        }
        if ($from !== null) {
            $where[] = 'p.tanggal_pertemuan >= ?';
            $params[] = $from;
        }
        if ($to !== null) {
            $where[] = 'p.tanggal_pertemuan <= ?';
            $params[] = $to;
        }
        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $fromSql = ' FROM pertemuan_pengajian p JOIN jadwal_ngaji j ON j.id = p.jadwal_id JOIN guru g ON g.id = j.id_guru JOIN kelas k ON k.id = j.id_kelas';
        $count = $this->one('SELECT COUNT(*) AS total' . $fromSql . $whereSql, $params);
        $offset = ($page - 1) * $perPage;
        $rows = $this->all(
            'SELECT p.id, p.jadwal_id, p.tanggal_pertemuan, p.status, p.opened_at, p.completed_at,
                    j.hari, j.waktu_mulai, j.waktu_selesai, j.fan_ilmu, j.nama_kitab, j.tempat,
                    g.nama_guru, k.nama_kelas,
                    (SELECT COUNT(*) FROM pertemuan_peserta pp WHERE pp.pertemuan_id = p.id) AS participant_count'
            . $fromSql . $whereSql . ' ORDER BY p.tanggal_pertemuan DESC, p.id DESC LIMIT ? OFFSET ?',
            [...$params, $perPage, $offset]
        );
        return ['rows' => $rows, 'total' => (int) ($count['total'] ?? 0)];
    }

    public function participantsWithAttendance(int $meetingId): array
    {
        return $this->all(
            "SELECT pp.santri_id, pp.nis_snapshot, pp.nama_santri_snapshot,
                    a.id AS attendance_id, a.status, a.catatan AS notes, a.dicatat_pada AS recorded_at, a.updated_at
             FROM pertemuan_peserta pp
             LEFT JOIN absensi_santri a ON a.pertemuan_id = pp.pertemuan_id AND a.santri_id = pp.santri_id
             WHERE pp.pertemuan_id = ?
             ORDER BY pp.nama_santri_snapshot, pp.santri_id",
            [$meetingId]
        );
    }

    public function teacherAttendance(int $meetingId): ?array
    {
        return $this->one(
            'SELECT id, guru_id, status, catatan AS notes, dicatat_pada AS recorded_at, updated_at FROM absensi_guru WHERE pertemuan_id = ? LIMIT 1',
            [$meetingId]
        );
    }

    public function upsertTeacherAttendance(int $meetingId, int $teacherId, string $status, ?string $notes, int $actorId): void
    {
        $this->execute(
            "INSERT INTO absensi_guru (pertemuan_id, guru_id, status, dicatat_pada, dicatat_oleh, catatan, created_at, updated_at)
             VALUES (?, ?, ?, NOW(), ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE status = VALUES(status), dicatat_pada = NOW(), dicatat_oleh = VALUES(dicatat_oleh), catatan = VALUES(catatan), updated_at = NOW()",
            [$meetingId, $teacherId, $status, $actorId, $notes]
        );
    }

    public function upsertStudentAttendance(int $meetingId, int $studentId, string $status, ?string $notes, int $actorId): void
    {
        $this->execute(
            "INSERT INTO absensi_santri (pertemuan_id, santri_id, status, dicatat_pada, dicatat_oleh, catatan, created_at, updated_at)
             VALUES (?, ?, ?, NOW(), ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE status = VALUES(status), dicatat_pada = NOW(), dicatat_oleh = VALUES(dicatat_oleh), catatan = VALUES(catatan), updated_at = NOW()",
            [$meetingId, $studentId, $status, $actorId, $notes]
        );
    }

    public function completeMeeting(int $meetingId, int $actorId): void
    {
        $this->execute(
            "UPDATE pertemuan_pengajian
             SET status = 'Selesai', completed_by = ?, completed_at = COALESCE(completed_at, NOW()), updated_at = NOW()
             WHERE id = ?",
            [$actorId, $meetingId]
        );
    }

    public function idempotencyFind(int $userId, string $key, bool $forUpdate = false): ?array
    {
        return $this->one(
            'SELECT * FROM api_idempotency_keys WHERE user_id = ? AND idempotency_key = ?' . ($forUpdate ? ' FOR UPDATE' : ''),
            [$userId, $key]
        );
    }

    public function idempotencyCreate(int $userId, string $key, string $operation, string $requestHash): int
    {
        try {
            $this->execute(
                'INSERT INTO api_idempotency_keys (user_id, idempotency_key, operation, request_hash, created_at) VALUES (?, ?, ?, ?, NOW())',
                [$userId, $key, $operation, $requestHash]
            );
        } catch (ApiException $exception) {
            if ($exception->errorCode() === 'DUPLICATE') {
                throw new ApiException('IDEMPOTENCY_RACE', 'Request identik sedang diproses. Silakan coba lagi.', 409);
            }
            throw $exception;
        }
        return (int) $this->db->insert_id;
    }

    public function idempotencyComplete(int $id, int $status, array $response, string $resourceType, int $resourceId): void
    {
        $json = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->execute(
            'UPDATE api_idempotency_keys SET response_status = ?, response_json = ?, resource_type = ?, resource_id = ?, completed_at = NOW() WHERE id = ?',
            [$status, $json, $resourceType, $resourceId, $id]
        );
    }

    private function one(string $sql, array $params = []): ?array
    {
        $statement = $this->statement($sql, $params);
        $row = $statement->get_result()->fetch_assoc() ?: null;
        $statement->close();
        return $row;
    }

    private function all(string $sql, array $params = []): array
    {
        $statement = $this->statement($sql, $params);
        $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
        $statement->close();
        return $rows;
    }

    private function execute(string $sql, array $params = []): void
    {
        $statement = $this->statement($sql, $params);
        $statement->close();
    }

    private function statement(string $sql, array $params): mysqli_stmt
    {
        $statement = $this->db->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Query API guru tidak dapat disiapkan: ' . $this->db->error);
        }
        try {
            if (!$statement->execute($params)) {
                $code = $statement->errno;
                $statement->close();
                if ($code === 1062) {
                    throw new ApiException('DUPLICATE', 'Data duplikat ditolak oleh basis data.', 409);
                }
                throw new RuntimeException('Query API guru gagal dijalankan.');
            }
        } catch (\mysqli_sql_exception $exception) {
            $statement->close();
            if ((int) $exception->getCode() === 1062) {
                throw new ApiException('DUPLICATE', 'Data duplikat ditolak oleh basis data.', 409);
            }
            throw $exception;
        }
        return $statement;
    }
}
