<?php

declare(strict_types=1);

namespace App\Report;

use App\Auth\Capabilities;
use mysqli;
use mysqli_stmt;
use RuntimeException;

/**
 * Akses baca laporan perizinan V2.
 *
 * Sifat yang dijaga:
 *
 *  1. **Satu definisi kriteria.** `conditions()` adalah satu-satunya tempat
 *     klausa WHERE dibangun. Ringkasan, median durasi, halaman detail, ekspor
 *     CSV, cetak, dan `EXPLAIN` seluruhnya memakainya, sehingga total pada
 *     keempat permukaan tidak dapat menyimpang untuk filter yang sama.
 *  2. **Cakupan selalu di SQL.** Predikat cakupan ditambahkan di sini dari
 *     `IzinReportFilter::$scope` (yang berasal dari akun, bukan request).
 *     Cakupan yang tidak dikenal menghasilkan `1 = 0`, bukan "semua baris".
 *  3. **Tidak pernah menggandakan baris.** Kamar/kelas dibaca lewat subquery
 *     skalar, bukan JOIN, karena satu santri dapat memiliki lebih dari satu
 *     baris plotting dan JOIN akan melipatgandakan baris — yang berarti total
 *     ringkasan dan detail langsung berbeda.
 *  4. **Repository ini tidak pernah menulis.** Laporan bersifat baca-saja.
 */
final class IzinReportRepository
{
    public function __construct(private mysqli $db)
    {
    }

    // -----------------------------------------------------------------------
    // Ringkasan
    // -----------------------------------------------------------------------

    /**
     * Jumlah pengajuan per status + total, untuk kriteria yang SAMA persis
     * dengan detail dan ekspor.
     *
     * @return array{total:int, legacy:int, per_status:array<string,int>}
     */
    public function summary(IzinReportFilter $filter): array
    {
        [$where, $params] = $this->conditions($filter);
        $rows = $this->all(
            'SELECT p.status, COUNT(*) AS jumlah, SUM(p.is_legacy) AS warisan '
            . $this->fromClause() . $where . ' GROUP BY p.status',
            $params
        );

        $perStatus = array_fill_keys(\App\Izin\IzinRepository::STATUSES, 0);
        $total = 0;
        $legacy = 0;
        foreach ($rows as $row) {
            $status = (string) $row['status'];
            if (array_key_exists($status, $perStatus)) {
                $perStatus[$status] = (int) $row['jumlah'];
            }
            $total += (int) $row['jumlah'];
            $legacy += (int) $row['warisan'];
        }

        return ['total' => $total, 'legacy' => $legacy, 'per_status' => $perStatus];
    }

    /**
     * Statistik durasi keputusan dalam DETIK, termasuk median.
     *
     * Median dihitung dengan dua query `LIMIT 1 OFFSET n` alih-alih fungsi
     * window. Alasannya kompatibilitas: MySQL 5.7 (masih umum pada cPanel)
     * tidak memiliki `PERCENTILE_CONT` maupun window function, sedangkan pola
     * ini berjalan pada MySQL 5.7, MySQL 8, dan MariaDB tanpa perbedaan hasil.
     * Pola ini juga tidak memuat seluruh baris ke memori PHP.
     *
     * Hanya pengajuan yang benar-benar mempunyai waktu pengajuan DAN waktu
     * keputusan yang dihitung. Data warisan V1 tidak memiliki keduanya dan
     * karena itu tidak pernah mengarang durasi.
     *
     * @return array{jumlah:int, median_detik:?int, rata_detik:?int, min_detik:?int, maks_detik:?int}
     */
    public function decisionDuration(IzinReportFilter $filter): array
    {
        [$where, $params] = $this->conditions($filter);
        $durasiWhere = $where === ''
            ? ' WHERE ' . self::DURASI_TERSEDIA
            : $where . ' AND ' . self::DURASI_TERSEDIA;

        $agregat = $this->one(
            'SELECT COUNT(*) AS jumlah, AVG(' . self::DURASI_DETIK . ') AS rata,
                    MIN(' . self::DURASI_DETIK . ') AS terkecil, MAX(' . self::DURASI_DETIK . ') AS terbesar '
            . $this->fromClause() . $durasiWhere,
            $params
        );

        $jumlah = (int) ($agregat['jumlah'] ?? 0);
        if ($jumlah === 0) {
            return ['jumlah' => 0, 'median_detik' => null, 'rata_detik' => null, 'min_detik' => null, 'maks_detik' => null];
        }

        // Median: untuk n ganjil ambil elemen tengah; untuk n genap rata-rata
        // dua elemen tengah. `intdiv` menjaga offset tetap bilangan bulat.
        $bawah = intdiv($jumlah - 1, 2);
        $atas = intdiv($jumlah, 2);
        $nilaiBawah = $this->durationAt($durasiWhere, $params, $bawah);
        $nilaiAtas = $bawah === $atas ? $nilaiBawah : $this->durationAt($durasiWhere, $params, $atas);

        $median = $nilaiBawah === null || $nilaiAtas === null
            ? null
            : (int) round(($nilaiBawah + $nilaiAtas) / 2);

        return [
            'jumlah' => $jumlah,
            'median_detik' => $median,
            'rata_detik' => $agregat['rata'] === null ? null : (int) round((float) $agregat['rata']),
            'min_detik' => $agregat['terkecil'] === null ? null : (int) $agregat['terkecil'],
            'maks_detik' => $agregat['terbesar'] === null ? null : (int) $agregat['terbesar'],
        ];
    }

    private function durationAt(string $durasiWhere, array $params, int $offset): ?int
    {
        $row = $this->one(
            'SELECT ' . self::DURASI_DETIK . ' AS durasi ' . $this->fromClause() . $durasiWhere
            . ' ORDER BY durasi ASC, p.id ASC LIMIT 1 OFFSET ' . max(0, $offset),
            $params
        );

        return $row === null || $row['durasi'] === null ? null : (int) $row['durasi'];
    }

    // -----------------------------------------------------------------------
    // Detail
    // -----------------------------------------------------------------------

    /**
     * Satu halaman detail laporan.
     *
     * @return array<int, array<string, mixed>>
     */
    public function page(IzinReportFilter $filter): array
    {
        [$where, $params] = $this->conditions($filter);
        $params[] = $filter->perPage;
        $params[] = $filter->offset();

        return $this->all(
            $this->selectClause() . $this->fromClause() . $where
            . ' ORDER BY p.tgl_izin DESC, p.id DESC LIMIT ? OFFSET ?',
            $params
        );
    }

    /**
     * SELURUH baris sesuai filter — dipakai CSV dan cetak.
     *
     * PRD Fase 5: CSV memuat seluruh hasil filter, bukan hanya halaman yang
     * sedang terlihat. Karena itu metode ini sengaja mengabaikan `page`, dan
     * hanya dibatasi pagar keamanan memori `MAX_EXPORT_ROWS`.
     *
     * @return array<int, array<string, mixed>>
     */
    public function allRows(IzinReportFilter $filter, int $limit = IzinReportFilter::MAX_EXPORT_ROWS): array
    {
        [$where, $params] = $this->conditions($filter);
        $params[] = max(1, $limit);

        return $this->all(
            $this->selectClause() . $this->fromClause() . $where
            . ' ORDER BY p.tgl_izin DESC, p.id DESC LIMIT ?',
            $params
        );
    }

    /**
     * Riwayat status ringkas untuk sekumpulan pengajuan (dipakai detail cetak).
     *
     * Dibaca sekali untuk seluruh halaman agar tidak menjadi query N+1.
     *
     * @param array<int, int> $pengajuanIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function historyFor(array $pengajuanIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $pengajuanIds)));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->all(
            'SELECT r.pengajuan_id, r.peristiwa, r.status_sebelum, r.status_sesudah,
                    r.pelaku_kapasitas, r.alasan, r.created_at, u.name AS pelaku_nama
               FROM izin_riwayat_status r
               LEFT JOIN users u ON u.id = r.pelaku_user_id
              WHERE r.pengajuan_id IN (' . $placeholders . ')
              ORDER BY r.pengajuan_id, r.id',
            $ids
        );

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row['pengajuan_id']][] = $row;
        }

        return $grouped;
    }

    // -----------------------------------------------------------------------
    // Opsi filter (dibatasi cakupan)
    // -----------------------------------------------------------------------

    /**
     * Pilihan filter yang boleh dilihat pengguna pada cakupannya.
     *
     * Daftar ini juga dibatasi cakupan: orang tua tidak menerima daftar seluruh
     * santri pesantren, murobi tidak menerima daftar seluruh pengurus, dan
     * seterusnya. Membocorkan nama lewat dropdown tetap kebocoran data.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function filterOptions(IzinReportFilter $filter): array
    {
        // Sengaja HANYA cakupan: dropdown tidak dipersempit filter lain, agar
        // pengguna tetap dapat memperlebar pilihannya kembali. Cakupan sendiri
        // tidak pernah dilonggarkan.
        [$where, $params] = $this->scopeConditions($filter);
        $from = $this->fromClause() . $where;

        return [
            'santri' => $this->all(
                'SELECT DISTINCT s.id, s.nis, s.nama_santri ' . $from . ' ORDER BY s.nama_santri, s.id LIMIT 500',
                $params
            ),
            'pengurus' => $this->all(
                'SELECT DISTINCT pg.id, pg.nama ' . $from . ' AND pg.id IS NOT NULL ORDER BY pg.nama, pg.id LIMIT 500',
                $params
            ),
            'murobi' => $this->all(
                'SELECT DISTINCT g.id, g.nama_guru ' . $from . ' AND g.id IS NOT NULL ORDER BY g.nama_guru, g.id LIMIT 500',
                $params
            ),
            'tahun_ajaran' => $this->all(
                "SELECT id, tahun, semester, status FROM tahun_ajaran
                  WHERE archived_at IS NULL ORDER BY status = 'Aktif' DESC, id DESC LIMIT 100"
            ),
            'kamar' => $this->all('SELECT id, nama_kamar FROM kamar ORDER BY nama_kamar, id LIMIT 300'),
            'kelas' => $this->all(
                'SELECT id, nama_kelas, jenjang FROM kelas
                  WHERE is_active = 1 AND archived_at IS NULL ORDER BY nama_kelas, id LIMIT 300'
            ),
        ];
    }

    // -----------------------------------------------------------------------
    // EXPLAIN — bukti sebelum menambahkan indeks
    // -----------------------------------------------------------------------

    /**
     * `EXPLAIN` untuk query ringkasan dan query halaman detail.
     *
     * PRD Fase 5 §6: indeks hanya ditambahkan SETELAH `EXPLAIN`. Metode ini
     * memberi auditor cara mengulang pengukuran yang sama, bukan sekadar
     * membaca klaim pada dokumen.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function explain(IzinReportFilter $filter): array
    {
        [$where, $params] = $this->conditions($filter);
        $pageParams = $params;
        $pageParams[] = $filter->perPage;
        $pageParams[] = $filter->offset();

        $durasiWhere = $where === ''
            ? ' WHERE ' . self::DURASI_TERSEDIA
            : $where . ' AND ' . self::DURASI_TERSEDIA;

        return [
            'ringkasan' => $this->all(
                'EXPLAIN SELECT p.status, COUNT(*) AS jumlah, SUM(p.is_legacy) AS warisan '
                . $this->fromClause() . $where . ' GROUP BY p.status',
                $params
            ),
            'detail' => $this->all(
                'EXPLAIN ' . $this->selectClause() . $this->fromClause() . $where
                . ' ORDER BY p.tgl_izin DESC, p.id DESC LIMIT ? OFFSET ?',
                $pageParams
            ),
            'durasi' => $this->all(
                'EXPLAIN SELECT COUNT(*) AS jumlah, AVG(' . self::DURASI_DETIK . ') AS rata '
                . $this->fromClause() . $durasiWhere,
                $params
            ),
        ];
    }

    // -----------------------------------------------------------------------
    // Bagian SQL bersama
    // -----------------------------------------------------------------------

    private const DURASI_DETIK = 'TIMESTAMPDIFF(SECOND, p.diajukan_pada, k.diputus_pada)';
    private const DURASI_TERSEDIA = '(p.diajukan_pada IS NOT NULL AND k.diputus_pada IS NOT NULL)';

    /**
     * FROM + JOIN yang dipakai SELURUH query laporan.
     *
     * Seluruh JOIN di sini bersifat 1:1 atau LEFT JOIN 1:1 (kunci unik pada
     * `izin_keputusan.pengajuan_id`), sehingga jumlah baris hasil selalu sama
     * dengan jumlah pengajuan. Ini yang membuat COUNT ringkasan identik dengan
     * jumlah baris detail/CSV.
     */
    private function fromClause(): string
    {
        return ' FROM izin_pengajuan p
                  JOIN santri s ON s.id = p.santri_id
                  LEFT JOIN pengurus pg ON pg.id = p.pengurus_id
                  LEFT JOIN guru g ON g.id = p.murobi_guru_id
                  LEFT JOIN tahun_ajaran ta ON ta.id = p.tahun_ajaran_id
                  LEFT JOIN izin_keputusan k ON k.pengajuan_id = p.id
                  LEFT JOIN users kp ON kp.id = k.diputus_oleh_user_id ';
    }

    /**
     * Daftar kolom detail. TIDAK memuat FROM: seluruh query laporan memakai
     * `fromClause()` yang sama persis, sehingga himpunan baris ringkasan,
     * detail, cetak, dan CSV dijamin identik untuk filter yang sama.
     */
    private function selectClause(): string
    {
        // Kamar/kelas memakai subquery skalar, BUKAN JOIN: satu santri dapat
        // memiliki lebih dari satu baris plotting dan JOIN akan menggandakan
        // baris pengajuan sehingga total detail berbeda dari total ringkasan.
        return "SELECT p.id, p.legacy_perizinan_id, p.is_legacy, p.santri_id, p.tgl_izin, p.tgl_kembali,
                       p.alasan, p.catatan_pengurus, p.status, p.version, p.diajukan_pada, p.created_at,
                       p.pengurus_id, p.murobi_guru_id, p.tahun_ajaran_id,
                       p.routing_kandidat, p.routing_catatan, p.routing_pada,
                       p.murobi_ditetapkan_pada, p.dibatalkan_pada, p.alasan_pembatalan,
                       s.nis, s.nama_santri,
                       pg.nama AS pengurus_nama, g.nama_guru AS murobi_nama,
                       ta.tahun AS tahun_ajaran, ta.semester AS semester,
                       k.hasil AS keputusan_hasil, k.alasan AS keputusan_alasan,
                       k.kapasitas AS keputusan_kapasitas, k.alasan_penggantian,
                       k.diputus_pada, k.jumlah_koreksi, k.dikoreksi_pada,
                       kp.name AS keputusan_oleh,
                       " . self::DURASI_DETIK . " AS durasi_keputusan_detik,
                       (SELECT km.nama_kamar FROM plotting_kamar pk
                          JOIN kamar km ON km.id = pk.id_kamar
                         WHERE pk.id_santri = p.santri_id
                           AND pk.id_tahun = COALESCE(p.tahun_ajaran_id, (SELECT ty.id FROM tahun_ajaran ty WHERE ty.status = 'Aktif' AND ty.archived_at IS NULL ORDER BY ty.id DESC LIMIT 1))
                         ORDER BY pk.id LIMIT 1) AS kamar_nama,
                       (SELECT kl.nama_kelas FROM plotting_kelas pl
                          JOIN kelas kl ON kl.id = pl.id_kelas
                         WHERE pl.id_santri = p.santri_id AND pl.status = 'Aktif'
                           AND pl.id_tahun = COALESCE(p.tahun_ajaran_id, (SELECT ty.id FROM tahun_ajaran ty WHERE ty.status = 'Aktif' AND ty.archived_at IS NULL ORDER BY ty.id DESC LIMIT 1))
                         ORDER BY pl.id LIMIT 1) AS kelas_nama,
                       (SELECT GROUP_CONCAT(DISTINCT o.kanal ORDER BY o.kanal SEPARATOR ', ')
                          FROM notifikasi_outbox o WHERE o.pengajuan_id = p.id) AS kanal_notifikasi ";
    }

    /**
     * SATU-SATUNYA pembangun klausa WHERE laporan.
     *
     * @return array{0:string, 1:array<int, mixed>}
     */
    private function conditions(IzinReportFilter $filter, bool $withDateRange = true): array
    {
        [$scopeWhere, $params] = $this->scopeConditions($filter);
        // `scopeConditions()` selalu mengembalikan minimal satu predikat, jadi
        // penggabungan di bawah tidak pernah menghasilkan WHERE kosong.
        $parts = [substr($scopeWhere, strlen(' WHERE '))];

        // --- 2. Rentang tanggal sesuai basis yang dipilih. -------------------
        if ($withDateRange) {
            switch ($filter->basisTanggal) {
                case 'pengajuan':
                    $parts[] = 'p.diajukan_pada IS NOT NULL AND DATE(p.diajukan_pada) BETWEEN ? AND ?';
                    $params[] = $filter->dateFrom;
                    $params[] = $filter->dateTo;
                    break;
                case 'keputusan':
                    $parts[] = 'k.diputus_pada IS NOT NULL AND DATE(k.diputus_pada) BETWEEN ? AND ?';
                    $params[] = $filter->dateFrom;
                    $params[] = $filter->dateTo;
                    break;
                default:
                    // Basis `izin`: pengajuan yang rentang izinnya BERSINGGUNGAN
                    // dengan rentang filter. Semantik ini sama dengan daftar
                    // perizinan Fase 1-2 sehingga angka laporan dan angka daftar
                    // tidak saling bertentangan.
                    $parts[] = 'p.tgl_kembali >= ? AND p.tgl_izin <= ?';
                    $params[] = $filter->dateFrom;
                    $params[] = $filter->dateTo;
            }
        }

        // --- 3. Filter yang hanya MEMPERSEMPIT. ------------------------------
        if ($filter->status !== null) {
            $parts[] = 'p.status = ?';
            $params[] = $filter->status;
        }
        if ($filter->santriId !== null) {
            $parts[] = 'p.santri_id = ?';
            $params[] = $filter->santriId;
        }
        if ($filter->pengurusId !== null) {
            $parts[] = 'p.pengurus_id = ?';
            $params[] = $filter->pengurusId;
        }
        if ($filter->murobiGuruId !== null) {
            $parts[] = 'p.murobi_guru_id = ?';
            $params[] = $filter->murobiGuruId;
        }
        if ($filter->tahunAjaranId !== null) {
            $parts[] = 'p.tahun_ajaran_id = ?';
            $params[] = $filter->tahunAjaranId;
        }
        if ($filter->kamarId !== null) {
            $parts[] = 'EXISTS (SELECT 1 FROM plotting_kamar pk
                                 WHERE pk.id_santri = p.santri_id AND pk.id_kamar = ?
                                   AND pk.id_tahun = COALESCE(p.tahun_ajaran_id, ?))';
            $params[] = $filter->kamarId;
            $params[] = $this->activeYearId();
        }
        if ($filter->kelasId !== null) {
            $parts[] = "EXISTS (SELECT 1 FROM plotting_kelas pl
                                 WHERE pl.id_santri = p.santri_id AND pl.id_kelas = ?
                                   AND pl.status = 'Aktif'
                                   AND pl.id_tahun = COALESCE(p.tahun_ajaran_id, ?))";
            $params[] = $filter->kelasId;
            $params[] = $this->activeYearId();
        }
        if ($filter->kanal !== null) {
            $parts[] = 'EXISTS (SELECT 1 FROM notifikasi_outbox o
                                 WHERE o.pengajuan_id = p.id AND o.kanal = ?)';
            $params[] = $filter->kanal;
        }
        if ($filter->durasiMinJam !== null) {
            $parts[] = self::DURASI_TERSEDIA . ' AND ' . self::DURASI_DETIK . ' >= ?';
            $params[] = $filter->durasiMinJam * 3600;
        }
        if ($filter->durasiMaksJam !== null) {
            $parts[] = self::DURASI_TERSEDIA . ' AND ' . self::DURASI_DETIK . ' <= ?';
            $params[] = $filter->durasiMaksJam * 3600;
        }
        if ($filter->sumber === 'legacy') {
            $parts[] = 'p.is_legacy = 1';
        } elseif ($filter->sumber === 'v2') {
            $parts[] = 'p.is_legacy = 0';
        }
        if ($filter->q !== '') {
            $parts[] = '(s.nama_santri LIKE ? OR s.nis LIKE ? OR p.alasan LIKE ?)';
            $params[] = '%' . $filter->q . '%';
            $params[] = '%' . $filter->q . '%';
            $params[] = '%' . $filter->q . '%';
        }

        return [' WHERE ' . implode(' AND ', $parts), $params];
    }

    /**
     * Predikat CAKUPAN saja — lapisan yang tidak pernah boleh dilewati.
     *
     * Selalu mengembalikan sedikitnya satu predikat, termasuk `1 = 0` untuk
     * cakupan yang tidak dikenal, sehingga tidak ada jalur kode yang dapat
     * menghasilkan query laporan tanpa batas cakupan.
     *
     * @return array{0:string, 1:array<int, mixed>}
     */
    private function scopeConditions(IzinReportFilter $filter): array
    {
        $scope = $filter->scope;
        $params = [];

        switch ($filter->scopeMode()) {
            case Capabilities::ADMIN:
                // Admin melihat seluruh pengajuan (PRD Fase 5 §1).
                $predikat = '1 = 1';
                break;
            case Capabilities::PENGURUS:
                $predikat = 'p.pengurus_id = ?';
                $params[] = (int) ($scope['pengurus_id'] ?? 0);
                break;
            case Capabilities::MUROBI:
                $predikat = 'p.murobi_guru_id = ?';
                $params[] = (int) ($scope['guru_id'] ?? 0);
                break;
            case Capabilities::ORANG_TUA:
                $predikat = 'p.santri_id IN (
                    SELECT sw.santri_id FROM santri_wali sw
                      JOIN wali w ON w.id = sw.wali_id
                     WHERE sw.wali_id = ? AND sw.archived_at IS NULL
                       AND w.is_active = 1 AND w.archived_at IS NULL)';
                $params[] = (int) ($scope['wali_id'] ?? 0);
                break;
            default:
                // Cakupan tidak dikenal: jangan pernah membocorkan satu baris pun.
                $predikat = '1 = 0';
        }

        return [' WHERE ' . $predikat, $params];
    }

    private ?int $activeYearId = null;

    private function activeYearId(): int
    {
        if ($this->activeYearId !== null) {
            return $this->activeYearId;
        }
        $row = $this->one(
            "SELECT id FROM tahun_ajaran WHERE status = 'Aktif' AND archived_at IS NULL ORDER BY id DESC LIMIT 1"
        );

        return $this->activeYearId = $row === null ? 0 : (int) $row['id'];
    }

    // -----------------------------------------------------------------------
    // Eksekusi
    // -----------------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    private function all(string $sql, array $params = []): array
    {
        $statement = $this->db->prepare($sql);
        if ($statement === false || !$this->run($statement, $params)) {
            throw new RuntimeException('Query laporan perizinan tidak dapat dijalankan.');
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
