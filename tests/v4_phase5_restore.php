<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
if(PHP_SAPI!=='cli'||app_config('env')!=='testing'||app_config('database.database')!=='webalhasan_v4_fase5_restore'||!str_contains(APP_ROOT,'restore-web'))exit(77);
$db=app_db();$ok=static function(bool $v,string $s){echo($v?'[lulus] ':'[gagal] ').$s.PHP_EOL;if(!$v)exit(1);};
// Compare business rows without writing their contents to evidence.
$tables=$db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 'v4\\_%'")->fetch_all(MYSQLI_ASSOC);
foreach($tables as $row){$name=$row['TABLE_NAME'];if(!preg_match('/^[a-z0-9_]+$/D',$name))exit(2);
 $digest=static function($rows){$s=array_map(static fn($r)=>json_encode($r,JSON_THROW_ON_ERROR),$rows);sort($s);return hash('sha256',implode("\n",$s));};
 $restored=$db->query("SELECT * FROM `$name`")->fetch_all(MYSQLI_ASSOC);
 $source=$db->query("SELECT * FROM webalhasan_v4_fase2_test.`$name`")->fetch_all(MYSQLI_ASSOC);
 $ok($digest($restored)===$digest($source),'Baris bisnis restore identik: '.$name);
}
$v=$db->query('SELECT * FROM v4_rapor_versi ORDER BY id LIMIT 1')->fetch_assoc();
$path=APP_ROOT.'/storage/private/v4/'.$v['pdf_private_path'];
$trusted=file_get_contents($path);$ok(hash('sha256',$trusted)===$v['pdf_sha256'],'Byte backup tepercaya cocok dengan hash kanonis');
try{
 file_put_contents($path,'corrupt synthetic PDF');
 try{(new App\V4\RaporStorage())->read($v);$ok(false,'PDF korup harus ditolak');}
 catch(App\V4\V4Exception $e){$ok($e->status===503,'PDF korup pada restore ditolak 503');}
}finally{file_put_contents($path,$trusted);chmod($path,0600);}
$ok((new App\V4\RaporStorage())->read($v)===$trusted,'Pemulihan byte backup tanpa regenerasi mengembalikan PDF identik');
