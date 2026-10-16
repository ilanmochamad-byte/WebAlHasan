<?php
require __DIR__.'/v4_phase4_support.php';
$files=static fn()=>count(glob(APP_ROOT.'/storage/private/v4/*.pdf')?:[]);
$counts=static fn()=>[$q('SELECT COUNT(*) n FROM v4_rapor')[0]['n'],$q('SELECT COUNT(*) n FROM v4_rapor_versi')[0]['n'],$q('SELECT COUNT(*) n FROM v4_verifikasi')[0]['n'],$q('SELECT COUNT(*) n FROM notifikasi_outbox')[0]['n'],$q('SELECT COUNT(*) n FROM v4_rapor_operasi')[0]['n'],$q('SELECT COUNT(*) n FROM audit_logs')[0]['n'],$files()];
$before=$counts();
// Incomplete cases each reread fresh preview, never stale-hash-only rejections.
foreach(['nilai','sikap','kehadiran'] as $kind){
 if($kind==='nilai'){$id=$q("SELECT id FROM v4_nilai WHERE santri_id=$sid LIMIT 1")[0]['id'];$time=$q("SELECT updated_at FROM v4_nilai WHERE id=$id")[0]['updated_at'];$db->query("UPDATE v4_nilai SET status='Draf' WHERE id=$id");}
 elseif($kind==='sikap'){$old=$q("SELECT adab FROM v4_sikap WHERE santri_id=$sid AND tahun_ajaran_id=4")[0]['adab'];$time=$q("SELECT updated_at FROM v4_sikap WHERE santri_id=$sid AND tahun_ajaran_id=4")[0]['updated_at'];$db->query("UPDATE v4_sikap SET adab=NULL WHERE santri_id=$sid AND tahun_ajaran_id=4");}
 else{$id=$q("SELECT id FROM absensi_santri WHERE santri_id=$sid ORDER BY id LIMIT 1")[0]['id'];$old=$q("SELECT * FROM absensi_santri WHERE id=$id")[0];$db->query("DELETE FROM absensi_santri WHERE id=$id");}
 try{$reject(fn()=>$svc->act($pe,$make()),422,'K1 bahan '.$kind.' tidak lengkap/belum diverifikasi');$ok($before===$counts(),'K1 nol PDF terbit/notifikasi '.$kind);}
 finally{if($kind==='nilai')$db->query("UPDATE v4_nilai SET status='Dikirim',updated_at='$time' WHERE id=$id");elseif($kind==='sikap')$db->query("UPDATE v4_sikap SET adab='$old',updated_at='$time' WHERE santri_id=$sid AND tahun_ajaran_id=4");else{$cols=implode(",",array_keys($old));$args=array_values($old);$s=$db->prepare("INSERT INTO absensi_santri ($cols) VALUES (".implode(",",array_fill(0,count($args),"?")).")");$s->bind_param(str_repeat("s",count($args)),...$args);$s->execute();}}
}
$ok($svc->preview($pe,$plan,$sid)['siap_terbit'],'Gate siap setelah sumber dipulihkan');
$asset=$q("SELECT a.* FROM v4_tanda_tangan_aset a JOIN v4_rencana r ON r.pendidikan_aset_id=a.id WHERE r.id=$plan")[0];
$path=(new App\V4\RaporStorage())->path($asset['private_path']);$original=file_get_contents($path);file_put_contents($path,$original.'changed');
try{$reject(fn()=>$svc->act($pe,$make()),422,'Hash gambar tanda tangan berubah memblokir penerbitan');$ok($before===$counts(),'Aset tidak sah menghasilkan nol PDF/notifikasi baru');}finally{file_put_contents($path,$original);}
$input=$make();
foreach(['audit_logs','notifikasi_outbox','v4_verifikasi'] as $table){
 $db->query("CREATE TRIGGER v4_f4_fail BEFORE INSERT ON $table FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic required failure'");
 try{$reject(fn()=>$svc->act($pe,$input),503,'Kegagalan wajib '.$table);$ok($before===$counts(),'Rollback bisnis, kode, outbox dan berkas '.$table);}
 finally{$db->query('DROP TRIGGER v4_f4_fail');}
}
$broken=new class extends App\V4\RaporStorage{public function write(string $n,string $b):void{parent::write($n,substr($b,0,30));throw new App\V4\V4Exception('Gagal sintetis setelah tulis parsial.',503);}};
$reject(fn()=>(new App\V4\RaporService($db,audit_logger(),$broken))->act($pe,$input),503,'Kegagalan filesystem parsial');$ok($before===$counts(),'Berkas parsial dibersihkan, nol dampak bisnis');
$reject(fn()=>$svc->act($admin,$input),422,'Penerbitan admin tanpa alasan');
$stale=$input;$stale['pratinjau_hash']='usang';$reject(fn()=>$svc->act($pe,$stale),409,'Pratinjau usang ditolak');
$db->query('UPDATE pengaturan_notifikasi SET push_enabled=1,whatsapp_enabled=0 WHERE singleton=1');
$r=$svc->act($pe,$input);$rid=(int)$r['id'];$ok((int)$r['current_version']===1&&$r['status']==='Terbit','K2 satu penerbitan versi 1');
$ok($svc->act($pe,$input)['replayed']===true,'K2 retry key sama replay aman');$newkey=$input;$newkey['idempotency_key']=bin2hex(random_bytes(20));$reject(fn()=>$svc->act($pe,$newkey),409,'K2 klik ganda key berbeda versi usang');
$changed=$input;$changed['catatan_umum']='Isi berbeda';$reject(fn()=>$svc->act($pe,$changed),409,'Key sama isi berbeda ditolak');
$ok((int)$q('SELECT COUNT(*) n FROM v4_rapor_versi')[0]['n']===1&&(int)$q('SELECT COUNT(*) n FROM v4_verifikasi')[0]['n']===1,'K2 tepat satu snapshot/PDF/kode');
$ok((int)$q("SELECT COUNT(*) n FROM notifikasi_outbox WHERE event_type='v4_rapor_terbit' AND kanal='InApp'")[0]['n']===2,'K2 satu InApp per masing-masing dua wali berakun');
$ok((int)$q("SELECT COUNT(*) n FROM notifikasi_outbox WHERE kanal='Push'")[0]['n']===2&&(int)$q("SELECT COUNT(*) n FROM notifikasi_outbox WHERE kanal='WhatsApp'")[0]['n']===0,'K6 Push ON dua outbox; WhatsApp nol');
$row=$q("SELECT * FROM v4_rapor_versi WHERE rapor_id=$rid AND version=1")[0];$path=(new App\V4\RaporStorage())->path($row['pdf_private_path']);$bytes=file_get_contents($path);file_put_contents($path,$bytes.'changed');
try{$reject(fn()=>$svc->pdf($pe,$rid,1,true),503,'Berkas kanonis rusak ditolak sebelum unduhan');}finally{file_put_contents($path,$bytes);}
$a=$users['v4_wali4a'];$b=$users['v4_wali4b'];$other=$users['v4_wali4lain'];
$ok(count($svc->listing($a))===1&&count($svc->listing($other))===0,'K5 daftar hanya relasi aktif');
$pdf=$svc->pdf($a,$rid,1);$h=hash('sha256',$pdf);$ok($h===hash('sha256',$svc->pdf($b,$rid,1))&&$h===hash('sha256',$svc->pdf($pe,$rid,1,true)),'K3 unduhan ulang dua wali/petugas hash identik');
file_put_contents(APP_ROOT.'/docs/phase-v4-4/rapor-sintetis-v1.pdf',$pdf);file_put_contents('/private/tmp/v4-fase4-pdf.pdf',$pdf);
foreach([$other,['id'=>0],$users['v4_guru']] as $u){$reject(fn()=>$svc->show($u,$rid),403,'K5 akses silang detail');$reject(fn()=>$svc->pdf($u,$rid,1),403,'K5 akses silang PDF');$reject(fn()=>$svc->pdf($u,$rid,1,true),403,'K5 petugas=1 tidak memberi hak');}
$w=json_decode(file_get_contents('/private/tmp/v4-fase4-ids.json'),true)['wali4']['a'];
$db->query("UPDATE santri_wali SET archived_at=NOW() WHERE santri_id=$sid AND wali_id=$w");
try{$reject(fn()=>$svc->show($a,$rid),403,'K5 relasi dicabut detail');$reject(fn()=>$svc->pdf($a,$rid,1),403,'K5 relasi dicabut PDF');$ok($svc->listing($a)===[],'K5 daftar setelah pencabutan kosong');}finally{$db->query("UPDATE santri_wali SET archived_at=NULL WHERE santri_id=$sid AND wali_id=$w");}
$ns=new App\V4\NilaiService($db,capabilities(),audit_logger());$n=$ns->read($pe,$ids['mapel'],$sid,true);
$reject(fn()=>$ns->save($pe,['rencana_mapel_id'=>$ids['mapel'],'santri_id'=>$sid,'action'=>'koreksi','alasan'=>'Coba koreksi','komponen'=>$n['nilai']],true),409,'Rapor terbit mengunci koreksi nilai');
$sk=new App\V4\SikapService($db,audit_logger());$s=$sk->read($users['v4_pembimbing'],$plan,$sid);
$reject(fn()=>$sk->save($users['v4_pembimbing'],array_replace($s['sikap'],['rencana_id'=>$plan,'action'=>'koreksi','catatan'=>'Coba','alasan'=>'Coba'])),409,'Rapor terbit mengunci koreksi sikap');
$push=$q("SELECT judul,isi,data_json FROM notifikasi_outbox WHERE kanal='Push'");foreach($push as $p){$ok($p['judul']==='Rapor semester tersedia'&&$p['isi']==='Rapor semester tersedia'&&array_keys(json_decode($p['data_json'],true))===['tipe','rapor_id'],'K6 payload antrean hanya generik dan penunjuk');}
$ids=json_decode(file_get_contents('/private/tmp/v4-fase4-ids.json'),true);$ids['rapor']=$rid;$ids['hash_v1']=$h;file_put_contents('/private/tmp/v4-fase4-ids.json',json_encode($ids));
echo "Penerbitan kanonis dan akses sintetis selesai, bukan produksi.\n";
