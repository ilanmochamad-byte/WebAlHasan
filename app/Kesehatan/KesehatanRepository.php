<?php

declare(strict_types=1);

namespace App\Kesehatan;

use App\Database\PageQuery;
use App\Database\Schema;
use mysqli;
use mysqli_stmt;
use Throwable;

/**
 * Akses basis data kesehatan santri (PRD V5 Fase 1–2, migrasi 024 dan 025).
 *
 * Seluruh SQL memakai parameter terikat. Repository tidak memutuskan hak:
 * `KesehatanService` memeriksa capability dan memanggil metode di sini hanya
 * setelah akses sah. Isi klinis (keluhan, catatan klinis, tindakan, obat,
 * rujukan, tindak lanjut) hanya dibaca oleh `kunjungan()`, `rincianAktif()`,
 * dan `versi()` untuk halaman/endpoint yang tercatat di `kesehatan_akses_log`;
 * daftar tidak memuat kolom klinis, hanya penanda jumlah.
 *
 * Cakupan pembimbing/murobi (Fase 2) memakai aturan yang sama dengan profil
 * santri binaan (`ProfilSantriService::binaanSql`) dan pembinaan V3: akun
 * aktif + role dasar + data master aktif + penugasan aktif hari ini + tahun
 * ajaran aktif + penempatan santri aktif di kamar/kelas binaan. Klausa itu
 * dipasang langsung pada SQL daftar/detail sehingga hak diperiksa per baris.
 */
final class KesehatanRepository
{
    public const TABEL = ['kesehatan_assignments', 'kesehatan_kunjungan', 'kesehatan_kunjungan_versi', 'kesehatan_idempotensi', 'kesehatan_akses_log',
        'kesehatan_obat', 'kesehatan_rujukan', 'kesehatan_tindak_lanjut', 'kesehatan_versi_rincian'];

    public const BINAAN_PEMBIMBING = 'pembimbing';
    public const BINAAN_MUROBI = 'murobi';

    public function __construct(private mysqli $db)
    {
    }

    /** Migrasi 024 dan 025 terpasang (sembilan tabel). */
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
     * Kunjungan baru + snapshot isi versi 1. Rincian (obat, rujukan, tindak
     * lanjut) disimpan terpisah lewat `terapkanRincian()`.
     *
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
        $this->simpanVersi($id, 1, $data, null, $userId);

        return $id;
    }

    /**
     * Snapshot isi utama satu versi (alasan NULL hanya untuk versi 1).
     *
     * @param array{status:string, waktu_kunjungan:string, keluhan:string, catatan_klinis:?string, tindakan:?string} $data
     */
    public function simpanVersi(int $kunjunganId, int $versi, array $data, ?string $alasan, int $userId): void
    {
        $this->execute(
            'INSERT INTO kesehatan_kunjungan_versi
                 (kunjungan_id, versi, status, waktu_kunjungan, keluhan, catatan_klinis, tindakan, alasan, dibuat_oleh)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$kunjunganId, $versi, $data['status'], $data['waktu_kunjungan'], $data['keluhan'], $data['catatan_klinis'], $data['tindakan'], $alasan, $userId]
        );
    }

    /**
     * Snapshot rincian satu versi (JSON obat/rujukan/tindak lanjut aktif).
     *
     * @param array{obat:array<int, array<string, mixed>>, rujukan:array<int, array<string, mixed>>, tindak_lanjut:array<int, array<string, mixed>>} $rincian
     */
    public function simpanRincianVersi(int $kunjunganId, int $versi, array $rincian, ?int $idempotensiId, int $userId): void
    {
        $this->execute(
            'INSERT INTO kesehatan_versi_rincian (kunjungan_id, versi, rincian, idempotensi_id, dibuat_oleh) VALUES (?, ?, ?, ?, ?)',
            [$kunjunganId, $versi, (string) json_encode($rincian, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $idempotensiId, $userId]
        );
    }

    /** Versi yang dihasilkan satu klaim idempotensi (pengulangan koreksi). */
    public function versiDariIdempotensi(int $idempotensiId): ?int
    {
        $row = $this->one('SELECT versi FROM kesehatan_versi_rincian WHERE idempotensi_id = ?', [$idempotensiId]);

        return $row === null ? null : (int) $row['versi'];
    }

    /**
     * Baris kunjungan dikunci tulis untuk koreksi. Dua koreksi bersamaan
     * berurutan di sini; yang kedua membaca versi terbaru dan ditolak 409.
     *
     * @return array<string, mixed>|null
     */
    public function kunciKunjungan(int $id): ?array
    {
        return $this->one(
            'SELECT id, santri_id, waktu_kunjungan, status, versi, keluhan, catatan_klinis, tindakan FROM kesehatan_kunjungan WHERE id = ? FOR UPDATE',
            [$id]
        );
    }

    /**
     * Isi utama kunjungan diganti ke versi baru. Penjaga versi di SQL
     * (`AND versi = ?`) melengkapi kunci baris: 0 baris berubah = konflik.
     *
     * @param array{status:string, waktu_kunjungan:string, keluhan:string, catatan_klinis:?string, tindakan:?string} $data
     */
    public function perbaruiKunjungan(int $id, int $versiLama, int $versiBaru, array $data, int $userId): bool
    {
        $statement = $this->prepare(
            'UPDATE kesehatan_kunjungan
                SET status = ?, waktu_kunjungan = ?, keluhan = ?, catatan_klinis = ?, tindakan = ?, versi = ?, updated_by = ?
              WHERE id = ? AND versi = ?'
        );
        try {
            $this->run($statement, [$data['status'], $data['waktu_kunjungan'], $data['keluhan'], $data['catatan_klinis'], $data['tindakan'], $versiBaru, $userId, $id, $versiLama]);

            return $statement->affected_rows === 1;
        } finally {
            $statement->close();
        }
    }

    /**
     * Rincian AKTIF satu kunjungan (versi_dibatalkan IS NULL), urut id.
     *
     * @return array{obat:array<int, array<string, mixed>>, rujukan:array<int, array<string, mixed>>, tindak_lanjut:array<int, array<string, mixed>>}
     */
    public function rincianAktif(int $kunjunganId): array
    {
        $akhir = ' ORDER BY id';

        return [
            'obat' => $this->all('SELECT id, nama_obat, jumlah, satuan, petunjuk, waktu_pemberian, versi_dibuat, dicatat_oleh, updated_at
                                    FROM kesehatan_obat WHERE kunjungan_id = ? AND versi_dibatalkan IS NULL' . $akhir, [$kunjunganId]),
            'rujukan' => $this->all('SELECT id, tujuan, tanggal_rujukan, alasan, status, hasil, tanggal_hasil, versi_dibuat, dicatat_oleh, updated_at
                                       FROM kesehatan_rujukan WHERE kunjungan_id = ? AND versi_dibatalkan IS NULL' . $akhir, [$kunjunganId]),
            'tindak_lanjut' => $this->all('SELECT id, uraian, tanggal_target, status, tanggal_selesai, versi_dibuat, dicatat_oleh, updated_at
                                             FROM kesehatan_tindak_lanjut WHERE kunjungan_id = ? AND versi_dibatalkan IS NULL' . $akhir, [$kunjunganId]),
        ];
    }

    /**
     * Menerapkan rencana perubahan rincian satu versi: baris baru disisipkan,
     * baris berubah diperbarui, baris yang dihapus diberi `versi_dibatalkan`
     * (tidak pernah DELETE). Dipanggil hanya setelah baris kunjungan dikunci
     * `kunciKunjungan()`, sehingga rincian tidak berubah di antara pembacaan
     * dan penerapan.
     *
     * @param array{obat:array<int, array<string, mixed>>, rujukan:array<int, array<string, mixed>>, tindak_lanjut:array<int, array<string, mixed>>} $rencana
     *        tiap baris: ['aksi' => 'tambah'|'ubah'|'batal', 'id' => ?int, 'data' => array]
     */
    public function terapkanRincian(int $kunjunganId, int $versi, array $rencana, int $userId): void
    {
        foreach ($rencana['obat'] as $r) {
            $d = $r['data'];
            match ($r['aksi']) {
                'tambah' => $this->execute(
                    'INSERT INTO kesehatan_obat (kunjungan_id, nama_obat, jumlah, satuan, petunjuk, waktu_pemberian, versi_dibuat, dicatat_oleh, diperbarui_oleh)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$kunjunganId, $d['nama_obat'], $d['jumlah'], $d['satuan'], $d['petunjuk'], $d['waktu_pemberian'], $versi, $userId, $userId]
                ),
                'ubah' => $this->ubahSatu(
                    'UPDATE kesehatan_obat SET nama_obat = ?, jumlah = ?, satuan = ?, petunjuk = ?, waktu_pemberian = ?, diperbarui_oleh = ?
                      WHERE id = ? AND kunjungan_id = ? AND versi_dibatalkan IS NULL',
                    [$d['nama_obat'], $d['jumlah'], $d['satuan'], $d['petunjuk'], $d['waktu_pemberian'], $userId, $r['id'], $kunjunganId]
                ),
                'batal' => $this->ubahSatu(
                    'UPDATE kesehatan_obat SET versi_dibatalkan = ?, diperbarui_oleh = ? WHERE id = ? AND kunjungan_id = ? AND versi_dibatalkan IS NULL',
                    [$versi, $userId, $r['id'], $kunjunganId]
                ),
            };
        }
        foreach ($rencana['rujukan'] as $r) {
            $d = $r['data'];
            match ($r['aksi']) {
                'tambah' => $this->execute(
                    'INSERT INTO kesehatan_rujukan (kunjungan_id, tujuan, tanggal_rujukan, alasan, status, hasil, tanggal_hasil, versi_dibuat, dicatat_oleh, diperbarui_oleh)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$kunjunganId, $d['tujuan'], $d['tanggal_rujukan'], $d['alasan'], $d['status'], $d['hasil'], $d['tanggal_hasil'], $versi, $userId, $userId]
                ),
                'ubah' => $this->ubahSatu(
                    'UPDATE kesehatan_rujukan SET tujuan = ?, tanggal_rujukan = ?, alasan = ?, status = ?, hasil = ?, tanggal_hasil = ?, diperbarui_oleh = ?
                      WHERE id = ? AND kunjungan_id = ? AND versi_dibatalkan IS NULL',
                    [$d['tujuan'], $d['tanggal_rujukan'], $d['alasan'], $d['status'], $d['hasil'], $d['tanggal_hasil'], $userId, $r['id'], $kunjunganId]
                ),
                'batal' => $this->ubahSatu(
                    'UPDATE kesehatan_rujukan SET versi_dibatalkan = ?, diperbarui_oleh = ? WHERE id = ? AND kunjungan_id = ? AND versi_dibatalkan IS NULL',
                    [$versi, $userId, $r['id'], $kunjunganId]
                ),
            };
        }
        foreach ($rencana['tindak_lanjut'] as $r) {
            $d = $r['data'];
            match ($r['aksi']) {
                'tambah' => $this->execute(
                    'INSERT INTO kesehatan_tindak_lanjut (kunjungan_id, uraian, tanggal_target, status, tanggal_selesai, versi_dibuat, dicatat_oleh, diperbarui_oleh)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$kunjunganId, $d['uraian'], $d['tanggal_target'], $d['status'], $d['tanggal_selesai'], $versi, $userId, $userId]
                ),
                'ubah' => $this->ubahSatu(
                    'UPDATE kesehatan_tindak_lanjut SET uraian = ?, tanggal_target = ?, status = ?, tanggal_selesai = ?, diperbarui_oleh = ?
                      WHERE id = ? AND kunjungan_id = ? AND versi_dibatalkan IS NULL',
                    [$d['uraian'], $d['tanggal_target'], $d['status'], $d['tanggal_selesai'], $userId, $r['id'], $kunjunganId]
                ),
                'batal' => $this->ubahSatu(
                    'UPDATE kesehatan_tindak_lanjut SET versi_dibatalkan = ?, diperbarui_oleh = ? WHERE id = ? AND kunjungan_id = ? AND versi_dibatalkan IS NULL',
                    [$versi, $userId, $r['id'], $kunjunganId]
                ),
            };
        }
    }

    /**
     * Seluruh versi satu kunjungan beserta snapshot rinciannya, urut naik.
     *
     * @return array<int, array<string, mixed>>
     */
    public function versi(int $kunjunganId): array
    {
        return $this->all(
            'SELECT v.versi, v.status, v.waktu_kunjungan, v.keluhan, v.catatan_klinis, v.tindakan, v.alasan, v.dibuat_pada,
                    v.dibuat_oleh, COALESCE(p.nama, u.name) AS pembuat_nama, r.rincian
               FROM kesehatan_kunjungan_versi v
               JOIN users u ON u.id = v.dibuat_oleh
               LEFT JOIN pengurus p ON p.id = u.pengurus_id
               LEFT JOIN kesehatan_versi_rincian r ON r.kunjungan_id = v.kunjungan_id AND r.versi = v.versi
              WHERE v.kunjungan_id = ?
              ORDER BY v.versi',
            [$kunjunganId]
        );
    }

    // =======================================================================
    // Cakupan pembimbing/murobi
    // =======================================================================

    /**
     * Penugasan binaan AKTIF milik akun (tanpa melihat santri). Akun yang
     * memegangnya boleh membuka daftar kesehatan binaan, yang barisnya tetap
     * disaring `cakupanSql()`.
     *
     * @return array{pembimbing:bool, murobi:bool}
     */
    public function binaanAktif(int $userId): array
    {
        $hasil = [];
        foreach ([self::BINAAN_PEMBIMBING, self::BINAAN_MUROBI] as $jenis) {
            [$role, $master, $kolom, $tabel] = $this->binaanTabel($jenis);
            $hasil[$jenis] = $this->one(
                "SELECT 1 AS ada
                   FROM users u
                   JOIN user_roles ur ON ur.user_id = u.id
                   JOIN roles r ON r.id = ur.role_id AND r.slug = '{$role}'
                   JOIN {$master} m ON m.id = u.{$kolom} AND m.is_active = 1 AND m.archived_at IS NULL
                   JOIN {$tabel} a ON a.{$kolom} = m.id
                   JOIN tahun_ajaran ta ON ta.id = a.tahun_ajaran_id AND ta.status = 'Aktif' AND ta.archived_at IS NULL
                  WHERE u.id = ? AND u.is_active = 1
                    AND a.is_active = 1 AND a.archived_at IS NULL
                    AND a.tanggal_mulai <= CURDATE() AND (a.tanggal_selesai IS NULL OR a.tanggal_selesai >= CURDATE())
                  LIMIT 1",
                [$userId]
            ) !== null;
        }

        return ['pembimbing' => $hasil[self::BINAAN_PEMBIMBING], 'murobi' => $hasil[self::BINAAN_MUROBI]];
    }

    /**
     * Klausa EXISTS: santri pada kolom `$kolomSantri` berada dalam cakupan
     * penugasan binaan aktif akun (satu parameter: user id). Sama dengan
     * `ProfilSantriService::binaanSql()`.
     */
    public function cakupanSql(string $jenis, string $kolomSantri): string
    {
        if (!preg_match('/^[a-z]+\.[a-z_]+$/D', $kolomSantri)) {
            throw new KesehatanException('Cakupan tidak valid.', 403);
        }
        [$role, $master, $kolom, $tabel] = $this->binaanTabel($jenis);

        return "EXISTS (SELECT 1 FROM users bu
                  JOIN user_roles bur ON bur.user_id = bu.id
                  JOIN roles br ON br.id = bur.role_id AND br.slug = '{$role}'
                  JOIN {$master} bm ON bm.id = bu.{$kolom} AND bm.is_active = 1 AND bm.archived_at IS NULL
                  JOIN {$tabel} ba ON ba.{$kolom} = bm.id
                  JOIN tahun_ajaran bta ON bta.id = ba.tahun_ajaran_id AND bta.status = 'Aktif' AND bta.archived_at IS NULL
                  LEFT JOIN kelas bkl ON bkl.id = ba.kelas_id
                  JOIN santri bs ON bs.id = {$kolomSantri} AND bs.is_active = 1 AND bs.archived_at IS NULL
                 WHERE bu.id = ? AND bu.is_active = 1
                   AND ba.is_active = 1 AND ba.archived_at IS NULL
                   AND ba.tanggal_mulai <= CURDATE() AND (ba.tanggal_selesai IS NULL OR ba.tanggal_selesai >= CURDATE())
                   AND ((ba.target_type = 'Kamar' AND EXISTS (SELECT 1 FROM plotting_kamar pm WHERE pm.id_santri = bs.id AND pm.id_tahun = ba.tahun_ajaran_id AND pm.id_kamar = ba.kamar_id))
                     OR (ba.target_type = 'Kelas' AND bkl.is_active = 1 AND bkl.archived_at IS NULL
                         AND EXISTS (SELECT 1 FROM plotting_kelas pk WHERE pk.id_santri = bs.id AND pk.id_tahun = ba.tahun_ajaran_id AND pk.id_kelas = ba.kelas_id AND pk.status = 'Aktif'))))";
    }

    /**
     * Penyaring baris untuk pembaca binaan: hanya kunjungan Tercatat santri
     * dalam cakupan salah satu jenis binaan aktif akun.
     *
     * @param array<int, string> $jenis
     * @return array{0:string, 1:array<int, int>}
     */
    public function saringBinaan(array $jenis, int $userId): array
    {
        $klausa = [];
        $params = [];
        foreach ($jenis as $j) {
            $klausa[] = $this->cakupanSql($j, 'k.santri_id');
            $params[] = $userId;
        }
        if ($klausa === []) {
            return ['1 = 0', []];
        }

        return ["k.status = 'Tercatat' AND (" . implode(' OR ', $klausa) . ')', $params];
    }

    /** @return array{0:string, 1:string, 2:string, 3:string} role, master, kolom, tabel penugasan */
    private function binaanTabel(string $jenis): array
    {
        return match ($jenis) {
            self::BINAAN_PEMBIMBING => ['pengurus', 'pengurus', 'pengurus_id', 'pembimbing_assignments'],
            self::BINAAN_MUROBI => ['guru', 'guru', 'guru_id', 'murobi_assignments'],
            default => throw new KesehatanException('Jenis binaan tidak dikenal.', 403),
        };
    }

    // =======================================================================
    // Pembacaan
    // =======================================================================

    /**
     * Detail satu kunjungan termasuk isi klinis. `$saring` (dari
     * `saringBinaan()`) membatasi pembaca binaan pada baris yang berhak.
     *
     * @param array{0:string, 1:array<int, int>}|null $saring
     * @return array<string, mixed>|null
     */
    public function kunjungan(int $id, ?array $saring = null): ?array
    {
        [$where, $params] = $saring ?? ['1 = 1', []];

        return $this->one(
            'SELECT k.id, k.santri_id, k.waktu_kunjungan, k.status, k.versi, k.keluhan, k.catatan_klinis, k.tindakan,
                    k.petugas_user_id, k.created_at, k.updated_at,
                    s.nis, s.nama_santri, s.is_active AS santri_aktif, s.archived_at AS santri_arsip,
                    COALESCE(p.nama, u.name) AS petugas_nama
               FROM kesehatan_kunjungan k
               JOIN santri s ON s.id = k.santri_id
               JOIN users u ON u.id = k.petugas_user_id
               LEFT JOIN pengurus p ON p.id = k.petugas_pengurus_id
              WHERE k.id = ? AND ' . $where,
            [$id, ...$params]
        );
    }

    /**
     * Daftar kunjungan TANPA kolom klinis, satu baris per kunjungan; penanda
     * rincian dihitung lewat subkueri sehingga beberapa obat/rujukan tidak
     * menggandakan baris.
     *
     * @param array{q:string, santri_id:int, status:string, dari:string, sampai:string, rujukan:string, tindak_lanjut:string} $filters
     * @param array{0:string, 1:array<int, int>}|null $saring
     * @return array{rows:array<int, array<string, mixed>>, total:int, page:int, perPage:int}
     */
    public function daftar(array $filters, int $page, ?array $saring = null): array
    {
        [$sql, $params] = $this->daftarSql($filters, $saring);

        return PageQuery::fetch($this->db, $sql, $params, 'waktu_kunjungan DESC, id DESC', max(1, $page), $filters['q'], ['nama_santri', 'nis']);
    }

    /**
     * Ringkasan operasional dari kueri daftar yang SAMA (penyaring + hak per
     * baris), sehingga angka tidak pernah memuat baris yang tidak boleh dilihat.
     *
     * @param array{q:string, santri_id:int, status:string, dari:string, sampai:string, rujukan:string, tindak_lanjut:string} $filters
     * @param array{0:string, 1:array<int, int>}|null $saring
     * @return array{kunjungan:int, dengan_obat:int, rujukan_menunggu:int, tindak_lanjut_terbuka:int, draf:int}
     */
    public function ringkasan(array $filters, ?array $saring = null): array
    {
        [$sql, $params] = $this->daftarSql($filters, $saring);
        $where = '';
        if ($filters['q'] !== '') {
            $where = ' WHERE (nama_santri LIKE ? OR nis LIKE ?)';
            $params[] = '%' . $filters['q'] . '%';
            $params[] = '%' . $filters['q'] . '%';
        }
        $row = $this->one(
            "SELECT COUNT(*) AS kunjungan, COALESCE(SUM(jumlah_obat > 0), 0) AS dengan_obat,
                    COALESCE(SUM(rujukan_menunggu > 0), 0) AS rujukan_menunggu, COALESCE(SUM(tindak_lanjut_terbuka > 0), 0) AS tindak_lanjut_terbuka,
                    COALESCE(SUM(status = 'Draf'), 0) AS draf
               FROM (" . $sql . ') listed' . $where,
            $params
        ) ?? [];

        return array_map('intval', [
            'kunjungan' => $row['kunjungan'] ?? 0, 'dengan_obat' => $row['dengan_obat'] ?? 0, 'rujukan_menunggu' => $row['rujukan_menunggu'] ?? 0,
            'tindak_lanjut_terbuka' => $row['tindak_lanjut_terbuka'] ?? 0, 'draf' => $row['draf'] ?? 0,
        ]);
    }

    /**
     * @param array{q:string, santri_id:int, status:string, dari:string, sampai:string, rujukan:string, tindak_lanjut:string} $filters
     * @param array{0:string, 1:array<int, int>}|null $saring
     * @return array{0:string, 1:array<int, mixed>}
     */
    private function daftarSql(array $filters, ?array $saring): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($saring !== null) {
            $where[] = $saring[0];
            array_push($params, ...$saring[1]);
        }
        if ($filters['santri_id'] > 0) {
            $where[] = 'k.santri_id = ?';
            $params[] = $filters['santri_id'];
        }
        if ($filters['status'] !== '') {
            $where[] = 'k.status = ?';
            $params[] = $filters['status'];
        }
        if ($filters['dari'] !== '') {
            $where[] = 'k.waktu_kunjungan >= ?';
            $params[] = $filters['dari'] . ' 00:00:00';
        }
        if ($filters['sampai'] !== '') {
            $where[] = 'k.waktu_kunjungan < DATE_ADD(?, INTERVAL 1 DAY)';
            $params[] = $filters['sampai'];
        }
        $ada = 'EXISTS (SELECT 1 FROM kesehatan_rujukan rx WHERE rx.kunjungan_id = k.id AND rx.versi_dibatalkan IS NULL';
        $where[] = match ($filters['rujukan']) {
            'ada' => $ada . ')',
            'menunggu' => $ada . " AND rx.status = 'Menunggu hasil')",
            'diketahui' => $ada . " AND rx.status = 'Hasil diketahui')",
            'tanpa' => 'NOT ' . $ada . ')',
            default => '1 = 1',
        };
        $tl = "EXISTS (SELECT 1 FROM kesehatan_tindak_lanjut tx WHERE tx.kunjungan_id = k.id AND tx.versi_dibatalkan IS NULL AND tx.status = 'Terbuka')";
        $where[] = match ($filters['tindak_lanjut']) {
            'terbuka' => $tl,
            'tanpa_terbuka' => 'NOT ' . $tl,
            default => '1 = 1',
        };

        return ['SELECT k.id, k.santri_id, k.waktu_kunjungan, k.status, k.versi, k.created_at,
                       s.nis, s.nama_santri, COALESCE(p.nama, u.name) AS petugas_nama,
                       (SELECT COUNT(*) FROM kesehatan_obat o WHERE o.kunjungan_id = k.id AND o.versi_dibatalkan IS NULL) AS jumlah_obat,
                       (SELECT COUNT(*) FROM kesehatan_rujukan r WHERE r.kunjungan_id = k.id AND r.versi_dibatalkan IS NULL) AS jumlah_rujukan,
                       (SELECT COUNT(*) FROM kesehatan_rujukan r WHERE r.kunjungan_id = k.id AND r.versi_dibatalkan IS NULL AND r.status = \'Menunggu hasil\') AS rujukan_menunggu,
                       (SELECT COUNT(*) FROM kesehatan_tindak_lanjut t WHERE t.kunjungan_id = k.id AND t.versi_dibatalkan IS NULL) AS jumlah_tindak_lanjut,
                       (SELECT COUNT(*) FROM kesehatan_tindak_lanjut t WHERE t.kunjungan_id = k.id AND t.versi_dibatalkan IS NULL AND t.status = \'Terbuka\') AS tindak_lanjut_terbuka
                  FROM kesehatan_kunjungan k
                  JOIN santri s ON s.id = k.santri_id
                  JOIN users u ON u.id = k.petugas_user_id
                  LEFT JOIN pengurus p ON p.id = k.petugas_pengurus_id
                 WHERE ' . implode(' AND ', $where), $params];
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

    /** UPDATE satu baris rincian milik kunjungan ini; lebih dari satu baris berarti data tidak konsisten. */
    private function ubahSatu(string $sql, array $params): void
    {
        $statement = $this->prepare($sql);
        try {
            $this->run($statement, $params);
            if ($statement->affected_rows > 1) {
                throw new KesehatanException('Rincian kunjungan tidak konsisten.', 503);
            }
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
