<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/app/bootstrap.php';
if(getenv('V3_RUN_TESTS')!=='1'||app_config('database.database')!=='webalhasan_v3_phase1_test')exit(77);
$fail=0;$assert=static function(bool $ok,string $label)use(&$fail){echo ($ok?'[lulus] ':'[gagal] ').$label.PHP_EOL;if(!$ok)$fail++;};$source=static fn(string $path):string=>(string)file_get_contents(APP_ROOT.'/'.$path);
foreach(['app/V3/KonselingRepository.php','app/V3/KonselingService.php','portal/v3_konseling.php','portal/v3_konseling_detail.php','portal/v3_konseling_cetak.php','database/migrations/016_v3_fase3_konseling.sql','database/rollbacks/016_v3_fase3_konseling.sql','database/migrations/017_v3_fase3_kerahasiaan_dan_revisi.sql','database/rollbacks/017_v3_fase3_kerahasiaan_dan_revisi.sql','bin/v3_phase3_preflight.php','bin/v3_phase3_verify.php','bin/v3_phase3_run_tests.sh'] as $file)$assert(is_file(APP_ROOT.'/'.$file),'Berkas Fase 3 tersedia: '.$file);
$service=$source('app/V3/KonselingService.php');$repo=$source('app/V3/KonselingRepository.php');
foreach(['createCase(','correctCase(','transitionCase(','addLinks(','createSession(','correctSession(','transitionSession(','acknowledge(','page(','show(','timeline(','parentSerializer('] as $method)$assert(str_contains($service,'function '.$method),'Service menyediakan '.$method);
$assert(str_contains($repo,'FOR UPDATE')&&str_contains($repo,'v3_poin_agregat'),'Mutasi konseling memakai kunci subjek santri/tahun');
$assert(!str_contains(substr($repo,(int)strpos($repo,'function lockSubject'),700),'schema_migrations'),'Kunci operasional tidak memakai gerbang migrasi global');
$assert(str_contains($service,'auditRequired(')&&str_contains($service,"throw new V3Exception('Audit wajib tidak dapat disimpan.',503)"),'Kegagalan audit menggulung transaksi bisnis');
$assert(str_contains($service,'tidak_berlaku_pada IS NULL')||str_contains($repo,'tidak_berlaku_pada IS NULL'),'Antrean rekomendasi menyaring rekomendasi tidak berlaku');
$assert(str_contains($repo,'ditindaklanjuti_kasus_id IS NULL'),'Rekomendasi yang sudah ditindaklanjuti tidak kembali ke antrean');
$assert(str_contains($repo,'sesi_satu_revisi')||str_contains($source('database/migrations/016_v3_fase3_konseling.sql'),'sesi_satu_revisi'),'Satu sumber sesi hanya memiliki satu revisi langsung');
$assert(str_contains($service,"'v3.konseling.sesi.dikoreksi.'.\$capacity"),'Audit membedakan koreksi sesi admin dan pembimbing');
$assert(str_contains($service,"['Internal','Rahasia']")&&str_contains($service,'Transisi status kasus tidak sah.')&&str_contains($service,'Transisi status sesi tidak sah.'),'Enum dan transisi status divalidasi server');
$assert(str_contains($service,'serializeCase')&&str_contains($service,'serializeSession')&&str_contains($service,'parentSerializer'),'Serializer memakai allowlist terpisah');
$parentStart=(int)strpos($service,'function parentSerializer');$parentEnd=(int)strpos($service,'private function normaliseCaseCreate',$parentStart);$parentBody=substr($service,$parentStart,$parentEnd-$parentStart);
foreach(['tujuan','ringkasan_internal','hasil','catatan_murobi','alasan_revisi','pembimbing_id'] as $forbidden)$assert(!str_contains($parentBody,"'{$forbidden}'"),'DTO orang tua tidak memuat '.$forbidden);
$showBody=substr($service,(int)strpos($service,'function show('),2400);$assert(str_contains($showBody,"\$internal=\$mode!=='murobi'||(string)\$row['kerahasiaan']==='Internal'")&&str_contains($showBody,"'rekomendasi'=>\$internal?")&&str_contains($showBody,"'riwayat_revisi_kasus'=>\$internal?"),'Isi internal hanya untuk pembimbing/admin dan murobi pada kasus Internal');
$api=$source('api/v1/index.php');foreach(['/v3/konseling/options','/v3/konseling/kasus','/timeline','/tautan','/sesi','/diketahui'] as $route)$assert(str_contains($api,$route),'Rute API Fase 3 tersedia: '.$route);
// Pola lama di dalam string bertanda kutip ganda membaca `$` sebagai jangkar regex sehingga tidak pernah cocok (audit K10).
// Penjaga ini membaca setiap baris rute GET dan membuktikan dirinya sendiri dapat mendeteksi rute mutasi sintetis.
$getLines=preg_match_all('/^.*\$method === \'GET\'.*$/m',$api,$matches)?$matches[0]:[];
$mutatingGet=static fn(array $lines):array=>array_values(array_filter($lines,static fn(string $line):bool=>str_contains($line,'/v3/konseling')&&preg_match('#/(?:status|tautan|sesi|diketahui)\b#',$line)===1));
$assert($mutatingGet(['    if ($method === \'GET\' && preg_match(\'#^/v3/konseling/sesi/(\d+)/status$#\',$path,$matches)) {'])!==[],'Penjaga mutasi GET terbukti mendeteksi rute mutasi sintetis');
$assert(count(array_filter($getLines,static fn(string $line):bool=>str_contains($line,'/v3/konseling')))>=4&&$mutatingGet($getLines)===[],'Tidak ada mutasi konseling melalui GET');
$web=$source('portal/v3_konseling.php').$source('portal/v3_konseling_detail.php');$assert(substr_count($web,'Csrf::requireValid')===2,'Kedua controller mutasi web mewajibkan CSRF');$assert(str_contains($web,'method="post"'),'Form konseling memakai POST');$assert(str_contains($source('portal/v3_konseling_cetak.php'),'Cache-Control: private, no-store'),'Tampilan cetak internal tidak boleh dicache publik');
$navigation=$source('app/Ui/Navigation.php');$assert(str_contains($navigation,"'/portal/v3_konseling.php'")&&str_contains($navigation,"'v3.konseling'"),'Navigasi konseling mengikuti capability V3');
$violationService=$source('app/V3/PelanggaranService.php');$assert(str_contains($violationService,"'konseling'=>")&&str_contains($source('app/V3/PelanggaranRepository.php'),'function counselingForViolations'),'Detail pelanggaran memuat seluruh tindak lanjut konseling tanpa menggandakan induk');
$migration=$source('database/migrations/016_v3_fase3_konseling.sql');foreach(['alasan_revisi_terakhir','alasan_pembatalan','alasan_penjadwalan_ulang','sesi_satu_revisi','ditindaklanjuti_kasus_id','rekomendasi_kasus_fk'] as $needle)$assert(str_contains($migration,$needle),'Migrasi 016 memuat '.$needle);
$assert(!str_contains($migration,'ADD UNIQUE KEY tautan_unik_efektif')&&!str_contains($migration,'ADD COLUMN sesi_unik_guard'),'Migrasi 016 tidak memasang guard tautan yang menduplikasi tautan_unik 013');
$assert(str_contains($migration,'UPDATE v3_konseling_kasus'),'Migrasi 016 memberi penanda jujur pada kasus batal tanpa alasan');
$assert(!str_contains($migration,'DROP TABLE')&&!str_contains($migration,'DELETE FROM'),'Migrasi 016 tidak menghapus catatan bisnis');
$rollback=$source('database/rollbacks/016_v3_fase3_konseling.sql');foreach(['alasan_revisi_terakhir','alasan_pembatalan','alasan_penjadwalan_ulang','ditindaklanjuti_kasus_id','ditindaklanjuti_pada'] as $column)$assert(preg_match('/DROP COLUMN '.$column.'\b/',$rollback)===0,'Rollback 016 mempertahankan kolom keputusan '.$column);
$assert(!str_contains($rollback,'DROP FOREIGN KEY rekomendasi_kasus_fk')&&!str_contains($rollback,'DROP TABLE')&&!str_contains($rollback,'DELETE FROM'),'Rollback 016 tidak memutus tautan rekomendasi atau menghapus catatan');
$assert(!str_contains($source('bin/v3_verify.php'),"\$table==='v3_konseling_tautan'&&\$type==='UNIQUE'"),'Verifier fondasi tidak mengharapkan unique tautan tambahan dari 016');
foreach(range(1,17) as $number){$prefix=str_pad((string)$number,3,'0',STR_PAD_LEFT).'_';$assert(count(glob(APP_ROOT.'/database/migrations/'.$prefix.'*.sql')?:[])===1,'Migrasi tetap tunggal '.$prefix);}
$auth=$source('app/Api/ApiAuthService.php');$assert(!str_contains($auth,'v3_konseling'),'Menu aplikasi belum diubah sebelum Fase 5');
// Penjaga koreksi audit Claude Code Fase 3.
$assert(str_contains($service,'Kasus sudah ditutup; status sesi tidak dapat diubah lagi.'),'K1 transisi sesi memeriksa status kasus yang terkunci');
$assert(str_contains($service,'releaseRecommendations(')&&str_contains($repo,'function releaseRecommendations')&&str_contains($repo,"r.status=\\'Baru\\'"),'K2 kasus batal melepas rekomendasi dan antrean hanya memuat rekomendasi Baru');
$assert(str_contains($service,"\$input['rekomendasi_ids']")&&substr_count($service,'linkRecommendation(')>=2,'K2 rekomendasi dapat ditautkan ke kasus yang sudah berjalan');
$assert(str_contains($repo,'function violationChainLinkedToCase')&&str_contains($violationService,"counselingForViolations(array_column(\$history,'id'),\$mode,\$this->actorId(\$user))"),'K3 tautan dan tampilan tindak lanjut membaca rantai revisi pelanggaran sesuai hak pembaca');
$assert(str_contains($service,'ARRAY_FILTER_USE_BOTH')&&str_contains($service,"if(\$status==='Dibatalkan')\$data['realisasi']=null;"),'K5 field kosong formulir tidak menghapus rencana dan sesi batal tanpa realisasi');
$assert(str_contains($service,'function sessionStateRules')&&str_contains($service,'$data=$this->sessionStateRules('),'K6 koreksi sesi menjaga arti statusnya');
$assert(str_contains($service,"':v'.\$version"),'K7 kunci deduplikasi status memuat versi hasil');
$detailPage=$source('portal/v3_konseling_detail.php');
$assert(str_contains($detailPage,'->timeline($currentUser,(int)$id,$detail)')&&str_contains($source('portal/v3_konseling_cetak.php'),'->timeline($currentUser,(int)$id,$detail)'),'K9 halaman detail dan cetak tidak mengaudit akses admin dua kali');
preg_match_all('/<label class="form-label[^"]*"(?![^>]*\bfor=)/',$detailPage,$bareLabels);$assert($bareLabels[0]===[],'K11 setiap label formulir halaman detail terhubung ke kontrolnya');
$assert(str_contains($detailPage,'value="tambah_tautan"'),'K2 halaman detail menyediakan formulir tautan manual');
// Penjaga keputusan Human Developer 11 September 2026.
$assert(str_contains($repo,'function privacySql')&&substr_count($repo,'$this->privacySql(')===2&&str_contains($source('app/V3/PelanggaranRepository.php'),"k.kerahasiaan<>'Rahasia'"),'Kerahasiaan disaring pada query daftar, detail kasus, dan tindak lanjut pelanggaran');
$assert(str_contains($service,'function assertCaseAccess')&&substr_count($service,'$this->assertCaseAccess(')>=5&&str_contains($service,"if((string)(\$case['kerahasiaan']??'Rahasia')==='Rahasia')return;")&&substr_count($service,'Kasus rahasia tidak dibuka kepada murobi.')===2,'Kasus Rahasia hanya untuk pembimbing pemilik dan tidak dibuka maupun diberitahukan kepada murobi');
$assert(str_contains($service,'closeScheduledSessions(')&&str_contains($service,"'v3.konseling.sesi.ditutup_otomatis'")&&str_contains($detailPage,'ikut menutup sesi yang masih terjadwal'),'Penutupan kasus menutup sesi terjadwal secara beraudit');
$assert(str_contains($service,'insertCaseRevision(')&&str_contains($repo,'INSERT INTO v3_konseling_kasus_revisi')&&str_contains($detailPage,'Riwayat revisi kasus')&&str_contains($source('portal/v3_konseling_cetak.php'),'Riwayat revisi kasus'),'Koreksi kasus disimpan dan ditampilkan sebagai revisi berbaris');
$migration017=$source('database/migrations/017_v3_fase3_kerahasiaan_dan_revisi.sql');$rollback017=$source('database/rollbacks/017_v3_fase3_kerahasiaan_dan_revisi.sql');
$assert(str_contains($migration017,'CREATE TABLE IF NOT EXISTS v3_konseling_kasus_revisi')&&str_contains($migration017,'kasus_revisi_satu_per_versi')&&preg_match('/^\s*(DROP|DELETE)\b/mi',$migration017)===0,'Migrasi 017 aditif tanpa DROP atau DELETE');
$assert(str_contains($rollback017,'@isi = 0')&&preg_match('/^\s*DROP\b/mi',$rollback017)===0,'Rollback 017 hanya melepas tabel revisi yang masih kosong');
exit($fail?1:0);
