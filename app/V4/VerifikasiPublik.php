<?php
declare(strict_types=1);
namespace App\V4;
use App\Audit\AuditLogger;
use mysqli;

final class VerifikasiPublik
{
    public function __construct(private mysqli $db) {}
    public function check(string $ip,mixed $code,?array $upload=null): array
    {
        $repo=new BahanRepository($this->db,new AuditLogger($this->db));
        // IP only, no cookie/proxy/code-dependent buckets. Fail closed on DB failure.
        $bucket=hash('sha256','v4-public:'.$ip);$window=intdiv(time(),60);
        $repo->query('INSERT INTO v4_verifikasi_laju (bucket,jendela,jumlah) VALUES (?,?,1) ON DUPLICATE KEY UPDATE jumlah=IF(jendela=VALUES(jendela),jumlah+1,1),jendela=VALUES(jendela)','si',[$bucket,$window]);
        $n=$repo->query('SELECT jumlah FROM v4_verifikasi_laju WHERE bucket=?','s',[$bucket])[0]['jumlah'];
        if((int)$n>20)throw new V4Exception('Terlalu banyak pemeriksaan. Coba lagi satu menit.',429);
        $repo->query('DELETE FROM v4_verifikasi_laju WHERE jendela<?','i',[$window-1440]);
        if(!is_string($code)||!preg_match('/^[a-f0-9]{48}$/D',$code))throw new V4Exception('Kode tidak ditemukan.',404);
        $row=$repo->query('SELECT k.status,v.version,k.dibuat_pada FROM v4_verifikasi k JOIN v4_rapor_versi v ON v.id=k.rapor_versi_id WHERE k.kode_hash=?','s',[hash('sha256',$code)])[0]??null;
        if(!$row)throw new V4Exception('Kode tidak ditemukan.',404);
        $result=['status'=>$row['status'],'version'=>(int)$row['version'],'tanggal_terbit'=>substr($row['dibuat_pada'],0,10)];
        if($upload!==null){
            if(($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file((string)($upload['tmp_name']??''))||(int)($upload['size']??0)<5||(int)$upload['size']>10485760)throw new V4Exception('Unggah PDF maksimal 10 MB.');
            $file=$upload['tmp_name'];$f=fopen($file,'rb');$head=fread($f,5);fclose($f);
            if($head!=='%PDF-')throw new V4Exception('Berkas harus berupa PDF.');
            $hash=hash_file('sha256',$file);$row=$repo->query('SELECT pdf_sha256 FROM v4_verifikasi WHERE kode_hash=?','s',[hash('sha256',$code)])[0];
            $result['cocok']=hash_equals($row['pdf_sha256'],$hash);
            // No move/copy, parser, persisted hash, original filename, code or upload in audit/log.
        }return $result;
    }
}
