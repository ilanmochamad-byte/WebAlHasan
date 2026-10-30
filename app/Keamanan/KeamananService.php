<?php

declare(strict_types=1);

namespace App\Keamanan;

use App\Audit\AuditLogger;
use App\Auth\Capabilities;
use App\Izin\IzinLaporanService;
use App\Izin\IzinRepository;
use App\Izin\IzinWaliRepository;
use App\Izin\IzinWaliService;
use mysqli;
use RuntimeException;

/**
 * Rincian perizinan untuk Keamanan (penugasan lintas guru/pengurus Fase 3).
 *
 * Daftar dan cetak izin memakai `IzinLaporanService` dengan cakupan
 * `keamanan` (satu pembangun WHERE dengan laporan V7). Rincian dibaca di sini
 * karena Keamanan membaca pengajuan asal Pengurus (V2) maupun Wali (V7) pada
 * SEMUA tahap, sedangkan rincian V2 hanya memuat status V2 dan rincian V7
 * memproyeksikan isi menurut peran pembimbing/murobi/wali.
 *
 * Aturan yang dijaga:
 *   - Hak dihitung ulang setiap permintaan (`KeamananAkses::sah`): penugasan
 *     Keamanan aktif milik akun sendiri. Tanpa hak → 403.
 *   - Cakupan seluruh santri AKTIF dan belum diarsipkan (PK-5). Pengajuan
 *     santri nonaktif dan ID yang tidak ada diperlakukan sama: 404.
 *   - Isi lengkap: alasan, catatan, alasan keputusan, riwayat (PK-T4).
 *   - Hanya SELECT. Setiap pembukaan rincian tercatat di `audit_logs` TANPA
 *     isi (ID, asal, status). Bila audit gagal, rincian tidak dikembalikan.
 */
final class KeamananService
{
    public function __construct(
        private mysqli $db,
        private Capabilities $capabilities,
        private AuditLogger $audit,
        private IzinRepository $izin,
        private IzinWaliRepository $wali,
        private IzinLaporanService $laporan
    ) {
    }

    /**
     * @param array<string, mixed> $user
     */
    public function pastikanAkses(array $user): void
    {
        if (!KeamananAkses::sah($this->capabilities, $user)) {
            throw new KeamananException('Akun ini tidak memegang penugasan Keamanan yang aktif.', 403);
        }
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function izinDetail(array $user, int $id): array
    {
        $this->pastikanAkses($user);
        if (!$this->wali->fase2Terpasang()) {
            throw new KeamananException('Perizinan Keamanan memerlukan migrasi 030 dan 031.', 503);
        }
        $row = $this->izinBaris($id);
        if ($row === null) {
            throw new KeamananException('Pengajuan tidak ditemukan atau santrinya tidak aktif.', 404);
        }
        if (!$this->audit->log('keamanan.izin.dilihat', 'izin_pengajuan', $id, null, [
            'pengajuan_id' => $id, 'asal' => (string) $row['asal_pengajuan'], 'status' => (string) $row['status'],
        ], (int) $user['id'])) {
            throw new KeamananException('Audit akses tidak dapat disimpan. Rincian tidak ditampilkan.', 503);
        }
        $status = (string) $row['status'];

        return [
            'pengajuan' => [
                'id' => $id,
                'asal' => (string) $row['asal_pengajuan'],
                'warisan' => (int) $row['is_legacy'] === 1,
                'santri' => ['id' => (int) $row['santri_id'], 'nis' => (string) $row['nis'], 'nama' => (string) $row['nama_santri']],
                'kamar' => $row['kamar_nama'] === null ? null : (string) $row['kamar_nama'],
                'kelas' => $row['kelas_nama'] === null ? null : (string) $row['kelas_nama'],
                'tahun_ajaran' => $row['tahun'] === null ? null : trim((string) $row['semester'] . ' ' . (string) $row['tahun']),
                'tgl_izin' => (string) $row['tgl_izin'],
                'tgl_kembali' => (string) $row['tgl_kembali'],
                'alasan' => (string) $row['alasan'],
                'catatan_pengurus' => $row['catatan_pengurus'] === null ? null : (string) $row['catatan_pengurus'],
                'catatan_wali' => $row['catatan_wali'] === null ? null : (string) $row['catatan_wali'],
                'status' => $status,
                'status_label' => IzinWaliService::statusLabel($status),
                'tahap' => IzinWaliService::tahap($status),
                'hasil' => IzinLaporanService::hasil($status),
                'diajukan_pada' => $row['diajukan_pada'] === null ? null : (string) $row['diajukan_pada'],
                'pengaju' => $row['pengaju_nama'] === null ? null : (string) $row['pengaju_nama'],
                'pengurus' => $row['pengurus_nama'] === null ? null : (string) $row['pengurus_nama'],
                'wali' => $row['wali_nama'] === null ? null : (string) $row['wali_nama'],
                'pembimbing' => $row['pembimbing_nama'] === null ? null : (string) $row['pembimbing_nama'],
                'murobi' => $row['murobi_nama'] === null ? null : (string) $row['murobi_nama'],
                'dibatalkan_pada' => $row['dibatalkan_pada'] === null ? null : (string) $row['dibatalkan_pada'],
                'alasan_pembatalan' => $row['alasan_pembatalan'] === null ? null : (string) $row['alasan_pembatalan'],
            ],
            'keputusan' => $this->izin->decision($id),
            'riwayat' => $this->izin->history($id),
            'koreksi' => $this->izin->corrections($id),
        ];
    }

    /**
     * Seluruh baris laporan izin Keamanan untuk halaman cetak (PK-T2: cetak
     * PDF lewat dialog cetak peramban; ekspor CSV TIDAK disediakan). Kriteria
     * dan cakupan sama dengan daftar. Dicatat di audit tanpa isi.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function izinCetak(array $user, array $query): array
    {
        $this->pastikanAkses($user);
        $dokumen = $this->laporan->cetakKeamanan(KeamananAkses::tandai($user), $query);
        if (!$this->audit->log('keamanan.izin.dicetak', 'izin_pengajuan', null, null, [
            'filter' => array_filter($dokumen['filter'], static fn ($v): bool => $v !== null), 'jumlah' => count($dokumen['items']),
        ], (int) $user['id'])) {
            throw new KeamananException('Audit akses tidak dapat disimpan. Laporan tidak dicetak.', 503);
        }

        return $dokumen;
    }

    /** @return array<string, mixed>|null */
    private function izinBaris(int $id): ?array
    {
        $statement = $this->db->prepare(
            "SELECT p.id, p.asal_pengajuan, p.is_legacy, p.santri_id, s.nis, s.nama_santri,
                    p.tgl_izin, p.tgl_kembali, p.alasan, p.catatan_pengurus, p.catatan_wali, p.status,
                    COALESCE(p.diajukan_pada, p.created_at) AS diajukan_pada, p.dibatalkan_pada, p.alasan_pembatalan,
                    ta.tahun, ta.semester, uj.name AS pengaju_nama, pg.nama AS pengurus_nama, w.nama AS wali_nama,
                    pb.nama AS pembimbing_nama, g.nama_guru AS murobi_nama,
                    (SELECT km.nama_kamar FROM plotting_kamar pk JOIN kamar km ON km.id = pk.id_kamar
                      WHERE pk.id_santri = p.santri_id AND pk.id_tahun = p.tahun_ajaran_id ORDER BY pk.id LIMIT 1) AS kamar_nama,
                    (SELECT kl.nama_kelas FROM plotting_kelas pl JOIN kelas kl ON kl.id = pl.id_kelas
                      WHERE pl.id_santri = p.santri_id AND pl.status = 'Aktif' AND pl.id_tahun = p.tahun_ajaran_id
                      ORDER BY pl.id LIMIT 1) AS kelas_nama
               FROM izin_pengajuan p
               JOIN santri s ON s.id = p.santri_id AND s.is_active = 1 AND s.archived_at IS NULL
               LEFT JOIN tahun_ajaran ta ON ta.id = p.tahun_ajaran_id
               LEFT JOIN users uj ON uj.id = p.diajukan_oleh_user_id
               LEFT JOIN pengurus pg ON pg.id = p.pengurus_id
               LEFT JOIN wali w ON w.id = p.wali_id
               LEFT JOIN pengurus pb ON pb.id = p.pembimbing_pengurus_id
               LEFT JOIN guru g ON g.id = p.murobi_guru_id
              WHERE p.id = ?
              LIMIT 1"
        );
        if ($statement === false) {
            throw new RuntimeException('Rincian perizinan tidak dapat dibaca.');
        }
        $statement->bind_param('i', $id);
        if (!$statement->execute()) {
            $statement->close();
            throw new RuntimeException('Rincian perizinan tidak dapat dibaca.');
        }
        $row = ($statement->get_result() ?: null)?->fetch_assoc();
        $statement->close();

        return is_array($row) ? $row : null;
    }
}
