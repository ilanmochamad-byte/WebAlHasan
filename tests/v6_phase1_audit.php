<?php

declare(strict_types=1);

/** Probe independen Codex: jalankan sesudah seed V6 pada DB uji baru.
 * V6F1_DB=test_v6_codex_probe_test V6_RUN_AUDIT=1 php tests/v6_phase1_audit.php
 * Memakai sesi/token sebelum penyempitan cakupan dan penonaktifan santri.
 * Tidak mencetak token, cookie, atau isi data keuangan ke log.
 */
if (getenv('V6_RUN_AUDIT') !== '1' || !preg_match('/^test_.*_test$/D', getenv('V6F1_DB') ?: '')) {
    exit(2);
}
putenv('DB_NAME=' . getenv('V6F1_DB'));
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Bsa\BsaException;

$db = app_db();
$scalar = static fn (string $sql): mixed => $db->query($sql)->fetch_row()[0];
$exec = static function (string $sql) use ($db): void {
    if (!$db->query($sql)) throw new RuntimeException($db->error);
};
$ok = 0;
$bad = 0;
$check = static function (bool $pass, string $label) use (&$ok, &$bad): void {
    $pass ? $ok++ : $bad++;
    echo ($pass ? '[lulus] ' : '[gagal] ') . $label . PHP_EOL;
};
$status = static function (callable $action): int {
    try { return (int) ($action()['status'] ?? 200); }
    catch (BsaException $e) { return $e->status; }
};
$counts = static fn (): array => $db->query('SELECT
    (SELECT COUNT(*) FROM bsa_tabungan_transaksi) tx,
    (SELECT COUNT(*) FROM bsa_tagihan) tagihan,
    (SELECT COUNT(*) FROM bsa_tagihan_komponen) komponen,
    (SELECT COUNT(*) FROM bsa_idempotensi) idem,
    (SELECT COALESCE(SUM(saldo),0) FROM bsa_tabungan_akun) saldo,
    (SELECT COUNT(*) FROM audit_logs WHERE action LIKE \'bsa.%\') audit')->fetch_assoc();
$uid = (int) $scalar("SELECT id FROM users WHERE username='sbx_v6_bsa_b'");
$sid = (int) $scalar("SELECT id FROM santri WHERE nis='V6S0001'");
$user = ['id' => $uid, 'roles' => []];
$svc = bsa_service();
$check($uid > 0 && $sid > 0 && (int) $counts()['tx'] === 0, 'Fixture auditor baru, transaksi awal kosong');
$deposit = ['idempotency_key' => 'audit-deposit-001', 'nominal' => 100000, 'metode' => 'Tunai'];
$withdraw = ['idempotency_key' => 'audit-withdraw-001', 'nominal' => 30000, 'metode' => 'Tunai', 'tujuan' => 'Kebutuhan uji'];
$bill = ['idempotency_key' => 'audit-bill-001', 'santri_id' => $sid, 'periode' => '2026-10', 'jatuh_tempo' => '2026-10-31',
    'komponen' => [['nama' => 'Komponen sintetis', 'nominal' => 50000]]];
$a = $svc->setoran($user, $sid, $deposit, 'api');
$b = $svc->penarikan($user, $sid, $withdraw, 'web');
$c = $svc->terbitkanTagihan($user, $bill, 'api');
$check($a['status'] === 201 && $b['status'] === 201 && $c['status'] === 201 && $svc->saldo($user, $sid)['saldo'] === 70000, 'Setoran, penarikan, satu tagihan: saldo 70000 tanpa debit otomatis');
$before = $counts();
$check($svc->setoran($user, $sid, $deposit, 'web')['id'] === $a['id'], 'Replay lintas kanal mengembalikan ID yang sama');
$check($status(fn () => $svc->setoran($user, $sid, array_replace($deposit, ['nominal' => 100001]), 'api')) === 409, 'Kunci sama, isi berbeda ditolak 409');
$check($counts() === $before, 'Replay/konflik tidak menggandakan ledger, saldo, tagihan, klaim, audit');

// Server terpisah memakai DB yang sama; sesi/token dibuat SEBELUM perubahan.
$port = 8994;
$log = tempnam(sys_get_temp_dir(), 'v6-audit-server-');
$server = proc_open(['php', '-S', "127.0.0.1:$port", 'tests/redesain_web_tahap1_router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, dirname(__DIR__), getenv());
register_shutdown_function(static function () use ($server): void { if (is_resource($server)) proc_terminate($server); });
for ($i = 0; $i < 50; $i++) {
    $socket = @fsockopen('127.0.0.1', $port);
    if ($socket) { fclose($socket); break; }
    usleep(100000);
}
$jar = tempnam(sys_get_temp_dir(), 'v6-audit-cookie-');
$req = static function (string $path, ?array $body = null, ?string $token = null) use ($port, $jar): array {
    $h = curl_init("http://127.0.0.1:$port$path");
    $headers = $token === null ? [] : ['Authorization: Bearer ' . $token, 'Content-Type: application/json'];
    curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar, CURLOPT_HTTPHEADER => $headers]);
    if ($body !== null) curl_setopt_array($h, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $token === null ? http_build_query($body) : json_encode($body)]);
    $text = (string) curl_exec($h);
    $code = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
    curl_close($h);
    return ['status' => $code, 'body' => $text, 'json' => json_decode($text, true)];
};
$page = $req('/portal/index.php');
preg_match('/name="_csrf" value="([^"]+)"/', $page['body'], $m);
$login = $req('/admin/cek_login.php', ['_csrf' => $m[1] ?? '', 'username' => 'sbx_v6_bsa_b', 'password' => 'Sandbox#123']);
$check(in_array($login['status'], [302, 303], true), 'Sesi web BSA dibuka sebelum perubahan hak');
$form = $req("/portal/bsa_transaksi.php?santri_id=$sid&jenis=setoran");
preg_match('/name="_csrf" value="([^"]+)"/', $form['body'], $m);
$csrf = $m[1] ?? '';
$auth = $req('/api/v1/auth/login', ['username' => 'sbx_v6_bsa_b', 'password' => 'Sandbox#123'], '');
$token = (string) ($auth['json']['data']['token'] ?? '');
$check($token !== '' && $csrf !== '', 'Token API dan CSRF tersedia sebelum penyempitan cakupan');

$assignment = (int) $scalar("SELECT a.id FROM bendahara_bulanan_assignments a JOIN users u ON u.pengurus_id=a.pengurus_id WHERE u.id=$uid");
foreach (['cakupan' => "UPDATE bendahara_bulanan_assignments SET jenjang='Tsanawi' WHERE id=$assignment",
          'santri_nonaktif' => "UPDATE santri SET is_active=0 WHERE id=$sid"] as $scenario => $sql) {
    $exec($sql);
    $before = $counts();
    $check($status(fn () => $svc->saldo($user, $sid)) === 404, "$scenario: baca ditolak 404");
    foreach (['setoran' => $deposit, 'penarikan' => $withdraw, 'tagihan' => $bill] as $op => $input) {
        $call = fn () => $op === 'tagihan' ? $svc->terbitkanTagihan($user, $input, 'api') : $svc->$op($user, $sid, $input, 'api');
        $actual = $status($call);
        $check($actual === 404, "$scenario: replay layanan $op ditolak 404 (aktual $actual)");
        $path = $op === 'tagihan' ? '/api/v1/v6/bsa/tagihan' : "/api/v1/v6/bsa/santri/$sid/$op";
        $http = $req($path, $input, $token);
        $check($http['status'] === 404 && !isset($http['json']['data']['id']), "$scenario: replay API $op token lama ditolak tanpa hasil (aktual {$http['status']})");
        $path = $op === 'tagihan' ? "/portal/bsa_tagihan_buat.php?santri_id=$sid" : "/portal/bsa_transaksi.php?santri_id=$sid&jenis=$op";
        $http = $req($path, $input + ['_csrf' => $csrf]);
        $check($http['status'] === 404, "$scenario: replay web $op sesi lama ditolak 404 (aktual {$http['status']})");
    }
    $check($counts() === $before, "$scenario: seluruh replay tanpa perubahan uang/klaim/audit");
    $exec("UPDATE bendahara_bulanan_assignments SET jenjang=NULL WHERE id=$assignment");
    $exec("UPDATE santri SET is_active=1 WHERE id=$sid");
}

// Injeksi kegagalan audit: semua catatan uang harus rollback bersama.
$before = $counts();
$exec("CREATE TRIGGER codex_v6_audit_fail BEFORE INSERT ON audit_logs FOR EACH ROW BEGIN IF NEW.action LIKE 'bsa.%' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic audit failure'; END IF; END");
try {
    $code = $status(fn () => $svc->setoran($user, $sid, array_replace($deposit, ['idempotency_key' => 'audit-failure-001']), 'api'));
    $check($code === 503 && $counts() === $before, 'Audit gagal: ledger, saldo, klaim dan audit rollback atomik');
    $code = $status(fn () => $svc->terbitkanTagihan($user, array_replace($bill, ['idempotency_key' => 'audit-failure-002', 'periode' => '2026-11', 'jatuh_tempo' => '2026-11-30']), 'web'));
    $check($code === 503 && $counts() === $before, 'Audit tagihan gagal: snapshot, tagihan, klaim rollback atomik');
} finally { $exec('DROP TRIGGER codex_v6_audit_fail'); }

$check($svc->setoran($user, $sid, $deposit, 'api')['replayed'] === true, 'Hak dipulihkan: replay sah kembali, tidak perlu kunci baru');

// Kontrol negatif verifier: ledger sah harus selalu mempunyai akun cermin.
$exec('CREATE TEMPORARY TABLE codex_v6_account_backup AS SELECT * FROM bsa_tabungan_akun');
$exec("DELETE FROM bsa_tabungan_akun WHERE santri_id=$sid");
try {
    $proc = proc_open(['php', 'bin/v6_phase1_verify.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $vp, dirname(__DIR__), getenv());
    $out = stream_get_contents($vp[1]) . stream_get_contents($vp[2]);
    $code = proc_close($proc);
    $check($code === 1 && str_contains($out, '[gagal]'), 'Verifier menolak ledger sah tanpa akun tabungan');
} finally {
    $exec("INSERT INTO bsa_tabungan_akun SELECT * FROM codex_v6_account_backup WHERE santri_id=$sid");
    $exec('DROP TEMPORARY TABLE codex_v6_account_backup');
}
$exec("UPDATE bendahara_bulanan_assignments SET is_active=0 WHERE id=$assignment");
$check($status(fn () => $svc->setoran($user, $sid, $deposit, 'api')) === 403, 'Penugasan dicabut: replay ditolak 403');
$check($req("/api/v1/v6/bsa/santri/$sid/saldo", null, $token)['status'] === 403, 'Token lama kehilangan akses saat penugasan dicabut');
$check($req("/portal/bsa_santri.php?id=$sid")['status'] === 403, 'Sesi web lama kehilangan akses saat penugasan dicabut');
$exec("UPDATE bendahara_bulanan_assignments SET is_active=1 WHERE id=$assignment");
$check(!preg_match('/PHP (Warning|Notice|Fatal|Deprecated)|Uncaught/', (string) file_get_contents($log)), 'Log server probe tanpa galat PHP');
proc_terminate($server);
proc_close($server);
unlink($jar);
unlink($log);
echo "Lulus: $ok · Gagal: $bad\n";
exit($bad === 0 ? 0 : 1);
