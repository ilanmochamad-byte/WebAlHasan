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

    public function classesPage(string $q, int $page, string $state = 'all'): array { return $this->repository->classesPage($q, $page, in_array($state, ['active', 'inactive', 'archived'], true) ? $state : 'all'); }
    public function classStateCounts(): array { return $this->repository->classStateCounts(); }
    public function asramaTersedia(): bool { return $this->repository->asramaTersedia(); }
    public function bukanGandaTersedia(): bool { return $this->repository->bukanGandaTersedia(); }
    public function roomSummary(int $yearId): array { return $this->repository->roomSummary($yearId); }
    public function linkedAccounts(string $column, int $id): array { return $this->repository->linkedAccounts($column, $id); }
    public function stateCounts(string $table, string $q): array { return $this->repository->stateCounts($table, mb_substr(Normalizer::text($q), 0, 100)); }
    public function yearsPage(string $q, int $page): array { return $this->repository->yearsPage($q, $page); }
    public function murobiPage(string $q, int $page): array { return $this->repository->murobiPage($q, $page); }
    public function roomsPage(string $q, int $page, int $yearId, string $asrama = '', int $perPage = 20): array { return $this->repository->roomsPage($q, $page, $yearId, in_array($asrama, ['Putra', 'Putri', 'belum'], true) ? $asrama : '', $perPage); }
    public function roomOccupantsPage(int $roomId, int $yearId, string $q, int $page): array { return $this->repository->roomOccupantsPage($roomId, $yearId, $q, $page); }

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
        // PK-1: identitas pengurus milik guru ikut berubah di transaksi yang sama.
        $this->repository->transaction(function () use ($id, $data, $before): void {
            $this->repository->guruUpdate($id, $data);
            $this->audit->log('master.update', 'guru', $id, $before, $this->repository->guruFind($id));
            $this->sinkronIdentitasGuru($id);
        });
        return $id;
    }

    /**
     * Identitas pengurus milik guru (PK-1), untuk panel Data Guru.
     *
     * @return array<string, mixed>|null
     */
    public function identitasGuru(int $guruId): ?array
    {
        return $this->repository->identitasGuru($guruId);
    }

    private function sinkronIdentitasGuru(int $guruId): void
    {
        if ($this->repository->sinkronIdentitasGuru($guruId) > 0) {
            $identitas = $this->repository->identitasGuru($guruId);
            $this->audit->log('master.identitas_guru.sinkron', 'pengurus', (int) ($identitas['id'] ?? 0), null, [
                'guru_id' => $guruId, 'is_active' => (int) ($identitas['is_active'] ?? 0), 'diarsipkan' => !empty($identitas['archived_at']),
            ]);
        }
    }

    /** Identitas pengurus milik guru tidak diubah dari Data Pengurus. */
    private function tolakIdentitasGuru(?array $pengurus): void
    {
        if ($pengurus !== null && !empty($pengurus['guru_id'])) {
            throw new MasterDataException('Data ini adalah identitas pengurus milik seorang guru. Nama, nomor HP, dan statusnya mengikuti Data Guru; ubah dari halaman Data Guru.');
        }
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
        $this->repository->transaction(function () use ($id, $action): void {
            $this->setState('guru', $id, $action, fn () => $this->repository->guruFind($id), fn (bool $active, bool $archive) => $this->repository->guruSetState($id, $active, $archive));
            // PK-1: menonaktifkan/mengarsipkan guru menggugurkan seluruh penugasan
            // berbasis pengurus miliknya; mengaktifkan memulihkan identitasnya.
            $this->sinkronIdentitasGuru($id);
        });
    }

    public function santriList(array $filters, int $page, int $perPage = 20): array
    {
        return $this->repository->santriList($this->filters($filters), $this->page($page), $this->perPage($perPage));
    }

    public function santriStateCounts(array $filters): array
    {
        return $this->repository->santriStateCounts($this->filters($filters));
    }

    public function santri(int $id): ?array
    {
        return $this->repository->santriFind($id);
    }

    /**
     * Menyimpan santri beserta relasi walinya.
     *
     * **Koreksi ke-2 (30 Agustus 2026).** Perilaku lama membuat wali BARU setiap
     * kali santri baru disimpan, sehingga dua saudara kandung selalu berakhir
     * dengan dua identitas ayah yang berbeda. Sekarang identitas wali dipilih
     * atau dibuat secara eksplisit oleh admin lewat `$input['wali']`:
     *
     *     $input['wali'] = [
     *         'Ayah' => ['mode' => 'abaikan'|'pilih'|'baru'|'lepas', 'wali_id' => int,
     *                    'nama' => string, 'no_hp' => string, 'alamat' => string],
     *         'Ibu'  => [...],
     *         'Wali' => [...],
     *     ];
     *
     * Aturan yang dipegang:
     *   - tidak ada penggabungan otomatis berdasarkan nama, nomor HP, atau
     *     pasangan nama ayah/ibu; nama dan nomor HP hanya petunjuk pencarian;
     *   - untuk saudara kandung, admin memilih ulang ID wali yang sama;
     *   - santri, wali baru, dan relasinya disimpan dalam SATU transaksi;
     *   - membuat atau memilih wali TIDAK membuat akun login;
     *   - kolom lama `nama_ayah`/`no_hp_ayah`/`nama_ibu`/`no_hp_ibu` tidak
     *     dihapus. Ia menjadi CERMIN dari identitas wali yang dikonfirmasi
     *     (satu sumber pengeditan), dan nilai lama yang bertentangan hanya
     *     ditimpa bila admin mengonfirmasi lewat `$input['konfirmasi_timpa']`;
     *   - pemanggil lama (impor, PSB) yang tidak mengirim `wali` tidak lagi
     *     membuat wali otomatis; santrinya muncul pada laporan rekonsiliasi.
     */
    public function saveSantri(array $input, ?int $id = null): int
    {
        $current = $id === null ? null : $this->mustFind($this->repository->santriFind($id), 'Santri');
        $data = $this->santriData($input, $current);
        $errors = $this->santriErrors($data);

        $spesifikasi = $this->normalizeWaliSpec($input['wali'] ?? [], $errors);
        $this->reject($errors);

        $actorId = (int) ($_SESSION['user_id'] ?? 0);
        $db = $this->repository->db();
        $db->begin_transaction();
        try {
            if ($id === null) {
                $id = $this->repository->santriCreate($data);
                $this->audit->log('master.create', 'santri', $id, null, $data);
            } else {
                $this->repository->santriUpdate($id, $data);
                $this->audit->log('master.update', 'santri', $id, $current, $this->repository->santriFind($id));
            }

            foreach ($spesifikasi as $hubungan => $spec) {
                $this->applyWaliSpec($id, $hubungan, $spec, $input, $actorId);
            }

            $db->commit();

            return $id;
        } catch (Throwable $exception) {
            $db->rollback();
            throw $exception;
        }
    }

    /**
     * Normalisasi isian santri. Dipakai `saveSantri()` dan pemeriksaan impor
     * (D-4) sehingga aturan validasinya satu.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $current
     * @return array<string, string>
     */
    private function santriData(array $input, ?array $current): array
    {
        return [
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
            // Kolom lama hanya ikut ditulis bila pemanggil memang mengirimnya.
            // Formulir santri tidak lagi mengirimnya, sehingga menyimpan santri
            // tidak pernah mengosongkan nilai lama secara tidak sengaja.
            'nama_ayah' => array_key_exists('nama_ayah', $input) ? Normalizer::text($input['nama_ayah']) : (string) ($current['nama_ayah'] ?? ''),
            'no_hp_ayah' => array_key_exists('no_hp_ayah', $input) ? Normalizer::phone($input['no_hp_ayah']) : (string) ($current['no_hp_ayah'] ?? ''),
            'nama_ibu' => array_key_exists('nama_ibu', $input) ? Normalizer::text($input['nama_ibu']) : (string) ($current['nama_ibu'] ?? ''),
            'no_hp_ibu' => array_key_exists('no_hp_ibu', $input) ? Normalizer::phone($input['no_hp_ibu']) : (string) ($current['no_hp_ibu'] ?? ''),
            'asal_sekolah' => Normalizer::text($input['asal_sekolah'] ?? ''),
            'sekolah_saat_ini' => Normalizer::text($input['sekolah_saat_ini'] ?? ''),
            'foto' => Normalizer::text($input['foto'] ?? ($current['foto'] ?? 'default.jpg')) ?: 'default.jpg',
        ];
    }

    /**
     * @param array<string, string> $data
     * @return array<int, string>
     */
    private function santriErrors(array $data): array
    {
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

        return $errors;
    }

    /** Urutan kolom berkas impor santri (templat D-4 dan format lama). */
    public const KOLOM_IMPOR = ['nis', 'nama_santri', 'jenis_kelamin', 'tempat_lahir', 'tgl_lahir', 'alamat', 'desa', 'kecamatan', 'kab_kota', 'provinsi', 'nama_ayah', 'no_hp_ayah', 'nama_ibu', 'no_hp_ibu', 'asal_sekolah', 'sekolah_saat_ini'];

    /** Judul kolom templat impor, sejajar dengan `KOLOM_IMPOR`. */
    public const JUDUL_IMPOR = ['NIS', 'Nama santri', 'L/P', 'Tempat lahir', 'Tanggal lahir (YYYY-MM-DD)', 'Alamat', 'Desa', 'Kecamatan', 'Kabupaten/kota', 'Provinsi', 'Nama ayah', 'HP ayah', 'Nama ibu', 'HP ibu', 'Sekolah asal', 'Sekolah saat ini'];

    /** Batas baris satu berkas impor (melindungi batas waktu cPanel). */
    public const BATAS_IMPOR = 1000;

    /**
     * D-4 (keputusan AC): memeriksa baris impor TANPA menyimpan apa pun.
     *
     * Aturan sama dengan `saveSantri()`, ditambah NIS yang sudah ada di basis
     * data dan NIS ganda di dalam berkas. Baris judul (kolom pertama "NIS")
     * dilewati; baris kosong diabaikan.
     *
     * @param array<int, mixed> $rows
     * @return array{baris:int, siap:int, bermasalah:int, hasil:array<int, array{baris:int, nis:string, nama:string, siap:bool, masalah:array<int,string>}>}
     */
    public function periksaImpor(array $rows): array
    {
        if (count($rows) > self::BATAS_IMPOR + 1) {
            throw new MasterDataException('Satu berkas impor dibatasi ' . self::BATAS_IMPOR . ' baris. Bagi berkas menjadi beberapa bagian.');
        }
        $calon = [];
        foreach ($rows as $index => $row) {
            $nomor = $index + 1;
            if (!is_array($row)) {
                $calon[] = ['baris' => $nomor, 'data' => null, 'masalah' => ['Format baris tidak valid.']];
                continue;
            }
            $row = array_values($row);
            if ($index === 0 && strtoupper(trim((string) ($row[0] ?? ''))) === 'NIS') {
                continue;
            }
            if (implode('', array_map(static fn ($v): string => is_scalar($v) ? trim((string) $v) : '', $row)) === '') {
                continue;
            }
            $input = ['foto' => 'default.jpg'];
            foreach (self::KOLOM_IMPOR as $i => $kolom) {
                $input[$kolom] = is_scalar($row[$i] ?? null) ? (string) $row[$i] : '';
            }
            $data = $this->santriData($input, null);
            $calon[] = ['baris' => $nomor, 'data' => $data, 'input' => $input, 'masalah' => $this->santriErrors($data)];
        }

        $ada = $this->repository->existingNis(array_map(static fn (array $c): string => (string) ($c['data']['nis'] ?? ''), $calon));
        $dilihat = [];
        $hasil = [];
        $siap = 0;
        foreach ($calon as $c) {
            $nis = (string) ($c['data']['nis'] ?? '');
            $kunci = strtoupper($nis);
            if ($nis !== '' && isset($ada[$kunci])) {
                $c['masalah'][] = 'NIS ' . $nis . ' sudah terdaftar.';
            }
            if ($nis !== '' && isset($dilihat[$kunci])) {
                $c['masalah'][] = 'NIS ' . $nis . ' ganda di dalam berkas (sama dengan baris ' . $dilihat[$kunci] . ').';
            }
            if ($nis !== '') {
                $dilihat[$kunci] ??= $c['baris'];
            }
            $ok = $c['masalah'] === [];
            $siap += $ok ? 1 : 0;
            $hasil[] = [
                'baris' => $c['baris'],
                'nis' => $nis,
                'nama' => (string) ($c['data']['nama_santri'] ?? ''),
                'siap' => $ok,
                'masalah' => array_values(array_unique($c['masalah'])),
                'input' => $c['input'] ?? null,
            ];
        }

        return ['baris' => count($hasil), 'siap' => $siap, 'bermasalah' => count($hasil) - $siap, 'hasil' => $hasil];
    }

    /**
     * D-4: menyimpan baris impor yang siap. Setiap baris DIPERIKSA ULANG
     * (data dapat berubah sejak pemeriksaan) lewat aturan yang sama, lalu
     * disimpan satu per satu dengan `saveSantri()`. Baris bermasalah dilewati
     * dan dilaporkan dengan cara yang sama seperti pemeriksaan.
     *
     * @param array<int, mixed> $rows
     * @return array{baris:int, disimpan:int, bermasalah:int, hasil:array<int, array<string, mixed>>}
     */
    public function imporSantri(array $rows): array
    {
        $periksa = $this->periksaImpor($rows);
        $disimpan = 0;
        foreach ($periksa['hasil'] as &$baris) {
            if (!$baris['siap']) {
                continue;
            }
            try {
                $baris['santri_id'] = $this->saveSantri((array) $baris['input']);
                $disimpan++;
            } catch (MasterDataException $exception) {
                $baris['siap'] = false;
                $baris['masalah'] = $exception->errors() !== [] ? $exception->errors() : [$exception->getMessage()];
            } catch (Throwable) {
                $baris['siap'] = false;
                $baris['masalah'] = ['Gagal disimpan karena kesalahan basis data.'];
            }
        }
        unset($baris);
        foreach ($periksa['hasil'] as &$baris) {
            unset($baris['input']);
        }
        unset($baris);

        return ['baris' => $periksa['baris'], 'disimpan' => $disimpan, 'bermasalah' => $periksa['baris'] - $disimpan, 'hasil' => $periksa['hasil']];
    }

    /**
     * Relasi wali aktif milik satu santri. Inilah sumber utama identitas orang
     * tua yang ditampilkan pada halaman detail santri.
     *
     * @return array<int, array<string, mixed>>
     */
    public function santriWali(int $santriId, bool $activeOnly = true): array
    {
        return $this->repository->santriWaliRelations($santriId, $activeOnly);
    }

    /**
     * Kandidat wali untuk formulir santri. Hasilnya hanya PETUNJUK.
     *
     * @return array<int, array<string, mixed>>
     */
    public function waliCandidates(string $q, int $limit = 20): array
    {
        return $this->repository->waliSearch($q, $limit);
    }

    /**
     * @param array<string, mixed> $spec
     * @return array<string, array<string, mixed>>
     */
    private function normalizeWaliSpec(mixed $raw, array &$errors): array
    {
        if (!is_array($raw) || $raw === []) {
            return [];
        }
        $hasil = [];
        foreach (['Ayah', 'Ibu', 'Wali'] as $hubungan) {
            $spec = $raw[$hubungan] ?? null;
            if (!is_array($spec)) {
                continue;
            }
            $mode = (string) ($spec['mode'] ?? 'abaikan');
            if (!in_array($mode, ['abaikan', 'pilih', 'baru', 'lepas'], true)) {
                $errors[] = 'Pilihan wali untuk ' . $hubungan . ' tidak dikenal.';
                continue;
            }
            if ($mode === 'abaikan') {
                continue;
            }
            if ($mode === 'pilih') {
                $waliId = (int) ($spec['wali_id'] ?? 0);
                if ($waliId < 1) {
                    $errors[] = 'Pilih satu wali terdaftar untuk ' . $hubungan . ', atau ubah pilihannya menjadi "buat wali baru".';
                    continue;
                }
                $hasil[$hubungan] = ['mode' => 'pilih', 'wali_id' => $waliId];
                continue;
            }
            if ($mode === 'lepas') {
                $hasil[$hubungan] = ['mode' => 'lepas'];
                continue;
            }

            $nama = Normalizer::text($spec['nama'] ?? '');
            $noHp = Normalizer::phone($spec['no_hp'] ?? '');
            $alamat = Normalizer::text($spec['alamat'] ?? '');
            if ($nama === '') {
                $errors[] = 'Nama wali baru untuk ' . $hubungan . ' wajib diisi.';
                continue;
            }
            $this->maxLength($nama, 100, 'Nama wali ' . $hubungan, $errors);
            $this->phone($noHp, $errors, 'Nomor HP wali ' . $hubungan);
            $this->maxLength($alamat, 2000, 'Alamat wali ' . $hubungan, $errors);
            $hasil[$hubungan] = ['mode' => 'baru', 'nama' => $nama, 'no_hp' => $noHp, 'alamat' => $alamat];
        }

        return $hasil;
    }

    /**
     * @param array<string, mixed> $spec
     * @param array<string, mixed> $input
     */
    private function applyWaliSpec(int $santriId, string $hubungan, array $spec, array $input, int $actorId): void
    {
        $relasiLama = $this->repository->santriRelationByHubungan($santriId, $hubungan);

        if ($spec['mode'] === 'lepas') {
            if ($relasiLama !== null) {
                $this->repository->relationArchiveById((int) $relasiLama['id']);
                $this->audit->log('master.relation.archive', 'santri_wali', (int) $relasiLama['id'], $relasiLama, null, $actorId);
            }
            return;
        }

        if ($spec['mode'] === 'baru') {
            // Identitas baru dibuat apa adanya. Sistem TIDAK mencari-cari
            // identitas mirip lalu menggabungkannya: dua orang bernama sama
            // tetap tersimpan sebagai dua orang berbeda.
            $waliId = $this->repository->waliCreate([
                'nama' => $spec['nama'],
                'no_hp' => $spec['no_hp'],
                'alamat' => $spec['alamat'],
            ]);
            $this->audit->log('master.create', 'wali', $waliId, null, [
                'nama' => $spec['nama'], 'no_hp' => $spec['no_hp'], 'sumber' => 'formulir_santri', 'akun_dibuat' => false,
            ], $actorId);
        } else {
            $waliId = (int) $spec['wali_id'];
            if ($this->repository->waliActiveFind($waliId) === null) {
                throw new MasterDataException('Wali yang dipilih untuk ' . $hubungan . ' tidak ditemukan, sudah tidak aktif, atau sudah digabungkan ke identitas lain.');
            }
        }

        if ($relasiLama !== null && (int) $relasiLama['wali_id'] === $waliId) {
            $this->mirrorParent($santriId, $hubungan, $waliId, $input, $actorId);
            return;
        }
        if ($relasiLama !== null) {
            $this->repository->relationArchiveById((int) $relasiLama['id']);
            $this->audit->log('master.relation.archive', 'santri_wali', (int) $relasiLama['id'], $relasiLama, null, $actorId);
        }

        $relationId = $this->repository->waliAttach($waliId, $santriId, $hubungan, $hubungan === 'Ayah', $actorId);
        $this->audit->log('master.relation.create', 'santri_wali', $relationId, null, [
            'wali_id' => $waliId, 'santri_id' => $santriId, 'hubungan' => $hubungan,
        ], $actorId);

        $this->mirrorParent($santriId, $hubungan, $waliId, $input, $actorId);
    }

    /**
     * Menyalin identitas wali yang dikonfirmasi ke kolom lama ayah/ibu.
     *
     * Nilai lama yang BERTENTANGAN tidak ditimpa diam-diam: bila kolom lama
     * sudah berisi nama berbeda, admin wajib mengonfirmasi lewat
     * `konfirmasi_timpa[<hubungan>]`. Nilai sebelum dan sesudah dicatat pada
     * audit sehingga selalu ada jejak untuk dipulihkan.
     *
     * @param array<string, mixed> $input
     */
    private function mirrorParent(int $santriId, string $hubungan, int $waliId, array $input, int $actorId): void
    {
        if ($hubungan !== 'Ayah' && $hubungan !== 'Ibu') {
            return;
        }
        $santri = $this->repository->santriFind($santriId);
        $wali = $this->repository->waliActiveFind($waliId);
        if ($santri === null || $wali === null) {
            return;
        }
        $kolomNama = $hubungan === 'Ayah' ? 'nama_ayah' : 'nama_ibu';
        $kolomHp = $hubungan === 'Ayah' ? 'no_hp_ayah' : 'no_hp_ibu';
        $lamaNama = (string) ($santri[$kolomNama] ?? '');
        $lamaHp = (string) ($santri[$kolomHp] ?? '');
        $baruNama = trim((string) $wali['nama']);
        $baruHp = (string) ($wali['no_hp'] ?? '');

        if ($lamaNama === $baruNama && $lamaHp === $baruHp) {
            return;
        }

        // A-02: nomor lama juga data yang harus dilindungi, termasuk ketika
        // nama sama atau kosong dan nomor wali baru kosong. Simpan nilai asli
        // (termasuk spasi) pada audit agar penimpaan dapat ditelusuri.
        $konflik = ($lamaNama !== '' && $lamaNama !== $baruNama)
            || ($lamaHp !== '' && $lamaHp !== $baruHp);
        $konfirmasi = $input['konfirmasi_timpa'][$hubungan] ?? null;
        if ($konflik && (string) $konfirmasi !== '1') {
            throw new MasterDataException(
                'Nama atau nomor HP pada kolom lama ' . $hubungan . ' berbeda dengan identitas wali yang dipilih. '
                . 'Centang konfirmasi penggantian nilai lama bila memang ingin menimpanya. '
                . 'Nilai sebelum dan sesudah akan tercatat pada audit.'
            );
        }

        $this->repository->santriMirrorParent($santriId, $hubungan, $baruNama, $wali['no_hp'] ?: null);
        $tercatat = $this->audit->log('master.legacy.mirror', 'santri', $santriId, [
            $kolomNama => $lamaNama, $kolomHp => $santri[$kolomHp] ?? null,
        ], [
            $kolomNama => $baruNama, $kolomHp => $wali['no_hp'], 'wali_id' => $waliId, 'dikonfirmasi_admin' => $konflik,
        ], $actorId);
        if (!$tercatat) {
            throw new MasterDataException('Penggantian kolom lama dibatalkan karena audit tidak dapat disimpan.');
        }
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

        // Identitas wali dapat dipakai bersama beberapa santri (saudara
        // kandung). Mengubahnya berdampak ke semua santri itu sekaligus,
        // sehingga admin wajib melihat daftarnya lebih dulu dan mengonfirmasi.
        $terdampak = array_values(array_filter(
            $this->repository->waliRelations($id),
            static fn (array $relasi): bool => $relasi['archived_at'] === null
        ));
        $berubah = $before['nama'] !== $data['nama'] || (string) ($before['no_hp'] ?? '') !== (string) ($data['no_hp'] ?? '');
        if ($berubah && count($terdampak) > 1 && (string) ($input['konfirmasi_dampak'] ?? '') !== '1') {
            throw new MasterDataException(
                'Identitas ini dipakai bersama oleh ' . count($terdampak) . ' santri: '
                . implode(', ', array_map(static fn (array $r): string => (string) $r['nama_santri'], $terdampak))
                . '. Periksa daftar tersebut lalu centang konfirmasi sebelum menyimpan perubahan identitas.'
            );
        }

        $this->repository->waliUpdate($id, $data);
        $this->audit->log('master.update', 'wali', $id, $before, [
            'sesudah' => $this->repository->waliFind($id),
            'santri_terdampak' => array_map(static fn (array $r): int => (int) $r['santri_id'], $terdampak),
        ]);
        return $id;
    }

    /**
     * Santri yang terdampak bila identitas satu wali diubah.
     *
     * @return array<int, array<string, mixed>>
     */
    public function waliImpact(int $waliId): array
    {
        return $this->repository->waliRelations($waliId);
    }

    /**
     * Laporan rekonsiliasi data wali lama.
     *
     * Seluruh isinya bersifat KANDIDAT dan LAPORAN. Tidak ada satu pun bagian
     * yang mengubah data: penggabungan hanya terjadi lewat `mergeWali()` setelah
     * admin mengonfirmasi identitas dan santri yang tepat, satu per satu.
     * Penggabungan massal tidak disediakan.
     *
     * @return array{rows:array, total:int, page:int, perPage:int}
     */
    public function reconciliationPage(string $section, string $q, int $page): array
    {
        $result = $this->repository->reconciliationPage($section, $q, $page);
        if ($section === 'duplikat') { $result['rows'] = $this->describeDuplicateGroups($result['rows']); }
        return $result;
    }

    private function describeDuplicateGroups(array $groups): array
    {
        $duplikat = [];
        foreach ($groups as $grup) {
            $ids = $this->repository->waliDuplicateIds((string)$grup['jenis'], (string)$grup['kunci']);
            $anggota = $this->repository->waliByIds($ids);
            $akun = 0;
            foreach ($anggota as $baris) {
                $akun += (int) $baris['jumlah_akun'];
            }
            $duplikat[] = [
                'jenis' => (string) $grup['jenis'],
                'kunci' => (string) $grup['kunci'],
                'jumlah' => (int) $grup['jumlah'],
                'anggota' => $anggota,
                'jumlah_akun' => $akun,
                // Diblokir bila lebih dari satu anggota memiliki akun login:
                // penggabungan akan mengubah santri yang dilihat akun orang tua.
                'diblokir' => $akun > 1,
            ];
        }

        return $duplikat;
    }

    /** Kontrak laporan lama dipertahankan untuk konsumen dan regresi yang ada. */
    public function reconciliationReport(int $limit = 100): array
    {
        $duplikat = $this->describeDuplicateGroups($this->repository->waliDuplicateCandidates($limit));

        return [
            'duplikat' => $duplikat,
            'tanpa_relasi' => $this->repository->waliWithoutRelations($limit),
            'relasi_belum_lengkap' => $this->repository->santriWithIncompleteWali($limit),
            'konflik_kolom_lama' => $this->repository->santriLegacyConflicts($limit),
        ];
    }

    /**
     * Menggabungkan satu identitas wali ke identitas lain, atas konfirmasi admin.
     *
     * Aturan keras:
     *   - hanya satu pasang per tindakan; tidak ada penggabungan massal;
     *   - diblokir bila salah satu sisi memiliki akun login, karena penggabungan
     *     mengubah santri yang dapat dilihat akun orang tua. Admin wajib
     *     menyelesaikan akun tersebut lebih dulu secara eksplisit;
     *   - diblokir bila admin belum melihat dan mengonfirmasi daftar santri
     *     yang terdampak;
     *   - baris wali sumber TIDAK DIHAPUS: ID-nya dipertahankan, barisnya
     *     diarsipkan, dan `merged_into_wali_id` menunjuk ke tujuan;
     *   - relasi yang sudah ada pada tujuan tidak diduplikasi.
     *
     * @return array{dipindahkan:int, diarsipkan:int, santri:array<int,int>}
     */
    public function mergeWali(int $sourceId, int $targetId, int $actorId, bool $confirmed): array
    {
        if ($sourceId === $targetId || $sourceId < 1 || $targetId < 1) {
            throw new MasterDataException('Pilih dua identitas wali yang berbeda.');
        }
        if (!$confirmed) {
            throw new MasterDataException('Penggabungan dibatalkan: konfirmasi daftar santri terdampak belum dicentang.');
        }

        $db = $this->repository->db();
        $db->begin_transaction();
        try {
            $this->repository->waliLockPair($sourceId, $targetId);
            // A-03: baca dan validasi ulang SETELAH lock. Dua permintaan
            // berlawanan tidak boleh sama-sama memakai keadaan sebelum merge.
            $sumber = $this->mustFind($this->repository->waliFind($sourceId), 'Wali sumber');
            $tujuan = $this->mustFind($this->repository->waliFind($targetId), 'Wali tujuan');
            if (!empty($tujuan['archived_at']) || !empty($tujuan['merged_into_wali_id'])) {
                throw new MasterDataException('Wali tujuan sudah diarsipkan atau sudah digabungkan ke identitas lain.');
            }
            if (!empty($sumber['merged_into_wali_id'])) {
                throw new MasterDataException('Wali sumber sudah pernah digabungkan. Periksa jejaknya pada audit.');
            }

            $akunSumber = $this->repository->waliAccount($sourceId);
            $akunTujuan = $this->repository->waliAccount($targetId);
            if ($akunSumber !== null && $akunTujuan !== null) {
                throw new MasterDataException(
                    'Penggabungan diblokir: kedua identitas memiliki akun login (@' . $akunSumber['username']
                    . ' dan @' . $akunTujuan['username'] . '). Selesaikan salah satu akun terlebih dahulu pada halaman Akun & Hak Akses.'
                );
            }
            if ($akunSumber !== null) {
                throw new MasterDataException(
                    'Penggabungan diblokir: identitas sumber memiliki akun login (@' . $akunSumber['username']
                    . '). Menggabungkannya akan mengubah santri yang dapat dilihat akun tersebut. '
                    . 'Pindahkan atau nonaktifkan akun itu lebih dahulu pada halaman Akun & Hak Akses.'
                );
            }

            $relasiSumber = $this->repository->waliRelations($sourceId);
            $relasiTujuan = $this->repository->waliRelations($targetId);
            $sudahAda = [];
            foreach ($relasiTujuan as $relasi) {
                if ($relasi['archived_at'] === null) {
                    $sudahAda[(int) $relasi['santri_id'] . '|' . (string) $relasi['hubungan']] = true;
                }
            }

            $dipindahkan = 0;
            $diarsipkan = 0;
            $santriTerdampak = [];
            foreach ($relasiSumber as $relasi) {
                if ($relasi['archived_at'] !== null) {
                    continue;
                }
                $santriTerdampak[] = (int) $relasi['santri_id'];
                $kunci = (int) $relasi['santri_id'] . '|' . (string) $relasi['hubungan'];
                if (isset($sudahAda[$kunci])) {
                    $this->repository->relationArchiveById((int) $relasi['id']);
                    $diarsipkan++;
                    continue;
                }
                $this->repository->relationRepoint((int) $relasi['id'], $targetId);
                $sudahAda[$kunci] = true;
                $dipindahkan++;
            }

            $this->repository->waliMarkMerged($sourceId, $targetId);

            $this->audit->log('master.wali.merge', 'wali', $sourceId, [
                'wali' => $sumber,
                'relasi_aktif' => $dipindahkan + $diarsipkan,
            ], [
                'digabungkan_ke' => $targetId,
                'wali_tujuan' => $tujuan['nama'],
                'relasi_dipindahkan' => $dipindahkan,
                'relasi_duplikat_diarsipkan' => $diarsipkan,
                'santri_terdampak' => $santriTerdampak,
                'id_lama_dipertahankan' => true,
            ], $actorId);

            $db->commit();

            return ['dipindahkan' => $dipindahkan, 'diarsipkan' => $diarsipkan, 'santri' => $santriTerdampak];
        } catch (Throwable $exception) {
            $db->rollback();
            throw $exception;
        }
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
        $this->tolakIdentitasGuru($before);
        $this->repository->pengurusUpdate($id, $data);
        $this->audit->log('master.update', 'pengurus', $id, $before, $this->repository->pengurusFind($id));
        return $id;
    }

    public function setPengurusState(int $id, string $action): void
    {
        $this->tolakIdentitasGuru($this->repository->pengurusFind($id));
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
        try {
            return $this->penugasan()->buat('murobi', $input, $actorId);
        } catch (\App\Penugasan\PenugasanException $e) {
            throw new MasterDataException($e->getMessage(), $e->errors());
        }
    }

    public function setMurobiState(int $id, string $action, ?int $actorId = null, string $alasan = ''): void
    {
        try {
            $this->penugasan()->statusLama('murobi', $id, $action, $actorId ?? (int) ($_SESSION['user_id'] ?? 0), $alasan);
        } catch (\App\Penugasan\PenugasanException $e) {
            throw new MasterDataException($e->getMessage(), $e->errors());
        }
    }

    private function penugasan(): \App\Penugasan\PenugasanService
    {
        $db = $this->repository->db();
        return new \App\Penugasan\PenugasanService(new \App\Penugasan\PenugasanRepository($db), $this->audit, new \App\Auth\Capabilities($db));
    }

    public function guruOptions(): array { return $this->repository->guruOptions(); }
    public function santriOptions(): array { return $this->repository->santriOptions(); }
    /** Semua santri termasuk nonaktif/arsip (D-9: hubungkan catatan alumni warisan). */
    public function santriListAll(): array { return $this->repository->santriAllOptions(); }
    public function kamarOptions(): array { return $this->repository->kamarList(); }

    public function room(int $id): ?array { return $this->repository->kamarFind($id); }

    /** Nilai asrama yang sah (keputusan AE). */
    public const ASRAMA = ['Putra', 'Putri'];

    /**
     * A-11: perubahan kamar tervalidasi dan tercatat; tidak menyediakan penghapusan.
     *
     * Keputusan AE (Tahap 6): kamar baru WAJIB memilih asrama Putra/Putri bila
     * kolomnya sudah ada (migrasi 023). Pada ubah, asrama hanya ditulis bila
     * dikirim, sehingga pemanggil lama tidak mengosongkannya diam-diam.
     */
    public function saveRoom(array $input, ?int $id = null): int
    {
        $name = is_scalar($input['nama_kamar'] ?? null) ? Normalizer::text($input['nama_kamar']) : '';
        $capacity = filter_var($input['kapasitas'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
        $errors = [];
        $this->required($name, 'Nama kamar wajib diisi.', $errors);
        $this->maxLength($name, 50, 'Nama kamar', $errors);
        if ($capacity === false) { $errors[] = 'Kapasitas harus bilangan bulat positif.'; }
        $data = ['nama_kamar' => $name, 'kapasitas' => $capacity];
        if ($this->repository->asramaTersedia() && ($id === null || array_key_exists('asrama', $input))) {
            $asrama = is_scalar($input['asrama'] ?? null) ? (string) $input['asrama'] : '';
            if (!in_array($asrama, self::ASRAMA, true)) {
                $errors[] = 'Pilih asrama kamar: Putra atau Putri.';
            }
            $data['asrama'] = $asrama;
        }
        $this->reject($errors);
        $db = $this->repository->db();
        $db->begin_transaction();
        try {
            $before = $id === null ? null : $this->mustFind($this->repository->kamarFind($id, true), 'Kamar');
            $id = $this->repository->kamarSave($data, $id);
            if (!$this->audit->log($before === null ? 'master.create' : 'master.update', 'kamar', $id, $before, $this->repository->kamarFind($id))) {
                throw new MasterDataException('Perubahan kamar dibatalkan karena audit tidak dapat disimpan.');
            }
            $db->commit();
            return $id;
        } catch (Throwable $exception) {
            $db->rollback();
            throw $exception;
        }
    }

    // -----------------------------------------------------------------------
    // Rekonsiliasi per pasangan dan tanda "bukan ganda" (Tahap 6, D-7, AD)
    // -----------------------------------------------------------------------

    /**
     * Jumlah tiap bagian rekonsiliasi untuk chip D-7 dan tombol D-6.
     *
     * @return array{duplikat:int, relasi_belum_lengkap:int, konflik_kolom_lama:int, tanpa_relasi:int, bukan_ganda:int, total:int}
     */
    public function reconciliationSummary(): array
    {
        $hasil = ['duplikat' => $this->repository->duplicatePairCount()];
        foreach (['relasi_belum_lengkap', 'konflik_kolom_lama', 'tanpa_relasi'] as $bagian) {
            $hasil[$bagian] = $this->repository->reconciliationCount($bagian);
        }
        $hasil['bukan_ganda'] = $this->repository->notDuplicateCount();
        $hasil['total'] = $hasil['duplikat'] + $hasil['relasi_belum_lengkap'] + $hasil['konflik_kolom_lama'] + $hasil['tanpa_relasi'];

        return $hasil;
    }

    /**
     * Satu pasangan kemungkinan ganda per halaman, lengkap untuk dibandingkan
     * berdampingan: identitas, santri terhubung, dan akun login.
     *
     * @return array{pasangan:?array<string, mixed>, total:int, page:int}
     */
    public function duplicatePair(string $q, int $page): array
    {
        $hasil = $this->repository->duplicatePairsPage($q, max(1, $page));
        $baris = $hasil['rows'][0] ?? null;
        if ($baris === null) {
            return ['pasangan' => null, 'total' => (int) $hasil['total'], 'page' => (int) $hasil['page']];
        }
        $anggota = [];
        foreach ($this->repository->waliByIds([(int) $baris['wali_a_id'], (int) $baris['wali_b_id']]) as $wali) {
            $wali['relations'] = array_values(array_filter($this->repository->waliRelations((int) $wali['id']), static fn (array $r): bool => $r['archived_at'] === null));
            $wali['akun'] = $this->repository->waliAccount((int) $wali['id']);
            $anggota[(int) $wali['id']] = $wali;
        }
        $a = $anggota[(int) $baris['wali_a_id']] ?? null;
        $b = $anggota[(int) $baris['wali_b_id']] ?? null;

        return [
            'pasangan' => $a === null || $b === null ? null : [
                'a' => $a,
                'b' => $b,
                'jenis' => explode(',', (string) $baris['jenis']),
                'kunci' => (string) $baris['kunci'],
                'diblokir' => $a['akun'] !== null && $b['akun'] !== null,
            ],
            'total' => (int) $hasil['total'],
            'page' => (int) $hasil['page'],
        ];
    }

    public function notDuplicatePage(string $q, int $page): array
    {
        return $this->repository->bukanGandaTersedia()
            ? $this->repository->notDuplicatePage($q, max(1, $page))
            : ['rows' => [], 'total' => 0, 'page' => 1, 'perPage' => 20];
    }

    /**
     * Keputusan AD: menyimpan tanda bahwa dua identitas wali BUKAN orang yang
     * sama. Hanya untuk pasangan yang memang masih kandidat (nama/HP sama);
     * alasan wajib; pelaku dan alasan tercatat di tabel dan audit.
     */
    public function markNotDuplicate(int $waliA, int $waliB, string $alasan, int $actorId): int
    {
        [$a, $b] = $this->pasanganUrut($waliA, $waliB);
        $alasan = $this->alasanPasangan($alasan);
        if (!$this->repository->bukanGandaTersedia()) {
            throw new MasterDataException('Tanda "bukan ganda" belum tersedia: migrasi basis data Tahap 6 belum dijalankan.');
        }
        $db = $this->repository->db();
        $db->begin_transaction();
        try {
            $this->repository->waliLockPair($a, $b);
            if (!$this->repository->isDuplicatePair($a, $b)) {
                throw new MasterDataException('Pasangan ini bukan lagi kemungkinan ganda (nama dan nomor HP berbeda, atau salah satu sudah diarsipkan/digabungkan).');
            }
            if ($this->repository->notDuplicateActive($a, $b, true) !== null) {
                throw new MasterDataException('Pasangan ini sudah ditandai bukan ganda.');
            }
            $id = $this->repository->notDuplicateCreate($a, $b, $alasan, $actorId);
            if (!$this->audit->log('master.wali.bukan_ganda', 'wali_bukan_ganda', $id, null, ['wali_a_id' => $a, 'wali_b_id' => $b, 'alasan' => $alasan], $actorId)) {
                throw new MasterDataException('Tanda dibatalkan karena audit tidak dapat disimpan.');
            }
            $db->commit();

            return $id;
        } catch (Throwable $exception) {
            $db->rollback();
            throw $exception;
        }
    }

    /** Membatalkan tanda "bukan ganda"; baris tanda tidak dihapus. */
    public function cancelNotDuplicate(int $markId, string $alasan, int $actorId): void
    {
        $alasan = $this->alasanPasangan($alasan);
        if (!$this->repository->bukanGandaTersedia()) {
            throw new MasterDataException('Tanda "bukan ganda" belum tersedia: migrasi basis data Tahap 6 belum dijalankan.');
        }
        $db = $this->repository->db();
        $db->begin_transaction();
        try {
            $tanda = $this->repository->notDuplicateFind($markId, true);
            if ($tanda === null || $tanda['dibatalkan_pada'] !== null) {
                throw new MasterDataException('Tanda bukan ganda tidak ditemukan atau sudah dibatalkan.');
            }
            $this->repository->notDuplicateCancel($markId, $alasan, $actorId);
            if (!$this->audit->log('master.wali.bukan_ganda_batal', 'wali_bukan_ganda', $markId, $tanda, ['alasan_batal' => $alasan], $actorId)) {
                throw new MasterDataException('Pembatalan dibatalkan karena audit tidak dapat disimpan.');
            }
            $db->commit();
        } catch (Throwable $exception) {
            $db->rollback();
            throw $exception;
        }
    }

    /** @return array{0:int, 1:int} */
    private function pasanganUrut(int $a, int $b): array
    {
        if ($a < 1 || $b < 1 || $a === $b) {
            throw new MasterDataException('Pilih dua identitas wali yang berbeda.');
        }

        return $a < $b ? [$a, $b] : [$b, $a];
    }

    private function alasanPasangan(string $alasan): string
    {
        $alasan = Normalizer::text($alasan);
        if (mb_strlen($alasan) < 5 || mb_strlen($alasan) > 500) {
            throw new MasterDataException('Alasan wajib diisi, 5–500 karakter.');
        }

        return $alasan;
    }

    public function exportGuru(array $filters): array { return $this->repository->exportGuru($this->filters($filters)); }
    public function exportSantri(array $filters): array { return $this->repository->exportSantri($this->filters($filters)); }
    public function exportPengurus(array $filters): array { return $this->repository->pengurusList($this->filters($filters), 1, 100000)['rows']; }

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
            'kamar_id' => max(0, (int) ($filters['kamar_id'] ?? 0)),
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
