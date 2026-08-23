<?php

declare(strict_types=1);

namespace App\Api;

use App\Notification\DeviceService;
use App\Notification\NotificationAdminService;
use App\Notification\NotificationCenterService;
use App\Notification\NotificationException;
use Throwable;

/**
 * Lapisan REST untuk notifikasi V2 Fase 4.
 *
 * Sama seperti `IzinApiService`, lapisan ini HANYA menerjemahkan. Seluruh
 * otorisasi, cakupan, deduplikasi, dan audit tetap dikerjakan layanan
 * `App\Notification\*`; tidak ada jalur pintas yang melewatinya.
 *
 * `NotificationException` dipetakan ke `ApiException` beserta status aslinya
 * (403/409/422/503) sehingga klien dapat menindaklanjuti secara spesifik, dan
 * pesan yang keluar sudah dipastikan aman (tanpa token maupun credential).
 */
final class NotificationApiService
{
    public function __construct(
        private NotificationCenterService $center,
        private DeviceService $devices,
        private NotificationAdminService $admin
    ) {
    }

    // =======================================================================
    // Pusat notifikasi pengguna
    // =======================================================================

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function index(array $user, array $query): array
    {
        return $this->translate(fn (): array => $this->center->index($user, $query));
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function unreadCount(array $user): array
    {
        return $this->translate(fn (): array => $this->center->unreadCount($user));
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function show(array $user, int $id): array
    {
        return $this->translate(fn (): array => $this->center->show($user, $id));
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function markRead(array $user, int $id): array
    {
        return $this->translate(fn (): array => $this->center->markRead($user, $id));
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function markAllRead(array $user): array
    {
        return $this->translate(fn (): array => $this->center->markAllRead($user));
    }

    // =======================================================================
    // Perangkat push
    // =======================================================================

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function registerDevice(array $user, array $input): array
    {
        return $this->translate(fn (): array => $this->devices->register($user, $input));
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function devices(array $user): array
    {
        return $this->translate(fn (): array => $this->devices->index($user));
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function revokeDevice(array $user, array $input): array
    {
        return $this->translate(fn (): array => $this->devices->revoke($user, $input));
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function setDevicePush(array $user, int $deviceId, array $input): array
    {
        $enabled = filter_var($input['push_aktif'] ?? true, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($enabled === null) {
            throw new ApiException('VALIDATION_FAILED', 'Nilai push_aktif harus true atau false.', 422);
        }

        return $this->translate(fn (): array => $this->devices->setEnabled($user, $deviceId, $enabled));
    }

    // =======================================================================
    // Panel admin
    // =======================================================================

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function adminStatus(array $user): array
    {
        return $this->translate(fn (): array => $this->admin->status($user));
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @param array{ip:?string, user_agent:?string} $meta
     * @return array<string, mixed>
     */
    public function adminCheck(array $user, array $input, array $meta): array
    {
        $kanal = (string) ($input['kanal'] ?? '');

        return $this->translate(fn (): array => $this->admin->periksaKonfigurasi($user, $kanal, $meta));
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @param array{ip:?string, user_agent:?string} $meta
     * @return array<string, mixed>
     */
    public function adminToggle(array $user, array $input, array $meta): array
    {
        $kanal = (string) ($input['kanal'] ?? '');
        $aktif = filter_var($input['aktif'] ?? null, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($aktif === null) {
            throw new ApiException('VALIDATION_FAILED', 'Nilai aktif harus true atau false.', 422);
        }

        return $this->translate(fn (): array => $this->admin->ubahSakelar($user, $kanal, $aktif, $meta));
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @param array{ip:?string, user_agent:?string} $meta
     * @return array<string, mixed>
     */
    public function adminTestMessage(array $user, array $input, array $meta): array
    {
        $kanal = (string) ($input['kanal'] ?? '');

        return $this->translate(fn (): array => $this->admin->kirimPesanUji($user, $kanal, $meta));
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function adminFailures(array $user, array $query): array
    {
        return $this->translate(fn (): array => $this->admin->kegagalan($user, $query));
    }

    /**
     * @param array<string, mixed> $user
     * @param array{ip:?string, user_agent:?string} $meta
     * @return array<string, mixed>
     */
    public function adminRetry(array $user, int $outboxId, array $meta): array
    {
        return $this->translate(fn (): array => $this->admin->cobaUlang($user, $outboxId, $meta));
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function adminRunWorker(array $user, array $input): array
    {
        $kanal = (string) ($input['kanal'] ?? '');
        $dryRun = filter_var($input['uji_coba'] ?? false, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;

        return $this->translate(fn (): array => $this->admin->jalankanWorker($user, $kanal, $dryRun));
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function adminAudit(array $user, array $query): array
    {
        $limit = (int) ($query['limit'] ?? 50);

        return $this->translate(fn (): array => $this->admin->auditKanal($user, $limit));
    }

    /**
     * Pencabutan perangkat saat logout. Tidak pernah melempar: logout harus
     * berhasil walaupun pencabutan bermasalah.
     *
     * @param array<string, mixed> $user
     */
    public function revokeOnLogout(array $user, ?string $pushToken): int
    {
        return $this->devices->revokeOnLogout($user, $pushToken);
    }

    /**
     * @template T
     * @param callable():T $operation
     * @return T
     */
    private function translate(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (NotificationException $exception) {
            throw new ApiException(
                match ($exception->status()) {
                    403 => 'FORBIDDEN',
                    409 => 'CONFLICT',
                    503 => 'SERVICE_UNAVAILABLE',
                    default => 'VALIDATION_FAILED',
                },
                $exception->getMessage(),
                $exception->status()
            );
        } catch (ApiException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            error_log('Notifikasi API gagal: ' . $exception->getMessage());
            throw new ApiException('SERVER_ERROR', 'Notifikasi tidak dapat diproses saat ini.', 500);
        }
    }
}
