<?php

declare(strict_types=1);

namespace App\Schedule;

use App\Audit\AuditLogger;
use DateTimeImmutable;
use Throwable;

final class ScheduleService
{
    private const DAYS = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];
    private const PRAYER_TIMES = ["Ba'da Shubuh", "Ba'da Ashar", "Ba'da Magrib", "Ba'da Isya"];

    public function __construct(private ScheduleRepository $repository, private AuditLogger $audit)
    {
    }

    public function list(array $filters, int $page, int $perPage = 20): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        return $this->repository->scheduleList($filters, $page, $perPage);
    }

    public function find(int $id): ?array
    {
        return $this->repository->scheduleFind($id);
    }

    public function years(): array
    {
        return $this->repository->yearOptions();
    }

    public function teachers(): array
    {
        return $this->repository->teacherOptions();
    }

    public function classes(): array
    {
        return $this->repository->classOptions();
    }

    public function days(): array
    {
        return self::DAYS;
    }

    public function prayerTimes(): array
    {
        return self::PRAYER_TIMES;
    }

    public function save(array $input, int $actorId, ?int $id = null): array
    {
        $data = $this->validateSchedule($input);
        $before = $id === null ? null : $this->mustSchedule($id);
        $this->ensureReferences($data);
        $willBeActive = $before === null || ((int) $before['is_active'] === 1 && $before['archived_at'] === null);
        if ($willBeActive) {
            $this->rejectTeacherConflict($data, $id);
        }
        $warnings = $willBeActive ? $this->conflictWarnings($this->repository->warningConflicts($data, $id)) : [];

        if ($id === null) {
            $id = $this->repository->scheduleCreate($data, $actorId);
            $this->audit->log('schedule.create', 'jadwal_ngaji', $id, null, $this->repository->scheduleFind($id), $actorId);
        } else {
            $this->repository->scheduleUpdate($id, $data, $actorId);
            $this->audit->log('schedule.update', 'jadwal_ngaji', $id, $before, $this->repository->scheduleFind($id), $actorId);
        }
        return ['id' => $id, 'warnings' => $warnings];
    }

    public function setState(int $id, string $action, int $actorId): array
    {
        $before = $this->mustSchedule($id);
        $active = (int) $before['is_active'] === 1;
        $archive = $before['archived_at'] !== null;
        if ($action === 'activate') {
            if ($archive) { throw new ScheduleException('Pulihkan jadwal dari arsip sebelum mengaktifkannya.'); }
            $data = $this->structuredFromRow($before);
            $this->requireStructured($data, 'Jadwal lama harus dilengkapi hari dan waktu terstruktur sebelum diaktifkan kembali.');
            $this->rejectTeacherConflict($data, $id);
            $active = true;
            $archive = false;
        } elseif ($action === 'deactivate') {
            $active = false;
            $archive = false;
        } elseif ($action === 'archive') {
            $active = false;
            $archive = true;
        } elseif ($action === 'restore') {
            $active = false;
            $archive = false;
        } else {
            throw new ScheduleException('Aksi status jadwal tidak valid.');
        }
        $this->repository->scheduleSetState($id, $active, $archive, $actorId);
        $after = $this->repository->scheduleFind($id);
        $this->audit->log('schedule.status', 'jadwal_ngaji', $id, $before, $after, $actorId);
        $warnings = $active ? $this->conflictWarnings($this->repository->warningConflicts($this->structuredFromRow($after ?? $before), $id)) : [];
        return ['id' => $id, 'warnings' => $warnings];
    }

    public function activeScheduleOptions(array $user): array
    {
        $this->requireScheduleUser($user);
        return $this->repository->activeScheduleOptionsForUser($user);
    }

    public function meetingPage(array $user, string $q, int $page): array
    {
        $this->requireScheduleUser($user);
        return $this->repository->meetingPage($user, $q, $page);
    }

    public function meetings(array $user): array
    {
        $this->requireScheduleUser($user);
        return $this->repository->meetingList($user);
    }

    public function meeting(int $id, array $user): ?array
    {
        $this->requireScheduleUser($user);
        $meeting = $this->repository->meetingFind($id);
        if ($meeting === null) { return null; }
        $this->requireOwnership($meeting, $user);
        $meeting['participants'] = $this->repository->meetingParticipants($id);
        return $meeting;
    }

    public function createDraft(int $scheduleId, string $date, string $notes, array $user): int
    {
        $this->requireScheduleUser($user);
        $date = $this->validDate($date);
        $actorId = (int) $user['id'];
        $db = $this->repository->db();
        $db->begin_transaction();
        try {
            $schedule = $this->meetingSchedule($scheduleId, $date, $user);
            if ($this->repository->meetingByScheduleDate($scheduleId, $date, true) !== null) {
                throw new ScheduleException('Pertemuan untuk jadwal dan tanggal tersebut sudah ada.');
            }
            $id = $this->repository->meetingCreate($scheduleId, $date, $this->text($notes, 2000, 'Catatan'), $actorId);
            $this->audit->log('meeting.create_draft', 'pertemuan_pengajian', $id, null, $this->repository->meetingFind($id), $actorId);
            $db->commit();
            return $id;
        } catch (Throwable $exception) {
            $db->rollback();
            throw $exception;
        }
    }

    public function open(int $scheduleId, string $date, string $notes, array $user): int
    {
        $this->requireScheduleUser($user);
        $date = $this->validDate($date);
        $actorId = (int) $user['id'];
        $db = $this->repository->db();
        $db->begin_transaction();
        try {
            $schedule = $this->meetingSchedule($scheduleId, $date, $user);
            $meeting = $this->repository->meetingByScheduleDate($scheduleId, $date, true);
            if ($meeting !== null && $meeting['status'] !== 'Draf') {
                throw new ScheduleException('Pertemuan untuk jadwal dan tanggal tersebut sudah ada dan tidak dapat dibuat ulang.');
            }
            if ($meeting === null) {
                $meetingId = $this->repository->meetingCreate($scheduleId, $date, $this->text($notes, 2000, 'Catatan'), $actorId);
            } else {
                $meetingId = (int) $meeting['id'];
            }
            $before = $this->repository->meetingFind($meetingId);
            $this->repository->meetingOpen($meetingId, $actorId);
            $this->repository->snapshotParticipants($meetingId, (int) $schedule['id_kelas'], (int) $schedule['id_tahun']);
            $after = $this->repository->meetingFind($meetingId);
            $this->audit->log('meeting.open', 'pertemuan_pengajian', $meetingId, $before, $after, $actorId);
            $db->commit();
            return $meetingId;
        } catch (Throwable $exception) {
            $db->rollback();
            throw $exception;
        }
    }

    public function complete(int $meetingId, array $user): void
    {
        $this->requireScheduleUser($user);
        $actorId = (int) $user['id'];
        $db = $this->repository->db();
        $db->begin_transaction();
        try {
            $before = $this->repository->meetingFind($meetingId, true);
            if ($before === null) { throw new ScheduleException('Pertemuan tidak ditemukan.'); }
            $this->requireOwnership($before, $user);
            if ($before['status'] !== 'Dibuka') { throw new ScheduleException('Hanya pertemuan berstatus Dibuka yang dapat diselesaikan.'); }
            $this->repository->meetingComplete($meetingId, $actorId);
            $after = $this->repository->meetingFind($meetingId);
            $this->audit->log('meeting.complete', 'pertemuan_pengajian', $meetingId, $before, $after, $actorId);
            $db->commit();
        } catch (Throwable $exception) {
            $db->rollback();
            throw $exception;
        }
    }

    private function validateSchedule(array $input): array
    {
        $start = $this->time((string) ($input['waktu_mulai'] ?? ''), 'Waktu mulai');
        $end = $this->time((string) ($input['waktu_selesai'] ?? ''), 'Waktu selesai');
        if ($start >= $end) { throw new ScheduleException('Waktu selesai harus lebih akhir daripada waktu mulai. Jadwal lintas tengah malam belum didukung V1.'); }
        $day = trim((string) ($input['hari'] ?? ''));
        if (!in_array($day, self::DAYS, true)) { throw new ScheduleException('Hari jadwal tidak valid.'); }
        $prayerTime = trim((string) ($input['waktu_sholat'] ?? ''));
        if (!in_array($prayerTime, self::PRAYER_TIMES, true)) { throw new ScheduleException('Waktu pelaksanaan lama tidak valid.'); }
        $data = [
            'id_tahun' => (int) ($input['id_tahun'] ?? 0),
            'waktu_sholat' => $prayerTime,
            'hari' => $day,
            'waktu_mulai' => $start,
            'waktu_selesai' => $end,
            'jam' => str_replace(':', '.', substr($start, 0, 5)) . ' - ' . str_replace(':', '.', substr($end, 0, 5)) . ' WIB',
            'id_kelas' => (int) ($input['id_kelas'] ?? 0),
            'fan_ilmu' => $this->text((string) ($input['fan_ilmu'] ?? ''), 100, 'Fan ilmu', true),
            'nama_kitab' => $this->text((string) ($input['nama_kitab'] ?? ''), 100, 'Nama kitab', true),
            'id_guru' => (int) ($input['id_guru'] ?? 0),
            'tempat' => $this->text((string) ($input['tempat'] ?? ''), 100, 'Tempat', true),
        ];
        if ($data['id_tahun'] < 1 || $data['id_kelas'] < 1 || $data['id_guru'] < 1) {
            throw new ScheduleException('Tahun ajaran, kelas, dan guru wajib dipilih.');
        }
        return $data;
    }

    private function ensureReferences(array $data): void
    {
        $year = $this->repository->yearFind($data['id_tahun']);
        if ($year === null || $year['archived_at'] !== null) { throw new ScheduleException('Tahun ajaran tidak tersedia.'); }
        $teacher = $this->repository->teacherFind($data['id_guru']);
        if ($teacher === null || (int) $teacher['is_active'] !== 1 || $teacher['archived_at'] !== null) { throw new ScheduleException('Guru harus aktif dan tidak diarsipkan.'); }
        $class = $this->repository->classFind($data['id_kelas']);
        if ($class === null || (int) $class['is_active'] !== 1 || $class['archived_at'] !== null) { throw new ScheduleException('Kelas harus aktif dan tidak diarsipkan.'); }
    }

    private function rejectTeacherConflict(array $data, ?int $excludeId): void
    {
        $conflict = $this->repository->teacherConflict($data, $excludeId);
        if ($conflict !== null) {
            throw new ScheduleException(sprintf('Bentrok guru: %s sudah memiliki jadwal #%d pada %s pukul %s–%s di semester aktif.', $conflict['nama_guru'], $conflict['id'], $conflict['hari'], substr($conflict['waktu_mulai'], 0, 5), substr($conflict['waktu_selesai'], 0, 5)));
        }
    }

    private function conflictWarnings(array $rows): array
    {
        $warnings = [];
        foreach ($rows as $row) {
            $types = [];
            if ((int) $row['class_conflict'] === 1) { $types[] = 'kelas ' . $row['nama_kelas']; }
            if ((int) $row['place_conflict'] === 1) { $types[] = 'tempat ' . $row['tempat']; }
            $warnings[] = sprintf('Peringatan bentrok %s dengan jadwal #%d pukul %s–%s.', implode(' dan ', $types), $row['id'], substr($row['waktu_mulai'], 0, 5), substr($row['waktu_selesai'], 0, 5));
        }
        return $warnings;
    }

    private function meetingSchedule(int $scheduleId, string $date, array $user): array
    {
        $schedule = $this->repository->scheduleFind($scheduleId, true);
        if ($schedule === null) { throw new ScheduleException('Jadwal tidak ditemukan.'); }
        $this->requireOwnership($schedule, $user);
        if ((int) $schedule['is_active'] !== 1 || $schedule['archived_at'] !== null || $schedule['tahun_status'] !== 'Aktif' || $schedule['tahun_archived_at'] !== null) {
            throw new ScheduleException('Pertemuan hanya dapat dibuka dari jadwal aktif pada semester aktif.');
        }
        $this->requireStructured($this->structuredFromRow($schedule), 'Hari dan waktu jadwal belum terstruktur. Lengkapi jadwal terlebih dahulu.');
        $day = self::DAYS[(int) (new DateTimeImmutable($date))->format('N') - 1];
        if ($schedule['hari'] !== $day) {
            throw new ScheduleException('Tanggal yang dipilih jatuh pada hari ' . $day . ', bukan ' . $schedule['hari'] . ' sesuai pola jadwal.');
        }
        return $schedule;
    }

    private function requireOwnership(array $record, array $user): void
    {
        if (in_array('admin', $user['roles'], true)) { return; }
        if (!in_array('guru', $user['roles'], true) || empty($user['guru_id']) || (int) $record['id_guru'] !== (int) $user['guru_id']) {
            throw new ScheduleException('Akun guru hanya dapat mengelola pertemuan dari jadwal miliknya.');
        }
    }

    private function requireScheduleUser(array $user): void
    {
        if (!in_array('admin', $user['roles'], true) && !in_array('guru', $user['roles'], true)) {
            throw new ScheduleException('Akun tidak memiliki akses jadwal atau pertemuan.');
        }
        if (!in_array('admin', $user['roles'], true) && empty($user['guru_id'])) {
            throw new ScheduleException('Akun guru belum terhubung ke data guru.');
        }
    }

    private function mustSchedule(int $id): array
    {
        $row = $this->repository->scheduleFind($id);
        if ($row === null) { throw new ScheduleException('Jadwal tidak ditemukan.'); }
        return $row;
    }

    private function structuredFromRow(array $row): array
    {
        return ['id_tahun' => (int) $row['id_tahun'], 'id_guru' => (int) $row['id_guru'], 'id_kelas' => (int) $row['id_kelas'], 'hari' => $row['hari'], 'waktu_mulai' => $row['waktu_mulai'], 'waktu_selesai' => $row['waktu_selesai'], 'tempat' => (string) $row['tempat']];
    }

    private function requireStructured(array $data, string $message): void
    {
        if (empty($data['hari']) || empty($data['waktu_mulai']) || empty($data['waktu_selesai'])) { throw new ScheduleException($message); }
    }

    private function validDate(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
        if ($date === false || $date->format('Y-m-d') !== trim($value)) { throw new ScheduleException('Tanggal pertemuan tidak valid.'); }
        return $date->format('Y-m-d');
    }

    private function time(string $value, string $label): string
    {
        $value = trim($value);
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value)) { throw new ScheduleException($label . ' harus berformat HH:MM.'); }
        return $value . ':00';
    }

    private function text(string $value, int $maximum, string $label, bool $required = false): string
    {
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';
        if ($required && $value === '') { throw new ScheduleException($label . ' wajib diisi.'); }
        if (mb_strlen($value) > $maximum) { throw new ScheduleException($label . ' maksimal ' . $maximum . ' karakter.'); }
        return $value;
    }
}
