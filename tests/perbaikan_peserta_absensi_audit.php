<?php

declare(strict_types=1);

/**
 * Probe auditor: kegagalan audit wajib membatalkan pembaruan peserta (web/API)
 * serta penonaktifan santri; retry dan status 403/404/409 tetap benar.
 *
 *   JKG_DB=test_ppa_codex_test bash tests/jadwal_kelas_gabungan_f1_db.sh
 *   PPA_DB=test_ppa_codex_test php tests/perbaikan_peserta_absensi_audit.php
 *
 * Bangun ulang DB sebelum mengulang (jadwal fixture memakai jam tetap).
 */

use App\Api\ApiException;
use App\Schedule\ScheduleException;

$root = dirname(__DIR__);
$dbName = getenv('PPA_DB') ?: 'test_ppa_codex_test';
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
$kolom = static function (string $sql) use ($db): array {
    $r = $db->query($sql);
    if ($r === false) {
        throw new RuntimeException($db->error . ' :: ' . $sql);
    }
    $ids = array_map(static fn (array $x): int => (int) $x[0], $r->fetch_all());
    sort($ids);
    return $ids;
};
$galat = static function (callable $aksi): ?Throwable {
    try {
        $aksi();
        return null;
    } catch (ScheduleException|ApiException $e) {
        return $e;
    }
};
$ids = require __DIR__ . '/jadwal_kelas_gabungan_f1_seed.php';
$k = $ids['kelas'];
$sn = $ids['santri'];
$admin = ['id' => $ids['admin_id'], 'roles' => ['admin'], 'guru_id' => null, 'name' => 'Admin uji'];
$guru = ['id' => $ids['guru_user_id'], 'roles' => ['guru'], 'guru_id' => $ids['guru_g'], 'name' => 'Guru uji'];
$guruLain = ['id' => $ids['admin_id'], 'roles' => ['guru'], 'guru_id' => $ids['guru_h'], 'name' => 'Guru lain'];
$s = schedule_service();
$api = teacher_api_service();
$master = master_data_service();
$tanggal = static fn (int $minggu): string => (new DateTimeImmutable('next thursday'))->modify('+' . (7 * $minggu) . ' days')->format('Y-m-d');
$dasar = ['id_tahun' => $ids['tahun'], 'waktu_sholat' => "Ba'da Isya", 'hari' => 'Kamis', 'waktu_mulai' => '20:00', 'waktu_selesai' => '20:45',
    'fan_ilmu' => 'Nahwu ' . $ids['sfx'], 'nama_kitab' => 'Jurumiyah', 'id_guru' => $ids['guru_g'], 'tempat' => 'Ruang ' . $ids['sfx']];
$peserta = static fn (int $pid): array => $kolom("SELECT santri_id FROM pertemuan_peserta WHERE pertemuan_id = $pid");
$aktifDi = static fn (int $sid): array => $kolom("SELECT id FROM plotting_kelas WHERE id_santri = $sid AND status = 'Aktif'");
$jadwal = (int) $s->save(array_replace($dasar, ['id_kelas' => $k['C'], 'waktu_mulai' => '02:10', 'waktu_selesai' => '02:40']), $ids['admin_id'])['id'];
$meetings = [
    'web' => $s->open($jadwal, $tanggal(5), '', $admin),
    'api' => $s->open($jadwal, $tanggal(6), '', $admin),
];
$m = $meetings['api'];
$keluar = $sn['C'][0];
$master->endActiveClass($keluar);
$awal = $peserta($m);
$db->query("CREATE TRIGGER ppa_audit_fail BEFORE INSERT ON audit_logs FOR EACH ROW BEGIN IF NEW.action IN ('meeting.refresh_participants', 'api.meeting.refresh_participants', 'master.deactivate') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit failure fixture'; END IF; END");
try {
    foreach (['web' => fn () => $s->refreshParticipants($meetings['web'], $admin), 'api' => fn () => $api->refreshParticipants($meetings['api'], $guru)] as $nama => $aksi) {
        $e = null;
        try { $aksi(); } catch (Throwable $x) { $e = $x; }
        $assert($e !== null, "$nama menolak perubahan ketika audit gagal");
        $assert($peserta($meetings[$nama]) === $awal, "$nama rollback peserta ketika audit gagal");
    }
    $sid = $sn['C'][1];
    $plot = $aktifDi($sid);
    $e = null;
    try { $master->setSantriState($sid, 'deactivate'); } catch (Throwable $x) { $e = $x; }
    $assert($e !== null && (int) $satu("SELECT is_active FROM santri WHERE id=$sid") === 1, 'Status santri rollback ketika audit gagal');
    $assert($aktifDi($sid) === $plot, 'Penempatan rollback ketika audit gagal');
} finally { $db->query('DROP TRIGGER ppa_audit_fail'); }
$r = $api->refreshParticipants($m, $guru);
$assert($r['participant_changes']['removed'] === [$keluar], 'Retry setelah audit pulih benar-benar menghapus peserta');
$assert((int) $satu("SELECT COUNT(*) FROM audit_logs WHERE entity_id=$m AND action='api.meeting.refresh_participants'") === 1, 'Retry menghasilkan satu audit');
foreach ([['id' => 999999999, 'user' => $guru, 'status' => 404], ['id' => $m, 'user' => $guruLain, 'status' => 403]] as $kasus) {
    $e = $galat(fn () => $api->refreshParticipants($kasus['id'], $kasus['user']));
    $assert($e instanceof ApiException && $e->status() === $kasus['status'], 'Status API tepat ' . $kasus['status']);
}
$s->complete($m, $admin);
$e = $galat(fn () => $api->refreshParticipants($m, $guru));
$assert($e instanceof ApiException && $e->errorCode() === 'MEETING_NOT_OPEN' && $e->status() === 409, 'Selesai ditolak 409 MEETING_NOT_OPEN');
echo "Hasil: $lulus lulus, " . count($gagal) . " gagal\n";
exit($gagal === [] ? 0 : 1);
