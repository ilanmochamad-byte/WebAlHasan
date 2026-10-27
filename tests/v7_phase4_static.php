<?php

declare(strict_types=1);

/**
 * Uji statis PRD V7 Fase 4 — paritas aplikasi pengajuan izin wali. Tanpa basis
 * data. Membaca checkout mobile `~/alhasanApps` (atau `V7F4_MOBILE`).
 *
 *   php tests/v7_phase4_static.php
 *
 *   X-1  batas web terhadap baseline audit Fase 3: tanpa migrasi; berkas
 *        berubah hanya dalam daftar yang diizinkan; layanan izin V2/V7, Auth,
 *        notifikasi, dan laporan tidak berubah;
 *   X-2  penanda `asal` V2 aditif (satu kunci, dari kolom 030, cadangan Pengurus);
 *   X-3  aplikasi: klien `/v7/izin` tanpa cache disk/log; setiap mutasi lewat
 *        penjaga idempotensi dengan `version`; keputusan murobi/admin tetap V2;
 *   X-4  aplikasi: tautan notifikasi `izin_wali` bertahan melewati login dan
 *        membuka rincian yang meminta server; tujuan tercatat setelah server
 *        mengizinkan; skema `alhasanapps://izin-wali/{id}`;
 *   X-5  aplikasi: perlindungan tangkapan layar sebelum layar dimuat; isi
 *        ditutup saat latar; tanpa console.log/AsyncStorage/SecureStore pada
 *        modul V7; pesan Indonesia untuk memuat/kosong/gagal/konflik;
 *   X-6  layar pengurus V2 tetap ada dan tidak memanggil `/v7`.
 */

$root = dirname(__DIR__);
$baseline = getenv('V7F4_BASELINE') ?: '2e2acc7c9fd8e1a742e69d081faab47bd95756c5';
$mobile = getenv('V7F4_MOBILE') ?: (getenv('HOME') . '/alhasanApps');
$lulus = 0;
$gagal = [];
$assert = static function (bool $ok, string $pesan) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    $ok ? $lulus++ : $gagal[] = $pesan;
};
$baca = static fn (string $f): string => (string) @file_get_contents($root . '/' . $f);
$m = static fn (string $f): string => (string) @file_get_contents($mobile . '/' . $f);
$git = static fn (string $args): string => (string) shell_exec('cd ' . escapeshellarg($root) . ' && git ' . $args . ' 2>/dev/null');
$berubah = array_values(array_filter(array_map('trim', explode("\n", $git("diff --name-only $baseline") . $git('ls-files --others --exclude-standard')))));
$berubah = array_values(array_unique(array_filter($berubah, static fn (string $f): bool => !str_starts_with($f, 'docs/panduan-pengguna-v4/')
    && !str_starts_with($f, 'docs/presentasi-al-hasan-v4/') && !str_starts_with($f, 'output/'))));

// ===================================================================== X-1
echo '=== X-1. Batas web Fase 4 ===' . PHP_EOL;
$assert(trim($git("cat-file -t $baseline")) === 'commit', "Baseline audit Fase 3 $baseline tersedia");
$assert(array_filter($berubah, static fn (string $f): bool => str_starts_with($f, 'database/')) === [], 'Tidak ada migrasi/rollback baru atau berubah');
$izin = static fn (string $f): bool => $f === 'app/Api/IzinApiService.php' || $f === 'AGENTS.md' || $f === 'docs/api-v1.md'
    || str_starts_with($f, 'docs/phase-v7-4/') || preg_match('#^tests/(v7_phase4_[a-z_]+\.(php|sh)|browser/uji-v7-fase4-app\.mjs)$#', $f) === 1;
$lain = array_values(array_filter($berubah, static fn (string $f): bool => !$izin($f)));
$assert($lain === [], 'Berkas web berubah hanya dalam daftar Fase 4' . ($lain === [] ? '' : ': ' . implode(', ', $lain)));
foreach (['app/Izin', 'app/Auth', 'app/Notification', 'api/v1/index.php', 'portal'] as $jalur) {
    $assert(trim($git("diff --name-only $baseline -- $jalur")) === '', "$jalur tidak berubah (keputusan, hak, dan notifikasi tetap di server)");
}

// ===================================================================== X-2
echo PHP_EOL . '=== X-2. Penanda asal V2 ===' . PHP_EOL;
$diff = $git("diff -U0 $baseline -- app/Api/IzinApiService.php");
$tambah = array_values(array_filter(explode("\n", $diff), static fn (string $l): bool => str_starts_with($l, '+') && !str_starts_with($l, '+++') && trim(substr($l, 1)) !== '' && !str_starts_with(trim(substr($l, 1)), '//')));
$hapus = array_values(array_filter(explode("\n", $diff), static fn (string $l): bool => str_starts_with($l, '-') && !str_starts_with($l, '---')));
$assert(count($tambah) === 1 && str_contains($tambah[0], "'asal' =>") && $hapus === [], 'IzinApiService hanya bertambah satu kunci `asal`, tanpa baris dihapus');
$assert(str_contains($tambah[0] ?? '', "\$row['asal_pengajuan'] ?? 'Pengurus'") && str_contains($tambah[0] ?? '', "? 'Wali' : 'Pengurus'"), 'Nilai `asal` dari kolom 030 dengan cadangan Pengurus (sebelum 030)');
$out = [];
exec('php -l ' . escapeshellarg($root . '/app/Api/IzinApiService.php') . ' 2>&1', $out, $kode);
$assert($kode === 0, 'Lint PHP IzinApiService');

// ===================================================================== X-3
echo PHP_EOL . '=== X-3. Klien dan mutasi aplikasi ===' . PHP_EOL;
$assert(is_dir($mobile . '/src'), "Checkout mobile tersedia di $mobile");
$klien = $m('src/api/izin-wali.ts');
$assert(substr_count($klien, "request<") >= 8 && !str_contains($klien, 'fetch(') && preg_match_all("#'/v7/izin/#", $klien) + preg_match_all('#`/v7/izin/#', $klien) >= 12,
    'Klien V7 memakai `request` bersama untuk seluruh endpoint `/v7/izin`');
$assert(str_contains($klien, 'idempotency_key: key') && preg_match_all('/post<HasilMutasi>\(/', $klien) === 5, 'Kelima mutasi V7 mengirim kunci idempotensi');
$assert(preg_match_all('/, version \}/', $klien) === 4, 'Tinjau, perbaiki, batal, dan penetapan mengirim `version`');
$layar = ['src/app/izin-wali/index.tsx', 'src/app/izin-wali/buat.tsx', 'src/app/izin-wali/[id].tsx', 'src/app/izin-wali/perbaiki.tsx'];
$semua = implode("\n", array_map($m, $layar));
$assert(substr_count($semua, 'useIzinWaliMutation(') === 5
    && preg_match_all('/izinWali\.(buat|tinjau|perbaiki|batalkan|tetapkanPembimbing)\(/', $semua) === 5
    && preg_match_all('/key => izinWali\.(buat|tinjau|perbaiki|batalkan|tetapkanPembimbing)\([^\n]*, key\)\)/', $semua) === 5,
    'Setiap mutasi layar lewat `useIzinWaliMutation` dengan kunci dari penjaga');
$hook = $m('src/hooks/use-izin-wali-mutation.ts');
$assert(str_contains($hook, 'useMutationGuard(prefix)') && str_contains($hook, '[401, 403, 404]') && str_contains($hook, 'status === 409'),
    'Penjaga mutasi: kunci sama saat retry, 401/403/404 menutup data, 409 ditawarkan muat ulang');
$assert(!str_contains($semua, '/keputusan') && str_contains($m('src/app/izin-wali/[id].tsx'), "pathname: '/izin/[id]'"), 'Keputusan murobi/admin pengganti tetap di layar V2 pada ID sama');

// ===================================================================== X-4
echo PHP_EOL . '=== X-4. Tautan notifikasi ===' . PHP_EOL;
$ctx = $m('src/notifications/notification-context.tsx');
$assert(str_contains($ctx, 'TIPE_IZIN_WALI, ...Object.keys(TIPE_KEUANGAN)') && str_contains($ctx, "'/izin-wali/[id]'") && str_contains($ctx, 'izinWaliLinkTarget(url)'),
    'Penyedia notifikasi: `izin_wali` bertahan melewati login, rute rincian V7, tautan skema');
$assert(str_contains($m('src/rapor/forms.ts'), "p.tipe === 'izin_wali' ? 'pengajuan_id'"), 'Payload `{tipe:izin_wali, pengajuan_id}` dikenali');
$detail = $m('src/app/izin-wali/[id].tsx');
$assert(preg_match('/await izinWali\.detail\(id\);\s*tujuanDibuka\(TIPE_IZIN_WALI, id\);/', $detail) === 1, 'Penunjuk dilepas hanya setelah server mengizinkan rincian');
$assert(str_contains($m('src/app/notifikasi/[id].tsx'), "data.tautan.tipe === 'izin_wali'"), 'Detail notifikasi menawarkan tombol "Buka pengajuan izin"');
$assert(str_contains($m('src/app/_layout.tsx'), '<Stack.Screen name="izin-wali"') && preg_match('#Stack\.Protected guard=\{Boolean\(profile\)\}>[\s\S]*name="izin-wali"#', $m('src/app/_layout.tsx')) === 1,
    'Navigator izin wali hanya di balik sesi masuk (logout → login dulu)');

// ===================================================================== X-5
echo PHP_EOL . '=== X-5. Privasi dan keadaan layar ===' . PHP_EOL;
$layout = $m('src/app/izin-wali/_layout.tsx');
$assert(str_contains($layout, 'preventScreenCaptureAsync(key)') && str_contains($layout, 'enableAppSwitcherProtectionAsync') && str_contains($layout, "status !== 'ready'"),
    'Perlindungan tangkapan layar dipasang sebelum layar V7 dimuat');
$modul = $semua . $klien . $hook . $m('src/components/izin-wali-shared.tsx') . $m('src/izin-wali/forms.ts');
$assert(!preg_match('/console\.|AsyncStorage|SecureStore|localStorage|FileSystem/', $modul), 'Modul V7 tanpa log konsol atau penyimpanan perangkat');
$assert(str_contains($m('src/components/izin-wali-shared.tsx'), 'Informasi perizinan disembunyikan.'), 'Isi ditutup saat aplikasi di latar');
foreach (['LoadingState', 'EmptyState', 'ErrorState', 'Muat versi terbaru'] as $kata) {
    $assert(str_contains($semua, $kata), "Layar V7 menangani: $kata");
}

// ===================================================================== X-6
echo PHP_EOL . '=== X-6. Jalur pengurus V2 ===' . PHP_EOL;
$v2 = $m('src/app/izin/buat.tsx') . $m('src/app/izin/[id].tsx');
$assert(str_contains($v2, 'api.izinBuat(') && str_contains($v2, 'api.izinKeputusan(') && !str_contains($v2, '/v7/'), 'Layar pengurus/murobi V2 tetap memakai endpoint V2');

echo PHP_EOL . sprintf('Ringkasan: %d lulus, %d gagal', $lulus, count($gagal)) . PHP_EOL;
exit($gagal === [] ? 0 : 1);
