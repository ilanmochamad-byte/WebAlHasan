<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/app/bootstrap.php';
if(getenv('V3_RUN_TESTS')!=='1'||app_config('database.database')!=='webalhasan_v3_phase1_test')exit(77);
$repo=new App\V3\PelanggaranRepository(app_db());$kRepo=new App\V3\KatalogRepository(app_db());$service=v3_pelanggaran_service();$catalogService=v3_katalog_service();
$fails=0;$assert=static function(bool $ok,string $label)use(&$fails){echo ($ok?'[lulus] ':'[gagal] ').$label.PHP_EOL;if(!$ok)$fails++;};
$reject=static function(callable $work,string $label,int $status=422)use($assert){try{$work();$assert(false,$label);}catch(App\V3\V3Exception $e){$assert($e->status===$status,$label.' ('.$e->status.')');}};
$user=static fn(string $name):array=>$repo->one('SELECT id FROM users WHERE username=?',[$name])??throw new RuntimeException('Fixture hilang '.$name);
$admin=$user('sbx_admin');$pembimbingA=$user('sbx_pengurus_a');$pembimbingB=$user('sbx_pengurus_b');$murobiA=$user('sbx_murobi_a');$murobiB=$user('sbx_murobi_b');$ortuA=$user('sbx_ortu_a');
$year=(int)$repo->one("SELECT id FROM tahun_ajaran WHERE status='Aktif' AND archived_at IS NULL ORDER BY id LIMIT 1")['id'];
$inactiveYear=(int)$repo->one("SELECT id FROM tahun_ajaran WHERE status<>'Aktif' AND archived_at IS NULL ORDER BY id LIMIT 1")['id'];
$childA=(int)$repo->one("SELECT sw.santri_id FROM santri_wali sw JOIN users u ON u.wali_id=sw.wali_id WHERE u.username='sbx_ortu_a' AND sw.archived_at IS NULL LIMIT 1")['santri_id'];
$childB=(int)$repo->one("SELECT sw.santri_id FROM santri_wali sw JOIN users u ON u.wali_id=sw.wali_id WHERE u.username='sbx_ortu_b' AND sw.archived_at IS NULL LIMIT 1")['santri_id'];
$childName=(string)$repo->one('SELECT nama_santri FROM santri WHERE id=?',[$childA])['nama_santri'];
$tag='SBX-F2-'.bin2hex(random_bytes(5));$period=['tanggal_mulai'=>date('Y-m-d'),'tanggal_selesai'=>'','is_active'=>1];
$categoryId=$catalogService->save('kategori',$period+['kode'=>$tag,'nama'=>'SBX Kategori Rahasia '.$tag,'uraian'=>'Fixture fiktif'],$admin);
$catalogId=$catalogService->save('katalog',$period+['kode'=>$tag,'nama'=>'SBX Jenis '.$tag,'kategori_id'=>$categoryId,'tingkat'=>'Ringan','poin_default'=>7,'uraian'=>'Katalog fixture'],$admin);
foreach($kRepo->rows('SELECT * FROM v3_ambang WHERE is_active=1 AND archived_at IS NULL') as $old){$catalogService->save('ambang',array_replace($old,['is_active'=>0,'alasan'=>'Isolasi suite Fase 2']),$admin,(int)$old['id']);}
$thresholdId=$catalogService->save('ambang',$period+['tahun_ajaran_id'=>$year,'nilai_minimum'=>7,'nilai_maksimum'=>'','label'=>'SBX rekomendasi '.$tag,'rekomendasi'=>'Tinjau pembinaan secara manual'],$admin);
$catalogService->save('ambang',$period+['tahun_ajaran_id'=>$inactiveYear,'nilai_minimum'=>0,'nilai_maksimum'=>'','label'=>'SBX tahun depan '.$tag,'rekomendasi'=>'Belum berlaku'],$admin);
$warnings=$service->configurationWarnings();$assert((bool)array_filter($warnings,static fn(string $w):bool=>str_contains($w,'tahun non-aktif')),'Ambang tahun nonaktif memberi sinyal operator yang terlihat');

$counselingBefore=(int)$repo->one('SELECT COUNT(*) n FROM v3_konseling_kasus')['n'];$placementBefore=(int)$repo->one('SELECT COUNT(*) n FROM plotting_kelas')['n'];
$baselinePoints=(int)$repo->one('SELECT COALESCE(SUM(perubahan_poin),0) total FROM v3_poin_ledger WHERE santri_id=? AND tahun_ajaran_id=? AND archived_at IS NULL',[$childA,$year])['total'];
$pdf=tempnam(sys_get_temp_dir(),'sbx-v3-');file_put_contents($pdf,"%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF\n");
$storage=new App\V3\AttachmentStorage(APP_ROOT.'/storage/private/v3');$file=$storage->stageTestFile($pdf,'sbx-bukti.pdf');@unlink($pdf);
$invalidSource=tempnam(sys_get_temp_dir(),'sbx-v3-');file_put_contents($invalidSource,"%PDF-1.4\n%%EOF\n");$invalidFile=$storage->stageTestFile($invalidSource,'sbx-invalid.pdf');@unlink($invalidSource);
$reject(fn()=>$service->create($pembimbingA,['uraian'=>'Input belum lengkap'],$invalidFile),'Validasi gagal membersihkan lampiran pending',422);
$assert(!is_file($invalidFile['pending'])&&!is_file($invalidFile['absolute']),'Tidak ada lampiran yatim saat validasi gagal');
$key='create-'.bin2hex(random_bytes(12));$description='SBX uraian rahasia '.$tag;
$createInput=['santri_id'=>$childA,'tahun_ajaran_id'=>$year,'katalog_id'=>$catalogId,'waktu_kejadian'=>date('Y-m-d\TH:i'),'tempat'=>'SBX lokasi privat','uraian'=>$description,'saksi'=>'SBX saksi rahasia','idempotency_key'=>$key];
$created=$service->create($pembimbingA,$createInput,$file);$id=(int)$created['data']['pelanggaran']['id'];
$assert($created['status']===201&&!$created['replayed'],'Pembimbing mencatat pelanggaran dalam cakupan');
$assert((int)$created['data']['pelanggaran']['poin']===7,'Snapshot poin katalog tersimpan bersama catatan');
$assert($repo->one('SELECT COUNT(*) n FROM v3_poin_ledger WHERE pelanggaran_id=?',[$id])['n']==1,'Ledger awal tersimpan tepat satu');
$assert(count($created['data']['rekomendasi_baru'])===1,'Ambang menghasilkan tepat satu rekomendasi');
$assert((int)$repo->one('SELECT COUNT(*) n FROM v3_rekomendasi WHERE santri_id=? AND tahun_ajaran_id=? AND ambang_id=?',[$childA,$year,$thresholdId])['n']===1,'Rekomendasi unik per ambang dan subjek');
$assert((int)$repo->one('SELECT COUNT(*) n FROM v3_konseling_kasus')['n']===$counselingBefore&&(int)$repo->one('SELECT COUNT(*) n FROM plotting_kelas')['n']===$placementBefore,'Rekomendasi tidak membuat konseling atau perubahan akademik otomatis');
$assert(count($repo->attachments($id))===1,'Lampiran privat dicatat dengan metadata aman');
$assert($service->page($admin,[])['total']>0&&(int)$service->show($admin,$id)['pelanggaran']['id']===$id,'Admin murni mengawasi seluruh catatan tanpa menyamar sebagai pembimbing');

$pdfRetry=tempnam(sys_get_temp_dir(),'sbx-v3-');file_put_contents($pdfRetry,"%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF\n");$retryFile=$storage->stageTestFile($pdfRetry,'sbx-bukti.pdf');@unlink($pdfRetry);
$replayed=$service->create($pembimbingA,$createInput,$retryFile);$assert($replayed['replayed']&&$replayed['status']===201,'Idempotency key sama me-replay respons tanpa dampak kedua');
$assert((int)$repo->one('SELECT COUNT(*) n FROM v3_pelanggaran WHERE created_by=? AND idempotency_key=?',[(int)$pembimbingA['id'],$key])['n']===1,'Retry hanya menghasilkan satu pelanggaran');
$reject(fn()=>$service->create($pembimbingA,array_replace($createInput,['idempotency_key'=>'other-'.bin2hex(random_bytes(8))])),'Fingerprint bisnis menolak duplikasi dengan key berbeda',409);
$reject(fn()=>$service->create($pembimbingA,array_replace($createInput,['santri_id'=>$childB,'idempotency_key'=>'cross-'.bin2hex(random_bytes(8))])),'Pembimbing tidak dapat mencatat santri di luar cakupan',403);
$reject(fn()=>$service->create($pembimbingB,array_replace($createInput,['idempotency_key'=>'cross-'.bin2hex(random_bytes(8))])),'Pengurus lain tidak dapat mencatat santri A',403);

$catalogRow=$catalogService->find('katalog',$catalogId,$admin);$catalogService->save('katalog',array_replace($catalogRow,['poin_default'=>99,'alasan'=>'Uji snapshot lama']),$admin,$catalogId);
$assert((int)$repo->violation($id)['poin_snapshot']===7,'Perubahan poin default tidak mengubah poin catatan lama');
$detail=$service->show($pembimbingA,$id);$assert($detail['total_poin']===$baselinePoints+7&&$detail['rekonsiliasi']['selisih']===0,'Akumulasi sama dengan penjumlahan ledger dan agregat terrekonsiliasi');
$dbFields=array_keys($repo->violation($id));$apiFields=array_keys($detail['pelanggaran']);
$assert(array_intersect(['created_by','updated_by','fingerprint','idempotency_key','cakupan_snapshot'],$apiFields)===[]&&array_diff($apiFields,$dbFields)!==[],'Serializer detail tidak mengembalikan baris database mentah');

$correctKey='correct-'.bin2hex(random_bytes(12));$corrected=$service->correct($pembimbingA,$id,['version'=>1,'idempotency_key'=>$correctKey,'uraian'=>$description.' dikoreksi','alasan'=>'Koreksi fakta fixture']);$revisionId=(int)$corrected['data']['pelanggaran']['id'];
$assert($revisionId!==$id&&(int)$corrected['data']['pelanggaran']['poin']===7,'Koreksi membuat revisi dan mempertahankan snapshot bila katalog sama');
$assert((int)$repo->violation($id)['poin_snapshot']===7&&(string)$repo->violation($id)['uraian']===$description,'Koreksi tidak menimpa nilai historis');
$ledger=$repo->ledgerHistory($childA,$year);$assert(array_sum(array_map(static fn(array $x):int=>(int)$x['perubahan_poin'],$ledger))===$baselinePoints+7,'Pembalik koreksi dan ledger revisi tetap terrekonsiliasi');
$assert(count(array_filter($ledger,static fn(array $x):bool=>(int)$x['pelanggaran_id']===$id&&$x['pembalik_dari_id']!==null))===1,'Koreksi membuat satu ledger pembalik');
$reject(fn()=>$service->correct($pembimbingA,$id,['version'=>1,'idempotency_key'=>'stale-'.bin2hex(random_bytes(8)),'uraian'=>'SBX konflik lama','alasan'=>'Konflik versi lama']),'Optimistic version menolak koreksi versi lama',409);

$roleAdmin=(int)$repo->one("SELECT id FROM roles WHERE slug='admin'")['id'];$repo->execute('INSERT INTO user_roles(user_id,role_id) VALUES (?,?)',[(int)$pembimbingA['id'],$roleAdmin]);
$caps=new App\Auth\Capabilities(app_db());$dual=$caps->v3Capabilities($pembimbingA);
$assert(($dual['v3.pelanggaran.kelola']['sumber']??null)===App\Auth\Capabilities::SUMBER_KEDUANYA,'T-2: admin merangkap pembimbing bersumber keduanya');
$adminCorrect=$service->correct($pembimbingA,$revisionId,['version'=>1,'idempotency_key'=>'admin-'.bin2hex(random_bytes(8)),'uraian'=>$description.' koreksi admin','poin'=>11,'alasan'=>'Koreksi admin beralasan']);$adminRevisionId=(int)$adminCorrect['data']['pelanggaran']['id'];
$auditAction=(string)$repo->one("SELECT action FROM audit_logs WHERE entity_type='v3_pelanggaran' AND entity_id=? ORDER BY id DESC LIMIT 1",[$adminRevisionId])['action'];
$assert($auditAction==='v3.pelanggaran.dikoreksi.admin'&&(int)$adminCorrect['data']['pelanggaran']['poin']===11,'Koreksi akun ganda tercatat sebagai admin beralasan');
$repo->execute('DELETE FROM user_roles WHERE user_id=? AND role_id=?',[(int)$pembimbingA['id'],$roleAdmin]);

$ack=$service->acknowledge($murobiA,$adminRevisionId,['idempotency_key'=>'ack-'.bin2hex(random_bytes(10)),'catatan'=>'SBX catatan murobi rahasia']);
$assert($ack['status']===201&&(int)$repo->one('SELECT COUNT(*) n FROM v3_murobi_catatan WHERE pelanggaran_id=?',[$adminRevisionId])['n']===1,'Murobi terkait menandai mengetahui tepat satu kali');
$reject(fn()=>$service->acknowledge($murobiB,$adminRevisionId,['idempotency_key'=>'ack-'.bin2hex(random_bytes(10))]),'Murobi lain ditolak',403);
$reject(fn()=>$service->acknowledge($ortuA,$adminRevisionId,['idempotency_key'=>'ack-'.bin2hex(random_bytes(10))]),'Orang tua ditolak dari tanda mengetahui',403);
$reject(fn()=>$service->acknowledge($pembimbingB,$adminRevisionId,['idempotency_key'=>'ack-'.bin2hex(random_bytes(10))]),'Pengurus di luar cakupan ditolak dari tanda mengetahui',403);
$assert($service->page($pembimbingA,[])['total']>0&&$service->page($murobiA,[])['total']>0,'Pembimbing dan murobi terkait membaca daftar');
$assert(count(array_filter($service->page($pembimbingB,[])['rows'],static fn(array $x):bool=>(int)$x['santri_id']===$childA))===0,'Daftar pembimbing lain tidak membocorkan santri A');
$assert(count(array_filter($service->page($murobiB,[])['rows'],static fn(array $x):bool=>(int)$x['santri_id']===$childA))===0,'Daftar murobi lain tidak membocorkan santri A');
$reject(fn()=>$service->page($ortuA,[]),'Orang tua tidak membaca data internal Fase 2',403);
$reject(fn()=>$service->show($murobiB,$adminRevisionId),'Murobi lain ditolak dari detail',403);
$reject(fn()=>$service->show($ortuA,$adminRevisionId),'Orang tua ditolak dari detail',403);
$reject(fn()=>$service->show($pembimbingB,$adminRevisionId),'Pengurus lain ditolak dari detail',403);
$attachmentId=(int)$repo->attachments($id)[0]['id'];
$reject(fn()=>$service->attachment($pembimbingB,$attachmentId),'Lampiran privat ditolak lintas cakupan',403);

$cancel=$service->cancel($pembimbingA,$adminRevisionId,['version'=>1,'idempotency_key'=>'cancel-'.bin2hex(random_bytes(9)),'alasan'=>'Pembatalan fixture sah']);
$assert($cancel['data']['pelanggaran']['status']==='Dibatalkan','Pembatalan mengubah status tanpa menghapus catatan');
$lastLedger=$repo->one('SELECT perubahan_poin,pembalik_dari_id FROM v3_poin_ledger WHERE pelanggaran_id=? ORDER BY id DESC LIMIT 1',[$adminRevisionId]);
$assert((int)$lastLedger['perubahan_poin']===-11&&$lastLedger['pembalik_dari_id']!==null,'Pembatalan membuat pembalik poin, bukan mengedit total');
$assert($cancel['data']['total_poin']===$baselinePoints&&$service->show($pembimbingA,$adminRevisionId)['rekonsiliasi']['selisih']===0,'Rekonsiliasi setelah koreksi berulang dan pembatalan berselisih nol');
$assert((int)$repo->one('SELECT COUNT(*) n FROM v3_rekomendasi WHERE santri_id=? AND tahun_ajaran_id=? AND ambang_id=?',[$childA,$year,$thresholdId])['n']===1,'Pembatalan tidak menggandakan rekomendasi');

$outbox=$repo->all("SELECT event_key,event_type,judul,isi,data_json FROM notifikasi_outbox WHERE event_type LIKE 'v3_%'");$privacy=true;
foreach($outbox as $notification){$payload=implode(' ',array_map('strval',$notification));$json=json_decode((string)$notification['data_json'],true);if(str_contains($payload,$childName)||str_contains($payload,'SBX Kategori Rahasia')||str_contains($payload,$description)||str_contains($payload,'SBX lokasi privat')||str_contains($payload,'SBX saksi rahasia')||!is_array($json)||array_diff(array_keys($json),['type'])!==[]){$privacy=false;}}
$assert($outbox!==[]&&$privacy,'Payload outbox/push hanya generik tanpa nama, kategori, uraian, poin, nomor, atau catatan rahasia');

$noPlacementNis='SBX-NP-'.bin2hex(random_bytes(4));$repo->execute("INSERT INTO santri(nis,nama_santri,jenis_kelamin,tempat_lahir,tgl_lahir,alamat,desa,kecamatan,kab_kota,provinsi,nama_ayah,nama_ibu,asal_sekolah,sekolah_saat_ini,is_active) VALUES (?,?,'L','X','2010-01-01','X','X','X','X','X','X','X','X','X',1)",[$noPlacementNis,'SBX tanpa penempatan']);$noPlacement=(int)app_db()->insert_id;
$assert(!(new App\Auth\Capabilities(app_db()))->v3AppliesToSantri($pembimbingA,'v3.pelanggaran.kelola',$noPlacement,$year),'Santri tanpa penempatan tetap di luar cakupan');
$reject(fn()=>$service->create($pembimbingA,array_replace($createInput,['santri_id'=>$noPlacement,'idempotency_key'=>'noplace-'.bin2hex(random_bytes(8))])),'Pencatatan santri tanpa penempatan ditolak',403);$repo->execute('DELETE FROM santri WHERE id=?',[$noPlacement]);

$businessBefore=(int)$repo->one('SELECT COUNT(*) n FROM v3_pelanggaran')['n'];$repo->execute("CREATE TRIGGER v3_f2_audit_failure BEFORE INSERT ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic audit failure'");
try{$reject(fn()=>$service->create($pembimbingA,array_replace($createInput,['waktu_kejadian'=>date('Y-m-d\TH:i',time()-600),'uraian'=>'SBX transaksi gagal '.$tag,'idempotency_key'=>'fail-'.bin2hex(random_bytes(8))])),'Kegagalan audit menggulung seluruh transaksi',503);}finally{$repo->execute('DROP TRIGGER v3_f2_audit_failure');}
$assert((int)$repo->one('SELECT COUNT(*) n FROM v3_pelanggaran')['n']===$businessBefore,'Tidak ada catatan parsial setelah audit gagal');
$assert((int)$repo->one('SELECT COUNT(*) n FROM v3_pelanggaran WHERE id=?',[$adminRevisionId])['n']===1,'Tidak ada hard delete catatan bisnis');

// ---------------------------------------------------------------------------
// Regresi koreksi audit Claude Code (T1-T3). Fingerprint hanya boleh menahan
// catatan yang masih berlaku, alasan pembatalan berdiri sendiri, dan
// rekomendasi mengikuti total poin terkini tanpa pernah digandakan.
// ---------------------------------------------------------------------------
$auditTag='SBX-AUD-'.bin2hex(random_bytes(5));
$auditCategory=$catalogService->save('kategori',$period+['kode'=>$auditTag,'nama'=>'SBX Kategori Audit '.$auditTag,'uraian'=>'Fixture fiktif'],$admin);
$auditCatalog=$catalogService->save('katalog',$period+['kode'=>$auditTag,'nama'=>'SBX Jenis Audit '.$auditTag,'kategori_id'=>$auditCategory,'tingkat'=>'Ringan','poin_default'=>4,'uraian'=>'Katalog fixture'],$admin);
$auditKey=static fn(string $prefix):string=>$prefix.'-'.bin2hex(random_bytes(10));
$auditBase=['santri_id'=>$childA,'tahun_ajaran_id'=>$year,'katalog_id'=>$auditCatalog,'waktu_kejadian'=>date('Y-m-d\TH:i',time()-3600),'tempat'=>'SBX aula '.$auditTag,'uraian'=>'SBX uraian audit '.$auditTag,'idempotency_key'=>$auditKey('aud-create')];

$auditId=(int)$service->create($pembimbingA,$auditBase)['data']['pelanggaran']['id'];
$auditRevision=(int)$service->correct($pembimbingA,$auditId,['version'=>1,'idempotency_key'=>$auditKey('aud-fix'),'tempat'=>'SBX masjid '.$auditTag,'alasan'=>'Koreksi tempat fixture'])['data']['pelanggaran']['id'];
$assert($repo->violation($auditId)['fingerprint']===null,'Catatan yang digantikan revisi melepas fingerprint');
$revert=$service->correct($pembimbingA,$auditRevision,['version'=>1,'idempotency_key'=>$auditKey('aud-back'),'tempat'=>'SBX aula '.$auditTag,'alasan'=>'Kembali ke tempat semula']);
$revertId=(int)$revert['data']['pelanggaran']['id'];
$assert($revertId!==$auditRevision,'Koreksi boleh mengembalikan isi ke nilai catatan yang sudah digantikan');

$revertRow=$repo->violation($revertId);
$cancelAudit=$service->cancel($pembimbingA,$revertId,['version'=>(int)$revertRow['version'],'idempotency_key'=>$auditKey('aud-stop'),'alasan'=>'Pembatalan fixture beralasan']);
$cancelled=$repo->violation($revertId);
$assert((string)$cancelled['alasan_revisi']==='Kembali ke tempat semula','Pembatalan tidak menimpa alasan koreksi');
$assert((string)$cancelled['alasan_pembatalan']==='Pembatalan fixture beralasan','Alasan pembatalan tersimpan pada kolomnya sendiri');
$assert($cancelled['fingerprint']===null,'Catatan yang dibatalkan melepas fingerprint');
$cancelDetail=$service->show($pembimbingA,$revertId)['pelanggaran'];
$assert($cancelAudit['data']['pelanggaran']['status']==='Dibatalkan'&&$cancelDetail['alasan_pembatalan']==='Pembatalan fixture beralasan'&&$cancelDetail['alasan_revisi']==='Kembali ke tempat semula','Serializer detail memisahkan alasan koreksi dan pembatalan');
$reReport=$service->create($pembimbingA,array_replace($auditBase,['tempat'=>'SBX aula '.$auditTag,'idempotency_key'=>$auditKey('aud-again')]));
$assert((int)$reReport['data']['pelanggaran']['id']!==$revertId,'Kejadian identik boleh dicatat ulang setelah pembatalan');
$reReportId=(int)$reReport['data']['pelanggaran']['id'];

// Ambang aktif milik blok sebelumnya tidak berbatas atas; nonaktifkan dahulu
// supaya rentang sempit di bawah ini tidak dianggap bertumpang tindih.
foreach($kRepo->rows('SELECT * FROM v3_ambang WHERE is_active=1 AND archived_at IS NULL') as $old){$catalogService->save('ambang',array_replace($old,['is_active'=>0,'alasan'=>'Isolasi blok regresi audit']),$admin,(int)$old['id']);}
$totalNow=(int)$repo->aggregate($childA,$year)['total_poin'];
$auditThreshold=$catalogService->save('ambang',$period+['tahun_ajaran_id'=>$year,'nilai_minimum'=>$totalNow+4,'nilai_maksimum'=>$totalNow+4,'label'=>'SBX ambang audit '.$auditTag,'rekomendasi'=>'Tinjau pembinaan secara manual'],$admin);
$trigger=$service->create($pembimbingA,array_replace($auditBase,['uraian'=>'SBX pemicu ambang '.$auditTag,'waktu_kejadian'=>date('Y-m-d\TH:i',time()-7200),'idempotency_key'=>$auditKey('aud-trigger')]));
$triggerId=(int)$trigger['data']['pelanggaran']['id'];
$bandRows=static fn(array $rows):array=>array_values(array_filter($rows,static fn(array $row):bool=>(int)$row['ambang_id']===$auditThreshold));
$assert(count($trigger['data']['rekomendasi_baru'])===1&&($bandRows($service->show($pembimbingA,$triggerId)['rekomendasi'])[0]['berlaku']??false),'Ambang tercapai memberi satu rekomendasi yang berlaku');
$triggerRow=$repo->violation($triggerId);
$stale=$service->cancel($pembimbingA,$triggerId,['version'=>(int)$triggerRow['version'],'idempotency_key'=>$auditKey('aud-stale'),'alasan'=>'Pembatalan menguji rekomendasi basi']);
$assert($stale['data']['rekomendasi_disesuaikan']['dinonaktifkan']!==[],'Pembatalan menandai rekomendasi yang totalnya sudah tidak berlaku');
$assert(($bandRows($service->show($pembimbingA,$triggerId)['rekomendasi'])[0]['berlaku']??true)===false,'Rekomendasi basi terbaca sebagai tidak berlaku');
$revive=$service->create($pembimbingA,array_replace($auditBase,['uraian'=>'SBX pemulih ambang '.$auditTag,'waktu_kejadian'=>date('Y-m-d\TH:i',time()-10800),'idempotency_key'=>$auditKey('aud-revive')]));
$reviveId=(int)$revive['data']['pelanggaran']['id'];
$assert($revive['data']['rekomendasi_disesuaikan']['dipulihkan']!==[]&&$revive['data']['rekomendasi_baru']===[],'Total kembali ke rentang memulihkan rekomendasi tanpa membuat baris kedua');
$assert((int)$repo->one('SELECT COUNT(*) n FROM v3_rekomendasi WHERE santri_id=? AND tahun_ajaran_id=? AND ambang_id=?',[$childA,$year,$auditThreshold])['n']===1,'Rekomendasi tetap tepat satu per ambang setelah batal dan pulih');
$assert(($bandRows($service->show($pembimbingA,$reviveId)['rekomendasi'])[0]['berlaku']??false),'Rekomendasi yang dipulihkan berlaku kembali');
$assert((int)$repo->one('SELECT COUNT(*) n FROM v3_pelanggaran WHERE id IN (?,?,?)',[$auditId,$revertId,$reReportId])['n']===3,'Seluruh catatan koreksi audit tetap tersimpan');

exit($fails?1:0);
