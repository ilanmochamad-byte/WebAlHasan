<?php

declare(strict_types=1);

/**
 * Uji statis Fase 1 penugasan lintas guru/pengurus (tanpa basis data).
 *
 *   php tests/penugasan_keamanan_f1_static.php
 *
 *   S-1 migrasi 032: aditif, idempoten, hanya kolom/unique/FK pada pengurus
 *   S-2 rollback 032: penjaga data sebelum perubahan skema
 *   S-3 katalog: Murobi/Guru Mapel tetap guru; jenis lain berbasis pengurus; tidak ada jenis/role baru
 *   S-4 layanan: identitas dibentuk di transaksi, kunci guru lebih dulu, audit, schema-guard
 *   S-5 web: tanpa SQL di halaman, POST + CSRF, escape, identitas tidak diubah dari Data Pengurus
 *   S-6 batas Fase 1 terhadap baseline (berkas yang boleh berubah)
 *   S-7 lint PHP seluruh berkas baru/berubah
 */

$root = dirname(__DIR__);
$baseline = getenv('PKF1_BASELINE') ?: '8917295';
$lulus = 0;
$gagal = [];
$assert = static function (bool $ok, string $pesan) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    $ok ? $lulus++ : $gagal[] = $pesan;
};
$baca = static fn (string $p): string => (string) file_get_contents($root . '/' . $p);
$tanpaKomentar = static fn (string $sql): string => (string) preg_replace('/^\s*--.*$/m', '', $sql);
$nama = '032_penugasan_keamanan_fase1_identitas_guru.sql';

// ===================================================================== S-1
echo PHP_EOL . '=== S-1. Migrasi 032 ===' . PHP_EOL;
$migrasi = glob($root . '/database/migrations/*.sql');
sort($migrasi);
$assert(basename((string) end($migrasi)) === $nama && is_file($root . '/database/rollbacks/' . $nama), '032 adalah migrasi terakhir dan memiliki pasangan rollback');
$mig = $tanpaKomentar($baca('database/migrations/' . $nama));
$assert(!preg_match('/\b(DROP|TRUNCATE|INSERT|RENAME|DELETE|UPDATE)\b/i', $mig), 'Migrasi tanpa DROP/DELETE/TRUNCATE/UPDATE/INSERT/RENAME: baris lama tidak diubah');
preg_match_all('/ALTER TABLE\s+(\w+)/i', $mig, $m);
$assert(array_values(array_unique($m[1])) === ['pengurus'], 'ALTER hanya pada tabel pengurus');
$assert(substr_count($mig, 'SET @sql := IF(') === 3 && substr_count($mig, 'PREPARE stmt FROM @sql') === 3, 'Tiga langkah bersyarat information_schema (kolom, unique, FK): dapat dijalankan ulang');
$assert(str_contains($mig, 'ADD COLUMN guru_id INT NULL DEFAULT NULL') && str_contains($mig, 'ADD UNIQUE KEY pengurus_guru_unique (guru_id)')
    && str_contains($mig, 'FOREIGN KEY (guru_id) REFERENCES guru (id)'), 'Kolom INT NULL, unique per guru, dan FK ke guru(id)');
$assert(!preg_match('/ON DELETE|ON UPDATE/i', $mig), 'FK tanpa CASCADE/SET NULL: menghapus guru yang punya identitas ditolak basis data');

// ===================================================================== S-2
echo PHP_EOL . '=== S-2. Rollback 032 ===' . PHP_EOL;
$rb = $tanpaKomentar($baca('database/rollbacks/' . $nama));
$posJaga = strpos($rb, 'penugasan_fase1_rollback_guard_kosong CHECK (jumlah = 0)');
$posAlter = strpos($rb, 'ALTER TABLE');
$assert($posJaga !== false && $posAlter !== false && $posJaga < $posAlter, 'Penjaga data CHECK dipasang sebelum perubahan skema');
$assert(str_contains($rb, 'SELECT COUNT(*) FROM pengurus WHERE guru_id IS NOT NULL'), 'Penjaga menghitung identitas pengurus milik guru');
$assert(!preg_match('/\b(DELETE|UPDATE|TRUNCATE)\b/i', $rb) && !preg_match('/DROP TABLE(?! *IF EXISTS)|DROP TABLE IF EXISTS (?!penugasan_fase1_rollback_guard)/i', str_replace('DROP TEMPORARY TABLE', '', $rb)),
    'Rollback tidak menghapus baris maupun tabel permanen');
$assert(strpos($rb, 'DROP FOREIGN KEY pengurus_guru_fk') < strpos($rb, 'DROP INDEX pengurus_guru_unique') && strpos($rb, 'DROP INDEX pengurus_guru_unique') < strpos($rb, 'DROP COLUMN guru_id'),
    'Urutan pelepasan: FK → unique → kolom');

// ===================================================================== S-3
echo PHP_EOL . '=== S-3. Katalog penugasan ===' . PHP_EOL;
require_once $root . '/app/Penugasan/PenugasanJenis.php';
use App\Penugasan\PenugasanJenis;

$assert(PenugasanJenis::definisi(PenugasanJenis::MUROBI)['subjek'] === 'guru' && PenugasanJenis::definisi(PenugasanJenis::GURU_MAPEL)['subjek'] === 'guru'
    && PenugasanJenis::definisi(PenugasanJenis::MUROBI)['role_dasar'] === 'guru' && PenugasanJenis::definisi(PenugasanJenis::GURU_MAPEL)['role_dasar'] === 'guru',
    'PK-2: Murobi dan Guru Mapel tetap berbasis guru dengan role dasar guru');
$pengurus = [PenugasanJenis::PEMBIMBING, PenugasanJenis::PENDIDIKAN, PenugasanJenis::KESEHATAN, PenugasanJenis::BENDAHARA_BULANAN, PenugasanJenis::PANITIA_PSB, PenugasanJenis::BENDAHARA_PSB];
$ok = true;
foreach ($pengurus as $j) {
    $d = PenugasanJenis::definisi($j);
    $ok = $ok && $d['subjek'] === 'pengurus' && $d['subjek_kolom'] === 'pengurus_id' && $d['role_dasar'] === 'pengurus';
}
$assert($ok, 'PK-1: enam jenis lain tetap merujuk pengurus_id dengan role dasar pengurus (modul V2–V7 tidak berubah)');
$assert(count(PenugasanJenis::ALL) === 8 && !in_array('keamanan', PenugasanJenis::ALL, true), 'Fase 1 tidak menambah jenis penugasan (Keamanan adalah Fase 3)');
$assert(str_contains(PenugasanJenis::penjelasan(PenugasanJenis::PEMBIMBING), 'Pembimbing adalah pengurus atau guru'), 'Penjelasan pembimbing menyebut pengurus atau guru');

// ===================================================================== S-4
echo PHP_EOL . '=== S-4. Layanan ===' . PHP_EOL;
$svc = $baca('app/Penugasan/PenugasanService.php');
$repo = $baca('app/Penugasan/PenugasanRepository.php');
$caps = $baca('app/Auth/Capabilities.php');
$posTx = strpos($svc, '$this->repository->transaction(function () use ($jenis, $definisi, $data, $actorId): int {');
$posPastikan = strpos($svc, '$data[\'subjek_id\'] = $this->pastikanIdentitasGuru(');
$posKunciSubjek = strpos($svc, '$this->repository->lockSubjectMaster($jenis, $data[\'subjek_id\']);');
$assert($posTx !== false && $posTx < $posPastikan && $posPastikan < $posKunciSubjek, 'Identitas dibentuk di dalam transaksi pembuatan, sebelum kunci subjek');
preg_match('/private function pastikanIdentitasGuru\(.*?\n    }\n/s', $svc, $f);
$fungsi = $f[0] ?? '';
$assert(strpos($fungsi, 'kunciGuru(') < strpos($fungsi, 'kunciIdentitasGuru(') && strpos($fungsi, 'kunciIdentitasGuru(') < strpos($fungsi, 'kunciAkunGuru('),
    'Urutan kunci: guru → identitas → akun');
$assert(str_contains($fungsi, "'penugasan.identitas_guru.buat'") && str_contains($fungsi, "'account_identitas_guru_linked'") && str_contains($fungsi, 'auditRequired('),
    'Pembentukan identitas dan penghubungan akun diaudit wajib (gagal audit = batal)');
$assert(str_contains($repo, "FROM guru WHERE id = ? FOR UPDATE") && str_contains($repo, 'FROM pengurus WHERE guru_id = ? FOR UPDATE') && str_contains($repo, 'FROM users u WHERE u.guru_id = ? ORDER BY u.id LIMIT 1 FOR UPDATE'),
    'Guru, identitas, dan akun dibaca dengan kunci baris');
$assert(str_contains($svc, "if (\$lama === null && \$definisi['subjek'] === 'pengurus')"), 'Jalur guru hanya pada pembuatan jenis berbasis pengurus; pengubahan tidak mengganti subjek');
$assert(str_contains($caps, "Schema::hasColumn(\$this->db, 'pengurus', 'guru_id')") && substr_count($caps, 'syaratIdentitasGuru(') === 3,
    'Capabilities: syarat identitas dipakai untuk akun pengurus dan cakupan penugasan, dijaga keberadaan skema');
$assert(str_contains($caps, 'guru_id = u.guru_id') && str_contains($caps, 'gi.is_active = 1 AND gi.archived_at IS NULL'), 'Capabilities: identitas hanya untuk akun guru pemiliknya dan selama guru aktif');
foreach (['app/Account/AccountRepository.php', 'app/Account/PerizinanAccountRepository.php', 'app/MasterData/MasterDataRepository.php', 'app/Penugasan/PenugasanRepository.php'] as $berkas) {
    $isi = $baca($berkas);
    $assert(!str_contains($isi, 'guru_id') || str_contains($isi, "Schema::hasColumn(\$this->db, 'pengurus', 'guru_id')") || str_contains($isi, 'identitasGuruTersedia'),
        "$berkas: rujukan pengurus.guru_id dijaga keberadaan skema");
}
$master = $baca('app/MasterData/MasterDataService.php');
$assert(substr_count($master, '$this->sinkronIdentitasGuru($id);') === 2 && substr_count($master, '$this->repository->transaction(function ()') >= 2,
    'Data Guru: simpan dan ubah status menyinkronkan identitas di transaksi yang sama');
$assert(substr_count($master, '$this->tolakIdentitasGuru(') === 2, 'Data Pengurus: simpan dan ubah status menolak identitas milik guru');
$perizinan = $baca('app/Account/PerizinanAccountService.php');
$assert(str_contains($perizinan, "Akun guru tidak dapat dihubungkan sebagai pengurus atau orang tua."), 'Penghubungan manual akun guru ke pengurus biasa tetap ditolak');

// ===================================================================== S-5
echo PHP_EOL . '=== S-5. Halaman web ===' . PHP_EOL;
foreach (['admin/admin_penugasan.php', 'admin/admin_pengurus.php', 'admin/admin_guru.php'] as $berkas) {
    $isi = $baca($berkas);
    $assert(!preg_match('/\b(SELECT|INSERT INTO|UPDATE \w+ SET|DELETE FROM)\b/', $isi) && !str_contains($isi, 'app_db()'), "$berkas tanpa SQL langsung");
}
$halaman = $baca('admin/admin_penugasan.php');
$assert(str_contains($halaman, '<optgroup label="Guru">') && str_contains($halaman, "\$nilaiGuru = 'guru:' . (int) \$g['id'];") && str_contains($halaman, 'ah_e($nilaiGuru)')
    && str_contains($halaman, "ah_e(\$g['nama']"), 'Kelompok Guru: nilai guru:ID dari integer, label di-escape');
$assert(str_contains($halaman, "\$bisaGuru = !\$modeUbah && \$opsi['subjek_guru'] !== [];"), 'Kelompok Guru hanya pada formulir tambah');
$assert(str_contains($halaman, "!empty(\$g['nama_sama_pengurus'])") && str_contains($halaman, 'id="subjek-nama-kembar"')
    && str_contains($halaman, "ah_e(implode(', ', array_map("), 'Peringatan nama sama: hanya penanda dan catatan, nama di-escape');
$layanan = $baca('app/Penugasan/PenugasanService.php');
$assert(str_contains($layanan, "\$opsi['subjek_guru'][\$i]['nama_sama_pengurus']") && str_contains($layanan, 'private static function namaPembanding('),
    'Penanda nama sama dihitung layanan dari pengurus biasa aktif');
$pengurusHal = $baca('admin/admin_pengurus.php');
$assert(str_contains($pengurusHal, "\$milikGuru(\$row) > 0 ? ah_menu([") && str_contains($pengurusHal, 'Buka data guru'), 'Daftar pengurus: identitas guru tanpa menu ubah/status');

// ===================================================================== S-6
echo PHP_EOL . '=== S-6. Batas Fase 1 ===' . PHP_EOL;
exec('cd ' . escapeshellarg($root) . ' && git diff --name-only ' . escapeshellarg($baseline) . ' -- . 2>/dev/null', $berubah, $kode);
exec('cd ' . escapeshellarg($root) . ' && git ls-files --others --exclude-standard 2>/dev/null', $baru);
$semua = array_values(array_unique(array_merge($berubah, $baru)));
$boleh = static fn (string $f): bool => in_array($f, [
    'admin/admin_guru.php', 'admin/admin_pengurus.php', 'admin/admin_penugasan.php',
    'app/Account/AccountRepository.php', 'app/Account/AccountService.php', 'app/Account/PerizinanAccountRepository.php',
    'app/Auth/Capabilities.php', 'app/MasterData/MasterDataRepository.php', 'app/MasterData/MasterDataService.php',
    'app/Penugasan/PenugasanJenis.php', 'app/Penugasan/PenugasanRepository.php', 'app/Penugasan/PenugasanService.php',
    'database/migrations/' . $nama, 'database/rollbacks/' . $nama, 'AGENTS.md',
], true) || str_starts_with($f, 'tests/penugasan_keamanan_f1') || str_starts_with($f, 'tests/browser/uji-penugasan-keamanan-f1') || str_starts_with($f, 'tests/browser/tangkapan-penugasan-keamanan-f1/')
    || str_starts_with($f, 'docs/penugasan-keamanan/') || str_starts_with($f, 'docs/panduan-pengguna-v4/') || str_starts_with($f, 'docs/presentasi-al-hasan-v4/') || str_starts_with($f, 'output/');
$liar = array_values(array_filter($semua, static fn (string $f): bool => !$boleh($f)));
$assert($kode === 0 && $liar === [], 'Hanya berkas Fase 1 yang berubah terhadap baseline ' . $baseline . ($liar === [] ? '' : ': ' . implode(', ', $liar)));
$assert(array_filter($semua, static fn (string $f): bool => (bool) preg_match('#^(api/|app/(Izin|V3|V4|Kesehatan|Bsa|Notification|Api)/|portal/)#', $f)) === [],
    'Modul izin, pembinaan, rapor, kesehatan, BSA, notifikasi, API, dan portal tidak diubah');

// ===================================================================== S-7
echo PHP_EOL . '=== S-7. Lint ===' . PHP_EOL;
foreach (array_filter($semua, static fn (string $f): bool => str_ends_with($f, '.php') && is_file($root . '/' . $f) && !str_starts_with($f, 'docs/') && !str_starts_with($f, 'output/')) as $berkas) {
    exec('php -l ' . escapeshellarg($root . '/' . $berkas) . ' 2>&1', $keluaran, $hasil);
    $assert($hasil === 0, "lint $berkas");
}

echo PHP_EOL . 'Hasil: ' . $lulus . ' lulus, ' . count($gagal) . ' gagal.' . PHP_EOL;
exit($gagal === [] ? 0 : 1);
