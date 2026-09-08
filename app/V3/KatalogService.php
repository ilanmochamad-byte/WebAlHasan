<?php

declare(strict_types=1);
namespace App\V3;

use App\Auth\Capabilities;
use App\Audit\AuditLogger;

final class KatalogService
{
    public function __construct(private KatalogRepository $repo,private Capabilities $capabilities,private AuditLogger $audit) {}
    public function requireAdmin(array $user): void
    {
        if (!isset($this->capabilities->v3Capabilities($user)['v3.katalog.kelola'])) {
            throw new V3Exception('Akses hanya untuk admin aktif.',403);
        }
    }
    private function text(array $input,string $key,int $max,bool $required=true): string
    {
        $value=$input[$key]??'';
        if (!is_string($value) && !is_int($value)) { throw new V3Exception('Isian '.$key.' tidak valid.'); }
        $value=trim((string)$value);
        if (($required && $value==='') || mb_strlen($value)>$max) { throw new V3Exception('Periksa isian '.$key.'.'); }
        return $value;
    }
    private function number(array $input,string $key,int $min=0,bool $nullable=false): ?int
    {
        $value=$input[$key]??'';
        if ($nullable && ($value==='' || $value===null)) { return null; }
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^\d+$/D',(string)$value) || (float)$value>2147483647 || (int)$value<$min) { throw new V3Exception('Nilai '.$key.' tidak valid.'); }
        return (int)$value;
    }
    private function date(array $input,string $key,bool $nullable=false): ?string
    {
        $v=$this->text($input,$key,10,!$nullable);
        if ($nullable && $v==='') { return null; }
        $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$v);
        if (!$d || $d->format('Y-m-d')!==$v || $v<'1000-01-01') { throw new V3Exception('Tanggal '.$key.' tidak valid.'); }
        return $v;
    }
    public function save(string $kind,array $input,array $user,?int $id=null): int
    {
        $this->repo->table($kind);$this->requireAdmin($user);
        return $this->repo->transaction(function() use($kind,$input,$user,$id): int {
            // One existing migration row serializes every V3 configuration write,
            // including category changes and threshold year moves. Reads remain concurrent.
            if ($this->repo->rows("SELECT id FROM schema_migrations WHERE migration='013_v3_fase1.sql' FOR UPDATE")===[]) { throw new V3Exception('Migrasi V3 belum terpasang.',503); }
            $this->requireAdmin($user);
            $before=$id===null?null:$this->repo->find($kind,$id,true);
            if ($before!==null && $this->number($input,'version',1)!==(int)$before['version']) { throw new V3Exception('Data telah berubah. Muat ulang sebelum menyimpan.',409); }
            $reason=$this->text($input,'alasan',1000,$before!==null);
            $data=['tanggal_mulai'=>$this->date($input,'tanggal_mulai'),'tanggal_selesai'=>$this->date($input,'tanggal_selesai',true),'is_active'=>$this->number($input,'is_active'),'alasan_status'=>$reason];
            if (!in_array($data['is_active'],[0,1],true)) { throw new V3Exception('Status tidak valid.'); }
            if ($data['tanggal_selesai']!==null && $data['tanggal_selesai']<$data['tanggal_mulai']) { throw new V3Exception('Tanggal selesai tidak boleh sebelum tanggal mulai.'); }
            if ($kind==='ambang') {
                $data+=['tahun_ajaran_id'=>$this->number($input,'tahun_ajaran_id',1),'nilai_minimum'=>$this->number($input,'nilai_minimum'),'nilai_maksimum'=>$this->number($input,'nilai_maksimum',0,true),'label'=>$this->text($input,'label',150),'rekomendasi'=>$this->text($input,'rekomendasi',5000)];
                if ($data['nilai_maksimum']!==null && $data['nilai_maksimum']<$data['nilai_minimum']) { throw new V3Exception('Maksimum tidak boleh lebih kecil dari minimum.'); }
                if ($this->repo->rows('SELECT id FROM tahun_ajaran WHERE id=? AND archived_at IS NULL',[$data['tahun_ajaran_id']])===[]) { throw new V3Exception('Tahun ajaran tidak tersedia.'); }
                if ($data['is_active']===1 && $this->repo->rows('SELECT id FROM v3_ambang WHERE tahun_ajaran_id=? AND is_active=1 AND archived_at IS NULL AND id<>? AND nilai_minimum<=? AND (nilai_maksimum IS NULL OR nilai_maksimum>=?) AND tanggal_mulai<=? AND (tanggal_selesai IS NULL OR tanggal_selesai>=?) FOR UPDATE',[$data['tahun_ajaran_id'],$id??0,$data['nilai_maksimum']??2147483647,$data['nilai_minimum'],$data['tanggal_selesai']??'9999-12-31',$data['tanggal_mulai']])!==[]) { throw new V3Exception('Rentang ambang bertumpang tindih pada masa berlaku yang sama.',409); }
            } else {
                $data+=['kode'=>strtoupper($this->text($input,'kode',40)),'nama'=>$this->text($input,'nama',150),'uraian'=>$this->text($input,'uraian',5000,false)];
                if (!preg_match('/^[A-Z0-9][A-Z0-9_.-]*$/D',$data['kode'])) { throw new V3Exception('Kode hanya boleh memuat huruf, angka, titik, garis bawah, atau tanda hubung.'); }
                if ($kind==='katalog') {
                    $data+=['kategori_id'=>$this->number($input,'kategori_id',1),'tingkat'=>$this->text($input,'tingkat',10),'poin_default'=>$this->number($input,'poin_default')];
                    if (!in_array($data['tingkat'],['Ringan','Sedang','Berat'],true)) { throw new V3Exception('Tingkat tidak valid.'); }
                    $category=$this->repo->find('kategori',$data['kategori_id'],true);
                    if ($data['is_active']===1 && ((int)$category['is_active']!==1 || $category['archived_at']!==null || $data['tanggal_mulai']<$category['tanggal_mulai'] || ($category['tanggal_selesai']!==null && ($data['tanggal_selesai']===null || $data['tanggal_selesai']>$category['tanggal_selesai'])))) { throw new V3Exception('Masa berlaku katalog harus berada dalam kategori aktif.'); }
                }
            }
            $data['updated_by']=(int)$user['id'];if ($id===null) { $data['created_by']=(int)$user['id']; }
            $saved=$this->repo->save($kind,$data,$id);
            $after=$this->repo->find($kind,$saved);
            if (!$this->audit->log('v3.'.$kind.'.'.($id===null?'buat':'ubah'),'v3_'.$kind,$saved,$before,$after,(int)$user['id'])) { throw new V3Exception('Audit tidak dapat disimpan. Perubahan dibatalkan.',503); }
            return $saved;
        });
    }
    public function page(string $kind,array $filters,array $user): array
    {
        $this->requireAdmin($user); return $this->repo->page($kind,$filters);
    }
    public function find(string $kind,int $id,array $user): array
    {
        $this->requireAdmin($user);return $this->repo->find($kind,$id);
    }
    public function options(array $user): array
    {
        $this->requireAdmin($user);
        return ['kategori'=>$this->repo->rows('SELECT id,nama FROM v3_kategori ORDER BY nama'),'tahun'=>$this->repo->rows('SELECT id,tahun,semester FROM tahun_ajaran WHERE archived_at IS NULL ORDER BY id DESC')];
    }
    public function history(string $kind,int $id,array $user): array
    {
        $this->requireAdmin($user);$this->repo->table($kind);
        return $this->repo->rows('SELECT action,before_json,after_json,created_at,actor_user_id FROM audit_logs WHERE entity_type=? AND entity_id=? ORDER BY id DESC LIMIT 50',['v3_'.$kind,$id]);
    }
    public function active(string $kind,array $filters,array $user): array
    {
        if (!in_array($kind,['katalog','ambang'],true)) { throw new V3Exception('Data tidak ditemukan.',404); }
        $caps=$this->capabilities->v3Capabilities($user);
        if (!isset($caps['v3.katalog.kelola']) && !isset($caps['v3.pelanggaran.kelola']) && !isset($caps['v3.binaan.baca'])) { throw new V3Exception('Tidak memiliki penugasan yang sesuai.',403); }
        if ($kind==='ambang') {
            $year=$this->number($filters,'tahun_ajaran_id',1);
            if (!isset($caps['v3.katalog.kelola']) && !$this->capabilities->featureAppliesToTahunAjaran($user,'pembimbing.binaan',$year) && !$this->capabilities->featureAppliesToTahunAjaran($user,'murobi.binaan',$year)) { throw new V3Exception('Tahun ajaran di luar cakupan.',403); }
            if ($this->repo->rows("SELECT id FROM tahun_ajaran WHERE id=? AND status='Aktif' AND archived_at IS NULL",[$year])===[]) { throw new V3Exception('Tahun ajaran tidak aktif.'); }
        }
        $result=$this->repo->page($kind,$filters,true);
        $fields=$kind==='katalog'?['id','kode','nama','kategori_id','kategori_nama','tingkat','poin_default','uraian','tanggal_mulai','tanggal_selesai']:['id','tahun_ajaran_id','nilai_minimum','nilai_maksimum','label','rekomendasi','tanggal_mulai','tanggal_selesai'];
        $result['rows']=array_map(static fn($row)=>array_intersect_key($row,array_flip($fields)),$result['rows']);return $result;
    }
    public function legacy(array $user,int $page): array
    {
        $this->requireAdmin($user);$page=max(1,min(1000000,$page));
        return ['rows'=>$this->repo->rows('SELECT p.*,s.nama_santri FROM pelanggaran p LEFT JOIN santri s ON s.id=p.id_santri ORDER BY p.id DESC LIMIT 25 OFFSET ?',[($page-1)*25]),'total'=>(int)$this->repo->rows('SELECT COUNT(*) AS n FROM pelanggaran')[0]['n'],'page'=>$page,'per_page'=>25];
    }
}
