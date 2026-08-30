<?php
declare(strict_types=1);
if(getenv('PERAPIHAN_AUDIT_DB')!=='1')exit(77);
require_once dirname(__DIR__).'/app/bootstrap.php';
if(!str_ends_with((string)app_config('database.database'),'_test'))exit(2);
$m=json_decode(file_get_contents(getenv('AUDIT_UI_MANIFEST')),true,512,JSON_THROW_ON_ERROR);
$db=app_db();$before=(int)$db->query('SELECT @@session.group_concat_max_len n')->fetch_assoc()['n'];$pass=0;$fail=0;
try{
 $db->query('SET SESSION group_concat_max_len=1024');
 $groups=master_data_service()->reconciliationPage('duplikat',$m['tag'],1)['rows'];
 $member=null;foreach($groups as$g)foreach($g['anggota']as$a)if((int)$a['id']===$m['wali'][0])$member=$a;
 foreach(range(1,45)as$i){$name=$m['tag'].' Santri '.sprintf('%02d',$i).' Nama panjang untuk pemeriksaan dampak';$ok=$member!==null&&str_contains($member['santri'],$name);$ok?$pass++:$fail++;echo($ok?'[lulus] ':'[gagal] ').'nama terdampak '.$i.' lengkap pada group_concat_max_len=1024'.PHP_EOL;}
}finally{$db->query('SET SESSION group_concat_max_len='.$before);}
echo"Total wali daftar panjang: $pass lulus, $fail gagal\n";exit($fail?1:0);
