<?php

declare(strict_types=1);

namespace App\Kesehatan;

use DateTimeImmutable;

/**
 * Laporan layanan klinik — PRD V5 Fase 5. Dipakai halaman web
 * `portal/kesehatan_laporan.php` dan API `GET /api/v1/v5/kesehatan/laporan`
 * sehingga angka keduanya identik.
 *
 * Hak mengikuti `KesehatanService::akses()` yang dihitung ulang setiap
 * panggilan:
 *
 *   - petugas dan admin : seluruh santri, termasuk Draf;
 *   - pembimbing/murobi : hanya kunjungan Tercatat santri dalam cakupan
 *                         penugasan aktif — penyaring baris yang SAMA dengan
 *                         daftar/detail (`KesehatanRepository::saringBinaan`)
 *                         dipasang pada setiap angka dan daftar, sehingga
 *                         agregat tidak memuat santri di luar cakupan;
 *   - admin (pengawasan): tambahan bagian `pengawasan` — koreksi, publikasi
 *                         ringkasan, akses rincian, antrean notifikasi, dan
 *                         aktivitas per petugas;
 *   - lainnya, termasuk wali : 403 (wali memakai proyeksi ringkasan Fase 3).
 *
 * Laporan tidak memuat isi klinis apa pun (lihat `LaporanRepository`) dan
 * tidak membuka rincian, sehingga tidak dicatat di log akses; rincian tetap
 * hanya dibuka di halaman detail yang tercatat.
 */
final class LaporanService
{
    public const BATAS_HARI = 366;
    public const BATAS_TERTUNDA = 50;

    public function __construct(
        private KesehatanService $kesehatan,
        private KesehatanRepository $kesehatanRepo,
        private LaporanRepository $repo
    ) {
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input dari, sampai (Y-m-d; bawaan awal bulan berjalan s.d. hari ini), kelas_id (angka | `tanpa`)
     * @return array<string, mixed>
     */
    public function laporan(array $user, array $input): array
    {
        $akses = $this->kesehatan->akses($user);
        if (!$akses['baca']) {
            throw new KesehatanException('Laporan kesehatan hanya untuk petugas kesehatan berpenugasan aktif, admin, serta pembimbing/murobi santri terkait.', 403);
        }
        $binaan = $akses['cakupan'] === KesehatanService::CAKUPAN_BINAAN;
        $saring = $binaan
            ? $this->kesehatanRepo->saringBinaan(array_keys(array_filter($akses['binaan'])), (int) $user['id'])
            : null;
        [$dari, $sampai] = $this->periode($input['dari'] ?? null, $input['sampai'] ?? null);
        $kelas = $this->kelas($input['kelas_id'] ?? null);

        $basis = $this->repo->basis(['dari' => $dari, 'sampai' => $sampai, 'kelas' => $kelas], $saring);
        // Daftar tertunda tidak dibatasi periode: rujukan/tindak lanjut lama yang belum selesai tetap tampil.
        $basisTertunda = $this->repo->basis(['dari' => null, 'sampai' => null, 'kelas' => $kelas], $saring);
        $kunjungan = $this->repo->kunjungan($basis);
        if ($binaan) {
            // Draf tidak pernah terlihat pembaca binaan; angka disembunyikan, bukan nol palsu.
            $kunjungan['draf'] = null;
        }
        $ringkasanSiap = $this->repo->ringkasanSiap();

        $hasil = [
            'periode' => ['dari' => $dari, 'sampai' => $sampai, 'hari' => $this->jumlahHari($dari, $sampai)],
            'filter' => ['dari' => $dari, 'sampai' => $sampai, 'kelas_id' => $kelas === null ? '' : ($kelas === 0 ? 'tanpa' : (string) $kelas)],
            'akses' => $akses,
            'kunjungan' => $kunjungan,
            ...$this->repo->rincian($basis),
            'ringkasan_wali' => $ringkasanSiap ? $this->repo->ringkasan($basis) : null,
            'per_kelas' => $this->repo->perKelas($basis),
            'per_bulan' => $this->repo->perBulan($basis),
            // Pilihan kelas tidak bergantung periode agar dapat dipilih bersama periode baru.
            'kelas_pilihan' => $this->repo->kelasPilihan($this->repo->basis(['dari' => null, 'sampai' => null, 'kelas' => null], $saring)),
            'tertunda' => [
                'rujukan' => $this->repo->tertunda('rujukan', $basisTertunda, self::BATAS_TERTUNDA),
                'tindak_lanjut' => $this->repo->tertunda('tindak_lanjut', $basisTertunda, self::BATAS_TERTUNDA),
                'batas' => self::BATAS_TERTUNDA,
            ],
            'pengawasan' => null,
        ];
        if ($akses['riwayat_akses']) {
            $hasil['pengawasan'] = [
                'koreksi' => $this->repo->koreksi($dari, $sampai),
                'koreksi_terbaru' => $this->repo->koreksiTerbaru($dari, $sampai, 20),
                'per_petugas' => $this->repo->perPetugas($dari, $sampai),
                'akses' => $this->repo->akses($dari, $sampai),
                'peristiwa_ringkasan' => $ringkasanSiap ? $this->repo->peristiwaRingkasan($dari, $sampai) : [],
                'notifikasi' => $this->repo->notifikasi($dari, $sampai),
            ];
        }

        return $hasil;
    }

    /**
     * Periode laporan. Kosong → awal bulan berjalan s.d. hari ini; tanggal
     * tidak valid, terbalik, atau lebih dari 366 hari → 422.
     *
     * @return array{0:string, 1:string}
     */
    private function periode(mixed $dari, mixed $sampai): array
    {
        $hariIni = new DateTimeImmutable('today');
        $d = $this->tanggal($dari, 'Tanggal awal') ?? $hariIni->modify('first day of this month');
        $s = $this->tanggal($sampai, 'Tanggal akhir') ?? $hariIni;
        if ($d > $s) {
            throw new KesehatanException('Tanggal awal laporan tidak boleh sesudah tanggal akhir.');
        }
        if ($this->jumlahHari($d->format('Y-m-d'), $s->format('Y-m-d')) > self::BATAS_HARI) {
            throw new KesehatanException('Periode laporan paling panjang ' . self::BATAS_HARI . ' hari.');
        }

        return [$d->format('Y-m-d'), $s->format('Y-m-d')];
    }

    private function tanggal(mixed $value, string $label): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if (!$date || $date->format('Y-m-d') !== $value || $date->format('Y') < '2000') {
            throw new KesehatanException($label . ' laporan tidak valid.');
        }

        return $date;
    }

    private function jumlahHari(string $dari, string $sampai): int
    {
        return (int) (new DateTimeImmutable($dari))->diff(new DateTimeImmutable($sampai))->days + 1;
    }

    /** Kelas penyaring: angka = satu kelas, `tanpa` = belum ditempatkan (0), lainnya = semua (null). */
    private function kelas(mixed $value): ?int
    {
        if ($value === 'tanpa') {
            return 0;
        }

        return is_scalar($value) && preg_match('/^[1-9][0-9]{0,9}$/D', (string) $value) && (int) $value <= 2147483647 ? (int) $value : null;
    }
}
