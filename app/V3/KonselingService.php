<?php

declare(strict_types=1);

namespace App\V3;

use App\Audit\AuditLogger;
use App\Auth\Capabilities;
use DateTimeImmutable;

/** Aturan bisnis tunggal konseling Fase 3 untuk website dan REST API. */
final class KonselingService
{
    public function __construct(
        private KonselingRepository $repo,
        private Capabilities $capabilities,
        private AuditLogger $audit
    ) {
    }

    public function options(array $user,?int $santriId=null,?int $tahunId=null):array
    {
        $caps=$this->capabilities->v3Capabilities($user);$actorId=$this->actorId($user);
        $students=isset($caps['v3.konseling.kelola'])?$this->repo->studentOptions($actorId):[];
        $violations=[];
        if($santriId!==null&&$tahunId!==null&&$this->capabilities->v3AppliesToSantri($user,'v3.konseling.kelola',$santriId,$tahunId)){
            $violations=array_map([$this,'serializeViolationLink'],$this->repo->currentViolationsForStudent($santriId,$tahunId));
        }elseif(isset($caps['v3.konseling.kelola'])){
            foreach($students as $student){
                foreach($this->repo->currentViolationsForStudent((int)$student['santri_id'],(int)$student['tahun_ajaran_id']) as $row){
                    $violations[]=$this->serializeViolationLink($row)+['santri_id'=>(int)$student['santri_id'],'tahun_ajaran_id'=>(int)$student['tahun_ajaran_id'],'santri_nama'=>(string)$student['nama_santri']];
                }
            }
        }
        $mode=$this->readMode($user);
        $recommendations=$mode==='murobi'?[]:array_map([$this,'serializeRecommendation'],$this->repo->pendingRecommendations($actorId,$mode));
        return [
            'santri'=>array_map(static fn(array $row):array=>['santri_id'=>(int)$row['santri_id'],'tahun_ajaran_id'=>(int)$row['tahun_ajaran_id'],'nama'=>(string)$row['nama_santri'],'tahun'=>(string)$row['tahun'],'semester'=>(string)$row['semester']],$students),
            'pelanggaran'=>$violations,'rekomendasi_belum_ditindaklanjuti'=>$recommendations,'dapat_membuat'=>$students!==[],
        ];
    }

    /** @return array{data:array<string,mixed>,status:int,replayed:bool} */
    public function createCase(array $user,array $input):array
    {
        $actorId=$this->actorId($user);$data=$this->normaliseCaseCreate($input);
        $this->assertOperational($user,$data['santri_id'],$data['tahun_ajaran_id']);
        $assignment=$this->repo->pembimbingAssignment($actorId,$data['santri_id'],$data['tahun_ajaran_id'],substr($data['dibuka_pada'],0,10));
        if($assignment===null)throw new V3Exception('Penugasan pembimbing tidak berlaku pada tanggal pembukaan.',403);
        $hash=$this->requestHash('case.create',$data);
        return $this->repo->transaction(function()use($user,$actorId,$data,$assignment,$hash):array{
            $this->repo->lockSubject($data['santri_id'],$data['tahun_ajaran_id'],$actorId);
            $idem=$this->repo->claimIdempotency($actorId,'v3.konseling.case.create',$data['idempotency_key'],$hash);
            if($idem['response_json']!==null)return $this->replay($idem,$hash);
            $snapshot=json_encode(['assignment_id'=>(int)$assignment['id'],'target_type'=>$assignment['target_type'],'kelas_id'=>$assignment['kelas_id'],'kamar_id'=>$assignment['kamar_id'],'sumber_capability'=>$this->source($user,'v3.konseling.kelola')],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $id=$this->repo->insertCase($data+['pembimbing_id'=>(int)$assignment['pengurus_id'],'pembimbing_assignment_id'=>(int)$assignment['id'],'cakupan_snapshot'=>$snapshot===false?null:$snapshot,'created_by'=>$actorId]);
            $links=[];
            foreach($data['pelanggaran_ids'] as $violationId){
                $violation=$this->repo->violationForLink($violationId,$data['santri_id'],$data['tahun_ajaran_id'],true);
                if($violation===null)throw new V3Exception('Pelanggaran tautan tidak sah atau bukan milik santri yang sama.',422);
                $links[]=$this->repo->insertLink($violationId,$id,null,'Ditautkan saat kasus dibuat',$actorId);
            }
            $recommendations=[];
            foreach($data['rekomendasi_ids'] as $recommendationId){
                if(!$this->repo->linkRecommendation($recommendationId,$id,$data['santri_id'],$data['tahun_ajaran_id'],$actorId))throw new V3Exception('Rekomendasi sudah tidak berlaku atau sudah ditindaklanjuti.',409);
                $recommendations[]=$recommendationId;
            }
            $this->notifyMurobi($data,$id,'v3_konseling_dibuka');
            $saved=$this->repo->case($id)??throw new V3Exception('Kasus tidak ditemukan.',503);
            $payload=['kasus'=>$this->serializeCase($saved,true),'tautan_ids'=>$links,'rekomendasi_ids'=>$recommendations];
            $this->auditRequired('v3.konseling.kasus.dibuka','v3_konseling_kasus',$id,null,['kasus'=>$this->auditCase($saved),'pelanggaran_ids'=>$data['pelanggaran_ids'],'rekomendasi_ids'=>$recommendations],$actorId);
            $this->repo->completeIdempotency((int)$idem['id'],$payload,201);
            return ['data'=>$payload,'status'=>201,'replayed'=>false];
        });
    }

    public function correctCase(array $user,int $id,array $input):array
    {
        $actorId=$this->actorId($user);$probe=$this->caseOr404($id);$capacity=$this->correctionCapacity($user,$probe);
        $version=$this->positiveInt($input,'version');$reason=$this->reason($input['alasan']??null);
        $purpose=array_key_exists('tujuan',$input)?$this->requiredText($input['tujuan'],5000,'Tujuan'):(string)$probe['tujuan'];
        $privacy=array_key_exists('kerahasiaan',$input)?$this->enum($input['kerahasiaan'],['Internal','Rahasia'],'Kerahasiaan'):(string)$probe['kerahasiaan'];
        $key=$this->idempotencyKey($input);$hash=$this->requestHash('case.correct',[$id,$version,$reason,$purpose,$privacy]);
        return $this->repo->transaction(function()use($actorId,$id,$probe,$capacity,$version,$reason,$purpose,$privacy,$key,$hash):array{
            $this->repo->lockSubject((int)$probe['santri_id'],(int)$probe['tahun_ajaran_id'],$actorId);
            $idem=$this->repo->claimIdempotency($actorId,'v3.konseling.case.correct:'.$id,$key,$hash);if($idem['response_json']!==null)return $this->replay($idem,$hash);
            $current=$this->repo->case($id,true)??throw new V3Exception('Kasus tidak ditemukan.',404);
            if((int)$current['version']!==$version||!$this->repo->updateCaseDetails($id,$version,$purpose,$privacy,$reason,$actorId))throw new V3Exception('Versi kasus sudah berubah. Muat ulang data.',409);
            // Keputusan Human Developer 11 September 2026: koreksi kasus disimpan sebagai revisi berbaris, bukan hanya audit.
            $revisionId=$this->repo->insertCaseRevision($id,$current,$purpose,$privacy,$reason,$capacity,$actorId);
            $saved=$this->repo->case($id)??throw new V3Exception('Kasus tidak ditemukan.',503);$payload=['kasus'=>$this->serializeCase($saved,true),'revisi_kasus_id'=>$revisionId];
            $this->auditRequired('v3.konseling.kasus.dikoreksi.'.$capacity,'v3_konseling_kasus',$id,$this->auditCase($current),['kasus'=>$this->auditCase($saved),'alasan'=>$reason,'kapasitas'=>$capacity,'revisi_kasus_id'=>$revisionId],$actorId);
            $this->repo->completeIdempotency((int)$idem['id'],$payload,200);return ['data'=>$payload,'status'=>200,'replayed'=>false];
        });
    }

    public function transitionCase(array $user,int $id,array $input):array
    {
        $actorId=$this->actorId($user);$probe=$this->caseOr404($id);$this->assertCaseAccess($user,$probe);
        $version=$this->positiveInt($input,'version');$status=$this->enum($input['status']??null,['Dalam Pendampingan','Selesai','Dibatalkan'],'Status kasus');
        $allowed=['Dibuka'=>['Dalam Pendampingan','Dibatalkan'],'Dalam Pendampingan'=>['Selesai','Dibatalkan'],'Selesai'=>[],'Dibatalkan'=>[]];
        $summary=$status==='Selesai'?$this->requiredText($input['ringkasan_penutupan']??null,5000,'Ringkasan penutupan'):null;
        $reason=$status==='Dibatalkan'?$this->reason($input['alasan']??null):null;
        $key=$this->idempotencyKey($input);$hash=$this->requestHash('case.status',[$id,$version,$status,$summary,$reason]);
        return $this->repo->transaction(function()use($actorId,$id,$probe,$version,$status,$allowed,$summary,$reason,$key,$hash):array{
            $this->repo->lockSubject((int)$probe['santri_id'],(int)$probe['tahun_ajaran_id'],$actorId);
            $idem=$this->repo->claimIdempotency($actorId,'v3.konseling.case.status:'.$id,$key,$hash);if($idem['response_json']!==null)return $this->replay($idem,$hash);
            $current=$this->repo->case($id,true)??throw new V3Exception('Kasus tidak ditemukan.',404);
            if((int)$current['version']!==$version)throw new V3Exception('Versi kasus sudah berubah. Muat ulang data.',409);
            if(!in_array($status,$allowed[(string)$current['status']]??[],true))throw new V3Exception('Transisi status kasus tidak sah.',422);
            if($status==='Selesai'&&$this->repo->completedSessionCount($id)<1)throw new V3Exception('Kasus hanya dapat ditutup setelah sedikitnya satu sesi selesai.',422);
            if(!$this->repo->updateCaseStatus($id,$version,$status,$summary,$reason,$actorId))throw new V3Exception('Versi kasus sudah berubah. Muat ulang data.',409);
            // Kasus batal tidak menindaklanjuti apa pun, sehingga rekomendasinya kembali ke antrean manual.
            $released=$status==='Dibatalkan'?$this->repo->releaseRecommendations($id,$actorId):[];
            // Keputusan Human Developer 11 September 2026: menutup kasus (Selesai atau Dibatalkan) ikut menutup sesi yang masih terjadwal.
            $autoClosed=[];if(in_array($status,['Selesai','Dibatalkan'],true)){foreach($this->repo->closeScheduledSessions($id,'Ditutup otomatis: kasus '.($status==='Selesai'?'diselesaikan':'dibatalkan').' sebelum sesi dilaksanakan.',$actorId) as $before){$after=$this->repo->session((int)$before['id'])??throw new V3Exception('Sesi tidak ditemukan.',503);$this->auditRequired('v3.konseling.sesi.ditutup_otomatis','v3_konseling_sesi',(int)$before['id'],$this->auditSession($before),['sesi'=>$this->auditSession($after),'kasus_id'=>$id,'status_kasus'=>$status],$actorId);$autoClosed[]=(int)$before['id'];}}
            $saved=$this->repo->case($id)??throw new V3Exception('Kasus tidak ditemukan.',503);$payload=['kasus'=>$this->serializeCase($saved,true),'rekomendasi_dilepas'=>$released,'sesi_ditutup_otomatis'=>$autoClosed];
            $this->auditRequired('v3.konseling.kasus.status','v3_konseling_kasus',$id,$this->auditCase($current),['kasus'=>$this->auditCase($saved),'alasan'=>$reason,'rekomendasi_dilepas'=>$released,'sesi_ditutup_otomatis'=>$autoClosed],$actorId);
            $this->notifyMurobi($saved,$id,'v3_konseling_status',(int)$saved['version']);
            $this->repo->completeIdempotency((int)$idem['id'],$payload,200);return ['data'=>$payload,'status'=>200,'replayed'=>false];
        });
    }

    public function addLinks(array $user,int $id,array $input):array
    {
        $actorId=$this->actorId($user);$case=$this->caseOr404($id);$this->assertCaseAccess($user,$case);
        if(in_array($case['status'],['Selesai','Dibatalkan'],true))throw new V3Exception('Kasus yang sudah ditutup tidak dapat menerima tautan baru.',409);
        $ids=$this->idList($input['pelanggaran_ids']??[],'Pelanggaran');$recommendationIds=$this->idList($input['rekomendasi_ids']??[],'Rekomendasi');if($ids===[]&&$recommendationIds===[])throw new V3Exception('Pilih sedikitnya satu pelanggaran atau rekomendasi.',422);
        $reason=$this->optionalText($input['alasan']??null,1000,'Alasan tautan');$key=$this->idempotencyKey($input);$hash=$this->requestHash('case.links',[$id,$ids,$reason,$recommendationIds]);
        return $this->repo->transaction(function()use($actorId,$case,$id,$ids,$recommendationIds,$reason,$key,$hash):array{
            $this->repo->lockSubject((int)$case['santri_id'],(int)$case['tahun_ajaran_id'],$actorId);$idem=$this->repo->claimIdempotency($actorId,'v3.konseling.case.links:'.$id,$key,$hash);if($idem['response_json']!==null)return $this->replay($idem,$hash);
            $locked=$this->repo->case($id,true)??throw new V3Exception('Kasus tidak ditemukan.',404);if(in_array($locked['status'],['Selesai','Dibatalkan'],true))throw new V3Exception('Kasus yang sudah ditutup tidak dapat menerima tautan baru.',409);
            $created=[];foreach($ids as $violationId){if($this->repo->violationForLink($violationId,(int)$case['santri_id'],(int)$case['tahun_ajaran_id'],true)===null)throw new V3Exception('Pelanggaran tautan tidak sah atau bukan milik santri yang sama.',422);if($this->repo->violationChainLinkedToCase($violationId,$id))throw new V3Exception('Pelanggaran ini atau revisi sebelumnya sudah ditautkan ke kasus.',409);$created[]=$this->repo->insertLink($violationId,$id,null,$reason,$actorId);}
            $linked=[];foreach($recommendationIds as $recommendationId){if(!$this->repo->linkRecommendation($recommendationId,$id,(int)$case['santri_id'],(int)$case['tahun_ajaran_id'],$actorId))throw new V3Exception('Rekomendasi sudah tidak berlaku atau sudah ditindaklanjuti.',409);$linked[]=$recommendationId;}
            $payload=['kasus_id'=>$id,'tautan_ids'=>$created,'rekomendasi_ids'=>$linked];$this->auditRequired('v3.konseling.tautan.ditambah','v3_konseling_kasus',$id,null,['pelanggaran_ids'=>$ids,'rekomendasi_ids'=>$linked,'alasan'=>$reason],$actorId);$this->repo->completeIdempotency((int)$idem['id'],$payload,201);return ['data'=>$payload,'status'=>201,'replayed'=>false];
        });
    }

    public function createSession(array $user,int $caseId,array $input):array
    {
        $actorId=$this->actorId($user);$case=$this->caseOr404($caseId);$this->assertCaseAccess($user,$case);
        if(in_array($case['status'],['Selesai','Dibatalkan'],true))throw new V3Exception('Kasus yang sudah ditutup tidak dapat menerima sesi.',409);
        $assignment=$this->repo->pembimbingAssignment($actorId,(int)$case['santri_id'],(int)$case['tahun_ajaran_id'],date('Y-m-d'));
        if($assignment===null)throw new V3Exception('Penugasan pembimbing tidak berlaku.',403);
        $data=$this->normaliseSession($input,null);$hash=$this->requestHash('session.create',[$caseId,$data]);
        return $this->repo->transaction(function()use($actorId,$case,$caseId,$assignment,$data,$hash):array{
            $this->repo->lockSubject((int)$case['santri_id'],(int)$case['tahun_ajaran_id'],$actorId);$idem=$this->repo->claimIdempotency($actorId,'v3.konseling.session.create:'.$caseId,$data['idempotency_key'],$hash);if($idem['response_json']!==null)return $this->replay($idem,$hash);
            $locked=$this->repo->case($caseId,true)??throw new V3Exception('Kasus tidak ditemukan.',404);if(in_array($locked['status'],['Selesai','Dibatalkan'],true))throw new V3Exception('Kasus yang sudah ditutup tidak dapat menerima sesi.',409);
            $sessionId=$this->repo->insertSession(array_replace($data,['kasus_id'=>$caseId,'pembimbing_id'=>(int)$assignment['pengurus_id'],'status'=>'Dijadwalkan','realisasi'=>null,'revisi_dari_id'=>null,'alasan_revisi'=>null,'alasan_penjadwalan_ulang'=>null,'alasan_pembatalan'=>null,'created_by'=>$actorId]));
            $linkIds=[];foreach($data['pelanggaran_ids'] as $violationId){if($this->repo->violationForLink($violationId,(int)$case['santri_id'],(int)$case['tahun_ajaran_id'],true)===null)throw new V3Exception('Pelanggaran sesi tidak sah atau bukan milik santri yang sama.',422);$linkIds[]=$this->repo->insertLink($violationId,$caseId,$sessionId,'Ditautkan ke sesi',$actorId);}
            $this->repo->setCaseInProgress($caseId,$actorId);$saved=$this->repo->session($sessionId)??throw new V3Exception('Sesi tidak ditemukan.',503);$payload=['sesi'=>$this->serializeSession($saved,true),'tautan_ids'=>$linkIds];
            $this->auditRequired('v3.konseling.sesi.dijadwalkan','v3_konseling_sesi',$sessionId,null,['sesi'=>$this->auditSession($saved),'pelanggaran_ids'=>$data['pelanggaran_ids']],$actorId);$this->notifyMurobi($locked,$sessionId,'v3_konseling_sesi');$this->repo->completeIdempotency((int)$idem['id'],$payload,201);return ['data'=>$payload,'status'=>201,'replayed'=>false];
        });
    }

    public function correctSession(array $user,int $sessionId,array $input):array
    {
        $actorId=$this->actorId($user);$probe=$this->sessionWithCase($sessionId);$capacity=$this->correctionCapacity($user,$probe['case']);$version=$this->positiveInt($input,'version');$reason=$this->reason($input['alasan']??null);$data=$this->normaliseSession($input,$probe['session']);$hash=$this->requestHash('session.correct',[$sessionId,$version,$reason,$data]);
        return $this->repo->transaction(function()use($actorId,$probe,$sessionId,$version,$reason,$data,$capacity,$hash):array{
            $case=$probe['case'];$this->repo->lockSubject((int)$case['santri_id'],(int)$case['tahun_ajaran_id'],$actorId);$idem=$this->repo->claimIdempotency($actorId,'v3.konseling.session.correct:'.$sessionId,$data['idempotency_key'],$hash);if($idem['response_json']!==null)return $this->replay($idem,$hash);
            $current=$this->repo->session($sessionId,true)??throw new V3Exception('Sesi tidak ditemukan.',404);if((int)$current['version']!==$version||$current['digantikan_oleh_id']!==null)throw new V3Exception('Versi sesi sudah berubah. Muat ulang data.',409);
            $data=$this->sessionStateRules((string)$current['status'],$data);if(!$this->repo->bumpSessionForRevision($sessionId,$version,$actorId))throw new V3Exception('Versi sesi sudah berubah. Muat ulang data.',409);
            $newId=$this->repo->insertSession($data+['kasus_id'=>(int)$current['kasus_id'],'pembimbing_id'=>(int)$current['pembimbing_id'],'status'=>(string)$current['status'],'realisasi'=>$data['realisasi']??$current['realisasi'],'revisi_dari_id'=>$sessionId,'alasan_revisi'=>$reason,'alasan_penjadwalan_ulang'=>$current['alasan_penjadwalan_ulang'],'alasan_pembatalan'=>$current['alasan_pembatalan'],'created_by'=>$actorId]);
            $saved=$this->repo->session($newId)??throw new V3Exception('Sesi hasil koreksi tidak ditemukan.',503);$payload=['sesi'=>$this->serializeSession($saved,true)];$this->auditRequired('v3.konseling.sesi.dikoreksi.'.$capacity,'v3_konseling_sesi',$newId,$this->auditSession($current),['sesi'=>$this->auditSession($saved),'alasan'=>$reason,'kapasitas'=>$capacity],$actorId);$this->repo->completeIdempotency((int)$idem['id'],$payload,200);return ['data'=>$payload,'status'=>200,'replayed'=>false];
        });
    }

    public function transitionSession(array $user,int $sessionId,array $input):array
    {
        $actorId=$this->actorId($user);$probe=$this->sessionWithCase($sessionId);$case=$probe['case'];$this->assertCaseAccess($user,$case);$version=$this->positiveInt($input,'version');$status=$this->enum($input['status']??null,['Selesai','Tidak Hadir','Dijadwalkan Ulang','Dibatalkan'],'Status sesi');
        $key=$this->idempotencyKey($input);$reason=in_array($status,['Dijadwalkan Ulang','Dibatalkan'],true)?$this->reason($input['alasan']??null):null;
        $request=array_intersect_key($input,array_flip(['jadwal','realisasi','ringkasan_internal','hasil','tindak_lanjut','jadwal_berikut']));
        $hash=$this->requestHash('session.status',[$sessionId,$version,$status,$reason,$request]);
        return $this->repo->transaction(function()use($actorId,$case,$sessionId,$version,$status,$reason,$input,$key,$hash):array{
            $this->repo->lockSubject((int)$case['santri_id'],(int)$case['tahun_ajaran_id'],$actorId);$idem=$this->repo->claimIdempotency($actorId,'v3.konseling.session.status:'.$sessionId,$key,$hash);if($idem['response_json']!==null)return $this->replay($idem,$hash);$current=$this->repo->session($sessionId,true)??throw new V3Exception('Sesi tidak ditemukan.',404);
            if((int)$current['version']!==$version||$current['digantikan_oleh_id']!==null)throw new V3Exception('Versi sesi sudah berubah. Muat ulang data.',409);
            // Kasus tertutup membekukan sesinya: tidak ada status baru maupun jadwal ulang sesudah penutupan.
            $lockedCase=$this->repo->case((int)$current['kasus_id'],true)??throw new V3Exception('Kasus tidak ditemukan.',404);if(in_array($lockedCase['status'],['Selesai','Dibatalkan'],true))throw new V3Exception('Kasus sudah ditutup; status sesi tidak dapat diubah lagi.',422);
            if(!in_array($current['status'],['Dijadwalkan','Dijadwalkan Ulang'],true))throw new V3Exception('Transisi status sesi tidak sah.',422);
            // Field opsional yang dikirim kosong oleh formulir web berarti "tidak diubah", bukan menghapus rencana yang sudah tersimpan.
            $filled=array_filter($input,static fn(mixed $value,string|int $name):bool=>!in_array($name,['realisasi','ringkasan_internal','hasil','tindak_lanjut','jadwal_berikut'],true)||!(is_string($value)&&trim($value)===''),ARRAY_FILTER_USE_BOTH);
            $data=$this->normaliseSession($filled,$current);$now=date('Y-m-d\TH:i');if($status==='Selesai'){$data['realisasi']=$this->dateTime($filled['realisasi']??$now,false,'Waktu realisasi');$data['ringkasan_internal']=$this->requiredText($input['ringkasan_internal']??null,10000,'Ringkasan internal');$data['hasil']=$this->requiredText($input['hasil']??null,5000,'Hasil');}
            if($status==='Tidak Hadir')$data['realisasi']=$this->dateTime($filled['realisasi']??$now,false,'Waktu realisasi');
            if($status==='Dibatalkan')$data['realisasi']=null;
            if($status==='Dijadwalkan Ulang'&&!array_key_exists('jadwal',$input))throw new V3Exception('Jadwal baru wajib diisi.',422);
            if($status==='Dijadwalkan Ulang'){
                if(!$this->repo->bumpSessionForRevision($sessionId,$version,$actorId))throw new V3Exception('Versi sesi sudah berubah. Muat ulang data.',409);
                $targetId=$this->repo->insertSession(array_replace($data,['kasus_id'=>(int)$current['kasus_id'],'pembimbing_id'=>(int)$current['pembimbing_id'],'status'=>$status,'realisasi'=>null,'revisi_dari_id'=>$sessionId,'alasan_revisi'=>null,'alasan_penjadwalan_ulang'=>$reason,'alasan_pembatalan'=>null,'created_by'=>$actorId]));
            }else{
                $data['status']=$status;$data['alasan_pembatalan']=$status==='Dibatalkan'?$reason:null;
                if(!$this->repo->updateSessionStatus($sessionId,$version,$data,$actorId))throw new V3Exception('Versi sesi sudah berubah. Muat ulang data.',409);$targetId=$sessionId;
            }
            $saved=$this->repo->session($targetId)??throw new V3Exception('Sesi tidak ditemukan.',503);$payload=['sesi'=>$this->serializeSession($saved,true)];$this->auditRequired('v3.konseling.sesi.status','v3_konseling_sesi',$targetId,$this->auditSession($current),['sesi'=>$this->auditSession($saved),'alasan'=>$reason],$actorId);$this->notifyMurobi($lockedCase,$targetId,'v3_konseling_sesi_status',(int)$saved['version']);$this->repo->completeIdempotency((int)$idem['id'],$payload,200);return ['data'=>$payload,'status'=>200,'replayed'=>false];
        });
    }

    public function acknowledge(array $user,string $type,int $id,array $input):array
    {
        $actorId=$this->actorId($user);if($type==='kasus'){$source=$this->caseOr404($id);$case=$source;}elseif($type==='sesi'){$found=$this->sessionWithCase($id);$source=$found['session'];$case=$found['case'];}else throw new V3Exception('Sumber catatan tidak valid.');
        if(!$this->capabilities->v3AppliesToSantri($user,'v3.murobi.mengetahui',(int)$case['santri_id'],(int)$case['tahun_ajaran_id']))throw new V3Exception('Konseling berada di luar cakupan murobi.',403);
        if((string)$case['kerahasiaan']==='Rahasia')throw new V3Exception('Kasus rahasia tidak dibuka kepada murobi.',403);
        $assignment=$this->repo->murobiAssignment($actorId,(int)$case['santri_id'],(int)$case['tahun_ajaran_id']);if($assignment===null)throw new V3Exception('Penugasan murobi tidak berlaku.',403);
        $key=$this->idempotencyKey($input);$note=$this->optionalText($input['catatan']??null,2000,'Catatan');$hash=$this->requestHash('ack',[$type,$id,$note]);
        return $this->repo->transaction(function()use($actorId,$case,$source,$assignment,$type,$id,$key,$note,$hash):array{
            $this->repo->lockSubject((int)$case['santri_id'],(int)$case['tahun_ajaran_id'],$actorId);$idem=$this->repo->claimIdempotency($actorId,'v3.konseling.'.$type.'.ack:'.$id,$key,$hash);if($idem['response_json']!==null)return $this->replay($idem,$hash);
            if((string)($this->repo->case((int)$case['id'],true)['kerahasiaan']??'Rahasia')==='Rahasia')throw new V3Exception('Kasus rahasia tidak dibuka kepada murobi.',403);
            $event='v3:konseling:'.$type.':'.$id.':diketahui:guru:'.(int)$assignment['guru_id'];$noteId=$this->repo->insertMurobiNote($type,$id,(int)$source['version'],(int)$assignment['guru_id'],(int)$assignment['id'],$note,$event,$actorId);$payload=['id'=>$noteId,$type.'_id'=>$id,'diketahui_pada'=>(new DateTimeImmutable())->format('Y-m-d H:i:s')];$this->auditRequired('v3.konseling.'.$type.'.murobi.diketahui','v3_murobi_catatan',$noteId,null,[$type.'_id'=>$id,'sumber_version'=>(int)$source['version'],'catatan'=>$note],$actorId);$this->repo->completeIdempotency((int)$idem['id'],$payload,201);return ['data'=>$payload,'status'=>201,'replayed'=>false];
        });
    }

    public function page(array $user,array $filters):array
    {
        $mode=$this->readMode($user);$result=$this->repo->page($this->actorId($user),$mode,$filters);$result['rows']=array_map(fn(array $row):array=>$this->serializeCase($row,false),$result['rows']);return $result;
    }

    public function show(array $user,int $id):array
    {
        $mode=$this->readMode($user);$actorId=$this->actorId($user);$row=$this->repo->visibleCase($id,$actorId,$mode);if($row===null)throw new V3Exception('Kasus berada di luar cakupan pengguna.',403);
        // Kasus Internal diketahui pembimbing dan murobi terkait; kasus Rahasia sudah tersaring query bagi murobi dan pembimbing bukan pemilik.
        $internal=$mode!=='murobi'||(string)$row['kerahasiaan']==='Internal';
        if($mode==='admin')$this->auditRequired('v3.konseling.kasus.dilihat.admin','v3_konseling_kasus',$id,null,['kasus_id'=>$id,'kerahasiaan'=>(string)$row['kerahasiaan']],$actorId);
        $sessions=$this->repo->sessions($id,true);return ['kasus'=>$this->serializeCase($row,$internal),'sesi'=>array_map(fn(array $s):array=>$this->serializeSession($s,$internal),$sessions),'sesi_aktif'=>array_values(array_filter(array_map(fn(array $s):array=>$this->serializeSession($s,$internal),$sessions),static fn(array $s):bool=>$s['digantikan_oleh_id']===null)),'tautan'=>array_map(fn(array $link):array=>$this->serializeLink($link,$internal),$this->repo->links($id)),'rekomendasi'=>$internal?array_map([$this,'serializeRecommendation'],$this->repo->recommendationsForCase($id)):[],'catatan_murobi'=>array_map([$this,'serializeMurobi'],$this->repo->notes($id)),'riwayat_revisi_kasus'=>$internal?array_map([$this,'serializeCaseRevision'],$this->repo->caseRevisions($id)):[],'akses_internal'=>$internal];
    }

    /** Halaman yang sudah memanggil show() meneruskan detailnya agar satu pembukaan admin hanya diaudit sekali. */
    public function timeline(array $user,int $id,?array $detail=null):array
    {
        if($detail===null||(int)($detail['kasus']['id']??0)!==$id)$detail=$this->show($user,$id);$events=[['jenis'=>'Kasus dibuka','waktu'=>$detail['kasus']['dibuka_pada'],'referensi_id'=>$id]];
        foreach($detail['sesi'] as $session)$events[]=['jenis'=>'Sesi · '.$session['status'],'waktu'=>$session['realisasi']??$session['jadwal'],'referensi_id'=>$session['id']];
        if($detail['kasus']['ditutup_pada']!==null)$events[]=['jenis'=>'Kasus '.$detail['kasus']['status'],'waktu'=>$detail['kasus']['ditutup_pada'],'referensi_id'=>$id];
        usort($events,static fn(array $a,array $b):int=>strcmp((string)$a['waktu'],(string)$b['waktu']));return ['rows'=>$events];
    }

    /** Allowlist DTO untuk Fase 4; tidak pernah menerima baris kasus/sesi mentah. */
    public function parentSerializer(array $publication):array
    {
        return ['id'=>(int)($publication['id']??0),'santri_id'=>(int)($publication['santri_id']??0),'ringkasan'=>(string)($publication['ringkasan']??''),'tindak_lanjut'=>isset($publication['tindak_lanjut'])?(string)$publication['tindak_lanjut']:null,'diterbitkan_pada'=>(string)($publication['diterbitkan_pada']??''),'ditarik_pada'=>$publication['ditarik_pada']??null,'dibaca_pada'=>$publication['dibaca_pada']??null];
    }

    private function normaliseCaseCreate(array $input):array
    {
        return ['santri_id'=>$this->positiveInt($input,'santri_id'),'tahun_ajaran_id'=>$this->positiveInt($input,'tahun_ajaran_id'),'tujuan'=>$this->requiredText($input['tujuan']??null,5000,'Tujuan'),'kerahasiaan'=>$this->enum($input['kerahasiaan']??'Rahasia',['Internal','Rahasia'],'Kerahasiaan'),'dibuka_pada'=>$this->dateTime($input['dibuka_pada']??date('Y-m-d\TH:i'),false,'Waktu pembukaan'),'pelanggaran_ids'=>$this->idList($input['pelanggaran_ids']??[],'Pelanggaran'),'rekomendasi_ids'=>$this->idList($input['rekomendasi_ids']??[],'Rekomendasi'),'idempotency_key'=>$this->idempotencyKey($input)];
    }

    private function normaliseSession(array $input,?array $old):array
    {
        return ['jadwal'=>array_key_exists('jadwal',$input)?$this->dateTime($input['jadwal'],true,'Jadwal sesi'):(string)($old['jadwal']??throw new V3Exception('Jadwal sesi wajib diisi.')),'realisasi'=>array_key_exists('realisasi',$input)&&$input['realisasi']!==''?$this->dateTime($input['realisasi'],false,'Waktu realisasi'):($old['realisasi']??null),'ringkasan_internal'=>array_key_exists('ringkasan_internal',$input)?$this->optionalText($input['ringkasan_internal'],10000,'Ringkasan internal'):($old['ringkasan_internal']??null),'hasil'=>array_key_exists('hasil',$input)?$this->optionalText($input['hasil'],5000,'Hasil'):($old['hasil']??null),'tindak_lanjut'=>array_key_exists('tindak_lanjut',$input)?$this->optionalText($input['tindak_lanjut'],5000,'Tindak lanjut'):($old['tindak_lanjut']??null),'jadwal_berikut'=>array_key_exists('jadwal_berikut',$input)&&$input['jadwal_berikut']!==''?$this->dateTime($input['jadwal_berikut'],true,'Jadwal berikutnya'):($old['jadwal_berikut']??null),'pelanggaran_ids'=>$this->idList($input['pelanggaran_ids']??[],'Pelanggaran'),'idempotency_key'=>$this->idempotencyKey($input)];
    }

    private function correctionCapacity(array $user,array $case):string
    {
        $caps=$this->capabilities->v3Capabilities($user);if(isset($caps['v3.koreksi']))return 'admin';$this->assertCaseAccess($user,$case);return 'pembimbing';
    }
    /** Revisi sesi tidak boleh menghasilkan baris yang melanggar arti statusnya sendiri. */
    private function sessionStateRules(string $status,array $data):array
    {
        if(in_array($status,['Dijadwalkan','Dijadwalkan Ulang'],true))$data['realisasi']=null;
        if(in_array($status,['Selesai','Tidak Hadir'],true)&&$data['realisasi']===null)throw new V3Exception('Sesi berstatus '.$status.' wajib memiliki waktu realisasi.',422);
        if($status==='Selesai'&&(($data['ringkasan_internal']??'')===''||($data['hasil']??'')===''))throw new V3Exception('Sesi selesai wajib tetap memiliki ringkasan internal dan hasil.',422);
        return $data;
    }
    /** Cakupan pembimbing aktif, ditambah kepemilikan untuk kasus Rahasia (keputusan Human Developer 11 September 2026). */
    private function assertCaseAccess(array $user,array $case):void
    {
        $santriId=(int)$case['santri_id'];$tahunId=(int)$case['tahun_ajaran_id'];$this->assertOperational($user,$santriId,$tahunId);
        if((string)$case['kerahasiaan']==='Rahasia'&&(int)($case['pembimbing_id']??0)!==(int)($this->repo->pengurusIdForUser($this->actorId($user))??-1))throw new V3Exception('Kasus rahasia hanya dapat dibuka dan dikelola pembimbing pemilik kasus.',403);
    }
    private function assertOperational(array $user,int $santriId,int $tahunId):void
    {
        $caps=$this->capabilities->v3Capabilities($user);if(!isset($caps['v3.konseling.kelola'])||!$this->capabilities->v3AppliesToSantri($user,'v3.konseling.kelola',$santriId,$tahunId))throw new V3Exception('Santri berada di luar cakupan pembimbing.',403);
    }
    private function readMode(array $user):string
    {
        $caps=$this->capabilities->v3Capabilities($user);if(isset($caps['v3.pengawasan']))return 'admin';if(isset($caps['v3.konseling.kelola']))return 'pembimbing';if(isset($caps['v3.binaan.baca']))return 'murobi';throw new V3Exception('Akun tidak berhak membaca konseling internal.',403);
    }
    private function caseOr404(int $id):array{return $this->repo->case($id)??throw new V3Exception('Kasus tidak ditemukan.',404);}
    private function sessionWithCase(int $id):array{$session=$this->repo->session($id)??throw new V3Exception('Sesi tidak ditemukan.',404);$case=$this->caseOr404((int)$session['kasus_id']);return ['session'=>$session,'case'=>$case];}
    /**
     * Peristiwa yang dapat terjadi lebih dari sekali pada sumber yang sama memakai versi hasil agar deduplikasi tidak menelan peristiwa berikutnya.
     * Kasus Rahasia tidak pernah diberitahukan kepada murobi; kerahasiaan yang tidak diketahui diperlakukan sebagai Rahasia.
     */
    private function notifyMurobi(array $case,int $id,string $event,?int $version=null):void{if((string)($case['kerahasiaan']??'Rahasia')==='Rahasia')return;foreach($this->repo->relatedMurobiUsers((int)$case['santri_id'],(int)$case['tahun_ajaran_id']) as $recipient)$this->repo->enqueueGeneric('v3:konseling:'.$id.':'.$event.($version===null?'':':v'.$version),$event,$recipient);}

    private function serializeCase(array $row,bool $internal=false):array
    {
        $result=['id'=>(int)$row['id'],'santri_id'=>(int)$row['santri_id'],'santri_nama'=>(string)($row['nama_santri']??''),'tahun_ajaran_id'=>(int)$row['tahun_ajaran_id'],'tahun'=>(string)($row['tahun']??''),'semester'=>(string)($row['semester']??''),'kerahasiaan'=>(string)$row['kerahasiaan'],'status'=>(string)$row['status'],'dibuka_pada'=>(string)$row['dibuka_pada'],'ditutup_pada'=>$row['ditutup_pada']??null,'jumlah_sesi'=>(int)($row['jumlah_sesi']??0),'version'=>(int)$row['version'],'updated_at'=>(string)($row['updated_at']??'')];
        if($internal)$result+=['tujuan'=>(string)$row['tujuan'],'ringkasan_penutupan'=>$row['ringkasan_penutupan']??null,'alasan_revisi_terakhir'=>$row['alasan_revisi_terakhir']??null,'alasan_pembatalan'=>$row['alasan_pembatalan']??null];else $result+=['akses_terbatas'=>true];return $result;
    }
    private function serializeSession(array $row,bool $internal):array
    {
        $result=['id'=>(int)$row['id'],'kasus_id'=>(int)$row['kasus_id'],'jadwal'=>(string)$row['jadwal'],'realisasi'=>$row['realisasi']??null,'status'=>(string)$row['status'],'jadwal_berikut'=>$row['jadwal_berikut']??null,'revisi_dari_id'=>$row['revisi_dari_id']===null?null:(int)$row['revisi_dari_id'],'digantikan_oleh_id'=>$row['digantikan_oleh_id']===null?null:(int)$row['digantikan_oleh_id'],'version'=>(int)$row['version'],'created_at'=>(string)($row['created_at']??'')];
        if($internal)$result+=['ringkasan_internal'=>$row['ringkasan_internal']??null,'hasil'=>$row['hasil']??null,'tindak_lanjut'=>$row['tindak_lanjut']??null,'alasan_revisi'=>$row['alasan_revisi']??null,'alasan_penjadwalan_ulang'=>$row['alasan_penjadwalan_ulang']??null,'alasan_pembatalan'=>$row['alasan_pembatalan']??null];return $result;
    }
    private function serializeLink(array $row,bool $internal):array{$result=['id'=>(int)$row['id'],'pelanggaran_id'=>(int)$row['pelanggaran_id'],'sesi_id'=>$row['sesi_id']===null?null:(int)$row['sesi_id'],'waktu_kejadian'=>(string)$row['waktu_kejadian'],'kategori'=>(string)$row['kategori_snapshot'],'tingkat'=>(string)$row['tingkat_snapshot'],'poin'=>(int)$row['poin_snapshot'],'status'=>(string)$row['status'],'pelanggaran_digantikan_oleh_id'=>($row['digantikan_oleh_id']??null)===null?null:(int)$row['digantikan_oleh_id']];if($internal)$result['alasan']=$row['alasan']??null;return $result;}
    private function serializeViolationLink(array $row):array{return ['id'=>(int)$row['id'],'waktu_kejadian'=>(string)$row['waktu_kejadian'],'kategori'=>(string)$row['kategori_snapshot'],'tingkat'=>(string)$row['tingkat_snapshot'],'poin'=>(int)$row['poin_snapshot'],'status'=>(string)$row['status']];}
    private function serializeRecommendation(array $row):array{return ['id'=>(int)$row['id'],'santri_id'=>(int)($row['santri_id']??0),'tahun_ajaran_id'=>(int)($row['tahun_ajaran_id']??0),'santri_nama'=>(string)($row['nama_santri']??''),'label'=>(string)$row['label_snapshot'],'rekomendasi'=>(string)$row['rekomendasi_snapshot'],'total_poin'=>(int)$row['total_poin_snapshot'],'status'=>(string)($row['status']??'Baru'),'ditindaklanjuti_pada'=>$row['ditindaklanjuti_pada']??null];}
    private function serializeMurobi(array $row):array{return ['id'=>(int)$row['id'],'kasus_id'=>$row['kasus_id']===null?null:(int)$row['kasus_id'],'sesi_id'=>$row['sesi_id']===null?null:(int)$row['sesi_id'],'dilihat_pada'=>$row['dilihat_pada'],'diketahui_pada'=>$row['diketahui_pada'],'catatan'=>$row['catatan'],'sumber_version'=>(int)$row['sumber_version']];}
    private function serializeCaseRevision(array $row):array{return ['id'=>(int)$row['id'],'versi_sebelum'=>(int)$row['versi_sebelum'],'tujuan_sebelum'=>(string)$row['tujuan_sebelum'],'tujuan_sesudah'=>(string)$row['tujuan_sesudah'],'kerahasiaan_sebelum'=>(string)$row['kerahasiaan_sebelum'],'kerahasiaan_sesudah'=>(string)$row['kerahasiaan_sesudah'],'alasan'=>(string)$row['alasan'],'kapasitas'=>(string)$row['kapasitas'],'dikoreksi_oleh_user_id'=>(int)$row['created_by'],'created_at'=>(string)$row['created_at']];}
    private function auditCase(array $row):array{return ['id'=>(int)$row['id'],'santri_id'=>(int)$row['santri_id'],'tahun_ajaran_id'=>(int)$row['tahun_ajaran_id'],'tujuan'=>$row['tujuan'],'kerahasiaan'=>$row['kerahasiaan'],'status'=>$row['status'],'ringkasan_penutupan'=>$row['ringkasan_penutupan']??null,'alasan_revisi_terakhir'=>$row['alasan_revisi_terakhir']??null,'alasan_pembatalan'=>$row['alasan_pembatalan']??null,'version'=>(int)$row['version']];}
    private function auditSession(array $row):array{return ['id'=>(int)$row['id'],'kasus_id'=>(int)$row['kasus_id'],'jadwal'=>$row['jadwal'],'realisasi'=>$row['realisasi'],'status'=>$row['status'],'ringkasan_internal'=>$row['ringkasan_internal'],'hasil'=>$row['hasil'],'tindak_lanjut'=>$row['tindak_lanjut'],'jadwal_berikut'=>$row['jadwal_berikut'],'revisi_dari_id'=>$row['revisi_dari_id'],'alasan_revisi'=>$row['alasan_revisi']??null,'alasan_penjadwalan_ulang'=>$row['alasan_penjadwalan_ulang']??null,'alasan_pembatalan'=>$row['alasan_pembatalan']??null,'version'=>(int)$row['version']];}

    private function replay(array $idem,string $hash):array{if(!hash_equals((string)$idem['request_hash'],$hash))throw new V3Exception('Idempotency key sudah dipakai untuk isi permintaan lain.',409);$data=json_decode((string)$idem['response_json'],true);if(!is_array($data))throw new V3Exception('Permintaan identik masih diproses. Coba lagi.',409);return ['data'=>$data,'status'=>(int)($idem['status_code']??200),'replayed'=>true];}
    private function auditRequired(string $action,string $entity,int $id,?array $before,array $after,int $actorId):void{if(!$this->audit->log($action,$entity,$id,$before,$after,$actorId))throw new V3Exception('Audit wajib tidak dapat disimpan.',503);}
    private function actorId(array $user):int{$id=(int)($user['id']??0);if($id<1)throw new V3Exception('Akun tidak valid.',403);return $id;}
    private function source(array $user,string $capability):string{return (string)($this->capabilities->v3Capabilities($user)[$capability]['sumber']??'');}
    private function idempotencyKey(array $input):string{$value=$input['idempotency_key']??null;if(!is_string($value)||!preg_match('/^[A-Za-z0-9._:-]{8,100}$/D',$value))throw new V3Exception('Idempotency key wajib 8-100 karakter aman.');return $value;}
    private function positiveInt(array $input,string $key):int{$v=$input[$key]??null;if(!is_scalar($v)||!preg_match('/^[1-9][0-9]*$/D',(string)$v)||(float)$v>2147483647)throw new V3Exception('Nilai '.$key.' tidak valid.');return (int)$v;}
    private function idList(mixed $value,string $label):array{if($value===null||$value==='')return [];if(!is_array($value))$value=[$value];$ids=[];foreach($value as $item){if(!is_scalar($item)||!preg_match('/^[1-9][0-9]*$/D',(string)$item))throw new V3Exception($label.' tidak valid.');$ids[]=(int)$item;}return array_values(array_unique($ids));}
    private function enum(mixed $value,array $allowed,string $label):string{if(!is_string($value)||!in_array($value,$allowed,true))throw new V3Exception($label.' tidak valid.');return $value;}
    private function requiredText(mixed $value,int $max,string $label):string{if(!is_string($value))throw new V3Exception($label.' wajib berupa teks.');$v=trim($value);if($v===''||mb_strlen($v)>$max)throw new V3Exception($label.' wajib diisi dan maksimum '.$max.' karakter.');return $v;}
    private function optionalText(mixed $value,int $max,string $label):?string{if($value===null||$value==='')return null;if(!is_string($value))throw new V3Exception($label.' wajib berupa teks.');$v=trim($value);if(mb_strlen($v)>$max)throw new V3Exception($label.' maksimum '.$max.' karakter.');return $v===''?null:$v;}
    private function reason(mixed $value):string{$reason=$this->requiredText($value,1000,'Alasan');if(mb_strlen($reason)<5)throw new V3Exception('Alasan minimal 5 karakter.');return $reason;}
    private function dateTime(mixed $value,bool $allowFuture,string $label):string{if(!is_string($value)||trim($value)==='')throw new V3Exception($label.' wajib diisi.');$raw=str_replace('T',' ',trim($value));$format=strlen($raw)===16?'Y-m-d H:i':'Y-m-d H:i:s';$date=DateTimeImmutable::createFromFormat('!'.$format,$raw);$errors=DateTimeImmutable::getLastErrors();if(!$date||($errors!==false&&($errors['warning_count']>0||$errors['error_count']>0))||$date->format($format)!==$raw)throw new V3Exception($label.' tidak valid.');if(!$allowFuture&&$date->getTimestamp()>time()+300)throw new V3Exception($label.' tidak boleh di masa depan.');return $date->format('Y-m-d H:i:s');}
    private function requestHash(string $operation,array $data):string{$json=json_encode([$operation,$data],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($json===false)throw new V3Exception('Permintaan tidak dapat diproses.');return hash('sha256',$json);}
}
