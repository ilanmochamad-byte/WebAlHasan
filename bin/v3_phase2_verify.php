<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/app/bootstrap.php';
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$pre=in_array('--pre',$argv,true);$fail=0;$check=static function(bool $ok,string $label)use(&$fail){echo ($ok?'[lulus] ':'[gagal] ').$label.PHP_EOL;if(!$ok)$fail++;};
try{
    $repo=new App\V3\PelanggaranRepository(app_db());$count=static fn(string $sql,array $params=[]):int=>(int)array_values($repo->one($sql,$params)??[0])[0];
    $check($count("SELECT COUNT(*) FROM schema_migrations WHERE migration='013_v3_fase1.sql'")===1,'Fondasi V3 Fase 1 tercatat');
    foreach(['v3_pelanggaran','v3_poin_ledger','v3_murobi_catatan','v3_idempotency','audit_logs','notifikasi_outbox'] as $table){$check($count('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table])===1,'Prasyarat '.$table);}
    if(!$pre){
        $check($count("SELECT COUNT(*) FROM schema_migrations WHERE migration='014_v3_fase2_pelanggaran.sql'")===1,'Migrasi 014 tercatat');
        foreach(['v3_poin_agregat','v3_rekomendasi'] as $table){$check($count('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table])===1,'Tabel '.$table);}
        $check($count("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_pelanggaran' AND INDEX_NAME='pelanggaran_satu_revisi'")>0,'Indeks satu revisi terpasang');
        $check($count('SELECT COUNT(*) FROM v3_pelanggaran c JOIN v3_pelanggaran p ON p.id=c.revisi_dari_id WHERE c.santri_id<>p.santri_id OR c.tahun_ajaran_id<>p.tahun_ajaran_id')===0,'Revisi tidak berpindah subjek atau tahun');
        $check($count('SELECT COUNT(*) FROM (SELECT revisi_dari_id FROM v3_pelanggaran WHERE revisi_dari_id IS NOT NULL GROUP BY revisi_dari_id HAVING COUNT(*)>1) x')===0,'Tidak ada sumber dengan dua revisi');
        $check($count('SELECT COUNT(*) FROM v3_poin_ledger l JOIN v3_pelanggaran p ON p.id=l.pelanggaran_id WHERE l.santri_id<>p.santri_id OR l.tahun_ajaran_id<>p.tahun_ajaran_id')===0,'Ledger cocok dengan subjek pelanggaran');
        $check($count('SELECT COUNT(*) FROM v3_poin_ledger r JOIN v3_poin_ledger o ON o.id=r.pembalik_dari_id WHERE r.perubahan_poin<>-o.perubahan_poin OR r.santri_id<>o.santri_id OR r.tahun_ajaran_id<>o.tahun_ajaran_id')===0,'Setiap ledger pembalik tepat menetralkan sumbernya');
        $check($count("SELECT COUNT(*) FROM v3_pelanggaran p WHERE p.status='Dibatalkan' AND NOT EXISTS (SELECT 1 FROM v3_poin_ledger r JOIN v3_poin_ledger o ON o.id=r.pembalik_dari_id WHERE r.pelanggaran_id=p.id)")===0,'Pelanggaran batal mempunyai pembalik');
        $check($count('SELECT COUNT(*) FROM (SELECT l.santri_id,l.tahun_ajaran_id,COALESCE(SUM(l.perubahan_poin),0) total FROM v3_poin_ledger l WHERE l.archived_at IS NULL GROUP BY l.santri_id,l.tahun_ajaran_id) x LEFT JOIN v3_poin_agregat a ON a.santri_id=x.santri_id AND a.tahun_ajaran_id=x.tahun_ajaran_id WHERE a.id IS NULL OR a.total_poin<>x.total')===0,'Agregat dapat direkonsiliasi dari seluruh ledger');
        $check($count('SELECT COUNT(*) FROM v3_poin_agregat a LEFT JOIN (SELECT santri_id,tahun_ajaran_id,COALESCE(SUM(perubahan_poin),0) total FROM v3_poin_ledger WHERE archived_at IS NULL GROUP BY santri_id,tahun_ajaran_id) l ON l.santri_id=a.santri_id AND l.tahun_ajaran_id=a.tahun_ajaran_id WHERE a.total_poin<>COALESCE(l.total,0)')===0,'Tidak ada selisih agregat terhadap ledger');
        $check($count('SELECT COUNT(*) FROM (SELECT santri_id,tahun_ajaran_id,ambang_id FROM v3_rekomendasi GROUP BY santri_id,tahun_ajaran_id,ambang_id HAVING COUNT(*)>1) x')===0,'Rekomendasi tepat satu per ambang/subjek');
        $check($count("SELECT COUNT(*) FROM v3_rekomendasi WHERE status NOT IN ('Baru','Ditinjau','Selesai') OR total_poin_snapshot<0")===0,'Status dan snapshot rekomendasi valid');
        $check($count("SELECT COUNT(*) FROM v3_idempotency WHERE response_json IS NOT NULL AND JSON_VALID(response_json)=0")===0,'Respons idempotensi berupa JSON valid');
        $privacy=true;foreach($repo->all("SELECT judul,isi,data_json FROM notifikasi_outbox WHERE event_type LIKE 'v3_%'") as $row){$payload=mb_strtolower(implode(' ',array_map('strval',$row)));$json=json_decode((string)$row['data_json'],true);if(preg_match('/nama_santri|kategori|uraian|poin|no_hp|nomor|catatan|saksi|tempat/',$payload)||!is_array($json)||array_diff(array_keys($json),['type'])!==[])$privacy=false;}
        $check($privacy,'Payload outbox V3 tidak memuat field sensitif');
        $filesOk=true;foreach($repo->all('SELECT lokasi_privat,sha256 FROM v3_lampiran WHERE archived_at IS NULL') as $file){try{$path=(new App\V3\AttachmentStorage(APP_ROOT.'/storage/private/v3'))->absolute((string)$file['lokasi_privat']);if(!hash_equals((string)$file['sha256'],hash_file('sha256',$path)))$filesOk=false;}catch(Throwable){$filesOk=false;}}
        $check($filesOk,'Lampiran privat tersedia dan cocok dengan hash');
        $pending=glob(APP_ROOT.'/storage/private/v3/.*.pending')?:[];$check($pending===[],'Tidak ada file lampiran pending tertinggal');
    }
}catch(Throwable $exception){$check(false,'Diagnostik Fase 2 tidak dapat diselesaikan: '.$exception->getMessage());}
if($fail>0)echo "BLOCKER: {$fail} pemeriksaan Fase 2 gagal.\n";else echo "LULUS: pemeriksaan Fase 2 tanpa blocker.\n";
exit($fail===0?0:1);
