<?php

declare(strict_types=1);

namespace App\Izin;

use App\Audit\AuditLogger;
use App\Auth\Capabilities;
use App\Report\IzinCsvExport;

/**
 * Laporan perizinan lintas asal wali/pengurus (PRD V7 Fase 3 persyaratan 3).
 *
 * Satu pintu web dan API. Cakupan dihitung ulang dari basis data pada setiap
 * permintaan (role akun aktif, pengurus/guru/wali tertaut), tidak pernah dari
 * parameter; `peran` hanya memilih SALAH SATU peran yang benar-benar dimiliki.
 * Kartu, daftar, dan ekspor memakai filter dan cakupan yang sama
 * (`IzinLaporanRepository::conditions`), dan setiap baris menunjuk rincian
 * yang memeriksa hak dengan aturan yang sama (V7 untuk baris wali, V2 untuk
 * baris pengurus). Laporan tidak memuat alasan, catatan, atau alasan
 * keputusan. Bagi orang tua, baris wali yang bukan kirimannya hanya status.
 */
final class IzinLaporanService
{
    public const PERAN = [Capabilities::ADMIN, Capabilities::PENGURUS, Capabilities::MUROBI, Capabilities::ORANG_TUA];
    private const PER_PAGE = 25;

    public function __construct(
        private IzinLaporanRepository $repo,
        private IzinWaliRepository $wali,
        private Capabilities $capabilities,
        private AuditLogger $audit
    ) {
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function laporan(array $user, array $query): array
    {
        $cakupan = $this->cakupan($user, $query['peran'] ?? null);
        $filter = $this->filter($query);
        $page = is_numeric($query['page'] ?? null) ? max(1, min(10000, (int) $query['page'])) : 1;
        $hasil = $this->repo->halaman($filter, $cakupan, $page, self::PER_PAGE);

        return [
            'peran' => $cakupan['mode'],
            'peran_tersedia' => $cakupan['tersedia'],
            'cakupan_label' => $this->labelCakupan($cakupan['mode']),
            'filter' => $filter,
            'ringkasan' => $this->ringkasan($this->repo->ringkasan($filter, $cakupan)),
            'items' => array_map(fn (array $r): array => $this->proyeksi($r, $cakupan, (int) $user['id']), $hasil['rows']),
            'total' => $hasil['total'],
            'page' => $page,
            'per_page' => self::PER_PAGE,
        ];
    }

    /**
     * Ekspor CSV seluruh baris kriteria yang sama (paling banyak 5.000).
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $query
     * @return array{nama_berkas:string, csv:string, jumlah:int}
     */
    public function ekspor(array $user, array $query): array
    {
        $cakupan = $this->cakupan($user, $query['peran'] ?? null);
        $filter = $this->filter($query);
        $rows = $this->repo->semua($filter, $cakupan);
        if (count($rows) > IzinLaporanRepository::MAX_EKSPOR) {
            throw IzinException::invalid('Hasil melebihi ' . IzinLaporanRepository::MAX_EKSPOR . ' baris. Persempit filter lalu ekspor lagi.');
        }
        $items = array_map(fn (array $r): array => $this->proyeksi($r, $cakupan, (int) $user['id']), $rows);
        $wali = $cakupan['mode'] === Capabilities::ORANG_TUA;
        $kepala = $wali
            ? ['ID', 'Asal', 'NIS', 'Nama santri', 'Tanggal izin', 'Tanggal kembali', 'Status', 'Tahap', 'Hasil', 'Pengaju', 'Diajukan']
            : ['ID', 'Asal', 'NIS', 'Nama santri', 'Kamar', 'Kelas', 'Tanggal izin', 'Tanggal kembali', 'Status', 'Tahap', 'Hasil',
                'Pengurus pengaju', 'Pembimbing', 'Murobi', 'Diajukan', 'Diteruskan pembimbing', 'Selesai',
                'Lama tahap pembimbing (jam)', 'Lama tahap murobi (jam)', 'Lama total (jam)'];
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, $kepala, ',', '"', '');
        foreach ($items as $i) {
            $baris = $wali
                ? [$i['id'], $i['asal'], $i['santri']['nis'], $i['santri']['nama'], $i['tgl_izin'], $i['tgl_kembali'],
                    $i['status_label'], $i['tahap'], $i['hasil'], $i['pengaju'], $i['diajukan_pada']]
                : [$i['id'], $i['asal'], $i['santri']['nis'], $i['santri']['nama'], $i['kamar'], $i['kelas'], $i['tgl_izin'], $i['tgl_kembali'],
                    $i['status_label'], $i['tahap'], $i['hasil'], $i['pengurus'], $i['pembimbing'], $i['murobi'], $i['diajukan_pada'],
                    $i['diteruskan_pada'], $i['selesai_pada'], $i['lama_jam']['pembimbing'], $i['lama_jam']['murobi'], $i['lama_jam']['total']];
            fputcsv($stream, array_map([IzinCsvExport::class, 'spreadsheetSafe'], $baris), ',', '"', '');
        }
        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);
        // Audit tanpa isi: peran, filter, dan jumlah baris.
        $this->audit->log('izin_v7_laporan_ekspor', 'izin_pengajuan', null, null, [
            'peran' => $cakupan['mode'], 'filter' => array_filter($filter, static fn ($v): bool => $v !== null), 'jumlah' => count($items),
        ], (int) $user['id']);

        return [
            'nama_berkas' => 'laporan-izin-v7-' . $cakupan['mode'] . '-' . date('Ymd-His') . '.csv',
            'csv' => $csv,
            'jumlah' => count($items),
        ];
    }

    // =======================================================================

    /**
     * Cakupan efektif dari basis data. Tanpa peran perizinan apa pun → 403.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function cakupan(array $user, mixed $peran = null): array
    {
        if (!$this->wali->fase2Terpasang()) {
            throw new IzinException('Laporan pengajuan wali memerlukan migrasi 030 dan 031.', 503);
        }
        $userId = (int) ($user['id'] ?? 0);
        $this->capabilities->forget($userId);
        $probe = ['id' => $userId, 'roles' => $this->wali->rolesAkun($userId), 'guru_id' => $this->wali->guruAkun($userId)];
        $tersedia = array_values(array_filter(self::PERAN, fn (string $p): bool => $this->capabilities->has($probe, $p)));
        if ($tersedia !== [] && in_array(Capabilities::ORANG_TUA, $tersedia, true) && $this->wali->waliAkun($userId) === null) {
            $tersedia = array_values(array_diff($tersedia, [Capabilities::ORANG_TUA]));
        }
        if ($tersedia === []) {
            throw IzinException::forbidden('Akun ini tidak memiliki cakupan laporan perizinan.');
        }
        $mode = is_string($peran) && in_array($peran, $tersedia, true) ? $peran : $tersedia[0];

        return [
            'mode' => $mode,
            'tersedia' => $tersedia,
            'tanggal' => date('Y-m-d'),
            'pengurus_id' => $mode === Capabilities::PENGURUS ? (int) ($this->capabilities->linkedPengurusId($userId) ?? 0) : null,
            'guru_id' => $mode === Capabilities::MUROBI ? (int) ($probe['guru_id'] ?? 0) : null,
            'wali_id' => $mode === Capabilities::ORANG_TUA ? (int) ($this->wali->waliAkun($userId) ?? 0) : null,
        ];
    }

    /**
     * @param array<string, mixed> $q
     * @return array<string, mixed>
     */
    public function filter(array $q): array
    {
        $pilih = static function (mixed $v, array $boleh, string $label): ?string {
            if ($v === null || $v === '') {
                return null;
            }
            if (!is_string($v) || !in_array($v, $boleh, true)) {
                throw IzinException::invalid($label . ' tidak dikenal.');
            }

            return $v;
        };
        $id = static function (mixed $v, string $label): ?int {
            if ($v === null || $v === '') {
                return null;
            }
            if (!is_string($v) && !is_int($v) || !preg_match('/^\d{1,9}$/', (string) $v) || (int) $v < 1) {
                throw IzinException::invalid($label . ' tidak valid.');
            }

            return (int) $v;
        };
        $tanggal = static function (mixed $v, string $label): ?string {
            if ($v === null || $v === '') {
                return null;
            }
            if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4))) {
                throw IzinException::invalid($label . ' wajib berformat YYYY-MM-DD.');
            }

            return $v;
        };
        $dari = $tanggal($q['dari'] ?? null, 'Tanggal awal');
        $sampai = $tanggal($q['sampai'] ?? null, 'Tanggal akhir');
        if (($dari === null) !== ($sampai === null)) {
            throw IzinException::invalid('Isi tanggal awal dan akhir bersamaan.');
        }
        if ($dari !== null && $sampai < $dari) {
            throw IzinException::invalid('Tanggal akhir tidak boleh mendahului tanggal awal.');
        }
        $min = $id($q['durasi_min_jam'] ?? null, 'Lama minimum');
        $maks = $id($q['durasi_maks_jam'] ?? null, 'Lama maksimum');
        if ($min !== null && $maks !== null && $maks < $min) {
            throw IzinException::invalid('Lama maksimum tidak boleh lebih kecil dari lama minimum.');
        }

        return [
            'asal' => $pilih($q['asal'] ?? null, ['wali', 'pengurus'], 'Asal pengajuan'),
            'tahap' => $pilih($q['tahap'] ?? null, array_keys(IzinLaporanRepository::TAHAP), 'Tahap'),
            'hasil' => $pilih($q['hasil'] ?? null, array_keys(IzinLaporanRepository::HASIL), 'Hasil'),
            'basis' => $pilih($q['basis'] ?? null, ['izin', 'diajukan'], 'Basis tanggal') ?? 'izin',
            'dari' => $dari,
            'sampai' => $sampai,
            'santri_id' => $id($q['santri_id'] ?? null, 'Santri'),
            'pembimbing_id' => $id($q['pembimbing_id'] ?? null, 'Pembimbing'),
            'murobi_id' => $id($q['murobi_id'] ?? null, 'Murobi'),
            'kamar_id' => $id($q['kamar_id'] ?? null, 'Kamar'),
            'kelas_id' => $id($q['kelas_id'] ?? null, 'Kelas'),
            'durasi_min_jam' => $min,
            'durasi_maks_jam' => $maks,
        ];
    }

    public static function hasil(string $status): string
    {
        foreach (IzinLaporanRepository::HASIL as $kode => $status2) {
            if (in_array($status, $status2, true)) {
                return $kode;
            }
        }

        return 'tidak_dikenal';
    }

    // =======================================================================

    /**
     * @param array<string, mixed> $agregat
     * @return array<string, mixed>
     */
    private function ringkasan(array $agregat): array
    {
        $kartu = [
            'total' => 0,
            'per_asal' => ['Wali' => 0, 'Pengurus' => 0],
            'per_tahap' => array_fill_keys(array_keys(IzinLaporanRepository::TAHAP), 0),
            'per_hasil' => array_fill_keys(array_keys(IzinLaporanRepository::HASIL), 0),
        ];
        foreach ($agregat['per_asal_status'] as $r) {
            $n = (int) $r['jumlah'];
            $kartu['total'] += $n;
            $kartu['per_asal'][(string) $r['asal']] = ($kartu['per_asal'][(string) $r['asal']] ?? 0) + $n;
            $tahap = IzinWaliService::tahap((string) $r['status']);
            if (isset($kartu['per_tahap'][$tahap])) {
                $kartu['per_tahap'][$tahap] += $n;
            }
            $hasil = self::hasil((string) $r['status']);
            if (isset($kartu['per_hasil'][$hasil])) {
                $kartu['per_hasil'][$hasil] += $n;
            }
        }
        $jam = static fn (mixed $detik): ?float => $detik === null ? null : round(((float) $detik) / 3600, 1);
        $r = $agregat['rerata'];
        $kartu['rerata_lama_jam'] = [
            'pembimbing' => $jam($r['pembimbing'] ?? null), 'n_pembimbing' => (int) ($r['n_pembimbing'] ?? 0),
            'murobi' => $jam($r['murobi'] ?? null), 'n_murobi' => (int) ($r['n_murobi'] ?? 0),
            'total' => $jam($r['total'] ?? null), 'n_total' => (int) ($r['n_total'] ?? 0),
        ];

        return $kartu;
    }

    /**
     * @param array<string, mixed> $r
     * @param array<string, mixed> $cakupan
     * @return array<string, mixed>
     */
    private function proyeksi(array $r, array $cakupan, int $userId): array
    {
        $id = (int) $r['id'];
        $asalWali = (string) $r['asal_pengajuan'] === 'Wali';
        $status = (string) $r['status'];
        $dasar = [
            'id' => $id,
            'asal' => $asalWali ? 'Wali' : 'Pengurus',
            'santri' => ['id' => (int) $r['santri_id'], 'nis' => (string) $r['nis'], 'nama' => (string) $r['nama_santri']],
            'tgl_izin' => (string) $r['tgl_izin'],
            'tgl_kembali' => (string) $r['tgl_kembali'],
            'status' => $status,
            'status_label' => IzinWaliService::statusLabel($status),
            'tahap' => IzinWaliService::tahap($status),
            'hasil' => self::hasil($status),
            'diajukan_pada' => $r['diajukan_pada'] === null ? null : (string) $r['diajukan_pada'],
            'detail' => $asalWali
                ? ['web' => '/portal/izin_wali_detail.php?id=' . $id, 'api' => '/v7/izin/pengajuan/' . $id]
                : ['web' => '/portal/izin_detail.php?id=' . $id, 'api' => '/izin/pengajuan/' . $id],
        ];
        if ($cakupan['mode'] === Capabilities::ORANG_TUA) {
            $sendiri = $asalWali && (int) $r['diajukan_oleh_user_id'] === $userId && (int) $r['wali_id'] === (int) $cakupan['wali_id'];

            return $dasar + ['pengaju' => $asalWali ? ($sendiri ? 'Anda' : 'Wali lain') : 'Pengurus'];
        }
        $jam = static fn (mixed $detik): ?float => $detik === null ? null : round(((float) $detik) / 3600, 1);

        return $dasar + [
            'kamar' => $r['kamar_nama'] === null ? null : (string) $r['kamar_nama'],
            'kelas' => $r['kelas_nama'] === null ? null : (string) $r['kelas_nama'],
            'pengurus' => $r['pengurus_nama'] === null ? null : (string) $r['pengurus_nama'],
            'pembimbing' => $r['pembimbing_nama'] === null ? null : (string) $r['pembimbing_nama'],
            'murobi' => $r['murobi_nama'] === null ? null : (string) $r['murobi_nama'],
            'keputusan_oleh' => $r['keputusan_kapasitas'] === null ? null : (string) $r['keputusan_kapasitas'],
            'diteruskan_pada' => $r['diteruskan_pada'] === null ? null : (string) $r['diteruskan_pada'],
            'selesai_pada' => $r['selesai_pada'] === null ? null : (string) $r['selesai_pada'],
            'lama_jam' => [
                'pembimbing' => $jam($r['lama_pembimbing_detik']),
                'murobi' => $jam($r['lama_murobi_detik']),
                'total' => $jam($r['lama_total_detik']),
            ],
            'warisan' => (int) $r['is_legacy'] === 1,
        ];
    }

    private function labelCakupan(string $mode): string
    {
        return match ($mode) {
            Capabilities::ADMIN => 'Admin — seluruh pengajuan wali dan pengurus',
            Capabilities::PENGURUS => 'Pengurus — pengajuan Anda dan pengajuan wali yang ditujukan kepada Anda sebagai pembimbing',
            Capabilities::MUROBI => 'Murobi — pengajuan yang diarahkan kepada Anda',
            Capabilities::ORANG_TUA => 'Orang tua — anak yang terhubung dengan Anda',
            default => 'Tanpa cakupan',
        };
    }
}
