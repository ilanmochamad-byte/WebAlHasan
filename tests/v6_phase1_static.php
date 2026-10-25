<?php

declare(strict_types=1);

/**
 * Pemeriksaan statis PRD V6 Fase 1 — fondasi BSA dan transaksi minimum.
 *
 * Tidak memerlukan basis data maupun server. Perilaku nyata diuji di
 * `tests/v6_phase1_integration.php`, `tests/v6_phase1_http.php`,
 * `tests/v6_phase1_migration.php`, dan `tests/browser/uji-v6-fase1.mjs`.
 *
 *   S-1  migrasi 027 aditif + rollback berpasangan + nomor berurutan, nominal
 *        BIGINT tanpa float, CHECK saldo/nominal, kunci unik idempotensi;
 *   S-2  satu penugasan BSA = jenis fondasi `bendahara_bulanan` diperluas
 *        aditif (tabel/entitas/capability lama tetap, + bsa.tabungan, tidak
 *        gugur per semester), tanpa role baru;
 *   S-3  layanan: hak dihitung ulang, admin/wali hanya baca, urutan kunci
 *        tetap di transaksi, idempotensi, nominal tanpa float, audit metadata;
 *   S-4  web: guard bersama, CSRF untuk POST, no-store, keluaran di-escape,
 *        tombol pencatatan hanya untuk petugas;
 *   S-5  API: rute /v6/bsa memakai layanan yang sama, no-store, kunci dari
 *        header/badan, galat BsaException dipetakan;
 *   S-6  privasi: AuditLogger menyaring `bsa.*`; wali tanpa referensi/catatan;
 *   S-7  batas Fase 1: kode PSB/V1–V5/Auth/mobile tidak diubah; Fase 2+ belum
 *        dimulai (tarif, pembayaran tagihan, kuitansi, pembalik, notifikasi);
 *   S-8  operasi: preflight/verifier/preflight rollback, skrip DB uji hanya
 *        test_*_test; lint seluruh berkas PHP baru/diubah.
 *
 *   php tests/v6_phase1_static.php
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
$baseline = getenv('V6F1_BASELINE') ?: '761ae07fd41fd5833a2f43653567e0db80a643ff';

echo '=== S-1. Migrasi 027 ===' . PHP_EOL;
$migrasi = $src('database/migrations/027_v6_fase1_bsa.sql');
$rollback = $src('database/rollbacks/027_v6_fase1_bsa.sql');
$kode = (string) preg_replace(['/--.*$/m', '/ON UPDATE CURRENT_TIMESTAMP/i'], '', $migrasi);
$daftarMigrasi = array_map('basename', glob($root . '/database/migrations/*.sql') ?: []);
$assert(end($daftarMigrasi) === '027_v6_fase1_bsa.sql' && in_array('026_v5_fase3_ringkasan_wali.sql', $daftarMigrasi, true), 'Migrasi 027 berurutan setelah 026 (migrasi terakhir yang ada)');
$assert(substr_count($kode, 'CREATE TABLE IF NOT EXISTS') === 6 && !preg_match('/\b(DROP|DELETE|UPDATE|TRUNCATE|ALTER|RENAME|INSERT)\b/i', $kode), 'Migrasi aditif: 6 CREATE TABLE IF NOT EXISTS, tanpa DROP/ALTER/DML');
$tabel = ['bsa_tagihan', 'bsa_tagihan_komponen', 'bsa_tabungan_transaksi', 'bsa_tabungan_akun', 'bsa_pembayaran', 'bsa_idempotensi'];
foreach ($tabel as $t) {
    $assert(str_contains($kode, 'CREATE TABLE IF NOT EXISTS ' . $t . ' (') && str_contains($rollback, 'DROP TABLE IF EXISTS ' . $t . ';'), "Tabel $t dibuat dan dilepas rollback berpasangan");
}
$assert(!preg_match('/\b(FLOAT|DOUBLE|REAL|DECIMAL\(\d+,\s*[1-9])/i', $kode) && preg_match_all('/\b(total|terbayar|nominal|saldo|saldo_sesudah) BIGINT NOT NULL|saldo_sesudah BIGINT NULL/', $kode) >= 7,
    'Nominal/saldo memakai BIGINT rupiah bulat, tanpa float/pecahan');
foreach (['bsa_akun_saldo_check CHECK (saldo >= 0)', 'bsa_transaksi_nominal_check CHECK (nominal > 0)', 'bsa_transaksi_saldo_check', 'bsa_tagihan_terbayar_check CHECK (terbayar >= 0 AND terbayar <= total)',
    'bsa_komponen_nominal_check CHECK (nominal > 0)', 'bsa_pembayaran_nominal_check CHECK (nominal > 0)'] as $c) {
    $assert(str_contains($kode, $c), "CHECK basis data: $c");
}
foreach (['bsa_tagihan_santri_periode_unique (santri_id, periode)', 'bsa_transaksi_retry_unique (dicatat_oleh, idempotency_key)', 'bsa_idempotensi_unique (user_id, kunci)',
    'bsa_pembayaran_kuitansi_unique (nomor_kuitansi)', 'bsa_tagihan_retry_unique (diterbitkan_oleh, idempotency_key)'] as $u) {
    $assert(str_contains($kode, $u), "Kunci unik: $u");
}
$assert(substr_count($kode, 'ENGINE=InnoDB') === 6 && str_contains($kode, 'REFERENCES bendahara_bulanan_assignments (id)') && str_contains($kode, 'REFERENCES santri (id)'), 'Enam tabel InnoDB berkunci asing ke santri dan penugasan BSA fondasi');
$assert(substr_count($rollback, 'DROP TABLE IF EXISTS') === 6 && str_contains($rollback, 'v6_fase1_rollback_guard_kosong CHECK (jumlah = 0)')
    && strpos($rollback, 'INSERT INTO v6_fase1_rollback_guard') < strpos($rollback, 'DROP TABLE IF EXISTS bsa_idempotensi')
    && strpos($rollback, 'DROP TABLE IF EXISTS bsa_tabungan_akun') < strpos($rollback, 'DROP TABLE IF EXISTS bsa_tabungan_transaksi')
    && strpos($rollback, 'DROP TABLE IF EXISTS bsa_pembayaran') < strpos($rollback, 'DROP TABLE IF EXISTS bsa_tagihan;'),
    'Rollback: penjaga data sebelum DROP, hanya 6 tabel baru, urut anak → induk');

echo PHP_EOL . '=== S-2. Penugasan BSA pada fondasi ===' . PHP_EOL;
require_once $root . '/app/Penugasan/PenugasanJenis.php';
use App\Penugasan\PenugasanJenis;

$def = PenugasanJenis::definisi(PenugasanJenis::BENDAHARA_BULANAN);
$assert($def['tabel'] === 'bendahara_bulanan_assignments' && $def['entitas'] === 'bendahara_bulanan_assignment' && $def['subjek'] === 'pengurus' && $def['role_dasar'] === 'pengurus'
    && $def['cakupan'] === PenugasanJenis::CAKUPAN_JENJANG, 'Jenis bendahara_bulanan tetap: tabel, entitas audit, subjek pengurus, cakupan unit/jenjang');
$assert($def['label'] === 'Petugas BSA' && $def['wajib_tahun_aktif'] === false && $def['prd'] === 'V6', 'Label Petugas BSA; tidak gugur saat semester berganti (keputusan 25 Oktober 2026)');
$assert(array_slice($def['capabilities'], 0, 4) === ['pembiayaan_bulanan.tagihan', 'pembiayaan_bulanan.pembayaran', 'pembiayaan_bulanan.verifikasi', 'pembiayaan_bulanan.laporan']
    && $def['capabilities'][4] === 'bsa.tabungan' && count($def['capabilities']) === 5, 'Capability lama dipertahankan berurutan, bsa.tabungan ditambahkan aditif');
$assert(PenugasanJenis::jenisUntukCapability('bsa.tabungan') === [PenugasanJenis::BENDAHARA_BULANAN], 'bsa.tabungan hanya dari satu penugasan BSA');
$assert(!in_array(PenugasanJenis::BENDAHARA_BULANAN, PenugasanJenis::MENDATANG, true) && PenugasanJenis::MENDATANG === [PenugasanJenis::PANITIA_PSB, PenugasanJenis::BENDAHARA_PSB],
    'BSA bukan lagi "modul mendatang"; PSB tetap mendatang');
$assert(PenugasanJenis::definisi(PenugasanJenis::BENDAHARA_PSB)['capabilities'] === ['psb_keuangan.tagihan', 'psb_keuangan.pembayaran', 'psb_keuangan.verifikasi', 'psb_keuangan.laporan']
    && PenugasanJenis::definisi(PenugasanJenis::PENDIDIKAN)['wajib_tahun_aktif'] === true, 'Jenis lain (bendahara PSB, Pendidikan) tidak berubah');
$capSrc = $tanpaKomentar($src('app/Auth/Capabilities.php'));
$assert(str_contains($capSrc, "\$syaratTahun = \$definisi['wajib_tahun_aktif'] ? \"AND ta.status = 'Aktif'\" : '';"), 'Resolver capability membaca wajib_tahun_aktif dari katalog (tanpa cabang khusus BSA)');
$assert(!preg_match('/INSERT INTO roles|bsa.*role/i', $kode), 'Tidak ada role login baru');
$admin = $src('admin/admin_penugasan.php');
$assert(str_contains($admin, "!\$definisi['wajib_tahun_aktif']") && str_contains($admin, 'tidak gugur saat semester berganti'), 'Formulir pusat penugasan menjelaskan masa berlaku BSA');

echo PHP_EOL . '=== S-3. Layanan ===' . PHP_EOL;
$svc = $tanpaKomentar($src('app/Bsa/BsaService.php'));
$repo = $tanpaKomentar($src('app/Bsa/BsaRepository.php'));
$assert(str_contains($svc, '$this->capabilities->forget($userId);') && str_contains($svc, 'PenugasanJenis::CAP_BSA_TABUNGAN'), 'Hak dihitung ulang dari basis data pada setiap panggilan (forget)');
$assert(preg_match("/'kelola' => \\\$akses\\['kelola'\\]|'kelola' => true, 'sumber' => \\\$sumber/", $svc) === 1 && str_contains($svc, "return ['baca' => true, 'kelola' => false, 'sumber' => \$sumber")
    && str_contains($svc, "'baca' => true, 'kelola' => false, 'sumber' => self::SUMBER_WALI"), 'Hanya petugas berpenugasan yang kelola; admin, admin+petugas, dan wali hanya baca');
$mutasi = substr($svc, (int) strpos($svc, 'private function mutasiTabungan'), 2600);
$urutan = [strpos($mutasi, 'penugasanTerkunci('), strpos($mutasi, 'klaim('), strpos($mutasi, 'santriDalamCakupan('), strpos($mutasi, 'kunciAkun('), strpos($mutasi, 'catatTransaksi(')];
$assert(!in_array(false, $urutan, true) && $urutan === array_values(array_unique($urutan)) && $urutan === (static function (array $a): array { sort($a); return $a; })($urutan),
    'Mutasi tabungan: penugasan → idempotensi → santri/cakupan → akun → ledger (satu urutan kunci)');
$assert(str_contains($mutasi, '$data[\'nominal\'] > $saldoLama') && str_contains($repo, 'WHERE santri_id = ? AND saldo = ?') && str_contains($repo, 'FOR UPDATE'), 'Saldo dicek setelah kunci tulis akun; pembaruan bersyarat pada saldo lama');
$assert(str_contains($repo, "LOCK IN SHARE MODE") && substr_count($repo, '->transaction(') === 0 && str_contains($svc, '$this->repo->transaction('), 'Penugasan/santri dikunci baca; mutasi di dalam transaksi layanan');
$assert(str_contains($repo, "SET TRANSACTION ISOLATION LEVEL READ COMMITTED") && preg_match('/\[1020, 1205, 1213\]/', $repo) === 1, 'Transaksi READ COMMITTED + kunci eksplisit; 1020/1205/1213 → 409 dapat diulang');
$assert(!preg_match('/\b(float|floatval|round|number_format|bcadd)\b|\(float\)/i', $svc . $repo), 'Tidak ada float/pembulatan pada layanan dan repository');
$assert(str_contains($svc, "'/^[1-9][0-9]{0,9}\$/D'") && str_contains($svc, 'NOMINAL_MAKS = 1_000_000_000'), 'Nominal hanya rupiah bulat positif dengan batas');
$assert(str_contains($svc, "\$klaim['operasi'] !== \$operasi || !hash_equals(\$klaim['request_hash'], \$hash)") && str_contains($svc, "'replayed' => true"), 'Idempotensi: hash isi + operasi; replay mengembalikan hasil sama, beda isi 409');
$assert(substr_count($svc, 'wajibAudit(') >= 3 && str_contains($svc, "'Audit wajib tidak dapat disimpan. Transaksi tidak dicatat.', 503"), 'Audit wajib di dalam transaksi; gagal audit membatalkan mutasi');
$assert(!preg_match('/->query\(\s*["\'](?!SET TRANSACTION)/', $repo) && !preg_match('/\.\s*\$(q|j|kunci|periode|nominal|santriId|userId|hash|operasi|data)\b/', $repo)
    && str_contains($repo, "implode(', ', array_fill(0, count(\$hak['jenjang']), '?'))"), 'Repository memakai parameter terikat (tanpa query mentah selain SET TRANSACTION, tanpa masukan disambung ke SQL)');
$assert(!str_contains($svc . $repo, 'error_log'), 'Tanpa error_log berisi masukan');

echo PHP_EOL . '=== S-4. Web ===' . PHP_EOL;
$guard = $src('portal/_bsa.php');
$assert(str_contains($guard, 'requireWebUser()') && str_contains($guard, 'Csrf::requireValid') && str_contains($guard, 'no-store') && str_contains($guard, '$bsa->akses($currentUser)'), 'Guard bersama: sesi web, CSRF untuk POST, no-store, hak dari layanan');
foreach (['portal/bsa.php', 'portal/bsa_santri.php', 'portal/bsa_transaksi.php', 'portal/bsa_tagihan_buat.php', 'portal/bsa_tagihan.php'] as $f) {
    $isi = $src($f);
    $assert(str_contains($isi, "require_once __DIR__ . '/_bsa.php';") && !preg_match('/<\?=\s*\$[a-z]+\[[^\]]+\]\s*\?>/', $isi) && !str_contains($isi, '$_GET[\'nominal\']'), "$f memakai guard bersama dan meng-escape keluaran");
}
$assert(str_contains($src('portal/bsa_transaksi.php'), "if (!\$aksesBsa['kelola'])") && str_contains($src('portal/bsa_tagihan_buat.php'), "if (!\$aksesBsa['kelola'])"), 'Formulir mutasi menolak non-petugas sebelum render');
$assert(str_contains($src('portal/bsa_santri.php'), "\$aksi = \$kelola ?") && str_contains($src('portal/bsa_transaksi.php'), 'name="idempotency_key"'), 'Tombol pencatatan hanya untuk petugas; formulir membawa kunci idempotensi');
$nav = $src('app/Ui/Navigation.php');
$assert(str_contains($nav, "self::item('bsa', \$label, '/portal/bsa.php', 'fa-wallet'") && str_contains($nav, "in_array(\$c->bsa(), ['admin', 'admin_petugas'], true)") && str_contains($nav, "\$c->bsa() === 'wali'"),
    'Menu Keuangan: admin (baca), petugas BSA, dan orang tua — hak dari BsaService');
$fa = (string) @file_get_contents($root . '/tests/fixtures/font_awesome_6_0_0_solid.txt');
$assert(preg_match('/^wallet$/m', $fa) === 1, 'Ikon fa-wallet tersedia di Font Awesome 6.0.0 yang dimuat');

echo PHP_EOL . '=== S-5. API ===' . PHP_EOL;
$api = $src('api/v1/index.php');
$assert(str_contains($api, "str_starts_with(\$path, '/v6/bsa')") && str_contains($api, '$bsa = bsa_service();') && str_contains($api, "header('Cache-Control: private, no-store');"), 'Rute /v6/bsa memakai layanan yang sama dan no-store');
foreach (["'/v6/bsa/akses'", "'/v6/bsa/santri'", "#^/v6/bsa/santri/(\\d+)\$#", "#^/v6/bsa/santri/(\\d+)/saldo\$#", "#^/v6/bsa/santri/(\\d+)/(setoran|penarikan)\$#", "'/v6/bsa/tagihan'", "#^/v6/bsa/tagihan/(\\d+)\$#"] as $r) {
    $assert(str_contains($api, $r), "Rute API $r");
}
$assert(str_contains($api, "\$body['idempotency_key'] ??= \$_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null;") && str_contains($api, 'catch (\App\Bsa\BsaException $exception)'), 'Kunci idempotensi dari header/badan; BsaException dipetakan ke kode galat');

echo PHP_EOL . '=== S-6. Privasi ===' . PHP_EOL;
$audit = $tanpaKomentar($src('app/Audit/AuditLogger.php'));
$assert(str_contains($audit, "str_starts_with(\$action, 'bsa.')") && str_contains($audit, "\$boleh = ['tagihan_id', 'transaksi_id', 'santri_id', 'jenis', 'metode', 'status', 'periode', 'penugasan_id', 'sumber', 'kanal', 'versi', 'jumlah_komponen'];"),
    'AuditLogger hanya meloloskan metadata untuk aksi bsa.* (tanpa nominal/saldo/referensi/tujuan)');
$assert(str_contains($svc, "unset(\$baris['referensi'], \$baris['catatan']);"), 'Riwayat wali tanpa referensi transfer dan catatan internal');

echo PHP_EOL . '=== S-7. Batas Fase 1 ===' . PHP_EOL;
$diff = static function (string $jalur) use ($root, $baseline): string {
    return trim((string) shell_exec('cd ' . escapeshellarg($root) . ' && git diff --name-only ' . escapeshellarg($baseline) . ' -- ' . $jalur . ' 2>/dev/null'));
};
$assert(trim((string) shell_exec('cd ' . escapeshellarg($root) . ' && git cat-file -t ' . escapeshellarg($baseline) . ' 2>/dev/null')) === 'commit', 'Baseline ' . substr($baseline, 0, 7) . ' tersedia');
$assert($diff('app/Auth app/Psb app/Kesehatan app/V3 app/V4 app/Izin app/MasterData app/Notification') === '', 'Kode Auth, PSB, V2–V5, master data, dan notifikasi tidak diubah');
$assert($diff("'database/migrations/0*.sql' 'database/rollbacks/0*.sql' ':(exclude)database/migrations/027_v6_fase1_bsa.sql' ':(exclude)database/rollbacks/027_v6_fase1_bsa.sql'") === '', 'Migrasi/rollback lama (≤ 026) tidak diubah');
$assert($diff('psb.php proses_psb.php portal_pendaftar.php biaya_psb.php cetak_biaya_psb.php lihat_berkas_psb.php admin/admin_pembayaran_psb.php admin/proses_psb_admin.php') === '', 'Halaman PSB tidak diubah');
$semua = $src('app/Bsa/BsaService.php') . $src('app/Bsa/BsaRepository.php') . $src('api/v1/index.php');
$assert(!preg_match('/\b(tarif|kuitansi_baru|bayarTagihan|pembalik\(|notifikasi_outbox)\b/i', $tanpaKomentar($src('app/Bsa/BsaService.php') . $src('app/Bsa/BsaRepository.php')))
    && !str_contains($semua, '/v6/bsa/pembayaran') && !str_contains($semua, '/v6/bsa/tarif'), 'Fase 2+ belum dimulai: tanpa tarif, pembayaran tagihan, kuitansi, pembalik, atau notifikasi');

echo PHP_EOL . '=== S-8. Operasi dan lint ===' . PHP_EOL;
foreach (['bin/v6_phase1_preflight.php', 'bin/v6_phase1_verify.php', 'bin/v6_phase1_rollback_preflight.php'] as $f) {
    $isi = $src($f);
    $assert(str_contains($isi, "PHP_SAPI !== 'cli'") && !preg_match('/\b(INSERT|UPDATE|DELETE|DROP|ALTER)\s/i', $tanpaKomentar($isi)), "$f hanya CLI dan hanya membaca");
}
$assert(str_contains($src('bin/v6_phase1_preflight.php'), 'id_fingerprints') && str_contains($src('bin/v6_phase1_verify.php'), 'Sidik jari ID'), 'Manifest memuat sidik jari ID identitas, diperiksa verifier');
$assert(str_contains($src('tests/v6_phase1_db.sh'), 'test_*_test') && str_contains($src('tests/v6_phase1_seed.php'), "'/^test_.*_test\$/'"), 'Skrip DB uji dan fixture hanya untuk test_*_test');
$berkas = ['app/Bsa/BsaService.php', 'app/Bsa/BsaRepository.php', 'app/Bsa/BsaException.php', 'app/Penugasan/PenugasanJenis.php', 'app/Audit/AuditLogger.php', 'app/Ui/Navigation.php',
    'app/Ui/NavigationContext.php', 'app/Ui/Status.php', 'app/bootstrap.php', 'api/v1/index.php', 'admin/admin_penugasan.php', 'portal/_bsa.php', 'portal/bsa.php', 'portal/bsa_santri.php',
    'portal/bsa_transaksi.php', 'portal/bsa_tagihan_buat.php', 'portal/bsa_tagihan.php', 'bin/v6_phase1_preflight.php', 'bin/v6_phase1_verify.php', 'bin/v6_phase1_rollback_preflight.php',
    'tests/v6_phase1_seed.php', 'tests/v6_phase1_worker.php', 'tests/v6_phase1_integration.php', 'tests/v6_phase1_http.php', 'tests/v6_phase1_migration.php'];
$lintGagal = [];
foreach ($berkas as $f) {
    exec('php -l ' . escapeshellarg($root . '/' . $f) . ' 2>&1', $o, $k);
    if ($k !== 0) {
        $lintGagal[] = $f;
    }
}
$assert($lintGagal === [], 'Lint ' . count($berkas) . ' berkas PHP baru/diubah' . ($lintGagal === [] ? '' : ': ' . implode(', ', $lintGagal)));

echo PHP_EOL . 'Lulus: ' . $lulus . ' · Gagal: ' . count($gagal) . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
