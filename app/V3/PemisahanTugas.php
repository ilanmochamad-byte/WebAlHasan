<?php

declare(strict_types=1);

namespace App\V3;

use mysqli;

/**
 * Pemisahan tugas pembinaan per AKUN (penugasan lintas guru/pengurus Fase 2).
 *
 * Murobi tidak menandai "mengetahui" catatan yang ia buat atau miliki sendiri
 * sebagai pembimbing — aturan yang sama dengan sikap V4 (keputusan Human
 * Developer PK-T7: tertahan, tanpa jalur pengganti). Catatan dianggap milik
 * akun bila akun itu:
 *
 *   - membuat atau memperbarui baris sumber (`created_by`/`updated_by`),
 *     termasuk seluruh revisi pelanggaran/sesi sebelumnya dan revisi kasus; atau
 *   - memegang identitas pengurus yang tercatat sebagai `pembimbing_id`
 *     sumber (identitas pengurus milik guru dari Fase 1 maupun akun ganda
 *     warisan).
 *
 * Sesi konseling juga milik pemilik kasusnya. Kelas ini hanya membaca dan
 * dipanggil sesudah baris sumber dikunci.
 */
final class PemisahanTugas
{
    public function __construct(private mysqli $db)
    {
    }

    public function pelanggaranMilik(int $userId, int $pelanggaranId): bool
    {
        return $this->rantaiMilik('v3_pelanggaran', $pelanggaranId, $userId);
    }

    public function kasusMilik(int $userId, int $kasusId): bool
    {
        $row = $this->one('SELECT created_by, updated_by, pembimbing_id FROM v3_konseling_kasus WHERE id = ?', [$kasusId]);
        if ($row === null) {
            return false;
        }
        if ($this->cocok($row, $userId, $this->pengurusAkun($userId))) {
            return true;
        }

        return $this->one('SELECT 1 AS ada FROM v3_konseling_kasus_revisi WHERE kasus_id = ? AND created_by = ? LIMIT 1', [$kasusId, $userId]) !== null;
    }

    public function sesiMilik(int $userId, int $sesiId): bool
    {
        $row = $this->one('SELECT kasus_id FROM v3_konseling_sesi WHERE id = ?', [$sesiId]);
        if ($row === null) {
            return false;
        }

        return $this->rantaiMilik('v3_konseling_sesi', $sesiId, $userId) || $this->kasusMilik($userId, (int) $row['kasus_id']);
    }

    /** Seluruh revisi ikut diperiksa; rantai rusak tidak boleh membuka akses. */
    private function rantaiMilik(string $table, int $id, int $userId): bool
    {
        // Nama tabel hanya berasal dari dua pemanggil internal di atas.
        $pengurus = $this->pengurusAkun($userId);
        $visited = [];
        while ($id > 0) {
            if (isset($visited[$id])) {
                throw new V3Exception('Rantai revisi tidak valid; pemisahan tugas tidak dapat diperiksa.', 503);
            }
            $visited[$id] = true;
            $row = $this->one('SELECT created_by, updated_by, pembimbing_id, revisi_dari_id FROM ' . $table . ' WHERE id = ?', [$id]);
            if ($row === null) {
                throw new V3Exception('Sumber revisi tidak ditemukan; pemisahan tugas tidak dapat diperiksa.', 503);
            }
            if ($this->cocok($row, $userId, $pengurus)) {
                return true;
            }
            $id = (int) ($row['revisi_dari_id'] ?? 0);
        }

        return false;
    }

    /** @param array<string, mixed> $row */
    private function cocok(array $row, int $userId, ?int $pengurusId): bool
    {
        return (int) ($row['created_by'] ?? 0) === $userId
            || (int) ($row['updated_by'] ?? 0) === $userId
            || ($pengurusId !== null && (int) ($row['pembimbing_id'] ?? 0) === $pengurusId);
    }

    private function pengurusAkun(int $userId): ?int
    {
        $row = $this->one('SELECT pengurus_id FROM users WHERE id = ?', [$userId]);

        return $row === null || $row['pengurus_id'] === null ? null : (int) $row['pengurus_id'];
    }

    /**
     * @param array<int, int> $params
     * @return array<string, mixed>|null
     */
    private function one(string $sql, array $params): ?array
    {
        $statement = $this->db->prepare($sql);
        if ($statement === false) {
            throw new V3Exception('Pemisahan tugas tidak dapat diperiksa.', 503);
        }
        $statement->bind_param(str_repeat('i', count($params)), ...$params);
        if (!$statement->execute()) {
            $statement->close();
            throw new V3Exception('Pemisahan tugas tidak dapat diperiksa.', 503);
        }
        $result = $statement->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $statement->close();

        return $row ?: null;
    }
}
