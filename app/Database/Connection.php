<?php

declare(strict_types=1);

namespace App\Database;

use mysqli;
use RuntimeException;

final class Connection
{
    private static ?mysqli $instance = null;

    public static function get(array $config): mysqli
    {
        if (self::$instance instanceof mysqli) {
            return self::$instance;
        }

        mysqli_report(MYSQLI_REPORT_OFF);
        $connection = @new mysqli(
            $config['host'],
            $config['username'],
            $config['password'],
            $config['database'],
            $config['port']
        );

        if ($connection->connect_errno) {
            throw new RuntimeException('Koneksi basis data tidak tersedia. Periksa konfigurasi environment.');
        }

        if (!$connection->set_charset($config['charset'])) {
            throw new RuntimeException('Character set basis data tidak dapat diterapkan.');
        }

        self::$instance = $connection;

        return self::$instance;
    }
}

