<?php
// Probe independen auditor Claude Code untuk Fase 4 PRD V4. Hanya DB uji sintetis.
// Menjalankan: php tests/v4_phase4_audit_claude.php  (setelah rangkaian Fase 4 Codex).
require __DIR__.'/v4_phase4_support.php';
use App\V4\{RaporService,RaporStorage,V4Exception};
$fx='/private/tmp/v4-fase4-audit';@mkdir($fx,0700,true);
$mode=$argv[1]??'main';
$storageDir=APP_ROOT.'/storage/private/v4';$ids=json_decode(file_get_contents('/private/tmp/v4-fase4-ids.json'),true);

if($mode==='worker'){
    // Penerbit lambat: menahan transaksi SERIALIZABLE sesudah gate + tulis PDF, sebelum commit.
    mysqli_report(MYSQLI_REPORT_OFF);
    $input=json_decode(file_get_contents("$fx/input.json"),true);
    $storage=new class($fx) extends RaporStorage {
        public function __construct(private string $fx){parent::__construct();}
        public function write(string $n,string $b):void{parent::write($n,$b);file_put_contents($this->fx.'/marker',$n);usleep(16000000);}
    };
    try{(new RaporService($db,audit_logger(),$storage))->act($pe,$input);echo '200';}catch(V4Exception $e){echo $e->status;fwrite(STDERR,$e->getMessage());}
    exit(0);
}

$pdfs=static fn()=>count(glob($storageDir.'/*.pdf')?:[]);
$state=static function()use($q,$pdfs){return [
    'versi'=>(int)$q('SELECT COUNT(*) n FROM v4_rapor_versi')[0]['n'],
    'kode'=>(int)$q('SELECT COUNT(*) n FROM v4_verifikasi')[0]['n'],
    'operasi'=>(int)$q('SELECT COUNT(*) n FROM v4_rapor_operasi')[0]['n'],
    'outbox'=>(int)$q("SELECT COUNT(*) n FROM notifikasi_outbox WHERE event_type='v4_rapor_terbit'")[0]['n'],
    'audit'=>(int)$q("SELECT COUNT(*) n FROM audit_logs WHERE action LIKE 'v4.rapor.%'")[0]['n'],
    'rapor'=>$q('SELECT status,current_version FROM v4_rapor ORDER BY id'),
    'pdf'=>$pdfs(),
];};
$fingerprint=static function()use($q,$storageDir){$out=[];foreach($q('SELECT id,version,status,snapshot_json,pdf_private_path,pdf_sha256 FROM v4_rapor_versi ORDER BY id') as $v){$out[$v['id']]=[hash('sha256',$v['snapshot_json']),$v['pdf_sha256'],hash_file('sha256',$storageDir.'/'.$v['pdf_private_path'])];}return $out;};
$immutable=$fingerprint();
foreach($immutable as $id=>[$snap,$stored,$file])$ok($stored===$file,"Awal: PDF versi #$id cocok hash tersimpan");
$verifyAll();

// Pertemuan Draf sintetis tanpa peserta (tidak memengaruhi gate) untuk uji phantom insert.
$jd=(int)$q("SELECT p.jadwal_id FROM pertemuan_peserta pp JOIN pertemuan_pengajian p ON p.id=pp.pertemuan_id WHERE pp.santri_id=$sid LIMIT 1")[0]['jadwal_id'];
$db->query("INSERT INTO pertemuan_pengajian (jadwal_id,tanggal_pertemuan,created_by,status) VALUES ($jd,'2031-12-31',1,'Draf')");
// ---------- A. Kunci sumber dibaca ulang di transaksi SERIALIZABLE ----------
@unlink("$fx/marker");file_put_contents("$fx/input.json",json_encode($make('Ganti')));
$pipes=[];$proc=proc_open([PHP_BINARY,__FILE__,'worker'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
$end=microtime(true)+15;while(!is_file("$fx/marker")&&microtime(true)<$end)usleep(20000);
$ok(is_file("$fx/marker"),'A0 Penerbit lambat mencapai tulis PDF sambil memegang transaksi');
$probe=new mysqli();$probe->real_connect((string)app_config('database.host'),(string)app_config('database.username'),(string)app_config('database.password'),(string)app_config('database.database'));
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);$probe->query('SET SESSION innodb_lock_wait_timeout=1');
$selesai=(int)$q("SELECT pp.pertemuan_id id FROM pertemuan_peserta pp JOIN pertemuan_pengajian p ON p.id=pp.pertemuan_id AND p.status='Selesai' WHERE pp.santri_id=$sid AND pp.tahun_ajaran_id_snapshot=4 ORDER BY pp.pertemuan_id LIMIT 1")[0]['id'];
$jadwal=(int)$q("SELECT jadwal_id FROM pertemuan_pengajian WHERE id=$selesai")[0]['jadwal_id'];
$otherMeeting=(int)$q('SELECT id FROM pertemuan_pengajian WHERE tanggal_pertemuan=\'2031-12-31\'')[0]['id'];
$tanpaAbsen=$q("SELECT pp.pertemuan_id id FROM pertemuan_peserta pp LEFT JOIN absensi_santri a ON a.pertemuan_id=pp.pertemuan_id AND a.santri_id=pp.santri_id WHERE pp.santri_id=$sid AND a.id IS NULL LIMIT 1")[0]['id']??null;
$probes=[
 'phantom peserta baru santri'=>"INSERT INTO pertemuan_peserta (pertemuan_id,santri_id,nis_snapshot,nama_santri_snapshot,kelas_id_snapshot,tahun_ajaran_id_snapshot) VALUES ($otherMeeting,$sid,'UJI','Uji',2,4)",
 'status pertemuan sumber'=>"SELECT id FROM pertemuan_pengajian WHERE id=$selesai FOR UPDATE",
 // Bacaan jadwal memakai indeks sekunder penutup (id_tahun); uji perubahan nyata semester jadwal.
 'semester jadwal sumber'=>"UPDATE jadwal_ngaji SET id_tahun=id_tahun+1 WHERE id=$jadwal",
 'status pertemuan menjadi Batal'=>"UPDATE pertemuan_pengajian SET status='Draf' WHERE id=$selesai",
 'absensi santri ada'=>"SELECT id FROM absensi_santri WHERE santri_id=$sid AND pertemuan_id=$selesai FOR UPDATE",
 'nilai santri'=>"SELECT id FROM v4_nilai WHERE santri_id=$sid FOR UPDATE",
 'sikap santri'=>"SELECT id FROM v4_sikap WHERE santri_id=$sid FOR UPDATE",
 'pemeriksaan bahan'=>"SELECT id FROM v4_bahan WHERE santri_id=$sid AND rencana_id=$plan FOR UPDATE",
 'penempatan kelas'=>"SELECT id FROM plotting_kelas WHERE id_santri=$sid FOR UPDATE",
 'penugasan Pendidikan pelaku'=>"SELECT id FROM pendidikan_assignments WHERE pengurus_id=1 FOR UPDATE",
 'aset tanda tangan'=>"SELECT id FROM v4_tanda_tangan_aset WHERE id IN (".(int)$ids['assets4']['pendidikan_aset_id'].",".(int)$ids['assets4']['pimpinan_aset_id'].") FOR UPDATE",
 'rencana mapel/target'=>"SELECT id FROM v4_rencana_mapel WHERE rencana_id=$plan FOR UPDATE",
];
if($tanpaAbsen)$probes['phantom absensi belum dicatat']="INSERT INTO absensi_santri (pertemuan_id,santri_id,status,dicatat_pada,dicatat_oleh) VALUES ($tanpaAbsen,$sid,'Hadir',NOW(),1)";
foreach($probes as $label=>$sql){
    $probe->begin_transaction();
    try{$probe->query($sql);$ok(false,"A Sumber '$label' harus terkunci selama penerbitan");}
    catch(mysqli_sql_exception $e){$ok((int)$e->getCode()===1205,"A Sumber '$label' terkunci (1205) selama penerbitan; kode ".$e->getCode());}
    finally{$probe->rollback();}
}
$code=trim(stream_get_contents($pipes[1]));$err=stream_get_contents($pipes[2]);foreach($pipes as $f)fclose($f);proc_close($proc);
$ok($code==='200'&&$err==='',"A Penerbit lambat commit satu versi ($code $err)");

// ---------- B. Injeksi kegagalan wajib: seluruh dampak digulung, berkas tak terujuk dihapus ----------
$db->query('CREATE TABLE IF NOT EXISTS audit_fail (n INT NOT NULL) ENGINE=MEMORY');$db->query('DELETE FROM audit_fail');$db->query('INSERT INTO audit_fail VALUES (0)');
$walib=(int)$q("SELECT id FROM users WHERE username='v4_wali4b'")[0]['id'];
$inject=[
 'kode verifikasi'=>['v4_verifikasi','INSERT','1'],
 'outbox wali kedua (sesudah wali pertama sukses)'=>['notifikasi_outbox','INSERT',"NEW.event_type='v4_rapor_terbit' AND NEW.penerima_user_id=$walib"],
 'operasi/idempotensi'=>['v4_rapor_operasi','INSERT','1'],
 'audit umum'=>['audit_logs','INSERT',"NEW.action LIKE 'v4.rapor.%'"],
 'pembatalan versi lama'=>['v4_rapor_versi','UPDATE','1'],
];
foreach($inject as $label=>[$table,$event,$cond]){
    $db->query('DROP TRIGGER IF EXISTS audit_inject');
    $db->query("CREATE TRIGGER audit_inject BEFORE $event ON $table FOR EACH ROW BEGIN IF $cond THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injeksi auditor'; END IF; END");
    $before=$state();$in=$make('Ganti');
    try{$svc->act($pe,$in);$ok(false,"B Injeksi $label harus gagal");}catch(V4Exception $e){$ok($e->status===503,"B Injeksi $label → HTTP {$e->status}");}
    $db->query('DROP TRIGGER audit_inject');
    $ok($state()===$before,"B Injeksi $label: versi/kode/operasi/outbox/audit/status/berkas PDF tidak berubah");
}
$before=$state();
$broken=new class extends RaporStorage { public function write(string $n,string $b):void{parent::write($n,substr($b,0,100));throw new V4Exception('tulis parsial',503);} };
try{(new RaporService($db,audit_logger(),$broken))->act($pe,$make('Ganti'));$ok(false,'B Tulis parsial harus gagal');}catch(V4Exception $e){$ok($e->status===503,'B Tulis parsial → 503');}
$ok($state()===$before,'B Tulis parsial: berkas parsial dihapus, tidak ada perubahan DB');

// ---------- C. Retry konflik transaksi sementara (1213) deterministik, strict dan OFF ----------
foreach(['strict'=>MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT,'off'=>MYSQLI_REPORT_OFF] as $label=>$report){
    foreach([2=>true,4=>false] as $failures=>$succeeds){
        $db->query("UPDATE audit_fail SET n=$failures");
        $db->query("CREATE TRIGGER audit_inject BEFORE INSERT ON v4_rapor_operasi FOR EACH ROW BEGIN IF (SELECT n FROM audit_fail)>0 THEN UPDATE audit_fail SET n=n-1; SIGNAL SQLSTATE '40001' SET MYSQL_ERRNO=1213, MESSAGE_TEXT='deadlock auditor'; END IF; END");
        $before=$state();$in=$make('Ganti');mysqli_report($report);
        try{$out=$svc->act($pe,$in);$res=200;}catch(V4Exception $e){$res=$e->status;}
        mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);$db->query('DROP TRIGGER audit_inject');
        $after=$state();
        if($succeeds){$ok($res===200&&$after['versi']===$before['versi']+1&&$after['operasi']===$before['operasi']+1&&$after['pdf']===$before['pdf']+1,"C $label: $failures konflik 1213 dicoba ulang → tepat satu versi/operasi/PDF");}
        else{$ok($res===409&&$after===$before,"C $label: konflik 1213 terus-menerus → 409 tanpa dampak, berkas tiap percobaan dihapus");}
    }
}
// Konflik versi produk tidak dicoba ulang/ditimpa.
$stale=$make('Ganti');$svc->act($pe,$make('Ganti'));$before=$state();
try{$svc->act($pe,$stale);$ok(false,'C revisi usang harus 409');}catch(V4Exception $e){$ok($e->status===409&&$state()===$before,'C Revisi usang 409 tanpa retry yang menimpa revisi');}

// ---------- D. Hasil commit ambigu: berkas ter-commit tidak dihapus ----------
$cfg=[(string)app_config('database.host'),(string)app_config('database.username'),(string)app_config('database.password'),(string)app_config('database.database')];
foreach(['commit sukses, jawaban false'=>'false','commit sukses, koneksi hilang'=>'gone','commit gagal sungguhan'=>'rollback'] as $label=>$how){
    $amb=new class($how) extends mysqli { public function __construct(public string $how){parent::__construct();}
        public function commit(int $flags=0,?string $name=null):bool{
            if($this->how==='rollback'){parent::rollback();return false;}
            parent::commit();if($this->how==='gone'){$this->close();throw new mysqli_sql_exception('MySQL server has gone away',2006);}return false;}
    };
    mysqli_report(MYSQLI_REPORT_OFF);$amb->real_connect(...$cfg);$amb->set_charset('utf8mb4');
    $before=$state();$in=$make('Ganti');
    try{(new RaporService($amb,new App\Audit\AuditLogger($amb)))->act($pe,$in);$ok(false,"D $label harus melaporkan ketidakpastian");}catch(V4Exception $e){$ok($e->status===503,"D $label → 503 'ulangi dengan key yang sama'");}
    mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
    $after=$state();
    if($how==='rollback'){$ok($after===$before,"D $label: tidak ada versi dan berkas yatim dihapus");continue;}
    $v=$q('SELECT * FROM v4_rapor_versi ORDER BY id DESC LIMIT 1')[0];
    $ok($after['versi']===$before['versi']+1&&is_file($storageDir.'/'.$v['pdf_private_path']),"D $label: versi ter-commit tetap menunjuk berkas yang tidak dihapus");
    $ok((new RaporStorage())->read($v)!=='','D '.$label.': PDF ter-commit terbaca dan hash cocok');
    $replay=$svc->act($pe,$in);$ok($replay['replayed']===true&&$state()===$after,"D $label: ulang key sama → replay tanpa versi baru");
}

// ---------- E. Replay, hak dicabut, key beda isi ----------
$in=$make('Ganti');$svc->act($pe,$in);$db->query('UPDATE pendidikan_assignments SET is_active=0 WHERE id=1');
try{$reject(fn()=>$svc->act($pe,$in),403,'E Replay key sama setelah penugasan Pendidikan dicabut ditolak');
    $reject(fn()=>$svc->preview($pe,$plan,$sid),403,'E Pratinjau setelah penugasan dicabut ditolak');
    $reject(fn()=>$svc->show($pe,(int)$ids['rapor'],true),403,'E Riwayat/cetak petugas setelah penugasan dicabut ditolak');
    $reject(fn()=>$svc->pdf($pe,(int)$ids['rapor'],1,true),403,'E PDF petugas setelah penugasan dicabut ditolak');
}finally{$db->query('UPDATE pendidikan_assignments SET is_active=1 WHERE id=1');}
$tarik=$make('Tarik');$svc->act($pe,$tarik);$cur=$state();$r=$svc->act($pe,$in);
$ok($r['replayed']===true&&$r['status']==='Ditarik'&&$state()===$cur,'E Replay setelah status berubah mengembalikan metadata saat ini tanpa menerbitkan ulang');
$changed=$in;$changed['catatan_umum'].=' beda';$reject(fn()=>$svc->act($pe,$changed),409,'E Key sama isi berbeda ditolak');
$verifyAll();$svc->act($pe,$make('Ganti'));

// ---------- F. Otorisasi wali/petugas pada ID langsung ----------
$rid=(int)$ids['rapor'];$cv=(int)$q("SELECT current_version FROM v4_rapor WHERE id=$rid")[0]['current_version'];
$wa=['id'=>(int)$q("SELECT id FROM users WHERE username='v4_wali4a'")[0]['id']];$wl=['id'=>(int)$q("SELECT id FROM users WHERE username='v4_wali4lain'")[0]['id']];
$ok(strlen($svc->pdf($wa,$rid,$cv))>1000,'F Wali A berelasi aktif mengunduh versi sah');
$reject(fn()=>$svc->pdf($wa,$rid,1),403,'F Wali A tidak dapat mengunduh versi Diganti');
$reject(fn()=>$svc->show($wl,$rid),403,'F Wali lain ditolak pada detail ID langsung');
$reject(fn()=>$svc->pdf($wl,$rid,$cv),403,'F Wali lain ditolak pada PDF ID langsung');
$reject(fn()=>$svc->show($wl,999999),403,'F ID rapor acak memberi penolakan yang sama');
$reject(fn()=>$svc->show($wa,$rid,true),403,'F Wali dengan flag petugas palsu ditolak');
$reject(fn()=>$svc->show(['id'=>(int)$q("SELECT id FROM users WHERE username='v4_guru'")[0]['id']],$rid,true),403,'F Guru dengan flag petugas ditolak');
$db->query("UPDATE santri_wali SET archived_at=NOW() WHERE santri_id=$sid AND wali_id=".(int)$ids['wali4']['a']);
try{$reject(fn()=>$svc->show($wa,$rid),403,'F Relasi dicabut: detail ditolak');$reject(fn()=>$svc->pdf($wa,$rid,$cv),403,'F Relasi dicabut: PDF ditolak');$ok(!$svc->listing($wa),'F Relasi dicabut: daftar kosong');}
finally{$db->query("UPDATE santri_wali SET archived_at=NULL WHERE santri_id=$sid AND wali_id=".(int)$ids['wali4']['a']);}
$db->query("UPDATE users SET is_active=0 WHERE id={$wa['id']}");
try{$reject(fn()=>$svc->show($wa,$rid),403,'F Akun wali nonaktif ditolak');}finally{$db->query("UPDATE users SET is_active=1 WHERE id={$wa['id']}");}
$detail=$svc->show($wa,$rid);$withSnap=array_filter($detail['versi'],fn($v)=>isset($v['snapshot']));
$ok(count($withSnap)===1&&array_values($withSnap)[0]['version']===$cv&&count($detail['versi'])===$cv,'F Wali melihat status semua versi, snapshot hanya versi sah');
$ok(!array_key_exists('alasan',$detail['riwayat'][0])&&!array_key_exists('actor_user_id',$detail['riwayat'][0]),'F Riwayat wali tanpa alasan/pelaku petugas');

// ---------- G. Outbox per versi, snapshot/PDF lama tidak berubah ----------
$event="v4:rapor:$rid:v$cv";$rows=$q("SELECT penerima_user_id,kanal,judul,isi,data_json FROM notifikasi_outbox WHERE event_key='$event'");
$inapp=array_column(array_filter($rows,fn($r)=>$r['kanal']==='InApp'),'penerima_user_id');sort($inapp);
$expect=[(int)$q("SELECT id FROM users WHERE username='v4_wali4a'")[0]['id'],$walib];sort($expect);
$ok(array_map('intval',$inapp)===$expect,'G Satu InApp per wali berakun aktif; tanpa akun dan wali lain nol');
$ok(!array_filter($rows,fn($r)=>$r['kanal']==='WhatsApp'),'G Tidak ada WhatsApp untuk rapor');
foreach($rows as $r){$ok($r['judul']==='Rapor semester tersedia'&&$r['isi']==='Rapor semester tersedia'&&array_keys(json_decode($r['data_json'],true))===['tipe','rapor_id'],'G Isi outbox generik dan penunjuk saja ('.$r['kanal'].')');}
$now=$fingerprint();
foreach($immutable as $id=>$fp)$ok($now[$id]===$fp,"G Snapshot dan PDF versi #$id byte-identik setelah seluruh probe");
$kode=$q('SELECT v.status vs,k.status ks FROM v4_rapor_versi v JOIN v4_verifikasi k ON k.rapor_versi_id=v.id');
$ok(!array_filter($kode,fn($r)=>$r['vs']!==$r['ks']),'G Status kode verifikasi selalu sama dengan status versi');
// ---------- H. Koreksi auditor: rata-rata pada rapor tampil bilangan bulat (PRD §5.1) ----------
$snap=$svc->preview($pe,$plan,$sid)['snapshot'];$body=App\V4\RaporDocument::body($snap);
$ok(is_int($snap['rata_rata_tampil'])&&$snap['rata_rata_tampil']===(int)round($snap['rata_rata'],0,PHP_ROUND_HALF_UP)&&str_contains($body,'Rata-rata nilai: '.$snap['rata_rata_tampil'].'</p>'),'H Rata-rata baru tampil bilangan bulat ('.$snap['rata_rata'].' → '.$snap['rata_rata_tampil'].'), internal dua desimal tetap di snapshot');
$legacy=$snap;unset($legacy['rata_rata_tampil']);$legacy['rata_rata']=84.6;
$ok(str_contains(App\V4\RaporDocument::body($legacy),'Rata-rata nilai: 84.6</p>'),'H Snapshot lama tanpa kunci tampil tetap sama dengan PDF terbitnya');
$cv=(int)$q("SELECT current_version FROM v4_rapor WHERE id=$rid")[0]['current_version'];$svc->act($pe,$make('Ganti'));
$text=$svc->show($wa,$rid)['versi'][0]['snapshot'];$ok(array_key_exists('rata_rata_tampil',$text)&&$text['version']===$cv+1,'H Versi pengganti baru menyimpan rata-rata tampil bulat dalam snapshot');
$db->query('DROP TABLE audit_fail');$db->query("DELETE FROM pertemuan_pengajian WHERE tanggal_pertemuan='2031-12-31' AND status='Draf'");
echo "Probe auditor Fase 4 selesai pada DB uji sintetis.\n";
