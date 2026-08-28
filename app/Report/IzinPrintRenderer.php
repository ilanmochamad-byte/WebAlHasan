<?php

declare(strict_types=1);

namespace App\Report;

/**
 * Halaman HTML ramah cetak untuk laporan perizinan V2.
 *
 * Kriteria penerimaan PRD Fase 5 mensyaratkan hasil cetak/PDF memuat:
 * identitas pesantren, filter aktif, pembuat laporan, waktu pembuatan,
 * keputusan, dan nomor halaman. Keenamnya dirender di bawah.
 *
 * Halaman ini juga menjadi sumber PDF pada aplikasi mobile: `expo-print`
 * (SDK 57) menerima HTML dan menghasilkan PDF, sehingga web dan aplikasi
 * mencetak dokumen yang identik dari data yang sama.
 *
 * NOMOR HALAMAN DIHITUNG SERVER, bukan oleh CSS. Dokumen dipecah menjadi
 * "lembar" oleh `PrintLayout`, dan tiap lembar membawa teks "Halaman i dari n"
 * miliknya sendiri. Alasannya dijelaskan panjang lebar pada `PrintLayout`:
 * `counter(page)` di dalam elemen `position: fixed` menghasilkan **Halaman 0**
 * pada WebKit/Safari, sedangkan kotak margin `@page { @bottom-center }` tidak
 * didukung satu pun mesin cetak peramban.
 */
final class IzinPrintRenderer
{
    public const IDENTITAS = 'Pesantren Al Hasan';
    public const JUDUL = 'Laporan Perizinan Santri';

    /**
     * Lebar kolom tabel dalam persen. Jumlahnya 100.
     *
     * Dipakai dua kali: sebagai `<colgroup>` saat merender, dan sebagai dasar
     * perkiraan tinggi baris. Menyimpannya di satu tempat membuat keduanya
     * tidak mungkin menyimpang.
     *
     * @var array<int, float>
     */
    private const LEBAR_KOLOM = [4, 7, 12, 11, 10, 12, 11, 7, 10, 10, 6];

    /** Judul kolom, sejajar dengan `LEBAR_KOLOM`. */
    private const JUDUL_KOLOM = [
        'No.', 'ID / Sumber', 'Santri', 'Kamar / Kelas', 'Rentang izin', 'Alasan',
        'Pengurus / Murobi', 'Status', 'Keputusan', 'Alasan keputusan', 'Durasi',
    ];

    /**
     * @param array<string, mixed> $report hasil `IzinReportService::document()`
     */
    public static function render(array $report): string
    {
        $items = array_values($report['items'] ?? []);

        // 1. Bangun sel setiap baris lebih dulu. Sel yang sama dipakai untuk
        //    memperkirakan tinggi DAN untuk merender, sehingga perkiraan tidak
        //    pernah menghitung teks yang berbeda dari yang benar-benar dicetak.
        $barisSel = [];
        foreach ($items as $index => $row) {
            $barisSel[] = self::selBaris($index, $row);
        }

        // 2. Pecah menjadi lembar. `PrintLayout` menganggarkan tinggi untuk A4
        //    lanskap DAN potret sekaligus, sehingga jumlah lembar di sini sama
        //    dengan jumlah halaman fisik PDF pada orientasi mana pun.
        $lembar = PrintLayout::pecahLembarKolom($barisSel, self::LEBAR_KOLOM);
        $totalHalaman = count($lembar);

        $catatanBatas = '';
        if (($report['terpotong'] ?? false) === true) {
            $catatanBatas = '<p class="peringatan">Hasil melebihi batas cetak '
                . PrintLayout::e((string) IzinReportFilter::MAX_EXPORT_ROWS)
                . ' baris. Persempit filter agar seluruh baris tercetak.</p>';
        }

        $isi = '';
        foreach ($lembar as $nomor => $indeksBaris) {
            $halaman = $nomor + 1;
            $isi .= '<section class="lembar">'
                . ($halaman === 1
                    ? self::kepalaLengkap($report) . $catatanBatas
                    : self::kepalaLanjutan($report, $halaman, $totalHalaman))
                . self::tabel($barisSel, $indeksBaris)
                . ($halaman === $totalHalaman ? self::tandaTangan() : '')
                . PrintLayout::footerHalaman(
                    $halaman,
                    $totalHalaman,
                    self::IDENTITAS . ' — ' . self::JUDUL
                )
                . '</section>';
        }

        return '<!doctype html><html lang="id"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . PrintLayout::e(self::JUDUL) . ' - ' . PrintLayout::e(self::IDENTITAS) . '</title>'
            . '<style>' . PrintLayout::cssDasar() . '</style></head><body>'
            . '<div class="report-nav"><button type="button" onclick="window.print()">Cetak / Simpan PDF</button></div>'
            . PrintLayout::petunjukOrientasi()
            . $isi
            . '</body></html>';
    }

    /**
     * Sel satu baris tabel, berurutan sesuai `JUDUL_KOLOM`.
     *
     * `<br>` dipertahankan sebagai penanda baris baru; `PrintLayout::barisTeks()`
     * menghitungnya sebagai pemisah baris yang pasti.
     *
     * @param array<string, mixed> $row
     * @return array<int, string>
     */
    private static function selBaris(int $index, array $row): array
    {
        return [
            (string) ($index + 1),
            '#' . PrintLayout::e($row['id'] ?? '') . '<br><span class="muted">'
                . PrintLayout::e($row['sumber_label'] ?? '') . '</span>',
            PrintLayout::e($row['nama_santri'] ?? '') . '<br><span class="muted">'
                . PrintLayout::e($row['nis'] ?? '') . '</span>',
            PrintLayout::e($row['kamar_kelas_label'] ?? ''),
            '<span class="utuh">' . PrintLayout::e($row['tgl_izin'] ?? '') . '</span><br>&rarr; '
                . '<span class="utuh">' . PrintLayout::e($row['tgl_kembali'] ?? '') . '</span>',
            PrintLayout::e($row['alasan'] ?? ''),
            PrintLayout::e($row['pengurus_label'] ?? '') . '<br><span class="muted">'
                . PrintLayout::e($row['murobi_label'] ?? '') . '</span>',
            PrintLayout::e($row['status'] ?? ''),
            PrintLayout::e($row['keputusan_label'] ?? '')
                . '<br><span class="muted">' . PrintLayout::e($row['keputusan_kapasitas'] ?? '-') . '</span>'
                . '<br><span class="muted">' . PrintLayout::e($row['diputus_pada'] ?? '-') . '</span>',
            PrintLayout::e($row['keputusan_alasan'] ?? '-'),
            PrintLayout::e($row['durasi_label'] ?? ''),
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
                . 'Tidak ada pengajuan yang cocok dengan filter dalam cakupan ini.</td></tr>';
        }

        return '<table><colgroup>' . $colgroup . '</colgroup>'
            . '<thead><tr>' . $judul . '</tr></thead>'
            . '<tbody>' . $baris . '</tbody></table>';
    }

    /**
     * @param array<string, mixed> $report
     */
    private static function kepalaLengkap(array $report): string
    {
        $ringkasan = $report['ringkasan'];
        $durasi = $report['durasi'];

        $filterRows = '';
        foreach ($report['filter_aktif'] as $label => $value) {
            $filterRows .= '<div><strong>' . PrintLayout::e($label) . ':</strong> '
                . PrintLayout::e($value) . '</div>';
        }

        $statusChips = '';
        foreach ($ringkasan['per_status'] as $status => $jumlah) {
            $statusChips .= '<span>' . PrintLayout::e($status) . ': <strong>'
                . PrintLayout::e((string) $jumlah) . '</strong></span>';
        }

        return '<header class="identity">'
            . '<h1>' . PrintLayout::e(self::IDENTITAS) . '</h1>'
            . '<h2>' . PrintLayout::e(self::JUDUL) . '</h2>'
            . '<div class="muted">' . PrintLayout::e($report['cakupan_label']) . '</div>'
            . '</header>'
            . '<section class="meta">'
            . '<div><strong>Dibuat oleh:</strong> ' . PrintLayout::e($report['dibuat_oleh']) . '</div>'
            . '<div><strong>Waktu pembuatan:</strong> ' . PrintLayout::e($report['dibuat_pada']) . '</div>'
            . $filterRows
            . '</section>'
            . '<section class="summary">'
            . '<span>Total pengajuan: <strong>' . PrintLayout::e((string) $ringkasan['total']) . '</strong></span>'
            . $statusChips
            . '<span>Data warisan: <strong>' . PrintLayout::e((string) $ringkasan['legacy']) . '</strong></span>'
            . '<span>Median durasi keputusan: <strong>' . PrintLayout::e($durasi['median_label']) . '</strong></span>'
            . '<span>Keputusan terhitung: <strong>' . PrintLayout::e((string) $durasi['jumlah']) . '</strong></span>'
            . '</section>';
    }

    /**
     * Kepala ringkas untuk lembar kedua dan seterusnya.
     *
     * Identitas pesantren dan waktu pembuatan tetap dibawa pada SETIAP lembar,
     * supaya satu halaman yang terlepas dari berkasnya masih dapat
     * dipertanggungjawabkan.
     *
     * @param array<string, mixed> $report
     */
    private static function kepalaLanjutan(array $report, int $halaman, int $total): string
    {
        return '<div class="lanjutan">'
            . '<strong>' . PrintLayout::e(self::IDENTITAS) . ' — ' . PrintLayout::e(self::JUDUL) . '</strong>'
            . '<span class="muted">' . PrintLayout::e($report['cakupan_label'])
            . ' &middot; ' . PrintLayout::e($report['dibuat_pada'])
            . ' &middot; lanjutan halaman ' . $halaman . ' dari ' . $total . '</span>'
            . '</div>';
    }

    private static function tandaTangan(): string
    {
        return '<footer class="tanda-tangan">'
            . '<div>Dicetak dari Sistem Perizinan Santri ' . PrintLayout::e(self::IDENTITAS) . '.</div>'
            . '</footer>';
    }
}
