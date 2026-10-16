<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/bootstrap.php';
use App\V4\{RaporService,RencanaService,VerifikasiService,NilaiService,SikapService,V4Exception};
if(PHP_SAPI!=='cli'||app_config('env')!=='testing'||app_config('database.database')!=='webalhasan_v4_fase2_test')exit(77);
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=app_db();mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);$q=static fn($sql)=>$db->query($sql)->fetch_all(MYSQLI_ASSOC);
$ok=static function($v,$s){echo ($v?'[lulus] ':'[gagal] ').$s.PHP_EOL;if(!$v)exit(1);};
$reject=static function(callable $fn,int $code,string $s)use($ok){try{$fn();$ok(false,$s);}catch(V4Exception $e){$ok($e->status===$code,$s.' HTTP '.$e->status);}};
$users=[];foreach($q("SELECT id,username FROM users WHERE username LIKE 'v4_%'") as $u)$users[$u['username']]=['id'=>(int)$u['id']];
$pe=$users['v4_pendidikan'];$admin=['id'=>1];$ids=json_decode(file_get_contents('/private/tmp/v4-fase3-ids.json'),true);$plan=(int)$ids['rencana'];$sid=(int)$ids['santri'];
$svc=new RaporService($db,audit_logger());$vs=new VerifikasiService($db,audit_logger());
$make=static function(string $action='Terbit',?array $p=null)use($svc,$pe,$plan,$sid):array{$p??=$svc->preview($pe,$plan,$sid,'Catatan umum sintetis <script>window.v4xss=1</script>');return ['action'=>$action,'rencana_id'=>$plan,'santri_id'=>$sid,'current_version'=>$p['current_version'],'revision'=>$p['revision'],'pratinjau_hash'=>$p['pratinjau_hash'],'catatan_umum'=>$p['snapshot']['catatan_umum'],'alasan'=>$action==='Terbit'?'':'Koreksi administrasi sintetis','idempotency_key'=>bin2hex(random_bytes(20))];};
$verifyAll=static function()use($vs,$pe,$plan,$sid):void{
 foreach($vs->read($pe,$plan,$sid)['matriks'][0]['bahan'] as $m){
  if($m['pemeriksaan']['status']==='Terverifikasi')continue;
  $in=['rencana_id'=>$plan,'santri_id'=>$sid,'jenis'=>$m['jenis'],'mapel_key'=>$m['mapel_key'],'alasan'=>'Verifikasi fixture sintetis'];
  if($m['jenis']==='kehadiran'&&$m['pemeriksaan']['status']!=='Dikirim'){$vs->act($pe,$in+['action'=>'serahkan']+$m['pemeriksaan']);$m=array_values(array_filter($vs->read($pe,$plan,$sid)['matriks'][0]['bahan'],fn($v)=>$v['jenis']==='kehadiran'))[0];}
  $vs->act($pe,$in+['action'=>'verifikasi']+$m['pemeriksaan']);
 }
};
