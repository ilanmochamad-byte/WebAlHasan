<?php
declare(strict_types=1);
namespace App\V4;
use App\Audit\AuditLogger;
use mysqli;

/** Dipanggil di dalam transaksi dengan kunci parent v4_rencana. */
final class BahanRepository
{
    public function __construct(private mysqli $db, private AuditLogger $audit) {}
    public function query(string $sql, string $types='', array $args=[]): array
    {
        $s=$this->db->prepare($sql);
        if(!$s)throw new V4Exception('Basis data tidak tersedia.',503);
        if($types!=='')$s->bind_param($types,...$args);
        if(!$s->execute()){ $code=$s->errno; $s->close(); throw new V4Exception('Perubahan gagal disimpan.',in_array($code,[1062,1020,1213,1205],true)?409:503,$code); }
        $r=$s->get_result();$rows=$r?$r->fetch_all(MYSQLI_ASSOC):[];$s->close();return $rows;
    }
    public static function fingerprint(array $source): string
    {
        return hash('sha256',json_encode($source,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
    }
    public function state(int $plan,int $sid,string $kind,int $key,array $source,string $initial='Draf'): array
    {
        $row=$this->query('SELECT * FROM v4_bahan WHERE rencana_id=? AND santri_id=? AND jenis=? AND mapel_key=?','iisi',[$plan,$sid,$kind,$key])[0]??null;
        $hash=self::fingerprint($source);
        return ['id'=>(int)($row['id']??0),'version'=>(int)($row['version']??0),
            'status'=>$row?($row['sumber_hash']===$hash?$row['status']:'Draf'):$initial,
            'perlu_verifikasi_ulang'=>$row!==null&&$row['sumber_hash']!==$hash,
            'sumber_hash'=>$hash,'alasan'=>$row['alasan']??null];
    }
    public function history(int $plan,int $sid,string $kind,int $key): array
    {
        return $this->query('SELECT h.* FROM v4_bahan_riwayat h JOIN v4_bahan b ON b.id=h.bahan_id WHERE b.rencana_id=? AND b.santri_id=? AND b.jenis=? AND b.mapel_key=? ORDER BY h.version DESC','iisi',[$plan,$sid,$kind,$key]);
    }
    public function write(int $plan,int $sid,string $kind,int $key,array $source,string $status,int $uid,string $capacity,string $reason=''): void
    {
        $old=$this->query('SELECT * FROM v4_bahan WHERE rencana_id=? AND santri_id=? AND jenis=? AND mapel_key=? FOR UPDATE','iisi',[$plan,$sid,$kind,$key])[0]??null;
        $hash=self::fingerprint($source);$version=(int)($old['version']??0)+1;
        if($old){$id=(int)$old['id'];$this->query('UPDATE v4_bahan SET status=?,sumber_hash=?,version=?,actor_user_id=?,kapasitas=?,alasan=? WHERE id=?','ssiissi',[$status,$hash,$version,$uid,$capacity,$reason,$id]);}
        else{$this->query('INSERT INTO v4_bahan (rencana_id,santri_id,jenis,mapel_key,status,sumber_hash,version,actor_user_id,kapasitas,alasan) VALUES (?,?,?,?,?,?,?,?,?,?)','iisissiiss',[$plan,$sid,$kind,$key,$status,$hash,$version,$uid,$capacity,$reason]);$id=(int)$this->db->insert_id;}
        $row=$this->query('SELECT * FROM v4_bahan WHERE id=?','i',[$id])[0];
        if($kind==='kehadiran')$row['snapshot_kehadiran']=['sumber'=>$source,'dihitung_pada'=>date(DATE_ATOM)];
        $this->query('INSERT INTO v4_bahan_riwayat (bahan_id,version,snapshot_json) VALUES (?,?,?)','iis',[$id,$version,json_encode($row,JSON_THROW_ON_ERROR)]);
        if(!$this->audit->log('v4.bahan.'.$status,'v4_bahan',$id,$old?['version'=>(int)$old['version']]:null,['version'=>$version,'status'=>$status,'kapasitas'=>$capacity,'alasan_tercatat'=>$reason!==''],$uid))throw new V4Exception('Audit gagal; perubahan dibatalkan.',503);
    }
}
