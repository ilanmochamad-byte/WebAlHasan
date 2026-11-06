<?php

declare(strict_types=1);

/**
 * Perbaikan peserta absensi (6 November 2026): merapikan data lama.
 *
 * Sebelum perbaikan, menonaktifkan/mengarsipkan santri di Data Santri tidak
 * menutup penempatan kelasnya. Santri itu tetap tercatat "Aktif" di kelas dan
 * ikut dihitung layanan berbasis kelas. Skrip ini menutup penempatan kelas
 * Aktif milik santri yang SUDAH nonaktif atau diarsipkan, dengan aturan yang
 * sama seperti tombol Nonaktifkan/Arsipkan sesudah perbaikan.
 *
 * SIFAT: LAPOR DULU.
 *   php bin/tutup_penempatan_santri_nonaktif.php              # laporan saja
 *   php bin/tutup_penempatan_santri_nonaktif.php --terapkan   # tutup penempatan
 *
 * Saat `--terapkan`: setiap santri diproses dalam transaksinya sendiri;
 * penempatan diberi status `Selesai` dan `tanggal_selesai` hari ini (baris
 * tetap ada sebagai riwayat); satu audit `master.santri_penempatan_ditutup`
 * per santri berisi ID penempatan yang ditutup. Bila audit gagal, perubahan
 * santri itu dibatalkan. Status santri, kamar, absensi, dan pertemuan yang
 * sudah ada TIDAK diubah.
 *
 * Kode keluar: 0 = selesai / tidak ada data; 1 = ada santri yang gagal;
 *              2 = tidak dapat dijalankan.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$terapkan = in_array('--terapkan', $argv, true);
$db = app_db();
$audit = audit_logger();

echo "Tutup penempatan kelas santri nonaktif/arsip\n";
echo 'Basis data : ' . (string) app_config('database.database') . "\n";
echo 'Mode       : ' . ($terapkan ? 'TERAPKAN (menulis)' : 'LAPORAN SAJA (tidak menulis)') . "\n";
echo 'Waktu      : ' . date('Y-m-d H:i:s') . "\n";
echo str_repeat('-', 72) . "\n";

$rs = $db->query(
    "SELECT pk.id, pk.id_santri, s.nis, s.nama_santri, s.is_active, s.archived_at,
            k.nama_kelas, ta.tahun, ta.semester
       FROM plotting_kelas pk
       JOIN santri s ON s.id = pk.id_santri
       LEFT JOIN kelas k ON k.id = pk.id_kelas
       LEFT JOIN tahun_ajaran ta ON ta.id = pk.id_tahun
      WHERE pk.status = 'Aktif' AND (s.is_active = 0 OR s.archived_at IS NOT NULL)
      ORDER BY pk.id_santri, pk.id"
);
if ($rs === false) {
    fwrite(STDERR, 'Query kandidat gagal: ' . $db->error . "\n");
    exit(2);
}

/** @var array<int, list<array<string, mixed>>> $perSantri */
$perSantri = [];
foreach ($rs->fetch_all(MYSQLI_ASSOC) as $row) {
    $perSantri[(int) $row['id_santri']][] = $row;
}

if ($perSantri === []) {
    echo "Tidak ada santri nonaktif/arsip dengan penempatan kelas Aktif.\n";
    exit(0);
}

foreach ($perSantri as $santriId => $rows) {
    $status = $rows[0]['archived_at'] !== null ? 'arsip' : 'nonaktif';
    echo sprintf("santri #%d %s %s (%s)\n", $santriId, $rows[0]['nis'], $rows[0]['nama_santri'], $status);
    foreach ($rows as $row) {
        echo sprintf("    penempatan #%d · %s · %s %s\n", (int) $row['id'], (string) ($row['nama_kelas'] ?? '?'), (string) ($row['tahun'] ?? '?'), (string) ($row['semester'] ?? ''));
    }
}
$jumlahPenempatan = array_sum(array_map('count', $perSantri));
echo str_repeat('-', 72) . "\n";
echo sprintf("%d santri, %d penempatan kelas Aktif.\n", count($perSantri), $jumlahPenempatan);

if (!$terapkan) {
    echo "Laporan saja. Jalankan ulang dengan --terapkan untuk menutup penempatan di atas.\n";
    exit(0);
}

$gagal = 0;
$tanggal = date('Y-m-d');
foreach ($perSantri as $santriId => $rows) {
    $db->begin_transaction();
    try {
        $kunci = $db->prepare("SELECT id FROM plotting_kelas pk
                                WHERE pk.id_santri = ? AND pk.status = 'Aktif'
                                  AND EXISTS (SELECT 1 FROM santri s WHERE s.id = pk.id_santri AND (s.is_active = 0 OR s.archived_at IS NOT NULL))
                                ORDER BY pk.id FOR UPDATE");
        $kunci->execute([$santriId]);
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $kunci->get_result()->fetch_all(MYSQLI_ASSOC));
        $kunci->close();
        if ($ids !== []) {
            $ubah = $db->prepare("UPDATE plotting_kelas SET status = 'Selesai', tanggal_selesai = ?, updated_at = NOW() WHERE id_santri = ? AND status = 'Aktif'");
            $ubah->execute([$tanggal, $santriId]);
            $ubah->close();
            if (!$audit->log('master.santri_penempatan_ditutup', 'santri', $santriId, ['penempatan_kelas_aktif' => $ids], ['penempatan_kelas_ditutup' => $ids, 'sumber' => 'bin/tutup_penempatan_santri_nonaktif.php'])) {
                throw new RuntimeException('audit tidak dapat disimpan');
            }
        }
        $db->commit();
        echo sprintf("  ditutup: santri #%d (%d penempatan)\n", $santriId, count($ids));
    } catch (Throwable $exception) {
        $db->rollback();
        $gagal++;
        fwrite(STDERR, sprintf("  GAGAL santri #%d: %s\n", $santriId, $exception->getMessage()));
    }
}

echo $gagal === 0 ? "Selesai.\n" : sprintf("Selesai dengan %d santri gagal; jalankan ulang setelah penyebabnya diperbaiki.\n", $gagal);
exit($gagal === 0 ? 0 : 1);
