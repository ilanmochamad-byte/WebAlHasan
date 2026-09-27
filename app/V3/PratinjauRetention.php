<?php
declare(strict_types=1);
namespace App\V3;
use App\Audit\AuditLogger;
/** Hanya draf kedaluwarsa lebih dari tujuh hari. Tidak menghapus snapshot/riwayat. */
final class PratinjauRetention
{
    public function __construct(private KonselingRepository $repo,private AuditLogger $audit){}
    public function run(bool $apply,int $batch,int $actor):array
    {
        if($batch<1||$batch>500)throw new V3Exception('Batch harus 1–500.');
        $caps=capabilities()->v3Capabilities(['id'=>$actor]);
        if(!isset($caps['v3.pengawasan']))throw new V3Exception('Operator admin aktif diperlukan.',403);
        return $this->repo->transaction(function()use($apply,$batch,$actor){
            $rows=$this->repo->all('SELECT id FROM v3_publikasi_pratinjau WHERE expires_at<DATE_SUB(NOW(),INTERVAL 7 DAY) ORDER BY expires_at,id LIMIT ? FOR UPDATE',[$batch]);
            $ids=array_map('intval',array_column($rows,'id'));
            if($apply&&$ids!==[]){
                $this->repo->execute('DELETE FROM v3_publikasi_pratinjau WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).') AND expires_at<DATE_SUB(NOW(),INTERVAL 7 DAY)',$ids);
                if(!$this->audit->log('v3.pratinjau.purge','v3_publikasi_pratinjau',null,null,['ids'=>$ids,'jumlah'=>count($ids),'retensi_hari'=>7],$actor))throw new V3Exception('Audit purge gagal; seluruh batch dibatalkan.',503);
            }
            return ['dry_run'=>!$apply,'jumlah'=>count($ids),'ids'=>$ids,'batch_limit'=>$batch,'retensi_hari'=>7];
        });
    }
}
