<?php

declare(strict_types=1);

/**
 * Uji statis Fase 1 perbaikan jadwal kelas gabungan (migrasi 034).
 *
 *   php tests/jadwal_kelas_gabungan_f1_static.php
 *
 *   S-1 lint seluruh berkas PHP yang dibuat/diubah
 *   S-2 migrasi 034 aditif: hanya CREATE TABLE IF NOT EXISTS + INSERT ke tabel baru
 *   S-3 rollback 034 berpenjaga dan hanya melepas dua tabel baru
 *   S-4 saringan kelas tidak lagi membandingkan `j.id_kelas = ?` di repositori jadwal/laporan
 *   S-5 pembukaan web dan API memakai satu implementasi (PesertaPertemuan)
 *   S-6 kontrak API: `class` lama dipertahankan, `classes` aditif
 *   S-7 formulir admin: kelas utama + kelas gabungan
 *   S-8 batas berkas: tanpa perubahan auth, migrasi lain, atau pembukaan otomatis
 */

$root = dirname(__DIR__);
$lulus = 0;
$gagal = [];
$assert = static function (bool $ok, string $pesan) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    $ok ? $lulus++ : $gagal[] = $pesan;
};
$baca = static fn (string $f): string => (string) file_get_contents($root . '/' . $f);

$berkasPhp = [
    'app/Schedule/KelasJadwal.php', 'app/Schedule/PesertaPertemuan.php', 'app/Schedule/ScheduleRepository.php', 'app/Schedule/ScheduleService.php',
    'app/Api/TeacherRepository.php', 'app/Api/TeacherService.php', 'app/Report/ReportRepository.php', 'app/Report/ReportService.php',
    'app/Beranda/BerandaService.php', 'admin/admin_pengajian.php', 'admin/_pengajian_jadwal.php', 'admin/_pengajian_pertemuan.php',
    'admin/laporan_absensi_detail.php', 'jadwal_ngaji.php',
    'tests/jadwal_kelas_gabungan_f1_seed.php', 'tests/jadwal_kelas_gabungan_f1_worker.php', 'tests/jadwal_kelas_gabungan_f1_integration.php',
    'tests/jadwal_kelas_gabungan_f1_migration.php', 'tests/jadwal_kelas_gabungan_f1_http.php', 'tests/jadwal_kelas_gabungan_f1_static.php',
    'tests/jadwal_kelas_gabungan_f1_audit_probe.php',
];
echo "=== S-1 lint ===\n";
foreach ($berkasPhp as $f) {
    exec('php -l ' . escapeshellarg($root . '/' . $f) . ' 2>&1', $out, $kode);
    $assert($kode === 0, "lint $f");
}

echo "\n=== S-2 migrasi aditif ===\n";
$mig = $baca('database/migrations/034_jadwal_kelas_gabungan_fase1.sql');
$sql = preg_replace('/^\s*--.*$/m', '', $mig) ?? '';
$tanpaFk = preg_replace('/ON (DELETE|UPDATE) (SET NULL|CASCADE|RESTRICT|CURRENT_TIMESTAMP)/i', '', $sql) ?? '';
$assert(!preg_match('/\b(ALTER|UPDATE|DELETE|TRUNCATE|DROP|RENAME)\b/i', $tanpaFk), 'Migrasi 034 tanpa ALTER/UPDATE/DELETE/TRUNCATE/DROP/RENAME');
$assert(preg_match_all('/CREATE TABLE IF NOT EXISTS (\w+)/', $sql, $m) === 2 && $m[1] === ['jadwal_kelas', 'pertemuan_kelas'], 'Migrasi 034 hanya membuat jadwal_kelas dan pertemuan_kelas (dapat diulang)');
preg_match_all('/INSERT INTO (\w+)/', $sql, $ins);
$assert($ins[1] !== [] && array_diff($ins[1], ['jadwal_kelas', 'pertemuan_kelas']) === [], 'Isian data lama hanya ke tabel baru');
$assert(substr_count($sql, 'WHERE NOT EXISTS') + substr_count($sql, 'AND NOT EXISTS (SELECT 1 FROM pertemuan_kelas') >= 3, 'Setiap isian dijaga NOT EXISTS (aman dijalankan ulang)');
$file = array_map('basename', glob($root . '/database/migrations/*.sql') ?: []);
sort($file);
$assert(end($file) === '034_jadwal_kelas_gabungan_fase1.sql', '034 adalah migrasi terakhir');
$assert(is_file($root . '/database/rollbacks/034_jadwal_kelas_gabungan_fase1.sql'), 'Rollback berpasangan tersedia');

echo "\n=== S-3 rollback berpenjaga ===\n";
$rb = preg_replace('/^\s*--.*$/m', '', $baca('database/rollbacks/034_jadwal_kelas_gabungan_fase1.sql')) ?? '';
$assert(str_contains($rb, 'CHECK (jumlah = 0)') && str_contains($rb, 'is_utama = 0') && str_contains($rb, 'HAVING COUNT(*) > 1'), 'Rollback ditolak bila ada kelas gabungan atau pertemuan berkelas ganda');
preg_match_all('/DROP TABLE IF EXISTS (\w+)/', $rb, $drop);
$assert($drop[1] === ['pertemuan_kelas', 'jadwal_kelas'] && !preg_match('/\b(ALTER|UPDATE|DELETE|TRUNCATE)\b/i', $rb), 'Rollback hanya melepas dua tabel baru');
$assert(strpos($rb, 'CHECK (jumlah = 0)') < strpos($rb, 'DROP TABLE IF EXISTS pertemuan_kelas'), 'Penjaga dijalankan sebelum DROP');

echo "\n=== S-4 saringan kelas ===\n";
foreach (['app/Schedule/ScheduleRepository.php', 'app/Report/ReportRepository.php', 'app/Api/TeacherRepository.php'] as $f) {
    $isi = $baca($f);
    $assert(!preg_match("/'j\\.id_kelas'\\s*=>|j\\.id_kelas = \\?/", $isi), "$f tidak menyaring kelas lewat j.id_kelas saja");
}
$assert(str_contains($baca('app/Report/ReportRepository.php'), "'pp.kelas_id_snapshot = ?'") && str_contains($baca('app/Report/ReportRepository.php'), 'KelasJadwal::pertemuanMencakup'),
    'Laporan: santri disaring menurut kelas asal, guru menurut kelas pertemuan');
$assert(str_contains($baca('jadwal_ngaji.php'), 'KelasJadwal::jadwalMencakup') && !str_contains($baca('jadwal_ngaji.php'), "AND j.id_kelas = ?"), 'Halaman publik menyaring lewat seluruh kelas jadwal');

echo "\n=== S-5 satu implementasi pembukaan ===\n";
foreach (['app/Schedule/ScheduleRepository.php', 'app/Api/TeacherRepository.php'] as $f) {
    $isi = $baca($f);
    $assert(str_contains($isi, 'PesertaPertemuan::bekukan(') && !str_contains($isi, 'INSERT INTO pertemuan_peserta'), "$f memakai PesertaPertemuan, tanpa INSERT peserta sendiri");
}
$pp = $baca('app/Schedule/PesertaPertemuan.php');
$assert(str_contains($pp, 'SELECT MIN(pk2.id)') && str_contains($pp, "pk.status = 'Aktif'") && str_contains($pp, 'pk.id_kelas, ?, NOW()'), 'Peserta: satu baris per santri, keanggotaan aktif, kelas asal disimpan');
$svc = $baca('app/Schedule/ScheduleService.php');
$assert(str_contains($svc, 'scheduleFind($id, true)') && str_contains($svc, 'syncScheduleClasses'), 'Simpan jadwal mengunci baris jadwal sebelum menyamakan kelas');

echo "\n=== S-6 kontrak API aditif ===\n";
$ts = $baca('app/Api/TeacherService.php');
$assert(substr_count($ts, "'class' => ['id' => (int) \$row['id_kelas'], 'name' => (string) \$row['nama_kelas']") === 1
    && substr_count($ts, "'class' => ['id' => (int) \$meeting['id_kelas'], 'name' => (string) \$meeting['nama_kelas']") === 1 && substr_count($ts, "'classes' =>") >= 3 && str_contains($ts, "'class_name' => (string) \$row['nama_kelas']"),
    'Jadwal/pertemuan API: `class` dan `class_name` lama dipertahankan; `classes` ditambahkan');
$assert(str_contains($ts, "// Aditif: kelas asal santri pada pertemuan"), 'Santri di API mendapat kelas asal (aditif)');
$rs = $baca('app/Report/ReportService.php');
$assert(str_contains($rs, "'classes' => \$this->repository->meetingClasses(") && str_contains($rs, "'class' => ['id' => (int) \$meeting['id_kelas']"), 'Laporan pertemuan: class lama + classes aditif');

echo "\n=== S-7 formulir admin ===\n";
$form = $baca('admin/_pengajian_jadwal.php');
$assert(str_contains($form, 'Kelas utama') && str_contains($form, 'name="kelas_gabungan[]"') && str_contains($form, 'name="id_kelas"'), 'Formulir: kelas utama (id_kelas) + kotak centang kelas gabungan');
$assert(str_contains($form, 'array_intersect($idKelasJadwal($x), $idKelasJadwal($y))'), 'Penanda bentrok tampilan membandingkan himpunan kelas');

echo "\n=== S-8 batas berkas ===\n";
exec('git -C ' . escapeshellarg($root) . ' diff --name-only 22a3645 2>/dev/null', $diff, $kodeGit);
exec('git -C ' . escapeshellarg($root) . ' ls-files --others --exclude-standard 2>/dev/null', $baru);
$semua = array_values(array_unique(array_merge($diff, $baru)));
$milikLain = ['docs/panduan-pengguna-v4/', 'docs/presentasi-al-hasan-v4/', 'output/', 'tests/browser/tangkapan-penugasan-keamanan-f1/'];
$semua = array_values(array_filter($semua, static function (string $f) use ($milikLain): bool {
    foreach ($milikLain as $p) {
        if (str_starts_with($f, $p)) {
            return false;
        }
    }
    return true;
}));
$diizinkan = static fn (string $f): bool => in_array($f, $GLOBALS['jkgBerkas'], true)
    || str_starts_with($f, 'docs/jadwal-kelas-gabungan/') || $f === 'AGENTS.md';
$GLOBALS['jkgBerkas'] = array_merge($berkasPhp, ['database/migrations/034_jadwal_kelas_gabungan_fase1.sql', 'database/rollbacks/034_jadwal_kelas_gabungan_fase1.sql', 'tests/jadwal_kelas_gabungan_f1_db.sh']);
$luar = array_values(array_filter($semua, static fn (string $f): bool => !$diizinkan($f)));
$assert($kodeGit === 0 && $luar === [], 'Perubahan hanya pada berkas Fase 1 (' . ($luar === [] ? 'ok' : implode(', ', $luar)) . ')');
$assert(!array_filter($semua, static fn (string $f): bool => str_starts_with($f, 'app/Auth/') || str_starts_with($f, 'api/')), 'Tanpa perubahan app/Auth atau router API');
$assert(!preg_match('/cron|otomatis membuka|auto.?open/i', $pp . $svc), 'Tidak ada pembukaan pertemuan otomatis');

echo "\nHasil: " . $lulus . ' lulus, ' . count($gagal) . " gagal\n";
foreach ($gagal as $pesan) {
    echo '  - ' . $pesan . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
