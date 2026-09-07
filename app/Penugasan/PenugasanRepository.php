<?php

declare(strict_types=1);

namespace App\Penugasan;

use App\Database\PageQuery;
use mysqli;
use mysqli_stmt;
use RuntimeException;
use Throwable;

/**
 * Akses data penugasan fungsional (fondasi V3–V6).
 *
 * Satu repository untuk tujuh jenis penugasan yang masing-masing punya tabel
 * sendiri (dengan kunci asing nyata). Nama tabel dan kolom TIDAK pernah
 * berasal dari input pengguna: seluruhnya diambil dari katalog
 * `PenugasanJenis`, sedangkan nilai selalu diikat lewat prepared statement.
 */
final class PenugasanRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function transaction(callable $work): mixed
    {
        $this->db->begin_transaction();
        try {
            $result = $work();
            $this->db->commit();

            return $result;
        } catch (Throwable $exception) {
            $this->db->rollback();
            throw $exception;
        }
    }

    public function actorIsAdmin(int $userId): bool
    {
        return $this->one(
            "SELECT u.id FROM users u
               JOIN user_roles ur ON ur.user_id = u.id
               JOIN roles r ON r.id = ur.role_id AND r.slug = 'admin'
              WHERE u.id = ? AND u.is_active = 1 LIMIT 1",
            [$userId]
        ) !== null;
    }

    public function schemaSiap(): bool
    {
        foreach (PenugasanJenis::semua() as $definisi) {
            if ($this->scalar(
                'SELECT COUNT(*) AS jumlah FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [$definisi['tabel']]
            ) !== 1) {
                return false;
            }
        }

        return $this->scalar(
            "SELECT COUNT(*) AS jumlah FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'murobi_assignments' AND COLUMN_NAME = 'diakhiri_pada'"
        ) === 1;
    }

    // =======================================================================
    // Pembacaan
    // =======================================================================

    public function find(string $jenis, int $id, bool $forUpdate = false): ?array
    {
        $definisi = PenugasanJenis::definisi($jenis);

        return $this->one(
            'SELECT * FROM ' . $definisi['tabel'] . ' WHERE id = ?' . ($forUpdate ? ' FOR UPDATE' : ''),
            [$id]
        );
    }

    /**
     * Baris lengkap dengan nama subjek, tahun ajaran, cakupan, dan akun terkait.
     */
    public function detail(string $jenis, int $id): ?array
    {
        [$sql, $params] = $this->baseSelect($jenis, ['id' => $id]);

        return $this->all($sql, $params)[0] ?? null;
    }

    /**
     * @param array<string, mixed> $filters q, tahun_ajaran_id, status, kelas_id,
     *        kamar_id, mata_pelajaran_id, jenjang, gelombang, subjek_id
     * @return array{rows:array<int, array<string, mixed>>, total:int, page:int, perPage:int}
     */
    public function page(string $jenis, array $filters, int $page): array
    {
        [$sql, $params] = $this->baseSelect($jenis, $filters);
        $q = PageQuery::term($filters['q'] ?? '');

        return PageQuery::fetch(
            $this->db,
            $sql,
            $params,
            'is_active DESC, tanggal_mulai DESC, id DESC',
            $page,
            $q,
            ['subjek_nama', 'subjek_keterangan', 'tahun', 'semester', 'cakupan_teks', 'username']
        );
    }

    /**
     * Seluruh baris (aktif maupun tidak) milik satu subjek pada satu tahun
     * ajaran, DIKUNCI untuk pemeriksaan tumpang tindih di dalam transaksi.
     *
     * @return array<int, array<string, mixed>>
     */
    public function lockForSubject(string $jenis, int $subjekId, int $tahunAjaranId): array
    {
        $definisi = PenugasanJenis::definisi($jenis);

        return $this->all(
            'SELECT * FROM ' . $definisi['tabel'] . ' WHERE ' . $definisi['subjek_kolom'] . ' = ? AND tahun_ajaran_id = ? ORDER BY id FOR UPDATE',
            [$subjekId, $tahunAjaranId]
        );
    }

    /**
     * Akun yang terhubung ke subjek penugasan (bila ada), beserta role dasarnya.
     *
     * @return array{id:int, name:string, username:string, is_active:bool, roles:array<int,string>, guru_id:int|null}|null
     */
    public function userForSubject(string $jenis, int $subjekId): ?array
    {
        $definisi = PenugasanJenis::definisi($jenis);
        $kolom = $definisi['subjek'] === 'guru' ? 'guru_id' : 'pengurus_id';
        $row = $this->one(
            "SELECT u.id, u.name, u.username, u.is_active, u.guru_id,
                    (SELECT GROUP_CONCAT(r.slug ORDER BY r.slug SEPARATOR ',')
                       FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id) AS roles
               FROM users u WHERE u.{$kolom} = ? ORDER BY u.is_active DESC, u.id LIMIT 1",
            [$subjekId]
        );
        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'username' => (string) $row['username'],
            'is_active' => (int) $row['is_active'] === 1,
            'roles' => $row['roles'] ? explode(',', (string) $row['roles']) : [],
            'guru_id' => $row['guru_id'] === null ? null : (int) $row['guru_id'],
        ];
    }

    // =======================================================================
    // Mutasi (selalu dipanggil di dalam transaksi oleh PenugasanService)
    // =======================================================================

    /**
     * @param array<string, mixed> $data hasil normalisasi PenugasanService
     */
    public function insert(string $jenis, array $data, int $actorId): int
    {
        $definisi = PenugasanJenis::definisi($jenis);
        $kolom = [$definisi['subjek_kolom'], 'tahun_ajaran_id'];
        $nilai = [$data['subjek_id'], $data['tahun_ajaran_id']];
        foreach ($this->scopeColumns($definisi['cakupan']) as $nama) {
            $kolom[] = $nama;
            $nilai[] = $data[$nama];
        }
        array_push($kolom, 'tanggal_mulai', 'tanggal_selesai', 'is_active', 'catatan', 'created_by', 'updated_by');
        array_push($nilai, $data['tanggal_mulai'], $data['tanggal_selesai'], 1, $data['catatan'], $actorId, $actorId);
        if (!$definisi['lama']) {
            $kolom[] = 'alasan_perubahan';
            $nilai[] = $data['alasan'] ?? null;
        }

        $this->execute(
            'INSERT INTO ' . $definisi['tabel'] . ' (' . implode(', ', $kolom) . ', created_at, updated_at) VALUES ('
            . implode(', ', array_fill(0, count($kolom), '?')) . ', NOW(), NOW())',
            $nilai
        );

        return (int) $this->db->insert_id;
    }

    /**
     * Mengubah cakupan, masa berlaku, dan catatan. Subjek dan tahun ajaran
     * TIDAK pernah diubah lewat jalur ini (ganti orang = penugasan baru).
     *
     * @param array<string, mixed> $data
     */
    public function update(string $jenis, int $id, array $data, int $actorId): void
    {
        $definisi = PenugasanJenis::definisi($jenis);
        $set = [];
        $nilai = [];
        foreach ($this->scopeColumns($definisi['cakupan']) as $nama) {
            $set[] = $nama . ' = ?';
            $nilai[] = $data[$nama];
        }
        array_push($set, 'tanggal_mulai = ?', 'tanggal_selesai = ?', 'catatan = ?', 'updated_by = ?', 'updated_at = NOW()');
        array_push($nilai, $data['tanggal_mulai'], $data['tanggal_selesai'], $data['catatan'], $actorId);
        if (!$definisi['lama']) {
            $set[] = 'alasan_perubahan = ?';
            $nilai[] = $data['alasan'] ?? null;
        }
        $nilai[] = $id;

        $this->execute('UPDATE ' . $definisi['tabel'] . ' SET ' . implode(', ', $set) . ' WHERE id = ?', $nilai);
    }

    public function end(string $jenis, int $id, string $tanggalSelesai, string $alasan, int $actorId): void
    {
        $definisi = PenugasanJenis::definisi($jenis);
        $this->execute(
            'UPDATE ' . $definisi['tabel'] . ' SET tanggal_selesai = ?, diakhiri_pada = NOW(), diakhiri_oleh = ?, alasan_pengakhiran = ?, updated_by = ?, updated_at = NOW() WHERE id = ?',
            [$tanggalSelesai, $actorId, $alasan, $actorId, $id]
        );
    }

    public function setActive(string $jenis, int $id, bool $active, string $alasan, int $actorId): void
    {
        $definisi = PenugasanJenis::definisi($jenis);
        if ($definisi['lama']) {
            // Tabel lama tidak punya kolom alasan perubahan: alasan disimpan
            // pada `catatan` agar tetap terbaca, dan selalu ada di audit.
            $this->execute(
                'UPDATE ' . $definisi['tabel'] . ' SET is_active = ?, catatan = ?, updated_by = ?, updated_at = NOW() WHERE id = ?',
                [$active ? 1 : 0, $alasan, $actorId, $id]
            );

            return;
        }
        $this->execute(
            'UPDATE ' . $definisi['tabel'] . ' SET is_active = ?, alasan_perubahan = ?, updated_by = ?, updated_at = NOW() WHERE id = ?',
            [$active ? 1 : 0, $alasan, $actorId, $id]
        );
    }

    // =======================================================================
    // Validasi referensi
    // =======================================================================

    public function guruAktif(int $id): ?array
    {
        return $this->one('SELECT id, nama_guru AS nama, nip AS keterangan FROM guru WHERE id = ? AND is_active = 1 AND archived_at IS NULL LIMIT 1', [$id]);
    }

    public function pengurusAktif(int $id): ?array
    {
        return $this->one('SELECT id, nama, jabatan AS keterangan FROM pengurus WHERE id = ? AND is_active = 1 AND archived_at IS NULL LIMIT 1', [$id]);
    }

    public function tahunTersedia(int $id): ?array
    {
        return $this->one('SELECT id, tahun, semester, status FROM tahun_ajaran WHERE id = ? AND archived_at IS NULL LIMIT 1', [$id]);
    }

    public function kelasAktif(int $id): ?array
    {
        return $this->one('SELECT id, nama_kelas, jenjang FROM kelas WHERE id = ? AND is_active = 1 AND archived_at IS NULL LIMIT 1', [$id]);
    }

    public function kamarAda(int $id): ?array
    {
        return $this->one('SELECT id, nama_kamar FROM kamar WHERE id = ? LIMIT 1', [$id]);
    }

    public function mataPelajaranAktif(int $id): ?array
    {
        return $this->one('SELECT id, kode, nama FROM mata_pelajaran WHERE id = ? AND is_active = 1 AND archived_at IS NULL LIMIT 1', [$id]);
    }

    public function jenjangTersedia(string $jenjang): bool
    {
        return $this->one('SELECT jenjang FROM kelas WHERE jenjang = ? AND archived_at IS NULL LIMIT 1', [$jenjang]) !== null;
    }

    // =======================================================================
    // Opsi formulir
    // =======================================================================

    /** @return array<int, array<string, mixed>> */
    public function guruOptions(): array
    {
        return $this->all('SELECT id, nama_guru AS nama, nip AS keterangan FROM guru WHERE is_active = 1 AND archived_at IS NULL ORDER BY nama_guru');
    }

    /** @return array<int, array<string, mixed>> */
    public function pengurusOptions(): array
    {
        return $this->all('SELECT id, nama, jabatan AS keterangan FROM pengurus WHERE is_active = 1 AND archived_at IS NULL ORDER BY nama');
    }

    /** @return array<int, array<string, mixed>> */
    public function tahunOptions(): array
    {
        return $this->all('SELECT id, tahun, semester, status FROM tahun_ajaran WHERE archived_at IS NULL ORDER BY tahun DESC, semester DESC, id DESC');
    }

    /** @return array<int, array<string, mixed>> */
    public function kelasOptions(): array
    {
        return $this->all('SELECT id, nama_kelas, jenjang FROM kelas WHERE is_active = 1 AND archived_at IS NULL ORDER BY jenjang, nama_kelas');
    }

    /** @return array<int, array<string, mixed>> */
    public function kamarOptions(): array
    {
        return $this->all('SELECT id, nama_kamar FROM kamar ORDER BY nama_kamar');
    }

    /** @return array<int, array<string, mixed>> */
    public function mataPelajaranOptions(): array
    {
        return $this->all('SELECT id, kode, nama, kategori FROM mata_pelajaran WHERE is_active = 1 AND archived_at IS NULL ORDER BY nama');
    }

    /** @return array<int, string> */
    public function jenjangOptions(): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['jenjang'],
            $this->all("SELECT DISTINCT jenjang FROM kelas WHERE archived_at IS NULL AND TRIM(jenjang) <> '' ORDER BY jenjang")
        );
    }

    // =======================================================================
    // Master mata pelajaran (minimum)
    // =======================================================================

    /**
     * @return array{rows:array<int, array<string, mixed>>, total:int, page:int, perPage:int}
     */
    public function mataPelajaranPage(string $q, int $page): array
    {
        $sql = 'SELECT mp.*, (SELECT COUNT(*) FROM guru_mapel_assignments a WHERE a.mata_pelajaran_id = mp.id) AS jumlah_penugasan FROM mata_pelajaran mp';

        return PageQuery::fetch($this->db, $sql, [], 'archived_at IS NOT NULL, is_active DESC, nama', $page, PageQuery::term($q), ['nama', 'kode', 'kategori']);
    }

    public function mataPelajaranFind(int $id): ?array
    {
        return $this->one('SELECT * FROM mata_pelajaran WHERE id = ? LIMIT 1', [$id]);
    }

    /** @param array{kode:?string, nama:string, kategori:?string} $data */
    public function mataPelajaranInsert(array $data, int $actorId): int
    {
        $this->execute(
            'INSERT INTO mata_pelajaran (kode, nama, kategori, is_active, created_by, updated_by, created_at, updated_at) VALUES (?, ?, ?, 1, ?, ?, NOW(), NOW())',
            [$data['kode'], $data['nama'], $data['kategori'], $actorId, $actorId]
        );

        return (int) $this->db->insert_id;
    }

    /** @param array{kode:?string, nama:string, kategori:?string} $data */
    public function mataPelajaranUpdate(int $id, array $data, int $actorId): void
    {
        $this->execute(
            'UPDATE mata_pelajaran SET kode = ?, nama = ?, kategori = ?, updated_by = ?, updated_at = NOW() WHERE id = ?',
            [$data['kode'], $data['nama'], $data['kategori'], $actorId, $id]
        );
    }

    public function mataPelajaranSetState(int $id, bool $active, ?bool $archive, int $actorId): void
    {
        if ($archive === null) {
            $this->execute('UPDATE mata_pelajaran SET is_active = ?, updated_by = ?, updated_at = NOW() WHERE id = ?', [$active ? 1 : 0, $actorId, $id]);

            return;
        }
        $this->execute(
            'UPDATE mata_pelajaran SET archived_at = ' . ($archive ? 'NOW()' : 'NULL') . ', is_active = ?, updated_by = ?, updated_at = NOW() WHERE id = ?',
            [$archive ? 0 : 1, $actorId, $id]
        );
    }

    // =======================================================================
    // Bagian dalam
    // =======================================================================

    /**
     * @return array<int, string>
     */
    private function scopeColumns(string $cakupan): array
    {
        return match ($cakupan) {
            PenugasanJenis::CAKUPAN_TARGET => ['target_type', 'kamar_id', 'kelas_id'],
            PenugasanJenis::CAKUPAN_MAPEL_KELAS => ['mata_pelajaran_id', 'kelas_id'],
            PenugasanJenis::CAKUPAN_JENJANG => ['jenjang'],
            PenugasanJenis::CAKUPAN_GELOMBANG => ['gelombang'],
            default => throw new RuntimeException('Bentuk cakupan tidak dikenal.'),
        };
    }

    /**
     * SELECT dasar per jenis: subjek, tahun ajaran, cakupan bernama, akun
     * terkait, dan pelaku. Filter yang bukan pencarian bebas dipasang di sini
     * sebagai parameter terikat.
     *
     * @param array<string, mixed> $filters
     * @return array{0:string, 1:array<int, mixed>}
     */
    private function baseSelect(string $jenis, array $filters): array
    {
        $definisi = PenugasanJenis::definisi($jenis);
        $tabel = $definisi['tabel'];
        $subjekJoin = $definisi['subjek'] === 'guru'
            ? 'JOIN guru s ON s.id = a.guru_id'
            : 'JOIN pengurus s ON s.id = a.pengurus_id';
        $subjekKolom = $definisi['subjek'] === 'guru'
            ? 's.nama_guru AS subjek_nama, s.nip AS subjek_keterangan'
            : 's.nama AS subjek_nama, s.jabatan AS subjek_keterangan';
        $userJoin = $definisi['subjek'] === 'guru'
            ? 'LEFT JOIN users u ON u.guru_id = s.id'
            : 'LEFT JOIN users u ON u.pengurus_id = s.id';

        $cakupanKolom = '';
        $cakupanJoin = '';
        $cakupanTeks = "''";
        switch ($definisi['cakupan']) {
            case PenugasanJenis::CAKUPAN_TARGET:
                $cakupanKolom = ', km.nama_kamar AS kamar_nama, kl.nama_kelas AS kelas_nama, kl.jenjang AS kelas_jenjang, kl.is_active AS kelas_aktif, kl.archived_at AS kelas_arsip';
                $cakupanJoin = 'LEFT JOIN kamar km ON km.id = a.kamar_id LEFT JOIN kelas kl ON kl.id = a.kelas_id';
                $cakupanTeks = "CONCAT(a.target_type, ': ', COALESCE(km.nama_kamar, kl.nama_kelas, '-'))";
                break;
            case PenugasanJenis::CAKUPAN_MAPEL_KELAS:
                $cakupanKolom = ', mp.nama AS mapel_nama, mp.kode AS mapel_kode, mp.is_active AS mapel_aktif, mp.archived_at AS mapel_arsip, kl.nama_kelas AS kelas_nama, kl.jenjang AS kelas_jenjang, kl.is_active AS kelas_aktif, kl.archived_at AS kelas_arsip';
                $cakupanJoin = 'JOIN mata_pelajaran mp ON mp.id = a.mata_pelajaran_id JOIN kelas kl ON kl.id = a.kelas_id';
                $cakupanTeks = "CONCAT(mp.nama, ' — ', kl.nama_kelas)";
                break;
            case PenugasanJenis::CAKUPAN_JENJANG:
                $cakupanTeks = "COALESCE(a.jenjang, 'Seluruh unit')";
                break;
            case PenugasanJenis::CAKUPAN_GELOMBANG:
                $cakupanTeks = "COALESCE(CONCAT('Gelombang ', a.gelombang), 'Seluruh gelombang')";
                break;
        }

        $where = ['1 = 1'];
        $params = [];
        $today = date('Y-m-d');
        if (!empty($filters['id'])) {
            $where[] = 'a.id = ?';
            $params[] = (int) $filters['id'];
        }
        if (!empty($filters['subjek_id'])) {
            $where[] = 'a.' . $definisi['subjek_kolom'] . ' = ?';
            $params[] = (int) $filters['subjek_id'];
        }
        if (!empty($filters['tahun_ajaran_id'])) {
            $where[] = 'a.tahun_ajaran_id = ?';
            $params[] = (int) $filters['tahun_ajaran_id'];
        }
        $arsip = $definisi['lama'] ? ' OR a.archived_at IS NOT NULL' : '';
        $hidup = $definisi['lama'] ? 'a.is_active = 1 AND a.archived_at IS NULL' : 'a.is_active = 1';
        switch ((string) ($filters['status'] ?? '')) {
            case 'aktif':
                $where[] = $hidup . ' AND a.tanggal_mulai <= ? AND (a.tanggal_selesai IS NULL OR a.tanggal_selesai >= ?)';
                array_push($params, $today, $today);
                break;
            case 'akan_datang':
                $where[] = $hidup . ' AND a.tanggal_mulai > ?';
                $params[] = $today;
                break;
            case 'berakhir':
                $where[] = $hidup . ' AND a.tanggal_selesai IS NOT NULL AND a.tanggal_selesai < ?';
                $params[] = $today;
                break;
            case 'nonaktif':
                $where[] = '(a.is_active = 0' . $arsip . ')';
                break;
        }
        if ($definisi['cakupan'] === PenugasanJenis::CAKUPAN_TARGET || $definisi['cakupan'] === PenugasanJenis::CAKUPAN_MAPEL_KELAS) {
            if (!empty($filters['kelas_id'])) {
                $where[] = 'a.kelas_id = ?';
                $params[] = (int) $filters['kelas_id'];
            }
        }
        if ($definisi['cakupan'] === PenugasanJenis::CAKUPAN_TARGET && !empty($filters['kamar_id'])) {
            $where[] = 'a.kamar_id = ?';
            $params[] = (int) $filters['kamar_id'];
        }
        if ($definisi['cakupan'] === PenugasanJenis::CAKUPAN_MAPEL_KELAS && !empty($filters['mata_pelajaran_id'])) {
            $where[] = 'a.mata_pelajaran_id = ?';
            $params[] = (int) $filters['mata_pelajaran_id'];
        }
        if ($definisi['cakupan'] === PenugasanJenis::CAKUPAN_JENJANG && trim((string) ($filters['jenjang'] ?? '')) !== '') {
            $where[] = 'a.jenjang = ?';
            $params[] = trim((string) $filters['jenjang']);
        }
        if ($definisi['cakupan'] === PenugasanJenis::CAKUPAN_GELOMBANG && trim((string) ($filters['gelombang'] ?? '')) !== '') {
            $where[] = 'a.gelombang = ?';
            $params[] = trim((string) $filters['gelombang']);
        }

        $archivedKolom = $definisi['lama'] ? 'a.archived_at' : 'NULL AS archived_at';
        $alasanKolom = $definisi['lama'] ? 'NULL AS alasan_perubahan' : 'a.alasan_perubahan';

        $sql = "SELECT a.id, a." . $definisi['subjek_kolom'] . " AS subjek_id, a.tahun_ajaran_id, a.tanggal_mulai, a.tanggal_selesai,
                       a.is_active, {$archivedKolom}, a.catatan, {$alasanKolom}, a.diakhiri_pada, a.diakhiri_oleh, a.alasan_pengakhiran,
                       a.created_by, a.updated_by, a.created_at, a.updated_at,
                       " . ($definisi['cakupan'] === PenugasanJenis::CAKUPAN_TARGET ? 'a.target_type, a.kamar_id, a.kelas_id,' : '') . "
                       " . ($definisi['cakupan'] === PenugasanJenis::CAKUPAN_MAPEL_KELAS ? 'a.mata_pelajaran_id, a.kelas_id,' : '') . "
                       " . ($definisi['cakupan'] === PenugasanJenis::CAKUPAN_JENJANG ? 'a.jenjang,' : '') . "
                       " . ($definisi['cakupan'] === PenugasanJenis::CAKUPAN_GELOMBANG ? 'a.gelombang,' : '') . "
                       {$subjekKolom}, s.is_active AS subjek_aktif, s.archived_at AS subjek_arsip,
                       ta.tahun, ta.semester, ta.status AS tahun_status, ta.archived_at AS tahun_arsip,
                       {$cakupanTeks} AS cakupan_teks{$cakupanKolom},
                       u.id AS user_id, u.username, u.is_active AS user_aktif,
                       (SELECT GROUP_CONCAT(r.slug ORDER BY r.slug SEPARATOR ',')
                          FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id) AS user_roles,
                       pc.name AS created_by_nama, pu.name AS updated_by_nama, pe.name AS diakhiri_oleh_nama
                  FROM {$tabel} a
                  {$subjekJoin}
                  JOIN tahun_ajaran ta ON ta.id = a.tahun_ajaran_id
                  {$cakupanJoin}
                  {$userJoin}
                  LEFT JOIN users pc ON pc.id = a.created_by
                  LEFT JOIN users pu ON pu.id = a.updated_by
                  LEFT JOIN users pe ON pe.id = a.diakhiri_oleh
                 WHERE " . implode(' AND ', $where);

        return [$sql, $params];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function all(string $sql, array $params = []): array
    {
        $statement = $this->db->prepare($sql);
        if ($statement === false || !$this->run($statement, $params)) {
            throw new RuntimeException('Data penugasan tidak dapat dibaca.');
        }
        $result = $statement->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $statement->close();

        return $rows;
    }

    private function one(string $sql, array $params = []): ?array
    {
        return $this->all($sql, $params)[0] ?? null;
    }

    private function scalar(string $sql, array $params = []): int
    {
        return (int) ($this->one($sql, $params)['jumlah'] ?? 0);
    }

    private function execute(string $sql, array $params): void
    {
        $statement = $this->db->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Perintah penugasan tidak dapat disiapkan.');
        }
        if (!$this->run($statement, $params)) {
            $errno = $statement->errno;
            $statement->close();
            if ($errno === 1062) {
                throw PenugasanException::conflict('Penugasan identik (orang, tahun ajaran, cakupan, dan tanggal mulai yang sama) sudah ada.');
            }
            if ($errno === 4025 || $errno === 3819) {
                throw PenugasanException::invalid('Penugasan ditolak oleh aturan basis data. Periksa cakupan dan rentang tanggal.');
            }
            if ($errno === 1452) {
                throw PenugasanException::invalid('Referensi guru/pengurus, tahun ajaran, atau cakupan tidak valid.');
            }
            throw new RuntimeException('Penugasan gagal disimpan.');
        }
        $statement->close();
    }

    private function run(mysqli_stmt $statement, array $params): bool
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
