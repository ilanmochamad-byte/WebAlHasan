#!/usr/bin/env python3
"""Pembantu uji Android (uiautomator) — di luar repo; langkah dicatat di berkas bukti."""
import os, re, subprocess, sys, time, xml.etree.ElementTree as ET
ADB = os.path.expanduser('~/Library/Android/sdk/platform-tools/adb')
def sh(*a, check=True): return subprocess.run([ADB, *a], capture_output=True, text=True, check=check).stdout
def dump():
    for _ in range(5):
        sh('shell', 'uiautomator', 'dump', '/sdcard/u.xml', check=False)
        x = sh('shell', 'cat', '/sdcard/u.xml', check=False)
        if x.strip().startswith('<?xml'): return ET.fromstring(x)
        time.sleep(1)
    raise SystemExit('dump gagal')
def nodes(root):
    for n in root.iter('node'):
        yield n
def label(n): return (n.get('text') or '') + '|' + (n.get('content-desc') or '')
def center(n):
    a, b, c, d = map(int, re.findall(r'\d+', n.get('bounds')))
    return (a + c) // 2, (b + d) // 2
def find(t, exact=False, timeout=20):
    end = time.time() + timeout
    while time.time() < end:
        r = dump()
        for n in nodes(r):
            for v in (n.get('text') or '', n.get('content-desc') or ''):
                if (v == t) if exact else (t in v):
                    return n
        time.sleep(1)
    return None
def text_all():
    r = dump(); return ' '.join(v for n in nodes(r) for v in (n.get('text') or '', n.get('content-desc') or '') if v)
def tap(t, exact=False, times=1, timeout=20):
    n = find(t, exact, timeout)
    if n is None: raise SystemExit(f'tidak ditemukan: {t}')
    x, y = center(n)
    for _ in range(times): sh('shell', 'input', 'tap', str(x), str(y))
    return True
def scroll_to(t, exact=False, max_swipe=8):
    for _ in range(max_swipe):
        n = find(t, exact, timeout=2)
        if n is not None: return n
        sh('shell', 'input', 'swipe', '540', '1700', '540', '700', '300')
    return find(t, exact, timeout=2)
def find_input(t, timeout=20):
    end = time.time() + timeout
    while time.time() < end:
        r = dump()
        for n in nodes(r):
            if n.get('class') == 'android.widget.EditText' and (t in (n.get('content-desc') or '') or t in (n.get('text') or '') or t in (n.get('hint') or '')):
                return n
        time.sleep(1)
    return None
def typ(field, value):
    n = find_input(field)
    if n is None: raise SystemExit(f'kolom tidak ditemukan: {field}')
    x, y = center(n); sh('shell', 'input', 'tap', str(x), str(y))
    time.sleep(0.4)
    sh('shell', 'input', 'text', value.replace(' ', '%s'))
    sh('shell', 'input', 'keyevent', '111')  # sembunyikan keyboard (ESC)
if __name__ == '__main__':
    cmd = sys.argv[1]
    if cmd == 'text': print(text_all())
    elif cmd == 'tap': tap(sys.argv[2], exact=len(sys.argv) > 3 and sys.argv[3] == 'exact', times=int(sys.argv[4]) if len(sys.argv) > 4 else 1); print('ok')
    elif cmd == 'type': typ(sys.argv[2], sys.argv[3]); print('ok')
    elif cmd == 'scroll': print('ada' if scroll_to(sys.argv[2]) is not None else 'tidak')
    elif cmd == 'wait': print('ada' if find(sys.argv[2], timeout=int(sys.argv[3]) if len(sys.argv) > 3 else 30) is not None else 'tidak')
    elif cmd == 'shot': sh('shell', 'screencap', '-p', '/sdcard/s.png'); sh('pull', '/sdcard/s.png', sys.argv[2]); print(sys.argv[2])
