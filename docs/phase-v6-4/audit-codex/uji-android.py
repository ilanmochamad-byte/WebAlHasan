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
try:
 deep('/keuangan');wait('Masuk ke akun Anda')
 u.typ('Masukkan username','sbx_v6_bsa_b');u.typ('Masukkan password','Sandbox#123');u.tap('Masuk',exact=True);wait('Keuangan')
 s=int(sql("SELECT id FROM santri WHERE nis='V6S0001'"));ts=int(sql("SELECT id FROM santri WHERE nis='V6S0002'"))
 auth=api('/auth/login',{'username':'sbx_v6_bsa_b','password':'Sandbox#123','device_name':'audit-native'});token=auth['token']
 deep(f'/keuangan/transaksi?santri_id={s}&mode=setoran');wait('Saldo saat ini')
 typ('Nominal (rupiah)','100000');tap('Catat setoran');wait('tercatat sah')
 check(sql(f'SELECT saldo FROM bsa_tabungan_akun WHERE santri_id={s}')=='100000' and sql('SELECT COUNT(*) FROM bsa_tabungan_transaksi')=='1','Android dev client: setoran Rp100.000 → satu kredit dan saldo sesuai server')
 # A second tap while still in flight is attempted via uiautomator coordinate reuse.
 deep(f'/keuangan/santri/{s}');wait('Riwayat tabungan');tap('Penarikan/belanja');wait('Penarikan atau belanja')
 tap('Belanja');typ('Nominal (rupiah)','25000');typ('Tujuan (wajib)','Kitab auditor');
 n=u.scroll_to('Catat belanja',exact=True);x,y=u.center(n)
 for _ in range(2):u.sh('shell','input','tap',str(x),str(y))
 wait('tercatat sah')
 check(sql(f'SELECT saldo FROM bsa_tabungan_akun WHERE santri_id={s}')=='75000' and sql("SELECT COUNT(*) FROM bsa_tabungan_transaksi WHERE jenis='Belanja'")=='1','Android: dua ketukan belanja Rp25.000 → satu debit; saldo Rp75.000')
 bill=api('/v6/bsa/tagihan',{'idempotency_key':'android-audit-bill','santri_id':s,'periode':'2026-10','jatuh_tempo':'2026-10-31','komponen':[{'nama':'Syahriah','nominal':100000}]},token);tid=bill['id']
 deep(f'/keuangan/tagihan/{tid}');wait('Rincian biaya');tap('Bayar dari tabungan');wait('Saldo tabungan Rp75.000')
 typ('Nominal dipotong dari tabungan','50000');tap('Bayar dari tabungan');wait('Centang persetujuan')
 check(sql('SELECT COUNT(*) FROM bsa_pembayaran')=='0','Android: pembayaran tabungan tanpa persetujuan tidak terkirim')
 tap('Saya menyetujui tabungan santri ini dipotong untuk membayar tagihan ini.');tap('Bayar dari tabungan');wait('Dari tabungan santri')
 check(sql(f'SELECT saldo FROM bsa_tabungan_akun WHERE santri_id={s}')=='25000' and sql(f'SELECT terbayar FROM bsa_tagihan WHERE id={tid}')=='50000' and sql("SELECT COUNT(*) FROM bsa_pembayaran WHERE sumber='Tabungan'")=='1','Android: bayar Rp50.000 → satu pembayaran/debit; saldo Rp25.000, sisa Rp50.000')
 check('Debit tabungan' in text(),'Android: kuitansi memuat rujukan silang debit tabungan')
 deep(f'/keuangan/transaksi?santri_id={s}&mode=setoran');wait('Saldo saat ini');typ('Nominal (rupiah)','5000')
 task=sql("SELECT b.id FROM bendahara_bulanan_assignments b JOIN users u ON u.pengurus_id=b.pengurus_id WHERE u.username='sbx_v6_bsa_b'")
 sql(f'UPDATE bendahara_bulanan_assignments SET is_active=0 WHERE id={task}');tap('Catat setoran');wait('Informasi tidak dapat diakses')
 check('SBX V6 Santri SMP' not in text() and 'Nominal (rupiah)' not in text() and sql(f'SELECT saldo FROM bsa_tabungan_akun WHERE santri_id={s}')=='25000','Android: POST 403 pada formulir lama menutup data/draf; saldo tidak berubah')
 sql(f'UPDATE bendahara_bulanan_assignments SET is_active=1 WHERE id={task}')
 # Stay within approved fixture. Clear the token by logging out using the normal profile flow.
 deep('/profil');wait('Keluar dari aplikasi');tap('Keluar dari aplikasi');wait('Keluar dari aplikasi?');tap('KELUAR');wait('Masuk ke akun Anda')
 u.typ('Masukkan username','sbx_v6_wali_dua');u.typ('Masukkan password','Sandbox#123');u.tap('Masuk',exact=True);wait('Assalamu')
 tap('Keuangan anak');wait('Keuangan anak');t=text()
 check('SBX V6 Santri SMP' in t and 'SBX V6 Santri Tsanawi' in t,'Android wali dua anak: dua kartu data terpisah')
 deep(f'/keuangan/santri/{ts}');wait('Riwayat tabungan');t=text();check('SBX V6 Santri SMP' not in t and 'Setoran' not in t,'Android wali: rincian Tsanawi tanpa data SMP/tombol mutasi')
 sql(f"UPDATE santri_wali sw JOIN users u ON u.wali_id=sw.wali_id SET sw.archived_at=NOW() WHERE u.username='sbx_v6_wali_dua' AND sw.santri_id={ts}")
 tap('Muat ulang');wait('Data belum dapat ditampilkan');check('SBX V6 Santri Tsanawi' not in text(),'Android: relasi dicabut saat layar lama terbuka → ditolak tanpa data')
 # Background/foreground revalidation of the remaining child.
 deep(f'/keuangan/santri/{s}');wait('Riwayat tabungan');u.sh('shell','input','keyevent','3');time.sleep(1)
 flags=u.sh('shell','dumpsys','window','windows');check('SECURE' in flags,'Android: jendela keuangan tetap ber-SECURE saat ke latar')
 sql(f"UPDATE santri_wali sw JOIN users u ON u.wali_id=sw.wali_id SET sw.archived_at=NOW() WHERE u.username='sbx_v6_wali_dua' AND sw.santri_id={s}")
 u.sh('shell','am','start','-n',PKG+'/.MainActivity');wait('Data belum dapat ditampilkan');check('SBX V6 Santri SMP' not in text(),'Android: kembali dari latar meminta server; relasi tercabut tidak memakai data lama')
except BaseException as e:
 failed+=1;print('[gagal] '+str(e),flush=True)
finally:
 sql("UPDATE bendahara_bulanan_assignments b JOIN users u ON u.pengurus_id=b.pengurus_id SET b.is_active=1 WHERE u.username='sbx_v6_bsa_b'")
 print(f'Ringkasan: {passed} lulus, {failed} gagal',flush=True)
 sys.exit(1 if failed else 0)
