<?php

declare(strict_types=1);

use App\Schedule\LegacyTimeParser;

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$output = APP_ROOT . '/storage/reports/phase3-preflight-' . date('Ymd_His');
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--output=')) { $output = rtrim(substr($argument, 9), '/'); }
}
if (!is_dir($output) && !mkdir($output, 0700, true) && !is_dir($output)) {
    throw new RuntimeException('Direktori laporan tidak dapat dibuat: ' . $output);
}

$db = app_db();
$rows = $db->query('SELECT id, id_tahun, jam, id_kelas, fan_ilmu, nama_kitab, id_guru, tempat FROM jadwal_ngaji ORDER BY id');
if ($rows === false) { throw new RuntimeException('Tabel jadwal_ngaji tidak dapat dibaca.'); }
$summary = ['total' => 0, 'parseable' => 0, 'unparseable' => 0, 'relation_issues' => []];
$failures = [];
while ($row = $rows->fetch_assoc()) {
    $summary['total']++;
    $parsed = LegacyTimeParser::parse((string) $row['jam']);
    if ($parsed['success']) { $summary['parseable']++; }
    else {
        $summary['unparseable']++;
        $failures[] = ['jadwal_id' => (int) $row['id'], 'original_jam' => $row['jam'], 'normalized_candidate' => $parsed['normalized'], 'reason' => $parsed['reason']];
    }
}

foreach ([
    'tahun_ajaran' => 'SELECT j.id jadwal_id, j.id_tahun missing_id FROM jadwal_ngaji j LEFT JOIN tahun_ajaran t ON t.id=j.id_tahun WHERE t.id IS NULL',
    'kelas' => 'SELECT j.id jadwal_id, j.id_kelas missing_id FROM jadwal_ngaji j LEFT JOIN kelas k ON k.id=j.id_kelas WHERE k.id IS NULL',
    'guru' => 'SELECT j.id jadwal_id, j.id_guru missing_id FROM jadwal_ngaji j LEFT JOIN guru g ON g.id=j.id_guru WHERE g.id IS NULL',
] as $relation => $sql) {
    $result = $db->query($sql);
    $summary['relation_issues'][$relation] = $result ? $result->fetch_all(MYSQLI_ASSOC) : [['error' => $db->error]];
}

file_put_contents($output . '/summary.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$csv = fopen($output . '/unparsed-jam.csv', 'wb');
if ($csv === false) { throw new RuntimeException('CSV laporan parsing tidak dapat dibuat.'); }
fputcsv($csv, ['jadwal_id', 'original_jam', 'normalized_candidate', 'reason']);
foreach ($failures as $failure) { fputcsv($csv, $failure); }
fclose($csv);
echo "Preflight Fase 3 dibuat di {$output}\n";
echo "Jadwal: {$summary['total']}; dapat diparsing: {$summary['parseable']}; gagal: {$summary['unparseable']}\n";
