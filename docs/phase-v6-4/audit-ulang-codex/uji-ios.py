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
smp=int(sql("SELECT id FROM santri WHERE nis='V6S0001'"));task=sql("SELECT b.id FROM bendahara_bulanan_assignments b JOIN users u ON u.pengurus_id=b.pengurus_id WHERE u.username='sbx_v6_bsa_b'")
try:
 baseline=int(sql('SELECT COUNT(*) FROM bsa_idempotensi'))
 flow('ios-setoran',[
  wait(r'Saldo saat ini Rp0'),{'tapOn':{'point':'201,397'}}, {'inputText':'100000'},tap('Setoran tabungan'),
  {'tapOn':{'text':'Catat setoran','repeat':2,'delay':50}},wait(r'Saldo saat ini Rp100\.000'),{'assertVisible':r'Saldo saat ini Rp100\.000'}])
 check(sql(f'SELECT saldo FROM bsa_tabungan_akun WHERE santri_id={smp}')=='100000' and sql('SELECT COUNT(*) FROM bsa_tabungan_transaksi')=='1' and int(sql('SELECT COUNT(*) FROM bsa_idempotensi'))==baseline+1,'iOS Release: dua ketukan setoran → satu ledger/klaim; saldo Rp100.000')
 check(sql("SELECT COUNT(*) FROM notifikasi_outbox WHERE event_type='v6_bsa_tabungan' AND kanal='InApp'")=='2','iOS setoran → tepat dua pemberitahuan InApp wali aktif')
 flow('ios-draf', [{'tapOn':{'point':'201,397'}},{'inputText':'5000'},tap('Setoran tabungan')])
 sql(f'UPDATE bendahara_bulanan_assignments SET is_active=0 WHERE id={task}')
 flow('ios-f1-ditolak',[tap('Catat setoran'),wait('Data belum dapat ditampilkan'),{'assertNotVisible':'.*SBX V6 Santri SMP.*'},{'assertNotVisible':r'Nominal \(rupiah\)'}])
 check(sql(f'SELECT saldo FROM bsa_tabungan_akun WHERE santri_id={smp}')=='100000' and sql('SELECT COUNT(*) FROM bsa_tabungan_transaksi')=='1','iOS F-1: POST 403 pada formulir lama menutup data/draf tanpa mutasi')
 sql(f'UPDATE bendahara_bulanan_assignments SET is_active=1 WHERE id={task}')
 flow('ios-v1-dipulihkan',[tap('Coba lagi'),wait(r'Saldo saat ini Rp100\.000'),{'assertNotVisible':'.*Informasi tidak dapat diakses.*'},{'assertNotVisible':'5000'}])
 check(True,'iOS V-1: Coba lagi sesudah akses dipulihkan → formulir bersih, tanpa pesan penolakan lama')
 # Terminate process and open a resource URI in embedded Release, no dev launcher.
 subprocess.run(['xcrun','simctl','terminate',DEVICE,'com.ilanmochamad.alhasanApps'],check=True)
 subprocess.run(['xcrun','simctl','openurl',DEVICE,'alhasanapps://keuangan/tagihan/1'],check=True)
 flow('ios-cold-start-berhak',[wait('Rincian biaya'),{'assertVisible':'.*SBX V6 Santri SMP.*'},{'assertVisible':r'Rp100\.000'},{'assertNotVisible':'Development Build'}])
 check(True,'iOS Release cold start dari URI, token tersimpan: langsung tagihan anak yang berhak, tanpa Metro/dev launcher')
 sql(f'UPDATE bendahara_bulanan_assignments SET is_active=0 WHERE id={task}')
 subprocess.run(['xcrun','simctl','terminate',DEVICE,'com.ilanmochamad.alhasanApps'],check=True)
 subprocess.run(['xcrun','simctl','openurl',DEVICE,'alhasanapps://keuangan/tagihan/1'],check=True)
 flow('ios-cold-start-dicabut',[wait('Data belum dapat ditampilkan'),{'assertNotVisible':'.*SBX V6 Santri SMP.*'},{'assertNotVisible':r'Rp100\.000'}])
 check(True,'iOS Release cold start dari URI setelah penugasan dicabut: token lama ditolak tanpa nama/nominal')
 sql(f'UPDATE bendahara_bulanan_assignments SET is_active=1 WHERE id={task}')
 flow('ios-pulih-lagi',[tap('Coba lagi'),wait('Rincian biaya')])
except BaseException as e:
 bad+=1;print('[gagal] '+str(e),flush=True)
finally:
 sql(f'UPDATE bendahara_bulanan_assignments SET is_active=1 WHERE id={task}')
 print(f'Ringkasan: {ok} lulus, {bad} gagal',flush=True)
 sys.exit(1 if bad else 0)
