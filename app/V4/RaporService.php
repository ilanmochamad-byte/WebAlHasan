<?php
declare(strict_types=1);
namespace App\V4;

use App\Audit\AuditLogger;
use App\Notification\{RecipientResolver,SettingsRepository,NotificationRepository};
use App\Penugasan\PenugasanJenis;
use mysqli;
use Throwable;

final class RaporService
{
    private BahanRepository $repo;
    private SemesterScope $scope;
    private RaporStorage $storage;
    public function __construct(private mysqli $db,private AuditLogger $audit,?RaporStorage $storage=null)
    {
        $this->repo=new BahanRepository($db,$audit);$this->scope=new SemesterScope($db);$this->storage=$storage??new RaporStorage();
    }
    private function q(string $sql,string $types='',array $args=[]): array {return $this->repo->query($sql,$types,$args);}
    private function one(string $sql,string $types='',array $args=[]): ?array {return $this->q($sql,$types,$args)[0]??null;}
    private function reason(mixed $reason,bool $required): string
    {
        if(!is_string($reason)||mb_strlen($reason)>1000||($required&&trim($reason)===''))throw new V4Exception('Alasan wajib diisi (maksimal 1000 karakter).');
        return trim($reason);
    }
    private function current(int $sid,int $year): ?array
    {
        return $this->one('SELECT r.*,(SELECT COALESCE(MAX(o.id),0) FROM v4_rapor_operasi o WHERE o.rapor_id=r.id) revision FROM v4_rapor r WHERE r.santri_id=? AND r.tahun_ajaran_id=?','ii',[$sid,$year]);
    }
    public function preview(array $u,int $plan,int $sid,string $note=''): array
    {
        if(mb_strlen($note)>2000)throw new V4Exception('Catatan umum maksimal 2000 karakter.');
        $matrix=(new VerifikasiService($this->db,$this->audit))->read($u,$plan,$sid);$p=$matrix['rencana'];
        $this->scope->education($u,$p,PenugasanJenis::CAP_RAPOR_FINALISASI);
        $student=$matrix['matriks'][0];$missing=$student['kekurangan'];$signers=[];
        foreach(['pendidikan'=>'Pendidikan','pimpinan'=>'Pimpinan'] as $key=>$job){
            $row=$this->one('SELECT p.id,p.jabatan,p.nama,p.gelar,a.id aset_id,a.private_path,a.sha256,a.mime_type FROM v4_penanda_tangan p JOIN v4_tanda_tangan_aset a ON a.penanda_tangan_id=p.id WHERE p.id=? AND a.id=? AND p.jabatan=? AND p.is_active=1 AND a.is_active=1 AND (a.berlaku_mulai IS NULL OR a.berlaku_mulai<=CURDATE()) AND (a.berlaku_selesai IS NULL OR a.berlaku_selesai>=CURDATE())','iis',[(int)$p[$key.'_penanda_id'],(int)$p[$key.'_aset_id'],$job]);
            if(!$row){$missing[]='Penanda tangan/aset '.$job.' belum sah.';continue;}
            try{$row['gambar']=$this->storage->asset($row);}catch(V4Exception $e){$missing[]=$e->getMessage();}
            unset($row['private_path']);$signers[]=$row;
        }
        if(!trim((string)$p['tempat']))$missing[]='Tempat penerbitan belum diisi.';
        $subjects=[];$att=[];$attitude=[];
        foreach($student['bahan'] as $m){
            if($m['jenis']==='nilai'){$target=$this->one('SELECT target_ketuntasan FROM v4_rencana_mapel WHERE id=?','i',[$m['mapel_key']]);$subjects[]=['nama'=>$m['label'],'hasil'=>$m['hasil'],'target'=>$target['target_ketuntasan'],'catatan'=>implode("\n",array_values(array_unique(array_filter(array_column($m['komponen'],'catatan'),static fn($v)=>$v!==null&&$v!==''))))];}
            elseif($m['jenis']==='sikap')$attitude=array_intersect_key($m['sikap'],array_flip(['adab','kedisiplinan','tanggung_jawab','catatan','pembimbing_user_id','diketahui_murobi_user_id']));
            else $att=array_intersect_key($m['kehadiran'],array_flip(['hitungan','jumlah_pertemuan','belum_dicatat','sumber']));
        }
        $finals=array_column(array_column($subjects,'hasil'),'nilai_internal');$average=count($finals)===count($subjects)&&$finals?round(array_sum($finals)/count($finals),2,PHP_ROUND_HALF_UP):null;
        $cur=$this->current($sid,(int)$p['tahun_ajaran_id']);
        $snapshot=['santri'=>$this->one('SELECT id,nama_santri,nis FROM santri WHERE id=?','i',[$sid]),'rencana_id'=>$plan,'tahun_ajaran_id'=>(int)$p['tahun_ajaran_id'],'kelas_id'=>(int)$p['kelas_id'],'kelas'=>$p['nama_kelas'],'tahun'=>$p['tahun'],'semester'=>$p['semester'],'mapel'=>$subjects,'rata_rata'=>$average,'sikap'=>$attitude,'kehadiran'=>$att,'catatan_umum'=>$note,'penanda'=>$signers,'tempat'=>$p['tempat'],'tanggal'=>date('Y-m-d'),'version'=>(int)($cur['current_version']??0)+1,'kekurangan'=>$missing];
        return ['snapshot'=>$snapshot,'pratinjau_hash'=>BahanRepository::fingerprint([$snapshot,$matrix['matriks_hash'],$p['version']]),'siap_terbit'=>!$missing,'current_version'=>(int)($cur['current_version']??0),'revision'=>(int)($cur['revision']??0),'status'=>$cur['status']??'Draf','rapor_id'=>(int)($cur['id']??0)];
    }
    /** SERIALIZABLE locks rows and matching gaps of live sources and authorization reads.
     * This intentionally keeps locks through PDF generation, before any commit. */
    private function begin(): void
    {
        if(!$this->db->query('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE')||!$this->db->begin_transaction())throw new V4Exception('Transaksi penerbitan belum tersedia.',503);
    }
    private function claim(array $u,array $in): array
    {
        $key=$in['idempotency_key']??null;
        if(!is_string($key)||!preg_match('/^[A-Za-z0-9_-]{16,100}$/D',$key))throw new V4Exception('Idempotency key wajib (16–100 karakter).');
        // Canonical order; unknown JSON properties do not affect the business fingerprint.
        $data=[];foreach(['action','rencana_id','santri_id','current_version','revision','pratinjau_hash','catatan_umum','alasan'] as $k)$data[$k]=$in[$k]??null;
        $key=hash('sha256',$key);$hash=BahanRepository::fingerprint($data);
        $old=$this->one('SELECT * FROM v4_rapor_operasi WHERE actor_user_id=? AND request_key=?','is',[(int)$u['id'],$key]);
        if($old&&!hash_equals($old['request_hash'],$hash))throw new V4Exception('Idempotency key telah digunakan untuk permintaan berbeda.',409);
        return [$key,$hash,$old];
    }
    private function result(int $rid,bool $replayed=false): array
    {
        $r=$this->one('SELECT id,status,current_version FROM v4_rapor WHERE id=?','i',[$rid]);
        return $r+['revision'=>(int)$this->one('SELECT COALESCE(MAX(id),0) n FROM v4_rapor_operasi WHERE rapor_id=?','i',[$rid])['n'],'replayed'=>$replayed];
    }
    public function act(array $u,array $in): array
    {
        for($attempt=0;$attempt<4;$attempt++){
            try{return $this->actAttempt($u,$in);}catch(Throwable $e){
                if(!in_array((int)$e->getCode(),[1020,1205,1213],true)){if($e instanceof V4Exception)throw $e;throw new V4Exception('Operasi gagal. Ulangi dengan key yang sama.',503);}
                if($attempt===3)throw new V4Exception('Transaksi bersamaan. Muat ulang atau ulangi dengan key yang sama.',409);
                usleep(random_int(10000,40000));
            }
        }
        throw new V4Exception('Transaksi belum selesai.',409);
    }
    private function actAttempt(array $u,array $in): array
    {
        $plan=(int)($in['rencana_id']??0);$sid=(int)($in['santri_id']??0);$action=$in['action']??'';
        if(!in_array($action,['Terbit','Ganti','Tarik','Buka'],true))throw new V4Exception('Tindakan rapor tidak sah.');
        $cap=$action==='Terbit'?PenugasanJenis::CAP_RAPOR_FINALISASI:PenugasanJenis::CAP_RAPOR_BUKA_KOREKSI;
        $p=$this->scope->plan($plan);$capacity=$this->scope->education($u,$p,$cap);$this->scope->student($p,$sid);
        if(isset($in['catatan_umum'])&&!is_string($in['catatan_umum']))throw new V4Exception('Catatan umum tidak valid.');
        $reason=$this->reason($in['alasan']??'',$action!=='Terbit'||$capacity==='admin');
        $file=null;$this->begin();
        try{
            $this->q('SELECT id FROM v4_rencana WHERE id=? FOR UPDATE','i',[$plan]);
            $p=$this->scope->plan($plan);$capacity=$this->scope->education($u,$p,$cap);$this->scope->student($p,$sid);
            [$key,$hash,$old]=$this->claim($u,$in);
            if($old){$r=$this->result((int)$old['rapor_id'],true);$this->db->commit();return $r;}
            $cur=$this->current($sid,(int)$p['tahun_ajaran_id']);$version=(int)($cur['current_version']??0);$revision=(int)($cur['revision']??0);
            if((string)($in['current_version']??'')!==(string)$version||(string)($in['revision']??'')!==(string)$revision)throw new V4Exception('Rapor berubah. Muat ulang sebelum melanjutkan.',409);
            if($action==='Terbit'&&$version!==0)throw new V4Exception('Gunakan penggantian beralasan untuk versi berikutnya.',409);
            if($action!=='Terbit'&&$version===0)throw new V4Exception('Belum ada versi rapor.',409);
            if(in_array($action,['Tarik','Buka'],true)&&$cur['status']!=='Terbit')throw new V4Exception('Rapor tidak berstatus Terbit.',409);
            $rid=(int)($cur['id']??0);$uid=(int)$u['id'];
            if(in_array($action,['Terbit','Ganti'],true)){
                // Lock complete participant ranges incl. missing attendance gaps, before rereading gate.
                $this->q('SELECT id FROM pertemuan_peserta WHERE santri_id=? FOR UPDATE','i',[$sid]);
                $this->q('SELECT id FROM absensi_santri WHERE santri_id=? FOR UPDATE','i',[$sid]);
                $preview=$this->preview($u,$plan,$sid,(string)($in['catatan_umum']??''));
                if(($in['pratinjau_hash']??'')!==$preview['pratinjau_hash'])throw new V4Exception('Bahan atau penanda tangan berubah. Muat ulang pratinjau.',409);
                if(!$preview['siap_terbit'])throw new V4Exception('Penerbitan ditolak: '.implode(' ',$preview['snapshot']['kekurangan']));
                $base=(string)app_config('url');if(!preg_match('#^https?://[^/]+#D',$base))throw new V4Exception('APP_URL wajib tersedia untuk QR verifikasi.',503);
                $code=bin2hex(random_bytes(24));$url=app_url('/verifikasi_rapor.php').'#'.$code;
                $bytes=(new RaporDocument())->render($preview['snapshot'],$url,$code);
                $file=bin2hex(random_bytes(20)).'.pdf';$this->storage->write($file,$bytes);$pdfHash=hash('sha256',$bytes);
                if(!$rid){$this->q('INSERT INTO v4_rapor (santri_id,tahun_ajaran_id) VALUES (?,?)','ii',[$sid,(int)$p['tahun_ajaran_id']]);$rid=(int)$this->db->insert_id;}
                if($version)$this->invalidate($rid,$version,'Diganti');
                $version++;
                $this->q("INSERT INTO v4_rapor_versi (rapor_id,version,status,snapshot_json,pdf_private_path,pdf_sha256,pendidikan_aset_id,pimpinan_aset_id,diterbitkan_oleh,diterbitkan_pada,alasan) VALUES (?,?,'Terbit',?,?,?,?,?,?,NOW(),?)",'iisssiiis',[$rid,$version,json_encode($preview['snapshot'],JSON_THROW_ON_ERROR),$file,$pdfHash,(int)$p['pendidikan_aset_id'],(int)$p['pimpinan_aset_id'],$uid,$reason]);
                $vid=(int)$this->db->insert_id;
                $this->q("INSERT INTO v4_verifikasi (rapor_versi_id,kode_hash,status,pdf_sha256,dibuat_pada) VALUES (?,?,'Terbit',?,NOW())",'iss',[$vid,hash('sha256',$code),$pdfHash]);
                $this->q("UPDATE v4_rapor SET status='Terbit',current_version=? WHERE id=?",'ii',[$version,$rid]);
                $this->notify($sid,$rid,$version);
            }else{
                $this->invalidate($rid,$version,'Ditarik');$status=$action==='Buka'?'Perlu Perbaikan':'Ditarik';
                $this->q('UPDATE v4_rapor SET status=? WHERE id=?','si',[$status,$rid]);
            }
            $this->q('INSERT INTO v4_rapor_operasi (rapor_id,actor_user_id,kapasitas,tindakan,version,alasan,request_key,request_hash) VALUES (?,?,?,?,?,?,?,?)','iississs',[$rid,$uid,$capacity,$action,$version,$reason,$key,$hash]);
            if(!$this->audit->log('v4.rapor.'.$action,'v4_rapor',$rid,['version'=>(int)($cur['current_version']??0),'status'=>$cur['status']??'Draf'],['version'=>$version,'kapasitas'=>$capacity,'alasan_tercatat'=>$reason!==''],$uid))throw new V4Exception('Audit gagal; penerbitan dibatalkan.',503);
            $out=$this->result($rid);if(!$this->db->commit())throw new V4Exception('Konfirmasi transaksi gagal. Muat ulang dengan key yang sama.',503);return $out;
        }catch(Throwable $e){
            // Commit response may be uncertain: never delete a file referenced by committed DB state.
            try{$this->db->rollback();if($file&&!$this->one('SELECT id FROM v4_rapor_versi WHERE pdf_private_path=?','s',[$file]))$this->storage->remove($file);}catch(Throwable){/* DB unavailable: retain inaccessible orphan for reconciliation. */}
            if($e instanceof \mysqli_sql_exception&&in_array((int)$e->getCode(),[1020,1205,1213],true))throw $e;
            if($e instanceof V4Exception)throw $e;
            throw new V4Exception('Operasi gagal. Muat ulang; ulangi dengan key yang sama.',in_array((int)$e->getCode(),[1062,1205,1213],true)?409:503);
        }
    }
    private function invalidate(int $rid,int $version,string $status): void
    {
        $column=$status==='Diganti'?'diganti_pada':'ditarik_pada';
        // A withdrawn code stays withdrawn, including when a new version replaces it later.
        $this->q("UPDATE v4_rapor_versi SET status=?,$column=NOW() WHERE rapor_id=? AND version=? AND status='Terbit'",'sii',[$status,$rid,$version]);
        $this->q("UPDATE v4_verifikasi k JOIN v4_rapor_versi v ON v.id=k.rapor_versi_id SET k.status=v.status,k.$column=v.$column WHERE v.rapor_id=? AND v.version=?",'ii',[$rid,$version]);
    }
    private function notify(int $sid,int $rid,int $version): void
    {
        $users=(new RecipientResolver($this->db))->waliSantri($sid);
        $setting=(new SettingsRepository($this->db))->current();$repo=new NotificationRepository($this->db);
        foreach($users as $id){foreach($setting['push_enabled']?['InApp','Push']:['InApp'] as $channel){
            $row=['event_key'=>"v4:rapor:$rid:v$version",'event_type'=>'v4_rapor_terbit','kanal'=>$channel,'penerima_user_id'=>$id,'pengajuan_id'=>null,'judul'=>'Rapor semester tersedia','isi'=>'Rapor semester tersedia','data_json'=>json_encode(['tipe'=>'rapor','rapor_id'=>$rid])];
            $out=$repo->enqueue($row);
            // Unlike legacy emit(), the mandatory outbox participates in failure/rollback.
            if($out===null&&!$this->one('SELECT id FROM notifikasi_outbox WHERE event_key=? AND kanal=? AND penerima_user_id=?','ssi',[$row['event_key'],$channel,$id]))throw new V4Exception('Outbox gagal; penerbitan dibatalkan.',503);
        }}
    }
    public static function assertEditable(mysqli $db,int $sid,int $year): void
    {
        $s=$db->prepare("SELECT id FROM v4_rapor WHERE santri_id=? AND tahun_ajaran_id=? AND status='Terbit'");$s->bind_param('ii',$sid,$year);$s->execute();$r=$s->get_result()->fetch_assoc();$s->close();
        if($r)throw new V4Exception('Rapor sudah terbit. Pendidikan harus membuka kembali dengan alasan sebelum koreksi.',409);
    }
    private function parentAccess(array $u): void
    {
        $r=$this->one("SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id AND r.slug='orang_tua' WHERE u.id=? AND u.is_active=1",'i',[(int)($u['id']??0)]);
        if(!$r)throw new V4Exception('Akun wali aktif diperlukan.',403);
    }
    public function listing(array $u): array
    {
        $this->parentAccess($u);
        return $this->q("SELECT DISTINCT r.id,r.status,r.current_version,s.nama_santri,t.tahun,t.semester FROM v4_rapor r JOIN santri s ON s.id=r.santri_id JOIN tahun_ajaran t ON t.id=r.tahun_ajaran_id JOIN santri_wali sw ON sw.santri_id=r.santri_id AND sw.archived_at IS NULL JOIN wali w ON w.id=sw.wali_id AND w.is_active=1 AND w.archived_at IS NULL JOIN users u ON u.wali_id=w.id AND u.is_active=1 WHERE u.id=? AND r.current_version>0 ORDER BY r.id DESC LIMIT 200",'i',[(int)$u['id']]);
    }
    public function show(array $u,int $rid,bool $staff=false): array
    {
        $r=$this->one('SELECT * FROM v4_rapor WHERE id=?','i',[$rid]);
        if(!$r)throw new V4Exception('Rapor tidak dapat diakses.',403);
        $versions=$this->q('SELECT id,version,status,snapshot_json,pdf_private_path,pdf_sha256,diterbitkan_pada FROM v4_rapor_versi WHERE rapor_id=? ORDER BY version DESC','i',[$rid]);
        if(!$versions)throw new V4Exception('Rapor tidak dapat diakses.',403);
        if($staff){$snap=json_decode($versions[0]['snapshot_json'],true,512,JSON_THROW_ON_ERROR);(new SemesterScope($this->db))->education($u,['kelas_id'=>$snap['kelas_id'],'tahun_ajaran_id'=>$r['tahun_ajaran_id']],PenugasanJenis::CAP_RAPOR_CETAK);}
        else{$this->parentAccess($u);if(!in_array((int)$u['id'],(new RecipientResolver($this->db))->waliSantri((int)$r['santri_id']),true))throw new V4Exception('Rapor tidak dapat diakses.',403);}
        $history=$this->q('SELECT tindakan,version,created_at'.($staff?',alasan,actor_user_id,kapasitas':'').' FROM v4_rapor_operasi WHERE rapor_id=? ORDER BY id','i',[$rid]);
        $out=['id'=>$rid,'status'=>$r['status'],'current_version'=>(int)$r['current_version'],'riwayat'=>$history,'versi'=>[]];
        foreach($versions as $v){$item=['id'=>(int)$v['id'],'version'=>(int)$v['version'],'status'=>$v['status'],'diterbitkan_pada'=>$v['diterbitkan_pada']];
            if($staff||($v['status']==='Terbit'&&$r['status']==='Terbit'))$item['snapshot']=json_decode($v['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
            $out['versi'][]=$item;
        }return $out;
    }
    public function pdf(array $u,int $rid,int $version,bool $staff=false): string
    {
        $out=$this->show($u,$rid,$staff);
        $v=$this->one('SELECT * FROM v4_rapor_versi WHERE rapor_id=? AND version=?','ii',[$rid,$version]);
        if(!$v||(!$staff&&($out['status']!=='Terbit'||$v['status']!=='Terbit')))throw new V4Exception('Versi ini tidak tersedia untuk unduhan baru.',403);
        return $this->storage->read($v);
    }
    public static function stream(string $bytes,int $rid,int $version): never
    {
        header('Cache-Control: private, no-store');header('Pragma: no-cache');header('X-Content-Type-Options: nosniff');header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="rapor-'.$rid.'-v'.$version.'.pdf"');header('Content-Length: '.strlen($bytes));echo $bytes;exit;
    }
}
