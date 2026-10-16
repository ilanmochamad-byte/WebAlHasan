<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/bootstrap.php';
if(PHP_SAPI!=='cli'||app_config('env')!=='testing'||app_config('database.database')!=='webalhasan_v4_fase2_test')exit(77);
$ids=json_decode(file_get_contents('/private/tmp/v4-fase2-ids.json'),true);
$start=(float)($argv[1]??0);while(microtime(true)<$start)usleep(1000);
try{
 $s=new App\V4\NilaiService(app_db(),new App\Auth\Capabilities(app_db()),audit_logger());
 $s->save(['id'=>$ids['guru'],'roles'=>[]],['rencana_mapel_id'=>$ids['mapel'],'santri_id'=>$ids['concurrent'],'komponen'=>[['komponen'=>'Harian','nomor_harian'=>1,'nilai'=>80,'version'=>0]]]);echo "200\n";
}catch(App\V4\V4Exception $e){echo $e->status."\n";}
