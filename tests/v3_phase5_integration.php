<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/bootstrap.php';
if(getenv('V3_RUN_TESTS')!=='1'||app_config('database.database')!=='webalhasan_v3_phase1_test')exit(77);
$r=new App\V3\KonselingRepository(app_db());$s=new App\V3\LaporanService(new App\V3\PelanggaranRepository(app_db()),capabilities());$k=v3_konseling_service();$p=v3_pelanggaran_service();$pub=v3_publikasi_service();$fail=0;
$check=static function($ok,$label)use(&$fail){echo ($ok?'[lulus] ':'[gagal] ').$label.PHP_EOL;if(!$ok)$fail++;};
$reject=static function($fn,$status,$label)use($check){try{$fn();$check(false,$label);}catch(App\V3\V3Exception $e){$check($e->status===$status,$label);}};
$u=fn($name)=>$r->one('SELECT id,wali_id FROM users WHERE username=?',[$name]);
$a=$u('sbx_pengurus_a');$b=$u('sbx_pengurus_b');$m=$u('sbx_murobi_a');$mb=$u('sbx_murobi_b');$pa=$u('sbx_ortu_a');$pb=$u('sbx_ortu_b');$admin=$u('sbx_admin');$key=fn()=>'f5-'.bin2hex(random_bytes(12));
$student=(new App\V3\PelanggaranRepository(app_db()))->studentOptions((int)$a['id'],1)[0];$sid=(int)$student['santri_id'];$year=(int)$student['tahun_ajaran_id'];
$catalog=$r->one("SELECT id FROM v3_katalog WHERE is_active=1 AND archived_at IS NULL AND tanggal_mulai<=CURDATE() AND (tanggal_selesai IS NULL OR tanggal_selesai>=CURDATE()) ORDER BY id DESC LIMIT 1");
$vi=$p->create($a,['santri_id'=>$sid,'tahun_ajaran_id'=>$year,'katalog_id'=>(int)$catalog['id'],'waktu_kejadian'=>date('Y-m-d H:i:s'),'uraian'=>'SBX F5 '.bin2hex(random_bytes(5)),'idempotency_key'=>$key()]);$vid=$vi['data']['pelanggaran']['id'];
$ci=['santri_id'=>$sid,'tahun_ajaran_id'=>$year,'kerahasiaan'=>'Internal','tujuan'=>'PRIVATE-F5-SENTINEL','pelanggaran_ids'=>[$vid],'idempotency_key'=>$key()];
$case=$k->createCase($a,$ci);$cid=$case['data']['kasus']['id'];$check($k->createCase($a,$ci)['data']['kasus']['id']===$cid,'Retry satu kasus');
foreach([1,2] as $n){$ses=$k->createSession($a,$cid,['jadwal'=>date('Y-m-d H:i:s'),'idempotency_key'=>$key()]);$sids[]=$ses['data']['sesi']['id'];$k->transitionSession($a,end($sids),['version'=>1,'status'=>'Selesai','ringkasan_internal'=>'PRIVATE-F5-SESSION-'.$n,'hasil'=>'SBX hasil '.$n,'idempotency_key'=>$key()]);}
$check(count(array_unique($sids))===2&&count($k->show($a,$cid)['sesi_aktif'])===2,'Dua sesi tersimpan berbeda');
$version=$r->case($cid)['version'];$k->transitionCase($a,$cid,['version'=>(int)$version,'status'=>'Selesai','ringkasan_penutupan'=>'SBX F5 selesai','idempotency_key'=>$key()]);
$check($k->show($a,$cid)['kasus']['status']==='Selesai','Kasus selesai tanpa menghapus sesi');
$reject(fn()=>$k->transitionCase($a,$cid,['version'=>1,'status'=>'Selesai','ringkasan_penutupan'=>'Basi','idempotency_key'=>$key()]),409,'Versi lama ditolak');
$k->acknowledge($m,'kasus',$cid,['version'=>(int)$r->case($cid)['version'],'catatan'=>'SBX F5 mengetahui','idempotency_key'=>$key()]);
$check(count($k->show($m,$cid)['catatan_murobi'])>0,'Murobi terkait mengetahui kasus yang sama');
foreach([$b,$mb,$pa] as $other)$reject(fn()=>$k->show($other,$cid),403,'Kasus lintas cakupan ditolak');
$preview=$pub->preview($a,['sumber_type'=>'kasus','sumber_id'=>$cid,'sumber_version'=>(int)$r->case($cid)['version'],'ringkasan'=>'=F5 formula <script>window.F5_XSS=1</script>','tindak_lanjut'=>'SBX khusus keluarga']);
$id=$pub->publish($a,['pratinjau_token'=>$preview['pratinjau_token'],'konfirmasi'=>true,'idempotency_key'=>$key()])['data']['publikasi_ids'][0];
foreach([$admin,$a,$m] as $actor)foreach(['pelanggaran','kasus'] as $kind){$d=$s->read($actor,['jenis'=>$kind,'santri_id'=>$sid],true);$check($d['total']>0&&!str_contains(json_encode($d),'PRIVATE-F5'),'Laporan '.$kind.' sesuai akses tanpa isi rahasia');}
foreach([$b,$mb] as $actor)$check($s->read($actor,['jenis'=>'kasus','santri_id'=>$sid])['total']===0,'Laporan lintas cakupan kosong');
$parent=$s->read($pa,['jenis'=>'publikasi','santri_id'=>$sid],true);$check($parent['total']>0&&!str_contains(json_encode($parent),'PRIVATE-F5'),'Laporan wali snapshot tanpa internal');
$check($s->read($pb,['jenis'=>'publikasi','santri_id'=>$sid])['total']===0,'Laporan wali lain kosong');
$reject(fn()=>$s->read($pa,['jenis'=>'kasus']),403,'Wali menebak jenis internal ditolak');
$reject(fn()=>$s->read($pa,['jenis'=>'publikasi','kategori'=>'x']),422,'Filter internal wali ditolak');
$reject(fn()=>$s->read($a,['santri_id'=>'1 OR 1=1']),422,'SQL injection angka ditolak');
$check($s->read($a,['kategori'=>"' OR 1=1 --"])['total']===0,'SQL injection teks terikat parameter');
foreach(['=1+1','+cmd','-1+2','@SUM(1)','  =1',"\t=1"] as $text)$check(str_starts_with(App\V3\LaporanService::csvCell($text),"'"),'Formula CSV dinetralkan');
$reject(fn()=>$s->read($a,['mulai'=>'2026-99-99']),422,'Tanggal tidak sah ditolak');
$check(count($p->options($a,['page'=>1])['katalog'])<=25,'Options mobile katalog berhalaman');
$end=$p->options($a,['page'=>1000000]);$check($end['dapat_mencatat']&&$end['santri']===[],'Halaman akhir kosong tetap mengizinkan kembali ke pilihan sebelumnya');
$reject(fn()=>$p->options($a,['page'=>'1 OR 1=1']),422,'Options halaman tidak sah ditolak');
$mu=(int)$r->one('SELECT guru_id FROM users WHERE id=?',[(int)$m['id']])['guru_id'];
$pi=(int)$r->one('SELECT pengurus_id FROM users WHERE id=?',[(int)$a['id']])['pengurus_id'];
$check($s->read($admin,['jenis'=>'kasus','santri_id'=>$sid,'pembimbing_id'=>$pi,'murobi_id'=>$mu])['total']>0,'Filter pembimbing dan murobi mengikuti penugasan aktif');
$plot=$r->one("SELECT id_kelas FROM plotting_kelas WHERE id_santri=? AND id_tahun=? AND status='Aktif' LIMIT 1",[$sid,$year]);
$room=$r->one('SELECT id_kamar FROM plotting_kamar WHERE id_santri=? AND id_tahun=? LIMIT 1',[$sid,$year]);
if($plot&&$room)$check($s->read($a,['jenis'=>'kasus','santri_id'=>$sid,'kelas_id'=>$plot['id_kelas'],'kamar_id'=>$room['id_kamar'],'status'=>'Selesai','tindak_lanjut'=>'ada'])['total']>0,'Filter kelas/kamar/status/tindak lanjut tidak menggandakan kasus');
$check($s->read($a,['santri_id'=>$sid,'mulai'=>date('Y-m-d'),'sampai'=>date('Y-m-d'),'poin_min'=>0,'poin_max'=>2147483647,'tingkat'=>'Ringan'])['total']>0,'Filter periode/tingkat/poin menghasilkan data dalam cakupan');
$reject(fn()=>$s->read($a,['page'=>0]),422,'Halaman laporan tidak sah ditolak');
// Retensi: buat draf nyata, kedaluwarsa hanya fixture itu; snapshot tidak disentuh.
$pr=$pub->preview($a,['sumber_type'=>'kasus','sumber_id'=>$cid,'sumber_version'=>(int)$r->case($cid)['version'],'ringkasan'=>'SBX purge aman','tindak_lanjut'=>'SBX retensi']);
$draft=$r->one('SELECT id FROM v3_publikasi_pratinjau WHERE token_hash=?',[hash('sha256',$pr['pratinjau_token'])]);
$r->execute('UPDATE v3_publikasi_pratinjau SET expires_at=DATE_SUB(NOW(),INTERVAL 8 DAY) WHERE id=?',[(int)$draft['id']]);
$ret=new App\V3\PratinjauRetention($r,new App\Audit\AuditLogger(app_db()));$before=(int)$r->one('SELECT COUNT(*) n FROM v3_publikasi')['n'];
$dry=$ret->run(false,500,(int)$admin['id']);$check(in_array((int)$draft['id'],$dry['ids'],true)&&$r->one('SELECT id FROM v3_publikasi_pratinjau WHERE id=?',[(int)$draft['id']])!==null,'Purge dry-run tidak menghapus draf');
app_db()->query("CREATE TRIGGER f5_retention_fail BEFORE INSERT ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='F5 test failure'");
try{$reject(fn()=>$ret->run(true,500,(int)$admin['id']),503,'Purge gagal audit menggulung batch');}finally{app_db()->query('DROP TRIGGER f5_retention_fail');}
$check($r->one('SELECT id FROM v3_publikasi_pratinjau WHERE id=?',[(int)$draft['id']])!==null,'Draf tetap ada setelah rollback');
$ret->run(true,500,(int)$admin['id']);$check($r->one('SELECT id FROM v3_publikasi_pratinjau WHERE id=?',[(int)$draft['id']])===null&&$before===(int)$r->one('SELECT COUNT(*) n FROM v3_publikasi')['n'],'Purge hanya draf, snapshot/riwayat tetap');
$reject(fn()=>$ret->run(true,501,(int)$admin['id']),422,'Purge batas batch');$reject(fn()=>$ret->run(true,10,(int)$pa['id']),403,'Purge bukan admin ditolak');
$audit=$r->all("SELECT before_json,after_json FROM audit_logs WHERE entity_type IN ('v3_konseling_kasus','v3_konseling_sesi') AND created_at>=DATE_SUB(NOW(),INTERVAL 1 MINUTE)");
$check(!str_contains(json_encode($audit),'PRIVATE-F5'),'Audit V3 baru hanya metadata/hash tanpa isi konseling');
echo 'Publikasi fixture F5 ID='.$id.PHP_EOL;exit($fail?1:0);
