"""Audit laporan Android dev client yang sudah dibangun Fase 4; bukan build rilis/fisik."""
import json, os, re, subprocess, time, urllib.request
from adbui import *

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))
DB, WEB = os.environ['DB_NAME'], os.environ['V5_WEB']
PORT = int(os.environ['V5_METRO_PORT'])
if not re.fullmatch(r'test_.*_test', DB) or not re.fullmatch(r'http://127\.0\.0\.1:\d+', WEB):
    raise RuntimeError('Hanya fixture localhost')
PKG = 'com.ilanmochamad.alhasanApps'
results = []
def ok(v, label):
    results.append(bool(v)); print(('[lulus] ' if v else '[gagal] ') + label, flush=True)
def sql(q):
    return subprocess.run(['php', ROOT+'/tests/v5_phase4_sql.php', q], check=True, capture_output=True, text=True).stdout.strip()
def api(user):
    req = urllib.request.Request(WEB+'/api/v1/auth/login', data=json.dumps({'username':user, 'password':'Sandbox#123','device_name':'codex-f5-native-probe'}).encode(), headers={'Content-Type':'application/json'})
    with urllib.request.urlopen(req) as r: token = json.load(r)['data']['token']
    req = urllib.request.Request(WEB+'/api/v1/v5/kesehatan/laporan?dari=2026-09-01&sampai=2026-09-30', headers={'Authorization':'Bearer '+token})
    with urllib.request.urlopen(req) as r: return json.load(r)['data']
def start(user):
    sql("UPDATE api_tokens SET expires_at=NOW()-INTERVAL 1 MINUTE WHERE name<>'codex-f5-native-probe'")
    sh('shell','am','force-stop',PKG)
    sh('shell','am','start','-a','android.intent.action.VIEW','-d',f'alhasanapps://expo-development-client/?url=http%3A%2F%2F127.0.0.1%3A{PORT}',PKG)
    wait('Masukkan username',timeout=60)
    isi('Masukkan username',user); isi('Masukkan password','Sandbox#123'); tap('Masuk',exact=True)
    wait('Beranda',timeout=40)
    sh('shell','am','start','-a','android.intent.action.VIEW','-d','alhasanapps://kesehatan/laporan',PKG)
def all_text():
    chunks = []
    for _ in range(9):
        chunks.append(teks_layar())
        sh('shell','input','swipe','540','1800','540','650','250')
        time.sleep(.2)
    return re.sub(r'\s+', ' ', ' '.join(chunks).replace('|',' '))
for user in ['sbx_admin','sbx_v5_petugas_f','sbx_v5_pembimbing','sbx_v5_murobi']:
    harap = api(user)
    start(user); wait('Bulan lalu'); tap('Bulan lalu',exact=True); wait('September 2026'); time.sleep(1)
    text = all_text()
    k = harap['kunjungan']
    ok(f"{k['total']} ({k['santri']} santri)" in text and f"{k['dengan_obat']} kunjungan · {harap['obat']['pemberian']} pemberian" in text,
       user+': angka kunjungan/obat native = API September')
    ok(('Pengawasan admin' in text) == (user=='sbx_admin') and ('V5F5RHS' not in text), user+': pengawasan sesuai hak, tanpa isi klinis')
    ok('SECURE' in sh('shell','dumpsys','window','windows'), user+': FLAG_SECURE aktif pada laporan')
    if user=='sbx_v5_pembimbing':
        pid = int(sql("SELECT pengurus_id FROM users WHERE username='sbx_v5_pembimbing'"))
        sql(f'UPDATE pembimbing_assignments SET is_active=0 WHERE pengurus_id={pid}')
        try:
            sh('shell','input','keyevent','KEYCODE_HOME'); time.sleep(1)
            sh('shell','monkey','-p',PKG,'-c','android.intent.category.LAUNCHER','1')
            wait('Data belum dapat ditampilkan',timeout=30)
            ok('SBX V5 Lapor' not in teks_layar(), 'Pembimbing dicabut: laporan hilang setelah aktif kembali')
        finally:
            sql(f'UPDATE pembimbing_assignments SET is_active=1 WHERE pengurus_id={pid}')
for user in ['sbx_v5_wali_1','sbx_pengurus_biasa']:
    start(user); wait('Data belum dapat ditampilkan',timeout=30)
    ok('SBX V5 Lapor' not in teks_layar(), user+': URI laporan ditolak tanpa data')
print('Hasil:',sum(results),'lulus,',len(results)-sum(results),'gagal',flush=True)
raise SystemExit(0 if all(results) else 1)
