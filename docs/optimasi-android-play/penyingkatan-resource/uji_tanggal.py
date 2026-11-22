"""Uji manual pemilih tanggal Android Release (optimized resource shrinking).
Hanya 127.0.0.1 dan akun sintetis sbx_*. Pemakaian: python3 -I uji_tanggal.py <folder-keluaran>"""
import os, re, subprocess, sys, time, xml.etree.ElementTree as ET

ADB = os.path.expanduser('~/Library/Android/sdk/platform-tools/adb')
PKG = 'com.ilanmochamad.alhasanApps'
OUT = sys.argv[1]
os.makedirs(OUT, exist_ok=True)
hasil = []

def adb(*a, text=True):
    return subprocess.run([ADB, *a], capture_output=True, text=text).stdout

def dump():
    adb('shell', 'uiautomator', 'dump', '/sdcard/ui.xml')
    x = adb('shell', 'cat', '/sdcard/ui.xml')
    try:
        root = ET.fromstring(x[x.index('<'):])
    except Exception:
        return []
    n = []
    for e in root.iter('node'):
        b = [int(v) for v in re.findall(r'\d+', e.get('bounds', ''))]
        n.append({'text': e.get('text', ''), 'desc': e.get('content-desc', ''), 'cls': e.get('class', ''), 'pkg': e.get('package', ''), 'b': b})
    return n

def cari(pola, timeout=30):
    r = re.compile(pola)
    akhir = time.time() + timeout
    while time.time() < akhir:
        for n in dump():
            if r.search(n['text']) or r.search(n['desc']):
                return n
        time.sleep(1)
    return None

def ketuk(n):
    b = n['b']
    adb('shell', 'input', 'tap', str((b[0] + b[2]) // 2), str((b[1] + b[3]) // 2))

def layar(nama):
    with open(os.path.join(OUT, nama + '.png'), 'wb') as f:
        f.write(subprocess.run([ADB, 'exec-out', 'screencap', '-p'], capture_output=True).stdout)

def ok(cond, pesan):
    hasil.append(bool(cond))
    print(('[lulus] ' if cond else '[gagal] ') + pesan, flush=True)

adb('shell', 'pm', 'clear', PKG)
adb('reverse', 'tcp:8971', 'tcp:8971')
time.sleep(2)
print(adb('shell', 'am', 'start', '-n', PKG + '/.MainActivity').strip(), flush=True)
u = cari(r'Masukkan username', 120)
ok(u, 'Layar Masuk tampil')
ketuk(u); adb('shell', 'input', 'text', 'sbx_pengurus_a')
p = cari(r'Masukkan password'); ketuk(p); adb('shell', 'input', 'text', 'Sandbox#123')
adb('shell', 'input', 'keyevent', '111')
m = [n for n in dump() if n['text'] == 'Masuk' or n['desc'] == 'Masuk']
ketuk(m[-1])
ok(cari(r'Buka profil', 60), 'Beranda tampil sesudah masuk')
izin = cari(r'^Izin$|Perizinan', 20); ketuk(izin)
fab = cari(r'^Ajukan izin baru$', 30); ok(fab, 'FAB Ajukan izin'); ketuk(fab)
s = cari(r'^SBX Santri A1, NIS', 30); ok(s, 'Langkah 1: santri sintetis tampil'); ketuk(s)
time.sleep(1)
lanjut = cari(r'^(Lanjut|Berikutnya|Selanjutnya)', 10)
if lanjut:
    ketuk(lanjut)
time.sleep(1)
print([n['desc'] or n['text'] for n in dump() if n['desc'] or n['text']][:40], flush=True)
f = cari(r'(Tanggal|Mulai|Berangkat).*(Ketuk|Pilih|pilih|belum)', 30)
ok(f, 'Kolom tanggal pada formulir izin')
layar('01-formulir')
ketuk(f)
time.sleep(2)
d = dump()
dialog = [n for n in d if n['text'] in ('Pilih', 'Batal') or n['desc'] in ('Pilih', 'Batal')]
kal = [n for n in d if re.search(r'\b(20\d\d)\b', n['text'] + n['desc'])]
layar('02-pemilih-tanggal')
ok(len(dialog) >= 2, f'Dialog pemilih tanggal terbuka dengan Pilih/Batal ({len(dialog)})')
ok(kal, 'Pemilih memuat tahun/kalender')
pilih = [n for n in dialog if 'Pilih' in (n['text'], n['desc'])]
if pilih:
    ketuk(pilih[0])
time.sleep(1.5)
layar('03-sesudah-pilih')
terisi = cari(r'^Mulai: \d{1,2} \w+ 20\d\d\. Ketuk untuk mengubah\.$', 10)
ok(terisi, 'Tanggal terisi sesudah Pilih')
crash = adb('logcat', '-d', '-b', 'crash')
ok(PKG not in crash, 'Tanpa crash di logcat buffer crash')
ok(adb('shell', 'pidof', PKG).strip(), 'Proses aplikasi masih hidup')
print(f'Hasil: {sum(hasil)} lulus / {len(hasil) - sum(hasil)} gagal')
