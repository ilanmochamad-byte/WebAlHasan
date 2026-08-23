<?php

declare(strict_types=1);

namespace App\Notification;

use mysqli;
use RuntimeException;

/**
 * Baca/tulis pengaturan kanal notifikasi (baris tunggal `pengaturan_notifikasi`).
 *
 * Tabel ini SENGAJA tidak memiliki kolom untuk credential apa pun. Secret
 * penyedia WhatsApp dan kunci push berada di environment server; yang disimpan
 * di sini hanyalah sakelar dan HASIL pemeriksaan konfigurasi (PRD 5.4).
 */
final class SettingsRepository
{
    public function __construct(private mysqli $db)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function current(): array
    {
        $result = $this->db->query(
            'SELECT id, inapp_enabled, push_enabled, push_check_status, push_check_pesan, push_check_pada,
                    whatsapp_enabled, whatsapp_provider, whatsapp_check_status, whatsapp_check_pesan,
                    whatsapp_check_pada, whatsapp_check_oleh_user_id, updated_by, updated_at
               FROM pengaturan_notifikasi
              WHERE singleton = 1
              LIMIT 1'
        );
        $row = $result === false ? null : $result->fetch_assoc();
        if (!is_array($row)) {
            throw new RuntimeException('Baris pengaturan notifikasi tidak ditemukan. Jalankan migrasi 006 dan 008.');
        }

        return [
            'id' => (int) $row['id'],
            // In-app tidak dapat dimatikan (CHECK constraint migrasi 008).
            'inapp_enabled' => true,
            'push_enabled' => (int) $row['push_enabled'] === 1,
            'push_check_status' => (string) $row['push_check_status'],
            'push_check_pesan' => $row['push_check_pesan'] === null ? null : (string) $row['push_check_pesan'],
            'push_check_pada' => $row['push_check_pada'] === null ? null : (string) $row['push_check_pada'],
            'whatsapp_enabled' => (int) $row['whatsapp_enabled'] === 1,
            'whatsapp_provider' => $row['whatsapp_provider'] === null ? null : (string) $row['whatsapp_provider'],
            'whatsapp_check_status' => (string) $row['whatsapp_check_status'],
            'whatsapp_check_pesan' => $row['whatsapp_check_pesan'] === null ? null : (string) $row['whatsapp_check_pesan'],
            'whatsapp_check_pada' => $row['whatsapp_check_pada'] === null ? null : (string) $row['whatsapp_check_pada'],
            'whatsapp_check_oleh_user_id' => $row['whatsapp_check_oleh_user_id'] === null
                ? null
                : (int) $row['whatsapp_check_oleh_user_id'],
            'updated_by' => $row['updated_by'] === null ? null : (int) $row['updated_by'],
            'updated_at' => $row['updated_at'] === null ? null : (string) $row['updated_at'],
        ];
    }

    public function setPushEnabled(bool $enabled, int $actorUserId): bool
    {
        return $this->exec(
            'UPDATE pengaturan_notifikasi SET push_enabled = ?, updated_by = ? WHERE singleton = 1',
            'ii',
            [$enabled ? 1 : 0, $actorUserId]
        );
    }

    /**
     * Mengaktifkan WhatsApp hanya boleh terjadi ketika pemeriksaan konfigurasi
     * terakhir berstatus `Lulus`. Kondisi itu ditegakkan pada klausa WHERE,
     * BUKAN hanya di lapisan layanan, dan diperkuat CHECK constraint pada
     * migrasi 006. Dua lapis ini membuat sakelar tidak dapat dinyalakan lewat
     * jalur mana pun ketika konfigurasi gagal.
     */
    public function setWhatsappEnabled(bool $enabled, int $actorUserId, ?string $provider = null): bool
    {
        if (!$enabled) {
            return $this->exec(
                'UPDATE pengaturan_notifikasi SET whatsapp_enabled = 0, updated_by = ? WHERE singleton = 1',
                'i',
                [$actorUserId]
            );
        }

        $provider = trim((string) $provider);
        if ($provider === '') {
            return false;
        }

        $statement = $this->db->prepare(
            "UPDATE pengaturan_notifikasi
                SET whatsapp_enabled = 1, updated_by = ?
              WHERE singleton = 1
                AND whatsapp_check_status = 'Lulus'
                AND whatsapp_provider = ?"
        );
        if ($statement === false) {
            return false;
        }
        $statement->bind_param('is', $actorUserId, $provider);
        $ok = $statement->execute();
        $affected = $statement->affected_rows;
        $statement->close();

        if (!$ok) {
            return false;
        }

        // affected_rows = 0 berarti baris tidak memenuhi syarat `Lulus`
        // (atau nilainya memang sudah 1). Pemanggil membaca ulang keadaan.
        return $this->current()['whatsapp_enabled'];
    }

    public function recordWhatsappCheck(string $status, string $safeMessage, ?string $provider, int $actorUserId): bool
    {
        if (!in_array($status, ['Belum Diperiksa', 'Lulus', 'Gagal'], true)) {
            throw new RuntimeException('Status pemeriksaan WhatsApp tidak dikenal.');
        }

        // Pemeriksaan yang GAGAL langsung mematikan sakelar: sistem tidak boleh
        // meninggalkan WhatsApp menyala dengan konfigurasi yang sudah rusak.
        $sql = $status === 'Lulus'
            ? 'UPDATE pengaturan_notifikasi
                  SET whatsapp_check_status = ?, whatsapp_check_pesan = ?, whatsapp_check_pada = NOW(),
                      whatsapp_check_oleh_user_id = ?, whatsapp_provider = ?, updated_by = ?
                WHERE singleton = 1'
            : 'UPDATE pengaturan_notifikasi
                  SET whatsapp_check_status = ?, whatsapp_check_pesan = ?, whatsapp_check_pada = NOW(),
                      whatsapp_check_oleh_user_id = ?, whatsapp_provider = ?, updated_by = ?, whatsapp_enabled = 0
                WHERE singleton = 1';

        $statement = $this->db->prepare($sql);
        if ($statement === false) {
            return false;
        }
        $message = SafeError::message($safeMessage, 'Tidak ada detail.');
        $statement->bind_param('ssisi', $status, $message, $actorUserId, $provider, $actorUserId);
        $ok = $statement->execute();
        $statement->close();

        return $ok;
    }

    public function recordPushCheck(string $status, string $safeMessage, int $actorUserId): bool
    {
        if (!in_array($status, ['Belum Diperiksa', 'Lulus', 'Gagal'], true)) {
            throw new RuntimeException('Status pemeriksaan push tidak dikenal.');
        }
        $message = SafeError::message($safeMessage, 'Tidak ada detail.');

        return $this->exec(
            'UPDATE pengaturan_notifikasi
                SET push_check_status = ?, push_check_pesan = ?, push_check_pada = NOW(), updated_by = ?
              WHERE singleton = 1',
            'ssi',
            [$status, $message, $actorUserId]
        );
    }

    /**
     * Audit khusus kanal notifikasi. Tidak pernah menerima credential: pemanggil
     * hanya boleh mengirim nilai sakelar, status, dan pesan yang sudah aman.
     */
    public function audit(
        string $aksi,
        ?string $kanal,
        ?string $sebelum,
        ?string $sesudah,
        ?string $hasil,
        ?string $pesan,
        ?int $actorUserId,
        ?string $ip,
        ?string $userAgent
    ): bool {
        $statement = $this->db->prepare(
            'INSERT INTO notifikasi_pengaturan_audit
                 (aksi, kanal, nilai_sebelum, nilai_sesudah, hasil, pesan, aktor_user_id, ip_address, user_agent, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        if ($statement === false) {
            return false;
        }
        $pesanAman = $pesan === null ? null : SafeError::message($pesan, 'Tidak ada detail.');
        $ipAman = $ip === null ? null : substr($ip, 0, 45);
        $uaAman = $userAgent === null ? null : substr($userAgent, 0, 255);
        $statement->bind_param('ssssssiss', $aksi, $kanal, $sebelum, $sesudah, $hasil, $pesanAman, $actorUserId, $ipAman, $uaAman);
        $ok = $statement->execute();
        $statement->close();

        return $ok;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function auditTrail(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $result = $this->db->query(
            'SELECT a.id, a.aksi, a.kanal, a.nilai_sebelum, a.nilai_sesudah, a.hasil, a.pesan,
                    a.aktor_user_id, a.created_at, u.name AS aktor_nama
               FROM notifikasi_pengaturan_audit a
               LEFT JOIN users u ON u.id = a.aktor_user_id
              ORDER BY a.id DESC
              LIMIT ' . $limit
        );
        $rows = [];
        while ($result !== false && ($row = $result->fetch_assoc()) !== null) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param array<int, mixed> $params
     */
    private function exec(string $sql, string $types, array $params): bool
    {
        $statement = $this->db->prepare($sql);
        if ($statement === false) {
            return false;
        }
        $statement->bind_param($types, ...$params);
        $ok = $statement->execute();
        $statement->close();

        return $ok;
    }
}
