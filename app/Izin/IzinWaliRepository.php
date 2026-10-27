<?php

declare(strict_types=1);

namespace App\Izin;

use mysqli;
use mysqli_stmt;
use RuntimeException;

/**
 * Akses data pengajuan izin wali (PRD V7 Fase 1).
 *
 * Baris wali tinggal di `izin_pengajuan` yang sama dengan jalur pengurus V2
 * (satu identitas kanonis). Kelas ini hanya menyentuh kolom/tabel V7 dan
 * menyisipkan baris berasal `Wali`; penguncian santri, pemeriksaan benturan
 * tanggal, dan riwayat status memakai `IzinWriteRepository` V2 agar kedua
 * jalur berbagi satu aturan.
 *
 * Seluruh query memakai prepared statement dan tidak membaca variabel global.
 */
final class IzinWaliRepository
{
    public const STATUS_MENUNGGU_PEMBIMBING = 'Menunggu Pembimbing';
    public const STATUS_PERLU_PENETAPAN_PEMBIMBING = 'Perlu Penetapan Pembimbing';
    public const STATUS_PERLU_PERBAIKAN = 'Perlu Perbaikan Wali';
    public const STATUS_DITOLAK_PEMBIMBING = 'Ditolak Pembimbing';

    /** Tahap V7 sebelum penerusan pembimbing. */
    public const STATUS_TAHAP_V7 = [
        self::STATUS_MENUNGGU_PEMBIMBING,
        self::STATUS_PERLU_PENETAPAN_PEMBIMBING,
        self::STATUS_PERLU_PERBAIKAN,
        self::STATUS_DITOLAK_PEMBIMBING,
    ];

    /** Status yang masih boleh ditetapkan/ditetapkan ulang pembimbingnya oleh admin. */
    public const STATUS_PENETAPAN = [self::STATUS_MENUNGGU_PEMBIMBING, self::STATUS_PERLU_PENETAPAN_PEMBIMBING];

    public function __construct(private mysqli $db)
    {
    }

    public function db(): mysqli
    {
        return $this->db;
    }

    /**
     * Transaksi READ COMMITTED: MariaDB 12 (`innodb_snapshot_isolation`)
     * menggagalkan pembacaan terkunci di REPEATABLE READ dengan galat 1020 bila
     * baris diubah transaksi lain sesudah snapshot terbentuk. Kebenaran tetap
     * bertumpu pada kunci baris santri/pengajuan dan versi optimistis.
     */
    public function begin(): void
    {
        $this->db->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        if (!$this->db->begin_transaction()) {
            throw new RuntimeException('Transaksi pengajuan wali tidak dapat dimulai.');
        }
    }

    public function commit(): void
    {
        if (!$this->db->commit()) {
            throw new RuntimeException('Transaksi pengajuan wali tidak dapat disimpan.');
        }
    }

    public function rollback(): void
    {
        $this->db->rollback();
    }

    /**
     * Kunci tulis baris akun pelaku pada awal transaksi mutasi.
     *
     * Kunci idempotensi bersifat per akun. Tanpa kunci ini, beberapa kiriman
     * bersamaan dengan kunci yang sama menunggu di indeks unik, lalu sesudah
     * kiriman pertama commit masing-masing memegang kunci baca atas baris
     * duplikat dan saling menunggu saat menaikkannya ke `FOR UPDATE` — InnoDB
     * memutus deadlock itu dengan menggagalkan sebagian kiriman (uji konkurensi
     * V7 Fase 1: 6 dari 8 klik ganda gagal). Dengan menserialkan mutasi V7 per
     * akun, kiriman berikutnya langsung menemukan klaim yang sudah selesai dan
     * memutar ulang hasilnya.
     */
    public function kunciAkun(int $userId): void
    {
        $this->one('SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId]);
    }

    // -----------------------------------------------------------------------
    // Hak wali (dibaca ulang di dalam transaksi dengan kunci baca)
    // -----------------------------------------------------------------------

    /**
     * Role akun AKTIF menurut basis data (akun nonaktif → tanpa role).
     *
     * @return array<int, string>
     */
    public function rolesAkun(int $userId): array
    {
        return array_map(static fn (array $row): string => (string) $row['slug'], $this->all(
            'SELECT r.slug FROM users u
               JOIN user_roles ur ON ur.user_id = u.id
               JOIN roles r ON r.id = ur.role_id
              WHERE u.id = ? AND u.is_active = 1
              ORDER BY r.slug',
            [$userId]
        ));
    }

    /** Wali aktif yang terhubung dengan akun aktif ber-role orang tua. */
    public function waliAkun(int $userId, bool $lock = false): ?int
    {
        $row = $this->one(
            "SELECT w.id
               FROM users u
               JOIN wali w ON w.id = u.wali_id AND w.is_active = 1 AND w.archived_at IS NULL
              WHERE u.id = ? AND u.is_active = 1
                AND EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id AND r.slug = 'orang_tua'
                             WHERE ur.user_id = u.id)
              LIMIT 1" . ($lock ? ' LOCK IN SHARE MODE' : ''),
            [$userId]
        );

        return $row === null ? null : (int) $row['id'];
    }

    /** Relasi `santri_wali` aktif. `$lock` = kunci baca agar pencabutan bersamaan menunggu. */
    public function relasiAktif(int $waliId, int $santriId, bool $lock = false): bool
    {
        return $this->one(
            'SELECT sw.id FROM santri_wali sw
               JOIN wali w ON w.id = sw.wali_id AND w.is_active = 1 AND w.archived_at IS NULL
              WHERE sw.wali_id = ? AND sw.santri_id = ? AND sw.archived_at IS NULL
              LIMIT 1' . ($lock ? ' LOCK IN SHARE MODE' : ''),
            [$waliId, $santriId]
        ) !== null;
    }

    /**
     * Anak berelasi aktif yang boleh diajukan (santri aktif).
     *
     * @return array<int, array<string, mixed>>
     */
    public function anakAktif(int $waliId): array
    {
        return $this->all(
            'SELECT s.id, s.nis, s.nama_santri, sw.hubungan
               FROM santri_wali sw
               JOIN wali w ON w.id = sw.wali_id AND w.is_active = 1 AND w.archived_at IS NULL
               JOIN santri s ON s.id = sw.santri_id AND s.is_active = 1 AND s.archived_at IS NULL
              WHERE sw.wali_id = ? AND sw.archived_at IS NULL
              ORDER BY s.nama_santri, s.id',
            [$waliId]
        );
    }

    // -----------------------------------------------------------------------
    // Mutasi
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $data
     */
    public function insertPengajuanWali(array $data): int
    {
        $statement = $this->db->prepare(
            "INSERT INTO izin_pengajuan
                (asal_pengajuan, santri_id, wali_id, diajukan_oleh_user_id, pengurus_id, murobi_guru_id,
                 pembimbing_assignment_id, pembimbing_pengurus_id, pembimbing_routing_kandidat,
                 pembimbing_routing_catatan, pembimbing_routing_pada, tahun_ajaran_id, tgl_izin, tgl_kembali,
                 alasan, catatan_wali, status, version, idempotency_key, diajukan_pada, is_legacy)
             VALUES ('Wali', ?, ?, ?, NULL, NULL, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, 1, ?, NOW(), 0)"
        );
        if ($statement === false) {
            throw new RuntimeException('Pengajuan wali tidak dapat disiapkan.');
        }
        $ok = $this->run($statement, [
            $data['santri_id'], $data['wali_id'], $data['user_id'],
            $data['pembimbing_assignment_id'], $data['pembimbing_pengurus_id'], $data['jumlah_kandidat'],
            $data['routing_catatan'], $data['tahun_ajaran_id'], $data['tgl_izin'], $data['tgl_kembali'],
            $data['alasan'], $data['catatan'], $data['status'], $data['idempotency_key'],
        ]);
        if (!$ok) {
            $errno = $statement->errno;
            $statement->close();
            throw $this->dbError($errno, 'Pengajuan wali gagal disimpan.');
        }
        $id = (int) $statement->insert_id;
        $statement->close();

        return $id;
    }

    /**
     * Penetapan pembimbing dengan versi optimistis. False = versi/status sudah
     * berubah (pemanggil menjawab 409).
     */
    public function applyPenetapan(int $id, int $pengurusId, int $assignmentId, int $actorUserId, int $expectedVersion): bool
    {
        $placeholders = implode(',', array_fill(0, count(self::STATUS_PENETAPAN), '?'));

        return $this->execute(
            "UPDATE izin_pengajuan
                SET status = ?, pembimbing_pengurus_id = ?, pembimbing_assignment_id = ?,
                    pembimbing_ditetapkan_oleh_user_id = ?, pembimbing_ditetapkan_pada = NOW(),
                    version = version + 1, updated_at = NOW()
              WHERE id = ? AND asal_pengajuan = 'Wali' AND version = ? AND status IN ($placeholders)",
            array_merge(
                [self::STATUS_MENUNGGU_PEMBIMBING, $pengurusId, $assignmentId, $actorUserId, $id, $expectedVersion],
                self::STATUS_PENETAPAN
            )
        ) === 1;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insertPenetapan(array $data): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO izin_pembimbing_penetapan
                (pengajuan_id, pembimbing_sebelum_pengurus_id, pembimbing_pengurus_id, pembimbing_assignment_id,
                 status_sebelum, jumlah_kandidat, alasan, ditetapkan_oleh_user_id, versi_sebelum, versi_sesudah, ditetapkan_pada)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        if ($statement === false) {
            throw new RuntimeException('Riwayat penetapan pembimbing tidak dapat disiapkan.');
        }
        $ok = $this->run($statement, [
            $data['pengajuan_id'], $data['sebelum'], $data['pengurus_id'], $data['assignment_id'],
            $data['status_sebelum'], $data['jumlah_kandidat'], $data['alasan'], $data['actor'],
            $data['versi_sebelum'], $data['versi_sebelum'] + 1,
        ]);
        if (!$ok) {
            $errno = $statement->errno;
            $statement->close();
            throw $this->dbError($errno, 'Riwayat penetapan pembimbing gagal disimpan.');
        }
        $id = (int) $statement->insert_id;
        $statement->close();

        return $id;
    }

    // -----------------------------------------------------------------------
    // Pembacaan
    // -----------------------------------------------------------------------

    /**
     * Satu baris wali lengkap (tanpa saringan hak; pemanggil wajib memeriksa).
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id, string $onDate): ?array
    {
        return $this->one($this->select($onDate)['sql'] . ' WHERE p.id = ? AND p.asal_pengajuan = \'Wali\' LIMIT 1',
            array_merge($this->select($onDate)['params'], [$id]));
    }

    /**
     * Pengajuan wali untuk anak berelasi aktif seorang wali.
     *
     * @return array{rows:array<int,array<string,mixed>>, total:int}
     */
    public function daftarWali(int $waliId, ?int $santriId, string $onDate, int $page, int $perPage): array
    {
        $where = " WHERE p.asal_pengajuan = 'Wali' AND p.santri_id IN (
                        SELECT sw.santri_id FROM santri_wali sw
                          JOIN wali w ON w.id = sw.wali_id AND w.is_active = 1 AND w.archived_at IS NULL
                         WHERE sw.wali_id = ? AND sw.archived_at IS NULL)";
        $params = [$waliId];
        if ($santriId !== null) {
            $where .= ' AND p.santri_id = ?';
            $params[] = $santriId;
        }

        return $this->page($where, $params, $onDate, $page, $perPage);
    }

    /**
     * Antrean pembimbing: pengajuan wali yang DIALAMATKAN kepadanya, masih
     * menunggu tinjauan, santri aktif, dan pengurus itu masih pembimbing sah
     * santri tersebut pada tanggal ini.
     *
     * @return array{rows:array<int,array<string,mixed>>, total:int}
     */
    public function antreanPembimbing(int $pengurusId, string $onDate, int $page, int $perPage): array
    {
        $where = " WHERE p.asal_pengajuan = 'Wali' AND p.pembimbing_pengurus_id = ? AND p.status = ?
                     AND s.is_active = 1 AND s.archived_at IS NULL
                     AND " . PembimbingRouter::sahSql('p.pembimbing_pengurus_id', 'p.santri_id');

        return $this->page($where, [$pengurusId, self::STATUS_MENUNGGU_PEMBIMBING, $onDate, $onDate], $onDate, $page, $perPage);
    }

    /**
     * Pemantauan admin atas seluruh pengajuan wali. `antrean` = pengecualian
     * yang menunggu admin: tanpa pembimbing tunggal, atau pembimbing tujuan
     * tidak lagi sah/santri tidak aktif sejak routing.
     *
     * @return array{rows:array<int,array<string,mixed>>, total:int}
     */
    public function daftarAdmin(bool $antreanSaja, ?string $status, string $onDate, int $page, int $perPage): array
    {
        $where = " WHERE p.asal_pengajuan = 'Wali'";
        $params = [];
        if ($antreanSaja) {
            [$kondisi, $kondisiParams] = $this->kondisiAntreanAdmin($onDate);
            $where .= ' AND ' . $kondisi;
            $params = array_merge($params, $kondisiParams);
        }
        if ($status !== null) {
            $where .= ' AND p.status = ?';
            $params[] = $status;
        }

        return $this->page($where, $params, $onDate, $page, $perPage);
    }

    public function jumlahAntreanAdmin(string $onDate): int
    {
        [$kondisi, $params] = $this->kondisiAntreanAdmin($onDate);
        $row = $this->one(
            "SELECT COUNT(*) AS jumlah FROM izin_pengajuan p JOIN santri s ON s.id = p.santri_id
              WHERE p.asal_pengajuan = 'Wali' AND " . $kondisi,
            $params
        );

        return (int) ($row['jumlah'] ?? 0);
    }

    /**
     * Ringkasan per status seluruh pengajuan wali (admin).
     *
     * @return array<string, int>
     */
    public function ringkasanStatus(): array
    {
        $hasil = [];
        foreach ($this->all("SELECT status, COUNT(*) AS jumlah FROM izin_pengajuan WHERE asal_pengajuan = 'Wali' GROUP BY status") as $row) {
            $hasil[(string) $row['status']] = (int) $row['jumlah'];
        }

        return $hasil;
    }

    /**
     * Riwayat status (terlama dulu).
     *
     * @return array<int, array<string, mixed>>
     */
    public function riwayat(int $id): array
    {
        return $this->all(
            'SELECT r.id, r.peristiwa, r.status_sebelum, r.status_sesudah, r.pelaku_kapasitas, r.alasan,
                    r.pelaku_user_id, r.created_at, u.name AS pelaku_nama
               FROM izin_riwayat_status r
               LEFT JOIN users u ON u.id = r.pelaku_user_id
              WHERE r.pengajuan_id = ?
              ORDER BY r.id',
            [$id]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function penetapan(int $id): array
    {
        return $this->all(
            'SELECT t.id, t.status_sebelum, t.jumlah_kandidat, t.alasan, t.versi_sebelum, t.versi_sesudah, t.ditetapkan_pada,
                    ps.nama AS pembimbing_sebelum, pg.nama AS pembimbing, u.name AS penetap
               FROM izin_pembimbing_penetapan t
               JOIN pengurus pg ON pg.id = t.pembimbing_pengurus_id
               LEFT JOIN pengurus ps ON ps.id = t.pembimbing_sebelum_pengurus_id
               LEFT JOIN users u ON u.id = t.ditetapkan_oleh_user_id
              WHERE t.pengajuan_id = ?
              ORDER BY t.id',
            [$id]
        );
    }

    // -----------------------------------------------------------------------
    // Utilitas
    // -----------------------------------------------------------------------

    /**
     * @return array{0:string, 1:array<int, mixed>}
     */
    private function kondisiAntreanAdmin(string $onDate): array
    {
        return [
            '(p.status = ? OR (p.status = ? AND NOT (s.is_active = 1 AND s.archived_at IS NULL AND '
                . PembimbingRouter::sahSql('p.pembimbing_pengurus_id', 'p.santri_id') . ')))',
            [self::STATUS_PERLU_PENETAPAN_PEMBIMBING, self::STATUS_MENUNGGU_PEMBIMBING, $onDate, $onDate],
        ];
    }

    /**
     * Kolom baca. `pembimbing_sah` = pembimbing tujuan masih sah dan santri aktif
     * pada tanggal ini (dipakai penanda "perlu penetapan ulang").
     *
     * @return array{sql:string, params:array<int, mixed>}
     */
    private function select(string $onDate): array
    {
        return [
            'sql' => "SELECT p.id, p.asal_pengajuan, p.santri_id, p.wali_id, p.diajukan_oleh_user_id, p.tahun_ajaran_id,
                             p.tgl_izin, p.tgl_kembali, p.alasan, p.catatan_wali, p.status, p.version,
                             p.pembimbing_pengurus_id, p.pembimbing_assignment_id, p.pembimbing_routing_kandidat,
                             p.pembimbing_routing_catatan, p.pembimbing_routing_pada, p.pembimbing_ditetapkan_pada,
                             p.murobi_guru_id, p.pengurus_id, p.diajukan_pada, p.created_at, p.updated_at,
                             s.nis, s.nama_santri, s.is_active AS santri_aktif,
                             w.nama AS wali_nama, uw.name AS pengaju_nama,
                             pg.nama AS pembimbing_nama, ta.tahun AS tahun_ajaran, ta.semester,
                             (s.is_active = 1 AND s.archived_at IS NULL AND p.pembimbing_pengurus_id IS NOT NULL
                                AND " . PembimbingRouter::sahSql('p.pembimbing_pengurus_id', 'p.santri_id') . ") AS pembimbing_sah
                        FROM izin_pengajuan p
                        JOIN santri s ON s.id = p.santri_id
                        LEFT JOIN wali w ON w.id = p.wali_id
                        LEFT JOIN users uw ON uw.id = p.diajukan_oleh_user_id
                        LEFT JOIN pengurus pg ON pg.id = p.pembimbing_pengurus_id
                        LEFT JOIN tahun_ajaran ta ON ta.id = p.tahun_ajaran_id",
            'params' => [$onDate, $onDate],
        ];
    }

    /**
     * @param array<int, mixed> $params
     * @return array{rows:array<int,array<string,mixed>>, total:int}
     */
    private function page(string $where, array $params, string $onDate, int $page, int $perPage): array
    {
        $total = (int) ($this->one('SELECT COUNT(*) AS jumlah FROM izin_pengajuan p JOIN santri s ON s.id = p.santri_id' . $where, $params)['jumlah'] ?? 0);
        $select = $this->select($onDate);
        $rows = $this->all(
            $select['sql'] . $where . ' ORDER BY p.id DESC LIMIT ? OFFSET ?',
            array_merge($select['params'], $params, [$perPage, max(0, ($page - 1) * $perPage)])
        );

        return ['rows' => $rows, 'total' => $total];
    }

    private function dbError(int $errno, string $pesan): \Throwable
    {
        return match (true) {
            $errno === 1062 => IzinException::conflict('Data pengajuan bentrok dengan baris yang sudah ada.'),
            in_array($errno, [1020, 1205, 1213], true) => IzinException::conflict('Pengajuan sedang diproses permintaan lain. Coba lagi sesaat lagi.'),
            in_array($errno, [3819, 4025], true) => IzinException::invalid('Pengajuan ditolak aturan basis data.'),
            default => new RuntimeException($pesan),
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function all(string $sql, array $params = []): array
    {
        $statement = $this->db->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Data pengajuan wali tidak dapat dibaca.');
        }
        if (!$this->run($statement, $params)) {
            $errno = $statement->errno;
            $statement->close();
            throw $this->dbError($errno, 'Data pengajuan wali tidak dapat dibaca.');
        }
        $result = $statement->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $statement->close();

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function one(string $sql, array $params = []): ?array
    {
        return $this->all($sql, $params)[0] ?? null;
    }

    private function execute(string $sql, array $params): int
    {
        $statement = $this->db->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Perintah pengajuan wali tidak dapat disiapkan.');
        }
        if (!$this->run($statement, $params)) {
            $errno = $statement->errno;
            $statement->close();
            throw $this->dbError($errno, 'Perintah pengajuan wali gagal disimpan.');
        }
        $affected = (int) $statement->affected_rows;
        $statement->close();

        return $affected;
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
