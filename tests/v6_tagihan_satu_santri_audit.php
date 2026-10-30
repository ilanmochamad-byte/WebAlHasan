<?php

declare(strict_types=1);

// Jalankan sesudah menyalin DB hasil integrasi satu santri ke DB audit berbeda.
// V6_RUN_AUDIT=1 DB_NAME=test_v6_satu_santri_audit_test php tests/v6_tagihan_satu_santri_audit.php
if (getenv('V6_RUN_AUDIT') !== '1' || !preg_match('/^test_.*_test$/D', (string) getenv('DB_NAME'))) { exit(2); }
require_once dirname(__DIR__) . '/app/bootstrap.php';
$db = app_db(); $tag = tagihan_service(); $ok = $bad = 0;
$q = static fn (string $s): mixed => $db->query($s)->fetch_row()[0] ?? null;
$user = static fn (string $u): array => ['id' => (int) $q("SELECT id FROM users WHERE username='$u'")];
$a = $user('sbx_v6_bsa_b'); $b = $user('sbx_v6_bsa_lama');
$s1 = (int) $q("SELECT id FROM santri WHERE nis='V6S0001'");
$s2 = (int) $q("SELECT id FROM santri WHERE nis='V6S0002'");
$other = (int) $q("SELECT santri_id FROM bsa_tagihan WHERE santri_id <> $s1 AND jenjang='SMP' ORDER BY santri_id LIMIT 1");
$assert = static function (bool $v, string $m) use (&$ok, &$bad): void { echo ($v ? '[lulus] ' : '[gagal] ') . $m . PHP_EOL; $v ? $ok++ : $bad++; };
$reject = static function (callable $f): int { try { $f(); return 200; } catch (App\Bsa\BsaException $e) { return $e->status; } };
$key = static fn (): string => 'audit-ss-' . bin2hex(random_bytes(12));
$period = static fn (int $i): string => (new DateTimeImmutable(date('Y-m-01')))->modify("+$i months")->format('Y-m');
$input = static function (array $u, int $sid, int $i) use ($tag, $period, $key): array {
    $x = ['periode' => $period($i), 'jatuh_tempo' => $period($i) . '-20'] + ($sid > 0 ? ['santri_id' => $sid] : ['unit' => 'SMP']);
    return $x + ['idempotency_key' => $key(), 'pratinjau_hash' => $tag->pratinjau($u, $x)['hash']];
};
$count = static fn (): string => (string) $q("SELECT CONCAT_WS(':', (SELECT COUNT(*) FROM bsa_tagihan),(SELECT COUNT(*) FROM bsa_tagihan_komponen),(SELECT COUNT(*) FROM bsa_tagihan_penyesuaian),
    (SELECT COUNT(*) FROM bsa_tagihan_riwayat),(SELECT COUNT(*) FROM bsa_penerbitan),(SELECT COUNT(*) FROM bsa_penerbitan_santri),(SELECT COUNT(*) FROM bsa_idempotensi),(SELECT COUNT(*) FROM audit_logs),(SELECT COUNT(*) FROM notifikasi_outbox))");
$run = static function (array $jobs): array {
    $gate = tempnam(sys_get_temp_dir(), 'ss-audit-'); unlink($gate); $procs = [];
    foreach ($jobs as [$user, $data]) {
        $p = proc_open([PHP_BINARY, __DIR__ . '/v6_tagihan_satu_santri_worker.php', $gate, (string) $user['id'], base64_encode((string) json_encode($data))], [1 => ['pipe','w'],2 => ['pipe','w']], $pipes);
        $procs[] = [$p, $pipes];
    }
    usleep(200000); touch($gate); $out = [];
    foreach ($procs as [$p, $pipes]) {
        $s = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); $code = proc_close($p);
        $out[] = json_decode(trim($s), true) ?? ['ok'=>false,'worker_exit'=>$code,'stderr_bytes'=>strlen($err)];
    }
    unlink($gate); return $out;
};
$assert($s1 > 0 && $s2 > 0 && $other > 0 && $a['id'] > 0 && $b['id'] > 0, 'Fixture auditor: dua petugas sah dan dua santri SMP, satu Tsanawi');

$x = $input($a, $s1, 1); $x['total'] = '1'; $x['komponen'] = [['nama'=>'Nilai palsu','nominal'=>'1']];
$h = $tag->terbitkanMassal($a, $x, 'api'); $p = $period(1);
$assert($h['jumlah_terbit'] === 1 && (int) $q("SELECT total FROM bsa_tagihan WHERE santri_id=$s1 AND periode='$p'") === 300000, 'Nominal/komponen kiriman palsu diabaikan: tarif 350.000 − potongan 50.000 = 300.000');
$assert((int) $q("SELECT COUNT(*) FROM bsa_tagihan WHERE periode='$p'") === 1, 'Manipulasi komponen tidak memperluas sasaran');

$x = $input($a, $s1, 2); $before = $count();
$db->query("CREATE TRIGGER v6ss_audit_fail BEFORE INSERT ON audit_logs FOR EACH ROW BEGIN IF NEW.action='bsa.tagihan.terbit' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic audit failure'; END IF; END");
try { $assert($reject(static fn () => $tag->terbitkanMassal($a,$x,'api')) === 503 && $count() === $before, 'Audit wajib gagal: seluruh tagihan/snapshot/batch/klaim/notifikasi ikut rollback'); }
finally { $db->query('DROP TRIGGER v6ss_audit_fail'); }
$h = $tag->terbitkanMassal($a,$x,'api'); $again = $tag->terbitkanMassal($a,$x,'api');
$assert($h['status'] === 201 && $again['replayed'] && $h['id'] === $again['id'], 'Sesudah rollback: kunci sama baru sekali berhasil, berikutnya replay');

$x = $input($a,$s1,3); $r = $run(array_fill(0,6,[$a,$x])); $p = $period(3);
$assert(count(array_filter($r,static fn ($v) => $v['ok'])) === 6 && count(array_unique(array_column($r,'id'))) === 1 && count(array_filter($r,static fn ($v) => $v['replayed'] ?? false)) === 5,
    'Enam proses satu santri/kunci sama: satu penerbitan, lima replay, tanpa 500');
$assert((int) $q("SELECT COUNT(*) FROM bsa_tagihan WHERE periode='$p'") === 1, 'Enam kiriman hanya membuat satu tagihan pada santri sasaran');

$x = $input($a,$s1,4); $y = $input($b,$s1,4); $r = $run([[$a,$x],[$b,$y]]); $p = $period(4);
$assert(count(array_filter($r,static fn ($v) => $v['ok'])) === 1 && count(array_filter($r,static fn ($v) => !$v['ok'] && in_array($v['status'] ?? 0,[409,422],true))) === 1,
    'Dua aktor/kunci berbeda untuk satu santri-periode: satu sukses, satu konflik aman');
$assert((int) $q("SELECT COUNT(*) FROM bsa_tagihan WHERE periode='$p'") === 1, 'Benturan aktor tidak menggandakan tagihan atau batch efektif');

$r = $run([[$a,$input($a,$s1,5)],[$b,$input($b,$other,5)]]); $p = $period(5);
$assert(count(array_filter($r,static fn ($v) => $v['ok'])) === 2 && (int) $q("SELECT COUNT(*) FROM bsa_tagihan WHERE periode='$p'") === 2, 'Dua santri berbeda bersamaan: dua sasaran tepat tanpa perluasan ke unit');

$single = $input($a,$s1,6); $mass = $input($b,0,6); $r = $run([[$a,$single],[$b,$mass]]); $p = $period(6);
$assert(count(array_filter($r,static fn ($v) => $v['ok'])) === 1 && count(array_filter($r,static fn ($v) => !$v['ok'] && in_array($v['status'] ?? 0,[409,422],true))) === 1,
    'Satu santri vs massal bersamaan: satu menang, satu diminta tinjau ulang tanpa 500');
$new = $input($b,0,6); $preview = $tag->pratinjau($b,$new);
if ($preview['jumlah']['Terbit'] > 0) { $tag->terbitkanMassal($b,$new,'api'); }
$assert((int) $q("SELECT COUNT(*) FROM bsa_tagihan WHERE periode='$p'") === count($preview['rows']) && (int) $q("SELECT COUNT(*) FROM bsa_tagihan WHERE periode='$p' AND santri_id=$s2") === 0,
    'Sesudah tinjau ulang massal: semua SMP tepat sekali, Tsanawi tidak tertagih');

$tasks = $db->query("SELECT id,jenjang FROM bendahara_bulanan_assignments WHERE pengurus_id=(SELECT pengurus_id FROM users WHERE id={$a['id']}) AND is_active=1")->fetch_all(MYSQLI_ASSOC);
$db->query("UPDATE bendahara_bulanan_assignments SET jenjang='Tsanawi' WHERE pengurus_id=(SELECT pengurus_id FROM users WHERE id={$a['id']}) AND is_active=1");
$before = $count();
try { $assert($reject(static fn () => $tag->terbitkanMassal($a,$single,'api')) === 404 && $count() === $before, 'Cakupan dipersempit: replay satu santri SMP 404 tanpa mutasi'); }
finally { foreach ($tasks as $t) { $s = $db->prepare('UPDATE bendahara_bulanan_assignments SET jenjang=? WHERE id=?'); $s->bind_param('si',$t['jenjang'],$t['id']);$s->execute();$s->close(); } }

$x = $input($a,$s1,7); $p = $period(7);
$tag->buatTarif($a,['unit'=>'SMP','berlaku_mulai'=>$p,'idempotency_key'=>$key(),'komponen'=>[['nama'=>'Tarif baru','nominal'=>'400000']],'alasan'=>'Perubahan tarif sintetis'],'api');
$assert($reject(static fn () => $tag->terbitkanMassal($a,$x,'api')) === 409 && (int) $q("SELECT COUNT(*) FROM bsa_tagihan WHERE periode='$p'") === 0, 'Tarif diganti sesudah pratinjau: hash basi ditolak tanpa tagihan');
$h = $tag->terbitkanMassal($a,$input($a,$s1,7),'api');
$old = $period(1);
$assert($h['jumlah_terbit'] === 1 && (int) $q("SELECT total FROM bsa_tagihan WHERE santri_id=$s1 AND periode='$p'") === 350000 && (int) $q("SELECT total FROM bsa_tagihan WHERE santri_id=$s1 AND periode='$old'") === 300000,
    'Pratinjau baru memakai tarif baru; snapshot tagihan lama tetap Rp300.000');

exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/bin/v6_phase3_verify.php').' 2>&1',$out,$code);
$assert($code === 0,'Verifier Fase 1–3 lulus setelah konkurensi dan rollback');
echo "Ringkasan: $ok lulus, $bad gagal\n"; exit($bad ? 1 : 0);
