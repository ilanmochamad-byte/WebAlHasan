<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? '[lulus] ' : '[gagal] ') . $message . PHP_EOL;
    if (!$condition) { $failures[] = $message; }
};
$source = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

$migration = $source('database/migrations/003_phase3_schedules_meetings.sql');
$rollback = $source('database/rollbacks/003_phase3_schedules_meetings.sql');
foreach (['ALTER TABLE jadwal_ngaji', 'ADD COLUMN hari', 'ADD COLUMN waktu_mulai', 'ADD COLUMN waktu_selesai', 'jam_migration_status', 'CREATE TABLE jadwal_jam_migration_report', 'CREATE TABLE pertemuan_pengajian', 'CREATE TABLE pertemuan_peserta', 'pertemuan_schedule_date_unique', 'pertemuan_participant_unique'] as $required) {
    $assert(str_contains($migration, $required), 'Migrasi Fase 3 memuat ' . $required);
}
$assert(!preg_match('/\b(?:DELETE\s+FROM|DROP\s+(?:TABLE|COLUMN))\b/i', $migration), 'Migrasi naik tidak menghapus tabel, kolom, atau baris lama');
$assert(!preg_match('/UPDATE\s+jadwal_ngaji\s+SET\s+jam\s*=/i', $migration), 'Migrasi naik tidak menimpa kolom jam lama');
$assert(str_contains($migration, 'original_jam') && str_contains($migration, "jam_migration_status = 'Gagal'"), 'Nilai jam gagal parsing dicatat bersama nilai asli');
$assert(str_contains($rollback, 'HANYA untuk staging') && str_contains($rollback, 'Jangan jalankan di produksi'), 'Rollback berpasangan memuat peringatan produksi');

$repository = $source('app/Schedule/ScheduleRepository.php');
$service = $source('app/Schedule/ScheduleService.php');
$assert(str_contains($repository, 'j.waktu_mulai < ? AND j.waktu_selesai > ?'), 'Pemeriksaan bentrok memakai aturan overlap rentang waktu');
$assert(str_contains($repository, 'j.id_guru = ?') && str_contains($service, 'Bentrok guru:'), 'Bentrok guru ditolak dengan pesan yang dapat ditindaklanjuti');
$assert(str_contains($repository, 'class_conflict') && str_contains($repository, 'place_conflict') && str_contains($service, 'Peringatan bentrok'), 'Bentrok kelas dan tempat menghasilkan peringatan');
$assert(substr_count($service, 'begin_transaction()') >= 3 && substr_count($service, 'rollback()') >= 3, 'Pembuatan, pembukaan, dan penyelesaian pertemuan memakai transaksi');
$assert(str_contains($repository, 'nis_snapshot') && str_contains($repository, 'nama_santri_snapshot') && str_contains($repository, "pk.status = 'Aktif'"), 'Pembukaan pertemuan membekukan peserta dari keanggotaan kelas aktif');
$assert(str_contains($service, "['Draf'") || str_contains($migration, "ENUM('Draf','Dibuka','Selesai')"), 'Status Draf, Dibuka, dan Selesai tersedia');
$assert(str_contains($service, 'requireOwnership') && str_contains($service, "in_array('guru'"), 'Guru dibatasi ke jadwal miliknya');
$assert(str_contains($repository, 'j.is_active = 1 AND j.archived_at IS NULL') && str_contains($repository, "ta.status = 'Aktif'"), 'Tugas guru hanya mengambil jadwal aktif pada semester aktif');

// PERUBAHAN LOKASI — paket perapihan V1-V2, keputusan pengguna 30 Agustus 2026
// (koreksi ke-4: jadwal dan pertemuan disatukan dalam satu menu bertab).
// Pemeriksaannya SETARA dengan sebelumnya; yang berpindah hanyalah berkas
// tempat UI-nya berada: `admin/_pengajian_jadwal.php` dan
// `admin/_pengajian_pertemuan.php`, dirender oleh `admin/admin_pengajian.php`.
$schedulePage = $source('admin/_pengajian_jadwal.php');
foreach (['Pencarian', 'Detail', 'Tambah jadwal', 'Ubah', 'Nonaktifkan', 'Arsipkan'] as $feature) {
    $assert(str_contains($schedulePage, $feature), 'UI jadwal menyediakan ' . $feature);
}
$assert(!preg_match('/DELETE\s+FROM\s+jadwal_ngaji/i', $schedulePage), 'UI jadwal tidak menghapus jadwal permanen');
$meetingPage = $source('admin/_pengajian_pertemuan.php');
$assert(
    str_contains($meetingPage, 'Simpan draf')
    && str_contains($meetingPage, 'Buka &amp; bekukan peserta')
    && str_contains($meetingPage, 'Selesaikan pertemuan'),
    'UI pertemuan mendukung seluruh perubahan status'
);
$assert(
    str_contains($source('admin/admin_pengajian.php'), 'Csrf::requireValid')
    && str_contains($meetingPage, 'ah_csrf()')
    && str_contains($schedulePage, 'ah_csrf()'),
    'Form jadwal dan pertemuan dilindungi CSRF'
);
// Navigasi jadwal <-> pertemuan tidak boleh memutus konteks.
$assert(
    str_contains($schedulePage, "'tab' => 'pertemuan'") && str_contains($meetingPage, "'tab' => 'jadwal'"),
    'Jadwal dan pertemuan saling tertaut dengan konteks terbawa'
);

require_once $root . '/app/Schedule/LegacyTimeParser.php';
use App\Schedule\LegacyTimeParser;
$parsed = LegacyTimeParser::parse('05.00 - 06.00 WIB');
$assert($parsed['success'] && $parsed['start'] === '05:00:00' && $parsed['end'] === '06:00:00', 'Format jam lama pada dump dapat diparsing tanpa mengubah sumber');
$assert(!LegacyTimeParser::parse('Ba\'da Shubuh')['success'], 'Format ambigu ditolak parser dan harus dilaporkan');
$assert(!LegacyTimeParser::parse('22.00 - 05.00 WIB')['success'], 'Rentang lintas tengah malam tidak ditebak oleh parser');

exit($failures === [] ? 0 : 1);
