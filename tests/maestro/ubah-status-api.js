// Mengubah status kasus lewat API dari "perangkat lain" untuk memicu konflik versi.
const base = 'http://127.0.0.1:8940/api/v1';
const login = http.post(base + '/auth/login', { headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ username: 'sbx_pengurus_a', password: 'Sandbox#123', device_name: 'maestro-konflik' }) });
const token = json(login.body).data.token;
const auth = { 'Content-Type': 'application/json', Authorization: 'Bearer ' + token };
const kasus = json(http.get(base + '/v3/konseling/kasus/' + KASUS, { headers: auth }).body).data.kasus;
const r = http.post(base + '/v3/konseling/kasus/' + KASUS + '/status', { headers: auth,
  body: JSON.stringify({ version: kasus.version, status: 'Dalam Pendampingan', idempotency_key: 'maestro-' + Date.now() }) });
output.statusKonflik = r.status;
