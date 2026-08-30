<?php
declare(strict_types=1);
// Fixture tambahan audit: hanya dibuat secara eksplisit di database *_test.
// Dipertahankan untuk perbandingan HTTP main/branch dengan data yang identik.
if (getenv('PERAPIHAN_AUDIT_DB') !== '1') { exit(77); }
require_once dirname(__DIR__).'/app/bootstrap.php';
if (!str_ends_with((string)app_config('database.database'),'_test')) { exit(2); }
$out=getenv('AUDIT_FIXTURE_MANIFEST')?:sys_get_temp_dir().'/perapihan-audit-fixture.json';
if(is_file($out)) { echo "Manifest sudah tersedia; fixture tidak dibuat ulang.\n"; exit; }
$db=app_db(); $master=master_data_service(); $fail=0;
$check=static function(bool $ok,string $text)use(&$fail):void {echo ($ok?'[lulus] ':'[gagal] ').$text.PHP_EOL;$fail+=!$ok;};
$admin=auth_repository()->findActiveById((int)$db->query("SELECT id FROM users WHERE username='sbx_admin'")->fetch_assoc()['id']);
$teacher=auth_repository()->findActiveById((int)$db->query("SELECT id FROM users WHERE username='sbx_guru_biasa'")->fetch_assoc()['id']);
$other=auth_repository()->findActiveById((int)$db->query("SELECT id FROM users WHERE username='sbx_murobi_a'")->fetch_assoc()['id']);
$_SESSION['user_id']=$admin['id']; $suffix=bin2hex(random_bytes(4));
$year=(int)$db->query("SELECT id FROM tahun_ajaran WHERE status='Aktif' AND archived_at IS NULL LIMIT 1")->fetch_assoc()['id'];
$class=$master->saveClass(['nama_kelas'=>'Audit 30 '.$suffix,'jenjang'=>'Uji']);
$students=[];
for($i=0;$i<30;$i++) {
    $students[]=$id=$master->saveSantri(['nis'=>'AUD'.$suffix.$i,'nama_santri'=>'Audit Santri '.$suffix.' '.$i,'jenis_kelamin'=>'L','tgl_lahir'=>'2010-01-01']);
    $master->assignActiveClass(['santri_id'=>$id,'kelas_id'=>$class,'tanggal_mulai'=>date('Y-m-d')],$admin['id']);
}
$schedule=[];
foreach([$teacher,$other] as $index=>$user) {
    $schedule[]=(int)schedule_service()->save(['id_tahun'=>$year,'hari'=>'Minggu','waktu_sholat'=>"Ba'da Isya",'waktu_mulai'=>$index?'22:00':'20:00','waktu_selesai'=>$index?'23:00':'21:00','id_kelas'=>$class,'id_guru'=>$user['guru_id'],'fan_ilmu'=>'Audit Fikih '.$suffix,'nama_kitab'=>'Kitab Audit','tempat'=>'Ruang Audit '.$suffix],$admin['id'])['id'];
}
$repo=new App\Api\TeacherRepository($db);
$meeting=$repo->createOpenedMeeting($schedule[0],date('Y-m-d'),'Fixture audit 30/1/31',$teacher['id']);
$repo->snapshotParticipants($meeting,$class,$year);
$repo->upsertTeacherAttendance($meeting,$teacher['guru_id'],'Hadir',null,$teacher['id']);
foreach($students as $id) $repo->upsertStudentAttendance($meeting,$id,'Hadir',null,$teacher['id']);
$filters=['date_from'=>date('Y-m-d'),'date_to'=>date('Y-m-d'),'schedule_id'=>$schedule[0]];
foreach(['santri'=>30,'guru'=>1,'gabungan'=>31] as $scope=>$expected) {
    $r=report_service()->report($filters+['subject_scope'=>$scope,'per_page'=>100],$admin);
    $e=report_service()->exportRows($filters+['subject_scope'=>$scope],$admin);
    $check($r['summary']['detail_count']===$expected && count($r['items'])===$expected && count($e['items'])===$expected,'LAPORAN '.$scope.' ringkasan/detail/ekspor='.$expected);
}
$rejected=false;
try {report_service()->report($filters+['subject_scope'=>'guru','teacher_id'=>$other['guru_id']],$teacher);} catch(App\Api\ApiException $e) {$rejected=$e->status()===403;}
$check($rejected,'LAPORAN teacher_id orang lain + subject_scope=guru ditolak 403');
$a=$master->saveWali(['nama'=>'Audit Benturan A '.$suffix]); $b=$master->saveWali(['nama'=>'Audit Benturan B '.$suffix]);
foreach([$a,$b] as $wid) $master->attachWali($wid,['santri_id'=>$students[0],'hubungan'=>'Ayah'],$admin['id']);
$master->attachWali($a,['santri_id'=>$students[1],'hubungan'=>'Ayah'],$admin['id']);
$before=$db->query("SELECT * FROM santri_wali WHERE wali_id=$a AND santri_id=".$students[1])->fetch_assoc();
$merge=$master->mergeWali($a,$b,$admin['id'],true);
$check($merge['dipindahkan']===1&&$merge['diarsipkan']===1,'WALI relasi sama diarsipkan; relasi berbeda dipindah');
$after=$db->query('SELECT * FROM santri_wali WHERE id='.(int)$before['id'])->fetch_assoc();
$check((int)$after['wali_id']===$b && $after['active_guard']!==null && $after['archived_at']===null,'WALI relationRepoint mempertahankan ID dan generated active_guard aktif');
$check(!in_array($a,array_map('intval',array_column($master->waliCandidates('Audit Benturan',200),'id')),true),'WALI hasil merge tidak menjadi kandidat lagi');
$unknown=$master->saveSantri(['nis'=>'UNK'.$suffix,'nama_santri'=>'Audit Unknown '.$suffix,'jenis_kelamin'=>'L','tgl_lahir'=>'2010-01-01','wali'=>['TidakDikenal'=>['mode'=>'baru','nama'=>'Tidak boleh dibuat'],'Ayah'=>['mode'=>'abaikan','is_admin'=>1]]]);
$check($master->santriWali($unknown)===[],'WALI kunci tak dikenal tidak membuat relasi atau wali');
$manifest=['database'=>app_config('database.database'),'year'=>$year,'class'=>$class,'students'=>[...$students,$unknown],'wali'=>[$a,$b],'schedules'=>$schedule,'meeting'=>$meeting,'teacher_id'=>$teacher['guru_id'],'other_teacher_id'=>$other['guru_id'],'filters'=>$filters];
file_put_contents($out,json_encode($manifest,JSON_PRETTY_PRINT));
echo "Manifest fixture sintetis: $out\n";
exit($fail?1:0);
