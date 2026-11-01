"""Simulator iOS, binary Release lama + bundel Hermes HEAD buatan auditor.

Prasyarat: fixture F1 bersih + f2_seed; API 8963, proksi 8964; bundle localhost.
python3 uji-ios.py /path/ids.json /path/output
Bukan build Release baru/distribusi atau perangkat fisik.
"""
import json, os, pathlib, subprocess, sys

IDS = pathlib.Path(sys.argv[1]); OUT = pathlib.Path(sys.argv[2]); OUT.mkdir(parents=True, exist_ok=True)
f = json.loads(IDS.read_text()); DB = 'test_jkg_f2_ios_codex_test'
APP = 'com.ilanmochamad.alhasanApps'; DEVICE = '05999342-AE62-44C4-B0E1-1E8504CF8512'
PROXY = OUT.parent / 'proxy-ios'; J = f['jadwal_gabungan']; JS = f['jadwal_satu']; DATE = f['tanggal']
ok = bad = 0
def sql(q): return subprocess.check_output(['mysql', '-N', '-B', DB, '-e', q], text=True).strip()
def check(v, s):
    global ok, bad
    ok += bool(v); bad += not bool(v); print(('[lulus] ' if v else '[gagal] ') + s, flush=True)
def flow(name, commands):
    file = OUT / (name + '.yaml'); file.write_text('appId: ' + APP + '\n---\n' + json.dumps(commands, ensure_ascii=False))
    with (OUT / (name + '.txt')).open('w') as log:
        result = subprocess.run(['maestro', '-p', 'ios', '--udid', DEVICE, 'test', '--test-output-dir', str(OUT / name), str(file)], stdout=log, stderr=subprocess.STDOUT)
    if result.returncode: raise RuntimeError(name + ' gagal')
def wait(s): return {'extendedWaitUntil': {'visible': s, 'timeout': 20000}}
def tap(s): return {'tapOn': {'point': '50%,92%'}} if s == 'Buka pertemuan' else {'tapOn': s}
def scroll(s): return {'scrollUntilVisible': {'element': {'text': s}, 'direction': 'DOWN', 'timeout': 30000}}
def deep(path): subprocess.run(['xcrun', 'simctl', 'openurl', DEVICE, 'alhasanapps://' + path], check=True)
def flag(s): (PROXY / s).touch()
def mutations():
    file = PROXY / 'kiriman.jsonl'
    return [x for x in map(json.loads, file.read_text().splitlines()) if 'kunci' in x] if file.exists() else []
def mid(j): return sql(f"SELECT id FROM pertemuan_pengajian WHERE jadwal_id={j} AND tanggal_pertemuan='{DATE}'")
try:
    if os.environ.get('IOS_SKIP_LOGIN') != '1': flow('01-login', [{'launchApp': {'stopApp': True}}, wait('Masukkan username'), tap('Masukkan username'), 'eraseText', {'inputText': 'sbx_guru_biasa'}, tap('Masuk ke akun Anda'), tap('Masukkan password'), 'eraseText', *[{'inputText': c} for c in 'Sandbox#123'], {'hideKeyboard': {}}, tap('Masuk'), {'runFlow': {'when': {'visible': 'Not Now'}, 'commands': [tap('Not Now')]}}, wait('Beranda')])
    deep(f'schedule/{J}?date={DATE}')
    flow('02-detail', [wait('Kelas gabungan'), {'assertVisible': f"(?s).*{f['nama_kelas']['PA']}.*{f['nama_kelas']['PI']}.*"}, {'assertVisible': 'Satu pertemuan untuk 2 kelas.*'}, {'takeScreenshot': 'ios-detail'}])
    check(mid(J) == '', 'iOS detail dua kelas; tanpa pembukaan otomatis')
    flag('gagal-buka'); flag('tunda')
    flow('03-buka-hilang', [{'tapOn': {'point': '50%,92%', 'repeat': 2, 'delay': 100}}, wait('Uji: gerbang gagal sesudah diproses.')])
    (PROXY/'tunda').unlink(missing_ok=True)
    check(len(mutations()) == 1 and bool(mid(J)), 'iOS ketukan ganda: satu POST; server commit walau menerima 503')
    flow('04-buka-retry', [tap('Buka pertemuan'), wait('Santri · 5 orang'), {'assertVisible': 'Kelas gabungan · 2 kelas dalam satu pertemuan'}, {'takeScreenshot': 'ios-gabungan'}])
    M = mid(J); m = mutations()
    check(len(m) == 2 and m[0]['kunci'] == m[1]['kunci'] and sql(f'SELECT COUNT(*) FROM pertemuan_kelas WHERE pertemuan_id={M}') == '2', 'iOS retry POST kunci sama, satu pertemuan dan dua kelas beku')
    for group in ['PA', 'PI']:
        for s in f['santri_nama'][group]:
            flow('05-peserta-'+str(s['id']), [scroll(s['nama']), {'assertVisible': 'NIS .* · Kelas ' + f['nama_kelas'][group]}])
    check(sql(f'SELECT COUNT(DISTINCT santri_id) FROM pertemuan_peserta WHERE pertemuan_id={M}') == '5', 'iOS kelima peserta terlihat dengan label kelas asal, lima ID unik')
    flag('gagal-absen'); flag('tunda')
    flow('06-absen-hilang', [tap('Konfirmasi dan kirim'), tap('Simpan'), scroll('Uji: gerbang gagal sesudah diproses.')])
    (PROXY/'tunda').unlink(missing_ok=True)
    check(len(mutations()) == 3 and sql(f'SELECT COUNT(*) FROM absensi_santri WHERE pertemuan_id={M}') == '5', 'iOS PUT server commit, jawaban 503')
    flow('07-absen-retry', [tap('Konfirmasi dan kirim'), tap('Simpan'), scroll('Absensi berhasil disimpan.*'), {'assertVisible': 'Simpan koreksi'}, {'takeScreenshot': 'ios-tersimpan'}])
    m = mutations()
    check(len(m) == 4 and m[2]['kunci'] == m[3]['kunci'] and sql(f'SELECT COUNT(*) FROM absensi_guru WHERE pertemuan_id={M}') == '1' and sql(f'SELECT COUNT(*) FROM absensi_santri WHERE pertemuan_id={M}') == '5', 'iOS retry PUT kunci sama, tepat satu guru + lima santri')
    flow('08-koreksi', [tap('Simpan koreksi'), tap('Simpan'), scroll('Alasan koreksi wajib diisi.*')])
    check(len(mutations()) == 4, 'iOS pertemuan Selesai tanpa alasan tidak mengirim koreksi')
    flow('09-koreksi-beralasan', [tap('Wajib diisi untuk menyimpan koreksi'), {'inputText': 'Koreksi audit iOS'}, {'hideKeyboard': {}}, tap('Simpan koreksi'), tap('Simpan'), scroll('Absensi berhasil disimpan.*')])
    check(len(mutations()) == 5 and mutations()[-1]['kunci'] != mutations()[-2]['kunci'], 'iOS koreksi beralasan berkunci baru tanpa tambahan baris')
    deep(f'schedule/{JS}?date={DATE}')
    flow('10-satu-kelas', [wait('Daftar santri akan dibekukan.*'), {'assertNotVisible': 'Kelas gabungan'}, tap('Buka pertemuan'), wait('Santri · 2 orang'), {'assertNotVisible': 'Kelas gabungan.*'}, {'takeScreenshot': 'ios-satu'}, tap('Konfirmasi dan kirim'), tap('Simpan'), scroll('Absensi berhasil disimpan.*')])
    S = mid(JS)
    check(bool(S) and sql(f'SELECT COUNT(*) FROM absensi_santri WHERE pertemuan_id={S}') == '2' and sql(f'SELECT COUNT(*) FROM absensi_guru WHERE pertemuan_id={S}') == '1', 'iOS satu kelas: tampilan lama, satu guru + dua santri')
    deep(f'meeting/{M}')
    flow('11-sesi-persiapan', [wait('Simpan koreksi'), scroll('Wajib diisi untuk menyimpan koreksi'), tap('Wajib diisi untuk menyimpan koreksi'), {'inputText': 'Koreksi sesi habis'}, {'hideKeyboard': {}}])
    before = sql(f'SELECT GROUP_CONCAT(id,":",status ORDER BY id) FROM absensi_santri WHERE pertemuan_id={M}')
    sql(f"UPDATE api_tokens SET expires_at=DATE_SUB(NOW(),INTERVAL 1 DAY) WHERE user_id={f['guru_user_id']}")
    flow('12-sesi-habis', [tap('Simpan koreksi'), tap('Simpan'), wait('Masukkan username')])
    check(sql(f'SELECT GROUP_CONCAT(id,":",status ORDER BY id) FROM absensi_santri WHERE pertemuan_id={M}') == before, 'iOS token kedaluwarsa: login kembali tanpa mengubah absensi')
except BaseException as e:
    bad += 1; print('[gagal] ' + str(e), flush=True)
finally:
    (PROXY/'tunda').unlink(missing_ok=True)
    print(f'Ringkasan: {ok} lulus, {bad} gagal', flush=True)
    sys.exit(1 if bad else 0)
