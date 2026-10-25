<?php
declare(strict_types=1);

/** Probe independen Codex: DB sintetis Fase 2 baru, jalankan BERURUTAN.
 * V6F2_DB=test_v6_f2_audit_test V6_RUN_AUDIT=1 php tests/v6_phase2_audit.php
 * Barrier named-lock pada INSERT batch memaksa pencabutan penyesuaian
 * sesudah perhitungan total, sebelum penyimpanan snapshot (dua koneksi).
 * Sesi/token dibuat sebelum perpindahan unit/nonaktif/pencabutan penugasan.
 */
if (getenv('V6_RUN_AUDIT') !== '1' || !preg_match('/^test_.*_test$/D', getenv('V6F2_DB') ?: '')) exit(2);
putenv('DB_NAME=' . getenv('V6F2_DB'));
require dirname(__DIR__) . '/app/bootstrap.php';
use App\Bsa\BsaException;
$db = app_db();
$scalar = static fn (string $sql): mixed => $db->query($sql)->fetch_row()[0];
$exec = static function (string $sql) use ($db): void { if (!$db->query($sql)) throw new RuntimeException($db->error); };
$ok = 0; $bad = 0;
$check = static function (bool $pass, string $label) use (&$ok, &$bad): void { $pass ? $ok++ : $bad++; echo ($pass ? '[lulus] ' : '[gagal] ') . $label . PHP_EOL; };
$status = static function (callable $fn): int { try { $fn(); return 200; } catch (BsaException $e) { return $e->status; } };
$uid = static fn (string $name): int => (int) $scalar("SELECT id FROM users WHERE username='$name'");
$user = ['id' => $uid('sbx_v6_bsa_b')];
$unitUser = ['id' => $uid('sbx_v6_bsa_tsanawi')];
$admin = ['id' => $uid('sbx_admin')];
$smp = (int) $scalar("SELECT id FROM santri WHERE nis='V6S0001'");
$ts = (int) $scalar("SELECT id FROM santri WHERE nis='V6S0002'");
$svc = tagihan_service();
$check((int) $scalar('SELECT COUNT(*) FROM bsa_tarif') === 0, 'Fixture baru tanpa tarif');
foreach (['SMP', 'Tsanawi'] as $u) $svc->buatTarif($user, ['idempotency_key' => 'audit-tarif-' . $u, 'unit' => $u, 'berlaku_mulai' => '2032-01', 'komponen' => [['nama' => 'Biaya sintetis', 'nominal' => 100000]]], 'api');
$adj = $svc->buatPenyesuaian($user, $smp, ['idempotency_key' => 'audit-adjustment-1', 'jenis' => 'Potongan', 'nama' => 'Potongan sintetis', 'nominal' => 10000, 'berlaku_mulai' => '2032-01', 'alasan' => 'Penyesuaian sintetis auditor'], 'api');
$input = ['periode' => '2032-01', 'jatuh_tempo' => '2032-01-31'];
$preview = $svc->pratinjau($user, $input);
$gate = 'codex_v6_f2_snapshot_gate';
$scalar("SELECT GET_LOCK('$gate', 0)");
$exec("CREATE TRIGGER codex_v6_f2_batch_gate BEFORE INSERT ON bsa_penerbitan FOR EACH ROW BEGIN DO GET_LOCK('$gate', 15); DO RELEASE_LOCK('$gate'); END");
$go = tempnam(sys_get_temp_dir(), 'v6f2-go-');
$p = proc_open(['php', 'tests/v6_phase2_worker.php', $go, (string) $user['id'], 'massal', $input['periode'], $input['jatuh_tempo'], $preview['hash'], 'audit-batch-race-1'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), getenv());
try {
    $waiting = false;
    for ($i = 0; $i < 500; $i++) {
        if ((int) $scalar("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE DB=DATABASE() AND STATE='User lock'") > 0) { $waiting = true; break; }
        usleep(10000);
    }
    $check($waiting, 'Barrier memastikan batch sudah menghitung total dan menunggu INSERT');
    $svc->cabutPenyesuaian($user, $adj['id'], ['idempotency_key' => 'audit-adjustment-revoke', 'alasan' => 'Cabut saat penerbitan berjalan'], 'api');
    $check((string) $scalar('SELECT status FROM bsa_penyesuaian WHERE id=' . $adj['id']) === 'Dicabut', 'Koneksi kedua mencabut penyesuaian saat batch tertahan');
} finally { $scalar("SELECT RELEASE_LOCK('$gate')"); }
$result = json_decode(stream_get_contents($pipes[1]), true);
$error = stream_get_contents($pipes[2]);
proc_close($p); unlink($go); $exec('DROP TRIGGER codex_v6_f2_batch_gate');
$check(($result['ok'] ?? false) && $error === '', 'Penerbitan setelah barrier berhasil tanpa galat');
$bill = (int) $scalar("SELECT id FROM bsa_tagihan WHERE santri_id=$smp AND periode='2032-01'");
$check((int) $scalar("SELECT total FROM bsa_tagihan WHERE id=$bill") === 90000, 'Total mempertahankan penyesuaian pada rencana yang disetujui');
$check((int) $scalar("SELECT COUNT(*) FROM bsa_tagihan_penyesuaian WHERE tagihan_id=$bill AND penyesuaian_id=" . $adj['id']) === 1, 'Snapshot menyimpan potongan yang sama dengan perhitungan total');
$check((int) $scalar("SELECT t.total - (SELECT SUM(nominal) FROM bsa_tagihan_komponen WHERE tagihan_id=t.id) - COALESCE((SELECT SUM(IF(jenis='Tambahan',nominal,-nominal)) FROM bsa_tagihan_penyesuaian WHERE tagihan_id=t.id),0) FROM bsa_tagihan t WHERE t.id=$bill") === 0, 'Total = komponen + penyesuaian setelah race');
$vp = proc_open(['php', 'bin/v6_phase2_verify.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $vpipe, dirname(__DIR__), getenv());
$vout = stream_get_contents($vpipe[1]) . stream_get_contents($vpipe[2]);
$check(proc_close($vp) === 0 && !str_contains($vout, '[gagal]'), 'Verifier menerima hasil penerbitan setelah race');

// Satu batch unit Tsanawi; perubahan hak tidak boleh membuka identitas/uang
// lewat halaman batch ketika rincian tagihan sudah menolak.
$params = ['periode' => '2032-02', 'jatuh_tempo' => '2032-02-28', 'unit' => 'Tsanawi'];
$pv = $svc->pratinjau($unitUser, $params);
$batch = $svc->terbitkanMassal($unitUser, $params + ['pratinjau_hash' => $pv['hash'], 'idempotency_key' => 'audit-unit-batch-1'], 'api');
$tsBill = (int) $scalar("SELECT id FROM bsa_tagihan WHERE santri_id=$ts AND periode='2032-02'");
$port = 8993; $log = tempnam(sys_get_temp_dir(), 'v6f2-audit-server-');
$server = proc_open(['php', '-S', "127.0.0.1:$port", 'tests/redesain_web_tahap1_router.php'], [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $sp, dirname(__DIR__), getenv());
register_shutdown_function(static function () use ($server): void { if (is_resource($server)) proc_terminate($server); });
for ($i=0; $i<50; $i++) { $sock = @fsockopen('127.0.0.1', $port); if ($sock) { fclose($sock); break; } usleep(100000); }
$jar = tempnam(sys_get_temp_dir(), 'v6f2-audit-jar-');
$req = static function (string $path, ?array $body=null, ?string $token=null) use ($port, $jar): array {
    $c = curl_init("http://127.0.0.1:$port$path");
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar,
        CURLOPT_HTTPHEADER => $token === null ? [] : ['Content-Type: application/json', 'Authorization: Bearer ' . $token]]);
    if ($body !== null) curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $token === null ? http_build_query($body) : json_encode($body)]);
    $text = (string) curl_exec($c); $code = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE); curl_close($c);
    return ['status' => $code, 'body' => $text, 'json' => json_decode($text, true)];
};
$page = $req('/portal/index.php'); preg_match('/name="_csrf" value="([^"]+)"/', $page['body'], $m);
$check($req('/admin/cek_login.php', ['_csrf' => $m[1] ?? '', 'username' => 'sbx_v6_bsa_tsanawi', 'password' => 'Sandbox#123'])['status'] === 302, 'Sesi web dibuka sebelum perubahan hak');
$auth = $req('/api/v1/auth/login', ['username' => 'sbx_v6_bsa_tsanawi', 'password' => 'Sandbox#123'], '');
$token = (string) ($auth['json']['data']['token'] ?? '');
$check($token !== '', 'Token API dibuat sebelum perubahan hak');
$before = $svc->penerbitan($unitUser, $batch['id']);
$check(in_array($ts, array_column(array_column($before['rows'], 'santri'), 'id'), true), 'Kontrol positif: santri Tsanawi terlihat pada batch');
$placement = $db->query("SELECT id,id_kelas FROM plotting_kelas WHERE id_santri=$ts AND status='Aktif' ORDER BY id DESC LIMIT 1")->fetch_assoc();
$smpClass = (int) $scalar("SELECT id_kelas FROM plotting_kelas WHERE id_santri=$smp AND status='Aktif' ORDER BY id DESC LIMIT 1");
$nis = (string) $scalar("SELECT nis FROM santri WHERE id=$ts");
$assignment = (int) $scalar('SELECT a.id FROM bendahara_bulanan_assignments a JOIN users u ON u.pengurus_id=a.pengurus_id WHERE u.id=' . $unitUser['id']);
foreach (['pindah_unit' => "UPDATE plotting_kelas SET id_kelas=$smpClass WHERE id=" . $placement['id'], 'nonaktif' => "UPDATE santri SET is_active=0 WHERE id=$ts"] as $scenario => $sql) {
    $exec($sql);
    $check($status(fn () => $svc->rincianTagihan($unitUser, $tsBill)) === 404, "$scenario: rincian tagihan tertutup (kontrol)");
    $rows = $svc->penerbitan($unitUser, $batch['id'])['rows'];
    $check(!in_array($ts, array_column(array_column($rows, 'santri'), 'id'), true), "$scenario: layanan batch tidak membuka santri di luar hak kini");
    $http = $req('/api/v1/v6/bsa/penerbitan/' . $batch['id'], null, $token);
    $ids = array_column(array_column($http['json']['data']['rows'] ?? [], 'santri'), 'id');
    $check($http['status'] === 200 && !in_array($ts, $ids, true), "$scenario: API batch token lama menutup data santri");
    $web = $req('/portal/bsa_penerbitan.php?id=' . $batch['id']);
    $check($web['status'] === 200 && !str_contains($web['body'], $nis), "$scenario: web batch sesi lama menutup data santri");
    $check(in_array($ts, array_column(array_column($svc->penerbitan($admin, $batch['id'])['rows'], 'santri'), 'id'), true), "$scenario: admin tetap dapat mengaudit riwayat");
    $exec('UPDATE plotting_kelas SET id_kelas=' . $placement['id_kelas'] . ' WHERE id=' . $placement['id']);
    $exec("UPDATE santri SET is_active=1 WHERE id=$ts");
}
$exec("UPDATE bendahara_bulanan_assignments SET is_active=0 WHERE id=$assignment");
$check($req('/api/v1/v6/bsa/penerbitan/' . $batch['id'], null, $token)['status'] === 403, 'Penugasan dicabut: token lama batch ditolak');
$check($req('/portal/bsa_penerbitan.php?id=' . $batch['id'])['status'] === 403, 'Penugasan dicabut: sesi lama batch ditolak');
$exec("UPDATE bendahara_bulanan_assignments SET is_active=1 WHERE id=$assignment");
$check($status(fn () => $svc->penerbitan($unitUser, $batch['id'])) === 200, 'Hak dipulihkan: batch kembali dapat diaudit');

// Replay transfer menunggu tidak mempunyai snapshot kuitansi; replay
// pembatalan tidak boleh memakai sisa kuitansi pembayaran yang dibatalkan.
$payInput = ['idempotency_key' => 'audit-pending-replay', 'nominal' => 40000, 'sumber' => 'Transfer', 'referensi' => 'SYNTHETIC-AUDIT', 'tanggal_transaksi' => date('Y-m-d')];
$pay = $svc->catatPembayaran($unitUser, $tsBill, $payInput, 'api');
$check($pay['sisa'] === 100000 && $pay['status_pembayaran'] === 'Menunggu', 'Transfer menunggu mempertahankan sisa 100000');
$check($svc->catatPembayaran($unitUser, $tsBill, $payInput, 'api')['sisa'] === 100000, 'Replay transfer menunggu layanan mempertahankan sisa 100000');
$http = $req("/api/v1/v6/bsa/tagihan/$tsBill/pembayaran", $payInput, $token);
$check($http['status'] === 200 && ($http['json']['data']['sisa'] ?? null) === 100000, 'Replay transfer menunggu API mengembalikan sisa 100000');
$svc->verifikasiPembayaran($unitUser, $pay['id'], ['idempotency_key' => 'audit-pending-verify'], 'api');
$cancelInput = ['idempotency_key' => 'audit-payment-cancel', 'alasan' => 'Pembatalan sintetis auditor'];
$cancel = $svc->batalkanPembayaran($unitUser, $pay['id'], $cancelInput, 'api');
$check($cancel['sisa'] === 100000, 'Pembatalan mengembalikan sisa ke 100000');
$check($svc->batalkanPembayaran($unitUser, $pay['id'], $cancelInput, 'api')['sisa'] === 100000, 'Replay pembatalan layanan mempertahankan sisa 100000');
$http = $req('/api/v1/v6/bsa/pembayaran/' . $pay['id'] . '/batal', $cancelInput, $token);
$check($http['status'] === 200 && ($http['json']['data']['sisa'] ?? null) === 100000, 'Replay pembatalan API mengembalikan sisa 100000');
proc_terminate($server);
echo "Hasil: $ok lulus, $bad gagal\n";
exit($bad ? 1 : 0);
