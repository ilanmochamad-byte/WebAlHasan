<?php
declare(strict_types=1);
namespace App\V4;
use App\Audit\AuditLogger;
use App\Auth\Capabilities;
use App\Penugasan\PenugasanJenis;
use mysqli;
use Throwable;

final class SikapService
{
    public const PREDIKAT=['Sangat Baik','Baik','Cukup','Perlu Bimbingan'];
    private BahanRepository $repo; private SemesterScope $scope;
    public function __construct(private mysqli $db, private AuditLogger $audit){$this->repo=new BahanRepository($db,$audit);$this->scope=new SemesterScope($db);}
    private function allowed(array $u,array $p,int $sid,string $mode): void
    {
        $this->scope->student($p,$sid);
        if($mode==='pendidikan'){$this->scope->education($u,$p);return;}
        if(!in_array($mode,['pembimbing','murobi'],true)||!$this->scope->operator($u,$p,$sid,$mode))throw new V4Exception('Penugasan tidak mencakup santri dan semester ini.',403);
    }
    public function options(array $u,string $mode='pembimbing'): array
    {
        $cap=match($mode){'pembimbing'=>PenugasanJenis::CAP_SIKAP_INPUT,'murobi'=>PenugasanJenis::CAP_SIKAP_MUROBI,'pendidikan'=>PenugasanJenis::CAP_RAPOR_VERIFIKASI,default=>''};
        if(!(new Capabilities($this->db))->hasFeature($u,$cap))throw new V4Exception('Akses sikap ditolak.',403);
        $rows=$this->repo->query("SELECT DISTINCT r.id AS rencana_id,r.tahun_ajaran_id,r.kelas_id,s.id AS santri_id,s.nama_santri,k.nama_kelas,t.tahun,t.semester FROM v4_rencana r JOIN kelas k ON k.id=r.kelas_id JOIN tahun_ajaran t ON t.id=r.tahun_ajaran_id JOIN plotting_kelas pk ON pk.id_kelas=r.kelas_id AND pk.id_tahun=r.tahun_ajaran_id JOIN santri s ON s.id=pk.id_santri ORDER BY t.id DESC,k.nama_kelas,s.nama_santri");
        return array_values(array_filter($rows,function($r)use($u,$mode){try{$p=$this->scope->plan((int)$r['rencana_id']);$this->allowed($u,$p,(int)$r['santri_id'],$mode);return true;}catch(V4Exception){return false;}}));
    }
    public function read(array $u,int $plan,int $sid,string $mode='pembimbing'): array
    {
        $p=$this->scope->plan($plan);$this->allowed($u,$p,$sid,$mode);
        $row=$this->repo->query('SELECT * FROM v4_sikap WHERE santri_id=? AND tahun_ajaran_id=?','ii',[$sid,(int)$p['tahun_ajaran_id']])[0]??null;
        if($row&&(int)$row['kelas_id']!==(int)$p['kelas_id'])throw new V4Exception('Sikap tersimpan pada kelas asal; perlu pemeriksaan Pendidikan.',409);
        $history=$row?$this->repo->query('SELECT * FROM v4_sikap_riwayat WHERE sikap_id=? ORDER BY version DESC','i',[(int)$row['id']]):[];
        return ['rencana'=>$p,'sikap'=>$row,'riwayat'=>$history,'murobi_aktif'=>$this->scope->murobis($p,$sid),'pemeriksaan'=>$this->repo->state($plan,$sid,'sikap',0,$row?[$row]:[],$row['status']??'Draf'),'riwayat_pemeriksaan'=>$this->repo->history($plan,$sid,'sikap',0)];
    }
    public function save(array $u,array $in,string $mode='pembimbing'): array
    {
        $plan=(int)($in['rencana_id']??0);$sid=(int)($in['santri_id']??0);
        $p=$this->scope->plan($plan);$this->allowed($u,$p,$sid,$mode);
        if(!in_array($mode,['pembimbing','murobi'],true))throw new V4Exception('Pendidikan hanya memeriksa bahan sikap.',403);
        $action=$in['action']??'draf';$reason=$in['alasan']??'';
        $version=filter_var($in['version']??null,FILTER_VALIDATE_INT);
        if($version===false||$version<0||!is_string($reason)||mb_strlen($reason)>1000)throw new V4Exception('Versi atau alasan tidak valid.');
        if(!in_array($action,$mode==='murobi'?['diketahui','koreksi']:['draf','serahkan','koreksi'],true))throw new V4Exception('Tindakan sikap tidak valid.');
        if($action==='koreksi'&&trim($reason)==='')throw new V4Exception('Alasan koreksi wajib diisi.');
        if($action!=='diketahui'){
            foreach(['adab','kedisiplinan','tanggung_jawab'] as $key)if(!in_array($in[$key]??null,self::PREDIKAT,true))throw new V4Exception('Isi tiga predikat sikap yang sah.');
            if(!is_string($in['catatan']??'')||mb_strlen($in['catatan']??'')>2000)throw new V4Exception('Catatan maksimal 2000 karakter.');
        }
        $this->db->begin_transaction();
        try{
            $this->repo->query('SELECT id FROM v4_rencana WHERE id=? FOR UPDATE','i',[$plan]);
            $p=$this->scope->plan($plan);$this->allowed($u,$p,$sid,$mode);
            $old=$this->repo->query('SELECT * FROM v4_sikap WHERE santri_id=? AND tahun_ajaran_id=? FOR UPDATE','ii',[$sid,(int)$p['tahun_ajaran_id']])[0]??null;
            if($version!==(int)($old['version']??0))throw new V4Exception('Versi usang. Muat ulang sikap.',409);
            if($old&&(int)$old['kelas_id']!==(int)$p['kelas_id'])throw new V4Exception('Sikap tersimpan pada kelas asal.',409);
            if($mode==='murobi'&&!$old)throw new V4Exception('Pembimbing belum mengisi sikap.',409);
            if($mode==='pembimbing'&&$old&&$action!=='koreksi'){
                foreach(['adab','kedisiplinan','tanggung_jawab','catatan'] as $key)if((string)$old[$key]!==($in[$key]??''))throw new V4Exception('Perubahan sikap memerlukan koreksi beralasan.');
                if($old['status']==='Dikirim')throw new V4Exception('Sikap sudah diserahkan; gunakan koreksi beralasan.',409);
            }
            $uid=(int)$u['id'];$ver=$version+1;
            if($action==='diketahui'){
                $state=$this->repo->state($plan,$sid,'sikap',0,[$old],$old['status']);
                if($state['status']==='Dikembalikan')throw new V4Exception('Sikap dikembalikan memerlukan koreksi beralasan.',409);
                // Tanda dari murobi yang tidak lagi sah tidak mengunci murobi pengganti; riwayat tetap menyimpan tanda lama.
                if($old['diketahui_murobi_user_id']!==null&&in_array((int)$old['diketahui_murobi_user_id'],$this->scope->murobis($p,$sid),true))throw new V4Exception('Sikap versi ini sudah diketahui murobi.',409);
                $this->repo->query('UPDATE v4_sikap SET diketahui_murobi_user_id=?,diketahui_pada=NOW(),version=? WHERE id=?','iii',[$uid,$ver,(int)$old['id']]);
            }else{
                $status=$action==='serahkan'||($mode==='murobi'&&$old['status']==='Dikirim')?'Dikirim':'Draf';
                $known=$mode==='murobi'?$uid:null;
                if($old){
                    if($old['adab']===$in['adab']&&$old['kedisiplinan']===$in['kedisiplinan']&&$old['tanggung_jawab']===$in['tanggung_jawab']&&(string)$old['catatan']===($in['catatan']??'')&&$old['status']===$status)throw new V4Exception('Tidak ada perubahan untuk disimpan.');
                    // Pembimbing pertama tetap tercatat; setiap revisi menyimpan aktor sebenarnya.
                    $this->repo->query('UPDATE v4_sikap SET adab=?,kedisiplinan=?,tanggung_jawab=?,catatan=?,status=?,diketahui_murobi_user_id=?,diketahui_pada=IF(? IS NULL,NULL,NOW()),version=? WHERE id=?','sssssiiii',[$in['adab'],$in['kedisiplinan'],$in['tanggung_jawab'],$in['catatan']??'',$status,$known,$known,$ver,(int)$old['id']]);
                }else{
                    $this->repo->query('INSERT INTO v4_sikap (santri_id,tahun_ajaran_id,kelas_id,adab,kedisiplinan,tanggung_jawab,catatan,pembimbing_user_id,status) VALUES (?,?,?,?,?,?,?,?,?)','iiissssis',[$sid,(int)$p['tahun_ajaran_id'],(int)$p['kelas_id'],$in['adab'],$in['kedisiplinan'],$in['tanggung_jawab'],$in['catatan']??'',$uid,$status]);
                }
            }
            $row=$this->repo->query('SELECT * FROM v4_sikap WHERE santri_id=? AND tahun_ajaran_id=?','ii',[$sid,(int)$p['tahun_ajaran_id']])[0];$id=(int)$row['id'];
            $this->repo->query('INSERT INTO v4_sikap_riwayat (sikap_id,version,snapshot_json,actor_user_id,kapasitas,alasan) VALUES (?,?,?,?,?,?)','iisiss',[$id,$ver,json_encode($row,JSON_THROW_ON_ERROR),$uid,$mode,$reason]);
            if(!$this->audit->log('v4.sikap.'.$action,'v4_sikap',$id,$old?['version'=>$version]:null,['version'=>$ver,'kapasitas'=>$mode,'alasan_tercatat'=>$reason!==''],$uid))throw new V4Exception('Audit gagal; perubahan dibatalkan.',503);
            $this->repo->write($plan,$sid,'sikap',0,[$row],$row['status'],$uid,$mode,$reason);
            $result=$this->read($u,$plan,$sid,$mode);$this->db->commit();return $result;
        }catch(Throwable $e){$this->db->rollback();if($e instanceof V4Exception)throw $e;
            if(in_array((int)$e->getCode(),[1062,1213,1205],true))throw new V4Exception('Konflik penyimpanan. Muat ulang sikap.',409);
            throw new V4Exception('Penyimpanan gagal; perubahan dibatalkan.',503);
        }
    }
}
