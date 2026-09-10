<?php

declare(strict_types=1);

use App\V3\V3Exception;

require_once dirname(__DIR__).'/app/bootstrap.php';
$currentUser=authorization()->requireWebUser();$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
if(!$id){http_response_code(404);exit;}
try{$file=v3_pelanggaran_service()->attachment($currentUser,(int)$id);}catch(V3Exception){http_response_code(404);exit;}
header('Content-Type: '.$file['mime']);header('Content-Length: '.(string)$file['size']);
header("Content-Disposition: attachment; filename*=UTF-8''".rawurlencode($file['name']));header('Cache-Control: private, no-store, max-age=0');
readfile($file['path']);exit;
