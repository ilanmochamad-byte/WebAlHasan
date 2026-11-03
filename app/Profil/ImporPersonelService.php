<?php

declare(strict_types=1);

namespace App\Profil;

use App\Audit\AuditLogger;
use App\MasterData\Normalizer;
use Throwable;

/**
 * Impor Excel identitas Guru dan Pengurus (PRD-PROFIL-IMPORT Fase 1 §3.4–3.5).
 *
 * Berkas dibaca di peramban (SheetJS, seperti impor santri) dan dikirim sebagai
 * daftar baris berkunci kolom kanonis. Pratinjau (`periksa`) dan simpan
 * (`simpan`) memakai analisis yang SAMA; simpan menganalisis ulang setiap baris
 * di bawah kunci baris dan membandingkan sidik pratinjau, sehingga perubahan
 * data di antara kedua langkah menjadi konflik, bukan penimpaan diam-diam.
 *
 * Aturan:
 *   - Sedikitnya NIP atau NIK wajib ada; NIK 16 digit (PI-4); NIP pengurus =
 *     `pengurus.nomor_identitas` (PI-9), NIK di profil.
 *   - Pencocokan pasti lewat ID sistem/NIP/NIK. Nama yang sama hanya MENYARANKAN calon;
 *     admin wajib memilih pasangan atau "tambah baru" (PI-3).
 *   - NIP dan NIK yang menunjuk dua orang berbeda, NIP/NIK/HP ganda di dalam
 *     berkas, dua baris yang menunjuk orang yang sama, dan identitas pengurus
 *     milik guru pada impor Data Pengurus (PI-10) menjadi konflik.
 *   - Sel kosong tidak pernah mengosongkan nilai lama. Nilai lama yang tidak
 *     kosong hanya ditimpa dengan konfirmasi per baris; NIP/NIK lama yang
 *     berbeda hanya diganti dengan konfirmasi pengenal tersendiri.
 *   - Tugas, Kamar/Kobong, dan Tugas Tambahan hanya informasi pratinjau: tidak
 *     pernah membuat atau mengubah penugasan, akun, username, atau hak.
 *   - Tidak ada akun yang dibuat. Pengurus baru berjabatan "Pengurus" (PI-8).
 *   - Ulang berkas yang sama: seluruh baris menjadi "tidak berubah" (idempoten).
 */
final class ImporPersonelService
{
    public const BATAS_BARIS = 500;

    /** Kolom kanonis templat, urut tampil. `nip_nik` = dikenali otomatis. */
    public const KOLOM = ['id_sistem', 'nama', 'nip', 'nik', 'tempat_lahir', 'tanggal_lahir', 'alamat_rumah', 'kelurahan', 'kecamatan', 'kabupaten', 'provinsi', 'no_hp', 'email', 'tugas', 'kamar', 'tugas_tambahan'];

    public const JUDUL = [
        'id_sistem' => 'ID Sistem', 'nama' => 'Nama Lengkap', 'nip' => 'NIP', 'nik' => 'NIK', 'nip_nik' => 'NIP atau NIK (dikenali otomatis)', 'tempat_lahir' => 'Tempat Lahir',
        'tanggal_lahir' => 'Tanggal Lahir', 'alamat_rumah' => 'Alamat Rumah', 'kelurahan' => 'Kelurahan/Desa', 'kecamatan' => 'Kecamatan',
        'kabupaten' => 'Kabupaten/Kota', 'provinsi' => 'Provinsi', 'no_hp' => 'No. HP', 'email' => 'Email',
        'tugas' => 'Tugas', 'kamar' => 'Kamar/Kobong', 'tugas_tambahan' => 'Tugas Tambahan',
    ];

    /** Kolom informasi saja — tidak pernah disimpan. */
    public const INFORMASI = ['tugas', 'kamar', 'tugas_tambahan'];

    private const IDENTITAS = ['nama', 'nip', 'nik', 'tempat_lahir', 'tanggal_lahir', 'alamat_rumah', 'kelurahan', 'kecamatan', 'kabupaten', 'provinsi', 'no_hp', 'email'];

    public function __construct(
        private ProfilRepository $repository,
        private ProfilService $profil,
        private AuditLogger $audit
    ) {
    }

    /**
     * Pratinjau tanpa menyimpan apa pun.
     *
     * @param array<int, mixed> $rows
     * @param array<string|int, mixed> $pilihan per nomor baris: aksi, pasangan_id, timpa, ubah_pengenal
     * @return array<string, mixed>
     */
    public function periksa(string $jenis, array $rows, array $pilihan = []): array
    {
        $this->jenis($jenis);
        if (!$this->repository->tersedia()) {
            throw ProfilException::belumDipasang();
        }
        $calon = $this->siapkan($rows);
        $hasil = [];
        foreach ($calon as $c) {
            $hasil[] = $this->analisis($jenis, $c, $this->pilihan($pilihan, $c['baris']), false);
        }
        $this->tandaiBentrokSasaran($hasil);

        return $this->ringkas($hasil);
    }

    /**
     * Menyimpan baris yang siap dalam SATU transaksi bersama audit ringkasan.
     * Setiap baris dianalisis ulang di bawah kunci; sidik yang tidak sama
     * dengan pratinjau → konflik dan dilewati.
     *
     * @param array<int, mixed> $rows
     * @param array<string|int, mixed> $pilihan
     * @param array{nama?:string, ukuran?:int, sha256?:string} $berkas metadata berkas (tanpa isi)
     * @return array<string, mixed>
     */
    public function simpan(string $jenis, array $rows, array $pilihan, int $aktor, array $berkas = []): array
    {
        $this->jenis($jenis);
        if (!$this->repository->tersedia()) {
            throw ProfilException::belumDipasang();
        }
        $calon = $this->siapkan($rows);

        return $this->repository->transaction(function () use ($jenis, $calon, $pilihan, $aktor, $berkas): array {
            $hasil = [];
            foreach ($calon as $c) {
                $hasil[] = $this->analisis($jenis, $c, $this->pilihan($pilihan, $c['baris']), true);
            }
            $this->tandaiBentrokSasaran($hasil);
            $hitung = ['ditambah' => 0, 'diperbarui' => 0];
            foreach ($hasil as &$baris) {
                $p = $this->pilihan($pilihan, $baris['baris']);
                if (!in_array($baris['aksi'], ['tambah', 'perbarui'], true)) {
                    continue;
                }
                if (!is_string($p['sidik'] ?? null) || !hash_equals($baris['sidik'], (string) $p['sidik'])) {
                    $baris['aksi'] = 'konflik';
                    $baris['masalah'][] = 'Data berubah sejak pratinjau atau baris belum diperiksa. Periksa ulang berkas sebelum menyimpan.';
                    continue;
                }
                $baris = $this->terapkan($jenis, $baris, $aktor);
                $hitung[$baris['aksi'] === 'ditambah' ? 'ditambah' : 'diperbarui']++;
            }
            unset($baris);
            $ringkas = $this->ringkas($hasil);
            if (!$this->audit->log('impor_personel.simpan', $jenis, null, null, [
                'jenis' => $jenis, 'baris' => $ringkas['baris'], 'ditambah' => $hitung['ditambah'], 'diperbarui' => $hitung['diperbarui'],
                'tidak_berubah' => $ringkas['jumlah']['tidak_berubah'], 'dilewati' => $ringkas['baris'] - $hitung['ditambah'] - $hitung['diperbarui'] - $ringkas['jumlah']['tidak_berubah'],
                'berkas_sha256' => isset($berkas['sha256']) && preg_match('/^[0-9a-f]{64}$/', (string) $berkas['sha256']) ? $berkas['sha256'] : null,
                'berkas_ukuran' => isset($berkas['ukuran']) ? (int) $berkas['ukuran'] : null,
            ], $aktor)) {
                throw new ProfilException('Impor dibatalkan karena audit tidak dapat disimpan. Tidak ada data yang berubah.', 503);
            }

            return $ringkas + ['disimpan' => $hitung['ditambah'] + $hitung['diperbarui'], 'ditambah' => $hitung['ditambah'], 'diperbarui' => $hitung['diperbarui']];
        });
    }

    /** Baris templat: judul kanonis + satu contoh fiktif. */
    public static function templat(string $jenis): array
    {
        $judul = array_map(static fn (string $k): string => self::JUDUL[$k], self::KOLOM);
        $contoh = ['', 'Contoh Nama Lengkap', $jenis === 'guru' ? 'G-0001' : 'P-0001', '3200000000000001', 'Ciamis', '1990-01-31', 'Jl. Contoh No. 1', 'Desa Contoh', 'Kecamatan Contoh', 'Kabupaten Contoh', 'Jawa Barat', '081200000000', 'contoh@example.com', $jenis === 'guru' ? 'Murobi' : 'Pembimbing', 'Kamar Contoh', '-'];

        return [$judul, $contoh];
    }

    // =====================================================================

    /**
     * @param array<int, mixed> $rows
     * @return array<int, array{baris:int, mentah:array<string, mixed>}>
     */
    private function siapkan(array $rows): array
    {
        if (count($rows) > self::BATAS_BARIS) {
            throw new ProfilException('Satu berkas impor dibatasi ' . self::BATAS_BARIS . ' baris. Bagi berkas menjadi beberapa bagian.', 422);
        }
        $hasil = [];
        foreach (array_values($rows) as $index => $row) {
            if (!is_array($row)) {
                $hasil[] = ['baris' => $index + 2, 'mentah' => null];
                continue;
            }
            $nomor = isset($row['baris']) && is_numeric($row['baris']) ? max(1, (int) $row['baris']) : $index + 2;
            $mentah = [];
            foreach (array_merge(self::KOLOM, ['nip_nik']) as $kolom) {
                $nilai = $row[$kolom] ?? null;
                $mentah[$kolom] = is_scalar($nilai) ? $nilai : null;
            }
            if (implode('', array_map(static fn ($v): string => trim((string) $v), $mentah)) === '') {
                continue;
            }
            $hasil[] = ['baris' => $nomor, 'mentah' => $mentah];
        }

        // Nomor ganda di dalam berkas (NIP, NIK, HP).
        $data = [];
        foreach ($hasil as $i => $c) {
            $data[$i] = $c['mentah'] === null ? [] : $this->normal($c['mentah'])[0];
        }
        $lihat = ['nip' => [], 'nik' => [], 'no_hp' => []];
        foreach ($data as $i => $d) {
            foreach (array_keys($lihat) as $kunci) {
                if (!empty($d[$kunci])) {
                    $lihat[$kunci][(string) $d[$kunci]][] = $hasil[$i]['baris'];
                }
            }
        }
        foreach ($hasil as $i => &$c) {
            $c['ganda'] = [];
            foreach ($lihat as $kunci => $peta) {
                $nilai = (string) ($data[$i][$kunci] ?? '');
                if ($nilai !== '' && count($peta[$nilai]) > 1) {
                    $lain = array_values(array_diff($peta[$nilai], [$c['baris']]));
                    $c['ganda'][] = ProfilData::LABEL[$kunci] . ' ' . $nilai . ' ganda di dalam berkas (sama dengan baris ' . implode(', ', $lain) . ').';
                }
            }
        }
        unset($c);

        return $hasil;
    }

    /**
     * @param array<string, mixed> $mentah
     * @return array{0: array<string, ?string>, 1: array<string, string>, 2: array<int, string>}
     */
    private function normal(array $mentah, string $jenis = 'guru'): array
    {
        $catatan = [];
        $masuk = $mentah;
        foreach (['nip', 'nik', 'nip_nik', 'no_hp'] as $kolom) {
            if (is_float($masuk[$kolom] ?? null) || is_int($masuk[$kolom] ?? null)) {
                $angka = $masuk[$kolom];
                $masuk[$kolom] = abs($angka) >= 1e15 || floor($angka) !== (float) $angka
                    ? '#PRESISI'
                    : sprintf('%.0f', $angka);
            }
            if (($masuk[$kolom] ?? null) === '#PRESISI') {
                $catatan[$kolom] = ProfilData::LABEL[$kolom === 'nip_nik' ? 'nik' : $kolom] . ' berupa sel angka yang terlalu panjang untuk dibaca dengan tepat; ubah sel menjadi teks di Excel.';
                $masuk[$kolom] = '';
            }
            if (is_string($masuk[$kolom] ?? null) && preg_match('/^[0-9.]+E\+?[0-9]+$/i', trim($masuk[$kolom]))) {
                $catatan[$kolom] = ProfilData::LABEL[$kolom === 'nip_nik' ? 'nik' : $kolom] . ' tertulis sebagai notasi ilmiah; ubah sel menjadi teks di Excel.';
            }
        }
        $otomatis = trim((string) ($masuk['nip_nik'] ?? ''));
        if ($otomatis !== '') {
            $bersih = preg_replace('/\s+/', '', $otomatis) ?? '';
            if (preg_match('/^[0-9]{16}$/', $bersih) && trim((string) ($masuk['nik'] ?? '')) === '') {
                $masuk['nik'] = $bersih;
            } elseif (trim((string) ($masuk['nip'] ?? '')) === '') {
                $masuk['nip'] = $otomatis;
            }
        }
        [$data, $errors] = ProfilData::normalisasi($masuk, self::IDENTITAS, [
            'batas_hp' => $jenis === 'guru' ? 15 : 16, 'batas_nip' => $jenis === 'guru' ? 30 : 50, 'latin1_nama' => $jenis === 'guru',
        ]);
        $errors = $catatan + $errors;
        $info = [];
        foreach (self::INFORMASI as $kolom) {
            $info[$kolom] = mb_substr(Normalizer::text($mentah[$kolom] ?? ''), 0, 200);
        }

        return [$data, $errors, $info];
    }

    /**
     * Analisis satu baris. `$kunci` = dipanggil di transaksi simpan (baris
     * sasaran dikunci).
     *
     * @param array<string, mixed> $c
     * @param array<string, mixed> $p
     * @return array<string, mixed>
     */
    private function analisis(string $jenis, array $c, array $p, bool $kunci): array
    {
        $baris = [
            'baris' => $c['baris'], 'nama' => '', 'nip' => null, 'nik' => null, 'informasi' => [],
            'aksi' => 'konflik', 'sasaran' => null, 'kandidat' => [], 'perubahan' => [], 'menimpa' => [],
            'masalah' => [], 'peringatan' => [], 'sidik' => '', 'data' => [],
        ];
        if ($c['mentah'] === null) {
            $baris['masalah'][] = 'Format baris tidak valid.';
            return $baris;
        }
        [$data, $errors, $info] = $this->normal($c['mentah'], $jenis);
        $baris['nama'] = (string) ($data['nama'] ?? '');
        $baris['nip'] = $data['nip'] ?? null;
        $baris['nik'] = $data['nik'] ?? null;
        $baris['informasi'] = $info;
        if (array_filter($info, static fn (string $v): bool => $v !== '') !== []) {
            $baris['peringatan'][] = 'Tugas, Kamar/Kobong, dan Tugas Tambahan hanya informasi: tidak membuat atau mengubah penugasan. Atur penugasan di menu Penugasan.';
        }
        $baris['masalah'] = array_values(array_merge($baris['masalah'], array_values($errors), $c['ganda']));
        $idMentah = trim((string) ($c['mentah']['id_sistem'] ?? ''));
        $idSistem = null;
        if ($idMentah !== '') {
            $idSistem = filter_var($idMentah, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($idSistem === false) {
                $baris['masalah'][] = 'ID Sistem harus berupa bilangan bulat positif.';
            }
        }
        if (empty($data['nip']) && empty($data['nik'])) {
            $baris['masalah'][] = 'NIP atau NIK wajib diisi sedikitnya salah satu.';
        }
        if ($baris['masalah'] !== []) {
            $baris['sidik'] = $this->sidik(['konflik', $c['baris']]);
            return $baris;
        }

        // --- Pencocokan pasti ----------------------------------------------
        $olehNip = $data['nip'] !== null ? $this->repository->cariNip($jenis, (string) $data['nip']) : [];
        $olehNik = $data['nik'] !== null ? $this->repository->cariNik($jenis, (string) $data['nik']) : [];
        if (count($olehNip) > 1) {
            $baris['masalah'][] = 'NIP ' . $data['nip'] . ' terdaftar pada lebih dari satu data lama. Rapikan data lama lebih dulu.';
        }
        $idNip = isset($olehNip[0]) ? (int) $olehNip[0]['id'] : null;
        $idNik = isset($olehNik[0]) ? (int) $olehNik[0]['id'] : null;
        if ($idNip !== null && $idNik !== null && $idNip !== $idNik) {
            $baris['masalah'][] = 'NIP dan NIK menunjuk dua ' . $jenis . ' berbeda (ID ' . $idNip . ' dan ' . $idNik . ').';
        }
        if ($idSistem !== null && (($idNip !== null && $idSistem !== $idNip) || ($idNik !== null && $idSistem !== $idNik))) {
            $baris['masalah'][] = 'ID Sistem bertentangan dengan pemilik NIP/NIK yang sudah terdaftar.';
        }
        $this->periksaSilang($jenis, $data, $baris);
        if ($baris['masalah'] !== []) {
            $baris['sidik'] = $this->sidik(['konflik', $c['baris']]);
            return $baris;
        }
        $sasaranId = $idSistem ?? $idNip ?? $idNik;
        $caraCocok = $idSistem !== null ? 'ID Sistem' : ($idNip !== null ? 'NIP' : ($idNik !== null ? 'NIK' : null));

        // --- Tanpa kecocokan pasti: calon bernama sama (PI-3) ----------------
        if ($sasaranId === null) {
            foreach ($this->repository->cariNama($jenis, (string) $data['nama']) as $calon) {
                $profilCalon = $this->repository->profil($jenis, (int) $calon['id']);
                $nilai = $this->profil->pribadiMaster($jenis, $calon, $profilCalon);
                $milikGuru = $jenis === 'pengurus' && !empty($calon['guru_id']);
                $baris['kandidat'][] = [
                    'id' => (int) $calon['id'], 'nama' => (string) $nilai['nama'], 'nip' => $nilai['nip'], 'nik' => $nilai['nik'], 'no_hp' => $nilai['no_hp'],
                    'aktif' => (int) $calon['is_active'] === 1 && empty($calon['archived_at']),
                    'dapat_dipilih' => !$milikGuru,
                    'catatan' => $milikGuru ? 'Identitas pengurus milik guru — ubah lewat Data Guru.' : null,
                ];
            }
            $aksi = (string) ($p['aksi'] ?? 'otomatis');
            if ($aksi === 'pasangan') {
                $pilih = (int) ($p['pasangan_id'] ?? 0);
                $calon = array_values(array_filter($baris['kandidat'], static fn (array $k): bool => $k['id'] === $pilih && $k['dapat_dipilih']));
                if ($calon === []) {
                    $baris['masalah'][] = 'Pasangan yang dipilih bukan calon yang sah untuk baris ini.';
                    $baris['sidik'] = $this->sidik(['konflik', $c['baris']]);
                    return $baris;
                }
                $sasaranId = $pilih;
                $caraCocok = 'pilihan admin';
            } elseif ($baris['kandidat'] !== [] && $aksi !== 'tambah') {
                $baris['aksi'] = 'pilih_pasangan';
                $baris['peringatan'][] = 'Ada ' . $jenis . ' bernama sama tanpa NIP/NIK yang cocok. Pilih pasangan data lama atau "Tambah baru"; sistem tidak menggabungkan otomatis.';
                $baris['sidik'] = $this->sidik(['pilih_pasangan', $c['baris'], array_column($baris['kandidat'], 'id')]);
                return $baris;
            }
        }

        if ($sasaranId === null) {
            $baris['aksi'] = 'tambah';
            $baris['data'] = $data;
            foreach ($data as $field => $nilai) {
                if ($nilai !== null) {
                    $baris['perubahan'][] = ['field' => $field, 'label' => ProfilData::LABEL[$field], 'lama' => null, 'baru' => $nilai];
                }
            }
            if ($jenis === 'pengurus') {
                $baris['peringatan'][] = 'Pengurus baru diberi jabatan "Pengurus"; ubah di Data Pengurus bila perlu.';
            }
            $baris['sidik'] = $this->sidik(['tambah', $c['baris'], $data, array_column($baris['kandidat'], 'id')]);
            return $baris;
        }

        // --- Ada sasaran: hitung perubahan ------------------------------------
        $master = $jenis === 'guru' ? $this->repository->guru($sasaranId, $kunci) : $this->repository->pengurus($sasaranId, $kunci);
        if ($master === null) {
            $baris['masalah'][] = 'Data lama tidak ditemukan lagi.';
            $baris['sidik'] = $this->sidik(['konflik', $c['baris']]);
            return $baris;
        }
        if ($jenis === 'pengurus' && !empty($master['guru_id'])) {
            $baris['masalah'][] = 'Baris ini cocok dengan identitas pengurus milik seorang guru. Ubah identitasnya lewat impor/formulir Data Guru.';
            $baris['sidik'] = $this->sidik(['konflik', $c['baris']]);
            return $baris;
        }
        $profilLama = $this->repository->profil($jenis, $sasaranId, $kunci);
        $lama = $this->profil->pribadiMaster($jenis, $master, $profilLama);
        $baris['sasaran'] = [
            'id' => $sasaranId, 'nama' => $lama['nama'], 'nip' => $lama['nip'], 'nik' => $lama['nik'], 'cocok_lewat' => $caraCocok,
            'aktif' => (int) $master['is_active'] === 1 && empty($master['archived_at']),
            'akun' => count($this->repository->akunTerhubungBaca($jenis, $sasaranId)),
        ];
        $terap = [];
        $perluPengenal = false;
        foreach ($data as $field => $nilai) {
            if ($nilai === null || (string) $nilai === (string) ($lama[$field] ?? '')) {
                continue;
            }
            $baris['perubahan'][] = ['field' => $field, 'label' => ProfilData::LABEL[$field], 'lama' => $lama[$field], 'baru' => $nilai];
            if ($lama[$field] !== null) {
                $baris['menimpa'][] = $field;
                if (in_array($field, ['nip', 'nik'], true)) {
                    $perluPengenal = true;
                }
            }
            $terap[$field] = $nilai;
        }
        if ($terap === []) {
            $baris['aksi'] = 'tidak_berubah';
            $baris['sidik'] = $this->sidik(['tidak_berubah', $c['baris'], $sasaranId]);
            return $baris;
        }
        $timpaNilai = array_diff($baris['menimpa'], ['nip', 'nik']) !== [];
        $baris['sidik'] = $this->sidik(['perbarui', $c['baris'], $sasaranId, $terap, $lama, (int) ($profilLama['versi'] ?? 0)]);
        $kurang = [];
        if ($timpaNilai && empty($p['timpa'])) {
            $kurang[] = 'Baris ini akan menimpa nilai lama yang sudah terisi (' . implode(', ', array_map(static fn (string $f): string => ProfilData::LABEL[$f], array_diff($baris['menimpa'], ['nip', 'nik']))) . '). Centang "Timpa nilai lama" untuk menyetujui.';
        }
        if ($perluPengenal && empty($p['ubah_pengenal'])) {
            $kurang[] = 'NIP/NIK data lama berbeda dan sudah dipakai' . ($baris['sasaran']['akun'] > 0 ? ' (ada akun terhubung)' : '') . '. Centang "Ganti NIP/NIK" bila memang dikoreksi.';
        }
        if ($kurang !== []) {
            $baris['aksi'] = 'perlu_konfirmasi';
            $baris['peringatan'] = array_merge($baris['peringatan'], $kurang);
            return $baris;
        }
        $baris['aksi'] = 'perbarui';
        $baris['data'] = $terap;

        return $baris;
    }

    /**
     * Peringatan/konflik lintas jenis: data ganda guru–pengurus (keputusan
     * 30 Oktober 2026: peringatan saja) dan identitas pengurus milik guru (PI-10).
     */
    private function periksaSilang(string $jenis, array $data, array &$baris): void
    {
        $lain = $jenis === 'guru' ? 'pengurus' : 'guru';
        $temuan = [];
        if ($data['nip'] !== null) {
            foreach ($this->repository->cariNip($lain, (string) $data['nip']) as $r) {
                $temuan[(int) $r['id']] = $r;
            }
        }
        if ($data['nik'] !== null) {
            foreach ($this->repository->cariNik($lain, (string) $data['nik']) as $r) {
                $temuan[(int) $r['id']] = $r;
            }
        }
        foreach ($temuan as $id => $r) {
            if ($jenis === 'pengurus' && $this->repository->identitasPengurusGuru($id) !== null) {
                $baris['masalah'][] = 'NIP/NIK baris ini milik guru "' . $r['nama_guru'] . '" yang sudah merangkap pengurus. Ubah identitasnya lewat Data Guru.';
                continue;
            }
            $baris['peringatan'][] = 'NIP/NIK sama dengan data ' . $lain . ' "' . ($r['nama_guru'] ?? $r['nama']) . '" (ID ' . $id . '). Bila orang yang sama, pertimbangkan satu identitas melalui penugasan; tidak digabung otomatis.';
        }
    }

    /** Dua baris yang menunjuk orang yang sama → keduanya konflik. */
    private function tandaiBentrokSasaran(array &$hasil): void
    {
        $peta = [];
        foreach ($hasil as $i => $b) {
            if ($b['sasaran'] !== null && in_array($b['aksi'], ['perbarui', 'tidak_berubah', 'perlu_konfirmasi'], true)) {
                $peta[$b['sasaran']['id']][] = $i;
            }
        }
        foreach ($peta as $id => $indeks) {
            if (count($indeks) < 2) {
                continue;
            }
            foreach ($indeks as $i) {
                $lain = array_map(static fn (int $j): int => $hasil[$j]['baris'], array_diff($indeks, [$i]));
                $hasil[$i]['aksi'] = 'konflik';
                $hasil[$i]['masalah'][] = 'Baris ini dan baris ' . implode(', ', $lain) . ' menunjuk data lama yang sama (ID ' . $id . ').';
            }
        }
    }

    /**
     * @param array<string, mixed> $baris
     * @return array<string, mixed>
     */
    private function terapkan(string $jenis, array $baris, int $aktor): array
    {
        $data = $baris['data'];
        try {
            if ($baris['aksi'] === 'tambah') {
                $nama = (string) $data['nama'];
                $id = $jenis === 'guru'
                    ? $this->repository->buatGuru($nama, $data['no_hp'] ?? null, $data['nip'] ?? null)
                    : $this->repository->buatPengurus($nama, $data['no_hp'] ?? null, $data['nip'] ?? null, 'Pengurus');
                $tambahan = array_filter(array_intersect_key($data, array_flip(array_merge(ProfilData::TAMBAHAN, ['nik']))), static fn ($v): bool => $v !== null);
                $hasil = $this->profil->terapkanIdentitas($jenis, $id, $tambahan, $aktor);
                $berubah = array_keys(array_filter($data, static fn ($v): bool => $v !== null));
                $aksi = 'ditambah';
            } else {
                $id = (int) $baris['sasaran']['id'];
                $hasil = $this->profil->terapkanIdentitas($jenis, $id, $data, $aktor);
                $berubah = $hasil['berubah'];
                $aksi = 'diperbarui';
            }
        } catch (ProfilException $exception) {
            throw new ProfilException('Baris ' . $baris['baris'] . ': ' . $exception->getMessage() . ' Tidak ada data yang disimpan; periksa ulang berkas.', $exception->status(), $exception->errors());
        } catch (Throwable $exception) {
            error_log('Impor personel gagal: ' . $exception->getMessage());
            throw new ProfilException('Baris ' . $baris['baris'] . ' gagal disimpan (mungkin NIP/NIK baru saja dipakai data lain). Tidak ada data yang disimpan; periksa ulang berkas.', 409);
        }
        if (!$this->audit->log('profil.impor_baris', $jenis, $id, null, [
            'jenis' => $jenis, 'pemilik_id' => $id, 'aksi' => $aksi, 'baris' => $baris['baris'], 'field_berubah' => $berubah, 'versi' => $hasil['versi'],
        ], $aktor)) {
            throw new ProfilException('Impor dibatalkan karena audit tidak dapat disimpan. Tidak ada data yang berubah.', 503);
        }
        $baris['aksi'] = $aksi;
        $baris['sasaran'] ??= ['id' => $id, 'nama' => $data['nama'] ?? '', 'nip' => $data['nip'] ?? null, 'nik' => $data['nik'] ?? null, 'cocok_lewat' => null, 'aktif' => true, 'akun' => 0];
        $baris['sasaran']['id'] = $id;

        return $baris;
    }

    /** @return array<string, mixed> */
    private function ringkas(array $hasil): array
    {
        $jumlah = ['tambah' => 0, 'perbarui' => 0, 'tidak_berubah' => 0, 'perlu_konfirmasi' => 0, 'pilih_pasangan' => 0, 'konflik' => 0, 'ditambah' => 0, 'diperbarui' => 0];
        foreach ($hasil as &$b) {
            $jumlah[$b['aksi']] = ($jumlah[$b['aksi']] ?? 0) + 1;
            unset($b['data']);
            $b['masalah'] = array_values(array_unique($b['masalah']));
            $b['peringatan'] = array_values(array_unique($b['peringatan']));
        }
        unset($b);

        return ['baris' => count($hasil), 'jumlah' => $jumlah, 'siap' => $jumlah['tambah'] + $jumlah['perbarui'], 'hasil' => $hasil];
    }

    /** @return array<string, mixed> */
    private function pilihan(array $pilihan, int $baris): array
    {
        $p = $pilihan[$baris] ?? $pilihan[(string) $baris] ?? [];

        return is_array($p) ? $p : [];
    }

    private function sidik(array $isi): string
    {
        return hash('sha256', (string) json_encode($isi, JSON_UNESCAPED_UNICODE));
    }

    private function jenis(string $jenis): void
    {
        if (!in_array($jenis, ['guru', 'pengurus'], true)) {
            throw new ProfilException('Jenis impor tidak dikenal.', 422);
        }
    }
}
