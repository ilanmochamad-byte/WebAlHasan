<?php

declare(strict_types=1);

namespace App\Account;

use App\Audit\AuditLogger;
use InvalidArgumentException;

/**
 * Pembuatan dan penghubungan akun pengurus serta orang tua (V2 Fase 1).
 *
 * Kontrak akun guru/admin V1 tidak diubah: kelas ini hanya menambah jalur baru dan
 * memakai AccountService lama untuk menonaktifkan akun serta mereset password.
 */
final class PerizinanAccountService
{
    public const KIND_PENGURUS = 'pengurus';
    public const KIND_ORANG_TUA = 'orang_tua';

    public function __construct(
        private PerizinanAccountRepository $repository,
        private AccountService $accounts,
        private AuditLogger $audit
    ) {
    }

    public function accounts(string $kind): array
    {
        return $this->repository->accounts($this->kind($kind));
    }

    public function availablePengurus(): array
    {
        return $this->repository->availablePengurus();
    }

    public function availableWali(): array
    {
        return $this->repository->availableWali();
    }

    public function unlinkedAccounts(): array
    {
        return $this->repository->unlinkedAccounts();
    }

    public function waliRelations(int $waliId): array
    {
        return $this->repository->waliRelations($waliId);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{id:int, temporary_password:string}
     */
    public function create(string $kind, array $input, int $actorId): array
    {
        $kind = $this->kind($kind);
        $data = $this->normalize($input);
        $data['pengurus_id'] = null;
        $data['wali_id'] = null;

        if ($kind === self::KIND_PENGURUS) {
            $data['pengurus_id'] = $this->requireAvailablePengurus((int) ($input['pengurus_id'] ?? 0));
        } else {
            $data['wali_id'] = $this->requireAvailableWali((int) ($input['wali_id'] ?? 0));
        }

        $temporaryPassword = $this->temporaryPassword();
        $id = $this->repository->createLinked($data, $kind, password_hash($temporaryPassword, PASSWORD_DEFAULT), $actorId);

        $this->audit->log('perizinan_account_created', 'user', $id, null, [
            'name' => $data['name'],
            'username' => $data['username'],
            'role' => $kind,
            'pengurus_id' => $data['pengurus_id'],
            'wali_id' => $data['wali_id'],
            'force_password_change' => true,
        ], $actorId);

        return ['id' => $id, 'temporary_password' => $temporaryPassword];
    }

    /**
     * Menghubungkan akun yang sudah ada ke satu master pengurus atau satu master wali.
     */
    public function link(string $kind, int $userId, int $masterId, int $actorId): void
    {
        $kind = $this->kind($kind);
        $user = $this->repository->findUser($userId);
        if ($user === null) {
            throw new InvalidArgumentException('Akun tidak ditemukan.');
        }
        if (!empty($user['guru_id'])) {
            throw new InvalidArgumentException('Akun guru tidak dapat dihubungkan sebagai pengurus atau orang tua.');
        }
        if (!empty($user['pengurus_id']) || !empty($user['wali_id'])) {
            throw new InvalidArgumentException('Akun ini sudah terhubung ke master pengurus atau wali lain.');
        }

        $pengurusId = null;
        $waliId = null;
        if ($kind === self::KIND_PENGURUS) {
            $pengurusId = $this->requireAvailablePengurus($masterId);
        } else {
            $waliId = $this->requireAvailableWali($masterId);
        }

        $this->repository->linkExisting($userId, $kind, $pengurusId, $waliId, $actorId);
        $this->audit->log('perizinan_account_linked', 'user', $userId, [
            'pengurus_id' => $user['pengurus_id'],
            'wali_id' => $user['wali_id'],
            'roles' => $user['roles'],
        ], [
            'pengurus_id' => $pengurusId,
            'wali_id' => $waliId,
            'role' => $kind,
        ], $actorId);
    }

    public function setActive(int $userId, bool $active, int $actorId): void
    {
        $this->accounts->setActive($userId, $active, $actorId);
    }

    public function resetPassword(int $userId, int $actorId): string
    {
        return $this->accounts->resetPassword($userId, $actorId);
    }

    private function requireAvailablePengurus(int $id): int
    {
        foreach ($this->repository->availablePengurus() as $row) {
            if ((int) $row['id'] === $id) {
                return $id;
            }
        }

        throw new InvalidArgumentException('Pengurus tidak ditemukan, sudah tidak aktif, atau sudah memiliki akun.');
    }

    private function requireAvailableWali(int $id): int
    {
        foreach ($this->repository->availableWali() as $row) {
            if ((int) $row['id'] === $id) {
                return $id;
            }
        }

        throw new InvalidArgumentException('Wali tidak ditemukan, tidak aktif, belum memiliki relasi santri aktif, atau sudah memiliki akun.');
    }

    private function kind(string $kind): string
    {
        if (!in_array($kind, [self::KIND_PENGURUS, self::KIND_ORANG_TUA], true)) {
            throw new InvalidArgumentException('Jenis akun perizinan tidak dikenal.');
        }

        return $kind;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function normalize(array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $username = strtolower(trim((string) ($input['username'] ?? '')));
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $phone = (string) preg_replace('/[^0-9+]/', '', trim((string) ($input['phone'] ?? '')));

        if ($name === '') {
            throw new InvalidArgumentException('Nama akun wajib diisi.');
        }
        if (!preg_match('/^[a-z0-9._-]{4,50}$/', $username)) {
            throw new InvalidArgumentException('Username harus 4–50 karakter dan hanya berisi huruf kecil, angka, titik, garis bawah, atau tanda hubung.');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Format email tidak valid.');
        }
        if (str_starts_with($phone, '+62')) {
            $phone = '0' . substr($phone, 3);
        } elseif (str_starts_with($phone, '62')) {
            $phone = '0' . substr($phone, 2);
        }
        if ($phone !== '' && !preg_match('/^0[0-9]{8,15}$/', $phone)) {
            throw new InvalidArgumentException('Nomor HP harus berupa 9–16 digit dan diawali 0.');
        }

        return [
            'name' => $name,
            'username' => $username,
            'email' => $email === '' ? null : $email,
            'phone' => $phone === '' ? null : $phone,
        ];
    }

    private function temporaryPassword(): string
    {
        return 'Ah!' . bin2hex(random_bytes(6)) . random_int(10, 99);
    }
}
