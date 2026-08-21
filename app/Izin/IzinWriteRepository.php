<?php

declare(strict_types=1);

namespace App\Izin;

use mysqli;
use mysqli_stmt;
use RuntimeException;

/**
 * Penulisan perizinan V2 (Fase 2).
 *
 * Dipisahkan dari IzinRepository yang tetap baca-saja, sehingga jalur baca tidak
 * pernah bisa menulis dan pemeriksaan statis atas keduanya tetap tegas.
 *
 * Aturan kelas ini:
 *   - seluruh mutasi memakai prepared statement;
 *   - tidak pernah membaca variabel global request (IP dan user agent dikirim
 *     pemanggil sebagai parameter);
 *   - penguncian baris eksplisit (`FOR UPDATE`) dipakai untuk membuat pemeriksaan
 *     tumpang tindih dan keputusan bersamaan menjadi aman;
 *   - tidak pernah menghapus baris riwayat, keputusan, atau pengajuan.
 */
final class IzinWriteRepository
{
    /** Status yang masih menahan slot tanggal santri (PRD 5.3). */
    public const STATUS_MENAHAN = ['Diajukan', 'Perlu Penetapan Admin', 'Disetujui'];

    /** Status yang masih boleh diputus, dibatalkan, atau ditetapkan ulang. */
    public const STATUS_BELUM_DIPUTUS = ['Diajukan', 'Perlu Penetapan Admin'];

    public function __construct(private mysqli $db)
    {
    }

    public function beginTransaction(): void
    {
        if (!$this->db->begin_transaction()) {
            throw new RuntimeException('Transaksi perizinan tidak dapat dimulai.');
        }
    }

    public function commit(): void
    {
        if (!$this->db->commit()) {
            throw new RuntimeException('Transaksi perizinan tidak dapat disimpan.');
        }
    }

    public function rollback(): void
    {
        $this->db->rollback();
    }

    // -----------------------------------------------------------------------
    // Penguncian dan pembacaan terkunci
    // -----------------------------------------------------------------------

    /**
     * Mengunci baris santri untuk menserialkan pembuatan pengajuan santri yang sama.
     * Dua request bersamaan untuk satu santri akan diproses berurutan sehingga
     * pemeriksaan tumpang tindih tidak dapat dilewati oleh balapan.
     *
     * @return array<string, mixed>|null
     */
    public function lockSantri(int $santriId): ?array
    {
        return $this->one(
            'SELECT id, nis, nama_santri, is_active, archived_at FROM santri WHERE id = ? LIMIT 1 FOR UPDATE',
            [$santriId]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lockPengajuan(int $id): ?array
    {
        return $this->one(
            'SELECT p.* FROM izin_pengajuan p WHERE p.id = ? LIMIT 1 FOR UPDATE',
            [$id]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lockKeputusan(int $pengajuanId): ?array
    {
        return $this->one(
            'SELECT k.* FROM izin_keputusan k WHERE k.pengajuan_id = ? LIMIT 1 FOR UPDATE',
            [$pengajuanId]
        );
    }

    /**
     * Pengajuan lain untuk santri yang sama dengan rentang tanggal bersinggungan.
     * Dua rentang bersinggungan bila `mulai_a <= selesai_b AND selesai_a >= mulai_b`.
     *
     * @return array<string, mixed>|null
     */
    public function findOverlap(int $santriId, string $from, string $to, ?int $excludeId = null): ?array
    {
        $placeholders = implode(',', array_fill(0, count(self::STATUS_MENAHAN), '?'));
        $params = array_merge([$santriId], self::STATUS_MENAHAN, [$to, $from]);
        $sql = 'SELECT id, tgl_izin, tgl_kembali, status
                  FROM izin_pengajuan
                 WHERE santri_id = ?
                   AND status IN (' . $placeholders . ')
                   AND tgl_izin <= ?
                   AND tgl_kembali >= ?';
        if ($excludeId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeId;
        }

        return $this->one($sql . ' ORDER BY id LIMIT 1 FOR UPDATE', $params);
    }

    // -----------------------------------------------------------------------
    // Mutasi
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $data
     */
    public function insertPengajuan(array $data): int
    {
        return $this->insertAndGetId(
            'INSERT INTO izin_pengajuan
                (santri_id, pengurus_id, diajukan_oleh_user_id, pembimbing_assignment_id,
                 tahun_ajaran_id, tgl_izin, tgl_kembali, alasan, catatan_pengurus,
                 status, version, idempotency_key, diajukan_pada, is_legacy)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW(), 0)',
            [
                $data['santri_id'],
                $data['pengurus_id'],
                $data['diajukan_oleh_user_id'],
                $data['pembimbing_assignment_id'],
                $data['tahun_ajaran_id'],
                $data['tgl_izin'],
                $data['tgl_kembali'],
                $data['alasan'],
                $data['catatan_pengurus'],
                $data['status'],
                $data['idempotency_key'],
            ]
        );
    }

    public function applyRouting(int $pengajuanId, ?int $murobiGuruId, string $status, int $jumlahKandidat, string $catatan): void
    {
        $this->execute(
            'UPDATE izin_pengajuan
                SET murobi_guru_id = ?, status = ?, routing_kandidat = ?, routing_catatan = ?, routing_pada = NOW()
              WHERE id = ?',
            [$murobiGuruId, $status, $jumlahKandidat, $catatan, $pengajuanId]
        );
    }

    /**
     * Perubahan status dengan optimistic version.
     *
     * Mengembalikan false bila versi sudah berubah atau status tidak lagi memenuhi
     * syarat — pemanggil menerjemahkannya menjadi `409`. Inilah pengaman kedua
     * (selain penguncian baris) agar dua keputusan bersamaan hanya menghasilkan satu.
     *
     * @param array<int, string> $statusYangDiizinkan
     */
    public function updateStatusWithVersion(
        int $pengajuanId,
        string $statusBaru,
        int $expectedVersion,
        array $statusYangDiizinkan
    ): bool {
        $placeholders = implode(',', array_fill(0, count($statusYangDiizinkan), '?'));
        $params = array_merge(
            [$statusBaru, $pengajuanId, $expectedVersion],
            $statusYangDiizinkan
        );

        // Jumlah baris terpengaruh dibaca dari statement SEBELUM ditutup: setelah
        // `close()`, `mysqli::$affected_rows` tidak lagi dapat diandalkan.
        return $this->execute(
            'UPDATE izin_pengajuan
                SET status = ?, version = version + 1, updated_at = NOW()
              WHERE id = ? AND version = ? AND status IN (' . $placeholders . ')',
            $params
        ) === 1;
    }

    public function markCancelled(int $pengajuanId, int $actorUserId, string $alasan): void
    {
        $this->execute(
            'UPDATE izin_pengajuan
                SET dibatalkan_oleh_user_id = ?, dibatalkan_pada = NOW(), alasan_pembatalan = ?
              WHERE id = ?',
            [$actorUserId, $alasan, $pengajuanId]
        );
    }

    public function assignMurobi(int $pengajuanId, int $murobiGuruId, int $actorUserId): void
    {
        $this->execute(
            'UPDATE izin_pengajuan
                SET murobi_guru_id = ?, murobi_ditetapkan_oleh_user_id = ?, murobi_ditetapkan_pada = NOW()
              WHERE id = ?',
            [$murobiGuruId, $actorUserId, $pengajuanId]
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insertKeputusan(array $data): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO izin_keputusan
                (pengajuan_id, hasil, alasan, diputus_oleh_user_id, kapasitas, alasan_penggantian,
                 diputus_pada, pengajuan_version, idempotency_key)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?)'
        );
        if ($statement === false) {
            throw new RuntimeException('Keputusan izin tidak dapat disiapkan.');
        }
        $params = [
            $data['pengajuan_id'],
            $data['hasil'],
            $data['alasan'],
            $data['diputus_oleh_user_id'],
            $data['kapasitas'],
            $data['alasan_penggantian'],
            $data['pengajuan_version'],
            $data['idempotency_key'],
        ];
        if (!$this->run($statement, $params)) {
            $errno = $statement->errno;
            $statement->close();
            if ($errno === 1062) {
                // Kunci unik (pengajuan_id) — pengaman ketiga terhadap keputusan ganda.
                throw IzinException::conflict('Pengajuan ini sudah memiliki keputusan. Keputusan kedua ditolak.');
            }
            if ($errno === 4025 || $errno === 3819) {
                throw IzinException::invalid('Keputusan ditolak aturan basis data: keputusan Admin Pengganti wajib memuat alasan penggantian.');
            }
            throw new RuntimeException('Keputusan izin gagal disimpan.');
        }
        $keputusanId = (int) $statement->insert_id;
        $statement->close();

        return $keputusanId;
    }

    /**
     * Koreksi memperbarui nilai yang BERLAKU, sedangkan nilai lama disimpan pada
     * `izin_keputusan_koreksi` dan `izin_riwayat_status`. Tidak ada baris dihapus.
     */
    public function updateKeputusan(int $keputusanId, string $hasil, string $alasan): void
    {
        $this->execute(
            'UPDATE izin_keputusan
                SET hasil = ?, alasan = ?, dikoreksi_pada = NOW(), jumlah_koreksi = jumlah_koreksi + 1
              WHERE id = ?',
            [$hasil, $alasan, $keputusanId]
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insertKoreksi(array $data): int
    {
        return $this->insertAndGetId(
            'INSERT INTO izin_keputusan_koreksi
                (pengajuan_id, keputusan_id, hasil_sebelum, hasil_sesudah, alasan_sebelum, alasan_sesudah,
                 status_sebelum, status_sesudah, alasan_koreksi, dikoreksi_oleh_user_id, dikoreksi_pada)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                $data['pengajuan_id'],
                $data['keputusan_id'],
                $data['hasil_sebelum'],
                $data['hasil_sesudah'],
                $data['alasan_sebelum'],
                $data['alasan_sesudah'],
                $data['status_sebelum'],
                $data['status_sesudah'],
                $data['alasan_koreksi'],
                $data['dikoreksi_oleh_user_id'],
            ]
        );
    }

    /**
     * Riwayat hanya pernah bertambah. IP dan user agent dikirim pemanggil agar
     * lapisan data tidak menyentuh variabel global.
     *
     * @param array<string, mixed> $data
     */
    public function insertRiwayat(array $data): int
    {
        return $this->insertAndGetId(
            'INSERT INTO izin_riwayat_status
                (pengajuan_id, peristiwa, status_sebelum, status_sesudah, pelaku_user_id,
                 pelaku_kapasitas, alasan, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $data['pengajuan_id'],
                $data['peristiwa'],
                $data['status_sebelum'],
                $data['status_sesudah'],
                $data['pelaku_user_id'],
                $data['pelaku_kapasitas'],
                $data['alasan'],
                $data['ip_address'],
                $data['user_agent'],
            ]
        );
    }

    // -----------------------------------------------------------------------
    // Utilitas
    // -----------------------------------------------------------------------

    /**
     * @return array<string, mixed>|null
     */
    private function one(string $sql, array $params): ?array
    {
        $statement = $this->db->prepare($sql);
        if ($statement === false || !$this->run($statement, $params)) {
            throw new RuntimeException('Data perizinan tidak dapat dikunci untuk diperbarui.');
        }
        $row = $statement->get_result()?->fetch_assoc();
        $statement->close();

        return is_array($row) ? $row : null;
    }

    /**
     * @return int jumlah baris terpengaruh (dibaca sebelum statement ditutup)
     */
    private function execute(string $sql, array $params): int
    {
        $statement = $this->db->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Perintah perizinan tidak dapat disiapkan.');
        }
        if (!$this->run($statement, $params)) {
            $errno = $statement->errno;
            $error = $statement->error;
            $statement->close();
            if ($errno === 1062) {
                throw IzinException::conflict('Data perizinan bentrok dengan baris yang sudah ada.');
            }
            if ($errno === 4025 || $errno === 3819) {
                throw IzinException::invalid('Perintah perizinan ditolak oleh aturan basis data: ' . $error);
            }
            throw new RuntimeException('Perintah perizinan gagal disimpan.');
        }
        $affected = (int) $statement->affected_rows;
        $statement->close();

        return $affected;
    }


    /**
     * @return int id baris baru (dibaca sebelum statement ditutup)
     */
    private function insertAndGetId(string $sql, array $params): int
    {
        $statement = $this->db->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Perintah perizinan tidak dapat disiapkan.');
        }
        if (!$this->run($statement, $params)) {
            $errno = $statement->errno;
            $error = $statement->error;
            $statement->close();
            if ($errno === 1062) {
                throw IzinException::conflict('Data perizinan bentrok dengan baris yang sudah ada.');
            }
            if ($errno === 4025 || $errno === 3819) {
                throw IzinException::invalid('Perintah perizinan ditolak oleh aturan basis data: ' . $error);
            }
            throw new RuntimeException('Perintah perizinan gagal disimpan.');
        }
        $id = (int) $statement->insert_id;
        $statement->close();

        return $id;
    }

    private function run(mysqli_stmt $statement, array $params): bool
    {
        if ($params !== []) {
            $types = '';
            $references = [];
            foreach ($params as $key => &$value) {
                $types .= is_int($value) || is_bool($value) ? 'i' : (is_float($value) ? 'd' : 's');
                $references[$key] = &$value;
            }
            unset($value);
            if (!$statement->bind_param($types, ...$references)) {
                return false;
            }
        }

        return $statement->execute();
    }
}
