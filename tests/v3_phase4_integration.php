<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/bootstrap.php';
if(getenv('V3_RUN_TESTS')!=='1'||app_config('database.database')!=='webalhasan_v3_phase1_test')exit(77);
$r=new App\V3\KonselingRepository(app_db());$s=v3_publikasi_service();$k=v3_konseling_service();$fail=0;
$check=static function($ok,$label)use(&$fail){echo ($ok?'[lulus] ':'[gagal] ').$label.PHP_EOL;if(!$ok)$fail++;};
$reject=static function($fn,$status,$label)use($check){try{$fn();$check(false,$label);}catch(App\V3\V3Exception $e){$safe=!str_contains($e->getMessage(),'PRIVATE-F4')&&!str_contains($e->getMessage(),'CREDENTIAL-F4-SENTINEL');$check($e->status===$status&&$safe,$label.' '.$e->status);}};
$user=fn($n)=>$r->one('SELECT id,wali_id FROM users WHERE username=?',[$n]);$a=$user('sbx_pengurus_a');$admin=$user('sbx_admin');$pa=$user('sbx_ortu_a');$pb=$user('sbx_ortu_b');$b=$user('sbx_pengurus_b');
$student=$r->studentOptions((int)$a['id'])[0];$sid=(int)$student['santri_id'];$year=(int)$student['tahun_ajaran_id'];$key=fn()=>'f4-'.bin2hex(random_bytes(12));
$count=fn($table)=>(int)$r->one('SELECT COUNT(*) n FROM '.$table)['n'];
$counts=fn()=>array_map($count,['v3_publikasi','v3_publikasi_riwayat','v3_publikasi_outbox','notifikasi_outbox','v3_idempotency']);
$case=$k->createCase($a,['santri_id'=>$sid,'tahun_ajaran_id'=>$year,'tujuan'=>'PRIVATE-F4-NOT-PUBLIC','kerahasiaan'=>'Rahasia','dibuka_pada'=>date('Y-m-d H:i:s'),'idempotency_key'=>$key()]);$cid=$case['data']['kasus']['id'];
$input=['sumber_type'=>'kasus','sumber_id'=>$cid,'sumber_version'=>1,'ringkasan'=>'Informasi untuk keluarga <script>uji</script>','tindak_lanjut'=>'Diskusikan rencana bersama.'];$before=$counts();
$reject(fn()=>$s->preview($a,$input),422,'Rahasia menolak pratinjau manual');$reject(fn()=>$s->preview($admin,$input),422,'Admin tidak melewati Rahasia');
$check($before===$counts(),'Rahasia tidak menghasilkan publikasi/outbox');
$check((int)$r->one('SELECT COUNT(*) n FROM v3_publikasi WHERE kasus_id=?',[$cid])['n']===0,'Kasus belum terbit memiliki nol publikasi bagi orang tua');
$k->correctCase($a,$cid,['version'=>1,'kerahasiaan'=>'Internal','alasan'=>'Persetujuan revisi kerahasiaan','idempotency_key'=>$key()]);$input['sumber_version']=2;
$rev=$r->caseRevisions($cid)[0];$check($rev['kerahasiaan_sebelum']==='Rahasia'&&$rev['kerahasiaan_sesudah']==='Internal','Revisi sah mencatat sebelum/sesudah');
$preview=$s->preview($a,$input);$check($before[0]===$count('v3_publikasi'),'Pratinjau tidak menerbitkan baris bisnis');
$reject(fn()=>$s->preview($pa,$input),403,'Orang tua tidak menyiapkan publikasi');$reject(fn()=>$s->preview($b,$input),403,'Pembimbing luar cakupan ditolak');
$pubInput=['pratinjau_token'=>$preview['pratinjau_token'],'konfirmasi'=>true,'idempotency_key'=>$key()];
$reject(fn()=>$s->publish($a,array_replace($pubInput,['konfirmasi'=>false])),422,'Konfirmasi wajib');
$published=$s->publish($a,$pubInput);$id=$published['data']['publikasi_ids'][0];$row=$s->show($pa,$id)['publikasi'];
$content=array_intersect_key($row,array_flip(['santri_id','ringkasan','tindak_lanjut']));
$check($preview['konten']===$published['data']['konten']&&$preview['konten']===$content,'Pratinjau = hasil terbit = snapshot orang tua');
$check(!str_contains(json_encode($row),'PRIVATE-F4')&&array_keys($row)===['id','santri_id','ringkasan','tindak_lanjut','diterbitkan_pada','ditarik_pada','dibaca_pada','version','status'],'Allowlist tidak memuat isi internal atau petugas');
$before=$counts();$check($s->publish($a,$pubInput)['replayed'],'Retry mengembalikan hasil pertama');$p2=$s->preview($a,$input);$again=$s->publish($a,['pratinjau_token'=>$p2['pratinjau_token'],'konfirmasi'=>true,'idempotency_key'=>$key()]);$check($again['data']['publikasi_ids']===[$id]&&$counts()[0]===$before[0]&&$counts()[3]===$before[3],'Key dan pratinjau berbeda tetap tidak menggandakan bisnis/notifikasi');
$reject(fn()=>$s->show($pb,$id),403,'IDOR wali B ditolak');
$r->execute('UPDATE santri_wali SET archived_at=NOW() WHERE santri_id=? AND wali_id=?',[$sid,(int)$pa['wali_id']]);
try{$reject(fn()=>$s->show($pa,$id),403,'Pencabutan wali segera menutup akses');}finally{$r->execute('UPDATE santri_wali SET archived_at=NULL WHERE santri_id=? AND wali_id=?',[$sid,(int)$pa['wali_id']]);}
$reject(fn()=>$k->correctCase($a,$cid,['version'=>2,'kerahasiaan'=>'Rahasia','alasan'=>'Uji invariant publikasi aktif','idempotency_key'=>$key()]),409,'Internal ke Rahasia ditolak selama publikasi aktif');
$check($r->case($cid)['kerahasiaan']==='Internal','Kerahasiaan tetap Internal sesudah penolakan');
$read=$s->markRead($pa,$id,['version'=>1]);$check($read['publikasi']['dibaca_pada']!==null,'Status dibaca hanya melalui mutasi');
$correction=$input+['publikasi_id'=>$id,'version'=>1,'alasan'=>'Perbaiki ringkasan publikasi'];$correction['ringkasan']='Ringkasan terkoreksi CREDENTIAL-F4-SENTINEL';
$reject(fn()=>$s->preview($a,array_replace($correction,['alasan'=>''])),422,'Koreksi wajib beralasan');
$cp=$s->preview($admin,$correction);$s->publish($admin,['pratinjau_token'=>$cp['pratinjau_token'],'konfirmasi'=>true,'idempotency_key'=>$key()]);
$detail=$s->show($pa,$id);$check($detail['publikasi']['version']===2&&$detail['publikasi']['dibaca_pada']===null&&count($detail['riwayat'])===2,'Koreksi menyimpan versi, riwayat dan reset baca');
$reject(fn()=>$s->markRead($pa,$id,['version'=>1]),409,'Versi baca basi ditolak');
$reject(fn()=>$s->withdraw($a,$id,['version'=>2,'alasan'=>'','idempotency_key'=>$key()]),422,'Penarikan wajib beralasan');
$s->withdraw($a,$id,['version'=>2,'alasan'=>'Penarikan informasi untuk revisi privasi','idempotency_key'=>$key()]);
$withdrawn=$s->show($pa,$id);$check($withdrawn['publikasi']['status']==='Ditarik'&&$withdrawn['publikasi']['tindak_lanjut']===null&&count($withdrawn['riwayat'])===3,'Penarikan menyisakan status dan riwayat aman');
$k->correctCase($a,$cid,['version'=>2,'kerahasiaan'=>'Rahasia','alasan'=>'Publikasi telah ditarik beralasan','idempotency_key'=>$key()]);
$reject(fn()=>$s->preview($a,array_replace($correction,['sumber_version'=>3,'version'=>3])),422,'Koreksi publikasi sumber Rahasia ditolak');
$reject(fn()=>$s->publish($a,$pubInput),422,'Replay terbit tidak melewati perubahan ke Rahasia');
$check($r->one('SELECT id FROM v3_publikasi WHERE id=?',[$id])!==null,'Baris bisnis tidak dihapus');



// Rollback penuh melalui kegagalan SQL nyata pada audit/outbox.
$k->correctCase($a,$cid,['version'=>3,'kerahasiaan'=>'Internal','alasan'=>'Uji transaksi berikutnya','idempotency_key'=>$key()]);$input['sumber_version']=4;
$rep=$s->preview($a,array_replace($input,['ringkasan'=>'Ringkasan terkoreksi']));$repResult=$s->publish($a,['pratinjau_token'=>$rep['pratinjau_token'],'konfirmasi'=>true,'idempotency_key'=>$key()]);
$check($repResult['data']['publikasi_ids'][0]!==$id,'Setelah penarikan, isi identik boleh diterbitkan ulang sebagai snapshot baru');
$s->withdraw($a,$repResult['data']['publikasi_ids'][0],['version'=>1,'alasan'=>'Akhiri fixture terbit ulang','idempotency_key'=>$key()]);

foreach(['audit_logs','notifikasi_outbox','v3_publikasi_outbox'] as $table){
 $pr=$s->preview($a,array_replace($input,['ringkasan'=>'Uji rollback '.$table]));$before=$counts();$auditBefore=$count('audit_logs');
 app_db()->query("CREATE TRIGGER f4_fail_write BEFORE INSERT ON $table FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='F4 injected failure'");
 try{$reject(fn()=>$s->publish($a,['pratinjau_token'=>$pr['pratinjau_token'],'konfirmasi'=>true,'idempotency_key'=>$key()]),503,'Kegagalan '.$table.' ditolak');}
 finally{app_db()->query('DROP TRIGGER f4_fail_write');}
 $check($before===$counts()&&$auditBefore===$count('audit_logs'),'Rollback penuh termasuk audit/idempotensi: '.$table);
}
$payload=$r->all('SELECT n.* FROM notifikasi_outbox n JOIN v3_publikasi_outbox x ON x.outbox_id=n.id WHERE x.publikasi_id=?',[$id]);
$check(count($payload)>=3&&!str_contains(json_encode($payload),'PRIVATE-F4')&&!str_contains(json_encode($payload),'Ringkasan terkoreksi'),'Notifikasi setiap perubahan generik');
$check(count(array_filter($payload,fn($p)=>$p['pengajuan_id']!==null||$p['kanal']==='WhatsApp'))===0,'Tidak menumpang perizinan atau WhatsApp');
$audits=$r->all("SELECT before_json,after_json FROM audit_logs WHERE entity_type='v3_publikasi' AND entity_id=?",[$id]);$check(!str_contains(json_encode($audits),'PRIVATE-F4')&&!str_contains(json_encode($audits),'Ringkasan terkoreksi'),'Audit publikasi hanya metadata');
$check(!str_contains(json_encode($payload),'CREDENTIAL-F4-SENTINEL')&&!str_contains(json_encode($audits),'CREDENTIAL-F4-SENTINEL'),'Credential sentinel tidak bocor pada payload atau audit publikasi');

// Wali jamak, pemilihan beralasan, versi pratinjau basi, sumber sesi/pelanggaran.
$r->execute('INSERT INTO santri_wali (santri_id,wali_id,hubungan,created_by) VALUES (?,?,?,?)',[$sid,(int)$pb['wali_id'],'Uji F4 tambahan',(int)$admin['id']]);$relation=(int)$r->one('SELECT LAST_INSERT_ID() id')['id'];
try{
 $multi=array_replace($input,['ringkasan'=>'Informasi beberapa wali']);
 $reject(fn()=>$s->preview($a,$multi+['wali_ids'=>[(int)$pa['wali_id']]]),422,'Memilih sebagian wali wajib alasan');
 $previewMulti=$s->preview($a,$multi);$check(count($previewMulti['wali_ids'])===2,'Default semua wali aktif dengan akun');
 $publishedMulti=$s->publish($a,['pratinjau_token'=>$previewMulti['pratinjau_token'],'konfirmasi'=>true,'idempotency_key'=>$key()]);$check(count($publishedMulti['data']['publikasi_ids'])===2,'Satu tindakan menghasilkan tepat satu snapshot per wali');
 foreach($publishedMulti['data']['publikasi_ids'] as $mid){$mr=$r->one('SELECT wali_id FROM v3_publikasi WHERE id=?',[$mid]);$correctParent=(int)$mr['wali_id']===(int)$pa['wali_id']?$pa:$pb;$wrongParent=(int)$mr['wali_id']===(int)$pa['wali_id']?$pb:$pa;$check($s->show($correctParent,$mid)['publikasi']['id']===$mid,'Wali membaca snapshot penerimanya sendiri');$reject(fn()=>$s->show($wrongParent,$mid),403,'Relasi santri sama tidak membuka snapshot wali lain');$s->withdraw($a,$mid,['version'=>1,'alasan'=>'Akhiri fixture wali jamak','idempotency_key'=>$key()]);}
 $subset=$s->preview($a,$multi+['wali_ids'=>[(int)$pa['wali_id']],'alasan_penerima'=>'Koordinasi melalui wali utama']);$check(count($subset['wali_ids'])===1,'Subset beralasan diterima');
}finally{$r->execute('UPDATE santri_wali SET archived_at=NOW() WHERE id=?',[$relation]);}
$stale=$s->preview($a,array_replace($input,['ringkasan'=>'Pratinjau versi basi']));
$k->correctCase($a,$cid,['version'=>4,'tujuan'=>'PRIVATE-F4-CHANGED','alasan'=>'Perubahan versi sumber','idempotency_key'=>$key()]);
$reject(fn()=>$s->publish($a,['pratinjau_token'=>$stale['pratinjau_token'],'konfirmasi'=>true,'idempotency_key'=>$key()]),409,'Sumber berubah sesudah pratinjau menolak terbit');
$session=$k->createSession($a,$cid,['jadwal'=>date('Y-m-d H:i:s',time()+3600),'ringkasan_internal'=>'PRIVATE-F4-SESSION','idempotency_key'=>$key()]);$sessionId=$session['data']['sesi']['id'];
$sp=$s->preview($a,['sumber_type'=>'sesi','sumber_id'=>$sessionId,'sumber_version'=>1,'ringkasan'=>'Kabar pertemuan keluarga','tindak_lanjut'=>'Rencana untuk keluarga']);$spub=$s->publish($a,['pratinjau_token'=>$sp['pratinjau_token'],'konfirmasi'=>true,'idempotency_key'=>$key()]);$spid=$spub['data']['publikasi_ids'][0];
$reject(fn()=>$k->correctCase($a,$cid,['version'=>(int)$r->case($cid)['version'],'kerahasiaan'=>'Rahasia','alasan'=>'Uji invariant sumber sesi','idempotency_key'=>$key()]),409,'Publikasi sumber sesi juga memblokir perubahan Rahasia');
$check(!str_contains(json_encode($s->show($pa,$spid)),'PRIVATE-F4-SESSION'),'Snapshot sesi tidak membawa isi sesi internal');
$s->withdraw($a,$spid,['version'=>1,'alasan'=>'Penarikan fixture sesi','idempotency_key'=>$key()]);
$k->correctCase($a,$cid,['version'=>(int)$r->case($cid)['version'],'kerahasiaan'=>'Rahasia','alasan'=>'Uji privasi sumber sesi','idempotency_key'=>$key()]);
$reject(fn()=>$s->preview($a,['sumber_type'=>'sesi','sumber_id'=>$sessionId,'sumber_version'=>1,'ringkasan'=>'Manual','tindak_lanjut'=>'Manual']),422,'Sesi induk Rahasia menolak preview manual');
$catalog=$r->one("SELECT id FROM v3_katalog WHERE is_active=1 AND archived_at IS NULL AND tanggal_mulai<=CURDATE() AND (tanggal_selesai IS NULL OR tanggal_selesai>=CURDATE()) ORDER BY id DESC LIMIT 1")??throw new RuntimeException('Katalog sandbox aktif diperlukan');
$violationCreated=v3_pelanggaran_service()->create($a,['santri_id'=>$sid,'tahun_ajaran_id'=>$year,'katalog_id'=>(int)$catalog['id'],'waktu_kejadian'=>date('Y-m-d\TH:i'),'tempat'=>'SBX F4','uraian'=>'Pelanggaran fixture F4 '.bin2hex(random_bytes(5)),'saksi'=>'SBX saksi internal','idempotency_key'=>$key()]);
$violation=$r->one('SELECT id,version FROM v3_pelanggaran WHERE id=?',[(int)$violationCreated['data']['pelanggaran']['id']]);
$vp=$s->preview($a,['sumber_type'=>'pelanggaran','sumber_id'=>(int)$violation['id'],'sumber_version'=>(int)$violation['version'],'ringkasan'=>'Ringkasan pelanggaran untuk keluarga','tindak_lanjut'=>'Pembinaan keluarga']);$vpub=$s->publish($a,['pratinjau_token'=>$vp['pratinjau_token'],'konfirmasi'=>true,'idempotency_key'=>$key()]);$check($s->show($pa,$vpub['data']['publikasi_ids'][0])['publikasi']['ringkasan']===$vp['konten']['ringkasan'],'Sumber pelanggaran memakai snapshot pilihan, tanpa poin internal');
$vid=(int)$violation['id'];$vpid=(int)$vpub['data']['publikasi_ids'][0];
$reject(fn()=>$k->addLinks($a,$cid,['pelanggaran_ids'=>[$vid],'alasan'=>'Tautan uji privasi','idempotency_key'=>$key()]),409,'Pelanggaran terbit tidak boleh ditautkan ke kasus Rahasia');
$s->withdraw($a,$vpid,['version'=>1,'alasan'=>'Tarik sebelum tautan Rahasia','idempotency_key'=>$key()]);
$k->addLinks($a,$cid,['pelanggaran_ids'=>[$vid],'alasan'=>'Tautan setelah penarikan','idempotency_key'=>$key()]);
$secretOutbox=$count('notifikasi_outbox');
$reject(fn()=>$s->preview($a,['sumber_type'=>'pelanggaran','sumber_id'=>$vid,'sumber_version'=>(int)$violation['version'],'ringkasan'=>'Teks manual','tindak_lanjut'=>'Teks manual']),422,'Pelanggaran terkait Rahasia menolak pratinjau manual');
$check($count('notifikasi_outbox')===$secretOutbox,'Penolakan pelanggaran Rahasia tidak membuat notifikasi');
$k->correctCase($a,$cid,['version'=>(int)$r->case($cid)['version'],'kerahasiaan'=>'Internal','alasan'=>'Uji revisi tautan ke Internal','idempotency_key'=>$key()]);
$linkedPreview=$s->preview($a,['sumber_type'=>'pelanggaran','sumber_id'=>$vid,'sumber_version'=>(int)$violation['version'],'ringkasan'=>'Kabar tautan setelah revisi','tindak_lanjut'=>'Pendampingan keluarga']);
$linkedPub=$s->publish($a,['pratinjau_token'=>$linkedPreview['pratinjau_token'],'konfirmasi'=>true,'idempotency_key'=>$key()]);$linkedId=(int)$linkedPub['data']['publikasi_ids'][0];
$reject(fn()=>$k->correctCase($a,$cid,['version'=>(int)$r->case($cid)['version'],'kerahasiaan'=>'Rahasia','alasan'=>'Uji blokir publikasi tautan','idempotency_key'=>$key()]),409,'Publikasi pelanggaran tertaut memblokir Internal ke Rahasia');
$s->withdraw($a,$linkedId,['version'=>1,'alasan'=>'Tarik tautan sebelum rahasia','idempotency_key'=>$key()]);
$k->correctCase($a,$cid,['version'=>(int)$r->case($cid)['version'],'kerahasiaan'=>'Rahasia','alasan'=>'Seluruh publikasi tautan sudah ditarik','idempotency_key'=>$key()]);
$check($r->case($cid)['kerahasiaan']==='Rahasia','Perubahan ke Rahasia aman setelah semua penarikan');
// Audit Claude Code Fase 4: Rahasia hanya diketahui pemilik/admin pada seluruh jalur publikasi (5.5a).
$ownerPengurus=(int)$r->case($cid)['pembimbing_id'];$otherPengurus=(int)$r->one("SELECT pengurus_id FROM users WHERE username='sbx_pengurus_b'")['pengurus_id'];
$r->execute('UPDATE v3_konseling_kasus SET pembimbing_id=? WHERE id=?',[$otherPengurus,$cid]);
try{
 $reject(fn()=>$s->options($a,'kasus',$cid),403,'Bukan pemilik tidak mengetahui kasus Rahasia lewat opsi publikasi');
 $reject(fn()=>$s->options($a,'sesi',$sessionId),403,'Bukan pemilik tidak mengetahui sesi kasus Rahasia');
 $reject(fn()=>$s->preview($a,['sumber_type'=>'kasus','sumber_id'=>$cid,'sumber_version'=>(int)$r->case($cid)['version'],'ringkasan'=>'Manual','tindak_lanjut'=>'Manual']),403,'Bukan pemilik tidak memperoleh pesan Rahasia pada pratinjau');
 $reject(fn()=>$s->manage($a,$id),403,'Bukan pemilik tidak membaca riwayat publikasi kasus Rahasia');
 $reject(fn()=>$s->withdraw($a,$id,['version'=>(int)$r->one('SELECT version FROM v3_publikasi WHERE id=?',[$id])['version'],'alasan'=>'Uji bukan pemilik','idempotency_key'=>$key()]),403,'Bukan pemilik tidak menarik publikasi kasus Rahasia');
 $check($s->manage($admin,$id)['publikasi']['id']===$id,'Admin tetap dapat mengawasi publikasi kasus Rahasia');
}finally{$r->execute('UPDATE v3_konseling_kasus SET pembimbing_id=? WHERE id=?',[$ownerPengurus,$cid]);}
$check($s->manage($a,$id)['publikasi']['id']===$id,'Pemilik tetap dapat membuka publikasi kasus Rahasia yang telah ditarik');
try{$s->preview($a,['sumber_type'=>'pelanggaran','sumber_id'=>$vid,'sumber_version'=>(int)$violation['version'],'ringkasan'=>'Teks','tindak_lanjut'=>'Teks']);$check(false,'Pesan penolakan pelanggaran tertaut netral');}catch(App\V3\V3Exception $e){$check($e->status===422&&!str_contains($e->getMessage(),'Rahasia'),'Pesan penolakan pelanggaran tertaut netral');}
// Audit Claude Code Fase 4: fingerprint tidak boleh mengembalikan snapshot yang isinya sudah dikoreksi.
$dc=$k->createCase($a,['santri_id'=>$sid,'tahun_ajaran_id'=>$year,'tujuan'=>'PRIVATE-F4-DEDUP','kerahasiaan'=>'Internal','dibuka_pada'=>date('Y-m-d H:i:s'),'idempotency_key'=>$key()])['data']['kasus']['id'];
$dIn=['sumber_type'=>'kasus','sumber_id'=>$dc,'sumber_version'=>1,'ringkasan'=>'Isi asli audit','tindak_lanjut'=>'TL audit'];
$dp=$s->preview($a,$dIn);$did=$s->publish($a,['pratinjau_token'=>$dp['pratinjau_token'],'konfirmasi'=>true,'idempotency_key'=>$key()])['data']['publikasi_ids'][0];
$dcp=$s->preview($a,array_replace($dIn,['ringkasan'=>'Isi koreksi audit','publikasi_id'=>$did,'version'=>1,'alasan'=>'Koreksi isi audit']));$s->publish($a,['pratinjau_token'=>$dcp['pratinjau_token'],'konfirmasi'=>true,'idempotency_key'=>$key()]);
$dp2=$s->preview($a,$dIn);$dr=$s->publish($a,['pratinjau_token'=>$dp2['pratinjau_token'],'konfirmasi'=>true,'idempotency_key'=>$key()]);$dnew=$dr['data']['publikasi_ids'][0];
$check($dnew!==$did&&$r->one('SELECT ringkasan FROM v3_publikasi WHERE id=?',[$dnew])['ringkasan']===$dr['data']['konten']['ringkasan'],'Terbit ulang isi asli sesudah koreksi membuat snapshot yang sesuai respons');
$dp3=$s->preview($a,$dIn);$check($s->publish($a,['pratinjau_token'=>$dp3['pratinjau_token'],'konfirmasi'=>true,'idempotency_key'=>$key()])['data']['publikasi_ids']===[$dnew],'Klik ganda sesudah terbit ulang tetap tidak menggandakan');
foreach([$did,$dnew] as $cleanup)$s->withdraw($a,$cleanup,['version'=>(int)$r->one('SELECT version FROM v3_publikasi WHERE id=?',[$cleanup])['version'],'alasan'=>'Akhiri fixture audit','idempotency_key'=>$key()]);
exit($fail?1:0);
