import sys,time,subprocess,json,urllib.request,pathlib,shlex
sys.path.insert(0,str(pathlib.Path(__file__).parent));import adbui as u
DB='test_v6_f4_codex_native_test';BASE='http://127.0.0.1:8987/api/v1';PKG='com.ilanmochamad.alhasanApps'
passed=failed=0
def check(b,s):
 global passed,failed
 print(('[lulus] ' if b else '[gagal] ')+s,flush=True)
 if b:passed+=1
 else:failed+=1
def sql(q):return subprocess.check_output(['mysql','-N','-B',DB,'-e',q],text=True).strip()
def api(path,body=None,token=None):
 data=json.dumps(body).encode() if body is not None else None
 req=urllib.request.Request(BASE+path,data=data,headers={'Content-Type':'application/json',**({'Authorization':'Bearer '+token} if token else {})})
 with urllib.request.urlopen(req) as r:return json.load(r)['data']
def deep(path):
 u.sh('shell','am','start','-a','android.intent.action.VIEW','-d',shlex.quote('alhasanapps://'+path.lstrip('/')),PKG);time.sleep(1)
def wait(s):
 if u.find(s,timeout=60) is None:raise RuntimeError('Tidak ditemukan: '+s)
def tap(s):
 for _ in range(8):
  matches=[n for n in u.nodes(u.dump()) if s in [(n.get('text') or ''),(n.get('content-desc') or '')]]
  if matches:
   x,y=u.center(matches[-1]);u.sh('shell','input','tap',str(x),str(y));return
  u.sh('shell','input','swipe','540','1700','540','700','300')
 raise RuntimeError('Tidak ditemukan tombol: '+s)
def typ(s,v):
 if u.scroll_to(s) is None:raise RuntimeError('Tidak ditemukan kolom: '+s)
 u.typ(s,v)
def text():return u.text_all()

def login(user):
 deep('/profil');wait('Keluar dari aplikasi');tap('Keluar dari aplikasi');wait('Keluar dari aplikasi?');tap('KELUAR');wait('Masuk ke akun Anda')
 u.typ('Masukkan username',user);u.typ('Masukkan password','Sandbox#123');u.tap('Masuk',exact=True);wait('Assalamu')
try:
 s=int(sql("SELECT id FROM santri WHERE nis='V6S0001'"))
 auth=api('/auth/login',{'username':'sbx_v6_bsa_b','password':'Sandbox#123','device_name':'audit-native-cache'});token=auth['token']
 check('SBX V6 Santri Tsanawi' in text(),'Android: tujuan deep link ditahan melewati login; akun BSA yang berhak membaca tujuan')
 marker='AUDIT-NATIVE-TRANSFER-PRIVATE'
 api(f'/v6/bsa/santri/{s}/setoran',{'idempotency_key':'android-audit-transfer-cache','nominal':'7000','metode':'Transfer','referensi':marker},token)
 deep('/keuangan/verifikasi');wait(marker);check(marker in text(),'Android petugas: referensi transfer sintetis benar dimuat dari API')
 # Scan raw private app directories, including HTTP/cache storage. SecureStore
 # encrypted authentication is necessary; no raw secret/file content is emitted.
 import io,tarfile,re
 raw=subprocess.check_output([u.ADB,'exec-out','run-as',PKG,'tar','cf','-','files','shared_prefs','cache','databases'],stderr=subprocess.DEVNULL)
 pathlib.Path('/private/tmp/v6f4-codex-native/app-data.tar').write_bytes(raw)
 members=[]
 with tarfile.open(fileobj=io.BytesIO(raw)) as tar:
  for m in tar:
   if m.isfile():members.append((m.name,tar.extractfile(m).read()))
 needles=[marker.encode(),b'SBX V6 Santri',b'BSA-KW-2026-',b'BSA-TB-2026-',b'Sandbox#123']
 hits=sum(any(n in data for n in needles) for _,data in members)
 bearer=sum(bool(re.search(rb'Bearer [A-Za-z0-9_\-\.]{24,}',data)) for _,data in members)
 check(hits==0 and bearer==0,f'Android disk/cache: {len(members)} berkas, {hits} berkas berpenanda keuangan/kata sandi, {bearer} bearer mentah')
 pid=u.sh('shell','pidof',PKG).strip();log=u.sh('logcat','-d','--pid='+pid)
 badlog=any(n.decode() in log for n in needles) or bool(re.search(r'Bearer [A-Za-z0-9_\-\.]{24,}',log))
 check(not badlog,f'Android logcat proses: {len(log.splitlines())} baris tanpa referensi/nama/kuitansi/kata sandi/bearer mentah')
 u.sh('shell','input','keyevent','187');time.sleep(1)
 png=subprocess.check_output([u.ADB,'exec-out','screencap','-p']);pathlib.Path('/private/tmp/v6f4-codex-native/recents.png').write_bytes(png)
 flags=u.sh('shell','dumpsys','window','windows');check('SECURE' in flags,'Android Recents: jendela Keuangan memakai FLAG_SECURE (gambar diperiksa terpisah)')
 u.sh('shell','am','force-stop',PKG);u.sh('shell','am','start','-a','android.intent.action.VIEW','-d','alhasanapps://keuangan/verifikasi',PKG);wait(marker)
 check(marker in text(),'Android force-stop + tautan skema pada dev client: tujuan meminta data akun aktif; bukan cold start build rilis/Push')
 deep('/profil');wait('Keluar dari aplikasi');flags=u.sh('shell','dumpsys','window','windows')
 check(' SECURE ' not in flags,'Android: perlindungan Keuangan dilepas saat keluar navigator ke Profil')
except BaseException as e:
 failed+=1;print('[gagal] '+str(e),flush=True)
finally:
 print(f'Ringkasan: {passed} lulus, {failed} gagal',flush=True)
 sys.exit(1 if failed else 0)
