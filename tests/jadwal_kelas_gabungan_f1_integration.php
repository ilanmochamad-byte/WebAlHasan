<?php

declare(strict_types=1);

/**
 * Uji integrasi Fase 1 perbaikan jadwal kelas gabungan (migrasi 034) lewat
 * layanan resmi web (ScheduleService), REST API (TeacherService), laporan
 * (ReportService), dan rekap kehadiran rapor (V4 VerifikasiService):
 *
 *   JKG_DB=test_jkg_f1_test bash tests/jadwal_kelas_gabungan_f1_db.sh
 *   JKG_DB=test_jkg_f1_test php tests/jadwal_kelas_gabungan_f1_integration.php
 *
 *   I-1  data lama tanpa baris relasi tetap terbaca satu kelas (isian migrasi: uji migrasi)
 *   I-2  jadwal satu kelas dan dua kelas; label/ID kelas; relasi tersimpan
 *   I-3  validasi pilihan kelas (arsip, tidak valid, >10, duplikat utama)
 *   I-4  benturan: guru ditolak; kelas (himpunan) & tempat diperingatkan;
 *        kelas di dalam satu jadwal gabungan bukan benturan
 *   I-5  saringan kelas daftar jadwal mencakup seluruh kelas terpilih
 *   I-6  satu pertemuan untuk kedua kelas; peserta aktif seluruh kelas,
 *        kelas asal tersimpan, tanpa duplikasi; pembukaan kedua ditolak (web/API)
 *   I-7  API: `classes` aditif, `class` lama tetap; kelas asal per santri
 *   I-8  absensi: satu absensi guru, satu absensi per santri
 *   I-9  perubahan kelas sesudah pertemuan dibuka hanya untuk pertemuan berikutnya
 *   I-10 laporan/ekspor: gabungan tepat sekali; per kelas = santri kelas itu;
 *        absensi guru tidak ganda; ringkasan jadwal tidak terpecah
 *   I-11 rekap kehadiran rapor: satu pertemuan gabungan = satu hitungan per santri
 *   I-12 dua pembukaan bersamaan (web + API) → tepat satu pertemuan, tanpa peserta ganda
 *   I-13 ubah kelas bersamaan dengan pembukaan → pertemuan konsisten dengan salah satu keadaan
 *   I-14 audit menyimpan kelas sebelum/sesudah
 *   I-15 preservasi: baris jadwal/pertemuan/peserta/absensi/santri lama tidak berubah
 *
 * Mengubah data uji; bangun ulang DB sebelum mengulang.
 */

use App\Api\ApiException;
use App\Report\ReportFilter;
use App\Schedule\ScheduleException;
use App\V4\VerifikasiService;

$root = dirname(__DIR__);
$dbName = getenv('JKG_DB') ?: 'test_jkg_f1_test';
if (!preg_match('/^test_.*_test$/', $dbName)) {
    fwrite(STDERR, "DB uji harus bernama test_*_test.\n");
    exit(2);
}
putenv('DB_NAME=' . $dbName);
require_once $root . '/app/bootstrap.php';

$lulus = 0;
$gagal = [];
$assert = static function (bool $ok, string $pesan) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    $ok ? $lulus++ : $gagal[] = $pesan;
};
$db = app_db();
$satu = static function (string $sql) use ($db): mixed {
    $r = $db->query($sql);
    if ($r === false) {
        throw new RuntimeException($db->error . ' :: ' . $sql);
    }
    return $r->fetch_row()[0] ?? null;
};
$semua = static function (string $sql) use ($db): array {
    $r = $db->query($sql);
    if ($r === false) {
        throw new RuntimeException($db->error . ' :: ' . $sql);
    }
    return $r->fetch_all(MYSQLI_ASSOC);
};
$ditolak = static function (callable $aksi, string $potongan = ''): bool {
    try {
        $aksi();
        return false;
    } catch (ScheduleException|ApiException $e) {
        return $potongan === '' || str_contains($e->getMessage(), $potongan);
    }
};

// Sidik baris lama (I-15) sebelum fixture apa pun dibuat.
$batas = [
    'jadwal' => (int) $satu('SELECT COALESCE(MAX(id), 0) FROM jadwal_ngaji'),
    'pertemuan' => (int) $satu('SELECT COALESCE(MAX(id), 0) FROM pertemuan_pengajian'),
    'santri' => (int) $satu('SELECT COALESCE(MAX(id), 0) FROM santri'),
];
$sidikLama = static function () use ($satu, $batas): array {
    $j = $batas['jadwal'];
    $p = $batas['pertemuan'];
    return [
        'jadwal' => (string) $satu("SELECT CONCAT(COUNT(*), ':', COALESCE(SUM(CRC32(CONCAT_WS('|', id, id_tahun, id_kelas, id_guru, hari, waktu_mulai, waktu_selesai, tempat, fan_ilmu, is_active, COALESCE(archived_at, '~')))), 0)) FROM jadwal_ngaji WHERE id <= $j"),
        'pertemuan' => (string) $satu("SELECT CONCAT(COUNT(*), ':', COALESCE(SUM(CRC32(CONCAT_WS('|', id, jadwal_id, tanggal_pertemuan, status, COALESCE(opened_at, '~'), COALESCE(completed_at, '~')))), 0)) FROM pertemuan_pengajian WHERE id <= $p"),
        'peserta' => (string) $satu("SELECT CONCAT(COUNT(*), ':', COALESCE(SUM(CRC32(CONCAT_WS('|', id, pertemuan_id, santri_id, COALESCE(plotting_kelas_id, '~'), kelas_id_snapshot, tahun_ajaran_id_snapshot, nis_snapshot))), 0)) FROM pertemuan_peserta WHERE pertemuan_id <= $p"),
        'absensi_guru' => (string) $satu("SELECT CONCAT(COUNT(*), ':', COALESCE(SUM(CRC32(CONCAT_WS('|', id, pertemuan_id, guru_id, status, dicatat_pada))), 0)) FROM absensi_guru WHERE pertemuan_id <= $p"),
        'absensi_santri' => (string) $satu("SELECT CONCAT(COUNT(*), ':', COALESCE(SUM(CRC32(CONCAT_WS('|', id, pertemuan_id, santri_id, status, dicatat_pada))), 0)) FROM absensi_santri WHERE pertemuan_id <= $p"),
        'santri' => (string) $satu("SELECT CONCAT(COUNT(*), ':', COALESCE(SUM(CRC32(CONCAT_WS('|', id, nis, nama_santri, is_active))), 0)) FROM santri WHERE id <= {$batas['santri']}"),
    ];
};
$awal = $sidikLama();

echo "=== I-1 data lama tanpa baris relasi ===\n";
// Rantai fixture memasang 034 lebih dulu lalu menyisipkan jadwal/pertemuan
// lewat SQL mentah, sehingga baris lama di DB ini TIDAK memiliki baris
// jadwal_kelas/pertemuan_kelas. Kode wajib tetap membacanya sebagai satu kelas
// (kelas utama dan kelas snapshot). Isian migrasi diuji di uji migrasi.
$lamaJadwal = array_map('intval', array_column($semua('SELECT id FROM jadwal_ngaji j WHERE EXISTS (SELECT 1 FROM kelas k WHERE k.id = j.id_kelas)'), 'id'));
$bacaJadwal = App\Schedule\KelasJadwal::daftarJadwal($db, $lamaJadwal);
$cocok = $lamaJadwal !== [];
foreach ($lamaJadwal as $jid) {
    $cocok = $cocok && array_column($bacaJadwal[$jid] ?? [], 'id') === [(int) $satu("SELECT id_kelas FROM jadwal_ngaji WHERE id = $jid")];
}
$assert($cocok, 'Jadwal lama tanpa baris jadwal_kelas terbaca tepat satu kelas = jadwal_ngaji.id_kelas (' . count($lamaJadwal) . ' jadwal)');
$lamaPertemuan = array_map('intval', array_column($semua("SELECT id FROM pertemuan_pengajian WHERE status IN ('Dibuka','Selesai')"), 'id'));
$bacaPertemuan = App\Schedule\KelasJadwal::daftarPertemuan($db, $lamaPertemuan);
$cocok = $lamaPertemuan !== [];
foreach ($lamaPertemuan as $pid) {
    $snap = array_map('intval', array_column($semua("SELECT DISTINCT kelas_id_snapshot FROM pertemuan_peserta WHERE pertemuan_id = $pid"), 'kelas_id_snapshot'));
    $cocok = $cocok && ($snap === [] || array_column($bacaPertemuan[$pid] ?? [], 'id') === $snap);
}
$assert($cocok, 'Pertemuan lama terbaca berkelas sama dengan snapshot pesertanya (' . count($lamaPertemuan) . ' pertemuan)');

$ids = require __DIR__ . '/jadwal_kelas_gabungan_f1_seed.php';
$k = $ids['kelas'];
$admin = ['id' => $ids['admin_id'], 'roles' => ['admin'], 'guru_id' => null, 'name' => 'Admin uji'];
$guru = ['id' => $ids['guru_user_id'], 'roles' => ['guru'], 'guru_id' => $ids['guru_g'], 'name' => 'Guru uji'];
$s = schedule_service();
$api = teacher_api_service();
$laporan = report_service();
$tanggal = static fn (int $minggu): string => (new DateTimeImmutable('next thursday'))->modify('+' . (7 * $minggu) . ' days')->format('Y-m-d');
$dasar = ['id_tahun' => $ids['tahun'], 'waktu_sholat' => "Ba'da Isya", 'hari' => 'Kamis', 'waktu_mulai' => '20:00', 'waktu_selesai' => '20:45',
    'fan_ilmu' => 'Nahwu ' . $ids['sfx'], 'nama_kitab' => 'Jurumiyah', 'id_guru' => $ids['guru_g'], 'tempat' => 'Ruang Bersama ' . $ids['sfx']];
$j = static fn (array $ubah): array => array_replace($dasar, $ubah);
$namaKelas = $s->classes();
$nama = [];
foreach ($namaKelas as $row) {
    $nama[(int) $row['id']] = (string) $row['nama_kelas'];
}

echo "\n=== I-2 jadwal satu kelas dan dua kelas ===\n";
$satuKelas = $s->save($j(['id_kelas' => $k['C'], 'id_guru' => $ids['guru_h'], 'tempat' => 'Ruang C ' . $ids['sfx'], 'fan_ilmu' => 'Fiqih ' . $ids['sfx']]), $ids['admin_id']);
$S1 = (int) $satuKelas['id'];
$gabungan = $s->save($j(['id_kelas' => $k['PA'], 'kelas_gabungan' => [(string) $k['PI']]]), $ids['admin_id']);
$S2 = (int) $gabungan['id'];
$f1 = $s->find($S1);
$f2 = $s->find($S2);
$assert($f1['kelas_label'] === $nama[$k['C']] && $f1['kelas_ids'] === (string) $k['C'], 'Jadwal satu kelas: label dan ID kelas = kelas itu saja');
$assert($f2['kelas_label'] === $nama[$k['PA']] . ', ' . $nama[$k['PI']] && $f2['kelas_ids'] === $k['PA'] . ',' . $k['PI'] && (int) $f2['id_kelas'] === $k['PA'],
    'Jadwal gabungan: kedua kelas tampil, kelas utama lebih dahulu, id_kelas tetap kelas utama');
$assert($semua("SELECT kelas_id, is_utama FROM jadwal_kelas WHERE jadwal_id = $S2 ORDER BY is_utama DESC, kelas_id") === [['kelas_id' => (string) $k['PA'], 'is_utama' => '1'], ['kelas_id' => (string) $k['PI'], 'is_utama' => '0']],
    'Relasi jadwal_kelas tersimpan: PA utama, PI gabungan');
$assert($gabungan['warnings'] === [] && $satuKelas['warnings'] === [], 'Kelas-kelas di dalam satu jadwal gabungan tidak dianggap benturan');

echo "\n=== I-3 validasi pilihan kelas ===\n";
$assert($ditolak(fn () => $s->save($j(['id_kelas' => $k['PA'], 'kelas_gabungan' => [$ids['kelas_arsip']], 'hari' => 'Rabu']), $ids['admin_id']), 'kelas gabungan harus aktif'), 'Kelas gabungan yang diarsipkan ditolak');
$assert($ditolak(fn () => $s->save($j(['id_kelas' => $k['PA'], 'kelas_gabungan' => ['x1'], 'hari' => 'Rabu']), $ids['admin_id']), 'tidak valid'), 'Nilai kelas gabungan tidak valid ditolak');
$assert($ditolak(fn () => $s->save($j(['id_kelas' => $k['PA'], 'kelas_gabungan' => 'semua', 'hari' => 'Rabu']), $ids['admin_id']), 'tidak valid'), 'Kelas gabungan bukan larik ditolak');
$sebelas = range(900001, 900010);
$assert($ditolak(fn () => $s->save($j(['id_kelas' => $k['PA'], 'kelas_gabungan' => $sebelas, 'hari' => 'Rabu']), $ids['admin_id']), 'paling banyak'), 'Lebih dari 10 kelas dalam satu jadwal ditolak');
$sebelum = (int) $satu('SELECT COUNT(*) FROM jadwal_ngaji');
$assert((int) $satu('SELECT COUNT(*) FROM jadwal_ngaji') === $sebelum && (int) $satu("SELECT COUNT(*) FROM jadwal_ngaji WHERE hari = 'Rabu' AND fan_ilmu = " . "'Nahwu {$ids['sfx']}'") === 0, 'Simpan yang ditolak tidak meninggalkan jadwal (transaksi dibatalkan)');
$dup = $s->save($j(['id_kelas' => $k['PA'], 'kelas_gabungan' => [$k['PA'], $k['PA']], 'hari' => 'Rabu', 'fan_ilmu' => 'Dup ' . $ids['sfx']]), $ids['admin_id']);
$assert((int) $satu('SELECT COUNT(*) FROM jadwal_kelas WHERE jadwal_id = ' . (int) $dup['id']) === 1, 'Kelas utama yang ikut dicentang tidak tersimpan dua kali');
$s->setState((int) $dup['id'], 'archive', $ids['admin_id']);

echo "\n=== I-4 benturan ===\n";
$assert($ditolak(fn () => $s->save($j(['id_kelas' => $k['C'], 'waktu_mulai' => '20:30', 'waktu_selesai' => '21:00', 'tempat' => 'Lain ' . $ids['sfx']]), $ids['admin_id']), 'Bentrok guru'),
    'Guru yang sama pada jam beririsan ditolak');
$w = $s->save($j(['id_kelas' => $k['PI'], 'id_guru' => $ids['guru_i'], 'waktu_mulai' => '20:15', 'waktu_selesai' => '20:30', 'tempat' => 'Lain ' . $ids['sfx'], 'hari' => 'Kamis']), $ids['admin_id']);
$teks = implode(' ', $w['warnings']);
$assert($w['warnings'] !== [] && str_contains($teks, $nama[$k['PI']]) && str_contains($teks, '#' . $S2) && !str_contains($teks, $nama[$k['PA']]),
    'Kelas gabungan (PI) pada jadwal lain diperingatkan dengan menyebut kelas yang sama saja');
$s->setState((int) $w['id'], 'deactivate', $ids['admin_id']);
$w2 = $s->save($j(['id_kelas' => $k['C'], 'id_guru' => $ids['guru_h'], 'kelas_gabungan' => [$k['PA']], 'waktu_mulai' => '05:00', 'waktu_selesai' => '05:30', 'hari' => 'Jumat', 'tempat' => 'Ruang Pagi ' . $ids['sfx']]), $ids['admin_id']);
$w3 = $s->save($j(['id_kelas' => $k['PI'], 'id_guru' => $ids['guru_g'], 'waktu_mulai' => '05:10', 'waktu_selesai' => '05:20', 'hari' => 'Jumat', 'tempat' => 'Ruang Pagi ' . $ids['sfx']]), $ids['admin_id']);
$assert(count($w3['warnings']) === 1 && str_contains($w3['warnings'][0], 'tempat') && !str_contains($w3['warnings'][0], 'kelas '),
    'Tempat sama diperingatkan; kelas yang tidak beririsan dengan himpunan kelas jadwal lain bukan benturan kelas');
$w4 = $s->save($j(['id_kelas' => $k['PA'], 'id_guru' => $ids['guru_g'], 'waktu_mulai' => '05:25', 'waktu_selesai' => '05:40', 'hari' => 'Jumat', 'tempat' => 'Ruang Lain ' . $ids['sfx']]), $ids['admin_id']);
$assert(count($w4['warnings']) === 1 && str_contains($w4['warnings'][0], 'kelas ' . $nama[$k['PA']]), 'Kelas utama jadwal ini beririsan dengan kelas GABUNGAN jadwal lain → peringatan');
foreach ([$w2, $w3, $w4] as $x) {
    $s->setState((int) $x['id'], 'archive', $ids['admin_id']);
}
$ulang = $s->save($j(['id_kelas' => $k['PA'], 'kelas_gabungan' => [$k['PI']]]), $ids['admin_id'], $S2);
$assert($ulang['warnings'] === [], 'Menyimpan ulang jadwal gabungan tidak membentur dirinya sendiri');

echo "\n=== I-5 saringan kelas daftar jadwal ===\n";
$idsDaftar = static fn (array $f) => array_map(static fn (array $r): int => (int) $r['id'], $s->list($f + ['state' => 'active', 'year_id' => $ids['tahun']], 1, 100)['rows']);
$assert(in_array($S2, $idsDaftar(['class_id' => $k['PI']]), true) && in_array($S2, $idsDaftar(['class_id' => $k['PA']]), true), 'Saringan kelas PA maupun PI menemukan jadwal gabungan');
$assert(!in_array($S2, $idsDaftar(['class_id' => $k['C']]), true) && in_array($S1, $idsDaftar(['class_id' => $k['C']]), true), 'Saringan kelas C hanya menemukan jadwal C');
$opsi = array_column($s->activeScheduleOptions($admin), 'kelas_label', 'id');
$assert(($opsi[$S2] ?? '') === $f2['kelas_label'], 'Pilihan jadwal saat membuka pertemuan menampilkan seluruh kelas');

echo "\n=== I-6 satu pertemuan untuk kedua kelas ===\n";
$D1 = $tanggal(0);
$m1 = $s->open($S2, $D1, 'Pertemuan gabungan', $admin);
$p = $s->meeting($m1, $admin);
$peserta = array_map(static fn (array $r): array => [(int) $r['santri_id'], (int) $r['kelas_id_snapshot']], $p['participants']);
sort($peserta);
$harapan = [];
foreach (['PA', 'PI'] as $kk) {
    foreach ($ids['santri'][$kk] as $sid) {
        $harapan[] = [$sid, $k[$kk]];
    }
}
sort($harapan);
$assert($peserta === $harapan, 'Peserta = santri aktif PA (3) + PI (2) dengan kelas asal masing-masing');
$assert(!in_array($ids['santri_selesai'], array_column($p['participants'], 'santri_id'), false) && !array_intersect($ids['santri']['C'], array_map('intval', array_column($p['participants'], 'santri_id'))),
    'Keanggotaan yang sudah Selesai dan santri kelas lain tidak ikut');
$assert((int) $satu("SELECT COUNT(*) FROM pertemuan_pengajian WHERE jadwal_id = $S2 AND tanggal_pertemuan = '$D1'") === 1
    && (int) $satu("SELECT COUNT(*) - COUNT(DISTINCT santri_id) FROM pertemuan_peserta WHERE pertemuan_id = $m1") === 0,
    'Tepat satu pertemuan untuk tanggal itu; tidak ada peserta ganda');
$assert($semua("SELECT kelas_id, is_utama, sumber FROM pertemuan_kelas WHERE pertemuan_id = $m1 ORDER BY is_utama DESC") === [
    ['kelas_id' => (string) $k['PA'], 'is_utama' => '1', 'sumber' => 'Pembukaan'], ['kelas_id' => (string) $k['PI'], 'is_utama' => '0', 'sumber' => 'Pembukaan']],
    'Kelas pertemuan dibekukan saat dibuka (PA utama, PI)');
$assert($p['kelas_label'] === $f2['kelas_label'], 'Rincian pertemuan web menampilkan seluruh kelas');
$assert($ditolak(fn () => $s->open($S2, $D1, '', $admin)), 'Pembukaan kedua (web) untuk jadwal–tanggal sama ditolak');
$kodeApi = null;
try {
    $api->openMeeting($S2, ['date' => $D1, 'idempotency_key' => 'jkg-dup-' . $ids['sfx']], $guru);
} catch (ApiException $e) {
    $kodeApi = $e->errorCode();
}
$assert($kodeApi === 'MEETING_CONFLICT', 'Pembukaan kedua (API) untuk jadwal–tanggal sama → MEETING_CONFLICT');

echo "\n=== I-7 API: classes aditif ===\n";
$jadwalApi = $api->schedule($S2, $D1, $guru);
$assert($jadwalApi['class'] === ['id' => $k['PA'], 'name' => $nama[$k['PA']], 'level' => 'Uji'], 'Field `class` lama tetap kelas utama');
$assert(array_column($jadwalApi['classes'], 'id') === [$k['PA'], $k['PI']] && $jadwalApi['classes'][0]['is_primary'] === true && $jadwalApi['classes'][1]['is_primary'] === false,
    '`classes` memuat seluruh kelas jadwal, utama lebih dahulu');
$daftar = $api->schedules($guru, ['date_from' => $D1, 'date_to' => $D1]);
$item = array_values(array_filter($daftar['items'], static fn (array $x): bool => $x['id'] === $S2))[0] ?? null;
$assert($item !== null && count($item['classes']) === 2 && $item['meeting']['id'] === $m1, 'Daftar jadwal API memuat `classes` dan pertemuan yang sama');
$rinci = $api->meeting($m1, $guru);
$kelasSiswa = [];
foreach ($rinci['students'] as $st) {
    $kelasSiswa[$st['student_id']] = $st['class']['id'];
}
$assert(count($rinci['students']) === 5 && $kelasSiswa[$ids['santri']['PI'][0]] === $k['PI'] && $kelasSiswa[$ids['santri']['PA'][0]] === $k['PA'],
    'Rincian pertemuan API: lima santri dengan kelas asal');
$assert(array_column($rinci['task']['classes'], 'id') === [$k['PA'], $k['PI']] && $rinci['task']['class']['id'] === $k['PA'], 'Rincian pertemuan API: task.class lama + task.classes');
$ringkas = $api->meetings($guru, ['date_from' => $D1, 'date_to' => $D1]);
$ringkasM1 = array_values(array_filter($ringkas['items'], static fn (array $x): bool => $x['id'] === $m1))[0] ?? [];
$assert(($ringkasM1['class_name'] ?? '') === $nama[$k['PA']] && ($ringkasM1['class_names'] ?? '') === $f2['kelas_label'] && count($ringkasM1['classes'] ?? []) === 2,
    'Daftar pertemuan API: class_name lama, class_names + classes aditif');
$jadwalC = $api->schedule($S1, $D1, ['id' => $ids['admin_id'], 'roles' => ['admin'], 'guru_id' => null]);
$assert(count($jadwalC['classes']) === 1 && $jadwalC['classes'][0]['id'] === $k['C'], 'Jadwal satu kelas: `classes` berisi satu kelas');

echo "\n=== I-8 absensi tanpa duplikasi ===\n";
$isian = static function (array $rinci, string $status): array {
    return array_map(static fn (array $st): array => ['student_id' => $st['student_id'], 'status' => $status], $rinci['students']);
};
$api->saveAttendance($m1, ['idempotency_key' => 'jkg-abs-' . $ids['sfx'], 'teacher' => ['status' => 'Hadir'], 'students' => $isian($rinci, 'Hadir')], $guru);
$assert((int) $satu("SELECT COUNT(*) FROM absensi_guru WHERE pertemuan_id = $m1") === 1 && (int) $satu("SELECT COUNT(*) FROM absensi_santri WHERE pertemuan_id = $m1") === 5,
    'Satu absensi guru dan lima absensi santri untuk pertemuan gabungan');
$kirimKurang = $isian($rinci, 'Hadir');
array_pop($kirimKurang);
$kode = null;
try {
    $api->saveAttendance($m1, ['idempotency_key' => 'jkg-abs2-' . $ids['sfx'], 'teacher' => ['status' => 'Hadir'], 'students' => $kirimKurang, 'correction_reason' => 'uji'], $guru);
} catch (ApiException $e) {
    $kode = $e->errorCode();
}
$assert($kode === 'VALIDATION_FAILED', 'Absensi yang tidak memuat seluruh peserta dua kelas ditolak');

echo "\n=== I-9 perubahan kelas setelah pertemuan dibuka ===\n";
$ubah = $s->save($j(['id_kelas' => $k['PA'], 'kelas_gabungan' => [$k['C']], 'waktu_mulai' => '20:00']), $ids['admin_id'], $S2);
$assert($ubah['warnings'] !== [] && str_contains(implode(' ', $ubah['warnings']), $nama[$k['C']]), 'Mengganti PI dengan C memperingatkan benturan kelas C dengan jadwal C');
$p1 = $s->meeting($m1, $admin);
$assert($p1['kelas_label'] === $nama[$k['PA']] . ', ' . $nama[$k['PI']] && count($p1['participants']) === 5, 'Pertemuan yang sudah dibuka tetap berkelas PA + PI dengan lima peserta');
$assert(array_column($api->meeting($m1, $guru)['task']['classes'], 'id') === [$k['PA'], $k['PI']], 'API pertemuan lama tetap membaca kelas historis');
$r2 = $api->openMeeting($S2, ['date' => $tanggal(1), 'idempotency_key' => 'jkg-open2-' . $ids['sfx']], $guru)['data'];
$m2 = (int) $r2['id'];
$kelas2 = array_count_values(array_map(static fn (array $st): int => $st['class']['id'], $r2['students']));
ksort($kelas2);
$assert($kelas2 === [$k['PA'] => 3, $k['C'] => 2] && array_column($r2['task']['classes'], 'id') === [$k['PA'], $k['C']], 'Pertemuan berikutnya (dibuka lewat API) memakai kelas baru PA + C');
$api->saveAttendance($m2, ['idempotency_key' => 'jkg-abs3-' . $ids['sfx'], 'teacher' => ['status' => 'Terlambat'],
    'students' => array_map(static fn (array $st): array => ['student_id' => $st['student_id'], 'status' => $st['class']['id'] === $k['C'] ? 'Izin' : 'Hadir'], $r2['students'])], $guru);
// Kembalikan ke PA + PI untuk bagian berikutnya.
$s->save($j(['id_kelas' => $k['PA'], 'kelas_gabungan' => [$k['PI']]]), $ids['admin_id'], $S2);
$mS1 = $s->open($S1, $D1, '', $admin);
$rS1 = $api->meeting($mS1, ['id' => $ids['admin_id'], 'roles' => ['admin'], 'guru_id' => null]);
$api->saveAttendance($mS1, ['idempotency_key' => 'jkg-abs4-' . $ids['sfx'], 'teacher' => ['status' => 'Hadir'], 'students' => $isian($rS1, 'Alpa')], ['id' => $ids['admin_id'], 'roles' => ['admin'], 'guru_id' => null]);

echo "\n=== I-10 laporan dan ekspor ===\n";
$rentang = ['date_from' => $D1, 'date_to' => $tanggal(1), 'per_page' => 100];
$lap = static fn (array $f, string $scope = 'gabungan') => $laporan->report($f + $rentang + ['schedule_id' => $S2], $admin, $scope);
$g = $lap([]);
$assert($g['summary']['meeting_count'] === 2 && $g['summary']['teacher_attendance_count'] === 2 && $g['summary']['student_attendance_count'] === 10,
    'Gabungan jadwal S2: 2 pertemuan, 2 absensi guru (tidak ganda), 10 absensi santri (5 + 5)');
$assert(count($g['schedules']) === 1 && $g['schedules'][0]['meeting_count'] === 2 && $g['schedules'][0]['detail_count'] === 12,
    'Ringkasan per jadwal satu baris untuk jadwal gabungan, tidak terpecah per kelas');
$assert(str_contains($g['schedules'][0]['class']['name'], $nama[$k['PI']]) && str_contains($g['schedules'][0]['class']['name'], $nama[$k['C']]),
    'Ringkasan jadwal menyebut kelas historis tiap pertemuan (PA+PI dan PA+C)');
$perKelas = [];
foreach (['PA', 'PI', 'C'] as $kk) {
    $r = $lap(['class_id' => $k[$kk]], 'santri');
    $perKelas[$kk] = $r;
    $kelasBaris = array_unique(array_map(static fn (array $i): int => $i['class_id'], $r['items']));
    $assert($kelasBaris === [] || $kelasBaris === [$k[$kk]], "Laporan kelas $kk hanya memuat santri kelas $kk");
}
$assert($perKelas['PA']['summary']['student_attendance_count'] === 6 && $perKelas['PI']['summary']['student_attendance_count'] === 2 && $perKelas['C']['summary']['student_attendance_count'] === 2,
    'Per kelas: PA 6 (2 pertemuan × 3), PI 2 (pertemuan pertama), C 2 (pertemuan kedua)');
$assert($perKelas['PA']['summary']['student_attendance_count'] + $perKelas['PI']['summary']['student_attendance_count'] + $perKelas['C']['summary']['student_attendance_count'] === $g['summary']['student_attendance_count'],
    'Jumlah laporan per kelas = laporan gabungan (tiap santri tepat sekali)');
$guruPI = $lap(['class_id' => $k['PI']], 'guru');
$guruPA = $lap(['class_id' => $k['PA']], 'guru');
$assert($guruPI['summary']['teacher_attendance_count'] === 1 && $guruPA['summary']['teacher_attendance_count'] === 2, 'Absensi guru per kelas = satu per pertemuan yang mencakup kelas itu');
$csv = $laporan->exportCsvRows($rentang + ['schedule_id' => $S2, 'subject_scope' => 'gabungan'], $admin);
$csvGuru = array_values(array_filter($csv['items'], static fn (array $i): bool => $i['subject_type'] === 'Guru'));
$csvPI = array_values(array_filter($csv['items'], static fn (array $i): bool => $i['subject_id'] === $ids['santri']['PI'][0]));
$assert(count($csv['items']) === 12 && count($csvGuru) === 2 && ($csvPI[0]['class_name'] ?? '') === $nama[$k['PI']], 'Ekspor: 12 baris (2 guru + 10 santri), kelas santri = kelas asal');
$assert(str_contains($csvGuru[0]['class_name'], ', '), 'Baris guru di ekspor memuat seluruh kelas pertemuan');
$opsiLap = $laporan->options($admin);
$assert(in_array($k['PI'], array_column($opsiLap['classes'], 'id'), true) && in_array($k['C'], array_column($opsiLap['classes'], 'id'), true), 'Pilihan saringan kelas laporan memuat kelas gabungan');
$rm = $laporan->meeting($m1, $admin);
$assert(count($rm['task']['classes']) === 2 && $rm['students'][0]['class']['id'] > 0 && $rm['student_summary']['participant_count'] === 5, 'Rincian laporan pertemuan: kelas pertemuan + kelas asal santri');
$satuK = $laporan->report($rentang + ['schedule_id' => $S1], $admin, 'gabungan');
$assert($satuK['summary']['teacher_attendance_count'] === 1 && $satuK['summary']['student_attendance_count'] === 2 && $satuK['schedules'][0]['class']['name'] === $nama[$k['C']],
    'Jadwal satu kelas: laporan tidak berubah bentuk');

echo "\n=== I-11 rekap kehadiran rapor ===\n";
$verif = new VerifikasiService($db, audit_logger());
$plan = ['tahun_ajaran_id' => $ids['tahun']];
$pi = $verif->attendance($plan, $ids['santri']['PI'][0]);
$pa = $verif->attendance($plan, $ids['santri']['PA'][0]);
$c = $verif->attendance($plan, $ids['santri']['C'][0]);
$assert($pi['jumlah_pertemuan'] === 1 && $pi['hitungan']['Hadir'] === 1, 'Rapor santri PI: pertemuan gabungan dihitung tepat sekali');
$assert($pa['jumlah_pertemuan'] === 2 && $pa['hitungan']['Hadir'] === 2, 'Rapor santri PA: dua pertemuan gabungan, masing-masing sekali');
$assert($c['jumlah_pertemuan'] === 2 && $c['hitungan']['Izin'] === 1 && $c['hitungan']['Alpa'] === 1, 'Rapor santri C: satu dari jadwal gabungan + satu dari jadwal C');
$belumPI = (int) $satu("SELECT COUNT(*) FROM pertemuan_peserta pp JOIN pertemuan_pengajian p ON p.id = pp.pertemuan_id AND p.status = 'Selesai'
    LEFT JOIN absensi_santri a ON a.pertemuan_id = pp.pertemuan_id AND a.santri_id = pp.santri_id WHERE pp.kelas_id_snapshot = {$k['PI']} AND a.id IS NULL");
$assert($belumPI === 0 && (int) $satu("SELECT COUNT(*) FROM pertemuan_peserta WHERE kelas_id_snapshot = {$k['PI']}") === 2, 'Rekap kehadiran per kelas (kelas_id_snapshot) memuat santri kelas gabungan');

echo "\n=== I-12 dua pembukaan bersamaan ===\n";
$pekerja = static function (array $tugas) use ($root, $dbName): array {
    $proses = [];
    $mulai = microtime(true) + 1.5;
    foreach ($tugas as $i => [$mode, $arg]) {
        $arg['mulai'] = $mulai;
        $proses[$i] = proc_open(['php', $root . '/tests/jadwal_kelas_gabungan_f1_worker.php', $mode, json_encode($arg)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipa, $root, array_merge(getenv(), ['DB_NAME' => $dbName]));
        $proses[$i] = [$proses[$i], $pipa];
    }
    $hasil = [];
    foreach ($proses as $i => [$h, $pipa]) {
        $out = stream_get_contents($pipa[1]);
        $err = stream_get_contents($pipa[2]);
        proc_close($h);
        $hasil[$i] = json_decode(trim((string) $out), true) ?? ['ok' => false, 'pesan' => trim($out . $err)];
    }
    return $hasil;
};
foreach ([2, 3] as $minggu) {
    $D = $tanggal($minggu);
    $hasil = $pekerja([
        ['web-open', ['jadwal' => $S2, 'tanggal' => $D, 'user' => $admin]],
        ['api-open', ['jadwal' => $S2, 'tanggal' => $D, 'user' => $guru, 'kunci' => 'jkg-race-' . $minggu . $ids['sfx']]],
        ['web-open', ['jadwal' => $S2, 'tanggal' => $D, 'user' => $admin]],
    ]);
    $ok = array_values(array_filter($hasil, static fn (array $h): bool => $h['ok'] === true));
    $tolak = array_values(array_filter($hasil, static fn (array $h): bool => $h['ok'] === false));
    $bersih = $tolak !== [] && array_reduce($tolak, static fn (bool $c, array $h): bool => $c && in_array($h['jenis'] ?? '', [ScheduleException::class, ApiException::class], true), true);
    $mid = (int) $satu("SELECT id FROM pertemuan_pengajian WHERE jadwal_id = $S2 AND tanggal_pertemuan = '$D'");
    $assert(count($ok) === 1 && $bersih && (int) $satu("SELECT COUNT(*) FROM pertemuan_pengajian WHERE jadwal_id = $S2 AND tanggal_pertemuan = '$D'") === 1,
        "Minggu +$minggu: tiga pembukaan bersamaan → satu berhasil, dua ditolak rapi, satu pertemuan (" . json_encode(array_map(static fn ($h) => $h['ok'] ? 'ok' : ($h['pesan'] ?? '?'), $hasil)) . ')');
    $assert((int) $satu("SELECT COUNT(*) FROM pertemuan_peserta WHERE pertemuan_id = $mid") === 5 && (int) $satu("SELECT COUNT(*) FROM pertemuan_kelas WHERE pertemuan_id = $mid") === 2,
        "Minggu +$minggu: peserta 5 tanpa ganda dan dua kelas beku");
}

echo "\n=== I-13 ubah kelas bersamaan dengan pembukaan ===\n";
$konsisten = true;
$catatan = [];
foreach ([4, 5, 6, 7] as $minggu) {
    $D = $tanggal($minggu);
    $kelasBaru = $minggu % 2 === 0 ? [$k['C']] : [$k['PI']];
    $hasil = $pekerja([
        ['save', ['jadwal' => $S2, 'aktor' => $ids['admin_id'], 'input' => $j(['id_kelas' => $k['PA'], 'kelas_gabungan' => $kelasBaru])]],
        ['web-open', ['jadwal' => $S2, 'tanggal' => $D, 'user' => $admin]],
    ]);
    $mid = (int) $satu("SELECT id FROM pertemuan_pengajian WHERE jadwal_id = $S2 AND tanggal_pertemuan = '$D'");
    $kelasBeku = array_map('intval', array_column($semua("SELECT kelas_id FROM pertemuan_kelas WHERE pertemuan_id = $mid ORDER BY kelas_id"), 'kelas_id'));
    $kelasPeserta = array_map('intval', array_column($semua("SELECT DISTINCT kelas_id_snapshot FROM pertemuan_peserta WHERE pertemuan_id = $mid ORDER BY kelas_id_snapshot"), 'kelas_id_snapshot'));
    $sah = [[$k['PA'], $k['PI']], [$k['PA'], $k['C']]];
    foreach ($sah as &$x) { sort($x); }
    unset($x);
    $baik = $hasil[0]['ok'] && $hasil[1]['ok'] && in_array($kelasBeku, $sah, true) && $kelasBeku === $kelasPeserta;
    $konsisten = $konsisten && $baik;
    $catatan[] = $minggu . ':' . implode('+', $kelasBeku);
}
$assert($konsisten, 'Empat putaran ubah-kelas ∥ buka: kelas beku = kelas peserta = salah satu keadaan utuh (' . implode(' ', $catatan) . ')');
$s->save($j(['id_kelas' => $k['PA'], 'kelas_gabungan' => [$k['PI']]]), $ids['admin_id'], $S2);

echo "\n=== I-14 audit ===\n";
$audit = $semua("SELECT before_json, after_json FROM audit_logs WHERE action = 'schedule.update' AND entity_id = $S2 ORDER BY id");
$jejak = array_map(static fn (array $a): string => (string) (json_decode((string) $a['before_json'], true)['kelas_ids'] ?? '') . '→' . (string) (json_decode((string) $a['after_json'], true)['kelas_ids'] ?? ''), $audit);
$assert(in_array($k['PA'] . ',' . $k['PI'] . '→' . $k['PA'] . ',' . $k['C'], $jejak, true), 'Audit schedule.update mencatat kelas sebelum dan sesudah');

echo "\n=== I-15 preservasi data lama ===\n";
$akhir = $sidikLama();
foreach ($awal as $tabel => $nilai) {
    $assert($akhir[$tabel] === $nilai, "Baris lama $tabel tidak berubah ($nilai)");
}
$assert((int) $satu('SELECT COUNT(*) FROM pertemuan_pengajian GROUP BY jadwal_id, tanggal_pertemuan HAVING COUNT(*) > 1') === 0
    && (int) $satu('SELECT COUNT(*) FROM (SELECT 1 FROM absensi_santri GROUP BY pertemuan_id, santri_id HAVING COUNT(*) > 1) x') === 0
    && (int) $satu('SELECT COUNT(*) FROM (SELECT 1 FROM absensi_guru GROUP BY pertemuan_id HAVING COUNT(*) > 1) x') === 0,
    'Tidak ada pertemuan ganda per jadwal–tanggal, absensi santri ganda, atau lebih dari satu absensi guru per pertemuan');

echo "\nHasil: " . $lulus . ' lulus, ' . count($gagal) . " gagal\n";
foreach ($gagal as $pesan) {
    echo '  - ' . $pesan . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
