<?php
require __DIR__.'/v4_phase4_support.php';
$db->query("UPDATE santri SET nis=CONCAT('UJI4-',id),nama_santri=CONCAT('Santri Sintetis Fase 4 #',id) WHERE id IN (SELECT id_santri FROM plotting_kelas WHERE id_kelas=2 AND id_tahun=4)");
$hash=password_hash(getenv('V4_TEST_PASSWORD'),PASSWORD_DEFAULT);$tokens=json_decode(file_get_contents(getenv('V4_HTTP_TOKENS')),true);$wids=[];
foreach(['a','b','lain','tanpaakun'] as $key){
 $db->query("INSERT INTO wali (nama,is_active) VALUES ('Wali Sintetis Fase 4 $key',1)");$wid=$db->insert_id;$wids[$key]=$wid;
 if($key!=='lain')$db->query("INSERT INTO santri_wali (santri_id,wali_id,hubungan) VALUES ($sid,$wid,'Ayah')");
 if($key==='tanpaakun')continue;
 $username='v4_wali4'.$key;$s=$db->prepare("INSERT INTO users (name,username,password,wali_id) VALUES ('Wali Sintetis Fase 4',?,?,?)");$s->bind_param('ssi',$username,$hash,$wid);$s->execute();$uid=$db->insert_id;$db->query("INSERT INTO user_roles (user_id,role_id) SELECT $uid,id FROM roles WHERE slug='orang_tua'");
 $raw=bin2hex(random_bytes(32));$h=(new App\Auth\TokenHasher((string)app_config('api.token_hash_secret'),'testing'))->hash($raw);$s=$db->prepare("INSERT INTO api_tokens (user_id,token_hash,name,expires_at) VALUES (?,?,'v4-fase4',DATE_ADD(NOW(),INTERVAL 1 DAY))");$s->bind_param('is',$uid,$h);$s->execute();$tokens[$username]=$raw;
}
file_put_contents(getenv('V4_HTTP_TOKENS'),json_encode($tokens));
$assets=[];
foreach(['Pendidikan','Pimpinan'] as $job){
 $db->query("INSERT INTO v4_penanda_tangan (jabatan,nama,gelar,created_by,updated_by) VALUES ('$job','Penanda Sintetis $job','UJI',1,1)");$pid=$db->insert_id;
 $name=bin2hex(random_bytes(20));$im=imagecreatetruecolor(350,100);$white=imagecolorallocate($im,255,255,255);imagefill($im,0,0,$white);$ink=imagecolorallocate($im,20,55,110);imagestring($im,5,10,10,'UJI '.$job,$ink);imageline($im,20,70,290,45,$ink);imagepng($im,APP_ROOT.'/storage/private/v4/'.$name);imagedestroy($im);chmod(APP_ROOT.'/storage/private/v4/'.$name,0600);$h=hash_file('sha256',APP_ROOT.'/storage/private/v4/'.$name);
 $s=$db->prepare("INSERT INTO v4_tanda_tangan_aset (penanda_tangan_id,private_path,sha256,mime_type,uploaded_by,activated_by) VALUES (?,?,?,'image/png',1,1)");$s->bind_param('iss',$pid,$name,$h);$s->execute();$assets[strtolower($job).'_penanda_id']=$pid;$assets[strtolower($job).'_aset_id']=$db->insert_id;
}
$p=$q("SELECT version FROM v4_rencana WHERE id=$plan")[0];$change=['rencana_id'=>$plan,'version'=>$p['version'],'tempat'=>'Kota Sintetis','alasan'=>'Persetujuan penanda tangan sintetis']+$assets;
$rp=new App\V4\RencanaService($db,capabilities(),audit_logger());$rp->changeSigners($admin,$change);
$reject(fn()=>$rp->changeSigners($admin,$change),409,'Perubahan penanda versi usang ditolak');$reject(fn()=>$rp->changeSigners($pe,$change),403,'Pendidikan tidak dapat memakai jalur khusus admin');
// Regresi browser sudah menyerahkan sikap; lengkapi pemeriksaan terbaru.
$verifyAll();
$ids['wali4']=$wids;$ids['assets4']=$assets;file_put_contents('/private/tmp/v4-fase4-ids.json',json_encode($ids));
echo "Fixture Fase 4 sintetis: dua wali berakun aktif, satu tanpa akun, satu di luar relasi; dua PNG uji privat.\n";
