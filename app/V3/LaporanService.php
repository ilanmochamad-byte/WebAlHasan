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
        if(array_intersect(['v3.pengawasan','v3.pelanggaran.kelola','v3.binaan.baca'],array_keys($c))!==[])$k=['pelanggaran','kasus'];
        if(isset($c['v3.publikasi.baca']))$k[]='publikasi';
        return $k;
    }

    public function read(array $user,array $filters,bool $export=false):array
    {
        $kinds=$this->kinds($user);
        if($kinds===[])throw new V3Exception('Laporan tidak dapat diakses.',403);
        foreach($filters as $value)if(!is_scalar($value)&&$value!==null)throw new V3Exception('Filter tidak valid.');
        $kind=(string)($filters['jenis']??$kinds[0]);
        if(!in_array($kind,$kinds,true))throw new V3Exception('Laporan tidak dapat diakses.',403);
        $id=(int)$user['id'];$c=$this->caps->v3Capabilities($user);
        $mode=isset($c['v3.pengawasan'])?'admin':(isset($c['v3.pelanggaran.kelola'])?'pembimbing':'murobi');
        $a=$kind==='kasus'?'k':'p';$params=[];$where=[];
        if($kind==='publikasi'){
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
        $from.=($kind==='publikasi'?' AND ':' WHERE ').($where===[]?'1=1':implode(' AND ',$where));
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

    public static function csvCell(mixed $value):string
    {
        $s=(string)($value??'');
        // Excel juga menafsirkan formula setelah spasi atau karakter kontrol.
        return preg_match('/^[\x00-\x20]*[=+@-]/u',$s)||preg_match('/^[\t\r\n]/',$s)?"'".$s:$s;
    }
}
