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
    private function parentFrom():string
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

    public function parentPage(int $userId,int $page):array
    {
        $from=$this->parentFrom();
        return ['rows'=>$this->sql->all('SELECT p.*'.$from.' ORDER BY p.id DESC LIMIT 25 OFFSET ?',[$userId,($page-1)*25]),'total'=>(int)$this->sql->one('SELECT COUNT(*) n'.$from,[$userId])['n'],'page'=>$page,'per_page'=>25];
    }

    public function history(int $id,bool $internal):array
    {
        return $this->sql->all('SELECT '.($internal?'*':'version,tindakan,created_at').' FROM v3_publikasi_riwayat WHERE publikasi_id=? ORDER BY version',[$id]);
    }
}
