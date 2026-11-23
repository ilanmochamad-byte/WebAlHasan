<?php

declare(strict_types=1);

/**
 * Uji integrasi status "Izin" otomatis pada absensi pengajian (migrasi 036,
 * keputusan Human Developer 23 November 2026, IP-1…IP-6) lewat layanan resmi
 * web (ScheduleService) dan REST (TeacherService), fixture sintetis:
 *
 *   JKG_DB=test_iop_test bash tests/jadwal_kelas_gabungan_f1_db.sh
 *   IOP_DB=test_iop_test php tests/izin_otomatis_pengajian_integration.php
 *
 *   K-1 buka pertemuan (web dan REST) membekukan usulan + keterangan IP-3;
 *       murobi & admin pengganti; asal Pengurus & Wali; batas tgl_izin/tgl_kembali
 *       inklusif; tumpang tindih → ID terbesar
 *   K-2 status lain, di luar rentang, santri lain, warisan tanpa keputusan → tanpa usulan
 *   K-3 perbarui: isi/kosongkan peserta belum berabsensi, berabsensi & Selesai
 *       tidak berubah, ulangan tanpa perubahan, audit hanya jumlah; dua urutan
 *       kunci perbarui ↔ simpan absensi (proses terpisah)
 *   K-4 payload REST `izin` aditif; tanpa alasan/catatan/keputusan/nama pengaju
 *       di payload, audit, dan log server
 *   K-5 (server) status tidak dipaksa: Hadir tersimpan sebagai Hadir
 *   K-6 rekap kehadiran rapor V4 dan laporan menghitung status tersimpan
 *
 * Mengubah data uji; bangun ulang DB sebelum mengulang.
 */

use App\Api\ApiException;
use App\Schedule\ScheduleException;
use App\V4\VerifikasiService;

$root = dirname(__DIR__);
$dbName = getenv('IOP_DB') ?: 'test_iop_test';
if (!preg_match('/^test_.*_test$/', $dbName)) {
    fwrite(STDERR, "DB uji harus bernama test_*_test.\n");
    exit(2);
}
putenv('DB_NAME=' . $dbName);
require_once $root . '/app/bootstrap.php';

if (($argv[1] ?? '') === '--worker') {
    $input = json_decode((string) file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
    echo "ready\n";
    flush();
    try {
        if ($input['operation'] === 'refresh') {
            teacher_api_service()->refreshParticipants($input['meeting'], $input['user']);
        } else {
            teacher_api_service()->saveAttendance($input['meeting'], $input['payload'], $input['user']);
        }
        echo "200\n";
    } catch (ApiException $e) {
        echo $e->status() . ' ' . $e->errorCode() . "\n";
    }
    exit;
}

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
$baris = static function (string $sql) use ($db): array {
    $r = $db->query($sql);
    if ($r === false) {
        throw new RuntimeException($db->error . ' :: ' . $sql);
    }
    return $r->fetch_all(MYSQLI_ASSOC);
};

$ids = require __DIR__ . '/jadwal_kelas_gabungan_f1_seed.php';
$k = $ids['kelas'];
$sn = $ids['santri'];
$sfx = $ids['sfx'];
$admin = ['id' => $ids['admin_id'], 'roles' => ['admin'], 'guru_id' => null, 'name' => 'Admin uji'];
$guru = ['id' => $ids['guru_user_id'], 'roles' => ['guru'], 'guru_id' => $ids['guru_g'], 'name' => 'Guru uji'];
$s = schedule_service();
$api = teacher_api_service();
$RAHASIA = 'RAHASIA-ALASAN-' . $sfx;
$waliId = (int) $satu('SELECT id FROM wali ORDER BY id LIMIT 1');
$pengurusId = (int) $satu('SELECT id FROM pengurus ORDER BY id LIMIT 1');
$assert($waliId > 0 && $pengurusId > 0, 'Fixture: ada wali dan pengurus untuk pengajuan sintetis');

$bulan = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
$dm = static fn (string $ymd): string => (int) substr($ymd, 8, 2) . ' ' . $bulan[(int) substr($ymd, 5, 2)];
$geser = static fn (string $ymd, int $hari): string => (new DateTimeImmutable($ymd))->modify(($hari >= 0 ? '+' : '') . $hari . ' days')->format('Y-m-d');

/**
 * Pengajuan sintetis. $kapasitas null = tanpa baris keputusan (mis. warisan).
 * Alasan, catatan, dan alasan keputusan berisi penanda RAHASIA yang tidak boleh bocor.
 */
$izin = static function (int $santri, string $dari, string $sampai, string $status, ?string $kapasitas = 'Murobi', string $asal = 'Pengurus', string $hasil = 'Disetujui', int $legacy = 0)
    use ($db, $waliId, $pengurusId, $RAHASIA, $ids): int {
    $wali = $asal === 'Wali' ? $waliId : null;
    $pengurus = $asal === 'Pengurus' ? $pengurusId : null;
    $st = $db->prepare("INSERT INTO izin_pengajuan (santri_id, pengurus_id, diajukan_oleh_user_id, murobi_guru_id, tahun_ajaran_id, tgl_izin, tgl_kembali, alasan, catatan_pengurus, catatan_wali, status, asal_pengajuan, wali_id, is_legacy, diajukan_pada)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    $alasan = $RAHASIA . ' alasan';
    $catatan = $RAHASIA . ' catatan';
    $st->execute([$santri, $pengurus, $ids['admin_id'], $ids['guru_h'], $ids['tahun'], $dari, $sampai, $alasan, $catatan, $asal === 'Wali' ? $catatan : null, $status, $asal, $wali, $legacy]);
    $id = (int) $db->insert_id;
    if ($kapasitas !== null) {
        $st = $db->prepare('INSERT INTO izin_keputusan (pengajuan_id, hasil, alasan, diputus_oleh_user_id, kapasitas, alasan_penggantian, diputus_pada) VALUES (?, ?, ?, ?, ?, ?, NOW())');
        $st->execute([$id, $hasil, $RAHASIA . ' keputusan', $ids['admin_id'], $kapasitas, $kapasitas === 'Admin Pengganti' ? $RAHASIA . ' pengganti' : null]);
    }
    return $id;
};
$usulan = static function (int $pertemuan) use ($baris): array {
    $hasil = [];
    foreach ($baris("SELECT santri_id, izin_pengajuan_id, izin_keterangan FROM pertemuan_peserta WHERE pertemuan_id = $pertemuan") as $r) {
        $hasil[(int) $r['santri_id']] = $r['izin_pengajuan_id'] === null ? null : ['id' => (int) $r['izin_pengajuan_id'], 'ket' => (string) $r['izin_keterangan']];
    }
    return $hasil;
};

$D = (new DateTimeImmutable('next thursday'))->modify('+21 days')->format('Y-m-d');
$dasar = ['id_tahun' => $ids['tahun'], 'waktu_sholat' => "Ba'da Isya", 'hari' => 'Kamis', 'waktu_mulai' => '20:00', 'waktu_selesai' => '20:45',
    'fan_ilmu' => 'Izin otomatis ' . $sfx, 'nama_kitab' => 'Jurumiyah', 'id_guru' => $ids['guru_g'], 'tempat' => 'Ruang ' . $sfx];
$SG = (int) $s->save(array_replace($dasar, ['id_kelas' => $k['PA'], 'kelas_gabungan' => [(string) $k['PI']]]), $ids['admin_id'])['id'];
$SC = (int) $s->save(array_replace($dasar, ['id_kelas' => $k['C'], 'waktu_mulai' => '21:00', 'waktu_selesai' => '21:45']), $ids['admin_id'])['id'];

[$pa0, $pa1, $pa2] = $sn['PA'];
[$pi0, $pi1] = $sn['PI'];
[$c0, $c1] = $sn['C'];

echo "=== K-1 / K-2 buka pertemuan (web) ===\n";
$iPa0 = $izin($pa0, $D, $geser($D, 3), 'Disetujui', 'Murobi', 'Pengurus');               // mulai = tanggal pertemuan
$iPa1 = $izin($pa1, $geser($D, -2), $D, 'Disetujui', 'Admin Pengganti', 'Wali');          // kembali = tanggal pertemuan
$iPa2a = $izin($pa2, $geser($D, -5), $geser($D, 5), 'Disetujui', 'Murobi');
$iPa2b = $izin($pa2, $geser($D, -1), $geser($D, 1), 'Disetujui', 'Murobi', 'Wali');       // tumpang tindih, ID terbesar
// Negatif untuk PI0/PI1 (K-2)
$izin($pi0, $D, $D, 'Diajukan', null);
$izin($pi0, $D, $D, 'Perlu Penetapan Admin', null);
$izin($pi0, $D, $D, 'Menunggu Pembimbing', null, 'Wali');
$izin($pi0, $D, $D, 'Perlu Penetapan Pembimbing', null, 'Wali');
$izin($pi0, $D, $D, 'Perlu Perbaikan Wali', null, 'Wali');
$izin($pi0, $D, $D, 'Ditolak', 'Murobi', 'Pengurus', 'Ditolak');
$izin($pi0, $D, $D, 'Ditolak Pembimbing', null, 'Wali');
$izin($pi0, $D, $D, 'Dibatalkan', null);
$izin($pi1, $geser($D, 1), $geser($D, 4), 'Disetujui', 'Murobi');                         // sesudah tanggal
$izin($pi1, $geser($D, -4), $geser($D, -1), 'Disetujui', 'Murobi');                       // sebelum tanggal
$izin($pi1, $D, $D, 'Disetujui', null, 'Pengurus', 'Disetujui', 1);                       // warisan tanpa keputusan
$izin($c0, $D, $D, 'Disetujui', 'Murobi');                                                 // santri lain (bukan peserta SG)

$mWeb = $s->open($SG, $D, '', $admin);
$u = $usulan($mWeb);
$assert(($u[$pa0]['id'] ?? null) === $iPa0 && $u[$pa0]['ket'] === "Izin No. $iPa0 — disetujui murobi s.d. " . $dm($geser($D, 3)),
    'K-1 web: izin murobi (asal Pengurus) mulai tepat pada tanggal pertemuan → keterangan persis IP-3');
$assert(($u[$pa1]['id'] ?? null) === $iPa1 && $u[$pa1]['ket'] === "Izin No. $iPa1 — disetujui admin pengganti s.d. " . $dm($D),
    'K-1 web: izin admin pengganti (asal Wali) berakhir tepat pada tanggal pertemuan → "admin pengganti"');
$assert(($u[$pa2]['id'] ?? null) === max($iPa2a, $iPa2b), 'K-1: izin tumpang tindih memakai ID terbesar');
$assert(array_key_exists($pi0, $u) && $u[$pi0] === null, 'K-2: Diajukan/Perlu Penetapan Admin/tahap pembimbing V7/Ditolak/Ditolak Pembimbing/Dibatalkan → tanpa usulan');
$assert(array_key_exists($pi1, $u) && $u[$pi1] === null, 'K-2: di luar rentang dan warisan tanpa keputusan → tanpa usulan');
$assert(!array_key_exists($c0, $u) && count($u) === 5, 'K-2: izin santri lain tidak menambah atau memengaruhi peserta');
$assert((int) $satu("SELECT COUNT(*) FROM absensi_santri WHERE pertemuan_id = $mWeb") === 0, 'Prinsip §3.1: pembukaan tidak menulis baris absensi');
$assert(!str_contains(json_encode($baris("SELECT * FROM pertemuan_peserta WHERE pertemuan_id = $mWeb"), JSON_UNESCAPED_UNICODE), $RAHASIA),
    'K-4: kolom peserta tidak memuat alasan/catatan/keputusan izin');

echo "\n=== K-1 / K-4 buka pertemuan (REST) ===\n";
$D2 = $geser($D, 7);
$iC0 = $izin($c0, $geser($D2, -1), $geser($D2, 2), 'Disetujui', 'Murobi', 'Wali');
$respBuka = $api->openMeeting($SC, ['date' => $D2, 'idempotency_key' => 'iop-open-' . $sfx], $guru)['data'];
$byId = [];
foreach ($respBuka['students'] as $st) {
    $byId[(int) $st['student_id']] = $st;
}
$assert(($byId[$c0]['izin'] ?? null) === ['pengajuan_id' => $iC0, 'keterangan' => "Izin No. $iC0 — disetujui murobi s.d. " . $dm($geser($D2, 2)), 'sampai' => $geser($D2, 2)],
    'K-4 REST buka: kunci aditif `izin` {pengajuan_id, keterangan, sampai} persis');
$assert(array_key_exists('izin', $byId[$c1]) && $byId[$c1]['izin'] === null, 'K-4 REST: santri tanpa izin → `izin: null`');
$assert($byId[$c0]['attendance'] === null && array_keys($byId[$c0]) === ['student_id', 'nis', 'name', 'class', 'attendance', 'izin'],
    'K-4: kunci lama tetap, `izin` hanya ditambahkan di akhir; belum ada absensi');
$json = json_encode($respBuka, JSON_UNESCAPED_UNICODE);
$assert(!str_contains($json, $RAHASIA) && !str_contains($json, 'alasan'), 'K-4: payload REST tanpa alasan/catatan/keputusan');
$mApi = (int) $respBuka['id'];
$detail = $api->meeting($mApi, $guru);
$assert(($detail['students'][0]['izin'] ?? 'x') !== 'x' && array_column($detail['students'], 'izin', 'student_id')[$c0]['pengajuan_id'] === $iC0, 'K-4 REST rincian GET /meetings/{id} memuat `izin` yang sama');

echo "\n=== K-5 (server) status tidak dipaksa; K-6 rekap ===\n";
$simpan = $api->saveAttendance($mApi, ['idempotency_key' => 'iop-save-' . $sfx, 'teacher' => ['status' => 'Hadir'],
    'students' => [['student_id' => $c0, 'status' => 'Hadir'], ['student_id' => $c1, 'status' => 'Hadir']]], $guru)['data'];
$assert((string) $satu("SELECT status FROM absensi_santri WHERE pertemuan_id = $mApi AND santri_id = $c0") === 'Hadir',
    'K-5: guru mengubah santri izin menjadi Hadir → tersimpan Hadir (server tidak memaksa Izin)');
$assert(array_column($simpan['students'], 'izin', 'student_id')[$c0]['pengajuan_id'] === $iC0, 'Respons simpan tetap memuat `izin` (usulan beku tidak dihapus)');
$audit = (string) $satu("SELECT CONCAT_WS('|', before_json, after_json) FROM audit_logs WHERE entity_type = 'pertemuan_pengajian' AND entity_id = $mApi ORDER BY id DESC LIMIT 1");
$assert(!str_contains($audit, $RAHASIA), 'K-4: audit simpan absensi tanpa isi izin');

echo "\n=== K-3 perbarui daftar peserta (web) ===\n";
// PA0: izin dibatalkan sesudah dibuka → dikosongkan. PI1: izin baru disetujui → diisi.
// PA1: sudah berabsensi lalu izinnya dibatalkan → tidak berubah.
$db->query("UPDATE izin_pengajuan SET status = 'Dibatalkan' WHERE id = $iPa0");
$db->query("UPDATE izin_pengajuan SET status = 'Dibatalkan' WHERE id = $iPa1");
$db->query("INSERT INTO absensi_santri (pertemuan_id, santri_id, status, dicatat_pada, dicatat_oleh, created_at, updated_at) VALUES ($mWeb, $pa1, 'Izin', NOW(), {$ids['admin_id']}, NOW(), NOW())");
$iPi1 = $izin($pi1, $D, $geser($D, 1), 'Disetujui', 'Admin Pengganti', 'Pengurus');
$sebelumPa1 = $usulan($mWeb)[$pa1];
$hasil = $s->refreshParticipants($mWeb, $admin);
$u = $usulan($mWeb);
$assert($u[$pa0] === null, 'K-3: izin dibatalkan → usulan peserta belum berabsensi dikosongkan');
$assert(($u[$pi1]['id'] ?? null) === $iPi1 && $u[$pi1]['ket'] === "Izin No. $iPi1 — disetujui admin pengganti s.d. " . $dm($geser($D, 1)), 'K-3: izin baru disetujui → usulan diisi');
$assert($u[$pa1] === $sebelumPa1, 'K-3: peserta yang sudah berabsensi tidak diubah walau izinnya dibatalkan');
$assert(($u[$pa2]['id'] ?? null) === max($iPa2a, $iPa2b), 'K-3: usulan yang tetap berlaku tidak berubah');
$assert(($hasil['izin_diisi'] ?? null) === 1 && ($hasil['izin_dikosongkan'] ?? null) === 1, 'K-3: hasil perbarui memuat jumlah usulan diisi/dikosongkan');
$auditRefresh = (string) $satu("SELECT after_json FROM audit_logs WHERE action = 'meeting.refresh_participants' AND entity_id = $mWeb ORDER BY id DESC LIMIT 1");
$assert(str_contains($auditRefresh, '"izin_diisi":1') && !str_contains($auditRefresh, $RAHASIA) && !str_contains($auditRefresh, 'Izin No.'), 'K-3/K-4: audit perbarui hanya jumlah, tanpa isi/keterangan izin');
$ulang = $s->refreshParticipants($mWeb, $admin);
$assert(($ulang['izin_diisi'] ?? null) === 0 && ($ulang['izin_dikosongkan'] ?? null) === 0 && $usulan($mWeb) === $u, 'K-3: perbarui ulang tanpa perubahan');

echo "\n=== K-3 perbarui (REST) dan pertemuan Selesai ===\n";
$iPa0b = $izin($pa0, $D, $D, 'Disetujui', 'Murobi', 'Wali');
$resp = $api->refreshParticipants($mWeb, $guru);
$assert(($resp['participant_changes']['izin_suggested'] ?? null) === 1 && ($resp['participant_changes']['izin_cleared'] ?? null) === 0, 'K-3 REST: participant_changes memuat izin_suggested/izin_cleared');
$assert(array_column($resp['students'], 'izin', 'student_id')[$pa0]['pengajuan_id'] === $iPa0b, 'K-3 REST: respons perbarui memuat usulan terbaru');
$sesudahSelesai = $usulan($mApi);
$db->query("UPDATE izin_pengajuan SET status = 'Dibatalkan' WHERE id = $iC0");
$e = null;
try {
    $api->refreshParticipants($mApi, $guru);
} catch (ApiException $x) {
    $e = $x;
}
$assert($e !== null && $e->status() === 409 && $usulan($mApi) === $sesudahSelesai, 'K-3: pertemuan Selesai tidak diperbarui (409) dan usulannya tetap');
$e = null;
try {
    $s->refreshParticipants($mApi, $admin);
} catch (ScheduleException $x) {
    $e = $x;
}
$assert($e !== null && $usulan($mApi) === $sesudahSelesai, 'K-3 web: pertemuan Selesai ditolak, usulan tetap');

echo "\n=== K-3 dua urutan kunci perbarui ↔ simpan absensi (proses terpisah) ===\n";
$start = static function (array $input) use ($assert): array {
    $file = tempnam(sys_get_temp_dir(), 'iop-worker-');
    file_put_contents($file, json_encode($input, JSON_THROW_ON_ERROR));
    $proc = proc_open([PHP_BINARY, __FILE__, '--worker', $file], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    stream_set_timeout($pipes[1], 15);
    $assert(trim((string) fgets($pipes[1])) === 'ready', 'Proses kedua siap');
    usleep(300000);
    $assert(proc_get_status($proc)['running'], 'Proses kedua menunggu kunci pertemuan');
    return [$proc, $pipes, $file];
};
$finish = static function (array $w): string {
    [$proc, $pipes, $file] = $w;
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    foreach ($pipes as $p) {
        fclose($p);
    }
    proc_close($proc);
    unlink($file);
    return trim($out);
};
// Urutan A: simpan lebih dahulu (santri izin disimpan Hadir), perbarui menunggu lalu 409.
$D3 = $geser($D, 14);
$iC1 = $izin($c1, $D3, $D3, 'Disetujui', 'Murobi');
$m3 = $s->open($SC, $D3, '', $admin);
$assert(($usulan($m3)[$c1]['id'] ?? null) === $iC1, 'Fixture urutan A: usulan terisi saat dibuka');
$worker = null;
$api->saveAttendance($m3, ['idempotency_key' => 'iop-konk-a-' . $sfx, 'teacher' => ['status' => 'Hadir'],
    'students' => [['student_id' => $c0, 'status' => 'Hadir'], ['student_id' => $c1, 'status' => 'Hadir']]], $admin,
    function () use (&$worker, $start, $m3, $admin): void {
        $worker = $start(['operation' => 'refresh', 'meeting' => $m3, 'user' => $admin]);
    });
$assert($finish($worker) === '409 MEETING_NOT_OPEN', 'Urutan A: perbarui menunggu lalu ditolak 409 sesudah simpan');
$assert((string) $satu("SELECT status FROM absensi_santri WHERE pertemuan_id = $m3 AND santri_id = $c1") === 'Hadir' && ($usulan($m3)[$c1]['id'] ?? null) === $iC1,
    'Urutan A: status guru (Hadir) tersimpan; usulan beku tidak berubah');
// Urutan B: perbarui lebih dahulu (mengisi usulan izin baru), simpan menunggu lalu 200 dengan status Izin.
$D4 = $geser($D, 28);
$m4 = $s->open($SC, $D4, '', $admin);
$assert($usulan($m4)[$c0] === null, 'Fixture urutan B: belum ada usulan saat dibuka');
$iC0b = $izin($c0, $D4, $geser($D4, 1), 'Disetujui', 'Murobi');
$db->begin_transaction();
try {
    $db->query("SELECT id FROM pertemuan_pengajian WHERE id = $m4 FOR UPDATE");
    $r = App\Schedule\PesertaPertemuan::perbarui($db, $m4, $k['C'], $ids['tahun']);
    $worker = $start(['operation' => 'save', 'meeting' => $m4, 'user' => $admin, 'payload' => ['idempotency_key' => 'iop-konk-b-' . $sfx, 'teacher' => ['status' => 'Hadir'],
        'students' => [['student_id' => $c0, 'status' => 'Izin', 'notes' => "Izin No. $iC0b — disetujui murobi s.d. " . $dm($geser($D4, 1))], ['student_id' => $c1, 'status' => 'Hadir']]]]);
    $db->commit();
} catch (Throwable $x) {
    $db->rollback();
    throw $x;
}
$assert($finish($worker) === '200' && ($r['izin_diisi'] ?? null) === 1, 'Urutan B: simpan menunggu perbarui, lalu 200');
$assert((string) $satu("SELECT status FROM absensi_santri WHERE pertemuan_id = $m4 AND santri_id = $c0") === 'Izin' && ($usulan($m4)[$c0]['id'] ?? null) === $iC0b,
    'Urutan B: status Izin tersimpan dan usulan tetap');

echo "\n=== K-6 rekap kehadiran rapor V4 dan laporan ===\n";
$ver = new VerifikasiService($db, audit_logger());
$rekapC0 = $ver->attendance(['tahun_ajaran_id' => $ids['tahun']], $c0)['hitungan'];
$rekapC1 = $ver->attendance(['tahun_ajaran_id' => $ids['tahun']], $c1)['hitungan'];
// c0: D2 Hadir (diubah guru), D3 Hadir, D4 Izin. c1: D2 Hadir, D3 Hadir (diubah guru), D4 Hadir.
$assert($rekapC0['Izin'] === 1 && $rekapC0['Hadir'] === 2, 'K-6 rapor V4: Izin hasil usulan yang disimpan terhitung Izin; yang diubah guru terhitung Hadir');
$assert($rekapC1['Izin'] === 0 && $rekapC1['Hadir'] === 3, 'K-6 rapor V4: santri yang usulannya diubah guru ke Hadir terhitung Hadir');
$lap = report_service()->exportRows(['date_from' => $D4, 'date_to' => $D4, 'subject_scope' => 'santri', 'schedule_id' => $SC], $admin);
$statusLap = [];
foreach ($lap['items'] as $row) {
    if ($row['subject_type'] === 'Santri') {
        $statusLap[$row['subject_id']] = $row['attendance_status'];
    }
}
$assert(($statusLap[$c0] ?? null) === 'Izin' && ($statusLap[$c1] ?? null) === 'Hadir', 'K-6 laporan: baris santri izin berstatus Izin, santri lain Hadir');
$rincian = report_service()->meeting($m4, $admin);
$assert(($rincian['student_summary']['statuses']['Izin'] ?? null) === 1 && array_column($rincian['students'], 'izin', 'student_id')[$c0]['pengajuan_id'] === $iC0b,
    'K-6 rincian laporan: hitungan Izin 1 dan keterangan usulan tersedia');

echo "\n=== K-4 log server tanpa isi izin ===\n";
$log = '';
foreach ([ini_get('error_log'), $root . '/storage/logs/app.log'] as $berkas) {
    if (is_string($berkas) && $berkas !== '' && is_file($berkas)) {
        $log .= (string) file_get_contents($berkas);
    }
}
$assert(!str_contains($log, $RAHASIA), 'K-4: log PHP/aplikasi tidak memuat alasan izin');
$assert((int) $satu("SELECT COUNT(*) FROM audit_logs WHERE CONCAT_WS('|', before_json, after_json) LIKE '%" . $db->real_escape_string($RAHASIA) . "%'") === 0, 'K-4: tidak ada audit yang memuat isi izin');

echo "\nHasil: $lulus lulus, " . count($gagal) . " gagal\n";
exit($gagal === [] ? 0 : 1);
