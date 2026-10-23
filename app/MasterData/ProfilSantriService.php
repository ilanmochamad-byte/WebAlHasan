<?php

declare(strict_types=1);

namespace App\MasterData;

use mysqli;
use RuntimeException;

/**
 * Bacaan profil santri (redesain website Tahap 6).
 *
 *   - D-2  Profil 360° admin: ringkasan per tab (biodata, wali, kelas & kamar,
 *          izin, pembinaan, nilai & rapor) dengan tautan ke modul aslinya.
 *          Hanya dipanggil halaman ber-guard admin.
 *   - D-10 Profil santri binaan (keputusan AB, perubahan hak akses):
 *          pembimbing dan murobi membuka santri DALAM CAKUPAN penugasan aktif
 *          miliknya, hanya-baca. Cakupan diperiksa di server pada SETIAP
 *          permintaan oleh `cakupanBinaan()`; santri di luar cakupan dan santri
 *          yang tidak ada diperlakukan sama (null → 403), agar keberadaan data
 *          tidak bocor lewat ID.
 *
 * Cakupan binaan memakai aturan yang sama dengan modul pembinaan V3
 * (`PelanggaranRepository::scopeSql`): akun aktif + role dasar + data master
 * aktif + penugasan aktif pada tanggal berjalan + tahun ajaran aktif +
 * penempatan santri pada kamar/kelas binaan di tahun itu. Santri nonaktif atau
 * diarsipkan tidak termasuk.
 *
 * Layanan ini hanya membaca. Tidak ada tindakan ubah data dari profil binaan.
 */
final class ProfilSantriService
{
    public const BINAAN_PEMBIMBING = 'pembimbing';
    public const BINAAN_MUROBI = 'murobi';

    public function __construct(private mysqli $db)
    {
    }

    /**
     * Penugasan yang memberi akun hak membuka profil satu santri, atau null.
     *
     * @param 'pembimbing'|'murobi' $jenis
     * @return array{id:int, jenis:string, target_type:string, target_name:string}|null
     */
    public function cakupanBinaan(int $userId, string $jenis, int $santriId): ?array
    {
        if ($userId < 1 || $santriId < 1) {
            return null;
        }
        [$sql, $params] = $this->binaanSql($jenis, $userId);
        $row = $this->one($sql . ' AND s.id = ? ORDER BY a.id LIMIT 1', [...$params, $santriId]);

        return $row === null ? null : [
            'id' => (int) $row['assignment_id'],
            'jenis' => $jenis,
            'target_type' => (string) $row['target_type'],
            'target_name' => (string) $row['target_name'],
        ];
    }

    /**
     * Santri binaan akun (daftar D-10), dengan pencarian nama/NIS.
     *
     * @param 'pembimbing'|'murobi' $jenis
     * @return array{rows:array<int, array<string, mixed>>, total:int, page:int, perPage:int}
     */
    public function daftarBinaan(int $userId, string $jenis, string $q, int $page): array
    {
        [$sql, $params] = $this->binaanSql($jenis, $userId);
        $inner = 'SELECT s.id, s.nis, s.nama_santri, s.jenis_kelamin, MIN(CONCAT(a.target_type, \': \', ' . "COALESCE(km.nama_kamar, kl.nama_kelas, '')" . ')) target '
            . substr($sql, strpos($sql, ' FROM ')) . ' GROUP BY s.id, s.nis, s.nama_santri, s.jenis_kelamin';

        return \App\Database\PageQuery::fetch($this->db, $inner, $params, 'nama_santri, id', max(1, $page), $q, ['nama_santri', 'nis']);
    }

    /**
     * Ringkasan bersama D-2 dan D-10: identitas ringkas, kelas dan kamar tahun
     * ajaran aktif, wali utama, dan jumlah izin semester aktif.
     *
     * @return array<string, mixed>|null
     */
    public function ringkasan(int $santriId): ?array
    {
        $tahun = $this->one("SELECT id, tahun, semester FROM tahun_ajaran WHERE status = 'Aktif' AND archived_at IS NULL LIMIT 1");
        $tahunId = (int) ($tahun['id'] ?? 0);
        $santri = $this->one(
            "SELECT s.id, s.nis, s.nama_santri, s.jenis_kelamin, s.tempat_lahir, s.tgl_lahir, s.sekolah_saat_ini, s.is_active, s.archived_at, s.foto,
                    (SELECT k.nama_kelas FROM plotting_kelas pk JOIN kelas k ON k.id = pk.id_kelas WHERE pk.id_santri = s.id AND pk.id_tahun = ? AND pk.status = 'Aktif' ORDER BY pk.id DESC LIMIT 1) kelas,
                    (SELECT k.jenjang FROM plotting_kelas pk JOIN kelas k ON k.id = pk.id_kelas WHERE pk.id_santri = s.id AND pk.id_tahun = ? AND pk.status = 'Aktif' ORDER BY pk.id DESC LIMIT 1) jenjang,
                    (SELECT km.nama_kamar FROM plotting_kamar pm JOIN kamar km ON km.id = pm.id_kamar WHERE pm.id_santri = s.id AND pm.id_tahun = ? ORDER BY pm.id LIMIT 1) kamar
               FROM santri s WHERE s.id = ?",
            [$tahunId, $tahunId, $tahunId, $santriId]
        );
        if ($santri === null) {
            return null;
        }
        $wali = $this->one(
            'SELECT w.nama, sw.hubungan FROM santri_wali sw JOIN wali w ON w.id = sw.wali_id
              WHERE sw.santri_id = ? AND sw.archived_at IS NULL ORDER BY sw.is_primary DESC, sw.id LIMIT 1',
            [$santriId]
        );
        $izin = $tahunId > 0
            ? $this->one("SELECT COUNT(*) total, COALESCE(SUM(status = 'Disetujui'), 0) disetujui FROM izin_pengajuan WHERE santri_id = ? AND tahun_ajaran_id = ? AND status <> 'Dibatalkan'", [$santriId, $tahunId])
            : null;

        return $santri + [
            'tahun' => $tahun,
            'wali_utama' => $wali,
            'izin_semester' => (int) ($izin['total'] ?? 0),
            'izin_disetujui' => (int) ($izin['disetujui'] ?? 0),
        ];
    }

    /**
     * Izin satu santri (tab Izin D-2/D-10): nomor, rentang, dan status saja.
     * Alasan dan keputusan dibaca di halaman detail izin, yang memeriksa
     * hak aksesnya sendiri.
     *
     * @return array<int, array<string, mixed>>
     */
    public function izin(int $santriId, int $limit = 20): array
    {
        return $this->all(
            'SELECT id, tgl_izin, tgl_kembali, status, is_legacy, diajukan_pada, created_at FROM izin_pengajuan WHERE santri_id = ? ORDER BY tgl_izin DESC, id DESC LIMIT ?',
            [$santriId, max(1, min(100, $limit))]
        );
    }

    /**
     * Status sikap semester aktif (tab Sikap). Hanya status dan waktu; isian
     * sikap dibaca di halaman Sikap semester sesuai hak masing-masing.
     *
     * @return array<string, mixed>|null
     */
    public function sikap(int $santriId): ?array
    {
        return $this->one(
            "SELECT sk.status, sk.updated_at, sk.diketahui_pada FROM v4_sikap sk
               JOIN tahun_ajaran ta ON ta.id = sk.tahun_ajaran_id AND ta.status = 'Aktif' AND ta.archived_at IS NULL
              WHERE sk.santri_id = ? LIMIT 1",
            [$santriId]
        );
    }

    /** Total poin pembinaan tahun ajaran aktif (agregat V3). */
    public function totalPoin(int $santriId): int
    {
        $row = $this->one(
            "SELECT pa.total_poin FROM v3_poin_agregat pa JOIN tahun_ajaran ta ON ta.id = pa.tahun_ajaran_id AND ta.status = 'Aktif' AND ta.archived_at IS NULL
              WHERE pa.santri_id = ? AND pa.archived_at IS NULL LIMIT 1",
            [$santriId]
        );

        return (int) ($row['total_poin'] ?? 0);
    }

    /**
     * D-2 (admin): riwayat kamar lintas tahun ajaran.
     *
     * @return array<int, array<string, mixed>>
     */
    public function riwayatKamar(int $santriId): array
    {
        return $this->all(
            'SELECT pm.id, km.nama_kamar, ta.tahun, ta.semester, ta.status FROM plotting_kamar pm
               LEFT JOIN kamar km ON km.id = pm.id_kamar JOIN tahun_ajaran ta ON ta.id = pm.id_tahun
              WHERE pm.id_santri = ? ORDER BY ta.tahun DESC, ta.semester DESC, pm.id DESC',
            [$santriId]
        );
    }

    /**
     * D-2 (admin): rapor per semester (status dan versi terakhir).
     *
     * @return array<int, array<string, mixed>>
     */
    public function rapor(int $santriId): array
    {
        return $this->all(
            'SELECT r.id, r.status, r.current_version, ta.tahun, ta.semester FROM v4_rapor r
               JOIN tahun_ajaran ta ON ta.id = r.tahun_ajaran_id
              WHERE r.santri_id = ? ORDER BY ta.tahun DESC, ta.semester DESC',
            [$santriId]
        );
    }

    /**
     * @param 'pembimbing'|'murobi' $jenis
     * @return array{0:string, 1:array<int, mixed>}
     */
    private function binaanSql(string $jenis, int $userId): array
    {
        [$role, $master, $kolom, $tabel] = match ($jenis) {
            self::BINAAN_PEMBIMBING => ['pengurus', 'pengurus', 'pengurus_id', 'pembimbing_assignments'],
            self::BINAAN_MUROBI => ['guru', 'guru', 'guru_id', 'murobi_assignments'],
            default => throw new MasterDataException('Jenis binaan tidak dikenal.'),
        };

        return ["SELECT a.id assignment_id, a.target_type, COALESCE(km.nama_kamar, kl.nama_kelas) target_name
                   FROM users u
                   JOIN user_roles ur ON ur.user_id = u.id
                   JOIN roles r ON r.id = ur.role_id AND r.slug = '{$role}'
                   JOIN {$master} m ON m.id = u.{$kolom} AND m.is_active = 1 AND m.archived_at IS NULL
                   JOIN {$tabel} a ON a.{$kolom} = m.id
                   JOIN tahun_ajaran ta ON ta.id = a.tahun_ajaran_id AND ta.status = 'Aktif' AND ta.archived_at IS NULL
                   LEFT JOIN kamar km ON km.id = a.kamar_id
                   LEFT JOIN kelas kl ON kl.id = a.kelas_id
                   JOIN santri s ON s.is_active = 1 AND s.archived_at IS NULL
                    AND ((a.target_type = 'Kamar' AND EXISTS (SELECT 1 FROM plotting_kamar pm WHERE pm.id_santri = s.id AND pm.id_tahun = a.tahun_ajaran_id AND pm.id_kamar = a.kamar_id))
                      OR (a.target_type = 'Kelas' AND kl.is_active = 1 AND kl.archived_at IS NULL
                          AND EXISTS (SELECT 1 FROM plotting_kelas pk WHERE pk.id_santri = s.id AND pk.id_tahun = a.tahun_ajaran_id AND pk.id_kelas = a.kelas_id AND pk.status = 'Aktif')))
                  WHERE u.id = ? AND u.is_active = 1
                    AND a.is_active = 1 AND a.archived_at IS NULL
                    AND a.tanggal_mulai <= CURDATE() AND (a.tanggal_selesai IS NULL OR a.tanggal_selesai >= CURDATE())", [$userId]];
    }

    /** @param array<int, mixed> $params */
    private function one(string $sql, array $params = []): ?array
    {
        return $this->all($sql, $params)[0] ?? null;
    }

    /**
     * @param array<int, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    private function all(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            throw new RuntimeException('Profil santri tidak dapat dibaca.');
        }
        if ($params !== []) {
            $stmt->bind_param(implode('', array_map(static fn (mixed $v): string => is_int($v) ? 'i' : 's', $params)), ...$params);
        }
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Profil santri tidak dapat dibaca.');
        }
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows;
    }
}
