import pathlib,subprocess,json,sys
ROOT=pathlib.Path(__file__).resolve().parents[2];OUT=ROOT/'docs/phase-v7-5/audit-codex';TMP=pathlib.Path('/private/tmp/v7f5-codex-ios-native');TMP.mkdir(exist_ok=True)
DB='test_v7_f5_codex_initial_test';D='05999342-AE62-44C4-B0E1-1E8504CF8512';APP='com.ilanmochamad.alhasanApps';ok=bad=0

def sql(q):return subprocess.check_output(['mysql','-N','-B',DB,'-e',q],text=True).strip()
def check(v,s):
 global ok,bad
 ok+=bool(v);bad+=not bool(v);print(('[lulus] ' if v else '[gagal] ')+s,flush=True)
def flow(n,cmd):
 cmd=[{'launchApp':{'stopApp':False}}]+cmd
 f=OUT/'maestro'/(n+'.yaml');f.write_text('appId: '+APP+'\n---\n'+json.dumps(cmd,ensure_ascii=False,indent=2))
 with (OUT/(n+'.txt')).open('w') as log:r=subprocess.run(['maestro','-p','ios','--udid',D,'test','--test-output-dir',str(TMP/n),str(f)],stdout=log,stderr=subprocess.STDOUT)
 if r.returncode:raise RuntimeError(n+' gagal')
def wait(s):return {'extendedWaitUntil':{'visible':s,'timeout':15000}}
def tap(s):return {'tapOn':s}
def url():subprocess.run(['xcrun','simctl','openurl',D,'alhasanapps://izin-wali/'+str(ID)],check=True)

import urllib.request,time
BASE='http://127.0.0.1:8977/api/v1';(OUT/'maestro').mkdir(exist_ok=True)
def api(user,path,body=None):
 def req(p,b=None,t=None):
  r=urllib.request.Request(BASE+p,data=json.dumps(b).encode() if b is not None else None,headers={'Content-Type':'application/json',**({'Authorization':'Bearer '+t} if t else {})})
  with urllib.request.urlopen(r) as x:return json.load(x)['data']
 t=req('/auth/login',{'username':user,'password':'Sandbox#123','device_name':'codex-ios'})['token'];return req(path,body,t)
def login(who):
 sql('UPDATE api_tokens SET revoked_at=NOW() WHERE revoked_at IS NULL')
 subprocess.run(['xcrun','simctl','terminate',D,APP],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL);url()
 flow('ios-login-'+who,[wait('Masukkan username'),tap('Masukkan username'),'eraseText',{'inputText':who},tap('Masuk ke akun Anda'),tap('Masukkan password'),'eraseText',{'inputText':'Sandbox#123'},{'pressKey':'enter'},wait('Not Now|Diajukan wali lain|Menunggu tinjauan pembimbing|Perlu perbaikan wali|Dibatalkan|Diteruskan ke murobi'),{'runFlow':{'when':{'visible':'Not Now'},'commands':[tap('Not Now')]}},wait('Diajukan wali lain|Menunggu tinjauan pembimbing|Perlu perbaikan wali|Dibatalkan|Diteruskan ke murobi')])

def inputTap(label):
 raw=subprocess.check_output(['maestro','-p','ios','--udid',D,'hierarchy'],text=True)
 data=json.loads(raw);nodes=[]
 def walk(n):
  a=n.get('attributes',{})
  if a.get('accessibilityText','').startswith(label):
   import re
   xy=list(map(int,re.findall('[0-9]+',a['bounds'])))
   if len(xy)==4 and xy[3]-xy[1]>30:nodes.append(xy)
  for c in n.get('children',[]):walk(c)
 walk(data)
 if not nodes:raise RuntimeError('Kolom input tidak ditemukan '+label)
 x1,y1,x2,y2=nodes[-1]
 return {'tapOn':{'point':f'{round((x1+x2)*50/402)}%,{round((y1+y2)*50/874)}%'}}
def mutation(user,part,body):return api(user,f'/v7/izin/pengajuan/{ID}/{part}',{**body,'idempotency_key':'ios-'+part+'-'+str(time.time_ns())})
def deep(path):subprocess.run(['xcrun','simctl','openurl',D,'alhasanapps://'+path],check=True)
try:
 sid=int(sql("SELECT id FROM santri WHERE nis='V7S0001'"))
 made=api('sbx_v7_wali_a','/v7/izin/wali/pengajuan',{'santri_id':sid,'tgl_izin':'2054-06-04','tgl_kembali':'2054-06-05','alasan':'RAHASIA-IOS-V7F5 fixture','idempotency_key':'ios-f5-'+str(time.time_ns())});ID=made['id']
 pathlib.Path('/private/tmp/v7f5-codex-ios-id').write_text(str(ID))
 login('sbx_v7_wali_a');flow('ios-f5-cold-wali',[wait('RAHASIA-IOS-V7F5 fixture'),{'assertVisible':'Menunggu tinjauan pembimbing'}]);check(True,'iOS Release cold URI/login: pengaju membaca ID dan isi fixture Fase 5')
 login('sbx_v7_wali_b');flow('ios-f5-wali-kedua',[wait('Diajukan wali lain'),{'assertNotVisible':'.*RAHASIA-IOS-V7F5.*'},{'assertNotVisible':'Batalkan pengajuan'}]);check(True,'iOS wali kedua hanya status tanpa alasan dan mutasi')
 flow('ios-f5-latar',[{'pressKey':'home'}]);sql(f"UPDATE santri_wali sw JOIN users u ON u.wali_id=sw.wali_id SET sw.archived_at=NOW() WHERE u.username='sbx_v7_wali_b' AND sw.santri_id={sid}")
 flow('ios-f5-relasi-dicabut',[{'launchApp':{'stopApp':False}},wait('Data belum dapat ditampilkan'),{'assertNotVisible':'SBX V7 Santri Satu'},{'assertNotVisible':'.*RAHASIA-IOS-V7F5.*'}]);check(True,'iOS relasi dicabut saat latar: sesi lama menutup isi')
 sql(f"UPDATE santri_wali sw JOIN users u ON u.wali_id=sw.wali_id SET archived_at=NULL WHERE u.username='sbx_v7_wali_b' AND sw.santri_id={sid}")
 mutation('sbx_v7_pembimbing_satu','tinjauan',{'tindakan':'teruskan','version':1})
 check(sql(f"SELECT CONCAT(status,':',version) FROM izin_pengajuan WHERE id={ID}")=='Diajukan:2','Server fixture meneruskan dengan satu ID dan versi 2')
 login('sbx_murobi_a');flow('ios-f5-murobi',[wait('Putuskan di Perizinan'),tap('Putuskan di Perizinan'),wait('Diajukan orang tua')]);check(True,'iOS murobi membuka keputusan V2 pengajuan wali pada ID sama')
 ma=sql("SELECT GROUP_CONCAT(ma.id) FROM murobi_assignments ma JOIN users u ON u.guru_id=ma.guru_id WHERE u.username='sbx_murobi_a' AND ma.is_active=1")
 flow('ios-f5-murobi-latar',[{'pressKey':'home'}]);sql(f'UPDATE murobi_assignments SET is_active=0 WHERE id IN ({ma})')
 flow('ios-f5-murobi-dicabut',[{'launchApp':{'stopApp':False}},wait('Data belum dapat ditampilkan'),{'assertNotVisible':'SBX V7 Santri Satu'},{'assertNotVisible':'.*RAHASIA-IOS-V7F5.*'}]);check(True,'iOS seluruh penugasan murobi dicabut: keputusan V2 menutup data saat kembali')
 check(sql(f'SELECT COUNT(*) FROM izin_keputusan WHERE pengajuan_id={ID}')=='0','iOS pencabutan tidak membuat keputusan')
except BaseException as e:
 bad+=1;print('[gagal] '+str(e),flush=True)
finally:
 if 'ma' in globals():sql(f'UPDATE murobi_assignments SET is_active=1 WHERE id IN ({ma})')
 if 'sid' in globals():sql(f"UPDATE santri_wali sw JOIN users u ON u.wali_id=sw.wali_id SET archived_at=NULL WHERE u.username='sbx_v7_wali_b' AND sw.santri_id={sid}")
 print(f'Ringkasan: {ok} lulus, {bad} gagal',flush=True);sys.exit(1 if bad else 0)
