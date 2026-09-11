<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/app/bootstrap.php';
if(getenv('V3_RUN_TESTS')!=='1'||app_config('database.database')!=='webalhasan_v3_phase1_test')exit(77);
$repo=new App\V3\KonselingRepository(app_db());$pRepo=new App\V3\PelanggaranRepository(app_db());$kRepo=new App\V3\KatalogRepository(app_db());$service=v3_konseling_service();$pService=v3_pelanggaran_service();$catalogService=v3_katalog_service();
$fails=0;$assert=static function(bool $ok,string $label)use(&$fails){echo ($ok?'[lulus] ':'[gagal] ').$label.PHP_EOL;if(!$ok)$fails++;};$reject=static function(callable $work,string $label,int $status=422)use($assert){try{$work();$assert(false,$label);}catch(App\V3\V3Exception $e){$assert($e->status===$status,$label.' ('.$e->status.')');}};
$user=static fn(string $name):array=>$repo->one('SELECT id FROM users WHERE username=?',[$name])??throw new RuntimeException('Fixture hilang '.$name);
$admin=$user('sbx_admin');$pembimbingA=$user('sbx_pengurus_a');$pembimbingB=$user('sbx_pengurus_b');$murobiA=$user('sbx_murobi_a');$murobiB=$user('sbx_murobi_b');$ortuA=$user('sbx_ortu_a');
$year=(int)$repo->one("SELECT id FROM tahun_ajaran WHERE status='Aktif' AND archived_at IS NULL ORDER BY id LIMIT 1")['id'];$childA=(int)$repo->one("SELECT sw.santri_id FROM santri_wali sw JOIN users u ON u.wali_id=sw.wali_id WHERE u.username='sbx_ortu_a' AND sw.archived_at IS NULL LIMIT 1")['santri_id'];$childB=(int)$repo->one("SELECT sw.santri_id FROM santri_wali sw JOIN users u ON u.wali_id=sw.wali_id WHERE u.username='sbx_ortu_b' AND sw.archived_at IS NULL LIMIT 1")['santri_id'];
$tag='SBX-F3-'.bin2hex(random_bytes(5));$key=static fn(string $prefix):string=>$prefix.'-'.bin2hex(random_bytes(10));$period=['tanggal_mulai'=>date('Y-m-d',time()-86400),'tanggal_selesai'=>'','is_active'=>1];
$categoryId=$catalogService->save('kategori',$period+['kode'=>$tag,'nama'=>'SBX Kategori F3 '.$tag,'uraian'=>'Fixture fiktif'],$admin);$catalogId=$catalogService->save('katalog',$period+['kode'=>$tag,'nama'=>'SBX Jenis F3 '.$tag,'kategori_id'=>$categoryId,'tingkat'=>'Ringan','poin_default'=>3,'uraian'=>'Fixture Fase 3'],$admin);
foreach($kRepo->rows('SELECT * FROM v3_ambang WHERE is_active=1 AND archived_at IS NULL') as $old)$catalogService->save('ambang',array_replace($old,['is_active'=>0,'alasan'=>'Isolasi suite Fase 3']),$admin,(int)$old['id']);
$thresholdId=$catalogService->save('ambang',$period+['tahun_ajaran_id'=>$year,'nilai_minimum'=>0,'nilai_maksimum'=>'','label'=>'SBX tindak lanjut '.$tag,'rekomendasi'=>'Hubungkan manual ke kasus konseling'],$admin);
$base=['santri_id'=>$childA,'tahun_ajaran_id'=>$year,'katalog_id'=>$catalogId,'tempat'=>'SBX area F3'];
$first=$pService->create($pembimbingA,$base+['waktu_kejadian'=>date('Y-m-d\TH:i',time()-7200),'uraian'=>'SBX pelanggaran pertama '.$tag,'idempotency_key'=>$key('violation-a')]);$firstId=(int)$first['data']['pelanggaran']['id'];$recommendationId=(int)($first['data']['rekomendasi_baru'][0]??0);
$second=$pService->create($pembimbingA,$base+['waktu_kejadian'=>date('Y-m-d\TH:i',time()-3600),'uraian'=>'SBX pelanggaran kedua '.$tag,'idempotency_key'=>$key('violation-b')]);$secondId=(int)$second['data']['pelanggaran']['id'];
$assert($recommendationId>0,'Fixture menghasilkan rekomendasi yang masih berlaku');

$caseInput=['santri_id'=>$childA,'tahun_ajaran_id'=>$year,'tujuan'=>'SBX tujuan internal sangat rahasia '.$tag,'kerahasiaan'=>'Internal','dibuka_pada'=>date('Y-m-d\TH:i',time()-1800),'pelanggaran_ids'=>[$firstId,$secondId],'rekomendasi_ids'=>[$recommendationId],'idempotency_key'=>$key('case')];
$created=$service->createCase($pembimbingA,$caseInput);$caseId=(int)$created['data']['kasus']['id'];$assert($created['status']===201&&!$created['replayed'],'Pembimbing membuka kasus dalam cakupan');$assert(count($created['data']['tautan_ids'])===2,'Satu kasus menautkan beberapa pelanggaran santri yang sama');$assert((int)$repo->one('SELECT ditindaklanjuti_kasus_id FROM v3_rekomendasi WHERE id=?',[$recommendationId])['ditindaklanjuti_kasus_id']===$caseId,'Rekomendasi berlaku ditautkan manual ke kasus');
$reject(fn()=>$service->addLinks($pembimbingA,$caseId,['pelanggaran_ids'=>[$firstId],'alasan'=>'Duplikat tautan fixture','idempotency_key'=>$key('duplicate-link')]),'Tautan tingkat kasus yang sama tidak dapat digandakan',409);
$replay=$service->createCase($pembimbingA,$caseInput);$assert($replay['replayed']&&(int)$replay['data']['kasus']['id']===$caseId,'Retry kasus me-replay satu dampak bisnis');
$reject(fn()=>$service->createCase($pembimbingA,array_replace($caseInput,['santri_id'=>$childB,'idempotency_key'=>$key('cross-create')])),'Pembimbing tidak membuat kasus di luar cakupan',403);
$reject(fn()=>$service->createCase($pembimbingA,array_replace($caseInput,['pelanggaran_ids'=>[$firstId],'rekomendasi_ids'=>[$recommendationId],'idempotency_key'=>$key('used-rec')])),'Rekomendasi yang sudah ditindaklanjuti tidak dapat ditautkan ulang',409);

$sessionOne=$service->createSession($pembimbingA,$caseId,['jadwal'=>date('Y-m-d\TH:i',time()+3600),'tindak_lanjut'=>'SBX rencana internal satu','pelanggaran_ids'=>[$firstId],'idempotency_key'=>$key('session-a')]);$sessionOneId=(int)$sessionOne['data']['sesi']['id'];
$sessionTwo=$service->createSession($pembimbingA,$caseId,['jadwal'=>date('Y-m-d\TH:i',time()+7200),'tindak_lanjut'=>'SBX rencana internal dua','pelanggaran_ids'=>[$firstId],'idempotency_key'=>$key('session-b')]);$sessionTwoId=(int)$sessionTwo['data']['sesi']['id'];
$assert((int)$repo->one('SELECT COUNT(*) n FROM v3_konseling_sesi WHERE kasus_id=?',[$caseId])['n']===2,'Dua sesi tersimpan sebagai baris berbeda dalam satu kasus');$assert((int)$repo->one('SELECT COUNT(*) n FROM v3_konseling_tautan WHERE pelanggaran_id=? AND kasus_id=?',[$firstId,$caseId])['n']===3,'Satu pelanggaran ditindaklanjuti pada tingkat kasus dan beberapa sesi');
$violationDetail=$pService->show($pembimbingA,$firstId);$sessionIds=array_values(array_filter(array_column($violationDetail['konseling'],'sesi_id')));$assert(in_array($sessionOneId,$sessionIds,true)&&in_array($sessionTwoId,$sessionIds,true),'Detail pelanggaran menampilkan seluruh sesi tindak lanjut tanpa menggandakan pelanggaran');

$completeInput=['version'=>1,'status'=>'Selesai','realisasi'=>date('Y-m-d\TH:i'),'ringkasan_internal'=>'SBX isi konseling rahasia '.$tag,'hasil'=>'SBX hasil internal '.$tag,'tindak_lanjut'=>'SBX tindak lanjut internal '.$tag,'idempotency_key'=>$key('complete')];$completed=$service->transitionSession($pembimbingA,$sessionOneId,$completeInput);$assert($completed['data']['sesi']['status']==='Selesai','Sesi dapat diselesaikan dengan ringkasan dan hasil internal');$assert($service->transitionSession($pembimbingA,$sessionOneId,$completeInput)['replayed'],'Retry transisi sesi terminal me-replay dampak pertama');
$reject(fn()=>$service->transitionSession($pembimbingA,$sessionOneId,['version'=>1,'status'=>'Tidak Hadir','idempotency_key'=>$key('stale-status')]),'Versi lama sesi ditolak tanpa menimpa perubahan baru',409);
$corrected=$service->correctSession($pembimbingA,$sessionOneId,['version'=>2,'jadwal'=>date('Y-m-d\TH:i',time()+3600),'ringkasan_internal'=>'SBX isi dikoreksi '.$tag,'hasil'=>'SBX hasil dikoreksi '.$tag,'tindak_lanjut'=>'SBX tindak lanjut dikoreksi '.$tag,'alasan'=>'Koreksi catatan sesi selesai','idempotency_key'=>$key('correct-session')]);$correctedId=(int)$corrected['data']['sesi']['id'];$assert($correctedId!==$sessionOneId&&(int)$repo->one('SELECT COUNT(*) n FROM v3_konseling_sesi WHERE revisi_dari_id=?',[$sessionOneId])['n']===1,'Koreksi sesi selesai membuat revisi beralasan');$assert((string)$repo->session($sessionOneId)['ringkasan_internal']==='SBX isi konseling rahasia '.$tag,'Koreksi sesi tidak menimpa isi historis');
$reject(fn()=>$service->correctSession($pembimbingA,$sessionOneId,['version'=>2,'alasan'=>'Versi lama kedua','idempotency_key'=>$key('stale-correct')]),'Sumber sesi tidak dapat memiliki revisi kedua',409);

$rescheduled=$service->transitionSession($pembimbingA,$sessionTwoId,['version'=>1,'status'=>'Dijadwalkan Ulang','jadwal'=>date('Y-m-d\TH:i',time()+10800),'realisasi'=>date('Y-m-d\TH:i'),'alasan'=>'Penyesuaian jadwal pembimbing','idempotency_key'=>$key('reschedule')]);$rescheduledId=(int)$rescheduled['data']['sesi']['id'];$assert($rescheduledId!==$sessionTwoId&&$rescheduled['data']['sesi']['status']==='Dijadwalkan Ulang'&&$rescheduled['data']['sesi']['alasan_penjadwalan_ulang']!==null&&$rescheduled['data']['sesi']['realisasi']===null,'Penjadwalan ulang membentuk revisi dengan alasan terpisah tanpa realisasi');
$invalidBefore=(int)$repo->one('SELECT COUNT(*) n FROM v3_konseling_sesi WHERE kasus_id=?',[$caseId])['n'];$reject(fn()=>$service->transitionSession($pembimbingA,$correctedId,['version'=>1,'status'=>'Dibatalkan','alasan'=>'Tidak sah sesudah selesai','idempotency_key'=>$key('invalid-terminal')]),'Transisi dari sesi terminal ditolak',422);$assert((int)$repo->one('SELECT COUNT(*) n FROM v3_konseling_sesi WHERE kasus_id=?',[$caseId])['n']===$invalidBefore,'Transisi tidak sah tidak menulis perubahan parsial');

$murobiDetail=$service->show($murobiA,$caseId);$assert($murobiDetail['akses_internal']&&($murobiDetail['kasus']['tujuan']??null)==='SBX tujuan internal sangat rahasia '.$tag,'Murobi terkait membaca isi kasus Internal (keputusan Human Developer)');$murobiSessions=array_column($murobiDetail['sesi'],null,'id');$assert(($murobiSessions[$sessionOneId]['ringkasan_internal']??null)==='SBX isi konseling rahasia '.$tag,'Murobi terkait membaca isi sesi pada kasus Internal');
$ack=$service->acknowledge($murobiA,'kasus',$caseId,['catatan'=>'SBX catatan murobi terpisah','idempotency_key'=>$key('ack-case')]);$assert($ack['status']===201&&(int)$repo->one('SELECT COUNT(*) n FROM v3_murobi_catatan WHERE kasus_id=?',[$caseId])['n']===1,'Murobi terkait memberi catatan kasus pada kolom sumber terpisah');$service->acknowledge($murobiA,'sesi',$rescheduledId,['catatan'=>'SBX catatan sesi murobi','idempotency_key'=>$key('ack-session')]);
$reject(fn()=>$service->show($murobiB,$caseId),'Murobi lain ditolak dari detail kasus',403);$reject(fn()=>$service->show($pembimbingB,$caseId),'Pembimbing lain ditolak dari detail kasus',403);$reject(fn()=>$service->show($ortuA,$caseId),'Orang tua ditolak dari endpoint internal kasus',403);$reject(fn()=>$service->acknowledge($murobiB,'kasus',$caseId,['idempotency_key'=>$key('ack-cross')]),'Murobi lain tidak dapat memberi catatan',403);
$assert(count(array_filter($service->page($pembimbingB,[])['rows'],static fn(array $row):bool=>(int)$row['id']===$caseId))===0,'Daftar pembimbing lain tidak membocorkan kasus');$assert(count(array_filter($service->page($murobiA,[])['rows'],static fn(array $row):bool=>(int)$row['id']===$caseId))===1,'Murobi terkait melihat kasus pada daftar cakupannya');
$public=$service->parentSerializer(['id'=>99,'santri_id'=>$childA,'ringkasan'=>'Ringkasan publik eksplisit','tindak_lanjut'=>'Tindak lanjut publik','diterbitkan_pada'=>date('Y-m-d H:i:s'),'tujuan'=>'RAHASIA','ringkasan_internal'=>'RAHASIA','hasil'=>'RAHASIA','catatan_murobi'=>'RAHASIA','pembimbing_id'=>999]);$assert(array_keys($public)===['id','santri_id','ringkasan','tindak_lanjut','diterbitkan_pada','ditarik_pada','dibaca_pada']&&strpos(json_encode($public),'RAHASIA')===false,'DTO orang tua allowlist tidak pernah membawa field internal');

$adminReadBefore=(int)$repo->one("SELECT COUNT(*) n FROM audit_logs WHERE action='v3.konseling.kasus.dilihat.admin' AND entity_id=?",[$caseId])['n'];$adminDetail=$service->show($admin,$caseId);$adminReadAfter=(int)$repo->one("SELECT COUNT(*) n FROM audit_logs WHERE action='v3.konseling.kasus.dilihat.admin' AND entity_id=?",[$caseId])['n'];$assert($adminDetail['akses_internal']&&$adminReadAfter===$adminReadBefore+1,'Pembukaan detail sensitif oleh admin tercatat sebagai audit akses');
$adminCase=$service->correctCase($admin,$caseId,['version'=>(int)$repo->case($caseId)['version'],'tujuan'=>'SBX tujuan terkoreksi admin '.$tag,'kerahasiaan'=>'Rahasia','alasan'=>'Koreksi administratif beralasan','idempotency_key'=>$key('admin-case')]);$assert($adminCase['data']['kasus']['tujuan']==='SBX tujuan terkoreksi admin '.$tag,'Admin mengoreksi kasus hanya melalui alasan wajib');$auditAction=(string)$repo->one("SELECT action FROM audit_logs WHERE entity_type='v3_konseling_kasus' AND entity_id=? ORDER BY id DESC LIMIT 1",[$caseId])['action'];$assert($auditAction==='v3.konseling.kasus.dikoreksi.admin','Audit membedakan koreksi administratif');
$revisions=$repo->caseRevisions($caseId);$assert(count($revisions)===1&&$revisions[0]['kerahasiaan_sebelum']==='Internal'&&$revisions[0]['kerahasiaan_sesudah']==='Rahasia'&&$revisions[0]['tujuan_sebelum']==='SBX tujuan internal sangat rahasia '.$tag&&$revisions[0]['kapasitas']==='admin'&&(int)$adminCase['data']['revisi_kasus_id']===(int)$revisions[0]['id'],'Koreksi kasus tersimpan sebagai revisi berbaris dengan nilai sebelum/sesudah');
$assert(count($service->show($pembimbingA,$caseId)['riwayat_revisi_kasus'])===1,'Detail kasus menampilkan riwayat revisi');
$reject(fn()=>$service->show($murobiA,$caseId),'Kasus Rahasia tidak dapat dibuka murobi terkait',403);$reject(fn()=>$service->acknowledge($murobiA,'kasus',$caseId,['idempotency_key'=>$key('ack-secret')]),'Murobi tidak dapat memberi tanda mengetahui pada kasus Rahasia',403);
$assert(count(array_filter($service->page($murobiA,[])['rows'],static fn(array $row):bool=>(int)$row['id']===$caseId))===0,'Kasus Rahasia tidak muncul pada daftar murobi');
$assert(!in_array($caseId,array_column($pService->show($murobiA,$firstId)['konseling'],'kasus_id'),true)&&in_array($caseId,array_column($pService->show($pembimbingA,$firstId)['konseling'],'kasus_id'),true),'Detail pelanggaran menyembunyikan kasus Rahasia dari murobi tetapi tidak dari pembimbing pemilik');
$caseVersion=(int)$repo->case($caseId)['version'];$reject(fn()=>$service->transitionCase($pembimbingA,$caseId,['version'=>$caseVersion,'status'=>'Dalam Pendampingan','idempotency_key'=>$key('invalid-case')]),'Transisi status kasus yang tidak sah ditolak',422);$assert((int)$repo->case($caseId)['version']===$caseVersion,'Transisi kasus tidak sah tidak mengubah versi');
$closeInput=['version'=>$caseVersion,'status'=>'Selesai','ringkasan_penutupan'=>'SBX ringkasan hasil penutupan '.$tag,'idempotency_key'=>$key('close')];$closed=$service->transitionCase($pembimbingA,$caseId,$closeInput);$assert($closed['data']['kasus']['status']==='Selesai'&&$closed['data']['kasus']['ditutup_pada']!==null,'Kasus ditutup hanya dengan ringkasan hasil');$assert($service->transitionCase($pembimbingA,$caseId,$closeInput)['replayed'],'Retry penutupan kasus me-replay dampak pertama');$assert((int)$repo->one('SELECT COUNT(*) n FROM v3_konseling_sesi WHERE kasus_id=?',[$caseId])['n']===$invalidBefore,'Penutupan mempertahankan seluruh sesi dan revisi');$assert((int)$repo->one('SELECT COUNT(*) n FROM v3_konseling_tautan WHERE kasus_id=?',[$caseId])['n']===4,'Penutupan mempertahankan seluruh tautan pelanggaran');
$autoClosedRow=$repo->session($rescheduledId);$assert($closed['data']['sesi_ditutup_otomatis']===[$rescheduledId]&&$autoClosedRow['status']==='Dibatalkan'&&$autoClosedRow['realisasi']===null&&str_starts_with((string)$autoClosedRow['alasan_pembatalan'],'Ditutup otomatis: kasus diselesaikan'),'Penutupan Selesai menutup sesi terjadwal sebagai Dibatalkan beralasan sistem');
$assert((int)$repo->one("SELECT COUNT(*) n FROM audit_logs WHERE action='v3.konseling.sesi.ditutup_otomatis' AND entity_id=?",[$rescheduledId])['n']===1,'Penutupan otomatis sesi tercatat audit');

$businessBefore=(int)$repo->one('SELECT COUNT(*) n FROM v3_konseling_kasus')['n'];$outboxBefore=(int)$repo->one("SELECT COUNT(*) n FROM notifikasi_outbox WHERE event_type LIKE 'v3_konseling%'")['n'];$repo->execute("CREATE TRIGGER v3_f3_audit_failure BEFORE INSERT ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic audit failure'");
try{$reject(fn()=>$service->createCase($pembimbingA,['santri_id'=>$childA,'tahun_ajaran_id'=>$year,'tujuan'=>'SBX transaksi gagal '.$tag,'kerahasiaan'=>'Rahasia','dibuka_pada'=>date('Y-m-d\TH:i'),'idempotency_key'=>$key('audit-fail')]),'Kegagalan audit menggulung kasus dan outbox',503);}finally{$repo->execute('DROP TRIGGER v3_f3_audit_failure');}
$assert((int)$repo->one('SELECT COUNT(*) n FROM v3_konseling_kasus')['n']===$businessBefore&&(int)$repo->one("SELECT COUNT(*) n FROM notifikasi_outbox WHERE event_type LIKE 'v3_konseling%'")['n']===$outboxBefore,'Tidak ada kasus atau outbox parsial setelah audit gagal');
$outbox=$repo->all("SELECT judul,isi,data_json FROM notifikasi_outbox WHERE event_type LIKE 'v3_konseling%'");$safe=true;foreach($outbox as $notification){$payload=implode(' ',array_map('strval',$notification));if(str_contains($payload,$tag)||preg_match('/tujuan|ringkasan_internal|hasil|tindak_lanjut|catatan/',$payload))$safe=false;}$assert($outbox!==[]&&$safe,'Outbox konseling hanya memuat pesan generik tanpa rincian sensitif');

// ===== Regresi koreksi audit Claude Code Fase 3 =====
// K1: kasus tertutup membekukan sesinya, tetapi replay transisi sebelum penutupan tetap dijawab dari idempotensi.
$sessionsClosed=(int)$repo->one('SELECT COUNT(*) n FROM v3_konseling_sesi WHERE kasus_id=?',[$caseId])['n'];
$reject(fn()=>$service->transitionSession($pembimbingA,$rescheduledId,['version'=>(int)$repo->session($rescheduledId)['version'],'status'=>'Dijadwalkan Ulang','jadwal'=>date('Y-m-d\TH:i',time()+14400),'alasan'=>'Jadwal ulang sesudah kasus ditutup','idempotency_key'=>$key('k1-reschedule')]),'K1 jadwal ulang sesi pada kasus Selesai ditolak',422);
$reject(fn()=>$service->transitionSession($pembimbingA,$rescheduledId,['version'=>(int)$repo->session($rescheduledId)['version'],'status'=>'Selesai','ringkasan_internal'=>'SBX ringkasan terlambat','hasil'=>'SBX hasil terlambat','idempotency_key'=>$key('k1-complete')]),'K1 penyelesaian sesi pada kasus Selesai ditolak',422);
$assert((int)$repo->one('SELECT COUNT(*) n FROM v3_konseling_sesi WHERE kasus_id=?',[$caseId])['n']===$sessionsClosed&&(string)$repo->session($rescheduledId)['status']==='Dibatalkan','K1 kasus tertutup tidak memperoleh sesi atau status sesi baru');
$assert($service->transitionSession($pembimbingA,$sessionOneId,$completeInput)['replayed'],'K1 replay transisi sebelum penutupan tetap dijawab dari idempotensi');

// K2: kasus batal melepas rekomendasinya, dan rekomendasi dapat ditautkan manual ke kasus yang sudah berjalan.
foreach($kRepo->rows('SELECT * FROM v3_ambang WHERE is_active=1 AND archived_at IS NULL') as $old)$catalogService->save('ambang',array_replace($old,['is_active'=>0,'alasan'=>'Isolasi regresi audit Fase 3']),$admin,(int)$old['id']);
$catalogService->save('ambang',$period+['tahun_ajaran_id'=>$year,'nilai_minimum'=>0,'nilai_maksimum'=>'','label'=>'SBX audit '.$tag,'rekomendasi'=>'Tautkan manual ke kasus berjalan'],$admin);
$third=$pService->create($pembimbingA,$base+['waktu_kejadian'=>date('Y-m-d\TH:i',time()-5400),'uraian'=>'SBX pelanggaran ketiga '.$tag,'idempotency_key'=>$key('violation-c')]);$thirdId=(int)$third['data']['pelanggaran']['id'];$auditRec=(int)($third['data']['rekomendasi_baru'][0]??0);$assert($auditRec>0,'K2 fixture menghasilkan rekomendasi baru');
$mistakenId=(int)$service->createCase($pembimbingA,['santri_id'=>$childA,'tahun_ajaran_id'=>$year,'tujuan'=>'SBX kasus keliru '.$tag,'kerahasiaan'=>'Rahasia','dibuka_pada'=>date('Y-m-d\TH:i',time()-900),'rekomendasi_ids'=>[$auditRec],'idempotency_key'=>$key('k2-mistaken')])['data']['kasus']['id'];
$cancelled=$service->transitionCase($pembimbingA,$mistakenId,['version'=>(int)$repo->case($mistakenId)['version'],'status'=>'Dibatalkan','alasan'=>'Kasus dibuka untuk santri yang keliru','idempotency_key'=>$key('k2-cancel')]);
$released=$repo->one('SELECT status,ditindaklanjuti_kasus_id,ditindaklanjuti_pada FROM v3_rekomendasi WHERE id=?',[$auditRec]);
$assert($cancelled['data']['rekomendasi_dilepas']===[$auditRec]&&$released['ditindaklanjuti_kasus_id']===null&&$released['ditindaklanjuti_pada']===null&&$released['status']==='Baru','K2 pembatalan kasus melepas rekomendasi tanpa menghapus barisnya');
$assert(in_array($auditRec,array_column($service->options($pembimbingA)['rekomendasi_belum_ditindaklanjuti'],'id'),true),'K2 rekomendasi yang dilepas kembali ke antrean manual');
$cancelAudit=json_decode((string)$repo->one("SELECT after_json FROM audit_logs WHERE action='v3.konseling.kasus.status' AND entity_id=? ORDER BY id DESC LIMIT 1",[$mistakenId])['after_json'],true);$assert(($cancelAudit['rekomendasi_dilepas']??null)===[$auditRec],'K2 audit pembatalan mencatat rekomendasi yang dilepas');
$openId=(int)$service->createCase($pembimbingA,['santri_id'=>$childA,'tahun_ajaran_id'=>$year,'tujuan'=>'SBX kasus berjalan '.$tag,'kerahasiaan'=>'Internal','dibuka_pada'=>date('Y-m-d\TH:i',time()-600),'idempotency_key'=>$key('k2-open')])['data']['kasus']['id'];
$ledgerBefore=(int)$repo->one('SELECT COUNT(*) n FROM v3_poin_ledger')['n'];
$linkedLater=$service->addLinks($pembimbingA,$openId,['pelanggaran_ids'=>[$thirdId],'rekomendasi_ids'=>[$auditRec],'alasan'=>'Ditautkan manual ke kasus berjalan','idempotency_key'=>$key('k2-link')]);
$assert($linkedLater['data']['rekomendasi_ids']===[$auditRec]&&(int)$repo->one('SELECT ditindaklanjuti_kasus_id FROM v3_rekomendasi WHERE id=?',[$auditRec])['ditindaklanjuti_kasus_id']===$openId,'K2 rekomendasi dapat ditautkan manual ke kasus yang sudah berjalan');
$assert((int)$repo->one('SELECT COUNT(*) n FROM v3_poin_ledger')['n']===$ledgerBefore&&(int)$repo->one('SELECT COUNT(*) n FROM v3_konseling_sesi WHERE kasus_id=?',[$openId])['n']===0,'K2 penautan manual tidak mengubah ledger dan tidak membuat sesi otomatis');
$reject(fn()=>$service->addLinks($pembimbingA,$openId,['rekomendasi_ids'=>[$auditRec],'idempotency_key'=>$key('k2-relink')]),'K2 rekomendasi yang sudah tertaut tidak dapat ditautkan dua kali',409);

// K3: koreksi pelanggaran tidak memutus tindak lanjut dan tidak membuka tautan ganda pada kasus yang sama.
$thirdVersion=(int)$pService->show($pembimbingA,$thirdId)['pelanggaran']['version'];
$thirdNewId=(int)$pService->correct($pembimbingA,$thirdId,['version'=>$thirdVersion,'tempat'=>'SBX area F3 terkoreksi','alasan'=>'Koreksi tempat sesudah ditautkan','idempotency_key'=>$key('k3-correct')])['data']['pelanggaran']['id'];
$assert($thirdNewId!==$thirdId&&in_array($openId,array_column($pService->show($pembimbingA,$thirdNewId)['konseling'],'kasus_id'),true),'K3 catatan pelanggaran terkini tetap menampilkan kasus yang menindaklanjuti revisi sebelumnya');
$reject(fn()=>$service->addLinks($pembimbingA,$openId,['pelanggaran_ids'=>[$thirdNewId],'idempotency_key'=>$key('k3-relink')]),'K3 revisi pelanggaran tidak ditautkan ulang ke kasus yang sama',409);
$openLinks=$service->show($pembimbingA,$openId)['tautan'];$assert(count($openLinks)===1&&$openLinks[0]['pelanggaran_digantikan_oleh_id']===$thirdNewId,'K3 detail kasus menandai pelanggaran tertaut yang sudah direvisi tanpa menggandakannya');

// K5: formulir web yang mengirim field kosong tidak menghapus rencana, dan sesi batal tidak mempunyai realisasi.
$webShape=static fn(string $status,array $extra):array=>$extra+['version'=>1,'status'=>$status,'realisasi'=>date('Y-m-d\TH:i'),'jadwal'=>date('Y-m-d\TH:i',time()+3600),'alasan'=>'','ringkasan_internal'=>'','hasil'=>'','tindak_lanjut'=>'','idempotency_key'=>$key('k5-web')];
$plannedId=(int)$service->createSession($pembimbingA,$openId,['jadwal'=>date('Y-m-d\TH:i',time()+3600),'tindak_lanjut'=>'SBX rencana tersimpan '.$tag,'jadwal_berikut'=>date('Y-m-d\TH:i',time()+86400),'idempotency_key'=>$key('k5-plan')])['data']['sesi']['id'];
$service->transitionSession($pembimbingA,$plannedId,$webShape('Selesai',['ringkasan_internal'=>'SBX ringkasan web '.$tag,'hasil'=>'SBX hasil web '.$tag]));
$plannedRow=$repo->session($plannedId);$assert($plannedRow['status']==='Selesai'&&$plannedRow['tindak_lanjut']==='SBX rencana tersimpan '.$tag&&$plannedRow['jadwal_berikut']!==null,'K5 penyelesaian lewat formulir web mempertahankan rencana tindak lanjut tersimpan');
$toCancel=(int)$service->createSession($pembimbingA,$openId,['jadwal'=>date('Y-m-d\TH:i',time()+7200),'tindak_lanjut'=>'SBX rencana batal '.$tag,'idempotency_key'=>$key('k5-cancel-plan')])['data']['sesi']['id'];
$service->transitionSession($pembimbingA,$toCancel,$webShape('Dibatalkan',['alasan'=>'Santri sedang sakit']));
$cancelRow=$repo->session($toCancel);$assert($cancelRow['status']==='Dibatalkan'&&$cancelRow['realisasi']===null&&$cancelRow['alasan_pembatalan']==='Santri sedang sakit'&&$cancelRow['tindak_lanjut']==='SBX rencana batal '.$tag,'K5 sesi batal lewat formulir web tanpa realisasi dan rencananya tetap utuh');

// K6: koreksi tidak menghasilkan sesi yang melanggar arti statusnya.
$revisionsBefore=(int)$repo->one('SELECT COUNT(*) n FROM v3_konseling_sesi WHERE revisi_dari_id=?',[$plannedId])['n'];
$reject(fn()=>$service->correctSession($pembimbingA,$plannedId,['version'=>2,'alasan'=>'Mengosongkan isi sesi selesai','ringkasan_internal'=>'','hasil'=>'','idempotency_key'=>$key('k6-blank')]),'K6 koreksi tidak dapat mengosongkan ringkasan dan hasil sesi selesai',422);
$assert((int)$repo->one('SELECT COUNT(*) n FROM v3_konseling_sesi WHERE revisi_dari_id=?',[$plannedId])['n']===$revisionsBefore&&(int)$repo->session($plannedId)['version']===2,'K6 koreksi yang ditolak tidak menulis revisi atau menaikkan versi');
$scheduledId=(int)$service->createSession($pembimbingA,$openId,['jadwal'=>date('Y-m-d\TH:i',time()+10800),'idempotency_key'=>$key('k6-scheduled')])['data']['sesi']['id'];
$scheduledRevision=$service->correctSession($pembimbingA,$scheduledId,['version'=>1,'alasan'=>'Koreksi jadwal sesi terjadwal','realisasi'=>date('Y-m-d\TH:i'),'jadwal'=>date('Y-m-d\TH:i',time()+14400),'idempotency_key'=>$key('k6-realisasi')]);$crossSession=(int)$scheduledRevision['data']['sesi']['id'];
$assert($scheduledRevision['data']['sesi']['status']==='Dijadwalkan'&&$scheduledRevision['data']['sesi']['realisasi']===null,'K6 revisi sesi terjadwal tidak memperoleh waktu realisasi');

// K7: setiap perubahan status yang sah menghasilkan notifikasi generiknya sendiri.
$twiceId=(int)$service->createCase($pembimbingA,['santri_id'=>$childA,'tahun_ajaran_id'=>$year,'tujuan'=>'SBX dua status '.$tag,'kerahasiaan'=>'Internal','dibuka_pada'=>date('Y-m-d\TH:i',time()-300),'idempotency_key'=>$key('k7-case')])['data']['kasus']['id'];
$service->transitionCase($pembimbingA,$twiceId,['version'=>1,'status'=>'Dalam Pendampingan','idempotency_key'=>$key('k7-progress')]);
$k7Session=(int)$service->createSession($pembimbingA,$twiceId,['jadwal'=>date('Y-m-d\TH:i',time()-120),'idempotency_key'=>$key('k7-session')])['data']['sesi']['id'];
$service->transitionSession($pembimbingA,$k7Session,['version'=>1,'status'=>'Selesai','ringkasan_internal'=>'SBX ringkasan K7','hasil'=>'SBX hasil K7','idempotency_key'=>$key('k7-done')]);
$service->transitionCase($pembimbingA,$twiceId,['version'=>(int)$repo->case($twiceId)['version'],'status'=>'Selesai','ringkasan_penutupan'=>'SBX tutup K7','idempotency_key'=>$key('k7-close')]);
$outboxFor=static fn(string $event,int $source):int=>(int)$repo->one("SELECT COUNT(*) n FROM notifikasi_outbox WHERE event_type=? AND kanal='InApp' AND penerima_user_id=? AND event_key LIKE ?",[$event,(int)$murobiA['id'],'v3:konseling:'.$source.':'.$event.'%'])['n'];
$assert($outboxFor('v3_konseling_status',$twiceId)===2&&(int)$repo->one("SELECT COUNT(*) n FROM audit_logs WHERE action='v3.konseling.kasus.status' AND entity_id=?",[$twiceId])['n']===2,'K7 dua perubahan status kasus menghasilkan dua notifikasi murobi');
$k7Base=(int)$service->createSession($pembimbingA,$openId,['jadwal'=>date('Y-m-d\TH:i',time()+18000),'idempotency_key'=>$key('k7-base')])['data']['sesi']['id'];
$k7Moved=(int)$service->transitionSession($pembimbingA,$k7Base,['version'=>1,'status'=>'Dijadwalkan Ulang','jadwal'=>date('Y-m-d\TH:i',time()+21600),'alasan'=>'Penyesuaian jadwal K7','idempotency_key'=>$key('k7-move')])['data']['sesi']['id'];
$service->transitionSession($pembimbingA,$k7Moved,['version'=>1,'status'=>'Tidak Hadir','idempotency_key'=>$key('k7-absent')]);
$assert($outboxFor('v3_konseling_sesi_status',$k7Moved)===2,'K7 jadwal ulang lalu tidak hadir pada sesi yang sama menghasilkan dua notifikasi');

// K9: satu pembukaan halaman oleh admin (detail + timeline) menghasilkan satu audit akses.
$accessCount=static fn():int=>(int)$repo->one("SELECT COUNT(*) n FROM audit_logs WHERE action='v3.konseling.kasus.dilihat.admin' AND entity_id=?",[$openId])['n'];
$accessBefore=$accessCount();$adminOpen=$service->show($admin,$openId);$service->timeline($admin,$openId,$adminOpen);$assert($accessCount()===$accessBefore+1,'K9 detail dan timeline dalam satu halaman admin hanya diaudit sekali');
$service->timeline($admin,$openId);$assert($accessCount()===$accessBefore+2,'K9 timeline yang dibuka tersendiri oleh admin tetap diaudit');

// Mutasi lintas cakupan: seluruh jalur tulis ditolak 403 dan tidak menulis apa pun.
$footprint=static fn():array=>[(int)$repo->one('SELECT COUNT(*) n FROM v3_konseling_sesi')['n'],(int)$repo->one('SELECT COUNT(*) n FROM v3_konseling_tautan')['n'],(int)$repo->case($openId)['version'],(int)$repo->session($crossSession)['version'],(int)$repo->one('SELECT COUNT(*) n FROM v3_murobi_catatan')['n']];
$footprintBefore=$footprint();$cv=(int)$repo->case($openId)['version'];$sv=(int)$repo->session($crossSession)['version'];
foreach(['pembimbing lain'=>$pembimbingB,'orang tua'=>$ortuA,'murobi lain'=>$murobiB] as $name=>$actor){
    foreach([
        'koreksi kasus'=>fn()=>$service->correctCase($actor,$openId,['version'=>$cv,'tujuan'=>'Lintas cakupan','alasan'=>'Uji lintas cakupan','idempotency_key'=>$key('x-cc')]),
        'status kasus'=>fn()=>$service->transitionCase($actor,$openId,['version'=>$cv,'status'=>'Dibatalkan','alasan'=>'Uji lintas cakupan','idempotency_key'=>$key('x-sc')]),
        'tautan'=>fn()=>$service->addLinks($actor,$openId,['pelanggaran_ids'=>[$secondId],'idempotency_key'=>$key('x-tl')]),
        'sesi baru'=>fn()=>$service->createSession($actor,$openId,['jadwal'=>date('Y-m-d\TH:i',time()+3600),'idempotency_key'=>$key('x-ns')]),
        'status sesi'=>fn()=>$service->transitionSession($actor,$crossSession,['version'=>$sv,'status'=>'Dibatalkan','alasan'=>'Uji lintas cakupan','idempotency_key'=>$key('x-ss')]),
        'koreksi sesi'=>fn()=>$service->correctSession($actor,$crossSession,['version'=>$sv,'alasan'=>'Uji lintas cakupan','idempotency_key'=>$key('x-ks')]),
        'catatan murobi kasus'=>fn()=>$service->acknowledge($actor,'kasus',$openId,['idempotency_key'=>$key('x-ak')]),
        'catatan murobi sesi'=>fn()=>$service->acknowledge($actor,'sesi',$crossSession,['idempotency_key'=>$key('x-as')]),
        'timeline'=>fn()=>$service->timeline($actor,$openId),
    ] as $operation=>$work)$reject($work,'Lintas cakupan: '.$name.' ditolak pada '.$operation,403);
}
foreach([
    'status kasus'=>fn()=>$service->transitionCase($admin,$openId,['version'=>$cv,'status'=>'Dibatalkan','alasan'=>'Admin bukan pengelola','idempotency_key'=>$key('a-sc')]),
    'tautan'=>fn()=>$service->addLinks($admin,$openId,['pelanggaran_ids'=>[$secondId],'idempotency_key'=>$key('a-tl')]),
    'sesi baru'=>fn()=>$service->createSession($admin,$openId,['jadwal'=>date('Y-m-d\TH:i',time()+3600),'idempotency_key'=>$key('a-ns')]),
    'status sesi'=>fn()=>$service->transitionSession($admin,$crossSession,['version'=>$sv,'status'=>'Dibatalkan','alasan'=>'Admin bukan pengelola','idempotency_key'=>$key('a-ss')]),
] as $operation=>$work)$reject($work,'Admin murni hanya mengoreksi: ditolak pada '.$operation,403);
$assert($footprint()===$footprintBefore,'Seluruh percobaan lintas cakupan tidak menulis sesi, tautan, versi, atau catatan murobi');

// ===== Keputusan Human Developer 11 September 2026 =====
// Kerahasiaan: kasus Rahasia tidak pernah diberitahukan kepada murobi.
$assert((int)$repo->one("SELECT COUNT(*) n FROM notifikasi_outbox WHERE penerima_user_id=? AND event_type IN ('v3_konseling_dibuka','v3_konseling_status') AND event_key LIKE ?",[(int)$murobiA['id'],'v3:konseling:'.$mistakenId.':%'])['n']===0,'Kasus Rahasia tidak menghasilkan notifikasi murobi');
// Kerahasiaan: kasus Rahasia hanya untuk pembimbing pemilik; pembimbing lain dalam cakupan disimulasikan dengan memindahkan kepemilikan sementara.
$secretId=(int)$service->createCase($pembimbingA,['santri_id'=>$childA,'tahun_ajaran_id'=>$year,'tujuan'=>'SBX kasus rahasia pemilik '.$tag,'kerahasiaan'=>'Rahasia','dibuka_pada'=>date('Y-m-d\TH:i',time()-300),'pelanggaran_ids'=>[$thirdNewId],'idempotency_key'=>$key('hd-secret')])['data']['kasus']['id'];
$ownerPengurus=(int)$repo->case($secretId)['pembimbing_id'];$otherPengurus=(int)$repo->one("SELECT pengurus_id FROM users WHERE username='sbx_pengurus_b'")['pengurus_id'];
$repo->execute('UPDATE v3_konseling_kasus SET pembimbing_id=? WHERE id=?',[$otherPengurus,$secretId]);
try{
    $secretVersion=(int)$repo->case($secretId)['version'];
    $reject(fn()=>$service->show($pembimbingA,$secretId),'Pembimbing dalam cakupan yang bukan pemilik tidak dapat membuka kasus Rahasia',403);
    foreach([
        'koreksi kasus'=>fn()=>$service->correctCase($pembimbingA,$secretId,['version'=>$secretVersion,'tujuan'=>'Bukan pemilik','alasan'=>'Uji bukan pemilik','idempotency_key'=>$key('np-cc')]),
        'status kasus'=>fn()=>$service->transitionCase($pembimbingA,$secretId,['version'=>$secretVersion,'status'=>'Dibatalkan','alasan'=>'Uji bukan pemilik','idempotency_key'=>$key('np-sc')]),
        'sesi baru'=>fn()=>$service->createSession($pembimbingA,$secretId,['jadwal'=>date('Y-m-d\TH:i',time()+3600),'idempotency_key'=>$key('np-ns')]),
        'tautan'=>fn()=>$service->addLinks($pembimbingA,$secretId,['pelanggaran_ids'=>[$secondId],'idempotency_key'=>$key('np-tl')]),
    ] as $operation=>$work)$reject($work,'Pembimbing bukan pemilik ditolak pada '.$operation.' kasus Rahasia',403);
    $assert(count(array_filter($service->page($pembimbingA,[])['rows'],static fn(array $row):bool=>(int)$row['id']===$secretId))===0&&!in_array($secretId,array_column($pService->show($pembimbingA,$thirdNewId)['konseling'],'kasus_id'),true),'Kasus Rahasia milik pembimbing lain tidak muncul pada daftar maupun detail pelanggaran');
    $assert($service->show($admin,$secretId)['akses_internal'],'Admin tetap dapat membuka kasus Rahasia untuk pengawasan');
}finally{$repo->execute('UPDATE v3_konseling_kasus SET pembimbing_id=? WHERE id=?',[$ownerPengurus,$secretId]);}
$assert($service->show($pembimbingA,$secretId)['kasus']['tujuan']==='SBX kasus rahasia pemilik '.$tag,'Pembimbing pemilik membaca kasus Rahasia miliknya');
// Revisi kasus oleh pemilik: satu revisi per versi sumber, versi lama ditolak, dan database menolak revisi ganda.
$secretVersion=(int)$repo->case($secretId)['version'];
$service->correctCase($pembimbingA,$secretId,['version'=>$secretVersion,'kerahasiaan'=>'Internal','alasan'=>'Dibuka untuk murobi terkait','idempotency_key'=>$key('hd-open')]);
$assert((int)$repo->one('SELECT COUNT(*) n FROM v3_konseling_kasus_revisi WHERE kasus_id=?',[$secretId])['n']===1&&$service->show($murobiA,$secretId)['akses_internal'],'Koreksi pemilik ke Internal membuat revisi dan membuka kasus kepada murobi terkait');
$reject(fn()=>$service->correctCase($pembimbingA,$secretId,['version'=>$secretVersion,'tujuan'=>'Versi lama','alasan'=>'Koreksi versi lama','idempotency_key'=>$key('hd-stale')]),'Koreksi kasus versi lama ditolak',409);
$assert((int)$repo->one('SELECT COUNT(*) n FROM v3_konseling_kasus_revisi WHERE kasus_id=?',[$secretId])['n']===1,'Koreksi yang ditolak tidak menulis revisi kedua');
try{$repo->execute('INSERT INTO v3_konseling_kasus_revisi (kasus_id,versi_sebelum,tujuan_sebelum,tujuan_sesudah,kerahasiaan_sebelum,kerahasiaan_sesudah,alasan,kapasitas,created_by) VALUES (?,?,?,?,?,?,?,?,?)',[$secretId,$secretVersion,'x','y','Rahasia','Internal','Duplikat versi','pembimbing',(int)$pembimbingA['id']]);$assert(false,'Database menolak revisi kasus ganda dari versi yang sama');}catch(App\V3\V3Exception $exception){$assert($exception->status===409,'Database menolak revisi kasus ganda dari versi yang sama');}
// Penutupan: pembatalan kasus juga menutup sesi yang masih terjadwal.
$pendingSession=(int)$service->createSession($pembimbingA,$secretId,['jadwal'=>date('Y-m-d\TH:i',time()+7200),'idempotency_key'=>$key('hd-pending')])['data']['sesi']['id'];
$secretCancelled=$service->transitionCase($pembimbingA,$secretId,['version'=>(int)$repo->case($secretId)['version'],'status'=>'Dibatalkan','alasan'=>'Pendampingan tidak diperlukan lagi','idempotency_key'=>$key('hd-cancel')]);
$pendingRow=$repo->session($pendingSession);$assert($secretCancelled['data']['sesi_ditutup_otomatis']===[$pendingSession]&&$pendingRow['status']==='Dibatalkan'&&$pendingRow['realisasi']===null&&str_starts_with((string)$pendingRow['alasan_pembatalan'],'Ditutup otomatis: kasus dibatalkan'),'Pembatalan kasus menutup sesi terjadwal sebagai Dibatalkan beralasan sistem');

// Kegagalan outbox menggulung transaksi bisnis terkait, sama seperti kegagalan audit.
$casesBefore=(int)$repo->one('SELECT COUNT(*) n FROM v3_konseling_kasus')['n'];$openAuditBefore=(int)$repo->one("SELECT COUNT(*) n FROM audit_logs WHERE action='v3.konseling.kasus.dibuka'")['n'];
$repo->execute("CREATE TRIGGER v3_f3_outbox_failure BEFORE INSERT ON notifikasi_outbox FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic outbox failure'");
try{$reject(fn()=>$service->createCase($pembimbingA,['santri_id'=>$childA,'tahun_ajaran_id'=>$year,'tujuan'=>'SBX outbox gagal '.$tag,'kerahasiaan'=>'Internal','dibuka_pada'=>date('Y-m-d\TH:i'),'idempotency_key'=>$key('outbox-fail')]),'Kegagalan outbox menggulung pembukaan kasus',503);}finally{$repo->execute('DROP TRIGGER v3_f3_outbox_failure');}
$assert((int)$repo->one('SELECT COUNT(*) n FROM v3_konseling_kasus')['n']===$casesBefore&&(int)$repo->one("SELECT COUNT(*) n FROM audit_logs WHERE action='v3.konseling.kasus.dibuka'")['n']===$openAuditBefore,'Tidak ada kasus atau audit parsial setelah outbox gagal');
exit($fails?1:0);
