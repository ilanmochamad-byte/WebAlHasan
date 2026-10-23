<?php
declare(strict_types=1);
// Probe independen auditor. DB Tahap 7 baru; server router pada BASE_URL.
$name=getenv('DB_NAME')?:'test_redesain_t7_test';
if(!preg_match('/^test_.*_test$/',$name))exit(77);
putenv('DB_NAME='.$name);
require dirname(__DIR__).'/app/bootstrap.php';
use App\V4\{RencanaService,V4Exception};
use App\Auth\AuthRepository;
$base=getenv('BASE_URL')?:'http://127.0.0.1:8977';
if(!preg_match('#^http://127\.0\.0\.1:\d+$#',$base))exit(77);
$db=app_db();$pass=0;$fail=0;
$check=static function(bool $ok,string $label)use(&$pass,&$fail):void{$ok?$pass++:$fail++;echo ($ok?'[lulus] ':'[gagal] ').$label.PHP_EOL;};
$rows=static fn(string $s):array=>$db->query($s)->fetch_all(MYSQLI_ASSOC);
$one=static fn(string $s):array=>$rows($s)[0]??[];
$reject=static function(callable $f,int $code,string $s)use($check):void{try{$f();$check(false,$s);}catch(V4Exception $e){$check($e->status===$code,$s.' → '.$e->status);}};
final class T7AuditClient {
    private string $jar;
    public function __construct(private string $base,string $user){$this->jar=tempnam(sys_get_temp_dir(),'t7audit-');$this->req('/admin/cek_login.php',['_csrf'=>$this->csrf(),'username'=>$user,'password'=>'Sandbox#123']);}
    public function __destruct(){@unlink($this->jar);}
    public function csrf(string $path='/portal/index.php'):string{preg_match('/name="_csrf" value="([^"]+)"/',$this->req($path)['body'],$m);return $m[1]??'';}
    public function req(string $path,?array $data=null,?string $token=null):array{
        $c=curl_init($this->base.$path);$headers=[];
        curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_COOKIEFILE=>$this->jar,CURLOPT_COOKIEJAR=>$this->jar,CURLOPT_TIMEOUT=>30]);
        if($token!==null){$headers=['Content-Type: application/json','Authorization: Bearer '.$token];if($data!==null)curl_setopt($c,CURLOPT_POSTFIELDS,json_encode($data));}
        elseif($data!==null)curl_setopt($c,CURLOPT_POSTFIELDS,http_build_query($data));
        if($headers)curl_setopt($c,CURLOPT_HTTPHEADER,$headers);
        $raw=(string)curl_exec($c);$n=curl_getinfo($c,CURLINFO_HEADER_SIZE);$status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);
        $body=substr($raw,$n);return ['status'=>$status,'body'=>$body,'json'=>json_decode($body,true)];
    }
    public function apiToken(string $user):string{return (string)($this->req('/api/v1/auth/login',['username'=>$user,'password'=>'Sandbox#123','device_name'=>'Audit lokal Tahap 7'],'')['json']['data']['token']??'');}
}
$ids=json_decode(file_get_contents(APP_ROOT.'/storage/private/v4/redesain_t7_ids.json'),true);
$admin=new T7AuditClient($base,'sbx_admin');$pend=new T7AuditClient($base,'sbx_pendidikan');$guru=new T7AuditClient($base,'sbx_guru_mapel');$wali=new T7AuditClient($base,'sbx_t7_ortu');
$auth=new AuthRepository($db);
$user=static fn(string $n):array=>$auth->findActiveById((int)$one("SELECT id FROM users WHERE username='$n'")['id']);
$svc=new RencanaService($db,capabilities(),audit_logger());$puser=$user('sbx_pendidikan');
$pid=(int)$ids['rencana'];$tahun=(int)$ids['tahun'];$kelas=(int)$ids['kelas'];
$plan=$svc->read($puser,$tahun,$kelas);
$payload=['tahun_ajaran_id'=>$tahun,'kelas_id'=>$kelas,'mapel'=>$plan['mapel']];
foreach(['pendidikan_penanda_id','pendidikan_aset_id','pimpinan_penanda_id','pimpinan_aset_id','tempat'] as $k)$payload[$k]=$plan['rencana'][$k];
$frozen=$rows('SELECT snapshot_json,pdf_sha256 FROM v4_rapor_versi ORDER BY id');

// AU: perubahan yang diterima/ditolak memakai endpoint REST yang sama dengan mobile.
$token=$admin->apiToken('sbx_pendidikan');$check($token!=='','REST login Pendidikan tersedia (token tidak dicetak)');
$db->query("INSERT INTO mata_pelajaran (kode,nama,kategori) VALUES ('AUD-T7-A','Audit tanpa nilai A','Diniyah'),('AUD-T7-B','Audit tanpa nilai B','Diniyah')");
$a=(int)$one("SELECT id FROM mata_pelajaran WHERE kode='AUD-T7-A'")['id'];$b=(int)$one("SELECT id FROM mata_pelajaran WHERE kode='AUD-T7-B'")['id'];
$add=$payload;$add['mapel'][]=['mata_pelajaran_id'=>$a,'target_ketuntasan'=>70];$add['mapel'][]=['mata_pelajaran_id'=>$b,'target_ketuntasan'=>71];
$r=$admin->req('/api/v1/v4/rencana',$add,$token);$check($r['status']===200&&count($svc->read($puser,$tahun,$kelas)['mapel'])===5,'AU REST tambah mapel tanpa nilai di rencana bernilai');
$edit=$add;$edit['mapel'][3]['target_ketuntasan']=82;$r=$admin->req('/api/v1/v4/rencana',$edit,$token);
$check($r['status']===200&&(float)$one("SELECT target_ketuntasan FROM v4_rencana_mapel WHERE rencana_id=$pid AND mata_pelajaran_id=$a")['target_ketuntasan']===82.0,'AU REST target mapel tanpa nilai dapat diubah');
$before=$svc->read($puser,$tahun,$kelas);
$bad=$edit;$bad['mapel'][0]['target_ketuntasan']=1;$bad['mapel'][3]['target_ketuntasan']=20;
$r=$admin->req('/api/v1/v4/rencana',$bad,$token);$check($r['status']===409&&$before===$svc->read($puser,$tahun,$kelas),'AU REST target bernilai ditolak atomik, target lain dan versi tidak berubah');
$bad=$payload;array_shift($bad['mapel']);$r=$admin->req('/api/v1/v4/rencana',$bad,$token);
$check($r['status']===409&&$before===$svc->read($puser,$tahun,$kelas),'AU REST hapus mapel bernilai ditolak tanpa ikut menghapus mapel kosong');
$bad=$edit;$bad['tempat']='Audit pindah';$r=$admin->req('/api/v1/v4/rencana',$bad,$token);
$check($r['status']===409&&$before===$svc->read($puser,$tahun,$kelas),'AU REST tempat bernilai wajib jalur admin');
$bad=$edit;$bad['pendidikan_penanda_id']=null;$bad['pendidikan_aset_id']=null;$r=$admin->req('/api/v1/v4/rencana',$bad,$token);
$check($r['status']===409&&$before===$svc->read($puser,$tahun,$kelas),'AU REST mengosongkan pasangan penanda bernilai ditolak');
$db->query("CREATE TRIGGER aud_t7_fail BEFORE INSERT ON audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic audit failure'");
try{$reject(fn()=>$svc->save($puser,$payload),503,'AU audit gagal menggulung penghapusan mapel kosong');}finally{$db->query('DROP TRIGGER aud_t7_fail');}
$check($before===$svc->read($puser,$tahun,$kelas),'AU audit gagal mempertahankan seluruh mapel dan versi');
$r=$admin->req('/api/v1/v4/rencana',$payload,$token);$after=$svc->read($puser,$tahun,$kelas);
$check($r['status']===200&&count($after['mapel'])===3,'AU REST hapus kedua mapel kosong berhasil');
$log=json_decode($one("SELECT after_json FROM audit_logs WHERE action='v4.rencana.ubah' AND entity_id=$pid ORDER BY id DESC LIMIT 1")['after_json'],true);
$check($log['mapel_dihapus']===[$a,$b],'AU audit mencatat kedua mapel dihapus');
$check($frozen===$rows('SELECT snapshot_json,pdf_sha256 FROM v4_rapor_versi ORDER BY id'),'AU snapshot dan hash PDF terbit tidak berubah');
$asset=(int)$ids['penanda']['pendidikan']['aset'];$signer=(int)$ids['penanda']['pendidikan']['penanda'];
$db->query("UPDATE v4_penanda_tangan SET is_active=0 WHERE id=$signer");
try{$r=$admin->req('/api/v1/v4/rencana',$payload,$token);$check($r['status']===200,'AU penanda lama nonaktif tidak menghalangi simpan mapel dengan pasangan tetap');$check($pend->req('/portal/rapor_ttd.php?aset='.$asset)['status']===403,'TTD penanda dinonaktifkan langsung ditolak sesi Pendidikan lama');$check($admin->req('/portal/rapor_ttd.php?aset='.$asset)['status']===200,'TTD admin tetap dapat memeriksa aset penanda nonaktif');}finally{$db->query("UPDATE v4_penanda_tangan SET is_active=1 WHERE id=$signer");}
foreach(["is_active=0","berlaku_mulai=DATE_ADD(CURDATE(),INTERVAL 1 DAY)","berlaku_selesai=DATE_SUB(CURDATE(),INTERVAL 1 DAY)"] as $change){
    $db->query("UPDATE v4_tanda_tangan_aset SET $change WHERE id=$asset");
    try{$check($pend->req('/portal/rapor_ttd.php?aset='.$asset)['status']===403,'TTD batas aktif/waktu diperiksa ulang: '.$change);}finally{$db->query("UPDATE v4_tanda_tangan_aset SET is_active=1,berlaku_mulai=NULL,berlaku_selesai=NULL WHERE id=$asset");}
}
$pengurus=(int)$one("SELECT pengurus_id FROM users WHERE username='sbx_pendidikan'")['pengurus_id'];
$assign=$rows('SELECT id,is_active FROM pendidikan_assignments WHERE pengurus_id='.$pengurus);
$db->query('UPDATE pendidikan_assignments SET is_active=0 WHERE pengurus_id='.$pengurus);
try{$check($pend->req('/portal/rapor_ttd.php?aset='.$asset)['status']===403,'TTD pencabutan penugasan langsung berlaku pada sesi lama');$check($admin->req('/api/v1/v4/rencana',$payload,$token)['status']===403,'AU pencabutan penugasan berlaku pada token REST lama');}finally{foreach($assign as $x)$db->query('UPDATE pendidikan_assignments SET is_active='.(int)$x['is_active'].' WHERE id='.(int)$x['id']);}
$sid=(int)$ids['santri'][1];$rid=(int)$ids['rapor'];$wid=(int)$one("SELECT wali_id FROM users WHERE username='sbx_t7_ortu'")['wali_id'];
$check($wali->req('/portal/rapor_pdf.php?id='.$rid.'&version=1')['status']===403,'AV PDF versi diganti ditolak orang tua');
$check($wali->req('/portal/rapor_pdf.php?id='.$rid.'&version=2')['status']===200,'AV PDF terbaru dapat diunduh orang tua');
$db->query("UPDATE santri_wali SET archived_at=NOW() WHERE wali_id=$wid AND santri_id=$sid");
try{$check($wali->req('/portal/rapor_wali.php?id='.$rid)['status']===403,'AV relasi dicabut menolak detail pada sesi wali lama');$check($wali->req('/portal/rapor_pdf.php?id='.$rid.'&version=2')['status']===403,'AV relasi dicabut menolak PDF pada sesi wali lama');$check(!str_contains($wali->req('/portal/rapor_wali.php')['body'],'SBX T7 Santri 01'),'AV relasi dicabut hilang dari chip dan daftar');}finally{$db->query("UPDATE santri_wali SET archived_at=NULL WHERE wali_id=$wid AND santri_id=$sid");}

// Satu kelas baru 30 santri sintetis, belum pernah dinilai/diterbitkan.
$db->query("INSERT INTO kelas (nama_kelas,jenjang,is_active) VALUES ('Audit T7 Kelas 30','Tsanawi',1)");$kc=(int)$db->insert_id;
$students=[];for($i=1;$i<=30;$i++){
    $db->query("INSERT INTO santri (nis,nama_santri,jenis_kelamin,tempat_lahir,tgl_lahir,alamat,desa,kecamatan,kab_kota,provinsi,nama_ayah,nama_ibu,asal_sekolah,sekolah_saat_ini,is_active) VALUES ('AUD7-$i','Audit Santri $i','L','Kota Uji','2013-03-03','Alamat uji','Desa','Kecamatan','Kabupaten','Provinsi','Ayah Uji','Ibu Uji','SD Uji','Tsanawi',1)");$s=(int)$db->insert_id;$students[]=$s;
    $db->query("INSERT INTO plotting_kelas (id_santri,id_kelas,id_tahun,tanggal_mulai,status) VALUES ($s,$kc,$tahun,'2026-08-24','Aktif')");
}
$gid=(int)$user('sbx_guru_mapel')['guru_id'];$mp=(int)$ids['mapel']['fiqih'];$uid=(int)$user('sbx_admin')['id'];
$db->query("INSERT INTO guru_mapel_assignments (guru_id,mata_pelajaran_id,kelas_id,tahun_ajaran_id,tanggal_mulai,created_by) VALUES ($gid,$mp,$kc,$tahun,'2026-08-24',$uid)");$ga=(int)$db->insert_id;
$p=$svc->save($puser,['tahun_ajaran_id'=>$tahun,'kelas_id'=>$kc,'mapel'=>[['mata_pelajaran_id'=>$mp,'guru_mapel_assignment_id'=>$ga]]]);$rm=(int)$p['mapel'][0]['id'];
$path='/portal/nilai_semester.php?rencana_mapel_id='.$rm.'&kolom=2';$html=$guru->req($path)['body'];
$check(substr_count($html,'<tr data-santri=')===30,'Kriteria 30 santri: kisi kelas kosong memuat semua baris');
$post=['_csrf'=>$guru->csrf($path),'aksi'=>'kisi','pengganti'=>'0','rencana_mapel_id'=>$rm,'n'=>[]];
foreach($students as $s)$post['n'][$s]=['Harian:1'=>'80','Harian:2'=>'90','Tengah:0'=>'78','Akhir:0'=>'92'];
$guru->req($path,$post);
$check((int)$one("SELECT COUNT(*) n FROM v4_nilai WHERE rencana_mapel_id=$rm")['n']===120,'Kriteria 30 santri: satu POST menyimpan seluruh 120 nilai');
$check((int)$one("SELECT COUNT(*) n FROM v4_nilai_riwayat h JOIN v4_nilai n ON n.id=h.nilai_id WHERE n.rencana_mapel_id=$rm")['n']===120,'Kriteria 30 santri: tepat 120 revisi pertama');
$n=$rows("SELECT * FROM v4_nilai WHERE rencana_mapel_id=$rm AND santri_id={$students[29]} ORDER BY id");
$stale=['_csrf'=>$post['_csrf'],'aksi'=>'kisi','pengganti'=>'0','rencana_mapel_id'=>$rm,'alasan_koreksi'=>'Uji konflik'];
foreach($n as $v){$key=$v['komponen'].':'.$v['nomor_harian'];$stale['n'][$students[29]][$key]=(string)$v['nilai'];$stale['ver'][$students[29]][$key]=(int)$v['version'];}
$stale['n'][$students[29]]['Tengah:0']='88';$guru->req($path,$stale);$latest=$rows("SELECT * FROM v4_nilai WHERE rencana_mapel_id=$rm AND santri_id={$students[29]} ORDER BY id");
$stale['n'][$students[29]]['Tengah:0']='10';$guru->req($path,$stale);
$check($latest===$rows("SELECT * FROM v4_nilai WHERE rencana_mapel_id=$rm AND santri_id={$students[29]} ORDER BY id"),'Kisi versi usang tidak menimpa koreksi santri terakhir');
$check(str_contains($guru->req($path)['body'],'Versi usang'),'Kisi konflik memberi pesan kegagalan per santri');
echo "Ringkasan: $pass lulus, $fail gagal\n";exit($fail?1:0);
