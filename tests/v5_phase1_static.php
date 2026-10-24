<?php

declare(strict_types=1);

/**
 * Pemeriksaan statis PRD V5 Fase 1 — penugasan kesehatan dan kunjungan dasar.
 *
 * Tidak memerlukan basis data maupun server. Perilaku nyata diuji di
 * `tests/v5_phase1_integration.php`, `tests/v5_phase1_http.php`,
 * `tests/v5_phase1_migration.php`, dan `tests/browser/uji-v5-fase1.mjs`.
 *
 *   S-1  migrasi 024 aditif + rollback berpasangan + nomor berurutan;
 *   S-2  jenis penugasan kesehatan aditif di fondasi pusat, tanpa role baru,
 *        bendahara bulanan tidak disulap, capability perizinan V2 tetap;
 *   S-3  layanan: hak dihitung ulang, admin hanya baca, kunci baca penugasan
 *        di transaksi, idempotensi per akun, audit metadata tanpa isi klinis;
 *   S-4  web: guard bersama, CSRF untuk POST, no-store, keluaran di-escape,
 *        daftar tanpa kolom klinis, tanpa tombol ubah;
 *   S-5  API: rute /v5/kesehatan memakai layanan yang sama, no-store, kunci
 *        dari header/badan, respons POST tanpa isi klinis;
 *   S-6  privasi: AuditLogger membuang kolom klinis untuk aksi kesehatan.*,
 *        tanpa error_log berisi masukan;
 *   S-7  operasi: preflight, verifier, preflight rollback, skrip DB uji hanya
 *        test_*_test; Fase 2+ belum dimulai (obat/rujukan/koreksi/notifikasi);
 *   S-8  lint seluruh berkas PHP yang diubah/ditambah.
 *
 *   php tests/v5_phase1_static.php
 */

$root = dirname(__DIR__);
$lulus = 0;
$gagal = [];
$assert = static function (bool $ok, string $pesan) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    $ok ? $lulus++ : $gagal[] = $pesan;
};
$src = static fn (string $path): string => (string) @file_get_contents($root . '/' . $path);
$tanpaKomentar = static function (string $php): string {
    $hasil = '';
    foreach (token_get_all($php) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $hasil .= is_array($token) ? $token[1] : $token;
    }

    return $hasil;
};

echo '=== S-1. Migrasi 024 ===' . PHP_EOL;
$migrasi = $src('database/migrations/024_v5_fase1_kesehatan.sql');
$rollback = $src('database/rollbacks/024_v5_fase1_kesehatan.sql');
$kode = (string) preg_replace(['/--.*$/m', '/ON (UPDATE|DELETE) (CURRENT_TIMESTAMP|SET NULL)/i'], '', $migrasi);
$daftarMigrasi = array_map('basename', glob($root . '/database/migrations/*.sql') ?: []);
$assert(end($daftarMigrasi) === '024_v5_fase1_kesehatan.sql' && in_array('023_redesain_web_tahap6_data_pesantren.sql', $daftarMigrasi, true), 'Migrasi 024 berurutan setelah 023 (migrasi terakhir yang ada)');
$assert(substr_count($kode, 'CREATE TABLE IF NOT EXISTS') === 5 && !preg_match('/\b(DROP|DELETE|UPDATE|TRUNCATE|ALTER|RENAME|INSERT)\b/i', $kode), 'Migrasi aditif: 5 CREATE TABLE IF NOT EXISTS, tanpa DROP/ALTER/DML');
foreach (['kesehatan_assignments', 'kesehatan_kunjungan', 'kesehatan_kunjungan_versi', 'kesehatan_idempotensi', 'kesehatan_akses_log'] as $t) {
    $assert(str_contains($kode, 'CREATE TABLE IF NOT EXISTS ' . $t . ' (') && str_contains($rollback, 'DROP TABLE IF EXISTS ' . $t . ';'), "Tabel $t dibuat dan dilepas rollback berpasangan");
}
$assert(substr_count($rollback, 'DROP TABLE IF EXISTS') === 5 && !preg_match('/\b(santri|wali|users|pengurus)\b\s*;/', $rollback) && strpos($rollback, 'kesehatan_akses_log') < strpos($rollback, 'DROP TABLE IF EXISTS kesehatan_assignments'), 'Rollback hanya melepas 5 tabel baru, urut anak → induk');
$assert(str_contains($kode, 'UNIQUE KEY kesehatan_kunjungan_retry (created_by, idempotency_key)') && str_contains($kode, 'UNIQUE KEY kesehatan_idempotensi_unique (user_id, operasi, kunci)'), 'Kunci unik idempotensi pada kunjungan dan klaim');
$assert(str_contains($kode, 'REFERENCES santri (id)') && str_contains($kode, 'REFERENCES kesehatan_assignments (id)') && substr_count($kode, 'FOREIGN KEY') === 18, '18 kunci asing nyata (santri, users, pengurus, tahun ajaran, penugasan)');
$akses = substr($kode, (int) strpos($kode, 'kesehatan_akses_log ('));
$assert(!preg_match('/\b(keluhan|catatan_klinis|tindakan)\b/', substr($akses, 0, (int) strpos($akses, 'ENGINE'))), 'Log akses tanpa kolom isi klinis');
$assert(!str_contains(substr($kode, (int) strpos($kode, 'kesehatan_idempotensi ('), 800), 'response_json'), 'Klaim idempotensi tidak menyimpan salinan respons/isi klinis');

echo PHP_EOL . '=== S-2. Penugasan dan capability ===' . PHP_EOL;
$jenis = $src('app/Penugasan/PenugasanJenis.php');
$caps = $src('app/Auth/Capabilities.php');
$assert(str_contains($jenis, "public const KESEHATAN = 'kesehatan';") && str_contains($jenis, "'tabel' => 'kesehatan_assignments'") && str_contains($jenis, "public const CAP_KESEHATAN_CATAT = 'kesehatan.catat';"), 'Jenis penugasan kesehatan + capability kesehatan.catat ditambahkan');
$def = substr($jenis, (int) strpos($jenis, 'self::KESEHATAN => ['), 900);
$assert(str_contains($def, "'role_dasar' => 'pengurus'") && str_contains($def, "'cakupan' => self::CAKUPAN_SELURUH") && str_contains($def, "'wajib_tahun_aktif' => false"), 'Kesehatan: role dasar pengurus, cakupan seluruh santri, tidak gugur saat semester berganti');
$bulanan = substr($jenis, (int) strpos($jenis, 'self::BENDAHARA_BULANAN => ['), 900);
$assert(str_contains($bulanan, "'tabel' => 'bendahara_bulanan_assignments'") && !str_contains($bulanan, 'kesehatan'), 'Bendahara bulanan lama tidak disulap menjadi petugas kesehatan');
$assert(str_contains($caps, 'public const ALL = [self::ADMIN, self::PENGURUS, self::MUROBI, self::ORANG_TUA];') && !str_contains($caps, 'kesehatan'), 'Capabilities perizinan V2 dan resolver tidak berubah (kesehatan lewat katalog fondasi)');
$assert(!preg_match("/INSERT INTO roles|slug\s*=\s*'kesehatan'/", $migrasi . $src('app/Kesehatan/KesehatanService.php')), 'Tidak ada role login baru');
$repo = $src('app/Penugasan/PenugasanRepository.php');
$assert(str_contains($repo, 'PenugasanJenis::CAKUPAN_SELURUH => [],') && str_contains($repo, "'Seluruh santri aktif'") && str_contains($repo, 'public function tabelAda('), 'Repository penugasan mengenal cakupan seluruh + pemeriksaan tabel');
$hal = $src('admin/admin_penugasan.php');
$assert(str_contains($hal, '$service->skemaTersedia($jenis)') && str_contains($hal, 'Cakupan: seluruh santri aktif'), 'Pusat penugasan tetap terbuka sebelum migrasi/sesudah rollback dan menjelaskan cakupan');

echo PHP_EOL . '=== S-3. Layanan kesehatan ===' . PHP_EOL;
$svc = $tanpaKomentar($src('app/Kesehatan/KesehatanService.php'));
$repoK = $tanpaKomentar($src('app/Kesehatan/KesehatanRepository.php'));
$assert(substr_count($svc, '$this->capabilities->forget(') === 1 && str_contains($svc, 'featureSource($user, PenugasanJenis::CAP_KESEHATAN_CATAT)'), 'Hak dihitung ulang dari basis data setiap panggilan (forget + featureSource)');
$assert(str_contains($svc, "'catat' => \$sumber === self::SUMBER_PETUGAS") && str_contains($svc, 'Capabilities::SUMBER_KEDUANYA => self::SUMBER_ADMIN_PETUGAS'), 'Hanya sumber penugasan murni yang boleh mencatat; admin dan admin+petugas hanya baca');
$buat = substr($svc, (int) strpos($svc, 'public function buat('), 2600);
$assert(str_contains($buat, '$this->wajibCatat($user);') && str_contains($buat, '$this->repo->transaction(') && str_contains($buat, 'kunciPenugasanAktif($userId)'), 'Pencatatan: wajib catat, transaksi, penugasan diperiksa ulang di dalam transaksi');
$assert(str_contains($repoK, 'LOCK IN SHARE MODE') && str_contains($repoK, "r.slug = 'pengurus'") && str_contains($repoK, 'a.is_active = 1') && str_contains($repoK, 'u.is_active = 1'), 'Kunci baca penugasan: akun aktif, peran pengurus, penugasan aktif');
$assert(strpos($buat, 'kunciPenugasanAktif') < strpos($buat, 'klaimIdempotensi') && strpos($buat, 'hash_equals') < strpos($buat, "'replayed' => true") && strpos($buat, "'replayed' => true") < strpos($buat, 'simpanKunjungan'), 'Urutan: penugasan → klaim → banding isi (409) → replay → simpan');
$assert(str_contains($buat, "'kesehatan.kunjungan.dicatat'") && !preg_match("/'(keluhan|catatan_klinis|tindakan)' =>/", substr($buat, (int) strpos($buat, "'kesehatan.kunjungan.dicatat'"), 500)), 'Audit pencatatan hanya metadata (tanpa keluhan/catatan/tindakan)');
$assert(str_contains($buat, 'Audit wajib tidak dapat disimpan'), 'Audit wajib: kegagalan membatalkan transaksi');
$detail = substr($svc, (int) strpos($svc, 'public function detail('), 1200);
$assert(strpos($detail, 'catatAkses') < strpos($detail, "'keluhan' =>"), 'Detail mencatat akses sebelum isi klinis dikembalikan (gagal tertutup)');
$daftar = substr($repoK, (int) strpos($repoK, 'public function daftar('), 1400);
$assert(!preg_match('/k\.(keluhan|catatan_klinis|tindakan)/', $daftar), 'Daftar tidak memilih kolom klinis');
$assert(!preg_match('/\b(UPDATE|DELETE)\s+(FROM\s+)?kesehatan_kunjungan\b/i', $repoK), 'Fase 1 tanpa jalur ubah/hapus kunjungan (koreksi berversi = Fase 2)');
$assert(str_contains($svc, "'status' => 'Tercatat'") && str_contains($svc, 'time() + 300') && str_contains($svc, '[A-Za-z0-9._:-]{8,100}'), 'Validasi: status Tercatat, waktu tidak di masa depan, kunci aman');

echo PHP_EOL . '=== S-4. Halaman web ===' . PHP_EOL;
$guard = $src('portal/_kesehatan.php');
$assert(str_contains($guard, 'authorization()->requireWebUser()') && str_contains($guard, 'Csrf::requireValid($_POST[\'_csrf\'] ?? null)') && str_contains($guard, "header('Cache-Control: private, no-store, max-age=0')"), 'Guard bersama: wajib masuk, CSRF untuk POST, no-store');
foreach (['portal/kesehatan.php', 'portal/kesehatan_buat.php', 'portal/kesehatan_detail.php'] as $p) {
    $isi = $src($p);
    $assert(str_contains($isi, "require_once __DIR__ . '/_kesehatan.php';") && !preg_match('/\$_(GET|POST|REQUEST)\[[^\]]+\]\s*\?>/', $isi) && !preg_match('/<\?=\s*\$[A-Za-z_]+(\[[^\]]+\])*\s*\?>/', $isi) && !preg_match('/->query\(|->prepare\(/', $isi), "$p: guard bersama, keluaran di-escape, tanpa SQL di halaman");
}
$form = $src('portal/kesehatan_buat.php');
$assert(str_contains($form, "Denial::page('Catat kunjungan klinik'") && str_contains($form, 'name="idempotency_key"') && str_contains($form, 'ah_csrf()') && !str_contains($form, 'ah_old_keep'), 'Formulir: hanya petugas, kunci idempotensi + CSRF, isi klinis tidak disimpan ke sesi');
$assert(!preg_match('/kesehatan_(ubah|hapus|koreksi)|name="aksi"/', $src('portal/kesehatan_detail.php') . $src('portal/kesehatan.php')), 'Tanpa tombol ubah/hapus pada daftar dan detail');
$nav = $src('app/Ui/Navigation.php');
$assert(str_contains($nav, "'kesehatan', 'Kesehatan', '/portal/kesehatan.php', 'fa-kit-medical'") && str_contains($nav, '$c->kesehatan(false)') && str_contains($nav, '$c->kesehatan(true)'), 'Menu Kesehatan: admin (pengawasan) dan petugas (penugasan sendiri)');

echo PHP_EOL . '=== S-5. API ===' . PHP_EOL;
$api = $src('api/v1/index.php');
$blok = substr($api, (int) strpos($api, "if (str_starts_with(\$path, '/v5/kesehatan')) {"), 2200);
$assert(str_contains($blok, "header('Cache-Control: private, no-store');") && str_contains($blok, '$kesehatan = kesehatan_service();'), 'API memakai layanan yang sama dengan web, no-store');
$assert(str_contains($blok, "\$body['idempotency_key'] ??= \$_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null;") && str_contains($blok, "JsonResponse::success(['id' => \$hasil['id'], 'replayed' => \$hasil['replayed']], \$hasil['status']);"), 'POST: kunci dari header/badan; respons tanpa isi klinis');
$assert(str_contains($api, 'catch (\App\Kesehatan\KesehatanException $exception)') && strpos($api, "'/v5/kesehatan'") > strpos($api, '$user = api_authenticator()->authenticate();'), 'Rute kesehatan sesudah autentikasi token; galat layanan dipetakan ke kode API');

echo PHP_EOL . '=== S-6. Privasi log ===' . PHP_EOL;
$audit = $src('app/Audit/AuditLogger.php');
$assert(str_contains($audit, "str_starts_with(\$action, 'kesehatan.')") && str_contains($audit, "['keluhan', 'catatan_klinis', 'tindakan', 'alasan', 'catatan']"), 'AuditLogger membuang kolom klinis untuk aksi kesehatan.*');
$semua = $svc . $repoK . $guard . $form . $src('portal/kesehatan_detail.php') . $src('portal/kesehatan.php');
$assert(!preg_match('/\berror_log\s*\(/', $semua) && !preg_match('/getMessage\(\)\s*\.\s*\$/', $semua), 'Kode kesehatan tidak menulis error_log dan pesan galat tidak menyisipkan masukan');

echo PHP_EOL . '=== S-7. Operasi dan batas fase ===' . PHP_EOL;
foreach (['bin/v5_phase1_preflight.php', 'bin/v5_phase1_verify.php', 'bin/v5_phase1_rollback_preflight.php'] as $b) {
    $assert(str_contains($src($b), "if (PHP_SAPI !== 'cli')") && !preg_match('/\b(INSERT|UPDATE|DELETE|DROP|ALTER)\b/', (string) preg_replace("/'[^']*'|\"[^\"]*\"|\/\*.*?\*\/|\/\/.*$/ms", '', $tanpaKomentar($src($b)))), "$b: CLI saja, hanya membaca");
}
$assert(str_contains($src('tests/v5_phase1_db.sh'), 'test_*_test) ;;') && str_contains($src('tests/v5_phase1_seed.php'), "preg_match('/^test_.*_test$/'"), 'Skrip DB/seed uji hanya untuk test_*_test');
$assert(!preg_match('/\b(obat|rujukan|tindak_lanjut|ringkasan_wali|notifikasi)\b/i', $kode) && !str_contains($svc, 'notification') && !str_contains($svc, 'NotificationService'), 'Fase 2–3 belum dimulai: tanpa obat, rujukan, ringkasan wali, atau notifikasi');

echo PHP_EOL . '=== S-8. Lint ===' . PHP_EOL;
$berkas = ['app/Kesehatan/KesehatanException.php', 'app/Kesehatan/KesehatanRepository.php', 'app/Kesehatan/KesehatanService.php', 'app/Penugasan/PenugasanJenis.php',
    'app/Penugasan/PenugasanRepository.php', 'app/Penugasan/PenugasanService.php', 'app/Audit/AuditLogger.php', 'app/Ui/Navigation.php', 'app/Ui/NavigationContext.php', 'app/Ui/Status.php',
    'app/bootstrap.php', 'api/v1/index.php', 'admin/admin_penugasan.php', 'portal/_kesehatan.php', 'portal/kesehatan.php', 'portal/kesehatan_buat.php', 'portal/kesehatan_detail.php',
    'bin/v5_phase1_preflight.php', 'bin/v5_phase1_verify.php', 'bin/v5_phase1_rollback_preflight.php', 'tests/v5_phase1_seed.php', 'tests/v5_phase1_worker.php',
    'tests/v5_phase1_integration.php', 'tests/v5_phase1_http.php', 'tests/v5_phase1_migration.php'];
$lintGagal = [];
foreach ($berkas as $b) {
    exec('php -l ' . escapeshellarg($root . '/' . $b) . ' 2>&1', $out, $kodeLint);
    if ($kodeLint !== 0) {
        $lintGagal[] = $b;
    }
}
$assert($lintGagal === [], 'Lint ' . count($berkas) . ' berkas PHP' . ($lintGagal === [] ? '' : ': ' . implode(', ', $lintGagal)));

echo PHP_EOL . 'Lulus: ' . $lulus . ' · Gagal: ' . count($gagal) . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
