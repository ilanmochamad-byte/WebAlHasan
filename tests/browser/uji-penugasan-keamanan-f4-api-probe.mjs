import { execFileSync } from 'node:child_process';
import assert from 'node:assert/strict';

// Probe auditor independen, hanya fixture sintetis dan API localhost.
const db = process.env.DB_NAME;
const base = process.env.PKF4_WEB;
assert.match(db ?? '', /^test_.*_test$/);
assert.match(base ?? '', /^http:\/\/127\.0\.0\.1:\d+$/);
const sql = q => execFileSync('mysql', ['-N', '-B', db, '-e', q], { encoding: 'utf8' }).trim();
const scalar = q => Number(sql(q));
let pass = 0, fail = 0;
const check = (ok, label) => { console.log(`${ok ? '[lulus]' : '[gagal]'} ${label}`); ok ? pass++ : fail++; };
async function api(path, token, method = 'GET') {
  const r = await fetch(`${base}/api/v1${path}`, { method, headers: { Authorization: `Bearer ${token}` } });
  const body = await r.text();
  let data = null;
  try { data = body === '' ? null : JSON.parse(body); } catch { data = { invalidJson: true }; }
  return { status: r.status, headers: r.headers, data };
}
async function login(name) {
  const r = await fetch(`${base}/api/v1/auth/login`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ username: name, password: 'Sandbox#123', device_name: 'audit-f4' }) });
  return (await r.json()).data.token;
}
const [k, g, a] = await Promise.all(['sbx_pengurus_biasa', 'sbx_guru_biasa', 'sbx_admin'].map(login));
const uid = name => scalar(`SELECT id FROM users WHERE username='${name}'`);
const ku = uid('sbx_pengurus_biasa'), gu = uid('sbx_guru_biasa'), au = uid('sbx_admin');
const gp = scalar(`SELECT pengurus_id FROM users WHERE id=${gu}`), kp = scalar(`SELECT pengurus_id FROM users WHERE id=${ku}`);
const gg = scalar(`SELECT guru_id FROM users WHERE id=${gu}`);
const other = scalar('SELECT p.id FROM pengurus p WHERE p.guru_id IS NULL AND p.is_active=1 AND p.archived_at IS NULL AND NOT EXISTS(SELECT 1 FROM users u WHERE u.pengurus_id=p.id) LIMIT 1');
assert.ok(other > 0, 'Fixture memerlukan pengurus tanpa akun');
const routes = ['/keamanan/izin', '/keamanan/izin/1', '/keamanan/pelanggaran', '/keamanan/pelanggaran/1', '/keamanan/konseling', '/keamanan/konseling/1', '/keamanan/kehadiran', '/keamanan/kehadiran/pertemuan/1'];
async function denied(token, label) {
  const responses = await Promise.all(routes.map(p => api(p, token)));
  console.log(`${label}: HTTP ${responses.map(r => r.status).join(',')}`);
  check(responses.every(r => r.status === 403 && !r.data.data), `${label}: delapan rute tanpa isi (token lama)`);
  check((await api('/keamanan/akses', token)).data.data.pantau === false, `${label}: ubin gugur`);
}
// Cabut satu dimensi hak, pulihkan di finally: tidak tergantung invalidasi klien.
const revocations = [
  [`DELETE FROM user_roles WHERE user_id=${gu} AND role_id=(SELECT id FROM roles WHERE slug='pengurus')`, `INSERT INTO user_roles(user_id,role_id) SELECT ${gu},id FROM roles WHERE slug='pengurus'`, g, 'role Pengurus guru dicabut'],
  [`UPDATE pengurus SET is_active=0 WHERE id=${kp}`, `UPDATE pengurus SET is_active=1 WHERE id=${kp}`, k, 'master Pengurus nonaktif'],
  [`UPDATE guru SET archived_at=NOW() WHERE id=${gg}`, `UPDATE guru SET archived_at=NULL WHERE id=${gg}`, g, 'master Guru diarsipkan'],
  [`UPDATE users SET pengurus_id=${other} WHERE id=${gu}`, `UPDATE users SET pengurus_id=${gp} WHERE id=${gu}`, g, 'identitas guru dipalsukan ke pengurus orang lain'],
  [`UPDATE keamanan_assignments SET tanggal_mulai=DATE_ADD(CURDATE(),INTERVAL 1 DAY) WHERE pengurus_id=${kp}`, `UPDATE keamanan_assignments SET tanggal_mulai=CURDATE() WHERE pengurus_id=${kp}`, k, 'penugasan belum mulai'],
];
for (const [change, restore, token, label] of revocations) {
  try { sql(change); await denied(token, label); } finally { sql(restore); }
  check((await api('/keamanan/akses', token)).data.data.pantau === true, `${label}: pulih sesudah dikembalikan`);
}
// Semester tidak menggugurkan Keamanan; admin pengawasan tidak menjadi tugas sendiri.
const tahun = scalar("SELECT id FROM tahun_ajaran WHERE status='Aktif' AND archived_at IS NULL ORDER BY id DESC LIMIT 1");
try {
  sql(`UPDATE tahun_ajaran SET status='Non-Aktif' WHERE id=${tahun}`);
  check((await api('/keamanan/akses', k)).data.data.pantau === true && (await api('/keamanan/izin', g)).status === 200, 'Keamanan bertahan saat semester tidak aktif');
} finally { sql(`UPDATE tahun_ajaran SET status='Aktif' WHERE id=${tahun}`); }
check((await api('/profile/identitas', a)).data.data.penugasan.length === 0, 'Seluruh pengawasan admin tidak dipresentasikan sebagai penugasan');
// Tambah admin pada akun K (sumber keduanya); tanpa mengubah penugasan.
try {
  sql(`INSERT INTO user_roles(user_id,role_id) SELECT ${ku},id FROM roles WHERE slug='admin'`);
  check((await api('/keamanan/izin', k)).status === 200 && (await api('/profile/identitas', k)).data.data.penugasan.some(p => p.jenis === 'keamanan'), 'Sumber keduanya (admin + tugas sendiri) tetap sah');
} finally { sql(`DELETE FROM user_roles WHERE user_id=${ku} AND role_id=(SELECT id FROM roles WHERE slug='admin')`); }
// Bentuk filter jahat tidak boleh mengubah cakupan/kerahasiaan.
for (const path of ['/keamanan/izin?asal[]=wali', '/keamanan/pelanggaran?status[]=Dicatat', '/keamanan/konseling?page[]=1',
  ...['date_from', 'date_to', 'academic_year_id', 'class_id', 'teacher_id', 'schedule_id', 'status', 'subject_scope', 'page', 'per_page'].map(key => `/keamanan/kehadiran?${key}[]=2026-01-01`)]) {
  const result = await api(path, k);
  check(result.status === 422 && !result.data?.invalidJson, `Array filter ditolak JSON 422: ${path}`);
}
const extra = '&peran=admin&subject_scope=all&include_rahasia=1&santri_id=4&cakupan=seluruh';
const normal = await api('/keamanan/konseling', k), injected = await api('/keamanan/konseling?' + extra.slice(1), k);
check(JSON.stringify(normal.data) === JSON.stringify(injected.data), 'Parameter asing tidak mengubah proyeksi konseling');
const attendance = await api('/keamanan/kehadiran?date_from=2020-01-01&date_to=2027-12-31&subject_scope=gabungan', k);
const activeStudents = sql('SELECT id FROM santri WHERE is_active=1 AND archived_at IS NULL').split('\n').map(Number);
check(attendance.status === 200 && attendance.data.data.items.every(r => r.subject_type !== 'Santri' || activeStudents.includes(r.subject_id)), 'Penyajian gabungan seperti web tetap menyaring santri aktif');
// HEAD dan OPTIONS juga hanya ditolak; audit dan semua tabel bisnis utuh.
const tables = sql('SHOW TABLES').split('\n').filter(t => !['api_tokens', 'audit_logs', 'auth_rate_limits'].includes(t));
const fingerprint = () => Object.fromEntries(tables.map(t => [t, sql(`CHECKSUM TABLE \`${t}\` EXTENDED`)]));
const before = fingerprint();
const auditBefore = scalar("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'keamanan.%'");
for (const method of ['HEAD', 'OPTIONS', 'POST', 'PUT', 'PATCH', 'DELETE']) {
  const results = await Promise.all(routes.map(p => api(p, k, method)));
  check(results.every(r => r.status === 405 && r.headers.get('allow') === 'GET'), `${method} ke delapan rute: 405 Allow GET`);
}
const after = fingerprint();
const changed = tables.filter(t => before[t] !== after[t]);
console.log(`Tabel berubah saat metode ditolak: ${changed.join(',') || '(tidak ada)'}`);
check(changed.length === 0 && scalar("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'keamanan.%'") === auditBefore, 'Penolakan metode tidak mengubah seluruh tabel bisnis maupun audit Keamanan');
// Logout membatalkan token lama, termasuk endpoint identitas.
await api('/auth/logout', g, 'POST');
check((await api('/keamanan/izin', g)).status === 401 && (await api('/profile/identitas', g)).status === 401, 'Logout mencabut pembacaan dan identitas dari token lama');
console.log(`Hasil: ${pass} lulus, ${fail} gagal`);
process.exitCode = fail ? 1 : 0;
