<?php

declare(strict_types=1);

namespace App\MasterData;

use mysqli;
use RuntimeException;

final class MasterDataRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function db(): mysqli
    {
        return $this->db;
    }

    public function guruList(array $filters, int $page, int $perPage): array
    {
        [$where, $params] = $this->masterWhere($filters, ['nama_guru', 'nip', 'no_hp']);
        $total = $this->scalar('SELECT COUNT(*) jumlah FROM guru ' . $where, $params);
        $params[] = $perPage;
        $params[] = ($page - 1) * $perPage;
        $rows = $this->all('SELECT * FROM guru ' . $where . ' ORDER BY nama_guru, id LIMIT ? OFFSET ?', $params);
        return compact('rows', 'total');
    }

    public function guruFind(int $id): ?array
    {
        return $this->one('SELECT * FROM guru WHERE id = ?', [$id]);
    }

    /**
     * Kolom `status` lama ('Guru'|'Pembimbing'|'Keduanya') TIDAK dihapus dari
     * skema. Sejak koreksi ke-3 (30 Agustus 2026) ia bukan lagi pilihan
     * operasional: guru baru selalu dibuat dengan nilai default 'Guru' agar
     * kolom NOT NULL tetap valid.
     */
    public function guruCreate(array $data): int
    {
        return $this->insert(
            'INSERT INTO guru (nip, nama_guru, no_hp, status, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, 1, NOW(), NOW())',
            [$data['nip'] ?: null, $data['nama_guru'], $data['no_hp'] ?: null, $data['status'] ?? 'Guru']
        );
    }

    /**
     * Pembaruan identitas guru SENGAJA tidak menyentuh kolom `status`.
     *
     * Menyimpan formulir guru tidak boleh diam-diam mengubah nilai tugas lama
     * ('Pembimbing'/'Keduanya') menjadi 'Guru'. Data historis dipertahankan
     * sampai strategi kompatibilitasnya diverifikasi.
     */
    public function guruUpdate(int $id, array $data): void
    {
        $this->execute(
            'UPDATE guru SET nip = ?, nama_guru = ?, no_hp = ?, updated_at = NOW() WHERE id = ?',
            [$data['nip'] ?: null, $data['nama_guru'], $data['no_hp'] ?: null, $id]
        );
    }

    /**
     * Ringkasan penugasan nyata seorang guru.
     *
     * Sumbernya bukan kolom `status` lama, melainkan data operasional:
     *   - mengajar : jumlah jadwal aktif pada semester aktif (jadwal_ngaji);
     *   - murobi   : jumlah penugasan murobi aktif (murobi_assignments).
     *
     * @return array{jadwal_aktif:int, murobi_aktif:int}
     */
    public function guruAssignmentSummary(int $guruId): array
    {
        $row = $this->one(
            "SELECT
                (SELECT COUNT(*) FROM jadwal_ngaji j
                   JOIN tahun_ajaran ta ON ta.id = j.id_tahun
                  WHERE j.id_guru = ? AND j.is_active = 1 AND j.archived_at IS NULL
                    AND ta.status = 'Aktif' AND ta.archived_at IS NULL) AS jadwal_aktif,
                (SELECT COUNT(*) FROM murobi_assignments ma
                   JOIN tahun_ajaran ta2 ON ta2.id = ma.tahun_ajaran_id
                  WHERE ma.guru_id = ? AND ma.is_active = 1 AND ma.archived_at IS NULL
                    AND ma.tanggal_mulai <= CURDATE()
                    AND (ma.tanggal_selesai IS NULL OR ma.tanggal_selesai >= CURDATE())
                    AND ta2.status = 'Aktif' AND ta2.archived_at IS NULL) AS murobi_aktif",
            [$guruId, $guruId]
        );

        return [
            'jadwal_aktif' => (int) ($row['jadwal_aktif'] ?? 0),
            'murobi_aktif' => (int) ($row['murobi_aktif'] ?? 0),
        ];
    }

    public function guruSetState(int $id, bool $active, bool $archive): void
    {
        $this->execute(
            'UPDATE guru SET is_active = ?, archived_at = ?, updated_at = NOW() WHERE id = ?',
            [$active ? 1 : 0, $archive ? date('Y-m-d H:i:s') : null, $id]
        );
    }

    public function santriList(array $filters, int $page, int $perPage): array
    {
        [$where, $params] = $this->masterWhere($filters, ['nama_santri', 'nis', 'sekolah_saat_ini'], 's');
        if (!empty($filters['gender']) && in_array($filters['gender'], ['L', 'P'], true)) {
            $where .= ($where === '' ? ' WHERE ' : ' AND ') . 's.jenis_kelamin = ?';
            $params[] = $filters['gender'];
        }
        if (!empty($filters['kelas_id'])) {
            $where .= ($where === '' ? ' WHERE ' : ' AND ') . 'pk.id_kelas = ?';
            $params[] = (int) $filters['kelas_id'];
        }
        $join = " FROM santri s LEFT JOIN plotting_kelas pk ON pk.id_santri = s.id AND pk.status = 'Aktif' AND pk.id_tahun = (SELECT id FROM tahun_ajaran WHERE status = 'Aktif' AND archived_at IS NULL LIMIT 1) LEFT JOIN kelas k ON k.id = pk.id_kelas LEFT JOIN tahun_ajaran ta ON ta.id = pk.id_tahun ";
        $total = $this->scalar('SELECT COUNT(DISTINCT s.id) jumlah ' . $join . $where, $params);
        $params[] = $perPage;
        $params[] = ($page - 1) * $perPage;
        $rows = $this->all('SELECT s.*, k.nama_kelas, ta.tahun, ta.semester ' . $join . $where . ' ORDER BY s.nama_santri, s.id LIMIT ? OFFSET ?', $params);
        return compact('rows', 'total');
    }

    public function santriFind(int $id): ?array
    {
        return $this->one('SELECT * FROM santri WHERE id = ?', [$id]);
    }

    public function santriCreate(array $data): int
    {
        return $this->insert(
            'INSERT INTO santri (nis, nama_santri, jenis_kelamin, tempat_lahir, tgl_lahir, alamat, desa, kecamatan, kab_kota, provinsi, nama_ayah, no_hp_ayah, nama_ibu, no_hp_ibu, asal_sekolah, sekolah_saat_ini, foto, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())',
            [$data['nis'], $data['nama_santri'], $data['jenis_kelamin'], $data['tempat_lahir'], $data['tgl_lahir'], $data['alamat'], $data['desa'], $data['kecamatan'], $data['kab_kota'], $data['provinsi'], $data['nama_ayah'], $data['no_hp_ayah'] ?: null, $data['nama_ibu'], $data['no_hp_ibu'] ?: null, $data['asal_sekolah'], $data['sekolah_saat_ini'], $data['foto']]
        );
    }

    public function santriUpdate(int $id, array $data): void
    {
        $this->execute(
            'UPDATE santri SET nis = ?, nama_santri = ?, jenis_kelamin = ?, tempat_lahir = ?, tgl_lahir = ?, alamat = ?, desa = ?, kecamatan = ?, kab_kota = ?, provinsi = ?, nama_ayah = ?, no_hp_ayah = ?, nama_ibu = ?, no_hp_ibu = ?, asal_sekolah = ?, sekolah_saat_ini = ?, foto = ?, updated_at = NOW() WHERE id = ?',
            [$data['nis'], $data['nama_santri'], $data['jenis_kelamin'], $data['tempat_lahir'], $data['tgl_lahir'], $data['alamat'], $data['desa'], $data['kecamatan'], $data['kab_kota'], $data['provinsi'], $data['nama_ayah'], $data['no_hp_ayah'] ?: null, $data['nama_ibu'], $data['no_hp_ibu'] ?: null, $data['asal_sekolah'], $data['sekolah_saat_ini'], $data['foto'], $id]
        );
    }

    public function santriSetState(int $id, bool $active, bool $archive): void
    {
        $this->execute('UPDATE santri SET is_active = ?, archived_at = ?, updated_at = NOW() WHERE id = ?', [$active ? 1 : 0, $archive ? date('Y-m-d H:i:s') : null, $id]);
    }

    public function waliList(array $filters, int $page, int $perPage): array
    {
        [$where, $params] = $this->masterWhere($filters, ['nama', 'no_hp'], 'w');
        $total = $this->scalar('SELECT COUNT(*) jumlah FROM wali w ' . $where, $params);
        $params[] = $perPage;
        $params[] = ($page - 1) * $perPage;
        $rows = $this->all("SELECT w.*, (SELECT COUNT(*) FROM santri_wali sw WHERE sw.wali_id = w.id AND sw.archived_at IS NULL) jumlah_santri, (SELECT GROUP_CONCAT(CONCAT(s.nama_santri, ' (', sw.hubungan, ')') ORDER BY s.nama_santri SEPARATOR ', ') FROM santri_wali sw JOIN santri s ON s.id = sw.santri_id WHERE sw.wali_id = w.id AND sw.archived_at IS NULL) santri FROM wali w " . $where . ' ORDER BY w.nama, w.id LIMIT ? OFFSET ?', $params);
        return compact('rows', 'total');
    }

    public function waliFind(int $id): ?array
    {
        return $this->one('SELECT * FROM wali WHERE id = ?', [$id]);
    }

    public function waliRelations(int $id): array
    {
        return $this->all('SELECT sw.id, sw.santri_id, sw.hubungan, sw.is_primary, sw.archived_at, s.nis, s.nama_santri FROM santri_wali sw JOIN santri s ON s.id = sw.santri_id WHERE sw.wali_id = ? ORDER BY sw.archived_at IS NOT NULL, s.nama_santri', [$id]);
    }

    public function waliCreate(array $data): int
    {
        return $this->insert('INSERT INTO wali (nama, no_hp, alamat, is_active, created_at, updated_at) VALUES (?, ?, ?, 1, NOW(), NOW())', [$data['nama'], $data['no_hp'] ?: null, $data['alamat'] ?: null]);
    }

    public function waliUpdate(int $id, array $data): void
    {
        $this->execute('UPDATE wali SET nama = ?, no_hp = ?, alamat = ?, updated_at = NOW() WHERE id = ?', [$data['nama'], $data['no_hp'] ?: null, $data['alamat'] ?: null, $id]);
    }

    public function waliSetState(int $id, bool $active, bool $archive): void
    {
        $this->execute('UPDATE wali SET is_active = ?, archived_at = ?, updated_at = NOW() WHERE id = ?', [$active ? 1 : 0, $archive ? date('Y-m-d H:i:s') : null, $id]);
    }

    public function waliAttach(int $waliId, int $santriId, string $relationship, bool $primary, int $actorId): int
    {
        return $this->insert('INSERT INTO santri_wali (santri_id, wali_id, hubungan, is_primary, archived_at, created_by, created_at) VALUES (?, ?, ?, ?, NULL, ?, NOW())', [$santriId, $waliId, $relationship, $primary ? 1 : 0, $actorId]);
    }

    public function waliDetach(int $relationId, int $waliId): void
    {
        $this->execute('UPDATE santri_wali SET archived_at = NOW() WHERE id = ? AND wali_id = ? AND archived_at IS NULL', [$relationId, $waliId]);
    }

    public function pengurusList(array $filters, int $page, int $perPage): array
    {
        [$where, $params] = $this->masterWhere($filters, ['nama', 'nomor_identitas', 'jabatan', 'no_hp']);
        $total = $this->scalar('SELECT COUNT(*) jumlah FROM pengurus ' . $where, $params);
        $params[] = $perPage;
        $params[] = ($page - 1) * $perPage;
        $rows = $this->all('SELECT * FROM pengurus ' . $where . ' ORDER BY nama, id LIMIT ? OFFSET ?', $params);
        return compact('rows', 'total');
    }

    public function pengurusFind(int $id): ?array
    {
        return $this->one('SELECT * FROM pengurus WHERE id = ?', [$id]);
    }

    public function pengurusCreate(array $data): int
    {
        return $this->insert('INSERT INTO pengurus (nama, nomor_identitas, no_hp, jabatan, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, 1, NOW(), NOW())', [$data['nama'], $data['nomor_identitas'] ?: null, $data['no_hp'] ?: null, $data['jabatan']]);
    }

    public function pengurusUpdate(int $id, array $data): void
    {
        $this->execute('UPDATE pengurus SET nama = ?, nomor_identitas = ?, no_hp = ?, jabatan = ?, updated_at = NOW() WHERE id = ?', [$data['nama'], $data['nomor_identitas'] ?: null, $data['no_hp'] ?: null, $data['jabatan'], $id]);
    }

    public function pengurusSetState(int $id, bool $active, bool $archive): void
    {
        $this->execute('UPDATE pengurus SET is_active = ?, archived_at = ?, updated_at = NOW() WHERE id = ?', [$active ? 1 : 0, $archive ? date('Y-m-d H:i:s') : null, $id]);
    }

    public function tahunList(bool $includeArchived = true): array
    {
        return $this->all('SELECT * FROM tahun_ajaran ' . ($includeArchived ? '' : 'WHERE archived_at IS NULL ') . 'ORDER BY tahun DESC, semester DESC, id DESC');
    }

    public function tahunFind(int $id): ?array
    {
        return $this->one('SELECT * FROM tahun_ajaran WHERE id = ?', [$id]);
    }

    public function tahunCreate(array $data): int
    {
        return $this->insert("INSERT INTO tahun_ajaran (tahun, semester, status, created_at, updated_at) VALUES (?, ?, 'Non-Aktif', NOW(), NOW())", [$data['tahun'], $data['semester']]);
    }

    public function tahunUpdate(int $id, array $data): void
    {
        $this->execute('UPDATE tahun_ajaran SET tahun = ?, semester = ?, updated_at = NOW() WHERE id = ?', [$data['tahun'], $data['semester'], $id]);
    }

    public function tahunActivate(int $id): void
    {
        $this->execute("UPDATE tahun_ajaran SET status = 'Non-Aktif', updated_at = NOW() WHERE status = 'Aktif'");
        $this->execute("UPDATE tahun_ajaran SET status = 'Aktif', archived_at = NULL, updated_at = NOW() WHERE id = ?", [$id]);
    }

    public function tahunArchive(int $id): void
    {
        $this->execute("UPDATE tahun_ajaran SET status = 'Non-Aktif', archived_at = NOW(), updated_at = NOW() WHERE id = ? AND status <> 'Aktif'", [$id]);
    }

    public function tahunRestore(int $id): void
    {
        $this->execute("UPDATE tahun_ajaran SET archived_at = NULL, updated_at = NOW() WHERE id = ?", [$id]);
    }

    public function kelasList(bool $includeArchived = true): array
    {
        return $this->all('SELECT * FROM kelas ' . ($includeArchived ? '' : 'WHERE archived_at IS NULL ') . 'ORDER BY jenjang, nama_kelas, id');
    }

    public function kelasFind(int $id): ?array
    {
        return $this->one('SELECT * FROM kelas WHERE id = ?', [$id]);
    }

    public function kelasCreate(array $data): int
    {
        return $this->insert('INSERT INTO kelas (nama_kelas, jenjang, is_active, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW())', [$data['nama_kelas'], $data['jenjang']]);
    }

    public function kelasUpdate(int $id, array $data): void
    {
        $this->execute('UPDATE kelas SET nama_kelas = ?, jenjang = ?, updated_at = NOW() WHERE id = ?', [$data['nama_kelas'], $data['jenjang'], $id]);
    }

    public function kelasSetState(int $id, bool $active, bool $archive): void
    {
        $this->execute('UPDATE kelas SET is_active = ?, archived_at = ?, updated_at = NOW() WHERE id = ?', [$active ? 1 : 0, $archive ? date('Y-m-d H:i:s') : null, $id]);
    }

    public function membershipHistory(int $santriId): array
    {
        return $this->all('SELECT pk.*, k.nama_kelas, ta.tahun, ta.semester FROM plotting_kelas pk JOIN kelas k ON k.id = pk.id_kelas JOIN tahun_ajaran ta ON ta.id = pk.id_tahun WHERE pk.id_santri = ? ORDER BY ta.tahun DESC, ta.semester DESC, pk.id DESC', [$santriId]);
    }

    public function membershipAssign(int $santriId, int $kelasId, int $tahunId, string $startDate, int $actorId): int
    {
        $this->execute("UPDATE plotting_kelas SET status = 'Selesai', tanggal_selesai = ?, updated_at = NOW() WHERE id_santri = ? AND id_tahun = ? AND status = 'Aktif'", [$startDate, $santriId, $tahunId]);
        return $this->insert("INSERT INTO plotting_kelas (id_santri, id_kelas, id_tahun, tanggal_mulai, status, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, 'Aktif', ?, NOW(), NOW())", [$santriId, $kelasId, $tahunId, $startDate, $actorId]);
    }

    public function membershipEnd(int $santriId, int $tahunId, string $endDate): void
    {
        $this->execute("UPDATE plotting_kelas SET status = 'Selesai', tanggal_selesai = ?, updated_at = NOW() WHERE id_santri = ? AND id_tahun = ? AND status = 'Aktif'", [$endDate, $santriId, $tahunId]);
    }

    public function activeYear(): ?array
    {
        return $this->one("SELECT * FROM tahun_ajaran WHERE status = 'Aktif' AND archived_at IS NULL LIMIT 1");
    }

    public function murobiList(): array
    {
        return $this->all("SELECT ma.*, g.nama_guru, ta.tahun, ta.semester, COALESCE(km.nama_kamar, k.nama_kelas) target_name FROM murobi_assignments ma JOIN guru g ON g.id = ma.guru_id JOIN tahun_ajaran ta ON ta.id = ma.tahun_ajaran_id LEFT JOIN kamar km ON km.id = ma.kamar_id LEFT JOIN kelas k ON k.id = ma.kelas_id ORDER BY ma.archived_at IS NOT NULL, ta.tahun DESC, g.nama_guru");
    }

    public function murobiFind(int $id): ?array
    {
        return $this->one('SELECT * FROM murobi_assignments WHERE id = ?', [$id]);
    }

    public function murobiCreate(array $data, int $actorId): int
    {
        return $this->insert('INSERT INTO murobi_assignments (guru_id, tahun_ajaran_id, target_type, kamar_id, kelas_id, tanggal_mulai, tanggal_selesai, is_active, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, NOW(), NOW())', [$data['guru_id'], $data['tahun_ajaran_id'], $data['target_type'], $data['kamar_id'], $data['kelas_id'], $data['tanggal_mulai'], $data['tanggal_selesai'], $actorId]);
    }

    public function murobiSetState(int $id, bool $active, bool $archive): void
    {
        $this->execute('UPDATE murobi_assignments SET is_active = ?, archived_at = ?, updated_at = NOW() WHERE id = ?', [$active ? 1 : 0, $archive ? date('Y-m-d H:i:s') : null, $id]);
    }

    public function kamarList(): array
    {
        return $this->all('SELECT * FROM kamar ORDER BY nama_kamar, id');
    }

    public function kamarFind(int $id): ?array
    {
        return $this->one('SELECT * FROM kamar WHERE id = ?', [$id]);
    }

    public function guruOptions(): array
    {
        return $this->all('SELECT id, nip, nama_guru FROM guru WHERE is_active = 1 AND archived_at IS NULL ORDER BY nama_guru');
    }

    public function santriOptions(): array
    {
        return $this->all('SELECT id, nis, nama_santri FROM santri WHERE is_active = 1 AND archived_at IS NULL ORDER BY nama_santri');
    }

    public function exportGuru(array $filters): array
    {
        [$where, $params] = $this->masterWhere($filters, ['nama_guru', 'nip', 'no_hp']);
        return $this->all('SELECT id, nip, nama_guru, no_hp, status, is_active, archived_at FROM guru ' . $where . ' ORDER BY nama_guru, id', $params);
    }

    public function exportSantri(array $filters): array
    {
        [$where, $params] = $this->masterWhere($filters, ['nama_santri', 'nis', 'sekolah_saat_ini'], 's');
        if (!empty($filters['gender']) && in_array($filters['gender'], ['L', 'P'], true)) {
            $where .= ($where === '' ? ' WHERE ' : ' AND ') . 's.jenis_kelamin = ?';
            $params[] = $filters['gender'];
        }
        if (!empty($filters['kelas_id'])) {
            $where .= ($where === '' ? ' WHERE ' : ' AND ') . 'pk.id_kelas = ?';
            $params[] = (int) $filters['kelas_id'];
        }
        return $this->all("SELECT DISTINCT s.id, s.nis, s.nama_santri, s.jenis_kelamin, s.tempat_lahir, s.tgl_lahir, s.alamat, s.desa, s.kecamatan, s.kab_kota, s.provinsi, s.asal_sekolah, s.sekolah_saat_ini, s.is_active, s.archived_at, k.nama_kelas FROM santri s LEFT JOIN plotting_kelas pk ON pk.id_santri = s.id AND pk.status = 'Aktif' AND pk.id_tahun = (SELECT id FROM tahun_ajaran WHERE status = 'Aktif' AND archived_at IS NULL LIMIT 1) LEFT JOIN kelas k ON k.id = pk.id_kelas " . $where . ' ORDER BY s.nama_santri, s.id', $params);
    }

    private function masterWhere(array $filters, array $searchColumns, string $alias = ''): array
    {
        $prefix = $alias === '' ? '' : $alias . '.';
        $parts = [];
        $params = [];
        $query = trim((string) ($filters['q'] ?? ''));
        if ($query !== '') {
            $search = [];
            foreach ($searchColumns as $column) {
                $search[] = $prefix . $column . ' LIKE ?';
                $params[] = '%' . $query . '%';
            }
            $parts[] = '(' . implode(' OR ', $search) . ')';
        }
        $state = (string) ($filters['state'] ?? 'active');
        if ($state === 'active') {
            $parts[] = $prefix . 'is_active = 1';
            $parts[] = $prefix . 'archived_at IS NULL';
        } elseif ($state === 'inactive') {
            $parts[] = $prefix . 'is_active = 0';
            $parts[] = $prefix . 'archived_at IS NULL';
        } elseif ($state === 'archived') {
            $parts[] = $prefix . 'archived_at IS NOT NULL';
        }
        return [$parts ? ' WHERE ' . implode(' AND ', $parts) : '', $params];
    }

    private function execute(string $sql, array $params = []): void
    {
        $statement = $this->db->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Query master data tidak dapat disiapkan.');
        }
        if (!$this->run($statement, $params)) {
            $errno = $statement->errno;
            $statement->close();
            if ($errno === 1062) {
                throw new MasterDataException('Data ditolak karena kunci bisnis tersebut sudah digunakan. Periksa NIS, NIP, identitas, atau kombinasi data yang dimasukkan.');
            }
            throw new RuntimeException('Perubahan master data gagal disimpan.');
        }
        $statement->close();
    }

    private function insert(string $sql, array $params): int
    {
        $this->execute($sql, $params);
        return (int) $this->db->insert_id;
    }

    private function one(string $sql, array $params = []): ?array
    {
        $rows = $this->all($sql, $params);
        return $rows[0] ?? null;
    }

    private function scalar(string $sql, array $params = []): int
    {
        $row = $this->one($sql, $params);
        return (int) ($row['jumlah'] ?? 0);
    }

    private function all(string $sql, array $params = []): array
    {
        $statement = $this->db->prepare($sql);
        if ($statement === false || !$this->run($statement, $params)) {
            throw new RuntimeException('Data master tidak dapat dibaca.');
        }
        $result = $statement->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $statement->close();
        return $rows;
    }

    private function run(\mysqli_stmt $statement, array $params): bool
    {
        if ($params !== []) {
            $types = '';
            $references = [];
            foreach ($params as $key => &$value) {
                $types .= is_int($value) || is_bool($value) ? 'i' : (is_float($value) ? 'd' : 's');
                $references[$key] = &$value;
            }
            unset($value);
            if (!$statement->bind_param($types, ...$references)) {
                return false;
            }
        }
        return $statement->execute();
    }
}
