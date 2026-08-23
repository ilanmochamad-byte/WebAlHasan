<?php

declare(strict_types=1);

namespace App\Notification;

use Throwable;

/**
 * Pembersih pesan galat penyedia eksternal.
 *
 * Setiap galat push/WhatsApp melewati kelas ini SEBELUM disimpan ke basis data,
 * ditulis ke log, atau ditampilkan kepada admin. Tujuannya satu: memenuhi PRD
 * Fase 4 §6.6 dan §9 — secret, credential, token perangkat, nomor tujuan, dan
 * URL berparameter tidak boleh muncul di respons API, database, audit, log,
 * maupun pesan error.
 *
 * Pendekatan yang dipakai adalah DAFTAR IZIN, bukan daftar larangan: pesan
 * dipotong pendek, lalu setiap pola yang menyerupai rahasia diganti penanda.
 * Bila ragu, lebih baik pesan menjadi kurang informatif daripada bocor.
 */
final class SafeError
{
    public const MAX_LENGTH = 255;

    /**
     * Pola yang selalu disamarkan. Urutan penting: pola paling spesifik dulu.
     *
     * @var array<int, array{0:string, 1:string}>
     */
    private const REDACTIONS = [
        // Token push Expo: ExponentPushToken[...] / ExpoPushToken[...]
        ['/Expo(?:nent)?PushToken\[[^\]]*\]/i', '[token disamarkan]'],
        // Skema Bearer: disamarkan LEBIH DULU agar nilainya tidak tertinggal
        // ketika pola "kunci: nilai" di bawah hanya memakan kata "Bearer".
        ['/\bBearer\s+[A-Za-z0-9._\-]{8,}/i', 'Bearer [disamarkan]'],
        // JSON Web Token dalam bentuk apa pun (tiga segmen dipisah titik).
        ['/\b[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]{8,}\b/', '[disamarkan]'],
        // Header/param credential yang lazim dipakai penyedia WhatsApp.
        ['/\b(?:authorization|api[_-]?key|apikey|access[_-]?token|token|secret|password|passwd|pwd|bearer|sid|auth)\b\s*[:=]\s*\S+/i', '$0'],
        // JSON: "token": "...."  (nilai diganti, kuncinya tetap agar tetap informatif)
        ['/"(?:authorization|api[_-]?key|apikey|access[_-]?token|token|secret|password|auth|sid)"\s*:\s*"[^"]*"/i', '"$0"'],
        // Query string berisi credential.
        ['/([?&](?:token|key|api_key|apikey|secret|access_token|auth|password)=)[^&\s]*/i', '$1[disamarkan]'],
        // Nomor telepon (WhatsApp) 8 digit atau lebih, dengan/ tanpa +.
        ['/\+?\d[\d\s\-().]{7,}\d/', '[nomor disamarkan]'],
        // Alamat email.
        ['/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '[email disamarkan]'],
        // String heksadesimal/base64 panjang: kandidat kuat sebuah secret.
        ['/\b[A-Fa-f0-9]{32,}\b/', '[disamarkan]'],
        ['/\b[A-Za-z0-9_\-]{40,}\b/', '[disamarkan]'],
    ];

    /**
     * Kunci JSON/heading yang nilainya selalu dihapus total.
     *
     * @var array<int, string>
     */
    private const SENSITIVE_KEYS = [
        'authorization', 'api_key', 'apikey', 'api-key', 'access_token', 'accesstoken',
        'token', 'secret', 'password', 'passwd', 'pwd', 'auth', 'sid', 'signature',
        'private_key', 'client_secret', 'session', 'cookie',
    ];

    /**
     * Membersihkan pesan bebas menjadi pesan aman berukuran terbatas.
     */
    public static function message(?string $raw, string $fallback = 'Pengiriman gagal tanpa detail yang dapat ditampilkan.'): string
    {
        $value = trim((string) $raw);
        if ($value === '') {
            return $fallback;
        }

        // Normalkan spasi/baris baru agar pola tidak terlewat karena pemenggalan.
        $value = (string) preg_replace('/\s+/u', ' ', $value);

        foreach (self::REDACTIONS as [$pattern, $replacement]) {
            if ($replacement === '$0' || $replacement === '"$0"') {
                // Ganti nilai setelah kunci sensitif, pertahankan nama kuncinya.
                $value = (string) preg_replace_callback(
                    $pattern,
                    static function (array $matches): string {
                        $text = $matches[0];
                        if (preg_match('/^"([^"]+)"\s*:/', $text, $key) === 1) {
                            return '"' . $key[1] . '":"[disamarkan]"';
                        }
                        if (preg_match('/^([A-Za-z_\-]+)\s*[:=]/', $text, $key) === 1) {
                            return $key[1] . '=[disamarkan]';
                        }

                        return '[disamarkan]';
                    },
                    $value
                );
                continue;
            }
            $value = (string) preg_replace($pattern, $replacement, $value);
        }

        $value = trim($value);
        if ($value === '') {
            return $fallback;
        }

        if (mb_strlen($value) > self::MAX_LENGTH) {
            $value = mb_substr($value, 0, self::MAX_LENGTH - 1) . '…';
        }

        return $value;
    }

    /**
     * Membersihkan exception tanpa pernah membocorkan jejak tumpukan atau
     * argumen pemanggil (yang bisa memuat credential penyedia).
     */
    public static function fromThrowable(Throwable $exception, string $fallback = 'Pengiriman gagal karena galat internal.'): string
    {
        return self::message($exception->getMessage(), $fallback);
    }

    /**
     * Kode galat pendek yang aman (huruf, angka, garis bawah).
     */
    public static function code(?string $raw, string $fallback = 'UNKNOWN'): string
    {
        $value = strtoupper(trim((string) $raw));
        $value = (string) preg_replace('/[^A-Z0-9_]/', '_', $value);
        $value = trim($value, '_');

        if ($value === '') {
            return $fallback;
        }

        return substr($value, 0, 60);
    }

    /**
     * Membersihkan struktur (array respons penyedia) untuk dicatat sebagai
     * ringkasan. Nilai pada kunci sensitif dihapus sepenuhnya.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function scrub(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 6) {
            return '[terlalu dalam]';
        }
        if (is_array($value)) {
            $clean = [];
            foreach ($value as $key => $item) {
                if (is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
                    $clean[$key] = '[disamarkan]';
                    continue;
                }
                $clean[$key] = self::scrub($item, $depth + 1);
            }

            return $clean;
        }
        if (is_string($value)) {
            return self::message($value, '');
        }

        return $value;
    }
}
