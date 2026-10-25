<?php
declare(strict_types=1);
/** Koreksi tagihan era 027: fixture menghapus HANYA riwayat versi 1 yang
 * belum ada sebelum 028, lalu merevisi/membatalkan lewat layanan.
 * DB_NAME=test_v6_f2_audit_test V6_RUN_AUDIT=1 php tests/v6_phase2_legacy_audit.php
 * Boleh dijalankan pada DB sintetis hasil probe lain; tidak perlu seed ulang.
 */
if (getenv('V6_RUN_AUDIT') !== '1' || !preg_match('/^test_.*_test$/D', getenv('DB_NAME') ?: '')) exit(2);
require dirname(__DIR__) . '/app/bootstrap.php';
$db = app_db();
$scalar = static fn (string $sql): mixed => $db->query($sql)->fetch_row()[0];
$writer = ['id' => (int) $scalar("SELECT id FROM users WHERE username='sbx_v6_bsa_b'")];
$editor = ['id' => (int) $scalar("SELECT id FROM users WHERE username='sbx_v6_bsa_tsanawi'")];
$sid = (int) $scalar("SELECT id FROM santri WHERE nis='V6S0002'");
$ok=0; $bad=0;
$check = static function (bool $yes, string $msg) use (&$ok, &$bad): void { $yes ? $ok++ : $bad++; echo ($yes ? '[lulus] ' : '[gagal] ') . $msg . PHP_EOL; };
foreach (['revisi' => '2034-01', 'batal' => '2034-02'] as $op => $period) {
    $bill = bsa_service()->terbitkanTagihan($writer, ['idempotency_key' => 'audit-legacy-' . bin2hex(random_bytes(6)), 'santri_id' => $sid, 'periode' => $period,
        'jatuh_tempo' => $period . '-20', 'komponen' => [['nama' => 'Biaya era 027 sintetis', 'nominal' => 100000]]], 'api');
    $id = (int) $bill['id'];
    $original = $db->query("SELECT diterbitkan_oleh,penugasan_id,created_at FROM bsa_tagihan WHERE id=$id")->fetch_assoc();
    $db->query("DELETE FROM bsa_tagihan_riwayat WHERE tagihan_id=$id");
    $check((int) $scalar("SELECT COUNT(*) FROM bsa_tagihan_riwayat WHERE tagihan_id=$id") === 0, "$op: fixture era 027 versi 1 tanpa riwayat");
    $input = ['idempotency_key' => 'audit-legacy-correction-' . bin2hex(random_bytes(5)), 'versi' => 1, 'alasan' => 'Koreksi tagihan era 027 sintetis'];
    if ($op === 'revisi') tagihan_service()->revisiTagihan($editor, $id, $input + ['jenis' => 'Potongan', 'nominal' => 10000], 'api');
    else tagihan_service()->batalkanTagihan($editor, $id, $input, 'api');
    $rows = $db->query("SELECT versi,total,aktor,penugasan_id,created_at FROM bsa_tagihan_riwayat WHERE tagihan_id=$id ORDER BY versi")->fetch_all(MYSQLI_ASSOC);
    $check(array_column($rows, 'versi') === ['1','2'], "$op: riwayat tagihan era 027 lengkap versi 1 dan 2");
    $check(isset($rows[0]) && (int) $rows[0]['total'] === 100000 && $rows[0]['aktor'] === $original['diterbitkan_oleh']
        && $rows[0]['penugasan_id'] === $original['penugasan_id'] && $rows[0]['created_at'] === $original['created_at'], "$op: versi awal memakai angka, aktor, penugasan dan waktu penerbitan asli");
}
$bill = bsa_service()->terbitkanTagihan($writer, ['idempotency_key' => 'audit-legacy-fail-' . bin2hex(random_bytes(6)), 'santri_id' => $sid, 'periode' => '2034-03',
    'jatuh_tempo' => '2034-03-20', 'komponen' => [['nama' => 'Biaya era 027 sintetis', 'nominal' => 100000]]], 'api');
$id = (int) $bill['id'];
$db->query("DELETE FROM bsa_tagihan_riwayat WHERE tagihan_id=$id");
$key = 'audit-legacy-rollback-' . bin2hex(random_bytes(6));
$db->query("CREATE TRIGGER codex_legacy_audit_fail BEFORE INSERT ON audit_logs FOR EACH ROW BEGIN IF NEW.action='bsa.tagihan.revisi' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic legacy audit failure'; END IF; END");
try {
    $status = 200;
    try { tagihan_service()->revisiTagihan($editor, $id, ['idempotency_key' => $key, 'versi' => 1, 'jenis' => 'Potongan', 'nominal' => 10000, 'alasan' => 'Uji rollback riwayat era 027'], 'api'); }
    catch (\App\Bsa\BsaException $e) { $status = $e->status; }
    $check($status === 503, 'Audit gagal pada koreksi pertama era 027 ditolak 503');
    $check((int) $scalar("SELECT COUNT(*) FROM bsa_tagihan_riwayat WHERE tagihan_id=$id") === 0
        && (int) $scalar("SELECT total FROM bsa_tagihan WHERE id=$id") === 100000 && (int) $scalar("SELECT versi FROM bsa_tagihan WHERE id=$id") === 1, 'Riwayat awal/baru dan perubahan tagihan ikut rollback atomik');
    $check((int) $scalar("SELECT COUNT(*) FROM bsa_idempotensi WHERE kunci='$key'") === 0, 'Klaim idempotensi koreksi era 027 gagal ikut rollback');
} finally { $db->query('DROP TRIGGER codex_legacy_audit_fail'); }
$p = proc_open(['php','bin/v6_phase2_verify.php'], [1=>['pipe','w'],2=>['pipe','w']], $pipes, dirname(__DIR__), getenv());
$text = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
$check(proc_close($p) === 0 && !str_contains($text, '[gagal]'), 'Verifier lulus setelah revisi dan pembatalan tagihan era 027');
echo "Hasil: $ok lulus, $bad gagal\n";
exit($bad ? 1 : 0);
