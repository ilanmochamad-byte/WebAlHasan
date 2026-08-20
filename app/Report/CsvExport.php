<?php

declare(strict_types=1);

namespace App\Report;

final class CsvExport
{
    public const HEADERS = [
        'ID Pertemuan', 'Tanggal', 'ID Jadwal', 'Tahun Ajaran', 'Guru Jadwal', 'Kelas',
        'Fan Ilmu', 'Kitab', 'Tempat', 'Jenis Peserta', 'Nomor Identitas', 'Nama Peserta',
        'Status Absensi', 'Catatan', 'Pencatat', 'Waktu Pencatatan', 'Waktu Perubahan',
    ];

    public static function encode(array $rows): string
    {
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            return '';
        }
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, self::HEADERS, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($stream, array_map([self::class, 'spreadsheetSafe'], [
                $row['meeting_id'], $row['meeting_date'], $row['schedule_id'], $row['academic_year'],
                $row['teacher_name'], $row['class_name'], $row['subject'], $row['book'], $row['place'],
                $row['subject_type'], $row['identity_number'], $row['subject_name'], $row['attendance_status'],
                $row['notes'] ?? '', $row['recorder_name'] ?? '', $row['recorded_at'] ?? '', $row['updated_at'] ?? '',
            ]), ',', '"', '');
        }
        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);
        return $contents === false ? '' : $contents;
    }

    private static function spreadsheetSafe(mixed $value): string
    {
        $text = (string) $value;
        if ($text !== '' && preg_match('/^[=+\-@]/u', $text)) {
            return "'" . $text;
        }
        return $text;
    }
}
