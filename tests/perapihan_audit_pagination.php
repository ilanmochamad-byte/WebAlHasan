<?php
declare(strict_types=1);
if (getenv('PERAPIHAN_AUDIT_DB') !== '1') exit(77);
require_once dirname(__DIR__) . '/app/bootstrap.php';
if (!str_ends_with((string) app_config('database.database'), '_test')) exit(2);
$db=app_db();$master=master_data_service();$repo=new App\MasterData\MasterDataRepository($db);$fail=0;$count=0;$ids=[];$jars=[];
$manifest=getenv('AUDIT_PAGE_MANIFEST') ?: sys_get_temp_dir().'/audit-pagination-fixture.json';
$cleanup=static function(array $ids)use($db):void{
 if(!empty($ids['kamar']))$db->query("DELETE FROM audit_logs WHERE entity_type='kamar' AND entity_id IN (".implode(',',array_map('intval',$ids['kamar'])).')');
 foreach(['plotting_kamar','santri_wali','pertemuan_pengajian','pembimbing_assignments','murobi_assignments','santri','wali','kamar','kelas','tahun_ajaran'] as $table){
  if(!empty($ids[$table]))$db->query('DELETE FROM '.$table.' WHERE id IN ('.implode(',',array_map('intval',$ids[$table])).')');
 }
};
if (($argv[1]??'')==='cleanup') {
 $saved=json_decode(file_get_contents($manifest),true,512,JSON_THROW_ON_ERROR);
 if($saved['database']!==app_config('database.database') || $saved['test']!=='perapihan_audit_pagination')exit(2);
 $cleanup($saved['ids']);unlink($manifest);echo "Fixture pagination milik tes dibersihkan.\n";exit;
}
if(is_file($manifest)){fwrite(STDERR,"Manifest fixture sudah ada; bersihkan milik tes sebelumnya dahulu.\n");exit(2);}
$check=static function(bool $ok,string $label)use(&$fail,&$count):void{$count++;$fail+=!$ok;echo($ok?'[lulus] ':'[gagal] ').$label.PHP_EOL;};
$insert=static function(string $table,string $columns,string $marks,array $params)use($db,&$ids):int{
 $stmt=$db->prepare("INSERT INTO $table ($columns) VALUES ($marks)");if(!$stmt)throw new RuntimeException($db->error);
 $types=implode('',array_map(static fn($v)=>is_int($v)?'i':'s',$params));$stmt->bind_param($types,...$params);
 if(!$stmt->execute())throw new RuntimeException($stmt->error);$id=(int)$db->insert_id;$ids[$table][]=$id;return $id;
};
$request=static function(string $path,string $jar,?array $post=null):array{
 $c=curl_init('http://127.0.0.1:8940'.$path);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$jar,CURLOPT_COOKIEJAR=>$jar,CURLOPT_TIMEOUT=>30]);
 if($post!==null)curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($post)]);
 $body=(string)curl_exec($c);return ['status'=>curl_getinfo($c,CURLINFO_RESPONSE_CODE),'body'=>$body];
};
$tag='PG'.strtoupper(bin2hex(random_bytes(4)));$keep=false;
try {
 $adminId=(int)$db->query("SELECT id FROM users WHERE username='sbx_admin'")->fetch_assoc()['id'];
 $teacher=auth_repository()->findActiveById((int)$db->query("SELECT id FROM users WHERE username='sbx_guru_biasa'")->fetch_assoc()['id']);
 $pengurus=(int)$db->query("SELECT pengurus_id FROM users WHERE username='sbx_pengurus_a'")->fetch_assoc()['pengurus_id'];
 $year=(int)$db->query("SELECT id FROM tahun_ajaran WHERE status='Aktif' AND archived_at IS NULL LIMIT 1")->fetch_assoc()['id'];
 $f=json_decode(file_get_contents((string)getenv('AUDIT_FIXTURE_MANIFEST')),true,512,JSON_THROW_ON_ERROR);
 if($f['database']!==app_config('database.database'))throw new RuntimeException('Manifest dasar berbeda database.');
 $db->begin_transaction();
 for($i=0;$i<45;$i++){
  $class=$insert('kelas','nama_kelas,jenjang,is_active','?,?,1',[$tag.' '.sprintf('%03d',$i),'Audit']);
  $insert('tahun_ajaran','tahun,semester,status',"?,'Ganjil','Non-Aktif'",[(1800+$i).'/'.(1801+$i)]);
  $room=$insert('kamar','nama_kamar,kapasitas','?,60',[$tag.' '.sprintf('%03d',$i)]);
  $insert('murobi_assignments','guru_id,tahun_ajaran_id,target_type,kelas_id,tanggal_mulai,is_active,created_by',"?,?,'Kelas',?,'2026-08-30',0,?",[$teacher['guru_id'],$year,$class,$adminId]);
  $insert('pembimbing_assignments','pengurus_id,tahun_ajaran_id,target_type,kelas_id,tanggal_mulai,is_active,created_by',"?,?,'Kelas',?,'2026-08-30',0,?",[$pengurus,$year,$class,$adminId]);
 }
 for($i=0;$i<101;$i++)for($j=0;$j<2;$j++)$insert('wali','nama,is_active','?,1',[$tag.' Duplikat '.sprintf('%03d',$i)]);
 $santriBase=['nis'=>'','nama_santri'=>'','jenis_kelamin'=>'L','tempat_lahir'=>'','tgl_lahir'=>'2010-01-01','alamat'=>'','desa'=>'','kecamatan'=>'','kab_kota'=>'','provinsi'=>'','nama_ayah'=>'Lama','no_hp_ayah'=>'','nama_ibu'=>'','no_hp_ibu'=>'','asal_sekolah'=>'','sekolah_saat_ini'=>'Audit','foto'=>''];
 for($i=0;$i<90;$i++){
  $id=$repo->santriCreate(array_replace($santriBase,['nis'=>$tag.$i,'nama_santri'=>$tag.' Santri '.sprintf('%03d',$i)]));$ids['santri'][]=$id;
  if($i>=45)$insert('santri_wali','santri_id,wali_id,hubungan,is_primary,created_by',"?,?,'Ayah',1,?",[$id,$ids['wali'][0],$adminId]);
  else $insert('plotting_kamar','id_santri,id_kamar,id_tahun','?,?,?',[$id,$ids['kamar'][0],$year]);
 }
 for($i=0;$i<105;$i++)$insert('pertemuan_pengajian','jadwal_id,tanggal_pertemuan,status,created_by,catatan',"?,?,'Draf',?,?",[$f['schedules'][0],(new DateTimeImmutable('1800-01-01'))->modify("+$i days")->format('Y-m-d'),$adminId,$tag]);
 $foreign=$insert('pertemuan_pengajian','jadwal_id,tanggal_pertemuan,status,created_by,catatan',"?,'1800-01-01','Draf',?,?",[$f['schedules'][1],$adminId,$tag]);
 $db->commit();
 $cases=[
 'kelas'=>fn($p)=>$master->classesPage($tag,$p),
 'tahun'=>fn($p)=>$master->yearsPage('18',$p),
 'murobi'=>fn($p)=>$master->murobiPage($tag,$p),
 'pembimbing'=>fn($p)=>pembimbing_service()->page($tag,$p),
 'kamar'=>fn($p)=>$master->roomsPage($tag,$p,$year),
 'penghuni'=>fn($p)=>$master->roomOccupantsPage($ids['kamar'][0],$year,$tag,$p),
 'relasi_belum_lengkap'=>fn($p)=>$master->reconciliationPage('relasi_belum_lengkap',$tag,$p),
 'konflik_kolom_lama'=>fn($p)=>$master->reconciliationPage('konflik_kolom_lama',$tag,$p),
 ];
 foreach($cases as $label=>$fetch){
  $one=$fetch(1);$two=$fetch(2);$last=$fetch(999999);$zero=$fetch(-4);
  $check($one['total']===45 && count($one['rows'])===20 && count($two['rows'])===20 && count($last['rows'])===5 && $last['page']===3,"$label 45 data: 20/20/5 dan page besar dibatasi");
  $all=[...array_column($one['rows'],'id'),...array_column($two['rows'],'id'),...array_column($last['rows'],'id')];
  $check(count(array_unique($all))===45 && $zero['page']===1 && $zero['rows']===$one['rows'],"$label urutan stabil, tanpa duplikat/hilang, page negatif kembali 1");
 }
 $dups=$master->reconciliationPage('duplikat',$tag,6);
 $check($dups['total']===101 && count($dups['rows'])===1 && count($dups['rows'][0]['anggota'])===2,'Duplikasi ke-101 tetap dapat dicapai lengkap, melampaui batas lama 100');
 $unrelated=$master->reconciliationPage('tanpa_relasi',$tag,11);
 $check($unrelated['total']===201 && count($unrelated['rows'])===1,'Wali tanpa relasi ke-201 tetap dapat dicapai');
 $meeting=schedule_service()->meetingPage($teacher,'1800-',6);
 $allMeetings=[];for($p=1;$p<=6;$p++)$allMeetings=[...$allMeetings,...array_column(schedule_service()->meetingPage($teacher,'1800-',$p)['rows'],'id')];
 $check($meeting['total']===105 && count($meeting['rows'])===5 && count(array_unique($allMeetings))===105 && !in_array($foreign,array_map('intval',$allMeetings),true),'Pertemuan guru melewati 100 tanpa membocorkan guru lain');
 $check($master->classesPage("' OR 1=1 --",1)['total']===0,'Pencarian SQL palsu diperlakukan sebagai teks');
 foreach(['sbx_admin','sbx_guru_biasa'] as $user){
  $jar=tempnam(sys_get_temp_dir(),'page-audit-');$jars[$user]=$jar;$login=$request('/portal/',$jar);preg_match('/name="_csrf" value="([^"]+)"/',$login['body'],$m);
  $r=$request('/admin/cek_login.php',$jar,['_csrf'=>$m[1]??'','username'=>$user,'password'=>'Sandbox#123']);$check($r['status']===302,'Login pagination '.$user);
 }
 foreach(['admin_kelas.php','admin_tahun.php','admin_murobi.php','admin_pembimbing.php','admin_kamar.php'] as $file){
  $query=$file==='admin_tahun.php'?'18':$tag;$r=$request('/admin/'.$file.'?'.http_build_query(['q'=>$query,'page'=>2]),$jars['sbx_admin']);
  $check($r['status']===200 && str_contains($r['body'],'Halaman 2 dari 3') && str_contains($r['body'],'q='.$query.'&amp;page=3'),"HTTP $file pencarian dan halaman terpelihara");
  $check($request('/admin/'.$file.'?page=2&q='.$tag,$jars['sbx_guru_biasa'])['status']===403,"HTTP $file tetap admin-only");
 }
 $r=$request('/admin/admin_kelas.php?q='.$tag.'&page=1',$jars['sbx_admin']);
 $check(str_contains($r['body'],'value="'.$ids['kelas'][44].'"'),'Pilihan penempatan kelas tetap mencakup kelas di halaman 3');
 foreach(['duplikat'=>6,'tanpa_relasi'=>11,'relasi_belum_lengkap'=>3,'konflik_kolom_lama'=>3] as $section=>$page){
  $r=$request('/admin/admin_wali_rekonsiliasi.php?'.http_build_query(['bagian'=>$section,'q'=>$tag,'page'=>$page]),$jars['sbx_admin']);
  $check($r['status']===200 && str_contains($r['body'],"Halaman $page dari $page") && str_contains($r['body'],'bagian='.$section),'HTTP rekonsiliasi '.$section.' mencapai akhir dan konteks terjaga');
 }
 $r=$request('/admin/admin_pengajian.php?tab=pertemuan&q=1800-&page=6',$jars['sbx_guru_biasa']);
 $check($r['status']===200 && str_contains($r['body'],'Halaman 6 dari 6') && !str_contains($r['body'],'id='.$foreign.'"'),'HTTP riwayat pertemuan guru terpaginasikan dan terisolasi');
 foreach(['/admin/admin_murobi.php?','/admin/admin_pembimbing.php?','/admin/admin_pengajian.php?tab=pertemuan&','/admin/admin_wali_rekonsiliasi.php?bagian=duplikat&','/admin/admin_wali_rekonsiliasi.php?bagian=tanpa_relasi&','/admin/admin_wali_rekonsiliasi.php?bagian=relasi_belum_lengkap&','/admin/admin_wali_rekonsiliasi.php?bagian=konflik_kolom_lama&'] as $url){
  $r=$request($url.'q=NORESULT'.$tag,$jars['sbx_admin']);
  $check($r['status']===200 && str_contains($r['body'],'Tidak ada hasil sesuai pencarian'),'Pencarian kosong tidak menyatakan seluruh data bersih: '.$url);
 }
 $keep=getenv('AUDIT_PAGE_KEEP')==='1' && $fail===0;
 if($keep)file_put_contents($manifest,json_encode(['test'=>'perapihan_audit_pagination','database'=>app_config('database.database'),'tag'=>$tag,'ids'=>$ids],JSON_PRETTY_PRINT));
} finally {
 $db->rollback();if(!$keep)$cleanup($ids);foreach($jars as $jar)unlink($jar);
}
echo "Total pagination: $count, gagal: $fail\n";exit($fail?1:0);
