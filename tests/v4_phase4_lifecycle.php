<?php
require __DIR__.'/v4_phase4_support.php';
$ids=json_decode(file_get_contents('/private/tmp/v4-fase4-ids.json'),true);$rid=(int)$ids['rapor'];$a=$users['v4_wali4a'];
$original=$svc->pdf($pe,$rid,1,true);$hash=hash('sha256',$original);$snap1=$q("SELECT snapshot_json FROM v4_rapor_versi WHERE rapor_id=$rid AND version=1")[0]['snapshot_json'];
$code=trim(file_get_contents('/private/tmp/v4-fase4-code.txt'));$public=new App\V4\VerifikasiPublik($db);
$ok($public->check('192.0.2.4',$code)['status']==='Diganti','K7 kode v1 setelah penggantian menunjukkan Diganti');
$reject(fn()=>$svc->pdf($a,$rid,1),403,'K7 wali tidak dapat mengunduh versi Diganti');
$payload=$make('Tarik');$reject(fn()=>$svc->act($pe,array_replace($payload,['alasan'=>''])),422,'Penarikan tanpa alasan ditolak');$r=$svc->act($pe,$payload);$version=(int)$r['current_version'];
$ok($r['status']==='Ditarik'&&$svc->act($pe,$payload)['replayed'],'K7 penarikan beralasan idempoten');$reject(fn()=>$svc->pdf($a,$rid,$version),403,'K7 wali tidak dapat mengunduh versi Ditarik');
$ok($svc->show($a,$rid)['status']==='Ditarik'&&!isset($svc->show($a,$rid)['versi'][0]['snapshot']),'K7 wali melihat riwayat status tanpa PDF/snapshot ditarik');
$k=$q("SELECT k.status FROM v4_verifikasi k JOIN v4_rapor_versi v ON v.id=k.rapor_versi_id WHERE v.rapor_id=$rid AND v.version=$version")[0];$ok($k['status']==='Ditarik','K7 kode versi yang ditarik menyimpan status Ditarik');
$r=$svc->act($pe,$make('Ganti'));$open=$make('Buka');$r=$svc->act($pe,$open);$ok($r['status']==='Perlu Perbaikan','Pembukaan kembali beralasan membatalkan versi sah dan membuka koreksi');
$sk=new App\V4\SikapService($db,audit_logger());$s=$sk->read($users['v4_pembimbing'],$plan,$sid);$r=$sk->save($users['v4_pembimbing'],array_replace($s['sikap'],['rencana_id'=>$plan,'action'=>'koreksi','alasan'=>'Perbaikan setelah pembukaan','catatan'=>'Catatan revisi setelah pembukaan']));
$reject(fn()=>$svc->act($pe,$make('Ganti')),422,'Koreksi setelah pembukaan wajib verifikasi ulang');
$r=$sk->save($users['v4_pembimbing'],array_replace($r['sikap'],['rencana_id'=>$plan,'action'=>'serahkan']));$sk->save($users['v4_murobi'],['rencana_id'=>$plan,'santri_id'=>$sid,'version'=>$r['sikap']['version'],'action'=>'diketahui'],'murobi');$verifyAll();
// New signer/place decision affects only future versions.
$p=$q("SELECT * FROM v4_rencana WHERE id=$plan")[0];$rp=new App\V4\RencanaService($db,capabilities(),audit_logger());
$change=['rencana_id'=>$plan,'version'=>$p['version'],'tempat'=>'Kota Revisi Sintetis','alasan'=>'Perubahan tempat setelah penilaian disetujui']+$ids['assets4'];
$reject(fn()=>$rp->changeSigners($admin,array_replace($change,['alasan'=>''])),422,'Perubahan penanda khusus wajib alasan');$rp->changeSigners($admin,$change);
$ok($q("SELECT snapshot_json FROM v4_rapor_versi WHERE rapor_id=$rid AND version=1")[0]['snapshot_json']===$snap1,'Snapshot lama tetap identik setelah tempat rencana berubah');
$r=$svc->act($pe,$make('Ganti'));$version=(int)$r['current_version'];$ok($r['status']==='Terbit','Versi baru terbit setelah pemeriksaan ulang');
$ok(hash('sha256',$svc->pdf($pe,$rid,1,true))===$hash&&$hash===$ids['hash_v1'],'K7 PDF lama tetap identik setelah ganti, tarik, buka, koreksi dan ganti penanda');
$history=$svc->show($pe,$rid,true)['riwayat'];$ok(count(array_filter($history,fn($h)=>$h['tindakan']!=='Terbit'&&trim($h['alasan'])===''))===0,'K7 seluruh operasi koreksi/ganti/tarik/buka mempunyai alasan dan kapasitas/pelaku/waktu');
$ok($public->check('192.0.2.4',$code)['status']==='Diganti','Kode lama v1 tetap Diganti setelah seluruh siklus');
$ok((int)$q("SELECT COUNT(*) n FROM notifikasi_outbox WHERE kanal='Push'")[0]['n']===2,'Push OFF selama seluruh penggantian tidak menambah outbox provider');
$ids['current_version']=$version;file_put_contents('/private/tmp/v4-fase4-ids.json',json_encode($ids));
