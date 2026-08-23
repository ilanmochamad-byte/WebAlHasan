<?php

declare(strict_types=1);

namespace App\Notification;

/**
 * Perlindungan token push perangkat.
 *
 * PRD Fase 4 §5.6 melarang token perangkat tampil kepada pengguna lain, masuk
 * log umum, atau tersimpan di audit. Kelas ini menegakkan bentuk penyimpanannya:
 *
 *   - `token_hash`        : HMAC-SHA256 (hex, 64 karakter). Dipakai untuk
 *                           mencari/menyamakan token tanpa pernah menyimpan
 *                           nilai aslinya dalam bentuk terbaca.
 *   - `token_terlindungi` : sandi AES-256-GCM dari token asli. Hanya worker
 *                           pengirim push yang membukanya, di dalam proses,
 *                           tepat sebelum memanggil layanan push.
 *
 * Kunci berasal dari environment server (`PUSH_TOKEN_KEY`) dan tidak pernah
 * disimpan di basis data, repositori, atau bundle aplikasi. Dua subkunci
 * berbeda diturunkan dengan HKDF sehingga kunci hash dan kunci enkripsi tidak
 * pernah sama.
 *
 * AES-256-GCM dipilih karena `ext-openssl` praktis selalu tersedia pada hosting
 * cPanel, sedangkan `ext-sodium` tidak dijamin ada.
 */
final class PushTokenProtector
{
    private const CIPHER = 'aes-256-gcm';
    private const VERSION = "\x01";
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;

    private ?string $hashKey = null;
    private ?string $encKey = null;

    public function __construct(private string $masterKey)
    {
        $raw = $this->decodeKey($masterKey);
        if ($raw !== null) {
            $this->hashKey = hash_hkdf('sha256', $raw, 32, 'alhasan-push-token-hash');
            $this->encKey = hash_hkdf('sha256', $raw, 32, 'alhasan-push-token-enc');
        }
    }

    /**
     * Apakah konfigurasi kunci sudah siap dipakai.
     *
     * Halaman pemeriksaan konfigurasi admin memakai ini untuk melaporkan
     * kesiapan push TANPA menampilkan kunci apa pun.
     */
    public function ready(): bool
    {
        return $this->hashKey !== null && $this->encKey !== null && extension_loaded('openssl');
    }

    public function reason(): ?string
    {
        if (!extension_loaded('openssl')) {
            return 'Ekstensi PHP openssl tidak aktif di server ini.';
        }
        if ($this->masterKey === '') {
            return 'Environment PUSH_TOKEN_KEY belum diisi pada server.';
        }
        if ($this->hashKey === null) {
            return 'Nilai PUSH_TOKEN_KEY tidak valid: wajib 32 byte acak dalam base64.';
        }

        return null;
    }

    public function hash(string $token): string
    {
        if ($this->hashKey === null) {
            throw NotificationException::unavailable('Kunci perlindungan token push belum dikonfigurasi di server.');
        }

        return hash_hmac('sha256', $token, $this->hashKey);
    }

    /**
     * Sandi siap simpan pada kolom `token_terlindungi`.
     *
     * Hasilnya di-base64 (ASCII) dengan sengaja: kolomnya VARBINARY, tetapi
     * byte mentah harus melewati koneksi yang ber-charset utf8mb4 menuju server
     * MySQL/MariaDB yang konfigurasinya di luar kendali kita (cPanel). ASCII
     * menghilangkan seluruh risiko konversi charset tanpa mengurangi kekuatan
     * enkripsi — yang dilindungi adalah kuncinya, bukan penyandiannya.
     */
    public function protect(string $token): string
    {
        if ($this->encKey === null || !extension_loaded('openssl')) {
            throw NotificationException::unavailable('Kunci perlindungan token push belum dikonfigurasi di server.');
        }
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';
        $cipher = openssl_encrypt($token, self::CIPHER, $this->encKey, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LENGTH);
        if ($cipher === false) {
            throw NotificationException::unavailable('Token push tidak dapat dilindungi pada server ini.');
        }

        return base64_encode(self::VERSION . $iv . $tag . $cipher);
    }

    /**
     * Mengembalikan token asli, atau null bila sandi rusak/kunci berganti.
     * Pemanggil WAJIB memperlakukan hasilnya sebagai rahasia: tidak boleh
     * masuk log, audit, atau respons API.
     */
    public function reveal(?string $protected): ?string
    {
        if ($protected === null || $this->encKey === null || !extension_loaded('openssl')) {
            return null;
        }
        $decoded = base64_decode($protected, true);
        if ($decoded === false) {
            return null;
        }
        $protected = $decoded;
        $minimum = 1 + self::IV_LENGTH + self::TAG_LENGTH;
        if (strlen($protected) <= $minimum || $protected[0] !== self::VERSION) {
            return null;
        }
        $iv = substr($protected, 1, self::IV_LENGTH);
        $tag = substr($protected, 1 + self::IV_LENGTH, self::TAG_LENGTH);
        $cipher = substr($protected, $minimum);

        $plain = openssl_decrypt($cipher, self::CIPHER, $this->encKey, OPENSSL_RAW_DATA, $iv, $tag);

        return $plain === false ? null : $plain;
    }

    /**
     * Bentuk aman token untuk ditampilkan kepada PEMILIKNYA sendiri pada daftar
     * perangkat. Tidak pernah cukup untuk mengirim push dan tidak pernah
     * ditampilkan kepada pengguna lain.
     */
    public static function mask(string $token): string
    {
        $panjang = strlen($token);
        if ($panjang <= 8) {
            return str_repeat('•', max(4, $panjang));
        }

        return substr($token, 0, 4) . str_repeat('•', 8) . substr($token, -4);
    }

    private function decodeKey(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $raw = base64_decode($value, true);
        if ($raw === false || strlen($raw) < 32) {
            // Terima juga kunci heksadesimal 64 karakter agar operator tidak
            // terkunci pada satu format.
            if (preg_match('/^[A-Fa-f0-9]{64}$/', $value) === 1) {
                $raw = (string) hex2bin($value);
            } else {
                return null;
            }
        }

        return substr($raw, 0, 32);
    }
}
