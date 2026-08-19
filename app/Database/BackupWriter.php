<?php

declare(strict_types=1);

namespace App\Database;

use mysqli;
use RuntimeException;

final class BackupWriter
{
    public function __construct(private mysqli $db)
    {
    }

    public function write(string $path): array
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('File backup tidak dapat dibuat: ' . $path);
        }

        $tables = $this->tables();
        fwrite($handle, "-- Backup pra-migrasi Sistem Informasi Pesantren Al Hasan\n");
        fwrite($handle, '-- Dibuat: ' . date(DATE_ATOM) . "\n");
        fwrite($handle, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

        $counts = [];
        foreach ($tables as $table) {
            $escapedTable = '`' . str_replace('`', '``', $table) . '`';
            $columns = $this->insertableColumns($table);
            if ($columns === []) {
                fclose($handle);
                throw new RuntimeException('Tabel tidak memiliki kolom yang dapat diekspor: ' . $table);
            }
            $escapedColumns = array_map(
                static fn (string $column): string => '`' . str_replace('`', '``', $column) . '`',
                $columns
            );
            $columnList = implode(', ', $escapedColumns);
            $createResult = $this->db->query('SHOW CREATE TABLE ' . $escapedTable);
            if ($createResult === false) {
                fclose($handle);
                throw new RuntimeException('Struktur tabel gagal dibaca: ' . $table);
            }
            $create = $createResult->fetch_array(MYSQLI_NUM);
            fwrite($handle, 'DROP TABLE IF EXISTS ' . $escapedTable . ";\n" . $create[1] . ";\n\n");

            $result = $this->db->query('SELECT ' . $columnList . ' FROM ' . $escapedTable, MYSQLI_USE_RESULT);
            if ($result === false) {
                fclose($handle);
                throw new RuntimeException('Data tabel gagal dibaca: ' . $table);
            }
            $count = 0;
            while ($row = $result->fetch_assoc()) {
                $values = array_map(fn (mixed $value): string => $this->sqlValue($value), array_values($row));
                fwrite(
                    $handle,
                    'INSERT INTO ' . $escapedTable . ' (' . $columnList . ') VALUES (' . implode(', ', $values) . ");\n"
                );
                $count++;
            }
            $result->free();
            fwrite($handle, "\n");
            $counts[$table] = $count;
        }

        fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($handle);

        return $counts;
    }

    public function duplicateReport(): array
    {
        $membershipStatus = $this->db->query("SHOW COLUMNS FROM plotting_kelas LIKE 'status'");
        $membershipCondition = $membershipStatus && $membershipStatus->num_rows > 0 ? " WHERE status = 'Aktif'" : '';
        $checks = [
            'users.username' => "SELECT username business_key, COUNT(*) total FROM users GROUP BY username HAVING COUNT(*) > 1",
            'users.guru_id' => "SELECT CAST(guru_id AS CHAR) business_key, COUNT(*) total FROM users WHERE guru_id IS NOT NULL GROUP BY guru_id HAVING COUNT(*) > 1",
            'guru.nip' => "SELECT nip business_key, COUNT(*) total FROM guru WHERE nip IS NOT NULL AND TRIM(nip) <> '' GROUP BY nip HAVING COUNT(*) > 1",
            'santri.nis' => "SELECT nis business_key, COUNT(*) total FROM santri GROUP BY nis HAVING COUNT(*) > 1",
            'tahun_ajaran.tahun_semester' => "SELECT CONCAT(tahun, '|', semester) business_key, COUNT(*) total FROM tahun_ajaran GROUP BY tahun, semester HAVING COUNT(*) > 1",
            'tahun_ajaran.status_aktif' => "SELECT 'active_rows' business_key, COUNT(*) total FROM tahun_ajaran WHERE status = 'Aktif' HAVING COUNT(*) <> 1",
            'kelas.nama_jenjang' => "SELECT CONCAT(TRIM(nama_kelas), '|', TRIM(jenjang)) business_key, COUNT(*) total FROM kelas GROUP BY TRIM(nama_kelas), TRIM(jenjang) HAVING COUNT(*) > 1",
            'plotting_kelas.santri_tahun' => "SELECT CONCAT(id_santri, '|', id_tahun) business_key, COUNT(*) total FROM plotting_kelas{$membershipCondition} GROUP BY id_santri, id_tahun HAVING COUNT(*) > 1",
        ];
        $report = [];
        foreach ($checks as $name => $sql) {
            $result = $this->db->query($sql);
            $report[$name] = $result ? $result->fetch_all(MYSQLI_ASSOC) : [['error' => $this->db->error]];
        }
        return $report;
    }

    public function tables(): array
    {
        $result = $this->db->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        if ($result === false) {
            throw new RuntimeException('Daftar tabel tidak dapat dibaca.');
        }
        $tables = [];
        while ($row = $result->fetch_array(MYSQLI_NUM)) {
            $tables[] = $row[0];
        }
        sort($tables, SORT_STRING);
        return $tables;
    }

    private function insertableColumns(string $table): array
    {
        $escapedTable = '`' . str_replace('`', '``', $table) . '`';
        $result = $this->db->query('SHOW FULL COLUMNS FROM ' . $escapedTable);
        if ($result === false) {
            throw new RuntimeException('Kolom tabel gagal dibaca: ' . $table);
        }

        $columns = [];
        while ($row = $result->fetch_assoc()) {
            $extra = strtoupper((string) ($row['Extra'] ?? ''));
            if (str_contains($extra, 'GENERATED')) {
                continue;
            }
            $columns[] = (string) $row['Field'];
        }

        return $columns;
    }

    private function sqlValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        return "'" . $this->db->real_escape_string((string) $value) . "'";
    }
}
