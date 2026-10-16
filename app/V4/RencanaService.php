<?php
declare(strict_types=1);
namespace App\V4;

use App\Audit\AuditLogger;
use App\Auth\Capabilities;
use App\Penugasan\PenugasanJenis;
use mysqli;
use mysqli_stmt;
use Throwable;

final class RencanaService {
    public function __construct(private mysqli $db, private Capabilities $caps, private AuditLogger $audit) {}

    private function run(mysqli_stmt $statement): void {
        if ($statement->execute()) return;
        if ($statement->errno===1062) throw new V4Exception('Rencana atau mata pelajaran ganda.',409);
        throw new V4Exception('Operasi basis data gagal; perubahan dibatalkan.',503);
    }

    private function allowed(array $user,int $tahun,int $kelas): void {
        if (!$this->caps->featureAppliesToKelas($user,PenugasanJenis::CAP_RAPOR_RENCANA,$kelas,$tahun))
            throw new V4Exception('Penugasan Bagian Pendidikan untuk kelas dan semester ini diperlukan.',403);
    }
    private function one(string $sql,string $types='',array $params=[]): ?array {
        $s=$this->db->prepare($sql);
        if (!$s) throw new V4Exception('Kueri tidak tersedia.',503);
        if ($types!=='') $s->bind_param($types,...$params);
        $this->run($s); $r=$s->get_result()->fetch_assoc(); $s->close(); return $r?:null;
    }
    private function rows(string $sql,string $types='',array $params=[]): array {
        $s=$this->db->prepare($sql);
        if (!$s) throw new V4Exception('Kueri tidak tersedia.',503);
        if ($types!=='') $s->bind_param($types,...$params);
        $this->run($s); $r=$s->get_result()->fetch_all(MYSQLI_ASSOC); $s->close(); return $r;
    }
    private function validScope(int $tahun,int $kelas): void {
        if ($tahun<1 || $kelas<1 || !$this->one('SELECT id FROM tahun_ajaran WHERE id=? AND archived_at IS NULL','i',[$tahun]) || !$this->one('SELECT id FROM kelas WHERE id=? AND is_active=1 AND archived_at IS NULL','i',[$kelas])) throw new V4Exception('Semester atau kelas tidak tersedia.');
    }
    public function options(array $user): array {
        if (!$this->caps->hasFeature($user,PenugasanJenis::CAP_RAPOR_RENCANA)) throw new V4Exception('Akses ditolak.',403);
        $years=$this->rows('SELECT id,tahun,semester,status FROM tahun_ajaran WHERE archived_at IS NULL ORDER BY id DESC');
        $classes=$this->rows('SELECT id,nama_kelas,jenjang FROM kelas WHERE is_active=1 AND archived_at IS NULL ORDER BY nama_kelas');
        $classes=array_values(array_filter($classes,fn($k)=>array_filter($years,fn($y)=>$this->caps->featureAppliesToKelas($user,PenugasanJenis::CAP_RAPOR_RENCANA,(int)$k['id'],(int)$y['id']))!==[]));
        $years=array_values(array_filter($years,fn($y)=>array_filter($classes,fn($k)=>$this->caps->featureAppliesToKelas($user,PenugasanJenis::CAP_RAPOR_RENCANA,(int)$k['id'],(int)$y['id']))!==[]));
        $assignments=$this->rows("SELECT a.id,a.tahun_ajaran_id,a.kelas_id,a.mata_pelajaran_id,g.nama_guru,mp.nama AS mata_pelajaran FROM guru_mapel_assignments a JOIN guru g ON g.id=a.guru_id AND g.is_active=1 AND g.archived_at IS NULL JOIN mata_pelajaran mp ON mp.id=a.mata_pelajaran_id AND mp.is_active=1 AND mp.archived_at IS NULL JOIN tahun_ajaran t ON t.id=a.tahun_ajaran_id AND t.status='Aktif' WHERE a.is_active=1 AND a.tanggal_mulai<=CURDATE() AND (a.tanggal_selesai IS NULL OR a.tanggal_selesai>=CURDATE()) ORDER BY mp.nama,g.nama_guru");
        $assignments=array_values(array_filter($assignments,fn($a)=>$this->caps->featureAppliesToKelas($user,PenugasanJenis::CAP_RAPOR_RENCANA,(int)$a['kelas_id'],(int)$a['tahun_ajaran_id'])));
        return ['tahun_ajaran'=>$years,'kelas'=>$classes,'mata_pelajaran'=>$this->rows('SELECT id,nama,kode FROM mata_pelajaran WHERE is_active=1 AND archived_at IS NULL ORDER BY nama'),'guru_assignments'=>$assignments,'penanda_tangan'=>$this->rows('SELECT id,jabatan,nama,gelar FROM v4_penanda_tangan WHERE is_active=1 ORDER BY jabatan,nama'),'aset'=>$this->rows('SELECT a.id,a.penanda_tangan_id,p.jabatan,p.nama FROM v4_tanda_tangan_aset a JOIN v4_penanda_tangan p ON p.id=a.penanda_tangan_id WHERE a.is_active=1 AND p.is_active=1 AND (a.berlaku_mulai IS NULL OR a.berlaku_mulai<=CURDATE()) AND (a.berlaku_selesai IS NULL OR a.berlaku_selesai>=CURDATE()) ORDER BY a.id DESC')];
    }
    public function read(array $user,int $tahun,int $kelas): array {
        $this->validScope($tahun,$kelas); $this->allowed($user,$tahun,$kelas);
        $plan=$this->one('SELECT r.*,k.nama_kelas,t.tahun,t.semester FROM v4_rencana r JOIN kelas k ON k.id=r.kelas_id JOIN tahun_ajaran t ON t.id=r.tahun_ajaran_id WHERE r.tahun_ajaran_id=? AND r.kelas_id=?','ii',[$tahun,$kelas]);
        if (!$plan) return ['rencana'=>null,'mapel'=>[]];
        $subjects=$this->rows('SELECT rm.id,rm.mata_pelajaran_id,mp.nama AS mata_pelajaran,rm.guru_mapel_assignment_id,rm.wajib,rm.target_ketuntasan,rm.bobot_harian,rm.bobot_tengah,rm.bobot_akhir FROM v4_rencana_mapel rm JOIN mata_pelajaran mp ON mp.id=rm.mata_pelajaran_id WHERE rm.rencana_id=? ORDER BY mp.nama','i',[(int)$plan['id']]);
        return ['rencana'=>$plan,'mapel'=>$subjects];
    }
    private function checkSigner(?int $signer,?int $asset,string $jabatan): void {
        if ($signer===null && $asset===null) return;
        if ($signer===null || $asset===null) throw new V4Exception('Penanda tangan dan aset aktif harus dipilih bersama.');
        $row=$this->one('SELECT a.id FROM v4_tanda_tangan_aset a JOIN v4_penanda_tangan p ON p.id=a.penanda_tangan_id WHERE a.id=? AND p.id=? AND p.jabatan=? AND p.is_active=1 AND a.is_active=1 AND (a.berlaku_mulai IS NULL OR a.berlaku_mulai<=CURDATE()) AND (a.berlaku_selesai IS NULL OR a.berlaku_selesai>=CURDATE())','iis',[$asset,$signer,$jabatan]);
        if (!$row) throw new V4Exception('Aset tanda tangan tidak aktif, kedaluwarsa, atau tidak cocok.');
    }
    public function save(array $user,array $input): array {
        $tahun=filter_var($input['tahun_ajaran_id']??null,FILTER_VALIDATE_INT);
        $kelas=filter_var($input['kelas_id']??null,FILTER_VALIDATE_INT);
        if (!$tahun || !$kelas) throw new V4Exception('Semester dan kelas wajib dipilih.');
        $this->validScope($tahun,$kelas); $this->allowed($user,$tahun,$kelas);
        $mapel=$input['mapel']??null;
        if (!is_array($mapel) || count($mapel)<1 || count($mapel)>100) throw new V4Exception('Pilih satu atau lebih mata pelajaran wajib.');
        $ids=[];$data=[];
        foreach ($mapel as $item) {
            if (!is_array($item)) throw new V4Exception('Baris mapel tidak valid.');
            $id=filter_var($item['mata_pelajaran_id']??null,FILTER_VALIDATE_INT);
            $target=filter_var($item['target_ketuntasan']??70,FILTER_VALIDATE_FLOAT);
            $h=filter_var($item['bobot_harian']??40,FILTER_VALIDATE_INT);$t=filter_var($item['bobot_tengah']??30,FILTER_VALIDATE_INT);$a=filter_var($item['bobot_akhir']??30,FILTER_VALIDATE_INT);
            if (!$id || $target===false || $target<0 || $target>100 || $h!==40 || $t!==30 || $a!==30 || $h+$t+$a!==100) throw new V4Exception('Mapel, target, atau bobot tidak valid (40/30/30).');
            if (isset($ids[$id])) throw new V4Exception('Mata pelajaran wajib ganda pada kelas dan semester ini.',409);
            $ids[$id]=true;
            if (!$this->one('SELECT id FROM mata_pelajaran WHERE id=? AND is_active=1 AND archived_at IS NULL','i',[$id])) throw new V4Exception('Mata pelajaran tidak aktif.');
            $assignment=isset($item['guru_mapel_assignment_id']) && $item['guru_mapel_assignment_id']!=='' ? filter_var($item['guru_mapel_assignment_id'],FILTER_VALIDATE_INT) : null;
            if ($assignment!==null && (!$assignment || !$this->one("SELECT a.id FROM guru_mapel_assignments a JOIN guru g ON g.id=a.guru_id AND g.is_active=1 AND g.archived_at IS NULL JOIN tahun_ajaran t ON t.id=a.tahun_ajaran_id AND t.status='Aktif' WHERE a.id=? AND a.kelas_id=? AND a.tahun_ajaran_id=? AND a.mata_pelajaran_id=? AND a.is_active=1 AND a.tanggal_mulai<=CURDATE() AND (a.tanggal_selesai IS NULL OR a.tanggal_selesai>=CURDATE())",'iiii',[$assignment,$kelas,$tahun,$id]))) throw new V4Exception('Penugasan guru tidak cocok atau tidak aktif.');
            $data[]=['id'=>$id,'target'=>$target,'assignment'=>$assignment];
        }
        $p=isset($input['pendidikan_penanda_id'])&&$input['pendidikan_penanda_id']!==''?(int)$input['pendidikan_penanda_id']:null;
        $pa=isset($input['pendidikan_aset_id'])&&$input['pendidikan_aset_id']!==''?(int)$input['pendidikan_aset_id']:null;
        $l=isset($input['pimpinan_penanda_id'])&&$input['pimpinan_penanda_id']!==''?(int)$input['pimpinan_penanda_id']:null;
        $la=isset($input['pimpinan_aset_id'])&&$input['pimpinan_aset_id']!==''?(int)$input['pimpinan_aset_id']:null;
        $this->checkSigner($p,$pa,'Pendidikan');$this->checkSigner($l,$la,'Pimpinan');
        $tempat=trim((string)($input['tempat']??''));if (mb_strlen($tempat)>120) throw new V4Exception('Tempat terlalu panjang.');
        $this->db->begin_transaction();
        try {
            $old=$this->one('SELECT * FROM v4_rencana WHERE tahun_ajaran_id=? AND kelas_id=? FOR UPDATE','ii',[$tahun,$kelas]);
            if ($old) {
                $rencanaId=(int)$old['id'];
                $existing=$this->rows('SELECT id,mata_pelajaran_id,target_ketuntasan FROM v4_rencana_mapel WHERE rencana_id=? FOR UPDATE','i',[$rencanaId]);
                foreach($existing as $row) if (!isset($ids[(int)$row['mata_pelajaran_id']])) throw new V4Exception('Penghapusan mapel rencana belum didukung pada Fase 1.',409);
                $before=['version'=>(int)$old['version'],'mapel'=>array_map(fn($r)=>['mata_pelajaran_id'=>(int)$r['mata_pelajaran_id'],'target_ketuntasan'=>(float)$r['target_ketuntasan']],$existing),'penanda_ids'=>[$old['pendidikan_penanda_id'],$old['pimpinan_penanda_id']],'aset_ids'=>[$old['pendidikan_aset_id'],$old['pimpinan_aset_id']]];
                $s=$this->db->prepare('UPDATE v4_rencana SET pendidikan_penanda_id=?,pendidikan_aset_id=?,pimpinan_penanda_id=?,pimpinan_aset_id=?,tempat=?,version=version+1,updated_by=? WHERE id=?');
                $uid=(int)$user['id'];$s->bind_param('iiiisii',$p,$pa,$l,$la,$tempat,$uid,$rencanaId);$this->run($s);$s->close();
            } else {
                $before=null;$uid=(int)$user['id'];
                $s=$this->db->prepare('INSERT INTO v4_rencana (tahun_ajaran_id,kelas_id,pendidikan_penanda_id,pendidikan_aset_id,pimpinan_penanda_id,pimpinan_aset_id,tempat,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?)');
                $s->bind_param('iiiiiisii',$tahun,$kelas,$p,$pa,$l,$la,$tempat,$uid,$uid);$this->run($s);$rencanaId=$this->db->insert_id;$s->close();
            }
            foreach($data as $row) {
                $existing=$this->one('SELECT id FROM v4_rencana_mapel WHERE rencana_id=? AND mata_pelajaran_id=?','ii',[$rencanaId,$row['id']]);
                $assignment=$row['assignment'];$target=$row['target'];$id=$row['id'];
                if($existing) {
                    if($this->one('SELECT id FROM v4_nilai WHERE rencana_mapel_id=? LIMIT 1','i',[(int)$existing['id']])) throw new V4Exception('Rencana yang sudah memiliki nilai tidak dapat diubah.',409);
                    $s=$this->db->prepare('UPDATE v4_rencana_mapel SET target_ketuntasan=?,guru_mapel_assignment_id=? WHERE id=?');$rid=(int)$existing['id'];$s->bind_param('dii',$target,$assignment,$rid);
                } else {
                    $s=$this->db->prepare('INSERT INTO v4_rencana_mapel (rencana_id,mata_pelajaran_id,guru_mapel_assignment_id,target_ketuntasan) VALUES (?,?,?,?)');$s->bind_param('iiid',$rencanaId,$id,$assignment,$target);
                }
                $this->run($s);$s->close();
            }
            $after=['tahun_ajaran_id'=>$tahun,'kelas_id'=>$kelas,'mapel'=>$data,'bobot'=>[40,30,30],'version'=>$old?(int)$old['version']+1:1,'penanda_ids'=>[$p,$l],'aset_ids'=>[$pa,$la]];
            if (!$this->audit->log($old?'v4.rencana.ubah':'v4.rencana.buat','v4_rencana',$rencanaId,$before,$after,(int)$user['id'])) throw new V4Exception('Audit gagal; perubahan dibatalkan.',503);
            $this->db->commit();return $this->read($user,$tahun,$kelas);
        } catch(Throwable $e) { $this->db->rollback();if ($e instanceof V4Exception) throw $e; if ($e instanceof \mysqli_sql_exception && (int)$e->getCode()===1062) throw new V4Exception('Rencana atau mata pelajaran ganda.',409);throw $e; }
    }
    public function saveSigner(array $user,array $input,?array $file=null): int {
        $this->requireSignerAdmin($user);
        $jabatan=(string)($input['jabatan']??'');$nama=trim((string)($input['nama']??''));$gelar=trim((string)($input['gelar']??''));
        if (!in_array($jabatan,['Pendidikan','Pimpinan'],true)||$nama===''||mb_strlen($nama)>150||mb_strlen($gelar)>100) throw new V4Exception('Identitas penanda tangan tidak valid.');
        if (!$file || ($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name']) || (int)$file['size']<1 || (int)$file['size']>2097152) throw new V4Exception('Unggah gambar tanda tangan maksimal 2 MB.');
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);$dimensions=@getimagesize($file['tmp_name']);
        if(!in_array($mime,['image/png','image/jpeg','image/webp'],true) || $dimensions===false || $dimensions[0]>3000 || $dimensions[1]>3000) throw new V4Exception('Gambar tanda tangan harus PNG, JPEG, atau WebP dengan ukuran maksimal 3000 × 3000 piksel.');
        $dir=APP_ROOT.'/storage/private/v4';$name=bin2hex(random_bytes(20));$path=$dir.'/'.$name; $hash=hash_file('sha256',$file['tmp_name']);
        if (!move_uploaded_file($file['tmp_name'],$path)) throw new V4Exception('Gagal menyimpan gambar privat.',503);
        chmod($path,0600);
        $this->db->begin_transaction();
        try { $uid=(int)$user['id'];$s=$this->db->prepare('INSERT INTO v4_penanda_tangan (jabatan,nama,gelar,created_by,updated_by) VALUES (?,?,?,?,?)');$s->bind_param('sssii',$jabatan,$nama,$gelar,$uid,$uid);$this->run($s);$signer=$this->db->insert_id;$s->close();
            $s=$this->db->prepare('INSERT INTO v4_tanda_tangan_aset (penanda_tangan_id,private_path,sha256,mime_type,uploaded_by,activated_by) VALUES (?,?,?,?,?,?)');$s->bind_param('isssii',$signer,$name,$hash,$mime,$uid,$uid);$this->run($s);$asset=$this->db->insert_id;$s->close();
            if (!$this->audit->log('v4.penanda.buat','v4_penanda_tangan',$signer,null,['jabatan'=>$jabatan,'asset_id'=>$asset,'sha256'=>$hash],$uid)) throw new V4Exception('Audit gagal.',503);
            $this->db->commit();return $signer;
        } catch(Throwable $e) {$this->db->rollback();@unlink($path);throw $e;}
    }
    private function requireSignerAdmin(array $user): void {
        if(!in_array($this->caps->featureSource($user,PenugasanJenis::CAP_RAPOR_RENCANA),[Capabilities::SUMBER_ADMIN,Capabilities::SUMBER_KEDUANYA],true)) throw new V4Exception('Hanya admin dapat mengelola penanda tangan.',403);
    }
    public function setSignerActive(array $user,int $id,bool $active): void {
        $this->requireSignerAdmin($user);
        if($id<1)throw new V4Exception('ID penanda tangan tidak valid.');
        $this->db->begin_transaction();
        try {
            $old=$this->one('SELECT id,is_active FROM v4_penanda_tangan WHERE id=? FOR UPDATE','i',[$id]);
            if(!$old)throw new V4Exception('Penanda tangan tidak ditemukan.',404);
            $value=$active?1:0;$uid=(int)$user['id'];
            $s=$this->db->prepare('UPDATE v4_penanda_tangan SET is_active=?,updated_by=? WHERE id=?');$s->bind_param('iii',$value,$uid,$id);$this->run($s);$s->close();
            if(!$this->audit->log('v4.penanda.status','v4_penanda_tangan',$id,['is_active'=>(int)$old['is_active']],['is_active'=>$value],$uid))throw new V4Exception('Audit gagal.',503);
            $this->db->commit();
        }catch(Throwable $e){$this->db->rollback();throw $e;}
    }
    public function signers(array $user): array {
        $this->requireSignerAdmin($user);
        return $this->rows('SELECT id,jabatan,nama,gelar,is_active FROM v4_penanda_tangan ORDER BY id DESC LIMIT 100');
    }
}
