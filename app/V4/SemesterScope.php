<?php
declare(strict_types=1);
namespace App\V4;
use App\Auth\Capabilities;
use App\Penugasan\PenugasanJenis;
use mysqli;

/** Hak saat ini; ID pengisi pada snapshot tidak pernah dipakai sebagai pemberi akses. */
final class SemesterScope
{
    private BahanRepository $repo;
    public function __construct(private mysqli $db){$this->repo=new BahanRepository($db,new \App\Audit\AuditLogger($db));}
    public function plan(int $id): array
    {
        $p=$this->repo->query("SELECT r.*,k.nama_kelas,t.tahun,t.semester FROM v4_rencana r JOIN kelas k ON k.id=r.kelas_id AND k.is_active=1 AND k.archived_at IS NULL JOIN tahun_ajaran t ON t.id=r.tahun_ajaran_id AND t.status='Aktif' AND t.archived_at IS NULL WHERE r.id=?",'i',[$id])[0]??null;
        if(!$p)throw new V4Exception('Rencana kelas dan semester tidak aktif atau di luar cakupan.',403);return $p;
    }
    public function student(array $p,int $sid): void
    {
        if(!$this->repo->query("SELECT s.id FROM santri s WHERE s.id=? AND s.is_active=1 AND s.archived_at IS NULL AND (EXISTS(SELECT 1 FROM plotting_kelas pk WHERE pk.id_santri=s.id AND pk.id_kelas=? AND pk.id_tahun=?) OR EXISTS(SELECT 1 FROM v4_nilai n JOIN v4_rencana_mapel rm ON rm.id=n.rencana_mapel_id WHERE n.santri_id=s.id AND rm.rencana_id=?) OR EXISTS(SELECT 1 FROM v4_sikap sk WHERE sk.santri_id=s.id AND sk.tahun_ajaran_id=? AND sk.kelas_id=?))",'iiiiii',[$sid,(int)$p['kelas_id'],(int)$p['tahun_ajaran_id'],(int)$p['id'],(int)$p['tahun_ajaran_id'],(int)$p['kelas_id']]))throw new V4Exception('Santri di luar kelas dan semester.',403);
    }
    public function education(array $u,array $p,string $cap=PenugasanJenis::CAP_RAPOR_VERIFIKASI): string
    {
        $caps=new Capabilities($this->db);
        if(!$caps->featureAppliesTo($u,$cap,['tahun_ajaran_id'=>(int)$p['tahun_ajaran_id'],'kelas_id'=>(int)$p['kelas_id']]))throw new V4Exception('Penugasan Pendidikan di luar cakupan.',403);
        return $caps->featureSource($u,$cap)===Capabilities::SUMBER_PENUGASAN?'pendidikan':'admin';
    }
    public function operator(array $u,array $p,int $sid,string $kind): bool
    {
        $cap=$kind==='pembimbing'?PenugasanJenis::CAP_SIKAP_INPUT:PenugasanJenis::CAP_SIKAP_MUROBI;
        $caps=new Capabilities($this->db);
        // Pengawasan admin tidak menggantikan pembimbing atau persetujuan murobi.
        $scopes=$caps->featureScopes($u,$cap);
        $placements=$this->repo->query("SELECT id_kelas AS kelas_id,NULL AS kamar_id FROM plotting_kelas WHERE id_santri=? AND id_tahun=? AND status='Aktif' UNION ALL SELECT NULL,id_kamar FROM plotting_kamar WHERE id_santri=? AND id_tahun=?",'iiii',[$sid,(int)$p['tahun_ajaran_id'],$sid,(int)$p['tahun_ajaran_id']]);
        foreach($scopes as $s){if((int)$s['tahun_ajaran_id']!==(int)$p['tahun_ajaran_id'])continue;
            foreach($placements as $v){if($s['kelas_id']!==null&&$v['kelas_id']!==null&&(int)$s['kelas_id']===(int)$v['kelas_id'])return true;
                if($s['kamar_id']!==null&&$v['kamar_id']!==null&&(int)$s['kamar_id']===(int)$v['kamar_id']&&$this->repo->query('SELECT id FROM kamar WHERE id=?','i',[(int)$v['kamar_id']]))return true;}
        }return false;
    }
    public function murobis(array $p,int $sid): array
    {
        $users=$this->repo->query('SELECT DISTINCT u.id FROM users u JOIN murobi_assignments a ON a.guru_id=u.guru_id WHERE a.tahun_ajaran_id=?','i',[(int)$p['tahun_ajaran_id']]);
        return array_values(array_map(fn($u)=>(int)$u['id'],array_filter($users,fn($u)=>$this->operator($u,$p,$sid,'murobi'))));
    }
}
