<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/bootstrap.php';
if(PHP_SAPI!=='cli'||app_config('env')!=='testing'||app_config('database.database')!=='webalhasan_v4_fase2_test')exit(77);
$start=microtime(true)+1;$workers=[];
for($i=0;$i<6;$i++){$pipes=[];$p=proc_open([PHP_BINARY,__DIR__.'/v4_phase3_worker.php',(string)$start],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$workers[]=[$p,$pipes];}
$codes=[];foreach($workers as [$p,$pipes]){$codes[]=trim(stream_get_contents($pipes[1]));$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($p)!==0||$err!==''){echo 'Worker gagal';exit(1);}}
sort($codes);if($codes!==['200','409','409','409','409','409']){echo json_encode($codes);exit(1);}echo "[lulus] Enam proses/koneksi DB terpisah: satu 200 dan lima 409.\n";
$ids=json_decode(file_get_contents('/private/tmp/v4-fase3-ids.json'),true);$sid=(int)$ids['sikap_concurrent'];
$db=app_db();$n=(int)$db->query("SELECT COUNT(*) n FROM v4_sikap WHERE santri_id=$sid AND tahun_ajaran_id=4")->fetch_assoc()['n'];$h=(int)$db->query("SELECT COUNT(*) n FROM v4_sikap_riwayat h JOIN v4_sikap s ON s.id=h.sikap_id WHERE s.santri_id=$sid AND s.tahun_ajaran_id=4")->fetch_assoc()['n'];$b=(int)$db->query("SELECT COUNT(*) n FROM v4_bahan_riwayat h JOIN v4_bahan b ON b.id=h.bahan_id WHERE b.santri_id=$sid AND b.jenis='sikap'")->fetch_assoc()['n'];if($n!==1||$h!==1||$b!==1)exit(1);echo "[lulus] Tepat satu sikap, satu versi dan satu riwayat pemeriksaan.\n";

$pb=['id'=>(int)$db->query("SELECT id FROM users WHERE username='v4_pembimbing'")->fetch_assoc()['id']];$mu=['id'=>(int)$db->query("SELECT id FROM users WHERE username='v4_murobi'")->fetch_assoc()['id']];$pe=['id'=>(int)$db->query("SELECT id FROM users WHERE username='v4_pendidikan'")->fetch_assoc()['id']];
$sk=new App\V4\SikapService($db,audit_logger());$r=$sk->read($pb,$ids['rencana'],$sid);$r=$sk->save($pb,array_replace($r['sikap'],['rencana_id'=>$ids['rencana'],'action'=>'serahkan']));$sk->save($mu,['rencana_id'=>$ids['rencana'],'santri_id'=>$sid,'version'=>(int)$r['sikap']['version'],'action'=>'diketahui'],'murobi');
$vs=new App\V4\VerifikasiService($db,audit_logger());$m=array_values(array_filter($vs->read($pe,$ids['rencana'],$sid)['matriks'][0]['bahan'],fn($m)=>$m['jenis']==='sikap'))[0];
file_put_contents('/private/tmp/v4-fase3-verifikasi-concurrent.json',json_encode(['rencana_id'=>$ids['rencana'],'santri_id'=>$sid,'jenis'=>'sikap','mapel_key'=>0,'action'=>'verifikasi']+$m['pemeriksaan']));
$start=microtime(true)+1;$workers=[];
for($i=0;$i<6;$i++){$pipes=[];$p=proc_open([PHP_BINARY,__DIR__.'/v4_phase3_worker.php',(string)$start,'verifikasi'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$workers[]=[$p,$pipes];}
$codes=[];foreach($workers as [$p,$pipes]){$codes[]=trim(stream_get_contents($pipes[1]));$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($p)!==0||$err!=='')exit(1);}
sort($codes);if($codes!==['200','409','409','409','409','409']){echo json_encode($codes);exit(1);}echo "[lulus] Enam pemeriksa bersamaan: satu 200 dan lima 409.\n";
$n=(int)$db->query("SELECT COUNT(*) n FROM v4_bahan_riwayat h JOIN v4_bahan b ON b.id=h.bahan_id WHERE b.santri_id=$sid AND b.jenis='sikap'")->fetch_assoc()['n'];if($n!==4)exit(1);echo "[lulus] Verifikasi tidak menggandakan riwayat: tiga versi sumber dan satu pemeriksaan.\n";
