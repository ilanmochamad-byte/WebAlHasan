<?php

declare(strict_types=1);

namespace App\Notification;

use App\Audit\AuditLogger;
use App\Auth\Capabilities;
use App\Notification\WhatsApp\ProviderFactory;
use App\Notification\WhatsApp\WhatsAppProvider;
use Throwable;

/**
 * Panel kanal notifikasi untuk admin.
 *
 * Tanggung jawab: status kanal, pemeriksaan konfigurasi, sakelar on/off,
 * pengiriman pesan uji, daftar kegagalan, dan percobaan ulang yang aman.
 *
 * Empat aturan yang tidak dapat ditawar:
 *
 *  1. **Hanya admin.** Setiap method memeriksa `Capabilities::ADMIN` dari akun
 *     yang sedang masuk, bukan dari parameter request.
 *  2. **WhatsApp tidak dapat dinyalakan tanpa pemeriksaan lulus.** Dijaga
 *     lapisan ini, klausa WHERE repositori, dan CHECK constraint basis data.
 *  3. **In-app tidak dapat dimatikan.** Ia adalah sumber status utama.
 *  4. **Tidak ada credential yang keluar.** Status hanya menyebut NAMA
 *     environment yang kurang, tidak pernah nilainya; seluruh pesan penyedia
 *     melewati `SafeError`.
 */
final class NotificationAdminService
{
    public function __construct(
        private Capabilities $capabilities,
        private SettingsRepository $settings,
        private NotificationRepository $notifications,
        private OutboxRepository $outbox,
        private DeviceRepository $devices,
        private PushTokenProtector $protector,
        private WhatsAppProvider $whatsapp,
        private NotificationService $notifier,
        private NotificationDispatcher $dispatcher,
        private AuditLogger $audit
    ) {
    }

    /**
     * Ringkasan status seluruh kanal.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function status(array $user): array
    {
        $this->requireAdmin($user);
        $pengaturan = $this->settings->current();
        $ringkasan = $this->notifications->summaryByChannel();
        $perangkat = $this->devices->counters();
        $pushSiap = $this->protector->ready();
        $waReadiness = $this->whatsapp->readiness();

        return [
            'kanal' => [
                [
                    'kanal' => NotificationChannel::IN_APP,
                    'label' => NotificationChannel::label(NotificationChannel::IN_APP),
                    'aktif' => true,
                    'dapat_dimatikan' => false,
                    'keterangan' => 'Sumber status utama. Selalu aktif dan tidak bergantung pada penyedia eksternal.',
                    'kesiapan' => ['siap' => true, 'pesan' => 'Tidak memerlukan konfigurasi eksternal.', 'detail' => []],
                    'antrean' => $ringkasan[NotificationChannel::IN_APP] ?? [],
                ],
                [
                    'kanal' => NotificationChannel::PUSH,
                    'label' => NotificationChannel::label(NotificationChannel::PUSH),
                    'aktif' => $pengaturan['push_enabled'],
                    'dapat_dimatikan' => true,
                    'keterangan' => 'Memakai expo-notifications. Memerlukan development build dan perangkat nyata.',
                    'kesiapan' => [
                        'siap' => $pushSiap,
                        'pesan' => $pushSiap
                            ? 'Kunci perlindungan token push tersedia di environment server.'
                            : (string) $this->protector->reason(),
                        'detail' => $pushSiap ? [] : ['Isi PUSH_TOKEN_KEY (32 byte acak base64) pada environment server.'],
                    ],
                    'pemeriksaan' => [
                        'status' => $pengaturan['push_check_status'],
                        'pesan' => $pengaturan['push_check_pesan'],
                        'pada' => $pengaturan['push_check_pada'],
                    ],
                    'perangkat' => $perangkat,
                    'antrean' => $ringkasan[NotificationChannel::PUSH] ?? [],
                ],
                [
                    'kanal' => NotificationChannel::WHATSAPP,
                    'label' => NotificationChannel::label(NotificationChannel::WHATSAPP),
                    'aktif' => $pengaturan['whatsapp_enabled'],
                    'dapat_dimatikan' => true,
                    'keterangan' => 'Opsional, default mati. Hanya dapat dinyalakan setelah pemeriksaan konfigurasi lulus.',
                    'penyedia' => [
                        'nama' => $this->whatsapp->name(),
                        'mengirim_nyata' => $this->whatsapp->mengirimNyata(),
                        'dikenal' => ProviderFactory::dikenal(),
                        // Hanya NAMA environment, tidak pernah nilainya.
                        // Ketika belum ada penyedia yang dipilih, yang
                        // ditampilkan adalah kebutuhan adapter HTTP generik,
                        // agar admin tahu apa yang perlu disiapkan.
                        'environment_dibutuhkan' => $this->environmentPenyedia(),
                    ],
                    'kesiapan' => $waReadiness,
                    'pemeriksaan' => [
                        'status' => $pengaturan['whatsapp_check_status'],
                        'pesan' => $pengaturan['whatsapp_check_pesan'],
                        'pada' => $pengaturan['whatsapp_check_pada'],
                        'oleh_user_id' => $pengaturan['whatsapp_check_oleh_user_id'],
                    ],
                    'antrean' => $ringkasan[NotificationChannel::WHATSAPP] ?? [],
                ],
            ],
            'diperbarui_pada' => $pengaturan['updated_at'],
        ];
    }

    /**
     * Menjalankan pemeriksaan konfigurasi satu kanal.
     *
     * Push: memeriksa kesiapan kunci dan ekstensi — TIDAK mengirim pesan.
     * WhatsApp: memanggil `verify()` penyedia — TIDAK mengirim pesan ke
     * penerima nyata. Hasilnya menentukan boleh/tidaknya sakelar dinyalakan.
     *
     * @param array<string, mixed> $user
     * @param array{ip:?string, user_agent:?string} $meta
     * @return array<string, mixed>
     */
    public function periksaKonfigurasi(array $user, string $kanal, array $meta): array
    {
        $this->requireAdmin($user);
        $userId = (int) $user['id'];

        if ($kanal === NotificationChannel::IN_APP) {
            $this->settings->audit(
                'pemeriksaan_konfigurasi',
                NotificationChannel::IN_APP,
                null,
                null,
                'Lulus',
                'Kanal in-app tidak memerlukan konfigurasi eksternal.',
                $userId,
                $meta['ip'] ?? null,
                $meta['user_agent'] ?? null
            );

            return [
                'kanal' => $kanal,
                'status' => 'Lulus',
                'pesan' => 'Kanal in-app selalu siap dan tidak memerlukan konfigurasi eksternal.',
                'detail' => [],
                'mengirim_nyata' => true,
            ];
        }

        if ($kanal === NotificationChannel::PUSH) {
            $siap = $this->protector->ready();
            $pesan = $siap
                ? 'Kunci perlindungan token push tersedia. Pengiriman nyata tetap memerlukan development build dan perangkat nyata.'
                : (string) $this->protector->reason();
            $status = $siap ? 'Lulus' : 'Gagal';
            $this->settings->recordPushCheck($status, $pesan, $userId);
            $this->settings->audit(
                'pemeriksaan_konfigurasi',
                NotificationChannel::PUSH,
                null,
                null,
                $status,
                $pesan,
                $userId,
                $meta['ip'] ?? null,
                $meta['user_agent'] ?? null
            );
            $this->audit->log('notifikasi.pemeriksaan_konfigurasi', 'pengaturan_notifikasi', null, null, [
                'kanal' => NotificationChannel::PUSH,
                'status' => $status,
            ], $userId);

            return [
                'kanal' => $kanal,
                'status' => $status,
                'pesan' => $pesan,
                'detail' => $siap ? [] : ['Isi PUSH_TOKEN_KEY pada environment server, lalu ulangi pemeriksaan.'],
                'mengirim_nyata' => true,
            ];
        }

        if ($kanal !== NotificationChannel::WHATSAPP) {
            throw NotificationException::invalid('Kanal tidak dikenal.');
        }

        $readiness = $this->whatsapp->readiness();
        if ($readiness['siap'] !== true) {
            $pesan = $readiness['pesan'] . ($readiness['detail'] === [] ? '' : ' ' . implode(' ', $readiness['detail']));
            $this->settings->recordWhatsappCheck('Gagal', $pesan, $this->whatsapp->name(), $userId);
            $this->auditWhatsappCheck('Gagal', $pesan, $userId, $meta);

            return [
                'kanal' => $kanal,
                'status' => 'Gagal',
                'pesan' => $readiness['pesan'],
                'detail' => $readiness['detail'],
                'mengirim_nyata' => $this->whatsapp->mengirimNyata(),
            ];
        }

        try {
            $result = $this->whatsapp->verify();
        } catch (Throwable $exception) {
            $pesan = SafeError::fromThrowable($exception, 'Pemeriksaan penyedia WhatsApp gagal.');
            $this->settings->recordWhatsappCheck('Gagal', $pesan, $this->whatsapp->name(), $userId);
            $this->auditWhatsappCheck('Gagal', $pesan, $userId, $meta);

            return [
                'kanal' => $kanal,
                'status' => 'Gagal',
                'pesan' => $pesan,
                'detail' => [],
                'mengirim_nyata' => $this->whatsapp->mengirimNyata(),
            ];
        }

        $status = $result->ok ? 'Lulus' : 'Gagal';
        $this->settings->recordWhatsappCheck($status, $result->pesan, $this->whatsapp->name(), $userId);
        $this->auditWhatsappCheck($status, $result->pesan, $userId, $meta);

        return [
            'kanal' => $kanal,
            'status' => $status,
            'pesan' => $result->pesan,
            'detail' => $this->whatsapp->mengirimNyata()
                ? []
                : ['Penyedia aktif adalah adapter uji. Hasil ini BUKAN bukti pengiriman WhatsApp nyata.'],
            'mengirim_nyata' => $this->whatsapp->mengirimNyata(),
        ];
    }

    /**
     * Menyalakan/mematikan satu kanal.
     *
     * @param array<string, mixed> $user
     * @param array{ip:?string, user_agent:?string} $meta
     * @return array<string, mixed>
     */
    public function ubahSakelar(array $user, string $kanal, bool $aktif, array $meta): array
    {
        $this->requireAdmin($user);
        $userId = (int) $user['id'];
        $sebelum = $this->settings->current();

        if ($kanal === NotificationChannel::IN_APP) {
            throw NotificationException::invalid(
                'Notifikasi dalam aplikasi adalah sumber status utama dan tidak dapat dimatikan.'
            );
        }

        if ($kanal === NotificationChannel::PUSH) {
            if ($aktif && !$this->protector->ready()) {
                throw NotificationException::conflict(
                    'Push tidak dapat dinyalakan: ' . (string) $this->protector->reason()
                );
            }
            $this->settings->setPushEnabled($aktif, $userId);
            $dibatalkan = $aktif ? 0 : $this->outbox->cancelQueued(
                NotificationChannel::PUSH,
                'Push dinonaktifkan admin sebelum baris ini terkirim.'
            );
            $this->catatSakelar(
                NotificationChannel::PUSH,
                $sebelum['push_enabled'],
                $aktif,
                $userId,
                $meta,
                $dibatalkan
            );

            return [
                'kanal' => $kanal,
                'aktif' => $aktif,
                'antrean_dibatalkan' => $dibatalkan,
                'pesan' => $aktif
                    ? 'Push dinyalakan. Notifikasi baru akan diantrekan untuk perangkat aktif.'
                    : 'Push dimatikan. Tidak ada enqueue push baru; notifikasi dalam aplikasi tetap berjalan.',
            ];
        }

        if ($kanal !== NotificationChannel::WHATSAPP) {
            throw NotificationException::invalid('Kanal tidak dikenal.');
        }

        if (!$aktif) {
            $this->settings->setWhatsappEnabled(false, $userId);
            $dibatalkan = $this->outbox->cancelQueued(
                NotificationChannel::WHATSAPP,
                'WhatsApp dinonaktifkan admin sebelum baris ini terkirim.'
            );
            $this->catatSakelar(
                NotificationChannel::WHATSAPP,
                $sebelum['whatsapp_enabled'],
                false,
                $userId,
                $meta,
                $dibatalkan
            );

            return [
                'kanal' => $kanal,
                'aktif' => false,
                'antrean_dibatalkan' => $dibatalkan,
                'pesan' => 'WhatsApp dimatikan. Tidak ada permintaan ke penyedia dan transaksi perizinan tidak terpengaruh.',
            ];
        }

        // Menyalakan WhatsApp: pemeriksaan konfigurasi WAJIB berstatus Lulus.
        if ($sebelum['whatsapp_check_status'] !== 'Lulus') {
            throw NotificationException::conflict(
                'WhatsApp tidak dapat dinyalakan sebelum pemeriksaan konfigurasi berstatus Lulus. Jalankan pemeriksaan lebih dahulu.'
            );
        }
        $berhasil = $this->settings->setWhatsappEnabled(true, $userId);
        if (!$berhasil) {
            throw NotificationException::conflict(
                'WhatsApp tidak dapat dinyalakan: basis data menolak karena pemeriksaan konfigurasi belum lulus.'
            );
        }
        $this->catatSakelar(NotificationChannel::WHATSAPP, $sebelum['whatsapp_enabled'], true, $userId, $meta, 0);

        return [
            'kanal' => $kanal,
            'aktif' => true,
            'antrean_dibatalkan' => 0,
            'pesan' => $this->whatsapp->mengirimNyata()
                ? 'WhatsApp dinyalakan.'
                : 'WhatsApp dinyalakan dengan ADAPTER UJI. Tidak ada pesan nyata yang akan terkirim.',
        ];
    }

    /**
     * Mengirim pesan uji kepada admin yang sedang masuk (bukan kepada wali atau
     * pengurus). Pesan uji tidak pernah memuat data santri.
     *
     * @param array<string, mixed> $user
     * @param array{ip:?string, user_agent:?string} $meta
     * @return array<string, mixed>
     */
    public function kirimPesanUji(array $user, string $kanal, array $meta): array
    {
        $this->requireAdmin($user);
        $userId = (int) $user['id'];
        if (!NotificationChannel::valid($kanal)) {
            throw NotificationException::invalid('Kanal tidak dikenal.');
        }

        $pengaturan = $this->settings->current();
        if ($kanal === NotificationChannel::PUSH && $pengaturan['push_enabled'] !== true) {
            throw NotificationException::conflict('Nyalakan kanal push terlebih dahulu sebelum mengirim pesan uji.');
        }
        if ($kanal === NotificationChannel::WHATSAPP && $pengaturan['whatsapp_enabled'] !== true) {
            throw NotificationException::conflict('Nyalakan kanal WhatsApp terlebih dahulu sebelum mengirim pesan uji.');
        }

        $nonce = bin2hex(random_bytes(8));
        $enqueue = $this->notifier->emitTest($kanal, $userId, $nonce);
        $dibuat = (int) ($enqueue['dibuat'][$kanal] ?? 0);

        $hasilWorker = null;
        if (in_array($kanal, NotificationChannel::EKSTERNAL, true) && $dibuat > 0) {
            // Jalankan satu putaran kecil agar admin melihat hasilnya seketika.
            $hasilWorker = $this->dispatcher->run($kanal, 5);
        }

        $catatan = [];
        if ($kanal === NotificationChannel::WHATSAPP && !$this->whatsapp->mengirimNyata()) {
            $catatan[] = 'Penyedia aktif adalah adapter uji: tidak ada pesan WhatsApp nyata yang dikirim.';
        }
        if ($kanal === NotificationChannel::PUSH) {
            $catatan[] = 'Push hanya tiba pada perangkat nyata dengan development build; simulator dan Expo Go tidak menerima push jarak jauh.';
        }

        $this->settings->audit(
            'pesan_uji',
            $kanal,
            null,
            null,
            $dibuat > 0 ? 'Diantrekan' : 'Duplikat',
            $enqueue['galat'] ?? ('Pesan uji untuk admin; hasil worker: ' . json_encode($hasilWorker === null ? [] : [
                'terkirim' => $hasilWorker['terkirim'],
                'gagal' => $hasilWorker['gagal'],
                'alasan' => $hasilWorker['alasan'],
            ], JSON_UNESCAPED_UNICODE)),
            $userId,
            $meta['ip'] ?? null,
            $meta['user_agent'] ?? null
        );
        $this->audit->log('notifikasi.pesan_uji', 'pengaturan_notifikasi', null, null, [
            'kanal' => $kanal,
            'diantrekan' => $dibuat > 0,
        ], $userId);

        return [
            'kanal' => $kanal,
            'diantrekan' => $dibuat > 0,
            'worker' => $hasilWorker,
            'catatan' => $catatan,
            'pesan' => $kanal === NotificationChannel::IN_APP
                ? 'Pesan uji dibuat. Buka pusat notifikasi Anda untuk melihatnya.'
                : 'Pesan uji diantrekan dan satu putaran pengiriman dijalankan.',
        ];
    }

    /**
     * Daftar pengiriman gagal beserta riwayat percobaan.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function kegagalan(array $user, array $query): array
    {
        $this->requireAdmin($user);
        $kanal = (string) ($query['kanal'] ?? '');
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($query['per_page'] ?? 20)));
        $hanyaPermanen = ((string) ($query['permanen'] ?? '')) === '1';

        $result = $this->notifications->failures($kanal, $page, $perPage, $hanyaPermanen);
        $total = (int) $result['total'];

        return [
            'items' => array_map(function (array $row): array {
                return [
                    'id' => (int) $row['id'],
                    'event_key' => (string) $row['event_key'],
                    'event_type' => (string) $row['event_type'],
                    'event_label' => NotificationEvent::label((string) $row['event_type']),
                    'kanal' => (string) $row['kanal'],
                    'penerima_user_id' => (int) $row['penerima_user_id'],
                    'penerima_nama' => $row['penerima_nama'] === null ? null : (string) $row['penerima_nama'],
                    'pengajuan_id' => $row['pengajuan_id'] === null ? null : (int) $row['pengajuan_id'],
                    'status' => (string) $row['status'],
                    'percobaan' => (int) $row['percobaan'],
                    'maksimum_percobaan' => OutboxRepository::MAX_PERCOBAAN,
                    'gagal_permanen' => (int) $row['gagal_permanen'] === 1,
                    'error_kode' => $row['error_kode'] === null ? null : (string) $row['error_kode'],
                    'error_aman' => $row['error_terakhir'] === null ? null : (string) $row['error_terakhir'],
                    'percobaan_terakhir_pada' => $row['percobaan_terakhir_pada'],
                    'percobaan_berikutnya_pada' => $row['tersedia_pada'],
                    'dibuat_pada' => (string) $row['created_at'],
                    'riwayat_percobaan' => $this->notifications->attempts((int) $row['id'], 5),
                ];
            }, $result['rows']),
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $total === 0 ? 0 : (int) ceil($total / $perPage),
            ],
        ];
    }

    /**
     * Mencoba ulang satu baris gagal.
     *
     * Baris yang SAMA dikembalikan ke antrean — tidak ada baris baru — sehingga
     * kunci unik peristiwa/kanal/penerima tetap dipatuhi dan penerima tidak
     * pernah mendapat pesan ganda.
     *
     * @param array<string, mixed> $user
     * @param array{ip:?string, user_agent:?string} $meta
     * @return array<string, mixed>
     */
    public function cobaUlang(array $user, int $outboxId, array $meta): array
    {
        $this->requireAdmin($user);
        $userId = (int) $user['id'];

        $row = $this->notifications->find($outboxId);
        if ($row === null) {
            throw NotificationException::invalid('Baris notifikasi tidak ditemukan.');
        }
        if ((string) $row['kanal'] === NotificationChannel::IN_APP) {
            throw NotificationException::invalid('Notifikasi in-app tidak memerlukan percobaan ulang.');
        }
        if ((string) $row['status'] !== 'Failed') {
            throw NotificationException::conflict('Hanya baris berstatus Failed yang dapat dicoba ulang.');
        }

        $pengaturan = $this->settings->current();
        $kanal = (string) $row['kanal'];
        $aktif = $kanal === NotificationChannel::PUSH
            ? $pengaturan['push_enabled'] === true
            : $pengaturan['whatsapp_enabled'] === true;
        if (!$aktif) {
            throw NotificationException::conflict(
                'Kanal ' . NotificationChannel::label($kanal) . ' sedang mati. Nyalakan kanal sebelum mencoba ulang.'
            );
        }

        $ok = $this->notifications->requeue($outboxId);
        $this->settings->audit(
            'percobaan_ulang',
            $kanal,
            'Failed',
            $ok ? 'Queued' : 'Failed',
            $ok ? 'Diantrekan ulang' : 'Gagal',
            'Percobaan ulang manual oleh admin untuk outbox #' . $outboxId . '.',
            $userId,
            $meta['ip'] ?? null,
            $meta['user_agent'] ?? null
        );
        $this->audit->log('notifikasi.percobaan_ulang', 'notifikasi_outbox', $outboxId, null, [
            'kanal' => $kanal,
            'berhasil_diantrekan' => $ok,
        ], $userId);

        return [
            'id' => $outboxId,
            'diantrekan_ulang' => $ok,
            'pesan' => $ok
                ? 'Baris dikembalikan ke antrean. Worker berikutnya akan mencoba mengirim ulang tanpa membuat pesan baru.'
                : 'Baris tidak dapat diantrekan ulang. Muat ulang halaman lalu periksa statusnya.',
        ];
    }

    /**
     * Menjalankan satu putaran worker dari panel admin (mode manual aman).
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function jalankanWorker(array $user, string $kanal, bool $dryRun = false): array
    {
        $this->requireAdmin($user);
        if (!in_array($kanal, NotificationChannel::EKSTERNAL, true)) {
            throw NotificationException::invalid('Hanya kanal push dan WhatsApp yang diproses worker.');
        }

        return $this->dispatcher->run($kanal, 25, $dryRun);
    }

    /**
     * @param array<string, mixed> $user
     * @return array{items:array<int, array<string, mixed>>}
     */
    public function auditKanal(array $user, int $limit = 50): array
    {
        $this->requireAdmin($user);

        return ['items' => $this->settings->auditTrail($limit)];
    }

    /**
     * Nama environment yang dibutuhkan penyedia WhatsApp aktif.
     *
     * Hanya NAMA — nilainya tidak pernah dibaca di sini, apalagi dikirim.
     *
     * @return array<int, string>
     */
    private function environmentPenyedia(): array
    {
        $penyedia = ProviderFactory::ENV_KEYS[$this->whatsapp->name()] ?? [];
        if ($penyedia === []) {
            $penyedia = ProviderFactory::ENV_KEYS['http'];
        }

        return array_values(array_unique(array_merge(['WHATSAPP_PROVIDER'], $penyedia)));
    }

    /**
     * @param array{ip:?string, user_agent:?string} $meta
     */
    private function catatSakelar(
        string $kanal,
        bool $sebelum,
        bool $sesudah,
        int $userId,
        array $meta,
        int $antreanDibatalkan
    ): void {
        $this->settings->audit(
            'kanal_diubah',
            $kanal,
            $sebelum ? 'aktif' : 'nonaktif',
            $sesudah ? 'aktif' : 'nonaktif',
            'Tersimpan',
            $antreanDibatalkan > 0
                ? $antreanDibatalkan . ' baris antrean dibatalkan karena kanal dimatikan.'
                : null,
            $userId,
            $meta['ip'] ?? null,
            $meta['user_agent'] ?? null
        );
        $this->audit->log(
            'notifikasi.kanal_diubah',
            'pengaturan_notifikasi',
            null,
            ['kanal' => $kanal, 'aktif' => $sebelum],
            ['kanal' => $kanal, 'aktif' => $sesudah, 'antrean_dibatalkan' => $antreanDibatalkan],
            $userId
        );
    }

    /**
     * @param array{ip:?string, user_agent:?string} $meta
     */
    private function auditWhatsappCheck(string $status, string $pesan, int $userId, array $meta): void
    {
        $this->settings->audit(
            'pemeriksaan_konfigurasi',
            NotificationChannel::WHATSAPP,
            null,
            null,
            $status,
            $pesan,
            $userId,
            $meta['ip'] ?? null,
            $meta['user_agent'] ?? null
        );
        $this->audit->log('notifikasi.pemeriksaan_konfigurasi', 'pengaturan_notifikasi', null, null, [
            'kanal' => NotificationChannel::WHATSAPP,
            'status' => $status,
            'penyedia' => $this->whatsapp->name(),
            'mengirim_nyata' => $this->whatsapp->mengirimNyata(),
        ], $userId);
    }

    /**
     * @param array<string, mixed> $user
     */
    private function requireAdmin(array $user): void
    {
        $probe = [
            'id' => (int) ($user['id'] ?? 0),
            'roles' => array_values((array) ($user['roles'] ?? [])),
            'guru_id' => ($user['guru_id'] ?? null) === null ? null : (int) $user['guru_id'],
        ];
        if (!$this->capabilities->has($probe, Capabilities::ADMIN)) {
            throw NotificationException::forbidden('Hanya admin yang dapat mengelola kanal notifikasi.');
        }
    }
}
