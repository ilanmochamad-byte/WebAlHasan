<?php

declare(strict_types=1);

/**
 * Uji statis Fase 1 profil pengguna dan impor guru/pengurus (migrasi 035).
 *
 *   php tests/profil_impor_f1_static.php
 *
 *   S-1 lint seluruh berkas PHP yang dibuat/diubah
 *   S-2 migrasi 035 aditif: satu tabel baru + dua kolom ber-DEFAULT, tanpa DML lama
 *   S-3 rollback 035 berpenjaga data dan mencabut token terbatas sebelum melepas kolom
 *   S-4 impor/profil tidak menulis penugasan, role, atau membuat akun/token
 *   S-5 audit profil/impor disaring ke metadata; password tidak pernah diaudit
 *   S-6 API: penjaga token terbatas sebelum rute; rute profil aditif; bentuk /profile lama tetap
 *   S-7 foto di folder privat; UI impor menulis isi berkas lewat textContent
 *   S-8 keputusan PI-1…PI-10 tercatat; batas berkas perubahan
 */

$root = dirname(__DIR__);
$lulus = 0;
$gagal = [];
$assert = static function (bool $ok, string $pesan) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    $ok ? $lulus++ : $gagal[] = $pesan;
};
$baca = static fn (string $f): string => (string) file_get_contents($root . '/' . $f);
$tanpaKomentar = static fn (string $sql): string => preg_replace('/^\s*--.*$/m', '', $sql) ?? '';

$berkasPhp = [
    'app/Profil/ProfilException.php', 'app/Profil/ProfilData.php', 'app/Profil/FotoProfilStorage.php', 'app/Profil/ProfilRepository.php',
    'app/Profil/ProfilService.php', 'app/Profil/ImporPersonelService.php', 'app/MasterData/MasterDataService.php', 'app/Audit/AuditLogger.php',
    'app/Auth/Authorization.php', 'app/Auth/AuthRepository.php', 'app/Auth/AuthService.php', 'app/Auth/ApiTokenAuthenticator.php',
    'app/Api/ApiAuthRepository.php', 'app/Api/ApiAuthService.php', 'app/bootstrap.php', 'api/v1/index.php',
    'admin/admin_guru.php', 'admin/admin_pengurus.php', 'admin/ubah_password.php', 'admin/proses_import_personel.php', 'admin/foto_profil.php',
    'admin/_impor_personel_ui.php', 'admin/_profil_admin_ui.php',
    'tests/profil_impor_f1_integration.php', 'tests/profil_impor_f1_migration.php', 'tests/profil_impor_f1_http.php', 'tests/profil_impor_f1_static.php',
];
echo "=== S-1 lint ===\n";
foreach ($berkasPhp as $f) {
    $out = [];
    exec('php -l ' . escapeshellarg($root . '/' . $f) . ' 2>&1', $out, $kode);
    $assert($kode === 0, "lint $f");
}

echo "\n=== S-2 migrasi aditif ===\n";
$mig = $tanpaKomentar($baca('database/migrations/035_profil_impor_fase1.sql'));
$assert(!preg_match('/\b(UPDATE|DELETE|TRUNCATE|DROP|RENAME|INSERT)\b(?![^\']*\')/i', preg_replace('/ON (DELETE|UPDATE) (SET NULL|CASCADE|RESTRICT|CURRENT_TIMESTAMP)/i', '', $mig) ?? ''),
    'Migrasi 035 tanpa UPDATE/DELETE/TRUNCATE/DROP/RENAME/INSERT atas data');
$assert(preg_match_all('/CREATE TABLE IF NOT EXISTS (\w+)/', $mig, $m) === 1 && $m[1] === ['profil_pribadi'], 'Satu tabel baru profil_pribadi (dapat diulang)');
preg_match_all("/'ALTER TABLE (\\w+) ADD COLUMN (\\w+)/", $mig, $alter, PREG_SET_ORDER);
$assert(array_map(static fn (array $a): string => $a[1] . '.' . $a[2], $alter) === ['users.sesi_versi', 'api_tokens.cakupan'], 'Hanya dua kolom aditif: users.sesi_versi, api_tokens.cakupan');
$assert(str_contains($mig, "DEFAULT 0 COMMENT") && str_contains($mig, "DEFAULT ''penuh''"), 'Kolom baru ber-DEFAULT yang mempertahankan makna lama (sesi 0, token penuh)');
$assert(substr_count($mig, "information_schema.COLUMNS") === 2, 'Penambahan kolom dijaga information_schema (aman dijalankan ulang)');
$assert(str_contains($mig, 'profil_pribadi_pemilik_check') && str_contains($mig, 'profil_pribadi_nik_check') && str_contains($mig, 'profil_pribadi_foto_check')
    && str_contains($mig, 'profil_pribadi_nik_unique (jenis_pemilik, nik)'), 'CHECK pemilik tepat satu, NIK 16 digit, pola nama foto; NIK unik per jenis');
$file = array_map('basename', glob($root . '/database/migrations/*.sql') ?: []);
sort($file);
$assert(end($file) === '035_profil_impor_fase1.sql' && is_file($root . '/database/rollbacks/035_profil_impor_fase1.sql'), '035 migrasi terakhir dengan rollback berpasangan');

echo "\n=== S-3 rollback berpenjaga ===\n";
$rb = $tanpaKomentar($baca('database/rollbacks/035_profil_impor_fase1.sql'));
$assert(str_contains($rb, 'CHECK (jumlah = 0)') && str_contains($rb, 'SELECT COUNT(*) FROM profil_pribadi'), 'Rollback ditolak bila profil pribadi sudah berisi');
$posCabut = strpos($rb, "SET revoked_at = NOW() WHERE cakupan = ''ganti_password''");
$posDrop = strpos($rb, 'DROP COLUMN cakupan');
$assert($posCabut !== false && $posDrop !== false && $posCabut < $posDrop, 'Token ganti_password dicabut sebelum kolom cakupan dilepas');
preg_match_all('/UPDATE (\w+)/', $rb, $up);
$assert($up[1] === ['api_tokens'] && !preg_match('/\bDELETE\b/i', $rb), 'Rollback tidak mengubah/menghapus data lain');

echo "\n=== S-4 tanpa penugasan/role/akun dari impor atau profil ===\n";
$kodeProfil = $baca('app/Profil/ProfilRepository.php') . $baca('app/Profil/ProfilService.php') . $baca('app/Profil/ImporPersonelService.php') . $baca('admin/proses_import_personel.php');
$assert(!preg_match('/(INSERT INTO|UPDATE|DELETE FROM)\s+\w*_assignments/i', $kodeProfil), 'Tidak ada tulis ke tabel *_assignments');
$assert(!preg_match('/(INSERT INTO|UPDATE|DELETE FROM)\s+user_roles/i', $kodeProfil) && !preg_match('/INSERT INTO users\b/i', $kodeProfil) && !preg_match('/INSERT INTO api_tokens/i', $kodeProfil),
    'Tidak ada tulis ke user_roles; tidak membuat akun atau token');
preg_match_all('/UPDATE users SET ((?:(?!WHERE)[^\'"])*)/i', $kodeProfil, $setUsers);
$assert($setUsers[1] !== [] && !preg_match('/\b(username|guru_id|pengurus_id|wali_id|is_active)\s*=/i', implode(' ', $setUsers[1])), 'Profil tidak mengubah username, tautan identitas, atau status akun');
$impor = $baca('app/Profil/ImporPersonelService.php');
$assert(str_contains($impor, "public const INFORMASI = ['tugas', 'kamar', 'tugas_tambahan']") && !preg_match('/\$data\[\'(tugas|kamar|tugas_tambahan)\'\]/', $impor), 'Tugas/Kamar/Tugas Tambahan hanya informasi, tidak masuk data simpan');
$assert(str_contains($impor, "'Pengurus')") && substr_count($impor, 'buatPengurus(') === 1, 'Pengurus baru berjabatan "Pengurus" (PI-8)');
$svc = $baca('app/Profil/ProfilService.php');
$assert(str_contains($svc, 'array_diff(array_keys($input), ProfilData::DIRI, self::KUNCI_KONTROL)'), 'Edit diri menolak kunci di luar daftar yang disetujui');

echo "\n=== S-5 audit tanpa data pribadi/password ===\n";
$audit = $baca('app/Audit/AuditLogger.php');
$assert(str_contains($audit, "str_starts_with(\$action, 'profil.') || str_starts_with(\$action, 'impor_personel.')") && str_contains($audit, 'private function metadataProfil'), 'AuditLogger menyaring aksi profil.* dan impor_personel.* ke metadata');
preg_match('/private function metadataProfil.*?\$boleh = \[(.*?)\];/s', $audit, $boleh);
$assert(isset($boleh[1]) && !preg_match("/'(nama|no_hp|email|nik|nip|alamat_rumah|tempat_lahir|tanggal_lahir|password|token)'/", $boleh[1]), 'Daftar kunci audit tidak memuat isian pribadi, password, atau token');
preg_match_all("/wajibAudit\\('profil\\.password[^;]+;/s", $svc, $pw);
$assert($pw[0] !== [] && !preg_match('/\$baru|\$saatIni|\$ulang|password_hash/', implode('', $pw[0])), 'Audit ganti password tidak menerima nilai password');

echo "\n=== S-6 API ===\n";
$api = $baca('api/v1/index.php');
$posGerbang = strpos($api, "hanya_ganti_password");
$posRute = strpos($api, "'/v3/publikasi'");
$assert($posGerbang !== false && $posRute !== false && $posGerbang < $posRute && str_contains($api, "in_array(\$path, ['/profile/password', '/auth/logout'], true)"),
    'Penjaga token terbatas berjalan sebelum seluruh rute; hanya ganti password dan logout');
foreach (["\$path === '/profile/pribadi'", "\$path === '/profile/foto'", "\$path === '/profile/foto/hapus'", "#^/profile/foto(?:/(\\d+))?$#", "\$path === '/profile/password'"] as $rute) {
    $assert(str_contains($api, $rute), "Rute $rute tersedia");
}
$auth = $baca('app/Api/ApiAuthService.php');
$assert(str_contains($auth, "(\$input['izinkan_password_sementara'] ?? false) === true") && str_contains($auth, "'PASSWORD_CHANGE_REQUIRED', 'Password sementara harus diubah melalui website"),
    'Login lama tetap 403 untuk password sementara; token terbatas hanya atas penanda eksplisit klien');
$assert(str_contains($auth, "'feature_capabilities' => \$this->featureCapabilityPayload(\$user),\n        ];\n    }"), 'Bentuk /profile lama tidak berubah');

echo "\n=== S-7 foto dan UI ===\n";
$assert(str_contains($baca('app/bootstrap.php'), "APP_ROOT . '/storage/private/profil'") && trim($baca('storage/private/.htaccess')) === 'Require all denied' && is_file($root . '/storage/private/profil/.gitignore'),
    'Foto di storage/private/profil (Require all denied, tidak terlacak Git)');
$foto = $baca('app/Profil/FotoProfilStorage.php');
$assert(str_contains($foto, 'MAX_BYTES = 2_097_152') && str_contains($foto, 'getimagesize') && str_contains($foto, 'bin2hex(random_bytes(16))') && str_contains($foto, 'is_uploaded_file'),
    'Foto ≤ 2 MiB, diperiksa sebagai gambar utuh, nama acak, hanya unggahan HTTP');
$ui = $baca('admin/_impor_personel_ui.php');
preg_match_all('/innerHTML\s*=\s*([^;]+);/', $ui, $inner);
$assert(count($inner[1]) === 1 && str_contains($inner[1][0], "'<section class=\"ah-stats\""), 'UI impor: satu innerHTML hanya untuk kartu angka; teks berkas/server lewat textContent');
$assert(str_contains($ui, "cellFormula: false") && str_contains($ui, "raw: true"), 'UI impor membaca nilai mentah tanpa rumus');

echo "\n=== S-8 keputusan dan batas berkas ===\n";
$prd = $baca('PRD-PROFIL-IMPORT.md');
foreach (range(1, 10) as $n) {
    $assert(str_contains($prd, "**PI-$n**"), "Keputusan PI-$n tercatat di PRD");
}
$assert(preg_match_all('/\(assumption\)/', $prd) === 2, 'Tidak ada butir (assumption) tersisa selain penjelasan di kepala PRD');
exec('git -C ' . escapeshellarg($root) . ' diff --name-only origin/main 2>/dev/null', $ubah, $kodeGit);
exec('git -C ' . escapeshellarg($root) . ' ls-files --others --exclude-standard 2>/dev/null', $baru);
if ($kodeGit === 0) {
    $boleh = '#^(PRD-PROFIL-IMPORT\.md|AGENTS\.md|\.gitignore|tests/browser/uji-profil-impor-f1\.mjs|app/(Profil/.*|MasterData/MasterDataService\.php|Audit/AuditLogger\.php|Auth/(Authorization|AuthRepository|AuthService|ApiTokenAuthenticator)\.php|Api/ApiAuth(Repository|Service)\.php|bootstrap\.php)'
        . '|api/v1/index\.php|admin/(admin_guru|admin_pengurus|ubah_password|proses_import_personel|foto_profil|_impor_personel_ui|_profil_admin_ui)\.php'
        . '|database/(migrations|rollbacks)/035_profil_impor_fase1\.sql|storage/private/profil/\.gitignore|tests/profil_impor_f1_.*|docs/profil-impor/fase-1/.*'
        . '|docs/(panduan-pengguna-v4|presentasi-al-hasan-v4)/.*|output/.*|tests/browser/tangkapan-penugasan-keamanan-f1/.*)$#';
    $liar = array_values(array_filter(array_merge($ubah, $baru), static fn (string $f): bool => preg_match($boleh, $f) !== 1));
    $assert($liar === [], 'Perubahan hanya pada berkas yang direncanakan' . ($liar === [] ? '' : ': ' . implode(', ', $liar)));
    $assert(!array_intersect($ubah, ['app/Penugasan/PenugasanService.php', 'app/Penugasan/PenugasanRepository.php', 'app/Auth/Capabilities.php']), 'Layanan penugasan dan Capabilities tidak diubah');
}

echo "\n" . $lulus . ' lulus, ' . count($gagal) . " gagal\n";
foreach ($gagal as $g) {
    echo ' - ' . $g . "\n";
}
exit($gagal === [] ? 0 : 1);
