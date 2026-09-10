<?php

declare(strict_types=1);

// Router localhost khusus uji browser Fase 2. Meniru rewrite API produksi,
// sementara berkas dan direktori web tetap dilayani server bawaan PHP.
$path=(string)parse_url((string)($_SERVER['REQUEST_URI']??'/'),PHP_URL_PATH);
if(str_starts_with($path,'/api/v1')){
    require dirname(__DIR__).'/api/v1/index.php';
    return true;
}
$target=dirname(__DIR__).$path;
if($path!=='/'&&(is_file($target)||is_dir($target))){return false;}
http_response_code(404);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success'=>false,'data'=>null,'error'=>['code'=>'NOT_FOUND','message'=>'Rute tidak ditemukan.','details'=>[]]]);
return true;
