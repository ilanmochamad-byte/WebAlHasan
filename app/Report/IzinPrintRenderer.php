<?php

declare(strict_types=1);

namespace App\Report;

/**
 * Halaman HTML ramah cetak untuk laporan perizinan V2.
 *
 * Kriteria penerimaan PRD Fase 5 mensyaratkan hasil cetak/PDF memuat:
 * identitas pesantren, filter aktif, pembuat laporan, waktu pembuatan,
 * keputusan, dan nomor halaman. Keenamnya dirender di bawah dan diperiksa
 * `tests/v2_phase5_integration.php`.
 *
 * Halaman ini juga menjadi sumber PDF pada aplikasi mobile: `expo-print`
 * (SDK 57) menerima HTML dan menghasilkan PDF, sehingga web dan aplikasi
 * mencetak dokumen yang identik dari data yang sama.
 *
 * Nomor halaman dirender dua kali dengan sengaja:
 *   - `@page { @bottom-center }` untuk mesin cetak berbasis Paged Media
 *     (dipakai `expo-print`/WebKit);
 *   - elemen `.page-footer` dengan `counter(page)` untuk Chrome/Firefox.
 * Keduanya hanya muncul saat mencetak, tidak saat halaman dibaca di layar.
 */
final class IzinPrintRenderer
{
    public const IDENTITAS = 'Pesantren Al Hasan';
    public const JUDUL = 'Laporan Perizinan Santri';

    /**
     * @param array<string, mixed> $report hasil `IzinReportService::document()`
     */
    public static function render(array $report): string
    {
        $ringkasan = $report['ringkasan'];
        $durasi = $report['durasi'];

        $filterRows = '';
        foreach ($report['filter_aktif'] as $label => $value) {
            $filterRows .= '<div><strong>' . self::e($label) . ':</strong> ' . self::e($value) . '</div>';
        }

        $rows = '';
        foreach ($report['items'] as $index => $row) {
            $rows .= '<tr>'
                . '<td>' . ($index + 1) . '</td>'
                . '<td>#' . self::e($row['id']) . '<br><span class="muted">' . self::e($row['sumber_label']) . '</span></td>'
                . '<td>' . self::e($row['nama_santri']) . '<br><span class="muted">' . self::e($row['nis']) . '</span></td>'
                . '<td>' . self::e($row['kamar_kelas_label']) . '</td>'
                . '<td>' . self::e($row['tgl_izin']) . '<br>&rarr; ' . self::e($row['tgl_kembali']) . '</td>'
                . '<td>' . self::e($row['alasan']) . '</td>'
                . '<td>' . self::e($row['pengurus_label']) . '<br><span class="muted">' . self::e($row['murobi_label']) . '</span></td>'
                . '<td>' . self::e($row['status']) . '</td>'
                . '<td>' . self::e($row['keputusan_label'])
                . '<br><span class="muted">' . self::e($row['keputusan_kapasitas'] ?? '-') . '</span>'
                . '<br><span class="muted">' . self::e($row['diputus_pada'] ?? '-') . '</span></td>'
                . '<td>' . self::e($row['keputusan_alasan'] ?? '-') . '</td>'
                . '<td>' . self::e($row['durasi_label']) . '</td>'
                . '</tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="11" class="empty">Tidak ada pengajuan yang cocok dengan filter dalam cakupan ini.</td></tr>';
        }

        $statusChips = '';
        foreach ($ringkasan['per_status'] as $status => $jumlah) {
            $statusChips .= '<span>' . self::e($status) . ': <strong>' . self::e((string) $jumlah) . '</strong></span>';
        }

        $catatanBatas = '';
        if (($report['terpotong'] ?? false) === true) {
            $catatanBatas = '<p class="peringatan">Hasil melebihi batas cetak '
                . self::e((string) IzinReportFilter::MAX_EXPORT_ROWS)
                . ' baris. Persempit filter agar seluruh baris tercetak.</p>';
        }

        return '<!doctype html><html lang="id"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . self::e(self::JUDUL) . ' - ' . self::e(self::IDENTITAS) . '</title>'
            . '<style>' . self::css() . '</style></head><body>'
            . '<div class="report-nav"><button type="button" onclick="window.print()">Cetak / Simpan PDF</button></div>'
            . '<header class="identity">'
            . '<h1>' . self::e(self::IDENTITAS) . '</h1>'
            . '<h2>' . self::e(self::JUDUL) . '</h2>'
            . '<div class="muted">' . self::e($report['cakupan_label']) . '</div>'
            . '</header>'
            . '<section class="meta">'
            . '<div><strong>Dibuat oleh:</strong> ' . self::e($report['dibuat_oleh']) . '</div>'
            . '<div><strong>Waktu pembuatan:</strong> ' . self::e($report['dibuat_pada']) . '</div>'
            . $filterRows
            . '</section>'
            . '<section class="summary">'
            . '<span>Total pengajuan: <strong>' . self::e((string) $ringkasan['total']) . '</strong></span>'
            . $statusChips
            . '<span>Data warisan: <strong>' . self::e((string) $ringkasan['legacy']) . '</strong></span>'
            . '<span>Median durasi keputusan: <strong>' . self::e($durasi['median_label']) . '</strong></span>'
            . '<span>Keputusan terhitung: <strong>' . self::e((string) $durasi['jumlah']) . '</strong></span>'
            . '</section>'
            . $catatanBatas
            . '<table><colgroup>'
            . '<col style="width:3%"><col style="width:7%"><col style="width:12%"><col style="width:8%">'
            . '<col style="width:9%"><col style="width:14%"><col style="width:11%"><col style="width:8%">'
            . '<col style="width:10%"><col style="width:12%"><col style="width:6%">'
            . '</colgroup><thead><tr>'
            . '<th>No.</th><th>ID / Sumber</th><th>Santri</th><th>Kamar / Kelas</th><th>Rentang izin</th>'
            . '<th>Alasan</th><th>Pengurus / Murobi</th><th>Status</th><th>Keputusan</th>'
            . '<th>Alasan keputusan</th><th>Durasi</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>'
            . '<footer class="tanda-tangan">'
            . '<div>Dicetak dari Sistem Perizinan Santri ' . self::e(self::IDENTITAS) . '.</div>'
            . '</footer>'
            . '<div class="page-footer"></div>'
            . '</body></html>';
    }

    private static function css(): string
    {
        return '@page{size:A4 landscape;margin:14mm 9mm 16mm;'
            . '@bottom-center{content:"Halaman " counter(page) " dari " counter(pages);font-size:8pt;color:#555}}'
            . '*{box-sizing:border-box}'
            . 'body{font-family:Arial,Helvetica,sans-serif;color:#17231c;font-size:9pt;margin:0;padding:10px}'
            . '.report-nav{margin-bottom:12px}'
            . 'h1{font-size:18pt;margin:0 0 3px}h2{font-size:12pt;margin:0 0 4px}'
            . '.identity{border-bottom:2px solid #176b3a;padding-bottom:8px;margin-bottom:8px}'
            . '.meta{display:grid;grid-template-columns:1fr 1fr;gap:3px 18px;margin-bottom:9px}'
            . '.summary{display:flex;flex-wrap:wrap;gap:6px;margin:8px 0 10px}'
            . '.summary span{border:1px solid #ccd8cf;border-radius:5px;padding:4px 7px}'
            . '.muted{color:#59665e;font-size:8pt}'
            . '.peringatan{border:1px solid #d0a34e;background:#fdf6e6;padding:6px 8px;border-radius:5px}'
            . 'table{width:100%;border-collapse:collapse;table-layout:fixed}'
            . 'th,td{border:1px solid #bac8bd;padding:4px;vertical-align:top;overflow-wrap:anywhere}'
            . 'th{background:#e8f2ea;text-align:left}tr{break-inside:avoid}'
            . '.empty{text-align:center;padding:20px}'
            . '.tanda-tangan{margin-top:12px;font-size:8pt;color:#59665e}'
            . '.page-footer{display:none}'
            . '@media print{.report-nav{display:none!important}'
            . 'body{print-color-adjust:exact;-webkit-print-color-adjust:exact;padding:0}'
            . '.page-footer{display:block;position:fixed;bottom:-11mm;left:0;right:0;text-align:center;font-size:8pt;color:#555}'
            . '.page-footer:after{content:"Halaman " counter(page)}}';
    }

    private static function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
