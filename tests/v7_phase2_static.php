<?php

declare(strict_types=1);

/**
 * Uji statis PRD V7 Fase 2 (tanpa basis data).
 *
 *   php tests/v7_phase2_static.php
 *
 *   T-1 migrasi 031: aditif, idempoten, tanpa ALTER tabel lama, CHECK tindakan/versi
 *   T-2 rollback 031: penjaga data sebelum DROP; hanya tabel 031
 *   T-3 layanan: urutan kunci, hak dibaca ulang sesudah kunci dan sebelum putar
 *       ulang, versi, penolakan pembimbing tanpa izin_keputusan, routing murobi V2,
 *       audit tanpa isi, tanpa notifikasi
 *   T-4 perubahan V2 terarah: pembatalan V2 menolak baris wali, tombol batal,
 *       notifikasi V2 dilewati, orang tua V2/laporan tanpa baris wali (berpenjaga skema)
 *   T-5 web/API: gerbang peran POST, CSRF, kunci idempotensi, versi, escape, rute
 *   T-6 batas Fase 2 terhadap baseline audit Fase 1
 *   T-7 lint PHP seluruh berkas baru/berubah
 */

$root = dirname(__DIR__);
$baseline = getenv('V7F2_BASELINE') ?: '192711e0b6bc2cadd46ffc79ee5dfa921f3a76d1';
$lulus = 0;
$gagal = [];
$assert = static function (bool $ok, string $pesan) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    $ok ? $lulus++ : $gagal[] = $pesan;
};
$baca = static fn (string $p): string => (string) file_get_contents($root . '/' . $p);
$tanpaKomentar = static fn (string $sql): string => (string) preg_replace('/^\s*--.*$/m', '', $sql);
/** Badan satu method (sampai deklarasi method berikutnya). */
$metode = static function (string $src, string $nama): string {
    $awal = strpos($src, 'function ' . $nama . '(');
    if ($awal === false) {
        return '';
    }
    preg_match('/\n    (public|private|protected) (static )?function /', $src, $m, PREG_OFFSET_CAPTURE, $awal + 10);

    return substr($src, $awal, isset($m[0][1]) ? $m[0][1] - $awal : null);
};
$urut = static function (string $teks, array $penanda): bool {
    $pos = -1;
    foreach ($penanda as $p) {
        $baru = strpos($teks, $p, $pos + 1);
        if ($baru === false) {
            return false;
        }
        $pos = $baru;
    }

    return true;
};

// ===================================================================== T-1
echo PHP_EOL . '=== T-1. Migrasi 031 ===' . PHP_EOL;
$migSql = $tanpaKomentar($baca('database/migrations/031_v7_fase2_tinjauan_pembimbing.sql'));
$migrasi = glob($root . '/database/migrations/*.sql');
sort($migrasi);
$assert(basename((string) end($migrasi)) === '031_v7_fase2_tinjauan_pembimbing.sql' && is_file($root . '/database/rollbacks/031_v7_fase2_tinjauan_pembimbing.sql'),
    '031 adalah migrasi terakhir dan memiliki pasangan rollback');
$assert(!preg_match('/\b(ALTER|DROP|TRUNCATE|INSERT|RENAME)\b|(?<!ON )\b(DELETE|UPDATE)\b/i', $migSql), 'Migrasi tanpa ALTER/DROP/DELETE/TRUNCATE/UPDATE/INSERT/RENAME (tabel lama tidak disentuh)');
preg_match_all('/CREATE TABLE IF NOT EXISTS (\w+)/', $migSql, $m);
$assert($m[1] === ['izin_pembimbing_tinjauan', 'izin_wali_perbaikan'] && substr_count($migSql, 'CREATE TABLE') === 2 && substr_count($migSql, 'ENGINE=InnoDB') === 2,
    'Hanya dua tabel baru InnoDB, idempoten (CREATE TABLE IF NOT EXISTS)');
$assert(str_contains($migSql, "tindakan ENUM('Minta Perbaikan','Tolak','Teruskan')") && str_contains($migSql, "status_sebelum = 'Menunggu Pembimbing'")
    && str_contains($migSql, "tindakan = 'Tolak' AND status_sesudah = 'Ditolak Pembimbing'") && str_contains($migSql, "tindakan = 'Minta Perbaikan' AND status_sesudah = 'Perlu Perbaikan Wali'"),
    'CHECK pasangan tindakan ↔ status: penolakan pembimbing selalu Ditolak Pembimbing');
$assert(str_contains($migSql, "status_sesudah = 'Diajukan' AND murobi_guru_id IS NOT NULL AND murobi_routing_kandidat = 1")
    && str_contains($migSql, "status_sesudah = 'Perlu Penetapan Admin' AND murobi_guru_id IS NULL AND murobi_routing_kandidat <> 1"),
    'CHECK penerusan: murobi tunggal ↔ Diajukan, tidak tunggal ↔ Perlu Penetapan Admin');
$assert(substr_count($migSql, 'CHECK (versi_sesudah = versi_sebelum + 1)') === 2 && str_contains($migSql, "tindakan = 'Teruskan' OR (catatan IS NOT NULL AND CHAR_LENGTH(TRIM(catatan)) >= 3)")
    && str_contains($migSql, 'CHECK (isi_hash_sebelum <> isi_hash_sesudah)') && str_contains($migSql, 'UNIQUE KEY izin_wali_perbaikan_tinjauan_unique (tinjauan_id)'),
    'CHECK versi naik satu, alasan wajib minta perbaikan/tolak, perbaikan harus mengubah isi, satu perbaikan per permintaan');
$assert(str_contains($migSql, 'murobi_guru_id INT NULL') && str_contains($migSql, 'REFERENCES guru (id)'), 'Kolom murobi bertipe sama dengan guru.id (INT) untuk kunci asing');

// ===================================================================== T-2
echo PHP_EOL . '=== T-2. Rollback 031 ===' . PHP_EOL;
$rb = $tanpaKomentar($baca('database/rollbacks/031_v7_fase2_tinjauan_pembimbing.sql'));
$assert($urut($rb, ['CREATE TEMPORARY TABLE v7_fase2_rollback_guard', 'CHECK (jumlah = 0)', 'INSERT INTO v7_fase2_rollback_guard', 'DROP TABLE IF EXISTS izin_wali_perbaikan', 'DROP TABLE IF EXISTS izin_pembimbing_tinjauan']),
    'Penjaga data dijalankan sebelum DROP; perbaikan dilepas sebelum tinjauan (urutan kunci asing)');
$assert(str_contains($rb, "operation IN ('izin.wali.tinjauan', 'izin.wali.perbaikan', 'izin.wali.batal')")
    && str_contains($rb, "asal_pengajuan = 'Wali' AND status NOT IN ('Menunggu Pembimbing', 'Perlu Penetapan Pembimbing')"),
    'Penjaga menghitung tinjauan, perbaikan, klaim Fase 2, dan pengajuan wali di luar tahap Fase 1');
$assert(!preg_match('/\b(ALTER|DELETE|TRUNCATE|UPDATE)\b/i', $rb) && preg_match_all('/DROP TABLE IF EXISTS (\w+)/', str_replace('DROP TEMPORARY TABLE IF EXISTS v7_fase2_rollback_guard', '', $rb), $d) === 2
    && $d[1] === ['izin_wali_perbaikan', 'izin_pembimbing_tinjauan'], 'Rollback tanpa ALTER/DELETE/UPDATE dan hanya melepas dua tabel 031');

// ===================================================================== T-3
echo PHP_EOL . '=== T-3. Layanan ===' . PHP_EOL;
$svc = $baca('app/Izin/IzinWaliService.php');
$repo = $baca('app/Izin/IzinWaliRepository.php');
$tinjau = $metode($svc, 'tinjau');
$perbaiki = $metode($svc, 'perbaiki');
$batalkan = $metode($svc, 'batalkan');
$assert($urut($tinjau, ['kunciAkun($userId)', 'wajibPembimbing([\'id\' => $userId])', 'idempotency->begin(', 'if ($replay !== null)', "pembimbing_sah", 'lockPengajuan($id)', 'assignmentFor($pengurusId, $santriId, $onDate, true)', 'STATUS_MENUNGGU_PEMBIMBING', "(int) \$row['version'] !== \$version"]),
    'tinjau: kunci akun → hak pembimbing dibaca ulang → klaim → putar ulang hanya bila masih sah → kunci pengajuan → penugasan dikunci & dihitung ulang → status → versi');
$assert(str_contains($tinjau, 'murobiRouter()->resolve($santriId, $onDate)') && str_contains($tinjau, 'applyTeruskan(') && str_contains($tinjau, "'routing_otomatis' : 'routing_perlu_admin'"),
    'Penerusan memakai routing murobi V2 dan peristiwa routing V2 pada baris yang sama');
$assert(!str_contains($svc, 'insertKeputusan') && !str_contains($repo, 'izin_keputusan (') && !preg_match('/INSERT INTO izin_keputusan/i', $repo),
    'Layanan V7 tidak pernah menulis izin_keputusan (penolakan pembimbing bukan keputusan murobi)');
$assert(str_contains($tinjau, 'STATUS_DITOLAK_PEMBIMBING') && !str_contains($tinjau, "'Ditolak'"), 'Tolak pembimbing → Ditolak Pembimbing, tidak pernah status Ditolak murobi');
$assert($urut($perbaiki, ['kunciAkun($userId)', 'idempotency->begin(', 'wajibPengaju($userId, $id, $onDate', 'if ($replay !== null)', 'lockSantri($santriId)', 'lockPengajuan($id)',
    'STATUS_PERLU_PERBAIKAN', "(int) \$locked['version'] !== \$version", 'permintaanPerbaikanTerbuka', 'hash_equals', 'findOverlap($santriId, $tglIzin, $tglKembali, $id)', 'applyPerbaikan', 'insertPerbaikan']),
    'perbaiki: hak wali pengaju sebelum putar ulang → kunci santri lalu pengajuan → hanya saat diminta → versi → isi berubah → benturan lintas jalur → simpan versi');
$assert($urut($batalkan, ['kunciAkun($userId)', 'idempotency->begin(', 'wajibPengaju($userId, $id, $onDate', 'if ($replay !== null)', 'lockPengajuan($id)', 'STATUS_DAPAT_DIBATALKAN', 'applyBatal(']),
    'batalkan: hak wali pengaju sebelum putar ulang → kunci pengajuan → status dapat dibatalkan → pembaruan bersyarat');
$applyBatal = $metode($repo, 'applyBatal');
$assert(str_contains($applyBatal, 'version = ?') && str_contains($applyBatal, 'status IN ($placeholders)') && str_contains($repo, "IzinRouter::STATUS_TERARAH,\n        IzinRouter::STATUS_PERLU_ADMIN,"),
    'Pembatalan bersyarat versi+status; termasuk tahap sesudah diteruskan sebelum keputusan');
foreach (['applyTinjauan', 'applyTeruskan', 'applyPerbaikan'] as $f) {
    $assert(str_contains($metode($repo, $f), "asal_pengajuan = 'Wali' AND version = ? AND status = ?"), $f . ': pembaruan bersyarat asal, versi, dan status');
}
$wajibPengaju = $metode($svc, 'wajibPengaju');
$assert(str_contains($wajibPengaju, 'waliAkun($userId, true)') && str_contains($wajibPengaju, 'relasiAktif($waliId, (int) $row[\'santri_id\'], true)')
    && str_contains($wajibPengaju, "diajukan_oleh_user_id'] !== \$userId") && str_contains($wajibPengaju, 'IzinException::forbidden'),
    'Wali pengaju = akun dan wali pengaju dengan relasi aktif berkunci baca; wali lain 403, tanpa relasi 404');
$auditBlok = implode("\n", [$tinjau, $perbaiki, $batalkan]);
preg_match_all('/auditOrFail\((.*?)\$userId\s*\);/s', $auditBlok, $audit);
$assert(count($audit[1]) === 3 && array_filter($audit[1], static fn (string $a): bool => (bool) preg_match("/'(alasan|catatan|tgl_izin|tgl_kembali)'\s*=>/", $a)) === [],
    'Tiga audit Fase 2 hanya metadata (tanpa kunci alasan, catatan, atau tanggal)');
$assert(!preg_match('/Notification|notify\(|notifikasi_outbox/', $svc . $repo), 'Tanpa notifikasi V7 (Fase 3)');
$assert(str_contains($metode($svc, 'wajibFase2'), "fase2Terpasang()") && str_contains($tinjau . $perbaiki . $batalkan, '$this->wajibFase2();'), 'Tindakan Fase 2 menjawab 503 bila 031 belum dipasang');

// ===================================================================== T-4
echo PHP_EOL . '=== T-4. Perubahan V2 terarah ===' . PHP_EOL;
$wf = $baca('app/Izin/IzinWorkflowService.php');
$assert($urut($metode($wf, 'cancel'), ['requirePending($pengajuanId)', "(string) (\$row['asal_pengajuan'] ?? 'Pengurus') === 'Wali'", 'IzinException::forbidden']),
    'Pembatalan V2 (pengurus/admin) menolak pengajuan wali (403)');
$assert(str_contains($metode($wf, 'actionsFor'), "'batalkan' => !\$legacy && !\$wali &&"), 'Tombol batal V2 tidak ditawarkan untuk pengajuan wali');
$assert($urut($metode($wf, 'notify'), ["(string) (\$pengajuan['asal_pengajuan'] ?? 'Pengurus') === 'Wali'", 'return;', 'notifications->emit(']), 'Notifikasi V2 dilewati untuk pengajuan wali (V7 Fase 3)');
foreach (['create', 'assignMurobi', 'decide', 'correctDecision'] as $f) {
    $assert(!str_contains($metode($wf, $f), "asal_pengajuan"), 'IzinWorkflowService::' . $f . ' tidak berubah perilaku untuk baris wali (aturan V2)');
}
$repoV2 = $baca('app/Izin/IzinRepository.php');
$assert(str_contains($repoV2, "if (\$this->adaAsal()) {\n                \$parts[] = \"p.asal_pengajuan = 'Pengurus'\";") && str_contains($metode($repoV2, 'adaAsal'), "Schema::hasColumn"),
    'Tampilan V2 orang tua tanpa baris wali, berpenjaga skema (tetap jalan sebelum 030)');
$laporan = $baca('app/Report/IzinReportRepository.php');
$assert(str_contains($laporan, "Schema::hasColumn(\$this->db, 'izin_pengajuan', 'asal_pengajuan')") && str_contains($laporan, "\$predikat .= \" AND p.asal_pengajuan = 'Pengurus'\";"),
    'Laporan V2 cakupan orang tua tanpa baris wali, berpenjaga skema');
$assert(str_contains($baca('app/Izin/IzinWriteRepository.php'), "'Menunggu Pembimbing', 'Perlu Penetapan Pembimbing', 'Perlu Perbaikan Wali',")
    && !str_contains($baca('app/Izin/IzinWriteRepository.php'), "'Ditolak Pembimbing'"), 'Status penahan lintas jalur tetap: Ditolak Pembimbing melepas rentang');

// ===================================================================== T-5
echo PHP_EOL . '=== T-5. Web dan API ===' . PHP_EOL;
$detail = $baca('portal/izin_wali_detail.php');
$assert(str_contains($detail, "'tinjau' => Capabilities::PENGURUS") && str_contains($detail, "'perbaiki', 'batalkan' => Capabilities::ORANG_TUA")
    && str_contains($detail, "'tetapkan_pembimbing' => Capabilities::ADMIN") && str_contains($detail, '$modeDiizinkan === null || $mode !== $modeDiizinkan'),
    'Rincian: setiap aksi POST hanya untuk peran aktif yang sesuai (server tetap memeriksa)');
$assert(substr_count($detail, '$tersembunyi(') >= 6 && str_contains($detail, 'portal_csrf()') && str_contains($detail, 'name="idempotency_key"') && str_contains($detail, 'name="version"'),
    'Setiap formulir membawa CSRF, versi, dan kunci idempotensi');
$assert(str_contains($detail, 'header(\'Location: \' . izin_wali_detail_url($id), true, 303)') && str_contains($detail, 'in_array($exception->status(), [409, 422], true)'),
    'Sukses 303 (POST/Redirect/GET); 409/422 dirender ulang dengan kunci yang sama');
preg_match_all('/<\?=\s*(.*?)\s*\?>/s', $detail, $keluaran);
/** Ganti panggilan pembungkus aman (kurung seimbang) dan cast int dengan X. */
$bersihkan = static function (string $e): string {
    foreach (['portal_e', 'izin_wali_status', 'ah_badge', 'portal_csrf', '$tersembunyi', 'izin_wali_detail_url'] as $f) {
        while (($pos = strpos($e, $f . '(')) !== false) {
            $i = $pos + strlen($f) + 1;
            for ($d = 1; $i < strlen($e) && $d > 0; $i++) {
                $d += $e[$i] === '(' ? 1 : ($e[$i] === ')' ? -1 : 0);
            }
            $e = substr($e, 0, $pos) . 'X' . substr($e, $i);
        }
    }

    return (string) preg_replace('/\(int\)\s*\$\w+(\[[^\]]*\])*|\(int\)\s*\([^)]*\)/', 'X', $e);
};
// Sisa: variabel yang digabung ke keluaran atau dikeluarkan langsung tanpa escape.
$mentah = array_filter(array_map($bersihkan, $keluaran[1]), static fn (string $e): bool => (bool) preg_match('/\.\s*\$|\$\w+(\[[^\]]*\])*\s*\.(?!=)|^\$\w+(\[[^\]]*\])*$/', $e));
$assert($mentah === [], 'Keluaran rincian di-escape (' . count($keluaran[1]) . ' ekspresi)' . ($mentah === [] ? '' : ': ' . implode(' | ', array_slice($mentah, 0, 3))));
$assert(str_contains($baca('portal/_izin_wali.php'), "header('Cache-Control: private, no-store');"), 'Halaman V7 no-store');
$api = $baca('api/v1/index.php');
foreach (["'/v7/izin/pembimbing/riwayat'", '#^/v7/izin/pengajuan/(\\d+)/tinjauan$#', '#^/v7/izin/pengajuan/(\\d+)/perbaikan$#', '#^/v7/izin/pengajuan/(\\d+)/pembatalan$#'] as $rute) {
    $assert(str_contains($api, $rute), 'Rute API ' . $rute);
}
$assert(!str_contains($api, "'/v7/izin/murobi") && !preg_match('#/v7/izin/pengajuan/\(\\\\d\+\)/keputusan#', $api), 'Tidak ada rute keputusan V7: keputusan tetap endpoint V2');
$v2Detail = $baca('portal/izin_detail.php');
$assert(str_contains($v2Detail, "izin_wali_service()->asalWali(\$iz)") && strpos($v2Detail, 'izin_service()->detail(') < strpos($v2Detail, 'asalWali('),
    'Rincian V2 menampilkan asal wali hanya sesudah hak V2 diperiksa');
$ui = $baca('portal/_ui.php');
foreach (['tinjauan_minta_perbaikan', 'tinjauan_ditolak_pembimbing', 'tinjauan_diteruskan', 'perbaikan_wali_dikirim', 'pembatalan_wali'] as $p) {
    $assert(str_contains($ui, "'$p' =>"), 'Label peristiwa ' . $p);
}

// ===================================================================== T-6
echo PHP_EOL . '=== T-6. Batas Fase 2 terhadap baseline ===' . PHP_EOL;
exec('git -C ' . escapeshellarg($root) . ' diff --name-only ' . escapeshellarg($baseline) . ' -- . 2>&1', $berubah, $kode);
exec('git -C ' . escapeshellarg($root) . ' ls-files --others --exclude-standard 2>&1', $baru);
$semua = array_values(array_unique(array_merge($berubah, $baru)));
// Audit F-1/F-3 membutuhkan koneksi transaksi yang sama untuk kunci akun V7;
// IzinWriteRepository hanya bertambah accessor db(), tanpa perubahan SQL V2.
$izin = '#^(app/Izin/(IzinWaliService|IzinWaliRepository|IzinRepository|IzinWorkflowService|IzinWriteRepository)\.php|app/Report/IzinReportRepository\.php|app/bootstrap\.php'
    . '|api/v1/index\.php|portal/(izin_wali|izin_wali_detail|izin_detail|_ui)\.php'
    . '|database/(migrations|rollbacks)/031_v7_fase2_tinjauan_pembimbing\.sql|bin/(_v7_phase2|v7_phase2_\w+)\.php'
    . '|tests/(v7_phase2_\w+\.(php|sh)|browser/uji-v7-fase2\.mjs)|docs/(phase-v7-2/.+|api-v1\.md)|AGENTS\.md|PRD-V7\.md'
    . '|docs/panduan-pengguna-v4/.+|docs/presentasi-al-hasan-v4/.+|output/.+)$#';
$luar = array_values(array_filter($semua, static fn (string $f): bool => $f !== '' && !preg_match($izin, $f)));
$assert($kode === 0 && $luar === [], 'Hanya berkas Fase 2 yang berubah dari baseline ' . substr($baseline, 0, 7) . ($luar === [] ? '' : ': ' . implode(', ', $luar)));
$terlarang = array_values(array_filter($semua, static fn (string $f): bool => (bool) preg_match('#^(app/(Auth|Penugasan|Bsa|Kesehatan|V3|V4|Psb|Notification|Ui)/|admin/|psb\.php|proses_psb\.php|portal/(bsa|kesehatan|rapor|v3_|izin_aksi)|database/migrations/0(2\d|30))#', $f)));
$assert($terlarang === [], 'Auth, Penugasan, Navigation, notifikasi, BSA/Kesehatan/V3/V4/PSB, izin_aksi, admin, dan migrasi lama tidak berubah');
$assert(array_filter($semua, static fn (string $f): bool => str_starts_with($f, 'tests/v7_phase1_') || str_starts_with($f, 'bin/v7_phase1') || str_starts_with($f, 'bin/_v7_phase1')) === [],
    'Uji dan alat Fase 1 tidak diubah (oracle historis tidak dilonggarkan)');

// ===================================================================== T-7
echo PHP_EOL . '=== T-7. Lint ===' . PHP_EOL;
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
