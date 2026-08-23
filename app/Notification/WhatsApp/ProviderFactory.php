<?php

declare(strict_types=1);

namespace App\Notification\WhatsApp;

use App\Support\Env;

/**
 * Pemilih penyedia WhatsApp berdasarkan environment server.
 *
 * Default sistem adalah `NullProvider`: tidak ada vendor, tidak ada koneksi
 * keluar, dan WhatsApp tetap mati (PRD Fase 4 §6.3). Sistem tidak pernah
 * memilih vendor berbayar, membuat akun, atau mengirim pesan tanpa admin
 * menyalakannya lebih dahulu melalui halaman pengaturan.
 *
 * Nilai credential dibaca langsung dari environment ke dalam adapter dan tidak
 * pernah melewati basis data, audit, respons API, maupun bundle mobile.
 */
final class ProviderFactory
{
    /**
     * Nama environment yang dibutuhkan setiap penyedia (untuk dokumentasi dan
     * halaman kesiapan). Hanya NAMA — tidak pernah nilainya.
     *
     * @var array<string, array<int, string>>
     */
    public const ENV_KEYS = [
        'http' => [
            'WHATSAPP_API_URL',
            'WHATSAPP_API_TOKEN',
            'WHATSAPP_AUTH_HEADER',
            'WHATSAPP_AUTH_PREFIX',
            'WHATSAPP_SENDER_ID',
            'WHATSAPP_TEMPLATE_NAME',
            'WHATSAPP_FIELD_TO',
            'WHATSAPP_FIELD_TEXT',
            'WHATSAPP_VERIFY_URL',
            'WHATSAPP_TIMEOUT_SECONDS',
        ],
        'fake' => ['WHATSAPP_FAKE_MODE', 'WHATSAPP_FAKE_JOURNAL'],
        'belum-dipilih' => [],
    ];

    public static function make(?string $appEnv = null): WhatsAppProvider
    {
        $appEnv ??= (string) Env::get('APP_ENV', 'production');
        $produksi = strtolower($appEnv) === 'production';
        $pilihan = strtolower(trim((string) Env::get('WHATSAPP_PROVIDER', '')));

        return match ($pilihan) {
            '' , 'none', 'null' => new NullProvider(),
            'fake', 'test', 'uji' => new FakeProvider(
                strtolower(trim((string) Env::get('WHATSAPP_FAKE_MODE', 'ok'))),
                $produksi,
                self::jurnalPath()
            ),
            default => new HttpProvider(self::httpEnv(), $pilihan),
        };
    }

    /**
     * Daftar penyedia yang dikenal (untuk dokumentasi dan halaman admin).
     *
     * @return array<int, string>
     */
    public static function dikenal(): array
    {
        return ['belum-dipilih', 'fake', 'http'];
    }

    /**
     * @return array<string, string>
     */
    private static function httpEnv(): array
    {
        $env = [];
        foreach (self::ENV_KEYS['http'] as $key) {
            $env[$key] = (string) Env::get($key, '');
        }

        return $env;
    }

    private static function jurnalPath(): ?string
    {
        $path = trim((string) Env::get('WHATSAPP_FAKE_JOURNAL', ''));

        return $path === '' ? null : $path;
    }
}
