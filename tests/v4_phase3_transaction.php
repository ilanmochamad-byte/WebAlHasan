<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/bootstrap.php';
use App\V4\{SikapService,VerifikasiService,NilaiService,V4Exception};
$db=app_db();if(PHP_SAPI!=='cli'||app_config('env')!=='testing'||app_config('database.database')!=='webalhasan_v4_fase2_test')exit(77);
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$check=static function($v,$s){echo($v?'[lulus] ':'[gagal] ').$s.PHP_EOL;if(!$v)exit(1);};
$reject=static function(callable $fn,int $status,string $s)use($check){try{$fn();$check(false,$s);}catch(V4Exception $e){$check($e->status===$status,$s.' HTTP '.$e->status);}};
$q=static fn($sql)=>$db->query($sql)->fetch_all(MYSQLI_ASSOC);
$users=[];foreach($q("SELECT id,username FROM users WHERE username LIKE 'v4_%'")as $u)$users[$u['username']]=['id'=>(int)$u['id']];
$ids=json_decode(file_get_contents('/private/tmp/v4-fase3-ids.json'),true);$plan=$ids['rencana'];$sid=$ids['santri'];$mid=$ids['mapel'];
$sk=new SikapService($db,audit_logger());$ns=new NilaiService($db,new App\Auth\Capabilities($db),audit_logger());$vs=new VerifikasiService($db,audit_logger());
$beforeS=$sk->read($users['v4_murobi'],$plan,$sid,'murobi');$beforeN=$ns->read($users['v4_guru'],$mid,$sid);$beforeM=$vs->read($users['v4_pendidikan'],$plan,$sid);
$audits=(int)$q('SELECT COUNT(*) n FROM audit_logs')[0]['n'];
// Gagal hanya pada audit workflow, SETELAH nilai/sikap/riwayat dan audit pertama sudah ditulis.
$db->query("CREATE TRIGGER v4_fase3_late_audit_fail BEFORE INSERT ON audit_logs FOR EACH ROW BEGIN IF NEW.action LIKE 'v4.bahan.%' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic late audit failure'; END IF; END");
try{
 $input=['rencana_id'=>$plan,'santri_id'=>$sid,'action'=>'koreksi','alasan'=>'Uji kegagalan audit tahap akhir']+$beforeS['sikap'];$input['catatan']='Harus digulung tahap akhir';$reject(fn()=>$sk->save($users['v4_murobi'],$input,'murobi'),503,'Audit workflow gagal setelah audit sikap berhasil');
 $input=['rencana_mapel_id'=>$mid,'santri_id'=>$sid,'action'=>'koreksi','alasan'=>'Uji kegagalan audit tahap akhir','komponen'=>$beforeN['nilai']];$input['komponen'][0]['catatan']='Harus digulung tahap akhir';$reject(fn()=>$ns->save($users['v4_guru'],$input),503,'Audit workflow gagal setelah audit komponen nilai berhasil');
 $m=array_values(array_filter($beforeM['matriks'][0]['bahan'],fn($m)=>$m['jenis']==='sikap'))[0];
 $reject(fn()=>$vs->act($users['v4_pendidikan'],['rencana_id'=>$plan,'santri_id'=>$sid,'jenis'=>'sikap','mapel_key'=>0,'action'=>'verifikasi']+$m['pemeriksaan']),503,'Audit verifikasi gagal menggulung status dan riwayat pemeriksaan');
}finally{$db->query('DROP TRIGGER v4_fase3_late_audit_fail');}
$check($beforeS===$sk->read($users['v4_murobi'],$plan,$sid,'murobi'),'Sikap dan dua jenis riwayat tetap utuh setelah rollback akhir');
$check($beforeN===$ns->read($users['v4_guru'],$mid,$sid),'Nilai, atribusi, status dan dua jenis riwayat tetap utuh');
$check($beforeM===$vs->read($users['v4_pendidikan'],$plan,$sid)&&(int)$q('SELECT COUNT(*) n FROM audit_logs')[0]['n']===$audits,'Matriks dan audit pertama ikut digulung; tidak ada audit sukses palsu');
$placements=$q("SELECT id,id_kelas FROM plotting_kelas WHERE id_santri=$sid AND id_tahun=4 AND status='Aktif'");
try{
 $db->query("UPDATE plotting_kelas SET id_kelas=3 WHERE id_santri=$sid AND id_tahun=4 AND status='Aktif'");
 $matrix=$vs->read($users['v4_pendidikan'],$plan,$sid);$check(str_contains(implode(' ',$matrix['matriks'][0]['kekurangan']),'Kelas semester ambigu'),'Perpindahan kelas dengan nilai asal menjadi penghalang finalisasi');
 $reject(fn()=>$vs->act($users['v4_pendidikan'],['rencana_id'=>$plan,'santri_id'=>$sid,'action'=>'periksa_finalisasi','matriks_hash'=>$matrix['matriks_hash']]),422,'Gate menolak kelas semester ambigu');
 $check($beforeN['nilai']===$ns->read($users['v4_guru'],$mid,$sid)['nilai'],'Perpindahan kelas tidak mengubah identitas/kelas/nilai pengisi historis');
}finally{foreach($placements as $p)$db->query('UPDATE plotting_kelas SET id_kelas='.(int)$p['id_kelas'].' WHERE id='.(int)$p['id']);}
