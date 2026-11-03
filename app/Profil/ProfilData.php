<?php

declare(strict_types=1);

namespace App\Profil;

use App\MasterData\Normalizer;
use DateTimeImmutable;

/**
 * Normalisasi dan validasi isian identitas pribadi. Satu aturan untuk edit
 * profil sendiri (web/API), formulir Data Guru/Data Pengurus, dan impor Excel,
 * sehingga pratinjau, simpan, dan formulir tidak berbeda pendapat.
 *
 * Isian kosong berarti "tidak diisi" (NULL). Pemanggil menentukan isian mana
 * yang boleh dikirim; isian lain diabaikan, bukan ditolak, agar klien lama
 * yang mengirim kunci tambahan tidak gagal — tetapi kunci terlarang (username,
 * role, penugasan, …) ditolak eksplisit oleh `ProfilService::ubahDiri()`.
 */
final class ProfilData
{
    /** Isian pribadi yang boleh diubah pemilik akun (PRD §3.2). */
    public const DIRI = ['nama', 'tempat_lahir', 'tanggal_lahir', 'alamat_rumah', 'kelurahan', 'kecamatan', 'kabupaten', 'provinsi', 'no_hp', 'email'];

    /** Isian tambahan profil (selain nama/HP yang tinggal di tabel master). */
    public const TAMBAHAN = ['tempat_lahir', 'tanggal_lahir', 'alamat_rumah', 'kelurahan', 'kecamatan', 'kabupaten', 'provinsi', 'email'];

    /** Isian alamat berjenjang, untuk menyusun `wali.alamat`. */
    public const ALAMAT = ['alamat_rumah', 'kelurahan', 'kecamatan', 'kabupaten', 'provinsi'];

    public const LABEL = [
        'nama' => 'Nama lengkap', 'nip' => 'NIP', 'nik' => 'NIK', 'tempat_lahir' => 'Tempat lahir', 'tanggal_lahir' => 'Tanggal lahir',
        'alamat_rumah' => 'Alamat rumah', 'kelurahan' => 'Kelurahan/Desa', 'kecamatan' => 'Kecamatan', 'kabupaten' => 'Kabupaten/Kota',
        'provinsi' => 'Provinsi', 'no_hp' => 'Nomor HP', 'email' => 'Email',
    ];

    private const PANJANG = ['nama' => 100, 'tempat_lahir' => 100, 'alamat_rumah' => 255, 'kelurahan' => 100, 'kecamatan' => 100, 'kabupaten' => 100, 'provinsi' => 100, 'email' => 191];

    /**
     * @param array<string, mixed> $input
     * @param array<int, string> $fields isian yang dibaca dari $input
     * @param array{batas_hp?:int, batas_nip?:int, latin1_nama?:bool} $opsi
     * @return array{0: array<string, ?string>, 1: array<string, string>} [data, galat per kolom]
     */
    public static function normalisasi(array $input, array $fields, array $opsi = []): array
    {
        $data = [];
        $errors = [];
        foreach ($fields as $field) {
            $raw = $input[$field] ?? null;
            if ($raw !== null && !is_scalar($raw)) {
                $errors[$field] = self::LABEL[$field] . ' tidak valid.';
                $data[$field] = null;
                continue;
            }
            $value = match ($field) {
                'no_hp' => self::telepon($raw),
                'nip' => Normalizer::identifier($raw ?? ''),
                'nik' => preg_replace('/\s+/', '', trim((string) $raw)) ?? '',
                'email' => strtolower(Normalizer::text($raw ?? '')),
                'tanggal_lahir' => self::tanggal($raw),
                default => Normalizer::text($raw ?? ''),
            };
            $data[$field] = $value === '' ? null : $value;
        }

        if (array_key_exists('nama', $data)) {
            if ($data['nama'] === null) {
                $errors['nama'] = 'Nama lengkap wajib diisi.';
            } elseif (!empty($opsi['latin1_nama']) && !self::latin1($data['nama'])) {
                // `guru` memakai latin1 (skema V1): karakter di luarnya akan rusak diam-diam.
                $errors['nama'] = 'Nama guru hanya boleh memuat huruf Latin (termasuk huruf beraksen).';
            }
        }
        foreach (self::PANJANG as $field => $max) {
            if (isset($data[$field]) && mb_strlen((string) $data[$field]) > $max) {
                $errors[$field] = self::LABEL[$field] . ' maksimal ' . $max . ' karakter.';
            }
        }
        if (isset($data['no_hp'])) {
            $batas = (int) ($opsi['batas_hp'] ?? 16);
            if (!preg_match('/^0[0-9]{8,15}$/', $data['no_hp']) || strlen($data['no_hp']) > $batas) {
                $errors['no_hp'] = 'Nomor HP harus berupa 9–' . $batas . ' digit dan diawali 0.';
            }
        }
        if (isset($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Format email tidak valid.';
        }
        if (isset($data['nik']) && !preg_match('/^[0-9]{16}$/', $data['nik'])) {
            $errors['nik'] = 'NIK harus tepat 16 digit angka.';
        }
        if (isset($data['nip'])) {
            $batas = (int) ($opsi['batas_nip'] ?? 30);
            if (!preg_match('/^[A-Z0-9.\/-]{1,50}$/', $data['nip'])) {
                $errors['nip'] = 'NIP hanya boleh memuat huruf, angka, titik, garis miring, atau tanda hubung.';
            } elseif (strlen($data['nip']) > $batas) {
                $errors['nip'] = 'NIP maksimal ' . $batas . ' karakter.';
            }
        }
        if (array_key_exists('tanggal_lahir', $data) && $data['tanggal_lahir'] === '!') {
            $errors['tanggal_lahir'] = 'Tanggal lahir tidak valid (gunakan format TTTT-BB-HH atau HH/BB/TTTT).';
            $data['tanggal_lahir'] = null;
        }

        return [$data, $errors];
    }

    /** Alamat lengkap satu baris untuk kolom lama `wali.alamat`. */
    public static function alamatLengkap(array $profil): ?string
    {
        $bagian = [];
        foreach (self::ALAMAT as $field) {
            $nilai = trim((string) ($profil[$field] ?? ''));
            if ($nilai !== '') {
                $bagian[] = $nilai;
            }
        }

        return $bagian === [] ? null : implode(', ', $bagian);
    }

    private static function telepon(mixed $raw): string
    {
        if (is_float($raw) || is_int($raw)) {
            $raw = sprintf('%.0f', $raw);
        }
        $nilai = Normalizer::phone($raw ?? '');
        // Sel Excel numerik kehilangan nol awal: 81234567890 → 081234567890.
        if (preg_match('/^8[0-9]{8,13}$/', $nilai)) {
            $nilai = '0' . $nilai;
        }

        return $nilai;
    }

    /** Tanggal ISO, '' bila kosong, '!' bila tidak terbaca. */
    private static function tanggal(mixed $raw): string
    {
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return '';
        }
        if (is_int($raw) || is_float($raw) || (is_string($raw) && preg_match('/^[0-9]{5}(\.[0-9]+)?$/', trim($raw)))) {
            // Nomor seri tanggal Excel (sistem 1900; 25569 = 1970-01-01).
            $serial = (int) floor((float) $raw);
            if ($serial < 1 || $serial > 80000) {
                return '!';
            }
            return self::wajar(gmdate('Y-m-d', ($serial - 25569) * 86400));
        }
        $teks = trim((string) $raw);
        foreach (['!Y-m-d', '!d/m/Y', '!d-m-Y', '!d.m.Y', '!Y/m/d'] as $format) {
            $tanggal = DateTimeImmutable::createFromFormat($format, $teks);
            if ($tanggal !== false && $tanggal->format(substr($format, 1)) === $teks) {
                return self::wajar($tanggal->format('Y-m-d'));
            }
            // Hari/bulan satu digit (1/2/1990).
            if ($tanggal !== false && in_array($format, ['!d/m/Y', '!d-m-Y', '!d.m.Y'], true)
                && preg_match('#^\d{1,2}[/.-]\d{1,2}[/.-]\d{4}$#', $teks)) {
                $bagian = preg_split('#[/.-]#', $teks);
                if (checkdate((int) $bagian[1], (int) $bagian[0], (int) $bagian[2])) {
                    return self::wajar(sprintf('%04d-%02d-%02d', $bagian[2], $bagian[1], $bagian[0]));
                }
            }
        }

        return '!';
    }

    private static function wajar(string $iso): string
    {
        return $iso >= '1900-01-01' && $iso <= date('Y-m-d') ? $iso : '!';
    }

    private static function latin1(string $value): bool
    {
        $konversi = @mb_convert_encoding($value, 'ISO-8859-1', 'UTF-8');

        return $konversi !== false && mb_convert_encoding($konversi, 'UTF-8', 'ISO-8859-1') === $value;
    }
}
