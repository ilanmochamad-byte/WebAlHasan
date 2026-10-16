<?php
require __DIR__.'/v4_phase4_support.php';
mysqli_report(MYSQLI_REPORT_OFF); // Same reporting mode as normal app connections.
$input=json_decode(file_get_contents('/private/tmp/v4-fase4-concurrent.json'),true);$mode=$argv[1]??'same';
if($mode==='different')$input['idempotency_key'].='-'.($argv[2]??'0');
$storage=null;
if(in_array($mode,['delay','crash'],true))$storage=new class($mode) extends App\V4\RaporStorage {
 public function __construct(private string $mode){parent::__construct();}
 public function write(string $n,string $b):void{parent::write($n,$b);file_put_contents('/private/tmp/v4-fase4-worker-marker',$n);if($this->mode==='crash')exit(99);usleep(2200000);}
};
try{(new App\V4\RaporService($db,audit_logger(),$storage))->act($pe,$input);echo '200';}catch(App\V4\V4Exception $e){echo $e->status;if($e->status===503)fwrite(STDERR,$e->getMessage());}
