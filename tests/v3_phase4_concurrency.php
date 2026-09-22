<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/bootstrap.php';
if(getenv('V3_RUN_TESTS')!=='1'||app_config('database.database')!=='webalhasan_v3_phase1_test')exit(77);
$r=new App\V3\KonselingRepository(app_db());$s=v3_publikasi_service();$a=$r->one("SELECT id FROM users WHERE username='sbx_pengurus_a'");$student=$r->studentOptions((int)$a['id'])[0];$fail=0;
$check=static function($ok,$label)use(&$fail){echo ($ok?'[lulus] ':'[gagal] ').$label.PHP_EOL;if(!$ok)$fail++;};$key=fn()=>'race-'.bin2hex(random_bytes(12));
$run=function(array $jobs):array{
 $running=[];$start=microtime(true)+0.3;
 foreach($jobs as $job){$process=proc_open([PHP_BINARY,__DIR__.'/v3_phase4_concurrency_worker.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fwrite($pipes[0],json_encode($job+['start'=>$start]));fclose($pipes[0]);$running[]=[$process,$pipes];}
 $out=[];foreach($running as [$process,$pipes]){$raw=stream_get_contents($pipes[1]);fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);if(proc_close($process)!==0)throw new RuntimeException('Worker failed: '.$err);$out[]=json_decode($raw,true,512,JSON_THROW_ON_ERROR);}return $out;
};
for($round=0;$round<4;$round++){
 $case=v3_konseling_service()->createCase($a,['santri_id'=>(int)$student['santri_id'],'tahun_ajaran_id'=>(int)$student['tahun_ajaran_id'],'tujuan'=>'SBX F4 konkurensi','kerahasiaan'=>'Internal','idempotency_key'=>$key()]);$id=$case['data']['kasus']['id'];
 $input=['sumber_type'=>'kasus','sumber_id'=>$id,'sumber_version'=>1,'ringkasan'=>'Ringkasan uji konkurensi','tindak_lanjut'=>'Tindak lanjut keluarga'];
 $p=$s->preview($a,$input);$q=$s->preview($a,$input);$one=['action'=>'publish','user'=>$a,'input'=>['pratinjau_token'=>$p['pratinjau_token'],'konfirmasi'=>true,'idempotency_key'=>$key()]];
 if($round===0){$two=$one;}
 elseif($round===1){$two=['action'=>'publish','user'=>$a,'input'=>['pratinjau_token'=>$q['pratinjau_token'],'konfirmasi'=>true,'idempotency_key'=>$key()]];}
 else{$two=['action'=>'privacy','user'=>$a,'id'=>$id,'input'=>['version'=>1,'kerahasiaan'=>'Rahasia','alasan'=>'Uji perubahan bersamaan','idempotency_key'=>$key()]];}
 $results=$run([$one,$two]);$rows=$r->all('SELECT * FROM v3_publikasi WHERE kasus_id=?',[$id]);
 if($round<2){$check(count($rows)===1,'Publikasi konkuren tepat satu per wali, putaran '.$round);$check(count(array_filter($results,fn($r)=>in_array($r['status'],[200,201],true)))===2,'Kedua retry selesai dengan hasil konsisten');$count=$r->one('SELECT COUNT(*) n FROM v3_publikasi_outbox WHERE publikasi_id=?',[(int)$rows[0]['id']]);$check((int)$count['n']===1,'Outbox InApp konkuren tepat satu');}
 else{$check(!($r->case($id)['kerahasiaan']==='Rahasia'&&$rows!==[]),'Konkurensi terbit/kerahasiaan mempertahankan invariant '.$round);$statuses=array_column($results,'status');$check(in_array(409,$statuses,true)||in_array(422,$statuses,true),'Salah satu operasi ditolak dengan konflik/kerahasiaan');}
}
exit($fail?1:0);
