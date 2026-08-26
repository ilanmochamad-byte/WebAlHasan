<?php

declare(strict_types=1);

namespace App\Report;

use App\Api\ApiException;
use App\Auth\Capabilities;
use App\Izin\IzinException;
use App\Izin\IzinService;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Layanan laporan perizinan V2 — permukaan bersama web, API, dan aplikasi.
 *
 * Web (`portal/laporan*.php`), REST API (`/api/v1/izin/laporan*`), dan aplikasi
 * mobile SELURUHNYA masuk lewat kelas ini. Tidak ada permukaan yang membangun
 * query laporannya sendiri, sehingga:
 *
 *   - aturan otorisasi hanya ada satu tempat dan tidak diduplikasi di aplikasi;
 *   - ringkasan, detail, cetak, dan CSV memakai `IzinReportFilter` yang sama;
 *   - total pada keempat permukaan tidak dapat menyimpang.
 *
 * Cakupan dihitung ulang dari akun pada SETIAP pemanggilan lewat
 * `IzinService::scopeFor()`. Parameter `mode` hanya dapat memilih di antara
 * kemampuan yang MEMANG dimiliki akun; mengirim `mode=admin` dari akun orang
 * tua tidak memberi kemampuan apa pun.
 */
final class IzinReportService
{
    public function __construct(
        private IzinReportRepository $repository,
        private IzinService $izinService,
        private string $timezone
    ) {
    }

    /**
     * Filter bercakupan untuk satu pengguna. Titik masuk tunggal.
     *
     * @param array{id:int, roles:array<int,string>, guru_id:int|null} $user
     * @param array<string, mixed> $input
     */
    public function filterFor(array $user, array $input, ?string $preferred = null): IzinReportFilter
    {
        // `IzinService` berbicara dalam `IzinException` (bahasa domain), tetapi
        // permukaan laporan dipakai langsung oleh router API yang hanya
        // menangani `ApiException`. Tanpa penerjemahan di sini, akun tanpa
        // kemampuan perizinan akan menerima `500 SERVER_ERROR` alih-alih `403`
        // — membocorkan galat internal dan menyembunyikan penolakan yang benar.
        try {
            $scope = $this->izinService->scopeFor($user, $preferred);
        } catch (IzinException $exception) {
            throw new ApiException(
                $exception->status() === 403 ? 'FORBIDDEN' : 'VALIDATION_FAILED',
                $exception->getMessage(),
                $exception->status()
            );
        }

        return IzinReportFilter::fromInput($input, $this->timezone)->forScope($scope);
    }

    /**
     * Laporan berhalaman: ringkasan + detail satu halaman.
     *
     * @param array{id:int, roles:array<int,string>, guru_id:int|null} $user
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function report(array $user, array $input, ?string $preferred = null): array
    {
        $filter = $this->filterFor($user, $input, $preferred);
        $ringkasan = $this->repository->summary($filter);
        $durasi = $this->presentDuration($this->repository->decisionDuration($filter));
        $rows = array_map([$this, 'present'], $this->repository->page($filter));

        return [
            'cakupan' => $filter->scope,
            'cakupan_label' => (string) $filter->scope['label'],
            'ringkasan' => $ringkasan,
            'durasi' => $durasi,
            'items' => $rows,
            'pagination' => [
                'current_page' => $filter->page,
                'per_page' => $filter->perPage,
                'total' => $ringkasan['total'],
                'total_pages' => $ringkasan['total'] === 0 ? 0 : (int) ceil($ringkasan['total'] / $filter->perPage),
            ],
            'filter' => $filter->toArray(),
            'filter_aktif' => $this->describeFilters($filter),
            'kriteria' => $filter->criteriaKey(),
            'query' => $filter->toQueryString(),
        ];
    }

    /**
     * Dokumen lengkap (SELURUH baris sesuai filter) untuk cetak, PDF, dan CSV.
     *
     * Sengaja memakai `allRows()`, bukan `page()`: PRD Fase 5 mensyaratkan
     * ekspor memuat seluruh hasil filter, bukan halaman yang sedang terlihat.
     * `ringkasan` di sini dihitung dari filter yang SAMA, sehingga totalnya
     * identik dengan total pada tampilan berhalaman.
     *
     * @param array{id:int, roles:array<int,string>, guru_id:int|null} $user
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function document(array $user, array $input, ?string $preferred = null): array
    {
        $filter = $this->filterFor($user, $input, $preferred);
        $ringkasan = $this->repository->summary($filter);
        $durasi = $this->presentDuration($this->repository->decisionDuration($filter));
        $mentah = $this->repository->allRows($filter);
        $rows = array_map([$this, 'present'], $mentah);
        $generatedAt = $this->now();

        return [
            'cakupan' => $filter->scope,
            'cakupan_label' => (string) $filter->scope['label'],
            'ringkasan' => $ringkasan,
            'durasi' => $durasi,
            'items' => $rows,
            'baris_mentah' => $mentah,
            'jumlah_baris' => count($rows),
            // Menandai bila hasil melebihi pagar memori. Dokumen TIDAK boleh
            // diam-diam memotong hasil tanpa memberi tahu pembacanya.
            'terpotong' => count($rows) >= IzinReportFilter::MAX_EXPORT_ROWS
                && $ringkasan['total'] > count($rows),
            'filter' => $filter->toArray(),
            'filter_aktif' => $this->describeFilters($filter),
            'kriteria' => $filter->criteriaKey(),
            'dibuat_oleh' => $this->actorName($user),
            'dibuat_pada' => $generatedAt,
            'identitas' => IzinPrintRenderer::IDENTITAS,
            'judul' => IzinPrintRenderer::JUDUL,
            'nama_berkas_csv' => IzinCsvExport::filename($filter, $generatedAt),
        ];
    }

    /**
     * HTML ramah cetak. Dipakai web dan `expo-print` pada aplikasi.
     *
     * @param array{id:int, roles:array<int,string>, guru_id:int|null} $user
     * @param array<string, mixed> $input
     */
    public function printHtml(array $user, array $input, ?string $preferred = null): array
    {
        $document = $this->document($user, $input, $preferred);

        return [
            'html' => IzinPrintRenderer::render($document),
            'judul' => $document['judul'],
            'jumlah_baris' => $document['jumlah_baris'],
            'kriteria' => $document['kriteria'],
            'ringkasan' => $document['ringkasan'],
            'dibuat_pada' => $document['dibuat_pada'],
        ];
    }

    /**
     * CSV seluruh hasil filter.
     *
     * @param array{id:int, roles:array<int,string>, guru_id:int|null} $user
     * @param array<string, mixed> $input
     * @return array{konten:string, nama_berkas:string, jumlah_baris:int, kriteria:string, terpotong:bool, ringkasan:array<string,mixed>}
     */
    public function csv(array $user, array $input, ?string $preferred = null): array
    {
        $document = $this->document($user, $input, $preferred);

        return [
            'konten' => IzinCsvExport::encode($document['baris_mentah']),
            'nama_berkas' => $document['nama_berkas_csv'],
            'jumlah_baris' => $document['jumlah_baris'],
            'kriteria' => $document['kriteria'],
            'terpotong' => $document['terpotong'],
            'ringkasan' => $document['ringkasan'],
        ];
    }

    /**
     * Pilihan filter dalam cakupan pengguna.
     *
     * @param array{id:int, roles:array<int,string>, guru_id:int|null} $user
     * @return array<string, mixed>
     */
    public function options(array $user, array $input = [], ?string $preferred = null): array
    {
        $filter = $this->filterFor($user, $input, $preferred);
        $options = $this->repository->filterOptions($filter);

        return [
            'cakupan' => $filter->scope,
            'santri' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'], 'nis' => (string) $row['nis'], 'nama' => (string) $row['nama_santri'],
            ], $options['santri']),
            'pengurus' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'], 'nama' => (string) $row['nama'],
            ], $options['pengurus']),
            'murobi' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'], 'nama' => (string) $row['nama_guru'],
            ], $options['murobi']),
            'tahun_ajaran' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'tahun' => (string) $row['tahun'],
                'semester' => (string) $row['semester'],
                'status' => (string) $row['status'],
            ], $options['tahun_ajaran']),
            'kamar' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'], 'nama' => (string) $row['nama_kamar'],
            ], $options['kamar']),
            'kelas' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'], 'nama' => (string) $row['nama_kelas'], 'jenjang' => (string) $row['jenjang'],
            ], $options['kelas']),
            'status' => \App\Izin\IzinRepository::STATUSES,
            'kanal' => IzinReportFilter::KANAL,
            'basis_tanggal' => IzinReportFilter::BASIS_TANGGAL,
        ];
    }

    /**
     * `EXPLAIN` query laporan. HANYA untuk admin — rencana eksekusi memuat nama
     * tabel dan indeks yang tidak perlu diketahui peran lain.
     *
     * @param array{id:int, roles:array<int,string>, guru_id:int|null} $user
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function explain(array $user, array $input, ?string $preferred = null): array
    {
        $filter = $this->filterFor($user, $input, $preferred);
        if ($filter->scopeMode() !== Capabilities::ADMIN) {
            // `ApiException`, bukan `IzinException`: router API hanya menangani
            // yang pertama, dan penolakan ini WAJIB terlihat sebagai 403.
            throw new ApiException('FORBIDDEN', 'Rencana eksekusi query hanya dapat dibuka admin.', 403);
        }

        return [
            'filter' => $filter->toArray(),
            'kriteria' => $filter->criteriaKey(),
            'explain' => $this->repository->explain($filter),
        ];
    }

    // -----------------------------------------------------------------------
    // Penyajian
    // -----------------------------------------------------------------------

    /**
     * Label tampilan; TIDAK mengubah satu pun nilai bisnis yang tersimpan.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        $legacy = (int) ($row['is_legacy'] ?? 0) === 1;
        $row['is_legacy'] = $legacy;
        $row['sumber_label'] = $legacy ? 'Data warisan' : 'V2';
        $row['pengurus_label'] = $row['pengurus_nama'] ?? ($legacy ? 'Data warisan' : 'Belum ditetapkan');
        $row['murobi_label'] = $row['murobi_nama'] ?? ($legacy ? 'Data warisan' : 'Belum ditetapkan');
        $row['keputusan_label'] = $row['keputusan_hasil'] ?? ($legacy ? 'Data warisan' : 'Belum ada keputusan');

        $kamar = trim((string) ($row['kamar_nama'] ?? ''));
        $kelas = trim((string) ($row['kelas_nama'] ?? ''));
        $bagian = array_values(array_filter([
            $kamar === '' ? '' : 'Kamar ' . $kamar,
            $kelas === '' ? '' : 'Kelas ' . $kelas,
        ]));
        $row['kamar_kelas_label'] = $bagian === [] ? '-' : implode(' / ', $bagian);

        $detik = $row['durasi_keputusan_detik'] ?? null;
        $row['durasi_keputusan_detik'] = $detik === null ? null : (int) $detik;
        $row['durasi_label'] = self::durationLabel($row['durasi_keputusan_detik']);

        return $row;
    }

    /**
     * @param array{jumlah:int, median_detik:?int, rata_detik:?int, min_detik:?int, maks_detik:?int} $durasi
     * @return array<string, mixed>
     */
    private function presentDuration(array $durasi): array
    {
        return $durasi + [
            'median_label' => self::durationLabel($durasi['median_detik']),
            'rata_label' => self::durationLabel($durasi['rata_detik']),
            'min_label' => self::durationLabel($durasi['min_detik']),
            'maks_label' => self::durationLabel($durasi['maks_detik']),
            'median_jam' => $durasi['median_detik'] === null
                ? null
                : round($durasi['median_detik'] / 3600, 2),
        ];
    }

    /**
     * Label durasi manusiawi. `null` berarti tidak ada keputusan yang dapat
     * dihitung — bukan nol, dan tidak boleh ditampilkan sebagai "0 jam".
     */
    public static function durationLabel(?int $detik): string
    {
        if ($detik === null) {
            return 'Tidak tersedia';
        }
        if ($detik < 60) {
            return $detik . ' detik';
        }
        if ($detik < 3600) {
            return intdiv($detik, 60) . ' menit';
        }
        if ($detik < 86400) {
            $jam = intdiv($detik, 3600);
            $menit = intdiv($detik % 3600, 60);

            return $menit === 0 ? $jam . ' jam' : $jam . ' jam ' . $menit . ' menit';
        }
        $hari = intdiv($detik, 86400);
        $jam = intdiv($detik % 86400, 3600);

        return $jam === 0 ? $hari . ' hari' : $hari . ' hari ' . $jam . ' jam';
    }

    /**
     * Deskripsi filter aktif untuk header cetak/PDF dan panel web.
     *
     * @return array<string, string>
     */
    private function describeFilters(IzinReportFilter $filter): array
    {
        $deskripsi = [
            'Cakupan' => (string) $filter->scope['label'],
            $filter->basisLabel() => $filter->dateFrom . ' s.d. ' . $filter->dateTo,
            'Status' => $filter->status ?? 'Semua status',
            'Sumber data' => match ($filter->sumber) {
                'legacy' => 'Data warisan V1',
                'v2' => 'Pengajuan V2',
                default => 'Semua sumber',
            },
        ];

        $deskripsi['Santri'] = $filter->santriId === null ? 'Semua santri' : '#' . $filter->santriId;
        $deskripsi['Pengurus'] = $filter->pengurusId === null ? 'Semua pengurus' : '#' . $filter->pengurusId;
        $deskripsi['Murobi'] = $filter->murobiGuruId === null ? 'Semua murobi' : '#' . $filter->murobiGuruId;
        $deskripsi['Kamar'] = $filter->kamarId === null ? 'Semua kamar' : '#' . $filter->kamarId;
        $deskripsi['Kelas'] = $filter->kelasId === null ? 'Semua kelas' : '#' . $filter->kelasId;
        $deskripsi['Tahun ajaran'] = $filter->tahunAjaranId === null ? 'Semua tahun ajaran' : '#' . $filter->tahunAjaranId;
        $deskripsi['Durasi keputusan'] = $this->durationFilterLabel($filter);
        $deskripsi['Kanal notifikasi'] = $filter->kanal ?? 'Semua kanal';
        $deskripsi['Pencarian'] = $filter->q === '' ? 'Tanpa kata kunci' : $filter->q;

        // Label bernama diisi bila daftar pilihan tersedia dalam cakupan.
        return $this->withNamedLabels($filter, $deskripsi);
    }

    /**
     * @param array<string, string> $deskripsi
     * @return array<string, string>
     */
    private function withNamedLabels(IzinReportFilter $filter, array $deskripsi): array
    {
        $butuhNama = $filter->santriId !== null || $filter->pengurusId !== null
            || $filter->murobiGuruId !== null || $filter->kamarId !== null
            || $filter->kelasId !== null || $filter->tahunAjaranId !== null;
        if (!$butuhNama) {
            return $deskripsi;
        }

        $options = $this->repository->filterOptions($filter);
        $cari = static function (array $rows, ?int $id, string $idKey, array $nameKeys): ?string {
            if ($id === null) {
                return null;
            }
            foreach ($rows as $row) {
                if ((int) $row[$idKey] === $id) {
                    $bagian = [];
                    foreach ($nameKeys as $key) {
                        if (isset($row[$key]) && trim((string) $row[$key]) !== '') {
                            $bagian[] = (string) $row[$key];
                        }
                    }

                    return $bagian === [] ? null : implode(' - ', $bagian);
                }
            }

            return null;
        };

        foreach ([
            ['Santri', $options['santri'], $filter->santriId, 'id', ['nama_santri', 'nis']],
            ['Pengurus', $options['pengurus'], $filter->pengurusId, 'id', ['nama']],
            ['Murobi', $options['murobi'], $filter->murobiGuruId, 'id', ['nama_guru']],
            ['Kamar', $options['kamar'], $filter->kamarId, 'id', ['nama_kamar']],
            ['Kelas', $options['kelas'], $filter->kelasId, 'id', ['nama_kelas']],
            ['Tahun ajaran', $options['tahun_ajaran'], $filter->tahunAjaranId, 'id', ['tahun', 'semester']],
        ] as [$label, $rows, $id, $idKey, $nameKeys]) {
            $nama = $cari($rows, $id, $idKey, $nameKeys);
            if ($nama !== null) {
                $deskripsi[$label] = $nama . ' (#' . $id . ')';
            }
        }

        return $deskripsi;
    }

    private function durationFilterLabel(IzinReportFilter $filter): string
    {
        if ($filter->durasiMinJam === null && $filter->durasiMaksJam === null) {
            return 'Semua durasi';
        }
        if ($filter->durasiMinJam !== null && $filter->durasiMaksJam !== null) {
            return $filter->durasiMinJam . ' s.d. ' . $filter->durasiMaksJam . ' jam';
        }
        if ($filter->durasiMinJam !== null) {
            return 'Minimal ' . $filter->durasiMinJam . ' jam';
        }

        return 'Maksimal ' . $filter->durasiMaksJam . ' jam';
    }

    /**
     * @param array<string, mixed> $user
     */
    private function actorName(array $user): string
    {
        $nama = trim((string) ($user['name'] ?? ''));
        if ($nama !== '') {
            return $nama;
        }
        $username = trim((string) ($user['username'] ?? ''));

        return $username === '' ? 'Pengguna' : $username;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone($this->timezone)))->format('Y-m-d H:i:s T');
    }
}
