<?php

declare(strict_types=1);

/**
 * Verifier PRD V5 Fase 2 (migrasi 025). Hanya membaca.
 *
 *   php bin/v5_phase2_verify.php [manifest.json]            # sesudah migrasi
 *   php bin/v5_phase2_verify.php --rollback [manifest.json] # sesudah rollback
 *
 * Sesudah migrasi: empat tabel 025 ada, migrasi 025 tercatat sekali, tidak
 * ada referensi yatim (termasuk kunci asing gabungan snapshot → versi),
 * versi kunjungan monoton tanpa celah dan sama dengan versi terbaru, setiap
 * versi > 1 punya snapshot rincian, koreksi atas versi Tercatat beralasan,
 * rincian aktif sama dengan snapshot versi terbaru, dan audit_logs tanpa isi
 * klinis. Dengan manifest: jumlah baris seluruh tabel lama (termasuk lima
 * tabel 024) tetap. Sesudah rollback: empat tabel dilepas, 024 tetap.
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
$tabelAda = static function (string $t) use ($db): bool {
    $s = $db->prepare('SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $s->bind_param('s', $t);
    $s->execute();
    $ada = (int) $s->get_result()->fetch_assoc()['n'] === 1;
    $s->close();

    return $ada;
};
$n = static fn (string $sql): int => (int) $db->query($sql)->fetch_row()[0];
$baru = ['kesehatan_obat', 'kesehatan_rujukan', 'kesehatan_tindak_lanjut', 'kesehatan_versi_rincian'];
$fase1 = ['kesehatan_assignments', 'kesehatan_kunjungan', 'kesehatan_kunjungan_versi', 'kesehatan_idempotensi', 'kesehatan_akses_log'];
$tercatat = $n("SELECT COUNT(*) FROM schema_migrations WHERE migration = '025_v5_fase2_rincian_kesehatan.sql'");

foreach ($fase1 as $t) {
    $periksa($tabelAda($t), 'Tabel 024 ' . $t . ' tetap ada');
}
if ($rollback) {
    foreach ($baru as $t) {
        $periksa(!$tabelAda($t), 'Tabel ' . $t . ' sudah dilepas');
    }
    $periksa($tercatat === 0, 'Migrasi 025 tidak lagi tercatat');
} else {
    foreach ($baru as $t) {
        $periksa($tabelAda($t), 'Tabel ' . $t);
    }
    $periksa($tercatat === 1, 'Migrasi 025 tercatat sekali');

    $kendala = $db->query("SELECT k.CONSTRAINT_NAME, k.TABLE_NAME, k.REFERENCED_TABLE_NAME,
            GROUP_CONCAT(k.COLUMN_NAME ORDER BY k.ORDINAL_POSITION) kolom, GROUP_CONCAT(k.REFERENCED_COLUMN_NAME ORDER BY k.ORDINAL_POSITION) rujukan
          FROM information_schema.KEY_COLUMN_USAGE k
         WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME IN ('kesehatan_obat', 'kesehatan_rujukan', 'kesehatan_tindak_lanjut', 'kesehatan_versi_rincian')
           AND k.REFERENCED_TABLE_NAME IS NOT NULL
         GROUP BY k.CONSTRAINT_NAME, k.TABLE_NAME, k.REFERENCED_TABLE_NAME ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME")->fetch_all(MYSQLI_ASSOC);
    $periksa(count($kendala) === 12, 'Kunci asing tabel 025 terpasang (' . count($kendala) . ', termasuk satu gabungan)');
    $yatim = 0;
    foreach ($kendala as $fk) {
        foreach ([$fk['TABLE_NAME'], $fk['REFERENCED_TABLE_NAME'], $fk['kolom'], $fk['rujukan']] as $nama) {
            if (!preg_match('/^[a-zA-Z0-9_,]+$/D', (string) $nama)) {
                exit(2);
            }
        }
        $kolom = explode(',', (string) $fk['kolom']);
        $rujukan = explode(',', (string) $fk['rujukan']);
        $on = implode(' AND ', array_map(static fn (string $c, string $r): string => "p.`$r` = c.`$c`", $kolom, $rujukan));
        $tidakNull = implode(' AND ', array_map(static fn (string $c): string => "c.`$c` IS NOT NULL", $kolom));
        $hit = $n("SELECT COUNT(*) FROM `{$fk['TABLE_NAME']}` c LEFT JOIN `{$fk['REFERENCED_TABLE_NAME']}` p ON $on WHERE $tidakNull AND p.`{$rujukan[0]}` IS NULL");
        $yatim += $hit;
        $periksa($hit === 0, "Referensi {$fk['TABLE_NAME']}({$fk['kolom']}) → {$fk['REFERENCED_TABLE_NAME']} tidak yatim");
    }
    echo 'Jumlah referensi yatim tabel 025: ' . $yatim . PHP_EOL;

    $periksa($n('SELECT COUNT(*) FROM kesehatan_kunjungan k LEFT JOIN (SELECT kunjungan_id, MAX(versi) maks, COUNT(*) jumlah FROM kesehatan_kunjungan_versi GROUP BY kunjungan_id) v
        ON v.kunjungan_id = k.id WHERE v.maks IS NULL OR v.maks <> k.versi OR v.jumlah <> k.versi') === 0, 'Versi kunjungan monoton tanpa celah dan sama dengan versi terbaru');
    $periksa($n('SELECT COUNT(*) FROM kesehatan_kunjungan_versi v LEFT JOIN kesehatan_versi_rincian r ON r.kunjungan_id = v.kunjungan_id AND r.versi = v.versi
        WHERE v.versi > 1 AND r.id IS NULL') === 0, 'Setiap versi koreksi (> 1) punya snapshot rincian');
    $periksa($n("SELECT COUNT(*) FROM kesehatan_kunjungan_versi v JOIN kesehatan_kunjungan_versi s ON s.kunjungan_id = v.kunjungan_id AND s.versi = v.versi - 1
        WHERE s.status = 'Tercatat' AND (v.alasan IS NULL OR TRIM(v.alasan) = '')") === 0, 'Setiap koreksi atas versi Tercatat punya alasan');
    $periksa($n("SELECT COUNT(*) FROM kesehatan_kunjungan_versi v JOIN kesehatan_kunjungan_versi s ON s.kunjungan_id = v.kunjungan_id AND s.versi = v.versi - 1
        WHERE s.status = 'Tercatat' AND v.status = 'Draf'") === 0, 'Tidak ada kunjungan Tercatat yang kembali menjadi Draf');
    $tidakSama = 0;
    foreach (['obat' => 'kesehatan_obat', 'rujukan' => 'kesehatan_rujukan', 'tindak_lanjut' => 'kesehatan_tindak_lanjut'] as $kunci => $t) {
        $tidakSama += $n("SELECT COUNT(*) FROM kesehatan_kunjungan k JOIN kesehatan_versi_rincian r ON r.kunjungan_id = k.id AND r.versi = k.versi
            WHERE JSON_LENGTH(r.rincian, '$.$kunci') <> (SELECT COUNT(*) FROM `$t` x WHERE x.kunjungan_id = k.id AND x.versi_dibatalkan IS NULL)");
    }
    $periksa($tidakSama === 0, 'Rincian aktif sama jumlahnya dengan snapshot versi terbaru');
    $periksa($n('SELECT COUNT(*) FROM kesehatan_kunjungan k WHERE NOT EXISTS (SELECT 1 FROM kesehatan_versi_rincian r WHERE r.kunjungan_id = k.id AND r.versi = k.versi)
        AND (EXISTS (SELECT 1 FROM kesehatan_obat o WHERE o.kunjungan_id = k.id) OR EXISTS (SELECT 1 FROM kesehatan_rujukan r WHERE r.kunjungan_id = k.id)
             OR EXISTS (SELECT 1 FROM kesehatan_tindak_lanjut t WHERE t.kunjungan_id = k.id))') === 0, 'Tidak ada rincian tanpa snapshot versi');
    $periksa($n('SELECT COUNT(*) FROM kesehatan_idempotensi WHERE kunjungan_id IS NULL') === 0, 'Tidak ada klaim idempotensi yang menggantung');
    $bocor = $n("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'kesehatan.%'
        AND (COALESCE(before_json, '') REGEXP '\"(keluhan|catatan_klinis|tindakan|alasan|nama_obat|petunjuk|tujuan|hasil|uraian)\"'
          OR COALESCE(after_json, '') REGEXP '\"(keluhan|catatan_klinis|tindakan|alasan|nama_obat|petunjuk|tujuan|hasil|uraian)\"')");
    $periksa($bocor === 0, 'audit_logs kesehatan tanpa kolom isi klinis atau rincian');
    $stok = $n("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND (TABLE_NAME LIKE '%stok%' OR TABLE_NAME LIKE '%persediaan%')");
    $periksa($stok === 0, 'Tidak ada tabel stok/persediaan obat');
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
        $kini = $n('SELECT COUNT(*) FROM `' . $t . '`');
        $periksa($kini === (int) $sebelum, "Jumlah baris lama $t tetap $sebelum");
    }
} else {
    echo "[belum diperiksa] Perbandingan baris tabel lama: berikan path manifest preflight.\n";
}
exit($gagal > 0 ? 1 : 0);
