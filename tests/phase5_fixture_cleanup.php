<?php

declare(strict_types=1);

$root = dirname(__DIR__);
if (getenv('PHASE5_CLEANUP_FIXTURE') !== '1') {
    fwrite(STDERR, "Set PHASE5_CLEANUP_FIXTURE=1 untuk membersihkan fixture manual Fase 5.\n");
    exit(2);
}
require_once $root . '/app/bootstrap.php';
if (!str_ends_with((string) app_config('database.database'), '_test')) {
    fwrite(STDERR, "Pembersihan ditolak: DB_NAME wajib berakhiran _test.\n");
    exit(2);
}
$path = $argv[1] ?? '';
if ($path === '' || !is_file($path)) {
    fwrite(STDERR, "Manifest fixture tidak ditemukan.\n");
    exit(2);
}
$manifest = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
if (($manifest['database'] ?? null) !== app_config('database.database') || !is_array($manifest['created'] ?? null)) {
    fwrite(STDERR, "Manifest tidak cocok dengan database target.\n");
    exit(2);
}
$db = app_db();
$created = $manifest['created'];
foreach (array_reverse($created['meetings'] ?? []) as $id) {
    $db->query('DELETE FROM absensi_santri WHERE pertemuan_id='.(int)$id);
    $db->query('DELETE FROM absensi_guru WHERE pertemuan_id='.(int)$id);
    $db->query('DELETE FROM pertemuan_peserta WHERE pertemuan_id='.(int)$id);
    $db->query('DELETE FROM pertemuan_pengajian WHERE id='.(int)$id);
}
foreach (array_reverse($created['schedules'] ?? []) as $id) { $db->query('DELETE FROM jadwal_ngaji WHERE id='.(int)$id); }
foreach (array_reverse($created['users'] ?? []) as $id) { $db->query('DELETE FROM users WHERE id='.(int)$id); }
foreach (array_reverse($created['students'] ?? []) as $id) {
    $db->query('DELETE FROM santri_wali WHERE santri_id='.(int)$id);
    $db->query('DELETE FROM plotting_kelas WHERE id_santri='.(int)$id);
    $db->query('DELETE FROM santri WHERE id='.(int)$id);
}
foreach (array_reverse($created['classes'] ?? []) as $id) { $db->query('DELETE FROM kelas WHERE id='.(int)$id); }
foreach (array_reverse($created['gurus'] ?? []) as $id) { $db->query('DELETE FROM guru WHERE id='.(int)$id); }
$db->query('DELETE FROM audit_logs WHERE id > ' . (int) ($manifest['audit_start'] ?? PHP_INT_MAX));
unlink($path);
echo "Fixture manual Fase 5 dibersihkan dari database uji.\n";
