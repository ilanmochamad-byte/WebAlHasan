"""Proksi uji lokal paket pencarian santri (native Android/iOS).

Meneruskan 127.0.0.1:<PORT> → API uji 127.0.0.1:<HULU> dan mencatat ke
<DIR>/lalu-lintas.jsonl, tanpa nama santri:
  - GET /api/v1/meetings/{id}: SHA-256 isi respons + jumlah kelas/peserta dan
    pasangan (student_id, class.id) — untuk membuktikan Android dan iOS
    menerima respons yang sama;
  - PUT /api/v1/meetings/{id}/attendance: daftar student_id + status dari
    muatan (tanpa catatan) dan kunci idempotensi.
Header Authorization tidak pernah dicatat. Hanya 127.0.0.1.

    python3 tests/android/pcs_proksi.py 8962 8961 <DIR>
"""
import hashlib, http.client, http.server, json, os, re, socketserver, sys, time

PORT, HULU, DIR = int(sys.argv[1]), int(sys.argv[2]), sys.argv[3]
os.makedirs(DIR, exist_ok=True)
RINCI = re.compile(r'^/api/v1/meetings/(\d+)$')
ABSEN = re.compile(r'^/api/v1/meetings/(\d+)/attendance$')
HOP = {'connection', 'keep-alive', 'transfer-encoding', 'content-length', 'te', 'trailer', 'upgrade'}


def catat(baris):
    with open(os.path.join(DIR, 'lalu-lintas.jsonl'), 'a', encoding='utf-8') as f:
        f.write(json.dumps(baris, ensure_ascii=False) + '\n')


class Penerus(http.server.BaseHTTPRequestHandler):
    protocol_version = 'HTTP/1.1'

    def log_message(self, *args):
        pass

    def _teruskan(self):
        panjang = int(self.headers.get('Content-Length') or 0)
        isi = self.rfile.read(panjang) if panjang else b''
        jalur = self.path.split('?')[0]
        conn = http.client.HTTPConnection('127.0.0.1', HULU, timeout=60)
        kepala = {k: v for k, v in self.headers.items() if k.lower() not in HOP and k.lower() != 'host'}
        kepala['Host'] = f'127.0.0.1:{HULU}'
        conn.request(self.command, self.path, body=isi or None, headers=kepala)
        r = conn.getresponse()
        badan = r.read()
        if self.command == 'GET' and RINCI.match(jalur) and r.status == 200:
            try:
                d = json.loads(badan)['data']
                catat({'t': time.time(), 'jenis': 'rincian', 'path': jalur, 'sha256': hashlib.sha256(badan).hexdigest(),
                       'kelas': [c.get('id') for c in (d.get('task', {}).get('classes') or [])],
                       'peserta': [[s['student_id'], (s.get('class') or {}).get('id')] for s in d.get('students', [])],
                       'status': d.get('status')})
            except Exception as e:  # noqa: BLE001
                catat({'jenis': 'rincian-galat', 'galat': str(e)})
        if self.command == 'PUT' and ABSEN.match(jalur):
            try:
                b = json.loads(isi or b'{}')
                catat({'t': time.time(), 'jenis': 'absensi', 'path': jalur, 'status_http': r.status,
                       'kunci': b.get('idempotency_key'), 'guru': (b.get('teacher') or {}).get('status'),
                       'santri': [[s['student_id'], s['status']] for s in b.get('students', [])]})
            except Exception as e:  # noqa: BLE001
                catat({'jenis': 'absensi-galat', 'galat': str(e)})
        self.send_response(r.status)
        for k, v in r.getheaders():
            if k.lower() not in HOP:
                self.send_header(k, v)
        self.send_header('Content-Length', str(len(badan)))
        self.end_headers()
        self.wfile.write(badan)
        conn.close()

    do_GET = do_POST = do_PUT = do_PATCH = do_DELETE = do_OPTIONS = _teruskan


class Server(socketserver.ThreadingMixIn, http.server.HTTPServer):
    daemon_threads = True
    allow_reuse_address = True


Server(('127.0.0.1', PORT), Penerus).serve_forever()
