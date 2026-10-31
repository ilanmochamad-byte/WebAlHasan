<?php

declare(strict_types=1);

namespace App\Schedule;

use App\Database\Schema;
use mysqli;
use RuntimeException;

/**
 * Kelas sebuah jadwal dan pertemuan (perbaikan kelas gabungan Fase 1, migrasi 034).
 *
 * Satu tempat untuk seluruh pembacaan "kelas mana yang ikut", sehingga daftar,
 * rincian, API, saringan, laporan, dan pembukaan pertemuan memakai definisi
 * yang sama:
 *
 *   - kelas JADWAL  = `jadwal_ngaji.id_kelas` (kelas utama) ∪ `jadwal_kelas`;
 *     berlaku untuk pertemuan BERIKUTNYA;
 *   - kelas PERTEMUAN = `pertemuan_kelas` yang dibekukan saat dibuka. Bila
 *     pertemuan belum punya baris (draf, atau data yang dibuat di luar layanan)
 *     dipakai kelas jadwal saat ini;
 *   - kelas asal santri = `pertemuan_peserta.kelas_id_snapshot`.
 *
 * Kelas utama selalu dihitung dari `jadwal_ngaji.id_kelas`, sehingga jadwal yang
 * tidak (atau belum) punya baris `jadwal_kelas` tetap terbaca sebagai satu kelas.
 * Sebelum migrasi 034 atau sesudah rollback, seluruh fragmen kembali ke
 * perilaku satu kelas.
 */
final class KelasJadwal
{
    /** Batas kelas dalam satu jadwal gabungan, termasuk kelas utama. */
    public const MAKS = 10;

    public static function terpasang(mysqli $db): bool
    {
        return Schema::hasTable($db, 'jadwal_kelas') && Schema::hasTable($db, 'pertemuan_kelas');
    }

    /** Daftar nama kelas jadwal, kelas utama lebih dahulu ("1 IBTIDA PA, 1 IBTIDA PI"). */
    public static function labelJadwal(mysqli $db, string $j = 'j'): string
    {
        if (!self::terpasang($db)) {
            return "(SELECT kk.nama_kelas FROM kelas kk WHERE kk.id = {$j}.id_kelas)";
        }
        return "(SELECT GROUP_CONCAT(kk.nama_kelas ORDER BY kk.id = {$j}.id_kelas DESC, kk.nama_kelas, kk.id SEPARATOR ', ')
                   FROM kelas kk
                  WHERE kk.id = {$j}.id_kelas
                     OR EXISTS (SELECT 1 FROM jadwal_kelas jkx WHERE jkx.jadwal_id = {$j}.id AND jkx.kelas_id = kk.id))";
    }

    /** ID kelas jadwal dipisah koma, kelas utama lebih dahulu. */
    public static function idsJadwal(mysqli $db, string $j = 'j'): string
    {
        if (!self::terpasang($db)) {
            return "CAST({$j}.id_kelas AS CHAR)";
        }
        return "(SELECT GROUP_CONCAT(kk.id ORDER BY kk.id = {$j}.id_kelas DESC, kk.nama_kelas, kk.id SEPARATOR ',')
                   FROM kelas kk
                  WHERE kk.id = {$j}.id_kelas
                     OR EXISTS (SELECT 1 FROM jadwal_kelas jkx WHERE jkx.jadwal_id = {$j}.id AND jkx.kelas_id = kk.id))";
    }

    /**
     * Predikat "jadwal mencakup kelas ?" — tepat SATU placeholder. `$nilai`
     * dapat diganti ekspresi kolom (mis. `k.id`) untuk korelasi.
     */
    public static function jadwalMencakup(mysqli $db, string $j = 'j', string $nilai = '?'): string
    {
        if (!self::terpasang($db)) {
            return "{$j}.id_kelas = {$nilai}";
        }
        return "EXISTS (SELECT 1 FROM kelas kx WHERE kx.id = {$nilai}
                   AND (kx.id = {$j}.id_kelas
                        OR EXISTS (SELECT 1 FROM jadwal_kelas jkx WHERE jkx.jadwal_id = {$j}.id AND jkx.kelas_id = kx.id)))";
    }

    /** Nama kelas pertemuan (kelas beku; bila belum ada, kelas jadwal). */
    public static function labelPertemuan(mysqli $db, string $p = 'p', string $j = 'j'): string
    {
        if (!self::terpasang($db)) {
            return self::labelJadwal($db, $j);
        }
        return "COALESCE((SELECT GROUP_CONCAT(kk.nama_kelas ORDER BY pkx.is_utama DESC, kk.nama_kelas, kk.id SEPARATOR ', ')
                            FROM pertemuan_kelas pkx JOIN kelas kk ON kk.id = pkx.kelas_id
                           WHERE pkx.pertemuan_id = {$p}.id), " . self::labelJadwal($db, $j) . ')';
    }

    /** Predikat "pertemuan mencakup kelas ?" — tepat SATU placeholder. */
    public static function pertemuanMencakup(mysqli $db, string $p = 'p', string $j = 'j'): string
    {
        if (!self::terpasang($db)) {
            return "{$j}.id_kelas = ?";
        }
        return "EXISTS (SELECT 1 FROM kelas kx WHERE kx.id = ?
                   AND (EXISTS (SELECT 1 FROM pertemuan_kelas pkx WHERE pkx.pertemuan_id = {$p}.id AND pkx.kelas_id = kx.id)
                        OR (NOT EXISTS (SELECT 1 FROM pertemuan_kelas pky WHERE pky.pertemuan_id = {$p}.id)
                            AND (kx.id = {$j}.id_kelas
                                 OR EXISTS (SELECT 1 FROM jadwal_kelas jkx WHERE jkx.jadwal_id = {$j}.id AND jkx.kelas_id = kx.id)))))";
    }

    /**
     * Kelas tiap jadwal, kelas utama lebih dahulu.
     *
     * @param array<int, int> $jadwalIds
     * @return array<int, list<array{id:int, name:string, level:string, is_primary:bool}>>
     */
    public static function daftarJadwal(mysqli $db, array $jadwalIds): array
    {
        $jadwalIds = self::ids($jadwalIds);
        if ($jadwalIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($jadwalIds), '?'));
        $tambahan = self::terpasang($db)
            ? " OR EXISTS (SELECT 1 FROM jadwal_kelas jkx WHERE jkx.jadwal_id = j.id AND jkx.kelas_id = kk.id)"
            : '';
        $rows = self::query(
            $db,
            "SELECT j.id AS induk_id, kk.id, kk.nama_kelas, kk.jenjang, kk.id = j.id_kelas AS is_primary
               FROM jadwal_ngaji j
               JOIN kelas kk ON (kk.id = j.id_kelas{$tambahan})
              WHERE j.id IN ({$in})
              ORDER BY j.id, kk.id = j.id_kelas DESC, kk.nama_kelas, kk.id",
            $jadwalIds
        );
        return self::kelompokkan($rows);
    }

    /**
     * Kelas tiap pertemuan: kelas beku saat dibuka; bila belum ada, kelas jadwal.
     *
     * @param array<int, int> $pertemuanIds
     * @return array<int, list<array{id:int, name:string, level:string, is_primary:bool}>>
     */
    public static function daftarPertemuan(mysqli $db, array $pertemuanIds): array
    {
        $pertemuanIds = self::ids($pertemuanIds);
        if ($pertemuanIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($pertemuanIds), '?'));
        $hasil = [];
        if (self::terpasang($db)) {
            $hasil = self::kelompokkan(self::query(
                $db,
                "SELECT pkx.pertemuan_id AS induk_id, kk.id, kk.nama_kelas, kk.jenjang, pkx.is_utama AS is_primary
                   FROM pertemuan_kelas pkx JOIN kelas kk ON kk.id = pkx.kelas_id
                  WHERE pkx.pertemuan_id IN ({$in})
                  ORDER BY pkx.pertemuan_id, pkx.is_utama DESC, kk.nama_kelas, kk.id",
                $pertemuanIds
            ));
        }
        $sisa = array_values(array_diff($pertemuanIds, array_keys($hasil)));
        if ($sisa !== []) {
            $in = implode(',', array_fill(0, count($sisa), '?'));
            $jadwal = [];
            foreach (self::query($db, "SELECT id, jadwal_id FROM pertemuan_pengajian WHERE id IN ({$in})", $sisa) as $row) {
                $jadwal[(int) $row['id']] = (int) $row['jadwal_id'];
            }
            $perJadwal = self::daftarJadwal($db, array_values($jadwal));
            foreach ($jadwal as $pertemuanId => $jadwalId) {
                $hasil[$pertemuanId] = $perJadwal[$jadwalId] ?? [];
            }
        }
        return $hasil;
    }

    /**
     * Normalisasi pilihan kelas jadwal: kelas utama + kelas gabungan, tanpa duplikat.
     *
     * @return list<int> kelas utama di posisi pertama
     */
    public static function pilihan(int $utama, mixed $gabungan): array
    {
        $ids = [$utama];
        if (is_array($gabungan)) {
            foreach ($gabungan as $nilai) {
                if (!is_scalar($nilai) || !preg_match('/^[1-9]\d{0,9}$/', trim((string) $nilai))) {
                    throw new ScheduleException('Pilihan kelas gabungan tidak valid.');
                }
                $ids[] = (int) $nilai;
            }
        } elseif ($gabungan !== null && $gabungan !== '') {
            throw new ScheduleException('Pilihan kelas gabungan tidak valid.');
        }
        $ids = array_values(array_unique($ids));
        if (count($ids) > self::MAKS) {
            throw new ScheduleException('Satu jadwal paling banyak mencakup ' . self::MAKS . ' kelas.');
        }
        return $ids;
    }

    /** @return list<int> */
    private static function ids(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
    }

    /** @return array<int, list<array{id:int, name:string, level:string, is_primary:bool}>> */
    private static function kelompokkan(array $rows): array
    {
        $hasil = [];
        foreach ($rows as $row) {
            $hasil[(int) $row['induk_id']][] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['nama_kelas'],
                'level' => (string) $row['jenjang'],
                'is_primary' => (int) $row['is_primary'] === 1,
            ];
        }
        return $hasil;
    }

    /** @param list<int> $params */
    private static function query(mysqli $db, string $sql, array $params): array
    {
        $statement = $db->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Query kelas jadwal tidak dapat disiapkan: ' . $db->error);
        }
        try {
            if (!$statement->execute($params)) {
                throw new RuntimeException('Query kelas jadwal gagal dijalankan.');
            }
            return $statement->get_result()->fetch_all(MYSQLI_ASSOC);
        } finally {
            $statement->close();
        }
    }
}
