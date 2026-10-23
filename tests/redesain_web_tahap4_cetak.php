<?php

declare(strict_types=1);

/**
 * Bukti PDF sungguhan templat cetak redesain website Tahap 4 (X-1, AS).
 *
 * Kolom tanda tangan Bagian Pendidikan menambah tinggi lembar terakhir. Uji
 * ini merender laporan perizinan dan kehadiran pada sejumlah ukuran yang
 * menekan batas lembar, mencetaknya menjadi PDF lewat Chromium
 * (`tests/browser/cetak-pdf.mjs`) dalam tiga orientasi (css, lanskap,
 * potret), lalu membuktikan:
 *
 *   - jumlah halaman fisik PDF = jumlah lembar yang dihitung server (nomor
 *     "Halaman i dari n" tetap benar, tanda tangan tidak terdorong sendirian);
 *   - tanda tangan muncul sekali, di lembar terakhir, bersama baris data.
 *
 * Tidak memerlukan poppler: jumlah halaman dibaca dari kotak MediaBox PDF
 * yang dilaporkan skrip Playwright.
 *
 *   php tests/redesain_web_tahap4_cetak.php
 */

use App\Report\IzinPrintRenderer;
use App\Report\PrintRenderer;

$root = dirname(__DIR__);
require_once $root . '/app/bootstrap.php';

$lulus = 0;
$gagal = [];
$assert = static function (bool $ok, string $pesan) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    $ok ? $lulus++ : $gagal[] = $pesan;
};

$izin = static function (int $n, bool $panjang = false): array {
    $items = [];
    for ($i = 1; $i <= $n; $i++) {
        $items[] = ['id' => $i, 'is_legacy' => false, 'sumber_label' => 'V2', 'nis' => '2401' . $i, 'nama_santri' => 'ADITYA FATHUROHMAN ' . $i,
            'kamar_kelas_label' => 'Kamar ABDURAHMAN BIN AUF / Kelas 3 IBTIDAIYAH PA', 'tgl_izin' => '2026-10-20', 'tgl_kembali' => '2026-10-22',
            'alasan' => $panjang ? str_repeat('Alasan izin yang cukup panjang untuk beberapa baris. ', 6) : 'Pulang: acara keluarga',
            'pengurus_label' => 'Adi Hidayat', 'murobi_label' => 'ILAN MOCHAMAD FAUZAN', 'status' => 'Disetujui', 'keputusan_label' => 'Disetujui',
            'keputusan_kapasitas' => 'Murobi', 'diputus_pada' => '2026-10-20 15:50:07', 'keputusan_alasan' => 'Hati-hati di jalan', 'durasi_label' => '8 menit'];
    }

    return ['cakupan_label' => 'Admin — seluruh pengajuan', 'ringkasan' => ['total' => $n, 'legacy' => 0, 'per_status' => ['Diajukan' => 0, 'Perlu Penetapan Admin' => 0, 'Disetujui' => $n, 'Ditolak' => 0, 'Dibatalkan' => 0]],
        'durasi' => ['jumlah' => $n, 'median_label' => '8 menit', 'min_label' => '8 menit', 'maks_label' => '8 menit', 'rata_label' => '8 menit', 'median_detik' => 480],
        'items' => $items, 'jumlah_baris' => $n, 'terpotong' => false, 'kriteria' => hash('sha256', 'uji' . $n),
        'filter_aktif' => ['Cakupan' => 'Admin', 'Rentang tanggal izin' => '2026-10-01 s.d. 2026-10-31', 'Status' => 'Semua status', 'Kelas' => 'Semua kelas', 'Kamar' => 'Semua kamar', 'Pencarian' => 'Tanpa kata kunci'],
        'dibuat_oleh' => 'Administrator', 'dibuat_pada' => '2026-10-23 09:00:00 WIB'];
};
$absensi = static function (int $n): array {
    $items = [];
    for ($i = 1; $i <= $n; $i++) {
        $items[] = ['meeting_date' => '2026-10-20', 'schedule_id' => 7, 'subject' => 'Tafsir Jalalain', 'teacher_name' => 'Ust. Syarif Hidayatullah', 'class_name' => 'Kelas 10 Aliyah',
            'subject_type' => 'Santri', 'identity_number' => '2410' . $i, 'subject_name' => 'MUHAMMAD RIZKI RAMADHAN ' . $i, 'attendance_status' => $i % 7 === 0 ? 'Terlambat' : 'Hadir',
            'notes' => $i % 5 === 0 ? 'Datang setelah iqamah' : null, 'recorder_name' => 'Ust. Syarif', 'updated_at' => '2026-10-20 18:12:00'];
    }

    return ['items' => $items, 'summary' => ['meeting_count' => 4, 'detail_count' => $n, 'statuses' => ['Hadir' => $n, 'Terlambat' => 0, 'Izin' => 0, 'Sakit' => 0, 'Alpa' => 0]],
        'filters' => ['date_from' => '2026-10-01', 'date_to' => '2026-10-31'], 'active_filters' => ['Rentang tanggal' => '2026-10-01 s.d. 2026-10-31', 'Penyajian' => 'Santri'],
        'generated_at' => '2026-10-23 09:00:00 WIB', 'created_by' => 'Administrator'];
};

$kasus = [];
foreach ([0, 1, 3, 5, 8, 12, 20, 40] as $n) {
    $kasus['izin-' . $n] = IzinPrintRenderer::render($izin($n));
}
$kasus['izin-panjang-6'] = IzinPrintRenderer::render($izin(6, true));
foreach ([0, 3, 10, 20, 36, 60] as $n) {
    $kasus['absensi-' . $n] = PrintRenderer::report($absensi($n));
}

echo '=== A. Lembar server ===' . PHP_EOL;
$lembarPer = [];
foreach ($kasus as $nama => $html) {
    $lembar = explode('<section class="lembar">', $html);
    array_shift($lembar);
    $lembarPer[$nama] = count($lembar);
    $akhir = (string) end($lembar);
    preg_match_all('/Halaman (\d+) dari (\d+)/', $html, $m);
    $assert(substr_count($html, 'class="ttd"') === 1 && str_contains($akhir, 'class="ttd"')
        && (str_contains($nama, '-0') || substr_count($akhir, '<tr><td>') >= 1)
        && count($m[0]) === count($lembar) && (int) end($m[2]) === count($lembar),
        "$nama: " . count($lembar) . ' lembar, tanda tangan sekali di lembar terakhir bersama baris data, nomor 1..n');
}

echo PHP_EOL . '=== B. PDF Chromium (css, lanskap, potret) ===' . PHP_EOL;
$skrip = $root . '/tests/browser/cetak-pdf.mjs';
exec('command -v node 2>/dev/null', $out, $kodeNode);
if ($kodeNode !== 0 || !is_dir($root . '/tests/browser/node_modules/playwright')) {
    echo '[menunggu] Node/Playwright tidak tersedia — bukti PDF ditunda, BUKAN lulus.' . PHP_EOL;
    exit(77);
}
$dir = sys_get_temp_dir() . '/redesain-t4-cetak-' . getmypid();
@mkdir($dir, 0700, true);
foreach ($kasus as $nama => $html) {
    file_put_contents("$dir/$nama.html", $html);
    foreach (['css', 'lanskap', 'potret'] as $orientasi) {
        $cmd = 'node ' . escapeshellarg($skrip) . ' ' . escapeshellarg("$dir/$nama.html") . ' ' . escapeshellarg("$dir/$nama-$orientasi.pdf") . ' --orientasi=' . $orientasi . ' 2>&1';
        $hasil = json_decode((string) shell_exec($cmd), true);
        $halaman = (int) ($hasil['jumlah_mediabox'] ?? -1);
        $assert($halaman === $lembarPer[$nama], "$nama $orientasi: $halaman halaman PDF = {$lembarPer[$nama]} lembar server");
    }
}

echo PHP_EOL . 'Ringkasan: ' . $lulus . ' lulus, ' . count($gagal) . ' gagal' . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
