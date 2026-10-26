<?php

declare(strict_types=1);

namespace App\Bsa;

use App\Database\PageQuery;
use mysqli;
use mysqli_stmt;

/**
 * Bacaan laporan keuangan santri/BSA (PRD V6 Fase 5). HANYA SELECT; tidak
 * ada tabel baru, tidak menulis log akses maupun audit (ekspor diaudit oleh
 * `LaporanService`).
 *
 * Seluruh angka satu laporan dibaca di dalam SATU snapshot konsisten
 * (`snapshot()`), sehingga mutasi yang berjalan bersamaan tidak membuat
 * bagian tagihan, tabungan, kas, dan daftar per santri saling tidak cocok.
 *
 * Hak tidak diputuskan di sini: `LaporanService` menghitung hak lewat
 * `BsaService` lalu menyerahkan `$hak` yang sama dengan daftar Fase 2–3
 * (`TagihanRepository::syaratHak`) — petugas: santri aktif dalam cakupan
 * unit; admin: seluruh santri; wali: anak dengan relasi aktif. Setiap kueri
 * memasang syarat itu pada alias santri `s`.
 *
 * Dua sumbu waktu, sama dengan rekonsiliasi harian Fase 3:
 *   - TAGIHAN menurut periode tagihan ('TTTT-BB' dari..sampai);
 *   - ARUS UANG menurut waktu kejadian di dalam jendela [mulai, akhir):
 *     baris ledger sah menurut `created_at`; pembayaran menurut waktu sahnya
 *     (debit tabungan pasangannya, atau `diverifikasi_pada`); pembatalan
 *     pembayaran sah menurut pembalik ledger pasangannya atau waktu
 *     pembatalan. Transfer yang masih menunggu tidak pernah dihitung.
 */
final class LaporanRepository
{
    /** Batas baris ekspor CSV; lebih dari ini diminta mempersempit periode, bukan dipotong diam-diam. */
    public const EKSPOR_MAKS = 10000;

    public function __construct(private mysqli $db, private BsaRepository $bsa, private TagihanRepository $tagihan)
    {
    }

    /**
     * Menjalankan seluruh bacaan satu laporan di dalam satu snapshot
     * REPEATABLE READ hanya-baca (tanpa kunci baris).
     *
     * @template T
     * @param callable(): T $kerja
     * @return T
     */
    public function snapshot(callable $kerja): mixed
    {
        $this->db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->db->query('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        try {
            $hasil = $kerja();
            $this->db->commit();

            return $hasil;
        } catch (\Throwable $exception) {
            $this->db->rollback();
            throw $exception;
        }
    }

    // =======================================================================
    // Tagihan menurut periode
    // =======================================================================

    /**
     * @param array{mode:string, user_id:int, jenjang:?array<int, string>} $hak
     * @param array{santri_id:?int, unit:?string} $f
     * @return array<string, int>
     */
    public function tagihan(array $hak, array $f, string $dari, string $sampai, string $hariIni): array
    {
        $p = [];
        $batas = $this->batas($hak, $f, $p);
        $r = $this->one("SELECT COUNT(CASE WHEN t.status = 'Terbit' THEN 1 END) AS terbit_jumlah,
                COALESCE(SUM(CASE WHEN t.status = 'Terbit' THEN t.total END), 0) AS terbit_nominal,
                COUNT(CASE WHEN t.status = 'Dibatalkan' THEN 1 END) AS batal_jumlah,
                COALESCE(SUM(CASE WHEN t.status = 'Dibatalkan' THEN t.total END), 0) AS batal_nominal,
                COALESCE(SUM(CASE WHEN t.status = 'Terbit' THEN t.terbayar END), 0) AS terbayar,
                COUNT(CASE WHEN t.status = 'Terbit' AND t.terbayar >= t.total THEN 1 END) AS lunas,
                COUNT(CASE WHEN t.status = 'Terbit' AND t.terbayar < t.total THEN 1 END) AS belum_lunas,
                COUNT(CASE WHEN t.status = 'Terbit' AND t.terbayar < t.total AND t.jatuh_tempo < ? THEN 1 END) AS lewat_jumlah,
                COALESCE(SUM(CASE WHEN t.status = 'Terbit' AND t.terbayar < t.total AND t.jatuh_tempo < ? THEN t.total - t.terbayar END), 0) AS lewat_sisa,
                COUNT(DISTINCT CASE WHEN t.status = 'Terbit' THEN t.santri_id END) AS santri,
                COUNT(CASE WHEN t.terbayar <> (SELECT COALESCE(SUM(p.nominal), 0) FROM bsa_pembayaran p WHERE p.tagihan_id = t.id AND p.status = 'Sah') THEN 1 END) AS selisih
              FROM bsa_tagihan t JOIN santri s ON s.id = t.santri_id" . $batas . ' AND t.periode BETWEEN ? AND ?',
            [$hariIni, $hariIni, ...$p, $dari, $sampai]);

        return array_map('intval', $r ?? []);
    }

    /**
     * Pembayaran atas tagihan periode dari..sampai menurut sumber dan status.
     *
     * @return array<int, array{sumber:string, status:string, jumlah:int, nominal:int}>
     */
    public function pembayaranTagihan(array $hak, array $f, string $dari, string $sampai): array
    {
        $p = [];
        $batas = $this->batas($hak, $f, $p);

        return $this->angka($this->all('SELECT b.sumber, b.status, COUNT(*) AS jumlah, COALESCE(SUM(b.nominal), 0) AS nominal
              FROM bsa_pembayaran b JOIN bsa_tagihan t ON t.id = b.tagihan_id JOIN santri s ON s.id = b.santri_id' . $batas . '
               AND t.periode BETWEEN ? AND ? GROUP BY b.sumber, b.status', [...$p, $dari, $sampai]), ['jumlah', 'nominal']);
    }

    /**
     * Tagihan per periode (bulan) di dalam rentang.
     *
     * @return array<int, array<string, mixed>>
     */
    public function tagihanBulanan(array $hak, array $f, string $dari, string $sampai): array
    {
        $p = [];
        $batas = $this->batas($hak, $f, $p);

        return $this->angka($this->all("SELECT t.periode AS bulan, COUNT(CASE WHEN t.status = 'Terbit' THEN 1 END) AS jumlah,
                COALESCE(SUM(CASE WHEN t.status = 'Terbit' THEN t.total END), 0) AS terbit,
                COALESCE(SUM(CASE WHEN t.status = 'Terbit' THEN t.terbayar END), 0) AS terbayar
              FROM bsa_tagihan t JOIN santri s ON s.id = t.santri_id" . $batas . ' AND t.periode BETWEEN ? AND ? GROUP BY t.periode', [...$p, $dari, $sampai]),
            ['jumlah', 'terbit', 'terbayar']);
    }

    // =======================================================================
    // Arus uang menurut waktu kejadian, per bulan
    // =======================================================================

    /**
     * Baris ledger sah di dalam jendela, per bulan × jenis × arah × metode.
     *
     * @return array<int, array{bulan:string, jenis:string, arah:string, metode:string, jumlah:int, nominal:int}>
     */
    public function ledgerBulanan(array $hak, array $f, string $mulai, string $akhir): array
    {
        $p = [];
        $batas = $this->batas($hak, $f, $p);

        return $this->angka($this->all("SELECT DATE_FORMAT(x.created_at, '%Y-%m') AS bulan, x.jenis, x.arah, x.metode, COUNT(*) AS jumlah, COALESCE(SUM(x.nominal), 0) AS nominal
              FROM bsa_tabungan_transaksi x JOIN santri s ON s.id = x.santri_id" . $batas . "
               AND x.status = 'Sah' AND x.created_at >= ? AND x.created_at < ? GROUP BY bulan, x.jenis, x.arah, x.metode", [...$p, $mulai, $akhir]), ['jumlah', 'nominal']);
    }

    /**
     * Pembayaran tagihan yang menjadi sah di dalam jendela, per bulan × sumber.
     * Termasuk pembayaran yang KEMUDIAN dibatalkan; pembatalannya dihitung
     * terpisah pada waktunya (`batalBulanan`), sama dengan rekonsiliasi.
     *
     * @return array<int, array{bulan:string, sumber:string, jumlah:int, nominal:int}>
     */
    public function bayarBulanan(array $hak, array $f, string $mulai, string $akhir): array
    {
        $p = [];
        $batas = $this->batas($hak, $f, $p);

        return $this->angka($this->all("SELECT DATE_FORMAT(COALESCE(tx.created_at, b.diverifikasi_pada), '%Y-%m') AS bulan, b.sumber, COUNT(*) AS jumlah, COALESCE(SUM(b.nominal), 0) AS nominal
              FROM bsa_pembayaran b LEFT JOIN bsa_tabungan_transaksi tx ON tx.id = b.tabungan_transaksi_id JOIN santri s ON s.id = b.santri_id" . $batas . '
               AND COALESCE(tx.created_at, b.diverifikasi_pada) >= ? AND COALESCE(tx.created_at, b.diverifikasi_pada) < ? GROUP BY bulan, b.sumber',
            [...$p, $mulai, $akhir]), ['jumlah', 'nominal']);
    }

    /**
     * Pembatalan pembayaran yang sebelumnya sah, per bulan × sumber.
     *
     * @return array<int, array{bulan:string, sumber:string, jumlah:int, nominal:int}>
     */
    public function batalBulanan(array $hak, array $f, string $mulai, string $akhir): array
    {
        $p = [];
        $batas = $this->batas($hak, $f, $p);

        return $this->angka($this->all("SELECT DATE_FORMAT(COALESCE(rv.created_at, xb.created_at), '%Y-%m') AS bulan, b.sumber, COUNT(*) AS jumlah, COALESCE(SUM(b.nominal), 0) AS nominal
              FROM bsa_pembayaran b JOIN bsa_pembayaran_pembatalan xb ON xb.pembayaran_id = b.id AND xb.status_sebelum = 'Sah'
              LEFT JOIN bsa_tabungan_transaksi rv ON rv.pembalik_dari_id = b.tabungan_transaksi_id JOIN santri s ON s.id = b.santri_id" . $batas . '
               AND COALESCE(rv.created_at, xb.created_at) >= ? AND COALESCE(rv.created_at, xb.created_at) < ? GROUP BY bulan, b.sumber',
            [...$p, $mulai, $akhir]), ['jumlah', 'nominal']);
    }

    /** Σ saldo ledger sah sebelum satu waktu (saldo awal/akhir jendela). */
    public function saldoSebelum(array $hak, array $f, string $waktu): int
    {
        $p = [];
        $batas = $this->batas($hak, $f, $p);

        return (int) ($this->one("SELECT COALESCE(SUM(CASE WHEN x.arah = 'Masuk' THEN x.nominal ELSE -x.nominal END), 0) AS n
              FROM bsa_tabungan_transaksi x JOIN santri s ON s.id = x.santri_id" . $batas . " AND x.status = 'Sah' AND x.created_at < ?", [...$p, $waktu])['n'] ?? 0);
    }

    /**
     * Keadaan menunggu verifikasi SAAT INI (bukan arus): setoran transfer dan
     * pembayaran transfer atas tagihan periode laporan.
     *
     * @return array{setoran:array{jumlah:int, nominal:int}, pembayaran:array{jumlah:int, nominal:int}}
     */
    public function menunggu(array $hak, array $f, string $dari, string $sampai): array
    {
        $p1 = [];
        $b1 = $this->batas($hak, $f, $p1);
        $setoran = $this->one("SELECT COUNT(*) AS jumlah, COALESCE(SUM(st.nominal), 0) AS nominal FROM bsa_setoran_transfer st JOIN santri s ON s.id = st.santri_id"
            . $b1 . " AND st.status = 'Menunggu'", $p1);
        $p2 = [];
        $b2 = $this->batas($hak, $f, $p2);
        $bayar = $this->one("SELECT COUNT(*) AS jumlah, COALESCE(SUM(b.nominal), 0) AS nominal FROM bsa_pembayaran b JOIN bsa_tagihan t ON t.id = b.tagihan_id
              JOIN santri s ON s.id = b.santri_id" . $b2 . " AND b.status = 'Menunggu' AND t.periode BETWEEN ? AND ?", [...$p2, $dari, $sampai]);

        return [
            'setoran' => ['jumlah' => (int) ($setoran['jumlah'] ?? 0), 'nominal' => (int) ($setoran['nominal'] ?? 0)],
            'pembayaran' => ['jumlah' => (int) ($bayar['jumlah'] ?? 0), 'nominal' => (int) ($bayar['nominal'] ?? 0)],
        ];
    }

    /**
     * Pembatalan/koreksi bukan-uang-efektif di dalam jendela: penolakan
     * transfer pembayaran, penolakan setoran transfer, revisi dan pembatalan
     * tagihan.
     *
     * @return array<string, int>
     */
    public function koreksiLain(array $hak, array $f, string $mulai, string $akhir): array
    {
        $hasil = [];
        foreach ([
            'penolakan_bayar' => ["SELECT COUNT(*) AS jumlah, COALESCE(SUM(b.nominal), 0) AS nominal FROM bsa_pembayaran_pembatalan xb JOIN bsa_pembayaran b ON b.id = xb.pembayaran_id
                    JOIN santri s ON s.id = b.santri_id", " AND xb.jenis = 'Penolakan' AND xb.created_at >= ? AND xb.created_at < ?"],
            'setoran_ditolak' => ['SELECT COUNT(*) AS jumlah, COALESCE(SUM(st.nominal), 0) AS nominal FROM bsa_setoran_transfer st JOIN santri s ON s.id = st.santri_id',
                " AND st.status = 'Ditolak' AND st.diputus_pada >= ? AND st.diputus_pada < ?"],
            'tagihan_revisi' => ['SELECT COUNT(*) AS jumlah, 0 AS nominal FROM bsa_tagihan_riwayat r JOIN bsa_tagihan t ON t.id = r.tagihan_id JOIN santri s ON s.id = t.santri_id',
                " AND r.tindakan = 'Revisi' AND r.created_at >= ? AND r.created_at < ?"],
            'tagihan_batal' => ['SELECT COUNT(*) AS jumlah, COALESCE(SUM(r.total), 0) AS nominal FROM bsa_tagihan_riwayat r JOIN bsa_tagihan t ON t.id = r.tagihan_id JOIN santri s ON s.id = t.santri_id',
                " AND r.tindakan = 'Dibatalkan' AND r.created_at >= ? AND r.created_at < ?"],
        ] as $kunci => [$sebelum, $sesudah]) {
            $p = [];
            $batas = $this->batas($hak, $f, $p);
            $r = $this->one($sebelum . $batas . $sesudah, [...$p, $mulai, $akhir]);
            $hasil[$kunci . '_jumlah'] = (int) ($r['jumlah'] ?? 0);
            $hasil[$kunci . '_nominal'] = (int) ($r['nominal'] ?? 0);
        }

        return $hasil;
    }

    /**
     * Daftar koreksi dan pembatalan di dalam jendela (terbaru dahulu), masing-
     * masing dengan aktor, waktu, alasan, dan rujukan asal. Hanya untuk
     * pembaca internal (layanan tidak memanggilnya untuk wali).
     *
     * @return array<int, array<string, mixed>>
     */
    public function koreksiDaftar(array $hak, array $f, string $mulai, string $akhir, int $batasBaris): array
    {
        $p = [];
        $batas = $this->batas($hak, $f, $p);

        return $this->all("SELECT k.*, s.nis, s.nama_santri FROM (
                SELECT 'tabungan' AS asal, x.id, x.created_at AS waktu, 'Koreksi tabungan' AS jenis, x.metode AS cara, x.arah, x.nominal, x.santri_id,
                       u.name AS aktor, x.alasan, a.id AS asal_id, a.jenis AS asal_jenis, a.created_at AS asal_dibuat, NULL AS asal_nomor, NULL AS periode
                  FROM bsa_tabungan_transaksi x JOIN bsa_tabungan_transaksi a ON a.id = x.pembalik_dari_id LEFT JOIN users u ON u.id = x.dicatat_oleh
                 WHERE x.jenis = 'Pembalik' AND x.metode <> 'Internal' AND x.created_at >= ? AND x.created_at < ?
                UNION ALL
                SELECT 'pembayaran', xb.id, xb.created_at, IF(xb.jenis = 'Pembatalan', 'Pembatalan pembayaran', 'Penolakan transfer pembayaran'), b.sumber, 'Keluar', b.nominal, b.santri_id,
                       u.name, xb.alasan, b.id, 'Pembayaran', b.created_at, b.nomor_kuitansi, t.periode
                  FROM bsa_pembayaran_pembatalan xb JOIN bsa_pembayaran b ON b.id = xb.pembayaran_id JOIN bsa_tagihan t ON t.id = b.tagihan_id LEFT JOIN users u ON u.id = xb.dibatalkan_oleh
                 WHERE xb.created_at >= ? AND xb.created_at < ?
                UNION ALL
                SELECT 'setoran', st.id, st.diputus_pada, 'Penolakan setoran transfer', 'Transfer', 'Masuk', st.nominal, st.santri_id,
                       u.name, st.alasan_tolak, st.id, 'SetoranTransfer', st.created_at, NULL, NULL
                  FROM bsa_setoran_transfer st LEFT JOIN users u ON u.id = st.diputus_oleh
                 WHERE st.status = 'Ditolak' AND st.diputus_pada >= ? AND st.diputus_pada < ?
                UNION ALL
                SELECT 'tagihan', r.id, r.created_at, IF(r.tindakan = 'Revisi', 'Revisi tagihan', 'Pembatalan tagihan'), NULL, NULL, r.total, t.santri_id,
                       u.name, r.alasan, t.id, 'Tagihan', t.created_at, NULL, t.periode
                  FROM bsa_tagihan_riwayat r JOIN bsa_tagihan t ON t.id = r.tagihan_id LEFT JOIN users u ON u.id = r.aktor
                 WHERE r.tindakan <> 'Terbit' AND r.created_at >= ? AND r.created_at < ?
            ) k JOIN santri s ON s.id = k.santri_id" . $batas . ' ORDER BY k.waktu DESC, k.asal, k.id DESC LIMIT ?',
            [$mulai, $akhir, $mulai, $akhir, $mulai, $akhir, $mulai, $akhir, ...$p, $batasBaris]);
    }

    // =======================================================================
    // Per santri
    // =======================================================================

    /**
     * Ringkasan per santri: tagihan periode, tunggakan seluruh waktu, arus
     * tabungan dalam jendela menurut JENIS (bukan arah), saldo awal/akhir,
     * saldo akun cerminan, dan dua selisih yang wajib Rp0:
     *   selisih_saldo = awal + setoran − penarikan − belanja − bayar dari
     *                   tabungan + koreksi masuk − koreksi keluar +
     *                   pengembalian − akhir;
     *   selisih_akun  = saldo akun − Σ ledger sah seluruh waktu.
     * Internal: hanya santri yang punya data BSA; wali: seluruh anaknya.
     *
     * @return array{0:string, 1:array<int, mixed>}
     */
    private function sqlPerSantri(array $hak, array $f, string $dari, string $sampai, string $mulai, string $akhir): array
    {
        $p = [];
        $syarat = $this->tagihan->syaratHak($hak, $p) . $this->saring($f, $p);
        $sql = "SELECT s.id, s.nis, s.nama_santri, s.is_active, s.archived_at, kl.nama_kelas, kl.jenjang,
                       COALESCE(tg.jumlah, 0) AS tagihan_jumlah, COALESCE(tg.tagihan, 0) AS tagihan, COALESCE(tg.terbayar, 0) AS terbayar,
                       COALESCE(tg.tagihan, 0) - COALESCE(tg.terbayar, 0) AS sisa, COALESCE(tu.sisa, 0) AS sisa_semua,
                       COALESCE(bk.kas, 0) AS bayar_kas, COALESCE(bt.batal, 0) AS batal_bayar,
                       COALESCE(lx.awal, 0) AS saldo_awal, COALESCE(lx.setoran, 0) AS setoran, COALESCE(lx.penarikan, 0) AS penarikan,
                       COALESCE(lx.belanja, 0) AS belanja, COALESCE(lx.bayar_tabungan, 0) AS bayar_tabungan,
                       COALESCE(lx.koreksi_masuk, 0) AS koreksi_masuk, COALESCE(lx.koreksi_keluar, 0) AS koreksi_keluar, COALESCE(lx.pengembalian, 0) AS pengembalian,
                       COALESCE(lx.akhir, 0) AS saldo_akhir, COALESCE(ak.saldo, 0) AS saldo_akun,
                       COALESCE(lx.awal, 0) + COALESCE(lx.setoran, 0) - COALESCE(lx.penarikan, 0) - COALESCE(lx.belanja, 0) - COALESCE(lx.bayar_tabungan, 0)
                           + COALESCE(lx.koreksi_masuk, 0) - COALESCE(lx.koreksi_keluar, 0) + COALESCE(lx.pengembalian, 0) - COALESCE(lx.akhir, 0) AS selisih_saldo,
                       COALESCE(ak.saldo, 0) - COALESCE(lx.seluruh, 0) AS selisih_akun
                  FROM santri s" . $this->bsa->joinUnit() . "
                  LEFT JOIN (SELECT santri_id, SUM(status = 'Terbit') AS jumlah, SUM(CASE WHEN status = 'Terbit' THEN total ELSE 0 END) AS tagihan,
                                    SUM(CASE WHEN status = 'Terbit' THEN terbayar ELSE 0 END) AS terbayar
                               FROM bsa_tagihan WHERE periode BETWEEN ? AND ? GROUP BY santri_id) tg ON tg.santri_id = s.id
                  LEFT JOIN (SELECT santri_id, SUM(total - terbayar) AS sisa FROM bsa_tagihan WHERE status = 'Terbit' GROUP BY santri_id) tu ON tu.santri_id = s.id
                  LEFT JOIN (SELECT b.santri_id, SUM(b.nominal) AS kas FROM bsa_pembayaran b
                              WHERE b.sumber IN ('Tunai', 'Transfer') AND b.diverifikasi_pada >= ? AND b.diverifikasi_pada < ? GROUP BY b.santri_id) bk ON bk.santri_id = s.id
                  LEFT JOIN (SELECT b.santri_id, SUM(b.nominal) AS batal FROM bsa_pembayaran b
                               JOIN bsa_pembayaran_pembatalan xb ON xb.pembayaran_id = b.id AND xb.status_sebelum = 'Sah'
                               LEFT JOIN bsa_tabungan_transaksi rv ON rv.pembalik_dari_id = b.tabungan_transaksi_id
                              WHERE COALESCE(rv.created_at, xb.created_at) >= ? AND COALESCE(rv.created_at, xb.created_at) < ? GROUP BY b.santri_id) bt ON bt.santri_id = s.id
                  LEFT JOIN (SELECT santri_id,
                                    SUM(CASE WHEN w < 0 THEN sg ELSE 0 END) AS awal,
                                    SUM(CASE WHEN w <= 0 THEN sg ELSE 0 END) AS akhir,
                                    SUM(CASE WHEN w = 0 AND jenis = 'Setoran' THEN nominal ELSE 0 END) AS setoran,
                                    SUM(CASE WHEN w = 0 AND jenis = 'Penarikan' THEN nominal ELSE 0 END) AS penarikan,
                                    SUM(CASE WHEN w = 0 AND jenis = 'Belanja' THEN nominal ELSE 0 END) AS belanja,
                                    SUM(CASE WHEN w = 0 AND jenis = 'PembayaranTagihan' THEN nominal ELSE 0 END) AS bayar_tabungan,
                                    SUM(CASE WHEN w = 0 AND jenis = 'Pembalik' AND metode <> 'Internal' AND arah = 'Masuk' THEN nominal ELSE 0 END) AS koreksi_masuk,
                                    SUM(CASE WHEN w = 0 AND jenis = 'Pembalik' AND metode <> 'Internal' AND arah = 'Keluar' THEN nominal ELSE 0 END) AS koreksi_keluar,
                                    SUM(CASE WHEN w = 0 AND jenis = 'Pembalik' AND metode = 'Internal' THEN nominal ELSE 0 END) AS pengembalian,
                                    SUM(sg) AS seluruh
                               FROM (SELECT santri_id, jenis, arah, metode, nominal, IF(arah = 'Masuk', nominal, -nominal) AS sg,
                                            CASE WHEN created_at < ? THEN -1 WHEN created_at < ? THEN 0 ELSE 1 END AS w
                                       FROM bsa_tabungan_transaksi WHERE status = 'Sah') z
                              GROUP BY santri_id) lx ON lx.santri_id = s.id
                  LEFT JOIN bsa_tabungan_akun ak ON ak.santri_id = s.id" . $syarat;
        if ($hak['mode'] !== 'wali') {
            $sql .= ' AND (tg.santri_id IS NOT NULL OR tu.santri_id IS NOT NULL OR bk.santri_id IS NOT NULL OR bt.santri_id IS NOT NULL OR lx.santri_id IS NOT NULL OR ak.santri_id IS NOT NULL)';
        }

        return [$sql, [$dari, $sampai, $mulai, $akhir, $mulai, $akhir, $mulai, $akhir, ...$p]];
    }

    /**
     * Satu halaman per santri beserta jumlah seluruh baris yang cocok.
     *
     * @return array{rows:array<int, array<string, mixed>>, total:int, page:int, perPage:int, jumlah:array<string, int>}
     */
    public function perSantri(array $hak, array $f, string $dari, string $sampai, string $mulai, string $akhir, string $q, int $page): array
    {
        [$sql, $params] = $this->sqlPerSantri($hak, $f, $dari, $sampai, $mulai, $akhir);
        $hasil = PageQuery::fetch($this->db, $sql, $params, 'nama_santri, id', max(1, $page), $q, ['nama_santri', 'nis']);
        $hasil['rows'] = $this->angka($hasil['rows'], self::KOLOM_SANTRI);
        $jumlah = $this->one('SELECT COUNT(*) AS santri, ' . implode(', ', array_map(static fn (string $k): string => "COALESCE(SUM($k), 0) AS $k", self::KOLOM_SANTRI))
            . ', COALESCE(SUM(selisih_saldo <> 0), 0) AS santri_selisih_saldo, COALESCE(SUM(selisih_akun <> 0), 0) AS santri_selisih_akun FROM (' . $sql . ') r'
            . ($q === '' ? '' : ' WHERE (nama_santri LIKE ? OR nis LIKE ?)'), $q === '' ? $params : [...$params, '%' . $q . '%', '%' . $q . '%']);
        $hasil['jumlah'] = array_map('intval', $jumlah ?? []);

        return $hasil;
    }

    /** Kolom angka per santri (juga dijumlahkan untuk baris total). */
    public const KOLOM_SANTRI = ['tagihan_jumlah', 'tagihan', 'terbayar', 'sisa', 'sisa_semua', 'bayar_kas', 'batal_bayar', 'saldo_awal', 'setoran', 'penarikan', 'belanja',
        'bayar_tabungan', 'koreksi_masuk', 'koreksi_keluar', 'pengembalian', 'saldo_akhir', 'saldo_akun', 'selisih_saldo', 'selisih_akun'];

    /**
     * Seluruh baris per santri untuk ekspor (urut nama), paling banyak
     * EKSPOR_MAKS + 1 baris agar pemanggil dapat menolak ekspor yang terlalu besar.
     *
     * @return array<int, array<string, mixed>>
     */
    public function perSantriSemua(array $hak, array $f, string $dari, string $sampai, string $mulai, string $akhir): array
    {
        [$sql, $params] = $this->sqlPerSantri($hak, $f, $dari, $sampai, $mulai, $akhir);

        return $this->angka($this->all('SELECT * FROM (' . $sql . ') r ORDER BY nama_santri, id LIMIT ?', [...$params, self::EKSPOR_MAKS + 1]), self::KOLOM_SANTRI);
    }

    /**
     * Santri nonaktif/diarsipkan yang masih bersaldo atau bertunggakan (R-10;
     * keputusan Human Developer 26 Oktober 2026: dilaporkan saja, tanpa jalur
     * mutasi). Hanya admin — petugas tidak membaca santri nonaktif.
     *
     * @return array<int, array<string, mixed>>
     */
    public function nonaktifBersaldo(?int $santriId): array
    {
        return $this->angka($this->all("SELECT s.id, s.nis, s.nama_santri, s.is_active, s.archived_at, COALESCE(ak.saldo, 0) AS saldo, COALESCE(tu.sisa, 0) AS sisa
              FROM santri s LEFT JOIN bsa_tabungan_akun ak ON ak.santri_id = s.id
              LEFT JOIN (SELECT santri_id, SUM(total - terbayar) AS sisa FROM bsa_tagihan WHERE status = 'Terbit' GROUP BY santri_id) tu ON tu.santri_id = s.id
             WHERE (s.is_active = 0 OR s.archived_at IS NOT NULL) AND (COALESCE(ak.saldo, 0) > 0 OR COALESCE(tu.sisa, 0) > 0)"
            . ($santriId === null ? '' : ' AND s.id = ?') . ' ORDER BY s.nama_santri, s.id', $santriId === null ? [] : [$santriId]), ['saldo', 'sisa']);
    }

    // =======================================================================
    // Rincian transaksi untuk ekspor
    // =======================================================================

    /**
     * Seluruh kejadian uang sah di dalam jendela (ledger, pembayaran sah,
     * pembatalan pembayaran sah), urut waktu; paling banyak EKSPOR_MAKS + 1.
     *
     * @return array<int, array<string, mixed>>
     */
    public function transaksi(array $hak, array $f, string $mulai, string $akhir): array
    {
        $p = [];
        $batas = $this->batas($hak, $f, $p);

        return $this->all("SELECT r.*, s.nis, s.nama_santri FROM (
                SELECT 'tabungan' AS asal, x.id, x.created_at AS waktu, x.jenis, x.arah, x.metode AS cara, x.nominal, x.santri_id,
                       x.pembalik_dari_id AS rujukan_id, ra.created_at AS rujukan_dibuat, NULL AS nomor_kuitansi, x.created_at AS dibuat, NULL AS periode
                  FROM bsa_tabungan_transaksi x LEFT JOIN bsa_tabungan_transaksi ra ON ra.id = x.pembalik_dari_id
                 WHERE x.status = 'Sah' AND x.created_at >= ? AND x.created_at < ?
                UNION ALL
                SELECT 'pembayaran', b.id, COALESCE(tx.created_at, b.diverifikasi_pada), 'Pembayaran', 'Masuk', b.sumber, b.nominal, b.santri_id,
                       b.tabungan_transaksi_id, tx.created_at, b.nomor_kuitansi, b.created_at, t.periode
                  FROM bsa_pembayaran b JOIN bsa_tagihan t ON t.id = b.tagihan_id LEFT JOIN bsa_tabungan_transaksi tx ON tx.id = b.tabungan_transaksi_id
                 WHERE COALESCE(tx.created_at, b.diverifikasi_pada) >= ? AND COALESCE(tx.created_at, b.diverifikasi_pada) < ?
                UNION ALL
                SELECT 'pembatalan', b.id, COALESCE(rv.created_at, xb.created_at), 'Pembatalan', 'Keluar', b.sumber, b.nominal, b.santri_id,
                       b.tabungan_transaksi_id, NULL, b.nomor_kuitansi, b.created_at, t.periode
                  FROM bsa_pembayaran b JOIN bsa_tagihan t ON t.id = b.tagihan_id
                  JOIN bsa_pembayaran_pembatalan xb ON xb.pembayaran_id = b.id AND xb.status_sebelum = 'Sah'
                  LEFT JOIN bsa_tabungan_transaksi rv ON rv.pembalik_dari_id = b.tabungan_transaksi_id
                 WHERE COALESCE(rv.created_at, xb.created_at) >= ? AND COALESCE(rv.created_at, xb.created_at) < ?
            ) r JOIN santri s ON s.id = r.santri_id" . $batas . " ORDER BY r.waktu, FIELD(r.asal, 'tabungan', 'pembayaran', 'pembatalan'), r.id LIMIT ?",
            [$mulai, $akhir, $mulai, $akhir, $mulai, $akhir, ...$p, self::EKSPOR_MAKS + 1]);
    }

    // =======================================================================

    /**
     * Penempatan unit + syarat hak + saringan atas alias santri `s`, diawali
     * JOIN dan selalu memuat klausa WHERE (pemanggil menambah AND).
     *
     * @param array{santri_id:?int, unit:?string} $f
     * @param array<int, mixed> $params
     */
    private function batas(array $hak, array $f, array &$params): string
    {
        return $this->bsa->joinUnit() . $this->tagihan->syaratHak($hak, $params) . $this->saring($f, $params);
    }

    /** @param array{santri_id:?int, unit:?string} $f */
    private function saring(array $f, array &$params): string
    {
        $sql = '';
        if (($f['santri_id'] ?? null) !== null) {
            $sql .= ' AND s.id = ?';
            $params[] = (int) $f['santri_id'];
        }
        if (($f['unit'] ?? null) !== null) {
            $sql .= ' AND LOWER(TRIM(kl.jenjang)) = ?';
            $params[] = mb_strtolower(trim((string) $f['unit']));
        }

        return $sql;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, string> $kolom
     * @return array<int, array<string, mixed>>
     */
    private function angka(array $rows, array $kolom): array
    {
        foreach ($rows as &$r) {
            foreach ($kolom as $k) {
                $r[$k] = (int) ($r[$k] ?? 0);
            }
        }

        return $rows;
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
            throw new BsaException('Laporan keuangan tidak tersedia.', 503);
        }
        try {
            if ($params !== []) {
                $types = implode('', array_map(static fn (mixed $v): string => is_int($v) ? 'i' : 's', $params));
                $statement->bind_param($types, ...$params);
            }
            if (!$statement->execute()) {
                throw new BsaException('Laporan keuangan tidak dapat dibaca.', 503, (int) $statement->errno);
            }
            $result = $statement->get_result();

            return $result === false ? [] : $result->fetch_all(MYSQLI_ASSOC);
        } finally {
            $statement->close();
        }
    }
}
