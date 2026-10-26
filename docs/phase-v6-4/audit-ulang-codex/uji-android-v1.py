import sys,subprocess,time,shlex
sys.path.insert(0,'/private/tmp/v6f4');import adbui as u
DB='test_v6_f4_reaudit_app_test';PKG='com.ilanmochamad.alhasanApps'
def sql(q):return subprocess.check_output(['mysql','-N','-B',DB,'-e',q],text=True).strip()
def wait(s):
 if u.find(s,timeout=35) is None:raise RuntimeError('Tidak ditemukan: '+s)
def text():return u.text_all()
ok=bad=0
def check(v,s):
 global ok,bad
 if v:ok+=1
 else:bad+=1
 print(('[lulus] ' if v else '[gagal] ')+s,flush=True)
task=sql("SELECT b.id FROM bendahara_bulanan_assignments b JOIN users u ON u.pengurus_id=b.pengurus_id WHERE u.username='sbx_v6_bsa_b'")
try:
 if u.find('Continue',exact=True,timeout=2) is not None:u.tap('Continue',exact=True)

 if u.find('Close',exact=True,timeout=3) is not None:u.tap('Close',exact=True)
 wait('Masuk ke akun Anda');u.typ('Masukkan username','sbx_v6_bsa_b');u.typ('Masukkan password','Sandbox#123');u.tap('Masuk',exact=True);wait('Keuangan')
 s=sql("SELECT id FROM santri WHERE nis='V6S0001'")
 url='alhasanapps://keuangan/transaksi?santri_id='+s+'&mode=setoran'
 u.sh('shell','am','start','-a','android.intent.action.VIEW','-d',shlex.quote(url),PKG);wait('Saldo saat ini');u.typ('Nominal (rupiah)','5000')
 before=sql('SELECT COUNT(*) FROM bsa_tabungan_transaksi')
 sql(f'UPDATE bendahara_bulanan_assignments SET is_active=0 WHERE id={task}');u.tap('Catat setoran',exact=True);wait('Data belum dapat ditampilkan')
 check('SBX V6 Santri SMP' not in text() and 'Nominal (rupiah)' not in text(),'Android 0097f91: POST ditolak menutup data/draf (F-1)')
 sql(f'UPDATE bendahara_bulanan_assignments SET is_active=1 WHERE id={task}');u.tap('Coba lagi',exact=True);wait('Saldo saat ini');t=text()
 check('Informasi tidak dapat diakses' not in t and '5000' not in t,'Android 0097f91: hak dipulihkan + Coba lagi → tanpa pesan basi dan draf lama (V-1)')
 check(sql('SELECT COUNT(*) FROM bsa_tabungan_transaksi')==before,'Android: seluruh penolakan/pemulihan tidak memutasi uang')
except BaseException as e:
 bad+=1;print('[gagal] '+str(e),flush=True)
finally:
 sql(f'UPDATE bendahara_bulanan_assignments SET is_active=1 WHERE id={task}')
 print(f'Ringkasan: {ok} lulus, {bad} gagal',flush=True)
 sys.exit(1 if bad else 0)
