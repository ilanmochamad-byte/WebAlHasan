<?php

declare(strict_types=1);
namespace App\V3;

/** SQL khusus publikasi; transaksi dan penanganan galat memakai fondasi V3. */
final class PublikasiRepository
{
    public function __construct(public KonselingRepository $sql) {}

    public function source(string $type,int $id):array
    {
        $table=match($type){'kasus'=>'v3_konseling_kasus','sesi'=>'v3_konseling_sesi','pelanggaran'=>'v3_pelanggaran',default=>throw new V3Exception('Sumber tidak valid.')};
        $fields=$type==='sesi'?'s.id,s.version,s.status,k.santri_id,k.tahun_ajaran_id,k.kerahasiaan,k.id kasus_id,k.pembimbing_id,k.archived_at':('s.id,s.version,s.status,s.santri_id,s.tahun_ajaran_id,s.archived_at'.($type==='kasus'?',s.kerahasiaan,s.id kasus_id,s.pembimbing_id':',NULL kerahasiaan,NULL kasus_id,NULL pembimbing_id'));
        return $this->sql->one('SELECT '.$fields.' FROM '.$table.' s'.($type==='sesi'?' JOIN v3_konseling_kasus k ON k.id=s.kasus_id':'').' WHERE s.id=? AND s.archived_at IS NULL',[$id])??throw new V3Exception('Sumber tidak dapat diakses.',403);
    }

    public function publication(int $id):array
    {
        return $this->sql->one('SELECT * FROM v3_publikasi WHERE id=?',[$id])??throw new V3Exception('Publikasi tidak dapat diakses.',403);
    }

    /** Batas relasi ada pada SQL sebelum snapshot diambil. Tidak membaca kasus/sesi. */
    public function parentFrom():string
    {
        return " FROM v3_publikasi p JOIN users u ON u.wali_id=p.wali_id AND u.is_active=1
         JOIN wali w ON w.id=p.wali_id AND w.is_active=1 AND w.archived_at IS NULL
         JOIN santri s ON s.id=p.santri_id AND s.is_active=1 AND s.archived_at IS NULL
         WHERE u.id=? AND p.archived_at IS NULL
         AND EXISTS (SELECT 1 FROM santri_wali sw WHERE sw.wali_id=p.wali_id AND sw.santri_id=p.santri_id AND sw.archived_at IS NULL)
         AND EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=u.id AND r.slug='orang_tua')";
    }

    public function parentDetail(int $userId,int $id):array
    {
        return $this->sql->one('SELECT p.*'.$this->parentFrom().' AND p.id=?',[$userId,$id])??throw new V3Exception('Publikasi tidak dapat diakses.',403);
    }

    /** Saringan anak (`santri_id`) dan "belum dibaca" (V-7) hanya mempersempit baris milik wali ini. */
    public function parentPage(int $userId,int $page,?int $santriId=null,bool $belum=false):array
    {
        $from=$this->parentFrom();$params=[$userId];
        if($santriId!==null){$from.=' AND p.santri_id=?';$params[]=$santriId;}
        if($belum)$from.=' AND p.dibaca_pada IS NULL AND p.ditarik_pada IS NULL';
        return ['rows'=>$this->sql->all('SELECT p.*'.$from.' ORDER BY p.id DESC LIMIT 25 OFFSET ?',[...$params,($page-1)*25]),'total'=>(int)$this->sql->one('SELECT COUNT(*) n'.$from,$params)['n'],'page'=>$page,'per_page'=>25];
    }

    public function history(int $id,bool $internal):array
    {
        return $this->sql->all('SELECT '.($internal?'*':'version,tindakan,created_at').' FROM v3_publikasi_riwayat WHERE publikasi_id=? ORDER BY version',[$id]);
    }

    /**
     * Keputusan Z (redesain Tahap 5): nama pembimbing pemilik sumber sebagai
     * pengirim informasi bagi orang tua. Hanya nama dari master pengurus yang
     * dibaca — pencatat pelanggaran atau pembimbing kasus (juga untuk sesi).
     * Tidak ada kolom isi sumber lain yang diambil. ID publikasi di sini
     * berasal dari baris yang sudah lolos penjaga relasi wali.
     *
     * @param array<int,int> $ids
     * @return array<int,string|null>
     */
    public function senderNames(array $ids):array
    {
        $ids=array_values(array_unique(array_map('intval',$ids)));if($ids===[])return [];
        $rows=$this->sql->all('SELECT p.id,COALESCE(pv.nama,pk.nama,ps.nama) AS pengirim FROM v3_publikasi p
            LEFT JOIN v3_pelanggaran sv ON sv.id=p.pelanggaran_id LEFT JOIN pengurus pv ON pv.id=sv.pembimbing_id
            LEFT JOIN v3_konseling_kasus sk ON sk.id=p.kasus_id LEFT JOIN pengurus pk ON pk.id=sk.pembimbing_id
            LEFT JOIN v3_konseling_sesi ss ON ss.id=p.sesi_id LEFT JOIN v3_konseling_kasus ssk ON ssk.id=ss.kasus_id LEFT JOIN pengurus ps ON ps.id=ssk.pembimbing_id
            WHERE p.id IN ('.implode(',',array_fill(0,count($ids),'?')).')',$ids);
        $names=[];foreach($rows as $row)$names[(int)$row['id']]=$row['pengirim']===null?null:(string)$row['pengirim'];return $names;
    }

    /** Nama pengirim calon publikasi untuk pratinjau staf (V-5), aturan sama dengan `senderNames()`. */
    public function sourceSender(string $type,int $id):?string
    {
        $sql=match($type){
            'pelanggaran'=>'SELECT pg.nama FROM v3_pelanggaran s JOIN pengurus pg ON pg.id=s.pembimbing_id WHERE s.id=?',
            'kasus'=>'SELECT pg.nama FROM v3_konseling_kasus s JOIN pengurus pg ON pg.id=s.pembimbing_id WHERE s.id=?',
            'sesi'=>'SELECT pg.nama FROM v3_konseling_sesi ss JOIN v3_konseling_kasus s ON s.id=ss.kasus_id JOIN pengurus pg ON pg.id=s.pembimbing_id WHERE ss.id=?',
            default=>throw new V3Exception('Sumber tidak valid.'),
        };
        $row=$this->sql->one($sql,[$id]);return $row===null?null:(string)$row['nama'];
    }

    /**
     * Daftar publikasi untuk pengelola (V-5). Satu baris per wali penerima.
     * Non-admin dibatasi cakupan penugasan pembimbing aktif atas santri dan
     * tahun ajaran sumber; publikasi dari kasus Rahasia hanya tampil bagi
     * pembimbing pemilik kasus, sama dengan penjaga `manage()`.
     *
     * @return array{rows:array<int,array<string,mixed>>,total:int,page:int,per_page:int}
     */
    public function managePage(bool $admin,int $userId,array $filters):array
    {
        $page=max(1,min(1000000,(int)($filters['page']??1)));$size=25;
        $inner="SELECT p.id,p.santri_id,p.wali_id,p.kasus_id,p.sesi_id,p.pelanggaran_id,p.version,p.diterbitkan_pada,p.ditarik_pada,p.dibaca_pada,
                s.nama_santri,w.nama AS wali_nama,COALESCE(sv.tahun_ajaran_id,sk.tahun_ajaran_id,ssk.tahun_ajaran_id) AS tahun_ajaran_id,
                COALESCE(sk.kerahasiaan,ssk.kerahasiaan,'Internal') AS kerahasiaan,COALESCE(sk.pembimbing_id,ssk.pembimbing_id) AS kasus_pembimbing_id,
                sv.waktu_kejadian,sv.kategori_snapshot,sk.dibuka_pada,ss.jadwal AS sesi_jadwal
            FROM v3_publikasi p JOIN santri s ON s.id=p.santri_id JOIN wali w ON w.id=p.wali_id
            LEFT JOIN v3_pelanggaran sv ON sv.id=p.pelanggaran_id
            LEFT JOIN v3_konseling_kasus sk ON sk.id=p.kasus_id
            LEFT JOIN v3_konseling_sesi ss ON ss.id=p.sesi_id LEFT JOIN v3_konseling_kasus ssk ON ssk.id=ss.kasus_id
            WHERE p.archived_at IS NULL";
        $where=['1=1'];$params=[];
        if(!$admin){
            [$scope,$scopeParams]=$this->sql->scopeFor('pembimbing',$userId,'r');
            $where[]='('.$scope.')';$params=[...$params,...$scopeParams];
            $where[]="(r.kerahasiaan='Internal' OR r.kasus_pembimbing_id=(SELECT ox.pengurus_id FROM users ox WHERE ox.id=?))";$params[]=$userId;
        }
        $status=(string)($filters['status']??'');
        if($status==='Terbit')$where[]='r.ditarik_pada IS NULL';elseif($status==='Ditarik')$where[]='r.ditarik_pada IS NOT NULL';elseif($status!=='')throw new V3Exception('Status filter tidak valid.');
        $q=trim((string)($filters['q']??''));
        if($q!==''){if(mb_strlen($q)>100)throw new V3Exception('Pencarian terlalu panjang.');$where[]='r.nama_santri LIKE ?';$params[]='%'.addcslashes($q,'%_\\').'%';}
        $from=' FROM ('.$inner.') r WHERE '.implode(' AND ',$where);
        return ['rows'=>$this->sql->all('SELECT r.*'.$from.' ORDER BY r.id DESC LIMIT ? OFFSET ?',[...$params,$size,($page-1)*$size]),'total'=>(int)$this->sql->one('SELECT COUNT(*) n'.$from,$params)['n'],'page'=>$page,'per_page'=>$size];
    }
}
