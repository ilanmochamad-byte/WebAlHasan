<?php

declare(strict_types=1);

namespace App\V3;

use App\Audit\AuditLogger;
use App\Auth\Capabilities;
use DateTimeImmutable;
use Throwable;

/** Aturan bisnis tunggal pelanggaran Fase 2 untuk web dan API. */
final class PelanggaranService
{
    public function __construct(
        private PelanggaranRepository $repo,
        private Capabilities $capabilities,
        private AuditLogger $audit,
        private AttachmentStorage $storage
    ) {
    }

    /** @return array{data:array<string,mixed>,status:int,replayed:bool} */
    public function create(array $user, array $input, ?array $attachment = null): array
    {
        try {
            $actorId = $this->actorId($user);
            $data = $this->normaliseCreate($input);
        } catch (Throwable $exception) {
            $this->storage->discard($attachment);
            throw $exception;
        }
        $caps = $this->capabilities->v3Capabilities($user);
        if (!isset($caps['v3.pelanggaran.kelola'])
            || !$this->capabilities->v3AppliesToSantri($user,'v3.pelanggaran.kelola',$data['santri_id'],$data['tahun_ajaran_id'])) {
            $this->storage->discard($attachment);
            throw new V3Exception('Santri berada di luar cakupan pembimbing.',403);
        }
        $date = substr($data['waktu_kejadian'],0,10);
        $assignment = $this->repo->pembimbingAssignment($actorId,$data['santri_id'],$data['tahun_ajaran_id'],$date);
        if ($assignment === null) {
            $this->storage->discard($attachment);
            throw new V3Exception('Penugasan pembimbing tidak berlaku pada tanggal kejadian.',403);
        }
        $requestHash = $this->requestHash('create',$data,$attachment);
        try {
            return $this->repo->transaction(function () use ($user,$actorId,$data,$assignment,$requestHash,&$attachment): array {
                $this->repo->lockSubject($data['santri_id'],$data['tahun_ajaran_id'],$actorId);
                $idem = $this->repo->claimIdempotency($actorId,'v3.pelanggaran.create',$data['idempotency_key'],$requestHash);
                if ($idem['response_json'] !== null) {
                    $this->storage->discard($attachment); $attachment=null;
                    return $this->replay($idem,$requestHash);
                }
                $catalog = $this->repo->activeKatalog($data['katalog_id'],substr($data['waktu_kejadian'],0,10),true);
                if ($catalog === null) { throw new V3Exception('Katalog tidak aktif pada tanggal kejadian.',422); }
                $row = $this->violationRow($data,$catalog,$assignment,$capsSource=$this->source($user,'v3.pelanggaran.kelola'),$actorId,null,null,'Dicatat');
                $id = $this->repo->insertViolation($row);
                $ledgerId = $this->repo->insertLedger($id,$data['santri_id'],$data['tahun_ajaran_id'],(int)$row['poin_snapshot'],'Pencatatan pelanggaran','v3:pelanggaran:'.$id.':dicatat',null,$actorId);
                if ($attachment !== null) {
                    $this->storage->finalize($attachment);
                    $this->repo->insertAttachment($id,1,$attachment,$actorId);
                }
                $total = $this->repo->reconcile($data['santri_id'],$data['tahun_ajaran_id'],$actorId);
                $validity = $this->repo->refreshRecommendationValidity($data['santri_id'],$data['tahun_ajaran_id'],$total,$actorId);
                $recommendationIds = $this->createRecommendations($data['santri_id'],$data['tahun_ajaran_id'],$id,$total,$actorId);
                $this->notifyMurobi($data['santri_id'],$data['tahun_ajaran_id'],$id,'v3_pelanggaran_dicatat');
                $saved = $this->repo->violation($id) ?? throw new V3Exception('Catatan tidak ditemukan.',503);
                $payload = [
                    'pelanggaran'=>$this->serializeViolation($saved),
                    'total_poin'=>$total,
                    'rekomendasi_baru'=>$recommendationIds,
                    'rekomendasi_disesuaikan'=>$validity,
                    'peringatan_konfigurasi'=>$this->repo->configurationWarnings(),
                ];
                $this->auditRequired('v3.pelanggaran.dicatat','v3_pelanggaran',$id,null,[
                    'pelanggaran'=>$this->serializeViolation($saved),'ledger_id'=>$ledgerId,
                    'total_poin'=>$total,'rekomendasi_ids'=>$recommendationIds,
                    'rekomendasi_disesuaikan'=>$validity,'sumber_capability'=>$capsSource,
                ],$actorId);
                $this->repo->completeIdempotency((int)$idem['id'],$payload,201);
                return ['data'=>$payload,'status'=>201,'replayed'=>false];
            });
        } catch (Throwable $exception) {
            $this->storage->discard($attachment);
            throw $exception;
        }
    }

    /** @return array{data:array<string,mixed>,status:int,replayed:bool} */
    public function correct(array $user, int $id, array $input, ?array $attachment = null): array
    {
        try {
            $actorId=$this->actorId($user); $probe=$this->repo->violation($id);
            if ($probe===null) { throw new V3Exception('Pelanggaran tidak ditemukan.',404); }
            $capacity=$this->mutationCapacity($user,$probe);
            $reason=$this->reason($input['alasan']??null);
            $version=$this->positiveInt($input,'version');
            $data=$this->normaliseCorrection($input,$probe,$capacity==='admin');
        } catch (Throwable $exception) {
            $this->storage->discard($attachment);
            throw $exception;
        }
        $requestHash=$this->requestHash('correct',[$id,$version,$reason,$data],$attachment);
        try {
            return $this->repo->transaction(function()use($user,$actorId,$id,$probe,$capacity,$reason,$version,$data,$requestHash,&$attachment):array{
                $this->repo->lockSubject((int)$probe['santri_id'],(int)$probe['tahun_ajaran_id'],$actorId);
                $idem=$this->repo->claimIdempotency($actorId,'v3.pelanggaran.correct:'.$id,$data['idempotency_key'],$requestHash);
                if($idem['response_json']!==null){$this->storage->discard($attachment);$attachment=null;return $this->replay($idem,$requestHash);}
                $current=$this->repo->violation($id,true);
                if($current===null){throw new V3Exception('Pelanggaran tidak ditemukan.',404);}
                if((int)$current['version']!==$version || $current['digantikan_oleh_id']!==null){throw new V3Exception('Versi sudah berubah. Muat ulang data.',409);}
                if($current['status']==='Dibatalkan'){throw new V3Exception('Pelanggaran yang dibatalkan tidak dapat dikoreksi.',409);}
                if($capacity!=='admin'){$this->assertOperationalScope($user,$current,substr($data['waktu_kejadian'],0,10));}
                $sameCatalog=(int)$current['katalog_id']===$data['katalog_id'];
                if($sameCatalog){
                    $catalog=['id'=>$data['katalog_id'],'kategori_nama'=>$current['kategori_snapshot'],'tingkat'=>$current['tingkat_snapshot'],'poin_default'=>(int)$current['poin_snapshot']];
                }else{
                    $catalog=$this->repo->activeKatalog($data['katalog_id'],substr($data['waktu_kejadian'],0,10),true);
                    if($catalog===null){throw new V3Exception('Katalog tidak aktif pada tanggal kejadian.',422);}
                }
                if($capacity==='admin' && array_key_exists('poin',$data)){$catalog['poin_default']=$data['poin'];}
                $assignment=$capacity==='admin' ? ['id'=>$current['pembimbing_assignment_id'],'pengurus_id'=>$current['pembimbing_id'],'target_type'=>'Admin','kelas_id'=>null,'kamar_id'=>null] : $this->repo->pembimbingAssignment($actorId,(int)$current['santri_id'],(int)$current['tahun_ajaran_id'],substr($data['waktu_kejadian'],0,10));
                if($assignment===null){throw new V3Exception('Penugasan pembimbing tidak berlaku pada tanggal kejadian.',403);}
                $row=$this->violationRow($data,$catalog,$assignment,$capacity,$actorId,$id,$reason,(string)$current['status']);
                // Catatan sumber melepas fingerprint-nya lebih dahulu. Koreksi yang
                // tidak mengubah isi -- misalnya hanya menambah alasan atau
                // melampirkan bukti belakangan -- kalau tidak akan bertabrakan
                // dengan catatan yang justru sedang dikoreksi. Keduanya berada
                // dalam satu transaksi, dan pemeriksaan versi jadi gagal-cepat
                // sebelum ada baris revisi yang tertulis.
                if(!$this->repo->updateViolationVersion($id,$version,$actorId)){throw new V3Exception('Versi sudah berubah. Muat ulang data.',409);}
                $newId=$this->repo->insertViolation($row);
                $positive=$this->repo->positiveLedger($id)??throw new V3Exception('Ledger sumber tidak ditemukan.',503);
                $reverseId=$this->repo->insertLedger($id,(int)$current['santri_id'],(int)$current['tahun_ajaran_id'],-(int)$positive['perubahan_poin'],'Pembalik karena koreksi','v3:pelanggaran:'.$id.':koreksi-pembalik:'.$newId,(int)$positive['id'],$actorId);
                $ledgerId=$this->repo->insertLedger($newId,(int)$current['santri_id'],(int)$current['tahun_ajaran_id'],(int)$row['poin_snapshot'],'Poin hasil koreksi','v3:pelanggaran:'.$newId.':koreksi',null,$actorId);
                if($attachment!==null){$this->storage->finalize($attachment);$this->repo->insertAttachment($newId,1,$attachment,$actorId);}
                $total=$this->repo->reconcile((int)$current['santri_id'],(int)$current['tahun_ajaran_id'],$actorId);
                $validity=$this->repo->refreshRecommendationValidity((int)$current['santri_id'],(int)$current['tahun_ajaran_id'],$total,$actorId);
                $recommendationIds=$this->createRecommendations((int)$current['santri_id'],(int)$current['tahun_ajaran_id'],$newId,$total,$actorId);
                $this->notifyMurobi((int)$current['santri_id'],(int)$current['tahun_ajaran_id'],$newId,'v3_pelanggaran_dikoreksi');
                $saved=$this->repo->violation($newId)??throw new V3Exception('Hasil koreksi tidak ditemukan.',503);
                $payload=['pelanggaran'=>$this->serializeViolation($saved),'total_poin'=>$total,'rekomendasi_baru'=>$recommendationIds,'rekomendasi_disesuaikan'=>$validity,'peringatan_konfigurasi'=>$this->repo->configurationWarnings()];
                $this->auditRequired('v3.pelanggaran.dikoreksi.'.$capacity,'v3_pelanggaran',$newId,$this->auditViolation($current),[
                    'pelanggaran'=>$this->auditViolation($saved),'alasan'=>$reason,'pembalik_ledger_id'=>$reverseId,'ledger_id'=>$ledgerId,'total_poin'=>$total,'rekomendasi_disesuaikan'=>$validity,'kapasitas'=>$capacity
                ],$actorId);
                $this->repo->completeIdempotency((int)$idem['id'],$payload,200);
                return ['data'=>$payload,'status'=>200,'replayed'=>false];
            });
        }catch(Throwable $exception){$this->storage->discard($attachment);throw $exception;}
    }

    /** @return array{data:array<string,mixed>,status:int,replayed:bool} */
    public function cancel(array $user,int $id,array $input):array
    {
        $actorId=$this->actorId($user);$probe=$this->repo->violation($id);
        if($probe===null){throw new V3Exception('Pelanggaran tidak ditemukan.',404);}
        $capacity=$this->mutationCapacity($user,$probe);$reason=$this->reason($input['alasan']??null);
        $version=$this->positiveInt($input,'version');$key=$this->idempotencyKey($input);
        $hash=$this->requestHash('cancel',[$id,$version,$reason],null);
        return $this->repo->transaction(function()use($user,$actorId,$id,$probe,$capacity,$reason,$version,$key,$hash):array{
            $this->repo->lockSubject((int)$probe['santri_id'],(int)$probe['tahun_ajaran_id'],$actorId);
            $idem=$this->repo->claimIdempotency($actorId,'v3.pelanggaran.cancel:'.$id,$key,$hash);
            if($idem['response_json']!==null){return $this->replay($idem,$hash);}
            $current=$this->repo->violation($id,true);
            if($current===null){throw new V3Exception('Pelanggaran tidak ditemukan.',404);}
            if((int)$current['version']!==$version || $current['digantikan_oleh_id']!==null){throw new V3Exception('Versi sudah berubah. Muat ulang data.',409);}
            if($capacity!=='admin'){$this->assertOperationalScope($user,$current,substr((string)$current['waktu_kejadian'],0,10));}
            if(!$this->repo->cancelViolation($id,$version,$reason,$actorId)){throw new V3Exception('Pelanggaran sudah berubah atau dibatalkan.',409);}
            $positive=$this->repo->positiveLedger($id)??throw new V3Exception('Ledger sumber tidak ditemukan.',503);
            $reverseId=$this->repo->insertLedger($id,(int)$current['santri_id'],(int)$current['tahun_ajaran_id'],-(int)$positive['perubahan_poin'],'Pembalik karena pembatalan','v3:pelanggaran:'.$id.':dibatalkan',(int)$positive['id'],$actorId);
            $total=$this->repo->reconcile((int)$current['santri_id'],(int)$current['tahun_ajaran_id'],$actorId);
            $validity=$this->repo->refreshRecommendationValidity((int)$current['santri_id'],(int)$current['tahun_ajaran_id'],$total,$actorId);
            $this->notifyMurobi((int)$current['santri_id'],(int)$current['tahun_ajaran_id'],$id,'v3_pelanggaran_dibatalkan');
            if($capacity==='admin'){$this->notifyPembimbing((int)$current['santri_id'],(int)$current['tahun_ajaran_id'],$id,'v3_pelanggaran_dibatalkan_admin');}
            $saved=$this->repo->violation($id)??throw new V3Exception('Pelanggaran tidak ditemukan.',503);
            $payload=['pelanggaran'=>$this->serializeViolation($saved),'total_poin'=>$total,'rekomendasi_disesuaikan'=>$validity];
            $this->auditRequired('v3.pelanggaran.dibatalkan.'.$capacity,'v3_pelanggaran',$id,$this->auditViolation($current),[
                'pelanggaran'=>$this->auditViolation($saved),'alasan'=>$reason,'pembalik_ledger_id'=>$reverseId,'total_poin'=>$total,'rekomendasi_disesuaikan'=>$validity,'kapasitas'=>$capacity
            ],$actorId);
            $this->repo->completeIdempotency((int)$idem['id'],$payload,200);
            return ['data'=>$payload,'status'=>200,'replayed'=>false];
        });
    }

    /** @return array{data:array<string,mixed>,status:int,replayed:bool} */
    public function acknowledge(array $user,int $id,array $input):array
    {
        $actorId=$this->actorId($user);$probe=$this->repo->violation($id);
        if($probe===null){throw new V3Exception('Pelanggaran tidak ditemukan.',404);}
        if(!$this->capabilities->v3AppliesToSantri($user,'v3.murobi.mengetahui',(int)$probe['santri_id'],(int)$probe['tahun_ajaran_id'])){throw new V3Exception('Pelanggaran berada di luar cakupan murobi.',403);}
        $assignment=$this->repo->murobiAssignment($actorId,(int)$probe['santri_id'],(int)$probe['tahun_ajaran_id']);
        if($assignment===null){throw new V3Exception('Penugasan murobi tidak berlaku.',403);}
        $key=$this->idempotencyKey($input);$note=$this->optionalText($input['catatan']??null,2000,'Catatan');
        $hash=$this->requestHash('acknowledge',[$id,$note],null);
        return $this->repo->transaction(function()use($actorId,$id,$probe,$assignment,$key,$note,$hash):array{
            $this->repo->lockSubject((int)$probe['santri_id'],(int)$probe['tahun_ajaran_id'],$actorId);
            $idem=$this->repo->claimIdempotency($actorId,'v3.pelanggaran.acknowledge:'.$id,$key,$hash);
            if($idem['response_json']!==null){return $this->replay($idem,$hash);}
            $current=$this->repo->violation($id,true);
            if($current===null){throw new V3Exception('Pelanggaran tidak ditemukan.',404);}
            $event='v3:pelanggaran:'.$id.':diketahui:guru:'.(int)$assignment['guru_id'];
            $noteId=$this->repo->insertMurobiNote($id,(int)$current['version'],(int)$assignment['guru_id'],(int)$assignment['id'],$note,$event,$actorId);
            $payload=['id'=>$noteId,'pelanggaran_id'=>$id,'diketahui_pada'=>(new DateTimeImmutable())->format('Y-m-d H:i:s')];
            $this->auditRequired('v3.pelanggaran.murobi.diketahui','v3_murobi_catatan',$noteId,null,[
                'pelanggaran_id'=>$id,'sumber_version'=>(int)$current['version'],'catatan'=>$note
            ],$actorId);
            $this->repo->completeIdempotency((int)$idem['id'],$payload,201);
            return ['data'=>$payload,'status'=>201,'replayed'=>false];
        });
    }

    public function page(array $user,array $filters):array
    {
        $mode=$this->readMode($user);$result=$this->repo->page($this->actorId($user),$mode,$filters);
        $result['rows']=array_map(fn(array $row):array=>$this->serializeViolation($row),$result['rows']);
        $result['peringatan_konfigurasi']=$this->repo->configurationWarnings();
        return $result;
    }

    public function show(array $user,int $id):array
    {
        $mode=$this->readMode($user);$row=$this->repo->visibleViolation($id,$this->actorId($user),$mode);
        if($row===null){throw new V3Exception('Pelanggaran berada di luar cakupan pengguna.',403);}
        $ledger=$this->repo->ledgerHistory((int)$row['santri_id'],(int)$row['tahun_ajaran_id']);
        $ledgerTotal=array_sum(array_map(static fn(array $item):int=>(int)$item['perubahan_poin'],$ledger));
        $aggregate=$this->repo->aggregate((int)$row['santri_id'],(int)$row['tahun_ajaran_id']);
        $history=$this->historyRows($row);
        return [
            'pelanggaran'=>$this->serializeViolation($row,true),
            'riwayat_revisi'=>$history,
            'ledger'=>array_map([$this,'serializeLedger'],$ledger),
            'total_poin'=>$ledgerTotal,
            'rekonsiliasi'=>['agregat'=>(int)($aggregate['total_poin']??0),'ledger'=>$ledgerTotal,'selisih'=>(int)($aggregate['total_poin']??0)-$ledgerTotal],
            'rekomendasi'=>array_map([$this,'serializeRecommendation'],$this->repo->recommendations((int)$row['santri_id'],(int)$row['tahun_ajaran_id'])),
            'murobi'=>array_map([$this,'serializeMurobi'],$this->repo->murobiNotes((int)$row['id'])),
            'lampiran'=>array_map([$this,'serializeAttachment'],$this->repo->attachments((int)$row['id'])),
            // Tindak lanjut dibaca pada seluruh rantai revisi agar koreksi tidak memutus tampilannya.
            'konseling'=>array_map([$this,'serializeCounselingLink'],$this->repo->counselingForViolations(array_column($history,'id'))),
            'peringatan_konfigurasi'=>$this->repo->configurationWarnings(),
        ];
    }

    public function history(array $user,int $id):array
    {
        return ['rows'=>$this->show($user,$id)['riwayat_revisi']];
    }

    public function options(array $user):array
    {
        $caps=$this->capabilities->v3Capabilities($user);
        $students=isset($caps['v3.pelanggaran.kelola'])?$this->repo->studentOptions($this->actorId($user)):[];
        return [
            'santri'=>array_map(static fn(array $row):array=>[
                'santri_id'=>(int)$row['santri_id'],'tahun_ajaran_id'=>(int)$row['tahun_ajaran_id'],
                'nama'=>(string)$row['nama_santri'],'tahun'=>(string)$row['tahun'],'semester'=>(string)$row['semester'],
            ],$students),
            'katalog'=>array_map(static fn(array $row):array=>[
                'id'=>(int)$row['id'],'kode'=>(string)$row['kode'],'nama'=>(string)$row['nama'],
                'kategori'=>(string)$row['kategori_nama'],'tingkat'=>(string)$row['tingkat'],'poin_default'=>(int)$row['poin_default'],
            ],$this->repo->katalogOptions()),
            'dapat_mencatat'=>$students!==[],
            'peringatan_konfigurasi'=>$this->repo->configurationWarnings(),
        ];
    }

    /** Metadata + path internal hanya untuk controller unduhan setelah otorisasi. */
    public function attachment(array $user,int $id):array
    {
        $file=$this->repo->attachment($id);if($file===null){throw new V3Exception('Lampiran tidak ditemukan.',404);}
        $mode=$this->readMode($user);
        if($this->repo->visibleViolation((int)$file['pelanggaran_id'],$this->actorId($user),$mode)===null){throw new V3Exception('Lampiran berada di luar cakupan pengguna.',403);}
        return ['id'=>(int)$file['id'],'name'=>(string)$file['nama_aman'],'mime'=>(string)$file['mime'],'size'=>(int)$file['ukuran'],'sha256'=>(string)$file['sha256'],'path'=>$this->storage->absolute((string)$file['lokasi_privat'])];
    }

    public function configurationWarnings():array{return $this->repo->configurationWarnings();}

    private function normaliseCreate(array $input):array
    {
        return [
            'santri_id'=>$this->positiveInt($input,'santri_id'),'tahun_ajaran_id'=>$this->positiveInt($input,'tahun_ajaran_id'),
            'katalog_id'=>$this->positiveInt($input,'katalog_id'),'waktu_kejadian'=>$this->dateTime($input['waktu_kejadian']??null),
            'tempat'=>$this->optionalText($input['tempat']??null,255,'Tempat'),'uraian'=>$this->requiredText($input['uraian']??null,5000,'Uraian'),
            'saksi'=>$this->optionalText($input['saksi']??null,2000,'Saksi'),'idempotency_key'=>$this->idempotencyKey($input),
        ];
    }

    private function normaliseCorrection(array $input,array $old,bool $admin):array
    {
        $data=[
            'santri_id'=>(int)$old['santri_id'],'tahun_ajaran_id'=>(int)$old['tahun_ajaran_id'],
            'katalog_id'=>isset($input['katalog_id'])?$this->positiveInt($input,'katalog_id'):(int)$old['katalog_id'],
            'waktu_kejadian'=>isset($input['waktu_kejadian'])?$this->dateTime($input['waktu_kejadian']):(string)$old['waktu_kejadian'],
            'tempat'=>array_key_exists('tempat',$input)?$this->optionalText($input['tempat'],255,'Tempat'):$old['tempat'],
            'uraian'=>array_key_exists('uraian',$input)?$this->requiredText($input['uraian'],5000,'Uraian'):(string)$old['uraian'],
            'saksi'=>array_key_exists('saksi',$input)?$this->optionalText($input['saksi'],2000,'Saksi'):$old['saksi'],
            'idempotency_key'=>$this->idempotencyKey($input),
        ];
        if(isset($input['poin'])){
            if(!$admin){throw new V3Exception('Penyesuaian poin hanya tersedia melalui koreksi admin.',403);}
            $data['poin']=$this->nonNegativeInt($input,'poin');
        }
        return $data;
    }

    private function violationRow(array $data,array $catalog,array $assignment,string $source,int $actorId,?int $revisionId,?string $reason,string $status):array
    {
        $snapshot=json_encode(['assignment_id'=>$assignment['id']??null,'target_type'=>$assignment['target_type']??null,'kelas_id'=>$assignment['kelas_id']??null,'kamar_id'=>$assignment['kamar_id']??null,'sumber_capability'=>$source],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $fingerprint=hash('sha256',json_encode([
            (int)$data['santri_id'],(int)$data['tahun_ajaran_id'],(int)$catalog['id'],$data['waktu_kejadian'],
            $this->fingerprintText($data['tempat']),$this->fingerprintText($data['uraian']),$this->fingerprintText($data['saksi']),
            (string)$catalog['kategori_nama'],(string)$catalog['tingkat'],(int)$catalog['poin_default']
        ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        return [
            'santri_id'=>(int)$data['santri_id'],'tahun_ajaran_id'=>(int)$data['tahun_ajaran_id'],
            'pembimbing_id'=>$assignment['pengurus_id']===null?null:(int)$assignment['pengurus_id'],
            'pembimbing_assignment_id'=>$assignment['id']===null?null:(int)$assignment['id'],'katalog_id'=>(int)$catalog['id'],
            'waktu_kejadian'=>$data['waktu_kejadian'],'tempat'=>$data['tempat'],'uraian'=>$data['uraian'],'saksi'=>$data['saksi'],
            'kategori_snapshot'=>(string)$catalog['kategori_nama'],'tingkat_snapshot'=>(string)$catalog['tingkat'],'poin_snapshot'=>(int)$catalog['poin_default'],
            'cakupan_snapshot'=>$snapshot===false?null:$snapshot,'status'=>$status,'fingerprint'=>$fingerprint,
            'idempotency_key'=>$data['idempotency_key'],'revisi_dari_id'=>$revisionId,'alasan_revisi'=>$reason,'created_by'=>$actorId,'updated_by'=>$actorId,
        ];
    }

    private function mutationCapacity(array $user,array $row):string
    {
        $caps=$this->capabilities->v3Capabilities($user);
        if(isset($caps['v3.koreksi'])){return 'admin';}
        if(!isset($caps['v3.pelanggaran.kelola']) || !$this->capabilities->v3AppliesToSantri($user,'v3.pelanggaran.kelola',(int)$row['santri_id'],(int)$row['tahun_ajaran_id'])){throw new V3Exception('Pelanggaran berada di luar cakupan pembimbing.',403);}
        return 'pembimbing';
    }

    private function assertOperationalScope(array $user,array $row,string $date):void
    {
        if(!$this->capabilities->v3AppliesToSantri($user,'v3.pelanggaran.kelola',(int)$row['santri_id'],(int)$row['tahun_ajaran_id']) || $this->repo->pembimbingAssignment($this->actorId($user),(int)$row['santri_id'],(int)$row['tahun_ajaran_id'],$date)===null){throw new V3Exception('Pelanggaran berada di luar cakupan pembimbing.',403);}
    }

    private function readMode(array $user):string
    {
        $caps=$this->capabilities->v3Capabilities($user);
        if(isset($caps['v3.pengawasan']))return 'admin';
        if(isset($caps['v3.pelanggaran.kelola']))return 'pembimbing';
        if(isset($caps['v3.binaan.baca']))return 'murobi';
        throw new V3Exception('Akun tidak berhak membaca pelanggaran.',403);
    }

    /** @return array<int,int> */
    private function createRecommendations(int $santriId,int $tahunId,int $violationId,int $total,int $actorId):array
    {
        $created=[];
        foreach($this->repo->activeThresholds($tahunId,$total) as $threshold){
            $id=$this->repo->insertRecommendation($santriId,$tahunId,$threshold,$violationId,$total,$actorId);
            if($id===null)continue;$created[]=$id;
            foreach($this->repo->relatedPembimbingUsers($santriId,$tahunId) as $recipient){
                $this->repo->enqueueGeneric('v3:rekomendasi:'.$id,'v3_rekomendasi_baru',$recipient,'Rekomendasi tindak lanjut tersedia','Ada rekomendasi pembinaan baru. Masuk untuk melihat sesuai kewenangan.','{"type":"v3_rekomendasi"}');
            }
        }
        return $created;
    }

    private function notifyMurobi(int $santriId,int $tahunId,int $id,string $event):void
    {
        foreach($this->repo->relatedMurobiUsers($santriId,$tahunId) as $recipient){
            $this->repo->enqueueGeneric('v3:pelanggaran:'.$id.':'.$event,$event,$recipient,'Pembaruan pembinaan','Ada pembaruan pembinaan baru. Masuk untuk melihat sesuai kewenangan.','{"type":"v3_pelanggaran"}');
        }
    }

    private function notifyPembimbing(int $santriId,int $tahunId,int $id,string $event):void
    {
        foreach($this->repo->relatedPembimbingUsers($santriId,$tahunId) as $recipient){
            $this->repo->enqueueGeneric('v3:pelanggaran:'.$id.':'.$event,$event,$recipient,'Pembaruan pembinaan','Ada pembaruan pembinaan baru. Masuk untuk melihat sesuai kewenangan.','{"type":"v3_pelanggaran"}');
        }
    }

    private function historyRows(array $row):array
    {
        $root=$row;$seen=[];
        while($root['revisi_dari_id']!==null && count($seen)<100){$seen[(int)$root['id']]=true;$parent=$this->repo->violation((int)$root['revisi_dari_id']);if($parent===null||isset($seen[(int)$parent['id']]))break;$root=$parent;}
        $rows=[];$current=$root;$seen=[];
        while($current!==null && count($rows)<100 && !isset($seen[(int)$current['id']])){$seen[(int)$current['id']]=true;$rows[]=$this->serializeViolation($current,true);$children=$this->repo->directRevisions((int)$current['id']);$current=$children===[]?null:$this->repo->violation((int)$children[0]['id']);}
        return $rows;
    }

    private function serializeViolation(array $row,bool $detail=false):array
    {
        $result=[
            'id'=>(int)$row['id'],'santri_id'=>(int)$row['santri_id'],'santri_nama'=>(string)($row['nama_santri']??''),
            'tahun_ajaran_id'=>(int)$row['tahun_ajaran_id'],'tahun'=>(string)($row['tahun']??''),'semester'=>(string)($row['semester']??''),
            'waktu_kejadian'=>(string)$row['waktu_kejadian'],'tempat'=>$row['tempat']===null?null:(string)$row['tempat'],
            'kategori'=>(string)$row['kategori_snapshot'],'tingkat'=>(string)$row['tingkat_snapshot'],'poin'=>(int)$row['poin_snapshot'],
            'status'=>(string)$row['status'],'version'=>(int)$row['version'],'created_at'=>(string)($row['created_at']??''),
        ];
        if($detail){$result+=['uraian'=>(string)$row['uraian'],'saksi'=>$row['saksi']===null?null:(string)$row['saksi'],'katalog_id'=>$row['katalog_id']===null?null:(int)$row['katalog_id'],'katalog_kode'=>$row['katalog_kode']??null,'revisi_dari_id'=>$row['revisi_dari_id']===null?null:(int)$row['revisi_dari_id'],'digantikan_oleh_id'=>$row['digantikan_oleh_id']===null?null:(int)$row['digantikan_oleh_id'],'alasan_revisi'=>$row['alasan_revisi']===null?null:(string)$row['alasan_revisi'],'alasan_pembatalan'=>($row['alasan_pembatalan']??null)===null?null:(string)$row['alasan_pembatalan']];}
        return $result;
    }

    private function serializeLedger(array $row):array{return ['id'=>(int)$row['id'],'pelanggaran_id'=>(int)$row['pelanggaran_id'],'perubahan_poin'=>(int)$row['perubahan_poin'],'alasan'=>(string)$row['alasan'],'pembalik_dari_id'=>$row['pembalik_dari_id']===null?null:(int)$row['pembalik_dari_id'],'created_at'=>(string)$row['created_at']];}
    private function serializeRecommendation(array $row):array{return ['id'=>(int)$row['id'],'ambang_id'=>(int)$row['ambang_id'],'dipicu_oleh_pelanggaran_id'=>(int)$row['dipicu_oleh_pelanggaran_id'],'total_poin'=>(int)$row['total_poin_snapshot'],'label'=>(string)$row['label_snapshot'],'rekomendasi'=>(string)$row['rekomendasi_snapshot'],'status'=>(string)$row['status'],'berlaku'=>($row['tidak_berlaku_pada']??null)===null,'tidak_berlaku_pada'=>$row['tidak_berlaku_pada']??null,'tidak_berlaku_alasan'=>$row['tidak_berlaku_alasan']??null,'created_at'=>(string)$row['created_at']];}
    private function serializeMurobi(array $row):array{return ['id'=>(int)$row['id'],'dilihat_pada'=>$row['dilihat_pada'],'diketahui_pada'=>$row['diketahui_pada'],'catatan'=>$row['catatan'],'sumber_version'=>(int)$row['sumber_version']];}
    private function serializeAttachment(array $row):array{return ['id'=>(int)$row['id'],'nama'=>(string)$row['nama_aman'],'mime'=>(string)$row['mime'],'ukuran'=>(int)$row['ukuran'],'sha256'=>(string)$row['sha256'],'created_at'=>(string)$row['created_at']];}
    private function serializeCounselingLink(array $row):array{return ['kasus_id'=>(int)$row['kasus_id'],'kasus_status'=>(string)$row['kasus_status'],'sesi_id'=>$row['sesi_id']===null?null:(int)$row['sesi_id'],'sesi_status'=>$row['sesi_status']??null,'jadwal'=>$row['jadwal']??null,'realisasi'=>$row['realisasi']??null,'jadwal_berikut'=>$row['jadwal_berikut']??null];}
    private function auditViolation(array $row):array{return ['id'=>(int)$row['id'],'santri_id'=>(int)$row['santri_id'],'tahun_ajaran_id'=>(int)$row['tahun_ajaran_id'],'waktu_kejadian'=>$row['waktu_kejadian'],'tempat'=>$row['tempat'],'uraian'=>$row['uraian'],'saksi'=>$row['saksi'],'kategori_snapshot'=>$row['kategori_snapshot'],'tingkat_snapshot'=>$row['tingkat_snapshot'],'poin_snapshot'=>(int)$row['poin_snapshot'],'status'=>$row['status'],'alasan_revisi'=>$row['alasan_revisi']??null,'alasan_pembatalan'=>$row['alasan_pembatalan']??null,'version'=>(int)$row['version']];}

    private function replay(array $idem,string $hash):array
    {
        if(!hash_equals((string)$idem['request_hash'],$hash)){throw new V3Exception('Idempotency key sudah dipakai untuk isi permintaan lain.',409);}
        $data=json_decode((string)$idem['response_json'],true);
        if(!is_array($data)){throw new V3Exception('Permintaan identik masih diproses. Coba lagi.',409);}
        return ['data'=>$data,'status'=>(int)($idem['status_code']??200),'replayed'=>true];
    }

    private function auditRequired(string $action,string $entity,int $id,?array $before,array $after,int $actorId):void
    {if(!$this->audit->log($action,$entity,$id,$before,$after,$actorId)){throw new V3Exception('Audit wajib tidak dapat disimpan.',503);}}
    private function actorId(array $user):int{$id=(int)($user['id']??0);if($id<1)throw new V3Exception('Akun tidak valid.',403);return $id;}
    private function source(array $user,string $capability):string{return (string)($this->capabilities->v3Capabilities($user)[$capability]['sumber']??'');}
    private function idempotencyKey(array $input):string{$value=$input['idempotency_key']??null;if(!is_string($value)||!preg_match('/^[A-Za-z0-9._:-]{8,100}$/D',$value))throw new V3Exception('Idempotency key wajib 8-100 karakter aman.');return $value;}
    private function positiveInt(array $input,string $key):int{$v=$input[$key]??null;if(!is_scalar($v)||!preg_match('/^[1-9][0-9]*$/D',(string)$v)||(float)$v>2147483647)throw new V3Exception('Nilai '.$key.' tidak valid.');return (int)$v;}
    private function nonNegativeInt(array $input,string $key):int{$v=$input[$key]??null;if(!is_scalar($v)||!preg_match('/^(0|[1-9][0-9]*)$/D',(string)$v)||(float)$v>2147483647)throw new V3Exception('Nilai '.$key.' tidak valid.');return (int)$v;}
    private function requiredText(mixed $value,int $max,string $label):string{if(!is_string($value))throw new V3Exception($label.' wajib berupa teks.');$v=trim($value);if($v===''||mb_strlen($v)>$max)throw new V3Exception($label.' wajib diisi dan maksimum '.$max.' karakter.');return $v;}
    private function optionalText(mixed $value,int $max,string $label):?string{if($value===null||$value==='')return null;if(!is_string($value))throw new V3Exception($label.' wajib berupa teks.');$v=trim($value);if(mb_strlen($v)>$max)throw new V3Exception($label.' maksimum '.$max.' karakter.');return $v===''?null:$v;}
    private function reason(mixed $value):string{$reason=$this->requiredText($value,1000,'Alasan');if(mb_strlen($reason)<5)throw new V3Exception('Alasan minimal 5 karakter.');return $reason;}
    private function dateTime(mixed $value):string{if(!is_string($value)||trim($value)==='')throw new V3Exception('Waktu kejadian wajib diisi.');$raw=str_replace('T',' ',trim($value));$format=strlen($raw)===16?'Y-m-d H:i':'Y-m-d H:i:s';$date=DateTimeImmutable::createFromFormat('!'.$format,$raw);$errors=DateTimeImmutable::getLastErrors();if(!$date||($errors!==false&&($errors['warning_count']>0||$errors['error_count']>0))||$date->format($format)!==$raw)throw new V3Exception('Waktu kejadian tidak valid.');if($date->getTimestamp()>time()+300)throw new V3Exception('Waktu kejadian tidak boleh di masa depan.');return $date->format('Y-m-d H:i:s');}
    private function fingerprintText(mixed $value):?string{if($value===null)return null;return mb_strtolower((string)preg_replace('/\s+/u',' ',trim((string)$value)));}
    private function requestHash(string $operation,array $data,?array $attachment):string{return hash('sha256',json_encode([$operation,$data,$attachment['sha256']??null],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));}
}
