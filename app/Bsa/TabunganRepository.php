<?php

declare(strict_types=1);

namespace App\Bsa;

use App\Database\PageQuery;
use App\Database\Schema;
use mysqli;
use mysqli_stmt;

/**
 * Akses basis data tabungan lengkap (PRD V6 Fase 3, migrasi 029): setoran
 * transfer yang menunggu verifikasi, daftar mutasi tabungan lintas santri,
 * dan rekonsiliasi harian. Ledger dan akun saldo tetap milik
 * `BsaRepository` (027).
 *
 * Seluruh SQL memakai parameter terikat. Repository tidak memutuskan hak:
 * `TabunganService` memeriksa penugasan/cakupan/relasi wali lewat
 * `BsaService` lebih dahulu; daftar memakai syarat hak yang sama dengan
 * Fase 2 (`TagihanRepository::syaratHak`). Transaksi memakai
 * `BsaRepository::transaction()` pada koneksi yang sama.
 *
 * URUTAN PENGUNCIAN (lanjutan Fase 1–2, satu urutan untuk semua operasi):
 *   penugasan (baca) → klaim idempotensi (tulis) → santri (baca)
 *   → tagihan (tulis) → pembayaran (tulis) → setoran transfer (tulis)
 *   → akun tabungan (tulis).
 * Mutasi tabungan murni (setoran/penarikan/belanja/koreksi) hanya mengunci
 * akun sesudah santri, sehingga tidak ada siklus dengan pembayaran tagihan
 * dari tabungan (tagihan lalu akun).
 */
final class TabunganRepository
{
    public const TABEL = ['bsa_setoran_transfer'];

    public function __construct(private mysqli $db, private BsaRepository $bsa, private TagihanRepository $tagihan)
    {
    }

    /** Migrasi 029 terpasang. */
    public function skemaSiap(): bool
    {
        return Schema::hasTable($this->db, 'bsa_setoran_transfer');
    }

    // =======================================================================
    // Setoran transfer menunggu verifikasi
    // =======================================================================

    /**
     * @param array{nominal:int, referensi:string, catatan:?string, idempotency_key:string} $data
     */
    public function simpanSetoranTransfer(int $santriId, array $data, int $userId, int $pengurusId, int $penugasanId): int
    {
        $this->execute(
            "INSERT INTO bsa_setoran_transfer (santri_id, nominal, referensi, catatan, status, penugasan_id, pengurus_id, dicatat_oleh, idempotency_key)
             VALUES (?, ?, ?, ?, 'Menunggu', ?, ?, ?, ?)",
            [$santriId, $data['nominal'], $data['referensi'], $data['catatan'], $penugasanId, $pengurusId, $userId, $data['idempotency_key']]
        );

        return (int) $this->db->insert_id;
    }

    private const SETORAN = 'SELECT st.id, st.santri_id, st.nominal, st.referensi, st.catatan, st.status, st.transaksi_id, st.diputus_pada, st.alasan_tolak,
                    st.penugasan_id, st.pengurus_id, st.dicatat_oleh, st.created_at,
                    COALESCE(p.nama, u.name) AS pencatat_nama, COALESCE(pd.nama, ud.name) AS pemutus_nama, x.created_at AS transaksi_dibuat,
                    s.nis, s.nama_santri
               FROM bsa_setoran_transfer st
               JOIN santri s ON s.id = st.santri_id
               JOIN users u ON u.id = st.dicatat_oleh
               LEFT JOIN pengurus p ON p.id = st.pengurus_id
               LEFT JOIN users ud ON ud.id = st.diputus_oleh
               LEFT JOIN pengurus pd ON pd.id = ud.pengurus_id
               LEFT JOIN bsa_tabungan_transaksi x ON x.id = st.transaksi_id';

    /**
     * Satu setoran transfer. Dengan `$kunci`, hanya kolom keputusan yang
     * dibaca dengan kunci tulis di dalam transaksi.
     *
     * @return array<string, mixed>|null
     */
    public function setoranTransfer(int $id, bool $kunci = false): ?array
    {
        if ($kunci) {
            $row = $this->one('SELECT id, santri_id, nominal, referensi, catatan, status, transaksi_id, penugasan_id, pengurus_id, dicatat_oleh
                                 FROM bsa_setoran_transfer WHERE id = ? FOR UPDATE', [$id]);

            return $row === null ? null : [
                'id' => (int) $row['id'], 'santri_id' => (int) $row['santri_id'], 'nominal' => (int) $row['nominal'], 'referensi' => (string) $row['referensi'],
                'catatan' => $row['catatan'] === null ? null : (string) $row['catatan'], 'status' => (string) $row['status'],
                'transaksi_id' => $row['transaksi_id'] === null ? null : (int) $row['transaksi_id'], 'penugasan_id' => (int) $row['penugasan_id'],
                'pengurus_id' => (int) $row['pengurus_id'], 'dicatat_oleh' => (int) $row['dicatat_oleh'],
            ];
        }

        return $this->one(self::SETORAN . ' WHERE st.id = ?', [$id]);
    }

    /** Menandai setoran transfer sah dan menautkan baris ledger yang baru disisipkan. */
    public function sahkanSetoranTransfer(int $id, int $transaksiId, int $userId): void
    {
        if ($this->affected("UPDATE bsa_setoran_transfer SET status = 'Sah', transaksi_id = ?, diputus_oleh = ?, diputus_pada = NOW() WHERE id = ? AND status = 'Menunggu'",
            [$transaksiId, $userId, $id]) !== 1) {
            throw new BsaException('Setoran transfer berubah oleh permintaan lain. Tidak ada data yang diubah.', 409);
        }
    }

    public function tolakSetoranTransfer(int $id, int $userId, string $alasan): void
    {
        if ($this->affected("UPDATE bsa_setoran_transfer SET status = 'Ditolak', diputus_oleh = ?, diputus_pada = NOW(), alasan_tolak = ? WHERE id = ? AND status = 'Menunggu'",
            [$userId, $alasan, $id]) !== 1) {
            throw new BsaException('Setoran transfer berubah oleh permintaan lain. Tidak ada data yang diubah.', 409);
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function setoranTransferSantri(int $santriId): array
    {
        return $this->all(self::SETORAN . " WHERE st.santri_id = ? AND (st.status = 'Menunggu' OR st.created_at >= NOW() - INTERVAL 90 DAY) ORDER BY st.status = 'Menunggu' DESC, st.id DESC LIMIT 50", [$santriId]);
    }

    /**
     * Setoran transfer menurut hak pembaca (petugas: santri aktif dalam
     * cakupan kini; admin: semua).
     *
     * @param array{mode:string, user_id:int, jenjang:?array<int, string>} $hak
     * @return array{rows:array<int, array<string, mixed>>, total:int, page:int, perPage:int}
     */
    public function daftarSetoranTransfer(array $hak, ?string $status, string $q, int $page): array
    {
        $params = [];
        $sql = self::SETORAN . $this->bsa->joinUnit() . $this->tagihan->syaratHak($hak, $params);
        if ($status !== null) {
            $sql .= ' AND st.status = ?';
            $params[] = $status;
        }

        return PageQuery::fetch($this->db, $sql, $params, 'id DESC', max(1, $page), $q, ['nama_santri', 'nis', 'referensi']);
    }

    // =======================================================================
    // Mutasi tabungan lintas santri
    // =======================================================================

    /**
     * Baris ledger (seluruhnya sah) menurut hak pembaca dan saringan.
     *
     * @param array{mode:string, user_id:int, jenjang:?array<int, string>} $hak
     * @param array{jenis:?string, metode:?string, tanggal:?string} $saring
     * @return array{rows:array<int, array<string, mixed>>, total:int, page:int, perPage:int}
     */
    public function daftarMutasi(array $hak, array $saring, string $q, int $page): array
    {
        $params = [];
        $sql = 'SELECT x.id, x.santri_id, x.jenis, x.arah, x.nominal, x.saldo_sesudah, x.metode, x.tujuan, x.referensi, x.pembalik_dari_id, x.created_at,
                       COALESCE(p.nama, u.name) AS petugas_nama, s.nis, s.nama_santri,
                       (SELECT rv.id FROM bsa_tabungan_transaksi rv WHERE rv.pembalik_dari_id = x.id) AS dikoreksi_oleh_id
                  FROM bsa_tabungan_transaksi x
                  JOIN santri s ON s.id = x.santri_id
                  JOIN users u ON u.id = x.dicatat_oleh
                  LEFT JOIN pengurus p ON p.id = x.pengurus_id' . $this->bsa->joinUnit() . $this->tagihan->syaratHak($hak, $params) . " AND x.status = 'Sah'";
        if ($saring['jenis'] !== null) {
            $sql .= ' AND x.jenis = ?';
            $params[] = $saring['jenis'];
        }
        if ($saring['metode'] !== null) {
            $sql .= ' AND x.metode = ?';
            $params[] = $saring['metode'];
        }
        if ($saring['tanggal'] !== null) {
            $sql .= ' AND x.created_at >= ? AND x.created_at < ? + INTERVAL 1 DAY';
            $params[] = $saring['tanggal'] . ' 00:00:00';
            $params[] = $saring['tanggal'];
        }

        return PageQuery::fetch($this->db, $sql, $params, 'id DESC', max(1, $page), $q, ['nama_santri', 'nis']);
    }

    // =======================================================================
    // Rekonsiliasi harian
    // =======================================================================

    /**
     * Angka mentah rekonsiliasi satu hari menurut hak pembaca. Dihitung
     * dalam satu snapshot baca (START TRANSACTION WITH CONSISTENT SNAPSHOT)
     * agar seluruh angka berasal dari keadaan basis data yang sama.
     *
     * @param array{mode:string, user_id:int, jenjang:?array<int, string>} $hak
     * @return array{ledger:array<int, array{jenis:string, arah:string, metode:string, jumlah:int, nominal:int}>,
     *     bayar:array<int, array{sumber:string, jumlah:int, nominal:int}>, batal:array<int, array{sumber:string, jumlah:int, nominal:int}>,
     *     saldo_awal:int, saldo_akhir:int, saldo_akun:int, menunggu_setoran:array{jumlah:int, nominal:int},
     *     menunggu_bayar:array{jumlah:int, nominal:int}, rows:array<int, array<string, mixed>>}
     */
    public function rekonsiliasi(array $hak, string $tanggal, bool $hariIni): array
    {
        $awal = $tanggal . ' 00:00:00';
        $this->db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->db->query('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        try {
            $hasil = [
                'ledger' => $this->terbatas($hak, "SELECT x.jenis, x.arah, x.metode, COUNT(*) AS jumlah, COALESCE(SUM(x.nominal), 0) AS nominal
                    FROM bsa_tabungan_transaksi x JOIN santri s ON s.id = x.santri_id", " AND x.status = 'Sah' AND x.created_at >= ? AND x.created_at < ? + INTERVAL 1 DAY
                    GROUP BY x.jenis, x.arah, x.metode", [$awal, $tanggal]),
                // Pembayaran tagihan dihitung pada tanggal SAH-nya (verifikasi),
                // termasuk yang kemudian dibatalkan — pembatalannya dihitung
                // terpisah pada tanggal pembatalan.
                'bayar' => $this->terbatas($hak, 'SELECT b.sumber, COUNT(*) AS jumlah, COALESCE(SUM(b.nominal), 0) AS nominal
                    FROM bsa_pembayaran b JOIN santri s ON s.id = b.santri_id', ' AND b.diverifikasi_pada >= ? AND b.diverifikasi_pada < ? + INTERVAL 1 DAY GROUP BY b.sumber', [$awal, $tanggal]),
                'batal' => $this->terbatas($hak, 'SELECT b.sumber, COUNT(*) AS jumlah, COALESCE(SUM(b.nominal), 0) AS nominal
                    FROM bsa_pembayaran b JOIN bsa_pembayaran_pembatalan xb ON xb.pembayaran_id = b.id AND xb.status_sebelum = \'Sah\' JOIN santri s ON s.id = b.santri_id',
                    ' AND xb.created_at >= ? AND xb.created_at < ? + INTERVAL 1 DAY GROUP BY b.sumber', [$awal, $tanggal]),
                'saldo_awal' => (int) ($this->terbatas($hak, "SELECT COALESCE(SUM(CASE WHEN x.arah = 'Masuk' THEN x.nominal ELSE -x.nominal END), 0) AS n
                    FROM bsa_tabungan_transaksi x JOIN santri s ON s.id = x.santri_id", " AND x.status = 'Sah' AND x.created_at < ?", [$awal])[0]['n'] ?? 0),
                'saldo_akhir' => (int) ($this->terbatas($hak, "SELECT COALESCE(SUM(CASE WHEN x.arah = 'Masuk' THEN x.nominal ELSE -x.nominal END), 0) AS n
                    FROM bsa_tabungan_transaksi x JOIN santri s ON s.id = x.santri_id", " AND x.status = 'Sah' AND x.created_at < ? + INTERVAL 1 DAY", [$tanggal])[0]['n'] ?? 0),
                // Cerminan akun hanya bermakna untuk hari ini (saldo kini).
                'saldo_akun' => $hariIni ? (int) ($this->terbatas($hak, 'SELECT COALESCE(SUM(a.saldo), 0) AS n FROM bsa_tabungan_akun a JOIN santri s ON s.id = a.santri_id', '', [])[0]['n'] ?? 0) : 0,
                'menunggu_setoran' => $this->jumlahNominal($this->terbatas($hak, "SELECT COUNT(*) AS jumlah, COALESCE(SUM(st.nominal), 0) AS nominal
                    FROM bsa_setoran_transfer st JOIN santri s ON s.id = st.santri_id", " AND st.status = 'Menunggu'", [])),
                'menunggu_bayar' => $this->jumlahNominal($this->terbatas($hak, "SELECT COUNT(*) AS jumlah, COALESCE(SUM(b.nominal), 0) AS nominal
                    FROM bsa_pembayaran b JOIN santri s ON s.id = b.santri_id", " AND b.status = 'Menunggu'", [])),
                'rows' => $this->terbatas($hak, "SELECT * FROM (
                    SELECT 'tabungan' AS asal, x.id, x.created_at AS waktu, x.jenis AS jenis, x.arah, x.metode AS cara, x.nominal, x.santri_id, x.pembalik_dari_id AS rujukan_id,
                           NULL AS nomor_kuitansi, x.created_at AS dibuat
                      FROM bsa_tabungan_transaksi x WHERE x.status = 'Sah' AND x.created_at >= ? AND x.created_at < ? + INTERVAL 1 DAY
                    UNION ALL
                    SELECT 'pembayaran', b.id, b.diverifikasi_pada, 'Pembayaran', 'Masuk', b.sumber, b.nominal, b.santri_id, b.tabungan_transaksi_id, b.nomor_kuitansi, b.created_at
                      FROM bsa_pembayaran b WHERE b.diverifikasi_pada >= ? AND b.diverifikasi_pada < ? + INTERVAL 1 DAY
                    UNION ALL
                    SELECT 'pembatalan', b.id, xb.created_at, 'Pembatalan', 'Keluar', b.sumber, b.nominal, b.santri_id, b.tabungan_transaksi_id, b.nomor_kuitansi, b.created_at
                      FROM bsa_pembayaran b JOIN bsa_pembayaran_pembatalan xb ON xb.pembayaran_id = b.id AND xb.status_sebelum = 'Sah'
                     WHERE xb.created_at >= ? AND xb.created_at < ? + INTERVAL 1 DAY
                  ) r JOIN santri s ON s.id = r.santri_id", " ORDER BY r.waktu, FIELD(r.asal, 'tabungan', 'pembayaran', 'pembatalan'), r.id LIMIT 500", [$awal, $tanggal, $awal, $tanggal, $awal, $tanggal], ['s.nis', 's.nama_santri']),
            ];
            $this->db->commit();
        } catch (\Throwable $exception) {
            $this->db->rollback();
            throw $exception;
        }

        return $hasil;
    }

    /**
     * Menjalankan kueri agregat yang dibatasi hak atas santri alias `s` (unit
     * dari `joinUnit`). `$sebelum` = SELECT … FROM … JOIN santri s; `$sesudah`
     * = syarat tambahan (diawali AND) beserta GROUP BY/ORDER BY.
     *
     * @param array{mode:string, user_id:int, jenjang:?array<int, string>} $hak
     * @param array<int, mixed> $nilai parameter `$sesudah`
     * @param array<int, string> $kolomTambahan
     * @return array<int, array<string, mixed>>
     */
    private function terbatas(array $hak, string $sebelum, string $sesudah, array $nilai, array $kolomTambahan = []): array
    {
        $paramsHak = [];
        $syarat = $this->tagihan->syaratHak($hak, $paramsHak);
        if ($kolomTambahan !== []) {
            $sebelum = preg_replace('/^SELECT \*/', 'SELECT r.*, ' . implode(', ', $kolomTambahan), $sebelum, 1) ?? $sebelum;
        }
        // Parameter kueri dalam (sebelum syarat hak) lalu parameter hak lalu
        // parameter syarat sesudahnya, mengikuti urutan placeholder.
        $dalam = substr_count($sebelum, '?');
        $paramsDalam = array_slice($nilai, 0, $dalam);
        $paramsSesudah = array_slice($nilai, $dalam);

        return $this->all($sebelum . $this->bsa->joinUnit() . $syarat . $sesudah, [...$paramsDalam, ...$paramsHak, ...$paramsSesudah]);
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function jumlahNominal(array $rows): array
    {
        return ['jumlah' => (int) ($rows[0]['jumlah'] ?? 0), 'nominal' => (int) ($rows[0]['nominal'] ?? 0)];
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

    private function affected(string $sql, array $params): int
    {
        $statement = $this->prepare($sql);
        try {
            $this->run($statement, $params);

            return (int) $statement->affected_rows;
        } finally {
            $statement->close();
        }
    }

    private function prepare(string $sql): mysqli_stmt
    {
        $statement = $this->db->prepare($sql);
        if ($statement === false) {
            throw new BsaException('Data tabungan tidak tersedia.', 503);
        }

        return $statement;
    }

    /** Pemetaan galat sama dengan `BsaRepository` (kunci → 409, CHECK → 422). */
    private function run(mysqli_stmt $statement, array $params): void
    {
        if ($params !== []) {
            $types = implode('', array_map(static fn (mixed $v): string => is_int($v) ? 'i' : 's', $params));
            $statement->bind_param($types, ...$params);
        }
        if (!$statement->execute()) {
            if (in_array($statement->errno, [1020, 1205, 1213], true)) {
                throw new BsaException('Data sedang diproses permintaan lain. Tidak ada data yang diubah; coba lagi.', 409);
            }
            if (in_array($statement->errno, [3819, 4025], true)) {
                throw new BsaException('Transaksi ditolak aturan saldo/nominal. Tidak ada data yang diubah.', 422);
            }
            throw new BsaException('Data tabungan tidak dapat disimpan.', 503, (int) $statement->errno);
        }
    }
}
