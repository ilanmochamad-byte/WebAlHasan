<?php

declare(strict_types=1);

namespace App\Kesehatan;

use App\Database\PageQuery;
use App\Database\Schema;
use mysqli;
use mysqli_stmt;

/**
 * Akses basis data ringkasan wali kesehatan (PRD V5 Fase 3, migrasi 026).
 *
 * Dua kelompok kueri yang sengaja dipisah:
 *
 *   - **Internal** (petugas/admin/pembimbing/murobi): kepala, snapshot versi,
 *     peristiwa, dan penerima. Dipanggil `RingkasanService` hanya setelah hak
 *     baca dan cakupan baris kunjungan diperiksa.
 *   - **Proyeksi wali** (`wali*`): HANYA membaca `kesehatan_ringkasan`,
 *     `kesehatan_ringkasan_versi`, santri, dan relasi wali. Tidak pernah
 *     menyentuh `kesehatan_kunjungan`, versi klinis, obat, rujukan, atau
 *     tindak lanjut internal. Relasi wali aktif dipasang langsung pada SQL
 *     (akun aktif + peran orang tua + master wali aktif + `santri_wali` aktif +
 *     santri aktif) sehingga setiap daftar/detail diperiksa per baris; teks
 *     ringkasan ditarik (Ditarik) tidak pernah dikembalikan SQL.
 *
 * Teks snapshot versi tidak pernah diperbarui: satu-satunya UPDATE pada
 * `kesehatan_ringkasan_versi` mengubah status dan waktu berakhir.
 */
final class RingkasanRepository
{
    public const TABEL = ['kesehatan_ringkasan', 'kesehatan_ringkasan_versi', 'kesehatan_ringkasan_peristiwa', 'kesehatan_ringkasan_penerima'];

    public function __construct(private mysqli $db)
    {
    }

    /** Migrasi 026 terpasang (empat tabel). */
    public function skemaSiap(): bool
    {
        foreach (self::TABEL as $tabel) {
            if (!Schema::hasTable($this->db, $tabel)) {
                return false;
            }
        }

        return true;
    }

    // =======================================================================
    // Tulis (dipanggil di dalam transaksi KesehatanRepository::transaction)
    // =======================================================================

    /**
     * Kepala ringkasan satu kunjungan dengan kunci tulis. Dipanggil sesudah
     * baris kunjungan dikunci sehingga penerbitan, penggantian, penarikan, dan
     * koreksi klinis atas kunjungan yang sama berurutan.
     *
     * @return array<string, mixed>|null
     */
    public function kunciKepala(int $kunjunganId): ?array
    {
        return $this->one('SELECT * FROM kesehatan_ringkasan WHERE kunjungan_id = ? FOR UPDATE', [$kunjunganId]);
    }

    public function simpanKepala(int $kunjunganId, int $santriId, int $userId): int
    {
        $this->execute(
            "INSERT INTO kesehatan_ringkasan (kunjungan_id, santri_id, status, versi_berlaku, versi_terakhir, revisi, dibuat_oleh, diperbarui_oleh)
             VALUES (?, ?, 'Terbit', 1, 1, 1, ?, ?)",
            [$kunjunganId, $santriId, $userId, $userId]
        );

        return (int) $this->db->insert_id;
    }

    /**
     * Kepala berpindah ke keadaan baru. Penjaga `revisi = ?` melengkapi kunci
     * baris: 0 baris berubah = konflik.
     */
    public function perbaruiKepala(int $id, string $status, ?int $versiBerlaku, int $versiTerakhir, int $revisiLama, int $userId): bool
    {
        $statement = $this->prepare(
            'UPDATE kesehatan_ringkasan
                SET status = ?, versi_berlaku = ?, versi_terakhir = ?, revisi = revisi + 1, diperbarui_oleh = ?
              WHERE id = ? AND revisi = ?'
        );
        try {
            $this->run($statement, [$status, $versiBerlaku, $versiTerakhir, $userId, $id, $revisiLama]);

            return $statement->affected_rows === 1;
        } finally {
            $statement->close();
        }
    }

    /**
     * Snapshot tetap satu versi publikasi.
     *
     * @param array{ringkasan:string, tindakan_umum:?string, tindak_lanjut_wali:?string} $isi
     */
    public function simpanVersi(int $ringkasanId, int $versi, int $kunjunganId, int $kunjunganVersi, string $tanggalKunjungan, array $isi, int $userId): int
    {
        $this->execute(
            'INSERT INTO kesehatan_ringkasan_versi
                 (ringkasan_id, versi, kunjungan_id, kunjungan_versi, tanggal_kunjungan, ringkasan, tindakan_umum, tindak_lanjut_wali, diterbitkan_oleh)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$ringkasanId, $versi, $kunjunganId, $kunjunganVersi, $tanggalKunjungan, $isi['ringkasan'], $isi['tindakan_umum'], $isi['tindak_lanjut_wali'], $userId]
        );

        return (int) $this->db->insert_id;
    }

    /** Versi yang sedang berlaku berakhir (Diganti/Ditarik). Teks tidak disentuh. */
    public function akhiriVersi(int $ringkasanId, int $versi, string $status): void
    {
        $statement = $this->prepare(
            "UPDATE kesehatan_ringkasan_versi SET status = ?, berakhir_pada = NOW()
              WHERE ringkasan_id = ? AND versi = ? AND status = 'Terbit'"
        );
        try {
            $this->run($statement, [$status, $ringkasanId, $versi]);
            if ($statement->affected_rows !== 1) {
                throw new KesehatanException('Versi ringkasan yang berlaku tidak konsisten.', 503);
            }
        } finally {
            $statement->close();
        }
    }

    public function versiId(int $ringkasanId, int $versi): ?int
    {
        $row = $this->one('SELECT id FROM kesehatan_ringkasan_versi WHERE ringkasan_id = ? AND versi = ?', [$ringkasanId, $versi]);

        return $row === null ? null : (int) $row['id'];
    }

    public function simpanPeristiwa(int $ringkasanId, int $versiId, string $jenis, ?string $alasan, int $jumlahPenerima, string $kanal, int $idempotensiId, int $userId): int
    {
        $this->execute(
            'INSERT INTO kesehatan_ringkasan_peristiwa (ringkasan_id, versi_id, jenis, alasan, jumlah_penerima, kanal, idempotensi_id, dibuat_oleh)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$ringkasanId, $versiId, $jenis, $alasan, $jumlahPenerima, $kanal, $idempotensiId, $userId]
        );

        return (int) $this->db->insert_id;
    }

    /** @param array<int, array{user_id:int, wali_id:int}> $penerima */
    public function simpanPenerima(int $peristiwaId, array $penerima): void
    {
        foreach ($penerima as $p) {
            $this->execute('INSERT INTO kesehatan_ringkasan_penerima (peristiwa_id, user_id, wali_id) VALUES (?, ?, ?)', [$peristiwaId, $p['user_id'], $p['wali_id']]);
        }
    }

    /**
     * Peristiwa yang dihasilkan satu klaim idempotensi (pengulangan).
     *
     * @return array{ringkasan_id:int, versi:int, revisi:int, jenis:string}|null
     */
    public function peristiwaDariIdempotensi(int $idempotensiId): ?array
    {
        $row = $this->one(
            'SELECT p.ringkasan_id, v.versi, p.jenis, g.revisi
               FROM kesehatan_ringkasan_peristiwa p
               JOIN kesehatan_ringkasan_versi v ON v.id = p.versi_id
               JOIN kesehatan_ringkasan g ON g.id = p.ringkasan_id
              WHERE p.idempotensi_id = ?',
            [$idempotensiId]
        );

        return $row === null ? null : ['ringkasan_id' => (int) $row['ringkasan_id'], 'versi' => (int) $row['versi'], 'revisi' => (int) $row['revisi'], 'jenis' => (string) $row['jenis']];
    }

    /**
     * Wali berakun yang berhak menerima ringkasan santri saat ini: syarat sama
     * dengan `RecipientResolver::waliSantri()`, ditambah id master wali untuk
     * jejak penerima.
     *
     * @return array<int, array{user_id:int, wali_id:int}>
     */
    public function penerimaBerhak(int $santriId): array
    {
        return array_map(static fn (array $r): array => ['user_id' => (int) $r['user_id'], 'wali_id' => (int) $r['wali_id']], $this->all(
            "SELECT DISTINCT u.id AS user_id, w.id AS wali_id
               FROM users u
               JOIN wali w ON w.id = u.wali_id AND w.is_active = 1 AND w.archived_at IS NULL
               JOIN santri_wali sw ON sw.wali_id = w.id AND sw.santri_id = ? AND sw.archived_at IS NULL
               JOIN user_roles ur ON ur.user_id = u.id
               JOIN roles r ON r.id = ur.role_id AND r.slug = 'orang_tua'
              WHERE u.is_active = 1
              ORDER BY u.id",
            [$santriId]
        ));
    }

    // =======================================================================
    // Pembacaan internal
    // =======================================================================

    /** @return array<string, mixed>|null */
    public function kepala(int $kunjunganId): ?array
    {
        return $this->one('SELECT * FROM kesehatan_ringkasan WHERE kunjungan_id = ?', [$kunjunganId]);
    }

    /**
     * Seluruh snapshot versi satu ringkasan, terbaru lebih dahulu.
     *
     * @return array<int, array<string, mixed>>
     */
    public function versiInternal(int $ringkasanId): array
    {
        return $this->all(
            'SELECT v.id, v.versi, v.kunjungan_versi, v.tanggal_kunjungan, v.ringkasan, v.tindakan_umum, v.tindak_lanjut_wali, v.status,
                    v.diterbitkan_pada, v.berakhir_pada, v.diterbitkan_oleh, COALESCE(p.nama, u.name) AS penerbit_nama
               FROM kesehatan_ringkasan_versi v
               JOIN users u ON u.id = v.diterbitkan_oleh
               LEFT JOIN pengurus p ON p.id = u.pengurus_id
              WHERE v.ringkasan_id = ?
              ORDER BY v.versi DESC',
            [$ringkasanId]
        );
    }

    /**
     * Jejak peristiwa (pelaku, waktu, alasan, jumlah penerima), terbaru dahulu.
     *
     * @return array<int, array<string, mixed>>
     */
    public function peristiwaInternal(int $ringkasanId): array
    {
        return $this->all(
            'SELECT e.id, e.jenis, e.alasan, e.jumlah_penerima, e.kanal, e.dibuat_pada, e.dibuat_oleh, v.versi,
                    COALESCE(p.nama, u.name) AS pelaku_nama
               FROM kesehatan_ringkasan_peristiwa e
               JOIN kesehatan_ringkasan_versi v ON v.id = e.versi_id
               JOIN users u ON u.id = e.dibuat_oleh
               LEFT JOIN pengurus p ON p.id = u.pengurus_id
              WHERE e.ringkasan_id = ?
              ORDER BY e.id DESC',
            [$ringkasanId]
        );
    }

    // =======================================================================
    // Proyeksi wali — tanpa tabel klinis
    // =======================================================================

    /** Akun orang tua aktif dengan master wali aktif. */
    public function waliAktif(int $userId): bool
    {
        return $this->one(
            "SELECT u.id FROM users u
               JOIN wali w ON w.id = u.wali_id AND w.is_active = 1 AND w.archived_at IS NULL
               JOIN user_roles ur ON ur.user_id = u.id
               JOIN roles r ON r.id = ur.role_id AND r.slug = 'orang_tua'
              WHERE u.id = ? AND u.is_active = 1
              LIMIT 1",
            [$userId]
        ) !== null;
    }

    /**
     * Anak dengan relasi wali aktif milik akun (untuk penyaring).
     *
     * @return array<int, array{id:int, nama:string}>
     */
    public function anakWali(int $userId): array
    {
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'nama' => (string) $r['nama_santri']], $this->all(
            'SELECT s.id, s.nama_santri FROM santri s' . $this->waliSyarat() . ' ORDER BY s.nama_santri, s.id',
            [$userId]
        ));
    }

    /**
     * Daftar ringkasan anak milik akun. Hanya kepala yang pernah terbit; teks
     * tidak dimuat di daftar.
     *
     * @return array{rows:array<int, array<string, mixed>>, total:int, page:int, perPage:int}
     */
    public function waliDaftar(int $userId, int $santriId, int $page): array
    {
        $params = [$userId];
        $where = '';
        if ($santriId > 0) {
            $where = ' AND g.santri_id = ?';
            $params[] = $santriId;
        }

        return PageQuery::fetch(
            $this->db,
            'SELECT g.id, g.santri_id, g.status, g.versi_terakhir AS versi, v.tanggal_kunjungan, v.diterbitkan_pada,
                    s.nama_santri, CASE WHEN g.status = \'Terbit\' AND v.tindak_lanjut_wali IS NOT NULL THEN 1 ELSE 0 END AS ada_tindak_lanjut'
            . $this->waliFrom() . $where,
            $params,
            'diterbitkan_pada DESC, id DESC',
            max(1, $page)
        );
    }

    /**
     * Satu ringkasan milik anak akun. Teks hanya dikembalikan untuk status
     * Terbit; ringkasan yang ditarik tidak membawa isi sama sekali.
     *
     * @return array<string, mixed>|null
     */
    public function waliDetail(int $userId, int $id): ?array
    {
        return $this->one(
            'SELECT g.id, g.kunjungan_id, g.santri_id, g.status, g.versi_terakhir AS versi, v.tanggal_kunjungan, v.diterbitkan_pada,
                    s.nama_santri,
                    CASE WHEN g.status = \'Terbit\' THEN v.ringkasan END AS ringkasan,
                    CASE WHEN g.status = \'Terbit\' THEN v.tindakan_umum END AS tindakan_umum,
                    CASE WHEN g.status = \'Terbit\' THEN v.tindak_lanjut_wali END AS tindak_lanjut_wali'
            . $this->waliFrom() . ' AND g.id = ?',
            [$userId, $id]
        );
    }

    /**
     * FROM + WHERE proyeksi wali (satu parameter: user id). Versi yang
     * ditampilkan = versi terakhir (yang berlaku, atau yang ditarik).
     */
    private function waliFrom(): string
    {
        return ' FROM kesehatan_ringkasan g
                 JOIN kesehatan_ringkasan_versi v ON v.ringkasan_id = g.id AND v.versi = g.versi_terakhir
                 JOIN santri s ON s.id = g.santri_id' . $this->waliSyarat();
    }

    /**
     * Relasi wali aktif akun atas santri alias `s` (satu parameter: user id):
     * akun aktif + master wali aktif + `santri_wali` aktif + santri aktif +
     * peran orang tua. Dihitung ulang setiap permintaan.
     */
    private function waliSyarat(): string
    {
        return " JOIN users wu ON wu.id = ? AND wu.is_active = 1
                 JOIN wali ww ON ww.id = wu.wali_id AND ww.is_active = 1 AND ww.archived_at IS NULL
                WHERE s.is_active = 1 AND s.archived_at IS NULL
                  AND EXISTS (SELECT 1 FROM santri_wali wsw WHERE wsw.wali_id = ww.id AND wsw.santri_id = s.id AND wsw.archived_at IS NULL)
                  AND EXISTS (SELECT 1 FROM user_roles wur JOIN roles wr ON wr.id = wur.role_id AND wr.slug = 'orang_tua' WHERE wur.user_id = wu.id)";
    }

    // =======================================================================

    /** @return array<string, mixed>|null */
    private function one(string $sql, array $params = []): ?array
    {
        return $this->all($sql, $params)[0] ?? null;
    }

    /** @return array<int, array<string, mixed>> */
    private function all(string $sql, array $params = []): array
    {
        $statement = $this->prepare($sql);
        try {
            $this->run($statement, $params);
            $result = $statement->get_result();

            return $result === false ? [] : $result->fetch_all(MYSQLI_ASSOC);
        } finally {
            $statement->close();
        }
    }

    private function execute(string $sql, array $params): void
    {
        $statement = $this->prepare($sql);
        try {
            $this->run($statement, $params);
        } finally {
            $statement->close();
        }
    }

    private function prepare(string $sql): mysqli_stmt
    {
        $statement = $this->db->prepare($sql);
        if ($statement === false) {
            throw new KesehatanException('Data ringkasan kesehatan tidak tersedia.', 503);
        }

        return $statement;
    }

    private function run(mysqli_stmt $statement, array $params): void
    {
        if ($params !== []) {
            $types = implode('', array_map(static fn (mixed $v): string => is_int($v) ? 'i' : 's', $params));
            $statement->bind_param($types, ...$params);
        }
        if (!$statement->execute()) {
            if (in_array($statement->errno, [1205, 1213], true)) {
                throw new KesehatanException('Data sedang diproses permintaan lain. Coba lagi.', 409);
            }
            throw new KesehatanException('Data ringkasan kesehatan tidak dapat disimpan.', 503);
        }
    }
}
