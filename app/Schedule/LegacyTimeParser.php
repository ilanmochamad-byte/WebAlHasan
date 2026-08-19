<?php

declare(strict_types=1);

namespace App\Schedule;

final class LegacyTimeParser
{
    public static function parse(string $original): array
    {
        $normalized = mb_strtolower(trim($original));
        $normalized = str_replace(['wib', '.', ' ', '–', '—', 's/d'], ['', ':', '', '-', '-', '-'], $normalized);
        if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)-([01]?\d|2[0-3]):([0-5]\d)$/', $normalized, $matches)) {
            return ['success' => false, 'original' => $original, 'normalized' => $normalized, 'reason' => 'Format waktu tidak dikenali dengan aman.'];
        }
        $start = sprintf('%02d:%02d:00', (int) $matches[1], (int) $matches[2]);
        $end = sprintf('%02d:%02d:00', (int) $matches[3], (int) $matches[4]);
        if ($start >= $end) {
            return ['success' => false, 'original' => $original, 'normalized' => $normalized, 'reason' => 'Waktu selesai tidak lebih akhir daripada waktu mulai.'];
        }
        return ['success' => true, 'original' => $original, 'normalized' => $normalized, 'start' => $start, 'end' => $end];
    }
}
