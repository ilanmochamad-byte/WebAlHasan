<?php

declare(strict_types=1);

/**
 * Uji statis PRD V6 Fase 2 — tagihan bulanan lengkap. Tanpa basis data.
 *
 *   php tests/v6_phase2_static.php
 *
 *   T-1  migrasi 028 aditif: 9 CREATE TABLE IF NOT EXISTS, rupiah BIGINT,
 *        tanpa tipe pecahan, InnoDB, 28 kunci asing, 22 CHECK, kunci unik
 *        tarif berlaku tunggal; tidak menyentuh tabel lama;
 *   T-2  rollback 028: penjaga data sebelum DROP, hanya sembilan tabel 028;
 *   T-3  layanan/repository: tanpa float di jalur uang, SQL berparameter,
 *        tidak membaca superglobal, pemetaan galat kunci/CHECK;
 *   T-4  urutan kunci dan pemeriksaan cakupan SEBELUM replay di setiap
 *        mutasi (pelajaran F-1 audit Fase 1);
 *   T-5  halaman web: penjaga bersama (sesi + CSRF + no-store), kunci
 *        idempotensi tersembunyi, keluaran di-escape, aksi tanpa GET;
 *   T-6  rute API Fase 2 lengkap dan memakai layanan yang sama;
 *   T-7  audit: daftar kunci `bsa.*` tanpa data uang/alasan; panggilan audit
 *        Fase 2 tidak mengirim kunci terlarang;
 *   T-8  batas Fase 2 terhadap baseline audit Fase 1: Auth/PSB/V1–V5/
 *        penugasan/notifikasi, halaman PSB, migrasi ≤ 027, preflight Fase 1
 *        tidak diubah; berkas berubah hanya dalam daftar yang diizinkan;
 *   T-9  lint seluruh berkas PHP yang ditambah/diubah.
 */

$root = dirname(__DIR__);
$baseline = getenv('V6F2_BASELINE') ?: 'e895dbe2a95ee7cb75acce9a3edbadf23c239a92';
$lulus = 0;
$gagal = [];
$assert = static function (bool $ok, string $pesan) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    $ok ? $lulus++ : $gagal[] = $pesan;
};
$baca = static fn (string $f): string => (string) @file_get_contents($root . '/' . $f);
$tanpaKomentar = static fn (string $php): string => implode('', array_map(
    static fn ($t): string => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t,
    token_get_all($php)
));

// ===================================================================== T-1
echo '=== T-1. Migrasi 028 ===' . PHP_EOL;
$mig = $baca('database/migrations/028_v6_fase2_tagihan_bulanan.sql');
$sql = (string) preg_replace(['/--.*$/m', '/ON UPDATE CURRENT_TIMESTAMP/i'], '', $mig);
$assert($mig !== '' && substr_count($sql, 'CREATE TABLE IF NOT EXISTS') === 9, 'Sembilan CREATE TABLE IF NOT EXISTS');
$assert(!preg_match('/\b(DROP|DELETE|UPDATE|TRUNCATE|ALTER|RENAME|INSERT|REPLACE)\b/i', $sql), 'Tanpa DROP/DELETE/UPDATE/ALTER/INSERT terhadap tabel mana pun');
$assert(!preg_match('/\b(FLOAT|DOUBLE|REAL|DECIMAL|NUMERIC)\b/i', $sql), 'Tanpa tipe pecahan; nominal rupiah bulat');
preg_match_all('/^\s+(nominal|total|terbayar_sebelum|sisa_sesudah|total_tagihan)\s+(\w+)/mi', $sql, $nom);
$assert($nom[2] !== [] && array_unique(array_map('strtoupper', $nom[2])) === ['BIGINT'], 'Seluruh kolom nominal BIGINT (' . count($nom[2]) . ' kolom)');
$assert(substr_count($sql, 'ENGINE=InnoDB') === 9 && substr_count($sql, 'FOREIGN KEY') === 28 && preg_match_all('/CONSTRAINT \w+ CHECK/', $sql) === 22, 'InnoDB ×9, 28 kunci asing, 22 CHECK');
preg_match_all('/CREATE TABLE IF NOT EXISTS (\w+)/', $sql, $t);
$assert(array_filter($t[1], static fn ($n) => !str_starts_with($n, 'bsa_')) === [] && array_intersect($t[1], ['bsa_tagihan', 'bsa_pembayaran', 'bsa_tagihan_komponen', 'bsa_tabungan_akun', 'bsa_tabungan_transaksi', 'bsa_idempotensi']) === [],
    'Hanya tabel baru bsa_*; enam tabel 027 tidak dibuat ulang');
$assert(str_contains($sql, "kunci_berlaku VARCHAR(40) AS (IF(status = 'Berlaku', CONCAT(jenjang, '|', berlaku_mulai), NULL)) STORED") && str_contains($sql, 'UNIQUE KEY bsa_tarif_berlaku_unique (kunci_berlaku)'),
    'Kunci unik terbangkit: satu tarif Berlaku per unit+periode mulai');
$assert(str_contains($sql, 'bsa_penerbitan_jumlah_check CHECK (jumlah_terbit > 0') && str_contains($sql, 'bsa_kuitansi_nilai_check') && str_contains($sql, 'bsa_pembatalan_alasan_check CHECK (CHAR_LENGTH(alasan) >= 5)'),
    'CHECK penerbitan, kuitansi, dan alasan pembatalan');

// ===================================================================== T-2
echo PHP_EOL . '=== T-2. Rollback 028 ===' . PHP_EOL;
$rb = (string) preg_replace('/--.*$/m', '', $baca('database/rollbacks/028_v6_fase2_tagihan_bulanan.sql'));
preg_match_all('/DROP TABLE IF EXISTS (\w+)/', $rb, $drop);
$assert(count($drop[1]) === 9 && array_intersect($drop[1], $t[1]) === $drop[1] && !preg_match('/\b(DELETE|UPDATE|TRUNCATE|ALTER)\b/i', $rb), 'Rollback melepas tepat sembilan tabel 028');
$assert(strpos($rb, 'v6_fase2_rollback_guard') !== false && strpos($rb, 'v6_fase2_rollback_guard') < strpos($rb, 'DROP TABLE IF EXISTS bsa_') && str_contains($rb, 'CHECK (jumlah = 0)')
    && str_contains($rb, '(SELECT COUNT(*) FROM bsa_pembayaran)'), 'Penjaga data (tabel 028 + bsa_pembayaran) dijalankan sebelum DROP');

// ===================================================================== T-3
echo PHP_EOL . '=== T-3. Layanan dan repository ===' . PHP_EOL;
$svc = $tanpaKomentar($baca('app/Bsa/TagihanService.php'));
$repo = $tanpaKomentar($baca('app/Bsa/TagihanRepository.php'));
$tanpaTeks = static fn (string $php): string => implode('', array_map(
    static fn ($t): string => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true) ? "''" : $t[1]) : $t,
    token_get_all($php)
));
$kodeUang = $tanpaTeks($baca('app/Bsa/TagihanService.php')) . $tanpaTeks($baca('app/Bsa/TagihanRepository.php'));
$assert(!preg_match('/\(float\)|floatval|\bround\(|\bfloor\(|\bceil\(|\b\d+\.\d+\b|\bfloat\b/', $kodeUang), 'Tanpa float, pembulatan, atau literal pecahan di kode layanan/repository tagihan');
$assert(!preg_match('/\$_(GET|POST|REQUEST|COOKIE|SERVER|SESSION)/', $svc . $repo), 'Layanan/repository tidak membaca superglobal');
$assert(!str_contains($repo, '->query(') && substr_count($repo, '$this->db->prepare(') === 1 && str_contains($repo, 'bind_param'), 'Repository hanya prepared statement (satu pintu prepare + bind_param)');
$assert(!in_array('"', array_filter(token_get_all($baca('app/Bsa/TagihanRepository.php')), 'is_string'), true), 'Tidak ada string berinterpolasi di repository (nilai selalu parameter terikat)');
$assert(str_contains($repo, '[1020, 1205, 1213]') && str_contains($repo, '[3819, 4025]'), 'Kunci/deadlock → 409, CHECK → 422 (sama dengan BsaRepository)');
$assert(str_contains($svc, "'Tinjau pratinjau penerbitan lebih dahulu.'") && str_contains($svc, 'hash_equals($rencana[\'hash\'], $hashPratinjau)'), 'Penerbitan wajib hash pratinjau dan dibandingkan aman waktu');

// ===================================================================== T-4
echo PHP_EOL . '=== T-4. Urutan kunci dan cakupan sebelum replay ===' . PHP_EOL;
$blok = static function (string $src, string $awal, string $akhir): string {
    $i = strpos($src, $awal);
    $j = $i === false ? false : strpos($src, $akhir, $i + 1);

    return $i === false || $j === false ? '' : substr($src, $i, $j - $i);
};
foreach ([
    'buatTarif' => ['function buatTarif', 'function penyesuaianSantri', 'penugasanUnit('],
    'buatPenyesuaian' => ['function buatPenyesuaian', 'function cabutPenyesuaian', 'santriDalamCakupan('],
    'cabutPenyesuaian' => ['function cabutPenyesuaian', 'function pratinjau', 'santriDalamCakupan('],
    'terbitkanMassal' => ['function terbitkanMassal', 'function penerbitan(', 'wajibUnitDalamCakupan('],
    'mutasiTagihan' => ['function mutasiTagihan', 'function sahkan', 'santriDalamCakupan('],
] as $nama => [$a, $b, $cakupan]) {
    $isi = $blok($svc, $a, $b);
    $pTugas = strpos($isi, 'penugasanTerkunci(');
    $pKlaim = strpos($isi, '->klaim(');
    $pCakup = strpos($isi, $cakupan, (int) $pKlaim);
    $pReplay = strpos($isi, "['hasil_id'] !== null");
    $assert($isi !== '' && $pTugas !== false && $pKlaim !== false && $pCakup !== false && $pReplay !== false && $pTugas < $pKlaim && $pKlaim < $pCakup && $pCakup < $pReplay,
        "$nama: penugasan (kunci baca) → klaim → cakupan/status santri → baru replay");
}
$mt = $blok($svc, 'function mutasiTagihan', 'function sahkan');
$assert(strpos($mt, 'santriDalamCakupan(') < strpos($mt, 'tagihanTerkunci('), 'mutasiTagihan: santri dikunci sebelum tagihan (urutan santri → tagihan → pembayaran)');
foreach (['verifikasiPembayaran', 'batalkanPembayaran'] as $f) {
    $isi = $blok($svc, 'function ' . $f, $f === 'verifikasiPembayaran' ? 'function batalkanPembayaran' : 'function pembayaran(');
    $assert(str_contains($isi, 'mutasiTagihan(') && str_contains($isi, 'pembayaranTerkunci('), "$f: tagihan dikunci (lewat mutasiTagihan) sebelum pembayaran");
}
$assert(str_contains($repo, "WHERE id = ? AND terbayar = ? AND status = 'Terbit'") && str_contains($repo, 'WHERE id = ? AND versi = ?'), 'Pembaruan terbayar dan versi tagihan bersyarat nilai yang dikunci');

// ===================================================================== T-5
echo PHP_EOL . '=== T-5. Halaman web ===' . PHP_EOL;
$halaman = ['bsa_tarif.php', 'bsa_penerbitan.php', 'bsa_penyesuaian.php', 'bsa_bayar.php', 'bsa_tagihan_aksi.php', 'bsa_kuitansi.php', 'bsa_tunggakan.php', 'bsa_pembayaran.php', 'bsa_tagihan.php'];
$guard = $baca('portal/_bsa.php');
$assert(str_contains($guard, 'requireWebUser()') && str_contains($guard, "Csrf::requireValid(\$_POST['_csrf'] ?? null)") && str_contains($guard, 'no-store'), 'Penjaga bersama: sesi web, CSRF untuk setiap POST, no-store');
foreach ($halaman as $h) {
    $src = $baca('portal/' . $h);
    $assert($src !== '' && str_contains($src, "require_once __DIR__ . '/_bsa.php';") && !preg_match('/<\?=\s*\$_(GET|POST)/', $src) && !preg_match('/echo\s+\$_(GET|POST)/', $src), "$h: lewat penjaga bersama, superglobal tidak dicetak mentah");
    if (preg_match_all('/<form method="post"/', $src) > 0) {
        $assert(substr_count($src, '<form method="post"') <= substr_count($src, 'ah_csrf()') && substr_count($src, '<form method="post"') <= substr_count($src, 'name="idempotency_key"'), "$h: setiap formulir POST membawa CSRF dan kunci idempotensi");
    }
}
$aksi = $baca('portal/bsa_tagihan_aksi.php');
$assert(str_contains($aksi, "!== 'POST'") && str_contains($aksi, 'http_response_code(405)'), 'bsa_tagihan_aksi.php menolak selain POST (405)');
$kw = $baca('portal/bsa_kuitansi.php');
$assert(str_contains($kw, "\$b['kuitansi']") && str_contains($kw, "!\$wali && (\$b['referensi'] ?? null) !== null") && !str_contains($kw, 'total_tagihan = '), 'Kuitansi memakai snapshot kuitansi; referensi hanya untuk internal');

// ===================================================================== T-6
echo PHP_EOL . '=== T-6. Rute API ===' . PHP_EOL;
$api = $baca('api/v1/index.php');
foreach (['/v6/bsa/tarif', '/v6/bsa/penerbitan/pratinjau', '/v6/bsa/penerbitan', '/v6/bsa/pembayaran', '/v6/bsa/tunggakan', 'penyesuaian', '(revisi|batal|pembayaran)', '(verifikasi|batal)'] as $r) {
    $assert(str_contains($api, $r), "Rute $r");
}
$assert(str_contains($api, '$tagihan = tagihan_service();') && str_contains($api, "rincianTagihan(\$user, (int) \$m[1])") && substr_count($api, "'/v6/bsa/tagihan/(\\d+)$#'") <= 1,
    'GET tagihan/{id} memakai rincianTagihan (kontrak Fase 1 + tambahan), satu rute');
$assert(str_contains($tanpaKomentar($baca('app/Bsa/TagihanService.php')), '$t = $this->bsa->tagihan($user, $id);'), 'rincianTagihan dibangun dari BsaService::tagihan (hak dan kunci Fase 1 sama)');

// ===================================================================== T-7
echo PHP_EOL . '=== T-7. Audit tanpa data uang ===' . PHP_EOL;
$logger = $baca('app/Audit/AuditLogger.php');
preg_match('/function metadataBsa.*?\$boleh = \[(.*?)\];/s', $logger, $mb);
preg_match_all("/'([a-z_]+)'/", (string) preg_replace('#//.*$#m', '', (string) ($mb[1] ?? '')), $mboleh);
$boleh = $mboleh[1];
$terlarang = ['nominal', 'total', 'terbayar', 'sisa', 'saldo', 'referensi', 'tujuan', 'catatan', 'alasan', 'nama', 'komponen'];
$assert($boleh !== [] && array_intersect(array_map('trim', $boleh), $terlarang) === [], 'Daftar kunci audit bsa.* tanpa nominal/total/sisa/referensi/alasan/nama');
preg_match_all("/wajibAudit\\('bsa\\.[a-z.]+', '\\w+', [^,]+, \\[(.*?)\\], \\\$userId\\)/s", $svc, $panggil);
$kunciAudit = [];
foreach ($panggil[1] as $isi) {
    preg_match_all("/'([a-z_]+)' =>/", $isi, $kk);
    $kunciAudit = array_merge($kunciAudit, $kk[1]);
}
$assert(count($panggil[1]) === 10 && array_intersect($kunciAudit, $terlarang) === [] && array_diff(array_unique($kunciAudit), array_map('trim', $boleh), ['tarif_diganti_id']) === [],
    'Sepuluh panggilan audit Fase 2 hanya memakai kunci metadata yang diizinkan');

// ===================================================================== T-8
echo PHP_EOL . '=== T-8. Batas Fase 2 ===' . PHP_EOL;
$git = static fn (string $args): string => trim((string) shell_exec('cd ' . escapeshellarg($root) . ' && git ' . $args . ' 2>/dev/null'));
$assert($git('cat-file -t ' . escapeshellarg($baseline)) === 'commit', 'Baseline audit Fase 1 ' . substr($baseline, 0, 7) . ' tersedia');
$diff = static fn (string $jalur): string => $git('diff --name-only ' . escapeshellarg($baseline) . ' -- ' . $jalur);
$assert($diff('app/Auth app/Psb app/Kesehatan app/V3 app/V4 app/Izin app/MasterData app/Notification app/Penugasan') === '', 'Kode Auth, PSB, V2–V5, penugasan, master data, dan notifikasi tidak diubah');
$assert($diff("'database/migrations/0*.sql' 'database/rollbacks/0*.sql' ':(exclude)database/migrations/028_v6_fase2_tagihan_bulanan.sql' ':(exclude)database/rollbacks/028_v6_fase2_tagihan_bulanan.sql'") === '',
    'Migrasi/rollback ≤ 027 tidak diubah');
$assert($diff('psb.php proses_psb.php portal_pendaftar.php biaya_psb.php cetak_biaya_psb.php lihat_berkas_psb.php admin/admin_pembayaran_psb.php admin/proses_psb_admin.php berkas_psb') === '', 'Halaman dan berkas PSB tidak diubah');
$assert($diff('bin/v6_phase1_preflight.php bin/v6_phase1_rollback_preflight.php portal/bsa_transaksi.php app/Bsa/BsaException.php') === '', 'Preflight Fase 1, halaman setoran/penarikan, dan BsaException tidak diubah');
$izin = ['AGENTS.md', 'PRD-V6.md', 'api/v1/index.php', 'assets/ui/alhasan.css', 'app/Audit/AuditLogger.php', 'app/Bsa/BsaRepository.php', 'app/Bsa/BsaService.php', 'app/Bsa/TagihanRepository.php', 'app/Bsa/TagihanService.php',
    'app/Ui/Navigation.php', 'app/Ui/Status.php', 'app/bootstrap.php', 'bin/v6_phase1_verify.php', 'bin/v6_phase2_preflight.php', 'bin/v6_phase2_rollback_preflight.php', 'bin/v6_phase2_verify.php',
    'database/migrations/028_v6_fase2_tagihan_bulanan.sql', 'database/rollbacks/028_v6_fase2_tagihan_bulanan.sql', 'docs/api-v1.md', 'docs/redesain-web/keputusan.md'];
$berubah = array_values(array_filter(explode("\n", $git('diff --name-only ' . escapeshellarg($baseline)) . "\n" . $git('ls-files --others --exclude-standard'))));
$luar = array_values(array_filter($berubah, static fn (string $f): bool => !in_array($f, $izin, true) && !str_starts_with($f, 'portal/bsa') && !str_starts_with($f, 'portal/_bsa')
    && !str_starts_with($f, 'tests/v6_phase2') && !str_starts_with($f, 'tests/browser/uji-v6-fase2') && !str_starts_with($f, 'docs/phase-v6-2/')
    && !str_starts_with($f, 'docs/panduan-pengguna-v4/') && !str_starts_with($f, 'docs/presentasi-al-hasan-v4/') && !str_starts_with($f, 'output/')));
$assert($luar === [], 'Berkas berubah hanya dalam daftar Fase 2' . ($luar === [] ? '' : ': ' . implode(', ', $luar)));
$assert(!is_dir($root . '/mobile') && $git('diff --name-only ' . escapeshellarg($baseline) . ' -- "*.tsx" "*.ts" package.json') === '', 'Tidak ada perubahan aplikasi mobile/Expo');

// ===================================================================== T-9
echo PHP_EOL . '=== T-9. Lint ===' . PHP_EOL;
$php = array_values(array_filter($berubah, static fn (string $f): bool => str_ends_with($f, '.php') && is_file($root . '/' . $f) && !str_starts_with($f, 'docs/') && !str_starts_with($f, 'output/')));
$salah = [];
foreach ($php as $f) {
    exec('php -l ' . escapeshellarg($root . '/' . $f) . ' 2>&1', $o, $kode);
    if ($kode !== 0) {
        $salah[] = $f;
    }
}
$assert($php !== [] && $salah === [], 'Lint ' . count($php) . ' berkas PHP' . ($salah === [] ? '' : ' — gagal: ' . implode(', ', $salah)));

echo PHP_EOL . 'Lulus: ' . $lulus . ' · Gagal: ' . count($gagal) . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
