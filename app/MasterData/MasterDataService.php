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
     * @return array{duplikat:array<int,array<string,mixed>>, tanpa_relasi:array<int,array<string,mixed>>, relasi_belum_lengkap:array<int,array<string,mixed>>, konflik_kolom_lama:array<int,array<string,mixed>>}
     */
    public function reconciliationReport(int $limit = 100): array
    {
        $duplikat = [];
        foreach ($this->repository->waliDuplicateCandidates($limit) as $grup) {
            $ids = array_map('intval', explode(',', (string) $grup['wali_ids']));
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

        $db = $this->repository->db();
        $db->begin_transaction();
        try {
            $this->repository->waliLockPair($sourceId, $targetId);

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
