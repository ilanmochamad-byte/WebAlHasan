<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/bootstrap.php';
if(PHP_SAPI!=='cli'||app_config('env')!=='testing'||app_config('database.database')!=='webalhasan_v4_fase2_test')exit(77);
$db=app_db();$ids=json_decode(file_get_contents('/private/tmp/v4-fase3-ids.json'),true);
$uid=(int)$db->query("SELECT id FROM users WHERE username='v4_pembimbing'")->fetch_assoc()['id'];
$in=['rencana_id'=>$ids['rencana'],'santri_id'=>$ids['sikap_concurrent'],'version'=>0,'adab'=>'Baik','kedisiplinan'=>'Baik','tanggung_jawab'=>'Baik','catatan'=>'Uji bersamaan'];
$end=(float)$argv[1];while(microtime(true)<$end)usleep(1000);
try{if(($argv[2]??'')==='verifikasi'){$uid=(int)$db->query("SELECT id FROM users WHERE username='v4_pendidikan'")->fetch_assoc()['id'];(new App\V4\VerifikasiService($db,audit_logger()))->act(['id'=>$uid],json_decode(file_get_contents('/private/tmp/v4-fase3-verifikasi-concurrent.json'),true));}else{(new App\V4\SikapService($db,audit_logger()))->save(['id'=>$uid],$in);}echo '200';}catch(App\V4\V4Exception $e){echo $e->status;}
