<?php

declare(strict_types=1);

// Probe independen auditor. Hanya fixture sintetis; perubahan negatif dipulihkan.
if (getenv('V7_RUN_AUDIT') !== '1' || !preg_match('/^test_[A-Za-z0-9_]*_test$/D', (string) getenv('DB_NAME'))) {
    fwrite(STDERR, "Gunakan V7_RUN_AUDIT=1 DB_NAME=test_*_test.\n");
    exit(2);
}
$root = dirname(__DIR__);
require_once $root . '/app/bootstrap.php';
$db = app_db();
if (($argv[1] ?? '') === '--assign') {
    echo $db->thread_id . PHP_EOL;
    flush();
    try {
        $r = izin_wali_service()->tetapkanPembimbing(['id' => (int) $argv[2]], (int) $argv[3], (int) $argv[4],
            'Penetapan fixture audit', 1, $argv[5], ['ip' => null, 'user_agent' => 'audit-v7']);
        echo json_encode(['http' => 200, 'id' => $r['id']]) . PHP_EOL;
    } catch (\App\Izin\IzinException $e) {
        echo json_encode(['http' => $e->status()]) . PHP_EOL;
    } catch (Throwable $e) {
        echo json_encode(['http' => 500, 'error' => $e->getMessage()]) . PHP_EOL;
    }
    exit;
}
$ok = $bad = 0;
$assert = static function (bool $pass, string $label) use (&$ok, &$bad): void {
    echo ($pass ? '[lulus] ' : '[gagal] ') . $label . PHP_EOL;
    $pass ? $ok++ : $bad++;
};
$value = static fn (string $q): mixed => $db->query($q)->fetch_row()[0];
$run = static function (array $args): array {
    $p = proc_open($args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    return [proc_close($p), $out];
};
$dir = sys_get_temp_dir() . '/v7-audit-' . bin2hex(random_bytes(5));
mkdir($dir, 0700);
register_shutdown_function(static function () use ($dir): void {
    foreach (glob($dir . '/*') ?: [] as $f) { unlink($f); }
    rmdir($dir);
});
$manifest = $dir . '/manifest.json';
[$code] = $run([PHP_BINARY, $root . '/bin/v7_phase1_preflight.php', $manifest]);
$m = json_decode((string) file_get_contents($manifest), true);
[$verify] = $run([PHP_BINARY, $root . '/bin/v7_phase1_verify.php', $manifest]);
$assert($code === 0 && $verify === 0, 'Kontrol: manifest asli dan verifier lulus pada fixture sehat');
foreach (['kosong', 'satu jumlah hilang', 'satu hash hilang'] as $case) {
    $x = $m;
    if ($case === 'kosong') { $x['legacy_counts'] = $x['id_fingerprints'] = []; }
    elseif ($case === 'satu jumlah hilang') { unset($x['legacy_counts']['santri_wali']); }
    else { unset($x['id_fingerprints']['santri_wali_relasi']); }
    $f = $dir . '/rusak.json';
    file_put_contents($f, json_encode($x));
    [$code] = $run([PHP_BINARY, $root . '/bin/v7_phase1_verify.php', $f]);
    $assert($code === 1, 'Manifest ' . $case . ' ditolak');
}
$rid = (int) $value('SELECT MIN(id) FROM izin_riwayat_status');
$old = $value('SELECT alasan FROM izin_riwayat_status WHERE id = ' . $rid);
try {
    $db->query("UPDATE izin_riwayat_status SET alasan = 'Kontrol perubahan riwayat audit' WHERE id = $rid");
    [$code] = $run([PHP_BINARY, $root . '/bin/v7_phase1_verify.php', $manifest]);
    $assert($code === 1, 'Perubahan isi riwayat V2 dengan ID/jumlah tetap terdeteksi manifest');
} finally {
    $s = $db->prepare('UPDATE izin_riwayat_status SET alasan = ? WHERE id = ?');
    $s->bind_param('si', $old, $rid); $s->execute(); $s->close();
}
if (stripos((string) $value('SELECT VERSION()'), 'mariadb') !== false) {
    $php = 'require ' . var_export($root . '/app/bootstrap.php', true)
        . '; app_db()->query("SET SESSION check_constraint_checks=OFF"); $argv=["preflight"]; require '
        . var_export($root . '/bin/v7_phase1_preflight.php', true) . ';';
    [$code] = $run([PHP_BINARY, '-r', $php]);
    $assert($code === 1, 'Preflight menolak CHECK sesi OFF meskipun versi MariaDB mendukung');
    $assert((int) $value('SELECT @@session.check_constraint_checks') === 1, 'CHECK koneksi induk tetap ON; default global tidak diubah');
}

// Pembuatan memakai tanggal berbeda per eksekusi agar probe dapat diulang.
$nonce = bin2hex(random_bytes(5));
$admin = (int) $value("SELECT id FROM users WHERE username='sbx_admin'");
$wali = ['id' => (int) $value("SELECT id FROM users WHERE username='sbx_v7_wali_a'")];
$santri = (int) $value("SELECT id FROM santri WHERE nis='V7S0003'");
$pengurus = (int) $value("SELECT pengurus_id FROM users WHERE username='sbx_v7_pembimbing_dua'");
$assignment = (int) $value("SELECT MIN(id) FROM pembimbing_assignments WHERE pengurus_id=$pengurus");
$day = (int) $value("SELECT COUNT(*) FROM izin_pengajuan WHERE asal_pengajuan='Wali'") + 1;
$base = (new DateTimeImmutable('2031-01-01'))->modify('+' . $day . ' days');
$create = static function (int $offset) use ($base, $wali, $santri, $nonce): int {
    $d = $base->modify('+' . $offset . ' days')->format('Y-m-d');
    return (int) izin_wali_service()->buat($wali, ['santri_id' => $santri, 'tgl_izin' => $d, 'tgl_kembali' => $d,
        'alasan' => 'Fixture audit konkurensi'], 'audit-create-' . $nonce . '-' . $offset, ['ip' => null, 'user_agent' => null])['id'];
};
$waitFor = static function (int $thread, string $fragment) use ($db): bool {
    $until = microtime(true) + 8;
    while (microtime(true) < $until) {
        $row = $db->query('SELECT INFO FROM information_schema.PROCESSLIST WHERE ID=' . $thread)->fetch_row();
        if ($row && str_contains((string) $row[0], $fragment)) { return true; }
        usleep(20000);
    }
    return false;
};
$start = static function (int $id, string $key) use ($admin, $pengurus, $root): array {
    $p = proc_open([PHP_BINARY, __FILE__, '--assign', (string) $admin, (string) $id, (string) $pengurus, $key],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    $thread = (int) fgets($pipes[1]);
    return [$p, $pipes, $thread];
};
$finish = static function (array $w): array {
    [$p, $pipes] = $w;
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    proc_close($p);
    return json_decode(trim($out), true) ?? ['http' => 500, 'error' => $err];
};
$id = $create(0);
try {
    $db->begin_transaction();
    $db->query("SELECT id FROM users WHERE id=$admin FOR UPDATE");
    $w = $start($id, 'audit-disabled-' . $nonce);
    $waiting = $waitFor($w[2], 'SELECT id FROM users');
    $assert($waiting, 'Kontrol: penetapan admin sudah lolos pemeriksaan awal dan menunggu kunci akun');
    $db->query("UPDATE users SET is_active=0 WHERE id=$admin");
    $db->commit();
    $r = $finish($w);
    $assert($r['http'] === 403 && (int) $value("SELECT version FROM izin_pengajuan WHERE id=$id") === 1,
        'Admin dicabut sebelum mendapat kunci → 403 tanpa penetapan (HTTP ' . $r['http'] . ')');
} finally {
    $db->rollback();
    $db->query("UPDATE users SET is_active=1 WHERE id=$admin");
}
$id = $create(1);
$oldEnd = $value("SELECT tanggal_selesai FROM pembimbing_assignments WHERE id=$assignment");
try {
    $db->begin_transaction();
    $db->query("SELECT id FROM pembimbing_assignments WHERE id=$assignment FOR UPDATE");
    $w = $start($id, 'audit-expired-' . $nonce);
    $waiting = $waitFor($w[2], 'SELECT id FROM pembimbing_assignments');
    $assert($waiting, 'Kontrol: penetapan membaca kandidat lama lalu menunggu kunci penugasan');
    // Tetap >= tanggal_mulai agar perubahan benar-benar diterima CHECK.
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $changed = $db->query("UPDATE pembimbing_assignments SET tanggal_selesai='$yesterday' WHERE id=$assignment");
    $assert($changed && $value("SELECT tanggal_selesai FROM pembimbing_assignments WHERE id=$assignment") === $yesterday,
        'Kontrol: tanggal berakhir kemarin benar-benar tersimpan (CHECK tetap aktif)');
    $db->commit();
    $r = $finish($w);
    $assert($r['http'] === 422 && (int) $value("SELECT version FROM izin_pengajuan WHERE id=$id") === 1,
        'Penugasan kedaluwarsa sebelum mendapat kunci → 422 tanpa penetapan (HTTP ' . $r['http'] . ')');
} finally {
    $db->rollback();
    $s = $db->prepare('UPDATE pembimbing_assignments SET tanggal_selesai=? WHERE id=?');
    $s->bind_param('si', $oldEnd, $assignment); $s->execute(); $s->close();
}
[$code] = $run([PHP_BINARY, $root . '/bin/v7_phase1_verify.php']);
$assert($code === 0, 'Verifier invarians lulus sesudah kontrol negatif dipulihkan');
echo "Ringkasan: $ok lulus, $bad gagal\n";
exit($bad === 0 ? 0 : 1);
