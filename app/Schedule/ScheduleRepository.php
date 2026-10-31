<?php

declare(strict_types=1);

namespace App\Schedule;

use mysqli;
use mysqli_stmt;
use RuntimeException;

final class ScheduleRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function db(): mysqli
    {
        return $this->db;
    }

    public function activeYear(): ?array
    {
        return $this->one("SELECT id, tahun, semester, status FROM tahun_ajaran WHERE status = 'Aktif' AND archived_at IS NULL LIMIT 1");
    }

    public function yearFind(int $id): ?array
    {
        return $this->one('SELECT id, tahun, semester, status, archived_at FROM tahun_ajaran WHERE id = ?', [$id]);
    }

    public function teacherFind(int $id): ?array
    {
        return $this->one('SELECT id, nama_guru, is_active, archived_at FROM guru WHERE id = ?', [$id]);
    }

    public function classFind(int $id): ?array
    {
        return $this->one('SELECT id, nama_kelas, jenjang, is_active, archived_at FROM kelas WHERE id = ?', [$id]);
    }

    public function yearOptions(): array
    {
        return $this->all("SELECT id, tahun, semester, status FROM tahun_ajaran WHERE archived_at IS NULL ORDER BY status = 'Aktif' DESC, tahun DESC, semester");
    }

    public function teacherOptions(): array
    {
        return $this->all('SELECT id, nip, nama_guru FROM guru WHERE is_active = 1 AND archived_at IS NULL ORDER BY nama_guru');
    }

    public function classOptions(): array
    {
        return $this->all('SELECT id, nama_kelas, jenjang FROM kelas WHERE is_active = 1 AND archived_at IS NULL ORDER BY jenjang, nama_kelas');
    }

    public function scheduleList(array $filters, int $page, int $perPage): array
    {
        [$where, $params] = $this->scheduleWhere($filters);
        $count = $this->one('SELECT COUNT(*) total FROM jadwal_ngaji j ' . $where, $params);
        $offset = ($page - 1) * $perPage;
        $rows = $this->all(
            "SELECT j.*, ta.tahun, ta.semester, ta.status AS tahun_status, k.nama_kelas, k.jenjang, g.nama_guru,
                    {$this->kelasLabel()} AS kelas_label, {$this->kelasIds()} AS kelas_ids
             FROM jadwal_ngaji j
             JOIN tahun_ajaran ta ON ta.id = j.id_tahun
             JOIN kelas k ON k.id = j.id_kelas
             JOIN guru g ON g.id = j.id_guru
             {$where}
             ORDER BY ta.status = 'Aktif' DESC, FIELD(j.hari, 'Senin','Selasa','Rabu','Kamis','Jumat','Sabtu','Minggu'), j.waktu_mulai, j.id
             LIMIT ? OFFSET ?",
            [...$params, $perPage, $offset]
        );
        return ['rows' => $rows, 'total' => (int) ($count['total'] ?? 0), 'page' => $page, 'per_page' => $perPage];
    }

    public function scheduleFind(int $id, bool $forUpdate = false): ?array
    {
        return $this->one(
            "SELECT j.*, ta.tahun, ta.semester, ta.status AS tahun_status, ta.archived_at AS tahun_archived_at,
                    k.nama_kelas, k.jenjang, g.nama_guru,
                    {$this->kelasLabel()} AS kelas_label, {$this->kelasIds()} AS kelas_ids
             FROM jadwal_ngaji j
             JOIN tahun_ajaran ta ON ta.id = j.id_tahun
             JOIN kelas k ON k.id = j.id_kelas
             JOIN guru g ON g.id = j.id_guru
             WHERE j.id = ?" . ($forUpdate ? ' FOR UPDATE' : ''),
            [$id]
        );
    }

    public function scheduleCreate(array $data, int $actorId): int
    {
        $sql = 'INSERT INTO jadwal_ngaji
            (id_tahun, waktu_sholat, hari, jam, waktu_mulai, waktu_selesai, jam_migration_status, jam_migration_note, id_kelas, fan_ilmu, nama_kitab, id_guru, tempat, is_active, archived_at, created_by, updated_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NULL, ?, ?, NOW(), NOW())';
        $this->execute($sql, [
            $data['id_tahun'], $data['waktu_sholat'], $data['hari'], $data['jam'], $data['waktu_mulai'], $data['waktu_selesai'],
            'Berhasil', 'Dibuat dari waktu terstruktur pada aplikasi Fase 3.', $data['id_kelas'], $data['fan_ilmu'], $data['nama_kitab'],
            $data['id_guru'], $data['tempat'], $actorId, $actorId,
        ]);
        return (int) $this->db->insert_id;
    }

    public function scheduleUpdate(int $id, array $data, int $actorId): void
    {
        $this->execute(
            'UPDATE jadwal_ngaji SET id_tahun = ?, waktu_sholat = ?, hari = ?, jam = ?, waktu_mulai = ?, waktu_selesai = ?, jam_migration_status = ?, jam_migration_note = ?, id_kelas = ?, fan_ilmu = ?, nama_kitab = ?, id_guru = ?, tempat = ?, updated_by = ?, updated_at = NOW() WHERE id = ?',
            [
                $data['id_tahun'], $data['waktu_sholat'], $data['hari'], $data['jam'], $data['waktu_mulai'], $data['waktu_selesai'],
                'Berhasil', 'Diperbarui dari waktu terstruktur pada aplikasi Fase 3; nilai jam tampilan diselaraskan.', $data['id_kelas'],
                $data['fan_ilmu'], $data['nama_kitab'], $data['id_guru'], $data['tempat'], $actorId, $id,
            ]
        );
    }

    public function scheduleSetState(int $id, bool $active, bool $archive, int $actorId): void
    {
        $this->execute(
            'UPDATE jadwal_ngaji SET is_active = ?, archived_at = CASE WHEN ? = 1 THEN COALESCE(archived_at, NOW()) ELSE NULL END, updated_by = ?, updated_at = NOW() WHERE id = ?',
            [$active ? 1 : 0, $archive ? 1 : 0, $actorId, $id]
        );
    }

    public function teacherConflict(array $data, ?int $excludeId = null): ?array
    {
        $sql = "SELECT j.id, g.nama_guru, j.hari, j.waktu_mulai, j.waktu_selesai
                FROM jadwal_ngaji j
                JOIN guru g ON g.id = j.id_guru
                JOIN tahun_ajaran ta ON ta.id = j.id_tahun
                WHERE j.id_tahun = ? AND ta.status = 'Aktif' AND ta.archived_at IS NULL
                  AND j.id_guru = ? AND j.hari = ? AND j.is_active = 1 AND j.archived_at IS NULL
                  AND j.waktu_mulai < ? AND j.waktu_selesai > ?";
        $params = [$data['id_tahun'], $data['id_guru'], $data['hari'], $data['waktu_selesai'], $data['waktu_mulai']];
        if ($excludeId !== null) {
            $sql .= ' AND j.id <> ?';
            $params[] = $excludeId;
        }
        $sql .= ' ORDER BY j.waktu_mulai LIMIT 1';
        return $this->one($sql, $params);
    }

    /**
     * Jadwal lain yang beririsan waktu dengan kelas atau tempat yang sama.
     *
     * Kelas dibandingkan sebagai HIMPUNAN: seluruh kelas jadwal ini (utama +
     * gabungan) terhadap seluruh kelas jadwal lain. Kelas-kelas di dalam satu
     * jadwal gabungan tidak pernah dibandingkan satu sama lain (jadwal sendiri
     * dikecualikan lewat `$excludeId`).
     */
    public function warningConflicts(array $data, ?int $excludeId = null): array
    {
        $kelas = array_values(array_map('intval', $data['kelas_ids'] ?? [$data['id_kelas']]));
        $mencakup = implode(' OR ', array_fill(0, count($kelas), KelasJadwal::jadwalMencakup($this->db)));
        $sql = "SELECT j.id, {$this->kelasLabel()} AS nama_kelas, {$this->kelasIds()} AS kelas_ids,
                       j.tempat, j.waktu_mulai, j.waktu_selesai,
                       ({$mencakup}) AS class_conflict,
                       (LOWER(TRIM(j.tempat)) = LOWER(TRIM(?))) AS place_conflict
                FROM jadwal_ngaji j
                JOIN tahun_ajaran ta ON ta.id = j.id_tahun
                WHERE j.id_tahun = ? AND ta.status = 'Aktif' AND ta.archived_at IS NULL
                  AND j.hari = ? AND j.is_active = 1 AND j.archived_at IS NULL
                  AND j.waktu_mulai < ? AND j.waktu_selesai > ?
                  AND ({$mencakup} OR LOWER(TRIM(j.tempat)) = LOWER(TRIM(?)))";
        $params = [...$kelas, $data['tempat'], $data['id_tahun'], $data['hari'], $data['waktu_selesai'], $data['waktu_mulai'], ...$kelas, $data['tempat']];
        if ($excludeId !== null) {
            $sql .= ' AND j.id <> ?';
            $params[] = $excludeId;
        }
        return $this->all($sql . ' ORDER BY j.waktu_mulai, j.id', $params);
    }

    /** @return array<int, string> id kelas => nama, untuk kelas yang diminta */
    public function classNames(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return [];
        }
        $rows = $this->all('SELECT id, nama_kelas FROM kelas WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
        return array_column($rows, 'nama_kelas', 'id');
    }

    /**
     * Menyamakan `jadwal_kelas` dengan pilihan admin. Dipanggil di dalam
     * transaksi sesudah baris jadwal dikunci, sehingga tidak bisa bersilang
     * dengan pembukaan pertemuan yang mengunci baris jadwal yang sama.
     *
     * @param list<int> $kelasIds kelas utama di posisi pertama
     */
    public function syncScheduleClasses(int $scheduleId, array $kelasIds, int $actorId): void
    {
        if (!KelasJadwal::terpasang($this->db)) {
            if (count($kelasIds) > 1) {
                throw new ScheduleException('Kelas gabungan belum dapat disimpan: migrasi 034 belum dipasang.');
            }
            return;
        }
        $utama = (int) $kelasIds[0];
        $in = implode(',', array_fill(0, count($kelasIds), '?'));
        $this->execute("DELETE FROM jadwal_kelas WHERE jadwal_id = ? AND kelas_id NOT IN ({$in})", [$scheduleId, ...$kelasIds]);
        foreach ($kelasIds as $kelasId) {
            $this->execute(
                'INSERT INTO jadwal_kelas (jadwal_id, kelas_id, is_utama, created_by, created_at) VALUES (?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE is_utama = VALUES(is_utama)',
                [$scheduleId, (int) $kelasId, (int) $kelasId === $utama ? 1 : 0, $actorId]
            );
        }
    }

    public function meetingList(array $user, int $limit = 100): array
    {
        $sql = "SELECT p.*, j.hari, j.waktu_mulai, j.waktu_selesai, j.fan_ilmu, j.nama_kitab, j.tempat,
                       g.nama_guru, k.nama_kelas, ta.tahun, ta.semester,
                       {$this->kelasPertemuanLabel()} AS kelas_label,
                       (SELECT COUNT(*) FROM pertemuan_peserta pp WHERE pp.pertemuan_id = p.id) AS participant_count
                FROM pertemuan_pengajian p
                JOIN jadwal_ngaji j ON j.id = p.jadwal_id
                JOIN guru g ON g.id = j.id_guru
                JOIN kelas k ON k.id = j.id_kelas
                JOIN tahun_ajaran ta ON ta.id = j.id_tahun";
        $params = [];
        if (!in_array('admin', $user['roles'], true)) {
            $sql .= ' WHERE j.id_guru = ?';
            $params[] = (int) ($user['guru_id'] ?? 0);
        }
        return $this->all($sql . ' ORDER BY p.tanggal_pertemuan DESC, p.id DESC LIMIT ?', [...$params, $limit]);
    }

    public function meetingPage(array $user, string $q, int $page): array
    {
        $sql = "SELECT p.*, j.hari, j.waktu_mulai, j.waktu_selesai, j.fan_ilmu, j.nama_kitab, j.tempat,
                       g.nama_guru, k.nama_kelas, ta.tahun, ta.semester,
                       {$this->kelasPertemuanLabel()} AS kelas_label,
                       (SELECT COUNT(*) FROM pertemuan_peserta pp WHERE pp.pertemuan_id = p.id) AS participant_count
                FROM pertemuan_pengajian p
                JOIN jadwal_ngaji j ON j.id = p.jadwal_id
                JOIN guru g ON g.id = j.id_guru
                JOIN kelas k ON k.id = j.id_kelas
                JOIN tahun_ajaran ta ON ta.id = j.id_tahun";
        $params = [];
        if (!in_array('admin', $user['roles'], true)) {
            $sql .= ' WHERE j.id_guru = ?';
            $params[] = (int) ($user['guru_id'] ?? 0);
        }
        return \App\Database\PageQuery::fetch($this->db, $sql, $params, 'tanggal_pertemuan DESC, id DESC', $page, $q, ['tanggal_pertemuan', 'nama_guru', 'nama_kelas', 'kelas_label', 'fan_ilmu', 'status']);
    }

    public function activeScheduleOptionsForUser(array $user): array
    {
        $sql = "SELECT j.id, j.hari, j.waktu_mulai, j.waktu_selesai, j.fan_ilmu, j.nama_kitab, j.tempat,
                       g.nama_guru, k.nama_kelas, ta.tahun, ta.semester, {$this->kelasLabel()} AS kelas_label
                FROM jadwal_ngaji j
                JOIN guru g ON g.id = j.id_guru
                JOIN kelas k ON k.id = j.id_kelas
                JOIN tahun_ajaran ta ON ta.id = j.id_tahun
                WHERE j.is_active = 1 AND j.archived_at IS NULL AND j.hari IS NOT NULL
                  AND j.waktu_mulai IS NOT NULL AND j.waktu_selesai IS NOT NULL
                  AND ta.status = 'Aktif' AND ta.archived_at IS NULL";
        $params = [];
        if (!in_array('admin', $user['roles'], true)) {
            $sql .= ' AND j.id_guru = ?';
            $params[] = (int) ($user['guru_id'] ?? 0);
        }
        return $this->all($sql . " ORDER BY FIELD(j.hari, 'Senin','Selasa','Rabu','Kamis','Jumat','Sabtu','Minggu'), j.waktu_mulai, j.id", $params);
    }

    public function meetingFind(int $id, bool $forUpdate = false): ?array
    {
        return $this->one(
            "SELECT p.*, j.id_guru, j.id_kelas, j.id_tahun, j.hari, j.waktu_mulai, j.waktu_selesai,
                    j.fan_ilmu, j.nama_kitab, j.tempat, g.nama_guru, k.nama_kelas, ta.tahun, ta.semester,
                    {$this->kelasPertemuanLabel()} AS kelas_label
             FROM pertemuan_pengajian p
             JOIN jadwal_ngaji j ON j.id = p.jadwal_id
             JOIN guru g ON g.id = j.id_guru
             JOIN kelas k ON k.id = j.id_kelas
             JOIN tahun_ajaran ta ON ta.id = j.id_tahun
             WHERE p.id = ?" . ($forUpdate ? ' FOR UPDATE' : ''),
            [$id]
        );
    }

    public function meetingParticipants(int $meetingId): array
    {
        return $this->all('SELECT pp.id, pp.santri_id, pp.plotting_kelas_id, pp.nis_snapshot, pp.nama_santri_snapshot, pp.kelas_id_snapshot, pp.tahun_ajaran_id_snapshot, pp.created_at, k.nama_kelas AS kelas_asal FROM pertemuan_peserta pp LEFT JOIN kelas k ON k.id = pp.kelas_id_snapshot WHERE pp.pertemuan_id = ? ORDER BY pp.nama_santri_snapshot, pp.santri_id', [$meetingId]);
    }

    public function meetingByScheduleDate(int $scheduleId, string $date, bool $forUpdate = false): ?array
    {
        return $this->one('SELECT * FROM pertemuan_pengajian WHERE jadwal_id = ? AND tanggal_pertemuan = ?' . ($forUpdate ? ' FOR UPDATE' : ''), [$scheduleId, $date]);
    }

    public function meetingCreate(int $scheduleId, string $date, string $notes, int $actorId): int
    {
        $this->execute('INSERT INTO pertemuan_pengajian (jadwal_id, tanggal_pertemuan, status, catatan, created_by, created_at, updated_at) VALUES (?, ?, \'Draf\', ?, ?, NOW(), NOW())', [$scheduleId, $date, $notes !== '' ? $notes : null, $actorId]);
        return (int) $this->db->insert_id;
    }

    public function meetingOpen(int $meetingId, int $actorId): void
    {
        $this->execute("UPDATE pertemuan_pengajian SET status = 'Dibuka', opened_by = ?, opened_at = NOW(), updated_at = NOW() WHERE id = ? AND status = 'Draf'", [$actorId, $meetingId]);
    }

    /**
     * Membekukan kelas dan peserta pertemuan yang sedang dibuka. Pemanggil
     * sudah mengunci baris jadwal dan pertemuan di transaksi yang sama.
     */
    public function snapshotParticipants(int $meetingId, int $classId, int $yearId, ?int $scheduleId = null): void
    {
        PesertaPertemuan::bekukan($this->db, $meetingId, $scheduleId, $classId, $yearId);
    }

    public function meetingComplete(int $meetingId, int $actorId): void
    {
        $this->execute("UPDATE pertemuan_pengajian SET status = 'Selesai', completed_by = ?, completed_at = NOW(), updated_at = NOW() WHERE id = ? AND status = 'Dibuka'", [$actorId, $meetingId]);
    }

    private function scheduleWhere(array $filters): array
    {
        $parts = [];
        $params = [];
        if (!empty($filters['year_id'])) { $parts[] = 'j.id_tahun = ?'; $params[] = (int) $filters['year_id']; }
        if (!empty($filters['teacher_id'])) { $parts[] = 'j.id_guru = ?'; $params[] = (int) $filters['teacher_id']; }
        if (!empty($filters['class_id'])) { $parts[] = KelasJadwal::jadwalMencakup($this->db); $params[] = (int) $filters['class_id']; }
        if (!empty($filters['day'])) { $parts[] = 'j.hari = ?'; $params[] = (string) $filters['day']; }
        $query = trim((string) ($filters['q'] ?? ''));
        if ($query !== '') {
            $parts[] = '(j.fan_ilmu LIKE ? OR j.nama_kitab LIKE ? OR j.tempat LIKE ?)';
            array_push($params, '%' . $query . '%', '%' . $query . '%', '%' . $query . '%');
        }
        $state = (string) ($filters['state'] ?? 'active');
        if ($state === 'active') { $parts[] = 'j.is_active = 1'; $parts[] = 'j.archived_at IS NULL'; }
        elseif ($state === 'inactive') { $parts[] = 'j.is_active = 0'; $parts[] = 'j.archived_at IS NULL'; }
        elseif ($state === 'archived') { $parts[] = 'j.archived_at IS NOT NULL'; }
        // Tampilan mingguan (redesain Tahap 4, J-1): aktif dan nonaktif, tanpa arsip.
        elseif ($state === 'all_unarchived') { $parts[] = 'j.archived_at IS NULL'; }
        return [$parts ? 'WHERE ' . implode(' AND ', $parts) : '', $params];
    }

    private function kelasLabel(): string
    {
        return KelasJadwal::labelJadwal($this->db);
    }

    private function kelasIds(): string
    {
        return KelasJadwal::idsJadwal($this->db);
    }

    private function kelasPertemuanLabel(): string
    {
        return KelasJadwal::labelPertemuan($this->db);
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
            throw new RuntimeException('Query jadwal tidak dapat disiapkan: ' . $this->db->error);
        }
        try {
            if (!$statement->execute($params)) {
                $message = $statement->error;
                $code = $statement->errno;
                $statement->close();
                if ($code === 1062) {
                    throw new ScheduleException('Data duplikat ditolak oleh basis data.');
                }
                throw new RuntimeException('Query jadwal gagal: ' . $message);
            }
        } catch (\mysqli_sql_exception $exception) {
            $statement->close();
            if ((int) $exception->getCode() === 1062) {
                throw new ScheduleException('Data duplikat ditolak oleh basis data.');
            }
            throw $exception;
        }
        return $statement;
    }
}
