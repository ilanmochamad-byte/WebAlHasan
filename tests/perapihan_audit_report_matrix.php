<?php
declare(strict_types=1);
if(getenv('PERAPIHAN_AUDIT_DB')!=='1')exit(77);
require_once dirname(__DIR__).'/app/bootstrap.php';
if(!str_ends_with((string)app_config('database.database'),'_test'))exit(2);
$base=getenv('BASE_URL')?:'http://127.0.0.1:8940';if(!preg_match('#^http://127\.0\.0\.1:\d+$#',$base))exit(2);
$db=app_db(); mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);$master=master_data_service();$tag='R14'.bin2hex(random_bytes(4));$pass=0;$fail=0;$students=[];$schedules=[];$meetings=[];$class=null;$jars=[];$oracle=[];$pdfs=[];
$one=static fn($sql,$p=[])=>$db->execute_query($sql,$p)->fetch_assoc();
$check=static function($ok,$label)use(&$pass,&$fail){$ok?$pass++:$fail++;echo($ok?'[lulus] ':'[gagal] ').$label.PHP_EOL;};
$users=[];foreach(['sbx_admin','sbx_guru_biasa','sbx_murobi_a']as$name){$users[$name]=auth_repository()->findActiveById((int)$one('SELECT id FROM users WHERE username=?',[$name])['id']);}
$_SESSION['user_id']=$users['sbx_admin']['id'];$actor=(int)$_SESSION['user_id'];$year=(int)$one("SELECT id FROM tahun_ajaran WHERE status='Aktif' AND archived_at IS NULL")['id'];
$request=static function($path,$jar,$post=null)use($base){$c=curl_init($base.$path);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$jar,CURLOPT_COOKIEJAR=>$jar]);if($post!==null)curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($post)]);$body=curl_exec($c);return['status'=>curl_getinfo($c,CURLINFO_RESPONSE_CODE),'body'=>$body];};
$xpath=static function($html){$d=new DOMDocument();@$d->loadHTML('<?xml encoding="utf-8" ?>'.$html);return new DOMXPath($d);};
try{
 $class=$master->saveClass(['nama_kelas'=>$tag,'jenjang'=>'Uji']);
 foreach(range(0,4)as$i)$students[]=$master->saveSantri(['nis'=>$tag.$i,'nama_santri'=>$tag.' Santri '.$i,'jenis_kelamin'=>'L','tgl_lahir'=>'2010-01-01']);
 $teacherRepo=new App\Api\TeacherRepository($db);$statuses=App\Report\ReportFilter::STATUSES;
 foreach(['sbx_guru_biasa','sbx_murobi_a']as$j=>$name){
  $teacher=$users[$name];
  // Synthetic schedule rows are fixtures; no existing schedule/attendance edited.
  $db->execute_query("INSERT INTO jadwal_ngaji(id_tahun,id_kelas,id_guru,hari,waktu_sholat,waktu_mulai,waktu_selesai,jam,fan_ilmu,nama_kitab,tempat)VALUES(?,?,?,'Senin',\"Ba'da Isya\",'01:00','02:00','01 - 02',?,?,?)",[$year,$class,$teacher['guru_id'],$tag,$tag,$tag]);$schedule=$schedules[]=(int)$db->insert_id;
  foreach(['2026-06-01','2026-06-08','2026-06-15']as$di=>$date){
   $mid=$meetings[]=$teacherRepo->createOpenedMeeting($schedule,$date,$tag,$teacher['id']);
   foreach($students as$i=>$sid){$db->execute_query('INSERT INTO pertemuan_peserta(pertemuan_id,santri_id,nis_snapshot,nama_santri_snapshot,kelas_id_snapshot,tahun_ajaran_id_snapshot)VALUES(?,?,?,?,?,?)',[$mid,$sid,$tag.$i,$tag.' Santri '.$i,$class,$year]);$status=$statuses[($i+$di)%5];$teacherRepo->upsertStudentAttendance($mid,$sid,$status,$tag,$actor);$oracle[]=['date'=>$date,'schedule'=>$schedule,'teacher'=>(int)$teacher['guru_id'],'type'=>'Santri','name'=>$tag.' Santri '.$i,'status'=>$status,'meeting'=>$mid];}
   $status=$statuses[($j+$di)%5];$teacherRepo->upsertTeacherAttendance($mid,$teacher['guru_id'],$status,$tag,$actor);$oracle[]=['date'=>$date,'schedule'=>$schedule,'teacher'=>(int)$teacher['guru_id'],'type'=>'Guru','name'=>$one('SELECT nama_guru FROM guru WHERE id=?',[$teacher['guru_id']])['nama_guru'],'status'=>$status,'meeting'=>$mid];
  }
 }
 foreach(['sbx_admin','sbx_guru_biasa']as$name){
  $jar=$jars[]=tempnam(sys_get_temp_dir(),'r14-');$r=$request('/portal/',$jar);preg_match('/name="_csrf" value="([^"]+)"/',$r['body'],$m);$r=$request('/admin/cek_login.php',$jar,['_csrf'=>$m[1],'username'=>$name,'password'=>'Sandbox#123']);if($r['status']!==302)throw new RuntimeException('Fixture login failed');
  foreach(['santri','guru','gabungan']as$scope)foreach(['',...$statuses]as$status)foreach([['2026-06-01','2026-06-15'],['2026-06-08','2026-06-08'],['2026-06-16','2026-06-16']]as[$from,$to])foreach(['class','year','schedule','teacher']as$dimension){
   $filter=['date_from'=>$from,'date_to'=>$to,'class_id'=>$class,'subject_scope'=>$scope,'status'=>$status,'per_page'=>100];
   if($dimension==='year')$filter['academic_year_id']=$year;
   if($dimension==='schedule')$filter['schedule_id']=$schedules[0];
   if($dimension==='teacher')$filter['teacher_id']=$users['sbx_guru_biasa']['guru_id'];
   $expected=array_values(array_filter($oracle,static fn($r)=>$r['date']>=$from&&$r['date']<=$to&&($scope==='gabungan'||strtolower($r['type'])===$scope)&&($status===''||$r['status']===$status)&&(!isset($filter['schedule_id'])||$r['schedule']===$filter['schedule_id'])&&(!isset($filter['teacher_id'])||$r['teacher']===$filter['teacher_id'])&&($name==='sbx_admin'||$r['teacher']===$users[$name]['guru_id'])));
   $report=report_service()->report($filter,$users[$name]);$export=report_service()->exportCsvRows($filter,$users[$name]);$count=count($expected);
   $keys=static function($rows,$normal=false){$v=array_map(static fn($r)=>$normal?[$r['meeting_date'],$r['subject_type'],$r['subject_name'],$r['attendance_status']]:[$r['date'],$r['type'],$r['name'],$r['status']],$rows);sort($v);return$v;};
   $ok=$report['summary']['detail_count']===$count&&$report['pagination']['total']===$count&&$keys($report['items'],true)===$keys($expected)&&$keys($export['items'],true)===$keys($expected);
   $q=http_build_query($filter);$screen=$request('/admin/admin_laporan_absensi.php?'.$q,$jar);$csv=$request('/admin/export_laporan_absensi.php?'.$q,$jar);$print=$request('/admin/laporan_absensi_cetak.php?'.$q,$jar);
   $screenx=$xpath($screen['body']);$screenRows=$screenx->query('//table[caption="Baris kehadiran sesuai filter"]/tbody/tr');$got=[];foreach($screenRows as$tr){$td=$screenx->query('td',$tr);$nameText=$td->item(4)->firstChild?->textContent??'';$got[]=[trim($td->item(0)->textContent),trim($td->item(3)->textContent),trim($nameText),trim($td->item(5)->firstChild->textContent)];}sort($got);
   $lines=preg_split('/\r?\n/',trim(ltrim($csv['body'],"\xEF\xBB\xBF")));array_shift($lines);$csvRows=[];foreach($lines as$line){if($line==='')continue;$r=str_getcsv($line,',','"','');$csvRows[]=[$r[1],$r[9],$r[11],$r[12]];}sort($csvRows);
   $px=$xpath($print['body']);$printNodes=$px->query('//table/tbody/tr[td and not(td/@colspan)]');$printCount=$printNodes->length;
   $printRows=[];foreach($printNodes as$tr){$td=$px->query('td',$tr);$printRows[]=[trim($td->item(1)->textContent),trim($td->item(5)->firstChild->textContent),trim($td->item(6)->textContent),trim($td->item(7)->textContent)];}sort($printRows);
   $ok=$ok&&$screen['status']===200&&$csv['status']===200&&$print['status']===200&&$got===$keys($expected)&&$csvRows===$keys($expected)&&$printCount===$count&&$printRows===$keys($expected);
   if (!$ok && $fail<2) echo 'DIAG '.json_encode(['summary'=>$report['summary']['detail_count'],'expected'=>$count,'service'=>$keys($report['items'],true)===$keys($expected),'screen'=>$got===$keys($expected),'csv'=>$csvRows===$keys($expected),'print'=>$printCount,'got'=>$got,'expectedKeys'=>$keys($expected),'HTTP'=>[$screen['status'],$csv['status'],$print['status']]]).PHP_EOL;
   $check($ok,"$name/$scope/".($status?:'Semua')."/$from..$to/$dimension count=$count service+screen+CSV+print");
   if($dimension==='class'&&$status===''&&$from==='2026-06-01'&&$name==='sbx_admin')$pdfs[$scope]=['html'=>$print['body'],'expected'=>$keys($expected),'count'=>$count];
  }
 }
 if($out=getenv('AUDIT_REPORT_PDFS')){if(!is_dir($out))mkdir($out,0700,true);foreach($pdfs as$scope=>$p){file_put_contents($out.'/'.$scope.'.html',$p['html']);unset($p['html']);file_put_contents($out.'/'.$scope.'.json',json_encode($p,JSON_PRETTY_PRINT));}}
}finally{
 foreach($jars as$jar)unlink($jar);
 foreach($meetings as$id){foreach(['absensi_santri','absensi_guru','pertemuan_peserta']as$t)$db->query("DELETE FROM $t WHERE pertemuan_id=$id");$db->query("DELETE FROM pertemuan_pengajian WHERE id=$id");}
 foreach($schedules as$id)$db->query("DELETE FROM jadwal_ngaji WHERE id=$id");
 foreach($students as$id){$db->execute_query("DELETE FROM audit_logs WHERE entity_type='santri' AND entity_id=?",[$id]);$db->query("DELETE FROM santri WHERE id=$id");}
 if($class){$db->execute_query("DELETE FROM audit_logs WHERE entity_type='kelas' AND entity_id=?",[$class]);$db->query("DELETE FROM kelas WHERE id=$class");}
}
echo"Total matriks laporan: $pass lulus, $fail gagal\n";exit($fail?1:0);
