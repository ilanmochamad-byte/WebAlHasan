<?php

declare(strict_types=1);

/** Probe auditor: manifest terpotong, skema semu, dan kegagalan menulis manifest.
 * Jalankan SESUDAH uji rilis Fase 5 pada DB sintetis yang sama.
 * PKF5_PROBE_DB=test_..._test php tests/penugasan_keamanan_f5_probe.php
 * PKF5_PROBE_ROOT boleh menunjuk arsip handoff untuk kontrol kode asli.
 */
$root = getenv('PKF5_PROBE_ROOT') ?: dirname(__DIR__);
$nama = getenv('PKF5_PROBE_DB') ?: '';
if (!preg_match('/^test_[A-Za-z0-9_]+_test$/D', $nama)) {
    exit(2);
}
putenv('DB_NAME=' . $nama);
require_once $root . '/bin/_penugasan_keamanan_rilis.php';
$db = app_db();
$dir = sys_get_temp_dir() . '/pkf5-probe-' . bin2hex(random_bytes(5));
mkdir($dir, 0700);
$ok = 0;
$fail = 0;
$assert = static function (bool $pass, string $label) use (&$ok, &$fail): void {
    echo ($pass ? '[lulus] ' : '[gagal] ') . $label . PHP_EOL;
    $pass ? $ok++ : $fail++;
};
$run = static function (string $file, array $args = []) use ($root): array {
    $p = proc_open(array_merge([PHP_BINARY, $root . '/bin/' . $file], $args),
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    return [proc_close($p), $out];
};
$save = static function (array $m, string $file) use ($dir): string {
    $path = $dir . '/' . $file . '.json';
    file_put_contents($path, json_encode($m, JSON_THROW_ON_ERROR));
    return $path;
};
$verify = static fn (array $args = []): array => $run('penugasan_keamanan_verify.php', $args);
$manifest = $dir . '/dasar.json';
[$code] = $run('penugasan_keamanan_preflight.php', [$manifest]);
$m = json_decode((string) file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);
$assert($code === 0 && $verify([$manifest])[0] === 0 && ($m['isi_pk'][PK_TABEL]['baris'] ?? 0) > 0,
    'Dasar berisi penugasan: preflight dan verifier + manifest lulus');

// F-1. Perubahan sah bentuknya tetapi berbeda dari restore point hanya ada
// pada tabel 033; hash seluruh tabel lama tidak dapat mendeteksinya.
$row = $db->query('SELECT id, catatan FROM keamanan_assignments ORDER BY id LIMIT 1')->fetch_assoc();
$id = (int) $row['id'];
$s = $db->prepare('UPDATE keamanan_assignments SET catatan = ?, updated_at = updated_at WHERE id = ?');
$catatan = 'AUDIT-PKF5-perubahan';
$s->bind_param('si', $catatan, $id);
$s->execute();
try {
    $assert($verify([$manifest])[0] === 1, 'Manifest utuh mendeteksi perubahan isi 033');
    foreach (['hilang', 'sebagian', 'tipe-salah'] as $mode) {
        $broken = $m;
        if ($mode === 'hilang') {
            unset($broken['pk_terpasang']);
        } elseif ($mode === 'sebagian') {
            $broken['pk_terpasang'][PK_MIGRASI[1]] = false;
        } else {
            $broken['pk_terpasang'] = array_fill_keys(PK_MIGRASI, 'true');
        }
        $assert($verify([$save($broken, $mode)])[0] === 1, 'Status migrasi manifest ' . $mode . ' ditolak; isi 033 tidak dilewati');
    }
} finally {
    $catatan = $row['catatan'];
    $s->execute();
    $s->close();
}
$broken = $m;
unset($broken['isi_pk'][PK_TABEL]);
$assert($verify([$save($broken, 'hash-terpotong')])[0] === 1, 'Hash isi_pk yang terpotong ditolak');
$broken = $m;
$broken['pk_terpasang'] = array_fill_keys(PK_MIGRASI, false);
$assert($verify([$save($broken, 'pra-dengan-isi')])[0] === 1, 'Manifest berstatus pra-rilis tetapi memuat isi_pk pasca-rilis ditolak');
$broken['isi_pk'] = [];
$assert($verify([$save($broken, 'pra-palsu')])[0] === 1, 'Manifest pra-rilis dengan isi_pk kosong tidak membuktikan preservasi DB yang sudah berisi penugasan');
$assert($verify([$manifest])[0] === 0, 'Sesudah kontrol F-1 dipulihkan, manifest utuh lulus');

// F-2. Nama constraint tidak membuktikan relasi/ekspresi yang benar.
$db->query('ALTER TABLE keamanan_assignments DROP CONSTRAINT keamanan_range_check');
$db->query('ALTER TABLE keamanan_assignments ADD CONSTRAINT keamanan_range_check CHECK (1)');
try {
    $assert($verify()[0] === 1, 'CHECK bernama benar dengan ekspresi selalu benar ditolak');
} finally {
    $db->query('ALTER TABLE keamanan_assignments DROP CONSTRAINT keamanan_range_check');
    $db->query('ALTER TABLE keamanan_assignments ADD CONSTRAINT keamanan_range_check CHECK (tanggal_selesai IS NULL OR tanggal_selesai >= tanggal_mulai)');
}
$db->query('ALTER TABLE keamanan_assignments DROP FOREIGN KEY keamanan_pengurus_fk');
$db->query('ALTER TABLE keamanan_assignments ADD CONSTRAINT keamanan_pengurus_fk FOREIGN KEY (id) REFERENCES pengurus(id)');
try {
    $assert($verify()[0] === 1, 'FK bernama dan induk benar tetapi kolom lokal salah ditolak');
} finally {
    $db->query('ALTER TABLE keamanan_assignments DROP FOREIGN KEY keamanan_pengurus_fk');
    $db->query('ALTER TABLE keamanan_assignments ADD CONSTRAINT keamanan_pengurus_fk FOREIGN KEY (pengurus_id) REFERENCES pengurus(id)');
}
$assert($verify([$manifest])[0] === 0, 'Sesudah skema dipulihkan, verifier dan preservasi data lulus');

// F-3. Exit 0 harus berarti manifest privat tersimpan, bukan hanya DB sehat.
[$code, $out] = $run('penugasan_keamanan_preflight.php', [$dir . '/tidak-ada/manifest.json']);
$assert($code !== 0 && !str_contains($out, 'Manifest privat ditulis:'), 'Tujuan manifest tidak dapat ditulis: nonzero dan tidak mengklaim tersimpan');
[$code, $out] = $run('penugasan_keamanan_preflight.php', [$dir]);
$assert($code !== 0 && !str_contains($out, 'Manifest privat ditulis:'), 'Tujuan berupa direktori: nonzero dan tidak mengklaim tersimpan');
// Kode handoff bahkan chmod direktori tujuan menjadi 0600 setelah write gagal.
chmod($dir, 0700);
$normal = $dir . '/normal.json';
[$code] = $run('penugasan_keamanan_preflight.php', [$normal]);
$assert($code === 0 && (fileperms($normal) & 0777) === 0600 && $verify([$normal])[0] === 0,
    'Tujuan valid: manifest 0600 tersimpan dan dapat diverifikasi');
echo "Hasil: $ok lulus, $fail gagal\n";
exit($fail === 0 ? 0 : 1);
