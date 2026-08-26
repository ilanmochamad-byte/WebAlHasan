<?php

declare(strict_types=1);

namespace App\Notification;

use App\Notification\Push\ExpoPushClient;
use App\Notification\Push\PushClient;
use App\Notification\Push\PushReceiptClient;
use App\Notification\WhatsApp\WhatsAppMessage;
use App\Notification\WhatsApp\WhatsAppProvider;
use mysqli;
use Throwable;

/**
 * Pekerja outbox: mengubah baris `Queued` menjadi `Sent` atau `Failed`.
 *
 * Dijalankan cron cPanel dan juga dapat dijalankan manual untuk pengujian.
 * Sifat-sifat yang dijaga:
 *
 *  - **Aman diulang.** Klaim baris memakai UPDATE atomik dengan pemilik dan
 *    masa berlaku, ditambah sewa proses `WorkerLock`. Dua cron yang tumpang
 *    tindih tidak pernah mengirim baris yang sama dua kali.
 *  - **Tidak pernah mengirim saat kanal mati.** Sakelar dibaca ulang setiap
 *    putaran; ketika kanal dimatikan, baris dilepas tanpa dihitung sebagai
 *    percobaan dan TIDAK ada satu pun permintaan ke penyedia.
 *  - **Tidak pernah mencetak rahasia.** Token perangkat dibuka hanya di memori,
 *    nomor WhatsApp ditampilkan tersamar, dan seluruh galat melewati
 *    `SafeError` sebelum disimpan atau dicetak.
 *  - **Tidak pernah membatalkan transaksi bisnis.** Pekerja ini hanya menyentuh
 *    tabel notifikasi; kegagalan apa pun di sini tidak mempengaruhi pengajuan
 *    atau keputusan yang sudah tersimpan.
 */
final class NotificationDispatcher
{
    public const ANDROID_CHANNEL_ID = 'perizinan';

    public function __construct(
        private mysqli $db,
        private OutboxRepository $outbox,
        private DeviceRepository $devices,
        private PushTokenProtector $protector,
        private PushClient $expo,
        private WhatsAppProvider $whatsapp,
        private SettingsRepository $settings,
        private WorkerLock $lock
    ) {
    }

    /**
     * Menjalankan satu putaran untuk satu kanal.
     *
     * @return array{kanal:string, dijalankan:bool, alasan:?string, diproses:int,
     *               terkirim:int, gagal:int, dilepas:int, catatan:array<int,string>}
     */
    public function run(string $kanal, int $batch = 25, bool $dryRun = false): array
    {
        $hasil = [
            'kanal' => $kanal,
            'dijalankan' => false,
            'alasan' => null,
            'diproses' => 0,
            'terkirim' => 0,
            'gagal' => 0,
            'dilepas' => 0,
            'catatan' => [],
        ];

        if (!in_array($kanal, NotificationChannel::EKSTERNAL, true)) {
            $hasil['alasan'] = 'Kanal ' . $kanal . ' tidak diproses worker.';

            return $hasil;
        }

        $pengaturan = $this->settings->current();
        $aktif = $kanal === NotificationChannel::PUSH
            ? $pengaturan['push_enabled'] === true
            : ($pengaturan['whatsapp_enabled'] === true && $pengaturan['whatsapp_check_status'] === 'Lulus');

        if (!$aktif) {
            // Berhenti SEBELUM klaim maupun koneksi apa pun.
            $hasil['alasan'] = 'Kanal ' . $kanal . ' sedang nonaktif. Tidak ada permintaan ke penyedia.';

            return $hasil;
        }

        if ($kanal === NotificationChannel::PUSH && !$this->protector->ready()) {
            $hasil['alasan'] = 'Konfigurasi push belum siap: ' . (string) $this->protector->reason();

            return $hasil;
        }
        if ($kanal === NotificationChannel::WHATSAPP) {
            $readiness = $this->whatsapp->readiness();
            if ($readiness['siap'] !== true) {
                $hasil['alasan'] = 'Penyedia WhatsApp belum siap: ' . $readiness['pesan'];

                return $hasil;
            }
        }

        $namaLock = $kanal === NotificationChannel::PUSH ? WorkerLock::PUSH : WorkerLock::WHATSAPP;
        if (!$this->lock->acquire($namaLock)) {
            $hasil['alasan'] = 'Worker lain sedang memproses kanal ini. Putaran ini dilewati.';

            return $hasil;
        }

        $hasil['dijalankan'] = true;
        $owner = (string) $this->lock->owner();

        try {
            if ($dryRun) {
                // Mode manual aman: tidak mengklaim, tidak mengirim, tidak mengubah data.
                $hasil['diproses'] = $this->outbox->pendingCount($kanal);
                $hasil['catatan'][] = 'Mode uji coba (dry-run): tidak ada baris yang diklaim maupun dikirim.';

                return $hasil;
            }

            $rows = $this->outbox->claim($kanal, $owner, $batch);
            foreach ($rows as $row) {
                // Heartbeat sebelum setiap panggilan penyedia. Batch hingga 100
                // baris dapat melampaui sewa awal lima menit; kedua sewa harus
                // tetap dimiliki agar worker lain tidak mengambil baris yang
                // sama di tengah putaran.
                if (!$this->lock->renew() || !$this->outbox->renewClaims($owner)) {
                    $hasil['catatan'][] = 'Sewa worker tidak dapat diperpanjang; putaran dihentikan sebelum pengiriman berikutnya.';
                    break;
                }
                $hasil['diproses']++;
                try {
                    $status = $kanal === NotificationChannel::PUSH
                        ? $this->kirimPush($row, $owner)
                        : $this->kirimWhatsapp($row, $owner);
                } catch (Throwable $exception) {
                    $status = 'gagal';
                    $this->outbox->markFailed(
                        (int) $row['id'],
                        $owner,
                        'GALAT_INTERNAL',
                        SafeError::fromThrowable($exception)
                    );
                }
                if ($status === 'terkirim') {
                    $hasil['terkirim']++;
                } elseif ($status === 'dilepas') {
                    $hasil['dilepas']++;
                } else {
                    $hasil['gagal']++;
                }
            }
        } finally {
            $this->lock->release();
        }

        return $hasil;
    }

    /**
     * @param array<string, mixed> $row
     * @return 'terkirim'|'gagal'|'dilepas'
     */
    private function kirimPush(array $row, string $owner): string
    {
        $outboxId = (int) $row['id'];
        $userId = (int) $row['penerima_user_id'];
        $perangkat = $this->devices->activeTokensFor($userId);

        if ($perangkat === []) {
            // Penerima mencabut atau menghapus seluruh perangkatnya setelah
            // baris diantrekan. Tidak ada gunanya mencoba lagi.
            $this->outbox->markFailed(
                $outboxId,
                $owner,
                'TANPA_PERANGKAT',
                'Penerima tidak memiliki perangkat push aktif.',
                true
            );

            return 'gagal';
        }

        $data = json_decode((string) ($row['data_json'] ?? ''), true);
        $data = is_array($data) ? $data : [];
        // Tegaskan: payload push tidak boleh membawa apa pun selain penunjuk.
        $data = array_intersect_key($data, array_flip(['tipe', 'event', 'pengajuan_id', 'url']));

        $messages = [];
        $petaToken = [];
        foreach ($perangkat as $device) {
            $token = $this->protector->reveal($device['token_terlindungi'] ?? null);
            if ($token === null || !ExpoPushClient::looksLikeExpoToken($token)) {
                // Token rusak atau bukan token Expo: cabut, jangan dipakai lagi.
                $this->devices->revokeInvalidToken((string) $device['token_hash']);
                continue;
            }
            $messages[] = ExpoPushClient::message(
                $token,
                (string) $row['judul'],
                (string) $row['isi'],
                $data,
                self::ANDROID_CHANNEL_ID
            );
            $petaToken[] = ['hash' => (string) $device['token_hash'], 'id' => (int) $device['id']];
            unset($token);
        }

        if ($messages === []) {
            $this->outbox->markFailed(
                $outboxId,
                $owner,
                'TOKEN_TIDAK_VALID',
                'Seluruh token perangkat penerima tidak valid dan telah dicabut.',
                true
            );

            return 'gagal';
        }

        $mulai = microtime(true);
        $response = $this->expo->send($messages);
        $durasi = (int) round((microtime(true) - $mulai) * 1000);

        if ($response['ok'] !== true) {
            $this->outbox->markFailed(
                $outboxId,
                $owner,
                $response['kode'],
                $response['pesan'],
                $response['permanen'],
                $durasi
            );

            return 'gagal';
        }

        $berhasil = 0;
        $permanenSemua = true;
        $kodeTerakhir = 'TIKET_GAGAL';
        $pesanTerakhir = 'Expo menolak seluruh pesan.';
        // V2 Fase 5: id tiket pertama yang berhasil. Satu baris outbox dapat
        // menyebar ke beberapa perangkat milik penerima yang sama, sehingga
        // receipt akhir yang disimpan mewakili perangkat PERTAMA yang menerima
        // tiket. Batasan ini disengaja dan dicatat pada dokumentasi Fase 5;
        // in-app tetap menjadi sumber status utama bagi pengguna.
        $tiketPertama = null;

        foreach ($response['tickets'] as $index => $ticket) {
            $status = is_array($ticket) ? (string) ($ticket['status'] ?? '') : '';
            if ($status === 'ok') {
                $berhasil++;
                $permanenSemua = false;
                if ($tiketPertama === null && is_array($ticket) && isset($ticket['id']) && is_string($ticket['id'])) {
                    $tiketPertama = $ticket['id'];
                }
                if (isset($petaToken[$index])) {
                    $this->devices->touch($petaToken[$index]['id']);
                }
                continue;
            }
            $detail = is_array($ticket) && is_array($ticket['details'] ?? null) ? $ticket['details'] : [];
            $kodeTiket = (string) ($detail['error'] ?? 'TIKET_GAGAL');
            $kodeTerakhir = SafeError::code($kodeTiket);
            $pesanTerakhir = SafeError::message(
                is_array($ticket) ? (string) ($ticket['message'] ?? '') : '',
                'Expo menolak pesan untuk salah satu perangkat.'
            );

            if ($kodeTiket === ExpoPushClient::ERROR_TOKEN_MATI) {
                // Wajib berhenti memakai token ini (kontrak Expo).
                if (isset($petaToken[$index])) {
                    $this->devices->revokeInvalidToken($petaToken[$index]['hash']);
                }
                continue;
            }
            if ($kodeTiket === 'MessageRateExceeded') {
                $permanenSemua = false;
            }
            if (isset($petaToken[$index])) {
                $this->devices->noteFailure($petaToken[$index]['id']);
            }
        }

        if ($berhasil > 0) {
            $this->outbox->markSent($outboxId, $owner, $durasi, $tiketPertama);

            return 'terkirim';
        }

        $this->outbox->markFailed($outboxId, $owner, $kodeTerakhir, $pesanTerakhir, $permanenSemua, $durasi);

        return 'gagal';
    }

    /**
     * Rekonsiliasi RECEIPT AKHIR push (V2 Fase 5).
     *
     * Menutup temuan terbuka Fase 4: status `Sent` sebelumnya hanya berarti
     * "Expo menerima tiket". Metode ini menanyakan jawaban akhir FCM/APNs dan
     * memutakhirkan `receipt_status` menjadi `Terkirim`, `Gagal`, atau
     * `Tidak Tersedia`.
     *
     * Aturan yang dijaga:
     *
     *  - **Tidak pernah mengirim ulang.** Receipt `Gagal` TIDAK mengembalikan
     *    baris ke antrean kirim; pesan sudah benar-benar dikirim dan mengirim
     *    ulang berarti notifikasi ganda di perangkat penerima.
     *  - **Tidak pernah menghubungi penyedia saat push mati.** Sakelar dibaca
     *    ulang di awal, sama seperti `run()`.
     *  - **`DeviceNotRegistered` mencabut token.** Kontrak Expo mewajibkan
     *    berhenti memakai token tersebut, termasuk bila baru diketahui pada
     *    tahap receipt.
     *  - **Aman diulang.** Pembaruan bersifat idempoten dan hanya menyentuh
     *    baris yang masih `Menunggu`.
     *
     * @return array{dijalankan:bool, alasan:?string, diperiksa:int, terkirim:int,
     *               gagal:int, belum_tersedia:int, token_dicabut:int, catatan:array<int,string>}
     */
    public function reconcileReceipts(int $batch = 100, ?int $minimalUmurDetik = null): array
    {
        $hasil = [
            'dijalankan' => false,
            'alasan' => null,
            'diperiksa' => 0,
            'terkirim' => 0,
            'gagal' => 0,
            'belum_tersedia' => 0,
            'token_dicabut' => 0,
            'catatan' => [],
        ];

        if (!$this->expo instanceof PushReceiptClient) {
            $hasil['alasan'] = 'Klien push yang dipakai tidak mendukung pengambilan receipt akhir.';

            return $hasil;
        }

        $pengaturan = $this->settings->current();
        if ($pengaturan['push_enabled'] !== true) {
            // Berhenti SEBELUM permintaan apa pun ke penyedia.
            $hasil['alasan'] = 'Kanal Push sedang nonaktif. Tidak ada permintaan ke penyedia.';

            return $hasil;
        }

        $baris = $this->outbox->pendingReceipts(
            $batch,
            $minimalUmurDetik ?? OutboxRepository::RECEIPT_TUNGGU_DETIK
        );
        if ($baris === []) {
            $hasil['dijalankan'] = true;
            $hasil['alasan'] = 'Tidak ada tiket yang menunggu receipt akhir.';

            return $hasil;
        }

        $hasil['dijalankan'] = true;
        $petaTiket = [];
        foreach ($baris as $row) {
            $petaTiket[$row['tiket_id']][] = $row['id'];
        }

        $response = $this->expo->getReceipts(array_keys($petaTiket));
        if ($response['ok'] !== true) {
            // Kegagalan pengambilan BUKAN kegagalan pengiriman. Baris tetap
            // `Menunggu` supaya putaran berikutnya mencoba lagi.
            $hasil['alasan'] = 'Pengambilan receipt gagal: ' . SafeError::message($response['pesan'], 'Penyedia tidak dapat dihubungi.');

            return $hasil;
        }

        foreach ($petaTiket as $tiketId => $outboxIds) {
            $receipt = $response['receipts'][$tiketId] ?? null;
            foreach ($outboxIds as $outboxId) {
                $hasil['diperiksa']++;

                if ($receipt === null) {
                    // Penyedia belum menjawab tiket ini.
                    $this->outbox->noteReceiptPending($outboxId);
                    $hasil['belum_tersedia']++;
                    continue;
                }

                $status = (string) ($receipt['status'] ?? '');
                if ($status === 'ok') {
                    $this->outbox->markReceipt($outboxId, 'Terkirim', 'OK', null);
                    $hasil['terkirim']++;
                    continue;
                }

                $detail = is_array($receipt['details'] ?? null) ? $receipt['details'] : [];
                $kode = (string) ($detail['error'] ?? 'RECEIPT_GAGAL');
                $pesan = (string) ($receipt['message'] ?? 'Penyedia melaporkan pengantaran gagal.');
                $this->outbox->markReceipt($outboxId, 'Gagal', $kode, $pesan);
                $hasil['gagal']++;

                if ($kode === ExpoPushClient::ERROR_TOKEN_MATI) {
                    $hasil['token_dicabut'] += $this->cabutTokenMatiDariReceipt($outboxId);
                }
            }
        }

        return $hasil;
    }

    /**
     * Mencabut token yang dinyatakan mati oleh RECEIPT, hanya bila pemetaannya
     * tidak ambigu.
     *
     * Satu baris outbox menyimpan SATU id tiket, sedangkan satu penerima dapat
     * memiliki beberapa perangkat. Karena itu:
     *
     *  - bila penerima hanya punya SATU perangkat aktif, perangkat itulah yang
     *    dimaksud receipt dan token-nya dicabut;
     *  - bila penerima punya lebih dari satu perangkat aktif, TIDAK ADA yang
     *    dicabut. Mencabut semuanya akan mematikan perangkat yang sehat.
     *    Token mati tersebut tetap akan tercabut secara tepat pada pengiriman
     *    berikutnya, karena tiket Expo mengembalikan `DeviceNotRegistered`
     *    per pesan dan `kirimPush()` sudah menanganinya per perangkat.
     */
    private function cabutTokenMatiDariReceipt(int $outboxId): int
    {
        $userId = $this->outboxRecipient($outboxId);
        if ($userId === null || $userId < 1) {
            return 0;
        }
        $perangkat = $this->devices->activeTokensFor($userId);
        if (count($perangkat) !== 1) {
            return 0;
        }

        return $this->devices->revokeInvalidToken((string) $perangkat[0]['token_hash']) ? 1 : 0;
    }

    /**
     * Penerima satu baris outbox — dibaca terpisah agar `pendingReceipts()`
     * tetap ringan dan tidak membawa data penerima yang tidak selalu dipakai.
     */
    private function outboxRecipient(int $outboxId): ?int
    {
        $statement = $this->db->prepare('SELECT penerima_user_id FROM notifikasi_outbox WHERE id = ? LIMIT 1');
        if ($statement === false) {
            return null;
        }
        $statement->bind_param('i', $outboxId);
        $statement->execute();
        $row = $statement->get_result()?->fetch_assoc();
        $statement->close();

        return $row === null || $row === false ? null : (int) $row['penerima_user_id'];
    }

    /**
     * @param array<string, mixed> $row
     * @return 'terkirim'|'gagal'|'dilepas'
     */
    private function kirimWhatsapp(array $row, string $owner): string
    {
        $outboxId = (int) $row['id'];
        $userId = (int) $row['penerima_user_id'];
        $nomor = $this->nomorTujuan($userId);

        if ($nomor === null) {
            $this->outbox->markFailed(
                $outboxId,
                $owner,
                'TANPA_NOMOR',
                'Penerima tidak memiliki nomor WhatsApp pada master data.',
                true
            );

            return 'gagal';
        }

        $message = new WhatsAppMessage(
            $nomor,
            (string) $row['judul'],
            (string) $row['isi'],
            (string) $row['event_key']
        );

        $mulai = microtime(true);
        $result = $this->whatsapp->send($message);
        $durasi = (int) round((microtime(true) - $mulai) * 1000);

        if ($result->ok) {
            $this->outbox->markSent($outboxId, $owner, $durasi);

            return 'terkirim';
        }

        $this->outbox->markFailed($outboxId, $owner, $result->kode, $result->pesan, $result->permanen, $durasi);

        return 'gagal';
    }

    /**
     * Nomor tujuan diresolusi SAAT PENGIRIMAN, bukan disimpan pada outbox,
     * sehingga nomor tidak pernah menjadi salinan data pribadi di antrean dan
     * perubahan master data selalu terpakai.
     */
    private function nomorTujuan(int $userId): ?string
    {
        $statement = $this->db->prepare(
            "SELECT COALESCE(NULLIF(TRIM(u.phone), ''), NULLIF(TRIM(w.no_hp), ''), NULLIF(TRIM(p.no_hp), '')) AS nomor
               FROM users u
               LEFT JOIN wali w ON w.id = u.wali_id
               LEFT JOIN pengurus p ON p.id = u.pengurus_id
              WHERE u.id = ? AND u.is_active = 1
              LIMIT 1"
        );
        if ($statement === false) {
            return null;
        }
        $statement->bind_param('i', $userId);
        $statement->execute();
        $row = $statement->get_result()?->fetch_assoc();
        $statement->close();

        $nomor = is_array($row) ? trim((string) ($row['nomor'] ?? '')) : '';

        return $nomor === '' ? null : $nomor;
    }
}
