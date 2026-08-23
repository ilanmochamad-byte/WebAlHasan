<?php

declare(strict_types=1);

namespace App\Notification\Push;

/**
 * Kontrak klien pengiriman push.
 *
 * Dipisahkan dari implementasinya karena dua alasan:
 *
 *  1. **Dapat diuji tanpa jaringan.** Pengujian otomatis menyuntikkan klien
 *     tiruan sehingga alur outbox, tiket, pencabutan token, backoff, dan
 *     deduplikasi dapat diverifikasi tanpa satu pun permintaan keluar dan
 *     tanpa memerlukan credential push.
 *  2. **Tidak mengunci vendor.** Kontrak ini berbicara dalam istilah "pesan
 *     masuk, tiket keluar" — bentuk yang sama dipakai layanan push Expo dan
 *     dapat dipetakan ke layanan lain bila suatu saat diperlukan.
 */
interface PushClient
{
    /**
     * Mengirim satu batch pesan.
     *
     * @param array<int, array<string, mixed>> $messages
     * @return array{ok:bool, tickets:array<int, array<string, mixed>>, kode:string, pesan:string, permanen:bool}
     */
    public function send(array $messages): array;
}
