<?php

declare(strict_types=1);

namespace App\Ui;

use DateTimeImmutable;

/**
 * Pilihan cepat periode laporan (I-6, J-4, J-6).
 *
 * Halaman laporan menampilkan "Periode" sebagai pilihan Hari ini, Pekan ini,
 * Bulan ini, Semester ini, atau Rentang sendiri. Kelas ini hanya
 * menerjemahkan pilihan itu menjadi `date_from`/`date_to` sebelum masukan
 * diteruskan ke layanan laporan; validasi tanggal, cakupan, dan seluruh
 * kriteria tetap dikerjakan filter laporan yang sama seperti sebelumnya,
 * sehingga ringkasan, detail, cetak, dan CSV tidak menyimpang.
 *
 * Pekan dimulai hari Senin dan berakhir Ahad (keputusan T). Tahun ajaran
 * tidak menyimpan tanggal, sehingga "Semester ini" mengikuti semester aktif:
 * Ganjil 1 Juli–31 Desember tahun pertama, Genap 1 Januari–30 Juni tahun
 * kedua dari teks tahun ajaran "2026/2027".
 */
final class Periode
{
    public const PILIHAN = [
        'hari' => 'Hari ini',
        'pekan' => 'Pekan ini',
        'bulan' => 'Bulan ini',
        'semester' => 'Semester ini',
        'rentang' => 'Rentang sendiri',
    ];

    /**
     * Masukan GET dengan `date_from`/`date_to` sesuai periode.
     *
     * Tanpa `periode` dan tanpa tanggal, laporan memakai `$bawaan`. Tanpa
     * `periode` tetapi dengan tanggal (alamat lama, tautan Beranda), periode
     * dianggap "Rentang sendiri" sehingga tanggal yang dikirim tetap berlaku.
     *
     * @param array<string, mixed> $input
     * @param array{tahun:string, semester:string}|null $semesterAktif
     * @return array{0:array<string, mixed>, 1:string}
     */
    public static function terapkan(array $input, ?array $semesterAktif, string $bawaan = 'bulan', ?DateTimeImmutable $hariIni = null): array
    {
        $kode = isset($input['periode']) && is_string($input['periode']) ? $input['periode'] : '';
        if (!array_key_exists($kode, self::PILIHAN)) {
            $adaTanggal = (isset($input['date_from']) && $input['date_from'] !== '') || (isset($input['date_to']) && $input['date_to'] !== '');
            $kode = $adaTanggal ? 'rentang' : $bawaan;
        }
        unset($input['periode']);
        if ($kode !== 'rentang') {
            [$dari, $sampai] = self::rentang($kode, $semesterAktif, $hariIni);
            $input['date_from'] = $dari;
            $input['date_to'] = $sampai;
        }

        return [$input, $kode];
    }

    /**
     * @param array{tahun:string, semester:string}|null $semesterAktif
     * @return array{0:string, 1:string}
     */
    public static function rentang(string $kode, ?array $semesterAktif, ?DateTimeImmutable $hariIni = null): array
    {
        $hari = $hariIni ?? new DateTimeImmutable('today');

        return match ($kode) {
            'hari' => [$hari->format('Y-m-d'), $hari->format('Y-m-d')],
            'pekan' => [
                $hari->modify('-' . ((int) $hari->format('N') - 1) . ' days')->format('Y-m-d'),
                $hari->modify('+' . (7 - (int) $hari->format('N')) . ' days')->format('Y-m-d'),
            ],
            'semester' => self::semester($semesterAktif, $hari),
            default => [$hari->modify('first day of this month')->format('Y-m-d'), $hari->format('Y-m-d')],
        };
    }

    /**
     * @param array{tahun:string, semester:string}|null $semesterAktif
     * @return array{0:string, 1:string}
     */
    private static function semester(?array $semesterAktif, DateTimeImmutable $hari): array
    {
        if ($semesterAktif !== null && preg_match('/^(\d{4})\s*\/\s*(\d{4})$/', trim((string) $semesterAktif['tahun']), $m)) {
            return (string) $semesterAktif['semester'] === 'Genap'
                ? [$m[2] . '-01-01', $m[2] . '-06-30']
                : [$m[1] . '-07-01', $m[1] . '-12-31'];
        }
        // Tanpa tahun ajaran aktif yang terbaca: enam bulan terakhir.
        return [$hari->modify('-6 months')->format('Y-m-d'), $hari->format('Y-m-d')];
    }

    public static function label(string $kode): string
    {
        return self::PILIHAN[$kode] ?? self::PILIHAN['bulan'];
    }
}
