<?php

declare(strict_types=1);

/**
 * Uji statis PRD V6 Fase 3 — tabungan lengkap dan pembayaran silang. Tanpa
 * basis data.
 *
 *   php tests/v6_phase3_static.php
 *
 *   U-1  migrasi 029 aditif: satu CREATE TABLE IF NOT EXISTS, rupiah BIGINT,
 *        tanpa tipe pecahan, InnoDB, 6 kunci asing, 5 CHECK; tidak menyentuh
 *        tabel lama;
 *   U-2  rollback 029: penjaga data tiga sumber sebelum DROP, satu tabel;
 *   U-3  layanan/repository: tanpa float di jalur uang, SQL berparameter,
 *        tidak membaca superglobal, pemetaan galat kunci/CHECK; ledger
 *        tabungan tidak pernah di-UPDATE/DELETE oleh kode;
 *   U-4  urutan kunci dan cakupan SEBELUM replay di setiap mutasi Fase 3;
 *        pembayaran dari tabungan: tagihan dikunci sebelum akun, debit dan
 *        pembayaran dalam satu transaksi; pembatalan mengembalikan saldo;
 *   U-5  halaman web: penjaga bersama, kunci idempotensi, escape, aksi
 *        tanpa GET, persetujuan eksplisit, kuitansi tanpa data internal wali;
 *   U-6  rute API Fase 3 lengkap dan memakai layanan yang sama;
 *   U-7  audit: kunci `bsa.*` Fase 3 tanpa data uang/alasan;
 *   U-8  batas Fase 3 terhadap baseline audit Fase 2: Auth/PSB/V1–V5/
 *        penugasan/notifikasi, halaman PSB, migrasi ≤ 028, mobile tidak
 *        diubah; berkas berubah hanya dalam daftar yang diizinkan;
 *   U-9  lint seluruh berkas PHP yang ditambah/diubah.
 */

$root = dirname(__DIR__);
$baseline = getenv('V6F3_BASELINE') ?: 'ca62c3108aafb71b0a93211b6b5d3a88d086a994';
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
$tanpaTeks = static fn (string $php): string => implode('', array_map(
    static fn ($t): string => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true) ? "''" : $t[1]) : $t,
    token_get_all($php)
));
$blok = static function (string $src, string $awal, string $akhir): string {
    $i = strpos($src, $awal);
    $j = $i === false ? false : strpos($src, $akhir, $i + 1);

    return $i === false || $j === false ? '' : substr($src, $i, $j - $i);
};

// ===================================================================== U-1
echo '=== U-1. Migrasi 029 ===' . PHP_EOL;
$mig = $baca('database/migrations/029_v6_fase3_tabungan.sql');
$sql = (string) preg_replace('/--.*$/m', '', $mig);
$assert($mig !== '' && substr_count($sql, 'CREATE TABLE IF NOT EXISTS') === 1 && str_contains($sql, 'CREATE TABLE IF NOT EXISTS bsa_setoran_transfer'), 'Satu CREATE TABLE IF NOT EXISTS bsa_setoran_transfer');
$assert(!preg_match('/\b(DROP|DELETE|UPDATE|TRUNCATE|ALTER|RENAME|INSERT|REPLACE)\b/i', $sql), 'Tanpa DROP/DELETE/UPDATE/ALTER/INSERT terhadap tabel mana pun (027/028 tidak diubah)');
$assert(!preg_match('/\b(FLOAT|DOUBLE|REAL|DECIMAL|NUMERIC)\b/i', $sql) && preg_match('/^\s+nominal BIGINT NOT NULL/m', $sql) === 1, 'Nominal BIGINT, tanpa tipe pecahan');
$assert(substr_count($sql, 'ENGINE=InnoDB') === 1 && substr_count($sql, 'FOREIGN KEY') === 6 && preg_match_all('/CONSTRAINT \w+ CHECK/', $sql) === 5, 'InnoDB, 6 kunci asing, 5 CHECK');
$assert(str_contains($sql, "CHECK ((status = 'Sah') = (transaksi_id IS NOT NULL))") && str_contains($sql, 'UNIQUE KEY bsa_setoran_transfer_transaksi_unique (transaksi_id)')
    && str_contains($sql, 'UNIQUE KEY bsa_setoran_transfer_retry_unique (dicatat_oleh, idempotency_key)') && str_contains($sql, "CHAR_LENGTH(alasan_tolak) >= 5"),
    'Sah ⇔ satu baris ledger (unik); kunci idempotensi unik per pencatat; tolak wajib beralasan');

// ===================================================================== U-2
echo PHP_EOL . '=== U-2. Rollback 029 ===' . PHP_EOL;
$rb = (string) preg_replace('/--.*$/m', '', $baca('database/rollbacks/029_v6_fase3_tabungan.sql'));
preg_match_all('/DROP TABLE IF EXISTS (\w+)/', $rb, $drop);
$assert($drop[1] === ['bsa_setoran_transfer'] && !preg_match('/\b(DELETE|UPDATE|TRUNCATE|ALTER)\b/i', $rb), 'Rollback melepas tepat satu tabel 029');
$assert(strpos($rb, 'v6_fase3_rollback_guard') !== false && strpos($rb, 'v6_fase3_rollback_guard') < strpos($rb, 'DROP TABLE IF EXISTS bsa_') && str_contains($rb, 'CHECK (jumlah = 0)')
    && str_contains($rb, 'FROM bsa_setoran_transfer)') && str_contains($rb, "jenis IN ('Belanja', 'Pembalik', 'PembayaranTagihan') OR metode = 'Internal'") && str_contains($rb, "sumber = 'Tabungan'"),
    'Penjaga data (antrean transfer, jenis ledger Fase 3, pembayaran dari tabungan) dijalankan sebelum DROP');

// ===================================================================== U-3
echo PHP_EOL . '=== U-3. Layanan dan repository ===' . PHP_EOL;
$berkas = ['app/Bsa/TabunganService.php', 'app/Bsa/TabunganRepository.php', 'app/Bsa/TagihanService.php', 'app/Bsa/BsaService.php', 'app/Bsa/BsaRepository.php'];
$kodeUang = implode('', array_map(static fn (string $f): string => $tanpaTeks($baca($f)), $berkas));
$assert(!preg_match('/\(float\)|floatval|\bround\(|\bfloor\(|\bceil\(|\b\d+\.\d+\b|\bfloat\b/', $kodeUang), 'Tanpa float, pembulatan, atau literal pecahan di kode layanan/repository BSA');
$svc = $tanpaKomentar($baca('app/Bsa/TabunganService.php'));
$repo = $tanpaKomentar($baca('app/Bsa/TabunganRepository.php'));
$tsvc = $tanpaKomentar($baca('app/Bsa/TagihanService.php'));
$assert(!preg_match('/\$_(GET|POST|REQUEST|COOKIE|SERVER|SESSION)/', $svc . $repo . $tsvc), 'Layanan/repository tidak membaca superglobal');
$assert(substr_count($repo, '$this->db->prepare(') === 1 && str_contains($repo, 'bind_param') && preg_match_all('/->query\(/', $repo) === 2
    && str_contains($repo, "->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')") && str_contains($repo, "->query('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY')"),
    'Repository tabungan: satu pintu prepare + bind_param; query langsung hanya untuk snapshot baca rekonsiliasi');
$assert(str_contains($repo, '[1020, 1205, 1213]') && str_contains($repo, '[3819, 4025]'), 'Kunci/deadlock → 409, CHECK → 422 (sama dengan BsaRepository)');
$semuaSql = $tanpaKomentar($baca('app/Bsa/BsaRepository.php')) . $tanpaKomentar($baca('app/Bsa/TagihanRepository.php')) . $repo;
$assert(!preg_match('/UPDATE\s+bsa_tabungan_transaksi|DELETE\s+FROM\s+bsa_/i', $semuaSql), 'Ledger tabungan tidak pernah di-UPDATE; tidak ada DELETE pada tabel BSA');
$assert(!preg_match('/catatTransaksi\(/', $blok($svc, 'function setoranMenunggu', 'function verifikasiSetoran')) && str_contains($blok($svc, 'function setoranMenunggu', 'function verifikasiSetoran'), 'simpanSetoranTransfer('),
    'Setoran transfer menunggu hanya menulis antrean 029, tidak menyentuh ledger/saldo');
$setoran = $blok($svc, 'function setoran(', 'function penarikan(');
$assert(str_contains($setoran, "\$metode !== 'Transfer' || \$terverifikasi") && str_contains($setoran, '$this->bsa->setoran('), 'Hanya tunai atau transfer yang dinyatakan sudah diperiksa yang sah seketika');

// ===================================================================== U-4
echo PHP_EOL . '=== U-4. Urutan kunci, cakupan sebelum replay, atomisitas ===' . PHP_EOL;
foreach ([
    'setoranMenunggu' => [$svc, 'function setoranMenunggu', 'function verifikasiSetoran'],
    'putusSetoran' => [$svc, 'function putusSetoran', 'function koreksi('],
    'koreksi' => [$svc, 'function koreksi(', 'function santri('],
] as $nama => [$src, $a, $b]) {
    $isi = $blok($src, $a, $b);
    $pTugas = strpos($isi, 'penugasanTerkunci(');
    $pKlaim = strpos($isi, '->klaim(');
    $pCakup = strpos($isi, 'santriDalamCakupan(', (int) $pKlaim);
    $pReplay = strpos($isi, "['hasil_id'] !== null");
    $assert($isi !== '' && $pTugas !== false && $pKlaim !== false && $pCakup !== false && $pReplay !== false && $pTugas < $pKlaim && $pKlaim < $pCakup && $pCakup < $pReplay,
        "$nama: penugasan (kunci baca) → klaim → cakupan/status santri → baru replay");
}
$putus = $blok($svc, 'function putusSetoran', 'function koreksi(');
$assert(strpos($putus, 'setoranTransfer($id, true)') < strpos($putus, 'kunciAkun(') && strpos($putus, 'kunciAkun(') < strpos($putus, 'catatTransaksi(') && strpos($putus, 'catatTransaksi(') < strpos($putus, 'sahkanSetoranTransfer('),
    'Verifikasi: setoran transfer (tulis) → akun (tulis) → ledger → tandai sah, satu transaksi');
$kor = $blok($svc, 'function koreksi(', 'function santri(');
$assert(strpos($kor, 'kunciAkun(') < strpos($kor, 'catatTransaksi(') && str_contains($kor, "'pembalik_dari_id' => \$transaksiId") && str_contains($kor, "\$x['jenis'] === 'PembayaranTagihan'")
    && str_contains($kor, '$saldoBaru < 0'), 'Koreksi: akun dikunci, pembalik merujuk asal, debit tagihan ditolak (lewat pembatalan), saldo tidak boleh negatif');
$bayar = $blok($tsvc, 'function bayarDariTabungan', 'function pembayaran(');
$assert(str_contains($bayar, 'mutasiTagihan(') && strpos($bayar, 'kunciAkun(') < strpos($bayar, 'catatTransaksi(') && strpos($bayar, 'catatTransaksi(') < strpos($bayar, 'simpanPembayaran(')
    && strpos($bayar, 'simpanPembayaran(') < strpos($bayar, '$this->sahkan(') && str_contains($bayar, "'tabungan_transaksi_id' => \$debit") && str_contains($bayar, "'metode' => 'Internal'"),
    'Bayar dari tabungan: lewat mutasiTagihan (tagihan dikunci dulu) → akun → debit ledger → pembayaran merujuk debit → terbayar + kuitansi, satu transaksi');
$assert(str_contains($bayar, "\$input['setuju']") && str_contains($bayar, '$nominal > $sisa - $t[\'menunggu\']') && str_contains($bayar, '$nominal > $saldo'),
    'Bayar dari tabungan: persetujuan eksplisit wajib; verifikasi sisa (dikurangi transfer menunggu) dan saldo');
$batal = $blok($tsvc, 'function batalkanPembayaran', 'function bayarDariTabungan');
$assert(str_contains($batal, "\$p['sumber'] === 'Tabungan'") && str_contains($batal, 'kembalikanKeTabungan(') && strpos($batal, 'tandaiPembayaranBatal(') < strpos($batal, 'kembalikanKeTabungan('),
    'Pembatalan pembayaran dari tabungan mengembalikan saldo di transaksi yang sama');
$assert(str_contains($tanpaKomentar($baca('app/Bsa/BsaRepository.php')), "UPDATE bsa_tabungan_akun\n                SET saldo = ?, jumlah_transaksi = jumlah_transaksi + 1, transaksi_terakhir_id = ?\n              WHERE santri_id = ? AND saldo = ?"),
    'Cerminan saldo akun diperbarui bersyarat saldo lama yang dikunci');

// ===================================================================== U-5
echo PHP_EOL . '=== U-5. Halaman web ===' . PHP_EOL;
$halaman = ['bsa_tabungan.php', 'bsa_rekonsiliasi.php', 'bsa_tabungan_aksi.php', 'bsa_tabungan_kuitansi.php', 'bsa_bayar_tabungan.php', 'bsa_transaksi.php', 'bsa_santri.php', 'bsa_tagihan.php'];
foreach ($halaman as $h) {
    $src = $baca('portal/' . $h);
    $assert($src !== '' && str_contains($src, "require_once __DIR__ . '/_bsa.php';") && !preg_match('/<\?=\s*\$_(GET|POST)/', $src) && !preg_match('/echo\s+\$_(GET|POST)/', $src), "$h: lewat penjaga bersama, superglobal tidak dicetak mentah");
    if (preg_match_all('/<form method="post"/', $src) > 0) {
        $assert(substr_count($src, '<form method="post"') <= substr_count($src, 'ah_csrf()') && substr_count($src, '<form method="post"') <= substr_count($src, 'name="idempotency_key"'), "$h: setiap formulir POST membawa CSRF dan kunci idempotensi");
    }
}
$aksi = $baca('portal/bsa_tabungan_aksi.php');
$assert(str_contains($aksi, "!== 'POST'") && str_contains($aksi, 'http_response_code(405)') && str_contains($aksi, 'bsa_wajib_fase3('), 'bsa_tabungan_aksi.php menolak selain POST (405) dan memerlukan 029');
$bt = $baca('portal/bsa_bayar_tabungan.php');
$assert(str_contains($bt, 'name="setuju"') && str_contains($bt, 'required') && str_contains($bt, 'bayarDariTabungan('), 'Formulir bayar dari tabungan memuat persetujuan eksplisit dan memanggil layanan server');
$kw = $baca('portal/bsa_tabungan_kuitansi.php');
$assert(str_contains($kw, "!\$wali && (\$x['referensi'] ?? null) !== null") && str_contains($kw, "!\$wali && (\$x['alasan'] ?? null) !== null") && str_contains($kw, '->transaksi($currentUser'),
    'Kuitansi tabungan lewat layanan (hak sama); referensi dan alasan hanya untuk internal');
$tr = $baca('portal/bsa_transaksi.php');
$assert(str_contains($tr, '$tabunganBsa->setoran(') && str_contains($tr, '$tabunganBsa->penarikan(') && !str_contains($tr, '$bsa->setoran(') && str_contains($tr, 'name="terverifikasi"'),
    'Formulir setoran/penarikan web memakai TabunganService (aturan verifikasi transfer satu sumber)');

// ===================================================================== U-6
echo PHP_EOL . '=== U-6. Rute API ===' . PHP_EOL;
$api = $baca('api/v1/index.php');
foreach (["'/v6/bsa/tabungan'", "'/v6/bsa/setoran-transfer'", '#^/v6/bsa/setoran-transfer/(\d+)/(verifikasi|tolak)$#', '#^/v6/bsa/tabungan/(\d+)$#', '#^/v6/bsa/tabungan/(\d+)/koreksi$#',
    "'/v6/bsa/rekonsiliasi'", '(revisi|batal|pembayaran|bayar-tabungan)'] as $r) {
    $assert(str_contains($api, $r), "Rute $r");
}
$assert(str_contains($api, '$tabungan = tabungan_service();') && str_contains($api, "\$tabungan->setoran(\$user, (int) \$m[1], \$body, \$kanal)") && str_contains($api, "\$tabungan->penarikan(\$user, (int) \$m[1], \$body, \$kanal)")
    && str_contains($api, "\$tabungan->santri(\$user, (int) \$m[1]") && !str_contains($api, '$bsa->setoran('), 'Setoran/penarikan/rincian santri API memakai TabunganService yang sama dengan web');

// ===================================================================== U-7
echo PHP_EOL . '=== U-7. Audit tanpa data uang ===' . PHP_EOL;
$logger = $baca('app/Audit/AuditLogger.php');
preg_match('/function metadataBsa.*?\$boleh = \[(.*?)\];/s', $logger, $mb);
preg_match_all("/'([a-z_]+)'/", (string) preg_replace('#//.*$#m', '', (string) ($mb[1] ?? '')), $mboleh);
$boleh = $mboleh[1];
$terlarang = ['nominal', 'total', 'terbayar', 'sisa', 'saldo', 'saldo_sesudah', 'referensi', 'tujuan', 'catatan', 'alasan', 'nama', 'komponen'];
$assert($boleh !== [] && array_intersect($boleh, $terlarang) === [] && in_array('setoran_transfer_id', $boleh, true) && in_array('pembalik_dari_id', $boleh, true),
    'Daftar kunci audit bsa.* bertambah ID Fase 3 saja, tanpa data uang/alasan');
$kunciAudit = [];
$jumlahPanggil = 0;
foreach ([$svc, $blok($tsvc, 'function bayarDariTabungan', 'function pembayaran('), $blok($tsvc, 'function batalkanPembayaran', 'function bayarDariTabungan')] as $src) {
    preg_match_all("/wajibAudit\\([^,]+, '\\w+', [^,]+, \\[(.*?)\\], \\\$userId\\)/s", $src, $panggil);
    $jumlahPanggil += count($panggil[1]);
    foreach ($panggil[1] as $isi) {
        preg_match_all("/'([a-z_]+)' =>/", $isi, $kk);
        $kunciAudit = array_merge($kunciAudit, $kk[1]);
    }
}
$assert($jumlahPanggil === 5 && array_intersect($kunciAudit, $terlarang) === [] && array_diff(array_unique($kunciAudit), $boleh) === [],
    'Lima panggilan audit Fase 3 (setoran menunggu, verifikasi/tolak, koreksi, bayar tabungan, pembatalan) hanya memakai kunci metadata yang diizinkan');

// ===================================================================== U-8
echo PHP_EOL . '=== U-8. Batas Fase 3 ===' . PHP_EOL;
$git = static fn (string $args): string => trim((string) shell_exec('cd ' . escapeshellarg($root) . ' && git ' . $args . ' 2>/dev/null'));
$assert($git('cat-file -t ' . escapeshellarg($baseline)) === 'commit', 'Baseline audit Fase 2 ' . substr($baseline, 0, 7) . ' tersedia');
$diff = static fn (string $jalur): string => $git('diff --name-only ' . escapeshellarg($baseline) . ' -- ' . $jalur);
$assert($diff('app/Auth app/Psb app/Kesehatan app/V3 app/V4 app/Izin app/MasterData app/Notification app/Penugasan') === '', 'Kode Auth, PSB, V2–V5, penugasan, master data, dan notifikasi tidak diubah');
$assert($diff("'database/migrations/0*.sql' 'database/rollbacks/0*.sql' ':(exclude)database/migrations/029_v6_fase3_tabungan.sql' ':(exclude)database/rollbacks/029_v6_fase3_tabungan.sql'") === '',
    'Migrasi/rollback ≤ 028 tidak diubah');
$assert($diff('psb.php proses_psb.php portal_pendaftar.php biaya_psb.php cetak_biaya_psb.php lihat_berkas_psb.php admin/admin_pembayaran_psb.php admin/proses_psb_admin.php berkas_psb') === '', 'Halaman dan berkas PSB tidak diubah');
$assert($diff('bin/v6_phase1_preflight.php bin/v6_phase1_rollback_preflight.php bin/v6_phase1_verify.php bin/v6_phase2_preflight.php bin/v6_phase2_rollback_preflight.php bin/v6_phase2_verify.php app/Bsa/BsaException.php') === '',
    'Preflight/verifier Fase 1–2 dan BsaException tidak diubah');
$izin = ['AGENTS.md', 'PRD-V6.md', 'api/v1/index.php', 'app/Audit/AuditLogger.php', 'app/Bsa/BsaRepository.php', 'app/Bsa/BsaService.php', 'app/Bsa/TagihanRepository.php', 'app/Bsa/TagihanService.php',
    'app/Bsa/TabunganRepository.php', 'app/Bsa/TabunganService.php', 'assets/ui/alhasan.css', 'app/Ui/Navigation.php', 'app/bootstrap.php', 'bin/v6_phase3_preflight.php', 'bin/v6_phase3_rollback_preflight.php', 'bin/v6_phase3_verify.php',
    'database/migrations/029_v6_fase3_tabungan.sql', 'database/rollbacks/029_v6_fase3_tabungan.sql', 'docs/api-v1.md'];
$berubah = array_values(array_filter(explode("\n", $git('diff --name-only ' . escapeshellarg($baseline)) . "\n" . $git('ls-files --others --exclude-standard'))));
$luar = array_values(array_filter($berubah, static fn (string $f): bool => !in_array($f, $izin, true) && !str_starts_with($f, 'portal/bsa') && !str_starts_with($f, 'portal/_bsa')
    && !str_starts_with($f, 'tests/v6_phase3') && !str_starts_with($f, 'tests/browser/uji-v6-fase3') && !str_starts_with($f, 'docs/phase-v6-3/')
    && !str_starts_with($f, 'docs/panduan-pengguna-v4/') && !str_starts_with($f, 'docs/presentasi-al-hasan-v4/') && !str_starts_with($f, 'output/')));
$assert($luar === [], 'Berkas berubah hanya dalam daftar Fase 3' . ($luar === [] ? '' : ': ' . implode(', ', $luar)));
$assert(!is_dir($root . '/mobile') && $git('diff --name-only ' . escapeshellarg($baseline) . ' -- "*.tsx" "*.ts" package.json') === '', 'Tidak ada perubahan aplikasi mobile/Expo');

// ===================================================================== U-9
echo PHP_EOL . '=== U-9. Lint ===' . PHP_EOL;
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
