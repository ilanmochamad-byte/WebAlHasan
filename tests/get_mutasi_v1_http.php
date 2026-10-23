<?php

declare(strict_types=1);

// HTTP probes for V1 admin mutations moved from GET to POST + CSRF.
// Synthetic _test DB and a disposable source copy only; never production.
if (PHP_SAPI !== 'cli' || getenv('GETMUTASI_RUN_TESTS') !== '1') exit(77);
require_once dirname(__DIR__) . '/app/bootstrap.php';
if (app_config('env') !== 'testing' || !str_ends_with((string) app_config('database.database'), '_test')) exit(2);
$base = (string) getenv('GETMUTASI_TEST_URL');
$webRoot = realpath((string) getenv('GETMUTASI_WEB_ROOT'));
if (!preg_match('#^http://127\.0\.0\.1:\d+$#D', $base) || $webRoot === false) exit(2);
if (is_dir($webRoot . '/.git')) {
    exit("GETMUTASI_WEB_ROOT must be a disposable source copy, not the Git checkout.\n");
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = app_db();
$failed = 0; $passed = 0;
$ok = static function (bool $value, string $label) use (&$failed, &$passed): void {
    $value ? $passed++ : $failed++;
    echo ($value ? '[lulus] ' : '[gagal] ') . $label . "\n";
};
$jars = [];
$request = static function (string $actor, string $path, string $method = 'GET', ?array $body = null) use ($base, &$jars): array {
    $jars[$actor] ??= tempnam(sys_get_temp_dir(), 'getmutasi-cookie-');
    chmod($jars[$actor], 0600);
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIEFILE => $jars[$actor], CURLOPT_COOKIEJAR => $jars[$actor],
        CURLOPT_TIMEOUT => 15, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_NOBODY => $method === 'HEAD']);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($body));
    $raw = curl_exec($ch);
    if ($raw === false) throw new RuntimeException('Local HTTP transport failed.');
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['status' => $status, 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
};
$snapshot = static function () use ($db): array {
    $tables = array_column($db->query("SELECT TABLE_NAME FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetch_all(MYSQLI_ASSOC), 'TABLE_NAME');
    $result = [];
    foreach ($tables as $table) {
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $table)) throw new RuntimeException('Invalid table identifier.');
        // Session/login bookkeeping changes per request; it is not a target table.
        if (in_array($table, ['users', 'audit_logs', 'api_tokens'], true)) continue;
        $rows = $db->query('SELECT * FROM `' . $table . '`')->fetch_all(MYSQLI_ASSOC);
        $encoded = array_map(static fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR), $rows);
        sort($encoded, SORT_STRING);
        $result[$table] = count($rows) . ':' . hash('sha256', implode("\n", $encoded));
    }
    return $result;
};
$row = static function (string $sql) use ($db): ?array {
    return $db->query($sql)->fetch_assoc() ?: null;
};
$csrfFrom = static function (string $body): string {
    return preg_match('/name="_csrf" value="([^"]+)"/', $body, $m) ? $m[1] : '';
};

// Additive synthetic fixture: PSB applicants/payments, news, gallery, download rows and files.
$tag = 'GM-' . bin2hex(random_bytes(2)); // no_pendaftaran is varchar(20)
$files = [];
$touch = static function (string $relative) use ($webRoot, &$files): string {
    $path = $webRoot . '/' . $relative;
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
    file_put_contents($path, 'synthetic');
    $files[] = $path;
    return $path;
};
$insertPsb = static function (string $suffix, string $status, bool $payment = true) use ($db, $tag): int {
    $no = $tag . '-' . $suffix;
    $stmt = $db->prepare("INSERT INTO psb_pendaftar (no_pendaftaran,nama_lengkap,nisn,nik,tempat_lahir,tgl_lahir,jenis_kelamin,
        alamat_jalan,desa,kecamatan,kab_kota,provinsi,nama_ayah,nama_ibu,no_hp_wali,sekolah_asal,jenjang_tujuan,status)
        VALUES (?,?,?,?,'Test','2014-01-01','L','Test','Test','Test','Test','Test',
        'Synthetic father','Synthetic mother','0800000000','Test','SMP Terpadu Al Hasan',?)");
    $name = 'Synthetic ' . $tag . ' ' . $suffix;
    $nisn = sprintf('9%09d', random_int(0, 999999999));
    $nik = sprintf('9%015d', random_int(0, PHP_INT_MAX) % 10 ** 15);
    $stmt->bind_param('sssss', $no, $name, $nisn, $nik, $status);
    $stmt->execute() || throw new RuntimeException('Synthetic fixture insert failed.');
    $id = (int) $db->insert_id;
    // hapus_siswa.php (unchanged) deletes only psb_pendaftar, so its target gets no payment row.
    if (!$payment) return $id;
    $stmt = $db->prepare("INSERT INTO psb_pembayaran (no_pendaftaran,kategori_biaya,syahriyyah,infaq,rincian_wajib,total_wajib,total_keseluruhan)
        VALUES (?,'Synthetic',100,100,'Synthetic',200,200)");
    $stmt->bind_param('s', $no);
    $stmt->execute() || throw new RuntimeException('Synthetic fixture insert failed.');
    return $id;
};

try {
    $psb = ['hapus' => $insertPsb('HAPUS', 'Baru', false), 'jaga' => $insertPsb('JAGA', 'Baru'),
        'terima' => $insertPsb('TERIMA', 'Baru'), 'migrasi' => $insertPsb('MIGRASI', 'Diterima')];
    $bayar = [];
    foreach (['terima', 'jaga'] as $key) {
        $bayar[$key] = (int) $row("SELECT pb.id FROM psb_pembayaran pb JOIN psb_pendaftar p ON p.no_pendaftaran=pb.no_pendaftaran WHERE p.id=" . $psb[$key])['id'];
    }
    $content = [];
    foreach (['berita' => ['gambar', 'admin/gambar/'], 'galeri' => ['gambar', 'gambar_galeri/'], 'download' => ['nama_file', 'admin/file_download/']] as $table => [$col, $dir]) {
        foreach (['hapus', 'jaga', 'jalan'] as $key) {
            $name = strtolower($tag) . '-' . $table . '-' . $key . '.txt';
            if ($key === 'jalan') $name = '../' . strtolower($tag) . '-' . $table . '-canary.txt';
            if ($key !== 'jalan') $touch($dir . $name);
            if ($table === 'berita') {
                $stmt = $db->prepare("INSERT INTO berita (judul, isi_berita, gambar) VALUES (?, 'Synthetic', ?)");
            } elseif ($table === 'galeri') {
                $stmt = $db->prepare("INSERT INTO galeri (judul, keterangan, gambar) VALUES (?, 'Synthetic', ?)");
            } else {
                $stmt = $db->prepare("INSERT INTO download (judul, nama_file) VALUES (?, ?)");
            }
            $title = $tag . ' ' . $key;
            $stmt->bind_param('ss', $title, $name);
            $stmt->execute() || throw new RuntimeException('Synthetic fixture insert failed.');
            $content[$table][$key] = ['id' => (int) $db->insert_id, 'file' => $webRoot . '/' . $dir . $name];
        }
        // Canary one level above the upload folder: the traversal name points at it.
        $parent = dirname(rtrim($dir, '/'));
        $canary = strtolower($tag) . '-' . $table . '-canary.txt';
        $content[$table]['canary'] = $touch($parent === '.' ? $canary : $parent . '/' . $canary);
    }

    $r = $request('admin', '/portal/index.php');
    $r = $request('admin', '/admin/cek_login.php', 'POST', ['_csrf' => $csrfFrom($r['body']),
        'username' => 'sbx_admin', 'password' => 'Sandbox#123']);
    $ok(in_array($r['status'], [302, 303], true), 'Login fixture admin');
    $r = $request('pengurus', '/portal/index.php');
    $request('pengurus', '/admin/cek_login.php', 'POST', ['_csrf' => $csrfFrom($r['body']),
        'username' => 'sbx_pengurus_a', 'password' => 'Sandbox#123']);

    // Pages render POST forms with CSRF and no mutating links.
    $pages = ['/admin/admin_data.php' => ['hapus_siswa.php?', 'proses_psb_admin.php?act='],
        '/admin/admin_pembayaran_psb.php' => ['admin_pembayaran_psb.php?act='],
        '/admin/admin_berita.php' => ['proses_berita.php?act=hapus&'],
        '/admin/admin_galeri.php' => ['admin_galeri.php?hapus='],
        '/admin/admin_download.php' => ['admin_download.php?hapus=']];
    $csrf = '';
    foreach ($pages as $page => $needles) {
        $r = $request('admin', $page);
        $csrf = $csrf ?: $csrfFrom($r['body']);
        $hasLink = false;
        foreach ($needles as $needle) $hasLink = $hasLink || str_contains($r['body'], $needle);
        $ok($r['status'] === 200 && !$hasLink && str_contains($r['body'], 'method="post"')
            && str_contains($r['body'], 'name="_csrf"'), 'Halaman ' . $page . ' memakai formulir POST + CSRF, tanpa tautan bermutasi');
    }
    // Redesain web Tahap 3 (K-5/K-6): keputusan pindah ke panel detail (?id=).
    $r = $request('admin', '/admin/admin_data.php?id=' . $psb['terima']);
    $m = $request('admin', '/admin/admin_data.php?id=' . $psb['migrasi']);
    $ok(str_contains($r['body'], 'id="formStatusTerima' . $psb['terima'] . '"')
        && str_contains($r['body'], 'id="formStatusTolak' . $psb['terima'] . '"')
        && str_contains($m['body'], 'name="act" value="migrasi"'), 'admin_data menampilkan tombol terima/tolak/migrasi sebagai POST');
    $r = $request('admin', '/admin/admin_pembayaran_psb.php?status=Belum+Lunas&id=' . $bayar['terima']);
    $ok($r['status'] === 200 && str_contains($r['body'], 'name="act" value="lunas"'), 'Filter GET pembayaran PSB tetap tersedia');
    $ok($csrf !== '', 'Token CSRF admin tersedia');

    // Every legacy GET mutation (and injection payloads) is refused with 405 and changes nothing.
    $before = $snapshot();
    $legacy = [
        '/admin/hapus_siswa.php?id=' . $psb['hapus'],
        '/admin/hapus_siswa.php?id=' . rawurlencode("0' OR '1'='1"),
        '/admin/hapus_siswa.php?id[]=1',
        '/admin/proses_psb_admin.php?act=status&id=' . $psb['terima'] . '&val=Diterima',
        '/admin/proses_psb_admin.php?act=status&id=' . $psb['terima'] . '&val=' . rawurlencode("x', nama_lengkap='pwn"),
        '/admin/proses_psb_admin.php?act=migrasi&id=' . $psb['migrasi'],
        '/admin/admin_pembayaran_psb.php?act=lunas&id=' . $bayar['terima'],
        '/admin/admin_pembayaran_psb.php?act=batal&id=' . $bayar['terima'],
        '/admin/proses_berita.php?act=hapus&id=' . $content['berita']['hapus']['id'] . '&gbr=' . rawurlencode('../' . basename($content['berita']['canary'])),
        '/admin/proses_berita.php?act=hapus&id=' . rawurlencode("0' OR '1'='1"),
        '/admin/admin_galeri.php?hapus=' . $content['galeri']['hapus']['id'] . '&file=' . rawurlencode('../' . basename($content['galeri']['canary'])),
        '/admin/admin_download.php?hapus=' . $content['download']['hapus']['id'] . '&file=' . rawurlencode('../' . basename($content['download']['canary'])),
        '/admin/admin_download.php?hapus=' . rawurlencode("0' OR '1'='1"),
    ];
    foreach ($legacy as $path) {
        foreach (['GET', 'HEAD'] as $method) {
            $r = $request('admin', $path, $method);
            $ok($r['status'] === 405 && str_contains($r['headers'], 'Allow: POST'), $method . ' lama ditolak 405 ' . strtok($path, '?'));
        }
    }
    foreach (['PUT', 'DELETE'] as $method) {
        $ok($request('admin', '/admin/hapus_siswa.php?id=' . $psb['hapus'], $method)['status'] === 405, $method . ' hapus_siswa ditolak 405');
    }
    $ok($snapshot() === $before, 'GET/HEAD/PUT/DELETE lama tidak mengubah satu tabel pun');
    $intact = true;
    foreach ($content as $table => $set) {
        $intact = $intact && is_file($set['hapus']['file']) && is_file($set['jaga']['file']) && is_file($set['canary']);
    }
    $ok($intact, 'GET lama tidak menghapus berkas unggahan maupun berkas canary');

    // POST without/with invalid CSRF → 419, no change. Non-admin → 403, anonymous → login.
    $posts = [
        ['/admin/hapus_siswa.php', ['id' => $psb['hapus']]],
        ['/admin/proses_psb_admin.php', ['act' => 'status', 'id' => $psb['terima'], 'val' => 'Diterima']],
        ['/admin/proses_psb_admin.php', ['act' => 'migrasi', 'id' => $psb['migrasi']]],
        ['/admin/admin_pembayaran_psb.php', ['act' => 'lunas', 'id' => $bayar['terima']]],
        ['/admin/proses_berita.php?act=hapus', ['id' => $content['berita']['hapus']['id']]],
        ['/admin/admin_galeri.php', ['hapus' => $content['galeri']['hapus']['id']]],
        ['/admin/admin_download.php', ['hapus' => $content['download']['hapus']['id']]],
    ];
    foreach ($posts as [$path, $body]) {
        $ok($request('admin', $path, 'POST', $body)['status'] === 419, 'POST tanpa CSRF ditolak 419 ' . $path);
        $ok($request('admin', $path, 'POST', $body + ['_csrf' => str_repeat('0', 64)])['status'] === 419, 'POST CSRF salah ditolak 419 ' . $path);
        $ok($request('pengurus', $path, 'POST', $body + ['_csrf' => $csrf])['status'] === 403, 'Non-admin ditolak 403 ' . $path);
        $r = $request('anonymous', $path, 'POST', $body + ['_csrf' => $csrf]);
        $ok($r['status'] === 302 && str_contains($r['headers'], 'pesan=sesi'), 'Anonim wajib login ' . $path);
    }
    $r = $request('admin', '/admin/proses_psb_admin.php', 'POST', ['_csrf' => $csrf, 'act' => 'status', 'id' => $psb['terima'], 'val' => 'Dimigrasi']);
    $ok($r['status'] === 422, 'Status di luar daftar ditolak 422');
    $r = $request('admin', '/admin/proses_psb_admin.php', 'POST', ['_csrf' => $csrf, 'act' => 'status', 'id' => $psb['terima'], 'val' => "x', nama_lengkap='pwn"]);
    $ok($r['status'] === 422, 'Muatan injeksi status ditolak 422');
    $r = $request('admin', '/admin/hapus_siswa.php', 'POST', ['_csrf' => $csrf, 'id' => "0' OR '1'='1"]);
    $ok(in_array($r['status'], [302, 303], true), 'POST hapus_siswa dengan muatan injeksi diproses sebagai id 0');
    $ok($snapshot() === $before, 'POST ditolak/injeksi tidak mengubah satu tabel pun');

    // Valid POST changes exactly the target row; other rows stay intact.
    $count = static fn (string $sql): int => (int) $row($sql)['n'];
    $psbTotal = $count('SELECT COUNT(*) n FROM psb_pendaftar');
    $jagaBefore = $row('SELECT * FROM psb_pendaftar WHERE id=' . $psb['jaga']);
    $r = $request('admin', '/admin/hapus_siswa.php', 'POST', ['_csrf' => $csrf, 'id' => $psb['hapus']]);
    $ok(in_array($r['status'], [302, 303], true) && str_contains($r['headers'], 'Location: admin_data.php'), 'POST hapus_siswa sah dialihkan ke admin_data');
    $ok($row('SELECT id FROM psb_pendaftar WHERE id=' . $psb['hapus']) === null
        && $count('SELECT COUNT(*) n FROM psb_pendaftar') === $psbTotal - 1
        && $row('SELECT * FROM psb_pendaftar WHERE id=' . $psb['jaga']) === $jagaBefore, 'Hanya pendaftar target terhapus');

    $r = $request('admin', '/admin/proses_psb_admin.php', 'POST', ['_csrf' => $csrf, 'act' => 'status', 'id' => $psb['terima'], 'val' => 'Diterima']);
    $ok($r['status'] === 302 && $row('SELECT status FROM psb_pendaftar WHERE id=' . $psb['terima'])['status'] === 'Diterima'
        && $row('SELECT status FROM psb_pendaftar WHERE id=' . $psb['jaga'])['status'] === 'Baru', 'POST status sah hanya mengubah pendaftar target');
    $request('admin', '/admin/proses_psb_admin.php', 'POST', ['_csrf' => $csrf, 'act' => 'status', 'id' => $psb['terima'], 'val' => 'Ditolak']);
    $ok($row('SELECT status FROM psb_pendaftar WHERE id=' . $psb['terima'])['status'] === 'Ditolak', 'POST tolak sah tersimpan');

    $santriTotal = $count('SELECT COUNT(*) n FROM santri');
    $r = $request('admin', '/admin/proses_psb_admin.php', 'POST', ['_csrf' => $csrf, 'act' => 'migrasi', 'id' => $psb['migrasi']]);
    // Tahap 3: hasil tampil sebagai pesan di halaman tujuan (PRG), bukan alert().
    preg_match('/^Location:\s*(\S+)/mi', $r['headers'], $loc);
    $hasil = $request('admin', '/admin/' . ($loc[1] ?? 'admin_data.php'));
    $ok($r['status'] === 302 && str_contains($hasil['body'], 'Migrasi berhasil')
        && $row('SELECT status FROM psb_pendaftar WHERE id=' . $psb['migrasi'])['status'] === 'Dimigrasi'
        && $count('SELECT COUNT(*) n FROM santri') === $santriTotal + 1
        && $count("SELECT COUNT(*) n FROM santri WHERE nama_santri='Synthetic " . $tag . " MIGRASI' AND nis LIKE '" . date('y') . "01%'") === 1,
        'POST migrasi sah membuat satu santri dengan NIS prefiks lama');

    $jagaBayar = $row('SELECT * FROM psb_pembayaran WHERE id=' . $bayar['jaga']);
    $r = $request('admin', '/admin/admin_pembayaran_psb.php', 'POST', ['_csrf' => $csrf, 'act' => 'lunas', 'id' => $bayar['terima']]);
    $lunas = $row('SELECT status_pembayaran, waktu_lunas FROM psb_pembayaran WHERE id=' . $bayar['terima']);
    $ok($r['status'] === 302 && $lunas['status_pembayaran'] === 'Lunas' && $lunas['waktu_lunas'] !== null
        && $row('SELECT * FROM psb_pembayaran WHERE id=' . $bayar['jaga']) === $jagaBayar, 'POST lunas sah hanya mengubah tagihan target');
    // Redesain web Tahap 3, koreksi audit F-2: batal lunas wajib beralasan di
    // server. Tanpa alasan (atau hanya spasi) ditolak sebelum UPDATE; permintaan
    // sah kini menyertakan alasan.
    foreach ([[], ['alasan' => '   ']] as $tanpaAlasan) {
        $request('admin', '/admin/admin_pembayaran_psb.php', 'POST', ['_csrf' => $csrf, 'act' => 'batal', 'id' => $bayar['terima']] + $tanpaAlasan);
        $ok($row('SELECT status_pembayaran, waktu_lunas FROM psb_pembayaran WHERE id=' . $bayar['terima']) === $lunas,
            'POST batal lunas tanpa alasan ditolak; status dan waktu lunas tetap');
    }
    $request('admin', '/admin/admin_pembayaran_psb.php', 'POST', ['_csrf' => $csrf, 'act' => 'batal', 'id' => $bayar['terima'], 'alasan' => 'Sintetis GM']);
    $batal = $row('SELECT status_pembayaran, waktu_lunas FROM psb_pembayaran WHERE id=' . $bayar['terima']);
    $ok($batal['status_pembayaran'] === 'Belum Lunas' && $batal['waktu_lunas'] === null, 'POST batal lunas sah (beralasan) tersimpan');

    foreach (['berita' => ['/admin/proses_berita.php?act=hapus', 'id'], 'galeri' => ['/admin/admin_galeri.php', 'hapus'],
        'download' => ['/admin/admin_download.php', 'hapus']] as $table => [$path, $field]) {
        $set = $content[$table];
        $total = $count('SELECT COUNT(*) n FROM ' . $table);
        $r = $request('admin', $path, 'POST', ['_csrf' => $csrf, $field => $set['hapus']['id']]);
        $ok(in_array($r['status'], [200, 302], true) && $row('SELECT id FROM ' . $table . ' WHERE id=' . $set['hapus']['id']) === null
            && $count('SELECT COUNT(*) n FROM ' . $table) === $total - 1 && !is_file($set['hapus']['file'])
            && is_file($set['jaga']['file']), 'POST hapus ' . $table . ' sah: baris dan berkas target saja yang terhapus');
        $r = $request('admin', $path, 'POST', ['_csrf' => $csrf, $field => $set['jalan']['id']]);
        $ok($row('SELECT id FROM ' . $table . ' WHERE id=' . $set['jalan']['id']) === null && is_file($set['canary']),
            'Nama berkas traversal di DB ' . $table . ' tidak menghapus berkas di luar folder unggahan');
    }

    foreach (['/', '/psb.php', '/berita.php', '/galeri.php', '/download.php'] as $path) {
        $ok($request('anonymous', $path)['status'] === 200, 'Situs publik ' . $path);
    }
} finally {
    foreach ($files as $path) if (is_file($path)) unlink($path);
    foreach ($jars as $jar) if (is_file($jar)) unlink($jar);
}
echo "Ringkasan: {$passed} lulus, {$failed} gagal\n";
exit($failed === 0 ? 0 : 1);
