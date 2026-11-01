<?php

declare(strict_types=1);

/**
 * Skenario sintetis Fase 2 (aplikasi) kelas gabungan, di atas DB
 * `tests/jadwal_kelas_gabungan_f1_db.sh` dan seed Fase 1, lewat layanan resmi:
 *
 *   J_GAB   guru G (`sbx_guru_biasa`), kelas PA (utama) + PI, hari ini
 *   J_SATU  guru G, kelas C saja, hari ini
 *   J_LAIN  guru G, kelas C, besok (tetap satu kelas; tampil di daftar pekan)
 *   K       `sbx_pengurus_biasa` diberi penugasan Keamanan (pembaca rincian kehadiran)
 *
 * Pertemuan TIDAK dibuka di sini: pembukaan dilakukan dari aplikasi.
 *
 *   JKG_DB=test_jkg_f2_app_test bash tests/jadwal_kelas_gabungan_f1_db.sh
 *   JKG_DB=test_jkg_f2_app_test php tests/jadwal_kelas_gabungan_f2_seed.php > ids.json
 */

$root = dirname(__DIR__);
$dbName = getenv('JKG_DB') ?: 'test_jkg_f2_app_test';
if (!preg_match('/^test_.*_test$/', $dbName)) {
    fwrite(STDERR, "DB uji harus bernama test_*_test.\n");
    exit(2);
}
putenv('DB_NAME=' . $dbName);
require_once $root . '/app/bootstrap.php';

$ids = require __DIR__ . '/jadwal_kelas_gabungan_f1_seed.php';
$db = app_db();
$s = schedule_service();
$k = $ids['kelas'];
$hariNama = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];
$zona = new DateTimeZone((string) (app_config('app.timezone') ?: 'Asia/Jakarta'));
$hariIni = new DateTimeImmutable('today', $zona);
$besok = $hariIni->modify('+1 day');
$hari = static fn (DateTimeImmutable $d): string => $hariNama[(int) $d->format('N') - 1];

/** Simpan dengan jam pertama yang tidak bentrok dengan jadwal guru G yang sudah ada. */
$simpan = static function (array $isian) use ($s, $ids): int {
    foreach ([['22:00', '22:30'], ['22:35', '23:05'], ['23:10', '23:40'], ['04:00', '04:30'], ['04:35', '05:05']] as [$mulai, $selesai]) {
        try {
            return (int) $s->save($isian + ['waktu_mulai' => $mulai, 'waktu_selesai' => $selesai], $ids['admin_id'])['id'];
        } catch (\App\Schedule\ScheduleException $e) {
            if (!str_contains($e->getMessage(), 'Bentrok guru')) {
                throw $e;
            }
        }
    }
    throw new RuntimeException('Tidak ada jam kosong untuk guru G.');
};
$dasar = static fn (string $hari, string $mapel, string $tempat): array => [
    'id_tahun' => $ids['tahun'], 'waktu_sholat' => "Ba'da Isya", 'hari' => $hari, 'id_guru' => $ids['guru_g'],
    'fan_ilmu' => $mapel . ' ' . $ids['sfx'], 'nama_kitab' => 'Kitab ' . $mapel, 'tempat' => $tempat . ' ' . $ids['sfx'],
];

$gab = $simpan($dasar($hari($hariIni), 'Nahwu', 'Ruang Bersama') + ['id_kelas' => $k['PA'], 'kelas_gabungan' => [(string) $k['PI']]]);
$satu = $simpan($dasar($hari($hariIni), 'Fiqih', 'Ruang C') + ['id_kelas' => $k['C']]);
$lain = $simpan($dasar($hari($besok), 'Tajwid', 'Ruang C') + ['id_kelas' => $k['C']]);

$keamanan = (int) $db->query("SELECT pengurus_id FROM users WHERE username = 'sbx_pengurus_biasa'")->fetch_row()[0];
penugasan_service()->buat(\App\Penugasan\PenugasanJenis::KEAMANAN, ['pengurus_id' => (string) $keamanan, 'tahun_ajaran_id' => (string) $ids['tahun'], 'tanggal_mulai' => $hariIni->format('Y-m-d')], $ids['admin_id']);

$nama = [];
foreach ($s->classes() as $row) {
    $nama[(int) $row['id']] = (string) $row['nama_kelas'];
}
$santri = [];
foreach (['PA', 'PI', 'C'] as $kunci) {
    foreach ($ids['santri'][$kunci] as $sid) {
        $r = $db->query('SELECT nama_santri FROM santri WHERE id = ' . (int) $sid)->fetch_row();
        $santri[$kunci][] = ['id' => (int) $sid, 'nama' => (string) $r[0]];
    }
}
$selesai = $db->query('SELECT nama_santri FROM santri WHERE id = ' . (int) $ids['santri_selesai'])->fetch_row();

echo json_encode($ids + [
    'tanggal' => $hariIni->format('Y-m-d'),
    'besok' => $besok->format('Y-m-d'),
    'jadwal_gabungan' => $gab,
    'jadwal_satu' => $satu,
    'jadwal_besok' => $lain,
    'nama_kelas' => ['PA' => $nama[$k['PA']], 'PI' => $nama[$k['PI']], 'C' => $nama[$k['C']]],
    'santri_nama' => $santri,
    'santri_selesai_nama' => (string) $selesai[0],
    'murobi_user' => 'sbx_murobi_a',
    'keamanan_user' => 'sbx_pengurus_biasa',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
