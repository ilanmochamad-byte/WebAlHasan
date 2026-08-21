<?php

declare(strict_types=1);

namespace App\Izin;

use mysqli;
use mysqli_stmt;
use RuntimeException;

/**
 * Akses baca perizinan V2.
 *
 * Fase 1 bersifat baca-saja: repository ini tidak pernah menulis, mengubah, atau
 * menghapus baris perizinan. Seluruh query memakai prepared statement dan tidak
 * pernah membaca variabel global request.
 */
final class IzinRepository
{
    public const STATUSES = ['Diajukan', 'Perlu Penetapan Admin', 'Disetujui', 'Ditolak', 'Dibatalkan'];

    public function __construct(private mysqli $db)
    {
    }

    /**
     * Daftar santri dalam cakupan penugasan pembimbing aktif seorang pengurus.
     *
     * @return array<int, array<string, mixed>>
     */
    public function santriForPengurus(int $pengurusId, string $onDate): array
    {
        return $this->all(
            "SELECT DISTINCT s.id, s.nis, s.nama_santri, s.jenis_kelamin,
                    pa.id AS pembimbing_assignment_id, pa.target_type,
                    COALESCE(km.nama_kamar, kl.nama_kelas) AS target_name
               FROM pembimbing_assignments pa
               JOIN tahun_ajaran ta ON ta.id = pa.tahun_ajaran_id
                    AND ta.status = 'Aktif' AND ta.archived_at IS NULL
               LEFT JOIN plotting_kamar pkm ON pkm.id_tahun = pa.tahun_ajaran_id AND pkm.id_kamar = pa.kamar_id
               LEFT JOIN plotting_kelas pkl ON pkl.id_tahun = pa.tahun_ajaran_id AND pkl.id_kelas = pa.kelas_id
                    AND pkl.status = 'Aktif'
               JOIN santri s ON s.id = CASE WHEN pa.target_type = 'Kamar' THEN pkm.id_santri ELSE pkl.id_santri END
               LEFT JOIN kamar km ON km.id = pa.kamar_id
               LEFT JOIN kelas kl ON kl.id = pa.kelas_id
              WHERE pa.pengurus_id = ?
                AND pa.is_active = 1 AND pa.archived_at IS NULL
                AND pa.tanggal_mulai <= ?
                AND (pa.tanggal_selesai IS NULL OR pa.tanggal_selesai >= ?)
                AND s.is_active = 1 AND s.archived_at IS NULL
              ORDER BY s.nama_santri, s.id",
            [$pengurusId, $onDate, $onDate]
        );
    }

    /**
     * Versi berhalaman + pencarian dari `santriForPengurus()`.
     *
     * Dipakai form pengajuan Fase 2. Cakupan pembimbing tetap dipaksakan di SQL:
     * mengubah parameter pencarian tidak pernah memunculkan santri di luar cakupan.
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function santriForPengurusPaged(int $pengurusId, string $onDate, string $query, int $page, int $perPage): array
    {
        $query = trim($query);
        $filter = '';
        $extra = [];
        if ($query !== '') {
            $filter = ' AND (s.nama_santri LIKE ? OR s.nis LIKE ?)';
            $extra = ['%' . $query . '%', '%' . $query . '%'];
        }

        $base = "FROM pembimbing_assignments pa
                 JOIN tahun_ajaran ta ON ta.id = pa.tahun_ajaran_id
                      AND ta.status = 'Aktif' AND ta.archived_at IS NULL
                 LEFT JOIN plotting_kamar pkm ON pkm.id_tahun = pa.tahun_ajaran_id AND pkm.id_kamar = pa.kamar_id
                 LEFT JOIN plotting_kelas pkl ON pkl.id_tahun = pa.tahun_ajaran_id AND pkl.id_kelas = pa.kelas_id
                      AND pkl.status = 'Aktif'
                 JOIN santri s ON s.id = CASE WHEN pa.target_type = 'Kamar' THEN pkm.id_santri ELSE pkl.id_santri END
                 LEFT JOIN kamar km ON km.id = pa.kamar_id
                 LEFT JOIN kelas kl ON kl.id = pa.kelas_id
                WHERE pa.pengurus_id = ?
                  AND pa.is_active = 1 AND pa.archived_at IS NULL
                  AND pa.tanggal_mulai <= ?
                  AND (pa.tanggal_selesai IS NULL OR pa.tanggal_selesai >= ?)
                  AND s.is_active = 1 AND s.archived_at IS NULL" . $filter;

        $scopeParams = array_merge([$pengurusId, $onDate, $onDate], $extra);

        $total = (int) ($this->one(
            'SELECT COUNT(DISTINCT s.id) AS jumlah ' . $base,
            $scopeParams
        )['jumlah'] ?? 0);

        $listParams = $scopeParams;
        $listParams[] = $perPage;
        $listParams[] = max(0, ($page - 1) * $perPage);

        $rows = $this->all(
            "SELECT s.id, s.nis, s.nama_santri, s.jenis_kelamin,
                    MIN(pa.id) AS pembimbing_assignment_id,
                    MIN(pa.target_type) AS target_type,
                    MIN(COALESCE(km.nama_kamar, kl.nama_kelas)) AS target_name "
            . $base . ' GROUP BY s.id, s.nis, s.nama_santri, s.jenis_kelamin
                        ORDER BY s.nama_santri, s.id LIMIT ? OFFSET ?',
            $listParams
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Penugasan pembimbing aktif yang membuat seorang pengurus berwenang atas satu
     * santri pada satu tanggal. `null` berarti santri berada DI LUAR cakupan.
     *
     * @return array<string, mixed>|null
     */
    public function pembimbingAssignmentFor(int $pengurusId, int $santriId, string $onDate): ?array
    {
        return $this->one(
            "SELECT pa.id, pa.target_type, pa.tahun_ajaran_id,
                    COALESCE(km.nama_kamar, kl.nama_kelas) AS target_name
               FROM pembimbing_assignments pa
               JOIN tahun_ajaran ta ON ta.id = pa.tahun_ajaran_id
                    AND ta.status = 'Aktif' AND ta.archived_at IS NULL
               LEFT JOIN plotting_kamar pkm ON pkm.id_tahun = pa.tahun_ajaran_id AND pkm.id_kamar = pa.kamar_id
               LEFT JOIN plotting_kelas pkl ON pkl.id_tahun = pa.tahun_ajaran_id AND pkl.id_kelas = pa.kelas_id
                    AND pkl.status = 'Aktif'
               JOIN santri s ON s.id = CASE WHEN pa.target_type = 'Kamar' THEN pkm.id_santri ELSE pkl.id_santri END
               LEFT JOIN kamar km ON km.id = pa.kamar_id
               LEFT JOIN kelas kl ON kl.id = pa.kelas_id
              WHERE pa.pengurus_id = ? AND s.id = ?
                AND pa.is_active = 1 AND pa.archived_at IS NULL
                AND pa.tanggal_mulai <= ?
                AND (pa.tanggal_selesai IS NULL OR pa.tanggal_selesai >= ?)
                AND s.is_active = 1 AND s.archived_at IS NULL
              ORDER BY pa.id
              LIMIT 1",
            [$pengurusId, $santriId, $onDate, $onDate]
        );
    }

    /**
     * Seluruh santri aktif, berhalaman. HANYA dipakai jalur admin (PRD 5.2:
     * admin dapat mengajukan untuk santri mana pun dan tindakannya diaudit).
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function santriAll(string $query, int $page, int $perPage): array
    {
        $query = trim($query);
        $where = 's.is_active = 1 AND s.archived_at IS NULL';
        $params = [];
        if ($query !== '') {
            $where .= ' AND (s.nama_santri LIKE ? OR s.nis LIKE ?)';
            $params[] = '%' . $query . '%';
            $params[] = '%' . $query . '%';
        }

        $total = (int) ($this->one(
            'SELECT COUNT(*) AS jumlah FROM santri s WHERE ' . $where,
            $params
        )['jumlah'] ?? 0);

        $listParams = $params;
        $listParams[] = $perPage;
        $listParams[] = max(0, ($page - 1) * $perPage);

        $rows = $this->all(
            'SELECT s.id, s.nis, s.nama_santri, s.jenis_kelamin,
                    NULL AS pembimbing_assignment_id, NULL AS target_type, NULL AS target_name
               FROM santri s
              WHERE ' . $where . ' ORDER BY s.nama_santri, s.id LIMIT ? OFFSET ?',
            $listParams
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function santriBasic(int $santriId): ?array
    {
        return $this->one(
            'SELECT id, nis, nama_santri, is_active, archived_at FROM santri WHERE id = ? LIMIT 1',
            [$santriId]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function activeYear(): ?array
    {
        return $this->one(
            "SELECT id, tahun, semester FROM tahun_ajaran
              WHERE status = 'Aktif' AND archived_at IS NULL
              ORDER BY id DESC LIMIT 1"
        );
    }

    /**
     * Seluruh koreksi keputusan sebuah pengajuan, terlama lebih dulu.
     *
     * @return array<int, array<string, mixed>>
     */
    public function corrections(int $pengajuanId): array
    {
        return $this->all(
            'SELECT c.id, c.hasil_sebelum, c.hasil_sesudah, c.alasan_sebelum, c.alasan_sesudah,
                    c.status_sebelum, c.status_sesudah, c.alasan_koreksi, c.dikoreksi_pada,
                    u.name AS pelaku_nama
               FROM izin_keputusan_koreksi c
               LEFT JOIN users u ON u.id = c.dikoreksi_oleh_user_id
              WHERE c.pengajuan_id = ?
              ORDER BY c.id',
            [$pengajuanId]
        );
    }

    /**
     * Jumlah pengajuan yang menunggu tindakan pengguna pada cakupannya.
     *
     * @param array{mode:string, pengurus_id?:int|null, guru_id?:int|null, wali_id?:int|null} $scope
     */
    public function queueCount(array $scope): int
    {
        [$where, $params] = $this->conditions(['antrean' => '1'], $scope);

        return (int) ($this->one(
            'SELECT COUNT(*) AS jumlah FROM izin_pengajuan p JOIN santri s ON s.id = p.santri_id ' . $where,
            $params
        )['jumlah'] ?? 0);
    }

    /**
     * Santri yang memiliki relasi wali AKTIF dengan wali tertentu.
     *
     * @return array<int, array<string, mixed>>
     */
    public function santriForWali(int $waliId): array
    {
        return $this->all(
            'SELECT s.id, s.nis, s.nama_santri, sw.hubungan, sw.is_primary
               FROM santri_wali sw
               JOIN santri s ON s.id = sw.santri_id
               JOIN wali w ON w.id = sw.wali_id
              WHERE sw.wali_id = ?
                AND sw.archived_at IS NULL
                AND w.is_active = 1 AND w.archived_at IS NULL
              ORDER BY s.nama_santri, s.id',
            [$waliId]
        );
    }

    /**
     * @param array<string, mixed> $filters
     * @param array{mode:string, pengurus_id?:int|null, guru_id?:int|null, wali_id?:int|null} $scope
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function list(array $filters, array $scope, int $page, int $perPage): array
    {
        [$where, $params] = $this->conditions($filters, $scope);

        $total = (int) ($this->one(
            'SELECT COUNT(*) AS jumlah FROM izin_pengajuan p JOIN santri s ON s.id = p.santri_id ' . $where,
            $params
        )['jumlah'] ?? 0);

        $listParams = $params;
        $listParams[] = $perPage;
        $listParams[] = max(0, ($page - 1) * $perPage);

        $rows = $this->all(
            $this->selectClause() . $where . ' ORDER BY p.tgl_izin DESC, p.id DESC LIMIT ? OFFSET ?',
            $listParams
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * @param array{mode:string, pengurus_id?:int|null, guru_id?:int|null, wali_id?:int|null} $scope
     */
    public function find(int $id, array $scope): ?array
    {
        [$where, $params] = $this->conditions(['id' => $id], $scope);
        return $this->one($this->selectClause() . $where . ' LIMIT 1', $params);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function history(int $pengajuanId): array
    {
        return $this->all(
            'SELECT r.id, r.peristiwa, r.status_sebelum, r.status_sesudah, r.pelaku_kapasitas,
                    r.alasan, r.created_at, u.name AS pelaku_nama
               FROM izin_riwayat_status r
               LEFT JOIN users u ON u.id = r.pelaku_user_id
              WHERE r.pengajuan_id = ?
              ORDER BY r.id',
            [$pengajuanId]
        );
    }

    public function decision(int $pengajuanId): ?array
    {
        return $this->one(
            'SELECT k.id, k.hasil, k.alasan, k.kapasitas, k.alasan_penggantian, k.diputus_pada,
                    u.name AS pemberi_keputusan
               FROM izin_keputusan k
               LEFT JOIN users u ON u.id = k.diputus_oleh_user_id
              WHERE k.pengajuan_id = ?
              LIMIT 1',
            [$pengajuanId]
        );
    }

    /**
     * @return array{total:int, legacy:int, per_status:array<string,int>}
     */
    public function summary(array $scope): array
    {
        [$where, $params] = $this->conditions([], $scope);
        $rows = $this->all(
            'SELECT p.status, SUM(p.is_legacy) AS warisan, COUNT(*) AS jumlah
               FROM izin_pengajuan p JOIN santri s ON s.id = p.santri_id ' . $where . ' GROUP BY p.status',
            $params
        );

        $perStatus = array_fill_keys(self::STATUSES, 0);
        $total = 0;
        $legacy = 0;
        foreach ($rows as $row) {
            $perStatus[(string) $row['status']] = (int) $row['jumlah'];
            $total += (int) $row['jumlah'];
            $legacy += (int) $row['warisan'];
        }

        return ['total' => $total, 'legacy' => $legacy, 'per_status' => $perStatus];
    }

    private function selectClause(): string
    {
        return "SELECT p.id, p.legacy_perizinan_id, p.is_legacy, p.santri_id, p.tgl_izin, p.tgl_kembali,
                       p.alasan, p.catatan_pengurus, p.status, p.version, p.diajukan_pada, p.created_at,
                       p.pengurus_id, p.murobi_guru_id, p.diajukan_oleh_user_id, p.tahun_ajaran_id,
                       p.routing_kandidat, p.routing_catatan, p.routing_pada,
                       p.murobi_ditetapkan_pada, p.dibatalkan_pada, p.alasan_pembatalan,
                       s.nis, s.nama_santri,
                       pg.nama AS pengurus_nama, g.nama_guru AS murobi_nama,
                       ta.tahun AS tahun_ajaran, ta.semester AS semester,
                       k.hasil AS keputusan_hasil, k.kapasitas AS keputusan_kapasitas, k.diputus_pada,
                       up.name AS penetap_nama, ub.name AS pembatal_nama
                  FROM izin_pengajuan p
                  JOIN santri s ON s.id = p.santri_id
                  LEFT JOIN pengurus pg ON pg.id = p.pengurus_id
                  LEFT JOIN guru g ON g.id = p.murobi_guru_id
                  LEFT JOIN tahun_ajaran ta ON ta.id = p.tahun_ajaran_id
                  LEFT JOIN izin_keputusan k ON k.pengajuan_id = p.id
                  LEFT JOIN users up ON up.id = p.murobi_ditetapkan_oleh_user_id
                  LEFT JOIN users ub ON ub.id = p.dibatalkan_oleh_user_id ";
    }

    /**
     * Membangun klausa WHERE gabungan filter UI dan cakupan server.
     *
     * Cakupan SELALU ditambahkan di sini sehingga tidak ada jalur pemanggilan yang
     * dapat melewatinya dengan mengubah parameter request.
     *
     * @return array{0:string, 1:array<int, mixed>}
     */
    private function conditions(array $filters, array $scope): array
    {
        $parts = [];
        $params = [];

        $mode = (string) ($scope['mode'] ?? '');
        if ($mode === 'admin') {
            // Admin melihat seluruh pengajuan; tidak ada batasan tambahan.
        } elseif ($mode === 'pengurus') {
            $parts[] = 'p.pengurus_id = ?';
            $params[] = (int) ($scope['pengurus_id'] ?? 0);
        } elseif ($mode === 'murobi') {
            $parts[] = 'p.murobi_guru_id = ?';
            $params[] = (int) ($scope['guru_id'] ?? 0);
        } elseif ($mode === 'orang_tua') {
            $parts[] = 'p.santri_id IN (
                SELECT sw.santri_id FROM santri_wali sw
                 JOIN wali w ON w.id = sw.wali_id
                WHERE sw.wali_id = ? AND sw.archived_at IS NULL
                  AND w.is_active = 1 AND w.archived_at IS NULL)';
            $params[] = (int) ($scope['wali_id'] ?? 0);
        } else {
            // Cakupan tidak dikenal: jangan pernah membocorkan data.
            $parts[] = '1 = 0';
        }

        if (!empty($filters['id'])) {
            $parts[] = 'p.id = ?';
            $params[] = (int) $filters['id'];
        }
        $query = trim((string) ($filters['q'] ?? ''));
        if ($query !== '') {
            $parts[] = '(s.nama_santri LIKE ? OR s.nis LIKE ? OR p.alasan LIKE ?)';
            $params[] = '%' . $query . '%';
            $params[] = '%' . $query . '%';
            $params[] = '%' . $query . '%';
        }
        $status = (string) ($filters['status'] ?? '');
        if ($status !== '' && in_array($status, self::STATUSES, true)) {
            $parts[] = 'p.status = ?';
            $params[] = $status;
        }
        $source = (string) ($filters['source'] ?? '');
        if ($source === 'legacy') {
            $parts[] = 'p.is_legacy = 1';
        } elseif ($source === 'v2') {
            $parts[] = 'p.is_legacy = 0';
        }
        if (!empty($filters['santri_id'])) {
            $parts[] = 'p.santri_id = ?';
            $params[] = (int) $filters['santri_id'];
        }
        if (!empty($filters['date_from'])) {
            $parts[] = 'p.tgl_kembali >= ?';
            $params[] = (string) $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $parts[] = 'p.tgl_izin <= ?';
            $params[] = (string) $filters['date_to'];
        }

        // Antrean = pengajuan yang menunggu tindakan pengguna pada cakupannya.
        // Cakupan di atas sudah membatasi baris; filter ini hanya mempersempit.
        if (!empty($filters['antrean'])) {
            $parts[] = match ($mode) {
                'murobi' => "p.status = 'Diajukan'",
                'admin' => "p.status = 'Perlu Penetapan Admin'",
                'pengurus' => "p.status IN ('Diajukan', 'Perlu Penetapan Admin')",
                default => '1 = 0',
            };
        }

        return [$parts === [] ? '' : ' WHERE ' . implode(' AND ', $parts), $params];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function all(string $sql, array $params = []): array
    {
        $statement = $this->db->prepare($sql);
        if ($statement === false || !$this->run($statement, $params)) {
            throw new RuntimeException('Data perizinan tidak dapat dibaca.');
        }
        $result = $statement->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $statement->close();

        return $rows;
    }

    private function one(string $sql, array $params = []): ?array
    {
        return $this->all($sql, $params)[0] ?? null;
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
