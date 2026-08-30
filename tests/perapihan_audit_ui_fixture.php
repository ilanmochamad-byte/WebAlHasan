<?php
declare(strict_types=1);
if(getenv('PERAPIHAN_AUDIT_DB')!=='1')exit(77);
require_once dirname(__DIR__).'/app/bootstrap.php';
if(!str_ends_with((string)app_config('database.database'),'_test'))exit(2);
$db=app_db();mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);$file=getenv('AUDIT_UI_MANIFEST');if(!$file)exit(2);
if(($argv[1]??'')==='cleanup'){
 $m=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);if($m['database']!==app_config('database.database')||!preg_match('/^UI14[0-9a-f]{8}$/',$m['tag']))exit(2);
 foreach($m['students']as$id){$ids=$db->execute_query('SELECT id FROM santri_wali WHERE santri_id=?',[$id])->fetch_all(MYSQLI_ASSOC);foreach($ids as$r)$db->execute_query("DELETE FROM audit_logs WHERE entity_type='santri_wali' AND entity_id=?",[$r['id']]);$db->execute_query('DELETE FROM santri_wali WHERE santri_id=?',[$id]);}
 foreach(['santri'=>$m['students'],'wali'=>$m['wali'],'guru'=>[$m['guru']],'pengurus'=>[$m['pengurus']]]as$table=>$ids)foreach($ids as$id){$db->execute_query('DELETE FROM audit_logs WHERE entity_type=? AND entity_id=?',[$table,$id]);$db->query("DELETE FROM $table WHERE id=".(int)$id);}
 echo 'Fixture UI sendiri dibersihkan; data lama tidak disentuh.'.PHP_EOL;exit;
}
if(is_file($file)){echo"Manifest sudah tersedia.\n";exit;}
$actor=(int)$db->query("SELECT id FROM users WHERE username='sbx_admin'")->fetch_assoc()['id'];$_SESSION['user_id']=$actor;$s=master_data_service();$tag='UI14'.bin2hex(random_bytes(4));$m=['database'=>app_config('database.database'),'tag'=>$tag,'students'=>[],'wali'=>[]];
$m['wali'][]=$wali=$s->saveWali(['nama'=>$tag.' Wali Bersama','no_hp'=>'081234560000']);$m['wali'][]=$s->saveWali(['nama'=>$tag.' Wali Bersama','no_hp'=>'081234560000']);
for($i=1;$i<=45;$i++){$m['students'][]=$id=$s->saveSantri(['nis'=>$tag.sprintf('%02d',$i),'nama_santri'=>$tag.' Santri '.sprintf('%02d',$i).' Nama panjang untuk pemeriksaan dampak','jenis_kelamin'=>'L','tgl_lahir'=>'2010-01-01']);$s->attachWali($wali,['santri_id'=>$id,'hubungan'=>'Wali'],$actor);}
$m['guru']=$s->saveGuru(['nip'=>$tag,'nama_guru'=>$tag.' Guru']);$m['pengurus']=$s->savePengurus(['nama'=>$tag.' Pengurus','jabatan'=>'Uji']);
file_put_contents($file,json_encode($m,JSON_PRETTY_PRINT));echo"45 relasi aktif untuk satu wali; dua kandidat; master tanpa akun tersedia.\n";
