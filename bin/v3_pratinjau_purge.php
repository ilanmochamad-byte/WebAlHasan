<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/app/bootstrap.php';
$o=getopt('',['apply','actor:','batch:']);
try{
    $r=new App\V3\KonselingRepository(app_db());
    $result=(new App\V3\PratinjauRetention($r,new App\Audit\AuditLogger(app_db())))->run(isset($o['apply']),(int)($o['batch']??100),(int)($o['actor']??0));
    echo json_encode($result,JSON_PRETTY_PRINT).PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,'Purge tidak selesai: '.$e->getMessage().PHP_EOL);exit(1);}
