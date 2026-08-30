<?php
declare(strict_types=1);
if(getenv('PERAPIHAN_AUDIT_DB')!=='1')exit(77);
require_once dirname(__DIR__).'/app/bootstrap.php';
if(!str_ends_with((string)app_config('database.database'),'_test'))exit(2);
$fixture=json_decode(file_get_contents(getenv('AUDIT_FIXTURE_MANIFEST')?:throw new RuntimeException('Fixture manifest required')),true,512,JSON_THROW_ON_ERROR);
$ui=json_decode(file_get_contents(getenv('AUDIT_UI_MANIFEST')?:throw new RuntimeException('UI fixture manifest required')),true,512,JSON_THROW_ON_ERROR);
$base=getenv('BASE_URL')?:'http://127.0.0.1:8940';if(!preg_match('#^http://127\.0\.0\.1:\d+$#',$base))exit(2);
$jar=tempnam(sys_get_temp_dir(),'a14-form-');$pass=0;$fail=0;
$request=static function(string $path,?array $post=null)use($base,$jar):array{$c=curl_init($base.$path);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$jar,CURLOPT_COOKIEJAR=>$jar,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>4]);if($post!==null)curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($post)]);$body=curl_exec($c);return['body'=>$body,'url'=>curl_getinfo($c,CURLINFO_EFFECTIVE_URL),'status'=>curl_getinfo($c,CURLINFO_RESPONSE_CODE)];};
$dom=static function(string $html):DOMXPath{$d=new DOMDocument();@$d->loadHTML('<?xml encoding="utf-8" ?>'.$html);return new DOMXPath($d);};
$check=static function(bool $ok,string $label)use(&$pass,&$fail):void{$ok?$pass++:$fail++;echo($ok?'[lulus] ':'[gagal] ').$label.PHP_EOL;};
try{
 $r=$request('/portal/');preg_match('/name="_csrf" value="([^"]+)"/',$r['body'],$m);$r=$request('/admin/cek_login.php',['_csrf'=>$m[1],'username'=>'sbx_admin','password'=>'Sandbox#123']);
 $cases=[
  ['pengurus','/admin/admin_pengurus.php?action=create',['action'=>'save','nama'=>'','nomor_identitas'=>'A14TEST','no_hp'=>'081234567890','jabatan'=>'Tetap <uji>'],['jabatan'=>'Tetap <uji>'],'nama'],
  ['kelas','/admin/admin_kelas.php?action=create',['action'=>'save','nama_kelas'=>'Tetap kelas','jenjang'=>''],['nama_kelas'=>'Tetap kelas'],'jenjang'],
  ['tahun','/admin/admin_tahun.php?action=create',['action'=>'save','tahun'=>'salah','semester'=>'Genap'],['tahun'=>'salah','semester'=>'Genap'],'tahun'],
  ['guru','/admin/admin_guru.php?action=create',['action'=>'save','nama_guru'=>'Guru Tetap','nip'=>'KEEP','no_hp'=>'123'],['nama_guru'=>'Guru Tetap','nip'=>'KEEP','no_hp'=>'123'],'no_hp'],
  ['wali','/admin/admin_wali.php?action=create',['action'=>'save','nama'=>'Wali Tetap','no_hp'=>'123','alamat'=>'Alamat <uji>'],['nama'=>'Wali Tetap','alamat'=>'Alamat <uji>'],'no_hp'],
  ['kamar','/admin/admin_kamar.php?action=create',['action'=>'save','nama_kamar'=>'Kamar Tetap','kapasitas'=>'0'],['nama_kamar'=>'Kamar Tetap','kapasitas'=>'0'],'kapasitas'],
  ['murobi','/admin/admin_murobi.php',['action'=>'save','guru_id'=>'0','tahun_ajaran_id'=>(string)$fixture['year'],'target_type'=>'Kelas','kelas_id'=>(string)$fixture['class'],'tanggal_mulai'=>'2026-09-01','tanggal_selesai'=>'2026-08-01'],['target_type'=>'Kelas','kelas_id'=>(string)$fixture['class'],'tanggal_mulai'=>'2026-09-01'],'guru_id'],
  ['pembimbing','/admin/admin_pembimbing.php',['action'=>'save','pengurus_id'=>'0','tahun_ajaran_id'=>(string)$fixture['year'],'target_type'=>'Kelas','kelas_id'=>(string)$fixture['class'],'tanggal_mulai'=>'2026-09-01','tanggal_selesai'=>'2026-08-01'],['target_type'=>'Kelas','kelas_id'=>(string)$fixture['class'],'tanggal_mulai'=>'2026-09-01'],'pengurus_id'],
  ['pertemuan','/admin/admin_pengajian.php?tab=pertemuan',['tab'=>'pertemuan','action'=>'draft','schedule_id'=>(string)$fixture['schedules'][0],'tanggal_pertemuan'=>'2026-08-31','catatan'=>'Catatan tetap'],['tanggal_pertemuan'=>'2026-08-31','catatan'=>'Catatan tetap'],'tanggal_pertemuan'],
  ['akun-guru','/admin/admin_akun.php',['action'=>'create_guru','guru_id'=>'0','name'=>'Akun Tetap','username'=>'x','email'=>'','phone'=>'081234567890'],['name'=>'Akun Tetap','username'=>'x'],'name'],
  ['santri','/admin/admin_master_santri.php?action=create',['action'=>'save','nis'=>'','nama_santri'=>'Santri Tetap','jenis_kelamin'=>'L','tgl_lahir'=>'2010-02-01','alamat'=>'Alamat tetap'],['nama_santri'=>'Santri Tetap','tgl_lahir'=>'2010-02-01'],'nis'],
  ['akun-link','/admin/admin_akun.php',['action'=>'link','kind'=>'orang_tua','user_id'=>'0','master_id'=>(string)$ui['wali'][0]],['kind'=>'orang_tua','master_id'=>(string)$ui['wali'][0]],'user_id'],
  ['akun-pengurus','/admin/admin_akun.php',['action'=>'create','kind'=>'pengurus','pengurus_id'=>(string)$ui['pengurus'],'name'=>'Akun Pengurus Tetap','username'=>'x','email'=>'','phone'=>'081234567890'],['name'=>'Akun Pengurus Tetap','pengurus_id'=>(string)$ui['pengurus'],'username'=>'x'],'username'],
  ['akun-ortu','/admin/admin_akun.php',['action'=>'create','kind'=>'orang_tua','wali_id'=>(string)$ui['wali'][0],'name'=>'Akun Wali Tetap','username'=>'x','email'=>'','phone'=>'081234567890'],['name'=>'Akun Wali Tetap','wali_id'=>(string)$ui['wali'][0],'username'=>'x'],'username'],
  ['jadwal','/admin/admin_pengajian.php?tab=jadwal&action=create',['action'=>'save','tab'=>'jadwal','id_tahun'=>(string)$fixture['year'],'id_kelas'=>(string)$fixture['class'],'id_guru'=>'0','hari'=>'Senin','waktu_sholat'=>"Ba'da Shubuh",'waktu_mulai'=>'06:00','waktu_selesai'=>'07:00','tempat'=>'Ruang <uji>','fan_ilmu'=>'Ilmu tetap','nama_kitab'=>'Kitab tetap'],['tempat'=>'Ruang <uji>','fan_ilmu'=>'Ilmu tetap','hari'=>'Senin'],'id_guru'],
  ['wali-attach','/admin/admin_wali.php?action=detail&id='.$ui['wali'][0],['action'=>'attach','id'=>(string)$ui['wali'][0],'santri_id'=>(string)$ui['students'][0],'hubungan'=>''],['santri_id'=>(string)$ui['students'][0]],'hubungan'],
  ['kelas-assign','/admin/admin_kelas.php',['action'=>'assign','santri_id'=>'0','kelas_id'=>(string)$fixture['class'],'tanggal_mulai'=>'2026-08-30'],['kelas_id'=>(string)$fixture['class'],'tanggal_mulai'=>'2026-08-30'],'santri_id'],
 ];
 foreach($cases as[$label,$path,$input,$expected,$errorField]){
  $r=$request($path);preg_match('/name="_csrf" value="([^"]+)"/',$r['body'],$m);$input['_csrf']=$m[1]??'';
  if(preg_match('/name="form_token" value="([^"]+)"/',$r['body'],$m))$input['form_token']=$m[1];
  $input['password']='DO-NOT-REFLECT-A14';$input['konfirmasi_admin']='DO-NOT-REFLECT-A14';
  $r=$request($path,$input);$x=$dom($r['body']);
  $check($r['status']<500&&str_contains($r['body'],'ah-note--danger'),$label.' validation error shown');
  foreach($expected as$field=>$value){$nodes=$x->query('//form[@method="post"]//*[self::input or self::select or self::textarea][@name="'.$field.'"]');$found=false;foreach($nodes as$n){$actual=$n->tagName==='select'?$x->evaluate('string(.//option[@selected]/@value)',$n):($n->tagName==='textarea'?$n->textContent:$n->getAttribute('value'));if($n->tagName==='select'&&$actual==='')$actual=$x->evaluate('string(.//option[@selected])',$n);if($actual===$value)$found=true;}$check($found,$label.' retained '.$field);}
  $check($x->query('//*[@data-error-for="'.$errorField.'"]')->length>0,$label.' error next to '.$errorField);
  $check(!str_contains($r['body'],'DO-NOT-REFLECT-A14'),$label.' no secret or confirmation echoed');
 }
 // Reject before any workflow mutation; use existing sandbox details read-only.
 foreach([['putuskan',56],['tetapkan',81],['batalkan',56],['koreksi',1]]as[$action,$id]){
  $r=$request('/portal/izin_detail.php?id='.$id);$x=$dom($r['body']);$form=$x->query('//form[input[@name="aksi" and @value="'.$action.'"]]')->item(0);if(!$form)throw new RuntimeException('Missing sandbox action '.$action);
  $input=[];foreach($x->query('.//input[@type="hidden"]',$form)as$n)$input[$n->getAttribute('name')]=$n->getAttribute('value');
  $input+=['hasil'=>'Disetujui','alasan'=>'x','alasan_penggantian'=>'Alasan penggantian tetap','alasan_koreksi'=>'Alasan koreksi tetap','murobi_guru_id'=>(string)$fixture['teacher_id']];
  $r=$request('/portal/izin_aksi.php',$input);$check($r['status']===422,'portal '.$action.' validation still 422');
  $r=$request('/portal/izin_detail.php?id='.$id);$x=$dom($r['body']);$form=$x->query('//form[input[@name="aksi" and @value="'.$action.'"]]')->item(0);
  $check($form&&$x->evaluate('string(.//textarea[@name="alasan"])',$form)==='x'&&$x->query('.//*[@data-error-for="alasan"]',$form)->length===1,'portal '.$action.' reason and field error restored');
 }
 // GET filter validation must keep the chosen mode and dates, with no export links.
 $r=$request('/admin/admin_laporan_absensi.php?date_from=2026-09-10&date_to=2026-09-01&subject_scope=guru&status=Hadir');$x=$dom($r['body']);
 $check($r['status']===422&&$x->evaluate('string(//input[@name="date_from"]/@value)')==='2026-09-10'&&$x->evaluate('string(//input[@name="date_to"]/@value)')==='2026-09-01','report invalid date range remains editable');
 $check($x->evaluate('string(//select[@name="subject_scope"]/option[@selected]/@value)')==='guru'&&$x->query('//*[@data-error-for="date_to"]')->length===1,'report scope retained and date error associated');
 $check(!str_contains($r['body'],'href="export_laporan_absensi.php?'),'invalid filter has no export action');
 foreach([['wrong','StrongAudit#123','StrongAudit#123','password_saat_ini'],['Sandbox#123','short','short','password_baru'],['Sandbox#123','StrongAudit#123','OtherAudit#123','konfirmasi_password']]as[$old,$new,$confirm,$field]){
  $r=$request('/admin/ubah_password.php');preg_match('/name="_csrf" value="([^"]+)"/',$r['body'],$m);$r=$request('/admin/ubah_password.php',['_csrf'=>$m[1],'password_saat_ini'=>$old,'password_baru'=>$new,'konfirmasi_password'=>$confirm]);$x=$dom($r['body']);
  $check($x->query('//input[@name="'.$field.'" and @aria-invalid="true"]')->length===1,'password inline error '.$field);
  $check($x->query('//input[@type="password" and @value!=""]')->length===0&&!str_contains($r['body'],'StrongAudit#123'),'password fields never retained');
 }
 // The rejected POST remains 422; the correction link restores page-3 student selection.
 $path='/portal/izin_buat.php?mode=admin&page=3&q='.rawurlencode($ui['tag']).'&santri_id='.$ui['students'][44];$r=$request($path);preg_match('/name="_csrf" value="([^"]+)"/',$r['body'],$m);
 $r=$request('/portal/izin_aksi.php',['_csrf'=>$m[1],'aksi'=>'buat','idempotency_key'=>bin2hex(random_bytes(16)),'mode'=>'admin','santri_id'=>$ui['students'][44],'return_page'=>3,'return_q'=>$ui['tag'],'tgl_izin'=>'2026-09-10','tgl_kembali'=>'2026-09-01','alasan'=>'Alasan tetap <uji>','catatan_pengurus'=>'Catatan tetap']);$x=$dom($r['body']);$link=$x->evaluate('string(//a[contains(text(),"Perbaiki isian")]/@href)');
 $check($r['status']===422&&str_contains($link,'page=3'),'portal invalid POST keeps 422 and safe correction link');
 $r=$request($link);$x=$dom($r['body']);$check($x->evaluate('string(//select[@name="santri_id"]/option[@selected]/@value)')===(string)$ui['students'][44]&&$x->evaluate('string(//textarea[@name="alasan"])')==='Alasan tetap <uji>','portal page-3 student and reason retained');
 $check($x->query('//*[@data-error-for="tgl_kembali"]')->length===1,'portal return-date error near field');
}finally{unlink($jar);}
echo"Total feedback formulir: $pass lulus, $fail gagal\n";exit($fail?1:0);
