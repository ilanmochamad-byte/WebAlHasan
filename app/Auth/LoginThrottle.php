<?php

declare(strict_types=1);

namespace App\Auth;

use mysqli;
use Throwable;

/**
 * Pembatasan percobaan masuk (paket perapihan V1–V2, koreksi ke-7).
 *
 * Menghitung percobaan gagal dari `audit_logs` yang SUDAH dicatat
 * `AuthService::attempt()`, sehingga tidak memerlukan tabel atau migrasi baru
 * dan tidak dapat dilewati hanya dengan membuang cookie sesi.
 *
 * Perilaku aman: bila penghitungan gagal (skema belum ada, basis data sibuk),
 * kelas ini MEMBUKA jalan, bukan mengunci semua orang. Pengaman utama tetap
 * hash password, CSRF, dan regenerasi sesi.
 */
final class LoginThrottle
{
    private const JENDELA_MENIT = 15;
    private const BATAS_USERNAME = 8;
    private const BATAS_IP = 20;

    public function __construct(private mysqli $db)
    {
    }

    /**
     * @return array{terkunci:bool, sisa_menit:int}
     */
    public function status(string $username, ?string $ip): array
    {
        $username = strtolower(trim($username));
        try {
            if ($username !== '' && $this->count('username', $username) >= self::BATAS_USERNAME) {
                return ['terkunci' => true, 'sisa_menit' => self::JENDELA_MENIT];
            }
            if ($ip !== null && $ip !== '' && $this->count('ip', $ip) >= self::BATAS_IP) {
                return ['terkunci' => true, 'sisa_menit' => self::JENDELA_MENIT];
            }
        } catch (Throwable $exception) {
            error_log('Pembatasan percobaan masuk dilewati: ' . $exception->getMessage());
        }

        return ['terkunci' => false, 'sisa_menit' => 0];
    }

    public function pesan(): string
    {
        return 'Terlalu banyak percobaan masuk yang gagal. Tunggu sekitar '
            . self::JENDELA_MENIT . ' menit lalu coba lagi, atau hubungi admin untuk mereset password.';
    }

    private function count(string $jenis, string $nilai): int
    {
        $sql = $jenis === 'ip'
            ? "SELECT COUNT(*) AS jumlah FROM audit_logs
                 WHERE action = 'login_failed'
                   AND ip_address = ?
                   AND created_at >= (NOW() - INTERVAL " . self::JENDELA_MENIT . ' MINUTE)'
            : "SELECT COUNT(*) AS jumlah FROM audit_logs
                 WHERE action = 'login_failed'
                   AND after_json LIKE ?
                   AND created_at >= (NOW() - INTERVAL " . self::JENDELA_MENIT . ' MINUTE)';

        $parameter = $jenis === 'ip'
            ? $nilai
            : '%"username":"' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $nilai) . '"%';

        $statement = $this->db->prepare($sql);
        if ($statement === false) {
            return 0;
        }
        $statement->bind_param('s', $parameter);
        if (!$statement->execute()) {
            $statement->close();
            return 0;
        }
        $row = $statement->get_result()?->fetch_assoc();
        $statement->close();

        return (int) ($row['jumlah'] ?? 0);
    }
}
