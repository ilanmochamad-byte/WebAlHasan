<?php
declare(strict_types=1);
namespace App\V4;

use App\Auth\Capabilities;
use App\Penugasan\PenugasanJenis;

/** Pemeriksaan server untuk tindakan V4. Belum membuka endpoint pengisian nilai. */
final class V4Access {
    public function __construct(private Capabilities $caps) {}
    public function nilaiInput(array $user,int $tahunAjaranId,int $kelasId,int $mataPelajaranId,bool $pengganti=false,?string $alasan=null): string {
        $context=['tahun_ajaran_id'=>$tahunAjaranId,'kelas_id'=>$kelasId,'mata_pelajaran_id'=>$mataPelajaranId];
        if($pengganti){
            if(!$this->caps->featureAppliesTo($user,PenugasanJenis::CAP_NILAI_PENGGANTI,$context))throw new V4Exception('Penugasan Pendidikan tidak mencakup tindakan pengganti.',403);
            if(trim((string)$alasan)==='' || mb_strlen((string)$alasan)>1000)throw new V4Exception('Alasan pengisian pengganti wajib dicatat (maksimal 1000 karakter).');
            return in_array($this->caps->featureSource($user,PenugasanJenis::CAP_NILAI_PENGGANTI),[Capabilities::SUMBER_ADMIN,Capabilities::SUMBER_KEDUANYA],true)?'admin':'pendidikan_pengganti';
        }
        if(!$this->caps->featureAppliesTo($user,PenugasanJenis::CAP_NILAI_INPUT,$context))throw new V4Exception('Penugasan guru mapel tidak mencakup kelas, mapel, dan semester ini.',403);
        if(in_array($this->caps->featureSource($user,PenugasanJenis::CAP_NILAI_INPUT),[Capabilities::SUMBER_ADMIN,Capabilities::SUMBER_KEDUANYA],true)){
            if(trim((string)$alasan)==='' || mb_strlen((string)$alasan)>1000)throw new V4Exception('Tindakan admin pada nilai membutuhkan alasan.');
            return 'admin';
        }
        return 'guru';
    }
}
