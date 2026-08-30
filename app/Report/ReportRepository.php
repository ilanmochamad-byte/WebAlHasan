<?php

declare(strict_types=1);

namespace App\Report;

use mysqli;
use mysqli_stmt;
use RuntimeException;

final class ReportRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function summary(ReportFilter $filter): array
    {
        [$sql, $params] = $this->attendanceRowsSql($filter);
        return $this->one(
            "SELECT COUNT(DISTINCT report.meeting_id) AS meeting_count,
                    COUNT(*) AS detail_count,
                    SUM(report.subject_type = 'Guru') AS teacher_attendance_count,
                    SUM(report.subject_type = 'Santri') AS student_attendance_count,
                    SUM(report.attendance_status = 'Hadir') AS hadir,
                    SUM(report.attendance_status = 'Terlambat') AS terlambat,
                    SUM(report.attendance_status = 'Izin') AS izin,
                    SUM(report.attendance_status = 'Sakit') AS sakit,
                    SUM(report.attendance_status = 'Alpa') AS alpa
             FROM ({$sql}) report",
            $params
        ) ?? [];
    }

    public function scheduleSummary(ReportFilter $filter): array
    {
        [$sql, $params] = $this->attendanceRowsSql($filter);
        return $this->all(
            "SELECT report.schedule_id, report.teacher_id, report.teacher_name, report.class_id, report.class_name,
                    report.subject, report.book, COUNT(DISTINCT report.meeting_id) AS meeting_count,
                    COUNT(*) AS detail_count,
                    SUM(report.attendance_status = 'Hadir') AS hadir,
                    SUM(report.attendance_status = 'Terlambat') AS terlambat,
                    SUM(report.attendance_status = 'Izin') AS izin,
                    SUM(report.attendance_status = 'Sakit') AS sakit,
                    SUM(report.attendance_status = 'Alpa') AS alpa
             FROM ({$sql}) report
             GROUP BY report.schedule_id, report.teacher_id, report.teacher_name, report.class_id,
                      report.class_name, report.subject, report.book
             ORDER BY report.teacher_name, report.class_name, report.subject, report.schedule_id",
            $params
        );
    }

    public function page(ReportFilter $filter): array
    {
        [$sql, $params] = $this->attendanceRowsSql($filter);
        $offset = ($filter->page - 1) * $filter->perPage;
        return $this->all(
            "SELECT * FROM ({$sql}) report
             ORDER BY report.meeting_date DESC, report.meeting_id DESC,
                      FIELD(report.subject_type, 'Guru', 'Santri'), report.subject_name
             LIMIT ? OFFSET ?",
            [...$params, $filter->perPage, $offset]
        );
    }

    public function allRows(ReportFilter $filter): array
    {
        [$sql, $params] = $this->attendanceRowsSql($filter);
        return $this->all(
            "SELECT * FROM ({$sql}) report
             ORDER BY report.meeting_date, report.meeting_id,
                      FIELD(report.subject_type, 'Guru', 'Santri'), report.subject_name",
            $params
        );
    }

    public function filterOptions(?int $teacherId): array
    {
        $where = $teacherId === null ? '' : ' WHERE j.id_guru = ?';
        $params = $teacherId === null ? [] : [$teacherId];
        $base = " FROM pertemuan_pengajian p
                  JOIN jadwal_ngaji j ON j.id = p.jadwal_id
                  JOIN tahun_ajaran ta ON ta.id = j.id_tahun
                  JOIN guru g ON g.id = j.id_guru
                  JOIN kelas k ON k.id = j.id_kelas" . $where;
        return [
            'academic_years' => $this->all(
                'SELECT DISTINCT ta.id, ta.tahun, ta.semester' . $base . ' ORDER BY ta.tahun DESC, ta.semester DESC',
                $params
            ),
            'teachers' => $this->all(
                'SELECT DISTINCT g.id, g.nip, g.nama_guru' . $base . ' ORDER BY g.nama_guru',
                $params
            ),
            'classes' => $this->all(
                'SELECT DISTINCT k.id, k.nama_kelas, k.jenjang' . $base . ' ORDER BY k.nama_kelas',
                $params
            ),
            'schedules' => $this->all(
                'SELECT DISTINCT j.id, j.id_guru, j.id_kelas, j.id_tahun, j.hari, j.waktu_mulai,
                        j.fan_ilmu, j.nama_kitab, g.nama_guru, k.nama_kelas' . $base .
                ' ORDER BY g.nama_guru, k.nama_kelas, j.fan_ilmu, j.id',
                $params
            ),
        ];
    }

    public function meeting(int $meetingId, ?int $teacherId): ?array
    {
        $sql = "SELECT p.id, p.jadwal_id, p.tanggal_pertemuan, p.status, p.catatan,
                       p.opened_at, p.completed_at, p.updated_at,
                       j.id_guru, j.id_kelas, j.id_tahun, j.hari, j.waktu_mulai, j.waktu_selesai,
                       j.fan_ilmu, j.nama_kitab, j.tempat,
                       g.nip, g.nama_guru, k.nama_kelas, k.jenjang, ta.tahun, ta.semester,
                       creator.name AS created_by_name
                FROM pertemuan_pengajian p
                JOIN jadwal_ngaji j ON j.id = p.jadwal_id
                JOIN guru g ON g.id = j.id_guru
                JOIN kelas k ON k.id = j.id_kelas
                JOIN tahun_ajaran ta ON ta.id = j.id_tahun
                LEFT JOIN users creator ON creator.id = p.created_by
                WHERE p.id = ?";
        $params = [$meetingId];
        if ($teacherId !== null) {
            $sql .= ' AND j.id_guru = ?';
            $params[] = $teacherId;
        }
        return $this->one($sql, $params);
    }

    public function meetingTeacherAttendance(int $meetingId): ?array
    {
        return $this->one(
            "SELECT ag.id, ag.guru_id, g.nip, g.nama_guru, ag.status, ag.catatan,
                    ag.dicatat_pada, ag.updated_at, actor.name AS dicatat_oleh_nama
             FROM absensi_guru ag
             JOIN guru g ON g.id = ag.guru_id
             LEFT JOIN users actor ON actor.id = ag.dicatat_oleh
             WHERE ag.pertemuan_id = ? LIMIT 1",
            [$meetingId]
        );
    }

    public function meetingStudents(int $meetingId): array
    {
        return $this->all(
            "SELECT pp.santri_id, pp.nis_snapshot, pp.nama_santri_snapshot,
                    attendance.id AS attendance_id, attendance.status, attendance.catatan,
                    attendance.dicatat_pada, attendance.updated_at, actor.name AS dicatat_oleh_nama
             FROM pertemuan_peserta pp
             LEFT JOIN absensi_santri attendance
               ON attendance.pertemuan_id = pp.pertemuan_id AND attendance.santri_id = pp.santri_id
             LEFT JOIN users actor ON actor.id = attendance.dicatat_oleh
             WHERE pp.pertemuan_id = ?
             ORDER BY pp.nama_santri_snapshot, pp.santri_id",
            [$meetingId]
        );
    }

    public function explainPage(ReportFilter $filter): array
    {
        [$sql, $params] = $this->attendanceRowsSql($filter);
        return $this->all(
            "EXPLAIN SELECT * FROM ({$sql}) report
             ORDER BY report.meeting_date DESC, report.meeting_id DESC,
                      FIELD(report.subject_type, 'Guru', 'Santri'), report.subject_name
             LIMIT ? OFFSET ?",
            [...$params, $filter->perPage, ($filter->page - 1) * $filter->perPage]
        );
    }

    private function attendanceRowsSql(ReportFilter $filter): array
    {
        [$where, $params] = $this->where($filter, 'attendance.status');
        $teacher = "SELECT 'Guru' AS subject_type, attendance.id AS attendance_id,
                           p.id AS meeting_id, p.jadwal_id AS schedule_id, p.tanggal_pertemuan AS meeting_date,
                           p.status AS meeting_status, j.id_tahun AS academic_year_id,
                           CONCAT(ta.tahun, ' - ', ta.semester) AS academic_year,
                           j.id_guru AS teacher_id, g.nama_guru AS teacher_name,
                           j.id_kelas AS class_id, k.nama_kelas AS class_name,
                           j.fan_ilmu AS subject, j.nama_kitab AS book, j.tempat AS place,
                           attendance.guru_id AS subject_id, COALESCE(g.nip, '') AS identity_number,
                           g.nama_guru AS subject_name, attendance.status AS attendance_status,
                           attendance.catatan AS notes, attendance.dicatat_pada AS recorded_at,
                           attendance.updated_at, actor.name AS recorder_name
                    FROM absensi_guru attendance
                    JOIN pertemuan_pengajian p ON p.id = attendance.pertemuan_id
                    JOIN jadwal_ngaji j ON j.id = p.jadwal_id
                    JOIN tahun_ajaran ta ON ta.id = j.id_tahun
                    JOIN guru g ON g.id = j.id_guru
                    JOIN kelas k ON k.id = j.id_kelas
                    LEFT JOIN users actor ON actor.id = attendance.dicatat_oleh
                    {$where}";

        [$studentWhere, $studentParams] = $this->where($filter, 'attendance.status');
        $student = "SELECT 'Santri' AS subject_type, attendance.id AS attendance_id,
                           p.id AS meeting_id, p.jadwal_id AS schedule_id, p.tanggal_pertemuan AS meeting_date,
                           p.status AS meeting_status, j.id_tahun AS academic_year_id,
                           CONCAT(ta.tahun, ' - ', ta.semester) AS academic_year,
                           j.id_guru AS teacher_id, g.nama_guru AS teacher_name,
                           j.id_kelas AS class_id, k.nama_kelas AS class_name,
                           j.fan_ilmu AS subject, j.nama_kitab AS book, j.tempat AS place,
                           attendance.santri_id AS subject_id, pp.nis_snapshot AS identity_number,
                           pp.nama_santri_snapshot AS subject_name, attendance.status AS attendance_status,
                           attendance.catatan AS notes, attendance.dicatat_pada AS recorded_at,
                           attendance.updated_at, actor.name AS recorder_name
                    FROM absensi_santri attendance
                    JOIN pertemuan_pengajian p ON p.id = attendance.pertemuan_id
                    JOIN jadwal_ngaji j ON j.id = p.jadwal_id
                    JOIN tahun_ajaran ta ON ta.id = j.id_tahun
                    JOIN guru g ON g.id = j.id_guru
                    JOIN kelas k ON k.id = j.id_kelas
                    JOIN pertemuan_peserta pp
                      ON pp.pertemuan_id = attendance.pertemuan_id AND pp.santri_id = attendance.santri_id
                    LEFT JOIN users actor ON actor.id = attendance.dicatat_oleh
                    {$studentWhere}";

        // Koreksi ke-5: penyajian dipisahkan di sini, satu tempat, sehingga
        // ringkasan, detail, CSV, dan cetak SELALU memakai definisi yang sama.
        // Absensi guru tidak pernah dihapus; mode Santri hanya tidak
        // menyertakan cabangnya pada UNION.
        if (!$filter->includesGuru()) {
            return [$student, $studentParams];
        }
        if (!$filter->includesSantri()) {
            return [$teacher, $params];
        }

        return [$teacher . ' UNION ALL ' . $student, [...$params, ...$studentParams]];
    }

    private function where(ReportFilter $filter, string $statusColumn): array
    {
        $clauses = ['p.tanggal_pertemuan BETWEEN ? AND ?'];
        $params = [$filter->dateFrom, $filter->dateTo];
        foreach ([
            'j.id_tahun' => $filter->academicYearId,
            'j.id_guru' => $filter->teacherId,
            'j.id_kelas' => $filter->classId,
            'j.id' => $filter->scheduleId,
        ] as $column => $value) {
            if ($value !== null) {
                $clauses[] = $column . ' = ?';
                $params[] = $value;
            }
        }
        if ($filter->status !== null) {
            $clauses[] = $statusColumn . ' = ?';
            $params[] = $filter->status;
        }
        return ['WHERE ' . implode(' AND ', $clauses), $params];
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

    private function statement(string $sql, array $params): mysqli_stmt
    {
        $statement = $this->db->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Query laporan tidak dapat disiapkan: ' . $this->db->error);
        }
        if (!$statement->execute($params)) {
            $message = $statement->error;
            $statement->close();
            throw new RuntimeException('Query laporan gagal dijalankan: ' . $message);
        }
        return $statement;
    }
}
