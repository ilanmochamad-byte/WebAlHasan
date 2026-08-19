<?php

declare(strict_types=1);

namespace App\Api;

use App\Audit\AuditLogger;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class TeacherService
{
    private const DAYS = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];
    private const ATTENDANCE_STATUSES = ['Hadir', 'Terlambat', 'Izin', 'Sakit', 'Alpa'];

    public function __construct(
        private TeacherRepository $repository,
        private AuditLogger $audit,
        private string $timezone
    ) {
    }

    public function today(array $user): array
    {
        $today = new DateTimeImmutable('today', new DateTimeZone($this->timezone));
        $all = $this->occurrences($user, $today, $today->add(new DateInterval('P7D')));
        $todayItems = array_values(array_filter($all, static fn (array $item): bool => $item['occurrence_date'] === $today->format('Y-m-d')));
        $future = array_values(array_filter($all, static fn (array $item): bool => $item['occurrence_date'] > $today->format('Y-m-d')));
        return [
            'date' => $today->format('Y-m-d'),
            'schedules' => $todayItems,
            'next_schedule' => $future[0] ?? null,
        ];
    }

    public function schedules(array $user, array $filters): array
    {
        $today = new DateTimeImmutable('today', new DateTimeZone($this->timezone));
        $from = $this->date((string) ($filters['date_from'] ?? $today->format('Y-m-d')), 'date_from');
        $to = $this->date((string) ($filters['date_to'] ?? $today->add(new DateInterval('P30D'))->format('Y-m-d')), 'date_to');
        if ($to < $from) {
            throw new ApiException('VALIDATION_FAILED', 'Tanggal akhir tidak boleh sebelum tanggal awal.', 422, ['date_to' => 'Tanggal akhir tidak valid.']);
        }
        if ($from->diff($to)->days > 92) {
            throw new ApiException('VALIDATION_FAILED', 'Rentang jadwal maksimal 92 hari.', 422, ['date_to' => 'Perkecil rentang tanggal.']);
        }
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 20)));
        $items = $this->occurrences($user, $from, $to);
        $total = count($items);
        return [
            'items' => array_slice($items, ($page - 1) * $perPage, $perPage),
            'pagination' => $this->pagination($page, $perPage, $total),
            'filters' => ['date_from' => $from->format('Y-m-d'), 'date_to' => $to->format('Y-m-d')],
        ];
    }

    public function schedule(int $scheduleId, ?string $date, array $user): array
    {
        $schedule = $this->repository->scheduleFind($scheduleId);
        if ($schedule === null) {
            throw new ApiException('NOT_FOUND', 'Jadwal tidak ditemukan.', 404);
        }
        $this->authorizeOwnership($schedule, $user, 'jadwal');
        $occurrence = $date === null || trim($date) === ''
            ? new DateTimeImmutable('today', new DateTimeZone($this->timezone))
            : $this->date($date, 'date');
        $meeting = $this->repository->meetingByScheduleDate($scheduleId, $occurrence->format('Y-m-d'));
        return $this->schedulePayload($schedule, $occurrence->format('Y-m-d'), $meeting);
    }

    public function meetings(array $user, array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 20)));
        $from = isset($filters['date_from']) && trim((string) $filters['date_from']) !== ''
            ? $this->date((string) $filters['date_from'], 'date_from')->format('Y-m-d') : null;
        $to = isset($filters['date_to']) && trim((string) $filters['date_to']) !== ''
            ? $this->date((string) $filters['date_to'], 'date_to')->format('Y-m-d') : null;
        if ($from !== null && $to !== null && $to < $from) {
            throw new ApiException('VALIDATION_FAILED', 'Tanggal akhir tidak boleh sebelum tanggal awal.', 422);
        }
        $result = $this->repository->meetingPage($user, $from, $to, $page, $perPage);
        return [
            'items' => array_map(fn (array $row): array => $this->meetingSummary($row), $result['rows']),
            'pagination' => $this->pagination($page, $perPage, (int) $result['total']),
        ];
    }

    public function meeting(int $meetingId, array $user): array
    {
        $meeting = $this->repository->meetingFind($meetingId);
        if ($meeting === null) {
            throw new ApiException('NOT_FOUND', 'Pertemuan tidak ditemukan.', 404);
        }
        $this->authorizeOwnership($meeting, $user, 'pertemuan');
        return $this->meetingPayload($meeting);
    }

    public function openMeeting(int $scheduleId, array $input, array $user): array
    {
        $date = $this->date((string) ($input['date'] ?? ''), 'date');
        $notes = $this->optionalText((string) ($input['notes'] ?? ''), 2000, 'notes');
        $key = $this->idempotencyKey((string) ($input['idempotency_key'] ?? ''));
        $operation = 'meeting.open:' . $scheduleId;
        $requestHash = $this->requestHash(['schedule_id' => $scheduleId, 'date' => $date->format('Y-m-d'), 'notes' => $notes]);
        $db = $this->repository->db();
        $db->begin_transaction();
        try {
            $idempotency = $this->beginIdempotency((int) $user['id'], $key, $operation, $requestHash);
            if ($idempotency['replayed']) {
                $db->commit();
                return ['data' => $idempotency['response'], 'replayed' => true];
            }

            $schedule = $this->repository->scheduleFind($scheduleId, true);
            if ($schedule === null) {
                throw new ApiException('NOT_FOUND', 'Jadwal tidak ditemukan.', 404);
            }
            $this->authorizeOwnership($schedule, $user, 'jadwal');
            $this->assertOpenableSchedule($schedule, $date);
            if ($this->repository->meetingByScheduleDate($scheduleId, $date->format('Y-m-d'), true) !== null) {
                throw new ApiException('MEETING_CONFLICT', 'Pertemuan untuk jadwal dan tanggal tersebut sudah ada.', 409);
            }

            $meetingId = $this->repository->createOpenedMeeting($scheduleId, $date->format('Y-m-d'), $notes, (int) $user['id']);
            $this->repository->snapshotParticipants($meetingId, (int) $schedule['id_kelas'], (int) $schedule['id_tahun']);
            $meeting = $this->repository->meetingFind($meetingId);
            if ($meeting === null) {
                throw new ApiException('SERVER_ERROR', 'Pertemuan gagal dibaca setelah dibuat.', 500);
            }
            $response = $this->meetingPayload($meeting);
            $this->repository->idempotencyComplete((int) $idempotency['id'], 201, $response, 'pertemuan_pengajian', $meetingId);
            $this->audit->log('api.meeting.open', 'pertemuan_pengajian', $meetingId, null, [
                'jadwal_id' => $scheduleId,
                'tanggal_pertemuan' => $date->format('Y-m-d'),
                'participant_count' => count($response['students']),
            ], (int) $user['id']);
            $db->commit();
            return ['data' => $response, 'replayed' => false];
        } catch (Throwable $exception) {
            $db->rollback();
            throw $exception;
        }
    }

    public function saveAttendance(int $meetingId, array $input, array $user, ?callable $afterTeacherWrite = null): array
    {
        $key = $this->idempotencyKey((string) ($input['idempotency_key'] ?? ''));
        $teacherInput = is_array($input['teacher'] ?? null) ? $input['teacher'] : [];
        $teacher = [
            'status' => $this->attendanceStatus((string) ($teacherInput['status'] ?? ''), 'teacher.status'),
            'notes' => $this->optionalText((string) ($teacherInput['notes'] ?? ''), 1000, 'teacher.notes'),
        ];
        if (!is_array($input['students'] ?? null)) {
            throw new ApiException('VALIDATION_FAILED', 'Daftar absensi santri wajib dikirim.', 422, ['students' => 'Daftar santri wajib berupa array.']);
        }
        $students = $this->studentAttendanceInput($input['students']);
        $reason = $this->optionalText((string) ($input['correction_reason'] ?? ''), 500, 'correction_reason');
        $operation = 'attendance.save:' . $meetingId;
        $requestHash = $this->requestHash([
            'meeting_id' => $meetingId,
            'teacher' => $teacher,
            'students' => $students,
            'correction_reason' => $reason,
        ]);

        $db = $this->repository->db();
        $db->begin_transaction();
        try {
            $idempotency = $this->beginIdempotency((int) $user['id'], $key, $operation, $requestHash);
            if ($idempotency['replayed']) {
                $db->commit();
                return ['data' => $idempotency['response'], 'replayed' => true];
            }

            $meeting = $this->repository->meetingFind($meetingId, true);
            if ($meeting === null) {
                throw new ApiException('NOT_FOUND', 'Pertemuan tidak ditemukan.', 404);
            }
            $this->authorizeOwnership($meeting, $user, 'pertemuan');
            if (!in_array($meeting['status'], ['Dibuka', 'Selesai'], true)) {
                throw new ApiException('MEETING_NOT_OPEN', 'Pertemuan harus dibuka sebelum absensi disimpan.', 409);
            }
            $isCorrection = $meeting['status'] === 'Selesai';
            if ($isCorrection && $reason === null) {
                throw new ApiException('CORRECTION_REASON_REQUIRED', 'Alasan koreksi wajib diisi untuk pertemuan yang sudah selesai.', 422, [
                    'correction_reason' => 'Alasan koreksi wajib diisi.',
                ]);
            }

            $snapshot = $this->repository->participantsWithAttendance($meetingId);
            $snapshotIds = array_map(static fn (array $row): int => (int) $row['santri_id'], $snapshot);
            $submittedIds = array_map(static fn (array $row): int => $row['student_id'], $students);
            sort($snapshotIds);
            sort($submittedIds);
            if ($snapshotIds !== $submittedIds) {
                throw new ApiException('VALIDATION_FAILED', 'Daftar santri harus sama dengan snapshot pertemuan.', 422, [
                    'students' => 'Pastikan seluruh santri snapshot dikirim tepat satu kali.',
                ]);
            }

            $before = [
                'teacher' => $this->repository->teacherAttendance($meetingId),
                'students' => $snapshot,
                'meeting_status' => $meeting['status'],
            ];
            $this->repository->upsertTeacherAttendance(
                $meetingId,
                (int) $meeting['id_guru'],
                $teacher['status'],
                $teacher['notes'],
                (int) $user['id']
            );
            if ($afterTeacherWrite !== null) {
                $afterTeacherWrite();
            }
            foreach ($students as $student) {
                $this->repository->upsertStudentAttendance(
                    $meetingId,
                    $student['student_id'],
                    $student['status'],
                    $student['notes'],
                    (int) $user['id']
                );
            }
            $this->repository->completeMeeting($meetingId, (int) $user['id']);
            $updated = $this->repository->meetingFind($meetingId);
            if ($updated === null) {
                throw new ApiException('SERVER_ERROR', 'Absensi tersimpan tetapi pertemuan tidak dapat dibaca.', 500);
            }
            $response = $this->meetingPayload($updated);
            $this->repository->idempotencyComplete((int) $idempotency['id'], 200, $response, 'pertemuan_pengajian', $meetingId);
            $this->audit->log(
                $isCorrection ? 'api.attendance.correct' : 'api.attendance.save',
                'pertemuan_pengajian',
                $meetingId,
                $before,
                [
                    'teacher' => $response['teacher_attendance'],
                    'students' => $response['students'],
                    'meeting_status' => $response['status'],
                    'correction_reason' => $reason,
                ],
                (int) $user['id']
            );
            $db->commit();
            return ['data' => $response, 'replayed' => false];
        } catch (Throwable $exception) {
            $db->rollback();
            throw $exception;
        }
    }

    private function occurrences(array $user, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $patterns = $this->repository->activeSchedulePatterns($user);
        $meetings = $this->repository->meetingsInRange($user, $from->format('Y-m-d'), $to->format('Y-m-d'));
        $meetingMap = [];
        foreach ($meetings as $meeting) {
            $meetingMap[$meeting['jadwal_id'] . ':' . $meeting['tanggal_pertemuan']] = $meeting;
        }
        $items = [];
        for ($date = $from; $date <= $to; $date = $date->add(new DateInterval('P1D'))) {
            $day = self::DAYS[(int) $date->format('N') - 1];
            foreach ($patterns as $pattern) {
                if ($pattern['hari'] !== $day) {
                    continue;
                }
                $key = $pattern['id'] . ':' . $date->format('Y-m-d');
                $items[] = $this->schedulePayload($pattern, $date->format('Y-m-d'), $meetingMap[$key] ?? null);
            }
        }
        usort($items, static fn (array $a, array $b): int => [$a['occurrence_date'], $a['start_time'], $a['id']] <=> [$b['occurrence_date'], $b['start_time'], $b['id']]);
        return $items;
    }

    private function schedulePayload(array $row, string $date, ?array $meeting): array
    {
        return [
            'id' => (int) $row['id'],
            'occurrence_date' => $date,
            'day' => (string) $row['hari'],
            'start_time' => substr((string) $row['waktu_mulai'], 0, 5),
            'end_time' => substr((string) $row['waktu_selesai'], 0, 5),
            'subject' => (string) $row['fan_ilmu'],
            'book' => (string) $row['nama_kitab'],
            'place' => (string) $row['tempat'],
            'class' => ['id' => (int) $row['id_kelas'], 'name' => (string) $row['nama_kelas'], 'level' => (string) $row['jenjang']],
            'teacher' => ['id' => (int) $row['id_guru'], 'nip' => $row['nip'] === null ? null : (string) $row['nip'], 'name' => (string) $row['nama_guru']],
            'academic_year' => ['id' => (int) $row['id_tahun'], 'year' => (string) $row['tahun'], 'semester' => (string) $row['semester']],
            'meeting' => $meeting === null ? null : [
                'id' => (int) $meeting['id'],
                'status' => (string) $meeting['status'],
                'opened_at' => $meeting['opened_at'],
                'completed_at' => $meeting['completed_at'],
            ],
        ];
    }

    private function meetingPayload(array $meeting): array
    {
        $students = $this->repository->participantsWithAttendance((int) $meeting['id']);
        return [
            'id' => (int) $meeting['id'],
            'schedule_id' => (int) $meeting['jadwal_id'],
            'date' => (string) $meeting['tanggal_pertemuan'],
            'status' => (string) $meeting['status'],
            'notes' => $meeting['catatan'],
            'opened_at' => $meeting['opened_at'],
            'completed_at' => $meeting['completed_at'],
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
            'teacher_attendance' => $this->normalizeTeacherAttendance($this->repository->teacherAttendance((int) $meeting['id'])),
            'students' => array_map(static fn (array $row): array => [
                'student_id' => (int) $row['santri_id'],
                'nis' => (string) $row['nis_snapshot'],
                'name' => (string) $row['nama_santri_snapshot'],
                'attendance' => $row['attendance_id'] === null ? null : [
                    'id' => (int) $row['attendance_id'],
                    'status' => (string) $row['status'],
                    'notes' => $row['notes'],
                    'recorded_at' => $row['recorded_at'],
                    'updated_at' => $row['updated_at'],
                ],
            ], $students),
        ];
    }

    private function meetingSummary(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'schedule_id' => (int) $row['jadwal_id'],
            'date' => (string) $row['tanggal_pertemuan'],
            'status' => (string) $row['status'],
            'opened_at' => $row['opened_at'],
            'completed_at' => $row['completed_at'],
            'participant_count' => (int) $row['participant_count'],
            'subject' => (string) $row['fan_ilmu'],
            'book' => (string) $row['nama_kitab'],
            'place' => (string) $row['tempat'],
            'class_name' => (string) $row['nama_kelas'],
            'teacher_name' => (string) $row['nama_guru'],
            'start_time' => substr((string) $row['waktu_mulai'], 0, 5),
            'end_time' => substr((string) $row['waktu_selesai'], 0, 5),
        ];
    }

    private function normalizeTeacherAttendance(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'teacher_id' => (int) $row['guru_id'],
            'status' => (string) $row['status'],
            'notes' => $row['notes'],
            'recorded_at' => $row['recorded_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    private function beginIdempotency(int $userId, string $key, string $operation, string $requestHash): array
    {
        $existing = $this->repository->idempotencyFind($userId, $key, true);
        if ($existing !== null) {
            if (!hash_equals((string) $existing['operation'], $operation) || !hash_equals((string) $existing['request_hash'], $requestHash)) {
                throw new ApiException('IDEMPOTENCY_CONFLICT', 'Idempotency key sudah dipakai untuk request yang berbeda.', 409);
            }
            if ($existing['completed_at'] === null || $existing['response_json'] === null) {
                throw new ApiException('REQUEST_IN_PROGRESS', 'Request dengan idempotency key ini sedang diproses.', 409);
            }
            $response = json_decode((string) $existing['response_json'], true);
            if (!is_array($response)) {
                throw new ApiException('SERVER_ERROR', 'Response idempotensi tersimpan tidak valid.', 500);
            }
            return ['id' => (int) $existing['id'], 'replayed' => true, 'response' => $response];
        }
        $id = $this->repository->idempotencyCreate($userId, $key, $operation, $requestHash);
        return ['id' => $id, 'replayed' => false, 'response' => null];
    }

    private function assertOpenableSchedule(array $schedule, DateTimeImmutable $date): void
    {
        if ((int) $schedule['is_active'] !== 1 || $schedule['archived_at'] !== null || $schedule['tahun_status'] !== 'Aktif' || $schedule['tahun_archived_at'] !== null) {
            throw new ApiException('SCHEDULE_INACTIVE', 'Pertemuan hanya dapat dibuka dari jadwal aktif pada semester aktif.', 409);
        }
        if ($schedule['hari'] === null || $schedule['waktu_mulai'] === null || $schedule['waktu_selesai'] === null) {
            throw new ApiException('SCHEDULE_UNSTRUCTURED', 'Hari dan waktu jadwal belum terstruktur.', 409);
        }
        $actualDay = self::DAYS[(int) $date->format('N') - 1];
        if ($actualDay !== $schedule['hari']) {
            throw new ApiException('SCHEDULE_DATE_MISMATCH', 'Tanggal pertemuan tidak sesuai hari pola jadwal.', 422, [
                'date' => 'Tanggal jatuh pada hari ' . $actualDay . ', sedangkan jadwal adalah ' . $schedule['hari'] . '.',
            ]);
        }
    }

    private function authorizeOwnership(array $record, array $user, string $resource): void
    {
        if (in_array('admin', $user['roles'], true)) {
            return;
        }
        if (!in_array('guru', $user['roles'], true) || $user['guru_id'] === null || (int) $record['id_guru'] !== (int) $user['guru_id']) {
            throw new ApiException('FORBIDDEN', 'Anda tidak berhak mengakses ' . $resource . ' ini.', 403);
        }
    }

    private function studentAttendanceInput(array $rows): array
    {
        $result = [];
        $seen = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                throw new ApiException('VALIDATION_FAILED', 'Format absensi santri tidak valid.', 422, ['students.' . $index => 'Item harus berupa objek.']);
            }
            $studentId = (int) ($row['student_id'] ?? 0);
            if ($studentId < 1 || isset($seen[$studentId])) {
                throw new ApiException('VALIDATION_FAILED', 'ID santri tidak valid atau dikirim lebih dari satu kali.', 422, ['students.' . $index . '.student_id' => 'ID wajib unik.']);
            }
            $seen[$studentId] = true;
            $result[] = [
                'student_id' => $studentId,
                'status' => $this->attendanceStatus((string) ($row['status'] ?? ''), 'students.' . $index . '.status'),
                'notes' => $this->optionalText((string) ($row['notes'] ?? ''), 1000, 'students.' . $index . '.notes'),
            ];
        }
        usort($result, static fn (array $a, array $b): int => $a['student_id'] <=> $b['student_id']);
        return $result;
    }

    private function attendanceStatus(string $value, string $field): string
    {
        if (!in_array($value, self::ATTENDANCE_STATUSES, true)) {
            throw new ApiException('VALIDATION_FAILED', 'Status absensi tidak valid.', 422, [$field => 'Pilih Hadir, Terlambat, Izin, Sakit, atau Alpa.']);
        }
        return $value;
    }

    private function date(string $value, string $field): DateTimeImmutable
    {
        $value = trim($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone($this->timezone));
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new ApiException('VALIDATION_FAILED', 'Tanggal harus berformat YYYY-MM-DD.', 422, [$field => 'Tanggal tidak valid.']);
        }
        return $date;
    }

    private function optionalText(string $value, int $maximum, string $field): ?string
    {
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';
        if (mb_strlen($value) > $maximum) {
            throw new ApiException('VALIDATION_FAILED', 'Teks melebihi batas karakter.', 422, [$field => 'Maksimal ' . $maximum . ' karakter.']);
        }
        return $value === '' ? null : $value;
    }

    private function idempotencyKey(string $value): string
    {
        $value = trim($value);
        if (!preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $value)) {
            throw new ApiException('VALIDATION_FAILED', 'Idempotency key wajib 8–100 karakter dan hanya memakai huruf, angka, titik, garis, titik dua, atau underscore.', 422, [
                'idempotency_key' => 'Gunakan UUID atau kunci acak yang stabil selama retry.',
            ]);
        }
        return $value;
    }

    private function requestHash(array $payload): string
    {
        return hash('sha256', json_encode($this->canonicalize($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
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
