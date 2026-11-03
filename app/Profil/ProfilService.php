<?php

declare(strict_types=1);

namespace App\Profil;

use App\Audit\AuditLogger;
use App\Auth\Capabilities;
use App\Penugasan\PenugasanJenis;

/**
 * Profil pengguna (PRD-PROFIL-IMPORT Fase 1): satu pintu baca/ubah profil
 * diri, foto, dan ganti password untuk web dan REST API, serta penerapan
 * identitas dari Data Guru/Data Pengurus dan impor Excel.
 *
 * Aturan sumber data (PRD §5, PI-7):
 *   - Jangkar profil = guru yang terhubung; bila tidak ada, pengurus biasa;
 *     bila tidak ada, wali; bila tidak ada, akun itu sendiri. Identitas
 *     pengurus milik guru (PK-1) memakai jangkar guru — tidak ada salinan kedua.
 *   - Nama dan HP tetap tinggal di tabel master dan `users`; setiap perubahan
 *     yang berhasil menyamakan seluruh identitas terhubung dan akun dalam SATU
 *     transaksi bersama audit. Audit gagal → seluruh perubahan dibatalkan.
 *   - Status, peran, penugasan, kamar, mapel, dan kelas hanya DIBACA dari
 *     penugasan aktif (`Capabilities::featureCapabilities`) dan role akun;
 *     tidak pernah dari masukan klien, teks Excel, atau kolom `guru.status`.
 */
final class ProfilService
{
    /** Kunci masukan edit diri yang diterima selain isian pribadi. */
    private const KUNCI_KONTROL = ['versi'];

    private const LABEL_PERAN = ['admin' => 'Admin', 'guru' => 'Guru', 'pengurus' => 'Pengurus', 'orang_tua' => 'Orang Tua'];

    public function __construct(
        private ProfilRepository $repository,
        private Capabilities $capabilities,
        private AuditLogger $audit,
        private FotoProfilStorage $foto
    ) {
    }

    public function tersedia(): bool
    {
        return $this->repository->tersedia();
    }

    // =====================================================================
    // Baca
    // =====================================================================

    /**
     * Profil lengkap pemilik akun. Hanya data akun ini sendiri.
     *
     * @return array<string, mixed>
     */
    public function baca(int $userId): array
    {
        $this->wajibTersedia();
        $akun = $this->repository->akun($userId);
        if ($akun === null || $akun['is_active'] !== 1) {
            throw new ProfilException('Sesi atau akun tidak valid.', 401);
        }
        [$jenis, $pemilikId] = $this->jangkar($akun);
        $profil = $this->repository->profil($jenis, $pemilikId);
        $pribadi = $this->pribadi($akun, $jenis, $pemilikId, $profil);
        [$status, $penugasan, $binaan, $mengajar] = $this->penugasan($akun);
        $foto = $profil !== null && $profil['foto'] !== null && $this->foto->path((string) $profil['foto']) !== null;

        return [
            'akun' => [
                'id' => $akun['id'],
                'username' => (string) $akun['username'],
                'peran' => array_values(array_intersect(array_keys(self::LABEL_PERAN), $akun['roles'])),
            ],
            'sumber' => $jenis,
            'pribadi' => $pribadi,
            'foto' => [
                'ada' => $foto,
                'versi' => $profil === null ? 0 : (int) $profil['foto_versi'],
                'url' => $foto ? '/profile/foto' : null,
                'inisial' => $this->inisial((string) $pribadi['nama']),
            ],
            'versi' => $profil === null ? 0 : (int) $profil['versi'],
            'status' => $status,
            'penugasan' => $penugasan,
            'binaan' => $binaan,
            'mengajar' => $mengajar,
            'dapat_diubah' => ProfilData::DIRI,
            'wajib_ganti_password' => $akun['force_password_change'] === 1,
        ];
    }

    /**
     * Identitas pribadi satu guru/pengurus untuk formulir admin.
     *
     * @return array<string, mixed>
     */
    public function profilMaster(string $jenis, int $id): array
    {
        if (!$this->tersedia()) {
            return [];
        }
        $profil = $this->repository->profil($jenis, $id);

        return $profil ?? [];
    }

    // =====================================================================
    // Ubah diri
    // =====================================================================

    /**
     * Mengubah identitas pribadi akun sendiri. Hanya kunci yang dikirim yang
     * berubah; kunci di luar daftar yang disetujui DITOLAK (username, role,
     * penugasan, kamar, mapel, kelas, NIP/NIK, akun lain, …).
     *
     * @param array<string, mixed> $user pengguna terautentikasi (hanya `id` dipakai)
     * @param array<string, mixed> $input
     * @return array<string, mixed> profil sesudah
     */
    public function ubahDiri(array $user, array $input): array
    {
        $this->wajibTersedia();
        $userId = (int) $user['id'];
        $terlarang = array_values(array_diff(array_keys($input), ProfilData::DIRI, self::KUNCI_KONTROL));
        if ($terlarang !== []) {
            throw new ProfilException('Isian berikut tidak dapat diubah melalui profil: ' . implode(', ', $terlarang) . '. Username, peran, dan penugasan hanya diatur admin.',
                422, array_fill_keys($terlarang, 'Tidak dapat diubah melalui profil.'));
        }
        $fields = array_values(array_intersect(ProfilData::DIRI, array_keys($input)));
        if ($fields === []) {
            throw new ProfilException('Tidak ada isian profil yang dikirim.', 422);
        }

        $this->repository->transaction(function () use ($userId, $input, $fields): void {
            $akun = $this->repository->akun($userId, true);
            if ($akun === null || $akun['is_active'] !== 1) {
                throw new ProfilException('Sesi atau akun tidak valid.', 401);
            }
            [$jenis, $pemilikId] = $this->jangkar($akun);
            $tautan = $this->kunciTautan($akun);
            $profil = $this->repository->profil($jenis, $pemilikId, true);
            if (array_key_exists('versi', $input) && (int) $input['versi'] !== ($profil === null ? 0 : (int) $profil['versi'])) {
                throw new ProfilException('Profil sudah diubah di tempat lain. Muat ulang lalu ulangi perubahan Anda.', 409);
            }
            $adaGuru = $tautan['guru'] !== null;
            [$data, $errors] = ProfilData::normalisasi($input, $fields, ['batas_hp' => $adaGuru ? 15 : 16, 'latin1_nama' => $adaGuru]);
            if ($errors !== []) {
                throw new ProfilException(implode(' ', $errors), 422, $errors);
            }
            if (isset($data['email']) && $this->repository->emailDipakaiAkunLain((string) $data['email'], [$userId])) {
                throw new ProfilException('Email sudah dipakai akun lain.', 409, ['email' => 'Email sudah dipakai akun lain.']);
            }

            $sekarang = $this->pribadi($akun, $jenis, $pemilikId, $profil);
            $berubah = [];
            foreach ($data as $field => $nilai) {
                if ((string) ($sekarang[$field] ?? '') !== (string) ($nilai ?? '')) {
                    $berubah[] = $field;
                }
            }
            if ($berubah === []) {
                return;
            }
            $nama = array_key_exists('nama', $data) ? (string) $data['nama'] : (string) $sekarang['nama'];
            $hp = array_key_exists('no_hp', $data) ? $data['no_hp'] : $sekarang['no_hp'];
            $tambahan = array_intersect_key($data, array_flip(ProfilData::TAMBAHAN));
            $alamatBerubah = array_intersect($berubah, ProfilData::ALAMAT) !== [];

            $sinkronPengurus = 0;
            if ($tautan['guru'] !== null) {
                $this->repository->ubahGuru($tautan['guru'], $nama, $hp);
                $sinkronPengurus = $this->repository->sinkronIdentitasGuru($tautan['guru']);
            }
            if ($tautan['pengurus'] !== null) {
                $this->repository->ubahPengurus($tautan['pengurus'], $nama, $hp);
            }
            if ($tautan['wali'] !== null) {
                $alamat = $alamatBerubah ? ProfilData::alamatLengkap(array_merge($sekarang, $data)) : null;
                $this->repository->ubahWali($tautan['wali'], $nama, $hp, $alamat, $alamatBerubah);
            }
            $ubahEmail = in_array('email', $berubah, true);
            $this->repository->ubahAkun($userId, $nama, $hp, $ubahEmail, $data['email'] ?? null);
            $sesudah = $tambahan === [] && $profil !== null
                ? $profil
                : $this->repository->tulisProfil($jenis, $pemilikId, $tambahan, $userId);

            $this->wajibAudit('profil.ubah_diri', 'user', $userId, [
                'sumber' => $jenis, 'pemilik_id' => $pemilikId, 'field_berubah' => $berubah,
                'versi' => (int) ($sesudah['versi'] ?? 0), 'identitas_pengurus_disinkronkan' => $sinkronPengurus,
            ], $userId);
        });

        return $this->baca($userId);
    }

    // =====================================================================
    // Foto
    // =====================================================================

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed>|null $upload satu entri `$_FILES`
     * @return array<string, mixed>
     */
    public function unggahFoto(array $user, ?array $upload): array
    {
        $this->wajibTersedia();
        $userId = (int) $user['id'];
        $nama = $this->foto->simpanUnggahan($upload);
        $lama = null;
        try {
            $lama = $this->repository->transaction(function () use ($userId, $nama): ?string {
                $akun = $this->repository->akun($userId, true);
                if ($akun === null || $akun['is_active'] !== 1) {
                    throw new ProfilException('Sesi atau akun tidak valid.', 401);
                }
                [$jenis, $pemilikId] = $this->jangkar($akun);
                $profil = $this->repository->profil($jenis, $pemilikId, true);
                $sesudah = $this->repository->tulisProfil($jenis, $pemilikId, ['foto' => $nama], $userId);
                $this->wajibAudit('profil.foto_diganti', 'user', $userId, [
                    'sumber' => $jenis, 'pemilik_id' => $pemilikId, 'foto_versi' => (int) $sesudah['foto_versi'], 'versi' => (int) $sesudah['versi'],
                ], $userId);

                return $profil['foto'] ?? null;
            });
        } catch (\Throwable $exception) {
            $this->foto->hapus($nama);
            throw $exception;
        }
        if ($lama !== null && $lama !== $nama) {
            $this->foto->hapus((string) $lama);
        }

        return $this->baca($userId);
    }

    /** @return array<string, mixed> */
    public function hapusFoto(array $user): array
    {
        $this->wajibTersedia();
        $userId = (int) $user['id'];
        $lama = $this->repository->transaction(function () use ($userId): ?string {
            $akun = $this->repository->akun($userId, true);
            if ($akun === null || $akun['is_active'] !== 1) {
                throw new ProfilException('Sesi atau akun tidak valid.', 401);
            }
            [$jenis, $pemilikId] = $this->jangkar($akun);
            $profil = $this->repository->profil($jenis, $pemilikId, true);
            if ($profil === null || $profil['foto'] === null) {
                return null;
            }
            $sesudah = $this->repository->tulisProfil($jenis, $pemilikId, ['foto' => null], $userId);
            $this->wajibAudit('profil.foto_dihapus', 'user', $userId, [
                'sumber' => $jenis, 'pemilik_id' => $pemilikId, 'foto_versi' => (int) $sesudah['foto_versi'], 'versi' => (int) $sesudah['versi'],
            ], $userId);

            return (string) $profil['foto'];
        });
        if ($lama !== null) {
            $this->foto->hapus($lama);
        }

        return $this->baca($userId);
    }

    /**
     * Foto akun `targetUserId` untuk `$viewer`: pemilik sendiri atau admin aktif
     * (tanpa wajib ganti password). Selain itu — termasuk akun yang tidak ada —
     * 404 agar keberadaan foto akun lain tidak terungkap.
     *
     * @param array<string, mixed> $viewer
     * @return array{path:string, mime:string, versi:int}
     */
    public function foto(array $viewer, int $targetUserId): array
    {
        $this->wajibTersedia();
        $viewerId = (int) $viewer['id'];
        if ($viewerId !== $targetUserId) {
            $penonton = $this->repository->akun($viewerId);
            if ($penonton === null || $penonton['is_active'] !== 1 || $penonton['force_password_change'] === 1 || !in_array('admin', $penonton['roles'], true)) {
                throw new ProfilException('Foto tidak ditemukan.', 404);
            }
        }
        $akun = $this->repository->akun($targetUserId);
        if ($akun === null) {
            throw new ProfilException('Foto tidak ditemukan.', 404);
        }
        [$jenis, $pemilikId] = $this->jangkar($akun);

        return $this->fotoPemilik($jenis, $pemilikId);
    }

    /**
     * Foto satu pemilik profil — HANYA untuk pemanggil yang sudah memeriksa
     * hak admin (halaman admin) atau `foto()` di atas.
     *
     * @return array{path:string, mime:string, versi:int}
     */
    public function fotoPemilik(string $jenis, int $pemilikId): array
    {
        $this->wajibTersedia();
        $profil = $this->repository->profil($jenis, $pemilikId);
        $path = $profil === null ? null : $this->foto->path($profil['foto'] === null ? null : (string) $profil['foto']);
        if ($path === null) {
            throw new ProfilException('Foto tidak ditemukan.', 404);
        }

        return ['path' => $path, 'mime' => $this->foto->mime((string) $profil['foto']), 'versi' => (int) $profil['foto_versi']];
    }

    // =====================================================================
    // Password
    // =====================================================================

    /**
     * Ganti password sendiri (web dan aplikasi). Password saat ini diverifikasi
     * di server; aturan kekuatan sama dengan website. Sesudah berhasil (PI-2),
     * sesi website lain berhenti lewat `sesi_versi`, token aplikasi lain dicabut,
     * dan perangkat push lain dicabut. Token terbatas yang dipakai (PI-1)
     * menjadi token penuh. Password/token tidak pernah masuk audit atau respons.
     *
     * @param array<string, mixed> $input password_saat_ini, password_baru, konfirmasi_password
     * @param array{token_id?:?int, device_id?:?string, kanal:string} $konteks
     * @return array{sesi_versi:int, token_lain_dicabut:int, perangkat_dicabut:int, wajib_ganti_password:bool}
     */
    public function gantiPassword(int $userId, array $input, array $konteks): array
    {
        $this->wajibTersedia();
        $saatIni = is_string($input['password_saat_ini'] ?? null) ? $input['password_saat_ini'] : '';
        $baru = is_string($input['password_baru'] ?? null) ? $input['password_baru'] : '';
        $ulang = is_string($input['konfirmasi_password'] ?? null) ? $input['konfirmasi_password'] : '';
        $kanal = in_array($konteks['kanal'] ?? '', ['web', 'api'], true) ? $konteks['kanal'] : 'api';

        return $this->repository->transaction(function () use ($userId, $saatIni, $baru, $ulang, $konteks, $kanal): array {
            $akun = $this->repository->akun($userId, true);
            if ($akun === null || $akun['is_active'] !== 1) {
                throw new ProfilException('Sesi atau akun tidak valid.', 401);
            }
            if ($saatIni === '' || !password_verify($saatIni, (string) $akun['password'])) {
                // Percobaan gagal dicatat di luar transaksi ini (yang dibatalkan).
                throw new ProfilException('Password saat ini tidak benar.', 422, ['password_saat_ini' => 'Password saat ini tidak benar.']);
            }
            if (strlen($baru) < 12 || !preg_match('/[A-Z]/', $baru) || !preg_match('/[a-z]/', $baru) || !preg_match('/\d/', $baru)) {
                throw new ProfilException('Password baru minimal 12 karakter dan memuat huruf besar, huruf kecil, serta angka.', 422,
                    ['password_baru' => 'Minimal 12 karakter dengan huruf besar, huruf kecil, dan angka.']);
            }
            if (strlen($baru) > 72 || str_contains($baru, "\0")) {
                throw new ProfilException('Password baru maksimal 72 byte dan tidak boleh memuat karakter NUL.', 422,
                    ['password_baru' => 'Maksimal 72 byte dan tanpa karakter NUL.']);
            }
            if ($baru !== $ulang) {
                throw new ProfilException('Ulangan password baru tidak sama.', 422, ['konfirmasi_password' => 'Ulangan password baru tidak sama.']);
            }
            $tokenId = isset($konteks['token_id']) ? (int) $konteks['token_id'] : null;
            $sesiVersi = $this->repository->gantiPassword($userId, password_hash($baru, PASSWORD_BCRYPT));
            $tokenDicabut = $this->repository->cabutTokenLain($userId, $tokenId);
            if ($tokenId !== null) {
                $this->repository->jadikanTokenPenuh($tokenId, (int) (app_config('api.token_ttl_days') ?: 30));
            }
            $deviceId = $kanal === 'api' && is_string($konteks['device_id'] ?? null) ? substr(trim($konteks['device_id']), 0, 100) : null;
            $perangkat = $this->repository->cabutPerangkatLain($userId, $deviceId);
            $this->wajibAudit('profil.password_diganti', 'user', $userId, [
                'kanal' => $kanal, 'wajib_ganti_dilepas' => $akun['force_password_change'] === 1,
                'token_lain_dicabut' => $tokenDicabut, 'perangkat_dicabut' => $perangkat, 'sesi_versi' => $sesiVersi,
            ], $userId);

            return ['sesi_versi' => $sesiVersi, 'token_lain_dicabut' => $tokenDicabut, 'perangkat_dicabut' => $perangkat, 'wajib_ganti_password' => false];
        });
    }

    /** Jejak percobaan ganti password dengan password saat ini yang salah. */
    public function catatPasswordGagal(int $userId, string $kanal): void
    {
        $this->audit->log('profil.password_gagal', 'user', $userId, null, ['kanal' => $kanal], $userId);
    }

    // =====================================================================
    // Penerapan identitas dari admin/impor (transaksi dibuka pemanggil)
    // =====================================================================

    /**
     * Menerapkan identitas guru/pengurus dari formulir admin atau impor.
     * DIPANGGIL DI DALAM TRANSAKSI pemanggil. `$data` sudah dinormalisasi
     * `ProfilData`; hanya kunci yang ada yang diterapkan (impor menyaring lebih
     * dulu isian yang menimpa nilai lama tanpa konfirmasi).
     *
     * @param array<string, ?string> $data
     * @return array{berubah:array<int,string>, versi:int, akun_disinkronkan:int}
     */
    public function terapkanIdentitas(string $jenis, int $id, array $data, ?int $aktor): array
    {
        $this->wajibTersedia();
        if (!in_array($jenis, ['guru', 'pengurus'], true)) {
            throw new ProfilException('Jenis identitas tidak dikenal.', 422);
        }
        $master = $jenis === 'guru' ? $this->repository->guru($id, true) : $this->repository->pengurus($id, true);
        if ($master === null) {
            throw new ProfilException(ucfirst($jenis) . ' tidak ditemukan.', 404);
        }
        if ($jenis === 'pengurus' && !empty($master['guru_id'])) {
            throw new ProfilException('Data ini adalah identitas pengurus milik seorang guru. Ubah identitasnya dari Data Guru.', 409);
        }
        $profil = $this->repository->profil($jenis, $id, true);
        $sekarang = $this->pribadiMaster($jenis, $master, $profil);

        if (isset($data['nik'])) {
            $pemilik = $this->repository->pemilikNik($jenis, (string) $data['nik']);
            if ($pemilik !== null && $pemilik !== $id) {
                throw new ProfilException('NIK sudah dipakai ' . $jenis . ' lain.', 409, ['nik' => 'NIK sudah dipakai ' . $jenis . ' lain.']);
            }
        }
        $berubah = [];
        foreach ($data as $field => $nilai) {
            if ((string) ($sekarang[$field] ?? '') !== (string) ($nilai ?? '')) {
                $berubah[] = $field;
            }
        }
        if ($berubah === []) {
            return ['berubah' => [], 'versi' => $profil === null ? 0 : (int) $profil['versi'], 'akun_disinkronkan' => 0];
        }
        $akunTerhubung = $this->repository->akunTerhubung($jenis, $id);
        if (in_array('email', $berubah, true) && isset($data['email'])
            && $this->repository->emailDipakaiAkunLain((string) $data['email'], array_column($akunTerhubung, 'id'))) {
            throw new ProfilException('Email sudah dipakai akun lain.', 409, ['email' => 'Email sudah dipakai akun lain.']);
        }

        $nama = array_key_exists('nama', $data) ? (string) $data['nama'] : (string) $sekarang['nama'];
        $hp = array_key_exists('no_hp', $data) ? $data['no_hp'] : $sekarang['no_hp'];
        $ubahNip = in_array('nip', $berubah, true);
        if ($jenis === 'guru') {
            $this->repository->ubahGuru($id, $nama, $hp, $data['nip'] ?? null, $ubahNip);
            $sinkron = $this->repository->sinkronIdentitasGuru($id);
            if ($sinkron > 0) {
                $identitas = $this->repository->identitasPengurusGuru($id);
                $this->wajibAudit('master.identitas_guru.sinkron', 'pengurus', (int) ($identitas['id'] ?? 0), [
                    'guru_id' => $id, 'is_active' => (int) ($identitas['is_active'] ?? 0), 'diarsipkan' => !empty($identitas['archived_at']),
                ], $aktor);
            }
        } else {
            $this->repository->ubahPengurus($id, $nama, $hp, $data['nip'] ?? null, $ubahNip);
        }
        $ubahEmail = in_array('email', $berubah, true);
        $akunBerubah = 0;
        foreach ($akunTerhubung as $akun) {
            $akunBerubah += $this->repository->ubahAkun((int) $akun['id'], $nama, $hp, $ubahEmail, $data['email'] ?? null);
        }
        $tambahan = array_intersect_key($data, array_flip(array_merge(ProfilData::TAMBAHAN, ['nik'])));
        $tambahanBerubah = array_intersect_key($tambahan, array_flip($berubah));
        $sesudah = $tambahanBerubah === [] ? $profil : $this->repository->tulisProfil($jenis, $id, $tambahanBerubah, $aktor);

        return ['berubah' => $berubah, 'versi' => $sesudah === null ? 0 : (int) $sesudah['versi'], 'akun_disinkronkan' => $akunBerubah];
    }

    /**
     * Nama dan HP akun orang tua mengikuti identitas wali yang baru diubah
     * admin (Data Wali). DIPANGGIL DI DALAM TRANSAKSI pemanggil.
     */
    public function sinkronAkunWali(int $waliId, ?int $aktor): int
    {
        if (!$this->tersedia()) {
            return 0;
        }
        $wali = $this->repository->wali($waliId, true);
        if ($wali === null) {
            return 0;
        }
        $berubah = 0;
        foreach ($this->repository->akunTerhubung('wali', $waliId) as $akun) {
            if ((string) $akun['name'] !== (string) $wali['nama'] || (string) ($akun['phone'] ?? '') !== (string) ($wali['no_hp'] ?? '')) {
                $berubah += $this->repository->ubahAkun((int) $akun['id'], (string) $wali['nama'], $wali['no_hp'] === null ? null : (string) $wali['no_hp']);
            }
        }
        if ($berubah > 0) {
            $this->wajibAudit('profil.ubah_admin', 'wali', $waliId, ['jenis' => 'wali', 'pemilik_id' => $waliId, 'field_berubah' => ['nama', 'no_hp'], 'akun_disinkronkan' => $berubah], $aktor);
        }

        return $berubah;
    }

    /**
     * Nilai identitas guru/pengurus saat ini dalam bentuk isian `ProfilData`.
     *
     * @param array<string, mixed> $master
     * @param array<string, mixed>|null $profil
     * @return array<string, ?string>
     */
    public function pribadiMaster(string $jenis, array $master, ?array $profil): array
    {
        $hasil = [
            'nama' => (string) ($jenis === 'guru' ? $master['nama_guru'] : $master['nama']),
            'nip' => $this->kosong($jenis === 'guru' ? ($master['nip'] ?? null) : ($master['nomor_identitas'] ?? null)),
            'no_hp' => $this->kosong($master['no_hp'] ?? null),
            'nik' => $this->kosong($profil['nik'] ?? null),
        ];
        foreach (ProfilData::TAMBAHAN as $field) {
            $hasil[$field] = $this->kosong($profil[$field] ?? null);
        }
        if ($hasil['email'] === null) {
            foreach ($this->repository->akunTerhubungBaca($jenis, (int) $master['id']) as $akun) {
                if (!empty($akun['email'])) {
                    $hasil['email'] = (string) $akun['email'];
                    break;
                }
            }
        }

        return $hasil;
    }

    // =====================================================================
    // Bagian dalam
    // =====================================================================

    /**
     * Jangkar profil akun (PI-7).
     *
     * @param array<string, mixed> $akun
     * @return array{0:string, 1:int}
     */
    public function jangkar(array $akun): array
    {
        if ($akun['guru_id'] !== null && $akun['nama_guru'] !== null) {
            return ['guru', (int) $akun['guru_id']];
        }
        if ($akun['pengurus_id'] !== null && $akun['pengurus_nama'] !== null) {
            return $akun['pengurus_guru_id'] !== null ? ['guru', (int) $akun['pengurus_guru_id']] : ['pengurus', (int) $akun['pengurus_id']];
        }
        if ($akun['wali_id'] !== null && $akun['wali_nama'] !== null) {
            return ['wali', (int) $akun['wali_id']];
        }

        return ['akun', (int) $akun['id']];
    }

    /**
     * Mengunci seluruh identitas master akun dan mengembalikan ID yang akan
     * disamakan. Identitas pengurus milik guru mengikuti guru (tidak diubah
     * langsung); pengurus biasa yang terhubung ikut diubah.
     *
     * @return array{guru:?int, pengurus:?int, wali:?int}
     */
    private function kunciTautan(array $akun): array
    {
        $guru = null;
        if ($akun['guru_id'] !== null && $this->repository->guru((int) $akun['guru_id'], true) !== null) {
            $guru = (int) $akun['guru_id'];
        }
        $pengurus = null;
        if ($akun['pengurus_id'] !== null && ($baris = $this->repository->pengurus((int) $akun['pengurus_id'], true)) !== null) {
            if (empty($baris['guru_id'])) {
                $pengurus = (int) $akun['pengurus_id'];
            } elseif ($guru === null && $this->repository->guru((int) $baris['guru_id'], true) !== null) {
                $guru = (int) $baris['guru_id'];
            }
        }
        $wali = null;
        if ($akun['wali_id'] !== null && $this->repository->wali((int) $akun['wali_id'], true) !== null) {
            $wali = (int) $akun['wali_id'];
        }

        return ['guru' => $guru, 'pengurus' => $pengurus, 'wali' => $wali];
    }

    /**
     * Isian pribadi yang tampil untuk akun.
     *
     * @param array<string, mixed> $akun
     * @param array<string, mixed>|null $profil
     * @return array<string, ?string>
     */
    private function pribadi(array $akun, string $jenis, int $pemilikId, ?array $profil): array
    {
        [$nama, $hp, $nip] = match ($jenis) {
            'guru' => $akun['guru_id'] === $pemilikId
                ? [$akun['nama_guru'], $akun['guru_no_hp'], $akun['nip']]
                : (function () use ($pemilikId): array {
                    $g = $this->repository->guru($pemilikId) ?? [];
                    return [$g['nama_guru'] ?? '', $g['no_hp'] ?? null, $g['nip'] ?? null];
                })(),
            'pengurus' => [$akun['pengurus_nama'], $akun['pengurus_no_hp'], $akun['pengurus_nomor']],
            'wali' => [$akun['wali_nama'], $akun['wali_no_hp'], null],
            default => [$akun['name'], $akun['phone'], null],
        };
        $hasil = [
            'nama' => (string) $nama,
            'nip' => $this->kosong($nip),
            'nik' => $this->kosong($profil['nik'] ?? null),
            'no_hp' => $this->kosong($hp) ?? $this->kosong($akun['phone']),
        ];
        foreach (ProfilData::TAMBAHAN as $field) {
            $hasil[$field] = $this->kosong($profil[$field] ?? null);
        }
        $hasil['email'] ??= $this->kosong($akun['email']);
        if ($jenis === 'wali' && $profil === null) {
            $hasil['alamat_rumah'] = $this->kosong($akun['wali_alamat']);
        }

        return $hasil;
    }

    /**
     * Status, penugasan aktif, target binaan, dan pasangan mapel–kelas.
     *
     * @param array<string, mixed> $akun
     * @return array{0:array<int,array<string,string>>, 1:array<int,array<string,mixed>>, 2:array<int,array<string,mixed>>, 3:array<int,array<string,mixed>>}
     */
    private function penugasan(array $akun): array
    {
        $userId = (int) $akun['id'];
        $status = [];
        $roles = $akun['roles'];
        if (in_array('admin', $roles, true)) {
            $status[] = ['kode' => 'admin', 'label' => 'Admin', 'jenis' => 'peran'];
        }
        if (in_array('guru', $roles, true) && $akun['guru_id'] !== null && (int) $akun['guru_aktif'] === 1 && $akun['guru_arsip'] === null) {
            $status[] = ['kode' => 'guru', 'label' => 'Guru', 'jenis' => 'peran'];
        }
        $pengurusSah = in_array('pengurus', $roles, true) ? $this->capabilities->linkedPengurusId($userId) : null;
        if ($pengurusSah !== null && $akun['pengurus_guru_id'] === null) {
            $status[] = ['kode' => 'pengurus', 'label' => 'Pengurus', 'jenis' => 'peran'];
        }
        if (in_array('orang_tua', $roles, true) && $this->capabilities->linkedWaliId($userId) !== null) {
            $status[] = ['kode' => 'orang_tua', 'label' => 'Orang Tua', 'jenis' => 'peran'];
        }

        $this->capabilities->forget($userId);
        $baris = [];
        try {
            foreach ($this->capabilities->featureCapabilities(['id' => $userId, 'roles' => [], 'guru_id' => $akun['guru_id']]) as $entri) {
                foreach ($entri['cakupan'] as $cakupan) {
                    $baris[(string) $cakupan['jenis']][(int) $cakupan['id']] = $cakupan;
                }
            }
        } catch (\Throwable $exception) {
            error_log('Penugasan profil gagal dihitung: ' . $exception->getMessage());
        }
        $ids = ['kamar' => [], 'kelas' => [], 'mata_pelajaran' => [], 'tahun_ajaran' => []];
        foreach ($baris as $daftar) {
            foreach ($daftar as $c) {
                $ids['kamar'][] = (int) $c['kamar_id'];
                $ids['kelas'][] = (int) $c['kelas_id'];
                $ids['mata_pelajaran'][] = (int) $c['mata_pelajaran_id'];
                $ids['tahun_ajaran'][] = (int) $c['tahun_ajaran_id'];
            }
        }
        $nama = [];
        foreach ($ids as $tabel => $daftar) {
            $nama[$tabel] = $this->repository->nama($tabel, $daftar);
        }

        $penugasan = [];
        $binaan = [];
        $mengajar = [];
        foreach (PenugasanJenis::semua() as $kode => $definisi) {
            if (!isset($baris[$kode])) {
                continue;
            }
            $status[] = ['kode' => $kode, 'label' => (string) $definisi['label'], 'jenis' => 'penugasan'];
            $rincian = [];
            foreach ($baris[$kode] as $c) {
                $target = null;
                if ($c['kamar_id'] !== null) {
                    $target = ['tipe' => 'Kamar', 'id' => (int) $c['kamar_id'], 'nama' => $nama['kamar'][(int) $c['kamar_id']] ?? ''];
                } elseif ($definisi['cakupan'] === PenugasanJenis::CAKUPAN_TARGET && $c['kelas_id'] !== null) {
                    $target = ['tipe' => 'Kelas', 'id' => (int) $c['kelas_id'], 'nama' => $nama['kelas'][(int) $c['kelas_id']] ?? ''];
                }
                $mapel = $definisi['cakupan'] === PenugasanJenis::CAKUPAN_MAPEL_KELAS ? [
                    'mata_pelajaran' => ['id' => (int) $c['mata_pelajaran_id'], 'nama' => $nama['mata_pelajaran'][(int) $c['mata_pelajaran_id']] ?? ''],
                    'kelas' => ['id' => (int) $c['kelas_id'], 'nama' => $nama['kelas'][(int) $c['kelas_id']] ?? ''],
                ] : null;
                $rincian[] = [
                    'id' => (int) $c['id'],
                    'tahun_ajaran' => ['id' => (int) $c['tahun_ajaran_id'], 'nama' => $nama['tahun_ajaran'][(int) $c['tahun_ajaran_id']] ?? ''],
                    'target' => $target,
                    'mata_pelajaran' => $mapel['mata_pelajaran'] ?? null,
                    'kelas' => $mapel['kelas'] ?? null,
                    'jenjang' => $definisi['cakupan'] === PenugasanJenis::CAKUPAN_JENJANG ? $c['jenjang'] : null,
                    'gelombang' => $definisi['cakupan'] === PenugasanJenis::CAKUPAN_GELOMBANG ? $c['gelombang'] : null,
                    'tanggal_mulai' => $c['tanggal_mulai'],
                    'tanggal_selesai' => $c['tanggal_selesai'],
                ];
                if ($target !== null) {
                    $binaan[] = ['peran' => (string) $definisi['label'], 'jenis' => $kode] + $target;
                }
                if ($mapel !== null) {
                    $kunci = $mapel['mata_pelajaran']['id'] . ':' . $mapel['kelas']['id'];
                    $mengajar[$kunci] = [
                        'mata_pelajaran_id' => $mapel['mata_pelajaran']['id'], 'mata_pelajaran' => $mapel['mata_pelajaran']['nama'],
                        'kelas_id' => $mapel['kelas']['id'], 'kelas' => $mapel['kelas']['nama'],
                    ];
                }
            }
            $penugasan[] = ['jenis' => $kode, 'label' => (string) $definisi['label'], 'identitas' => (string) $definisi['subjek'], 'rincian' => $rincian];
        }

        return [$status, $penugasan, $binaan, array_values($mengajar)];
    }

    private function inisial(string $nama): string
    {
        $kata = preg_split('/\s+/u', trim($nama)) ?: [];
        $huruf = '';
        foreach ($kata as $k) {
            if ($k !== '' && preg_match('/\p{L}/u', $k, $m)) {
                $huruf .= mb_strtoupper($m[0]);
            }
            if (mb_strlen($huruf) === 2) {
                break;
            }
        }

        return $huruf === '' ? '?' : $huruf;
    }

    private function kosong(mixed $nilai): ?string
    {
        $teks = $nilai === null ? '' : trim((string) $nilai);

        return $teks === '' ? null : $teks;
    }

    private function wajibTersedia(): void
    {
        if (!$this->repository->tersedia()) {
            throw ProfilException::belumDipasang();
        }
    }

    /** Audit adalah bagian transaksi; kegagalannya membatalkan seluruh perubahan. */
    private function wajibAudit(string $action, string $entity, int $id, array $after, ?int $actor): void
    {
        if (!$this->audit->log($action, $entity, $id, null, $after, $actor)) {
            throw new ProfilException('Perubahan dibatalkan karena audit tidak dapat disimpan. Silakan coba lagi.', 503);
        }
    }
}
