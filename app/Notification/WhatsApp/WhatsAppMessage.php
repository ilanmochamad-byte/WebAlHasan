<?php

declare(strict_types=1);

namespace App\Notification\WhatsApp;

/**
 * Pesan WhatsApp keluar.
 *
 * Isi pesan sudah dirakit `NotificationEvent::render()` dengan varian kanal
 * eksternal: tanpa alasan izin, catatan pengurus, nama santri, atau data
 * pribadi lain. Objek ini hanya membawa apa yang perlu diketahui penyedia.
 */
final class WhatsAppMessage
{
    public function __construct(
        public readonly string $tujuan,
        public readonly string $judul,
        public readonly string $isi,
        public readonly string $eventKey,
        public readonly ?string $templateVars = null
    ) {
    }

    /**
     * Teks utuh yang dikirim ke penyedia.
     */
    public function teks(): string
    {
        return trim($this->judul . "\n" . $this->isi);
    }

    /**
     * Bentuk tujuan yang aman untuk log/audit: nomor tidak pernah utuh.
     */
    public function tujuanTersamar(): string
    {
        $digits = preg_replace('/\D+/', '', $this->tujuan) ?? '';
        if (strlen($digits) <= 4) {
            return '••••';
        }

        return str_repeat('•', max(4, strlen($digits) - 4)) . substr($digits, -4);
    }
}
