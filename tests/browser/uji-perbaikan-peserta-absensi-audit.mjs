import { chromium } from 'playwright';
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
const web = process.env.PPA_WEB, app = process.env.PPA_APP, db = process.env.DB_NAME;
if (![web, app].every(u => /^http:\/\/127\.0\.0\.1:\d+$/.test(u || '')) || !/^test_.*_test$/.test(db || '')) throw Error('Hanya fixture localhost');
const ids = JSON.parse(readFileSync(process.env.SEED_JSON));
let pass = 0, fail = 0;
const ok = (v, label) => { console.log(`[${v ? 'lulus' : 'gagal'}] ${label}`); v ? pass++ : fail++; };
const sql = q => execFileSync('mysql', ['-N', '-B', db, '-e', q], { encoding: 'utf8' }).trim();
async function call(method, path, token, body) { const r = await fetch(web + '/api/v1' + path, { method, headers: { 'Content-Type': 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) }, ...(body ? { body: JSON.stringify(body) } : {}) }); return { status: r.status, json: await r.json() }; }
async function login(username) { return (await call('POST', '/auth/login', null, { username, password: 'Sandbox#123', device_name: 'audit-ppa' })).json.data.token; }
const token = await login('sbx_guru_biasa');
const date = new Date(ids.tanggal + 'T12:00:00Z'); date.setUTCDate(date.getUTCDate() + 7);
const opened = await call('POST', `/schedules/${ids.jadwal_gabungan}/meetings`, token, { date: date.toISOString().slice(0, 10), idempotency_key: 'ppa-audit-' + ids.sfx });
const mid = opened.json.data.id, endpoint = `/meetings/${mid}/participants/refresh`;
const browser = await chromium.launch();
try {
  ok((await call('POST', endpoint, null, {})).status === 401, 'Tanpa token ditolak 401');
  for (const user of ['sbx_pengurus_biasa', 'sbx_murobi_a']) ok((await call('POST', endpoint, await login(user), {})).status === 403, `${user} ditolak 403`);
  ok((await call('POST', '/meetings/999999999/participants/refresh', token, {})).status === 404, 'ID tidak ada ditolak 404');
  const audit = () => Number(sql(`SELECT COUNT(*) FROM audit_logs WHERE action='api.meeting.refresh_participants' AND entity_id=${mid}`));
  const n = audit();
  ok((await call('GET', endpoint, token)).status === 404 && audit() === n, 'GET tidak bermutasi');
  const ctx = await browser.newContext({ viewport: { width: 375, height: 812 } });
  await ctx.route('**/*', async route => {
    const req = route.request(), u = new URL(req.url());
    if (u.hostname !== '127.0.0.1') return route.abort();
    if (u.origin !== web || !u.pathname.startsWith('/api/v1')) return route.fallback();
    const headers = { 'Access-Control-Allow-Origin': app, 'Access-Control-Allow-Headers': 'authorization,content-type', 'Access-Control-Allow-Methods': 'GET,POST,PUT' };
    if (req.method() === 'OPTIONS') return route.fulfill({ status: 204, headers });
    const response = await route.fetch(); return route.fulfill({ response, headers: { ...response.headers(), ...headers } });
  });
  const page = await ctx.newPage(); page.on('dialog', d => d.accept());
  await page.goto(app + '/login');
  await page.getByPlaceholder('Masukkan username').fill('sbx_guru_biasa'); await page.getByPlaceholder('Masukkan password').fill('Sandbox#123');
  await page.getByRole('button', { name: 'Masuk', exact: true }).click(); await page.waitForURL(u => u.pathname !== '/login');
  await page.goto(`${app}/meeting/${mid}`);
  const button = name => page.getByRole('button', { name, exact: true });
  await button('Perbarui daftar').waitFor();
  const pa = ids.santri_nama.PA[1];
  const card = page.locator('div').filter({ has: page.getByText(pa.nama, { exact: true }) }).filter({ has: page.getByRole('radiogroup') }).last();
  await card.getByRole('radio', { name: 'Sakit' }).click(); await card.getByPlaceholder('Catatan santri (opsional)').fill('Catatan draf audit');
  await page.getByPlaceholder('Catatan guru (opsional)').fill('Catatan guru audit');
  let posts = 0;
  await page.route(`**/meetings/${mid}/participants/refresh`, async route => {
    if (route.request().method() !== 'POST') return route.fallback();
    posts++;
    if (posts === 1) return route.fulfill({ status: 503, contentType: 'application/json', headers: { 'Access-Control-Allow-Origin': app }, body: JSON.stringify({ success: false, error: { code: 'SERVER_ERROR', message: 'Fixture sementara tidak tersedia' } }) });
    await new Promise(r => setTimeout(r, 600)); return route.fallback();
  });
  await button('Perbarui daftar').click(); await page.getByText('Fixture sementara tidak tersedia', { exact: false }).waitFor();
  const draft = async () => await card.getByRole('radio', { name: 'Sakit' }).getAttribute('aria-checked') === 'true' && await card.getByPlaceholder('Catatan santri (opsional)').inputValue() === 'Catatan draf audit';
  ok(await draft(), 'Galat 503 mempertahankan status dan catatan draf');
  await button('Perbarui daftar').evaluate(el => { el.click(); el.click(); });
  ok(await button('Konfirmasi dan kirim').isDisabled(), 'Kirim dinonaktifkan selama refresh');
  await page.getByText('Daftar santri sudah sesuai', { exact: false }).waitFor();
  ok(posts === 2, 'Ketukan ganda menghasilkan satu POST tambahan');
  ok(await draft() && await page.getByPlaceholder('Catatan guru (opsional)').inputValue() === 'Catatan guru audit', 'Retry sukses mempertahankan seluruh draf');
  for (const width of [375, 768, 1280]) { await page.setViewportSize({ width, height: 812 }); ok(await button('Perbarui daftar').isVisible() && await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `Aplikasi ${width}px tanpa overflow`); }
  await button('Konfirmasi dan kirim').click(); await page.getByText('Absensi berhasil disimpan', { exact: false }).waitFor();
  ok(sql(`SELECT CONCAT(status,'|',catatan) FROM absensi_santri WHERE pertemuan_id=${mid} AND santri_id=${pa.id}`) === 'Sakit|Catatan draf audit', 'DB menyimpan status dan catatan santri setelah retry');
  ok(sql(`SELECT catatan FROM absensi_guru WHERE pertemuan_id=${mid}`) === 'Catatan guru audit', 'DB menyimpan catatan guru');
  const done = await call('POST', endpoint, token, {}); ok(done.status === 409 && done.json.error.code === 'MEETING_NOT_OPEN', 'Selesai ditolak 409 MEETING_NOT_OPEN');
  const wc = await browser.newContext({ javaScriptEnabled: false });
  await wc.route('**/*', r => new URL(r.request().url()).hostname === '127.0.0.1' ? r.fallback() : r.abort());
  const w = await wc.newPage(); await w.goto(web + '/portal/index.php');
  await w.fill('#username', 'sbx_admin'); await w.fill('#password', 'Sandbox#123');
  await Promise.all([w.waitForNavigation(), w.getByRole('button', { name: 'Masuk', exact: true }).click()]);
  const ms = Number(sql(`SELECT id FROM pertemuan_pengajian WHERE jadwal_id=${ids.jadwal_satu} AND status='Dibuka' ORDER BY id DESC LIMIT 1`));
  const url = `${web}/admin/admin_pengajian.php?tab=pertemuan&id=${ms}`;
  const audits = () => Number(sql(`SELECT COUNT(*) FROM audit_logs WHERE action='meeting.refresh_participants' AND entity_id=${ms}`));
  const before = audits();
  const r = await w.request.post(url, { form: { action: 'refresh_participants', tab: 'pertemuan', meeting_id: String(ms) } });
  ok(r.status() === 419 && audits() === before, 'Web POST tanpa CSRF ditolak 419 tanpa perubahan');
  await w.goto(url + '&action=refresh_participants&meeting_id=' + ms); ok(audits() === before, 'Web GET tidak bermutasi');
  await w.getByRole('button', { name: 'Perbarui daftar peserta' }).click(); ok(audits() === before + 1, 'Web POST + CSRF bekerja tanpa JavaScript');
  for (const width of [375, 768, 1280]) { await w.setViewportSize({ width, height: 812 }); ok(await w.getByRole('button', { name: 'Perbarui daftar peserta' }).isVisible(), `Web tanpa JavaScript ${width}px tombol tersedia`); }
} catch (e) { ok(false, e.stack); }
finally { await browser.close(); }
console.log(`Hasil: ${pass} lulus, ${fail} gagal`); process.exit(fail ? 1 : 0);
