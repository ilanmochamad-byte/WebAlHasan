<?php

declare(strict_types=1);

/**
 * Uji statis redesain website Tahap 6 — data pesantren & penugasan
 * (D-1 s.d. D-10, M-1 s.d. M-3, G-1 · AB, AC, AD, AE, AF).
 *
 *   php tests/redesain_web_tahap6_static.php
 *
 * Tanpa basis data. Memeriksa sumber: lint, migrasi 023 aditif + rollback
 * berpasangan, kode tetap jalan tanpa migrasi (pemeriksaan skema), penjaga
 * profil binaan (AB), aturan asrama di layanan (AE), tanda bukan ganda (AD),
 * impor periksa tanpa simpan (AC), CSRF/POST pada mutasi, tanpa isian ID,
 * tanpa alert()/confirm()/handler inline, dan peta menu tidak berubah.
 */

$root = dirname(__DIR__);
$lulus = 0;
$gagal = 0;
$assert = static function (bool $ok, string $pesan) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    $ok ? $lulus++ : $gagal++;
};
$src = static fn (string $f): string => (string) file_get_contents($root . '/' . $f);

$halaman = ['admin/admin_master_santri.php', 'admin/proses_import_santri.php', 'admin/admin_penempatan_santri.php', 'admin/admin_wali.php', 'admin/admin_wali_rekonsiliasi.php',
    'admin/admin_kelulusan_santri.php', 'admin/admin_alumni.php', 'admin/admin_guru.php', 'admin/admin_pengurus.php', 'admin/admin_kelas.php', 'admin/admin_kamar.php',
    'admin/admin_tahun.php', 'admin/admin_penugasan.php', 'admin/admin_murobi.php', 'admin/admin_pembimbing.php', 'admin/_santri_wali_field.php', 'admin/export_master.php',
    'portal/santri_binaan.php'];
$layanan = ['app/Database/Schema.php', 'app/MasterData/MasterDataRepository.php', 'app/MasterData/MasterDataService.php', 'app/MasterData/PenempatanRepository.php',
    'app/MasterData/PenempatanService.php', 'app/MasterData/ProfilSantriService.php', 'app/Penugasan/PenugasanJenis.php', 'app/bootstrap.php', 'portal/index.php',
    'portal/izin_detail.php', 'portal/v3_pelanggaran_detail.php', 'admin/admin_akun.php', 'tests/redesain_web_tahap6_seed.php', 'tests/redesain_web_tahap6_http.php'];

echo '=== S6-1. Lint ===' . PHP_EOL;
foreach (array_merge($halaman, $layanan) as $f) {
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($root . '/' . $f) . ' 2>&1', $out, $kode);
    $assert($kode === 0, 'php -l ' . $f);
}
foreach (['assets/ui/alhasan.js', 'tests/browser/uji-redesain-web-tahap6.mjs'] as $f) {
    exec('node --check ' . escapeshellarg($root . '/' . $f) . ' 2>&1', $out, $kode);
    $assert($kode === 0, 'node --check ' . $f);
}
exec('bash -n ' . escapeshellarg($root . '/tests/redesain_web_tahap6_db.sh') . ' 2>&1', $out, $kode);
$assert($kode === 0, 'bash -n tests/redesain_web_tahap6_db.sh');

echo PHP_EOL . '=== S6-2. Migrasi 023 dan rollback (AD, AE) ===' . PHP_EOL;
$up = $src('database/migrations/023_redesain_web_tahap6_data_pesantren.sql');
$down = $src('database/rollbacks/023_redesain_web_tahap6_data_pesantren.sql');
$tanpaKomentar = static fn (string $sql): string => (string) preg_replace('/^--.*$/m', '', $sql);
$assert($up !== '' && $down !== '', 'Migrasi dan rollback 023 berpasangan');
$assert(!preg_match('/\b(DROP|DELETE|TRUNCATE|UPDATE)\b/i', $tanpaKomentar($up)), 'Migrasi aditif: tanpa DROP/DELETE/TRUNCATE/UPDATE');
$assert(str_contains($up, "ENUM(''Putra'',''Putri'') NULL DEFAULT NULL") && str_contains($up, 'information_schema.COLUMNS') && str_contains($up, 'CREATE TABLE IF NOT EXISTS wali_bukan_ganda'), 'Kolom asrama NULL (tanpa tebakan) dan tabel penanda; idempoten');
$assert(str_contains($up, 'CHECK (wali_a_id < wali_b_id)') && str_contains($up, 'UNIQUE KEY wali_bukan_ganda_aktif_unique (wali_a_id, wali_b_id, aktif_kunci)')
    && str_contains($up, 'REFERENCES wali (id)') && str_contains($up, 'REFERENCES users (id)'), 'Batasan pasangan terurut, satu tanda aktif, kunci asing');
$assert(str_contains($tanpaKomentar($down), 'DROP TABLE IF EXISTS wali_bukan_ganda') && str_contains($down, 'ALTER TABLE kamar DROP COLUMN asrama') && !preg_match('/\b(DELETE|TRUNCATE)\b/i', $tanpaKomentar($down)), 'Rollback hanya melepas kolom/tabel baru');
$assert(str_contains($down, 'KEHILANGAN DATA YANG DISENGAJA'), 'Rollback mendokumentasikan kehilangan data yang disengaja');
$schema = $src('app/Database/Schema.php');
$repo = $src('app/MasterData/MasterDataRepository.php');
$penRepo = $src('app/MasterData/PenempatanRepository.php');
$assert(str_contains($repo, "Schema::hasColumn(\$this->db, 'kamar', 'asrama')") && str_contains($repo, "Schema::hasTable(\$this->db, 'wali_bukan_ganda')")
    && str_contains($penRepo, "'NULL AS asrama'"), 'Kode memeriksa skema dan tetap jalan tanpa migrasi (asrama = NULL)');

echo PHP_EOL . '=== S6-3. Keputusan AB: profil santri binaan ===' . PHP_EOL;
$binaan = $src('portal/santri_binaan.php');
$profil = $src('app/MasterData/ProfilSantriService.php');
$assert(str_contains($binaan, 'authorization()->requireWebUser()') && str_contains($binaan, '$profil->cakupanBinaan($userId, $jenis, $santriId)') && str_contains($binaan, "Denial::page('Profil santri binaan'"),
    'Halaman D-10: akun masuk + pemeriksaan cakupan server + 403');
$assert(preg_match('/\$cakupan = \$profil->cakupanBinaan.*?\$ringkas = \$profil->ringkasan/s', $binaan) === 1, 'Cakupan diperiksa SEBELUM data santri dibaca');
$assert(str_contains($binaan, 'Peran::PENGURUS => ProfilSantriService::BINAAN_PEMBIMBING') && str_contains($binaan, 'Peran::GURU => ProfilSantriService::BINAAN_MUROBI') && str_contains($binaan, 'default => null'), 'Jenis binaan mengikuti peran aktif; peran lain ditolak');
foreach (["r.slug = '{\$role}'", 'm.is_active = 1 AND m.archived_at IS NULL', "ta.status = 'Aktif' AND ta.archived_at IS NULL", 'a.is_active = 1 AND a.archived_at IS NULL',
    'a.tanggal_mulai <= CURDATE() AND (a.tanggal_selesai IS NULL OR a.tanggal_selesai >= CURDATE())', 's.is_active = 1 AND s.archived_at IS NULL', 'u.id = ? AND u.is_active = 1'] as $syarat) {
    $assert(str_contains($profil, $syarat), 'Cakupan binaan mensyaratkan: ' . $syarat);
}
$assert(!preg_match('/method="post"|ah_form_token|->save|->set[A-Z]/', $binaan), 'Profil binaan hanya-baca (tanpa formulir/mutasi)');
$assert(str_contains($binaan, "v3_pelanggaran_service()->page(\$currentUser") && str_contains($binaan, "v3_konseling_service()->page(\$currentUser"), 'Tab Pembinaan memakai layanan V3 dengan akun sendiri (kerahasiaan tetap)');
$assert(!str_contains($binaan, 'nilai') || !str_contains($binaan, 'rapor_semester'), 'Tanpa tab Nilai & rapor');
$assert(str_contains($src('admin/_guard.php'), "requireWebRole('admin')"), 'Data santri admin tetap di bawah penjaga admin');

echo PHP_EOL . '=== S6-4. Keputusan AE: asrama di layanan penempatan ===' . PHP_EOL;
$pen = $src('app/MasterData/PenempatanService.php');
$assert(str_contains($pen, "(\$jenisKelamin === 'L' && \$asrama === 'Putri') || (\$jenisKelamin === 'P' && \$asrama === 'Putra')"), 'Aturan tidak sesuai: L ke Putri atau P ke Putra; kamar tanpa asrama tidak diperingatkan');
$assert(preg_match('/rencana\(\$aksi, \$ids, \$santri, \$target, \$year, \$options, true\);.*?normalizeAsramaConfirmation/s', $pen) === 1, 'apply() memeriksa konfirmasi dari rencana TERKUNCI, bukan tinjauan');
$assert(str_contains($pen, "(string) (\$options['konfirmasi_asrama'] ?? '') !== '1' || mb_strlen(\$alasan) < 5"), 'Konfirmasi dan alasan ≥ 5 karakter wajib');
$assert(str_contains($pen, "\$sesudah['alasan_asrama']") && str_contains($pen, "'alasan_asrama' =>"), 'Alasan asrama tercatat di audit per santri dan ringkasan massal');
$svc = $src('app/MasterData/MasterDataService.php');
$assert(str_contains($svc, "(\$id === null || array_key_exists('asrama', \$input))") && str_contains($svc, "'Pilih asrama kamar: Putra atau Putri.'"), 'Kamar baru wajib asrama; ubah tanpa isian tidak mengosongkan');

echo PHP_EOL . '=== S6-5. Keputusan AD dan AC ===' . PHP_EOL;
$assert(preg_match('/function markNotDuplicate.*?waliLockPair.*?isDuplicatePair.*?notDuplicateActive\(\$a, \$b, true\).*?audit->log\(\'master\.wali\.bukan_ganda\'/s', $svc) === 1, 'Tanda bukan ganda: kunci pasangan, hanya kandidat, tolak ganda, audit');
$assert(str_contains($svc, "function cancelNotDuplicate") && str_contains($repo, 'UPDATE wali_bukan_ganda SET dibatalkan_pada = NOW()') && !str_contains($repo, 'DELETE FROM wali_bukan_ganda'), 'Pembatalan tanda tidak menghapus baris');
$assert(str_contains($repo, 'x.dibatalkan_pada IS NULL') && str_contains($repo, 'function duplicatePairsSql'), 'Pasangan bertanda aktif tidak muncul sebagai kemungkinan ganda');
preg_match('/public function periksaImpor.*?\n    }\n/s', $svc, $periksa);
$assert(($periksa[0] ?? '') !== '' && !preg_match('/->save|Create\(|Update\(|INSERT|begin_transaction/', $periksa[0]) && str_contains($periksa[0], 'existingNis') && str_contains($periksa[0], 'ganda di dalam berkas'), 'periksaImpor tidak menyimpan; memeriksa NIS terdaftar dan ganda dalam berkas');
$assert(preg_match('/public function imporSantri.*?\$this->periksaImpor\(\$rows\).*?saveSantri/s', $svc) === 1, 'Impor memeriksa ulang setiap baris sebelum menyimpan');
$assert(str_contains($svc, 'private function santriData') && str_contains($svc, 'private function santriErrors') && preg_match('/function saveSantri.*?santriData\(\$input, \$current\).*?santriErrors\(\$data\)/s', $svc) === 1, 'Formulir santri dan impor memakai aturan validasi yang sama');
$imp = $src('admin/proses_import_santri.php');
$assert(str_contains($imp, "require_once __DIR__ . '/_guard.php'") && str_contains($imp, 'admin_require_post()') && str_contains($imp, "unset(\$baris['input'])"), 'Impor: guard admin + CSRF, GET → 405, jawaban tanpa isi mentah');

echo PHP_EOL . '=== S6-6. Layar: POST/CSRF, tanpa ID, tanpa dialog bawaan ===' . PHP_EOL;
foreach ($halaman as $f) {
    $s = $src($f);
    // Tampilan GET halaman murobi/pembimbing lama tidak lagi dirender (dialihkan).
    if (!in_array($f, ['admin/admin_murobi.php', 'admin/admin_pembimbing.php'], true)) {
        $assert(!preg_match('/\balert\(|\bconfirm\(|\bonclick=|\bonsubmit=|\bonchange=/', $s), $f . ': tanpa alert()/confirm()/handler inline');
    }
    $assert(preg_match('/<input[^>]*type="(number|text)"[^>]*name="[a-z_]+_id"/', $s) === 0 && !str_contains($s, 'label" for="santri_id_hubung">ID santri'), $f . ': tanpa isian ID yang diketik');
    $assert(!preg_match('/(heading|title)\'\s*=>\s*\'[^\']*(V2|V3|V4|Master Data)/', $s), $f . ': judul tanpa label versi/istilah lama');
    if (preg_match('/method="post"/i', $s) && $f !== 'admin/_santri_wali_field.php') {
        $assert(str_contains($s, 'csrf()') || str_contains($s, 'ah_csrf') || str_contains($s, 'master_csrf'), $f . ': formulir POST ber-CSRF');
    }
}
$assert(str_contains($src('admin/admin_murobi.php'), "admin_penugasan.php?jenis=murobi'), true, 301") && str_contains($src('admin/admin_pembimbing.php'), "admin_penugasan.php?jenis=pembimbing'), true, 301"), 'Halaman Murobi/Pembimbing lama dialihkan (GET), POST tetap');
$assert(!str_contains($src('admin/admin_kelas.php'), 'Tempatkan Santri pada Semester Aktif'), 'Kartu "Tempatkan santri" ganda di Kelas dihapus');
$assert(!str_contains($src('admin/_santri_wali_field.php'), 'name="konfirmasi_timpa'), 'D-3: konfirmasi penimpaan tidak lagi kotak centang di formulir');

echo PHP_EOL . '=== S6-7. Keputusan AF dan peta menu ===' . PHP_EOL;
$jenis = $src('app/Penugasan/PenugasanJenis.php');
$assert(str_contains($jenis, 'public const MENDATANG = [self::BENDAHARA_BULANAN, self::PANITIA_PSB, self::BENDAHARA_PSB]') && str_contains($jenis, 'belum memberi akses ke halaman apa pun'), 'Jenis modul mendatang dipisah dengan keterangan');
$assert(str_contains($src('admin/admin_penugasan.php'), 'Disiapkan untuk modul mendatang'), 'G-1 memuat kelompok "Disiapkan untuk modul mendatang"');
$nav = $src('app/Ui/Navigation.php');
$assert(substr_count($nav, "self::item('") >= 17 && str_contains($nav, "'Data pesantren'") && str_contains($nav, "'Asatidz & pengurus'") && str_contains($nav, "'Kelas & kobong'"), 'Peta menu admin tetap (tanpa menu baru Tahap 6)');
$assert(exec('git -C ' . escapeshellarg($root) . ' diff --quiet origin/main -- app/Ui/Navigation.php app/Auth; echo $?') === '0', 'Navigation dan app/Auth tidak diubah Tahap 6');

echo PHP_EOL . 'Ringkasan: ' . $lulus . ' lulus, ' . $gagal . ' gagal' . PHP_EOL;
exit($gagal === 0 ? 0 : 1);
