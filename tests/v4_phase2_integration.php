<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/bootstrap.php';
use App\V4\NilaiService;
use App\V4\RencanaService;
use App\V4\V4Exception;
use App\Auth\Capabilities;
$db=app_db();if(PHP_SAPI!=='cli'||app_config('env')!=='testing'||app_config('database.database')!=='webalhasan_v4_fase2_test')exit(77);
$check=static function(bool $v,string $s){echo ($v?'[lulus] ':'[gagal] ').$s.PHP_EOL;if(!$v)exit(1);};
$reject=static function(callable $fn,int $status,string $s)use($check){try{$fn();$check(false,$s);}catch(V4Exception $e){$check($e->status===$status,$s.' HTTP '.$e->status);}};
$rows=static fn($sql)=>$db->query($sql)->fetch_all(MYSQLI_ASSOC);
$users=[];foreach($rows("SELECT id,username FROM users WHERE username LIKE 'v4_%'")as $u)$users[$u['username']]=['id'=>(int)$u['id'],'roles'=>[]];
$guru=$users['v4_guru'];$pend=$users['v4_pendidikan'];$admin=['id'=>1,'roles'=>['admin']];
$service=new NilaiService($db,new Capabilities($db),audit_logger());$plan=$service->options($guru)[0];$mid=(int)$plan['id'];
$students=$service->read($guru,$mid)['santri'];$sid=(int)$students[0]['id'];$sid2=(int)$students[1]['id'];
$component=static fn($kind,$num,$value,$version=0)=>['komponen'=>$kind,'nomor_harian'=>$num,'nilai'=>$value,'version'=>$version,'catatan'=>'Capaian <script>window.v4xss=1</script>'];
$input=['rencana_mapel_id'=>$mid,'santri_id'=>$sid,'action'=>'draf','komponen'=>[$component('Harian',1,80),$component('Harian',2,90),$component('Tengah',0,78),$component('Akhir',0,92)]];
$result=$service->save($guru,$input);$check($result['hasil']['nilai_tampil']===85&&$result['hasil']['predikat']==='B'&&$result['hasil']['nilai_internal']===85.0,'80/90 + 78 + 92 = 85 B');
$check(count($result['riwayat'])===4,'Versi pertama keempat komponen tersimpan');
$reject(fn()=>$service->save($guru,$input),409,'Retry identik ditolak tanpa duplikasi');
$check(count($service->read($guru,$mid,$sid)['nilai'])===4,'Tetap empat komponen berlaku');
$input['komponen']=$result['nilai'];$input['action']='serahkan';$result=$service->save($guru,$input);$check(count(array_filter($result['nilai'],fn($r)=>$r['status']==='Dikirim'))===4,'Penyerahan lengkap');
$reject(fn()=>$service->save($guru,$input),409,'Versi usang tidak menimpa');
$input['komponen']=$result['nilai'];$input['action']='koreksi';$reject(fn()=>$service->save($guru,$input),422,'Koreksi tanpa alasan');
$input['alasan']='Pemeriksaan capaian';$input['komponen'][0]['catatan']='Dikoreksi';$result=$service->save($guru,$input);
$check(count($result['riwayat'])===12&&$result['nilai'][0]['status']==='Draf','Koreksi menyimpan versi 1–3, kembali draf');
$zero=['rencana_mapel_id'=>$mid,'santri_id'=>$sid2,'komponen'=>[$component('Harian',1,0)]];$zr=$service->save($guru,$zero);
$check((float)$zr['nilai'][0]['nilai']===0.0&&$zr['hasil']['kelengkapan']==='Belum lengkap','Nol tersimpan; komponen hilang Belum lengkap');
$zero['komponen']=$zr['nilai'];$zero['action']='serahkan';$reject(fn()=>$service->save($guru,$zero),422,'Penyerahan belum lengkap ditolak');
foreach([-1,101,'1e2','NaN',[],null,'',true,'99.999',"0 OR 1=1"]as $bad){$zero['komponen'][0]['nilai']=$bad;$zero['action']='koreksi';$zero['alasan']='Uji validasi';$reject(fn()=>$service->save($guru,$zero),422,'Skala/tipe tidak sah ditolak');}
$zero['komponen']=$zr['nilai'];$zero['komponen'][]=$zr['nilai'][0];$reject(fn()=>$service->save($guru,$zero),409,'Komponen ganda dalam payload ditolak');
$other=(int)$rows("SELECT rm.id FROM v4_rencana_mapel rm JOIN mata_pelajaran mp ON mp.id=rm.mata_pelajaran_id WHERE mp.kode='V4UJI02'")[0]['id'];
$reject(fn()=>$service->read($guru,$other),403,'Guru mapel lain baca');
$reject(fn()=>$service->save($guru,array_replace($input,['rencana_mapel_id'=>$other])),403,'Guru mapel lain tulis');
$rp=new RencanaService($db,new Capabilities($db),audit_logger());$mp=(int)$plan['mata_pelajaran_id'];
foreach([[4,3],[5,2]]as [$year,$class]){
 $p=$rp->save($admin,['tahun_ajaran_id'=>$year,'kelas_id'=>$class,'mapel'=>[['mata_pelajaran_id'=>$mp]]]);$outside=(int)$p['mapel'][0]['id'];
 $reject(fn()=>$service->read($guru,$outside),403,'Guru kelas/semester lain baca');
 $reject(fn()=>$service->save($guru,array_replace($input,['rencana_mapel_id'=>$outside])),403,'Guru kelas/semester lain tulis');
 $reject(fn()=>$service->read($pend,$outside,0,true),403,'Pendidikan jenjang/semester lain baca');
 $reject(fn()=>$service->save($pend,array_replace($input,['rencana_mapel_id'=>$outside,'alasan'=>'Guru berhalangan']),true),403,'Pendidikan jenjang/semester lain tulis');
}
$sub=['rencana_mapel_id'=>$other,'santri_id'=>$sid,'komponen'=>[$component('Harian',1,90)]];
$reject(fn()=>$service->save($pend,$sub,true),422,'Pengganti tanpa alasan');
$assign=$rows('SELECT * FROM guru_mapel_assignments');$sub['alasan']='Guru berhalangan';$res=$service->save($pend,$sub,true);
$check($res['riwayat'][0]['kapasitas']==='pendidikan_pengganti'&&(int)$res['riwayat'][0]['pelaku_user_id']===$pend['id'],'Pengganti menyimpan pelaku dan kapasitas');
$check($assign===$rows('SELECT * FROM guru_mapel_assignments'),'Pengganti tidak mengubah penugasan');
$a=$rows("SELECT after_json FROM audit_logs WHERE action='v4.nilai.draf' ORDER BY id DESC LIMIT 1")[0];$check(str_contains($a['after_json'],'pendidikan_pengganti'),'Audit kapasitas pengganti');
foreach(['v4_pengurus','v4_wali']as $key){$reject(fn()=>$service->options($users[$key]),403,'Peran tanpa hak ditolak');$reject(fn()=>$service->read($users[$key],$mid,$sid),403,'Detail peran tanpa hak ditolak');}
$reject(fn()=>$service->read($guru,$mid,99999999),403,'ID santri di luar cakupan');
// Pindahkan penempatan pada DB uji, baca nilai asal, lalu pulihkan di finally.
$placements=$rows("SELECT id,id_kelas FROM plotting_kelas WHERE id_santri=$sid AND id_tahun=4 AND id_kelas=2");
try{$db->query("UPDATE plotting_kelas SET id_kelas=3 WHERE id_santri=$sid AND id_tahun=4 AND id_kelas=2");$historical=$service->read($guru,$mid,$sid);$check((int)$historical['rencana_mapel']['kelas_id']===2&&$historical['hasil']['nilai_tampil']===85,'Nilai historis tidak mengikuti perpindahan kelas');}finally{foreach($placements as $r)$db->query('UPDATE plotting_kelas SET id_kelas=2 WHERE id='.(int)$r['id']);}
// Kegagalan audit harus menggulung nilai dan riwayat.
$before=$service->read($guru,$mid,$sid);$input['komponen']=$before['nilai'];
$db->query("CREATE TRIGGER v4_test_audit_fail BEFORE INSERT ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic failure'");
try{$reject(fn()=>$service->save($guru,$input),503,'Kegagalan audit menggulung transaksi');}finally{$db->query('DROP TRIGGER v4_test_audit_fail');}
$check($before===$service->read($guru,$mid,$sid),'Nilai dan riwayat tetap setelah rollback');
$check(NilaiService::calculate([$component('Harian',1,89.5),$component('Tengah',0,89.5),$component('Akhir',0,89.5)])['predikat']==='A','Half-up 89.5 menjadi 90 A');
$check(NilaiService::calculate([$component('Harian',1,0),$component('Tengah',0,0),$component('Akhir',0,0)])['nilai_tampil']===0,'Seluruh komponen nol lengkap');
// Penugasan dicabut harus ditolak pada request baru, lalu fixture dipulihkan.
$assignment=(int)$rows("SELECT a.id FROM guru_mapel_assignments a JOIN users u ON u.guru_id=a.guru_id WHERE u.username='v4_guru'")[0]['id'];
try{$db->query("UPDATE guru_mapel_assignments SET is_active=0 WHERE id=$assignment");$revoked=new NilaiService($db,new Capabilities($db),audit_logger());$reject(fn()=>$revoked->read($guru,$mid,$sid),403,'Penugasan guru dicabut ditolak pada baca');$reject(fn()=>$revoked->save($guru,$input),403,'Penugasan guru dicabut ditolak pada tulis');}finally{$db->query("UPDATE guru_mapel_assignments SET is_active=1 WHERE id=$assignment");}
$check(abs(NilaiService::calculate([$component('Harian',1,80),$component('Harian',2,80),$component('Harian',3,81),$component('Tengah',0,80),$component('Akhir',0,80)])['nilai_internal']-80.13)<0.00001,'Rata-rata pecahan mempertahankan presisi hingga hasil internal dua desimal');
// ID fixture nonrahasia untuk HTTP/browser dan worker konkurensi.
file_put_contents('/private/tmp/v4-fase2-ids.json',json_encode(['mapel'=>$mid,'other'=>$other,'santri'=>$sid,'santri2'=>$sid2,'concurrent'=>(int)$students[2]['id'],'web'=>(int)$students[3]['id'],'guru'=>$guru['id']]));
echo "Selesai pada MariaDB uji, bukan produksi.\n";
