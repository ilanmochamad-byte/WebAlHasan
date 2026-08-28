<?php

declare(strict_types=1);

namespace App\Report;

use App\Api\ApiException;
use App\Auth\Capabilities;
use App\Izin\IzinRepository;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Definisi filter laporan perizinan V2 — SATU-SATUNYA sumber kebenaran.
 *
 * PRD V2 Fase 5 §5 mensyaratkan ringkasan, detail, cetak, dan CSV memakai
 * filter serta repository yang konsisten. Karena itu:
 *
 *  - Objek ini **immutable**. Setiap penyempitan menghasilkan instance baru,
 *    sehingga tidak ada jalur kode yang dapat mengubah filter di tengah jalan
 *    dan membuat total ringkasan berbeda dari total detail/CSV.
 *  - Objek ini adalah **satu-satunya** cara membangun kriteria laporan.
 *    `IzinReportRepository` tidak menerima array mentah dari request.
 *  - `page`/`per_page` sengaja DIPISAH dari kriteria (lihat `criteriaKey()`),
 *    karena pagination tidak boleh mengubah himpunan baris yang dihitung.
 *
 * Cakupan (`scope`) TIDAK pernah berasal dari request. Ia dihitung server dari
 * akun yang sedang masuk (`IzinService::scopeFor()`), lalu dipasang di sini
 * lewat `forScope()`. Parameter request hanya boleh MEMPERSEMPIT hasil; setiap
 * usaha memperluas cakupan dijawab `403`.
 */
final class IzinReportFilter
{
    public const BASIS_TANGGAL = ['izin', 'pengajuan', 'keputusan'];
    public const KANAL = ['InApp', 'Push', 'WhatsApp'];
    public const SUMBER = ['legacy', 'v2'];
    public const MAX_PER_PAGE = 100;

    /** Batas atas ekspor CSV/cetak; melindungi memori pada data besar. */
    public const MAX_EXPORT_ROWS = 20000;

    /**
     * @param array{mode:string, pengurus_id:?int, guru_id:?int, wali_id:?int, label:string} $scope
     */
    private function __construct(
        public readonly string $dateFrom,
        public readonly string $dateTo,
        public readonly string $basisTanggal,
        public readonly ?string $status,
        public readonly ?int $santriId,
        public readonly ?int $pengurusId,
        public readonly ?int $murobiGuruId,
        public readonly ?int $kamarId,
        public readonly ?int $kelasId,
        public readonly ?int $tahunAjaranId,
        public readonly ?int $durasiMinJam,
        public readonly ?int $durasiMaksJam,
        public readonly ?string $kanal,
        public readonly ?string $sumber,
        public readonly string $q,
        public readonly int $page,
        public readonly int $perPage,
        public readonly array $scope
    ) {
    }

    /**
     * Membangun filter dari input request (belum bercakupan).
     *
     * Seluruh nilai divalidasi ketat di sini. Nilai yang tidak dikenal ditolak
     * dengan `422`, bukan diabaikan diam-diam — filter yang diabaikan diam-diam
     * adalah cara paling mudah membuat total ringkasan dan detail berbeda.
     *
     * @param array<string, mixed> $input
     */
    public static function fromInput(array $input, string $timezone): self
    {
        $zone = new DateTimeZone($timezone);
        $today = new DateTimeImmutable('today', $zone);
        $defaultFrom = $today->modify('first day of this month')->format('Y-m-d');

        $from = self::date($input['date_from'] ?? $defaultFrom, 'date_from', $defaultFrom, $zone);
        $to = self::date($input['date_to'] ?? $today->format('Y-m-d'), 'date_to', $today->format('Y-m-d'), $zone);
        if ($to < $from) {
            throw new ApiException('VALIDATION_FAILED', 'Tanggal akhir tidak boleh sebelum tanggal awal.', 422, [
                'date_to' => 'Tanggal akhir tidak valid.',
            ]);
        }

        $durasiMin = self::nonNegativeInt($input['durasi_min_jam'] ?? null, 'durasi_min_jam');
        $durasiMaks = self::nonNegativeInt($input['durasi_maks_jam'] ?? null, 'durasi_maks_jam');
        if ($durasiMin !== null && $durasiMaks !== null && $durasiMaks < $durasiMin) {
            throw new ApiException('VALIDATION_FAILED', 'Durasi maksimum tidak boleh lebih kecil dari durasi minimum.', 422, [
                'durasi_maks_jam' => 'Durasi maksimum tidak valid.',
            ]);
        }

        $kamarId = self::positiveId($input['kamar_id'] ?? null, 'kamar_id');
        $kelasId = self::positiveId($input['kelas_id'] ?? null, 'kelas_id');
        if ($kamarId !== null && $kelasId !== null) {
            throw new ApiException('VALIDATION_FAILED', 'Pilih kamar atau kelas, tidak keduanya sekaligus.', 422, [
                'kelas_id' => 'Filter kamar dan kelas tidak dapat dipakai bersamaan.',
            ]);
        }

        return new self(
            $from,
            $to,
            self::pilihan($input['basis_tanggal'] ?? null, self::BASIS_TANGGAL, 'basis_tanggal') ?? 'izin',
            self::pilihan($input['status'] ?? null, IzinRepository::STATUSES, 'status'),
            self::positiveId($input['santri_id'] ?? null, 'santri_id'),
            self::positiveId($input['pengurus_id'] ?? null, 'pengurus_id'),
            self::positiveId($input['murobi_guru_id'] ?? null, 'murobi_guru_id'),
            $kamarId,
            $kelasId,
            self::positiveId($input['tahun_ajaran_id'] ?? null, 'tahun_ajaran_id'),
            $durasiMin,
            $durasiMaks,
            self::pilihan($input['kanal'] ?? null, self::KANAL, 'kanal'),
            self::pilihan($input['sumber'] ?? null, self::SUMBER, 'sumber'),
            mb_substr(trim((string) ($input['q'] ?? '')), 0, 100),
            max(1, (int) ($input['page'] ?? 1)),
            max(1, min(self::MAX_PER_PAGE, (int) ($input['per_page'] ?? 25))),
            ['mode' => '', 'pengurus_id' => null, 'guru_id' => null, 'wali_id' => null, 'label' => 'Tanpa cakupan']
        );
    }

    /**
     * Memasang cakupan server dan menolak parameter yang berusaha MEMPERLUAS-nya.
     *
     * Ini adalah lapisan kedua, bukan satu-satunya pengaman: predikat cakupan
     * sesungguhnya tetap ditambahkan `IzinReportRepository::conditions()` pada
     * setiap query. Bahkan bila pemeriksaan di bawah dilewati, SQL tetap tidak
     * dapat mengembalikan baris di luar cakupan.
     *
     * @param array{mode:string, pengurus_id:?int, guru_id:?int, wali_id:?int, label:string} $scope
     */
    public function forScope(array $scope): self
    {
        $mode = (string) ($scope['mode'] ?? '');
        $pengurusId = $this->pengurusId;
        $murobiGuruId = $this->murobiGuruId;

        if ($mode === Capabilities::PENGURUS) {
            $milik = (int) ($scope['pengurus_id'] ?? 0);
            if ($pengurusId !== null && $pengurusId !== $milik) {
                throw new ApiException('FORBIDDEN', 'Pengurus hanya dapat membuka laporan pengajuan miliknya.', 403);
            }
            $pengurusId = $milik;
        } elseif ($mode === Capabilities::MUROBI) {
            $milik = (int) ($scope['guru_id'] ?? 0);
            if ($murobiGuruId !== null && $murobiGuruId !== $milik) {
                throw new ApiException('FORBIDDEN', 'Murobi hanya dapat membuka laporan pengajuan yang diarahkan kepadanya.', 403);
            }
            $murobiGuruId = $milik;
        } elseif ($mode !== Capabilities::ADMIN && $mode !== Capabilities::ORANG_TUA) {
            throw new ApiException('FORBIDDEN', 'Akun ini tidak memiliki cakupan laporan perizinan.', 403);
        }

        return new self(
            $this->dateFrom,
            $this->dateTo,
            $this->basisTanggal,
            $this->status,
            $this->santriId,
            $pengurusId,
            $murobiGuruId,
            $this->kamarId,
            $this->kelasId,
            $this->tahunAjaranId,
            $this->durasiMinJam,
            $this->durasiMaksJam,
            $this->kanal,
            $this->sumber,
            $this->q,
            $this->page,
            $this->perPage,
            $scope
        );
    }

    public function withPagination(int $page, int $perPage): self
    {
        return new self(
            $this->dateFrom,
            $this->dateTo,
            $this->basisTanggal,
            $this->status,
            $this->santriId,
            $this->pengurusId,
            $this->murobiGuruId,
            $this->kamarId,
            $this->kelasId,
            $this->tahunAjaranId,
            $this->durasiMinJam,
            $this->durasiMaksJam,
            $this->kanal,
            $this->sumber,
            $this->q,
            max(1, $page),
            max(1, min(self::MAX_PER_PAGE, $perPage)),
            $this->scope
        );
    }

    public function offset(): int
    {
        return max(0, ($this->page - 1) * $this->perPage);
    }

    public function scopeMode(): string
    {
        return (string) ($this->scope['mode'] ?? '');
    }

    /**
     * Kriteria filter tanpa pagination, sebagai array stabil.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'date_from' => $this->dateFrom,
            'date_to' => $this->dateTo,
            'basis_tanggal' => $this->basisTanggal,
            'status' => $this->status,
            'santri_id' => $this->santriId,
            'pengurus_id' => $this->pengurusId,
            'murobi_guru_id' => $this->murobiGuruId,
            'kamar_id' => $this->kamarId,
            'kelas_id' => $this->kelasId,
            'tahun_ajaran_id' => $this->tahunAjaranId,
            'durasi_min_jam' => $this->durasiMinJam,
            'durasi_maks_jam' => $this->durasiMaksJam,
            'kanal' => $this->kanal,
            'sumber' => $this->sumber,
            'q' => $this->q === '' ? null : $this->q,
        ];
    }

    /**
     * Sidik jari kriteria (TANPA page/per_page).
     *
     * Dipakai pengujian dan header ekspor untuk membuktikan bahwa ringkasan,
     * detail, cetak, dan CSV benar-benar berangkat dari kriteria yang sama.
     */
    public function criteriaKey(): string
    {
        return hash('sha256', (string) json_encode(
            $this->toArray() + ['scope_mode' => $this->scopeMode()],
            JSON_UNESCAPED_UNICODE
        ));
    }

    /**
     * Query string kriteria (tanpa pagination) untuk tautan cetak/CSV.
     */
    public function toQueryString(array $extra = []): string
    {
        $values = array_filter(
            $this->toArray(),
            static fn (mixed $value): bool => $value !== null && $value !== ''
        );

        return http_build_query($values + $extra);
    }

    public function basisLabel(): string
    {
        return match ($this->basisTanggal) {
            'pengajuan' => 'Tanggal pengajuan',
            'keputusan' => 'Tanggal keputusan',
            default => 'Rentang tanggal izin',
        };
    }

    // -----------------------------------------------------------------------
    // Validasi
    // -----------------------------------------------------------------------

    private static function date(mixed $value, string $field, string $fallback, DateTimeZone $zone): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return $fallback;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new ApiException('VALIDATION_FAILED', 'Tanggal harus berformat YYYY-MM-DD.', 422, [
                $field => 'Tanggal tidak valid.',
            ]);
        }

        return $date->format('Y-m-d');
    }

    /**
     * @param array<int, string> $allowed
     */
    private static function pilihan(mixed $value, array $allowed, string $field): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }
        if (!in_array($value, $allowed, true)) {
            throw new ApiException('VALIDATION_FAILED', 'Nilai filter tidak dikenal.', 422, [
                $field => 'Pilih salah satu dari: ' . implode(', ', $allowed) . '.',
            ]);
        }

        return $value;
    }

    private static function positiveId(mixed $value, string $field): ?int
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }
        if (preg_match('/^[1-9][0-9]{0,17}$/', $value) !== 1) {
            throw new ApiException('VALIDATION_FAILED', 'Parameter filter tidak valid.', 422, [
                $field => 'ID harus berupa bilangan bulat positif.',
            ]);
        }

        return (int) $value;
    }

    private static function nonNegativeInt(mixed $value, string $field): ?int
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }
        if (preg_match('/^[0-9]{1,6}$/', $value) !== 1) {
            throw new ApiException('VALIDATION_FAILED', 'Durasi harus berupa jumlah jam bulat.', 422, [
                $field => 'Isi dengan angka jam, misalnya 24.',
            ]);
        }

        return (int) $value;
    }
}
