<?php

declare(strict_types=1);
namespace App\V3;

use mysqli;
use mysqli_stmt;
use Throwable;

final class KatalogRepository
{
    public const TABLES = ['kategori' => 'v3_kategori', 'katalog' => 'v3_katalog', 'ambang' => 'v3_ambang'];
    public function __construct(private mysqli $db) {}
    public function table(string $kind): string
    {
        return self::TABLES[$kind] ?? throw new V3Exception('Jenis data tidak dikenal.');
    }
    public function transaction(callable $work): mixed
    {
        if (!$this->db->begin_transaction()) { throw new V3Exception('Transaksi tidak tersedia.', 503); }
        try {
            $result = $work();
            if (!$this->db->commit()) { throw new V3Exception('Transaksi tidak dapat disimpan.', 503); }
            return $result;
        } catch (Throwable $e) { $this->db->rollback(); throw $e; }
    }
    private function statement(string $sql, array $values): mysqli_stmt
    {
        try {
            $s = $this->db->prepare($sql);
            if ($s === false) { $this->failure($this->db->errno); }
            if ($values !== []) {
                $types = implode('', array_map(static fn($v) => is_int($v) ? 'i' : 's', $values));
                if (!$s->bind_param($types, ...$values)) { $this->failure($s->errno); }
            }
            if (!$s->execute()) { $errno=$s->errno; $s->close(); $this->failure($errno); }
            return $s;
        } catch (\mysqli_sql_exception $e) { $this->failure((int)$e->getCode()); }
    }
    private function failure(int $errno): never
    {
        if ($errno === 1062) { throw new V3Exception('Kode sudah digunakan. Gunakan kode lain.', 409); }
        if (in_array($errno, [1205,1213], true)) { throw new V3Exception('Data sedang diperbarui. Muat ulang lalu coba lagi.',409); }
        throw new V3Exception('Data tidak dapat diproses. Silakan coba lagi.',503);
    }
    public function rows(string $sql, array $values=[]): array
    {
        $s=$this->statement($sql,$values);
        try {
            $r=$s->get_result();
            if ($r === false) { $this->failure($s->errno); }
            return $r->fetch_all(MYSQLI_ASSOC);
        } catch (\mysqli_sql_exception $e) { $this->failure((int)$e->getCode()); } finally { $s->close(); }
    }
    public function execute(string $sql, array $values=[]): int
    {
        $s=$this->statement($sql,$values); $id=(int)$s->insert_id; $s->close(); return $id;
    }
    public function find(string $kind,int $id,bool $lock=false): array
    {
        return $this->rows('SELECT * FROM '.$this->table($kind).' WHERE id=?'.($lock?' FOR UPDATE':''),[$id])[0]
            ?? throw new V3Exception('Data tidak ditemukan.',404);
    }
    public function save(string $kind,array $data,?int $id): int
    {
        $table=$this->table($kind);
        // Field names are exclusively supplied by the service's explicit normalization.
        if ($id === null) {
            return $this->execute('INSERT INTO '.$table.' ('.implode(',',array_keys($data)).') VALUES ('.implode(',',array_fill(0,count($data),'?')).')',array_values($data));
        }
        $this->execute('UPDATE '.$table.' SET '.implode(',',array_map(static fn($k)=>$k.'=?',array_keys($data))).',version=version+1 WHERE id=?',[...array_values($data),$id]);
        return $id;
    }
    public function page(string $kind,array $filters,bool $activeOnly=false): array
    {
        foreach (['status','kategori_id','tingkat','tahun_ajaran_id','page'] as $key) {
            if (isset($filters[$key]) && !is_scalar($filters[$key])) { throw new V3Exception('Filter tidak valid.'); }
        }
        if (!in_array($filters['status'] ?? '', ['', 'aktif', 'nonaktif', 'akan_datang', 'berakhir'], true)) { throw new V3Exception('Status filter tidak valid.'); }
        if (isset($filters['tingkat']) && !in_array($filters['tingkat'], ['', 'Ringan', 'Sedang', 'Berat'], true)) { throw new V3Exception('Tingkat filter tidak valid.'); }
        foreach (['kategori_id','tahun_ajaran_id','page'] as $key) {
            if (isset($filters[$key]) && $filters[$key] !== '' && (!preg_match('/^[1-9][0-9]*$/D', (string)$filters[$key]) || (float)$filters[$key]>2147483647)) { throw new V3Exception('Filter angka tidak valid.'); }
        }
        $table=$this->table($kind); $where=['1=1'];$params=[];
        foreach (['kategori_id','tingkat','tahun_ajaran_id'] as $key) {
            if (($kind==='katalog' && in_array($key,['kategori_id','tingkat'],true)) || ($kind==='ambang' && $key==='tahun_ajaran_id')) {
                if (isset($filters[$key]) && $filters[$key]!=='') { $where[]="$key=?"; $params[]=$filters[$key]; }
            }
        }
        if ($activeOnly || ($filters['status']??'')==='aktif') {
            $where[]='is_active=1 AND archived_at IS NULL AND tanggal_mulai<=CURDATE() AND (tanggal_selesai IS NULL OR tanggal_selesai>=CURDATE())';
            if ($kind==='katalog') { $where[]='EXISTS (SELECT 1 FROM v3_kategori k WHERE k.id=v3_katalog.kategori_id AND k.is_active=1 AND k.archived_at IS NULL AND k.tanggal_mulai<=CURDATE() AND (k.tanggal_selesai IS NULL OR k.tanggal_selesai>=CURDATE()))'; }
        } elseif (($filters['status']??'')==='nonaktif') { $where[]='is_active=0'; }
        elseif (($filters['status']??'')==='akan_datang') { $where[]='is_active=1 AND tanggal_mulai>CURDATE()'; }
        elseif (($filters['status']??'')==='berakhir') { $where[]='is_active=1 AND tanggal_selesai<CURDATE()'; }
        $sql=' FROM '.$table.' WHERE '.implode(' AND ',$where);
        $total=(int)$this->rows('SELECT COUNT(*) AS n'.$sql,$params)[0]['n'];
        $page=max(1,min(1000000,(int)($filters['page']??1)));$size=25;
        return ['rows'=>$this->rows("SELECT *,CASE WHEN is_active=0 OR archived_at IS NOT NULL THEN 'Nonaktif' WHEN tanggal_mulai>CURDATE() THEN 'Akan datang' WHEN tanggal_selesai<CURDATE() THEN 'Berakhir' ELSE 'Aktif' END AS status_efektif".($kind==='katalog'?',(SELECT nama FROM v3_kategori WHERE id=v3_katalog.kategori_id) AS kategori_nama':'').$sql.' ORDER BY id DESC LIMIT ? OFFSET ?',[...$params,$size,($page-1)*$size]),'total'=>$total,'page'=>$page,'per_page'=>$size];
    }
}
