<?php

declare(strict_types=1);

namespace App\Izin;

use App\Database\Schema;
use mysqli;
use RuntimeException;

/**
 * Pemisahan tugas perizinan per AKUN (penugasan lintas guru/pengurus Fase 2,
 * rancangan §3.2; keputusan Human Developer PK-T6).
 *
 * Sejak satu akun guru boleh memegang identitas pengurus (Fase 1), orang yang
 * sama dapat menjadi pembimbing (pengaju/peninjau) sekaligus murobi santri
 * yang sama. Aturan "pengaju bukan pemutus" karena itu ditegakkan pada level
 * akun, bukan identitas master:
 *
 *   - akun terlibat = akun pengaju (`diajukan_oleh_user_id`) ditambah setiap
 *     akun pembimbing yang pernah meninjau pengajuan wali (V7);
 *   - guru terlibat = `users.guru_id` milik akun-akun itu. Satu guru paling
 *     banyak satu akun (`users_guru_unique`), sehingga akun ganda warisan
 *     (akun guru yang juga memegang pengurus biasa) ikut tertangkap;
 *   - routing murobi dan penetapan admin tidak memilih guru terlibat;
 *   - keputusan dan koreksi oleh akun terlibat ditolak, termasuk admin.
 *
 * Batas: dua akun TERPISAH milik satu orang (data ganda guru/pengurus yang
 * tidak dihubungkan, keputusan "peringatan saja" Fase 1) tidak dapat dikenali.
 * Kelas ini hanya membaca.
 */
final class PemisahanTugas
{
    public function __construct(private mysqli $db)
    {
    }

    /**
     * Akun yang mengajukan atau meninjau satu pengajuan.
     *
     * Dibaca sesudah baris pengajuan dikunci: `diajukan_oleh_user_id` tidak
     * pernah berubah dan tinjauan hanya ditulis saat baris yang sama dikunci.
     *
     * @return array<int, int>
     */
    public function akunTerlibat(int $pengajuanId): array
    {
        $ids = [];
        foreach ($this->select('SELECT diajukan_oleh_user_id AS user_id FROM izin_pengajuan WHERE id = ?', [$pengajuanId]) as $row) {
            if ($row['user_id'] !== null) {
                $ids[] = (int) $row['user_id'];
            }
        }
        if (Schema::hasTable($this->db, 'izin_pembimbing_tinjauan')) {
            foreach ($this->select(
                'SELECT DISTINCT ditinjau_oleh_user_id AS user_id FROM izin_pembimbing_tinjauan WHERE pengajuan_id = ?',
                [$pengajuanId]
            ) as $row) {
                $ids[] = (int) $row['user_id'];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Guru milik akun-akun tertentu.
     *
     * @param array<int, int> $userIds
     * @return array<int, int>
     */
    public function guruAkun(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn (int $id): bool => $id > 0)));
        if ($userIds === []) {
            return [];
        }
        $rows = $this->select(
            'SELECT guru_id FROM users WHERE guru_id IS NOT NULL AND id IN (' . implode(',', array_fill(0, count($userIds), '?')) . ')',
            $userIds
        );

        return array_values(array_unique(array_map(static fn (array $row): int => (int) $row['guru_id'], $rows)));
    }

    /**
     * Guru yang tidak boleh menjadi murobi tujuan pengajuan ini.
     *
     * @param array<int, int> $akunTambahan akun yang sedang bertindak tetapi
     *        belum tercatat (pengaju baru atau pembimbing yang sedang meneruskan)
     * @return array<int, int>
     */
    public function guruTerlibat(?int $pengajuanId, array $akunTambahan = []): array
    {
        $akun = $pengajuanId === null ? [] : $this->akunTerlibat($pengajuanId);

        return $this->guruAkun(array_merge($akun, $akunTambahan));
    }

    public function terlibat(int $userId, int $pengajuanId): bool
    {
        return in_array($userId, $this->akunTerlibat($pengajuanId), true);
    }

    /** Penolakan seragam untuk keputusan dan koreksi oleh akun terlibat. */
    public function wajibBukanTerlibat(int $userId, int $pengajuanId): void
    {
        if ($this->terlibat($userId, $pengajuanId)) {
            throw IzinException::forbidden(
                'Anda mengajukan atau meninjau pengajuan ini, sehingga tidak dapat memutus atau mengoreksinya. '
                . 'Keputusan diberikan murobi lain atau admin lain.'
            );
        }
    }

    /**
     * @param array<int, int|string> $params
     * @return array<int, array<string, mixed>>
     */
    private function select(string $sql, array $params): array
    {
        $statement = $this->db->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Pemisahan tugas perizinan tidak dapat diperiksa.');
        }
        $types = '';
        foreach ($params as $value) {
            $types .= is_int($value) ? 'i' : 's';
        }
        if ($params !== [] && !$statement->bind_param($types, ...$params)) {
            $statement->close();
            throw new RuntimeException('Pemisahan tugas perizinan tidak dapat diperiksa.');
        }
        if (!$statement->execute()) {
            $statement->close();
            throw new RuntimeException('Pemisahan tugas perizinan tidak dapat diperiksa.');
        }
        $result = $statement->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $statement->close();

        return $rows;
    }
}
