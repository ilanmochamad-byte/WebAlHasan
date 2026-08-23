<?php

declare(strict_types=1);

namespace App\Notification;

use App\Audit\AuditLogger;
use App\Notification\Push\ExpoPushClient;

/**
 * Registrasi dan pencabutan perangkat push milik pengguna yang sedang masuk.
 *
 * Kewajiban keamanan yang ditegakkan di sini (PRD Fase 4 §5.4-§5.6):
 *   - `user_id` selalu dari token/sesi; parameter request tidak pernah dipakai
 *     untuk menentukan pemilik perangkat;
 *   - token mentah TIDAK PERNAH dikembalikan ke klien, ditulis ke audit, atau
 *     dicatat ke log — audit hanya menyimpan platform, label, dan sidik pendek;
 *   - pencabutan tersedia untuk logout, penonaktifan push oleh pengguna, token
 *     yang tidak valid, dan penghapusan perangkat.
 */
final class DeviceService
{
    public const ALASAN_LOGOUT = 'logout';
    public const ALASAN_PENGGUNA = 'dinonaktifkan_pengguna';
    public const ALASAN_TOKEN_INVALID = 'token_invalid';
    public const ALASAN_DIHAPUS = 'perangkat_dihapus';

    public function __construct(
        private DeviceRepository $repository,
        private PushTokenProtector $protector,
        private AuditLogger $audit
    ) {
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function register(array $user, array $input): array
    {
        $userId = $this->userId($user);

        if (!$this->protector->ready()) {
            throw NotificationException::unavailable(
                'Registrasi perangkat push belum dapat dilayani: ' . (string) $this->protector->reason()
            );
        }

        $token = trim((string) ($input['token'] ?? ''));
        if ($token === '') {
            throw NotificationException::invalid('Token perangkat wajib diisi.');
        }
        if (mb_strlen($token) > 255) {
            throw NotificationException::invalid('Token perangkat terlalu panjang.');
        }
        if (!ExpoPushClient::looksLikeExpoToken($token)) {
            throw NotificationException::invalid(
                'Token bukan Expo push token yang valid. Pastikan aplikasi memakai development build, bukan Expo Go.'
            );
        }

        $platform = strtolower(trim((string) ($input['platform'] ?? '')));
        if (!in_array($platform, ['android', 'ios', 'web'], true)) {
            throw NotificationException::invalid('Platform perangkat harus android, ios, atau web.');
        }

        $deviceId = $this->optionalText($input['device_id'] ?? null, 100);
        $label = $this->optionalText($input['device_label'] ?? null, 100);
        $appVersion = $this->optionalText($input['app_version'] ?? null, 30);

        $hash = $this->protector->hash($token);
        $hasil = $this->repository->register([
            'user_id' => $userId,
            'token_hash' => $hash,
            'token_terlindungi' => $this->protector->protect($token),
            'platform' => $platform,
            'device_id' => $deviceId,
            'device_label' => $label,
            'app_version' => $appVersion,
        ]);

        // Audit TIDAK memuat token. Sidik 12 karakter pertama dari HMAC cukup
        // untuk menelusuri satu perangkat tanpa dapat dibalik menjadi token.
        $this->audit->log('notifikasi.perangkat_didaftarkan', 'perangkat_push', $hasil['id'], null, [
            'platform' => $platform,
            'device_label' => $label,
            'app_version' => $appVersion,
            'sidik_token' => substr($hash, 0, 12),
            'baru' => $hasil['baru'],
        ], $userId);

        return [
            'perangkat_id' => $hasil['id'],
            'baru' => $hasil['baru'],
            'platform' => $platform,
            'push_aktif' => true,
            'pesan' => 'Perangkat terdaftar untuk menerima push notification.',
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array{items:array<int, array<string, mixed>>}
     */
    public function index(array $user): array
    {
        $userId = $this->userId($user);

        return [
            'items' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'platform' => (string) $row['platform'],
                'device_label' => $row['device_label'] === null ? null : (string) $row['device_label'],
                'app_version' => $row['app_version'] === null ? null : (string) $row['app_version'],
                'push_aktif' => (int) $row['push_aktif'] === 1 && $row['dicabut_pada'] === null,
                'dicabut' => $row['dicabut_pada'] !== null,
                'alasan_pencabutan' => $row['alasan_pencabutan'] === null ? null : (string) $row['alasan_pencabutan'],
                'terakhir_aktif_pada' => $row['terakhir_aktif_pada'] === null ? null : (string) $row['terakhir_aktif_pada'],
                'terdaftar_pada' => (string) $row['created_at'],
            ], $this->repository->listForUser($userId)),
        ];
    }

    /**
     * Mencabut satu perangkat milik pengguna.
     *
     * Menerima `perangkat_id` (dari daftar perangkat) ATAU `token` (dipakai
     * aplikasi saat logout, ketika ia hanya memegang tokennya sendiri).
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function revoke(array $user, array $input): array
    {
        $userId = $this->userId($user);
        $alasan = $this->alasan((string) ($input['alasan'] ?? self::ALASAN_DIHAPUS));

        $perangkatId = (int) ($input['perangkat_id'] ?? 0);
        $token = trim((string) ($input['token'] ?? ''));
        $semua = (bool) ($input['semua'] ?? false);

        if ($semua) {
            $jumlah = $this->repository->revokeAllForUser($userId, $alasan);
            $this->audit->log('notifikasi.perangkat_dicabut', 'perangkat_push', null, null, [
                'lingkup' => 'semua',
                'jumlah' => $jumlah,
                'alasan' => $alasan,
            ], $userId);

            return ['dicabut' => $jumlah, 'pesan' => $jumlah . ' perangkat dicabut dari akun ini.'];
        }

        if ($perangkatId > 0) {
            $ok = $this->repository->revoke($perangkatId, $userId, $alasan);
            if (!$ok) {
                // Perangkat milik orang lain, tidak ada, atau sudah dicabut:
                // ketiganya dijawab sama agar keberadaan ID tidak bocor.
                throw NotificationException::forbidden('Perangkat tidak ditemukan pada akun ini.');
            }
            $this->audit->log('notifikasi.perangkat_dicabut', 'perangkat_push', $perangkatId, null, [
                'lingkup' => 'satu',
                'alasan' => $alasan,
            ], $userId);

            return ['dicabut' => 1, 'pesan' => 'Perangkat dicabut dan tidak akan menerima push lagi.'];
        }

        if ($token !== '') {
            if (!$this->protector->ready()) {
                throw NotificationException::unavailable('Konfigurasi perlindungan token belum siap di server.');
            }
            $hash = $this->protector->hash($token);
            $ok = $this->repository->revokeByHash($hash, $userId, $alasan);
            $this->audit->log('notifikasi.perangkat_dicabut', 'perangkat_push', null, null, [
                'lingkup' => 'token',
                'sidik_token' => substr($hash, 0, 12),
                'alasan' => $alasan,
                'ditemukan' => $ok,
            ], $userId);

            // Logout tidak boleh gagal hanya karena token sudah tidak terdaftar.
            return ['dicabut' => $ok ? 1 : 0, 'pesan' => 'Registrasi push untuk perangkat ini dihentikan.'];
        }

        throw NotificationException::invalid('Sertakan perangkat_id, token, atau semua=true.');
    }

    /**
     * Menyalakan/mematikan push pada satu perangkat tanpa mencabut registrasi.
     *
     * Mematikan push TIDAK pernah mempengaruhi notifikasi in-app.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function setEnabled(array $user, int $perangkatId, bool $enabled): array
    {
        $userId = $this->userId($user);
        if ($perangkatId < 1) {
            throw NotificationException::invalid('Perangkat tidak valid.');
        }
        $milik = false;
        foreach ($this->repository->listForUser($userId) as $row) {
            if ((int) $row['id'] === $perangkatId) {
                $milik = true;
                break;
            }
        }
        if (!$milik) {
            throw NotificationException::forbidden('Perangkat tidak ditemukan pada akun ini.');
        }

        $this->repository->setPushEnabled($perangkatId, $userId, $enabled);
        $this->audit->log('notifikasi.perangkat_push_diubah', 'perangkat_push', $perangkatId, null, [
            'push_aktif' => $enabled,
        ], $userId);

        return [
            'perangkat_id' => $perangkatId,
            'push_aktif' => $enabled,
            'pesan' => $enabled
                ? 'Push dinyalakan untuk perangkat ini.'
                : 'Push dimatikan untuk perangkat ini. Notifikasi dalam aplikasi tetap berjalan.',
        ];
    }

    /**
     * Dipanggil saat logout API. Tidak pernah melempar galat: logout harus
     * selalu berhasil walaupun pencabutan perangkat bermasalah.
     *
     * @param array<string, mixed> $user
     */
    public function revokeOnLogout(array $user, ?string $token): int
    {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId < 1) {
            return 0;
        }
        try {
            if ($token !== null && trim($token) !== '' && $this->protector->ready()) {
                $hash = $this->protector->hash(trim($token));
                $ok = $this->repository->revokeByHash($hash, $userId, self::ALASAN_LOGOUT);
                $this->audit->log('notifikasi.perangkat_dicabut', 'perangkat_push', null, null, [
                    'lingkup' => 'logout_token',
                    'sidik_token' => substr($hash, 0, 12),
                    'ditemukan' => $ok,
                ], $userId);

                return $ok ? 1 : 0;
            }

            // Tanpa token perangkat, logout mencabut seluruh registrasi akun ini
            // agar sesi lama tidak meninggalkan perangkat yang masih menerima push.
            $jumlah = $this->repository->revokeAllForUser($userId, self::ALASAN_LOGOUT);
            if ($jumlah > 0) {
                $this->audit->log('notifikasi.perangkat_dicabut', 'perangkat_push', null, null, [
                    'lingkup' => 'logout_semua',
                    'jumlah' => $jumlah,
                ], $userId);
            }

            return $jumlah;
        } catch (\Throwable $exception) {
            error_log('Pencabutan perangkat saat logout gagal: ' . SafeError::fromThrowable($exception));

            return 0;
        }
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

    private function alasan(string $value): string
    {
        $value = strtolower(trim($value));

        return in_array($value, [
            self::ALASAN_LOGOUT,
            self::ALASAN_PENGGUNA,
            self::ALASAN_TOKEN_INVALID,
            self::ALASAN_DIHAPUS,
        ], true) ? $value : self::ALASAN_DIHAPUS;
    }

    private function optionalText(mixed $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        return mb_substr($text, 0, $max);
    }
}
