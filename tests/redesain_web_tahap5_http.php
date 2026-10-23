<?php

declare(strict_types=1);

/**
 * Uji server redesain website Tahap 5 — pembinaan (V-1 s.d. V-7, M-4 · F, Z).
 *
 * Kriteria selesai Tahap 5 (cetak biru): tidak ada isian ID; kasus Rahasia
 * tetap tersembunyi; orang tua hanya bertambah melihat nama pembimbing.
 * Skrip menjalankan server PHP lokal sendiri di atas DB uji dari
 * `tests/redesain_web_tahap5_db.sh` dan memeriksa:
 *
 *   H5-A  akses per peran ke setiap layar (status HTTP, menu/tab aktif);
 *   H5-B  kasus Rahasia: tidak terbuka, tidak tampil, tidak terhitung bagi
 *         pembimbing lain dan murobi (papan, daftar, laporan, ringkasan,
 *         tindak lanjut di detail pelanggaran); publikasi ditolak server;
 *   H5-C  keputusan Z: orang tua melihat judul bertanggal, nama anak, dan
 *         nama pembimbing pengirim — web dan REST; satu kunci tambahan saja,
 *         tanpa isi internal; "Pesantren Al Hasan" bila tanpa pembimbing;
 *         wali lain ditolak; saringan anak/belum dibaca = basis data;
 *   H5-D  tidak ada isian ID di layar pembinaan; alamat lama ber-ID tetap jalan;
 *   H5-E  laporan V-6: saringan bernama, ringkasan = basis data, CSV dan REST
 *         tidak berubah bentuk;
 *   H5-F  V-1: chip = basis data, cari/periode, catat lewat panel, CSRF, murobi
 *         tidak dapat mencatat;
 *   H5-G  V-3/V-5: wizard tidak menerima santri luar cakupan; daftar publikasi
 *         sesuai cakupan;
 *   H5-H  M-4: khusus admin, riwayat berupa kalimat tanpa JSON mentah;
 *   H5-I  tidak ada galat/peringatan PHP di log server.
 *
 *   bash tests/redesain_web_tahap5_db.sh
 *   php tests/redesain_web_tahap5_http.php
 *
 * Skrip mengubah data uji (satu pelanggaran baru); bangun ulang DB sebelum
 * mengulang bersama uji browser.
 */

$root = dirname(__DIR__);
$db = getenv('TAHAP5_DB') ?: 'test_redesain_t5_test';
$port = (int) (getenv('TAHAP5_PORT') ?: 8982);
if (!preg_match('/^test_.*_test$/', $db)) {
    fwrite(STDERR, "Ditolak: DB uji harus bernama test_*_test.\n");
    exit(2);
}
putenv('DB_NAME=' . $db);
require_once $root . '/app/bootstrap.php';

$lulus = 0;
$gagal = [];
$assert = static function (bool $ok, string $pesan) use (&$lulus, &$gagal): void {
    echo ($ok ? '[lulus] ' : '[gagal] ') . $pesan . PHP_EOL;
    $ok ? $lulus++ : $gagal[] = $pesan;
};

$log = tempnam(sys_get_temp_dir(), 'tahap5-server-');
$server = proc_open(['php', '-S', '127.0.0.1:' . $port, $root . '/tests/redesain_web_tahap1_router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root, array_merge(getenv(), ['DB_NAME' => $db]));
register_shutdown_function(static function () use (&$server): void {
    if (is_resource($server)) {
        proc_terminate($server);
    }
});
for ($i = 0; $i < 50; $i++) {
    $sock = @fsockopen('127.0.0.1', $port);
    if ($sock !== false) {
        fclose($sock);
        break;
    }
    usleep(100000);
}

final class Klien
{
    private string $jar;

    public function __construct(private string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'tahap5-jar-');
    }

    /** @return array{status:int, body:string, location:?string, type:string} */
    public function req(string $path, ?array $post = null, array $headers = [], bool $json = false): array
    {
        $h = curl_init($this->base . $path);
        curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => $json ? array_merge($headers, ['Content-Type: application/json']) : $headers]);
        if ($post !== null) {
            curl_setopt($h, CURLOPT_POST, true);
            curl_setopt($h, CURLOPT_POSTFIELDS, $json ? json_encode($post) : http_build_query($post));
        }
        $raw = (string) curl_exec($h);
        $status = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
        $size = (int) curl_getinfo($h, CURLINFO_HEADER_SIZE);
        $type = (string) curl_getinfo($h, CURLINFO_CONTENT_TYPE);
        curl_close($h);
        preg_match('/^Location:\s*(.+)$/mi', substr($raw, 0, $size), $m);

        return ['status' => $status, 'body' => substr($raw, $size), 'location' => isset($m[1]) ? trim($m[1]) : null, 'type' => $type];
    }

    public function csrf(string $path = '/portal/index.php'): string
    {
        $r = $this->req($path);
        if (preg_match('/name="_csrf" value="([^"]+)"/', $r['body'], $m) !== 1) {
            throw new RuntimeException('Token CSRF tidak ditemukan pada ' . $path);
        }

        return $m[1];
    }

    public function login(string $user): void
    {
        $this->req('/admin/cek_login.php', ['_csrf' => $this->csrf(), 'username' => $user, 'password' => 'Sandbox#123']);
    }
}

$base = 'http://127.0.0.1:' . $port;
$klien = static function (string $user) use ($base): Klien {
    $k = new Klien($base);
    $k->login($user);

    return $k;
};
$teks = static fn (string $html): string => trim(html_entity_decode((string) preg_replace('/\s+/', ' ', (string) preg_replace('/<[^>]+>/', ' ', (string) preg_replace('#<(script|style)\b.*?</\1>#s', '', $html))), ENT_QUOTES));
$sql = static function (string $query): array {
    $r = app_db()->query($query);

    return $r === true ? [] : $r->fetch_all(MYSQLI_ASSOC);
};
$satu = static fn (string $query): mixed => array_values($sql($query)[0] ?? [null])[0];
$main = static fn (string $html): string => (string) substr($html, (int) strpos($html, '<main'));
$aktif = static function (string $html): ?string {
    $side = substr($html, (int) strpos($html, 'id="ah-sidebar"'));
    preg_match('/aria-current="page">\s*<i[^>]*><\/i>\s*<span>([^<]*)<\/span>/', $side, $m);

    return isset($m[1]) ? html_entity_decode($m[1], ENT_QUOTES) : null;
};
$tabModul = static function (string $html): ?string {
    preg_match('/ah-tabs--module.*?aria-current="page"><span>([^<]*)</s', $html, $m);

    return isset($m[1]) ? html_entity_decode($m[1], ENT_QUOTES) : null;
};

$id = [
    'a1Tsb' => (int) $satu("SELECT p.id FROM v3_pelanggaran p JOIN santri s ON s.id=p.santri_id JOIN v3_katalog k ON k.id=p.katalog_id WHERE s.nama_santri='SBX Santri A1' AND k.kode='SBX-TSB' ORDER BY p.id LIMIT 1"),
    'a1Bls' => (int) $satu("SELECT p.id FROM v3_pelanggaran p JOIN santri s ON s.id=p.santri_id JOIN v3_katalog k ON k.id=p.katalog_id WHERE s.nama_santri='SBX Santri A1' AND k.kode='SBX-BLS' ORDER BY p.id LIMIT 1"),
    'a2' => (int) $satu("SELECT p.id FROM v3_pelanggaran p JOIN santri s ON s.id=p.santri_id WHERE s.nama_santri='SBX Santri A2' ORDER BY p.id LIMIT 1"),
    'c1' => (int) $satu("SELECT p.id FROM v3_pelanggaran p JOIN santri s ON s.id=p.santri_id WHERE s.nama_santri='SBX Santri C1' ORDER BY p.id LIMIT 1"),
    'internal' => (int) $satu("SELECT id FROM v3_konseling_kasus WHERE kerahasiaan='Internal' ORDER BY id LIMIT 1"),
    'rahasia' => (int) $satu("SELECT id FROM v3_konseling_kasus WHERE kerahasiaan='Rahasia' ORDER BY id LIMIT 1"),
    'pubKasus' => (int) $satu('SELECT id FROM v3_publikasi WHERE kasus_id IS NOT NULL ORDER BY id LIMIT 1'),
    'pubPel' => (int) $satu('SELECT id FROM v3_publikasi WHERE pelanggaran_id IS NOT NULL AND ditarik_pada IS NULL ORDER BY id LIMIT 1'),
];
$assert(!in_array(0, $id, true), 'Fixture Tahap 5 tersedia: ' . json_encode($id));

$akun = ['sbx_admin', 'sbx_pengurus_a', 'sbx_pengurus_b', 'sbx_murobi_a', 'sbx_ortu_a', 'sbx_ortu_b', 'sbx_guru_biasa', 'sbx_pengurus_biasa'];
$k = [];
foreach ($akun as $u) {
    $k[$u] = $klien($u);
}

// ===================================================================== H5-A
echo PHP_EOL . '=== H5-A. Akses per peran ===' . PHP_EOL;
$matriks = [
    // alamat => admin, pengurus_a, pengurus_b, murobi_a, ortu_a, ortu_b, guru_biasa, pengurus_biasa
    '/portal/v3_pelanggaran.php' => [200, 200, 200, 200, 403, 403, 403, 403],
    '/portal/v3_pelanggaran.php?action=create' => [200, 200, 200, 200, 403, 403, 403, 403],
    '/portal/v3_pelanggaran_detail.php?id=' . $id['a1Bls'] => [200, 200, 200, 200, 403, 403, 403, 403],
    '/portal/v3_pelanggaran_detail.php?id=' . $id['c1'] => [200, 200, 403, 403, 403, 403, 403, 403],
    '/portal/v3_konseling.php' => [200, 200, 200, 200, 403, 403, 403, 403],
    '/portal/v3_konseling_detail.php?id=' . $id['internal'] => [200, 200, 200, 200, 403, 403, 403, 403],
    '/portal/v3_konseling_detail.php?id=' . $id['rahasia'] => [200, 200, 403, 403, 403, 403, 403, 403],
    '/portal/v3_konseling_cetak.php?id=' . $id['rahasia'] => [200, 200, 403, 403, 403, 403, 403, 403],
    '/portal/v3_publikasi_kelola.php' => [200, 200, 200, 403, 403, 403, 403, 403],
    '/portal/v3_publikasi_kelola.php?id=' . $id['pubKasus'] => [200, 200, 200, 403, 403, 403, 403, 403],
    '/portal/v3_laporan.php' => [200, 200, 200, 200, 200, 200, 403, 403],
    '/portal/v3_laporan.php?jenis=kasus' => [200, 200, 200, 200, 403, 403, 403, 403],
    '/portal/v3_publikasi.php' => [403, 403, 403, 403, 200, 200, 403, 403],
    '/portal/v3_publikasi.php?id=' . $id['pubKasus'] => [403, 403, 403, 403, 200, 403, 403, 403],
    '/admin/admin_v3_katalog.php' => [200, 403, 403, 403, 403, 403, 403, 403],
];
foreach ($matriks as $path => $harap) {
    $dapat = [];
    foreach ($akun as $i => $u) {
        $dapat[] = $k[$u]->req($path)['status'];
    }
    $assert($dapat === $harap, "$path → " . implode(',', $dapat) . ($dapat === $harap ? '' : ' (harap ' . implode(',', $harap) . ')'));
}
$h = $k['sbx_admin']->req('/portal/v3_konseling.php')['body'];
$assert($aktif($h) === 'Pembinaan' && $tabModul($h) === 'Konseling', 'Admin: menu Pembinaan › tab Konseling');
$h = $k['sbx_admin']->req('/admin/admin_v3_katalog.php')['body'];
$assert($tabModul($h) === 'Katalog & poin' && str_contains($h, 'id="ah-shell"'), 'M-4 di bawah Pembinaan › Katalog & poin, kerangka bersama');
$h = $k['sbx_murobi_a']->req('/portal/v3_laporan.php')['body'];
$assert($aktif($h) === 'Pembinaan binaan' && $tabModul($h) === 'Laporan', 'Murobi: Pembinaan binaan › tab Laporan (V-6)');
$h = $k['sbx_ortu_a']->req('/portal/v3_publikasi.php?baca=belum')['body'];
$assert($aktif($h) === 'Informasi pembinaan' && $tabModul($h) === 'Belum dibaca', 'Orang tua: menu "Informasi pembinaan" › tab Belum dibaca (F)');
$h = $k['sbx_ortu_a']->req('/portal/v3_laporan.php')['body'];
$assert($aktif($h) === 'Informasi pembinaan', 'Orang tua: rekap laporan tetap di bawah Informasi pembinaan');
$h = $k['sbx_pengurus_a']->req('/portal/v3_pelanggaran.php')['body'];
$assert(!preg_match('/\bV3\b|ledger|Agregat/i', $teks($main($h))), 'V-1 tanpa label versi/istilah teknis');

// ===================================================================== H5-B
echo PHP_EOL . '=== H5-B. Kasus Rahasia ===' . PHP_EOL;
$papan = static function (string $html): array {
    preg_match_all('/class="ah-board__head"[^>]*><span>([^<]+)<\/span><span class="ah-chips__count">(\d+)</', $html, $m);

    return array_combine($m[1], array_map('intval', $m[2]));
};
foreach (['sbx_admin' => true, 'sbx_pengurus_a' => true, 'sbx_pengurus_b' => false, 'sbx_murobi_a' => false] as $u => $boleh) {
    $h = $main($k[$u]->req('/portal/v3_konseling.php')['body']);
    $angka = array_sum($papan($h));
    $harap = $boleh ? 2 : 1;
    $assert(str_contains($h, 'SBX Santri A2') === $boleh && $angka === $harap, "$u papan: kasus Rahasia " . ($boleh ? 'tampil' : 'tidak tampil') . " dan angka kolom $angka = $harap");
    $d = $main($k[$u]->req('/portal/v3_konseling.php?tampil=daftar')['body']);
    $assert(str_contains($d, 'SBX Santri A2') === $boleh, "$u daftar: kasus Rahasia " . ($boleh ? 'tampil' : 'tidak tampil'));
    $l = $main($k[$u]->req('/portal/v3_laporan.php?jenis=kasus&periode=semua')['body']);
    $assert(str_contains($l, $harap . ' baris sesuai saringan') && str_contains($l, 'SBX Santri A2') === $boleh, "$u laporan kasus: $harap baris");
    preg_match('/Kasus berjalan<\/p><p class="ah-stat__value">(\d+)</', $l, $m);
    $assert((int) ($m[1] ?? -1) === $harap, "$u ringkasan \"Kasus berjalan\" = $harap");
    $v = $main($k[$u]->req('/portal/v3_pelanggaran_detail.php?id=' . $id['a2'] . '&tab=konseling')['body']);
    $assert(str_contains($v, 'Belum ditautkan ke kasus konseling.') === !$boleh, "$u detail pelanggaran A2: tindak lanjut Rahasia " . ($boleh ? 'tampil' : 'tidak tampil'));
    $assert(!str_contains($v . $d . $h, 'PRIVATE-T5-RAHASIA'), "$u tidak pernah melihat tujuan kasus Rahasia di daftar");
}
$v = $main($k['sbx_pengurus_a']->req('/portal/v3_pelanggaran_detail.php?id=' . $id['a2'])['body']);
$assert(str_contains($v, 'disabled aria-describedby="sebab-publikasi"') && str_contains($v, 'tidak dapat dipratinjau'), 'V-2: publikasi pelanggaran bertaut kasus Rahasia nonaktif dengan sebab dari server');
$v = $main($k['sbx_pengurus_a']->req('/portal/v3_konseling_detail.php?id=' . $id['rahasia'])['body']);
$assert(str_contains($v, 'Kasus Rahasia tidak dapat dipublikasikan') && str_contains($v, 'Kasus Rahasia tidak boleh dipratinjau'), 'V-4: catatan Rahasia dan tombol publikasi nonaktif beralasan');
$pa = $k['sbx_pengurus_a'];
$r = $pa->req('/portal/v3_publikasi_kelola.php', ['_csrf' => $pa->csrf('/portal/v3_publikasi_kelola.php'), 'aksi' => 'pratinjau', 'sumber_type' => 'kasus', 'sumber_id' => $id['rahasia'], 'sumber_version' => 1, 'ringkasan' => 'x', 'tindak_lanjut' => 'y', 'wali_ids' => [1]]);
$assert($r['status'] === 422 && (int) $satu('SELECT COUNT(*) FROM v3_publikasi_pratinjau WHERE sumber_type="kasus" AND sumber_id=' . $id['rahasia']) === 0, 'Server menolak pratinjau publikasi kasus Rahasia (422, tanpa baris pratinjau)');

// ===================================================================== H5-C
echo PHP_EOL . '=== H5-C. Keputusan Z: informasi pembinaan orang tua ===' . PHP_EOL;
$ortu = $k['sbx_ortu_a'];
$h = $main($ortu->req('/portal/v3_publikasi.php')['body']);
$daftar = substr($h, (int) strpos($h, 'ah-pub-list'));
$terbit = (int) $satu('SELECT COUNT(*) FROM v3_publikasi p JOIN users u ON u.wali_id=p.wali_id WHERE u.username="sbx_ortu_a" AND p.archived_at IS NULL');
$assert(substr_count($daftar, 'Informasi pembinaan · ') === $terbit && substr_count($teks($daftar), 'SBX Santri A1 · dari SBX Pengurus A (pembimbing)') === $terbit, "V-7 daftar: $terbit baris berjudul bertanggal dengan nama anak dan pengirim");
$assert(!preg_match('/pelanggaran|konseling|kasus|sesi/i', $teks(substr($daftar, 0, (int) strpos($daftar, '</ul>')))), 'V-7: jenis sumber tidak ditampilkan di daftar');
$assert(!str_contains($h, 'Santri #') && !str_contains($h, 'PRIVATE-T5'), 'V-7 tanpa "Santri #id" dan tanpa isi internal');
$belum = (int) $satu('SELECT COUNT(*) FROM v3_publikasi p JOIN users u ON u.wali_id=p.wali_id WHERE u.username="sbx_ortu_a" AND p.archived_at IS NULL AND p.dibaca_pada IS NULL AND p.ditarik_pada IS NULL');
$hb = $teks($main($ortu->req('/portal/v3_publikasi.php?baca=belum')['body']));
$assert(str_contains($hb, $belum . ' informasi'), "V-7 Belum dibaca: $belum = basis data");
$hd = $main($ortu->req('/portal/v3_publikasi.php?id=' . $id['pubKasus'])['body']);
$assert(str_contains($teks($hd), 'Dari SBX Pengurus A (pembimbing)') && str_contains($hd, 'Saya sudah membaca') && !preg_match('/konseling|kasus/i', $teks($hd)), 'V-7 detail: pengirim, tombol baca, tanpa jenis sumber');
$assert($k['sbx_ortu_b']->req('/portal/v3_publikasi.php?id=' . $id['pubKasus'])['status'] === 403, 'Wali lain tidak dapat membuka informasi (403)');
// REST: satu kunci tambahan "pengirim", allowlist lain tetap.
$api = new Klien($base);
$login = $api->req('/api/v1/auth/login', ['username' => 'sbx_ortu_a', 'password' => 'Sandbox#123', 'device_name' => 'uji-t5'], [], true);
$token = json_decode($login['body'], true)['data']['token'] ?? '';
try {
    $r = json_decode($api->req('/api/v1/v3/publikasi/' . $id['pubKasus'], null, ['Authorization: Bearer ' . $token])['body'], true);
    $row = $r['data']['publikasi'] ?? [];
    $assert(array_keys($row) === ['id', 'santri_id', 'ringkasan', 'tindak_lanjut', 'diterbitkan_pada', 'ditarik_pada', 'dibaca_pada', 'version', 'status', 'pengirim'] && $row['pengirim'] === 'SBX Pengurus A',
        'REST orang tua: hanya bertambah kunci "pengirim" = nama pembimbing');
    $semua = json_encode($api->req('/api/v1/v3/publikasi', null, ['Authorization: Bearer ' . $token])['body']);
    $assert(!str_contains($semua, 'PRIVATE-T5') && !str_contains($semua, 'pembimbing_id') && !str_contains($semua, 'tujuan'), 'REST daftar orang tua tanpa isi internal atau ID pembimbing');
    // Tanpa pembimbing pemilik sumber → "Pesantren Al Hasan".
    $sumber = (int) $satu('SELECT pelanggaran_id FROM v3_publikasi WHERE id=' . $id['pubPel']);
    $asal = (int) $satu('SELECT pembimbing_id FROM v3_pelanggaran WHERE id=' . $sumber);
    try {
        app_db()->query('UPDATE v3_pelanggaran SET pembimbing_id=NULL WHERE id=' . $sumber);
        $r = json_decode($api->req('/api/v1/v3/publikasi/' . $id['pubPel'], null, ['Authorization: Bearer ' . $token])['body'], true);
        $hw = $teks($main($ortu->req('/portal/v3_publikasi.php?id=' . $id['pubPel'])['body']));
        $assert(($r['data']['publikasi']['pengirim'] ?? '') === 'Pesantren Al Hasan' && str_contains($hw, 'Dari Pesantren Al Hasan') && !str_contains($hw, '(pembimbing)'), 'Tanpa pembimbing: pengirim "Pesantren Al Hasan" (web dan REST)');
    } finally {
        app_db()->query('UPDATE v3_pelanggaran SET pembimbing_id=' . $asal . ' WHERE id=' . $sumber);
    }
    $assert($api->req('/api/v1/v3/publikasi?santri_id=999999', null, ['Authorization: Bearer ' . $token])['status'] === 200
        && (json_decode($api->req('/api/v1/v3/publikasi?santri_id=999999', null, ['Authorization: Bearer ' . $token])['body'], true)['data']['total'] ?? -1) === 0, 'Saringan anak lain tidak membuka baris apa pun');
} finally {
    app_db()->query("DELETE FROM api_tokens WHERE name='uji-t5'");
}

// ===================================================================== H5-D
echo PHP_EOL . '=== H5-D. Tidak ada isian ID ===' . PHP_EOL;
$layar = [
    'sbx_admin' => ['/portal/v3_pelanggaran.php', '/portal/v3_pelanggaran_detail.php?id=' . $id['a1Bls'], '/portal/v3_konseling.php?tampil=daftar', '/portal/v3_konseling_detail.php?id=' . $id['internal'],
        '/portal/v3_publikasi_kelola.php', '/portal/v3_laporan.php', '/portal/v3_laporan.php?jenis=kasus', '/portal/v3_laporan.php?jenis=ambang', '/admin/admin_v3_katalog.php?action=create', '/admin/admin_v3_katalog.php?jenis=ambang&action=create'],
    'sbx_pengurus_a' => ['/portal/v3_pelanggaran.php?action=create', '/portal/v3_konseling.php?action=create', '/portal/v3_konseling.php?action=create&santri=1%7C1', '/portal/v3_publikasi_kelola.php?sumber_type=pelanggaran&sumber_id=' . $id['a1Tsb'], '/portal/v3_laporan.php'],
    'sbx_murobi_a' => ['/portal/v3_laporan.php'],
    'sbx_ortu_a' => ['/portal/v3_laporan.php', '/portal/v3_publikasi.php'],
];
foreach ($layar as $u => $alamat) {
    foreach ($alamat as $path) {
        $h = $main($k[$u]->req($path)['body']);
        preg_match_all('/<input\b(?![^>]*type="(?:hidden|checkbox)")[^>]*name="[a-z_]*_id(?:s\[\])?"[^>]*>/', $h, $m);
        $assert($m[0] === [] && !preg_match('/>\s*ID (santri|kelas|kamar|pembimbing|murobi)\s*</', $h), "$u $path: tanpa isian ID");
    }
}
$h = $main($k['sbx_admin']->req('/portal/v3_laporan.php')['body']);
$assert(str_contains($h, '<select class="form-select" id="kelas_id"') && str_contains($h, '<select class="form-select" id="kamar_id"') && str_contains($h, 'id="pembimbing_id"') && str_contains($h, 'id="murobi_id"') && str_contains($h, '>SBX Kamar A<'), 'V-6: kelas, kamar, pembimbing, murobi berupa pilihan bernama (admin)');
$h = $main($k['sbx_pengurus_a']->req('/portal/v3_laporan.php')['body']);
$assert(!str_contains($h, 'id="pembimbing_id"') && !str_contains($h, 'id="murobi_id"'), 'V-6: pilihan pembimbing/murobi hanya untuk admin');
$santriA1 = (int) $satu("SELECT id FROM santri WHERE nama_santri='SBX Santri A1'");
$h = $main($k['sbx_admin']->req('/portal/v3_laporan.php?jenis=pelanggaran&santri_id=' . $santriA1 . '&periode=semua')['body']);
$assert(str_contains($h, 'Laporan dibatasi pada satu santri') && !str_contains($h, 'SBX Santri A2') && str_contains($h, 'SBX Santri A1'), 'Alamat lama ber-santri_id tetap menyaring, dengan tautan "Tampilkan semua santri"');

// ===================================================================== H5-E
echo PHP_EOL . '=== H5-E. Laporan V-6 ===' . PHP_EOL;
$h = $main($k['sbx_admin']->req('/portal/v3_laporan.php?periode=semua')['body']);
$stat = static function (string $html, string $label): int {
    preg_match('/' . preg_quote($label, '/') . '<\/p><p class="ah-stat__value">(\d+)</', $html, $m);

    return (int) ($m[1] ?? -1);
};
$dbCatatan = (int) $satu("SELECT COUNT(*) FROM v3_pelanggaran p WHERE p.archived_at IS NULL AND p.status<>'Dibatalkan' AND NOT EXISTS(SELECT 1 FROM v3_pelanggaran nx WHERE nx.revisi_dari_id=p.id)");
$dbPoin = (int) $satu("SELECT COALESCE(SUM(p.poin_snapshot),0) FROM v3_pelanggaran p WHERE p.archived_at IS NULL AND p.status<>'Dibatalkan' AND NOT EXISTS(SELECT 1 FROM v3_pelanggaran nx WHERE nx.revisi_dari_id=p.id)");
$dbKasus = (int) $satu("SELECT COUNT(*) FROM v3_konseling_kasus WHERE archived_at IS NULL AND status IN ('Dibuka','Dalam Pendampingan')");
$dbAmbang = (int) $satu("SELECT COUNT(*) FROM v3_poin_agregat r JOIN tahun_ajaran ta ON ta.id=r.tahun_ajaran_id AND ta.status='Aktif' WHERE r.archived_at IS NULL AND r.total_poin>=(SELECT MIN(nilai_minimum) FROM v3_ambang a WHERE a.tahun_ajaran_id=r.tahun_ajaran_id AND a.is_active=1 AND a.archived_at IS NULL AND a.tanggal_mulai<=CURDATE() AND (a.tanggal_selesai IS NULL OR a.tanggal_selesai>=CURDATE()))");
$assert($stat($h, 'Catatan pelanggaran') === $dbCatatan && $stat($h, 'Total poin') === $dbPoin && $stat($h, 'Kasus berjalan') === $dbKasus && $stat($h, 'Santri ≥ ambang') === $dbAmbang,
    "Ringkasan admin = basis data (catatan $dbCatatan, poin $dbPoin, kasus $dbKasus, ambang $dbAmbang)");
$kamarA = (int) $satu("SELECT id FROM kamar WHERE nama_kamar='SBX Kamar A'");
$h = $main($k['sbx_admin']->req('/portal/v3_laporan.php?jenis=pelanggaran&periode=semua&kamar_id=' . $kamarA)['body']);
$assert(str_contains($h, 'SBX Santri A1') && !str_contains($h, 'SBX Santri C1') && str_contains($h, '>SBX Kamar A<'), 'Saringan kamar bernama menyaring baris; kolom kamar bernama');
$h = $main($k['sbx_admin']->req('/portal/v3_laporan.php?jenis=pelanggaran&periode=semua&q=A2')['body']);
$assert(str_contains($h, '1 baris sesuai saringan') && str_contains($h, 'SBX Santri A2'), 'Cari nama santri (q) menyaring laporan');
$assert(str_contains($h, '&lt;script&gt;') === false && !str_contains($h, '<script>window.T5_XSS'), 'Isi laporan tetap di-escape');
$csv = $k['sbx_admin']->req('/portal/v3_laporan.php?jenis=pelanggaran&format=csv&periode=semua');
$baris = explode("\n", trim(substr($csv['body'], 3)));
$assert(str_contains($csv['type'], 'text/csv') && str_getcsv($baris[0], ',', '"', '') === ['ID pelanggaran', 'ID santri', 'Santri', 'Kejadian', 'Kategori', 'Tingkat', 'Poin', 'Status'] && count($baris) - 1 === (int) $satu('SELECT COUNT(*) FROM v3_pelanggaran p WHERE p.archived_at IS NULL AND NOT EXISTS(SELECT 1 FROM v3_pelanggaran nx WHERE nx.revisi_dari_id=p.id)'), 'CSV tidak berubah bentuk dan lengkap');
$h = $k['sbx_admin']->req('/portal/v3_laporan.php?jenis=pelanggaran&format=cetak&periode=semua')['body'];
$assert(str_contains($h, 'Laporan pembinaan · Pelanggaran') && !str_contains($main($h), 'ah-filter'), 'Cetak laporan tanpa saringan, berjudul jenis');
$assert($k['sbx_admin']->req('/portal/v3_laporan.php?jenis=pelanggaran&kelas_id=1%20OR%201=1')['status'] === 422, 'Nilai saringan tidak sah ditolak 422');
$h = $main($k['sbx_pengurus_a']->req('/portal/v3_laporan.php?jenis=ambang')['body']);
$assert(str_contains($h, '>Total poin</th>') && str_contains($h, 'baris sesuai saringan'), 'Laporan ambang (tautan Beranda AA) tetap terbuka');

// ===================================================================== H5-F
echo PHP_EOL . '=== H5-F. V-1 pelanggaran ===' . PHP_EOL;
$h = $main($pa->req('/portal/v3_pelanggaran.php')['body']);
preg_match_all('/<span>(Semua|Dicatat|Ditindaklanjuti|Selesai|Dibatalkan)<\/span> <span class="ah-chips__count">(\d+)</', $h, $m);
$chip = array_combine($m[1], array_map('intval', $m[2]));
$dbDicatat = (int) $satu("SELECT COUNT(*) FROM v3_pelanggaran p JOIN plotting_kamar pm ON pm.id_santri=p.santri_id AND pm.id_tahun=p.tahun_ajaran_id JOIN pembimbing_assignments pa ON pa.kamar_id=pm.id_kamar AND pa.pengurus_id=1 AND pa.is_active=1 WHERE p.status='Dicatat' AND NOT EXISTS(SELECT 1 FROM v3_pelanggaran nx WHERE nx.revisi_dari_id=p.id)");
$assert(($chip['Dicatat'] ?? -1) === $dbDicatat && ($chip['Semua'] ?? -1) === array_sum(array_diff_key($chip, ['Semua' => 1])), "Chip status = basis data cakupan pengurus A (Dicatat $dbDicatat)");
$h = $main($pa->req('/portal/v3_pelanggaran.php?q=C1')['body']);
$assert(str_contains($h, 'SBX Santri C1') && !str_contains($h, 'SBX Santri A1'), 'Cari santri menyaring daftar');
$h = $main($pa->req('/portal/v3_pelanggaran.php?periode=rentang&mulai=2000-01-01&sampai=2000-01-31')['body']);
$assert(str_contains($h, 'Belum ada catatan yang cocok'), 'Periode rentang tanpa data menampilkan keadaan kosong');
$h = $main($pa->req('/portal/v3_pelanggaran.php?action=create')['body']);
$assert(str_contains($h, 'id="ah-panel"') && str_contains($h, 'data-total="') && str_contains($h, 'data-poin="25"') && !str_contains($h, 'Jenis lama nonaktif'), 'Panel catat: total poin santri dan poin jenis tersedia; jenis nonaktif tidak dapat dipilih');
$sebelum = (int) $satu('SELECT COUNT(*) FROM v3_pelanggaran');
$santriC1 = (int) $satu("SELECT id FROM santri WHERE nama_santri='SBX Santri C1'");
$tahun = (int) $satu("SELECT id FROM tahun_ajaran WHERE status='Aktif' LIMIT 1");
$jenisHp = (int) $satu("SELECT id FROM v3_katalog WHERE kode='SBX-HP'");
$isian = ['aksi' => 'buat', 'santri_scope' => $santriC1 . '|' . $tahun, 'katalog_id' => $jenisHp, 'waktu_kejadian' => date('Y-m-d\TH:i', strtotime('-1 hour')), 'uraian' => 'Uji HTTP T5', 'idempotency_key' => 'web-t5-' . bin2hex(random_bytes(6))];
$r = $pa->req('/portal/v3_pelanggaran.php', $isian);
$assert(in_array($r['status'], [403, 419], true) && (int) $satu('SELECT COUNT(*) FROM v3_pelanggaran') === $sebelum, 'Catat tanpa CSRF ditolak (' . $r['status'] . ')');
$r = $pa->req('/portal/v3_pelanggaran.php', ['_csrf' => $pa->csrf('/portal/v3_pelanggaran.php')] + $isian);
$assert($r['status'] === 302 && str_contains((string) $r['location'], 'v3_pelanggaran_detail.php?id=') && (int) $satu('SELECT COUNT(*) FROM v3_pelanggaran') === $sebelum + 1, 'Catat lewat panel tersimpan dan membuka detail');
$mu = $k['sbx_murobi_a'];
$h = $main($mu->req('/portal/v3_pelanggaran.php?action=create')['body']);
$assert(!str_contains($h, 'id="ah-panel"') && !str_contains($h, 'Catat pelanggaran</a>'), 'Murobi tidak mendapat tombol/panel catat');
$r = $mu->req('/portal/v3_pelanggaran.php', ['_csrf' => $mu->csrf('/portal/v3_pelanggaran.php')] + array_merge($isian, ['idempotency_key' => 'web-t5-m-' . bin2hex(random_bytes(6))]));
$assert($r['status'] === 200 && (int) $satu('SELECT COUNT(*) FROM v3_pelanggaran') === $sebelum + 1, 'POST catat oleh murobi ditolak layanan (tanpa baris baru)');

// ===================================================================== H5-G
echo PHP_EOL . '=== H5-G. Wizard & publikasi sesuai cakupan ===' . PHP_EOL;
$pb = $k['sbx_pengurus_b'];
$h = $main($pb->req('/portal/v3_konseling.php?action=create&santri=' . $santriC1 . '%7C' . $tahun)['body']);
$assert(str_contains($h, 'Pilih santri') && !str_contains($h, 'name="tujuan"'), 'Wizard: santri di luar cakupan tidak membuka langkah 2');
$h = $main($pa->req('/portal/v3_konseling.php?action=create&santri=' . $santriA1 . '%7C' . $tahun . '&rekomendasi=' . (int) $satu("SELECT id FROM v3_rekomendasi WHERE santri_id=$santriA1 AND status='Baru' AND tidak_berlaku_pada IS NULL ORDER BY id DESC LIMIT 1"))['body']);
$panel = substr($h, (int) strpos($h, 'id="ah-panel"'), (int) strpos($h, 'id="rekomendasi"') - (int) strpos($h, 'id="ah-panel"'));
$assert(str_contains($panel, 'name="tujuan"') && preg_match('/name="rekomendasi_ids\[\]" value="\d+" checked/', $panel) === 1 && str_contains($panel, 'SBX Santri A1') && !str_contains($panel, 'SBX Santri C1'), 'Wizard langkah 2: rekomendasi dari baris tercentang; hanya tautan santri itu');
$h = $main($k['sbx_admin']->req('/portal/v3_konseling.php')['body']);
$assert(preg_match_all('/<tr data-rekomendasi="\d+">/', $h) === (int) $satu("SELECT COUNT(*) FROM v3_rekomendasi WHERE archived_at IS NULL AND tidak_berlaku_pada IS NULL AND ditindaklanjuti_kasus_id IS NULL AND status='Baru'") && str_contains($h, 'id="rekomendasi"'), 'Daftar rekomendasi (tujuan kartu Beranda AA) = basis data');
$h = $main($k['sbx_admin']->req('/portal/v3_publikasi_kelola.php')['body']);
$assert(str_contains($h, (int) $satu('SELECT COUNT(*) FROM v3_publikasi WHERE archived_at IS NULL') . ' dari ') && str_contains($h, 'SBX Wali A') && !str_contains($h, 'wali #'), 'V-5 daftar admin: semua publikasi dengan nama penerima');
$h = $main($k['sbx_pengurus_b']->req('/portal/v3_publikasi_kelola.php?status=Ditarik')['body']);
$assert(str_contains($h, 'Ditarik') && str_contains($h, 'SBX Santri A1'), 'V-5 pembimbing lain dalam cakupan kamar melihat publikasi santri binaan');
$h = $main($k['sbx_admin']->req('/portal/v3_publikasi_kelola.php?id=' . $id['pubKasus'])['body']);
$assert(str_contains($h, 'Isi yang dilihat wali') && str_contains($h, 'Tarik publikasi') && str_contains($h, 'Diterbitkan'), 'V-5 kelola: isi, riwayat, tarik beralasan');

// ===================================================================== H5-H
echo PHP_EOL . '=== H5-H. M-4 Katalog & poin ===' . PHP_EOL;
$ambang = (int) $satu("SELECT id FROM v3_ambang WHERE nilai_maksimum=29 LIMIT 1");
$h = $main($k['sbx_admin']->req('/admin/admin_v3_katalog.php?jenis=ambang&edit=' . $ambang)['body']);
$t = $teks($h);
$assert(str_contains($t, 'Nilai maksimum: — → 29') && str_contains($t, 'Alasan: Keputusan rapat pengasuh') && str_contains($t, 'SBX Administrator') && !str_contains($h, 'before_json') && !str_contains($h, '{&quot;'), 'Riwayat ambang berupa kalimat dengan pelaku, tanpa JSON mentah');
$h = $main($k['sbx_admin']->req('/admin/admin_v3_katalog.php')['body']);
$assert(str_contains($h, 'Riwayat perubahan terbaru') && str_contains($teks($h), 'Menambah “Membawa HP tanpa izin”'), 'Kartu riwayat terbaru menulis kalimat perubahan');
$r = $k['sbx_pengurus_a']->req('/admin/admin_v3_katalog.php', ['_csrf' => $pa->csrf(), 'id' => 0, 'kode' => 'X', 'nama' => 'X']);
$assert(in_array($r['status'], [302, 403], true) && (int) $satu("SELECT COUNT(*) FROM v3_katalog WHERE kode='X'") === 0, 'Non-admin tidak dapat menyimpan katalog');

// ===================================================================== H5-I
echo PHP_EOL . '=== H5-I. Log server ===' . PHP_EOL;
$isi = (string) file_get_contents($log);
preg_match_all('/PHP (Warning|Notice|Deprecated|Fatal error|Parse error)[^\n]*/', $isi, $m);
$assert($m[0] === [], 'Server tanpa galat/peringatan PHP' . ($m[0] === [] ? '' : ': ' . implode(' | ', array_slice(array_unique($m[0]), 0, 3))));

echo PHP_EOL . 'Ringkasan: ' . $lulus . ' lulus, ' . count($gagal) . ' gagal' . PHP_EOL;
foreach ($gagal as $g) {
    echo '  - ' . $g . PHP_EOL;
}
exit($gagal === [] ? 0 : 1);
