<?php

declare(strict_types=1);

namespace App\Api;

use App\Audit\AuditLogger;
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
        private string $timezone
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
            && (in_array('admin', $user['roles'], true) || (
                in_array('guru', $user['roles'], true)
                && $user['guru_id'] !== null
                && $user['guru_is_active'] === true
                && $user['guru_archived_at'] === null
            ));

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
        return [
            'token' => $plainToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->format(DATE_ATOM),
            'profile' => $this->repository->publicProfile($user),
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
        ];
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
