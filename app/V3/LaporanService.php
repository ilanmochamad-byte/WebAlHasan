<?php
declare(strict_types=1);
namespace App\V3;
use App\Auth\Capabilities;

/** Satu query/proyeksi untuk HTML, cetak dan CSV; tidak membaca isi konseling. */
final class LaporanService
{
    public const EXPORT_LIMIT=10000;
    public function __construct(private PelanggaranRepository $repo,private Capabilities $caps) {}

    public function kinds(array $user):array
    {
        $c=$this->caps->v3Capabilities($user);$k=[];
        // `ambang` (redesain web Tahap 2, keputusan AA): santri dalam cakupan
        // yang total poin semester aktifnya mencapai ambang aktif.
        if(array_intersect(['v3.pengawasan','v3.pelanggaran.kelola','v3.binaan.baca'],array_keys($c))!==[])$k=['pelanggaran','kasus','ambang'];
        if(isset($c['v3.publikasi.baca']))$k[]='publikasi';
        return $k;
    }

    public function read(array $user,array $filters,bool $export=false):array
    {
        [$kind,$from,$fields,$columns,$params,$a]=$this->build($user,$filters);
        if(isset($filters['page'])&&!preg_match('/^[1-9][0-9]{0,6}$/D',(string)$filters['page']))throw new V3Exception('Halaman tidak valid.');
        $page=max(1,min(1000000,(int)($filters['page']??1)));$size=$export?self::EXPORT_LIMIT:25;
        // Snapshot repeatable read mengikat count dan halaman/ekspor pada keadaan yang sama.
        $this->repo->execute('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        return $this->repo->transaction(function()use($from,$fields,$columns,$params,$a,$page,$size,$export,$kind){
            $total=(int)$this->repo->one('SELECT COUNT(*) n'.$from,$params)['n'];
            if($export&&$total>self::EXPORT_LIMIT)throw new V3Exception('Ekspor melebihi 10.000 baris. Persempit filter; tidak ada berkas parsial yang dibuat.',422);
            $rows=$this->repo->all('SELECT '.$fields.$from." ORDER BY $a.id DESC LIMIT ? OFFSET ?",[...$params,$size,$export?0:($page-1)*$size]);
            return ['jenis'=>$kind,'columns'=>$columns,'rows'=>$rows,'total'=>$total,'page'=>$page,'per_page'=>$size,'export_limit'=>self::EXPORT_LIMIT];
        });
    }

    /**
     * Ringkasan angka laporan web (V-6, redesain Tahap 5): catatan dan total
     * poin pelanggaran (tanpa yang dibatalkan), kasus yang masih berjalan, dan
     * santri mencapai ambang. Setiap angka dihitung lewat `build()` yang sama
     * dengan daftar, sehingga cakupan penugasan dan penyaring kerahasiaan kasus
     * berlaku sama. Saringan yang tidak berlaku untuk suatu jenis tidak ikut.
     *
     * @return array{catatan:int,total_poin:int,kasus_berjalan:int,ambang:int}|null
     */
    public function ringkasan(array $user,array $filters):?array
    {
        if(!in_array('pelanggaran',$this->kinds($user),true))return null;
        $umum=array_intersect_key($filters,array_flip(['mulai','sampai','santri_id','kelas_id','kamar_id','pembimbing_id','murobi_id','kategori','tingkat','q']));
        [, $from,, , $params]=$this->build($user,['jenis'=>'pelanggaran','aktif'=>'1']+$umum+array_intersect_key($filters,array_flip(['poin_min','poin_max','tindak_lanjut'])));
        $p=$this->repo->one('SELECT COUNT(*) n,COALESCE(SUM(p.poin_snapshot),0) poin'.$from,$params);
        [, $from,, , $params]=$this->build($user,['jenis'=>'kasus']+$umum);
        $k=$this->repo->one("SELECT COUNT(*) n".$from." AND k.status IN ('Dibuka','Dalam Pendampingan')",$params);
        [, $from,, , $params]=$this->build($user,['jenis'=>'ambang']+array_intersect_key($filters,array_flip(['santri_id','kelas_id','kamar_id','q'])));
        $r=$this->repo->one('SELECT COUNT(*) n'.$from,$params);
        return ['catatan'=>(int)$p['n'],'total_poin'=>(int)$p['poin'],'kasus_berjalan'=>(int)$k['n'],'ambang'=>(int)$r['n']];
    }

    /**
     * Pilihan bernama untuk saringan web (V-6): kelas, kamar, kategori, dan
     * — hanya bagi pengawas (admin) — pembimbing dan murobi. Nilai yang
     * dikirim tetap divalidasi `build()` dan dibatasi cakupan akun.
     *
     * @return array{kelas:array<int,array{id:int,nama:string}>,kamar:array<int,array{id:int,nama:string}>,kategori:array<int,string>,pembimbing:array<int,array{id:int,nama:string}>,murobi:array<int,array{id:int,nama:string}>}
     */
    public function filterOptions(array $user):array
    {
        if(array_diff($this->kinds($user),['publikasi'])===[])return ['kelas'=>[],'kamar'=>[],'kategori'=>[],'pembimbing'=>[],'murobi'=>[]];
        $c=$this->caps->v3Capabilities($user);$admin=isset($c['v3.pengawasan']);
        $pair=static fn(array $rows):array=>array_map(static fn(array $r):array=>['id'=>(int)$r['id'],'nama'=>(string)$r['nama']],$rows);
        return [
            'kelas'=>$pair($this->repo->all('SELECT id,nama_kelas nama FROM kelas WHERE archived_at IS NULL ORDER BY nama_kelas,id')),
            'kamar'=>$pair($this->repo->all('SELECT id,nama_kamar nama FROM kamar ORDER BY nama_kamar,id')),
            'kategori'=>array_map(static fn(array $r):string=>(string)$r['nama'],$this->repo->all('SELECT DISTINCT nama FROM v3_kategori WHERE archived_at IS NULL ORDER BY nama')),
            'pembimbing'=>$admin?$pair($this->repo->all("SELECT DISTINCT p.id,p.nama FROM pengurus p JOIN pembimbing_assignments pa ON pa.pengurus_id=p.id AND pa.archived_at IS NULL WHERE p.archived_at IS NULL ORDER BY p.nama,p.id")):[],
            'murobi'=>$admin?$pair($this->repo->all("SELECT DISTINCT g.id,g.nama_guru nama FROM guru g JOIN murobi_assignments ma ON ma.guru_id=g.id AND ma.archived_at IS NULL WHERE g.archived_at IS NULL ORDER BY g.nama_guru,g.id")):[],
        ];
    }

    /**
     * Kelas dan kamar tahun ajaran aktif bagi santri yang SUDAH terbaca pada
     * halaman laporan (kolom tampilan V-6). Tidak menambah baris laporan.
     *
     * @param array<int,int> $santriIds
     * @return array<int,array{kelas:?string,kamar:?string}>
     */
    public function penempatan(array $santriIds):array
    {
        $ids=array_values(array_unique(array_filter(array_map('intval',$santriIds))));if($ids===[])return [];
        $in=implode(',',array_fill(0,count($ids),'?'));$out=[];
        foreach($this->repo->all("SELECT s.id,(SELECT k.nama_kelas FROM plotting_kelas pk JOIN kelas k ON k.id=pk.id_kelas JOIN tahun_ajaran t ON t.id=pk.id_tahun AND t.status='Aktif' WHERE pk.id_santri=s.id AND pk.status='Aktif' LIMIT 1) kelas,
                (SELECT km.nama_kamar FROM plotting_kamar pm JOIN kamar km ON km.id=pm.id_kamar JOIN tahun_ajaran t ON t.id=pm.id_tahun AND t.status='Aktif' WHERE pm.id_santri=s.id LIMIT 1) kamar
              FROM santri s WHERE s.id IN ($in)",$ids) as $row)$out[(int)$row['id']]=['kelas'=>$row['kelas'],'kamar'=>$row['kamar']];
        return $out;
    }

    /** @return array{0:string,1:string,2:string,3:array<string,string>,4:array<int,mixed>,5:string} */
    private function build(array $user,array $filters):array
    {
        $kinds=$this->kinds($user);
        if($kinds===[])throw new V3Exception('Laporan tidak dapat diakses.',403);
        foreach($filters as $value)if(!is_scalar($value)&&$value!==null)throw new V3Exception('Filter tidak valid.');
        $kind=(string)($filters['jenis']??$kinds[0]);
        if(!in_array($kind,$kinds,true))throw new V3Exception('Laporan tidak dapat diakses.',403);
        $id=(int)$user['id'];$c=$this->caps->v3Capabilities($user);
        $mode=isset($c['v3.pengawasan'])?'admin':(isset($c['v3.pelanggaran.kelola'])?'pembimbing':'murobi');
        $a=match($kind){'kasus'=>'k','ambang'=>'r',default=>'p'};$params=[];$where=[];
        if($kind==='ambang'){
            foreach(['mulai','sampai','status','kategori','tingkat','poin_min','poin_max','pembimbing_id','murobi_id','tindak_lanjut','aktif'] as $key)if(($filters[$key]??'')!=='')throw new V3Exception('Filter tersebut tidak tersedia untuk laporan ambang.');
            [$scope,$params]=$this->repo->scopeSql($mode,$id,$a);
            $ambang="SELECT MIN(am.nilai_minimum) FROM v3_ambang am WHERE am.tahun_ajaran_id=r.tahun_ajaran_id AND am.is_active=1 AND am.archived_at IS NULL AND am.tanggal_mulai<=CURDATE() AND (am.tanggal_selesai IS NULL OR am.tanggal_selesai>=CURDATE())";
            $where=['r.archived_at IS NULL','('.$scope.')',"ta.status='Aktif'",'ta.archived_at IS NULL',"r.total_poin>=($ambang)"];
            $from=' FROM v3_poin_agregat r JOIN santri s ON s.id=r.santri_id JOIN tahun_ajaran ta ON ta.id=r.tahun_ajaran_id';$date='r.direkonsiliasi_pada';
            $fields="r.id,r.santri_id,s.nama_santri santri,CONCAT(ta.tahun,' ',ta.semester) periode,r.total_poin,(SELECT am.label FROM v3_ambang am WHERE am.tahun_ajaran_id=r.tahun_ajaran_id AND am.is_active=1 AND am.archived_at IS NULL AND am.tanggal_mulai<=CURDATE() AND (am.tanggal_selesai IS NULL OR am.tanggal_selesai>=CURDATE()) AND am.nilai_minimum<=r.total_poin ORDER BY am.nilai_minimum DESC,am.id DESC LIMIT 1) ambang";
            $columns=['id'=>'ID','santri_id'=>'ID santri','santri'=>'Santri','periode'=>'Semester','total_poin'=>'Total poin','ambang'=>'Ambang tercapai'];
        }elseif($kind==='publikasi'){
            $sql=new KonselingRepository(app_db());
            $from=(new PublikasiRepository($sql))->parentFrom();$params=[$id];
            $date='p.diterbitkan_pada';
            $fields="p.id,p.santri_id,p.diterbitkan_pada periode,IF(p.ditarik_pada IS NULL,'Terbit','Ditarik') status,IF(p.ditarik_pada IS NULL,p.ringkasan,'Informasi ini telah ditarik.') ringkasan,IF(p.ditarik_pada IS NULL,p.tindak_lanjut,NULL) tindak_lanjut";
            $columns=['id'=>'ID publikasi','santri_id'=>'ID santri','periode'=>'Diterbitkan','status'=>'Status','ringkasan'=>'Ringkasan untuk keluarga','tindak_lanjut'=>'Tindak lanjut'];
            foreach(['kelas_id','kamar_id','kategori','tingkat','poin_min','poin_max','pembimbing_id','murobi_id'] as $key)if(($filters[$key]??'')!=='')throw new V3Exception('Filter internal tidak tersedia untuk publikasi.');
        }else{
            [$scope,$params]=$this->repo->scopeSql($mode,$id,$a);
            $where=["$a.archived_at IS NULL",'('.$scope.')'];
            if($kind==='kasus'){
                if($mode!=='admin'){
                    $privacy=$mode==='pembimbing'?"(k.kerahasiaan<>'Rahasia' OR k.pembimbing_id=(SELECT pengurus_id FROM users WHERE id=?))":"k.kerahasiaan<>'Rahasia'";
                    $where[]=$privacy;if($mode==='pembimbing')$params[]=$id;
                }
                $from=' FROM v3_konseling_kasus k JOIN santri s ON s.id=k.santri_id';$date='k.dibuka_pada';
                $fields='k.id,k.santri_id,s.nama_santri santri,k.dibuka_pada periode,k.status,k.kerahasiaan,(SELECT COUNT(*) FROM v3_konseling_sesi ss WHERE ss.kasus_id=k.id AND ss.archived_at IS NULL AND NOT EXISTS(SELECT 1 FROM v3_konseling_sesi nx WHERE nx.revisi_dari_id=ss.id)) jumlah_sesi';
                $columns=['id'=>'ID kasus','santri_id'=>'ID santri','santri'=>'Santri','periode'=>'Dibuka','status'=>'Status','kerahasiaan'=>'Kerahasiaan','jumlah_sesi'=>'Sesi terkini'];
            }else{
                $where[]='NOT EXISTS(SELECT 1 FROM v3_pelanggaran nx WHERE nx.revisi_dari_id=p.id)';
                $from=' FROM v3_pelanggaran p JOIN santri s ON s.id=p.santri_id';$date='p.waktu_kejadian';
                $fields='p.id,p.santri_id,s.nama_santri santri,p.waktu_kejadian periode,p.kategori_snapshot kategori,p.tingkat_snapshot tingkat,p.poin_snapshot poin,p.status';
                $columns=['id'=>'ID pelanggaran','santri_id'=>'ID santri','santri'=>'Santri','periode'=>'Kejadian','kategori'=>'Kategori','tingkat'=>'Tingkat','poin'=>'Poin','status'=>'Status'];
            }
        }
        foreach(['mulai','sampai'] as $key){
            $v=(string)($filters[$key]??'');if($v==='')continue;
            $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$v);
            if(!$d||$d->format('Y-m-d')!==$v)throw new V3Exception('Periode tidak valid.');
            $where[]=$date.($key==='mulai'?'>=?':'<?');$params[]=$key==='mulai'?$v:$d->modify('+1 day')->format('Y-m-d');
        }
        if(($filters['mulai']??'')!==''&&($filters['sampai']??'')!==''&&$filters['mulai']>$filters['sampai'])throw new V3Exception('Awal periode melewati akhir periode.');
        foreach(['santri_id','kelas_id','kamar_id','pembimbing_id','murobi_id','poin_min','poin_max'] as $key){
            $v=(string)($filters[$key]??'');if($v==='')continue;
            if(!preg_match('/^\d{1,10}$/D',$v)||(float)$v>2147483647)throw new V3Exception('Filter angka tidak valid.');
            if($kind==='ambang'&&!in_array($key,['santri_id','kelas_id','kamar_id'],true))continue;
            if($key==='santri_id')$where[]="$a.santri_id=?";
            elseif($key==='pembimbing_id')$where[]="$a.pembimbing_id=?";
            elseif($key==='kelas_id')$where[]="EXISTS(SELECT 1 FROM plotting_kelas pk WHERE pk.id_santri=$a.santri_id AND pk.id_tahun=$a.tahun_ajaran_id AND pk.id_kelas=? AND pk.status='Aktif')";
            elseif($key==='kamar_id')$where[]="EXISTS(SELECT 1 FROM plotting_kamar pm WHERE pm.id_santri=$a.santri_id AND pm.id_tahun=$a.tahun_ajaran_id AND pm.id_kamar=?)";
            elseif($key==='murobi_id'){
                [$scope,$sp]=$this->repo->scopeSql('murobi',0,$a);
                $scope=str_replace('ux.id=?','mx.id=?',$scope);$where[]='('.$scope.')';
            }else{
                $op=$key==='poin_min'?'>=':'<=';
                $where[]=$kind==='pelanggaran'?"p.poin_snapshot$op?":"EXISTS(SELECT 1 FROM v3_konseling_tautan t JOIN v3_pelanggaran vp ON vp.id=t.pelanggaran_id WHERE t.kasus_id=k.id AND t.is_active=1 AND t.archived_at IS NULL AND vp.poin_snapshot$op?)";
            }
            $params[]=(int)$v;
        }
        // `aktif=1`: tanpa catatan yang dibatalkan (kartu "Pelanggaran bulan ini").
        if(($filters['aktif']??'')!==''){
            if((string)$filters['aktif']!=='1'||!in_array($kind,['pelanggaran','kasus'],true))throw new V3Exception('Filter aktif tidak valid.');
            $where[]="$a.status<>'Dibatalkan'";
        }
        foreach(['kategori','tingkat','status','tindak_lanjut'] as $key){
            $v=trim((string)($filters[$key]??''));if($v==='')continue;
            if(mb_strlen($v)>150)throw new V3Exception('Filter teks terlalu panjang.');
            if($key==='status'){
                $allowed=match($kind){'pelanggaran'=>['Draf','Dicatat','Ditindaklanjuti','Selesai','Dibatalkan'],'kasus'=>['Dibuka','Dalam Pendampingan','Selesai','Dibatalkan'],default=>['Terbit','Ditarik']};
                if(!in_array($v,$allowed,true))throw new V3Exception('Status filter tidak valid.');
                if($kind==='publikasi')$where[]='p.ditarik_pada IS '.($v==='Terbit'?'NULL':'NOT NULL');
                else{$where[]="$a.status=?";$params[]=$v;}
            }elseif($key==='tindak_lanjut'){
                if(!in_array($v,['ada','belum'],true))throw new V3Exception('Filter tindak lanjut tidak valid.');
                if($kind==='publikasi')$expr="p.ditarik_pada IS NULL AND COALESCE(p.tindak_lanjut,'')<>''";
                elseif($kind==='kasus')$expr='EXISTS(SELECT 1 FROM v3_konseling_sesi ss WHERE ss.kasus_id=k.id AND ss.archived_at IS NULL)';
                else{
                    // Tidak menggunakan kasus Rahasia yang tidak boleh diketahui sebagai sinyal filter.
                    $privacy=$mode==='admin'?'1=1':"ck.kerahasiaan<>'Rahasia'";
                    if($mode==='pembimbing'){$privacy="(ck.kerahasiaan<>'Rahasia' OR ck.pembimbing_id=(SELECT pengurus_id FROM users WHERE id=?))";$params[]=$id;}
                    $expr="EXISTS(SELECT 1 FROM v3_konseling_tautan t JOIN v3_konseling_kasus ck ON ck.id=t.kasus_id WHERE t.pelanggaran_id=p.id AND t.is_active=1 AND t.archived_at IS NULL AND ck.archived_at IS NULL AND ($privacy))";
                }
                $where[]=($v==='belum'?'NOT ':'').'('.$expr.')';
            }else{
                $field=$key==='kategori'?'kategori_snapshot':'tingkat_snapshot';
                if($kind==='pelanggaran')$where[]="p.$field=?";
                else $where[]="EXISTS(SELECT 1 FROM v3_konseling_tautan t JOIN v3_pelanggaran vp ON vp.id=t.pelanggaran_id WHERE t.kasus_id=k.id AND t.is_active=1 AND t.archived_at IS NULL AND vp.$field=?)";
                $params[]=$v;
            }
        }
        // `q` (redesain Tahap 5, V-6): cari nama santri, pengganti isian "ID santri".
        $q=trim((string)($filters['q']??''));
        if($q!==''){if(mb_strlen($q)>100)throw new V3Exception('Filter teks terlalu panjang.');$where[]='s.nama_santri LIKE ?';$params[]='%'.addcslashes($q,'%_\\').'%';}
        $from.=($kind==='publikasi'?' AND ':' WHERE ').($where===[]?'1=1':implode(' AND ',$where));
        return [$kind,$from,$fields,$columns,$params,$a];
    }

    public static function csvCell(mixed $value):string
    {
        $s=(string)($value??'');
        // Excel juga menafsirkan formula setelah spasi atau karakter kontrol.
        return preg_match('/^[\x00-\x20]*[=+@-]/u',$s)||preg_match('/^[\t\r\n]/',$s)?"'".$s:$s;
    }
}
