<?php

declare(strict_types=1);

/**
 * Backfill referensi santri pada catatan alumni warisan
 * (paket "Koreksi Pengelolaan Alumni", keputusan pengguna 6 September 2026).
 *
 * SIFAT: KONSERVATIF DAN LAPOR DULU.
 *
 * Secara bawaan skrip ini HANYA MEMBACA dan mencetak laporan. Ia baru menulis
 * bila dijalankan dengan `--terapkan`, dan bahkan saat itu pun ia hanya
 * memasangkan pasangan yang PASTI:
 *
 *   * catatan alumni AKTIF yang `santri_id`-nya masih NULL; DAN
 *   * NIS-nya cocok dengan TEPAT SATU baris `santri`; DAN
 *   * NIS itu dipakai TEPAT SATU catatan alumni; DAN
 *   * santri tersebut belum punya catatan alumni aktif lain.
 *
 * Data yang tidak memenuhi seluruh syarat itu DILAPORKAN dan DIBIARKAN apa
 * adanya. Skrip ini tidak pernah menebak berdasarkan kesamaan nama santri,
 * nama ayah, atau nama ibu; tidak pernah menghapus baris; dan tidak pernah
 * mengubah kolom lain.
 *
 * Setiap pemasangan dicatat pada `audit_logs` dengan aksi `alumni.backfill`.
 *
 * Pemakaian:
 *   php bin/alumni_backfill.php              # laporan saja (tidak menulis)
 *   php bin/alumni_backfill.php --terapkan   # pasangkan yang pasti saja
 *
 * Kode keluar:
 *   0 = selesai; tidak ada data ambigu yang tersisa
 *   1 = selesai; masih ada data ambigu yang perlu diputuskan admin
 *   2 = tidak dapat dijalankan
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\MasterData\AlumniRepository;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$terapkan = in_array('--terapkan', $argv, true);
$repository = new AlumniRepository(app_db());
$db = app_db();
$audit = audit_logger();

echo "Backfill referensi santri pada catatan alumni\n";
echo 'Basis data : ' . (string) app_config('database.database') . "\n";
echo 'Mode       : ' . ($terapkan ? 'TERAPKAN (menulis)' : 'LAPORAN SAJA (tidak menulis)') . "\n";
echo 'Waktu      : ' . date('Y-m-d H:i:s') . "\n";
echo str_repeat('-', 72) . "\n";

if (!$repository->schemaSiap()) {
    fwrite(STDERR, "Migrasi 011 belum terpasang. Jalankan 'php bin/migrate.php up' lebih dahulu.\n");
    exit(2);
}

/**
 * Kandidat pemasangan. Seluruh syarat kepastian ditulis sebagai klausa SQL
 * sehingga tidak ada penilaian samar di sisi PHP.
 */
$sql = "SELECT a.id alumni_id, a.nis, a.nama_santri,
               (SELECT COUNT(*) FROM santri s WHERE s.nis = a.nis) santri_cocok,
               (SELECT MIN(s.id) FROM santri s WHERE s.nis = a.nis) santri_id,
               (SELECT MIN(s.nama_santri) FROM santri s WHERE s.nis = a.nis) santri_nama,
               (SELECT COUNT(*) FROM alumni a2 WHERE a2.nis = a.nis) alumni_dengan_nis,
               (SELECT COUNT(*) FROM alumni a3
                 WHERE a3.archived_at IS NULL AND a3.santri_id IS NOT NULL
                   AND a3.santri_id = (SELECT MIN(s.id) FROM santri s WHERE s.nis = a.nis)) alumni_aktif_santri
          FROM alumni a
         WHERE a.santri_id IS NULL AND a.archived_at IS NULL
         ORDER BY a.id";

$rs = $db->query($sql);
if ($rs === false) {
    fwrite(STDERR, 'Query kandidat gagal: ' . $db->error . "\n");
    exit(2);
}
$kandidat = $rs->fetch_all(MYSQLI_ASSOC);

$pasti = [];
$ambigu = [];
foreach ($kandidat as $row) {
    $cocok = (int) $row['santri_cocok'];
    $alumniNis = (int) $row['alumni_dengan_nis'];
    $sudahAda = (int) $row['alumni_aktif_santri'];

    if ($cocok === 1 && $alumniNis === 1 && $sudahAda === 0) {
        $pasti[] = $row;
        continue;
    }
    $alasan = match (true) {
        $cocok === 0 => 'tidak ada santri dengan NIS ini',
        $cocok > 1 => $cocok . ' santri memakai NIS ini',
        $alumniNis > 1 => $alumniNis . ' catatan alumni memakai NIS ini',
        default => 'santri tujuan sudah memiliki catatan alumni aktif lain',
    };
    $ambigu[] = $row + ['alasan' => $alasan];
}

echo "\n## Kandidat yang PASTI dapat dipasangkan (" . count($pasti) . ")\n";
foreach ($pasti as $row) {
    echo '  - Alumni #' . (int) $row['alumni_id'] . ' (' . $row['nis'] . ' — ' . $row['nama_santri']
        . ') -> santri #' . (int) $row['santri_id'] . ' (' . $row['santri_nama'] . ")\n";
}
if ($pasti === []) {
    echo "  Tidak ada.\n";
}

echo "\n## Data AMBIGU — TIDAK dipasangkan otomatis (" . count($ambigu) . ")\n";
foreach ($ambigu as $row) {
    echo '  - Alumni #' . (int) $row['alumni_id'] . ' (' . $row['nis'] . ' — ' . $row['nama_santri']
        . '): ' . $row['alasan'] . "\n";
}
if ($ambigu === []) {
    echo "  Tidak ada.\n";
}

$dipasang = 0;
if ($terapkan && $pasti !== []) {
    echo "\n## Menerapkan pemasangan\n";
    foreach ($pasti as $row) {
        $alumniId = (int) $row['alumni_id'];
        $santriId = (int) $row['santri_id'];

        if ($db->begin_transaction() === false) {
            fwrite(STDERR, "Transaksi tidak dapat dimulai. Backfill dihentikan.\n");
            exit(2);
        }
        try {
            // Diperiksa ULANG di dalam transaksi: keadaan bisa berubah sejak
            // laporan di atas disusun.
            $terkunci = $repository->lockAlumni($alumniId);
            if ($terkunci === null || $terkunci['santri_id'] !== null || $terkunci['archived_at'] !== null) {
                $db->rollback();
                echo '  - Alumni #' . $alumniId . " dilewati: keadaannya sudah berubah.\n";
                continue;
            }
            if ($repository->lockActiveBySantri([$santriId]) !== []) {
                $db->rollback();
                echo '  - Alumni #' . $alumniId . " dilewati: santri tujuan sudah punya catatan alumni aktif.\n";
                continue;
            }

            $repository->attachSantri($alumniId, $santriId, null);
            $tercatat = $audit->log(
                'alumni.backfill',
                'alumni',
                $alumniId,
                ['santri_id' => null, 'nis' => $terkunci['nis'], 'nama_santri' => $terkunci['nama_santri']],
                [
                    'santri_id' => $santriId,
                    'nis' => $terkunci['nis'],
                    'nama_santri' => $terkunci['nama_santri'],
                    'dasar' => 'NIS cocok persis satu santri dan satu catatan alumni',
                    'sumber' => 'bin/alumni_backfill.php',
                ],
                null
            );
            if (!$tercatat) {
                $db->rollback();
                fwrite(STDERR, '  - Alumni #' . $alumniId . " dibatalkan: audit tidak dapat disimpan.\n");
                continue;
            }
            $db->commit();
            $dipasang++;
            echo '  - Alumni #' . $alumniId . ' -> santri #' . $santriId . " dipasangkan.\n";
        } catch (Throwable $exception) {
            $db->rollback();
            fwrite(STDERR, '  - Alumni #' . $alumniId . ' gagal: ' . $exception->getMessage() . "\n");
        }
    }
}

echo "\n" . str_repeat('-', 72) . "\n";
echo 'Catatan alumni aktif tanpa referensi santri : ' . count($kandidat) . "\n";
echo 'Dapat dipasangkan dengan pasti              : ' . count($pasti) . "\n";
echo 'Ambigu (dibiarkan apa adanya)               : ' . count($ambigu) . "\n";
echo 'Benar-benar dipasangkan pada jalankan ini   : ' . $dipasang . "\n";
if (!$terapkan) {
    echo "\nTidak ada data yang diubah. Jalankan ulang dengan --terapkan untuk memasangkan yang pasti saja.\n";
}
if ($ambigu !== []) {
    echo "\nData ambigu TIDAK boleh dipasangkan otomatis. Hubungkan satu per satu dari\n";
    echo "halaman Data Alumni -> Detail -> \"Hubungkan ke santri sumber\" setelah admin memastikan orangnya.\n";
    exit(1);
}
exit(0);
