<?php

declare(strict_types=1);

namespace App\Database;

use mysqli;

/**
 * Pemeriksaan keberadaan kolom/tabel aditif (redesain website Tahap 6).
 *
 * Fitur migrasi 023 (`kamar.asrama`, `wali_bukan_ganda`) dipakai hanya bila
 * skemanya ada, sehingga kode tetap berjalan sebelum migrasi dijalankan dan
 * sesudah rollback. Hasil disimpan per koneksi selama satu permintaan.
 */
final class Schema
{
    /** @var array<string, bool> */
    private static array $cache = [];

    public static function hasColumn(mysqli $db, string $table, string $column): bool
    {
        return self::cached($db, $table . '.' . $column, 'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column]);
    }

    public static function hasTable(mysqli $db, string $table): bool
    {
        return self::cached($db, $table, 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
    }

    /** Dipakai uji migrasi yang mengubah skema di tengah satu proses. */
    public static function forget(): void
    {
        self::$cache = [];
    }

    /** @param array<int, string> $params */
    private static function cached(mysqli $db, string $key, string $sql, array $params): bool
    {
        $key = spl_object_id($db) . ':' . $key;
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            return self::$cache[$key] = false;
        }
        $stmt->bind_param(str_repeat('s', count($params)), ...$params);
        $ada = $stmt->execute() && (int) ($stmt->get_result()->fetch_row()[0] ?? 0) > 0;
        $stmt->close();

        return self::$cache[$key] = $ada;
    }
}
