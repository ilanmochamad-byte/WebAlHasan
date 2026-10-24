<?php

declare(strict_types=1);

/** Probe independen auditor: rincian majemuk, kegagalan atomik, dan pembacaan
 * yang bersilangan dengan koreksi. Hanya fixture sintetis test_*_test.
 * V5F2_DB=test_codex_v5_f2_test V5_RUN_AUDIT=1 php tests/v5_phase2_audit.php
 */
if (PHP_SAPI !== 'cli' || getenv('V5_RUN_AUDIT') !== '1'
    || !preg_match('/^test_.*_test$/D', getenv('V5F2_DB') ?: '')) {
    exit(2);
}
putenv('DB_NAME=' . getenv('V5F2_DB'));
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Kesehatan\KesehatanException;

if (($argv[1] ?? '') === '--read') {
    $u = ['id' => (int) $argv[2], 'roles' => []];
    $svc = kesehatan_service();
    echo json_encode($argv[4] === 'detail' ? $svc->detail($u, (int) $argv[3], 'api') : $svc->riwayatVersi($u, (int) $argv[3], 'api'));
    exit;
}

$db = app_db();
$svc = kesehatan_service();
$ok = 0;
$fail = 0;
$check = static function (bool $pass, string $label) use (&$ok, &$fail): void {
    $pass ? $ok++ : $fail++;
    echo ($pass ? '[lulus] ' : '[gagal] ') . $label . PHP_EOL;
};
$sql = static function (string $q) use ($db): mixed {
    $r = $db->query($q);
    if ($r === false) throw new RuntimeException($db->error);
    return $r;
};
$val = static fn (string $q): mixed => $sql($q)->fetch_row()[0];
$user = static fn (string $name): array => ['id' => (int) $val("SELECT id FROM users WHERE username='$name'"), 'roles' => []];
$petugas = $user('sbx_v5_petugas_b');
$pembimbing = $user('sbx_v5_pembimbing');
$sid = (int) $val("SELECT id FROM santri WHERE nis='V5S0101'");
if ($petugas['id'] < 1 || $sid < 1) throw new RuntimeException('Fixture Fase 2 belum tersedia');
$key = static fn (): string => 'audit-f2-' . bin2hex(random_bytes(8));
$body = [
    'santri_id' => $sid, 'waktu_kunjungan' => date('Y-m-d H:i:s', strtotime('-2 hours')),
    'keluhan' => 'AUDIT-F2-PRIVAT-AWAL', 'catatan_klinis' => 'AUDIT-F2-PRIVAT-CATATAN',
    'obat' => [['nama_obat' => 'AUDIT-F2-OBAT-A', 'jumlah' => '0,25'], ['nama_obat' => 'AUDIT-F2-OBAT-B', 'jumlah' => '3']],
    'rujukan' => [['tujuan' => 'AUDIT-F2-FASILITAS-A'], ['tujuan' => 'AUDIT-F2-FASILITAS-B']],
    'tindak_lanjut' => [['uraian' => 'AUDIT-F2-KONTROL-A'], ['uraian' => 'AUDIT-F2-KONTROL-B']],
];
$id = $svc->buat($petugas, $body + ['idempotency_key' => $key()], 'api')['id'];
$state = static fn (array $d): array => array_intersect_key($d, array_flip(['status', 'waktu_kunjungan', 'keluhan', 'catatan_klinis', 'tindakan', 'obat', 'rujukan', 'tindak_lanjut']));
$counts = static fn (): array => array_map(static fn (string $t): int => (int) $val("SELECT COUNT(*) FROM $t"),
    ['kesehatan_kunjungan', 'kesehatan_kunjungan_versi', 'kesehatan_obat', 'kesehatan_rujukan', 'kesehatan_tindak_lanjut', 'kesehatan_versi_rincian', 'kesehatan_idempotensi', 'audit_logs']);
$attempt = static function (callable $work): int {
    try { $work(); return 200; } catch (KesehatanException $e) { return $e->status; }
};
$d = $svc->detail($petugas, $id, 'web');
$list = $svc->daftar($pembimbing, ['santri_id' => $sid]);
$rows = array_values(array_filter($list['rows'], static fn (array $r): bool => $r['id'] === $id));
$check(count($rows) === 1 && $rows[0]['penanda']['obat'] === 2 && $rows[0]['penanda']['rujukan'] === 2 && $rows[0]['penanda']['tindak_lanjut'] === 2, 'Dua obat, dua rujukan, dua tindak lanjut tetap satu baris daftar');
$check(count($d['obat']) === 2 && count($d['rujukan']) === 2 && count($d['tindak_lanjut']) === 2 && $d['obat'][0]['jumlah'] === '0.25', 'Detail majemuk dan jumlah desimal dipertahankan');
$r = $svc->hasilRujukan($petugas, $id, $d['rujukan'][1]['id'], ['versi' => 1, 'alasan' => 'Hasil kedua diterima', 'hasil' => 'AUDIT-F2-HASIL-B', 'idempotency_key' => $key()], 'api');
$d = $svc->detail($petugas, $id, 'api');
$check($r['versi'] === 2 && $d['rujukan'][0]['hasil'] === null && $d['rujukan'][1]['hasil'] === 'AUDIT-F2-HASIL-B', 'Hasil rujukan kedua tidak mengubah rujukan pertama');
$input = $state($d);
$input['tindak_lanjut'][0]['status'] = 'Selesai';
$input['tindak_lanjut'][0]['tanggal_selesai'] = date('Y-m-d');
$input['versi'] = 2;
$input['idempotency_key'] = $key();
$before = $counts();
$check($attempt(static fn () => $svc->koreksi($petugas, $id, $input, 'api')) === 422 && $counts() === $before, 'Selesai tindak lanjut tanpa alasan ditolak atomik');
$input['alasan'] = 'Kontrol pertama sudah selesai';
$svc->koreksi($petugas, $id, $input, 'api');
$d = $svc->detail($pembimbing, $id, 'api');
$check($d['versi'] === 3 && $d['tindak_lanjut'][0]['status'] === 'Selesai' && $d['tindak_lanjut'][1]['status'] === 'Terbuka', 'Satu tindak lanjut selesai, yang lain tetap terbuka pada kunjungan sama');
$history = $svc->riwayatVersi($petugas, $id, 'api');
$changes = array_values(array_filter($history['versi'][0]['perubahan'], static fn (array $p): bool => $p['bagian'] === 'tindak_lanjut' && $p['kolom'] === 'status'));
$check(count($changes) === 1 && $changes[0]['sebelum'] === 'Terbuka' && $changes[0]['sesudah'] === 'Selesai', 'Riwayat sebelum/sesudah tindak lanjut utuh');

echo "=== Kegagalan parsial koreksi ===\n";
foreach (['kesehatan_rujukan' => 'UPDATE', 'kesehatan_versi_rincian' => 'INSERT', 'audit_logs' => 'INSERT'] as $table => $event) {
    $d = $svc->detail($petugas, $id, 'api');
    $input = $state($d) + ['versi' => $d['versi'], 'alasan' => 'Probe transaksi wajib atomik', 'idempotency_key' => $key()];
    $input['obat'][0]['nama_obat'] = 'AUDIT-F2-TIDAK-BOLEH-TERSIMPAN';
    $input['rujukan'][0]['alasan'] = 'AUDIT-F2-GAGAL';
    $before = $counts();
    $sql("CREATE TRIGGER audit_v5f2_fail BEFORE $event ON $table FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic failure'");
    try {
        $check($attempt(static fn () => $svc->koreksi($petugas, $id, $input, 'api')) === 503, "$table gagal: koreksi ditutup 503");
    } finally { $sql('DROP TRIGGER IF EXISTS audit_v5f2_fail'); }
    $after = $svc->detail($petugas, $id, 'api');
    $check($counts() === $before && $state($after) === $state($d) && $after['versi'] === $d['versi'], "$table gagal: isi, rincian, versi, snapshot, klaim, audit kembali utuh");
}

echo "=== Pembacaan bersilangan koreksi ===\n";
foreach (['detail', 'riwayat'] as $mode) {
    $d = $svc->detail($petugas, $id, 'api');
    $input = $state($d) + ['versi' => $d['versi'], 'alasan' => 'Koreksi saat pembaca tertahan', 'idempotency_key' => $key()];
    $input['keluhan'] = 'AUDIT-F2-BARU-' . $mode;
    $input['obat'][0]['nama_obat'] = 'AUDIT-F2-OBAT-BARU-' . $mode;
    $input['rujukan'][0]['tujuan'] = 'AUDIT-F2-RUJUKAN-BARU-' . $mode;
    $input['tindak_lanjut'][1]['uraian'] = 'AUDIT-F2-TL-BARU-' . $mode;
    $gate = 'audit-v5f2-' . bin2hex(random_bytes(4));
    $sql("SELECT GET_LOCK('$gate', 5)");
    $sql("CREATE TRIGGER audit_v5f2_gate BEFORE INSERT ON kesehatan_akses_log FOR EACH ROW BEGIN DECLARE x INT; SET x=GET_LOCK('$gate',15); SET x=RELEASE_LOCK('$gate'); END");
    $proc = proc_open(['php', __FILE__, '--read', (string) $petugas['id'], (string) $id, $mode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    try {
        $waiting = false;
        for ($i = 0; $i < 100; $i++) {
            if ((int) $val("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE STATE LIKE '%User lock%' AND INFO LIKE '%kesehatan_akses_log%'") > 0) { $waiting = true; break; }
            usleep(30000);
        }
        $check($waiting, "$mode: pembaca benar-benar tertahan sesudah membaca kepala kunjungan");
        $svc->koreksi($petugas, $id, $input, 'api');
    } finally { $sql("SELECT RELEASE_LOCK('$gate')"); }
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    $exit = proc_close($proc);
    $sql('DROP TRIGGER IF EXISTS audit_v5f2_gate');
    $read = json_decode((string) $out, true);
    $check($exit === 0 && $err === '' && is_array($read), "$mode: respons pembaca berhasil");
    if ($mode === 'detail') {
        $check($read['versi'] === $d['versi'] && $state($read) === $state($d), 'Detail memakai satu versi utuh meskipun koreksi masuk di tengah pembacaan');
    } else {
        $check($read['kunjungan']['versi'] === $d['versi'] && max(array_column($read['versi'], 'versi')) === $d['versi'], 'Kepala riwayat dan versi terbarunya konsisten saat koreksi bersamaan');
    }
}
$sql('DROP TRIGGER IF EXISTS audit_v5f2_gate');

echo "=== Log akses wajib dan pencabutan bersamaan koreksi ===\n";
$sql("CREATE TRIGGER audit_v5f2_fail BEFORE INSERT ON kesehatan_akses_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic read failure'");
try {
    $check($attempt(static fn () => $svc->detail($pembimbing, $id, 'api')) === 503, 'Log akses gagal: detail pembimbing ditutup');
    $check($attempt(static fn () => $svc->riwayatVersi($petugas, $id, 'api')) === 503, 'Log akses gagal: riwayat versi ditutup');
} finally { $sql('DROP TRIGGER IF EXISTS audit_v5f2_fail'); }

$d = $svc->detail($petugas, $id, 'api');
$input = $state($d) + ['versi' => $d['versi'], 'alasan' => 'Koreksi bersamaan pencabutan', 'idempotency_key' => $key()];
$input['keluhan'] = 'AUDIT-F2-REVOKE-TIDAK-TERSIMPAN';
$before = $counts();
$aid = (int) $val("SELECT id FROM kesehatan_assignments WHERE pengurus_id=(SELECT pengurus_id FROM users WHERE id={$petugas['id']}) AND is_active=1 LIMIT 1");
$hold = new mysqli((string) app_config('database.host'), (string) app_config('database.username'),
    (string) app_config('database.password'), (string) app_config('database.database'), (int) app_config('database.port'));
$hold->begin_transaction();
$hold->query("UPDATE kesehatan_assignments SET is_active=0 WHERE id=$aid");
$tmp = tempnam(sys_get_temp_dir(), 'audit-v5f2-input-');
$gate = tempnam(sys_get_temp_dir(), 'audit-v5f2-start-');
file_put_contents($tmp, json_encode($input));
$proc = proc_open(['php', __DIR__ . '/v5_phase2_worker.php', (string) $petugas['id'], (string) $id, $tmp, $gate], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
try {
    $waiting = false;
    for ($i = 0; $i < 100; $i++) {
        if ((int) $val("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID<>CONNECTION_ID() AND DB=DATABASE() AND INFO LIKE '%LOCK IN SHARE MODE%'") > 0) { $waiting = true; break; }
        usleep(30000);
    }
    $check($waiting, 'Koreksi benar-benar menunggu kunci penugasan yang sedang dicabut');
    $hold->commit();
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    $exit = proc_close($proc);
    $r = json_decode((string) $out, true);
    $check($exit === 0 && $err === '' && ($r['status'] ?? null) === 403, 'Sesudah commit pencabutan, koreksi tertahan ditolak 403');
    $check($counts() === $before, 'Pencabutan bersamaan: tidak ada perubahan kunjungan/rincian/versi/klaim/audit');
    $check($attempt(static fn () => $svc->koreksi($petugas, $id, $input, 'api')) === 403, 'Objek pengguna lama tetap ditolak sesudah pencabutan');
} finally {
    $hold->rollback();
    $hold->query("UPDATE kesehatan_assignments SET is_active=1 WHERE id=$aid");
    $hold->close();
    unlink($tmp);
    unlink($gate);
}
$check((int) $val("SELECT COUNT(*) FROM audit_logs WHERE CONCAT(COALESCE(before_json,''),COALESCE(after_json,'')) LIKE '%AUDIT-F2-%'") === 0, 'Probe kegagalan dan koreksi tidak menyalin isi privat ke audit umum');

echo "=== Perubahan peran menjadi admin saat koreksi menunggu ===\n";
$d = $svc->detail($petugas, $id, 'api');
$input = $state($d) + ['versi' => $d['versi'], 'alasan' => 'Uji promosi admin bersamaan', 'idempotency_key' => $key()];
$input['keluhan'] = 'AUDIT-F2-ADMIN-TIDAK-TERSIMPAN';
$before = $counts();
$adminRole = (int) $val("SELECT id FROM roles WHERE slug='admin'");
$hold = new mysqli((string) app_config('database.host'), (string) app_config('database.username'),
    (string) app_config('database.password'), (string) app_config('database.database'), (int) app_config('database.port'));
$hold->begin_transaction();
// Pemeriksaan capability nonlocking masih melihat role lama, tetapi
// pemeriksaan hak di dalam transaksi harus melihat admin sesudah commit.
$hold->query("UPDATE users SET name=name WHERE id={$petugas['id']}");
$hold->query("INSERT INTO user_roles(user_id,role_id) VALUES({$petugas['id']},$adminRole)");
$tmp = tempnam(sys_get_temp_dir(), 'audit-v5f2-admin-');
$gate = tempnam(sys_get_temp_dir(), 'audit-v5f2-start-');
file_put_contents($tmp, json_encode($input));
$proc = proc_open(['php', __DIR__ . '/v5_phase2_worker.php', (string) $petugas['id'], (string) $id, $tmp, $gate], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
try {
    $waiting = false;
    for ($i = 0; $i < 100; $i++) {
        if ((int) $val("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID<>CONNECTION_ID() AND DB=DATABASE() AND INFO LIKE '%LOCK IN SHARE MODE%'") > 0) { $waiting = true; break; }
        usleep(30000);
    }
    $check($waiting, 'Koreksi menunggu kunci akun saat role admin sedang ditambahkan');
    $hold->commit();
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    $exit = proc_close($proc);
    $r = json_decode((string) $out, true);
    $check($exit === 0 && $err === '' && ($r['status'] ?? null) === 403, 'Akun yang kini admin ditolak menulis klinis sesudah menunggu');
    $check($counts() === $before, 'Promosi admin bersamaan tidak menghasilkan versi/klaim/audit klinis');
} finally {
    $hold->rollback();
    $hold->query("DELETE FROM user_roles WHERE user_id={$petugas['id']} AND role_id=$adminRole");
    $hold->close();
    unlink($tmp);
    unlink($gate);
}
echo "Lulus: $ok · Gagal: $fail\n";
exit($fail > 0 ? 1 : 0);
