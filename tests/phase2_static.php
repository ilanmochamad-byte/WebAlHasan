<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? '[lulus] ' : '[gagal] ') . $message . PHP_EOL;
    if (!$condition) { $failures[] = $message; }
};
$source = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

$migration = $source('database/migrations/002_phase2_master_data.sql');
$rollback = $source('database/rollbacks/002_phase2_master_data.sql');
foreach (['ALTER TABLE guru', 'ALTER TABLE santri', 'CREATE TABLE wali', 'CREATE TABLE santri_wali', 'CREATE TABLE pengurus', 'CREATE TABLE murobi_assignments', 'active_guard', 'plotting_kelas_one_active_unique', 'guru_nip_unique'] as $required) {
    $assert(str_contains($migration, $required), 'Migrasi Fase 2 memuat ' . $required);
}
$assert(!preg_match('/\bDROP\s+(TABLE|COLUMN)\b/i', $migration), 'Migrasi naik tidak menghapus tabel atau kolom lama');
$assert(str_contains($migration, 'legacy_santri_id') && str_contains($migration, 'nama_ayah') && str_contains($migration, 'nama_ibu'), 'Migrasi wali memiliki jejak sumber dan mempertahankan kolom ayah/ibu');
$assert(str_contains($rollback, 'Rollback Fase 2 hanya untuk staging'), 'Rollback berpasangan memperingatkan agar tidak dijalankan sembarang di produksi');

$repository = $source('app/MasterData/MasterDataRepository.php');
$service = $source('app/MasterData/MasterDataService.php');
$assert(str_contains($repository, 'prepare($sql)') && str_contains($repository, 'bind_param($types'), 'Akses data baru memakai prepared statement dengan tipe parameter');
$assert(str_contains($service, "'master.create'") && str_contains($service, "'master.update'") && str_contains($service, "'master.archive'"), 'Create, update, dan arsip dicatat ke audit');
$assert(str_contains($service, 'begin_transaction()') && str_contains($service, 'rollback()'), 'Perubahan relasi penting memakai transaksi');
$assert(str_contains($service, 'endActiveClass') && str_contains($repository, "status = 'Selesai'"), 'Perubahan kelas menutup keanggotaan lama tanpa menghapus riwayat');

foreach (['admin/admin_guru.php', 'admin/admin_master_santri.php'] as $page) {
    $php = $source($page);
    foreach (['Pencarian', 'Detail', 'Tambah', 'Ubah', 'Nonaktifkan', 'Arsipkan'] as $feature) {
        $assert(str_contains($php, $feature), basename($page) . ' menyediakan ' . $feature);
    }
    $assert(!preg_match('/DELETE\s+FROM\s+(guru|santri)/i', $php), basename($page) . ' tidak menghapus guru/santri permanen');
}
foreach (['admin/admin_wali.php', 'admin/admin_pengurus.php', 'admin/admin_murobi.php', 'admin/admin_tahun.php', 'admin/admin_kelas.php', 'admin/export_master.php'] as $page) {
    $assert(is_file($root . '/' . $page), $page . ' tersedia');
}
$assert(str_contains($source('admin/admin_wali.php'), 'attachWali') && str_contains($repository, 'waliAttach'), 'UI wali mendukung relasi ke banyak santri');
$assert(str_contains($source('admin/admin_murobi.php'), 'approval izin'), 'UI murobi menjelaskan bahwa approval izin tidak diberikan');
$assert(str_contains($source('admin/export_master.php'), 'fputcsv') && str_contains($source('admin/admin_guru.php'), 'export_master.php') && str_contains($source('admin/admin_master_santri.php'), 'export_master.php'), 'Guru dan santri dapat diekspor CSV sesuai filter');
$assert(str_contains($source('admin/admin_master_santri.php'), 'Impor Format Santri Lama') && str_contains($source('admin/proses_import_santri.php'), 'RINCIAN BARIS GAGAL'), 'Impor lama memvalidasi dan melaporkan baris gagal secara terpisah');
$accountService = $source('app/Account/AccountService.php');
$assert(str_contains($accountService, 'strtolower') && str_contains($accountService, 'FILTER_VALIDATE_EMAIL') && str_contains($accountService, "str_starts_with(\$data['phone'], '+62')"), 'Username, email, dan nomor HP akun dinormalisasi dan divalidasi');
$assert(!str_contains($source('admin/proses_mutasi_alumni.php'), 'DELETE FROM santri'), 'Mutasi alumni mengarsipkan santri dan tidak menghapus data sumber');
$assert(!str_contains($source('admin/admin_santri.php'), 'DELETE FROM plotting_kelas'), 'Modul penempatan lama tidak lagi menghapus riwayat kelas');

require_once $root . '/app/MasterData/Normalizer.php';
use App\MasterData\Normalizer;
$assert(Normalizer::identifier(' ab 12-3 ') === 'AB12-3', 'NIS/NIP dinormalisasi konsisten');
$assert(Normalizer::phone('+62 812-3456-7890') === '081234567890', 'Nomor HP dinormalisasi ke format lokal');
$assert(Normalizer::date('2026-02-29', true) === '' && Normalizer::date('2028-02-29', true) === '2028-02-29', 'Tanggal tidak valid ditolak');

exit($failures === [] ? 0 : 1);
