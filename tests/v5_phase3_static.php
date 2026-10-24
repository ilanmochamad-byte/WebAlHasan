<?php

declare(strict_types=1);

/**
 * Pemeriksaan statis PRD V5 Fase 3 — ringkasan orang tua dan notifikasi.
 *
 * Tidak memerlukan basis data maupun server. Perilaku nyata diuji di
 * `tests/v5_phase3_integration.php`, `tests/v5_phase3_http.php`,
 * `tests/v5_phase3_migration.php`, dan `tests/browser/uji-v5-fase3.mjs`.
 *
 *   S-1  migrasi 026 aditif (CREATE TABLE saja) + rollback berpasangan +
 *        nomor berurutan; snapshot versi berkunci asing gabungan ke versi
 *        kunjungan sumber; jejak peristiwa terkait klaim idempotensi; tanpa
 *        tabel notifikasi baru;
 *   S-2  layanan: urutan terbit (penugasan → klaim → replay → kunci kunjungan
 *        → Tercatat/versi → kunci kepala/revisi → versi → peristiwa →
 *        penerima → outbox → audit), pratinjau wajib (hash + konfirmasi),
 *        alasan penggantian/penarikan, snapshot tidak pernah di-UPDATE teksnya;
 *   S-3  proyeksi wali: hanya tabel ringkasan + relasi wali aktif per baris,
 *        tanpa tabel klinis, isi ditarik tidak dikembalikan SQL;
 *   S-4  notifikasi: InApp selalu, Push hanya sakelar + perangkat, tanpa
 *        WhatsApp; teks generik; data hanya penunjuk; worker memeriksa ulang
 *        relasi/versi dan membuang kunci data lain;
 *   S-5  web: pratinjau + konfirmasi, halaman petugas memakai guard kesehatan,
 *        halaman wali memeriksa ulang lewat layanan, keluaran di-escape,
 *        tautan notifikasi hanya penunjuk;
 *   S-6  API: rute ringkasan memakai layanan yang sama dengan web;
 *   S-7  privasi: audit hanya metadata, tanpa error_log, koreksi klinis tidak
 *        menyentuh tabel ringkasan;
 *   S-8  batas fase: tanpa mobile/laporan Fase 4–5, app/Auth/Penugasan/Audit
 *        tidak berubah dari baseline audit Fase 2; operasi CLI hanya baca;
 *        lint.
 *
 *   php tests/v5_phase3_static.php
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
$fungsi = static function (string $kode, string $nama): string {
    $awal = strpos($kode, 'function ' . $nama . '(');
    if ($awal === false) {
        return '';
    }
    // Berakhir pada deklarasi anggota kelas berikutnya (public/private, statis atau tidak).
    $akhir = preg_match('/\n    (public|private|protected) /', $kode, $m, PREG_OFFSET_CAPTURE, $awal + 10) === 1 ? (int) $m[0][1] : null;

    return substr($kode, $awal, $akhir === null ? null : $akhir - $awal);
};
const BASELINE_FASE2 = 'c1cb262141b04791eb7870e4e8f466ae361fa0d3';

echo '=== S-1. Migrasi 026 ===' . PHP_EOL;
$migrasi = $src('database/migrations/026_v5_fase3_ringkasan_wali.sql');
$rollback = $src('database/rollbacks/026_v5_fase3_ringkasan_wali.sql');
$kode = (string) preg_replace(['/--.*$/m', '/ON (UPDATE|DELETE) (CURRENT_TIMESTAMP|SET NULL)/i'], '', $migrasi);
$daftarMigrasi = array_map('basename', glob($root . '/database/migrations/*.sql') ?: []);
$assert(end($daftarMigrasi) === '026_v5_fase3_ringkasan_wali.sql' && in_array('025_v5_fase2_rincian_kesehatan.sql', $daftarMigrasi, true), 'Migrasi 026 berurutan setelah 025 (migrasi terakhir yang ada)');
$assert(substr_count($kode, 'CREATE TABLE IF NOT EXISTS') === 4 && !preg_match('/\b(DROP|DELETE|UPDATE|TRUNCATE|ALTER|RENAME|INSERT)\b/i', $kode), 'Migrasi aditif: 4 CREATE TABLE IF NOT EXISTS, tanpa DROP/ALTER/DML');
$tabelBaru = ['kesehatan_ringkasan', 'kesehatan_ringkasan_versi', 'kesehatan_ringkasan_peristiwa', 'kesehatan_ringkasan_penerima'];
foreach ($tabelBaru as $t) {
    $assert(str_contains($kode, 'CREATE TABLE IF NOT EXISTS ' . $t . ' (') && str_contains($rollback, 'DROP TABLE IF EXISTS ' . $t . ';'), "Tabel $t dibuat dan dilepas rollback berpasangan");
}
$rbKode = (string) preg_replace('/--.*$/m', '', $rollback);
$assert(substr_count($rbKode, 'DROP TABLE IF EXISTS') === 4 && !preg_match('/(kesehatan_(kunjungan|obat|rujukan|tindak_lanjut|versi_rincian|idempotensi|akses_log)|notifikasi_\w+)\s*;/', $rbKode)
    && strpos($rbKode, 'kesehatan_ringkasan_penerima') < strpos($rbKode, 'kesehatan_ringkasan_peristiwa') && strpos($rbKode, 'kesehatan_ringkasan_versi') < strpos($rbKode, 'DROP TABLE IF EXISTS kesehatan_ringkasan;'),
    'Rollback hanya melepas 4 tabel 026 (024–025 dan notifikasi tetap), urut anak → induk');
$assert(!preg_match('/CREATE TABLE IF NOT EXISTS notifikasi|perangkat_push|pengaturan_notifikasi/', $kode), 'Tanpa tabel/kolom notifikasi baru: outbox V2 dipakai ulang');
$assert(str_contains($kode, 'FOREIGN KEY (kunjungan_id, kunjungan_versi) REFERENCES kesehatan_kunjungan_versi (kunjungan_id, versi)') && str_contains($kode, 'UNIQUE KEY kesehatan_ringkasan_kunjungan_unique (kunjungan_id)')
    && str_contains($kode, 'UNIQUE KEY kesehatan_ringkasan_versi_unique (ringkasan_id, versi)'), 'Satu kepala per kunjungan; versi unik; snapshot menunjuk versi kunjungan sumber (FK gabungan)');
$assert(str_contains($kode, 'UNIQUE KEY kesehatan_ringkasan_peristiwa_idempotensi (idempotensi_id)') && str_contains($kode, 'REFERENCES kesehatan_idempotensi (id)')
    && str_contains($kode, "CHECK (jenis = 'Terbit' OR alasan IS NOT NULL)"), 'Peristiwa terkait satu klaim idempotensi; Ganti/Tarik wajib alasan (CHECK)');
$assert(str_contains($kode, "(status = 'Terbit' AND versi_berlaku IS NOT NULL AND versi_berlaku = versi_terakhir)") && str_contains($kode, "OR (status = 'Ditarik' AND versi_berlaku IS NULL)")
    && str_contains($kode, 'revisi >= versi_terakhir'), 'CHECK kepala: Terbit = versi terakhir berlaku, Ditarik tanpa versi berlaku, revisi monoton');
$assert(substr_count($kode, 'FOREIGN KEY') === 14, '14 kunci asing nyata (kunjungan, santri, versi gabungan, idempotensi, users, wali)');
$assert(!preg_match('/\b(keluhan|catatan_klinis|nama_obat|petunjuk|tujuan|hasil|uraian)\b/', $kode), 'Tabel ringkasan tidak memiliki kolom klinis (keluhan, catatan, obat, rujukan, tindak lanjut internal)');

echo PHP_EOL . '=== S-2. Layanan publikasi ===' . PHP_EOL;
$svc = $tanpaKomentar($src('app/Kesehatan/RingkasanService.php'));
$repo = $tanpaKomentar($src('app/Kesehatan/RingkasanRepository.php'));
$terbit = $fungsi($svc, 'terbitkan');
$assert($urut($terbit, ['$this->wajibCatat($user);', "\$input['konfirmasi']", '$this->hash($kunjunganId, $data)', 'hash_equals($hash, $dariPratinjau)', 'transaction(',
    'kunciPenugasanAktif($userId)', 'klaimIdempotensi($userId, self::OPERASI_TERBIT', 'hash_equals($klaim', "'replayed' => true", 'kunciKunjungan($kunjunganId)', 'wajibTercatat($baris)',
    "(int) \$baris['versi'] !== \$data['kunjungan_versi']", 'santriAktif(', 'kunciKepala($kunjunganId)', 'wajibRevisi($kepala', 'wajibAlasanPenggantian($kepala', 'simpanVersi(', 'penerimaBerhak($santriId)',
    'simpanPeristiwa(', 'simpanPenerima(', '$this->antrekan(', 'selesaikanIdempotensi(', "'kesehatan.ringkasan.terbit'"]),
    'Urutan terbit: hak → konfirmasi → hash pratinjau → penugasan → klaim → replay → kunci kunjungan → Tercatat/versi → santri aktif → kepala/revisi → alasan → versi → penerima → peristiwa → outbox → idempotensi → audit');
$assert(str_contains($terbit, "akhiriVersi(\$ringkasanId, (int) \$kepala['versi_berlaku'], 'Diganti')") && str_contains($terbit, 'perbaruiKepala($ringkasanId, \'Terbit\''), 'Penggantian: versi lama menjadi Diganti, kepala menunjuk versi baru');
$tarik = $fungsi($svc, 'tarik');
$assert($urut($tarik, ['$this->wajibCatat($user);', 'self::MIN_ALASAN', 'kunciPenugasanAktif($userId)', 'klaimIdempotensi($userId, self::OPERASI_TARIK', 'kunciKunjungan(', 'kunciKepala(',
    "\$kepala['status'] !== 'Terbit'", 'wajibRevisi(', "akhiriVersi(\$ringkasanId, \$versi, 'Ditarik')", "perbaruiKepala(\$ringkasanId, 'Ditarik', null", "simpanPeristiwa(\$ringkasanId, \$versiId, 'Tarik', \$alasan, 0",
    "'kesehatan.ringkasan.ditarik'"]) && !str_contains($tarik, 'antrekan('), 'Penarikan: alasan wajib, berurutan dengan kunci, snapshot tetap, tanpa notifikasi baru');
$pratinjau = $fungsi($svc, 'pratinjau');
$assert(str_contains($pratinjau, '$this->wajibCatat($user);') && !preg_match('/->(simpan|perbarui|akhiri|catat)\w*\(|transaction\(|antrekan\(/', $pratinjau) && str_contains($pratinjau, "'pratinjau_hash' => \$this->hash("),
    'Pratinjau: hanya petugas, tanpa menulis apa pun, menghasilkan hash yang wajib dibawa penerbitan');
$assert(str_contains($pratinjau, '$this->proyeksi(') && str_contains($fungsi($svc, 'detailWali'), '$this->proyeksi(') && str_contains($fungsi($svc, 'internal'), '$this->proyeksi('),
    'Pratinjau, versi berlaku internal, dan detail wali memakai satu proyeksi yang sama');
$assert(preg_match_all('/UPDATE kesehatan_ringkasan_versi SET ([^\n]+)/', $repo, $m) === 1 && str_contains($m[1][0], 'status = ?, berakhir_pada = NOW()') && !preg_match('/ringkasan\s*=|tindakan_umum\s*=|tindak_lanjut_wali\s*=/', $m[1][0]),
    'Snapshot versi tidak pernah diperbarui teksnya (satu-satunya UPDATE: status + waktu berakhir)');
$assert(!preg_match('/\bDELETE\s+FROM\b/i', $repo . $svc), 'Tanpa DELETE ringkasan/versi/peristiwa');
$assert(str_contains($repo, 'WHERE id = ? AND revisi = ?') && str_contains($repo, 'WHERE kunjungan_id = ? FOR UPDATE'), 'Kepala berpenjaga revisi (optimistic) + kunci tulis');
foreach (['terbitkan', 'tarik', 'pratinjau'] as $f) {
    $assert(str_starts_with(trim(substr($fungsi($svc, $f), (int) strpos($fungsi($svc, $f), '{') + 1, 60)), '$this->wajibCatat($user);'), "$f(): hanya petugas (wajibCatat) sebelum apa pun");
}
$wajibCatat = $fungsi($svc, 'wajibCatat');
$assert(str_contains($wajibCatat, "if (\$akses['catat']) {") && str_contains($wajibCatat, "'Admin hanya membaca ringkasan wali dan tidak menerbitkannya.'"), 'Admin dan pembaca binaan ditolak menerbitkan; catat = petugas saja');
$assert(str_contains($svc, 'private const MIN_ALASAN = 5;') && str_contains($fungsi($svc, 'wajibAlasanPenggantian'), '$kepala !== null && ($alasan === null'), 'Penggantian/penerbitan ulang wajib alasan ≥ 5 karakter');

echo PHP_EOL . '=== S-3. Proyeksi wali ===' . PHP_EOL;
$waliKueri = substr($repo, (int) strpos($repo, 'public function waliAktif('));
$assert(!preg_match('/kesehatan_(kunjungan|obat|rujukan|tindak_lanjut|versi_rincian|kunjungan_versi)\b/', $waliKueri), 'Kueri proyeksi wali tidak menyentuh tabel klinis mana pun');
$syarat = $fungsi($repo, 'waliSyarat');
foreach (['wu.is_active = 1', 'ww.is_active = 1 AND ww.archived_at IS NULL', 's.is_active = 1 AND s.archived_at IS NULL', 'wsw.archived_at IS NULL', "wr.slug = 'orang_tua'"] as $s) {
    $assert(str_contains($syarat, $s), "Relasi wali aktif per baris: $s");
}
$assert(str_contains($fungsi($repo, 'waliFrom'), '$this->waliSyarat()') && str_contains($fungsi($repo, 'waliDetail'), "\$this->waliFrom() . ' AND g.id = ?'"), 'Detail dan daftar wali memakai syarat relasi yang sama (ID tebakan tetap tersaring)');
$assert(substr_count($fungsi($repo, 'waliDetail'), "CASE WHEN g.status = \\'Terbit\\' THEN v.") === 3, 'Teks ringkasan ditarik tidak dikembalikan SQL');
$detailWali = $fungsi($svc, 'detailWali');
$assert(str_contains($detailWali, '$this->wajibWali($user);') && str_contains($detailWali, "'baca_ringkasan'") && str_contains($detailWali, ', 403)'), 'Detail wali: akun wali aktif, tidak berhak = 403, pembukaan tercatat (metadata)');
$proyeksi = $fungsi($svc, 'proyeksi');
$assert(!preg_match("/'(keluhan|catatan_klinis|tindakan|obat|rujukan|petugas|diterbitkan_oleh|alasan)'/", $proyeksi), 'Proyeksi wali tanpa kolom klinis, nama petugas, atau alasan');

echo PHP_EOL . '=== S-4. Notifikasi ===' . PHP_EOL;
$antre = $fungsi($svc, 'antrekan');
$assert(str_contains($antre, "\$pengaturan['push_enabled'] === true ? \$this->devices->usersWithActiveDevice(\$userIds) : []") && str_contains($antre, '$kanal = [NotificationChannel::IN_APP];')
    && !str_contains($antre, 'WHATSAPP') && !str_contains($antre, 'WhatsApp'), 'InApp selalu; Push hanya bila sakelar menyala dan ada perangkat aktif; WhatsApp tidak pernah diantrekan');
$assert(str_contains($antre, "\$id === null && !\$this->notifikasi->exists(\$key, \$k, \$userId)") && str_contains($antre, 'throw new KesehatanException'), 'Outbox gagal ditulis → publikasi dibatalkan (bukan hilang diam-diam)');
$assert(str_contains($antre, "json_encode(['tipe' => self::TIPE_TAUTAN, 'ringkasan_id' => \$ringkasanId]"), 'Data notifikasi hanya penunjuk {tipe, ringkasan_id}');
$teks = $fungsi($svc, 'teksNotifikasi');
preg_match_all('/\$[A-Za-z_]+/', $teks, $varTeks);
$assert($teks !== '' && array_unique($varTeks[0]) === ['$eventType'] && !preg_match('/santri|nama|obat|rujukan|keluhan/i', $teks), 'Teks notifikasi berupa kalimat tetap tanpa variabel data (nama/isi) maupun kata klinis');
$assert(str_contains($svc, "return 'v5:kesehatan:ringkasan:' . \$ringkasanId . ':v' . \$versi;"), 'Kunci peristiwa deterministik per ringkasan × versi (deduplikasi outbox)');
$disp = $tanpaKomentar($src('app/Notification/NotificationDispatcher.php'));
$blokDisp = substr($disp, (int) strpos($disp, 'in_array((string) $row[\'event_type\'], RingkasanService::EVENTS, true)'), 1800);
$assert(str_contains($blokDisp, "\$ringkasan['status'] !== 'Terbit'") && str_contains($blokDisp, 'RingkasanService::eventKey($rid, (int) $ringkasan[\'versi_berlaku\'])')
    && str_contains($blokDisp, 'waliSantri((int) $ringkasan[\'santri_id\'])') && str_contains($blokDisp, "'AKSES_BERUBAH'") && str_contains($blokDisp, 'RingkasanService::teksNotifikasi('),
    'Worker Push: memeriksa ulang status/versi/relasi wali sebelum provider; teks dirakit ulang dari katalog');
$assert(str_contains($disp, "\$data = ['tipe' => RingkasanService::TIPE_TAUTAN, 'ringkasan_id' => \$rid];"), 'Payload Push V5 hanya {tipe, ringkasan_id}');
$assert(str_contains($src('app/Notification/NotificationRepository.php'), "'kesehatan' => 'v5%'") && str_contains($src('app/Notification/NotificationCenterService.php'), "'ringkasan_id' => isset(\$data['ringkasan_id'])"),
    'Pusat notifikasi: kategori Kesehatan dan penunjuk ringkasan_id (aditif)');

echo PHP_EOL . '=== S-5. Halaman web ===' . PHP_EOL;
$petugasWeb = $src('portal/kesehatan_ringkasan.php');
$waliWeb = $src('portal/kesehatan_wali.php');
$partial = $src('portal/partials/kesehatan_ringkasan_konten.php');
$escape = static fn (string $isi): bool => !preg_match('/\$_(GET|POST|REQUEST)\[[^\]]+\]\s*\?>/', $isi) && !preg_match('/<\?=\s*\$[A-Za-z_]+(\[[^\]]+\])*\s*\?>/', $isi) && !preg_match('/->query\(|->prepare\(/', $isi);
$assert(str_contains($petugasWeb, "require_once __DIR__ . '/_kesehatan.php';") && $escape($petugasWeb), 'Halaman petugas: guard kesehatan bersama (sesi, CSRF, no-store), keluaran di-escape, tanpa SQL');
$assert(str_contains($petugasWeb, 'name="konfirmasi" value="1" required') && str_contains($petugasWeb, 'name="pratinjau_hash"') && str_contains($petugasWeb, 'name="idempotency_key"')
    && str_contains($petugasWeb, "require __DIR__ . '/partials/kesehatan_ringkasan_konten.php';"), 'Penerbitan web: pratinjau persis tampilan wali, konfirmasi eksplisit, hash, kunci idempotensi');
$assert(str_contains($petugasWeb, 'name="alasan"') && str_contains($petugasWeb, "'tarik'") && str_contains($petugasWeb, 'Alasan penarikan'), 'Penggantian dan penarikan web beralasan');
$assert(!preg_match("/\\\$info\['kunjungan'\]\['(keluhan|catatan_klinis|tindakan)'\]|kesehatan_service\(\)->detail\(/", $petugasWeb), 'Formulir ringkasan tidak diisi dari catatan klinis');
$assert(str_contains($waliWeb, 'authorization()->requireWebUser();') && str_contains($waliWeb, "header('Cache-Control: private, no-store, max-age=0');") && str_contains($waliWeb, '->detailWali($currentUser, $id')
    && str_contains($waliWeb, '->daftarWali($currentUser') && $escape($waliWeb), 'Halaman wali: masuk wajib (tautan notifikasi sesudah logout → login), no-store, setiap tampilan lewat layanan, di-escape');
$assert($escape($partial) && !preg_match('/keluhan|catatan_klinis|obat|rujukan|petugas/i', (string) preg_replace('/\/\*.*?\*\//s', '', $partial)), 'Partial tampilan wali: di-escape, tanpa kolom klinis/nama petugas');
$notif = $src('portal/notifikasi.php');
$assert(str_contains($notif, "'kesehatan_ringkasan' && (int) (\$tautan['ringkasan_id'] ?? 0) > 0") && str_contains($notif, "app_url('/portal/kesehatan_wali.php?id=' . (int) \$tautan['ringkasan_id'])"),
    'Tautan notifikasi hanya penunjuk ke halaman wali yang memeriksa ulang relasi');
$nav = $src('app/Ui/Navigation.php');
$assert(str_contains($nav, '$c->kesehatanWali()') && str_contains($nav, "'kesehatan_ringkasan.php'") && str_contains($src('app/Ui/NavigationContext.php'), 'kesehatan_ringkasan_service()->aksesWali($this->user)'),
    'Menu: Kesehatan anak bagi wali aktif (dari hak server); halaman ringkasan di bawah menu Kesehatan');

echo PHP_EOL . '=== S-6. API ===' . PHP_EOL;
$api = $src('api/v1/index.php');
$blok = substr($api, (int) strpos($api, '$ringkasan = kesehatan_ringkasan_service();'), 2600);
foreach (["'/v5/kesehatan/wali/akses'", "'/v5/kesehatan/wali/ringkasan'", '#^/v5/kesehatan/wali/ringkasan/(\d+)$#', '#^/v5/kesehatan/kunjungan/(\d+)/ringkasan$#',
    '#^/v5/kesehatan/kunjungan/(\d+)/ringkasan/pratinjau$#', '#^/v5/kesehatan/kunjungan/(\d+)/ringkasan(/tarik)?$#'] as $rute) {
    $assert(str_contains($blok, $rute), 'Rute ' . $rute);
}
$assert(str_contains($blok, '$ringkasan->terbitkan($user,') && str_contains($blok, '$ringkasan->tarik($user,') && str_contains($blok, '$ringkasan->detailWali($user,') && str_contains($blok, "\$body['idempotency_key'] ??= \$_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null;"),
    'API memakai layanan yang sama dengan web; kunci idempotensi dari badan/header');
$assert(strpos($api, '$ringkasan = kesehatan_ringkasan_service();') > strpos($api, "if (str_starts_with(\$path, '/v5/kesehatan')) {") && str_contains(substr($api, (int) strpos($api, "if (str_starts_with(\$path, '/v5/kesehatan')) {"), 300), "header('Cache-Control: private, no-store');"),
    'Rute ringkasan di bawah blok /v5/kesehatan (no-store)');

echo PHP_EOL . '=== S-7. Privasi ===' . PHP_EOL;
foreach (["'kesehatan.ringkasan.terbit'", "'kesehatan.ringkasan.ditarik'"] as $aksi) {
    $potong = substr($svc, (int) strpos($svc, $aksi), 1500);
    $assert(!preg_match("/'(ringkasan|tindakan_umum|tindak_lanjut_wali|alasan|keluhan|catatan_klinis)' =>/", $potong), "Audit $aksi hanya metadata (tanpa teks ringkasan/alasan)");
}
$semua = $svc . $repo . $petugasWeb . $waliWeb . $partial;
$assert(!preg_match('/\berror_log\s*\(/', $semua) && !preg_match('/getMessage\(\)\s*\.\s*\$/', $semua), 'Kode ringkasan tidak menulis error_log dan pesan galat tidak menyisipkan masukan');
$klinik = $tanpaKomentar($src('app/Kesehatan/KesehatanService.php') . $src('app/Kesehatan/KesehatanRepository.php'));
$assert(!str_contains($klinik, 'kesehatan_ringkasan'), 'Koreksi klinis (KesehatanService/Repository) tidak menyentuh tabel ringkasan: snapshot terbit tidak berubah diam-diam');

echo PHP_EOL . '=== S-8. Batas fase, operasi, lint ===' . PHP_EOL;
exec('git -C ' . escapeshellarg($root) . ' diff --name-only ' . BASELINE_FASE2 . ' -- app/Auth app/Penugasan app/Audit app/Kesehatan/KesehatanService.php app/Kesehatan/KesehatanRepository.php 2>&1', $diff, $kodeGit);
$assert($kodeGit === 0 && $diff === [], 'app/Auth, app/Penugasan, AuditLogger, dan layanan/repositori klinis tidak berubah dari baseline audit Fase 2');
exec('git -C ' . escapeshellarg($root) . ' diff --name-only ' . BASELINE_FASE2 . ' 2>&1', $semuaDiff, $kodeGit);
$assert($kodeGit === 0 && preg_grep('#^(app/V[34]/|app/Izin/|app/Psb/|admin/|alhasanApps)#', $semuaDiff) === [], 'Tidak mengubah modul V3/V4/izin/PSB/admin atau aplikasi mobile');
foreach (['bin/v5_phase3_preflight.php', 'bin/v5_phase3_verify.php', 'bin/v5_phase3_rollback_preflight.php'] as $b) {
    $assert(str_contains($src($b), "if (PHP_SAPI !== 'cli')") && !preg_match('/\b(INSERT|UPDATE|DELETE|DROP|ALTER)\b/', (string) preg_replace("/'[^']*'|\"[^\"]*\"|\/\*.*?\*\/|\/\/.*$/ms", '', $tanpaKomentar($src($b)))), "$b: CLI saja, hanya membaca");
}
$assert(str_contains($src('tests/v5_phase3_db.sh'), 'test_*_test) ;;') && str_contains($src('tests/v5_phase3_seed.php'), "preg_match('/^test_.*_test$/'"), 'Skrip DB/seed uji hanya untuk test_*_test');
$berkas = ['app/Kesehatan/RingkasanRepository.php', 'app/Kesehatan/RingkasanService.php', 'app/Notification/NotificationDispatcher.php', 'app/Notification/NotificationCenterService.php',
    'app/Notification/NotificationRepository.php', 'app/Notification/NotificationEvent.php', 'app/Ui/Navigation.php', 'app/Ui/NavigationContext.php', 'app/bootstrap.php', 'api/v1/index.php',
    'portal/kesehatan_ringkasan.php', 'portal/kesehatan_wali.php', 'portal/kesehatan_detail.php', 'portal/_kesehatan.php', 'portal/notifikasi.php', 'portal/partials/kesehatan_ringkasan_konten.php',
    'bin/v5_phase3_preflight.php', 'bin/v5_phase3_verify.php', 'bin/v5_phase3_rollback_preflight.php',
    'tests/v5_phase3_seed.php', 'tests/v5_phase3_worker.php', 'tests/v5_phase3_integration.php', 'tests/v5_phase3_http.php', 'tests/v5_phase3_migration.php'];
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
