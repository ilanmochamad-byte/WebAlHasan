<?php
declare(strict_types=1);
if(getenv('PERAPIHAN_AUDIT_DB')!=='1')exit(77);
require_once dirname(__DIR__).'/app/bootstrap.php';
if(!str_ends_with((string)app_config('database.database'),'_test'))exit(2);
$f=json_decode(file_get_contents((string)getenv('AUDIT_FIXTURE_MANIFEST')),true,512,JSON_THROW_ON_ERROR);
if($f['database']!==app_config('database.database'))exit(2);
$base='http://127.0.0.1:8940/api/v1';$token=null;$fail=0;$count=0;
$check=static function(bool $ok,string $label)use(&$fail,&$count):void{$count++;$fail+=!$ok;echo($ok?'[lulus] ':'[gagal] ').'A-09 '.$label.PHP_EOL;};
$request=static function(string $path,?array $data=null)use($base,&$token):array{
 $headers=['Accept: application/json'];if($token)$headers[]='Authorization: Bearer '.$token;
 $c=curl_init($base.$path);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30]);
 if($data!==null){$headers[]='Content-Type: application/json';curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($data)]);}
 curl_setopt($c,CURLOPT_HTTPHEADER,$headers);$raw=(string)curl_exec($c);
 return ['status'=>curl_getinfo($c,CURLINFO_RESPONSE_CODE),'json'=>json_decode($raw,true)];
};
try{
 $login=$request('/auth/login',['username'=>'sbx_guru_biasa','password'=>'Sandbox#123','device_name'=>'audit-api-baseline']);
 $token=$login['json']['data']['token']??null;
 $check($login['status']===200&&$token!==null,'login mobile guru tetap kompatibel');
 $r=$request('/reports');$data=$r['json']['data']??[];
 $check($r['status']===200&&($data['summary']['detail_count']??0)===31,'tanpa parameter tetap gabungan 31');
 $check(array_keys($data['filters'])===['date_from','date_to','academic_year_id','teacher_id','class_id','schedule_id','status'],'keys filters persis kontrak baseline');
 $check(!isset($data['active_filters']['Penyajian']),'active_filters tidak memuat penyajian web');
 if(($snapshot=getenv('AUDIT_BASELINE_API_SNAPSHOT'))&&is_file($snapshot)){
  $baseline=json_decode(file_get_contents($snapshot),true,512,JSON_THROW_ON_ERROR);
  $check($r['json']===$baseline,'seluruh envelope/payload tanpa parameter identik snapshot main pada fixture sama');
 }
 foreach(['santri','guru','gabungan','invalid'] as $scope){
  $s=$request('/reports?subject_scope='.$scope);
  $check($s['status']===200&&$s['json']===$r['json'],'parameter web '.$scope.' tidak mengubah API baseline');
 }
 $options=$request('/reports/filters');
 $check($options['status']===200&&array_keys($options['json']['data'])===['academic_years','teachers','classes','schedules','statuses'],'options API tidak memuat subject_scopes');
 $print=$request('/reports/print?'.http_build_query($f['filters']+['subject_scope'=>'santri']));
 $html=$print['json']['data']['html']??'';$doc=new DOMDocument();@$doc->loadHTML($html?:'<html/>');
 $text=(new DOMXPath($doc))->evaluate('string(//section[@class="summary"]/span[strong="Baris detail:"])');
 $check($print['status']===200&&preg_match('/Baris detail:\s*31\b/',$text)===1&&!str_contains($html,'Penyajian'),'cetak API tetap gabungan tanpa metadata web');
 $foreign=$request('/reports?'.http_build_query(['teacher_id'=>$f['other_teacher_id'],'subject_scope'=>'guru']));
 $check($foreign['status']===403&&($foreign['json']['error']['code']??'')==='FORBIDDEN','teacher_id asing tetap 403');
 $meeting=$request('/reports/meetings/'.(int)$f['meeting']);
 $check($meeting['status']===200&&count($meeting['json']['data']['students']??[])===30,'detail API tetap 30 peserta snapshot + kehadiran guru');
}finally{if($token)$request('/auth/logout',[]);}
echo "Total API kompatibilitas: $count, gagal: $fail\n";
exit($fail?1:0);
