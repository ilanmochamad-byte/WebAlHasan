<?php

declare(strict_types=1);

/**
 * Probe independen Codex Tahap 5 untuk keputusan Z, melalui HTTP dan REST.
 * Jalankan setelah seed/browser Tahap 5: php tests/redesain_web_tahap5_audit.php
 * Memakai DB test_*_test (bawaan test_redesain_t5_test), server sendiri 8983.
 * Nama master, referensi sumber, isi internal, dan pencabutan relasi/master
 * sementara dipulihkan di finally; token probe dihapus. Tidak menerbitkan data.
 * Login/pembacaan admin tetap menghasilkan jejak audit pada DB uji.
 */

$root = dirname(__DIR__);
$db = getenv('TAHAP5_DB') ?: 'test_redesain_t5_test';
$port = (int) (getenv('TAHAP5_PORT') ?: 8983);
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

$repo = new App\V3\KonselingRepository(app_db());
$api = new Klien($base);
$login = $api->req('/api/v1/auth/login', ['username'=>'sbx_ortu_a','password'=>'Sandbox#123','device_name'=>'audit-t5-z'], [], true);
$token = json_decode($login['body'], true)['data']['token'] ?? '';
$headers = ['Authorization: Bearer '.$token];
$detail = static fn(int $pid):array => $api->req('/api/v1/v3/publikasi/'.$pid, null, $headers);
$dto = static fn(int $pid):array => json_decode($detail($pid)['body'], true)['data']['publikasi'] ?? [];
$allow = ['id','santri_id','ringkasan','tindak_lanjut','diterbitkan_pada','ditarik_pada','dibaca_pada','version','status','pengirim'];
$pub = $repo->one('SELECT * FROM v3_publikasi WHERE id=?', [$id['pubPel']]);
$kasus = $repo->one('SELECT * FROM v3_konseling_kasus WHERE id=?', [$id['internal']]);
$session = $repo->one("SELECT id FROM v3_konseling_sesi WHERE kasus_id=? AND status='Selesai' ORDER BY id LIMIT 1", [$id['internal']]);
$pengurus = $repo->one('SELECT * FROM pengurus WHERE id=?', [(int)$kasus['pembimbing_id']]);
$wali = $repo->one("SELECT wali_id FROM users WHERE username='sbx_ortu_a'");
$sid = (int)$pub['santri_id'];
$snapshot = $dto($id['pubPel']);
$assert($token !== '' && array_keys($snapshot)===$allow, 'REST token nyata dan allowlist Z tepat');
try {
    foreach ([$id['pubPel'], $id['pubKasus']] as $pid) {
        $row=$dto($pid);
        $assert(array_keys($row)===$allow && $row['pengirim']===$pengurus['nama'], 'Pengirim sumber '.($pid===$id['pubPel']?'pelanggaran':'kasus').' tepat, tanpa kolom internal');
    }
    // Sumber sesi: alihkan satu fixture sementara tanpa mengubah snapshot publikasi.
    $repo->execute('UPDATE v3_publikasi SET pelanggaran_id=NULL,sesi_id=? WHERE id=?', [(int)$session['id'],$id['pubPel']]);
    try {
        $row=$dto($id['pubPel']);
        $assert($row===$snapshot, 'Sumber sesi memakai pembimbing kasus, isi snapshot dan allowlist identik');
        $html=$k['sbx_ortu_a']->req('/portal/v3_publikasi.php?id='.$id['pubPel'])['body'];
        $assert(str_contains($html, 'SBX Pengurus A (pembimbing)')&&!str_contains($html,'PRIVATE-T5'), 'Sumber sesi di web tanpa isi internal');
        $alamat='/portal/v3_konseling_detail.php?id='.$id['internal'].'#sesi-'.(int)$session['id'];
        $daftar=$k['sbx_pengurus_a']->req('/portal/v3_publikasi_kelola.php')['body'];
        $kelola=$k['sbx_pengurus_a']->req('/portal/v3_publikasi_kelola.php?id='.$id['pubPel'])['body'];
        $assert(str_contains($daftar,$alamat)&&str_contains($kelola,$alamat),'V-5 sumber sesi bertaut ke kasus dan sesi yang tepat pada daftar/kelola');
        $tujuan=$k['sbx_pengurus_a']->req('/portal/v3_konseling_detail.php?id='.$id['internal']);
        $assert($tujuan['status']===200&&str_contains($tujuan['body'],'id="sesi-'.(int)$session['id'].'"'),'V-5 tujuan sumber sesi memiliki jangkar yang terbaca sesuai hak');
    } finally {
        $repo->execute('UPDATE v3_publikasi SET sesi_id=NULL,pelanggaran_id=? WHERE id=?', [(int)$pub['pelanggaran_id'],$id['pubPel']]);
    }
    $nama='AUDIT-Z <script>window.AUDIT_Z=1</script> & nama';
    $repo->execute('UPDATE pengurus SET nama=? WHERE id=?', [$nama,(int)$pengurus['id']]);
    try {
        $row=$dto($id['pubKasus']);
        $html=$k['sbx_ortu_a']->req('/portal/v3_publikasi.php?id='.$id['pubKasus'])['body'];
        $assert(($row['pengirim']??null)===$nama&&array_keys($row)===$allow, 'Nama pengirim dinamis mengikuti master; DTO tetap tepat');
        $assert(str_contains($html,htmlspecialchars($nama,ENT_QUOTES,'UTF-8'))&&!str_contains($html,'<script>window.AUDIT_Z'), 'Nama pengirim di-escape pada web');
    } finally {$repo->execute('UPDATE pengurus SET nama=? WHERE id=?', [$pengurus['nama'],(int)$pengurus['id']]);}
    // Pencabutan pada sesi dan token yang sudah login: detail/list/laporan serentak tertutup.
    $revocations=[
        'relasi wali'=>['santri_wali','archived_at','NOW()','santri_id=? AND wali_id=?',[$sid,(int)$wali['wali_id']]],
        'master wali'=>['wali','is_active','0','id=?',[(int)$wali['wali_id']]],
        'master santri'=>['santri','is_active','0','id=?',[$sid]],
    ];
    foreach($revocations as $label=>[$table,$column,$value,$where,$params]) {
        $before=$repo->all("SELECT id,$column FROM $table WHERE $where",$params);
        $repo->execute("UPDATE $table SET $column=$value WHERE $where",$params);
        try {
            $web=$k['sbx_ortu_a']->req('/portal/v3_publikasi.php?id='.$id['pubPel']);
            $rest=$detail($id['pubPel']);
            $assert($web['status']===403&&$rest['status']===403, "$label dicabut: detail web dan token REST lama 403");
            $list=$api->req('/api/v1/v3/publikasi?santri_id='.$sid,null,$headers);
            $data=json_decode($list['body'],true)['data']??[];
            $assert(($list['status']===403||($list['status']===200&&($data['total']??-1)===0))&&!str_contains($list['body'],'pengirim'), "$label dicabut: daftar REST kosong atau 403 tanpa nama/snapshot");
            foreach(['html','csv','cetak'] as $format){
                $r=$k['sbx_ortu_a']->req('/portal/v3_laporan.php?santri_id='.$sid.'&format='.$format);
                $assert(!str_contains($r['body'],$snapshot['ringkasan'])&&!str_contains($r['body'],'PRIVATE-T5'), "$label dicabut: laporan $format tanpa isi publikasi");
            }
        } finally {foreach($before as $row)$repo->execute("UPDATE $table SET $column=? WHERE id=?",[$row[$column],(int)$row['id']]);}
        $assert($dto($id['pubPel'])===$snapshot,"$label dipulihkan: snapshot dan pengirim identik");
    }
    $other = new Klien($base);
    $loginB=$other->req('/api/v1/auth/login',['username'=>'sbx_ortu_b','password'=>'Sandbox#123','device_name'=>'audit-t5-z-b'],[],true);
    $tokenB=json_decode($loginB['body'],true)['data']['token']??'';
    $assert($tokenB!==''&&$other->req('/api/v1/v3/publikasi/'.$id['pubPel'],null,['Authorization: Bearer '.$tokenB])['status']===403,'Wali lain mengganti ID pada REST tetap 403');
    $before=$repo->one('SELECT ringkasan_internal FROM v3_konseling_sesi WHERE id=?',[(int)$session['id']]);
    $repo->execute('UPDATE v3_konseling_sesi SET ringkasan_internal=? WHERE id=?',['AUDIT-Z-PRIVATE-SENTINEL',(int)$session['id']]);
    try {
        $row=$dto($id['pubKasus']);
        $assert(!str_contains(json_encode($row),'AUDIT-Z-PRIVATE-SENTINEL')&&array_keys($row)===$allow, 'Perubahan isi internal tidak masuk snapshot orang tua');
    } finally {$repo->execute('UPDATE v3_konseling_sesi SET ringkasan_internal=? WHERE id=?',[$before['ringkasan_internal'],(int)$session['id']]);}
    // Kasus yang kemudian Rahasia: publikasi ditarik tidak menjadi jalan ke sumber.
    $tarik=(int)$satu('SELECT id FROM v3_publikasi WHERE ditarik_pada IS NOT NULL ORDER BY id LIMIT 1');
    $row=$dto($tarik);
    $assert(array_keys($row)===$allow&&$row['ringkasan']==='Informasi ini telah ditarik.'&&$row['tindak_lanjut']===null, 'Publikasi ditarik mempertahankan redaksi aman dan hanya kolom Z');
} finally {
    $repo->execute("DELETE FROM api_tokens WHERE name IN ('audit-t5-z','audit-t5-z-b')");
}
$assert($dto($id['pubPel'])===[], 'Token probe telah dicabut setelah pengujian');
$isi=(string)file_get_contents($log);
$assert(!preg_match('/PHP (Warning|Notice|Deprecated|Fatal error|Parse error)/',$isi),'Probe HTTP tanpa galat PHP');
echo PHP_EOL.'Ringkasan: '.$lulus.' lulus, '.count($gagal).' gagal'.PHP_EOL;
exit($gagal===[]?0:1);

