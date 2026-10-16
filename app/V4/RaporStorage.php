<?php
declare(strict_types=1);
namespace App\V4;

/** Berkas hanya terlihat lewat baris versi ter-commit. Bukan transaksi lintas filesystem/DB. */
class RaporStorage
{
    public function __construct(private ?string $root=null) {$this->root??=APP_ROOT.'/storage/private/v4';}
    public function path(string $name): string
    {
        if(!preg_match('/^[a-f0-9]{40}(?:\.pdf)?$/D',$name))throw new V4Exception('Berkas privat tidak valid.',503);
        return $this->root.'/'.$name;
    }
    public function asset(array $row): string
    {
        $bytes=@file_get_contents($this->path($row['private_path']));
        if($bytes===false||!hash_equals($row['sha256'],hash('sha256',$bytes)))throw new V4Exception('Aset tanda tangan tidak tersedia atau berubah.',422);
        return 'data:'.$row['mime_type'].';base64,'.base64_encode($bytes);
    }
    public function write(string $name,string $bytes): void
    {
        $f=@fopen($this->path($name),'x+b');
        if(!$f)throw new V4Exception('PDF privat gagal dibuat.',503);
        try{
            if(!chmod($this->path($name),0600))throw new V4Exception('Izin PDF gagal ditetapkan.',503);
            $len=strlen($bytes);$pos=0;
            while($pos<$len){$n=fwrite($f,substr($bytes,$pos));if(!$n)throw new V4Exception('Penulisan PDF gagal.',503);$pos+=$n;}
            if(!fflush($f)||(function_exists('fsync')&&!fsync($f)))throw new V4Exception('Penyimpanan PDF gagal.',503);
        }finally{fclose($f);}
    }
    public function remove(string $name): void {@unlink($this->path($name));}
    public function read(array $version): string
    {
        $bytes=@file_get_contents($this->path($version['pdf_private_path']??''));
        if($bytes===false||!hash_equals($version['pdf_sha256']??'',hash('sha256',$bytes)))throw new V4Exception('PDF kanonis tidak tersedia atau rusak. Hubungi admin.',503);
        return $bytes;
    }
}
