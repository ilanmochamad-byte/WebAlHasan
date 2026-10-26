import pathlib,subprocess,json,urllib.request,re,hashlib,sys,shutil
ROOT=pathlib.Path('/Users/ilanmochamad/Documents/GitHub/webalhasan/WebAlHasan');OUT=ROOT/'docs/phase-v6-4/audit-ulang-codex';DEVICE='05999342-AE62-44C4-B0E1-1E8504CF8512';BUNDLE='com.ilanmochamad.alhasanApps';BASE='http://127.0.0.1:8990/api/v1';MARK='REAUDIT-IOS-TRF-PRIVATE'
def req(p,b,t=None):
 h={'Content-Type':'application/json'}
 if t:h['Authorization']='Bearer '+t
 with urllib.request.urlopen(urllib.request.Request(BASE+p,data=json.dumps(b).encode(),headers=h))as r:return json.load(r)['data']
t=req('/auth/login',{'username':'sbx_v6_bsa_b','password':'Sandbox#123','device_name':'codex-reaudit-privacy'})['token']
req('/v6/bsa/santri/1710/setoran',{'idempotency_key':'ios-reaudit-privacy-transfer','nominal':'7000','metode':'Transfer','referensi':MARK},t)
subprocess.run(['xcrun','simctl','openurl',DEVICE,'alhasanapps://keuangan/santri/1710'],check=True)
flow=pathlib.Path('/private/tmp/v6f4-reaudit-maestro/ios-privasi.yaml')
cmd=[{'extendedWaitUntil':{'visible':'.*SBX V6 Santri SMP.*','timeout':15000}},{'scrollUntilVisible':{'element':{'text':'.*'+MARK+'.*'},'direction':'DOWN','timeout':15000}},{'assertVisible':'.*'+MARK+'.*'}]
flow.write_text('appId: '+BUNDLE+'\n---\n'+json.dumps(cmd,indent=2)+'\n')
with (OUT/'ios-privasi-ui.txt').open('w')as f:r=subprocess.run(['maestro','-p','ios','--udid',DEVICE,'test','--test-output-dir','/private/tmp/v6f4-reaudit-maestro/output-privasi',str(flow)],stdout=f,stderr=subprocess.STDOUT)
if r.returncode:print('[gagal] Referensi belum teramati di UI');sys.exit(1)
print('[lulus] Referensi transfer sintetis benar dimuat pada layar admin iOS',flush=True)
p=pathlib.Path(subprocess.check_output(['xcrun','simctl','get_app_container',DEVICE,BUNDLE,'data'],text=True).strip())
needles=[MARK.encode(),b'SBX V6 Santri',b'BSA-TB-2026-',b'BSA-KW-2026-',b'Sandbox#123']
files=[];bad=[];unreadable=[]
for f in p.rglob('*'):
 if not f.is_file() or f.is_symlink():continue
 try:data=f.read_bytes()
 except OSError:unreadable.append(str(f.relative_to(p)));continue
 files.append(str(f.relative_to(p)))
 if any(n in data for n in needles) or re.search(rb'Bearer [A-Za-z0-9_.-]{24,}',data):bad.append(str(f.relative_to(p)))
print(('[lulus]' if not bad and not unreadable else '[gagal]')+f' Kontainer iOS: {len(files)} berkas, {len(bad)} berpenanda rahasia/bearer mentah, {len(unreadable)} tidak terbaca',flush=True)
log=subprocess.check_output(['xcrun','simctl','spawn',DEVICE,'log','show','--last','60m','--style','compact','--predicate','process == "alhasanApps"'],stderr=subprocess.DEVNULL)
pathlib.Path('/private/tmp/v6f4-reaudit-ios-process.log').write_bytes(log)
hits=any(n in log for n in needles) or bool(re.search(rb'Bearer [A-Za-z0-9_.-]{24,}',log))
print(('[lulus]' if not hits else '[gagal]')+f' Log proses iOS: {len(log.splitlines())} baris tanpa penanda yang diuji/bearer mentah',flush=True)
(OUT/'ios-privasi-data.json').write_text(json.dumps({'files':files,'sensitive_marker_files':bad,'unreadable':unreadable,'log_lines':len(log.splitlines()),'log_sensitive_marker_found':hits},indent=2)+'\n')
# Metro audit sudah dihentikan setelah Android selesai.
subprocess.run(['xcrun','simctl','terminate',DEVICE,BUNDLE],check=True)
subprocess.run(['xcrun','simctl','openurl',DEVICE,'alhasanapps://keuangan/tagihan/1'],check=True)
cmd=[{'extendedWaitUntil':{'visible':'Rincian biaya','timeout':15000}},{'assertVisible':'.*SBX V6 Santri SMP.*'},{'assertNotVisible':'Development Build'}]
f=pathlib.Path('/private/tmp/v6f4-reaudit-maestro/ios-release-tanpa-metro.yaml');f.write_text('appId: '+BUNDLE+'\n---\n'+json.dumps(cmd,indent=2)+'\n')
with (OUT/'ios-release-tanpa-metro.txt').open('w')as o:r=subprocess.run(['maestro','-p','ios','--udid',DEVICE,'test','--test-output-dir','/private/tmp/v6f4-reaudit-maestro/output-tanpa-metro',str(f)],stdout=o,stderr=subprocess.STDOUT)
print(('[lulus]' if r.returncode==0 else '[gagal]')+' Cold start iOS Release tetap membuka tujuan setelah Metro audit dihentikan',flush=True)
sys.exit(1 if bad or unreadable or hits or r.returncode else 0)
