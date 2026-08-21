<?php

declare(strict_types=1);

namespace App\Izin;

use App\Audit\AuditLogger;

/**
 * Validasi dan audit penugasan pembimbing.
 *
 * Seluruh pemeriksaan dilakukan di server: pengurus harus aktif, tahun ajaran harus
 * belum diarsipkan, dan target kamar/kelas harus benar-benar ada serta dapat dipakai.
 */
final class PembimbingService
{
    public function __construct(
        private PembimbingRepository $repository,
        private AuditLogger $audit
    ) {
    }

    public function all(): array
    {
        return $this->repository->all();
    }

    public function activePengurus(): array
    {
        return $this->repository->activePengurus();
    }

    public function activeForPengurus(int $pengurusId, ?string $onDate = null): array
    {
        return $this->repository->activeForPengurus($pengurusId, $onDate ?? date('Y-m-d'));
    }

    /**
     * @param array<string, mixed> $input
     */
    public function create(array $input, int $actorId): int
    {
        $data = $this->validate($input);
        $id = $this->repository->create($data, $actorId);
        $this->audit->log('pembimbing_assignment_created', 'pembimbing_assignment', $id, null, $data, $actorId);

        return $id;
    }

    public function setState(int $id, string $action, int $actorId): void
    {
        $before = $this->repository->find($id);
        if ($before === null) {
            throw IzinException::notFound('Penugasan pembimbing tidak ditemukan.');
        }

        match ($action) {
            'activate' => $this->repository->setState($id, true),
            'deactivate' => $this->repository->setState($id, false),
            'archive' => $this->repository->setState($id, false, true),
            'restore' => $this->repository->setState($id, true, false),
            default => throw IzinException::invalid('Aksi penugasan pembimbing tidak dikenal.'),
        };

        $after = $this->repository->find($id);
        $this->audit->log(
            'pembimbing_assignment_state_changed',
            'pembimbing_assignment',
            $id,
            ['is_active' => (int) $before['is_active'], 'archived_at' => $before['archived_at']],
            ['is_active' => (int) ($after['is_active'] ?? 0), 'archived_at' => $after['archived_at'] ?? null, 'action' => $action],
            $actorId
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function validate(array $input): array
    {
        $pengurusId = (int) ($input['pengurus_id'] ?? 0);
        if ($pengurusId < 1 || !$this->repository->pengurusIsActive($pengurusId)) {
            throw IzinException::invalid('Penugasan pembimbing hanya dapat memakai pengurus yang aktif dan belum diarsipkan.');
        }

        $yearId = (int) ($input['tahun_ajaran_id'] ?? 0);
        if ($yearId < 1 || !$this->repository->yearIsUsable($yearId)) {
            throw IzinException::invalid('Tahun ajaran tidak valid atau sudah diarsipkan.');
        }

        $targetType = (string) ($input['target_type'] ?? '');
        if (!in_array($targetType, ['Kamar', 'Kelas'], true)) {
            throw IzinException::invalid('Jenis target penugasan harus Kamar atau Kelas.');
        }

        $kamarId = null;
        $kelasId = null;
        if ($targetType === 'Kamar') {
            $kamarId = (int) ($input['kamar_id'] ?? 0);
            if ($kamarId < 1 || !$this->repository->kamarExists($kamarId)) {
                throw IzinException::invalid('Kamar target tidak ditemukan.');
            }
        } else {
            $kelasId = (int) ($input['kelas_id'] ?? 0);
            if ($kelasId < 1 || !$this->repository->kelasIsUsable($kelasId)) {
                throw IzinException::invalid('Kelas target tidak ditemukan atau sudah tidak aktif.');
            }
        }

        $start = $this->date((string) ($input['tanggal_mulai'] ?? ''));
        if ($start === null) {
            throw IzinException::invalid('Tanggal mulai penugasan wajib diisi dengan tanggal yang valid.');
        }
        $endRaw = trim((string) ($input['tanggal_selesai'] ?? ''));
        $end = $endRaw === '' ? null : $this->date($endRaw);
        if ($endRaw !== '' && $end === null) {
            throw IzinException::invalid('Tanggal selesai penugasan tidak valid.');
        }
        if ($end !== null && $end < $start) {
            throw IzinException::invalid('Tanggal selesai tidak boleh mendahului tanggal mulai.');
        }

        return [
            'pengurus_id' => $pengurusId,
            'tahun_ajaran_id' => $yearId,
            'target_type' => $targetType,
            'kamar_id' => $kamarId,
            'kelas_id' => $kelasId,
            'tanggal_mulai' => $start,
            'tanggal_selesai' => $end,
        ];
    }

    private function date(string $value): ?string
    {
        $value = trim($value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year) ? $value : null;
    }
}
