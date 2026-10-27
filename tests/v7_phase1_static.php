<?php

declare(strict_types=1);

/**
 * Uji statis PRD V7 Fase 1 (tanpa basis data).
 *
 *   php tests/v7_phase1_static.php
 *
 *   S-1 migrasi 030: aditif, idempoten, ENUM V2 tetap di depan, CHECK asal
 *   S-2 rollback 030: penjaga data sebelum perubahan skema
 *   S-3 layanan: urutan kunci, idempotensi, versi, audit tanpa isi, tanpa notifikasi
 *   S-4 V2: status penahan lintas jalur; tampilan/laporan V2 hanya status V2
 *   S-5 web/API: CSRF, escape, no-store, kunci idempotensi, rute V7
 *   S-6 batas Fase 1 terhadap baseline (berkas yang boleh berubah)
 *   S-7 lint PHP seluruh berkas baru/berubah
 */

$root = dirname(__DIR__);
$baseline = getenv('V7F1_BASELINE') ?: '3e785645bc412b7bc95b1e05152f3ed0508a6a58';
$lulus = 0;
$gagal = [];
$assert = static function (bool $ok, string $pesan) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    $ok ? $lulus++ : $gagal[] = $pesan;
};
$baca = static fn (string $p): string => (string) file_get_contents($root . '/' . $p);
$tanpaKomentar = static fn (string $sql): string => (string) preg_replace('/^\s*--.*$/m', '', $sql);

// ===================================================================== S-1
echo PHP_EOL . '=== S-1. Migrasi 030 ===' . PHP_EOL;
$mig = $baca('database/migrations/030_v7_fase1_pengajuan_wali.sql');
$migSql = $tanpaKomentar($mig);
$migrasi = glob($root . '/database/migrations/*.sql');
sort($migrasi);
$assert(basename((string) end($migrasi)) === '030_v7_fase1_pengajuan_wali.sql' && is_file($root . '/database/rollbacks/030_v7_fase1_pengajuan_wali.sql'), '030 adalah migrasi terakhir dan memiliki pasangan rollback');
$assert(!preg_match('/\b(DROP|TRUNCATE|INSERT|RENAME)\b|(?<!ON )\b(DELETE|UPDATE)\b/i', $migSql), 'Migrasi tanpa DROP/DELETE/TRUNCATE/UPDATE/INSERT/RENAME (selain aturan ON DELETE kunci asing)');
preg_match_all('/ALTER TABLE\s+(\w+)/i', $migSql, $m);
$assert(array_unique($m[1]) === ['izin_pengajuan'], 'ALTER hanya pada izin_pengajuan');
$assert(substr_count($migSql, "PREPARE stmt FROM @sql") === substr_count($migSql, 'SET @sql := IF(') && substr_count($migSql, 'SET @sql := IF(') >= 18
    && str_contains($migSql, 'CREATE TABLE IF NOT EXISTS izin_pembimbing_penetapan'), 'Setiap perubahan dibungkus pemeriksaan INFORMATION_SCHEMA (idempoten)');
$assert(str_contains($migSql, "ENUM(''Diajukan'',''Perlu Penetapan Admin'',''Disetujui'',''Ditolak'',''Dibatalkan'',''Menunggu Pembimbing'',''Perlu Penetapan Pembimbing'',''Perlu Perbaikan Wali'',''Ditolak Pembimbing'')"),
    'ENUM: lima nilai V2 berurutan di depan, empat tahap V7 di ujung');
$assert(str_contains($migSql, "asal_pengajuan ENUM(''Pengurus'',''Wali'') NOT NULL DEFAULT ''Pengurus''") && str_contains($migSql, 'izin_pengajuan_v7_asal_check')
    && str_contains($migSql, "asal_pengajuan = ''Wali'' AND wali_id IS NOT NULL AND pengurus_id IS NULL"), 'Asal bawaan Pengurus; CHECK asal ↔ wali ↔ tahap');
$assert(str_contains($migSql, 'izin_pembimbing_penetapan_versi_check CHECK (versi_sesudah = versi_sebelum + 1)') && str_contains($migSql, 'ENGINE=InnoDB'), 'Tabel penetapan: versi berurutan, InnoDB');

// ===================================================================== S-2
echo PHP_EOL . '=== S-2. Rollback 030 ===' . PHP_EOL;
$rb = $tanpaKomentar($baca('database/rollbacks/030_v7_fase1_pengajuan_wali.sql'));
$posPenjaga = strpos($rb, 'INSERT INTO v7_fase1_rollback_guard');
$posDrop = strpos($rb, 'DROP TABLE IF EXISTS izin_pembimbing_penetapan');
$assert($posPenjaga !== false && $posDrop !== false && $posPenjaga < $posDrop && str_contains($rb, 'CHECK (jumlah = 0)'), 'Penjaga data (CHECK pada tabel sementara) dijalankan sebelum perubahan skema');
$assert(str_contains($rb, "asal_pengajuan = 'Wali'") && str_contains($rb, 'izin_pembimbing_penetapan') && str_contains($rb, "operation LIKE 'izin.wali.%'"), 'Penjaga menghitung baris wali/tahap V7, penetapan, dan klaim idempotensi V7');
$assert(!preg_match('/\b(DELETE|TRUNCATE|UPDATE)\b/i', $rb) && !preg_match('/DROP TABLE(?! IF EXISTS izin_pembimbing_penetapan)(?! IF EXISTS v7_)/i', str_replace('DROP TEMPORARY TABLE', '', $rb)),
    'Rollback tanpa DELETE/UPDATE dan hanya melepas tabel V7');
$assert(str_contains($rb, "MODIFY COLUMN status ENUM(''Diajukan'',''Perlu Penetapan Admin'',''Disetujui'',''Ditolak'',''Dibatalkan'') NOT NULL DEFAULT ''Diajukan''"), 'ENUM dikembalikan ke lima nilai V2');

// ===================================================================== S-3
echo PHP_EOL . '=== S-3. Layanan ===' . PHP_EOL;
$svc = $baca('app/Izin/IzinWaliService.php');
$repo = $baca('app/Izin/IzinWaliRepository.php');
$router = $baca('app/Izin/PembimbingRouter.php');
$buat = substr($svc, (int) strpos($svc, 'public function buat('), (int) strpos($svc, 'public function kandidatPembimbing(') - (int) strpos($svc, 'public function buat('));
$urutan = array_map(static fn (string $s): int|false => strpos($buat, $s), ['kunciAkun', 'idempotency->begin', 'waliAkun($userId, true)', 'relasiAktif($waliId, $santriId, true)',
    'lockSantri', 'activeYear', 'findOverlap', 'router->resolve', 'insertPengajuanWali', 'insertRiwayat', 'idempotency->complete', 'auditOrFail']);
$assert(!in_array(false, $urutan, true) && $urutan === array_values(array_unique($urutan)) && $urutan == array_values((static function (array $a): array { sort($a); return $a; })($urutan)),
    'Buat: kunci akun → klaim idempotensi → hak wali (kunci baca) → kunci santri → tahun → benturan → routing → sisip → riwayat → selesai → audit');
$tetap = substr($svc, (int) strpos($svc, 'public function tetapkanPembimbing('), (int) strpos($svc, 'public function daftarWali(') - (int) strpos($svc, 'public function tetapkanPembimbing('));
$urutan = array_map(static fn (string $s): int|false => strpos($tetap, $s), ['wajibAdmin', 'kunciAkun', 'idempotency->begin', 'lockPengajuan', "(int) \$row['version'] !== \$version", 'assignmentFor($pengurusId, $santriId, $onDate, true)', 'applyPenetapan', 'insertPenetapan', 'insertRiwayat', 'idempotency->complete', 'auditOrFail']);
$assert(!in_array(false, $urutan, true) && $urutan == array_values((static function (array $a): array { sort($a); return $a; })($urutan)),
    'Penetapan: admin → kunci akun → klaim → kunci pengajuan → versi → pembimbing sah (kunci baca) → ubah bersyarat versi → riwayat penetapan → riwayat → audit');
$assert(str_contains($repo, 'WHERE id = ? AND asal_pengajuan = \'Wali\' AND version = ? AND status IN') && str_contains($repo, 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED'),
    'Ubah penetapan memakai versi optimistis + status; transaksi READ COMMITTED');
$assert(preg_match("/VALUES \\('Wali', \\?, \\?, \\?, NULL, NULL,/", $repo) === 1, 'Sisip pengajuan wali: pengurus_id dan murobi_guru_id NULL (murobi belum menerima)');
$auditBuat = substr($buat, (int) strpos($buat, "auditOrFail('izin_wali_pengajuan_dibuat'"), 600);
$assert(!preg_match("/'(alasan|catatan|tgl_izin|tgl_kembali)'\\s*=>/", $auditBuat) && str_contains($buat, "'Wali', null, \$meta"), 'Audit pembuatan tanpa alasan/catatan/tanggal; riwayat pembuatan tanpa alasan izin');
$assert(!preg_match('/\$_(GET|POST|SERVER|SESSION|COOKIE)\b/', $svc . $repo . $router), 'Layanan/repositori/router V7 tidak membaca variabel global request');
$assert(!preg_match('/Notification|->emit\(|outbox|WhatsApp/', $svc . $repo), 'Fase 1 tidak mengirim notifikasi (Fase 3); WhatsApp tidak disentuh');
$assert(str_contains($router, "r_v7.slug = 'pengurus'") && str_contains($router, 'u_v7.is_active = 1') && str_contains($router, "ta_v7.status = 'Aktif'"), 'Pembimbing sah: penugasan aktif, tahun aktif, akun pengurus aktif');
$assert(substr_count($svc, 'throw IzinException::notFound(') >= 4 && str_contains($svc, "throw IzinException::notFound('Anak tidak ditemukan"), 'Di luar hak baca/anak lain → 404');
$assert(str_contains($svc, "return \$dasar + ['ringkas' => true];") && str_contains($svc, "'riwayat' => \$proyeksi['pengaju_sendiri'] ? \$this->riwayatWali(\$id) : []"), 'Wali lain: proyeksi ringkas tanpa riwayat');

// ===================================================================== S-4
echo PHP_EOL . '=== S-4. Perubahan V2 terarah ===' . PHP_EOL;
$write = $baca('app/Izin/IzinWriteRepository.php');
preg_match('/STATUS_MENAHAN = \[(.*?)\];/s', $write, $mm);
$menahan = array_map(static fn ($s) => trim($s, " '\n"), explode(',', (string) ($mm[1] ?? '')));
$assert(array_values(array_filter($menahan)) === ['Diajukan', 'Perlu Penetapan Admin', 'Disetujui', 'Menunggu Pembimbing', 'Perlu Penetapan Pembimbing', 'Perlu Perbaikan Wali'],
    'Status penahan rentang: V2 + tiga tahap wali aktif; Ditolak Pembimbing/Dibatalkan melepaskan');
$assert(str_contains($write, "public const STATUS_BELUM_DIPUTUS = ['Diajukan', 'Perlu Penetapan Admin'];"), 'Status yang dapat diputus V2 tidak berubah (tahap V7 tidak dapat diputus murobi/admin)');
$assert(str_contains($baca('app/Izin/IzinRepository.php'), "\$parts[] = 'p.status IN (' . implode(',', array_fill(0, count(self::STATUSES), '?')) . ')';")
    && str_contains($baca('app/Report/IzinReportRepository.php'), "\$parts[] = 'p.status IN (' . implode(',', array_fill(0, count(\\App\\Izin\\IzinRepository::STATUSES), '?')) . ')';"),
    'Daftar/antrean/ringkasan/detail dan laporan V2 hanya memuat status V2');
$assert(str_contains($baca('app/Izin/IzinRepository.php'), "public const STATUSES = ['Diajukan', 'Perlu Penetapan Admin', 'Disetujui', 'Ditolak', 'Dibatalkan'];"), 'Daftar status V2 (kontrak API/laporan) tidak berubah');

// ===================================================================== S-5
echo PHP_EOL . '=== S-5. Web dan API ===' . PHP_EOL;
$halaman = ['portal/_izin_wali.php', 'portal/izin_wali.php', 'portal/izin_wali_buat.php', 'portal/izin_wali_detail.php'];
foreach ($halaman as $h) {
    $isi = $baca($h);
    $assert(str_contains($isi, "require_once __DIR__ . '/_izin_wali.php'") || str_contains($isi, "require_once __DIR__ . '/_ui.php'"), "$h memakai guard portal (CSRF POST)");
    preg_match_all('/<\?=(.*?)\?>/s', $isi, $keluaran);
    $mentah = array_filter($keluaran[1], static fn (string $e): bool => (bool) preg_match("/\\['(alasan|catatan|nama|nis|pengaju|routing_catatan|penetap|pembimbing)'\\]|\\\$galat/", $e)
        && !str_contains($e, 'portal_e('));
    $assert($mentah === [], "$h meng-escape keluaran isi pengajuan" . ($mentah === [] ? '' : ': ' . implode(' | ', array_map('trim', $mentah))));
}
$assert(str_contains($baca('portal/_izin_wali.php'), "header('Cache-Control: private, no-store');"), 'Halaman V7: Cache-Control private, no-store');
$assert(str_contains($baca('portal/_guard.php'), "Csrf::requireValid(\$_POST['_csrf']"), 'Guard portal memeriksa CSRF untuk setiap POST');
$assert(str_contains($baca('portal/izin_wali_buat.php'), 'name="idempotency_key"') && str_contains($baca('portal/izin_wali_detail.php'), 'name="idempotency_key"')
    && str_contains($baca('portal/izin_wali_detail.php'), 'name="version"'), 'Formulir mutasi membawa kunci idempotensi; penetapan membawa versi');
$api = $baca('api/v1/index.php');
foreach (["'/v7/izin/akses'", "'/v7/izin/wali/anak'", "'/v7/izin/wali/pengajuan'", "'/v7/izin/pembimbing/antrean'", "'/v7/izin/admin/pengajuan'",
    '#^/v7/izin/pengajuan/(\\d+)$#', '#^/v7/izin/pengajuan/(\\d+)/kandidat-pembimbing$#', '#^/v7/izin/pengajuan/(\\d+)/penetapan-pembimbing$#'] as $rute) {
    $assert(str_contains($api, $rute), 'Rute API ' . $rute);
}
$assert(str_contains($api, "if (str_starts_with(\$path, '/v7/izin')) {\n        header('Cache-Control: private, no-store');") && str_contains($api, '} catch (\\App\\Izin\\IzinException $exception) {'),
    'API V7: no-store dan pemetaan IzinException (403/404/409/422/503)');
$assert(!str_contains($api, "'/v7/izin/murobi"), 'Tidak ada rute murobi V7 pada Fase 1');

// ===================================================================== S-6
echo PHP_EOL . '=== S-6. Batas Fase 1 terhadap baseline ===' . PHP_EOL;
exec('git -C ' . escapeshellarg($root) . ' diff --name-only ' . escapeshellarg($baseline) . ' -- . 2>&1', $berubah, $kode);
exec('git -C ' . escapeshellarg($root) . ' ls-files --others --exclude-standard 2>&1', $baru);
$semua = array_values(array_unique(array_merge($berubah, $baru)));
$izin = '#^(app/Izin/(IzinWaliService|IzinWaliRepository|PembimbingRouter|IzinRepository|IzinWriteRepository)\.php|app/Report/IzinReportRepository\.php|app/bootstrap\.php'
    . '|app/Ui/Navigation\.php|api/v1/index\.php|portal/(_izin_wali|izin_wali|izin_wali_buat|izin_wali_detail|izin|_ui)\.php'
    . '|database/(migrations|rollbacks)/030_v7_fase1_pengajuan_wali\.sql|bin/(_v7_phase1|v7_phase1_\w+)\.php'
    . '|tests/(v7_phase1_\w+\.(php|sh)|browser/uji-v7-fase1\.mjs|browser/tangkapan-v7-fase1/.+)|docs/(phase-v7-1/.+|api-v1\.md)|AGENTS\.md|PRD-V7\.md'
    . '|docs/panduan-pengguna-v4/.+|docs/presentasi-al-hasan-v4/.+|output/.+)$#';
$luar = array_values(array_filter($semua, static fn (string $f): bool => $f !== '' && !preg_match($izin, $f)));
$assert($kode === 0 && $luar === [], 'Hanya berkas Fase 1 yang berubah dari baseline ' . substr($baseline, 0, 7) . ($luar === [] ? '' : ': ' . implode(', ', $luar)));
$terlarang = array_values(array_filter($semua, static fn (string $f): bool => (bool) preg_match('#^(app/(Auth|Penugasan|Bsa|Kesehatan|V3|V4|Psb|Notification)/|admin/|psb\.php|proses_psb\.php|portal/(bsa|kesehatan|rapor|v3_))#', $f)));
$assert($terlarang === [], 'Auth, Penugasan, BSA V6, Kesehatan V5, V3, V4, PSB, notifikasi, dan halaman admin tidak berubah');

// ===================================================================== S-7
echo PHP_EOL . '=== S-7. Lint ===' . PHP_EOL;
$php = array_values(array_filter($semua, static fn (string $f): bool => str_ends_with($f, '.php') && is_file($root . '/' . $f) && !str_starts_with($f, 'docs/')));
$galatLint = [];
foreach ($php as $f) {
    exec('php -l ' . escapeshellarg($root . '/' . $f) . ' 2>&1', $o, $k);
    if ($k !== 0) {
        $galatLint[] = $f;
    }
}
$assert($php !== [] && $galatLint === [], 'php -l ' . count($php) . ' berkas PHP baru/berubah' . ($galatLint === [] ? '' : ': ' . implode(', ', $galatLint)));

echo PHP_EOL . 'Hasil: ' . $lulus . ' lulus, ' . count($gagal) . ' gagal' . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
