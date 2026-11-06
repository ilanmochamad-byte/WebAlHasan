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
 *
 * Perbaikan peserta absensi (6 November 2026):
 *   - hanya santri yang statusnya AKTIF dan tidak diarsipkan yang menjadi
 *     peserta. Sebelumnya santri yang dinonaktifkan di Data Santri tetapi
 *     penempatan kelasnya masih "Aktif" tetap ikut dibekukan;
 *   - `perbarui()` menyelaraskan peserta pertemuan berstatus Dibuka dengan
 *     keanggotaan aktif saat ini pada kelas yang SAMA dengan saat dibuka.
 */
final class PesertaPertemuan
{
    /** Syarat santri yang boleh menjadi peserta (alias `s` = tabel santri). */
    private const SANTRI_AKTIF = 's.is_active = 1 AND s.archived_at IS NULL';

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
                   AND " . self::SANTRI_AKTIF . "
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
               AND " . self::SANTRI_AKTIF . "
               AND pk.id = (SELECT MIN(pk2.id)
                              FROM plotting_kelas pk2
                              JOIN pertemuan_kelas mk ON mk.pertemuan_id = ? AND mk.kelas_id = pk2.id_kelas
                             WHERE pk2.id_santri = pk.id_santri AND pk2.id_tahun = ? AND pk2.status = 'Aktif')
             ORDER BY s.nama_santri, s.id",
            [$meetingId, $yearId, $yearId, $meetingId, $yearId]
        );
    }

    /**
     * Menyelaraskan peserta pertemuan berstatus Dibuka dengan keanggotaan kelas
     * yang aktif SAAT INI.
     *
     *   - Kelas pertemuan tetap kelas yang dibekukan saat dibuka
     *     (`pertemuan_kelas`; sebelum 034 = kelas utama jadwal). Perubahan kelas
     *     jadwal sesudah pertemuan dibuka tidak ikut.
     *   - Santri aktif yang kini ditempatkan di kelas itu tetapi belum menjadi
     *     peserta → ditambahkan.
     *   - Peserta yang tidak lagi memenuhi syarat (penempatan ditutup/pindah
     *     kelas, santri nonaktif/diarsipkan) → dikeluarkan, KECUALI sudah memiliki
     *     absensi pada pertemuan ini; absensi yang tercatat tidak pernah dihapus.
     *
     * Pemanggil WAJIB sudah mengunci baris `pertemuan_pengajian` (FOR UPDATE)
     * dan memastikan statusnya `Dibuka` di transaksi yang sama. Penyimpanan
     * absensi mengunci baris yang sama, sehingga keduanya berjalan berurutan.
     *
     * @return array{ditambah:list<int>, dikeluarkan:list<int>, dipertahankan:list<int>}
     *         ID santri; `dipertahankan` = tidak lagi memenuhi syarat tetapi sudah berabsensi.
     */
    public static function perbarui(mysqli $db, int $meetingId, int $classId, int $yearId): array
    {
        if (KelasJadwal::terpasang($db)) {
            $kelasSyarat = 'EXISTS (SELECT 1 FROM pertemuan_kelas mk WHERE mk.pertemuan_id = ? AND mk.kelas_id = pk2.id_kelas)';
            $kelasParams = [$meetingId];
        } else {
            $kelasSyarat = 'pk2.id_kelas = ?';
            $kelasParams = [$classId];
        }

        $layak = [];
        foreach (self::query(
            $db,
            "SELECT s.id AS santri_id, pk.id AS plotting_id, pk.id_kelas, s.nis, s.nama_santri
               FROM plotting_kelas pk
               JOIN santri s ON s.id = pk.id_santri
              WHERE pk.id_tahun = ? AND pk.status = 'Aktif'
                AND " . self::SANTRI_AKTIF . "
                AND pk.id = (SELECT MIN(pk2.id) FROM plotting_kelas pk2
                              WHERE pk2.id_santri = pk.id_santri AND pk2.id_tahun = ? AND pk2.status = 'Aktif'
                                AND " . $kelasSyarat . ")
              ORDER BY s.nama_santri, s.id",
            [$yearId, $yearId, ...$kelasParams]
        ) as $row) {
            $layak[(int) $row['santri_id']] = $row;
        }

        $sekarang = [];
        foreach (self::query(
            $db,
            'SELECT pp.santri_id,
                    EXISTS (SELECT 1 FROM absensi_santri a WHERE a.pertemuan_id = pp.pertemuan_id AND a.santri_id = pp.santri_id) AS berabsensi
               FROM pertemuan_peserta pp WHERE pp.pertemuan_id = ?',
            [$meetingId]
        ) as $row) {
            $sekarang[(int) $row['santri_id']] = (int) $row['berabsensi'] === 1;
        }

        $hasil = ['ditambah' => [], 'dikeluarkan' => [], 'dipertahankan' => []];
        foreach ($layak as $santriId => $row) {
            if (array_key_exists($santriId, $sekarang)) {
                continue;
            }
            self::execute(
                $db,
                'INSERT INTO pertemuan_peserta
                    (pertemuan_id, santri_id, plotting_kelas_id, nis_snapshot, nama_santri_snapshot, kelas_id_snapshot, tahun_ajaran_id_snapshot, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
                [$meetingId, $santriId, (int) $row['plotting_id'], (string) $row['nis'], (string) $row['nama_santri'], (int) $row['id_kelas'], $yearId]
            );
            $hasil['ditambah'][] = $santriId;
        }
        foreach ($sekarang as $santriId => $berabsensi) {
            if (isset($layak[$santriId])) {
                continue;
            }
            if ($berabsensi) {
                $hasil['dipertahankan'][] = $santriId;
                continue;
            }
            self::execute($db, 'DELETE FROM pertemuan_peserta WHERE pertemuan_id = ? AND santri_id = ?', [$meetingId, $santriId]);
            $hasil['dikeluarkan'][] = $santriId;
        }

        return $hasil;
    }

    /**
     * @param list<int|string> $params
     * @return list<array<string, mixed>>
     */
    private static function query(mysqli $db, string $sql, array $params): array
    {
        $statement = $db->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Query peserta pertemuan tidak dapat disiapkan: ' . $db->error);
        }
        try {
            if (!$statement->execute($params)) {
                throw new RuntimeException('Query peserta pertemuan gagal: ' . $statement->error);
            }
            $result = $statement->get_result();
            return $result === false ? [] : array_values($result->fetch_all(MYSQLI_ASSOC));
        } finally {
            $statement->close();
        }
    }

    /** @param list<int|string> $params */
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
