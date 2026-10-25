<?php
declare(strict_types=1);
/** Probe Codex: clock boundary deterministik di antara statement, DB sintetis BARU.
 * V6F3_DB=test_v6_f3_boundary_test bash tests/v6_phase3_db.sh
 * V6F3_DB=test_v6_f3_boundary_test V6_RUN_AUDIT=1 php tests/v6_phase3_boundary_audit.php
 * Trigger uji hanya menggeser timestamp sesi, bukan isi/aturan transaksi.
 */
if (getenv('V6_RUN_AUDIT') !== '1' || !preg_match('/^test_.*_test$/D', getenv('V6F3_DB') ?: '')) exit(2);
putenv('DB_NAME=' . getenv('V6F3_DB'));
require dirname(__DIR__) . '/app/bootstrap.php';
$db = app_db();
$scalar = static fn (string $sql): mixed => $db->query($sql)->fetch_row()[0];
$exec = static function (string $sql) use ($db): void { if (!$db->query($sql)) throw new RuntimeException($db->error); };
$ok = 0; $bad = 0;
$check = static function (bool $pass, string $label) use (&$ok, &$bad): void { $pass ? $ok++ : $bad++; echo ($pass ? '[lulus] ' : '[gagal] ') . $label . PHP_EOL; };
$u = ['id' => (int) $scalar("SELECT id FROM users WHERE username='sbx_v6_bsa_b'")];
$a = ['id' => (int) $scalar("SELECT id FROM users WHERE username='sbx_admin'")];
$s = (int) $scalar("SELECT id FROM santri WHERE nis='V6S0001'");
$tab = tabungan_service(); $tag = tagihan_service();
$d1 = date('Y-m-d', strtotime('-2 days')); $d2 = date('Y-m-d', strtotime('-1 day')); $d3 = date('Y-m-d');
$check((int) $scalar('SELECT COUNT(*) FROM bsa_tabungan_transaksi') === 0, 'Fixture kosong untuk batas tanggal');
try {
    $exec("SET timestamp=UNIX_TIMESTAMP('$d1 12:00:00')");
    $tab->setoran($u, $s, ['idempotency_key' => 'boundary-deposit', 'nominal' => 100000, 'metode' => 'Tunai'], 'api');
    $t = bsa_service()->terbitkanTagihan($u, ['idempotency_key' => 'boundary-bill', 'santri_id' => $s, 'periode' => '2039-01', 'jatuh_tempo' => '2039-01-10',
        'komponen' => [['nama' => 'Uji batas hari', 'nominal' => 100000]]], 'api');
    $exec("CREATE TRIGGER audit_v6f3_clock_pay AFTER INSERT ON bsa_tabungan_transaksi FOR EACH ROW SET timestamp=UNIX_TIMESTAMP('$d2 00:00:01')");
    $exec("SET timestamp=UNIX_TIMESTAMP('$d1 23:59:59')");
    $p = $tag->bayarDariTabungan($u, $t['id'], ['idempotency_key' => 'boundary-pay', 'nominal' => 50000, 'setuju' => true], 'api');
    $exec('DROP TRIGGER audit_v6f3_clock_pay');
    $actual = $db->query('SELECT x.created_at AS debit, b.diverifikasi_pada AS pembayaran FROM bsa_pembayaran b JOIN bsa_tabungan_transaksi x ON x.id=b.tabungan_transaksi_id WHERE b.id=' . $p['id'])->fetch_assoc();
    $check($actual['debit'] === "$d1 23:59:59" && $actual['pembayaran'] === "$d2 00:00:01", 'Kontrol: satu transaksi benar-benar mempunyai timestamp debit/pembayaran di dua hari (' . json_encode($actual) . ')');
    $exec("CREATE TRIGGER audit_v6f3_clock_cancel AFTER INSERT ON bsa_pembayaran_pembatalan FOR EACH ROW SET timestamp=UNIX_TIMESTAMP('$d3 00:00:01')");
    $exec("SET timestamp=UNIX_TIMESTAMP('$d2 23:59:59')");
    $c = $tag->batalkanPembayaran($u, $p['id'], ['idempotency_key' => 'boundary-cancel', 'alasan' => 'Koreksi uji batas tanggal'], 'api');
    $exec('DROP TRIGGER audit_v6f3_clock_cancel');
    $actual = $db->query('SELECT xb.created_at AS batal, rv.created_at AS pembalik FROM bsa_pembayaran_pembatalan xb JOIN bsa_pembayaran b ON b.id=xb.pembayaran_id JOIN bsa_tabungan_transaksi rv ON rv.pembalik_dari_id=b.tabungan_transaksi_id WHERE b.id=' . $p['id'])->fetch_assoc();
    $check($actual['batal'] === "$d2 23:59:59" && $actual['pembalik'] === "$d3 00:00:01", 'Kontrol: pembatalan/pembalik di dua hari (' . json_encode($actual) . ')');
    $exec('SET timestamp=0');
    $reports = [];
    foreach ([$d1, $d2, $d3] as $d) $reports[] = $tab->rekonsiliasi($a, ['tanggal' => $d]);
    $check(array_column(array_column($reports, 'internal'), 'selisih') === [0, 0, 0], 'Selisih debit/pembayaran Rp0 pada ketiga hari');
    $check(array_column(array_column($reports, 'internal'), 'selisih_pengembalian') === [0, 0, 0], 'Selisih pembalik/pembatalan Rp0 pada ketiga hari');
    $check($reports[0]['internal']['pembayaran_dari_tabungan'] === 50000 && $reports[1]['internal']['pembayaran_dari_tabungan'] === 0
        && $reports[2]['internal']['pembatalan_pembayaran_tabungan'] === 50000, 'Pasangan internal memakai hari debit/pembalik, bukan waktu statement pasangannya');
    $check(count(array_filter($reports[0]['rows'], fn ($x) => $x['internal'])) === 2 && count(array_filter($reports[1]['rows'], fn ($x) => $x['internal'])) === 0
        && count(array_filter($reports[2]['rows'], fn ($x) => $x['internal'])) === 2, 'Rincian dan agregat menempatkan dua sisi pada hari sama');
    $check(array_column($reports, 'penerimaan_kas_baru') === [100000, 0, 0] && array_column(array_column($reports, 'saldo'), 'akhir') === [50000, 50000, 100000], 'Kas hanya saat setoran, saldo akhir mengikuti ledger lintas batas hari');
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../bin/v6_phase3_verify.php') . ' 2>&1', $out, $code);
    $check($code === 0 && $c['saldo'] === 100000, 'Verifier tetap lulus: uang, referensi, dan saldo utuh');
} finally {
    $exec('DROP TRIGGER IF EXISTS audit_v6f3_clock_pay');
    $exec('DROP TRIGGER IF EXISTS audit_v6f3_clock_cancel');
    $exec('SET timestamp=0');
}
echo "Hasil: $ok lulus, $bad gagal\n";
exit($bad ? 1 : 0);
