<?php

declare(strict_types=1);

namespace App\MasterData;

use App\Audit\AuditLogger;
use Throwable;

final class MasterDataService
{
    public function __construct(private MasterDataRepository $repository, private AuditLogger $audit)
    {
    }

    public function guruList(array $filters, int $page, int $perPage = 20): array
    {
        return $this->repository->guruList($this->filters($filters), $this->page($page), $this->perPage($perPage));
    }

    public function guru(int $id): ?array
    {
        return $this->repository->guruFind($id);
    }

    /**
     * Menyimpan identitas guru.
     *
     * **Koreksi ke-3 (30 Agustus 2026).** Pilihan tugas lama
     * 'Guru'/'Pembimbing'/'Keduanya' DIHAPUS dari alur operasional:
     *
     *   - penugasan mengajar ditentukan oleh jadwal pengajian;
     *   - penugasan murobi ditentukan oleh `admin_murobi.php`;
     *   - pembimbing tetap penugasan pengurus, bukan identitas guru.
     *
     * Kolom `guru.status` lama TIDAK dihapus dan TIDAK ditimpa. Guru baru
     * dibuat dengan nilai default 'Guru' agar kolom NOT NULL tetap valid;
     * penyimpanan guru lama sama sekali tidak menyentuh kolom itu, sehingga
     * nilai 'Pembimbing'/'Keduanya' pada data historis tetap utuh sampai
     * strategi kompatibilitasnya diverifikasi. Nilai `status` yang dikirim
     * pemanggil lama diabaikan dengan sengaja, bukan divalidasi.
     */
    public function saveGuru(array $input, ?int $id = null): int
    {
        $data = [
            'nip' => Normalizer::identifier($input['nip'] ?? ''),
            'nama_guru' => Normalizer::text($input['nama_guru'] ?? ''),
            'no_hp' => Normalizer::phone($input['no_hp'] ?? ''),
        ];
        $errors = [];
        $this->required($data['nama_guru'], 'Nama guru wajib diisi.', $errors);
        $this->identifier($data['nip'], 'NIP', false, $errors);
        $this->maxLength($data['nip'], 30, 'NIP', $errors);
        $this->maxLength($data['nama_guru'], 100, 'Nama guru', $errors);
        $this->phone($data['no_hp'], $errors);
        $this->maxLength($data['no_hp'], 15, 'Nomor HP guru', $errors);
        $this->reject($errors);

        if ($id === null) {
            $data['status'] = 'Guru';
            $id = $this->repository->guruCreate($data);
            $this->audit->log('master.create', 'guru', $id, null, $data);
            return $id;
        }
        $before = $this->mustFind($this->repository->guruFind($id), 'Guru');
        $this->repository->guruUpdate($id, $data);
        $this->audit->log('master.update', 'guru', $id, $before, $this->repository->guruFind($id));
        return $id;
    }

    /**
     * Penugasan NYATA seorang guru (jadwal mengajar aktif dan penugasan murobi
     * aktif). Dipakai halaman Data Guru untuk menampilkan kemampuan berdasarkan
     * penugasan, bukan berdasarkan kolom tugas lama.
     *
     * @return array{jadwal_aktif:int, murobi_aktif:int}
     */
    public function guruAssignments(int $id): array
    {
        return $this->repository->guruAssignmentSummary($id);
    }

    public function setGuruState(int $id, string $action): void
    {
        $this->setState('guru', $id, $action, fn () => $this->repository->guruFind($id), fn (bool $active, bool $archive) => $this->repository->guruSetState($id, $active, $archive));
    }

    public function santriList(array $filters, int $page, int $perPage = 20): array
    {
        return $this->repository->santriList($this->filters($filters), $this->page($page), $this->perPage($perPage));
    }

    public function santri(int $id): ?array
    {
        return $this->repository->santriFind($id);
    }

    public function saveSantri(array $input, ?int $id = null): int
    {
        $current = $id === null ? null : $this->mustFind($this->repository->santriFind($id), 'Santri');
        $data = [
            'nis' => Normalizer::identifier($input['nis'] ?? ''),
            'nama_santri' => Normalizer::text($input['nama_santri'] ?? ''),
            'jenis_kelamin' => strtoupper(Normalizer::text($input['jenis_kelamin'] ?? '')),
            'tempat_lahir' => Normalizer::text($input['tempat_lahir'] ?? ''),
            'tgl_lahir' => Normalizer::date($input['tgl_lahir'] ?? '', true),
            'alamat' => Normalizer::text($input['alamat'] ?? ''),
            'desa' => Normalizer::text($input['desa'] ?? ''),
            'kecamatan' => Normalizer::text($input['kecamatan'] ?? ''),
            'kab_kota' => Normalizer::text($input['kab_kota'] ?? ''),
            'provinsi' => Normalizer::text($input['provinsi'] ?? ''),
            'nama_ayah' => Normalizer::text($input['nama_ayah'] ?? ''),
            'no_hp_ayah' => Normalizer::phone($input['no_hp_ayah'] ?? ''),
            'nama_ibu' => Normalizer::text($input['nama_ibu'] ?? ''),
            'no_hp_ibu' => Normalizer::phone($input['no_hp_ibu'] ?? ''),
            'asal_sekolah' => Normalizer::text($input['asal_sekolah'] ?? ''),
            'sekolah_saat_ini' => Normalizer::text($input['sekolah_saat_ini'] ?? ''),
            'foto' => Normalizer::text($input['foto'] ?? ($current['foto'] ?? 'default.jpg')) ?: 'default.jpg',
        ];
        $errors = [];
        $this->required($data['nis'], 'NIS wajib diisi.', $errors);
        $this->identifier($data['nis'], 'NIS', true, $errors);
        $this->maxLength($data['nis'], 20, 'NIS', $errors);
        $this->required($data['nama_santri'], 'Nama santri wajib diisi.', $errors);
        $this->maxLength($data['nama_santri'], 100, 'Nama santri', $errors);
        if (!in_array($data['jenis_kelamin'], ['L', 'P'], true)) {
            $errors[] = 'Jenis kelamin harus L atau P.';
        }
        if ($data['tgl_lahir'] === '') {
            $errors[] = 'Tanggal lahir harus berformat YYYY-MM-DD dan merupakan tanggal valid.';
        }
        $this->phone($data['no_hp_ayah'], $errors, 'Nomor HP ayah');
        $this->phone($data['no_hp_ibu'], $errors, 'Nomor HP ibu');
        foreach (['tempat_lahir' => 50, 'desa' => 50, 'kecamatan' => 50, 'kab_kota' => 50, 'provinsi' => 50, 'nama_ayah' => 100, 'nama_ibu' => 100, 'asal_sekolah' => 100, 'sekolah_saat_ini' => 50, 'foto' => 255] as $field => $maximum) {
            $this->maxLength($data[$field], $maximum, str_replace('_', ' ', ucfirst($field)), $errors);
        }
        $this->maxLength($data['alamat'], 2000, 'Alamat', $errors);
        $this->reject($errors);

        if ($id === null) {
            $db = $this->repository->db();
            $db->begin_transaction();
            try {
                $id = $this->repository->santriCreate($data);
                $actorId = (int) ($_SESSION['user_id'] ?? 0);
                foreach ([['name' => $data['nama_ayah'], 'phone' => $data['no_hp_ayah'], 'relationship' => 'Ayah'], ['name' => $data['nama_ibu'], 'phone' => $data['no_hp_ibu'], 'relationship' => 'Ibu']] as $parent) {
                    if ($parent['name'] === '') {
                        continue;
                    }
                    $waliId = $this->repository->waliCreate(['nama' => $parent['name'], 'no_hp' => $parent['phone'], 'alamat' => $data['alamat']]);
                    $relationId = $this->repository->waliAttach($waliId, $id, $parent['relationship'], $parent['relationship'] === 'Ayah' || $data['nama_ayah'] === '', $actorId);
                    $this->audit->log('master.relation.create', 'santri_wali', $relationId, null, ['wali_id' => $waliId, 'santri_id' => $id, 'hubungan' => $parent['relationship']]);
                }
                $this->audit->log('master.create', 'santri', $id, null, $data);
                $db->commit();
                return $id;
            } catch (Throwable $exception) {
                $db->rollback();
                throw $exception;
            }
        }
        $this->repository->santriUpdate($id, $data);
        $this->audit->log('master.update', 'santri', $id, $current, $this->repository->santriFind($id));
        return $id;
    }

    public function setSantriState(int $id, string $action): void
    {
        $this->setState('santri', $id, $action, fn () => $this->repository->santriFind($id), fn (bool $active, bool $archive) => $this->repository->santriSetState($id, $active, $archive));
    }

    public function waliList(array $filters, int $page, int $perPage = 20): array
    {
        return $this->repository->waliList($this->filters($filters), $this->page($page), $this->perPage($perPage));
    }

    public function wali(int $id): ?array
    {
        $row = $this->repository->waliFind($id);
        if ($row !== null) {
            $row['relations'] = $this->repository->waliRelations($id);
        }
        return $row;
    }

    public function saveWali(array $input, ?int $id = null): int
    {
        $data = [
            'nama' => Normalizer::text($input['nama'] ?? ''),
            'no_hp' => Normalizer::phone($input['no_hp'] ?? ''),
            'alamat' => Normalizer::text($input['alamat'] ?? ''),
        ];
        $errors = [];
        $this->required($data['nama'], 'Nama orang tua/wali wajib diisi.', $errors);
        $this->maxLength($data['nama'], 100, 'Nama orang tua/wali', $errors);
        $this->maxLength($data['alamat'], 2000, 'Alamat', $errors);
        $this->phone($data['no_hp'], $errors);
        $this->reject($errors);
        if ($id === null) {
            $id = $this->repository->waliCreate($data);
            $this->audit->log('master.create', 'wali', $id, null, $data);
            return $id;
        }
        $before = $this->mustFind($this->repository->waliFind($id), 'Orang tua/wali');
        $this->repository->waliUpdate($id, $data);
        $this->audit->log('master.update', 'wali', $id, $before, $this->repository->waliFind($id));
        return $id;
    }

    public function setWaliState(int $id, string $action): void
    {
        $this->setState('wali', $id, $action, fn () => $this->repository->waliFind($id), fn (bool $active, bool $archive) => $this->repository->waliSetState($id, $active, $archive));
    }

    public function attachWali(int $waliId, array $input, int $actorId): void
    {
        $this->mustFind($this->repository->waliFind($waliId), 'Orang tua/wali');
        $santriId = (int) ($input['santri_id'] ?? 0);
        $this->mustFind($this->repository->santriFind($santriId), 'Santri');
        $relationship = Normalizer::text($input['hubungan'] ?? 'Wali');
        if ($relationship === '' || mb_strlen($relationship) > 30) {
            throw new MasterDataException('Hubungan wali wajib diisi dan maksimal 30 karakter.');
        }
        $relationId = $this->repository->waliAttach($waliId, $santriId, $relationship, !empty($input['is_primary']), $actorId);
        $this->audit->log('master.relation.create', 'santri_wali', $relationId, null, ['wali_id' => $waliId, 'santri_id' => $santriId, 'hubungan' => $relationship]);
    }

    public function detachWali(int $waliId, int $relationId): void
    {
        $before = null;
        foreach ($this->repository->waliRelations($waliId) as $relation) {
            if ((int) $relation['id'] === $relationId) {
                $before = $relation;
                break;
            }
        }
        if ($before === null) {
            throw new MasterDataException('Relasi wali tidak ditemukan.');
        }
        $this->repository->waliDetach($relationId, $waliId);
        $this->audit->log('master.relation.archive', 'santri_wali', $relationId, $before, null);
    }

    public function pengurusList(array $filters, int $page, int $perPage = 20): array
    {
        return $this->repository->pengurusList($this->filters($filters), $this->page($page), $this->perPage($perPage));
    }

    public function pengurus(int $id): ?array
    {
        return $this->repository->pengurusFind($id);
    }

    public function savePengurus(array $input, ?int $id = null): int
    {
        $data = [
            'nama' => Normalizer::text($input['nama'] ?? ''),
            'nomor_identitas' => Normalizer::identifier($input['nomor_identitas'] ?? ''),
            'no_hp' => Normalizer::phone($input['no_hp'] ?? ''),
            'jabatan' => Normalizer::text($input['jabatan'] ?? ''),
        ];
        $errors = [];
        $this->required($data['nama'], 'Nama pengurus wajib diisi.', $errors);
        $this->identifier($data['nomor_identitas'], 'Nomor identitas', false, $errors);
        $this->maxLength($data['nomor_identitas'], 50, 'Nomor identitas', $errors);
        $this->phone($data['no_hp'], $errors);
        $this->required($data['jabatan'], 'Jabatan wajib diisi.', $errors);
        $this->maxLength($data['nama'], 100, 'Nama pengurus', $errors);
        $this->maxLength($data['jabatan'], 100, 'Jabatan', $errors);
        $this->reject($errors);
        if ($id === null) {
            $id = $this->repository->pengurusCreate($data);
            $this->audit->log('master.create', 'pengurus', $id, null, $data);
            return $id;
        }
        $before = $this->mustFind($this->repository->pengurusFind($id), 'Pengurus');
        $this->repository->pengurusUpdate($id, $data);
        $this->audit->log('master.update', 'pengurus', $id, $before, $this->repository->pengurusFind($id));
        return $id;
    }

    public function setPengurusState(int $id, string $action): void
    {
        $this->setState('pengurus', $id, $action, fn () => $this->repository->pengurusFind($id), fn (bool $active, bool $archive) => $this->repository->pengurusSetState($id, $active, $archive));
    }

    public function years(): array
    {
        return $this->repository->tahunList();
    }

    public function year(int $id): ?array
    {
        return $this->repository->tahunFind($id);
    }

    public function saveYear(array $input, ?int $id = null): int
    {
        $data = ['tahun' => Normalizer::text($input['tahun'] ?? ''), 'semester' => Normalizer::text($input['semester'] ?? '')];
        $errors = [];
        if (!preg_match('/^\d{4}\/\d{4}$/', $data['tahun'])) {
            $errors[] = 'Tahun ajaran harus berformat YYYY/YYYY.';
        }
        if (!in_array($data['semester'], ['Ganjil', 'Genap'], true)) {
            $errors[] = 'Semester tidak valid.';
        }
        $this->reject($errors);
        if ($id === null) {
            $id = $this->repository->tahunCreate($data);
            $this->audit->log('master.create', 'tahun_ajaran', $id, null, $data);
            return $id;
        }
        $before = $this->mustFind($this->repository->tahunFind($id), 'Tahun ajaran');
        $this->repository->tahunUpdate($id, $data);
        $this->audit->log('master.update', 'tahun_ajaran', $id, $before, $this->repository->tahunFind($id));
        return $id;
    }

    public function activateYear(int $id): void
    {
        $before = $this->mustFind($this->repository->tahunFind($id), 'Tahun ajaran');
        $db = $this->repository->db();
        $db->begin_transaction();
        try {
            $this->repository->tahunActivate($id);
            $after = $this->repository->tahunFind($id);
            if ($after === null || $after['status'] !== 'Aktif') {
                throw new MasterDataException('Tahun ajaran tidak dapat diaktifkan.');
            }
            $this->audit->log('master.status', 'tahun_ajaran', $id, $before, $after);
            $db->commit();
        } catch (Throwable $exception) {
            $db->rollback();
            throw $exception;
        }
    }

    public function archiveYear(int $id): void
    {
        $before = $this->mustFind($this->repository->tahunFind($id), 'Tahun ajaran');
        if ($before['status'] === 'Aktif') {
            throw new MasterDataException('Tahun ajaran aktif tidak dapat diarsipkan. Aktifkan semester lain terlebih dahulu.');
        }
        $this->repository->tahunArchive($id);
        $this->audit->log('master.archive', 'tahun_ajaran', $id, $before, $this->repository->tahunFind($id));
    }

    public function restoreYear(int $id): void
    {
        $before = $this->mustFind($this->repository->tahunFind($id), 'Tahun ajaran');
        $this->repository->tahunRestore($id);
        $this->audit->log('master.restore', 'tahun_ajaran', $id, $before, $this->repository->tahunFind($id));
    }

    public function classes(): array
    {
        return $this->repository->kelasList();
    }

    public function class(int $id): ?array
    {
        return $this->repository->kelasFind($id);
    }

    public function saveClass(array $input, ?int $id = null): int
    {
        $data = ['nama_kelas' => Normalizer::text($input['nama_kelas'] ?? ''), 'jenjang' => Normalizer::text($input['jenjang'] ?? '')];
        $errors = [];
        $this->required($data['nama_kelas'], 'Nama kelas wajib diisi.', $errors);
        $this->required($data['jenjang'], 'Jenjang wajib diisi.', $errors);
        $this->maxLength($data['nama_kelas'], 50, 'Nama kelas', $errors);
        $this->maxLength($data['jenjang'], 20, 'Jenjang', $errors);
        $this->reject($errors);
        if ($id === null) {
            $id = $this->repository->kelasCreate($data);
            $this->audit->log('master.create', 'kelas', $id, null, $data);
            return $id;
        }
        $before = $this->mustFind($this->repository->kelasFind($id), 'Kelas');
        $this->repository->kelasUpdate($id, $data);
        $this->audit->log('master.update', 'kelas', $id, $before, $this->repository->kelasFind($id));
        return $id;
    }

    public function setClassState(int $id, string $action): void
    {
        $this->setState('kelas', $id, $action, fn () => $this->repository->kelasFind($id), fn (bool $active, bool $archive) => $this->repository->kelasSetState($id, $active, $archive));
    }

    public function membershipHistory(int $santriId): array
    {
        return $this->repository->membershipHistory($santriId);
    }

    public function assignActiveClass(array $input, int $actorId): void
    {
        $santriId = (int) ($input['santri_id'] ?? 0);
        $kelasId = (int) ($input['kelas_id'] ?? 0);
        $santri = $this->mustFind($this->repository->santriFind($santriId), 'Santri');
        $kelas = $this->mustFind($this->repository->kelasFind($kelasId), 'Kelas');
        $year = $this->repository->activeYear();
        if ($year === null) {
            throw new MasterDataException('Belum ada tahun ajaran aktif.');
        }
        $date = Normalizer::date($input['tanggal_mulai'] ?? date('Y-m-d'), true);
        if ($date === '') {
            throw new MasterDataException('Tanggal mulai tidak valid.');
        }
        $before = $this->repository->membershipHistory($santriId);
        $db = $this->repository->db();
        $db->begin_transaction();
        try {
            $id = $this->repository->membershipAssign($santriId, $kelasId, (int) $year['id'], $date, $actorId);
            $after = ['santri' => $santri['nama_santri'], 'kelas' => $kelas['nama_kelas'], 'tahun_ajaran_id' => (int) $year['id'], 'tanggal_mulai' => $date];
            $this->audit->log('master.relation.create', 'plotting_kelas', $id, ['history' => $before], $after);
            $db->commit();
        } catch (Throwable $exception) {
            $db->rollback();
            throw $exception;
        }
    }

    public function endActiveClass(int $santriId): void
    {
        $this->mustFind($this->repository->santriFind($santriId), 'Santri');
        $year = $this->repository->activeYear();
        if ($year === null) {
            throw new MasterDataException('Belum ada tahun ajaran aktif.');
        }
        $before = $this->repository->membershipHistory($santriId);
        $this->repository->membershipEnd($santriId, (int) $year['id'], date('Y-m-d'));
        $this->audit->log('master.relation.status', 'plotting_kelas', null, ['history' => $before], ['santri_id' => $santriId, 'tahun_ajaran_id' => (int) $year['id'], 'status' => 'Selesai']);
    }

    public function murobi(): array
    {
        return $this->repository->murobiList();
    }

    public function saveMurobi(array $input, int $actorId): int
    {
        $type = Normalizer::text($input['target_type'] ?? '');
        $targetId = $type === 'Kamar' ? (int) ($input['kamar_id'] ?? 0) : (int) ($input['kelas_id'] ?? 0);
        $data = [
            'guru_id' => (int) ($input['guru_id'] ?? 0),
            'tahun_ajaran_id' => (int) ($input['tahun_ajaran_id'] ?? 0),
            'target_type' => $type,
            'kamar_id' => $type === 'Kamar' ? $targetId : null,
            'kelas_id' => $type === 'Kelas' ? $targetId : null,
            'tanggal_mulai' => Normalizer::date($input['tanggal_mulai'] ?? '', true),
            'tanggal_selesai' => Normalizer::date($input['tanggal_selesai'] ?? ''),
        ];
        $errors = [];
        if ($this->repository->guruFind($data['guru_id']) === null) {
            $errors[] = 'Guru tidak ditemukan.';
        }
        if ($this->repository->tahunFind($data['tahun_ajaran_id']) === null) {
            $errors[] = 'Tahun ajaran tidak ditemukan.';
        }
        if (!in_array($type, ['Kamar', 'Kelas'], true) || $targetId < 1) {
            $errors[] = 'Kelompok binaan harus berupa kamar atau kelas yang valid.';
        } elseif ($type === 'Kamar' && $this->repository->kamarFind($targetId) === null) {
            $errors[] = 'Kamar kelompok binaan tidak ditemukan.';
        } elseif ($type === 'Kelas' && $this->repository->kelasFind($targetId) === null) {
            $errors[] = 'Kelas kelompok binaan tidak ditemukan.';
        }
        if ($data['tanggal_mulai'] === '') {
            $errors[] = 'Tanggal mulai tidak valid.';
        }
        if ($data['tanggal_selesai'] === '' || ($data['tanggal_selesai'] !== null && $data['tanggal_selesai'] < $data['tanggal_mulai'])) {
            $errors[] = 'Tanggal selesai tidak valid atau lebih awal dari tanggal mulai.';
        }
        $this->reject($errors);
        $id = $this->repository->murobiCreate($data, $actorId);
        $this->audit->log('master.relation.create', 'murobi_assignment', $id, null, $data);
        return $id;
    }

    public function setMurobiState(int $id, string $action): void
    {
        $this->setState('murobi_assignment', $id, $action, fn () => $this->repository->murobiFind($id), fn (bool $active, bool $archive) => $this->repository->murobiSetState($id, $active, $archive));
    }

    public function guruOptions(): array { return $this->repository->guruOptions(); }
    public function santriOptions(): array { return $this->repository->santriOptions(); }
    public function kamarOptions(): array { return $this->repository->kamarList(); }
    public function exportGuru(array $filters): array { return $this->repository->exportGuru($this->filters($filters)); }
    public function exportSantri(array $filters): array { return $this->repository->exportSantri($this->filters($filters)); }

    private function setState(string $entity, int $id, string $action, callable $find, callable $save): void
    {
        $before = $this->mustFind($find(), ucfirst(str_replace('_', ' ', $entity)));
        [$active, $archive] = match ($action) {
            'activate', 'restore' => [true, false],
            'deactivate' => [false, false],
            'archive' => [false, true],
            default => throw new MasterDataException('Aksi status tidak valid.'),
        };
        $save($active, $archive);
        $this->audit->log('master.' . $action, $entity, $id, $before, $find());
    }

    private function filters(array $filters): array
    {
        return [
            'q' => mb_substr(Normalizer::text($filters['q'] ?? ''), 0, 100),
            'state' => in_array(($filters['state'] ?? 'active'), ['active', 'inactive', 'archived', 'all'], true) ? $filters['state'] : 'active',
            'gender' => in_array(($filters['gender'] ?? ''), ['L', 'P'], true) ? $filters['gender'] : '',
            'kelas_id' => max(0, (int) ($filters['kelas_id'] ?? 0)),
        ];
    }

    private function page(int $page): int { return max(1, $page); }
    private function perPage(int $perPage): int { return max(10, min(100, $perPage)); }

    private function required(string $value, string $message, array &$errors): void
    {
        if ($value === '') { $errors[] = $message; }
    }

    private function identifier(string $value, string $label, bool $required, array &$errors): void
    {
        if ($value === '' && !$required) { return; }
        if (!preg_match('/^[A-Z0-9.\/-]{1,50}$/', $value)) { $errors[] = $label . ' hanya boleh memuat huruf, angka, titik, garis miring, atau tanda hubung.'; }
    }

    private function phone(string $value, array &$errors, string $label = 'Nomor HP'): void
    {
        if ($value !== '' && !preg_match('/^0[0-9]{8,15}$/', $value)) { $errors[] = $label . ' harus berupa 9–16 digit dan diawali 0.'; }
    }

    private function maxLength(string $value, int $maximum, string $label, array &$errors): void
    {
        if (mb_strlen($value) > $maximum) { $errors[] = $label . ' maksimal ' . $maximum . ' karakter.'; }
    }

    private function reject(array $errors): void
    {
        if ($errors !== []) { throw new MasterDataException(implode(' ', $errors), $errors); }
    }

    private function mustFind(?array $row, string $label): array
    {
        if ($row === null) { throw new MasterDataException($label . ' tidak ditemukan.'); }
        return $row;
    }
}
