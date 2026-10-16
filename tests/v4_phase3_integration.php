<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/bootstrap.php';
use App\V4\{SikapService,VerifikasiService,NilaiService,BahanRepository,V4Exception};
use App\Auth\Capabilities;
$db=app_db();if(PHP_SAPI!=='cli'||app_config('env')!=='testing'||app_config('database.database')!=='webalhasan_v4_fase2_test')exit(77);
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$check=static function(bool $v,string $s){echo ($v?'[lulus] ':'[gagal] ').$s.PHP_EOL;if(!$v)exit(1);};
$reject=static function(callable $fn,int $status,string $s)use($check){try{$fn();$check(false,$s);}catch(V4Exception $e){$check($e->status===$status,$s.' HTTP '.$e->status);}};
$q=static fn($sql)=>$db->query($sql)->fetch_all(MYSQLI_ASSOC);
$users=[];foreach($q("SELECT id,username FROM users WHERE username LIKE 'v4_%'")as $u)$users[$u['username']]=['id'=>(int)$u['id']];
$pb=$users['v4_pembimbing'];$mu=$users['v4_murobi'];$pe=$users['v4_pendidikan'];$gu=$users['v4_guru'];
$ids=json_decode(file_get_contents('/private/tmp/v4-fase3-ids.json'),true);$plan=$ids['rencana'];$sid=$ids['santri'];
$sk=new SikapService($db,audit_logger());$vs=new VerifikasiService($db,audit_logger());$ns=new NilaiService($db,new Capabilities($db),audit_logger());
$in=['rencana_id'=>$plan,'santri_id'=>$sid,'version'=>0,'adab'=>'Baik','kedisiplinan'=>'Sangat Baik','tanggung_jawab'=>'Cukup','catatan'=>'Catatan pembimbing <script>window.v4xss=1</script>'];
$r=$sk->save($pb,$in);$check($r['sikap']['adab']==='Baik'&&$r['sikap']['kedisiplinan']==='Sangat Baik'&&$r['sikap']['tanggung_jawab']==='Cukup'&&$r['sikap']['catatan']===$in['catatan'],'K1: tiga predikat dan catatan tersimpan');
$reject(fn()=>$sk->save($pb,$in),409,'Retry draf tidak membuat versi ganda');
foreach(['pembimbing_lain'=>'pembimbing','murobi_lain'=>'murobi'] as $name=>$mode){$u=$users['v4_'.$name];$reject(fn()=>$sk->read($u,$plan,$sid,$mode),403,'Baca petugas di luar cakupan');$reject(fn()=>$sk->save($u,$in,$mode),403,'Mutasi petugas di luar cakupan');}
foreach(['v4_guru','v4_wali','v4_pengurus'] as $name){$reject(fn()=>$sk->read($users[$name],$plan,$sid),403,'Baca sikap tanpa tugas');$reject(fn()=>$vs->read($users[$name],$plan,$sid),403,'Matriks tanpa Pendidikan');}
$in['version']=(int)$r['sikap']['version'];$in['action']='serahkan';$r=$sk->save($pb,$in);$check($r['sikap']['status']==='Dikirim','Pembimbing menyerahkan sikap');
$known=['rencana_id'=>$plan,'santri_id'=>$sid,'version'=>(int)$r['sikap']['version'],'action'=>'diketahui'];$r=$sk->save($mu,$known,'murobi');$check((int)$r['sikap']['diketahui_murobi_user_id']===$mu['id'],'K2: murobi sah menandai diketahui');
$known['version']=(int)$r['sikap']['version'];$reject(fn()=>$sk->save($mu,$known,'murobi'),409,'Diketahui tepat sekali walau versi terbaru');
$cor=array_replace($in,['action'=>'koreksi','version'=>(int)$r['sikap']['version'],'adab'=>'Sangat Baik']);$reject(fn()=>$sk->save($mu,$cor,'murobi'),422,'Koreksi murobi tanpa alasan ditolak');$cor['alasan']='Peninjauan perilaku';$r=$sk->save($mu,$cor,'murobi');
$check(count($r['riwayat'])===4&&json_decode($r['riwayat'][3]['snapshot_json'],true)['adab']==='Baik','K2: koreksi versi baru mempertahankan masukan pembimbing');
$check((int)$r['sikap']['pembimbing_user_id']===$pb['id']&&$r['riwayat'][0]['kapasitas']==='murobi','Identitas pembimbing historis tidak diganti murobi');
$matrix=static fn()=>$vs->read($pe,$plan,$sid)['matriks'][0];
$material=static function($kind,$key=0)use($matrix){foreach($matrix()['bahan']as $m)if($m['jenis']===$kind&&$m['mapel_key']===$key)return $m;throw new Exception('Missing');};
$act=static function($kind,$key,$action,$reason='')use($vs,$pe,$plan,$sid,$material){$m=$material($kind,$key);return $vs->act($pe,['rencana_id'=>$plan,'santri_id'=>$sid,'jenis'=>$kind,'mapel_key'=>$key,'action'=>$action,'alasan'=>$reason]+$m['pemeriksaan']);};
$final=static fn()=>$vs->act($pe,['rencana_id'=>$plan,'santri_id'=>$sid,'action'=>'periksa_finalisasi','matriks_hash'=>BahanRepository::fingerprint($matrix())]);
$act('sikap',0,'verifikasi');$check($material('sikap')['pemeriksaan']['status']==='Terverifikasi','Pendidikan memverifikasi sikap');
// Tidak ada murobi: persetujuan historis tidak otomatis berlaku.
$aid=(int)$q("SELECT a.id FROM murobi_assignments a JOIN users u ON u.guru_id=a.guru_id WHERE u.username='v4_murobi'")[0]['id'];
try{$db->query("UPDATE murobi_assignments SET is_active=0 WHERE id=$aid");$reject(fn()=>$sk->read($mu,$plan,$sid,'murobi'),403,'Murobi dicabut pada instance layanan sama');$check(!$material('sikap')['lengkap']&&str_contains($material('sikap')['penghalang'],'Tidak ada murobi'),'Penghalang tanpa murobi aktif; tanpa persetujuan otomatis');$reject($final,422,'Finalisasi tanpa murobi aktif ditolak');}finally{$db->query("UPDATE murobi_assignments SET is_active=1 WHERE id=$aid");}
// Pertemuan Selesai tanpa absensi; snapshot tetap menjadi sumber meskipun status plotting berubah.
$j=(int)$q('SELECT id FROM jadwal_ngaji WHERE id_tahun=4 AND id_kelas=2 ORDER BY id LIMIT 1')[0]['id'];$meetings=[];
foreach([null,'Hadir','Terlambat','Izin','Sakit','Alpa'] as $i=>$status){$date='2026-09-'.(10+$i);$db->query("INSERT INTO pertemuan_pengajian (jadwal_id,tanggal_pertemuan,status,created_by) VALUES ($j,'$date','Selesai',1)");$meeting=$db->insert_id;$meetings[]=$meeting;$db->query("INSERT INTO pertemuan_peserta (pertemuan_id,santri_id,nis_snapshot,nama_santri_snapshot,kelas_id_snapshot,tahun_ajaran_id_snapshot) VALUES ($meeting,$sid,'UJI','Santri Sintetis',2,4)");if($status)$db->query("INSERT INTO absensi_santri (pertemuan_id,santri_id,status,dicatat_pada,dicatat_oleh) VALUES ($meeting,$sid,'$status',NOW(),1)");
 if($i===0){$a=$material('kehadiran')['kehadiran'];$check($a['jumlah_pertemuan']===1&&$a['belum_dicatat']===1&&$a['hitungan']['Alpa']===0,'K3: satu pertemuan tanpa absensi = satu Belum dicatat dan nol Alpa');$reject($final,422,'K3: satu pertemuan tanpa absensi menolak finalisasi');}
}
$att=$material('kehadiran')['kehadiran'];$check($att['belum_dicatat']===1&&$att['hitungan']['Alpa']===1&&$att['jumlah_pertemuan']===6,'K3: tanpa absensi Belum dicatat tepat satu, tidak menambah Alpa');$check(array_values($att['hitungan'])===[1,1,1,1,1],'K4: kelima status masing-masing satu tanpa duplikasi');$reject($final,422,'K3: Belum dicatat menghalangi finalisasi');$reject(fn()=>$act('kehadiran',0,'serahkan'),422,'Kehadiran tidak lengkap tidak dapat diserahkan');
// Sumber berbeda semester/Draf/Dibuka tidak dihitung.
foreach(['Draf','Dibuka']as $i=>$status){$date='2026-09-'.(20+$i);$db->query("INSERT INTO pertemuan_pengajian (jadwal_id,tanggal_pertemuan,status,created_by) VALUES ($j,'$date','$status',1)");$m=$db->insert_id;$db->query("INSERT INTO pertemuan_peserta (pertemuan_id,santri_id,nis_snapshot,nama_santri_snapshot,kelas_id_snapshot,tahun_ajaran_id_snapshot) VALUES ($m,$sid,'UJI','Santri Sintetis',2,4)");}

$check($material('kehadiran')['kehadiran']['jumlah_pertemuan']===6,'Draf dan Dibuka tidak masuk rekap');
$db->query('INSERT INTO absensi_santri (pertemuan_id,santri_id,status,dicatat_pada,dicatat_oleh) VALUES ('.$meetings[0].",$sid,'Hadir',NOW(),1)");$act('kehadiran',0,'serahkan');$act('kehadiran',0,'verifikasi');
// Lengkapi dua mapel dan verifikasi tanpa menyentuh atribusi komponen.
foreach([$ids['mapel'],$ids['other']]as $mid){$r=$ns->read($pe,$mid,$sid,true);$items=$r['nilai'];$kinds=array_column($items,'komponen');foreach(['Harian','Tengah','Akhir']as $k)if(!in_array($k,$kinds,true))$items[]=['komponen'=>$k,'nomor_harian'=>$k==='Harian'?1:0,'nilai'=>80,'version'=>0,'catatan'=>''];
 if(count(array_filter($items,fn($r)=>($r['status']??'')!=='Dikirim')))$ns->save($pe,['rencana_mapel_id'=>$mid,'santri_id'=>$sid,'komponen'=>$items,'alasan'=>'Guru berhalangan','action'=>'serahkan'],true);
 $before=$ns->read($pe,$mid,$sid,true);$act('nilai',$mid,'verifikasi');$after=$ns->read($pe,$mid,$sid,true);$check($before['nilai']===$after['nilai']&&$before['riwayat']===$after['riwayat'],'Verifikasi tidak mengubah komponen/atribusi/riwayat guru');
}
$check($matrix()['siap_finalisasi'],'Semua bahan lengkap terverifikasi');$check($final()['rapor_diterbitkan']===false,'Gate sah hanya memeriksa, tanpa penerbitan');
$mid=$ids['mapel'];$old=$ns->read($gu,$mid,$sid);$input=['rencana_mapel_id'=>$mid,'santri_id'=>$sid,'komponen'=>$old['nilai'],'action'=>'koreksi','alasan'=>'Koreksi pascaverifikasi'];$input['komponen'][0]['nilai']='81';$r=$ns->save($gu,$input);$check($material('nilai',$mid)['pemeriksaan']['status']==='Draf','K5: koreksi nilai membatalkan verifikasi mapel');$reject($final,422,'K5: finalisasi sebelum verifikasi ulang nilai ditolak');
$input['komponen']=$r['nilai'];$input['action']='serahkan';$ns->save($gu,$input);$act('nilai',$mid,'verifikasi');
$r=$sk->read($pb,$plan,$sid);$input=array_replace($r['sikap'],['rencana_id'=>$plan,'santri_id'=>$sid,'action'=>'koreksi','alasan'=>'Koreksi sikap pascaverifikasi','catatan'=>'Catatan baru']);$r=$sk->save($pb,$input);
$check($material('sikap')['pemeriksaan']['status']==='Draf'&&!$material('sikap')['diketahui_sah'],'K5: koreksi pembimbing membatalkan verifikasi dan pengetahuan murobi');$reject($final,422,'K5: finalisasi sebelum verifikasi ulang sikap ditolak');
$input['version']=(int)$r['sikap']['version'];$input['action']='serahkan';$r=$sk->save($pb,$input);$sk->save($mu,['rencana_id'=>$plan,'santri_id'=>$sid,'action'=>'diketahui','version'=>(int)$r['sikap']['version']],'murobi');
$reject(fn()=>$act('sikap',0,'kembalikan'),422,'Pengembalian membutuhkan alasan');$act('sikap',0,'kembalikan','Catatan perlu diperiksa');$check($material('sikap')['pemeriksaan']['status']==='Dikembalikan','Perlu Perbaikan tercatat');$reject(fn()=>$act('sikap',0,'verifikasi'),409,'Bahan dikembalikan tidak bisa langsung diverifikasi');
$r=$sk->read($pb,$plan,$sid);$input['action']='koreksi';$input['version']=(int)$r['sikap']['version'];$input['catatan']='Diperbaiki';$r=$sk->save($pb,$input);$input['version']=(int)$r['sikap']['version'];$input['action']='serahkan';$r=$sk->save($pb,$input);$sk->save($mu,['rencana_id'=>$plan,'santri_id'=>$sid,'action'=>'diketahui','version'=>(int)$r['sikap']['version']],'murobi');
$m=$material('sikap');$payload=['rencana_id'=>$plan,'santri_id'=>$sid,'jenis'=>'sikap','mapel_key'=>0,'action'=>'verifikasi']+$m['pemeriksaan'];$vs->act($pe,$payload);$reject(fn()=>$vs->act($pe,$payload),409,'Retry verifikasi tidak membuat riwayat ganda');
// Perubahan sumber absensi otomatis menonaktifkan verifikasi efektif.
$oldMatrix=$matrix();$db->query("UPDATE absensi_santri SET status='Sakit' WHERE pertemuan_id=".$meetings[0]." AND santri_id=$sid");$check($material('kehadiran')['pemeriksaan']['perlu_verifikasi_ulang']&&$material('kehadiran')['pemeriksaan']['status']==='Draf','Perubahan sumber absensi membatalkan verifikasi efektif');$reject(fn()=>$vs->act($pe,['rencana_id'=>$plan,'santri_id'=>$sid,'action'=>'periksa_finalisasi','matriks_hash'=>BahanRepository::fingerprint($oldMatrix)]),409,'Finalisasi dengan sumber lama ditolak 409');$reject($final,422,'Finalisasi absensi berubah memerlukan pemeriksaan ulang');
$reject(fn()=>$act('kehadiran',0,'serahkan'),422,'Koreksi rekap kehadiran membutuhkan alasan');$act('kehadiran',0,'serahkan','Koreksi sumber absensi');$act('kehadiran',0,'verifikasi');
// Rollback audit pada sikap dan pemeriksaan: seluruh jejak bisnis harus utuh.
$before=$sk->read($mu,$plan,$sid,'murobi');$beforeMatrix=$matrix();
$db->query("CREATE TRIGGER v4_fase3_audit_fail BEFORE INSERT ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic audit failure'");
try{
 $inp=array_replace($before['sikap'],['rencana_id'=>$plan,'santri_id'=>$sid,'action'=>'koreksi','alasan'=>'Uji rollback','catatan'=>'Harus digulung']);$reject(fn()=>$sk->save($mu,$inp,'murobi'),503,'Kegagalan audit menggulung sikap dan riwayat');
 // Buat payload penyerahan di DB lain tidak perlu: nilai mapel sudah terverifikasi, kembalikan hanya dikirim. Ubah sumber lalu serahkan untuk memicu kegagalan.
 $db->query("UPDATE absensi_santri SET status='Izin' WHERE pertemuan_id=".$meetings[0]." AND santri_id=$sid");$reject(fn()=>$act('kehadiran',0,'serahkan','Uji rollback'),503,'Kegagalan audit menggulung pemeriksaan dan riwayat');$db->query("UPDATE absensi_santri SET status='Sakit' WHERE pertemuan_id=".$meetings[0]." AND santri_id=$sid");
}finally{$db->query('DROP TRIGGER v4_fase3_audit_fail');}
$check($before===$sk->read($mu,$plan,$sid,'murobi')&&$beforeMatrix===$matrix(),'Rollback mempertahankan nilai sikap, status dan seluruh riwayat');
// Cakupan kamar menggunakan master lama yang hanya mempunyai id/nama/kapasitas.
$db->query("INSERT INTO kamar (nama_kamar,kapasitas) VALUES ('Kamar Sintetis Fase 3',20)");$room=$db->insert_id;
$db->query("INSERT INTO plotting_kamar (id_santri,id_kamar,id_tahun) VALUES ($sid,$room,4)");$roomPlacement=$db->insert_id;
foreach(['pembimbing'=>['pembimbing_assignments','pengurus_id',$pb],'murobi'=>['murobi_assignments','guru_id',$mu]]as $mode=>[$table,$column,$u]){
 $aid=(int)$q("SELECT a.id FROM $table a JOIN users u ON u.$column=a.$column WHERE u.id=".$u['id'])[0]['id'];
 try{$db->query("UPDATE $table SET target_type='Kamar',kelas_id=NULL,kamar_id=$room WHERE id=$aid");$check($sk->read($u,$plan,$sid,$mode)['sikap']!==null,'Cakupan kamar membaca sikap '.$mode);$db->query("DELETE FROM plotting_kamar WHERE id=$roomPlacement");$reject(fn()=>$sk->read($u,$plan,$sid,$mode),403,'Relasi kamar dicabut '.$mode);$db->query("INSERT INTO plotting_kamar (id,id_santri,id_kamar,id_tahun) VALUES ($roomPlacement,$sid,$room,4)");}finally{$db->query("UPDATE $table SET target_type='Kelas',kelas_id=2,kamar_id=NULL WHERE id=$aid");}
}
$db->query("DELETE FROM plotting_kamar WHERE id=$roomPlacement");$db->query("DELETE FROM kamar WHERE id=$room");
// Pencabutan pada instance yang sama harus langsung menolak (tidak mengandalkan cache).
foreach(['pembimbing'=>['pembimbing_assignments','pengurus_id',$pb],'murobi'=>['murobi_assignments','guru_id',$mu]]as $mode=>[$table,$column,$u]){
 $aid=(int)$q("SELECT a.id FROM $table a JOIN users u ON u.$column=a.$column WHERE u.id=".$u['id'])[0]['id'];
 try{$db->query("UPDATE $table SET is_active=0 WHERE id=$aid");$reject(fn()=>$sk->read($u,$plan,$sid,$mode),403,'Penugasan dicabut baca '.$mode);$reject(fn()=>$sk->save($u,$in,$mode),403,'Penugasan dicabut mutasi '.$mode);}finally{$db->query("UPDATE $table SET is_active=1 WHERE id=$aid");}
}
$aid=(int)$q("SELECT a.id FROM pendidikan_assignments a JOIN users u ON u.pengurus_id=a.pengurus_id WHERE u.id=".$pe['id'])[0]['id'];
try{$db->query("UPDATE pendidikan_assignments SET is_active=0 WHERE id=$aid");$reject(fn()=>$vs->read($pe,$plan,$sid),403,'Pendidikan dicabut baca');$reject(fn()=>$vs->act($pe,$payload),403,'Pendidikan dicabut mutasi');}finally{$db->query("UPDATE pendidikan_assignments SET is_active=1 WHERE id=$aid");}
// Data semester berbeda tidak masuk hitungan, termasuk snapshot semester yang tidak cocok jadwal.
$db->query("INSERT INTO pertemuan_pengajian (jadwal_id,tanggal_pertemuan,status,created_by) VALUES ($j,'2026-09-25','Selesai',1)");$outside=$db->insert_id;$db->query("INSERT INTO pertemuan_peserta (pertemuan_id,santri_id,nis_snapshot,nama_santri_snapshot,kelas_id_snapshot,tahun_ajaran_id_snapshot) VALUES ($outside,$sid,'UJI','Santri Sintetis',2,5)");$check($material('kehadiran')['kehadiran']['jumlah_pertemuan']===6,'Snapshot semester lain tidak masuk rekap');
try{$db->query("UPDATE jadwal_ngaji SET id_tahun=5 WHERE id=$j");$check($material('kehadiran')['kehadiran']['jumlah_pertemuan']===0,'Snapshot tidak cocok semester jadwal dikecualikan');}finally{$db->query("UPDATE jadwal_ngaji SET id_tahun=4 WHERE id=$j");}
$reject(fn()=>$sk->read($pb,$plan,99999999),403,'Santri di luar cakupan');$reject(fn()=>$vs->read($pe,99999999,$sid),403,'Rencana di luar cakupan');
foreach($ids['rencana_luar'] as $outsidePlan){$reject(fn()=>$vs->read($pe,$outsidePlan),403,'Matriks Pendidikan kelas/semester di luar cakupan');$reject(fn()=>$vs->act($pe,array_replace($payload,['rencana_id'=>$outsidePlan])),403,'Mutasi Pendidikan kelas/semester di luar cakupan');}
$check((int)$q('SELECT COUNT(*) n FROM v4_rapor')[0]['n']===0&&(int)$q('SELECT COUNT(*) n FROM v4_rapor_versi')[0]['n']===0&&(int)$q('SELECT COUNT(*) n FROM v4_verifikasi')[0]['n']===0,'K5: nol rapor terbit, versi, kode publik');
$check((int)$q("SELECT COUNT(*) n FROM audit_logs WHERE action LIKE 'v4.%' AND (after_json LIKE '%Catatan baru%' OR after_json LIKE '%Harus digulung%')")[0]['n']===0,'Audit umum tidak memuat catatan sikap');

// Pengetahuan tanpa koreksi tidak boleh melewati bahan yang dikembalikan Pendidikan.
$sid2=$ids['santri2'];$returned=$sk->save($pb,array_replace($in,['santri_id'=>$sid2,'version'=>0,'action'=>'serahkan']));
$mat2=$vs->read($pe,$plan,$sid2)['matriks'][0];$s2=array_values(array_filter($mat2['bahan'],fn($m)=>$m['jenis']==='sikap'))[0];
$vs->act($pe,['rencana_id'=>$plan,'santri_id'=>$sid2,'jenis'=>'sikap','mapel_key'=>0,'action'=>'kembalikan','alasan'=>'Perbaiki sikap']+$s2['pemeriksaan']);
$reject(fn()=>$sk->save($mu,['rencana_id'=>$plan,'santri_id'=>$sid2,'version'=>(int)$returned['sikap']['version'],'action'=>'diketahui'],'murobi'),409,'Diketahui tidak melewati pengembalian beralasan');
$check((int)$q('SELECT COUNT(*) n FROM notifikasi_outbox')[0]['n']===0,'Nol outbox notifikasi Fase 3');
echo "Selesai Fase 3 pada MariaDB uji.\n";
