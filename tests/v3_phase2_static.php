<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/app/bootstrap.php';
if(getenv('V3_RUN_TESTS')!=='1'||app_config('database.database')!=='webalhasan_v3_phase1_test')exit(77);
$fail=0;$assert=static function(bool $ok,string $label)use(&$fail){echo ($ok?'[lulus] ':'[gagal] ').$label.PHP_EOL;if(!$ok)$fail++;};
$source=static fn(string $path):string=>(string)file_get_contents(APP_ROOT.'/'.$path);
foreach(['app/V3/PelanggaranRepository.php','app/V3/PelanggaranService.php','app/V3/AttachmentStorage.php','portal/v3_pelanggaran.php','portal/v3_pelanggaran_detail.php','portal/v3_lampiran.php','database/migrations/014_v3_fase2_pelanggaran.sql','database/rollbacks/014_v3_fase2_pelanggaran.sql'] as $file){$assert(is_file(APP_ROOT.'/'.$file),'Berkas Fase 2 tersedia: '.$file);}
$cap=$source('app/Auth/Capabilities.php');
$assert(str_contains($cap,"'sumber' => \$entry['sumber']"),'T-2 mempertahankan provenance capability fondasi');
$repo=$source('app/V3/PelanggaranRepository.php');
$assert(str_contains($repo,'v3_poin_agregat')&&str_contains($repo,'FOR UPDATE'),'Kunci operasional memakai baris agregat santri/tahun');
$assert(!str_contains(substr($repo,(int)strpos($repo,'function lockSubject'),900),'schema_migrations'),'Mutasi operasional tidak memakai gerbang schema_migrations');
$assert(str_contains($repo,'pembalik_dari_id'),'Pembatalan dan koreksi memakai ledger pembalik');
$assert(str_contains($repo,'ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)'),'Deduplikasi idempotensi/rekomendasi ditegakkan database');
$service=$source('app/V3/PelanggaranService.php');
foreach(['create(','correct(','cancel(','acknowledge(','page(','show(','history('] as $method){$assert(str_contains($service,'function '.$method),'Service menyediakan '.$method);}
$assert(str_contains($service,"'v3.pelanggaran.dikoreksi.'.\$capacity"),'Audit membedakan koreksi admin dan pembimbing');
$assert(str_contains($service,'serializeViolation')&&str_contains($service,'serializeLedger')&&str_contains($service,'serializeRecommendation'),'Serializer baru memakai allowlist eksplisit');
$assert(!preg_match('/return\s+\$this->repo->(?:all|one|page)\(/',$service),'Service tidak mengembalikan baris database mentah');
$api=$source('api/v1/index.php');
foreach(["\$method === 'POST' && \$path === '/v3/pelanggaran'","\$method === 'PATCH' && preg_match",'/pembatalan','/diketahui'] as $needle){$assert(str_contains($api,$needle),'Rute mutasi V3 tersedia tanpa GET: '.$needle);}
$assert(!preg_match("/\\\$method === 'GET'.*\\/(?:pembatalan|diketahui)/",$api),'Tidak ada mutasi status melalui GET');
$web=$source('portal/v3_pelanggaran.php').$source('portal/v3_pelanggaran_detail.php');
$assert(str_contains($web,'Csrf::requireValid'),'Mutasi web mewajibkan CSRF');
$assert(str_contains($web,'method="post"'),'Form mutasi web memakai POST');
$assert(str_contains($web,'accept="image/jpeg,image/png,application/pdf"'),'Lampiran web dibatasi tipe aman');
$legacy=$source('admin/admin_pelanggaran.php');
$assert(str_contains($legacy,'Data warisan'),'Halaman pelanggaran lama tetap berlabel Data warisan');
$assert(!str_contains($legacy,'DELETE FROM pelanggaran'),'Halaman warisan tidak membuka penghapusan');
$navigation=$source('app/Ui/Navigation.php');
$assert(str_contains($navigation,"'/portal/v3_pelanggaran.php'")&&str_contains($navigation,'Data pelanggaran warisan'),'Menu web membedakan V3 dan data warisan');
$auth=$source('app/Api/ApiAuthService.php');
$assert(!str_contains($auth,'Navigation::'),'Menu aplikasi tetap bukan dari App\\Ui\\Navigation');
$migration=$source('database/migrations/014_v3_fase2_pelanggaran.sql');
$assert(str_contains($migration,'UNIQUE KEY poin_agregat_subjek_unik (santri_id, tahun_ajaran_id)'),'Migrasi memberi satu kunci agregat per subjek');
$assert(str_contains($migration,'UNIQUE KEY rekomendasi_ambang_subjek_unik'),'Migrasi menjamin satu rekomendasi per ambang');
$assert(str_contains($migration,'pelanggaran_satu_revisi'),'Migrasi mencegah dua revisi atas sumber yang sama');
foreach(range(1,13) as $number){$prefix=str_pad((string)$number,3,'0',STR_PAD_LEFT).'_';$files=glob(APP_ROOT.'/database/migrations/'.$prefix.'*.sql')?:[];$assert(count($files)===1,'Migrasi lama tetap tunggal '.$prefix);}
$verify=$source('bin/v3_verify.php');
$assert(str_contains($verify,'[warisan]')&&str_contains($verify,'exit($fail===0&&$warisan===0?0:1)'),'Diagnostik yatim warisan tetap exit nonzero');
exit($fail?1:0);
