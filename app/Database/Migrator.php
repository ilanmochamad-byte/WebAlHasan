<?php

declare(strict_types=1);

namespace App\Database;

use mysqli;
use RuntimeException;

final class Migrator
{
    public function __construct(
        private mysqli $db,
        private string $migrationPath,
        private string $rollbackPath
    ) {
    }

    public function ensureRepository(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS schema_migrations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            migration VARCHAR(191) NOT NULL,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY schema_migrations_name_unique (migration)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if (!$this->db->query($sql)) {
            throw new RuntimeException('Tidak dapat membuat tabel pencatat migrasi: ' . $this->db->error);
        }
    }

    public function status(): array
    {
        $this->ensureRepository();
        $applied = [];
        $result = $this->db->query('SELECT migration, applied_at FROM schema_migrations ORDER BY id');
        while ($row = $result->fetch_assoc()) {
            $applied[$row['migration']] = $row['applied_at'];
        }

        return array_map(static fn (string $file): array => [
            'migration' => basename($file),
            'applied_at' => $applied[basename($file)] ?? null,
        ], $this->migrationFiles());
    }

    public function up(): array
    {
        $this->ensureRepository();
        $applied = [];
        foreach ($this->status() as $item) {
            if ($item['applied_at'] !== null) {
                continue;
            }
            $name = $item['migration'];
            $this->runSqlFile($this->migrationPath . '/' . $name);
            $statement = $this->db->prepare('INSERT INTO schema_migrations (migration, applied_at) VALUES (?, NOW())');
            $statement->bind_param('s', $name);
            if (!$statement->execute()) {
                throw new RuntimeException('Migrasi sudah berjalan tetapi gagal dicatat: ' . $name);
            }
            $statement->close();
            $applied[] = $name;
        }

        return $applied;
    }

    public function rollbackLast(): ?string
    {
        $this->ensureRepository();
        $result = $this->db->query('SELECT migration FROM schema_migrations ORDER BY id DESC LIMIT 1');
        $row = $result->fetch_assoc();
        if (!$row) {
            return null;
        }

        $name = $row['migration'];
        $rollback = $this->rollbackPath . '/' . $name;
        if (!is_file($rollback)) {
            throw new RuntimeException('File rollback tidak ditemukan untuk ' . $name);
        }
        $this->runSqlFile($rollback);
        $statement = $this->db->prepare('DELETE FROM schema_migrations WHERE migration = ?');
        $statement->bind_param('s', $name);
        $statement->execute();
        $statement->close();

        return $name;
    }

    private function migrationFiles(): array
    {
        $files = glob($this->migrationPath . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        return $files;
    }

    private function runSqlFile(string $path): void
    {
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException('File SQL tidak dapat dibaca: ' . $path);
        }

        if (!$this->db->multi_query($sql)) {
            throw new RuntimeException('Migrasi gagal pada ' . basename($path) . ': ' . $this->db->error);
        }

        while (true) {
            if ($result = $this->db->store_result()) {
                $result->free();
            }
            if ($this->db->errno) {
                throw new RuntimeException('Migrasi gagal pada ' . basename($path) . ': ' . $this->db->error);
            }
            if (!$this->db->more_results()) {
                break;
            }
            if (!$this->db->next_result()) {
                throw new RuntimeException('Migrasi gagal pada ' . basename($path) . ': ' . $this->db->error);
            }
        }
    }
}
