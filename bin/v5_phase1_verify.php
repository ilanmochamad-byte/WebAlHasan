<?php

declare(strict_types=1);

/**
 * Verifier PRD V5 Fase 1 (migrasi 024). Hanya membaca.
 *
 *   php bin/v5_phase1_verify.php [manifest.json]            # sesudah migrasi
 *   php bin/v5_phase1_verify.php --rollback [manifest.json] # sesudah rollback
 *
 * Sesudah migrasi: lima tabel kesehatan ada, migrasi 024 tercatat sekali,
 * tidak ada referensi yatim pada kunci asing tabel kesehatan, setiap
 * kunjungan punya snapshot versi terkininya, dan tidak ada isi klinis di
 * `audit_logs`. Dengan manifest: jumlah baris seluruh tabel lama tetap
 * (santri, wali, santri_wali, users, penugasan, V1–V4, ...).
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
$kesehatan = ['kesehatan_assignments', 'kesehatan_kunjungan', 'kesehatan_kunjungan_versi', 'kesehatan_idempotensi', 'kesehatan_akses_log'];
$tercatat = (int) $db->query("SELECT COUNT(*) AS n FROM schema_migrations WHERE migration = '024_v5_fase1_kesehatan.sql'")->fetch_assoc()['n'];

if ($rollback) {
    foreach ($kesehatan as $t) {
        $periksa(!$tabelAda($t), 'Tabel ' . $t . ' sudah dilepas');
    }
    $periksa($tercatat === 0, 'Migrasi 024 tidak lagi tercatat');
} else {
    foreach ($kesehatan as $t) {
        $periksa($tabelAda($t), 'Tabel ' . $t);
    }
    $periksa($tercatat === 1, 'Migrasi 024 tercatat sekali');

    $yatim = 0;
    $fks = $db->query("SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'kesehatan\\_%' AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME, COLUMN_NAME")->fetch_all(MYSQLI_ASSOC);
    $periksa(count($fks) === 18, 'Kunci asing tabel kesehatan terpasang (' . count($fks) . ')');
    foreach ($fks as $fk) {
        foreach ($fk as $nama) {
            if (!preg_match('/^[a-zA-Z0-9_]+$/D', (string) $nama)) {
                exit(2);
            }
        }
        [$c, $col, $p, $key] = [$fk['TABLE_NAME'], $fk['COLUMN_NAME'], $fk['REFERENCED_TABLE_NAME'], $fk['REFERENCED_COLUMN_NAME']];
        $n = (int) $db->query("SELECT COUNT(*) AS n FROM `$c` c LEFT JOIN `$p` p ON p.`$key` = c.`$col` WHERE c.`$col` IS NOT NULL AND p.`$key` IS NULL")->fetch_assoc()['n'];
        $yatim += $n;
        $periksa($n === 0, "Referensi $c.$col → $p tidak yatim");
    }
    echo 'Jumlah referensi yatim tabel kesehatan: ' . $yatim . PHP_EOL;

    $tanpaVersi = (int) $db->query('SELECT COUNT(*) AS n FROM kesehatan_kunjungan k LEFT JOIN kesehatan_kunjungan_versi v ON v.kunjungan_id = k.id AND v.versi = k.versi WHERE v.id IS NULL')->fetch_assoc()['n'];
    $periksa($tanpaVersi === 0, 'Setiap kunjungan punya snapshot versi terkininya');
    $ganda = (int) $db->query('SELECT COUNT(*) AS n FROM (SELECT created_by, idempotency_key FROM kesehatan_kunjungan GROUP BY created_by, idempotency_key HAVING COUNT(*) > 1) g')->fetch_assoc()['n'];
    $periksa($ganda === 0, 'Tidak ada kunjungan ganda per kunci idempotensi');
    $idemYatim = (int) $db->query('SELECT COUNT(*) AS n FROM kesehatan_idempotensi WHERE kunjungan_id IS NULL')->fetch_assoc()['n'];
    $periksa($idemYatim === 0, 'Tidak ada klaim idempotensi yang menggantung');
    $bocor = (int) $db->query("SELECT COUNT(*) AS n FROM audit_logs WHERE action LIKE 'kesehatan.%'
        AND (COALESCE(before_json, '') REGEXP '\"(keluhan|catatan_klinis|tindakan)\"' OR COALESCE(after_json, '') REGEXP '\"(keluhan|catatan_klinis|tindakan)\"')")->fetch_assoc()['n'];
    $periksa($bocor === 0, 'audit_logs kesehatan tanpa kolom isi klinis');
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
        $kini = (int) $db->query('SELECT COUNT(*) AS n FROM `' . $t . '`')->fetch_assoc()['n'];
        $periksa($kini === (int) $sebelum, "Jumlah baris lama $t tetap $sebelum");
    }
} else {
    echo "[belum diperiksa] Perbandingan baris tabel lama: berikan path manifest preflight.\n";
}
exit($gagal > 0 ? 1 : 0);
