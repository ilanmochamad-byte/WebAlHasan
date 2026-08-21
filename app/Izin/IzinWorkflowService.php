<?php

declare(strict_types=1);

namespace App\Izin;

use App\Audit\AuditLogger;
use App\Auth\Capabilities;
use RuntimeException;
use Throwable;

/**
 * Alur perizinan V2 Fase 2: pengajuan, routing, penetapan murobi, keputusan,
 * pembatalan, dan koreksi.
 *
 * Prinsip yang dipegang seluruh method mutasi:
 *   1. Cakupan dan role diperiksa DI SERVER dari akun yang sedang masuk. Parameter
 *      request tidak pernah dipakai untuk memperluas cakupan.
 *   2. Satu transaksi per mutasi. Baris kunci idempotensi disisipkan lebih dulu
 *      di dalam transaksi itu sehingga retry tidak pernah menghasilkan duplikasi
 *      dan dua request bersamaan berurutan secara otomatis.
 *   3. Baris pengajuan dikunci (`FOR UPDATE`) sebelum diperiksa, lalu diperbarui
 *      dengan optimistic version. Kunci unik basis data menjadi pengaman terakhir.
 *   4. Setiap perubahan menulis riwayat status (tidak pernah ditimpa) dan audit.
 *   5. Tidak membaca variabel global; IP dan user agent dikirim pemanggil.
 */
final class IzinWorkflowService
{
    public const KAPASITAS_MUROBI = 'Murobi';
    public const KAPASITAS_ADMIN_PENGGANTI = 'Admin Pengganti';

    public function __construct(
        private IzinRepository $read,
        private IzinWriteRepository $write,
        private IzinRouter $router,
        private IzinIdempotency $idempotency,
        private IzinService $izin,
        private Capabilities $capabilities,
        private AuditLogger $audit
    ) {
    }

    // =======================================================================
    // 1. Pengajuan
    // =======================================================================

    /**
     * Daftar santri yang boleh diajukan oleh pengguna, berhalaman dan dapat dicari.
     *
     * @param array{id:int, roles:array<int,string>, guru_id:int|null} $user
     * @return array{scope:array<string,mixed>, rows:array<int,array<string,mixed>>,
     *               total:int, page:int, per_page:int}
     */
    public function selectableSantri(array $user, string $query = '', int $page = 1, int $perPage = 20, ?string $preferred = null): array
    {
        $scope = $this->requireCreatorScope($user, $preferred);
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        if ($scope['mode'] === Capabilities::PENGURUS) {
            $result = $this->read->santriForPengurusPaged(
                (int) $scope['pengurus_id'],
                $this->today(),
                $query,
                $page,
                $perPage
            );
        } else {
            // Admin boleh mengajukan untuk santri mana pun (PRD 5.2); tindakan diaudit.
            $result = $this->read->santriAll($query, $page, $perPage);
        }

        return [
            'scope' => $scope,
            'rows' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * Membuat pengajuan izin dan langsung menjalankan routing.
     *
     * @param array{id:int, roles:array<int,string>, guru_id:int|null} $user
     * @param array<string, mixed> $input
     * @param array{ip:?string, user_agent:?string} $meta
     * @return array<string, mixed>
     */
    public function create(array $user, array $input, ?string $idempotencyKey, array $meta, ?string $preferred = null): array
    {
        $scope = $this->requireCreatorScope($user, $preferred);
        $key = $this->idempotency->normalizeKey($idempotencyKey);
        $userId = (int) $user['id'];

        $santriId = (int) ($input['santri_id'] ?? 0);
        if ($santriId < 1) {
            throw IzinException::invalid('Santri wajib dipilih.');
        }
        $tglIzin = $this->requireDate((string) ($input['tgl_izin'] ?? ''), 'Tanggal izin');
        $tglKembali = $this->requireDate((string) ($input['tgl_kembali'] ?? ''), 'Tanggal kembali');
        if ($tglKembali < $tglIzin) {
            throw IzinException::invalid('Tanggal kembali tidak boleh mendahului tanggal izin.');
        }
        $alasan = $this->requireText((string) ($input['alasan'] ?? ''), 'Alasan izin', 3, 2000);
        $catatan = $this->optionalText((string) ($input['catatan_pengurus'] ?? ''), 'Catatan pengurus', 1000);

        $onDate = $this->today();
        $payload = [
            'santri_id' => $santriId,
            'tgl_izin' => $tglIzin,
            'tgl_kembali' => $tglKembali,
            'alasan' => $alasan,
            'catatan_pengurus' => $catatan,
            'mode' => $scope['mode'],
        ];

        return $this->transactional(function () use (
            $user, $userId, $scope, $key, $payload, $santriId, $tglIzin, $tglKembali,
            $alasan, $catatan, $onDate, $meta
        ): array {
            $replay = $this->idempotency->begin($userId, IzinIdempotency::OP_CREATE, $key, $payload);
            if ($replay !== null) {
                return $replay['response'] + ['idempotent_replay' => true];
            }

            // Kunci baris santri: menserialkan pembuatan pengajuan santri yang sama.
            $santri = $this->write->lockSantri($santriId);
            if ($santri === null || (int) $santri['is_active'] !== 1 || $santri['archived_at'] !== null) {
                throw IzinException::invalid('Santri tidak ditemukan atau sudah tidak aktif.');
            }

            // Cakupan dan tahun ajaran dibaca ulang DI DALAM transaksi setelah
            // santri dikunci. Perubahan master data di antara tampilan form dan
            // pengiriman tidak boleh meloloskan penugasan yang sudah tidak aktif.
            $assignment = null;
            if ($scope['mode'] === Capabilities::PENGURUS) {
                $assignment = $this->read->pembimbingAssignmentFor((int) $scope['pengurus_id'], $santriId, $onDate);
                if ($assignment === null) {
                    throw IzinException::forbidden('Santri tersebut berada di luar cakupan penugasan pembimbing aktif Anda.');
                }
            }
            $year = $this->read->activeYear();
            if ($year === null) {
                throw IzinException::invalid('Tidak ada tahun ajaran aktif. Hubungi admin sebelum membuat pengajuan.');
            }

            $overlap = $this->write->findOverlap($santriId, $tglIzin, $tglKembali);
            if ($overlap !== null) {
                throw IzinException::conflict(sprintf(
                    'Santri ini sudah memiliki pengajuan #%d berstatus %s pada rentang %s s.d. %s yang bersinggungan.',
                    (int) $overlap['id'],
                    (string) $overlap['status'],
                    (string) $overlap['tgl_izin'],
                    (string) $overlap['tgl_kembali']
                ));
            }

            $pengajuanId = $this->write->insertPengajuan([
                'santri_id' => $santriId,
                'pengurus_id' => $scope['mode'] === Capabilities::PENGURUS ? (int) $scope['pengurus_id'] : null,
                'diajukan_oleh_user_id' => $userId,
                'pembimbing_assignment_id' => $assignment === null ? null : (int) $assignment['id'],
                'tahun_ajaran_id' => (int) $year['id'],
                'tgl_izin' => $tglIzin,
                'tgl_kembali' => $tglKembali,
                'alasan' => $alasan,
                'catatan_pengurus' => $catatan,
                'status' => IzinRouter::STATUS_TERARAH,
                'idempotency_key' => $key,
            ]);

            $kapasitas = $scope['mode'] === Capabilities::ADMIN ? 'Admin' : 'Pengurus';
            $this->write->insertRiwayat($this->historyRow($pengajuanId, 'pengajuan_dibuat', null, IzinRouter::STATUS_TERARAH, $userId, $kapasitas, $alasan, $meta));

            // --- Routing ---------------------------------------------------
            $routing = $this->router->resolve($santriId, $onDate);
            $this->write->applyRouting(
                $pengajuanId,
                $routing['murobi_guru_id'],
                $routing['status'],
                (int) $routing['jumlah'],
                (string) $routing['catatan']
            );
            $this->write->insertRiwayat($this->historyRow(
                $pengajuanId,
                $routing['jumlah'] === 1 ? 'routing_otomatis' : 'routing_perlu_admin',
                IzinRouter::STATUS_TERARAH,
                (string) $routing['status'],
                $userId,
                'Sistem',
                (string) $routing['catatan'],
                $meta
            ));

            $response = [
                'id' => $pengajuanId,
                'status' => (string) $routing['status'],
                'murobi_guru_id' => $routing['murobi_guru_id'],
                'routing_kandidat' => (int) $routing['jumlah'],
                'routing_catatan' => (string) $routing['catatan'],
            ];
            $this->idempotency->complete($userId, IzinIdempotency::OP_CREATE, $key, 201, $response, $pengajuanId);

            $this->auditOrFail('izin_pengajuan_created', 'izin_pengajuan', $pengajuanId, null, [
                'santri_id' => $santriId,
                'pengurus_id' => $scope['mode'] === Capabilities::PENGURUS ? (int) $scope['pengurus_id'] : null,
                'kapasitas_pengaju' => $kapasitas,
                'tgl_izin' => $tglIzin,
                'tgl_kembali' => $tglKembali,
                'tahun_ajaran_id' => (int) $year['id'],
            ], $userId);
            $this->auditOrFail('izin_routing_resolved', 'izin_pengajuan', $pengajuanId, null, [
                'status' => (string) $routing['status'],
                'murobi_guru_id' => $routing['murobi_guru_id'],
                'jumlah_kandidat' => (int) $routing['jumlah'],
                'catatan' => (string) $routing['catatan'],
            ], $userId);

            return $response + ['idempotent_replay' => false];
        });
    }

    // =======================================================================
    // 2. Penetapan murobi oleh admin
    // =======================================================================

    /**
     * @param array{id:int, roles:array<int,string>, guru_id:int|null} $user
     * @param array{ip:?string, user_agent:?string} $meta
     * @return array<string, mixed>
     */
    public function assignMurobi(
        array $user,
        int $pengajuanId,
        int $guruId,
        ?string $alasan,
        ?int $expectedVersion,
        ?string $idempotencyKey,
        array $meta
    ): array {
        if (!$this->capabilities->has($user, Capabilities::ADMIN)) {
            throw IzinException::forbidden('Hanya admin yang dapat menetapkan atau mengganti murobi.');
        }
        $key = $this->idempotency->normalizeKey($idempotencyKey);
        $userId = (int) $user['id'];
        $alasan = $this->requireText((string) $alasan, 'Alasan penetapan murobi', 3, 1000);
        if ($guruId < 1) {
            throw IzinException::invalid('Murobi tujuan wajib dipilih.');
        }

        $payload = ['pengajuan_id' => $pengajuanId, 'guru_id' => $guruId, 'alasan' => $alasan];

        return $this->transactional(function () use ($userId, $pengajuanId, $guruId, $alasan, $expectedVersion, $key, $payload, $meta): array {
            $replay = $this->idempotency->begin($userId, IzinIdempotency::OP_ASSIGN, $key, $payload);
            if ($replay !== null) {
                return $replay['response'] + ['idempotent_replay' => true];
            }

            $row = $this->requirePending($pengajuanId);
            $version = $this->requireVersion($row, $expectedVersion);

            if (!$this->router->isEligibleMurobi($guruId, $this->today())) {
                throw IzinException::invalid('Guru yang dipilih tidak memiliki penugasan murobi aktif pada tahun ajaran aktif, sehingga tidak dapat memberi keputusan.');
            }

            $statusSebelum = (string) $row['status'];
            $murobiSebelum = $row['murobi_guru_id'] === null ? null : (int) $row['murobi_guru_id'];
            if ($murobiSebelum === $guruId && $statusSebelum === IzinRouter::STATUS_TERARAH) {
                throw IzinException::conflict('Murobi tersebut sudah ditetapkan pada pengajuan ini.');
            }

            if (!$this->write->updateStatusWithVersion($pengajuanId, IzinRouter::STATUS_TERARAH, $version, IzinWriteRepository::STATUS_BELUM_DIPUTUS)) {
                throw IzinException::conflict('Pengajuan sudah berubah sejak halaman ini dimuat. Muat ulang lalu coba lagi.');
            }
            $this->write->assignMurobi($pengajuanId, $guruId, $userId);
            $this->write->insertRiwayat($this->historyRow(
                $pengajuanId,
                $murobiSebelum === null ? 'murobi_ditetapkan' : 'murobi_ditetapkan_ulang',
                $statusSebelum,
                IzinRouter::STATUS_TERARAH,
                $userId,
                'Admin',
                $alasan,
                $meta
            ));

            $response = [
                'id' => $pengajuanId,
                'status' => IzinRouter::STATUS_TERARAH,
                'murobi_guru_id' => $guruId,
                'version' => $version + 1,
            ];
            $this->idempotency->complete($userId, IzinIdempotency::OP_ASSIGN, $key, 200, $response, $pengajuanId);
            $this->auditOrFail(
                'izin_murobi_assigned',
                'izin_pengajuan',
                $pengajuanId,
                ['status' => $statusSebelum, 'murobi_guru_id' => $murobiSebelum],
                ['status' => IzinRouter::STATUS_TERARAH, 'murobi_guru_id' => $guruId, 'alasan' => $alasan],
                $userId
            );

            return $response + ['idempotent_replay' => false];
        });
    }

    // =======================================================================
    // 3. Keputusan
    // =======================================================================

    /**
     * @param array{id:int, roles:array<int,string>, guru_id:int|null} $user
     * @param array{ip:?string, user_agent:?string} $meta
     * @return array<string, mixed>
     */
    public function decide(
        array $user,
        int $pengajuanId,
        string $hasil,
        ?string $alasan,
        ?string $alasanPenggantian,
        ?int $expectedVersion,
        ?string $idempotencyKey,
        array $meta,
        ?string $preferred = null
    ): array {
        if (!in_array($hasil, ['Disetujui', 'Ditolak'], true)) {
            throw IzinException::invalid('Hasil keputusan harus Disetujui atau Ditolak.');
        }
        $scope = $this->izin->scopeFor($user, $preferred);
        if (!in_array($scope['mode'], [Capabilities::MUROBI, Capabilities::ADMIN], true)) {
            throw IzinException::forbidden('Akun ini tidak berhak memberi keputusan izin.');
        }
        $key = $this->idempotency->normalizeKey($idempotencyKey);
        $userId = (int) $user['id'];
        $alasan = $this->requireText((string) $alasan, 'Alasan keputusan', 3, 2000);

        $kapasitas = $scope['mode'] === Capabilities::ADMIN ? self::KAPASITAS_ADMIN_PENGGANTI : self::KAPASITAS_MUROBI;
        if ($kapasitas === self::KAPASITAS_ADMIN_PENGGANTI) {
            // Wajib: admin pengganti tidak dapat memutus tanpa alasan penggantian.
            $alasanPenggantian = $this->requireText(
                (string) $alasanPenggantian,
                'Alasan penggantian murobi',
                3,
                1000
            );
        } else {
            $alasanPenggantian = null;
        }

        $payload = [
            'pengajuan_id' => $pengajuanId,
            'hasil' => $hasil,
            'alasan' => $alasan,
            'kapasitas' => $kapasitas,
            'alasan_penggantian' => $alasanPenggantian,
        ];

        return $this->transactional(function () use (
            $user, $userId, $scope, $pengajuanId, $hasil, $alasan, $alasanPenggantian,
            $kapasitas, $expectedVersion, $key, $payload, $meta
        ): array {
            $replay = $this->idempotency->begin($userId, IzinIdempotency::OP_DECISION, $key, $payload);
            if ($replay !== null) {
                return $replay['response'] + ['idempotent_replay' => true];
            }

            $row = $this->requirePending($pengajuanId);

            if ($kapasitas === self::KAPASITAS_MUROBI) {
                $guruId = $scope['guru_id'] === null ? 0 : (int) $scope['guru_id'];
                if ($row['murobi_guru_id'] === null || (int) $row['murobi_guru_id'] !== $guruId) {
                    // Murobi lain (atau pengajuan yang belum ditetapkan) => 403, bukan 404,
                    // dan tidak membocorkan isi pengajuan milik murobi lain.
                    throw IzinException::forbidden('Pengajuan ini tidak diarahkan kepada Anda.');
                }
            }

            $version = $this->requireVersion($row, $expectedVersion);
            $statusSebelum = (string) $row['status'];

            if (!$this->write->updateStatusWithVersion($pengajuanId, $hasil, $version, IzinWriteRepository::STATUS_BELUM_DIPUTUS)) {
                throw IzinException::conflict('Pengajuan ini sudah diputus atau berubah. Hanya satu keputusan yang berlaku.');
            }

            $keputusanId = $this->write->insertKeputusan([
                'pengajuan_id' => $pengajuanId,
                'hasil' => $hasil,
                'alasan' => $alasan,
                'diputus_oleh_user_id' => $userId,
                'kapasitas' => $kapasitas,
                'alasan_penggantian' => $alasanPenggantian,
                'pengajuan_version' => $version,
            'idempotency_key' => $key,
            ]);

            $this->write->insertRiwayat($this->historyRow(
                $pengajuanId,
                'keputusan',
                $statusSebelum,
                $hasil,
                $userId,
                $kapasitas,
                $alasanPenggantian === null ? $alasan : $alasan . ' | Alasan penggantian: ' . $alasanPenggantian,
                $meta
            ));

            $response = [
                'id' => $pengajuanId,
                'keputusan_id' => $keputusanId,
                'status' => $hasil,
                'kapasitas' => $kapasitas,
                'version' => $version + 1,
            ];
            $this->idempotency->complete($userId, IzinIdempotency::OP_DECISION, $key, 201, $response, $pengajuanId);
            $this->auditOrFail(
                'izin_decision_recorded',
                'izin_pengajuan',
                $pengajuanId,
                ['status' => $statusSebelum, 'version' => $version],
                [
                    'status' => $hasil,
                    'kapasitas' => $kapasitas,
                    'keputusan_id' => $keputusanId,
                    'alasan_penggantian_terisi' => $alasanPenggantian !== null,
                ],
                $userId
            );

            return $response + ['idempotent_replay' => false];
        });
    }

    // =======================================================================
    // 4. Pembatalan oleh pengurus
    // =======================================================================

    /**
     * @param array{id:int, roles:array<int,string>, guru_id:int|null} $user
     * @param array{ip:?string, user_agent:?string} $meta
     * @return array<string, mixed>
     */
    public function cancel(
        array $user,
        int $pengajuanId,
        ?string $alasan,
        ?int $expectedVersion,
        ?string $idempotencyKey,
        array $meta,
        ?string $preferred = null
    ): array {
        $scope = $this->izin->scopeFor($user, $preferred);
        if (!in_array($scope['mode'], [Capabilities::PENGURUS, Capabilities::ADMIN], true)) {
            throw IzinException::forbidden('Hanya pengurus pengaju atau admin yang dapat membatalkan pengajuan.');
        }
        $key = $this->idempotency->normalizeKey($idempotencyKey);
        $userId = (int) $user['id'];
        $alasan = $this->requireText((string) $alasan, 'Alasan pembatalan', 3, 1000);
        $payload = ['pengajuan_id' => $pengajuanId, 'alasan' => $alasan];

        return $this->transactional(function () use ($userId, $scope, $pengajuanId, $alasan, $expectedVersion, $key, $payload, $meta): array {
            $replay = $this->idempotency->begin($userId, IzinIdempotency::OP_CANCEL, $key, $payload);
            if ($replay !== null) {
                return $replay['response'] + ['idempotent_replay' => true];
            }

            $row = $this->requirePending($pengajuanId);
            if ($scope['mode'] === Capabilities::PENGURUS) {
                $pengurusId = $scope['pengurus_id'] === null ? 0 : (int) $scope['pengurus_id'];
                if ($row['pengurus_id'] === null || (int) $row['pengurus_id'] !== $pengurusId) {
                    throw IzinException::forbidden('Pengajuan ini bukan milik cakupan Anda.');
                }
            }

            $version = $this->requireVersion($row, $expectedVersion);
            $statusSebelum = (string) $row['status'];

            if (!$this->write->updateStatusWithVersion($pengajuanId, 'Dibatalkan', $version, IzinWriteRepository::STATUS_BELUM_DIPUTUS)) {
                throw IzinException::conflict('Pengajuan sudah diputus atau berubah, sehingga tidak dapat dibatalkan.');
            }
            $this->write->markCancelled($pengajuanId, $userId, $alasan);
            $this->write->insertRiwayat($this->historyRow(
                $pengajuanId,
                'pembatalan',
                $statusSebelum,
                'Dibatalkan',
                $userId,
                $scope['mode'] === Capabilities::ADMIN ? 'Admin' : 'Pengurus',
                $alasan,
                $meta
            ));

            $response = ['id' => $pengajuanId, 'status' => 'Dibatalkan', 'version' => $version + 1];
            $this->idempotency->complete($userId, IzinIdempotency::OP_CANCEL, $key, 200, $response, $pengajuanId);
            $this->auditOrFail(
                'izin_cancelled',
                'izin_pengajuan',
                $pengajuanId,
                ['status' => $statusSebelum, 'version' => $version],
                ['status' => 'Dibatalkan', 'alasan' => $alasan],
                $userId
            );

            return $response + ['idempotent_replay' => false];
        });
    }

    // =======================================================================
    // 5. Koreksi keputusan oleh admin
    // =======================================================================

    /**
     * Koreksi TIDAK menghapus keputusan atau riwayat: nilai lama disalin ke
     * `izin_keputusan_koreksi` dan peristiwanya ditambahkan ke riwayat.
     *
     * @param array{id:int, roles:array<int,string>, guru_id:int|null} $user
     * @param array{ip:?string, user_agent:?string} $meta
     * @return array<string, mixed>
     */
    public function correctDecision(
        array $user,
        int $pengajuanId,
        string $hasilBaru,
        ?string $alasanBaru,
        ?string $alasanKoreksi,
        ?int $expectedVersion,
        ?string $idempotencyKey,
        array $meta
    ): array {
        if (!$this->capabilities->has($user, Capabilities::ADMIN)) {
            throw IzinException::forbidden('Hanya admin yang dapat mengoreksi keputusan.');
        }
        if (!in_array($hasilBaru, ['Disetujui', 'Ditolak'], true)) {
            throw IzinException::invalid('Hasil koreksi harus Disetujui atau Ditolak.');
        }
        $key = $this->idempotency->normalizeKey($idempotencyKey);
        $userId = (int) $user['id'];
        $alasanBaru = $this->requireText((string) $alasanBaru, 'Alasan keputusan setelah koreksi', 3, 2000);
        $alasanKoreksi = $this->requireText((string) $alasanKoreksi, 'Alasan koreksi', 3, 1000);

        $payload = [
            'pengajuan_id' => $pengajuanId,
            'hasil' => $hasilBaru,
            'alasan' => $alasanBaru,
            'alasan_koreksi' => $alasanKoreksi,
        ];

        return $this->transactional(function () use (
            $userId, $pengajuanId, $hasilBaru, $alasanBaru, $alasanKoreksi, $expectedVersion, $key, $payload, $meta
        ): array {
            $replay = $this->idempotency->begin($userId, IzinIdempotency::OP_CORRECTION, $key, $payload);
            if ($replay !== null) {
                return $replay['response'] + ['idempotent_replay' => true];
            }

            $row = $this->write->lockPengajuan($pengajuanId);
            if ($row === null) {
                throw IzinException::forbidden();
            }
            $keputusan = $this->write->lockKeputusan($pengajuanId);
            if ($keputusan === null) {
                throw IzinException::conflict('Pengajuan ini belum memiliki keputusan yang dapat dikoreksi.');
            }
            $version = $this->requireVersion($row, $expectedVersion);
            $statusSebelum = (string) $row['status'];

            if (!$this->write->updateStatusWithVersion($pengajuanId, $hasilBaru, $version, ['Disetujui', 'Ditolak'])) {
                throw IzinException::conflict('Status pengajuan sudah berubah sejak halaman ini dimuat.');
            }

            $koreksiId = $this->write->insertKoreksi([
                'pengajuan_id' => $pengajuanId,
                'keputusan_id' => (int) $keputusan['id'],
                'hasil_sebelum' => (string) $keputusan['hasil'],
                'hasil_sesudah' => $hasilBaru,
                'alasan_sebelum' => (string) $keputusan['alasan'],
                'alasan_sesudah' => $alasanBaru,
                'status_sebelum' => $statusSebelum,
                'status_sesudah' => $hasilBaru,
                'alasan_koreksi' => $alasanKoreksi,
                'dikoreksi_oleh_user_id' => $userId,
            ]);
            $this->write->updateKeputusan((int) $keputusan['id'], $hasilBaru, $alasanBaru);
            $this->write->insertRiwayat($this->historyRow(
                $pengajuanId,
                'keputusan_dikoreksi',
                $statusSebelum,
                $hasilBaru,
                $userId,
                'Admin',
                $alasanKoreksi,
                $meta
            ));

            $response = [
                'id' => $pengajuanId,
                'status' => $hasilBaru,
                'koreksi_id' => $koreksiId,
                'version' => $version + 1,
            ];
            $this->idempotency->complete($userId, IzinIdempotency::OP_CORRECTION, $key, 200, $response, $pengajuanId);
            $this->auditOrFail(
                'izin_decision_corrected',
                'izin_pengajuan',
                $pengajuanId,
                ['status' => $statusSebelum, 'hasil' => (string) $keputusan['hasil'], 'alasan' => (string) $keputusan['alasan']],
                ['status' => $hasilBaru, 'hasil' => $hasilBaru, 'alasan' => $alasanBaru, 'alasan_koreksi' => $alasanKoreksi],
                $userId
            );

            return $response + ['idempotent_replay' => false];
        });
    }

    // =======================================================================
    // 6. Kemampuan aksi untuk tampilan (UI hanya cermin, bukan kontrol akses)
    // =======================================================================

    /**
     * @param array<string, mixed> $pengajuan
     * @param array<string, mixed> $scope
     * @return array<string, bool>
     */
    public function actionsFor(array $pengajuan, array $scope): array
    {
        $status = (string) ($pengajuan['status'] ?? '');
        $belumDiputus = in_array($status, IzinWriteRepository::STATUS_BELUM_DIPUTUS, true);
        $sudahDiputus = in_array($status, ['Disetujui', 'Ditolak'], true);
        $legacy = (bool) ($pengajuan['is_legacy'] ?? false);
        $mode = (string) ($scope['mode'] ?? '');

        $murobiCocok = $mode === Capabilities::MUROBI
            && $pengajuan['murobi_guru_id'] !== null
            && (int) $pengajuan['murobi_guru_id'] === (int) ($scope['guru_id'] ?? 0);
        $pengurusCocok = $mode === Capabilities::PENGURUS
            && $pengajuan['pengurus_id'] !== null
            && (int) $pengajuan['pengurus_id'] === (int) ($scope['pengurus_id'] ?? 0);

        return [
            'putuskan_murobi' => !$legacy && $belumDiputus && $murobiCocok && $status === IzinRouter::STATUS_TERARAH,
            'putuskan_admin' => !$legacy && $belumDiputus && $mode === Capabilities::ADMIN,
            'tetapkan_murobi' => !$legacy && $belumDiputus && $mode === Capabilities::ADMIN,
            'batalkan' => !$legacy && $belumDiputus && ($pengurusCocok || $mode === Capabilities::ADMIN),
            'koreksi' => !$legacy && $sudahDiputus && $mode === Capabilities::ADMIN,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function eligibleMurobi(): array
    {
        return $this->router->eligibleMurobi($this->today());
    }

    /**
     * @param array<string, mixed> $pengajuan
     * @return array<int, array<string, mixed>>
     */
    public function routingCandidates(array $pengajuan): array
    {
        return $this->router->candidates((int) $pengajuan['santri_id'], $this->today());
    }

    // =======================================================================
    // Utilitas internal
    // =======================================================================

    /**
     * @param array{id:int, roles:array<int,string>, guru_id:int|null} $user
     * @return array<string, mixed>
     */
    private function requireCreatorScope(array $user, ?string $preferred): array
    {
        $available = $this->capabilities->forUser($user);
        // Pengurus diutamakan agar akun ganda tetap membuat pengajuan atas nama
        // pengurusnya, kecuali pengguna secara eksplisit memilih mode admin.
        if ($preferred === null && in_array(Capabilities::PENGURUS, $available, true)) {
            $preferred = Capabilities::PENGURUS;
        }
        $scope = $this->izin->scopeFor($user, $preferred);
        if (!in_array($scope['mode'], [Capabilities::PENGURUS, Capabilities::ADMIN], true)) {
            throw IzinException::forbidden('Akun ini tidak berhak membuat pengajuan izin.');
        }

        return $scope;
    }

    /**
     * @return array<string, mixed>
     */
    private function requirePending(int $pengajuanId): array
    {
        $row = $this->write->lockPengajuan($pengajuanId);
        if ($row === null) {
            // Tidak ada dan di luar cakupan diperlakukan sama: 403.
            throw IzinException::forbidden();
        }
        if ((int) $row['is_legacy'] === 1) {
            throw IzinException::conflict('Pengajuan warisan V1 bersifat baca-saja dan tidak dapat diproses pada alur V2.');
        }
        if (!in_array((string) $row['status'], IzinWriteRepository::STATUS_BELUM_DIPUTUS, true)) {
            throw IzinException::conflict('Pengajuan ini berstatus ' . (string) $row['status'] . ' sehingga tidak dapat diproses lagi.');
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function requireVersion(array $row, ?int $expectedVersion): int
    {
        $current = (int) $row['version'];
        if ($expectedVersion !== null && $expectedVersion !== $current) {
            throw IzinException::conflict('Pengajuan sudah diperbarui pihak lain (versi ' . $current . '). Muat ulang halaman lalu ulangi.');
        }

        return $current;
    }

    /**
     * @param array{ip:?string, user_agent:?string} $meta
     * @return array<string, mixed>
     */
    private function historyRow(
        int $pengajuanId,
        string $peristiwa,
        ?string $statusSebelum,
        ?string $statusSesudah,
        ?int $pelakuUserId,
        ?string $kapasitas,
        ?string $alasan,
        array $meta
    ): array {
        $ip = $meta['ip'] ?? null;
        $userAgent = $meta['user_agent'] ?? null;

        return [
            'pengajuan_id' => $pengajuanId,
            'peristiwa' => $peristiwa,
            'status_sebelum' => $statusSebelum,
            'status_sesudah' => $statusSesudah,
            'pelaku_user_id' => $pelakuUserId,
            'pelaku_kapasitas' => $kapasitas,
            'alasan' => $alasan === null ? null : mb_substr($alasan, 0, 2000),
            'ip_address' => $ip === null ? null : substr($ip, 0, 45),
            'user_agent' => $userAgent === null ? null : substr($userAgent, 0, 255),
        ];
    }

    /**
     * @template T
     * @param callable():T $operation
     * @return T
     */
    private function transactional(callable $operation): mixed
    {
        $this->write->beginTransaction();
        try {
            $result = $operation();
            $this->write->commit();

            return $result;
        } catch (Throwable $exception) {
            $this->write->rollback();
            throw $exception;
        }
    }

    /**
     * Audit Fase 2 merupakan bagian wajib dari transaksi. AuditLogger tetap
     * kompatibel dengan pemanggil V1 yang bersifat best-effort, sedangkan jalur
     * perizinan menggagalkan transaksi bila jejak audit tidak dapat disimpan.
     */
    private function auditOrFail(
        string $action,
        string $entityType,
        ?int $entityId,
        ?array $before,
        ?array $after,
        int $actorUserId
    ): void {
        if (!$this->audit->log($action, $entityType, $entityId, $before, $after, $actorUserId)) {
            throw new RuntimeException('Audit perubahan perizinan tidak dapat disimpan. Transaksi dibatalkan.');
        }
    }

    private function requireDate(string $value, string $label): string
    {
        $value = trim($value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw IzinException::invalid($label . ' wajib diisi dengan format tanggal YYYY-MM-DD.');
        }
        [$year, $month, $day] = array_map('intval', explode('-', $value));
        if (!checkdate($month, $day, $year)) {
            throw IzinException::invalid($label . ' bukan tanggal yang valid.');
        }

        return $value;
    }

    private function requireText(string $value, string $label, int $min, int $max): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        if (mb_strlen($value) < $min) {
            throw IzinException::invalid($label . ' wajib diisi minimal ' . $min . ' karakter.');
        }
        if (mb_strlen($value) > $max) {
            throw IzinException::invalid($label . ' maksimal ' . $max . ' karakter.');
        }

        return $value;
    }

    private function optionalText(string $value, string $label, int $max): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $max) {
            throw IzinException::invalid($label . ' maksimal ' . $max . ' karakter.');
        }

        return $value;
    }

    private function today(): string
    {
        return date('Y-m-d');
    }
}
