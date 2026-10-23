<?php

declare(strict_types=1);

/**
 * Uji statis redesain website Tahap 5 — pembinaan (V-1 s.d. V-7, M-4 · F, Z).
 *
 *   php tests/redesain_web_tahap5_static.php
 *
 * Tanpa basis data. Memeriksa sumber: lint, tanpa isian ID dan label versi,
 * batas data orang tua keputusan Z (satu kolom pengirim, penyaring SQL orang
 * tua tidak membaca sumber), penyaring kerahasiaan pada hitungan papan,
 * CSRF dan POST pada semua mutasi, serta komponen bersama baru.
 */

$root = dirname(__DIR__);
$lulus = 0;
$gagal = 0;
$assert = static function (bool $ok, string $pesan) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    $ok ? $lulus++ : $gagal++;
};
$src = static fn (string $f): string => (string) file_get_contents($root . '/' . $f);

$halaman = ['portal/v3_pelanggaran.php', 'portal/v3_pelanggaran_detail.php', 'portal/v3_konseling.php', 'portal/v3_konseling_detail.php', 'portal/v3_konseling_cetak.php',
    'portal/v3_publikasi_kelola.php', 'portal/v3_publikasi.php', 'portal/partials/v3_publikasi_konten.php', 'portal/v3_laporan.php', 'admin/admin_v3_katalog.php'];
$layanan = ['app/V3/PelanggaranRepository.php', 'app/V3/PelanggaranService.php', 'app/V3/KonselingRepository.php', 'app/V3/KonselingService.php', 'app/V3/PublikasiRepository.php',
    'app/V3/PublikasiService.php', 'app/V3/LaporanService.php', 'app/V3/KatalogService.php', 'app/Ui/functions.php', 'app/Ui/Status.php', 'app/Ui/Navigation.php',
    'tests/redesain_web_tahap5_seed.php', 'tests/redesain_web_tahap5_http.php'];

echo '=== S5-1. Lint ===' . PHP_EOL;
foreach (array_merge($halaman, $layanan) as $f) {
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($root . '/' . $f) . ' 2>&1', $out, $kode);
    $assert($kode === 0, 'php -l ' . $f);
}
exec('node --check ' . escapeshellarg($root . '/assets/ui/alhasan.js') . ' 2>&1', $out, $kode);
$assert($kode === 0, 'node --check assets/ui/alhasan.js');
exec('node --check ' . escapeshellarg($root . '/tests/browser/uji-redesain-web-tahap5.mjs') . ' 2>&1', $out, $kode);
$assert($kode === 0, 'node --check tests/browser/uji-redesain-web-tahap5.mjs');

echo PHP_EOL . '=== S5-2. Tanpa isian ID dan label versi (kriteria 1) ===' . PHP_EOL;
foreach ($halaman as $f) {
    $s = $src($f);
    $assert(preg_match('/[\'"]ID (santri|kelas|kamar|pembimbing|murobi)[\'"]\s*,\s*[\'"](number|text)/', $s) === 0
        && preg_match('/<input[^>]*type="(number|text)"[^>]*name="[a-z_]+_id"/', $s) === 0, $f . ': tanpa isian ID yang diketik');
    $assert(preg_match('/(heading|title)\'\s*=>\s*\'[^\']*V3/', $s) === 0 && !str_contains($s, 'Katalog & Ambang V3'), $f . ': judul tanpa label versi');
    $assert(!preg_match('/\balert\(|\bconfirm\(|onclick=/', $s), $f . ': tanpa alert()/confirm()/onclick bawaan');
    $assert(!str_contains($s, "'Santri #'") && !str_contains($s, 'Santri #<?=') && !str_contains($s, 'wali #<?='), $f . ': tanpa "Santri #id"/"wali #id"');
}
$lap = $src('portal/v3_laporan.php');
$assert(str_contains($lap, 'id="kelas_id" name="kelas_id"') && str_contains($lap, 'id="kamar_id" name="kamar_id"') && str_contains($lap, 'id="pembimbing_id" name="pembimbing_id"')
    && str_contains($lap, 'id="murobi_id" name="murobi_id"') && str_contains($lap, 'name="q"'), 'V-6: kelas/kamar/pembimbing/murobi berupa <select>, santri berupa pencarian nama');

echo PHP_EOL . '=== S5-3. Keputusan Z: data orang tua ===' . PHP_EOL;
$repo = $src('app/V3/PublikasiRepository.php');
preg_match('/(?:public|private) function parentFrom.*?(?=public function history)/s', $repo, $wilayah);
$assert(($wilayah[0] ?? '') !== '' && !str_contains($wilayah[0], 'v3_konseling') && !str_contains($wilayah[0], 'v3_pelanggaran'), 'Penyaring SQL orang tua (parentFrom…parentPage) tetap tidak membaca sumber');
preg_match('/function senderNames.*?\n    }\n/s', $repo, $sender);
$s = $sender[0] ?? '';
$assert($s !== '' && str_contains($s, 'COALESCE(pv.nama,pk.nama,ps.nama) AS pengirim') && substr_count($s, 'SELECT') === 1 && !preg_match('/\b(uraian|tujuan|ringkasan_internal|hasil|catatan|kerahasiaan)\b/', $s), 'senderNames hanya mengambil nama pengurus pemilik sumber');
$svc = $src('app/V3/PublikasiService.php');
$assert(str_contains($svc, "\$this->dto(\$row)+['pengirim'=>\$names[(int)\$row['id']]??'Pesantren Al Hasan']") && substr_count($svc, '->parentRows(') === 2, 'DTO orang tua hanya bertambah "pengirim" (page dan show), bawaan "Pesantren Al Hasan"');
$kon = $src('app/V3/KonselingService.php');
preg_match('/function parentSerializer.*?\n    }/s', $kon, $ps);
$assert(isset($ps[0]) && !str_contains($ps[0], 'pembimbing') && !str_contains($ps[0], 'pengirim'), 'parentSerializer (allowlist Fase 3) tidak diubah');
$v7 = $src('portal/v3_publikasi.php');
$assert(str_contains($v7, "'Informasi pembinaan · '.ah_tanggal") && str_contains($v7, "'Dari'=>\$dari(\$row)") && !preg_match('/sumber_type|pelanggaran_id|kasus_id/', $v7), 'V-7: judul bertanggal, pengirim, tanpa jenis sumber');
$assert(str_contains($v7, 'santriInScope($user,Capabilities::ORANG_TUA)'), 'V-7: nama anak dari relasi wali akun sendiri (bukan kolom baru)');

echo PHP_EOL . '=== S5-4. Kerahasiaan (kriteria 2) ===' . PHP_EOL;
$kr = $src('app/V3/KonselingRepository.php');
preg_match('/private function pageWhere.*?\n    }\n/s', $kr, $pw);
$assert(isset($pw[0]) && str_contains($pw[0], '$this->privacySql($mode,$userId)') && str_contains($kr, 'function statusCounts') && str_contains($kr, '$this->pageWhere($userId,$mode,$filters,false)'), 'Hitungan papan memakai penyaring kerahasiaan yang sama dengan daftar');
$lsvc = $src('app/V3/LaporanService.php');
$assert(substr_count($lsvc, '$this->build($user,') >= 4 && str_contains($lsvc, "k.kerahasiaan<>'Rahasia'"), 'Ringkasan laporan dihitung lewat build() yang sama (penyaring Rahasia)');
$pr = $src('app/V3/PublikasiRepository.php');
$assert(str_contains($pr, "r.kerahasiaan='Internal' OR r.kasus_pembimbing_id=(SELECT ox.pengurus_id FROM users ox WHERE ox.id=?)"), 'Daftar publikasi V-5: kasus Rahasia hanya untuk pemilik (non-admin)');
foreach (['portal/v3_pelanggaran_detail.php' => "'pelanggaran',(int)\$id", 'portal/v3_konseling_detail.php' => "'kasus',(int)\$id"] as $f => $sumber) {
    $assert(str_contains($src($f), 'v3_publikasi_service()->options($currentUser,' . $sumber . ')'), $f . ': tombol publikasi mengikuti keputusan layanan publikasi');
}

echo PHP_EOL . '=== S5-5. Mutasi tetap POST + CSRF ===' . PHP_EOL;
foreach (['portal/v3_pelanggaran.php', 'portal/v3_pelanggaran_detail.php', 'portal/v3_konseling.php', 'portal/v3_konseling_detail.php', 'portal/v3_publikasi_kelola.php', 'portal/v3_publikasi.php'] as $f) {
    $s = $src($f);
    $assert(substr_count($s, 'Csrf::requireValid') === 1 && str_contains($s, "=== 'POST'") || str_contains($s, "==='POST'"), $f . ': satu pintu POST dengan CSRF');
    $assert(!preg_match('/<form method="get"[^>]*>(?:(?!<\/form>).)*name="aksi"/s', $s), $f . ': tidak ada aksi mutasi lewat GET');
}
$assert(str_contains($src('admin/admin_v3_katalog.php'), "require_once __DIR__ . '/_guard.php'") && str_contains($src('app/V3/KatalogService.php'), "\$this->requireAdmin(\$user);\$this->repo->table(\$kind);\$limit"), 'M-4 khusus admin (guard + layanan)');
$v3 = $src('portal/v3_konseling.php') . $src('portal/v3_konseling_detail.php');
$assert(substr_count($v3, 'Csrf::requireValid') === 2 && str_contains($src('portal/v3_konseling_detail.php'), '->timeline($currentUser,(int)$id,$detail)') && str_contains($src('portal/v3_konseling_detail.php'), 'value="tambah_tautan"'), 'Penjaga statis V3 Fase 3 tetap terpenuhi');

echo PHP_EOL . '=== S5-6. Komponen & navigasi ===' . PHP_EOL;
$fn = $src('app/Ui/functions.php');
$assert(str_contains($fn, 'function ah_tingkat(') && str_contains($fn, 'function ah_stepper('), 'Komponen ah_tingkat dan ah_stepper');
$js = $src('assets/ui/alhasan.js');
$assert(str_contains($js, "qsa('[data-ah-when]')") && str_contains($js, "qsa('[data-ah-poin-preview]')"), 'JS isian bersyarat (V-4) dan pratinjau poin (V-1)');
$css = $src('assets/ui/alhasan.css');
$assert(str_contains($css, '.ah-board {') && str_contains($css, '.ah-steps {') && str_contains($css, '.ah-meter {') && str_contains($css, '.ah-cell-wrap {'), 'CSS papan, langkah, meter, sel bertingkat');
$st = $src('app/Ui/Status.php');
$assert(str_contains($st, "'dicatat' => 'info'") && str_contains($st, "'tidak hadir' => 'warn'"), 'Kamus status pembinaan');
$nav = $src('app/Ui/Navigation.php');
$assert(str_contains($nav, "self::tab('Belum dibaca', '/portal/v3_publikasi.php?baca=belum'") && str_contains($nav, "'Informasi pembinaan', '/portal/v3_publikasi.php'") && str_contains($nav, "self::tab('Laporan', '/portal/v3_laporan.php', ['v3_laporan.php'])"), 'Peta menu: F (Informasi pembinaan · Belum dibaca/Semua), murobi tab Laporan');

echo PHP_EOL . "Ringkasan: $lulus lulus, $gagal gagal" . PHP_EOL;
exit($gagal === 0 ? 0 : 1);
