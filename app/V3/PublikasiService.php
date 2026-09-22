<?php

declare(strict_types=1);
namespace App\V3;

use App\Auth\Capabilities;
use App\Audit\AuditLogger;
use App\Notification\RecipientResolver;
use App\Notification\SettingsRepository;

/** Satu pintu pratinjau, konfirmasi, revisi dan pembacaan snapshot wali. */
final class PublikasiService
{
    public function __construct(private PublikasiRepository $repo,private Capabilities $caps,private RecipientResolver $recipients,private KonselingService $konseling,private AuditLogger $audit,private SettingsRepository $settings) {}

    public function options(array $user,string $type,int $id):array
    {
        $source=$this->source($user,$type,$id);
        return ['sumber_type'=>$type,'sumber_id'=>$id,'sumber_version'=>(int)$source['version'],'wali'=>$this->wali((int)$source['santri_id']),
            'publikasi'=>$this->repo->sql->all('SELECT id,wali_id,version,diterbitkan_pada,ditarik_pada,dibaca_pada FROM v3_publikasi WHERE '.$this->column($type).'=? ORDER BY id DESC LIMIT 100',[$id])];
    }

    public function preview(array $user,array $input):array
    {
        $type=$this->type($input['sumber_type']??null);$id=$this->integer($input['sumber_id']??null);
        $probe=$this->source($user,$type,$id);$actor=(int)$user['id'];
        return $this->repo->sql->transaction(function()use($user,$input,$type,$id,$probe,$actor):array{
            $this->repo->sql->lockSubject((int)$probe['santri_id'],(int)$probe['tahun_ajaran_id'],$actor);
            $source=$this->source($user,$type,$id);
            if((int)$source['version']!==$this->integer($input['sumber_version']??null))throw new V3Exception('Sumber berubah. Muat ulang pratinjau.',409);
            $publicationId=isset($input['publikasi_id'])&&$input['publikasi_id']!==''?$this->integer($input['publikasi_id']):null;
            $reason=$publicationId===null?null:$this->reason($input['alasan']??null);
            $version=null;$all=$this->wali((int)$source['santri_id']);$allIds=array_column($all,'id');
            if($publicationId!==null){
                $old=$this->repo->publication($publicationId);
                if((int)($old[$this->column($type)]??0)!==$id)throw new V3Exception('Sumber publikasi tidak sesuai.',422);
                $version=$this->integer($input['version']??null);
                if((int)$old['version']!==$version||$old['ditarik_pada']!==null)throw new V3Exception('Publikasi sudah berubah atau ditarik.',409);
                $selected=[(int)$old['wali_id']];
            }else{
                $raw=$input['wali_ids']??$allIds;if(!is_array($raw))throw new V3Exception('Penerima tidak valid.');
                $selected=array_values(array_unique(array_map(fn($v)=>$this->integer($v),$raw)));sort($selected);
            }
            if($selected===[]||array_diff($selected,$allIds)!==[])throw new V3Exception('Pilih wali aktif yang sah.',422);
            $recipientReason=$publicationId===null&&count($selected)!==count($allIds)?$this->reason($input['alasan_penerima']??null):null;
            $content=['santri_id'=>(int)$source['santri_id'],'ringkasan'=>$this->text($input['ringkasan']??null,5000),'tindak_lanjut'=>$this->text($input['tindak_lanjut']??null,5000)];
            $token=bin2hex(random_bytes(32));
            $this->repo->sql->execute('INSERT INTO v3_publikasi_pratinjau (token_hash,created_by,sumber_type,sumber_id,sumber_version,publikasi_id,publikasi_version,isi_json,penerima_json,alasan,alasan_penerima,expires_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 30 MINUTE))',[hash('sha256',$token),$actor,$type,$id,(int)$source['version'],$publicationId,$version,$this->json($content),$this->json($selected),$reason,$recipientReason]);
            return ['pratinjau_token'=>$token,'konten'=>$this->content($content),'wali_ids'=>$selected,'berlaku_menit'=>30];
        });
    }

    public function publish(array $user,array $input):array
    {
        if(!in_array($input['konfirmasi']??null,[true,1,'1'],true))throw new V3Exception('Konfirmasi eksplisit wajib diberikan.');
        $token=$input['pratinjau_token']??null;if(!is_string($token)||!preg_match('/^[a-f0-9]{64}$/D',$token))throw new V3Exception('Pratinjau tidak valid.');
        $actor=(int)$user['id'];$key=$this->key($input);
        $draft=$this->repo->sql->one('SELECT * FROM v3_publikasi_pratinjau WHERE token_hash=? AND created_by=?',[hash('sha256',$token),$actor])??throw new V3Exception('Pratinjau tidak dapat diakses.',403);
        $probe=$this->source($user,$draft['sumber_type'],(int)$draft['sumber_id']);
        return $this->repo->sql->transaction(function()use($user,$draft,$probe,$actor,$key,$token):array{
            $this->repo->sql->lockSubject((int)$probe['santri_id'],(int)$probe['tahun_ajaran_id'],$actor);
            $source=$this->source($user,$draft['sumber_type'],(int)$draft['sumber_id']);
            $selected=json_decode($draft['penerima_json'],true,512,JSON_THROW_ON_ERROR);
            if(array_diff($selected,array_column($this->wali((int)$source['santri_id']),'id'))!==[])throw new V3Exception('Relasi penerima telah berubah. Buat pratinjau baru.',409);
            $hash=hash('sha256',$token);$idem=$this->repo->sql->claimIdempotency($actor,'v3.publikasi.terbit',$key,$hash);
            if(!hash_equals($idem['request_hash'],$hash))throw new V3Exception('Idempotency key telah dipakai.',409);
            if($idem['response_json']!==null)return ['data'=>json_decode($idem['response_json'],true),'status'=>200,'replayed'=>true];
            if((int)$source['version']!==(int)$draft['sumber_version']||strtotime($draft['expires_at'])<time())throw new V3Exception('Pratinjau kedaluwarsa atau sumber berubah.',409);
            $content=json_decode($draft['isi_json'],true,512,JSON_THROW_ON_ERROR);$ids=[];
            foreach($selected as $wali){
                $old=null;
                if($draft['publikasi_id']!==null){
                    $old=$this->repo->publication((int)$draft['publikasi_id']);
                    if((int)$old['version']!==(int)$draft['publikasi_version']||$old['ditarik_pada']!==null)throw new V3Exception('Versi publikasi telah berubah.',409);
                    $id=(int)$old['id'];
                    $this->repo->sql->execute('UPDATE v3_publikasi SET ringkasan=?,tindak_lanjut=?,sumber_version=?,version=version+1,updated_by=?,dibaca_pada=NULL WHERE id=?',[$content['ringkasan'],$content['tindak_lanjut'],(int)$source['version'],$actor,$id]);
                }else{
                    // Fingerprint bisnis juga melindungi dua pratinjau/klik dengan key berbeda.
                    $fingerprint='v3:publikasi:'.hash('sha256',$this->json([$draft['sumber_type'],(int)$draft['sumber_id'],(int)$source['version'],$wali,$content]));
                    $latest=$this->repo->sql->one('SELECT id,ditarik_pada,event_key,ringkasan,tindak_lanjut FROM v3_publikasi WHERE event_key=? OR event_key LIKE ? ORDER BY id DESC LIMIT 1',[$fingerprint,$fingerprint.':r%']);
                    // Audit Fase 4: snapshot yang sudah dikoreksi tidak lagi mewakili isi pratinjau ini.
                    if($latest!==null&&$latest['ditarik_pada']===null&&$latest['ringkasan']===$content['ringkasan']&&$latest['tindak_lanjut']===$content['tindak_lanjut']){$ids[]=(int)$latest['id'];continue;}
                    // Penarikan atau koreksi menjadikan konfirmasi ini keputusan baru: teks identik
                    // diterbitkan sebagai snapshot baru yang dirantai ke ID snapshot terakhir.
                    $event=$latest===null?$fingerprint:$fingerprint.':r'.(int)$latest['id'];
                    $this->repo->sql->execute('INSERT INTO v3_publikasi ('.$this->column($draft['sumber_type']).',sumber_version,santri_id,wali_id,ringkasan,tindak_lanjut,diterbitkan_pada,alasan_penerima,event_key,created_by,updated_by) VALUES (?,?,?,?,?,?,NOW(),?,?,?,?)',[(int)$draft['sumber_id'],(int)$source['version'],(int)$source['santri_id'],$wali,$content['ringkasan'],$content['tindak_lanjut'],$draft['alasan_penerima'],$event,$actor,$actor]);
                    $id=(int)$this->repo->sql->one('SELECT LAST_INSERT_ID() id')['id'];
                }
                $saved=$this->repo->publication($id);$this->record($old,$saved,$old===null?'Terbit':'Koreksi',$draft['alasan'],$actor);
                $ids[]=$id;
            }
            $payload=['publikasi_ids'=>$ids,'konten'=>$this->content($content)];$this->repo->sql->completeIdempotency((int)$idem['id'],$payload,201);
            return ['data'=>$payload,'status'=>201,'replayed'=>false];
        });
    }

    public function withdraw(array $user,int $id,array $input):array
    {
        $probe=$this->repo->publication($id);[$type,$sourceId]=$this->publicationSource($probe);
        // Penarikan tetap tersedia bila sumber terlanjur rahasia (pemulihan invariant).
        $source=$this->source($user,$type,$sourceId,false);$actor=(int)$user['id'];$reason=$this->reason($input['alasan']??null);$version=$this->integer($input['version']??null);$key=$this->key($input);
        return $this->repo->sql->transaction(function()use($user,$source,$type,$sourceId,$id,$actor,$reason,$version,$key):array{
            $this->repo->sql->lockSubject((int)$source['santri_id'],(int)$source['tahun_ajaran_id'],$actor);$this->source($user,$type,$sourceId,false);
            $hash=hash('sha256',$this->json([$id,$version,$reason]));$idem=$this->repo->sql->claimIdempotency($actor,'v3.publikasi.tarik:'.$id,$key,$hash);
            if(!hash_equals($idem['request_hash'],$hash))throw new V3Exception('Idempotency key telah dipakai.',409);
            if($idem['response_json']!==null)return ['data'=>json_decode($idem['response_json'],true),'status'=>200,'replayed'=>true];
            $old=$this->repo->publication($id);if((int)$old['version']!==$version||$old['ditarik_pada']!==null)throw new V3Exception('Publikasi sudah berubah atau ditarik.',409);
            $this->repo->sql->execute('UPDATE v3_publikasi SET ditarik_pada=NOW(),alasan_penarikan=?,version=version+1,updated_by=? WHERE id=?',[$reason,$actor,$id]);
            $saved=$this->repo->publication($id);$this->record($old,$saved,'Tarik',$reason,$actor);$payload=['id'=>$id,'version'=>(int)$saved['version'],'status'=>'Ditarik'];
            $this->repo->sql->completeIdempotency((int)$idem['id'],$payload,200);return ['data'=>$payload,'status'=>200,'replayed'=>false];
        });
    }

    public function page(array $user,array $filters):array
    {
        $this->parentAccess($user);$page=isset($filters['page'])?$this->integer($filters['page']):1;$page=min(1000000,$page);
        $result=$this->repo->parentPage((int)$user['id'],$page);$result['rows']=array_map(fn($row)=>$this->dto($row),$result['rows']);return $result;
    }
    public function show(array $user,int $id):array
    {
        $this->parentAccess($user);$row=$this->repo->parentDetail((int)$user['id'],$id);
        if(!in_array((int)$user['id'],$this->recipients->waliSantri((int)$row['santri_id']),true))throw new V3Exception('Publikasi tidak dapat diakses.',403);
        return ['publikasi'=>$this->dto($row),'riwayat'=>$this->repo->history($id,false)];
    }
    public function markRead(array $user,int $id,array $input):array
    {
        $version=$this->integer($input['version']??null);
        return $this->repo->sql->transaction(function()use($user,$id,$version):array{
            $this->parentAccess($user);$this->repo->sql->one('SELECT id FROM v3_publikasi WHERE id=? FOR UPDATE',[$id]);$row=$this->repo->parentDetail((int)$user['id'],$id);
            if((int)$row['version']!==$version)throw new V3Exception('Publikasi berubah. Muat ulang.',409);
            $this->repo->sql->execute('UPDATE v3_publikasi SET dibaca_pada=COALESCE(dibaca_pada,NOW()) WHERE id=? AND version=?',[$id,$version]);
            $this->auditRequired('Dibaca',$id,null,['version'=>$version],(int)$user['id']);return $this->show($user,$id);
        });
    }
    public function manage(array $user,int $id):array
    {
        $row=$this->repo->publication($id);[$type,$sourceId]=$this->publicationSource($row);$source=$this->source($user,$type,$sourceId,false);
        if(isset($this->caps->v3Capabilities($user)['v3.koreksi']))$this->auditRequired('Dilihat.admin',$id,null,['version'=>(int)$row['version']],(int)$user['id']);
        return ['publikasi'=>$this->dto($row),'sumber_type'=>$type,'sumber_id'=>$sourceId,'sumber_version'=>(int)$source['version'],'riwayat'=>$this->repo->history($id,true)];
    }

    private function source(array $user,string $type,int $id,bool $checkPrivacy=true):array
    {
        $caps=$this->caps->v3Capabilities($user);$cap=$type==='pelanggaran'?'v3.pelanggaran.kelola':'v3.konseling.kelola';
        if(!isset($caps['v3.koreksi'])&&!isset($caps[$cap]))throw new V3Exception('Tidak memiliki hak publikasi.',403);
        $row=$this->repo->source($type,$id);
        if(!isset($caps['v3.koreksi'])&&!$this->caps->v3AppliesToSantri($user,$cap,(int)$row['santri_id'],(int)$row['tahun_ajaran_id']))throw new V3Exception('Sumber tidak dapat diakses.',403);
        if($row['archived_at']!==null)throw new V3Exception('Sumber tidak dapat diakses.',403);
        // Audit Fase 4: Rahasia hanya diketahui pemilik dan admin (5.5a), termasuk jalur kelola/tarik tanpa cek privasi.
        if(($row['kerahasiaan']??'Internal')!=='Internal'&&!isset($caps['v3.koreksi'])&&(int)($row['pembimbing_id']??0)!==(int)($this->repo->sql->pengurusIdForUser((int)$user['id'])??-1))throw new V3Exception('Sumber tidak dapat diakses.',403);
        if($checkPrivacy&&($row['status']==='Dibatalkan'||($type==='pelanggaran'&&$row['status']==='Draf')))throw new V3Exception('Catatan batal atau draf tidak dapat diterbitkan.',422);
        if($checkPrivacy&&$type==='pelanggaran'&&$this->repo->sql->violationHasSecretCase($id,(int)$row['santri_id']))throw new V3Exception('Pelanggaran ini tidak dapat dipratinjau atau diterbitkan kepada orang tua.',422);
        if($checkPrivacy&&($row['kerahasiaan']??'Internal')!=='Internal')throw new V3Exception('Kasus Rahasia tidak boleh dipratinjau atau diterbitkan. Revisi kerahasiaan terlebih dahulu.',422);
        return $row;
    }
    private function wali(int $santri):array
    {
        $ids=$this->recipients->waliSantri($santri);if($ids===[])return [];
        return array_map(static fn($r)=>['id'=>(int)$r['id'],'nama'=>(string)$r['nama']],$this->repo->sql->all('SELECT DISTINCT w.id,w.nama FROM users u JOIN wali w ON w.id=u.wali_id WHERE u.id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY w.id',$ids));
    }
    private function record(?array $old,array $saved,string $action,?string $reason,int $actor):void
    {
        $id=(int)$saved['id'];$version=(int)$saved['version'];
        $this->repo->sql->execute('INSERT INTO v3_publikasi_riwayat (publikasi_id,version,tindakan,sebelum_json,sesudah_json,alasan,created_by) VALUES (?,?,?,?,?,?,?)',[$id,$version,$action,$old===null?null:$this->json($old),$this->json($saved),$reason,$actor]);
        // Isi snapshot/alasan ada di riwayat privat; audit/log hanya metadata referensi.
        $this->auditRequired($action,$id,$old===null?null:['version'=>(int)$old['version']],['version'=>$version,'riwayat_version'=>$version],$actor);
        $settings=$this->settings->current();$channels=$settings['push_enabled']?['InApp','Push']:['InApp'];
        foreach($this->recipients->waliSantri((int)$saved['santri_id']) as $recipient){
            $user=$this->repo->sql->one('SELECT wali_id FROM users WHERE id=?',[$recipient]);if((int)$user['wali_id']!==(int)$saved['wali_id'])continue;
            foreach($channels as $channel){
                $event='v3:publikasi:'.$id.':v'.$version;$data=$this->json(['tipe'=>'v3_publikasi','publikasi_id'=>$id]);
                $this->repo->sql->execute("INSERT INTO notifikasi_outbox (event_key,event_type,kanal,penerima_user_id,judul,isi,data_json,status,dikirim_pada,tersedia_pada) VALUES (?,?,?,?,?,?,?, ?,".($channel==='InApp'?'NOW(),NULL':'NULL,NOW()').')',[$event,'v3_publikasi_'.strtolower($action),$channel,$recipient,'Pembaruan pembinaan','Ada pembaruan pembinaan. Masuk untuk melihat informasi.',$data,$channel==='InApp'?'Sent':'Queued']);
                $outbox=(int)$this->repo->sql->one('SELECT LAST_INSERT_ID() id')['id'];
                $this->repo->sql->execute('INSERT INTO v3_publikasi_outbox (outbox_id,publikasi_id,publikasi_version) VALUES (?,?,?)',[$outbox,$id,$version]);
            }
        }
    }
    private function auditRequired(string $action,int $id,?array $before,array $after,int $actor):void {if(!$this->audit->log('v3.publikasi.'.strtolower($action),'v3_publikasi',$id,$before,$after,$actor))throw new V3Exception('Audit wajib tidak dapat disimpan.',503);}
    private function parentAccess(array $user):void {if(!isset($this->caps->v3Capabilities($user)['v3.publikasi.baca']))throw new V3Exception('Tidak memiliki akses publikasi.',403);}
    private function dto(array $row):array {if($row['ditarik_pada']!==null){$row['ringkasan']='Informasi ini telah ditarik.';$row['tindak_lanjut']=null;}return $this->konseling->parentSerializer($row)+['version'=>(int)$row['version'],'status'=>$row['ditarik_pada']===null?'Terbit':'Ditarik'];}
    private function content(array $row):array {return array_intersect_key($this->konseling->parentSerializer($row),array_flip(['santri_id','ringkasan','tindak_lanjut']));}
    private function publicationSource(array $row):array {foreach(['kasus','sesi','pelanggaran'] as $type)if($row[$this->column($type)]!==null)return [$type,(int)$row[$this->column($type)]];throw new V3Exception('Sumber tidak valid.');}
    private function type(mixed $v):string {if(!is_string($v)||!in_array($v,['kasus','sesi','pelanggaran'],true))throw new V3Exception('Sumber tidak valid.');return $v;}
    private function column(string $type):string {return $this->type($type).'_id';}
    private function integer(mixed $v):int {if(!is_scalar($v)||!preg_match('/^[1-9][0-9]*$/D',(string)$v)||(float)$v>2147483647)throw new V3Exception('Angka tidak valid.');return (int)$v;}
    private function text(mixed $v,int $max):string {if(!is_string($v)||trim($v)===''||mb_strlen($v)>$max)throw new V3Exception('Teks wajib diisi, maksimum '.$max.' karakter.');return trim($v);}
    private function reason(mixed $v):string {$v=$this->text($v,1000);if(mb_strlen($v)<5)throw new V3Exception('Alasan minimal 5 karakter.');return $v;}
    private function key(array $input):string {$key=$input['idempotency_key']??null;if(!is_string($key)||!preg_match('/^[A-Za-z0-9._:-]{8,100}$/D',$key))throw new V3Exception('Idempotency key tidak valid.');return $key;}
    private function json(array $v):string {return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
}
