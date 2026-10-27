<?php
declare(strict_types=1);

// Pembacaan penuh log pengajuan wali; tidak dibatasi nama action atau GROUP_CONCAT.
if(getenv('V7_RUN_AUDIT')!=='1' || !preg_match('/^test_[A-Za-z0-9_]*_test$/D',(string)getenv('DB_NAME'))) exit(2);
require_once dirname(__DIR__).'/app/bootstrap.php';
$db=app_db();$pass=$fail=0;
$needles=['RAHASIA-V7F3','PRIVAT-AUDIT-NOTIF'];
foreach([
    'SELECT nama_santri v FROM santri',
    'SELECT no_hp v FROM wali UNION SELECT no_hp FROM pengurus',
    "SELECT alasan v FROM izin_pengajuan WHERE asal_pengajuan='Wali'",
    "SELECT catatan_wali v FROM izin_pengajuan WHERE asal_pengajuan='Wali'",
    "SELECT k.alasan v FROM izin_keputusan k JOIN izin_pengajuan p ON p.id=k.pengajuan_id WHERE p.asal_pengajuan='Wali'",
    'SELECT catatan v FROM izin_pembimbing_tinjauan',
] as $sql) foreach($db->query($sql)->fetch_all(MYSQLI_ASSOC) as $r) if(strlen((string)$r['v'])>=8)$needles[]=(string)$r['v'];
$hasPrivateKey=static function(mixed $data) use (&$hasPrivateKey): bool {
    if(!is_array($data))return false;
    foreach($data as $k=>$v) if(in_array($k,['alasan','alasan_koreksi','catatan','catatan_wali'],true)||$hasPrivateKey($v))return true;
    return false;
};
foreach([
    'audit seluruh action'=>"SELECT a.before_json,a.after_json FROM audit_logs a JOIN izin_pengajuan p ON p.id=a.entity_id WHERE a.entity_type='izin_pengajuan' AND p.asal_pengajuan='Wali'",
    'outbox'=>"SELECT judul,isi,data_json,error_terakhir FROM notifikasi_outbox WHERE event_key LIKE 'v7:izin:%'",
    'percobaan'=>"SELECT c.error_kode,c.error_pesan FROM notifikasi_percobaan c JOIN notifikasi_outbox o ON o.id=c.outbox_id WHERE o.event_key LIKE 'v7:izin:%'",
] as $label=>$sql) {
    $rows=$db->query($sql)->fetch_all(MYSQLI_ASSOC);$leaks=0;
    foreach($rows as $r) {
        foreach($r as $text) if($hasPrivateKey(json_decode((string)$text,true)))$leaks++;
        $text=json_encode($r,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        foreach($needles as $needle) if(str_contains($text,$needle))$leaks++;
        if(preg_match('/Expo(?:nent)?PushToken\[[^\]]+\]|Bearer\s+[A-Za-z0-9._-]{16,}/',$text))$leaks++;
    }
    $ok=$leaks===0 && ($label==='percobaan'||count($rows)>0);
    echo ($ok?'[lulus] ':'[gagal] ')."$label: ".count($rows)." baris, $leaks temuan pola sensitif\n";$ok?$pass++:$fail++;
}
echo "Hasil: $pass lulus, $fail gagal\n";exit($fail===0?0:1);
