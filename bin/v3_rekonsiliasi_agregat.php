<?php

declare(strict_types=1);

/**
 * Membangun ulang `v3_poin_agregat` dari `v3_poin_ledger`.
 *
 * Agregat adalah cache turunan, bukan sumber kebenaran (PRD V3 5.8). Rollback
 * skema Fase 2 membuang tabelnya sementara ledger tetap utuh, sehingga setelah
 * pemasangan ulang operator perlu satu perintah untuk memulihkan total tanpa
 * menyentuh catatan bisnis.
 *
 *   php bin/v3_rekonsiliasi_agregat.php --dry-run
 *   php bin/v3_rekonsiliasi_agregat.php
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$dryRun = in_array('--dry-run', $argv, true);
$repo = new App\V3\PelanggaranRepository(app_db());

$pending = $repo->all(
    'SELECT x.santri_id,x.tahun_ajaran_id,x.total AS ledger,a.id AS agregat_id,a.total_poin
       FROM (SELECT l.santri_id,l.tahun_ajaran_id,COALESCE(SUM(l.perubahan_poin),0) total
               FROM v3_poin_ledger l
              WHERE l.archived_at IS NULL
              GROUP BY l.santri_id,l.tahun_ajaran_id) x
       LEFT JOIN v3_poin_agregat a
         ON a.santri_id=x.santri_id AND a.tahun_ajaran_id=x.tahun_ajaran_id
      WHERE a.id IS NULL OR a.total_poin<>x.total
      ORDER BY x.santri_id,x.tahun_ajaran_id'
);

foreach ($pending as $row) {
    $before = $row['agregat_id'] === null ? 'tidak ada baris' : (string) $row['total_poin'];
    echo 'santri ' . (int) $row['santri_id'] . ' / tahun ' . (int) $row['tahun_ajaran_id']
        . ': agregat ' . $before . ' -> ledger ' . (int) $row['ledger'] . PHP_EOL;
}

if ($pending === []) {
    echo "Seluruh agregat sudah cocok dengan ledger.\n";
    exit(0);
}

if ($dryRun) {
    echo count($pending) . " subjek perlu direkonsiliasi. Jalankan tanpa --dry-run untuk menerapkan.\n";
    exit(0);
}

$repo->execute(
    'INSERT INTO v3_poin_agregat (santri_id,tahun_ajaran_id,total_poin,direkonsiliasi_pada)
     SELECT l.santri_id,l.tahun_ajaran_id,COALESCE(SUM(l.perubahan_poin),0),NOW()
       FROM v3_poin_ledger l
      WHERE l.archived_at IS NULL
      GROUP BY l.santri_id,l.tahun_ajaran_id
         ON DUPLICATE KEY UPDATE total_poin=VALUES(total_poin),direkonsiliasi_pada=NOW()'
);

$sisa = (int) ($repo->one(
    'SELECT COUNT(*) n
       FROM (SELECT l.santri_id,l.tahun_ajaran_id,COALESCE(SUM(l.perubahan_poin),0) total
               FROM v3_poin_ledger l WHERE l.archived_at IS NULL
              GROUP BY l.santri_id,l.tahun_ajaran_id) x
       LEFT JOIN v3_poin_agregat a
         ON a.santri_id=x.santri_id AND a.tahun_ajaran_id=x.tahun_ajaran_id
      WHERE a.id IS NULL OR a.total_poin<>x.total'
)['n'] ?? 0);

if ($sisa !== 0) {
    fwrite(STDERR, "BLOCKER: {$sisa} subjek masih berselisih setelah rekonsiliasi.\n");
    exit(1);
}

echo count($pending) . " subjek direkonsiliasi. Post-check tidak menemukan selisih.\n";
exit(0);
