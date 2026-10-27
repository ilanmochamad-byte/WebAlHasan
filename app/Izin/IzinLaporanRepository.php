<?php

declare(strict_types=1);

namespace App\Izin;

use mysqli;
use RuntimeException;

/**
 * Akses baca laporan perizinan lintas asal wali/pengurus (PRD V7 Fase 3).
 *
 * Sifat yang dijaga (sama dengan laporan V2 `IzinReportRepository`):
 *
 *  1. **Satu definisi kriteria.** `conditions()` adalah satu-satunya pembangun
 *     klausa WHERE; kartu ringkasan, daftar berhalaman, dan ekspor memakainya
 *     dengan `fromClause()` yang sama, sehingga angka tidak dapat menyimpang.
 *  2. **Cakupan selalu di SQL** dan berasal dari akun (bukan request). Cakupan
 *     tidak dikenal menghasilkan `1 = 0`. Predikat cakupan sama dengan aturan
 *     rincian: pembimbing sah (`PembimbingRouter::sahSql`) untuk baris wali,
 *     `pengurus_id` untuk baris pengurus, `murobi_guru_id` + status V2 untuk
 *     murobi, dan relasi wali aktif untuk orang tua.
 *  3. **Satu baris per pengajuan.** Kamar, kelas, dan waktu tinjauan dibaca
 *     lewat subquery skalar; `izin_keputusan` unik per pengajuan.
 *  4. **Tanpa isi izin.** Alasan, catatan, dan alasan keputusan tidak dibaca.
 *  5. **Baca saja.**
 */
final class IzinLaporanRepository
{
    public const MAX_EKSPOR = 5000;

    /** Status per tahap (label tahap sama dengan `IzinWaliService::tahap`). */
    public const TAHAP = [
        'pembimbing' => [IzinWaliRepository::STATUS_MENUNGGU_PEMBIMBING],
        'penetapan_pembimbing' => [IzinWaliRepository::STATUS_PERLU_PENETAPAN_PEMBIMBING],
        'perbaikan_wali' => [IzinWaliRepository::STATUS_PERLU_PERBAIKAN],
        'murobi' => [IzinRouter::STATUS_TERARAH],
        'penetapan_murobi' => [IzinRouter::STATUS_PERLU_ADMIN],
        'selesai' => [IzinWaliRepository::STATUS_DITOLAK_PEMBIMBING, 'Disetujui', 'Ditolak', 'Dibatalkan'],
    ];

    /** Status per hasil. */
    public const HASIL = [
        'berjalan' => [
            IzinWaliRepository::STATUS_MENUNGGU_PEMBIMBING, IzinWaliRepository::STATUS_PERLU_PENETAPAN_PEMBIMBING,
            IzinWaliRepository::STATUS_PERLU_PERBAIKAN, IzinRouter::STATUS_TERARAH, IzinRouter::STATUS_PERLU_ADMIN,
        ],
        'disetujui' => ['Disetujui'],
        'ditolak_murobi' => ['Ditolak'],
        'ditolak_pembimbing' => [IzinWaliRepository::STATUS_DITOLAK_PEMBIMBING],
        'dibatalkan' => ['Dibatalkan'],
    ];

    private const MULAI = 'COALESCE(p.diajukan_pada, p.created_at)';
    private const DITERUSKAN = "(SELECT MAX(tt.ditinjau_pada) FROM izin_pembimbing_tinjauan tt WHERE tt.pengajuan_id = p.id AND tt.tindakan = 'Teruskan')";
    private const DITOLAK_PB = "(SELECT MAX(tt.ditinjau_pada) FROM izin_pembimbing_tinjauan tt WHERE tt.pengajuan_id = p.id AND tt.tindakan = 'Tolak')";

    public function __construct(private mysqli $db)
    {
    }

    /** Seluruh bacaan satu respons memakai keadaan DB yang sama. */
    public function snapshot(callable $kerja): mixed
    {
        if (!$this->db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')
            || !$this->db->query('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY')) {
            throw new RuntimeException('Snapshot laporan perizinan tidak dapat dimulai.');
        }
        try {
            $hasil = $kerja();
            if (!$this->db->commit()) {
                throw new RuntimeException('Snapshot laporan perizinan tidak dapat diselesaikan.');
            }

            return $hasil;
        } catch (\Throwable $exception) {
            $this->db->rollback();
            throw $exception;
        }
    }

    /**
     * Kartu: jumlah per asal × status dan rerata lama tiap tahap untuk kriteria
     * yang SAMA dengan daftar dan ekspor.
     *
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $cakupan
     * @return array{per_asal_status:array<int,array<string,mixed>>, rerata:array<string,mixed>}
     */
    public function ringkasan(array $filter, array $cakupan): array
    {
        [$where, $params] = $this->conditions($filter, $cakupan);
        $per = $this->all('SELECT p.asal_pengajuan AS asal, p.status, COUNT(*) AS jumlah' . $this->fromClause() . $where
            . ' GROUP BY p.asal_pengajuan, p.status ORDER BY p.asal_pengajuan, p.status', $params);
        $rerata = $this->one('SELECT AVG(' . $this->lamaPembimbing() . ') AS pembimbing, COUNT(' . $this->lamaPembimbing() . ') AS n_pembimbing,
                                     AVG(' . $this->lamaMurobi() . ') AS murobi, COUNT(' . $this->lamaMurobi() . ') AS n_murobi,
                                     AVG(' . $this->lamaTotal() . ') AS total, COUNT(' . $this->lamaTotal() . ') AS n_total'
            . $this->fromClause() . $where, $params) ?? [];

        return ['per_asal_status' => $per, 'rerata' => $rerata];
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $cakupan
     * @return array{rows:array<int,array<string,mixed>>, total:int}
     */
    public function halaman(array $filter, array $cakupan, int $page, int $perPage): array
    {
        [$where, $params] = $this->conditions($filter, $cakupan);
        $total = (int) ($this->one('SELECT COUNT(*) AS jumlah' . $this->fromClause() . $where, $params)['jumlah'] ?? 0);
        $rows = $this->all($this->selectClause() . $this->fromClause() . $where . ' ORDER BY p.id DESC LIMIT ? OFFSET ?',
            array_merge($params, [$perPage, max(0, ($page - 1) * $perPage)]));

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $cakupan
     * @return array<int, array<string, mixed>>
     */
    public function semua(array $filter, array $cakupan, int $batas = self::MAX_EKSPOR): array
    {
        [$where, $params] = $this->conditions($filter, $cakupan);

        return $this->all($this->selectClause() . $this->fromClause() . $where . ' ORDER BY p.id DESC LIMIT ?',
            array_merge($params, [$batas + 1]));
    }

    // -----------------------------------------------------------------------

    private function fromClause(): string
    {
        return ' FROM izin_pengajuan p
                  JOIN santri s ON s.id = p.santri_id
                  LEFT JOIN pengurus pg ON pg.id = p.pengurus_id
                  LEFT JOIN pengurus pb ON pb.id = p.pembimbing_pengurus_id
                  LEFT JOIN guru g ON g.id = p.murobi_guru_id
                  LEFT JOIN izin_keputusan k ON k.pengajuan_id = p.id ';
    }

    private function selectClause(): string
    {
        return "SELECT p.id, p.asal_pengajuan, p.is_legacy, p.santri_id, s.nis, s.nama_santri,
                       p.tgl_izin, p.tgl_kembali, p.status, p.wali_id, p.diajukan_oleh_user_id,
                       " . self::MULAI . " AS diajukan_pada,
                       pg.nama AS pengurus_nama, pb.nama AS pembimbing_nama, g.nama_guru AS murobi_nama,
                       k.hasil AS keputusan_hasil, k.kapasitas AS keputusan_kapasitas, k.diputus_pada,
                       " . self::DITERUSKAN . " AS diteruskan_pada,
                       " . $this->selesai() . " AS selesai_pada,
                       " . $this->lamaPembimbing() . " AS lama_pembimbing_detik,
                       " . $this->lamaMurobi() . " AS lama_murobi_detik,
                       " . $this->lamaTotal() . " AS lama_total_detik,
                       (SELECT km.nama_kamar FROM plotting_kamar pk JOIN kamar km ON km.id = pk.id_kamar
                         WHERE pk.id_santri = p.santri_id AND pk.id_tahun = p.tahun_ajaran_id ORDER BY pk.id LIMIT 1) AS kamar_nama,
                       (SELECT kl.nama_kelas FROM plotting_kelas pl JOIN kelas kl ON kl.id = pl.id_kelas
                         WHERE pl.id_santri = p.santri_id AND pl.status = 'Aktif' AND pl.id_tahun = p.tahun_ajaran_id
                         ORDER BY pl.id LIMIT 1) AS kelas_nama ";
    }

    /** Waktu pengajuan keluar dari alur (keputusan, penolakan pembimbing, pembatalan). */
    private function selesai(): string
    {
        return "(CASE WHEN p.status IN ('Disetujui', 'Ditolak') THEN k.diputus_pada
                      WHEN p.status = 'Ditolak Pembimbing' THEN " . self::DITOLAK_PB . "
                      WHEN p.status = 'Dibatalkan' THEN p.dibatalkan_pada END)";
    }

    /** Baris wali: diajukan → penerusan/penolakan pembimbing (termasuk siklus perbaikan). */
    private function lamaPembimbing(): string
    {
        return "(CASE WHEN p.asal_pengajuan = 'Wali' THEN TIMESTAMPDIFF(SECOND, " . self::MULAI . ', COALESCE('
            . self::DITERUSKAN . ', ' . self::DITOLAK_PB . ')) END)';
    }

    /** Penerusan (wali) atau pengajuan (pengurus) → keputusan. */
    private function lamaMurobi(): string
    {
        return "(CASE WHEN k.diputus_pada IS NOT NULL THEN TIMESTAMPDIFF(SECOND, CASE WHEN p.asal_pengajuan = 'Wali' THEN "
            . self::DITERUSKAN . ' ELSE ' . self::MULAI . ' END, k.diputus_pada) END)';
    }

    private function lamaTotal(): string
    {
        return 'TIMESTAMPDIFF(SECOND, ' . self::MULAI . ', ' . $this->selesai() . ')';
    }

    /**
     * SATU-SATUNYA pembangun klausa WHERE laporan V7.
     *
     * @param array<string, mixed> $filter  sudah tervalidasi layanan
     * @param array<string, mixed> $cakupan dari akun (mode, pengurus_id, guru_id, wali_id)
     * @return array{0:string, 1:array<int, mixed>}
     */
    private function conditions(array $filter, array $cakupan): array
    {
        $onDate = (string) $cakupan['tanggal'];
        $v2 = IzinRepository::STATUSES;
        $isi = static fn (array $nilai): string => implode(',', array_fill(0, count($nilai), '?'));
        [$parts, $params] = match ((string) $cakupan['mode']) {
            'admin' => [['1 = 1'], []],
            'pengurus' => [["((p.asal_pengajuan = 'Pengurus' AND p.pengurus_id = ?)
                    OR (p.asal_pengajuan = 'Wali' AND p.pembimbing_pengurus_id = ? AND s.is_active = 1 AND s.archived_at IS NULL AND "
                    . PembimbingRouter::sahSql('p.pembimbing_pengurus_id', 'p.santri_id') . '))'],
                [(int) $cakupan['pengurus_id'], (int) $cakupan['pengurus_id'], $onDate, $onDate]],
            'murobi' => [['p.murobi_guru_id = ? AND p.status IN (' . $isi($v2) . ") AND (p.asal_pengajuan = 'Pengurus' OR "
                . IzinRouter::sahSql('p.murobi_guru_id', 'p.santri_id') . ')'], array_merge([(int) $cakupan['guru_id']], $v2, [$onDate, $onDate])],
            'orang_tua' => [["p.santri_id IN (SELECT sw.santri_id FROM santri_wali sw JOIN wali w ON w.id = sw.wali_id
                                  WHERE sw.wali_id = ? AND sw.archived_at IS NULL AND w.is_active = 1 AND w.archived_at IS NULL)
                    AND (p.asal_pengajuan = 'Wali' OR p.status IN (" . $isi($v2) . '))'], array_merge([(int) $cakupan['wali_id']], $v2)],
            default => [['1 = 0'], []],
        };

        if (($filter['asal'] ?? null) !== null) {
            $parts[] = 'p.asal_pengajuan = ?';
            $params[] = $filter['asal'] === 'wali' ? 'Wali' : 'Pengurus';
        }
        foreach (['tahap' => self::TAHAP, 'hasil' => self::HASIL] as $kunci => $peta) {
            if (($filter[$kunci] ?? null) !== null) {
                $parts[] = 'p.status IN (' . $isi($peta[$filter[$kunci]]) . ')';
                array_push($params, ...$peta[$filter[$kunci]]);
            }
        }
        if (($filter['dari'] ?? null) !== null) {
            if (($filter['basis'] ?? 'izin') === 'diajukan') {
                $parts[] = 'DATE(' . self::MULAI . ') BETWEEN ? AND ?';
            } else {
                // Rentang izin yang BERSINGGUNGAN dengan rentang filter (semantik V2).
                $parts[] = 'p.tgl_kembali >= ? AND p.tgl_izin <= ?';
            }
            $params[] = $filter['dari'];
            $params[] = $filter['sampai'];
        }
        foreach (['santri_id' => 'p.santri_id', 'pembimbing_id' => 'p.pembimbing_pengurus_id', 'murobi_id' => 'p.murobi_guru_id'] as $kunci => $kolom) {
            if (($filter[$kunci] ?? null) !== null) {
                $parts[] = $kolom . ' = ?';
                $params[] = (int) $filter[$kunci];
            }
        }
        if (($filter['kamar_id'] ?? null) !== null) {
            $parts[] = 'EXISTS (SELECT 1 FROM plotting_kamar pk WHERE pk.id_santri = p.santri_id AND pk.id_kamar = ? AND pk.id_tahun = p.tahun_ajaran_id)';
            $params[] = (int) $filter['kamar_id'];
        }
        if (($filter['kelas_id'] ?? null) !== null) {
            $parts[] = "EXISTS (SELECT 1 FROM plotting_kelas pl WHERE pl.id_santri = p.santri_id AND pl.id_kelas = ? AND pl.status = 'Aktif' AND pl.id_tahun = p.tahun_ajaran_id)";
            $params[] = (int) $filter['kelas_id'];
        }
        if (($filter['durasi_min_jam'] ?? null) !== null) {
            $parts[] = $this->lamaTotal() . ' >= ?';
            $params[] = (int) $filter['durasi_min_jam'] * 3600;
        }
        if (($filter['durasi_maks_jam'] ?? null) !== null) {
            $parts[] = $this->lamaTotal() . ' <= ?';
            $params[] = (int) $filter['durasi_maks_jam'] * 3600;
        }

        return [' WHERE ' . implode(' AND ', $parts), $params];
    }

    /** @return array<string, mixed>|null */
    private function one(string $sql, array $params): ?array
    {
        return $this->all($sql, $params)[0] ?? null;
    }

    /** @return array<int, array<string, mixed>> */
    private function all(string $sql, array $params): array
    {
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            throw new RuntimeException('Laporan perizinan tidak dapat dibaca.');
        }
        if ($params !== []) {
            $stmt->bind_param(implode('', array_map(static fn ($v): string => is_int($v) ? 'i' : 's', $params)), ...$params);
        }
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Laporan perizinan tidak dapat dibaca.');
        }
        $result = $stmt->get_result();
        $rows = [];
        while ($result !== false && ($row = $result->fetch_assoc()) !== null) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }
}
