<?php

declare(strict_types=1);

/**
 * Pemeriksaan statis V2 Fase 3.
 *
 * Tidak memerlukan basis data. Fokus:
 *   1. sifat aditif kontrak API V1 (tidak ada breaking change);
 *   2. seluruh endpoint Fase 3 terpasang dan selalu melewati IzinApiService;
 *   3. capability dikirim server dan navigasi aplikasi memakainya, bukan role;
 *   4. tidak ada jejak Fase 4 (notifikasi, push, WhatsApp, outbox, worker);
 *   5. aplikasi Expo tetap SDK 57 tanpa upgrade dan tanpa perubahan arsitektur;
 *   6. penanganan loading/empty/offline/401/403/409/422 tersedia di aplikasi;
 *   7. mutasi memakai idempotency key yang sama saat retry dan tombol terkunci;
 *   8. tidak ada secret, credential, atau data produksi yang ikut masuk repo;
 *   9. lint sintaks seluruh berkas PHP baru/diubah.
 *
 * Jalankan:
 *   MOBILE_APP_ROOT=/path/ke/alhasanApps php tests/v2_phase3_static.php
 */

$root = dirname(__DIR__);
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? '[lulus] ' : '[gagal] ') . $message . PHP_EOL;
    if (!$condition) {
        $failures[] = $message;
    }
};
$source = static fn (string $path): string => (string) @file_get_contents($root . '/' . $path);

$mobileRoot = getenv('MOBILE_APP_ROOT') ?: dirname($root, 4) . '/alhasanApps';
$mobile = static function (string $path) use ($mobileRoot): string {
    return (string) @file_get_contents($mobileRoot . '/' . $path);
};
$adaMobile = is_dir($mobileRoot . '/src');

// ---------------------------------------------------------------------------
// 1. Kontrak API V1 tetap utuh (aditif)
// ---------------------------------------------------------------------------
echo PHP_EOL . '=== 1. Kompatibilitas kontrak API V1 ===' . PHP_EOL;

$router = $source('api/v1/index.php');
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
    $assert(str_contains($router, $rute), 'Rute V1 dipertahankan: ' . $rute);
}
$assert(
    substr_count($router, 'assertScheduleAccess($user)') >= 10,
    'Seluruh endpoint jadwal/laporan V1 tetap memakai penjaga role admin/guru'
);
$assert(
    str_contains($source('app/Auth/ApiTokenAuthenticator.php'), 'public function requireScheduleAccess()'),
    'Method V1 requireScheduleAccess() tetap tersedia untuk pemanggil lama'
);
$assert(
    str_contains($source('app/Http/JsonResponse.php'), "'success' => true")
    && str_contains($source('app/Http/JsonResponse.php'), "'error' => ['code' =>"),
    'Envelope JSON V1 tidak diubah'
);
$profileService = $source('app/Api/ApiAuthService.php');
foreach (["'id' =>", "'name' =>", "'username' =>", "'guru' =>", "'roles' =>"] as $field) {
    $assert(str_contains($profileService, $field), 'Field profil V1 ' . $field . ' dipertahankan');
}
$assert(
    str_contains($profileService, "'capabilities' => \$this->capabilityPayload(\$user)"),
    'Profil menambahkan capability secara aditif'
);

// ---------------------------------------------------------------------------
// 2. Kelengkapan endpoint Fase 3
// ---------------------------------------------------------------------------
echo PHP_EOL . '=== 2. Endpoint Fase 3 ===' . PHP_EOL;

foreach ([
    "'/me/capabilities'" => 'akun dan capability',
    "'/izin/santri'" => 'daftar santri dalam cakupan pengurus',
    "'/izin/pengajuan'" => 'pembuatan dan daftar pengajuan',
    '#^/izin/pengajuan/(\d+)$#' => 'detail pengajuan',
    "'/izin/antrean'" => 'antrean murobi dan admin',
    "'/izin/admin/monitor'" => 'pemantauan admin',
    '#^/izin/pengajuan/(\d+)/routing$#' => 'routing murobi',
    '#^/izin/pengajuan/(\d+)/penetapan-murobi$#' => 'penetapan murobi oleh admin',
    '#^/izin/pengajuan/(\d+)/keputusan$#' => 'keputusan murobi dan Admin Pengganti',
    '#^/izin/pengajuan/(\d+)/pembatalan$#' => 'pembatalan pengajuan',
    '#^/izin/pengajuan/(\d+)/koreksi$#' => 'koreksi keputusan',
    '#^/izin/pengajuan/(\d+)/riwayat$#' => 'riwayat perubahan',
    "'/izin/anak'" => 'status orang tua',
    "'/izin/filters'" => 'pilihan filter',
] as $rute => $keterangan) {
    $assert(str_contains($router, $rute), 'Endpoint ' . $keterangan . ' terpasang (' . $rute . ')');
}
$assert(
    !str_contains($router, 'izin_workflow_service()') && !str_contains($router, 'izin_service()'),
    'Router tidak memanggil layanan domain langsung; seluruh akses lewat IzinApiService'
);
$assert(
    str_contains($source('app/bootstrap.php'), 'function izin_api_service'),
    'bootstrap menyediakan izin_api_service()'
);

// ---------------------------------------------------------------------------
// 3. Otorisasi, cakupan, dan idempotensi pada lapisan API
// ---------------------------------------------------------------------------
echo PHP_EOL . '=== 3. Otorisasi dan idempotensi ===' . PHP_EOL;

$apiService = $source('app/Api/IzinApiService.php');
$assert(
    str_contains($apiService, 'in_array($mode, Capabilities::ALL, true) ? $mode : null'),
    '`mode` dari request hanya boleh mempersempit, tidak pernah menaikkan cakupan'
);
$assert(
    preg_match_all('/private function translate\(/', $apiService) === 1
    && substr_count($apiService, '$this->translate(') >= 12,
    'Seluruh operasi API perizinan melewati penerjemah error tunggal'
);
foreach (["403 => 'FORBIDDEN'", "404 => 'NOT_FOUND'", "409 => 'CONFLICT'", "'VALIDATION_FAILED'"] as $peta) {
    $assert(str_contains($apiService, $peta), 'Pemetaan status HTTP memuat ' . $peta);
}
foreach (['create', 'decide', 'assign', 'cancel', 'correct'] as $mutasi) {
    $assert(
        preg_match('/public function ' . $mutasi . '\(.*?\$this->idempotencyKey\(/s', $apiService) === 1,
        'Mutasi ' . $mutasi . '() meneruskan idempotency key ke lapisan domain'
    );
}
$assert(
    str_contains($apiService, "'status' => (\$result['idempotent_replay'] ?? false) ? 200 : 201"),
    'Retry create/keputusan membalas 200 (pemutaran ulang), bukan 201 baru'
);
$assert(
    !str_contains($apiService, 'INSERT ') && !str_contains($apiService, 'UPDATE ') && !str_contains($apiService, 'DELETE '),
    'Lapisan API tidak pernah menulis langsung ke basis data'
);
$assert(
    str_contains($source('app/Auth/Capabilities.php'), 'hasActiveMurobiAssignment'),
    'Capability murobi tetap berasal dari penugasan aktif, bukan role terpisah'
);
$loginService = $source('app/Api/ApiAuthService.php');
$assert(
    str_contains($loginService, "in_array('pengurus', \$roles, true)")
    && str_contains($loginService, "in_array('orang_tua', \$roles, true)")
    && str_contains($loginService, "\$user['pengurus_is_active'] ?? null) === true")
    && str_contains($loginService, "\$user['wali_is_active'] ?? null) === true"),
    'Login pengurus/orang tua hanya diizinkan bila relasi masternya aktif'
);
$assert(
    str_contains($loginService, "in_array('admin', \$roles, true)")
    && str_contains($loginService, "\$user['guru_is_active'] === true"),
    'Aturan login admin dan guru V1 tidak berubah'
);

// ---------------------------------------------------------------------------
// 4. Batas ruang lingkup modul Fase 3
//
// CATATAN PERUBAHAN (V2 Fase 4, 23 Agustus 2026):
// Sebelumnya bagian ini menuntut TIDAK ADA jejak notifikasi di mana pun, karena
// Fase 4 memang belum dikerjakan. Setelah Fase 4 diterapkan, tuntutan itu
// diganti dengan tuntutan yang lebih tepat dan tetap ketat: Fase 4 harus
// ADITIF — modul Fase 3 tidak boleh ditulis ulang untuk menampung notifikasi.
// Modul-modul di bawah karena itu wajib tetap bersih dari logika notifikasi;
// yang boleh berubah hanyalah router `api/v1/index.php`, yang memang tempat
// pendaftaran rute baru, dan kontrak Fase 3 di dalamnya sudah diperiksa pada
// bagian 1 di atas.
// ---------------------------------------------------------------------------
echo PHP_EOL . '=== 4. Batas ruang lingkup modul Fase 3 ===' . PHP_EOL;

$berkasFase3 = [
    'app/Api/IzinApiService.php',
    'app/Api/ApiAuthService.php',
    'app/Api/ApiAuthRepository.php',
    'app/Auth/ApiTokenAuthenticator.php',
    'bin/v2_phase3_sandbox_seed.php',
    'tests/v2_phase3_api_contract.php',
];
foreach ($berkasFase3 as $berkas) {
    $isi = $source($berkas);
    $assert(
        !preg_match('/expo-notifications|whatsapp|notifikasi_outbox|perangkat_push|pengaturan_notifikasi|outbox/i', $isi),
        'Modul Fase 3 tetap bersih dari logika notifikasi Fase 4: ' . $berkas
    );
}
$assert(
    !is_file($root . '/database/migrations/008_v2_phase3.sql'),
    'Fase 3 tidak menambah migrasi skema (seluruh tabel sudah ada sejak Fase 1–2)'
);
$assert(
    // Fase 3 sendiri tidak menambah migrasi. Angka di bawah naik hanya ketika
    // pekerjaan LAIN menambahkannya: 008 milik Fase 4, 009 milik Fase 5, dan
    // 010 milik paket perapihan V1-V2 (koreksi ke-2, rekonsiliasi wali) sesuai
    // keputusan pengguna 30 Agustus 2026.
    count(glob($root . '/database/migrations/*.sql') ?: []) === 10,
    'Jumlah migrasi menjadi 10 berkas: 7 dari Fase 1–2, 008 Fase 4, 009 Fase 5, 010 perapihan V1-V2'
);
$assert(
    is_file($root . '/database/migrations/008_v2_phase4_notifikasi_push_whatsapp.sql')
        && is_file($root . '/database/rollbacks/008_v2_phase4_notifikasi_push_whatsapp.sql'),
    'Migrasi 008 Fase 4 memiliki pasangan rollback'
);
$assert(
    count(glob($root . '/database/migrations/*.sql') ?: [])
        === count(glob($root . '/database/rollbacks/*.sql') ?: []),
    'Setiap migrasi tetap memiliki satu berkas rollback'
);
$assert(
    // Fase 5 sudah dimulai. Yang dijaga sekarang BUKAN lagi "belum dimulai",
    // melainkan bahwa migrasi Fase 5 tetap berpasangan dengan rollback-nya —
    // pagar yang sama yang berlaku untuk setiap migrasi lain.
    is_file($root . '/database/migrations/009_v2_phase5_laporan_dan_push_receipt.sql')
        && is_file($root . '/database/rollbacks/009_v2_phase5_laporan_dan_push_receipt.sql'),
    'Migrasi 009 Fase 5 memiliki pasangan rollback'
);
$assert(
    // Fase 5 TIDAK boleh mengubah kontrak endpoint Fase 3 yang sudah dipakai
    // aplikasi. Rute laporan baru wajib berada di bawah `/izin/laporan`.
    str_contains($source('api/v1/index.php'), "\$path === '/izin/pengajuan'")
        && str_contains($source('api/v1/index.php'), "\$path === '/izin/laporan'"),
    'Endpoint Fase 3 tetap utuh dan endpoint laporan Fase 5 bersifat aditif'
);

// ---------------------------------------------------------------------------
// 5. Tidak ada secret atau data produksi
// ---------------------------------------------------------------------------
echo PHP_EOL . '=== 5. Kebersihan repositori ===' . PHP_EOL;

$seed = $source('bin/v2_phase3_sandbox_seed.php');
$assert(str_contains($seed, "PHP_SAPI !== 'cli'"), 'Seed fixture hanya dapat dijalankan dari CLI');
$assert(str_contains($seed, "str_ends_with(\$database, '_test')"), 'Seed fixture menolak database selain *_test');
$assert(str_contains($seed, "getenv('V2_PHASE3_SEED') !== '1'"), 'Seed fixture memerlukan penanda lingkungan eksplisit');
$assert(
    substr_count($seed, 'SBX') >= 10 && !preg_match('/k1807225|webalhasan\.sql/i', $seed),
    'Fixture memakai data sintetis berawalan SBX dan tidak menyentuh dump produksi'
);
$kontrak = $source('tests/v2_phase3_api_contract.php');
$assert(
    str_contains($kontrak, "str_ends_with((string) app_config('database.database'), '_test')"),
    'Pengujian API menolak berjalan di luar database *_test'
);
$assert(
    !preg_match('/API_TOKEN_HASH_SECRET[ \t]*=[ \t]*\S+/', $source('.env.example')),
    '.env.example tidak memuat nilai secret sesungguhnya'
);
foreach (['app/Api/IzinApiService.php', 'api/v1/index.php', 'tests/v2_phase3_api_contract.php'] as $berkas) {
    $assert(
        !preg_match('/password\s*=\s*[\'"](?!Sandbox#123)[^\'"]{6,}/i', $source($berkas)),
        'Tidak ada password tertanam pada ' . $berkas
    );
}

// ---------------------------------------------------------------------------
// 6. Aplikasi mobile
// ---------------------------------------------------------------------------
echo PHP_EOL . '=== 6. Aplikasi Expo ===' . PHP_EOL;

if (!$adaMobile) {
    $assert(false, 'MOBILE_APP_ROOT tidak ditemukan: ' . $mobileRoot);
} else {
    $package = $mobile('package.json');
    $packageData = json_decode($package, true);
    $expoVersion = (string) ($packageData['dependencies']['expo'] ?? '');
    $reactNativeVersion = (string) ($packageData['dependencies']['react-native'] ?? '');
    $assert(
        preg_match('/^[~^]?57\./', $expoVersion) === 1
            && preg_match('/^[~^]?0\.86\./', $reactNativeVersion) === 1,
        'Expo SDK 57 dan React Native 0.86 tidak di-upgrade'
    );
    $assert(
        // `expo-notifications` menjadi sah sejak Fase 4; yang tetap dilarang
        // adalah penggantian arsitektur klien HTTP milik Fase 3.
        !str_contains($package, 'axios') && !str_contains($package, 'react-query'),
        'Arsitektur klien HTTP Fase 3 tidak diganti'
    );

    $client = $mobile('src/api/client.ts');
    foreach ([
        'izinSantri', 'izinAnak', 'izinList', 'izinAntrean', 'izinMonitorAdmin',
        'izinDetail', 'izinRiwayat', 'izinRouting', 'izinBuat', 'izinKeputusan',
        'izinPenetapanMurobi', 'izinPembatalan', 'izinKoreksi', 'capabilities',
    ] as $fungsi) {
        $assert(str_contains($client, $fungsi), 'Klien API menyediakan ' . $fungsi . '()');
    }
    $assert(
        // 5 mutasi perizinan Fase 3 + 1 pembukaan pertemuan V1 yang sudah ada.
        substr_count($client, 'idempotency_key: idempotencyKey') === 6,
        'Kelima mutasi perizinan (dan mutasi V1 yang sudah ada) mengirim idempotency key dari pemanggil'
    );
    foreach (['401', '403', '404', '409', '422', 'NETWORK_ERROR', 'TIMEOUT'] as $kode) {
        $assert(str_contains($client, $kode), 'actionableError menangani ' . $kode);
    }

    $guard = $mobile('src/hooks/use-mutation-guard.ts');
    $assert(str_contains($guard, 'inFlight.current'), 'Penjaga mutasi menolak request kedua selama request berjalan');
    $assert(
        str_contains($guard, 'keyRef.current = createIdempotencyKey(prefix)'),
        'Kunci idempotensi dibuat sekali per operasi dan dipakai ulang saat retry'
    );
    $assert(
        str_contains($guard, 'payloadFingerprintRef.current !== fingerprint')
        && substr_count($guard, 'payloadFingerprintRef.current = null') >= 3,
        'Kunci lama dibuang bila payload berubah atau operasi telah selesai definitif'
    );

    $tabs = $mobile('src/components/app-tabs.tsx');
    $assert(
        str_contains($tabs, 'capabilities.list.length > 0') && str_contains($tabs, 'hidden={!adaPerizinan}'),
        'Tab perizinan ditampilkan berdasarkan capability aktual'
    );
    $assert(
        str_contains($tabs, "name=\"(izin)\"") && str_contains($tabs, 'NativeTabs'),
        'Navigasi tetap memakai NativeTabs Expo Router (tanpa perubahan arsitektur)'
    );
    $tabsWeb = $mobile('src/components/app-tabs.web.tsx');
    $assert(str_contains($tabsWeb, 'adaPerizinan'), 'Navigasi web juga berbasis capability');

    $auth = $mobile('src/auth/auth-context.tsx');
    $assert(
        str_contains($auth, 'profile?.capabilities ?? NO_CAPABILITIES'),
        'Konteks autentikasi mengambil capability dari profil server'
    );

    $layar = $mobile('src/app/(app)/(izin)/perizinan.tsx');
    foreach (['LoadingState', 'EmptyState', 'ErrorState', 'RefreshControl', 'ModeSwitcher'] as $bagian) {
        $assert(str_contains($layar, $bagian), 'Layar perizinan menangani ' . $bagian);
    }
    $assert(
        str_contains($layar, "orangTua = modeAktif === 'orang_tua'")
        && str_contains($layar, 'capabilities.aksi.dapat_membuat_pengajuan'),
        'Layar perizinan menyembunyikan tombol mutasi bagi orang tua'
    );

    $detail = $mobile('src/app/izin/[id].tsx');
    foreach ([
        'aksi.putuskan_murobi', 'aksi.putuskan_admin', 'aksi.tetapkan_murobi', 'aksi.batalkan',
        'alasanPenggantian', 'scope.hanya_baca', 'Riwayat perubahan',
    ] as $bagian) {
        $assert(str_contains($detail, $bagian), 'Layar detail memuat ' . $bagian);
    }
    $assert(
        str_contains($detail, 'disabled={sedangMutasi}') && str_contains($detail, 'editable={!sedangMutasi}'),
        'Tombol dan isian dinonaktifkan selama mutasi berjalan'
    );
    $assert(
        str_contains($detail, 'version: detail.pengajuan.version'),
        'Mutasi mengirim optimistic version agar konflik menghasilkan 409'
    );

    $buat = $mobile('src/app/izin/buat.tsx');
    $assert(
        str_contains($buat, "'pilih'") && str_contains($buat, "'isi'") && str_contains($buat, "'konfirmasi'"),
        'Alur pengurus mencakup pilih santri, isi data, dan konfirmasi'
    );
    $assert(str_contains($buat, 'api.izinSantri('), 'Daftar santri berasal dari endpoint bercakupan server');
    $assert(str_contains($buat, 'guard.run('), 'Pengiriman pengajuan memakai penjaga mutasi');
    $assert(
        str_contains($buat, 'JSON.stringify({ payload, mode })')
        && substr_count($detail, 'JSON.stringify({ id, mode, payload })') >= 2
        && str_contains($detail, 'JSON.stringify({ id, payload })'),
        'Seluruh layar mutasi mengikat siklus kunci idempotensi ke fingerprint payload'
    );
}

// ---------------------------------------------------------------------------
// 7. Dokumentasi
// ---------------------------------------------------------------------------
echo PHP_EOL . '=== 7. Dokumentasi Fase 3 ===' . PHP_EOL;

foreach ([
    'docs/api-v1.md',
    'docs/phase-v2-3/endpoint-inventory.md',
    'docs/phase-v2-3/capability-matrix.md',
    'docs/phase-v2-3/testing-sandbox.md',
    'docs/phase-v2-3/acceptance-status.md',
    'docs/phase-v2-3/cpanel-deployment.md',
    'docs/phase-v2-3/mobile-build-and-smoke-test.md',
    'docs/phase-v2-3/migration-and-rollback.md',
] as $doc) {
    $assert(is_file($root . '/' . $doc), 'Dokumentasi tersedia: ' . $doc);
}
$assert(
    str_contains($source('docs/api-v1.md'), '/izin/pengajuan'),
    'Kontrak API terdokumentasi memuat endpoint perizinan Fase 3'
);

// ---------------------------------------------------------------------------
// 8. Lint sintaks PHP
// ---------------------------------------------------------------------------
echo PHP_EOL . '=== 8. php -l ===' . PHP_EOL;

foreach ([
    'api/v1/index.php',
    'app/Api/IzinApiService.php',
    'app/Api/ApiAuthService.php',
    'app/Api/ApiAuthRepository.php',
    'app/Auth/ApiTokenAuthenticator.php',
    'app/bootstrap.php',
    'portal/_ui.php',
    'bin/v2_phase3_sandbox_seed.php',
    'tests/v2_phase3_api_contract.php',
    'tests/v2_phase3_concurrency_worker.php',
    'tests/v2_phase3_router.php',
    'tests/v2_phase3_static.php',
] as $berkas) {
    $output = [];
    $status = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($root . '/' . $berkas) . ' 2>&1', $output, $status);
    $assert($status === 0, 'php -l lulus: ' . $berkas);
}

echo PHP_EOL;
if ($failures === []) {
    echo 'SELURUH PEMERIKSAAN STATIS FASE 3 LULUS.' . PHP_EOL;
    exit(0);
}
echo 'GAGAL (' . count($failures) . '):' . PHP_EOL;
foreach ($failures as $failure) {
    echo ' - ' . $failure . PHP_EOL;
}
exit(1);
