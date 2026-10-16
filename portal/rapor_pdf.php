<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/bootstrap.php';
use App\V4\{RaporService,V4Exception};
header('Cache-Control: private, no-store');header('Referrer-Policy: no-referrer');
$user=authorization()->requireWebUser();$id=(int)($_GET['id']??0);$v=(int)($_GET['version']??0);
try{$bytes=(new RaporService(app_db(),audit_logger()))->pdf($user,$id,$v,($_GET['petugas']??'0')==='1');RaporService::stream($bytes,$id,$v);}catch(V4Exception $e){http_response_code($e->status);echo ah_e($e->getMessage());}
