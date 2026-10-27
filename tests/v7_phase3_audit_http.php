<?php
declare(strict_types=1);

// Regresi HTTP koreksi privasi auditor. DB harus fixture sintetis Fase 3.
if (getenv('V7_RUN_AUDIT')!=='1' || !preg_match('/^test_[A-Za-z0-9_]*_test$/D',(string)getenv('DB_NAME'))) exit(2);
$root=dirname(__DIR__);
require_once $root.'/app/bootstrap.php';
$port=(int)(getenv('V7_AUDIT_PORT')?:9017);
$base='http://127.0.0.1:'.$port;
$log=tempnam(sys_get_temp_dir(),'audit-v7f3-http-');
$server=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,$root.'/tests/redesain_web_tahap1_router.php'],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,$root,getenv());
register_shutdown_function(static function() use ($server): void { if(is_resource($server)) proc_terminate($server); });
for($i=0;$i<50;$i++) { $sock=@fsockopen('127.0.0.1',$port); if($sock!==false){fclose($sock);break;} usleep(100000); }
$request=static function(string $path,?string $token=null,?array $body=null) use ($base): array {
    $h=curl_init($base.'/api/v1'.$path);
    $headers=['Accept: application/json','Content-Type: application/json'];
    if($token!==null) $headers[]='Authorization: Bearer '.$token;
    curl_setopt_array($h,[CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_TIMEOUT=>20]);
    if($body!==null) {curl_setopt($h,CURLOPT_POST,true);curl_setopt($h,CURLOPT_POSTFIELDS,json_encode($body));}
    $raw=(string)curl_exec($h); $code=(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE);$size=(int)curl_getinfo($h,CURLINFO_HEADER_SIZE);curl_close($h);
    return [$code,json_decode(substr($raw,$size),true),substr($raw,0,$size)];
};
$pass=$fail=0;
$assert=static function(bool $ok,string $label) use (&$pass,&$fail): void {echo ($ok?'[lulus] ':'[gagal] ').$label.PHP_EOL;$ok?$pass++:$fail++;};
$tokens=[];
foreach(['sbx_v7_wali_a','sbx_v7_wali_b','sbx_admin'] as $name) {
    [$code,$body]=$request('/auth/login',null,['username'=>$name,'password'=>'Sandbox#123','device_name'=>'audit-v7f3']);
    $token=(string)($body['data']['token']??'');$tokens[]=$token;
    $assert($code===200 && $token!=='',"Login API $name");
    [$code,$body,$header]=$request('/v7/izin/laporan',$token);
    $isAdmin=$name==='sbx_admin';
    $assert($code===200 && isset($body['data']['ringkasan']['rerata_lama_jam'])===$isAdmin && str_contains(strtolower($header),'no-store'),"API $name: proyeksi durasi sesuai peran dan no-store");
    foreach(['pembimbing_id'=>'1','murobi_id'=>'1','durasi_min_jam'=>'1','durasi_maks_jam'=>'1'] as $field=>$v) {
        foreach(['/v7/izin/laporan','/v7/izin/laporan/ekspor'] as $endpoint) {
            [$code]=$request($endpoint.'?'.http_build_query([$field=>$v]),$token);
            $assert($code===($isAdmin?200:422),"API $name $endpoint: filter $field HTTP $code");
        }
    }
}
foreach(['/v7/izin/laporan','/v7/izin/laporan/ekspor'] as $endpoint) {
    [$code]=$request($endpoint);$assert($code===401,"API anonim $endpoint ditolak 401");
}
$raw=(string)file_get_contents($log);$clean=true;
foreach($tokens as $t) if($t!=='' && str_contains($raw,$t)) $clean=false;
$assert($clean && !preg_match('/PHP (Warning|Fatal|Notice)|Uncaught/',$raw),'Log server probe tanpa token mentah atau galat PHP');
echo "Hasil: $pass lulus, $fail gagal\n";
exit($fail===0?0:1);
