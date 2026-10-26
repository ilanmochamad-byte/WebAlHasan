<?php

declare(strict_types=1);

namespace App\Bsa;

use App\Notification\DeviceRepository;
use App\Notification\NotificationChannel;
use App\Notification\NotificationRepository;
use App\Notification\RecipientResolver;
use App\Notification\SettingsRepository;
use mysqli;

/**
 * Pemberitahuan keuangan santri kepada orang tua (PRD V6 §3.6, Fase 4).
 *
 * Satu peristiwa (tagihan terbit, pembayaran sah/dibatalkan, transaksi
 * tabungan sah) → tepat satu baris InApp per wali berakun aktif dengan relasi
 * `santri_wali` aktif, dan paling banyak satu baris Push (hanya bila sakelar
 * Push menyala dan wali mempunyai perangkat aktif). Baris ditulis ke
 * `notifikasi_outbox` di DALAM transaksi mutasi yang sama — pola PRD V5
 * Fase 3 — sehingga replay idempotensi tidak mengantre ulang dan kegagalan
 * antrean membatalkan mutasi. WhatsApp tidak pernah diantrekan.
 *
 * Judul/isi generik: tanpa nominal, nama santri, nomor kuitansi, referensi
 * transfer, atau nomor rekening. Data hanya satu penunjuk sumber daya;
 * aplikasi/website wajib meminta endpoint BSA yang memeriksa ulang hak.
 * Worker Push memeriksa ulang keadaan dan relasi lewat `pushMasihBerhak()`
 * (statis: dispatcher hanya memegang koneksi basis data).
 */
final class BsaNotifikasi
{
    public const EVENT_TAGIHAN = 'v6_bsa_tagihan_terbit';
    public const EVENT_PEMBAYARAN = 'v6_bsa_pembayaran_sah';
    public const EVENT_PEMBAYARAN_BATAL = 'v6_bsa_pembayaran_batal';
    public const EVENT_TABUNGAN = 'v6_bsa_tabungan';
    public const EVENTS = [self::EVENT_TAGIHAN, self::EVENT_PEMBAYARAN, self::EVENT_PEMBAYARAN_BATAL, self::EVENT_TABUNGAN];

    /** Tipe tautan dan kunci penunjuknya (satu-satunya data yang dikirim). */
    public const TAUTAN = [
        self::EVENT_TAGIHAN => ['bsa_tagihan', 'tagihan_id'],
        self::EVENT_PEMBAYARAN => ['bsa_pembayaran', 'pembayaran_id'],
        self::EVENT_PEMBAYARAN_BATAL => ['bsa_pembayaran', 'pembayaran_id'],
        self::EVENT_TABUNGAN => ['bsa_tabungan', 'transaksi_id'],
    ];

    public function __construct(
        private mysqli $db,
        private NotificationRepository $notifikasi,
        private SettingsRepository $settings,
        private DeviceRepository $devices
    ) {
    }

    /**
     * Katalog teks — satu-satunya tempat teks dirakit, dipakai saat antre dan
     * ulang oleh worker Push. Generik, tanpa data pribadi maupun nominal.
     *
     * @return array{judul:string, isi:string}
     */
    public static function teks(string $event): array
    {
        return match ($event) {
            self::EVENT_TAGIHAN => ['judul' => 'Tagihan bulanan', 'isi' => 'Ada tagihan bulanan baru untuk anak Anda. Masuk untuk melihatnya.'],
            self::EVENT_PEMBAYARAN => ['judul' => 'Pembayaran tercatat', 'isi' => 'Ada pembayaran tagihan yang tercatat untuk anak Anda. Masuk untuk melihatnya.'],
            self::EVENT_PEMBAYARAN_BATAL => ['judul' => 'Pembaruan pembayaran', 'isi' => 'Ada pembaruan pembayaran tagihan untuk anak Anda. Masuk untuk melihatnya.'],
            default => ['judul' => 'Transaksi tabungan', 'isi' => 'Ada transaksi tabungan yang tercatat untuk anak Anda. Masuk untuk melihatnya.'],
        };
    }

    public static function eventKey(string $event, int $id): string
    {
        return match ($event) {
            self::EVENT_TAGIHAN => 'v6:bsa:tagihan:' . $id . ':terbit',
            self::EVENT_PEMBAYARAN => 'v6:bsa:pembayaran:' . $id . ':sah',
            self::EVENT_PEMBAYARAN_BATAL => 'v6:bsa:pembayaran:' . $id . ':batal',
            default => 'v6:bsa:tabungan:' . $id,
        };
    }

    /** @return array{tipe:string}&array<string, int> */
    public static function data(string $event, int $id): array
    {
        [$tipe, $kunci] = self::TAUTAN[$event];

        return ['tipe' => $tipe, $kunci => $id];
    }

    /**
     * Mengantre satu peristiwa untuk seluruh wali aktif santri. Dipanggil di
     * dalam transaksi mutasi; baris yang tidak dapat ditulis (dan belum ada)
     * melempar 503 sehingga seluruh mutasi dibatalkan.
     *
     * @return array{in_app:int, push:int}
     */
    public function antrekan(int $santriId, string $event, int $id): array
    {
        $hasil = ['in_app' => 0, 'push' => 0];
        if (!isset(self::TAUTAN[$event]) || $id < 1) {
            return $hasil;
        }
        $penerima = (new RecipientResolver($this->db))->waliSantri($santriId);
        if ($penerima === []) {
            return $hasil;
        }
        $berperangkat = $this->settings->current()['push_enabled'] === true ? $this->devices->usersWithActiveDevice($penerima) : [];
        $teks = self::teks($event);
        $key = self::eventKey($event, $id);
        $data = (string) json_encode(self::data($event, $id), JSON_UNESCAPED_SLASHES);
        foreach ($penerima as $userId) {
            $kanal = [NotificationChannel::IN_APP];
            if (in_array($userId, $berperangkat, true)) {
                $kanal[] = NotificationChannel::PUSH;
            }
            foreach ($kanal as $k) {
                $baris = $this->notifikasi->enqueue([
                    'event_key' => $key, 'event_type' => $event, 'kanal' => $k, 'penerima_user_id' => $userId,
                    'pengajuan_id' => null, 'judul' => $teks['judul'], 'isi' => $teks['isi'], 'data_json' => $data,
                ]);
                if ($baris === null && !$this->notifikasi->exists($key, $k, $userId)) {
                    throw new BsaException('Pemberitahuan tidak dapat diantrekan. Tidak ada data yang diubah; coba lagi.', 503);
                }
                $hasil[$k === NotificationChannel::IN_APP ? 'in_app' : 'push'] += $baris === null ? 0 : 1;
            }
        }

        return $hasil;
    }

    /**
     * Pemeriksaan ulang worker Push tepat sebelum provider: sumber daya masih
     * dalam keadaan peristiwa, santri aktif, dan penerima masih wali aktif.
     * Mengembalikan data penunjuk yang boleh dikirim, atau null bila tidak
     * berhak lagi. Isi baris outbox tidak pernah dipercaya.
     *
     * @param array<string, mixed> $row baris outbox (event_type, event_key, data_json, penerima_user_id)
     * @return array<string, int|string>|null
     */
    public static function pushMasihBerhak(mysqli $db, array $row): ?array
    {
        $event = (string) ($row['event_type'] ?? '');
        if (!isset(self::TAUTAN[$event])) {
            return null;
        }
        [, $kunci] = self::TAUTAN[$event];
        $pointer = json_decode((string) ($row['data_json'] ?? ''), true);
        $id = (int) (is_array($pointer) ? ($pointer[$kunci] ?? 0) : 0);
        if ($id < 1 || (string) ($row['event_key'] ?? '') !== self::eventKey($event, $id)) {
            return null;
        }
        [$dari, $status] = match ($event) {
            self::EVENT_TAGIHAN => ['bsa_tagihan x JOIN bsa_tagihan t ON t.id = x.id', 'Terbit'],
            self::EVENT_PEMBAYARAN => ['bsa_pembayaran x JOIN bsa_tagihan t ON t.id = x.tagihan_id', 'Sah'],
            self::EVENT_PEMBAYARAN_BATAL => ['bsa_pembayaran x JOIN bsa_tagihan t ON t.id = x.tagihan_id', 'Dibatalkan'],
            default => ['bsa_tabungan_transaksi x JOIN bsa_tabungan_transaksi t ON t.id = x.id', 'Sah'],
        };
        $stmt = $db->prepare('SELECT t.santri_id, x.status FROM ' . $dari
            . ' JOIN santri s ON s.id = t.santri_id AND s.is_active = 1 AND s.archived_at IS NULL WHERE x.id = ?');
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $sumber = $stmt->get_result()?->fetch_assoc();
        $stmt->close();
        if (!is_array($sumber) || (string) $sumber['status'] !== $status
            || !in_array((int) ($row['penerima_user_id'] ?? 0), (new RecipientResolver($db))->waliSantri((int) $sumber['santri_id']), true)) {
            return null;
        }

        return self::data($event, $id);
    }
}
