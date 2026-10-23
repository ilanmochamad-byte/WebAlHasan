<?php

declare(strict_types=1);

/**
 * Uji statis redesain website Tahap 7 — penilaian & rapor
 * (R-1 s.d. R-8, M-5 · U, V, W, X, Y).
 *
 *   php tests/redesain_web_tahap7_static.php
 *
 * Tanpa basis data. Memeriksa sumber: lint, layanan V4/REST/database tidak
 * berubah terhadap baseline, pembaca tampilan hanya SELECT dan dipanggil
 * SESUDAH layanan menegakkan cakupan, mutasi hanya POST + CSRF, tindakan
 * massal satu per satu (V), simpan draf + peringatan (U), tanpa impor Excel
 * (W), salin semester lalu memakai pembacaan rencana yang ada (X), rapor
 * orang tua versi terbaru (Y), jalur gambar tanda tangan berpenjaga, M-5,
 * nama bukan nomor, tanpa alert()/confirm()/handler inline.
 */

$root = dirname(__DIR__);
$baseline = getenv('TAHAP7_BASELINE') ?: '86721a2';
$lulus = 0;
$gagal = 0;
$assert = static function (bool $ok, string $pesan) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    $ok ? $lulus++ : $gagal++;
};
$src = static fn (string $f): string => (string) file_get_contents($root . '/' . $f);
$pos = static fn (string $isi, string $cari): int => ($p = strpos($isi, $cari)) === false ? PHP_INT_MAX : $p;

$halaman = ['portal/rencana_nilai.php', 'portal/nilai_semester.php', 'portal/sikap_semester.php', 'portal/verifikasi_semester.php', 'portal/rapor_semester.php',
    'portal/rapor_penanda.php', 'portal/rapor_wali.php', 'portal/rapor_ttd.php', 'portal/_rapor_ui.php', 'admin/admin_mata_pelajaran.php', 'admin/admin_penugasan.php'];
$lain = ['app/V4/RaporTampilan.php', 'app/Penugasan/PenugasanRepository.php', 'app/Penugasan/PenugasanService.php', 'app/Ui/Navigation.php', 'app/Ui/Status.php',
    'tests/redesain_web_tahap7_seed.php', 'tests/redesain_web_tahap7_http.php'];

echo '=== S7-1. Lint ===' . PHP_EOL;
foreach (array_merge($halaman, $lain) as $f) {
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($root . '/' . $f) . ' 2>&1', $out, $kode);
    $assert($kode === 0, 'php -l ' . $f);
}
foreach (['assets/ui/rapor.js', 'assets/ui/alhasan.js', 'tests/browser/uji-redesain-web-tahap7.mjs'] as $f) {
    exec('node --check ' . escapeshellarg($root . '/' . $f) . ' 2>&1', $out, $kode);
    $assert($kode === 0, 'node --check ' . $f);
}
exec('bash -n ' . escapeshellarg($root . '/tests/redesain_web_tahap7_db.sh') . ' 2>&1', $out, $kode);
$assert($kode === 0, 'bash -n tests/redesain_web_tahap7_db.sh');

echo PHP_EOL . '=== S7-2. Layanan V4, REST, dan basis data tidak berubah ===' . PHP_EOL;
$v4 = ['app/V4/NilaiService.php', 'app/V4/SikapService.php', 'app/V4/VerifikasiService.php', 'app/V4/RaporService.php', 'app/V4/BahanRepository.php',
    'app/V4/SemesterScope.php', 'app/V4/V4Access.php', 'app/V4/RaporDocument.php', 'app/V4/RaporStorage.php', 'app/V4/VerifikasiPublik.php', 'app/Auth/Capabilities.php', 'app/Penugasan/PenugasanJenis.php'];
$jalankan = static function (string $perintah) use ($root): array {
    exec('cd ' . escapeshellarg($root) . ' && ' . $perintah . ' 2>&1', $out, $kode);
    return [$kode, implode("\n", $out)];
};
[$kode] = $jalankan('git cat-file -e ' . escapeshellarg($baseline . '^{commit}'));
$assert($kode === 0, 'Baseline ' . $baseline . ' tersedia');
foreach ($v4 as $f) {
    [$kode] = $jalankan('git diff --quiet ' . escapeshellarg($baseline) . ' -- ' . escapeshellarg($f));
    $assert($kode === 0, 'Tidak berubah: ' . $f);
}
[$kode, $daftar] = $jalankan('git diff --name-only ' . escapeshellarg($baseline) . ' -- api database bin');
$assert($kode === 0 && trim($daftar) === '', 'REST (api/), migrasi/rollback (database/), dan bin/ tidak berubah');
$penRepo = $src('app/Penugasan/PenugasanRepository.php');
[$kode, $diffRepo] = $jalankan('git diff -U0 ' . escapeshellarg($baseline) . ' -- app/Penugasan/PenugasanRepository.php app/Penugasan/PenugasanService.php');
$assert(!preg_match('/^-(?!--)/m', $diffRepo) && !preg_match('/^\+.*\b(INSERT|UPDATE|DELETE)\b/m', $diffRepo), 'Penugasan: hanya penambahan baca (daftar M-5), tanpa baris lama dihapus');

// Keputusan Human Developer 23 Oktober 2026: aturan rencana disesuaikan (satu-satunya perubahan layanan V4).
$rencanaSvc = $src('app/V4/RencanaService.php');
$assert(str_contains($rencanaSvc, "throw new V4Exception('Mapel yang sudah memiliki nilai tidak dapat dihapus dari rencana.',409);")
    && str_contains($rencanaSvc, "throw new V4Exception('Target ketuntasan mapel yang sudah memiliki nilai tidak dapat diubah.',409);")
    && str_contains($rencanaSvc, "throw new V4Exception('Rencana sudah memiliki nilai; penanda tangan dan tempat diganti admin lewat penggantian beralasan.',409);"), 'RencanaService: mapel bernilai terkunci (hapus/target), penanda & tempat tetap jalur admin');
$assert(str_contains($rencanaSvc, "DELETE FROM v4_rencana_mapel WHERE id=? AND NOT EXISTS(SELECT 1 FROM v4_nilai n WHERE n.rencana_mapel_id=?)") && str_contains($rencanaSvc, "'mapel_dihapus'=>"), 'RencanaService: hapus hanya baris tanpa nilai, tercatat di audit');
$assert($pos($rencanaSvc, "FROM v4_rencana WHERE tahun_ajaran_id=? AND kelas_id=? FOR UPDATE") < $pos($rencanaSvc, "DELETE FROM v4_rencana_mapel"), 'RencanaService: hapus di bawah kunci baris induk v4_rencana (sama dengan pengisian nilai)');

echo PHP_EOL . '=== S7-3. Pembaca tampilan hanya SELECT, sesudah layanan ===' . PHP_EOL;
$tampil = $src('app/V4/RaporTampilan.php');
$assert(!preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|TRUNCATE)\b/', $tampil), 'RaporTampilan tanpa pernyataan tulis');
$assert(preg_match_all('/->prepare\(/', $tampil) === 1 && str_contains($tampil, 'bind_param'), 'RaporTampilan memakai satu jalur prepared statement');
$urutan = [
    ['portal/nilai_semester.php', '$current=$service->read($user,$mapel,0,$pengganti);', '$tampil->nilaiKelas((int)$plan[\'id\'],$ids)'],
    ['portal/sikap_semester.php', '$semua=$service->options($user,$mode);', '$tampil->sikapKelas($tahun,$ids)'],
    ['portal/verifikasi_semester.php', '$current=$service->read($user,$plan);', '$tampil->namaPengguna($orangIds)'],
    ['portal/rapor_semester.php', '$baca=$verif->read($user,$plan);', '$tampil->raporKelas((int)$rencanaRow[\'tahun_ajaran_id\'],$ids)'],
    ['portal/rapor_wali.php', '$rows=$svc->listing($user);', '$tampil->santriRapor(array_column($rows,\'id\'))'],
    ['portal/rencana_nilai.php', '$current=$service->read($user,$tahun,$kelas);', '$tampil->mapelBernilai((int)$plan[\'id\'])'],
    ['portal/rapor_penanda.php', '$svc->signers($user);', '$tampil->penandaDenganAset(true)'],
];
foreach ($urutan as [$f, $layanan, $baca]) {
    $isi = $src($f);
    $assert($pos($isi, $layanan) < $pos($isi, $baca) && $pos($isi, $baca) !== PHP_INT_MAX, $f . ': layanan menegakkan cakupan sebelum pembacaan tampilan');
}
$assert(str_contains($src('portal/sikap_semester.php'), 'if(!is_array($nilai)||!isset($boleh[$kunci]))'), 'Kisi sikap hanya menerima pasangan rencana–santri dari pilihan layanan');
$assert(str_contains($src('portal/nilai_semester.php'), "if(!in_array(\$sid,\$ids,true)){\$gagal[]='Santri #'"), 'Kisi nilai hanya menerima santri dari kelas layanan');

echo PHP_EOL . '=== S7-4. Mutasi hanya POST + CSRF ===' . PHP_EOL;
foreach (['portal/rencana_nilai.php', 'portal/nilai_semester.php', 'portal/sikap_semester.php', 'portal/verifikasi_semester.php', 'portal/rapor_semester.php', 'portal/rapor_penanda.php'] as $f) {
    $isi = $src($f);
    $p = $pos($isi, "\$_SERVER['REQUEST_METHOD']==='POST'");
    $c = $pos($isi, 'Csrf::requireValid');
    $mutasi = PHP_INT_MAX;
    foreach (['->save(', '->act(', '->changeSigners(', '->saveSigner(', '->setSignerActive('] as $m) {
        $q = strpos($isi, $m, min(strlen($isi), $p === PHP_INT_MAX ? 0 : $p));
        if ($q !== false) $mutasi = min($mutasi, $q);
    }
    $assert($p < $c && $c < $mutasi && $mutasi !== PHP_INT_MAX, $f . ': cabang POST memeriksa CSRF sebelum mutasi');
    $assert(!preg_match('/\$_GET\[[^\]]*\]\s*\)\s*\)?\s*;?\s*\$(service|svc|verif)->(save|act|changeSigners|saveSigner|setSignerActive)/', $isi), $f . ': tidak ada mutasi dari GET');
}
$mapel = $src('admin/admin_mata_pelajaran.php');
$assert(str_contains($mapel, "require_once __DIR__ . '/_guard.php';") && str_contains($mapel, "\$_SERVER['REQUEST_METHOD'] === 'POST'"), 'M-5: penjaga admin, mutasi lewat POST (CSRF di _guard)');
$assert(str_contains($src('portal/rapor_semester.php'), "if(!\$json&&\$i>=10)"), 'R-6 tanpa JavaScript paling banyak 10 rapor per kiriman (batas waktu cPanel)');

echo PHP_EOL . '=== S7-5. Keputusan U, V, W, X, Y ===' . PHP_EOL;
$js = $src('assets/ui/rapor.js');
$assert(str_contains($js, "addEventListener('beforeunload'") && str_contains($js, 'form[data-rapor-kisi]'), 'U: peringatan perubahan belum disimpan pada kisi');
foreach (['portal/nilai_semester.php', 'portal/sikap_semester.php'] as $f) {
    $assert(str_contains($src($f), 'data-rapor-kisi') && str_contains($src($f), '>Simpan draf</button>'), 'U: ' . $f . ' memakai tombol "Simpan draf"');
}
$assert(str_contains($js, 'AlHasan.bulk(') && str_contains($js, 'data.pilih = item.id') && str_contains($js, 'return AlHasan.post(url, data);'), 'V: tindakan massal dikirim satu pilihan per permintaan');
foreach (['portal/nilai_semester.php' => 'serahkan_lengkap', 'portal/sikap_semester.php' => 'diketahui', 'portal/verifikasi_semester.php' => 'verifikasi_massal', 'portal/rapor_semester.php' => 'terbit_massal'] as $f => $aksi) {
    $isi = $src($f);
    $assert(str_contains($isi, 'data-rapor-massal') && str_contains($isi, $aksi) && str_contains($isi, "if(\$json)ah_json(['ok'=>false"), 'V: ' . $f . ' — ' . $aksi . ' berjawaban JSON per pilihan');
}
foreach (['portal/nilai_semester.php', 'portal/sikap_semester.php'] as $f) {
    $assert(!preg_match('/xlsx|SheetJS|type="file"|proses_import/i', $src($f)), 'W: tanpa impor Excel (unggah berkas) di ' . $f);
}
$rencana = $src('portal/rencana_nilai.php');
$assert(str_contains($rencana, '$sumber=$service->read($user,(int)$lalu[\'id\'],$kelas);') && !str_contains(substr($rencana, $pos($rencana, '// Keputusan X'), 900), '->save('), 'X: salin memakai RencanaService::read semester lalu tanpa menyimpan');
$assert(str_contains($rencana, 'featureAppliesToKelas($user,\App\Penugasan\PenugasanJenis::CAP_RAPOR_RENCANA,$kelas,(int)$lalu[\'id\'])'), 'X: tombol salin hanya bila cakupan baca semester lalu berlaku (dalam praktik admin; keputusan 23 Okt 2026)');
$wali = $src('portal/rapor_wali.php');
$assert(str_contains($wali, 'Versi sebelumnya') && str_contains($wali, "ah_badge('Sudah diganti'") && str_contains($wali, 'menggantikan versi'), 'Y: versi terbaru + "Versi sebelumnya" + "Sudah diganti"');
$assert(!str_contains($wali, 'petugas=1') && !preg_match('/->show\([^)]*true\)/', $wali), 'Y: orang tua tetap memakai jalur non-staf RaporService::show()');

echo PHP_EOL . '=== S7-6. Gambar tanda tangan berpenjaga ===' . PHP_EOL;
$ttd = $src('portal/rapor_ttd.php');
foreach (['requireWebUser()', 'hasFeature($user,PenugasanJenis::CAP_RAPOR_RENCANA)', "if(!(bool)\$aset['berlaku']&&!\$admin)", "hash_equals((string)\$aset['sha256'],hash('sha256',\$bytes))",
    "X-Content-Type-Options: nosniff", "Cache-Control: private, no-store", "Content-Security-Policy: default-src 'none'; sandbox"] as $syarat) {
    $assert(str_contains($ttd, $syarat), 'rapor_ttd.php: ' . $syarat);
}
$assert(str_contains($src('portal/_rapor_ui.php'), "app_url('/portal/rapor_ttd.php')") && !preg_match('/storage\/private/', $src('portal/rencana_nilai.php') . $src('portal/rapor_penanda.php')), 'Halaman memakai jalur berpenjaga, bukan berkas privat');

echo PHP_EOL . '=== S7-7. M-5 dan peta menu ===' . PHP_EOL;
$nav = $src('app/Ui/Navigation.php');
$assert(str_contains($nav, "self::tab('Mata pelajaran', '/admin/admin_mata_pelajaran.php', ['admin_mata_pelajaran.php', 'admin_penugasan.php?jenis=mata_pelajaran'])"), 'Tab Mata pelajaran menuju M-5');
foreach (["self::tab('Rencana'", "self::tab('Kelengkapan'", "self::tab('Penerbitan'", "self::tab('Penanda tangan'"] as $tab) {
    $assert(str_contains($nav, $tab), 'Tab tetap: ' . $tab);
}
$pen = $src('admin/admin_penugasan.php');
$assert(str_contains($pen, "header('Location: ' . app_url('/admin/admin_mata_pelajaran.php')") && str_contains($pen, ', true, 301);') && str_contains($pen, "case 'mapel_simpan':"), 'Alamat lama mapel dialihkan 301; POST lama tetap diproses');

echo PHP_EOL . '=== S7-8. Nama bukan nomor, tanpa dialog bawaan ===' . PHP_EOL;
$semua = implode("\n", array_map($src, $halaman)) . $js;
foreach (['pelaku #', 'Admin #', 'aset #', 'murobi #', 'kapasitas #', "'#'.(int)"] as $pola) {
    $assert(!str_contains($semua, $pola), 'Tanpa "' . $pola . '" di tampilan');
}
$assert(!preg_match('/\balert\(|\bconfirm\(|\sonclick=|\sonchange=|\sonsubmit=/', $semua), 'Tanpa alert()/confirm()/handler inline');
$assert(!preg_match('/<input[^>]*name="[a-z_]*_id"[^>]*type="(?:text|number)"/', $semua) && !preg_match('/<input[^>]*type="(?:text|number)"[^>]*name="[a-z_]*_id"/', $semua), 'Tanpa isian ID yang diketik');
$assert(str_contains($src('portal/rapor_semester.php'), 'rapor_dokumen($snap)') && str_contains($wali, 'rapor_dokumen($v[\'snapshot\'])'), 'Pratinjau rapor di halaman memakai judul bertingkat turun (satu h1)');

echo PHP_EOL . "Ringkasan: {$lulus} lulus, {$gagal} gagal" . PHP_EOL;
exit($gagal === 0 ? 0 : 1);
