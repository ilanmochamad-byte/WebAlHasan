<?php

declare(strict_types=1);

namespace App\Report;

final class PrintRenderer
{
    public static function report(array $report, string $reportType = 'Laporan Absensi Pengajian'): string
    {
        $filters = '';
        foreach ($report['active_filters'] as $label => $value) {
            $filters .= '<div><strong>' . self::e($label) . ':</strong> ' . self::e($value) . '</div>';
        }
        $rows = '';
        foreach ($report['items'] as $index => $row) {
            $rows .= '<tr><td>' . ($index + 1) . '</td><td>' . self::e($row['meeting_date']) . '</td>'
                . '<td>#' . self::e($row['schedule_id']) . '<br>' . self::e($row['subject']) . '</td>'
                . '<td>' . self::e($row['teacher_name']) . '</td><td>' . self::e($row['class_name']) . '</td>'
                . '<td>' . self::e($row['subject_type']) . '<br><span class="muted">' . self::e($row['identity_number']) . '</span></td>'
                . '<td>' . self::e($row['subject_name']) . '</td><td>' . self::e($row['attendance_status']) . '</td>'
                . '<td>' . self::e($row['notes'] ?? '-') . '</td><td>' . self::e($row['recorder_name'] ?? '-')
                . '<br><span class="muted">' . self::e($row['updated_at'] ?? '-') . '</span></td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="10" class="empty">Tidak ada data sesuai filter.</td></tr>';
        }
        $status = $report['summary']['statuses'];
        return '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width">'
            . '<title>' . self::e($reportType) . ' - Pesantren Al Hasan</title><style>'
            . '@page{size:A4 landscape;margin:14mm 9mm 16mm;@bottom-center{content:"Halaman " counter(page) " dari " counter(pages);font-size:8pt;color:#555}}'
            . '*{box-sizing:border-box}body{font-family:Arial,sans-serif;color:#17231c;font-size:9pt;margin:0}.report-nav{margin-bottom:12px}'
            . 'h1{font-size:18pt;margin:0 0 3px}h2{font-size:12pt;margin:0 0 10px}.identity{border-bottom:2px solid #176b3a;padding-bottom:8px;margin-bottom:8px}'
            . '.meta{display:grid;grid-template-columns:1fr 1fr;gap:3px 18px;margin-bottom:9px}.summary{display:flex;flex-wrap:wrap;gap:6px;margin:8px 0 10px}'
            . '.summary span{border:1px solid #ccd8cf;border-radius:5px;padding:4px 7px}.muted{color:#59665e;font-size:8pt}'
            . 'table{width:100%;border-collapse:collapse;table-layout:fixed}th,td{border:1px solid #bac8bd;padding:4px;vertical-align:top;overflow-wrap:anywhere}'
            . 'th{background:#e8f2ea;text-align:left}tr{break-inside:avoid}.empty{text-align:center;padding:20px}.page-footer{display:none}'
            . '@media print{.report-nav{display:none!important}body{print-color-adjust:exact;-webkit-print-color-adjust:exact}.page-footer{display:block;position:fixed;bottom:-11mm;left:0;right:0;text-align:center;font-size:8pt;color:#555}.page-footer:after{content:"Halaman " counter(page)}}'
            . '</style></head><body><div class="report-nav"><button type="button" onclick="window.print()">Cetak / Simpan PDF</button></div>'
            . '<header class="identity"><h1>Pesantren Al Hasan</h1><h2>' . self::e($reportType) . '</h2></header>'
            . '<section class="meta"><div><strong>Dibuat:</strong> ' . self::e($report['generated_at']) . '</div><div><strong>Pembuat:</strong> ' . self::e($report['created_by']) . '</div>' . $filters . '</section>'
            . '<section class="summary"><span><strong>Pertemuan:</strong> ' . self::e($report['summary']['meeting_count']) . '</span><span><strong>Baris detail:</strong> ' . self::e($report['summary']['detail_count']) . '</span>'
            . '<span>Hadir: ' . self::e($status['Hadir']) . '</span><span>Terlambat: ' . self::e($status['Terlambat']) . '</span><span>Izin: ' . self::e($status['Izin']) . '</span><span>Sakit: ' . self::e($status['Sakit']) . '</span><span>Alpa: ' . self::e($status['Alpa']) . '</span></section>'
            . '<table><colgroup><col style="width:3%"><col style="width:7%"><col style="width:11%"><col style="width:11%"><col style="width:8%"><col style="width:8%"><col style="width:12%"><col style="width:7%"><col style="width:15%"><col style="width:18%"></colgroup>'
            . '<thead><tr><th>No.</th><th>Tanggal</th><th>Jadwal</th><th>Guru</th><th>Kelas</th><th>Jenis/ID</th><th>Peserta</th><th>Status</th><th>Catatan</th><th>Pencatat/Perubahan</th></tr></thead><tbody>' . $rows . '</tbody></table>'
            . '<div class="page-footer"></div></body></html>';
    }

    private static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
