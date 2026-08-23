<?php

declare(strict_types=1);

namespace App\Account;

use App\Audit\AuditLogger;
use App\Notification\DeviceRepository;
use InvalidArgumentException;
use RuntimeException;

final class AccountService
{
    private const ALASAN_AKUN_NONAKTIF = 'akun_dinonaktifkan';

    public function __construct(
        private AccountRepository $accounts,
        private AuditLogger $audit,
        private DeviceRepository $devices
    ) {
    }

    public function createTeacher(array $input, int $actorId): array
    {
        $data = [
            'guru_id' => (int) ($input['guru_id'] ?? 0),
            'name' => trim((string) ($input['name'] ?? '')),
            'username' => strtolower(trim((string) ($input['username'] ?? ''))),
            'email' => strtolower(trim((string) ($input['email'] ?? ''))),
            'phone' => preg_replace('/[^0-9+]/', '', trim((string) ($input['phone'] ?? ''))),
        ];
        if ($data['guru_id'] < 1 || $data['name'] === '') {
            throw new InvalidArgumentException('Guru dan nama akun wajib diisi.');
        }
        if (!preg_match('/^[a-z0-9._-]{4,50}$/', $data['username'])) {
            throw new InvalidArgumentException('Username harus 4–50 karakter dan hanya berisi huruf kecil, angka, titik, garis bawah, atau tanda hubung.');
        }
        if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Format email tidak valid.');
        }
        if (str_starts_with($data['phone'], '+62')) {
            $data['phone'] = '0' . substr($data['phone'], 3);
        } elseif (str_starts_with($data['phone'], '62')) {
            $data['phone'] = '0' . substr($data['phone'], 2);
        }
        if ($data['phone'] !== '' && !preg_match('/^0[0-9]{8,15}$/', $data['phone'])) {
            throw new InvalidArgumentException('Nomor HP harus berupa 9–16 digit dan diawali 0.');
        }
        $data['email'] = $data['email'] === '' ? null : $data['email'];
        $data['phone'] = $data['phone'] === '' ? null : $data['phone'];

        $temporaryPassword = $this->temporaryPassword();
        $id = $this->accounts->createTeacher($data, password_hash($temporaryPassword, PASSWORD_DEFAULT), $actorId);
        $this->audit->log('account_created', 'user', $id, null, [
            'name' => $data['name'], 'username' => $data['username'], 'guru_id' => $data['guru_id'], 'roles' => ['guru'],
        ], $actorId);

        return ['id' => $id, 'temporary_password' => $temporaryPassword];
    }

    public function setActive(int $id, bool $active, int $actorId): void
    {
        if ($id === $actorId && !$active) {
            throw new InvalidArgumentException('Anda tidak dapat menonaktifkan akun sendiri.');
        }
        $before = $this->required($id);
        if (!$this->accounts->setActive($id, $active)) {
            throw new RuntimeException('Status akun tidak berubah.');
        }
        $perangkatDicabut = !$active
            ? $this->devices->revokeAllForUser($id, self::ALASAN_AKUN_NONAKTIF)
            : 0;
        $this->audit->log(
            'account_status_changed',
            'user',
            $id,
            ['is_active' => (bool) $before['is_active']],
            ['is_active' => $active, 'perangkat_push_dicabut' => $perangkatDicabut],
            $actorId
        );
    }

    public function setRole(int $id, string $role, int $actorId): void
    {
        if (!in_array($role, ['admin', 'guru'], true)) {
            throw new InvalidArgumentException('Role tidak valid.');
        }
        if ($id === $actorId && $role !== 'admin') {
            throw new InvalidArgumentException('Anda tidak dapat melepas role admin dari akun sendiri.');
        }
        $before = $this->required($id);
        if ($role === 'guru' && empty($before['guru_id'])) {
            throw new InvalidArgumentException('Role guru hanya dapat diberikan kepada akun yang terhubung dengan data guru.');
        }
        $this->accounts->setRole($id, $role, $actorId);
        $this->audit->log('account_role_changed', 'user', $id, ['roles' => $before['roles']], ['roles' => $role], $actorId);
    }

    public function resetPassword(int $id, int $actorId): string
    {
        if ($id === $actorId) {
            throw new InvalidArgumentException('Gunakan halaman Ganti Password untuk akun Anda sendiri.');
        }
        $this->required($id);
        $temporaryPassword = $this->temporaryPassword();
        if (!$this->accounts->resetPassword($id, password_hash($temporaryPassword, PASSWORD_DEFAULT))) {
            throw new RuntimeException('Password sementara gagal dibuat.');
        }
        $this->audit->log('account_password_reset', 'user', $id, null, ['force_password_change' => true], $actorId);
        return $temporaryPassword;
    }

    private function required(int $id): array
    {
        $account = $this->accounts->find($id);
        if ($account === null) {
            throw new InvalidArgumentException('Akun tidak ditemukan.');
        }
        return $account;
    }

    private function temporaryPassword(): string
    {
        return 'Ah!' . bin2hex(random_bytes(6)) . random_int(10, 99);
    }
}
