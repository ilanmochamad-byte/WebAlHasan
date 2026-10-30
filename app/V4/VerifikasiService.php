<?php
declare(strict_types=1);
namespace App\V4;
use App\Audit\AuditLogger;
use App\Auth\Capabilities;
use App\Penugasan\PenugasanJenis;
use mysqli;
use Throwable;

final class VerifikasiService
{
    private BahanRepository $repo; private SemesterScope $scope;
    public function __construct(private mysqli $db, private AuditLogger $audit){$this->repo=new BahanRepository($db,$audit);$this->scope=new SemesterScope($db);}
    public function options(array $u): array
    {
        if(!(new Capabilities($this->db))->hasFeature($u,PenugasanJenis::CAP_RAPOR_VERIFIKASI))throw new V4Exception('Akses verifikasi ditolak.',403);
        $rows=$this->repo->query('SELECT r.id,r.kelas_id,r.tahun_ajaran_id,k.nama_kelas,t.tahun,t.semester FROM v4_rencana r JOIN kelas k ON k.id=r.kelas_id JOIN tahun_ajaran t ON t.id=r.tahun_ajaran_id ORDER BY r.id DESC');
        return array_values(array_filter($rows,function($r)use($u){try{$p=$this->scope->plan((int)$r['id']);$this->scope->education($u,$p);return true;}catch(V4Exception){return false;}}));
    }
    public function attendance(array $p,int $sid): array
    {
        // Jadwal DAN snapshot harus sama semester; kelas snapshot tidak mengikuti perpindahan kelas.
        $rows=$this->repo->query("SELECT pp.pertemuan_id,p.tanggal_pertemuan,a.status FROM pertemuan_peserta pp JOIN pertemuan_pengajian p ON p.id=pp.pertemuan_id AND p.status='Selesai' JOIN jadwal_ngaji j ON j.id=p.jadwal_id AND j.id_tahun=pp.tahun_ajaran_id_snapshot LEFT JOIN absensi_santri a ON a.pertemuan_id=pp.pertemuan_id AND a.santri_id=pp.santri_id WHERE pp.santri_id=? AND pp.tahun_ajaran_id_snapshot=? ORDER BY pp.pertemuan_id",'ii',[$sid,(int)$p['tahun_ajaran_id']]);
        $counts=array_fill_keys(['Hadir','Terlambat','Izin','Sakit','Alpa'],0);$missing=[];$seen=[];
        foreach($rows as $r){$id=(int)$r['pertemuan_id'];if(isset($seen[$id]))continue;$seen[$id]=true;
            if($r['status']===null)$missing[]=$r;else $counts[$r['status']]++;
        }
        return ['hitungan'=>$counts,'jumlah_pertemuan'=>count($seen),'belum_dicatat'=>count($missing),'kekurangan'=>$missing,'sumber'=>$rows];
    }
    private function materials(array $p,int $sid): array
    {
        $plan=(int)$p['id'];$out=[];
        $mapels=$this->repo->query('SELECT rm.*,mp.nama,mp.is_active,mp.archived_at FROM v4_rencana_mapel rm JOIN mata_pelajaran mp ON mp.id=rm.mata_pelajaran_id WHERE rm.rencana_id=? AND rm.wajib=1 ORDER BY rm.id','i',[$plan]);
        foreach($mapels as $mp){$key=(int)$mp['id'];$source=$this->repo->query('SELECT * FROM v4_nilai WHERE rencana_mapel_id=? AND santri_id=? ORDER BY komponen,nomor_harian','ii',[$key,$sid]);
            $calc=NilaiService::calculate($source,(float)$mp['target_ketuntasan']);
            $complete=$calc['kelengkapan']==='Lengkap'&&(int)$mp['is_active']===1&&$mp['archived_at']===null;
            $submitted=$source&&count(array_filter($source,fn($r)=>$r['status']==='Dikirim'))===count($source);
            $out[]=['jenis'=>'nilai','mapel_key'=>$key,'label'=>$mp['nama'],'lengkap'=>$complete,'komponen'=>$source,'hasil'=>$calc,'sumber'=>$source,'pemeriksaan'=>$this->repo->state($plan,$sid,'nilai',$key,$source,$submitted?'Dikirim':'Draf')];
        }
        $sk=$this->repo->query('SELECT * FROM v4_sikap WHERE santri_id=? AND tahun_ajaran_id=?','ii',[$sid,(int)$p['tahun_ajaran_id']]);$row=$sk[0]??[];
        $murobis=$this->scope->murobis($p,$sid);
        // Pemisahan tugas (PK-T7): tanda dari murobi yang juga pengisi sikap sebagai pembimbing tidak sah.
        $sah=(new SikapService($this->db,$this->audit))->murobiSah($p,$sid,$row?:null);
        $known=$row&&in_array((int)($row['diketahui_murobi_user_id']??0),$sah,true);
        $complete=$row&&(int)$row['kelas_id']===(int)$p['kelas_id']&&$row['adab']&&$row['kedisiplinan']&&$row['tanggung_jawab']&&$known;
        $out[]=['jenis'=>'sikap','mapel_key'=>0,'label'=>'Sikap','lengkap'=>(bool)$complete,'sumber'=>$sk,'sikap'=>$row,'murobi_aktif'=>$murobis,'diketahui_sah'=>$known,'penghalang'=>!$murobis?'Tidak ada murobi aktif yang sah. Minta admin memperbaiki penugasan.':(!$sah?'Seluruh murobi aktif juga pengisi sikap sebagai pembimbing (pemisahan tugas). Minta admin menugaskan murobi atau pembimbing lain.':(!$known?'Menunggu diketahui atau koreksi murobi.':null)),'pemeriksaan'=>$this->repo->state($plan,$sid,'sikap',0,$sk,$row['status']??'Draf')];
        $att=$this->attendance($p,$sid);
        $out[]=['jenis'=>'kehadiran','mapel_key'=>0,'label'=>'Kehadiran','lengkap'=>$att['belum_dicatat']===0,'sumber'=>$att['sumber'],'kehadiran'=>$att,'pemeriksaan'=>$this->repo->state($plan,$sid,'kehadiran',0,$att['sumber'])];
        return $out;
    }
    /**
     * PK-T1 (penugasan lintas guru/pengurus Fase 2, keputusan Human Developer
     * PK-T5 "per bahan nilai"): akun pengisi nilai — pelaku baris `v4_nilai`
     * atau riwayatnya, sebagai Guru Mapel maupun Pendidikan pengganti — tidak
     * memverifikasi atau mengembalikan bahan nilai itu dalam kapasitas
     * Pendidikan. Bahan lain, finalisasi, dan penerbitan tidak terdampak;
     * admin tetap jalur pengganti.
     *
     * @param array<int,array<string,mixed>> $sumber baris `v4_nilai`
     * @return array<int,int>
     */
    public function pengisiNilai(array $sumber): array
    {
        $ids=array_values(array_unique(array_map(static fn($r)=>(int)$r['id'],$sumber)));
        $out=array_map(static fn($r)=>(int)$r['pelaku_user_id'],$sumber);
        if($ids!==[]){$in=implode(',',array_fill(0,count($ids),'?'));
            foreach($this->repo->query("SELECT DISTINCT pelaku_user_id FROM v4_nilai_riwayat WHERE pelaku_user_id IS NOT NULL AND nilai_id IN ($in)",str_repeat('i',count($ids)),$ids) as $r)$out[]=(int)$r['pelaku_user_id'];}
        return array_values(array_unique($out));
    }
    public function read(array $u,int $plan,int $sid=0): array
    {
        $p=$this->scope->plan($plan);$capacity=$this->scope->education($u,$p);
        $students=$this->repo->query('SELECT DISTINCT s.id,s.nama_santri FROM santri s JOIN plotting_kelas pk ON pk.id_santri=s.id WHERE pk.id_kelas=? AND pk.id_tahun=? AND s.is_active=1 AND s.archived_at IS NULL ORDER BY s.nama_santri','ii',[(int)$p['kelas_id'],(int)$p['tahun_ajaran_id']]);
        if($sid){$this->scope->student($p,$sid);$students=$this->repo->query('SELECT id,nama_santri FROM santri WHERE id=?','i',[$sid]);}
        $matrix=[];
        foreach($students as $s){$id=(int)$s['id'];$items=$this->materials($p,$id);$missing=[];
            if(!array_filter($items,fn($r)=>$r['jenis']==='nilai'))$missing[]='Belum ada mapel wajib.';
            foreach($items as $r){if(!$r['lengkap'])$missing[]=$r['label'].' belum lengkap'.($r['penghalang']??'');if($r['pemeriksaan']['status']!=='Terverifikasi')$missing[]=$r['label'].' perlu verifikasi.';}
            $classes=$this->repo->query("SELECT DISTINCT kelas_id FROM (SELECT id_kelas AS kelas_id FROM plotting_kelas WHERE id_santri=? AND id_tahun=? AND status='Aktif' UNION SELECT r.kelas_id FROM v4_nilai n JOIN v4_rencana_mapel rm ON rm.id=n.rencana_mapel_id JOIN v4_rencana r ON r.id=rm.rencana_id WHERE n.santri_id=? AND r.tahun_ajaran_id=?) x",'iiii',[$id,(int)$p['tahun_ajaran_id'],$id,(int)$p['tahun_ajaran_id']]);
            if(count($classes)!==1||(int)($classes[0]['kelas_id']??0)!==(int)$p['kelas_id'])$missing[]='Kelas semester ambigu; periksa penempatan dan bahan kelas asal.';
            $history=$this->repo->query('SELECT h.* FROM v4_bahan_riwayat h JOIN v4_bahan b ON b.id=h.bahan_id WHERE b.rencana_id=? AND b.santri_id=? ORDER BY h.id DESC','ii',[$plan,$id]);
            $matrix[]=['santri'=>$s,'bahan'=>$items,'kekurangan'=>$missing,'siap_finalisasi'=>!$missing,'riwayat_pemeriksaan'=>$history];
        }
        // Di luar matriks agar sidik jari bahan tidak bergantung pada akun pembaca.
        // Satu kueri riwayat untuk seluruh matriks; aturan sama dengan pengisiNilai().
        $sendiri=[];
        if($capacity==='pendidikan'){
            $uid=(int)$u['id'];$ids=[];
            foreach($matrix as $st)foreach($st['bahan'] as $m)if($m['jenis']==='nilai')foreach($m['sumber'] as $r)$ids[]=(int)$r['id'];
            $riwayat=[];
            if($ids!==[]){$ids=array_values(array_unique($ids));$in=implode(',',array_fill(0,count($ids),'?'));
                foreach($this->repo->query("SELECT DISTINCT nilai_id FROM v4_nilai_riwayat WHERE pelaku_user_id=? AND nilai_id IN ($in)",'i'.str_repeat('i',count($ids)),array_merge([$uid],$ids)) as $r)$riwayat[(int)$r['nilai_id']]=true;}
            foreach($matrix as $st)foreach($st['bahan'] as $m){
                if($m['jenis']!=='nilai')continue;
                foreach($m['sumber'] as $r)if((int)$r['pelaku_user_id']===$uid||isset($riwayat[(int)$r['id']])){$sendiri[]=(int)$st['santri']['id'].':'.(int)$m['mapel_key'];break;}
            }
        }
        return ['rencana'=>$p,'matriks'=>$matrix,'matriks_hash'=>$sid?BahanRepository::fingerprint($matrix[0]):null,'diisi_sendiri'=>$sendiri];
    }
    public function act(array $u,array $in): array
    {
        $plan=(int)($in['rencana_id']??0);$sid=(int)($in['santri_id']??0);$action=$in['action']??'';
        $p=$this->scope->plan($plan);$capacity=$this->scope->education($u,$p,$action==='periksa_finalisasi'?PenugasanJenis::CAP_RAPOR_FINALISASI:PenugasanJenis::CAP_RAPOR_VERIFIKASI);$this->scope->student($p,$sid);
        if(!in_array($action,['serahkan','kembalikan','verifikasi','periksa_finalisasi'],true))throw new V4Exception('Tindakan verifikasi tidak valid.');
        $reason=$in['alasan']??'';if(!is_string($reason)||mb_strlen($reason)>1000)throw new V4Exception('Alasan tidak valid.');
        if(($action==='kembalikan'||$capacity==='admin')&&trim($reason)==='')throw new V4Exception('Alasan pengembalian atau tindakan admin wajib diisi.');
        $this->db->begin_transaction();
        try{
            $this->repo->query('SELECT id FROM v4_rencana WHERE id=? FOR UPDATE','i',[$plan]);
            RaporService::assertEditable($this->db,$sid,(int)$p['tahun_ajaran_id']);
            $p=$this->scope->plan($plan);$capacity=$this->scope->education($u,$p,$action==='periksa_finalisasi'?PenugasanJenis::CAP_RAPOR_FINALISASI:PenugasanJenis::CAP_RAPOR_VERIFIKASI);$this->scope->student($p,$sid);
            $current=$this->read($u,$plan,$sid);$student=$current['matriks'][0];
            if($action==='periksa_finalisasi'){
                if(($in['matriks_hash']??'')!==BahanRepository::fingerprint($student))throw new V4Exception('Bahan berubah. Muat ulang matriks.',409);
                if(!$student['siap_finalisasi'])throw new V4Exception('Finalisasi ditolak: '.implode(' ', $student['kekurangan']));
                // Gate saja. Penerbitan/snapshot/PDF/notifikasi bukan Fase 3.
                $this->db->commit();return ['siap_finalisasi'=>true,'rapor_diterbitkan'=>false];
            }
            $kind=$in['jenis']??'';$key=(int)($in['mapel_key']??0);$material=null;
            foreach($student['bahan'] as $m)if($m['jenis']===$kind&&$m['mapel_key']===$key)$material=$m;
            if(!$material)throw new V4Exception('Bahan tidak ada pada rencana ini.',403);
            // PK-T1: dibaca sesudah rencana dikunci; penyimpanan nilai mengunci rencana yang sama.
            if($kind==='nilai'&&in_array($action,['verifikasi','kembalikan'],true)&&$capacity==='pendidikan'&&in_array((int)$u['id'],$this->pengisiNilai($material['sumber']),true))
                throw new V4Exception('Nilai ini memuat isian Anda sendiri, sehingga diverifikasi Bagian Pendidikan lain atau admin (pemisahan tugas).',403);
            $state=$material['pemeriksaan'];
            if((string)($in['version']??'')!==(string)$state['version']||($in['sumber_hash']??'')!==$state['sumber_hash'])throw new V4Exception('Versi atau sumber bahan berubah. Muat ulang matriks.',409);
            if($action==='serahkan'){
                if($kind!=='kehadiran')throw new V4Exception('Nilai dan sikap diserahkan oleh pengisinya.',403);
                if(!in_array($state['status'],['Draf','Dikembalikan'],true))throw new V4Exception('Bahan sudah diserahkan.',409);
                if(($state['status']==='Dikembalikan'||$state['perlu_verifikasi_ulang'])&&trim($reason)==='')throw new V4Exception('Alasan koreksi kehadiran wajib diisi.');
            }else{
                if($state['status']!=='Dikirim')throw new V4Exception('Bahan harus diserahkan kembali sebelum diperiksa.',409);
            }
            if($action!=='kembalikan'&&!$material['lengkap'])throw new V4Exception('Bahan belum lengkap. '.($material['penghalang']??''));
            $status=match($action){'serahkan'=>'Dikirim','kembalikan'=>'Dikembalikan',default=>'Terverifikasi'};
            $this->repo->write($plan,$sid,$kind,$key,$material['sumber'],$status,(int)$u['id'],$capacity,$reason);
            $result=$this->read($u,$plan,$sid);$this->db->commit();return $result;
        }catch(Throwable $e){$this->db->rollback();if($e instanceof V4Exception)throw $e;throw new V4Exception('Pemeriksaan gagal; perubahan dibatalkan.',503);}
    }
}
