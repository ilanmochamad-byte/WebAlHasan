<?php

declare(strict_types=1);

/**
 * Pembantu bersama alat rilis PRD V7 Fase 2 (migrasi 031). Memakai pembantu
 * Fase 1 dan hanya membaca basis data.
 */

require_once __DIR__ . '/_v7_phase1.php';

const V7F2_MIGRASI = '031_v7_fase2_tinjauan_pembimbing.sql';
const V7F2_TABEL = ['izin_pembimbing_tinjauan', 'izin_wali_perbaikan'];
const V7F2_OPERASI = ['izin.wali.tinjauan', 'izin.wali.perbaikan', 'izin.wali.batal'];

/**
 * Peristiwa riwayat yang masing-masing menaikkan versi pengajuan tepat satu
 * (V7 Fase 1–2 dan penetapan/keputusan/koreksi V2 atas baris yang sama).
 */
const V7F2_PERISTIWA_VERSI = [
    'pembimbing_ditetapkan', 'pembimbing_ditetapkan_ulang',
    'tinjauan_minta_perbaikan', 'tinjauan_ditolak_pembimbing', 'tinjauan_diteruskan',
    'perbaikan_wali_dikirim', 'pembatalan_wali',
    'murobi_ditetapkan', 'murobi_ditetapkan_ulang', 'keputusan', 'keputusan_dikoreksi',
];

/**
 * Snapshot Fase 1 tanpa tabel 031, sehingga manifest sebelum dan sesudah
 * migrasi memuat daftar tabel lama yang sama.
 *
 * @return array{legacy_counts:array<string,int>, id_fingerprints:array<string,string>}
 */
function v7f2_snapshot(mysqli $db): array
{
    $snapshot = v7f1_snapshot($db);
    foreach (V7F2_TABEL as $tabel) {
        unset($snapshot['legacy_counts'][$tabel]);
    }

    return $snapshot;
}

/** Daftar SQL bertanda kutip untuk klausa IN. */
function v7f2_in(array $nilai): string
{
    return "'" . implode("','", $nilai) . "'";
}
