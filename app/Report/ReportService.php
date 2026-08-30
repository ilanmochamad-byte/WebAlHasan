<?php

declare(strict_types=1);

namespace App\Report;

use App\Api\ApiException;
use DateTimeImmutable;
use DateTimeZone;

final class ReportService
{
    public const CSV_MAX_ROWS = 20000;

    public function __construct(private ReportRepository $repository, private string $timezone)
    {
    }

    /** A-09: fitur penyajian web tidak mengubah kontrak API aplikasi V1. */
    public function apiReport(array $input, array $user): array
    {
        unset($input['subject_scope']);
        return $this->legacyApiShape($this->report($input, $user, ReportFilter::SCOPE_GABUNGAN));
    }

    public function apiPrintRows(array $input, array $user): array
    {
        unset($input['subject_scope']);
        return $this->legacyApiShape($this->exportRows($input, $user, ReportFilter::SCOPE_GABUNGAN));
    }

    public function apiOptions(array $user): array
    {
        $options = $this->options($user);
        unset($options['subject_scopes']);
        return $options;
    }

    private function legacyApiShape(array $report): array
    {
        unset($report['filters']['subject_scope'], $report['active_filters']['Penyajian']);
        return $report;
    }

    /**
     * @param string $defaultScope Penyajian awal bila pemanggil tidak mengirim
     *        `subject_scope`. Halaman web mengirim `santri`; REST API memakai
     *        default lama `gabungan` agar kontraknya tidak berubah.
     */
    public function report(array $input, array $user, string $defaultScope = ReportFilter::DEFAULT_SCOPE_API): array
    {
        $filter = ReportFilter::fromInput($input, $this->timezone, $defaultScope)->forUser($user);
        $summary = $this->normalizeSummary($this->repository->summary($filter));
        $rows = array_map([$this, 'normalizeRow'], $this->repository->page($filter));
        return [
            'summary' => $summary,
            'schedules' => array_map([$this, 'normalizeScheduleSummary'], $this->repository->scheduleSummary($filter)),
            'items' => $rows,
            'pagination' => $this->pagination($filter->page, $filter->perPage, $summary['detail_count']),
            'filters' => $filter->toArray(),
            'active_filters' => $this->describeFilters($filter, $user),
        ];
    }

    public function dashboardSummary(array $input, array $user, string $defaultScope = ReportFilter::DEFAULT_SCOPE_API): array
    {
        $filter = ReportFilter::fromInput($input, $this->timezone, $defaultScope)->forUser($user);
        return [
            'summary' => $this->normalizeSummary($this->repository->summary($filter)),
            'filters' => $filter->toArray(),
        ];
    }

    public function exportCsvRows(array $input, array $user, string $defaultScope = ReportFilter::SCOPE_SANTRI): array
    {
        return $this->exportRows($input, $user, $defaultScope, self::CSV_MAX_ROWS);
    }

    public function exportRows(array $input, array $user, string $defaultScope = ReportFilter::DEFAULT_SCOPE_API, ?int $maxRows = null): array
    {
        $filter = ReportFilter::fromInput($input, $this->timezone, $defaultScope)->forUser($user);
        $summary = $this->normalizeSummary($this->repository->summary($filter));
        if ($maxRows !== null && $summary['detail_count'] > $maxRows) {
            throw new ApiException('EXPORT_TOO_LARGE', 'Ekspor CSV maksimal 20.000 baris. Persempit filter laporan.', 422);
        }
        // Baca paling banyak batas+1 untuk CSV. Pemeriksaan kedua menjaga
        // batas bila absensi baru masuk sesudah query ringkasan.
        $rows = $this->repository->allRows($filter, $maxRows === null ? null : $maxRows + 1);
        if ($maxRows !== null && count($rows) > $maxRows) {
            throw new ApiException('EXPORT_TOO_LARGE', 'Ekspor CSV maksimal 20.000 baris. Persempit filter laporan.', 422);
        }
        return [
            'summary' => $summary,
            'items' => array_map([$this, 'normalizeRow'], $rows),
            'filters' => $filter->toArray(),
            'active_filters' => $this->describeFilters($filter, $user),
            'generated_at' => (new DateTimeImmutable('now', new DateTimeZone($this->timezone)))->format('Y-m-d H:i:s T'),
            'created_by' => (string) ($user['name'] ?? $user['username'] ?? 'Pengguna'),
        ];
    }

    public function options(array $user): array
    {
        $teacherId = in_array('admin', $user['roles'] ?? [], true) ? null : (int) ($user['guru_id'] ?? 0);
        if ($teacherId !== null && $teacherId < 1) {
            throw new ApiException('FORBIDDEN', 'Akun tidak memiliki akses laporan.', 403);
        }
        $options = $this->repository->filterOptions($teacherId);
        return [
            'academic_years' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'], 'year' => (string) $row['tahun'], 'semester' => (string) $row['semester'],
            ], $options['academic_years']),
            'teachers' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'], 'nip' => $row['nip'] === null ? null : (string) $row['nip'], 'name' => (string) $row['nama_guru'],
            ], $options['teachers']),
            'classes' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'], 'name' => (string) $row['nama_kelas'], 'level' => (string) $row['jenjang'],
            ], $options['classes']),
            'schedules' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'teacher_id' => (int) $row['id_guru'],
                'class_id' => (int) $row['id_kelas'],
                'academic_year_id' => (int) $row['id_tahun'],
                'label' => sprintf(
                    '#%d · %s · %s · %s · %s %s',
                    (int) $row['id'],
                    (string) $row['nama_guru'],
                    (string) $row['nama_kelas'],
                    (string) $row['fan_ilmu'],
                    (string) ($row['hari'] ?? '-'),
                    $row['waktu_mulai'] === null ? '-' : substr((string) $row['waktu_mulai'], 0, 5)
                ),
            ], $options['schedules']),
            'statuses' => ReportFilter::STATUSES,
            // Aditif: pilihan penyajian laporan (koreksi ke-5).
            'subject_scopes' => [
                ['value' => ReportFilter::SCOPE_SANTRI, 'label' => 'Santri'],
                ['value' => ReportFilter::SCOPE_GURU, 'label' => 'Guru'],
                ['value' => ReportFilter::SCOPE_GABUNGAN, 'label' => 'Gabungan (santri dan guru)'],
            ],
        ];
    }

    public function meeting(int $meetingId, array $user): array
    {
        $meeting = $this->repository->meeting($meetingId, null);
        if ($meeting === null) {
            throw new ApiException('NOT_FOUND', 'Pertemuan tidak ditemukan.', 404);
        }
        if (!in_array('admin', $user['roles'] ?? [], true)) {
            $guruId = (int) ($user['guru_id'] ?? 0);
            if (!in_array('guru', $user['roles'] ?? [], true) || $guruId < 1 || (int) $meeting['id_guru'] !== $guruId) {
                throw new ApiException('FORBIDDEN', 'Anda tidak berhak membuka laporan pertemuan ini.', 403);
            }
        }
        $teacher = $this->repository->meetingTeacherAttendance($meetingId);
        $students = $this->repository->meetingStudents($meetingId);
        $statusCounts = array_fill_keys(ReportFilter::STATUSES, 0);
        foreach ($students as $student) {
            if ($student['status'] !== null && isset($statusCounts[$student['status']])) {
                $statusCounts[$student['status']]++;
            }
        }
        return [
            'id' => (int) $meeting['id'],
            'schedule_id' => (int) $meeting['jadwal_id'],
            'date' => (string) $meeting['tanggal_pertemuan'],
            'status' => (string) $meeting['status'],
            'notes' => $meeting['catatan'],
            'opened_at' => $meeting['opened_at'],
            'completed_at' => $meeting['completed_at'],
            'updated_at' => $meeting['updated_at'],
            'created_by' => $meeting['created_by_name'],
            'task' => [
                'day' => (string) $meeting['hari'],
                'start_time' => substr((string) $meeting['waktu_mulai'], 0, 5),
                'end_time' => substr((string) $meeting['waktu_selesai'], 0, 5),
                'subject' => (string) $meeting['fan_ilmu'],
                'book' => (string) $meeting['nama_kitab'],
                'place' => (string) $meeting['tempat'],
                'class' => ['id' => (int) $meeting['id_kelas'], 'name' => (string) $meeting['nama_kelas'], 'level' => (string) $meeting['jenjang']],
                'teacher' => ['id' => (int) $meeting['id_guru'], 'nip' => $meeting['nip'] === null ? null : (string) $meeting['nip'], 'name' => (string) $meeting['nama_guru']],
                'academic_year' => ['id' => (int) $meeting['id_tahun'], 'year' => (string) $meeting['tahun'], 'semester' => (string) $meeting['semester']],
            ],
            'teacher_attendance' => $teacher === null ? null : [
                'teacher_id' => (int) $teacher['guru_id'],
                'nip' => $teacher['nip'] === null ? null : (string) $teacher['nip'],
                'name' => (string) $teacher['nama_guru'],
                'status' => (string) $teacher['status'],
                'notes' => $teacher['catatan'],
                'recorded_by' => $teacher['dicatat_oleh_nama'],
                'recorded_at' => $teacher['dicatat_pada'],
                'updated_at' => $teacher['updated_at'],
            ],
            'students' => array_map(static fn (array $row): array => [
                'student_id' => (int) $row['santri_id'],
                'nis' => (string) $row['nis_snapshot'],
                'name' => (string) $row['nama_santri_snapshot'],
                'status' => $row['status'] === null ? null : (string) $row['status'],
                'notes' => $row['catatan'],
                'recorded_by' => $row['dicatat_oleh_nama'],
                'recorded_at' => $row['dicatat_pada'],
                'updated_at' => $row['updated_at'],
            ], $students),
            'student_summary' => [
                'participant_count' => count($students),
                'recorded_count' => count(array_filter($students, static fn (array $row): bool => $row['status'] !== null)),
                'statuses' => $statusCounts,
            ],
        ];
    }

    public function explain(array $input, array $user, string $defaultScope = ReportFilter::DEFAULT_SCOPE_API): array
    {
        $filter = ReportFilter::fromInput($input, $this->timezone, $defaultScope)->forUser($user);
        return $this->repository->explainPage($filter);
    }

    private function describeFilters(ReportFilter $filter, array $user): array
    {
        $descriptions = [
            'Rentang tanggal' => $filter->dateFrom . ' s.d. ' . $filter->dateTo,
            'Penyajian' => $filter->scopeLabel(),
            'Status' => $filter->status ?? 'Semua status',
        ];
        $options = $this->options($user);
        $descriptions['Tahun ajaran'] = $this->optionLabel($options['academic_years'], $filter->academicYearId, static fn (array $row): string => $row['year'] . ' - ' . $row['semester']);
        $descriptions['Guru'] = $this->optionLabel($options['teachers'], $filter->teacherId, static fn (array $row): string => $row['name']);
        $descriptions['Kelas'] = $this->optionLabel($options['classes'], $filter->classId, static fn (array $row): string => $row['name']);
        $descriptions['Jadwal'] = $this->optionLabel($options['schedules'], $filter->scheduleId, static fn (array $row): string => $row['label']);
        return $descriptions;
    }

    private function optionLabel(array $rows, ?int $id, callable $label): string
    {
        if ($id === null) {
            return 'Semua';
        }
        foreach ($rows as $row) {
            if ((int) $row['id'] === $id) {
                return $label($row);
            }
        }
        return '#' . $id;
    }

    private function normalizeSummary(array $row): array
    {
        return [
            'meeting_count' => (int) ($row['meeting_count'] ?? 0),
            'detail_count' => (int) ($row['detail_count'] ?? 0),
            'teacher_attendance_count' => (int) ($row['teacher_attendance_count'] ?? 0),
            'student_attendance_count' => (int) ($row['student_attendance_count'] ?? 0),
            'statuses' => [
                'Hadir' => (int) ($row['hadir'] ?? 0),
                'Terlambat' => (int) ($row['terlambat'] ?? 0),
                'Izin' => (int) ($row['izin'] ?? 0),
                'Sakit' => (int) ($row['sakit'] ?? 0),
                'Alpa' => (int) ($row['alpa'] ?? 0),
            ],
        ];
    }

    private function normalizeScheduleSummary(array $row): array
    {
        return [
            'schedule_id' => (int) $row['schedule_id'],
            'teacher' => ['id' => (int) $row['teacher_id'], 'name' => (string) $row['teacher_name']],
            'class' => ['id' => (int) $row['class_id'], 'name' => (string) $row['class_name']],
            'subject' => (string) $row['subject'],
            'book' => (string) $row['book'],
            'meeting_count' => (int) $row['meeting_count'],
            'detail_count' => (int) $row['detail_count'],
            'statuses' => [
                'Hadir' => (int) $row['hadir'], 'Terlambat' => (int) $row['terlambat'],
                'Izin' => (int) $row['izin'], 'Sakit' => (int) $row['sakit'], 'Alpa' => (int) $row['alpa'],
            ],
        ];
    }

    private function normalizeRow(array $row): array
    {
        return [
            'attendance_id' => (int) $row['attendance_id'],
            'meeting_id' => (int) $row['meeting_id'],
            'schedule_id' => (int) $row['schedule_id'],
            'meeting_date' => (string) $row['meeting_date'],
            'meeting_status' => (string) $row['meeting_status'],
            'academic_year_id' => (int) $row['academic_year_id'],
            'academic_year' => (string) $row['academic_year'],
            'teacher_id' => (int) $row['teacher_id'],
            'teacher_name' => (string) $row['teacher_name'],
            'class_id' => (int) $row['class_id'],
            'class_name' => (string) $row['class_name'],
            'subject' => (string) $row['subject'],
            'book' => (string) $row['book'],
            'place' => (string) $row['place'],
            'subject_type' => (string) $row['subject_type'],
            'subject_id' => (int) $row['subject_id'],
            'identity_number' => (string) $row['identity_number'],
            'subject_name' => (string) $row['subject_name'],
            'attendance_status' => (string) $row['attendance_status'],
            'notes' => $row['notes'],
            'recorder_name' => $row['recorder_name'],
            'recorded_at' => $row['recorded_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    private function pagination(int $page, int $perPage, int $total): array
    {
        return [
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $total === 0 ? 0 : (int) ceil($total / $perPage),
        ];
    }
}
