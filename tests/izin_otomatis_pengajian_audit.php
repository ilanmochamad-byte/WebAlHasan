<?php
declare(strict_types=1);
/** Independent adversarial probe. Only synthetic test_*_test databases. */
$dbName = getenv('IOP_DB') ?: '';
if (!preg_match('/^test_.*_test$/', $dbName)) exit(2);
putenv('DB_NAME=' . $dbName);
require dirname(__DIR__) . '/app/bootstrap.php';
$db = app_db();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$ids = require __DIR__ . '/jadwal_kelas_gabungan_f1_seed.php';
$n=0; $f=0;
$ok = static function(bool $v,string $s) use (&$n,&$f):void {echo ($v?'[lulus] ':'[gagal] ').$s."\n"; $v?$n++:$f++;};
$one = static fn(string $q):mixed => $db->query($q)->fetch_row()[0]??null;
$admin=['id'=>$ids['admin_id'],'roles'=>['admin'],'guru_id'=>null];
$guru=['id'=>$ids['guru_user_id'],'roles'=>['guru'],'guru_id'=>$ids['guru_g']];
$other=['id'=>$ids['guru_user_id'],'roles'=>['guru'],'guru_id'=>$ids['guru_h']];
$student=$ids['santri']['C'][0]; $second=$ids['santri']['C'][1];
$base=['id_tahun'=>$ids['tahun'],'id_kelas'=>$ids['kelas']['C'],'id_guru'=>$ids['guru_g'],'hari'=>'Kamis','waktu_mulai'=>'07:00','waktu_selesai'=>'07:30','waktu_sholat'=>"Ba'da Isya",'fan_ilmu'=>'Audit '.$ids['sfx'],'nama_kitab'=>'Sintetis','tempat'=>'Audit'];
$sc=0;
for($minute=7*60;$minute<19*60;$minute+=30){
 $slot=array_replace($base,['waktu_mulai'=>sprintf('%02d:%02d',intdiv($minute,60),$minute%60),'waktu_selesai'=>sprintf('%02d:%02d',intdiv($minute+25,60),($minute+25)%60)]);
 try{$sc=(int)schedule_service()->save($slot,$ids['admin_id'])['id'];break;}catch(App\Schedule\ScheduleException $e){if(!str_contains($e->getMessage(),'Bentrok'))throw $e;}
}
if(!$sc)throw new RuntimeException('No free fixture schedule slot');
$date=(new DateTimeImmutable('next thursday'))->modify('+70 days')->format('Y-m-d');
$private='PRIVATE-AUDITOR-'.bin2hex(random_bytes(5));
$create=static function(string $status,?string $hasil,string $cap='Murobi',string $asal='Pengurus',int $offset=0) use($db,$ids,$date,$student,$private):int {
 $d=(new DateTimeImmutable($date))->modify("$offset days")->format('Y-m-d');
 $st=$db->prepare('INSERT INTO izin_pengajuan(santri_id,murobi_guru_id,tahun_ajaran_id,tgl_izin,tgl_kembali,alasan,catatan_pengurus,catatan_wali,status,asal_pengajuan,wali_id,diajukan_pada) VALUES(?,?,?,?,?,?,?,?,?,?,?,NOW())');
 $st->execute([$student,$ids['guru_h'],$ids['tahun'],$d,$d,$private,$private,$private,$status,$asal,$asal==='Wali'?(int)$db->query('SELECT id FROM wali LIMIT 1')->fetch_row()[0]:null]);$id=(int)$db->insert_id;
 if($hasil!==null){$st=$db->prepare('INSERT INTO izin_keputusan(pengajuan_id,hasil,alasan,diputus_oleh_user_id,kapasitas,alasan_penggantian,diputus_pada) VALUES(?,?,?,?,?,?,NOW())');$st->execute([$id,$hasil,$private,$ids['guru_user_id'],$cap,$private]);}
 return $id;
};
$m=schedule_service()->open($sc,$date,'',$admin);
$hint=static fn():mixed=>$one("SELECT izin_pengajuan_id FROM pertemuan_peserta WHERE pertemuan_id=$m AND santri_id=$student");
// Each status isolated: no grouped negatives can hide a missing exclusion.
foreach(['Diajukan','Perlu Penetapan Admin','Menunggu Pembimbing','Perlu Penetapan Pembimbing','Perlu Perbaikan Wali','Ditolak','Ditolak Pembimbing','Dibatalkan'] as $st){
 $id=$create($st,'Disetujui','Murobi',in_array($st,['Menunggu Pembimbing','Perlu Penetapan Pembimbing','Perlu Perbaikan Wali','Ditolak Pembimbing'],true)?'Wali':'Pengurus');teacher_api_service()->refreshParticipants($m,$guru);$ok($hint()===null,"status $st sendiri tidak mengusulkan Izin");$db->query("UPDATE izin_pengajuan SET status='Dibatalkan' WHERE id=$id");
}
foreach([[null,0],['Ditolak',0],['Disetujui',1],['Disetujui',-1]] as [$h,$o]){
 $id=$create('Disetujui',$h,'Murobi','Pengurus',$o);teacher_api_service()->refreshParticipants($m,$guru);$ok($hint()===null,'negatif keputusan/rentang '.json_encode([$h,$o]));$db->query("UPDATE izin_pengajuan SET status='Dibatalkan' WHERE id=$id");
}
$positive=[];
foreach(['Pengurus','Wali'] as $asal)foreach(['Murobi','Admin Pengganti'] as $cap){
 $id=$create('Disetujui','Disetujui',$cap,$asal);$positive[]=$id;$r=teacher_api_service()->refreshParticipants($m,$guru);
 $s=array_column($r['students'],null,'student_id')[$student];
 $ok((int)$hint()===$id && $s['izin']['sampai']===$date && str_contains($s['izin']['keterangan'],$cap==='Murobi'?'disetujui murobi':'disetujui admin pengganti'),"$asal / $cap, rentang satu hari dan kapasitas tepat");
 $ok($r['participant_changes']['izin_suggested']===1,'penggantian usulan dihitung satu');
}
// Real correction workflow mutates effective decision/status; refresh must follow it.
$version=(int)$one("SELECT version FROM izin_pengajuan WHERE id=$id");
izin_workflow_service()->correctDecision($admin,$id,'Ditolak',$private,'Audit koreksi',$version,'audit-correct-'.$ids['sfx'], []);
$r=teacher_api_service()->refreshParticipants($m,$guru);
$ok((int)$hint()===$positive[count($positive)-2] && $r['participant_changes']['izin_suggested']===1,'koreksi resmi Disetujui → Ditolak memilih izin tumpang tindih sah berikutnya');
$version=(int)$one("SELECT version FROM izin_pengajuan WHERE id=$id");
izin_workflow_service()->correctDecision($admin,$id,'Disetujui',$private,'Audit koreksi balik',$version,'audit-correct-back-'.$ids['sfx'], []);
$r=teacher_api_service()->refreshParticipants($m,$guru);
$ok((int)$hint()===$id,'koreksi resmi Ditolak → Disetujui mengisi usulan');
foreach(['meeting','refreshParticipants'] as $op){try{teacher_api_service()->$op($m,$other);$pass=false;}catch(App\Api\ApiException $e){$pass=$e->status()===403;}$ok($pass,"hak $op guru lain tetap 403");}
$p=['idempotency_key'=>'audit-save-'.$ids['sfx'],'teacher'=>['status'=>'Hadir'],'students'=>[['student_id'=>$student,'status'=>'Hadir','notes'=>''],['student_id'=>$second,'status'=>'Izin','notes'=>'Catatan guru sintetis']]];
$a=teacher_api_service()->saveAttendance($m,$p,$guru);
$b=teacher_api_service()->saveAttendance($m,$p,$guru);
$ok(!$a['replayed'] && $b['replayed'] && $a['data']===$b['data'],'ulang simpan kunci sama mengembalikan respons identik');
$ok((int)$one("SELECT COUNT(*) FROM audit_logs WHERE action='api.attendance.save' AND entity_id=$m")===1,'ulang simpan hanya satu audit/penyimpanan');
$ok($one("SELECT status FROM absensi_santri WHERE pertemuan_id=$m AND santri_id=$student")==='Hadir' && $one("SELECT status FROM absensi_santri WHERE pertemuan_id=$m AND santri_id=$second")==='Izin','klien lama boleh simpan Hadir berizin dan Izin tanpa usulan');
$p['students'][0]['status']='Alpa';try{teacher_api_service()->saveAttendance($m,$p,$guru);$pass=false;}catch(App\Api\ApiException $e){$pass=$e->status()===409;}$ok($pass,'kunci sama isi berbeda tetap ditolak 409');
$p['idempotency_key'].='-new';try{teacher_api_service()->saveAttendance($m,$p,$guru);$pass=false;}catch(App\Api\ApiException $e){$pass=$e->status()===422;}$ok($pass,'koreksi Selesai tanpa alasan tetap 422');
$before=teacher_api_service()->meeting($m,$guru);$db->query("UPDATE izin_pengajuan SET status='Dibatalkan' WHERE id=$id");
try{teacher_api_service()->refreshParticipants($m,$guru);$pass=false;}catch(App\Api\ApiException $e){$pass=$e->status()===409;}$ok($pass && teacher_api_service()->meeting($m,$guru)===$before,'Selesai tetap identik setelah pembatalan dan percobaan perbarui');
$ok(!str_contains(json_encode($before),$private),'payload absensi tidak mengandung penanda privat');
// Restrict audit scan to attendance actions: permit workflow legitimately audits its own reasons.
$leaks=(int)$one("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'api.%' AND entity_type='pertemuan_pengajian' AND entity_id=$m AND CONCAT_WS('|',before_json,after_json) LIKE '%$private%'");
$ok($leaks===0,'audit absensi/buka/perbarui tanpa alasan/catatan/keputusan izin');
// Transaction failure: inject audit rejection, verify whole refresh (including hint) rolls back.
$d2=(new DateTimeImmutable($date))->modify('+7 days')->format('Y-m-d');$m2=schedule_service()->open($sc,$d2,'',$admin);
$i2=$create('Disetujui','Disetujui','Murobi','Pengurus',7);
$db->query("CREATE TRIGGER audit_iop_fail BEFORE INSERT ON audit_logs FOR EACH ROW BEGIN IF NEW.action='api.meeting.refresh_participants' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='audit fixture rejection'; END IF; END");
try{teacher_api_service()->refreshParticipants($m2,$guru);$pass=false;}catch(Throwable $e){$pass=true;}finally{$db->query('DROP TRIGGER audit_iop_fail');}
$ok($pass && $one("SELECT izin_pengajuan_id FROM pertemuan_peserta WHERE pertemuan_id=$m2 AND santri_id=$student")===null,'audit gagal: seluruh penyelarasan usulan rollback');
$r=teacher_api_service()->refreshParticipants($m2,$guru);$ok((int)$one("SELECT izin_pengajuan_id FROM pertemuan_peserta WHERE pertemuan_id=$m2 AND santri_id=$student")===$i2,'sesudah audit pulih perbarui berhasil');
// All 12 month spellings tested independently from helper's own mapping.
foreach(['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'] as $i=>$mon){$dt=sprintf('2027-%02d-05',$i+1);$ok(App\Schedule\IzinPeserta::keterangan(123,'Murobi',$dt)==="Izin No. 123 — disetujui murobi s.d. 5 $mon","IP-3 bulan $mon");}
echo "Hasil: $n lulus, $f gagal\n";exit($f?1:0);
