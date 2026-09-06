<?php

declare(strict_types=1);

/**
 * Preflight arsip alumni (paket "Koreksi Pengelolaan Alumni",
 * keputusan pengguna 6 September 2026).
 *
 * HANYA MEMBACA. Skrip ini tidak pernah menulis, menghapus, memperbaiki, atau
 * menormalkan data produksi. Ia melaporkan keadaan yang harus diputuskan admin
 * lebih dahulu:
 *
 *   0. apakah migrasi 011 sudah terpasang;
 *   1. NIS ganda pada tabel alumni (penghalang kunci unik NIS aktif);
 *   2. catatan alumni aktif yang belum terhubung ke santri sumber, beserta
 *      jumlah kandidat santri yang NIS-nya cocok persis;
 *   3. santri dengan lebih dari satu catatan alumni aktif;
 *   4. catatan alumni aktif yang santri sumbernya justru masih aktif;
 *   5. alumni aktif yang masih memegang penempatan kelas pada semester aktif;
 *   6. alumni aktif yang masih memegang penempatan kamar pada semester aktif;
 *   7. konfigurasi server yang dibutuhkan (binlog_format).
 *
 * Bagian 1 adalah GERBANG migrasi 011: kunci unik `alumni_nis_aktif_unique`
 * hanya dapat dipasang bila tidak ada dua catatan AKTIF dengan NIS sama.
 * Bagian 5 dan 6 adalah sisa proses lama yang tidak menutup kelas/kamar.
 *
 * Pemakaian:
 *   php bin/alumni_preflight.php
 *
 * Kode keluar:
 *   0 = tidak ada penghalang
 *   1 = ada temuan yang harus diputuskan admin
 *   2 = tidak dapat dijalankan
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\MasterData\AlumniRepository;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$repository = new AlumniRepository(app_db());
$db = app_db();
$database = (string) app_config('database.database');

echo "Preflight arsip alumni\n";
echo "Basis data : {$database}\n";
echo 'Waktu      : ' . date('Y-m-d H:i:s') . "\n";
echo str_repeat('-', 72) . "\n";

$temuan = 0;

$bagian = static function (string $judul, array $rows, callable $baris) use (&$temuan): void {
    echo "\n## {$judul}\n";
    if ($rows === []) {
        echo "  Tidak ada temuan.\n";

        return;
    }
    $temuan += count($rows);
    foreach ($rows as $row) {
        echo '  - ' . $baris($row) . "\n";
    }
};

$skemaSiap = $repository->schemaSiap();
echo "\n## 0. Skema migrasi 011\n";
if ($skemaSiap) {
    echo "  Terpasang: kolom santri_id, archived_at, dan kolom penjaga keunikan tersedia.\n";
} else {
    echo "  BELUM terpasang. Jalankan 'php bin/migrate.php up' pada salinan uji lebih dahulu.\n";
    echo "  Bagian 2-6 di bawah dilewati karena kolomnya belum ada.\n";
}

// --------------------------------------------------------------------- 1
$ganda = [];
$rs = $db->query(
    "SELECT nis, COUNT(*) jumlah, GROUP_CONCAT(id ORDER BY id) daftar_id
       FROM alumni GROUP BY nis HAVING COUNT(*) > 1 ORDER BY nis LIMIT 200"
);
if ($rs !== false) {
    $ganda = $rs->fetch_all(MYSQLI_ASSOC);
}
$bagian(
    '1. NIS ganda pada tabel alumni',
    $ganda,
    static fn (array $r): string => 'NIS ' . $r['nis'] . ' dipakai ' . $r['jumlah'] . ' catatan (ID: ' . $r['daftar_id'] . ')'
);

if (!$skemaSiap) {
    echo "\n" . str_repeat('-', 72) . "\n";
    echo "Migrasi 011 belum terpasang; pemeriksaan lanjutan tidak dapat dijalankan.\n";
    exit(1);
}

// --------------------------------------------------------------------- 2
$bagian(
    '2. Catatan alumni aktif tanpa referensi santri (data warisan)',
    $repository->reportUnlinked(),
    static fn (array $r): string => 'Alumni #' . $r['id'] . ' (' . $r['nis'] . ' — ' . $r['nama_santri'] . '): '
        . ((int) $r['kandidat'] === 1
            ? 'cocok PERSIS satu santri (#' . (int) $r['kandidat_id'] . ') — dapat di-backfill'
            : ((int) $r['kandidat'] === 0
                ? 'tidak ada santri dengan NIS itu — AMBIGU, biarkan apa adanya'
                : (int) $r['kandidat'] . ' santri memakai NIS itu — AMBIGU, putuskan manual'))
);

// --------------------------------------------------------------------- 3
$bagian(
    '3. Santri dengan lebih dari satu catatan alumni aktif',
    $repository->reportDuplicateActive(),
    static fn (array $r): string => 'Santri #' . (int) $r['santri_id'] . ' memiliki ' . (int) $r['jumlah']
        . ' catatan aktif (ID: ' . $r['daftar_id'] . ')'
);

// --------------------------------------------------------------------- 4
$bagian(
    '4. Alumni aktif yang santri sumbernya masih berstatus aktif',
    $repository->reportActiveSantriWithAlumni(),
    static fn (array $r): string => 'Alumni #' . (int) $r['alumni_id'] . ' (' . $r['nis'] . ' — ' . $r['nama_santri']
        . ') terhubung ke santri #' . (int) $r['santri_id'] . ' yang masih aktif'
);

// --------------------------------------------------------------------- 5 & 6
$year = $repository->activeYear();
if ($year === null) {
    echo "\n## 5-6. Penempatan kelas/kamar alumni pada semester aktif\n";
    echo "  Dilewati: belum ada tahun ajaran aktif.\n";
} else {
    $yearId = (int) $year['id'];
    echo "\n(Semester aktif: " . $year['tahun'] . ' ' . $year['semester'] . ")\n";
    $bagian(
        '5. Alumni aktif yang masih memegang kelas aktif',
        $repository->reportAlumniStillInClass($yearId),
        static fn (array $r): string => 'Alumni #' . (int) $r['alumni_id'] . ' (' . $r['nama_santri'] . ') masih di kelas '
            . ($r['nama_kelas'] ?? 'tidak diketahui')
    );
    $bagian(
        '6. Alumni aktif yang masih menempati kamar',
        $repository->reportAlumniStillInRoom($yearId),
        static fn (array $r): string => 'Alumni #' . (int) $r['alumni_id'] . ' (' . $r['nama_santri'] . ') masih di kamar '
            . ($r['nama_kamar'] ?? 'tidak diketahui')
    );
}

// --------------------------------------------------------------------- 7
echo "\n## 7. Konfigurasi server\n";
$binlog = null;
$rs = $db->query("SHOW VARIABLES LIKE 'binlog_format'");
if ($rs !== false && $row = $rs->fetch_assoc()) {
    $binlog = strtoupper((string) $row['Value']);
}
if ($binlog === null) {
    echo "  binlog_format tidak dapat dibaca (izin terbatas). Periksa manual bila replikasi aktif.\n";
} elseif ($binlog === 'STATEMENT') {
    echo "  binlog_format = STATEMENT. Transaksi READ COMMITTED akan DITOLAK server.\n";
    echo "  Minta pengelola server mengubahnya menjadi ROW atau MIXED sebelum rilis.\n";
    $temuan++;
} else {
    echo "  binlog_format = {$binlog} (aman).\n";
}

echo "\n" . str_repeat('-', 72) . "\n";
if ($temuan === 0) {
    echo "Tidak ada penghalang. Migrasi 011 dan proses alumni aman dijalankan.\n";
    exit(0);
}
echo "Terdapat {$temuan} temuan.\n";
echo "Bagian 1 WAJIB kosong sebelum migrasi 011 dijalankan.\n";
echo "Bagian 2 tidak menghalangi migrasi: jalankan 'php bin/alumni_backfill.php' untuk laporan pemasangan.\n";
echo "Bagian 3-6 adalah sisa data yang harus diperiksa admin; skrip ini TIDAK memperbaikinya.\n";
exit(1);
