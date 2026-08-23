<?php

declare(strict_types=1);

namespace App\Notification;

/**
 * Pusat notifikasi milik pengguna yang sedang masuk.
 *
 * Aturan tunggal yang menyangga seluruh kelas ini: **id penerima selalu diambil
 * dari sesi/token, tidak pernah dari parameter request.** Semua query di
 * `NotificationRepository` mewajibkan `penerima_user_id`, sehingga mengganti
 * `id` pada URL atau body hanya menghasilkan 403 — bukan notifikasi milik orang
 * lain (kriteria penerimaan Fase 4 poin 2).
 */
final class NotificationCenterService
{
    public const PER_PAGE_DEFAULT = 20;
    public const PER_PAGE_MAX = 100;

    public function __construct(private NotificationRepository $repository)
    {
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function index(array $user, array $query): array
    {
        $userId = $this->userId($user);
        $filter = $this->filter($query);
        [$page, $perPage] = $this->pageOf($query);

        $result = $this->repository->listForUser($userId, $filter, $page, $perPage);
        $total = (int) $result['total'];

        return [
            'items' => array_map([$this, 'present'], $result['rows']),
            'jumlah_belum_dibaca' => $this->repository->unreadCount($userId),
            'filters' => ['status' => $filter],
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $total === 0 ? 0 : (int) ceil($total / $perPage),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array{jumlah:int}
     */
    public function unreadCount(array $user): array
    {
        return ['jumlah' => $this->repository->unreadCount($this->userId($user))];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function show(array $user, int $id): array
    {
        $userId = $this->userId($user);
        $row = $this->repository->findForUser($id, $userId);
        if ($row === null) {
            // Tidak ada dan bukan milik pengguna diperlakukan sama: 403.
            throw NotificationException::forbidden();
        }

        return ['notifikasi' => $this->present($row)];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function markRead(array $user, int $id): array
    {
        $userId = $this->userId($user);
        $row = $this->repository->findForUser($id, $userId);
        if ($row === null) {
            throw NotificationException::forbidden();
        }
        $this->repository->markRead($id, $userId);
        $segar = $this->repository->findForUser($id, $userId);

        return [
            'notifikasi' => $segar === null ? $this->present($row) : $this->present($segar),
            'jumlah_belum_dibaca' => $this->repository->unreadCount($userId),
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array{ditandai:int, jumlah_belum_dibaca:int}
     */
    public function markAllRead(array $user): array
    {
        $userId = $this->userId($user);
        $ditandai = $this->repository->markAllRead($userId);

        return [
            'ditandai' => $ditandai,
            'jumlah_belum_dibaca' => $this->repository->unreadCount($userId),
        ];
    }

    /**
     * Bentuk tampilan satu notifikasi.
     *
     * Tidak pernah memuat token, nomor telepon, credential, atau alasan izin —
     * kolom-kolom itu memang tidak ikut dibaca repositori.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        $data = json_decode((string) ($row['data_json'] ?? ''), true);
        $data = is_array($data) ? $data : [];

        return [
            'id' => (int) $row['id'],
            'event_type' => (string) $row['event_type'],
            'event_label' => NotificationEvent::label((string) $row['event_type']),
            'judul' => (string) $row['judul'],
            'isi' => (string) $row['isi'],
            'pengajuan_id' => $row['pengajuan_id'] === null ? null : (int) $row['pengajuan_id'],
            'pengajuan_status' => $row['pengajuan_status'] === null ? null : (string) $row['pengajuan_status'],
            'santri_nama' => $row['santri_nama'] === null ? null : (string) $row['santri_nama'],
            'dibaca' => $row['dibaca_pada'] !== null,
            'dibaca_pada' => $row['dibaca_pada'] === null ? null : (string) $row['dibaca_pada'],
            'dibuat_pada' => (string) $row['created_at'],
            // Hanya penunjuk sumber daya. Klien WAJIB tetap memanggil endpoint
            // detail izin, yang memverifikasi hak akses di server.
            'tautan' => [
                'tipe' => (string) ($data['tipe'] ?? 'sistem'),
                'pengajuan_id' => isset($data['pengajuan_id']) ? (int) $data['pengajuan_id'] : null,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $user
     */
    private function userId(array $user): int
    {
        $id = (int) ($user['id'] ?? 0);
        if ($id < 1) {
            throw NotificationException::forbidden('Sesi tidak valid.');
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $query
     */
    private function filter(array $query): string
    {
        $status = strtolower(trim((string) ($query['status'] ?? 'semua')));

        return in_array($status, ['belum_dibaca', 'sudah_dibaca'], true) ? $status : 'semua';
    }

    /**
     * @param array<string, mixed> $query
     * @return array{0:int, 1:int}
     */
    private function pageOf(array $query): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = (int) ($query['per_page'] ?? self::PER_PAGE_DEFAULT);
        $perPage = max(1, min(self::PER_PAGE_MAX, $perPage === 0 ? self::PER_PAGE_DEFAULT : $perPage));

        return [$page, $perPage];
    }
}
