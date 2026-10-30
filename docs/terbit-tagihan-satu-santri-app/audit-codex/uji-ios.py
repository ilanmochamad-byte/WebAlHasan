# Fixture sintetis test_v6_ss_app_ios_test; API 127.0.0.1:8990; build Release sumber audit.
import pathlib, subprocess, json, sys
ROOT=pathlib.Path(__file__).resolve().parents[3];OUT=ROOT/'docs/terbit-tagihan-satu-santri-app/audit-codex';TMP=pathlib.Path('/private/tmp/v6ss-app-codex-ios');TMP.mkdir(exist_ok=True);(OUT/'maestro').mkdir(exist_ok=True)
DB='test_v6_ss_app_ios_test';D='05999342-AE62-44C4-B0E1-1E8504CF8512';APP='com.ilanmochamad.alhasanApps';ok=bad=0
def sql(q):return subprocess.check_output(['mysql','-N','-B',DB,'-e',q],text=True).strip()
def check(v,s):
 global ok,bad
 ok+=bool(v);bad+=not bool(v);print(('[lulus] ' if v else '[gagal] ')+s,flush=True)
def flow(n,cmd):
 f=OUT/'maestro'/(n+'.yaml');f.write_text('appId: '+APP+'\n---\n'+json.dumps(cmd,ensure_ascii=False,indent=2))
 with (OUT/(n+'.txt')).open('w') as log:r=subprocess.run(['maestro','-p','ios','--udid',D,'test','--test-output-dir',str(TMP/n),str(f)],stdout=log,stderr=subprocess.STDOUT)
 if r.returncode:raise RuntimeError(n+' gagal')
def wait(s):return {'extendedWaitUntil':{'visible':s,'timeout':20000}}
def tap(s):return {'tapOn':s}
def deep(path):subprocess.run(['xcrun','simctl','openurl',D,'alhasanapps://'+path],check=True)
def login(who):
 sql('UPDATE api_tokens SET revoked_at=NOW() WHERE revoked_at IS NULL')
 subprocess.run(['xcrun','simctl','terminate',D,APP],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL);deep('keuangan')
 flow('ios-login-'+who,[wait('Masukkan username'),tap('Masukkan username'),'eraseText',{'inputText':who},tap('Masuk ke akun Anda'),tap('Masukkan password'),'eraseText',{'inputText':'Sandbox#123'},{'pressKey':'enter'},wait('Not Now|Keuangan anak|Keuangan'),{'runFlow':{'when':{'visible':'Not Now'},'commands':[tap('Not Now')]}},wait('Keuangan anak|Keuangan')])
sid=int(sql("SELECT id FROM santri WHERE nis='V6S0001'"));penugasan=sql("SELECT a.id FROM bendahara_bulanan_assignments a JOIN users u ON u.pengurus_id=a.pengurus_id WHERE u.username='sbx_v6_bsa_b'")
try:
 login('sbx_v6_bsa_b');deep('keuangan/santri/'+str(sid))
 flow('ios-tombol-pratinjau',[wait('Terbitkan tagihan'),tap('Terbitkan tagihan'),wait('Tampilkan tagihan'),tap('Tampilkan tagihan'),wait('Total tagihan'),{'assertVisible':r'Rp150\.000'},{'assertVisible':r'Rp200\.000'},{'assertVisible':r'−Rp50\.000'},{'assertVisible':'Terbitkan tagihan Rp300.000'}])
 check(sql('SELECT COUNT(*) FROM bsa_tagihan')=='0','iOS BSA: tombol detail, tarif150000+200000-potongan50000=300000; pratinjau tanpa tagihan')
 flow('ios-pratinjau-latar',[{'pressKey':'home'},{'launchApp':{'stopApp':False}},wait('Tampilkan tagihan'),{'assertNotVisible':'Total tagihan'},{'assertNotVisible':'Terbitkan tagihan Rp300.000'}])
 check(True,'iOS kembali dari latar membuang pratinjau lama, meminta pratinjau baru')
 flow('ios-terbit',[tap('Tampilkan tagihan'),wait('Terbitkan tagihan Rp300.000'),tap('Terbitkan tagihan Rp300.000'),wait('Rincian tagihan')])
 check(sql(f"SELECT COUNT(*) FROM bsa_tagihan WHERE santri_id={sid} AND total=300000")=='1' and sql('SELECT COUNT(*) FROM bsa_tagihan')=='1' and sql('SELECT jumlah_sasaran FROM bsa_penerbitan ORDER BY id DESC LIMIT 1')=='1','iOS terbit: tepat satu tagihan300000, satu sasaran; membuka rincian')
 deep('keuangan/terbit-santri?santri_id='+str(sid))
 flow('ios-sudah-ada',[wait('Tampilkan tagihan'),tap('Tampilkan tagihan'),wait('Buka tagihan'),{'assertNotVisible':'Terbitkan tagihan Rp300.000'},tap('Buka tagihan'),wait('Rincian tagihan')]);check(True,'iOS periode sudah terbit membuka tagihan yang sama')
 deep('keuangan/terbit-santri?santri_id='+str(sid));flow('ios-bsa-latar',[wait('Tampilkan tagihan'),{'pressKey':'home'}]);sql('UPDATE bendahara_bulanan_assignments SET is_active=0 WHERE id='+penugasan)
 flow('ios-bsa-dicabut',[{'launchApp':{'stopApp':False}},wait('Data belum dapat ditampilkan'),{'assertNotVisible':'Tampilkan tagihan'}]);check(True,'iOS penugasan dicabut saat latar menutup formulir pada sesi lama');sql('UPDATE bendahara_bulanan_assignments SET is_active=1 WHERE id='+penugasan)
 for who in ['sbx_admin','sbx_v6_wali_dua']:
  login(who);deep('keuangan/santri/'+str(sid));flow('ios-detail-'+who,[wait('SBX V6 Santri SMP'),{'assertNotVisible':'Terbitkan tagihan'}]);deep('keuangan/terbit-santri?santri_id='+str(sid));flow('ios-langsung-'+who,[wait('Data belum dapat ditampilkan'),{'assertNotVisible':'Tampilkan tagihan'}]);check(True,'iOS '+who+': tanpa tombol; deep link penerbitan ditolak')
except BaseException as e:
 bad+=1;print('[gagal] '+str(e),flush=True)
finally:
 sql('UPDATE bendahara_bulanan_assignments SET is_active=1 WHERE id='+penugasan)
 print(f'Ringkasan: {ok} lulus, {bad} gagal',flush=True);sys.exit(1 if bad else 0)
