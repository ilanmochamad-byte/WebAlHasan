<?php

declare(strict_types=1);

namespace App\Notification;

use mysqli;

/**
 * Perangkat push per pengguna (`perangkat_push`).
 *
 * Token asli tidak pernah tersimpan dalam bentuk terbaca: hanya `token_hash`
 * (HMAC) dan `token_terlindungi` (sandi AES-GCM). Tidak ada satu pun method di
 * sini yang mengembalikan token terbaca ke lapisan HTTP — hanya
 * `activeTokensFor()`, yang khusus dipakai worker pengirim, mengembalikan
 * bentuk terlindungi untuk dibuka di memori proses.
 */
final class DeviceRepository
{
    public function __construct(private mysqli $db)
    {
    }

    /**
     * Mendaftarkan atau memperbarui satu perangkat.
     *
     * Idempoten pada dua sumbu:
     *   - token yang sama didaftarkan ulang -> baris yang sama dihidupkan lagi;
     *   - perangkat yang sama (device_id) mengirim token baru -> baris lama
     *     diperbarui, bukan menumpuk baris baru.
     *
     * @param array<string, mixed> $data
     * @return array{id:int, baru:bool}
     */
    public function register(array $data): array
    {
        $userId = (int) $data['user_id'];
        $tokenHash = (string) $data['token_hash'];
        $protected = (string) $data['token_terlindungi'];
        $platform = (string) $data['platform'];
        $deviceId = ($data['device_id'] ?? null) === null ? null : substr((string) $data['device_id'], 0, 100);
        $label = ($data['device_label'] ?? null) === null ? null : mb_substr((string) $data['device_label'], 0, 100);
        $appVersion = ($data['app_version'] ?? null) === null ? null : substr((string) $data['app_version'], 0, 30);

        // 1. Token yang sudah dikenal: hidupkan kembali dan pindahkan ke akun
        //    yang sedang masuk (perangkat dapat berpindah pengguna).
        $existing = $this->findByHash($tokenHash);
        if ($existing !== null) {
            $statement = $this->db->prepare(
                'UPDATE perangkat_push
                    SET user_id = ?, token_terlindungi = ?, platform = ?, device_id = ?, device_label = ?,
                        app_version = ?, push_aktif = 1, dicabut_pada = NULL, alasan_pencabutan = NULL,
                        gagal_berturut = 0, terakhir_aktif_pada = NOW()
                  WHERE id = ?'
            );
            if ($statement !== false) {
                $id = (int) $existing['id'];
                $statement->bind_param('isssssi', $userId, $protected, $platform, $deviceId, $label, $appVersion, $id);
                $statement->execute();
                $statement->close();
            }

            return ['id' => (int) $existing['id'], 'baru' => false];
        }

        // 2. Perangkat yang sudah dikenal dengan token baru: ganti tokennya.
        if ($deviceId !== null) {
            $sameDevice = $this->findByDevice($userId, $deviceId);
            if ($sameDevice !== null) {
                $statement = $this->db->prepare(
                    'UPDATE perangkat_push
                        SET token_hash = ?, token_terlindungi = ?, platform = ?, device_label = ?,
                            app_version = ?, push_aktif = 1, dicabut_pada = NULL, alasan_pencabutan = NULL,
                            gagal_berturut = 0, terakhir_aktif_pada = NOW()
                      WHERE id = ?'
                );
                if ($statement !== false) {
                    $id = (int) $sameDevice['id'];
                    $statement->bind_param('sssssi', $tokenHash, $protected, $platform, $label, $appVersion, $id);
                    $statement->execute();
                    $statement->close();
                }

                return ['id' => (int) $sameDevice['id'], 'baru' => false];
            }
        }

        // 3. Perangkat baru.
        $statement = $this->db->prepare(
            'INSERT INTO perangkat_push
                 (user_id, token_hash, token_terlindungi, platform, device_id, device_label, app_version,
                  push_aktif, terakhir_aktif_pada, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW(), NOW())'
        );
        if ($statement === false) {
            throw NotificationException::unavailable('Perangkat push tidak dapat disimpan.');
        }
        // Sandi biner diikat langsung sebagai parameter string: protokol
        // prepared statement mengirim panjangnya secara eksplisit sehingga byte
        // apa pun tersimpan utuh pada kolom VARBINARY.
        $statement->bind_param('issssss', $userId, $tokenHash, $protected, $platform, $deviceId, $label, $appVersion);
        if (!$statement->execute()) {
            $statement->close();
            throw NotificationException::unavailable('Perangkat push tidak dapat disimpan.');
        }
        $id = (int) $statement->insert_id;
        $statement->close();

        return ['id' => $id, 'baru' => true];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByHash(string $tokenHash): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, user_id, platform, device_id, dicabut_pada FROM perangkat_push WHERE token_hash = ? LIMIT 1'
        );
        if ($statement === false) {
            return null;
        }
        $statement->bind_param('s', $tokenHash);
        $statement->execute();
        $row = $statement->get_result()?->fetch_assoc();
        $statement->close();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByDevice(int $userId, string $deviceId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, user_id, platform FROM perangkat_push WHERE user_id = ? AND device_id = ? LIMIT 1'
        );
        if ($statement === false) {
            return null;
        }
        $statement->bind_param('is', $userId, $deviceId);
        $statement->execute();
        $row = $statement->get_result()?->fetch_assoc();
        $statement->close();

        return is_array($row) ? $row : null;
    }

    /**
     * Daftar perangkat milik SATU pengguna. Tidak pernah mengembalikan token —
     * baik terbaca maupun terlindungi.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForUser(int $userId): array
    {
        $statement = $this->db->prepare(
            'SELECT id, platform, device_id, device_label, app_version, push_aktif,
                    terakhir_aktif_pada, dicabut_pada, alasan_pencabutan, gagal_berturut, created_at
               FROM perangkat_push
              WHERE user_id = ?
              ORDER BY (dicabut_pada IS NULL) DESC, id DESC'
        );
        if ($statement === false) {
            return [];
        }
        $statement->bind_param('i', $userId);
        $statement->execute();
        $result = $statement->get_result();
        $rows = [];
        while ($result !== false && ($row = $result->fetch_assoc()) !== null) {
            $rows[] = $row;
        }
        $statement->close();

        return $rows;
    }

    /**
     * Mencabut satu perangkat milik pengguna tertentu.
     *
     * Selalu dibatasi `user_id` sehingga mengganti ID pada request tidak dapat
     * mencabut perangkat orang lain.
     */
    public function revoke(int $deviceId, int $userId, string $alasan): bool
    {
        $statement = $this->db->prepare(
            'UPDATE perangkat_push
                SET dicabut_pada = NOW(), alasan_pencabutan = ?, push_aktif = 0
              WHERE id = ? AND user_id = ? AND dicabut_pada IS NULL'
        );
        if ($statement === false) {
            return false;
        }
        $alasanPendek = substr($alasan, 0, 40);
        $statement->bind_param('sii', $alasanPendek, $deviceId, $userId);
        $ok = $statement->execute();
        $affected = $statement->affected_rows;
        $statement->close();

        return $ok && $affected > 0;
    }

    /**
     * Mencabut satu perangkat berdasarkan token (dipakai saat aplikasi logout
     * dan hanya memegang tokennya sendiri).
     */
    public function revokeByHash(string $tokenHash, int $userId, string $alasan): bool
    {
        $statement = $this->db->prepare(
            'UPDATE perangkat_push
                SET dicabut_pada = NOW(), alasan_pencabutan = ?, push_aktif = 0
              WHERE token_hash = ? AND user_id = ?'
        );
        if ($statement === false) {
            return false;
        }
        $alasanPendek = substr($alasan, 0, 40);
        $statement->bind_param('ssi', $alasanPendek, $tokenHash, $userId);
        $ok = $statement->execute();
        $affected = $statement->affected_rows;
        $statement->close();

        return $ok && $affected > 0;
    }

    /**
     * Mencabut SELURUH perangkat milik satu pengguna (logout tanpa token, atau
     * penonaktifan akun).
     */
    public function revokeAllForUser(int $userId, string $alasan): int
    {
        $statement = $this->db->prepare(
            'UPDATE perangkat_push
                SET dicabut_pada = NOW(), alasan_pencabutan = ?, push_aktif = 0
              WHERE user_id = ? AND dicabut_pada IS NULL'
        );
        if ($statement === false) {
            return 0;
        }
        $alasanPendek = substr($alasan, 0, 40);
        $statement->bind_param('si', $alasanPendek, $userId);
        $statement->execute();
        $affected = $statement->affected_rows;
        $statement->close();

        return max(0, $affected);
    }

    /**
     * Mencabut token yang ditolak penyedia (mis. `DeviceNotRegistered`).
     * Tidak memerlukan user_id karena pemanggilnya adalah worker.
     */
    public function revokeInvalidToken(string $tokenHash): bool
    {
        $statement = $this->db->prepare(
            "UPDATE perangkat_push
                SET dicabut_pada = NOW(), alasan_pencabutan = 'token_invalid', push_aktif = 0
              WHERE token_hash = ? AND dicabut_pada IS NULL"
        );
        if ($statement === false) {
            return false;
        }
        $statement->bind_param('s', $tokenHash);
        $ok = $statement->execute();
        $affected = $statement->affected_rows;
        $statement->close();

        return $ok && $affected > 0;
    }

    /**
     * Menyalakan/mematikan push pada satu perangkat tanpa mencabut registrasi.
     */
    public function setPushEnabled(int $deviceId, int $userId, bool $enabled): bool
    {
        $statement = $this->db->prepare(
            'UPDATE perangkat_push SET push_aktif = ? WHERE id = ? AND user_id = ? AND dicabut_pada IS NULL'
        );
        if ($statement === false) {
            return false;
        }
        $flag = $enabled ? 1 : 0;
        $statement->bind_param('iii', $flag, $deviceId, $userId);
        $ok = $statement->execute();
        $statement->close();

        return $ok;
    }

    /**
     * Pengguna (dari daftar yang diberikan) yang memiliki minimal satu perangkat
     * aktif. Dipakai `NotificationService` agar push hanya diantrekan untuk
     * penerima yang benar-benar dapat dijangkau.
     *
     * @param array<int, int> $userIds
     * @return array<int, int>
     */
    public function usersWithActiveDevice(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $statement = $this->db->prepare(
            'SELECT DISTINCT user_id
               FROM perangkat_push
              WHERE user_id IN (' . $placeholders . ')
                AND dicabut_pada IS NULL
                AND push_aktif = 1'
        );
        if ($statement === false) {
            return [];
        }
        $statement->bind_param(str_repeat('i', count($userIds)), ...$userIds);
        $statement->execute();
        $result = $statement->get_result();
        $ids = [];
        while ($result !== false && ($row = $result->fetch_assoc()) !== null) {
            $ids[] = (int) $row['user_id'];
        }
        $statement->close();

        return $ids;
    }

    /**
     * Perangkat aktif satu pengguna, beserta token TERLINDUNGI.
     *
     * HANYA worker pengirim push yang boleh memanggil ini. Nilai
     * `token_terlindungi` masih tersandi; pemanggil membukanya lewat
     * `PushTokenProtector::reveal()` dan tidak boleh menuliskannya ke mana pun.
     *
     * @return array<int, array<string, mixed>>
     */
    public function activeTokensFor(int $userId): array
    {
        $statement = $this->db->prepare(
            'SELECT id, token_hash, token_terlindungi, platform, device_label
               FROM perangkat_push
              WHERE user_id = ? AND dicabut_pada IS NULL AND push_aktif = 1
              ORDER BY id'
        );
        if ($statement === false) {
            return [];
        }
        $statement->bind_param('i', $userId);
        $statement->execute();
        $result = $statement->get_result();
        $rows = [];
        while ($result !== false && ($row = $result->fetch_assoc()) !== null) {
            $rows[] = $row;
        }
        $statement->close();

        return $rows;
    }

    public function touch(int $deviceId): void
    {
        $statement = $this->db->prepare(
            'UPDATE perangkat_push SET terakhir_aktif_pada = NOW(), gagal_berturut = 0 WHERE id = ?'
        );
        if ($statement !== false) {
            $statement->bind_param('i', $deviceId);
            $statement->execute();
            $statement->close();
        }
    }

    public function noteFailure(int $deviceId): void
    {
        $statement = $this->db->prepare(
            'UPDATE perangkat_push SET gagal_berturut = LEAST(gagal_berturut + 1, 65000) WHERE id = ?'
        );
        if ($statement !== false) {
            $statement->bind_param('i', $deviceId);
            $statement->execute();
            $statement->close();
        }
    }

    /**
     * @return array{total:int, aktif:int, dicabut:int}
     */
    public function counters(): array
    {
        $result = $this->db->query(
            'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN dicabut_pada IS NULL AND push_aktif = 1 THEN 1 ELSE 0 END) AS aktif,
                    SUM(CASE WHEN dicabut_pada IS NOT NULL THEN 1 ELSE 0 END) AS dicabut
               FROM perangkat_push'
        );
        $row = $result === false ? null : $result->fetch_assoc();

        return [
            'total' => (int) ($row['total'] ?? 0),
            'aktif' => (int) ($row['aktif'] ?? 0),
            'dicabut' => (int) ($row['dicabut'] ?? 0),
        ];
    }
}
