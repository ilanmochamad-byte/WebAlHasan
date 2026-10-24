<?php

declare(strict_types=1);

/**
 * Pembantu bersama alat rilis PRD V5 Fase 5 (`v5_preflight.php`,
 * `v5_verify.php`, `v5_backup_restore_drill.php`). Hanya CLI.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Sidik jari identitas yang wajib utuh melewati migrasi/rollback V5:
 * ID + status santri, ID + status wali, dan relasi santri–wali. Hanya hash,
 * tidak ada nama/nomor yang ditulis ke manifest.
 *
 * @return array<string, array{baris:int, sha256:string}>
 */
function v5_sidik_jari(mysqli $db): array
{
    $hasil = [];
    foreach ([
        'santri' => 'SELECT id, nis, is_active, archived_at FROM santri ORDER BY id',
        'wali' => 'SELECT id, is_active, archived_at, merged_into_wali_id FROM wali ORDER BY id',
        'santri_wali' => 'SELECT id, santri_id, wali_id, hubungan, is_primary, archived_at FROM santri_wali ORDER BY id',
    ] as $nama => $sql) {
        $baris = 0;
        $ctx = hash_init('sha256');
        $r = $db->query($sql, MYSQLI_USE_RESULT);
        while ($row = $r->fetch_row()) {
            hash_update($ctx, implode("\x1f", array_map(static fn ($v): string => $v === null ? "\0" : (string) $v, $row)) . "\n");
            $baris++;
        }
        $r->free();
        $hasil[$nama] = ['baris' => $baris, 'sha256' => hash_final($ctx)];
    }

    return $hasil;
}
