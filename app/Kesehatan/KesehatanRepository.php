<?php

declare(strict_types=1);

namespace App\Kesehatan;

use App\Database\PageQuery;
use App\Database\Schema;
use mysqli;
use mysqli_stmt;
use Throwable;

/**
 * Akses basis data kesehatan santri (PRD V5 Fase 1, migrasi 024).
 *
 * Seluruh SQL memakai parameter terikat. Repository tidak memutuskan hak:
 * `KesehatanService` memeriksa capability dan memanggil metode di sini hanya
 * setelah akses sah. Isi klinis (keluhan, catatan klinis, tindakan) hanya
 * dibaca oleh `kunjungan()` untuk halaman/endpoint detail yang tercatat di
 * `kesehatan_akses_log`; daftar tidak memuat kolom klinis.
 */
final class KesehatanRepository
{
    public const TABEL = ['kesehatan_assignments', 'kesehatan_kunjungan', 'kesehatan_kunjungan_versi', 'kesehatan_idempotensi', 'kesehatan_akses_log'];

    public function __construct(private mysqli $db)
    {
    }

    /** Migrasi 024 terpasang (lima tabel). */
    public function skemaSiap(): bool
    {
        foreach (self::TABEL as $tabel) {
            if (!Schema::hasTable($this->db, $tabel)) {
                return false;
            }
        }

        return true;
    }

    public function transaction(callable $work): mixed
    {
        if (!$this->db->begin_transaction()) {
            throw new KesehatanException('Transaksi tidak tersedia.', 503);
        }
        try {
            $result = $work();
            if (!$this->db->commit()) {
                throw new KesehatanException('Transaksi tidak dapat disimpan.', 503);
            }

            return $result;
        } catch (Throwable $exception) {
            $this->db->rollback();
            throw $exception;
        }
    }

    /**
     * Penugasan kesehatan AKTIF milik akun, dibaca dengan kunci baca di dalam
     * transaksi pencatatan. Admin yang mengakhiri/menonaktifkan penugasan
     * memerlukan kunci tulis pada baris yang sama, sehingga keduanya
     * berurutan: pencatatan yang menunggu membaca keadaan terbaru dan ditolak
     * bila penugasan sudah tidak berlaku. Syaratnya sama dengan resolver
     * `Capabilities` untuk jenis kesehatan (akun aktif, peran pengurus,
     * pengurus aktif, penugasan aktif pada tanggal berjalan, tahun ajaran
     * tidak diarsipkan).
     *
     * @return array{id:int, pengurus_id:int}|null
     */
    public function kunciPenugasanAktif(int $userId): ?array
    {
        $row = $this->one(
            "SELECT a.id, a.pengurus_id
               FROM users u
               JOIN user_roles ur ON ur.user_id = u.id
               JOIN roles r ON r.id = ur.role_id AND r.slug = 'pengurus'
               JOIN pengurus p ON p.id = u.pengurus_id
               JOIN kesehatan_assignments a ON a.pengurus_id = p.id
               JOIN tahun_ajaran ta ON ta.id = a.tahun_ajaran_id
              WHERE u.id = ? AND u.is_active = 1
                AND p.is_active = 1 AND p.archived_at IS NULL
                AND a.is_active = 1
                AND a.tanggal_mulai <= CURDATE()
                AND (a.tanggal_selesai IS NULL OR a.tanggal_selesai >= CURDATE())
                AND ta.archived_at IS NULL
              ORDER BY a.id
              LIMIT 1
              LOCK IN SHARE MODE",
            [$userId]
        );

        return $row === null ? null : ['id' => (int) $row['id'], 'pengurus_id' => (int) $row['pengurus_id']];
    }

    /** @return array{id:int, nis:string, nama_santri:string}|null */
    public function santriAktif(int $santriId, bool $kunci = false): ?array
    {
        $row = $this->one(
            'SELECT id, nis, nama_santri FROM santri WHERE id = ? AND is_active = 1 AND archived_at IS NULL'
            . ($kunci ? ' LOCK IN SHARE MODE' : ''),
            [$santriId]
        );

        return $row === null ? null : ['id' => (int) $row['id'], 'nis' => (string) $row['nis'], 'nama_santri' => (string) $row['nama_santri']];
    }

    /**
     * Santri aktif untuk pemilih formulir (paling banyak 100 baris).
     *
     * @return array{rows:array<int, array<string, mixed>>, total:int}
     */
    public function santriPilihan(string $q, int $limit = 100): array
    {
        $where = 's.is_active = 1 AND s.archived_at IS NULL';
        $params = [];
        if ($q !== '') {
            $where .= ' AND (s.nama_santri LIKE ? OR s.nis LIKE ?)';
            $params = ['%' . $q . '%', '%' . $q . '%'];
        }
        $total = (int) ($this->one('SELECT COUNT(*) AS n FROM santri s WHERE ' . $where, $params)['n'] ?? 0);
        $rows = $this->all(
            'SELECT s.id, s.nis, s.nama_santri FROM santri s WHERE ' . $where . ' ORDER BY s.nama_santri, s.id LIMIT ?',
            [...$params, max(1, min(100, $limit))]
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Klaim kunci idempotensi. Baris pertama dibuat kosong; permintaan yang
     * sama berikutnya menunggu kunci baris ini sampai transaksi pertama
     * selesai, lalu membaca kunjungan yang tercatat.
     *
     * @return array{id:int, request_hash:string, kunjungan_id:?int}
     */
    public function klaimIdempotensi(int $userId, string $operasi, string $kunci, string $hash): array
    {
        $this->execute(
            'INSERT INTO kesehatan_idempotensi (user_id, operasi, kunci, request_hash)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)',
            [$userId, $operasi, $kunci, $hash]
        );
        $row = $this->one(
            'SELECT id, request_hash, kunjungan_id FROM kesehatan_idempotensi
              WHERE user_id = ? AND operasi = ? AND kunci = ? FOR UPDATE',
            [$userId, $operasi, $kunci]
        ) ?? throw new KesehatanException('Idempotensi tidak tersedia.', 503);

        return [
            'id' => (int) $row['id'],
            'request_hash' => (string) $row['request_hash'],
            'kunjungan_id' => $row['kunjungan_id'] === null ? null : (int) $row['kunjungan_id'],
        ];
    }

    public function selesaikanIdempotensi(int $id, int $kunjunganId, int $status): void
    {
        $this->execute(
            'UPDATE kesehatan_idempotensi SET kunjungan_id = ?, status_code = ? WHERE id = ?',
            [$kunjunganId, $status, $id]
        );
    }

    /**
     * @param array{santri_id:int, waktu_kunjungan:string, status:string, keluhan:string,
     *     catatan_klinis:?string, tindakan:?string, idempotency_key:string} $data
     */
    public function simpanKunjungan(array $data, int $userId, int $pengurusId, int $penugasanId): int
    {
        $this->execute(
            'INSERT INTO kesehatan_kunjungan
                 (santri_id, waktu_kunjungan, status, versi, keluhan, catatan_klinis, tindakan,
                  petugas_user_id, petugas_pengurus_id, penugasan_id, idempotency_key, created_by, updated_by)
             VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $data['santri_id'], $data['waktu_kunjungan'], $data['status'], $data['keluhan'], $data['catatan_klinis'], $data['tindakan'],
                $userId, $pengurusId, $penugasanId, $data['idempotency_key'], $userId, $userId,
            ]
        );
        $id = (int) $this->db->insert_id;
        $this->execute(
            'INSERT INTO kesehatan_kunjungan_versi
                 (kunjungan_id, versi, status, waktu_kunjungan, keluhan, catatan_klinis, tindakan, alasan, dibuat_oleh)
             VALUES (?, 1, ?, ?, ?, ?, ?, NULL, ?)',
            [$id, $data['status'], $data['waktu_kunjungan'], $data['keluhan'], $data['catatan_klinis'], $data['tindakan'], $userId]
        );

        return $id;
    }

    /**
     * Detail satu kunjungan termasuk isi klinis.
     *
     * @return array<string, mixed>|null
     */
    public function kunjungan(int $id): ?array
    {
        return $this->one(
            'SELECT k.id, k.santri_id, k.waktu_kunjungan, k.status, k.versi, k.keluhan, k.catatan_klinis, k.tindakan,
                    k.petugas_user_id, k.created_at, k.updated_at,
                    s.nis, s.nama_santri, s.is_active AS santri_aktif, s.archived_at AS santri_arsip,
                    COALESCE(p.nama, u.name) AS petugas_nama
               FROM kesehatan_kunjungan k
               JOIN santri s ON s.id = k.santri_id
               JOIN users u ON u.id = k.petugas_user_id
               LEFT JOIN pengurus p ON p.id = k.petugas_pengurus_id
              WHERE k.id = ?',
            [$id]
        );
    }

    /**
     * Daftar kunjungan TANPA kolom klinis. Satu baris per kunjungan.
     *
     * @param array{q:string, santri_id:int, status:string} $filters
     * @return array{rows:array<int, array<string, mixed>>, total:int, page:int, perPage:int}
     */
    public function daftar(array $filters, int $page): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($filters['santri_id'] > 0) {
            $where[] = 'k.santri_id = ?';
            $params[] = $filters['santri_id'];
        }
        if ($filters['status'] !== '') {
            $where[] = 'k.status = ?';
            $params[] = $filters['status'];
        }
        $sql = 'SELECT k.id, k.santri_id, k.waktu_kunjungan, k.status, k.versi, k.created_at,
                       s.nis, s.nama_santri, COALESCE(p.nama, u.name) AS petugas_nama
                  FROM kesehatan_kunjungan k
                  JOIN santri s ON s.id = k.santri_id
                  JOIN users u ON u.id = k.petugas_user_id
                  LEFT JOIN pengurus p ON p.id = k.petugas_pengurus_id
                 WHERE ' . implode(' AND ', $where);

        return PageQuery::fetch($this->db, $sql, $params, 'waktu_kunjungan DESC, id DESC', max(1, $page), $filters['q'], ['nama_santri', 'nis']);
    }

    public function catatAkses(int $kunjunganId, int $santriId, int $userId, string $sumber, string $aksi, string $kanal): void
    {
        $this->execute(
            'INSERT INTO kesehatan_akses_log (kunjungan_id, santri_id, user_id, sumber, aksi, kanal) VALUES (?, ?, ?, ?, ?, ?)',
            [$kunjunganId, $santriId, $userId, $sumber, $aksi, $kanal]
        );
    }

    /** @return array{rows:array<int, array<string, mixed>>, total:int, page:int, perPage:int} */
    public function riwayatAkses(int $kunjunganId, int $page): array
    {
        return PageQuery::fetch(
            $this->db,
            'SELECT l.id, l.user_id, l.sumber, l.aksi, l.kanal, l.created_at, u.name AS pengguna_nama, u.username
               FROM kesehatan_akses_log l
               JOIN users u ON u.id = l.user_id
              WHERE l.kunjungan_id = ?',
            [$kunjunganId],
            'created_at DESC, id DESC',
            max(1, $page)
        );
    }

    // =======================================================================

    /** @return array<string, mixed>|null */
    private function one(string $sql, array $params = []): ?array
    {
        return $this->all($sql, $params)[0] ?? null;
    }

    /** @return array<int, array<string, mixed>> */
    private function all(string $sql, array $params = []): array
    {
        $statement = $this->prepare($sql);
        try {
            $this->run($statement, $params);
            $result = $statement->get_result();

            return $result === false ? [] : $result->fetch_all(MYSQLI_ASSOC);
        } finally {
            $statement->close();
        }
    }

    private function execute(string $sql, array $params): void
    {
        $statement = $this->prepare($sql);
        try {
            $this->run($statement, $params);
        } finally {
            $statement->close();
        }
    }

    private function prepare(string $sql): mysqli_stmt
    {
        $statement = $this->db->prepare($sql);
        if ($statement === false) {
            throw new KesehatanException('Data kesehatan tidak tersedia.', 503);
        }

        return $statement;
    }

    private function run(mysqli_stmt $statement, array $params): void
    {
        if ($params !== []) {
            $types = implode('', array_map(static fn (mixed $v): string => is_int($v) ? 'i' : 's', $params));
            $statement->bind_param($types, ...$params);
        }
        if (!$statement->execute()) {
            // Kunci/deadlock: pemanggil boleh mengulang; pesan tidak memuat nilai masukan.
            if (in_array($statement->errno, [1205, 1213], true)) {
                throw new KesehatanException('Data sedang diproses permintaan lain. Coba lagi.', 409);
            }
            throw new KesehatanException('Data kesehatan tidak dapat disimpan.', 503);
        }
    }
}
