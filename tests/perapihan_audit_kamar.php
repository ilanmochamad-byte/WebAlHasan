<?php
declare(strict_types=1);
if (getenv('PERAPIHAN_AUDIT_DB') !== '1') exit(77);
require_once dirname(__DIR__) . '/app/bootstrap.php';
if (!str_ends_with((string) app_config('database.database'), '_test')) exit(2);
$db=app_db(); $base='http://127.0.0.1:8940'; $jars=[]; $roomId=null; $checks=0; $failed=0;
$tag='AUDROOM'.bin2hex(random_bytes(5));
$check=static function(bool $ok,string $label)use(&$checks,&$failed):void{$checks++;$failed+=!$ok;echo($ok?'[lulus] ':'[gagal] ').$label.PHP_EOL;};
$request=static function(string $path,string $jar,?array $data=null)use($base):array{
 $c=curl_init($base.$path);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$jar,CURLOPT_COOKIEJAR=>$jar,CURLOPT_TIMEOUT=>20]);
 if($data!==null)curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($data)]);
 $body=(string)curl_exec($c);$status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);return compact('body','status');
};
$csrf=static function(string $body):string{preg_match('/name="_csrf" value="([^"]+)"/',$body,$m);return $m[1]??'';};
try {
 foreach(['sbx_admin','sbx_guru_biasa','sbx_pengurus_a','sbx_ortu_a'] as $user){
  $jar=tempnam(sys_get_temp_dir(),'room-audit-');$jars[$user]=$jar;
  $r=$request('/portal/',$jar);$login=$request('/admin/cek_login.php',$jar,['_csrf'=>$csrf($r['body']),'username'=>$user,'password'=>'Sandbox#123']);
  $check($login['status']===302,'Kamar login '.$user);
 }
 $admin=$jars['sbx_admin'];$token=$csrf($request('/admin/logout.php',$admin)['body']);
 foreach(['sbx_guru_biasa','sbx_pengurus_a','sbx_ortu_a'] as $user)$check($request('/admin/admin_kamar.php',$jars[$user])['status']===403,'Kamar 403 '.$user);
 $count=(int)$db->query('SELECT COUNT(*) n FROM kamar')->fetch_assoc()['n'];
 $r=$request('/admin/admin_kamar.php',$admin,['tambah'=>'1','nama_kamar'=>$tag,'kapasitas'=>'20']);
 $check($r['status']===419 && (int)$db->query('SELECT COUNT(*) n FROM kamar')->fetch_assoc()['n']===$count,'POST tanpa CSRF ditolak tanpa data baru');
 $r=$request('/admin/admin_kamar.php',$admin,['_csrf'=>$token,'tambah'=>'1','nama_kamar'=>$tag,'kapasitas'=>'0']);
 $check($r['status']===422,'Kapasitas nol ditolak');
 $r=$request('/admin/admin_kamar.php',$admin,['_csrf'=>$token,'tambah'=>'1','nama_kamar'=>$tag,'kapasitas'=>'20']);
 $row=$db->query("SELECT * FROM kamar WHERE nama_kamar='$tag'")->fetch_assoc();$roomId=$row?(int)$row['id']:null;
 $check($r['status']===302 && $roomId!==null,'Tambah kamar sah tersimpan');
 if(!$roomId)throw new RuntimeException('Fixture kamar gagal dibuat');
 $r=$request('/admin/admin_kamar.php?hapus='.$roomId,$admin);
 $check($r['status']===405 && $db->query("SELECT id FROM kamar WHERE id=$roomId")->num_rows===1,'GET hapus ditolak dan ID kamar tetap ada');
 $r=$request('/admin/admin_kamar.php',$admin,['_csrf'=>$token,'edit'=>'1','id'=>$roomId.' OR 1=1','nama_kamar'=>'palsu','kapasitas'=>'30']);
 $check($r['status']===422 && (int)$db->query("SELECT kapasitas FROM kamar WHERE id=$roomId")->fetch_assoc()['kapasitas']===20,'ID edit tidak valid ditolak tanpa mutasi');
 $r=$request('/admin/admin_kamar.php',$admin,['_csrf'=>$token,'edit'=>'1','id'=>$roomId,'nama_kamar'=>$tag.' Ubah','kapasitas'=>'30']);
 $check($r['status']===302 && (int)$db->query("SELECT kapasitas FROM kamar WHERE id=$roomId")->fetch_assoc()['kapasitas']===30,'Edit sah memperbarui kapasitas');
 $audit=$db->query("SELECT action FROM audit_logs WHERE entity_type='kamar' AND entity_id='$roomId' ORDER BY id")->fetch_all(MYSQLI_ASSOC);
 $check(array_column($audit,'action')===['master.create','master.update'],'Tambah dan ubah kamar memiliki audit');
 $check((int)$db->query('SELECT COUNT(*) n FROM kamar')->fetch_assoc()['n']===$count+1,'Kamar lama tidak dihapus atau ditambah oleh serangan');
}finally{
 if($roomId){$db->query("DELETE FROM audit_logs WHERE entity_type='kamar' AND entity_id='$roomId'");$db->query("DELETE FROM kamar WHERE id=$roomId");}
 foreach($jars as $jar)unlink($jar);
}
echo "Total kamar: $checks, gagal: $failed\n";exit($failed?1:0);
