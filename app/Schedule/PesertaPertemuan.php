<?php

declare(strict_types=1);

namespace App\Schedule;

use mysqli;
use RuntimeException;

/**
 * Pembekuan kelas dan peserta saat pertemuan dibuka (kelas gabungan Fase 1).
 *
 * Satu implementasi untuk web (`ScheduleService::open`) dan REST API
 * (`TeacherService::openMeeting`). Pemanggil WAJIB sudah mengunci baris
 * `jadwal_ngaji` (FOR UPDATE) dan membuat/membuka baris pertemuan di transaksi
 * yang sama, sehingga perubahan kelas jadwal yang bersamaan menunggu dan baru
 * berlaku untuk pertemuan berikutnya.
 *
 *   1. `pertemuan_kelas` ← seluruh kelas jadwal saat ini (utama + gabungan);
 *   2. `pertemuan_peserta` ← keanggotaan kelas AKTIF semester jadwal dari
 *      seluruh kelas itu, SATU baris per santri. Kelas asal santri disimpan di
 *      `kelas_id_snapshot`. Santri yang tercatat aktif di lebih dari satu kelas
 *      terpilih tetap satu peserta (keanggotaan dengan ID terkecil), sehingga
 *      tidak ada peserta atau absensi ganda.
 */
final class PesertaPertemuan
{
    /**
     * @param int|null $scheduleId jadwal pertemuan; `null` = pemanggil lama yang
     *        menyebut satu kelas saja (`$classId`), yang kemudian dibekukan apa adanya.
     */
    public static function bekukan(mysqli $db, int $meetingId, ?int $scheduleId, int $classId, int $yearId): void
    {
        if (!KelasJadwal::terpasang($db)) {
            // Perilaku satu kelas sebelum migrasi 034 / sesudah rollback.
            self::execute(
                $db,
                "INSERT INTO pertemuan_peserta
                    (pertemuan_id, santri_id, plotting_kelas_id, nis_snapshot, nama_santri_snapshot, kelas_id_snapshot, tahun_ajaran_id_snapshot, created_at)
                 SELECT ?, s.id, pk.id, s.nis, s.nama_santri, ?, ?, NOW()
                 FROM plotting_kelas pk
                 JOIN santri s ON s.id = pk.id_santri
                 WHERE pk.id_kelas = ? AND pk.id_tahun = ? AND pk.status = 'Aktif'
                 ORDER BY s.nama_santri, s.id",
                [$meetingId, $classId, $yearId, $classId, $yearId]
            );
            return;
        }

        if ($scheduleId === null) {
            self::execute(
                $db,
                "INSERT INTO pertemuan_kelas (pertemuan_id, kelas_id, is_utama, sumber, created_at)
                 SELECT p.id, kk.id, 1, 'Pembukaan', NOW()
                 FROM pertemuan_pengajian p JOIN kelas kk ON kk.id = ?
                 WHERE p.id = ?",
                [$classId, $meetingId]
            );
        } else {
            self::execute(
                $db,
                "INSERT INTO pertemuan_kelas (pertemuan_id, kelas_id, is_utama, sumber, created_at)
                 SELECT ?, kk.id, kk.id = j.id_kelas, 'Pembukaan', NOW()
                 FROM jadwal_ngaji j
                 JOIN kelas kk ON (kk.id = j.id_kelas
                                   OR EXISTS (SELECT 1 FROM jadwal_kelas jk WHERE jk.jadwal_id = j.id AND jk.kelas_id = kk.id))
                 WHERE j.id = ?
                 ORDER BY kk.id = j.id_kelas DESC, kk.nama_kelas, kk.id",
                [$meetingId, $scheduleId]
            );
        }

        self::execute(
            $db,
            "INSERT INTO pertemuan_peserta
                (pertemuan_id, santri_id, plotting_kelas_id, nis_snapshot, nama_santri_snapshot, kelas_id_snapshot, tahun_ajaran_id_snapshot, created_at)
             SELECT ?, s.id, pk.id, s.nis, s.nama_santri, pk.id_kelas, ?, NOW()
             FROM plotting_kelas pk
             JOIN santri s ON s.id = pk.id_santri
             WHERE pk.id_tahun = ? AND pk.status = 'Aktif'
               AND pk.id = (SELECT MIN(pk2.id)
                              FROM plotting_kelas pk2
                              JOIN pertemuan_kelas mk ON mk.pertemuan_id = ? AND mk.kelas_id = pk2.id_kelas
                             WHERE pk2.id_santri = pk.id_santri AND pk2.id_tahun = ? AND pk2.status = 'Aktif')
             ORDER BY s.nama_santri, s.id",
            [$meetingId, $yearId, $yearId, $meetingId, $yearId]
        );
    }

    /** @param list<int> $params */
    private static function execute(mysqli $db, string $sql, array $params): void
    {
        $statement = $db->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Query peserta pertemuan tidak dapat disiapkan: ' . $db->error);
        }
        try {
            if (!$statement->execute($params)) {
                if ($statement->errno === 1062) {
                    throw new ScheduleException('Data duplikat ditolak oleh basis data.');
                }
                throw new RuntimeException('Query peserta pertemuan gagal: ' . $statement->error);
            }
        } catch (\mysqli_sql_exception $exception) {
            if ((int) $exception->getCode() === 1062) {
                throw new ScheduleException('Data duplikat ditolak oleh basis data.');
            }
            throw $exception;
        } finally {
            $statement->close();
        }
    }
}
