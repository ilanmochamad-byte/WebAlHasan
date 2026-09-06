<?php

declare(strict_types=1);

/**
 * Kelulusan/mutasi alumni pada PERMINTAAN BERSAMAAN
 * (paket "Koreksi Pengelolaan Alumni", keputusan pengguna 6 September 2026).
 *
 * Pemeriksaan aplikasi biasa ("cek dulu, baru simpan") TIDAK cukup: dua
 * permintaan dapat sama-sama membaca "santri ini belum jadi alumni", lalu
 * sama-sama menyimpan. Karena itu `AlumniService` mengunci baris santri
 * (`SELECT ... FOR UPDATE`) sebelum memeriksa, memakai isolasi READ COMMITTED
 * agar pembacaan setelah kunci melihat keadaan terkini, dan basis data
 * menegakkan kunci unik `alumni_santri_aktif_unique` sebagai lapisan kedua.
 *
 * Skenario:
 *   KA-1 dua permintaan meluluskan SANTRI YANG SAMA pada detik yang sama —
 *        tepat satu berhasil dan hanya ada SATU catatan alumni;
 *   KA-2 lima permintaan atas santri yang sama (simulasi klik ganda beruntun) —
 *        tetap hanya satu catatan alumni dan satu santri terarsip;
 *   KA-3 dua permintaan MASSAL yang daftar santrinya bertumpuk — tidak ada
 *        catatan ganda, dan tidak ada santri yang setengah jadi;
 *   KA-4 permintaan yang kalah mendapat pesan yang dapat dimengerti admin,
 *        bukan galat basis data mentah.
 *
 * Jalankan hanya pada database berakhiran `_test`:
 *   ALUMNI_RUN_CONCURRENCY=1 php tests/alumni_concurrency.php
 */

$root = dirname(__DIR__);
if (getenv('ALUMNI_RUN_CONCURRENCY') !== '1') {
    fwrite(STDOUT, "[lewati] Set ALUMNI_RUN_CONCURRENCY=1 dan arahkan DB_NAME ke database khusus *_test.\n");
    exit(77);
}

require_once $root . '/app/bootstrap.php';

if (!str_ends_with((string) app_config('database.database'), '_test')) {
    fwrite(STDERR, "Ditolak: pengujian ini hanya boleh berjalan pada database berakhiran _test.\n");
    exit(2);
}

$db = app_db();
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? '[lulus] ' : '[gagal] ') . $message . PHP_EOL;
    if (!$condition) {
        $failures[] = $message;
    }
};

$suffix = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
$kecil = strtolower($suffix);

$exec = static function (string $sql, array $params = []) use ($db): int {
    $statement = $db->prepare($sql);
    if ($statement === false) {
        throw new RuntimeException('Query gagal disiapkan: ' . $db->error);
    }
    if ($params !== []) {
        $types = '';
        $refs = [];
        foreach ($params as $key => &$value) {
            $types .= is_int($value) ? 'i' : 's';
            $refs[$key] = &$value;
        }
        unset($value);
        $statement->bind_param($types, ...$refs);
    }
    if (!$statement->execute()) {
        $error = $statement->error;
        $statement->close();
        throw new RuntimeException('Query gagal dijalankan: ' . $error);
    }
    $id = (int) $statement->insert_id;
    $statement->close();

    return $id;
};
$angka = static function (string $sql) use ($db): int {
    $rs = $db->query($sql);

    return (int) (($rs && $row = $rs->fetch_assoc()) ? ($row['n'] ?? 0) : 0);
};

/**
 * Menjalankan beberapa proses anak pada detik yang sama.
 *
 * @param array<int, array<string, int|string>> $tugas
 * @return array<int, array<string, mixed>>
 */
$jalankanBersamaan = static function (array $tugas) use ($root): array {
    $mulai = microtime(true) + 1.0;
    $proses = [];
    $pipa = [];
    foreach ($tugas as $index => $item) {
        $perintah = escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($root . '/tests/alumni_concurrency_worker.php')
            . ' --at=' . escapeshellarg((string) $mulai);
        foreach ($item as $nama => $nilai) {
            $perintah .= ' --' . $nama . '=' . escapeshellarg((string) $nilai);
        }
        $descriptors = [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']];
        $proses[$index] = proc_open($perintah, $descriptors, $pipa[$index], $root);
    }
    $hasil = [];
    foreach ($proses as $index => $handle) {
        if (!is_resource($handle)) {
            continue;
        }
        $keluaran = stream_get_contents($pipa[$index][1]);
        fclose($pipa[$index][1]);
        proc_close($handle);
        $baris = json_decode(trim((string) $keluaran), true);
        $hasil[] = is_array($baris) ? $baris : ['berhasil' => false, 'pesan' => 'keluaran tidak valid'];
    }

    return $hasil;
};

$dibuat = ['users' => [], 'santri' => [], 'kelas' => []];

try {
    $adminId = $exec(
        'INSERT INTO users (name, username, password, is_active, force_password_change, created_at, updated_at)
         VALUES (?, ?, ?, 1, 0, NOW(), NOW())',
        ['Admin Alumni Konkurensi ' . $suffix, 'ka.admin.' . $kecil, password_hash('UjiAlumniKonk123Aa', PASSWORD_DEFAULT)]
    );
    $dibuat['users'][] = $adminId;
    $exec("INSERT INTO user_roles (user_id, role_id, assigned_by) SELECT ?, id, ? FROM roles WHERE slug = 'admin'", [$adminId, $adminId]);
    $_SESSION = ['user_id' => $adminId];

    $year = alumni_service()->activeYear();
    if ($year === null) {
        fwrite(STDERR, "Tahun ajaran aktif tidak tersedia.\n");
        exit(2);
    }
    $yearId = (int) $year['id'];

    $kelas = $exec("INSERT INTO kelas (nama_kelas, jenjang, is_active, created_at, updated_at) VALUES (?, 'Uji', 1, NOW(), NOW())", ['KA Kelas ' . $suffix]);
    $dibuat['kelas'][] = $kelas;

    $santri = [];
    for ($i = 1; $i <= 6; $i++) {
        $santri[$i] = $exec(
            "INSERT INTO santri (nis, nama_santri, jenis_kelamin, tempat_lahir, tgl_lahir, alamat, desa, kecamatan, kab_kota, provinsi,
                                 nama_ayah, nama_ibu, asal_sekolah, sekolah_saat_ini, foto, is_active, created_at, updated_at)
             VALUES (?, ?, 'L', 'Ciamis', '2011-03-01', 'Jl Uji', 'Desa', 'Kec', 'Kab', 'Prov', 'Ayah', 'Ibu', 'SD', 'MTs Uji', 'default.jpg', 1, NOW(), NOW())",
            ['KA' . $suffix . $i, 'Santri Alumni Konkurensi ' . $i . ' ' . $suffix]
        );
        $dibuat['santri'][] = $santri[$i];
        $exec(
            "INSERT INTO plotting_kelas (id_santri, id_kelas, id_tahun, tanggal_mulai, status, created_at, updated_at)
             VALUES (?, ?, ?, '2026-07-01', 'Aktif', NOW(), NOW())",
            [$santri[$i], $kelas, $yearId]
        );
    }

    // ================================================================= KA-1
    $hasil1 = $jalankanBersamaan([
        ['santri' => (string) $santri[1], 'actor' => $adminId, 'status' => 'Lulus'],
        ['santri' => (string) $santri[1], 'actor' => $adminId, 'status' => 'Lulus'],
    ]);
    $berhasil1 = count(array_filter($hasil1, static fn (array $r): bool => (bool) ($r['berhasil'] ?? false)));
    $catatan1 = $angka("SELECT COUNT(*) n FROM alumni WHERE santri_id = {$santri[1]}");
    $assert($berhasil1 === 1, 'KA-1a Dua permintaan meluluskan santri yang sama: tepat satu berhasil [' . $berhasil1 . ']');
    $assert($catatan1 === 1, 'KA-1b Hanya ada SATU catatan alumni untuk santri itu [' . $catatan1 . ']');
    $assert(
        $angka("SELECT COUNT(*) n FROM santri WHERE id = {$santri[1]} AND is_active = 0 AND archived_at IS NOT NULL") === 1,
        'KA-1c Santri sumber terarsip tepat sekali dan tidak dihapus'
    );
    $assert(
        $angka("SELECT COUNT(*) n FROM plotting_kelas WHERE id_santri = {$santri[1]} AND id_tahun = {$yearId} AND status = 'Aktif'") === 0,
        'KA-1d Penempatan kelas aktif tetap tertutup meski ada permintaan bersamaan'
    );

    // ================================================================= KA-4
    $ditolak1 = array_values(array_filter($hasil1, static fn (array $r): bool => !($r['berhasil'] ?? false)));
    $pesan1 = (string) ($ditolak1[0]['pesan'] ?? '');
    $assert(
        $pesan1 !== '' && (
            str_contains($pesan1, 'sudah tercatat sebagai alumni')
            || str_contains($pesan1, 'perubahan bersamaan')
            || str_contains($pesan1, 'baru saja diproses')
            || str_contains($pesan1, 'tidak aktif atau sudah diarsipkan')
        ),
        'KA-4a Permintaan yang kalah mendapat pesan yang dapat dimengerti admin [' . $pesan1 . ']'
    );
    foreach ($hasil1 as $index => $baris) {
        $pesan = (string) ($baris['pesan'] ?? '');
        $bocor = false;
        foreach (['SQLSTATE', 'Duplicate entry', 'mysqli', 'SELECT ', 'INSERT INTO'] as $jejak) {
            if (str_contains($pesan, $jejak)) {
                $bocor = true;
            }
        }
        $assert(!$bocor, 'KA-4b Pesan permintaan #' . $index . ' tidak membocorkan galat basis data mentah');
    }

    // ================================================================= KA-2
    $hasil2 = $jalankanBersamaan(array_fill(0, 5, ['santri' => (string) $santri[2], 'actor' => $adminId, 'status' => 'Pindah']));
    $berhasil2 = count(array_filter($hasil2, static fn (array $r): bool => (bool) ($r['berhasil'] ?? false)));
    $catatan2 = $angka("SELECT COUNT(*) n FROM alumni WHERE santri_id = {$santri[2]}");
    $assert($berhasil2 === 1, 'KA-2a Lima permintaan atas santri yang sama: tepat satu berhasil [' . $berhasil2 . ']');
    $assert($catatan2 === 1, 'KA-2b Klik ganda beruntun tetap menghasilkan satu catatan alumni [' . $catatan2 . ']');

    // ================================================================= KA-3
    // Dua permintaan massal yang daftarnya bertumpuk pada santri 4.
    $hasil3 = $jalankanBersamaan([
        ['santri' => $santri[3] . ',' . $santri[4], 'actor' => $adminId, 'status' => 'Lulus'],
        ['santri' => $santri[4] . ',' . $santri[5], 'actor' => $adminId, 'status' => 'Lulus'],
    ]);
    $catatan4 = $angka("SELECT COUNT(*) n FROM alumni WHERE santri_id = {$santri[4]}");
    $assert($catatan4 === 1, 'KA-3a Santri yang muncul pada dua permintaan massal tetap punya satu catatan [' . $catatan4 . ']');

    $berhasil3 = count(array_filter($hasil3, static fn (array $r): bool => (bool) ($r['berhasil'] ?? false)));
    $assert($berhasil3 >= 1, 'KA-3b Sedikitnya satu permintaan massal berhasil (tidak keduanya gagal diam-diam)');

    // Atomisitas: setiap santri yang punya catatan alumni HARUS berstatus arsip
    // dan tanpa kelas aktif; setiap santri tanpa catatan HARUS masih aktif dan
    // masih memegang kelasnya. Tidak boleh ada keadaan setengah jadi.
    $setengahJadi = 0;
    foreach ([$santri[3], $santri[4], $santri[5]] as $id) {
        $punyaAlumni = $angka("SELECT COUNT(*) n FROM alumni WHERE santri_id = {$id}") > 0;
        $terarsip = $angka("SELECT COUNT(*) n FROM santri WHERE id = {$id} AND is_active = 0 AND archived_at IS NOT NULL") === 1;
        $kelasAktif = $angka("SELECT COUNT(*) n FROM plotting_kelas WHERE id_santri = {$id} AND id_tahun = {$yearId} AND status = 'Aktif'") > 0;
        if ($punyaAlumni !== $terarsip || $punyaAlumni === $kelasAktif) {
            $setengahJadi++;
        }
    }
    $assert($setengahJadi === 0, 'KA-3c Tidak ada santri yang berakhir setengah jadi [' . $setengahJadi . ' bermasalah]');

    $gandaGlobal = $angka(
        'SELECT COUNT(*) n FROM (SELECT santri_id FROM alumni WHERE santri_id IS NOT NULL AND archived_at IS NULL
          GROUP BY santri_id HAVING COUNT(*) > 1) x'
    );
    $assert($gandaGlobal === 0, 'KA-3d Tidak ada satu pun santri dengan lebih dari satu catatan alumni aktif');
} catch (Throwable $exception) {
    echo '[gagal] Pengujian berhenti: ' . $exception->getMessage() . PHP_EOL;
    $failures[] = 'pengujian berhenti: ' . $exception->getMessage();
} finally {
    try {
        foreach ($dibuat['santri'] as $id) {
            $db->query('DELETE FROM alumni WHERE santri_id = ' . (int) $id);
            $db->query('DELETE FROM plotting_kamar WHERE id_santri = ' . (int) $id);
            $db->query('DELETE FROM plotting_kelas WHERE id_santri = ' . (int) $id);
        }
        foreach ($dibuat['users'] as $id) {
            $db->query('DELETE FROM user_roles WHERE user_id = ' . (int) $id);
            $db->query('DELETE FROM audit_logs WHERE actor_user_id = ' . (int) $id);
            $db->query('UPDATE alumni SET created_by = NULL, updated_by = NULL WHERE created_by = ' . (int) $id . ' OR updated_by = ' . (int) $id);
            $db->query('DELETE FROM users WHERE id = ' . (int) $id);
        }
        foreach ($dibuat['santri'] as $id) {
            $db->query('DELETE FROM santri WHERE id = ' . (int) $id);
        }
        foreach ($dibuat['kelas'] as $id) {
            $db->query('DELETE FROM plotting_kelas WHERE id_kelas = ' . (int) $id);
            $db->query('DELETE FROM kelas WHERE id = ' . (int) $id);
        }
    } catch (Throwable $exception) {
        echo '[gagal] Bersih-bersih fixture: ' . $exception->getMessage() . PHP_EOL;
        $failures[] = 'bersih-bersih fixture';
    }
}

echo PHP_EOL;
if ($failures === []) {
    echo 'SELURUH PENGUJIAN PERMINTAAN BERSAMAAN ALUMNI LULUS.' . PHP_EOL;
    exit(0);
}
echo 'GAGAL (' . count($failures) . '):' . PHP_EOL . ' - ' . implode(PHP_EOL . ' - ', $failures) . PHP_EOL;
exit(1);
