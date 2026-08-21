<?php

declare(strict_types=1);

/**
 * Pemeriksaan statis V2 Fase 2.
 *
 * Tidak memerlukan basis data. Fokus: sifat aditif dan idempoten migrasi 007,
 * pemisahan jalur baca/tulis, transaksi + idempotensi + optimistic version pada
 * setiap mutasi, kewajiban alasan, kelengkapan audit, pengarsipan modul lama,
 * keutuhan kontrak V1, dan lint seluruh berkas PHP baru/diubah.
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

// --- 1. Migrasi dan rollback -----------------------------------------------
$migration = $source('database/migrations/007_v2_phase2_pengajuan_routing_keputusan.sql');
$rollback = $source('database/rollbacks/007_v2_phase2_pengajuan_routing_keputusan.sql');
$statementsOnly = static fn (string $sql): string => (string) preg_replace('/^\s*--.*$/m', '', $sql);
$migrationSql = $statementsOnly($migration);
$rollbackSql = $statementsOnly($rollback);

foreach ([
    'routing_kandidat',
    'routing_catatan',
    'murobi_ditetapkan_oleh_user_id',
    'dibatalkan_oleh_user_id',
    'alasan_pembatalan',
    'CREATE TABLE IF NOT EXISTS izin_keputusan_koreksi',
    'izin_pengajuan_antrean_index',
    'izin_pengajuan_overlap_index',
] as $required) {
    $assert(str_contains($migration, $required), 'Migrasi 007 memuat ' . $required);
}
$assert(
    !preg_match('/\bDROP\s+(TABLE|COLUMN|INDEX)\b/i', $migrationSql)
    && !preg_match('/\b(DELETE\s+FROM|TRUNCATE)\b/i', $migrationSql),
    'Migrasi naik 007 sepenuhnya aditif: tanpa DROP, DELETE, atau TRUNCATE'
);
$assert(
    !preg_match('/\b(ALTER\s+TABLE|UPDATE|DROP\s+TABLE)\s+`?perizinan`?\b/i', $migrationSql),
    'Migrasi 007 tidak pernah mengubah tabel `perizinan` lama'
);
$assert(
    substr_count($migration, 'information_schema.COLUMNS') >= 8
    && substr_count($migration, 'information_schema.STATISTICS') >= 2,
    'Setiap penambahan kolom/indeks dijaga pemeriksaan INFORMATION_SCHEMA (dapat dijalankan ulang)'
);
$assert(
    substr_count($migration, 'PREPARE stmt FROM @sql') === substr_count($migration, 'DEALLOCATE PREPARE stmt'),
    'Seluruh pernyataan dinamis migrasi dilepas kembali (tanpa kebocoran prepared statement)'
);
$assert(
    str_contains($migration, 'izin_koreksi_alasan_check') && str_contains($migration, 'CHAR_LENGTH(TRIM(alasan_koreksi)) > 0'),
    'Alasan koreksi wajib terisi pada tingkat basis data'
);
$assert(
    str_contains($rollbackSql, 'DROP TABLE IF EXISTS izin_keputusan_koreksi')
    && !preg_match('/DROP\s+TABLE[^\n]*\b(perizinan|izin_pengajuan|izin_keputusan|izin_riwayat_status)\b\s*;/i', $rollbackSql),
    'Rollback 007 hanya melepas jejak Fase 2; pengajuan, keputusan, riwayat, dan `perizinan` tetap ada'
);
$assert(
    str_contains($rollback, 'hanya untuk staging atau pemulihan terencana'),
    'Rollback 007 memperingatkan agar tidak dijalankan sembarangan di produksi'
);
$assert(
    substr_count($rollback, 'information_schema') >= 4,
    'Rollback 007 juga dapat dijalankan ulang dengan aman'
);

// --- 2. Pemisahan baca dan tulis -------------------------------------------
$readRepository = $source('app/Izin/IzinRepository.php');
$writeRepository = $source('app/Izin/IzinWriteRepository.php');
$service = $source('app/Izin/IzinService.php');
$workflow = $source('app/Izin/IzinWorkflowService.php');
$router = $source('app/Izin/IzinRouter.php');
$idempotency = $source('app/Izin/IzinIdempotency.php');
$auditLogger = $source('app/Audit/AuditLogger.php');

$assert(
    !preg_match('/\b(INSERT INTO|UPDATE\s+izin|DELETE\s+FROM)\b/i', $readRepository),
    'IzinRepository tetap baca-saja setelah Fase 2'
);
foreach ([
    'app/Izin/IzinRepository.php' => $readRepository,
    'app/Izin/IzinWriteRepository.php' => $writeRepository,
    'app/Izin/IzinService.php' => $service,
    'app/Izin/IzinWorkflowService.php' => $workflow,
    'app/Izin/IzinRouter.php' => $router,
    'app/Izin/IzinIdempotency.php' => $idempotency,
] as $path => $code) {
    $assert(
        !preg_match('/\$_(?:GET|POST|REQUEST|SERVER|COOKIE)\b/', $code),
        basename($path) . ' tidak membaca input global secara langsung'
    );
}
$assert(
    substr_count($writeRepository, '$this->db->prepare(') >= 3
    && !preg_match('/\bquery\(\s*[\'"].*\$/', $writeRepository),
    'Seluruh mutasi memakai prepared statement, bukan penggabungan string'
);

// --- 3. Transaksi, penguncian, idempotensi, dan versi ----------------------
$assert(
    str_contains($writeRepository, 'LIMIT 1 FOR UPDATE') && str_contains($writeRepository, 'lockPengajuan'),
    'Pengajuan dikunci baris sebelum diperiksa dan diperbarui'
);
$assert(
    str_contains($writeRepository, 'lockSantri'),
    'Pembuatan pengajuan menserialkan santri yang sama lewat penguncian baris'
);
$assert(
    str_contains($writeRepository, 'AND version = ?') && str_contains($writeRepository, 'version = version + 1'),
    'Perubahan status memakai optimistic version'
);
$assert(
    str_contains($writeRepository, '$statement->affected_rows'),
    'Jumlah baris terpengaruh dibaca dari statement (bukan setelah statement ditutup)'
);
$assert(
    str_contains($workflow, 'private function transactional')
    && str_contains($workflow, '$this->write->beginTransaction();')
    && str_contains($workflow, '$this->write->rollback();'),
    'Setiap mutasi berjalan dalam satu transaksi dengan rollback pada kegagalan'
);
$assert(
    substr_count($workflow, '$this->idempotency->begin(') === 5
    && substr_count($workflow, '$this->idempotency->complete(') === 5,
    'Kelima mutasi (create, assign, decide, cancel, correction) memakai idempotency key'
);
$assert(
    str_contains($idempotency, 'LIMIT 1 FOR UPDATE') && str_contains($idempotency, 'hash_equals'),
    'Penjaga idempotensi membaca baris terkunci dan membandingkan hash request secara aman'
);
$assert(
    str_contains($idempotency, 'sudah dipakai untuk permintaan dengan isi berbeda'),
    'Kunci idempotensi yang dipakai untuk isi berbeda menghasilkan konflik, bukan diam-diam diterima'
);
$assert(
    str_contains($writeRepository, 'izin_keputusan_pengajuan_unique') === false
    && str_contains($writeRepository, 'sudah memiliki keputusan'),
    'Duplikasi keputusan dari kunci unik basis data diterjemahkan menjadi konflik yang jelas'
);
$assert(
    str_contains($source('app/Izin/IzinException.php'), 'public static function conflict'),
    'Tersedia status konflik 409 yang eksplisit'
);

// --- 4. Aturan bisnis PRD ---------------------------------------------------
$assert(
    str_contains($router, "\$jumlah === 1") && str_contains($router, 'STATUS_PERLU_ADMIN'),
    'Routing hanya mengarahkan bila tepat satu murobi kandidat'
);
$assert(
    str_contains($router, 'murobi_assignments') && str_contains($router, "ta.status = 'Aktif'")
    && str_contains($router, 'plotting_kamar') && str_contains($router, 'plotting_kelas')
    && str_contains($router, 'kl.is_active = 1 AND kl.archived_at IS NULL'),
    'Routing menilai penugasan murobi aktif, tahun ajaran aktif, serta kamar/kelas santri'
);
$assert(
    substr_count($readRepository, 'kl.is_active = 1 AND kl.archived_at IS NULL') >= 3,
    'Cakupan pembimbing menolak target kelas yang sudah nonaktif atau diarsipkan'
);
$assert(
    str_contains($router, 'isEligibleMurobi'),
    'Penetapan manual admin dibatasi pada guru dengan penugasan murobi aktif'
);
$assert(
    str_contains($workflow, "requireText(\n                (string) \$alasanPenggantian")
    || str_contains($workflow, '$alasanPenggantian = $this->requireText('),
    'Keputusan Admin Pengganti wajib memuat alasan penggantian'
);
$assert(
    str_contains($workflow, "KAPASITAS_ADMIN_PENGGANTI = 'Admin Pengganti'")
    && str_contains($workflow, "KAPASITAS_MUROBI = 'Murobi'"),
    'Kapasitas pemberi keputusan dibedakan antara Murobi dan Admin Pengganti'
);
$assert(
    str_contains($workflow, 'Pengajuan ini tidak diarahkan kepada Anda.'),
    'Murobi hanya dapat memutus pengajuan yang diarahkan kepadanya'
);
$assert(
    str_contains($workflow, 'STATUS_BELUM_DIPUTUS') && str_contains($workflow, 'requirePending'),
    'Pembatalan dan keputusan hanya mungkin sebelum pengajuan diputus'
);
$assert(
    str_contains($writeRepository, "STATUS_MENAHAN = ['Diajukan', 'Perlu Penetapan Admin', 'Disetujui']")
    && str_contains($writeRepository, 'findOverlap'),
    'Tumpang tindih dinilai terhadap status Diajukan, Perlu Penetapan Admin, dan Disetujui'
);
$assert(
    str_contains($workflow, 'insertKoreksi') && !preg_match('/DELETE\s+FROM\s+izin_/i', $workflow . $writeRepository),
    'Koreksi menambah peristiwa dan tidak pernah menghapus keputusan atau riwayat'
);
$assert(
    str_contains($workflow, 'bersifat baca-saja dan tidak dapat diproses pada alur V2'),
    'Data warisan V1 tetap baca-saja pada alur V2'
);

// --- 5. Riwayat dan audit ---------------------------------------------------
foreach ([
    'izin_pengajuan_created',
    'izin_routing_resolved',
    'izin_murobi_assigned',
    'izin_decision_recorded',
    'izin_cancelled',
    'izin_decision_corrected',
] as $action) {
    $assert(str_contains($workflow, "'" . $action . "'"), 'Audit mencatat aksi ' . $action);
}
$assert(
    substr_count($workflow, '$this->auditOrFail(') === 6,
    'Seluruh mutasi menulis audit (pembuatan menulis dua peristiwa: pengajuan dan routing)'
);
$assert(
    str_contains($workflow, 'Audit perubahan perizinan tidak dapat disimpan. Transaksi dibatalkan.')
    && str_contains($auditLogger, '): bool')
    && str_contains($auditLogger, 'return false;'),
    'Kegagalan audit membatalkan transaksi perizinan, bukan diabaikan'
);
$assert(
    substr_count($workflow, '$this->write->insertRiwayat(') === 6,
    'Setiap transisi status menulis riwayat yang tidak pernah ditimpa'
);
$assert(
    str_contains($workflow, "'ip_address' => \$ip === null ? null : substr(\$ip, 0, 45)")
    && str_contains($workflow, "'user_agent' => \$userAgent === null ? null : substr(\$userAgent, 0, 255)"),
    'Riwayat menyimpan IP dan user agent tanpa credential'
);
$assert(
    !preg_match('/password|secret|token_hash_secret/i', $workflow),
    'Audit dan riwayat perizinan tidak menyentuh credential atau secret'
);

// --- 6. Halaman web ---------------------------------------------------------
foreach ([
    'portal/izin_buat.php',
    'portal/izin_aksi.php',
    'portal/izin_antrean.php',
    'portal/izin_detail.php',
    'portal/izin.php',
    'portal/index.php',
] as $page) {
    $assert(is_file($root . '/' . $page), 'Halaman tersedia: ' . $page);
    $assert(str_contains($source($page), '_ui.php'), basename($page) . ' memuat guard portal sebelum output');
}
$aksi = $source('portal/izin_aksi.php');
$assert(
    str_contains($aksi, "\$_SERVER['REQUEST_METHOD'] !== 'POST'") && str_contains($aksi, 'http_response_code(405)'),
    'Controller mutasi hanya menerima POST'
);
$assert(
    str_contains($aksi, 'http_response_code($exception->status())') && str_contains($aksi, '303'),
    'Sukses memakai POST/Redirect/GET; kegagalan mempertahankan status HTTP asli (403/409/422)'
);
$assert(
    str_contains($source('portal/_guard.php'), 'Csrf::requireValid'),
    'Seluruh POST portal dilindungi CSRF'
);
$assert(
    substr_count($source('portal/izin_buat.php'), 'idempotency_key') >= 1
    && substr_count($source('portal/izin_detail.php'), 'portal_idempotency_key()') >= 4,
    'Setiap formulir mutasi membawa kunci idempotensi'
);
$assert(
    str_contains($source('portal/_ui.php'), 'function portal_idempotency_key')
    && str_contains($source('portal/_ui.php'), 'function portal_flash_set'),
    'Helper kunci idempotensi dan pesan hasil tersedia untuk seluruh halaman portal'
);
$assert(
    str_contains($source('portal/izin_detail.php'), 'actionsFor')
    && str_contains($source('portal/izin_detail.php'), 'tidak pernah menjadi kontrol akses'),
    'Tombol pada detail hanya cermin hak yang dihitung server'
);
$assert(
    str_contains($source('portal/izin_antrean.php'), 'Tidak ada pengajuan yang menunggu tindakan Anda')
    && str_contains($source('portal/izin_buat.php'), 'Belum ada penugasan pembimbing aktif untuk akun ini'),
    'Halaman antrean dan pengajuan menyediakan empty state yang informatif'
);
$assert(
    str_contains($readRepository, "'admin' => \"p.status = 'Perlu Penetapan Admin'\"")
    && str_contains($readRepository, "'murobi' => \"p.status = 'Diajukan'\""),
    'Antrean admin dan murobi dibatasi server sesuai peran'
);
$assert(
    str_contains($readRepository, "\$parts[] = '1 = 0'"),
    'Cakupan yang tidak dikenal tetap tidak mengembalikan baris'
);

// --- 7. Pengarsipan modul lama dan kompatibilitas V1 ------------------------
$legacyModule = $source('admin/admin_izin.php');
$assert(is_file($root . '/admin/admin_izin.php'), 'Modul izin lama TIDAK dihapus');
$assert(
    str_contains($legacyModule, "header('Location: ' . \$tujuan, true, 302)"),
    'Modul izin lama dialihkan secara kompatibel dengan 302'
);
$assert(
    str_contains($legacyModule, 'IZIN_LEGACY_ENABLED') && str_contains($legacyModule, "\$_GET['legacy'] === '1'"),
    'Modul lama hanya dapat dibuka lewat feature flag eksplisit untuk pemulihan darurat'
);
$assert(
    str_contains($legacyModule, 'INSERT INTO perizinan') && str_contains($legacyModule, 'SELECT p.*, s.nama_santri FROM perizinan'),
    'Kode modul lama tetap utuh di bawah blok pengalihan (tidak dihapus)'
);
$assert(
    !preg_match('/\bDROP\s+TABLE\b|\bTRUNCATE\b/i', $legacyModule),
    'Pengarsipan tidak menghapus data perizinan lama'
);
$assert(
    str_contains($source('.env.example'), 'IZIN_LEGACY_ENABLED=false'),
    'Feature flag modul lama terdokumentasi dan mati secara bawaan'
);

$apiRouter = $source('api/v1/index.php');
// V2 Fase 3 menambahkan endpoint perizinan secara ADITIF. Router tidak boleh
// memanggil IzinWorkflowService langsung: seluruh mutasi API harus melewati
// IzinApiService agar penerjemahan error, cakupan, dan idempotensi seragam.
$assert(
    !str_contains($apiRouter, 'izin_workflow_service()'),
    'Router API tidak memanggil IzinWorkflowService langsung (selalu lewat IzinApiService)'
);
$assert(
    str_contains($apiRouter, 'izin_api_service()') && str_contains($apiRouter, "'/izin/pengajuan'"),
    'Endpoint perizinan Fase 3 tersedia melalui IzinApiService'
);
foreach (["'/schedules/today'", "'/meetings'", "'/reports'", "'/auth/login'"] as $ruteV1) {
    $assert(str_contains($apiRouter, $ruteV1), 'Rute V1 ' . $ruteV1 . ' tetap ada setelah Fase 3');
}
$bootstrap = $source('app/bootstrap.php');
foreach (['izin_workflow_service', 'izin_router', 'izin_repository'] as $helper) {
    $assert(str_contains($bootstrap, 'function ' . $helper), 'bootstrap menyediakan ' . $helper . '()');
}
foreach (['teacher_api_service', 'report_service', 'schedule_service', 'master_data_service', 'api_auth_service', 'izin_service', 'pembimbing_service'] as $legacyHelper) {
    $assert(str_contains($bootstrap, 'function ' . $legacyHelper), 'Helper lama ' . $legacyHelper . '() dipertahankan');
}
$assert(
    str_contains($source('app/Auth/LandingRouter.php'), "app_url('/portal/index.php')")
    && str_contains($source('admin/cek_login.php'), 'landing_router()->url($user)'),
    'Alur login V1/Fase 1 tidak diubah oleh Fase 2'
);

// --- 8. Skrip operasional dan dokumentasi ----------------------------------
foreach (['bin/v2_phase2_preflight.php', 'bin/v2_phase2_verify.php'] as $script) {
    $assert(is_file($root . '/' . $script), 'Skrip tersedia: ' . $script);
    $assert(str_contains($source($script), "PHP_SAPI !== 'cli'"), basename($script) . ' hanya dapat dijalankan dari CLI');
}
$preflight = $source('bin/v2_phase2_preflight.php');
$assert(
    str_contains($preflight, 'BackupWriter') && str_contains($preflight, 'manifest.json') && str_contains($preflight, 'conflicts.json'),
    'Preflight menghasilkan backup, manifest jumlah baris, dan laporan konflik'
);
$assert(
    str_contains($preflight, 'exit(3)') && str_contains($preflight, '$blocking'),
    'Preflight memblokir migrasi bila menemukan konflik yang memblokir'
);
$verify = $source('bin/v2_phase2_verify.php');
$assert(
    str_contains($verify, 'Setiap pengajuan memiliki paling banyak satu keputusan')
    && str_contains($verify, 'Nilai bisnis pengajuan warisan tidak berubah'),
    'Verifikasi memeriksa invarian keputusan tunggal dan keutuhan data warisan'
);

foreach ([
    'docs/phase-v2-2/acceptance-status.md',
    'docs/phase-v2-2/migration-and-rollback.md',
    'docs/phase-v2-2/test-results.md',
    'docs/phase-v2-2/cpanel-deployment.md',
] as $doc) {
    $assert(is_file($root . '/' . $doc), 'Dokumentasi tersedia: ' . $doc);
}
$runbook = $source('docs/phase-v2-2/migration-and-rollback.md');
$assert(
    str_contains($runbook, 'bin/v2_phase2_preflight.php')
    && str_contains($runbook, 'bin/migrate.php rollback')
    && str_contains($runbook, 'bin/v2_phase2_verify.php'),
    'Runbook memuat preflight, migrasi naik, verifikasi, dan petunjuk rollback'
);

// --- 9. Berkas uji Fase 2 ---------------------------------------------------
foreach (['tests/v2_phase2_integration.php', 'tests/v2_phase2_concurrency_worker.php', 'tests/v2_phase2_web_smoke.php'] as $testFile) {
    $assert(is_file($root . '/' . $testFile), 'Berkas uji tersedia: ' . $testFile);
    $assert(str_contains($source($testFile), "_test"), basename($testFile) . ' menolak berjalan di luar database *_test');
}
$assert(
    str_contains($source('tests/v2_phase2_integration.php'), 'proc_open'),
    'Uji dua keputusan bersamaan memakai dua proses PHP yang benar-benar terpisah'
);

// --- 9b. Hotfix navigasi murobi --------------------------------------------
// Masalah: seluruh role `guru` diarahkan ke jadwal mengajar tanpa memeriksa
// capability murobi, sehingga murobi tidak pernah sampai ke antrean keputusan.
$landing = $source('app/Auth/LandingRouter.php');
$cekLogin = $source('admin/cek_login.php');
$halamanLogin = $source('admin/admin_login.php');
$halamanSandi = $source('admin/ubah_password.php');
$halamanJadwal = $source('admin/pertemuan_pengajian.php');
$portalUi = $source('portal/_ui.php');

$assert(is_file($root . '/app/Auth/LandingRouter.php'), 'Tujuan pasca-login punya satu sumber kebenaran: app/Auth/LandingRouter.php');
$assert(
    str_contains($landing, '$this->capabilities->has($user, Capabilities::MUROBI)')
    && !preg_match("/in_array\('murobi', \\\$roles/", $landing),
    'Cabang murobi memakai Capabilities, bukan role mentah'
);
$assert(
    strpos($landing, "in_array('admin', \$roles, true)") < strpos($landing, 'Capabilities::MUROBI')
    && strpos($landing, 'Capabilities::MUROBI') < strpos($landing, "in_array('guru', \$roles, true)"),
    'Urutan tujuan: admin, lalu murobi (capability), baru guru biasa'
);
$assert(
    str_contains($landing, "app_url('/portal/izin_antrean.php?mode=' . Capabilities::MUROBI)"),
    'Murobi diarahkan ke /portal/izin_antrean.php?mode=murobi'
);
$assert(
    str_contains($landing, 'bukan kontrol akses'),
    'LandingRouter menegaskan dirinya bukan pengganti pemeriksaan otorisasi'
);
foreach ([
    'admin/cek_login.php' => $cekLogin,
    'admin/admin_login.php' => $halamanLogin,
    'admin/ubah_password.php' => $halamanSandi,
] as $path => $code) {
    $assert(str_contains($code, 'landing_router()'), basename($path) . ' memakai LandingRouter yang sama');
    $assert(
        !preg_match("/in_array\('guru',[^\n]*\n[^\n]*pertemuan_pengajian/", $code)
        && !str_contains($code, "in_array('guru', \$_SESSION['roles'] ?? [], true)"),
        basename($path) . ' tidak lagi mengarahkan seluruh role guru ke jadwal tanpa memeriksa capability'
    );
}
$assert(
    str_contains($source('app/bootstrap.php'), 'function landing_router'),
    'bootstrap menyediakan landing_router()'
);
$assert(
    str_contains($halamanJadwal, 'capabilities()->has($currentUser, Capabilities::MUROBI)')
    && str_contains($halamanJadwal, '$bolehAntreanIzin'),
    'Halaman jadwal menghitung hak antrean dari capability murobi'
);
$assert(
    str_contains($halamanJadwal, 'if ($bolehAntreanIzin):')
    && str_contains($halamanJadwal, "app_url('/portal/izin_antrean.php?mode=' . Capabilities::MUROBI)"),
    'Tautan Antrean Perizinan pada halaman jadwal hanya dirender untuk murobi aktif'
);
$assert(
    str_contains($halamanJadwal, 'BUKAN kontrol akses'),
    'Halaman jadwal menegaskan tautan bukan pengganti pemeriksaan server'
);
$assert(
    str_contains($halamanJadwal, "!in_array('admin', \$currentUser['roles'], true) && !in_array('guru', \$currentUser['roles'], true)")
    && str_contains($halamanJadwal, 'http_response_code(403)'),
    'Guard server halaman jadwal tidak dilonggarkan oleh hotfix'
);
$assert(
    str_contains($portalUi, "in_array('guru', \$user['roles'] ?? [], true)")
    && str_contains($portalUi, "app_url('/admin/pertemuan_pengajian.php')"),
    'Portal menyediakan jalan kembali ke jadwal mengajar bagi akun ber-role guru'
);
$assert(
    str_contains($source('portal/_guard.php'), 'requireAnyPerizinan')
    && str_contains($source('app/Auth/PortalGuard.php'), 'http_response_code(403)'),
    'Guard portal tetap menolak akun tanpa kemampuan perizinan dengan 403'
);
$assert(
    is_file($root . '/tests/v2_phase2_navigasi_murobi.php')
    && str_contains($source('tests/v2_phase2_navigasi_murobi.php'), '_test'),
    'Tersedia uji navigasi murobi yang hanya berjalan pada database *_test'
);

// --- 10. Lint seluruh berkas PHP baru/diubah -------------------------------
$phpFiles = [
    // baru
    'app/Izin/IzinRouter.php',
    'app/Izin/IzinIdempotency.php',
    'app/Izin/IzinWriteRepository.php',
    'app/Izin/IzinWorkflowService.php',
    'app/Audit/AuditLogger.php',
    'app/Auth/Capabilities.php',
    'portal/izin_buat.php',
    'portal/izin_aksi.php',
    'portal/izin_antrean.php',
    'bin/v2_phase2_preflight.php',
    'bin/v2_phase2_verify.php',
    'tests/v2_phase2_static.php',
    'tests/v2_phase2_integration.php',
    'tests/v2_phase2_concurrency_worker.php',
    'tests/v2_phase2_web_smoke.php',
    'app/Auth/LandingRouter.php',
    'tests/v2_phase2_navigasi_murobi.php',
    // diubah
    'app/bootstrap.php',
    'app/Izin/IzinException.php',
    'app/Izin/IzinRepository.php',
    'app/Izin/IzinService.php',
    'portal/_ui.php',
    'portal/index.php',
    'portal/izin.php',
    'portal/izin_detail.php',
    'admin/admin_izin.php',
    'admin/sidebar.php',
    'admin/cek_login.php',
    'admin/admin_login.php',
    'admin/ubah_password.php',
    'admin/pertemuan_pengajian.php',
];
foreach ($phpFiles as $file) {
    $output = [];
    $status = 0;
    exec('php -l ' . escapeshellarg($root . '/' . $file) . ' 2>&1', $output, $status);
    $assert($status === 0, 'php -l lulus untuk ' . $file);
}

exit($failures === [] ? 0 : 1);
