<?php

declare(strict_types=1);

namespace App\Bsa;

use App\Database\PageQuery;
use App\Database\Schema;
use mysqli;
use mysqli_stmt;
use Throwable;

/**
 * Akses basis data keuangan santri/BSA (PRD V6 Fase 1, migrasi 027).
 *
 * Seluruh SQL memakai parameter terikat. Repository tidak memutuskan hak:
 * `BsaService` memeriksa penugasan/cakupan/relasi wali dan baru memanggil
 * metode di sini setelah akses sah.
 *
 * URUTAN PENGUNCIAN mutasi (satu urutan untuk semua operasi, mencegah
 * deadlock dan dua penarikan membelanjakan uang yang sama):
 *   1. baris penugasan BSA akun (kunci baca)          `kunciPenugasan()`
 *   2. klaim idempotensi akun (kunci tulis)           `klaimIdempotensi()`
 *   3. baris santri (kunci baca)                      `santriTerkunci()`
 *   4. akun tabungan santri (kunci tulis)             `kunciAkun()`
 * Akun tabungan dibuat lebih dahulu di luar transaksi (`pastikanAkun()`),
 * sehingga dua transaksi tidak pernah berebut menyisipkan baris akun yang sama.
 */
final class BsaRepository
{
    public const TABEL = ['bsa_tagihan', 'bsa_tagihan_komponen', 'bsa_tabungan_akun', 'bsa_tabungan_transaksi', 'bsa_pembayaran', 'bsa_idempotensi'];

    /** Tahun ajaran aktif untuk penempatan kelas (unit/jenjang santri). */
    private const TAHUN_AKTIF = "(SELECT ta.id FROM tahun_ajaran ta WHERE ta.status = 'Aktif' AND ta.archived_at IS NULL ORDER BY ta.id DESC LIMIT 1)";

    public function __construct(private mysqli $db)
    {
    }

    /** Migrasi 027 terpasang (enam tabel). */
    public function skemaSiap(): bool
    {
        foreach (self::TABEL as $tabel) {
            if (!Schema::hasTable($this->db, $tabel)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Transaksi mutasi BSA berjalan pada READ COMMITTED (hanya transaksi
     * berikutnya; sesi lain tidak terpengaruh). Kebenaran saldo bertumpu pada
     * kunci baris eksplisit (`FOR UPDATE`/`LOCK IN SHARE MODE`) dan kunci unik,
     * bukan pada snapshot REPEATABLE READ: pada MariaDB 11.6+ dengan
     * `innodb_snapshot_isolation=ON`, kunci baris akun yang diubah transaksi
     * lain sesudah snapshot dibuat akan gagal (galat 1020) alih-alih menunggu,
     * sehingga dua penarikan berurutan ditolak tanpa alasan bisnis.
     */
    public function transaction(callable $work): mixed
    {
        if (!$this->db->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED') || !$this->db->begin_transaction()) {
            throw new BsaException('Transaksi tidak tersedia.', 503);
        }
        try {
            $result = $work();
            if (!$this->db->commit()) {
                throw new BsaException('Transaksi tidak dapat disimpan.', 503);
            }

            return $result;
        } catch (Throwable $exception) {
            $this->db->rollback();
            throw $exception;
        }
    }

    // =======================================================================
    // Penugasan dan cakupan
    // =======================================================================

    /**
     * Penugasan BSA AKTIF milik akun. Syaratnya sama dengan resolver
     * `Capabilities` untuk jenis `bendahara_bulanan` (akun aktif, peran
     * pengurus, pengurus aktif, penugasan aktif pada tanggal berjalan, tahun
     * ajaran tidak diarsipkan; tidak gugur saat semester berganti). Dengan
     * `$kunci`, baris dibaca dengan kunci baca di dalam transaksi: admin yang
     * mengakhiri/menonaktifkan penugasan memerlukan kunci tulis pada baris yang
     * sama, sehingga mutasi yang menunggu membaca keadaan terbaru dan ditolak.
     *
     * @return array<int, array{id:int, pengurus_id:int, jenjang:?string}>
     */
    public function penugasanAktif(int $userId, bool $kunci = false): array
    {
        $rows = $this->all(
            "SELECT a.id, a.pengurus_id, a.jenjang
               FROM users u
               JOIN user_roles ur ON ur.user_id = u.id
               JOIN roles r ON r.id = ur.role_id AND r.slug = 'pengurus'
               JOIN pengurus p ON p.id = u.pengurus_id
               JOIN bendahara_bulanan_assignments a ON a.pengurus_id = p.id
               JOIN tahun_ajaran ta ON ta.id = a.tahun_ajaran_id
              WHERE u.id = ? AND u.is_active = 1
                AND p.is_active = 1 AND p.archived_at IS NULL
                AND a.is_active = 1
                AND a.tanggal_mulai <= CURDATE()
                AND (a.tanggal_selesai IS NULL OR a.tanggal_selesai >= CURDATE())
                AND ta.archived_at IS NULL
              ORDER BY a.id"
            . ($kunci ? ' LOCK IN SHARE MODE' : ''),
            [$userId]
        );

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'pengurus_id' => (int) $r['pengurus_id'],
            'jenjang' => $r['jenjang'] === null || trim((string) $r['jenjang']) === '' ? null : (string) $r['jenjang'],
        ], $rows);
    }

    /** Akun orang tua aktif dengan master wali aktif. */
    public function waliAktif(int $userId): bool
    {
        return $this->one(
            "SELECT u.id FROM users u
               JOIN wali w ON w.id = u.wali_id AND w.is_active = 1 AND w.archived_at IS NULL
               JOIN user_roles ur ON ur.user_id = u.id
               JOIN roles r ON r.id = ur.role_id AND r.slug = 'orang_tua'
              WHERE u.id = ? AND u.is_active = 1
              LIMIT 1",
            [$userId]
        ) !== null;
    }

    // =======================================================================
    // Santri
    // =======================================================================

    /**
     * Satu santri beserta kelas/unit penempatan aktif dan saldo.
     *
     * @return array<string, mixed>|null
     */
    public function santri(int $santriId): ?array
    {
        return $this->one(
            'SELECT s.id, s.nis, s.nama_santri, s.is_active, s.archived_at, kl.nama_kelas, kl.jenjang,
                    COALESCE(ak.saldo, 0) AS saldo, COALESCE(ak.jumlah_transaksi, 0) AS jumlah_transaksi
               FROM santri s' . $this->joinUnit() . '
               LEFT JOIN bsa_tabungan_akun ak ON ak.santri_id = s.id
              WHERE s.id = ?',
            [$santriId]
        );
    }

    /**
     * Baris santri AKTIF dikunci baca di dalam transaksi mutasi; unit dibaca
     * dari penempatan kelas aktif. Null bila santri tidak ada/nonaktif.
     *
     * @return array{id:int, jenjang:?string}|null
     */
    public function santriTerkunci(int $santriId): ?array
    {
        $row = $this->one('SELECT id FROM santri WHERE id = ? AND is_active = 1 AND archived_at IS NULL LOCK IN SHARE MODE', [$santriId]);
        if ($row === null) {
            return null;
        }
        $unit = $this->one('SELECT kl.jenjang FROM santri s' . $this->joinUnit() . ' WHERE s.id = ?', [$santriId]);
        $jenjang = $unit['jenjang'] ?? null;

        return ['id' => (int) $row['id'], 'jenjang' => $jenjang === null || trim((string) $jenjang) === '' ? null : (string) $jenjang];
    }

    /** Relasi wali aktif akun atas santri aktif (dihitung ulang tiap permintaan). */
    public function anakWali(int $userId, int $santriId): bool
    {
        return $this->one('SELECT s.id FROM santri s' . $this->waliSyarat() . ' AND s.id = ?', [$userId, $santriId]) !== null;
    }

    /**
     * Daftar santri menurut hak pembaca.
     *
     * @param array{mode:string, user_id:int, jenjang:?array<int, string>} $hak
     *   mode `petugas` (santri aktif dalam cakupan; `jenjang` null = seluruh
     *   unit), `admin` (santri aktif + santri yang sudah punya data BSA),
     *   `wali` (anak dengan relasi aktif).
     * @return array{rows:array<int, array<string, mixed>>, total:int, page:int, perPage:int}
     */
    public function daftarSantri(array $hak, string $q, int $page): array
    {
        $params = [];
        $select = 'SELECT s.id, s.nis, s.nama_santri, kl.nama_kelas, kl.jenjang,
                          COALESCE(ak.saldo, 0) AS saldo,
                          (SELECT COUNT(*) FROM bsa_tagihan t WHERE t.santri_id = s.id AND t.status = \'Terbit\' AND t.terbayar < t.total) AS tagihan_terbuka,
                          (SELECT COALESCE(SUM(t.total - t.terbayar), 0) FROM bsa_tagihan t WHERE t.santri_id = s.id AND t.status = \'Terbit\') AS sisa_tagihan
                     FROM santri s' . $this->joinUnit() . '
                     LEFT JOIN bsa_tabungan_akun ak ON ak.santri_id = s.id';
        switch ($hak['mode']) {
            case 'wali':
                $sql = $select . $this->waliSyarat();
                $params[] = $hak['user_id'];
                break;
            case 'admin':
                $sql = $select . ' WHERE (s.is_active = 1 AND s.archived_at IS NULL)
                                     OR ak.santri_id IS NOT NULL
                                     OR EXISTS (SELECT 1 FROM bsa_tagihan t2 WHERE t2.santri_id = s.id)';
                break;
            default:
                $sql = $select . ' WHERE s.is_active = 1 AND s.archived_at IS NULL';
                if ($hak['jenjang'] !== null) {
                    if ($hak['jenjang'] === []) {
                        $sql .= ' AND 1 = 0';
                    } else {
                        $sql .= ' AND LOWER(TRIM(kl.jenjang)) IN (' . implode(', ', array_fill(0, count($hak['jenjang']), '?')) . ')';
                        foreach ($hak['jenjang'] as $j) {
                            $params[] = mb_strtolower(trim($j));
                        }
                    }
                }
        }

        return PageQuery::fetch($this->db, $sql, $params, 'nama_santri, id', max(1, $page), $q, ['nama_santri', 'nis']);
    }

    // =======================================================================
    // Idempotensi
    // =======================================================================

    /**
     * Klaim kunci idempotensi. Baris pertama dibuat kosong; permintaan dengan
     * kunci yang sama berikutnya menunggu kunci baris ini sampai transaksi
     * pertama selesai, lalu membaca hasil yang tercatat.
     *
     * @return array{id:int, operasi:string, request_hash:string, hasil_id:?int, status_code:?int}
     */
    public function klaimIdempotensi(int $userId, string $kunci, string $operasi, string $hash): array
    {
        $this->execute(
            'INSERT INTO bsa_idempotensi (user_id, kunci, operasi, request_hash)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)',
            [$userId, $kunci, $operasi, $hash]
        );
        $row = $this->one(
            'SELECT id, operasi, request_hash, hasil_id, status_code FROM bsa_idempotensi WHERE user_id = ? AND kunci = ? FOR UPDATE',
            [$userId, $kunci]
        ) ?? throw new BsaException('Idempotensi tidak tersedia.', 503);

        return [
            'id' => (int) $row['id'],
            'operasi' => (string) $row['operasi'],
            'request_hash' => (string) $row['request_hash'],
            'hasil_id' => $row['hasil_id'] === null ? null : (int) $row['hasil_id'],
            // Fase 3: 202 = hasil berupa setoran transfer menunggu verifikasi.
            'status_code' => $row['status_code'] === null ? null : (int) $row['status_code'],
        ];
    }

    public function selesaikanIdempotensi(int $id, int $hasilId, int $status): void
    {
        $this->execute('UPDATE bsa_idempotensi SET hasil_id = ?, status_code = ? WHERE id = ?', [$hasilId, $status, $id]);
    }

    // =======================================================================
    // Tabungan
    // =======================================================================

    /**
     * Membuat akun tabungan bersaldo nol bila belum ada, di luar transaksi
     * mutasi (autocommit). Hanya dipanggil layanan setelah hak dan cakupan
     * diperiksa; baris kosong tidak mengubah saldo siapa pun.
     */
    public function pastikanAkun(int $santriId): void
    {
        $this->execute('INSERT IGNORE INTO bsa_tabungan_akun (santri_id, saldo) VALUES (?, 0)', [$santriId]);
    }

    /** @return array{saldo:int, jumlah_transaksi:int}|null */
    public function kunciAkun(int $santriId): ?array
    {
        $row = $this->one('SELECT saldo, jumlah_transaksi FROM bsa_tabungan_akun WHERE santri_id = ? FOR UPDATE', [$santriId]);

        return $row === null ? null : ['saldo' => (int) $row['saldo'], 'jumlah_transaksi' => (int) $row['jumlah_transaksi']];
    }

    /**
     * Satu baris ledger sah beserta cerminan saldo akun, di transaksi yang
     * sama. Pembaruan akun bersyarat pada saldo lama yang telah dikunci: bila
     * tidak tepat satu baris berubah, seluruh transaksi dibatalkan.
     *
     * Ledger hanya-tambah: baris yang tersimpan sudah sah dan tidak pernah
     * diubah. Fase 3 menambah `pembalik_dari_id` + `alasan` (koreksi pembalik)
     * dan penyetuju yang berbeda dari pencatat (verifikasi setoran transfer:
     * pencatat = petugas yang menerima, penyetuju = petugas yang memeriksa).
     *
     * @param array{jenis:string, arah:string, nominal:int, metode:string, tujuan:?string, referensi:?string,
     *     catatan:?string, idempotency_key:string, pembalik_dari_id?:?int, alasan?:?string} $data
     */
    public function catatTransaksi(int $santriId, array $data, int $saldoLama, int $saldoBaru, int $userId, int $pengurusId, int $penugasanId, ?int $penyetuju = null): int
    {
        $this->execute(
            "INSERT INTO bsa_tabungan_transaksi
                 (santri_id, jenis, arah, nominal, saldo_sesudah, status, metode, tujuan, referensi, catatan, pembalik_dari_id, alasan,
                  penugasan_id, pengurus_id, dicatat_oleh, disetujui_oleh, disetujui_pada, idempotency_key)
             VALUES (?, ?, ?, ?, ?, 'Sah', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)",
            [
                $santriId, $data['jenis'], $data['arah'], $data['nominal'], $saldoBaru, $data['metode'], $data['tujuan'], $data['referensi'], $data['catatan'],
                $data['pembalik_dari_id'] ?? null, $data['alasan'] ?? null,
                $penugasanId, $pengurusId, $userId, $penyetuju ?? $userId, $data['idempotency_key'],
            ]
        );
        $id = (int) $this->db->insert_id;
        $berubah = $this->affected(
            'UPDATE bsa_tabungan_akun
                SET saldo = ?, jumlah_transaksi = jumlah_transaksi + 1, transaksi_terakhir_id = ?
              WHERE santri_id = ? AND saldo = ?',
            [$saldoBaru, $id, $santriId, $saldoLama]
        );
        if ($berubah !== 1) {
            throw new BsaException('Saldo berubah oleh transaksi lain. Tidak ada data yang diubah; coba lagi.', 409);
        }

        return $id;
    }

    /**
     * Kolom ledger beserta referensi silang (semuanya tabel 027): pembayaran
     * tagihan yang didebit dari baris ini (atau yang dikembalikan oleh baris
     * pembalik ini), baris pembalik yang mengoreksinya, dan penyetuju.
     */
    private const LEDGER = 'SELECT x.id, x.santri_id, x.jenis, x.arah, x.nominal, x.saldo_sesudah, x.status, x.metode, x.tujuan, x.referensi, x.catatan,
                    x.pembalik_dari_id, x.alasan, x.disetujui_pada, x.created_at,
                    COALESCE(p.nama, u.name) AS petugas_nama, COALESCE(pv.nama, uv.name) AS penyetuju_nama,
                    rv.id AS dikoreksi_oleh_id, ax.jenis AS asal_jenis, ax.created_at AS asal_created_at,
                    pb.id AS bayar_id, pb.nomor_kuitansi AS bayar_kuitansi, pb.tagihan_id AS bayar_tagihan_id, pb.status AS bayar_status, tb.periode AS bayar_periode
               FROM bsa_tabungan_transaksi x
               JOIN users u ON u.id = x.dicatat_oleh
               LEFT JOIN pengurus p ON p.id = x.pengurus_id
               LEFT JOIN users uv ON uv.id = x.disetujui_oleh
               LEFT JOIN pengurus pv ON pv.id = uv.pengurus_id
               LEFT JOIN bsa_tabungan_transaksi rv ON rv.pembalik_dari_id = x.id
               LEFT JOIN bsa_tabungan_transaksi ax ON ax.id = x.pembalik_dari_id
               LEFT JOIN bsa_pembayaran pb ON pb.tabungan_transaksi_id = IF(x.jenis = \'Pembalik\', x.pembalik_dari_id, x.id)
               LEFT JOIN bsa_tagihan tb ON tb.id = pb.tagihan_id';

    /** @return array<string, mixed>|null */
    public function transaksi(int $id): ?array
    {
        return $this->one(self::LEDGER . ' WHERE x.id = ?', [$id]);
    }

    /** @return array{rows:array<int, array<string, mixed>>, total:int, page:int, perPage:int} */
    public function riwayatTabungan(int $santriId, int $page): array
    {
        return PageQuery::fetch($this->db, self::LEDGER . ' WHERE x.santri_id = ?', [$santriId], 'id DESC', max(1, $page));
    }

    // =======================================================================
    // Tagihan
    // =======================================================================

    /**
     * Menyisipkan tagihan beserta snapshot komponennya. Kunci unik
     * santri+periode menjamin paling banyak satu tagihan per periode,
     * termasuk pada dua penerbitan bersamaan.
     *
     * @param array{santri_id:int, periode:string, jatuh_tempo:string, catatan:?string, idempotency_key:string,
     *     komponen:array<int, array{nama:string, nominal:int}>, total:int} $data
     */
    public function simpanTagihan(array $data, ?string $jenjang, int $userId, int $pengurusId, int $penugasanId): int
    {
        try {
            $this->execute(
                "INSERT INTO bsa_tagihan
                     (santri_id, periode, jatuh_tempo, total, terbayar, jenjang, status, versi, catatan,
                      penugasan_id, pengurus_id, diterbitkan_oleh, idempotency_key)
                 VALUES (?, ?, ?, ?, 0, ?, 'Terbit', 1, ?, ?, ?, ?, ?)",
                [$data['santri_id'], $data['periode'], $data['jatuh_tempo'], $data['total'], $jenjang, $data['catatan'],
                    $penugasanId, $pengurusId, $userId, $data['idempotency_key']]
            );
        } catch (BsaException $exception) {
            if ($exception->errno === 1062) {
                throw new BsaException('Tagihan periode ini untuk santri tersebut sudah terbit. Tidak ada tagihan kedua yang dibuat.', 409);
            }
            throw $exception;
        }
        $id = (int) $this->db->insert_id;
        foreach (array_values($data['komponen']) as $i => $k) {
            $this->execute(
                'INSERT INTO bsa_tagihan_komponen (tagihan_id, urutan, nama, nominal) VALUES (?, ?, ?, ?)',
                [$id, $i + 1, $k['nama'], $k['nominal']]
            );
        }
        // Fase 2 (migrasi 028): setiap tagihan punya riwayat versi sejak terbit.
        if (Schema::hasTable($this->db, 'bsa_tagihan_riwayat')) {
            $this->execute(
                "INSERT INTO bsa_tagihan_riwayat (tagihan_id, versi, tindakan, total, jatuh_tempo, alasan, penugasan_id, aktor)
                 VALUES (?, 1, 'Terbit', ?, ?, NULL, ?, ?)",
                [$id, $data['total'], $data['jatuh_tempo'], $penugasanId, $userId]
            );
        }

        return $id;
    }

    /** @return array<string, mixed>|null */
    public function tagihan(int $id): ?array
    {
        return $this->one(
            'SELECT t.id, t.santri_id, t.periode, t.jatuh_tempo, t.total, t.terbayar, t.jenjang, t.status, t.versi, t.catatan,
                    t.created_at, COALESCE(p.nama, u.name) AS penerbit_nama
               FROM bsa_tagihan t
               JOIN users u ON u.id = t.diterbitkan_oleh
               LEFT JOIN pengurus p ON p.id = t.pengurus_id
              WHERE t.id = ?',
            [$id]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function komponen(int $tagihanId): array
    {
        return $this->all('SELECT urutan, nama, nominal FROM bsa_tagihan_komponen WHERE tagihan_id = ? ORDER BY urutan', [$tagihanId]);
    }

    /** @return array<int, array<string, mixed>> */
    public function tagihanSantri(int $santriId): array
    {
        return $this->all(
            'SELECT t.id, t.periode, t.jatuh_tempo, t.total, t.terbayar, t.status, t.versi, t.created_at
               FROM bsa_tagihan t WHERE t.santri_id = ? ORDER BY t.periode DESC, t.id DESC LIMIT 60',
            [$santriId]
        );
    }

    // =======================================================================

    /**
     * Penempatan kelas aktif tahun ajaran aktif (paling banyak satu per santri).
     * Publik agar `TagihanRepository` (Fase 2) memakai definisi unit yang sama.
     */
    public function joinUnit(): string
    {
        return " LEFT JOIN plotting_kelas pk ON pk.id_santri = s.id AND pk.status = 'Aktif' AND pk.id_tahun = " . self::TAHUN_AKTIF . '
                 LEFT JOIN kelas kl ON kl.id = pk.id_kelas';
    }

    /**
     * Relasi wali aktif akun atas santri alias `s` (satu parameter: user id):
     * akun aktif + peran orang tua + master wali aktif + `santri_wali` aktif +
     * santri aktif. Dihitung ulang setiap permintaan. Publik agar daftar Fase 2
     * (`TagihanRepository`) memakai syarat relasi wali yang sama persis.
     */
    public function waliSyarat(): string
    {
        return " JOIN users wu ON wu.id = ? AND wu.is_active = 1
                 JOIN wali ww ON ww.id = wu.wali_id AND ww.is_active = 1 AND ww.archived_at IS NULL
                WHERE s.is_active = 1 AND s.archived_at IS NULL
                  AND EXISTS (SELECT 1 FROM santri_wali wsw WHERE wsw.wali_id = ww.id AND wsw.santri_id = s.id AND wsw.archived_at IS NULL)
                  AND EXISTS (SELECT 1 FROM user_roles wur JOIN roles wr ON wr.id = wur.role_id AND wr.slug = 'orang_tua' WHERE wur.user_id = wu.id)";
    }

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
            throw new BsaException('Data keuangan tidak tersedia.', 503);
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
            if (in_array($statement->errno, [1020, 1205, 1213], true)) {
                throw new BsaException('Data sedang diproses permintaan lain. Tidak ada data yang diubah; coba lagi.', 409);
            }
            // CHECK (saldo/nominal) adalah pertahanan terakhir di basis data.
            if (in_array($statement->errno, [3819, 4025], true)) {
                throw new BsaException('Transaksi ditolak aturan saldo/nominal. Tidak ada data yang diubah.', 422);
            }
            // Kode basis data diteruskan agar pemanggil dapat memberi pesan yang tepat (mis. 1062 duplikat).
            throw new BsaException('Data keuangan tidak dapat disimpan.', 503, (int) $statement->errno);
        }
    }
}
