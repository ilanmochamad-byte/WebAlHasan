"""Uji native (Android emulator / iOS simulator) paket pencarian santri.

Alur Maestro yang SAMA untuk kedua platform di atas fixture yang sama
(`tests/jadwal_kelas_gabungan_f1_db.sh` + `tests/jadwal_kelas_gabungan_f2_seed.php`,
dipulihkan dari snapshot yang sama sebelum setiap platform sehingga ID sama),
akun sama (`sbx_guru_biasa`), API lewat proksi `tests/android/pcs_proksi.py`.

    DB_NAME=test_pcs_app_test python3 tests/android/pcs_native.py \
        --platform android|ios --device <id> --ids ids.json --proksi <dir> --out <dir>

N-1 tab Semua/PA/PI berhitungan; tab PA/PI hanya kelompoknya (nama dari DB)
N-2 cari NIS pada tab PI untuk santri PA → kosong; Semua → tampil
N-3 status PI1 = Izin (tab PI) dan PA1 = Sakit (pencarian aktif); simpan dengan saringan aktif
N-4 DB: satu absensi guru, lima santri unik, status per santri_id; proksi: PUT seluruh
    lima peserta unik; respons rincian (sha256, kelas, pasangan student_id–kelas) dicatat
N-5 buka ulang aplikasi (cold start) + tautan pertemuan: tab PI menampilkan Izin terpilih
"""
import argparse, json, os, subprocess, sys, time

p = argparse.ArgumentParser()
p.add_argument('--platform', required=True, choices=['android', 'ios'])
p.add_argument('--device', required=True)
p.add_argument('--ids', required=True)
p.add_argument('--proksi', required=True)
p.add_argument('--out', required=True)
a = p.parse_args()
DB = os.environ.get('DB_NAME', '')
if not (DB.startswith('test_') and DB.endswith('_test')):
    sys.exit('DB uji harus test_*_test')
os.makedirs(a.out, exist_ok=True)
ids = json.load(open(a.ids))
APP = 'com.ilanmochamad.alhasanApps'
lulus, gagal = 0, []


def ok(v, pesan):
    global lulus
    print(('[lulus] ' if v else '[gagal] ') + pesan, flush=True)
    if v:
        lulus += 1
    else:
        gagal.append(pesan)


def sql(q):
    return subprocess.run(['mysql', '-N', '-B', DB, '-e', q], capture_output=True, text=True, check=True).stdout.strip()


def flow(nama, langkah):
    berkas = os.path.join(a.out, f'{nama}.yaml')
    with open(berkas, 'w') as f:
        f.write(f'appId: {APP}\n---\n' + json.dumps(langkah, ensure_ascii=False))
    env = dict(os.environ, MAESTRO_CLI_NO_ANALYTICS='1')
    r = subprocess.run(['maestro', '--device', a.device, 'test', '--test-output-dir', os.path.join(a.out, 'maestro-' + nama), berkas],
                       capture_output=True, text=True, env=env, timeout=900)
    with open(os.path.join(a.out, f'{nama}.txt'), 'w') as f:
        f.write(r.stdout + r.stderr)
    return r.returncode == 0


def ketik(teks):
    return [{'inputText': c} for c in teks]


nis = lambda sid: sql(f'SELECT nis FROM santri WHERE id={int(sid)}')
PA, PI = ids['santri_nama']['PA'], ids['santri_nama']['PI']
pa1, pi1 = PA[0], PI[0]
nisPA1, nisPI1 = nis(pa1['id']), nis(pi1['id'])
JG, T = ids['jadwal_gabungan'], ids['tanggal']
# Maestro menyimpan tangkapan relatif terhadap --test-output-dir alur.
shot = lambda n: {'takeScreenshot': f'{a.platform}-{n}'}

login = [
    {'launchApp': {'stopApp': True, 'clearState': True}},
    {'extendedWaitUntil': {'visible': 'Masukkan username', 'timeout': 60000}},
    {'tapOn': 'Masukkan username'}, 'eraseText', {'inputText': 'sbx_guru_biasa'},
    {'tapOn': 'Masukkan password'}, 'eraseText', *ketik('Sandbox#123'), 'hideKeyboard',
    {'tapOn': 'Masuk'},
    {'runFlow': {'when': {'visible': 'Not Now'}, 'commands': [{'tapOn': 'Not Now'}]}},
    {'extendedWaitUntil': {'visible': 'Beranda', 'timeout': 30000}},
]
utama = login + [
    {'openLink': f'alhasanapps://schedule/{JG}?date={T}'},
    {'extendedWaitUntil': {'visible': 'Buka pertemuan', 'timeout': 30000}},
    # Judul panel juga berteks "Buka pertemuan"; ketuk TOMBOL di bawah kolom catatan.
    {'tapOn': {'text': 'Buka pertemuan', 'below': {'text': 'Catatan pertemuan.*'}}},
    {'extendedWaitUntil': {'visible': 'Santri · 5 orang', 'timeout': 30000}},
    shot('01-absensi-awal'),
    {'scrollUntilVisible': {'element': {'text': 'PI · 2'}, 'direction': 'DOWN', 'timeout': 20000}},
    {'assertVisible': 'Semua · 5'}, {'assertVisible': 'PA · 3'}, {'assertVisible': 'PI · 2'},
    shot('02-tab-semua'),
    {'tapOn': 'PA · 3'},
    {'assertNotVisible': f'NIS {nisPI1}.*'},
    {'scrollUntilVisible': {'element': {'text': f'NIS {nisPA1}.*'}, 'direction': 'DOWN', 'timeout': 20000}},
    shot('03-tab-pa'),
    {'scrollUntilVisible': {'element': {'text': 'PI · 2'}, 'direction': 'UP', 'timeout': 20000}},
    {'tapOn': 'PI · 2'},
    {'assertNotVisible': f'NIS {nisPA1}.*'},
    {'scrollUntilVisible': {'element': {'text': f'NIS {nisPI1}.*'}, 'direction': 'DOWN', 'timeout': 20000}},
    {'tapOn': {'text': 'Izin', 'below': {'text': f'NIS {nisPI1}.*'}}},
    shot('04-tab-pi-izin'),
    {'scrollUntilVisible': {'element': {'text': 'Cari nama atau NIS'}, 'direction': 'UP', 'timeout': 20000}},
    # Enter (returnKeyType search) menutup keyboard di kedua platform; hideKeyboard Maestro tidak andal di iOS.
    {'tapOn': 'Cari nama atau NIS'}, {'inputText': nisPA1}, {'pressKey': 'Enter'},
    {'extendedWaitUntil': {'visible': 'Tidak ada santri yang cocok.*', 'timeout': 10000}},
    shot('05-pi-cari-nis-pa-kosong'),
    {'tapOn': 'Semua · 5'},
    {'scrollUntilVisible': {'element': {'text': f'NIS {nisPA1}.*'}, 'direction': 'DOWN', 'timeout': 20000}},
    {'assertNotVisible': f'NIS {nisPI1}.*'},
    {'tapOn': {'text': 'Sakit', 'below': {'text': f'NIS {nisPA1}.*'}}},
    shot('06-semua-cari-pa-sakit'),
    {'tapOn': 'Konfirmasi dan kirim'},
    {'tapOn': {'text': '(?i)simpan'}},
    # Pesan sukses ada di panel Ringkasan di bawah daftar.
    {'scrollUntilVisible': {'element': {'text': 'Absensi berhasil disimpan.*'}, 'direction': 'DOWN', 'timeout': 30000}},
    shot('07-tersimpan'),
]

print(f'\n=== {a.platform} perangkat {a.device} ===')
mulai = time.time()
ok(flow('alur-utama', utama), 'Maestro alur utama (login, buka pertemuan, tab, cari, status, simpan dengan saringan aktif)')

MG = sql(f"SELECT id FROM pertemuan_pengajian WHERE jadwal_id={int(JG)} AND tanggal_pertemuan='{T}'").split('\n')
ok(len(MG) == 1 and MG[0] != '', f'Tepat satu pertemuan ({MG})')
MG = int(MG[0]) if MG and MG[0] else 0
ok(sql(f'SELECT COUNT(*) FROM absensi_guru WHERE pertemuan_id={MG}') == '1'
   and sql(f'SELECT COUNT(*) FROM absensi_santri WHERE pertemuan_id={MG}') == '5'
   and sql(f'SELECT COUNT(DISTINCT santri_id) FROM absensi_santri WHERE pertemuan_id={MG}') == '5', 'DB: satu absensi guru, lima santri unik')
st = dict(r.split('\t') for r in sql(f'SELECT santri_id, status FROM absensi_santri WHERE pertemuan_id={MG}').split('\n') if r)
ok(st.get(str(pi1['id'])) == 'Izin' and st.get(str(pa1['id'])) == 'Sakit' and list(st.values()).count('Hadir') == 3,
   f'DB: PI1 Izin, PA1 Sakit, tiga Hadir ({sorted(st.values())})')

log = [json.loads(b) for b in open(os.path.join(a.proksi, 'lalu-lintas.jsonl')) if b.strip()] if os.path.exists(os.path.join(a.proksi, 'lalu-lintas.jsonl')) else []
log = [b for b in log if b.get('t', 0) >= mulai]
put = [b for b in log if b.get('jenis') == 'absensi']
ok(len(put) == 1 and len(put[0]['santri']) == 5 and len({s[0] for s in put[0]['santri']}) == 5,
   f'Proksi: satu PUT absensi memuat lima peserta unik ({len(put)} PUT)')
rinci = [b for b in log if b.get('jenis') == 'rincian']
ok(len(rinci) >= 1 and rinci[0]['kelas'] == [ids['kelas']['PA'], ids['kelas']['PI']], 'Proksi: respons rincian memuat kelas [PA, PI]')
with open(os.path.join(a.out, f'{a.platform}-respons-rincian.json'), 'w') as f:
    json.dump(rinci, f, indent=1)

# Status radio terpilih: Android = simpul induk `checked` berisi teks; iOS = satu simpul
# aksesibilitas `selected` (nilai "radio button, checked").
TERPILIH_IZIN = ({'assertVisible': {'checked': True, 'containsChild': {'text': 'Izin'}, 'below': {'text': f'NIS {nisPI1}.*'}}}
                 if a.platform == 'android' else
                 {'assertVisible': {'text': 'Izin', 'selected': True, 'below': {'text': f'NIS {nisPI1}.*'}}})
ulang = [
    {'stopApp': APP}, {'launchApp': {'stopApp': True}},
    {'extendedWaitUntil': {'visible': 'Beranda', 'timeout': 60000}},
    {'openLink': f'alhasanapps://meeting/{MG}'},
    {'extendedWaitUntil': {'visible': 'Santri · 5 orang', 'timeout': 30000}},
    {'scrollUntilVisible': {'element': {'text': 'PI · 2'}, 'direction': 'DOWN', 'timeout': 20000}},
    {'tapOn': 'PI · 2'},
    {'scrollUntilVisible': {'element': {'text': f'NIS {nisPI1}.*'}, 'direction': 'DOWN', 'timeout': 20000}},
    # Status terpilih ada pada simpul radio (induk teks), bukan pada teksnya.
    TERPILIH_IZIN,
    shot('08-buka-ulang-pi'),
]
ok(flow('buka-ulang', ulang), 'Buka ulang (cold start) + tautan pertemuan: tab PI, Izin PI1 terpilih dari server')

print(f'\nRingkasan: {lulus} lulus, {len(gagal)} gagal')
for g in gagal:
    print(' - ' + g)
sys.exit(0 if not gagal else 1)
