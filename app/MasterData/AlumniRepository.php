<?php

declare(strict_types=1);

namespace App\MasterData;

use mysqli;
use mysqli_stmt;
use RuntimeException;

/**
 * Repository terpusat arsip alumni dan proses kelulusan/mutasi santri
 * (paket "Koreksi Pengelolaan Alumni", keputusan pengguna 6 September 2026).
 *
 * Sebelum paket ini, seluruh query alumni ditulis langsung di
 * `admin/admin_alumni.php` dan `admin/proses_mutasi_alumni.php` dengan
 * interpolasi string (`WHERE tahun_angkatan = '$_GET[tahun]'`) dan penghapusan
 * permanen lewat GET (`?hapus=ID`). Kelas ini memindahkan SELURUH query alumni
 * ke satu tempat dengan aturan keras:
 *
 *   - hanya prepared statement; tidak ada nilai GET/POST yang masuk ke SQL;
 *   - nama tabel, kolom, dan urutan hanya berasal dari konstanta di berkas ini;
 *   - baris santri dan baris alumni dikunci `FOR UPDATE` sebelum diperiksa,
 *     dengan urutan ID menaik yang sama seperti `PenempatanRepository` supaya
 *     kedua modul tidak saling mengunci;
 *   - TIDAK ADA `DELETE FROM alumni` sama sekali. Catatan alumni hanya dapat
 *     diarsipkan (`archived_at`) dan dipulihkan.
 *
 * Kelas ini tidak melakukan otorisasi dan tidak menulis audit: keduanya milik
 * `AlumniService` dan halaman pemanggil.
 */
final class AlumniRepository
{
    /** Kolom pencarian daftar alumni. Konstanta, bukan input pengguna. */
    private const KOLOM_CARI = ['a.nama_santri', 'a.nis'];

    /**
     * Kolom snapshot yang disalin dari `santri` ke `alumni` saat kelulusan.
     * Urutannya mengikuti urutan parameter pada `createAlumni()`.
     */
    public const KOLOM_SNAPSHOT = [
        'nis', 'nama_santri', 'jenis_kelamin', 'tempat_lahir', 'tgl_lahir',
        'alamat', 'desa', 'kecamatan', 'kab_kota', 'provinsi',
        'nama_ayah', 'no_hp_ayah', 'nama_ibu', 'no_hp_ibu', 'asal_sekolah',
    ];

    public function __construct(private mysqli $db)
    {
    }

    public function db(): mysqli
    {
        return $this->db;
    }

    // -----------------------------------------------------------------------
    // Pembacaan referensi
    // -----------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    public function activeYear(): ?array
    {
        return $this->one("SELECT id, tahun, semester, status FROM tahun_ajaran WHERE status = 'Aktif' AND archived_at IS NULL LIMIT 1");
    }

    /**
     * Kelas yang boleh dipakai sebagai sumber pemrosesan massal.
     *
     * @return array<int, array<string, mixed>>
     */
    public function classOptions(int $yearId): array
    {
        return $this->all(
            "SELECT k.id, k.nama_kelas, k.jenjang,
                    (SELECT COUNT(*) FROM plotting_kelas pk
                      JOIN santri s ON s.id = pk.id_santri
                     WHERE pk.id_kelas = k.id AND pk.id_tahun = ? AND pk.status = 'Aktif'
                       AND s.is_active = 1 AND s.archived_at IS NULL) jumlah_aktif
               FROM kelas k
              WHERE k.archived_at IS NULL
              ORDER BY k.jenjang, k.nama_kelas, k.id",
            [$yearId]
        );
    }

    /** @return array<string, mixed>|null */
    public function classFind(int $id): ?array
    {
        return $this->one('SELECT id, nama_kelas, jenjang, is_active, archived_at FROM kelas WHERE id = ?', [$id]);
    }

    /**
     * ID santri AKTIF yang menempati satu kelas pada tahun ajaran tertentu.
     *
     * @return array<int, int>
     */
    public function activeSantriIdsInClass(int $kelasId, int $yearId): array
    {
        $rows = $this->all(
            "SELECT pk.id_santri
               FROM plotting_kelas pk
               JOIN santri s ON s.id = pk.id_santri
              WHERE pk.id_kelas = ? AND pk.id_tahun = ? AND pk.status = 'Aktif'
                AND s.is_active = 1 AND s.archived_at IS NULL
              ORDER BY pk.id_santri",
            [$kelasId, $yearId]
        );

        return array_map(static fn (array $row): int => (int) $row['id_santri'], $rows);
    }

    // -----------------------------------------------------------------------
    // Santri sumber
    // -----------------------------------------------------------------------

    /**
     * Mengunci baris santri menurut ID menaik. Urutan ini SAMA dengan
     * `PenempatanRepository::lockSantri()` sehingga kedua modul tidak
     * saling mengunci.
     *
     * @param array<int, int> $ids
     * @return array<int, array<string, mixed>> ID santri => baris lengkap
     */
    public function lockSantri(array $ids): array
    {
        return $this->keyed($this->allIn('SELECT * FROM santri WHERE id IN (%s) ORDER BY id FOR UPDATE', $ids), 'id');
    }

    /**
     * Membaca baris santri TANPA mengunci. Hanya untuk layar tinjauan; setiap
     * penerapan selalu membaca ulang dengan `lockSantri()` di dalam transaksi.
     *
     * @param array<int, int> $ids
     * @return array<int, array<string, mixed>>
     */
    public function santriByIds(array $ids): array
    {
        return $this->keyed($this->allIn('SELECT * FROM santri WHERE id IN (%s) ORDER BY id', $ids), 'id');
    }

    /** @return array<string, mixed>|null */
    public function santriFind(int $id): ?array
    {
        return $this->one('SELECT * FROM santri WHERE id = ?', [$id]);
    }

    /**
     * Kelas aktif setiap santri pada satu tahun ajaran.
     *
     * @param array<int, int> $ids
     * @return array<int, array<string, mixed>> ID santri => baris
     */
    public function activeClass(array $ids, int $yearId): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        return $this->keyed(
            $this->all(
                "SELECT pk.id, pk.id_santri, pk.id_kelas, k.nama_kelas, k.jenjang
                   FROM plotting_kelas pk
                   LEFT JOIN kelas k ON k.id = pk.id_kelas
                  WHERE pk.id_tahun = ? AND pk.status = 'Aktif' AND pk.id_santri IN (" . $placeholders . ')
                  ORDER BY pk.id_santri, pk.id',
                [$yearId, ...array_values($ids)]
            ),
            'id_santri'
        );
    }

    /**
     * SELURUH baris kamar milik sekumpulan santri pada satu tahun ajaran.
     *
     * Sengaja mengembalikan seluruh baris, bukan satu: bila data produksi
     * memuat duplikasi warisan, layanan harus melihatnya dan menolak bekerja,
     * bukan diam-diam memilih salah satu.
     *
     * @param array<int, int> $ids
     * @return array<int, array<int, array<string, mixed>>> ID santri => daftar baris
     */
    public function activeRooms(array $ids, int $yearId): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->all(
            'SELECT pkm.id, pkm.id_santri, pkm.id_kamar, km.nama_kamar
               FROM plotting_kamar pkm
               LEFT JOIN kamar km ON km.id = pkm.id_kamar
              WHERE pkm.id_tahun = ? AND pkm.id_santri IN (' . $placeholders . ')
              ORDER BY pkm.id_santri, pkm.id',
            [$yearId, ...array_values($ids)]
        );
        $hasil = [];
        foreach ($rows as $row) {
            $hasil[(int) $row['id_santri']][] = $row;
        }

        return $hasil;
    }

    /**
     * Mengeluarkan santri dari kamar pada tahun ajaran tertentu.
     *
     * `plotting_kamar` warisan V1 tidak punya kolom status, sehingga "keluar"
     * hanya dapat diwakili dengan menghapus baris tahun berjalan — persis
     * seperti `PenempatanRepository::releaseRoomAssignment()`. Baris tahun
     * ajaran LAIN tidak pernah disentuh (klausa `id_tahun`), dan nilai sebelum
     * penghapusan selalu tercatat pada `audit_logs` dan pada snapshot
     * `alumni.kamar_terakhir`.
     */
    public function releaseRoom(int $assignmentId, int $yearId): void
    {
        $this->execute('DELETE FROM plotting_kamar WHERE id = ? AND id_tahun = ?', [$assignmentId, $yearId]);
    }

    /** Menutup penempatan kelas aktif; barisnya TETAP ADA sebagai riwayat. */
    public function endActiveClass(int $santriId, int $yearId, string $endDate): void
    {
        $this->execute(
            "UPDATE plotting_kelas SET status = 'Selesai', tanggal_selesai = ?, updated_at = NOW()
              WHERE id_santri = ? AND id_tahun = ? AND status = 'Aktif'",
            [$endDate, $santriId, $yearId]
        );
    }

    public function setSantriState(int $santriId, bool $active, bool $archive): void
    {
        $this->execute(
            'UPDATE santri SET is_active = ?, archived_at = ?, updated_at = NOW() WHERE id = ?',
            [$active ? 1 : 0, $archive ? date('Y-m-d H:i:s') : null, $santriId]
        );
    }

    // -----------------------------------------------------------------------
    // Catatan alumni
    // -----------------------------------------------------------------------

    /**
     * Mengunci catatan alumni AKTIF milik sekumpulan santri.
     *
     * Dipanggil SETELAH `lockSantri()`. Inilah pemeriksaan duplikasi tingkat
     * aplikasi; kunci unik `alumni_santri_aktif_unique` adalah lapisan kedua di
     * tingkat basis data.
     *
     * @param array<int, int> $santriIds
     * @return array<int, array<string, mixed>> ID santri => baris alumni aktif
     */
    public function lockActiveBySantri(array $santriIds): array
    {
        return $this->keyed(
            $this->allIn(
                'SELECT id, santri_id, nis, nama_santri, status_keluar, tgl_keluar, archived_at
                   FROM alumni WHERE archived_at IS NULL AND santri_id IN (%s) ORDER BY id FOR UPDATE',
                $santriIds
            ),
            'santri_id'
        );
    }

    /**
     * Catatan alumni AKTIF milik sekumpulan santri, TANPA mengunci.
     *
     * Hanya untuk layar tinjauan; setiap penerapan selalu membaca ulang dengan
     * `lockActiveBySantri()` di dalam transaksi.
     *
     * @param array<int, int> $santriIds
     * @return array<int, array<string, mixed>> ID santri => baris alumni aktif
     */
    public function activeBySantri(array $santriIds): array
    {
        return $this->keyed(
            $this->allIn(
                'SELECT id, santri_id, nis, nama_santri, status_keluar, tgl_keluar, archived_at
                   FROM alumni WHERE archived_at IS NULL AND santri_id IN (%s) ORDER BY id',
                $santriIds
            ),
            'santri_id'
        );
    }

    /**
     * Catatan alumni AKTIF yang memakai NIS tertentu, TANPA mengunci.
     *
     * @param array<int, string> $nisList
     * @return array<string, array<string, mixed>> NIS => baris
     */
    public function activeByNis(array $nisList): array
    {
        return $this->byNis($nisList, false);
    }

    /**
     * Catatan alumni AKTIF yang memakai NIS tertentu, apa pun `santri_id`-nya.
     *
     * Diperlukan karena data warisan boleh saja memiliki NIS yang sama tanpa
     * referensi santri. Tanpa pemeriksaan ini, kunci unik
     * `alumni_nis_aktif_unique` akan menolak penyimpanan dengan galat mentah.
     *
     * @param array<int, string> $nisList
     * @return array<string, array<string, mixed>> NIS => baris
     */
    public function lockActiveByNis(array $nisList): array
    {
        return $this->byNis($nisList, true);
    }

    /**
     * @param array<int, string> $nisList
     * @return array<string, array<string, mixed>>
     */
    private function byNis(array $nisList, bool $lock): array
    {
        $nisList = array_values(array_unique(array_filter(
            array_map(static fn (mixed $nis): string => (string) $nis, $nisList),
            static fn (string $nis): bool => $nis !== ''
        )));
        sort($nisList);
        if ($nisList === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($nisList), '?'));
        $rows = $this->all(
            'SELECT id, santri_id, nis, nama_santri, status_keluar, tgl_keluar
               FROM alumni WHERE archived_at IS NULL AND nis IN (' . $placeholders . ') ORDER BY id'
            . ($lock ? ' FOR UPDATE' : ''),
            $nisList
        );
        $hasil = [];
        foreach ($rows as $row) {
            $hasil[(string) $row['nis']] = $row;
        }

        return $hasil;
    }

    /**
     * Menyimpan snapshot alumni.
     *
     * @param array<string, mixed> $santri baris `santri` lengkap
     * @param array<string, mixed> $data status_keluar, tgl_keluar, tahun_angkatan,
     *        tingkat, unit_terakhir, kelas_terakhir, kamar_terakhir, catatan
     */
    public function createAlumni(array $santri, array $data, int $actorId): int
    {
        return $this->insert(
            'INSERT INTO alumni (
                santri_id, nis, nama_santri, jenis_kelamin, tempat_lahir, tgl_lahir,
                alamat, desa, kecamatan, kab_kota, provinsi,
                nama_ayah, no_hp_ayah, nama_ibu, no_hp_ibu, asal_sekolah,
                unit_terakhir, kelas_terakhir, kamar_terakhir,
                tahun_angkatan, tingkat, status_keluar, tgl_keluar, foto,
                catatan, created_by, updated_by, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                (int) $santri['id'],
                (string) $santri['nis'],
                (string) $santri['nama_santri'],
                (string) $santri['jenis_kelamin'],
                (string) $santri['tempat_lahir'],
                (string) $santri['tgl_lahir'],
                (string) $santri['alamat'],
                (string) $santri['desa'],
                (string) $santri['kecamatan'],
                (string) $santri['kab_kota'],
                (string) $santri['provinsi'],
                (string) $santri['nama_ayah'],
                ($santri['no_hp_ayah'] ?? '') === '' ? null : (string) $santri['no_hp_ayah'],
                (string) $santri['nama_ibu'],
                ($santri['no_hp_ibu'] ?? '') === '' ? null : (string) $santri['no_hp_ibu'],
                (string) $santri['asal_sekolah'],
                (string) $data['unit_terakhir'],
                $data['kelas_terakhir'],
                $data['kamar_terakhir'],
                (string) $data['tahun_angkatan'],
                (string) $data['tingkat'],
                (string) $data['status_keluar'],
                (string) $data['tgl_keluar'],
                ((string) ($santri['foto'] ?? '')) === '' ? 'default.jpg' : (string) $santri['foto'],
                $data['catatan'],
                $actorId,
                $actorId,
            ]
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->one(
            'SELECT a.*, s.is_active santri_aktif, s.archived_at santri_archived_at, s.nama_santri santri_nama_terkini,
                    up.name dibuat_oleh, uu.name diubah_oleh
               FROM alumni a
               LEFT JOIN santri s ON s.id = a.santri_id
               LEFT JOIN users up ON up.id = a.created_by
               LEFT JOIN users uu ON uu.id = a.updated_by
              WHERE a.id = ?',
            [$id]
        );
    }

    /** @return array<string, mixed>|null */
    public function lockAlumni(int $id): ?array
    {
        return $this->one('SELECT * FROM alumni WHERE id = ? FOR UPDATE', [$id]);
    }

    /**
     * Daftar alumni dengan filter dan pagination server.
     *
     * Seluruh nilai filter dikirim sebagai parameter terikat. Kolom pencarian
     * berasal dari konstanta `KOLOM_CARI`, bukan dari URL.
     *
     * @param array<string, mixed> $filters keluaran `AlumniService::normalizeFilters()`
     * @return array{rows:array<int, array<string, mixed>>, total:int, page:int, perPage:int}
     */
    public function listPage(array $filters, int $page, int $perPage): array
    {
        $perPage = max(10, min(100, $perPage));
        $page = max(1, $page);
        [$where, $params] = $this->listWhere($filters);

        $from = ' FROM alumni a LEFT JOIN users up ON up.id = a.created_by';
        $total = (int) ($this->one('SELECT COUNT(*) jumlah' . $from . $where, $params)['jumlah'] ?? 0);

        $offset = ($page - 1) * $perPage;
        $rows = $this->all(
            'SELECT a.*, up.name dibuat_oleh' . $from . $where
            . ' ORDER BY a.tgl_keluar DESC, a.id DESC LIMIT ? OFFSET ?',
            [...$params, $perPage, $offset]
        );

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'perPage' => $perPage];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0:string, 1:array<int, mixed>}
     */
    private function listWhere(array $filters): array
    {
        $klausa = [];
        $params = [];

        // Nilai filter TIDAK PERNAH disambung ke potongan SQL. Ia hanya memilih
        // salah satu klausa tetap di bawah, atau menjadi parameter terikat.
        $state = (string) ($filters['state'] ?? 'active');
        $klausa[] = match ($state) {
            'archived' => 'a.archived_at IS NOT NULL',
            'all' => '1 = 1',
            default => 'a.archived_at IS NULL',
        };

        if (($filters['q'] ?? '') !== '') {
            $bagian = [];
            foreach (self::KOLOM_CARI as $kolom) {
                $bagian[] = $kolom . ' LIKE ?';
                $params[] = '%' . $filters['q'] . '%';
            }
            $klausa[] = '(' . implode(' OR ', $bagian) . ')';
        }
        if (($filters['status'] ?? '') !== '') {
            $klausa[] = 'a.status_keluar = ?';
            $params[] = $filters['status'];
        }
        if (($filters['tahun'] ?? '') !== '') {
            $klausa[] = 'a.tahun_angkatan = ?';
            $params[] = $filters['tahun'];
        }
        if (($filters['tingkat'] ?? '') !== '') {
            $klausa[] = 'a.tingkat = ?';
            $params[] = $filters['tingkat'];
        }
        if (($filters['tautan'] ?? '') === 'tanpa_santri') {
            $klausa[] = 'a.santri_id IS NULL';
        } elseif (($filters['tautan'] ?? '') === 'dengan_santri') {
            $klausa[] = 'a.santri_id IS NOT NULL';
        }

        return [' WHERE ' . implode(' AND ', $klausa), $params];
    }

    /**
     * Nilai tahun angkatan yang benar-benar ada. Dipakai mengisi dropdown
     * filter sehingga admin tidak menebak.
     *
     * @return array<int, string>
     */
    public function yearOptions(): array
    {
        $rows = $this->all("SELECT DISTINCT tahun_angkatan FROM alumni WHERE TRIM(tahun_angkatan) <> '' ORDER BY tahun_angkatan DESC");

        return array_map(static fn (array $row): string => (string) $row['tahun_angkatan'], $rows);
    }

    /** @return array{aktif:int, arsip:int, tanpa_santri:int} */
    public function summary(): array
    {
        $row = $this->one(
            'SELECT SUM(archived_at IS NULL) aktif,
                    SUM(archived_at IS NOT NULL) arsip,
                    SUM(archived_at IS NULL AND santri_id IS NULL) tanpa_santri
               FROM alumni'
        ) ?? [];

        return [
            'aktif' => (int) ($row['aktif'] ?? 0),
            'arsip' => (int) ($row['arsip'] ?? 0),
            'tanpa_santri' => (int) ($row['tanpa_santri'] ?? 0),
        ];
    }

    /**
     * Memperbarui kolom alumni yang boleh dikoreksi admin.
     *
     * Daftar kolomnya TETAP dan ditulis di sini, bukan berasal dari kunci
     * array masukan: tidak ada kolom lain yang dapat ikut terganti.
     *
     * @param array<string, mixed> $data
     */
    public function updateAlumni(int $id, array $data, int $actorId): void
    {
        $this->execute(
            'UPDATE alumni SET status_keluar = ?, tgl_keluar = ?, tahun_angkatan = ?, tingkat = ?,
                    unit_terakhir = ?, kelas_terakhir = ?, kamar_terakhir = ?, catatan = ?,
                    updated_by = ?, updated_at = NOW()
              WHERE id = ?',
            [
                (string) $data['status_keluar'],
                (string) $data['tgl_keluar'],
                (string) $data['tahun_angkatan'],
                (string) $data['tingkat'],
                (string) $data['unit_terakhir'],
                $data['kelas_terakhir'],
                $data['kamar_terakhir'],
                $data['catatan'],
                $actorId,
                $id,
            ]
        );
    }

    /** Mengarsipkan catatan alumni. TIDAK ada baris yang dihapus. */
    public function archive(int $id, string $jenis, string $alasan, int $actorId): void
    {
        $this->execute(
            'UPDATE alumni SET archived_at = NOW(), jenis_arsip = ?, alasan_arsip = ?, updated_by = ?, updated_at = NOW() WHERE id = ?',
            [$jenis, $alasan, $actorId, $id]
        );
    }

    /** Memulihkan catatan alumni yang diarsipkan. */
    public function restore(int $id, string $alasan, int $actorId): void
    {
        $this->execute(
            'UPDATE alumni SET archived_at = NULL, jenis_arsip = NULL, alasan_arsip = ?, updated_by = ?, updated_at = NOW() WHERE id = ?',
            [$alasan, $actorId, $id]
        );
    }

    /**
     * Memasangkan catatan alumni warisan dengan santri sumbernya.
     *
     * `$actorId` boleh null: `bin/alumni_backfill.php` berjalan dari CLI tanpa
     * sesi admin, dan menulis 0 ke `updated_by` akan melanggar kunci asing
     * `alumni_updater_fk`.
     */
    public function attachSantri(int $id, ?int $santriId, ?int $actorId): void
    {
        $this->execute(
            'UPDATE alumni SET santri_id = ?, updated_by = ?, updated_at = NOW() WHERE id = ?',
            [$santriId, $actorId, $id]
        );
    }

    // -----------------------------------------------------------------------
    // Laporan konsistensi (hanya membaca) — dipakai bin/alumni_preflight.php
    // -----------------------------------------------------------------------

    /**
     * Catatan alumni aktif yang belum punya referensi santri, beserta jumlah
     * santri yang NIS-nya cocok persis.
     *
     * @return array<int, array<string, mixed>>
     */
    public function reportUnlinked(int $limit = 500): array
    {
        return $this->all(
            'SELECT a.id, a.nis, a.nama_santri, a.tahun_angkatan, a.status_keluar,
                    (SELECT COUNT(*) FROM santri s WHERE s.nis = a.nis) kandidat,
                    (SELECT MIN(s.id) FROM santri s WHERE s.nis = a.nis) kandidat_id
               FROM alumni a
              WHERE a.santri_id IS NULL AND a.archived_at IS NULL
              ORDER BY a.id
              LIMIT ' . $this->limit($limit)
        );
    }

    /**
     * Santri yang punya lebih dari satu catatan alumni aktif. Kondisi ini tidak
     * mungkin muncul setelah migrasi 011 terpasang, tetapi tetap diperiksa
     * karena migrasi bisa saja belum dijalankan.
     *
     * @return array<int, array<string, mixed>>
     */
    public function reportDuplicateActive(int $limit = 200): array
    {
        return $this->all(
            'SELECT santri_id, COUNT(*) jumlah, GROUP_CONCAT(id ORDER BY id) daftar_id
               FROM alumni
              WHERE santri_id IS NOT NULL AND archived_at IS NULL
              GROUP BY santri_id HAVING COUNT(*) > 1
              ORDER BY santri_id
              LIMIT ' . $this->limit($limit)
        );
    }

    /**
     * Santri yang sudah punya catatan alumni aktif tetapi barisnya masih aktif
     * pada master data — keadaan saling bertentangan yang harus dilihat admin.
     *
     * @return array<int, array<string, mixed>>
     */
    public function reportActiveSantriWithAlumni(int $limit = 200): array
    {
        return $this->all(
            'SELECT a.id alumni_id, a.nis, a.nama_santri, s.id santri_id, s.is_active, s.archived_at
               FROM alumni a
               JOIN santri s ON s.id = a.santri_id
              WHERE a.archived_at IS NULL AND s.is_active = 1 AND s.archived_at IS NULL
              ORDER BY a.id
              LIMIT ' . $this->limit($limit)
        );
    }

    /**
     * Alumni aktif yang masih memegang penempatan kamar pada tahun ajaran
     * aktif — sisa data dari proses lama yang tidak menutup kamar.
     *
     * @return array<int, array<string, mixed>>
     */
    public function reportAlumniStillInRoom(int $yearId, int $limit = 200): array
    {
        return $this->all(
            'SELECT a.id alumni_id, a.nis, a.nama_santri, km.nama_kamar
               FROM alumni a
               JOIN plotting_kamar pkm ON pkm.id_santri = a.santri_id AND pkm.id_tahun = ?
               LEFT JOIN kamar km ON km.id = pkm.id_kamar
              WHERE a.archived_at IS NULL AND a.santri_id IS NOT NULL
              ORDER BY a.id
              LIMIT ' . $this->limit($limit),
            [$yearId]
        );
    }

    /**
     * Alumni aktif yang masih memegang penempatan KELAS aktif pada tahun
     * ajaran aktif.
     *
     * @return array<int, array<string, mixed>>
     */
    public function reportAlumniStillInClass(int $yearId, int $limit = 200): array
    {
        return $this->all(
            "SELECT a.id alumni_id, a.nis, a.nama_santri, k.nama_kelas
               FROM alumni a
               JOIN plotting_kelas pk ON pk.id_santri = a.santri_id AND pk.id_tahun = ? AND pk.status = 'Aktif'
               LEFT JOIN kelas k ON k.id = pk.id_kelas
              WHERE a.archived_at IS NULL AND a.santri_id IS NOT NULL
              ORDER BY a.id
              LIMIT " . $this->limit($limit),
            [$yearId]
        );
    }

    /**
     * Apakah kolom migrasi 011 sudah terpasang. Dipakai preflight agar pesannya
     * jelas, bukan galat SQL mentah.
     */
    public function schemaSiap(): bool
    {
        $row = $this->one(
            "SELECT COUNT(*) jumlah FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumni'
                AND COLUMN_NAME IN ('santri_id', 'archived_at', 'santri_aktif_guard', 'nis_aktif_guard')"
        );

        return (int) ($row['jumlah'] ?? 0) === 4;
    }

    // -----------------------------------------------------------------------
    // Utilitas SQL
    // -----------------------------------------------------------------------

    private function limit(int $limit): int
    {
        return max(1, min(1000, $limit));
    }

    /**
     * @param array<int, int> $ids
     * @return array<int, array<string, mixed>>
     */
    private function allIn(string $template, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        if ($ids === []) {
            return [];
        }

        return $this->all(sprintf($template, implode(',', array_fill(0, count($ids), '?'))), $ids);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function keyed(array $rows, string $column): array
    {
        $hasil = [];
        foreach ($rows as $row) {
            $hasil[(int) $row[$column]] = $row;
        }

        return $hasil;
    }

    private function execute(string $sql, array $params = []): void
    {
        $statement = $this->db->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Query alumni tidak dapat disiapkan.');
        }
        if (!$this->run($statement, $params)) {
            $errno = $statement->errno;
            $error = $statement->error;
            $statement->close();
            $this->fail($errno, $error);
        }
        $statement->close();
    }

    private function insert(string $sql, array $params): int
    {
        $this->execute($sql, $params);

        return (int) $this->db->insert_id;
    }

    /** @return array<string, mixed>|null */
    private function one(string $sql, array $params = []): ?array
    {
        return $this->all($sql, $params)[0] ?? null;
    }

    /** @return array<int, array<string, mixed>> */
    private function all(string $sql, array $params = []): array
    {
        $statement = $this->db->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Query alumni tidak dapat disiapkan.');
        }
        if (!$this->run($statement, $params)) {
            $errno = $statement->errno;
            $error = $statement->error;
            $statement->close();
            $this->fail($errno, $error);
        }
        $result = $statement->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $statement->close();

        return $rows;
    }

    /**
     * Galat basis data tidak pernah dikirim mentah ke pengguna: pesan MySQL
     * dapat memuat nilai kolom. Konflik kunci diterjemahkan menjadi permintaan
     * mencoba lagi; sisanya menjadi pesan umum dan detailnya hanya masuk log
     * server.
     */
    private function fail(int $errno, string $error): never
    {
        if (in_array($errno, AlumniService::KODE_KONFLIK_KUNCI, true)) {
            throw new AlumniConflictException(
                'Proses alumni dibatalkan karena ada perubahan bersamaan pada santri yang sama. '
                . 'Tidak ada satu pun perubahan yang tersimpan. Muat ulang halaman lalu coba lagi.'
            );
        }
        if ($errno === AlumniService::KODE_KUNCI_GANDA) {
            throw new AlumniConflictException(
                'Proses alumni dibatalkan karena santri itu baru saja diproses oleh permintaan lain. '
                . 'Tidak ada catatan alumni ganda yang dibuat. Muat ulang halaman lalu periksa keadaannya.'
            );
        }
        error_log('Query alumni gagal (' . $errno . '): ' . $error);

        throw new RuntimeException('Perubahan data alumni gagal disimpan.');
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
