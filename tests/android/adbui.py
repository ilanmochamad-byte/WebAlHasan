"""Pembantu otomasi UI Android (uiautomator dump + adb input) untuk uji V5 Fase 4."""
import re, subprocess, sys, time, xml.etree.ElementTree as ET
def sh(*a): return subprocess.run(['adb', *a], capture_output=True, text=True).stdout
def dump():
    for _ in range(3):
        sh('shell', 'uiautomator', 'dump', '/sdcard/ui.xml')
        x = sh('shell', 'cat', '/sdcard/ui.xml')
        if x.strip().startswith('<?xml'): return ET.fromstring(x[x.index('<?xml'):].split('UI hierchary dumped')[0])
        time.sleep(1)
    raise RuntimeError('dump gagal')
def nodes(root):
    for n in root.iter('node'):
        yield n, (n.get('text') or '') + ' | ' + (n.get('content-desc') or '')
def find(pat, exact=False, cls=None):
    r = dump()
    for n, t in nodes(r):
        if cls and n.get('class') != cls: continue
        cand = [n.get('text') or '', n.get('content-desc') or '']
        if any((c == pat) if exact else (pat in c) for c in cand):
            b = list(map(int, re.findall(r'\d+', n.get('bounds'))))
            return ((b[0]+b[2])//2, (b[1]+b[3])//2), n
    return None, None
def wait(pat, timeout=40, exact=False):
    t0 = time.time()
    while time.time() - t0 < timeout:
        p, n = find(pat, exact)
        if p: return p
        time.sleep(1.5)
    raise TimeoutError(pat)
def tap(pat, exact=False, timeout=40, scroll=True, cls=None, ketuk=True):
    t0 = time.time(); n = 0
    while time.time() - t0 < timeout:
        p, _ = find(pat, exact, cls)
        if p:
            if ketuk: sh('shell', 'input', 'tap', str(p[0]), str(p[1])); time.sleep(1.2)
            return p
        n += 1
        if scroll and n > 2:
            # Turun dulu beberapa kali, lalu naik: hasil bisa berada di atas posisi gulir.
            if (n // 6) % 2 == 0: sh('shell', 'input', 'swipe', '540', '1700', '540', '1000', '300')
            else: sh('shell', 'input', 'swipe', '540', '700', '540', '1500', '300')
        time.sleep(1)
    raise TimeoutError(pat)
def ketik(teks):
    sh('shell', 'input', 'text', teks.replace(' ', '%s')); time.sleep(0.6)
def isi(label, teks, exact=False):
    tap(label, exact, cls='android.widget.EditText'); ketik(teks); sh('shell', 'input', 'keyevent', '111')  # ESC tutup papan ketik
def layar(path): 
    with open(path, 'wb') as f: f.write(subprocess.run(['adb', 'exec-out', 'screencap', '-p'], capture_output=True).stdout)
def teks_layar():
    return ' '.join(t for _, t in nodes(dump()))

def posisi(pat, exact=False, timeout=40):
    return tap(pat, exact, timeout, ketuk=False)
