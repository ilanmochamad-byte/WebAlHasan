"""Optimasi Android Google Play (21 November 2026) — uji upgrade dan sesi pada EMULATOR.

APK LAMA = Release 1.9.0 tanpa R8 (sumber main 82f156a), APK BARU = Release 1.9.1 R8
(sumber handoff); keduanya salinan UJI (cleartext hanya 127.0.0.1) dan ditandatangani
kunci debug template yang SAMA, sehingga `adb install -r` mensimulasikan pembaruan.
Ini BUKAN bukti Play App Signing/upgrade dari Play Store.

  adb reverse tcp:8971 tcp:8971   (API uji, DB test_*_test)
  python3 tests/android/oap_upgrade_sesi.py <apk-lama> <apk-baru> <keluaran>

Versi pasangan lain harus ditetapkan secara eksplisit, misalnya
OAP_OLD_VERSION=1.9.2 OAP_NEW_VERSION=1.10.0 untuk audit SDK 58.
Nilai bawaan tetap pasangan audit awal; assertion versi tidak dihapus.

  U-1 install bersih APK lama, masuk sbx_guru_biasa → Beranda
  U-2 install -r APK baru (data dipertahankan) → cold start tanpa Metro langsung Beranda
      (token SecureStore + Keystore terbaca oleh kode R8), versionName 1.9.1
  U-3 kembali dari latar (HOME → buka lagi) tetap Beranda
  U-4 Keluar → layar Masuk; masuk lagi → Beranda; force-stop + cold start → Beranda
  U-5 logcat tanpa password/bearer mentah
"""
import os, re, subprocess, sys, time
sys.path.insert(0, os.path.dirname(__file__))
from adbui import sh, tap, wait, find, ketik, layar, teks_layar
import adbui

# Jangan menerima snapshot sebelum uiautomator gagal membuat XML baru.
_dump_original = adbui.dump
def fresh_dump():
    sh('shell', 'rm', '-f', '/sdcard/ui.xml')
    return _dump_original()
adbui.dump = fresh_dump

LAMA, BARU, OUT = sys.argv[1], sys.argv[2], sys.argv[3]
VERSI_LAMA = os.environ.get('OAP_OLD_VERSION', '1.9.0')
VERSI_BARU = os.environ.get('OAP_NEW_VERSION', '1.9.1')
os.makedirs(OUT, exist_ok=True)
PKG = 'com.ilanmochamad.alhasanApps'
PASS = 'Sandbox#123'
lulus = gagal = 0


def ok(c, pesan):
    global lulus, gagal
    if c: lulus += 1
    else: gagal += 1
    print(f"[{'lulus' if c else 'gagal'}] {pesan}", flush=True)


def buka():
    sh('shell', 'am', 'start', '-a', 'android.intent.action.MAIN', '-c', 'android.intent.category.LAUNCHER', '-n', f'{PKG}/.MainActivity')
    time.sleep(5)


def di_beranda(timeout=60):
    try:
        # ScrollView dapat memulihkan offset sesudah install -r/cold start.
        # Kartu identitas tetap wajib terlihat; ubin menu saja tidak cukup
        # membuktikan sesi. Pastikan kartu di awal Beranda dapat diperiksa.
        sh('shell', 'input', 'keyevent', '111')
        for _ in range(2):
            sh('shell', 'input', 'swipe', '540', '500', '540', '1800', '350')
        time.sleep(0.5)
        # Assalamu juga ada di Masuk, dan placeholder hilang setelah isian
        # diketik. Kartu beridentitas hanya muncul setelah autentikasi sah.
        wait('Buka profil', timeout)
        return any((n.get('content-desc') or '').endswith('. Buka profil')
                   for n in adbui.dump().iter('node'))
    except TimeoutError:
        return False


def masuk(user):
    tap('Masukkan username', cls='android.widget.EditText', timeout=90)
    # Input per karakter menghindari pengetikan adb terpotong. Nilai akun dan
    # password tetap fixture asli; verifikasi input sebelum mengirim login.
    for char in user:
        ketik('\\#' if char == '#' else char)
    sh('shell', 'input', 'keyevent', '111')
    if not any(n.get('class') == 'android.widget.EditText' and n.get('text') == user
               for n in adbui.dump().iter('node')):
        raise RuntimeError('Input username fixture tidak lengkap; login belum dikirim')
    tap('Masukkan password', cls='android.widget.EditText')
    for char in PASS:
        ketik('\\#' if char == '#' else char)
    sh('shell', 'input', 'keyevent', 'KEYCODE_ENTER')


def versi():
    m = re.search(r'versionName=(\S+)', sh('shell', 'dumpsys', 'package', PKG))
    return m.group(1) if m else '?'


try:
    sh('logcat', '-c')
    sh('uninstall', PKG)
    print(subprocess.run(['adb', 'install', LAMA], capture_output=True, text=True).stdout.strip().splitlines()[-1])
    ok(versi() == VERSI_LAMA, f'U-1 APK lama terpasang ({versi()}; diharapkan {VERSI_LAMA})')
    buka()
    ok(not di_beranda(2), 'U-1 kontrol negatif: layar Masuk tidak dianggap Beranda')
    masuk('sbx_guru_biasa')
    old_home = di_beranda()
    ok(old_home, 'U-1 APK lama: masuk sbx_guru_biasa → Beranda'); layar(f'{OUT}/u1-lama-beranda.png')
    if not old_home:
        raise RuntimeError('Login baseline belum tervalidasi; upgrade tidak dijalankan')

    r = subprocess.run(['adb', 'install', '-r', BARU], capture_output=True, text=True).stdout.strip().splitlines()[-1]
    print(r)
    ok(versi() == VERSI_BARU, f'U-2 APK baru dipasang sebagai pembaruan ({versi()}; diharapkan {VERSI_BARU})')
    sh('shell', 'am', 'force-stop', PKG); buka()
    ok(di_beranda(), 'U-2 cold start APK baru (tanpa Metro): sesi SecureStore lama terbaca → Beranda tanpa masuk ulang')
    layar(f'{OUT}/u2-baru-beranda.png')

    sh('shell', 'input', 'keyevent', 'KEYCODE_HOME'); time.sleep(3); buka()
    ok(di_beranda(20), 'U-3 kembali dari latar: tetap Beranda')

    tap('Buka profil', timeout=30); time.sleep(1)
    tap('Keluar dari aplikasi', timeout=40)  # tombol di Profil (digulir bila perlu)
    try:
        tap('KELUAR', exact=True, timeout=8, scroll=False)  # tombol konfirmasi Alert (Material: kapital)
    except TimeoutError:
        tap('Keluar', exact=True, timeout=8, scroll=False)
    try:
        wait('Masukkan username', 40); ok(True, 'U-4 Keluar → layar Masuk')
    except TimeoutError:
        ok(False, 'U-4 Keluar → layar Masuk')
    masuk('sbx_guru_biasa')
    ok(di_beranda(), 'U-4 masuk lagi → Beranda')
    sh('shell', 'am', 'force-stop', PKG); buka()
    ok(di_beranda(), 'U-4 force-stop + cold start: sesi baru bertahan')
    layar(f'{OUT}/u4-cold.png')

    log = subprocess.run(['adb', 'logcat', '-d'], capture_output=True, text=True).stdout
    with open(f'{OUT}/logcat.txt', 'w') as f: f.write(log)
    ok(PASS not in log and not re.search(r'Bearer [A-Za-z0-9._-]{20,}', log), f'U-5 logcat ({len(log.splitlines())} baris) tanpa password/bearer mentah')
except Exception as e:  # noqa: BLE001
    ok(False, f'galat: {e}')
    layar(f'{OUT}/galat.png')
print(f'\nRingkasan: {lulus} lulus, {gagal} gagal')
sys.exit(1 if gagal else 0)
