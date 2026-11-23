<?php

declare(strict_types=1);

/**
 * Fixture uji native (K-9) status "Izin" otomatis di atas DB
 * `jadwal_kelas_gabungan_f1_db.sh` + `jadwal_kelas_gabungan_f2_seed.php`:
 * satu jadwal kelas C (guru G) hari ini, izin murobi Disetujui untuk santri C
 * pertama mulai hari ini, dan dua pertemuan dibuka lewat REST (hari ini untuk
 * Android, +7 hari untuk iOS). Mencetak JSON.
 *
 *   IOP_DB=test_iop_app_test php tests/izin_otomatis_pengajian_native_seed.php
 */

$dbName = getenv('IOP_DB') ?: 'test_iop_app_test';
if (!preg_match('/^test_.*_test$/', $dbName)) {
    exit(2);
}
putenv('DB_NAME=' . $dbName);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$db = app_db();
$one = static fn (string $sql): mixed => $db->query($sql)->fetch_row()[0] ?? null;
$zona = new DateTimeZone((string) (app_config('app.timezone') ?: 'Asia/Jakarta'));
$hariIni = new DateTimeImmutable('today', $zona);
$hari = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'][(int) $hariIni->format('N') - 1];
$guruG = (int) $one("SELECT guru_id FROM users WHERE username = 'sbx_guru_biasa'");
$guruUser = (int) $one("SELECT id FROM users WHERE username = 'sbx_guru_biasa'");
$adminId = (int) $one("SELECT id FROM users WHERE username = 'sbx_admin'");
$tahun = (int) $one("SELECT id FROM tahun_ajaran WHERE status = 'Aktif' ORDER BY id DESC LIMIT 1");
$kelasC = (int) $one("SELECT id FROM kelas WHERE nama_kelas LIKE '2 IBTIDA C %' ORDER BY id DESC LIMIT 1");
$santri = $db->query("SELECT s.id, s.nama_santri FROM plotting_kelas pk JOIN santri s ON s.id = pk.id_santri WHERE pk.id_kelas = $kelasC AND pk.status = 'Aktif' AND s.is_active = 1 ORDER BY s.nama_santri, s.id")->fetch_all(MYSQLI_ASSOC);
$jadwal = null;
foreach ([['05:10', '05:40'], ['05:45', '06:15'], ['06:20', '06:50'], ['13:00', '13:30']] as [$mulai, $selesai]) {
    try {
        $jadwal = (int) schedule_service()->save(['id_tahun' => $tahun, 'id_kelas' => $kelasC, 'id_guru' => $guruG, 'hari' => $hari, 'waktu_mulai' => $mulai, 'waktu_selesai' => $selesai,
            'waktu_sholat' => "Ba'da Isya", 'fan_ilmu' => 'Native izin', 'nama_kitab' => 'Uji', 'tempat' => 'Ruang native'], $adminId)['id'];
        break;
    } catch (App\Schedule\ScheduleException $e) {
        if (!str_contains($e->getMessage(), 'Bentrok')) {
            throw $e;
        }
    }
}
$c0 = (int) $santri[0]['id'];
$sampai = $hariIni->modify('+9 days')->format('Y-m-d');
$db->query("INSERT INTO izin_pengajuan (santri_id, murobi_guru_id, tahun_ajaran_id, tgl_izin, tgl_kembali, alasan, status, asal_pengajuan, diajukan_pada)
            VALUES ($c0, $guruG, $tahun, '{$hariIni->format('Y-m-d')}', '$sampai', 'RAHASIA-ALASAN-native', 'Disetujui', 'Pengurus', NOW())");
$izinId = (int) $db->insert_id;
$db->query("INSERT INTO izin_keputusan (pengajuan_id, hasil, alasan, diputus_oleh_user_id, kapasitas, diputus_pada) VALUES ($izinId, 'Disetujui', 'RAHASIA-ALASAN-native', $adminId, 'Murobi', NOW())");
$guru = ['id' => $guruUser, 'roles' => ['guru'], 'guru_id' => $guruG, 'name' => 'Guru'];
$buka = static fn (string $d): int => (int) teacher_api_service()->openMeeting($jadwal, ['date' => $d, 'idempotency_key' => 'iop-native-' . $d . '-' . $jadwal], $guru)['data']['id'];
$bulan = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
echo json_encode([
    'android' => $buka($hariIni->format('Y-m-d')),
    'ios' => $buka($hariIni->modify('+7 days')->format('Y-m-d')),
    'izin' => $izinId,
    'santri_izin' => $santri[0]['nama_santri'],
    'santri_lain' => $santri[1]['nama_santri'] ?? '',
    'label' => 'Izin s.d. ' . (int) substr($sampai, 8, 2) . ' ' . $bulan[(int) substr($sampai, 5, 2)],
    'keterangan' => "Izin No. $izinId — disetujui murobi s.d. " . (int) substr($sampai, 8, 2) . ' ' . $bulan[(int) substr($sampai, 5, 2)],
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
