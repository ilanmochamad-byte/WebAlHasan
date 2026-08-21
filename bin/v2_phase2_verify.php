<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Verifikasi pasca-migrasi V2 Fase 2.
 *
 * Pemakaian:
 *   php bin/v2_phase2_verify.php [/path/manifest.json]
 *
 * Memeriksa skema Fase 2, keutuhan data perizinan lama, dan invarian alur:
 * satu keputusan per pengajuan, alasan wajib terisi, setiap pengajuan V2
 * memiliki riwayat, dan koreksi tidak menghapus keputusan.
 */

$db = app_db();
$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? '[sesuai]  ' : '[berbeda] ') . $message . PHP_EOL;
    if (!$ok) {
        $failures[] = $message;
    }
};
$scalar = static function (string $sql) use ($db): int {
    $result = $db->query($sql);
    return $result ? (int) array_values($result->fetch_assoc())[0] : -1;
};
$hasColumn = static fn (string $table, string $column): bool =>
    ($db->query('SHOW COLUMNS FROM `' . $table . "` LIKE '" . $db->real_escape_string($column) . "'")?->num_rows ?? 0) === 1;

// 1. Skema Fase 2.
foreach ([
    'routing_kandidat', 'routing_catatan', 'routing_pada',
    'murobi_ditetapkan_oleh_user_id', 'murobi_ditetapkan_pada',
    'dibatalkan_oleh_user_id', 'dibatalkan_pada', 'alasan_pembatalan',
] as $column) {
    $check($hasColumn('izin_pengajuan', $column), 'Kolom izin_pengajuan.' . $column . ' tersedia');
}
foreach (['dikoreksi_pada', 'jumlah_koreksi'] as $column) {
    $check($hasColumn('izin_keputusan', $column), 'Kolom izin_keputusan.' . $column . ' tersedia');
}
$check(($db->query("SHOW TABLES LIKE 'izin_keputusan_koreksi'")?->num_rows ?? 0) === 1, 'Tabel izin_keputusan_koreksi tersedia');
foreach (['izin_pengajuan_antrean_index', 'izin_pengajuan_overlap_index'] as $index) {
    $check(
        $scalar("SELECT COUNT(*) FROM information_schema.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'izin_pengajuan'
                    AND INDEX_NAME = '" . $db->real_escape_string($index) . "'") > 0,
        'Indeks ' . $index . ' terpasang'
    );
}

// 2. Data lama tetap utuh.
$check(($db->query("SHOW TABLES LIKE 'perizinan'")?->num_rows ?? 0) === 1, 'Tabel `perizinan` lama tidak dihapus');
$legacyCount = $scalar('SELECT COUNT(*) FROM perizinan');
$migratedCount = $scalar('SELECT COUNT(*) FROM izin_pengajuan WHERE is_legacy = 1');
$check($legacyCount === $migratedCount, "Jumlah pengajuan warisan tetap sama ({$legacyCount} vs {$migratedCount})");
$check(
    $scalar("SELECT COUNT(*) FROM perizinan p JOIN izin_pengajuan t ON t.id = p.id
             WHERE p.id_santri <> t.santri_id OR p.tgl_izin <> t.tgl_izin OR p.tgl_kembali <> t.tgl_kembali
                OR p.alasan <> t.alasan
                OR t.status <> CASE p.status WHEN 'Pending' THEN 'Diajukan' ELSE p.status END") === 0,
    'Nilai bisnis pengajuan warisan tidak berubah'
);
$check(
    $scalar('SELECT COUNT(*) FROM izin_pengajuan WHERE is_legacy = 1 AND (routing_pada IS NOT NULL OR murobi_ditetapkan_pada IS NOT NULL OR dibatalkan_pada IS NOT NULL)') === 0,
    'Kolom jejak Fase 2 tidak diisi untuk data warisan'
);

// 3. Invarian alur Fase 2.
$check(
    $scalar('SELECT COUNT(*) FROM (SELECT pengajuan_id FROM izin_keputusan GROUP BY pengajuan_id HAVING COUNT(*) > 1) x') === 0,
    'Setiap pengajuan memiliki paling banyak satu keputusan'
);
$check(
    $scalar("SELECT COUNT(*) FROM izin_keputusan
              WHERE kapasitas = 'Admin Pengganti'
                AND (alasan_penggantian IS NULL OR CHAR_LENGTH(TRIM(alasan_penggantian)) = 0)") === 0,
    'Seluruh keputusan Admin Pengganti memiliki alasan penggantian'
);
$check(
    $scalar("SELECT COUNT(*) FROM izin_keputusan WHERE CHAR_LENGTH(TRIM(alasan)) = 0") === 0,
    'Seluruh keputusan memiliki alasan'
);
$check(
    $scalar("SELECT COUNT(*) FROM izin_pengajuan p
              LEFT JOIN izin_riwayat_status r ON r.pengajuan_id = p.id
             WHERE p.is_legacy = 0 AND r.id IS NULL") === 0,
    'Setiap pengajuan V2 memiliki sedikitnya satu baris riwayat'
);
$check(
    $scalar("SELECT COUNT(*) FROM izin_pengajuan
              WHERE status IN ('Disetujui','Ditolak') AND is_legacy = 0
                AND id NOT IN (SELECT pengajuan_id FROM izin_keputusan)") === 0,
    'Setiap pengajuan V2 yang sudah diputus memiliki baris keputusan'
);
$check(
    $scalar("SELECT COUNT(*) FROM izin_pengajuan
              WHERE status = 'Dibatalkan' AND is_legacy = 0
                AND (alasan_pembatalan IS NULL OR CHAR_LENGTH(TRIM(alasan_pembatalan)) = 0)") === 0,
    'Seluruh pembatalan V2 memiliki alasan'
);
$check(
    $scalar('SELECT COUNT(*) FROM izin_keputusan_koreksi k
              LEFT JOIN izin_keputusan d ON d.id = k.keputusan_id
             WHERE d.id IS NULL') === 0,
    'Setiap koreksi tetap menunjuk keputusan yang masih ada (koreksi tidak menghapus keputusan)'
);
$check(
    $scalar('SELECT COUNT(*) FROM izin_keputusan_koreksi k
              LEFT JOIN izin_riwayat_status r
                ON r.pengajuan_id = k.pengajuan_id AND r.peristiwa = \'keputusan_dikoreksi\'
             WHERE r.id IS NULL') === 0,
    'Setiap koreksi memiliki jejak riwayat'
);
$check(
    $scalar("SELECT COUNT(*) FROM izin_idempotency_keys WHERE CHAR_LENGTH(request_hash) <> 64") === 0,
    'Seluruh kunci idempotensi menyimpan hash request'
);

// 4. Perbandingan dengan manifest preflight (opsional).
$manifestPath = $argv[1] ?? '';
if ($manifestPath !== '') {
    if (!is_file($manifestPath)) {
        fwrite(STDERR, "Manifest tidak ditemukan: {$manifestPath}\n");
        exit(2);
    }
    $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
    $expected = $manifest['legacy_perizinan'] ?? null;
    if (is_array($expected)) {
        $check((int) $expected['total'] === $legacyCount, 'Jumlah `perizinan` sama dengan manifest pra-migrasi');
        $actualIds = [];
        $result = $db->query('SELECT id FROM perizinan ORDER BY id');
        while ($result && $row = $result->fetch_assoc()) {
            $actualIds[] = (int) $row['id'];
        }
        $check($actualIds === array_map('intval', $expected['ids'] ?? []), 'Daftar ID `perizinan` identik dengan manifest pra-migrasi');
    }
    foreach (($manifest['row_counts'] ?? []) as $table => $count) {
        if (in_array($table, ['perizinan', 'santri', 'users', 'wali', 'santri_wali', 'pengurus', 'guru'], true)) {
            $identifier = '`' . str_replace('`', '``', (string) $table) . '`';
            $check($scalar('SELECT COUNT(*) FROM ' . $identifier) === (int) $count, 'Jumlah baris `' . $table . '` tidak berubah setelah migrasi');
        }
    }
}

echo PHP_EOL . ($failures === [] ? "Verifikasi V2 Fase 2: LULUS\n" : 'Verifikasi V2 Fase 2: GAGAL (' . count($failures) . " pemeriksaan)\n");
exit($failures === [] ? 0 : 2);
