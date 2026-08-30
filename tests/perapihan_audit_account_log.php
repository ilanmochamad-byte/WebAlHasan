<?php
declare(strict_types=1);
// A-12 / K1-05: real audit writes and failed-writer rollback, only synthetic data.
if (getenv('PERAPIHAN_AUDIT_DB') !== '1') exit(77);
require_once dirname(__DIR__).'/app/bootstrap.php';
if (!str_ends_with((string)app_config('database.database'), '_test')) exit(2);
$db=app_db(); $pass=0; $fail=0; $users=[]; $masters=[];
$tag='a14'.bin2hex(random_bytes(4));
$check=static function(bool $ok,string $label)use(&$pass,&$fail):void{$ok?$pass++:$fail++;echo ($ok?'[lulus] ':'[gagal] ').$label.PHP_EOL;};
$one=static fn(string $sql,array $p=[])=>$db->execute_query($sql,$p)->fetch_assoc();
$actor=(int)$one("SELECT id FROM users WHERE username='sbx_admin'")['id'];
if(!$actor)exit(2);
$_SESSION['user_id']=$actor;
$repo=new App\Account\AccountRepository($db);$devices=new App\Notification\DeviceRepository($db);
$good=new App\Audit\AuditLogger($db);
// Closed independent writer models AuditLogger::log returning false, without DDL,
// altering historic audits, or interrupting the live sandbox connection.
$unavailable=new mysqli();$unavailable->close();$bad=new App\Audit\AuditLogger($unavailable);
$account=static fn($logger)=>new App\Account\AccountService($repo,$logger,$devices);
$linked=static fn($logger)=>new App\Account\PerizinanAccountService(new App\Account\PerizinanAccountRepository($db),$account($logger),$logger);
$makeUser=static function()use($db,$tag,&$users):int{$db->execute_query('INSERT INTO users(name,username,password,is_active,force_password_change)VALUES(?,?,?,1,0)',[$tag,$tag.count($users),password_hash('SandboxOnly!123',PASSWORD_DEFAULT)]);return $users[]=(int)$db->insert_id;};
$snapshot=static function(int $id)use($db,$one):array{return [$one('SELECT * FROM users WHERE id=?',[$id]),$db->execute_query('SELECT role_id FROM user_roles WHERE user_id=? ORDER BY role_id',[$id])->fetch_all(MYSQLI_ASSOC),$db->execute_query('SELECT * FROM perangkat_push WHERE user_id=? ORDER BY id',[$id])->fetch_all(MYSQLI_ASSOC)];};
$event=static function(string $action,int $id,array $expectedAfter,?array $expectedBefore=null)use($db,$one,$actor,$check):void{
 $rows=$db->execute_query('SELECT * FROM audit_logs WHERE action=? AND entity_type=? AND entity_id=? ORDER BY id DESC',[$action,'user',$id])->fetch_all(MYSQLI_ASSOC);$r=$rows[0]??[];
 $after=json_decode($r['after_json']??'{}',true);$before=json_decode($r['before_json']??'null',true);
 $ok=$r&&(int)$r['actor_user_id']===$actor;
 foreach($expectedAfter as $k=>$v)$ok=$ok&&($after[$k]??null)===$v;
 foreach($expectedBefore??[] as $k=>$v)$ok=$ok&&($before[$k]??null)===$v;
 $check((bool)$ok,$action.' actor, entity, before/after correct');
 $safe=$after??[]; unset($safe['force_password_change']);
 $check(!preg_match('/password|token|secret|\\$2[aby]\\$/i',json_encode($safe).json_encode($before)),$action.' no credential values');
};
try{
 $id=$makeUser();
 $db->execute_query("INSERT INTO perangkat_push(user_id,token_hash,token_terlindungi,platform,device_id,push_aktif,terakhir_aktif_pada)VALUES(?,?,?,'web',?,1,NOW())",[$id,hash('sha256',$tag),'synthetic-unusable',$tag]);
 foreach(['grant','revoke','deactivate','activate','reset'] as $operation){
  $db->execute_query('UPDATE users SET is_active=? WHERE id=?',[$operation==='activate'?0:1,$id]);
  if($operation==='revoke')$repo->grantRole($id,'admin',$actor);
  if($operation==='grant')$repo->revokeRole($id,'admin');
  $run=static function($s)use($operation,$id,$actor){return match($operation){'grant'=>$s->grantRole($id,'admin',$actor,['konfirmasi_admin'=>'BERI AKSES ADMIN']),'revoke'=>$s->revokeRole($id,'admin',$actor),'deactivate'=>$s->setActive($id,false,$actor),'activate'=>$s->setActive($id,true,$actor),'reset'=>$s->resetPassword($id,$actor)};};
  $before=$snapshot($id);$n=(int)$one('SELECT COUNT(*) n FROM audit_logs WHERE entity_type=? AND entity_id=?',['user',$id])['n'];$rejected=false;
  try{$run($account($bad));}catch(RuntimeException $e){$rejected=str_contains($e->getMessage(),'audit');}
  $check($rejected&&$snapshot($id)===$before,$operation.' failed audit rolls back account/roles/devices');
  $check((int)$one('SELECT COUNT(*) n FROM audit_logs WHERE entity_type=? AND entity_id=?',['user',$id])['n']===$n,$operation.' failed operation produces no success audit');
  $password=$run($account($good));
  if($operation==='reset'){$event('account_password_reset',$id,['force_password_change'=>true]);$check(password_verify($password,$one('SELECT password FROM users WHERE id=?',[$id])['password']),'temporary password matches saved hash');}
  elseif(in_array($operation,['activate','deactivate'],true))$event('account_status_changed',$id,['is_active'=>$operation==='activate','perangkat_push_dicabut'=>$operation==='deactivate'?1:0],['is_active'=>$operation!=='activate']);
  else $event('account_role_'.($operation==='grant'?'granted':'revoked'),$id,[$operation==='grant'?'role_ditambahkan':'role_dicabut'=>'admin']);
 }
 // Every account creation/link entry point, including both V2 master kinds.
 $teacher=master_data_service()->saveGuru(['nip'=>$tag,'nama_guru'=>$tag]);$masters['guru']=[$teacher];
 $input=['guru_id'=>$teacher,'name'=>$tag,'username'=>$tag.'_teacher'];
 try{$account($bad)->createTeacher($input,$actor);$rejected=false;}catch(RuntimeException $e){$rejected=str_contains($e->getMessage(),'audit');}
 $check($rejected&&!$one('SELECT id FROM users WHERE username=?',[$input['username']]),'create teacher failed audit rolls back user and role');
 $r=$account($good)->createTeacher($input,$actor);$users[]=$r['id'];$event('account_created',$r['id'],['roles'=>['guru'],'guru_id'=>$teacher]);
 foreach(['pengurus','orang_tua'] as $kind)foreach(['create','link'] as $mode){
  if($kind==='pengurus'){$master=master_data_service()->savePengurus(['nama'=>$tag.$mode,'jabatan'=>'Uji']);$masters['pengurus'][]=$master;$key='pengurus_id';}
  else{$master=master_data_service()->saveWali(['nama'=>$tag.$mode]);$masters['wali'][]=$master;$key='wali_id';$student=master_data_service()->saveSantri(['nis'=>$tag.$mode,'nama_santri'=>$tag.$mode,'jenis_kelamin'=>'L','tgl_lahir'=>'2010-01-01']);$masters['santri'][]=$student;master_data_service()->attachWali($master,['santri_id'=>$student,'hubungan'=>'Wali'],$actor);}
  $input=['name'=>$tag,'username'=>$tag.'_'.$kind.'_'.$mode,$key=>$master];$target=$mode==='link'?$makeUser():0;$before=$target?$snapshot($target):null;
  try{if($mode==='create')$linked($bad)->create($kind,$input,$actor);else $linked($bad)->link($kind,$target,$master,$actor);$rejected=false;}catch(RuntimeException $e){$rejected=str_contains($e->getMessage(),'audit');}
  $check($rejected&&($target?$snapshot($target)===$before:!$one('SELECT id FROM users WHERE username=?',[$input['username']])),$mode.' '.$kind.' failed audit rolls back user/master/roles');
  if($mode==='create'){$r=$linked($good)->create($kind,$input,$actor);$target=$users[]=$r['id'];}else $linked($good)->link($kind,$target,$master,$actor);
  $event('perizinan_account_'.($mode==='create'?'created':'linked'),$target,[$key=>$master,'role'=>$kind]);
 }
}finally{
 foreach($users as $id){$db->execute_query("DELETE FROM audit_logs WHERE entity_type='user' AND entity_id=?",[$id]);$db->execute_query('DELETE FROM perangkat_push WHERE user_id=?',[$id]);$db->execute_query('DELETE FROM user_roles WHERE user_id=?',[$id]);$db->execute_query('DELETE FROM users WHERE id=?',[$id]);}
 foreach($masters['santri']??[] as $id)$db->execute_query('DELETE FROM santri_wali WHERE santri_id=?',[$id]);
 foreach(['santri','wali','pengurus','guru'] as $table)foreach($masters[$table]??[] as $id){$db->execute_query('DELETE FROM audit_logs WHERE entity_type=? AND entity_id=?',[$table,$id]);$db->query("DELETE FROM $table WHERE id=".(int)$id);}
}
echo "Total audit akun: $pass lulus, $fail gagal\n";exit($fail?1:0);
