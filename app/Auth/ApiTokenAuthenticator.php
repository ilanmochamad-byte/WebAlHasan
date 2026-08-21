<?php

declare(strict_types=1);

namespace App\Auth;

use App\Api\ApiException;
use App\Http\Request;
use mysqli;

final class ApiTokenAuthenticator
{
    public function __construct(private mysqli $db, private TokenHasher $hasher)
    {
    }

    public function authenticate(): array
    {
        $plainToken = Request::bearerToken();
        if ($plainToken === null) {
            throw new ApiException('UNAUTHENTICATED', 'Token bearer diperlukan.', 401);
        }

        $hash = $this->hasher->hash($plainToken);
        $statement = $this->db->prepare(
            "SELECT t.id AS token_id, t.expires_at, u.id, u.name, u.username, u.guru_id,
                    u.pengurus_id, u.wali_id,
                    g.nip, g.nama_guru,
                    GROUP_CONCAT(DISTINCT r.slug ORDER BY r.slug SEPARATOR ',') roles
             FROM api_tokens t
             JOIN users u ON u.id = t.user_id AND u.is_active = 1
             LEFT JOIN guru g ON g.id = u.guru_id AND g.is_active = 1 AND g.archived_at IS NULL
             JOIN user_roles ur ON ur.user_id = u.id
             JOIN roles r ON r.id = ur.role_id
             WHERE t.token_hash = ? AND t.revoked_at IS NULL AND t.expires_at > NOW()
             GROUP BY t.id, u.id
             LIMIT 1"
        );
        if ($statement === false) {
            throw new ApiException('SERVER_ERROR', 'Layanan autentikasi belum siap.', 500);
        }
        $statement->bind_param('s', $hash);
        $statement->execute();
        $user = $statement->get_result()->fetch_assoc() ?: null;
        $statement->close();

        if ($user === null) {
            throw new ApiException('UNAUTHENTICATED', 'Sesi atau token tidak valid.', 401);
        }
        $roles = $user['roles'] ? explode(',', (string) $user['roles']) : [];
        $user['id'] = (int) $user['id'];
        $user['token_id'] = (int) $user['token_id'];
        $user['guru_id'] = $user['guru_id'] === null ? null : (int) $user['guru_id'];
        // V2 Fase 3 (aditif): relasi akun perizinan tersedia bagi lapisan layanan.
        $user['pengurus_id'] = ($user['pengurus_id'] ?? null) === null ? null : (int) $user['pengurus_id'];
        $user['wali_id'] = ($user['wali_id'] ?? null) === null ? null : (int) $user['wali_id'];
        $user['roles'] = $roles;

        if (in_array('guru', $roles, true) && !in_array('admin', $roles, true) && $user['guru_id'] === null) {
            throw new ApiException('UNAUTHENTICATED', 'Akun guru tidak lagi aktif atau terhubung.', 401);
        }

        $touch = $this->db->prepare('UPDATE api_tokens SET last_used_at = NOW() WHERE id = ?');
        if ($touch !== false) {
            $touch->bind_param('i', $user['token_id']);
            $touch->execute();
            $touch->close();
        }

        return $user;
    }

    public function requireRole(string $role): array
    {
        $user = $this->authenticate();
        if (!in_array($role, $user['roles'], true)) {
            throw new ApiException('FORBIDDEN', 'Akun tidak berhak mengakses sumber daya ini.', 403);
        }
        return $user;
    }

    public function requireScheduleAccess(): array
    {
        return $this->assertScheduleAccess($this->authenticate());
    }

    /**
     * Penjaga akses jadwal/laporan V1 untuk pengguna yang SUDAH diautentikasi.
     *
     * Dipisahkan pada V2 Fase 3 agar router dapat mengautentikasi satu kali lalu
     * menerapkan penjaga per endpoint: endpoint jadwal/laporan V1 tetap terbatas
     * pada admin dan guru (kontrak tidak berubah), sedangkan endpoint perizinan V2
     * memakai penjaga berbasis capability.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function assertScheduleAccess(array $user): array
    {
        if (!in_array('admin', $user['roles'], true) && !in_array('guru', $user['roles'], true)) {
            throw new ApiException('FORBIDDEN', 'Akun tidak memiliki akses jadwal.', 403);
        }

        return $user;
    }
}
