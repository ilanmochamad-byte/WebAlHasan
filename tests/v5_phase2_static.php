<?php

declare(strict_types=1);

/**
 * Pemeriksaan statis PRD V5 Fase 2 — obat, rujukan, koreksi berversi, dan
 * pembaca internal.
 *
 * Tidak memerlukan basis data maupun server. Perilaku nyata diuji di
 * `tests/v5_phase2_integration.php`, `tests/v5_phase2_http.php`,
 * `tests/v5_phase2_migration.php`, dan `tests/browser/uji-v5-fase2.mjs`.
 *
 *   T-1  migrasi 025 aditif (CREATE TABLE saja) + rollback berpasangan +
 *        nomor berurutan; tanpa tabel/kolom stok; rincian dibatalkan, bukan
 *        dihapus; snapshot versi berkunci asing gabungan;
 *   T-2  layanan: urutan koreksi (penugasan → klaim → banding isi → replay →
 *        kunci kunjungan → versi → alasan → UPDATE berpenjaga versi →
 *        snapshot → audit), alasan wajib untuk Tercatat, tanpa DELETE;
 *   T-3  pembaca binaan: hanya baca, hanya Tercatat, cakupan sama dengan
 *        profil binaan (D-10) dan dipasang pada SQL per baris;
 *   T-4  web: guard bersama, halaman mutasi hanya petugas, keluaran di-escape,
 *        tombol ubah hanya bagi petugas, riwayat versi hanya petugas/admin;
 *   T-5  API: rute koreksi/hasil rujukan/riwayat versi memakai layanan sama,
 *        respons tulis tanpa isi klinis;
 *   T-6  privasi: audit hanya metadata dan kode bagian; tanpa error_log;
 *   T-7  batas fase: tanpa ringkasan wali/notifikasi (Fase 3), app/Auth dan
 *        Capabilities tidak berubah dari baseline audit Fase 1;
 *   T-8  operasi: preflight/verifier/preflight rollback 025 CLI hanya baca,
 *        skrip DB/seed uji hanya test_*_test; lint berkas PHP fase.
 *
 *   php tests/v5_phase2_static.php
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

echo '=== T-1. Migrasi 025 ===' . PHP_EOL;
$migrasi = $src('database/migrations/025_v5_fase2_rincian_kesehatan.sql');
$rollback = $src('database/rollbacks/025_v5_fase2_rincian_kesehatan.sql');
$kode = (string) preg_replace(['/--.*$/m', '/ON (UPDATE|DELETE) (CURRENT_TIMESTAMP|SET NULL)/i'], '', $migrasi);
$daftarMigrasi = array_map('basename', glob($root . '/database/migrations/*.sql') ?: []);
$assert(end($daftarMigrasi) === '025_v5_fase2_rincian_kesehatan.sql' && in_array('024_v5_fase1_kesehatan.sql', $daftarMigrasi, true), 'Migrasi 025 berurutan setelah 024 (migrasi terakhir yang ada)');
$assert(substr_count($kode, 'CREATE TABLE IF NOT EXISTS') === 4 && !preg_match('/\b(DROP|DELETE|UPDATE|TRUNCATE|ALTER|RENAME|INSERT)\b/i', $kode), 'Migrasi aditif: 4 CREATE TABLE IF NOT EXISTS, tanpa DROP/ALTER/DML (tabel 024 pun tidak diubah)');
foreach (['kesehatan_obat', 'kesehatan_rujukan', 'kesehatan_tindak_lanjut', 'kesehatan_versi_rincian'] as $t) {
    $assert(str_contains($kode, 'CREATE TABLE IF NOT EXISTS ' . $t . ' (') && str_contains($rollback, 'DROP TABLE IF EXISTS ' . $t . ';'), "Tabel $t dibuat dan dilepas rollback berpasangan");
}
$rbKode = (string) preg_replace('/--.*$/m', '', $rollback);
$assert(substr_count($rbKode, 'DROP TABLE IF EXISTS') === 4 && !preg_match('/kesehatan_(assignments|kunjungan|kunjungan_versi|idempotensi|akses_log)\s*;/', $rbKode)
    && strpos($rbKode, 'kesehatan_versi_rincian') < strpos($rbKode, 'kesehatan_obat'), 'Rollback hanya melepas 4 tabel 025 (024 tetap), urut anak → induk');
$assert(!preg_match('/\b(stok|stock|persediaan|sisa|kedaluwarsa|pembelian)\b/i', $kode), 'Tanpa tabel/kolom stok, sisa, pembelian, atau kedaluwarsa obat');
$assert(substr_count($kode, 'versi_dibatalkan INT UNSIGNED NULL') === 3 && substr_count($kode, 'REFERENCES kesehatan_kunjungan (id)') === 3, 'Rincian berversi: kolom versi_dibatalkan (tidak dihapus), induk kunjungan');
$assert(str_contains($kode, 'FOREIGN KEY (kunjungan_id, versi) REFERENCES kesehatan_kunjungan_versi (kunjungan_id, versi)') && str_contains($kode, 'UNIQUE KEY kesehatan_versi_rincian_unique (kunjungan_id, versi)')
    && str_contains($kode, 'UNIQUE KEY kesehatan_versi_rincian_idempotensi (idempotensi_id)') && str_contains($kode, 'CHECK (JSON_VALID(rincian))'), 'Snapshot rincian satu per versi (FK gabungan), terkait klaim idempotensi, JSON valid');
$assert(substr_count($kode, 'FOREIGN KEY') === 12, '12 kunci asing nyata (kunjungan, versi gabungan, idempotensi, users)');
$assert(str_contains($kode, "status = 'Menunggu hasil' AND hasil IS NULL AND tanggal_hasil IS NULL") && str_contains($kode, "(status = 'Terbuka' AND tanggal_selesai IS NULL)") && str_contains($kode, 'CHECK (jumlah IS NULL OR jumlah > 0)'),
    'CHECK status rujukan/tindak lanjut konsisten dengan isinya; jumlah obat positif');

echo PHP_EOL . '=== T-2. Layanan: koreksi berversi ===' . PHP_EOL;
$svc = $tanpaKomentar($src('app/Kesehatan/KesehatanService.php'));
$repo = $tanpaKomentar($src('app/Kesehatan/KesehatanRepository.php'));
$terapkan = substr($svc, (int) strpos($svc, 'private function terapkan('), 5200);
$assert($urut($terapkan, ['kunciPenugasanAktif($userId)', 'klaimIdempotensi(', 'hash_equals(', "'replayed' => true", 'kunciKunjungan($id)', "(int) \$baris['versi'] !== \$versi",
    'self::MIN_ALASAN', 'perbaruiKunjungan(', 'simpanVersi(', 'terapkanRincian(', 'simpanRincianVersi(', 'selesaikanIdempotensi(', "'kesehatan.kunjungan.dikoreksi'"]),
    'Urutan koreksi: penugasan → klaim → banding isi → replay → kunci kunjungan → versi → alasan → UPDATE → versi → rincian → snapshot → audit');
$assert(str_contains($terapkan, "\$kini['status'] === self::STATUS_TERCATAT && (\$alasan === null") && str_contains($terapkan, "self::STATUS_TERCATAT && \$baru['status'] === self::STATUS_DRAF"),
    'Alasan wajib untuk kunjungan Tercatat; Tercatat tidak dapat kembali Draf');
$assert(str_contains($terapkan, "'Tidak ada perubahan yang perlu disimpan.'") && str_contains($terapkan, '409'), 'Koreksi tanpa perubahan ditolak; versi lama → 409');
foreach (['koreksi', 'hasilRujukan', 'buat'] as $f) {
    $isi = substr($svc, (int) strpos($svc, 'public function ' . $f . '('), 900);
    $assert(str_contains($isi, '$this->wajibCatat($user);'), "$f(): hanya petugas (wajibCatat) sebelum apa pun");
}
$assert(str_contains($repo, 'WHERE id = ? AND versi = ?') && str_contains($repo, 'FOR UPDATE') && str_contains($repo, "LOCK IN SHARE MODE"), 'UPDATE kunjungan berpenjaga versi + kunci tulis baris + kunci baca penugasan');
$assert(!preg_match('/\bDELETE\s+FROM\s+kesehatan_/i', $repo . $svc) && substr_count($repo, 'SET versi_dibatalkan = ?') === 3, 'Tanpa DELETE rincian/kunjungan: rincian dihapus = versi_dibatalkan');
$assert(str_contains($svc, "'versi' => (int) \$this->repo->versiDariIdempotensi(\$klaim['id'])") && str_contains($repo, 'WHERE idempotensi_id = ?'), 'Replay koreksi menjawab versi yang dihasilkan klaim yang sama');
$assert(str_contains($svc, 'private const BATAS_OBAT = 20;') && str_contains($svc, "'Obat ke-' . \$n . ': jumlah wajib angka positif"), 'Validasi rincian: batas baris, jumlah positif, pesan tanpa nilai masukan');

echo PHP_EOL . '=== T-3. Pembaca pembimbing/murobi ===' . PHP_EOL;
$akses = substr($svc, (int) strpos($svc, 'public function akses('), 2400);
$assert(str_contains($akses, "'catat' => \$sumber === self::SUMBER_PETUGAS") && str_contains($akses, "'riwayat_versi' => \$pengawas || \$sumber === self::SUMBER_PETUGAS")
    && str_contains($akses, 'if ($sumber === null) {') && str_contains($akses, '$this->repo->binaanAktif($userId)'), 'Binaan hanya bila tanpa hak seluruh; catat hanya petugas; riwayat versi hanya petugas/admin');
$profil = $src('app/MasterData/ProfilSantriService.php');
$cakupan = substr($repo, (int) strpos($repo, 'public function cakupanSql('), 2400);
foreach (["bm.is_active = 1 AND bm.archived_at IS NULL", "bta.status = 'Aktif' AND bta.archived_at IS NULL", 'bs.is_active = 1 AND bs.archived_at IS NULL', 'bu.is_active = 1',
    'ba.is_active = 1 AND ba.archived_at IS NULL', 'ba.tanggal_mulai <= CURDATE() AND (ba.tanggal_selesai IS NULL OR ba.tanggal_selesai >= CURDATE())',
    "pm.id_tahun = ba.tahun_ajaran_id AND pm.id_kamar = ba.kamar_id", "bkl.is_active = 1 AND bkl.archived_at IS NULL", "pk.id_kelas = ba.kelas_id AND pk.status = 'Aktif'"] as $syarat) {
    $padan = str_replace(['bm.', 'bta.', 'bs.', 'bu.', 'ba.', 'bkl.'], ['m.', 'ta.', 's.', 'u.', 'a.', 'kl.'], $syarat);
    $assert(str_contains($cakupan, $syarat) && str_contains($profil, $padan), "Cakupan binaan sama dengan profil D-10: $padan");
}
$saring = substr($repo, (int) strpos($repo, 'public function saringBinaan('), 700);
$assert(str_contains($saring, "\"k.status = 'Tercatat' AND (\"") && str_contains($saring, "['1 = 0', []]"), 'Pembaca binaan hanya Tercatat; tanpa jenis binaan → 0 baris');
$assert(str_contains($repo, "WHERE k.id = ? AND ' . \$where") && str_contains(substr($repo, (int) strpos($repo, 'private function daftarSql('), 900), '$where[] = $saring[0];'), 'Saring dipasang pada SQL detail dan daftar (per baris)');
$detail = substr($svc, (int) strpos($svc, 'public function detail('), 1500);
$assert(str_contains($detail, "'Kunjungan tidak ditemukan atau di luar cakupan binaan Anda.', 403") && strpos($detail, 'catatAkses') < strpos($detail, "'keluhan' =>"),
    'Binaan: di luar cakupan dan tidak ada sama-sama 403; log akses sebelum isi');
$assert(str_contains($svc, "\$akses['cakupan'] === self::CAKUPAN_SELURUH && is_string(\$input['status'] ?? null)"), 'Penyaring status (draf) hanya untuk cakupan seluruh');

echo PHP_EOL . '=== T-4. Halaman web ===' . PHP_EOL;
$halaman = ['portal/kesehatan.php', 'portal/kesehatan_buat.php', 'portal/kesehatan_detail.php', 'portal/kesehatan_koreksi.php', 'portal/kesehatan_hasil_rujukan.php', 'portal/kesehatan_riwayat.php'];
foreach ($halaman as $p) {
    $isi = $src($p);
    $assert(str_contains($isi, "require_once __DIR__ . '/_kesehatan.php';") && !preg_match('/\$_(GET|POST|REQUEST)\[[^\]]+\]\s*\?>/', $isi) && !preg_match('/<\?=\s*\$[A-Za-z_]+(\[[^\]]+\])*\s*\?>/', $isi) && !preg_match('/->query\(|->prepare\(/', $isi),
        "$p: guard bersama, keluaran di-escape, tanpa SQL di halaman");
}
$formBersama = $src('portal/_kesehatan_form.php');
$assert(!preg_match('/<\?=\s*\$[A-Za-z_]+(\[[^\]]+\])*\s*\?>/', $formBersama) && !preg_match('/->query\(|->prepare\(/', $formBersama) && !preg_match('/name="[^"]*(stok|sisa|kedaluwarsa)/i', $formBersama) && str_contains($formBersama, 'Tidak ada perhitungan stok'),
    'Formulir rincian bersama: di-escape, tanpa SQL, tanpa isian stok');
foreach (['portal/kesehatan_koreksi.php' => 'Koreksi kunjungan klinik', 'portal/kesehatan_hasil_rujukan.php' => 'Catat hasil rujukan', 'portal/kesehatan_buat.php' => 'Catat kunjungan klinik'] as $p => $judul) {
    $isi = $src($p);
    $assert(str_contains($isi, "if (!\$aksesKesehatan['catat']) {\n    Denial::page('$judul'") && str_contains($isi, 'name="idempotency_key"'), "$p: hanya petugas (403 sebelum formulir), kunci idempotensi");
}
$koreksi = $src('portal/kesehatan_koreksi.php');
$assert(str_contains($koreksi, 'name="versi" value="') && str_contains($koreksi, 'Muat versi terbaru') && !str_contains($koreksi, 'ah_old_keep'), 'Koreksi: versi dibawa formulir, konflik menawarkan muat ulang, isian tidak disimpan ke sesi');
$detailWeb = $src('portal/kesehatan_detail.php');
$assert(str_contains($detailWeb, "if (\$a['catat']) {\n    \$aksi .=") && str_contains($detailWeb, "if (\$a['riwayat_versi']) {") && str_contains($detailWeb, "\$a['catat'] && \$r['status'] === KesehatanService::RUJUKAN_MENUNGGU"),
    'Detail: tombol Koreksi/Catat hasil hanya petugas, Riwayat versi hanya petugas/admin');
$nav = $src('app/Ui/Navigation.php');
$ctx = $src('app/Ui/NavigationContext.php');
$assert(str_contains($nav, "\$c->kesehatanBinaan('murobi')") && str_contains($nav, "\$c->kesehatanBinaan('pembimbing')") && str_contains($ctx, 'public function kesehatanBinaan(string $jenis): bool')
    && str_contains($ctx, 'kesehatan_service()->akses($this->user)'), 'Menu Kesehatan binaan: pembimbing (Santri binaan) dan murobi (Murobi), dari hak server');

echo PHP_EOL . '=== T-5. API ===' . PHP_EOL;
$api = $src('api/v1/index.php');
$blok = substr($api, (int) strpos($api, "if (str_starts_with(\$path, '/v5/kesehatan')) {"), 4200);
$assert(str_contains($blok, "#^/v5/kesehatan/kunjungan/(\\d+)/koreksi$#") && str_contains($blok, "#^/v5/kesehatan/kunjungan/(\\d+)/rujukan/(\\d+)/hasil$#") && str_contains($blok, "#^/v5/kesehatan/kunjungan/(\\d+)/versi$#"),
    'Rute koreksi, hasil rujukan, dan riwayat versi di bawah /v5/kesehatan');
$assert(substr_count($blok, "JsonResponse::success(['id' => \$hasil['id'], 'versi' => \$hasil['versi'], 'replayed' => \$hasil['replayed']], \$hasil['status']);") === 2
    && substr_count($blok, "\$body['idempotency_key'] ??= \$_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null;") === 3, 'Respons tulis hanya {id, versi, replayed}; kunci dari header/badan');
$assert(str_contains($blok, '$kesehatan->koreksi($user,') && str_contains($blok, '$kesehatan->hasilRujukan($user,') && str_contains($blok, '$kesehatan->riwayatVersi($user,'), 'API memakai layanan yang sama dengan web');

echo PHP_EOL . '=== T-6. Privasi ===' . PHP_EOL;
$audit = substr($terapkan, (int) strpos($terapkan, "'kesehatan.kunjungan.dikoreksi'"), 900);
$assert(!preg_match("/'(keluhan|catatan_klinis|tindakan|alasan|nama_obat|petunjuk|tujuan|hasil|uraian)' =>/", $audit) && str_contains($audit, "'bagian_berubah' => \$berubah"), 'Audit koreksi hanya metadata dan kode bagian');
$assert(str_contains($svc, "? 'isi_klinis' : \$kolom"), 'Kode bagian isi klinis disatukan (nama kolom klinis tidak masuk audit)');
$semua = $svc . $repo . $src('portal/_kesehatan.php') . $formBersama;
foreach ($halaman as $p) {
    $semua .= $src($p);
}
$assert(!preg_match('/\berror_log\s*\(/', $semua) && !preg_match('/getMessage\(\)\s*\.\s*\$/', $semua), 'Kode kesehatan tidak menulis error_log dan pesan galat tidak menyisipkan masukan');
$assert(str_contains($src('app/Audit/AuditLogger.php'), "['keluhan', 'catatan_klinis', 'tindakan', 'alasan', 'catatan']"), 'AuditLogger tetap membuang kolom klinis untuk aksi kesehatan.*');

echo PHP_EOL . '=== T-7. Batas fase ===' . PHP_EOL;
$assert(!preg_match('/\b(ringkasan_wali|notifikasi|outbox|push)\b/i', $kode) && !str_contains($svc, 'Notification') && !str_contains($svc, 'outbox'), 'Fase 3 belum dimulai: tanpa ringkasan wali, notifikasi, atau outbox');
exec('git -C ' . escapeshellarg($root) . ' diff --name-only 1852fdba5e706c602a66c026c6d7ed92fa055797 -- app/Auth app/Penugasan app/Audit 2>&1', $diff, $kodeGit);
$assert($kodeGit === 0 && $diff === [], 'app/Auth, app/Penugasan, dan AuditLogger tidak berubah dari baseline audit Fase 1 (1852fdb)');

echo PHP_EOL . '=== T-8. Operasi dan lint ===' . PHP_EOL;
foreach (['bin/v5_phase2_preflight.php', 'bin/v5_phase2_verify.php', 'bin/v5_phase2_rollback_preflight.php'] as $b) {
    $assert(str_contains($src($b), "if (PHP_SAPI !== 'cli')") && !preg_match('/\b(INSERT|UPDATE|DELETE|DROP|ALTER)\b/', (string) preg_replace("/'[^']*'|\"[^\"]*\"|\/\*.*?\*\/|\/\/.*$/ms", '', $tanpaKomentar($src($b)))), "$b: CLI saja, hanya membaca");
}
$assert(str_contains($src('tests/v5_phase2_db.sh'), 'test_*_test) ;;') && str_contains($src('tests/v5_phase2_seed.php'), "preg_match('/^test_.*_test$/'"), 'Skrip DB/seed uji hanya untuk test_*_test');
$berkas = ['app/Kesehatan/KesehatanRepository.php', 'app/Kesehatan/KesehatanService.php', 'app/Ui/Navigation.php', 'app/Ui/NavigationContext.php', 'api/v1/index.php',
    'portal/_kesehatan.php', 'portal/_kesehatan_form.php', ...$halaman, 'bin/v5_phase2_preflight.php', 'bin/v5_phase2_verify.php', 'bin/v5_phase2_rollback_preflight.php',
    'tests/v5_phase2_seed.php', 'tests/v5_phase2_worker.php', 'tests/v5_phase2_integration.php', 'tests/v5_phase2_http.php', 'tests/v5_phase2_migration.php'];
$lintGagal = [];
foreach ($berkas as $b) {
    exec('php -l ' . escapeshellarg($root . '/' . $b) . ' 2>&1', $out, $kodeLint);
    if ($kodeLint !== 0) {
        $lintGagal[] = $b;
    }
}
$assert($lintGagal === [], 'Lint ' . count($berkas) . ' berkas PHP' . ($lintGagal === [] ? '' : ': ' . implode(', ', $lintGagal)));

echo PHP_EOL . 'Lulus: ' . $lulus . ' · Gagal: ' . count($gagal) . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
