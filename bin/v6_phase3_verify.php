<?php

declare(strict_types=1);

/**
 * Verifier PRD V6 Fase 3 (migrasi 029, tabungan lengkap dan pembayaran
 * silang). Hanya membaca.
 *
 *   php bin/v6_phase3_verify.php [manifest.json]            # sesudah migrasi
 *   php bin/v6_phase3_verify.php --rollback [manifest.json] # sesudah rollback
 *
 * Sesudah migrasi: seluruh invarians Fase 1–2 (`bin/v6_phase2_verify.php`,
 * termasuk saldo akun = ledger = jumlah berjalan, terbayar = Σ pembayaran
 * sah) DITAMBAH tabel 029 (InnoDB, 6 kunci asing, 0 yatim) dan invarians
 * tabungan lengkap:
 *   - setoran transfer: Sah ⇔ tepat satu baris ledger Setoran/Transfer
 *     dengan santri, nominal, referensi sama dan penyetuju = pemutus;
 *     Menunggu/Ditolak tidak punya baris ledger (tidak dihitung saldo);
 *   - pembayaran dari tabungan: setiap debit ledger `PembayaranTagihan`
 *     dirujuk TEPAT satu pembayaran `Tabungan` (santri dan nominal sama) dan
 *     sebaliknya — nol debit yatim, nol pembayaran tanpa debit;
 *   - pembalik: transaksi asal ada, santri/nominal/metode sama, arah
 *     berlawanan, asal bukan pembalik, beralasan; pembayaran tabungan
 *     dibatalkan ⇔ debitnya dikembalikan;
 *   - metode Internal hanya untuk debit tagihan dan pengembaliannya;
 *     Σ debit tabungan = Σ pembayaran dari tabungan; Σ pengembalian =
 *     Σ pembayaran tabungan yang dibatalkan (perpindahan internal tidak
 *     menjadi kas);
 *   - audit `bsa.*` tanpa nominal, saldo, referensi, alasan, atau nama.
 * Dengan manifest: jumlah baris seluruh tabel lama dan sidik jari ID serta
 * isi uang 027/028 tetap.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/app/bootstrap.php';

$db = app_db();
$args = array_slice($argv, 1);
$rollback = in_array('--rollback', $args, true);
$args = array_values(array_filter($args, static fn (string $a): bool => $a !== '--rollback'));
$gagal = 0;
$periksa = static function (bool $ok, string $label) use (&$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $label . PHP_EOL;
    if (!$ok) {
        $gagal++;
    }
};
$angka = static fn (string $sql): int => (int) $db->query($sql)->fetch_row()[0];
$tabelAda = static function (string $t) use ($db): bool {
    $s = $db->prepare('SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $s->bind_param('s', $t);
    $s->execute();
    $ada = (int) $s->get_result()->fetch_assoc()['n'] === 1;
    $s->close();

    return $ada;
};
$tercatat = $angka("SELECT COUNT(*) FROM schema_migrations WHERE migration = '029_v6_fase3_tabungan.sql'");

if ($rollback) {
    $periksa(!$tabelAda('bsa_setoran_transfer'), 'Tabel bsa_setoran_transfer sudah dilepas');
    $periksa($tercatat === 0, 'Migrasi 029 tidak lagi tercatat');
    foreach ([...\App\Bsa\BsaRepository::TABEL, ...\App\Bsa\TagihanRepository::TABEL] as $t) {
        $periksa($tabelAda($t), 'Tabel 027/028 ' . $t . ' tetap ada');
    }
} else {
    echo '--- Invarians Fase 1–2 (027, 028) ---' . PHP_EOL;
    $keluaran = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/v6_phase2_verify.php') . ' 2>&1', $keluaran, $kodeFase2);
    foreach ($keluaran as $baris) {
        if (!str_starts_with($baris, '[belum diperiksa]')) {
            echo '  ' . $baris . PHP_EOL;
        }
    }
    $periksa($kodeFase2 === 0, 'Seluruh invarians Fase 1–2 lulus');
    echo '--- Fase 3 (029) ---' . PHP_EOL;
    $periksa($tabelAda('bsa_setoran_transfer'), 'Tabel bsa_setoran_transfer');
    $periksa($tercatat === 1, 'Migrasi 029 tercatat sekali');
    $periksa($angka("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bsa_setoran_transfer' AND ENGINE <> 'InnoDB'") === 0, 'Tabel Fase 3 memakai InnoDB');
    $fks = $db->query("SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bsa_setoran_transfer' AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY COLUMN_NAME")->fetch_all(MYSQLI_ASSOC);
    $periksa(count($fks) === 6, 'Kunci asing tabel Fase 3 terpasang (' . count($fks) . ' dari 6)');
    $yatim = 0;
    foreach ($fks as $fk) {
        foreach ($fk as $nama) {
            if (!preg_match('/^[a-zA-Z0-9_]+$/D', (string) $nama)) {
                exit(2);
            }
        }
        [$c, $col, $p, $key] = [$fk['TABLE_NAME'], $fk['COLUMN_NAME'], $fk['REFERENCED_TABLE_NAME'], $fk['REFERENCED_COLUMN_NAME']];
        $n = $angka("SELECT COUNT(*) FROM `$c` c LEFT JOIN `$p` p ON p.`$key` = c.`$col` WHERE c.`$col` IS NOT NULL AND p.`$key` IS NULL");
        $yatim += $n;
        $periksa($n === 0, "Referensi $c.$col → $p tidak yatim");
    }
    echo 'Jumlah referensi yatim tabel Fase 3: ' . $yatim . PHP_EOL;

    // Ledger tetap hanya berisi baris sah (antrean transfer berada di 029).
    $periksa($angka("SELECT COUNT(*) FROM bsa_tabungan_transaksi WHERE status <> 'Sah'") === 0, 'Ledger tabungan hanya memuat transaksi sah (menunggu/ditolak tidak masuk saldo)');

    // Setoran transfer.
    $periksa($angka("SELECT COUNT(*) FROM bsa_setoran_transfer st LEFT JOIN bsa_tabungan_transaksi x ON x.id = st.transaksi_id
        WHERE st.status = 'Sah' AND (x.id IS NULL OR x.jenis <> 'Setoran' OR x.metode <> 'Transfer' OR x.santri_id <> st.santri_id OR x.nominal <> st.nominal
            OR x.referensi <> st.referensi OR x.disetujui_oleh <> st.diputus_oleh OR x.idempotency_key <> CONCAT('transfer#', st.id))") === 0,
        'Setoran transfer sah ⇔ satu baris ledger Setoran/Transfer yang sama (santri, nominal, referensi, penyetuju)');
    $periksa($angka("SELECT COUNT(*) FROM bsa_tabungan_transaksi x WHERE x.idempotency_key LIKE 'transfer#%'
        AND NOT EXISTS (SELECT 1 FROM bsa_setoran_transfer st WHERE st.transaksi_id = x.id AND st.status = 'Sah')") === 0, 'Setiap baris ledger hasil verifikasi dirujuk setoran transfer sah');
    $periksa($angka("SELECT COUNT(*) FROM bsa_setoran_transfer WHERE status <> 'Sah' AND transaksi_id IS NOT NULL") === 0, 'Setoran transfer menunggu/ditolak tanpa baris ledger');
    $periksa($angka("SELECT COUNT(*) FROM bsa_idempotensi i WHERE i.status_code = 202 AND NOT EXISTS (SELECT 1 FROM bsa_setoran_transfer st WHERE st.id = i.hasil_id AND st.dicatat_oleh = i.user_id)") === 0,
        'Klaim idempotensi 202 menunjuk setoran transfer milik akun yang sama');

    // Pembayaran dari tabungan ⇔ debit ledger.
    $periksa($angka("SELECT COUNT(*) FROM bsa_tabungan_transaksi x LEFT JOIN bsa_pembayaran p ON p.tabungan_transaksi_id = x.id
        WHERE x.jenis = 'PembayaranTagihan' AND (p.id IS NULL OR p.sumber <> 'Tabungan' OR p.santri_id <> x.santri_id OR p.nominal <> x.nominal OR x.metode <> 'Internal' OR x.arah <> 'Keluar')") === 0,
        'Setiap debit tabungan untuk tagihan dirujuk tepat satu pembayaran Tabungan (santri, nominal sama) — nol debit yatim');
    $periksa($angka("SELECT COUNT(*) FROM bsa_pembayaran p LEFT JOIN bsa_tabungan_transaksi x ON x.id = p.tabungan_transaksi_id
        WHERE p.sumber = 'Tabungan' AND (x.id IS NULL OR x.jenis <> 'PembayaranTagihan')") === 0, 'Setiap pembayaran dari tabungan menunjuk debit PembayaranTagihan — nol pembayaran tanpa debit');
    $periksa($angka("SELECT COUNT(*) FROM bsa_pembayaran WHERE sumber = 'Tabungan' AND status = 'Menunggu'") === 0, 'Pembayaran dari tabungan tidak pernah menunggu verifikasi');

    // Pembalik.
    $periksa($angka("SELECT COUNT(*) FROM bsa_tabungan_transaksi r LEFT JOIN bsa_tabungan_transaksi a ON a.id = r.pembalik_dari_id
        WHERE r.jenis = 'Pembalik' AND (a.id IS NULL OR a.santri_id <> r.santri_id OR a.nominal <> r.nominal OR a.arah = r.arah OR a.metode <> r.metode
            OR a.jenis = 'Pembalik' OR a.id >= r.id OR r.alasan IS NULL OR CHAR_LENGTH(r.alasan) < 5)") === 0,
        'Pembalik: asal lebih awal pada santri sama, nominal dan metode sama, arah berlawanan, asal bukan pembalik, beralasan');
    $periksa($angka("SELECT COUNT(*) FROM bsa_pembayaran p LEFT JOIN bsa_tabungan_transaksi r ON r.pembalik_dari_id = p.tabungan_transaksi_id
        WHERE p.sumber = 'Tabungan' AND ((p.status = 'Dibatalkan') <> (r.id IS NOT NULL))") === 0, 'Pembayaran dari tabungan dibatalkan ⇔ debitnya dikembalikan ke tabungan');
    $periksa($angka("SELECT COUNT(*) FROM bsa_tabungan_transaksi x LEFT JOIN bsa_tabungan_transaksi a ON a.id = x.pembalik_dari_id
        WHERE x.metode = 'Internal' AND NOT (x.jenis = 'PembayaranTagihan' OR (x.jenis = 'Pembalik' AND a.jenis = 'PembayaranTagihan'))") === 0,
        'Metode Internal hanya untuk debit tagihan dan pengembaliannya');

    // Rekonsiliasi perpindahan internal (seluruh waktu).
    $debit = $angka("SELECT COALESCE(SUM(nominal), 0) FROM bsa_tabungan_transaksi WHERE jenis = 'PembayaranTagihan'");
    $bayar = $angka("SELECT COALESCE(SUM(nominal), 0) FROM bsa_pembayaran WHERE sumber = 'Tabungan'");
    $periksa($debit === $bayar, 'Σ debit tabungan untuk tagihan = Σ pembayaran dari tabungan (selisih Rp' . ($debit - $bayar) . ')');
    $kembali = $angka("SELECT COALESCE(SUM(r.nominal), 0) FROM bsa_tabungan_transaksi r JOIN bsa_tabungan_transaksi a ON a.id = r.pembalik_dari_id WHERE a.jenis = 'PembayaranTagihan'");
    $batal = $angka("SELECT COALESCE(SUM(nominal), 0) FROM bsa_pembayaran WHERE sumber = 'Tabungan' AND status = 'Dibatalkan'");
    $periksa($kembali === $batal, 'Σ pengembalian ke tabungan = Σ pembayaran dari tabungan yang dibatalkan (selisih Rp' . ($kembali - $batal) . ')');

    $bocor = $angka("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'bsa.%'
        AND (COALESCE(after_json, '') REGEXP '\"(nominal|saldo|saldo_sesudah|total|terbayar|sisa|referensi|tujuan|catatan|alasan|nama)\"' OR COALESCE(after_json, '') LIKE '%TRF%')");
    $periksa($bocor === 0, 'audit_logs bsa.* (termasuk Fase 3) tanpa nominal, saldo, referensi, alasan, atau nama');
}

if (isset($args[0])) {
    $manifest = json_decode((string) file_get_contents($args[0]), true);
    if (!is_array($manifest) || ($manifest['fase'] ?? null) !== 'v6-fase3' || !is_array($manifest['legacy_counts'] ?? null)) {
        fwrite(STDERR, "Manifest tidak valid (gunakan manifest bin/v6_phase3_preflight.php).\n");
        exit(2);
    }
    foreach ($manifest['legacy_counts'] as $t => $sebelum) {
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', (string) $t)) {
            exit(2);
        }
        if (!$tabelAda((string) $t)) {
            $periksa(false, "Tabel lama $t masih ada");
            continue;
        }
        $periksa($angka('SELECT COUNT(*) FROM `' . $t . '`') === (int) $sebelum, "Jumlah baris lama $t tetap $sebelum");
    }
    $sidik = $manifest['id_fingerprints'] ?? [];
    $db->query('SET SESSION group_concat_max_len = 4294967295');
    $kini = [];
    foreach (['santri', 'wali', 'santri_wali', 'users'] as $t) {
        $kini[$t] = (string) $db->query("SELECT SHA2(COALESCE(GROUP_CONCAT(id ORDER BY id SEPARATOR ','), ''), 256) AS h FROM `$t`")->fetch_assoc()['h'];
    }
    $kini['santri_wali_relasi'] = (string) $db->query("SELECT SHA2(COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, santri_id, wali_id, COALESCE(archived_at, '-')) ORDER BY id SEPARATOR ','), ''), 256) AS h FROM santri_wali")->fetch_assoc()['h'];
    $kini['bsa_tagihan'] = (string) $db->query("SELECT SHA2(COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, santri_id, periode, total, terbayar, status, versi) ORDER BY id SEPARATOR ','), ''), 256) AS h FROM bsa_tagihan")->fetch_assoc()['h'];
    $kini['bsa_tagihan_komponen'] = (string) $db->query("SELECT SHA2(COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, tagihan_id, urutan, nominal) ORDER BY id SEPARATOR ','), ''), 256) AS h FROM bsa_tagihan_komponen")->fetch_assoc()['h'];
    $kini['bsa_tabungan'] = (string) $db->query("SELECT SHA2(COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, santri_id, jenis, nominal, saldo_sesudah, status) ORDER BY id SEPARATOR ','), ''), 256) AS h FROM bsa_tabungan_transaksi")->fetch_assoc()['h'];
    $kini['bsa_saldo'] = (string) $db->query("SELECT SHA2(COALESCE(GROUP_CONCAT(CONCAT_WS(':', santri_id, saldo) ORDER BY santri_id SEPARATOR ','), ''), 256) AS h FROM bsa_tabungan_akun")->fetch_assoc()['h'];
    $kini['bsa_pembayaran'] = (string) $db->query("SELECT SHA2(COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, tagihan_id, nominal, sumber, status, nomor_kuitansi, COALESCE(tabungan_transaksi_id, '-')) ORDER BY id SEPARATOR ','), ''), 256) AS h FROM bsa_pembayaran")->fetch_assoc()['h'];
    foreach ($kini as $kunci => $h) {
        $periksa(isset($sidik[$kunci]) && hash_equals((string) $sidik[$kunci], $h), "Sidik jari $kunci tetap");
    }
} else {
    echo "[belum diperiksa] Perbandingan baris tabel lama: berikan path manifest preflight Fase 3.\n";
}
exit($gagal > 0 ? 1 : 0);
