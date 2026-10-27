import datetime
import sys,time,subprocess,json,urllib.request,pathlib,shlex
sys.path.insert(0,str(pathlib.Path(__file__).parent));import adbui as u
DB='test_v7_f5_codex_initial_test';BASE='http://127.0.0.1:8977/api/v1';APP='com.ilanmochamad.alhasanApps';passed=failed=0
def sql(q):return subprocess.check_output(['mysql','-N','-B',DB,'-e',q],text=True).strip()
def check(v,s):
 global passed,failed
 passed+=bool(v);failed+=not bool(v);print(('[lulus] ' if v else '[gagal] ')+s,flush=True)
def api(user,path,body=None):
 def request(p,b=None,t=None):
  req=urllib.request.Request(BASE+p,data=json.dumps(b).encode() if b is not None else None,headers={'Content-Type':'application/json',**({'Authorization':'Bearer '+t} if t else {})})
  with urllib.request.urlopen(req) as r:return json.load(r)['data']
 t=request('/auth/login',{'username':user,'password':'Sandbox#123','device_name':'auditor-native'})['token'];return request(path,body,t)
def deep(path):u.sh('shell','am','start','-a','android.intent.action.VIEW','-d',shlex.quote('alhasanapps://'+path),APP)
def wait(s):
 if u.find(s,timeout=45) is None:raise RuntimeError('Tidak ditemukan '+s)
def tap(s):
 n=u.scroll_to(s,exact=True)
 if n is None:raise RuntimeError('Tidak ditemukan tombol '+s)
 x,y=u.center(n);u.sh('shell','input','tap',str(x),str(y))
def login(user):
 sql('UPDATE api_tokens SET revoked_at=NOW() WHERE revoked_at IS NULL');deep('izin-wali');wait('Masuk ke akun Anda');u.typ('Masukkan username',user);u.typ('Masukkan password','Sandbox#123');u.tap('Masuk',exact=True);time.sleep(2)
try:
 sid=int(sql("SELECT id FROM santri WHERE nis='V7S0001'"))
 day=(datetime.date(2071,1,1)+datetime.timedelta(days=int(sql("SELECT COUNT(*) FROM izin_pengajuan WHERE asal_pengajuan='Wali'"))*3)).isoformat()
 id=api('sbx_v7_wali_a','/v7/izin/wali/pengajuan',{'santri_id':sid,'tgl_izin':day,'tgl_kembali':day,'alasan':'RAHASIA-AUDIT-NATIVE fixture F5','idempotency_key':'android-f5-'+str(time.time_ns())})['id'];pathlib.Path('/private/tmp/v7f51-codex-native-id').write_text(str(id))
 login('sbx_v7_wali_a');deep('izin-wali/'+str(id));wait('Menunggu tinjauan pembimbing')
 check('RAHASIA-AUDIT-NATIVE' in u.text_all(),'Android wali pengaju membaca isi pada ID kanonis')
 flags=u.sh('shell','dumpsys','window','windows');check('SECURE' in flags,'Android layar V7 memakai FLAG_SECURE')
 deep('profil');wait('Keluar dari aplikasi');tap('Keluar dari aplikasi');wait('Keluar dari aplikasi?');tap('KELUAR');wait('Masuk ke akun Anda');deep('izin-wali/'+str(id));wait('Masuk ke akun Anda')
 check('RAHASIA-AUDIT-NATIVE' not in u.text_all(),'Android logout lalu URI: harus login tanpa isi pengajuan')
 u.typ('Masukkan username','sbx_v7_wali_b');u.typ('Masukkan password','Sandbox#123');u.tap('Masuk',exact=True);wait('Diajukan wali lain')
 check('RAHASIA-AUDIT-NATIVE' not in u.text_all(),'Android wali kedua: status ringkas tanpa alasan')
 u.sh('shell','input','keyevent','3');sql(f"UPDATE santri_wali sw JOIN users u ON u.wali_id=sw.wali_id SET archived_at=NOW() WHERE u.username='sbx_v7_wali_b' AND sw.santri_id={sid}")
 deep('izin-wali/'+str(id));wait('Data belum dapat ditampilkan');check('SBX V7 Santri Satu' not in u.text_all(),'Android relasi dicabut pada sesi lama: kembali dari latar tanpa isi')
 sql("UPDATE santri_wali sw JOIN users u ON u.wali_id=sw.wali_id SET archived_at=NULL WHERE u.username='sbx_v7_wali_b'")
 login('sbx_v7_pembimbing_satu');deep('izin-wali/'+str(id));wait('Teruskan ke murobi');n=u.scroll_to('Teruskan ke murobi',exact=True);x,y=u.center(n)
 for _ in range(2):u.sh('shell','input','tap',str(x),str(y))
 wait('Pengajuan diteruskan');check(sql(f"SELECT CONCAT(status,':',version) FROM izin_pengajuan WHERE id={id}")=='Diajukan:2' and sql(f'SELECT COUNT(*) FROM izin_pembimbing_tinjauan WHERE pengajuan_id={id}')=='1','Android dua ketukan pembimbing: satu penerusan pada ID sama')
 login('sbx_murobi_a');deep('izin-wali/'+str(id));wait('Putuskan di Perizinan');tap('Putuskan di Perizinan');wait('Diajukan orang tua')
 check('SECURE' in u.sh('shell','dumpsys','window','windows'),'Android keputusan V2 atas pengajuan wali tetap ber-SECURE')
 ma=sql(f"SELECT GROUP_CONCAT(DISTINCT ma.id) FROM murobi_assignments ma JOIN users u ON u.guru_id=ma.guru_id JOIN plotting_kamar pk ON pk.id_kamar=ma.kamar_id AND pk.id_tahun=ma.tahun_ajaran_id WHERE pk.id_santri={sid} AND u.username='sbx_murobi_a' AND ma.is_active=1");sql(f"UPDATE murobi_assignments SET is_active=0 WHERE id IN ({ma})");check(int(sql("SELECT COUNT(*) FROM murobi_assignments ma JOIN users u ON u.guru_id=ma.guru_id WHERE u.username='sbx_murobi_a' AND ma.is_active=1"))>0,'Kontrol F5-1: penugasan murobi lain tetap aktif sesudah cakupan santri dicabut');u.sh('shell','input','keyevent','3');deep('izin/'+str(id)+'?mode=murobi');wait('Data belum dapat ditampilkan')
 check('RAHASIA-AUDIT-NATIVE' not in u.text_all() and sql(f'SELECT COUNT(*) FROM izin_keputusan WHERE pengajuan_id={id}')=='0','Android layar V2 setelah pencabutan parsial dan kembali dari latar: isi tertutup, nol keputusan')
except BaseException as e:
 failed+=1;print('[gagal] '+str(e),flush=True)
finally:

 if 'ma' in globals():sql(f"UPDATE murobi_assignments SET is_active=1 WHERE id IN ({ma})")
 if 'sid' in globals():sql(f"UPDATE santri_wali sw JOIN users u ON u.wali_id=sw.wali_id SET archived_at=NULL WHERE u.username='sbx_v7_wali_b' AND sw.santri_id={sid}")
 print(f'Ringkasan: {passed} lulus, {failed} gagal',flush=True);sys.exit(1 if failed else 0)
