<?php

declare(strict_types=1);

/**
 * Uji statis status "Izin" otomatis (migrasi 036), tanpa DB:
 *   php tests/izin_otomatis_pengajian_static.php
 */

$root = dirname(__DIR__);
$lulus = 0;
$gagal = [];
$assert = static function (bool $ok, string $pesan) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    $ok ? $lulus++ : $gagal[] = $pesan;
};
$baca = static fn (string $f): string => is_file($root . '/' . $f) ? (string) file_get_contents($root . '/' . $f) : '';

$berkasPhp = [
    'app/Schedule/IzinPeserta.php', 'app/Schedule/PesertaPertemuan.php', 'app/Schedule/ScheduleRepository.php',
    'app/Api/TeacherRepository.php', 'app/Api/TeacherService.php', 'app/Report/ReportRepository.php', 'app/Report/ReportService.php',
    'app/Keamanan/KeamananApiService.php', 'admin/admin_pengajian.php', 'admin/_pengajian_pertemuan.php', 'admin/laporan_absensi_detail.php',
    'tests/izin_otomatis_pengajian_integration.php', 'tests/izin_otomatis_pengajian_migration.php', 'tests/izin_otomatis_pengajian_http.php',
];
foreach ($berkasPhp as $f) {
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($root . '/' . $f) . ' 2>&1', $o, $kode);
    $assert($kode === 0, "Lint $f");
}

$mig = $baca('database/migrations/036_izin_otomatis_pengajian.sql');
$rb = $baca('database/rollbacks/036_izin_otomatis_pengajian.sql');
$migKode = (string) preg_replace('/^--.*$/m', '', $mig);
$assert($mig !== '' && $rb !== '', 'Migrasi 036 dan rollback berpasangan ada');
$assert(str_contains($migKode, 'izin_pengajuan_id BIGINT UNSIGNED NULL') && str_contains($migKode, 'izin_keterangan VARCHAR(160) NULL'), 'Dua kolom NULL sesuai rencana §3.2');
$assert(str_contains($migKode, 'REFERENCES izin_pengajuan (id)'), 'Kunci asing ke izin_pengajuan');
$assert(!preg_match('/\b(UPDATE|DELETE|TRUNCATE|INSERT|DROP)\b/i', $migKode), 'Migrasi aditif: tanpa UPDATE/DELETE/TRUNCATE/INSERT/DROP');
$assert(substr_count($migKode, "information_schema") >= 4, 'Setiap langkah migrasi dijaga information_schema (dapat diulang)');
$assert(!preg_match('/\babsensi_santri\b|ALTER TABLE izin_/', $migKode . preg_replace('/^--.*$/m', '', $rb)), 'Migrasi/rollback tidak menyentuh absensi_santri atau tabel izin_*');

$helper = $baca('app/Schedule/IzinPeserta.php');
$assert(str_contains($helper, "k.hasil = 'Disetujui'") && str_contains($helper, "p.status = 'Disetujui'"), 'Aturan IP-4: status Disetujui + baris keputusan Disetujui');
$assert(str_contains($helper, 'p.tgl_izin <= ? AND p.tgl_kembali >= ?') && str_contains($helper, 'ORDER BY p.id DESC'), 'Aturan IP-2 inklusif; tumpang tindih → ID terbesar');
$assert(str_contains($helper, "'Izin No. ' . \$pengajuanId . ' — disetujui ' . \$pemutus . ' s.d. '"), 'Format keterangan IP-3');
$assert(!preg_match('/p\.alasan|k\.alasan|catatan_|alasan_penggantian|diputus_oleh|nama_pengaju/', $helper), 'Pembantu tidak membaca alasan/catatan/keputusan/pengaju');
$assert(str_contains($helper, 'NOT EXISTS (SELECT 1 FROM absensi_santri a'), 'Hanya peserta belum berabsensi yang diselaraskan');
$assert(!preg_match('/INSERT INTO absensi_santri/i', $helper), 'Pembantu tidak menulis baris absensi');

$pp = $baca('app/Schedule/PesertaPertemuan.php');
$assert(substr_count($pp, 'IzinPeserta::selaraskan($db, $meetingId)') === 3, 'selaraskan dipanggil di bekukan (dua jalur) dan perbarui');
$assert(str_contains($pp, 'if (IzinPeserta::terpasang($db))'), 'Kunci izin_* perbarui hanya sesudah 036 (K-7)');

$ts = $baca('app/Api/TeacherService.php');
$assert(str_contains($ts, "'izin' => IzinPeserta::payload(\$row)") && str_contains($ts, "'izin_suggested'"), 'REST: kunci aditif izin dan izin_suggested/izin_cleared');
$assert(!preg_match("/attendanceStatus[^\\n]*Izin/", $ts) && !str_contains($ts, 'izin_pengajuan_id ='), 'saveAttendance tidak memaksa status Izin');

$view = $baca('admin/_pengajian_pertemuan.php') . $baca('admin/laporan_absensi_detail.php');
$assert(str_contains($view, 'data-izin-usulan') && str_contains($view, 'Sedang izin #'), 'Web: keterangan snapshot + label lama untuk pertemuan tanpa usulan');
$assert(!preg_match('/<select[^>]+name="status|name="students\[/', $view), 'Web tanpa formulir absensi (keputusan R)');

echo "\nHasil: $lulus lulus, " . count($gagal) . " gagal\n";
exit($gagal === [] ? 0 : 1);
