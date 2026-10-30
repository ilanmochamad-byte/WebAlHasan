<?php

declare(strict_types=1);

namespace App\Izin;

use App\Audit\AuditLogger;
use App\Auth\Capabilities;
use App\Database\Schema;
use RuntimeException;
use Throwable;

/**
 * Pengajuan izin oleh orang tua/wali melalui pembimbing (PRD V7 Fase 1–2).
 *
 * Satu pintu untuk website dan REST API. Fase 1: wali berelasi aktif membuat
 * SATU pengajuan kanonis dan membaca statusnya; routing pembimbing (tepat satu
 * sah → antrean pembimbing, nol/lebih → antrean admin); pemantauan admin dan
 * penetapan pembimbing sah. Fase 2: pembimbing meminta perbaikan, menolak,
 * atau meneruskan; wali pengaju memperbaiki/mengirim ulang saat diminta atau
 * membatalkan sebelum keputusan akhir; penerusan menjalankan routing murobi V2
 * pada baris yang SAMA, lalu penetapan murobi, keputusan murobi/admin
 * pengganti, dan koreksi tetap `IzinWorkflowService` V2. Penolakan pembimbing
 * berstatus `Ditolak Pembimbing` dan tidak pernah menulis `izin_keputusan`.
 * Fase 3: setiap peralihan tahap mengantre notifikasi V7 (`IzinWaliNotifikasi`)
 * di dalam transaksi yang sama; kegagalan antrean tidak membatalkan mutasi.
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
    public const OP_TINJAUAN = 'izin.wali.tinjauan';
    public const OP_PERBAIKAN = 'izin.wali.perbaikan';
    public const OP_BATAL = 'izin.wali.batal';

    public const PERAN_WALI = 'wali';
    public const PERAN_PEMBIMBING = 'pembimbing';
    public const PERAN_ADMIN = 'admin';
    public const PERAN_MUROBI = 'murobi';

    /** Kode tindakan pembimbing (web/API) → nilai tersimpan. */
    public const TINDAKAN = [
        'minta_perbaikan' => IzinWaliRepository::TINJAUAN_MINTA_PERBAIKAN,
        'tolak' => IzinWaliRepository::TINJAUAN_TOLAK,
        'teruskan' => IzinWaliRepository::TINJAUAN_TERUSKAN,
    ];

    public function __construct(
        private IzinWaliRepository $repo,
        private IzinWriteRepository $write,
        private IzinRepository $read,
        private PembimbingRouter $router,
        private IzinIdempotency $idempotency,
        private Capabilities $capabilities,
        private AuditLogger $audit,
        private ?IzinRouter $murobiRouter = null,
        private ?IzinWaliNotifikasi $notifikasi = null
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
            'murobi' => $peran[self::PERAN_MUROBI],
            'tinjauan_terpasang' => $this->repo->fase2Terpasang(),
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
            $this->notifikasi()->antrekanTahapPembimbing($id, $userId);

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
            $this->notifikasi()->antrekanTahapPembimbing($id, $userId);

            return $response + ['idempotent_replay' => false, 'status_http' => 200];
        });
    }

    // =======================================================================
    // 2a. Tinjauan pembimbing (Fase 2)
    // =======================================================================

    /**
     * Minta perbaikan, tolak, atau teruskan oleh pembimbing tujuan yang masih
     * sah. Penolakan menutup pengajuan pada tahap pembimbing (status `Ditolak
     * Pembimbing`, melepas rentang tanggal) TANPA baris `izin_keputusan`.
     * Penerusan menjalankan routing murobi V2 pada baris yang sama: tepat satu
     * murobi → `Diajukan`; nol/lebih → `Perlu Penetapan Admin`.
     *
     * @param array<string, mixed> $user
     * @param array{ip:?string, user_agent:?string} $meta
     * @return array<string, mixed>
     */
    public function tinjau(array $user, int $id, mixed $tindakan, mixed $catatan, mixed $expectedVersion, ?string $idempotencyKey, array $meta): array
    {
        $this->wajibFase2();
        $kode = is_string($tindakan) ? $tindakan : '';
        if (!isset(self::TINDAKAN[$kode])) {
            throw IzinException::invalid('Tindakan tinjauan harus minta_perbaikan, tolak, atau teruskan.');
        }
        $tindakan = self::TINDAKAN[$kode];
        $this->wajibPembimbing($user);
        $userId = (int) $user['id'];
        $key = $this->idempotency->normalizeKey($idempotencyKey);
        $catatan = match ($tindakan) {
            IzinWaliRepository::TINJAUAN_MINTA_PERBAIKAN => $this->requireText($catatan, 'Catatan perbaikan untuk wali', 3, 2000),
            IzinWaliRepository::TINJAUAN_TOLAK => $this->requireText($catatan, 'Alasan penolakan', 3, 2000),
            default => $this->optionalText($catatan, 'Catatan penerusan', 1000),
        };
        $version = $this->requireVersion($expectedVersion);
        $payload = ['pengajuan_id' => $id, 'tindakan' => $tindakan, 'catatan' => $catatan, 'version' => $version];
        $onDate = $this->today();

        return $this->transactional(function () use ($userId, $id, $tindakan, $catatan, $version, $key, $payload, $onDate, $meta): array {
            $this->repo->kunciAkun($userId);
            // Hak dibaca ulang SESUDAH kunci akun: role/penugasan yang dicabut
            // selagi permintaan menunggu tidak lagi memberi hak (sesi/token lama).
            $pengurusId = $this->wajibPembimbing(['id' => $userId]);
            $replay = $this->idempotency->begin($userId, self::OP_TINJAUAN, $key, $payload);
            if ($replay !== null) {
                $row = $this->repo->find($id, $onDate);
                if ($row === null || (int) ($row['pembimbing_pengurus_id'] ?? 0) !== $pengurusId || (int) $row['pembimbing_sah'] !== 1) {
                    throw IzinException::notFound();
                }

                return $replay['response'] + ['idempotent_replay' => true, 'status_http' => 200];
            }

            $row = $this->write->lockPengajuan($id);
            if ($row === null || (string) ($row['asal_pengajuan'] ?? '') !== 'Wali'
                || $row['pembimbing_pengurus_id'] === null || (int) $row['pembimbing_pengurus_id'] !== $pengurusId) {
                throw IzinException::notFound();
            }
            // Kelayakan dihitung ulang sesudah baris dikunci; penugasan dikunci
            // baca sampai transaksi selesai, sehingga pencabutan bersamaan
            // menunggu atau membuat tindakan ini ditolak.
            $santriId = (int) $row['santri_id'];
            $assignmentId = $this->router->assignmentFor($pengurusId, $santriId, $onDate, true);
            if ($assignmentId === null) {
                throw IzinException::notFound();
            }
            $statusSebelum = (string) $row['status'];
            if ($statusSebelum !== IzinWaliRepository::STATUS_MENUNGGU_PEMBIMBING) {
                throw IzinException::conflict('Pengajuan berstatus ' . self::statusLabel($statusSebelum) . ' sehingga tidak lagi menunggu tinjauan pembimbing.');
            }
            if ((int) $row['version'] !== $version) {
                throw IzinException::conflict('Pengajuan sudah diperbarui pihak lain (versi ' . (int) $row['version'] . '). Muat ulang halaman lalu ulangi.');
            }

            $murobiGuruId = null;
            $jumlahMurobi = null;
            $routing = null;
            if ($tindakan === IzinWaliRepository::TINJAUAN_TERUSKAN) {
                $santri = $this->read->santriBasic($santriId);
                if ($santri === null || (int) $santri['is_active'] !== 1 || $santri['archived_at'] !== null) {
                    throw IzinException::invalid('Santri tidak aktif sehingga pengajuan tidak dapat diteruskan.');
                }
                // Routing murobi V2 yang SAMA dengan jalur pengurus. Pemisahan
                // tugas: guru milik akun wali pengaju, pembimbing yang sedang
                // meneruskan, dan peninjau sebelumnya tidak menjadi murobi tujuan.
                $routing = $this->murobiRouter()->resolve(
                    $santriId,
                    $onDate,
                    (new PemisahanTugas($this->write->db()))->guruTerlibat($id, [$userId])
                );
                $statusSesudah = (string) $routing['status'];
                $murobiGuruId = $routing['murobi_guru_id'];
                $jumlahMurobi = (int) $routing['jumlah'];
                $ok = $this->repo->applyTeruskan($id, $statusSesudah, $murobiGuruId, $jumlahMurobi, mb_substr((string) $routing['catatan'], 0, 255), $version);
            } else {
                $statusSesudah = $tindakan === IzinWaliRepository::TINJAUAN_TOLAK
                    ? IzinWaliRepository::STATUS_DITOLAK_PEMBIMBING
                    : IzinWaliRepository::STATUS_PERLU_PERBAIKAN;
                $ok = $this->repo->applyTinjauan($id, $statusSesudah, $version);
            }
            if (!$ok) {
                throw IzinException::conflict('Pengajuan sudah berubah sejak halaman ini dimuat. Muat ulang lalu coba lagi.');
            }

            $tinjauanId = $this->repo->insertTinjauan([
                'pengajuan_id' => $id,
                'tindakan' => $tindakan,
                'pengurus_id' => $pengurusId,
                'assignment_id' => $assignmentId,
                'actor' => $userId,
                'catatan' => $catatan,
                'status_sebelum' => $statusSebelum,
                'status_sesudah' => $statusSesudah,
                'versi_sebelum' => $version,
                'isi_hash' => self::isiHash($row['tgl_izin'], $row['tgl_kembali'], $row['alasan'], $row['catatan_wali']),
                'murobi_guru_id' => $murobiGuruId,
                'murobi_routing_kandidat' => $jumlahMurobi,
            ]);
            $peristiwa = match ($tindakan) {
                IzinWaliRepository::TINJAUAN_MINTA_PERBAIKAN => 'tinjauan_minta_perbaikan',
                IzinWaliRepository::TINJAUAN_TOLAK => 'tinjauan_ditolak_pembimbing',
                default => 'tinjauan_diteruskan',
            };
            $this->write->insertRiwayat($this->historyRow($id, $peristiwa, $statusSebelum, $statusSesudah, $userId, 'Pembimbing', $catatan, $meta));
            if ($routing !== null) {
                $this->write->insertRiwayat($this->historyRow(
                    $id,
                    $jumlahMurobi === 1 ? 'routing_otomatis' : 'routing_perlu_admin',
                    $statusSesudah,
                    $statusSesudah,
                    $userId,
                    'Sistem',
                    (string) $routing['catatan'],
                    $meta
                ));
            }

            $response = [
                'id' => $id,
                'tindakan' => array_search($tindakan, self::TINDAKAN, true),
                'status' => $statusSesudah,
                'tahap' => self::tahap($statusSesudah),
                'version' => $version + 1,
                'tinjauan_id' => $tinjauanId,
            ];
            if ($routing !== null) {
                $response['murobi_guru_id'] = $murobiGuruId;
                $response['perlu_penetapan_murobi'] = $jumlahMurobi !== 1;
            }
            $this->idempotency->complete($userId, self::OP_TINJAUAN, $key, 200, $response, $id);
            $this->auditOrFail(match ($tindakan) {
                IzinWaliRepository::TINJAUAN_MINTA_PERBAIKAN => 'izin_wali_diminta_perbaikan',
                IzinWaliRepository::TINJAUAN_TOLAK => 'izin_wali_ditolak_pembimbing',
                default => 'izin_wali_diteruskan',
            }, $id, ['status' => $statusSebelum, 'version' => $version], [
                'status' => $statusSesudah,
                'version' => $version + 1,
                'tinjauan_id' => $tinjauanId,
                'pembimbing_pengurus_id' => $pengurusId,
                'pembimbing_assignment_id' => $assignmentId,
                'murobi_guru_id' => $murobiGuruId,
                'jumlah_kandidat_murobi' => $jumlahMurobi,
                'catatan_terisi' => $catatan !== null,
            ], $userId);
            if ($tindakan === IzinWaliRepository::TINJAUAN_TERUSKAN) {
                $this->notifikasi()->antrekan(IzinWaliNotifikasi::DITERUSKAN, $id, $userId);
                $this->notifikasi()->antrekan($jumlahMurobi === 1 ? IzinWaliNotifikasi::MASUK_MUROBI : IzinWaliNotifikasi::PERLU_PENETAPAN_MUROBI, $id, $userId);
            } else {
                $this->notifikasi()->antrekan($tindakan === IzinWaliRepository::TINJAUAN_TOLAK
                    ? IzinWaliNotifikasi::DITOLAK_PEMBIMBING : IzinWaliNotifikasi::PERLU_PERBAIKAN, $id, $userId);
            }

            return $response + ['idempotent_replay' => false, 'status_http' => 200];
        });
    }

    // =======================================================================
    // 2b. Perbaikan dan pembatalan oleh wali pengaju (Fase 2)
    // =======================================================================

    /**
     * Wali pengaju memperbaiki isi dan mengirim ulang — hanya saat pembimbing
     * meminta perbaikan. Isi sebelum dan sesudah disimpan pada
     * `izin_wali_perbaikan`; rentang baru diperiksa ulang terhadap benturan
     * lintas jalur dengan kunci santri yang sama seperti pembuatan.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input tgl_izin, tgl_kembali, alasan, catatan
     * @param array{ip:?string, user_agent:?string} $meta
     * @return array<string, mixed>
     */
    public function perbaiki(array $user, int $id, array $input, mixed $expectedVersion, ?string $idempotencyKey, array $meta): array
    {
        $this->wajibFase2();
        $this->wajibWali($user);
        $userId = (int) $user['id'];
        $key = $this->idempotency->normalizeKey($idempotencyKey);
        $tglIzin = $this->requireDate($input['tgl_izin'] ?? null, 'Tanggal izin');
        $tglKembali = $this->requireDate($input['tgl_kembali'] ?? null, 'Tanggal kembali');
        if ($tglKembali < $tglIzin) {
            throw IzinException::invalid('Tanggal kembali tidak boleh mendahului tanggal izin.');
        }
        $alasan = $this->requireText($input['alasan'] ?? null, 'Alasan izin', 3, 2000);
        $catatan = $this->optionalText($input['catatan'] ?? null, 'Catatan', 1000);
        $version = $this->requireVersion($expectedVersion);
        $payload = ['pengajuan_id' => $id, 'tgl_izin' => $tglIzin, 'tgl_kembali' => $tglKembali, 'alasan' => $alasan, 'catatan' => $catatan, 'version' => $version];
        $onDate = $this->today();

        return $this->transactional(function () use ($userId, $id, $tglIzin, $tglKembali, $alasan, $catatan, $version, $key, $payload, $onDate, $meta): array {
            $this->repo->kunciAkun($userId);
            $replay = $this->idempotency->begin($userId, self::OP_PERBAIKAN, $key, $payload);
            [$waliId, $row] = $this->wajibPengaju($userId, $id, $onDate, 'memperbaiki');
            if ($replay !== null) {
                return $replay['response'] + ['idempotent_replay' => true, 'status_http' => 200];
            }

            // Kunci santri LEBIH DULU (urutan sama dengan pembuatan kedua jalur),
            // baru baris pengajuan; pemeriksaan benturan rentang baru aman.
            $santriId = (int) $row['santri_id'];
            $santri = $this->write->lockSantri($santriId);
            $locked = $this->write->lockPengajuan($id);
            if ($locked === null) {
                throw IzinException::notFound();
            }
            $statusSebelum = (string) $locked['status'];
            if ($statusSebelum !== IzinWaliRepository::STATUS_PERLU_PERBAIKAN) {
                throw IzinException::conflict('Pengajuan berstatus ' . self::statusLabel($statusSebelum) . '; perbaikan hanya dapat dikirim saat pembimbing memintanya.');
            }
            if ((int) $locked['version'] !== $version) {
                throw IzinException::conflict('Pengajuan sudah diperbarui pihak lain (versi ' . (int) $locked['version'] . '). Muat ulang halaman lalu ulangi.');
            }
            if ($santri === null || (int) $santri['is_active'] !== 1 || $santri['archived_at'] !== null) {
                throw IzinException::invalid('Santri tidak aktif sehingga pengajuan tidak dapat dikirim ulang.');
            }
            $permintaan = $this->repo->permintaanPerbaikanTerbuka($id);
            if ($permintaan === null) {
                throw IzinException::conflict('Tidak ada permintaan perbaikan yang terbuka untuk pengajuan ini.');
            }
            $sebelum = [
                'tgl_izin' => (string) $locked['tgl_izin'],
                'tgl_kembali' => (string) $locked['tgl_kembali'],
                'alasan' => (string) $locked['alasan'],
                'catatan' => $locked['catatan_wali'] === null ? null : (string) $locked['catatan_wali'],
            ];
            $sesudah = ['tgl_izin' => $tglIzin, 'tgl_kembali' => $tglKembali, 'alasan' => $alasan, 'catatan' => $catatan];
            $hashSebelum = self::isiHash($sebelum['tgl_izin'], $sebelum['tgl_kembali'], $sebelum['alasan'], $sebelum['catatan']);
            $hashSesudah = self::isiHash($tglIzin, $tglKembali, $alasan, $catatan);
            if (hash_equals($hashSebelum, $hashSesudah)) {
                throw IzinException::invalid('Isi pengajuan belum diubah. Perbaiki tanggal, alasan, atau catatan sesuai permintaan pembimbing.');
            }
            $overlap = $this->write->findOverlap($santriId, $tglIzin, $tglKembali, $id);
            if ($overlap !== null) {
                throw IzinException::conflict(sprintf(
                    'Anak ini sudah memiliki pengajuan izin yang masih berjalan pada rentang %s s.d. %s yang bersinggungan.',
                    (string) $overlap['tgl_izin'],
                    (string) $overlap['tgl_kembali']
                ));
            }
            if (!$this->repo->applyPerbaikan($id, $tglIzin, $tglKembali, $alasan, $catatan, $version)) {
                throw IzinException::conflict('Pengajuan sudah berubah sejak halaman ini dimuat. Muat ulang lalu coba lagi.');
            }
            $perbaikanId = $this->repo->insertPerbaikan([
                'pengajuan_id' => $id,
                'tinjauan_id' => (int) $permintaan['id'],
                'wali_id' => $waliId,
                'actor' => $userId,
                'sebelum' => $sebelum,
                'sesudah' => $sesudah,
                'hash_sebelum' => $hashSebelum,
                'hash_sesudah' => $hashSesudah,
                'versi_sebelum' => $version,
            ]);
            // Riwayat tidak menyalin isi izin; isi tiap versi ada di tabel perbaikan.
            $this->write->insertRiwayat($this->historyRow($id, 'perbaikan_wali_dikirim', $statusSebelum,
                IzinWaliRepository::STATUS_MENUNGGU_PEMBIMBING, $userId, 'Wali', null, $meta));

            $response = [
                'id' => $id,
                'status' => IzinWaliRepository::STATUS_MENUNGGU_PEMBIMBING,
                'tahap' => self::tahap(IzinWaliRepository::STATUS_MENUNGGU_PEMBIMBING),
                'version' => $version + 1,
                'perbaikan_id' => $perbaikanId,
            ];
            $this->idempotency->complete($userId, self::OP_PERBAIKAN, $key, 200, $response, $id);
            $this->auditOrFail('izin_wali_perbaikan_dikirim', $id,
                ['status' => $statusSebelum, 'version' => $version, 'isi_hash' => $hashSebelum],
                [
                    'status' => IzinWaliRepository::STATUS_MENUNGGU_PEMBIMBING,
                    'version' => $version + 1,
                    'isi_hash' => $hashSesudah,
                    'perbaikan_id' => $perbaikanId,
                    'tinjauan_id' => (int) $permintaan['id'],
                    'rentang_berubah' => $sebelum['tgl_izin'] !== $tglIzin || $sebelum['tgl_kembali'] !== $tglKembali,
                ],
                $userId
            );
            $this->notifikasi()->antrekanTahapPembimbing($id, $userId);

            return $response + ['idempotent_replay' => false, 'status_http' => 200];
        });
    }

    /**
     * Wali pengaju membatalkan beralasan pada tahap mana pun sebelum keputusan
     * murobi/admin pengganti. Bersaing dengan penerusan atau keputusan: baris
     * pengajuan dikunci dan pembaruan bersyarat versi+status, sehingga hanya
     * satu hasil terminal yang berlaku.
     *
     * @param array<string, mixed> $user
     * @param array{ip:?string, user_agent:?string} $meta
     * @return array<string, mixed>
     */
    public function batalkan(array $user, int $id, mixed $alasan, mixed $expectedVersion, ?string $idempotencyKey, array $meta): array
    {
        $this->wajibFase2();
        $this->wajibWali($user);
        $userId = (int) $user['id'];
        $key = $this->idempotency->normalizeKey($idempotencyKey);
        $alasan = $this->requireText($alasan, 'Alasan pembatalan', 3, 1000);
        $version = $this->requireVersion($expectedVersion);
        $payload = ['pengajuan_id' => $id, 'alasan' => $alasan, 'version' => $version];
        $onDate = $this->today();

        return $this->transactional(function () use ($userId, $id, $alasan, $version, $key, $payload, $onDate, $meta): array {
            $this->repo->kunciAkun($userId);
            $replay = $this->idempotency->begin($userId, self::OP_BATAL, $key, $payload);
            $this->wajibPengaju($userId, $id, $onDate, 'membatalkan');
            if ($replay !== null) {
                return $replay['response'] + ['idempotent_replay' => true, 'status_http' => 200];
            }

            $locked = $this->write->lockPengajuan($id);
            if ($locked === null) {
                throw IzinException::notFound();
            }
            $statusSebelum = (string) $locked['status'];
            if (!in_array($statusSebelum, IzinWaliRepository::STATUS_DAPAT_DIBATALKAN, true)) {
                throw IzinException::conflict('Pengajuan berstatus ' . self::statusLabel($statusSebelum) . ' sehingga tidak dapat dibatalkan lagi.');
            }
            if ((int) $locked['version'] !== $version) {
                throw IzinException::conflict('Pengajuan sudah diperbarui pihak lain (versi ' . (int) $locked['version'] . '). Muat ulang halaman lalu ulangi.');
            }
            if (!$this->repo->applyBatal($id, $userId, $alasan, $version)) {
                throw IzinException::conflict('Pengajuan sudah diputus atau berubah, sehingga tidak dapat dibatalkan.');
            }
            $this->write->insertRiwayat($this->historyRow($id, 'pembatalan_wali', $statusSebelum, 'Dibatalkan', $userId, 'Wali', $alasan, $meta));

            $response = ['id' => $id, 'status' => 'Dibatalkan', 'tahap' => self::tahap('Dibatalkan'), 'version' => $version + 1];
            $this->idempotency->complete($userId, self::OP_BATAL, $key, 200, $response, $id);
            $this->auditOrFail('izin_wali_dibatalkan', $id, ['status' => $statusSebelum, 'version' => $version], [
                'status' => 'Dibatalkan',
                'version' => $version + 1,
                'tahap_sebelum' => self::tahap($statusSebelum),
                'murobi_guru_id' => $locked['murobi_guru_id'] === null ? null : (int) $locked['murobi_guru_id'],
            ], $userId);
            $this->notifikasi()->antrekan(IzinWaliNotifikasi::DIBATALKAN, $id, $userId);

            return $response + ['idempotent_replay' => false, 'status_http' => 200];
        });
    }

    /**
     * Jejak asal untuk halaman rincian V2 (murobi/admin) — dipanggil HANYA
     * sesudah layanan V2 mengizinkan pembaca melihat pengajuan itu. Null bila
     * bukan pengajuan wali atau skema V7 belum ada.
     *
     * @return array<string, mixed>|null
     */
    public function asalWali(int $id): ?array
    {
        if (!$this->terpasang()) {
            return null;
        }
        $row = $this->repo->find($id, $this->today());
        if ($row === null) {
            return null;
        }
        $diteruskan = null;
        if ($this->repo->fase2Terpasang()) {
            foreach ($this->repo->tinjauan($id) as $t) {
                if ((string) $t['tindakan'] === IzinWaliRepository::TINJAUAN_TERUSKAN) {
                    $diteruskan = $t;
                }
            }
        }

        return [
            'id' => (int) $row['id'],
            'pembimbing' => $diteruskan['pembimbing'] ?? ($row['pembimbing_nama'] === null ? null : (string) $row['pembimbing_nama']),
            'diteruskan_pada' => $diteruskan['ditinjau_pada'] ?? null,
            'catatan_penerusan' => $diteruskan['catatan'] ?? null,
        ];
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
     * Fase 2: pengajuan yang sudah keluar dari antrean pembimbing (diminta
     * perbaikan, ditolak, diteruskan, diputus, dibatalkan) — status terkini.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function riwayatPembimbing(array $user, mixed $page = 1): array
    {
        $this->wajibTerpasang();
        $pengurusId = $this->wajibPembimbing($user);
        [$page, $perPage] = [$this->pageOf($page), 20];
        $hasil = $this->repo->riwayatPembimbing($pengurusId, $this->today(), $page, $perPage);

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
     *   admin → lengkap + riwayat penetapan/tinjauan/versi isi; pembimbing
     *   tujuan yang masih sah → lengkap + tindakan tinjauan; murobi tujuan
     *   sesudah penerusan → lengkap (keputusan lewat halaman/API V2); wali
     *   pengaju → isi, catatan pembimbing, hasil, tindakan perbaiki/batal;
     *   wali lain berelasi aktif → status ringkas; selain itu 404.
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
        $fase2 = $this->repo->fase2Terpasang();
        $status = (string) $row['status'];
        $statusV2 = in_array($status, IzinRepository::STATUSES, true);
        $belumDiputus = in_array($status, IzinWriteRepository::STATUS_BELUM_DIPUTUS, true);
        // Pemisahan tugas: tampilan saja; layanan V2 menolak keputusan akun terlibat.
        $terlibat = (new PemisahanTugas($this->write->db()))->terlibat($userId, $id);
        $jejakPetugas = fn (): array => [
            'riwayat' => $this->riwayatPetugas($id),
            'tinjauan' => $fase2 ? $this->tinjauanPetugas($id) : [],
            'perbaikan' => $fase2 ? $this->perbaikanProyeksi($id) : [],
            'keputusan' => $this->keputusanProyeksi($id, true),
        ];

        if ($peran[self::PERAN_ADMIN]) {
            return [
                'peran_akses' => self::PERAN_ADMIN,
                'pengajuan' => $this->proyeksiPetugas($row),
                'penetapan' => $this->repo->penetapan($id),
                // Fase 3: jejak antrean/pengiriman notifikasi (metadata, tanpa isi).
                'notifikasi' => $this->notifikasi()->jejak($id),
                // Admin memantau dan menetapkan; tidak menggantikan tinjauan
                // pembimbing. Penetapan murobi dan keputusan pengganti memakai
                // halaman/API V2 pada ID yang sama.
                'tindakan' => [
                    'tetapkan_pembimbing' => in_array($status, IzinWaliRepository::STATUS_PENETAPAN, true),
                    'tetapkan_murobi' => $belumDiputus,
                    'putuskan_pengganti' => $belumDiputus && !$terlibat,
                    'buka_v2' => $statusV2,
                ],
            ] + $jejakPetugas();
        }
        if ($peran[self::PERAN_PEMBIMBING]
            && $row['pembimbing_pengurus_id'] !== null
            && (int) $row['pembimbing_pengurus_id'] === $this->pengurusId($user)
            && (int) $row['pembimbing_sah'] === 1) {
            $menunggu = $fase2 && $status === IzinWaliRepository::STATUS_MENUNGGU_PEMBIMBING;

            return [
                'peran_akses' => self::PERAN_PEMBIMBING,
                'pengajuan' => $this->proyeksiPetugas($row),
                'tindakan' => [
                    'minta_perbaikan' => $menunggu,
                    'tolak' => $menunggu,
                    'teruskan' => $menunggu,
                ],
            ] + $jejakPetugas();
        }
        if ($peran[self::PERAN_MUROBI] && $statusV2 && $row['murobi_guru_id'] !== null) {
            $guruId = $this->repo->guruAkun($userId);
            if ($guruId !== null && (int) $row['murobi_guru_id'] === $guruId && (int) $row['murobi_sah'] === 1) {
                return [
                    'peran_akses' => self::PERAN_MUROBI,
                    'pengajuan' => $this->proyeksiPetugas($row),
                    'tindakan' => [
                        'putuskan' => $status === IzinRouter::STATUS_TERARAH && !$terlibat,
                        'buka_v2' => true,
                    ],
                ] + $jejakPetugas();
            }
        }
        if ($peran[self::PERAN_WALI]) {
            $waliId = $this->repo->waliAkun($userId);
            if ($waliId !== null && $this->repo->relasiAktif($waliId, (int) $row['santri_id'])) {
                $proyeksi = $this->proyeksiWali($row, $userId, $waliId);
                if (!$proyeksi['pengaju_sendiri']) {
                    // Wali lain: status ringkas tanpa catatan, alasan, atau tindakan.
                    return ['peran_akses' => self::PERAN_WALI, 'pengajuan' => $proyeksi, 'riwayat' => [], 'tindakan' => []];
                }

                return [
                    'peran_akses' => self::PERAN_WALI,
                    'pengajuan' => $proyeksi,
                    'riwayat' => $this->riwayatWali($id),
                    'tinjauan' => $fase2 ? $this->tinjauanWali($id) : [],
                    'perbaikan' => $fase2 ? $this->perbaikanProyeksi($id) : [],
                    'keputusan' => $this->keputusanProyeksi($id, false),
                    'pembatalan' => $status === 'Dibatalkan' && $row['dibatalkan_pada'] !== null
                        ? ['alasan' => $row['alasan_pembatalan'] === null ? null : (string) $row['alasan_pembatalan'], 'waktu' => (string) $row['dibatalkan_pada']]
                        : null,
                    'tindakan' => [
                        'perbaiki' => $fase2 && $status === IzinWaliRepository::STATUS_PERLU_PERBAIKAN,
                        'batalkan' => $fase2 && in_array($status, IzinWaliRepository::STATUS_DAPAT_DIBATALKAN, true),
                    ],
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
            'murobi' => ($row['murobi_nama'] ?? null) === null ? null : (string) $row['murobi_nama'],
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
                || (string) $row['status'] === IzinRouter::STATUS_PERLU_ADMIN
                || ((string) $row['status'] === IzinWaliRepository::STATUS_MENUNGGU_PEMBIMBING && (int) $row['pembimbing_sah'] !== 1)
                || ((string) $row['status'] === IzinRouter::STATUS_TERARAH && (int) $row['murobi_sah'] !== 1),
            'murobi_guru_id' => $row['murobi_guru_id'] === null ? null : (int) $row['murobi_guru_id'],
            'murobi' => [
                'nama' => ($row['murobi_nama'] ?? null) === null ? null : (string) $row['murobi_nama'],
                'sah' => (int) $row['murobi_sah'] === 1,
                'routing_kandidat' => ($row['routing_kandidat'] ?? null) === null ? null : (int) $row['routing_kandidat'],
                'routing_catatan' => ($row['routing_catatan'] ?? null) === null ? null : (string) $row['routing_catatan'],
            ],
            'keputusan_hasil' => ($row['keputusan_hasil'] ?? null) === null ? null : (string) $row['keputusan_hasil'],
            'pembatalan' => ($row['dibatalkan_pada'] ?? null) === null ? null : [
                'alasan' => $row['alasan_pembatalan'] === null ? null : (string) $row['alasan_pembatalan'],
                'waktu' => (string) $row['dibatalkan_pada'],
            ],
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function tinjauanPetugas(int $id): array
    {
        return array_map(static fn (array $t): array => [
            'tindakan' => (string) array_search((string) $t['tindakan'], self::TINDAKAN, true),
            'tindakan_label' => (string) $t['tindakan'],
            'catatan' => $t['catatan'] === null ? null : (string) $t['catatan'],
            'pembimbing' => (string) $t['pembimbing'],
            'peninjau' => $t['peninjau'] === null ? null : (string) $t['peninjau'],
            'status_sesudah' => (string) $t['status_sesudah'],
            'versi_sebelum' => (int) $t['versi_sebelum'],
            'versi_sesudah' => (int) $t['versi_sesudah'],
            'murobi' => $t['murobi'] === null ? null : (string) $t['murobi'],
            'murobi_routing_kandidat' => $t['murobi_routing_kandidat'] === null ? null : (int) $t['murobi_routing_kandidat'],
            'waktu' => (string) $t['ditinjau_pada'],
        ], $this->repo->tinjauan($id));
    }

    /**
     * Untuk wali pengaju: catatan perbaikan dan alasan penolakan pembimbing
     * ditampilkan; catatan penerusan untuk murobi tidak.
     *
     * @return array<int, array<string, mixed>>
     */
    private function tinjauanWali(int $id): array
    {
        return array_map(static fn (array $t): array => [
            'tindakan' => (string) array_search((string) $t['tindakan'], self::TINDAKAN, true),
            'tindakan_label' => (string) $t['tindakan'],
            'catatan' => (string) $t['tindakan'] === IzinWaliRepository::TINJAUAN_TERUSKAN || $t['catatan'] === null ? null : (string) $t['catatan'],
            'pembimbing' => (string) $t['pembimbing'],
            'waktu' => (string) $t['ditinjau_pada'],
        ], $this->repo->tinjauan($id));
    }

    /**
     * Versi isi yang pernah dikirim ulang wali pengaju.
     *
     * @return array<int, array<string, mixed>>
     */
    private function perbaikanProyeksi(int $id): array
    {
        return array_map(static fn (array $w): array => [
            'versi_sebelum' => (int) $w['versi_sebelum'],
            'versi_sesudah' => (int) $w['versi_sesudah'],
            'sebelum' => ['tgl_izin' => (string) $w['tgl_izin_sebelum'], 'tgl_kembali' => (string) $w['tgl_kembali_sebelum'],
                'alasan' => (string) $w['alasan_sebelum'], 'catatan' => $w['catatan_sebelum'] === null ? null : (string) $w['catatan_sebelum']],
            'sesudah' => ['tgl_izin' => (string) $w['tgl_izin_sesudah'], 'tgl_kembali' => (string) $w['tgl_kembali_sesudah'],
                'alasan' => (string) $w['alasan_sesudah'], 'catatan' => $w['catatan_sesudah'] === null ? null : (string) $w['catatan_sesudah']],
            'pengirim' => $w['pengirim'] === null ? null : (string) $w['pengirim'],
            'waktu' => (string) $w['dikirim_pada'],
        ], $this->repo->perbaikan($id));
    }

    /**
     * Keputusan akhir V2 (murobi atau admin pengganti). Penolakan pembimbing
     * tidak pernah muncul di sini karena tidak menulis `izin_keputusan`.
     *
     * @return array<string, mixed>|null
     */
    private function keputusanProyeksi(int $id, bool $petugas): ?array
    {
        $k = $this->repo->keputusan($id);
        if ($k === null) {
            return null;
        }
        $hasil = [
            'hasil' => (string) $k['hasil'],
            'kapasitas' => (string) $k['kapasitas'],
            'oleh' => (string) $k['kapasitas'] === IzinWorkflowService::KAPASITAS_ADMIN_PENGGANTI ? 'Admin pengganti' : 'Murobi',
            'alasan' => (string) $k['alasan'],
            'waktu' => (string) $k['diputus_pada'],
            'dikoreksi' => (int) $k['jumlah_koreksi'] > 0,
        ];
        if ($petugas) {
            $hasil['pemberi'] = $k['pemberi'] === null ? null : (string) $k['pemberi'];
        }

        return $hasil;
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
            self::PERAN_MUROBI => $this->capabilities->has($probe, Capabilities::MUROBI),
        ];
    }

    /**
     * Wali pengaju atas satu pengajuan, dibaca ulang DI DALAM transaksi dengan
     * kunci baca (akun wali dan relasi). Wali lain yang berelasi → 403 (boleh
     * melihat status, tidak boleh mengubah); tanpa relasi/ID tebakan → 404.
     * Diperiksa sebelum putar ulang, sehingga relasi yang dicabut sesudah
     * kiriman pertama tidak lagi menerima hasil tersimpan.
     *
     * @return array{0:int, 1:array<string, mixed>}
     */
    private function wajibPengaju(int $userId, int $id, string $onDate, string $kerja): array
    {
        $waliId = $this->repo->waliAkun($userId, true);
        if ($waliId === null) {
            throw IzinException::forbidden('Akun ini bukan akun orang tua/wali yang aktif.');
        }
        $row = $this->repo->find($id, $onDate);
        if ($row === null || !$this->repo->relasiAktif($waliId, (int) $row['santri_id'], true)) {
            throw IzinException::notFound();
        }
        if ((int) $row['diajukan_oleh_user_id'] !== $userId || (int) $row['wali_id'] !== $waliId) {
            throw IzinException::forbidden('Hanya wali pengaju yang dapat ' . $kerja . ' pengajuan ini.');
        }

        return [$waliId, $row];
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

    private function wajibFase2(): void
    {
        $this->wajibTerpasang();
        if (!$this->repo->fase2Terpasang()) {
            throw new IzinException('Tinjauan pembimbing, perbaikan, dan pembatalan wali belum dipasang (migrasi 031).', 503);
        }
    }

    private function notifikasi(): IzinWaliNotifikasi
    {
        return $this->notifikasi ??= new IzinWaliNotifikasi($this->repo->db(), $this->audit);
    }

    private function murobiRouter(): IzinRouter
    {
        return $this->murobiRouter ??= new IzinRouter($this->repo->db());
    }

    private function requireVersion(mixed $expectedVersion): int
    {
        if (!is_numeric($expectedVersion) || (int) $expectedVersion < 1) {
            throw IzinException::invalid('Versi data wajib disertakan. Muat ulang halaman lalu ulangi.');
        }

        return (int) $expectedVersion;
    }

    /** Sidik isi yang ditinjau/diperbaiki (isi tidak disalin ke tinjauan). */
    public static function isiHash(mixed $tglIzin, mixed $tglKembali, mixed $alasan, mixed $catatan): string
    {
        return hash('sha256', (string) json_encode(
            [(string) $tglIzin, (string) $tglKembali, (string) $alasan, $catatan === null ? null : (string) $catatan],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
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
            // Kode galat dibaca SEBELUM rollback (rollback mengosongkannya).
            // Fase 3: baris outbox berkunci asing ke akun penerima, sehingga
            // balapan wali vs pemroses dapat berakhir deadlock; korban dijawab
            // 409 seperti konflik versi, bukan 500.
            $errno = (int) $exception->getCode() ?: $this->repo->db()->errno;
            $this->repo->rollback();
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
