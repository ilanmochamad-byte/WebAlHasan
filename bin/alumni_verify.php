<?php

declare(strict_types=1);

/**
 * Verifikasi pasca-migrasi arsip alumni
 * (paket "Koreksi Pengelolaan Alumni", keputusan pengguna 6 September 2026).
 *
 * HANYA MEMBACA. Dijalankan SETELAH `php bin/migrate.php up` untuk memastikan
 * migrasi 011 benar-benar terpasang seperti yang dijanjikan dan tidak ada data
 * lama yang hilang.
 *
 * Yang diperiksa:
 *   1. seluruh kolom baru ada;
 *   2. kunci unik alumni aktif (santri dan NIS) terpasang;
 *   3. kunci unik `nis` lama sudah digantikan, bukan sekadar dilepas;
 *   4. kunci asing santri/pelaku terpasang;
 *   5. jumlah baris alumni TIDAK berkurang dibanding nilai yang dicatat
 *      sebelum migrasi (opsional, lewat --sebelum=N);
 *   6. tidak ada catatan alumni aktif ganda per santri maupun per NIS;
 *   7. seluruh baris lama masih dapat dibaca beserta foto dan snapshot
 *      identitasnya.
 *
 * Pemakaian:
 *   php bin/alumni_verify.php
 *   php bin/alumni_verify.php --sebelum=1234
 *
 * Kode keluar:
 *   0 = seluruh pemeriksaan lulus
 *   1 = ada pemeriksaan yang gagal
 *   2 = tidak dapat dijalankan
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$db = app_db();
$sebelum = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--sebelum=')) {
        $sebelum = (int) substr($arg, 10);
    }
}

echo "Verifikasi migrasi 011 (arsip alumni)\n";
echo 'Basis data : ' . (string) app_config('database.database') . "\n";
echo 'Waktu      : ' . date('Y-m-d H:i:s') . "\n";
echo str_repeat('-', 72) . "\n";

$gagal = 0;
$periksa = static function (bool $lulus, string $pesan) use (&$gagal): void {
    echo ($lulus ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    if (!$lulus) {
        $gagal++;
    }
};

$skalar = static function (string $sql) use ($db): int {
    $rs = $db->query($sql);

    return $rs !== false && ($row = $rs->fetch_row()) ? (int) $row[0] : -1;
};

// ------------------------------------------------------------------- 1
foreach ([
    'santri_id', 'kelas_terakhir', 'kamar_terakhir', 'catatan', 'archived_at',
    'jenis_arsip', 'alasan_arsip', 'created_by', 'updated_by', 'created_at',
    'updated_at', 'santri_aktif_guard', 'nis_aktif_guard',
] as $kolom) {
    $ada = $skalar(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumni' AND COLUMN_NAME = '" . $kolom . "'"
    );
    $periksa($ada === 1, 'Kolom alumni.' . $kolom . ' tersedia');
}

// ------------------------------------------------------------------- 2, 3
$indeks = static fn (string $nama): int => (int) ($db->query(
    "SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumni' AND INDEX_NAME = '" . $nama . "'"
)?->fetch_row()[0] ?? 0);

$periksa($indeks('alumni_santri_aktif_unique') > 0, 'Kunci unik alumni aktif per santri terpasang');
$periksa($indeks('alumni_nis_aktif_unique') > 0, 'Kunci unik alumni aktif per NIS terpasang');
$periksa($indeks('alumni_nis_index') > 0, 'Indeks pencarian NIS tetap ada');
$periksa($indeks('nis') === 0, 'Kunci unik `nis` lama sudah digantikan penjaga NIS aktif');
$periksa($indeks('alumni_filter_index') > 0, 'Indeks filter status/tahun/tingkat terpasang');

// ------------------------------------------------------------------- 4
foreach (['alumni_santri_fk', 'alumni_creator_fk', 'alumni_updater_fk'] as $fk) {
    $ada = $skalar(
        "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumni' AND CONSTRAINT_NAME = '" . $fk . "'"
    );
    $periksa($ada === 1, 'Kunci asing ' . $fk . ' terpasang');
}

// ------------------------------------------------------------------- 5
$total = $skalar('SELECT COUNT(*) FROM alumni');
echo 'Jumlah baris alumni saat ini: ' . $total . PHP_EOL;
if ($sebelum !== null) {
    $periksa($total >= $sebelum, 'Jumlah baris alumni tidak berkurang (sebelum: ' . $sebelum . ', sesudah: ' . $total . ')');
} else {
    echo "[info]  Jumlah baris sebelum migrasi tidak diberikan; jalankan ulang dengan --sebelum=N untuk membandingkannya.\n";
}

// ------------------------------------------------------------------- 6
$periksa(
    $skalar('SELECT COUNT(*) FROM (SELECT santri_id FROM alumni WHERE santri_id IS NOT NULL AND archived_at IS NULL GROUP BY santri_id HAVING COUNT(*) > 1) x') === 0,
    'Tidak ada santri dengan lebih dari satu catatan alumni aktif'
);
$periksa(
    $skalar('SELECT COUNT(*) FROM (SELECT nis FROM alumni WHERE archived_at IS NULL GROUP BY nis HAVING COUNT(*) > 1) x') === 0,
    'Tidak ada NIS dengan lebih dari satu catatan alumni aktif'
);

// ------------------------------------------------------------------- 7
$periksa(
    $skalar("SELECT COUNT(*) FROM alumni WHERE nama_santri = '' OR nis = ''") === 0,
    'Seluruh baris alumni masih memiliki NIS dan nama'
);
$periksa(
    $skalar('SELECT COUNT(*) FROM alumni WHERE foto IS NULL') === 0,
    'Kolom foto seluruh baris alumni tetap terisi'
);

echo str_repeat('-', 72) . "\n";
if ($gagal === 0) {
    echo "SELURUH PEMERIKSAAN LULUS.\n";
    exit(0);
}
echo "TERDAPAT {$gagal} PEMERIKSAAN YANG GAGAL.\n";
exit(1);
