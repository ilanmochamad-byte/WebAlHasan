import pathlib,subprocess,json,os,sys
ROOT=pathlib.Path('/Users/ilanmochamad/Documents/GitHub/webalhasan/WebAlHasan');OUT=ROOT/'docs/phase-v6-4/audit-ulang-codex';TMP=pathlib.Path('/private/tmp/v6f4-reaudit-maestro');DB='test_v6_f4_reaudit_ios_test';DEVICE='05999342-AE62-44C4-B0E1-1E8504CF8512'
ok=bad=0

def sql(q):return subprocess.check_output(['mysql','-N','-B',DB,'-e',q],text=True).strip()
def check(v,s):
 global ok,bad
 if v:ok+=1
 else:bad+=1
 print(('[lulus] ' if v else '[gagal] ')+s,flush=True)
def flow(name,commands):
 f=TMP/(name+'.yaml');f.write_text('appId: com.ilanmochamad.alhasanApps\n---\n'+json.dumps(commands,ensure_ascii=False,indent=2)+'\n')
 with (OUT/(name+'.txt')).open('w')as log:r=subprocess.run(['maestro','-p','ios','--udid',DEVICE,'test','--test-output-dir',str(TMP/('output-'+name)),str(f)],stdout=log,stderr=subprocess.STDOUT)
 if r.returncode:raise RuntimeError('Alur '+name+' gagal; lihat log/tangkapan')
def wait(s):return {'extendedWaitUntil':{'visible':s,'timeout':15000}}
def tap(s):return {'tapOn':s}

def openurl(path):subprocess.run(['xcrun','simctl','openurl',DEVICE,'alhasanapps://'+path.lstrip('/')],check=True)
def fields(who,expected):
 return [wait('Masukkan username'),tap('Masukkan username'),'eraseText',{'inputText':who},tap('Masuk ke akun Anda'),tap('Masukkan password'),'waitForAnimationToEnd',{'inputText':'Sandbox#123'},{'pressKey':'enter'},wait('Not Now|Keuangan anak|Keuangan|Rincian biaya|Data belum dapat ditampilkan'),{'runFlow':{'when':{'visible':'Not Now'},'commands':[tap('Not Now')]}},wait(expected)]
def login(who):
 # Account changes use token revocation on this isolated fixture, followed by
 # an actual cold start. No UI profile/logout behavior is claimed by this helper.
 sql("UPDATE api_tokens SET revoked_at=NOW() WHERE revoked_at IS NULL")
 subprocess.run(['xcrun','simctl','terminate',DEVICE,'com.ilanmochamad.alhasanApps'],check=True)
 openurl('/keuangan/tagihan/1')
 flow('ios-login-'+who,fields(who,'Keuangan anak|Keuangan|Rincian biaya'))
smp=int(sql("SELECT id FROM santri WHERE nis='V6S0001'"));ts=int(sql("SELECT id FROM santri WHERE nis='V6S0002'"))
try:
 login('sbx_v6_wali_dua');openurl('/keuangan')
 flow('ios-wali-dua',[wait('Keuangan anak'),{'assertVisible':'.*SBX V6 Santri SMP.*'},{'assertVisible':'.*SBX V6 Santri Tsanawi.*'},{'assertNotVisible':'Verifikasi transfer'}])
 check(True,'iOS wali dua anak: dua kartu terpisah, tanpa tindakan petugas')
 openurl(f'/keuangan/santri/{ts}')
 flow('ios-wali-anak-ts',[wait('.*SBX V6 Santri Tsanawi.*'),{'assertNotVisible':'.*SBX V6 Santri SMP.*'},{'assertNotVisible':'Setoran'}])
 check(True,'iOS wali memilih Tsanawi: tidak membawa data SMP atau tombol setoran')
 sql(f"UPDATE santri_wali sw JOIN users u ON u.wali_id=sw.wali_id SET sw.archived_at=NOW() WHERE u.username='sbx_v6_wali_dua' AND sw.santri_id={ts}")
 flow('ios-relasi-dicabut',[{'scrollUntilVisible':{'element':{'text':'Muat ulang'},'direction':'DOWN','timeout':15000}},tap('Muat ulang'),wait('Data belum dapat ditampilkan'),{'assertNotVisible':'.*SBX V6 Santri Tsanawi.*'}])
 check(True,'iOS relasi dicabut pada layar lama: muat ulang menutup data anak itu')
 openurl(f'/keuangan/santri/{smp}')
 flow('ios-sebelum-latar',[wait('.*SBX V6 Santri SMP.*'),{'pressKey':'home'}])
 sql(f"UPDATE santri_wali sw JOIN users u ON u.wali_id=sw.wali_id SET sw.archived_at=NOW() WHERE u.username='sbx_v6_wali_dua' AND sw.santri_id={smp}")
 flow('ios-kembali-latar',[{'launchApp':{'stopApp':False}},wait('Data belum dapat ditampilkan'),{'assertNotVisible':'.*SBX V6 Santri SMP.*'}])
 check(True,'iOS kembali dari latar memeriksa relasi lagi; anak kedua tercabut tidak memakai data lama')
 login('sbx_v6_wali_smp');openurl('/keuangan')
 flow('ios-wali-satu',[wait('Keuangan anak'),{'assertVisible':'.*SBX V6 Santri SMP.*'},{'assertNotVisible':'.*SBX V6 Santri Tsanawi.*'}])
 check(True,'iOS wali satu anak: hanya SMP, tanpa kartu anak lain')
 # A revoked token must re-authenticate and keep only the pointer to the URI.
 sql("UPDATE api_tokens t JOIN users u ON u.id=t.user_id SET t.revoked_at=NOW() WHERE u.username='sbx_v6_wali_smp' AND t.revoked_at IS NULL")
 subprocess.run(['xcrun','simctl','terminate',DEVICE,'com.ilanmochamad.alhasanApps'],check=True);openurl('/keuangan/tagihan/2')
 flow('ios-cold-start-token-kedaluwarsa',[wait('Masukkan username'),{'assertNotVisible':'.*SBX V6 Santri.*'}])
 check(True,'iOS cold start dengan token dicabut → masuk ulang, tanpa data lama')
 flow('ios-cold-start-wali-asing',fields('sbx_v6_wali_smp','Data belum dapat ditampilkan')+[{'assertNotVisible':'.*SBX V6 Santri Tsanawi.*'},{'assertNotVisible':'Rp120[.]000'}])
 check(True,'iOS deep link melewati login wali asing tetap 404/403, tanpa nama atau nominal anak lain')
 login('sbx_admin');openurl(f'/keuangan/santri/{smp}')
 flow('ios-admin-baca',[wait('.*SBX V6 Santri SMP.*'),{'assertNotVisible':'Setoran'},{'assertNotVisible':'Penarikan/belanja'}])
 check(True,'iOS admin mengawasi santri melalui data server, tanpa tombol mutasi')
except BaseException as e:
 bad+=1;print('[gagal] '+str(e),flush=True)
finally:
 sql("UPDATE santri_wali sw JOIN users u ON u.wali_id=sw.wali_id SET sw.archived_at=NULL WHERE u.username='sbx_v6_wali_dua'")
 print(f'Ringkasan: {ok} lulus, {bad} gagal',flush=True);sys.exit(1 if bad else 0)
