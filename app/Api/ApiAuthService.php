<?php

declare(strict_types=1);

namespace App\Api;

use App\Audit\AuditLogger;
use App\Auth\Capabilities;
use App\Auth\TokenHasher;
use DateTimeImmutable;
use DateTimeZone;

final class ApiAuthService
{
    public function __construct(
        private ApiAuthRepository $repository,
        private TokenHasher $hasher,
        private AuditLogger $audit,
        private int $ttlDays,
        private string $timezone,
        private ?Capabilities $capabilities = null
    ) {
    }

    public function login(array $input): array
    {
        $username = trim((string) ($input['username'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $deviceName = $this->deviceName((string) ($input['device_name'] ?? 'mobile'));
        if ($username === '' || $password === '') {
            throw new ApiException('VALIDATION_FAILED', 'Username dan password wajib diisi.', 422, [
                'username' => $username === '' ? 'Username wajib diisi.' : null,
                'password' => $password === '' ? 'Password wajib diisi.' : null,
            ]);
        }

        $user = $this->repository->loginCandidate($username);
        $valid = $user !== null
            && $user['is_active']
            && password_verify($password, (string) $user['password'])
            && $this->hasUsableRole($user);

        if (!$valid) {
            $this->audit->log('api.login_failed', 'user', $user['id'] ?? null, null, [
                'username' => $username,
                'reason' => 'invalid_credentials_or_inactive',
            ]);
            throw new ApiException('INVALID_CREDENTIALS', 'Username atau password tidak valid.', 401);
        }
        if ($user['force_password_change']) {
            throw new ApiException('PASSWORD_CHANGE_REQUIRED', 'Password sementara harus diubah melalui website sebelum aplikasi dapat digunakan.', 403);
        }

        $plainToken = bin2hex(random_bytes(32));
        $token = $this->repository->createToken(
            (int) $user['id'],
            $this->hasher->hash($plainToken),
            $deviceName,
            max(1, min(365, $this->ttlDays))
        );
        $this->repository->touchLogin((int) $user['id']);
        $this->audit->log('api.login_succeeded', 'user', (int) $user['id'], null, [
            'roles' => $user['roles'],
            'device_name' => $deviceName,
            'expires_at' => $token['expires_at'],
        ], (int) $user['id']);

        $expiresAt = new DateTimeImmutable($token['expires_at'], new DateTimeZone($this->timezone));
        $profile = $this->repository->publicProfile($user);
        // V2 Fase 3: capability aktual ikut pada respons login agar aplikasi dapat
        // membangun navigasi tanpa menebak dari nama role (PRD 8 poin 11).
        $profile['capabilities'] = $this->capabilityPayload($user);

        return [
            'token' => $plainToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->format(DATE_ATOM),
            'profile' => $profile,
        ];
    }

    public function profile(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'name' => (string) $user['name'],
            'username' => (string) $user['username'],
            'guru' => $user['guru_id'] === null ? null : [
                'id' => (int) $user['guru_id'],
                'nip' => $user['nip'] === null ? null : (string) $user['nip'],
                'name' => (string) $user['nama_guru'],
            ],
            'roles' => array_values($user['roles']),
            // Aditif terhadap kontrak V1: field lama tidak berubah bentuk maupun makna.
            'capabilities' => $this->capabilityPayload($user),
        ];
    }

    /**
     * Kemampuan aktual pengguna, selalu dihitung ulang di server.
     *
     * Aplikasi guru V1 mengabaikan field ini; aplikasi V2 memakainya sebagai
     * satu-satunya sumber navigasi perizinan.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function capabilityPayload(array $user): array
    {
        $roles = array_values((array) ($user['roles'] ?? []));
        $userId = (int) $user['id'];

        if ($this->capabilities === null) {
            return [
                'list' => [],
                'default_mode' => null,
                'konteks' => ['guru_id' => null, 'pengurus_id' => null, 'wali_id' => null],
                'menus' => [],
                'aksi' => $this->actionFlags([]),
            ];
        }

        $probe = [
            'id' => $userId,
            'roles' => $roles,
            'guru_id' => ($user['guru_id'] ?? null) === null ? null : (int) $user['guru_id'],
        ];
        $this->capabilities->forget($userId);
        $list = $this->capabilities->forUser($probe);

        return [
            'list' => array_values($list),
            'default_mode' => $this->defaultMode($list),
            'konteks' => [
                'guru_id' => $probe['guru_id'],
                'pengurus_id' => in_array(Capabilities::PENGURUS, $list, true)
                    ? $this->capabilities->linkedPengurusId($userId)
                    : null,
                'wali_id' => in_array(Capabilities::ORANG_TUA, $list, true)
                    ? $this->capabilities->linkedWaliId($userId)
                    : null,
            ],
            'menus' => $this->menus($list, $roles),
            'aksi' => $this->actionFlags($list),
        ];
    }

    /**
     * @param array<int, string> $capabilities
     * @return array<int, array<string, mixed>>
     */
    private function menus(array $capabilities, array $roles): array
    {
        $menus = [];
        if (in_array('guru', $roles, true) || in_array('admin', $roles, true)) {
            $menus[] = ['key' => 'jadwal', 'label' => 'Jadwal', 'capability' => null];
            $menus[] = ['key' => 'laporan', 'label' => 'Laporan', 'capability' => null];
        }
        foreach ($capabilities as $capability) {
            $menus[] = match ($capability) {
                Capabilities::ADMIN => ['key' => 'izin_admin', 'label' => 'Perizinan — Admin', 'capability' => Capabilities::ADMIN],
                Capabilities::PENGURUS => ['key' => 'izin_pengurus', 'label' => 'Perizinan — Pengurus', 'capability' => Capabilities::PENGURUS],
                Capabilities::MUROBI => ['key' => 'izin_murobi', 'label' => 'Perizinan — Murobi', 'capability' => Capabilities::MUROBI],
                Capabilities::ORANG_TUA => ['key' => 'izin_orang_tua', 'label' => 'Izin Anak', 'capability' => Capabilities::ORANG_TUA],
                default => ['key' => 'izin', 'label' => 'Perizinan', 'capability' => $capability],
            };
        }

        return $menus;
    }

    /**
     * @param array<int, string> $capabilities
     * @return array<string, bool>
     */
    private function actionFlags(array $capabilities): array
    {
        $admin = in_array(Capabilities::ADMIN, $capabilities, true);

        return [
            'dapat_membuat_pengajuan' => $admin || in_array(Capabilities::PENGURUS, $capabilities, true),
            'dapat_memutuskan' => $admin || in_array(Capabilities::MUROBI, $capabilities, true),
            'dapat_menetapkan_murobi' => $admin,
            'dapat_mengoreksi_keputusan' => $admin,
            'dapat_membatalkan' => $admin || in_array(Capabilities::PENGURUS, $capabilities, true),
            'hanya_baca' => $capabilities === [Capabilities::ORANG_TUA],
        ];
    }

    /**
     * @param array<int, string> $capabilities
     */
    private function defaultMode(array $capabilities): ?string
    {
        foreach ([Capabilities::ADMIN, Capabilities::PENGURUS, Capabilities::MUROBI, Capabilities::ORANG_TUA] as $candidate) {
            if (in_array($candidate, $capabilities, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Kelayakan login API.
     *
     * V1: admin, atau guru dengan baris guru aktif (tidak diubah).
     * V2 Fase 3 (aditif): pengurus dengan baris pengurus aktif, dan orang tua
     * dengan baris wali aktif. Role tanpa relasi aktif tetap ditolak agar akun
     * tanpa cakupan tidak memperoleh token.
     *
     * @param array<string, mixed> $user
     */
    private function hasUsableRole(array $user): bool
    {
        $roles = (array) $user['roles'];

        if (in_array('admin', $roles, true)) {
            return true;
        }
        if (
            in_array('guru', $roles, true)
            && $user['guru_id'] !== null
            && $user['guru_is_active'] === true
            && $user['guru_archived_at'] === null
        ) {
            return true;
        }
        if (
            in_array('pengurus', $roles, true)
            && ($user['pengurus_id'] ?? null) !== null
            && ($user['pengurus_is_active'] ?? null) === true
            && ($user['pengurus_archived_at'] ?? null) === null
        ) {
            return true;
        }

        return in_array('orang_tua', $roles, true)
            && ($user['wali_id'] ?? null) !== null
            && ($user['wali_is_active'] ?? null) === true
            && ($user['wali_archived_at'] ?? null) === null;
    }

    public function logout(array $user): void
    {
        $this->repository->revokeToken((int) $user['token_id']);
        $this->audit->log('api.logout', 'api_token', (int) $user['token_id'], null, [
            'revoked' => true,
        ], (int) $user['id']);
    }

    private function deviceName(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';
        if ($value === '') {
            $value = 'mobile';
        }
        if (mb_strlen($value) > 100) {
            throw new ApiException('VALIDATION_FAILED', 'Nama perangkat maksimal 100 karakter.', 422, [
                'device_name' => 'Nama perangkat maksimal 100 karakter.',
            ]);
        }
        return $value;
    }
}
