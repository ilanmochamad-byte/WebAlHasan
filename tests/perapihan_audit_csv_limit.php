<?php
declare(strict_types=1);
// Sengaja menguji persyaratan audit, bukan menerima perilaku baseline yang
// belum membatasi CSV absensi. Kegagalan memerlukan keputusan pengguna.
if(getenv('PERAPIHAN_AUDIT_DB')!=='1') exit(77);
require_once dirname(__DIR__).'/app/bootstrap.php';
if(!str_ends_with((string)app_config('database.database'),'_test')) exit(2);
$manifest=json_decode(file_get_contents((string)getenv('AUDIT_FIXTURE_MANIFEST')),true);
if($manifest['database']!==app_config('database.database')) exit(2);
$db=app_db(); $schedule=(int)$manifest['schedules'][1]; $teacher=(int)$manifest['other_teacher_id'];
$actor=(int)$db->query("SELECT id FROM users WHERE username='sbx_admin'")->fetch_assoc()['id'];
$tag='AUDITCSV'.bin2hex(random_bytes(6)); $jar=tempnam(sys_get_temp_dir(),'csv-audit-');
$base=getenv('BASE_URL')?:'http://127.0.0.1:8940';
if(!preg_match('#^http://127\.0\.0\.1:\d+$#',$base)) exit(2);
$request=static function(string $path,?array $data=null)use($base,$jar):array {
    $c=curl_init($base.$path); curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$jar,CURLOPT_COOKIEJAR=>$jar,CURLOPT_TIMEOUT=>30]);
    if($data!==null)curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($data)]);
    $body=(string)curl_exec($c); return ['status'=>curl_getinfo($c,CURLINFO_RESPONSE_CODE),'body'=>$body,'type'=>curl_getinfo($c,CURLINFO_CONTENT_TYPE)];
};
$ok=false;
try {
    $db->begin_transaction(); $date=new DateTimeImmutable('1900-01-01');
    for($offset=0;$offset<20001;$offset+=1000) {
        $values=[];
        for($i=$offset;$i<min($offset+1000,20001);$i++) $values[]="($schedule,'".$date->modify("+$i days")->format('Y-m-d')."','Selesai',$actor,'$tag')";
        $db->query('INSERT INTO pertemuan_pengajian(jadwal_id,tanggal_pertemuan,status,created_by,catatan) VALUES '.implode(',',$values));
    }
    $db->query("INSERT INTO absensi_guru(pertemuan_id,guru_id,status,dicatat_pada,dicatat_oleh) SELECT id,$teacher,'Hadir',NOW(),$actor FROM pertemuan_pengajian WHERE jadwal_id=$schedule AND catatan='$tag'");
    $db->commit();
    $login=$request('/portal/'); preg_match('/name="_csrf" value="([^"]+)"/',$login['body'],$m);
    $request('/admin/cek_login.php',['_csrf'=>$m[1]??'','username'=>'sbx_admin','password'=>'Sandbox#123']);
    $r=$request('/admin/export_laporan_absensi.php?'.http_build_query(['date_from'=>'1900-01-01','date_to'=>'1999-12-31','schedule_id'=>$schedule,'subject_scope'=>'guru']));
    $ok=$r['status']===422 && !str_contains((string)$r['type'],'text/csv');
    $records=substr_count($r['body'],"\n")-1;
    echo ($ok?'[lulus] ':'[gagal] ').'CSV >20000 wajib ditolak 422; aktual HTTP='.$r['status'].' tipe='.$r['type'].' baris-data='.$records.PHP_EOL;
} finally {
    $db->rollback();
    $db->query("DELETE a FROM absensi_guru a JOIN pertemuan_pengajian p ON p.id=a.pertemuan_id WHERE p.jadwal_id=$schedule AND p.catatan='$tag'");
    $db->query("DELETE FROM pertemuan_pengajian WHERE jadwal_id=$schedule AND catatan='$tag'");
    unlink($jar);
}
exit($ok?0:1);
