<?php

declare(strict_types=1);

namespace App\Keamanan;

use App\Api\ApiException;
use App\Audit\AuditLogger;
use App\Auth\Capabilities;
use App\Database\Schema;
use App\Izin\IzinException;
use App\Izin\IzinLaporanService;
use App\Izin\IzinService;
use App\Izin\IzinWaliService;
use App\Report\ReportFilter;
use App\Report\ReportService;
use App\Ui\Periode;
use App\V3\KonselingService;
use App\V3\PelanggaranService;
use App\V3\V3Exception;
use mysqli;

/**
 * REST baca-saja Keamanan untuk aplikasi (penugasan lintas guru/pengurus
 * Fase 4, PK-T3).
 *
 * Tidak ada lapisan data kedua: setiap endpoint memanggil layanan yang sama
 * dengan halaman web Keamanan Fase 3 (`KeamananService`, `IzinLaporanService`,
 * `PelanggaranService`, `KonselingService`, `ReportService`) dengan penanda
 * yang sama, dan layanan itu memeriksa ulang hak sendiri. Aturan:
 *
 *   - Hak dihitung ulang setiap permintaan dari penugasan Keamanan aktif MILIK
 *     SENDIRI (`KeamananAkses::sah`). Token lama gugur bersama penugasannya.
 *   - Hanya GET (router menolak metode lain dengan 405). Tidak ada CSV; cetak
 *     PDF tetap halaman cetak website (PK-T2).
 *   - Respons = isi yang ditampilkan halaman web Keamanan, bukan keluaran mentah
 *     layanan: rekomendasi, rekonsiliasi poin, peringatan konfigurasi, dan
 *     catatan murobi yang tidak ditampilkan web juga tidak dikirim.
 *   - Kasus Rahasia, ID yang tidak ada, dan santri nonaktif sama-sama 404.
 *   - Rincian izin/pelanggaran/kasus/pertemuan diaudit tanpa isi oleh layanan
 *     pemilik jejak yang sama dengan web; audit gagal → 503 tanpa isi.
 *   - Lampiran pelanggaran hanya metadata; unduhan tetap lewat website yang
 *     mencatat setiap unduhan.
 */
final class KeamananApiService
{
    private const STATUS_PELANGGARAN = ['Dicatat', 'Ditindaklanjuti', 'Selesai', 'Dibatalkan'];
    private const STATUS_KASUS = ['Dibuka', 'Dalam Pendampingan', 'Selesai', 'Dibatalkan'];

    public function __construct(
        private mysqli $db,
        private Capabilities $capabilities,
        private AuditLogger $audit,
        private KeamananService $keamanan,
        private IzinLaporanService $izinLaporan,
        private IzinService $izin,
        private PelanggaranService $pelanggaran,
        private KonselingService $konseling,
        private ReportService $report
    ) {
    }

    /**
     * Pintu masuk aplikasi. Akun tanpa penugasan menerima `pantau = false`
     * (bukan 403) agar Beranda dapat memutuskan ubin tanpa galat.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function akses(array $user): array
    {
        $terpasang = Schema::hasTable($this->db, 'keamanan_assignments');
        $pantau = $terpasang && KeamananAkses::sah($this->capabilities, $user);

        return [
            'pantau' => $pantau,
            'terpasang' => $terpasang,
            'bagian' => $pantau ? ['kehadiran', 'izin', 'pelanggaran', 'konseling'] : [],
            'cetak' => $pantau ? 'website' : null,
            'csv' => false,
        ];
    }

    // --- Perizinan ----------------------------------------------------------

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function izinDaftar(array $user, array $query): array
    {
        $this->pastikanAkses($user);
        try {
            $lap = $this->izinLaporan->laporan(KeamananAkses::tandai($user), $this->hanya($query,
                ['asal', 'tahap', 'hasil', 'basis', 'dari', 'sampai', 'page']));
        } catch (IzinException $exception) {
            throw new KeamananException($exception->getMessage(), $exception->status());
        }
        $lap['items'] = array_map(static function (array $item): array {
            $item['detail'] = ['web' => $item['detail']['web'], 'api' => '/keamanan/izin/' . (int) $item['id']];

            return $item;
        }, $lap['items']);
        unset($lap['peran_tersedia']);

        return $lap;
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function izinDetail(array $user, int $id): array
    {
        // Hak, cakupan santri aktif, 404, dan audit wajib = `KeamananService` web.
        $detail = $this->keamanan->izinDetail($user, $id);
        // Label status riwayat sama dengan yang ditampilkan halaman web.
        $detail['riwayat'] = array_map(static fn (array $r): array => $r + [
            'status_sebelum_label' => $r['status_sebelum'] === null ? null : IzinWaliService::statusLabel((string) $r['status_sebelum']),
            'status_sesudah_label' => IzinWaliService::statusLabel((string) $r['status_sesudah']),
        ], $detail['riwayat']);
        $detail['cetak_web'] = '/portal/keamanan_izin_detail.php?id=' . $id;

        return $detail;
    }

    // --- Pelanggaran --------------------------------------------------------

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function pelanggaranDaftar(array $user, array $query): array
    {
        $this->pastikanAkses($user);
        $status = $this->pilihan($query, 'status', self::STATUS_PELANGGARAN);
        [$mulai, $sampai] = $this->periode($query);
        $filters = array_filter([
            'status' => $status, 'q' => $this->cari($query), 'mulai' => $mulai, 'sampai' => $sampai,
            'page' => (string) $this->halaman($query),
        ], static fn ($v): bool => $v !== '' && $v !== null);
        $pantau = KeamananAkses::tandai($user);
        try {
            $list = $this->pelanggaran->page($pantau, $filters);
            $counts = $this->pelanggaran->statusCounts($pantau, $filters);
        } catch (V3Exception $exception) {
            throw new KeamananException($exception->getMessage(), $exception->status);
        }

        return [
            'filter' => ['status' => $status, 'q' => $filters['q'] ?? '', 'mulai' => $mulai, 'sampai' => $sampai],
            'jumlah_status' => $counts,
            'items' => array_map(static fn (array $r): array => [
                'id' => $r['id'], 'santri_id' => $r['santri_id'], 'santri_nama' => $r['santri_nama'],
                'tahun' => $r['tahun'], 'semester' => $r['semester'], 'waktu_kejadian' => $r['waktu_kejadian'],
                'jenis' => $r['jenis'], 'kategori' => $r['kategori'], 'tingkat' => $r['tingkat'], 'poin' => $r['poin'],
                'status' => $r['status'], 'detail' => ['api' => '/keamanan/pelanggaran/' . (int) $r['id']],
            ], $list['rows']),
            'total' => (int) $list['total'],
            'page' => (int) $list['page'],
            'per_page' => (int) $list['per_page'],
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function pelanggaranDetail(array $user, int $id): array
    {
        $this->pastikanAkses($user);
        try {
            $d = $this->pelanggaran->show(KeamananAkses::tandai($user), $id);
        } catch (V3Exception $exception) {
            throw $this->bukanCakupan($exception, 'Pelanggaran tidak ditemukan atau santrinya tidak aktif.');
        }
        $p = $d['pelanggaran'];
        $kasus = [];
        foreach ($d['konseling'] as $tautan) {
            $kasus[$tautan['kasus_id']] ??= ['kasus_id' => $tautan['kasus_id'], 'kasus_status' => $tautan['kasus_status'], 'sesi' => []];
            if ($tautan['sesi_id'] !== null) {
                $kasus[$tautan['kasus_id']]['sesi'][] = ['jadwal' => $tautan['jadwal'], 'status' => $tautan['sesi_status']];
            }
        }

        return [
            'pelanggaran' => [
                'id' => $p['id'], 'santri_id' => $p['santri_id'], 'santri_nama' => $p['santri_nama'], 'tahun' => $p['tahun'],
                'semester' => $p['semester'], 'waktu_kejadian' => $p['waktu_kejadian'], 'tempat' => $p['tempat'],
                'jenis' => $p['jenis'], 'kategori' => $p['kategori'], 'tingkat' => $p['tingkat'], 'poin' => $p['poin'],
                'status' => $p['status'], 'uraian' => $p['uraian'], 'saksi' => $p['saksi'], 'pencatat' => $p['pencatat'],
                'dicatat_pada' => $p['created_at'], 'alasan_revisi' => $p['alasan_revisi'], 'alasan_pembatalan' => $p['alasan_pembatalan'],
                'digantikan_oleh_id' => $p['digantikan_oleh_id'],
            ],
            'riwayat_revisi' => array_map(static fn (array $r): array => [
                'id' => $r['id'], 'revisi_dari_id' => $r['revisi_dari_id'], 'status' => $r['status'], 'created_at' => $r['created_at'],
                'alasan_revisi' => $r['alasan_revisi'], 'alasan_pembatalan' => $r['alasan_pembatalan'],
            ], $d['riwayat_revisi']),
            'murobi_mengetahui' => array_values(array_map(static fn (array $m): array => [
                'diketahui_pada' => $m['diketahui_pada'], 'catatan' => $m['catatan'],
            ], array_filter($d['murobi'], static fn (array $m): bool => $m['diketahui_pada'] !== null))),
            'total_poin' => $d['total_poin'],
            'ledger' => array_map(static fn (array $l): array => [
                'perubahan_poin' => $l['perubahan_poin'], 'alasan' => $l['pembalik_dari_id'] === null ? $l['alasan'] : 'Pengurangan karena koreksi/pembatalan',
                'created_at' => $l['created_at'],
            ], $d['ledger']),
            'lampiran' => array_map(static fn (array $f): array => [
                'id' => $f['id'], 'nama' => $f['nama'], 'mime' => $f['mime'], 'ukuran' => $f['ukuran'], 'created_at' => $f['created_at'],
            ], $d['lampiran']),
            'lampiran_unduh' => 'website',
            'konseling' => array_values(array_map(static fn (array $k): array => $k + ['detail' => ['api' => '/keamanan/konseling/' . (int) $k['kasus_id']]], $kasus)),
            'cetak_web' => '/portal/keamanan_pelanggaran_cetak.php?id=' . $id,
        ];
    }

    // --- Konseling ----------------------------------------------------------

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function konselingDaftar(array $user, array $query): array
    {
        $this->pastikanAkses($user);
        $status = $this->pilihan($query, 'status', self::STATUS_KASUS);
        $saring = array_filter(['q' => $this->cari($query)], static fn ($v): bool => $v !== '');
        $pantau = KeamananAkses::tandai($user);
        try {
            $counts = $this->konseling->statusCounts($pantau, $saring);
            $list = $this->konseling->page($pantau, $saring + array_filter(['status' => $status, 'page' => (string) $this->halaman($query)]));
        } catch (V3Exception $exception) {
            throw new KeamananException($exception->getMessage(), $exception->status);
        }

        return [
            'filter' => ['status' => $status, 'q' => $saring['q'] ?? ''],
            'jumlah_status' => $counts,
            'items' => array_map(static fn (array $r): array => [
                'id' => $r['id'], 'santri_id' => $r['santri_id'], 'santri_nama' => $r['santri_nama'], 'tahun' => $r['tahun'],
                'semester' => $r['semester'], 'kerahasiaan' => $r['kerahasiaan'], 'status' => $r['status'],
                'dibuka_pada' => $r['dibuka_pada'], 'jumlah_sesi' => $r['jumlah_sesi'],
                'detail' => ['api' => '/keamanan/konseling/' . (int) $r['id']],
            ], $list['rows']),
            'total' => (int) $list['total'],
            'page' => (int) $list['page'],
            'per_page' => (int) $list['per_page'],
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function konselingDetail(array $user, int $id): array
    {
        $this->pastikanAkses($user);
        $pantau = KeamananAkses::tandai($user);
        try {
            $d = $this->konseling->show($pantau, $id);
            $timeline = $this->konseling->timeline($pantau, $id, $d);
        } catch (V3Exception $exception) {
            throw $this->bukanCakupan($exception, 'Kasus tidak ditemukan atau tidak dapat dibaca Keamanan.');
        }
        $k = $d['kasus'];

        return [
            'kasus' => [
                'id' => $k['id'], 'santri_id' => $k['santri_id'], 'santri_nama' => $k['santri_nama'], 'tahun' => $k['tahun'],
                'semester' => $k['semester'], 'kerahasiaan' => $k['kerahasiaan'], 'status' => $k['status'],
                'tujuan' => $k['tujuan'] ?? null, 'dibuka_pada' => $k['dibuka_pada'], 'ditutup_pada' => $k['ditutup_pada'],
                'ringkasan_penutupan' => $k['ringkasan_penutupan'] ?? null, 'alasan_pembatalan' => $k['alasan_pembatalan'] ?? null,
            ],
            'sesi' => array_map(static fn (array $s): array => [
                'id' => $s['id'], 'jadwal' => $s['jadwal'], 'realisasi' => $s['realisasi'], 'status' => $s['status'],
                'ringkasan_internal' => $s['ringkasan_internal'] ?? null, 'hasil' => $s['hasil'] ?? null, 'tindak_lanjut' => $s['tindak_lanjut'] ?? null,
            ], $d['sesi_aktif']),
            'tautan' => array_map(static fn (array $t): array => [
                'pelanggaran_id' => $t['pelanggaran_id'], 'kategori' => $t['kategori'], 'waktu_kejadian' => $t['waktu_kejadian'],
                'poin' => $t['poin'], 'status' => $t['status'], 'detail' => ['api' => '/keamanan/pelanggaran/' . (int) $t['pelanggaran_id']],
            ], $d['tautan']),
            'linimasa' => array_map(static fn (array $e): array => ['jenis' => $e['jenis'], 'waktu' => $e['waktu']], $timeline['rows']),
            'riwayat_revisi' => array_map(static fn (array $r): array => [
                'versi_sebelum' => $r['versi_sebelum'], 'kapasitas' => $r['kapasitas'], 'alasan' => $r['alasan'], 'created_at' => $r['created_at'],
            ], $d['riwayat_revisi_kasus']),
            'cetak_web' => '/portal/keamanan_konseling_cetak.php?id=' . $id,
        ];
    }

    // --- Kehadiran pengajian ------------------------------------------------

    /**
     * Laporan kehadiran seluruh jadwal, peserta santri aktif kini (pola
     * keputusan H + koreksi audit Fase 3 F-1). Penanda dipasang di sini,
     * sesudah hak diperiksa; `/reports` aplikasi guru tidak pernah membawanya.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function kehadiran(array $user, array $query): array
    {
        $baca = $this->pembacaKehadiran($user);
        $filter = $this->hanya($query,
            ['date_from', 'date_to', 'academic_year_id', 'class_id', 'teacher_id', 'schedule_id', 'status', 'subject_scope', 'page', 'per_page']);
        // GET dapat membawa array (mis. date_from[]=...). ReportFilter lama
        // mengharapkan skalar; tolak di batas REST baru sebelum cast agar
        // respons tetap JSON 422 tanpa warning atau isi rincian implementasi.
        foreach ($filter as $nilai) {
            if (!is_scalar($nilai)) {
                throw new KeamananException('Saringan kehadiran tidak valid.', 422);
            }
        }
        try {
            $laporan = $this->report->report($filter, $baca, ReportFilter::SCOPE_SANTRI);
        } catch (ApiException $exception) {
            throw new KeamananException($exception->getMessage(), $exception->status());
        }
        unset($laporan['active_filters']);
        $laporan['items'] = array_map(static fn (array $r): array => $r + ['detail' => ['api' => '/keamanan/kehadiran/pertemuan/' . (int) $r['meeting_id']]], $laporan['items']);

        return $laporan;
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function kehadiranPertemuan(array $user, int $id): array
    {
        $baca = $this->pembacaKehadiran($user);
        try {
            $meeting = $this->report->meeting($id, $baca);
        } catch (ApiException $exception) {
            throw new KeamananException($exception->getMessage(), $exception->status());
        }
        // PK-5: setiap pembukaan rincian berjejak tanpa isi, sama dengan web (koreksi audit Fase 3 F-2).
        if (!$this->audit->log('keamanan.kehadiran.dilihat', 'pertemuan_pengajian', $id, null, ['pertemuan_id' => $id], (int) $user['id'])) {
            throw new KeamananException('Audit akses tidak dapat disimpan. Rincian tidak ditampilkan.', 503);
        }
        $izin = $this->izin->approvedCovering(array_map(static fn (array $s): int => (int) $s['student_id'], $meeting['students']), (string) $meeting['date']);
        $meeting['students'] = array_map(static fn (array $s): array => $s + [
            'izin_id' => isset($izin[(int) $s['student_id']]) ? (int) $izin[(int) $s['student_id']]['id'] : null,
        ], $meeting['students']);
        $meeting['cetak_web'] = '/admin/laporan_absensi_detail.php?id=' . $id;

        return $meeting;
    }

    // =======================================================================

    /** @param array<string, mixed> $user */
    private function pastikanAkses(array $user): void
    {
        $this->keamanan->pastikanAkses($user);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function pembacaKehadiran(array $user): array
    {
        $this->pastikanAkses($user);
        $user[ReportFilter::BACA_SEMUA_JADWAL] = true;
        $user[ReportFilter::SANTRI_AKTIF_SAJA] = true;

        return $user;
    }

    /** Di luar cakupan dan tidak ada diperlakukan sama, agar keberadaan kasus Rahasia tidak bocor. */
    private function bukanCakupan(V3Exception $exception, string $pesan): KeamananException
    {
        return $exception->status === 403 || $exception->status === 404
            ? new KeamananException($pesan, 404)
            : new KeamananException($exception->getMessage(), $exception->status);
    }

    /**
     * Hanya kunci saringan yang dikenal; parameter lain (mis. `peran`) tidak
     * pernah diteruskan ke layanan.
     *
     * @param array<string, mixed> $query
     * @param array<int, string> $kunci
     * @return array<string, mixed>
     */
    private function hanya(array $query, array $kunci): array
    {
        return array_intersect_key($query, array_flip($kunci));
    }

    /**
     * @param array<string, mixed> $query
     * @param array<int, string> $boleh
     */
    private function pilihan(array $query, string $kunci, array $boleh): string
    {
        $nilai = $query[$kunci] ?? '';
        if (!is_string($nilai) || ($nilai !== '' && !in_array($nilai, $boleh, true))) {
            throw new KeamananException('Saringan ' . $kunci . ' tidak valid.', 422);
        }

        return $nilai;
    }

    /** @param array<string, mixed> $query */
    private function cari(array $query): string
    {
        $q = $query['q'] ?? '';
        if (!is_string($q)) {
            throw new KeamananException('Pencarian tidak valid.', 422);
        }

        return mb_substr(trim($q), 0, 100);
    }

    /** @param array<string, mixed> $query */
    private function halaman(array $query): int
    {
        $page = $query['page'] ?? '1';
        if (!is_scalar($page) || !preg_match('/^[1-9][0-9]{0,6}$/D', (string) $page)) {
            throw new KeamananException('Halaman tidak valid.', 422);
        }

        return (int) $page;
    }

    /**
     * Periode kejadian pelanggaran, sama dengan saringan halaman web.
     *
     * @param array<string, mixed> $query
     * @return array{0:string, 1:string}
     */
    private function periode(array $query): array
    {
        $kode = $query['periode'] ?? 'semua';
        if (!is_string($kode) || ($kode !== 'semua' && !array_key_exists($kode, Periode::PILIHAN))) {
            throw new KeamananException('Periode tidak valid.', 422);
        }
        if ($kode === 'semua') {
            return ['', ''];
        }
        if ($kode === 'rentang') {
            $tanggal = static function (mixed $v): string {
                if ($v === null || $v === '') {
                    return '';
                }
                if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $v)) {
                    throw new KeamananException('Tanggal periode tidak valid.', 422);
                }

                return $v;
            };

            return [$tanggal($query['mulai'] ?? ''), $tanggal($query['sampai'] ?? '')];
        }
        $aktif = $this->db->query("SELECT tahun, semester FROM tahun_ajaran WHERE status = 'Aktif' AND archived_at IS NULL ORDER BY id DESC LIMIT 1")?->fetch_assoc();

        return Periode::rentang($kode, $aktif ?: null);
    }
}
