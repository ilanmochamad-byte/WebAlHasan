<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/bootstrap.php';
if(PHP_SAPI!=='cli'||app_config('env')!=='testing'||app_config('database.database')!=='webalhasan_v4_fase2_test')exit(77);
$start=microtime(true)+1;$workers=[];
for($i=0;$i<6;$i++){$pipes=[];$p=proc_open([PHP_BINARY,'-d','mysqli.default_socket='.ini_get('mysqli.default_socket'),__DIR__.'/v4_phase2_worker.php',(string)$start],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$workers[]=[$p,$pipes];}
$codes=[];foreach($workers as [$p,$pipes]){$codes[]=trim(stream_get_contents($pipes[1]));$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($p)!==0||$err!==''){echo 'Worker gagal';exit(1);}}
sort($codes);if($codes!==['200','409','409','409','409','409']){echo json_encode($codes);exit(1);}
echo "[lulus] Enam proses PHP/koneksi DB bersamaan: satu 200, lima 409.\n";
$ids=json_decode(file_get_contents('/private/tmp/v4-fase2-ids.json'),true);$m=(int)$ids['mapel'];$s=(int)$ids['concurrent'];$r=app_db()->query("SELECT COUNT(*) n FROM v4_nilai WHERE rencana_mapel_id=$m AND santri_id=$s")->fetch_assoc();
$h=app_db()->query("SELECT COUNT(*) n FROM v4_nilai_riwayat h JOIN v4_nilai n ON n.id=h.nilai_id WHERE n.rencana_mapel_id=$m AND n.santri_id=$s")->fetch_assoc();
if((int)$r['n']!==1||(int)$h['n']!==1)exit(1);echo "[lulus] Tepat satu komponen berlaku dan satu revisi, tanpa duplikasi.\n";
