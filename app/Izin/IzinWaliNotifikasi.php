<?php

declare(strict_types=1);

namespace App\Izin;

use App\Audit\AuditLogger;
use App\Notification\DeviceRepository;
use App\Notification\NotificationChannel;
use App\Notification\NotificationRepository;
use App\Notification\RecipientResolver;
use App\Notification\SafeError;
use App\Notification\SettingsRepository;
use mysqli;
use RuntimeException;
use Throwable;

/**
 * Notifikasi tahap pengajuan izin wali (PRD V7 Fase 3, §3.5 dan §5.3).
 *
 * Satu peralihan tahap → tepat satu baris InApp per penerima berhak dan paling
 * banyak satu baris Push (hanya bila sakelar Push menyala dan penerima punya
 * perangkat aktif). WhatsApp TIDAK PERNAH diantrekan. Baris ditulis ke
 * `notifikasi_outbox` V2 di dalam transaksi mutasi yang sama, sehingga replay
 * idempotensi tidak mengantre ulang (kunci peristiwa memuat versi baris
 * sesudah peralihan) dan peristiwa yang dibatalkan rollback tidak meninggalkan
 * notifikasi.
 *
 * Berbeda dengan pola V6, kegagalan menulis antrean TIDAK membatalkan
 * transaksi izin (PRD V7 Fase 3 persyaratan 5): penulisan dibungkus SAVEPOINT;
 * galat apa pun dikembalikan ke SAVEPOINT, dicatat ke audit/log tanpa isi, dan
 * transaksi izin tetap berjalan. Pengecualian satu-satunya adalah deadlock —
 * InnoDB sudah membatalkan seluruh transaksi, sehingga mutasi dijawab 409.
 *
 * Penerima selalu dihitung dari basis data saat ini (`penerima()`), bukan dari
 * isi permintaan: pembimbing tujuan yang masih sah, murobi tujuan berpenugasan
 * aktif, admin aktif, atau HANYA wali pengaju yang akun, data wali, dan relasi
 * `santri_wali`-nya masih aktif (wali lain tidak diberi tahu). Fungsi yang
 * sama dipakai worker Push tepat sebelum provider (`pushMasihBerhak()`), dan
 * halaman/endpoint tujuan memeriksa hak lagi saat tautan dibuka.
 *
 * Judul/isi generik untuk SEMUA kanal: tanpa nama santri, alasan izin, catatan
 * pembimbing, tanggal, nomor telepon, atau credential. `pengajuan_id` pada
 * baris outbox sengaja NULL agar pusat notifikasi tidak menggabungkan nama
 * santri ke daftar; satu-satunya penunjuk ada di `data_json`
 * (`{"tipe":"izin_wali","pengajuan_id":N}`).
 */
final class IzinWaliNotifikasi
{
    public const MASUK_PEMBIMBING = 'izin.wali_masuk_pembimbing';
    public const PERLU_PENETAPAN_PEMBIMBING = 'izin.wali_perlu_penetapan_pembimbing';
    public const PERLU_PERBAIKAN = 'izin.wali_perlu_perbaikan';
    public const DITOLAK_PEMBIMBING = 'izin.wali_ditolak_pembimbing';
    public const DITERUSKAN = 'izin.wali_diteruskan';
    public const MASUK_MUROBI = 'izin.wali_masuk_murobi';
    public const PERLU_PENETAPAN_MUROBI = 'izin.wali_perlu_penetapan_murobi';
    public const DIPUTUS = 'izin.wali_diputus';
    public const DIKOREKSI = 'izin.wali_keputusan_dikoreksi';
    public const DIBATALKAN = 'izin.wali_dibatalkan';

    public const EVENTS = [
        self::MASUK_PEMBIMBING,
        self::PERLU_PENETAPAN_PEMBIMBING,
        self::PERLU_PERBAIKAN,
        self::DITOLAK_PEMBIMBING,
        self::DITERUSKAN,
        self::MASUK_MUROBI,
        self::PERLU_PENETAPAN_MUROBI,
        self::DIPUTUS,
        self::DIKOREKSI,
        self::DIBATALKAN,
    ];

    /** Tipe tautan; satu-satunya kunci lain pada data adalah `pengajuan_id`. */
    public const TIPE_TAUTAN = 'izin_wali';

    /** Penerima menurut peristiwa (dipakai juga untuk dokumentasi/uji). */
    public const PENERIMA = [
        self::MASUK_PEMBIMBING => 'pembimbing',
        self::PERLU_PENETAPAN_PEMBIMBING => 'admin',
        self::PERLU_PERBAIKAN => 'wali_pengaju',
        self::DITOLAK_PEMBIMBING => 'wali_pengaju',
        self::DITERUSKAN => 'wali_pengaju',
        self::MASUK_MUROBI => 'murobi',
        self::PERLU_PENETAPAN_MUROBI => 'admin',
        self::DIPUTUS => 'wali_pengaju',
        self::DIKOREKSI => 'wali_pengaju',
        self::DIBATALKAN => 'pemegang_antrean',
    ];

    private const SAVEPOINT = 'v7_izin_notifikasi';

    private NotificationRepository $notifikasi;
    private SettingsRepository $settings;
    private DeviceRepository $devices;
    private RecipientResolver $resolver;
    private IzinWaliRepository $repo;

    public function __construct(private mysqli $db, private ?AuditLogger $audit = null)
    {
        $this->notifikasi = new NotificationRepository($db);
        $this->settings = new SettingsRepository($db);
        $this->devices = new DeviceRepository($db);
        $this->resolver = new RecipientResolver($db);
        $this->repo = new IzinWaliRepository($db);
    }

    // =======================================================================
    // Katalog
    // =======================================================================

    /**
     * Teks generik — satu-satunya tempat teks dirakit, dipakai saat antre dan
     * ulang oleh worker Push. Hanya nomor pengajuan; tanpa data pribadi.
     *
     * @return array{judul:string, isi:string}
     */
    public static function teks(string $event, int $id): array
    {
        $nomor = '#' . $id;

        return match ($event) {
            self::MASUK_PEMBIMBING => ['judul' => 'Pengajuan izin wali menunggu tinjauan',
                'isi' => 'Pengajuan izin ' . $nomor . ' dari wali menunggu tinjauan Anda. Masuk untuk melihat detail.'],
            self::PERLU_PENETAPAN_PEMBIMBING => ['judul' => 'Pengajuan izin wali perlu penetapan pembimbing',
                'isi' => 'Pengajuan izin ' . $nomor . ' belum memiliki satu pembimbing sah. Masuk untuk menetapkan pembimbing.'],
            self::PERLU_PERBAIKAN => ['judul' => 'Pengajuan izin perlu diperbaiki',
                'isi' => 'Pembimbing meminta perbaikan pengajuan izin ' . $nomor . '. Masuk untuk membaca catatan dan mengirim ulang.'],
            self::DITOLAK_PEMBIMBING => ['judul' => 'Pengajuan izin tidak diteruskan',
                'isi' => 'Pengajuan izin ' . $nomor . ' ditolak pada tahap pembimbing. Masuk untuk melihat detail.'],
            self::DITERUSKAN => ['judul' => 'Pengajuan izin diteruskan ke murobi',
                'isi' => 'Pengajuan izin ' . $nomor . ' sudah diteruskan pembimbing dan menunggu keputusan. Masuk untuk melihat status.'],
            self::MASUK_MUROBI => ['judul' => 'Pengajuan izin menunggu keputusan',
                'isi' => 'Pengajuan izin ' . $nomor . ' diteruskan pembimbing kepada Anda. Masuk untuk memberi keputusan.'],
            self::PERLU_PENETAPAN_MUROBI => ['judul' => 'Pengajuan izin perlu penetapan murobi',
                'isi' => 'Pengajuan izin ' . $nomor . ' sudah diteruskan pembimbing tetapi belum memiliki satu murobi. Masuk untuk menetapkan murobi.'],
            self::DIPUTUS => ['judul' => 'Keputusan izin tersedia',
                'isi' => 'Pengajuan izin ' . $nomor . ' sudah diputus. Masuk untuk melihat hasil dan alasannya.'],
            self::DIKOREKSI => ['judul' => 'Keputusan izin diperbarui',
                'isi' => 'Keputusan pengajuan izin ' . $nomor . ' dikoreksi admin. Masuk untuk melihat keputusan terbaru.'],
            self::DIBATALKAN => ['judul' => 'Pengajuan izin dibatalkan wali',
                'isi' => 'Pengajuan izin ' . $nomor . ' dibatalkan wali pengaju dan keluar dari antrean Anda.'],
            default => ['judul' => 'Pemberitahuan perizinan', 'isi' => 'Pengajuan izin ' . $nomor . ' diperbarui. Masuk untuk melihat detail.'],
        };
    }

    public static function label(string $event): string
    {
        return match ($event) {
            self::MASUK_PEMBIMBING => 'Pengajuan wali menunggu tinjauan',
            self::PERLU_PENETAPAN_PEMBIMBING => 'Pengajuan wali perlu penetapan pembimbing',
            self::PERLU_PERBAIKAN => 'Pengajuan perlu diperbaiki',
            self::DITOLAK_PEMBIMBING => 'Ditolak pembimbing',
            self::DITERUSKAN => 'Diteruskan ke murobi',
            self::MASUK_MUROBI => 'Pengajuan wali menunggu keputusan',
            self::PERLU_PENETAPAN_MUROBI => 'Pengajuan wali perlu penetapan murobi',
            self::DIPUTUS => 'Keputusan izin',
            self::DIKOREKSI => 'Keputusan izin dikoreksi',
            self::DIBATALKAN => 'Dibatalkan wali',
            default => $event,
        };
    }

    public static function eventKey(string $event, int $id, int $version): string
    {
        return 'v7:izin:' . $id . ':' . substr($event, strlen('izin.wali_')) . ':v' . $version;
    }

    /** @return array{tipe:string, pengajuan_id:int} */
    public static function data(int $id): array
    {
        return ['tipe' => self::TIPE_TAUTAN, 'pengajuan_id' => $id];
    }

    // =======================================================================
    // Antrean (di dalam transaksi mutasi)
    // =======================================================================

    /**
     * Mengantre satu peristiwa. WAJIB dipanggil di dalam transaksi mutasi,
     * sesudah baris pengajuan diperbarui (versi baru terbaca). Tidak melempar
     * galat kecuali deadlock (seluruh transaksi sudah dibatalkan InnoDB).
     *
     * @return array{event_key:string, penerima:int, in_app:int, push:int, galat:?string}
     */
    public function antrekan(string $event, int $id, int $aktorUserId): array
    {
        $hasil = ['event_key' => '', 'penerima' => 0, 'in_app' => 0, 'push' => 0, 'galat' => null];
        if (!in_array($event, self::EVENTS, true) || $id < 1) {
            return $hasil;
        }
        if (!$this->db->query('SAVEPOINT ' . self::SAVEPOINT)) {
            // Tanpa savepoint galat antrean tidak dapat diisolasi; notifikasi
            // dilewati agar transaksi izin tetap utuh.
            $hasil['galat'] = 'Savepoint notifikasi tidak dapat dibuat.';
            error_log('Notifikasi V7 dilewati untuk ' . $event . ': savepoint gagal.');

            return $hasil;
        }

        try {
            $row = $this->baris($id);
            if ($row === null) {
                throw new RuntimeException('Pengajuan wali tidak terbaca untuk notifikasi.');
            }
            $version = (int) $row['version'];
            $key = self::eventKey($event, $id, $version);
            $hasil['event_key'] = $key;
            $penerima = array_values(array_filter(
                $this->penerima($event, $row),
                static fn (int $userId): bool => $userId !== $aktorUserId
            ));
            $hasil['penerima'] = count($penerima);
            $berperangkat = $penerima !== [] && $this->settings->current()['push_enabled'] === true
                ? $this->devices->usersWithActiveDevice($penerima) : [];
            $teks = self::teks($event, $id);
            $data = (string) json_encode(self::data($id), JSON_UNESCAPED_SLASHES);
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
                        throw new RuntimeException('Baris notifikasi tidak dapat ditulis.');
                    }
                    $hasil[$k === NotificationChannel::IN_APP ? 'in_app' : 'push'] += $baris === null ? 0 : 1;
                }
            }
            if ($this->audit !== null && !$this->audit->log('izin_wali_notifikasi_diantrekan', 'izin_pengajuan', $id, null, [
                'event' => $event, 'version' => $version, 'penerima' => $hasil['penerima'],
                'in_app' => $hasil['in_app'], 'push' => $hasil['push'],
            ], $aktorUserId)) {
                throw new RuntimeException('Audit notifikasi tidak dapat disimpan.');
            }
            $this->db->query('RELEASE SAVEPOINT ' . self::SAVEPOINT);
        } catch (Throwable $exception) {
            $errno = $this->db->errno;
            if ($errno === 1213 || !$this->db->query('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT)) {
                // Deadlock: InnoDB sudah membatalkan seluruh transaksi izin.
                throw IzinException::conflict('Pengajuan sedang diproses permintaan lain. Coba lagi sesaat lagi.');
            }
            $hasil['in_app'] = 0;
            $hasil['push'] = 0;
            $hasil['galat'] = SafeError::fromThrowable($exception, 'Notifikasi gagal diantrekan.');
            // Hanya nama peristiwa dan nomor; tanpa isi izin.
            error_log('Notifikasi V7 gagal diantrekan untuk ' . $event . ' #' . $id . '.');
            $this->audit?->log('izin_wali_notifikasi_gagal', 'izin_pengajuan', $id, null, [
                'event' => $event, 'penerima' => $hasil['penerima'],
            ], $aktorUserId);
        }

        return $hasil;
    }

    /**
     * Pengajuan (kembali) masuk tahap pembimbing — sesudah dibuat, ditetapkan
     * admin, atau diperbaiki wali: pembimbing tujuan bila masih sah, selain itu
     * admin (antrean penetapan). Dipilih dari keadaan baris, bukan pemanggil.
     *
     * @return array{event_key:string, penerima:int, in_app:int, push:int, galat:?string}
     */
    public function antrekanTahapPembimbing(int $id, int $aktorUserId): array
    {
        $row = $this->baris($id);
        $sah = $row !== null && (int) ($row['pembimbing_sah'] ?? 0) === 1
            && (string) $row['status'] === IzinWaliRepository::STATUS_MENUNGGU_PEMBIMBING;

        return $this->antrekan($sah ? self::MASUK_PEMBIMBING : self::PERLU_PENETAPAN_PEMBIMBING, $id, $aktorUserId);
    }

    // =======================================================================
    // Penerima (dihitung ulang dari basis data)
    // =======================================================================

    /**
     * Penerima berhak untuk peristiwa pada keadaan baris SAAT INI. Keadaan yang
     * tidak sesuai peristiwa (mis. sudah ditinjau) menghasilkan daftar kosong.
     *
     * @param array<string, mixed> $row baris `IzinWaliRepository::find()`
     * @return array<int, int>
     */
    public function penerima(string $event, array $row): array
    {
        $status = (string) $row['status'];
        $sah = (int) ($row['pembimbing_sah'] ?? 0) === 1;
        $pemegangPembimbing = fn (): array => $sah
            ? $this->resolver->pengurus($row['pembimbing_pengurus_id'] === null ? null : (int) $row['pembimbing_pengurus_id'])
            : $this->resolver->admin();
        $ids = match ($event) {
            self::MASUK_PEMBIMBING => $status === IzinWaliRepository::STATUS_MENUNGGU_PEMBIMBING && $sah
                ? $this->resolver->pengurus((int) $row['pembimbing_pengurus_id']) : [],
            self::PERLU_PENETAPAN_PEMBIMBING => $status === IzinWaliRepository::STATUS_PERLU_PENETAPAN_PEMBIMBING
                || ($status === IzinWaliRepository::STATUS_MENUNGGU_PEMBIMBING && !$sah) ? $this->resolver->admin() : [],
            self::PERLU_PERBAIKAN => $status === IzinWaliRepository::STATUS_PERLU_PERBAIKAN ? $this->waliPengaju($row) : [],
            self::DITOLAK_PEMBIMBING => $status === IzinWaliRepository::STATUS_DITOLAK_PEMBIMBING ? $this->waliPengaju($row) : [],
            self::DITERUSKAN => in_array($status, [IzinRouter::STATUS_TERARAH, IzinRouter::STATUS_PERLU_ADMIN], true) ? $this->waliPengaju($row) : [],
            self::MASUK_MUROBI => $status === IzinRouter::STATUS_TERARAH && $row['murobi_guru_id'] !== null
                ? $this->resolver->murobi((int) $row['murobi_guru_id']) : [],
            self::PERLU_PENETAPAN_MUROBI => $status === IzinRouter::STATUS_PERLU_ADMIN ? $this->resolver->admin() : [],
            self::DIPUTUS, self::DIKOREKSI => in_array($status, ['Disetujui', 'Ditolak'], true) ? $this->waliPengaju($row) : [],
            self::DIBATALKAN => $status === 'Dibatalkan' ? match ($this->statusSebelumBatal((int) $row['id'])) {
                IzinWaliRepository::STATUS_MENUNGGU_PEMBIMBING, IzinWaliRepository::STATUS_PERLU_PERBAIKAN => $pemegangPembimbing(),
                IzinWaliRepository::STATUS_PERLU_PENETAPAN_PEMBIMBING, IzinRouter::STATUS_PERLU_ADMIN => $this->resolver->admin(),
                IzinRouter::STATUS_TERARAH => $row['murobi_guru_id'] === null ? [] : $this->resolver->murobi((int) $row['murobi_guru_id']),
                default => [],
            } : [],
            default => [],
        };
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return $ids;
    }

    /**
     * Pemeriksaan ulang worker Push tepat sebelum provider: peristiwa masih
     * sesuai keadaan pengajuan (versi kunci = versi baris) dan penerima masih
     * berhak. Mengembalikan data penunjuk yang boleh dikirim, atau null.
     *
     * @param array<string, mixed> $outbox baris outbox (event_type, event_key, penerima_user_id)
     * @return array{tipe:string, pengajuan_id:int}|null
     */
    public static function pushMasihBerhak(mysqli $db, array $outbox): ?array
    {
        $event = (string) ($outbox['event_type'] ?? '');
        if (!in_array($event, self::EVENTS, true)
            || preg_match('/^v7:izin:(\d+):([a-z_]+):v(\d+)$/', (string) ($outbox['event_key'] ?? ''), $m) !== 1) {
            return null;
        }
        $id = (int) $m[1];
        if (self::eventKey($event, $id, (int) $m[3]) !== (string) $outbox['event_key']) {
            return null;
        }
        $self = new self($db);
        $row = $self->baris($id);
        if ($row === null || (int) $row['version'] !== (int) $m[3]
            || !in_array((int) ($outbox['penerima_user_id'] ?? 0), $self->penerima($event, $row), true)) {
            return null;
        }

        return self::data($id);
    }

    /**
     * Jejak pengiriman untuk admin (metadata saja; tanpa isi).
     *
     * @return array<int, array<string, mixed>>
     */
    public function jejak(int $id): array
    {
        $pola = 'v7:izin:' . $id . ':%';
        $stmt = $this->db->prepare(
            'SELECT o.event_type, o.kanal, o.status, o.percobaan, o.gagal_permanen, o.error_kode,
                    o.created_at, o.dikirim_pada, u.name AS penerima
               FROM notifikasi_outbox o
               LEFT JOIN users u ON u.id = o.penerima_user_id
              WHERE o.event_key LIKE ?
              ORDER BY o.id'
        );
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('s', $pola);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($result !== false && ($r = $result->fetch_assoc()) !== null) {
            $rows[] = [
                'peristiwa' => (string) $r['event_type'],
                'peristiwa_label' => self::label((string) $r['event_type']),
                'kanal' => (string) $r['kanal'],
                'status' => (string) $r['status'],
                'percobaan' => (int) $r['percobaan'],
                'gagal_permanen' => (int) $r['gagal_permanen'] === 1,
                'kode_galat' => $r['error_kode'] === null ? null : (string) $r['error_kode'],
                'penerima' => $r['penerima'] === null ? null : (string) $r['penerima'],
                'dibuat' => (string) $r['created_at'],
                'dikirim' => $r['dikirim_pada'] === null ? null : (string) $r['dikirim_pada'],
            ];
        }
        $stmt->close();

        return $rows;
    }

    // =======================================================================
    // Internal
    // =======================================================================

    /** @return array<string, mixed>|null */
    private function baris(int $id): ?array
    {
        return $this->repo->find($id, date('Y-m-d'));
    }

    /**
     * HANYA wali pengaju: akun aktif ber-role orang tua yang masih terhubung
     * ke data wali pengaju yang aktif dan berelasi aktif dengan santri.
     *
     * @param array<string, mixed> $row
     * @return array<int, int>
     */
    private function waliPengaju(array $row): array
    {
        $userId = (int) ($row['diajukan_oleh_user_id'] ?? 0);
        $waliId = (int) ($row['wali_id'] ?? 0);
        if ($userId < 1 || $waliId < 1) {
            return [];
        }
        $stmt = $this->db->prepare(
            "SELECT u.id
               FROM users u
               JOIN wali w ON w.id = u.wali_id AND w.is_active = 1 AND w.archived_at IS NULL
               JOIN santri_wali sw ON sw.wali_id = w.id AND sw.santri_id = ? AND sw.archived_at IS NULL
              WHERE u.id = ? AND u.wali_id = ? AND u.is_active = 1
                AND EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id AND r.slug = 'orang_tua'
                             WHERE ur.user_id = u.id)
              LIMIT 1"
        );
        if ($stmt === false) {
            return [];
        }
        $santriId = (int) $row['santri_id'];
        $stmt->bind_param('iii', $santriId, $userId, $waliId);
        $stmt->execute();
        $ada = $stmt->get_result()?->fetch_assoc();
        $stmt->close();

        return is_array($ada) ? [$userId] : [];
    }

    /** Status sesaat sebelum pembatalan wali (dari riwayat, tidak dari request). */
    private function statusSebelumBatal(int $id): ?string
    {
        $stmt = $this->db->prepare(
            "SELECT status_sebelum FROM izin_riwayat_status
              WHERE pengajuan_id = ? AND peristiwa = 'pembatalan_wali'
              ORDER BY id DESC LIMIT 1"
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()?->fetch_assoc();
        $stmt->close();

        return is_array($row) && $row['status_sebelum'] !== null ? (string) $row['status_sebelum'] : null;
    }
}
