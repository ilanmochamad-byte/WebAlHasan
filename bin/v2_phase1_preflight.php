<?php

declare(strict_types=1);

use App\Database\BackupWriter;

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Preflight V2 Fase 1.
 *
 * Menghasilkan, sebelum migrasi 006 dijalankan:
 *   - inventaris skema tabel yang tersentuh,
 *   - backup basis data lengkap + manifest jumlah baris,
 *   - laporan relasi yatim (blocking),
 *   - laporan pengajuan izin lama.
 *
 * Keluar dengan kode 3 bila ditemukan relasi yatim: migrasi TIDAK boleh dijalankan
 * sebelum masalah itu diselesaikan, karena `izin_pengajuan` memasang foreign key
 * ke `santri`.
 */

$base = APP_ROOT . '/storage/backups/v2-phase1/' . date('Ymd_His');
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--output=')) {
        $base = rtrim(substr($argument, 9), '/');
    }
}
if (!is_dir($base) && !mkdir($base, 0700, true) && !is_dir($base)) {
    throw new RuntimeException('Direktori output tidak dapat dibuat: ' . $base);
}

$db = app_db();

$inventoryTables = [
    'perizinan', 'users', 'roles', 'user_roles', 'pengurus', 'wali', 'santri_wali',
    'murobi_assignments', 'plotting_kamar', 'plotting_kelas', 'santri', 'guru',
    'tahun_ajaran', 'kamar', 'kelas', 'audit_logs',
];

$inventory = [];
$missing = [];
foreach ($inventoryTables as $table) {
    $escaped = '`' . str_replace('`', '``', $table) . '`';
    $columns = $db->query('SHOW COLUMNS FROM ' . $escaped);
    if ($columns === false) {
        $missing[] = $table;
        continue;
    }
    $rows = [];
    while ($column = $columns->fetch_assoc()) {
        $rows[] = [
            'field' => $column['Field'],
            'type' => $column['Type'],
            'null' => $column['Null'],
            'key' => $column['Key'],
            'default' => $column['Default'],
            'extra' => $column['Extra'],
        ];
    }
    $count = $db->query('SELECT COUNT(*) AS jumlah FROM ' . $escaped);
    $inventory[$table] = [
        'rows' => $count ? (int) $count->fetch_assoc()['jumlah'] : -1,
        'columns' => $rows,
    ];
}

/**
 * Laporan relasi yatim. Setiap entri yang tidak nol WAJIB diselesaikan sebelum migrasi.
 *
 * @var array<string, string> $orphanQueries
 */
$orphanQueries = [
    'perizinan_tanpa_santri' => 'SELECT p.id, p.id_santri FROM perizinan p LEFT JOIN santri s ON s.id = p.id_santri WHERE s.id IS NULL',
    'santri_wali_tanpa_santri' => 'SELECT sw.id, sw.santri_id FROM santri_wali sw LEFT JOIN santri s ON s.id = sw.santri_id WHERE s.id IS NULL',
    'santri_wali_tanpa_wali' => 'SELECT sw.id, sw.wali_id FROM santri_wali sw LEFT JOIN wali w ON w.id = sw.wali_id WHERE w.id IS NULL',
    'murobi_tanpa_guru' => 'SELECT ma.id, ma.guru_id FROM murobi_assignments ma LEFT JOIN guru g ON g.id = ma.guru_id WHERE g.id IS NULL',
    'plotting_kamar_tanpa_santri' => 'SELECT pk.id, pk.id_santri FROM plotting_kamar pk LEFT JOIN santri s ON s.id = pk.id_santri WHERE s.id IS NULL',
    'plotting_kelas_tanpa_santri' => 'SELECT pk.id, pk.id_santri FROM plotting_kelas pk LEFT JOIN santri s ON s.id = pk.id_santri WHERE s.id IS NULL',
];

$orphans = [];
$blocking = 0;
foreach ($orphanQueries as $key => $sql) {
    $result = $db->query($sql);
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $orphans[$key] = $rows;
    if ($key === 'perizinan_tanpa_santri') {
        $blocking += count($rows);
    }
}

$legacyResult = $db->query(
    "SELECT status, COUNT(*) AS jumlah, MIN(id) AS id_min, MAX(id) AS id_max FROM perizinan GROUP BY status"
);
$legacyByStatus = $legacyResult ? $legacyResult->fetch_all(MYSQLI_ASSOC) : [];
$legacyTotalResult = $db->query('SELECT COUNT(*) AS jumlah, IFNULL(MIN(id),0) AS id_min, IFNULL(MAX(id),0) AS id_max FROM perizinan');
$legacyTotal = $legacyTotalResult ? $legacyTotalResult->fetch_assoc() : ['jumlah' => 0, 'id_min' => 0, 'id_max' => 0];
$legacyIdsResult = $db->query('SELECT id FROM perizinan ORDER BY id');
$legacyIds = [];
while ($legacyIdsResult && $row = $legacyIdsResult->fetch_assoc()) {
    $legacyIds[] = (int) $row['id'];
}

$writer = new BackupWriter($db);
$counts = $writer->write($base . '/database.sql');

$manifest = [
    'created_at' => date(DATE_ATOM),
    'phase' => 'v2-phase-1',
    'database' => app_config('database.database'),
    'backup_file' => 'database.sql',
    'row_counts' => $counts,
    'legacy_perizinan' => [
        'total' => (int) $legacyTotal['jumlah'],
        'id_min' => (int) $legacyTotal['id_min'],
        'id_max' => (int) $legacyTotal['id_max'],
        'ids' => $legacyIds,
        'per_status' => $legacyByStatus,
    ],
    'orphan_counts' => array_map('count', $orphans),
    'blocking_orphans' => $blocking,
];
file_put_contents(
    $base . '/manifest.json',
    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);
file_put_contents(
    $base . '/inventory.json',
    json_encode(['tables' => $inventory, 'missing' => $missing], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

$report = "# Preflight V2 Fase 1\n\n";
$report .= '- Waktu: ' . $manifest['created_at'] . "\n";
$report .= '- Database: `' . $manifest['database'] . "`\n";
$report .= "- Backup: `database.sql`\n";
$report .= '- Relasi yatim yang memblokir migrasi: **' . $blocking . "**\n\n";

$report .= "## Inventaris skema\n\n| Tabel | Jumlah baris | Jumlah kolom |\n|---|---:|---:|\n";
foreach ($inventory as $table => $info) {
    $report .= '| `' . $table . '` | ' . $info['rows'] . ' | ' . count($info['columns']) . " |\n";
}
if ($missing !== []) {
    $report .= "\n> Tabel berikut TIDAK ditemukan: `" . implode('`, `', $missing) . "`. "
        . "Jalankan migrasi V1 001–005 terlebih dahulu.\n";
}

$report .= "\n## Jumlah baris backup\n\n| Tabel | Jumlah |\n|---|---:|\n";
foreach ($counts as $table => $count) {
    $report .= '| `' . $table . '` | ' . $count . " |\n";
}

$report .= "\n## Laporan relasi yatim\n\n| Pemeriksaan | Jumlah |\n|---|---:|\n";
foreach ($orphans as $key => $rows) {
    $report .= '| `' . $key . '` | ' . count($rows) . " |\n";
}
foreach ($orphans as $key => $rows) {
    if ($rows === []) {
        continue;
    }
    $report .= "\n### `{$key}`\n\n```json\n"
        . json_encode(array_slice($rows, 0, 50), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n```\n";
}

$report .= "\n## Pengajuan izin lama\n\n";
$report .= '- Total: ' . (int) $legacyTotal['jumlah'] . "\n";
$report .= '- Rentang ID: ' . (int) $legacyTotal['id_min'] . ' – ' . (int) $legacyTotal['id_max'] . "\n\n";
$report .= "| Status lama | Jumlah | ID min | ID max |\n|---|---:|---:|---:|\n";
foreach ($legacyByStatus as $row) {
    $report .= '| `' . $row['status'] . '` | ' . $row['jumlah'] . ' | ' . $row['id_min'] . ' | ' . $row['id_max'] . " |\n";
}
$report .= "\nPemetaan status: `Pending` → `Diajukan`, `Disetujui` → `Disetujui`, `Ditolak` → `Ditolak`.\n";
$report .= "Pelaku pada data lama tidak diketahui dan akan disimpan sebagai `NULL` dengan penanda `Data warisan`.\n";

file_put_contents($base . '/report.md', $report);

echo "Preflight V2 Fase 1 selesai. Artefak: {$base}\n";
echo '- Pengajuan izin lama: ' . (int) $legacyTotal['jumlah'] . "\n";
echo '- Relasi yatim memblokir: ' . $blocking . "\n";

if ($missing !== []) {
    fwrite(STDERR, "Migrasi V1 belum lengkap. Tabel hilang: " . implode(', ', $missing) . "\n");
    exit(2);
}

if ($blocking > 0) {
    fwrite(STDERR, "JANGAN jalankan migrasi 006: ada {$blocking} baris `perizinan` tanpa santri yang cocok.\n");
    exit(3);
}

exit(0);
