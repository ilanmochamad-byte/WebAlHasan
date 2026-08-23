<?php

declare(strict_types=1);

namespace App\Notification;

use mysqli;
use Throwable;

/**
 * Pembuat notifikasi untuk peristiwa perizinan (produsen outbox).
 *
 * Empat aturan yang ditegakkan kelas ini:
 *
 *  1. **In-app selalu dibuat.** Kanal in-app tidak memiliki sakelar mati dan
 *     tidak memanggil penyedia eksternal, sehingga status selalu terbaca di
 *     aplikasi/website walaupun push dan WhatsApp gagal total (PRD 5.7).
 *
 *  2. **Tepat satu per penerima per peristiwa.** Kunci peristiwa deterministik
 *     dari `NotificationEvent::key()` plus kunci unik basis data membuat retry,
 *     replay idempotensi, atau pemanggilan ganda tidak pernah menghasilkan
 *     notifikasi kedua.
 *
 *  3. **Enqueue ikut transaksi bisnis, pengiriman tidak.** Pemanggil menjalankan
 *     `emit()` di dalam transaksi pengajuan/keputusan. Yang ditulis hanyalah
 *     baris lokal; tidak ada satu pun panggilan jaringan di jalur ini, sehingga
 *     transaksi tidak pernah menunggu penyedia eksternal.
 *
 *  4. **Tidak pernah menggagalkan transaksi bisnis.** `emit()` menangkap semua
 *     galatnya sendiri dan mengembalikan ringkasan. Kegagalan notifikasi dicatat
 *     ke error log server, bukan dilempar ke pemanggil — pengajuan dan keputusan
 *     yang sudah sah tidak boleh batal karena masalah kanal (PRD 5.7).
 */
final class NotificationService
{
    public function __construct(
        private mysqli $db,
        private NotificationRepository $repository,
        private RecipientResolver $recipients,
        private SettingsRepository $settings,
        private DeviceRepository $devices
    ) {
    }

    /**
     * Membuat notifikasi untuk satu peristiwa perizinan.
     *
     * @param array<string, mixed> $pengajuan baris `izin_pengajuan`
     * @param array<string, mixed> $opsi      version, aktor_user_id,
     *                                        murobi_sebelumnya_guru_id
     * @return array{event_key:string, penerima:array<int,int>, dibuat:array<string,int>, galat:?string}
     */
    public function emit(string $event, array $pengajuan, array $opsi = []): array
    {
        $pengajuanId = (int) ($pengajuan['id'] ?? 0);
        $version = ($opsi['version'] ?? null) === null ? null : (int) $opsi['version'];
        $eventKey = NotificationEvent::key($event, $pengajuanId, $version);
        $hasil = [
            'event_key' => $eventKey,
            'penerima' => [],
            'dibuat' => [
                NotificationChannel::IN_APP => 0,
                NotificationChannel::PUSH => 0,
                NotificationChannel::WHATSAPP => 0,
            ],
            'galat' => null,
        ];

        try {
            if (!NotificationEvent::valid($event)) {
                throw NotificationException::invalid('Peristiwa notifikasi tidak dikenal: ' . $event);
            }

            $penerima = $this->recipients->forEvent($event, $pengajuan, $opsi);
            $hasil['penerima'] = $penerima;
            if ($penerima === []) {
                return $hasil;
            }

            $context = $this->context($pengajuan);
            $data = json_encode(
                NotificationEvent::data($event, $context),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            $data = $data === false ? null : $data;

            $pengaturan = $this->settings->current();
            $pushAktif = $pengaturan['push_enabled'] === true;
            $waAktif = $pengaturan['whatsapp_enabled'] === true
                && $pengaturan['whatsapp_check_status'] === 'Lulus';

            // Kanal eksternal hanya diantrekan untuk penerima yang benar-benar
            // dapat dijangkau. Ini menjaga daftar kegagalan admin tetap berisi
            // masalah nyata, bukan penerima yang memang belum memasang aplikasi.
            $berperangkat = $pushAktif ? $this->devices->usersWithActiveDevice($penerima) : [];
            $bernomor = $waAktif ? $this->usersWithPhone($penerima) : [];

            foreach ($penerima as $userId) {
                // 1. In-app — selalu, tanpa syarat kanal apa pun.
                $hasil['dibuat'][NotificationChannel::IN_APP] += $this->enqueue(
                    $event,
                    NotificationChannel::IN_APP,
                    $eventKey,
                    $userId,
                    $pengajuanId,
                    $context,
                    $data
                );

                // 2. Push — hanya bila sakelar menyala DAN ada perangkat aktif.
                if ($pushAktif && in_array($userId, $berperangkat, true)) {
                    $hasil['dibuat'][NotificationChannel::PUSH] += $this->enqueue(
                        $event,
                        NotificationChannel::PUSH,
                        $eventKey,
                        $userId,
                        $pengajuanId,
                        $context,
                        $data
                    );
                }

                // 3. WhatsApp — hanya bila sakelar menyala, pemeriksaan lulus,
                //    dan penerima punya nomor. Saat mati/tidak siap tidak ada
                //    baris yang dibuat dan tidak ada request ke penyedia.
                if ($waAktif && in_array($userId, $bernomor, true)) {
                    $hasil['dibuat'][NotificationChannel::WHATSAPP] += $this->enqueue(
                        $event,
                        NotificationChannel::WHATSAPP,
                        $eventKey,
                        $userId,
                        $pengajuanId,
                        $context,
                        $data
                    );
                }
            }
        } catch (Throwable $exception) {
            // Notifikasi tidak pernah membatalkan pengajuan atau keputusan.
            $hasil['galat'] = SafeError::fromThrowable($exception, 'Notifikasi gagal dibuat.');
            error_log('Notifikasi Fase 4 gagal untuk ' . $event . ': ' . $hasil['galat']);
        }

        return $hasil;
    }

    /**
     * Notifikasi sistem (pesan uji admin) kepada satu penerima.
     *
     * @return array{event_key:string, dibuat:array<string,int>, galat:?string}
     */
    public function emitTest(string $channel, int $userId, string $nonce): array
    {
        $eventKey = substr('sistem:pesan_uji:' . $channel . ':' . $nonce, 0, 120);
        $hasil = ['event_key' => $eventKey, 'dibuat' => [$channel => 0], 'galat' => null];

        try {
            $context = ['pengajuan_id' => 0];
            $data = json_encode(
                NotificationEvent::data(NotificationEvent::PESAN_UJI, $context),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            $hasil['dibuat'][$channel] = $this->enqueue(
                NotificationEvent::PESAN_UJI,
                $channel,
                $eventKey,
                $userId,
                null,
                $context,
                $data === false ? null : $data
            );
        } catch (Throwable $exception) {
            $hasil['galat'] = SafeError::fromThrowable($exception, 'Pesan uji gagal dibuat.');
        }

        return $hasil;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function enqueue(
        string $event,
        string $channel,
        string $eventKey,
        int $userId,
        ?int $pengajuanId,
        array $context,
        ?string $data
    ): int {
        $isi = NotificationEvent::render($event, $channel, $context);

        $id = $this->repository->enqueue([
            'event_key' => $eventKey,
            'event_type' => $event,
            'kanal' => $channel,
            'penerima_user_id' => $userId,
            'pengajuan_id' => $pengajuanId,
            'judul' => $isi['judul'],
            'isi' => $isi['isi'],
            'data_json' => $data,
        ]);

        return $id === null ? 0 : 1;
    }

    /**
     * Konteks isi notifikasi. HANYA nilai tidak sensitif yang diambil: nama
     * santri dan rentang tanggal. Alasan izin, catatan pengurus, dan alasan
     * keputusan sengaja TIDAK dibaca di sini agar tidak mungkin bocor ke isi
     * notifikasi kanal mana pun.
     *
     * @param array<string, mixed> $pengajuan
     * @return array<string, mixed>
     */
    private function context(array $pengajuan): array
    {
        $pengajuanId = (int) ($pengajuan['id'] ?? 0);
        $santriNama = (string) ($pengajuan['santri_nama'] ?? '');
        $tglIzin = (string) ($pengajuan['tgl_izin'] ?? '');
        $tglKembali = (string) ($pengajuan['tgl_kembali'] ?? '');

        if ($santriNama === '' && $pengajuanId > 0) {
            $statement = $this->db->prepare(
                'SELECT s.nama_santri AS santri_nama, p.tgl_izin, p.tgl_kembali
                   FROM izin_pengajuan p
                   LEFT JOIN santri s ON s.id = p.santri_id
                  WHERE p.id = ?
                  LIMIT 1'
            );
            if ($statement !== false) {
                $statement->bind_param('i', $pengajuanId);
                $statement->execute();
                $row = $statement->get_result()?->fetch_assoc();
                $statement->close();
                if (is_array($row)) {
                    $santriNama = (string) ($row['santri_nama'] ?? '');
                    $tglIzin = $tglIzin !== '' ? $tglIzin : (string) ($row['tgl_izin'] ?? '');
                    $tglKembali = $tglKembali !== '' ? $tglKembali : (string) ($row['tgl_kembali'] ?? '');
                }
            }
        }

        return [
            'pengajuan_id' => $pengajuanId,
            'santri_nama' => $santriNama,
            'tgl_izin' => $tglIzin,
            'tgl_kembali' => $tglKembali,
            // Hanya status akhir (Disetujui/Ditolak) — bukan alasan keputusan.
            'hasil' => (string) ($pengajuan['hasil'] ?? ''),
        ];
    }

    /**
     * Pengguna yang memiliki nomor telepon terjangkau WhatsApp.
     *
     * Nomor diambil dari akun, lalu dari master `wali`/`pengurus` yang terkait.
     * Nomornya sendiri TIDAK dikembalikan ke pemanggil dan tidak pernah masuk
     * ke baris outbox; ia baru diresolusi ulang saat pengiriman.
     *
     * @param array<int, int> $userIds
     * @return array<int, int>
     */
    private function usersWithPhone(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $statement = $this->db->prepare(
            'SELECT u.id
               FROM users u
               LEFT JOIN wali w ON w.id = u.wali_id
               LEFT JOIN pengurus p ON p.id = u.pengurus_id
              WHERE u.id IN (' . $placeholders . ')
                AND u.is_active = 1
                AND COALESCE(NULLIF(TRIM(u.phone), \'\'), NULLIF(TRIM(w.no_hp), \'\'), NULLIF(TRIM(p.no_hp), \'\')) IS NOT NULL'
        );
        if ($statement === false) {
            return [];
        }
        $statement->bind_param(str_repeat('i', count($userIds)), ...$userIds);
        $statement->execute();
        $result = $statement->get_result();
        $ids = [];
        while ($result !== false && ($row = $result->fetch_assoc()) !== null) {
            $ids[] = (int) $row['id'];
        }
        $statement->close();

        return $ids;
    }
}
