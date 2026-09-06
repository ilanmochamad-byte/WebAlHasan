<?php

declare(strict_types=1);

namespace App\MasterData;

use mysqli;
use mysqli_stmt;
use RuntimeException;

/**
 * Repository terpusat untuk penempatan kelas dan kamar santri
 * (keputusan pengguna 6 September 2026).
 *
 * Sebelum paket ini, seluruh query kamar ditulis langsung di
 * `admin/admin_santri.php` dengan interpolasi string dan pola hapus-lalu-sisip.
 * Kelas ini memindahkan SELURUH query penempatan ke satu tempat dengan aturan
 * keras:
 *
 *   - hanya prepared statement; tidak ada nilai GET/POST yang masuk ke SQL;
 *   - nama tabel, kolom, dan urutan hanya berasal dari konstanta di berkas ini;
 *   - baris kamar dikunci dengan `FOR UPDATE` sebelum kapasitas dihitung;
 *   - baris santri dikunci lebih dahulu sehingga satu santri tidak dapat
 *     ditempatkan dua kali secara bersamaan meskipun `plotting_kamar` warisan
 *     V1 belum memiliki constraint unik;
 *   - urutan penguncian selalu SAMA (santri menaik, lalu kamar menaik) supaya
 *     dua permintaan massal tidak saling mengunci (deadlock).
 *
 * Kelas ini tidak melakukan otorisasi dan tidak menulis audit: keduanya milik
 * `PenempatanService` dan halaman pemanggil.
 */
final class PenempatanRepository
{
    /** Kolom pencarian daftar penempatan. Konstanta, bukan input pengguna. */
    private const KOLOM_CARI = ['s.nama_santri', 's.nis'];

    public function __construct(private mysqli $db)
    {
    }

    public function db(): mysqli
    {
        return $this->db;
    }

    // -----------------------------------------------------------------------
    // Pembacaan referensi
    // -----------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    public function activeYear(): ?array
    {
        return $this->one("SELECT id, tahun, semester, status FROM tahun_ajaran WHERE status = 'Aktif' AND archived_at IS NULL LIMIT 1");
    }

    /** @return array<string, mixed>|null */
    public function santri(int $id): ?array
    {
        return $this->one('SELECT id, nis, nama_santri, jenis_kelamin, sekolah_saat_ini, is_active, archived_at FROM santri WHERE id = ?', [$id]);
    }

    /** Kelas yang boleh dipakai penempatan baru: ada, aktif, dan tidak diarsipkan. */
    public function assignableClass(int $id): ?array
    {
        return $this->one('SELECT id, nama_kelas, jenjang, is_active, archived_at FROM kelas WHERE id = ? AND is_active = 1 AND archived_at IS NULL', [$id]);
    }

    /**
     * Kamar yang boleh dipakai penempatan baru.
     *
     * Tabel `kamar` warisan V1 tidak memiliki kolom `is_active`/`archived_at`,
     * sehingga "aktif" untuk kamar berarti barisnya ada dan kapasitasnya masih
     * bilangan bulat positif. Fakta ini didokumentasikan pada
     * `docs/penempatan-santri/aturan-bisnis.md` supaya tidak terlihat seperti
     * pemeriksaan yang terlewat.
     */
    public function assignableRoom(int $id): ?array
    {
        return $this->one('SELECT id, nama_kamar, kapasitas FROM kamar WHERE id = ? AND kapasitas >= 1', [$id]);
    }

    /** @return array<int, array<string, mixed>> */
    public function classOptions(): array
    {
        return $this->all('SELECT id, nama_kelas, jenjang FROM kelas WHERE is_active = 1 AND archived_at IS NULL ORDER BY jenjang, nama_kelas, id');
    }

    /** @return array<int, array<string, mixed>> */
    public function roomOptions(int $yearId): array
    {
        return $this->all(
            'SELECT km.id, km.nama_kamar, km.kapasitas,
                    (SELECT COUNT(*) FROM plotting_kamar pk WHERE pk.id_kamar = km.id AND pk.id_tahun = ?) AS terisi
               FROM kamar km ORDER BY km.nama_kamar, km.id',
            [$yearId]
        );
    }

    /** @return array<int, string> */
    public function schoolOptions(): array
    {
        $rows = $this->all("SELECT DISTINCT sekolah_saat_ini FROM santri WHERE is_active = 1 AND archived_at IS NULL AND TRIM(COALESCE(sekolah_saat_ini, '')) <> '' ORDER BY sekolah_saat_ini");

        return array_map(static fn (array $row): string => (string) $row['sekolah_saat_ini'], $rows);
    }

    // -----------------------------------------------------------------------
    // Penguncian (selalu dipanggil di dalam transaksi)
    // -----------------------------------------------------------------------

    /**
     * Mengunci baris santri menurut ID menaik.
     *
     * Kunci ini yang menjaga aturan "satu santri hanya satu kamar per tahun
     * ajaran" tanpa perubahan skema: dua permintaan untuk santri yang sama
     * tidak dapat berjalan bersamaan, sehingga tidak ada baris ganda.
     *
     * @param array<int, int> $ids
     * @return array<int, array<string, mixed>> ID santri => baris
     */
    public function lockSantri(array $ids): array
    {
        return $this->keyed(
            $this->allIn('SELECT id, nis, nama_santri, jenis_kelamin, is_active, archived_at FROM santri WHERE id IN (%s) ORDER BY id FOR UPDATE', $ids),
            'id'
        );
    }

    /**
     * Membaca baris santri TANPA mengunci. Hanya untuk layar tinjauan; setiap
     * penerapan selalu membaca ulang dengan `lockSantri()` di dalam transaksi.
     *
     * @param array<int, int> $ids
     * @return array<int, array<string, mixed>> ID santri => baris
     */
    public function santriByIds(array $ids): array
    {
        return $this->keyed(
            $this->allIn('SELECT id, nis, nama_santri, jenis_kelamin, is_active, archived_at FROM santri WHERE id IN (%s) ORDER BY id', $ids),
            'id'
        );
    }

    /**
     * Mengunci baris kamar menurut ID menaik (dipanggil SETELAH lockSantri).
     *
     * @param array<int, int> $ids
     * @return array<int, array<string, mixed>> ID kamar => baris
     */
    public function lockRooms(array $ids): array
    {
        return $this->keyed(
            $this->allIn('SELECT id, nama_kamar, kapasitas FROM kamar WHERE id IN (%s) ORDER BY id FOR UPDATE', $ids),
            'id'
        );
    }

    // -----------------------------------------------------------------------
    // Penempatan kamar
    // -----------------------------------------------------------------------

    /**
     * Seluruh baris kamar milik sekumpulan santri pada satu tahun ajaran.
     *
     * Sengaja mengembalikan SELURUH baris, bukan satu: bila data produksi
     * memuat duplikasi warisan, layanan harus melihatnya dan menolak bekerja,
     * bukan diam-diam memilih salah satu.
     *
     * @param array<int, int> $santriIds
     * @return array<int, array<int, array<string, mixed>>> ID santri => daftar baris
     */
    public function roomAssignments(array $santriIds, int $yearId): array
    {
        if ($santriIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($santriIds), '?'));
        $rows = $this->all(
            'SELECT pk.id, pk.id_santri, pk.id_kamar, pk.id_tahun, km.nama_kamar, km.kapasitas
               FROM plotting_kamar pk
               LEFT JOIN kamar km ON km.id = pk.id_kamar
              WHERE pk.id_tahun = ? AND pk.id_santri IN (' . $placeholders . ')
              ORDER BY pk.id_santri, pk.id',
            [$yearId, ...array_values($santriIds)]
        );
        $hasil = [];
        foreach ($rows as $row) {
            $hasil[(int) $row['id_santri']][] = $row;
        }

        return $hasil;
    }

    /**
     * Jumlah penghuni terkini per kamar pada satu tahun ajaran.
     *
     * @param array<int, int> $roomIds
     * @return array<int, int> ID kamar => jumlah penghuni
     */
    public function roomOccupancy(array $roomIds, int $yearId): array
    {
        if ($roomIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($roomIds), '?'));
        $rows = $this->all(
            'SELECT id_kamar, COUNT(*) AS jumlah FROM plotting_kamar WHERE id_tahun = ? AND id_kamar IN (' . $placeholders . ') GROUP BY id_kamar',
            [$yearId, ...array_values($roomIds)]
        );
        $hasil = array_fill_keys(array_map('intval', $roomIds), 0);
        foreach ($rows as $row) {
            $hasil[(int) $row['id_kamar']] = (int) $row['jumlah'];
        }

        return $hasil;
    }

    /**
     * Memindahkan penempatan yang sudah ada ke kamar lain.
     *
     * ID baris DIPERTAHANKAN — inilah alasan perpindahan tidak lagi memakai
     * pola hapus-lalu-sisip: penunjuk luar (laporan, audit lama) tetap sahih.
     */
    public function moveRoomAssignment(int $assignmentId, int $roomId, int $yearId): void
    {
        // Klausa `id_tahun` adalah jaring pengaman: sekalipun ID penempatan
        // kelak berasal dari tempat lain, pernyataan ini tidak akan pernah
        // menyentuh baris tahun ajaran lain.
        $this->execute('UPDATE plotting_kamar SET id_kamar = ? WHERE id = ? AND id_tahun = ?', [$roomId, $assignmentId, $yearId]);
    }

    public function createRoomAssignment(int $santriId, int $roomId, int $yearId): int
    {
        return $this->insert('INSERT INTO plotting_kamar (id_santri, id_kamar, id_tahun) VALUES (?, ?, ?)', [$santriId, $roomId, $yearId]);
    }

    /**
     * Mengeluarkan santri dari kamar pada TAHUN AJARAN AKTIF.
     *
     * `plotting_kamar` warisan V1 tidak punya kolom status, sehingga "keluar"
     * hanya dapat diwakili dengan menghapus baris tahun berjalan. Baris tahun
     * ajaran LAIN tidak pernah disentuh (klausa `id_tahun`), dan nilai sebelum
     * penghapusan selalu tercatat pada `audit_logs` sehingga tetap dapat
     * ditelusuri.
     */
    public function releaseRoomAssignment(int $assignmentId, int $yearId): void
    {
        $this->execute('DELETE FROM plotting_kamar WHERE id = ? AND id_tahun = ?', [$assignmentId, $yearId]);
    }

    // -----------------------------------------------------------------------
    // Penempatan kelas
    // -----------------------------------------------------------------------

    /**
     * Penempatan kelas aktif milik sekumpulan santri pada satu tahun ajaran.
     *
     * @param array<int, int> $santriIds
     * @return array<int, array<string, mixed>> ID santri => baris aktif
     */
    public function activeClassAssignments(array $santriIds, int $yearId): array
    {
        if ($santriIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($santriIds), '?'));

        return $this->keyed(
            $this->all(
                "SELECT pk.id, pk.id_santri, pk.id_kelas, pk.id_tahun, pk.tanggal_mulai, pk.status, k.nama_kelas, k.jenjang
                   FROM plotting_kelas pk
                   LEFT JOIN kelas k ON k.id = pk.id_kelas
                  WHERE pk.id_tahun = ? AND pk.status = 'Aktif' AND pk.id_santri IN (" . $placeholders . ')
                  ORDER BY pk.id_santri, pk.id',
                [$yearId, ...array_values($santriIds)]
            ),
            'id_santri'
        );
    }

    // -----------------------------------------------------------------------
    // Daftar halaman penempatan
    // -----------------------------------------------------------------------

    /**
     * Daftar santri beserta kelas dan kamar aktifnya, dengan filter dan
     * pagination server. Seluruh nilai filter dikirim sebagai parameter terikat.
     *
     * @param array<string, mixed> $filters
     * @return array{rows:array<int, array<string, mixed>>, total:int, page:int, perPage:int}
     */
    public function listPage(array $filters, int $yearId, int $page, int $perPage): array
    {
        $perPage = max(10, min(100, $perPage));
        [$where, $params] = $this->listWhere($filters, $yearId);

        $from = ' FROM santri s'
            . " LEFT JOIN plotting_kelas pk ON pk.id_santri = s.id AND pk.id_tahun = ? AND pk.status = 'Aktif'"
            . ' LEFT JOIN kelas k ON k.id = pk.id_kelas'
            . ' LEFT JOIN plotting_kamar pkm ON pkm.id = (SELECT MIN(p2.id) FROM plotting_kamar p2 WHERE p2.id_santri = s.id AND p2.id_tahun = ?)'
            . ' LEFT JOIN kamar km ON km.id = pkm.id_kamar'
            . $where;

        $head = [$yearId, $yearId];
        $total = (int) ($this->one('SELECT COUNT(*) AS jumlah' . $from, [...$head, ...$params])['jumlah'] ?? 0);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));

        $rows = $this->all(
            'SELECT s.id, s.nis, s.nama_santri, s.jenis_kelamin, s.sekolah_saat_ini,'
            . ' pk.id AS plotting_kelas_id, pk.id_kelas, k.nama_kelas, k.jenjang,'
            . ' pkm.id AS plotting_kamar_id, pkm.id_kamar, km.nama_kamar, km.kapasitas,'
            . ' (SELECT COUNT(*) FROM plotting_kamar p3 WHERE p3.id_santri = s.id AND p3.id_tahun = ?) AS jumlah_kamar'
            . $from . ' ORDER BY s.nama_santri, s.id LIMIT ? OFFSET ?',
            [$yearId, ...$head, ...$params, $perPage, ($page - 1) * $perPage]
        );

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'perPage' => $perPage];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0:string, 1:array<int, mixed>}
     */
    private function listWhere(array $filters, int $yearId): array
    {
        $status = (string) ($filters['status'] ?? '');
        // Daftar biasa hanya memuat santri aktif. Filter khusus
        // `nonaktif_berkamar` sengaja membalik syarat itu supaya admin dapat
        // menemukan — dan mengeluarkan — santri nonaktif/arsip yang masih
        // menempati kamar; tanpanya tempat tidur mereka terkunci selamanya.
        $parts = $status === 'nonaktif_berkamar'
            ? ['(s.is_active = 0 OR s.archived_at IS NOT NULL)', 'pkm.id IS NOT NULL']
            : ['s.is_active = 1', 's.archived_at IS NULL'];
        $params = [];

        $q = (string) ($filters['q'] ?? '');
        if ($q !== '') {
            $cari = [];
            foreach (self::KOLOM_CARI as $column) {
                $cari[] = $column . ' LIKE ?';
                $params[] = '%' . $q . '%';
            }
            $parts[] = '(' . implode(' OR ', $cari) . ')';
        }
        if (in_array($filters['jk'] ?? '', ['L', 'P'], true)) {
            $parts[] = 's.jenis_kelamin = ?';
            $params[] = (string) $filters['jk'];
        }
        if (($filters['sekolah'] ?? '') !== '') {
            $parts[] = 's.sekolah_saat_ini = ?';
            $params[] = (string) $filters['sekolah'];
        }
        if ((int) ($filters['kelas_id'] ?? 0) > 0) {
            $parts[] = 'pk.id_kelas = ?';
            $params[] = (int) $filters['kelas_id'];
        }
        if ((int) ($filters['kamar_id'] ?? 0) > 0) {
            // EXISTS, bukan `pkm.id_kamar = ?`: santri yang datanya berkonflik
            // (lebih dari satu kamar pada tahun yang sama) hanya terwakili baris
            // ber-ID terkecil pada JOIN, sehingga filter berbasis JOIN akan
            // menyembunyikannya dari sisi kamar yang lain.
            $parts[] = 'EXISTS (SELECT 1 FROM plotting_kamar p4 WHERE p4.id_santri = s.id AND p4.id_tahun = ? AND p4.id_kamar = ?)';
            $params[] = $yearId;
            $params[] = (int) $filters['kamar_id'];
        }
        if ($status === 'tanpa_kelas') {
            $parts[] = 'pk.id IS NULL';
        } elseif ($status === 'tanpa_kamar') {
            $parts[] = 'pkm.id IS NULL';
        } elseif ($status === 'tanpa_keduanya') {
            $parts[] = 'pk.id IS NULL AND pkm.id IS NULL';
        }

        return [' WHERE ' . implode(' AND ', $parts), $params];
    }

    // -----------------------------------------------------------------------
    // Preflight: laporan konflik data. Membaca saja, tidak pernah memperbaiki.
    // -----------------------------------------------------------------------

    /** Santri dengan lebih dari satu kamar pada tahun ajaran yang sama. */
    public function conflictDuplicateRoom(int $limit = 200): array
    {
        return $this->all(
            'SELECT pk.id_santri, pk.id_tahun, COUNT(*) AS jumlah, s.nis, s.nama_santri, ta.tahun, ta.semester
               FROM plotting_kamar pk
               LEFT JOIN santri s ON s.id = pk.id_santri
               LEFT JOIN tahun_ajaran ta ON ta.id = pk.id_tahun
              GROUP BY pk.id_santri, pk.id_tahun, s.nis, s.nama_santri, ta.tahun, ta.semester
             HAVING COUNT(*) > 1
              ORDER BY COUNT(*) DESC, pk.id_santri LIMIT ?',
            [$this->limit($limit)]
        );
    }

    /** Baris penempatan kamar yang menunjuk santri, kamar, atau tahun yang tidak ada. */
    public function conflictOrphanRoom(int $limit = 200): array
    {
        return $this->all(
            'SELECT pk.id, pk.id_santri, pk.id_kamar, pk.id_tahun,
                    (s.id IS NULL) AS santri_hilang, (km.id IS NULL) AS kamar_hilang, (ta.id IS NULL) AS tahun_hilang
               FROM plotting_kamar pk
               LEFT JOIN santri s ON s.id = pk.id_santri
               LEFT JOIN kamar km ON km.id = pk.id_kamar
               LEFT JOIN tahun_ajaran ta ON ta.id = pk.id_tahun
              WHERE s.id IS NULL OR km.id IS NULL OR ta.id IS NULL
              ORDER BY pk.id LIMIT ?',
            [$this->limit($limit)]
        );
    }

    /** Baris penempatan kelas yang menunjuk santri, kelas, atau tahun yang tidak ada. */
    public function conflictOrphanClass(int $limit = 200): array
    {
        return $this->all(
            'SELECT pk.id, pk.id_santri, pk.id_kelas, pk.id_tahun, pk.status,
                    (s.id IS NULL) AS santri_hilang, (k.id IS NULL) AS kelas_hilang, (ta.id IS NULL) AS tahun_hilang
               FROM plotting_kelas pk
               LEFT JOIN santri s ON s.id = pk.id_santri
               LEFT JOIN kelas k ON k.id = pk.id_kelas
               LEFT JOIN tahun_ajaran ta ON ta.id = pk.id_tahun
              WHERE s.id IS NULL OR k.id IS NULL OR ta.id IS NULL
              ORDER BY pk.id LIMIT ?',
            [$this->limit($limit)]
        );
    }

    /** Kamar yang penghuninya melebihi kapasitas pada satu tahun ajaran. */
    public function conflictOverCapacity(int $limit = 200): array
    {
        return $this->all(
            'SELECT km.id, km.nama_kamar, km.kapasitas, pk.id_tahun, COUNT(*) AS terisi, ta.tahun, ta.semester
               FROM plotting_kamar pk
               JOIN kamar km ON km.id = pk.id_kamar
               LEFT JOIN tahun_ajaran ta ON ta.id = pk.id_tahun
              GROUP BY km.id, km.nama_kamar, km.kapasitas, pk.id_tahun, ta.tahun, ta.semester
             HAVING COUNT(*) > km.kapasitas
              ORDER BY COUNT(*) - km.kapasitas DESC, km.nama_kamar, km.id LIMIT ?',
            [$this->limit($limit)]
        );
    }

    /** Santri dengan lebih dari satu kelas berstatus Aktif pada tahun yang sama. */
    public function conflictDuplicateActiveClass(int $limit = 200): array
    {
        return $this->all(
            "SELECT pk.id_santri, pk.id_tahun, COUNT(*) AS jumlah, s.nis, s.nama_santri
               FROM plotting_kelas pk
               LEFT JOIN santri s ON s.id = pk.id_santri
              WHERE pk.status = 'Aktif'
              GROUP BY pk.id_santri, pk.id_tahun, s.nis, s.nama_santri
             HAVING COUNT(*) > 1
              ORDER BY COUNT(*) DESC, pk.id_santri LIMIT ?",
            [$this->limit($limit)]
        );
    }

    /**
     * Nilai `binlog_format` server.
     *
     * `STATEMENT` membuat MariaDB menolak setiap penulisan InnoDB di dalam
     * transaksi READ COMMITTED (galat 1665), sehingga seluruh penempatan gagal.
     * Diperiksa preflight supaya ketahuan sebelum rilis, bukan saat admin
     * pertama kali menekan tombol.
     */
    public function binlogFormat(): ?string
    {
        $row = $this->one("SHOW VARIABLES LIKE 'binlog_format'");

        return $row === null ? null : (string) ($row['Value'] ?? '');
    }

    /** Jumlah tahun ajaran berstatus Aktif dan tidak diarsipkan. */
    public function activeYearCount(): int
    {
        return (int) ($this->one("SELECT COUNT(*) AS jumlah FROM tahun_ajaran WHERE status = 'Aktif' AND archived_at IS NULL")['jumlah'] ?? 0);
    }

    /** Santri aktif yang belum punya kelas maupun kamar pada tahun ajaran aktif. */
    public function countWithoutPlacement(int $yearId): array
    {
        return (array) $this->one(
            "SELECT
                (SELECT COUNT(*) FROM santri s WHERE s.is_active = 1 AND s.archived_at IS NULL) AS santri_aktif,
                (SELECT COUNT(*) FROM santri s WHERE s.is_active = 1 AND s.archived_at IS NULL
                    AND NOT EXISTS (SELECT 1 FROM plotting_kelas pk WHERE pk.id_santri = s.id AND pk.id_tahun = ? AND pk.status = 'Aktif')) AS tanpa_kelas,
                (SELECT COUNT(*) FROM santri s WHERE s.is_active = 1 AND s.archived_at IS NULL
                    AND NOT EXISTS (SELECT 1 FROM plotting_kamar pk WHERE pk.id_santri = s.id AND pk.id_tahun = ?)) AS tanpa_kamar,
                (SELECT COUNT(DISTINCT pk.id_santri) FROM plotting_kamar pk
                   JOIN santri s ON s.id = pk.id_santri
                  WHERE pk.id_tahun = ? AND (s.is_active = 0 OR s.archived_at IS NOT NULL)) AS nonaktif_berkamar",
            [$yearId, $yearId, $yearId]
        );
    }

    // -----------------------------------------------------------------------
    // Primitif query
    // -----------------------------------------------------------------------

    private function limit(int $limit): int
    {
        return max(1, min(1000, $limit));
    }

    /**
     * @param array<int, int> $ids
     * @return array<int, array<string, mixed>>
     */
    private function allIn(string $template, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        if ($ids === []) {
            return [];
        }

        return $this->all(sprintf($template, implode(',', array_fill(0, count($ids), '?'))), $ids);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function keyed(array $rows, string $column): array
    {
        $hasil = [];
        foreach ($rows as $row) {
            $hasil[(int) $row[$column]] = $row;
        }

        return $hasil;
    }

    private function execute(string $sql, array $params = []): void
    {
        $statement = $this->db->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Query penempatan tidak dapat disiapkan.');
        }
        if (!$this->run($statement, $params)) {
            $errno = $statement->errno;
            $error = $statement->error;
            $statement->close();
            $this->fail($errno, $error);
        }
        $statement->close();
    }

    private function insert(string $sql, array $params): int
    {
        $this->execute($sql, $params);

        return (int) $this->db->insert_id;
    }

    private function one(string $sql, array $params = []): ?array
    {
        return $this->all($sql, $params)[0] ?? null;
    }

    private function all(string $sql, array $params = []): array
    {
        $statement = $this->db->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Query penempatan tidak dapat disiapkan.');
        }
        if (!$this->run($statement, $params)) {
            $errno = $statement->errno;
            $error = $statement->error;
            $statement->close();
            $this->fail($errno, $error);
        }
        $result = $statement->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $statement->close();

        return $rows;
    }

    /**
     * Galat basis data tidak pernah dikirim mentah ke pengguna.
     * Konflik kunci (deadlock / lock wait) diterjemahkan menjadi pesan yang
     * meminta admin mencoba lagi; sisanya menjadi pesan umum.
     */
    private function fail(int $errno, string $error): never
    {
        if (in_array($errno, PenempatanService::KODE_KONFLIK_KUNCI, true)) {
            throw new PenempatanConflictException(
                'Penempatan dibatalkan karena ada perubahan bersamaan pada santri atau kamar yang sama. '
                . 'Tidak ada satu pun perubahan yang tersimpan. Muat ulang halaman lalu coba lagi.'
            );
        }
        error_log('Query penempatan gagal (' . $errno . '): ' . $error);

        throw new RuntimeException('Perubahan penempatan gagal disimpan.');
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
