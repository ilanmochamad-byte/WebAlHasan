<?php

declare(strict_types=1);

namespace App\Notification;

/**
 * Katalog peristiwa notifikasi perizinan V2 Fase 4.
 *
 * Kelas ini adalah SATU-SATUNYA tempat isi notifikasi dirakit. Dua aturan yang
 * ditegakkan di sini:
 *
 *  1. **Kunci peristiwa stabil.** `key()` menghasilkan kunci deterministik per
 *     peristiwa bisnis (memakai id pengajuan dan versi barisnya). Bersama kunci
 *     unik `(event_key, kanal, penerima_user_id)` pada `notifikasi_outbox`,
 *     mengulang peristiwa yang sama TIDAK PERNAH menghasilkan notifikasi atau
 *     pesan kedua — baik karena retry pengguna, replay idempotensi, maupun
 *     percobaan ulang worker.
 *
 *  2. **Isi aman berlapis.** Kanal eksternal (Push, WhatsApp) hanya menerima
 *     kalimat generik dan nomor pengajuan. Nama santri, alasan izin, catatan
 *     pengurus, alasan keputusan, alasan penggantian, alasan pembatalan,
 *     alasan koreksi, nomor telepon, dan credential TIDAK PERNAH masuk ke
 *     kanal mana pun — termasuk in-app, kecuali nama santri yang memang
 *     diperlukan untuk mengenali pengajuan dan hanya tampil setelah pengguna
 *     terautentikasi di dalam aplikasi/website (PRD Fase 4 §4 dan §5.11).
 */
final class NotificationEvent
{
    public const PENGAJUAN_DIBUAT = 'izin.pengajuan_dibuat';
    public const ROUTING_PERLU_ADMIN = 'izin.routing_perlu_admin';
    public const MUROBI_DITETAPKAN = 'izin.murobi_ditetapkan';
    public const MUROBI_DITETAPKAN_ULANG = 'izin.murobi_ditetapkan_ulang';
    public const KEPUTUSAN_DISETUJUI = 'izin.keputusan_disetujui';
    public const KEPUTUSAN_DITOLAK = 'izin.keputusan_ditolak';
    public const KEPUTUSAN_ADMIN_PENGGANTI = 'izin.keputusan_admin_pengganti';
    public const PEMBATALAN = 'izin.pembatalan';
    public const KOREKSI = 'izin.koreksi';
    public const PESAN_UJI = 'sistem.pesan_uji';

    public const ALL = [
        self::PENGAJUAN_DIBUAT,
        self::ROUTING_PERLU_ADMIN,
        self::MUROBI_DITETAPKAN,
        self::MUROBI_DITETAPKAN_ULANG,
        self::KEPUTUSAN_DISETUJUI,
        self::KEPUTUSAN_DITOLAK,
        self::KEPUTUSAN_ADMIN_PENGGANTI,
        self::PEMBATALAN,
        self::KOREKSI,
        self::PESAN_UJI,
    ];

    /**
     * Peristiwa perizinan yang WAJIB menghasilkan tepat satu notifikasi in-app
     * bagi tiap penerima berhak (kriteria penerimaan Fase 4 poin 1).
     */
    public const PERISTIWA_IZIN = [
        self::PENGAJUAN_DIBUAT,
        self::ROUTING_PERLU_ADMIN,
        self::MUROBI_DITETAPKAN,
        self::MUROBI_DITETAPKAN_ULANG,
        self::KEPUTUSAN_DISETUJUI,
        self::KEPUTUSAN_DITOLAK,
        self::KEPUTUSAN_ADMIN_PENGGANTI,
        self::PEMBATALAN,
        self::KOREKSI,
    ];

    public static function valid(string $event): bool
    {
        return in_array($event, self::ALL, true);
    }

    public static function label(string $event): string
    {
        return match ($event) {
            self::PENGAJUAN_DIBUAT => 'Pengajuan izin baru',
            self::ROUTING_PERLU_ADMIN => 'Pengajuan perlu penetapan admin',
            self::MUROBI_DITETAPKAN => 'Penetapan murobi',
            self::MUROBI_DITETAPKAN_ULANG => 'Penetapan ulang murobi',
            self::KEPUTUSAN_DISETUJUI => 'Izin disetujui',
            self::KEPUTUSAN_DITOLAK => 'Izin ditolak',
            self::KEPUTUSAN_ADMIN_PENGGANTI => 'Keputusan oleh Admin Pengganti',
            self::PEMBATALAN => 'Pengajuan dibatalkan',
            self::KOREKSI => 'Keputusan dikoreksi',
            self::PESAN_UJI => 'Pesan uji kanal',
            default => $event,
        };
    }

    /**
     * Kunci peristiwa deterministik.
     *
     * Untuk peristiwa yang dapat terjadi lebih dari sekali pada satu pengajuan
     * (penetapan ulang, koreksi), versi baris pengajuan ikut masuk kunci karena
     * setiap mutasi menaikkan versi. Peristiwa yang hanya terjadi sekali
     * (pembuatan) tidak memerlukan versi.
     */
    public static function key(string $event, int $pengajuanId, ?int $version = null): string
    {
        $key = 'izin:' . $pengajuanId . ':' . str_replace('izin.', '', $event);
        if ($version !== null) {
            $key .= ':v' . $version;
        }

        return substr($key, 0, 120);
    }

    /**
     * Isi notifikasi per kanal.
     *
     * @param array<string, mixed> $context Nilai TIDAK sensitif saja:
     *        pengajuan_id, santri_nama, tgl_izin, tgl_kembali, status.
     * @return array{judul:string, isi:string}
     */
    public static function render(string $event, string $channel, array $context): array
    {
        $nomor = '#' . (int) ($context['pengajuan_id'] ?? 0);
        $santri = self::safeName((string) ($context['santri_nama'] ?? ''));
        $rentang = self::rentang($context);

        // Kanal eksternal: kalimat generik, tanpa nama santri dan tanpa alasan.
        // Penerima membuka aplikasi untuk melihat detail (PRD 5.7).
        if ($channel !== NotificationChannel::IN_APP) {
            return [
                'judul' => self::judulRingkas($event),
                'isi' => self::isiRingkas($event, $nomor),
            ];
        }

        $subjek = $santri === '' ? 'Pengajuan izin ' . $nomor : 'Izin ' . $santri . ' (' . $nomor . ')';

        return match ($event) {
            self::PENGAJUAN_DIBUAT => [
                'judul' => 'Pengajuan izin baru menunggu keputusan',
                'isi' => $subjek . ' diarahkan kepada Anda' . $rentang . '. Buka detail untuk menyetujui atau menolak.',
            ],
            self::ROUTING_PERLU_ADMIN => [
                'judul' => 'Pengajuan perlu penetapan murobi',
                'isi' => $subjek . ' tidak memiliki satu murobi tunggal' . $rentang . '. Tetapkan murobi melalui antrean admin.',
            ],
            self::MUROBI_DITETAPKAN => [
                'judul' => 'Murobi ditetapkan',
                'isi' => $subjek . ' kini diarahkan kepada murobi yang ditetapkan admin' . $rentang . '.',
            ],
            self::MUROBI_DITETAPKAN_ULANG => [
                'judul' => 'Murobi ditetapkan ulang',
                'isi' => $subjek . ' dialihkan kepada murobi lain oleh admin' . $rentang . '.',
            ],
            self::KEPUTUSAN_DISETUJUI => [
                'judul' => 'Izin disetujui',
                'isi' => $subjek . ' telah disetujui' . $rentang . '. Buka detail untuk melihat alasan keputusan.',
            ],
            self::KEPUTUSAN_DITOLAK => [
                'judul' => 'Izin ditolak',
                'isi' => $subjek . ' telah ditolak' . $rentang . '. Buka detail untuk melihat alasan keputusan.',
            ],
            self::KEPUTUSAN_ADMIN_PENGGANTI => [
                'judul' => 'Keputusan diambil Admin Pengganti',
                'isi' => $subjek . ' ' . self::hasilFrasa($context) . ' oleh admin sebagai pengganti murobi'
                    . $rentang . '. Buka detail untuk melihat alasan keputusan.',
            ],
            self::PEMBATALAN => [
                'judul' => 'Pengajuan izin dibatalkan',
                'isi' => $subjek . ' dibatalkan sebelum ada keputusan' . $rentang . '.',
            ],
            self::KOREKSI => [
                'judul' => 'Keputusan izin dikoreksi',
                'isi' => $subjek . ' dikoreksi admin' . $rentang . '. Riwayat keputusan sebelumnya tetap tersimpan.',
            ],
            self::PESAN_UJI => [
                'judul' => 'Pesan uji kanal notifikasi',
                'isi' => 'Pesan uji dari admin untuk memastikan kanal notifikasi berfungsi. Tidak ada tindakan yang diperlukan.',
            ],
            default => [
                'judul' => 'Pemberitahuan perizinan',
                'isi' => $subjek . ' diperbarui.',
            ],
        };
    }

    /**
     * Payload deep link. Hanya memuat penunjuk sumber daya — TIDAK memuat data
     * pribadi. Server tetap memverifikasi hak akses saat detail dibuka; id di
     * sini tidak pernah dipercaya sebagai bukti otorisasi (PRD Fase 4 §5.10).
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function data(string $event, array $context): array
    {
        $pengajuanId = (int) ($context['pengajuan_id'] ?? 0);

        if ($event === self::PESAN_UJI || $pengajuanId < 1) {
            return ['tipe' => 'sistem', 'event' => $event];
        }

        return [
            'tipe' => 'izin',
            'event' => $event,
            'pengajuan_id' => $pengajuanId,
            'url' => '/izin/' . $pengajuanId,
        ];
    }

    private static function judulRingkas(string $event): string
    {
        return match ($event) {
            self::PENGAJUAN_DIBUAT => 'Pengajuan izin baru',
            self::ROUTING_PERLU_ADMIN => 'Pengajuan perlu penetapan',
            self::MUROBI_DITETAPKAN, self::MUROBI_DITETAPKAN_ULANG => 'Penetapan murobi',
            self::KEPUTUSAN_DISETUJUI, self::KEPUTUSAN_DITOLAK, self::KEPUTUSAN_ADMIN_PENGGANTI => 'Keputusan izin',
            self::PEMBATALAN => 'Pengajuan dibatalkan',
            self::KOREKSI => 'Keputusan dikoreksi',
            self::PESAN_UJI => 'Pesan uji Al Hasan',
            default => 'Pemberitahuan perizinan',
        };
    }

    private static function isiRingkas(string $event, string $nomor): string
    {
        return match ($event) {
            self::PENGAJUAN_DIBUAT => 'Ada pengajuan izin ' . $nomor . ' menunggu keputusan Anda. Buka aplikasi untuk melihat detail.',
            self::ROUTING_PERLU_ADMIN => 'Pengajuan ' . $nomor . ' menunggu penetapan murobi. Buka aplikasi untuk melihat detail.',
            self::MUROBI_DITETAPKAN, self::MUROBI_DITETAPKAN_ULANG => 'Penetapan murobi pengajuan ' . $nomor . ' diperbarui. Buka aplikasi untuk melihat detail.',
            self::KEPUTUSAN_DISETUJUI, self::KEPUTUSAN_DITOLAK, self::KEPUTUSAN_ADMIN_PENGGANTI => 'Pengajuan ' . $nomor . ' sudah diputus. Buka aplikasi untuk melihat detail.',
            self::PEMBATALAN => 'Pengajuan ' . $nomor . ' dibatalkan. Buka aplikasi untuk melihat detail.',
            self::KOREKSI => 'Keputusan pengajuan ' . $nomor . ' dikoreksi. Buka aplikasi untuk melihat detail.',
            self::PESAN_UJI => 'Pesan uji kanal notifikasi Al Hasan. Tidak ada tindakan yang diperlukan.',
            default => 'Pengajuan ' . $nomor . ' diperbarui. Buka aplikasi untuk melihat detail.',
        };
    }

    /**
     * Frasa hasil keputusan. Hanya menerima dua nilai status yang sudah publik
     * pada daftar pengajuan; nilai lain diperlakukan netral.
     *
     * @param array<string, mixed> $context
     */
    private static function hasilFrasa(array $context): string
    {
        return match ((string) ($context['hasil'] ?? '')) {
            'Disetujui' => 'DISETUJUI',
            'Ditolak' => 'DITOLAK',
            default => 'sudah diputus',
        };
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function rentang(array $context): string
    {
        $mulai = self::safeDate((string) ($context['tgl_izin'] ?? ''));
        $selesai = self::safeDate((string) ($context['tgl_kembali'] ?? ''));
        if ($mulai === null || $selesai === null) {
            return '';
        }

        return ' untuk ' . $mulai . ' s.d. ' . $selesai;
    }

    private static function safeDate(string $value): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }

    /**
     * Nama ditampilkan apa adanya tetapi dibatasi panjangnya dan dibersihkan
     * dari karakter kendali agar tidak merusak tampilan atau payload JSON.
     */
    private static function safeName(string $value): string
    {
        $value = trim((string) preg_replace('/[\p{C}]+/u', ' ', $value));
        $value = (string) preg_replace('/\s+/u', ' ', $value);

        return mb_strlen($value) > 60 ? mb_substr($value, 0, 59) . '…' : $value;
    }
}
