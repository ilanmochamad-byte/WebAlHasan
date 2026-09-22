<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/bootstrap.php';
if(getenv('V3_RUN_TESTS')!=='1'||app_config('database.database')!=='webalhasan_v3_phase1_test')exit(77);
$data=json_decode(stream_get_contents(STDIN),true);while(microtime(true)<$data['start'])usleep(1000);
try{
$result=match($data['action']){
 'publish'=>v3_publikasi_service()->publish($data['user'],$data['input']),
 'privacy'=>v3_konseling_service()->correctCase($data['user'],$data['id'],$data['input']),
 default=>throw new RuntimeException('Invalid action')};
echo json_encode(['status'=>$result['status'],'data'=>$result['data']]);
}catch(App\V3\V3Exception $e){echo json_encode(['status'=>$e->status]);}
