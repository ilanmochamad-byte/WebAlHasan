"""Independent native role/revocation/privacy probe. Local synthetic DB/API only.

DB_NAME=test_*_test V5_WEB=http://127.0.0.1:8982 OUT_DIR=/tmp/... python3 tests/android/v5_fase4_audit.py
Requires rebuilt Android dev client connected to Metro on 8099.
"""
import json, os, re, subprocess, time, urllib.request
from adbui import *

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))
DB = os.environ['DB_NAME']; WEB = os.environ['V5_WEB']
if not re.fullmatch(r'test_.*_test', DB) or not re.fullmatch(r'http://127\.0\.0\.1:\d+', WEB): raise RuntimeError('Only local synthetic fixtures')
PKG = 'com.ilanmochamad.alhasanApps'
OUT = os.environ.get('OUT_DIR', '/tmp/codex-v5f4-native'); os.makedirs(OUT, exist_ok=True)
def sql(q): return subprocess.run(['php', ROOT + '/tests/v5_phase4_sql.php', q], check=True, capture_output=True, text=True).stdout.strip()
results = []
def ok(v, label):
    results.append(bool(v)); print(('[lulus] ' if v else '[gagal] ') + label, flush=True)
def request(path, body, token=None):
    headers = {'Content-Type':'application/json'}
    if token: headers['Authorization'] = 'Bearer ' + token
    req = urllib.request.Request(WEB + '/api/v1' + path, data=json.dumps(body).encode(), headers=headers)
    with urllib.request.urlopen(req) as r: return json.load(r)['data']
token = request('/auth/login', {'username':'sbx_v5_petugas_d','password':'Sandbox#123','device_name':'codex-role-probe'})['token']
sid = int(sql("SELECT id FROM santri WHERE nis='V5S0101'"))
marker = 'CODEXNATIVE-' + str(int(time.time()))
kid = request('/v5/kesehatan/kunjungan', {'santri_id':sid,'waktu_kunjungan':time.strftime('%Y-%m-%dT08:00'),'keluhan':marker,'catatan_klinis':marker+'-PRIVATE','idempotency_key':marker}, token)['id']
def start(user):
    # Force reauthentication; no plaintext token is printed or placed in a URI.
    sql("UPDATE api_tokens SET expires_at=NOW()-INTERVAL 1 MINUTE WHERE name<>'codex-role-probe'")
    sh('shell','am','force-stop',PKG)
    sh('shell','am','start','-a','android.intent.action.VIEW','-d','alhasanapps://expo-development-client/?url=http%3A%2F%2F127.0.0.1%3A8099',PKG)
    wait('Masukkan username', timeout=60)
    isi('Masukkan username',user); isi('Masukkan password','Sandbox#123'); tap('Masuk',exact=True)
    wait('Beranda', timeout=40)
    sh('shell','am','start','-a','android.intent.action.VIEW','-d',f'alhasanapps://kesehatan/kunjungan/{kid}',PKG)
def background(): sh('shell','input','keyevent','KEYCODE_HOME'); time.sleep(1)
def foreground(): sh('shell','monkey','-p',PKG,'-c','android.intent.category.LAUNCHER','1'); time.sleep(3)
for user in ['sbx_admin','sbx_v5_petugas_d','sbx_v5_pembimbing','sbx_v5_murobi','sbx_v5_wali_1']:
    start(user)
    if user == 'sbx_v5_wali_1':
        wait('Data belum dapat ditampilkan', timeout=30)
        ok(marker not in teks_layar(),'Native wali: clinical URI denied without old data')
        continue
    wait(marker,timeout=30)
    ok(True,user+': native internal detail matches API fixture')
    text=teks_layar()
    # Buttons can be below the fold; query the server-proven detail then scroll.
    if user == 'sbx_admin':
        tap('Riwayat akses',exact=True); wait('kanal api',timeout=30)
        ok(marker not in teks_layar(),'Native admin: access history without clinical contents')
    elif user == 'sbx_v5_petugas_d':
        tap('Koreksi catatan',exact=True); wait('Keluhan (wajib)')
        flags=sh('shell','dumpsys','window','windows')
        ok('SECURE' in flags,'Android health window has native SECURE flag')
        background(); sh('shell','input','keyevent','KEYCODE_APP_SWITCH'); time.sleep(2)
        layar(OUT+'/recents-protected.png')
        ok(marker not in teks_layar(),'Android recent-app card contains no clinical marker')
        sh('shell','input','keyevent','KEYCODE_HOME'); foreground()
    else:
        table, key = ('pembimbing_assignments','pengurus_id') if user.endswith('pembimbing') else ('murobi_assignments','guru_id')
        subject=int(sql(f"SELECT {key} FROM users WHERE username='{user}'"))
        sql(f'UPDATE {table} SET is_active=0 WHERE {key}={subject}')
        try:
            background(); foreground(); wait('Data belum dapat ditampilkan',timeout=30)
            ok(marker not in teks_layar(),user+': native revocation removes detail on resume')
        finally: sql(f'UPDATE {table} SET is_active=1 WHERE {key}={subject}')
print('Hasil:',sum(results),'lulus,',len(results)-sum(results),'gagal',flush=True)
raise SystemExit(0 if all(results) else 1)
