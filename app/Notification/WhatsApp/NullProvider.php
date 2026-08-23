<?php

declare(strict_types=1);

namespace App\Notification\WhatsApp;

/**
 * Penyedia bawaan ketika belum ada vendor yang dipilih.
 *
 * Inilah keadaan DEFAULT sistem: WhatsApp mati dan tidak ada vendor yang
 * dikonfigurasi. Kelas ini tidak pernah membuka koneksi jaringan, sehingga
 * memenuhi kriteria "saat WhatsApp mati/tidak siap, tidak ada request ke
 * penyedia" tanpa bergantung pada kedisiplinan pemanggil.
 */
final class NullProvider implements WhatsAppProvider
{
    public function name(): string
    {
        return 'belum-dipilih';
    }

    public function mengirimNyata(): bool
    {
        return false;
    }

    public function readiness(): array
    {
        return [
            'siap' => false,
            'pesan' => 'Penyedia WhatsApp belum dipilih. Isi WHATSAPP_PROVIDER pada environment server.',
            'detail' => [
                'WHATSAPP_PROVIDER belum diisi.',
                'Tidak ada permintaan yang dikirim ke penyedia mana pun.',
            ],
        ];
    }

    public function verify(): ProviderResult
    {
        return ProviderResult::permanen(
            'PROVIDER_BELUM_DIPILIH',
            'Penyedia WhatsApp belum dipilih pada environment server.'
        );
    }

    public function send(WhatsAppMessage $message): ProviderResult
    {
        return ProviderResult::permanen(
            'PROVIDER_BELUM_DIPILIH',
            'Penyedia WhatsApp belum dipilih; pesan tidak dikirim.'
        );
    }
}
