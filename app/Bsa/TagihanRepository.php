<?php

declare(strict_types=1);

namespace App\Bsa;

use App\Database\PageQuery;
use App\Database\Schema;
use mysqli;
use mysqli_stmt;

/**
 * Akses basis data tagihan bulanan lengkap (PRD V6 Fase 2, migrasi 028):
 * tarif unit/jenjang, penyesuaian per santri, penerbitan massal, revisi dan
 * pembatalan tagihan, pembayaran tunai/transfer, kuitansi, dan tunggakan.
 *
 * Seluruh SQL memakai parameter terikat. Repository tidak memutuskan hak:
 * `TagihanService` memeriksa penugasan/cakupan/relasi wali lewat
 * `BsaService` lebih dahulu. Transaksi memakai `BsaRepository::transaction()`
 * pada koneksi yang sama (READ COMMITTED + kunci eksplisit).
 *
 * URUTAN PENGUNCIAN (lanjutan urutan Fase 1, satu urutan untuk semua operasi):
 *   1. penugasan BSA akun (kunci baca)
 *   2. klaim idempotensi akun (kunci tulis)
 *   3. santri (kunci baca, urut ID bila banyak)
 *   4. tarif berlaku unit+periode (kunci tulis, hanya saat mengganti tarif)
 *      atau penyesuaian santri (kunci tulis, hanya saat mencabut)
 *   5. tagihan (kunci tulis)                          `tagihanTerkunci()`
 *   6. pembayaran (kunci tulis)                       `pembayaranTerkunci()`
 *   7. akun tabungan (kunci tulis; Fase 3: pembayaran dari tabungan dan
 *      pengembalian saldo saat pembayaran tabungan dibatalkan)
 */
final class TagihanRepository
{
    public const TABEL = ['bsa_tarif', 'bsa_tarif_komponen', 'bsa_penyesuaian', 'bsa_penerbitan', 'bsa_penerbitan_santri',
        'bsa_tagihan_penyesuaian', 'bsa_tagihan_riwayat', 'bsa_pembayaran_kuitansi', 'bsa_pembayaran_pembatalan'];

    public function __construct(private mysqli $db, private BsaRepository $bsa)
    {
    }

    /** Migrasi 028 terpasang (sembilan tabel). */
    public function skemaSiap(): bool
    {
        foreach (self::TABEL as $tabel) {
            if (!Schema::hasTable($this->db, $tabel)) {
                return false;
            }
        }

        return true;
    }

    // =======================================================================
    // Unit dan tarif
    // =======================================================================

    /**
     * Nama unit/jenjang kanonik dari kelas aktif (mis. SMP, Tsanawi).
     *
     * @return array<int, string>
     */
    public function daftarUnit(): array
    {
        return array_map(static fn (array $r): string => (string) $r['jenjang'], $this->all(
            "SELECT MIN(TRIM(jenjang)) AS jenjang FROM kelas
              WHERE is_active = 1 AND archived_at IS NULL AND TRIM(jenjang) <> ''
              GROUP BY LOWER(TRIM(jenjang)) ORDER BY jenjang"
        ));
    }

    /**
     * Seluruh tarif (berlaku dan diganti) unit tertentu, terbaru di atas.
     *
     * @param array<int, string>|null $unit null = semua unit
     * @return array<int, array<string, mixed>>
     */
    public function daftarTarif(?array $unit): array
    {
        $params = [];
        $where = '';
        if ($unit !== null) {
            if ($unit === []) {
                return [];
            }
            $where = ' WHERE LOWER(t.jenjang) IN (' . implode(', ', array_fill(0, count($unit), '?')) . ')';
            foreach ($unit as $u) {
                $params[] = mb_strtolower(trim($u));
            }
        }

        return $this->all(
            'SELECT t.id, t.jenjang, t.berlaku_mulai, t.total, t.status, t.alasan, t.diganti_oleh_id, t.diganti_pada, t.created_at,
                    COALESCE(p.nama, u.name) AS pembuat_nama
               FROM bsa_tarif t
               JOIN users u ON u.id = t.dibuat_oleh
               LEFT JOIN pengurus p ON p.id = t.pengurus_id' . $where . '
              ORDER BY t.jenjang, t.berlaku_mulai DESC, t.id DESC LIMIT 200',
            $params
        );
    }

    /**
     * Komponen beberapa tarif sekaligus, dikelompokkan per tarif.
     *
     * @param array<int, int> $ids
     * @return array<int, array<int, array{urutan:int, nama:string, nominal:int}>>
     */
    public function komponenTarif(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $hasil = [];
        foreach ($this->all('SELECT tarif_id, urutan, nama, nominal FROM bsa_tarif_komponen WHERE tarif_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ') ORDER BY tarif_id, urutan', $ids) as $r) {
            $hasil[(int) $r['tarif_id']][] = ['urutan' => (int) $r['urutan'], 'nama' => (string) $r['nama'], 'nominal' => (int) $r['nominal']];
        }

        return $hasil;
    }

    /** Ada tarif (berlaku atau diganti) lebih dahulu untuk unit ini. */
    public function adaTarifUnit(string $unit): bool
    {
        return $this->one('SELECT id FROM bsa_tarif WHERE jenjang = ? LIMIT 1', [$unit]) !== null;
    }

    /** Tarif berlaku unit+periode mulai yang sama, dikunci tulis (untuk diganti). */
    public function tarifBerlakuTerkunci(string $unit, string $mulai): ?int
    {
        $row = $this->one("SELECT id FROM bsa_tarif WHERE jenjang = ? AND berlaku_mulai = ? AND status = 'Berlaku' FOR UPDATE", [$unit, $mulai]);

        return $row === null ? null : (int) $row['id'];
    }

    /** Menandai tarif lama Diganti (nominalnya tidak diubah). */
    public function tandaiTarifDiganti(int $id): void
    {
        if ($this->affected("UPDATE bsa_tarif SET status = 'Diganti', diganti_pada = NOW() WHERE id = ? AND status = 'Berlaku'", [$id]) !== 1) {
            throw new BsaException('Tarif berubah oleh permintaan lain. Tidak ada data yang diubah; coba lagi.', 409);
        }
    }

    public function tautkanPengganti(int $lamaId, int $baruId): void
    {
        $this->execute('UPDATE bsa_tarif SET diganti_oleh_id = ? WHERE id = ? AND diganti_oleh_id IS NULL', [$baruId, $lamaId]);
    }

    /**
     * @param array{jenjang:string, berlaku_mulai:string, total:int, alasan:?string, idempotency_key:string,
     *     komponen:array<int, array{nama:string, nominal:int}>} $data
     */
    public function simpanTarif(array $data, int $userId, int $pengurusId, int $penugasanId): int
    {
        try {
            $this->execute(
                "INSERT INTO bsa_tarif (jenjang, berlaku_mulai, total, status, alasan, penugasan_id, pengurus_id, dibuat_oleh, idempotency_key)
                 VALUES (?, ?, ?, 'Berlaku', ?, ?, ?, ?, ?)",
                [$data['jenjang'], $data['berlaku_mulai'], $data['total'], $data['alasan'], $penugasanId, $pengurusId, $userId, $data['idempotency_key']]
            );
        } catch (BsaException $exception) {
            if ($exception->errno === 1062) {
                throw new BsaException('Tarif unit untuk periode ini baru saja disimpan permintaan lain. Muat ulang lalu ulangi bila perlu.', 409);
            }
            throw $exception;
        }
        $id = (int) $this->db->insert_id;
        foreach (array_values($data['komponen']) as $i => $k) {
            $this->execute('INSERT INTO bsa_tarif_komponen (tarif_id, urutan, nama, nominal) VALUES (?, ?, ?, ?)', [$id, $i + 1, $k['nama'], $k['nominal']]);
        }

        return $id;
    }

    /**
     * Tarif efektif seluruh unit untuk satu periode: baris Berlaku dengan
     * periode mulai terbesar yang ≤ periode. Kunci = unit huruf kecil.
     *
     * @return array<string, array{id:int, jenjang:string, berlaku_mulai:string, total:int}>
     */
    public function tarifEfektif(string $periode): array
    {
        $hasil = [];
        foreach ($this->all(
            "SELECT t.id, t.jenjang, t.berlaku_mulai, t.total
               FROM bsa_tarif t
              WHERE t.status = 'Berlaku' AND t.berlaku_mulai <= ?
                AND t.berlaku_mulai = (SELECT MAX(t2.berlaku_mulai) FROM bsa_tarif t2
                                        WHERE t2.status = 'Berlaku' AND t2.jenjang = t.jenjang AND t2.berlaku_mulai <= ?)",
            [$periode, $periode]
        ) as $r) {
            $hasil[mb_strtolower(trim((string) $r['jenjang']))] = ['id' => (int) $r['id'], 'jenjang' => (string) $r['jenjang'], 'berlaku_mulai' => (string) $r['berlaku_mulai'], 'total' => (int) $r['total']];
        }

        return $hasil;
    }

    // =======================================================================
    // Penyesuaian per santri
    // =======================================================================

    /** @return array<int, array<string, mixed>> */
    public function penyesuaianSantri(int $santriId): array
    {
        return $this->all(
            'SELECT j.id, j.santri_id, j.jenis, j.nama, j.nominal, j.berlaku_mulai, j.berlaku_sampai, j.alasan, j.status,
                    j.dicabut_pada, j.alasan_cabut, j.created_at,
                    COALESCE(p.nama, u.name) AS pembuat_nama, uc.name AS pencabut_nama
               FROM bsa_penyesuaian j
               JOIN users u ON u.id = j.dibuat_oleh
               LEFT JOIN pengurus p ON p.id = j.pengurus_id
               LEFT JOIN users uc ON uc.id = j.dicabut_oleh
              WHERE j.santri_id = ?
              ORDER BY j.status, j.berlaku_mulai DESC, j.id DESC LIMIT 100',
            [$santriId]
        );
    }

    public function jumlahPenyesuaianAktif(int $santriId): int
    {
        return (int) ($this->one("SELECT COUNT(*) AS n FROM bsa_penyesuaian WHERE santri_id = ? AND status = 'Aktif'", [$santriId])['n'] ?? 0);
    }

    /**
     * Penyesuaian aktif yang mencakup periode untuk beberapa santri.
     *
     * @param array<int, int> $santriIds
     * @return array<int, array<int, array{id:int, jenis:string, nama:string, nominal:int, alasan:string}>>
     */
    public function penyesuaianBerlaku(array $santriIds, string $periode): array
    {
        if ($santriIds === []) {
            return [];
        }
        $hasil = [];
        foreach (array_chunk($santriIds, 500) as $potong) {
            foreach ($this->all(
                "SELECT id, santri_id, jenis, nama, nominal, alasan FROM bsa_penyesuaian
                  WHERE status = 'Aktif' AND berlaku_mulai <= ? AND (berlaku_sampai IS NULL OR berlaku_sampai >= ?)
                    AND santri_id IN (" . implode(', ', array_fill(0, count($potong), '?')) . ')
                  ORDER BY santri_id, id',
                [$periode, $periode, ...$potong]
            ) as $r) {
                $hasil[(int) $r['santri_id']][] = ['id' => (int) $r['id'], 'jenis' => (string) $r['jenis'], 'nama' => (string) $r['nama'], 'nominal' => (int) $r['nominal'], 'alasan' => (string) $r['alasan']];
            }
        }

        return $hasil;
    }

    /**
     * @param array{jenis:string, nama:string, nominal:int, berlaku_mulai:string, berlaku_sampai:?string, alasan:string, idempotency_key:string} $data
     */
    public function simpanPenyesuaian(int $santriId, array $data, int $userId, int $pengurusId, int $penugasanId): int
    {
        $this->execute(
            "INSERT INTO bsa_penyesuaian (santri_id, jenis, nama, nominal, berlaku_mulai, berlaku_sampai, alasan, status, penugasan_id, pengurus_id, dibuat_oleh, idempotency_key)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'Aktif', ?, ?, ?, ?)",
            [$santriId, $data['jenis'], $data['nama'], $data['nominal'], $data['berlaku_mulai'], $data['berlaku_sampai'], $data['alasan'], $penugasanId, $pengurusId, $userId, $data['idempotency_key']]
        );

        return (int) $this->db->insert_id;
    }

    /** @return array{id:int, santri_id:int, status:string}|null */
    public function penyesuaian(int $id, bool $kunci = false): ?array
    {
        $row = $this->one('SELECT id, santri_id, status FROM bsa_penyesuaian WHERE id = ?' . ($kunci ? ' FOR UPDATE' : ''), [$id]);

        return $row === null ? null : ['id' => (int) $row['id'], 'santri_id' => (int) $row['santri_id'], 'status' => (string) $row['status']];
    }

    public function cabutPenyesuaian(int $id, int $userId, string $alasan): void
    {
        if ($this->affected("UPDATE bsa_penyesuaian SET status = 'Dicabut', dicabut_oleh = ?, dicabut_pada = NOW(), alasan_cabut = ? WHERE id = ? AND status = 'Aktif'", [$userId, $alasan, $id]) !== 1) {
            throw new BsaException('Penyesuaian sudah dicabut. Tidak ada data yang diubah.', 409);
        }
    }

    // =======================================================================
    // Penerbitan massal
    // =======================================================================

    /**
     * Santri aktif sasaran penerbitan beserta unit penempatan aktif.
     *
     * @param array<int, string>|null $cakupan unit petugas (null = seluruh unit)
     * @param string|null $unit saringan satu unit (null = seluruh cakupan)
     * @param int|null $santriId saringan satu santri (penerbitan dari rincian santri)
     * @return array<int, array{id:int, nis:string, nama:string, kelas:?string, jenjang:?string}>
     */
    public function sasaranPenerbitan(?array $cakupan, ?string $unit, ?int $santriId = null): array
    {
        $params = [];
        $sql = 'SELECT s.id, s.nis, s.nama_santri, kl.nama_kelas, kl.jenjang FROM santri s' . $this->bsa->joinUnit() . '
                 WHERE s.is_active = 1 AND s.archived_at IS NULL';
        if ($cakupan !== null) {
            if ($cakupan === []) {
                return [];
            }
            $sql .= ' AND LOWER(TRIM(kl.jenjang)) IN (' . implode(', ', array_fill(0, count($cakupan), '?')) . ')';
            foreach ($cakupan as $c) {
                $params[] = mb_strtolower(trim($c));
            }
        }
        if ($unit !== null) {
            $sql .= ' AND LOWER(TRIM(kl.jenjang)) = ?';
            $params[] = mb_strtolower(trim($unit));
        }
        if ($santriId !== null) {
            $sql .= ' AND s.id = ?';
            $params[] = $santriId;
        }

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'nis' => (string) $r['nis'], 'nama' => (string) $r['nama_santri'],
            'kelas' => $r['nama_kelas'] === null ? null : (string) $r['nama_kelas'],
            'jenjang' => $r['jenjang'] === null || trim((string) $r['jenjang']) === '' ? null : trim((string) $r['jenjang']),
        ], $this->all($sql . ' ORDER BY s.id', $params));
    }

    /**
     * Mengunci baca baris santri aktif sasaran (urut ID, satu urutan untuk
     * semua penerbitan) dan mengembalikan ID yang masih aktif.
     *
     * @param array<int, int> $ids
     * @return array<int, int>
     */
    public function kunciSantri(array $ids): array
    {
        $aktif = [];
        sort($ids);
        foreach (array_chunk($ids, 500) as $potong) {
            foreach ($this->all('SELECT id FROM santri WHERE id IN (' . implode(', ', array_fill(0, count($potong), '?')) . ') AND is_active = 1 AND archived_at IS NULL ORDER BY id LOCK IN SHARE MODE', $potong) as $r) {
                $aktif[] = (int) $r['id'];
            }
        }

        return $aktif;
    }

    /**
     * Tagihan yang sudah ada untuk periode (status apa pun), per santri.
     *
     * @param array<int, int> $santriIds
     * @return array<int, array{id:int, status:string}>
     */
    public function tagihanPeriode(array $santriIds, string $periode): array
    {
        $hasil = [];
        foreach (array_chunk($santriIds, 500) as $potong) {
            foreach ($this->all('SELECT id, santri_id, status FROM bsa_tagihan WHERE periode = ? AND santri_id IN (' . implode(', ', array_fill(0, count($potong), '?')) . ')', [$periode, ...$potong]) as $r) {
                $hasil[(int) $r['santri_id']] = ['id' => (int) $r['id'], 'status' => (string) $r['status']];
            }
        }

        return $hasil;
    }

    /** @param array{periode:string, jenjang:?string, jatuh_tempo:string, idempotency_key:string} $data */
    public function simpanPenerbitan(array $data, array $jumlah, string $hash, int $userId, int $pengurusId, int $penugasanId): int
    {
        $this->execute(
            'INSERT INTO bsa_penerbitan (periode, jenjang, jatuh_tempo, jumlah_sasaran, jumlah_terbit, jumlah_sudah_ada, jumlah_dilewati, pratinjau_hash,
                                         penugasan_id, pengurus_id, dibuat_oleh, idempotency_key)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$data['periode'], $data['jenjang'], $data['jatuh_tempo'], $jumlah['sasaran'], $jumlah['Terbit'], $jumlah['SudahAda'], $jumlah['Dilewati'], $hash,
                $penugasanId, $pengurusId, $userId, $data['idempotency_key']]
        );

        return (int) $this->db->insert_id;
    }

    public function simpanHasilPenerbitan(int $penerbitanId, int $santriId, ?string $jenjang, string $hasil, ?int $tagihanId, ?int $tarifId, ?string $keterangan): void
    {
        $this->execute(
            'INSERT INTO bsa_penerbitan_santri (penerbitan_id, santri_id, jenjang, hasil, tagihan_id, tarif_id, keterangan) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$penerbitanId, $santriId, $jenjang, $hasil, $tagihanId, $tarifId, $keterangan]
        );
    }

    /** @return array<string, mixed>|null */
    public function penerbitan(int $id): ?array
    {
        return $this->one(
            'SELECT b.id, b.periode, b.jenjang, b.jatuh_tempo, b.jumlah_sasaran, b.jumlah_terbit, b.jumlah_sudah_ada, b.jumlah_dilewati, b.created_at,
                    b.dibuat_oleh, COALESCE(p.nama, u.name) AS pembuat_nama
               FROM bsa_penerbitan b JOIN users u ON u.id = b.dibuat_oleh LEFT JOIN pengurus p ON p.id = b.pengurus_id WHERE b.id = ?',
            [$id]
        );
    }

    /**
     * Hak memakai status/penempatan santri saat permintaan, bukan snapshot
     * jenjang di h. Admin tetap dapat mengaudit seluruh hasil historis.
     * @param array{mode:string, user_id:int, jenjang:?array<int, string>} $hak
     * @return array<int, array<string, mixed>>
     */
    public function hasilPenerbitan(int $id, array $hak): array
    {
        $params = [];
        $syarat = $this->syaratHak($hak, $params);
        $params[] = $id;
        return $this->all(
            'SELECT h.santri_id, h.jenjang, h.hasil, h.tagihan_id, h.tarif_id, h.keterangan, s.nis, s.nama_santri, t.total
               FROM bsa_penerbitan_santri h
               JOIN santri s ON s.id = h.santri_id
               LEFT JOIN bsa_tagihan t ON t.id = h.tagihan_id' . $this->bsa->joinUnit() . $syarat . '
                AND h.penerbitan_id = ? ORDER BY FIELD(h.hasil, \'Terbit\', \'SudahAda\', \'Dilewati\'), s.nama_santri, h.santri_id',
            $params
        );
    }

    /**
     * @param array<int, string>|null $unit null = semua; daftar = penerbitan unit
     *   itu atau tanpa saringan unit yang dibuat akun `$userId` sendiri
     * @return array{rows:array<int, array<string, mixed>>, total:int, page:int, perPage:int}
     */
    public function daftarPenerbitan(?array $unit, int $userId, int $page): array
    {
        $params = [];
        $sql = 'SELECT b.id, b.periode, b.jenjang, b.jatuh_tempo, b.jumlah_sasaran, b.jumlah_terbit, b.jumlah_sudah_ada, b.jumlah_dilewati, b.created_at,
                       b.dibuat_oleh, COALESCE(p.nama, u.name) AS pembuat_nama
                  FROM bsa_penerbitan b JOIN users u ON u.id = b.dibuat_oleh LEFT JOIN pengurus p ON p.id = b.pengurus_id';
        if ($unit !== null) {
            $sql .= ' WHERE (b.jenjang IS NULL AND b.dibuat_oleh = ?)' . ($unit === [] ? '' : ' OR LOWER(b.jenjang) IN (' . implode(', ', array_fill(0, count($unit), '?')) . ')');
            $params[] = $userId;
            foreach ($unit as $u) {
                $params[] = mb_strtolower(trim($u));
            }
        }

        return PageQuery::fetch($this->db, $sql, $params, 'id DESC', max(1, $page));
    }

    // =======================================================================
    // Tagihan: baris penyesuaian, riwayat, revisi, pembatalan
    // =======================================================================

    public function simpanBarisPenyesuaian(int $tagihanId, int $versi, string $jenis, string $nama, int $nominal, string $asal, ?int $penyesuaianId, string $alasan, int $userId): void
    {
        $this->execute(
            'INSERT INTO bsa_tagihan_penyesuaian (tagihan_id, versi, jenis, nama, nominal, asal, penyesuaian_id, alasan, dicatat_oleh) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$tagihanId, $versi, $jenis, $nama, $nominal, $asal, $penyesuaianId, $alasan, $userId]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function barisPenyesuaian(int $tagihanId): array
    {
        return $this->all(
            'SELECT b.id, b.versi, b.jenis, b.nama, b.nominal, b.asal, b.penyesuaian_id, b.alasan, b.created_at, u.name AS pencatat_nama
               FROM bsa_tagihan_penyesuaian b JOIN users u ON u.id = b.dicatat_oleh WHERE b.tagihan_id = ? ORDER BY b.id',
            [$tagihanId]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function riwayatTagihan(int $tagihanId): array
    {
        return $this->all(
            'SELECT r.versi, r.tindakan, r.total, r.jatuh_tempo, r.alasan, r.created_at, COALESCE(p.nama, u.name) AS aktor_nama
               FROM bsa_tagihan_riwayat r
               JOIN users u ON u.id = r.aktor
               LEFT JOIN pengurus p ON p.id = u.pengurus_id
              WHERE r.tagihan_id = ? ORDER BY r.versi',
            [$tagihanId]
        );
    }

    public function catatRiwayat(int $tagihanId, int $versi, string $tindakan, int $total, string $jatuhTempo, ?string $alasan, int $penugasanId, int $userId): void
    {
        $this->execute(
            'INSERT INTO bsa_tagihan_riwayat (tagihan_id, versi, tindakan, total, jatuh_tempo, alasan, penugasan_id, aktor) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$tagihanId, $versi, $tindakan, $total, $jatuhTempo, $alasan, $penugasanId, $userId]
        );
    }

    /**
     * Baris tagihan dikunci tulis di dalam transaksi.
     *
     * @return array{id:int, santri_id:int, periode:string, jatuh_tempo:string, total:int, terbayar:int, status:string, versi:int, menunggu:int}|null
     */
    public function tagihanTerkunci(int $id): ?array
    {
        $row = $this->one('SELECT id, santri_id, periode, jatuh_tempo, total, terbayar, status, versi FROM bsa_tagihan WHERE id = ? FOR UPDATE', [$id]);
        if ($row === null) {
            return null;
        }
        $menunggu = (int) ($this->one("SELECT COALESCE(SUM(nominal), 0) AS n FROM bsa_pembayaran WHERE tagihan_id = ? AND status = 'Menunggu'", [$id])['n'] ?? 0);

        return [
            'id' => (int) $row['id'], 'santri_id' => (int) $row['santri_id'], 'periode' => (string) $row['periode'], 'jatuh_tempo' => (string) $row['jatuh_tempo'],
            'total' => (int) $row['total'], 'terbayar' => (int) $row['terbayar'], 'status' => (string) $row['status'], 'versi' => (int) $row['versi'], 'menunggu' => $menunggu,
        ];
    }

    /** Santri pemilik tagihan (tanpa kunci; untuk pemeriksaan hak sebelum mengunci). */
    public function santriTagihan(int $tagihanId): ?int
    {
        $row = $this->one('SELECT santri_id FROM bsa_tagihan WHERE id = ?', [$tagihanId]);

        return $row === null ? null : (int) $row['santri_id'];
    }

    /** Revisi/pembatalan bersyarat versi (optimistic) dan terbayar yang telah dikunci. */
    public function ubahTagihan(int $id, int $versiLama, int $total, string $jatuhTempo, string $status): void
    {
        // Tagihan yang dibuat sebelum 028 sah tanpa riwayat versi 1.
        // Saat koreksi pertama, simpan keadaan ASLI dari baris yang sudah
        // dikunci, sebelum total/status berubah. Tidak membuat aktor/waktu
        // penerbitan baru dan tidak memerlukan backfill migrasi tabel lama.
        if ($versiLama === 1) {
            $this->execute(
                "INSERT INTO bsa_tagihan_riwayat (tagihan_id, versi, tindakan, total, jatuh_tempo, alasan, penugasan_id, aktor, created_at)
                 SELECT t.id, 1, 'Terbit', t.total, t.jatuh_tempo, NULL, t.penugasan_id, t.diterbitkan_oleh, t.created_at
                   FROM bsa_tagihan t WHERE t.id = ? AND t.versi = 1
                    AND NOT EXISTS (SELECT 1 FROM bsa_tagihan_riwayat r WHERE r.tagihan_id = t.id AND r.versi = 1)",
                [$id]
            );
        }
        if ($this->affected('UPDATE bsa_tagihan SET total = ?, jatuh_tempo = ?, status = ?, versi = versi + 1 WHERE id = ? AND versi = ?', [$total, $jatuhTempo, $status, $id, $versiLama]) !== 1) {
            throw new BsaException('Tagihan berubah oleh permintaan lain. Tidak ada data yang diubah; muat ulang.', 409);
        }
    }

    public function ubahTerbayar(int $tagihanId, int $lama, int $baru): void
    {
        if ($this->affected("UPDATE bsa_tagihan SET terbayar = ? WHERE id = ? AND terbayar = ? AND status = 'Terbit'", [$baru, $tagihanId, $lama]) !== 1) {
            throw new BsaException('Tagihan berubah oleh permintaan lain. Tidak ada data yang diubah; coba lagi.', 409);
        }
    }

    // =======================================================================
    // Pembayaran, kuitansi, pembatalan
    // =======================================================================

    /**
     * Menyisipkan pembayaran; nomor kuitansi diturunkan dari ID agar urut dan
     * unik (`BSA-KW-TTTT-000123`), diisi di transaksi yang sama.
     *
     * Fase 3: sumber `Tabungan` wajib membawa `tabungan_transaksi_id` = debit
     * ledger `PembayaranTagihan` yang disisipkan di transaksi yang sama (kunci
     * unik + CHECK 027: satu pembayaran ⇔ satu debit).
     *
     * @param array{nominal:int, sumber:string, tanggal_transaksi:string, referensi:?string, catatan:?string, idempotency_key:string,
     *     tabungan_transaksi_id?:?int} $data
     * @return array{id:int, nomor:string}
     */
    public function simpanPembayaran(int $tagihanId, int $santriId, array $data, bool $sah, int $userId, int $pengurusId, int $penugasanId): array
    {
        $sementara = 'TMP-' . bin2hex(random_bytes(12));
        $this->execute(
            'INSERT INTO bsa_pembayaran (tagihan_id, santri_id, nominal, sumber, status, tanggal_transaksi, referensi, nomor_kuitansi, tabungan_transaksi_id, alasan,
                                         penugasan_id, pengurus_id, dicatat_oleh, diverifikasi_oleh, diverifikasi_pada, idempotency_key)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ' . ($sah ? 'NOW()' : 'NULL') . ', ?)',
            [$tagihanId, $santriId, $data['nominal'], $data['sumber'], $sah ? 'Sah' : 'Menunggu', $data['tanggal_transaksi'], $data['referensi'], $sementara,
                $data['tabungan_transaksi_id'] ?? null, $data['catatan'], $penugasanId, $pengurusId, $userId, $sah ? $userId : null, $data['idempotency_key']]
        );
        $id = (int) $this->db->insert_id;
        $nomor = 'BSA-KW-' . date('Y') . '-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
        $this->execute('UPDATE bsa_pembayaran SET nomor_kuitansi = ? WHERE id = ? AND nomor_kuitansi = ?', [$nomor, $id, $sementara]);

        return ['id' => $id, 'nomor' => $nomor];
    }

    /** @return array{tagihan_id:int, santri_id:int}|null */
    public function pemilikPembayaran(int $id): ?array
    {
        $row = $this->one('SELECT tagihan_id, santri_id FROM bsa_pembayaran WHERE id = ?', [$id]);

        return $row === null ? null : ['tagihan_id' => (int) $row['tagihan_id'], 'santri_id' => (int) $row['santri_id']];
    }

    /** @return array{id:int, tagihan_id:int, nominal:int, sumber:string, status:string, tabungan_transaksi_id:?int}|null */
    public function pembayaranTerkunci(int $id): ?array
    {
        $row = $this->one('SELECT id, tagihan_id, nominal, sumber, status, tabungan_transaksi_id FROM bsa_pembayaran WHERE id = ? FOR UPDATE', [$id]);

        return $row === null ? null : ['id' => (int) $row['id'], 'tagihan_id' => (int) $row['tagihan_id'], 'nominal' => (int) $row['nominal'], 'sumber' => (string) $row['sumber'],
            'status' => (string) $row['status'], 'tabungan_transaksi_id' => $row['tabungan_transaksi_id'] === null ? null : (int) $row['tabungan_transaksi_id']];
    }

    public function verifikasiPembayaran(int $id, int $userId): void
    {
        if ($this->affected("UPDATE bsa_pembayaran SET status = 'Sah', diverifikasi_oleh = ?, diverifikasi_pada = NOW() WHERE id = ? AND status = 'Menunggu'", [$userId, $id]) !== 1) {
            throw new BsaException('Pembayaran berubah oleh permintaan lain. Tidak ada data yang diubah.', 409);
        }
    }

    public function tandaiPembayaranBatal(int $id, string $statusLama): void
    {
        if ($this->affected("UPDATE bsa_pembayaran SET status = 'Dibatalkan' WHERE id = ? AND status = ?", [$id, $statusLama]) !== 1) {
            throw new BsaException('Pembayaran berubah oleh permintaan lain. Tidak ada data yang diubah.', 409);
        }
    }

    public function simpanKuitansi(int $pembayaranId, int $versi, int $total, int $terbayarSebelum, int $sisaSesudah): void
    {
        $this->execute(
            'INSERT INTO bsa_pembayaran_kuitansi (pembayaran_id, tagihan_versi, total_tagihan, terbayar_sebelum, sisa_sesudah) VALUES (?, ?, ?, ?, ?)',
            [$pembayaranId, $versi, $total, $terbayarSebelum, $sisaSesudah]
        );
    }

    public function simpanPembatalan(int $pembayaranId, string $statusLama, string $alasan, int $userId, int $pengurusId, int $penugasanId): void
    {
        $this->execute(
            'INSERT INTO bsa_pembayaran_pembatalan (pembayaran_id, jenis, status_sebelum, alasan, penugasan_id, pengurus_id, dibatalkan_oleh) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$pembayaranId, $statusLama === 'Menunggu' ? 'Penolakan' : 'Pembatalan', $statusLama, $alasan, $penugasanId, $pengurusId, $userId]
        );
    }

    /** Pilihan kolom pembayaran beserta kuitansi, pembatalan, tagihan, dan santri. */
    private const PEMBAYARAN = 'SELECT b.id, b.tagihan_id, b.santri_id, b.nominal, b.sumber, b.status, b.tanggal_transaksi, b.referensi, b.nomor_kuitansi,
                    b.alasan AS catatan, b.diverifikasi_pada, b.created_at, b.tabungan_transaksi_id, tx.created_at AS debit_dibuat,
                    (SELECT rv.id FROM bsa_tabungan_transaksi rv WHERE rv.pembalik_dari_id = b.tabungan_transaksi_id) AS pengembalian_id,
                    COALESCE(pc.nama, uc.name) AS pencatat_nama, COALESCE(pv.nama, uv.name) AS verifikator_nama,
                    k.tagihan_versi, k.total_tagihan, k.terbayar_sebelum, k.sisa_sesudah,
                    x.jenis AS batal_jenis, x.alasan AS batal_alasan, x.created_at AS batal_pada, ux.name AS pembatal_nama,
                    t.periode, t.status AS tagihan_status, t.total - t.terbayar AS sisa_kini, s.nis, s.nama_santri
               FROM bsa_pembayaran b
               JOIN bsa_tagihan t ON t.id = b.tagihan_id
               JOIN santri s ON s.id = b.santri_id
               JOIN users uc ON uc.id = b.dicatat_oleh
               LEFT JOIN pengurus pc ON pc.id = b.pengurus_id
               LEFT JOIN users uv ON uv.id = b.diverifikasi_oleh
               LEFT JOIN pengurus pv ON pv.id = uv.pengurus_id
               LEFT JOIN bsa_pembayaran_kuitansi k ON k.pembayaran_id = b.id
               LEFT JOIN bsa_pembayaran_pembatalan x ON x.pembayaran_id = b.id
               LEFT JOIN users ux ON ux.id = x.dibatalkan_oleh
               LEFT JOIN bsa_tabungan_transaksi tx ON tx.id = b.tabungan_transaksi_id';

    /** @return array<string, mixed>|null */
    public function pembayaran(int $id): ?array
    {
        return $this->one(self::PEMBAYARAN . ' WHERE b.id = ?', [$id]);
    }

    /** @return array<int, array<string, mixed>> */
    public function pembayaranTagihan(int $tagihanId): array
    {
        return $this->all(self::PEMBAYARAN . ' WHERE b.tagihan_id = ? ORDER BY b.id', [$tagihanId]);
    }

    /**
     * Riwayat pembayaran menurut hak pembaca.
     *
     * @param array{mode:string, user_id:int, jenjang:?array<int, string>} $hak
     * @param array{periode:?string, status:?string, sumber:?string} $saring
     * @return array{rows:array<int, array<string, mixed>>, total:int, page:int, perPage:int}
     */
    public function daftarPembayaran(array $hak, array $saring, string $q, int $page, bool $tanpaMenunggu = false): array
    {
        $params = [];
        $sql = self::PEMBAYARAN . $this->bsa->joinUnit() . $this->syaratHak($hak, $params);
        // Fase 4: orang tua tidak melihat transfer menunggu — disaring di SQL agar
        // jumlah total dan halaman juga tidak menghitungnya.
        if ($tanpaMenunggu) {
            $sql .= " AND b.status <> 'Menunggu'";
        }
        foreach (['periode' => 't.periode', 'status' => 'b.status', 'sumber' => 'b.sumber'] as $kunci => $kolom) {
            if ($saring[$kunci] !== null) {
                $sql .= ' AND ' . $kolom . ' = ?';
                $params[] = $saring[$kunci];
            }
        }

        return PageQuery::fetch($this->db, $sql, $params, 'id DESC', max(1, $page), $q, ['nama_santri', 'nis', 'nomor_kuitansi']);
    }

    /**
     * Tunggakan: tagihan Terbit dengan sisa > 0, menurut hak pembaca.
     *
     * @param array{mode:string, user_id:int, jenjang:?array<int, string>} $hak
     * @param array{periode:?string, unit:?string, lewat:bool} $saring
     * @return array{rows:array<int, array<string, mixed>>, total:int, page:int, perPage:int, ringkasan:array{jumlah:int, sisa:int, santri:int}}
     */
    public function tunggakan(array $hak, array $saring, string $q, int $page): array
    {
        $params = [];
        $sql = "SELECT t.id, t.santri_id, t.periode, t.jatuh_tempo, t.total, t.terbayar, t.total - t.terbayar AS sisa, t.jenjang AS unit_saat_terbit,
                       s.nis, s.nama_santri, kl.nama_kelas, kl.jenjang,
                       (SELECT COALESCE(SUM(m.nominal), 0) FROM bsa_pembayaran m WHERE m.tagihan_id = t.id AND m.status = 'Menunggu') AS menunggu
                  FROM bsa_tagihan t
                  JOIN santri s ON s.id = t.santri_id" . $this->bsa->joinUnit() . $this->syaratHak($hak, $params) . "
                   AND t.status = 'Terbit' AND t.terbayar < t.total";
        if ($saring['periode'] !== null) {
            $sql .= ' AND t.periode = ?';
            $params[] = $saring['periode'];
        }
        if ($saring['unit'] !== null) {
            $sql .= ' AND LOWER(TRIM(kl.jenjang)) = ?';
            $params[] = mb_strtolower(trim($saring['unit']));
        }
        if ($saring['lewat']) {
            $sql .= ' AND t.jatuh_tempo < CURDATE()';
        }
        $hasil = PageQuery::fetch($this->db, $sql, $params, 'jatuh_tempo, nama_santri, id', max(1, $page), $q, ['nama_santri', 'nis']);
        $ringkas = $this->one('SELECT COUNT(*) AS jumlah, COALESCE(SUM(sisa), 0) AS sisa, COUNT(DISTINCT santri_id) AS santri FROM (' . $sql . ') r'
            . ($q === '' ? '' : ' WHERE (nama_santri LIKE ? OR nis LIKE ?)'), $q === '' ? $params : [...$params, '%' . $q . '%', '%' . $q . '%']);
        $hasil['ringkasan'] = ['jumlah' => (int) ($ringkas['jumlah'] ?? 0), 'sisa' => (int) ($ringkas['sisa'] ?? 0), 'santri' => (int) ($ringkas['santri'] ?? 0)];

        return $hasil;
    }

    /**
     * Syarat hak atas santri alias `s` (unit dari `kl` joinUnit): petugas =
     * santri aktif dalam cakupan unit; admin = semua; wali = anak dengan
     * relasi aktif. Selalu diakhiri klausa WHERE agar pemanggil menambah AND.
     * Publik agar daftar Fase 3 (`TabunganRepository`) memakai syarat yang sama.
     *
     * @param array{mode:string, user_id:int, jenjang:?array<int, string>} $hak
     * @param array<int, mixed> $params
     */
    public function syaratHak(array $hak, array &$params): string
    {
        switch ($hak['mode']) {
            case 'wali':
                $params[] = $hak['user_id'];

                return $this->bsa->waliSyarat();
            case 'admin':
                return ' WHERE 1 = 1';
            default:
                $sql = ' WHERE s.is_active = 1 AND s.archived_at IS NULL';
                if ($hak['jenjang'] !== null) {
                    if ($hak['jenjang'] === []) {
                        return $sql . ' AND 1 = 0';
                    }
                    $sql .= ' AND LOWER(TRIM(kl.jenjang)) IN (' . implode(', ', array_fill(0, count($hak['jenjang']), '?')) . ')';
                    foreach ($hak['jenjang'] as $j) {
                        $params[] = mb_strtolower(trim($j));
                    }
                }

                return $sql;
        }
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

    private function affected(string $sql, array $params): int
    {
        $statement = $this->prepare($sql);
        try {
            $this->run($statement, $params);

            return (int) $statement->affected_rows;
        } finally {
            $statement->close();
        }
    }

    private function prepare(string $sql): mysqli_stmt
    {
        $statement = $this->db->prepare($sql);
        if ($statement === false) {
            throw new BsaException('Data tagihan tidak tersedia.', 503);
        }

        return $statement;
    }

    /** Pemetaan galat sama dengan `BsaRepository` (kunci → 409, CHECK → 422). */
    private function run(mysqli_stmt $statement, array $params): void
    {
        if ($params !== []) {
            $types = implode('', array_map(static fn (mixed $v): string => is_int($v) ? 'i' : 's', $params));
            $statement->bind_param($types, ...$params);
        }
        if (!$statement->execute()) {
            if (in_array($statement->errno, [1020, 1205, 1213], true)) {
                throw new BsaException('Data sedang diproses permintaan lain. Tidak ada data yang diubah; coba lagi.', 409);
            }
            if (in_array($statement->errno, [3819, 4025], true)) {
                throw new BsaException('Transaksi ditolak aturan nominal tagihan. Tidak ada data yang diubah.', 422);
            }
            throw new BsaException('Data tagihan tidak dapat disimpan.', 503, (int) $statement->errno);
        }
    }
}
