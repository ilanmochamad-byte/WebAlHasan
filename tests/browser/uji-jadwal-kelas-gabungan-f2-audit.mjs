// Probe auditor: jalankan sesudah fixture F1 baru + f2_seed; Expo WEB + API nyata.
// DB_NAME=test_*_test JKG_WEB=http://127.0.0.1:8961 JKG_APP=http://127.0.0.1:8098
// SEED_JSON=/path/ids.json node tests/browser/uji-jadwal-kelas-gabungan-f2-audit.mjs
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';
import { chromium } from 'playwright';

const db = process.env.DB_NAME, web = process.env.JKG_WEB, app = process.env.JKG_APP;
if (!/^test_.*_test$/.test(db || '') || ![web, app].every(x => /^http:\/\/127\.0\.0\.1:\d+$/.test(x || ''))) throw Error('Hanya fixture dan localhost');
const ids = JSON.parse(fs.readFileSync(process.env.SEED_JSON, 'utf8'));
const root = new URL('../../', import.meta.url).pathname;
const sql = q => execFileSync('mysql', ['-N', '-B', db, '-e', q], { encoding: 'utf8' }).trim();
let pass = 0, fail = 0, external = 0;
function check(v, s) { v ? pass++ : fail++; console.log(`[${v ? 'lulus' : 'gagal'}] ${s}`); }
const tokens = {};
async function api(user, path, body, method = 'GET') {
  if (!tokens[user]) {
    const r = await fetch(web + '/api/v1/auth/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ username: user, password: 'Sandbox#123', device_name: 'codex-probe' }) });
    tokens[user] = (await r.json()).data.token;
  }
  const r = await fetch(web + '/api/v1' + path, { method, headers: { Authorization: `Bearer ${tokens[user]}`, 'Content-Type': 'application/json' }, ...(body ? { body: JSON.stringify(body) } : {}) });
  return { status: r.status, data: (await r.json()).data };
}
const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 375, height: 812 } });
const page = await ctx.newPage();
const mutations = [];
page.on('dialog', d => d.accept());
await page.route('**/*', async route => {
  const req = route.request(), u = new URL(req.url());
  if (u.hostname !== '127.0.0.1') { external++; return route.abort(); }
  if (u.origin !== web) return route.fallback();
  const headers = { 'Access-Control-Allow-Origin': app, 'Access-Control-Allow-Headers': 'authorization,content-type', 'Access-Control-Allow-Methods': 'GET,POST,PUT' };
  if (req.method() === 'OPTIONS') return route.fulfill({ status: 204, headers });
  if (['POST', 'PUT'].includes(req.method()) && /\/(meetings|attendance)$/.test(u.pathname)) mutations.push({ path: u.pathname, body: JSON.parse(req.postData()), method: req.method() });
  const response = await route.fetch();
  return route.fulfill({ response, headers: { ...response.headers(), ...headers } });
});
const text = () => page.locator('body').innerText();
const wait = s => page.getByText(s, { exact: false }).first().waitFor();
const button = s => page.getByRole('button', { name: s, exact: true });
const G = 'sbx_guru_biasa', J = ids.jadwal_gabungan, D = ids.tanggal;
try {
  await page.goto(app + '/login');
  await page.getByPlaceholder('Masukkan username').fill(G);
  await page.getByPlaceholder('Masukkan password').fill('Sandbox#123');
  await button('Masuk').click();
  await page.waitForURL(u => u.pathname !== '/login');
  await page.goto(`${app}/schedule/${J}?date=${D}`);
  await button('Buka pertemuan').waitFor();
  check(sql(`SELECT COUNT(*) FROM pertemuan_pengajian WHERE jadwal_id=${J}`) === '0', 'Layar awal tidak membuka pertemuan');
  // Perangkat lain mendahului saat layar masih menampilkan belum dibuka.
  const other = await api(G, `/schedules/${J}/meetings`, { date: D, notes: '', idempotency_key: 'codex-other-' + ids.sfx }, 'POST');
  check(other.status === 201, 'Perangkat lain membuka satu pertemuan melalui API');
  const mid = other.data.id;
  await button('Buka pertemuan').click();
  await button('Isi absensi').waitFor();
  check((await text()).includes('sudah') && mutations.length === 1, '409 memuat ulang status dan menampilkan Isi absensi, tanpa POST tambahan');
  await button('Isi absensi').click();
  await wait('Santri · 5 orang');
  check(new URL(page.url()).pathname === `/meeting/${mid}`, '409 membuka ID pertemuan yang dibuat perangkat lain');
  const old = (await api(G, `/meetings/${mid}`)).data;
  const change = `require $argv[1].'/app/bootstrap.php'; $f=json_decode(file_get_contents($argv[2]),true); $r=app_db()->query('SELECT * FROM jadwal_ngaji WHERE id='.(int)$f['jadwal_gabungan'])->fetch_assoc(); schedule_service()->save(array_replace($r,['id_kelas'=>$f['kelas']['C'],'kelas_gabungan'=>[],'waktu_mulai'=>substr($r['waktu_mulai'],0,5),'waktu_selesai'=>substr($r['waktu_selesai'],0,5)]),$f['admin_id'],$f['jadwal_gabungan']);`;
  execFileSync('php', ['-r', change, root, process.env.SEED_JSON], { env: { ...process.env, DB_NAME: db } });
  await page.reload();
  await wait('Santri · 5 orang');
  let t = await text();
  check(t.includes(ids.nama_kelas.PA) && t.includes(ids.nama_kelas.PI) && !t.includes(ids.nama_kelas.C), 'Pertemuan menampilkan kelas beku PA+PI setelah jadwal diganti C');
  const current = (await api(G, `/meetings/${mid}`)).data;
  check(current.task.class.id === ids.kelas.C && JSON.stringify(current.task.classes) === JSON.stringify(old.task.classes), 'Field kompatibilitas kini C, classes pertemuan tetap snapshot');
  // Status dan catatan berbeda untuk SETIAP peserta membuktikan grouping tidak menukar ID.
  const statuses = ['Hadir', 'Terlambat', 'Izin', 'Sakit', 'Alpa'];
  for (const [i, s] of current.students.entries()) {
    const card = page.locator('div').filter({ has: page.getByText(s.name, { exact: true }) }).filter({ has: page.getByRole('radiogroup') }).last();
    await card.getByRole('radio', { name: statuses[i], exact: true }).click();
    await card.getByPlaceholder('Catatan santri (opsional)').fill('Catatan ID ' + s.student_id);
  }
  await page.getByRole('radiogroup').first().getByRole('radio', { name: 'Terlambat', exact: true }).click();
  await button('Konfirmasi dan kirim').click();
  await wait('Absensi berhasil disimpan');
  const saved = (await api(G, `/meetings/${mid}`)).data;
  check(saved.teacher_attendance.status === 'Terlambat' && saved.students.every((s, i) => s.attendance.status === statuses[i] && s.attendance.notes === 'Catatan ID ' + s.student_id), 'Grouping mempertahankan ID, lima status, catatan tiap santri dan satu status guru');
  check(sql(`SELECT COUNT(*) FROM absensi_guru WHERE pertemuan_id=${mid}`) === '1' && sql(`SELECT COUNT(*) FROM absensi_santri WHERE pertemuan_id=${mid}`) === '5', 'Satu guru dan satu absensi per santri setelah grouping');
  const before = mutations.length;
  await page.goto(`${app}/schedule/${J}?date=${D}`);
  await button('Buka absensi tersimpan').waitFor();
  check(!(await text()).includes('Kelas gabungan') && (await text()).includes(ids.nama_kelas.C), 'Detail jadwal mengikuti kelas kini C dan pertemuan Selesai');
  await button('Buka absensi tersimpan').click();
  await button('Simpan koreksi').waitFor();
  check(mutations.length === before && (await text()).includes(ids.nama_kelas.PI), 'Pertemuan Selesai dibuka kembali tanpa POST, dengan kelas historis');
  await page.goto(app + `/report/${mid}`); await wait('2 kelas dalam satu pertemuan');
  t = await text();
  check(!t.includes(ids.nama_kelas.C) && saved.students.every(s => t.includes(`Kelas ${s.class.name}`)), 'Laporan aplikasi menggunakan kelas beku dan kelas asal, bukan kelas kini');
  const rpt = (await api(G, `/reports/meetings/${mid}`)).data;
  check(rpt.students.every((s, i) => s.status === statuses[i] && s.notes === 'Catatan ID ' + s.student_id) && rpt.teacher_attendance.status === 'Terlambat', 'Rincian API laporan sama dengan lima status/catatan dan guru dari aplikasi');
  const denied = await api(ids.murobi_user, `/schedules/${J}/meetings`, { date: D, notes: '', idempotency_key: 'codex-foreign-' + ids.sfx }, 'POST');
  check([403, 404].includes(denied.status) && sql(`SELECT COUNT(*) FROM pertemuan_pengajian WHERE jadwal_id=${J}`) === '1', 'POST pembukaan oleh guru lain ditolak, tetap satu pertemuan');
  await page.goto(app + `/meeting/${mid}`); await button('Simpan koreksi').waitFor();
  await page.getByPlaceholder('Wajib diisi untuk menyimpan koreksi').fill('Uji token kedaluwarsa');
  const hashBefore = sql(`SELECT GROUP_CONCAT(santri_id,':',status,':',catatan ORDER BY santri_id) FROM absensi_santri WHERE pertemuan_id=${mid}`);
  sql(`UPDATE api_tokens SET expires_at=DATE_SUB(NOW(),INTERVAL 1 DAY) WHERE user_id=${ids.guru_user_id} AND name<>'codex-probe'`);
  await button('Simpan koreksi').click(); await page.waitForURL(u => u.pathname === '/login');
  check(sql(`SELECT GROUP_CONCAT(santri_id,':',status,':',catatan ORDER BY santri_id) FROM absensi_santri WHERE pertemuan_id=${mid}`) === hashBefore, 'Token expires_at lampau: kembali ke login tanpa perubahan absensi');
  check(external === 0, 'Probe tidak mengakses host luar');
} catch (e) { check(false, e.message); }
finally { await browser.close(); }
console.log(`Ringkasan: ${pass} lulus, ${fail} gagal`);
process.exit(fail ? 1 : 0);
