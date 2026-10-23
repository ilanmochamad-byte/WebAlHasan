<?php

declare(strict_types=1);

namespace App\Ui;

/**
 * Grafik sederhana yang dirender server (redesain website Tahap 3, N-4/K-8).
 *
 * Tanpa pustaka grafik dan tanpa JavaScript: batang memakai lebar CSS, donat
 * memakai `conic-gradient`, garis memakai SVG. Warna hanya dari token
 * (`.ah-chart__c1` … `.ah-chart__c6` di alhasan.css), angka selalu tertulis
 * sebagai teks, dan setiap grafik punya keadaan kosong.
 *
 * Murni presentasi; data dihitung halaman pemanggil.
 */
final class Chart
{
    /**
     * Batang mendatar: satu baris per kategori.
     *
     * @param array<string, int> $data label => nilai
     */
    public static function bar(string $title, array $data, string $emptyText = 'Belum ada data.', ?string $unit = null): string
    {
        $max = $data === [] ? 0 : max($data);
        $html = self::open($title);
        if ($max <= 0) {
            return $html . self::empty($emptyText) . '</figure>';
        }
        $html .= '<ul class="ah-chart__bars">';
        $index = 0;
        foreach ($data as $label => $value) {
            $width = $value > 0 ? max(1, (int) round(100 * $value / $max)) : 0;
            $html .= '<li><span class="ah-chart__label">' . Layout::escape($label) . '</span>'
                . '<span class="ah-chart__track"><span class="ah-chart__fill ah-chart__c' . (($index % 6) + 1) . '" style="width:' . $width . '%"></span></span>'
                . '<span class="ah-chart__num">' . self::number($value) . ($unit !== null ? ' ' . Layout::escape($unit) : '') . '</span></li>';
            $index++;
        }

        return $html . '</ul></figure>';
    }

    /**
     * Donat dengan legenda berangka dan persentase.
     *
     * @param array<string, int> $data label => nilai
     */
    public static function donut(string $title, array $data, string $emptyText = 'Belum ada data.'): string
    {
        $total = array_sum($data);
        $html = self::open($title);
        if ($total <= 0) {
            return $html . self::empty($emptyText) . '</figure>';
        }
        $stops = [];
        $start = 0.0;
        $index = 0;
        $legend = '';
        foreach ($data as $label => $value) {
            $color = 'var(--ah-chart-' . (($index % 6) + 1) . ')';
            $end = $start + 100 * $value / $total;
            if ($value > 0) {
                $stops[] = $color . ' ' . round($start, 2) . '% ' . round($end, 2) . '%';
            }
            $legend .= '<li><span class="ah-chart__key ah-chart__c' . (($index % 6) + 1) . '" aria-hidden="true"></span>'
                . '<span class="ah-chart__label">' . Layout::escape($label) . '</span>'
                . '<span class="ah-chart__num">' . self::number($value) . ' · ' . self::percent($value, $total) . '</span></li>';
            $start = $end;
            $index++;
        }

        return $html . '<div class="ah-chart__donut-wrap">'
            . '<div class="ah-chart__donut" style="background:conic-gradient(' . implode(', ', $stops) . ')" aria-hidden="true">'
            . '<span>' . self::number($total) . '<small>total</small></span></div>'
            . '<ul class="ah-chart__legend">' . $legend . '</ul></div></figure>';
    }

    /**
     * Garis untuk deret waktu (mis. kehadiran per pekan). Titik tanpa data
     * (null) memutus garis dan tertulis "—" pada tabel di bawahnya.
     *
     * @param array<string, float|int|null> $data label => nilai
     */
    public static function line(string $title, array $data, string $emptyText = 'Belum ada data.', string $unit = '%', float $max = 100.0): string
    {
        $html = self::open($title);
        $values = array_filter($data, static fn ($value): bool => $value !== null);
        if ($values === []) {
            return $html . self::empty($emptyText) . '</figure>';
        }
        $count = count($data);
        $width = 300;
        $height = 110;
        $step = $count > 1 ? $width / ($count - 1) : 0;
        $segments = [];
        $current = [];
        $dots = '';
        $i = 0;
        foreach ($data as $value) {
            if ($value === null) {
                if ($current !== []) {
                    $segments[] = $current;
                }
                $current = [];
            } else {
                $x = round($i * $step, 1);
                $y = round($height - ($height - 10) * min((float) $value, $max) / max($max, 1), 1);
                $current[] = $x . ',' . $y;
                $dots .= '<circle cx="' . $x . '" cy="' . $y . '" r="3"></circle>';
            }
            $i++;
        }
        if ($current !== []) {
            $segments[] = $current;
        }
        $paths = '';
        foreach ($segments as $points) {
            $paths .= '<polyline points="' . implode(' ', $points) . '"></polyline>';
        }
        $rows = '';
        foreach ($data as $label => $value) {
            $rows .= '<li><span class="ah-chart__label">' . Layout::escape($label) . '</span><span class="ah-chart__num">'
                . ($value === null ? '—' : Layout::escape(self::decimal((float) $value) . $unit)) . '</span></li>';
        }

        return $html . '<svg class="ah-chart__line" viewBox="-4 -4 ' . ($width + 8) . ' ' . ($height + 8) . '" preserveAspectRatio="none" aria-hidden="true">'
            . '<line class="ah-chart__axis" x1="0" y1="' . $height . '" x2="' . $width . '" y2="' . $height . '"></line>'
            . $paths . $dots . '</svg>'
            . '<details class="ah-chart__data"><summary>Lihat angka</summary><ul class="ah-chart__legend">' . $rows . '</ul></details></figure>';
    }

    /**
     * @param array<int, array{label:string, value:int, max:int, hint?:string}> $items
     */
    public static function progress(array $items): string
    {
        $html = '<ul class="ah-progress-list">';
        foreach ($items as $item) {
            $percent = $item['max'] > 0 ? (int) round(100 * $item['value'] / $item['max']) : 0;
            $html .= '<li><div class="ah-progress-list__item"><span class="ah-progress-list__label">' . Layout::escape($item['label']) . '</span>'
                . '<span class="ah-progress-list__bar"><i style="width:' . min(100, $percent) . '%"></i></span>'
                . '<span class="ah-progress-list__num">' . $percent . '%'
                . (isset($item['hint']) ? ' · ' . Layout::escape($item['hint']) : '') . '</span></div></li>';
        }

        return $html . '</ul>';
    }

    private static function open(string $title): string
    {
        return '<figure class="ah-chart"><figcaption class="ah-chart__title">' . Layout::escape($title) . '</figcaption>';
    }

    private static function empty(string $text): string
    {
        return '<p class="ah-chart__empty">' . Layout::escape($text) . '</p>';
    }

    private static function number(int|float $value): string
    {
        return number_format((float) $value, 0, ',', '.');
    }

    private static function decimal(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, ',', '.'), '0'), ',');
    }

    private static function percent(int $value, int $total): string
    {
        return $total > 0 ? self::decimal(100 * $value / $total) . '%' : '0%';
    }
}
