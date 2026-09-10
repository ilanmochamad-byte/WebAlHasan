<?php

declare(strict_types=1);

namespace App\V3;

use mysqli;
use mysqli_stmt;
use Throwable;

/** Seluruh SQL kasus, sesi, tautan, dan catatan murobi Fase 3. */
final class KonselingRepository
{
    public function __construct(private mysqli $db)
    {
    }

    public function transaction(callable $work): mixed
    {
        if (!$this->db->begin_transaction()) {
            throw new V3Exception('Transaksi tidak tersedia.', 503);
        }
        try {
            $result = $work();
            if (!$this->db->commit()) {
                throw new V3Exception('Transaksi tidak dapat disimpan.', 503);
            }
            return $result;
        } catch (Throwable $exception) {
            $this->db->rollback();
            throw $exception;
        }
    }

    public function lockSubject(int $santriId, int $tahunId, int $actorId): void
    {
        $this->execute(
            'INSERT INTO v3_poin_agregat
                 (santri_id,tahun_ajaran_id,total_poin,direkonsiliasi_pada,created_by,updated_by)
             VALUES (?,?,0,NOW(),?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)',
            [$santriId,$tahunId,$actorId,$actorId]
        );
        if ($this->one('SELECT id FROM v3_poin_agregat WHERE santri_id=? AND tahun_ajaran_id=? FOR UPDATE', [$santriId,$tahunId]) === null) {
            throw new V3Exception('Kunci subjek tidak tersedia.', 503);
        }
    }

    public function claimIdempotency(int $userId, string $operation, string $key, string $hash): array
    {
        $this->execute(
            'INSERT INTO v3_idempotency (user_id,operation,idempotency_key,request_hash,created_by,updated_by)
             VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)',
            [$userId,$operation,$key,$hash,$userId,$userId]
        );
        return $this->one(
            'SELECT id,request_hash,response_json,status_code FROM v3_idempotency
              WHERE user_id=? AND operation=? AND idempotency_key=? FOR UPDATE',
            [$userId,$operation,$key]
        ) ?? throw new V3Exception('Idempotensi tidak tersedia.', 503);
    }

    public function completeIdempotency(int $id, array $response, int $status): void
    {
        $json=json_encode($response,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if($json===false)throw new V3Exception('Respons tidak dapat disimpan.',503);
        $this->execute('UPDATE v3_idempotency SET response_json=?,status_code=?,version=version+1,updated_at=NOW() WHERE id=?',[$json,$status,$id]);
    }

    public function pembimbingAssignment(int $userId,int $santriId,int $tahunId,string $date):?array
    {
        return $this->one(
            "SELECT pa.id,pa.pengurus_id,pa.target_type,pa.kamar_id,pa.kelas_id
               FROM users u JOIN user_roles ur ON ur.user_id=u.id
               JOIN roles r ON r.id=ur.role_id AND r.slug='pengurus'
               JOIN pengurus p ON p.id=u.pengurus_id AND p.is_active=1 AND p.archived_at IS NULL
               JOIN pembimbing_assignments pa ON pa.pengurus_id=p.id
               JOIN tahun_ajaran ta ON ta.id=pa.tahun_ajaran_id AND ta.status='Aktif' AND ta.archived_at IS NULL
              WHERE u.id=? AND u.is_active=1 AND pa.tahun_ajaran_id=? AND pa.is_active=1 AND pa.archived_at IS NULL
                AND pa.tanggal_mulai<=? AND (pa.tanggal_selesai IS NULL OR pa.tanggal_selesai>=?)
                AND ((pa.target_type='Kelas' AND EXISTS (SELECT 1 FROM plotting_kelas pk WHERE pk.id_santri=? AND pk.id_tahun=? AND pk.id_kelas=pa.kelas_id AND pk.status='Aktif'))
                  OR (pa.target_type='Kamar' AND EXISTS (SELECT 1 FROM plotting_kamar pm WHERE pm.id_santri=? AND pm.id_tahun=? AND pm.id_kamar=pa.kamar_id)))
              ORDER BY pa.id LIMIT 1",
            [$userId,$tahunId,$date,$date,$santriId,$tahunId,$santriId,$tahunId]
        );
    }

    public function murobiAssignment(int $userId,int $santriId,int $tahunId):?array
    {
        return $this->one(
            "SELECT ma.id,ma.guru_id FROM users u JOIN user_roles ur ON ur.user_id=u.id
               JOIN roles r ON r.id=ur.role_id AND r.slug='guru'
               JOIN guru g ON g.id=u.guru_id AND g.is_active=1 AND g.archived_at IS NULL
               JOIN murobi_assignments ma ON ma.guru_id=g.id
               JOIN tahun_ajaran ta ON ta.id=ma.tahun_ajaran_id AND ta.status='Aktif' AND ta.archived_at IS NULL
              WHERE u.id=? AND u.is_active=1 AND ma.tahun_ajaran_id=? AND ma.is_active=1 AND ma.archived_at IS NULL
                AND ma.tanggal_mulai<=CURDATE() AND (ma.tanggal_selesai IS NULL OR ma.tanggal_selesai>=CURDATE())
                AND ((ma.target_type='Kelas' AND EXISTS (SELECT 1 FROM plotting_kelas pk WHERE pk.id_santri=? AND pk.id_tahun=? AND pk.id_kelas=ma.kelas_id AND pk.status='Aktif'))
                  OR (ma.target_type='Kamar' AND EXISTS (SELECT 1 FROM plotting_kamar pm WHERE pm.id_santri=? AND pm.id_tahun=? AND pm.id_kamar=ma.kamar_id)))
              ORDER BY ma.id LIMIT 1",
            [$userId,$tahunId,$santriId,$tahunId,$santriId,$tahunId]
        );
    }

    public function studentOptions(int $userId):array
    {
        return $this->all(
            "SELECT DISTINCT s.id AS santri_id,ta.id AS tahun_ajaran_id,s.nama_santri,ta.tahun,ta.semester
               FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id AND r.slug='pengurus'
               JOIN pengurus p ON p.id=u.pengurus_id AND p.is_active=1 AND p.archived_at IS NULL
               JOIN pembimbing_assignments pa ON pa.pengurus_id=p.id
               JOIN tahun_ajaran ta ON ta.id=pa.tahun_ajaran_id AND ta.status='Aktif' AND ta.archived_at IS NULL
               JOIN santri s ON s.is_active=1 AND s.archived_at IS NULL
              WHERE u.id=? AND u.is_active=1 AND pa.is_active=1 AND pa.archived_at IS NULL
                AND pa.tanggal_mulai<=CURDATE() AND (pa.tanggal_selesai IS NULL OR pa.tanggal_selesai>=CURDATE())
                AND ((pa.target_type='Kelas' AND EXISTS (SELECT 1 FROM plotting_kelas pk WHERE pk.id_santri=s.id AND pk.id_tahun=ta.id AND pk.id_kelas=pa.kelas_id AND pk.status='Aktif'))
                  OR (pa.target_type='Kamar' AND EXISTS (SELECT 1 FROM plotting_kamar pm WHERE pm.id_santri=s.id AND pm.id_tahun=ta.id AND pm.id_kamar=pa.kamar_id)))
              ORDER BY s.nama_santri,s.id",[$userId]
        );
    }

    public function currentViolationsForStudent(int $santriId,int $tahunId):array
    {
        return $this->all(
            "SELECT p.id,p.waktu_kejadian,p.kategori_snapshot,p.tingkat_snapshot,p.poin_snapshot,p.status
               FROM v3_pelanggaran p
              WHERE p.santri_id=? AND p.tahun_ajaran_id=? AND p.archived_at IS NULL AND p.status<>'Dibatalkan'
                AND NOT EXISTS (SELECT 1 FROM v3_pelanggaran nx WHERE nx.revisi_dari_id=p.id)
              ORDER BY p.waktu_kejadian DESC,p.id DESC",[$santriId,$tahunId]
        );
    }

    public function pendingRecommendations(int $userId,string $mode):array
    {
        [$scope,$params]=$this->scopeSql($mode,$userId,'r');
        return $this->all(
            'SELECT r.id,r.santri_id,r.tahun_ajaran_id,r.label_snapshot,r.rekomendasi_snapshot,r.total_poin_snapshot,r.created_at,s.nama_santri
               FROM v3_rekomendasi r JOIN santri s ON s.id=r.santri_id
              WHERE r.archived_at IS NULL AND r.tidak_berlaku_pada IS NULL AND r.ditindaklanjuti_kasus_id IS NULL AND r.status=\'Baru\'
                AND ('.$scope.') ORDER BY r.id DESC', $params
        );
    }

    public function insertCase(array $row):int
    {
        return $this->insert(
            'INSERT INTO v3_konseling_kasus
                (santri_id,tahun_ajaran_id,pembimbing_id,pembimbing_assignment_id,tujuan,kerahasiaan,status,
                 dibuka_pada,cakupan_snapshot,idempotency_key,created_by,updated_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
            [$row['santri_id'],$row['tahun_ajaran_id'],$row['pembimbing_id'],$row['pembimbing_assignment_id'],$row['tujuan'],$row['kerahasiaan'],'Dibuka',$row['dibuka_pada'],$row['cakupan_snapshot'],$row['idempotency_key'],$row['created_by'],$row['created_by']]
        );
    }

    public function case(int $id,bool $lock=false):?array
    {
        return $this->one(
            'SELECT k.*,s.nama_santri,ta.tahun,ta.semester
               FROM v3_konseling_kasus k JOIN santri s ON s.id=k.santri_id JOIN tahun_ajaran ta ON ta.id=k.tahun_ajaran_id
              WHERE k.id=?'.($lock?' FOR UPDATE':''),[$id]
        );
    }

    public function visibleCase(int $id,int $userId,string $mode):?array
    {
        [$scope,$params]=$this->scopeSql($mode,$userId,'k');
        return $this->one(
            'SELECT k.*,s.nama_santri,ta.tahun,ta.semester
               FROM v3_konseling_kasus k JOIN santri s ON s.id=k.santri_id JOIN tahun_ajaran ta ON ta.id=k.tahun_ajaran_id
              WHERE k.id=? AND k.archived_at IS NULL AND ('.$scope.')',[$id,...$params]
        );
    }

    public function page(int $userId,string $mode,array $filters):array
    {
        foreach(['page','santri_id','tahun_ajaran_id','status'] as $key)if(isset($filters[$key])&&!is_scalar($filters[$key]))throw new V3Exception('Filter tidak valid.');
        $page=max(1,min(1000000,(int)($filters['page']??1)));$size=25;$status=(string)($filters['status']??'');
        if(!in_array($status,['','Dibuka','Dalam Pendampingan','Selesai','Dibatalkan'],true))throw new V3Exception('Status filter tidak valid.');
        foreach(['santri_id','tahun_ajaran_id'] as $key)if(($filters[$key]??'')!==''&&!preg_match('/^[1-9][0-9]*$/D',(string)$filters[$key]))throw new V3Exception('Filter angka tidak valid.');
        [$scope,$scopeParams]=$this->scopeSql($mode,$userId,'k');$where=['k.archived_at IS NULL','('.$scope.')'];$params=$scopeParams;
        if($status!==''){$where[]='k.status=?';$params[]=$status;}
        foreach(['santri_id','tahun_ajaran_id'] as $key)if(($filters[$key]??'')!==''){$where[]='k.'.$key.'=?';$params[]=(int)$filters[$key];}
        $from=' FROM v3_konseling_kasus k JOIN santri s ON s.id=k.santri_id JOIN tahun_ajaran ta ON ta.id=k.tahun_ajaran_id WHERE '.implode(' AND ',$where);
        $total=(int)($this->one('SELECT COUNT(*) n'.$from,$params)['n']??0);
        $rows=$this->all('SELECT k.id,k.santri_id,k.tahun_ajaran_id,k.kerahasiaan,k.status,k.dibuka_pada,k.ditutup_pada,k.version,k.updated_at,s.nama_santri,ta.tahun,ta.semester,(SELECT COUNT(*) FROM v3_konseling_sesi ss WHERE ss.kasus_id=k.id AND ss.archived_at IS NULL AND NOT EXISTS (SELECT 1 FROM v3_konseling_sesi nx WHERE nx.revisi_dari_id=ss.id)) AS jumlah_sesi'.$from.' ORDER BY k.id DESC LIMIT ? OFFSET ?',[...$params,$size,($page-1)*$size]);
        return ['rows'=>$rows,'total'=>$total,'page'=>$page,'per_page'=>$size];
    }

    public function violationForLink(int $id,int $santriId,int $tahunId,bool $lock=false):?array
    {
        return $this->one(
            "SELECT p.id,p.santri_id,p.tahun_ajaran_id,p.waktu_kejadian,p.kategori_snapshot,p.tingkat_snapshot,p.poin_snapshot,p.status,p.version
               FROM v3_pelanggaran p WHERE p.id=? AND p.santri_id=? AND p.tahun_ajaran_id=? AND p.archived_at IS NULL
                AND p.status<>'Dibatalkan' AND NOT EXISTS (SELECT 1 FROM v3_pelanggaran nx WHERE nx.revisi_dari_id=p.id)".($lock?' FOR UPDATE':''),[$id,$santriId,$tahunId]
        );
    }

    public function insertLink(int $violationId,int $caseId,?int $sessionId,?string $reason,int $actorId):int
    {
        return $this->insert(
            'INSERT INTO v3_konseling_tautan (pelanggaran_id,kasus_id,sesi_id,is_active,alasan,created_by,updated_by) VALUES (?,?,?,1,?,?,?)',
            [$violationId,$caseId,$sessionId,$reason,$actorId,$actorId]
        );
    }

    public function links(int $caseId):array
    {
        return $this->all(
            'SELECT t.id,t.pelanggaran_id,t.sesi_id,t.alasan,t.created_at,p.waktu_kejadian,p.kategori_snapshot,p.tingkat_snapshot,p.poin_snapshot,p.status,
                    (SELECT nx.id FROM v3_pelanggaran nx WHERE nx.revisi_dari_id=p.id LIMIT 1) AS digantikan_oleh_id
               FROM v3_konseling_tautan t JOIN v3_pelanggaran p ON p.id=t.pelanggaran_id
              WHERE t.kasus_id=? AND t.is_active=1 AND t.archived_at IS NULL ORDER BY t.id',[$caseId]
        );
    }

    /** Revisi pelanggaran tidak ditautkan ulang bila catatan leluhurnya sudah tertaut pada tingkat kasus yang sama. */
    public function violationChainLinkedToCase(int $violationId,int $caseId):bool
    {
        $ids=[];$current=$violationId;
        while($current!==null&&count($ids)<100&&!in_array($current,$ids,true)){$ids[]=$current;$row=$this->one('SELECT revisi_dari_id FROM v3_pelanggaran WHERE id=?',[$current]);$current=($row['revisi_dari_id']??null)===null?null:(int)$row['revisi_dari_id'];}
        return $this->one('SELECT 1 AS ada FROM v3_konseling_tautan WHERE kasus_id=? AND sesi_id IS NULL AND is_active=1 AND archived_at IS NULL AND pelanggaran_id IN ('.implode(',',array_fill(0,count($ids),'?')).') LIMIT 1',[$caseId,...$ids])!==null;
    }

    public function linkRecommendation(int $id,int $caseId,int $santriId,int $tahunId,int $actorId):bool
    {
        return $this->execute(
            "UPDATE v3_rekomendasi SET ditindaklanjuti_kasus_id=?,ditindaklanjuti_pada=NOW(),status='Ditinjau',updated_by=?,version=version+1
              WHERE id=? AND santri_id=? AND tahun_ajaran_id=? AND archived_at IS NULL
                AND tidak_berlaku_pada IS NULL AND ditindaklanjuti_kasus_id IS NULL AND status='Baru'",
            [$caseId,$actorId,$id,$santriId,$tahunId]
        )===1;
    }

    public function recommendationsForCase(int $caseId):array
    {
        return $this->all('SELECT id,label_snapshot,rekomendasi_snapshot,total_poin_snapshot,status,ditindaklanjuti_pada FROM v3_rekomendasi WHERE ditindaklanjuti_kasus_id=? AND archived_at IS NULL ORDER BY id',[$caseId]);
    }

    /** Kasus batal melepas rekomendasinya agar kembali ke antrean tindak lanjut manual; ID lepasan dicatat di audit. */
    public function releaseRecommendations(int $caseId,int $actorId):array
    {
        $ids=array_map('intval',array_column($this->all('SELECT id FROM v3_rekomendasi WHERE ditindaklanjuti_kasus_id=? AND archived_at IS NULL ORDER BY id FOR UPDATE',[$caseId]),'id'));
        if($ids!==[])$this->execute("UPDATE v3_rekomendasi SET ditindaklanjuti_kasus_id=NULL,ditindaklanjuti_pada=NULL,status='Baru',updated_by=?,version=version+1 WHERE ditindaklanjuti_kasus_id=? AND archived_at IS NULL",[$actorId,$caseId]);
        return $ids;
    }

    public function updateCaseDetails(int $id,int $version,string $purpose,string $privacy,string $reason,int $actorId):bool
    {
        return $this->execute('UPDATE v3_konseling_kasus SET tujuan=?,kerahasiaan=?,alasan_revisi_terakhir=?,updated_by=?,version=version+1 WHERE id=? AND version=?',[$purpose,$privacy,$reason,$actorId,$id,$version])===1;
    }

    public function updateCaseStatus(int $id,int $version,string $status,?string $closingSummary,?string $cancelReason,int $actorId):bool
    {
        return $this->execute(
            'UPDATE v3_konseling_kasus SET status=?,ditutup_pada='.(in_array($status,['Selesai','Dibatalkan'],true)?'NOW()':'NULL').',ringkasan_penutupan=?,alasan_pembatalan=?,updated_by=?,version=version+1 WHERE id=? AND version=?',
            [$status,$closingSummary,$cancelReason,$actorId,$id,$version]
        )===1;
    }

    public function setCaseInProgress(int $id,int $actorId):void
    {
        $this->execute("UPDATE v3_konseling_kasus SET status='Dalam Pendampingan',updated_by=?,version=version+1 WHERE id=? AND status='Dibuka'",[$actorId,$id]);
    }

    public function completedSessionCount(int $caseId):int
    {
        return (int)($this->one("SELECT COUNT(*) n FROM v3_konseling_sesi s WHERE s.kasus_id=? AND s.status='Selesai' AND s.archived_at IS NULL AND NOT EXISTS (SELECT 1 FROM v3_konseling_sesi nx WHERE nx.revisi_dari_id=s.id)",[$caseId])['n']??0);
    }

    public function insertSession(array $row):int
    {
        return $this->insert(
            'INSERT INTO v3_konseling_sesi
                (kasus_id,pembimbing_id,jadwal,realisasi,status,ringkasan_internal,hasil,tindak_lanjut,jadwal_berikut,
                 revisi_dari_id,alasan_revisi,alasan_penjadwalan_ulang,alasan_pembatalan,idempotency_key,created_by,updated_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$row['kasus_id'],$row['pembimbing_id'],$row['jadwal'],$row['realisasi'],$row['status'],$row['ringkasan_internal'],$row['hasil'],$row['tindak_lanjut'],$row['jadwal_berikut'],$row['revisi_dari_id'],$row['alasan_revisi'],$row['alasan_penjadwalan_ulang'],$row['alasan_pembatalan'],$row['idempotency_key'],$row['created_by'],$row['created_by']]
        );
    }

    public function session(int $id,bool $lock=false):?array
    {
        return $this->one('SELECT s.*,(SELECT nx.id FROM v3_konseling_sesi nx WHERE nx.revisi_dari_id=s.id LIMIT 1) AS digantikan_oleh_id FROM v3_konseling_sesi s WHERE s.id=?'.($lock?' FOR UPDATE':''),[$id]);
    }

    public function sessions(int $caseId,bool $includeHistory=false):array
    {
        return $this->all(
            'SELECT s.*,(SELECT nx.id FROM v3_konseling_sesi nx WHERE nx.revisi_dari_id=s.id LIMIT 1) AS digantikan_oleh_id
               FROM v3_konseling_sesi s WHERE s.kasus_id=? AND s.archived_at IS NULL'.($includeHistory?'':' AND NOT EXISTS (SELECT 1 FROM v3_konseling_sesi nx WHERE nx.revisi_dari_id=s.id)').' ORDER BY s.jadwal,s.id',[$caseId]
        );
    }

    public function bumpSessionForRevision(int $id,int $version,int $actorId):bool
    {
        return $this->execute('UPDATE v3_konseling_sesi SET version=version+1,updated_by=? WHERE id=? AND version=? AND NOT EXISTS (SELECT 1 FROM (SELECT revisi_dari_id FROM v3_konseling_sesi WHERE revisi_dari_id IS NOT NULL) nx WHERE nx.revisi_dari_id=?)',[$actorId,$id,$version,$id])===1;
    }

    public function updateSessionStatus(int $id,int $version,array $data,int $actorId):bool
    {
        return $this->execute(
            'UPDATE v3_konseling_sesi SET status=?,realisasi=?,ringkasan_internal=?,hasil=?,tindak_lanjut=?,jadwal_berikut=?,alasan_pembatalan=?,updated_by=?,version=version+1 WHERE id=? AND version=?',
            [$data['status'],$data['realisasi'],$data['ringkasan_internal'],$data['hasil'],$data['tindak_lanjut'],$data['jadwal_berikut'],$data['alasan_pembatalan'],$actorId,$id,$version]
        )===1;
    }

    public function insertMurobiNote(string $type,int $sourceId,int $sourceVersion,int $guruId,int $assignmentId,?string $note,string $event,int $actorId):int
    {
        if(!in_array($type,['kasus','sesi'],true))throw new V3Exception('Sumber catatan tidak valid.');
        $column=$type==='kasus'?'kasus_id':'sesi_id';
        return $this->insert(
            'INSERT INTO v3_murobi_catatan ('.$column.',sumber_version,guru_id,murobi_assignment_id,dilihat_pada,diketahui_pada,catatan,event_key,created_by,updated_by) VALUES (?,?,?, ?,NOW(),NOW(),?,?,?,?)',
            [$sourceId,$sourceVersion,$guruId,$assignmentId,$note,$event,$actorId,$actorId]
        );
    }

    public function notes(int $caseId):array
    {
        return $this->all('SELECT id,kasus_id,sesi_id,dilihat_pada,diketahui_pada,catatan,sumber_version,created_at FROM v3_murobi_catatan WHERE archived_at IS NULL AND (kasus_id=? OR sesi_id IN (SELECT id FROM v3_konseling_sesi WHERE kasus_id=?)) ORDER BY id',[$caseId,$caseId]);
    }

    public function relatedMurobiUsers(int $santriId,int $tahunId):array
    {
        return array_map('intval',array_column($this->all(
            "SELECT DISTINCT u.id FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id AND r.slug='guru'
               JOIN guru g ON g.id=u.guru_id AND g.is_active=1 AND g.archived_at IS NULL JOIN murobi_assignments ma ON ma.guru_id=g.id
               JOIN tahun_ajaran ta ON ta.id=ma.tahun_ajaran_id AND ta.status='Aktif' AND ta.archived_at IS NULL
              WHERE u.is_active=1 AND ma.tahun_ajaran_id=? AND ma.is_active=1 AND ma.archived_at IS NULL
                AND ma.tanggal_mulai<=CURDATE() AND (ma.tanggal_selesai IS NULL OR ma.tanggal_selesai>=CURDATE())
                AND ((ma.target_type='Kelas' AND EXISTS (SELECT 1 FROM plotting_kelas pk WHERE pk.id_santri=? AND pk.id_tahun=? AND pk.id_kelas=ma.kelas_id AND pk.status='Aktif'))
                  OR (ma.target_type='Kamar' AND EXISTS (SELECT 1 FROM plotting_kamar pm WHERE pm.id_santri=? AND pm.id_tahun=? AND pm.id_kamar=ma.kamar_id)))",
            [$tahunId,$santriId,$tahunId,$santriId,$tahunId]
        ),'id'));
    }

    public function enqueueGeneric(string $eventKey,string $eventType,int $userId):void
    {
        $title='Pembaruan pendampingan';$body='Ada pembaruan pendampingan. Masuk untuk melihat sesuai kewenangan.';$data='{"type":"v3_konseling"}';
        $this->insertOutbox($eventKey,$eventType,'InApp',$userId,$title,$body,$data,'Sent');
        if($this->one('SELECT 1 AS ada FROM pengaturan_notifikasi pn WHERE pn.singleton=1 AND pn.push_enabled=1 AND EXISTS (SELECT 1 FROM perangkat_push pp WHERE pp.user_id=? AND pp.dicabut_pada IS NULL AND pp.push_aktif=1) LIMIT 1',[$userId])!==null){
            $this->insertOutbox($eventKey,$eventType,'Push',$userId,$title,$body,$data,'Queued');
        }
    }

    private function insertOutbox(string $eventKey,string $eventType,string $channel,int $userId,string $title,string $body,string $data,string $status):void
    {
        $this->execute('INSERT INTO notifikasi_outbox (event_key,event_type,kanal,penerima_user_id,pengajuan_id,judul,isi,data_json,status,percobaan,dikirim_pada,tersedia_pada,created_at,updated_at) VALUES (?,?,?,?,NULL,?,?,?,?,0,'.($channel==='InApp'?'NOW()':'NULL').','.($channel==='Push'?'NOW()':'NULL').',NOW(),NOW()) ON DUPLICATE KEY UPDATE id=id',[$eventKey,$eventType,$channel,$userId,$title,$body,$data,$status]);
    }

    private function scopeSql(string $mode,int $userId,string $alias):array
    {
        if($mode==='admin')return ['1=1',[]];
        $assignment=$mode==='pembimbing'?'pembimbing_assignments':'murobi_assignments';$master=$mode==='pembimbing'?'pengurus':'guru';$userColumn=$mode==='pembimbing'?'pengurus_id':'guru_id';$role=$mode==='pembimbing'?'pengurus':'guru';$prefix=$mode==='pembimbing'?'pa':'ma';
        $sql="EXISTS (SELECT 1 FROM users ux JOIN user_roles urx ON urx.user_id=ux.id JOIN roles rx ON rx.id=urx.role_id AND rx.slug='{$role}' JOIN {$master} mx ON mx.id=ux.{$userColumn} AND mx.is_active=1 AND mx.archived_at IS NULL JOIN {$assignment} {$prefix} ON {$prefix}.{$userColumn}=mx.id JOIN tahun_ajaran tx ON tx.id={$prefix}.tahun_ajaran_id AND tx.status='Aktif' AND tx.archived_at IS NULL WHERE ux.id=? AND ux.is_active=1 AND {$prefix}.tahun_ajaran_id={$alias}.tahun_ajaran_id AND {$prefix}.is_active=1 AND {$prefix}.archived_at IS NULL AND {$prefix}.tanggal_mulai<=CURDATE() AND ({$prefix}.tanggal_selesai IS NULL OR {$prefix}.tanggal_selesai>=CURDATE()) AND (({$prefix}.target_type='Kelas' AND EXISTS (SELECT 1 FROM plotting_kelas pk WHERE pk.id_santri={$alias}.santri_id AND pk.id_tahun={$alias}.tahun_ajaran_id AND pk.id_kelas={$prefix}.kelas_id AND pk.status='Aktif')) OR ({$prefix}.target_type='Kamar' AND EXISTS (SELECT 1 FROM plotting_kamar pm WHERE pm.id_santri={$alias}.santri_id AND pm.id_tahun={$alias}.tahun_ajaran_id AND pm.id_kamar={$prefix}.kamar_id))))";
        return [$sql,[$userId]];
    }

    public function all(string $sql,array $params=[]):array
    {
        $statement=$this->statement($sql,$params);try{$result=$statement->get_result();if($result===false)$this->fail($statement->errno);return $result->fetch_all(MYSQLI_ASSOC);}finally{$statement->close();}
    }
    public function one(string $sql,array $params=[]):?array{return $this->all($sql,$params)[0]??null;}
    public function execute(string $sql,array $params=[]):int{$statement=$this->statement($sql,$params);$affected=$statement->affected_rows;$statement->close();return $affected;}
    private function insert(string $sql,array $params):int{$statement=$this->statement($sql,$params);$id=(int)$statement->insert_id;$statement->close();if($id<1)throw new V3Exception('Data tidak dapat disimpan.',503);return $id;}
    private function statement(string $sql,array $params):mysqli_stmt
    {
        try{$statement=$this->db->prepare($sql);if($statement===false)$this->fail($this->db->errno);if($params!==[]){$types=implode('',array_map(static fn($value):string=>is_int($value)?'i':'s',$params));if(!$statement->bind_param($types,...$params))$this->fail($statement->errno);}if(!$statement->execute())$this->fail($statement->errno);return $statement;}catch(\mysqli_sql_exception $exception){$this->fail((int)$exception->getCode());}
    }
    private function fail(int $errno):never
    {
        if($errno===1062)throw new V3Exception('Permintaan menduplikasi catatan yang sudah ada.',409);
        if(in_array($errno,[1205,1213],true))throw new V3Exception('Data sedang diperbarui. Muat ulang lalu coba lagi.',409);
        throw new V3Exception('Data konseling tidak dapat diproses. Silakan coba lagi.',503);
    }
}
