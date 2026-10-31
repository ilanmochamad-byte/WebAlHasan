"""Proksi uji lokal Fase 2 kelas gabungan (native Android/iOS).

Meneruskan 127.0.0.1:<PORT> → API uji 127.0.0.1:<HULU>, mencatat setiap mutasi
pengajian (metode, alamat, kunci idempotensi) ke <DIR>/kiriman.jsonl, dan dapat
"memutus jaringan" sekali: bila berkas penanda <DIR>/putus-buka atau
<DIR>/putus-absen ada, permintaan pembukaan pertemuan / absensi berikutnya
DITERUSKAN ke server (server memproses), lalu sambungan ke aplikasi ditutup
tanpa jawaban. Penanda dihapus sesudah dipakai. Selama <DIR>/tunda ada, setiap
mutasi pengajian ditahan 3 detik sebelum diteruskan (agar ketukan kedua jatuh
ketika permintaan pertama masih berjalan). Penanda <DIR>/gagal-buka atau
<DIR>/gagal-absen: permintaan DITERUSKAN dan diproses server, lalu aplikasi
menerima 503 (jawaban asli hilang, mis. gerbang/proksi gagal); dipakai karena
klien HTTP Android (OkHttp) dapat mengirim ulang sendiri POST yang sambungannya
ditutup tanpa jawaban.

    python3 tests/android/jkg_f2_proksi.py 8962 8961 <DIR>
Hanya 127.0.0.1; tidak pernah meneruskan ke host lain.
"""
import http.client, http.server, json, os, re, socketserver, sys, time

PORT, HULU, DIR = int(sys.argv[1]), int(sys.argv[2]), sys.argv[3]
os.makedirs(DIR, exist_ok=True)
BUKA = re.compile(r'^/api/v1/schedules/\d+/meetings$')
ABSEN = re.compile(r'^/api/v1/meetings/\d+/attendance$')
HOP = {'connection', 'keep-alive', 'transfer-encoding', 'content-length', 'te', 'trailer', 'upgrade'}


class Penerus(http.server.BaseHTTPRequestHandler):
    protocol_version = 'HTTP/1.1'

    def log_message(self, *a):
        pass

    def _teruskan(self):
        panjang = int(self.headers.get('Content-Length') or 0)
        badan = self.rfile.read(panjang) if panjang else None
        jalur = self.path.split('?')[0]
        penanda = None
        if self.command == 'POST' and BUKA.match(jalur):
            penanda = 'putus-buka'
        elif self.command == 'PUT' and ABSEN.match(jalur):
            penanda = 'putus-absen'
        if penanda:
            try:
                kunci = json.loads(badan or b'{}').get('idempotency_key')
            except ValueError:
                kunci = None
            with open(os.path.join(DIR, 'kiriman.jsonl'), 'a') as f:
                f.write(json.dumps({'metode': self.command, 'jalur': jalur, 'kunci': kunci}) + '\n')
        if penanda and os.path.exists(os.path.join(DIR, 'tunda')):
            time.sleep(3)
        hulu = http.client.HTTPConnection('127.0.0.1', HULU, timeout=60)
        kepala = {k: v for k, v in self.headers.items() if k.lower() not in HOP and k.lower() != 'host'}
        hulu.request(self.command, self.path, body=badan, headers=kepala)
        jawab = hulu.getresponse()
        isi = jawab.read()
        gagal = penanda.replace('putus', 'gagal') if penanda else None
        if gagal and os.path.exists(os.path.join(DIR, gagal)):
            os.remove(os.path.join(DIR, gagal))
            with open(os.path.join(DIR, 'kiriman.jsonl'), 'a') as f:
                f.write(json.dumps({'gagal': gagal, 'status_server': jawab.status}) + '\n')
            isi = json.dumps({'success': False, 'data': None, 'error': {'code': 'UPSTREAM', 'message': 'Uji: gerbang gagal sesudah diproses.'}}).encode()
            self.send_response(503)
            self.send_header('Content-Type', 'application/json')
            self.send_header('Content-Length', str(len(isi)))
            self.end_headers()
            self.wfile.write(isi)
            return
        if penanda and os.path.exists(os.path.join(DIR, penanda)):
            os.remove(os.path.join(DIR, penanda))
            with open(os.path.join(DIR, 'kiriman.jsonl'), 'a') as f:
                f.write(json.dumps({'putus': penanda, 'status_server': jawab.status}) + '\n')
            self.close_connection = True
            self.connection.close()
            return
        self.send_response(jawab.status)
        for k, v in jawab.getheaders():
            if k.lower() not in HOP:
                self.send_header(k, v)
        self.send_header('Content-Length', str(len(isi)))
        self.end_headers()
        self.wfile.write(isi)

    do_GET = do_POST = do_PUT = do_DELETE = do_PATCH = do_OPTIONS = _teruskan


class Server(socketserver.ThreadingMixIn, http.server.HTTPServer):
    daemon_threads = True
    allow_reuse_address = True


Server(('127.0.0.1', PORT), Penerus).serve_forever()
