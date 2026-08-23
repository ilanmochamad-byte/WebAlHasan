<?php

declare(strict_types=1);

namespace App\Notification\WhatsApp;

/**
 * Adapter uji: memverifikasi KONTRAK tanpa mengirim pesan nyata.
 *
 * Dipakai untuk menguji enqueue, send, fail, retry, dan deduplikasi selama
 * penyedia sungguhan belum tersedia (PRD Fase 4 §6.7). Tiga pengaman:
 *
 *   1. `mengirimNyata()` mengembalikan false. Lapisan admin memakai nilai ini
 *      untuk menuliskan "adapter uji — bukan pengiriman nyata" pada status dan
 *      audit, sehingga sistem tidak pernah mengklaim WhatsApp nyata lulus.
 *   2. Tidak ada satu pun panggilan jaringan.
 *   3. Menolak aktif ketika `APP_ENV=production`, agar adapter uji tidak
 *      pernah menjadi jalur produksi karena salah konfigurasi.
 *
 * Perilaku dapat diarahkan lewat environment untuk menguji jalur gagal:
 *   WHATSAPP_FAKE_MODE = ok | gagal | gagal_permanen | verify_gagal
 */
final class FakeProvider implements WhatsAppProvider
{
    /** @var array<int, array<string, mixed>> */
    private array $terkirim = [];

    public function __construct(
        private string $mode = 'ok',
        private bool $produksi = false,
        private ?string $jurnalPath = null
    ) {
    }

    public function name(): string
    {
        return 'fake';
    }

    public function mengirimNyata(): bool
    {
        return false;
    }

    public function readiness(): array
    {
        if ($this->produksi) {
            return [
                'siap' => false,
                'pesan' => 'Adapter uji WhatsApp tidak boleh dipakai pada APP_ENV=production.',
                'detail' => ['Pilih penyedia sungguhan atau biarkan WhatsApp mati.'],
            ];
        }

        return [
            'siap' => $this->mode !== 'verify_gagal',
            'pesan' => $this->mode === 'verify_gagal'
                ? 'Adapter uji sengaja dikonfigurasi gagal (WHATSAPP_FAKE_MODE=verify_gagal).'
                : 'Adapter uji siap. TIDAK ada pesan nyata yang dikirim.',
            'detail' => [
                'Adapter uji tidak menghubungi penyedia mana pun.',
                'Hasil "lulus" pada adapter ini BUKAN bukti pengiriman WhatsApp nyata.',
            ],
        ];
    }

    public function verify(): ProviderResult
    {
        if ($this->produksi) {
            return ProviderResult::permanen(
                'ADAPTER_UJI_DI_PRODUKSI',
                'Adapter uji WhatsApp tidak diizinkan pada APP_ENV=production.'
            );
        }
        if ($this->mode === 'verify_gagal') {
            return ProviderResult::permanen('KONFIGURASI_TIDAK_VALID', 'Pemeriksaan adapter uji sengaja gagal.');
        }

        return ProviderResult::ok('Adapter uji siap. Tidak ada pesan nyata yang dikirim.');
    }

    public function send(WhatsAppMessage $message): ProviderResult
    {
        if ($this->produksi) {
            return ProviderResult::permanen(
                'ADAPTER_UJI_DI_PRODUKSI',
                'Adapter uji WhatsApp tidak diizinkan pada APP_ENV=production.'
            );
        }

        // Jurnal memakai tujuan TERSAMAR: bahkan pada adapter uji, nomor utuh
        // tidak ditulis ke berkas.
        $entri = [
            'event_key' => $message->eventKey,
            'tujuan' => $message->tujuanTersamar(),
            'judul' => $message->judul,
            'panjang_isi' => mb_strlen($message->isi),
            'waktu' => date('c'),
        ];
        $this->terkirim[] = $entri;
        if ($this->jurnalPath !== null) {
            @file_put_contents(
                $this->jurnalPath,
                json_encode($entri, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
                FILE_APPEND | LOCK_EX
            );
        }

        return match ($this->mode) {
            'gagal' => ProviderResult::gagal('UJI_GAGAL_SEMENTARA', 'Adapter uji mengembalikan kegagalan sementara.'),
            'gagal_permanen' => ProviderResult::permanen('UJI_GAGAL_PERMANEN', 'Adapter uji mengembalikan kegagalan permanen.'),
            default => ProviderResult::ok('Pesan dicatat adapter uji (tidak dikirim ke penyedia nyata).', 'fake-' . count($this->terkirim)),
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function terkirim(): array
    {
        return $this->terkirim;
    }
}
