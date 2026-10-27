<?php

declare(strict_types=1);

/**
 * Uji statis PRD V7 Fase 3 — notifikasi tahap, laporan lintas asal, audit.
 * Tanpa basis data.
 *
 *   php tests/v7_phase3_static.php
 *
 *   T-1 katalog notifikasi: sepuluh peristiwa, teks generik tanpa data pribadi,
 *       kunci berversi, data hanya penunjuk, tanpa WhatsApp, pengajuan_id NULL
 *   T-2 antrean di dalam transaksi dengan SAVEPOINT; galat tidak membatalkan
 *       mutasi kecuali deadlock (409); audit metadata
 *   T-3 kait peralihan tahap: layanan V7 dan jalur V2 untuk baris wali
 *   T-4 worker Push memeriksa ulang sebelum provider; tautan pusat notifikasi
 *   T-5 laporan: satu pembangun WHERE, cakupan dari akun, tanpa alasan, ekspor
 *       diaudit, rute web/API
 *   T-6 batas Fase 3 terhadap baseline audit Fase 2
 *   T-7 lint
 */

$root = dirname(__DIR__);
$baseline = '96dc8275857d78ebcdc0a432f51e3c2f9c4cae4d';
$lulus = 0;
$gagal = [];
$assert = static function (bool $ok, string $pesan) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    $ok ? $lulus++ : $gagal[] = $pesan;
};
$baca = static fn (string $f): string => (string) file_get_contents($root . '/' . $f);
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

require_once $root . '/app/Izin/IzinWaliRepository.php';
require_once $root . '/app/Izin/IzinRouter.php';
require_once $root . '/app/Izin/IzinWaliNotifikasi.php';

use App\Izin\IzinWaliNotifikasi as N;

// ===================================================================== T-1
echo PHP_EOL . '=== T-1. Katalog notifikasi ===' . PHP_EOL;
$assert(count(N::EVENTS) === 10 && count(array_unique(N::EVENTS)) === 10 && array_keys(N::PENERIMA) === N::EVENTS
    && array_filter(N::EVENTS, static fn ($e) => !str_starts_with($e, 'izin.wali_')) === [],
    'Sepuluh peristiwa izin.wali_* dengan penerima terdokumentasi (masuk dalam kategori Perizinan pusat notifikasi)');
$teks = [];
foreach (N::EVENTS as $e) {
    $t = N::teks($e, 4321);
    $teks[] = $t['judul'] . ' ' . $t['isi'];
    $assert(mb_strlen($t['judul']) <= 150 && mb_strlen($t['isi']) <= 500 && str_contains($t['isi'], '#4321') && N::label($e) !== $e,
        "Teks $e: generik, muat kolom outbox, hanya nomor pengajuan, berlabel");
}
$assert(!preg_match('/\d{4}-\d{2}-\d{2}|santri_nama|alasan_|catatan_|\{|\$/', implode(' ', $teks)), 'Katalog tanpa tanggal, nama, atau placeholder isi');
$src = $baca('app/Izin/IzinWaliNotifikasi.php');
$assert(!preg_match('/nama_santri|->alasan|\[\'alasan\'\]|catatan_wali|no_hp|phone/', $metode($src, 'teks') . $metode($src, 'antrekan')),
    'Perakitan dan antrean tidak membaca nama santri, alasan, catatan, atau nomor telepon');
$assert(N::eventKey(N::DITERUSKAN, 12, 3) === 'v7:izin:12:diteruskan:v3' && strlen(N::eventKey(N::PERLU_PENETAPAN_PEMBIMBING, 99999999, 9999)) <= 120,
    'Kunci peristiwa berversi dan muat 120 karakter');
$assert(N::data(5) === ['tipe' => 'izin_wali', 'pengajuan_id' => 5], 'Data tautan hanya {tipe, pengajuan_id}');
$antre = $metode($src, 'antrekan');
$assert(str_contains($antre, "'pengajuan_id' => null") && !str_contains($src, 'NotificationChannel::WHATSAPP')
    && str_contains($antre, "\$this->settings->current()['push_enabled'] === true"),
    'pengajuan_id NULL (pusat notifikasi tidak menggabungkan nama santri); WhatsApp tidak pernah; Push hanya saat sakelar menyala');

// ===================================================================== T-2
echo PHP_EOL . '=== T-2. Antrean transaksional dan kegagalan ===' . PHP_EOL;
$assert($urut($antre, ["'SAVEPOINT '", '$this->baris($id)', '$this->penerima($event, $row)', '$aktorUserId', '->enqueue(', '->exists(', "'izin_wali_notifikasi_diantrekan'", "'RELEASE SAVEPOINT '"]),
    'Savepoint → baris terkini → penerima dihitung ulang tanpa aktor → antre (dedup) → audit metadata → lepas savepoint');
$assert($urut($antre, ['catch (Throwable', '1213', "'ROLLBACK TO SAVEPOINT '", 'IzinException::conflict', "'izin_wali_notifikasi_gagal'"])
    && !preg_match('/catch \(Throwable[^}]*throw \$exception/s', $antre),
    'Galat antrean dikembalikan ke savepoint dan dicatat; hanya deadlock (transaksi sudah dibatalkan InnoDB) menjadi 409');
$assert(str_contains($antre, "error_log('Notifikasi V7 gagal diantrekan untuk ' . \$event . ' #' . \$id . '.')"), 'Log galat hanya nama peristiwa dan nomor');
foreach (['app/Izin/IzinWaliService.php' => '$this->repo->db()->errno', 'app/Izin/IzinWorkflowService.php' => '$this->write->db()->errno'] as $f => $errno) {
    $tr = $metode($baca($f), 'transactional');
    $assert($urut($tr, ['$errno = (int) $exception->getCode() ?: ' . $errno, 'rollback()', '1213']), "$f: kode galat dibaca sebelum rollback; deadlock → 409");
}
$assert(str_contains($baca('app/Izin/IzinWriteRepository.php'), "'Data perizinan tidak dapat dikunci untuk diperbarui.', \$statement === false ? \$this->db->errno : \$statement->errno"),
    'IzinWriteRepository membawa kode galat MySQL pada exception (tanpa perubahan SQL)');

// ===================================================================== T-3
echo PHP_EOL . '=== T-3. Kait peralihan tahap ===' . PHP_EOL;
$wali = $baca('app/Izin/IzinWaliService.php');
$kait = [
    'buat' => ['auditOrFail(\'izin_wali_pengajuan_dibuat\'', 'antrekanTahapPembimbing($id, $userId)'],
    'tetapkanPembimbing' => ['\'izin_wali_pembimbing_ditetapkan\'', 'antrekanTahapPembimbing($id, $userId)'],
    'tinjau' => ['IzinWaliNotifikasi::DITERUSKAN', 'IzinWaliNotifikasi::MASUK_MUROBI : IzinWaliNotifikasi::PERLU_PENETAPAN_MUROBI', 'IzinWaliNotifikasi::DITOLAK_PEMBIMBING : IzinWaliNotifikasi::PERLU_PERBAIKAN'],
    'perbaiki' => ['\'izin_wali_perbaikan_dikirim\'', 'antrekanTahapPembimbing($id, $userId)'],
    'batalkan' => ['\'izin_wali_dibatalkan\'', 'IzinWaliNotifikasi::DIBATALKAN'],
];
foreach ($kait as $m => $p) {
    $badan = $metode($wali, $m);
    $assert($urut($badan, $p) && strpos($badan, 'notifikasi()') > strpos($badan, '$this->idempotency->complete('), "IzinWaliService::$m mengantre notifikasi sesudah mutasi, di dalam transaksi");
}
$v2 = $metode($baca('app/Izin/IzinWorkflowService.php'), 'notify');
$assert($urut($v2, ["=== 'Wali'", 'MUROBI_DITETAPKAN', 'IzinWaliNotifikasi::MASUK_MUROBI', 'IzinWaliNotifikasi::DIPUTUS', 'IzinWaliNotifikasi::DIKOREKSI', 'return;', '$this->notifications->emit(']),
    'Jalur V2 atas baris wali: penetapan murobi → murobi, keputusan/koreksi → wali pengaju (V7); notifikasi V2 lama tidak dipancarkan');

// ===================================================================== T-4
echo PHP_EOL . '=== T-4. Worker Push dan tautan ===' . PHP_EOL;
$disp = $metode($baca('app/Notification/NotificationDispatcher.php'), 'kirimPush');
$assert($urut($disp, ['IzinWaliNotifikasi::EVENTS', 'IzinWaliNotifikasi::pushMasihBerhak($this->db, $row)', "'AKSES_BERUBAH'", 'IzinWaliNotifikasi::teks(', 'activeTokensFor', '$data = $penunjukIzinWali', "push_enabled'] !== true", '$this->expo->send(']),
    'Worker: pemeriksaan ulang penerima/keadaan → teks dari katalog → data hanya penunjuk → sakelar dibaca ulang → provider');
$cek = $metode($src, 'pushMasihBerhak');
$assert($urut($cek, ['preg_match', 'eventKey(', '$self->baris($id)', "(int) \$row['version'] !== (int) \$m[3]", '$self->penerima($event, $row)']),
    'Push basi (versi berubah) atau penerima tidak berhak lagi tidak dikirim');
$pen = $metode($src, 'penerima');
$assert(str_contains($pen, "\$this->resolver->pengurus(") && str_contains($pen, '$this->resolver->murobi(') && str_contains($pen, '$this->waliPengaju($row)')
    && !str_contains($pen, 'waliSantri'), 'Penerima: pembimbing sah, murobi berpenugasan, admin, atau HANYA wali pengaju (bukan seluruh wali santri)');
$wp = $metode($src, 'waliPengaju');
$assert(str_contains($wp, 'sw.archived_at IS NULL') && str_contains($wp, 'w.is_active = 1') && str_contains($wp, "r.slug = 'orang_tua'") && str_contains($wp, 'u.is_active = 1'),
    'Wali pengaju diperiksa: akun aktif ber-role orang tua, data wali aktif, relasi santri aktif');
$notif = $baca('portal/notifikasi.php');
$assert(str_contains($notif, "=== 'izin_wali'") && str_contains($notif, "'/portal/izin_wali_detail.php?id=' . (int) \$tautan['pengajuan_id']"),
    'Pusat notifikasi web membuka rincian V7 (yang memeriksa ulang hak)');

// ===================================================================== T-5
echo PHP_EOL . '=== T-5. Laporan ===' . PHP_EOL;
$lr = $baca('app/Izin/IzinLaporanRepository.php');
$assert(substr_count($lr, '$this->conditions(') === 3 && substr_count($lr, '$this->fromClause()') >= 4, 'Kartu, daftar, dan ekspor memakai satu pembangun WHERE dan FROM yang sama');
$assert(!preg_match('/p\.alasan|catatan_wali|k\.alasan|alasan_pembatalan|alasan_penggantian/', $lr), 'SQL laporan tidak membaca alasan, catatan, atau alasan keputusan');
$kond = $metode($lr, 'conditions');
$assert(str_contains($kond, "default => [['1 = 0'], []]") && str_contains($kond, "PembimbingRouter::sahSql('p.pembimbing_pengurus_id', 'p.santri_id')")
    && str_contains($kond, 'sw.archived_at IS NULL') && !str_contains($kond, '$_GET'), 'Cakupan di SQL: tidak dikenal → 1 = 0; pembimbing sah; relasi wali aktif');
$ls = $baca('app/Izin/IzinLaporanService.php');
$assert($urut($metode($ls, 'cakupan'), ['$this->capabilities->forget($userId)', 'rolesAkun($userId)', 'in_array($peran, $tersedia, true)']),
    'Cakupan dihitung ulang dari basis data; parameter peran hanya memilih peran yang dimiliki');
$assert(str_contains($metode($ls, 'ekspor'), "'izin_v7_laporan_ekspor'") && str_contains($metode($ls, 'ekspor'), 'spreadsheetSafe'), 'Ekspor diaudit dan aman spreadsheet');
$api = $baca('api/v1/index.php');
$assert(str_contains($api, "\$path === '/v7/izin/laporan'") && str_contains($api, "\$path === '/v7/izin/laporan/ekspor'"), 'Rute API laporan dan ekspor');
$web = $baca('portal/izin_wali_laporan.php');
$assert(str_contains($web, "require_once __DIR__ . '/_izin_wali.php'") && !preg_match('/<\?=\s*\$(?!lap\b)\w+\[[^\]]+\]\s*\?>/', $web)
    && str_contains($baca('portal/izin_wali_laporan_ekspor.php'), 'izin_laporan_service()->ekspor('), 'Halaman laporan memakai penjaga V7 (no-store) dan keluaran ter-escape');

// ===================================================================== T-6
echo PHP_EOL . '=== T-6. Batas Fase 3 terhadap baseline ===' . PHP_EOL;
exec('git -C ' . escapeshellarg($root) . ' diff --name-only ' . escapeshellarg($baseline) . ' -- . 2>&1', $berubah, $kode);
exec('git -C ' . escapeshellarg($root) . ' ls-files --others --exclude-standard 2>&1', $baru);
$semua = array_values(array_unique(array_merge($berubah, $baru)));
$boleh = '#^(app/Izin/(IzinWaliService|IzinWorkflowService|IzinWriteRepository|IzinWaliNotifikasi|IzinLaporanRepository|IzinLaporanService)\.php'
    . '|app/Notification/(NotificationDispatcher|NotificationEvent)\.php|app/bootstrap\.php|api/v1/index\.php'
    . '|portal/(izin_wali|izin_wali_detail|izin_wali_laporan|izin_wali_laporan_ekspor|notifikasi|laporan)\.php|bin/v7_phase3_verify\.php'
    . '|tests/(v7_phase3_\w+\.(php|sh)|browser/uji-v7-fase3\.mjs)|docs/(phase-v7-3/.+|api-v1\.md)|AGENTS\.md|PRD-V7\.md'
    . '|docs/panduan-pengguna-v4/.+|docs/presentasi-al-hasan-v4/.+|output/.+)$#';
$luar = array_values(array_filter($semua, static fn (string $f): bool => $f !== '' && !preg_match($boleh, $f)));
$assert($kode === 0 && $luar === [], 'Hanya berkas Fase 3 yang berubah dari baseline ' . substr($baseline, 0, 7) . ($luar === [] ? '' : ': ' . implode(', ', $luar)));
$assert(array_filter($semua, static fn (string $f): bool => (bool) preg_match('#^database/|^app/(Auth|Penugasan|Bsa|Kesehatan|V3|V4|Psb|Ui)/|^admin/#', $f)) === [],
    'Tanpa migrasi; Auth, Penugasan, Navigation/Ui, BSA, Kesehatan, V3, V4, PSB, dan admin tidak berubah');
$assert(array_filter($semua, static fn (string $f): bool => (bool) preg_match('#^(tests/v7_phase[12]_|bin/(_?v7_phase[12]))#', $f)) === [],
    'Uji dan alat Fase 1–2 tidak diubah (oracle historis tidak dilonggarkan)');

// ===================================================================== T-7
echo PHP_EOL . '=== T-7. Lint ===' . PHP_EOL;
$php = array_values(array_filter($semua, static fn (string $f): bool => str_ends_with($f, '.php') && is_file($root . '/' . $f) && !str_starts_with($f, 'docs/') && !str_starts_with($f, 'output/')));
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
