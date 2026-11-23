from pathlib import Path
import subprocess,os,re,json,time
root=Path('/Users/ilanmochamad/Documents/GitHub/webalhasan/WebAlHasan')
base=Path('/private/tmp/iop-codex-audit/baseline-git')
out=root/'docs/izin-otomatis-pengajian/bukti-audit/regresi-pasangan';out.mkdir(parents=True,exist_ok=True)
# Identical historical executables; schemas correspond to actual commits (035 / 036).
cases=[
 ('jkg-int','jadwal_kelas_gabungan_f1_integration.php','JKG_DB',{}),
 ('jkg-http','jadwal_kelas_gabungan_f1_http.php','JKG_DB',{}),
 ('jkg-audit','jadwal_kelas_gabungan_f1_audit_probe.php','JKG_DB',{}),
 ('jkg-mig','jadwal_kelas_gabungan_f1_migration.php','JKG_DB',{}),
 ('ppa-int','perbaikan_peserta_absensi_integration.php','PPA_DB',{}),
 ('ppa-conc','perbaikan_peserta_absensi_konkurensi.php','PPA_DB',{}),
 ('ppa-audit','perbaikan_peserta_absensi_audit.php','PPA_DB',{}),
 ('pcs-http','pencarian_santri_http.php','PKF3_DB',{}),
 ('pcs-audit','pencarian_santri_audit.php','DB_NAME',{}),
 ('pk3-int','penugasan_keamanan_f3_integration.php','PKF3_DB',{}),
 ('pk3-http','penugasan_keamanan_f3_http.php','PKF3_DB',{}),
 ('pk3-audit','penugasan_keamanan_f3_audit_probe.php','PKF3_DB',{}),
 ('pk4-http','penugasan_keamanan_f4_http.php','PKF3_DB',{}),
 ('v7f5-e2e','v7_phase5_e2e.php','V7F5_DB',{}),
 ('v7f5-http','v7_phase5_http.php','V7F5_DB',{'V7F5_PORT':'8977'}),
 ('v7f4-http','v7_phase4_http.php','V7F4_DB',{}),
 ('v7f3-int','v7_phase3_integration.php','V7F3_DB',{}),
 ('v7f2-int','v7_phase2_integration.php','V7F2_DB',{}),
 ('v7f1-int','v7_phase1_integration.php','V7F1_DB',{}),
 ('v2f1-int','v2_phase1_integration.php','DB_NAME',{'V2_PHASE1_RUN_INTEGRATION':'1'}),
 ('v2f2-int','v2_phase2_integration.php','DB_NAME',{'V2_PHASE2_RUN_INTEGRATION':'1'}),
 ('v2f4-int','v2_phase4_integration.php','DB_NAME',{'V2_PHASE4_RUN_INTEGRATION':'1'}),
 ('v2f5-int','v2_phase5_integration.php','DB_NAME',{'V2_PHASE5_RUN_INTEGRATION':'1'}),
 ('v1f3-int','phase3_integration.php','DB_NAME',{'PHASE3_RUN_INTEGRATION':'1'}),
 ('v1f4-int','phase4_integration.php','DB_NAME',{'PHASE4_RUN_INTEGRATION':'1'}),
 ('v1f5-int','phase5_integration.php','DB_NAME',{'PHASE5_RUN_INTEGRATION':'1'}),
]
results=[]
for name,file,var,extra in cases:
 for tag,tree,schema in [('final',root,'036'),('baseline',base,'035')]:
  db='test_cap_'+tag+'_'+name.replace('-','_')+'_test'
  subprocess.run(['mysql','-e',f'DROP DATABASE IF EXISTS `{db}`; CREATE DATABASE `{db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'],check=True)
  with open(f'/private/tmp/iop-codex-audit/core{schema}.sql','rb')as sql:
   subprocess.run(['mysql',db],stdin=sql,check=True,stdout=subprocess.DEVNULL)
  env={**os.environ,var:db,**extra}
  with (out/f'{name}-{tag}.txt').open('w')as log:
   if name=='pcs-audit':
    seed=subprocess.run(['php','tests/penugasan_keamanan_f3_seed.php'],cwd=tree,env={**env,'PKF3_DB':db},stdout=subprocess.DEVNULL,stderr=log)
    if seed.returncode:raise RuntimeError('Seed failed')
   t=time.time();r=subprocess.run(['php','tests/'+file],cwd=tree,env=env,stdout=log,stderr=subprocess.STDOUT)
  txt=(out/f'{name}-{tag}.txt').read_text()
  good=len(re.findall(r'^ *(?:\[(?:lulus|OK|ok|PASS)\]|✓|PASS|ok )',txt,re.M));bad=len(re.findall(r'^ *(?:\[(?:gagal|GAGAL|FAIL)\]|✗|FAIL|not ok )',txt,re.M))
  item={'suite':name,'source':tag,'exit':r.returncode,'lulus':good,'gagal':bad,'seconds':round(time.time()-t,1)};results.append(item)
  (out/'hasil.json').write_text(json.dumps(results,indent=2)+'\n');print(json.dumps(item),flush=True)
print('SELESAI',flush=True)
