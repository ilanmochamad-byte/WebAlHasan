<?php

declare(strict_types=1);

/**
 * Verifier PRD V6 Fase 1 (migrasi 027, fondasi BSA). Hanya membaca.
 *
 *   php bin/v6_phase1_verify.php [manifest.json]            # sesudah migrasi
 *   php bin/v6_phase1_verify.php --rollback [manifest.json] # sesudah rollback
 *
 * Sesudah migrasi: enam tabel BSA ada (InnoDB), migrasi 027 tercatat sekali,
 * nol referensi yatim pada kunci asing tabel BSA, dan invarians uang:
 *   - saldo akun = saldo_sesudah transaksi sah terakhir = Σ masuk − Σ keluar
 *     transaksi sah, tidak ada saldo negatif;
 *   - total tagihan = Σ komponen; terbayar = Σ pembayaran sah; tidak ada
 *     tagihan ganda per santri+periode;
 *   - tidak ada klaim idempotensi yang menggantung, tidak ada nominal/
 *     referensi/tujuan di `audit_logs` jejak `bsa.*`.
 * Dengan manifest: jumlah baris seluruh tabel lama dan sidik jari ID
 * santri/wali/santri_wali/users tetap.
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
$bsa = ['bsa_tagihan', 'bsa_tagihan_komponen', 'bsa_tabungan_akun', 'bsa_tabungan_transaksi', 'bsa_pembayaran', 'bsa_idempotensi'];
$tercatat = $angka("SELECT COUNT(*) FROM schema_migrations WHERE migration = '027_v6_fase1_bsa.sql'");

if ($rollback) {
    foreach ($bsa as $t) {
        $periksa(!$tabelAda($t), 'Tabel ' . $t . ' sudah dilepas');
    }
    $periksa($tercatat === 0, 'Migrasi 027 tidak lagi tercatat');
    $periksa($tabelAda('bendahara_bulanan_assignments'), 'Tabel penugasan bendahara_bulanan_assignments tetap ada');
} else {
    foreach ($bsa as $t) {
        $periksa($tabelAda($t), 'Tabel ' . $t);
    }
    $periksa($tercatat === 1, 'Migrasi 027 tercatat sekali');
    $bukanInno = $angka("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'bsa\\_%' AND ENGINE <> 'InnoDB'");
    $periksa($bukanInno === 0, 'Seluruh tabel BSA memakai InnoDB');

    $yatim = 0;
    // Hanya enam tabel 027; tabel Fase 2 (028) diperiksa `bin/v6_phase2_verify.php`.
    $fks = $db->query("SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('" . implode("', '", $bsa) . "') AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME, COLUMN_NAME")->fetch_all(MYSQLI_ASSOC);
    $periksa(count($fks) === 22, 'Kunci asing tabel BSA terpasang (' . count($fks) . ' dari 22)');
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
    echo 'Jumlah referensi yatim tabel BSA: ' . $yatim . PHP_EOL;

    // Invarians tabungan.
    $periksa($angka('SELECT COUNT(*) FROM bsa_tabungan_akun WHERE saldo < 0') === 0, 'Tidak ada saldo akun negatif');
    $periksa($angka("SELECT COUNT(*) FROM bsa_tabungan_transaksi WHERE status = 'Sah' AND saldo_sesudah < 0") === 0, 'Tidak ada saldo sesudah transaksi yang negatif');
    $selisihLedger = $angka("SELECT COUNT(*) FROM bsa_tabungan_akun a
        LEFT JOIN (SELECT santri_id, SUM(CASE WHEN arah = 'Masuk' THEN nominal ELSE -nominal END) AS bersih, COUNT(*) AS n
                     FROM bsa_tabungan_transaksi WHERE status = 'Sah' GROUP BY santri_id) l ON l.santri_id = a.santri_id
        WHERE a.saldo <> COALESCE(l.bersih, 0) OR a.jumlah_transaksi <> COALESCE(l.n, 0)");
    $periksa($selisihLedger === 0, 'Saldo akun = Σ masuk − Σ keluar transaksi sah, jumlah transaksi cocok (' . $selisihLedger . ' akun selisih)');
    $tanpaAkun = $angka("SELECT COUNT(DISTINCT x.santri_id) FROM bsa_tabungan_transaksi x LEFT JOIN bsa_tabungan_akun a ON a.santri_id = x.santri_id WHERE x.status = 'Sah' AND a.santri_id IS NULL");
    $periksa($tanpaAkun === 0, 'Setiap transaksi sah punya akun tabungan');
    $terakhir = $angka("SELECT COUNT(*) FROM bsa_tabungan_akun a
        LEFT JOIN bsa_tabungan_transaksi x ON x.id = a.transaksi_terakhir_id
        WHERE (a.jumlah_transaksi > 0 AND (x.id IS NULL OR x.santri_id <> a.santri_id OR x.saldo_sesudah <> a.saldo
               OR x.id <> (SELECT MAX(y.id) FROM bsa_tabungan_transaksi y WHERE y.santri_id = a.santri_id AND y.status = 'Sah')))
           OR (a.jumlah_transaksi = 0 AND a.transaksi_terakhir_id IS NOT NULL)");
    $periksa($terakhir === 0, 'Saldo akun = saldo sesudah transaksi sah terakhir santri itu');
    $rantai = $angka("SELECT COUNT(*) FROM bsa_tabungan_transaksi x
        WHERE x.status = 'Sah' AND x.saldo_sesudah <> (SELECT SUM(CASE WHEN y.arah = 'Masuk' THEN y.nominal ELSE -y.nominal END)
              FROM bsa_tabungan_transaksi y WHERE y.santri_id = x.santri_id AND y.status = 'Sah' AND y.id <= x.id)");
    $periksa($rantai === 0, 'Saldo sesudah setiap transaksi = jumlah berjalan ledger');
    $ganda = $angka('SELECT COUNT(*) FROM (SELECT dicatat_oleh, idempotency_key FROM bsa_tabungan_transaksi GROUP BY dicatat_oleh, idempotency_key HAVING COUNT(*) > 1) g');
    $periksa($ganda === 0, 'Tidak ada transaksi ganda per kunci idempotensi');

    // Invarians tagihan.
    $periksa($angka('SELECT COUNT(*) FROM (SELECT santri_id, periode FROM bsa_tagihan GROUP BY santri_id, periode HAVING COUNT(*) > 1) g') === 0, 'Paling banyak satu tagihan per santri per periode');
    $tanpaKomponen = $angka('SELECT COUNT(*) FROM bsa_tagihan t WHERE NOT EXISTS (SELECT 1 FROM bsa_tagihan_komponen k WHERE k.tagihan_id = t.id)');
    $periksa($tanpaKomponen === 0, 'Setiap tagihan punya snapshot komponen');
    // Sesudah migrasi 028 (Fase 2) total juga memuat baris penyesuaian bertanda.
    $baris = $tabelAda('bsa_tagihan_penyesuaian')
        ? " + (SELECT COALESCE(SUM(CASE WHEN b.jenis = 'Tambahan' THEN b.nominal ELSE -b.nominal END), 0) FROM bsa_tagihan_penyesuaian b WHERE b.tagihan_id = t.id)"
        : '';
    $selisihTagihan = $angka('SELECT COUNT(*) FROM bsa_tagihan t WHERE t.total <> (SELECT COALESCE(SUM(k.nominal), 0) FROM bsa_tagihan_komponen k WHERE k.tagihan_id = t.id)' . $baris);
    $periksa($selisihTagihan === 0, 'Total tagihan = Σ komponen snapshot' . ($baris === '' ? '' : ' + Σ penyesuaian bertanda (028)'));
    $selisihBayar = $angka("SELECT COUNT(*) FROM bsa_tagihan t WHERE t.terbayar <> (SELECT COALESCE(SUM(p.nominal), 0) FROM bsa_pembayaran p WHERE p.tagihan_id = t.id AND p.status = 'Sah')");
    $periksa($selisihBayar === 0, 'Terbayar tagihan = Σ pembayaran sah; tidak ada pembayaran melebihi total (CHECK)');

    $menggantung = $angka('SELECT COUNT(*) FROM bsa_idempotensi WHERE hasil_id IS NULL');
    $periksa($menggantung === 0, 'Tidak ada klaim idempotensi yang menggantung');
    $bocor = $angka("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'bsa.%'
        AND (COALESCE(before_json, '') REGEXP '\"(nominal|saldo|saldo_sesudah|total|referensi|tujuan|catatan|alasan)\"'
          OR COALESCE(after_json, '') REGEXP '\"(nominal|saldo|saldo_sesudah|total|referensi|tujuan|catatan|alasan)\"')");
    $periksa($bocor === 0, 'audit_logs bsa.* tanpa nominal, saldo, referensi, tujuan, atau catatan');
}

if (isset($args[0])) {
    $manifest = json_decode((string) file_get_contents($args[0]), true);
    if (!is_array($manifest) || !isset($manifest['legacy_counts']) || !is_array($manifest['legacy_counts'])) {
        fwrite(STDERR, "Manifest tidak valid.\n");
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
        $kini = $angka('SELECT COUNT(*) FROM `' . $t . '`');
        $periksa($kini === (int) $sebelum, "Jumlah baris lama $t tetap $sebelum");
    }
    $sidik = $manifest['id_fingerprints'] ?? null;
    if (!is_array($sidik) || count($sidik) !== 5) {
        $periksa(false, 'Manifest memuat sidik jari ID santri/wali/santri_wali/users');
    } else {
        $db->query('SET SESSION group_concat_max_len = 4294967295');
        foreach (['santri', 'wali', 'santri_wali', 'users'] as $t) {
            $h = (string) $db->query("SELECT SHA2(COALESCE(GROUP_CONCAT(id ORDER BY id SEPARATOR ','), ''), 256) AS h FROM `$t`")->fetch_assoc()['h'];
            $periksa(hash_equals((string) $sidik[$t], $h), "Sidik jari ID $t tetap");
        }
        $h = (string) $db->query("SELECT SHA2(COALESCE(GROUP_CONCAT(CONCAT_WS(':', id, santri_id, wali_id, COALESCE(archived_at, '-')) ORDER BY id SEPARATOR ','), ''), 256) AS h FROM santri_wali")->fetch_assoc()['h'];
        $periksa(hash_equals((string) $sidik['santri_wali_relasi'], $h), 'Relasi santri–wali (santri, wali, status arsip) tetap');
    }
} else {
    echo "[belum diperiksa] Perbandingan baris tabel lama: berikan path manifest preflight.\n";
}
exit($gagal > 0 ? 1 : 0);
