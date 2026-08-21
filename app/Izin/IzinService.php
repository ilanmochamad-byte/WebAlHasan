<?php

declare(strict_types=1);

namespace App\Izin;

use App\Auth\Capabilities;

/**
 * Layanan baca perizinan berbasis kemampuan (Fase 1).
 *
 * Cakupan ditentukan sepenuhnya di server dari akun yang sedang masuk. Parameter
 * request tidak pernah dipakai untuk memperluas cakupan; parameter hanya boleh
 * mempersempit hasil di dalam cakupan yang sudah ditetapkan.
 */
final class IzinService
{
    public function __construct(
        private IzinRepository $repository,
        private Capabilities $capabilities
    ) {
    }

    /**
     * Cakupan efektif untuk satu pengguna pada satu tampilan.
     *
     * @param array{id:int, roles:array<int,string>, guru_id:int|null} $user
     * @return array{mode:string, pengurus_id:?int, guru_id:?int, wali_id:?int, label:string}
     */
    public function scopeFor(array $user, ?string $preferred = null): array
    {
        $available = $this->capabilities->forUser($user);
        if ($available === []) {
            throw IzinException::forbidden('Akun ini belum memiliki kemampuan perizinan.');
        }

        $mode = $preferred !== null && in_array($preferred, $available, true)
            ? $preferred
            : $this->defaultMode($available);

        return [
            'mode' => $mode,
            'pengurus_id' => $mode === Capabilities::PENGURUS ? $this->capabilities->linkedPengurusId((int) $user['id']) : null,
            'guru_id' => $mode === Capabilities::MUROBI ? ($user['guru_id'] === null ? null : (int) $user['guru_id']) : null,
            'wali_id' => $mode === Capabilities::ORANG_TUA ? $this->capabilities->linkedWaliId((int) $user['id']) : null,
            'label' => $this->label($mode),
        ];
    }

    /**
     * @param array<int, string> $available
     */
    private function defaultMode(array $available): string
    {
        foreach ([Capabilities::ADMIN, Capabilities::PENGURUS, Capabilities::MUROBI, Capabilities::ORANG_TUA] as $candidate) {
            if (in_array($candidate, $available, true)) {
                return $candidate;
            }
        }

        throw IzinException::forbidden('Akun ini belum memiliki kemampuan perizinan.');
    }

    public function label(string $mode): string
    {
        return match ($mode) {
            Capabilities::ADMIN => 'Admin — seluruh pengajuan',
            Capabilities::PENGURUS => 'Pengurus — pengajuan yang Anda buat',
            Capabilities::MUROBI => 'Murobi — pengajuan yang diarahkan kepada Anda',
            Capabilities::ORANG_TUA => 'Orang tua — santri yang terhubung dengan Anda',
            default => 'Tanpa cakupan',
        };
    }

    /**
     * @param array{id:int, roles:array<int,string>, guru_id:int|null} $user
     * @param array<string, mixed> $filters
     */
    public function list(array $user, array $filters, int $page = 1, int $perPage = 20, ?string $preferred = null): array
    {
        $scope = $this->scopeFor($user, $preferred);
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $result = $this->repository->list($filters, $scope, $page, $perPage);

        return [
            'scope' => $scope,
            'rows' => array_map([$this, 'present'], $result['rows']),
            'total' => $result['total'],
            'page' => $page,
            'per_page' => $perPage,
            'summary' => $this->repository->summary($scope),
        ];
    }

    /**
     * @param array{id:int, roles:array<int,string>, guru_id:int|null} $user
     */
    public function detail(array $user, int $id, ?string $preferred = null): array
    {
        $scope = $this->scopeFor($user, $preferred);
        $row = $this->repository->find($id, $scope);
        if ($row === null) {
            // Di luar cakupan dan tidak ada sama sekali diperlakukan sama: 403,
            // agar keberadaan pengajuan milik orang lain tidak bocor lewat ID.
            throw IzinException::forbidden();
        }

        return [
            'scope' => $scope,
            'pengajuan' => $this->present($row),
            'keputusan' => $this->repository->decision($id),
            'riwayat' => $this->repository->history($id),
            // Fase 2: koreksi keputusan disimpan sebagai peristiwa terpisah sehingga
            // nilai sebelum/sesudah tetap terbaca tanpa menimpa keputusan pertama.
            'koreksi' => $this->repository->corrections($id),
        ];
    }

    /**
     * Jumlah pengajuan yang menunggu tindakan pengguna pada cakupannya.
     *
     * @param array{id:int, roles:array<int,string>, guru_id:int|null} $user
     */
    public function queueCount(array $user, ?string $preferred = null): int
    {
        return $this->repository->queueCount($this->scopeFor($user, $preferred));
    }

    /**
     * @param array{id:int, roles:array<int,string>, guru_id:int|null} $user
     */
    public function santriInScope(array $user, ?string $preferred = null): array
    {
        $scope = $this->scopeFor($user, $preferred);

        return match ($scope['mode']) {
            Capabilities::PENGURUS => $this->repository->santriForPengurus((int) $scope['pengurus_id'], date('Y-m-d')),
            Capabilities::ORANG_TUA => $this->repository->santriForWali((int) $scope['wali_id']),
            default => [],
        };
    }

    /**
     * Menambahkan label tampilan tanpa mengubah nilai bisnis yang tersimpan.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        $legacy = (int) ($row['is_legacy'] ?? 0) === 1;
        $row['is_legacy'] = $legacy;
        $row['sumber_label'] = $legacy ? 'Data warisan' : 'V2';
        $row['pengurus_label'] = $row['pengurus_nama'] ?? ($legacy ? 'Data warisan' : 'Belum ditetapkan');
        $row['murobi_label'] = $row['murobi_nama'] ?? ($legacy ? 'Data warisan' : 'Belum ditetapkan');
        $row['keputusan_label'] = $row['keputusan_hasil'] ?? ($legacy ? 'Data warisan' : 'Belum ada keputusan');

        return $row;
    }
}
