"""Probe auditor pada APK Release, tanpa Metro; hanya fixture lokal sintetis.

DB_NAME=test_*_test python3 tests/android/oap_audit_native.py documents|rapor|bsa|kesehatan|izin|layout OUT
API binary uji harus http://127.0.0.1:8971/api/v1. Jangan kirim dokumen ke target berbagi.
Recents disimpan untuk inspeksi visual terpisah; screenshot saja tidak dihitung lulus.
"""
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import time

import adbui
from adbui import sh, tap, wait, ketik, layar, teks_layar, dump

# Semua helper impor memakai snapshot baru; XML sisa tidak boleh membuat
# input koordinat/hasil positif palsu ketika uiautomator gagal idle.
_dump_original = dump
def dump():
    sh("shell", "rm", "-f", "/sdcard/ui.xml")
    return _dump_original()
adbui.dump = dump

MODE, OUTPUT = sys.argv[1:]
DB = os.environ.get("DB_NAME", "")
if not re.fullmatch(r"test_.*_test", DB):
    raise SystemExit("Hanya DB fixture test_*_test")
if MODE not in {"documents", "rapor", "bsa", "kesehatan", "izin", "layout"}:
    raise SystemExit("Mode tidak dikenal")
OUT = Path(OUTPUT)
OUT.mkdir(parents=True, exist_ok=True)
PKG = "com.ilanmochamad.alhasanApps"
results = []


def ok(value, label):
    results.append({"pass": bool(value), "label": label})
    print(f"[{'lulus' if value else 'gagal'}] {label}", flush=True)


def sql(query):
    return subprocess.check_output(["mysql", "-N", "-B", DB, "-e", query], text=True).strip()


def type_input(value):
    # Opsi transport input emulator lama; nilai dan oracle autentikasi tetap.
    if os.environ.get("OAP_SLOW_INPUT") == "1":
        for char in value:
            ketik("\\#" if char == "#" else char)
    else:
        ketik(value)


def uri(path):
    sh("shell", "am", "start", "-a", "android.intent.action.VIEW", "-d", f"alhasanapps://{path}", PKG)
    time.sleep(3)


def top():
    text = sh("shell", "dumpsys", "activity", "activities")
    return "\n".join(line for line in text.splitlines() if "ResumedActivity" in line)


def until(predicate, seconds=45):
    end = time.monotonic() + seconds
    while time.monotonic() < end:
        if predicate():
            return True
        time.sleep(1)
    return False


def screenshot(name):
    path = OUT / f"{name}.png"
    layar(str(path))
    return path.stat().st_size


def bounds(node):
    return tuple(map(int, re.findall(r"\d+", node.get("bounds", ""))))


def element(label):
    return next((n for n in dump().iter("node") if label in [n.get("text"), n.get("content-desc")]), None)


def inset_frame(names):
    state = sh("shell", "dumpsys", "window", "displays")
    pattern = rf"(?:{'|'.join(names)})\s+frame=\[(\d+),(\d+)\]\[(\d+),(\d+)\].*visible=true"
    hit = re.search(pattern, state)
    if not hit:
        raise RuntimeError("Frame inset sistem tidak ditemukan: " + "/".join(names))
    return tuple(map(int, hit.groups()))


def system_share(label):
    opened = until(lambda: bool(re.search(r"ChooserActivity|ResolverActivity", top())), 90)
    ok(opened, label + ": chooser sistem terbuka setelah PDF dibuat/diunduh")
    if opened:
        screenshot("share")
        shared_ui = teks_layar()
        (OUT / "share-ui.txt").write_text(shared_ui)
        ok(bool(re.search(r"pdf", shared_ui, re.I)), label + ": chooser menampilkan dokumen PDF")
        sh("shell", "input", "keyevent", "KEYCODE_BACK")
        time.sleep(2)
    # Tidak memilih target; tidak ada pengiriman ke pihak lain.


try:
    sh("reverse", "tcp:8971", "tcp:8971")
    scale = os.environ.get("OAP_FONT_SCALE", "1.0")
    if scale not in {"1.0", "2.0"}:
        raise RuntimeError("Skala probe hanya 1.0/2.0")
    sh("shell", "settings", "put", "system", "font_scale", scale)
    sh("shell", "cmd", "uimode", "night", "yes" if os.environ.get("OAP_DARK") == "1" else "no")
    sh("logcat", "-c")
    sh("shell", "pm", "clear", PKG)
    sh("shell", "am", "start", "-n", PKG + "/.MainActivity")
    user = {"documents": "sbx_guru_biasa", "rapor": "sbx_t7_ortu", "bsa": "sbx_v6_bsa_b", "kesehatan": "sbx_v5_petugas_f", "izin": "sbx_ortu_a", "layout": "sbx_pengurus_a"}[MODE]
    tap("Masukkan username", cls="android.widget.EditText", timeout=120)
    type_input(user)
    sh("shell", "input", "keyevent", "111")
    time.sleep(0.5)
    # Pada penskalaan linear Android 12, password berada di bawah lipatan.
    # Pastikan field terjangkau sebelum mengetuk; jangan menerima judul
    # Assalamu pada layar Masuk sebagai bukti autentikasi berhasil.
    sh("shell", "input", "swipe", "540", "1700", "540", "700", "300")
    time.sleep(0.6)
    tap("Masukkan password", cls="android.widget.EditText")
    type_input("Sandbox#123")
    sh("shell", "input", "keyevent", "KEYCODE_ENTER")
    # Label kartu identitas adalah "<nama>. <status>. Buka profil".
    # Awalan "Buka profil" menolak Beranda yang sudah terautentikasi; tetap
    # wajib kartu profil, bukan teks Assalamu yang juga ada di layar Masuk.
    if not until(lambda: any((n.get("content-desc") or "").endswith(". Buka profil") for n in dump().iter("node")), 90):
        raise RuntimeError("Beranda terautentikasi tidak terbuka")
    ok(True, MODE + ": login pada Release dengan bundel tertanam")
    control = screenshot("beranda-kontrol")

    if MODE == "layout":
        uri("perizinan")
        wait("Ajukan izin", timeout=60)
        status = inset_frame(["statusBars", "ITYPE_STATUS_BAR"])
        nav = inset_frame(["navigationBars", "ITYPE_NAVIGATION_BAR"])
        back = element("Kembali ke Beranda")
        fab = element("Ajukan izin baru")
        ok(back is not None and bounds(back)[1] >= status[3], f"kepala di bawah status/cutout (bawah status {status[3]})")
        tab_nodes = [n for n in dump().iter("node") if n.get("text") in {"Beranda", "Profil"}]
        tabs = [bounds(n) for n in tab_nodes if bounds(n)[1] > 1000]
        ok(bool(tabs) and all(b[3] <= nav[1] for b in tabs), f"label tab di atas navigasi sistem (atas nav {nav[1]})")
        ok(fab is not None and bool(tabs) and bounds(fab)[3] <= min(b[1] for b in tabs), "tindakan Ajukan izin tidak tertutup tab")
        screenshot("layout-perizinan")
        edits = [n for n in dump().iter("node") if n.get("class", "").endswith("EditText")]
        if not edits:
            raise RuntimeError("Kolom pencarian tidak terjangkau")
        box = bounds(edits[0])
        sh("shell", "input", "tap", str((box[0] + box[2]) // 2), str((box[1] + box[3]) // 2))
        time.sleep(3)
        ime = inset_frame(["type=ime", "ITYPE_IME"])
        edits = [n for n in dump().iter("node") if n.get("class", "").endswith("EditText")]
        ok(bool(edits) and bounds(edits[0])[3] <= ime[1], f"kolom aktif tidak tertutup keyboard (atas IME {ime[1]})")
        if scale == "1.0":
            ok(element("Ajukan izin baru") is None, "FAB hilang saat keyboard terbuka")
        screenshot("layout-keyboard")
        sh("shell", "input", "keyevent", "KEYCODE_BACK")
        uri("notifikasi")
        wait("Notifikasi", timeout=60)
        tap("Tindakan lain", exact=True)
        wait("Tandai semua dibaca", timeout=30)
        action = element("Tandai semua dibaca")
        ok(action is not None and status[3] <= bounds(action)[1] < bounds(action)[3] <= nav[1], "aksi lembar bawah berada di dalam inset sistem")
        screenshot("layout-dialog")
        sh("shell", "input", "keyevent", "KEYCODE_BACK")
    elif MODE == "documents":
        uri("reports")
        wait("Laporan kehadiran", timeout=60)
        tap("Tindakan lain", exact=True)
        tap("Cetak PDF", exact=True)
        opened = until(lambda: "com.android.printspooler" in top(), 90)
        ok(opened, "expo-print: dialog cetak sistem terbuka")
        if opened:
            time.sleep(6)
            screenshot("print-preview")
            (OUT / "print-ui.txt").write_text(teks_layar())
            sh("shell", "input", "keyevent", "KEYCODE_BACK")
        wait("Laporan kehadiran", timeout=45)
        ok(True, "batal cetak kembali ke laporan")
        tap("Tindakan lain", exact=True)
        tap("Bagikan", exact=True)
        system_share("expo-print + expo-sharing")
        wait("Laporan kehadiran", timeout=45)
        ok(True, "batal berbagi kembali ke laporan")
    elif MODE == "rapor":
        rid = sql("SELECT r.id FROM v4_rapor r JOIN santri s ON s.id=r.santri_id WHERE s.nama_santri='SBX T7 Santri 01' LIMIT 1")
        if not rid:
            raise RuntimeError("Fixture rapor terbit tidak ada")
        uri("rapor/" + rid)
        wait("Status rapor", timeout=60)
        tap("Unduh PDF rapor", timeout=60)
        system_share("expo-file-system unduh rapor + expo-sharing")
    else:
        path, marker = {
            "bsa": ("keuangan", "Santri, saldo, dan tagihan"),
            "kesehatan": ("kesehatan", "Catat kunjungan"),
            "izin": ("izin-wali", "Ajukan izin"),
        }[MODE]
        uri(path)
        wait(marker, timeout=60)
        time.sleep(2)
        protected = screenshot("terlindungi")
        ok(protected < control / 4, f"{MODE}: screenshot terlindungi {protected} B vs Beranda {control} B")
        window = sh("shell", "dumpsys", "window", "windows")
        (OUT / "window.txt").write_text(window)
        sh("shell", "input", "keyevent", "KEYCODE_APP_SWITCH")
        time.sleep(3)
        screenshot("recents")
        (OUT / "recents-ui.txt").write_text(teks_layar())
        print("[inspeksi] Recents perlu diperiksa visual; tidak otomatis dihitung lulus", flush=True)
        sh("shell", "am", "start", "-n", PKG + "/.MainActivity")
        wait(marker, timeout=60)
        ok(True, MODE + ": kembali dari latar memuat ulang layar berhak")

    log = sh("logcat", "-d")
    (OUT / "logcat.txt").write_text(log)
    ok("Sandbox#123" not in log and not re.search(r"Bearer [A-Za-z0-9._-]{20,}", log), "logcat tanpa password atau bearer mentah")
except Exception as error:
    ok(False, "Pengecualian: " + str(error))
    screenshot("error")
finally:
    (OUT / "results.json").write_text(json.dumps(results, indent=2) + "\n")
    good = sum(r["pass"] for r in results)
    bad = len(results) - good
    print(f"Ringkasan: {good} lulus / {bad} gagal", flush=True)
sys.exit(1 if bad else 0)
