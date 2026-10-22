<?php

declare(strict_types=1);

namespace App\Ui;

/**
 * Kamus warna status bersama (redesain website Tahap 1, 22 Oktober 2026).
 *
 * Satu kamus untuk seluruh modul sehingga warna yang sama selalu berarti hal
 * yang sama:
 *   hijau (ok)     = selesai / disetujui / lunas / terbit
 *   biru (info)    = diajukan / baru / diserahkan
 *   kuning (warn)  = menunggu / perlu perbaikan / belum lunas
 *   merah (danger) = ditolak / ditarik / gagal
 *   abu (muted)    = draf / arsip / dibatalkan
 *
 * Label status selalu berisi kata; warna hanya penguat. Kelas ini murni
 * presentasi dan tidak menentukan alur status apa pun.
 */
final class Status
{
    /** @var array<string, string> */
    private const TONES = [
        // Hijau
        'selesai' => 'ok',
        'disetujui' => 'ok',
        'lunas' => 'ok',
        'terbit' => 'ok',
        'diterbitkan' => 'ok',
        'terverifikasi' => 'ok',
        'diterima' => 'ok',
        'dimigrasi' => 'ok',
        'aktif' => 'ok',
        'terkirim' => 'ok',
        'lulus' => 'ok',
        'hadir' => 'ok',
        'lengkap' => 'ok',
        // Biru
        'diajukan' => 'info',
        'baru' => 'info',
        'diserahkan' => 'info',
        'dibuka' => 'info',
        'berlangsung' => 'info',
        'belum dibaca' => 'info',
        // Kuning
        'menunggu' => 'warn',
        'perlu perbaikan' => 'warn',
        'perlu penetapan admin' => 'warn',
        'dikembalikan' => 'warn',
        'belum lunas' => 'warn',
        'cadangan' => 'warn',
        'terlambat' => 'warn',
        'nonaktif' => 'warn',
        // Merah
        'ditolak' => 'danger',
        'ditarik' => 'danger',
        'gagal' => 'danger',
        'berhenti' => 'danger',
        // Abu
        'draf' => 'muted',
        'arsip' => 'muted',
        'dibatalkan' => 'muted',
        'berakhir' => 'muted',
        'ditutup' => 'muted',
        'ditangguhkan' => 'muted',
        'dibaca' => 'muted',
        'belum terbit' => 'muted',
        'belum waktunya' => 'muted',
    ];

    /** Awalan kata yang selalu bermakna "menunggu tindakan". */
    private const WARN_PREFIXES = ['menunggu', 'perlu ', 'belum '];

    public static function tone(string $status, string $fallback = 'info'): string
    {
        $key = mb_strtolower(trim($status));
        if (isset(self::TONES[$key])) {
            return self::TONES[$key];
        }
        foreach (self::WARN_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return 'warn';
            }
        }

        return $fallback;
    }
}
