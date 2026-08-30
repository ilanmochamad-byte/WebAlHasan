<?php

declare(strict_types=1);

namespace App\Report;

/**
 * Halaman HTML ramah cetak untuk laporan absensi V1.
 *
 * Cacatnya sama persis dengan laporan perizinan V2 sebelum diperbaiki:
 * `@page { @bottom-center { content: "Halaman " counter(page) } }` yang tidak
 * didukung satu pun mesin cetak peramban, `.page-footer:after` dengan
 * `counter(page)` di dalam elemen `position: fixed` — yang dievaluasi WebKit
 * menjadi **0** sehingga tercetak "Halaman 0" — serta `bottom:-11mm` yang
 * menaruh footer di luar kotak halaman dan melahirkan halaman hantu.
 *
 * Karena itu laporan ini kini memakai mekanisme yang sama dengan V2:
 * `PrintLayout` memecah dokumen menjadi "lembar" di sisi SERVER dan setiap
 * lembar membawa teks "Halaman i dari n" miliknya sendiri. Alasan lengkapnya
 * ada pada dokumentasi kelas `PrintLayout`.
 *
 * Tanda tangan publik `report()` sengaja tidak berubah: pemanggilnya adalah
 * `admin/laporan_absensi_cetak.php` dan `GET /reports/print` pada API v1.
 */
final class PrintRenderer
{
    public const IDENTITAS = 'Pesantren Al Hasan';

    /**
     * Lebar kolom dalam persen; jumlahnya 100.
     *
     * Dipilih agar setiap KATA pada judul kolom muat utuh pada orientasi
     * tersempit (A4 potret). Kolom "Tanggal" dan "Jenis/ID" dilebarkan dari
     * versi lama justru karena keduanya yang paling mudah terpotong.
     * Status memakai 8% agar "Terlambat" utuh pada A4 potret; Catatan
     * tetap membungkus teks panjang dalam sisa lebar, tanpa mengecilkan font.
     *
     * @var array<int, int>
     */
    private const LEBAR_KOLOM = [4, 8, 11, 9, 7, 9, 11, 8, 15, 18];

    /** Judul kolom, sejajar dengan `LEBAR_KOLOM`. */
    private const JUDUL_KOLOM = [
        'No.', 'Tanggal', 'Jadwal', 'Guru', 'Kelas', 'Jenis/ID',
        'Peserta', 'Status', 'Catatan', 'Pencatat/Perubahan',
    ];

    /**
     * Tinggi blok kepala lengkap laporan absensi.
     *
     * Lebih ringkas daripada laporan perizinan (identitas + meta + ringkasan
     * satu baris chip), sehingga anggarannya ditimpa lewat `pecahLembar()`.
     */
    private const TINGGI_KEPALA_PERTAMA_MM = 58.0;

    /**
     * Tinggi kepala ringkas + cadangan fragmentasi Safari pada lembar lanjutan.
     *
     * PDF Safari nyata tanggal 28 Agustus 2026 membuktikan nilai 18 mm masih
     * mengizinkan 14 baris absensi pada lembar kedua. Tabelnya masuk, tetapi
     * footer `Halaman 2 dari 3` terdorong sendirian ke halaman fisik berikutnya.
     * Tambahan cadangan 8 mm memindahkan satu baris ke lembar berikutnya tanpa
     * mengecilkan font atau skala cetak, sehingga tabel dan footer tetap satu
     * kesatuan pada A4 lanskap Safari.
     */
    private const TINGGI_KEPALA_LANJUTAN_MM = 26.0;

    /**
     * @param array<string, mixed> $report
     */
    public static function report(array $report, string $reportType = 'Laporan Absensi Pengajian'): string
    {
        $items = array_values($report['items'] ?? []);

        // Sel dibangun sekali dan dipakai DUA kali: untuk memperkirakan tinggi
        // baris dan untuk merender. Dengan begitu perkiraan tidak pernah
        // menghitung teks yang berbeda dari yang benar-benar tercetak.
        $barisSel = [];
        foreach ($items as $index => $row) {
            $barisSel[] = self::selBaris($index, $row);
        }

        // Anggaran tinggi dihitung untuk A4 lanskap DAN potret sekaligus,
        // sehingga jumlah lembar sama dengan jumlah halaman fisik PDF pada
        // orientasi mana pun yang dipilih pengguna di dialog cetak.
        $lembar = PrintLayout::pecahLembarKolom(
            $barisSel,
            self::LEBAR_KOLOM,
            self::TINGGI_KEPALA_PERTAMA_MM,
            self::TINGGI_KEPALA_LANJUTAN_MM
        );
        $totalHalaman = count($lembar);

        $isi = '';
        foreach ($lembar as $nomor => $indeksBaris) {
            $halaman = $nomor + 1;
            $isi .= '<section class="lembar">'
                . ($halaman === 1
                    ? self::kepalaLengkap($report, $reportType)
                    : self::kepalaLanjutan($report, $reportType, $halaman, $totalHalaman))
                . self::tabel($barisSel, $indeksBaris)
                . PrintLayout::footerHalaman(
                    $halaman,
                    $totalHalaman,
                    self::IDENTITAS . ' — ' . $reportType
                )
                . '</section>';
        }

        return '<!doctype html><html lang="id"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, user-scalable=no">'
            . '<title>' . PrintLayout::e($reportType) . ' - ' . PrintLayout::e(self::IDENTITAS) . '</title>'
            . '<style>' . PrintLayout::cssDasar() . '</style></head><body>'
            . '<div class="report-nav"><button type="button" onclick="window.print()">Cetak / Simpan PDF</button></div>'
            . PrintLayout::petunjukOrientasi()
            . $isi
            . '</body></html>';
    }

    /**
     * @param array<string, mixed> $row
     * @return array<int, string>
     */
    private static function selBaris(int $index, array $row): array
    {
        return [
            (string) ($index + 1),
            // Tanggal tidak boleh pecah pada tanda hubung menjadi "2026-08-" / "25".
            '<span class="utuh">' . PrintLayout::e($row['meeting_date'] ?? '') . '</span>',
            '#' . PrintLayout::e($row['schedule_id'] ?? '') . '<br>'
                . PrintLayout::e($row['subject'] ?? ''),
            PrintLayout::e($row['teacher_name'] ?? ''),
            PrintLayout::e($row['class_name'] ?? ''),
            PrintLayout::e($row['subject_type'] ?? '') . '<br><span class="muted">'
                . PrintLayout::e($row['identity_number'] ?? '') . '</span>',
            PrintLayout::e($row['subject_name'] ?? ''),
            PrintLayout::e($row['attendance_status'] ?? ''),
            PrintLayout::e($row['notes'] ?? '-'),
            PrintLayout::e($row['recorder_name'] ?? '-') . '<br><span class="muted">'
                . PrintLayout::e($row['updated_at'] ?? '-') . '</span>',
        ];
    }

    /**
     * @param array<int, array<int, string>> $barisSel
     * @param array<int, int> $indeksBaris
     */
    private static function tabel(array $barisSel, array $indeksBaris): string
    {
        $colgroup = '';
        foreach (self::LEBAR_KOLOM as $lebar) {
            $colgroup .= '<col style="width:' . $lebar . '%">';
        }

        $judul = '';
        foreach (self::JUDUL_KOLOM as $teks) {
            $judul .= '<th>' . PrintLayout::e($teks) . '</th>';
        }

        $baris = '';
        foreach ($indeksBaris as $indeks) {
            $baris .= '<tr>';
            foreach ($barisSel[$indeks] as $sel) {
                $baris .= '<td>' . $sel . '</td>';
            }
            $baris .= '</tr>';
        }
        if ($baris === '') {
            $baris = '<tr><td colspan="' . count(self::JUDUL_KOLOM) . '" class="empty">'
                . 'Tidak ada data sesuai filter.</td></tr>';
        }

        return '<table><colgroup>' . $colgroup . '</colgroup>'
            . '<thead><tr>' . $judul . '</tr></thead>'
            . '<tbody>' . $baris . '</tbody></table>';
    }

    /**
     * @param array<string, mixed> $report
     */
    private static function kepalaLengkap(array $report, string $reportType): string
    {
        $filters = '';
        foreach (($report['active_filters'] ?? []) as $label => $value) {
            $filters .= '<div><strong>' . PrintLayout::e($label) . ':</strong> '
                . PrintLayout::e($value) . '</div>';
        }

        $status = $report['summary']['statuses'] ?? [];
        $chips = '';
        foreach (['Hadir', 'Terlambat', 'Izin', 'Sakit', 'Alpa'] as $nama) {
            $chips .= '<span>' . PrintLayout::e($nama) . ': <strong>'
                . PrintLayout::e($status[$nama] ?? 0) . '</strong></span>';
        }

        return '<header class="identity">'
            . '<h1>' . PrintLayout::e(self::IDENTITAS) . '</h1>'
            . '<h2>' . PrintLayout::e($reportType) . '</h2>'
            . '</header>'
            . '<section class="meta">'
            . '<div><strong>Dibuat:</strong> ' . PrintLayout::e($report['generated_at'] ?? '') . '</div>'
            . '<div><strong>Pembuat:</strong> ' . PrintLayout::e($report['created_by'] ?? '') . '</div>'
            . $filters
            . '</section>'
            . '<section class="summary">'
            . '<span><strong>Pertemuan:</strong> '
            . PrintLayout::e($report['summary']['meeting_count'] ?? 0) . '</span>'
            . '<span><strong>Baris detail:</strong> '
            . PrintLayout::e($report['summary']['detail_count'] ?? 0) . '</span>'
            . $chips
            . '</section>';
    }

    /**
     * Kepala ringkas untuk lembar kedua dan seterusnya, supaya satu halaman
     * yang terlepas dari berkasnya tetap dapat dipertanggungjawabkan.
     *
     * @param array<string, mixed> $report
     */
    private static function kepalaLanjutan(
        array $report,
        string $reportType,
        int $halaman,
        int $total
    ): string {
        return '<div class="lanjutan">'
            . '<strong>' . PrintLayout::e(self::IDENTITAS) . ' — ' . PrintLayout::e($reportType) . '</strong>'
            . '<span class="muted">' . PrintLayout::e($report['generated_at'] ?? '')
            . ' &middot; lanjutan halaman ' . $halaman . ' dari ' . $total . '</span>'
            . '</div>';
    }
}
