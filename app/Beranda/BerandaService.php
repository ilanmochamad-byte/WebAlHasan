<?php

declare(strict_types=1);

namespace App\Beranda;

use App\Auth\Capabilities;
use App\Penugasan\PenugasanJenis;
use App\V3\LaporanService;
use App\V3\PelanggaranRepository;
use App\V4\BahanRepository;
use App\V4\NilaiService;
use App\V4\SikapService;
use App\V4\VerifikasiService;
use mysqli;
use RuntimeException;
use Throwable;

/**
 * Data Beranda per peran (redesain website Tahap 2, B-1 s.d. B-5).
 *
 * Kelas ini HANYA MEMBACA. Setiap angka dihitung pada cakupan nyata akun:
 *
 *   - admin memakai seluruh data (pengawasan) — sama dengan halaman tujuan;
 *   - guru, murobi, pengurus, Bagian Pendidikan, dan orang tua memakai
 *     cakupan penugasan/relasi mereka sendiri, lewat layanan yang sudah ada
 *     (`izin_service()`, `capabilities()`) atau query yang memakai cakupan
 *     yang sama.
 *
 * Beranda bukan kontrol akses: kartu hanya menyebut jumlah dan menautkan ke
 * halaman yang tetap menjaga dirinya sendiri. Kegagalan satu kartu (mis. tabel
 * modul yang belum dimigrasi) tidak boleh menjatuhkan Beranda; kartu itu
 * disembunyikan dan dicatat ke log.
 */
final class BerandaService
{
    /** Batas tertunda: lebih dari 1 hari (keputusan P). */
    public const BATAS_TERTUNDA_JAM = 24;

    /** Jumlah tempat kartu "Perlu tindakan" admin (keputusan L). */
    public const TEMPAT_PRIORITAS = 4;

    private ?array $tahunAktif = null;

    public function __construct(private mysqli $db, private Capabilities $caps)
    {
    }

    // ------------------------------------------------------------------
    // B-1 Admin
    // ------------------------------------------------------------------

    /**
     * Delapan kartu prioritas admin dalam urutan keputusan L + AA. Kartu
     * bernilai 0 atau yang gagal dihitung tidak ikut.
     *
     * Setiap angka dihitung dengan predikat yang SAMA dengan daftar tujuan
     * kartunya (layanan atau filter server yang sama), sehingga klik kartu
     * selalu membuka tepat himpunan yang dihitung (B-1).
     *
     * @param array<string, mixed> $user akun admin yang membuka Beranda.
     * @return array<int, array{key:string, jumlah:int, judul:string, tombol:string, url:string, tertunda:bool}>
     */
    public function prioritasAdmin(array $user): array
    {
        $tahun = $this->tahunAktifId();
        $kartu = [];

        // Antrean admin = `Perlu Penetapan Admin` (IzinRepository, filter antrean).
        $this->kartu($kartu, 'izin_penetapan', function () use ($user): array {
            $jumlah = izin_service()->queueCount($user, Capabilities::ADMIN);
            $row = $this->one("SELECT SUM(COALESCE(routing_pada, diajukan_pada, created_at) < NOW() - INTERVAL ? HOUR) AS lama
                                 FROM izin_pengajuan WHERE status = 'Perlu Penetapan Admin'", 'i', [self::BATAS_TERTUNDA_JAM]);
            return [$jumlah, (int) $row['lama'] > 0];
        }, 'Izin menunggu penetapan murobi', 'Tetapkan', '/portal/izin_antrean.php');

        // Tujuan memakai filter status yang sama (`admin_data.php?status=Baru`).
        $this->kartu($kartu, 'psb_baru', function (): array {
            $row = $this->one("SELECT COUNT(*) AS n, SUM(tgl_daftar < NOW() - INTERVAL ? HOUR) AS lama FROM psb_pendaftar WHERE status = 'Baru'", 'i', [self::BATAS_TERTUNDA_JAM]);
            return [(int) $row['n'], (int) $row['lama'] > 0];
        }, 'Pendaftar PSB baru', 'Periksa', '/admin/admin_data.php?status=Baru');

        // Sama dengan daftar `admin_pembayaran_psb.php?status=Belum Lunas`
        // (pembayaran yang terhubung ke pendaftar).
        $this->kartu($kartu, 'psb_belum_lunas', function (): array {
            $row = $this->one("SELECT COUNT(*) AS n, SUM(pb.tanggal < NOW() - INTERVAL ? HOUR) AS lama
                                 FROM psb_pembayaran pb JOIN psb_pendaftar p ON p.no_pendaftaran = pb.no_pendaftaran
                                WHERE pb.status_pembayaran = 'Belum Lunas'", 'i', [self::BATAS_TERTUNDA_JAM]);
            return [(int) $row['n'], (int) $row['lama'] > 0];
        }, 'Pembayaran PSB belum lunas', 'Lihat', '/admin/admin_pembayaran_psb.php?status=' . rawurlencode('Belum Lunas'));

        // Sama dengan daftar "Pengiriman gagal" (NotificationRepository::failures).
        $this->kartu($kartu, 'notifikasi_gagal', function () use ($user): array {
            $jumlah = (int) notification_admin_service()->kegagalan($user, ['per_page' => 1])['pagination']['total'];
            $row = $this->one("SELECT SUM(updated_at < NOW() - INTERVAL ? HOUR) AS lama FROM notifikasi_outbox WHERE status = 'Failed'", 'i', [self::BATAS_TERTUNDA_JAM]);
            return [$jumlah, (int) $row['lama'] > 0];
        }, 'Notifikasi gagal terkirim', 'Periksa', '/admin/admin_notifikasi.php?tab=gagal#pengiriman-gagal');

        // Filter `tertunda=1` IzinRepository: Diajukan lebih dari 24 jam.
        $this->kartu($kartu, 'izin_tertunda', function () use ($user): array {
            $jumlah = (int) izin_service()->list($user, ['status' => 'Diajukan', 'tertunda' => '1'], 1, 1, Capabilities::ADMIN)['total'];
            return [$jumlah, true];
        }, 'Izin belum diputus lebih dari 1 hari', 'Lihat', '/portal/izin.php?status=Diajukan&tertunda=1');

        // Status EFEKTIF bahan (sumber berubah = perlu verifikasi ulang), sama
        // dengan matriks kelengkapan; tujuan menyaring bahan Dikembalikan.
        $perbaikanPerRencana = [];
        $this->kartu($kartu, 'bahan_perbaikan', function () use ($tahun, &$perbaikanPerRencana): array {
            $jumlah = 0;
            $lama = false;
            foreach ($this->rencanaAktif($tahun) as $rencana) {
                $ringkas = $this->ringkasBahan((int) $rencana['id'], $tahun, (int) $rencana['kelas_id']);
                if ($ringkas['perbaikan'] > 0) {
                    $perbaikanPerRencana[] = (int) $rencana['id'];
                }
                $jumlah += $ringkas['perbaikan'];
                $lama = $lama || $ringkas['perbaikan_lama'];
            }
            return [$jumlah, $lama];
        }, 'Bahan rapor perlu perbaikan', 'Lihat', '/portal/verifikasi_semester.php?status=Dikembalikan');
        if (isset($kartu['bahan_perbaikan']) && count($perbaikanPerRencana) === 1) {
            $kartu['bahan_perbaikan']['url'] = app_url('/portal/verifikasi_semester.php?rencana_id=' . $perbaikanPerRencana[0] . '&status=Dikembalikan');
        }

        // Filter penempatan `tanpa_tempat` (tanpa kelas ATAU tanpa kamar).
        $this->kartu($kartu, 'santri_tanpa_tempat', function () use ($tahun): array {
            $jumlah = $tahun > 0 ? (int) penempatan_service()->listPage(['status' => 'tanpa_tempat'], $tahun, 1, 1)['total'] : 0;
            return [$jumlah, false];
        }, 'Santri tanpa kelas atau kamar', 'Tempatkan', '/admin/admin_penempatan_santri.php?status=tanpa_tempat');

        // Daftar rekomendasi yang sama dengan halaman Konseling
        // (KonselingRepository::pendingRecommendations).
        $this->kartu($kartu, 'rekomendasi', function () use ($user): array {
            $jumlah = count(v3_konseling_service()->options($user)['rekomendasi_belum_ditindaklanjuti']);
            $row = $this->one("SELECT SUM(created_at < NOW() - INTERVAL ? HOUR) AS lama FROM v3_rekomendasi
                                WHERE status = 'Baru' AND ditindaklanjuti_kasus_id IS NULL AND tidak_berlaku_pada IS NULL AND archived_at IS NULL",
                'i', [self::BATAS_TERTUNDA_JAM]);
            return [$jumlah, (int) $row['lama'] > 0];
        }, 'Rekomendasi pembinaan belum ditindaklanjuti', 'Lihat', '/portal/v3_konseling.php#rekomendasi');

        return array_values($kartu);
    }

    /**
     * @return array<int, array{label:string, nilai:string, url?:string}>
     */
    public function statistikAdmin(): array
    {
        $tahun = $this->tahunAktifId();
        $hari = $this->hariIni();
        $stat = [];
        $this->stat($stat, 'Santri aktif', fn (): string => $this->angka((int) $this->one(
            'SELECT COUNT(*) AS n FROM santri WHERE is_active = 1 AND archived_at IS NULL'
        )['n']), '/admin/admin_master_santri.php');
        $this->stat($stat, 'Izin aktif hari ini', fn (): string => $this->angka((int) $this->one(
            "SELECT COUNT(*) AS n FROM izin_pengajuan WHERE status = 'Disetujui' AND tgl_izin <= CURDATE() AND tgl_kembali >= CURDATE()"
        )['n']), '/portal/izin.php?status=Disetujui&date_from=' . date('Y-m-d') . '&date_to=' . date('Y-m-d'));
        $this->stat($stat, 'Pertemuan hari ini', function () use ($tahun, $hari): string {
            $row = $this->one("SELECT COUNT(*) AS jadwal,
                        SUM(EXISTS (SELECT 1 FROM pertemuan_pengajian p WHERE p.jadwal_id = j.id AND p.tanggal_pertemuan = CURDATE() AND p.status IN ('Dibuka', 'Selesai'))) AS jalan
                      FROM jadwal_ngaji j WHERE j.id_tahun = ? AND j.hari = ? AND j.is_active = 1 AND j.archived_at IS NULL", 'is', [$tahun, $hari]);
            return (int) $row['jalan'] . '/' . (int) $row['jadwal'];
        }, '/admin/admin_pengajian.php?tab=pertemuan');
        $this->stat($stat, 'Hunian asrama', function () use ($tahun): string {
            $row = $this->one('SELECT (SELECT COALESCE(SUM(kapasitas), 0) FROM kamar) AS kapasitas,
                        (SELECT COUNT(DISTINCT pm.id_santri) FROM plotting_kamar pm JOIN santri s ON s.id = pm.id_santri
                          WHERE pm.id_tahun = ? AND s.is_active = 1 AND s.archived_at IS NULL) AS terisi', 'i', [$tahun]);
            $kapasitas = (int) $row['kapasitas'];
            return $kapasitas > 0 ? (string) round(100 * (int) $row['terisi'] / $kapasitas) . '%' : '—';
        }, '/admin/admin_kamar.php');

        return $stat;
    }

    /**
     * Jadwal pengajian hari ini beserta status pertemuannya. Guru hanya
     * jadwalnya sendiri; admin seluruh jadwal.
     *
     * @param array<string, mixed> $user
     * @return array<int, array<string, mixed>>
     */
    public function jadwalHariIni(array $user, bool $semua, int $batas = 10): array
    {
        $tahun = $this->tahunAktifId();
        $guruId = (int) ($user['guru_id'] ?? 0);
        if (!$semua && $guruId < 1) {
            return [];
        }
        $rows = $this->all(
            "SELECT j.id, j.waktu_sholat, j.waktu_mulai, j.waktu_selesai, j.fan_ilmu, j.nama_kitab, j.tempat,
                    k.nama_kelas, g.nama_guru, p.id AS pertemuan_id, p.status AS pertemuan_status
               FROM jadwal_ngaji j
               JOIN kelas k ON k.id = j.id_kelas
               LEFT JOIN guru g ON g.id = j.id_guru
               LEFT JOIN pertemuan_pengajian p ON p.jadwal_id = j.id AND p.tanggal_pertemuan = CURDATE()
              WHERE j.id_tahun = ? AND j.hari = ? AND j.is_active = 1 AND j.archived_at IS NULL"
                . ($semua ? '' : ' AND j.id_guru = ?') . '
              ORDER BY j.waktu_mulai IS NULL, j.waktu_mulai, k.nama_kelas
              LIMIT ' . max(1, $batas),
            $semua ? 'is' : 'isi',
            $semua ? [$tahun, $this->hariIni()] : [$tahun, $this->hariIni(), $guruId]
        );
        $sekarang = date('H:i:s');
        foreach ($rows as &$row) {
            $status = (string) ($row['pertemuan_status'] ?? '');
            $mulai = (string) ($row['waktu_mulai'] ?? '');
            $row['tindakan'] = match (true) {
                $status === 'Selesai' => ['label' => 'Selesai', 'url' => app_url('/admin/admin_pengajian.php?tab=pertemuan&id=' . (int) $row['pertemuan_id']), 'utama' => false],
                $status === 'Dibuka' => ['label' => 'Lanjutkan', 'url' => app_url('/admin/admin_pengajian.php?tab=pertemuan&id=' . (int) $row['pertemuan_id']), 'utama' => true],
                $mulai === '' || $mulai <= date('H:i:s', strtotime('+30 minutes')) => ['label' => 'Buka pertemuan', 'url' => app_url('/admin/admin_pengajian.php?tab=pertemuan&schedule_id=' . (int) $row['id']), 'utama' => true],
                default => ['label' => 'Belum waktunya', 'url' => null, 'utama' => false],
            };
            $row['status_label'] = $status !== '' ? $status : ($mulai !== '' && $mulai < $sekarang ? 'Belum dibuka' : 'Belum waktunya');
        }
        unset($row);

        return $rows;
    }

    // ------------------------------------------------------------------
    // B-2 Guru & murobi
    // ------------------------------------------------------------------

    /**
     * Kemajuan nilai per mapel–kelas semester aktif dari penugasan guru mapel
     * MILIK AKUN SENDIRI (B-2). Hak pengawasan admin pada akun yang sama tidak
     * ikut dihitung; pengawasan tetap tersedia di peran Admin.
     *
     * @param array<string, mixed> $user
     * @return array<int, array{label:string, terisi:int, total:int, url:string}>
     */
    public function kemajuanNilai(array $user): array
    {
        $tahun = $this->tahunAktifId();
        $hasil = [];
        foreach ((new NilaiService($this->db, $this->caps, audit_logger()))->options($user) as $row) {
            if ((int) $row['tahun_ajaran_id'] !== $tahun || !$this->caps->featureOwnAppliesTo($user, PenugasanJenis::CAP_NILAI_INPUT, [
                'tahun_ajaran_id' => (int) $row['tahun_ajaran_id'],
                'kelas_id' => (int) $row['kelas_id'],
                'mata_pelajaran_id' => (int) $row['mata_pelajaran_id'],
            ])) {
                continue;
            }
            $total = (int) $this->one(
                "SELECT COUNT(DISTINCT pk.id_santri) AS n FROM plotting_kelas pk JOIN santri s ON s.id = pk.id_santri
                  WHERE pk.id_kelas = ? AND pk.id_tahun = ? AND s.is_active = 1 AND s.archived_at IS NULL",
                'ii',
                [(int) $row['kelas_id'], $tahun]
            )['n'];
            $terisi = (int) $this->one(
                "SELECT COUNT(*) AS n FROM (
                    SELECT santri_id FROM v4_nilai WHERE rencana_mapel_id = ? AND nilai IS NOT NULL
                     GROUP BY santri_id
                    HAVING SUM(komponen = 'Harian') > 0 AND SUM(komponen = 'Tengah') > 0 AND SUM(komponen = 'Akhir') > 0) x",
                'i',
                [(int) $row['id']]
            )['n'];
            $hasil[] = [
                'label' => $row['mata_pelajaran'] . ' · ' . $row['nama_kelas'],
                'terisi' => min($terisi, max($total, $terisi)),
                'total' => max($total, $terisi),
                'url' => app_url('/portal/nilai_semester.php?rencana_mapel_id=' . (int) $row['id']),
            ];
        }

        return $hasil;
    }

    /**
     * Sikap binaan yang sudah diserahkan pembimbing tetapi belum diketahui
     * murobi. Dihitung dari daftar yang SAMA dengan tujuan kartunya
     * (`sikap_semester.php?mode=murobi&status=perlu_diketahui`): pilihan
     * `SikapService` pada cakupan penugasan murobi sendiri, lalu disaring.
     *
     * @param array<string, mixed> $user
     */
    public function sikapPerluDiketahui(array $user): int
    {
        $service = new SikapService($this->db, audit_logger());

        return count($service->filterOptions($service->options($user, 'murobi'), 'perlu_diketahui', (int) $user['id']));
    }

    // ------------------------------------------------------------------
    // B-3 Pengurus / pembimbing
    // ------------------------------------------------------------------

    /**
     * Angka ringkas pengurus pada cakupan binaannya. Setiap angka memakai
     * layanan/filter yang sama dengan halaman tujuannya.
     *
     * @param array<string, mixed> $user
     * @return array<int, array{label:string, nilai:string, url?:string, tertunda?:bool}>
     */
    public function statistikPengurus(array $user, bool $sikap, bool $pembinaan): array
    {
        $stat = [];
        $hariIni = date('Y-m-d');
        $this->stat($stat, 'Izin aktif', fn (): string => $this->angka((int) izin_service()->list(
            $user,
            ['status' => 'Disetujui', 'date_from' => $hariIni, 'date_to' => $hariIni],
            1,
            1,
            Capabilities::PENGURUS
        )['total']), '/portal/izin.php?status=Disetujui&date_from=' . $hariIni . '&date_to=' . $hariIni);
        $this->stat($stat, 'Menunggu keputusan', fn (): string => $this->angka(izin_service()->queueCount($user, Capabilities::PENGURUS)), '/portal/izin_antrean.php');

        if ($sikap) {
            $this->stat($stat, 'Sikap belum diisi', function () use ($user): string {
                $service = new SikapService($this->db, audit_logger());
                return $this->angka(count($service->filterOptions($service->options($user, 'pembimbing'), 'belum')));
            }, '/portal/sikap_semester.php?status=belum', true);
        }
        if ($pembinaan) {
            $laporan = new LaporanService(new PelanggaranRepository($this->db), $this->caps);
            $bulanIni = date('Y-m-01');
            $this->stat($stat, 'Pelanggaran bulan ini', fn (): string => $this->angka((int) $laporan->read(
                $user,
                ['jenis' => 'pelanggaran', 'mulai' => $bulanIni, 'aktif' => '1']
            )['total']), '/portal/v3_laporan.php?jenis=pelanggaran&mulai=' . $bulanIni . '&aktif=1');
            // Keputusan AA: santri binaan yang total poinnya masuk ambang aktif;
            // laporan "ambang" berisi himpunan santri yang sama.
            $this->stat($stat, 'Santri mencapai ambang', fn (): string => $this->angka((int) $laporan->read(
                $user,
                ['jenis' => 'ambang']
            )['total']), '/portal/v3_laporan.php?jenis=ambang', true);
        }

        return $stat;
    }

    // ------------------------------------------------------------------
    // B-4 Bagian Pendidikan
    // ------------------------------------------------------------------

    /**
     * Kemajuan semester aktif per kelas dalam cakupan penugasan Bagian
     * Pendidikan MILIK AKUN SENDIRI.
     *
     * @param array<string, mixed> $user
     * @return array<int, array<string, mixed>>
     */
    public function kemajuanKelas(array $user): array
    {
        $tahun = $this->tahunAktifId();
        $hasil = [];
        foreach ((new VerifikasiService($this->db, audit_logger()))->options($user) as $plan) {
            if ((int) $plan['tahun_ajaran_id'] !== $tahun || !$this->caps->featureOwnAppliesTo($user, PenugasanJenis::CAP_RAPOR_VERIFIKASI, [
                'tahun_ajaran_id' => (int) $plan['tahun_ajaran_id'],
                'kelas_id' => (int) $plan['kelas_id'],
            ])) {
                continue;
            }
            $kelas = (int) $plan['kelas_id'];
            $rencana = (int) $plan['id'];
            $bahan = $this->ringkasBahan($rencana, $tahun, $kelas);
            $santri = $bahan['santri'];
            $mapel = $bahan['mapel'];
            $nilai = (int) $this->one("SELECT COUNT(*) AS n FROM (
                    SELECT n.rencana_mapel_id, n.santri_id FROM v4_nilai n
                      JOIN v4_rencana_mapel rm ON rm.id = n.rencana_mapel_id AND rm.rencana_id = ? AND rm.wajib = 1
                     WHERE n.nilai IS NOT NULL GROUP BY n.rencana_mapel_id, n.santri_id
                    HAVING SUM(n.komponen = 'Harian') > 0 AND SUM(n.komponen = 'Tengah') > 0 AND SUM(n.komponen = 'Akhir') > 0) x", 'i', [$rencana])['n'];
            $sikap = (int) $this->one('SELECT COUNT(*) AS n FROM v4_sikap WHERE tahun_ajaran_id = ? AND kelas_id = ?
                    AND adab IS NOT NULL AND kedisiplinan IS NOT NULL AND tanggung_jawab IS NOT NULL', 'ii', [$tahun, $kelas])['n'];
            $hadirBelum = (int) $this->one("SELECT COUNT(*) AS n FROM pertemuan_peserta pp
                    JOIN pertemuan_pengajian p ON p.id = pp.pertemuan_id AND p.status = 'Selesai'
                    LEFT JOIN absensi_santri a ON a.pertemuan_id = pp.pertemuan_id AND a.santri_id = pp.santri_id
                   WHERE pp.kelas_id_snapshot = ? AND pp.tahun_ajaran_id_snapshot = ? AND a.id IS NULL", 'ii', [$kelas, $tahun])['n'];
            $terbit = (int) $this->one("SELECT COUNT(*) AS n FROM v4_rapor r JOIN plotting_kelas pk ON pk.id_santri = r.santri_id AND pk.id_tahun = r.tahun_ajaran_id AND pk.id_kelas = ?
                    WHERE r.tahun_ajaran_id = ? AND r.status = 'Terbit'", 'ii', [$kelas, $tahun])['n'];
            $harapan = $bahan['harapan'];
            $hasil[] = [
                'rencana_id' => $rencana,
                'kelas' => (string) $plan['nama_kelas'],
                'santri' => $santri,
                'nilai_persen' => $santri * $mapel > 0 ? (int) round(100 * $nilai / ($santri * $mapel)) : null,
                'sikap_persen' => $santri > 0 ? (int) round(100 * min($sikap, $santri) / $santri) : null,
                'kehadiran_belum' => $hadirBelum,
                'terverifikasi' => $bahan['terverifikasi'],
                'harapan' => $harapan,
                'terverifikasi_persen' => $harapan > 0 ? (int) round(100 * $bahan['terverifikasi'] / $harapan) : null,
                'perbaikan' => $bahan['perbaikan'],
                'terbit' => $terbit,
                'url' => app_url('/portal/verifikasi_semester.php?rencana_id=' . $rencana),
                'url_perbaikan' => app_url('/portal/verifikasi_semester.php?rencana_id=' . $rencana . '&status=Dikembalikan'),
            ];
        }

        return $hasil;
    }

    /**
     * Status EFEKTIF bahan rapor satu rencana kelas, dengan himpunan bahan dan
     * aturan yang sama dengan matriks `VerifikasiService::read()`:
     *
     *   - santri: penempatan kelas pada semester rencana, santri aktif;
     *   - bahan: setiap mapel wajib, sikap, dan kehadiran per santri;
     *   - status: baris `v4_bahan` berlaku hanya bila sidik sumbernya masih
     *     sama dengan sumber terkini (`BahanRepository::state()`); sumber yang
     *     berubah sesudah diverifikasi kembali menjadi Draf (perlu verifikasi
     *     ulang). Tanpa baris pemeriksaan, status awal sama dengan matriks.
     *
     * Sumber dibaca per rencana dengan prepared statement (tipe nilai sama
     * dengan pembacaan matriks), bukan per santri, agar Beranda tetap ringan.
     *
     * @return array{santri:int, mapel:int, harapan:int, terverifikasi:int, perbaikan:int, perbaikan_lama:bool, perbaikan_santri:array<int, int>}
     */
    public function ringkasBahan(int $rencana, int $tahun, int $kelas): array
    {
        $santri = array_map('intval', array_column($this->all(
            'SELECT DISTINCT s.id FROM santri s JOIN plotting_kelas pk ON pk.id_santri = s.id
              WHERE pk.id_kelas = ? AND pk.id_tahun = ? AND s.is_active = 1 AND s.archived_at IS NULL',
            'ii',
            [$kelas, $tahun]
        ), 'id'));
        $mapel = array_map('intval', array_column($this->all(
            'SELECT rm.id FROM v4_rencana_mapel rm JOIN mata_pelajaran mp ON mp.id = rm.mata_pelajaran_id
              WHERE rm.rencana_id = ? AND rm.wajib = 1 ORDER BY rm.id',
            'i',
            [$rencana]
        ), 'id'));
        $hasil = ['santri' => count($santri), 'mapel' => count($mapel), 'harapan' => count($santri) * (count($mapel) + 2),
            'terverifikasi' => 0, 'perbaikan' => 0, 'perbaikan_lama' => false, 'perbaikan_santri' => []];
        if ($santri === []) {
            return $hasil;
        }
        $inSantri = implode(',', array_fill(0, count($santri), '?'));

        $nilai = [];
        if ($mapel !== []) {
            $inMapel = implode(',', array_fill(0, count($mapel), '?'));
            foreach ($this->all(
                "SELECT * FROM v4_nilai WHERE rencana_mapel_id IN ($inMapel) AND santri_id IN ($inSantri)
                  ORDER BY rencana_mapel_id, santri_id, komponen, nomor_harian",
                str_repeat('i', count($mapel) + count($santri)),
                array_merge($mapel, $santri)
            ) as $row) {
                $nilai[(int) $row['rencana_mapel_id'] . '|' . (int) $row['santri_id']][] = $row;
            }
        }
        $sikap = [];
        foreach ($this->all("SELECT * FROM v4_sikap WHERE tahun_ajaran_id = ? AND santri_id IN ($inSantri)", 'i' . str_repeat('i', count($santri)), array_merge([$tahun], $santri)) as $row) {
            $sikap[(int) $row['santri_id']][] = $row;
        }
        $hadir = [];
        foreach ($this->all(
            "SELECT pp.santri_id AS _santri, pp.pertemuan_id, p.tanggal_pertemuan, a.status
               FROM pertemuan_peserta pp
               JOIN pertemuan_pengajian p ON p.id = pp.pertemuan_id AND p.status = 'Selesai'
               JOIN jadwal_ngaji j ON j.id = p.jadwal_id AND j.id_tahun = pp.tahun_ajaran_id_snapshot
               LEFT JOIN absensi_santri a ON a.pertemuan_id = pp.pertemuan_id AND a.santri_id = pp.santri_id
              WHERE pp.santri_id IN ($inSantri) AND pp.tahun_ajaran_id_snapshot = ?
              ORDER BY pp.santri_id, pp.pertemuan_id",
            str_repeat('i', count($santri)) . 'i',
            array_merge($santri, [$tahun])
        ) as $row) {
            $sid = (int) $row['_santri'];
            unset($row['_santri']);
            $hadir[$sid][] = $row;
        }
        $periksa = [];
        foreach ($this->all('SELECT * FROM v4_bahan WHERE rencana_id = ?', 'i', [$rencana]) as $row) {
            $periksa[(int) $row['santri_id'] . '|' . $row['jenis'] . '|' . (int) $row['mapel_key']] = $row;
        }
        $batasLama = date('Y-m-d H:i:s', time() - self::BATAS_TERTUNDA_JAM * 3600);
        $nilaiBahan = function (int $sid, string $jenis, int $key, array $sumber, string $awal) use ($periksa, &$hasil, $batasLama): void {
            $row = $periksa[$sid . '|' . $jenis . '|' . $key] ?? null;
            $status = $row === null ? $awal : ($row['sumber_hash'] === BahanRepository::fingerprint($sumber) ? (string) $row['status'] : 'Draf');
            if ($status === 'Terverifikasi') {
                $hasil['terverifikasi']++;
            } elseif ($status === 'Dikembalikan') {
                $hasil['perbaikan']++;
                $hasil['perbaikan_santri'][$sid] = ($hasil['perbaikan_santri'][$sid] ?? 0) + 1;
                if ($row !== null && (string) $row['updated_at'] < $batasLama) {
                    $hasil['perbaikan_lama'] = true;
                }
            }
        };
        foreach ($santri as $sid) {
            foreach ($mapel as $key) {
                $sumber = $nilai[$key . '|' . $sid] ?? [];
                $diserahkan = $sumber !== [] && count(array_filter($sumber, static fn (array $r): bool => $r['status'] === 'Dikirim')) === count($sumber);
                $nilaiBahan($sid, 'nilai', $key, $sumber, $diserahkan ? 'Dikirim' : 'Draf');
            }
            $sumberSikap = $sikap[$sid] ?? [];
            $nilaiBahan($sid, 'sikap', 0, $sumberSikap, (string) ($sumberSikap[0]['status'] ?? 'Draf'));
            $nilaiBahan($sid, 'kehadiran', 0, $hadir[$sid] ?? [], 'Draf');
        }

        return $hasil;
    }

    /**
     * Rencana kelas semester aktif yang dapat dibaca matriks (kelas aktif).
     *
     * @return array<int, array{id:int, kelas_id:int}>
     */
    public function rencanaAktif(int $tahun): array
    {
        return $this->all(
            'SELECT r.id, r.kelas_id FROM v4_rencana r JOIN kelas k ON k.id = r.kelas_id AND k.is_active = 1 AND k.archived_at IS NULL
              WHERE r.tahun_ajaran_id = ? ORDER BY r.id',
            'i',
            [$tahun]
        );
    }

    // ------------------------------------------------------------------
    // B-5 Orang tua
    // ------------------------------------------------------------------

    /**
     * Ringkasan satu anak untuk Beranda orang tua. Pemanggil WAJIB mengambil
     * `$santriId` dari daftar anak yang terhubung dengan akun ini.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function ringkasanAnak(array $user, int $santriId): array
    {
        $tahun = $this->tahunAktifId();
        $hasil = ['kelas' => null, 'kamar' => null, 'izin' => null, 'rapor' => null, 'info_belum_dibaca' => null];
        try {
            $row = $this->one("SELECT
                    (SELECT k.nama_kelas FROM plotting_kelas pk JOIN kelas k ON k.id = pk.id_kelas WHERE pk.id_santri = ? AND pk.id_tahun = ? AND pk.status = 'Aktif' LIMIT 1) AS kelas,
                    (SELECT km.nama_kamar FROM plotting_kamar pm JOIN kamar km ON km.id = pm.id_kamar WHERE pm.id_santri = ? AND pm.id_tahun = ? LIMIT 1) AS kamar",
                'iiii', [$santriId, $tahun, $santriId, $tahun]);
            $hasil['kelas'] = $row['kelas'];
            $hasil['kamar'] = $row['kamar'];
        } catch (Throwable $exception) {
            $this->log('penempatan anak', $exception);
        }
        try {
            $izin = izin_service()->list($user, ['santri_id' => $santriId], 1, 1, Capabilities::ORANG_TUA)['rows'][0] ?? null;
            $hasil['izin'] = $izin;
        } catch (Throwable $exception) {
            $this->log('izin anak', $exception);
        }
        try {
            $hasil['rapor'] = $this->one("SELECT r.id, r.status, r.current_version, t.tahun, t.semester FROM v4_rapor r
                    JOIN tahun_ajaran t ON t.id = r.tahun_ajaran_id
                   WHERE r.santri_id = ? AND r.current_version > 0 AND r.status = 'Terbit' ORDER BY r.tahun_ajaran_id DESC, r.id DESC LIMIT 1", 'i', [$santriId], true);
        } catch (Throwable $exception) {
            $this->log('rapor anak', $exception);
        }
        try {
            $hasil['info_belum_dibaca'] = (int) $this->one("SELECT COUNT(*) AS n FROM v3_publikasi p JOIN users u ON u.wali_id = p.wali_id AND u.is_active = 1
                   WHERE u.id = ? AND p.santri_id = ? AND p.archived_at IS NULL AND p.ditarik_pada IS NULL AND p.dibaca_pada IS NULL", 'ii', [(int) $user['id'], $santriId])['n'];
        } catch (Throwable $exception) {
            $this->log('informasi pembinaan anak', $exception);
        }

        return $hasil;
    }

    // ------------------------------------------------------------------
    // Bantuan
    // ------------------------------------------------------------------

    /** @return array{id:int, tahun:string, semester:string}|null */
    public function tahunAktif(): ?array
    {
        if ($this->tahunAktif === null) {
            try {
                $this->tahunAktif = $this->one("SELECT id, tahun, semester FROM tahun_ajaran WHERE status = 'Aktif' AND archived_at IS NULL ORDER BY id DESC LIMIT 1", '', [], true) ?? [];
            } catch (Throwable $exception) {
                $this->log('tahun ajaran aktif', $exception);
                $this->tahunAktif = [];
            }
        }

        return $this->tahunAktif === [] ? null : $this->tahunAktif;
    }

    private function tahunAktifId(): int
    {
        return (int) ($this->tahunAktif()['id'] ?? 0);
    }

    /** Nama hari pada kolom `jadwal_ngaji.hari` (enum basis data memakai "Minggu"). */
    private function hariIni(): string
    {
        return ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'][(int) date('w')];
    }

    /**
     * @param array<string, array<string, mixed>> $kartu
     * @param callable(): array{0:int, 1:bool} $hitung
     */
    private function kartu(array &$kartu, string $key, callable $hitung, string $judul, string $tombol, string $path): void
    {
        try {
            [$jumlah, $tertunda] = $hitung();
        } catch (Throwable $exception) {
            $this->log('kartu ' . $key, $exception);
            return;
        }
        if ($jumlah < 1) {
            return;
        }
        $kartu[$key] = [
            'key' => $key,
            'jumlah' => $jumlah,
            'judul' => $judul,
            'tombol' => $tombol,
            'url' => app_url($path),
            'tertunda' => $tertunda,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $stat
     * @param callable(): string $hitung
     */
    private function stat(array &$stat, string $label, callable $hitung, ?string $path = null, bool $sorot = false): void
    {
        try {
            $nilai = $hitung();
        } catch (Throwable $exception) {
            $this->log('angka ' . $label, $exception);
            return;
        }
        $stat[] = ['label' => $label, 'nilai' => $nilai, 'url' => $path === null ? null : app_url($path), 'sorot' => $sorot && $nilai !== '0'];
    }

    private function angka(int $nilai): string
    {
        return number_format($nilai, 0, ',', '.');
    }

    private function log(string $bagian, Throwable $exception): void
    {
        error_log('Beranda: ' . $bagian . ' tidak dapat dihitung: ' . $exception->getMessage());
    }

    /**
     * @param array<int, mixed> $params
     * @return array<string, mixed>|null
     */
    private function one(string $sql, string $types = '', array $params = [], bool $nullable = false): ?array
    {
        $rows = $this->all($sql, $types, $params);
        if ($rows === [] && !$nullable) {
            throw new RuntimeException('Baris ringkasan tidak ditemukan.');
        }

        return $rows[0] ?? null;
    }

    /**
     * @param array<int, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    private function all(string $sql, string $types = '', array $params = []): array
    {
        $statement = $this->db->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Query Beranda tidak dapat disiapkan: ' . $this->db->error);
        }
        try {
            if ($types !== '') {
                $statement->bind_param($types, ...$params);
            }
            if (!$statement->execute()) {
                throw new RuntimeException('Query Beranda gagal: ' . $statement->error);
            }
            $result = $statement->get_result();

            return $result === false ? [] : $result->fetch_all(MYSQLI_ASSOC);
        } finally {
            $statement->close();
        }
    }
}
