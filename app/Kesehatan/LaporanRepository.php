<?php

declare(strict_types=1);

namespace App\Kesehatan;

use App\Database\Schema;
use mysqli;
use mysqli_stmt;
use Throwable;

/**
 * Agregat laporan layanan klinik (PRD V5 Fase 5). Hanya SELECT, parameter
 * terikat, dan TANPA kolom klinis: keluhan, catatan klinis, tindakan, nama
 * obat, tujuan/alasan/hasil rujukan, uraian tindak lanjut, teks ringkasan,
 * dan alasan koreksi tidak pernah dipilih — hanya jumlah, status, tanggal,
 * identitas santri, dan nama petugas.
 *
 * Seluruh angka bersumber dari satu himpunan kunjungan `basis()` (periode +
 * hak per baris + kelas), sehingga laporan tidak pernah menghitung baris
 * yang tidak boleh dilihat akun. Rincian (obat, rujukan, tindak lanjut)
 * dihitung lewat `kunjungan_id IN (basis)` atau `EXISTS`, bukan JOIN, agar
 * kunjungan dengan beberapa obat tetap dihitung sekali.
 */
final class LaporanRepository
{
    /** Penanda tempat JOIN tambahan pada FROM basis (sebelum WHERE). */
    private const SISIPAN = '/*sisipan*/';

    public function __construct(private mysqli $db)
    {
    }

    /** Semua bagian satu laporan membaca keadaan commit yang sama. */
    public function snapshot(callable $work): array
    {
        // Jangan bergantung pada isolation level bawaan hosting (READ COMMITTED
        // akan memberi snapshot berbeda untuk setiap SELECT).
        $this->db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        if (!$this->db->begin_transaction(MYSQLI_TRANS_START_READ_ONLY | MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT)) {
            throw new KesehatanException('Laporan kesehatan tidak tersedia.', 503);
        }
        try {
            $result = $work();
            if (!$this->db->commit()) {
                throw new KesehatanException('Laporan kesehatan tidak dapat disusun.', 503);
            }
            return $result;
        } catch (Throwable $exception) {
            $this->db->rollback();
            throw $exception;
        }
    }

    /** Tabel ringkasan wali (migrasi 026) terpasang. */
    public function ringkasanSiap(): bool
    {
        return Schema::hasTable($this->db, 'kesehatan_ringkasan') && Schema::hasTable($this->db, 'kesehatan_ringkasan_peristiwa');
    }

    /**
     * Himpunan kunjungan laporan: FROM … WHERE … beserta parameternya.
     *
     * @param array{dari:?string, sampai:?string, kelas:?int} $f kelas 0 = belum ditempatkan, null = semua
     * @param array{0:string, 1:array<int, int>}|null $saring penyaring baris binaan (`KesehatanRepository::saringBinaan`)
     * @return array{0:string, 1:array<int, mixed>}
     */
    public function basis(array $f, ?array $saring): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($saring !== null) {
            $where[] = $saring[0];
            array_push($params, ...$saring[1]);
        }
        if ($f['dari'] !== null) {
            $where[] = 'k.waktu_kunjungan >= ?';
            $params[] = $f['dari'] . ' 00:00:00';
        }
        if ($f['sampai'] !== null) {
            $where[] = 'k.waktu_kunjungan < DATE_ADD(?, INTERVAL 1 DAY)';
            $params[] = $f['sampai'];
        }
        if ($f['kelas'] !== null) {
            $where[] = $f['kelas'] > 0 ? 'kl.id = ?' : 'kl.id IS NULL';
            if ($f['kelas'] > 0) {
                $params[] = $f['kelas'];
            }
        }

        // Kelas = penempatan aktif tahun ajaran aktif (paling banyak satu per
        // santri, indeks unik `plotting_kelas_one_active_unique`), bukan kelas
        // pada saat kunjungan.
        return ["FROM kesehatan_kunjungan k
                   JOIN santri s ON s.id = k.santri_id
                   LEFT JOIN plotting_kelas pk ON pk.id_santri = k.santri_id AND pk.status = 'Aktif'
                         AND pk.id_tahun = (SELECT ta.id FROM tahun_ajaran ta WHERE ta.status = 'Aktif' AND ta.archived_at IS NULL ORDER BY ta.id DESC LIMIT 1)
                   LEFT JOIN kelas kl ON kl.id = pk.id_kelas
                   " . self::SISIPAN . "
                  WHERE " . implode(' AND ', $where), $params];
    }

    /**
     * @param array{0:string, 1:array<int, mixed>} $basis
     * @return array{total:int, tercatat:int, draf:int, santri:int, dikoreksi:int, dengan_obat:int, dengan_rujukan:int, dengan_tindak_lanjut:int}
     */
    public function kunjungan(array $basis): array
    {
        [$from, $params] = $basis;
        $aktif = static fn (string $tabel): string => "EXISTS (SELECT 1 FROM {$tabel} x WHERE x.kunjungan_id = k.id AND x.versi_dibatalkan IS NULL)";
        $row = $this->one(
            "SELECT COUNT(*) AS total, COALESCE(SUM(k.status = 'Tercatat'), 0) AS tercatat, COALESCE(SUM(k.status = 'Draf'), 0) AS draf,
                    COUNT(DISTINCT k.santri_id) AS santri, COALESCE(SUM(k.versi > 1), 0) AS dikoreksi,
                    COALESCE(SUM({$aktif('kesehatan_obat')}), 0) AS dengan_obat,
                    COALESCE(SUM({$aktif('kesehatan_rujukan')}), 0) AS dengan_rujukan,
                    COALESCE(SUM({$aktif('kesehatan_tindak_lanjut')}), 0) AS dengan_tindak_lanjut
             {$from}",
            $params
        ) ?? [];

        return $this->angka($row, ['total', 'tercatat', 'draf', 'santri', 'dikoreksi', 'dengan_obat', 'dengan_rujukan', 'dengan_tindak_lanjut']);
    }

    /**
     * Pemberian obat aktif (baris), rujukan, dan tindak lanjut pada himpunan kunjungan.
     *
     * @param array{0:string, 1:array<int, mixed>} $basis
     * @return array{obat:array{pemberian:int}, rujukan:array{total:int, menunggu:int, diketahui:int},
     *     tindak_lanjut:array{total:int, terbuka:int, terlambat:int, selesai:int}}
     */
    public function rincian(array $basis): array
    {
        [$from, $params] = $basis;
        $dalam = "kunjungan_id IN (SELECT k.id {$from})";
        $obat = $this->one("SELECT COUNT(*) AS pemberian FROM kesehatan_obat WHERE versi_dibatalkan IS NULL AND {$dalam}", $params) ?? [];
        $rujukan = $this->one(
            "SELECT COUNT(*) AS total, COALESCE(SUM(status = 'Menunggu hasil'), 0) AS menunggu, COALESCE(SUM(status = 'Hasil diketahui'), 0) AS diketahui
               FROM kesehatan_rujukan WHERE versi_dibatalkan IS NULL AND {$dalam}",
            $params
        ) ?? [];
        $tl = $this->one(
            "SELECT COUNT(*) AS total, COALESCE(SUM(status = 'Terbuka'), 0) AS terbuka,
                    COALESCE(SUM(status = 'Terbuka' AND tanggal_target IS NOT NULL AND tanggal_target < CURDATE()), 0) AS terlambat,
                    COALESCE(SUM(status = 'Selesai'), 0) AS selesai
               FROM kesehatan_tindak_lanjut WHERE versi_dibatalkan IS NULL AND {$dalam}",
            $params
        ) ?? [];

        return [
            'obat' => $this->angka($obat, ['pemberian']),
            'rujukan' => $this->angka($rujukan, ['total', 'menunggu', 'diketahui']),
            'tindak_lanjut' => $this->angka($tl, ['total', 'terbuka', 'terlambat', 'selesai']),
        ];
    }

    /**
     * Status ringkasan wali per kunjungan dalam himpunan: terbit, ditarik,
     * dan kunjungan Tercatat yang belum pernah diterbitkan ringkasannya.
     *
     * @param array{0:string, 1:array<int, mixed>} $basis
     * @return array{terbit:int, ditarik:int, belum:int}
     */
    public function ringkasan(array $basis): array
    {
        [$from, $params] = $basis;
        $row = $this->one(
            "SELECT COALESCE(SUM(g.status = 'Terbit'), 0) AS terbit, COALESCE(SUM(g.status = 'Ditarik'), 0) AS ditarik,
                    COALESCE(SUM(g.id IS NULL AND k.status = 'Tercatat'), 0) AS belum
             {$from}",
            $params,
            'LEFT JOIN kesehatan_ringkasan g ON g.kunjungan_id = k.id'
        ) ?? [];

        return $this->angka($row, ['terbit', 'ditarik', 'belum']);
    }

    /**
     * @param array{0:string, 1:array<int, mixed>} $basis
     * @return array<int, array{kelas:?array{id:int, nama:string}, kunjungan:int, santri:int}>
     */
    public function perKelas(array $basis): array
    {
        [$from, $params] = $basis;

        return array_map(static fn (array $r): array => [
            'kelas' => $r['id'] === null ? null : ['id' => (int) $r['id'], 'nama' => (string) $r['nama_kelas']],
            'kunjungan' => (int) $r['kunjungan'],
            'santri' => (int) $r['santri'],
        ], $this->all(
            "SELECT kl.id, kl.nama_kelas, COUNT(*) AS kunjungan, COUNT(DISTINCT k.santri_id) AS santri {$from}
              GROUP BY kl.id, kl.nama_kelas ORDER BY kl.id IS NULL, kl.nama_kelas, kl.id",
            $params
        ));
    }

    /**
     * @param array{0:string, 1:array<int, mixed>} $basis
     * @return array<int, array{bulan:string, kunjungan:int, santri:int}>
     */
    public function perBulan(array $basis): array
    {
        [$from, $params] = $basis;

        return array_map(static fn (array $r): array => [
            'bulan' => (string) $r['bulan'], 'kunjungan' => (int) $r['kunjungan'], 'santri' => (int) $r['santri'],
        ], $this->all(
            "SELECT DATE_FORMAT(k.waktu_kunjungan, '%Y-%m') AS bulan, COUNT(*) AS kunjungan, COUNT(DISTINCT k.santri_id) AS santri {$from}
              GROUP BY bulan ORDER BY bulan",
            $params
        ));
    }

    /**
     * Kunjungan yang masih memiliki rujukan menunggu hasil (`rujukan`) atau
     * tindak lanjut terbuka (`tindak_lanjut`), satu baris per kunjungan,
     * terlama/target terdekat lebih dahulu. Tanpa isi rincian.
     *
     * @param array{0:string, 1:array<int, mixed>} $basis
     * @return array{total:int, rows:array<int, array<string, mixed>>}
     */
    public function tertunda(string $jenis, array $basis, int $batas): array
    {
        [$from, $params] = $basis;
        [$tabel, $status, $kolom] = match ($jenis) {
            'rujukan' => ['kesehatan_rujukan', 'Menunggu hasil', 'tanggal_rujukan'],
            'tindak_lanjut' => ['kesehatan_tindak_lanjut', 'Terbuka', 'tanggal_target'],
            default => throw new KesehatanException('Jenis laporan tidak dikenal.', 404),
        };
        // `lewat` = tindak lanjut melewati tanggal target; tanggal rujukan bukan target sehingga selalu 0.
        $lewat = $jenis === 'tindak_lanjut' ? "COALESCE(SUM(x.{$kolom} IS NOT NULL AND x.{$kolom} < CURDATE()), 0)" : '0';
        $sub = "SELECT x.kunjungan_id, COUNT(*) AS jumlah, MIN(x.{$kolom}) AS tanggal, {$lewat} AS lewat
                  FROM {$tabel} x WHERE x.versi_dibatalkan IS NULL AND x.status = '{$status}' GROUP BY x.kunjungan_id";
        $join = "JOIN ({$sub}) t ON t.kunjungan_id = k.id";
        $total = (int) ($this->one("SELECT COUNT(*) AS n {$from}", $params, $join)['n'] ?? 0);
        $rows = $this->all(
            "SELECT k.id, k.santri_id, k.waktu_kunjungan, k.status, s.nis, s.nama_santri, kl.nama_kelas, t.jumlah, t.tanggal, t.lewat {$from}
              ORDER BY t.tanggal IS NULL, t.tanggal, k.waktu_kunjungan, k.id LIMIT ?",
            [...$params, max(1, min(200, $batas))],
            $join
        );

        return ['total' => $total, 'rows' => array_map(static fn (array $r): array => [
            'kunjungan_id' => (int) $r['id'],
            'santri' => ['id' => (int) $r['santri_id'], 'nis' => (string) $r['nis'], 'nama' => (string) $r['nama_santri']],
            'kelas' => $r['nama_kelas'] === null ? null : (string) $r['nama_kelas'],
            'waktu_kunjungan' => (string) $r['waktu_kunjungan'],
            'status' => (string) $r['status'],
            'jumlah' => (int) $r['jumlah'],
            'tanggal' => $r['tanggal'] === null ? null : (string) $r['tanggal'],
            'lewat' => (int) $r['lewat'],
        ], $rows)];
    }

    /**
     * Kelas yang dapat dipilih pada penyaring: kelas penempatan santri dari
     * kunjungan yang boleh dilihat akun (basis tanpa periode dan kelas), sehingga
     * pembaca binaan tidak pernah melihat nama kelas di luar cakupannya.
     *
     * @param array{0:string, 1:array<int, mixed>} $basis
     * @return array<int, array{id:int, nama:string}>
     */
    public function kelasPilihan(array $basis): array
    {
        [$from, $params] = $basis;

        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'nama' => (string) $r['nama_kelas']], $this->all(
            "SELECT DISTINCT kl.id, kl.nama_kelas {$from} AND kl.id IS NOT NULL ORDER BY kl.nama_kelas, kl.id",
            $params
        ));
    }

    // =======================================================================
    // Pengawasan admin (periode berdasarkan waktu peristiwa, seluruh santri)
    // =======================================================================

    /**
     * Koreksi (versi > 1) yang dibuat dalam periode. `beralasan` = alasan
     * tersimpan; `wajib_alasan` = versi sebelumnya sudah Tercatat (alasan
     * diwajibkan layanan); `tanpa_alasan_wajib` harus selalu 0.
     *
     * @return array{total:int, beralasan:int, wajib_alasan:int, tanpa_alasan_wajib:int}
     */
    public function koreksi(string $dari, string $sampai): array
    {
        $row = $this->one(
            "SELECT COUNT(*) AS total, COALESCE(SUM(v.alasan IS NOT NULL AND TRIM(v.alasan) <> ''), 0) AS beralasan,
                    COALESCE(SUM(p.status = 'Tercatat'), 0) AS wajib_alasan,
                    COALESCE(SUM(p.status = 'Tercatat' AND (v.alasan IS NULL OR CHAR_LENGTH(TRIM(v.alasan)) < 5)), 0) AS tanpa_alasan_wajib
               FROM kesehatan_kunjungan_versi v
               JOIN kesehatan_kunjungan_versi p ON p.kunjungan_id = v.kunjungan_id AND p.versi = v.versi - 1
              WHERE v.versi > 1 AND v.dibuat_pada >= ? AND v.dibuat_pada < DATE_ADD(?, INTERVAL 1 DAY)",
            [$dari . ' 00:00:00', $sampai]
        ) ?? [];

        return $this->angka($row, ['total', 'beralasan', 'wajib_alasan', 'tanpa_alasan_wajib']);
    }

    /**
     * Koreksi terbaru dalam periode: siapa, kapan, versi berapa — tanpa isi
     * maupun teks alasan (alasan dibaca di riwayat versi yang tercatat aksesnya).
     *
     * @return array<int, array<string, mixed>>
     */
    public function koreksiTerbaru(string $dari, string $sampai, int $batas): array
    {
        return array_map(static fn (array $r): array => [
            'kunjungan_id' => (int) $r['kunjungan_id'],
            'santri' => ['id' => (int) $r['santri_id'], 'nis' => (string) $r['nis'], 'nama' => (string) $r['nama_santri']],
            'versi_sebelum' => (int) $r['versi'] - 1,
            'versi' => (int) $r['versi'],
            'oleh' => (string) $r['pembuat_nama'],
            'waktu' => (string) $r['dibuat_pada'],
            'beralasan' => (int) $r['beralasan'] === 1,
        ], $this->all(
            "SELECT v.kunjungan_id, v.versi, v.dibuat_pada, (v.alasan IS NOT NULL AND TRIM(v.alasan) <> '') AS beralasan,
                    k.santri_id, s.nis, s.nama_santri, COALESCE(p.nama, u.name) AS pembuat_nama
               FROM kesehatan_kunjungan_versi v
               JOIN kesehatan_kunjungan k ON k.id = v.kunjungan_id
               JOIN santri s ON s.id = k.santri_id
               JOIN users u ON u.id = v.dibuat_oleh
               LEFT JOIN pengurus p ON p.id = u.pengurus_id
              WHERE v.versi > 1 AND v.dibuat_pada >= ? AND v.dibuat_pada < DATE_ADD(?, INTERVAL 1 DAY)
              ORDER BY v.dibuat_pada DESC, v.id DESC LIMIT ?",
            [$dari . ' 00:00:00', $sampai, max(1, min(100, $batas))]
        ));
    }

    /**
     * Kunjungan dicatat dan koreksi dibuat per petugas dalam periode.
     *
     * @return array<int, array{user_id:int, nama:string, kunjungan:int, koreksi:int}>
     */
    public function perPetugas(string $dari, string $sampai): array
    {
        $rentang = [$dari . ' 00:00:00', $sampai];
        $hasil = [];
        foreach ($this->all(
            'SELECT k.petugas_user_id AS uid, MAX(COALESCE(p.nama, u.name)) AS nama, COUNT(*) AS n
               FROM kesehatan_kunjungan k JOIN users u ON u.id = k.petugas_user_id LEFT JOIN pengurus p ON p.id = k.petugas_pengurus_id
              WHERE k.waktu_kunjungan >= ? AND k.waktu_kunjungan < DATE_ADD(?, INTERVAL 1 DAY)
              GROUP BY k.petugas_user_id',
            $rentang
        ) as $r) {
            $hasil[(int) $r['uid']] = ['user_id' => (int) $r['uid'], 'nama' => (string) $r['nama'], 'kunjungan' => (int) $r['n'], 'koreksi' => 0];
        }
        foreach ($this->all(
            'SELECT v.dibuat_oleh AS uid, MAX(COALESCE(p.nama, u.name)) AS nama, COUNT(*) AS n
               FROM kesehatan_kunjungan_versi v JOIN users u ON u.id = v.dibuat_oleh LEFT JOIN pengurus p ON p.id = u.pengurus_id
              WHERE v.versi > 1 AND v.dibuat_pada >= ? AND v.dibuat_pada < DATE_ADD(?, INTERVAL 1 DAY)
              GROUP BY v.dibuat_oleh',
            $rentang
        ) as $r) {
            $hasil[(int) $r['uid']] ??= ['user_id' => (int) $r['uid'], 'nama' => (string) $r['nama'], 'kunjungan' => 0, 'koreksi' => 0];
            $hasil[(int) $r['uid']]['koreksi'] = (int) $r['n'];
        }
        $hasil = array_values($hasil);
        usort($hasil, static fn (array $a, array $b): int => [$b['kunjungan'], $b['koreksi'], $a['nama']] <=> [$a['kunjungan'], $a['koreksi'], $b['nama']]);

        return $hasil;
    }

    /**
     * Pembukaan rincian/ringkasan dari `kesehatan_akses_log` per sumber hak dan aksi.
     *
     * @return array<int, array{sumber:string, aksi:string, kanal:string, jumlah:int, pengguna:int}>
     */
    public function akses(string $dari, string $sampai): array
    {
        return array_map(static fn (array $r): array => [
            'sumber' => (string) $r['sumber'], 'aksi' => (string) $r['aksi'], 'kanal' => (string) $r['kanal'],
            'jumlah' => (int) $r['jumlah'], 'pengguna' => (int) $r['pengguna'],
        ], $this->all(
            'SELECT sumber, aksi, kanal, COUNT(*) AS jumlah, COUNT(DISTINCT user_id) AS pengguna
               FROM kesehatan_akses_log WHERE created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)
              GROUP BY sumber, aksi, kanal ORDER BY sumber, aksi, kanal',
            [$dari . ' 00:00:00', $sampai]
        ));
    }

    /**
     * Peristiwa publikasi ringkasan wali (Terbit/Ganti/Tarik) dalam periode.
     *
     * @return array<int, array{jenis:string, jumlah:int, beralasan:int, penerima:int}>
     */
    public function peristiwaRingkasan(string $dari, string $sampai): array
    {
        return array_map(static fn (array $r): array => [
            'jenis' => (string) $r['jenis'], 'jumlah' => (int) $r['jumlah'], 'beralasan' => (int) $r['beralasan'], 'penerima' => (int) $r['penerima'],
        ], $this->all(
            "SELECT jenis, COUNT(*) AS jumlah, COALESCE(SUM(alasan IS NOT NULL AND TRIM(alasan) <> ''), 0) AS beralasan,
                    COALESCE(SUM(jumlah_penerima), 0) AS penerima
               FROM kesehatan_ringkasan_peristiwa WHERE dibuat_pada >= ? AND dibuat_pada < DATE_ADD(?, INTERVAL 1 DAY)
              GROUP BY jenis ORDER BY FIELD(jenis, 'Terbit', 'Ganti', 'Tarik')",
            [$dari . ' 00:00:00', $sampai]
        ));
    }

    /**
     * Status antrean notifikasi kesehatan (InApp/Push) yang dibuat dalam periode.
     *
     * @return array<int, array{kanal:string, status:string, jumlah:int}>
     */
    public function notifikasi(string $dari, string $sampai): array
    {
        return array_map(static fn (array $r): array => [
            'kanal' => (string) $r['kanal'], 'status' => (string) $r['status'], 'jumlah' => (int) $r['jumlah'],
        ], $this->all(
            "SELECT kanal, status, COUNT(*) AS jumlah FROM notifikasi_outbox
              WHERE event_type LIKE 'v5\\_kesehatan\\_%' AND created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)
              GROUP BY kanal, status ORDER BY kanal, status",
            [$dari . ' 00:00:00', $sampai]
        ));
    }

    // =======================================================================

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $kunci
     * @return array<string, int>
     */
    private function angka(array $row, array $kunci): array
    {
        $hasil = [];
        foreach ($kunci as $k) {
            $hasil[$k] = (int) ($row[$k] ?? 0);
        }

        return $hasil;
    }

    /**
     * `$join` menggantikan penanda SISIPAN pada FROM basis (sebelum WHERE).
     *
     * @return array<string, mixed>|null
     */
    private function one(string $sql, array $params = [], string $join = ''): ?array
    {
        return $this->all($sql, $params, $join)[0] ?? null;
    }

    /** @return array<int, array<string, mixed>> */
    private function all(string $sql, array $params = [], string $join = ''): array
    {
        $sql = str_replace(self::SISIPAN, $join, $sql);
        $statement = $this->prepare($sql);
        try {
            if ($params !== []) {
                $types = implode('', array_map(static fn (mixed $v): string => is_int($v) ? 'i' : 's', $params));
                $statement->bind_param($types, ...$params);
            }
            if (!$statement->execute()) {
                throw new KesehatanException('Laporan kesehatan tidak dapat disusun.', 503);
            }
            $result = $statement->get_result();

            return $result === false ? [] : $result->fetch_all(MYSQLI_ASSOC);
        } finally {
            $statement->close();
        }
    }

    private function prepare(string $sql): mysqli_stmt
    {
        $statement = $this->db->prepare($sql);
        if ($statement === false) {
            throw new KesehatanException('Laporan kesehatan tidak tersedia.', 503);
        }

        return $statement;
    }
}
