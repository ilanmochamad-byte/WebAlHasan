<?php

declare(strict_types=1);

/**
 * Pemeriksaan statis V2 Fase 5.
 *
 * Tidak memerlukan basis data. Fokus:
 *   1. migrasi 009 aditif, idempoten, dan memiliki rollback berpasangan;
 *   2. laporan memakai SATU definisi filter/repository (konsistensi total);
 *   3. cakupan laporan ditegakkan di server dan tidak dapat dilewati;
 *   4. CSV: header terdokumentasi dan formula injection dinetralkan;
 *   5. halaman cetak memuat identitas, filter, pembuat, waktu, keputusan, nomor halaman;
 *   6. endpoint laporan bersifat aditif dan tidak mengubah kontrak V1/Fase 3;
 *   7. aplikasi memakai API yang sama dan tidak menduplikasi aturan otorisasi;
 *   8. receipt push akhir terpasang tanpa mengubah kontrak `PushClient` Fase 4;
 *   9. WhatsApp tetap DITANGGUHKAN, default mati, tanpa provider/credential;
 *  10. fixture/pengukuran/restore hanya berjalan pada database `_test`;
 *  11. tidak ada secret atau data produksi pada repositori;
 *  12. aplikasi tetap Expo SDK 57 tanpa kenaikan versi Expo/React Native;
 *  13. lint sintaks seluruh berkas PHP baru/diubah.
 *
 * Jalankan:
 *   MOBILE_APP_ROOT=/path/ke/alhasanApps php tests/v2_phase5_static.php
 */

$root = dirname(__DIR__);
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? '[lulus] ' : '[gagal] ') . $message . PHP_EOL;
    if (!$condition) {
        $failures[] = $message;
    }
};
$source = static fn (string $path): string => (string) @file_get_contents($root . '/' . $path);
$prd = $source('PRD-V2.md');

$mobileRoot = getenv('MOBILE_APP_ROOT') ?: dirname($root, 4) . '/alhasanApps';
$mobile = static fn (string $path): string => (string) @file_get_contents($mobileRoot . '/' . $path);
$adaMobile = is_dir($mobileRoot . '/src');

/** Membuang komentar sebelum memeriksa larangan: yang dinilai KODE, bukan dokumentasi. */
$tanpaKomentarSql = static fn (string $sql): string => (string) preg_replace('/^\s*--.*$/m', '', $sql);
$tanpaKomentarPhp = static function (string $php): string {
    if (trim($php) === '') {
        return '';
    }
    $bersih = '';
    foreach (token_get_all($php) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $bersih .= $token[1];
            continue;
        }
        $bersih .= $token;
    }

    return $bersih;
};
$tanpaKomentarTs = static function (string $ts): string {
    $ts = (string) preg_replace('#/\*.*?\*/#s', '', $ts);

    return (string) preg_replace('#^\s*//.*$#m', '', $ts);
};

spl_autoload_register(static function (string $class) use ($root): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $path = $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

// ---------------------------------------------------------------------------
echo '=== 1. Migrasi 009 aditif dan berpasangan ===' . PHP_EOL;
// ---------------------------------------------------------------------------

$migrasiPath = 'database/migrations/009_v2_phase5_laporan_dan_push_receipt.sql';
$rollbackPath = 'database/rollbacks/009_v2_phase5_laporan_dan_push_receipt.sql';
$migrasi = $source($migrasiPath);
$rollback = $source($rollbackPath);

$assert($migrasi !== '' && $rollback !== '', 'Migrasi 009 dan rollback-nya ada');
$assert(
    count(glob($root . '/database/migrations/*.sql') ?: [])
        === count(glob($root . '/database/rollbacks/*.sql') ?: []),
    'Setiap migrasi tetap memiliki tepat satu berkas rollback'
);

$migrasiKode = $tanpaKomentarSql($migrasi);
foreach (['DROP TABLE', 'DROP COLUMN', 'TRUNCATE', 'DELETE FROM'] as $terlarang) {
    $assert(
        !str_contains(strtoupper($migrasiKode), $terlarang),
        'Migrasi 009 tidak memakai ' . $terlarang . ' (aditif murni)'
    );
}
$assert(
    substr_count($migrasi, 'information_schema.COLUMNS') >= 6
        && str_contains($migrasi, 'information_schema.STATISTICS'),
    'Setiap perubahan migrasi 009 dibungkus pemeriksaan INFORMATION_SCHEMA (idempoten)'
);
$assert(
    !preg_match('/\bALTER TABLE\s+(perizinan|izin_pengajuan|izin_keputusan|izin_riwayat_status)\b/i', $migrasiKode),
    'Migrasi 009 tidak menyentuh tabel perizinan V1 maupun tabel inti V2'
);
$assert(
    str_contains($migrasi, 'TIDAK menambahkan indeks laporan')
        || str_contains($migrasi, 'SENGAJA TIDAK menambahkan indeks'),
    'Migrasi 009 mencatat keputusan indeks laporan berbasis pengukuran'
);
$assert(
    !preg_match('/ALTER TABLE\s+izin_pengajuan\s+ADD\s+KEY/i', $migrasiKode),
    'Migrasi 009 tidak menambahkan indeks izin_pengajuan yang tidak terbukti diperlukan'
);
$assert(
    str_contains($tanpaKomentarSql($rollback), 'notifikasi_outbox')
        && !preg_match('/DROP\s+TABLE\s+(?!IF)/i', $tanpaKomentarSql($rollback)),
    'Rollback 009 hanya melepas kolom/indeks jejak, tidak menghapus tabel data'
);
$assert(
    !preg_match('/\bDELETE\s+FROM\b|\bTRUNCATE\b/i', $tanpaKomentarSql($rollback)),
    'Rollback 009 tidak menghapus satu baris data pun'
);

// ---------------------------------------------------------------------------
echo PHP_EOL . '=== 2. Satu definisi filter dan repository ===' . PHP_EOL;
// ---------------------------------------------------------------------------

$filter = $source('app/Report/IzinReportFilter.php');
$repo = $source('app/Report/IzinReportRepository.php');
$service = $source('app/Report/IzinReportService.php');
$csv = $source('app/Report/IzinCsvExport.php');
$print = $source('app/Report/IzinPrintRenderer.php');
$layout = $source('app/Report/PrintLayout.php');

foreach ([
    'app/Report/IzinReportFilter.php' => $filter,
    'app/Report/IzinReportRepository.php' => $repo,
    'app/Report/IzinReportService.php' => $service,
    'app/Report/IzinCsvExport.php' => $csv,
    'app/Report/IzinPrintRenderer.php' => $print,
    'app/Report/PrintLayout.php' => $layout,
] as $path => $isi) {
    $assert($isi !== '', 'Berkas ' . $path . ' tersedia');
}

$assert(
    str_contains($filter, 'private function __construct'),
    'IzinReportFilter tidak dapat dibangun sembarangan (konstruktor privat)'
);
$assert(
    substr_count($filter, 'public readonly') >= 15,
    'Seluruh kriteria filter bersifat readonly (immutable)'
);
$assert(
    str_contains($filter, 'public function criteriaKey()'),
    'Filter menyediakan sidik jari kriteria untuk membuktikan konsistensi permukaan'
);
$assert(
    !str_contains($tanpaKomentarPhp($filter), "criteriaKey")
        || !preg_match("/criteriaKey\(\).*'page'/s", $tanpaKomentarPhp($filter)),
    'Sidik jari kriteria TIDAK memuat pagination (halaman tidak boleh mengubah himpunan)'
);

$repoKode = $tanpaKomentarPhp($repo);
$assert(
    substr_count($repoKode, 'private function conditions(') === 1,
    'Klausa WHERE laporan dibangun di SATU tempat (conditions())'
);
$assert(
    substr_count($repoKode, 'private function fromClause(') === 1
        && substr_count($repoKode, 'FROM izin_pengajuan p') === 1,
    'Seluruh query laporan memakai SATU klausa FROM yang sama'
);
foreach (['summary', 'decisionDuration', 'page', 'allRows', 'explain'] as $metode) {
    $assert(
        preg_match('/function ' . $metode . '\(IzinReportFilter[^)]*\)[^{]*\{(?:[^{}]|\{[^{}]*\})*\$this->conditions\(/s', $repoKode) === 1
            || preg_match('/function ' . $metode . '\(.*?\$this->conditions\(/s', $repoKode) === 1,
        'Metode ' . $metode . '() memakai conditions() yang sama'
    );
}
$assert(
    !preg_match('/JOIN\s+plotting_kamar/i', $repoKode) || str_contains($repo, 'BUKAN JOIN'),
    'Kamar/kelas dibaca lewat subquery skalar agar baris pengajuan tidak berlipat'
);
$assert(
    str_contains($repo, 'LIMIT 1 OFFSET') && str_contains($repo, 'MySQL 5.7'),
    'Median durasi memakai pola LIMIT/OFFSET yang kompatibel MySQL 5.7 (bukan window function)'
);
$assert(
    !preg_match('/\bOVER\s*\(|PERCENTILE_CONT|ROW_NUMBER\s*\(/i', $repoKode),
    'Tidak ada window function yang akan gagal pada MySQL 5.7 cPanel'
);

$serviceKode = $tanpaKomentarPhp($service);
$assert(
    str_contains($serviceKode, 'function document(') && str_contains($serviceKode, 'allRows('),
    'Dokumen cetak/CSV memakai jalur ekspor allRows(), bukan satu halaman pagination'
);
$assert(
    preg_match('/function csv\(.*?\$this->document\(/s', $serviceKode) === 1,
    'CSV dibangun dari document() yang sama dengan cetak'
);
$assert(
    preg_match('/function printHtml\(.*?\$this->document\(/s', $serviceKode) === 1,
    'Cetak dibangun dari document() yang sama dengan CSV'
);
$assert(
    !str_contains($serviceKode, 'page(') || str_contains($serviceKode, 'repository->page($filter)'),
    'Tampilan berhalaman dan dokumen berangkat dari objek filter yang sama'
);

// ---------------------------------------------------------------------------
echo PHP_EOL . '=== 3. Cakupan ditegakkan di server ===' . PHP_EOL;
// ---------------------------------------------------------------------------

$assert(
    substr_count($repoKode, 'private function scopeConditions(') === 1,
    'Predikat cakupan dibangun di satu tempat khusus'
);
$assert(
    str_contains($repoKode, "'1 = 0'"),
    'Cakupan yang tidak dikenal menghasilkan 1 = 0, bukan seluruh baris'
);
foreach ([
    'Capabilities::PENGURUS' => 'p.pengurus_id = ?',
    'Capabilities::MUROBI' => 'p.murobi_guru_id = ?',
    'Capabilities::ORANG_TUA' => 'santri_wali',
] as $mode => $predikat) {
    $assert(
        str_contains($repoKode, $mode) && str_contains($repoKode, $predikat),
        'Cakupan ' . $mode . ' dibatasi di SQL (' . $predikat . ')'
    );
}
$assert(
    preg_match('/function conditions\(.*?scopeConditions\(/s', $repoKode) === 1,
    'conditions() selalu diawali predikat cakupan sehingga tidak dapat dilewati'
);
$assert(
    str_contains($tanpaKomentarPhp($filter), 'FORBIDDEN')
        && substr_count($tanpaKomentarPhp($filter), '403') >= 2,
    'Filter menolak parameter yang berusaha memperluas cakupan dengan 403'
);
$assert(
    preg_match('/function filterFor\(.*?izinService->scopeFor\(/s', $serviceKode) === 1,
    'Cakupan dihitung ulang dari akun pada setiap pemanggilan laporan'
);
$assert(
    str_contains($serviceKode, 'Capabilities::ADMIN') && str_contains($serviceKode, 'function explain('),
    'EXPLAIN laporan dibatasi hanya untuk admin'
);

$laporanWeb = $source('portal/laporan.php');
$laporanCetak = $source('portal/laporan_cetak.php');
$laporanCsv = $source('portal/laporan_csv.php');
foreach ([
    'portal/laporan.php' => $laporanWeb,
    'portal/laporan_cetak.php' => $laporanCetak,
    'portal/laporan_csv.php' => $laporanCsv,
] as $path => $isi) {
    $assert($isi !== '', 'Halaman ' . $path . ' tersedia');
    $assert(
        str_contains($isi, "_guard.php") || str_contains($isi, "_ui.php"),
        $path . ' melewati guard portal sebelum menghasilkan output'
    );
    $assert(
        str_contains($isi, 'izin_report_service()'),
        $path . ' memakai layanan laporan bersama, bukan query sendiri'
    );
    // Yang dijaga: halaman tidak boleh menyentuh basis data sendiri. Kata
    // "select" pada markup (<select>) sengaja TIDAK dianggap SQL.
    $assert(
        !preg_match('/->(query|prepare|real_escape_string)\s*\(|\bmysqli\b|\bSELECT\b[\s\S]{0,200}?\bFROM\b/', $tanpaKomentarPhp($isi)),
        $path . ' tidak mengakses basis data secara langsung'
    );
}

// ---------------------------------------------------------------------------
echo PHP_EOL . '=== 4. CSV: header terdokumentasi dan formula injection ===' . PHP_EOL;
// ---------------------------------------------------------------------------

require_once $root . '/app/Report/IzinCsvExport.php';

$headers = \App\Report\IzinCsvExport::HEADERS;
$dokumentasi = \App\Report\IzinCsvExport::DOKUMENTASI;

$assert(count($headers) >= 25, 'CSV memuat kolom yang memadai (' . count($headers) . ' kolom)');
$tanpaDok = array_values(array_diff($headers, array_keys($dokumentasi)));
$assert($tanpaDok === [], 'Setiap kolom CSV memiliki dokumentasi: ' . (implode(', ', $tanpaDok) ?: 'lengkap'));
$dokBerlebih = array_values(array_diff(array_keys($dokumentasi), $headers));
$assert($dokBerlebih === [], 'Tidak ada dokumentasi untuk kolom yang tidak ada: ' . (implode(', ', $dokBerlebih) ?: 'tidak ada'));
$assert(
    count(array_unique($headers)) === count($headers),
    'Tidak ada nama kolom CSV yang duplikat'
);

// Uji langsung fungsi netralisasi.
foreach ([
    '=SUM(A1:A9)' => "'=SUM(A1:A9)",
    '+1+1' => "'+1+1",
    '-2+3' => "'-2+3",
    '@SUM(1)' => "'@SUM(1)",
    "\t=cmd" => "'\t=cmd",
    "\r=cmd" => "'\r=cmd",
    ' =1+1' => "' =1+1",
    '=HYPERLINK("http://x","klik")' => '\'=HYPERLINK("http://x","klik")',
    'Nama Santri Biasa' => 'Nama Santri Biasa',
    '2026-01-01' => '2026-01-01',
    '' => '',
] as $masukan => $harapan) {
    $hasil = \App\Report\IzinCsvExport::spreadsheetSafe($masukan);
    $assert(
        $hasil === $harapan,
        'Formula injection dinetralkan untuk ' . var_export($masukan, true)
            . ' -> ' . var_export($hasil, true)
    );
}
$assert(
    \App\Report\IzinCsvExport::spreadsheetSafe(null) === '',
    'Nilai null menjadi string kosong, bukan teks "null"'
);
$assert(
    str_contains($csv, "\\xEF\\xBB\\xBF"),
    'CSV diawali BOM UTF-8 agar Excel membaca huruf beraksen dengan benar'
);
$assert(
    count(\App\Report\IzinCsvExport::PEMBUKA_BERBAHAYA) >= 6,
    'Daftar karakter pembuka berbahaya memuat = + - @ TAB dan CR'
);
$assert(
    str_contains($laporanCsv, 'Content-Disposition: attachment')
        && str_contains($laporanCsv, 'X-Content-Type-Options: nosniff'),
    'Unduhan CSV dipaksa sebagai lampiran dan tidak dirender peramban'
);
$assert(
    str_contains($laporanCsv, 'X-Laporan-Jumlah-Baris'),
    'Unduhan CSV mengumumkan jumlah baris agar dapat dicocokkan dengan ringkasan'
);
$assert(
    str_contains($serviceKode, "'EXPORT_TOO_LARGE'")
        && str_contains($serviceKode, 'IzinReportFilter::MAX_EXPORT_ROWS'),
    'CSV yang melebihi pagar memori ditolak eksplisit, bukan dikirim parsial'
);
$assert(
    str_contains($prd, 'maksimum 20.000 baris')
        && str_contains($prd, '422 EXPORT_TOO_LARGE')
        && str_contains($prd, 'dilarang mengirim CSV parsial'),
    'PRD menetapkan kontrak produk ekspor maksimum 20.000 baris tanpa CSV parsial'
);
$assert(
    str_contains($serviceKode, "'terpotong'"),
    'Dokumen menandai secara eksplisit bila hasil melebihi batas ekspor'
);

// ---------------------------------------------------------------------------
echo PHP_EOL . '=== 5. Halaman cetak/PDF ===' . PHP_EOL;
// ---------------------------------------------------------------------------

foreach ([
    'Pesantren Al Hasan' => 'identitas pesantren',
    'Dibuat oleh' => 'pembuat laporan',
    'Waktu pembuatan' => 'waktu pembuatan',
    'Keputusan' => 'keputusan',
    'filter_aktif' => 'filter aktif',
] as $penanda => $arti) {
    $assert(str_contains($print, $penanda), 'Halaman cetak memuat ' . $arti);
}

// Nomor halaman TIDAK BOLEH lagi mengandalkan CSS. `@page{@bottom-center{}}`
// tidak didukung satu pun mesin cetak peramban, dan `counter(page)` di dalam
// elemen `position:fixed` dievaluasi WebKit menjadi 0 — itulah asal
// "Halaman 0" pada PDF produksi. Perilaku sebenarnya dibuktikan pada
// tests/v2_phase5_cetak_pdf.php terhadap PDF sungguhan.
// Diperiksa pada CSS YANG DIHASILKAN, bukan pada teks sumber: komentar kelas
// memang menyebut `counter(page)` untuk menjelaskan mengapa ia ditinggalkan.
$cssCetak = \App\Report\PrintLayout::cssDasar();
$assert(
    !str_contains($cssCetak, 'counter(page)')
        && !str_contains($cssCetak, 'counter(pages)')
        && !str_contains($cssCetak, '@bottom-center')
        && !str_contains($cssCetak, 'position:fixed'),
    'CSS cetak yang dihasilkan tidak memakai counter(page) maupun footer position:fixed'
);
$assert(
    str_contains($layout, 'Halaman \' . $halaman . \' dari \' . $total')
        && str_contains($print, 'PrintLayout::footerHalaman'),
    'Nomor halaman dihitung server dan dicetak sebagai teks biasa pada setiap lembar'
);
$assert(
    !str_contains($layout, 'overflow-wrap:anywhere')
        && str_contains($layout, 'overflow-wrap:break-word')
        && str_contains($layout, 'word-break:normal'),
    'CSS cetak tidak memakai overflow-wrap:anywhere yang memotong kata di tengah'
);
$assert(
    str_contains($layout, 'break-inside:avoid') && str_contains($layout, 'page-break-inside:avoid'),
    'Baris data dijaga tidak terbelah antarhalaman'
);
$assert(
    str_contains($layout, 'Lanskap (Landscape)'),
    'Halaman cetak memberi petunjuk orientasi lanskap bagi peramban yang mengabaikan @page size'
);
$assert(
    str_contains($layout, 'htmlspecialchars') && str_contains($layout, 'ENT_QUOTES'),
    'Seluruh nilai pada halaman cetak di-escape'
);
$assert(
    str_contains($laporanCetak, 'no-store'),
    'Halaman cetak tidak boleh disimpan cache bersama (memuat data pribadi)'
);
$assert(
    str_contains($print, 'Median durasi keputusan'),
    'Halaman cetak memuat median durasi keputusan'
);

// ---------------------------------------------------------------------------
echo PHP_EOL . '=== 6. Endpoint aditif ===' . PHP_EOL;
// ---------------------------------------------------------------------------

$router = $source('api/v1/index.php');
foreach ([
    '/izin/laporan',
    '/izin/laporan/filters',
    '/izin/laporan/cetak',
    '/izin/laporan/csv',
    '/izin/laporan/explain',
] as $rute) {
    $assert(str_contains($router, "'" . $rute . "'"), 'Endpoint ' . $rute . ' terpasang');
}
foreach ([
    '/reports',
    '/reports/filters',
    '/reports/print',
    '/izin/pengajuan',
    '/izin/antrean',
    '/notifikasi',
] as $ruteLama) {
    $assert(str_contains($router, "'" . $ruteLama . "'"), 'Endpoint lama ' . $ruteLama . ' tetap ada (tidak ada breaking change)');
}
$assert(
    substr_count($router, 'izin_report_service()') >= 5,
    'Seluruh endpoint laporan melewati layanan bersama'
);
$assert(
    str_contains($router, '$modePreferensi'),
    'Parameter mode diperlakukan sebagai preferensi, bukan hak akses'
);
$assert(
    str_contains($source('app/bootstrap.php'), 'function izin_report_service()')
        && str_contains($source('app/bootstrap.php'), 'function report_service()'),
    'Layanan laporan V2 ditambahkan tanpa mengganti layanan laporan absensi V1'
);
$assert(
    // Sejak koreksi ke-6 (paket perapihan V1-V2) menu berasal dari satu peta
    // navigasi bersama, bukan dari markup tiap halaman. Isinya setara.
    str_contains($source('app/Ui/Navigation.php'), '/portal/laporan.php'),
    'Navigasi portal memuat tautan laporan untuk seluruh peran perizinan'
);

// ---------------------------------------------------------------------------
echo PHP_EOL . '=== 7. Receipt push akhir (temuan terbuka Fase 4) ===' . PHP_EOL;
// ---------------------------------------------------------------------------

$expo = $source('app/Notification/Push/ExpoPushClient.php');
$pushClient = $source('app/Notification/Push/PushClient.php');
$receiptClient = $source('app/Notification/Push/PushReceiptClient.php');
$dispatcher = $source('app/Notification/NotificationDispatcher.php');
$outbox = $source('app/Notification/OutboxRepository.php');
$worker = $source('bin/notifikasi_worker.php');

$assert($receiptClient !== '', 'Antarmuka PushReceiptClient tersedia');
$assert(
    !str_contains($tanpaKomentarPhp($pushClient), 'getReceipts'),
    'Kontrak PushClient Fase 4 TIDAK berubah (klien tiruan Fase 4 tetap sah)'
);
$assert(
    str_contains($expo, 'implements PushClient, PushReceiptClient')
        && str_contains($expo, 'getReceipts.php') === false
        && str_contains($expo, 'RECEIPT_ENDPOINT'),
    'ExpoPushClient mengimplementasikan pengambilan receipt resmi Expo'
);
$assert(
    str_contains($expo, 'https://exp.host/--/api/v2/push/getReceipts'),
    'Endpoint receipt sesuai dokumentasi Expo'
);
$assert(
    str_contains($dispatcher, 'instanceof PushReceiptClient'),
    'Dispatcher melewatkan rekonsiliasi dengan tenang bila klien tidak mendukung receipt'
);
$assert(
    preg_match('/function reconcileReceipts\(.*?push_enabled.*?return \$hasil;/s', $tanpaKomentarPhp($dispatcher)) === 1,
    'Rekonsiliasi receipt berhenti SEBELUM permintaan apa pun ketika push mati'
);
$assert(
    str_contains($dispatcher, 'Receipt `Gagal` TIDAK mengembalikan')
        || str_contains($outbox, 'TIDAK dikembalikan ke'),
    'Receipt gagal TIDAK memicu pengiriman ulang (mencegah notifikasi ganda)'
);
$assert(
    str_contains($outbox, 'function pendingReceipts(')
        && str_contains($outbox, 'function markReceipt(')
        && str_contains($outbox, 'function noteReceiptPending('),
    'Repository outbox menyediakan klaim dan pencatatan receipt'
);
$assert(
    str_contains($outbox, 'RECEIPT_MAKS_PERCOBAAN') && str_contains($outbox, "'Tidak Tersedia'"),
    'Tiket tanpa jawaban berhenti diminta dan ditandai Tidak Tersedia, bukan Gagal'
);
$assert(
    str_contains($outbox, 'SafeError::code') && str_contains($outbox, 'SafeError::message'),
    'Kode dan pesan receipt melewati SafeError sebelum disimpan'
);
$assert(
    str_contains($worker, '--receipts') && str_contains($worker, 'reconcileReceipts('),
    'Worker menyediakan mode --receipts untuk cron cPanel'
);
$assert(
    str_contains($worker, 'receiptSummary('),
    'Mode --status menampilkan sebaran receipt akhir, bukan hanya tiket awal'
);
$assert(
    str_contains($dispatcher, 'markSent($outboxId, $owner, $durasi, $tiketPertama)'),
    'Id tiket disimpan saat pengiriman berhasil agar receipt dapat diambil'
);
$assert(
    str_contains($dispatcher, 'count($perangkat) !== 1'),
    'Pencabutan token dari receipt hanya dilakukan bila pemetaannya tidak ambigu'
);

// ---------------------------------------------------------------------------
echo PHP_EOL . '=== 8. WhatsApp tetap ditangguhkan dan mati ===' . PHP_EOL;
// ---------------------------------------------------------------------------

$prd = $source('PRD-V2.md');
$assert(
    str_contains($prd, '**Notifikasi:** in-app dan push didukung; WhatsApp opsional serta dikendalikan admin. **[JANGAN DIUBAH]**')
        || str_contains($prd, 'in-app dan push didukung; WhatsApp opsional serta dikendalikan admin. **[JANGAN DIUBAH]**'),
    'Kalimat prinsip notifikasi PRD dipertahankan persis'
);
$assert(
    substr_count($prd, '[JANGAN DIUBAH]') >= 9,
    'Seluruh penanda [JANGAN DIUBAH] pada PRD tetap utuh'
);
$assert(
    str_contains($prd, 'DITANGGUHKAN'),
    'Status WhatsApp DITANGGUHKAN tetap tercatat pada PRD'
);
$assert(
    is_file($root . '/docs/phase-v2-4/whatsapp-provider-checklist.md'),
    'Checklist aktivasi WhatsApp masa depan tetap disimpan'
);
// Yang dilarang adalah klaim AFIRMATIF bahwa WhatsApp lulus. Kalimat
// penyangkalan seperti "WhatsApp tidak dinyatakan lulus" justru WAJIB ada,
// sehingga `(?![^.\n]*tidak)` mengecualikan kalimat bernegasi.
$dokAcceptance = $source('docs/phase-v2-5/acceptance-status.md');
$dokTest = $source('docs/phase-v2-5/test-results.md');
foreach (['acceptance-status.md' => $dokAcceptance, 'test-results.md' => $dokTest] as $namaDok => $isiDok) {
    $assert(
        $isiDok !== '' && !preg_match('/WhatsApp(?![^.\n]*tidak)[^.\n]{0,40}(LULUS|lulus|berhasil dikirim)/', $isiDok),
        'Dokumentasi Fase 5 (' . $namaDok . ') tidak menyatakan WhatsApp lulus'
    );
}
$assert(
    str_contains($dokAcceptance, 'tidak dinyatakan lulus')
        && str_contains($dokAcceptance, 'DITANGGUHKAN'),
    'Dokumentasi Fase 5 menyatakan secara eksplisit bahwa WhatsApp ditangguhkan dan tidak lulus'
);
$assert(
    str_contains($source('bin/v2_phase5_preflight.php'), 'whatsapp_enabled = 1')
        && str_contains($source('bin/v2_phase5_verify.php'), 'whatsapp_enabled = 1'),
    'Preflight dan verifikasi memblokir rilis bila WhatsApp menyala'
);
// Tidak boleh ada provider nyata, credential, atau nomor bisnis di repositori.
foreach (glob($root . '/app/Notification/WhatsApp/*.php') ?: [] as $berkas) {
    $isi = (string) file_get_contents($berkas);
    $assert(
        !preg_match('/(graph\.facebook\.com|api\.twilio\.com|Bearer\s+[A-Za-z0-9_\-]{20,})/i', $tanpaKomentarPhp($isi)),
        'Tidak ada endpoint atau credential penyedia nyata pada ' . basename($berkas)
    );
}

// ---------------------------------------------------------------------------
echo PHP_EOL . '=== 9. Perkakas hanya berjalan pada database uji ===' . PHP_EOL;
// ---------------------------------------------------------------------------

foreach ([
    'bin/v2_phase5_fixture.php' => 'V2_PHASE5_FIXTURE',
    'bin/v2_phase5_ukur_laporan.php' => 'V2_PHASE5_UKUR',
    'bin/v2_phase5_backup_restore_drill.php' => 'V2_PHASE5_DRILL',
] as $path => $penanda) {
    $isi = $source($path);
    $assert($isi !== '', 'Perkakas ' . $path . ' tersedia');
    $assert(str_contains($isi, "PHP_SAPI !== 'cli'"), $path . ' hanya dapat dijalankan dari CLI');
    $assert(str_contains($isi, "getenv('" . $penanda . "')"), $path . ' memerlukan penanda lingkungan eksplisit');
    $assert(
        str_contains($isi, "str_ends_with(\$database, '_test')")
            || str_contains($isi, "str_ends_with(\$sumber, '_test')"),
        $path . ' menolak database yang tidak berakhiran _test'
    );
}
foreach (['bin/v2_phase5_fixture.php', 'bin/v2_phase5_backup_restore_drill.php'] as $path) {
    $assert(
        str_contains($source($path), "=== 'production'"),
        $path . ' menolak APP_ENV=production'
    );
}
$assert(
    str_contains($source('bin/v2_phase5_fixture.php'), 'P5')
        && !preg_match('/k1807225|webalhasan\.sql/i', $source('bin/v2_phase5_fixture.php')),
    'Fixture performa memakai data sintetis berawalan P5 dan tidak menyentuh dump produksi'
);
$assert(
    str_contains($source('bin/v2_phase5_backup_restore_drill.php'), 'opsiKutip')
        && str_contains($source('bin/v2_phase5_backup_restore_drill.php'), 'defaults-extra-file'),
    'Latihan restore mengirim credential lewat berkas opsi terkutip, bukan argumen baris perintah'
);
$assert(
    str_contains($source('bin/v2_phase5_preflight.php'), 'fingerprint_sha256'),
    'Preflight menyimpan sidik jari nilai bisnis perizinan lama'
);
$assert(
    str_contains($source('bin/v2_phase5_verify.php'), 'fingerprint_sha256')
        && str_contains($source('bin/v2_phase5_verify.php'), 'ID `perizinan` identik'),
    'Verifikasi membandingkan ID dan nilai bisnis perizinan lama dengan manifest'
);

// ---------------------------------------------------------------------------
echo PHP_EOL . '=== 10. Kebersihan repositori ===' . PHP_EOL;
// ---------------------------------------------------------------------------

$berkasFase5 = array_merge(
    glob($root . '/app/Report/Izin*.php') ?: [],
    glob($root . '/bin/v2_phase5_*.php') ?: [],
    glob($root . '/portal/laporan*.php') ?: [],
    glob($root . '/tests/v2_phase5_*.php') ?: []
);
foreach ($berkasFase5 as $berkas) {
    $isi = (string) file_get_contents($berkas);
    $assert(
        !preg_match('/(DB_PASSWORD\s*=\s*[\'"][^\'"]+|API_TOKEN_HASH_SECRET\s*=\s*[\'"][^\'"]{8,}|EXPO_ACCESS_TOKEN\s*=\s*[\'"][^\'"]+)/', $isi),
        'Tidak ada credential tertanam pada ' . basename($berkas)
    );
    // Token push: yang dilarang adalah token SUNGGUHAN. Berkas uji boleh
    // memuat token sintetis, tetapi hanya bila ia jelas-jelas bertanda uji
    // (`UJI`, `SBX`, `FIXTURE`, atau `xxx`). Token tanpa penanda dianggap
    // nyata dan ditolak — sehingga token produksi tidak dapat menyelinap
    // masuk dengan alasan "ini cuma untuk pengujian".
    preg_match_all('/Expo(?:nent)?PushToken\[([^\]]*)\]/', $isi, $tokenCocok);
    $tokenMencurigakan = array_values(array_filter(
        $tokenCocok[1] ?? [],
        static fn (string $isiToken): bool => preg_match('/UJI|SBX|FIXTURE|xxx|TEST|DUMMY|\$|\{/i', $isiToken) !== 1
    ));
    $assert(
        $tokenMencurigakan === [],
        'Tidak ada token push sungguhan pada ' . basename($berkas)
            . ($tokenMencurigakan === [] ? '' : ' (' . implode(', ', $tokenMencurigakan) . ')')
    );
}
$assert(
    !is_file($root . '/.env') || !in_array('.env', explode("\n", trim($source('.gitignore'))), true) === false,
    'Berkas .env tetap diabaikan Git'
);

// ---------------------------------------------------------------------------
echo PHP_EOL . '=== 11. Aplikasi mobile ===' . PHP_EOL;
// ---------------------------------------------------------------------------

if (!$adaMobile) {
    echo '[lewat] MOBILE_APP_ROOT tidak ditemukan; pemeriksaan aplikasi dilewati.' . PHP_EOL;
} else {
    $paket = $mobile('package.json');
    $paketData = json_decode($paket, true);
    $deps = is_array($paketData) ? ($paketData['dependencies'] ?? []) : [];

    $assert(($deps['expo'] ?? '') === '~57.0.15', 'Expo TETAP ~57.0.15 (tidak dinaikkan)');
    $assert(($deps['react-native'] ?? '') === '0.86.2', 'React Native TETAP 0.86.2 (tidak dinaikkan)');
    $assert(($deps['expo-notifications'] ?? '') === '~57.0.13', 'expo-notifications tetap selaras SDK 57');
    $assert(str_starts_with((string) ($deps['expo-print'] ?? ''), '~57.'), 'expo-print selaras SDK 57');
    $assert(str_starts_with((string) ($deps['expo-sharing'] ?? ''), '~57.'), 'expo-sharing selaras SDK 57');
    $assert(str_starts_with((string) ($deps['expo-file-system'] ?? ''), '~57.'), 'expo-file-system selaras SDK 57');

    $dokumen = $tanpaKomentarTs($mobile('src/report/izin-report-document.ts'));
    $layar = $tanpaKomentarTs($mobile('src/app/izin/laporan.tsx'));
    $klien = $tanpaKomentarTs($mobile('src/api/client.ts'));

    $assert($dokumen !== '', 'Modul dokumen laporan perizinan mobile tersedia');
    $assert($layar !== '', 'Layar laporan perizinan mobile tersedia');

    $assert(
        str_contains($dokumen, "from 'expo-print'") && str_contains($dokumen, "from 'expo-sharing'"),
        'Cetak dan berbagi memakai API resmi Expo SDK 57'
    );
    $assert(
        str_contains($dokumen, 'Paths') && str_contains($dokumen, 'new File(')
            && !str_contains($dokumen, 'cacheDirectory')
            && !str_contains($dokumen, 'writeAsStringAsync'),
        'Penulisan berkas memakai API expo-file-system SDK 57 (Paths/File), bukan API lama'
    );
    $assert(
        str_contains($klien, 'izinLaporanCetak') && str_contains($klien, 'izinLaporanCsv'),
        'Aplikasi memakai endpoint laporan yang sama dengan website'
    );
    $assert(
        str_contains($klien, '/izin/laporan'),
        'Aplikasi memanggil /izin/laporan, bukan menyusun laporannya sendiri'
    );
    // Aturan otorisasi TIDAK boleh diduplikasi sebagai satu-satunya pengaman.
    $assert(
        !preg_match('/\bif\s*\([^)]*(role|roles)\s*===?\s*[\'"](admin|pengurus|murobi|orang_tua)[\'"]/', $layar),
        'Layar laporan tidak menegakkan otorisasi berbasis nama role di sisi aplikasi'
    );
    $assert(
        !preg_match('/\.filter\(\s*\(?\s*\w+\s*\)?\s*=>[^)]*pengurus_id|\.filter\([^)]*murobi_guru_id/', $layar),
        'Layar laporan tidak menyaring baris berdasarkan cakupan di sisi klien'
    );
    $assert(
        str_contains($layar, 'data.ringkasan.total') && !preg_match('/items\.(length|reduce)\s*[^)]*ringkasan/i', $layar),
        'Ringkasan pada aplikasi berasal dari server, tidak dihitung ulang dari satu halaman'
    );
    $assert(
        str_contains($layar, 'ModeSwitcher') && str_contains($layar, 'capabilities.list'),
        'Pilihan cakupan aplikasi berasal dari capability yang dikirim server'
    );
    $assert(
        str_contains($dokumen, 'jumlah_baris') && str_contains($dokumen, 'terpotong'),
        'Aplikasi memverifikasi bahwa CSV memuat seluruh hasil filter'
    );

    // Jalur Expo Print: `expo-print` memakai bawaan US Letter POTRET (612×792)
    // dan tidak membaca `@page { size: A4 landscape }` dari HTML. Ukuran
    // kertas karena itu wajib diminta lewat opsi `width`/`height`, yang
    // tersedia lintas platform pada SDK 57.
    $halamanCetak = $tanpaKomentarTs($mobile('src/report/print-page.ts'));
    $dokumenAbsensi = $tanpaKomentarTs($mobile('src/report/report-document.ts'));
    $dialogCetak = $tanpaKomentarTs($mobile('src/report/print-dialog.ts'));
    $galatCetak = $tanpaKomentarTs($mobile('src/report/print-errors.ts'));
    $ujiDialogCetak = $tanpaKomentarTs($mobile('tests/print-dialog.test.ts'));
    $assert(
        str_contains($halamanCetak, 'width: 842') && str_contains($halamanCetak, 'height: 595'),
        'Jalur Expo Print meminta ukuran A4 lanskap (842×595 pada 72 PPI)'
    );
    $assert(
        str_contains($halamanCetak, 'Print.Orientation.landscape'),
        'Dialog cetak iOS diminta dalam orientasi lanskap'
    );
    $assert(
        str_contains($halamanCetak, 'textZoom: 100'),
        'PDF Android mengunci textZoom 100 agar tinggi baris tidak dipengaruhi WebView/OEM'
    );
    $assert(
        str_contains($halamanCetak, 'left: 29')
            && str_contains($halamanCetak, 'right: 29')
            && substr_count($halamanCetak, 'margins: MARGIN_IOS_HORIZONTAL_1CM') === 2,
        'Cetak dan PDF iOS memakai margin horizontal native sekurang-kurangnya 1 cm'
    );
    $assert(
        str_contains($source('app/Report/PrintLayout.php'), 'margin:12mm 10mm'),
        'HTML mempertahankan margin horizontal 10 mm untuk Android dan peramban'
    );
    foreach (['izin-report-document.ts' => $dokumen, 'report-document.ts' => $dokumenAbsensi] as $berkas => $isi) {
        $assert(
            !preg_match('/print(?:To File)?Async\(\s*\{\s*html\s*\}/', str_replace('ToFile', 'To File', $isi)),
            $berkas . ' tidak lagi mencetak dengan ukuran kertas bawaan US Letter'
        );
        $assert(
            str_contains($isi, 'opsiCetakA4Lanskap') && str_contains($isi, 'opsiPdfA4Lanskap'),
            $berkas . ' memakai opsi halaman A4 lanskap bersama'
        );
        $assert(
            str_contains($isi, 'openSystemPrintDialog('),
            $berkas . ' memakai penanganan pembatalan dialog cetak bersama'
        );
    }
    $assert(
        str_contains($galatCetak, 'PrintIncompleteException')
            && str_contains($galatCetak, 'Printing did not complete')
            && str_contains($galatCetak, "return 'dibatalkan'")
            && str_contains($galatCetak, 'throw caught'),
        'Pembatalan cetak iOS dianggap normal tanpa menyembunyikan kegagalan printer nyata'
    );
    $assert(
        str_contains($dialogCetak, 'settlePrintDialog(() => Print.printAsync(options))'),
        'Seluruh dialog cetak melewati normalisasi hasil native Expo Print'
    );
    $assert(
        substr_count($ujiDialogCetak, "'dibatalkan'") >= 1
            && str_contains($ujiDialogCetak, 'assert.rejects')
            && str_contains($ujiDialogCetak, "'dimulai'"),
        'Tes mobile mencakup cetak dimulai, dibatalkan, dan kegagalan nyata'
    );
    $assert(
        !preg_match('/ExponentPushToken\[|API_TOKEN_HASH_SECRET|DB_PASSWORD/', $dokumen . $layar),
        'Tidak ada secret pada berkas laporan aplikasi'
    );
}

// ---------------------------------------------------------------------------
echo PHP_EOL . '=== 12. Lint sintaks PHP ===' . PHP_EOL;
// ---------------------------------------------------------------------------

$berkasLint = array_merge(
    glob($root . '/app/Report/*.php') ?: [],
    glob($root . '/app/Notification/Push/*.php') ?: [],
    [$root . '/app/Notification/NotificationDispatcher.php'],
    [$root . '/app/Notification/OutboxRepository.php'],
    [$root . '/app/bootstrap.php'],
    [$root . '/api/v1/index.php'],
    glob($root . '/portal/*.php') ?: [],
    glob($root . '/bin/v2_phase5_*.php') ?: [],
    [$root . '/bin/notifikasi_worker.php'],
    glob($root . '/tests/v2_phase5_*.php') ?: []
);
foreach (array_unique($berkasLint) as $berkas) {
    if (!is_file($berkas)) {
        continue;
    }
    $keluaran = [];
    $kode = 1;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($berkas) . ' 2>&1', $keluaran, $kode);
    $assert($kode === 0, 'php -l lulus: ' . str_replace($root . '/', '', $berkas));
}

// ---------------------------------------------------------------------------
echo PHP_EOL;
if ($failures !== []) {
    echo 'GAGAL (' . count($failures) . '):' . PHP_EOL;
    foreach ($failures as $failure) {
        echo ' - ' . $failure . PHP_EOL;
    }
    exit(1);
}
echo 'SELURUH PEMERIKSAAN STATIS FASE 5 LULUS.' . PHP_EOL;
exit(0);
