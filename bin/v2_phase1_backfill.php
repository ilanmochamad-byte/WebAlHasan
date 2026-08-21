<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Backfill perizinan warisan (dapat dijalankan ulang dengan aman).
 *
 * Skrip ini menjalankan blok SQL yang sama persis dengan yang ada di dalam migrasi
 * 006 — dibaca langsung dari file migrasi di antara penanda BACKFILL:BEGIN dan
 * BACKFILL:END — sehingga hanya ada satu sumber kebenaran.
 *
 * Gunakan bila ada baris `perizinan` baru yang muncul setelah migrasi dijalankan,
 * atau untuk memverifikasi sifat idempoten backfill pada database uji.
 */

$migration = APP_ROOT . '/database/migrations/006_v2_phase1_perizinan_foundation.sql';
$sql = file_get_contents($migration);
if ($sql === false) {
    fwrite(STDERR, "File migrasi 006 tidak dapat dibaca.\n");
    exit(1);
}

if (!preg_match('/-- BACKFILL:BEGIN(.*)-- BACKFILL:END/s', $sql, $matches)) {
    fwrite(STDERR, "Penanda BACKFILL tidak ditemukan pada migrasi 006.\n");
    exit(1);
}

$db = app_db();
$before = (int) ($db->query('SELECT COUNT(*) AS jumlah FROM izin_pengajuan WHERE is_legacy = 1')?->fetch_assoc()['jumlah'] ?? 0);
$legacy = (int) ($db->query('SELECT COUNT(*) AS jumlah FROM perizinan')?->fetch_assoc()['jumlah'] ?? 0);

if (!$db->multi_query($matches[1])) {
    fwrite(STDERR, 'Backfill gagal: ' . $db->error . "\n");
    exit(2);
}
while (true) {
    if ($result = $db->store_result()) {
        $result->free();
    }
    if ($db->errno) {
        fwrite(STDERR, 'Backfill gagal: ' . $db->error . "\n");
        exit(2);
    }
    if (!$db->more_results()) {
        break;
    }
    if (!$db->next_result()) {
        fwrite(STDERR, 'Backfill gagal: ' . $db->error . "\n");
        exit(2);
    }
}

$after = (int) ($db->query('SELECT COUNT(*) AS jumlah FROM izin_pengajuan WHERE is_legacy = 1')?->fetch_assoc()['jumlah'] ?? 0);

echo "Backfill perizinan warisan selesai.\n";
echo '- Baris `perizinan`          : ' . $legacy . "\n";
echo '- Pengajuan warisan sebelum  : ' . $before . "\n";
echo '- Pengajuan warisan sesudah  : ' . $after . "\n";
echo '- Baris baru ditambahkan     : ' . ($after - $before) . "\n";

exit($after === $legacy ? 0 : 2);
