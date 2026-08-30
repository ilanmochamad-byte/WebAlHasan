<?php

declare(strict_types=1);

/**
 * Pemeriksaan statis V2 Fase 1.
 *
 * Tidak memerlukan basis data. Fokus: sifat aditif migrasi, keberadaan guard
 * berbasis kemampuan, cakupan yang dipaksakan di server, dan kelengkapan artefak.
 */

$root = dirname(__DIR__);
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? '[lulus] ' : '[gagal] ') . $message . PHP_EOL;
    if (!$condition) {
        $failures[] = $message;
    }
};
$source = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

// --- 1. Migrasi dan rollback ------------------------------------------------
$migration = $source('database/migrations/006_v2_phase1_perizinan_foundation.sql');
$rollback = $source('database/rollbacks/006_v2_phase1_perizinan_foundation.sql');

foreach ([
    "INSERT INTO roles (slug, name) VALUES ('pengurus'",
    "INSERT INTO roles (slug, name) VALUES ('orang_tua'",
    'ADD COLUMN pengurus_id',
    'ADD COLUMN wali_id',
    'users_pengurus_unique',
    'users_wali_unique',
    'CREATE TABLE pembimbing_assignments',
    'CREATE TABLE izin_pengajuan',
    'CREATE TABLE izin_keputusan',
    'CREATE TABLE izin_riwayat_status',
    'CREATE TABLE izin_idempotency_keys',
    'CREATE TABLE notifikasi_outbox',
    'CREATE TABLE perangkat_push',
    'CREATE TABLE pengaturan_notifikasi',
] as $required) {
    $assert(str_contains($migration, $required), 'Migrasi 006 memuat ' . $required);
}

// Komentar dibuang lebih dulu agar penjelasan yang menyebut kata kunci berbahaya
// tidak dianggap sebagai pernyataan SQL.
$statementsOnly = static fn (string $sql): string => (string) preg_replace('/^\s*--.*$/m', '', $sql);
$migrationSql = $statementsOnly($migration);
$rollbackSql = $statementsOnly($rollback);

$assert(
    !preg_match('/\bDROP\s+(TABLE|COLUMN|INDEX)\b/i', $migrationSql)
    && !preg_match('/\b(DELETE\s+FROM|TRUNCATE)\b/i', $migrationSql),
    'Migrasi naik sepenuhnya aditif: tanpa DROP, DELETE, atau TRUNCATE'
);
$assert(
    !preg_match('/\b(ALTER\s+TABLE|UPDATE|DROP\s+TABLE)\s+`?perizinan`?\b/i', $migrationSql),
    'Migrasi tidak pernah mengubah tabel `perizinan` lama'
);
$assert(
    str_contains($migration, "WHEN 'Pending' THEN 'Diajukan'")
    && str_contains($migration, "WHEN 'Disetujui' THEN 'Disetujui'")
    && str_contains($migration, "WHEN 'Ditolak' THEN 'Ditolak'"),
    'Pemetaan status lama sesuai PRD 5.5'
);
$assert(
    str_contains($migration, 'SELECT' . PHP_EOL . '    p.id,' . PHP_EOL . '    p.id,')
    || preg_match('/INSERT INTO izin_pengajuan\s*\n\s*\(id, legacy_perizinan_id/', $migration) === 1,
    'Backfill mempertahankan ID pengajuan lama secara eksplisit'
);
$assert(
    str_contains($migration, 'WHERE NOT EXISTS (SELECT 1 FROM izin_pengajuan t WHERE t.legacy_perizinan_id = p.id)'),
    'Backfill idempoten dan tidak pernah menduplikasi baris warisan'
);
$assert(
    str_contains($migration, '-- BACKFILL:BEGIN') && str_contains($migration, '-- BACKFILL:END'),
    'Blok backfill diberi penanda agar dipakai ulang oleh bin/v2_phase1_backfill.php'
);
$assert(
    str_contains($migration, 'ALTER TABLE izin_pengajuan AUTO_INCREMENT'),
    'AUTO_INCREMENT diselaraskan agar ID warisan tidak dipakai ulang'
);
$assert(
    str_contains($migration, 'izin_keputusan_pengganti_check')
    && str_contains($migration, "kapasitas <> 'Admin Pengganti'"),
    'Keputusan admin pengganti wajib memiliki alasan penggantian di tingkat basis data'
);
$assert(
    str_contains($migration, 'notifikasi_event_channel_recipient_unique'),
    'Outbox notifikasi memiliki kunci unik peristiwa/kanal/penerima'
);
$assert(
    str_contains($migration, "whatsapp_enabled = 0 OR whatsapp_check_status = 'Lulus'"),
    'WhatsApp hanya dapat aktif setelah pemeriksaan konfigurasi lulus'
);
$assert(
    !preg_match('/\b(secret|token_plain|api_key|password)\b\s+VARCHAR/i', $migration),
    'Skema notifikasi tidak menyediakan tempat penyimpanan credential provider'
);
$assert(
    str_contains($rollbackSql, 'DROP TABLE IF EXISTS izin_pengajuan')
    && !preg_match('/DROP\s+TABLE[^\n]*\bperizinan\b\s*;/i', $rollbackSql),
    'Rollback melepas tabel V2 tanpa menyentuh tabel `perizinan` lama'
);
$assert(
    str_contains($rollback, 'hanya untuk staging atau pemulihan terencana'),
    'Rollback memperingatkan agar tidak dijalankan sembarangan di produksi'
);

// --- 2. Kemampuan dan otorisasi --------------------------------------------
$capabilities = $source('app/Auth/Capabilities.php');
$guard = $source('app/Auth/PortalGuard.php');
$izinRepository = $source('app/Izin/IzinRepository.php');
$izinService = $source('app/Izin/IzinService.php');

$assert(
    str_contains($capabilities, 'hasActiveMurobiAssignment')
    && str_contains($capabilities, 'murobi_assignments')
    && !preg_match("/in_array\('murobi', \\\$roles/", $capabilities),
    'Hak murobi berasal dari penugasan aktif, bukan dari role terpisah'
);
$assert(
    str_contains($capabilities, "ma.tanggal_mulai <= CURDATE()")
    && str_contains($capabilities, "ta.status = 'Aktif'"),
    'Penugasan murobi dinilai pada tanggal berjalan dan tahun ajaran aktif'
);
$assert(
    str_contains($capabilities, "in_array('pengurus', \$roles, true) && \$this->linkedPengurusId")
    && str_contains($capabilities, "in_array('orang_tua', \$roles, true) && \$this->linkedWaliId"),
    'Kemampuan pengurus dan orang tua menuntut role sekaligus relasi master aktif'
);
// PERUBAHAN LOKASI — paket perapihan V1-V2 (koreksi ke-6): tampilan penolakan
// dipusatkan pada App\Ui\Denial agar seragam lintas halaman. Kode statusnya
// tetap 403 dan tetap ditegakkan di server.
$assert(
    str_contains($guard, 'Denial::render') && str_contains($guard, 'requireWebUser')
    && str_contains($source('app/Ui/Denial.php'), 'http_response_code($status)'),
    'Guard portal menolak kemampuan yang tidak berwenang dengan 403 di server'
);
$assert(
    str_contains($izinRepository, "\$parts[] = '1 = 0'"),
    'Cakupan yang tidak dikenal tidak pernah mengembalikan baris'
);
$assert(
    !preg_match('/\$_(?:GET|POST|REQUEST|SESSION)/', $izinRepository)
    && !preg_match('/\$_(?:GET|POST|REQUEST)/', $izinService),
    'Repository dan service perizinan tidak membaca input global secara langsung'
);
$assert(
    !preg_match('/\b(INSERT INTO|UPDATE\s+izin|DELETE\s+FROM)\b/i', $izinRepository),
    'Fase 1 baca-saja: repository perizinan tidak menulis atau menghapus data'
);
$assert(
    str_contains($izinRepository, 'sw.archived_at IS NULL') && str_contains($izinRepository, 'w.is_active = 1'),
    'Orang tua hanya dapat membaca santri dengan relasi wali aktif'
);
$assert(
    str_contains($izinService, 'throw IzinException::forbidden()'),
    'Detail di luar cakupan menghasilkan 403, bukan kebocoran keberadaan data'
);
$assert(
    str_contains($izinService, 'in_array($preferred, $available, true)'),
    'Mode cakupan yang diminta harus berada dalam kemampuan yang benar-benar dimiliki'
);

// --- 3. Validasi penugasan pembimbing --------------------------------------
$pembimbingService = $source('app/Izin/PembimbingService.php');
$assert(
    str_contains($pembimbingService, 'pengurusIsActive') && str_contains($pembimbingService, 'hanya dapat memakai pengurus yang aktif'),
    'Penugasan pembimbing menolak pengurus tidak aktif'
);
$assert(
    str_contains($pembimbingService, 'kamarExists') && str_contains($pembimbingService, 'kelasIsUsable'),
    'Target kamar/kelas divalidasi terhadap master data'
);
$assert(
    str_contains($pembimbingService, "'pembimbing_assignment_created'")
    && str_contains($pembimbingService, "'pembimbing_assignment_state_changed'")
    && substr_count($pembimbingService, '$this->audit->log(') === 2,
    'Perubahan penugasan pembimbing tercatat pada audit'
);

// --- 4. Akun pengurus dan orang tua ----------------------------------------
$accountService = $source('app/Account/PerizinanAccountService.php');
$accountRepository = $source('app/Account/PerizinanAccountRepository.php');
$assert(
    str_contains($accountService, "audit->log('perizinan_account_created'")
    && str_contains($accountService, "audit->log('perizinan_account_linked'"),
    'Pembuatan dan penghubungan akun tercatat pada audit'
);
$assert(
    str_contains($accountService, 'requireAvailablePengurus') && str_contains($accountService, 'requireAvailableWali'),
    'Akun hanya dapat dihubungkan ke master aktif yang belum terpakai'
);
$assert(
    str_contains($accountService, 'sudah terhubung ke master pengurus atau wali lain'),
    'Satu akun tidak dapat terhubung ke lebih dari satu master'
);
$assert(
    str_contains($accountService, 'force_password_change') || str_contains($accountRepository, 'force_password_change, created_at'),
    'Akun baru mewajibkan penggantian password awal'
);
$assert(
    str_contains($accountRepository, 'begin_transaction()') && str_contains($accountRepository, 'rollback()'),
    'Pembuatan akun beserta relasi dan role memakai transaksi'
);
$assert(
    !preg_match('/\$_(?:GET|POST|REQUEST)/', $accountRepository),
    'Repository akun perizinan tidak membaca input global secara langsung'
);

// --- 5. Halaman web ---------------------------------------------------------
foreach ([
    'admin/admin_pembimbing.php',
    'admin/admin_akun_perizinan.php',
    'portal/_guard.php',
    'portal/_ui.php',
    'portal/index.php',
    'portal/izin.php',
    'portal/izin_detail.php',
] as $page) {
    $assert(is_file($root . '/' . $page), 'Halaman tersedia: ' . $page);
}
foreach (['admin/admin_pembimbing.php', 'admin/admin_akun_perizinan.php'] as $page) {
    $assert(str_contains($source($page), '_guard.php'), basename($page) . ' memakai guard role admin');
}
foreach (['portal/index.php', 'portal/izin.php', 'portal/izin_detail.php'] as $page) {
    $assert(str_contains($source($page), "_ui.php"), basename($page) . ' memuat guard portal sebelum output');
}
$portalGuardSource = $source('portal/_guard.php');
$assert(
    str_contains($portalGuardSource, 'portal_guard()->requireAnyPerizinan()') && str_contains($portalGuardSource, 'Csrf::requireValid'),
    'Guard portal memeriksa kemampuan dan melindungi seluruh POST dengan CSRF'
);
$assert(
    str_contains($source('portal/izin.php'), 'Tidak ada pengajuan yang cocok'),
    'Daftar perizinan menyediakan empty state'
);
$assert(
    str_contains($source('portal/izin_detail.php'), 'Data warisan')
    && str_contains($izinService, "'Data warisan'"),
    'Data lama tanpa pelaku ditampilkan sebagai Data warisan'
);

// --- 6. Kompatibilitas V1 ---------------------------------------------------
$bootstrap = $source('app/bootstrap.php');
foreach (['capabilities()', 'portal_guard()', 'izin_service()', 'pembimbing_service()', 'perizinan_account_service()'] as $helper) {
    $assert(str_contains($bootstrap, 'function ' . rtrim($helper, '()')), 'bootstrap menyediakan ' . $helper);
}
foreach (['teacher_api_service', 'report_service', 'schedule_service', 'master_data_service', 'api_auth_service'] as $legacyHelper) {
    $assert(str_contains($bootstrap, 'function ' . $legacyHelper), 'Helper V1 ' . $legacyHelper . '() dipertahankan');
}
$router = $source('api/v1/index.php');
// Sejak V2 Fase 3 endpoint perizinan sudah ditambahkan secara ADITIF di bawah
// `/api/v1`. Yang dijaga di sini adalah janji Fase 1: seluruh rute V1 tetap ada
// dengan metode dan path yang sama sehingga aplikasi guru tidak perlu diubah.
foreach ([
    "'/auth/login'",
    "'/profile'",
    "'/auth/logout'",
    "'/schedules/today'",
    "'/schedules'",
    "'/meetings'",
    "'/reports'",
    "'/reports/filters'",
    "'/reports/print'",
    '#^/schedules/(\d+)$#',
    '#^/schedules/(\d+)/meetings$#',
    '#^/meetings/(\d+)(?:/attendance)?$#',
    '#^/meetings/(\d+)/attendance$#',
    '#^/reports/meetings/(\d+)$#',
] as $rute) {
    $assert(str_contains($router, $rute), 'Rute API V1 dipertahankan: ' . $rute);
}
$assert(
    str_contains($router, 'assertScheduleAccess($user)'),
    'Endpoint jadwal/laporan V1 tetap dijaga penjaga role admin/guru yang sama'
);
$login = $source('admin/cek_login.php');
// Sejak hotfix navigasi murobi, aturan tujuan pasca-login berada pada satu sumber
// kebenaran (App\Auth\LandingRouter) dan dipakai bersama oleh cek_login.php,
// admin_login.php, serta ubah_password.php. Perilakunya tidak berubah untuk
// admin, guru biasa, pengurus, dan orang tua.
$landingRouter = $source('app/Auth/LandingRouter.php');
// PERUBAHAN PERILAKU — paket perapihan V1-V2, koreksi ke-7 (satu pintu masuk),
// keputusan pengguna 30 Agustus 2026. Alasannya: memilih SATU halaman tujuan
// berdasarkan urutan role membuat akun multi-peran kehilangan jalur peran
// lainnya saat mendarat, dan tiap peran mendapat kerangka berbeda.
//
// Sekarang seluruh akun yang sah mendarat pada satu beranda /portal/index.php
// yang menyusun panel dari kemampuan NYATA akun. Pemeriksaan pengganti yang
// setara: satu sumber kebenaran tetap LandingRouter, dipakai bersama oleh
// cek_login.php dan ubah_password.php, dan pintasan modul tetap dihitung dari
// capability (bukan nama role) sehingga guru tanpa penugasan murobi tidak
// pernah ditawari antrean keputusan.
$assert(
    str_contains($login, 'landing_router()->url($user)')
    && str_contains($landingRouter, "in_array('admin', \$roles, true)")
    && str_contains($landingRouter, "in_array('guru', \$roles, true)")
    && str_contains($landingRouter, "app_url('/portal/index.php')"),
    'Alur login memakai satu sumber kebenaran dan seluruh peran mendarat di beranda tunggal'
);
$assert(
    str_contains($landingRouter, 'Capabilities::MUROBI')
    && str_contains($landingRouter, '$this->capabilities->forUser($user)'),
    'Pintasan beranda dihitung dari capability nyata, bukan dari nama role'
);
$passwordPage = $source('admin/ubah_password.php');
$assert(
    str_contains($passwordPage, 'landing_router()->destination($user)')
    && str_contains($landingRouter, 'ROLE_BERANDA')
    && str_contains($landingRouter, "'pengurus'")
    && str_contains($landingRouter, "'orang_tua'")
    && str_contains($landingRouter, "app_url('/portal/index.php')"),
    'Akun pengurus/orang tua diarahkan ke beranda setelah mengganti password awal'
);
$assert(
    str_contains($source('admin/admin_izin.php'), 'perizinan'),
    'Modul izin lama belum dihapus (baru dipensiunkan pada Fase 2 setelah persetujuan)'
);

// --- 7. Skrip operasional dan dokumentasi ----------------------------------
foreach (['bin/v2_phase1_preflight.php', 'bin/v2_phase1_backfill.php', 'bin/v2_phase1_verify.php'] as $script) {
    $assert(is_file($root . '/' . $script), 'Skrip tersedia: ' . $script);
    $assert(str_contains($source($script), "PHP_SAPI !== 'cli'"), basename($script) . ' hanya dapat dijalankan dari CLI');
}
$preflight = $source('bin/v2_phase1_preflight.php');
$assert(
    str_contains($preflight, 'perizinan_tanpa_santri') && str_contains($preflight, 'exit(3)'),
    'Preflight memblokir migrasi bila ada relasi izin yatim'
);
$assert(
    str_contains($preflight, 'BackupWriter') && str_contains($preflight, 'manifest.json') && str_contains($preflight, 'inventory.json'),
    'Preflight menghasilkan backup, manifest jumlah baris, dan inventaris skema'
);
$verify = $source('bin/v2_phase1_verify.php');
$assert(
    str_contains($verify, 'Daftar ID pengajuan lama identik dengan manifest pra-migrasi'),
    'Verifikasi membandingkan ID pengajuan lama sebelum dan sesudah migrasi'
);

foreach ([
    'docs/phase-v2-1/inventory.md',
    'docs/phase-v2-1/migration-and-rollback.md',
    'docs/phase-v2-1/acceptance-status.md',
] as $doc) {
    $assert(is_file($root . '/' . $doc), 'Dokumentasi tersedia: ' . $doc);
}
$runbook = $source('docs/phase-v2-1/migration-and-rollback.md');
$assert(
    str_contains($runbook, 'bin/v2_phase1_preflight.php') && str_contains($runbook, 'bin/migrate.php rollback'),
    'Runbook memuat preflight, migrasi naik, verifikasi, dan petunjuk rollback'
);

// --- 8. Lint seluruh berkas PHP baru/diubah --------------------------------
$phpFiles = [
    'app/Auth/Capabilities.php', 'app/Auth/PortalGuard.php', 'app/bootstrap.php',
    'app/Izin/IzinException.php', 'app/Izin/IzinRepository.php', 'app/Izin/IzinService.php',
    'app/Izin/PembimbingRepository.php', 'app/Izin/PembimbingService.php',
    'app/Account/PerizinanAccountRepository.php', 'app/Account/PerizinanAccountService.php',
    'admin/admin_pembimbing.php', 'admin/admin_akun_perizinan.php', 'admin/cek_login.php',
    'admin/admin_login.php', 'admin/logout.php', 'admin/sidebar.php',
    'portal/_guard.php', 'portal/_ui.php', 'portal/index.php', 'portal/izin.php', 'portal/izin_detail.php',
    'bin/v2_phase1_preflight.php', 'bin/v2_phase1_backfill.php', 'bin/v2_phase1_verify.php',
];
foreach ($phpFiles as $file) {
    $output = [];
    $status = 0;
    exec('php -l ' . escapeshellarg($root . '/' . $file) . ' 2>&1', $output, $status);
    $assert($status === 0, 'php -l lulus untuk ' . $file);
}

exit($failures === [] ? 0 : 1);
