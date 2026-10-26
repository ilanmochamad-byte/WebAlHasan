# Fixture test_v6_f5_ios_test, API lokal :8990 dan build Release simulator sesuai manifest-dan-build.json wajib disiapkan dahulu.
import pathlib,subprocess,json,sys
ROOT=pathlib.Path(__file__).resolve().parents[3];OUT=ROOT/'docs/phase-v6-5/audit-codex';TMP=pathlib.Path('/private/tmp/v6f5-codex-native');TMP.mkdir(exist_ok=True)
DB='test_v6_f5_ios_test';D='05999342-AE62-44C4-B0E1-1E8504CF8512';APP='com.ilanmochamad.alhasanApps';ok=bad=0

def sql(q):return subprocess.check_output(['mysql','-N','-B',DB,'-e',q],text=True).strip()
def check(v,s):
 global ok,bad
 ok+=bool(v);bad+=not bool(v);print(('[lulus] ' if v else '[gagal] ')+s,flush=True)
def flow(n,cmd):
 f=OUT/'maestro'/(n+'.yaml');f.write_text('appId: '+APP+'\n---\n'+json.dumps(cmd,ensure_ascii=False,indent=2))
 with (OUT/(n+'.txt')).open('w') as log:r=subprocess.run(['maestro','-p','ios','--udid',D,'test','--test-output-dir',str(TMP/n),str(f)],stdout=log,stderr=subprocess.STDOUT)
 if r.returncode:raise RuntimeError(n+' gagal')
def wait(s):return {'extendedWaitUntil':{'visible':s,'timeout':15000}}
def tap(s):return {'tapOn':s}
def url():subprocess.run(['xcrun','simctl','openurl',D,'alhasanapps://keuangan/laporan'],check=True)
def login(who):
 sql('UPDATE api_tokens SET revoked_at=NOW() WHERE revoked_at IS NULL');subprocess.run(['xcrun','simctl','terminate',D,APP],check=True);url()
 flow('ios-login-'+who,[wait('Masukkan username'),tap('Masukkan username'),'eraseText',{'inputText':who},tap('Masuk ke akun Anda'),tap('Masukkan password'),'waitForAnimationToEnd',{'inputText':'Sandbox#123'},{'pressKey':'enter'},wait('Not Now|Keuangan anak|Keuangan|Saldo akhir'),{'runFlow':{'when':{'visible':'Not Now'},'commands':[tap('Not Now')]}},wait('Keuangan anak|Keuangan|Saldo akhir')])
 url()
 flow('ios-tujuan-'+who,[wait('Saldo akhir')])
try:
 login('sbx_admin')
 before=sql('SELECT CONCAT_WS(":",(SELECT COUNT(*) FROM bsa_tabungan_transaksi),(SELECT COUNT(*) FROM bsa_pembayaran),(SELECT SUM(saldo) FROM bsa_tabungan_akun))')
 flow('ios-laporan-admin',[wait('Saldo akhir'),{'assertVisible':r'Rp62\.000'},{'assertVisible':r'Rp100\.000'},{'assertVisible':r'Rp132\.000'},{'assertVisible':'Rekonsiliasi: selisih Rp0'}, {'assertNotVisible':'Catat setoran'}])
 check(True,'iOS Release admin: tagihan Rp100.000, saldo Rp62.000, kas Rp132.000, rekonsiliasi Rp0; hanya baca')
 flow('ios-laporan-admin-nonaktif',[{'scrollUntilVisible':{'element':{'text':'Santri nonaktif masih bersaldo/bertunggakan'},'direction':'DOWN','timeout':20000}},{'assertVisible':'.*SBX V6 Santri Nonaktif.*'}])
 check(True,'iOS admin: R-10 santri nonaktif tampil dalam laporan')
 login('sbx_v6_bsa_b')
 flow('ios-laporan-bsa',[wait('Saldo akhir'),{'assertVisible':r'Rp50\.000'},{'assertVisible':r'Rp120\.000'},{'assertVisible':'Rekonsiliasi: selisih Rp0'}])
 check(True,'iOS BSA: saldo Rp50.000 dan kas Rp120.000 hanya santri aktif dalam hak')
 flow('ios-bsa-latar',[{'pressKey':'home'}])
 sql("UPDATE bendahara_bulanan_assignments a JOIN users u ON u.pengurus_id=a.pengurus_id SET a.is_active=0 WHERE u.username='sbx_v6_bsa_b'")
 flow('ios-bsa-dicabut',[{'launchApp':{'stopApp':False}},wait('Data belum dapat ditampilkan'),{'assertNotVisible':r'Rp50\.000'},{'assertNotVisible':'Saldo akhir'}])
 check(True,'iOS laporan BSA lama ditutup setelah penugasan dicabut dan kembali dari latar')
 sql("UPDATE bendahara_bulanan_assignments a JOIN users u ON u.pengurus_id=a.pengurus_id SET a.is_active=1 WHERE u.username='sbx_v6_bsa_b'")
 login('sbx_v6_wali_dua')
 flow('ios-laporan-wali',[wait('Saldo akhir'),{'assertVisible':r'Rp50\.000'},{'assertNotVisible':'Penerimaan kas baru'},{'assertNotVisible':'Rekonsiliasi: selisih Rp0'}, {'scrollUntilVisible':{'element':{'text':'.*SBX V6 Santri Tsanawi.*'},'direction':'DOWN','timeout':20000}}, {'assertVisible':'.*SBX V6 Santri SMP.*'},{'assertNotVisible':'.*SBX V6 Santri Nonaktif.*'}])
 check(True,'iOS wali dua anak: dua anak sendiri, saldo Rp50.000, tanpa kas/rekonsiliasi internal/santri keluar')
 flow('ios-wali-latar',[{'pressKey':'home'}])
 sql("UPDATE santri_wali sw JOIN users u ON u.wali_id=sw.wali_id JOIN santri s ON s.id=sw.santri_id SET sw.archived_at=NOW() WHERE u.username='sbx_v6_wali_dua' AND s.nis='V6S0001'")
 flow('ios-wali-dicabut',[{'launchApp':{'stopApp':False}},wait('Saldo akhir'),{'assertNotVisible':r'Rp50\.000'},{'scrollUntilVisible':{'element':{'text':'.*SBX V6 Santri Tsanawi.*'},'direction':'DOWN','timeout':20000}},{'assertNotVisible':'.*SBX V6 Santri SMP.*'},{'assertNotVisible':r'Rp50\.000'}])
 check(True,'iOS layar laporan wali lama: relasi SMP dicabut menyisakan Tsanawi setelah kembali dari latar')
 after=sql('SELECT CONCAT_WS(":",(SELECT COUNT(*) FROM bsa_tabungan_transaksi),(SELECT COUNT(*) FROM bsa_pembayaran),(SELECT SUM(saldo) FROM bsa_tabungan_akun))')
 check(before==after,'Laporan native tidak mengubah ledger, pembayaran, atau saldo')
except BaseException as e:
 bad+=1;print('[gagal] '+str(e),flush=True)
finally:
 sql("UPDATE bendahara_bulanan_assignments a JOIN users u ON u.pengurus_id=a.pengurus_id SET a.is_active=1 WHERE u.username='sbx_v6_bsa_b'")
 sql("UPDATE santri_wali sw JOIN users u ON u.wali_id=sw.wali_id SET sw.archived_at=NULL WHERE u.username='sbx_v6_wali_dua'")
 print(f'Ringkasan: {ok} lulus, {bad} gagal',flush=True);sys.exit(1 if bad else 0)
