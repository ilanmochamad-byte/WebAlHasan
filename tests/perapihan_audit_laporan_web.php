<?php
declare(strict_types=1);
if(getenv('PERAPIHAN_AUDIT_DB')!=='1') exit(77);
require_once dirname(__DIR__).'/app/bootstrap.php';
if(!str_ends_with((string)app_config('database.database'),'_test')) exit(2);
$fixture=json_decode(file_get_contents((string)getenv('AUDIT_FIXTURE_MANIFEST')),true,512,JSON_THROW_ON_ERROR);
if($fixture['database']!==app_config('database.database')) exit(2);
$base=getenv('BASE_URL')?:'http://127.0.0.1:8940';
if(!preg_match('#^http://127\.0\.0\.1:\d+$#',$base)) exit(2);
$fail=0;$jars=[];$count=0;
$check=static function(bool $ok,string $label)use(&$fail,&$count):void{$count++;$fail+=!$ok;echo ($ok?'[lulus] ':'[gagal] ').'A-07 '.$label.PHP_EOL;};
$request=static function(string $path,string $jar,?array $post=null)use($base):array{
 $c=curl_init($base.$path);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$jar,CURLOPT_COOKIEJAR=>$jar,CURLOPT_TIMEOUT=>30]);
 if($post!==null)curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($post)]);
 $body=(string)curl_exec($c);return ['status'=>curl_getinfo($c,CURLINFO_RESPONSE_CODE),'body'=>$body];
};
try {
 foreach(['sbx_admin','sbx_guru_biasa','sbx_murobi_a','sbx_pengurus_a','sbx_ortu_a'] as $user){
  $jar=tempnam(sys_get_temp_dir(),'audit-report-');$jars[$user]=$jar;
  $form=$request('/portal/',$jar);preg_match('/name="_csrf" value="([^"]+)"/',$form['body'],$m);
  $login=$request('/admin/cek_login.php',$jar,['_csrf'=>$m[1]??'','username'=>$user,'password'=>'Sandbox#123']);
  $check($login['status']===302,'login '.$user);
 }
 $guru=$jars['sbx_guru_biasa'];$admin=$jars['sbx_admin'];$meeting=(int)$fixture['meeting'];
 $baseFilter=$fixture['filters'];
 foreach(['santri'=>30,'guru'=>1,'gabungan'=>31] as $scope=>$expected){
  $q=http_build_query($baseFilter+['subject_scope'=>$scope,'per_page'=>100]);
  $screen=$request('/admin/admin_laporan_absensi.php?'.$q,$guru);
  $csv=$request('/admin/export_laporan_absensi.php?'.$q,$guru);
  $print=$request('/admin/laporan_absensi_cetak.php?'.$q,$guru);
  $check($screen['status']===200 && str_contains($screen['body'],'= '.$expected.' baris detail'),'layar guru scope '.$scope.' = '.$expected);
  $check($csv['status']===200 && substr_count($csv['body'],"\n")-1===$expected,'CSV guru scope '.$scope.' = '.$expected);
  $printDoc=new DOMDocument(); @$printDoc->loadHTML($print['body']);
  $printText=(new DOMXPath($printDoc))->evaluate('string(//section[@class="summary"]/span[strong="Baris detail:"])');
  $check($print['status']===200 && preg_match('/Baris detail:\s*'.$expected.'\b/',$printText)===1,'cetak guru scope '.$scope.' = '.$expected);
 }
 $check($request('/admin/laporan_absensi_detail.php?id='.$meeting,$guru)['status']===200,'detail pertemuan milik guru terbuka');
 $check($request('/admin/laporan_absensi_detail.php?id='.$meeting,$jars['sbx_murobi_a'])['status']===403,'detail guru lain ditolak 403 untuk murobi');
 foreach(['admin_laporan_absensi.php','export_laporan_absensi.php','laporan_absensi_cetak.php'] as $page){
  $q=http_build_query($baseFilter+['teacher_id'=>$fixture['other_teacher_id'],'subject_scope'=>'guru']);
  $check($request('/admin/'.$page.'?'.$q,$guru)['status']===403,$page.' teacher_id asing + scope guru ditolak');
  $own=$request('/admin/'.$page.'?'.http_build_query($baseFilter+['subject_scope'=>'gabungan']),$jars['sbx_murobi_a']);
  $check($own['status']===200 && !str_contains($own['body'],'Audit Santri '),$page.' schedule_id asing tidak membocorkan peserta');
 }
 foreach(['admin_laporan_absensi.php','laporan_absensi_detail.php','export_laporan_absensi.php','laporan_absensi_cetak.php'] as $page){
  $path='/admin/'.$page.'?id='.$meeting.'&'.http_build_query($baseFilter);
  foreach(['sbx_pengurus_a','sbx_ortu_a'] as $user)$check($request($path,$jars[$user])['status']===403,$page.' tetap 403 untuk '.$user);
  $check($request($path,$guru,['action'=>'save'])['status']===405,$page.' POST guru ditolak 405 tanpa mutasi');
 }
 foreach(['admin_akun.php','admin_master_santri.php','get_wali_json.php'] as $page)$check($request('/admin/'.$page,$guru)['status']===403,$page.' tetap khusus admin');
 $check($request('/admin/_laporan_guard.php',$admin)['status']===404,'guard helper langsung 404');
}finally{foreach($jars as $jar)unlink($jar);}
echo "Total laporan web: $count, gagal: $fail\n";
exit($fail?1:0);
