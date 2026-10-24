"""Uji Android emulator PRD V5 Fase 4 — sesi kedaluwarsa, tautan wali (tanpa sesi/latar/depan), relasi dicabut, akun lain, data aplikasi dan logcat. Jalankan sesudah v5_fase4_a.py dengan penanda yang sama."""
import os, subprocess, time, sys
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from adbui import *
WEB=os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..'))
PKG='com.ilanmochamad.alhasanApps'
def sql(q): return subprocess.run(['php', WEB+'/tests/v5_phase4_sql.php', q], capture_output=True, text=True, env={'DB_NAME':'test_v5_f4_app_test','PATH':'/opt/homebrew/bin:/usr/bin:/bin'}).stdout.strip()
T = sys.argv[1]
OUT = os.environ.get('OUT_DIR', '/tmp/v5f4-android')
kid, rid = open(f'{OUT}/ids.txt').read().split()
hasil=[]
def ok(v,l): print(('[lulus] ' if v else '[gagal] ')+l, flush=True); hasil.append(v)
def masuk(u):
    isi('Masukkan username', u); isi('Masukkan password', 'Sandbox#123'); tap('Masuk', exact=True); time.sleep(5)
def latar(): sh('shell', 'input', 'keyevent', 'KEYCODE_HOME'); time.sleep(2)
def depan(): sh('shell', 'monkey', '-p', PKG, '-c', 'android.intent.category.LAUNCHER', '1'); time.sleep(5)
def tautan(r): sh('shell', 'am', 'start', '-a', 'android.intent.action.VIEW', '-d', f'alhasanapps://kesehatan/wali/{r}', PKG); time.sleep(6)
def ada(t): return t in teks_layar()
TEKS = f'{T}-RINGKASAN-wali'

# 1. Sesi kedaluwarsa saat aplikasi di latar → kembali ke depan meminta masuk.
ok(ada('Ringkasan untuk wali'), 'Petugas masih pada layar ringkasan sebelum uji sesi')
sql("UPDATE api_tokens t JOIN users u ON u.id=t.user_id SET t.expires_at=NOW() - INTERVAL 1 MINUTE WHERE u.username='sbx_v5_petugas_f'")
latar()
sh('shell', 'input', 'keyevent', 'KEYCODE_APP_SWITCH'); time.sleep(2)
layar(f'{OUT}/android-recents-petugas.png')
sh('shell', 'input', 'keyevent', 'KEYCODE_HOME'); time.sleep(1)
depan()
wait('Masukkan username', timeout=30)
ok(not ada(T), 'Token kedaluwarsa → kembali ke depan menampilkan layar masuk tanpa isi kesehatan')

# 2. Tautan ringkasan saat belum masuk → masuk → ringkasan terbuka (penunjuk bertahan).
tautan(rid)
ok(ada('Masukkan username') and not ada(TEKS), 'Tautan ringkasan tanpa sesi → tetap layar masuk, tanpa isi')
masuk('sbx_v5_wali_1')
wait(TEKS, timeout=30)
ok(True, 'Sesudah masuk sebagai wali berhak, ringkasan dari tautan terbuka (server memeriksa relasi)')
layar(f'{OUT}/android-wali-ringkasan.png')
ok(not ada(f'{T}-KELUHAN') and not ada(f'{T}-CATATAN') and not ada(f'{T}-OBAT1'), 'Layar wali tanpa keluhan/catatan klinis/obat')

# 3. Tautan saat aplikasi di latar → kembali ke ringkasan, satu layar.
latar(); tautan(rid)
wait(TEKS, timeout=20)
sh('shell', 'input', 'keyevent', '4'); time.sleep(2)
ok(not ada(TEKS), 'Tautan dari latar tidak menumpuk dua layar ringkasan yang sama (Kembali keluar dari ringkasan)')

# 4. Tautan saat aplikasi di depan (Beranda) → ringkasan.
tautan(rid)
wait(TEKS, timeout=20)
ok(True, 'Tautan saat aplikasi di depan membuka ringkasan')

# 5. Relasi dicabut → kembali dari latar memuat ulang → 403 tanpa isi.
wid = sql("SELECT wali_id FROM users WHERE username='sbx_v5_wali_1'")
sid = sql(f"SELECT santri_id FROM kesehatan_ringkasan WHERE id={rid}")
sql(f"UPDATE santri_wali SET archived_at=NOW() WHERE wali_id={wid} AND santri_id={sid}")
try:
    latar(); depan()
    wait('Data belum dapat ditampilkan', timeout=25)
    ok(not ada(TEKS), 'Relasi dicabut → ringkasan ditolak sesudah kembali dari latar, isi lama hilang')
    layar(f'{OUT}/android-wali-dicabut.png')
finally:
    sql(f"UPDATE santri_wali SET archived_at=NULL WHERE wali_id={wid} AND santri_id={sid}")

# 6. Akun lain dengan tautan yang sama → 403.
sql("UPDATE api_tokens t JOIN users u ON u.id=t.user_id SET t.expires_at=NOW() - INTERVAL 1 MINUTE WHERE u.username='sbx_v5_wali_1'")
latar(); depan()
wait('Masukkan username', timeout=30)
tautan(rid)
masuk('sbx_v5_wali_lain')
wait('Data belum dapat ditampilkan', timeout=30)
ok(not ada(TEKS), 'Wali lain membawa tautan yang sama → ditolak tanpa isi')
layar(f'{OUT}/android-wali-lain.png')

# 7. Penyimpanan perangkat dan log.
berkas = subprocess.run(['adb','shell','run-as',PKG,'sh','-c','find . -type f 2>/dev/null | head -500'], capture_output=True, text=True).stdout.split()
temuan = []
for f in berkas:
    isi_b = subprocess.run(['adb','exec-out','run-as',PKG,'cat',f], capture_output=True).stdout
    for kata in [T.encode(), b'SBX V5 Wali Santri']:
        if kata in isi_b: temuan.append((f, kata.decode()))
ok(len(berkas) > 0 and temuan == [], f'Data aplikasi di perangkat ({len(berkas)} berkas) tanpa isi kesehatan/nama santri: {temuan[:5]}')
log = subprocess.run(['adb','logcat','-d'], capture_output=True, text=True, errors='replace').stdout
# Baris AccessibilityNodeInfoDumper berasal dari alat uji uiautomator (membaca layar), bukan dari aplikasi.
log = '\n'.join(l for l in log.splitlines() if 'AccessibilityNodeInfoDumper' not in l)
ok(sum('ReactNativeJS' in l for l in log.splitlines()) >= 0, f"Baris log JS aplikasi (ReactNativeJS): {sum('ReactNativeJS' in l for l in log.splitlines())}")
tok = sql("SELECT COUNT(*) FROM api_tokens")
ok(T not in log and 'SBX V5 Wali Santri' not in log and 'Sandbox#123' not in log, f'logcat ({len(log.splitlines())} baris) tanpa isi kesehatan, nama santri, kata sandi')
ok('Bearer ' not in log and 'ExponentPushToken' not in log, 'logcat tanpa token bearer/token push')
print('GAGAL', hasil.count(False))
