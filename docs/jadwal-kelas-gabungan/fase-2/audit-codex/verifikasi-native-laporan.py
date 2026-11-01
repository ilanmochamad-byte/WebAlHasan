"""Sesudah uji native: bandingkan DB, API, HTML web dan CSV (fixture lokal saja).
python3 verifikasi-native-laporan.py <ids.json> <test_*_test> <http://127.0.0.1:port>
"""
import csv, http.cookiejar, io, json, re, subprocess, sys, urllib.parse, urllib.request
f = json.load(open(sys.argv[1])); db, base = sys.argv[2:4]
if not re.fullmatch(r'test_.*_test', db) or not re.fullmatch(r'http://127\.0\.0\.1:\d+', base): raise RuntimeError('Fixture lokal saja')
def sql(q): return subprocess.check_output(['mysql', '-N', '-B', db, '-e', q], text=True).strip()
def api(path, body=None, token=None):
    headers = {'Content-Type': 'application/json'}
    if token: headers['Authorization'] = 'Bearer ' + token
    req = urllib.request.Request(base+'/api/v1'+path, data=json.dumps(body).encode() if body else None, headers=headers)
    return json.load(urllib.request.urlopen(req))['data']
passed = failed = 0
def check(v, s):
    global passed, failed
    passed += bool(v); failed += not bool(v); print(('[lulus] ' if v else '[gagal] ')+s)
token = api('/auth/login', {'username':'sbx_guru_biasa','password':'Sandbox#123','device_name':'codex-native-report'})['token']
j = f['jadwal_gabungan']; date = f['tanggal']; mid = int(sql(f"SELECT id FROM pertemuan_pengajian WHERE jadwal_id={j} AND tanggal_pertemuan='{date}'"))
meeting = api(f'/reports/meetings/{mid}', token=token)
q = urllib.parse.urlencode({'date_from':date,'date_to':date,'schedule_id':j,'subject_scope':'gabungan'})
report = api('/reports?'+q, token=token)
check(report['summary']['meeting_count']==1 and report['summary']['teacher_attendance_count']==1 and report['summary']['student_attendance_count']==5 and len(report['schedules'])==1, 'Native → API laporan: satu pertemuan, satu guru, lima santri, satu ringkasan jadwal')
check(sql(f'SELECT COUNT(*) FROM absensi_guru WHERE pertemuan_id={mid}')=='1' and sql(f'SELECT COUNT(*) FROM absensi_santri WHERE pertemuan_id={mid}')=='5' and len({s['student_id'] for s in meeting['students']})==5, 'DB dan rincian API: satu guru dan lima santri unik, termasuk sesudah koreksi/retry')
web = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
page = web.open(base+'/portal/index.php').read().decode()
csrf = re.search(r'name="_csrf" value="([^"]+)"', page).group(1)
web.open(base+'/admin/cek_login.php', urllib.parse.urlencode({'_csrf':csrf,'username':'sbx_admin','password':'Sandbox#123'}).encode()).read()
html = web.open(base+'/admin/admin_laporan_absensi.php?'+q).read().decode()
check(all(html.count(s['name'])==1 for s in meeting['students']), 'Native → laporan HTML admin: tiap santri tepat sekali')
text = web.open(base+'/admin/export_laporan_absensi.php?'+q).read().decode('utf-8-sig')
rows = list(csv.DictReader(io.StringIO(text)))
students = [r for r in rows if r['Jenis Peserta']=='Santri']
teachers = [r for r in rows if r['Jenis Peserta']=='Guru']
by_name = {r['Nama Peserta']:r for r in students}
check(len(rows)==6 and len(teachers)==1 and len(students)==5 and all(s['name'] in by_name and by_name[s['name']]['Kelas']==s['class']['name'] and by_name[s['name']]['Status Absensi']==s['status'] for s in meeting['students']), 'Native → CSV: satu guru + lima santri, kelas asal dan seluruh status sama dengan API')
print(f'Ringkasan: {passed} lulus, {failed} gagal')
sys.exit(1 if failed else 0)
