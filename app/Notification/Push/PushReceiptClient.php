<?php

declare(strict_types=1);

namespace App\Notification\Push;

/**
 * Kontrak pengambilan RECEIPT AKHIR push (V2 Fase 5).
 *
 * Mengapa terpisah dari `PushClient`?
 *
 *  1. **Tiket bukan bukti pengantaran.** Fase 4 berhenti pada tiket awal Expo,
 *     yang hanya membuktikan Expo MENERIMA pesan. Receipt akhir adalah jawaban
 *     Expo setelah FCM/APNs menjawab, dan itulah yang membuktikan pengantaran.
 *     Temuan terbuka Fase 4 (`acceptance-status.md` §5) menuntut lapisan ini.
 *
 *  2. **Kompatibilitas mundur.** Antarmuka `PushClient` sengaja TIDAK diubah.
 *     Seluruh klien tiruan pada pengujian Fase 4 mengimplementasikan
 *     `PushClient`; menambahkan metode ke sana akan membuat mereka berhenti
 *     memenuhi kontrak dan memaksa perubahan berkas uji Fase 4 yang sudah
 *     lulus audit. Dispatcher memeriksa `instanceof PushReceiptClient` dan
 *     melewatkan rekonsiliasi dengan tenang bila klien tidak mendukungnya.
 */
interface PushReceiptClient
{
    /**
     * Mengambil receipt akhir untuk sekumpulan id tiket.
     *
     * @param array<int, string> $ticketIds
     * @return array{ok:bool, receipts:array<string, array<string, mixed>>, kode:string, pesan:string, permanen:bool}
     *         `receipts` dipetakan berdasarkan id tiket. Tiket yang belum
     *         dijawab penyedia TIDAK muncul pada peta tersebut — itu berarti
     *         "belum tersedia", bukan "gagal".
     */
    public function getReceipts(array $ticketIds): array;
}
