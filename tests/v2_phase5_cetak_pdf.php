<?php

declare(strict_types=1);

/**
 * Regresi cetak/PDF Fase 5 — MEMBUKTIKAN hasil PDF, bukan hanya string CSS.
 *
 * Latar belakang. PDF produksi (Safari, A4 potret) memperlihatkan tiga cacat
 * sekaligus, dan tidak satu pun tertangkap oleh pengujian lama yang hanya
 * mencari string `counter(page)` pada HTML:
 *
 *   1. footer tercetak "Halaman 0" — `counter(page)` di dalam elemen
 *      `position:fixed` bukan penghitung konteks halaman pada WebKit;
 *   2. muncul halaman hantu — `bottom:-11mm` menaruh footer di luar kotak
 *      halaman;
 *   3. kata terpotong di tengah ("Sumb er", "Disetuju i") — akibat
 *      `overflow-wrap:anywhere` pada kolom yang menyempit di orientasi potret.
 *
 * Karena itu berkas ini bekerja pada dua tingkat:
 *
 *   BAGIAN A — deterministik, tanpa peramban. Memeriksa pemecahan lembar dan
 *   penomoran halaman langsung dari HTML. Selalu dijalankan.
 *
 *   BAGIAN B — PDF sungguhan lewat Chromium (Playwright) pada tiga orientasi:
 *   `css` (Chrome menghormati `@page`), `lanskap`, dan `potret` (meniru Safari
 *   / dialog cetak macOS yang MENGABAIKAN `@page { size: ... }`). Memeriksa
 *   jumlah halaman fisik, nomor halaman pada tiap halaman, orientasi kertas,
 *   dan pemenggalan kata pada teks hasil ekstraksi. Dilewati dengan status
 *   MENUNGGU VERIFIKASI bila Node/Playwright/poppler tidak tersedia — TIDAK
 *   pernah dilaporkan lulus tanpa bukti.
 *
 * Pemakaian:
 *   php tests/v2_phase5_cetak_pdf.php
 *   V2_PHASE5_PDF_KELUARAN=/tmp/pdfbukti php tests/v2_phase5_cetak_pdf.php
 *
 * Prasyarat Bagian B:
 *   node (>=18), `playwright` terpasang dan dapat dimuat dari
 *   tests/browser/node_modules, serta `pdftotext`/`pdfinfo` (poppler-utils).
 */

use App\Report\IzinPrintRenderer;
use App\Report\PrintLayout;
use App\Report\PrintRenderer;

$root = dirname(__DIR__);
require_once $root . '/app/bootstrap.php';

$failures = [];
$tertunda = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? '[lulus] ' : '[gagal] ') . $message . PHP_EOL;
    if (!$condition) {
        $failures[] = $message;
    }
};
$menunggu = static function (string $message) use (&$tertunda): void {
    echo '[menunggu] ' . $message . PHP_EOL;
    $tertunda[] = $message;
};

// ---------------------------------------------------------------------------
// Fixture: dokumen berbentuk keluaran IzinReportService::document().
//
// Isinya sengaja memakai HURUF KAPITAL panjang (nama santri, nama kamar) dan
// kata-kata panjang, karena justru itulah yang dipotong di tengah pada PDF
// produksi.
// ---------------------------------------------------------------------------
$dokumenIzin = static function (int $jumlahBaris, bool $barisMaksimum = false): array {
    $items = [];
    for ($i = 1; $i <= $jumlahBaris; $i++) {
        $items[] = [
            'id' => $i,
            'is_legacy' => false,
            'sumber_label' => 'V2',
            'nis' => '24010' . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
            'nama_santri' => 'ADITYA FATHUROHMAN',
            'kamar_kelas_label' => 'Kamar ABDURAHMAN BIN AUF / Kelas 3 IBTIDAIYAH PA',
            'tgl_izin' => '2026-08-24',
            'tgl_kembali' => '2026-08-25',
            'alasan' => $barisMaksimum
                ? 'AWALIZ ' . mb_substr(str_repeat('Alasan izin dengan panjang maksimum. ', 60), 0, 1970)
                    . ' AKHIRIZ'
                : 'Menghadiri acara keluarga di kampung halaman bersama orang tua',
            'pengurus_label' => 'Adi Hidayat',
            'murobi_label' => 'ILAN MOCHAMAD FAUZAN',
            'status' => 'Disetujui',
            'keputusan_label' => 'Disetujui',
            'keputusan_kapasitas' => 'Murobi',
            'diputus_pada' => '2026-08-24 15:50:07',
            'keputusan_alasan' => $barisMaksimum
                ? 'AWALKEP '
                    . mb_substr(str_repeat('Alasan keputusan dengan panjang maksimum. ', 55), 0, 1960)
                    . ' AKHIRKEP'
                : 'Uji coba penerimaan',
            'durasi_label' => '8 menit',
        ];
    }

    return [
        'cakupan_label' => 'Admin — seluruh pengajuan',
        'ringkasan' => [
            'total' => $jumlahBaris,
            'legacy' => 0,
            'per_status' => [
                'Diajukan' => 0, 'Perlu Penetapan Admin' => 0,
                'Disetujui' => $jumlahBaris, 'Ditolak' => 0, 'Dibatalkan' => 0,
            ],
        ],
        'durasi' => [
            'jumlah' => $jumlahBaris, 'median_label' => '8 menit', 'min_label' => '8 menit',
            'maks_label' => '8 menit', 'rata_label' => '8 menit', 'median_detik' => 480,
        ],
        'items' => $items,
        'jumlah_baris' => $jumlahBaris,
        'terpotong' => false,
        'filter_aktif' => [
            'Cakupan' => 'Admin — seluruh pengajuan',
            'Rentang tanggal izin' => '2026-08-01 s.d. 2026-08-28',
            'Status' => 'Semua status',
            'Sumber data' => 'Semua sumber',
            'Santri' => 'Semua santri',
            'Pengurus' => 'Semua pengurus',
            'Murobi' => 'Semua murobi',
            'Kamar' => 'Semua kamar',
            'Kelas' => 'Semua kelas',
            'Tahun ajaran' => 'Semua tahun ajaran',
            'Durasi keputusan' => 'Semua durasi',
            'Kanal notifikasi' => 'Semua kanal',
            'Pencarian' => 'Tanpa kata kunci',
        ],
        'dibuat_oleh' => 'Administrator',
        'dibuat_pada' => '2026-08-28 11:01:03 WIB',
    ];
};

$dokumenAbsensi = static function (int $jumlahBaris): array {
    $items = [];
    for ($i = 1; $i <= $jumlahBaris; $i++) {
        $items[] = [
            'meeting_date' => '2026-08-20',
            'schedule_id' => 7,
            'subject' => 'Fikih Muamalah Kontemporer',
            'teacher_name' => 'USTADZ ABDURAHMAN',
            'class_name' => 'IBTIDA PA',
            'subject_type' => 'Santri',
            'identity_number' => '24010' . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
            'subject_name' => 'MUHAMMAD FATHUROHMAN',
            'attendance_status' => 'Hadir',
            'notes' => 'Mengikuti seluruh rangkaian pengajian tanpa keterlambatan.',
            'recorder_name' => 'Administrator',
            'updated_at' => '2026-08-20 10:00:00',
        ];
    }

    return [
        'active_filters' => ['Rentang tanggal' => '2026-08-01 s.d. 2026-08-20'],
        'items' => $items,
        'summary' => [
            'meeting_count' => $jumlahBaris,
            'detail_count' => $jumlahBaris,
            'statuses' => ['Hadir' => $jumlahBaris, 'Terlambat' => 0, 'Izin' => 0, 'Sakit' => 0, 'Alpa' => 0],
        ],
        'generated_at' => '2026-08-20 12:00:00 WIB',
        'created_by' => 'Administrator',
    ];
};

/** Mengambil pasangan (halaman, total) dari teks apa pun, berurutan. */
$nomorHalaman = static function (string $teks): array {
    preg_match_all('/Halaman\s+(\d+)\s+dari\s+(\d+)/u', $teks, $m);

    return array_map(
        static fn (string $h, string $t): array => [(int) $h, (int) $t],
        $m[1],
        $m[2]
    );
};

// ===========================================================================
echo '=== BAGIAN A. Penomoran halaman deterministik (tanpa peramban) ===' . PHP_EOL;
// ===========================================================================

// A1. Unit murni PrintLayout: dokumen kosong tetap satu lembar.
$assert(PrintLayout::pecahLembar([]) === [[]], 'A1 Dokumen tanpa baris tetap menghasilkan satu lembar');

// A2. Satu baris raksasa tidak boleh membuat pemecahan berputar tanpa maju.
$raksasa = PrintLayout::pecahLembar([PrintLayout::TINGGI_AREA_MM * 5]);
$assert(count($raksasa) === 1 && $raksasa[0] === [0], 'A2 Satu baris melebihi anggaran tetap dimuat pada satu lembar');

// A3. Anggaran lembar lanjutan lebih longgar daripada lembar pertama.
$assert(
    PrintLayout::anggaranLembar(false) > PrintLayout::anggaranLembar(true),
    'A3 Lembar lanjutan memiliki anggaran lebih besar daripada lembar pertama'
);

// A4. Perkiraan baris teks menghormati padding sel; kolom sempit tidak boleh
//     dianggap lebih lapang daripada kenyataannya (asal cacat "Duras i").
$assert(
    PrintLayout::barisTeks('Durasi', 6.0) >= PrintLayout::barisTeks('Durasi', 12.0),
    'A4 Kolom lebih sempit membutuhkan baris teks lebih banyak atau sama'
);
$assert(PrintLayout::barisTeks('', 20.0) === 1, 'A5 Sel kosong tetap dihitung satu baris');
$assert(
    PrintLayout::barisTeks('satu<br>dua<br>tiga', 60.0) === 3,
    'A6 Penanda <br> dihitung sebagai pemisah baris yang pasti'
);
$teksPanjang = trim((string) preg_replace('/\s+/u', ' ', str_repeat('teks audit panjang ', 40)));
$pecahanTeks = PrintLayout::pecahTeks($teksPanjang, 80);
$assert(
    implode(' ', $pecahanTeks) === $teksPanjang
        && max(array_map('mb_strlen', $pecahanTeks)) <= 80,
    'A6b Pemecahan teks panjang tidak membuang isi dan menghormati batas fragmen'
);

/**
 * Pemeriksaan penomoran bersama untuk kedua laporan.
 *
 * @param callable(int): string $render
 */
$periksaHtml = static function (string $label, callable $render, int $jumlahBaris) use ($assert, $nomorHalaman): array {
    $html = $render($jumlahBaris);
    $lembar = substr_count($html, '<section class="lembar">');
    $nomor = $nomorHalaman($html);

    $assert($lembar >= 1, $label . ' menghasilkan sekurang-kurangnya satu lembar (' . $lembar . ')');
    $assert(!str_contains($html, 'Halaman 0'), $label . ' tidak memuat "Halaman 0"');
    $assert(
        count($nomor) === $lembar,
        $label . ' memuat tepat satu nomor halaman per lembar (' . count($nomor) . '/' . $lembar . ')'
    );
    $harusnya = [];
    for ($i = 1; $i <= $lembar; $i++) {
        $harusnya[] = [$i, $lembar];
    }
    $assert($nomor === $harusnya, $label . ' bernomor berurutan 1..' . $lembar . ' dengan total yang konsisten');
    $assert(
        !str_contains($html, 'counter(page)') && !str_contains($html, 'position:fixed'),
        $label . ' tidak memakai counter(page) maupun footer position:fixed'
    );
    $assert(
        str_contains($html, 'Pesantren Al Hasan'),
        $label . ' memuat identitas pesantren'
    );

    return ['html' => $html, 'lembar' => $lembar];
};

echo PHP_EOL . '--- A7. Laporan perizinan V2 ---' . PHP_EOL;
$izinSatu = $periksaHtml('A7 Perizinan 3 baris', static fn (int $n): string => IzinPrintRenderer::render($dokumenIzin($n)), 3);
$izinKosong = $periksaHtml('A8 Perizinan kosong', static fn (int $n): string => IzinPrintRenderer::render($dokumenIzin($n)), 0);
$izinBanyak = $periksaHtml('A9 Perizinan 40 baris', static fn (int $n): string => IzinPrintRenderer::render($dokumenIzin($n)), 40);
$izinBarisMaksimum = $periksaHtml(
    'A9b Perizinan dengan alasan panjang maksimum',
    static fn (int $n): string => IzinPrintRenderer::render($dokumenIzin($n, true)),
    1
);

$assert($izinSatu['lembar'] === 1, 'A10 Perizinan 3 baris muat dalam satu lembar');
$assert($izinKosong['lembar'] === 1, 'A11 Perizinan kosong tetap satu lembar bernomor');
$assert($izinBanyak['lembar'] > 1, 'A12 Perizinan 40 baris terpecah menjadi ' . $izinBanyak['lembar'] . ' lembar');
$assert(
    substr_count($izinBarisMaksimum['html'], 'Lanjutan') > 0,
    'A12b Alasan panjang maksimum dipecah menjadi baris lanjutan'
);
$assert(
    str_contains($izinBarisMaksimum['html'], 'AWALIZ')
        && str_contains($izinBarisMaksimum['html'], 'AKHIRIZ')
        && str_contains($izinBarisMaksimum['html'], 'AWALKEP')
        && str_contains($izinBarisMaksimum['html'], 'AKHIRKEP'),
    'A12c Awal dan akhir alasan izin/keputusan tetap dicetak tanpa kehilangan isi'
);

// Identitas, pembuat, waktu, dan judul tetap dibawa pada SETIAP lembar,
// supaya satu halaman yang terlepas tetap dapat dipertanggungjawabkan.
$assert(
    substr_count($izinBanyak['html'], 'Pesantren Al Hasan') >= $izinBanyak['lembar'],
    'A13 Identitas pesantren hadir pada setiap lembar'
);
$assert(
    substr_count($izinBanyak['html'], '2026-08-28 11:01:03 WIB') >= $izinBanyak['lembar'],
    'A14 Waktu pembuatan hadir pada setiap lembar'
);
$assert(
    str_contains($izinBanyak['html'], 'Administrator')
        && str_contains($izinBanyak['html'], 'Rentang tanggal izin')
        && str_contains($izinBanyak['html'], 'Median durasi keputusan')
        && str_contains($izinBanyak['html'], 'Keputusan'),
    'A15 Pembuat, filter aktif, ringkasan, dan keputusan tetap tersedia'
);
// Judul tabel diulang pada tiap lembar supaya kesebelas kolom tetap terbaca.
$assert(
    substr_count($izinBanyak['html'], '<thead>') === $izinBanyak['lembar'],
    'A16 Judul kolom diulang pada setiap lembar'
);
$assert(
    substr_count($izinBanyak['html'], '<col style="width:') === 11 * $izinBanyak['lembar'],
    'A17 Kesebelas kolom dirender pada setiap lembar'
);
// Setiap baris data muncul tepat sekali: tidak hilang, tidak terduplikasi,
// dan karena itu tidak mungkin terbelah antarhalaman.
$assert(
    substr_count($izinBanyak['html'], '<tr><td>') === 40,
    'A18 Keempat puluh baris data dirender tepat sekali'
);

echo PHP_EOL . '--- A19. Laporan absensi V1 ---' . PHP_EOL;
$absensiSatu = $periksaHtml('A19 Absensi 3 baris', static fn (int $n): string => PrintRenderer::report($dokumenAbsensi($n)), 3);
$absensiKosong = $periksaHtml('A20 Absensi kosong', static fn (int $n): string => PrintRenderer::report($dokumenAbsensi($n)), 0);
$absensiBanyak = $periksaHtml('A21 Absensi 400 baris', static fn (int $n): string => PrintRenderer::report($dokumenAbsensi($n)), 400);

$assert($absensiSatu['lembar'] === 1, 'A22 Absensi 3 baris muat dalam satu lembar');
$assert($absensiKosong['lembar'] === 1, 'A23 Absensi kosong tetap satu lembar bernomor');
$assert($absensiBanyak['lembar'] > 1, 'A24 Absensi 400 baris terpecah menjadi ' . $absensiBanyak['lembar'] . ' lembar');
$assert(
    substr_count($absensiBanyak['html'], '<tr><td>') === 400,
    'A25 Keempat ratus baris absensi dirender tepat sekali'
);
$assert(
    str_contains($absensiSatu['html'], 'Laporan Absensi Pengajian')
        && str_contains($absensiSatu['html'], 'Pembuat:')
        && str_contains($absensiSatu['html'], 'Dibuat:'),
    'A26 Laporan absensi tetap memuat judul, pembuat, dan waktu pembuatan'
);

// ===========================================================================
echo PHP_EOL . '=== BAGIAN B. PDF sungguhan (Chromium) ===' . PHP_EOL;
// ===========================================================================

$keluaran = getenv('V2_PHASE5_PDF_KELUARAN') ?: sys_get_temp_dir() . '/v2-phase5-pdf';
$skrip = $root . '/tests/browser/cetak-pdf.mjs';

$adaPerintah = static function (string $perintah): bool {
    exec('command -v ' . escapeshellarg($perintah) . ' 2>/dev/null', $out, $kode);

    return $kode === 0;
};

$siap = true;
foreach (['node', 'pdftotext', 'pdfinfo'] as $perintah) {
    if (!$adaPerintah($perintah)) {
        $menunggu('Perintah "' . $perintah . '" tidak tersedia — verifikasi PDF ditunda');
        $siap = false;
    }
}
if ($siap && !is_dir($root . '/tests/browser/node_modules/playwright')) {
    $menunggu('Paket playwright belum terpasang di tests/browser/node_modules — verifikasi PDF ditunda');
    $siap = false;
}
if (!is_file($skrip)) {
    $menunggu('tests/browser/cetak-pdf.mjs tidak ditemukan — verifikasi PDF ditunda');
    $siap = false;
}

if ($siap) {
    if (!is_dir($keluaran) && !mkdir($keluaran, 0775, true) && !is_dir($keluaran)) {
        $menunggu('Folder keluaran ' . $keluaran . ' tidak dapat dibuat — verifikasi PDF ditunda');
        $siap = false;
    }
}

if (!$siap) {
    echo PHP_EOL . 'BAGIAN B DILEWATI — status MENUNGGU VERIFIKASI, bukan LULUS.' . PHP_EOL;
} else {
    /**
     * Merender satu HTML menjadi PDF dan mengembalikan fakta terukur.
     *
     * @return array{halaman:int, teks:string, lebar:float, tinggi:float, orientasi:string}|null
     */
    $render = static function (string $html, string $nama, string $orientasi) use ($keluaran, $skrip, $root): ?array {
        $berkasHtml = $keluaran . '/' . $nama . '.html';
        $berkasPdf = $keluaran . '/' . $nama . '-' . $orientasi . '.pdf';
        file_put_contents($berkasHtml, $html);

        $perintah = 'cd ' . escapeshellarg($root) . ' && node ' . escapeshellarg($skrip) . ' '
            . escapeshellarg($berkasHtml) . ' ' . escapeshellarg($berkasPdf)
            . ' --orientasi=' . escapeshellarg($orientasi) . ' 2>&1';
        exec($perintah, $keluaranBaris, $kode);
        if ($kode !== 0 || !is_file($berkasPdf)) {
            echo '        (render gagal: ' . implode(' | ', $keluaranBaris) . ')' . PHP_EOL;

            return null;
        }
        $info = json_decode((string) end($keluaranBaris), true);

        exec('pdfinfo ' . escapeshellarg($berkasPdf) . ' 2>/dev/null', $barisInfo);
        $halaman = 0;
        foreach ($barisInfo as $baris) {
            if (preg_match('/^Pages:\s*(\d+)/', $baris, $m)) {
                $halaman = (int) $m[1];
            }
        }

        // -layout mempertahankan tata letak kolom, sehingga pemenggalan kata
        // yang dilakukan mesin cetak ikut terlihat pada teks hasil ekstraksi.
        exec('pdftotext -layout ' . escapeshellarg($berkasPdf) . ' - 2>/dev/null', $barisTeks);

        return [
            'berkas' => $berkasPdf,
            'halaman' => $halaman,
            'teks' => implode("\n", $barisTeks),
            'lebar' => (float) ($info['lebar_pt'] ?? 0),
            'tinggi' => (float) ($info['tinggi_pt'] ?? 0),
            'orientasi' => (string) ($info['orientasi_hasil'] ?? '?'),
        ];
    };

    /**
     * Kata-kata yang WAJIB utuh pada teks hasil ekstraksi.
     *
     * Semuanya benar-benar terpotong pada PDF produksi: judul kolom
     * ("Sumber" → "Sumb er", "Durasi" → "Duras i") dan isi
     * ("FATHUROHMAN" → "FATHUROH MAN").
     */
    $kataUtuh = [
        'Sumber', 'Santri', 'Kamar', 'Rentang', 'Alasan', 'Pengurus', 'Murobi',
        'Status', 'Keputusan', 'Durasi', 'FATHUROHMAN', 'ABDURAHMAN', 'Disetujui',
        'IBTIDAIYAH', 'MOCHAMAD', 'Administrator',
    ];

    $kasus = [
        'izin-1hal' => ['html' => $izinSatu['html'], 'lembar' => $izinSatu['lembar'], 'kata' => true],
        'izin-banyakhal' => ['html' => $izinBanyak['html'], 'lembar' => $izinBanyak['lembar'], 'kata' => true],
        'izin-baris-maksimum' => [
            'html' => $izinBarisMaksimum['html'],
            'lembar' => $izinBarisMaksimum['lembar'],
            'kata' => false,
            'penanda' => ['AWALIZ', 'AKHIRIZ', 'AWALKEP', 'AKHIRKEP'],
        ],
        'absensi-1hal' => ['html' => $absensiSatu['html'], 'lembar' => $absensiSatu['lembar'], 'kata' => false],
        'absensi-banyakhal' => ['html' => $absensiBanyak['html'], 'lembar' => $absensiBanyak['lembar'], 'kata' => false],
    ];

    foreach ($kasus as $nama => $kasusIni) {
        echo PHP_EOL . '--- B. ' . $nama . ' ---' . PHP_EOL;
        foreach (['css', 'lanskap', 'potret'] as $orientasi) {
            $hasil = $render($kasusIni['html'], $nama, $orientasi);
            $assert($hasil !== null, 'B ' . $nama . '/' . $orientasi . ' berhasil dirender menjadi PDF');
            if ($hasil === null) {
                continue;
            }

            // Kriteria 3: tidak boleh ada "Halaman 0" pada PDF sungguhan.
            $assert(
                !str_contains($hasil['teks'], 'Halaman 0'),
                'B ' . $nama . '/' . $orientasi . ' tidak memuat "Halaman 0"'
            );

            // Kriteria 4: jumlah halaman fisik = jumlah lembar yang dihitung
            // server, sehingga nomor halaman tidak mungkin meleset.
            $assert(
                $hasil['halaman'] === $kasusIni['lembar'],
                'B ' . $nama . '/' . $orientasi . ' menghasilkan ' . $hasil['halaman']
                    . ' halaman fisik = ' . $kasusIni['lembar'] . ' lembar'
            );

            $nomor = $nomorHalaman($hasil['teks']);
            $harusnya = [];
            for ($i = 1; $i <= $kasusIni['lembar']; $i++) {
                $harusnya[] = [$i, $kasusIni['lembar']];
            }
            $assert(
                $nomor === $harusnya,
                'B ' . $nama . '/' . $orientasi . ' bernomor 1..' . $kasusIni['lembar'] . ' berurutan, satu per halaman'
            );

            // Kriteria 1: Chrome menghormati @page landscape.
            if ($orientasi === 'css') {
                $assert(
                    $hasil['orientasi'] === 'lanskap',
                    'B ' . $nama . ' pada mesin yang menghormati @page menghasilkan A4 lanskap ('
                        . $hasil['lebar'] . '×' . $hasil['tinggi'] . ' pt)'
                );
            }
            if ($orientasi === 'potret') {
                $assert(
                    $hasil['orientasi'] === 'potret',
                    'B ' . $nama . ' pada mesin yang mengabaikan @page benar-benar diuji sebagai potret'
                );
            }

            // Kriteria 7: kata tidak boleh terpotong di tengah. Ekstraksi
            // memakai -layout, sehingga potongan mesin cetak ikut terbawa.
            if ($kasusIni['kata']) {
                $rusak = [];
                $rapat = preg_replace('/[ \t]+/', ' ', $hasil['teks']) ?? '';
                foreach ($kataUtuh as $kata) {
                    if (!str_contains($rapat, $kata)) {
                        $rusak[] = $kata;
                    }
                }
                $assert(
                    $rusak === [],
                    'B ' . $nama . '/' . $orientasi . ' menjaga kata tetap utuh'
                        . ($rusak === [] ? '' : ' (terpotong: ' . implode(', ', $rusak) . ')')
                );

                // Tanggal tidak boleh pecah pada tanda hubung.
                $assert(
                    !preg_match('/\d{4}-\d{2}-\s*\n/', $hasil['teks']),
                    'B ' . $nama . '/' . $orientasi . ' tidak memecah tanggal pada tanda hubung'
                );
            }

            if (isset($kasusIni['penanda'])) {
                // Kolom potret dapat membungkus token tepat setelah tanda
                // hubung. Abaikan whitespace tata letak, tetapi tetap tuntut
                // seluruh karakter penanda hadir berurutan.
                $teksRapat = preg_replace('/\s+/u', '', $hasil['teks']) ?? '';
                $hilang = array_values(array_filter(
                    $kasusIni['penanda'],
                    static fn (string $penanda): bool => !str_contains(
                        $teksRapat,
                        preg_replace('/\s+/u', '', $penanda) ?? $penanda
                    )
                ));
                $assert(
                    $hilang === [],
                    'B ' . $nama . '/' . $orientasi . ' mempertahankan awal dan akhir seluruh alasan panjang'
                        . ($hilang === [] ? '' : ' (hilang: ' . implode(', ', $hilang) . ')')
                );
            }
        }
    }

    echo PHP_EOL . 'PDF pembanding tersimpan di: ' . $keluaran . PHP_EOL;
}

// ---------------------------------------------------------------------------
echo PHP_EOL . '=== Ringkasan ===' . PHP_EOL;
// ---------------------------------------------------------------------------

if ($tertunda !== []) {
    echo 'MENUNGGU VERIFIKASI (' . count($tertunda) . '):' . PHP_EOL;
    foreach ($tertunda as $catatan) {
        echo '  - ' . $catatan . PHP_EOL;
    }
}
if ($failures !== []) {
    echo 'GAGAL (' . count($failures) . '):' . PHP_EOL;
    foreach ($failures as $pesan) {
        echo '  - ' . $pesan . PHP_EOL;
    }
    exit(1);
}
echo 'Seluruh pemeriksaan cetak/PDF yang dapat dijalankan LULUS.' . PHP_EOL;
exit(0);
