<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/app/bootstrap.php';
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}$pre=in_array('--pre',$argv,true);$fail=0;$check=static function(bool $ok,string $label)use(&$fail){echo ($ok?'[lulus] ':'[gagal] ').$label.PHP_EOL;if(!$ok)$fail++;};
try{$repo=new App\V3\KonselingRepository(app_db());$count=static fn(string $sql,array $p=[]):int=>(int)array_values($repo->one($sql,$p)??[0])[0];
    foreach(['013_v3_fase1.sql','014_v3_fase2_pelanggaran.sql','015_v3_fase2_koreksi_dan_rekomendasi.sql'] as $migration)$check($count('SELECT COUNT(*) FROM schema_migrations WHERE migration=?',[$migration])===1,'Prasyarat '.$migration.' tercatat');
    foreach(['v3_konseling_kasus','v3_konseling_sesi','v3_konseling_tautan','v3_murobi_catatan','v3_rekomendasi','audit_logs','notifikasi_outbox'] as $table)$check($count('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table])===1,'Prasyarat '.$table);
    if(!$pre){
        $check($count("SELECT COUNT(*) FROM schema_migrations WHERE migration='016_v3_fase3_konseling.sql'")===1,'Migrasi 016 tercatat');
        $check($count("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_konseling_kasus' AND COLUMN_NAME IN ('alasan_revisi_terakhir','alasan_pembatalan')")===2,'Metadata alasan kasus lengkap');
        $check($count("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_konseling_sesi' AND COLUMN_NAME IN ('alasan_penjadwalan_ulang','alasan_pembatalan')")===2,'Metadata alasan sesi lengkap');
        $check($count("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_konseling_sesi' AND INDEX_NAME='sesi_satu_revisi' AND NON_UNIQUE=0")>0,'Indeks unik satu revisi sesi terpasang');
        // Tautan sesi NULL dijaga unique tautan_unik milik 013 melalui kolom generated sesi_key (audit K8).
        $uniqueColumns=(string)($repo->one("SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) kolom FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_konseling_tautan' AND INDEX_NAME='tautan_unik' AND NON_UNIQUE=0")['kolom']??'');
        $generated=strtolower((string)($repo->one("SELECT GENERATION_EXPRESSION g FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_konseling_tautan' AND COLUMN_NAME='sesi_key'")['g']??''));
        $check($uniqueColumns==='pelanggaran_id,kasus_id,sesi_key'&&str_contains($generated,'sesi_id'),'Unique tautan 013 mencegah duplikasi termasuk sesi NULL');
        $check($count("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_konseling_tautan' AND INDEX_NAME='tautan_unik_efektif'")===0,'Tidak ada guard tautan duplikat dari draf awal 016');
        $check($count('SELECT COUNT(*) FROM v3_konseling_tautan t JOIN v3_konseling_kasus k ON k.id=t.kasus_id JOIN v3_pelanggaran p ON p.id=t.pelanggaran_id WHERE k.santri_id<>p.santri_id OR k.tahun_ajaran_id<>p.tahun_ajaran_id')===0,'Tautan pelanggaran tidak berpindah santri atau tahun');
        $check($count('SELECT COUNT(*) FROM v3_konseling_tautan t JOIN v3_konseling_sesi s ON s.id=t.sesi_id WHERE t.sesi_id IS NOT NULL AND s.kasus_id<>t.kasus_id')===0,'Tautan sesi selalu milik kasus yang sama');
        $check($count('SELECT COUNT(*) FROM v3_konseling_sesi c JOIN v3_konseling_sesi p ON p.id=c.revisi_dari_id WHERE c.kasus_id<>p.kasus_id')===0,'Revisi sesi tidak berpindah kasus');
        $check($count('SELECT COUNT(*) FROM (SELECT revisi_dari_id FROM v3_konseling_sesi WHERE revisi_dari_id IS NOT NULL GROUP BY revisi_dari_id HAVING COUNT(*)>1) x')===0,'Sumber sesi tidak mempunyai dua revisi langsung');
        $check($count("SELECT COUNT(*) FROM v3_konseling_kasus WHERE status='Selesai' AND (ditutup_pada IS NULL OR ringkasan_penutupan IS NULL OR ringkasan_penutupan='')")===0,'Kasus selesai memiliki waktu dan ringkasan penutupan');
        $check($count("SELECT COUNT(*) FROM v3_konseling_kasus WHERE status='Dibatalkan' AND (ditutup_pada IS NULL OR alasan_pembatalan IS NULL OR alasan_pembatalan='')")===0,'Kasus batal memiliki waktu dan alasan terpisah');
        $check($count("SELECT COUNT(*) FROM v3_konseling_sesi WHERE status='Dijadwalkan Ulang' AND (alasan_penjadwalan_ulang IS NULL OR alasan_penjadwalan_ulang='')")===0,'Sesi dijadwalkan ulang memiliki alasan terpisah');
        $check($count("SELECT COUNT(*) FROM v3_konseling_sesi WHERE status='Dibatalkan' AND (alasan_pembatalan IS NULL OR alasan_pembatalan='')")===0,'Sesi batal memiliki alasan terpisah');
        // Invariant audit K5/K6: baris sesi terkini tidak boleh melanggar arti statusnya sendiri.
        $current='NOT EXISTS (SELECT 1 FROM v3_konseling_sesi nx WHERE nx.revisi_dari_id=s.id)';
        $check($count("SELECT COUNT(*) FROM v3_konseling_sesi s WHERE {$current} AND s.status='Selesai' AND (s.realisasi IS NULL OR s.ringkasan_internal IS NULL OR s.ringkasan_internal='' OR s.hasil IS NULL OR s.hasil='')")===0,'Sesi selesai terkini memiliki realisasi, ringkasan internal, dan hasil');
        $check($count("SELECT COUNT(*) FROM v3_konseling_sesi s WHERE {$current} AND ((s.status IN ('Dijadwalkan','Dijadwalkan Ulang') AND s.realisasi IS NOT NULL) OR (s.status='Tidak Hadir' AND s.realisasi IS NULL))")===0,'Waktu realisasi sesi terkini sesuai statusnya');
        $check($count('SELECT COUNT(*) FROM v3_rekomendasi r JOIN v3_konseling_kasus k ON k.id=r.ditindaklanjuti_kasus_id WHERE r.ditindaklanjuti_kasus_id IS NOT NULL AND (r.santri_id<>k.santri_id OR r.tahun_ajaran_id<>k.tahun_ajaran_id OR r.ditindaklanjuti_pada IS NULL)')===0,'Rekomendasi hanya ditautkan ke kasus dengan subjek yang sama');
        // Invariant audit K2: kasus batal tidak menahan rekomendasi, dan antrean tidak membawa tautan.
        $check($count("SELECT COUNT(*) FROM v3_rekomendasi r LEFT JOIN v3_konseling_kasus k ON k.id=r.ditindaklanjuti_kasus_id WHERE (r.ditindaklanjuti_kasus_id IS NOT NULL AND (r.status<>'Ditinjau' OR k.status='Dibatalkan')) OR (r.status='Baru' AND (r.ditindaklanjuti_kasus_id IS NOT NULL OR r.ditindaklanjuti_pada IS NOT NULL))")===0,'Rekomendasi tertaut tidak berada pada kasus batal dan antrean tidak membawa tautan');
        // Keputusan Human Developer 11 September 2026 (migrasi 017): revisi kasus berbaris dan penutupan sesi terjadwal.
        $check($count("SELECT COUNT(*) FROM schema_migrations WHERE migration='017_v3_fase3_kerahasiaan_dan_revisi.sql'")===1,'Migrasi 017 tercatat');
        $check($count("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v3_konseling_kasus_revisi' AND INDEX_NAME='kasus_revisi_satu_per_versi' AND NON_UNIQUE=0")>0&&$count("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='v3_konseling_kasus_revisi' AND CONSTRAINT_TYPE='FOREIGN KEY'")===2,'Tabel revisi kasus memiliki unique per versi dan foreign key');
        $check($count("SELECT COUNT(*) FROM v3_konseling_sesi s JOIN v3_konseling_kasus k ON k.id=s.kasus_id WHERE {$current} AND s.archived_at IS NULL AND s.status IN ('Dijadwalkan','Dijadwalkan Ulang') AND k.status IN ('Selesai','Dibatalkan')")===0,'Kasus tertutup tidak menyisakan sesi terjadwal');
        $check($count('SELECT COUNT(*) FROM v3_konseling_kasus_revisi r JOIN v3_konseling_kasus k ON k.id=r.kasus_id WHERE r.versi_sebelum>=k.version')===0,'Revisi kasus selalu berasal dari versi sebelum versi kasus saat ini');
        $check($count('SELECT COUNT(*) FROM v3_konseling_kasus k JOIN v3_konseling_kasus_revisi r ON r.kasus_id=k.id AND r.versi_sebelum=(SELECT MAX(r2.versi_sebelum) FROM v3_konseling_kasus_revisi r2 WHERE r2.kasus_id=k.id) WHERE r.tujuan_sesudah<>k.tujuan OR r.kerahasiaan_sesudah<>k.kerahasiaan')===0,'Revisi kasus terakhir sama dengan nilai kasus saat ini');
        $privacy=true;foreach($repo->all("SELECT judul,isi,data_json FROM notifikasi_outbox WHERE event_type LIKE 'v3_konseling%'") as $row){$payload=mb_strtolower(implode(' ',array_map('strval',$row)));$json=json_decode((string)$row['data_json'],true);if(preg_match('/nama_santri|tujuan|ringkasan|hasil|tindak_lanjut|catatan|poin|pelanggaran/',$payload)||!is_array($json)||array_keys($json)!==['type'])$privacy=false;}$check($privacy,'Payload notifikasi konseling tidak memuat rincian sensitif');
    }
}catch(Throwable $exception){$check(false,'Diagnostik Fase 3 tidak dapat diselesaikan: '.$exception->getMessage());}
if($fail>0)echo "BLOCKER: {$fail} pemeriksaan Fase 3 gagal.\n";else echo "LULUS: pemeriksaan Fase 3 tanpa blocker.\n";exit($fail?1:0);
