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

    public function page(string $q, int $page): array { return $this->repository->page($q, $page); }

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
        try {
            return $this->penugasan()->buat('pembimbing', $input, $actorId);
        } catch (\App\Penugasan\PenugasanException $e) {
            throw new IzinException($e->getMessage(), $e->status());
        }
    }

    public function setState(int $id, string $action, int $actorId, string $alasan = ''): void
    {
        try {
            $this->penugasan()->statusLama('pembimbing', $id, $action, $actorId, $alasan);
        } catch (\App\Penugasan\PenugasanException $e) {
            throw new IzinException($e->getMessage(), $e->status());
        }
    }

    private function penugasan(): \App\Penugasan\PenugasanService
    {
        $db = $this->repository->db();
        return new \App\Penugasan\PenugasanService(new \App\Penugasan\PenugasanRepository($db), $this->audit, new \App\Auth\Capabilities($db));
    }
}
