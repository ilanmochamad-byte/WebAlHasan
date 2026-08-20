<?php

declare(strict_types=1);

namespace App\Report;

use App\Api\ApiException;
use DateTimeImmutable;
use DateTimeZone;

final class ReportFilter
{
    public const STATUSES = ['Hadir', 'Terlambat', 'Izin', 'Sakit', 'Alpa'];

    public function __construct(
        public readonly string $dateFrom,
        public readonly string $dateTo,
        public readonly ?int $academicYearId,
        public readonly ?int $teacherId,
        public readonly ?int $classId,
        public readonly ?int $scheduleId,
        public readonly ?string $status,
        public readonly int $page,
        public readonly int $perPage
    ) {
    }

    public static function fromInput(array $input, string $timezone): self
    {
        $zone = new DateTimeZone($timezone);
        $today = new DateTimeImmutable('today', $zone);
        $defaultFrom = $today->modify('first day of this month')->format('Y-m-d');
        $from = self::date((string) ($input['date_from'] ?? $defaultFrom), 'date_from', $zone);
        $to = self::date((string) ($input['date_to'] ?? $today->format('Y-m-d')), 'date_to', $zone);
        if ($to < $from) {
            throw new ApiException('VALIDATION_FAILED', 'Tanggal akhir tidak boleh sebelum tanggal awal.', 422, [
                'date_to' => 'Tanggal akhir tidak valid.',
            ]);
        }

        $status = trim((string) ($input['status'] ?? ''));
        if ($status !== '' && !in_array($status, self::STATUSES, true)) {
            throw new ApiException('VALIDATION_FAILED', 'Status absensi tidak valid.', 422, [
                'status' => 'Pilih Hadir, Terlambat, Izin, Sakit, atau Alpa.',
            ]);
        }

        return new self(
            $from->format('Y-m-d'),
            $to->format('Y-m-d'),
            self::positiveId($input['academic_year_id'] ?? $input['year_id'] ?? null, 'academic_year_id'),
            self::positiveId($input['teacher_id'] ?? null, 'teacher_id'),
            self::positiveId($input['class_id'] ?? null, 'class_id'),
            self::positiveId($input['schedule_id'] ?? null, 'schedule_id'),
            $status === '' ? null : $status,
            max(1, (int) ($input['page'] ?? 1)),
            max(1, min(100, (int) ($input['per_page'] ?? 25)))
        );
    }

    public function forUser(array $user): self
    {
        if (in_array('admin', $user['roles'] ?? [], true)) {
            return $this;
        }
        $guruId = isset($user['guru_id']) ? (int) $user['guru_id'] : 0;
        if (!in_array('guru', $user['roles'] ?? [], true) || $guruId < 1) {
            throw new ApiException('FORBIDDEN', 'Akun tidak memiliki akses laporan.', 403);
        }
        if ($this->teacherId !== null && $this->teacherId !== $guruId) {
            throw new ApiException('FORBIDDEN', 'Guru hanya dapat membuka laporan jadwal miliknya.', 403);
        }

        return new self(
            $this->dateFrom,
            $this->dateTo,
            $this->academicYearId,
            $guruId,
            $this->classId,
            $this->scheduleId,
            $this->status,
            $this->page,
            $this->perPage
        );
    }

    public function withPagination(int $page, int $perPage): self
    {
        return new self(
            $this->dateFrom,
            $this->dateTo,
            $this->academicYearId,
            $this->teacherId,
            $this->classId,
            $this->scheduleId,
            $this->status,
            max(1, $page),
            max(1, min(100, $perPage))
        );
    }

    public function toArray(): array
    {
        return [
            'date_from' => $this->dateFrom,
            'date_to' => $this->dateTo,
            'academic_year_id' => $this->academicYearId,
            'teacher_id' => $this->teacherId,
            'class_id' => $this->classId,
            'schedule_id' => $this->scheduleId,
            'status' => $this->status,
        ];
    }

    private static function date(string $value, string $field, DateTimeZone $zone): DateTimeImmutable
    {
        $value = trim($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new ApiException('VALIDATION_FAILED', 'Tanggal harus berformat YYYY-MM-DD.', 422, [
                $field => 'Tanggal tidak valid.',
            ]);
        }
        return $date;
    }

    private static function positiveId(mixed $value, string $field): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        if (!preg_match('/^[1-9][0-9]*$/', trim((string) $value))) {
            throw new ApiException('VALIDATION_FAILED', 'Parameter filter tidak valid.', 422, [
                $field => 'ID harus berupa bilangan bulat positif.',
            ]);
        }
        return (int) $value;
    }
}
