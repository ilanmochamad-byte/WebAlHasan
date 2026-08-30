<?php

declare(strict_types=1);

namespace App\Account;

use App\Audit\AuditLogger;
use App\Notification\DeviceRepository;
use InvalidArgumentException;
use RuntimeException;

/**
 * Aturan pengelolaan akun dan hak akses.
 *
 * **Koreksi ke-1 (30 Agustus 2026).** Perubahan role lama (`setRole`) diganti
 * penambahan/pencabutan role yang eksplisit:
 *
 *   - `grantRole()` menambahkan satu role tanpa menghapus role lain;
 *   - `revokeRole()` mencabut satu role tanpa menyentuh role lain;
 *   - role `guru`, `pengurus`, dan `orang_tua` menuntut relasi master yang
 *     valid dan aktif — penetapan tanpa relasi ditolak server;
 *   - pemberian role `admin` menuntut konfirmasi khusus yang diketik ulang;
 *   - admin terakhir dilindungi, termasuk pada permintaan bersamaan;
 *   - admin tidak dapat melepas hak adminnya sendiri atau menonaktifkan
 *     akunnya sendiri.
 *
 * Perubahan hak akses berlaku pada pemeriksaan server berikutnya: role dibaca
 * ulang dari basis data setiap request (`Authorization::currentUser()`) dan
 * kemampuan dihitung ulang (`Capabilities`), sehingga sesi lama tidak dapat
 * mempertahankan hak yang sudah dicabut.
 */
final class AccountService
{
    private const ALASAN_AKUN_NONAKTIF = 'akun_dinonaktifkan';

    /** Kalimat konfirmasi untuk pemberian role admin. */
    public const KONFIRMASI_ADMIN = 'BERI AKSES ADMIN';

    public function __construct(
        private AccountRepository $accounts,
        private AuditLogger $audit,
        private DeviceRepository $devices
    ) {
    }

    /**
     * @param array{q?:string, role?:string, status?:string} $filters
     * @return array{rows:array<int, array<string, mixed>>, total:int}
     */
    public function list(array $filters, int $page = 1, int $perPage = 20): array
    {
        return $this->accounts->paginate($filters, max(1, $page), max(10, min(100, $perPage)));
    }

    public function find(int $id): ?array
    {
        return $this->accounts->find($id);
    }

    public function availableTeachers(): array
    {
        return $this->accounts->availableTeachers();
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
        $id = $this->accounts->createTeacher($data, password_hash($temporaryPassword, PASSWORD_DEFAULT), $actorId,
            function (int $id) use ($data, $actorId): void {
                $this->auditRequired('account_created', $id, null, [
                    'name' => $data['name'], 'username' => $data['username'], 'guru_id' => $data['guru_id'], 'roles' => ['guru'],
                ], $actorId);
            });

        return ['id' => $id, 'temporary_password' => $temporaryPassword];
    }

    public function setActive(int $id, bool $active, int $actorId): void
    {
        if ($id === $actorId && !$active) {
            throw new InvalidArgumentException('Anda tidak dapat menonaktifkan akun sendiri. Minta admin lain melakukannya.');
        }
        $this->accounts->transaction(function () use ($id, $active, $actorId): void {
            // Kunci admin sebelum membaca nilai sebelum/sesudah dan menulis audit.
            $this->accounts->countActiveAdmins(true);
            $before = $this->required($id);
            if (!$this->accounts->setActive($id, $active)) {
                throw new RuntimeException('Status akun tidak berubah.');
            }
            if (!$active && $this->beraturAdmin($before) && $this->accounts->countActiveAdmins() < 1) {
                throw new InvalidArgumentException('Penonaktifan dibatalkan: ini akun admin aktif terakhir. Tetapkan admin lain terlebih dahulu.');
            }
            $perangkatDicabut = !$active ? $this->devices->revokeAllForUser($id, self::ALASAN_AKUN_NONAKTIF) : 0;
            $this->auditRequired('account_status_changed', $id,
                ['is_active' => (bool) $before['is_active']],
                ['is_active' => $active, 'perangkat_push_dicabut' => $perangkatDicabut], $actorId);
        });
    }

    /**
     * Menambahkan satu role. Role lain milik akun dipertahankan.
     *
     * @param array<string, mixed> $input Data formulir (untuk konfirmasi admin).
     */
    public function grantRole(int $id, string $role, int $actorId, array $input = []): void
    {
        $role = $this->role($role);
        $this->accounts->transaction(function () use ($id, $role, $actorId, $input): void {
            $this->accounts->countActiveAdmins(true);
            $before = $this->required($id);
            $rolesSebelum = $this->roles($before);

            if (in_array($role, $rolesSebelum, true)) {
                throw new InvalidArgumentException('Akun ini sudah memiliki role ' . $this->label($role) . '.');
            }
            if (!$before['is_active']) {
                throw new InvalidArgumentException('Aktifkan akun terlebih dahulu sebelum menambahkan hak akses.');
            }

            // Role yang menuntut relasi master yang valid dan aktif. Menetapkan role
            // tanpa hubungan data yang sah SELALU ditolak di server, bukan hanya
            // disembunyikan dari formulir.
            $this->requireMasterRelation($role, $before);

            if ($role === 'admin' && trim((string) ($input['konfirmasi_admin'] ?? '')) !== self::KONFIRMASI_ADMIN) {
                throw new InvalidArgumentException(
                    'Pemberian hak admin adalah tindakan khusus. Ketik ulang kalimat "' . self::KONFIRMASI_ADMIN
                    . '" pada kolom konfirmasi untuk melanjutkan.'
                );
            }

            $this->accounts->grantRole($id, $role, $actorId);
            $after = $this->required($id);
            $this->auditRequired(
                'account_role_granted',
                $id,
                ['roles' => $rolesSebelum],
                ['roles' => $this->roles($after), 'role_ditambahkan' => $role],
                $actorId
            );
        });
    }

    /**
     * Mencabut satu role. Role lain milik akun dipertahankan.
     */
    public function revokeRole(int $id, string $role, int $actorId): void
    {
        $role = $this->role($role);
        $this->accounts->transaction(function () use ($id, $role, $actorId): void {
            $this->accounts->countActiveAdmins(true);
            $before = $this->required($id);
            $rolesSebelum = $this->roles($before);
            if (!in_array($role, $rolesSebelum, true)) {
                throw new InvalidArgumentException('Akun ini tidak memiliki role ' . $this->label($role) . '.');
            }
            if ($role === 'admin' && $id === $actorId) {
                throw new InvalidArgumentException('Anda tidak dapat melepas hak admin dari akun sendiri. Minta admin lain melakukannya.');
            }
            $this->accounts->revokeRole($id, $role);
            if ($role === 'admin' && $this->accounts->countActiveAdmins() < 1) {
                throw new InvalidArgumentException('Pencabutan dibatalkan: ini admin aktif terakhir. Tetapkan admin lain terlebih dahulu.');
            }
            $after = $this->required($id);
            $this->auditRequired('account_role_revoked', $id,
                ['roles' => $rolesSebelum],
                ['roles' => $this->roles($after), 'role_dicabut' => $role], $actorId);
        });
    }

    public function resetPassword(int $id, int $actorId): string
    {
        if ($id === $actorId) {
            throw new InvalidArgumentException('Gunakan halaman Ganti Password untuk akun Anda sendiri.');
        }
        $temporaryPassword = $this->temporaryPassword();
        $this->accounts->transaction(function () use ($id, $actorId, $temporaryPassword): void {
            $this->required($id);
            if (!$this->accounts->resetPassword($id, password_hash($temporaryPassword, PASSWORD_DEFAULT))) {
                throw new RuntimeException('Password sementara gagal dibuat.');
            }
            $this->auditRequired('account_password_reset', $id, null, ['force_password_change' => true], $actorId);
        });
        return $temporaryPassword;
    }

    /**
     * Alasan mengapa sebuah role belum dapat diberikan kepada akun tertentu,
     * atau null bila sudah memenuhi syarat. Dipakai UI untuk menjelaskan
     * sebelum admin menekan tombol — pemeriksaan sebenarnya tetap di server.
     *
     * @param array<string, mixed> $account
     */
    public function blockerFor(string $role, array $account): ?string
    {
        try {
            $this->requireMasterRelation($this->role($role), $account);
        } catch (InvalidArgumentException $exception) {
            return $exception->getMessage();
        }

        return null;
    }

    /**
     * @param array<string, mixed> $account
     */
    private function requireMasterRelation(string $role, array $account): void
    {
        if ($role === 'guru') {
            if (empty($account['guru_id'])) {
                throw new InvalidArgumentException('Role Guru hanya dapat diberikan kepada akun yang terhubung dengan data guru.');
            }
            if ((int) ($account['guru_aktif'] ?? 0) !== 1 || !empty($account['guru_arsip'])) {
                throw new InvalidArgumentException('Data guru yang terhubung sedang nonaktif atau diarsipkan. Aktifkan data guru terlebih dahulu.');
            }
            return;
        }
        if ($role === 'pengurus') {
            if (empty($account['pengurus_id'])) {
                throw new InvalidArgumentException('Role Pengurus hanya dapat diberikan kepada akun yang terhubung dengan satu data pengurus.');
            }
            if ((int) ($account['pengurus_aktif'] ?? 0) !== 1 || !empty($account['pengurus_arsip'])) {
                throw new InvalidArgumentException('Data pengurus yang terhubung sedang nonaktif atau diarsipkan.');
            }
            return;
        }
        if ($role === 'orang_tua') {
            if (empty($account['wali_id'])) {
                throw new InvalidArgumentException('Role Orang Tua hanya dapat diberikan kepada akun yang terhubung dengan satu data wali.');
            }
            if ((int) ($account['wali_aktif'] ?? 0) !== 1 || !empty($account['wali_arsip'])) {
                throw new InvalidArgumentException('Data wali yang terhubung sedang nonaktif atau diarsipkan.');
            }
        }
    }

    /**
     * @param array<string, mixed> $account
     * @return array<int, string>
     */
    public function roles(array $account): array
    {
        $roles = (string) ($account['roles'] ?? '');

        return $roles === '' ? [] : explode(',', $roles);
    }

    public function label(string $role): string
    {
        return match ($role) {
            'admin' => 'Admin',
            'guru' => 'Guru',
            'pengurus' => 'Pengurus',
            'orang_tua' => 'Orang Tua',
            default => $role,
        };
    }

    /**
     * @param array<string, mixed> $account
     */
    private function beraturAdmin(array $account): bool
    {
        return in_array('admin', $this->roles($account), true);
    }

    private function role(string $role): string
    {
        if (!in_array($role, AccountRepository::ROLES, true)) {
            throw new InvalidArgumentException('Role tidak dikenal.');
        }

        return $role;
    }

    private function required(int $id): array
    {
        $account = $this->accounts->find($id);
        if ($account === null) {
            throw new InvalidArgumentException('Akun tidak ditemukan.');
        }
        return $account;
    }

    /** Audit merupakan bagian transaksi mutasi akun; kegagalannya wajib membatalkan mutasi. */
    private function auditRequired(string $action, int $id, ?array $before, array $after, int $actorId): void
    {
        if (!$this->audit->log($action, 'user', $id, $before, $after, $actorId)) {
            throw new RuntimeException('Perubahan akun dibatalkan karena audit tidak dapat disimpan. Silakan coba lagi.');
        }
    }

    private function temporaryPassword(): string
    {
        return 'Ah!' . bin2hex(random_bytes(6)) . random_int(10, 99);
    }
}
