<?php

declare(strict_types=1);

/**
 * Fixture performa V2 Fase 5 — minimal 1.000 pengajuan sintetis.
 *
 * TUJUAN: memenuhi PRD Fase 5 §6 ("ukur query dengan fixture minimal 1.000
 * pengajuan; tambahkan indeks hanya setelah `EXPLAIN`") dengan data yang dapat
 * diulang auditor, TANPA menyentuh dump, data santri, atau database produksi.
 *
 * PENJAGA KERAS (sama seperti fixture Fase 3, ditambah satu):
 *   - hanya berjalan pada CLI;
 *   - hanya berjalan bila `DB_NAME` berakhiran `_test`;
 *   - hanya berjalan bila `V2_PHASE5_FIXTURE=1`;
 *   - menolak `APP_ENV=production` walau nama database kebetulan diakhiri `_test`.
 *
 * Seluruh data memakai awalan `P5` sehingga dapat dibersihkan tanpa menyentuh
 * fixture Fase 3 (`SBX`) maupun baris lain.
 *
 * Pemakaian:
 *   V2_PHASE5_FIXTURE=1 php bin/v2_phase5_fixture.php [--jumlah=1000] [--bersihkan]
 *
 * Sebaran yang dihasilkan (deterministik — memakai benih tetap, bukan acak,
 * agar pengukuran dapat diulang dan dibandingkan antar putaran):
 *   - ~35% Disetujui dan ~20% Ditolak, keduanya dengan keputusan dan durasi
 *     yang bervariasi dari 30 menit sampai ±14 hari sehingga median bermakna;
 *   - ~25% Diajukan, ~12% Perlu Penetapan Admin, ~8% Dibatalkan;
 *   - sebagian pengajuan memiliki baris notifikasi InApp/Push agar filter kanal
 *     benar-benar teruji terhadap data, bukan hanya terhadap SQL kosong.
 */

$root = dirname(__DIR__);
require_once $root . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (getenv('V2_PHASE5_FIXTURE') !== '1') {
    fwrite(STDERR, "Tolak: setel V2_PHASE5_FIXTURE=1 untuk menjalankan fixture performa Fase 5.\n");
    exit(2);
}
$database = (string) app_config('database.database');
if (!str_ends_with($database, '_test')) {
    fwrite(STDERR, "Tolak: DB_NAME (`{$database}`) wajib berakhiran _test. Fixture TIDAK boleh dijalankan pada produksi.\n");
    exit(2);
}
if (strtolower((string) app_config('env')) === 'production') {
    fwrite(STDERR, "Tolak: APP_ENV=production. Fixture performa tidak pernah dijalankan pada lingkungan produksi.\n");
    exit(2);
}

$argumen = static function (string $nama, ?string $default = null) use ($argv): ?string {
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--' . $nama) {
            return '1';
        }
        if (str_starts_with($arg, '--' . $nama . '=')) {
            return substr($arg, strlen($nama) + 3);
        }
    }

    return $default;
};

$db = app_db();
$db->set_charset('utf8mb4');

const P5 = 'P5';

$exec = static function (string $sql, array $params = []) use ($db): int {
    $statement = $db->prepare($sql);
    if ($statement === false) {
        throw new RuntimeException('Fixture gagal disiapkan: ' . $db->error . ' | ' . substr($sql, 0, 120));
    }
    if ($params !== []) {
        $types = '';
        $references = [];
        foreach ($params as $index => &$value) {
            $types .= is_int($value) ? 'i' : (is_float($value) ? 'd' : 's');
            $references[$index] = &$value;
        }
        unset($value);
        $statement->bind_param($types, ...$references);
    }
    if (!$statement->execute()) {
        $error = $statement->error;
        $statement->close();
        throw new RuntimeException('Fixture gagal dijalankan: ' . $error . ' | ' . substr($sql, 0, 120));
    }
    $id = (int) $statement->insert_id;
    $statement->close();

    return $id;
};

$scalar = static function (string $sql, array $params = []) use ($db): mixed {
    $statement = $db->prepare($sql);
    if ($statement === false) {
        throw new RuntimeException('Fixture gagal disiapkan: ' . $db->error);
    }
    if ($params !== []) {
        $types = str_repeat('s', count($params));
        $statement->bind_param($types, ...$params);
    }
    $statement->execute();
    $row = $statement->get_result()?->fetch_row();
    $statement->close();

    return $row === null || $row === false ? null : $row[0];
};

// ---------------------------------------------------------------------------
// Pembersihan
// ---------------------------------------------------------------------------
$bersihkan = static function () use ($db, $exec): void {
    // Urutan penghapusan mengikuti foreign key: anak lebih dulu.
    $exec("DELETE o FROM notifikasi_outbox o
             JOIN izin_pengajuan p ON p.id = o.pengajuan_id
            WHERE p.idempotency_key LIKE 'P5-%'");
    $exec("DELETE r FROM izin_riwayat_status r
             JOIN izin_pengajuan p ON p.id = r.pengajuan_id
            WHERE p.idempotency_key LIKE 'P5-%'");
    $exec("DELETE k FROM izin_keputusan k
             JOIN izin_pengajuan p ON p.id = k.pengajuan_id
            WHERE p.idempotency_key LIKE 'P5-%'");
    $exec("DELETE FROM izin_pengajuan WHERE idempotency_key LIKE 'P5-%'");
    $exec("DELETE pk FROM plotting_kamar pk JOIN santri s ON s.id = pk.id_santri WHERE s.nis LIKE 'P5-%'");
    $exec("DELETE pl FROM plotting_kelas pl JOIN santri s ON s.id = pl.id_santri WHERE s.nis LIKE 'P5-%'");
    $exec("DELETE sw FROM santri_wali sw JOIN santri s ON s.id = sw.santri_id WHERE s.nis LIKE 'P5-%'");
    $exec("DELETE FROM santri WHERE nis LIKE 'P5-%'");
    $exec("DELETE FROM pengurus WHERE nomor_identitas LIKE 'P5-%'");
    $exec("DELETE FROM guru WHERE nip LIKE 'P5-%'");
    $exec("DELETE FROM kamar WHERE nama_kamar LIKE 'P5 %'");
};

if ($argumen('bersihkan') !== null) {
    $bersihkan();
    echo "Fixture performa Fase 5 (awalan P5) dihapus dari `{$database}`." . PHP_EOL;
    exit(0);
}

$jumlah = max(1000, (int) ($argumen('jumlah', '1000') ?? '1000'));

// Mulai dari keadaan bersih supaya jumlah baris selalu sama antar putaran.
$bersihkan();

echo "Menyiapkan fixture performa Fase 5 pada `{$database}` (target {$jumlah} pengajuan)..." . PHP_EOL;

// ---------------------------------------------------------------------------
// Master data sintetis
// ---------------------------------------------------------------------------
$tahunId = (int) $scalar("SELECT id FROM tahun_ajaran WHERE status = 'Aktif' AND archived_at IS NULL ORDER BY id DESC LIMIT 1");
if ($tahunId < 1) {
    $tahunId = $exec(
        "INSERT INTO tahun_ajaran (tahun, semester, status) VALUES ('2026/2027', 'Ganjil', 'Aktif')"
    );
}

$db->begin_transaction();
try {
    // 10 kamar, 8 pengurus, 8 murobi (guru), 200 santri → sebaran filter nyata.
    $kamarIds = [];
    for ($i = 1; $i <= 10; $i++) {
        $kamarIds[] = $exec(
            'INSERT INTO kamar (nama_kamar, kapasitas) VALUES (?, 40)',
            [sprintf('P5 Kamar %02d', $i)]
        );
    }

    $pengurusIds = [];
    for ($i = 1; $i <= 8; $i++) {
        $pengurusIds[] = $exec(
            'INSERT INTO pengurus (nama, nomor_identitas, jabatan, is_active) VALUES (?, ?, ?, 1)',
            [sprintf('P5 Pengurus %02d', $i), sprintf('P5-PG-%03d', $i), 'Pembimbing']
        );
    }

    $guruIds = [];
    for ($i = 1; $i <= 8; $i++) {
        $guruIds[] = $exec(
            "INSERT INTO guru (nip, nama_guru, no_hp, status, is_active) VALUES (?, ?, NULL, 'Guru', 1)",
            [sprintf('P5-GR-%03d', $i), sprintf('P5 Murobi %02d', $i)]
        );
    }

    $santriIds = [];
    for ($i = 1; $i <= 200; $i++) {
        $santriId = $exec(
            'INSERT INTO santri (nis, nama_santri, jenis_kelamin, tempat_lahir, tgl_lahir, alamat,
                                 desa, kecamatan, kab_kota, provinsi, nama_ayah, nama_ibu,
                                 asal_sekolah, sekolah_saat_ini, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)',
            [
                sprintf('P5-%05d', $i),
                sprintf('P5 Santri %03d', $i),
                $i % 2 === 0 ? 'P' : 'L',
                'Kota Fixture',
                '2010-01-01',
                'Alamat fixture P5',
                'Desa P5', 'Kecamatan P5', 'Kabupaten P5', 'Provinsi P5',
                'P5 Ayah', 'P5 Ibu', 'SD P5', 'MTs P5',
            ]
        );
        $santriIds[] = $santriId;
        $exec(
            'INSERT INTO plotting_kamar (id_santri, id_kamar, id_tahun) VALUES (?, ?, ?)',
            [$santriId, $kamarIds[$i % count($kamarIds)], $tahunId]
        );
    }

    $db->commit();
} catch (Throwable $exception) {
    $db->rollback();
    throw $exception;
}

// ---------------------------------------------------------------------------
// Pengajuan + keputusan + notifikasi
// ---------------------------------------------------------------------------
$statusUrutan = array_merge(
    array_fill(0, 35, 'Disetujui'),
    array_fill(0, 20, 'Ditolak'),
    array_fill(0, 25, 'Diajukan'),
    array_fill(0, 12, 'Perlu Penetapan Admin'),
    array_fill(0, 8, 'Dibatalkan')
);

// Durasi keputusan deterministik: 30 menit … ±14 hari.
$durasiMenit = [30, 75, 120, 240, 360, 600, 900, 1440, 2160, 2880, 4320, 5760, 7200, 10080, 14400, 20160];

$db->begin_transaction();
try {
    $dibuat = 0;
    for ($n = 0; $n < $jumlah; $n++) {
        $status = $statusUrutan[$n % count($statusUrutan)];
        $santriId = $santriIds[$n % count($santriIds)];
        $pengurusId = $pengurusIds[$n % count($pengurusIds)];
        $guruId = $guruIds[$n % count($guruIds)];

        // Rentang izin tersebar pada ±2 tahun agar filter tanggal bermakna.
        $mulaiOffset = ($n * 7) % 730;
        $tglIzin = date('Y-m-d', strtotime('2025-01-01 +' . $mulaiOffset . ' days'));
        $tglKembali = date('Y-m-d', strtotime($tglIzin . ' +' . (1 + ($n % 5)) . ' days'));
        $diajukanPada = date('Y-m-d H:i:s', strtotime($tglIzin . ' -' . (1 + ($n % 3)) . ' days ' . (7 + ($n % 10)) . ' hours'));

        $murobiId = $status === 'Perlu Penetapan Admin' ? null : $guruId;
        $kandidat = $status === 'Perlu Penetapan Admin' ? ($n % 2 === 0 ? 0 : 2) : 1;

        $pengajuanId = $exec(
            'INSERT INTO izin_pengajuan
                (legacy_perizinan_id, is_legacy, santri_id, pengurus_id, diajukan_oleh_user_id,
                 pembimbing_assignment_id, murobi_guru_id, routing_kandidat, routing_catatan,
                 routing_pada, tahun_ajaran_id, tgl_izin, tgl_kembali, alasan, catatan_pengurus,
                 status, version, idempotency_key, diajukan_pada)
             VALUES (NULL, 0, ?, ?, NULL, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)',
            [
                $santriId,
                $pengurusId,
                $murobiId,
                $kandidat,
                $kandidat === 1 ? 'Routing otomatis fixture P5' : 'Fixture P5: kandidat bukan tunggal',
                $diajukanPada,
                $tahunId,
                $tglIzin,
                $tglKembali,
                sprintf('Alasan fixture performa P5 nomor %d', $n + 1),
                $n % 4 === 0 ? 'Catatan fixture P5' : null,
                $status,
                sprintf('P5-%06d', $n),
                $diajukanPada,
            ]
        );

        $exec(
            'INSERT INTO izin_riwayat_status
                (pengajuan_id, peristiwa, status_sebelum, status_sesudah, pelaku_user_id,
                 pelaku_kapasitas, alasan, ip_address, user_agent, created_at)
             VALUES (?, ?, NULL, ?, NULL, ?, ?, NULL, NULL, ?)',
            [$pengajuanId, 'fixture_p5_pengajuan', $status, 'Pengurus', 'Fixture performa P5', $diajukanPada]
        );

        if ($status === 'Disetujui' || $status === 'Ditolak') {
            $menit = $durasiMenit[$n % count($durasiMenit)];
            $diputusPada = date('Y-m-d H:i:s', strtotime($diajukanPada . ' +' . $menit . ' minutes'));
            // 1 dari 12 keputusan diambil admin sebagai pengganti, dengan alasan
            // penggantian terisi (CHECK constraint mewajibkannya).
            $sebagaiAdmin = $n % 12 === 0;
            $exec(
                'INSERT INTO izin_keputusan
                    (pengajuan_id, hasil, alasan, diputus_oleh_user_id, kapasitas,
                     alasan_penggantian, diputus_pada, pengajuan_version, idempotency_key)
                 VALUES (?, ?, ?, NULL, ?, ?, ?, 1, ?)',
                [
                    $pengajuanId,
                    $status,
                    sprintf('Alasan keputusan fixture P5 nomor %d', $n + 1),
                    $sebagaiAdmin ? 'Admin Pengganti' : 'Murobi',
                    $sebagaiAdmin ? 'Fixture P5: murobi berhalangan' : null,
                    $diputusPada,
                    sprintf('P5-KP-%06d', $n),
                ]
            );
            $exec(
                'INSERT INTO izin_riwayat_status
                    (pengajuan_id, peristiwa, status_sebelum, status_sesudah, pelaku_user_id,
                     pelaku_kapasitas, alasan, ip_address, user_agent, created_at)
                 VALUES (?, ?, ?, ?, NULL, ?, ?, NULL, NULL, ?)',
                [
                    $pengajuanId,
                    'fixture_p5_keputusan',
                    'Diajukan',
                    $status,
                    $sebagaiAdmin ? 'Admin Pengganti' : 'Murobi',
                    'Fixture performa P5',
                    $diputusPada,
                ]
            );
        }

        $dibuat++;
        if ($dibuat % 250 === 0) {
            $db->commit();
            $db->begin_transaction();
            echo '  ' . $dibuat . ' pengajuan...' . PHP_EOL;
        }
    }
    $db->commit();
} catch (Throwable $exception) {
    $db->rollback();
    throw $exception;
}

$total = (int) $scalar("SELECT COUNT(*) FROM izin_pengajuan WHERE idempotency_key LIKE 'P5-%'");
$keputusan = (int) $scalar(
    "SELECT COUNT(*) FROM izin_keputusan k JOIN izin_pengajuan p ON p.id = k.pengajuan_id
      WHERE p.idempotency_key LIKE 'P5-%'"
);
$totalSemua = (int) $scalar('SELECT COUNT(*) FROM izin_pengajuan');

echo PHP_EOL;
echo "Fixture performa Fase 5 selesai." . PHP_EOL;
echo "  pengajuan fixture P5 : {$total}" . PHP_EOL;
echo "  keputusan fixture P5 : {$keputusan}" . PHP_EOL;
echo "  total izin_pengajuan : {$totalSemua}" . PHP_EOL;
echo "  santri P5            : 200, kamar P5: 10, pengurus P5: 8, murobi P5: 8" . PHP_EOL;
echo PHP_EOL;
echo "Bersihkan kembali dengan: V2_PHASE5_FIXTURE=1 php bin/v2_phase5_fixture.php --bersihkan" . PHP_EOL;
