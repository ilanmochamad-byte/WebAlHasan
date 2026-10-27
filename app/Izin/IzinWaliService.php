<?php

declare(strict_types=1);

namespace App\Izin;

use App\Audit\AuditLogger;
use App\Auth\Capabilities;
use App\Database\Schema;
use RuntimeException;
use Throwable;

/**
 * Pengajuan izin oleh orang tua/wali melalui pembimbing (PRD V7 Fase 1).
 *
 * Satu pintu untuk website dan REST API. Fase 1 mencakup: wali berelasi aktif
 * membuat SATU pengajuan kanonis dan membaca statusnya; routing pembimbing
 * (tepat satu sah → antrean pembimbing, nol/lebih → antrean admin); antrean
 * pembimbing hanya-baca; pemantauan admin dan penetapan pembimbing sah.
 * Tinjauan pembimbing, perbaikan, penerusan ke murobi, pembatalan wali, dan
 * notifikasi adalah Fase 2–3 dan sengaja belum ada di sini.
 *
 * Prinsip (sama dengan alur V2 `IzinWorkflowService`):
 *   1. Hak dihitung ulang dari basis data pada setiap panggilan; parameter
 *      request tidak pernah memperluas cakupan. Di luar hak → 404 agar ID
 *      tebakan tidak membocorkan keberadaan pengajuan.
 *   2. Satu transaksi per mutasi: kunci baris akun pelaku (menserialkan
 *      kiriman ulang bersamaan), klaim idempotensi, lalu kunci
 *      baris santri (urutan yang SAMA dengan jalur pengurus V2, sehingga dua
 *      jalur berurutan pada satu santri), hak dibaca ulang dengan kunci baca,
 *      benturan tanggal diperiksa dengan aturan V2 yang sama.
 *   3. Versi optimistis untuk setiap perubahan baris yang sudah ada.
 *   4. Riwayat status dan audit wajib di dalam transaksi; audit hanya memuat
 *      metadata (tanpa alasan izin, catatan, atau tanggal rinci).
 *   5. Murobi tidak pernah menerima pengajuan wali sebelum diteruskan
 *      pembimbing: `murobi_guru_id` tetap NULL dan status berada di tahap V7.
 */
final class IzinWaliService
{
    public const OP_CREATE = 'izin.wali.create';
    public const OP_PENETAPAN = 'izin.wali.penetapan_pembimbing';

    public const PERAN_WALI = 'wali';
    public const PERAN_PEMBIMBING = 'pembimbing';
    public const PERAN_ADMIN = 'admin';

    public function __construct(
        private IzinWaliRepository $repo,
        private IzinWriteRepository $write,
        private IzinRepository $read,
        private PembimbingRouter $router,
        private IzinIdempotency $idempotency,
        private Capabilities $capabilities,
        private AuditLogger $audit
    ) {
    }

    public function terpasang(): bool
    {
        $db = $this->repo->db();

        return Schema::hasColumn($db, 'izin_pengajuan', 'asal_pengajuan')
            && Schema::hasTable($db, 'izin_pembimbing_penetapan');
    }

    // =======================================================================
    // Akses
    // =======================================================================

    /**
     * Peran V7 yang benar-benar dimiliki akun saat ini (petunjuk tampilan;
     * setiap tindakan tetap memeriksa ulang).
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function akses(array $user): array
    {
        $this->wajibTerpasang();
        $peran = $this->peran($user);
        $hasil = [
            'peran' => array_keys(array_filter($peran)),
            'wali' => $peran[self::PERAN_WALI],
            'pembimbing' => $peran[self::PERAN_PEMBIMBING],
            'admin' => $peran[self::PERAN_ADMIN],
            'boleh_mengajukan' => false,
            'antrean_pembimbing' => 0,
            'antrean_admin' => 0,
        ];
        if ($peran[self::PERAN_WALI]) {
            $waliId = $this->repo->waliAkun((int) $user['id']);
            $hasil['boleh_mengajukan'] = $waliId !== null && $this->repo->anakAktif($waliId) !== [];
        }
        if ($peran[self::PERAN_PEMBIMBING]) {
            $hasil['antrean_pembimbing'] = $this->repo->antreanPembimbing($this->pengurusId($user), $this->today(), 1, 1)['total'];
        }
        if ($peran[self::PERAN_ADMIN]) {
            $hasil['antrean_admin'] = $this->repo->jumlahAntreanAdmin($this->today());
        }

        return $hasil;
    }

    /**
     * Anak berelasi aktif yang boleh diajukan.
     *
     * @param array<string, mixed> $user
     * @return array<int, array<string, mixed>>
     */
    public function anak(array $user): array
    {
        $this->wajibTerpasang();
        $waliId = $this->wajibWali($user);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'nis' => (string) $row['nis'],
            'nama' => (string) $row['nama_santri'],
            'hubungan' => $row['hubungan'] === null ? null : (string) $row['hubungan'],
        ], $this->repo->anakAktif($waliId));
    }

    // =======================================================================
    // 1. Pengajuan oleh wali
    // =======================================================================

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input  santri_id, tgl_izin, tgl_kembali, alasan, catatan
     * @param array{ip:?string, user_agent:?string} $meta
     * @return array<string, mixed> berisi `status_http` 201 (baru) atau 200 (putar ulang)
     */
    public function buat(array $user, array $input, ?string $idempotencyKey, array $meta): array
    {
        $this->wajibTerpasang();
        $userId = (int) $user['id'];
        $this->wajibWali($user);
        $key = $this->idempotency->normalizeKey($idempotencyKey);

        $santriId = is_numeric($input['santri_id'] ?? null) ? (int) $input['santri_id'] : 0;
        if ($santriId < 1) {
            throw IzinException::invalid('Anak wajib dipilih.');
        }
        $tglIzin = $this->requireDate($input['tgl_izin'] ?? null, 'Tanggal izin');
        $tglKembali = $this->requireDate($input['tgl_kembali'] ?? null, 'Tanggal kembali');
        if ($tglKembali < $tglIzin) {
            throw IzinException::invalid('Tanggal kembali tidak boleh mendahului tanggal izin.');
        }
        $alasan = $this->requireText($input['alasan'] ?? null, 'Alasan izin', 3, 2000);
        $catatan = $this->optionalText($input['catatan'] ?? null, 'Catatan', 1000);

        $payload = [
            'santri_id' => $santriId,
            'tgl_izin' => $tglIzin,
            'tgl_kembali' => $tglKembali,
            'alasan' => $alasan,
            'catatan' => $catatan,
        ];
        $onDate = $this->today();

        return $this->transactional(function () use ($userId, $key, $payload, $santriId, $tglIzin, $tglKembali, $alasan, $catatan, $onDate, $meta): array {
            $this->repo->kunciAkun($userId);
            $replay = $this->idempotency->begin($userId, self::OP_CREATE, $key, $payload);

            // Hak dibaca ulang DI DALAM transaksi dengan kunci baca. Putar ulang
            // juga diperiksa: relasi yang dicabut sesudah kiriman pertama tidak
            // lagi menerima hasil tersimpan.
            $waliId = $this->repo->waliAkun($userId, true);
            if ($waliId === null) {
                throw IzinException::forbidden('Akun ini bukan akun orang tua/wali yang aktif.');
            }
            if (!$this->repo->relasiAktif($waliId, $santriId, true)) {
                throw IzinException::notFound('Anak tidak ditemukan di daftar anak yang terhubung dengan akun Anda.');
            }
            if ($replay !== null) {
                return $replay['response'] + ['idempotent_replay' => true, 'status_http' => 200];
            }

            // Kunci baris santri: urutan sama dengan jalur pengurus V2, sehingga
            // pembuatan pengajuan kedua jalur untuk santri yang sama berurutan.
            $santri = $this->write->lockSantri($santriId);
            if ($santri === null || (int) $santri['is_active'] !== 1 || $santri['archived_at'] !== null) {
                throw IzinException::invalid('Santri tidak aktif sehingga tidak dapat diajukan izin.');
            }
            $year = $this->read->activeYear();
            if ($year === null) {
                throw IzinException::invalid('Tidak ada tahun ajaran aktif. Hubungi admin pesantren.');
            }

            // Benturan tanggal LINTAS JALUR: aturan dan status penahan V2 yang
            // sama, kini termasuk tahap pembimbing V7 (PRD V7 §5.2).
            $overlap = $this->write->findOverlap($santriId, $tglIzin, $tglKembali);
            if ($overlap !== null) {
                throw IzinException::conflict(sprintf(
                    'Anak ini sudah memiliki pengajuan izin yang masih berjalan pada rentang %s s.d. %s yang bersinggungan.',
                    (string) $overlap['tgl_izin'],
                    (string) $overlap['tgl_kembali']
                ));
            }

            $routing = $this->router->resolve($santriId, $onDate);
            $status = $routing['jumlah'] === 1
                ? IzinWaliRepository::STATUS_MENUNGGU_PEMBIMBING
                : IzinWaliRepository::STATUS_PERLU_PENETAPAN_PEMBIMBING;

            $id = $this->repo->insertPengajuanWali([
                'santri_id' => $santriId,
                'wali_id' => $waliId,
                'user_id' => $userId,
                'pembimbing_assignment_id' => $routing['assignment_id'],
                'pembimbing_pengurus_id' => $routing['pengurus_id'],
                'jumlah_kandidat' => $routing['jumlah'],
                'routing_catatan' => mb_substr($routing['catatan'], 0, 255),
                'tahun_ajaran_id' => (int) $year['id'],
                'tgl_izin' => $tglIzin,
                'tgl_kembali' => $tglKembali,
                'alasan' => $alasan,
                'catatan' => $catatan,
                'status' => $status,
                'idempotency_key' => $key,
            ]);

            // Riwayat tidak menyalin alasan izin: isi tetap di baris pengajuan
            // dan hanya dibuka kepada pihak yang berhak melihat isi.
            $this->write->insertRiwayat($this->historyRow($id, 'pengajuan_wali_dibuat', null, $status, $userId, 'Wali', null, $meta));
            $this->write->insertRiwayat($this->historyRow(
                $id,
                $routing['jumlah'] === 1 ? 'routing_pembimbing_otomatis' : 'routing_pembimbing_perlu_admin',
                $status,
                $status,
                $userId,
                'Sistem',
                $routing['catatan'],
                $meta
            ));

            $response = [
                'id' => $id,
                'status' => $status,
                'tahap' => self::tahap($status),
                'version' => 1,
                'pembimbing_ditetapkan' => $routing['jumlah'] === 1,
            ];
            $this->idempotency->complete($userId, self::OP_CREATE, $key, 201, $response, $id);
            $this->auditOrFail('izin_wali_pengajuan_dibuat', $id, null, [
                'santri_id' => $santriId,
                'wali_id' => $waliId,
                'tahun_ajaran_id' => (int) $year['id'],
                'status' => $status,
                'jumlah_kandidat_pembimbing' => $routing['jumlah'],
                'pembimbing_pengurus_id' => $routing['pengurus_id'],
            ], $userId);

            return $response + ['idempotent_replay' => false, 'status_http' => 201];
        });
    }

    // =======================================================================
    // 2. Penetapan pembimbing oleh admin
    // =======================================================================

    /**
     * Kandidat pembimbing sah untuk satu pengajuan wali (admin).
     *
     * @param array<string, mixed> $user
     * @return array<int, array<string, mixed>>
     */
    public function kandidatPembimbing(array $user, int $id): array
    {
        $this->wajibTerpasang();
        $this->wajibAdmin($user);
        $row = $this->repo->find($id, $this->today());
        if ($row === null) {
            throw IzinException::notFound();
        }

        return $this->router->candidates((int) $row['santri_id'], $this->today());
    }

    /**
     * @param array<string, mixed> $user
     * @param array{ip:?string, user_agent:?string} $meta
     * @return array<string, mixed>
     */
    public function tetapkanPembimbing(array $user, int $id, mixed $pengurusId, mixed $alasan, mixed $expectedVersion, ?string $idempotencyKey, array $meta): array
    {
        $this->wajibTerpasang();
        $this->wajibAdmin($user);
        $userId = (int) $user['id'];
        $key = $this->idempotency->normalizeKey($idempotencyKey);
        $pengurusId = is_numeric($pengurusId) ? (int) $pengurusId : 0;
        if ($pengurusId < 1) {
            throw IzinException::invalid('Pembimbing tujuan wajib dipilih.');
        }
        $alasan = $this->requireText($alasan, 'Alasan penetapan pembimbing', 3, 1000);
        if (!is_numeric($expectedVersion) || (int) $expectedVersion < 1) {
            throw IzinException::invalid('Versi data wajib disertakan. Muat ulang halaman lalu ulangi.');
        }
        $version = (int) $expectedVersion;
        $payload = ['pengajuan_id' => $id, 'pengurus_id' => $pengurusId, 'alasan' => $alasan, 'version' => $version];
        $onDate = $this->today();

        return $this->transactional(function () use ($userId, $id, $pengurusId, $alasan, $version, $key, $payload, $onDate, $meta): array {
            $this->repo->kunciAkun($userId);
            // Pemeriksaan awal dapat mendahului pencabutan akun/role saat
            // menunggu kunci. Periksa kembali sebelum klaim maupun replay.
            $this->wajibAdmin(['id' => $userId]);
            $replay = $this->idempotency->begin($userId, self::OP_PENETAPAN, $key, $payload);
            if ($replay !== null) {
                return $replay['response'] + ['idempotent_replay' => true, 'status_http' => 200];
            }

            $row = $this->write->lockPengajuan($id);
            if ($row === null || (string) ($row['asal_pengajuan'] ?? '') !== 'Wali') {
                throw IzinException::notFound();
            }
            if (!in_array((string) $row['status'], IzinWaliRepository::STATUS_PENETAPAN, true)) {
                throw IzinException::conflict('Pengajuan berstatus ' . (string) $row['status'] . ' sehingga pembimbingnya tidak dapat ditetapkan lagi.');
            }
            if ((int) $row['version'] !== $version) {
                throw IzinException::conflict('Pengajuan sudah diperbarui pihak lain (versi ' . (int) $row['version'] . '). Muat ulang halaman lalu ulangi.');
            }

            // Hanya pembimbing SAH atas santri ini; penugasan dikunci baca agar
            // pencabutan bersamaan tidak meloloskan penugasan yang sudah tidak aktif.
            $santriId = (int) $row['santri_id'];
            $assignmentId = $this->router->assignmentFor($pengurusId, $santriId, $onDate, true);
            if ($assignmentId === null) {
                throw IzinException::invalid('Pengurus yang dipilih bukan pembimbing sah santri ini (penugasan aktif pada kamar/kelas santri dan akun pengurus aktif).');
            }
            $sebelum = $row['pembimbing_pengurus_id'] === null ? null : (int) $row['pembimbing_pengurus_id'];
            $statusSebelum = (string) $row['status'];
            if ($sebelum === $pengurusId && $statusSebelum === IzinWaliRepository::STATUS_MENUNGGU_PEMBIMBING
                && $this->router->assignmentFor($sebelum, $santriId, $onDate) !== null) {
                throw IzinException::conflict('Pembimbing tersebut sudah menjadi pembimbing tujuan pengajuan ini.');
            }
            $jumlahKandidat = count($this->router->candidates($santriId, $onDate));

            if (!$this->repo->applyPenetapan($id, $pengurusId, $assignmentId, $userId, $version)) {
                throw IzinException::conflict('Pengajuan sudah berubah sejak halaman ini dimuat. Muat ulang lalu coba lagi.');
            }
            $this->repo->insertPenetapan([
                'pengajuan_id' => $id,
                'sebelum' => $sebelum,
                'pengurus_id' => $pengurusId,
                'assignment_id' => $assignmentId,
                'status_sebelum' => $statusSebelum,
                'jumlah_kandidat' => $jumlahKandidat,
                'alasan' => $alasan,
                'actor' => $userId,
                'versi_sebelum' => $version,
            ]);
            $this->write->insertRiwayat($this->historyRow(
                $id,
                $sebelum === null ? 'pembimbing_ditetapkan' : 'pembimbing_ditetapkan_ulang',
                $statusSebelum,
                IzinWaliRepository::STATUS_MENUNGGU_PEMBIMBING,
                $userId,
                'Admin',
                $alasan,
                $meta
            ));

            $response = [
                'id' => $id,
                'status' => IzinWaliRepository::STATUS_MENUNGGU_PEMBIMBING,
                'tahap' => self::tahap(IzinWaliRepository::STATUS_MENUNGGU_PEMBIMBING),
                'pembimbing_pengurus_id' => $pengurusId,
                'version' => $version + 1,
            ];
            $this->idempotency->complete($userId, self::OP_PENETAPAN, $key, 200, $response, $id);
            $this->auditOrFail(
                'izin_wali_pembimbing_ditetapkan',
                $id,
                ['status' => $statusSebelum, 'pembimbing_pengurus_id' => $sebelum, 'version' => $version],
                [
                    'status' => IzinWaliRepository::STATUS_MENUNGGU_PEMBIMBING,
                    'pembimbing_pengurus_id' => $pengurusId,
                    'pembimbing_assignment_id' => $assignmentId,
                    'jumlah_kandidat' => $jumlahKandidat,
                    'version' => $version + 1,
                ],
                $userId
            );

            return $response + ['idempotent_replay' => false, 'status_http' => 200];
        });
    }

    // =======================================================================
    // 3. Pembacaan per peran
    // =======================================================================

    /**
     * Daftar pengajuan wali untuk anak berelasi aktif. Wali pengaju melihat
     * isi lengkap; wali lain pada santri yang sama hanya status ringkas.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function daftarWali(array $user, mixed $santriId = null, mixed $page = 1): array
    {
        $this->wajibTerpasang();
        $waliId = $this->wajibWali($user);
        $santri = is_numeric($santriId) && (int) $santriId > 0 ? (int) $santriId : null;
        [$page, $perPage] = [$this->pageOf($page), 20];
        $hasil = $this->repo->daftarWali($waliId, $santri, $this->today(), $page, $perPage);

        return [
            'peran' => self::PERAN_WALI,
            'items' => array_map(fn (array $row): array => $this->proyeksiWali($row, (int) $user['id'], $waliId), $hasil['rows']),
            'total' => $hasil['total'],
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function antreanPembimbing(array $user, mixed $page = 1): array
    {
        $this->wajibTerpasang();
        $pengurusId = $this->wajibPembimbing($user);
        [$page, $perPage] = [$this->pageOf($page), 20];
        $hasil = $this->repo->antreanPembimbing($pengurusId, $this->today(), $page, $perPage);

        return [
            'peran' => self::PERAN_PEMBIMBING,
            'items' => array_map(fn (array $row): array => $this->proyeksiPetugas($row), $hasil['rows']),
            'total' => $hasil['total'],
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $query  antrean=1, status
     * @return array<string, mixed>
     */
    public function daftarAdmin(array $user, array $query = []): array
    {
        $this->wajibTerpasang();
        $this->wajibAdmin($user);
        $antrean = (string) ($query['antrean'] ?? '') === '1';
        $status = is_string($query['status'] ?? null) && in_array($query['status'], array_merge(IzinWaliRepository::STATUS_TAHAP_V7, IzinRepository::STATUSES), true)
            ? (string) $query['status'] : null;
        [$page, $perPage] = [$this->pageOf($query['page'] ?? 1), 20];
        $hasil = $this->repo->daftarAdmin($antrean, $status, $this->today(), $page, $perPage);

        return [
            'peran' => self::PERAN_ADMIN,
            'filter' => ['antrean' => $antrean, 'status' => $status],
            'items' => array_map(fn (array $row): array => $this->proyeksiPetugas($row), $hasil['rows']),
            'total' => $hasil['total'],
            'page' => $page,
            'per_page' => $perPage,
            'ringkasan' => $this->repo->ringkasanStatus(),
            'antrean_admin' => $this->repo->jumlahAntreanAdmin($this->today()),
        ];
    }

    /**
     * Rincian satu pengajuan wali sesuai hak akun:
     *   admin → lengkap + riwayat penetapan; pembimbing tujuan yang masih sah →
     *   lengkap; wali pengaju → lengkap tanpa catatan internal; wali lain
     *   berelasi aktif → status ringkas; selain itu 404.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function detail(array $user, int $id): array
    {
        $this->wajibTerpasang();
        $onDate = $this->today();
        $row = $this->repo->find($id, $onDate);
        if ($row === null) {
            throw IzinException::notFound();
        }
        $peran = $this->peran($user);
        $userId = (int) $user['id'];

        if ($peran[self::PERAN_ADMIN]) {
            return [
                'peran_akses' => self::PERAN_ADMIN,
                'pengajuan' => $this->proyeksiPetugas($row),
                'riwayat' => $this->riwayatPetugas($id),
                'penetapan' => $this->repo->penetapan($id),
                'tindakan' => [
                    'tetapkan_pembimbing' => in_array((string) $row['status'], IzinWaliRepository::STATUS_PENETAPAN, true),
                ],
            ];
        }
        if ($peran[self::PERAN_PEMBIMBING]
            && $row['pembimbing_pengurus_id'] !== null
            && (int) $row['pembimbing_pengurus_id'] === $this->pengurusId($user)
            && (int) $row['pembimbing_sah'] === 1) {
            return [
                'peran_akses' => self::PERAN_PEMBIMBING,
                'pengajuan' => $this->proyeksiPetugas($row),
                'riwayat' => $this->riwayatPetugas($id),
                // Tindakan tinjauan pembimbing adalah Fase 2.
                'tindakan' => [],
            ];
        }
        if ($peran[self::PERAN_WALI]) {
            $waliId = $this->repo->waliAkun($userId);
            if ($waliId !== null && $this->repo->relasiAktif($waliId, (int) $row['santri_id'])) {
                $proyeksi = $this->proyeksiWali($row, $userId, $waliId);

                return [
                    'peran_akses' => self::PERAN_WALI,
                    'pengajuan' => $proyeksi,
                    'riwayat' => $proyeksi['pengaju_sendiri'] ? $this->riwayatWali($id) : [],
                    // Perbaikan/pembatalan oleh wali pengaju adalah Fase 2;
                    // wali lain tidak pernah memperoleh tindakan.
                    'tindakan' => [],
                ];
            }
        }

        throw IzinException::notFound();
    }

    // =======================================================================
    // Label tahap (dipakai web/API)
    // =======================================================================

    public static function tahap(string $status): string
    {
        return match ($status) {
            IzinWaliRepository::STATUS_MENUNGGU_PEMBIMBING => 'pembimbing',
            IzinWaliRepository::STATUS_PERLU_PENETAPAN_PEMBIMBING => 'penetapan_pembimbing',
            IzinWaliRepository::STATUS_PERLU_PERBAIKAN => 'perbaikan_wali',
            IzinWaliRepository::STATUS_DITOLAK_PEMBIMBING, 'Dibatalkan', 'Disetujui', 'Ditolak' => 'selesai',
            IzinRouter::STATUS_TERARAH => 'murobi',
            IzinRouter::STATUS_PERLU_ADMIN => 'penetapan_murobi',
            default => 'tidak_dikenal',
        };
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            IzinWaliRepository::STATUS_MENUNGGU_PEMBIMBING => 'Menunggu tinjauan pembimbing',
            IzinWaliRepository::STATUS_PERLU_PENETAPAN_PEMBIMBING => 'Menunggu penetapan pembimbing oleh admin',
            IzinWaliRepository::STATUS_PERLU_PERBAIKAN => 'Perlu perbaikan wali',
            IzinWaliRepository::STATUS_DITOLAK_PEMBIMBING => 'Ditolak pembimbing',
            IzinRouter::STATUS_TERARAH => 'Diteruskan ke murobi',
            IzinRouter::STATUS_PERLU_ADMIN => 'Diteruskan; menunggu penetapan murobi',
            'Disetujui' => 'Disetujui murobi',
            'Ditolak' => 'Ditolak murobi',
            'Dibatalkan' => 'Dibatalkan',
            default => $status,
        };
    }

    // =======================================================================
    // Proyeksi
    // =======================================================================

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function proyeksiWali(array $row, int $userId, int $waliId): array
    {
        $sendiri = (int) $row['diajukan_oleh_user_id'] === $userId && (int) $row['wali_id'] === $waliId;
        $dasar = [
            'id' => (int) $row['id'],
            'asal' => 'Wali',
            'santri' => ['id' => (int) $row['santri_id'], 'nis' => (string) $row['nis'], 'nama' => (string) $row['nama_santri']],
            'tgl_izin' => (string) $row['tgl_izin'],
            'tgl_kembali' => (string) $row['tgl_kembali'],
            'status' => (string) $row['status'],
            'status_label' => self::statusLabel((string) $row['status']),
            'tahap' => self::tahap((string) $row['status']),
            'diajukan_pada' => $row['diajukan_pada'] === null ? null : (string) $row['diajukan_pada'],
            'pengaju_sendiri' => $sendiri,
            'pengaju' => $sendiri ? 'Anda' : 'Wali lain',
        ];
        if (!$sendiri) {
            // Wali lain: status ringkas saja — tanpa alasan, catatan, versi, atau riwayat.
            return $dasar + ['ringkas' => true];
        }

        return $dasar + [
            'ringkas' => false,
            'alasan' => (string) $row['alasan'],
            'catatan' => $row['catatan_wali'] === null ? null : (string) $row['catatan_wali'],
            'version' => (int) $row['version'],
            'pembimbing' => $row['pembimbing_nama'] === null ? null : (string) $row['pembimbing_nama'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function proyeksiPetugas(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'asal' => 'Wali',
            'santri' => ['id' => (int) $row['santri_id'], 'nis' => (string) $row['nis'], 'nama' => (string) $row['nama_santri'],
                'aktif' => (int) $row['santri_aktif'] === 1],
            'wali' => ['nama' => $row['wali_nama'] === null ? null : (string) $row['wali_nama'],
                'akun' => $row['pengaju_nama'] === null ? null : (string) $row['pengaju_nama']],
            'tgl_izin' => (string) $row['tgl_izin'],
            'tgl_kembali' => (string) $row['tgl_kembali'],
            'alasan' => (string) $row['alasan'],
            'catatan' => $row['catatan_wali'] === null ? null : (string) $row['catatan_wali'],
            'status' => (string) $row['status'],
            'status_label' => self::statusLabel((string) $row['status']),
            'tahap' => self::tahap((string) $row['status']),
            'version' => (int) $row['version'],
            'pembimbing' => [
                'pengurus_id' => $row['pembimbing_pengurus_id'] === null ? null : (int) $row['pembimbing_pengurus_id'],
                'nama' => $row['pembimbing_nama'] === null ? null : (string) $row['pembimbing_nama'],
                'sah' => (int) $row['pembimbing_sah'] === 1,
                'routing_kandidat' => $row['pembimbing_routing_kandidat'] === null ? null : (int) $row['pembimbing_routing_kandidat'],
                'routing_catatan' => $row['pembimbing_routing_catatan'] === null ? null : (string) $row['pembimbing_routing_catatan'],
                'ditetapkan_pada' => $row['pembimbing_ditetapkan_pada'] === null ? null : (string) $row['pembimbing_ditetapkan_pada'],
            ],
            'perlu_admin' => (string) $row['status'] === IzinWaliRepository::STATUS_PERLU_PENETAPAN_PEMBIMBING
                || ((string) $row['status'] === IzinWaliRepository::STATUS_MENUNGGU_PEMBIMBING && (int) $row['pembimbing_sah'] !== 1),
            'murobi_guru_id' => $row['murobi_guru_id'] === null ? null : (int) $row['murobi_guru_id'],
            'tahun_ajaran' => $row['tahun_ajaran'] === null ? null : (string) $row['tahun_ajaran'] . ' ' . (string) $row['semester'],
            'diajukan_pada' => $row['diajukan_pada'] === null ? null : (string) $row['diajukan_pada'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function riwayatPetugas(int $id): array
    {
        return array_map(static fn (array $r): array => [
            'peristiwa' => (string) $r['peristiwa'],
            'status_sebelum' => $r['status_sebelum'],
            'status_sesudah' => $r['status_sesudah'],
            'kapasitas' => $r['pelaku_kapasitas'],
            'pelaku' => $r['pelaku_nama'],
            'alasan' => $r['alasan'],
            'waktu' => (string) $r['created_at'],
        ], $this->repo->riwayat($id));
    }

    /**
     * Riwayat untuk wali pengaju: peristiwa dan waktu; catatan routing dan
     * alasan internal admin tidak ikut.
     *
     * @return array<int, array<string, mixed>>
     */
    private function riwayatWali(int $id): array
    {
        return array_map(static fn (array $r): array => [
            'peristiwa' => (string) $r['peristiwa'],
            'status_sesudah' => $r['status_sesudah'] === null ? null : self::statusLabel((string) $r['status_sesudah']),
            'waktu' => (string) $r['created_at'],
        ], $this->repo->riwayat($id));
    }

    // =======================================================================
    // Penjaga hak
    // =======================================================================

    /**
     * @param array<string, mixed> $user
     * @return array<string, bool>
     */
    private function peran(array $user): array
    {
        $userId = (int) $user['id'];
        $this->capabilities->forget($userId);
        // Role dibaca ulang dari basis data (akun aktif), bukan dari sesi/token.
        $roles = $this->repo->rolesAkun($userId);
        $probe = ['id' => $userId, 'roles' => $roles, 'guru_id' => $user['guru_id'] ?? null];

        return [
            self::PERAN_WALI => in_array('orang_tua', $roles, true) && $this->repo->waliAkun($userId) !== null,
            self::PERAN_PEMBIMBING => $this->capabilities->has($probe, Capabilities::PENGURUS),
            self::PERAN_ADMIN => $this->capabilities->has($probe, Capabilities::ADMIN),
        ];
    }

    /** @param array<string, mixed> $user */
    private function wajibWali(array $user): int
    {
        $waliId = $this->peran($user)[self::PERAN_WALI] ? $this->repo->waliAkun((int) $user['id']) : null;
        if ($waliId === null) {
            throw IzinException::forbidden('Pengajuan izin wali hanya tersedia untuk akun orang tua/wali yang aktif.');
        }

        return $waliId;
    }

    /** @param array<string, mixed> $user */
    private function wajibPembimbing(array $user): int
    {
        if (!$this->peran($user)[self::PERAN_PEMBIMBING]) {
            throw IzinException::forbidden('Antrean pengajuan wali hanya tersedia untuk pengurus pembimbing.');
        }

        return $this->pengurusId($user);
    }

    /** @param array<string, mixed> $user */
    private function wajibAdmin(array $user): void
    {
        if (!$this->peran($user)[self::PERAN_ADMIN]) {
            throw IzinException::forbidden('Hanya admin yang dapat memantau dan menetapkan pembimbing pengajuan wali.');
        }
    }

    /** @param array<string, mixed> $user */
    private function pengurusId(array $user): int
    {
        return (int) ($this->capabilities->linkedPengurusId((int) $user['id']) ?? 0);
    }

    private function wajibTerpasang(): void
    {
        if (!$this->terpasang()) {
            throw new IzinException('Fitur pengajuan izin wali belum dipasang (migrasi 030).', 503);
        }
    }

    // =======================================================================
    // Utilitas
    // =======================================================================

    /**
     * @template T
     * @param callable():T $operation
     * @return T
     */
    private function transactional(callable $operation): mixed
    {
        $this->repo->begin();
        try {
            $result = $operation();
            $this->repo->commit();

            return $result;
        } catch (Throwable $exception) {
            $this->repo->rollback();
            $errno = $this->repo->db()->errno;
            if (!$exception instanceof IzinException && in_array($errno, [1020, 1205, 1213], true)) {
                throw IzinException::conflict('Pengajuan sedang diproses permintaan lain. Coba lagi sesaat lagi.');
            }
            throw $exception;
        }
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    private function auditOrFail(string $action, int $entityId, ?array $before, ?array $after, int $actorUserId): void
    {
        if (!$this->audit->log($action, 'izin_pengajuan', $entityId, $before, $after, $actorUserId)) {
            throw new RuntimeException('Audit pengajuan wali tidak dapat disimpan. Transaksi dibatalkan.');
        }
    }

    /**
     * @param array{ip:?string, user_agent:?string} $meta
     * @return array<string, mixed>
     */
    private function historyRow(int $id, string $peristiwa, ?string $sebelum, ?string $sesudah, int $pelaku, string $kapasitas, ?string $alasan, array $meta): array
    {
        $ip = $meta['ip'] ?? null;
        $ua = $meta['user_agent'] ?? null;

        return [
            'pengajuan_id' => $id,
            'peristiwa' => $peristiwa,
            'status_sebelum' => $sebelum,
            'status_sesudah' => $sesudah,
            'pelaku_user_id' => $pelaku,
            'pelaku_kapasitas' => $kapasitas,
            'alasan' => $alasan === null ? null : mb_substr($alasan, 0, 2000),
            'ip_address' => $ip === null ? null : substr((string) $ip, 0, 45),
            'user_agent' => $ua === null ? null : substr((string) $ua, 0, 255),
        ];
    }

    private function requireDate(mixed $value, string $label): string
    {
        $value = is_string($value) ? trim($value) : '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw IzinException::invalid($label . ' wajib diisi dengan format tanggal YYYY-MM-DD.');
        }
        [$y, $m, $d] = array_map('intval', explode('-', $value));
        if (!checkdate($m, $d, $y)) {
            throw IzinException::invalid($label . ' bukan tanggal yang valid.');
        }

        return $value;
    }

    private function requireText(mixed $value, string $label, int $min, int $max): string
    {
        $value = is_string($value) ? trim(preg_replace('/\s+/u', ' ', $value) ?? '') : '';
        if (mb_strlen($value) < $min) {
            throw IzinException::invalid($label . ' wajib diisi minimal ' . $min . ' karakter.');
        }
        if (mb_strlen($value) > $max) {
            throw IzinException::invalid($label . ' maksimal ' . $max . ' karakter.');
        }

        return $value;
    }

    private function optionalText(mixed $value, string $label, int $max): ?string
    {
        if ($value !== null && !is_string($value)) {
            throw IzinException::invalid($label . ' tidak valid.');
        }
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $max) {
            throw IzinException::invalid($label . ' maksimal ' . $max . ' karakter.');
        }

        return $value;
    }

    private function pageOf(mixed $page): int
    {
        return is_numeric($page) ? max(1, min(10000, (int) $page)) : 1;
    }

    private function today(): string
    {
        return date('Y-m-d');
    }
}
