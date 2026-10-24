<?php

declare(strict_types=1);

/**
 * Probe auditor Fase 1: pencabutan master/role pada objek pengguna lama,
 * kegagalan transaksi/audit akses, dan pencabutan yang bertabrakan dengan
 * penyimpanan. Hanya fixture sintetis V5 pada DB test_*_test.
 *
 * V5F1_DB=test_codex_v5_f1_test V5_RUN_AUDIT=1 php tests/v5_phase1_audit.php
 * Jalankan sesudah suite integrasi V5 (petugas pertama sudah ditugaskan).
 */
if (PHP_SAPI !== 'cli' || getenv('V5_RUN_AUDIT') !== '1'
    || !preg_match('/^test_.*_test$/D', getenv('V5F1_DB') ?: '')) {
    exit(2);
}
putenv('DB_NAME=' . getenv('V5F1_DB'));
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Kesehatan\KesehatanException;

$db = app_db();
$svc = kesehatan_service();
$ok = 0;
$fail = 0;
$check = static function (bool $pass, string $label) use (&$ok, &$fail): void {
    $pass ? $ok++ : $fail++;
    echo ($pass ? '[lulus] ' : '[gagal] ') . $label . PHP_EOL;
};
$sql = static function (string $query) use ($db): mixed {
    $result = $db->query($query);
    if ($result === false) {
        throw new RuntimeException('Query probe gagal: ' . $db->error);
    }
    return $result;
};
$val = static fn (string $query): mixed => $sql($query)->fetch_row()[0];
$uid = (int) $val("SELECT id FROM users WHERE username='sbx_v5_petugas'");
$pid = (int) $val("SELECT pengurus_id FROM users WHERE id=$uid");
$sid = (int) $val("SELECT id FROM santri WHERE nis='V5S0001'");
$aid = (int) $val("SELECT id FROM kesehatan_assignments WHERE pengurus_id=$pid AND is_active=1 LIMIT 1");
$tid = (int) $val("SELECT tahun_ajaran_id FROM kesehatan_assignments WHERE id=$aid");
$rid = (int) $val("SELECT id FROM roles WHERE slug='pengurus'");
$adminRole = (int) $val("SELECT id FROM roles WHERE slug='admin'");
if (min($uid, $pid, $sid, $aid, $tid, $rid, $adminRole) < 1) {
    throw new RuntimeException('Jalankan fixture dan suite integrasi V5 lebih dahulu.');
}
$user = ['id' => $uid, 'roles' => ['admin', 'pengurus']]; // role klien sengaja palsu
$body = [
    'santri_id' => $sid, 'waktu_kunjungan' => date('Y-m-d H:i', strtotime('-1 hour')),
    'keluhan' => 'AUDIT-PRIVAT-KELUHAN', 'catatan_klinis' => 'AUDIT-PRIVAT-CATATAN',
    'tindakan' => 'AUDIT-PRIVAT-TINDAKAN',
];
$attempt = static function (array $input) use ($svc, $user): int {
    try {
        return $svc->buat($user, $input, 'api')['status'];
    } catch (KesehatanException $e) {
        return $e->status;
    }
};
$counts = static fn (): array => array_map(static fn (string $table): int => (int) $val('SELECT COUNT(*) FROM ' . $table),
    ['kesehatan_kunjungan', 'kesehatan_kunjungan_versi', 'kesehatan_idempotensi', 'audit_logs']);
$check($svc->akses($user)['catat'], 'Role masukan tidak mengganti role database');

echo "=== A. Pencabutan pada objek pengguna lama ===\n";
foreach ([
    'akun nonaktif' => "UPDATE users SET is_active=0 WHERE id=$uid",
    'master nonaktif' => "UPDATE pengurus SET is_active=0 WHERE id=$pid",
    'master diarsipkan' => "UPDATE pengurus SET archived_at=NOW() WHERE id=$pid",
    'role pengurus dicabut' => "DELETE FROM user_roles WHERE user_id=$uid AND role_id=$rid",
    'akun menjadi admin' => "INSERT INTO user_roles(user_id,role_id) VALUES($uid,$adminRole)",
    'tahun diarsipkan' => "UPDATE tahun_ajaran SET archived_at=NOW() WHERE id=$tid",
    'penugasan belum mulai' => "UPDATE kesehatan_assignments SET tanggal_mulai=DATE_ADD(CURDATE(),INTERVAL 1 DAY) WHERE id=$aid",
] as $label => $query) {
    $before = $counts();
    $db->begin_transaction();
    try {
        $sql($query);
        $access = $svc->akses($user);
        $status = $attempt($body + ['idempotency_key' => 'audit-revoke-' . bin2hex(random_bytes(8))]);
        $check(!$access['catat'] && $status === 403 && $counts() === $before, "$label: tulis ditolak, tidak ada kunjungan/versi/klaim/audit baru");
        if ($label !== 'akun menjadi admin') {
            $check(!$access['baca'], "$label: baca juga dicabut");
        } else {
            $check($access['baca'] && $access['riwayat_akses'], 'Promosi admin menyisakan baca/audit');
        }
    } finally {
        $db->rollback();
    }
}
// Klinik sepanjang tahun; pergantian semester tidak mencabut petugas.
$db->begin_transaction();
try {
    $sql("UPDATE tahun_ajaran SET status='Non-Aktif' WHERE id=$tid");
    $check($svc->akses($user)['catat'], 'Tahun tidak aktif tanpa arsip tetap memberi hak klinik (P-2)');
} finally {
    $db->rollback();
}

echo "=== B. Kegagalan parsial dan privasi ===\n";
foreach ([
    'snapshot' => ['kesehatan_kunjungan_versi', ''],
    'audit' => ['audit_logs', "IF NEW.action='kesehatan.kunjungan.dicatat' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Uji audit wajib gagal'; END IF;"],
] as $label => [$table, $condition]) {
    $trigger = 'codex_v5_fail_' . $label;
    $before = $counts();
    $sql("CREATE TRIGGER $trigger BEFORE INSERT ON $table FOR EACH ROW BEGIN "
        . ($condition ?: "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Uji snapshot gagal';") . ' END');
    $key = 'audit-fail-' . bin2hex(random_bytes(8));
    try {
        $check($attempt($body + ['idempotency_key' => $key]) === 503 && $counts() === $before,
            "Gagal $label: 503 dan seluruh transaksi dibatalkan");
    } finally {
        $sql("DROP TRIGGER $trigger");
    }
    $check($attempt($body + ['idempotency_key' => $key]) === 201,
        "Retry sesudah kegagalan $label menerima kunci yang sama");
}
$visit = (int) $val("SELECT MAX(id) FROM kesehatan_kunjungan WHERE created_by=$uid");
$accessBefore = (int) $val('SELECT COUNT(*) FROM kesehatan_akses_log');
$sql("CREATE TRIGGER codex_v5_fail_read BEFORE INSERT ON kesehatan_akses_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Uji log akses gagal'");
try {
    $returned = false;
    $status = null;
    try {
        $svc->detail($user, $visit, 'api');
        $returned = true;
    } catch (KesehatanException $e) {
        $status = $e->status;
    }
    $check(!$returned && $status === 503 && (int) $val('SELECT COUNT(*) FROM kesehatan_akses_log') === $accessBefore,
        'Kegagalan log akses menutup rincian (503)');
} finally {
    $sql('DROP TRIGGER codex_v5_fail_read');
}
$check($svc->detail($user, $visit, 'api')['keluhan'] === $body['keluhan'], 'Detail kembali tersedia sesudah log akses pulih');
$check((int) $val("SELECT COUNT(*) FROM audit_logs WHERE CONCAT(COALESCE(before_json,''),COALESCE(after_json,'')) LIKE '%AUDIT-PRIVAT-%'") === 0,
    'Kegagalan/retry tidak menulis isi klinis ke audit umum');

echo "=== C. Pencabutan bersamaan dengan penyimpanan ===\n";
$before = $counts();
$hold = new mysqli((string) app_config('database.host'), (string) app_config('database.username'),
    (string) app_config('database.password'), (string) app_config('database.database'), (int) app_config('database.port'));
$hold->begin_transaction();
$hold->query("UPDATE kesehatan_assignments SET is_active=0 WHERE id=$aid");
$gate = tempnam(sys_get_temp_dir(), 'codex-v5-gate-');
$process = proc_open(['php', __DIR__ . '/v5_phase1_worker.php', (string) $uid, (string) $sid,
    'audit-race-' . bin2hex(random_bytes(8)), 'AUDIT-PRIVAT-RACE', $body['waktu_kunjungan'], $gate],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
try {
    $waiting = false;
    $limit = microtime(true) + 10;
    do {
        // Hanya membaca statistik koneksi pada DB uji sendiri.
        $waiting = (int) $val("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID<>CONNECTION_ID() AND DB=DATABASE() AND INFO LIKE '%LOCK IN SHARE MODE%'") > 0;
        if (!$waiting) {
            usleep(100000);
        }
    } while (!$waiting && microtime(true) < $limit);
    $check($waiting, 'Pekerja benar-benar menunggu kunci pencabutan sebelum commit');
    $hold->commit();
    $result = json_decode((string) stream_get_contents($pipes[1]), true);
    $error = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $check($exit === 0 && $error === '' && ($result['status'] ?? null) === 403,
        'Pekerja membaca pencabutan terbaru sesudah menunggu: 403');
    $check($counts() === $before, 'Balapan pencabutan: 0 kunjungan/versi/klaim/audit baru');
    $check($attempt($body + ['idempotency_key' => 'audit-post-race-' . bin2hex(random_bytes(8))]) === 403,
        'Objek pengguna lama tetap ditolak sesudah balapan');
} finally {
    $hold->rollback();
    $hold->query("UPDATE kesehatan_assignments SET is_active=1 WHERE id=$aid");
    $hold->close();
    unlink($gate);
}
echo "Lulus: $ok · Gagal: $fail\n";
exit($fail === 0 ? 0 : 1);
