<?php

declare(strict_types=1);

namespace App\Penugasan;

use App\Audit\AuditLogger;
use App\Auth\Capabilities;
use RuntimeException;
use Throwable;

/**
 * Satu-satunya pintu masuk pembuatan, perubahan, pengaktifan, penonaktifan,
 * dan pengakhiran penugasan fungsional (fondasi PRD V3–V6, keputusan pengguna
 * 7 September 2026).
 *
 * Aturan yang ditegakkan di sini — di server, bukan di formulir:
 *
 *   - hanya akun ber-role `admin` aktif yang boleh memanggil mutasi;
 *   - guru/pengurus subjek harus aktif dan belum diarsipkan;
 *   - tahun ajaran harus belum diarsipkan (murobi, pembimbing, guru mapel,
 *     pendidikan, bendahara bulanan: capability baru muncul bila tahun ajaran
 *     berstatus Aktif; PSB boleh untuk tahun yang belum aktif);
 *   - cakupan harus merujuk kelas aktif / kamar yang ada / mata pelajaran
 *     aktif / jenjang yang dipakai kelas / label gelombang yang wajar;
 *   - tanggal selesai tidak boleh mendahului tanggal mulai;
 *   - penugasan IDENTIK atau BERTUMPANG TINDIH (orang, tahun ajaran, cakupan
 *     yang sama atau saling mencakup, periode beririsan) DITOLAK;
 *   - tidak ada penghapusan: penugasan diakhiri lewat `tanggal_selesai`
 *     atau dinonaktifkan lewat `is_active`;
 *   - setiap mutasi + auditnya berjalan dalam SATU transaksi; kegagalan audit
 *     membatalkan mutasi;
 *   - perubahan yang mengubah capability akun terkait dicatat tersendiri.
 *
 * Halaman admin hanya memanggil layanan ini; tidak ada query mutasi di halaman.
 * Halaman `admin_murobi.php` dan `admin_pembimbing.php` lama tetap memakai
 * layanan lamanya (kompatibilitas), sedangkan pusat penugasan memakai kelas
 * ini untuk seluruh jenis termasuk murobi dan pembimbing.
 */
final class PenugasanService
{
    public const STATUS_AKTIF = 'Aktif';
    public const STATUS_AKAN_DATANG = 'Akan Datang';
    public const STATUS_BERAKHIR = 'Berakhir';
    public const STATUS_NONAKTIF = 'Dinonaktifkan';

    private const ALASAN_MIN = 5;
    private const ALASAN_MAX = 500;

    public function __construct(
        private PenugasanRepository $repository,
        private AuditLogger $audit,
        private Capabilities $capabilities
    ) {
    }

    // =======================================================================
    // Pembacaan
    // =======================================================================

    /**
     * @param array<string, mixed> $filters
     * @return array{rows:array<int, array<string, mixed>>, total:int, page:int, perPage:int}
     */
    public function daftar(string $jenis, array $filters, int $page): array
    {
        $this->jenis($jenis);
        $hasil = $this->repository->page($jenis, $filters, max(1, $page));
        $hasil['rows'] = array_map(fn (array $row): array => $this->hias($jenis, $row), $hasil['rows']);

        return $hasil;
    }

    public function detail(string $jenis, int $id): ?array
    {
        $this->jenis($jenis);
        $row = $this->repository->detail($jenis, $id);

        return $row === null ? null : $this->hias($jenis, $row);
    }

    /**
     * Status turunan dari data, bukan dari kolom status tersendiri.
     *
     * @param array<string, mixed> $row
     */
    public function status(array $row, ?string $today = null): string
    {
        $today ??= date('Y-m-d');
        if ((int) ($row['is_active'] ?? 0) !== 1 || !empty($row['archived_at'])) {
            return self::STATUS_NONAKTIF;
        }
        if ((string) $row['tanggal_mulai'] > $today) {
            return self::STATUS_AKAN_DATANG;
        }
        if (!empty($row['tanggal_selesai']) && (string) $row['tanggal_selesai'] < $today) {
            return self::STATUS_BERAKHIR;
        }

        return self::STATUS_AKTIF;
    }

    /**
     * Opsi formulir untuk satu jenis.
     *
     * @return array<string, mixed>
     */
    public function opsi(string $jenis): array
    {
        $definisi = $this->jenis($jenis);
        $opsi = [
            'subjek' => $definisi['subjek'] === 'guru' ? $this->repository->guruOptions() : $this->repository->pengurusOptions(),
            'tahun' => $this->repository->tahunOptions(),
        ];
        switch ($definisi['cakupan']) {
            case PenugasanJenis::CAKUPAN_TARGET:
                $opsi['kamar'] = $this->repository->kamarOptions();
                $opsi['kelas'] = $this->repository->kelasOptions();
                break;
            case PenugasanJenis::CAKUPAN_MAPEL_KELAS:
                $opsi['mata_pelajaran'] = $this->repository->mataPelajaranOptions();
                $opsi['kelas'] = $this->repository->kelasOptions();
                break;
            case PenugasanJenis::CAKUPAN_JENJANG:
                $opsi['jenjang'] = $this->repository->jenjangOptions();
                break;
        }

        return $opsi;
    }

    /**
     * Capability efektif yang benar-benar diperoleh subjek dari penugasan
     * jenis ini, beserta penjelasan bila belum efektif (belum punya akun,
     * akun nonaktif, atau role dasar belum diberikan).
     *
     * @return array{akun:?array<string, mixed>, capabilities:array<int, string>, semua:array<int, string>, pesan:?string}
     */
    public function capabilityEfektif(string $jenis, int $subjekId): array
    {
        $definisi = $this->jenis($jenis);
        $akun = $this->repository->userForSubject($jenis, $subjekId);
        if ($akun === null) {
            return [
                'akun' => null, 'capabilities' => [], 'semua' => [],
                'pesan' => ucfirst($definisi['label_subjek']) . ' ini belum mempunyai akun. Penugasan tetap tersimpan; capability baru efektif setelah akun dibuat dan diberi role ' . $definisi['role_dasar'] . '.',
            ];
        }
        if (!$akun['is_active']) {
            return ['akun' => $akun, 'capabilities' => [], 'semua' => [], 'pesan' => 'Akun ' . $akun['username'] . ' sedang nonaktif, sehingga tidak ada capability yang efektif.'];
        }
        if (!in_array($definisi['role_dasar'], $akun['roles'], true)) {
            return ['akun' => $akun, 'capabilities' => [], 'semua' => [], 'pesan' => 'Akun ' . $akun['username'] . ' belum memegang role ' . $definisi['role_dasar'] . '. Berikan role dasar itu pada halaman Akun & Hak Akses agar penugasan ini efektif.'];
        }

        $this->capabilities->forget($akun['id']);
        $semua = $this->capabilities->featureList($akun);
        $dariJenis = array_values(array_intersect($definisi['capabilities'], $semua));

        return [
            'akun' => $akun,
            'capabilities' => $dariJenis,
            'semua' => $semua,
            'pesan' => $dariJenis === [] ? 'Akun sudah memenuhi syarat, tetapi belum ada penugasan ' . $definisi['label'] . ' yang AKTIF pada tanggal ini dan tahun ajaran yang berlaku.' : null,
        ];
    }

    // =======================================================================
    // Mutasi
    // =======================================================================

    /**
     * @param array<string, mixed> $input
     */
    public function buat(string $jenis, array $input, int $actorId): int
    {
        $definisi = $this->jenis($jenis);
        $data = null;

        try {
            $this->requireAdmin($actorId);
            $data = $this->normalisasi($jenis, $input, null);

            return $this->repository->transaction(function () use ($jenis, $definisi, $data, $actorId): int {
                // Gerbang serialisasi per orang: mutasi bersamaan untuk subjek
                // yang sama menunggu di sini, lalu membaca keadaan terbaru.
                $this->repository->lockSubjectMaster($jenis, $data['subjek_id']);
                $akun = $this->repository->userForSubject($jenis, $data['subjek_id']);
                $sebelum = $this->potretCapability($akun);

                $terkunci = $this->repository->lockForSubject($jenis, $data['subjek_id'], $data['tahun_ajaran_id']);
                $this->tolakTumpangTindih($jenis, $data, $terkunci, null);

                $id = $this->repository->insert($jenis, $data, $actorId);
                $this->auditRequired('penugasan.buat', $definisi['entitas'], $id, null, $this->ringkasAudit($jenis, $data + ['id' => $id]), $actorId);
                $this->auditCapability($akun, $sebelum, $actorId, $definisi['entitas'], $id);

                return $id;
            });
        } catch (PenugasanException $exception) {
            $this->catatPenolakan($jenis, $exception, $data ?? $input, $actorId);
            throw $exception;
        }
    }

    /**
     * Mengubah cakupan, masa berlaku, dan catatan. Alasan wajib.
     *
     * @param array<string, mixed> $input
     */
    public function ubah(string $jenis, int $id, array $input, int $actorId): void
    {
        $definisi = $this->jenis($jenis);

        try {
            $this->requireAdmin($actorId);
            $alasan = $this->alasan($input['alasan'] ?? '', 'perubahan');
            $this->repository->transaction(function () use ($jenis, $definisi, $id, $input, $alasan, $actorId): void {
                $lama = $this->kunciBaris($jenis, $id);
                if (!empty($lama['archived_at'])) {
                    throw PenugasanException::invalid('Penugasan yang sudah diarsipkan tidak dapat diubah. Buat penugasan baru bila diperlukan.');
                }
                // Subjek dan tahun ajaran dikunci pada nilai lama: ganti orang atau
                // tahun ajaran berarti penugasan baru, bukan suntingan.
                $input[$definisi['subjek_kolom']] = $lama[$definisi['subjek_kolom']];
                $input['tahun_ajaran_id'] = $lama['tahun_ajaran_id'];
                $data = $this->normalisasi($jenis, $input, $lama);
                $data['alasan'] = $alasan;

                $akun = $this->repository->userForSubject($jenis, $data['subjek_id']);
                $sebelum = $this->potretCapability($akun);

                $terkunci = $this->repository->lockForSubject($jenis, $data['subjek_id'], $data['tahun_ajaran_id']);
                if ((int) $lama['is_active'] === 1) {
                    $this->tolakTumpangTindih($jenis, $data, $terkunci, $id);
                }

                $this->repository->update($jenis, $id, $data, $actorId);
                $baru = $this->repository->find($jenis, $id);
                $this->auditRequired(
                    'penugasan.ubah',
                    $definisi['entitas'],
                    $id,
                    $this->ringkasAudit($jenis, $lama),
                    $this->ringkasAudit($jenis, $baru ?? []) + ['alasan' => $alasan],
                    $actorId
                );
                $this->auditCapability($akun, $sebelum, $actorId, $definisi['entitas'], $id);
            });
        } catch (PenugasanException $exception) {
            $this->catatPenolakan($jenis, $exception, ['id' => $id] + $input, $actorId);
            throw $exception;
        }
    }

    /**
     * Mengakhiri penugasan secara historis: baris tetap ada, `tanggal_selesai`
     * diisi, pelaku dan alasan tercatat.
     */
    public function akhiri(string $jenis, int $id, string $tanggalSelesai, string $alasan, int $actorId): void
    {
        $definisi = $this->jenis($jenis);

        try {
            $this->requireAdmin($actorId);
            $alasan = $this->alasan($alasan, 'pengakhiran');
            $tanggal = $this->tanggal($tanggalSelesai);
            if ($tanggal === null) {
                throw PenugasanException::invalid('Tanggal selesai wajib diisi dengan tanggal yang valid.', ['tanggal_selesai' => 'Tanggal selesai tidak valid.']);
            }
            $this->repository->transaction(function () use ($jenis, $definisi, $id, $tanggal, $alasan, $actorId): void {
                $lama = $this->kunciBaris($jenis, $id);
                if ($tanggal < (string) $lama['tanggal_mulai']) {
                    throw PenugasanException::invalid('Tanggal selesai tidak boleh mendahului tanggal mulai (' . $lama['tanggal_mulai'] . ').', ['tanggal_selesai' => 'Tanggal selesai mendahului tanggal mulai.']);
                }
                if (!empty($lama['diakhiri_pada']) && (string) $lama['tanggal_selesai'] === $tanggal) {
                    throw PenugasanException::conflict('Penugasan ini sudah diakhiri pada tanggal yang sama.');
                }

                $akun = $this->repository->userForSubject($jenis, (int) $lama[$definisi['subjek_kolom']]);
                $sebelum = $this->potretCapability($akun);

                $this->repository->end($jenis, $id, $tanggal, $alasan, $actorId);
                $baru = $this->repository->find($jenis, $id);
                $this->auditRequired(
                    'penugasan.akhiri',
                    $definisi['entitas'],
                    $id,
                    $this->ringkasAudit($jenis, $lama),
                    $this->ringkasAudit($jenis, $baru ?? []) + ['alasan' => $alasan],
                    $actorId
                );
                $this->auditCapability($akun, $sebelum, $actorId, $definisi['entitas'], $id);
            });
        } catch (PenugasanException $exception) {
            $this->catatPenolakan($jenis, $exception, ['id' => $id, 'tanggal_selesai' => $tanggalSelesai], $actorId);
            throw $exception;
        }
    }

    public function nonaktifkan(string $jenis, int $id, string $alasan, int $actorId): void
    {
        $this->ubahStatusAktif($jenis, $id, false, $alasan, $actorId);
    }

    public function aktifkan(string $jenis, int $id, string $alasan, int $actorId): void
    {
        $this->ubahStatusAktif($jenis, $id, true, $alasan, $actorId);
    }

    // =======================================================================
    // Master mata pelajaran (minimum yang diperlukan penugasan guru)
    // =======================================================================

    /**
     * @return array{rows:array<int, array<string, mixed>>, total:int, page:int, perPage:int}
     */
    public function mataPelajaranDaftar(string $q, int $page): array
    {
        return $this->repository->mataPelajaranPage($q, max(1, $page));
    }

    public function mataPelajaran(int $id): ?array
    {
        return $this->repository->mataPelajaranFind($id);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function simpanMataPelajaran(array $input, ?int $id, int $actorId): int
    {
        $this->requireAdmin($actorId);
        $nama = trim(preg_replace('/\s+/u', ' ', (string) ($input['nama'] ?? '')) ?? '');
        $kode = strtoupper(trim((string) ($input['kode'] ?? '')));
        $kategori = trim((string) ($input['kategori'] ?? ''));
        $errors = [];
        if ($nama === '' || mb_strlen($nama) > 100) {
            $errors['nama'] = 'Nama mata pelajaran wajib diisi, maksimal 100 karakter.';
        }
        if ($kode !== '' && !preg_match('/^[A-Z0-9._-]{1,20}$/', $kode)) {
            $errors['kode'] = 'Kode hanya boleh huruf, angka, titik, garis bawah, atau tanda hubung (maksimal 20).';
        }
        if (mb_strlen($kategori) > 30) {
            $errors['kategori'] = 'Kategori maksimal 30 karakter.';
        }
        if ($errors !== []) {
            throw PenugasanException::invalid(implode(' ', $errors), $errors);
        }
        $data = ['kode' => $kode === '' ? null : $kode, 'nama' => $nama, 'kategori' => $kategori === '' ? null : $kategori];

        return $this->repository->transaction(function () use ($data, $id, $actorId): int {
            $kembar = $this->repository->mataPelajaranDuplikat($data['nama'], $data['kode'], $id);
            if ($kembar !== null) {
                throw PenugasanException::conflict('Nama atau kode mata pelajaran sudah dipakai oleh "' . $kembar['nama'] . '" (#' . (int) $kembar['id'] . ').');
            }
            if ($id === null) {
                $baru = $this->repository->mataPelajaranInsert($data, $actorId);
                $this->auditRequired('mata_pelajaran.buat', 'mata_pelajaran', $baru, null, $data, $actorId);

                return $baru;
            }
            $lama = $this->repository->mataPelajaranFind($id);
            if ($lama === null) {
                throw PenugasanException::notFound('Mata pelajaran tidak ditemukan.');
            }
            $this->repository->mataPelajaranUpdate($id, $data, $actorId);
            $this->auditRequired('mata_pelajaran.ubah', 'mata_pelajaran', $id, ['kode' => $lama['kode'], 'nama' => $lama['nama'], 'kategori' => $lama['kategori']], $data, $actorId);

            return $id;
        });
    }

    public function setMataPelajaranState(int $id, string $action, int $actorId): void
    {
        $this->requireAdmin($actorId);
        $this->repository->transaction(function () use ($id, $action, $actorId): void {
            $lama = $this->repository->mataPelajaranFind($id);
            if ($lama === null) {
                throw PenugasanException::notFound('Mata pelajaran tidak ditemukan.');
            }
            match ($action) {
                'activate' => $this->repository->mataPelajaranSetState($id, true, null, $actorId),
                'deactivate' => $this->repository->mataPelajaranSetState($id, false, null, $actorId),
                'archive' => $this->repository->mataPelajaranSetState($id, false, true, $actorId),
                'restore' => $this->repository->mataPelajaranSetState($id, true, false, $actorId),
                default => throw PenugasanException::invalid('Aksi mata pelajaran tidak dikenal.'),
            };
            $baru = $this->repository->mataPelajaranFind($id);
            $this->auditRequired(
                'mata_pelajaran.status',
                'mata_pelajaran',
                $id,
                ['is_active' => (int) $lama['is_active'], 'archived_at' => $lama['archived_at']],
                ['is_active' => (int) ($baru['is_active'] ?? 0), 'archived_at' => $baru['archived_at'] ?? null, 'action' => $action],
                $actorId
            );
        });
    }

    // =======================================================================
    // Bagian dalam
    // =======================================================================

    private function ubahStatusAktif(string $jenis, int $id, bool $aktif, string $alasan, int $actorId): void
    {
        $definisi = $this->jenis($jenis);

        try {
            $this->requireAdmin($actorId);
            $alasan = $this->alasan($alasan, $aktif ? 'pengaktifan' : 'penonaktifan');
            $this->repository->transaction(function () use ($jenis, $definisi, $id, $aktif, $alasan, $actorId): void {
                $lama = $this->kunciBaris($jenis, $id);
                if (!empty($lama['archived_at'])) {
                    throw PenugasanException::invalid('Penugasan yang diarsipkan hanya dapat dipulihkan dari halaman lamanya.');
                }
                if (((int) $lama['is_active'] === 1) === $aktif) {
                    throw PenugasanException::conflict($aktif ? 'Penugasan ini sudah aktif.' : 'Penugasan ini sudah dinonaktifkan.');
                }

                $subjekId = (int) $lama[$definisi['subjek_kolom']];
                $akun = $this->repository->userForSubject($jenis, $subjekId);
                $sebelum = $this->potretCapability($akun);

                if ($aktif) {
                    // Mengaktifkan kembali harus melewati pemeriksaan tumpang tindih
                    // yang sama dengan pembuatan baru.
                    $data = $this->dataDariBaris($jenis, $lama);
                    $terkunci = $this->repository->lockForSubject($jenis, $subjekId, (int) $lama['tahun_ajaran_id']);
                    $this->tolakTumpangTindih($jenis, $data, $terkunci, $id);
                }

                $this->repository->setActive($jenis, $id, $aktif, $alasan, $actorId);
                $baru = $this->repository->find($jenis, $id);
                $this->auditRequired(
                    $aktif ? 'penugasan.aktifkan' : 'penugasan.nonaktifkan',
                    $definisi['entitas'],
                    $id,
                    $this->ringkasAudit($jenis, $lama),
                    $this->ringkasAudit($jenis, $baru ?? []) + ['alasan' => $alasan],
                    $actorId
                );
                $this->auditCapability($akun, $sebelum, $actorId, $definisi['entitas'], $id);
            });
        } catch (PenugasanException $exception) {
            $this->catatPenolakan($jenis, $exception, ['id' => $id, 'aktif' => $aktif], $actorId);
            throw $exception;
        }
    }

    /**
     * Mengunci baris penugasan beserta gerbang subjeknya, dalam urutan yang
     * SELALU sama (master subjek dahulu, baru baris penugasan) agar dua
     * transaksi tidak pernah saling menunggu secara silang (deadlock).
     *
     * @return array<string, mixed>
     */
    private function kunciBaris(string $jenis, int $id): array
    {
        $definisi = PenugasanJenis::definisi($jenis);
        $awal = $this->repository->find($jenis, $id);
        if ($awal === null) {
            throw PenugasanException::notFound();
        }
        $this->repository->lockSubjectMaster($jenis, (int) $awal[$definisi['subjek_kolom']]);
        $lama = $this->repository->find($jenis, $id, true);
        if ($lama === null) {
            throw PenugasanException::notFound();
        }

        return $lama;
    }

    /**
     * @return array<string, mixed>
     */
    private function jenis(string $jenis): array
    {
        if (!PenugasanJenis::valid($jenis)) {
            throw PenugasanException::invalid('Jenis penugasan tidak dikenal.');
        }

        return PenugasanJenis::definisi($jenis);
    }

    private function requireAdmin(int $actorId): void
    {
        if ($actorId < 1 || !$this->repository->actorIsAdmin($actorId)) {
            throw PenugasanException::forbidden();
        }
    }

    /**
     * Validasi dan normalisasi seluruh isian di server.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $lama baris lama saat mengubah
     * @return array<string, mixed>
     */
    private function normalisasi(string $jenis, array $input, ?array $lama): array
    {
        $definisi = PenugasanJenis::definisi($jenis);
        $errors = [];

        $subjekId = (int) ($input[$definisi['subjek_kolom']] ?? 0);
        $subjek = $definisi['subjek'] === 'guru' ? $this->repository->guruAktif($subjekId) : $this->repository->pengurusAktif($subjekId);
        if ($subjekId < 1 || $subjek === null) {
            $errors[$definisi['subjek_kolom']] = ucfirst($definisi['label_subjek']) . ' harus dipilih dari data ' . $definisi['label_subjek'] . ' yang aktif dan belum diarsipkan.';
        }

        $tahunId = (int) ($input['tahun_ajaran_id'] ?? 0);
        $tahun = $tahunId > 0 ? $this->repository->tahunTersedia($tahunId) : null;
        if ($tahun === null) {
            $errors['tahun_ajaran_id'] = 'Tahun ajaran tidak valid atau sudah diarsipkan.';
        }

        $data = [
            'subjek_id' => $subjekId,
            'tahun_ajaran_id' => $tahunId,
            'catatan' => $this->teksOpsional($input['catatan'] ?? '', 500, 'Catatan', $errors, 'catatan'),
        ];

        switch ($definisi['cakupan']) {
            case PenugasanJenis::CAKUPAN_TARGET:
                $target = (string) ($input['target_type'] ?? '');
                if (!in_array($target, ['Kamar', 'Kelas'], true)) {
                    $errors['target_type'] = 'Jenis kelompok harus Kamar atau Kelas.';
                }
                $kamarId = $target === 'Kamar' ? (int) ($input['kamar_id'] ?? 0) : 0;
                $kelasId = $target === 'Kelas' ? (int) ($input['kelas_id'] ?? 0) : 0;
                if ($target === 'Kamar' && ($kamarId < 1 || $this->repository->kamarAda($kamarId) === null)) {
                    $errors['kamar_id'] = 'Kamar tidak ditemukan.';
                }
                if ($target === 'Kelas' && ($kelasId < 1 || $this->repository->kelasAktif($kelasId) === null)) {
                    $errors['kelas_id'] = 'Kelas tidak ditemukan atau sudah tidak aktif.';
                }
                $data += ['target_type' => $target, 'kamar_id' => $kamarId > 0 ? $kamarId : null, 'kelas_id' => $kelasId > 0 ? $kelasId : null];
                break;

            case PenugasanJenis::CAKUPAN_MAPEL_KELAS:
                $mapelId = (int) ($input['mata_pelajaran_id'] ?? 0);
                $kelasId = (int) ($input['kelas_id'] ?? 0);
                if ($mapelId < 1 || $this->repository->mataPelajaranAktif($mapelId) === null) {
                    $errors['mata_pelajaran_id'] = 'Mata pelajaran harus dipilih dari master yang aktif.';
                }
                if ($kelasId < 1 || $this->repository->kelasAktif($kelasId) === null) {
                    $errors['kelas_id'] = 'Kelas tidak ditemukan atau sudah tidak aktif.';
                }
                $data += ['mata_pelajaran_id' => $mapelId, 'kelas_id' => $kelasId];
                break;

            case PenugasanJenis::CAKUPAN_JENJANG:
                $jenjang = trim((string) ($input['jenjang'] ?? ''));
                if ($jenjang !== '' && (mb_strlen($jenjang) > 20 || !$this->repository->jenjangTersedia($jenjang))) {
                    $errors['jenjang'] = 'Unit/jenjang harus salah satu jenjang yang dipakai data kelas, atau dikosongkan untuk seluruh unit.';
                }
                $data['jenjang'] = $jenjang === '' ? null : $jenjang;
                break;

            case PenugasanJenis::CAKUPAN_GELOMBANG:
                $gelombang = trim(preg_replace('/\s+/u', ' ', (string) ($input['gelombang'] ?? '')) ?? '');
                if ($gelombang !== '' && (mb_strlen($gelombang) > 50 || !preg_match('/^[\pL\pN .\/_-]+$/u', $gelombang))) {
                    $errors['gelombang'] = 'Label gelombang maksimal 50 karakter huruf/angka, atau dikosongkan untuk seluruh gelombang.';
                }
                $data['gelombang'] = $gelombang === '' ? null : $gelombang;
                break;
        }

        $mulai = $this->tanggal((string) ($input['tanggal_mulai'] ?? ''));
        if ($mulai === null) {
            $errors['tanggal_mulai'] = 'Tanggal mulai wajib diisi dengan tanggal yang valid.';
        }
        $selesaiRaw = trim((string) ($input['tanggal_selesai'] ?? ''));
        $selesai = $selesaiRaw === '' ? null : $this->tanggal($selesaiRaw);
        if ($selesaiRaw !== '' && $selesai === null) {
            $errors['tanggal_selesai'] = 'Tanggal selesai tidak valid.';
        }
        if ($mulai !== null && $selesai !== null && $selesai < $mulai) {
            $errors['tanggal_selesai'] = 'Tanggal selesai tidak boleh mendahului tanggal mulai.';
        }
        $data['tanggal_mulai'] = $mulai ?? '';
        $data['tanggal_selesai'] = $selesai;

        if ($errors !== []) {
            throw PenugasanException::invalid(implode(' ', $errors), $errors);
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function dataDariBaris(string $jenis, array $row): array
    {
        $definisi = PenugasanJenis::definisi($jenis);
        $data = [
            'subjek_id' => (int) $row[$definisi['subjek_kolom']],
            'tahun_ajaran_id' => (int) $row['tahun_ajaran_id'],
            'tanggal_mulai' => (string) $row['tanggal_mulai'],
            'tanggal_selesai' => $row['tanggal_selesai'] === null ? null : (string) $row['tanggal_selesai'],
            'catatan' => $row['catatan'] ?? null,
        ];
        foreach (['target_type', 'kamar_id', 'kelas_id', 'mata_pelajaran_id', 'jenjang', 'gelombang'] as $kolom) {
            if (array_key_exists($kolom, $row)) {
                $data[$kolom] = $row[$kolom];
            }
        }

        return $data;
    }

    /**
     * Kunci cakupan untuk perbandingan tumpang tindih.
     *
     * @param array<string, mixed> $data
     */
    private function kunciCakupan(string $jenis, array $data): string
    {
        return match (PenugasanJenis::definisi($jenis)['cakupan']) {
            PenugasanJenis::CAKUPAN_TARGET => (string) $data['target_type'] . ':' . (int) ($data['kamar_id'] ?? $data['kelas_id'] ?? 0),
            PenugasanJenis::CAKUPAN_MAPEL_KELAS => (int) $data['mata_pelajaran_id'] . ':' . (int) $data['kelas_id'],
            PenugasanJenis::CAKUPAN_JENJANG => $data['jenjang'] === null || $data['jenjang'] === '' ? '*' : mb_strtolower((string) $data['jenjang']),
            PenugasanJenis::CAKUPAN_GELOMBANG => $data['gelombang'] === null || $data['gelombang'] === '' ? '*' : mb_strtolower((string) $data['gelombang']),
            default => '',
        };
    }

    /**
     * Cakupan dianggap bentrok bila identik, atau bila salah satunya adalah
     * cakupan "seluruh" (`*`) yang sudah mencakup yang lain.
     */
    private function cakupanBentrok(string $a, string $b): bool
    {
        return $a === $b || $a === '*' || $b === '*';
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, array<string, mixed>> $terkunci
     */
    private function tolakTumpangTindih(string $jenis, array $data, array $terkunci, ?int $kecualiId): void
    {
        $kunci = $this->kunciCakupan($jenis, $data);
        $mulai = (string) $data['tanggal_mulai'];
        $selesai = $data['tanggal_selesai'];
        $bentrok = [];
        foreach ($terkunci as $baris) {
            if ($kecualiId !== null && (int) $baris['id'] === $kecualiId) {
                continue;
            }
            if ((int) $baris['is_active'] !== 1 || !empty($baris['archived_at'])) {
                continue;
            }
            if (!$this->cakupanBentrok($kunci, $this->kunciCakupan($jenis, $this->dataDariBaris($jenis, $baris)))) {
                continue;
            }
            $barisMulai = (string) $baris['tanggal_mulai'];
            $barisSelesai = $baris['tanggal_selesai'] === null ? null : (string) $baris['tanggal_selesai'];
            $beririsan = ($selesai === null || $barisMulai <= $selesai) && ($barisSelesai === null || $barisSelesai >= $mulai);
            if ($beririsan) {
                $bentrok[] = (int) $baris['id'];
            }
        }
        if ($bentrok !== []) {
            throw PenugasanException::conflict(
                'Penugasan bertumpang tindih dengan penugasan aktif #' . implode(', #', $bentrok)
                . ' untuk orang, tahun ajaran, dan cakupan yang sama pada periode yang beririsan. '
                . 'Akhiri penugasan lama lebih dahulu atau sesuaikan cakupan/periode.'
            );
        }
    }

    /**
     * Ringkasan nilai untuk audit: tanpa credential, hanya kolom bisnis.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function ringkasAudit(string $jenis, array $row): array
    {
        $definisi = PenugasanJenis::definisi($jenis);
        $ringkas = ['jenis' => $jenis];
        $ringkas['subjek_id'] = (int) ($row['subjek_id'] ?? $row[$definisi['subjek_kolom']] ?? 0);
        foreach (['id', 'tahun_ajaran_id', 'target_type', 'kamar_id', 'kelas_id', 'mata_pelajaran_id', 'jenjang', 'gelombang',
            'tanggal_mulai', 'tanggal_selesai', 'is_active', 'catatan', 'diakhiri_pada', 'alasan_pengakhiran'] as $kolom) {
            if (array_key_exists($kolom, $row)) {
                $ringkas[$kolom] = is_numeric($row[$kolom]) && in_array($kolom, ['id', 'tahun_ajaran_id', 'kamar_id', 'kelas_id', 'mata_pelajaran_id', 'is_active'], true)
                    ? (int) $row[$kolom]
                    : $row[$kolom];
            }
        }

        return $ringkas;
    }

    /**
     * Potret capability akun sebelum mutasi (base + feature), atau null bila
     * subjek belum punya akun.
     *
     * @param array<string, mixed>|null $akun
     * @return array{dasar:array<int,string>, fitur:array<int,string>}|null
     */
    private function potretCapability(?array $akun): ?array
    {
        if ($akun === null) {
            return null;
        }
        $this->capabilities->forget($akun['id']);

        return [
            'dasar' => $this->capabilities->forUser($akun),
            'fitur' => $this->capabilities->featureList($akun),
        ];
    }

    /**
     * Mencatat perubahan capability akun (bertambah atau berkurang) sebagai
     * peristiwa audit tersendiri, di dalam transaksi yang sama.
     *
     * @param array<string, mixed>|null $akun
     * @param array{dasar:array<int,string>, fitur:array<int,string>}|null $sebelum
     */
    private function auditCapability(?array $akun, ?array $sebelum, int $actorId, string $entitas, int $penugasanId): void
    {
        if ($akun === null || $sebelum === null) {
            return;
        }
        $sesudah = $this->potretCapability($akun);
        if ($sesudah === null || ($sesudah['dasar'] === $sebelum['dasar'] && $sesudah['fitur'] === $sebelum['fitur'])) {
            return;
        }
        $this->auditRequired(
            'penugasan.capability_berubah',
            'user',
            (int) $akun['id'],
            $sebelum,
            $sesudah + [
                'bertambah' => array_values(array_diff($sesudah['fitur'], $sebelum['fitur'])),
                'berkurang' => array_values(array_diff($sebelum['fitur'], $sesudah['fitur'])),
                'pemicu' => ['entitas' => $entitas, 'id' => $penugasanId],
            ],
            $actorId
        );
    }

    /**
     * Percobaan yang ditolak karena duplikat/tumpang tindih atau hak, dicatat
     * di luar transaksi (transaksi sudah dibatalkan).
     *
     * @param array<string, mixed> $input
     */
    private function catatPenolakan(string $jenis, PenugasanException $exception, array $input, int $actorId): void
    {
        if (!in_array($exception->status(), [403, 409], true)) {
            return;
        }
        $definisi = PenugasanJenis::definisi($jenis);
        $aman = [];
        foreach (['id', 'subjek_id', $definisi['subjek_kolom'], 'tahun_ajaran_id', 'target_type', 'kamar_id', 'kelas_id',
            'mata_pelajaran_id', 'jenjang', 'gelombang', 'tanggal_mulai', 'tanggal_selesai', 'aktif'] as $kolom) {
            if (array_key_exists($kolom, $input) && is_scalar($input[$kolom])) {
                $aman[$kolom] = $input[$kolom];
            }
        }
        $this->audit->log(
            $exception->status() === 409 ? 'penugasan.tolak_tumpang_tindih' : 'penugasan.tolak_hak',
            $definisi['entitas'],
            isset($input['id']) ? (int) $input['id'] : null,
            null,
            ['jenis' => $jenis, 'pesan' => $exception->getMessage(), 'isian' => $aman],
            $actorId > 0 ? $actorId : null
        );
    }

    /**
     * Audit adalah bagian transaksi; kegagalannya membatalkan mutasi.
     */
    private function auditRequired(string $action, string $entityType, int $id, ?array $before, array $after, int $actorId): void
    {
        if (!$this->audit->log($action, $entityType, $id, $before, $after, $actorId)) {
            throw new RuntimeException('Perubahan penugasan dibatalkan karena audit tidak dapat disimpan. Silakan coba lagi.');
        }
    }

    /**
     * @param array<string, string> $errors
     */
    private function teksOpsional(mixed $value, int $maks, string $label, array &$errors, string $field): ?string
    {
        $teks = trim((string) $value);
        if ($teks === '') {
            return null;
        }
        if (mb_strlen($teks) > $maks) {
            $errors[$field] = $label . ' maksimal ' . $maks . ' karakter.';
        }

        return $teks;
    }

    private function alasan(mixed $value, string $untuk): string
    {
        $teks = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');
        if (mb_strlen($teks) < self::ALASAN_MIN || mb_strlen($teks) > self::ALASAN_MAX) {
            throw PenugasanException::invalid(
                'Alasan ' . $untuk . ' wajib diisi (' . self::ALASAN_MIN . '–' . self::ALASAN_MAX . ' karakter).',
                ['alasan' => 'Alasan ' . $untuk . ' wajib diisi.']
            );
        }

        return $teks;
    }

    private function tanggal(string $value): ?string
    {
        $value = trim($value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year) ? $value : null;
    }

    /**
     * Melengkapi baris daftar dengan status turunan dan teks masa berlaku.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hias(string $jenis, array $row): array
    {
        $row['jenis'] = $jenis;
        $row['status_label'] = $this->status($row);
        $row['masa_berlaku'] = $row['tanggal_mulai'] . ' — ' . ($row['tanggal_selesai'] ?: 'seterusnya');
        $row['user_roles_list'] = !empty($row['user_roles']) ? explode(',', (string) $row['user_roles']) : [];

        return $row;
    }
}
