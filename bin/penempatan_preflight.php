<?php

declare(strict_types=1);

/**
 * Preflight penempatan kelas dan kamar (keputusan pengguna 6 September 2026).
 *
 * HANYA MEMBACA. Skrip ini tidak pernah menulis, menghapus, memperbaiki, atau
 * menormalkan data produksi. Ia melaporkan konflik yang harus diputuskan admin
 * lebih dahulu:
 *
 *   0. konfigurasi server yang dibutuhkan (binlog_format);
 *   1. santri dengan lebih dari satu kamar pada tahun ajaran yang sama;
 *   2. relasi yatim (penempatan menunjuk santri/kamar/kelas/tahun yang hilang);
 *   3. kamar yang penghuninya melebihi kapasitas;
 *   4. santri aktif tanpa tahun ajaran aktif yang sah;
 *   5. santri dengan lebih dari satu kelas berstatus Aktif pada tahun yang sama.
 *
 * Paket ini TIDAK menambah migrasi. Bila kelak constraint unik
 * `plotting_kamar (id_santri, id_tahun)` hendak ditambahkan, laporan ini adalah
 * gerbangnya: constraint hanya boleh dipasang setelah bagian 1, 2, dan 5 kosong.
 *
 * Pemakaian:
 *   php bin/penempatan_preflight.php
 *
 * Kode keluar:
 *   0 = tidak ada konflik
 *   1 = ada konflik; migrasi/operasi terkait harus dihentikan sampai admin
 *       memutuskan
 *   2 = tidak dapat dijalankan
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\MasterData\PenempatanRepository;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$repository = new PenempatanRepository(app_db());
$database = (string) app_config('database.database');

echo "Preflight penempatan kelas & kamar\n";
echo "Basis data : {$database}\n";
echo 'Waktu      : ' . date('Y-m-d H:i:s') . "\n";
echo str_repeat('-', 72) . "\n";

$konflik = 0;

/** Batas baris per bagian; sama dengan batas bawaan repository. */
const PENEMPATAN_BATAS_LAPORAN = 200;

$bagian = static function (string $judul, array $rows, callable $baris) use (&$konflik): void {
    echo "\n## {$judul}\n";
    if ($rows === []) {
        echo "  Tidak ada temuan.\n";
        return;
    }
    $konflik += count($rows);
    foreach ($rows as $row) {
        echo '  - ' . $baris($row) . "\n";
    }
    // Jumlah dilaporkan apa adanya, termasuk saat daftarnya terpotong: angka
    // yang terlihat kecil tidak boleh membuat admin salah memperkirakan
    // besarnya pekerjaan.
    echo count($rows) >= PENEMPATAN_BATAS_LAPORAN
        ? '  Jumlah temuan: ' . count($rows) . " atau LEBIH (daftar dipotong pada batas laporan).\n"
        : '  Jumlah temuan: ' . count($rows) . "\n";
};

echo "\n## 0. Konfigurasi server yang dibutuhkan\n";
$binlog = $repository->binlogFormat();
if ($binlog === null || $binlog === '') {
    echo "  binlog_format tidak dapat dibaca (hak akses terbatas). Periksa manual bila server memakai binary log.\n";
} elseif (strtoupper($binlog) === 'STATEMENT') {
    $konflik++;
    echo "  KONFLIK: binlog_format = STATEMENT.\n";
    echo "  Penempatan berjalan pada transaksi READ COMMITTED; MariaDB menolak menulis tabel InnoDB\n";
    echo "  di dalam transaksi tersebut ketika binlog_format = STATEMENT (galat 1665).\n";
    echo "  Setel binlog_format ke ROW atau MIXED sebelum merilis fitur ini.\n";
} else {
    echo '  binlog_format = ' . $binlog . " (mendukung transaksi READ COMMITTED).\n";
}

$bagian(
    '1. Santri dengan lebih dari satu kamar pada tahun ajaran yang sama',
    $repository->conflictDuplicateRoom(),
    static fn (array $r): string => 'santri #' . (int) $r['id_santri'] . ' (' . ($r['nis'] ?? '-') . ' ' . ($r['nama_santri'] ?? 'nama tidak ditemukan') . ')'
        . ' pada tahun #' . (int) $r['id_tahun'] . ' (' . trim(($r['tahun'] ?? '?') . ' ' . ($r['semester'] ?? '')) . ')'
        . ' memiliki ' . (int) $r['jumlah'] . ' baris penempatan kamar'
);

$bagian(
    '2a. Relasi yatim pada penempatan kamar',
    $repository->conflictOrphanRoom(),
    static fn (array $r): string => 'plotting_kamar #' . (int) $r['id'] . ' menunjuk'
        . ((int) $r['santri_hilang'] === 1 ? ' santri #' . (int) $r['id_santri'] . ' (hilang)' : '')
        . ((int) $r['kamar_hilang'] === 1 ? ' kamar #' . (int) $r['id_kamar'] . ' (hilang)' : '')
        . ((int) $r['tahun_hilang'] === 1 ? ' tahun #' . (int) $r['id_tahun'] . ' (hilang)' : '')
);

$bagian(
    '2b. Relasi yatim pada penempatan kelas',
    $repository->conflictOrphanClass(),
    static fn (array $r): string => 'plotting_kelas #' . (int) $r['id'] . ' (' . (string) $r['status'] . ') menunjuk'
        . ((int) $r['santri_hilang'] === 1 ? ' santri #' . (int) $r['id_santri'] . ' (hilang)' : '')
        . ((int) $r['kelas_hilang'] === 1 ? ' kelas #' . (int) $r['id_kelas'] . ' (hilang)' : '')
        . ((int) $r['tahun_hilang'] === 1 ? ' tahun #' . (int) $r['id_tahun'] . ' (hilang)' : '')
);

$bagian(
    '3. Kamar melebihi kapasitas',
    $repository->conflictOverCapacity(),
    static fn (array $r): string => 'kamar #' . (int) $r['id'] . ' (' . (string) $r['nama_kamar'] . ')'
        . ' pada tahun #' . (int) $r['id_tahun'] . ' (' . trim(($r['tahun'] ?? '?') . ' ' . ($r['semester'] ?? '')) . ')'
        . ' terisi ' . (int) $r['terisi'] . ' dari kapasitas ' . (int) $r['kapasitas']
);

$tahunAktif = $repository->activeYearCount();
echo "\n## 4. Tahun ajaran aktif\n";
if ($tahunAktif === 1) {
    $year = $repository->activeYear();
    $ringkas = $repository->countWithoutPlacement((int) $year['id']);
    echo '  Tahun ajaran aktif: ' . $year['tahun'] . ' ' . $year['semester'] . ' (#' . (int) $year['id'] . ")\n";
    echo '  Santri aktif        : ' . (int) $ringkas['santri_aktif'] . "\n";
    echo '  Belum punya kelas   : ' . (int) $ringkas['tanpa_kelas'] . " (bukan konflik; hanya pekerjaan yang belum selesai)\n";
    echo '  Belum punya kamar   : ' . (int) $ringkas['tanpa_kamar'] . " (bukan konflik; hanya pekerjaan yang belum selesai)\n";
    echo '  Nonaktif/arsip tetapi masih menempati kamar: ' . (int) $ringkas['nonaktif_berkamar']
        . " (bukan konflik data; tempat tidurnya masih terpakai — bebaskan lewat filter\n"
        . "                                              \"Nonaktif/arsip tetapi masih berkamar\" pada halaman penempatan)\n";
} else {
    $konflik++;
    echo '  KONFLIK: jumlah tahun ajaran berstatus Aktif dan tidak diarsipkan = ' . $tahunAktif
        . ". Harus tepat satu; penempatan tidak dapat dijalankan.\n";
}

$bagian(
    '5. Santri dengan lebih dari satu kelas Aktif pada tahun yang sama',
    $repository->conflictDuplicateActiveClass(),
    static fn (array $r): string => 'santri #' . (int) $r['id_santri'] . ' (' . ($r['nis'] ?? '-') . ' ' . ($r['nama_santri'] ?? 'nama tidak ditemukan') . ')'
        . ' pada tahun #' . (int) $r['id_tahun'] . ' memiliki ' . (int) $r['jumlah'] . ' penempatan kelas aktif'
);

echo "\n" . str_repeat('-', 72) . "\n";
if ($konflik === 0) {
    echo "HASIL: tidak ada konflik data penempatan.\n";
    exit(0);
}

echo 'HASIL: ditemukan ' . $konflik . " konflik data penempatan.\n";
echo "Hentikan migrasi atau operasi massal terkait sampai admin memutuskan penyelesaiannya.\n";
echo "Skrip ini TIDAK memperbaiki data; perbaikan dilakukan manusia atas keputusan tertulis.\n";
exit(1);
