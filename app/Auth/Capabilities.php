<?php

declare(strict_types=1);

namespace App\Auth;

use App\Penugasan\PenugasanJenis;
use mysqli;

/**
 * Kemampuan (capability) perizinan V2.
 *
 * Kemampuan selalu dihitung ulang dari basis data pada sisi server. Menyembunyikan
 * tombol pada UI tidak pernah dianggap sebagai kontrol akses (PRD 5.2).
 *
 * - `admin`      : role `admin`.
 * - `pengurus`   : role `pengurus` DAN `users.pengurus_id` menunjuk pengurus aktif.
 * - `murobi`     : role `guru` DAN ada `murobi_assignments` aktif yang cocok dengan
 *                  tahun ajaran aktif pada tanggal berjalan. Tidak ada role `murobi`.
 * - `orang_tua`  : role `orang_tua` DAN `users.wali_id` menunjuk wali aktif.
 *
 * **Fondasi penugasan V3–V6 (keputusan pengguna 7 September 2026).** Kelas
 * yang sama diperluas dengan *feature capability* yang dihitung dari
 * penugasan fungsional (`App\Penugasan\PenugasanJenis`): guru mata pelajaran,
 * Bagian Pendidikan, bendahara pembiayaan bulanan, panitia PSB, bendahara PSB,
 * serta cakupan binaan murobi/pembimbing. Tidak ada sistem otorisasi kedua:
 * daftar `forUser()` di atas TIDAK berubah bentuk maupun maknanya (dipakai
 * guard perizinan V2 dan aplikasi perangkat), sedangkan feature capability
 * disajikan lewat metode `feature*()` yang terpisah dan aditif.
 *
 * Feature capability dihitung dari: akun aktif → role dasar → relasi master
 * aktif → penugasan aktif → masa berlaku (tanggal berjalan) → tahun ajaran →
 * cakupan. Satu akun dapat memegang beberapa penugasan sekaligus; penugasan
 * yang berakhir atau dinonaktifkan langsung tidak menghasilkan capability pada
 * pemeriksaan berikutnya. Admin memperoleh seluruh feature capability sebagai
 * PENGAWASAN (`sumber = admin`) — modul masa depan wajib membedakan pelaku
 * admin dari pelaku operasional lewat `featureSource()`.
 */
final class Capabilities
{
    /** Sumber feature capability: dari penugasan aktif milik akun itu sendiri. */
    public const SUMBER_PENUGASAN = 'penugasan';
    /** Sumber feature capability: hak pengawasan admin (tanpa penugasan). */
    public const SUMBER_ADMIN = 'admin';
    /** Sumber feature capability: admin yang juga memegang penugasan aktif. */
    public const SUMBER_KEDUANYA = 'keduanya';

    public const ADMIN = 'admin';
    public const PENGURUS = 'pengurus';
    public const MUROBI = 'murobi';
    public const ORANG_TUA = 'orang_tua';

    public const ALL = [self::ADMIN, self::PENGURUS, self::MUROBI, self::ORANG_TUA];

    /** @var array<int, array<int, string>> */
    private array $cache = [];

    /** @var array<int, array<string, array{sumber:string, cakupan:array<int, array<string, mixed>>}>> */
    private array $featureCache = [];

    /** @var array<int, string|null> */
    private array $kelasJenjangCache = [];

    public function __construct(private mysqli $db)
    {
    }

    /**
     * @param array{id:int, roles:array<int,string>, guru_id:int|null} $user
     * @return array<int, string>
     */
    public function forUser(array $user): array
    {
        $userId = (int) $user['id'];
        if (isset($this->cache[$userId])) {
            return $this->cache[$userId];
        }

        $roles = $user['roles'] ?? [];
        $capabilities = [];

        if (in_array('admin', $roles, true)) {
            $capabilities[] = self::ADMIN;
        }
        if (in_array('pengurus', $roles, true) && $this->linkedPengurusId($userId) !== null) {
            $capabilities[] = self::PENGURUS;
        }
        if (in_array('guru', $roles, true) && $this->hasActiveMurobiAssignment($userId)) {
            $capabilities[] = self::MUROBI;
        }
        if (in_array('orang_tua', $roles, true) && $this->linkedWaliId($userId) !== null) {
            $capabilities[] = self::ORANG_TUA;
        }

        return $this->cache[$userId] = $capabilities;
    }

    /**
     * @param array{id:int, roles:array<int,string>, guru_id:int|null} $user
     */
    public function has(array $user, string $capability): bool
    {
        return in_array($capability, $this->forUser($user), true);
    }

    /**
     * @param array{id:int, roles:array<int,string>, guru_id:int|null} $user
     * @param array<int, string> $capabilities
     */
    public function hasAny(array $user, array $capabilities): bool
    {
        return array_intersect($capabilities, $this->forUser($user)) !== [];
    }

    public function linkedPengurusId(int $userId): ?int
    {
        return $this->scalar(
            'SELECT p.id AS nilai
               FROM users u
               JOIN pengurus p ON p.id = u.pengurus_id
              WHERE u.id = ? AND u.is_active = 1 AND p.is_active = 1 AND p.archived_at IS NULL
              LIMIT 1',
            $userId
        );
    }

    public function linkedWaliId(int $userId): ?int
    {
        return $this->scalar(
            'SELECT w.id AS nilai
               FROM users u
               JOIN wali w ON w.id = u.wali_id
              WHERE u.id = ? AND u.is_active = 1 AND w.is_active = 1 AND w.archived_at IS NULL
              LIMIT 1',
            $userId
        );
    }

    /**
     * Guru tanpa penugasan murobi aktif tidak pernah memperoleh kemampuan keputusan.
     */
    public function hasActiveMurobiAssignment(int $userId): bool
    {
        return $this->scalar(
            "SELECT 1 AS nilai
               FROM users u
               JOIN guru g ON g.id = u.guru_id
               JOIN murobi_assignments ma ON ma.guru_id = g.id
               JOIN tahun_ajaran ta ON ta.id = ma.tahun_ajaran_id
               LEFT JOIN kelas kl ON kl.id = ma.kelas_id
                    AND kl.is_active = 1 AND kl.archived_at IS NULL
              WHERE u.id = ?
                AND u.is_active = 1
                AND g.is_active = 1 AND g.archived_at IS NULL
                AND ma.is_active = 1 AND ma.archived_at IS NULL
                AND ma.tanggal_mulai <= CURDATE()
                AND (ma.tanggal_selesai IS NULL OR ma.tanggal_selesai >= CURDATE())
                AND ta.status = 'Aktif' AND ta.archived_at IS NULL
                AND (ma.target_type = 'Kamar' OR (ma.target_type = 'Kelas' AND kl.id IS NOT NULL))
              LIMIT 1",
            $userId
        ) !== null;
    }

    public function forget(int $userId): void
    {
        unset($this->cache[$userId], $this->featureCache[$userId]);
    }

    // =======================================================================
    // Feature capability dari penugasan fungsional (fondasi V3–V6). Aditif.
    // =======================================================================

    /**
     * Peta capability → sumber dan cakupan efektif, dihitung ulang dari basis
     * data (di-cache per request per akun; `forget()` membersihkannya).
     *
     * Bentuk tiap cakupan:
     *   ['jenis' => 'guru_mapel', 'id' => 12, 'tahun_ajaran_id' => 4,
     *    'kelas_id' => 3|null, 'kamar_id' => null, 'mata_pelajaran_id' => 7|null,
     *    'jenjang' => null|'Tsanawi', 'gelombang' => null|'1',
     *    'tanggal_mulai' => 'Y-m-d', 'tanggal_selesai' => 'Y-m-d'|null]
     *
     * Admin tanpa penugasan memperoleh setiap capability dengan `sumber = admin`
     * dan cakupan kosong (= tidak dibatasi, sebagai pengawasan).
     *
     * @param array{id:int, roles:array<int,string>, guru_id?:int|null} $user
     * @return array<string, array{sumber:string, cakupan:array<int, array<string, mixed>>}>
     */
    public function featureCapabilities(array $user): array
    {
        $userId = (int) $user['id'];
        if (isset($this->featureCache[$userId])) {
            return $this->featureCache[$userId];
        }

        // Role dibaca ULANG dari basis data untuk akun aktif — bukan dari array
        // yang dikirim pemanggil, sesi, atau respons klien. Nilai `roles` pada
        // $user hanya dipakai bila akun tidak ditemukan (hasilnya kosong).
        $roles = $this->rolesFromDatabase($userId);
        if ($roles === null) {
            return $this->featureCache[$userId] = [];
        }
        $isAdmin = in_array('admin', $roles, true);
        $hasil = [];

        foreach (PenugasanJenis::semua() as $jenis => $definisi) {
            if (!in_array($definisi['role_dasar'], $roles, true)) {
                continue;
            }
            $cakupan = $this->activeScopes($userId, $jenis);
            if ($cakupan === []) {
                continue;
            }
            foreach ($definisi['capabilities'] as $capability) {
                $hasil[$capability] ??= ['sumber' => self::SUMBER_PENUGASAN, 'cakupan' => []];
                $hasil[$capability]['cakupan'] = array_merge($hasil[$capability]['cakupan'], $cakupan);
            }
        }

        if ($isAdmin) {
            foreach (PenugasanJenis::semuaCapability() as $capability) {
                if (isset($hasil[$capability])) {
                    $hasil[$capability]['sumber'] = self::SUMBER_KEDUANYA;
                    continue;
                }
                $hasil[$capability] = ['sumber' => self::SUMBER_ADMIN, 'cakupan' => []];
            }
        }

        // Urutan tetap mengikuti katalog agar keluaran deterministik.
        $terurut = [];
        foreach (PenugasanJenis::semuaCapability() as $capability) {
            if (isset($hasil[$capability])) {
                $terurut[$capability] = $hasil[$capability];
            }
        }

        return $this->featureCache[$userId] = $terurut;
    }

    /**
     * @param array{id:int, roles:array<int,string>, guru_id?:int|null} $user
     * @return array<int, string>
     */
    public function featureList(array $user): array
    {
        return array_keys($this->featureCapabilities($user));
    }

    /**
     * @param array{id:int, roles:array<int,string>, guru_id?:int|null} $user
     */
    public function hasFeature(array $user, string $capability): bool
    {
        return isset($this->featureCapabilities($user)[$capability]);
    }

    /**
     * `penugasan`, `admin`, `keduanya`, atau null bila tidak dimiliki.
     *
     * @param array{id:int, roles:array<int,string>, guru_id?:int|null} $user
     */
    public function featureSource(array $user, string $capability): ?string
    {
        return $this->featureCapabilities($user)[$capability]['sumber'] ?? null;
    }

    /**
     * Cakupan penugasan yang mendasari sebuah capability (kosong untuk admin
     * tanpa penugasan).
     *
     * @param array{id:int, roles:array<int,string>, guru_id?:int|null} $user
     * @return array<int, array<string, mixed>>
     */
    public function featureScopes(array $user, string $capability): array
    {
        return $this->featureCapabilities($user)[$capability]['cakupan'] ?? [];
    }

    /**
     * Pemeriksaan cakupan terpusat.
     *
     * `$konteks` boleh memuat: `kelas_id`, `kamar_id`, `mata_pelajaran_id`,
     * `tahun_ajaran_id`, `jenjang`, `gelombang`. Seluruh kunci yang diberikan
     * harus cocok pada SATU cakupan yang sama. Admin (pengawasan) selalu lolos;
     * bedakan pelakunya lewat `featureSource()`.
     *
     * @param array{id:int, roles:array<int,string>, guru_id?:int|null} $user
     * @param array<string, mixed> $konteks
     */
    public function featureAppliesTo(array $user, string $capability, array $konteks): bool
    {
        $entri = $this->featureCapabilities($user)[$capability] ?? null;
        if ($entri === null) {
            return false;
        }
        if ($entri['sumber'] === self::SUMBER_ADMIN) {
            return true;
        }
        foreach ($entri['cakupan'] as $cakupan) {
            if ($this->scopeMatches($cakupan, $konteks)) {
                return true;
            }
        }

        // Admin yang juga punya penugasan tetap berhak sebagai pengawas.
        return $entri['sumber'] === self::SUMBER_KEDUANYA;
    }

    /** @param array{id:int, roles:array<int,string>, guru_id?:int|null} $user */
    public function featureAppliesToKelas(array $user, string $capability, int $kelasId, ?int $tahunAjaranId = null): bool
    {
        return $this->featureAppliesTo($user, $capability, array_filter(
            ['kelas_id' => $kelasId, 'tahun_ajaran_id' => $tahunAjaranId],
            static fn (mixed $nilai): bool => $nilai !== null
        ));
    }

    /** @param array{id:int, roles:array<int,string>, guru_id?:int|null} $user */
    public function featureAppliesToKamar(array $user, string $capability, int $kamarId, ?int $tahunAjaranId = null): bool
    {
        return $this->featureAppliesTo($user, $capability, array_filter(
            ['kamar_id' => $kamarId, 'tahun_ajaran_id' => $tahunAjaranId],
            static fn (mixed $nilai): bool => $nilai !== null
        ));
    }

    /** @param array{id:int, roles:array<int,string>, guru_id?:int|null} $user */
    public function featureAppliesToMataPelajaran(array $user, string $capability, int $mataPelajaranId, ?int $kelasId = null, ?int $tahunAjaranId = null): bool
    {
        return $this->featureAppliesTo($user, $capability, array_filter(
            ['mata_pelajaran_id' => $mataPelajaranId, 'kelas_id' => $kelasId, 'tahun_ajaran_id' => $tahunAjaranId],
            static fn (mixed $nilai): bool => $nilai !== null
        ));
    }

    /** @param array{id:int, roles:array<int,string>, guru_id?:int|null} $user */
    public function featureAppliesToTahunAjaran(array $user, string $capability, int $tahunAjaranId): bool
    {
        return $this->featureAppliesTo($user, $capability, ['tahun_ajaran_id' => $tahunAjaranId]);
    }

    /**
     * Semester diwakili baris `tahun_ajaran` (pasangan tahun+semester unik).
     *
     * @param array{id:int, roles:array<int,string>, guru_id?:int|null} $user
     */
    public function featureAppliesToSemester(array $user, string $capability, string $tahun, string $semester): bool
    {
        $tahunAjaranId = $this->tahunAjaranId($tahun, $semester);

        return $tahunAjaranId !== null && $this->featureAppliesToTahunAjaran($user, $capability, $tahunAjaranId);
    }

    /**
     * Gelombang PSB belum mempunyai master; label dibandingkan setelah
     * dinormalkan. Penugasan tanpa gelombang berlaku untuk seluruh gelombang
     * tahun penerimaan itu.
     *
     * @param array{id:int, roles:array<int,string>, guru_id?:int|null} $user
     */
    public function featureAppliesToGelombangPsb(array $user, string $capability, int $tahunAjaranId, ?string $gelombang = null): bool
    {
        $konteks = ['tahun_ajaran_id' => $tahunAjaranId];
        if ($gelombang !== null && trim($gelombang) !== '') {
            $konteks['gelombang'] = $gelombang;
        }

        return $this->featureAppliesTo($user, $capability, $konteks);
    }

    /**
     * @param array<string, mixed> $cakupan
     * @param array<string, mixed> $konteks
     */
    private function scopeMatches(array $cakupan, array $konteks): bool
    {
        if (array_key_exists('tahun_ajaran_id', $konteks) && (int) $cakupan['tahun_ajaran_id'] !== (int) $konteks['tahun_ajaran_id']) {
            return false;
        }
        if (array_key_exists('kelas_id', $konteks)) {
            $kelasId = (int) $konteks['kelas_id'];
            if ($cakupan['kelas_id'] !== null) {
                if ((int) $cakupan['kelas_id'] !== $kelasId) {
                    return false;
                }
            } elseif ($cakupan['kamar_id'] !== null) {
                return false; // cakupan kamar tidak pernah mewakili kelas
            } elseif ($cakupan['jenjang'] !== null) {
                $jenjang = $this->kelasJenjang($kelasId);
                if ($jenjang === null || !$this->sameLabel($jenjang, (string) $cakupan['jenjang'])) {
                    return false;
                }
            }
        }
        if (array_key_exists('kamar_id', $konteks)) {
            if ($cakupan['kamar_id'] !== null) {
                if ((int) $cakupan['kamar_id'] !== (int) $konteks['kamar_id']) {
                    return false;
                }
            } elseif ($cakupan['kelas_id'] !== null || $cakupan['jenjang'] !== null) {
                return false; // cakupan kelas/jenjang tidak mewakili kamar
            }
        }
        if (array_key_exists('mata_pelajaran_id', $konteks)
            && $cakupan['mata_pelajaran_id'] !== null
            && (int) $cakupan['mata_pelajaran_id'] !== (int) $konteks['mata_pelajaran_id']) {
            return false;
        }
        if (array_key_exists('jenjang', $konteks)) {
            $diminta = (string) $konteks['jenjang'];
            if ($cakupan['jenjang'] !== null) {
                if (!$this->sameLabel((string) $cakupan['jenjang'], $diminta)) {
                    return false;
                }
            } elseif ($cakupan['kelas_id'] !== null) {
                $jenjang = $this->kelasJenjang((int) $cakupan['kelas_id']);
                if ($jenjang === null || !$this->sameLabel($jenjang, $diminta)) {
                    return false;
                }
            }
        }
        if (array_key_exists('gelombang', $konteks)
            && $cakupan['gelombang'] !== null
            && !$this->sameLabel((string) $cakupan['gelombang'], (string) $konteks['gelombang'])) {
            return false;
        }

        return true;
    }

    private function sameLabel(string $a, string $b): bool
    {
        return mb_strtolower(trim($a)) === mb_strtolower(trim($b));
    }

    /**
     * Cakupan penugasan AKTIF milik akun untuk satu jenis. Seluruh syarat
     * (akun aktif, master aktif, penugasan aktif/tidak diarsipkan, masa
     * berlaku pada tanggal berjalan, tahun ajaran, cakupan yang masih sah)
     * ditegakkan di dalam query, bukan disaring belakangan di PHP.
     *
     * @return array<int, array<string, mixed>>
     */
    private function activeScopes(int $userId, string $jenis): array
    {
        $definisi = PenugasanJenis::definisi($jenis);
        $tabel = $definisi['tabel'];

        $subjek = $definisi['subjek'] === 'guru'
            ? 'JOIN guru s ON s.id = u.guru_id'
            : 'JOIN pengurus s ON s.id = u.pengurus_id';
        $joinCakupan = '';
        $syaratCakupan = '';
        $kolom = 'NULL AS kelas_id, NULL AS kamar_id, NULL AS mata_pelajaran_id, NULL AS jenjang, NULL AS gelombang';

        switch ($definisi['cakupan']) {
            case PenugasanJenis::CAKUPAN_TARGET:
                $kolom = 'a.kelas_id, a.kamar_id, NULL AS mata_pelajaran_id, NULL AS jenjang, NULL AS gelombang';
                $joinCakupan = 'LEFT JOIN kelas kl ON kl.id = a.kelas_id AND kl.is_active = 1 AND kl.archived_at IS NULL';
                $syaratCakupan = "AND a.archived_at IS NULL AND (a.target_type = 'Kamar' OR (a.target_type = 'Kelas' AND kl.id IS NOT NULL))";
                break;
            case PenugasanJenis::CAKUPAN_MAPEL_KELAS:
                $kolom = 'a.kelas_id, NULL AS kamar_id, a.mata_pelajaran_id, NULL AS jenjang, NULL AS gelombang';
                $joinCakupan = 'JOIN kelas kl ON kl.id = a.kelas_id AND kl.is_active = 1 AND kl.archived_at IS NULL
                                JOIN mata_pelajaran mp ON mp.id = a.mata_pelajaran_id AND mp.is_active = 1 AND mp.archived_at IS NULL';
                break;
            case PenugasanJenis::CAKUPAN_JENJANG:
                $kolom = 'NULL AS kelas_id, NULL AS kamar_id, NULL AS mata_pelajaran_id, a.jenjang, NULL AS gelombang';
                break;
            case PenugasanJenis::CAKUPAN_GELOMBANG:
                $kolom = 'NULL AS kelas_id, NULL AS kamar_id, NULL AS mata_pelajaran_id, NULL AS jenjang, a.gelombang';
                break;
        }

        $syaratTahun = $definisi['wajib_tahun_aktif'] ? "AND ta.status = 'Aktif'" : '';

        $sql = "SELECT a.id, a.tahun_ajaran_id, {$kolom}, a.tanggal_mulai, a.tanggal_selesai
                  FROM users u
                  {$subjek}
                  JOIN {$tabel} a ON a.{$definisi['subjek_kolom']} = s.id
                  JOIN tahun_ajaran ta ON ta.id = a.tahun_ajaran_id
                  {$joinCakupan}
                 WHERE u.id = ? AND u.is_active = 1
                   AND s.is_active = 1 AND s.archived_at IS NULL
                   AND a.is_active = 1
                   AND a.tanggal_mulai <= CURDATE()
                   AND (a.tanggal_selesai IS NULL OR a.tanggal_selesai >= CURDATE())
                   AND ta.archived_at IS NULL {$syaratTahun}
                   {$syaratCakupan}
                 ORDER BY a.id";

        $statement = $this->db->prepare($sql);
        if ($statement === false) {
            // Skema fondasi belum terpasang: tidak ada capability, bukan galat fatal.
            return [];
        }
        $statement->bind_param('i', $userId);
        if (!$statement->execute()) {
            $statement->close();
            return [];
        }
        $rows = $statement->get_result()?->fetch_all(MYSQLI_ASSOC) ?? [];
        $statement->close();

        return array_map(static fn (array $row): array => [
            'jenis' => $jenis,
            'id' => (int) $row['id'],
            'tahun_ajaran_id' => (int) $row['tahun_ajaran_id'],
            'kelas_id' => $row['kelas_id'] === null ? null : (int) $row['kelas_id'],
            'kamar_id' => $row['kamar_id'] === null ? null : (int) $row['kamar_id'],
            'mata_pelajaran_id' => $row['mata_pelajaran_id'] === null ? null : (int) $row['mata_pelajaran_id'],
            'jenjang' => $row['jenjang'] === null ? null : (string) $row['jenjang'],
            'gelombang' => $row['gelombang'] === null ? null : (string) $row['gelombang'],
            'tanggal_mulai' => (string) $row['tanggal_mulai'],
            'tanggal_selesai' => $row['tanggal_selesai'] === null ? null : (string) $row['tanggal_selesai'],
        ], $rows);
    }

    /**
     * Role dasar akun AKTIF menurut basis data; null bila akun tidak ada atau
     * nonaktif.
     *
     * @return array<int, string>|null
     */
    private function rolesFromDatabase(int $userId): ?array
    {
        $statement = $this->db->prepare(
            "SELECT u.id, GROUP_CONCAT(DISTINCT r.slug ORDER BY r.slug SEPARATOR ',') AS roles
               FROM users u
               LEFT JOIN user_roles ur ON ur.user_id = u.id
               LEFT JOIN roles r ON r.id = ur.role_id
              WHERE u.id = ? AND u.is_active = 1
              GROUP BY u.id LIMIT 1"
        );
        if ($statement === false) {
            return null;
        }
        $statement->bind_param('i', $userId);
        if (!$statement->execute()) {
            $statement->close();
            return null;
        }
        $row = $statement->get_result()?->fetch_assoc();
        $statement->close();
        if (!$row) {
            return null;
        }

        return $row['roles'] ? explode(',', (string) $row['roles']) : [];
    }

    private function kelasJenjang(int $kelasId): ?string
    {
        if (array_key_exists($kelasId, $this->kelasJenjangCache)) {
            return $this->kelasJenjangCache[$kelasId];
        }
        $statement = $this->db->prepare('SELECT jenjang FROM kelas WHERE id = ? LIMIT 1');
        $jenjang = null;
        if ($statement !== false) {
            $statement->bind_param('i', $kelasId);
            if ($statement->execute()) {
                $row = $statement->get_result()?->fetch_assoc();
                $jenjang = $row ? (string) $row['jenjang'] : null;
            }
            $statement->close();
        }

        return $this->kelasJenjangCache[$kelasId] = $jenjang;
    }

    private function tahunAjaranId(string $tahun, string $semester): ?int
    {
        $statement = $this->db->prepare('SELECT id FROM tahun_ajaran WHERE tahun = ? AND semester = ? LIMIT 1');
        if ($statement === false) {
            return null;
        }
        $tahun = trim($tahun);
        $semester = trim($semester);
        $statement->bind_param('ss', $tahun, $semester);
        $id = null;
        if ($statement->execute()) {
            $row = $statement->get_result()?->fetch_assoc();
            $id = $row ? (int) $row['id'] : null;
        }
        $statement->close();

        return $id;
    }

    private function scalar(string $sql, int $userId): ?int
    {
        $statement = $this->db->prepare($sql);
        if ($statement === false) {
            return null;
        }
        $statement->bind_param('i', $userId);
        if (!$statement->execute()) {
            $statement->close();
            return null;
        }
        $row = $statement->get_result()?->fetch_assoc();
        $statement->close();

        return $row === null || $row === false ? null : (int) $row['nilai'];
    }
}
