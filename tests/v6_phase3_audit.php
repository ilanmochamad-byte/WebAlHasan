<?php
declare(strict_types=1);

/** Probe independen Codex, hanya DB sintetis baru.
 * V6F3_DB=test_v6_f3_audit_test bash tests/v6_phase3_db.sh
 * V6F3_DB=test_v6_f3_audit_test V6_RUN_AUDIT=1 php tests/v6_phase3_audit.php
 * SET timestamp hanya mengendalikan waktu fixture pada koneksi DB uji ini.
 */
if (getenv('V6_RUN_AUDIT') !== '1' || !preg_match('/^test_.*_test$/D', getenv('V6F3_DB') ?: '')) exit(2);
putenv('DB_NAME=' . getenv('V6F3_DB'));
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
$admin = ['id' => $uid('sbx_admin')];
$wali = ['id' => $uid('sbx_v6_wali_smp')];
$other = ['id' => $uid('sbx_v6_bsa_tsanawi')];
$smp = (int) $scalar("SELECT id FROM santri WHERE nis='V6S0001'");
$ts = (int) $scalar("SELECT id FROM santri WHERE nis='V6S0002'");
$tab = tabungan_service(); $tag = tagihan_service(); $bsa = bsa_service();
$k = static fn (): string => 'audit3-' . bin2hex(random_bytes(8));
$check((int) $scalar('SELECT COUNT(*) FROM bsa_tabungan_transaksi') === 0, 'Fixture baru tanpa ledger');
$pending = static fn (int $s, int $n): array => $tab->setoran($user, $s, ['idempotency_key' => $k(), 'nominal' => $n, 'metode' => 'Transfer', 'referensi' => 'TRF-AUDITOR'], 'api');
$bill = static fn (int $s, string $p): int => $bsa->terbitkanTagihan($user, ['idempotency_key' => $k(), 'santri_id' => $s, 'periode' => $p, 'jatuh_tempo' => $p . '-10', 'komponen' => [['nama' => 'Uji auditor', 'nominal' => 100000]]], 'api')['id'];
$balance = static fn (int $s): int => $bsa->saldo($user, $s)['saldo'];
$counts = static function () use ($scalar): array {
    $r = [];
    foreach (['bsa_tabungan_transaksi', 'bsa_pembayaran', 'bsa_pembayaran_kuitansi', 'bsa_pembayaran_pembatalan', 'bsa_idempotensi', 'audit_logs'] as $t) $r[$t] = (int) $scalar("SELECT COUNT(*) FROM $t");
    return $r;
};

echo "=== A. Jumlah menunggu melampaui batas tampilan ===\n";
for ($i = 0; $i < 51; $i++) $pending($smp, 1000 + $i);
$expected = (int) $scalar("SELECT SUM(nominal) FROM bsa_setoran_transfer WHERE santri_id=$smp AND status='Menunggu'");
$detail = $tab->santri($user, $smp);
$check(count($detail['setoran_transfer']) === 50, 'Daftar rincian dibatasi 50 baris');
$check($detail['saldo_menunggu'] === $expected, "Ringkasan seluruh 51 transfer Rp$expected, bukan hanya 50 baris (aktual Rp{$detail['saldo_menunggu']})");
$check($balance($smp) === 0 && $tab->santri($wali, $smp)['saldo_menunggu'] === 0, '51 transfer belum sah tanpa saldo efektif atau data menunggu untuk wali');
$check($tab->rekonsiliasi($admin, [])['menunggu']['setoran_transfer']['nominal'] === $expected, 'Rekonsiliasi memakai seluruh nominal menunggu');

echo "=== B. Hari setoran, hari pembayaran, hari pembatalan terpisah ===\n";
$d1 = date('Y-m-d', strtotime('-2 days')); $d2 = date('Y-m-d', strtotime('-1 day')); $today = date('Y-m-d');
$time = static function (string $day) use ($exec): void { $exec("SET timestamp = UNIX_TIMESTAMP('$day 12:00:00')"); };
$time($d1);
$deposit = $tab->setoran($user, $ts, ['idempotency_key' => $k(), 'nominal' => 100000, 'metode' => 'Tunai'], 'api');
$withdraw = $tab->penarikan($user, $ts, ['idempotency_key' => $k(), 'jenis' => 'Belanja', 'nominal' => 25000, 'metode' => 'Tunai', 'tujuan' => 'Kitab auditor'], 'api');
$transfer = $pending($ts, 20000);
$id = $bill($ts, '2035-01');
$check($balance($ts) === 75000, 'Setoran Rp100.000 − belanja Rp25.000 = Rp75.000; transfer belum sah tidak dihitung');
$time($d2);
$payInput = ['idempotency_key' => $k(), 'nominal' => 50000, 'setuju' => true];
$pay = $tag->bayarDariTabungan($user, $id, $payInput, 'api');
$check($balance($ts) === 25000 && $pay['sisa'] === 50000, 'Hari kedua: bayar Rp50.000 → saldo Rp25.000 dan sisa Rp50.000');
$replay = $tag->bayarDariTabungan($user, $id, $payInput, 'api');
$check($replay['transaksi_id'] === $pay['transaksi_id'] && $replay['replayed'], 'Retry pembayaran menunjuk debit yang sama');
$time($today);
$cancelInput = ['idempotency_key' => $k(), 'alasan' => 'Pembayaran keliru menurut pemeriksaan auditor'];
$cancel = $tag->batalkanPembayaran($user, $pay['id'], $cancelInput, 'api');
$after = $counts();
$cancelReplay = $tag->batalkanPembayaran($user, $pay['id'], $cancelInput, 'api');
$check(($cancelReplay['transaksi_id'] ?? null) === $cancel['transaksi_id'] && ($cancelReplay['saldo'] ?? null) === 75000,
    'Replay pembatalan tetap membawa ID pembalik dan saldo (kontrak Fase 3)');
$check($cancelReplay['replayed'] && $cancelReplay['sisa'] === 100000 && $balance($ts) === 75000 && $counts() === $after, 'Replay pembatalan tanpa pembalik/klaim/audit tambahan');
$tab->verifikasiSetoran($user, $transfer['id'], ['idempotency_key' => $k()], 'api');
$reports = [];
foreach ([$d1, $d2, $today] as $d) $reports[] = $tab->rekonsiliasi($admin, ['tanggal' => $d]);
$check(array_column($reports, 'penerimaan_kas_baru') === [100000, 0, 20000], 'Kas baru per hari Rp100.000/Rp0/Rp20.000; internal tidak dihitung ulang');
$check(array_column(array_column($reports, 'saldo'), 'awal') === [0, 75000, 25000]
    && array_column(array_column($reports, 'saldo'), 'akhir') === [75000, 25000, 95000], 'Saldo awal/akhir tepat pada tiga hari');
$check($reports[1]['internal']['debit_tabungan'] === 50000 && $reports[1]['internal']['selisih'] === 0
    && $reports[2]['internal']['pengembalian_ke_tabungan'] === 50000 && $reports[2]['internal']['selisih_pengembalian'] === 0, 'Debit dan pembayaran pada hari kedua, pembalik dan pembatalan pada hari ketiga berpasangan Rp0 selisih');
$check($reports[2]['kas']['Transfer']['masuk'] === 20000 && $reports[0]['kas']['Transfer']['masuk'] === 0, 'Transfer dihitung hari diverifikasi, bukan hari antrean dibuat');
$receipt = $tab->transaksi($user, $withdraw['id']);
$check($receipt['saldo_sebelum'] === 100000 && $receipt['saldo_sesudah'] === 75000 && $receipt['nomor_kuitansi'] === $withdraw['nomor_kuitansi'], 'Kuitansi belanja mempertahankan angka dan nomor historis sesudah pembayaran/pembatalan');
$exec('SET timestamp = 0');

echo "=== C. Gagal sesudah debit, lalu retry kunci yang sama ===\n";
$failedInput = ['idempotency_key' => $k(), 'nominal' => 30000, 'setuju' => true];
$before = $counts(); $beforeBalance = $balance($ts);
$exec("CREATE TRIGGER audit_v6f3_fail BEFORE INSERT ON bsa_pembayaran FOR EACH ROW BEGIN IF NEW.sumber='Tabungan' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='audit gagal sesudah debit'; END IF; END");
try { $code = $status(fn () => $tag->bayarDariTabungan($user, $id, $failedInput, 'api')); }
finally { $exec('DROP TRIGGER audit_v6f3_fail'); }
$check($code === 503 && $counts() === $before && $balance($ts) === $beforeBalance
    && (int) $scalar("SELECT terbayar FROM bsa_tagihan WHERE id=$id") === 0, 'Rollback debit/pembayaran/kuitansi/klaim/audit saat INSERT pembayaran gagal');
$retried = $tag->bayarDariTabungan($user, $id, $failedInput, 'api');
$retriedAgain = $tag->bayarDariTabungan($user, $id, $failedInput, 'api');
$check(!$retried['replayed'] && $retriedAgain['replayed'] && $retried['id'] === $retriedAgain['id'] && $balance($ts) === $beforeBalance - 30000, 'Retry kunci yang gagal lalu dua kali berhasil hanya membukukan satu debit/pembayaran');
$check($status(fn () => $tab->koreksi($user, $deposit['id'], ['idempotency_key' => $k(), 'alasan' => 'Setoran sudah dipakai membayar'], 'api')) === 422, 'Koreksi setoran yang sudah dipakai ditolak tanpa saldo negatif');
$check($status(fn () => $tag->bayarDariTabungan($admin, $id, $failedInput, 'api')) === 403
    && $status(fn () => $tab->transaksi($other, $deposit['id'])) === 200
    && $status(fn () => $tab->transaksi($wali, $deposit['id'])) === 404, 'Admin mutasi ditolak; petugas Tsanawi membaca Tsanawi; wali SMP menebak ID ditolak');

echo "=== HTTP. Ringkasan dan replay melalui API ===\n";
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:8963', __DIR__ . '/redesain_web_tahap1_router.php'],
    [1 => ['file', sys_get_temp_dir() . '/v6f3-audit-probe-http.log', 'a'], 2 => ['file', sys_get_temp_dir() . '/v6f3-audit-probe-http.log', 'a']], $pipes, dirname(__DIR__));
$req = static function (string $path, ?array $body = null, string $token = ''): array {
    $c = curl_init('http://127.0.0.1:8963/api/v1' . $path);
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $token]]);
    if ($body !== null) curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body)]);
    $raw = curl_exec($c); $code = curl_getinfo($c, CURLINFO_RESPONSE_CODE); curl_close($c);
    return [$code, json_decode((string) $raw, true)['data'] ?? []];
};
try {
    usleep(500000);
    [, $auth] = $req('/auth/login', ['username' => 'sbx_v6_bsa_b', 'password' => 'Sandbox#123']);
    $token = (string) ($auth['token'] ?? '');
    [$code, $detail] = $req('/v6/bsa/santri/' . $smp, null, $token);
    $check($token !== '' && $code === 200 && ($detail['saldo_menunggu'] ?? -1) === $expected, 'API ringkasan menunggu mencakup semua 51 transfer');
    [$code, $replay] = $req('/v6/bsa/pembayaran/' . $pay['id'] . '/batal', $cancelInput, $token);
    $check($code === 200 && ($replay['replayed'] ?? false) && ($replay['transaksi_id'] ?? null) === $cancel['transaksi_id']
        && ($replay['saldo'] ?? null) === $balance($ts), 'API replay pembatalan membawa pembalik yang sama dan saldo kini setelah transaksi berikutnya');
} finally { proc_terminate($server); proc_close($server); }

echo "=== D. Kontrol negatif verifier dan preservasi invarians ===\n";
$verify = static function (): array {
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../bin/v6_phase3_verify.php') . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
};
$check($verify()[0] === 0, 'Verifier lulus setelah fixture, rollback dan retry');
// Perusakan nominal hanya di fixture, dipulihkan sebelum keluar.
$exec('UPDATE bsa_tabungan_transaksi SET nominal=nominal+1 WHERE id=' . $retried['transaksi_id']);
try { $check($verify()[0] !== 0, 'Verifier menolak debit berbeda nominal dari pembayarannya'); }
finally { $exec('UPDATE bsa_tabungan_transaksi SET nominal=nominal-1 WHERE id=' . $retried['transaksi_id']); }
$check($verify()[0] === 0 && (int) $scalar('SELECT COUNT(*) FROM bsa_tabungan_akun WHERE saldo<0') === 0, 'Fixture pulih; nol saldo negatif dan verifier kembali lulus');
$manifestPath = tempnam(sys_get_temp_dir(), 'v6f3-manifest-audit-');
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../bin/v6_phase3_preflight.php') . ' ' . escapeshellarg($manifestPath) . ' 2>&1', $preflightOut, $preflightCode);
$manifest = json_decode((string) file_get_contents($manifestPath), true);
$verifyManifest = static function (array $m) use ($manifestPath): int {
    file_put_contents($manifestPath, json_encode($m));
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../bin/v6_phase3_verify.php') . ' ' . escapeshellarg($manifestPath) . ' 2>&1', $out, $code);
    return $code;
};
try {
    $check($preflightCode === 0 && $verifyManifest($manifest) === 0, 'Kontrol positif manifest lengkap lulus');
    $cut = $manifest; unset($cut['legacy_counts']['psb_pendaftar']);
    $check($verifyManifest($cut) !== 0, 'Verifier menolak manifest tanpa hitungan tabel PSB');
    $cut = $manifest; $cut['legacy_counts'] = [];
    $check($verifyManifest($cut) !== 0, 'Verifier menolak manifest dengan daftar tabel lama kosong');
} finally { unlink($manifestPath); }
echo "Hasil: $ok lulus, $bad gagal\n";
exit($bad ? 1 : 0);
