"""Uji Android emulator Fase 2 jadwal kelas gabungan (dev client Debug + Metro).

Prasyarat (lihat docs/jadwal-kelas-gabungan/fase-2/README.md):
  - DB test_*_test dari tests/jadwal_kelas_gabungan_f1_db.sh + f2_seed (ids.json)
  - API uji 127.0.0.1:8961, proksi tests/android/jkg_f2_proksi.py 8962 → 8961 (DIR proksi)
  - Metro dari salinan alhasanApps tanpa .env*, EXPO_PUBLIC_API_BASE_URL=http://127.0.0.1:8962/api/v1
  - adb reverse untuk port Metro dan 8962; aplikasi sudah terbuka di layar masuk

    DB_NAME=… python3 tests/android/jkg_f2_android.py <ids.json> <DIR proksi> <OUT>
Ini EMULATOR, bukan perangkat fisik; binary dev client Debug, bukan build rilis.
"""
import json, os, re, subprocess, sys, time, urllib.request
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from adbui import *

DB = os.environ.get('DB_NAME', '')
if not re.fullmatch(r'test_.*_test', DB): raise RuntimeError('Hanya DB test_*_test')
ids = json.load(open(sys.argv[1]))
PROKSI, OUT = sys.argv[2], sys.argv[3]
os.makedirs(OUT, exist_ok=True)
API = 'http://127.0.0.1:8961/api/v1'
T, sfx, NK = ids['tanggal'], ids['sfx'], ids['nama_kelas']
JG, JS = ids['jadwal_gabungan'], ids['jadwal_satu']
GAB = f"{NK['PA']}, {NK['PI']}"
SANTRI = ids['santri_nama']['PA'] + ids['santri_nama']['PI']

hasil = []
def ok(v, l): print(('[lulus] ' if v else '[gagal] ') + l, flush=True); hasil.append(bool(v))
def sql(q): return subprocess.run(['mysql', '-N', '-B', DB, '-e', q], check=True, capture_output=True, text=True).stdout.strip()
def n(q): return int(sql(q) or 0)
def pertemuan(j): return [int(x) for x in sql(f"SELECT id FROM pertemuan_pengajian WHERE jadwal_id={j} AND tanggal_pertemuan='{T}'").split() if x]
def kiriman():
    p = os.path.join(PROKSI, 'kiriman.jsonl')
    return [json.loads(b) for b in open(p)] if os.path.exists(p) else []
def mutasi(): return [k for k in kiriman() if 'kunci' in k]
def putus(nama): open(os.path.join(PROKSI, nama), 'w').close()
def ada(t): return t.lower() in teks_layar().lower()
def tunggu(t, timeout=40):
    t0 = time.time()
    while time.time() - t0 < timeout:
        if ada(t): return True
        time.sleep(1.2)
    raise TimeoutError(t)
def tunggu_bawah(t, timeout=40):
    """Pesan pada panel Ringkasan berada di bawah daftar santri: gulir ke bawah sambil menunggu."""
    t0 = time.time()
    while time.time() - t0 < timeout:
        if ada(t): return True
        for _ in range(3): sh('shell', 'input', 'swipe', '540', '1700', '540', '600', '200')
        time.sleep(0.8)
    raise TimeoutError(t)
def gulir_atas():
    for _ in range(6): sh('shell', 'input', 'swipe', '540', '700', '540', '1900', '200')
    time.sleep(0.8)
def kumpul():
    """Teks seluruh layar bergulir: atas → bawah."""
    gulir_atas(); bagian = []
    for _ in range(10):
        bagian.append(teks_layar())
        sh('shell', 'input', 'swipe', '540', '1700', '540', '900', '400'); time.sleep(1)
    return ' '.join(bagian)
def ketuk_status(nama, status):
    """Ketuk radio status pada kartu santri `nama` (radio terdekat di bawah nama)."""
    gulir_atas()
    for _ in range(12):
        r = dump(); y_nama = None
        for node, t in nodes(r):
            if (node.get('text') or '') == nama:
                y_nama = list(map(int, re.findall(r'\d+', node.get('bounds'))))[3]
        if y_nama is not None:
            calon = []
            for node, t in nodes(r):
                if (node.get('text') or '') == status or (node.get('content-desc') or '') == status:
                    b = list(map(int, re.findall(r'\d+', node.get('bounds'))))
                    if b[1] > y_nama and b[3] < 2150: calon.append(b)
            if calon:
                b = min(calon, key=lambda b: b[1])
                sh('shell', 'input', 'tap', str((b[0] + b[2]) // 2), str((b[1] + b[3]) // 2)); time.sleep(1)
                return
        sh('shell', 'input', 'swipe', '540', '1600', '540', '1100', '400'); time.sleep(1)
    raise TimeoutError(f'{nama}/{status}')
def ketuk_ganda(pos_a, pos_b=None):
    pos_b = pos_b or pos_a
    sh('shell', f'input tap {pos_a[0]} {pos_a[1]}; input tap {pos_b[0]} {pos_b[1]}')
def tombol(label, ketuk=True, timeout=30):
    """Node terakhir berteks persis `label` (tombol bilah aksi berada paling bawah; judul panel sama di atasnya)."""
    t0 = time.time()
    while time.time() - t0 < timeout:
        # Tombol dialog Android tampil berhuruf kapital (SIMPAN), jadi dibandingkan tanpa beda huruf.
        cocok = [n for n, _ in nodes(dump()) if (n.get('text') or '').strip().lower() == label.lower() or (n.get('content-desc') or '').strip().lower() == label.lower()]
        if cocok:
            b = list(map(int, re.findall(r'\d+', cocok[-1].get('bounds'))))
            p = ((b[0] + b[2]) // 2, (b[1] + b[3]) // 2)
            if ketuk: sh('shell', 'input', 'tap', str(p[0]), str(p[1])); time.sleep(1.2)
            return p
        time.sleep(1)
    raise TimeoutError(label)
def buka_jadwal(mapel):
    """Kartu sesi (label aksesibilitas) atau sorotan Beranda (tombol "Buka detail tugas")."""
    p, _ = find(f'{mapel} {sfx}, ')
    if p is None:
        p, _ = find('Buka detail tugas', exact=True)
    sh('shell', 'input', 'tap', str(p[0]), str(p[1])); time.sleep(1.5)
def api_get(user_token, p):
    req = urllib.request.Request(API + p, headers={'Authorization': 'Bearer ' + user_token})
    return json.load(urllib.request.urlopen(req))['data']
def token(u):
    req = urllib.request.Request(API + '/auth/login', data=json.dumps({'username': u, 'password': 'Sandbox#123', 'device_name': 'uji-jkg-f2-android'}).encode(), headers={'Content-Type': 'application/json'})
    return json.load(urllib.request.urlopen(req))['data']['token']

try:
    print('\n=== N-1. Masuk dan Beranda ===')
    isi('Masukkan username', 'sbx_guru_biasa'); isi('Masukkan password', 'Sandbox#123'); tap('Masuk', exact=True)
    tunggu(f'Nahwu {sfx}')
    t = teks_layar()
    ok(f'Nahwu {sfx}, {GAB},' in t or f'Nahwu {sfx} — {GAB}' in t, 'Beranda: sesi gabungan (kartu atau sorotan) memuat kedua kelas')
    ok((f'Fiqih {sfx}, {NK["C"]},' in t or f'Fiqih {sfx} — {NK["C"]} ' in t + ' ') and f'Fiqih {sfx}, {NK["C"]}, {NK["PI"]}' not in t, 'Beranda: sesi satu kelas tetap satu kelas')
    layar(f'{OUT}/android-beranda.png')

    print('\n=== N-2. Detail jadwal gabungan ===')
    buka_jadwal('Nahwu')
    tunggu('Kelas gabungan')
    t = teks_layar()
    ok(NK['PA'] in t and NK['PI'] in t and 'Satu pertemuan untuk 2 kelas' in t, 'Detail: kedua kelas dan keterangan satu pertemuan')
    ok(pertemuan(JG) == [], 'Belum ada pertemuan sebelum dibuka manual')
    layar(f'{OUT}/android-detail-gabungan.png')

    print('\n=== N-3. Buka pertemuan: ketukan ganda + jawaban hilang (503 sesudah diproses) ===')
    putus('gagal-buka'); putus('tunda')
    pos = tombol('Buka pertemuan', ketuk=False)
    ketuk_ganda(pos)
    tunggu('gerbang gagal sesudah diproses')
    os.remove(os.path.join(PROKSI, 'tunda'))
    m = mutasi()
    ok(len(m) == 1, f'Ketukan ganda saat permintaan berjalan: satu POST pembukaan ({len(m)})')
    ok(len(pertemuan(JG)) == 1, 'Server sudah membuat pertemuan walau jawaban hilang')
    layar(f'{OUT}/android-buka-putus.png')
    tombol('Buka pertemuan')
    tunggu('Santri · 5 orang')
    m = mutasi()
    MG = pertemuan(JG)[0]
    ok(len(m) == 2 and m[0]['kunci'] == m[1]['kunci'], 'Kirim ulang: kunci idempotensi sama')
    ok(len(pertemuan(JG)) == 1 and n(f'SELECT COUNT(DISTINCT santri_id) FROM pertemuan_peserta WHERE pertemuan_id={MG}') == 5
       and n(f'SELECT COUNT(*) FROM pertemuan_peserta WHERE pertemuan_id={MG}') == 5 and n(f'SELECT COUNT(*) FROM pertemuan_kelas WHERE pertemuan_id={MG}') == 2,
       'Tepat satu pertemuan, lima peserta unik, dua kelas beku')

    print('\n=== N-4. Absensi gabungan ===')
    layar(f'{OUT}/android-absensi-gabungan.png')
    semua = kumpul()
    ok(f'· {GAB}' in semua and 'Kelas gabungan · 2 kelas dalam satu pertemuan' in semua, 'Judul memuat kedua kelas dan penanda gabungan')
    ok(NK['PA'].lower() in semua.lower() and '3 santri' in semua and '2 santri' in semua, 'Judul kelompok per kelas asal (3 dan 2 santri)')
    ok(all(s['nama'] in semua for s in SANTRI) and ids['santri_selesai_nama'] not in semua, 'Peserta kedua kelas; keanggotaan Selesai tidak ikut')
    rinci = api_get(token('sbx_guru_biasa'), f'/meetings/{MG}')
    ok(all(f"NIS {s['nis']} · Kelas {s['class']['name']}" in semua for s in rinci['students']), 'Label kelas asal tiap santri = API')
    layar(f'{OUT}/android-absensi-bawah.png')

    print('\n=== N-5. Kirim absensi: ketukan ganda + jawaban hilang (503 sesudah diproses) ===')
    pi1 = ids['santri_nama']['PI'][0]
    ketuk_status(pi1['nama'], 'Izin')
    putus('gagal-absen'); putus('tunda')
    tap('Konfirmasi dan kirim', exact=True)
    simpan = tombol('Simpan', ketuk=False)
    kirim = posisi('Konfirmasi dan kirim', exact=True, timeout=5) if find('Konfirmasi dan kirim', True)[0] else (540, 2250)
    ketuk_ganda(simpan, kirim)
    time.sleep(1.5)
    if ada('Kirim absensi?'): tombol('Periksa lagi')
    tunggu_bawah('gerbang gagal sesudah diproses')
    os.remove(os.path.join(PROKSI, 'tunda'))
    ok(len(mutasi()) == 3, f'Simpan + ketukan kedua saat berjalan: satu PUT ({len(mutasi()) - 2})')
    ok(n(f'SELECT COUNT(*) FROM absensi_santri WHERE pertemuan_id={MG}') == 5, 'Server sudah menyimpan walau jawaban hilang')
    tap('Konfirmasi dan kirim', exact=True); tombol('Simpan')
    tunggu_bawah('Absensi berhasil disimpan')
    m = mutasi()
    ok(len(m) == 4 and m[2]['kunci'] == m[3]['kunci'], 'Kirim ulang absensi: kunci sama')
    ok(n(f'SELECT COUNT(*) FROM absensi_guru WHERE pertemuan_id={MG}') == 1 and n(f'SELECT COUNT(*) FROM absensi_santri WHERE pertemuan_id={MG}') == 5
       and sql(f"SELECT status FROM absensi_santri WHERE pertemuan_id={MG} AND santri_id={pi1['id']}") == 'Izin'
       and sql(f'SELECT status FROM pertemuan_pengajian WHERE id={MG}') == 'Selesai', 'Satu guru + lima santri, PI1 Izin, pertemuan Selesai')
    layar(f'{OUT}/android-absensi-tersimpan.png')

    print('\n=== N-6. Pertemuan selesai: koreksi ===')
    pa1 = ids['santri_nama']['PA'][0]
    ketuk_status(pa1['nama'], 'Sakit')
    tap('Simpan koreksi', exact=True); tombol('Simpan')
    tunggu_bawah('Alasan koreksi wajib diisi')
    ok(len(mutasi()) == 4, 'Tanpa alasan: tidak ada PUT')
    isi('Wajib diisi untuk menyimpan koreksi', 'Koreksi uji android')
    tap('Simpan koreksi', exact=True); tombol('Simpan')
    tunggu_bawah('Absensi berhasil disimpan')
    m = mutasi()
    ok(len(m) == 5 and m[4]['kunci'] != m[3]['kunci'], 'Koreksi: satu PUT, kunci baru')
    ok(n(f'SELECT COUNT(*) FROM absensi_santri WHERE pertemuan_id={MG}') == 5 and sql(f"SELECT status FROM absensi_santri WHERE pertemuan_id={MG} AND santri_id={pa1['id']}") == 'Sakit',
       'Koreksi memperbarui baris (PA1 Sakit), tidak menambah')

    print('\n=== N-7. Laporan dan website ===')
    lap = api_get(token('sbx_guru_biasa'), f'/reports?date_from={T}&date_to={T}&schedule_id={JG}')
    s = lap['summary']
    ok(s['meeting_count'] == 1 and s['teacher_attendance_count'] == 1 and s['student_attendance_count'] == 5, 'REST laporan (layanan sama dengan website): satu pertemuan, satu guru, lima santri')

    print('\n=== N-8. Jadwal satu kelas ===')
    for _ in range(3):
        if ada(f'Fiqih {sfx}'): break
        sh('shell', 'input', 'keyevent', '4'); time.sleep(1.5)
    tunggu(f'Fiqih {sfx}')
    buka_jadwal('Fiqih')
    tunggu('Daftar santri akan dibekukan dari keanggotaan kelas saat pertemuan dibuka.')
    ok(not ada('Kelas gabungan'), 'Detail satu kelas tanpa penanda gabungan')
    # Sambungan ditutup tanpa jawaban sesudah server memproses: dicatat apa adanya.
    sebelum = len(mutasi())
    putus('putus-buka')
    tombol('Buka pertemuan')
    t0 = time.time()
    while time.time() - t0 < 30 and not ada('Santri · 2 orang') and not ada('server tidak terjangkau'): time.sleep(1)
    if ada('server tidak terjangkau'):
        print('    (sambungan putus: aplikasi menampilkan galat jaringan; kirim ulang oleh pengguna)')
        tombol('Buka pertemuan')
    tunggu('Santri · 2 orang')
    baru = mutasi()[sebelum:]
    print(f'    POST pembukaan yang sampai ke proksi: {len(baru)}; kunci {"sama" if len({k["kunci"] for k in baru}) == 1 else "berbeda"}')
    ok(len(baru) >= 1 and len({k['kunci'] for k in baru}) == 1 and len(pertemuan(JS)) == 1, 'Sambungan putus sesudah diproses: setiap kiriman ulang memakai kunci sama, tepat satu pertemuan')
    semua = kumpul()
    ok('Kelas gabungan' not in semua and '· Kelas ' not in semua and f"{NK['C'].lower()} 2 santri" not in semua.lower(), 'Absensi satu kelas: tanpa judul kelompok/label kelas (tampilan lama)')
    layar(f'{OUT}/android-absensi-satu.png')
    tap('Konfirmasi dan kirim', exact=True); tombol('Simpan')
    tunggu_bawah('Absensi berhasil disimpan')
    MS = pertemuan(JS)
    ok(len(MS) == 1 and n(f'SELECT COUNT(*) FROM absensi_guru WHERE pertemuan_id={MS[0]}') == 1 and n(f'SELECT COUNT(*) FROM absensi_santri WHERE pertemuan_id={MS[0]}') == 2,
       'Satu pertemuan, satu guru + dua santri')
except Exception as e:
    ok(False, f'Uji berhenti: {e}')
    layar(f'{OUT}/android-berhenti.png')
print(f'\nRingkasan: {sum(hasil)} lulus, {len(hasil) - sum(hasil)} gagal')
sys.exit(0 if all(hasil) else 1)
