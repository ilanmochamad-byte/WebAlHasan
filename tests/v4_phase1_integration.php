<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
require_once dirname(__DIR__).'/app/bootstrap.php';
use App\V4\RencanaService;
use App\V4\V4Exception;
use App\V4\V4Access;
$db=app_db();$name=(string)$db->query('SELECT DATABASE() n')->fetch_assoc()['n'];if(!preg_match('/(?:^|_)(?:test|uji)(?:_|$)/i',$name))exit("Hanya DB uji.\n");
$one=static function(string $sql)use($db):array {return $db->query($sql)->fetch_assoc()?:[];};
$ids=$db->query("SELECT id,username FROM users WHERE username IN ('v4_pendidikan','v4_pengurus','v4_guru','v4_wali')")->fetch_all(MYSQLI_ASSOC);$users=[];foreach($ids as $r)$users[$r['username']]=['id'=>(int)$r['id'],'roles'=>[]];
foreach(['v4_pendidikan','v4_pengurus','v4_guru','v4_wali'] as $key)if(!isset($users[$key]))exit("Fixture $key belum ada.\n");
$mapels=$db->query("SELECT id,kode FROM mata_pelajaran WHERE kode IN ('V4UJI01','V4UJI02')")->fetch_all(MYSQLI_ASSOC);$m=[];foreach($mapels as $r)$m[$r['kode']]=(int)$r['id'];
$service=new RencanaService($db,capabilities(),audit_logger());$admin=['id'=>1,'roles'=>['admin']];
$payload=['tahun_ajaran_id'=>4,'kelas_id'=>2,'mapel'=>[['mata_pelajaran_id'=>$m['V4UJI01'],'target_ketuntasan'=>70],['mata_pelajaran_id'=>$m['V4UJI02'],'target_ketuntasan'=>75]]];
$assert=static function(bool $ok,string $label):void{echo ($ok?'[lulus] ':'[gagal] ').$label.PHP_EOL;if(!$ok)exit(1);};
$created=$service->save($users['v4_pendidikan'],$payload);
$assert(count($created['mapel'])===2,'Pendidikan menyimpan dua mapel wajib');
$web=$service->read($users['v4_pendidikan'],4,2);$api=$service->read($admin,4,2);
$assert(count($web['mapel'])===2&&count($api['mapel'])===2,'Rencana terbaca ulang di layanan web dan API');
$payload['mapel'][0]['target_ketuntasan']=80;$changed=$service->save($users['v4_pendidikan'],$payload);
$targets=array_column($changed['mapel'],'target_ketuntasan','mata_pelajaran_id');$assert((float)$targets[$m['V4UJI01']]===80.0,'Target berubah sebelum ada nilai');
$count=(int)$one('SELECT COUNT(*) n FROM v4_rencana_mapel')['n'];$duplicate=$payload;$duplicate['mapel'][]=$duplicate['mapel'][0];
try{$service->save($users['v4_pendidikan'],$duplicate);$assert(false,'Duplikasi ditolak');}catch(V4Exception $e){$assert($e->status===409,'Duplikasi ditolak 409');}
$assert((int)$one('SELECT COUNT(*) n FROM v4_rencana_mapel')['n']===$count,'Duplikasi tidak menambah baris');
foreach(['v4_pengurus','v4_guru','v4_wali'] as $key){try{$service->save($users[$key],$payload);$assert(false,"$key ditolak");}catch(V4Exception $e){$assert($e->status===403,"$key mendapat 403");}}
// Audit Claude Code: tanpa capability tetap 403 walau parameter kosong/tidak dikenal (tidak membocorkan ID valid).
foreach(['v4_pengurus','v4_guru','v4_wali'] as $key){foreach([[0,0],[999999,2]] as [$t,$k]){try{$service->read($users[$key],$t,$k);$assert(false,"$key baca $t/$k ditolak");}catch(V4Exception $e){$assert($e->status===403,"$key baca semester $t kelas $k mendapat 403");}}
 try{$service->save($users[$key],[]);$assert(false,"$key simpan kosong ditolak");}catch(V4Exception $e){$assert($e->status===403,"$key simpan payload kosong mendapat 403");}
 try{$service->options($users[$key]);$assert(false,"$key opsi ditolak");}catch(V4Exception $e){$assert($e->status===403,"$key opsi mendapat 403");}}
$bad=$payload;$bad['mapel'][0]['target_ketuntasan']=101;try{$service->save($admin,$bad);$assert(false,'Target >100 ditolak');}catch(V4Exception $e){$assert($e->status===422,'Target >100 ditolak 422');}
$bad=$payload;$bad['mapel'][0]['bobot_harian']=50;try{$service->save($admin,$bad);$assert(false,'Bobot salah ditolak');}catch(V4Exception $e){$assert($e->status===422,'Bobot salah ditolak 422');}
$audit=$one("SELECT before_json,after_json FROM audit_logs WHERE action='v4.rencana.ubah' ORDER BY id DESC LIMIT 1");$json=($audit['before_json']??'').($audit['after_json']??'');$assert($json!==''&&!preg_match('/token|private_path|gambar|password/i',$json),'Audit rencana tanpa token atau gambar');
$access=new V4Access(capabilities());
$assert($access->nilaiInput($users['v4_guru'],4,2,$m['V4UJI01'])==='guru','Guru sesuai cakupan memiliki capability input');
try{$access->nilaiInput($users['v4_guru'],4,2,$m['V4UJI02']);$assert(false,'Guru mapel lain ditolak');}catch(V4Exception $e){$assert($e->status===403,'Guru mapel lain ditolak 403');}
try{$access->nilaiInput($users['v4_pendidikan'],4,2,$m['V4UJI02'],true);$assert(false,'Pengganti tanpa alasan ditolak');}catch(V4Exception $e){$assert($e->status===422,'Pengganti tanpa alasan ditolak 422');}
$assert($access->nilaiInput($users['v4_pendidikan'],4,2,$m['V4UJI02'],true,'Guru berhalangan')==='pendidikan_pengganti','Pengganti beralasan memiliki kapasitas khusus');
$inactive=$one('SELECT p.id AS penanda_id,a.id AS aset_id FROM v4_penanda_tangan p JOIN v4_tanda_tangan_aset a ON a.penanda_tangan_id=p.id WHERE p.is_active=0 ORDER BY p.id DESC LIMIT 1');
if($inactive){$bad=$payload;$bad['pendidikan_penanda_id']=$inactive['penanda_id'];$bad['pendidikan_aset_id']=$inactive['aset_id'];try{$service->save($admin,$bad);$assert(false,'Aset nonaktif ditolak');}catch(V4Exception $e){$assert($e->status===422,'Aset nonaktif ditolak 422');}}
try{$access->nilaiInput($admin,4,2,$m['V4UJI02']);$assert(false,'Admin tanpa alasan ditolak');}catch(V4Exception $e){$assert($e->status===422,'Admin tanpa alasan ditolak 422');}
$assert($access->nilaiInput($admin,4,2,$m['V4UJI02'],false,'Koreksi administratif')==='admin','Admin beralasan tercatat dalam kapasitas admin');
