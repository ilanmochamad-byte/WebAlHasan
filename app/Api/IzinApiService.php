<?php

declare(strict_types=1);

namespace App\Api;

use App\Auth\Capabilities;
use App\Izin\IzinException;
use App\Izin\IzinRepository;
use App\Izin\IzinRouter;
use App\Izin\IzinService;
use App\Izin\IzinWorkflowService;
use Throwable;

/**
 * REST API perizinan V2 Fase 3.
 *
 * Lapisan ini HANYA menerjemahkan: seluruh otorisasi, cakupan, transaksi,
 * idempotensi, optimistic version, riwayat, dan audit tetap dikerjakan
 * `IzinService`/`IzinWorkflowService` yang sudah lulus audit Fase 2. Tidak ada
 * jalur pintas yang melewati pemeriksaan tersebut.
 *
 * Prinsip yang dipegang:
 *   1. `mode` dari request hanya boleh MEMPERSEMPIT ke capability yang benar-benar
 *      dimiliki pengguna. `IzinService::scopeFor()` menolak mode di luar itu, jadi
 *      manipulasi parameter lintas peran selalu berakhir 403 dari server.
 *   2. Envelope, pagination, filter, dan status HTTP mengikuti konvensi API V1.
 *   3. `IzinException` dipetakan ke `ApiException` beserta status aslinya
 *      (403/404/409/422) sehingga klien dapat menindaklanjuti secara spesifik.
 *   4. Tidak ada endpoint mutasi untuk orang tua — kemampuan orang tua hanya baca.
 */
final class IzinApiService
{
    private const PER_PAGE_DEFAULT = 20;
    private const PER_PAGE_MAX = 100;

    public function __construct(
        private IzinService $izin,
        private IzinWorkflowService $workflow,
        private IzinRepository $read,
        private IzinRouter $router,
        private Capabilities $capabilities
    ) {
    }

    // =======================================================================
    // Akun dan capability
    // =======================================================================

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function capabilities(array $user): array
    {
        $list = $this->capabilities->forUser($this->probe($user));
        $userId = (int) $user['id'];

        return [
            'list' => array_values($list),
            'default_mode' => $this->defaultMode($list),
            'konteks' => [
                'guru_id' => $this->guruId($user),
                'pengurus_id' => in_array(Capabilities::PENGURUS, $list, true)
                    ? $this->capabilities->linkedPengurusId($userId)
                    : null,
                'wali_id' => in_array(Capabilities::ORANG_TUA, $list, true)
                    ? $this->capabilities->linkedWaliId($userId)
                    : null,
            ],
            'label' => array_map(
                fn (string $capability): array => [
                    'mode' => $capability,
                    'label' => $this->izin->label($capability),
                ],
                array_values($list)
            ),
        ];
    }

    // =======================================================================
    // Daftar dan detail
    // =======================================================================

    /**
     * Santri yang boleh diajukan oleh pengguna (pengurus: cakupan pembimbing aktif;
     * admin: seluruh santri aktif, dan tindakannya diaudit).
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function santri(array $user, array $query): array
    {
        return $this->translate(function () use ($user, $query): array {
            [$page, $perPage] = $this->pageOf($query);
            $result = $this->workflow->selectableSantri(
                $user,
                trim((string) ($query['q'] ?? '')),
                $page,
                $perPage,
                $this->mode($query)
            );

            return [
                'scope' => $this->presentScope($result['scope']),
                'items' => array_map([$this, 'presentSantri'], $result['rows']),
                'pagination' => $this->pagination($page, $perPage, (int) $result['total']),
                'filters' => ['q' => trim((string) ($query['q'] ?? ''))],
            ];
        });
    }

    /**
     * Daftar anak yang terhubung dengan akun orang tua, beserta ringkasan izin.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function anak(array $user): array
    {
        return $this->translate(function () use ($user): array {
            $scope = $this->izin->scopeFor($user, Capabilities::ORANG_TUA);
            if ($scope['mode'] !== Capabilities::ORANG_TUA) {
                throw IzinException::forbidden('Daftar anak hanya tersedia untuk akun orang tua.');
            }

            $rows = $this->read->santriForWali((int) $scope['wali_id']);

            return [
                'scope' => $this->presentScope($scope),
                'items' => array_map(static fn (array $row): array => [
                    'santri' => [
                        'id' => (int) $row['id'],
                        'nis' => (string) $row['nis'],
                        'nama' => (string) $row['nama_santri'],
                    ],
                    'hubungan' => $row['hubungan'] === null ? null : (string) $row['hubungan'],
                    'wali_utama' => (int) ($row['is_primary'] ?? 0) === 1,
                ], $rows),
                'total' => count($rows),
            ];
        });
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function index(array $user, array $query, bool $antreanOnly = false): array
    {
        return $this->translate(function () use ($user, $query, $antreanOnly): array {
            [$page, $perPage] = $this->pageOf($query);
            $filters = $this->filtersOf($query, $antreanOnly);
            $result = $this->izin->list($user, $filters, $page, $perPage, $this->mode($query));
            $scope = $result['scope'];

            return [
                'scope' => $this->presentScope($scope),
                'items' => array_map(fn (array $row): array => $this->presentPengajuan($row, $scope), $result['rows']),
                'pagination' => $this->pagination($page, $perPage, (int) $result['total']),
                'filters' => $this->presentFilters($filters, $antreanOnly),
                'summary' => $result['summary'],
            ];
        });
    }

    /**
     * Pemantauan admin: seluruh pengajuan ditambah penghitung antrean routing.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function adminMonitor(array $user, array $query): array
    {
        return $this->translate(function () use ($user, $query): array {
            if (!$this->capabilities->has($this->probe($user), Capabilities::ADMIN)) {
                throw IzinException::forbidden('Pemantauan seluruh pengajuan hanya untuk admin.');
            }
            $query['mode'] = Capabilities::ADMIN;
            $payload = $this->index($user, $query);
            $payload['antrean_admin'] = $this->izin->queueCount($user, Capabilities::ADMIN);

            return $payload;
        });
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function show(array $user, int $id, array $query): array
    {
        return $this->translate(function () use ($user, $id, $query): array {
            $detail = $this->izin->detail($user, $id, $this->mode($query));
            $scope = $detail['scope'];
            $pengajuan = $detail['pengajuan'];
            $actions = $this->workflow->actionsFor($pengajuan, $scope);

            return [
                'scope' => $this->presentScope($scope),
                'pengajuan' => $this->presentPengajuan($pengajuan, $scope),
                'keputusan' => $this->presentKeputusan($detail['keputusan']),
                'riwayat' => array_map([$this, 'presentRiwayat'], $detail['riwayat']),
                'koreksi' => array_map([$this, 'presentKoreksi'], $detail['koreksi']),
                'aksi' => $actions,
            ];
        });
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function history(array $user, int $id, array $query): array
    {
        return $this->translate(function () use ($user, $id, $query): array {
            $detail = $this->izin->detail($user, $id, $this->mode($query));

            return [
                'pengajuan_id' => (int) $detail['pengajuan']['id'],
                'status' => (string) $detail['pengajuan']['status'],
                'version' => (int) $detail['pengajuan']['version'],
                'riwayat' => array_map([$this, 'presentRiwayat'], $detail['riwayat']),
                'koreksi' => array_map([$this, 'presentKoreksi'], $detail['koreksi']),
            ];
        });
    }

    /**
     * Kandidat routing dan daftar murobi yang berhak — hanya admin.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function routing(array $user, int $id): array
    {
        return $this->translate(function () use ($user, $id): array {
            if (!$this->capabilities->has($this->probe($user), Capabilities::ADMIN)) {
                throw IzinException::forbidden('Hanya admin yang dapat melihat atau memperbaiki routing.');
            }
            $detail = $this->izin->detail($user, $id, Capabilities::ADMIN);
            $pengajuan = $detail['pengajuan'];

            return [
                'pengajuan_id' => (int) $pengajuan['id'],
                'status' => (string) $pengajuan['status'],
                'version' => (int) $pengajuan['version'],
                'murobi_saat_ini' => $pengajuan['murobi_guru_id'] === null ? null : (int) $pengajuan['murobi_guru_id'],
                'routing' => [
                    'kandidat' => (int) ($pengajuan['routing_kandidat'] ?? 0),
                    'catatan' => $pengajuan['routing_catatan'] === null ? null : (string) $pengajuan['routing_catatan'],
                    'pada' => $pengajuan['routing_pada'] === null ? null : (string) $pengajuan['routing_pada'],
                ],
                'kandidat' => array_map([$this, 'presentGuru'], $this->workflow->routingCandidates($pengajuan)),
                'murobi_berhak' => array_map([$this, 'presentGuru'], $this->workflow->eligibleMurobi()),
            ];
        });
    }

    /**
     * Pilihan filter yang aman ditampilkan pada UI.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function filters(array $user, array $query): array
    {
        return $this->translate(function () use ($user, $query): array {
            $scope = $this->izin->scopeFor($user, $this->mode($query));
            $payload = [
                'scope' => $this->presentScope($scope),
                'statuses' => IzinRepository::STATUSES,
                'sources' => ['legacy', 'v2'],
                'modes' => array_values($this->capabilities->forUser($this->probe($user))),
                'murobi_berhak' => [],
                'santri' => [],
            ];
            if ($scope['mode'] === Capabilities::ADMIN) {
                $payload['murobi_berhak'] = array_map([$this, 'presentGuru'], $this->workflow->eligibleMurobi());
            }
            if ($scope['mode'] === Capabilities::ORANG_TUA) {
                $payload['santri'] = array_map([$this, 'presentSantri'], $this->read->santriForWali((int) $scope['wali_id']));
            }

            return $payload;
        });
    }

    // =======================================================================
    // Mutasi
    // =======================================================================

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @param array{ip:?string, user_agent:?string} $meta
     * @return array{data:array<string, mixed>, status:int}
     */
    public function create(array $user, array $input, array $meta, array $query = []): array
    {
        return $this->translate(function () use ($user, $input, $meta, $query): array {
            $result = $this->workflow->create(
                $user,
                [
                    'santri_id' => (int) ($input['santri_id'] ?? 0),
                    'tgl_izin' => (string) ($input['tgl_izin'] ?? ''),
                    'tgl_kembali' => (string) ($input['tgl_kembali'] ?? ''),
                    'alasan' => (string) ($input['alasan'] ?? ''),
                    'catatan_pengurus' => (string) ($input['catatan_pengurus'] ?? ''),
                ],
                $this->idempotencyKey($input),
                $meta,
                $this->mode($input + $query)
            );

            // Retry dengan kunci dan isi yang sama membalas respons tersimpan dengan
            // 200 (bukan 201) sehingga klien tahu tidak ada baris baru dibuat.
            return [
                'data' => $result,
                'status' => ($result['idempotent_replay'] ?? false) ? 200 : 201,
            ];
        });
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @param array{ip:?string, user_agent:?string} $meta
     * @return array{data:array<string, mixed>, status:int}
     */
    public function decide(array $user, int $id, array $input, array $meta, array $query = []): array
    {
        return $this->translate(function () use ($user, $id, $input, $meta, $query): array {
            $result = $this->workflow->decide(
                $user,
                $id,
                (string) ($input['hasil'] ?? ''),
                (string) ($input['alasan'] ?? ''),
                isset($input['alasan_penggantian']) ? (string) $input['alasan_penggantian'] : null,
                $this->version($input),
                $this->idempotencyKey($input),
                $meta,
                $this->mode($input + $query)
            );

            return [
                'data' => $result,
                'status' => ($result['idempotent_replay'] ?? false) ? 200 : 201,
            ];
        });
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @param array{ip:?string, user_agent:?string} $meta
     * @return array{data:array<string, mixed>, status:int}
     */
    public function assign(array $user, int $id, array $input, array $meta): array
    {
        return $this->translate(function () use ($user, $id, $input, $meta): array {
            $result = $this->workflow->assignMurobi(
                $user,
                $id,
                (int) ($input['murobi_guru_id'] ?? 0),
                (string) ($input['alasan'] ?? ''),
                $this->version($input),
                $this->idempotencyKey($input),
                $meta
            );

            return ['data' => $result, 'status' => 200];
        });
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @param array{ip:?string, user_agent:?string} $meta
     * @return array{data:array<string, mixed>, status:int}
     */
    public function cancel(array $user, int $id, array $input, array $meta, array $query = []): array
    {
        return $this->translate(function () use ($user, $id, $input, $meta, $query): array {
            $result = $this->workflow->cancel(
                $user,
                $id,
                (string) ($input['alasan'] ?? ''),
                $this->version($input),
                $this->idempotencyKey($input),
                $meta,
                $this->mode($input + $query)
            );

            return ['data' => $result, 'status' => 200];
        });
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @param array{ip:?string, user_agent:?string} $meta
     * @return array{data:array<string, mixed>, status:int}
     */
    public function correct(array $user, int $id, array $input, array $meta): array
    {
        return $this->translate(function () use ($user, $id, $input, $meta): array {
            $result = $this->workflow->correctDecision(
                $user,
                $id,
                (string) ($input['hasil'] ?? ''),
                (string) ($input['alasan'] ?? ''),
                (string) ($input['alasan_koreksi'] ?? ''),
                $this->version($input),
                $this->idempotencyKey($input),
                $meta
            );

            return ['data' => $result, 'status' => 200];
        });
    }

    // =======================================================================
    // Penyajian
    // =======================================================================

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function presentPengajuan(array $row, array $scope): array
    {
        $legacy = (bool) ($row['is_legacy'] ?? false);

        return [
            'id' => (int) $row['id'],
            'is_legacy' => $legacy,
            'sumber_label' => (string) ($row['sumber_label'] ?? ($legacy ? 'Data warisan' : 'V2')),
            'status' => (string) $row['status'],
            'version' => (int) $row['version'],
            'santri' => [
                'id' => (int) $row['santri_id'],
                'nis' => (string) $row['nis'],
                'nama' => (string) $row['nama_santri'],
            ],
            'pengurus' => $row['pengurus_id'] === null ? null : [
                'id' => (int) $row['pengurus_id'],
                'nama' => $row['pengurus_nama'] === null ? null : (string) $row['pengurus_nama'],
            ],
            'pengurus_label' => (string) ($row['pengurus_label'] ?? ''),
            'murobi' => $row['murobi_guru_id'] === null ? null : [
                'guru_id' => (int) $row['murobi_guru_id'],
                'nama' => $row['murobi_nama'] === null ? null : (string) $row['murobi_nama'],
            ],
            'murobi_label' => (string) ($row['murobi_label'] ?? ''),
            'tahun_ajaran' => $row['tahun_ajaran_id'] === null ? null : [
                'id' => (int) $row['tahun_ajaran_id'],
                'tahun' => $row['tahun_ajaran'] === null ? null : (string) $row['tahun_ajaran'],
                'semester' => $row['semester'] === null ? null : (string) $row['semester'],
            ],
            'tgl_izin' => (string) $row['tgl_izin'],
            'tgl_kembali' => (string) $row['tgl_kembali'],
            'alasan' => (string) $row['alasan'],
            'catatan_pengurus' => $row['catatan_pengurus'] === null ? null : (string) $row['catatan_pengurus'],
            'routing' => [
                'kandidat' => (int) ($row['routing_kandidat'] ?? 0),
                'catatan' => $row['routing_catatan'] === null ? null : (string) $row['routing_catatan'],
                'pada' => $row['routing_pada'] === null ? null : (string) $row['routing_pada'],
            ],
            'keputusan_ringkas' => $row['keputusan_hasil'] === null ? null : [
                'hasil' => (string) $row['keputusan_hasil'],
                'kapasitas' => $row['keputusan_kapasitas'] === null ? null : (string) $row['keputusan_kapasitas'],
                'diputus_pada' => $row['diputus_pada'] === null ? null : (string) $row['diputus_pada'],
            ],
            'keputusan_label' => (string) ($row['keputusan_label'] ?? ''),
            'pembatalan' => $row['dibatalkan_pada'] === null ? null : [
                'oleh' => $row['pembatal_nama'] === null ? null : (string) $row['pembatal_nama'],
                'pada' => (string) $row['dibatalkan_pada'],
                'alasan' => $row['alasan_pembatalan'] === null ? null : (string) $row['alasan_pembatalan'],
            ],
            'diajukan_pada' => $row['diajukan_pada'] === null ? null : (string) $row['diajukan_pada'],
            'aksi' => $this->workflow->actionsFor($row, $scope),
        ];
    }

    /**
     * @param array<string, mixed>|null $row
     * @return array<string, mixed>|null
     */
    private function presentKeputusan(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'hasil' => (string) $row['hasil'],
            'alasan' => (string) $row['alasan'],
            'kapasitas' => (string) $row['kapasitas'],
            'alasan_penggantian' => $row['alasan_penggantian'] === null ? null : (string) $row['alasan_penggantian'],
            'pemberi_keputusan' => $row['pemberi_keputusan'] === null ? null : (string) $row['pemberi_keputusan'],
            'diputus_pada' => (string) $row['diputus_pada'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentRiwayat(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'peristiwa' => (string) $row['peristiwa'],
            'status_sebelum' => $row['status_sebelum'] === null ? null : (string) $row['status_sebelum'],
            'status_sesudah' => $row['status_sesudah'] === null ? null : (string) $row['status_sesudah'],
            'pelaku_nama' => $row['pelaku_nama'] === null ? null : (string) $row['pelaku_nama'],
            'pelaku_kapasitas' => $row['pelaku_kapasitas'] === null ? null : (string) $row['pelaku_kapasitas'],
            'alasan' => $row['alasan'] === null ? null : (string) $row['alasan'],
            'waktu' => (string) $row['created_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentKoreksi(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'hasil_sebelum' => (string) $row['hasil_sebelum'],
            'hasil_sesudah' => (string) $row['hasil_sesudah'],
            'alasan_sebelum' => (string) $row['alasan_sebelum'],
            'alasan_sesudah' => (string) $row['alasan_sesudah'],
            'status_sebelum' => (string) $row['status_sebelum'],
            'status_sesudah' => (string) $row['status_sesudah'],
            'alasan_koreksi' => (string) $row['alasan_koreksi'],
            'pelaku_nama' => $row['pelaku_nama'] === null ? null : (string) $row['pelaku_nama'],
            'waktu' => (string) $row['dikoreksi_pada'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentSantri(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'nis' => (string) $row['nis'],
            'nama' => (string) $row['nama_santri'],
            'jenis_kelamin' => isset($row['jenis_kelamin']) && $row['jenis_kelamin'] !== null
                ? (string) $row['jenis_kelamin']
                : null,
            'cakupan' => isset($row['target_name']) && $row['target_name'] !== null
                ? (string) $row['target_type'] . ': ' . (string) $row['target_name']
                : null,
            'pembimbing_assignment_id' => ($row['pembimbing_assignment_id'] ?? null) === null
                ? null
                : (int) $row['pembimbing_assignment_id'],
            'hubungan' => ($row['hubungan'] ?? null) === null ? null : (string) $row['hubungan'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentGuru(array $row): array
    {
        return [
            'guru_id' => (int) $row['guru_id'],
            'nama' => (string) $row['nama_guru'],
            'nip' => ($row['nip'] ?? null) === null ? null : (string) $row['nip'],
            'targets' => array_values((array) ($row['targets'] ?? [])),
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function presentScope(array $scope): array
    {
        return [
            'mode' => (string) $scope['mode'],
            'label' => (string) $scope['label'],
            'pengurus_id' => $scope['pengurus_id'] === null ? null : (int) $scope['pengurus_id'],
            'guru_id' => $scope['guru_id'] === null ? null : (int) $scope['guru_id'],
            'wali_id' => $scope['wali_id'] === null ? null : (int) $scope['wali_id'],
            'hanya_baca' => $scope['mode'] === Capabilities::ORANG_TUA,
        ];
    }

    // =======================================================================
    // Utilitas
    // =======================================================================

    /**
     * `mode` hanya boleh mempersempit. Nilai yang tidak dikenal diabaikan sehingga
     * server memakai capability default pengguna, bukan menaikkan hak.
     *
     * @param array<string, mixed> $source
     */
    private function mode(array $source): ?string
    {
        $mode = trim((string) ($source['mode'] ?? ''));

        return in_array($mode, Capabilities::ALL, true) ? $mode : null;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function idempotencyKey(array $input): ?string
    {
        $key = trim((string) ($input['idempotency_key'] ?? ''));
        if ($key === '') {
            $header = (string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '');
            $key = trim($header);
        }

        return $key === '' ? null : $key;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function version(array $input): ?int
    {
        if (!array_key_exists('version', $input) || $input['version'] === null || $input['version'] === '') {
            return null;
        }

        return (int) $input['version'];
    }

    /**
     * @param array<string, mixed> $query
     * @return array{0:int, 1:int}
     */
    private function pageOf(array $query): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = (int) ($query['per_page'] ?? self::PER_PAGE_DEFAULT);
        $perPage = max(1, min(self::PER_PAGE_MAX, $perPage === 0 ? self::PER_PAGE_DEFAULT : $perPage));

        return [$page, $perPage];
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function filtersOf(array $query, bool $antreanOnly): array
    {
        $filters = [
            'q' => trim((string) ($query['q'] ?? '')),
            'status' => (string) ($query['status'] ?? ''),
            'source' => (string) ($query['source'] ?? ''),
            'date_from' => (string) ($query['date_from'] ?? ''),
            'date_to' => (string) ($query['date_to'] ?? ''),
            'santri_id' => (int) ($query['santri_id'] ?? 0),
        ];
        if ($antreanOnly) {
            $filters['antrean'] = '1';
        }

        return $filters;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function presentFilters(array $filters, bool $antreanOnly): array
    {
        return [
            'q' => (string) $filters['q'],
            'status' => (string) $filters['status'],
            'source' => (string) $filters['source'],
            'date_from' => (string) $filters['date_from'],
            'date_to' => (string) $filters['date_to'],
            'santri_id' => (int) $filters['santri_id'] === 0 ? null : (int) $filters['santri_id'],
            'antrean' => $antreanOnly,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function pagination(int $page, int $perPage, int $total): array
    {
        return [
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $total === 0 ? 0 : (int) ceil($total / $perPage),
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array{id:int, roles:array<int,string>, guru_id:int|null}
     */
    private function probe(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'roles' => array_values((array) ($user['roles'] ?? [])),
            'guru_id' => $this->guruId($user),
        ];
    }

    /**
     * @param array<string, mixed> $user
     */
    private function guruId(array $user): ?int
    {
        return ($user['guru_id'] ?? null) === null ? null : (int) $user['guru_id'];
    }

    /**
     * @param array<int, string> $capabilities
     */
    private function defaultMode(array $capabilities): ?string
    {
        foreach ([Capabilities::ADMIN, Capabilities::PENGURUS, Capabilities::MUROBI, Capabilities::ORANG_TUA] as $candidate) {
            if (in_array($candidate, $capabilities, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Menerjemahkan `IzinException` domain ke `ApiException` beserta status aslinya.
     *
     * Kode error dipilih agar klien dapat membedakan tindakan lanjutan:
     *   403 FORBIDDEN          -> muat ulang cakupan / pindah mode,
     *   404 NOT_FOUND          -> sumber daya tidak ada,
     *   409 CONFLICT           -> muat ulang lalu ulangi dengan versi terbaru,
     *   422 VALIDATION_FAILED  -> perbaiki isian.
     *
     * @template T
     * @param callable():T $operation
     * @return T
     */
    private function translate(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (IzinException $exception) {
            throw new ApiException(
                match ($exception->status()) {
                    403 => 'FORBIDDEN',
                    404 => 'NOT_FOUND',
                    409 => 'CONFLICT',
                    default => 'VALIDATION_FAILED',
                },
                $exception->getMessage(),
                $exception->status()
            );
        } catch (ApiException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            error_log((string) $exception);
            throw new ApiException('SERVER_ERROR', 'Permintaan perizinan tidak dapat diproses.', 500);
        }
    }
}
