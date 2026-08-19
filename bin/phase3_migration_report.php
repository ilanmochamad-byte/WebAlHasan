<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$db = app_db();
$summary = $db->query("SELECT COUNT(*) total, SUM(jam_migration_status='Berhasil') parsed_count, SUM(jam_migration_status='Gagal') failed_count FROM jadwal_ngaji")?->fetch_assoc();
if (!$summary) { throw new RuntimeException('Migrasi Fase 3 belum tersedia atau laporan tidak dapat dibaca.'); }
$issues = $db->query('SELECT jadwal_id, original_jam, normalized_candidate, reason, reported_at FROM jadwal_jam_migration_report ORDER BY jadwal_id')?->fetch_all(MYSQLI_ASSOC) ?? [];
$duplicates = $db->query('SELECT jadwal_id, tanggal_pertemuan, COUNT(*) total FROM pertemuan_pengajian GROUP BY jadwal_id, tanggal_pertemuan HAVING COUNT(*) > 1')?->fetch_all(MYSQLI_ASSOC) ?? [];
$orphaned = $db->query('SELECT j.id FROM jadwal_ngaji j LEFT JOIN tahun_ajaran t ON t.id=j.id_tahun LEFT JOIN kelas k ON k.id=j.id_kelas LEFT JOIN guru g ON g.id=j.id_guru WHERE t.id IS NULL OR k.id IS NULL OR g.id IS NULL')?->fetch_all(MYSQLI_ASSOC) ?? [];
echo json_encode(['schedule_time_migration' => $summary, 'unparsed_values' => $issues, 'duplicate_meetings' => $duplicates, 'orphaned_schedule_relations' => $orphaned], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
