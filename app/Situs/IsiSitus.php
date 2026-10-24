<?php

declare(strict_types=1);

namespace App\Situs;

/**
 * Pembaca isi resmi situs publik (`app/Situs/isi-situs.php`), redesain Tahap 8.
 *
 * Isi diisi manual oleh pesantren. Kelas ini hanya membaca dan menormalkan:
 * isian kosong dianggap tidak ada sehingga halaman tidak pernah menampilkan
 * teks contoh. Gambar hanya diterima bila berkasnya ada di dalam folder situs
 * dan berjenis gambar, agar tautan rusak atau alamat luar tidak tampil.
 */
final class IsiSitus
{
    /** @var array<string, mixed>|null */
    private static ?array $isi = null;

    /** @return array<string, mixed> */
    public static function semua(): array
    {
        if (self::$isi === null) {
            $isi = require __DIR__ . '/isi-situs.php';
            self::$isi = is_array($isi) ? $isi : [];
        }

        return self::$isi;
    }

    /** Teks tunggal; '' bila kosong atau bukan teks. */
    public static function teks(string $kunci): string
    {
        $nilai = self::ambil($kunci);

        return is_scalar($nilai) ? trim((string) $nilai) : '';
    }

    /** @return array<mixed> */
    public static function daftar(string $kunci): array
    {
        $nilai = self::ambil($kunci);

        return is_array($nilai) ? $nilai : [];
    }

    public static function ada(string $kunci): bool
    {
        $nilai = self::ambil($kunci);

        return is_array($nilai) ? $nilai !== [] : (is_scalar($nilai) && trim((string) $nilai) !== '');
    }

    /**
     * Alamat gambar yang aman ditampilkan, atau null. Hanya berkas lokal
     * (jalur relatif tanpa `..`) berekstensi gambar yang benar-benar ada.
     */
    public static function gambar(string $jalur): ?string
    {
        $jalur = trim($jalur);
        if ($jalur === '' || str_contains($jalur, '..') || preg_match('#^[a-z]+:|^//#i', $jalur) === 1
            || preg_match('/\.(?:png|jpe?g|webp|svg)$/i', $jalur) !== 1) {
            return null;
        }
        $jalur = ltrim($jalur, '/');

        return is_file(APP_ROOT . '/' . $jalur) ? app_url('/' . $jalur) : null;
    }

    /**
     * Paragraf dari teks dengan baris kosong sebagai pemisah, di-escape.
     */
    public static function paragraf(string $teks): string
    {
        $html = '';
        foreach (preg_split('/\R\s*\R/u', trim($teks)) ?: [] as $bagian) {
            $bagian = trim($bagian);
            if ($bagian !== '') {
                $html .= '<p>' . nl2br(htmlspecialchars($bagian, ENT_QUOTES, 'UTF-8'), false) . '</p>';
            }
        }

        return $html;
    }

    /** Nomor WhatsApp resmi (angka saja) atau ''. */
    public static function whatsapp(): string
    {
        $angka = preg_replace('/\D+/', '', self::teks('whatsapp')) ?? '';

        return strlen($angka) >= 9 ? $angka : '';
    }

    public static function psbDibuka(): bool
    {
        return (bool) (self::daftar('psb')['dibuka'] ?? false);
    }

    public static function tahunPsb(): string
    {
        $tahun = self::daftar('psb')['tahun_ajaran'] ?? '';

        return is_string($tahun) ? trim($tahun) : '';
    }

    /**
     * Unit pendidikan yang dinormalkan.
     *
     * @return array<string, array{nama:string, jenjang_psb:string, keterangan:string, situs:string}>
     */
    public static function unit(): array
    {
        $hasil = [];
        foreach (self::daftar('unit') as $kunci => $unit) {
            if (!is_array($unit) || !is_string($kunci)) {
                continue;
            }
            $situs = is_string($unit['situs'] ?? null) ? trim($unit['situs']) : '';
            $hasil[$kunci] = [
                'nama' => is_string($unit['nama'] ?? null) ? trim($unit['nama']) : '',
                'jenjang_psb' => is_string($unit['jenjang_psb'] ?? null) ? trim($unit['jenjang_psb']) : '',
                'keterangan' => is_string($unit['keterangan'] ?? null) ? trim($unit['keterangan']) : '',
                // Hanya alamat https yang ditautkan.
                'situs' => preg_match('#^https://[^\s"\'<>]+$#', $situs) === 1 ? $situs : '',
            ];
        }

        return $hasil;
    }

    /** Hanya untuk pengujian: memakai isi lain tanpa menyentuh berkas. */
    public static function pakai(?array $isi): void
    {
        self::$isi = $isi;
    }

    private static function ambil(string $kunci): mixed
    {
        $nilai = self::semua();
        foreach (explode('.', $kunci) as $bagian) {
            if (!is_array($nilai) || !array_key_exists($bagian, $nilai)) {
                return null;
            }
            $nilai = $nilai[$bagian];
        }

        return $nilai;
    }
}
